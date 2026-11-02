<?php

declare(strict_types=1);

namespace Ravi\Services;

use Ravi\Core\Database;
use Ravi\Core\Encryption;
use Ravi\Models\Setting;
use Ravi\Models\UserPref;

/**
 * Gerência de LICENÇAS do Claude Code (assinaturas Max). Cada licença é um CLAUDE_CODE_OAUTH_TOKEN
 * de uma conta Max (gerado por `claude setup-token`), guardado criptografado em `claude_licenses`.
 *
 * O admin cadastra várias e escolhe qual licença cada consumidor usa, SEPARADO por escopo:
 *   - api        → gateway (endereço do painel, GatewayService::urlPublica()) — só padrão global
 *   - ide        → IDE (code-server/claude) — global + override por usuário
 *   - autonomous → Dev Autônomo (ClaudeCodeService) — global + override por usuário
 *
 * Resolução (espelha o ModelService): override do USUÁRIO → padrão GLOBAL do escopo. NÃO existe
 * fallback para o .env: usar uma credencial que o admin não escolheu no painel foi exatamente o
 * vetor do vazamento de 07/2026. O que o painel setou, vale — funcionando ou não.
 *
 * ROTEAMENTO DA API (feature global, configurada no painel):
 *   - off      → a API usa só a licença global do escopo `api`;
 *   - failover → principal (licença global da API) + N backups em lista ORDENADA; tenta na ordem
 *                e para na primeira que responder;
 *   - balance  → 2 licenças escolhidas dividem a carga (sorteio por requisição); se a sorteada
 *                falhar, usa a parceira.
 * Um token de API com licença própria (gw_tokens.license_id) usa ELA como principal; no modo
 * failover os backups (também escolhidos pelo admin) valem para ele; no balance, não (usa só ela).
 *
 * ROTEAMENTO DO DEV AUTÔNOMO (feature global, configurada no painel):
 *   - off     → todos os agentes usam a licença do escopo (override do usuário → global);
 *   - balance → cada AGENTE é sorteado no spawn para a menos carregada de N licenças marcadas
 *               (sticky por agente/sessão; licença esgotada entra em cooldown até o reset).
 * Ver a seção "Roteamento do Dev Autônomo" abaixo.
 */
class LicenseService
{
    /** Escopos do núcleo (chave => rótulo). Módulos opcionais acrescentam os seus pelo gancho 'licencas.escopos'. */
    public const SCOPES = [
        'api'        => 'API (gateway)',
        'ide'        => 'IDE',
        'autonomous' => 'Dev Autônomo',
    ];

    /** Todos os escopos: os do núcleo mais os registrados por módulos (gancho 'licencas.escopos' devolve chave => rótulo). */
    public static function scopes(): array
    {
        $todos = self::SCOPES;
        foreach (\Ravi\Core\Modulos::gancho('licencas.escopos') as $extra) {
            foreach ((array) $extra as $chave => $rotulo) {
                $todos[(string) $chave] = (string) $rotulo;
            }
        }
        return $todos;
    }

    /** Escopos que aceitam override por usuário (a API é sempre global). */
    public const USER_SCOPES = ['ide', 'autonomous'];

    /** Pref (user_prefs) que libera o usuário a escolher a própria conta do Claude no perfil. */
    public const SELF_CHOICE_PREF = 'license_self_choice';

    /** @var array<int,array<string,mixed>>|null cache das linhas cruas por request */
    private static ?array $rows = null;

    // ──────────────────────────────────────────────────────────────────────
    // Armazenamento / CRUD
    // ──────────────────────────────────────────────────────────────────────

    private static function enc(): Encryption
    {
        return new Encryption();
    }

    /** @return array<int,array<string,mixed>> linhas cruas (com token_encrypted) indexadas por id */
    private static function rawRows(): array
    {
        if (self::$rows !== null) {
            return self::$rows;
        }
        self::$rows = [];
        try {
            foreach (Database::getInstance()->fetchAll('SELECT * FROM claude_licenses ORDER BY id ASC') as $r) {
                self::$rows[(int) $r['id']] = $r;
            }
        } catch (\Throwable $e) {
            // tabela ausente → sem licenças (cai no fallback do .env)
        }
        return self::$rows;
    }

    /** Lista para exibição (sem o token cru; só um "hint" mascarado). */
    public static function all(): array
    {
        $out = [];
        foreach (self::rawRows() as $r) {
            $out[] = [
                'id'           => (int) $r['id'],
                'name'         => (string) $r['name'],
                'is_active'    => (bool) $r['is_active'],
                'note'         => (string) ($r['note'] ?? ''),
                'hint'         => self::mask(self::decrypt($r)),
                'reset_dow'    => isset($r['weekly_reset_dow']) && $r['weekly_reset_dow'] !== null ? (int) $r['weekly_reset_dow'] : null,
                'reset_hour'   => isset($r['weekly_reset_hour']) && $r['weekly_reset_hour'] !== null ? (int) $r['weekly_reset_hour'] : null,
                'limit_pct'    => isset($r['usage_limit_pct']) && $r['usage_limit_pct'] !== null ? (int) $r['usage_limit_pct'] : null,
                'last_used_at' => $r['last_used_at'] ?? null,
                'created_at'   => $r['created_at'] ?? null,
            ];
        }
        return $out;
    }

    /** Linha de exibição de uma licença (sem token cru), ou null. */
    public static function find(int $id): ?array
    {
        foreach (self::all() as $r) {
            if ($r['id'] === $id) {
                return $r;
            }
        }
        return null;
    }

    public static function exists(int $id): bool
    {
        return isset(self::rawRows()[$id]);
    }

    /**
     * Normaliza um token colado pelo admin: terminais quebram tokens longos em várias linhas na
     * exibição, e o copiar/colar traz quebras e espaços PARA DENTRO do token. Como o token nunca
     * contém whitespace, remove-se todo (não só nas pontas).
     */
    private static function sanitizeToken(string $token): string
    {
        return preg_replace('/\s+/u', '', $token) ?? '';
    }

    /** Normaliza dia (0=dom…6=sáb) e hora (0–23) da renovação semanal; fora da faixa vira null. */
    private static function sanitizeReset(?int $dow, ?int $hour): array
    {
        return [
            ($dow !== null && $dow >= 0 && $dow <= 6) ? $dow : null,
            ($hour !== null && $hour >= 0 && $hour <= 23) ? $hour : null,
        ];
    }

    /** Normaliza o teto de uso da IDE/Dev Autônomo: 1–99; fora da faixa = sem teto (null). */
    private static function sanitizeLimit(?int $pct): ?int
    {
        return ($pct !== null && $pct >= 1 && $pct <= 99) ? $pct : null;
    }

    /** Cria uma licença. Retorna o id. */
    public static function create(string $name, string $token, ?string $note = null, ?int $resetDow = null, ?int $resetHour = null, ?int $limitPct = null): int
    {
        [$dow, $hour] = self::sanitizeReset($resetDow, $resetHour);
        $id = (int) Database::getInstance()->insert('claude_licenses', [
            'name'              => mb_substr(trim($name), 0, 100),
            'token_encrypted'   => self::enc()->encrypt(self::sanitizeToken($token)),
            'is_active'         => 1,
            'note'              => $note !== null ? mb_substr($note, 0, 255) : null,
            'weekly_reset_dow'  => $dow,
            'weekly_reset_hour' => $hour,
            'usage_limit_pct'   => self::sanitizeLimit($limitPct),
        ]);
        self::$rows = null;
        return $id;
    }

    /** Renomeia / atualiza nota, renovação semanal e teto de uso (e o token, se um novo for enviado). */
    public static function update(int $id, string $name, ?string $note, ?string $newToken = null, ?int $resetDow = null, ?int $resetHour = null, ?int $limitPct = null): void
    {
        [$dow, $hour] = self::sanitizeReset($resetDow, $resetHour);
        $data = [
            'name'              => mb_substr(trim($name), 0, 100),
            'note'              => $note !== null ? mb_substr($note, 0, 255) : null,
            'weekly_reset_dow'  => $dow,
            'weekly_reset_hour' => $hour,
            'usage_limit_pct'   => self::sanitizeLimit($limitPct),
        ];
        if ($newToken !== null && self::sanitizeToken($newToken) !== '') {
            $data['token_encrypted'] = self::enc()->encrypt(self::sanitizeToken($newToken));
        }
        Database::getInstance()->update('claude_licenses', $data, 'id = :id', ['id' => $id]);
        self::$rows = null;
    }

    public static function setActive(int $id, bool $active): void
    {
        Database::getInstance()->update('claude_licenses', ['is_active' => $active ? 1 : 0], 'id = :id', ['id' => $id]);
        self::$rows = null;
    }

    public static function delete(int $id): void
    {
        Database::getInstance()->delete('claude_licenses', 'id = ?', [$id]);
        self::$rows = null;
    }

    /** Token cru (descriptografado) de uma licença ATIVA; '' se inativa/ausente/corrompida. */
    public static function token(int $id): string
    {
        $r = self::rawRows()[$id] ?? null;
        if (!$r || !(int) $r['is_active']) {
            return '';
        }
        return self::decrypt($r);
    }

    /** Token cru independentemente de is_active (usado pelo botão "Testar" antes de ativar). */
    public static function rawToken(int $id): string
    {
        $r = self::rawRows()[$id] ?? null;
        return $r ? self::decrypt($r) : '';
    }

    private static function decrypt(array $row): string
    {
        try {
            // sanitize também na leitura: conserta licenças gravadas antes da normalização
            return self::sanitizeToken(self::enc()->decrypt((string) $row['token_encrypted']));
        } catch (\Throwable $e) {
            logger()->error('LicenseService: falha ao descriptografar licença ' . ($row['id'] ?? '?') . ': ' . $e->getMessage());
            return '';
        }
    }

    /** Mascara um token para exibição (ex.: sk-ant-oat…a1b2). Nunca mostra o token inteiro. */
    private static function mask(string $token): string
    {
        if ($token === '') {
            return '(inválido)';
        }
        $head = mb_substr($token, 0, 10);
        $tail = mb_substr($token, -4);
        return $head . '…' . $tail;
    }

    // ── Teto de uso (IDE / Dev Autônomo) ──────────────────────────────────

    /** Teto de uso (%) configurado pelo admin para a IDE/Dev Autônomo nesta licença, ou null. */
    public static function usageLimitPct(int $id): ?int
    {
        $r = self::rawRows()[$id] ?? null;
        $v = $r['usage_limit_pct'] ?? null;
        return self::sanitizeLimit($v !== null ? (int) $v : null);
    }

    /**
     * A licença está NO TETO de uso configurado? O teto vale para a IDE e o Dev Autônomo
     * (consumidores que o admin pode pausar); a API nunca passa por aqui — a folga entre o
     * teto e 100% fica reservada para ela. Sem teto configurado, nunca bloqueia.
     *
     * $probe=true consulta o uso com a sonda (TTL de 10 min — para o spawn do Dev Autônomo,
     * onde segundos de latência não doem); false lê só o cache + leitura de passagem do proxy
     * (para o resolver do proxy da IDE, que está no caminho de cada request).
     *
     * @return array{limit:int,pct:int,window:string,reset:?int}|null null = liberada
     */
    public static function usageBlocked(int $id, bool $probe = false): ?array
    {
        $limit = self::usageLimitPct($id);
        if ($limit === null) {
            return null;
        }
        $over = LicenseUsageService::overLimit($id, $limit, $probe);
        return $over === null ? null : $over + ['limit' => $limit];
    }

    private static function markUsed(int $id): void
    {
        try {
            Database::getInstance()->query('UPDATE claude_licenses SET last_used_at = NOW() WHERE id = ?', [$id]);
        } catch (\Throwable $e) { /* best-effort */ }
    }

    // ──────────────────────────────────────────────────────────────────────
    // Atribuição (qual licença cada escopo usa)
    // ──────────────────────────────────────────────────────────────────────

    public static function isScope(string $scope): bool
    {
        return isset(self::scopes()[$scope]);
    }

    public static function isUserScope(string $scope): bool
    {
        return in_array($scope, self::USER_SCOPES, true);
    }

    public static function label(string $scope): string
    {
        return self::scopes()[$scope] ?? $scope;
    }

    public static function globalKey(string $scope): string
    {
        return 'license_' . $scope;
    }

    public static function userKey(string $scope): string
    {
        return 'license_' . $scope;
    }

    /** Id da licença atribuída globalmente ao escopo, ou null. */
    public static function globalId(string $scope): ?int
    {
        $v = (string) (Setting::get(self::globalKey($scope), '') ?? '');
        return ctype_digit($v) ? (int) $v : null;
    }

    public static function setGlobal(string $scope, ?int $id): void
    {
        Setting::set(self::globalKey($scope), ($id && self::exists($id)) ? (string) $id : '');
    }

    /** Id da licença atribuída ao usuário no escopo (só ide|autonomous), ou null (herda global). */
    public static function userIdFor(int $userId, string $scope): ?int
    {
        if ($userId <= 0 || !self::isUserScope($scope)) {
            return null;
        }
        $v = UserPref::getRaw($userId, self::userKey($scope), '');
        return ctype_digit($v) ? (int) $v : null;
    }

    public static function setUser(int $userId, string $scope, ?int $id): void
    {
        if (!self::isUserScope($scope)) {
            return;
        }
        UserPref::putRaw($userId, self::userKey($scope), ($id && self::exists($id)) ? (string) $id : '');
    }

    // ── Escolha da própria conta pelo usuário (liberada por usuário, pelo admin) ──

    /** O usuário pode escolher a própria conta do Claude no perfil? Default: não. */
    public static function selfChoiceEnabled(int $userId): bool
    {
        return $userId > 0 && UserPref::enabled($userId, self::SELF_CHOICE_PREF, false);
    }

    public static function setSelfChoice(int $userId, bool $on): void
    {
        UserPref::set($userId, self::SELF_CHOICE_PREF, $on);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Resolução (usada pelos consumidores)
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Token do .env (legado). Usado APENAS pelo seed do primeiro acesso (ensureSeeded) e pelo
     * diagnóstico da tela de admin — NUNCA na resolução de licenças. Em produção a variável
     * CLAUDE_CODE_OAUTH_TOKEN foi removida do .env; isto retorna '' lá.
     */
    public static function envFallback(): string
    {
        return (string) env('CLAUDE_CODE_OAUTH_TOKEN', '');
    }

    /** Token efetivo para um usuário num escopo: override do usuário → global. Sem fallback. */
    public static function tokenForUser(int $userId, string $scope): string
    {
        if (self::isUserScope($scope)) {
            $uid = self::userIdFor($userId, $scope);
            if ($uid !== null) {
                $t = self::token($uid);
                if ($t !== '') {
                    self::markUsed($uid);
                    return $t;
                }
            }
        }
        return self::tokenForScope($scope);
    }

    /**
     * Id da licença ATIVA que um usuário usaria num escopo (override do usuário → global), ou null.
     * Diferente de tokenForUser: devolve o ID, não o token — usado pelo proxy da IDE para amarrar o
     * handle a uma licença sem o token cru sair do PHP. NÃO cai no .env (o .env não tem id).
     */
    public static function licenseIdForUser(int $userId, string $scope): ?int
    {
        if (self::isUserScope($scope)) {
            $uid = self::userIdFor($userId, $scope);
            if ($uid !== null && self::token($uid) !== '') {
                return $uid;
            }
        }
        $gid = self::globalId($scope);
        if ($gid !== null && self::token($gid) !== '') {
            return $gid;
        }
        return null;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Roteamento da API (feature global: off | failover | balance)
    // ──────────────────────────────────────────────────────────────────────

    /** Modos de roteamento da API (chave => rótulo). */
    public const API_MODES = [
        'off'      => 'Desligado (usa só a licença da API)',
        'failover' => 'Failover (principal + backups em ordem)',
        'balance'  => 'Balance (2 licenças dividindo a carga)',
    ];

    /** Modo de roteamento da API configurado no painel: off | failover | balance. */
    public static function apiMode(): string
    {
        $m = (string) (Setting::get('api_license_mode', 'off') ?? 'off');
        return isset(self::API_MODES[$m]) ? $m : 'off';
    }

    public static function setApiMode(string $mode): void
    {
        Setting::set('api_license_mode', isset(self::API_MODES[$mode]) ? $mode : 'off');
    }

    /** @return int[] ids de licenças de uma lista JSON em app_settings (só ids existentes, sem duplicata). */
    private static function idList(string $key): array
    {
        return self::idsFromJson((string) (Setting::get($key, '') ?? ''));
    }

    /** @return int[] parse de uma lista JSON de ids (só existentes, sem duplicata). */
    private static function idsFromJson(string $raw): array
    {
        $arr = json_decode($raw, true);
        if (!is_array($arr)) {
            return [];
        }
        $out = [];
        foreach ($arr as $v) {
            $id = (int) $v;
            if ($id > 0 && self::exists($id) && !in_array($id, $out, true)) {
                $out[] = $id;
            }
        }
        return $out;
    }

    private static function setIdList(string $key, array $ids): void
    {
        $clean = [];
        foreach ($ids as $v) {
            $id = (int) $v;
            if ($id > 0 && self::exists($id) && !in_array($id, $clean, true)) {
                $clean[] = $id;
            }
        }
        Setting::set($key, json_encode($clean));
    }

    /** @return int[] backups do modo failover, na ordem configurada (valem após a principal da requisição). */
    public static function apiFailoverBackups(): array
    {
        return self::idList('api_license_failover');
    }

    public static function setApiFailoverBackups(array $ids): void
    {
        self::setIdList('api_license_failover', $ids);
    }

    /** @return int[] o par do modo balance (2 licenças escolhidas no painel). */
    public static function apiBalancePair(): array
    {
        return array_slice(self::idList('api_license_balance'), 0, 2);
    }

    public static function setApiBalancePair(array $ids): void
    {
        self::setIdList('api_license_balance', array_slice(array_values($ids), 0, 2));
    }

    /**
     * Cadeia de licenças que a API pode usar nesta requisição, NA ORDEM de tentativa. Contém
     * APENAS licenças que o admin escolheu no painel — sem failover cego e sem .env (vetor do
     * vazamento de 07/2026). Vazia = API sem licença configurada (o gateway recusa com erro claro).
     *
     * PRINCIPAL da requisição = a licença própria do token de API (gw_tokens.license_id), se
     * houver; senão a licença global da API. O modo global então define o resto:
     *   - off      → [principal];
     *   - failover → [principal, backups na ordem configurada];
     *   - balance  → token COM licença própria usa só ela ([principal]); quem herda usa o par
     *                escolhido: [sorteada, parceira].
     *
     * Licenças inativas/ilegíveis são puladas. NÃO marca uso; quem atender chama touch().
     *
     * @return array<int,array{id:int,name:string,token:string}>
     */
    public static function apiFailoverChain(?int $gwTokenId): array
    {
        $chain = [];
        $seen = [];
        $push = static function (?int $id) use (&$chain, &$seen): void {
            if ($id === null || $id <= 0 || isset($seen[$id])) {
                return;
            }
            $seen[$id] = true;
            $r = self::rawRows()[$id] ?? null;
            if (!$r || !(int) $r['is_active']) {
                return;
            }
            $token = self::decrypt($r);
            if ($token === '') {
                return;
            }
            $chain[] = ['id' => $id, 'name' => (string) $r['name'], 'token' => $token];
        };

        // Licença própria do token de API (escolha explícita no painel) → é o principal dele.
        $own = null;
        if ($gwTokenId !== null && $gwTokenId > 0) {
            try {
                $row = Database::getInstance()->fetch('SELECT license_id FROM gw_tokens WHERE id = ?', [$gwTokenId]);
                $own = ($row && $row['license_id'] !== null && (int) $row['license_id'] > 0)
                    ? (int) $row['license_id'] : null;
            } catch (\Throwable $e) { /* sem licença própria → herda o global */ }
        }

        $mode = self::apiMode();
        if ($mode === 'balance') {
            if ($own !== null) {
                $push($own);
                return $chain;
            }
            $pair = self::apiBalancePair();
            if (count($pair) === 2 && random_int(0, 1) === 1) {
                $pair = [$pair[1], $pair[0]];
            }
            foreach ($pair as $id) {
                $push($id);
            }
            return $chain;
        }

        $push($own ?? self::globalId('api'));
        if ($mode === 'failover') {
            foreach (self::apiFailoverBackups() as $id) {
                $push($id);
            }
        }
        return $chain;
    }

    /** Marca uso (last_used_at) de uma licença — usado pelo failover do gateway ao atendê-la. */
    public static function touch(int $id): void
    {
        self::markUsed($id);
    }

    /** Token efetivo global de um escopo: a licença global, ou '' se não houver. Sem fallback. */
    public static function tokenForScope(string $scope): string
    {
        $gid = self::globalId($scope);
        if ($gid !== null) {
            $t = self::token($gid);
            if ($t !== '') {
                self::markUsed($gid);
                return $t;
            }
        }
        return '';
    }

    // ──────────────────────────────────────────────────────────────────────
    // Roteamento do Dev Autônomo (feature global: off | balance)
    //
    // O Dev Autônomo sobe VÁRIOS agentes (Claude Code) ao mesmo tempo; numa licença
    // só, as ondas paralelas estouram o limite de sessão da conta ("hit your session
    // limit"). No modo balance o admin marca N licenças e cada AGENTE é sorteado no
    // SPAWN para a menos carregada — sticky: o agente inteiro conversa pela mesma
    // licença (preserva o prompt-cache, que é por conta). Trocar de licença POR
    // REQUISIÇÃO jogaria o cache fora e gastaria MAIS cota, não menos.
    //
    // Licença que atinge o limite entra em COOLDOWN (até o horário de reset que a
    // própria mensagem informa) e sai do sorteio até lá. O cooldown vive em
    // app_settings e é lido DIRETO do banco (freshSetting): o worker do Maestro é um
    // processo de longa duração e o cache por request do Setting não enxergaria o que
    // outros processos gravaram.
    // ──────────────────────────────────────────────────────────────────────

    /** Modos de roteamento do Dev Autônomo (chave => rótulo). */
    public const AUTONOMOUS_MODES = [
        'off'     => 'Desligado (todos os agentes usam a licença do escopo)',
        'balance' => 'Balance (agentes distribuídos entre as licenças marcadas)',
    ];

    /**
     * Lê uma configuração DIRETO do banco, sem o cache por request do Setting. Necessário
     * para modo/pool/cooldowns valerem imediatamente dentro do worker de longa duração.
     */
    private static function freshSetting(string $key): ?string
    {
        try {
            $row = Database::getInstance()->fetch('SELECT svalue FROM app_settings WHERE skey = ?', [$key]);
            $v = $row['svalue'] ?? null;
            return ($v === null || $v === '') ? null : (string) $v;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Modo de roteamento do Dev Autônomo configurado no painel: off | balance. */
    public static function autonomousMode(): string
    {
        $m = (string) (self::freshSetting('autonomous_license_mode') ?? 'off');
        return isset(self::AUTONOMOUS_MODES[$m]) ? $m : 'off';
    }

    public static function setAutonomousMode(string $mode): void
    {
        Setting::set('autonomous_license_mode', isset(self::AUTONOMOUS_MODES[$mode]) ? $mode : 'off');
    }

    /** @return int[] licenças marcadas para o balance do Dev Autônomo (N, sem ordem). */
    public static function autonomousPool(): array
    {
        return self::idsFromJson((string) (self::freshSetting('autonomous_license_pool') ?? ''));
    }

    public static function setAutonomousPool(array $ids): void
    {
        self::setIdList('autonomous_license_pool', $ids);
    }

    // ── Cooldown (licença esgotada até o reset) ───────────────────────────

    /** @return array<int,int> id => epoch até quando a licença está esgotada (só cooldowns futuros). */
    public static function cooldowns(): array
    {
        $arr = json_decode((string) (self::freshSetting('license_cooldowns') ?? ''), true);
        $out = [];
        if (is_array($arr)) {
            $now = time();
            foreach ($arr as $k => $v) {
                $id = (int) $k;
                $until = (int) $v;
                if ($id > 0 && $until > $now) {
                    $out[$id] = $until;
                }
            }
        }
        return $out;
    }

    /**
     * Marca uma licença como esgotada até $untilEpoch (fallback: 30 min). Nunca ENCURTA um
     * cooldown já vigente. Escrita read-modify-write sem lock: uma corrida entre dois workers
     * pode perder uma marcação, o custo é só uma retentativa na licença esgotada.
     */
    public static function startCooldown(int $id, ?int $untilEpoch = null): void
    {
        if ($id <= 0) {
            return;
        }
        $until = ($untilEpoch !== null && $untilEpoch > time()) ? $untilEpoch : time() + 1800;
        $cd = self::cooldowns();
        $cd[$id] = max($cd[$id] ?? 0, $until);
        Setting::set('license_cooldowns', json_encode($cd));
    }

    public static function inCooldown(int $id): bool
    {
        return isset(self::cooldowns()[$id]);
    }

    /**
     * Escolhe a licença de UM agente do Dev Autônomo no modo balance. O chamador informa
     * quantos agentes dele estão AGORA em cada licença ($inFlight) e a menos carregada
     * ganha (empate = sorteio). Licenças em cooldown ficam fora; se TODAS estiverem
     * esgotadas, o sorteio volta a incluí-las (parar tudo é pior: a que liberar primeiro
     * atende). Retorna null quando o balance NÃO se aplica e o caminho normal vale:
     * modo off, pool com menos de 2 licenças utilizáveis, ou usuário com licença própria
     * de Dev Autônomo (override fica fora do balance, como no balance da API).
     *
     * @param array<int,int> $inFlight id da licença => agentes ativos no processo chamador
     * @return array{id:int,name:string,token:string}|null
     */
    public static function pickAutonomous(int $userId, array $inFlight = []): ?array
    {
        if (self::autonomousMode() !== 'balance') {
            return null;
        }
        // Worker de longa duração: invalida o cache das licenças a cada sorteio (1 SELECT por
        // spawn de agente), senão ativar/desativar/trocar token no painel só valeria após restart.
        self::$rows = null;
        if ($userId > 0) {
            $own = self::userIdFor($userId, 'autonomous');
            if ($own !== null && self::token($own) !== '') {
                return null;
            }
        }

        $pool = [];
        foreach (self::autonomousPool() as $id) {
            $r = self::rawRows()[$id] ?? null;
            if (!$r || !(int) $r['is_active']) {
                continue;
            }
            $t = self::decrypt($r);
            if ($t === '') {
                continue;
            }
            $pool[$id] = ['id' => $id, 'name' => (string) $r['name'], 'token' => $t];
        }
        if (count($pool) < 2) {
            return null;
        }

        $usable = array_diff_key($pool, self::cooldowns());
        if ($usable === []) {
            $usable = $pool;
        }

        // Teto de uso configurado pelo admin: licença no teto sai do sorteio SEM o fallback do
        // cooldown (o teto é duro — a folga é da API). Se todas as marcadas estiverem no teto,
        // cai no caminho normal (null) e o gate do spawn barra com mensagem clara.
        foreach (array_keys($usable) as $id) {
            if (self::usageBlocked($id, true) !== null) {
                unset($usable[$id]);
            }
        }
        if ($usable === []) {
            return null;
        }

        $bestLoad = PHP_INT_MAX;
        $ties = [];
        foreach ($usable as $id => $lic) {
            $load = (int) ($inFlight[$id] ?? 0);
            if ($load < $bestLoad) {
                $bestLoad = $load;
                $ties = [$lic];
            } elseif ($load === $bestLoad) {
                $ties[] = $lic;
            }
        }
        $pick = $ties[random_int(0, count($ties) - 1)];
        self::markUsed($pick['id']);
        return $pick;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Seed
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Importa a licença do .env como "Licença atual" no primeiro uso (tabela vazia) e a define como
     * padrão global de todos os escopos. Idempotente: não faz nada se já houver alguma licença.
     */
    public static function ensureSeeded(): void
    {
        try {
            if (!empty(self::rawRows())) {
                return;
            }
            $tok = self::envFallback();
            if ($tok === '') {
                return;
            }
            $id = self::create('Licença atual', $tok, 'Importada do .env');
            foreach (array_keys(self::scopes()) as $sc) {
                self::setGlobal($sc, $id);
            }
        } catch (\Throwable $e) {
            logger()->error('LicenseService::ensureSeeded: ' . $e->getMessage());
        }
    }
}
