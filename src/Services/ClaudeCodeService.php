<?php

declare(strict_types=1);

namespace Ravi\Services;

use Ravi\Models\Conversation;
use Ravi\Models\Project;

/**
 * Executa tarefas usando o Claude Code (CLI) rodando DENTRO do sandbox Docker.
 *
 * Substitui o caminho ClaudeService + MultiAgentService quando CLAUDE_CODE_ENABLED=true.
 * O Claude Code traz o próprio loop de agente, ferramentas (bash/edição/etc) e subagentes,
 * então aqui só precisamos: disparar `claude -p --output-format stream-json` num container do
 * projeto, com rede temporária, e traduzir cada evento JSON para os tipos que o chat.js entende.
 *
 * Duas formas de uso:
 *   - run()      : UMA invocação bloqueante (plano, verificação, integração). Lança em erro.
 *   - runMany()  : VÁRIAS invocações CONCORRENTES (ondas de especialistas), multiplexando os
 *                  streams; cada evento é carimbado com a identidade do agente daquele job.
 *                  Falha de um job NÃO derruba os outros (vira agent_error daquele agente).
 *
 * Autenticação:
 *   - CLAUDE_CODE_OAUTH_TOKEN (assinatura Max) → SEM rate-limit de API.
 *   - fallback: CLAUDE_API_KEY.
 */
class ClaudeCodeService
{
    /** @var callable|null */
    private $onEvent = null;

    private string $model;
    private int $timeout;
    private int $stallTimeout;
    private string $image;
    private string $memoryLimit;
    private string $cpuLimit;
    private string $parallelMemoryLimit;
    private string $parallelCpuLimit;
    private string $workspaceBase;

    /** Tipo do projeto (web|app|both). Define imagem e variantes de prompt; web é o default inalterado. */
    private string $projectType = 'web';

    /** Usuário que disparou o trabalho, define o ACESSO ao contexto (memórias/servidores). 0 = dono. */
    public int $actingUserId = 0;

    /** Diretorio (host) onde os transcripts de sessao persistem; setado pelo Maestro no build. Vazio = efemero. */
    public string $sessionDir = '';

    /** Acumulado de uso por TIPO de token (para custo real com cache) desde a última leitura. */
    private array $usageTotals = ['input' => 0, 'cache_write' => 0, 'cache_read' => 0, 'output' => 0, 'runs' => 0];

    /** @var array<int,int> balance do Dev Autônomo: id da licença => agentes MEUS no ar agora. */
    private array $licInFlight = [];

    /** @var array<string,int> balance: sessão (session_id/resume) => licença já usada por ela (sticky, preserva o cache). */
    private array $licBySession = [];

    /** Motivo do último startJob recusado pelo TETO de uso de licença ('' = falha comum de processo). */
    private string $startBlock = '';

    public function __construct(private SandboxService $sandbox)
    {
        $this->model   = (string) env('CLAUDE_CODE_MODEL', 'claude-opus-4-8');
        $this->timeout = (int) env('CLAUDE_CODE_TIMEOUT', 1800);
        // Watchdog: se um agente ficar MUDO (sem produzir nada) por mais que isto, é considerado
        // travado e o motor o mata + remove o container sozinho (autorrecuperação, sem humano).
        $this->stallTimeout = (int) env('CLAUDE_CODE_STALL_TIMEOUT', 720);

        $cfg = config('sandbox');
        $this->image         = $cfg['image'] ?? 'ravi-sandbox:latest';
        $this->memoryLimit   = $cfg['memory_limit'] ?? '2g';
        $this->cpuLimit      = (string) ($cfg['cpu_limit'] ?? '2');
        // Containers das ondas paralelas são mais enxutos (cabem vários ao mesmo tempo).
        $this->parallelMemoryLimit = (string) env('CLAUDE_CODE_PARALLEL_MEMORY', '1g');
        $this->parallelCpuLimit    = (string) env('CLAUDE_CODE_PARALLEL_CPU', '1');
        $this->workspaceBase = $cfg['workspace']['base_path'] ?? (STORAGE_PATH . '/sandbox');
    }

    /** Registra o callback de streaming (recebe arrays no formato dos agent_events). */
    public function onUpdate(callable $cb): void
    {
        $this->onEvent = $cb;
    }

    /**
     * Seleciona a imagem do sandbox conforme o TIPO do projeto.
     *
     * REGRA DE OURO: projetos WEB (default) NUNCA mudam, continuam na imagem padrão
     * definida no construtor (ravi-sandbox:latest), com o mesmo fluxo de sempre.
     * Apenas app/both passam a usar a imagem MOBILE (RN/Expo + Android SDK).
     */
    public function selectImageForType(?string $type): void
    {
        $type = strtolower(trim((string) $type));
        $this->projectType = ($type === 'app' || $type === 'both') ? $type : 'web';
        if ($this->isMobile()) {
            $cfg = config('sandbox');
            $this->image = $cfg['image_mobile'] ?? 'ravi-sandbox-mobile:latest';
        }
        // web (ou desconhecido) → mantém a imagem padrão do construtor (caminho web inalterado).
    }

    /** Projeto mobile (RN/Expo)? Usado para ramificar imagem e prompts; web é o default. */
    private function isMobile(): bool
    {
        return $this->projectType === 'app' || $this->projectType === 'both';
    }

    /** Emite um evento bruto (sem carimbo de agente). Usado para status de nível superior. */
    private function emit(array $data): void
    {
        if ($this->onEvent) {
            ($this->onEvent)($data);
        }
    }

    /**
     * Compatível com a assinatura de MultiAgentService/AgentService::executeTask().
     */
    public function executeTask(Conversation $conversation, string $message): array
    {
        $project = Project::find($conversation->project_id);
        if (!$project) {
            throw new \RuntimeException("Projeto não encontrado para a conversa {$conversation->id}");
        }

        // Mobile (app/both) usa a imagem RN/Expo; web mantém a imagem padrão.
        $this->selectImageForType($project->type ?? 'web');

        $this->emit(['type' => 'status', 'message' => 'Preparando sandbox do projeto…']);
        $workspacePath = $this->ensureWorkspace($project->uuid);

        return $this->run($workspacePath, $project->uuid, $message);
    }

    /**
     * Garante que o diretório de workspace do projeto existe no host e retorna o caminho.
     */
    public function ensureWorkspace(string $projectUuid): string
    {
        $workspacePath = rtrim($this->workspaceBase, '/') . '/' . $projectUuid;
        if (!is_dir($workspacePath)) {
            @mkdir($workspacePath, 0777, true);
        }
        @chmod($workspacePath, 0777);
        return $workspacePath;
    }

    /**
     * Motor reutilizável: roda UMA invocação do Claude Code (bloqueante) e retorna o resultado.
     * Usado pelo plano, pelos verificadores e pela integração final do Maestro.
     *
     * @param array $opt ['system'=>?string, 'quiet'=>bool, 'clean'=>bool, 'model'=>?string, 'agent'=>?array]
     */
    public function run(string $workspacePath, string $projectUuid, string $prompt, array $opt = []): array
    {
        $job = [
            'id'     => 'single',
            'ws'     => $workspacePath,
            'uuid'   => $projectUuid,
            'prompt' => $prompt,
            'system' => $opt['system'] ?? null,
            'quiet'  => (bool) ($opt['quiet'] ?? false),
            'clean'  => (bool) ($opt['clean'] ?? true),
            'model'  => $opt['model'] ?? null,
            'agent'  => (isset($opt['agent']) && is_array($opt['agent'])) ? $opt['agent'] : null,
            'session_id' => $opt['session_id'] ?? null,
            'resume'     => $opt['resume'] ?? null,
            'fork'       => $opt['fork'] ?? null,
        ];

        // Single usa os limites cheios do sandbox (plano/integração precisam de mais fôlego).
        $results = $this->runJobs([$job], 1, $this->memoryLimit, $this->cpuLimit);
        $res = $results['single'] ?? null;

        if ($res === null || ($res['_no_result'] ?? false)) {
            throw new \RuntimeException($res['_error'] ?? 'Claude Code não retornou resultado.');
        }
        return $res;
    }

    /**
     * Roda VÁRIOS jobs CONCORRENTEMENTE (ondas de especialistas), com teto de concorrência.
     * Cada job é um array como em run(): {id, ws, uuid, prompt, system, agent, quiet?, clean?, model?}.
     * Os eventos saem interleaved, cada um carimbado com o 'agent' do job. Um job que falha
     * vira agent_error e NÃO interrompe os demais.
     *
     * @param array<int,array> $jobs
     * @return array<string,array> map id => resultado
     */
    public function runMany(array $jobs, int $maxParallel = 10): array
    {
        $maxParallel = max(1, $maxParallel);
        return $this->runJobs($jobs, $maxParallel, $this->parallelMemoryLimit, $this->parallelCpuLimit);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Executor concorrente (multiplexa stdout de N processos via stream_select)
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * @param array<int,array> $jobs
     * @return array<string,array>
     */
    private function runJobs(array $jobs, int $maxParallel, string $mem, string $cpu): array
    {
        $queue   = array_values($jobs);
        $active  = [];   // id => state
        $results = [];   // id => result

        // ESCALONAMENTO DE PARTIDA: cada agente é um fork que carrega o contexto INTEIRO do Arquiteto
        // na primeira chamada (pico de input-tokens). Subir N forks no MESMO instante soma esses picos
        // e estoura o rate limit de TRÁFEGO do plano (HTTP 429), mesmo com a sua cota de uso intacta.
        // Por isso espaçamos o INÍCIO de cada agente; uma vez no ar, eles seguem em paralelo sem trava.
        $staggerMs  = max(0, (int) env('SPECIALISTS_LAUNCH_STAGGER_MS', 3000));
        $lastLaunch = 0.0;

        while (!empty($queue) || !empty($active)) {
            // Preenche slots livres, UM de cada vez, respeitando o intervalo entre partidas.
            while (count($active) < $maxParallel && !empty($queue)) {
                if ($staggerMs > 0 && $lastLaunch > 0.0) {
                    $waitS = ($lastLaunch + $staggerMs / 1000.0) - microtime(true);
                    if ($waitS > 0) {
                        // Ainda não é hora do próximo. Se já há agentes no ar, deixa o multiplex
                        // abaixo cuidar deles enquanto o relógio corre; se não há nenhum, aguarda.
                        if (!empty($active)) { break; }
                        usleep((int) (min($waitS, 1.0) * 1000000));
                        continue;
                    }
                }
                $job        = array_shift($queue);
                $state      = $this->startJob($job, $mem, $cpu);
                $lastLaunch = microtime(true);
                if ($state === null) {
                    $results[$job['id']] = ['_no_result' => true, '_error' => $this->startBlock !== ''
                        ? $this->startBlock
                        : 'Falha ao iniciar o processo do Claude Code'];
                    $this->startBlock = '';
                    continue;
                }
                $active[$job['id']] = $state;
                break; // lança um por vez; reavalia o relógio no próximo giro do laço externo
            }

            if (empty($active)) {
                if (!empty($queue)) {
                    continue; // ainda há agentes a escalonar; volta para lançar o próximo na hora certa
                }
                break;
            }

            // Multiplexa: espera dados em qualquer stdout ativo.
            $read = [];
            foreach ($active as $id => $st) {
                $read[$id . '|out'] = $st['out'];
                if ($st['err'] !== null) {
                    $read[$id . '|err'] = $st['err'];
                }
            }
            $r = $read; $w = null; $e = null;
            $streams = array_values($r);
            if (@stream_select($streams, $w, $e, 1, 0) === false) {
                usleep(50000);
            }

            foreach ($active as $id => &$st) {
                // Lê stdout disponível.
                $chunk = $this->readAvailable($st['out']);
                if ($chunk !== '') {
                    $st['buf'] .= $chunk;
                    $st['buf'] = $this->drainLines($st['buf'], $st, $results);
                    $st['lastOut'] = microtime(true); // teve atividade → reseta o watchdog
                }
                // Acumula stderr (para diagnóstico).
                if ($st['err'] !== null) {
                    $errc = $this->readAvailable($st['err']);
                    if ($errc !== '') { $st['errbuf'] .= $errc; $st['lastOut'] = microtime(true); }
                }

                // Processo terminou e stdout esgotou?
                $status = proc_get_status($st['proc']);
                $stdoutEof = feof($st['out']);
                if (!$status['running'] && $stdoutEof) {
                    // Drena qualquer resto.
                    $st['buf'] .= $this->readAvailable($st['out']);
                    $st['buf'] = $this->drainLines($st['buf'], $st, $results);
                    $this->finishJob($id, $st, $results);
                    unset($active[$id]);
                    continue;
                }

                // WATCHDOG: agente mudo por tempo demais = travado → mata e remove o container
                // sozinho (autorrecuperação). O resultado vira erro e o Arquiteto re-despacha depois.
                if ($this->stallTimeout > 0 && (microtime(true) - (float) $st['lastOut']) > $this->stallTimeout) {
                    $this->reapStalledJob($id, $st, $results);
                    unset($active[$id]);
                    continue;
                }
            }
            unset($st);
        }

        // Contabiliza o uso (tokens/custo) de todos os jobs desta chamada, single e paralelo.
        foreach ($results as $r) {
            $this->accrueUsage($r);
        }

        return $results;
    }

    /** Soma o uso de um job no acumulador, separando os tipos de token (para custo real com cache). */
    private function accrueUsage(?array $res): void
    {
        if (!is_array($res) || !empty($res['_no_result'])) {
            return;
        }
        $u = $res['usage'] ?? null;
        if (is_array($u)) {
            $this->usageTotals['input']       += (int) ($u['input_tokens'] ?? 0);
            $this->usageTotals['cache_write'] += (int) ($u['cache_creation_input_tokens'] ?? 0);
            $this->usageTotals['cache_read']  += (int) ($u['cache_read_input_tokens'] ?? 0);
            $this->usageTotals['output']      += (int) ($u['output_tokens'] ?? 0);
        }
        $this->usageTotals['runs'] += 1;

        // Custo EM TEMPO REAL: emite o custo acumulado do job a cada rodada (a UI soma ao
        // custo-base da conversa). Assim o chip de custo cresce durante o build, não só no fim.
        try {
            $t = $this->usageTotals;
            if (function_exists('realApiCost')) {
                $cost = realApiCost((int) $t['input'], (int) $t['cache_write'], (int) $t['cache_read'], (int) $t['output']);
                $this->emit([
                    'type'     => 'usage_live',
                    'job_cost' => round((float) $cost, 6),
                ]);
            }
        } catch (\Throwable $e) { /* métrica não pode atrapalhar */ }
    }

    /**
     * Lê e ZERA o acumulado de uso desde a última leitura, detalhado por tipo de token:
     * input (envio novo), cache_write (escrita de cache), cache_read (leitura de cache) e output.
     * O Maestro usa isto para calcular o CUSTO REAL (cache é mais barato) e gravar no histórico.
     *
     * @return array{input:int,cache_write:int,cache_read:int,output:int,runs:int}
     */
    public function consumeUsage(): array
    {
        $u = $this->usageTotals;
        $this->usageTotals = ['input' => 0, 'cache_write' => 0, 'cache_read' => 0, 'output' => 0, 'runs' => 0];
        return $u;
    }

    /** Inicia um processo de job; devolve o estado ou null em falha. */
    private function startJob(array $job, string $mem, string $cpu): ?array
    {
        $system = $this->composeSystem($job['system'] ?? null, (string) ($job['ws'] ?? ''));
        // Modelo do Dev Autônomo: override explícito do job → senão resolve por usuário (pref → global → .env).
        $model  = \Ravi\Services\ModelService::cliModel((string) ($job['model'] ?? \Ravi\Services\ModelService::forUser($this->actingUserId, 'autonomous')));
        // Balance do Dev Autônomo: sorteia a licença DESTE agente no spawn (sticky). Null = caminho normal.
        $lic    = $this->pickJobLicense($job);
        // TETO de uso configurado pelo admin: agente novo não sobe por licença no teto — a folga
        // é da API. O balance já desvia das licenças no teto; este é o gate final (cobre o caminho
        // normal e o caso de todas as contas no teto). Agentes já em andamento terminam normalmente.
        $gateId = $lic['id'] ?? \Ravi\Services\LicenseService::licenseIdForUser((int) $this->actingUserId, 'autonomous');
        if ($gateId !== null && ($blk = \Ravi\Services\LicenseService::usageBlocked((int) $gateId, true)) !== null) {
            $this->startBlock = 'A conta do Claude está no teto de uso definido pelo administrador: '
                . $blk['pct'] . '% usado com teto de ' . $blk['limit'] . '% (janela ' . $blk['window'] . ')'
                . ($blk['reset'] !== null ? '; libera ' . date('d/m H:i', (int) $blk['reset']) : '')
                . '. Agentes novos aguardam a renovação — a folga fica reservada para a API.';
            logger()->warning('Dev Autônomo: spawn bloqueado pelo teto de uso da licença ' . $gateId
                . ' (' . $blk['pct'] . '% >= ' . $blk['limit'] . '%).');
            return null;
        }
        $env    = $this->buildEnv($lic['token'] ?? null);
        // Contexto permanente do projeto (memórias + fontes externas SSH) → ~/.claude/CLAUDE.md
        // e ~/.ravi/servers.json dentro do container (ver buildDockerCmd). Respeita o acesso do ator.
        $env    = array_merge($env, $this->contextInjectionEnv((string) ($job['uuid'] ?? '')));
        $name   = 'ravi-cc-' . substr((string) $job['uuid'], 0, 8) . '-' . uniqid();
        $sess = [
            'dir'        => $this->sessionDir,
            'session_id' => $job['session_id'] ?? null,
            'resume'     => $job['resume'] ?? null,
            'fork'       => $job['fork'] ?? null,
        ];
        $this->captureDebug($job, $system, $sess);
        $cmd    = $this->buildDockerCmd((string) $job['ws'], $env, $system, $model, $name, $mem, $cpu, $sess);

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $pipes = [];
        $proc  = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($proc)) {
            return null;
        }

        // Prompt via stdin (evita limites de argumento / escaping).
        fwrite($pipes[0], (string) $job['prompt']);
        fclose($pipes[0]);

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $state = [
            'id'        => $job['id'],
            'proc'      => $proc,
            'out'       => $pipes[1],
            'err'       => $pipes[2],
            'buf'       => '',
            'errbuf'    => '',
            'agent'     => (isset($job['agent']) && is_array($job['agent'])) ? $job['agent'] : null,
            'quiet'     => (bool) ($job['quiet'] ?? false),
            'clean'     => (bool) ($job['clean'] ?? true),
            'lastText'  => '',
            'sessionId' => null,
            'final'     => null,
            'container' => $name,          // nome do container (p/ o watchdog forçar a remoção)
            'lastOut'   => microtime(true), // último instante com saída (watchdog de travamento)
            'stalled'   => false,
            // Licença do balance usada por ESTE job (null = licença do escopo, caminho normal).
            'license'   => $lic !== null ? ['id' => $lic['id'], 'name' => $lic['name']] : null,
        ];
        if ($lic !== null) {
            $this->licInFlight[$lic['id']] = ($this->licInFlight[$lic['id']] ?? 0) + 1;
        }

        // Anuncia que ESTE agente entrou em campo (painel de agentes ao vivo no chat).
        // Carimbado com a identidade do agente; o 'id' do job distingue cada slot paralelo.
        if (!$state['quiet'] && $state['agent'] !== null) {
            $this->emitFor($state, ['type' => 'agent_start', 'id' => $state['id']]);
        }

        return $state;
    }

    /** Fecha o processo e consolida o resultado do job (ou erro). */
    private function finishJob(string $id, array &$st, array &$results): void
    {
        $errbuf = $st['errbuf'] . $this->readAvailable($st['err']);
        @fclose($st['out']);
        if ($st['err'] !== null) {
            @fclose($st['err']);
        }
        $exit = proc_close($st['proc']);
        $this->releaseJobLicense($st);

        // Tira ESTE agente do painel de agentes ao vivo (terminou, com sucesso ou erro).
        if (!$st['quiet'] && ($st['agent'] ?? null) !== null) {
            $this->emitFor($st, ['type' => 'agent_done', 'id' => $id, 'ok' => ($st['final'] !== null)]);
        }

        if ($st['final'] !== null) {
            $results[$id] = $st['final'];
            return;
        }

        // Sem resultado final → erro.
        $msg = 'Claude Code não retornou resultado (exit=' . $exit . ').';
        if ($exit === 124) {
            $msg = 'Claude Code excedeu o timeout de ' . $this->timeout . 's.';
        }
        if (trim($errbuf) !== '') {
            $msg .= ' ' . mb_substr($errbuf, 0, 600);
        }
        $this->noteLicenseLimit($st, $msg);
        if (!$st['quiet']) {
            $this->emitFor($st, ['type' => 'agent_error', 'error' => $msg]);
        }
        $results[$id] = ['_no_result' => true, '_error' => $msg];
    }

    /**
     * WATCHDOG: mata um agente TRAVADO (mudo além do stallTimeout) e remove o container à força,
     * para não deixar processos pendurados (ex.: um `php -S` deixado em primeiro plano). O sistema
     * se recupera SOZINHO, sem intervenção humana. Se o job já tinha um resultado final no buffer,
     * aproveita-o; senão marca como erro de travamento para o Arquiteto re-despachar.
     */
    private function reapStalledJob(string $id, array &$st, array &$results): void
    {
        $secs = $this->stallTimeout;

        // Encerra o processo do `docker run` (TERM e, se preciso, KILL).
        @proc_terminate($st['proc'], 15);
        // Remove o container à força, isto MATA qualquer processo pendurado dentro dele.
        if (!empty($st['container'])) {
            @exec('docker rm -f ' . escapeshellarg((string) $st['container']) . ' 2>/dev/null >/dev/null &');
        }
        @proc_terminate($st['proc'], 9);
        @fclose($st['out']);
        if ($st['err'] !== null) {
            @fclose($st['err']);
        }
        @proc_close($st['proc']);
        $this->releaseJobLicense($st);

        if (!$st['quiet'] && ($st['agent'] ?? null) !== null) {
            $this->emitFor($st, ['type' => 'agent_done', 'id' => $id, 'ok' => ($st['final'] !== null)]);
        }

        // Se o agente já tinha entregue o resultado final antes de pendurar, aproveita-o.
        if ($st['final'] !== null) {
            $results[$id] = $st['final'];
            return;
        }

        $msg = 'Agente reiniciado pelo supervisor: ficou sem responder por mais de ' . $secs . 's '
            . '(possível processo travado, ex.: servidor em primeiro plano). A tarefa será retomada automaticamente.';
        if (!$st['quiet']) {
            $this->emitFor($st, ['type' => 'status', 'message' => $msg]);
        }
        $results[$id] = ['_no_result' => true, '_error' => $msg, '_stalled' => true];
    }

    /** Lê tudo o que estiver disponível num pipe não-bloqueante. */
    private function readAvailable($pipe): string
    {
        $data = '';
        while (($chunk = fread($pipe, 65536)) !== false && $chunk !== '') {
            $data .= $chunk;
            if (strlen($chunk) < 65536) {
                break;
            }
        }
        return $data;
    }

    /** Processa as linhas completas no buffer; devolve o resto incompleto. */
    private function drainLines(string $buf, array &$st, array &$results): string
    {
        while (($pos = strpos($buf, "\n")) !== false) {
            $line = trim(substr($buf, 0, $pos));
            $buf  = substr($buf, $pos + 1);
            if ($line === '') {
                continue;
            }
            $evt = json_decode($line, true);
            if (!is_array($evt)) {
                continue; // ruído não-JSON
            }
            $final = $this->handleStreamEvent($evt, $st);
            if ($final !== null) {
                $st['final'] = $final;
            }
        }
        return $buf;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Montagem de comando / ambiente
    // ──────────────────────────────────────────────────────────────────────────

    /** Acrescenta as regras fixas de comunicação ao system-prompt da persona. */
    private function composeSystem(?string $base, string $ws = ''): string
    {
        // MOBILE (app/both): regras de ambiente/qualidade próprias de RN/Expo.
        // REGRA DE OURO: o caminho WEB abaixo permanece INALTERADO.
        if ($this->isMobile()) {
            return $this->composeSystemMobile($base, $ws);
        }

        $system = $base ?? (
            'Você é o Ravi Developer. Trabalhe de forma autônoma no diretório /workspace, '
            . 'crie código completo e funcional, e resuma o resultado ao final.'
        );

        // Contexto de onboarding gerado na importação: todo agente "já conhece" o projeto.
        $ctx = $this->projectContext($ws);
        if ($ctx !== '') {
            $system .= "\n\nCONTEXTO DO PROJETO (resumo do onboarding, leia antes de agir; "
                . "o arquivo completo está em /workspace/.maestro/context.md):\n" . $ctx;
        }

        $system .= "\n\nREGRAS DE COMUNICAÇÃO (obrigatórias):\n"
            . "- Responda e narre SEMPRE em português do Brasil.\n"
            . "- Escreva mensagens curtas e em linguagem natural, como um colega explicando o que está fazendo.\n"
            . "- NÃO cole blocos de código, comandos de terminal nem saídas brutas no texto, o código vai para os arquivos do projeto, não para o chat.\n"
            . "- Ao usar a ferramenta Bash, escreva o campo 'description' SEMPRE em português (ex.: 'Listar arquivos', 'Instalar dependências', 'Rodar os testes').\n"
            . "- O usuário NÃO enxerga o sistema de arquivos nem caminhos como /workspace. Ele vê apenas os arquivos no PAINEL À DIREITA do chat e baixa um .zip. Nunca peça para ele 'ver /workspace/...'; refira-se ao 'painel de arquivos à direita' e ao botão de baixar.\n"
            . "- Tom PROFISSIONAL e sóbrio: NÃO use emojis nem ícones decorativos. E NUNCA use o caractere de travessão (em dash) em NENHUM texto que você escrever: mensagens do chat, textos e rótulos da interface, README ou comentários. No lugar use vírgula, dois-pontos, parênteses ou reescreva a frase. É uma plataforma séria de desenvolvimento.\n"
            . "- Ambiente: PHP 8.3 disponível. Para rodar/testar no sandbox, prefira SQLite (sem servidor) como banco e deixe a troca para MariaDB/MySQL fácil via configuração (.env). NÃO tente instalar servidores de banco aqui.\n"
            . "- NUNCA rode um processo que BLOQUEIA o terminal em primeiro plano (servidor, watcher, tail -f, etc.), isso TRAVA você para sempre. Para testar um servidor web: suba em SEGUNDO PLANO e mate ao terminar, ex.: 'php -S 127.0.0.1:8080 -t public >/tmp/srv.log 2>&1 & SRV=\$!; sleep 1; curl -i http://127.0.0.1:8080/; kill \$SRV'. SEMPRE use '&' para o servidor e SEMPRE encerre o processo depois. Em QUALQUER comando que possa demorar/pendurar, use 'timeout' (ex.: 'timeout 60 <comando>'). Nunca deixe um processo rodando ao terminar sua tarefa.\n"
            . "- Narre o MÍNIMO: no máximo uma frase curta no começo dizendo o que vai fazer e um resumo objetivo no final. NÃO comente verificações de ambiente, listagens de diretório, versões instaladas nem passos triviais, apenas execute. O usuário acompanha o progresso por um indicador de status; evite encher o chat de comentários.\n"
            . "- REGRA DE PRODUTO (inviolável): em QUALQUER interface ou JavaScript que você escrever, NUNCA use os diálogos nativos do navegador, proibido alert(), confirm() e prompt(). Use SEMPRE um modal/diálogo próprio (acessível) e mensagens inline/toast. Use também caminhos relativos (sem barra inicial) para a app funcionar sob subdiretório.\n"
            . "- SERVIR ESTÁTICOS NO php -S (CRÍTICO p/ o preview): se o start_command usa um front-controller como ROTEADOR do servidor embutido (ex.: 'php -S 0.0.0.0:\$PORT -t public public/index.php'), o roteador INTERCEPTA TODOS os pedidos, inclusive CSS/JS/imagens, e, se não tratar, devolve HTML no lugar do arquivo (o navegador recusa o CSS por MIME 'text/html' e a página fica SEM ESTILO). Por isso, o index.php roteador DEVE, logo no início, devolver o arquivo estático existente, mas NUNCA um arquivo .php (senão o próprio index.php é servido vazio e os links 'index.php?r=...' viram PÁGINA BRANCA): `if (PHP_SAPI === 'cli-server') { \$p = parse_url(\$_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH); if (\$p !== '/' && is_file(__DIR__ . \$p) && substr(\$p, -4) !== '.php') { return false; } }`. SEMPRE inclua esse guard (com a exclusão de .php) quando houver roteador. PROVE: peça o CSS via HTTP e confirme content-type 'text/css'; e abra um link interno tipo 'index.php?r=...' confirmando que retorna CONTEÚDO (não 0 bytes / branco).\n"
            . "\nQUALIDADE: proporcional no TAMANHO, inegociável na CORREÇÃO e na SEGURANÇA.\n"
            . "PROPORCIONAL (só isto): número de arquivos, estrutura e abstrações, framework de testes e documentação.\n"
            . "- Entregue o MENOR número de arquivos e a estrutura MAIS SIMPLES que resolva BEM o pedido. Um pedido pequeno (ex.: uma tela de login) cabe em POUCOS arquivos (tipicamente 2 a 6), NÃO em dezenas. NÃO crie abstrações/camadas/classes por antecipação; prefira juntar responsabilidades pequenas no mesmo arquivo.\n"
            . "- TESTES: NUNCA crie um framework/runner de teste PRÓPRIO e, para tarefa pequena, NÃO instale via composer um framework de testes (ex.: PHPUnit), se for testar, um script de verificação curto e direto basta. Mas isso NÃO substitui rodar o sistema de verdade (veja CORREÇÃO).\n"
            . "- DOCUMENTAÇÃO proporcional: um README curto basta para projeto pequeno.\n"
            . "INEGOCIÁVEL, CORREÇÃO (sempre, qualquer tamanho): o que você entrega TEM que RODAR sem erro e o FLUXO PRINCIPAL tem que FUNCIONAR. PROVE isso EXECUTANDO o sistema de verdade antes de considerar pronto, para app web: suba o servidor (php -S) e exercite as rotas reais (ex.: abrir a página, fazer login, acessar a área logada, logout) confirmando que NENHUMA retorna erro/500. Se quebrar, conserte; NÃO entregue algo que não roda. Garanta também o setup: se usa banco, deixe a migração/seed prontas e referencie-as no app.json (setup_command) para o ambiente subir do zero.\n"
            . "- RAIZ OBRIGATÓRIA (app web): a URL raiz \"/\" do servidor TEM que abrir a aplicação, o preview SEMPRE acessa \"/\", então NÃO basta a página existir só em login.php/outra. Crie um index.php na docroot que seja a entrada (a própria home/login OU um redirect para a tela inicial). PROVE rodando: 'curl -i http://127.0.0.1:PORTA/' tem que devolver 200 (ou um 302 para a tela certa), NUNCA 404. Se a docroot tiver subpasta (ex.: public/), o index.php fica nela e o start_command usa '-t public'.\n"
            . "INEGOCIÁVEL, SEGURANÇA (sempre, por RISCO e não por tamanho): TODA entrega passa por uma revisão de segurança do que foi criado, encontre e CORRIJA as falhas. Mesmo um simples formulário de contato com uma brecha (ex.: SQL injection) pode expor o banco inteiro. Aplique e verifique: prepared statements (nunca concatenar SQL), validação e escape de TODA entrada/saída (anti-XSS), CSRF em formulários, senhas com hash forte, sessão/cookies seguros, sem segredos versionados, controle de acesso nas rotas protegidas. NÃO precisa de relatório extenso, a entrega é o código CORRIGIDO e seguro.\n"
            . "Robustez = código CERTO, simples, FUNCIONANDO e SEGURO, não é quantidade de arquivos nem cerimônia.";

        return $system;
    }

    /**
     * System-prompt do caminho MOBILE (projeto app/both): React Native + Expo (TypeScript).
     * Compartilha o NÚCLEO (PT-BR, narração enxuta, proporcionalidade) com o web, mas troca o
     * ambiente e o "como provar que roda": aqui NÃO há PHP/banco-servidor/php -S, a prova é
     * `expo export --platform web` gerar dist/ sem erro + checagem de tipos. NÃO mexe no web.
     */
    private function composeSystemMobile(?string $base, string $ws = ''): string
    {
        $system = $base ?? (
            'Você é o Ravi Developer, construindo um app MOBILE (React Native + Expo, TypeScript). '
            . 'Trabalhe de forma autônoma no diretório /workspace, crie código completo e funcional, e resuma o resultado ao final.'
        );

        // Mesmo contexto de onboarding do caminho web (já conhece o projeto importado).
        $ctx = $this->projectContext($ws);
        if ($ctx !== '') {
            $system .= "\n\nCONTEXTO DO PROJETO (resumo do onboarding, leia antes de agir; "
                . "o arquivo completo está em /workspace/.maestro/context.md):\n" . $ctx;
        }

        // ── Núcleo compartilhado de comunicação (igual ao web) ───────────────────
        $system .= "\n\nREGRAS DE COMUNICAÇÃO (obrigatórias):\n"
            . "- Responda e narre SEMPRE em português do Brasil.\n"
            . "- Escreva mensagens curtas e em linguagem natural, como um colega explicando o que está fazendo.\n"
            . "- NÃO cole blocos de código, comandos de terminal nem saídas brutas no texto, o código vai para os arquivos do projeto, não para o chat.\n"
            . "- Ao usar a ferramenta Bash, escreva o campo 'description' SEMPRE em português (ex.: 'Instalar dependências', 'Exportar a versão web', 'Conferir os tipos').\n"
            . "- O usuário NÃO enxerga o sistema de arquivos nem caminhos como /workspace. Ele vê apenas os arquivos no PAINEL À DIREITA do chat e baixa um .zip. Nunca peça para ele 'ver /workspace/...'; refira-se ao 'painel de arquivos à direita' e ao botão de baixar.\n"
            . "- Tom PROFISSIONAL e sóbrio: NÃO use emojis nem ícones decorativos. E NUNCA use o caractere de travessão (em dash) em NENHUM texto que você escrever: mensagens do chat, textos e rótulos da interface, README ou comentários. No lugar use vírgula, dois-pontos, parênteses ou reescreva a frase. É uma plataforma séria de desenvolvimento.\n"
            . "- Narre o MÍNIMO: no máximo uma frase curta no começo dizendo o que vai fazer e um resumo objetivo no final. NÃO comente verificações de ambiente, listagens de diretório, versões instaladas nem passos triviais, apenas execute.\n"
            . "- NUNCA rode um processo que BLOQUEIA o terminal em primeiro plano (servidor de dev/Metro, watcher, 'expo start', 'tail -f', etc.), isso TRAVA você para sempre. Para tudo aqui use comandos que TERMINAM: 'npx expo export', 'npx tsc --noEmit', 'gradlew assembleDebug'. Se PRECISAR de um processo de fundo, suba com '&' e mate ao terminar, e use 'timeout' em qualquer comando que possa pendurar. Nunca deixe um processo rodando ao terminar sua tarefa.\n";

        // ── Ambiente MOBILE (substitui o bloco PHP/SQLite/php -S do web) ──────────
        $system .= "\nAMBIENTE (mobile, leia com atenção):\n"
            . "- A imagem já traz Node 20, Expo, react-native, Android SDK + JDK 17 + Gradle e eas-cli. NÃO precisa instalar SDK/JDK; NÃO há PHP nem servidor de banco aqui (não tente subir php -S, MySQL, etc.).\n"
            . "- O app é React Native + Expo no fluxo MANAGED, em TypeScript. Para projeto novo, faça o scaffold com 'create-expo-app' (template TypeScript) e desenvolva sobre ele. Use bibliotecas do ecossistema Expo (ex.: expo-router/React Navigation, @expo/vector-icons, expo-secure-store) quando ajudarem.\n"
            . "- PROVA WEB (preview): para o app aparecer no preview, ele precisa exportar para web. Garanta as dependências web ('npx expo install react-dom react-native-web @expo/metro-runtime') e que 'npx expo export --platform web' gere /workspace/dist/index.html SEM erro. Use componentes e estilos compatíveis com react-native-web (View/Text/Pressable/StyleSheet; evite APIs exclusivas de plataforma sem fallback).\n"
            . "- Para rede/persistência, prefira soluções locais que rodam no sandbox sem servidor externo (ex.: AsyncStorage/SQLite do Expo, ou um mock de dados) e deixe pontos de integração com API claros para troca depois.\n";

        // ── REGRA DE PRODUTO (mobile) ────────────────────────────────────────────
        $system .= "\nREGRA DE PRODUTO (mobile, inviolável):\n"
            . "- Interface NATIVA e caprichada: layout consistente, espaçamento e tipografia coerentes, áreas de toque adequadas, estados de carregando/erro/vazio, feedback de toque (Pressable), e acessibilidade (accessibilityLabel/role).\n"
            . "- Diálogos: use componentes próprios (Modal do React Native ou um componente de diálogo) ou a API Alert do React Native, NUNCA os diálogos do navegador (alert()/confirm()/prompt()), que não existem em app nativo.\n"
            . "- Suporte aos dois alvos (Android e iOS): evite código específico de uma plataforma sem fallback; use Platform.select quando necessário. O build de iOS é feito noutro nó (macOS); aqui você garante que o código está pronto para ambos.\n";

        // ── Qualidade: núcleo compartilhado com web (proporcionalidade) ──────────
        $system .= "\nQUALIDADE: proporcional no TAMANHO, inegociável na CORREÇÃO e na SEGURANÇA.\n"
            . "PROPORCIONAL (só isto): número de arquivos/telas/componentes, abstrações, testes e documentação.\n"
            . "- Entregue o MENOR número de arquivos e a estrutura MAIS SIMPLES que resolva BEM o pedido. Um pedido pequeno cabe em POUCOS arquivos (uma tela ou duas), NÃO em dezenas. NÃO crie camadas/abstrações por antecipação.\n"
            . "- TESTES proporcionais: para algo pequeno, a checagem de tipos (tsc) e o export web sem erro já bastam; não monte uma suíte pesada sem necessidade.\n"
            . "- DOCUMENTAÇÃO proporcional: um README curto basta para projeto pequeno.\n"
            . "INEGOCIÁVEL, CORREÇÃO (sempre, qualquer tamanho): o que você entrega TEM que COMPILAR e RODAR. PROVE antes de considerar pronto: 'npx tsc --noEmit' sem erros de tipo E 'npx expo export --platform web' concluindo e gerando /workspace/dist/index.html. Se quebrar, conserte; NÃO entregue algo que não exporta. (Build de APK Android é desejável quando couber, mas a prova mínima é tipos + export web.)\n"
            . "INEGOCIÁVEL, SEGURANÇA (sempre, por RISCO e não por tamanho): revise o que foi criado e CORRIJA as falhas. Mobile: NUNCA versione segredos/API keys no código do app (o bundle é distribuído ao usuário), use variáveis de ambiente/configuração e armazenamento seguro (expo-secure-store) para tokens; valide e trate TODA entrada do usuário e TODA resposta de API; use HTTPS para chamadas externas; não logue dados sensíveis. NÃO precisa de relatório extenso, a entrega é o código CORRIGIDO e seguro.\n"
            . "Robustez = código CERTO, simples, COMPILANDO, EXPORTANDO e SEGURO, não é quantidade de arquivos nem cerimônia.";

        return $system;
    }

    /**
     * Lê o resumo de onboarding (.maestro/context.md) do workspace, limitado em tamanho para
     * não inflar o prompt (especialmente em ondas paralelas). Vazio se não houver import.
     */
    private function projectContext(string $ws): string
    {
        if ($ws === '') {
            return '';
        }
        $f = rtrim($ws, '/') . '/.maestro/context.md';
        if (!is_file($f)) {
            return '';
        }
        $txt = trim((string) @file_get_contents($f));
        if ($txt === '') {
            return '';
        }
        $max = 3500; // cap para o digest; o arquivo completo segue no workspace para leitura sob demanda
        if (mb_strlen($txt) > $max) {
            $txt = mb_substr($txt, 0, $max) . "\n…(resumo truncado; veja /workspace/.maestro/context.md)";
        }
        return $txt;
    }

    /** Variáveis de ambiente passadas ao container. $licToken = licença já sorteada pelo balance. */
    private function buildEnv(?string $licToken = null): array
    {
        $env = [
            'HOME'                                     => '/tmp/cc-home',
            'DISABLE_AUTOUPDATER'                      => '1',
            'DISABLE_TELEMETRY'                        => '1',
            'DISABLE_ERROR_REPORTING'                  => '1',
            'CLAUDE_CODE_DISABLE_NONESSENTIAL_TRAFFIC' => '1',
        ];

        // Balance do Dev Autônomo: o job já vem com a licença sorteada; senão, caminho normal:
        // licença resolvida pelo ator (override do usuário → global do escopo; sem fallback).
        $token = ($licToken !== null && $licToken !== '')
            ? $licToken
            : \Ravi\Services\LicenseService::tokenForUser((int) ($this->actingUserId ?? 0), 'autonomous');
        if ($token !== '') {
            $env['CLAUDE_CODE_OAUTH_TOKEN'] = $token;
        } else {
            $env['ANTHROPIC_API_KEY'] = (string) config('claude.api_key');
        }

        return $env;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Balance de licenças do Dev Autônomo (sorteio sticky por agente + cooldown)
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Licença deste job no modo balance. STICKY POR SESSÃO: um job que RETOMA uma sessão
     * (resume/session_id, ex.: o Arquiteto entre ondas) reusa a licença que a sessão já
     * usava, preservando o prompt-cache (que é por conta). FORKS não são sticky de
     * propósito: as ondas paralelas de especialistas são forks, e é nelas que o balance
     * precisa espalhar a carga. Null = balance não se aplica (buildEnv segue o caminho normal).
     *
     * @return array{id:int,name:string,token:string}|null
     */
    private function pickJobLicense(array $job): ?array
    {
        $key = (string) ($job['resume'] ?? $job['session_id'] ?? '');
        if ($key !== '' && isset($this->licBySession[$key])) {
            $id = $this->licBySession[$key];
            // Reusa só se a licença segue utilizável; esgotada/no teto/desativada → sorteia outra abaixo.
            if (!\Ravi\Services\LicenseService::inCooldown($id)
                && \Ravi\Services\LicenseService::usageBlocked($id, true) === null) {
                $t = \Ravi\Services\LicenseService::token($id);
                if ($t !== '') {
                    $l = \Ravi\Services\LicenseService::find($id);
                    return ['id' => $id, 'name' => (string) ($l['name'] ?? ('#' . $id)), 'token' => $t];
                }
            }
        }
        $pick = \Ravi\Services\LicenseService::pickAutonomous((int) $this->actingUserId, $this->licInFlight);
        if ($pick !== null && $key !== '') {
            $this->licBySession[$key] = $pick['id'];
        }
        return $pick;
    }

    /** Devolve o slot da licença do job ao terminar (normal ou pelo watchdog). */
    private function releaseJobLicense(array $st): void
    {
        $id = (int) ($st['license']['id'] ?? 0);
        if ($id > 0 && isset($this->licInFlight[$id])) {
            $this->licInFlight[$id]--;
            if ($this->licInFlight[$id] <= 0) {
                unset($this->licInFlight[$id]);
            }
        }
    }

    /**
     * Detecta "limite de uso atingido" no erro de um job do balance e põe a licença em
     * COOLDOWN até o reset que a própria mensagem informa (fallback 30 min): os PRÓXIMOS
     * agentes saem pelas demais licenças em vez de insistir na esgotada. Erros 529
     * (Overloaded) são do SERVIDOR, não da licença, e ficam de fora de propósito.
     */
    private function noteLicenseLimit(array $st, string $text): void
    {
        $id = (int) ($st['license']['id'] ?? 0);
        if ($id <= 0) {
            return;
        }
        if (!preg_match('/hit your [a-z0-9 -]{0,20}limit|usage limit reached/i', $text)) {
            return;
        }

        // "resets 11:30pm (UTC)" → epoch da PRÓXIMA ocorrência desse horário em UTC.
        $until = null;
        if (preg_match('/resets?(?:\s+at)?\s+(\d{1,2})(?::(\d{2}))?\s*(am|pm)/i', $text, $m)) {
            $h = ((int) $m[1]) % 12 + (strtolower($m[3]) === 'pm' ? 12 : 0);
            $cand = gmmktime($h, (int) ($m[2] !== '' ? $m[2] : 0), 0);
            $until = $cand > time() ? $cand : $cand + 86400;
        }
        \Ravi\Services\LicenseService::startCooldown($id, $until);

        $name = (string) ($st['license']['name'] ?? ('#' . $id));
        $msg  = 'A licença "' . $name . '" atingiu o limite de uso'
            . ($until !== null ? ' (libera por volta de ' . date('H:i', $until) . ')' : '')
            . '. Os próximos agentes saem pelas outras licenças do balance.';
        if (!$st['quiet']) {
            $this->emitFor($st, ['type' => 'status', 'message' => $msg]);
        }
        logger()->warning('Dev Autônomo: licença ' . $id . ' (' . $name . ') em cooldown por limite de uso. '
            . mb_substr($text, 0, 200));
    }

    /**
     * Env (base64) das memórias/servidores do projeto para o ator. Vazio se sem acesso.
     * Ator = $this->actingUserId (setado pelo worker/Maestro) ou, na falta, o DONO do projeto.
     */
    private function contextInjectionEnv(string $uuid): array
    {
        if ($uuid === '') {
            return [];
        }
        try {
            $p = \Ravi\Models\Project::findByUuid($uuid);
            if (!$p) {
                return [];
            }
            $actor = $this->actingUserId > 0 ? $this->actingUserId : (int) ($p->user_id ?? 0);
            return (new \Ravi\Services\ProjectContextService())->injectionEnv($p, $actor);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** DEBUG: grava o que CADA agente recebe (system + diretiva + sessao) p/ auditar o nivel dos prompts. */
    private function captureDebug(array $job, string $system, array $sess): void
    {
        if (!filter_var(env('CLAUDE_CODE_DEBUG_PROMPTS', true), FILTER_VALIDATE_BOOLEAN)) { return; }
        try {
            $ws = (string) ($job['ws'] ?? '');
            if ($ws === '') { return; }
            $dir = rtrim($ws, '/') . '/.maestro/debug';
            @mkdir($dir, 0777, true);
            $agent = (isset($job['agent']) && is_array($job['agent'])) ? (string) ($job['agent']['key'] ?? 'arquiteto') : 'arquiteto';
            $id = (string) ($job['id'] ?? '');
            $si = !empty($sess['fork']) ? ('FORK de ' . $sess['fork']) : (!empty($sess['resume']) ? ('RESUME ' . $sess['resume']) : (!empty($sess['session_id']) ? ('NOVA SESSAO ' . $sess['session_id']) : 'efemera'));
            $safe = preg_replace('/[^a-z0-9]/i', '', $agent) . '-' . preg_replace('/[^a-z0-9]/i', '', $id);
            $file = $dir . '/' . date('His') . '-' . $safe . '.txt';
            $out = '=== AGENTE: ' . $agent . ' | id=' . $id . ' | sessao: ' . $si . " ===\n\n"
                . "----- SYSTEM PROMPT -----\n" . $system . "\n\n"
                . "----- DIRETIVA -----\n" . (string) ($job['prompt'] ?? '') . "\n";
            @file_put_contents($file, $out);
        } catch (\Throwable $e) {}
    }

    /** Monta o comando `docker run` efêmero (com rede) para uma invocação. */
    private function buildDockerCmd(string $workspacePath, array $env, string $append, string $model, string $name, string $mem, string $cpu, array $sess = []): string
    {
        // Sessao: estabelecer (session_id), continuar (resume) ou clonar contexto (fork).
        $sessFlag = '';
        if (!empty($sess['fork']))           { $sessFlag = ' --resume ' . escapeshellarg((string) $sess['fork']) . ' --fork-session'; }
        elseif (!empty($sess['resume']))     { $sessFlag = ' --resume ' . escapeshellarg((string) $sess['resume']); }
        elseif (!empty($sess['session_id'])) { $sessFlag = ' --session-id ' . escapeshellarg((string) $sess['session_id']); }
        // Persistencia dos transcripts: o Arquiteto (establish/resume) grava de volta no host;
        // o fork apenas LE (sem gravar) para nao haver corrida entre agentes paralelos.
        $persistBack = (!empty($sess['dir']) && empty($sess['fork']))
            ? '; __rc=$?; cp -f "$HOME/.claude/projects/-workspace/"*.jsonl /tmp/ravi-sess/ 2>/dev/null; exit $__rc'
            : '';
        $envFlags = '';
        foreach ($env as $k => $v) {
            if ($v === '' || $v === null) {
                continue;
            }
            $envFlags .= ' -e ' . escapeshellarg($k . '=' . $v);
        }

        // umask 000: arquivos/dirs criados no workspace ficam world-writable, para o app (www-data)
        // poder editar/excluir pelo navegador de arquivos do hub mesmo sendo de outro dono (uid sandbox).
        $inner = 'mkdir -p "$HOME/.claude/projects/-workspace" "$HOME/.ravi" 2>/dev/null; '
            // Memoria compartilhada via symlink: o mount fica em /tmp/ravi-mem (neutro) e o
            // proprio sandbox cria ~/.claude/projects/-workspace (no tmpfs, dono sandbox) e aponta
            // memory -> /tmp/ravi-mem. Sem isso o Docker criaria o dir-pai como ROOT e o claude
            // nao conseguiria gravar o transcript da sessao (falhava em silencio: exit=-1).
            . 'ln -sfn /tmp/ravi-mem "$HOME/.claude/projects/-workspace/memory" 2>/dev/null; [ -d /tmp/ravi-sess ] && cp -n /tmp/ravi-sess/*.jsonl "$HOME/.claude/projects/-workspace/" 2>/dev/null; umask 000; '
            // Memórias permanentes + fontes externas (injetadas via env base64). Fora do /workspace.
            . '[ -n "$RAVI_CLAUDE_MD_B64" ] && printf %s "$RAVI_CLAUDE_MD_B64" | base64 -d > "$HOME/.claude/CLAUDE.md"; '
            . '[ -n "$RAVI_SERVERS_B64" ] && { printf %s "$RAVI_SERVERS_B64" | base64 -d > "$HOME/.ravi/servers.json"; chmod 600 "$HOME/.ravi/servers.json"; ravi-ctx-keys 2>/dev/null || true; }; '
            . 'claude -p'
            . ' --output-format stream-json --verbose'
            . ' --model ' . escapeshellarg($model)
            . ' --permission-mode bypassPermissions'
            . $sessFlag
            . ' --append-system-prompt ' . escapeshellarg($append)
            . $persistBack;

        // Memória do Claude Code COMPARTILHADA com a IDE e PERSISTENTE: o mesmo diretório do host
        // (storage/claude-memory/<uuid>) montado em ~/.claude/projects/-workspace/memory → o que o
        // Claude salva no chat é o mesmo que a IDE vê (e vice-versa), e não se perde.
        $ctxSvc = new \Ravi\Services\ProjectContextService();
        $memDir = $ctxSvc->memoryDir(basename($workspacePath));

        // #3, Projetos LIGADOS: monta o workspace de cada alvo ativo em /linked/<slug> (fora do
        // /workspace). :ro para leitura, :rw quando a ligação permite edição. Best-effort.
        $linkMounts = '';
        try {
            $pLink = \Ravi\Models\Project::findByUuid(basename($workspacePath));
            if ($pLink) {
                $actorLink = $this->actingUserId > 0 ? $this->actingUserId : (int) ($pLink->user_id ?? 0);
                foreach ($ctxSvc->linkedMounts($pLink, $actorLink) as $m) {
                    $linkMounts .= ' -v ' . escapeshellarg($m['host'] . ':' . $m['container'] . ($m['ro'] ? ':ro' : ':rw'));
                }
            }
        } catch (\Throwable $e) { /* ligações nunca quebram o run */ }

        return 'timeout ' . $this->timeout . ' docker run --rm -i'
            . ' --name ' . escapeshellarg($name)
            . ' --memory=' . escapeshellarg($mem)
            . ' --cpus=' . escapeshellarg($cpu)
            . ' --user sandbox'
            . ' --read-only'
            . ' --tmpfs /tmp:rw,nosuid,size=512m'
            . $envFlags
            . ' -v ' . escapeshellarg($workspacePath . ':/workspace:rw')
            . ' -v ' . escapeshellarg($memDir . ':/tmp/ravi-mem')
            . (!empty($sess['dir']) ? ' -v ' . escapeshellarg($sess['dir'] . ':/tmp/ravi-sess') : '')
            . $linkMounts
            . ' -w /workspace'
            . ' ' . escapeshellarg($this->image)
            . ' bash -lc ' . escapeshellarg($inner);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Tradução de eventos (por job/estado, para suportar concorrência)
    // ──────────────────────────────────────────────────────────────────────────

    /** Emite carimbando a identidade do agente do job E o id do job (p/ rotear a atividade na UI). */
    private function emitFor(array $st, array $data): void
    {
        if (!$this->onEvent) {
            return;
        }
        if (($st['agent'] ?? null) !== null && !isset($data['agent'])) {
            $data['agent'] = $st['agent'];
        }
        // O id do job liga cada evento (passo/ferramenta/texto) à linha do agente certo na UI,
        // mesmo havendo VÁRIOS agentes do mesmo tipo (ex.: 3 "backend") na mesma onda.
        if (!isset($data['id']) && isset($st['id'])) {
            $data['id'] = $st['id'];
        }
        ($this->onEvent)($data);
    }

    /**
     * Traduz um evento stream-json para os tipos que o chat.js entende, usando o estado do job.
     * Retorna o array de resultado final ao receber `result`, senão null.
     */
    private function handleStreamEvent(array $e, array &$st): ?array
    {
        switch ($e['type'] ?? '') {
            case 'system':
                if (($e['subtype'] ?? '') === 'init') {
                    $st['sessionId'] = $e['session_id'] ?? null;
                }
                return null;

            case 'assistant':
                foreach ($e['message']['content'] ?? [] as $block) {
                    $bt = $block['type'] ?? '';
                    if ($bt === 'text' && trim($block['text'] ?? '') !== '') {
                        $st['lastText'] = $block['text'];
                        if (!$st['quiet']) {
                            $this->emitFor($st, ['type' => 'agent_text', 'text' => $block['text']]);
                        }
                    } elseif ($bt === 'tool_use' && !$st['quiet']) {
                        if ($st['clean']) {
                            $step = $this->friendlyStep($block['name'] ?? 'tool', $block['input'] ?? []);
                            if ($step !== null) {
                                $this->emitFor($st, ['type' => 'step', 'text' => $step]);
                            }
                        } else {
                            $this->emitFor($st, [
                                'type'  => 'agent_tool_start',
                                'tool'  => $block['name'] ?? 'tool',
                                'input' => $block['input'] ?? [],
                            ]);
                        }
                    }
                }
                return null;

            case 'user':
                if ($st['quiet'] || $st['clean']) {
                    return null;
                }
                foreach ($e['message']['content'] ?? [] as $block) {
                    if (($block['type'] ?? '') === 'tool_result') {
                        $out = $block['content'] ?? '';
                        if (is_array($out)) {
                            $out = implode("\n", array_map(
                                fn($c) => is_array($c) ? ($c['text'] ?? '') : (string) $c,
                                $out
                            ));
                        }
                        $this->emitFor($st, [
                            'type'   => 'agent_tool_result',
                            'tool'   => '',
                            'output' => mb_substr((string) $out, 0, 2000),
                        ]);
                    }
                }
                return null;

            case 'result':
                $text    = (string) ($e['result'] ?? ($e['subtype'] ?? 'concluído'));
                $isError = (bool) ($e['is_error'] ?? false);
                if ($isError) {
                    // Limite de uso da licença? → cooldown, p/ o balance desviar os próximos agentes.
                    $this->noteLicenseLimit($st, $text);
                }
                if (!$st['quiet']) {
                    if ($isError) {
                        $this->emitFor($st, ['type' => 'agent_error', 'error' => $text]);
                    } elseif (trim($text) !== '' && trim($text) !== trim($st['lastText'])) {
                        $this->emitFor($st, ['type' => 'agent_text', 'text' => $text]);
                    }
                }
                return [
                    'summary'    => $text,
                    'session_id' => $st['sessionId'],
                    'cost_usd'   => $e['total_cost_usd'] ?? null,
                    'usage'      => $e['usage'] ?? null,
                    'num_turns'  => $e['num_turns'] ?? null,
                    'duration'   => $e['duration_ms'] ?? null,
                    'is_error'   => $isError,
                ];
        }

        return null;
    }

    /**
     * Traduz uma chamada de ferramenta num passo curto e amigável em PT.
     * Retorna null para ferramentas de exploração (ruído) que não devem aparecer no chat.
     */
    private function friendlyStep(string $tool, array $input): ?string
    {
        $base = function (string $key) use ($input): string {
            $p = (string) ($input[$key] ?? '');
            $p = preg_replace('#^/workspace/?#', '', $p);
            return $p !== '' ? basename($p) : $p;
        };

        switch ($tool) {
            case 'Write':
            case 'file_write':
                return 'Criou ' . ($base('file_path') ?: $base('path') ?: 'arquivo');

            case 'Edit':
            case 'MultiEdit':
            case 'str_replace':
            case 'str_replace_editor':
                return 'Editou ' . ($base('file_path') ?: $base('path') ?: 'arquivo');

            case 'NotebookEdit':
                return 'Editou notebook ' . $base('notebook_path');

            case 'Bash':
            case 'bash_execute':
                $desc = trim((string) ($input['description'] ?? ''));
                return $desc !== '' ? $desc : 'Executou um comando';

            case 'Task':
                $desc = trim((string) ($input['description'] ?? ''));
                return 'Subagente' . ($desc !== '' ? ': ' . $desc : '');

            case 'WebSearch':
            case 'web_search':
                $q = trim((string) ($input['query'] ?? ''));
                return 'Pesquisou na web' . ($q !== '' ? ': ' . mb_substr($q, 0, 60) : '');

            case 'WebFetch':
            case 'web_fetch':
                return 'Consultou uma página da web';

            case 'Read':
            case 'file_read':
            case 'Glob':
            case 'Grep':
            case 'LS':
            case 'file_list':
            case 'TodoWrite':
            case 'ExitPlanMode':
                return null;

            default:
                return null;
        }
    }
}
