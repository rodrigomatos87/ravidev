<?php

declare(strict_types=1);

namespace Ravi\Services;

use Ravi\Core\Database;
use Ravi\Models\Project;
use Ravi\Models\ProjectMemory;
use Ravi\Models\ProjectSshSource;

/**
 * Monta e injeta o "contexto permanente" do projeto no Claude Code (chat e IDE):
 *  - MEMÓRIAS → ~/.claude/CLAUDE.md (lido por padrão pelo Claude Code, FORA do /workspace → não
 *    aparece na IDE).
 *  - FONTES EXTERNAS (SSH) ativas → ~/.ravi/servers.json (lido pelo helper `ravi-ssh "<nome>"`),
 *    e a lista (sem senha) entra no CLAUDE.md ensinando o Claude a usar.
 *  - SFTP da IDE → .vscode/sftp.json no workspace para os servidores `auto_deploy`.
 *
 * Tudo respeita o acesso POR USUÁRIO (dono sempre; membro só se liberado): a injeção é vazia
 * para quem não tem acesso.
 */
class ProjectContextService
{
    /**
     * Vars de env (base64) para o `docker run` do Claude (chat/IDE), para um ATOR específico.
     * Vazio se o ator não tem acesso.
     * @param bool $stripSshKeys true (IDE): NÃO inclui chave privada no servers.json — o container
     *                           usa o ssh-agent do host (IdeSshAgentService); vai só a chave PÚBLICA
     *                           (pin do IdentitiesOnly). false (chat/sandbox): comportamento antigo.
     * @return array{RAVI_CLAUDE_MD_B64?:string, RAVI_SERVERS_B64?:string}
     */
    public function injectionEnv(Project $project, int $actingUserId, bool $stripSshKeys = false): array
    {
        if (!$project->userHasContextAccess($actingUserId)) {
            return [];
        }
        $out = [];
        $claudeMd = $this->buildClaudeMd($project, $actingUserId);
        if ($claudeMd !== '') {
            $out['RAVI_CLAUDE_MD_B64'] = base64_encode($claudeMd);
        }
        $servers = $this->activeServersJson($project, $actingUserId, $stripSshKeys);
        if ($servers !== '') {
            $out['RAVI_SERVERS_B64'] = base64_encode($servers);
        }
        return $out;
    }

    /**
     * Monta o CLAUDE.md injetado: hoje só a seção "Servidores externos" (SEM senhas). As MEMÓRIAS
     * do projeto NÃO vão mais aqui — ficam na memória nativa do Claude Code (diretório montado,
     * compartilhado IDE+chat), gerenciada pelo painel "Memórias".
     */
    public function buildClaudeMd(Project $project, int $actingUserId = 0): string
    {
        $parts = [];

        // #3 — Projetos ligados (sistemas SEPARADOS) acessíveis em /linked/<slug>.
        $digests     = $this->linkedDigests($project, $actingUserId);
        $linkedSrv   = $this->linkedServers($project, $actingUserId);
        if ($digests) {
            $block = [
                "# Projetos ligados (sistemas SEPARADOS)",
                "Além DESTE projeto (/workspace), você tem acesso aos arquivos de outro(s) projeto(s) que são SISTEMAS/ECOSSISTEMAS DIFERENTES e independentes. Esse acesso foi concedido pontualmente para integrar as duas pontas (ex.: comunicação via API entre os dois sistemas). Use-os para entender e integrar, mas trate cada um como um sistema próprio — não misture um no outro.",
                "Os caminhos abaixo ficam FORA do /workspace (não aparecem na árvore de arquivos da IDE), mas você pode acessá-los pelo caminho:",
            ];
            foreach ($digests as $m) {
                $block[] = $m['access'] === 'write'
                    ? sprintf('- "%s" em `%s` — LEITURA E ESCRITA: você pode ler E alterar os arquivos deste outro sistema.', $m['name'], $m['container'])
                    : sprintf('- "%s" em `%s` — SOMENTE LEITURA: leia para entender como funciona; NÃO altere nada aqui.', $m['name'], $m['container']);
                if ($m['desc'] !== '') {
                    $block[] = '    Do que se trata: ' . $m['desc'];
                }
                if ($m['memo'] !== '') {
                    $block[] = '    Contexto do sistema "' . $m['name'] . '" (memórias do projeto):';
                    foreach (explode("\n", $m['memo']) as $ml) {
                        $block[] = '      ' . $ml;
                    }
                }
            }
            $block[] = "Esse acesso vale apenas enquanto a ligação estiver ativa.";
            if ($linkedSrv) {
                $block[] = "Os sistemas ligados em LEITURA E ESCRITA também concederam acesso aos PRÓPRIOS servidores SSH deles (listados em \"Servidores externos\" abaixo, marcados como \"do sistema ligado\"). Use cada servidor no contexto do sistema a que ele pertence.";
            }
            $parts[] = implode("\n", $block);
        }

        $servers = $this->activeServers($project);
        if ($servers || $linkedSrv) {
            $block = [
                "# Servidores externos (SSH)",
                "Você tem acesso a estes servidores. Para rodar algo num servidor, use o comando:",
                "  ravi-ssh \"<nome>\" \"<comando>\"   (sem comando = shell interativo; `ravi-ssh --list` lista)",
                "O diretório de trabalho de cada servidor já é aplicado automaticamente. NÃO precisa de senha — o ravi-ssh cuida disso. NUNCA escreva ou peça senhas.",
                "Servidores disponíveis:",
            ];
            foreach ($servers as $s) {
                $block[] = sprintf('- "%s" — %s@%s:%d, diretório %s (deste projeto)',
                    (string) $s->name, (string) $s->username, (string) $s->host,
                    (int) $s->port, ((string) $s->remote_dir !== '' ? (string) $s->remote_dir : '~'));
            }
            foreach ($linkedSrv as $ls) {
                $s = $ls['source'];
                $block[] = sprintf('- "%s" — %s@%s:%d, diretório %s (servidor do sistema ligado "%s")',
                    $ls['name'], (string) $s->username, (string) $s->host,
                    (int) $s->port, ((string) $s->remote_dir !== '' ? (string) $s->remote_dir : '~'),
                    (string) $ls['project']->name);
            }
            $parts[] = implode("\n", $block);
        }

        return trim(implode("\n\n", $parts));
    }

    /**
     * #3 — Montagens de projetos LIGADOS para um ator. Só inclui links ativos cujo projeto-alvo o
     * ator pode acessar (dono/membro), e cujo workspace existe no host. Caminho do container fica
     * FORA do /workspace (em /linked/<slug>) para não poluir a árvore da IDE.
     * @return array<int,array{name:string, host:string, container:string, ro:bool, access:string, uuid:string}>
     */
    public function linkedMounts(Project $project, int $actingUserId): array
    {
        $out = [];
        foreach ($this->resolveLinks($project, $actingUserId) as $r) {
            $b = $r['project'];
            $write = $r['access'] === 'write';
            $out[] = [
                'name'      => (string) $b->name,
                'host'      => $r['host'],
                'container' => '/linked/' . $this->linkSlug($b),
                'ro'        => !$write,
                'access'    => $r['access'],
                'uuid'      => (string) $b->uuid,
            ];
        }
        return $out;
    }

    /**
     * Base comum de linkedMounts()/linkedServers(): resolve os projetos LIGADOS aplicáveis a um ator
     * — links ATIVOS cujo projeto-alvo o ator pode acessar (dono/membro) e cujo workspace existe no
     * host. Retorna o objeto Project do alvo + nível de acesso + caminho do workspace.
     * @return array<int,array{project:Project, access:string, host:string}>
     */
    private function resolveLinks(Project $project, int $actingUserId): array
    {
        try {
            $links = \Ravi\Models\ProjectLink::forProject((int) $project->id, true);
        } catch (\Throwable $e) {
            return [];
        }
        if (!$links) {
            return [];
        }
        $accessible = $actingUserId > 0 ? Project::accessibleIdsFor($actingUserId) : [];
        $out = [];
        $seen = [];
        foreach ($links as $ln) {
            $bid = (int) $ln->linked_project_id;
            if ($bid === (int) $project->id || isset($seen[$bid])) {
                continue;
            }
            $b = Project::find($bid);
            if (!$b || ($b->status ?? '') === 'deleted') {
                continue;
            }
            // O ator precisa ter acesso ao projeto-alvo (senão a ligação não se aplica a ele).
            // Dono/membro pela lista; o ADMINISTRADOR acessa qualquer projeto (isAccessibleBy).
            if ($actingUserId > 0 && !in_array($bid, $accessible, true) && !$b->isAccessibleBy($actingUserId)) {
                continue;
            }
            $host = $this->workspacePath((string) $b->uuid);
            if (!is_dir($host)) {
                continue;
            }
            $seen[$bid] = true;
            $out[] = [
                'project' => $b,
                'access'  => (string) $ln->access === 'write' ? 'write' : 'read',
                'host'    => $host,
            ];
        }
        return $out;
    }

    /**
     * Servidores SSH dos projetos LIGADOS cujo acesso (LEITURA E ESCRITA) foi concedido ao ator. Cada
     * sistema ligado é SEPARADO: seus servidores são acessíveis pelo `ravi-ssh` com nome QUALIFICADO
     * pelo projeto (para não colidir com os deste projeto), usando a CHAVE/credencial do PRÓPRIO
     * projeto ligado (o "acesso configurado no projeto que foi linkado").
     *
     * Só ligações de escrita propagam servidores — uma ligação somente-leitura dá apenas os arquivos.
     * E respeita o controle de acesso do PRÓPRIO projeto ligado (o ator precisa de context_access nele),
     * para não vazar credenciais que o dono do projeto ligado não liberou.
     * @return array<int,array{source:ProjectSshSource, project:Project, name:string}>
     */
    public function linkedServers(Project $project, int $actingUserId): array
    {
        $out = [];
        foreach ($this->resolveLinks($project, $actingUserId) as $r) {
            if ($r['access'] !== 'write') {
                continue;
            }
            $b = $r['project'];
            if ($actingUserId > 0 && !$b->userHasContextAccess($actingUserId)) {
                continue;
            }
            foreach ($this->activeServers($b) as $s) {
                $out[] = [
                    'source'  => $s,
                    'project' => $b,
                    'name'    => $this->linkedServerName((string) $s->name, $b),
                ];
            }
        }
        return $out;
    }

    /**
     * Projetos cuja chave SSH o ATOR usa neste contexto (este projeto + ligados em escrita), ou
     * seja: os donos de servidores key-auth ativos. É o conjunto que o IdeSshAgentService carrega
     * no ssh-agent do container — a chave privada nunca sai do host.
     * @return Project[] distintos
     */
    public function sshKeyProjects(Project $project, int $actingUserId): array
    {
        // MESMA regra do injectionEnv: sem acesso ao contexto → sem servidores → sem chave no
        // agente (senão o agente viraria um bypass do context_access do membro).
        if (!$project->userHasContextAccess($actingUserId)) {
            return [];
        }
        $list = [];
        $push = function (Project $p) use (&$list): void {
            foreach ($list as $x) {
                if ((int) $x->id === (int) $p->id) {
                    return;
                }
            }
            $list[] = $p;
        };
        foreach ($this->activeServers($project) as $s) {
            if ($s->isKeyAuth()) {
                $push($project);
                break;
            }
        }
        foreach ($this->linkedServers($project, $actingUserId) as $ls) {
            if ($ls['source']->isKeyAuth()) {
                $push($ls['project']);
            }
        }
        return $list;
    }

    /** Nome qualificado de um servidor de projeto ligado, para o ravi-ssh não colidir com os deste projeto. */
    private function linkedServerName(string $serverName, Project $linked): string
    {
        return $serverName . ' (' . (string) $linked->name . ')';
    }

    /**
     * Resumo de cada projeto LIGADO para o CLAUDE.md, para o Claude já saber DO QUE SE TRATA cada
     * sistema ligado sem precisar varrer os arquivos: nome, acesso, caminho de montagem, descrição e
     * um índice das memórias do projeto ligado. O índice de memórias só entra se o ator tiver
     * context_access no projeto ligado (mesma regra dos servidores).
     * @return array<int,array{name:string, access:string, container:string, desc:string, memo:string}>
     */
    private function linkedDigests(Project $project, int $actingUserId): array
    {
        $out = [];
        foreach ($this->resolveLinks($project, $actingUserId) as $r) {
            $b = $r['project'];
            $memo = ($actingUserId === 0 || $b->userHasContextAccess($actingUserId))
                ? $this->memoryIndexDigest((string) $b->uuid)
                : '';
            $out[] = [
                'name'      => (string) $b->name,
                'access'    => $r['access'],
                'container' => '/linked/' . $this->linkSlug($b),
                'desc'      => trim((string) ($b->description ?? '')),
                'memo'      => $memo,
            ];
        }
        return $out;
    }

    /** Índice curto (o "do que se trata") das memórias de um projeto, a partir do MEMORY.md. '' se vazio. */
    private function memoryIndexDigest(string $projectUuid): string
    {
        $idx = $this->memoryDir($projectUuid) . '/MEMORY.md';
        if (!is_file($idx)) {
            return '';
        }
        $lines = [];
        foreach (explode("\n", (string) @file_get_contents($idx)) as $l) {
            $l = trim($l);
            if ($l === '' || $l[0] === '#') {
                continue; // descarta cabeçalho "# Memory Index" e linhas em branco
            }
            $lines[] = $l;
            if (count($lines) >= 20) {
                break;
            }
        }
        return implode("\n", $lines);
    }

    /** Caminho do workspace (host) de um projeto pelo uuid. */
    public function workspacePath(string $projectUuid): string
    {
        $base = config('sandbox')['workspace']['base_path'] ?? (STORAGE_PATH . '/sandbox');
        return rtrim((string) $base, '/') . '/' . $projectUuid;
    }

    /** Caminho de montagem (/linked/<slug>) de um projeto-alvo — para exibir na UI. */
    public function mountPathFor(Project $b): string
    {
        return '/linked/' . $this->linkSlug($b);
    }

    /** Slug path-safe (nome + 8 do uuid) para o ponto de montagem /linked/<slug>. */
    private function linkSlug(Project $b): string
    {
        $s = strtolower((string) $b->name);
        $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
        $s = trim($s, '-');
        if ($s === '') {
            $s = 'proj';
        }
        return $s . '-' . substr((string) $b->uuid, 0, 8);
    }

    /** @return ProjectSshSource[] servidores ATIVOS (enabled=1). */
    private function activeServers(Project $project): array
    {
        // Só SSH: o Claude acessa por shell (ravi-ssh). Servidores FTP não entram aqui (sem shell);
        // eles só recebem upload das entregas (auto_deploy) e a config SFTP/FTP da IDE.
        return array_values(array_filter(
            ProjectSshSource::forProject((int) $project->id),
            fn ($s) => (int) $s->enabled === 1 && !$s->isFtp()
        ));
    }

    /** @return ProjectSshSource[] servidores com deploy automático (e ativos). */
    public function autoDeployServers(Project $project): array
    {
        return array_values(array_filter(
            ProjectSshSource::forProject((int) $project->id),
            fn ($s) => (int) $s->auto_deploy === 1 && (int) $s->enabled === 1
        ));
    }

    /**
     * JSON dos servidores ativos COM senha/chave decifrada (para ~/.ravi/servers.json). '' se nenhum.
     * Inclui os servidores deste projeto E os dos projetos LIGADOS em escrita (sistemas separados cujo
     * acesso foi concedido ao ator) — cada um com a CHAVE do seu PRÓPRIO projeto dono.
     */
    private function activeServersJson(Project $project, int $actingUserId = 0, bool $stripKeys = false): string
    {
        // (source, projeto-dono, nome-exibido): deste projeto + dos ligados (escrita).
        $tuples = [];
        foreach ($this->activeServers($project) as $s) {
            $tuples[] = ['source' => $s, 'project' => $project, 'name' => (string) $s->name];
        }
        foreach ($this->linkedServers($project, $actingUserId) as $ls) {
            $tuples[] = ['source' => $ls['source'], 'project' => $ls['project'], 'name' => $ls['name']];
        }
        if (!$tuples) {
            return '';
        }

        // Chave privada por projeto, gerada/decifrada uma vez (o projeto dono define a chave, não o servidor).
        $keyCache = [];
        $keyOf = function (Project $p) use (&$keyCache): string {
            $pid = (int) $p->id;
            if (!array_key_exists($pid, $keyCache)) {
                $p->ensureSshKey();
                $keyCache[$pid] = (string) ($p->getSshPrivateKey() ?? '');
            }
            return $keyCache[$pid];
        };

        $arr = [];
        foreach ($tuples as $t) {
            $s = $t['source'];
            $entry = [
                'id'   => (int) $s->id,
                'name' => $t['name'],
                'host' => (string) $s->host,
                'ips'  => $s->hostCandidates(), // host primário + IPs descobertos (inclui VPN) p/ fallback
                'port' => (int) $s->port,
                'user' => (string) $s->username,
                'dir'  => (string) ($s->remote_dir ?? ''),
            ];
            if ($s->isKeyAuth()) {
                // Chave ÚNICA do PROJETO dono do servidor (este projeto OU o ligado), não por servidor.
                $entry['auth'] = 'key';
                if ($stripKeys) {
                    // IDE: a chave privada fica no HOST (ssh-agent por container). Só a PÚBLICA vai,
                    // para o ravi-ssh pinar a identidade certa no agente (IdentitiesOnly).
                    $t['project']->ensureSshKey();
                    $entry['pub'] = (string) ($t['project']->getSshPublicKey() ?? '');
                } else {
                    $entry['key'] = $keyOf($t['project']);
                }
            } else {
                $entry['auth'] = 'password';
                $entry['pass'] = (string) ($s->getPassword() ?? '');
            }
            $arr[] = $entry;
        }
        return $arr ? (string) json_encode($arr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
    }

    /**
     * Gera (ou remove) o .vscode/sftp.json no workspace para os servidores `auto_deploy` — assim a
     * extensão SFTP da IDE já vem configurada (upload ao salvar). Fica no workspace (visível a membros
     * com acesso à IDE) → é gitignored pelo GitSyncService.
     */
    public function writeSftpConfig(Project $project, string $workspacePath): void
    {
        $dir  = rtrim($workspacePath, '/') . '/.vscode';
        $file = $dir . '/sftp.json';
        $deploy = $this->autoDeployServers($project);
        if (!$deploy) {
            @unlink($file);
            return;
        }
        $mk = function ($s) {
            $cfg = [
                'name'         => (string) $s->name,
                'host'         => (string) $s->host,
                'protocol'     => $s->isFtp() ? 'ftp' : 'sftp',
                'port'         => (int) $s->port,
                'username'     => (string) $s->username,
                'remotePath'   => ((string) $s->remote_dir !== '' ? (string) $s->remote_dir : '/'),
                'uploadOnSave' => true,
                'ignore'       => ['.vscode', '.git', '.maestro', 'node_modules', 'vendor', '.env'],
            ];
            if ($s->isKeyAuth()) {
                // A chave privada NÃO entra mais no container: a extensão SFTP assina pelo
                // ssh-agent do host, montado em /home/sandbox/.ravi-ssh/agent.sock (IdeSshAgentService).
                $cfg['agent'] = \Ravi\Services\IdeSshAgentService::CONTAINER_SOCK;
            } else {
                $cfg['password'] = (string) ($s->getPassword() ?? '');
            }
            return $cfg;
        };
        if (count($deploy) === 1) {
            $cfg = $mk($deploy[0]);
        } else {
            $profiles = [];
            foreach ($deploy as $s) {
                $profiles[$this->slug((string) $s->name)] = $mk($s);
            }
            $cfg = ['defaultProfile' => $this->slug((string) $deploy[0]->name), 'profiles' => $profiles];
        }
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        @file_put_contents($file, json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        @chmod($file, 0666);
    }

    /**
     * Aplica o contexto NAS IDEs RODANDO do projeto (best-effort, via docker exec) + atualiza o
     * sftp.json no workspace. Chamado quando o dono altera memórias/servidores/acesso, para refletir
     * sem precisar reabrir a IDE.
     */
    public function refreshRunningIdes(Project $project, string $workspacePath): void
    {
        $this->writeSftpConfig($project, $workspacePath);

        $rows = Database::getInstance()->fetchAll(
            "SELECT container, user_id FROM ide_sessions WHERE project_id = ? AND status = 'running'",
            [(int) $project->id]
        );
        foreach ($rows as $r) {
            $env = $this->injectionEnv($project, (int) $r['user_id'], true); // IDE: sem chave privada
            $md  = $env['RAVI_CLAUDE_MD_B64'] ?? '';
            $sv  = $env['RAVI_SERVERS_B64'] ?? '';
            $script = 'mkdir -p "$HOME/.claude" "$HOME/.ravi"; '
                . ($md !== '' ? 'printf %s ' . escapeshellarg($md) . ' | base64 -d > "$HOME/.claude/CLAUDE.md"; ' : 'rm -f "$HOME/.claude/CLAUDE.md"; ')
                . ($sv !== '' ? 'printf %s ' . escapeshellarg($sv) . ' | base64 -d > "$HOME/.ravi/servers.json"; chmod 600 "$HOME/.ravi/servers.json"; ' : 'rm -f "$HOME/.ravi/servers.json"; ')
                . 'ravi-ctx-keys 2>/dev/null || true; ';
            @exec('docker exec ' . escapeshellarg((string) $r['container']) . ' sh -lc ' . escapeshellarg($script) . ' 2>/dev/null');
            // Recarrega o agente (inclusão/remoção de servidor ou de projeto ligado muda as chaves).
            try {
                (new IdeSshAgentService())->ensure((string) $r['container'], $project, (int) $r['user_id'], true);
            } catch (\Throwable $e) { /* best-effort */ }
        }
    }

    private function slug(string $s): string
    {
        $s = strtolower(trim($s));
        $s = preg_replace('/[^a-z0-9._-]+/', '-', $s) ?? '';
        return trim($s, '-') ?: 'server';
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Memória do Claude Code (a que o Claude da IDE/chat salva sozinho) — persistida
    // num diretório do host POR PROJETO e montada nos containers em
    // ~/.claude/projects/-workspace/memory. O painel "Memórias" lê/edita esses arquivos.
    // ──────────────────────────────────────────────────────────────────────────

    /** Diretório (host) que guarda a memória do Claude Code do projeto. Fora do workspace. */
    public function memoryDir(string $projectUuid): string
    {
        $base = config('sandbox')['workspace']['base_path'] ?? (STORAGE_PATH . '/sandbox');
        $dir = rtrim(dirname(rtrim((string) $base, '/')), '/') . '/claude-memory/' . $projectUuid;
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        @chmod($dir, 0777);
        return $dir;
    }

    /** Caminho (dentro do container) onde o Claude Code guarda a memória. */
    public const CONTAINER_MEMORY_PATH = '/.claude/projects/-workspace/memory';

    /**
     * Caminho (dentro do container) do projeto do Claude Code — guarda as CONVERSAS (transcripts
     * *.jsonl) do `claude` rodando em /workspace (encoded como "-workspace"). A memória é um subdir
     * deste, montado separadamente (mount aninhado).
     */
    public const CONTAINER_PROJECT_PATH = '/.claude/projects/-workspace';

    /**
     * Diretório (host) que persiste as conversas do Claude Code do projeto (transcripts da IDE).
     * Fora do workspace, POR PROJETO e POR USUÁRIO — sobrevive à recriação do container da IDE e
     * isola as conversas de cada membro (cada um só vê e retoma as próprias na IDE).
     * Layout: storage/claude-conversations/<projeto>/<usuario>.
     */
    public function conversationsDir(string $projectUuid, int $userId): string
    {
        $base = config('sandbox')['workspace']['base_path'] ?? (STORAGE_PATH . '/sandbox');
        $root = rtrim(dirname(rtrim((string) $base, '/')), '/') . '/claude-conversations/' . $projectUuid;
        $dir  = $root . '/' . $userId;
        $isNew = !is_dir($dir);
        if ($isNew) {
            @mkdir($dir, 0777, true);
            // MIGRAÇÃO única: antes as conversas eram achatadas em <projeto>/ (pool compartilhado
            // entre membros). Na 1ª criação do diretório do DONO, movemos os transcripts legados
            // para ele — o histórico antigo fica com o dono; os membros começam limpos.
            $this->migrateLegacyConversations($projectUuid, $userId, $root, $dir);
        }
        @chmod($dir, 0777);
        return $dir;
    }

    /**
     * Move os transcripts legados achatados em <projeto>/ para o diretório por usuário do DONO.
     * Só roda para o dono e só uma vez (quando o dir dele acabou de ser criado). Best-effort.
     */
    private function migrateLegacyConversations(string $projectUuid, int $userId, string $root, string $dir): void
    {
        try {
            $owner = (int) (\Ravi\Core\Database::getInstance()
                ->fetch("SELECT user_id FROM projects WHERE uuid = ?", [$projectUuid])['user_id'] ?? 0);
            if ($owner === 0 || $owner !== $userId) {
                return; // só o dono herda o histórico compartilhado legado
            }
            // Os transcripts legados são root-owned (escritos pelo container que roda como root), então
            // o www-data (este processo) NÃO consegue movê-los — por isso a cópia é feita por um
            // container ROOT, uma única vez (quando o diretório do dono nasce). Copia o que é conversa
            // (nome com não-dígito: *.jsonl e dirs de sessão uuid), pulando 'memory' e os dirs
            // por-usuário (só dígitos). cp -an = arquivo, sem sobrescrever; mantém o flat como backup.
            $image = (string) env('IDE_IMAGE', 'ravi-ide:latest');
            $uid = (int) $userId;
            $sh = 'cd /conv 2>/dev/null || exit 0; mkdir -p ' . $uid . '; '
                . 'for x in *; do [ -e "$x" ] || continue; '
                . 'case "$x" in memory|' . $uid . ') continue;; esac; '
                . 'case "$x" in *[!0-9]*) cp -an "$x" ' . $uid . '/ 2>/dev/null;; esac; done';
            @exec('docker run --rm --entrypoint sh -v ' . escapeshellarg($root) . ':/conv '
                . escapeshellarg($image) . ' -c ' . escapeshellarg($sh) . ' 2>/dev/null');
        } catch (\Throwable $e) {
            // migração nunca impede abrir a IDE
        }
    }

    /** Título legível de um arquivo de memória (frontmatter description/name, ou 1ª linha). */
    private function memoryTitle(string $raw, string $fallback): string
    {
        if (preg_match('/^description:\s*"?(.+?)"?\s*$/mi', $raw, $m)) {
            return trim($m[1]);
        }
        if (preg_match('/^name:\s*(.+?)\s*$/mi', $raw, $m)) {
            return trim($m[1]);
        }
        return $fallback;
    }

    /** Corpo (sem o frontmatter ---...---) de um arquivo de memória. */
    private function memoryBody(string $raw): string
    {
        if (strncmp($raw, '---', 3) === 0) {
            $end = strpos($raw, "\n---", 3);
            if ($end !== false) {
                $after = strpos($raw, "\n", $end + 1);
                return ltrim(substr($raw, $after === false ? strlen($raw) : $after + 1), "\n");
            }
        }
        return $raw;
    }

    /** Lista as memórias do projeto: [{id:arquivo, name:título, content:corpo}]. */
    public function listMemories(string $projectUuid): array
    {
        $dir = $this->memoryDir($projectUuid);
        $out = [];
        foreach (glob($dir . '/*.md') ?: [] as $f) {
            $file = basename($f);
            if (strcasecmp($file, 'MEMORY.md') === 0) {
                continue;
            }
            $raw = (string) @file_get_contents($f);
            $mtime = (int) @filemtime($f);
            $out[] = [
                'id'      => $file,
                'name'    => $this->memoryTitle($raw, preg_replace('/\.md$/', '', $file)),
                'content' => $this->memoryBody($raw),
                'mtime'   => $mtime,
                'updated' => $mtime ? date('d/m/Y H:i', $mtime) : '',
            ];
        }
        // Mais recentes primeiro (útil para os cards mostrarem o que mudou por último).
        usort($out, fn ($a, $b) => ($b['mtime'] <=> $a['mtime']) ?: strcasecmp($a['name'], $b['name']));
        return $out;
    }

    /**
     * Semeia as memórias padrão de um projeto recém-criado: diretrizes fixas que o Claude Code
     * (chat e IDE) passa a seguir por padrão. Idempotente — não recria se o arquivo já existe,
     * para não sobrescrever ajustes que o usuário/Claude tenham feito depois.
     */
    public function seedDefaultMemories(string $projectUuid): void
    {
        $dir = $this->memoryDir($projectUuid);
        if (is_file($dir . '/diretrizes-do-projeto.md')) {
            return;
        }
        $content = implode("\n", [
            'Regras fixas para todo texto e código gerado neste projeto. Valem para o chat e para a IDE.',
            '',
            '**Escrita (textos, mensagens, documentação, interface):**',
            '- NUNCA use travessão (o caractere "—"). Reescreva a frase com vírgula, dois-pontos ou ponto final.',
            '- Nada de emojis decorativos ou infantis. Tom profissional e direto. Use emoji só se o usuário pedir explicitamente.',
            '',
            '**Código (front-end):**',
            '- NUNCA use alert(), confirm() ou prompt() do navegador. Eles travam a página e têm aparência amadora.',
            '- Para avisos e mensagens, use um modal próprio do projeto ou uma notificação (toast / showNotification).',
            '- Para confirmações ("tem certeza?"), use um modal com botões de confirmar e cancelar.',
            '- Para entrada de texto, use um campo dentro de um modal ou formulário, nunca prompt().',
            '- Se o projeto ainda não tiver um componente de modal ou de notificação, crie um simples e reutilizável e passe a usá-lo em todo lugar.',
        ]);
        $this->saveMemory($projectUuid, 'diretrizes-do-projeto.md', 'Diretrizes do projeto (escrita e código)', $content);
    }

    /** Cria/atualiza uma memória (arquivo .md + linha no MEMORY.md). $id='' = nova. */
    public function saveMemory(string $projectUuid, string $id, string $name, string $content): array
    {
        $dir = $this->memoryDir($projectUuid);
        $name = trim($name) !== '' ? trim($name) : 'memória';
        $file = $id !== '' ? basename($id) : ($this->slug($name) . '.md');
        if (!preg_match('/^[\w.\- ]+\.md$/', $file) || strcasecmp($file, 'MEMORY.md') === 0) {
            return ['ok' => false, 'error' => 'Nome de arquivo inválido.'];
        }
        $slug = preg_replace('/\.md$/', '', $file);
        $body = "---\nname: " . $slug . "\ndescription: \"" . str_replace('"', "'", $name) . "\"\nmetadata:\n  node_type: memory\n  type: project\n---\n\n" . trim($content) . "\n";
        if (@file_put_contents($dir . '/' . $file, $body) === false) {
            return ['ok' => false, 'error' => 'Não consegui salvar a memória.'];
        }
        @chmod($dir . '/' . $file, 0666);
        $this->updateMemoryIndex($dir, $file, $name, trim($content));
        return ['ok' => true];
    }

    /** Exclui uma memória (arquivo + linha no índice). */
    public function deleteMemory(string $projectUuid, string $id): void
    {
        $dir = $this->memoryDir($projectUuid);
        $file = basename($id);
        if ($file === '' || strcasecmp($file, 'MEMORY.md') === 0) {
            return;
        }
        @unlink($dir . '/' . $file);
        $this->updateMemoryIndex($dir, $file, null, null);
    }

    /** Mantém o MEMORY.md (índice do Claude Code): upsert/remoção da linha do arquivo. */
    private function updateMemoryIndex(string $dir, string $file, ?string $title, ?string $content): void
    {
        $idx = $dir . '/MEMORY.md';
        $lines = is_file($idx) ? explode("\n", (string) @file_get_contents($idx)) : [];
        // Remove qualquer linha que referencie este arquivo.
        $lines = array_values(array_filter($lines, fn ($l) => strpos($l, '](' . $file . ')') === false));
        if ($title !== null) {
            $hook = trim((string) strtok((string) $content, "\n"));
            $hook = mb_substr(preg_replace('/\s+/', ' ', $hook) ?? '', 0, 90);
            $lines[] = '- [' . $title . '](' . $file . ')' . ($hook !== '' ? ' — ' . $hook : '');
        }
        $body = trim(implode("\n", array_filter($lines, fn ($l) => trim($l) !== ''))) . "\n";
        @file_put_contents($idx, $body);
        @chmod($idx, 0666);
    }
}
