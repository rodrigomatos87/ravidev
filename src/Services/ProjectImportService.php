<?php

declare(strict_types=1);

namespace Ravi\Services;

use Ravi\Agent\SpecialistRegistry;

/**
 * Importação inteligente de projetos (.zip) — Fase 1 do Hub.
 *
 * Roda em BACKGROUND (queue-worker, job_type=process_project_import) porque um sistema grande
 * pode demorar para descompactar, analisar e configurar. O progresso é gravado em
 * /workspace/.maestro/import.json (fonte única), que a tela do projeto consulta por polling.
 * Enquanto status=running, a UI e o ChatController/ChatHandler BLOQUEIAM abrir/enviar chats.
 *
 * Etapas:
 *   1. extracting → descompacta o .zip no workspace (sanitizado, anti path-traversal).
 *   2. analyzing  → o Analista (Claude Code) estuda o projeto, lê todos os .md, detecta stack/
 *                   runtime/scripts de instalação e gera context.md + app.json + import-summary.json.
 *   3. preparing  → o Analista CONFIGURA DE VERDADE (composer/npm/.env/banco) deixando pronto p/ rodar.
 *   4. done       → conclui; a tela libera os chats e mostra o resumo + pontos importantes.
 *
 * O que persiste vive no WORKSPACE (volume compartilhado): vendor/, node_modules/, .env, banco
 * SQLite e os artefatos .maestro/* — então os agentes e o preview (sob demanda) já encontram tudo.
 */
class ProjectImportService
{
    /** Ordem canônica das etapas exibidas na UI. */
    private const STAGES = [
        'extracting' => 'Descompactando o projeto',
        'analyzing'  => 'Analisando o projeto (IA)',
        'preparing'  => 'Preparando o ambiente (IA)',
        'done'       => 'Pronto',
    ];

    /** Etapas quando a origem é um repositório Git (clona em vez de descompactar). */
    private const GIT_STAGES = [
        'cloning'   => 'Clonando o repositório',
        'analyzing' => 'Analisando o projeto (IA)',
        'preparing' => 'Preparando o ambiente (IA)',
        'done'      => 'Pronto',
    ];

    /** Mapa de etapas em uso (zip ou git). Definido no início de cada processamento. */
    private array $stageMap = self::STAGES;

    private ClaudeCodeService $cc;

    public function __construct(SandboxService $sandbox)
    {
        $this->cc = new ClaudeCodeService($sandbox);
    }

    /** Caminho do arquivo de estado do import para um workspace. */
    public static function statusFile(string $ws): string
    {
        return rtrim($ws, '/') . '/.maestro/import.json';
    }

    /** Lê o estado do import (ou null se nunca houve import neste projeto). */
    public static function readStatus(string $ws): ?array
    {
        $f = self::statusFile($ws);
        if (!is_file($f)) {
            return null;
        }
        $data = json_decode((string) @file_get_contents($f), true);
        return is_array($data) ? $data : null;
    }

    /** true se há um import EM ANDAMENTO neste workspace (gate para abrir/enviar chats). */
    public static function isProcessing(string $ws): bool
    {
        $s = self::readStatus($ws);
        return is_array($s) && (($s['status'] ?? '') === 'running');
    }

    /**
     * Marca o estado inicial (chamado pelo controller no upload, antes de enfileirar o job),
     * para a tela já mostrar "na fila" e bloquear os chats imediatamente.
     */
    public function markQueued(string $ws): void
    {
        $this->ensureMaestroDir($ws);
        $this->write($ws, 'extracting', 'running', ['queued_at' => date('c')]);
    }

    /**
     * Executa o pipeline completo. Não lança em erro de etapa de IA — registra em import.json
     * (status=failed) para a UI mostrar, mas tenta seguir o máximo possível.
     *
     * @param string $uuid    UUID do projeto (= nome da pasta de workspace)
     * @param string $zipPath Caminho do .zip salvo (será removido ao final)
     */
    public function process(string $uuid, string $zipPath, string $instructions = ''): array
    {
        $ws = $this->cc->ensureWorkspace($uuid);
        $this->ensureMaestroDir($ws);

        // ── 1. Descompactar ──────────────────────────────────────────────
        $this->write($ws, 'extracting', 'running');
        $ext = $this->extractZipInto($zipPath, $ws);
        @unlink($zipPath);
        if (empty($ext['ok'])) {
            $this->fail($ws, 'extracting', (string) ($ext['error'] ?? 'Falha ao descompactar.'));
            return ['ok' => false, 'stage' => 'extracting', 'error' => $ext['error'] ?? ''];
        }
        @exec('chmod -R 0777 ' . escapeshellarg($ws) . ' 2>/dev/null');
        $this->patch($ws, ['file_count' => (int) $ext['count']]);

        // Instruções opcionais do usuário: guarda verbatim (a IA registra como "Objetivo do usuário").
        $instructions = trim($instructions);
        if ($instructions !== '') {
            @file_put_contents($ws . '/.maestro/instructions.md', $instructions);
            $this->patch($ws, ['has_instructions' => true]);
        }

        // ── 2. Análise (IA) ──────────────────────────────────────────────
        $this->write($ws, 'analyzing', 'running', ['file_count' => (int) $ext['count']]);
        try {
            $this->runAnalyst($ws, $uuid, $this->buildAnalyzeDirective($instructions));
        } catch (\Throwable $e) {
            // Análise é importante mas não fatal — segue para preparar mesmo assim.
            $this->patch($ws, ['analyze_error' => $e->getMessage()]);
        }
        $summary = $this->readSummary($ws);

        // ── 3. Preparar ambiente (IA) ────────────────────────────────────
        $this->write($ws, 'preparing', 'running', [
            'summary'     => $summary['summary'] ?? '',
            'key_points'  => $summary['key_points'] ?? [],
            'file_count'  => (int) $ext['count'],
        ]);
        try {
            $this->runAnalyst($ws, $uuid, $this->buildPrepareDirective($instructions));
        } catch (\Throwable $e) {
            $this->patch($ws, ['prepare_error' => $e->getMessage()]);
        }
        @exec('chmod -R 0777 ' . escapeshellarg($ws) . ' 2>/dev/null');

        // ── 4. Concluído ─────────────────────────────────────────────────
        $summary = $this->readSummary($ws); // pode ter sido enriquecido na preparação
        $this->write($ws, 'done', 'done', [
            'summary'    => $summary['summary'] ?? '',
            'key_points' => $summary['key_points'] ?? [],
            'file_count' => (int) $ext['count'],
            'finished_at'=> date('c'),
        ]);

        return ['ok' => true, 'stage' => 'done', 'summary' => $summary['summary'] ?? ''];
    }

    /** Marca "na fila" para a origem Git (a tela já mostra "Clonando…" e bloqueia chats). */
    public function markQueuedGit(string $ws): void
    {
        $this->stageMap = self::GIT_STAGES;
        $this->ensureMaestroDir($ws);
        $this->write($ws, 'cloning', 'running', ['queued_at' => date('c')]);
    }

    /**
     * Importa CLONANDO de um repositório do GitHub (inclusive privado, via token do dono).
     * Mesmo onboarding inteligente do .zip: clona → analisa → prepara o ambiente.
     */
    public function processGit(string $uuid, string $repoFullName, string $token, string $branch = 'main', string $instructions = ''): array
    {
        $this->stageMap = self::GIT_STAGES;
        $ws = $this->cc->ensureWorkspace($uuid);
        $this->ensureMaestroDir($ws);

        // ── 1. Clonar ────────────────────────────────────────────────────
        $this->write($ws, 'cloning', 'running');
        $clone = $this->cloneInto($repoFullName, $token, $ws);
        if (empty($clone['ok'])) {
            $this->fail($ws, 'cloning', (string) ($clone['error'] ?? 'Falha ao clonar.'));
            return ['ok' => false, 'stage' => 'cloning', 'error' => $clone['error'] ?? ''];
        }
        @exec('chmod -R 0777 ' . escapeshellarg($ws) . ' 2>/dev/null');
        $this->patch($ws, ['file_count' => (int) $clone['count']]);

        $instructions = trim($instructions);
        if ($instructions !== '') {
            @file_put_contents($ws . '/.maestro/instructions.md', $instructions);
            $this->patch($ws, ['has_instructions' => true]);
        }

        // ── 2. Análise (IA) ──────────────────────────────────────────────
        $this->write($ws, 'analyzing', 'running', ['file_count' => (int) $clone['count']]);
        try {
            $this->runAnalyst($ws, $uuid, $this->buildAnalyzeDirective($instructions));
        } catch (\Throwable $e) {
            $this->patch($ws, ['analyze_error' => $e->getMessage()]);
        }
        $summary = $this->readSummary($ws);

        // ── 3. Preparar ambiente (IA) ────────────────────────────────────
        $this->write($ws, 'preparing', 'running', [
            'summary'    => $summary['summary'] ?? '',
            'key_points' => $summary['key_points'] ?? [],
            'file_count' => (int) $clone['count'],
        ]);
        try {
            $this->runAnalyst($ws, $uuid, $this->buildPrepareDirective($instructions));
        } catch (\Throwable $e) {
            $this->patch($ws, ['prepare_error' => $e->getMessage()]);
        }
        @exec('chmod -R 0777 ' . escapeshellarg($ws) . ' 2>/dev/null');

        // ── 4. Concluído ─────────────────────────────────────────────────
        $summary = $this->readSummary($ws);
        $this->write($ws, 'done', 'done', [
            'summary'    => $summary['summary'] ?? '',
            'key_points' => $summary['key_points'] ?? [],
            'file_count' => (int) $clone['count'],
            'finished_at'=> date('c'),
        ]);

        return ['ok' => true, 'stage' => 'done', 'summary' => $summary['summary'] ?? ''];
    }

    /**
     * Clona o repo para DENTRO do workspace (preservando .maestro). Token via askpass
     * (fora de argv e disco). O remote 'origin' fica com URL LIMPA p/ os pushes futuros.
     * @return array{ok:bool, count?:int, error?:string}
     */
    private function cloneInto(string $repoFullName, string $token, string $ws): array
    {
        $tmp = rtrim($ws, '/') . '/__ravi_clone_' . substr(md5($repoFullName . microtime()), 0, 8);
        @exec('rm -rf ' . escapeshellarg($tmp));

        $askpass = dirname(__DIR__, 2) . '/bin/git-askpass.sh';
        $url = 'https://x-access-token@github.com/' . $repoFullName . '.git';
        $env = [
            'HOME'                => sys_get_temp_dir(),
            'GIT_TERMINAL_PROMPT' => '0',
            'GIT_ASKPASS'         => $askpass,
            'RAVI_GIT_TOKEN'      => $token,
            'PATH'                => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
        ];
        $proc = proc_open(['git', 'clone', $url, $tmp], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname($ws), $env);
        if (!is_resource($proc)) {
            return ['ok' => false, 'error' => 'proc_open falhou'];
        }
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $rc = proc_close($proc);
        if ($rc !== 0 || !is_dir($tmp)) {
            @exec('rm -rf ' . escapeshellarg($tmp));
            return ['ok' => false, 'error' => 'git clone falhou: ' . trim($out)];
        }
        // Limpa a credencial do .git/config (clone com user na URL não grava senha, mas garante URL limpa).
        @exec('git -C ' . escapeshellarg($tmp) . ' remote set-url origin ' . escapeshellarg('https://github.com/' . $repoFullName . '.git') . ' 2>/dev/null');
        // Move o conteúdo (inclui .git e dotfiles) para o workspace, mesclando com .maestro.
        @exec('cp -a ' . escapeshellarg($tmp . '/.') . ' ' . escapeshellarg($ws . '/') . ' 2>/dev/null', $o, $cpRc);
        @exec('rm -rf ' . escapeshellarg($tmp));
        $count = 0;
        foreach (@scandir($ws) ?: [] as $f) {
            if ($f !== '.' && $f !== '..' && $f !== '.maestro') { $count++; }
        }
        return ['ok' => true, 'count' => $count];
    }

    // ──────────────────────────────────────────────────────────────────────
    // Diretivas da IA
    // ──────────────────────────────────────────────────────────────────────

    /** Bloco com as instruções do usuário (se houver) para anexar às diretivas. */
    private function userGoalBlock(string $instructions): string
    {
        if (trim($instructions) === '') {
            return '';
        }
        return "\n\n>>> OBJETIVO DO USUÁRIO (instruções enviadas junto com o upload — PRIORIDADE):\n"
            . "«" . trim($instructions) . "»\n"
            . "Leve essas instruções em conta. No /workspace/.maestro/context.md, inclua uma seção "
            . "'## Objetivo do usuário' registrando FIELMENTE essa intenção (ex.: usar este projeto como MODELO/REFERÊNCIA "
            . "para construir um projeto NOVO do zero que faça algo parecido), para que qualquer agente futuro saiba o "
            . "propósito antes de começar. NÃO comece a construir o projeto novo agora — apenas analise/prepare e registre o objetivo.\n";
    }

    private function buildAnalyzeDirective(string $instructions = ''): string
    {
        return $this->userGoalBlock($instructions)
            . "Um projeto EXISTENTE acabou de ser importado para /workspace (veio de um .zip). "
            . "Sua tarefa AGORA é APENAS ESTUDAR e DOCUMENTAR — NÃO instale dependências nem altere o código ainda.\n\n"
            . "1. Explore a estrutura do projeto e IDENTIFIQUE: linguagem(ns) e stack principal, framework, "
            . "gerenciador de dependências (composer.json, package.json, requirements.txt, go.mod, etc.), "
            . "tipo de banco de dados, e a VERSÃO de runtime exigida (ex.: PHP 8.1, Node 18) se estiver declarada.\n"
            . "2. LEIA TODOS os arquivos de documentação (README, INSTALL, CONTRIBUTING, docs/*.md e quaisquer .md) e "
            . "os scripts de instalação/execução (install.sh, Makefile, docker-compose.yml, composer/npm scripts).\n"
            . "3. ESCREVA /workspace/.maestro/context.md — um resumo de ONBOARDING em português, conciso e útil, que "
            . "QUALQUER agente vai ler antes de mexer no projeto. Inclua: o que o projeto é e faz; a stack e a arquitetura "
            . "em alto nível; estrutura de pastas principal; como instalar e como rodar; convenções/padrões importantes; e "
            . "PONTOS DE ATENÇÃO (pegadinhas, requisitos divergentes do ambiente — ex.: 'exige PHP 7.4' ou 'usa nginx' — "
            . "dependências externas, segredos necessários). Seja fiel: o que não estiver claro, registre como dúvida/atenção.\n"
            . "4. ESCREVA /workspace/.maestro/app.json descrevendo como rodar a app, no formato EXATO: "
            . '{"type":"web|api|cli","has_web_ui":true|false,"docroot":"public","start_command":"php -S 0.0.0.0:$PORT -t public","setup_command":"","requires_login":true|false,"demo_credentials":{"user":"","password":""},"db":"none|sqlite|mysql","db_env":{"driver":"DB_DRIVER","host":"DB_HOST","port":"DB_PORT","database":"DB_NAME","user":"DB_USER","password":"DB_PASS"}}. '
            . "Use SEMPRE o literal \$PORT no start_command. has_web_ui=true só se houver interface visual no navegador. "
            . "setup_command: o comando para preparar o ambiente do zero (ex.: 'composer install && cp .env.example .env'), ou vazio.\n"
            . "5. ESCREVA /workspace/.maestro/import-summary.json no formato EXATO: "
            . '{"summary":"2-4 frases sobre o projeto e seu estado","key_points":["ponto importante 1","ponto 2","..."]}. '
            . "key_points: 3 a 7 itens curtos e objetivos (stack, como rodar, requisitos divergentes, riscos).\n\n"
            . "NÃO rode instalações nesta etapa. Apenas analise e grave os três arquivos. Ao final, uma frase de resumo.";
    }

    private function buildPrepareDirective(string $instructions = ''): string
    {
        return $this->userGoalBlock($instructions)
            . "Agora CONFIGURE DE VERDADE o projeto importado em /workspace para deixá-lo pronto para rodar. "
            . "Use o que você descobriu (veja /workspace/.maestro/context.md e app.json).\n\n"
            . "Faça, na ordem e só o que se aplicar:\n"
            . "1. Instale as dependências REAIS do projeto: se houver composer.json → 'composer install'; se houver "
            . "package.json → 'npm ci' (ou 'npm install'); requirements.txt → 'pip install -r'. Você TEM acesso à rede.\n"
            . "2. Configuração: se houver .env.example e não houver .env, crie o .env a partir dele e ajuste valores óbvios "
            . "para o ambiente local. Para banco, PREFIRA SQLite (arquivo, sem servidor) para o projeto já rodar aqui, "
            . "deixando a troca para MariaDB/MySQL fácil via .env — sem instalar servidores de banco.\n"
            . "3. Banco: se o framework tiver migrations/seed simples, execute-as contra o SQLite local para o app subir.\n"
            . "4. NÃO rode a suíte de testes inteira, NÃO inicie servidores web e NÃO refatore o código. O objetivo é apenas "
            . "deixar o ambiente PRONTO (dependências instaladas, .env e banco preparados).\n"
            . "5. ATUALIZE /workspace/.maestro/context.md (seção 'Como rodar' e 'Pontos de atenção') e "
            . "/workspace/.maestro/import-summary.json com o estado final (o que foi instalado, se está pronto para rodar, "
            . "e qualquer passo manual que ainda falte). Mantenha o mesmo formato JSON do import-summary.\n\n"
            . "Se algo falhar (ex.: dependência sem rede, versão incompatível), NÃO trave: registre o problema nos pontos de "
            . "atenção do context.md/import-summary e siga. Ao final, uma frase de resumo do que ficou pronto.";
    }

    private function runAnalyst(string $ws, string $uuid, string $directive): void
    {
        $spec = SpecialistRegistry::get('analista');
        $this->cc->run($ws, $uuid, $directive, [
            'quiet'  => true,            // não streamamos no chat; o progresso é o import.json
            'system' => $spec['system'],
            'agent'  => SpecialistRegistry::ui('analista'),
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Estado (import.json) — fonte única consultada pela UI e pelo gate de chat
    // ──────────────────────────────────────────────────────────────────────

    /** Grava o estado completo posicionando a etapa atual e o status global. */
    private function write(string $ws, string $stage, string $status, array $extra = []): void
    {
        $existing = self::readStatus($ws) ?: [];

        // Monta a lista de etapas com seus estados (pending/active/done).
        // Se já houver etapas gravadas (status anterior), preserva o mapa correto (zip vs git).
        if (isset($existing['stages']) && is_array($existing['stages']) && in_array($stage, ['cloning'], true)) {
            $this->stageMap = self::GIT_STAGES;
        }
        $order = array_keys($this->stageMap);
        $curIdx = array_search($stage, $order, true);
        $stages = [];
        foreach ($order as $i => $key) {
            $state = 'pending';
            if ($status === 'done') {
                $state = 'done';
            } elseif ($status === 'failed') {
                $state = ($i < $curIdx) ? 'done' : (($i === $curIdx) ? 'failed' : 'pending');
            } else {
                $state = ($i < $curIdx) ? 'done' : (($i === $curIdx) ? 'active' : 'pending');
            }
            $stages[] = ['key' => $key, 'label' => $this->stageMap[$key], 'state' => $state];
        }

        $data = array_merge($existing, [
            'status'     => $status,         // running | done | failed
            'stage'      => $stage,          // etapa atual
            'stages'     => $stages,
            'updated_at' => date('c'),
        ], $extra);
        if (!isset($data['started_at'])) {
            $data['started_at'] = date('c');
        }

        $this->save($ws, $data);
    }

    /** Atualiza apenas alguns campos sem recomputar as etapas. */
    private function patch(string $ws, array $extra): void
    {
        $data = self::readStatus($ws) ?: [];
        $data = array_merge($data, $extra, ['updated_at' => date('c')]);
        $this->save($ws, $data);
    }

    private function fail(string $ws, string $stage, string $error): void
    {
        $this->write($ws, $stage, 'failed', ['error' => $error, 'finished_at' => date('c')]);
    }

    private function save(string $ws, array $data): void
    {
        @file_put_contents(self::statusFile($ws), json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /** Lê o import-summary.json gravado pela IA (resumo + pontos importantes). */
    private function readSummary(string $ws): array
    {
        $f = rtrim($ws, '/') . '/.maestro/import-summary.json';
        if (!is_file($f)) {
            return [];
        }
        $data = json_decode((string) @file_get_contents($f), true);
        if (!is_array($data)) {
            return [];
        }
        return [
            'summary'    => (string) ($data['summary'] ?? ''),
            'key_points' => array_values(array_filter(array_map(
                fn($p) => is_string($p) ? trim($p) : '',
                (array) ($data['key_points'] ?? [])
            ))),
        ];
    }

    private function ensureMaestroDir(string $ws): void
    {
        $dir = rtrim($ws, '/') . '/.maestro';
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        @chmod($dir, 0777);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Extração de .zip (sanitizada) — mesma lógica do ProjectController::extractZipInto
    // ──────────────────────────────────────────────────────────────────────

    private function extractZipInto(string $zipPath, string $dest): array
    {
        if (!class_exists('\ZipArchive')) {
            return ['ok' => false, 'error' => 'Suporte a ZIP indisponível no servidor.'];
        }
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return ['ok' => false, 'error' => 'Não consegui abrir o .zip (corrompido?).'];
        }

        // Pasta-wrapper única (ex.: "meu-projeto/...") → extrai o conteúdo na raiz do workspace.
        $top = null; $single = true;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $n     = str_replace('\\', '/', (string) $zip->getNameIndex($i));
            $first = explode('/', ltrim($n, '/'))[0] ?? '';
            if ($first === '' || $first === '__MACOSX') { continue; }
            if ($top === null) { $top = $first; }
            elseif ($top !== $first) { $single = false; break; }
        }
        $strip    = ($single && $top !== null) ? ($top . '/') : '';
        $destReal = rtrim($dest, '/');
        $count    = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = str_replace('\\', '/', (string) $zip->getNameIndex($i));
            if (strpos($name, '__MACOSX/') === 0) { continue; }
            if ($strip !== '' && strpos($name, $strip) === 0) {
                $name = substr($name, strlen($strip));
            }
            $name = ltrim($name, '/');
            if ($name === '' || strpos($name, '..') !== false || strpos($name, "\0") !== false) {
                continue;
            }
            $target = $destReal . '/' . $name;
            if (substr($name, -1) === '/') {
                @mkdir($target, 0777, true);
                continue;
            }
            @mkdir(dirname($target), 0777, true);
            $data = $zip->getFromIndex($i);
            if ($data !== false && @file_put_contents($target, $data) !== false) {
                $count++;
            }
        }
        $zip->close();

        return $count === 0
            ? ['ok' => false, 'error' => 'O .zip está vazio ou sem arquivos válidos.']
            : ['ok' => true, 'count' => $count];
    }
}
