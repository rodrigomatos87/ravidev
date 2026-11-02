<?php

declare(strict_types=1);

/**
 * Ravi Developer - Application Entry Point
 */

// Bootstrap
$app = require __DIR__ . '/../src/bootstrap.php';

// Request
$request = \Ravi\Core\Request::capture();

// Router
$router = $app->get('router');

// Dispatch
try {
    $response = $router->dispatch($request);
    
    if ($response instanceof \Ravi\Core\Response) {
        $response->send();
    } elseif ($response instanceof \Ravi\Core\JsonResponse) {
        $response->send();
    } elseif (is_string($response)) {
        echo $response;
    } elseif (is_array($response)) {
        header('Content-Type: application/json');
        echo json_encode($response);
    }
    
} catch (\Ravi\Core\HttpException $e) {
    http_response_code($e->getStatusCode());
    
    if (isAjaxRequest()) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage(),
            'code' => $e->getStatusCode()
        ]);
    } else {
        $errorView = VIEWS_PATH . '/errors/' . $e->getStatusCode() . '.php';
        if (file_exists($errorView)) {
            $message = $e->getMessage();
            include $errorView;
        } else {
            echo '<h1>' . $e->getStatusCode() . ' - ' . $e->getMessage() . '</h1>';
        }
    }
    
} catch (\Throwable $e) {
    $logger = \Ravi\Core\Logger::getInstance();
    $logger->error('Unhandled exception', [
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ]);
    
    http_response_code(500);
    
    if (isAjaxRequest()) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => env('APP_DEBUG', false) ? $e->getMessage() : 'Internal server error',
            'code' => 500
        ]);
    } else {
        if (env('APP_DEBUG', false)) {
            echo '<h1>Error</h1>';
            echo '<p><strong>' . htmlspecialchars($e->getMessage()) . '</strong></p>';
            echo '<p>File: ' . htmlspecialchars($e->getFile()) . ':' . $e->getLine() . '</p>';
            echo '<pre>' . htmlspecialchars($e->getTraceAsString()) . '</pre>';
        } else {
            $errorView = VIEWS_PATH . '/errors/500.php';
            if (file_exists($errorView)) {
                include $errorView;
            } else {
                echo '<h1>500 - Internal Server Error</h1>';
            }
        }
    }
}
