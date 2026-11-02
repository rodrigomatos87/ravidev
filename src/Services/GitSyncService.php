<?php

declare(strict_types=1);

namespace Ravi\Services;

use Ravi\Models\Project;
use Ravi\Models\User;

/**
 * Sincronização Git no HOST (commit + push). Roda no worker/app, NUNCA dentro do container.
 *
 * Segurança do token:
 *   - O token (do DONO do projeto) é passado ao git via variável de ambiente lida por um
 *     askpass (bin/git-askpass.sh) — não vai no argv (ps) nem é persistido no .git/config
 *     (o remote 'origin' fica com a URL limpa, sem credencial).
 *
 * O que entra no commit: tudo do workspace, EXCETO arquivos de processo/build (ver gitignore()).
 */
class GitSyncService
{
    /** Bloco do .gitignore que o Ravi garante (não versionar processo/artefatos/segredos). */
    private const IGNORE_BLOCK = <<<GI
# === Ravi Developer (não versionar) ===
/.maestro/
/PLAN.md
/PROGRESS.md
/delivery/
/vendor/
/node_modules/
/.expo/
/dist/
.env
*.log
*.sqlite
*.sqlite3
.DS_Store
__pycache__/
.vscode/sftp.json
.ravi/
# === fim Ravi Developer ===
GI;

    private string $askpass;

    public function __construct()
    {
        $this->askpass = dirname(__DIR__, 2) . '/bin/git-askpass.sh';
    }

    /**
     * Enfileira um job de sincronização (commit + push em background, no worker) para o projeto,
     * SE ele estiver ligado a um repositório e o dono tiver GitHub conectado. Usado pelo botão
     * "Sincronizar" da IDE e pelo auto-sync ao PAUSAR/FECHAR a IDE.
     * @return bool true se foi enfileirado.
     */
    public static function enqueueSync(Project $project, ?int $actorUserId = null, ?string $message = null): bool
    {
        if (!$project->gitEnabled()) {
            return false;
        }
        $owner = $project->user();
        if (!$owner || !$owner->hasGithub()) {
            return false;
        }
        $payload = ['project_id' => $project->id, 'project_uuid' => $project->uuid, 'auto' => true];
        if ($actorUserId) {
            $payload['triggered_by'] = $actorUserId;
        }
        if ($message !== null && $message !== '') {
            $payload['message'] = $message;
        }
        try {
            \Ravi\Core\Database::getInstance()->query(
                "INSERT INTO job_queue (uuid, queue, job_type, payload, max_attempts) VALUES (?, 'default', 'git_sync', ?, 2)",
                [uuid(), json_encode($payload, JSON_UNESCAPED_UNICODE)]
            );
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Caminho do workspace do projeto. */
    private function workspace(Project $project): string
    {
        $base = config('sandbox.workspace.base_path');
        if (!$base) {
            $base = (defined('STORAGE_PATH') ? STORAGE_PATH : dirname(__DIR__, 2) . '/storage') . '/sandbox';
        }
        return rtrim((string) $base, '/') . '/' . $project->uuid;
    }

    /**
     * A IDE e o Claude rodam como ROOT dentro do container e, ao mexer no git, deixam o `.git`
     * (e arquivos do workspace) com dono root no host. O worker roda como www-data e aí o commit
     * falha (".git/index.lock: Permission denied"). Como o www-data está no grupo `docker`, usa um
     * container root efêmero para devolver a posse dos arquivos ao usuário atual. Best-effort, e só
     * quando realmente não dá para escrever no `.git` (caminho comum não paga nada).
     */
    private function ensureOwnership(string $ws): void
    {
        $git = $ws . '/.git';
        if (!is_dir($git)) {
            return; // sem repo ainda: o `git init` criará como www-data
        }
        $probe = $git . '/.ravi-w';
        if (@touch($probe)) {
            @unlink($probe);
            return; // já gravável → nada a fazer
        }
        $uid = function_exists('posix_getuid') ? posix_getuid() : 33;
        $gid = function_exists('posix_getgid') ? posix_getgid() : 33;
        $image = (string) env('SANDBOX_IMAGE', 'ravi-sandbox:latest');
        @exec(
            'docker run --rm --user 0:0 -v ' . escapeshellarg($ws) . ':/w '
            . escapeshellarg($image) . ' chown -R ' . ((int) $uid) . ':' . ((int) $gid) . ' /w 2>/dev/null'
        );
    }

    /**
     * Commit + push do estado atual do workspace para o repo vinculado.
     *
     * @param ?string $message     Mensagem do commit (ex.: resumo da entrega do Maestro). Default: timestamp.
     * @param ?int    $actorUserId Quem APLICOU a alteração (vira o AUTOR do commit). Em projeto
     *                             compartilhado, mostra o nome de quem fez — mesmo que o push use o
     *                             token do dono. Default: o dono.
     * @return array{ok:bool, pushed?:bool, sha?:string, message?:string, error?:string}
     */
    public function sync(Project $project, ?string $message = null, ?int $actorUserId = null): array
    {
        if (!$project->gitEnabled()) {
            return ['ok' => false, 'error' => 'Projeto não está ligado a um repositório.'];
        }
        $ws = $this->workspace($project);
        if (!is_dir($ws)) {
            return ['ok' => false, 'error' => 'Workspace do projeto não existe ainda.'];
        }
        $this->ensureOwnership($ws);
        $owner = $project->user();
        $token = $owner ? $owner->getGithubToken() : null;
        if (!$token) {
            return ['ok' => false, 'error' => 'O dono do projeto não tem GitHub conectado.'];
        }
        $full   = (string) $project->git_repo_full_name;
        $branch = (string) ($project->git_branch ?: 'main');
        [$name, $email] = $this->commitIdentity($actorUserId, $owner);

        $this->ensureGitignore($ws);

        // Inicializa o repo se necessário (sem persistir credencial).
        if (!is_dir($ws . '/.git')) {
            [$rc, $out] = $this->git($ws, ['init', '-q']);
            if ($rc !== 0) {
                return ['ok' => false, 'error' => 'git init falhou: ' . $out];
            }
        }
        // Remote 'origin' com URL LIMPA (sem token).
        $cleanUrl = 'https://github.com/' . $full . '.git';
        $this->git($ws, ['remote', 'remove', 'origin']); // ignora erro se não existir
        $this->git($ws, ['remote', 'add', 'origin', $cleanUrl]);

        // Garante a branch alvo.
        $this->git($ws, ['checkout', '-q', '-B', $branch]);

        // Adiciona tudo (respeitando .gitignore) e commita se houver mudança.
        $this->git($ws, ['add', '-A']);
        [$rcS, $status] = $this->git($ws, ['status', '--porcelain']);
        $committed = false;
        if (trim($status) !== '') {
            $msg = $this->commitMessage($message);
            [$rcC, $outC] = $this->git($ws, [
                '-c', 'user.name=' . $name,
                '-c', 'user.email=' . $email,
                'commit', '-q',
                '--author=' . $name . ' <' . $email . '>',
                '-m', $msg,
            ]);
            if ($rcC !== 0) {
                return ['ok' => false, 'error' => 'git commit falhou: ' . $outC];
            }
            $committed = true;
        }

        // Existe algum commit para empurrar?
        [$rcH, $head] = $this->git($ws, ['rev-parse', '--verify', 'HEAD']);
        if ($rcH !== 0) {
            // Nenhum commit (workspace vazio) — nada a sincronizar.
            $project->markGitSynced(null);
            return ['ok' => true, 'pushed' => false, 'message' => 'Nada para sincronizar (workspace vazio).'];
        }

        // Push (token via askpass/env; URL com usuário, sem senha no argv).
        $pushUrl = 'https://x-access-token@github.com/' . $full . '.git';
        [$rcP, $outP] = $this->git($ws, ['push', $pushUrl, 'HEAD:' . $branch], $token);
        if ($rcP !== 0) {
            // non-fast-forward / rejeição: não força — reporta.
            $hint = '';
            if (stripos($outP, 'non-fast-forward') !== false || stripos($outP, 'rejected') !== false) {
                $hint = ' O repositório remoto está à frente. Resolva o conflito no GitHub antes de sincronizar.';
            }
            return ['ok' => false, 'error' => 'git push falhou: ' . trim($outP) . $hint];
        }

        $sha = trim($head);
        $project->markGitSynced($sha);
        return [
            'ok'      => true,
            'pushed'  => true,
            'sha'     => $sha,
            'message' => $committed ? 'Alterações enviadas ao GitHub.' : 'Repositório já estava em dia (push de commits pendentes).',
        ];
    }

    /**
     * Restaura o projeto para um commit anterior, como um NOVO commit no topo (histórico
     * linear, sem force-push). O workspace passa a ter o conteúdo daquela versão e o Maestro
     * continua dali. O autor do commit é quem clicou em "Voltar para esta versão".
     * @return array{ok:bool, sha?:string, message?:string, error?:string}
     */
    public function revertTo(Project $project, string $sha, ?int $actorUserId = null): array
    {
        $sha = trim($sha);
        if (!preg_match('/^[0-9a-f]{7,40}$/i', $sha)) {
            return ['ok' => false, 'error' => 'Commit inválido.'];
        }
        if (!$project->gitEnabled()) {
            return ['ok' => false, 'error' => 'Projeto não está ligado a um repositório.'];
        }
        $ws = $this->workspace($project);
        if (!is_dir($ws . '/.git')) {
            return ['ok' => false, 'error' => 'Sincronize o projeto ao menos uma vez antes de reverter.'];
        }
        $this->ensureOwnership($ws);
        $owner = $project->user();
        $token = $owner ? $owner->getGithubToken() : null;
        if (!$token) {
            return ['ok' => false, 'error' => 'O dono do projeto não tem GitHub conectado.'];
        }
        $full   = (string) $project->git_repo_full_name;
        $branch = (string) ($project->git_branch ?: 'main');
        [$name, $email] = $this->commitIdentity($actorUserId, $owner);

        // Traz o histórico do remoto (precisa do token).
        $this->git($ws, ['remote', 'remove', 'origin']);
        $this->git($ws, ['remote', 'add', 'origin', 'https://github.com/' . $full . '.git']);
        $pushUrl = 'https://x-access-token@github.com/' . $full . '.git';
        [$rcF] = $this->git($ws, ['fetch', '--quiet', $pushUrl, $branch], $token);
        if ($rcF !== 0) {
            return ['ok' => false, 'error' => 'Não consegui buscar o histórico do repositório.'];
        }
        // O alvo precisa existir.
        [$rcE] = $this->git($ws, ['cat-file', '-e', $sha . '^{commit}']);
        if ($rcE !== 0) {
            return ['ok' => false, 'error' => 'Esse commit não existe no repositório.'];
        }
        // Posiciona na ponta do remoto.
        $this->git($ws, ['checkout', '-q', '-B', $branch, 'FETCH_HEAD']);
        // Conteúdo = versão alvo, mas mantendo a ponta como pai (commit novo no topo).
        $this->git($ws, ['reset', '--hard', '-q', $sha]);
        $this->git($ws, ['reset', '--soft', 'FETCH_HEAD']);

        [$rcS, $status] = $this->git($ws, ['status', '--porcelain']);
        if (trim($status) === '') {
            $project->markGitSynced(null);
            return ['ok' => true, 'message' => 'O projeto já está nesta versão.'];
        }
        $short = substr($sha, 0, 7);
        $msg = 'Voltar para a versão ' . $short . ' — via Ravi Developer (' . date('Y-m-d H:i') . ')';
        [$rcC, $outC] = $this->git($ws, [
            '-c', 'user.name=' . $name,
            '-c', 'user.email=' . $email,
            'commit', '-q',
            '--author=' . $name . ' <' . $email . '>',
            '-m', $msg,
        ]);
        if ($rcC !== 0) {
            return ['ok' => false, 'error' => 'Falha ao criar o commit de reversão: ' . $outC];
        }
        [$rcP, $outP] = $this->git($ws, ['push', $pushUrl, 'HEAD:' . $branch], $token);
        if ($rcP !== 0) {
            return ['ok' => false, 'error' => 'git push falhou: ' . trim($outP)];
        }
        [, $head] = $this->git($ws, ['rev-parse', '--verify', 'HEAD']);
        $newSha = trim($head);
        $project->markGitSynced($newSha);
        @exec('chmod -R 0777 ' . escapeshellarg($ws) . ' 2>/dev/null');
        return ['ok' => true, 'sha' => $newSha, 'message' => 'Projeto restaurado para a versão ' . $short . '.'];
    }

    /** Identidade (nome/email) do AUTOR do commit: quem aplicou a alteração (ou o dono). */
    private function commitIdentity(?int $actorUserId, ?User $owner): array
    {
        $actor = $actorUserId ? User::find($actorUserId) : null;
        if (!$actor) {
            $actor = $owner;
        }
        $login = $actor ? (string) ($actor->github_login ?? '') : '';
        $name  = $actor ? (string) ($actor->name ?: ($login ?: 'Ravi Developer')) : 'Ravi Developer';
        // Se quem aplicou tem GitHub conectado, o e-mail noreply LIGA o commit à conta dele.
        // Senão, um noreply genérico (o NOME ainda aparece no histórico).
        $email = $login !== '' ? ($login . '@users.noreply.github.com') : (string) config('urls.noreply');
        return [$name, $email];
    }

    /** Mensagem do commit: resumo da entrega (1ª linha) ou um padrão com timestamp. */
    private function commitMessage(?string $message): string
    {
        $message = trim((string) $message);
        if ($message === '') {
            return 'Sincronização via Ravi Developer — ' . date('Y-m-d H:i:s');
        }
        // Assunto = 1ª linha enxuta; corpo = resto (se houver).
        $lines   = preg_split('/\r?\n/', $message);
        $subject = trim((string) ($lines[0] ?? ''));
        if (mb_strlen($subject) > 72) {
            $subject = mb_substr($subject, 0, 69) . '…';
        }
        $body = trim(implode("\n", array_slice($lines, 1)));
        $out  = $subject !== '' ? $subject : 'Atualização via Ravi Developer';
        if ($body !== '') {
            $out .= "\n\n" . $body;
        }
        return $out;
    }

    /** Garante o bloco de exclusões do Ravi no .gitignore do workspace (preserva o que já existe). */
    private function ensureGitignore(string $ws): void
    {
        $path = $ws . '/.gitignore';
        $current = is_file($path) ? (string) @file_get_contents($path) : '';
        if (strpos($current, '=== Ravi Developer') !== false) {
            return; // já tem o bloco
        }
        $new = rtrim($current) . ($current !== '' ? "\n\n" : '') . self::IGNORE_BLOCK . "\n";
        @file_put_contents($path, $new);
    }

    /**
     * Executa um comando git no workspace de forma segura.
     * Se $token != null, expõe RAVI_GIT_TOKEN + GIT_ASKPASS para autenticar SEM vazar o token.
     * @return array{0:int,1:string} [exitCode, output combinado]
     */
    private function git(string $ws, array $args, ?string $token = null): array
    {
        $cmd = array_merge(
            ['git', '-C', $ws, '-c', 'safe.directory=*'],
            $args
        );

        $env = [
            'HOME'                 => sys_get_temp_dir(),
            'GIT_TERMINAL_PROMPT'  => '0',
            'GIT_CONFIG_NOSYSTEM'  => '1',
            'PATH'                 => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
        ];
        if ($token !== null) {
            $env['RAVI_GIT_TOKEN'] = $token;
            $env['GIT_ASKPASS']    = $this->askpass;
        }

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($cmd, $descriptors, $pipes, $ws, $env);
        if (!is_resource($proc)) {
            return [1, 'proc_open falhou'];
        }
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $rc = proc_close($proc);
        return [$rc, (string) $out];
    }
}
