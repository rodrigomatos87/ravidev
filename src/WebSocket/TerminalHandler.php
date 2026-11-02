<?php

declare(strict_types=1);

namespace Ravi\WebSocket;

use Ratchet\ConnectionInterface;
use React\EventLoop\LoopInterface;
use Ravi\Models\Project;
use Ravi\Services\ProjectImportService;

/**
 * Terminal web SOB DEMANDA para um projeto.
 *
 * Abre um shell interativo num container EFÊMERO (`docker run --rm`) que monta o MESMO
 * workspace que o Arquiteto/agentes usam (storage/sandbox/{uuid}). Assim, o que o usuário
 * editar/instalar manualmente persiste no workspace (vendor/, node_modules/, .env, SQLite)
 * e é visto pelos agentes na próxima execução. O container sobe ao abrir o terminal e é
 * removido quando o WebSocket fecha (ou em term_stop).
 *
 * PTY: usamos `script -q -c "..." /dev/null` da util-linux para alocar um pseudo-terminal
 * dentro do container (o `docker run -i` não aloca TTY sozinho). O I/O do script é
 * multiplexado no event loop do React via addReadStream — sem bloquear o servidor.
 *
 * Os dados trafegam em base64 nos dois sentidos (term_input/term_output) para não quebrar
 * o JSON com sequências ANSI / UTF-8 parcial.
 *
 * Protocolo (cliente → servidor):
 *   {type:'term_start',  project_id, cols, rows}
 *   {type:'term_input',  data:<base64>}
 *   {type:'term_stop'}
 * (servidor → cliente):
 *   {type:'term_started'} | {type:'term_output', data:<base64>} | {type:'term_exit'} | {type:'error'}
 */
class TerminalHandler
{
    private ConnectionManager $connections;
    private ?LoopInterface $loop = null;

    /** @var array<int,array{proc:resource,stdin:resource,stdout:resource,stderr:resource,container:string}> */
    private array $sessions = [];

    public function __construct(ConnectionManager $connections)
    {
        $this->connections = $connections;
    }

    public function setLoop(LoopInterface $loop): void
    {
        $this->loop = $loop;
    }

    public function handle(ConnectionInterface $conn, array $data): void
    {
        switch ($data['type'] ?? '') {
            case 'term_start':  $this->start($conn, $data); break;
            case 'term_input':  $this->input($conn, $data); break;
            case 'term_resize': $this->resize($conn, $data); break;
            case 'term_stop':   $this->stop($conn); break;
        }
    }

    /** Inicia uma sessão de terminal para o projeto (1 por conexão). */
    private function start(ConnectionInterface $conn, array $data): void
    {
        if ($this->loop === null) {
            $this->send($conn, ['type' => 'error', 'message' => 'Terminal indisponível (loop ausente).']);
            return;
        }

        $meta = $this->connections->getMetadata($conn);
        if (!$meta || empty($meta['user_id'])) {
            $this->send($conn, ['type' => 'error', 'message' => 'Não autenticado.']);
            return;
        }

        $projectUuid = (string) ($data['project_id'] ?? '');
        $project = $projectUuid !== '' ? Project::where('uuid', '=', $projectUuid)->first() : null;
        if (!$project || !$project->isAccessibleBy((int) $meta['user_id'])) {
            $this->send($conn, ['type' => 'error', 'message' => 'Projeto não encontrado.']);
            return;
        }

        $ws = $this->workspacePath($project->uuid);
        if (!is_dir($ws)) {
            @mkdir($ws, 0777, true);
        }
        @chmod($ws, 0777); // garante que o usuário do container (sandbox) consegue gravar no workspace

        // Não abre terminal enquanto o projeto está sendo importado/processado.
        if (ProjectImportService::isProcessing($ws)) {
            $this->send($conn, ['type' => 'error', 'message' => 'O projeto ainda está sendo processado. Aguarde concluir.']);
            return;
        }

        // Encerra uma sessão anterior desta mesma conexão, se houver.
        $this->stop($conn);

        $cols = max(20, min(400, (int) ($data['cols'] ?? 80)));
        $rows = max(5,  min(200, (int) ($data['rows'] ?? 24)));

        $image = (string) (config('sandbox.image') ?: 'ravi-sandbox:latest');
        $name  = 'ravi-term-' . substr($project->uuid, 0, 8) . '-' . bin2hex(random_bytes(3));

        // Shell interativo dentro de um PTY (script), com tamanho inicial e HOME em tmpfs.
        $inner = 'mkdir -p "$HOME" 2>/dev/null; umask 000; stty rows ' . $rows . ' cols ' . $cols
            . ' 2>/dev/null; exec bash -il';
        $cmd = 'docker run --rm -i'
            . ' --name ' . escapeshellarg($name)
            . ' --memory=1g --cpus=1 --pids-limit=512'
            . ' -e ' . escapeshellarg('TERM=xterm-256color')
            . ' -e ' . escapeshellarg('HOME=/tmp/termhome')
            . ' --tmpfs /tmp:rw,nosuid,size=256m'
            . ' --user sandbox'
            . ' -v ' . escapeshellarg($ws . ':/workspace:rw')
            . ' -w /workspace'
            . ' ' . escapeshellarg($image)
            . ' script -q -c ' . escapeshellarg($inner) . ' /dev/null';

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $pipes = [];
        $proc = @proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($proc)) {
            $this->send($conn, ['type' => 'error', 'message' => 'Não consegui abrir o terminal.']);
            return;
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $rid = $conn->resourceId;
        $this->sessions[$rid] = [
            'proc'      => $proc,
            'stdin'     => $pipes[0],
            'stdout'    => $pipes[1],
            'stderr'    => $pipes[2],
            'container' => $name,
        ];

        $onRead = function ($stream) use ($conn, $rid) {
            $chunk = @fread($stream, 65536);
            if ($chunk === '' || $chunk === false) {
                if (@feof($stream)) {
                    $this->finish($conn, $rid);
                }
                return;
            }
            $this->send($conn, ['type' => 'term_output', 'data' => base64_encode($chunk)]);
        };
        $this->loop->addReadStream($pipes[1], $onRead);
        $this->loop->addReadStream($pipes[2], $onRead);

        $this->send($conn, ['type' => 'term_started']);
    }

    /** Repassa as teclas do usuário para o stdin do shell. */
    private function input(ConnectionInterface $conn, array $data): void
    {
        $s = $this->sessions[$conn->resourceId] ?? null;
        if (!$s) {
            return;
        }
        $raw = base64_decode((string) ($data['data'] ?? ''), true);
        if ($raw === false || $raw === '') {
            return;
        }
        @fwrite($s['stdin'], $raw);
    }

    /**
     * Redimensiona o PTY ao vivo. Como o pseudo-terminal é alocado pelo `script` DENTRO do
     * container (em /dev/pts/0), aplicamos `stty rows/cols` nele via `docker exec`: o kernel
     * dispara SIGWINCH automaticamente para o programa em foco (bash, vim, etc.). Fire-and-forget
     * em background para não bloquear o event loop.
     */
    private function resize(ConnectionInterface $conn, array $data): void
    {
        $s = $this->sessions[$conn->resourceId] ?? null;
        if (!$s || empty($s['container'])) {
            return;
        }
        $cols = max(20, min(400, (int) ($data['cols'] ?? 80)));
        $rows = max(5,  min(200, (int) ($data['rows'] ?? 24)));
        $stty = 'stty rows ' . $rows . ' cols ' . $cols . ' < /dev/pts/0 2>/dev/null';
        @exec('docker exec ' . escapeshellarg($s['container']) . ' sh -c ' . escapeshellarg($stty) . ' >/dev/null 2>&1 &');
    }

    /** Encerra a sessão de terminal desta conexão (chamado em term_stop e no onClose). */
    public function stop(ConnectionInterface $conn): void
    {
        $this->finish($conn, $conn->resourceId, true);
    }

    /** Limpeza ao fechar a conexão WebSocket. */
    public function cleanup(ConnectionInterface $conn): void
    {
        $this->finish($conn, $conn->resourceId, true);
    }

    /** Finaliza uma sessão: remove streams do loop, fecha pipes, mata o processo e o container. */
    private function finish(ConnectionInterface $conn, int $rid, bool $silent = false): void
    {
        $s = $this->sessions[$rid] ?? null;
        if (!$s) {
            return;
        }
        unset($this->sessions[$rid]);

        if ($this->loop !== null) {
            @$this->loop->removeReadStream($s['stdout']);
            @$this->loop->removeReadStream($s['stderr']);
        }
        foreach (['stdin', 'stdout', 'stderr'] as $p) {
            if (is_resource($s[$p])) {
                @fclose($s[$p]);
            }
        }
        if (is_resource($s['proc'])) {
            @proc_terminate($s['proc']);
            @proc_close($s['proc']);
        }
        // Garante a remoção do container efêmero (o --rm cobre o caso normal).
        @exec('docker rm -f ' . escapeshellarg($s['container']) . ' >/dev/null 2>&1 &');

        if (!$silent) {
            $this->send($conn, ['type' => 'term_exit']);
        }
    }

    private function workspacePath(string $projectUuid): string
    {
        $base = config('sandbox.workspace.base_path') ?: (dirname(__DIR__, 2) . '/storage/sandbox');
        return rtrim((string) $base, '/') . '/' . $projectUuid;
    }

    private function send(ConnectionInterface $conn, array $data): void
    {
        $conn->send(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }
}
