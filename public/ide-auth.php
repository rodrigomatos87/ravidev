<?php

declare(strict_types=1);

/**
 * Verificação de acesso à IDE (alvo do `auth_request` do nginx para /ide/<token>/).
 *
 * Roda a CADA pedido do proxy do code-server, então é DELIBERADAMENTE LEVE: não dá bootstrap
 * da app nem abre a sessão do PHP (que travaria com lock e serializaria os assets). Só:
 *   1. lê o cookie de sessão (ravi_session) e valida em user_sessions (mesma fonte do WebSocket);
 *   2. resolve o token → projeto em ide_sessions;
 *   3. confere acesso (dono OU membro compartilhado).
 * Responde 200 (libera) ou 403 (bloqueia). Qualquer falha = 403.
 */

header('Cache-Control: no-store');

function deny(): void
{
    http_response_code(403);
    echo 'forbidden';
    exit;
}

$token = preg_replace('/[^a-f0-9]/', '', (string) ($_GET['token'] ?? ''));
if ($token === '') {
    deny();
}

// Cookie de sessão (nome do config; default ravi_session).
$sid = (string) ($_COOKIE['ravi_session'] ?? '');
if ($sid === '' || !preg_match('/^[A-Za-z0-9,\-]{16,128}$/', $sid)) {
    deny();
}

require __DIR__ . '/../vendor/autoload.php';
try {
    \Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
} catch (\Throwable $e) {
    deny();
}

try {
    $host = getenv('DB_HOST') ?: ($_ENV['DB_HOST'] ?? '127.0.0.1');
    $port = getenv('DB_PORT') ?: ($_ENV['DB_PORT'] ?? '3306');
    $name = getenv('DB_DATABASE') ?: ($_ENV['DB_DATABASE'] ?? '');
    $user = getenv('DB_USERNAME') ?: ($_ENV['DB_USERNAME'] ?? '');
    $pass = getenv('DB_PASSWORD') ?: ($_ENV['DB_PASSWORD'] ?? '');
    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3]
    );

    // 1. sessão → usuário (janela de 24h; sessão é mantida pelo uso normal da app)
    $st = $pdo->prepare('SELECT user_id FROM user_sessions WHERE id = ? AND last_activity > ? LIMIT 1');
    $st->execute([$sid, time() - 86400]);
    $userId = $st->fetchColumn();
    if ($userId === false) {
        deny();
    }
    $userId = (int) $userId;

    // 2. token → sessão (projeto + dono da sessão)
    $st = $pdo->prepare("SELECT project_id, user_id FROM ide_sessions WHERE token = ? AND status = 'running' LIMIT 1");
    $st->execute([$token]);
    $sess = $st->fetch(PDO::FETCH_ASSOC);
    if (!$sess) {
        deny();
    }
    $projectId = (int) $sess['project_id'];
    $sessUser  = $sess['user_id'] !== null ? (int) $sess['user_id'] : null;

    // 3a. A sessão da IDE é PESSOAL: só o usuário dono da sessão a acessa (o container do dono
    //     do projeto carrega o token do GitHub — nunca pode ser aberto por outro usuário).
    if ($sessUser !== null && $sessUser !== $userId) {
        deny();
    }

    // 3b. Defesa em profundidade: o usuário precisa ter acesso ao projeto (dono OU membro).
    $st = $pdo->prepare('SELECT 1 FROM projects WHERE id = ? AND user_id = ? LIMIT 1');
    $st->execute([$projectId, $userId]);
    $ok = $st->fetchColumn() !== false;
    if (!$ok) {
        $st = $pdo->prepare('SELECT 1 FROM project_members WHERE project_id = ? AND user_id = ? LIMIT 1');
        $st->execute([$projectId, $userId]);
        $ok = $st->fetchColumn() !== false;
    }
    if (!$ok) {
        deny();
    }

    // HEARTBEAT: cada atividade HTTP da IDE renova last_used_at → a limpeza por ociosidade não
    // derruba uma IDE em uso. (Throttle barato: só atualiza se passou > 60s desde a última.)
    try {
        $pdo->prepare("UPDATE ide_sessions SET last_used_at = NOW()
                       WHERE token = ? AND last_used_at < (NOW() - INTERVAL 60 SECOND)")
            ->execute([$token]);
    } catch (\Throwable $e) {
        // heartbeat é best-effort
    }
} catch (\Throwable $e) {
    deny();
}

http_response_code(200);
echo 'ok';
