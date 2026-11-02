#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Cria um usuário ADMINISTRADOR (role = admin, cadastro aprovado, conta ativa).
 *
 * Substitui o antigo usuário com senha fixa do seed: nenhuma instalação nasce com credencial
 * conhecida. A senha é gravada com o mesmo hash do cadastro normal (Encryption::hashPassword,
 * o mesmo usado por User::register).
 *
 * Uso:
 *   php bin/criar-admin.php                                        (pergunta nome, e-mail e senha)
 *   php bin/criar-admin.php --nome="Fulano" --email=fulano@empresa.com --senha='...'
 *
 * Se o e-mail já existir, a conta é promovida a admin (aprovada e ativada) e a senha só é
 * trocada se --senha for informada ou digitada.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

/** Lê variável do .env ou do ambiente do processo (compose/systemd), nessa ordem. */
function variavelAmbiente(string $nome, string $padrao): string
{
    $valor = $_ENV[$nome] ?? $_SERVER[$nome] ?? getenv($nome);
    return ($valor === false || $valor === null || $valor === '') ? $padrao : (string) $valor;
}

$opcoes = getopt('', ['nome:', 'email:', 'senha:', 'help']);

if (isset($opcoes['help'])) {
    echo <<<AJUDA
Cria um administrador do Ravi Developer.

Uso:
  php bin/criar-admin.php [--nome="Nome"] [--email=email@dominio] [--senha='senha']

O que não vier por argumento é perguntado no terminal (a senha sem eco).
Senha: mínimo de 10 caracteres.

AJUDA;
    exit(0);
}

$interativo = function_exists('posix_isatty') ? posix_isatty(STDIN) : true;

function perguntarTexto(string $rotulo): string
{
    echo $rotulo;
    $linha = fgets(STDIN);
    return trim($linha === false ? '' : $linha);
}

function perguntarSenha(string $rotulo): string
{
    echo $rotulo;
    $temStty = trim((string) shell_exec('command -v stty 2>/dev/null')) !== '';
    if ($temStty) {
        shell_exec('stty -echo 2>/dev/null');
    }
    $linha = fgets(STDIN);
    if ($temStty) {
        shell_exec('stty echo 2>/dev/null');
    }
    echo "\n";
    return rtrim($linha === false ? '' : $linha, "\r\n");
}

function falhar(string $msg): never
{
    fwrite(STDERR, "✗ {$msg}\n");
    exit(1);
}

$nome = trim((string) ($opcoes['nome'] ?? ''));
$email = strtolower(trim((string) ($opcoes['email'] ?? '')));
$senha = isset($opcoes['senha']) ? (string) $opcoes['senha'] : null;

if ($email === '') {
    if (!$interativo) {
        falhar('Informe --email (sem terminal não dá para perguntar).');
    }
    $email = strtolower(perguntarTexto('E-mail do administrador: '));
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    falhar("E-mail inválido: {$email}");
}

// ----------------------------------------------------------------------------
// Banco (mesmas variáveis do .env usadas por bin/db-init.php)
// ----------------------------------------------------------------------------
$host = variavelAmbiente('DB_HOST', '127.0.0.1');
$porta = variavelAmbiente('DB_PORT', '3306');
$banco = variavelAmbiente('DB_DATABASE', 'ravi_developer');
try {
    $pdo = new PDO(
        "mysql:host={$host};port={$porta};dbname={$banco};charset=utf8mb4",
        variavelAmbiente('DB_USERNAME', 'ravi'),
        variavelAmbiente('DB_PASSWORD', 'secret'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    falhar('Falha ao conectar no banco: ' . $e->getMessage());
}

try {
    $pdo->query('SELECT role, approval_status FROM users LIMIT 0');
} catch (PDOException $e) {
    falhar('A tabela users não tem as colunas role/approval_status. Rode antes: php bin/db-init.php --migrate');
}

$st = $pdo->prepare('SELECT id, name FROM users WHERE email = ?');
$st->execute([$email]);
$existente = $st->fetch() ?: null;

if ($nome === '' && $existente === null) {
    if (!$interativo) {
        falhar('Informe --nome (sem terminal não dá para perguntar).');
    }
    $nome = perguntarTexto('Nome: ');
}
if ($existente === null && ($nome === '' || mb_strlen($nome) > 100)) {
    falhar('Nome obrigatório (até 100 caracteres).');
}

if ($senha === null && ($existente === null || $interativo)) {
    if (!$interativo) {
        falhar('Informe --senha (sem terminal não dá para perguntar).');
    }
    $rotulo = $existente === null ? 'Senha: ' : 'Nova senha (vazio = manter a atual): ';
    $senha = perguntarSenha($rotulo);
    if ($senha !== '' && perguntarSenha('Repita a senha: ') !== $senha) {
        falhar('As senhas não conferem.');
    }
    if ($senha === '' && $existente !== null) {
        $senha = null;
    }
}
if ($senha !== null && mb_strlen($senha) < 10) {
    falhar('A senha precisa de pelo menos 10 caracteres.');
}

$hash = $senha !== null ? \Ravi\Core\Encryption::hashPassword($senha) : null;

if ($existente !== null) {
    $campos = "role = 'admin', approval_status = 'approved', is_active = 1,
               email_verified_at = COALESCE(email_verified_at, NOW()), email_verify_token = NULL";
    $args = [];
    if ($nome !== '') {
        $campos .= ', name = ?';
        $args[] = $nome;
    }
    if ($hash !== null) {
        $campos .= ', password_hash = ?';
        $args[] = $hash;
    }
    $args[] = (int) $existente['id'];
    $pdo->prepare("UPDATE users SET {$campos} WHERE id = ?")->execute($args);
    echo "✓ Conta existente promovida a administrador: {$email}" . ($hash !== null ? ' (senha trocada)' : '') . "\n";
    exit(0);
}

$pdo->prepare(
    "INSERT INTO users (uuid, name, email, password_hash, plan, tokens_limit, is_active,
                        role, approval_status, email_verified_at)
     VALUES (?, ?, ?, ?, 'free', 100000, 1, 'admin', 'approved', NOW())"
)->execute([uuid(), $nome, $email, $hash]);

echo "✓ Administrador criado: {$email}. Entre pelo /login.\n";
