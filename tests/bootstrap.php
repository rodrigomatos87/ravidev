<?php

declare(strict_types=1);

/**
 * Bootstrap dos testes.
 *
 * Não passa pelo src/bootstrap.php de propósito: aquele arquivo carrega o .env, liga handlers
 * globais de erro, abre sessão e conecta no banco. Aqui só existe o mínimo para as classes
 * do núcleo funcionarem isoladas: autoload do composer, helpers globais e as constantes de caminho.
 */

$raiz = dirname(__DIR__);

require $raiz . '/vendor/autoload.php';

// O composer já inclui os helpers (autoload "files"); require_once garante o mesmo se um dia sair de lá.
require_once $raiz . '/src/Helpers/helpers.php';
require_once $raiz . '/src/Helpers/uuid.php';
require_once $raiz . '/src/Helpers/formatting.php';

// Pasta temporária própria da execução: logs e arquivos gerados nos testes nunca caem em storage/.
$temporario = sys_get_temp_dir() . '/ravi-testes-' . getmypid();
if (!is_dir($temporario)) {
    mkdir($temporario, 0700, true);
}

$constantes = [
    'ROOT_PATH' => $raiz,
    'SRC_PATH' => $raiz . '/src',
    'CONFIG_PATH' => $raiz . '/config',
    'VIEWS_PATH' => $raiz . '/views',
    'STORAGE_PATH' => $temporario . '/storage',
    'PUBLIC_PATH' => $raiz . '/public',
    'RAVI_VERSION' => '1.0.0',
    'RAVI_START_TIME' => microtime(true),
    'RAVI_TESTES_TMP' => $temporario,
];
foreach ($constantes as $nome => $valor) {
    if (!defined($nome)) {
        define($nome, $valor);
    }
}

date_default_timezone_set('America/Sao_Paulo');

// Limpa a pasta temporária ao fim da execução.
register_shutdown_function(static function () use ($temporario): void {
    if (!is_dir($temporario)) {
        return;
    }
    $itens = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($temporario, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($itens as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($temporario);
});
