<?php

declare(strict_types=1);

// Derruba previews ao vivo ociosos (container + database + registro). Rodar via cron.
// Agendado no crontab a cada 15 minutos (ver instalacao). Para o teto de ociosidade use PREVIEW_IDLE_MINUTES.

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
(Dotenv\Dotenv::createImmutable($root))->safeLoad();

define('ROOT_PATH', $root);
define('SRC_PATH', $root . '/src');
define('CONFIG_PATH', $root . '/config');
define('STORAGE_PATH', $root . '/storage');

require_once SRC_PATH . '/Helpers/helpers.php';
require_once SRC_PATH . '/Helpers/uuid.php';
require_once SRC_PATH . '/Helpers/formatting.php';

$minutes = (int) (env('PREVIEW_IDLE_MINUTES') ?: 60);
$stopped = (new \Ravi\Services\PreviewService())->cleanupIdle($minutes);
echo date('c') . " preview cleanup: parou {$stopped} preview(s) ociosos (> {$minutes} min)\n";
