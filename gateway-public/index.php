<?php

declare(strict_types=1);

/**
 * Front controller ISOLADO do Gateway de API (subdomínio próprio da API, ex.: api.seu-dominio.com.br).
 *
 * Propositalmente NÃO carrega o bootstrap do app (sem Session/CSRF/Router/middleware).
 * Só: autoload (que já traz os helpers via composer "files"), .env e a constante de storage.
 * Toda a lógica fica em Ravi\Gateway\GatewayController.
 */

require __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

if (!defined('STORAGE_PATH')) {
    define('STORAGE_PATH', dirname(__DIR__) . '/storage');
}

// Anexos (imagens/PDF) chegam em base64: um corpo de ~27MB vira corpo cru +
// array decodificado + binários ao mesmo tempo. Folga LOCAL, só neste front.
ini_set('memory_limit', '256M');

// Chamada longa (x-ravi-tempo-limite, até GW_TEMPO_TETO = 600 s): no Linux o
// max_execution_time conta CPU, não parede, e a espera pelo container não pesa;
// mesmo assim o teto deste front fica explícito acima do teto da chamada.
set_time_limit(max(700, (int) env('GW_TEMPO_TETO', 600) + 100));

header('Content-Type: application/json; charset=utf-8');

try {
    $method  = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $path    = $_SERVER['REQUEST_URI'] ?? '/';
    $apiKey  = $_SERVER['HTTP_X_API_KEY'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    $rawBody = file_get_contents('php://input') ?: '';

    $result = (new \Ravi\Gateway\GatewayController())->dispatch($method, $path, (string) $apiKey, $rawBody);

    http_response_code((int) ($result['http'] ?? 500));
    echo json_encode($result['json'] ?? [], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (\Throwable $e) {
    error_log('[gateway] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode([
        'type'  => 'error',
        'error' => ['type' => 'api_error', 'message' => 'Erro interno do gateway.'],
    ], JSON_UNESCAPED_UNICODE);
}
