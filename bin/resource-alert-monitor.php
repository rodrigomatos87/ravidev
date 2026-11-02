#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Alerta de uso do servidor (disco/RAM). Roda a cada 5 minutos (cron) e, quando o uso do disco (/)
 * ou da memória passa do limite configurável (Configurações → Recursos, default 90%), dispara um
 * aviso aos administradores por WhatsApp + e-mail. Reavisa a cada 6h enquanto continuar acima e
 * manda um "normalizou" quando voltar. Histerese de 5 pontos evita ping-pong na borda do limite.
 *
 *   *\/5 * * * * cd /caminho/da/instalacao && php bin/resource-alert-monitor.php >> storage/logs/resource-alert.log 2>&1
 */

require __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

define('ROOT_PATH', dirname(__DIR__));
define('SRC_PATH', ROOT_PATH . '/src');
define('CONFIG_PATH', ROOT_PATH . '/config');
define('STORAGE_PATH', ROOT_PATH . '/storage');

require_once SRC_PATH . '/Helpers/helpers.php';
require_once SRC_PATH . '/Helpers/uuid.php';
require_once SRC_PATH . '/Helpers/formatting.php';

foreach (['app', 'database', 'claude', 'sandbox', 'tools', 'github'] as $cfg) {
    $cfgPath = CONFIG_PATH . '/' . $cfg . '.php';
    if (is_file($cfgPath)) { $GLOBALS['config'][$cfg] = require $cfgPath; }
}
\Ravi\Core\Logger::getInstance()->setLogPath(STORAGE_PATH . '/logs');
$app = \Ravi\Core\Application::create(ROOT_PATH);
$app->bind('logger', fn () => \Ravi\Core\Logger::getInstance());

use Ravi\Models\Setting;
use Ravi\Services\NotificationService;

const REPEAT_SECONDS = 6 * 3600; // reavisa a cada 6h enquanto seguir acima do limite
const HYSTERESIS     = 5;        // só considera "normalizou" abaixo de (limite - 5)

/** Uso do disco da raiz (/) em % e bytes livres. @return array{pct:int,free:int,total:int} */
function diskUsage(): array
{
    $total = (int) (@disk_total_space('/') ?: 0);
    $free  = (int) (@disk_free_space('/') ?: 0);
    $pct   = $total > 0 ? (int) round(($total - $free) / $total * 100) : 0;
    return ['pct' => $pct, 'free' => $free, 'total' => $total];
}

/** Uso de RAM em % e bytes usados/total (MemTotal - MemAvailable). @return array{pct:int,used:int,total:int} */
function ramUsage(): array
{
    $info = [];
    foreach (@file('/proc/meminfo') ?: [] as $line) {
        if (preg_match('/^(\w+):\s+(\d+)\s*kB/', $line, $m)) {
            $info[$m[1]] = (int) $m[2] * 1024;
        }
    }
    $total = $info['MemTotal'] ?? 0;
    $avail = $info['MemAvailable'] ?? ($info['MemFree'] ?? 0);
    $used  = max(0, $total - $avail);
    $pct   = $total > 0 ? (int) round($used / $total * 100) : 0;
    return ['pct' => $pct, 'used' => $used, 'total' => $total];
}

/** Formata bytes em GB legível (pt-BR). */
function gb(int $bytes): string
{
    return number_format($bytes / 1073741824, 1, ',', '.') . ' GB';
}

/**
 * Máquina de estados por métrica, persistida em app_settings:
 *   resource_alert_state_<metric> = 'ok' | 'firing'
 *   resource_alert_ts_<metric>    = epoch do último aviso enviado
 */
function evaluate(string $metric, int $pct, int $threshold, string $detail, NotificationService $notif): ?string
{
    $stateKey = 'resource_alert_state_' . $metric;
    $tsKey    = 'resource_alert_ts_' . $metric;
    $state    = Setting::get($stateKey, 'ok');
    $lastTs   = (int) Setting::get($tsKey, '0');
    $now      = time();

    if ($pct >= $threshold) {
        if ($state !== 'firing') {
            $notif->systemResourceAlert($metric, $pct, $threshold, $detail, false);
            Setting::setMany([$stateKey => 'firing', $tsKey => (string) $now]);
            return "ALERTA {$metric}={$pct}% (limite {$threshold}%)";
        }
        if ($now - $lastTs >= REPEAT_SECONDS) {
            $notif->systemResourceAlert($metric, $pct, $threshold, $detail, false);
            Setting::set($tsKey, (string) $now);
            return "REFORÇO {$metric}={$pct}% (limite {$threshold}%)";
        }
        return null; // já avisado e dentro da janela de silêncio
    }

    if ($pct <= $threshold - HYSTERESIS) {
        if ($state === 'firing') {
            $notif->systemResourceAlert($metric, $pct, $threshold, $detail, true);
            Setting::setMany([$stateKey => 'ok', $tsKey => (string) $now]);
            return "NORMALIZOU {$metric}={$pct}%";
        }
    }
    return null; // ok, ou dentro da faixa de histerese (não mexe no estado)
}

try {
    if (Setting::get('resource_alert_enabled', '1') !== '1') {
        exit(0);
    }
    $diskTh = max(50, min(99, (int) Setting::get('resource_alert_disk_pct', '90')));
    $ramTh  = max(50, min(99, (int) Setting::get('resource_alert_ram_pct', '90')));

    $disk = diskUsage();
    $ram  = ramUsage();
    $notif = new NotificationService();

    $logs = [];
    $r = evaluate('disk', $disk['pct'], $diskTh, 'Livre: ' . gb($disk['free']) . ' de ' . gb($disk['total']) . '.', $notif);
    if ($r) { $logs[] = $r; }
    $r = evaluate('ram', $ram['pct'], $ramTh, 'Em uso: ' . gb($ram['used']) . ' de ' . gb($ram['total']) . '.', $notif);
    if ($r) { $logs[] = $r; }

    if ($logs) {
        echo date('Y-m-d H:i:s') . ' — ' . implode(' | ', $logs) . "\n";
    }
} catch (\Throwable $e) {
    fwrite(STDERR, date('Y-m-d H:i:s') . ' — erro: ' . $e->getMessage() . "\n");
    exit(1);
}
