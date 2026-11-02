<?php

declare(strict_types=1);

namespace Ravi\Services;

use Ravi\Core\Database;
use Ravi\Core\Encryption;
use Ravi\Models\Setting;
use Ravi\Models\UserPref;

/**
 * Gerência de LICENÇAS do ChatGPT (assinaturas Plus/Pro/Business/Enterprise) para o Codex na IDE —
 * a extensão oficial "Codex – OpenAI's coding agent" (openai.chatgpt) e o CLI `codex` do terminal.
 *
 * O que é uma "licença": o conteúdo do `~/.codex/auth.json` de uma conta ChatGPT (tokens OAuth do
 * "Sign in with ChatGPT", gerado por `codex login` numa máquina com navegador). Copiar esse arquivo
 * para uma máquina headless/container é o caminho documentado pela OpenAI. Fica CRIPTOGRAFADO em
 * `chatgpt_licenses` (AES-256-GCM via Encryption/APP_KEY), como as licenças do Claude.
 *
 * Escopo: só a IDE (o Dev Autônomo e a API continuam no Claude). Resolução, espelhando o
 * LicenseService: override do USUÁRIO (user_prefs chatgpt_license_ide) → padrão GLOBAL
 * (app_settings chatgpt_license_ide). Sem licença = extensão deslogada (o usuário pode logar à mão).
 *
 * RENOVAÇÃO (o ponto delicado): o Codex renova os tokens sozinho quando `last_refresh` passa de ~8
 * dias (ou num 401) e grava o auth.json NOVO — e o refresh token antigo deixa de valer. Se a cópia
 * do banco ficasse parada, a licença morreria na 1ª renovação feita dentro de uma IDE. Por isso:
 *   - o auth.json entra no container por `docker exec` (nunca pelo env → não aparece no inspect),
 *     junto de um MARCADOR (.ravi-license) com o id da licença;
 *   - o scheduler (1/min) faz reconcile(): puxa de volta o auth.json de cada IDE rodando quando o
 *     `last_refresh` dela é mais novo que o do banco, e empurra o do banco para as IDEs que estão
 *     com uma cópia mais velha (o Codex recarrega o arquivo antes de renovar). Assim várias IDEs
 *     com a mesma licença não se derrubam na renovação.
 * O auth.json NUNCA é exibido (só e-mail/plano extraídos do id_token e um hint mascarado).
 */
class ChatgptLicenseService
{
    /** Chave do padrão global (app_settings) e do override por usuário (user_prefs). */
    public const KEY_IDE = 'chatgpt_license_ide';

    /** Caminhos dentro do container da IDE (HOME=/home/sandbox mesmo rodando como root). */
    public const CONTAINER_DIR  = '/home/sandbox/.codex';
    public const CONTAINER_AUTH = '/home/sandbox/.codex/auth.json';
    public const CONTAINER_MARK = '/home/sandbox/.codex/.ravi-license';

    /** @var array<int,array<string,mixed>>|null cache das linhas cruas por request */
    private static ?array $rows = null;

    // ──────────────────────────────────────────────────────────────────────
    // Armazenamento / CRUD
    // ──────────────────────────────────────────────────────────────────────

    private static function enc(): Encryption
    {
        return new Encryption();
    }

    /** @return array<int,array<string,mixed>> linhas cruas indexadas por id */
    private static function rawRows(): array
    {
        if (self::$rows !== null) {
            return self::$rows;
        }
        self::$rows = [];
        try {
            foreach (Database::getInstance()->fetchAll('SELECT * FROM chatgpt_licenses ORDER BY id ASC') as $r) {
                self::$rows[(int) $r['id']] = $r;
            }
        } catch (\Throwable $e) {
            // tabela ausente (migration 032 não aplicada) → sem licenças
        }
        return self::$rows;
    }

    /** Invalida o cache por request (processos longos: scheduler). */
    public static function refresh(): void
    {
        self::$rows = null;
    }

    /** Lista para exibição (sem tokens; só e-mail/plano e um hint mascarado). */
    public static function all(): array
    {
        $out = [];
        foreach (self::rawRows() as $r) {
            $out[] = [
                'id'            => (int) $r['id'],
                'name'          => (string) $r['name'],
                'is_active'     => (bool) $r['is_active'],
                'note'          => (string) ($r['note'] ?? ''),
                'account_email' => (string) ($r['account_email'] ?? ''),
                'plan_type'     => (string) ($r['plan_type'] ?? ''),
                'auth_mode'     => (string) ($r['auth_mode'] ?? ''),
                'hint'          => self::mask(self::decrypt($r)),
                'last_refresh'  => $r['last_refresh'] ?? null,
                'last_used_at'  => $r['last_used_at'] ?? null,
                'created_at'    => $r['created_at'] ?? null,
            ];
        }
        return $out;
    }

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

    /** Quantas licenças ATIVAS existem (a UI só oferece a escolha por usuário quando há ≥ 1). */
    public static function activeCount(): int
    {
        $n = 0;
        foreach (self::rawRows() as $r) {
            if ((int) $r['is_active']) {
                $n++;
            }
        }
        return $n;
    }

    public static function hasActive(): bool
    {
        return self::activeCount() > 0;
    }

    /**
     * Valida e normaliza um auth.json colado pelo admin. Aceita o formato do "Sign in with ChatGPT"
     * (tokens.access_token/refresh_token/id_token/account_id + last_refresh) e o de API key
     * (OPENAI_API_KEY). Retorna null se não for um auth.json reconhecível.
     *
     * @return array{json:string,mode:string,email:string,plan:string,account_id:string,last_refresh:?string}|null
     */
    public static function parseAuth(string $raw): ?array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }
        $tokens = is_array($data['tokens'] ?? null) ? $data['tokens'] : [];
        $access = trim((string) ($tokens['access_token'] ?? ''));
        $apiKey = trim((string) ($data['OPENAI_API_KEY'] ?? ''));
        if ($access === '' && $apiKey === '') {
            return null;
        }
        $mode = (string) ($data['auth_mode'] ?? ($access !== '' ? 'chatgpt' : 'apikey'));
        $claims = $access !== '' ? self::jwtClaims((string) ($tokens['id_token'] ?? '')) : [];
        if ($claims === [] && $access !== '') {
            $claims = self::jwtClaims($access);
        }
        $profile = is_array($claims['https://api.openai.com/profile'] ?? null) ? $claims['https://api.openai.com/profile'] : [];
        $auth    = is_array($claims['https://api.openai.com/auth'] ?? null) ? $claims['https://api.openai.com/auth'] : [];
        $email   = (string) ($profile['email'] ?? ($claims['email'] ?? ''));
        $plan    = (string) ($auth['chatgpt_plan_type'] ?? '');
        $account = (string) ($tokens['account_id'] ?? ($auth['chatgpt_account_id'] ?? ''));
        return [
            'json'         => json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'mode'         => $mode,
            'email'        => mb_substr($email, 0, 255),
            'plan'         => mb_substr($plan, 0, 50),
            'account_id'   => $account,
            'last_refresh' => self::toDbDate((string) ($data['last_refresh'] ?? '')),
        ];
    }

    /** Claims (payload) de um JWT, sem verificar assinatura — só para exibir e-mail/plano. */
    private static function jwtClaims(string $jwt): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) < 2) {
            return [];
        }
        $payload = base64_decode(strtr($parts[1], '-_', '+/') . str_repeat('=', (4 - strlen($parts[1]) % 4) % 4), true);
        $claims = $payload !== false ? json_decode($payload, true) : null;
        return is_array($claims) ? $claims : [];
    }

    /** RFC3339 do auth.json ("2026-09-01T10:20:30.123456Z") → "Y-m-d H:i:s" UTC, ou null. */
    private static function toDbDate(string $iso): ?string
    {
        if ($iso === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable($iso))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Cria uma licença a partir de um auth.json válido (use parseAuth antes). Retorna o id. */
    public static function create(string $name, array $parsed, ?string $note = null): int
    {
        $id = (int) Database::getInstance()->insert('chatgpt_licenses', [
            'name'           => mb_substr(trim($name), 0, 100),
            'auth_encrypted' => self::enc()->encrypt($parsed['json']),
            'is_active'      => 1,
            'note'           => $note !== null ? mb_substr($note, 0, 255) : null,
            'account_email'  => $parsed['email'] !== '' ? $parsed['email'] : null,
            'plan_type'      => $parsed['plan'] !== '' ? $parsed['plan'] : null,
            'auth_mode'      => $parsed['mode'],
            'last_refresh'   => $parsed['last_refresh'],
        ]);
        self::$rows = null;
        return $id;
    }

    /** Renomeia / atualiza nota (e substitui o auth.json, se um novo válido for enviado). */
    public static function update(int $id, string $name, ?string $note, ?array $parsed = null): void
    {
        $data = ['name' => mb_substr(trim($name), 0, 100), 'note' => $note !== null ? mb_substr($note, 0, 255) : null];
        if ($parsed !== null) {
            $data['auth_encrypted'] = self::enc()->encrypt($parsed['json']);
            $data['account_email']  = $parsed['email'] !== '' ? $parsed['email'] : null;
            $data['plan_type']      = $parsed['plan'] !== '' ? $parsed['plan'] : null;
            $data['auth_mode']      = $parsed['mode'];
            $data['last_refresh']   = $parsed['last_refresh'];
        }
        Database::getInstance()->update('chatgpt_licenses', $data, 'id = :id', ['id' => $id]);
        self::$rows = null;
    }

    public static function setActive(int $id, bool $active): void
    {
        Database::getInstance()->update('chatgpt_licenses', ['is_active' => $active ? 1 : 0], 'id = :id', ['id' => $id]);
        self::$rows = null;
    }

    public static function delete(int $id): void
    {
        Database::getInstance()->delete('chatgpt_licenses', 'id = ?', [$id]);
        self::$rows = null;
    }

    /** auth.json (descriptografado) de uma licença ATIVA; '' se inativa/ausente/corrompida. */
    public static function auth(int $id): string
    {
        $r = self::rawRows()[$id] ?? null;
        if (!$r || !(int) $r['is_active']) {
            return '';
        }
        return self::decrypt($r);
    }

    /** auth.json independentemente de is_active (botão "Testar"). */
    public static function rawAuth(int $id): string
    {
        $r = self::rawRows()[$id] ?? null;
        return $r ? self::decrypt($r) : '';
    }

    private static function decrypt(array $row): string
    {
        try {
            return trim(self::enc()->decrypt((string) $row['auth_encrypted']));
        } catch (\Throwable $e) {
            logger()->error('ChatgptLicenseService: falha ao descriptografar licença ' . ($row['id'] ?? '?') . ': ' . $e->getMessage());
            return '';
        }
    }

    /** Hint mascarado do access token / API key (nunca o valor inteiro). */
    private static function mask(string $json): string
    {
        $p = self::parseAuth($json);
        if ($p === null) {
            return '(inválido)';
        }
        $data = json_decode($json, true);
        $tok = (string) (($data['tokens']['access_token'] ?? '') ?: ($data['OPENAI_API_KEY'] ?? ''));
        if ($tok === '') {
            return '(inválido)';
        }
        return mb_substr($tok, 0, 8) . '…' . mb_substr($tok, -4);
    }

    private static function markUsed(int $id): void
    {
        try {
            Database::getInstance()->query('UPDATE chatgpt_licenses SET last_used_at = NOW() WHERE id = ?', [$id]);
        } catch (\Throwable $e) { /* best-effort */ }
    }

    /**
     * Guarda um auth.json RENOVADO pelo Codex (vindo de uma IDE ou do teste), só se for da MESMA
     * conta e com `last_refresh` mais novo que o do banco. Retorna true se gravou.
     */
    public static function storeRefreshed(int $id, string $json): bool
    {
        $r = self::rawRows()[$id] ?? null;
        if (!$r) {
            return false;
        }
        $new = self::parseAuth($json);
        if ($new === null || $new['last_refresh'] === null) {
            return false;
        }
        $cur = self::parseAuth(self::decrypt($r));
        if ($cur !== null && $cur['account_id'] !== '' && $new['account_id'] !== $cur['account_id']) {
            return false; // outra conta (alguém logou à mão dentro da IDE) → não contamina a licença
        }
        $curRefresh = (string) ($r['last_refresh'] ?? '');
        if ($curRefresh !== '' && strcmp($new['last_refresh'], $curRefresh) <= 0) {
            return false;
        }
        Database::getInstance()->update('chatgpt_licenses', [
            'auth_encrypted' => self::enc()->encrypt($new['json']),
            'account_email'  => $new['email'] !== '' ? $new['email'] : ($r['account_email'] ?? null),
            'plan_type'      => $new['plan'] !== '' ? $new['plan'] : ($r['plan_type'] ?? null),
            'auth_mode'      => $new['mode'],
            'last_refresh'   => $new['last_refresh'],
        ], 'id = :id', ['id' => $id]);
        self::$rows = null;
        logger()->info("ChatgptLicenseService: auth.json renovado guardado para a licença #{$id} (last_refresh {$new['last_refresh']})");
        return true;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Atribuição (qual licença a IDE de cada usuário usa)
    // ──────────────────────────────────────────────────────────────────────

    /** Id da licença global da IDE, ou null. */
    public static function globalId(): ?int
    {
        $v = (string) (Setting::get(self::KEY_IDE, '') ?? '');
        return ctype_digit($v) ? (int) $v : null;
    }

    public static function setGlobal(?int $id): void
    {
        Setting::set(self::KEY_IDE, ($id && self::exists($id)) ? (string) $id : '');
    }

    /** Id da licença do usuário na IDE, ou null (herda o global). */
    public static function userIdFor(int $userId): ?int
    {
        if ($userId <= 0) {
            return null;
        }
        $v = UserPref::getRaw($userId, self::KEY_IDE, '');
        return ctype_digit($v) ? (int) $v : null;
    }

    public static function setUser(int $userId, ?int $id): void
    {
        UserPref::putRaw($userId, self::KEY_IDE, ($id && self::exists($id)) ? (string) $id : '');
    }

    /** Id da licença ATIVA que a IDE deste usuário usa (override → global), ou null. */
    public static function licenseIdForUser(int $userId): ?int
    {
        $own = self::userIdFor($userId);
        if ($own !== null && self::auth($own) !== '') {
            return $own;
        }
        $gid = self::globalId();
        if ($gid !== null && self::auth($gid) !== '') {
            return $gid;
        }
        return null;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Containers da IDE (o auth.json entra/sai por docker exec, nunca pelo env)
    // ──────────────────────────────────────────────────────────────────────

    /** Roda um comando com stdin, com timeout. @return array{rc:int,out:string} */
    private static function runWithStdin(string $cmd, string $stdin, int $timeout): array
    {
        $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $p = @proc_open('timeout ' . $timeout . ' ' . $cmd, $desc, $pipes);
        if (!is_resource($p)) {
            return ['rc' => 1, 'out' => 'proc_open falhou'];
        }
        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $rc = proc_close($p);
        return ['rc' => $rc, 'out' => $out . ($err !== '' ? "\n" . $err : '')];
    }

    /**
     * Coloca (ou remove) a licença numa IDE RODANDO. $licenseId null = remove o auth.json que NÓS
     * pusemos (só se o marcador existir — um login manual do usuário fica intacto).
     */
    public static function pushToContainer(string $container, ?int $licenseId): bool
    {
        if ($licenseId === null) {
            $sh = 'if [ -f ' . self::CONTAINER_MARK . ' ]; then rm -f ' . self::CONTAINER_AUTH . ' ' . self::CONTAINER_MARK . '; fi';
            @exec('timeout 8 docker exec ' . escapeshellarg($container) . ' sh -c ' . escapeshellarg($sh) . ' >/dev/null 2>&1', $o, $rc);
            return $rc === 0;
        }
        $r = self::rawRows()[$licenseId] ?? null;
        $json = $r ? self::decrypt($r) : '';
        if ($json === '') {
            return false;
        }
        $mark = $licenseId . "\n" . (string) ($r['last_refresh'] ?? '') . "\n";
        // Escrita atômica (tmp + mv) para o Codex nunca ler um arquivo pela metade.
        $sh = 'umask 077 && mkdir -p ' . self::CONTAINER_DIR
            . ' && cat > ' . self::CONTAINER_AUTH . '.tmp && mv -f ' . self::CONTAINER_AUTH . '.tmp ' . self::CONTAINER_AUTH
            . ' && printf %s ' . escapeshellarg($mark) . ' > ' . self::CONTAINER_MARK;
        $res = self::runWithStdin('docker exec -i ' . escapeshellarg($container) . ' sh -c ' . escapeshellarg($sh), $json, 10);
        if ($res['rc'] === 0) {
            self::markUsed($licenseId);
            return true;
        }
        logger()->warning("ChatgptLicenseService: falha ao injetar licença #{$licenseId} em {$container}: " . trim($res['out']));
        return false;
    }

    /**
     * Lê o estado do Codex numa IDE rodando: marcador (id da licença que pusemos) e o auth.json atual.
     * @return array{mark_id:?int, mark_refresh:string, auth:string}|null null = container inacessível
     */
    private static function readContainer(string $container): ?array
    {
        $sh = 'cat ' . self::CONTAINER_MARK . ' 2>/dev/null; printf "\n__RAVI_SEP__\n"; cat ' . self::CONTAINER_AUTH . ' 2>/dev/null';
        $out = [];
        @exec('timeout 8 docker exec ' . escapeshellarg($container) . ' sh -c ' . escapeshellarg($sh) . ' 2>/dev/null', $out, $rc);
        if ($rc !== 0) {
            return null;
        }
        $text = implode("\n", $out);
        $pos = strpos($text, "__RAVI_SEP__");
        if ($pos === false) {
            return null;
        }
        $markLines = preg_split('/\R/', trim(substr($text, 0, $pos))) ?: [];
        $auth = trim(substr($text, $pos + strlen("__RAVI_SEP__")));
        $markId = ctype_digit((string) ($markLines[0] ?? '')) ? (int) $markLines[0] : null;
        return ['mark_id' => $markId, 'mark_refresh' => (string) ($markLines[1] ?? ''), 'auth' => $auth];
    }

    /**
     * Reconcile do scheduler (1/min): para cada IDE RODANDO, puxa o auth.json renovado pelo Codex
     * (banco fica com o refresh token válido) e empurra o do banco quando a IDE está com cópia mais
     * velha ou com a licença errada. Só containers em estado running (exec num pausado pendura).
     * @return array{checked:int,pulled:int,pushed:int,removed:int}
     */
    public static function reconcile(): array
    {
        $stats = ['checked' => 0, 'pulled' => 0, 'pushed' => 0, 'removed' => 0];
        self::$rows = null;
        if (self::rawRows() === []) {
            return $stats; // sem licenças cadastradas → nada a sincronizar
        }
        $db = Database::getInstance();
        $sessions = $db->fetchAll("SELECT container, user_id FROM ide_sessions WHERE status = 'running'");
        if ($sessions === []) {
            return $stats;
        }
        // Estado real dos containers numa chamada só (pausado tem Running=true → olhar o Status).
        $names = array_map(static fn($s) => (string) $s['container'], $sessions);
        $out = [];
        @exec('timeout 6 docker inspect -f "{{.Name}} {{.State.Status}}" ' . implode(' ', array_map('escapeshellarg', $names)) . ' 2>/dev/null', $out);
        $running = [];
        foreach ($out as $line) {
            [$n, $st] = array_pad(explode(' ', trim($line), 2), 2, '');
            if ($st === 'running') {
                $running[ltrim($n, '/')] = true;
            }
        }
        foreach ($sessions as $s) {
            $container = (string) $s['container'];
            if (!isset($running[$container])) {
                continue;
            }
            $stats['checked']++;
            $want = self::licenseIdForUser((int) $s['user_id']);
            $state = self::readContainer($container);
            if ($state === null) {
                continue;
            }
            if ($want === null) {
                if ($state['mark_id'] !== null) {
                    self::pushToContainer($container, null);
                    $stats['removed']++;
                }
                continue;
            }
            $row = self::rawRows()[$want] ?? null;
            $dbRefresh = (string) ($row['last_refresh'] ?? '');
            if ($state['mark_id'] === $want && $state['auth'] !== '') {
                // Mesma licença: quem está mais novo manda. IDE renovou → guarda no banco.
                if (self::storeRefreshed($want, $state['auth'])) {
                    $stats['pulled']++;
                    // Atualiza o marcador para o novo last_refresh (evita re-push no próximo minuto).
                    $row = self::rawRows()[$want] ?? $row;
                    $dbRefresh = (string) ($row['last_refresh'] ?? '');
                    $mark = $want . "\n" . $dbRefresh . "\n";
                    @exec('timeout 8 docker exec ' . escapeshellarg($container) . ' sh -c ' . escapeshellarg('printf %s ' . escapeshellarg($mark) . ' > ' . self::CONTAINER_MARK) . ' >/dev/null 2>&1');
                    continue;
                }
                if ($state['mark_refresh'] === $dbRefresh) {
                    continue; // em dia
                }
            }
            // Licença diferente da marcada, sem marcador, ou banco mais novo → empurra a do banco.
            if (self::pushToContainer($container, $want)) {
                $stats['pushed']++;
            }
        }
        return $stats;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Teste (roda um `codex exec` efêmero com a licença)
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Testa se a licença autentica de verdade: sobe um container efêmero da imagem da IDE (que tem
     * o `codex`), injeta o auth.json e pede "OK". Se o Codex renovar os tokens no caminho, guarda.
     * @return array{ok:bool,detail:string}
     */
    public static function test(int $id): array
    {
        $json = self::rawAuth($id);
        if ($json === '') {
            return ['ok' => false, 'detail' => 'Licença não encontrada ou auth.json ilegível.'];
        }
        $img = (string) env('IDE_IMAGE', 'ravi-ide:latest');
        $inner = 'command -v codex >/dev/null 2>&1 || { echo "__RAVI_NOCODEX__"; exit 3; }; '
               . 'umask 077; mkdir -p "$HOME/.codex" && cat > "$HOME/.codex/auth.json" && cd /tmp && '
               . 'codex exec --skip-git-repo-check --ephemeral -s read-only -c cli_auth_credentials_store="file" '
               . escapeshellarg('Responda apenas com a palavra: OK') . ' > /tmp/codex-out.txt 2>&1; rc=$?; '
               . 'tail -c 900 /tmp/codex-out.txt; printf "\n__RAVI_RC__%s\n__RAVI_AUTH__\n" "$rc"; cat "$HOME/.codex/auth.json"';
        $cmd = 'docker run --rm -i --entrypoint bash'
             . ' -e DISABLE_AUTOUPDATER=1'
             . ' ' . escapeshellarg($img)
             . ' -lc ' . escapeshellarg($inner) . ' 2>&1';
        $res = self::runWithStdin($cmd, $json, 110);
        $text = $res['out'];
        if (str_contains($text, '__RAVI_NOCODEX__')) {
            return ['ok' => false, 'detail' => 'A imagem da IDE ainda não tem o Codex (rebuild da ravi-ide pendente).'];
        }
        $authPos = strpos($text, '__RAVI_AUTH__');
        $body = $authPos !== false ? substr($text, 0, $authPos) : $text;
        $rc = preg_match('/__RAVI_RC__(\d+)/', $body, $m) ? (int) $m[1] : ($res['rc'] ?: 1);
        $body = trim((string) preg_replace('/__RAVI_RC__\d+/', '', $body));
        if ($authPos !== false) {
            $newAuth = trim(substr($text, $authPos + strlen('__RAVI_AUTH__')));
            if ($newAuth !== '' && self::storeRefreshed($id, $newAuth)) {
                $body .= "\n(tokens renovados pelo Codex durante o teste; guardados)";
            }
        }
        $lc = strtolower($body);
        $authErr = str_contains($lc, '401') || str_contains($lc, 'unauthorized') || str_contains($lc, 'not logged in')
                 || str_contains($lc, 'sign in again') || str_contains($lc, 'invalid');
        // Últimas linhas do codex exec = resposta do modelo. Sucesso = saiu 0, sem erro de auth, e "OK" na resposta.
        $lines = array_values(array_filter(array_map('trim', explode("\n", $body)), static fn($l) => $l !== ''));
        $reply = $lines !== [] ? end($lines) : '';
        if ($rc !== 0 || $authErr || stripos($reply, 'OK') === false) {
            $detail = $body !== '' ? mb_substr($body, -600) : 'sem resposta (timeout ou erro de execução)';
            return ['ok' => false, 'detail' => $detail];
        }
        self::markUsed($id);
        return ['ok' => true, 'detail' => mb_substr($reply, 0, 120)];
    }
}
