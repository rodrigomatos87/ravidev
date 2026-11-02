<?php

declare(strict_types=1);

namespace Ravi\Controllers;

use Ravi\Core\Request;
use Ravi\Core\JsonResponse;
use Ravi\Core\Logger;

/**
 * Webhook Controller
 * Recebe callbacks de webhooks externos (GitHub)
 * 
 * NOTA: A implementação completa de processamento de pagamentos
 * e integração com GitHub será feita conforme necessidade do projeto.
 * Atualmente os webhooks são validados e logados para análise.
 */
class WebhookController extends Controller
{
    /**
     * Handle GitHub webhooks
     */
    public function github(Request $request): JsonResponse
    {
        $logger = Logger::getInstance();
        
        $payload = file_get_contents('php://input');
        $signature = $request->getHeader('X-Hub-Signature-256');
        $event = $request->getHeader('X-GitHub-Event');
        $webhookSecret = env('GITHUB_WEBHOOK_SECRET');
        
        // Sem segredo configurado não há como validar a origem: recusa em vez de aceitar às cegas.
        if (!$webhookSecret) {
            $logger->warning('GitHub webhook recusado: GITHUB_WEBHOOK_SECRET não configurado');
            return $this->jsonError('Webhook não configurado neste servidor', 403);
        }
        
        // Verifica assinatura
        $expectedSignature = 'sha256=' . hash_hmac('sha256', $payload, $webhookSecret);
        if (!hash_equals($expectedSignature, $signature ?? '')) {
            $logger->warning('GitHub webhook: assinatura inválida');
            return new JsonResponse(['error' => 'Invalid signature'], 400);
        }
        
        try {
            $data = json_decode($payload, true);
            
            if (!$data) {
                return new JsonResponse(['error' => 'Invalid payload'], 400);
            }
            
            $logger->info('GitHub webhook recebido', [
                'event' => $event,
                'repo' => $data['repository']['full_name'] ?? 'unknown',
                'delivery' => $request->getHeader('X-GitHub-Delivery')
            ]);
            
            // Processa eventos - por enquanto apenas loga
            $this->processGitHubEvent($event, $data);
            
            return $this->json(['received' => true]);
            
        } catch (\Exception $e) {
            $logger->error('GitHub webhook erro', [
                'error' => $e->getMessage()
            ]);
            
            return new JsonResponse(['error' => 'Processing failed'], 500);
        }
    }

    /**
     * Processa evento GitHub
     * Implementação básica - expanda conforme necessidade
     */
    private function processGitHubEvent(string $event, array $data): void
    {
        $logger = Logger::getInstance();
        
        switch ($event) {
            case 'push':
                $logger->info('GitHub: Push received', [
                    'repo' => $data['repository']['full_name'] ?? null,
                    'ref' => $data['ref'] ?? null,
                    'commits' => count($data['commits'] ?? [])
                ]);
                break;
                
            case 'pull_request':
                $logger->info('GitHub: Pull Request', [
                    'action' => $data['action'] ?? null,
                    'number' => $data['number'] ?? null,
                    'title' => $data['pull_request']['title'] ?? null
                ]);
                break;
                
            case 'issues':
                $logger->info('GitHub: Issue', [
                    'action' => $data['action'] ?? null,
                    'number' => $data['issue']['number'] ?? null,
                    'title' => $data['issue']['title'] ?? null
                ]);
                break;
                
            default:
                $logger->debug('GitHub: Evento não processado', ['event' => $event]);
        }
    }
}
