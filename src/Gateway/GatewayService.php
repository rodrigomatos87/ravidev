<?php

declare(strict_types=1);

namespace Ravi\Gateway;

use Ravi\Core\Database;

/**
 * Orquestra uma requisição no formato Messages API da Anthropic, servida pela
 * assinatura Max (Claude Code). O Ravi Developer é uma ALTERNATIVA à API oficial
 * para uso interno — NÃO faz fallback para a API oficial (quem decide isso é o
 * cliente, ex.: um sistema de e-mail). Monta a resposta no formato da Messages API e
 * registra o consumo em gw_requests.
 *
 * Isolado do produto — só toca tabelas gw_*.
 */
class GatewayService
{
    /** Chave em app_settings do endereço público do Gateway (configurado no painel). */
    public const CHAVE_URL_PUBLICA = 'gateway_public_url';

    /**
     * Endereço público do Gateway desta instalação, sem barra no fim.
     * Prioridade: configurado no painel (app_settings) > GW_PUBLIC_URL do .env > https://api.<host do painel>.
     */
    public static function urlPublica(): string
    {
        return self::urlPublicaComOrigem()['url'];
    }

    /** Mesmo que urlPublica(), dizendo de onde veio: painel, env ou padrao. @return array{url:string, origem:string} */
    public static function urlPublicaComOrigem(): array
    {
        $doPainel = '';
        try {
            $doPainel = trim((string) \Ravi\Models\Setting::get(self::CHAVE_URL_PUBLICA, ''));
        } catch (\Throwable $e) {
            // sem banco: segue para o .env
        }
        if ($doPainel !== '') {
            return ['url' => rtrim($doPainel, '/'), 'origem' => 'painel'];
        }
        $doEnv = trim((string) (env('GW_PUBLIC_URL', '') ?: ''));
        if ($doEnv !== '') {
            return ['url' => rtrim($doEnv, '/'), 'origem' => 'env'];
        }
        return ['url' => 'https://api.' . config('urls.host'), 'origem' => 'padrao'];
    }

    /**
     * Valida e normaliza o endereço digitado no painel: https, domínio de verdade (sem IP,
     * sem localhost), sem caminho, consulta ou credenciais. Vazio é válido (volta ao padrão).
     * @return array{ok:bool, url:string, erro:string}
     */
    public static function validarUrlPublica(string $bruto): array
    {
        $bruto = trim($bruto);
        if ($bruto === '') {
            return ['ok' => true, 'url' => '', 'erro' => ''];
        }
        if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $bruto)) {
            $bruto = 'https://' . $bruto;
        }
        $partes = parse_url($bruto);
        $falha = static fn(string $m): array => ['ok' => false, 'url' => '', 'erro' => $m];
        if (!is_array($partes) || empty($partes['host'])) {
            return $falha('Endereço inválido. Use o formato https://api.seu-dominio.com.br');
        }
        if (strtolower((string) ($partes['scheme'] ?? '')) !== 'https') {
            return $falha('O endereço precisa começar com https://');
        }
        if (isset($partes['user']) || isset($partes['pass']) || isset($partes['query']) || isset($partes['fragment'])
            || !in_array($partes['path'] ?? '', ['', '/'], true)) {
            return $falha('Informe só o domínio, sem caminho no fim (ex.: https://api.seu-dominio.com.br).');
        }
        $host = strtolower((string) $partes['host']);
        if (filter_var($host, FILTER_VALIDATE_IP) || !str_contains($host, '.')
            || !filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
            || !preg_match('/\.[a-z]{2,}$/', $host) || str_contains($host, 'seu-dominio')) {
            return $falha('Informe um domínio válido (ex.: api.seu-dominio.com.br).');
        }
        $porta = isset($partes['port']) && (int) $partes['port'] !== 443 ? ':' . (int) $partes['port'] : '';
        return ['ok' => true, 'url' => 'https://' . $host . $porta, 'erro' => ''];
    }

    /** Tempo (s) desta requisição no backend: GW_MAX_TIMEOUT, ou o pedido pelo cliente (faixa longa). */
    private int $tempo = 0;
    /** Requisição na faixa LONGA (tempo pedido acima do GW_MAX_TIMEOUT): semáforo próprio e orçamento = tempo. */
    private bool $longa = false;
    /** max_tokens recebido no corpo desta requisição (vai para gw_requests.max_tokens). */
    private ?int $maxTokensPedido = null;

    /**
     * Tempo da requisição. Sem pedido (null) = GW_MAX_TIMEOUT, como sempre foi. Com o
     * cabeçalho x-ravi-tempo-limite, o cliente escolhe entre 10 s e GW_TEMPO_TETO (600):
     * para o processamento longo (ex.: conversão de contrato inteiro do Ravisign), em que
     * o modelo raciocina antes de responder e passa dos 110 s. O nginx do vhost precisa de
     * fastcgi_read_timeout ACIMA do teto (630), senão corta antes.
     * @return array{0:int,1:bool} [tempo em s, faixa longa?]
     */
    public static function tempoDaRequisicao(?int $pedido): array
    {
        $padrao = max(5, (int) env('GW_MAX_TIMEOUT', 50));
        if ($pedido === null || $pedido <= 0) {
            return [$padrao, false];
        }
        $teto = max($padrao, (int) env('GW_TEMPO_TETO', 600));
        $tempo = max(10, min($pedido, $teto));
        return [$tempo, $tempo > $padrao];
    }

    /**
     * @param array $body  Corpo já decodificado (formato Messages API).
     * @param ?int  $tokenId  Token autenticado (para registrar o consumo).
     * @return array{http:int, json:array}
     */
    public function createMessage(array $body, ?int $tokenId, ?string $clientIp = null, array $raviFields = [], ?int $tempoPedido = null): array
    {
        $started = microtime(true);
        [$this->tempo, $this->longa] = self::tempoDaRequisicao($tempoPedido);
        $model = (string) ($body['model'] ?? '');
        // max_tokens e thinking da Messages API: repassados ao CLI (sem thinking pedido, o
        // raciocínio fica desligado e a saída limitada ao max_tokens) e registrados no histórico.
        $maxTokens = isset($body['max_tokens']) && is_numeric($body['max_tokens']) && (int) $body['max_tokens'] > 0
            ? (int) $body['max_tokens'] : null;
        $this->maxTokensPedido = $maxTokens;
        $thinking = is_array($body['thinking'] ?? null) ? $body['thinking'] : null;
        $system = $this->extractSystem($body['system'] ?? '');
        $userText = $this->extractUserText($body['messages'] ?? []);
        // Anexos do cliente (visão/PDF): blocos de imagem e de documento repassados
        // ao backend CLI como arquivos; o achatamento texto-puro os descartava em silêncio.
        // Anexo fora das regras (mime, tamanho, quantidade) NÃO é descartado em silêncio:
        // vira 400 claro mais abaixo (senão o modelo responderia "não vejo imagem nenhuma").
        $anx = $this->extractAnexos($body['messages'] ?? []);
        $anexos = $anx['anexos'];

        // Campos de identidade configurados para este token (personalizados por token).
        $fields = $this->tokenFields($tokenId);

        // Identidade RECEBIDA (para registrar), respeitando log_server_identity.
        $srvTok = $srvIps = $identityJson = null;
        if ($this->tokenLogsIdentity($tokenId)) {
            // Coluna dedicada server_token = token do sistema Ravi (field_key 'tokenravi').
            $srvTok = ($raviFields['tokenravi'] ?? '') !== '' ? mb_substr((string) $raviFields['tokenravi'], 0, 191) : null;
            $srvIps = ($raviFields['server_ips'] ?? '') !== '' ? mb_substr((string) $raviFields['server_ips'], 0, 255) : null;
            if ($fields !== []) {
                $obj = [];
                foreach ($fields as $f) { $obj[$f['field_key']] = $raviFields[$f['field_key']] ?? null; }
                $identityJson = json_encode($obj, JSON_UNESCAPED_UNICODE);
            }
        }

        // "Chamada" registrada para auditoria = system + mensagem do usuário.
        $sufAnexos = '';
        if ($anexos !== []) {
            $nPdf = count(array_filter($anexos, static fn(array $a): bool => $a['media_type'] === 'application/pdf'));
            $nImg = count($anexos) - $nPdf;
            $p = [];
            if ($nImg > 0) { $p[] = $nImg . ' imagem(ns)'; }
            if ($nPdf > 0) { $p[] = $nPdf . ' PDF'; }
            $sufAnexos = "\n[anexos: " . implode(', ', $p) . ']';
        }
        $requestText = ($system !== '' ? "[SYSTEM]\n" . $system . "\n\n" : '') . "[USER]\n" . (string) $userText
            . $sufAnexos;

        // Recusa por um gate de segurança: PERSISTE a tentativa em gw_requests (status=error)
        // — motivo, identidade recebida, modelo e corpo — para análise forense, e devolve o
        // erro GENÉRICO ao chamador (sem revelar o porquê). O motivo também vai ao log do
        // servidor para tail ao vivo. NÃO revelamos nada ao chamador que ensine a burlar.
        $reject = function (string $reason) use ($tokenId, $clientIp, $model, $started, $requestText, $srvTok, $srvIps, $identityJson): array {
            error_log('[gateway] token ' . ($tokenId ?? '?') . ' recusado: ' . $reason . ' origem=' . ($clientIp ?? '?'));
            $this->record($tokenId, $clientIp, $model, null, null, [], 'error', $reason, $started, $requestText, null, $srvTok, $srvIps, $identityJson);
            return $this->errorResponse(400, 'invalid_request_error', 'Requisição inválida.');
        };

        // GATE de obrigatoriedade de identidade: campo obrigatório ausente/vazio.
        $missing = [];
        foreach ($fields as $f) {
            if ((int) $f['required'] === 1 && trim((string) ($raviFields[$f['field_key']] ?? '')) === '') {
                $missing[] = $f['field_key'];
            }
        }
        if ($missing !== []) {
            return $reject('identidade obrigatória ausente (' . implode(',', $missing) . ')');
        }

        if ($model === '' || $userText === null) {
            // Validação padrão da Messages API (model/messages são campos públicos) — registra
            // a tentativa, mas mantém a mensagem específica (não é gate de segurança).
            $this->record($tokenId, $clientIp, $model, null, null, [], 'error', 'model/messages ausente', $started, $requestText, null, $srvTok, $srvIps, $identityJson);
            return $this->errorResponse(400, 'invalid_request_error',
                'Campos obrigatórios ausentes: "model" e "messages" (com ao menos uma mensagem de usuário).');
        }

        // Anexo inválido: validação PÚBLICA de formato (como model/messages) — mensagem
        // específica ajuda o integrador e não ensina a burlar nada.
        if ($anx['erro'] !== null) {
            $this->record($tokenId, $clientIp, $model, null, null, [], 'error', 'anexo inválido: ' . $anx['erro'], $started, $requestText, null, $srvTok, $srvIps, $identityJson);
            return $this->errorResponse(400, 'invalid_request_error', $anx['erro']);
        }

        // GATE de catálogo: modelo desconhecido (fora do catálogo Anthropic e DeepSeek)
        // NÃO é servido. Sem isto, o mapModel cairia em sonnet por padrão e serviria um
        // modelo qualquer (ex.: claude-fable-5) silenciosamente como Sonnet.
        $ehDeepSeekExplicito = str_starts_with(strtolower($model), 'deepseek');
        $ehCodexExplicito = str_starts_with(strtolower($model), 'gpt');
        if ($ehCodexExplicito && !GatewayCodexService::modeloConhecido($model)) {
            // gpt-* fora do catálogo curado: com conta ChatGPT o Codex recusa (400) ou
            // degrada com metadados de fallback — melhor recusar aqui, com log.
            return $reject('modelo desconhecido (' . $model . ')');
        }
        if (!$ehDeepSeekExplicito && !$ehCodexExplicito && !GatewayClaudeService::isKnownModel($model)) {
            return $reject('modelo desconhecido (' . $model . ')');
        }

        // GATE de modelos permitidos: comparação por família (opus/sonnet/haiku/
        // deepseek-flash/deepseek-pro), então sufixos de versão não escapam da regra.
        // A restrição vale igual para os modelos DeepSeek: fora da lista, recusa.
        $allowedModels = $this->tokenAllowedModels($tokenId);
        $allowedFams = $allowedModels === null ? null
            : array_values(array_unique(array_map([self::class, 'familia'], $allowedModels)));
        if ($allowedFams !== null && !in_array(self::familia($model), $allowedFams, true)) {
            return $reject('modelo não permitido (' . $model . ')');
        }

        // ── Backend DeepSeek ──
        // Duas portas de entrada: pedido EXPLÍCITO de um modelo deepseek-* do catálogo,
        // ou a regra de executor do token remapeando os claude-* (migração transparente).
        // Token sem regra e pedido claude-* seguem o caminho Max de sempre (este bloco
        // nem executa). Encaminhamento HTTP puro do corpo ORIGINAL (multi-turno e anexos
        // intactos), sem container/slot. Fallback só existe se o HUB DE BACKUPS mandar:
        // com backup claude-* configurado para o modelo executado, a falha cai no Max
        // logo abaixo; sem backup, o erro volta claro como sempre.
        $backups = self::modelBackups();
        $modelosMaxPlano = null;   // definido quando uma reserva Claude do hub manda a falha DeepSeek/Codex para o Max
        $reservasPosMax = null;    // resto da fila de reservas, para depois do Max quando ele também esgotar
        $erroDeepSeek = null;
        $erroCodex = null;
        if ($ehDeepSeekExplicito && $this->tokenBackendRule($tokenId) === 'max_deepseek') {
            // Regra INVERSA do executor (30/10/2026): token com backend='max_deepseek' serve os
            // pedidos deepseek-* no plano Max (flash → Haiku; pro → Sonnet), sem gastar a conta
            // DeepSeek. Caso de uso: um cliente antigo pede deepseek-flash por herança do tempo
            // de gargalo no servidor compartilhado; com o RaviDev API dedicado o Haiku responde
            // em ~5 s. model_requested continua sendo o pedido original (a medição explícito ×
            // fallback em gw_requests segue valendo); model_served mostra o Claude que atendeu.
            $modelosMaxPlano = [str_contains(strtolower($model), 'pro')
                ? (string) env('GW_FALLBACK_MODEL', 'claude-sonnet-5')
                : 'claude-haiku-4-5'];
        } elseif ($ehDeepSeekExplicito || $this->tokenBackendFor($tokenId, GatewayClaudeService::mapModel($model)) === 'deepseek') {
            $ds = (new GatewayDeepSeekService())->forward($body, $this->longa ? $this->tempo : 120);
            if ($ds['ok'] && $ds['json'] !== null) {
                return $this->deepseekSucesso($ds['json'], $tokenId, $clientIp, $model, $started, $requestText, $srvTok, $srvIps, $identityJson);
            }
            $erroDeepSeek = (string) ($ds['error'] ?? 'desconhecido');
            // modelo EXECUTADO na DeepSeek: o pedido (explícito) ou o equivalente do remap
            $executado = $ehDeepSeekExplicito ? strtolower($model)
                : (GatewayClaudeService::mapModel($model) === 'opus' ? 'deepseek-v4-pro' : 'deepseek-flash');
            $fila = $this->reservasElegiveis($backups[$executado] ?? [], $allowedFams);
            $r = $this->executarFilaReservas($fila, true, false, $body, $system, $userText, $anexos,
                $tokenId, $clientIp, $model, $started, $requestText, $srvTok, $srvIps, $identityJson, $erroDeepSeek);
            if ($r['tipo'] === 'resposta') {
                return $r['resposta'];
            }
            if ($r['tipo'] === 'claude') {
                // a vez da reserva Claude: a MESMA chamada segue no Max logo abaixo,
                // e o resto da fila continua valendo se o Max também esgotar
                error_log('[gateway] token ' . ($tokenId ?? '?') . ': DeepSeek falhou (' . $erroDeepSeek . '); reserva ' . $r['claude'] . ' via Max');
                $modelosMaxPlano = [$r['claude']];
                $reservasPosMax = $r['resto'];
            } else {
                $this->record($tokenId, $clientIp, $model, null, 'deepseek', [], 'error', $erroDeepSeek, $started, $requestText, null, $srvTok, $srvIps, $identityJson);
                return $this->errorResponse(502, 'api_error', 'Falha ao processar a requisição via DeepSeek: ' . $erroDeepSeek);
            }
        }

        // ── Backend ChatGPT (Codex) ──
        // Modelos gpt-* do catálogo, servidos pelo Codex CLI nas contas ChatGPT do
        // painel (cota do plano). Container efêmero como o Max: entra no MESMO
        // semáforo de slots. Falhou em todas as contas, vale o hub de backups.
        if ($ehCodexExplicito && $modelosMaxPlano === null) {
            $slots = new GatewaySlots($this->longa);
            if (!$slots->acquire()) {
                $err = $this->erroSaturado();
                $this->record($tokenId, $clientIp, $model, null, 'codex', [], 'error', $err, $started, $requestText, null, $srvTok, $srvIps, $identityJson);
                return $this->errorResponse(529, 'overloaded_error', 'Servidor sobrecarregado. Tente novamente em instantes.');
            }
            try {
                $cx = (new GatewayCodexService($this->longa ? $this->tempo : null))->complete($model, $system, (string) $userText, $anexos);
            } finally {
                $slots->release();
            }
            if ($cx['ok']) {
                $this->record($tokenId, $clientIp, $model, (string) $cx['model'], 'codex', $cx, 'ok', null, $started, $requestText, (string) $cx['text'], $srvTok, $srvIps, $identityJson);
                return ['http' => 200, 'json' => $this->messageEnvelope((string) $cx['model'], (string) $cx['text'], (int) $cx['input_tokens'], (int) $cx['output_tokens'])];
            }
            $erroCodex = (string) ($cx['error'] ?? 'desconhecido');
            $fila = $this->reservasElegiveis($backups[strtolower($model)] ?? [], $allowedFams);
            $r = $this->executarFilaReservas($fila, true, false, $body, $system, $userText, $anexos,
                $tokenId, $clientIp, $model, $started, $requestText, $srvTok, $srvIps, $identityJson, $erroCodex);
            if ($r['tipo'] === 'resposta') {
                return $r['resposta'];
            }
            if ($r['tipo'] === 'claude') {
                error_log('[gateway] token ' . ($tokenId ?? '?') . ': Codex falhou (' . $erroCodex . '); reserva ' . $r['claude'] . ' via Max');
                $modelosMaxPlano = [$r['claude']];
                $reservasPosMax = $r['resto'];
            } else {
                $this->record($tokenId, $clientIp, $model, null, 'codex', [], 'error', $erroCodex, $started, $requestText, null, $srvTok, $srvIps, $identityJson);
                return $this->errorResponse(502, 'api_error', 'Falha ao processar a requisição via Codex: ' . $erroCodex);
            }
        }

        // Cadeia de licenças que o PAINEL configurou para a API (licença própria do token, ou o
        // modo global off/failover/balance). Sem failover cego e sem .env: cadeia vazia = recusa.
        $licenses = \Ravi\Services\LicenseService::apiFailoverChain($tokenId);
        if ($licenses === []) {
            $err = 'nenhuma licença ativa configurada para a API (Admin → Licenças)';
            $this->record($tokenId, $clientIp, $model, null, 'max', [], 'error', $err, $started, $requestText, null, $srvTok, $srvIps, $identityJson);
            return $this->errorResponse(502, 'api_error', 'Falha ao processar a requisição via Max: ' . $err);
        }
        // ── Backend PRIMÁRIO: proxy fino → Messages API oficial (padrão; GW_PROXY_ENABLED=false desliga) ──
        // Até 02/11/2026 vinha desligado: a API crua com o token OAuth do Max caía no rate-limit da
        // API PADRÃO (429 imediato no Opus/Sonnet). A causa era o request sem a cara do Claude Code:
        // a assinatura exige um primeiro bloco de `system` com a identidade do agente (e os
        // cabeçalhos do CLI), o que o GatewayHttpService passou a montar. Provado em A/B no
        // servidor da API: mesma licença, Opus 429 em 0,3 s sem o bloco e 200 em 0,9 s com ele.
        // Percorre a cadeia de licenças do painel: limite (429) ou sobrecarga (529) numa licença
        // passa à seguinte; outro erro de API ou falha de transporte cai no CLI legado, que
        // continua como reserva. Erro do proxy NUNCA vai direto ao cliente.
        $proxyErr = null;
        if (filter_var(env('GW_PROXY_ENABLED', true), FILTER_VALIDATE_BOOLEAN)) {
            foreach ($licenses as $lic) {
                $proxy = (new GatewayHttpService($lic['token'], $this->tempo))->forward($body);
                if ($proxy['ok'] && $proxy['json'] !== null) {
                    \Ravi\Services\LicenseService::touch($lic['id']);
                    $j = $proxy['json'];
                    $u = is_array($j['usage'] ?? null) ? $j['usage'] : [];
                    $prompt     = (int) ($u['input_tokens'] ?? 0);
                    $cacheRead  = (int) ($u['cache_read_input_tokens'] ?? 0);
                    $cacheWrite = (int) ($u['cache_creation_input_tokens'] ?? 0);
                    $output     = (int) ($u['output_tokens'] ?? 0);
                    $usage = [
                        'input_tokens'       => $prompt + $cacheRead + $cacheWrite, // total processado (compat)
                        'prompt_tokens'      => $prompt,
                        'cache_read_tokens'  => $cacheRead,
                        'cache_write_tokens' => $cacheWrite,
                        'output_tokens'      => $output,
                    ];
                    $this->record($tokenId, $clientIp, $model, (string) ($j['model'] ?? $model), 'max', $usage, 'ok', null, $started, $requestText, $this->extractResponseText($j), $srvTok, $srvIps, $identityJson);
                    // Repassa a resposta REAL da Anthropic (inclui usage com cache — corrige a telemetria do cliente).
                    return ['http' => $proxy['http'], 'json' => $j];
                }
                $proxyErr = '[' . $lic['name'] . '/proxy] ' . (string) $proxy['error'];
                if (!$proxy['transport_fail'] && in_array($proxy['http'], [429, 529], true)) {
                    continue; // limite desta licença: tenta a próxima da cadeia
                }
                break; // erro de API ou transporte: o CLI legado assume
            }
            error_log('[gateway] token ' . ($tokenId ?? '?') . ': proxy fino falhou (' . $proxyErr . '); reserva no CLI');
        }

        // ── Backend PRIMÁRIO (padrão): Claude Code CLI na assinatura Max (bucket generoso) ──
        // Tenta o modelo pedido em CADA licença da cadeia do painel; se todas falharem e o pedido
        // era Opus, repete a cadeia inteira no Sonnet. Erros de limite voltam em segundos,
        // então a cadeia é barata; um TIMEOUT (GW_MAX_TIMEOUT) consome o orçamento de parede e
        // encerra a cadeia para não estourar o fastcgi_read_timeout do nginx (630 s desde 20/10/2026;
        // era 120). Na faixa longa o orçamento é o tempo pedido pelo cliente (ver tempoDaRequisicao).
        //
        // SEMÁFORO: cada tentativa CLI é um container de 1GB; sem teto agregado, um token
        // vazado (ou uma tempestade de retry) derruba o host por OOM. Um slot por requisição,
        // segurado durante a cadeia inteira; sem slot em GW_SLOT_WAIT s → 529 overloaded.
        $slots = new GatewaySlots($this->longa);
        if (!$slots->acquire()) {
            $err = $this->erroSaturado();
            error_log('[gateway] token ' . ($tokenId ?? '?') . ' recusado: ' . $err . ' origem=' . ($clientIp ?? '?'));
            $this->record($tokenId, $clientIp, $model, null, 'max', [], 'error', $err, $started, $requestText, null, $srvTok, $srvIps, $identityJson);
            return $this->errorResponse(529, 'overloaded_error', 'Servidor sobrecarregado. Tente novamente em instantes.');
        }
        try {
        $models = $modelosMaxPlano ?? [$model];
        if ($modelosMaxPlano === null && GatewayClaudeService::mapModel($model) === 'opus') {
            $models[] = (string) env('GW_FALLBACK_MODEL', 'claude-sonnet-5');
        }
        // Não faz fallback para um modelo que o token não pode usar.
        if ($allowedFams !== null) {
            $models = array_values(array_filter($models, static fn($m) => in_array(GatewayClaudeService::mapModel($m), $allowedFams, true)));
        }
        $budget = (float) env('GW_FAILOVER_BUDGET', 100); // s de parede para ainda INICIAR nova tentativa
        // Faixa longa: o orçamento é o próprio tempo pedido. Cada tentativa recebe só o que
        // SOBRA dele (a parede total fica no tempo pedido, que o cliente está esperando), e
        // uma nova tentativa só começa com pelo menos 20 s de sobra.
        $tentativaTempo = $this->tempo;
        $attempts = [];
        $max = null;
        foreach ($models as $tryModel) {
            foreach ($licenses as $lic) {
                if ($this->longa) {
                    $sobra = (int) floor($this->tempo - (microtime(true) - $started));
                    if ($attempts !== [] && $sobra < 20) {
                        $attempts[] = 'failover interrompido (orçamento de tempo)';
                        break 2;
                    }
                    $tentativaTempo = max(10, min($this->tempo, $sobra));
                } elseif ($attempts !== [] && (microtime(true) - $started) > $budget) {
                    $attempts[] = 'failover interrompido (orçamento de tempo)';
                    break 2;
                }
                $max = (new GatewayClaudeService($lic['token'], $tentativaTempo))->complete($tryModel, $system, $userText, $anexos, $maxTokens, $thinking);
                if ($max['ok']) {
                    if ($lic['id'] !== null) {
                        \Ravi\Services\LicenseService::touch($lic['id']);
                    }
                    $this->record($tokenId, $clientIp, $model, $max['model'], 'max', $max, 'ok', null, $started, $requestText, (string) $max['text'], $srvTok, $srvIps, $identityJson);
                    // O envelope informa o modelo SERVIDO (o cliente percebe o fallback p/ Sonnet).
                    $served = $tryModel === $model ? $model : $tryModel;
                    return ['http' => 200, 'json' => $this->messageEnvelope($served, $max['text'], $max['input_tokens'], $max['output_tokens'], $max['stop_reason'] ?? null)];
                }
                $attempts[] = '[' . $lic['name'] . '/' . GatewayClaudeService::mapModel($tryModel) . '] ' . (string) ($max['error'] ?? '?');
            }
        }

        $err = $attempts !== [] ? implode(' | ', $attempts) : (string) ($proxyErr ?? 'desconhecido');
        if ($erroDeepSeek !== null) {
            $err = 'DeepSeek: ' . $erroDeepSeek . ' | ' . $err;
        }
        if ($erroCodex !== null) {
            $err = 'Codex: ' . $erroCodex . ' | ' . $err;
        }

        // Hub de backups depois do Max: a fila é o RESTO das reservas do titular
        // original (quando o Max era ele próprio uma reserva Claude), ou a fila do
        // modelo claude-* pedido. Reservas Claude aqui são puladas (Max já esgotou);
        // ainda dentro do slot desta requisição, o Codex roda inline sem novo acquire.
        $filaPosMax = $reservasPosMax ?? ($modelosMaxPlano === null
            ? $this->reservasElegiveis($backups[strtolower($model)] ?? [], $allowedFams)
            : []);
        if ($filaPosMax !== []) {
            error_log('[gateway] token ' . ($tokenId ?? '?') . ': Max esgotado (' . $err . '); seguindo a fila de reservas');
            $r = $this->executarFilaReservas($filaPosMax, false, true, $body, $system, $userText, $anexos,
                $tokenId, $clientIp, $model, $started, $requestText, $srvTok, $srvIps, $identityJson, $err);
            if ($r['tipo'] === 'resposta') {
                return $r['resposta'];
            }
        }

        // Sem fallback além daqui: o cliente é quem trata o fallback p/ API oficial.
        $this->record($tokenId, $clientIp, $model, null, 'max', $max ?? [], 'error', $err, $started, $requestText, null, $srvTok, $srvIps, $identityJson);
        return $this->errorResponse(502, 'api_error',
            'Falha ao processar a requisição via Max: ' . $err);
        } finally {
            $slots->release();
        }
    }

    /**
     * Resposta OK vinda da DeepSeek: registra o consumo (com os campos de cache
     * reais) e repassa o envelope Anthropic original. model_requested x
     * model_served no histórico denunciam quando a chamada veio de um backup.
     * @return array{http:int, json:array}
     */
    private function deepseekSucesso(array $j, ?int $tokenId, ?string $clientIp, string $modelPedido, float $started,
                                     ?string $requestText, ?string $srvTok, ?string $srvIps, ?string $identityJson): array
    {
        $u = is_array($j['usage'] ?? null) ? $j['usage'] : [];
        $prompt     = (int) ($u['input_tokens'] ?? 0);
        $cacheRead  = (int) ($u['cache_read_input_tokens'] ?? 0);
        $cacheWrite = (int) ($u['cache_creation_input_tokens'] ?? 0);
        $usage = [
            'input_tokens'       => $prompt + $cacheRead + $cacheWrite, // total processado (compat)
            'prompt_tokens'      => $prompt,
            'cache_read_tokens'  => $cacheRead,
            'cache_write_tokens' => $cacheWrite,
            'output_tokens'      => (int) ($u['output_tokens'] ?? 0),
        ];
        $this->record($tokenId, $clientIp, $modelPedido, (string) ($j['model'] ?? 'deepseek'), 'deepseek', $usage, 'ok', null, $started, $requestText, $this->extractResponseText($j), $srvTok, $srvIps, $identityJson);
        return ['http' => 200, 'json' => $j];
    }

    // ── Extração do corpo (Anthropic aceita system/content como string OU array de blocos) ──

    private function extractSystem(mixed $system): string
    {
        if (is_string($system)) {
            return $system;
        }
        if (is_array($system)) {
            $parts = [];
            foreach ($system as $blk) {
                if (is_array($blk) && isset($blk['text'])) {
                    $parts[] = (string) $blk['text'];
                } elseif (is_string($blk)) {
                    $parts[] = $blk;
                }
            }
            return implode("\n", $parts);
        }
        return '';
    }

    /** Concatena as mensagens em um único prompt de usuário (há clientes que mandam só 1 user). */
    private function extractUserText(mixed $messages): ?string
    {
        if (!is_array($messages) || $messages === []) {
            return null;
        }
        $parts = [];
        foreach ($messages as $msg) {
            if (!is_array($msg)) {
                continue;
            }
            $role = (string) ($msg['role'] ?? 'user');
            $content = $msg['content'] ?? '';
            $text = '';
            if (is_string($content)) {
                $text = $content;
            } elseif (is_array($content)) {
                foreach ($content as $blk) {
                    if (is_array($blk) && ($blk['type'] ?? '') === 'text') {
                        $text .= (string) ($blk['text'] ?? '');
                    } elseif (is_string($blk)) {
                        $text .= $blk;
                    }
                }
            }
            if (trim($text) === '') {
                continue;
            }
            // Prefixo de papel só quando houver mais de uma mensagem (histórico).
            $parts[] = count($messages) > 1 ? strtoupper($role) . ': ' . $text : $text;
        }
        return $parts === [] ? null : implode("\n\n", $parts);
    }

    /**
     * Anexos das mensagens: blocos de imagem (máx. 4, 5MB cada decodificada, mime
     * png/jpeg/webp/gif) e de documento PDF (máx. 1, 10MB decodificado).
     * Devolve ['anexos' => [{media_type, data(binário)}], 'erro' => ?string].
     * Anexo fora das regras vira ERRO (o chamador devolve 400): descartado em
     * silêncio, o modelo responderia "não vejo imagem nenhuma" e o integrador
     * nunca saberia o porquê.
     */
    private function extractAnexos(mixed $messages): array
    {
        $out = [];
        $imgs = 0;
        $pdfs = 0;
        $err = static fn(string $m): array => ['anexos' => [], 'erro' => $m];
        if (!is_array($messages)) {
            return ['anexos' => [], 'erro' => null];
        }
        foreach ($messages as $msg) {
            $content = is_array($msg) ? ($msg['content'] ?? null) : null;
            if (!is_array($content)) {
                continue;
            }
            foreach ($content as $blk) {
                if (!is_array($blk)) {
                    continue;
                }
                $type = (string) ($blk['type'] ?? '');
                if ($type !== 'image' && $type !== 'document') {
                    continue;
                }
                $rotulo = $type === 'image' ? 'Imagem' : 'Documento';
                $src = is_array($blk['source'] ?? null) ? $blk['source'] : [];
                if (($src['type'] ?? '') !== 'base64') {
                    return $err($rotulo . ': apenas source.type "base64" é suportado.');
                }
                $mt = mb_substr((string) ($src['media_type'] ?? ''), 0, 60);
                if ($type === 'image') {
                    if (!in_array($mt, ['image/png', 'image/jpeg', 'image/webp', 'image/gif'], true)) {
                        return $err('Imagem: media_type "' . $mt . '" não suportado (aceitos: image/png, image/jpeg, image/webp, image/gif).');
                    }
                } elseif ($mt !== 'application/pdf') {
                    return $err('Documento: media_type "' . $mt . '" não suportado (aceito: application/pdf).');
                }
                $data = base64_decode((string) ($src['data'] ?? ''), true);
                if ($data === false || $data === '') {
                    return $err($rotulo . ': dados base64 inválidos ou vazios.');
                }
                $capMb = $type === 'image' ? 5 : 10;
                if (strlen($data) > $capMb * 1024 * 1024) {
                    return $err($rotulo . ' excede o limite de ' . $capMb . 'MB (tamanho decodificado).');
                }
                if ($type === 'image') {
                    // Valida que o conteúdo É uma imagem e limita as dimensões (mesmo teto
                    // da Anthropic: 8000px por lado) — também protege o ajuste GD do
                    // backend contra bomba de descompressão (25MP ≈ 125MB decodificados).
                    $info = @getimagesizefromstring($data);
                    if ($info === false) {
                        return $err('Imagem: o conteúdo não é uma imagem válida.');
                    }
                    if ($info[0] > 8000 || $info[1] > 8000 || ($info[0] * $info[1]) > 25_000_000) {
                        return $err('Imagem: dimensões máximas de 8000x8000 e até 25 megapixels.');
                    }
                }
                if ($type === 'image' && ++$imgs > 4) {
                    return $err('Máximo de 4 imagens por requisição.');
                }
                if ($type === 'document' && ++$pdfs > 1) {
                    return $err('Máximo de 1 documento PDF por requisição.');
                }
                $out[] = ['media_type' => $mt, 'data' => $data];
            }
        }
        return ['anexos' => $out, 'erro' => null];
    }

    /** Extrai o texto concatenado dos blocos `content` de uma resposta Messages API (para auditoria). */
    private function extractResponseText(array $json): string
    {
        $out = '';
        if (isset($json['content']) && is_array($json['content'])) {
            foreach ($json['content'] as $blk) {
                if (is_array($blk) && ($blk['type'] ?? '') === 'text') {
                    $out .= (string) ($blk['text'] ?? '');
                }
            }
        }
        return $out;
    }

    // ── Montagem de respostas no formato Anthropic ────────────────────────────

    private function messageEnvelope(string $model, string $text, int $in, int $out, ?string $stopReason = null): array
    {
        return [
            'id'            => 'msg_' . bin2hex(random_bytes(12)),
            'type'          => 'message',
            'role'          => 'assistant',
            'model'         => $model,
            'content'       => [['type' => 'text', 'text' => $text]],
            'stop_reason'   => $stopReason ?? 'end_turn',
            'stop_sequence' => null,
            'usage'         => ['input_tokens' => $in, 'output_tokens' => $out],
        ];
    }

    /**
     * POST /v1/messages/count_tokens — estima o número de tokens de entrada (system + messages +
     * tools). É uma ESTIMATIVA (~4 caracteres/token); a contagem exata exigiria o tokenizer oficial.
     */
    public function countTokens(array $body): array
    {
        if ((string) ($body['model'] ?? '') === '') {
            return $this->errorResponse(400, 'invalid_request_error', 'Campo obrigatório ausente: "model".');
        }
        $system = $this->extractSystem($body['system'] ?? '');
        $userText = (string) $this->extractUserText($body['messages'] ?? []);
        $chars = mb_strlen($system) + mb_strlen($userText);
        if (!empty($body['tools']) && is_array($body['tools'])) {
            $chars += mb_strlen((string) json_encode($body['tools']));
        }
        $est = (int) max(1, (int) ceil($chars / 4));
        return ['http' => 200, 'json' => ['input_tokens' => $est]];
    }

    /** GET /v1/models — catálogo de modelos no formato da API da Anthropic.
     *  Os modelos DeepSeek só aparecem com a chave cadastrada (sem ela, seriam recusados). */
    public function listModels(): array
    {
        $models = $this->modelCatalog();
        if (!GatewayDeepSeekService::configured()) {
            $models = array_values(array_filter($models, static fn(array $m): bool => ($m['provider'] ?? '') !== 'deepseek'));
        }
        if (!GatewayCodexService::disponivel()) {
            $models = array_values(array_filter($models, static fn(array $m): bool => ($m['provider'] ?? '') !== 'openai'));
        }
        return ['http' => 200, 'json' => [
            'data'     => $models,
            'has_more' => false,
            'first_id' => $models[0]['id'] ?? null,
            'last_id'  => $models ? $models[count($models) - 1]['id'] : null,
        ]];
    }

    /** GET /v1/models/{id} — detalhe de um modelo, ou 404. */
    public function getModel(string $id): array
    {
        foreach ($this->modelCatalog() as $mdl) {
            if ($mdl['id'] === $id) {
                return ['http' => 200, 'json' => $mdl];
            }
        }
        return $this->errorResponse(404, 'not_found_error', 'Modelo não encontrado: ' . $id);
    }

    /**
     * Hub de backups de modelo (Admin → Licenças → Backup de modelo): mapa global
     * {modelo executado => FILA ordenada de reservas}, em app_settings
     * (gw_model_backups). Quando a execução do titular falha (DeepSeek fora do
     * ar/sem crédito, Codex sem conta, cadeia Max esgotada), o gateway percorre a
     * fila NA ORDEM, cada reserva no próprio backend, respeitando os modelos
     * permitidos do token. Só a fila do TITULAR vale (a reserva da reserva nunca
     * é seguida: sem laço). Aceita o formato antigo (valor string = fila de 1).
     * @return array<string,string[]> chaves e valores em minúsculas
     */
    public static function modelBackups(): array
    {
        try {
            $raw = (string) (\Ravi\Models\Setting::get('gw_model_backups', '') ?? '');
            $map = json_decode($raw, true);
            if (!is_array($map)) return [];
            $out = [];
            foreach ($map as $de => $para) {
                $de = strtolower(trim((string) $de));
                if ($de === '') continue;
                $fila = [];
                foreach ((is_array($para) ? $para : [$para]) as $p) {
                    $p = strtolower(trim((string) $p));
                    if ($p !== '' && $p !== $de && !in_array($p, $fila, true)) $fila[] = $p;
                }
                if ($fila !== []) $out[$de] = array_slice($fila, 0, 3);
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Reservas da fila que o token pode usar (trava de modelos) e que têm backend válido. */
    private function reservasElegiveis(array $fila, ?array $allowedFams): array
    {
        $out = [];
        foreach ($fila as $r) {
            $r = strtolower((string) $r);
            $backendOk = str_starts_with($r, 'claude')
                || str_starts_with($r, 'deepseek')
                || (str_starts_with($r, 'gpt') && GatewayCodexService::modeloConhecido($r));
            $famOk = $allowedFams === null || in_array(self::familia($r), $allowedFams, true);
            if ($backendOk && $famOk && !in_array($r, $out, true)) $out[] = $r;
        }
        return $out;
    }

    /**
     * Percorre a fila de reservas NA ORDEM. deepseek-* e gpt-* executam aqui mesmo;
     * uma reserva claude-* interrompe a fila e é devolvida ao chamador (que manda a
     * chamada ao caminho Max, com o RESTO da fila valendo depois, se o Max esgotar).
     * $comSlot: o chamador já segura um slot (o Codex roda inline, sem novo acquire).
     * @return array{tipo:'resposta'|'claude'|'fim', resposta?:array, claude?:string, resto?:array}
     */
    private function executarFilaReservas(array $fila, bool $permitirClaude, bool $comSlot, array $body, string $system,
        ?string $userText, array $anexos, ?int $tokenId, ?string $clientIp, string $model, float $started,
        ?string $requestText, ?string $srvTok, ?string $srvIps, ?string $identityJson, string &$erros): array
    {
        foreach ($fila as $i => $reserva) {
            if (str_starts_with($reserva, 'claude')) {
                if ($permitirClaude) {
                    return ['tipo' => 'claude', 'claude' => $reserva, 'resto' => array_slice($fila, $i + 1)];
                }
                $erros .= ' | reserva ' . $reserva . ': cadeia Max já esgotada, pulada';
                continue;
            }
            if (str_starts_with($reserva, 'deepseek')) {
                error_log('[gateway] token ' . ($tokenId ?? '?') . ': tentando reserva ' . $reserva . ' via DeepSeek');
                $bodyReserva = $body;
                $bodyReserva['model'] = $reserva;
                $ds = (new GatewayDeepSeekService())->forward($bodyReserva, $this->longa ? $this->tempo : 120);
                if ($ds['ok'] && $ds['json'] !== null) {
                    return ['tipo' => 'resposta', 'resposta' => $this->deepseekSucesso($ds['json'], $tokenId, $clientIp, $model, $started, $requestText, $srvTok, $srvIps, $identityJson)];
                }
                $erros .= ' | reserva DeepSeek (' . $reserva . '): ' . (string) ($ds['error'] ?? 'desconhecido');
                continue;
            }
            // gpt-*: Codex em container — respeita o semáforo quando ainda não há slot
            error_log('[gateway] token ' . ($tokenId ?? '?') . ': tentando reserva ' . $reserva . ' via Codex');
            $slots = null;
            if (!$comSlot) {
                $slots = new GatewaySlots($this->longa);
                if (!$slots->acquire()) {
                    $erros .= ' | reserva Codex (' . $reserva . '): gateway saturado';
                    continue;
                }
            }
            try {
                $cx = (new GatewayCodexService($this->longa ? $this->tempo : null))->complete($reserva, $system, (string) $userText, $anexos);
            } finally {
                $slots?->release();
            }
            if ($cx['ok']) {
                $this->record($tokenId, $clientIp, $model, (string) $cx['model'], 'codex', $cx, 'ok', null, $started, $requestText, (string) $cx['text'], $srvTok, $srvIps, $identityJson);
                return ['tipo' => 'resposta', 'resposta' => ['http' => 200, 'json' => $this->messageEnvelope((string) $cx['model'], (string) $cx['text'], (int) $cx['input_tokens'], (int) $cx['output_tokens'])]];
            }
            $erros .= ' | reserva Codex (' . $reserva . '): ' . (string) ($cx['error'] ?? 'desconhecido');
        }
        return ['tipo' => 'fim'];
    }

    /**
     * Família de um modelo para os gates de permissão: claude-* cai na família
     * Anthropic (opus/sonnet/haiku); deepseek-* cai em deepseek-flash/deepseek-pro.
     * Assim a trava de "Modelos permitidos" enxerga e barra DeepSeek como qualquer
     * outro modelo do catálogo.
     */
    public static function familia(string $model): string
    {
        $m = strtolower($model);
        if (str_starts_with($m, 'deepseek')) {
            return str_contains($m, 'pro') ? 'deepseek-pro' : 'deepseek-flash';
        }
        if (str_starts_with($m, 'gpt')) {
            return $m; // cada modelo GPT é a própria família: a trava distingue Astra de Sol etc.
        }
        return GatewayClaudeService::mapModel($model);
    }

    /** Catálogo de modelos servidos pelo gateway (Anthropic via Max + DeepSeek via API). */
    public function modelCatalog(): array
    {
        return [
            ['type' => 'model', 'id' => 'claude-opus-5-5',   'display_name' => 'Claude Opus 5.5',   'provider' => 'anthropic', 'created_at' => '2026-09-22T00:00:00Z'],
            ['type' => 'model', 'id' => 'claude-opus-5',     'display_name' => 'Claude Opus 5',     'provider' => 'anthropic', 'created_at' => '2026-07-01T00:00:00Z'],
            ['type' => 'model', 'id' => 'claude-opus-4-8',   'display_name' => 'Claude Opus 4.8',   'provider' => 'anthropic', 'created_at' => '2026-01-01T00:00:00Z'],
            ['type' => 'model', 'id' => 'claude-sonnet-5',   'display_name' => 'Claude Sonnet 5',   'provider' => 'anthropic', 'created_at' => '2026-08-01T00:00:00Z'],
            ['type' => 'model', 'id' => 'claude-sonnet-4-6', 'display_name' => 'Claude Sonnet 4.6', 'provider' => 'anthropic', 'created_at' => '2025-11-01T00:00:00Z'],
            ['type' => 'model', 'id' => 'claude-haiku-4-5',  'display_name' => 'Claude Haiku 4.5',  'provider' => 'anthropic', 'created_at' => '2025-10-01T00:00:00Z'],
            ['type' => 'model', 'id' => 'deepseek-flash',    'display_name' => 'DeepSeek V4.1 Flash', 'provider' => 'deepseek', 'created_at' => '2026-09-10T00:00:00Z'],
            ['type' => 'model', 'id' => 'deepseek-v4-pro',   'display_name' => 'DeepSeek V4 Pro',     'provider' => 'deepseek', 'created_at' => '2026-08-16T00:00:00Z'],
            ['type' => 'model', 'id' => 'gpt-6-astra',       'display_name' => 'GPT-6 Astra',         'provider' => 'openai',   'created_at' => '2026-09-01T00:00:00Z'],
            ['type' => 'model', 'id' => 'gpt-5.6-sol',       'display_name' => 'GPT-5.6 Sol',         'provider' => 'openai',   'created_at' => '2026-06-01T00:00:00Z'],
            ['type' => 'model', 'id' => 'gpt-5.6-terra',     'display_name' => 'GPT-5.6 Terra',       'provider' => 'openai',   'created_at' => '2026-06-01T00:00:00Z'],
            ['type' => 'model', 'id' => 'gpt-5.6-luna',      'display_name' => 'GPT-5.6 Luna',        'provider' => 'openai',   'created_at' => '2026-06-01T00:00:00Z'],
            ['type' => 'model', 'id' => 'gpt-5.5',           'display_name' => 'GPT-5.5',             'provider' => 'openai',   'created_at' => '2026-02-01T00:00:00Z'],
        ];
    }

    /** Motivo do 529 para o registro: diz qual faixa (normal ou longa) estava cheia. */
    private function erroSaturado(): string
    {
        return $this->longa
            ? 'gateway saturado na faixa longa (' . env('GW_LONGAS_MAX', 2) . ' chamadas longas simultâneas)'
            : 'gateway saturado (' . env('GW_MAX_CONCURRENCY', 6) . ' containers simultâneos)';
    }

    public function errorResponse(int $http, string $type, string $message): array
    {
        return [
            'http' => $http,
            'json' => ['type' => 'error', 'error' => ['type' => $type, 'message' => $message]],
        ];
    }

    // ── Registro de consumo ───────────────────────────────────────────────────

    /** Token registra a identidade do servidor de origem? (default sim; configurável no admin.) */
    private function tokenLogsIdentity(?int $tokenId): bool
    {
        if ($tokenId === null) return false;
        try {
            $row = Database::getInstance()->fetch("SELECT log_server_identity FROM gw_tokens WHERE id = ?", [$tokenId]);
            return $row ? ((int) $row['log_server_identity'] === 1) : false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Backend efetivo do token para uma família de modelo: 'max' (padrão) ou
     * 'deepseek'. Regras: gw_tokens.backend NULL/'' = Max para tudo;
     * 'deepseek_haiku' = só a família haiku via DeepSeek; 'deepseek' = tudo.
     */
    private function tokenBackendFor(?int $tokenId, string $family): string
    {
        $regra = $this->tokenBackendRule($tokenId);
        if ($regra === 'deepseek') return 'deepseek';
        if ($regra === 'deepseek_haiku' && $family === 'haiku') return 'deepseek';
        return 'max';
    }

    /** Regra BRUTA de executor do token (coluna gw_tokens.backend); '' = sem regra. */
    private function tokenBackendRule(?int $tokenId): string
    {
        if ($tokenId === null) return '';
        try {
            $row = Database::getInstance()->fetch("SELECT backend FROM gw_tokens WHERE id = ?", [$tokenId]);
            return trim((string) ($row['backend'] ?? ''));
        } catch (\Throwable $e) {
            return ''; // coluna ausente (migração ainda não rodou) = comportamento de sempre
        }
    }

    /** Modelos permitidos para o token, ou null se sem restrição (todos). */
    private function tokenAllowedModels(?int $tokenId): ?array
    {
        if ($tokenId === null) return null;
        try {
            $row = Database::getInstance()->fetch("SELECT allowed_models FROM gw_tokens WHERE id = ?", [$tokenId]);
            $raw = trim((string) ($row['allowed_models'] ?? ''));
            if ($raw === '') return null;
            $list = json_decode($raw, true);
            if (!is_array($list) || $list === []) return null;
            return array_values(array_filter(array_map('strval', $list), static fn($m) => $m !== ''));
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Campos de identidade configurados para o token (personalizados no admin). */
    private function tokenFields(?int $tokenId): array
    {
        if ($tokenId === null) return [];
        try {
            return Database::getInstance()->fetchAll(
                "SELECT field_key, label, required FROM gw_token_fields WHERE token_id = ? ORDER BY sort_order, id",
                [$tokenId]
            );
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function record(?int $tokenId, ?string $clientIp, ?string $modelReq, ?string $modelServed, ?string $backend,
                            array $usage, string $status, ?string $error, float $started,
                            ?string $requestText = null, ?string $responseText = null,
                            ?string $serverToken = null, ?string $serverIps = null, ?string $identityJson = null): void
    {
        // Cap de tamanho do conteúdo guardado (evita linhas gigantes; MEDIUMTEXT comporta).
        $cap = static fn(?string $s): ?string => $s === null ? null : mb_substr($s, 0, 200_000);

        try {
            Database::getInstance()->insert('gw_requests', [
                'token_id'           => $tokenId,
                'client_ip'          => $clientIp,
                'server_token'       => $serverToken,
                'server_ips'         => $serverIps,
                'identity_json'      => $identityJson,
                'model_requested'    => $modelReq,
                'model_served'       => $modelServed,
                'backend'            => $backend,
                'input_tokens'       => (int) ($usage['input_tokens'] ?? 0),
                'output_tokens'      => (int) ($usage['output_tokens'] ?? 0),
                'prompt_tokens'      => (int) ($usage['prompt_tokens'] ?? 0),
                'cache_read_tokens'  => (int) ($usage['cache_read_tokens'] ?? 0),
                'cache_write_tokens' => (int) ($usage['cache_write_tokens'] ?? 0),
                'max_tokens'         => $this->maxTokensPedido,
                'status'             => $status,
                'error'              => $error !== null ? mb_substr($error, 0, 255) : null,
                'duration_ms'        => (int) round((microtime(true) - $started) * 1000),
                'request_text'       => $cap($requestText),
                'response_text'      => $cap($responseText),
            ]);
        } catch (\Throwable $e) {
            error_log('[GatewayService] falha ao registrar gw_requests: ' . $e->getMessage());
        }
    }
}
