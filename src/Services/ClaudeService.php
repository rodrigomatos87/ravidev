<?php

declare(strict_types=1);

namespace Ravi\Services;

use Ravi\Core\Encryption;
use Ravi\Models\User;
use Ravi\Models\Conversation;
use Ravi\Models\Message;

/**
 * Serviço de integração com a API Claude da Anthropic
 */
class ClaudeService
{
    private string $apiKey;
    private string $model;
    private string $apiUrl = 'https://api.anthropic.com/v1/messages';
    private string $apiVersion = '2023-06-01';
    private int $maxTokens;
    private float $temperature;
    private array $tools = [];
    
    /**
     * @param string|null $userApiKey API key do usuário (criptografada) ou null para usar a padrão
     */
    public function __construct(?string $userApiKey = null)
    {
        $config = config('claude');
        
        // Usa API key do usuário se fornecida, senão usa a padrão
        if ($userApiKey) {
            // decrypt é método de instância; a chave vem de Encryption::chaveDoAmbiente()
            $this->apiKey = (new Encryption())->decrypt($userApiKey);
        } else {
            $this->apiKey = $config['api_key'];
        }
        
        $this->model = $config['model'];
        $this->maxTokens = $config['max_tokens'];
        $this->temperature = $config['temperature'];
        // A API da Anthropic espera uma lista indexada; config('tools.schemas') é associativo por nome
        $this->tools = array_values(config('tools.schemas') ?? []);
    }
    
    /**
     * Define o modelo a usar
     */
    public function setModel(string $model): self
    {
        $this->model = $model;
        return $this;
    }
    
    /**
     * Define max tokens
     */
    public function setMaxTokens(int $maxTokens): self
    {
        $this->maxTokens = $maxTokens;
        return $this;
    }
    
    /**
     * Define temperatura
     */
    public function setTemperature(float $temperature): self
    {
        $this->temperature = max(0, min(1, $temperature));
        return $this;
    }
    
    /**
     * Define ferramentas disponíveis
     */
    public function setTools(array $tools): self
    {
        $this->tools = $tools;
        return $this;
    }
    
    /**
     * Envia mensagem para a API Claude
     * 
     * @param array $messages Array de mensagens no formato da API
     * @param string|null $systemPrompt System prompt opcional
     * @param callable|null $onStream Callback para streaming (recebe chunks)
     * @return array Resposta da API
     */
    public function sendMessage(
        array $messages, 
        ?string $systemPrompt = null,
        ?callable $onStream = null
    ): array {
        $payload = [
            'model' => $this->model,
            'max_tokens' => $this->maxTokens,
            'messages' => $this->formatMessages($messages)
        ];
        
        if ($systemPrompt) {
            $payload['system'] = $systemPrompt;
        }
        
        if (!empty($this->tools)) {
            $payload['tools'] = $this->tools;
        }
        
        // Executa com retry/backoff em caso de rate limit (429) ou sobrecarga (529)
        $maxRetries = 5;
        $attempt = 0;

        while (true) {
            try {
                if ($onStream !== null) {
                    $payload['stream'] = true;
                    return $this->streamRequest($payload, $onStream);
                }

                return $this->makeRequest($payload);
            } catch (\RuntimeException $e) {
                $msg = $e->getMessage();
                $retryable = (strpos($msg, 'HTTP 429') !== false) || (strpos($msg, 'HTTP 529') !== false);

                if ($retryable && $attempt < $maxRetries) {
                    $attempt++;
                    // Backoff crescente (15s, 30s, 45s...) — respeita janela de tokens/minuto
                    $wait = min(60, 15 * $attempt);
                    if (function_exists('logger')) {
                        logger()->warning("Rate limit da API Claude; aguardando {$wait}s (tentativa {$attempt}/{$maxRetries})");
                    }
                    sleep($wait);
                    continue;
                }

                throw $e;
            }
        }
    }
    
    /**
     * Formata mensagens para o formato da API
     */
    private function formatMessages(array $messages): array
    {
        $formatted = [];
        
        foreach ($messages as $message) {
            if ($message instanceof Message) {
                $formatted[] = $message->toApiFormat();
            } elseif (is_array($message)) {
                $formatted[] = $message;
            }
        }
        
        return $formatted;
    }
    
    /**
     * Faz requisição normal (não streaming)
     */
    private function makeRequest(array $payload): array
    {
        $ch = curl_init($this->apiUrl);
        
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-api-key: ' . $this->apiKey,
                'anthropic-version: ' . $this->apiVersion
            ],
            CURLOPT_TIMEOUT => 300
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            throw new \RuntimeException("Erro de conexão com API Claude: {$error}");
        }
        
        $data = json_decode($response, true);
        
        if ($httpCode !== 200) {
            $errorMsg = $data['error']['message'] ?? 'Erro desconhecido';
            throw new \RuntimeException("Erro da API Claude ({$httpCode}): {$errorMsg}");
        }
        
        return $data;
    }
    
    /**
     * Faz requisição com streaming
     */
    private function streamRequest(array $payload, callable $onStream): array
    {
        $ch = curl_init($this->apiUrl);
        
        $fullResponse = [
            'id' => '',
            'type' => 'message',
            'role' => 'assistant',
            'content' => [],
            'model' => $this->model,
            'stop_reason' => null,
            'usage' => ['input_tokens' => 0, 'output_tokens' => 0]
        ];
        
        $currentContentBlock = null;
        $buffer = '';
        $rawBody = '';

        $writeCallback = function($ch, $data) use ($onStream, &$fullResponse, &$currentContentBlock, &$buffer, &$rawBody) {
            $rawBody .= $data;
            $buffer .= $data;
            
            // Processa eventos SSE completos
            while (($pos = strpos($buffer, "\n\n")) !== false) {
                $event = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 2);
                
                // Parse do evento SSE
                if (preg_match('/^data: (.+)$/m', $event, $matches)) {
                    $json = json_decode($matches[1], true);
                    
                    if ($json) {
                        $this->processStreamEvent($json, $fullResponse, $currentContentBlock, $onStream);
                    }
                }
            }
            
            return strlen($data);
        };
        
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-api-key: ' . $this->apiKey,
                'anthropic-version: ' . $this->apiVersion
            ],
            CURLOPT_WRITEFUNCTION => $writeCallback,
            CURLOPT_TIMEOUT => 300
        ]);
        
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            throw new \RuntimeException("Erro de conexão: {$error}");
        }
        
        if ($httpCode !== 200) {
            throw new \RuntimeException("Erro da API Claude: HTTP {$httpCode} - " . substr(trim($rawBody), 0, 600));
        }
        
        return $fullResponse;
    }
    
    /**
     * Processa evento de streaming
     */
    private function processStreamEvent(
        array $event, 
        array &$response, 
        &$currentBlock,
        callable $onStream
    ): void {
        switch ($event['type']) {
            case 'message_start':
                $response['id'] = $event['message']['id'] ?? '';
                $response['model'] = $event['message']['model'] ?? $this->model;
                if (isset($event['message']['usage'])) {
                    $response['usage']['input_tokens'] = $event['message']['usage']['input_tokens'] ?? 0;
                }
                break;
                
            case 'content_block_start':
                $currentBlock = $event['content_block'];
                $currentBlock['index'] = $event['index'];
                
                if ($currentBlock['type'] === 'tool_use') {
                    $currentBlock['input'] = '';
                }
                break;
                
            case 'content_block_delta':
                if ($currentBlock) {
                    $delta = $event['delta'];
                    
                    if ($delta['type'] === 'text_delta') {
                        $currentBlock['text'] = ($currentBlock['text'] ?? '') . $delta['text'];
                        $onStream([
                            'type' => 'text',
                            'text' => $delta['text']
                        ]);
                    } elseif ($delta['type'] === 'input_json_delta') {
                        $currentBlock['input'] .= $delta['partial_json'];
                    }
                }
                break;
                
            case 'content_block_stop':
                if ($currentBlock) {
                    if ($currentBlock['type'] === 'tool_use' && isset($currentBlock['input'])) {
                        $currentBlock['input'] = json_decode($currentBlock['input'], true) ?? [];
                        $onStream([
                            'type' => 'tool_use',
                            'id' => $currentBlock['id'],
                            'name' => $currentBlock['name'],
                            'input' => $currentBlock['input']
                        ]);
                    }
                    
                    unset($currentBlock['index']);
                    $response['content'][] = $currentBlock;
                    $currentBlock = null;
                }
                break;
                
            case 'message_delta':
                $response['stop_reason'] = $event['delta']['stop_reason'] ?? null;
                if (isset($event['usage'])) {
                    $response['usage']['output_tokens'] = $event['usage']['output_tokens'] ?? 0;
                }
                break;
                
            case 'message_stop':
                $onStream(['type' => 'done']);
                break;
        }
    }
    
    /**
     * Obtém o system prompt do agente
     */
    public function getAgentSystemPrompt(string $projectName = '', array $filesContext = []): string
    {
        $config = config('claude');
        $prompt = $config['system_prompt'];
        
        // Substitui variáveis
        $prompt = str_replace('{project_name}', $projectName, $prompt);
        $prompt = str_replace('{files_context}', implode("\n", $filesContext), $prompt);
        
        return $prompt;
    }
    
    /**
     * Calcula custo estimado em USD
     */
    public function estimateCost(int $inputTokens, int $outputTokens): float
    {
        // Preços aproximados (atualizar conforme pricing da Anthropic)
        // Preços por 1 milhão de tokens (USD)
        $prices = [
            // Modelos atuais (set/2026). Opus 5 e Opus 4.8 estão na mesma faixa ($5/$25 por milhão);
            // os $15/$75 antigos eram preço de Opus 3 e inflavam o custo estimado da geração 4.x.
            'claude-fable-5-1' => ['input' => 10.00, 'output' => 50.00],
            'claude-fable-5' => ['input' => 10.00, 'output' => 50.00],
            'claude-opus-5-5' => ['input' => 4.00, 'output' => 20.00],
            'claude-opus-5' => ['input' => 5.00, 'output' => 25.00],
            'claude-opus-4-8' => ['input' => 5.00, 'output' => 25.00],
            'claude-sonnet-5' => ['input' => 2.00, 'output' => 10.00],
            'claude-haiku-4-5-20251001' => ['input' => 1.00, 'output' => 5.00],
            // Modelos legados (mantidos para conversas/registros antigos)
            'claude-sonnet-4-6' => ['input' => 3.00, 'output' => 15.00],
            'claude-opus-4-5-20251101' => ['input' => 15.00, 'output' => 75.00],
            'claude-sonnet-4-5-20250929' => ['input' => 3.00, 'output' => 15.00],
            'claude-sonnet-4-20250514' => ['input' => 3.00, 'output' => 15.00],
            'claude-3-5-sonnet-20241022' => ['input' => 3.00, 'output' => 15.00],
            'claude-3-opus-20240229' => ['input' => 15.00, 'output' => 75.00],
            'claude-3-haiku-20240307' => ['input' => 0.25, 'output' => 1.25],
        ];

        $modelPrices = $prices[$this->model] ?? $prices['claude-sonnet-5'];
        
        // Preços são por 1M tokens
        $inputCost = ($inputTokens / 1_000_000) * $modelPrices['input'];
        $outputCost = ($outputTokens / 1_000_000) * $modelPrices['output'];
        
        return round($inputCost + $outputCost, 4);
    }
    
    /**
     * Verifica se a API key é válida
     */
    public function validateApiKey(): bool
    {
        try {
            $response = $this->sendMessage([
                ['role' => 'user', 'content' => 'Hi']
            ]);
            return isset($response['id']);
        } catch (\Exception $e) {
            return false;
        }
    }
}
