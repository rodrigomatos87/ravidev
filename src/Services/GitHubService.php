<?php

declare(strict_types=1);

namespace Ravi\Services;

/**
 * Integração com a API REST do GitHub (OAuth App clássico).
 *
 * Responsável SÓ por falar com o GitHub (OAuth + API). O trabalho de git local
 * (clone/commit/push no workspace do host) fica no GitSyncService. O token nunca
 * entra em container — todo uso acontece no host (app/worker).
 */
class GitHubService
{
    private string $clientId;
    private string $clientSecret;
    private string $scope;
    private string $redirectUri;

    public function __construct()
    {
        $this->clientId     = (string) config('github.client_id', '');
        $this->clientSecret = (string) config('github.client_secret', '');
        $this->scope        = (string) config('github.scope', 'repo');
        $this->redirectUri  = (string) config('github.redirect_uri', '');
    }

    /** O OAuth App da plataforma está configurado (client_id/secret no .env)? */
    public function isConfigured(): bool
    {
        return $this->clientId !== '' && $this->clientSecret !== '';
    }

    /** URL para onde mandar o usuário autorizar (com `state` anti-CSRF). */
    public function authorizeUrl(string $state): string
    {
        $params = http_build_query([
            'client_id'    => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'scope'        => $this->scope,
            'state'        => $state,
            'allow_signup' => 'false',
        ]);
        return 'https://github.com/login/oauth/authorize?' . $params;
    }

    /**
     * Troca o `code` do callback por um access token.
     * @return array{ok:bool, token?:string, scope?:string, error?:string}
     */
    public function exchangeCode(string $code): array
    {
        [$status, $body] = $this->http(
            'POST',
            'https://github.com/login/oauth/access_token',
            [
                'client_id'     => $this->clientId,
                'client_secret' => $this->clientSecret,
                'code'          => $code,
                'redirect_uri'  => $this->redirectUri,
            ],
            null,
            ['Accept: application/json']
        );

        $json = json_decode((string) $body, true);
        if ($status === 200 && is_array($json) && !empty($json['access_token'])) {
            return [
                'ok'    => true,
                'token' => (string) $json['access_token'],
                'scope' => (string) ($json['scope'] ?? ''),
            ];
        }
        $err = is_array($json) ? ($json['error_description'] ?? $json['error'] ?? 'desconhecido') : 'resposta inválida';
        return ['ok' => false, 'error' => (string) $err];
    }

    /**
     * Dados públicos da conta autenticada (login, avatar).
     * @return array{ok:bool, login?:string, avatar?:string, name?:string, error?:string}
     */
    public function getUser(string $token): array
    {
        [$status, $body] = $this->apiGet($token, '/user');
        $json = json_decode((string) $body, true);
        if ($status === 200 && is_array($json) && !empty($json['login'])) {
            return [
                'ok'     => true,
                'login'  => (string) $json['login'],
                'avatar' => (string) ($json['avatar_url'] ?? ''),
                'name'   => (string) ($json['name'] ?? ''),
            ];
        }
        return ['ok' => false, 'error' => 'token inválido ou sem acesso (HTTP ' . $status . ')'];
    }

    /**
     * Lista repositórios do usuário (próprios + colaborador), ordenados por atualização.
     * Faz algumas páginas (até ~300 repos) — suficiente p/ um seletor.
     * @return array<int,array{full_name:string, private:bool, default_branch:string, updated_at:string}>
     */
    public function listRepos(string $token): array
    {
        $out = [];
        for ($page = 1; $page <= 3; $page++) {
            [$status, $body] = $this->apiGet(
                $token,
                '/user/repos?per_page=100&sort=updated&affiliation=owner,collaborator,organization_member&page=' . $page
            );
            if ($status !== 200) {
                break;
            }
            $rows = json_decode((string) $body, true);
            if (!is_array($rows) || empty($rows)) {
                break;
            }
            foreach ($rows as $r) {
                if (!is_array($r) || empty($r['full_name'])) {
                    continue;
                }
                $out[] = [
                    'full_name'      => (string) $r['full_name'],
                    'private'        => (bool) ($r['private'] ?? false),
                    'default_branch' => (string) ($r['default_branch'] ?? 'main'),
                    'updated_at'     => (string) ($r['updated_at'] ?? ''),
                ];
            }
            if (count($rows) < 100) {
                break;
            }
        }
        return $out;
    }

    /**
     * Descrição aceita pelo GitHub: uma linha só, sem caracteres de controle (quebra de linha e
     * tabulação da descrição do projeto viram espaço), espaços colapsados e no máximo 350 caracteres.
     * Sem isto a API recusa com "description control characters are not allowed".
     */
    public static function descricaoRepositorio(string $descricao): string
    {
        $d = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $descricao) ?? '';
        $d = trim((string) preg_replace('/\s+/u', ' ', $d));
        return mb_strlen($d) > 350 ? rtrim(mb_substr($d, 0, 349)) . '…' : $d;
    }

    /**
     * Cria um repositório novo na conta do usuário.
     * @return array{ok:bool, full_name?:string, default_branch?:string, clone_url?:string, error?:string}
     */
    public function createRepo(string $token, string $name, bool $private = true, string $description = ''): array
    {
        [$status, $body] = $this->http(
            'POST',
            'https://api.github.com/user/repos',
            null,
            json_encode([
                'name'        => $name,
                'private'     => $private,
                'description' => self::descricaoRepositorio($description),
                'auto_init'   => false,
            ]),
            $this->authHeaders($token)
        );
        $json = json_decode((string) $body, true);
        if (($status === 201) && is_array($json) && !empty($json['full_name'])) {
            return [
                'ok'             => true,
                'full_name'      => (string) $json['full_name'],
                'default_branch' => (string) ($json['default_branch'] ?? 'main'),
                'clone_url'      => (string) ($json['clone_url'] ?? ''),
            ];
        }
        $err = is_array($json) ? ($json['message'] ?? 'falha ao criar') : 'falha ao criar';
        if (is_array($json) && !empty($json['errors'][0]['message'])) {
            $err .= ': ' . $json['errors'][0]['message'];
        }
        return ['ok' => false, 'error' => (string) $err];
    }

    /**
     * Metadados de um repo específico (existe? branch padrão?).
     * @return array{ok:bool, full_name?:string, default_branch?:string, private?:bool, error?:string}
     */
    public function getRepo(string $token, string $fullName): array
    {
        [$status, $body] = $this->apiGet($token, '/repos/' . $fullName);
        $json = json_decode((string) $body, true);
        if ($status === 200 && is_array($json) && !empty($json['full_name'])) {
            return [
                'ok'             => true,
                'full_name'      => (string) $json['full_name'],
                'default_branch' => (string) ($json['default_branch'] ?? 'main'),
                'private'        => (bool) ($json['private'] ?? false),
            ];
        }
        return ['ok' => false, 'error' => 'repositório não encontrado ou sem acesso (HTTP ' . $status . ')'];
    }

    /**
     * Lista os commits recentes de uma branch (para o histórico / "voltar para esta versão").
     * @return array<int,array{sha:string, short:string, message:string, author:string, date:string}>
     */
    public function listCommits(string $token, string $fullName, string $branch = 'main', int $perPage = 20): array
    {
        $perPage = max(1, min(50, $perPage));
        [$status, $body] = $this->apiGet(
            $token,
            '/repos/' . $fullName . '/commits?sha=' . rawurlencode($branch) . '&per_page=' . $perPage
        );
        if ($status !== 200) {
            return [];
        }
        $rows = json_decode((string) $body, true);
        if (!is_array($rows)) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            if (!is_array($r) || empty($r['sha'])) {
                continue;
            }
            $commit  = $r['commit'] ?? [];
            $message = trim((string) ($commit['message'] ?? ''));
            $subject = trim((string) (preg_split('/\r?\n/', $message)[0] ?? ''));
            $out[] = [
                'sha'     => (string) $r['sha'],
                'short'   => substr((string) $r['sha'], 0, 7),
                'message' => $subject,
                'author'  => (string) ($commit['author']['name'] ?? ''),
                'date'    => (string) ($commit['author']['date'] ?? ''),
            ];
        }
        return $out;
    }

    // ---------------------------------------------------------------- HTTP

    private function authHeaders(string $token): array
    {
        return [
            'Authorization: Bearer ' . $token,
            'Accept: application/vnd.github+json',
            'X-GitHub-Api-Version: 2022-11-28',
        ];
    }

    /** GET na API com token. @return array{0:int,1:string} */
    private function apiGet(string $token, string $path): array
    {
        return $this->http('GET', 'https://api.github.com' . $path, null, null, $this->authHeaders($token));
    }

    /**
     * Requisição HTTP genérica.
     * @return array{0:int,1:string} [status, body]
     */
    private function http(string $method, string $url, ?array $form, ?string $rawBody, array $headers): array
    {
        $ch = curl_init($url);
        $headers[] = 'User-Agent: RaviDeveloper';
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_FOLLOWLOCATION => false,
        ];
        if ($form !== null) {
            $opts[CURLOPT_POSTFIELDS] = http_build_query($form);
        } elseif ($rawBody !== null) {
            $opts[CURLOPT_POSTFIELDS] = $rawBody;
            $opts[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
        }
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($body === false) {
            $body = '';
        }
        curl_close($ch);
        return [$status, (string) $body];
    }
}
