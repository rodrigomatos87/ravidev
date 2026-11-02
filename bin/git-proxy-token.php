#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Resolver do PROXY GIT (bin/git-proxy.py): handle + repo → token do GitHub do DONO do projeto.
 *
 * O daemon nunca decifra credencial: passa o HANDLE + o repo pedido (owner/nome) e este script
 * decide. Dois tipos de handle, na ordem:
 *
 * A) Handle de IDE (ide_proxy_handles — o mesmo do proxy do Claude). Autoriza quando TUDO bate:
 *   1. handle vivo (não revogado) → user_id + container;
 *   2. o container é uma sessão de IDE → projeto;
 *   3. o repo pedido é o repo DO PROJETO da sessão, OU o repo de um projeto LIGADO a ele
 *      (project_links ativo — o Claude da IDE também trabalha em /linked/<slug>; push exige
 *      ligação de ESCRITA, fetch aceita qualquer ligação);
 *   4. o ator (dono do handle) é DONO ou MEMBRO do projeto da sessão E do projeto-alvo.
 *
 * B) Handle de INTEGRAÇÃO (git_integration_handles: outro sistema seu, pela
 *    instância LAN do proxy). Sem sessão de IDE: o escopo é a lista de projetos git ACESSÍVEIS ao
 *    usuário do handle (ou a lista explícita em `repos`); push exige can_write. Operações extra:
 *    `list` devolve em JSON os repos que o handle alcança (para a tela do consumidor listar) e
 *    `create` cria um repo novo na conta do usuário (auto_init) E o projeto correspondente no
 *    RaviDev — o mesmo "Criar repo novo" da tela de projetos, só que disparado pela integração.
 *
 * Nos dois casos o token devolvido é o de quem ALCANÇA o repo (decrypt no PHP): se o owner do
 * repo for o github_login de um envolvido (dono do alvo, dono da sessão/ator ou membro do alvo),
 * o token é DESSE usuário; senão, o do DONO do projeto-alvo. Membro/integração pusha com a
 * credencial do dono SEM nunca vê-la. Revogou → recusa em ≤30s (cache do daemon).
 *
 * Uso:  php bin/git-proxy-token.php <handle> <owner/repo|-|nome-novo> <read|write|list|create>
 * Saída: token cru (read/write) ou JSON (list/create) em stdout + exit 0; nada + exit 1 se não
 *        autorizado. Nunca loga o token. Só roda no HOST (bin/, fora do document root).
 */

require __DIR__ . '/../src/bootstrap.php';

use Ravi\Core\Database;
use Ravi\Models\Project;
use Ravi\Models\User;

$handle = $argv[1] ?? '';
$repo   = trim((string) ($argv[2] ?? ''));
$op     = in_array($argv[3] ?? 'read', ['write', 'list', 'create'], true) ? $argv[3] : 'read';

if ($handle === '' || strlen($handle) > 128 || $repo === '' || strlen($repo) > 200) {
    fwrite(STDERR, "argumentos ausentes/invalidos\n");
    exit(1);
}

/**
 * Token de quem alcança o repo, dentro do círculo do projeto (dono do alvo, dono da sessão,
 * ator e membros do alvo). Caso real: repo na conta GitHub de OUTRO participante (ex.:
 * transferência de dono) — o token do dono atual daria 404; usamos o do envolvido cujo
 * github_login é o owner do repo. Nunca sai do círculo já validado.
 */
function tokenParaRepo(Project $alvo, Project $projetoSessao, int $atorId, string $repo): string
{
    $repoOwner  = (string) strstr($repo, '/', true);
    $candidatos = [];
    $envolvidos = [$alvo->user(), $projetoSessao->user(), User::find($atorId)];
    foreach ($alvo->members() as $m) {
        $envolvidos[] = User::find((int) $m['user_id']);
    }
    foreach ($envolvidos as $u) {
        if ($u instanceof User && !isset($candidatos[(int) $u->id])) {
            $candidatos[(int) $u->id] = $u;
        }
    }
    foreach ($candidatos as $u) {
        if (strcasecmp((string) ($u->github_login ?? ''), $repoOwner) === 0) {
            $token = (string) ($u->getGithubToken() ?? '');
            if ($token !== '') {
                return $token;
            }
        }
    }
    $owner = $alvo->user();
    return $owner instanceof User ? (string) ($owner->getGithubToken() ?? '') : '';
}

try {
    $db = Database::getInstance();

    // ── A) Handle de IDE ─────────────────────────────────────────────────────────────────────
    $h = $db->fetch(
        'SELECT user_id, container FROM ide_proxy_handles WHERE handle = ? AND revoked_at IS NULL LIMIT 1',
        [$handle]
    );
    if ($h) {
        if ($op === 'list' || $op === 'create') {
            exit(1); // listagem/criação são só para handles de integração
        }
        $s = $db->fetch(
            'SELECT project_id FROM ide_sessions WHERE container = ? LIMIT 1',
            [(string) $h['container']]
        );
        if (!$s) {
            exit(1); // handle sem sessão de IDE (não deveria acontecer)
        }
        $project = Project::find((int) $s['project_id']);
        if (!$project) {
            exit(1);
        }

        // Repo do PRÓPRIO projeto da sessão, ou de um projeto LIGADO a ele (o Claude da IDE
        // também commita em /linked/<slug>).
        $alvo = null;
        if ($project->gitEnabled() && strcasecmp((string) $project->git_repo_full_name, $repo) === 0) {
            $alvo = $project;
        } else {
            foreach (\Ravi\Models\ProjectLink::forProject((int) $project->id, true) as $ln) {
                $b = Project::find((int) $ln->linked_project_id);
                if (!$b || ($b->status ?? '') === 'deleted' || !$b->gitEnabled()) {
                    continue;
                }
                if (strcasecmp((string) $b->git_repo_full_name, $repo) !== 0) {
                    continue;
                }
                if ($op === 'write' && !$ln->isWrite()) {
                    continue; // ligação somente-leitura não autoriza push no repo ligado
                }
                $alvo = $b;
                break;
            }
        }
        if (!$alvo) {
            exit(1); // repo não é o do projeto nem de nenhum projeto ligado autorizável
        }
        if (!$project->isAccessibleBy((int) $h['user_id'])) {
            exit(1); // ator não é dono nem membro do projeto da sessão (ex.: compartilhamento removido)
        }
        if ((int) $alvo->id !== (int) $project->id && !$alvo->isAccessibleBy((int) $h['user_id'])) {
            exit(1); // ator precisa ter acesso TAMBÉM ao projeto ligado (mesma regra dos mounts)
        }

        $token = tokenParaRepo($alvo, $project, (int) $h['user_id'], $repo);
        if ($token === '') {
            exit(1); // ninguém do círculo do projeto tem GitHub conectado para este repo
        }

        // Auditoria best-effort (nunca bloqueia a resposta).
        try {
            $db->query('UPDATE ide_proxy_handles SET last_used_at = NOW() WHERE handle = ?', [$handle]);
        } catch (\Throwable $e) { /* ignore */ }

        fwrite(STDOUT, $token);
        exit(0);
    }

    // ── B) Handle de INTEGRAÇÃO ──────────────────────────────────────────────────────────────
    $g = $db->fetch(
        'SELECT id, user_id, repos, can_write FROM git_integration_handles WHERE handle = ? AND revoked_at IS NULL LIMIT 1',
        [$handle]
    );
    if (!$g) {
        exit(1); // handle desconhecido ou revogado
    }
    $atorId = (int) $g['user_id'];

    // Escopo: lista explícita em `repos` (JSON) ou, se NULL, todos os projetos git acessíveis.
    $permitidos = null;
    if ($g['repos'] !== null && trim((string) $g['repos']) !== '') {
        $lista = json_decode((string) $g['repos'], true);
        $permitidos = is_array($lista) ? array_map('strval', $lista) : [];
    }
    $projetos = [];
    foreach (Project::accessibleIdsFor($atorId) as $pid) {
        $p = Project::find((int) $pid);
        if (!$p || !$p->gitEnabled() || ($p->status ?? '') === 'deleted') {
            continue;
        }
        if ($permitidos !== null) {
            $dentro = false;
            foreach ($permitidos as $r) {
                if (strcasecmp($r, (string) $p->git_repo_full_name) === 0) {
                    $dentro = true;
                    break;
                }
            }
            if (!$dentro) {
                continue;
            }
        }
        $projetos[] = $p;
    }

    if ($op === 'list') {
        $saida = [];
        foreach ($projetos as $p) {
            $saida[] = [
                'repo'   => (string) $p->git_repo_full_name,
                'branch' => (string) ($p->git_branch ?: 'main'),
                'nome'   => (string) $p->name,
            ];
        }
        try {
            $db->query('UPDATE git_integration_handles SET last_used_at = NOW() WHERE id = ?', [(int) $g['id']]);
        } catch (\Throwable $e) { /* ignore */ }
        fwrite(STDOUT, json_encode(['repos' => $saida], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        exit(0);
    }

    if ($op === 'create') {
        // Cria repo novo na conta do usuário do handle E o projeto no RaviDev (o mesmo fluxo do
        // "Criar repo novo" da tela de projetos). Handle precisa de escrita e escopo total.
        if (!(int) $g['can_write'] || $permitidos !== null) {
            exit(1);
        }
        $nome = $repo; // no create, o 2º argumento é o NOME do repo (um segmento)
        if (!preg_match('/^[\w.-]{1,100}$/', $nome)) {
            fwrite(STDOUT, json_encode(['ok' => false, 'erro' => 'nome invalido (use letras, numeros, ponto, traco, underline)']));
            exit(0);
        }
        $ator = User::find($atorId);
        $token = $ator instanceof User ? (string) ($ator->getGithubToken() ?? '') : '';
        if ($token === '') {
            fwrite(STDOUT, json_encode(['ok' => false, 'erro' => 'usuario do handle sem GitHub conectado']));
            exit(0);
        }
        // auto_init: o consumidor clona em seguida, então o repo precisa nascer com branch.
        $ch = curl_init('https://api.github.com/user/repos');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Accept: application/vnd.github+json',
                'User-Agent: RaviDev-GitProxy',
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'name' => $nome,
                'private' => true,
                'description' => 'Criado por integração via Ravi Developer',
                'auto_init' => true,
            ]),
        ]);
        $corpo = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $j = json_decode($corpo, true);
        if ($status !== 201 || !is_array($j) || empty($j['full_name'])) {
            $erro = is_array($j) ? (string) ($j['errors'][0]['message'] ?? $j['message'] ?? 'status ' . $status) : 'status ' . $status;
            fwrite(STDOUT, json_encode(['ok' => false, 'erro' => 'GitHub recusou: ' . $erro], JSON_UNESCAPED_UNICODE));
            exit(0);
        }
        $fullName = (string) $j['full_name'];
        $branch = (string) ($j['default_branch'] ?? 'main') ?: 'main';
        $projeto = Project::createForUser($atorId, [
            'name' => $nome,
            'description' => 'Criado por integração (site gerido pela IA)',
            'type' => 'web',
        ]);
        $projeto->linkRepo($fullName, $branch, false);
        try {
            $db->query('UPDATE git_integration_handles SET last_used_at = NOW() WHERE id = ?', [(int) $g['id']]);
        } catch (\Throwable $e) { /* ignore */ }
        fwrite(STDOUT, json_encode(['ok' => true, 'repo' => $fullName, 'branch' => $branch], JSON_UNESCAPED_SLASHES));
        exit(0);
    }

    if ($op === 'write' && !(int) $g['can_write']) {
        exit(1); // integração somente-leitura não pusha
    }
    $alvo = null;
    foreach ($projetos as $p) {
        if (strcasecmp((string) $p->git_repo_full_name, $repo) === 0) {
            $alvo = $p;
            break;
        }
    }
    if (!$alvo) {
        exit(1); // repo fora do escopo do handle
    }

    $token = tokenParaRepo($alvo, $alvo, $atorId, $repo);
    if ($token === '') {
        exit(1);
    }

    try {
        $db->query('UPDATE git_integration_handles SET last_used_at = NOW() WHERE id = ?', [(int) $g['id']]);
    } catch (\Throwable $e) { /* ignore */ }

    fwrite(STDOUT, $token);
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, 'erro: ' . $e->getMessage() . "\n");
    exit(1);
}
