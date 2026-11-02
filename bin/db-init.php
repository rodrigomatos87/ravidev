#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Ravi Developer: inicialização e migrations do banco.
 *
 * Banco oficial: MariaDB 10.6 ou superior. Várias migrations usam sintaxe exclusiva do MariaDB
 * (ADD COLUMN IF NOT EXISTS, CREATE TRIGGER IF NOT EXISTS); MySQL não é suportado.
 *
 * Controle de aplicação: cada arquivo aplicado com sucesso é registrado em `schema_migrations`
 * (arquivo + aplicado_em) e não roda de novo. As migrations do núcleo (database/migrations) e as
 * dos módulos opcionais (modulos/<nome>/migrations) são mescladas e ordenadas pelo nome do
 * arquivo (a numeração é única entre todos).
 *
 * Uso: php bin/db-init.php --help
 */

require __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

/** Lê variável do .env ou do ambiente do processo (compose/systemd), nessa ordem. */
function variavelAmbiente(string $nome, string $padrao): string
{
    $valor = $_ENV[$nome] ?? $_SERVER[$nome] ?? getenv($nome);
    return ($valor === false || $valor === null || $valor === '') ? $padrao : (string) $valor;
}

define('ROOT_PATH', dirname(__DIR__));
define('DATABASE_PATH', ROOT_PATH . '/database');

// ============================================================================
// OPÇÕES
// ============================================================================

$opcoes = getopt('', ['migrate', 'seed', 'fresh', 'help', 'force', 'marcar-aplicadas::', 'status']);

if (isset($opcoes['help'])) {
    echo <<<AJUDA
Ravi Developer: inicialização do banco (MariaDB 10.6+)

Uso:
  php bin/db-init.php [opções]

Opções:
  --migrate                     Aplica só as migrations pendentes
  --seed                        Roda só os seeds (database/seeds/*.sql)
  --fresh                       APAGA todas as tabelas e views e roda migrations + seeds do zero
  --force                       Não pede confirmação (necessário com --fresh fora de terminal)
  --status                      Lista as migrations e diz quais já foram aplicadas
  --marcar-aplicadas[=ARQUIVO]  Registra migrations como aplicadas SEM executar nada.
                                Sem valor: marca todas as existentes. Com valor: marca até
                                esse arquivo, inclusive (ex.: --marcar-aplicadas=038_gw_requests_max_tokens.sql).
                                Serve para adotar o controle num banco que já existe (instalação
                                antiga, criada antes de schema_migrations): sem isso, as migrations
                                antigas falham com "já existe". Confira antes que o banco realmente
                                tem tudo o que essas migrations criam.
  --help                        Mostra esta ajuda

Sem opções: aplica as migrations pendentes e roda os seeds.

Exemplos:
  php bin/db-init.php                     # instalação nova: migrations + seeds
  php bin/db-init.php --migrate           # só o que ainda não foi aplicado
  php bin/db-init.php --fresh --force     # recria tudo (DESTRÓI os dados)
  php bin/db-init.php --marcar-aplicadas=038_gw_requests_max_tokens.sql && php bin/db-init.php --migrate
                                          # banco existente: adota o controle e aplica o resto

Depois da instalação, crie o primeiro administrador:
  php bin/criar-admin.php

AJUDA;
    exit(0);
}

$marcarAplicadas = array_key_exists('marcar-aplicadas', $opcoes);
$marcarAte = $marcarAplicadas && is_string($opcoes['marcar-aplicadas']) ? basename($opcoes['marcar-aplicadas']) : null;
$soStatus = isset($opcoes['status']);
$rodarFresh = isset($opcoes['fresh']);
$force = isset($opcoes['force']);
$rodarMigrate = isset($opcoes['migrate']) || (!isset($opcoes['seed']) && !$rodarFresh);
$rodarSeed = isset($opcoes['seed']) || (!isset($opcoes['migrate']) && !$rodarFresh);

if ($marcarAplicadas || $soStatus) {
    // Ações exclusivas: não aplicam migrations nem seeds.
    $rodarMigrate = false;
    $rodarSeed = false;
    if ($rodarFresh) {
        fwrite(STDERR, "--fresh não combina com --marcar-aplicadas/--status.\n");
        exit(1);
    }
}

// ============================================================================
// FUNÇÕES
// ============================================================================

/**
 * Divide um script SQL em comandos, respeitando aspas, comentários e a diretiva DELIMITER
 * (usada nos triggers da 001). Comentários são descartados; o que sobra é executável.
 *
 * @return string[]
 */
function dividirSql(string $sql): array
{
    $comandos = [];
    $atual = '';
    $delimitador = ';';
    $n = strlen($sql);
    $i = 0;
    $inicioDeLinha = true;

    while ($i < $n) {
        $c = $sql[$i];

        // Diretiva DELIMITER (só vale no início de linha, como no cliente mysql)
        if ($inicioDeLinha) {
            $fimLinha = strpos($sql, "\n", $i);
            $linha = $fimLinha === false ? substr($sql, $i) : substr($sql, $i, $fimLinha - $i);
            if (preg_match('/^\s*DELIMITER\s+(\S+)\s*$/i', $linha, $m)) {
                if (trim($atual) !== '') {
                    $comandos[] = trim($atual);
                }
                $atual = '';
                $delimitador = $m[1];
                $i = $fimLinha === false ? $n : $fimLinha + 1;
                continue;
            }
        }
        $inicioDeLinha = false;

        // Comentário de linha: "-- " (ou "--" no fim da linha) e "#"
        if (($c === '-' && substr($sql, $i, 2) === '--' && ($i + 2 >= $n || ctype_space($sql[$i + 2]))) || $c === '#') {
            $fim = strpos($sql, "\n", $i);
            $i = $fim === false ? $n : $fim;
            continue;
        }
        // Comentário de bloco
        if ($c === '/' && substr($sql, $i, 2) === '/*') {
            $fim = strpos($sql, '*/', $i + 2);
            $i = $fim === false ? $n : $fim + 2;
            continue;
        }
        // Literais entre aspas (com escape por barra e por aspa dobrada)
        if ($c === "'" || $c === '"' || $c === '`') {
            $j = $i + 1;
            while ($j < $n) {
                if ($sql[$j] === '\\' && $c !== '`') {
                    $j += 2;
                    continue;
                }
                if ($sql[$j] === $c) {
                    if ($j + 1 < $n && $sql[$j + 1] === $c) {
                        $j += 2;
                        continue;
                    }
                    break;
                }
                $j++;
            }
            $atual .= substr($sql, $i, $j - $i + 1);
            $i = $j + 1;
            continue;
        }
        // Fim de comando
        if (substr($sql, $i, strlen($delimitador)) === $delimitador) {
            if (trim($atual) !== '') {
                $comandos[] = trim($atual);
            }
            $atual = '';
            $i += strlen($delimitador);
            continue;
        }

        $atual .= $c;
        if ($c === "\n") {
            $inicioDeLinha = true;
        }
        $i++;
    }
    if (trim($atual) !== '') {
        $comandos[] = trim($atual);
    }
    return $comandos;
}

/** Executa um arquivo .sql inteiro. Lança PDOException no primeiro comando que falhar. */
function executarArquivoSql(PDO $pdo, string $arquivo): void
{
    $sql = file_get_contents($arquivo);
    if ($sql === false) {
        throw new RuntimeException("Não foi possível ler {$arquivo}");
    }
    foreach (dividirSql($sql) as $comando) {
        $stmt = $pdo->query($comando);
        // Esvazia resultados (ex.: SELECT 'ok' no fim das migrations) para liberar a conexão.
        if ($stmt instanceof PDOStatement) {
            if ($stmt->columnCount() > 0) {
                $stmt->fetchAll();
            }
            $stmt->closeCursor();
        }
    }
}

/** @return string[] caminhos de todas as migrations (núcleo + módulos), ordenados pelo nome do arquivo */
function listarMigrations(): array
{
    $arquivos = glob(DATABASE_PATH . '/migrations/*.sql') ?: [];
    // Migrations dos módulos opcionais (modulos/<nome>/migrations/*.sql), ordenadas pelo nome
    // do arquivo junto com as do núcleo (a numeração é única entre todos).
    \Ravi\Core\Modulos::carregar(ROOT_PATH . '/modulos');
    $arquivos = array_merge($arquivos, \Ravi\Core\Modulos::migrations());
    usort($arquivos, static fn(string $a, string $b): int => strcmp(basename($a), basename($b)));

    $vistos = [];
    foreach ($arquivos as $a) {
        $nome = basename($a);
        if (isset($vistos[$nome])) {
            fwrite(STDERR, "✗ Nome de migration repetido: {$nome} ({$vistos[$nome]} e {$a})\n");
            exit(1);
        }
        $vistos[$nome] = $a;
    }
    return $arquivos;
}

function garantirTabelaControle(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
        arquivo VARCHAR(255) NOT NULL PRIMARY KEY,
        aplicado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/** @return array<string,string> arquivo => aplicado_em */
function migrationsAplicadas(PDO $pdo): array
{
    $saida = [];
    foreach ($pdo->query('SELECT arquivo, aplicado_em FROM schema_migrations') as $r) {
        $saida[$r['arquivo']] = (string) $r['aplicado_em'];
    }
    return $saida;
}

function registrarMigration(PDO $pdo, string $nome): void
{
    $pdo->prepare('INSERT IGNORE INTO schema_migrations (arquivo) VALUES (?)')->execute([$nome]);
}

function perguntar(string $pergunta): string
{
    echo $pergunta;
    $linha = fgets(STDIN);
    return strtolower(trim($linha === false ? '' : $linha));
}

// ============================================================================
// CONEXÃO
// ============================================================================

$dbConfig = [
    'host' => variavelAmbiente('DB_HOST', '127.0.0.1'),
    'port' => variavelAmbiente('DB_PORT', '3306'),
    'database' => variavelAmbiente('DB_DATABASE', 'ravi_developer'),
    'username' => variavelAmbiente('DB_USERNAME', 'ravi'),
    'password' => variavelAmbiente('DB_PASSWORD', 'secret'),
];

echo "\nRavi Developer: banco de dados\n";
echo "Conectando em {$dbConfig['database']}@{$dbConfig['host']}:{$dbConfig['port']}...\n";

$opcoesPdo = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

try {
    // Conecta sem banco para criá-lo se ainda não existir
    $pdo = new PDO("mysql:host={$dbConfig['host']};port={$dbConfig['port']};charset=utf8mb4",
        $dbConfig['username'], $dbConfig['password'], $opcoesPdo);
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbConfig['database']}`
                CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    $pdo = new PDO("mysql:host={$dbConfig['host']};port={$dbConfig['port']};dbname={$dbConfig['database']};charset=utf8mb4",
        $dbConfig['username'], $dbConfig['password'], $opcoesPdo);

    $versao = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
    echo "✓ Conectado ({$versao})\n";
    if (stripos($versao, 'mariadb') === false) {
        echo "⚠ Este servidor não parece ser MariaDB. O banco oficial é MariaDB 10.6+; várias migrations não rodam em MySQL.\n";
    }
    echo "\n";
} catch (PDOException $e) {
    echo "✗ Falha ao conectar: " . $e->getMessage() . "\n";
    exit(1);
}

// ============================================================================
// --fresh: APAGA TUDO
// ============================================================================

if ($rodarFresh) {
    if (!$force) {
        if (perguntar("⚠ ATENÇÃO: isto APAGA todas as tabelas e todos os dados de {$dbConfig['database']}.\nDigite 'sim' para continuar: ") !== 'sim') {
            echo "Cancelado.\n";
            exit(0);
        }
    }

    echo "Apagando tabelas e views...\n";
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach ($pdo->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM) as [$nome, $tipo]) {
        if (strtoupper((string) $tipo) === 'VIEW') {
            echo "  view: {$nome}\n";
            $pdo->exec("DROP VIEW IF EXISTS `{$nome}`");
        } else {
            echo "  tabela: {$nome}\n";
            $pdo->exec("DROP TABLE IF EXISTS `{$nome}`");
        }
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    echo "✓ Banco limpo\n\n";

    $rodarMigrate = true;
    $rodarSeed = true;
}

// ============================================================================
// CONTROLE (schema_migrations)
// ============================================================================

garantirTabelaControle($pdo);
$arquivosMigration = listarMigrations();

if ($soStatus) {
    $aplicadas = migrationsAplicadas($pdo);
    $pendentes = 0;
    foreach ($arquivosMigration as $arquivo) {
        $nome = basename($arquivo);
        if (isset($aplicadas[$nome])) {
            echo "  ✓ {$nome}  (aplicada em {$aplicadas[$nome]})\n";
        } else {
            echo "  · {$nome}  PENDENTE\n";
            $pendentes++;
        }
    }
    $conhecidos = array_map('basename', $arquivosMigration);
    foreach (array_diff(array_keys($aplicadas), $conhecidos) as $orfa) {
        echo "  ? {$orfa}  registrada, mas o arquivo não existe mais (módulo removido?)\n";
    }
    echo "\nPendentes: {$pendentes}\n";
    exit(0);
}

if ($marcarAplicadas) {
    if ($marcarAte !== null && !in_array($marcarAte, array_map('basename', $arquivosMigration), true)) {
        echo "✗ Migration '{$marcarAte}' não encontrada. Veja os nomes com --status.\n";
        exit(1);
    }
    $aplicadas = migrationsAplicadas($pdo);
    $marcadas = 0;
    echo "Registrando migrations como aplicadas (sem executar)...\n";
    foreach ($arquivosMigration as $arquivo) {
        $nome = basename($arquivo);
        if ($marcarAte !== null && strcmp($nome, $marcarAte) > 0) {
            break;
        }
        if (isset($aplicadas[$nome])) {
            continue;
        }
        registrarMigration($pdo, $nome);
        echo "  ✓ {$nome}\n";
        $marcadas++;
    }
    echo "✓ {$marcadas} migration(s) registrada(s). Rode --migrate para aplicar o que ficou pendente.\n";
    exit(0);
}

// ============================================================================
// MIGRATIONS
// ============================================================================

if ($rodarMigrate) {
    echo "Aplicando migrations...\n";
    $aplicadas = migrationsAplicadas($pdo);
    $pendentes = array_values(array_filter(
        $arquivosMigration,
        static fn(string $a): bool => !isset($aplicadas[basename($a)])
    ));

    if ($pendentes === []) {
        echo "  Nada pendente (" . count($arquivosMigration) . " já aplicadas)\n";
    }

    foreach ($pendentes as $arquivo) {
        $nome = basename($arquivo);
        echo "  {$nome}... ";
        try {
            executarArquivoSql($pdo, $arquivo);
            registrarMigration($pdo, $nome);
            echo "✓\n";
        } catch (Throwable $e) {
            echo "✗\n";
            echo "    Erro: " . $e->getMessage() . "\n";
            echo "    A migration NÃO foi registrada; as seguintes não foram aplicadas.\n";
            if (preg_match('/already exists|Duplicate column|Duplicate key name|1050|1060|1061/i', $e->getMessage())) {
                echo "    Parece um banco que já tem este schema. Para adotar o controle num banco existente,\n";
                echo "    veja --marcar-aplicadas em: php bin/db-init.php --help\n";
            }
            // DDL no MariaDB faz commit implícito: comandos anteriores do mesmo arquivo podem ter
            // ficado aplicados. Corrija a causa e confira o schema antes de rodar de novo.
            exit(1);
        }
    }

    echo "✓ Migrations concluídas\n\n";
}

// ============================================================================
// SEEDS
// ============================================================================

if ($rodarSeed) {
    echo "Rodando seeds...\n";
    $arquivosSeed = glob(DATABASE_PATH . '/seeds/*.sql') ?: [];
    sort($arquivosSeed);

    if ($arquivosSeed === []) {
        echo "  Nenhum seed encontrado\n";
    }
    foreach ($arquivosSeed as $arquivo) {
        $nome = basename($arquivo);
        echo "  {$nome}... ";
        try {
            executarArquivoSql($pdo, $arquivo);
            echo "✓\n";
        } catch (Throwable $e) {
            echo "✗\n";
            echo "    Erro: " . $e->getMessage() . "\n";
            exit(1);
        }
    }
    echo "✓ Seeds concluídos\n\n";
}

// ============================================================================
// RESUMO
// ============================================================================

$totalTabelas = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ?');
$totalTabelas->execute([$dbConfig['database']]);
echo "Tabelas: " . $totalTabelas->fetchColumn() . "\n";

try {
    $admins = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
    echo "Administradores: {$admins}\n";
    if ($admins === 0) {
        echo "\nNenhum administrador ainda. Crie o primeiro com:\n  php bin/criar-admin.php\n";
    }
} catch (Throwable $e) {
    // tabela users ainda não existe (ex.: só --seed num banco vazio)
}

echo "\nPronto.\n";
