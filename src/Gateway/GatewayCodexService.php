<?php

declare(strict_types=1);

namespace Ravi\Gateway;

use Ravi\Services\ChatgptLicenseService;

/**
 * Backend ChatGPT do gateway: produz uma resposta de turno único usando o
 * Codex CLI logado nas CONTAS ChatGPT do painel (Admin → Licenças → ChatGPT),
 * consumindo a cota do PLANO (custo zero por token), num container efêmero da
 * imagem dedicada ravi-codex (a sandbox da IDE fica intocada).
 *
 * Espelho do GatewayClaudeService: prompt via stdin (argumento "-"), sandbox
 * read-only, sem sessão persistida (--ephemeral) e instrução explícita de
 * responder direto sem usar ferramentas. Tenta cada conta ativa na ordem
 * (cadeia, como as licenças Max); o auth.json renovado pelo Codex volta ao
 * banco via storeRefreshed (só grava se for mais novo — várias frentes podem
 * compartilhar a conta).
 *
 * Com conta ChatGPT o Codex só serve modelos LIBERADOS PARA O PLANO — o
 * catálogo curado em MODELOS espelha o seletor da extensão oficial (validado
 * ao vivo em 19/09/2026; id fora disso volta 400 "not supported when using
 * Codex with a ChatGPT account").
 */
class GatewayCodexService
{
    /** Modelos servíveis via conta ChatGPT (validados ao vivo contra o CLI da imagem,
     *  espelhando o seletor da extensão oficial: Astra, Sol, Terra, Luna e 5.5). */
    public const MODELOS = ['gpt-6-astra', 'gpt-5.6-sol', 'gpt-5.6-terra', 'gpt-5.6-luna', 'gpt-5.5'];

    private string $image;
    private int $timeout;
    private string $wsBase;

    /** @param ?int $timeout tempo da chamada longa (faixa longa); null = GW_CODEX_TIMEOUT */
    public function __construct(?int $timeout = null)
    {
        $this->image   = (string) env('GW_CODEX_IMAGE', 'ravi-codex:latest');
        $this->timeout = $timeout ?? (int) env('GW_CODEX_TIMEOUT', 90);
        $this->wsBase  = defined('STORAGE_PATH')
            ? STORAGE_PATH . '/gateway/ws'
            : dirname(__DIR__, 2) . '/storage/gateway/ws';
    }

    public static function modeloConhecido(string $modelo): bool
    {
        return in_array(strtolower($modelo), self::MODELOS, true);
    }

    public static function disponivel(): bool
    {
        return ChatgptLicenseService::hasActive();
    }

    /**
     * Tenta o turno em CADA conta ChatGPT ativa, na ordem do painel.
     * @param array $anexos [{media_type, data(binário)}] — imagens viram -i; PDF não é suportado aqui.
     * @return array{ok:bool, text:string, input_tokens:int, prompt_tokens:int, cache_read_tokens:int,
     *               cache_write_tokens:int, output_tokens:int, model:string, error:?string}
     */
    public function complete(string $requestedModel, string $system, string $userText, array $anexos = []): array
    {
        $modelo = strtolower($requestedModel);
        $fail = fn(string $e): array => [
            'ok' => false, 'text' => '',
            'input_tokens' => 0, 'prompt_tokens' => 0, 'cache_read_tokens' => 0, 'cache_write_tokens' => 0,
            'output_tokens' => 0, 'model' => $modelo, 'error' => $e,
        ];
        if (!self::modeloConhecido($modelo)) {
            return $fail('modelo fora do catálogo Codex (' . $requestedModel . ')');
        }
        foreach ($anexos as $ax) {
            if (($ax['media_type'] ?? '') === 'application/pdf') {
                return $fail('o backend ChatGPT não aceita documento PDF (envie imagem, ou use um modelo Claude)');
            }
        }
        $contas = array_values(array_filter(ChatgptLicenseService::all(), static fn(array $l): bool => (int) $l['is_active'] === 1));
        if ($contas === []) {
            return $fail('nenhuma conta ChatGPT ativa (Admin → Licenças)');
        }

        $erros = [];
        foreach ($contas as $conta) {
            $r = $this->executarNaConta((int) $conta['id'], $modelo, $system, $userText, $anexos);
            if ($r['ok']) {
                return $r;
            }
            $erros[] = '[' . (string) $conta['name'] . '] ' . (string) $r['error'];
        }
        return $fail(implode(' | ', $erros));
    }

    /** Um turno numa conta específica: workspace efêmero + container + writeback do auth renovado. */
    private function executarNaConta(int $licencaId, string $modelo, string $system, string $userText, array $anexos): array
    {
        $fail = fn(string $e): array => [
            'ok' => false, 'text' => '',
            'input_tokens' => 0, 'prompt_tokens' => 0, 'cache_read_tokens' => 0, 'cache_write_tokens' => 0,
            'output_tokens' => 0, 'model' => $modelo, 'error' => $e,
        ];
        $auth = ChatgptLicenseService::auth($licencaId);
        if ($auth === '') {
            return $fail('licença sem auth.json utilizável');
        }
        if (!is_dir($this->wsBase)) {
            @mkdir($this->wsBase, 0777, true);
        }
        $ws = $this->wsBase . '/' . bin2hex(random_bytes(8));
        if (!@mkdir($ws . '/.codex', 0777, true) || !@mkdir($ws . '/trabalho', 0777, true)) {
            return $fail('falha ao criar workspace efêmero');
        }
        @chmod($ws, 0777);
        @chmod($ws . '/.codex', 0777);
        @chmod($ws . '/trabalho', 0777);
        @file_put_contents($ws . '/.codex/auth.json', $auth);
        @chmod($ws . '/.codex/auth.json', 0666);

        // imagens do cliente viram arquivos + flags -i (visão do Codex)
        $flagsImagem = '';
        $ext = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/gif' => 'gif'];
        $ni = 0;
        foreach ($anexos as $ax) {
            $nome = 'imagem' . ++$ni . '.' . ($ext[(string) ($ax['media_type'] ?? '')] ?? 'png');
            @file_put_contents($ws . '/' . $nome, (string) $ax['data']);
            @chmod($ws . '/' . $nome, 0666);
            $flagsImagem .= ' -i ' . escapeshellarg('/ws/' . $nome);
        }

        // system embutido no prompt (codex exec não tem flag de system) + trava de
        // comportamento: queremos a RESPOSTA do modelo, nunca uma sessão agêntica
        $prompt = "INSTRUÇÕES DE SISTEMA (siga à risca):\n"
            . "Responda diretamente à mensagem, em um único turno, SEM executar comandos, sem ler ou escrever arquivos e sem usar ferramenta nenhuma.\n"
            . ($system !== '' ? "\n" . $system . "\n" : '')
            . "\nMENSAGEM:\n" . $userText;

        try {
            $inner = 'codex exec'
                . ' --json'
                . ' --ephemeral'
                . ' --ignore-user-config'
                . ' --skip-git-repo-check'
                . ' -s read-only'
                . ' -m ' . escapeshellarg($modelo)
                . $flagsImagem
                . ' -';
            $cmd = 'timeout ' . $this->timeout . ' docker run --rm -i'
                . ' --user sandbox'
                . ' --memory=1g --cpus=1'
                . ' --read-only'
                . ' --tmpfs /tmp:rw,nosuid,size=256m'
                . ' -e CODEX_HOME=/ws/.codex'
                . ' -e HOME=/tmp/cx-home'
                . ' -v ' . escapeshellarg($ws . ':/ws:rw')
                . ' -w /ws/trabalho'
                . ' --entrypoint bash'
                . ' ' . escapeshellarg($this->image)
                . ' -lc ' . escapeshellarg($inner);
            $saida = $this->runProcess($cmd, $prompt);
            if ($saida === null || trim($saida) === '') {
                return $fail('sem resposta do processo Codex (timeout?)');
            }

            // JSONL: guarda a última mensagem do agente e o usage do turno
            $texto = '';
            $uso = [];
            $erroTurno = '';
            foreach (explode("\n", $saida) as $linha) {
                $ev = json_decode(trim($linha), true);
                if (!is_array($ev)) {
                    continue;
                }
                if (($ev['type'] ?? '') === 'item.completed' && (($ev['item']['type'] ?? '') === 'agent_message')) {
                    $texto = (string) ($ev['item']['text'] ?? '');
                }
                if (($ev['type'] ?? '') === 'turn.completed' && is_array($ev['usage'] ?? null)) {
                    $uso = $ev['usage'];
                }
                if (in_array($ev['type'] ?? '', ['turn.failed', 'error'], true)) {
                    $m = $ev['error']['message'] ?? $ev['message'] ?? '';
                    if (is_string($m) && $m !== '') {
                        $erroTurno = $m;
                    }
                }
            }
            if (trim($texto) === '') {
                return $fail($erroTurno !== '' ? mb_substr($erroTurno, 0, 300) : 'resposta vazia do Codex');
            }

            $entradaTotal = (int) ($uso['input_tokens'] ?? 0);        // total processado (inclui cache)
            $cacheLido    = (int) ($uso['cached_input_tokens'] ?? 0);
            return [
                'ok'                 => true,
                'text'               => $texto,
                'input_tokens'       => $entradaTotal,
                'prompt_tokens'      => max(0, $entradaTotal - $cacheLido),
                'cache_read_tokens'  => $cacheLido,
                'cache_write_tokens' => (int) ($uso['cache_write_input_tokens'] ?? 0),
                'output_tokens'      => (int) ($uso['output_tokens'] ?? 0) + (int) ($uso['reasoning_output_tokens'] ?? 0),
                'model'              => $modelo,
                'error'              => null,
            ];
        } finally {
            // auth renovado pelo Codex volta ao banco (storeRefreshed só aceita se for mais novo)
            $novoAuth = (string) @file_get_contents($ws . '/.codex/auth.json');
            if ($novoAuth !== '' && $novoAuth !== $auth) {
                ChatgptLicenseService::storeRefreshed($licencaId, $novoAuth);
            }
            try {
                \Ravi\Core\Database::getInstance()->query('UPDATE chatgpt_licenses SET last_used_at = NOW() WHERE id = ?', [$licencaId]);
            } catch (\Throwable $e) { /* best-effort */ }
            $this->rrmdir($ws);
        }
    }

    /** Roda o comando, escreve o prompt no stdin (EOF fecha) e devolve o stdout completo. */
    private function runProcess(string $cmd, string $stdin): ?string
    {
        $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            return null;
        }
        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        return $out;
    }

    private function rrmdir(string $dir): void
    {
        foreach ((array) @scandir($dir) as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $p = $dir . '/' . $f;
            is_dir($p) ? $this->rrmdir($p) : @unlink($p);
        }
        @rmdir($dir);
    }
}
