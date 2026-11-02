<?php

declare(strict_types=1);

// Gerencia IDEs (code-server) ociosas. Rodar via cron. Dois estágios:
//  - PAUSA (docker pause, preserva tudo) as rodando ociosas há mais de IDE_IDLE_MINUTES (default 120).
//    Ociosa = sem página /ide visível (o poll dela marca uso) e sem Claude/build rodando.
//  - REMOVE de vez as PAUSADAS há mais de IDE_REAP_DAYS (default 7) para liberar disco.

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

$minutes  = (int) (env('IDE_IDLE_MINUTES') ?: 120);
$reapDays = (int) (env('IDE_REAP_DAYS') ?: 7);
$res = (new \Ravi\Services\IdeService())->cleanupIdle($minutes, $reapDays);
echo date('c') . " ide cleanup: pausou {$res['paused']} ociosa(s) (> {$minutes} min), removeu {$res['reaped']} pausada(s) (> {$reapDays} dia(s))\n";
