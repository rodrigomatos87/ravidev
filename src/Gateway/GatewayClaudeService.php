<?php

declare(strict_types=1);

namespace Ravi\Gateway;

/**
 * Backend PRIMÁRIO do gateway: produz uma resposta de turno único usando o
 * Claude Code CLI na assinatura Max (custo zero por token), dentro de um
 * container efêmero do sandbox.
 *
 * É deliberadamente ISOLADO do produto: NÃO usa ClaudeCodeService nem emite
 * eventos de chat. Só faz "system + 1 mensagem do usuário → texto + usage",
 * espelhando o que a API oficial de Messages faria.
 *
 * O prompt do usuário vai por STDIN e o system por --system-prompt-file (evita
 * qualquer problema de escaping). --exclude-dynamic-system-prompt-sections corta
 * o scaffolding agêntico do Claude Code (fidelidade + menos tokens); --tools ""
 * REMOVE as ferramentas do prompt (resposta direta, como a API).
 *
 * Atenção: --allowed-tools "" NÃO serve aqui. Ele só nega o uso: o modelo continua
 * vendo as ferramentas (~40k tokens de definições por chamada) e tenta usá-las por
 * conta própria, turno após turno, até concluir que "não tem acesso" ao que pediu.
 * Caso real em 08/09/2026 (um cliente do gateway): 135k tokens, 72 s e
 * uma transferência indevida; com --tools "" o mesmo turno levou 6,9k tokens e 5,7 s.
 */
class GatewayClaudeService
{
    private string $image;
    private string $oauthToken;
    private int $timeout;
    private string $wsBase;

    /** @param ?int $timeout teto desta chamada em s (faixa longa ou o que sobra do orçamento); null = GW_MAX_TIMEOUT */
    public function __construct(?string $oauthToken = null, ?int $timeout = null)
    {
        $this->image      = (string) (config('sandbox')['image'] ?? 'ravi-sandbox:latest');
        // Licença: quando o chamador resolve por token de API, passa aqui; senão usa o padrão global
        // do escopo API (→ fallback do .env).
        $this->oauthToken = $oauthToken !== null ? $oauthToken : \Ravi\Services\LicenseService::tokenForScope('api');
        // Janela curta: há cliente que aborta em 60s. O Max responde em poucos segundos;
        // se estourar, o GatewayService cai no fallback oficial.
        $this->timeout    = max(5, $timeout ?? (int) env('GW_MAX_TIMEOUT', 50));
        $this->wsBase     = defined('STORAGE_PATH')
            ? STORAGE_PATH . '/gateway/ws'
            : dirname(__DIR__, 2) . '/storage/gateway/ws';
    }

    public function isAvailable(): bool
    {
        return $this->oauthToken !== '';
    }

    /** Mapeia o id de modelo pedido para um alias que o Claude Code aceita. */
    public static function mapModel(string $requested): string
    {
        $m = strtolower($requested);
        if (str_contains($m, 'opus'))  return 'opus';
        if (str_contains($m, 'haiku')) return 'haiku';
        if (str_contains($m, 'sonnet')) return 'sonnet';
        return 'sonnet';
    }

    /** Modelo pertence ao catálogo servido (opus/sonnet/haiku)? Se não, não deve ser servido
     *  (mapModel cairia em sonnet por padrão — substituição silenciosa). */
    public static function isKnownModel(string $requested): bool
    {
        $m = strtolower($requested);
        return str_contains($m, 'opus') || str_contains($m, 'sonnet') || str_contains($m, 'haiku');
    }

    /**
     * O que vai no --model do CLI. Um id do catálogo CURADO (ModelService::MODELS) vai INTEIRO,
     * com o sufixo [1m] quando cabe: é o que permite a quem chama a API escolher de fato entre
     * Opus 5 e Opus 4.8 — o alias de família serve "o Opus do momento" e ignora o pedido.
     *
     * Qualquer outro id (cliente antigo pedindo claude-3-opus-…, por exemplo) continua caindo no
     * alias de família, porque um id que o CLI da imagem não conhece TRAVA a chamada em timeout
     * silencioso em vez de recusar. O catálogo curado é justamente a lista que validamos contra
     * o CLI embutido nas imagens — ver o aviso no topo do ModelService.
     */
    public static function cliModelFor(string $requested): string
    {
        if (isset(\Ravi\Services\ModelService::MODELS[$requested])) {
            return \Ravi\Services\ModelService::cliModel($requested);
        }
        if (isset(self::IDS_EXATOS[$requested])) {
            return $requested;
        }
        return self::mapModel($requested);
    }

    /**
     * Ids que o gateway serve EXATOS mas que não entram no seletor da IDE/Dev Autônomo
     * (ModelService::MODELS). Sem esta lista o claude-haiku-4-5 virava o alias "haiku"
     * (o Haiku do momento, não o pedido). Validados contra o CLI 2.1.281 da ravi-sandbox
     * em 30/10/2026: id desconhecido TRAVA o CLI em timeout, então só entra aqui id provado.
     */
    public const IDS_EXATOS = [
        'claude-haiku-4-5'          => 'Haiku 4.5',
        'claude-haiku-4-5-20251001' => 'Haiku 4.5',
    ];

    /** Id exato servido (MODELS ou IDS_EXATOS)? */
    public static function idExato(string $requested): bool
    {
        return isset(\Ravi\Services\ModelService::MODELS[$requested]) || isset(self::IDS_EXATOS[$requested]);
    }

    /**
     * @param array $anexos anexos do cliente ([{media_type, data(binário)}]): imagens
     *   (máx. 4, validadas no GatewayService) e documento PDF (máx. 1). Cada anexo vira
     *   um ARQUIVO no workspace efêmero e uma menção @arquivo no prompt — o Claude Code
     *   anexa a imagem de verdade (visão) e lê o PDF, coisa que o achatamento
     *   texto-puro descartava.
     * @param ?int   $maxTokens max_tokens do corpo (teto da saída, como na Messages API)
     * @param ?array $thinking  campo thinking do corpo; ausente = sem raciocínio (padrão da API)
     * @return array{ok:bool, text:string, input_tokens:int, output_tokens:int, model:string, error:?string, stop_reason:?string}
     */
    public function complete(string $requestedModel, string $system, string $userText, array $anexos = [],
                             ?int $maxTokens = null, ?array $thinking = null): array
    {
        // $cliModel é o que o CLI recebe (id inteiro do catálogo curado, ou alias de família);
        // $model é o que reportamos ao chamador e ao ledger, sempre sem o sufixo [1m].
        $cliModel = self::cliModelFor($requestedModel);
        $model    = self::idExato($requestedModel)
            ? $requestedModel
            : self::mapModel($requestedModel);
        $fail = fn(string $e): array => [
            'ok' => false, 'text' => '',
            'input_tokens' => 0, 'prompt_tokens' => 0, 'cache_read_tokens' => 0, 'cache_write_tokens' => 0,
            'output_tokens' => 0, 'model' => $model, 'error' => $e, 'stop_reason' => null,
        ];

        if (!$this->isAvailable()) {
            return $fail('Max indisponível (sem CLAUDE_CODE_OAUTH_TOKEN)');
        }

        // Workspace efêmero só para carregar o system prompt num arquivo.
        if (!is_dir($this->wsBase)) {
            @mkdir($this->wsBase, 0777, true);
        }
        // Faxina de órfãos: um kill duro (OOM, restart do FPM) pula o finally e deixaria
        // arquivo de cliente (foto/PDF) em disco. Qualquer ws com mais de 6h é lixo.
        foreach ((array) @scandir($this->wsBase) as $orfao) {
            if ($orfao === '.' || $orfao === '..') {
                continue;
            }
            $p = $this->wsBase . '/' . $orfao;
            if (is_dir($p) && (int) @filemtime($p) < time() - 6 * 3600) {
                $this->rrmdir($p);
            }
        }
        $ws = $this->wsBase . '/' . bin2hex(random_bytes(8));
        if (!@mkdir($ws, 0777, true)) {
            return $fail('Falha ao criar workspace efêmero');
        }
        @chmod($ws, 0777);
        @file_put_contents($ws . '/system.txt', $system !== '' ? $system : 'You are a helpful assistant.');
        @chmod($ws . '/system.txt', 0666);

        // Anexos do cliente (imagens e PDF) viram arquivos no /ws + menção @arquivo no prompt
        if ($anexos !== []) {
            $ext = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/gif' => 'gif'];
            $mencoes = [];
            $ni = 0;
            $nd = 0;
            foreach ($anexos as $ax) {
                $mt = (string) ($ax['media_type'] ?? '');
                $dados = (string) $ax['data'];
                if ($mt !== 'application/pdf') {
                    [$mt, $dados] = $this->ajustarImagem($mt, $dados);
                }
                $nome = $mt === 'application/pdf'
                    ? 'documento' . ++$nd . '.pdf'
                    : 'imagem' . ++$ni . '.' . ($ext[$mt] ?? 'png');
                @file_put_contents($ws . '/' . $nome, $dados);
                @chmod($ws . '/' . $nome, 0666);
                $mencoes[] = '@' . $nome;
            }
            $userText .= "\n\n(Arquivos anexados: " . implode(' ', $mencoes) . ')';
        }

        try {
            $comTeto = self::envRaciocinio($maxTokens, $thinking) !== [] && $maxTokens !== null && $maxTokens > 0;
            $nome = 'gw-' . bin2hex(random_bytes(6));
            $cmd = $this->buildDockerCmd($ws, $cliModel, $maxTokens, $thinking, $comTeto ? $nome : null);
            $res = $comTeto ? $this->runComTeto($cmd, $userText, $nome) : $this->runProcess($cmd, $userText);
            if ($res === null) {
                return $fail('Sem resposta do processo Max');
            }
            if (is_array($res)) {
                // Bateu no max_tokens: texto até o teto, como a Messages API devolve.
                if (trim($res['text']) === '') {
                    return $fail('Resposta vazia do Max');
                }
                return [
                    'ok'                 => true,
                    'text'               => $res['text'],
                    'input_tokens'       => $res['prompt'] + $res['cache_write'] + $res['cache_read'],
                    'prompt_tokens'      => $res['prompt'],
                    'cache_read_tokens'  => $res['cache_read'],
                    'cache_write_tokens' => $res['cache_write'],
                    'output_tokens'      => (int) $maxTokens,
                    'model'              => $model,
                    'error'              => null,
                    'stop_reason'        => 'max_tokens',
                ];
            }

            // Saída pode ter ruído antes/depois — extrai o último objeto JSON.
            $json = $this->extractJson($res);
            if ($json === null) {
                return $fail('JSON inválido do Claude Code');
            }
            if (!empty($json['is_error'])) {
                return $fail('Claude Code retornou erro: ' . (string) ($json['result'] ?? 'desconhecido'));
            }

            $text = (string) ($json['result'] ?? '');
            if (trim($text) === '') {
                return $fail('Resposta vazia do Max');
            }

            $u = is_array($json['usage'] ?? null) ? $json['usage'] : [];
            // Breakdown: o prompt REAL do cliente vem em input_tokens; o scaffolding
            // do Claude Code (~17,9k/chamada) vem como cache (creation na 1ª, read nas
            // seguintes). Guardamos separado para o custo estimado refletir só o real.
            $prompt     = (int) ($u['input_tokens'] ?? 0);
            $cacheWrite = (int) ($u['cache_creation_input_tokens'] ?? 0);
            $cacheRead  = (int) ($u['cache_read_input_tokens'] ?? 0);
            $output     = (int) ($u['output_tokens'] ?? 0);

            return [
                'ok'                 => true,
                'text'               => $text,
                'input_tokens'       => $prompt + $cacheWrite + $cacheRead, // total processado (compat)
                'prompt_tokens'      => $prompt,
                'cache_read_tokens'  => $cacheRead,
                'cache_write_tokens' => $cacheWrite,
                'output_tokens'      => $output,
                'model'              => $model,
                'error'              => null,
                // stop_reason real do CLI quando ele informa (ex.: max_tokens); senão end_turn
                'stop_reason'        => is_string($json['stop_reason'] ?? null) && $json['stop_reason'] !== ''
                    ? (string) $json['stop_reason'] : null,
            ];
        } finally {
            $this->rrmdir($ws);
        }
    }

    /**
     * Teto REAL do backend: a API da Anthropic recusa imagem acima de ~5MB EM BASE64
     * (≈3,75MB decodificada). O gateway aceita 5MB do cliente e ajusta AQUI: acima do
     * teto, recomprime como JPEG e reduz as dimensões até caber. Sem isto o CLI tenta
     * redimensionar sozinho com ferramentas (que são negadas) e derrapa até o timeout,
     * validado na prática em 02/09/2026 com um PNG de 4,3MB (502 após 110s).
     */
    private const IMG_TETO_BYTES = 3_700_000;

    /** @return array{0:string,1:string} [media_type, dados] prontos para o /ws */
    private function ajustarImagem(string $mediaType, string $dados): array
    {
        if (strlen($dados) <= self::IMG_TETO_BYTES || !function_exists('imagecreatefromstring')) {
            return [$mediaType, $dados];
        }
        $im = @imagecreatefromstring($dados);
        if ($im === false) {
            return [$mediaType, $dados];
        }
        // JPEG achata transparência sobre branco (visão não precisa de alfa nem de animação).
        $w = imagesx($im);
        $h = imagesy($im);
        $out = $dados;
        for ($i = 0; $i < 6; $i++) {
            $tela = imagecreatetruecolor($w, $h);
            imagefill($tela, 0, 0, (int) imagecolorallocate($tela, 255, 255, 255));
            imagecopyresampled($tela, $im, 0, 0, 0, 0, $w, $h, imagesx($im), imagesy($im));
            ob_start();
            imagejpeg($tela, null, 82);
            $out = (string) ob_get_clean();
            imagedestroy($tela);
            if (strlen($out) <= self::IMG_TETO_BYTES) {
                break;
            }
            $w = max(200, (int) round($w * 0.75));
            $h = max(200, (int) round($h * 0.75));
        }
        imagedestroy($im);
        return ['image/jpeg', $out];
    }

    /**
     * Variáveis do CLI para max_tokens e thinking (nomes conferidos no binário do CLI
     * 2.1.281 da ravi-sandbox em 30/10/2026):
     * - sem thinking pedido (ou thinking.type=disabled): CLAUDE_CODE_DISABLE_THINKING=1 e
     *   MAX_THINKING_TOKENS=0 desligam o raciocínio, e CLAUDE_CODE_MAX_OUTPUT_TOKENS limita
     *   a saída ao max_tokens. Antes o CLI raciocinava escondido e um pedido de 300 tokens
     *   gastava 3.600 de saída.
     * - com thinking pedido: nada muda (comportamento de sempre do CLI).
     * @return array<string,string>
     */
    public static function envRaciocinio(?int $maxTokens, ?array $thinking): array
    {
        $tipo = is_array($thinking) ? strtolower((string) ($thinking['type'] ?? '')) : '';
        if ($tipo !== '' && $tipo !== 'disabled') {
            return [];
        }
        $env = [
            'CLAUDE_CODE_DISABLE_THINKING' => '1',
            'MAX_THINKING_TOKENS'          => '0',
        ];
        if ($maxTokens !== null && $maxTokens > 0) {
            $env['CLAUDE_CODE_MAX_OUTPUT_TOKENS'] = (string) $maxTokens;
        }
        return $env;
    }

    private function buildDockerCmd(string $ws, string $model, ?int $maxTokens = null, ?array $thinking = null, ?string $nome = null): string
    {
        $env = [
            'HOME'                                     => '/tmp/cc-home',
            'DISABLE_AUTOUPDATER'                      => '1',
            'DISABLE_TELEMETRY'                        => '1',
            'DISABLE_ERROR_REPORTING'                  => '1',
            'CLAUDE_CODE_DISABLE_NONESSENTIAL_TRAFFIC' => '1',
            'CLAUDE_CODE_OAUTH_TOKEN'                  => $this->oauthToken,
        ] + self::envRaciocinio($maxTokens, $thinking);
        $envFlags = '';
        foreach ($env as $k => $v) {
            if ($v === '') {
                continue;
            }
            $envFlags .= ' -e ' . escapeshellarg($k . '=' . $v);
        }

        $inner = 'mkdir -p "$HOME/.claude" 2>/dev/null; umask 000; '
            . 'exec claude -p'
            . ($nome !== null ? ' --output-format stream-json --verbose' : ' --output-format json')
            . ' --model ' . escapeshellarg($model)
            . ' --tools ""'
            . ' --exclude-dynamic-system-prompt-sections'
            . ' --system-prompt-file /ws/system.txt';

        // --entrypoint bash: a imagem sandbox pode trocar de ENTRYPOINT entre builds
        // (o rebuild de 21/07 veio com ENTRYPOINT ["sh"], que virava `sh bash -lc ...`
        // e derrubava 100% das chamadas com "JSON inválido do Claude Code").
        return 'timeout ' . $this->timeout . ' docker run --rm -i'
            . ($nome !== null ? ' --name ' . escapeshellarg($nome) : '')
            . ' --user sandbox'
            . ' --memory=1g --cpus=1'
            . ' --read-only'
            . ' --tmpfs /tmp:rw,nosuid,size=256m'
            . $envFlags
            . ' -v ' . escapeshellarg($ws . ':/ws:rw')
            . ' -w /ws'
            . ' --entrypoint bash'
            . ' ' . escapeshellarg($this->image)
            . ' -lc ' . escapeshellarg($inner);
    }

    /**
     * Execução com max_tokens (saída em stream-json). Ao bater no teto, o CLI injeta uma
     * mensagem de usuário ("Output token limit hit. Resume directly...") e retoma até 3
     * vezes, devolvendo só o ÚLTIMO pedaço: texto quebrado e até 4x a saída. Sem
     * ferramentas (--tools ""), qualquer evento de usuário depois de uma resposta só pode
     * ser essa retomada: paramos ali, matamos o container e devolvemos o texto até o teto.
     * @return string|array|null stdout completo (terminou sozinho), array do corte no teto, ou null
     */
    private function runComTeto(string $cmd, string $stdin, string $nome): string|array|null
    {
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $pipes = [];
        $proc = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($proc)) {
            return null;
        }
        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        // stderr não pode encher e travar o processo enquanto lemos o stdout linha a linha
        stream_set_blocking($pipes[2], false);

        $out = '';
        $texto = '';
        $viuResposta = false;
        $uso = ['prompt' => 0, 'cache_write' => 0, 'cache_read' => 0];
        $corte = null;
        while (($linha = fgets($pipes[1])) !== false) {
            $out .= $linha;
            @stream_get_contents($pipes[2]);
            $ev = json_decode(trim($linha), true);
            if (!is_array($ev)) {
                continue;
            }
            $tipo = (string) ($ev['type'] ?? '');
            if ($tipo === 'assistant') {
                $msg = is_array($ev['message'] ?? null) ? $ev['message'] : [];
                foreach ((array) ($msg['content'] ?? []) as $blk) {
                    if (is_array($blk) && ($blk['type'] ?? '') === 'text') {
                        $texto .= (string) ($blk['text'] ?? '');
                    }
                }
                if (!$viuResposta) {
                    $u = is_array($msg['usage'] ?? null) ? $msg['usage'] : [];
                    $uso = [
                        'prompt'      => (int) ($u['input_tokens'] ?? 0),
                        'cache_write' => (int) ($u['cache_creation_input_tokens'] ?? 0),
                        'cache_read'  => (int) ($u['cache_read_input_tokens'] ?? 0),
                    ];
                }
                $viuResposta = true;
            } elseif ($tipo === 'user' && $viuResposta) {
                $corte = ['text' => $texto] + $uso;
                break;
            }
        }
        if ($corte !== null) {
            @exec('docker kill ' . escapeshellarg($nome) . ' >/dev/null 2>&1');
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        return $corte ?? $out;
    }

    /** Roda o comando, escreve o prompt no stdin, devolve o stdout completo (ou null). */
    private function runProcess(string $cmd, string $stdin): ?string
    {
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $pipes = [];
        $proc = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($proc)) {
            return null;
        }
        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);

        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        return $out === false ? null : $out;
    }

    /** Extrai o último objeto JSON do tipo result do stdout. */
    private function extractJson(string $out): ?array
    {
        $out = trim($out);
        if ($out === '') {
            return null;
        }
        // Caminho feliz: a saída inteira é o JSON.
        $d = json_decode($out, true);
        if (is_array($d)) {
            return $d;
        }
        // Senão, pega a última linha que parseia como objeto JSON.
        $lines = preg_split('/\r?\n/', $out) ?: [];
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $line = trim($lines[$i]);
            if ($line === '' || $line[0] !== '{') {
                continue;
            }
            $d = json_decode($line, true);
            if (is_array($d)) {
                return $d;
            }
        }
        return null;
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir) ?: [];
        foreach ($items as $it) {
            if ($it === '.' || $it === '..') {
                continue;
            }
            $p = $dir . '/' . $it;
            is_dir($p) ? $this->rrmdir($p) : @unlink($p);
        }
        @rmdir($dir);
    }
}
