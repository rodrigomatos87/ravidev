<?php

declare(strict_types=1);

namespace Ravi\WebSocket;

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use React\EventLoop\LoopInterface;

/**
 * Servidor WebSocket para comunicação em tempo real
 */
class WebSocketServer implements MessageComponentInterface
{
    protected ConnectionManager $connections;
    protected ChatHandler $chatHandler;
    protected LoopInterface $loop;
    
    public function __construct(?ConnectionManager $connections = null)
    {
        // Usa o ConnectionManager fornecido (ex.: por bin/websocket-server.php) ou cria um próprio
        $this->connections = $connections ?? new ConnectionManager();
        $this->chatHandler = new ChatHandler($this->connections);
    }
    
    /**
     * Define o event loop
     */
    public function setLoop(LoopInterface $loop): void
    {
        $this->loop = $loop;
        $this->chatHandler->setLoop($loop);
    }
    
    /**
     * Nova conexão
     */
    public function onOpen(ConnectionInterface $conn): void
    {
        $this->connections->add($conn);
        
        echo "[+] Nova conexão: {$conn->resourceId}\n";
        
        // Envia mensagem de boas-vindas
        $conn->send(json_encode([
            'type' => 'connected',
            'connection_id' => $conn->resourceId,
            'message' => 'Conectado ao Ravi Developer'
        ]));
    }
    
    /**
     * Mensagem recebida
     */
    public function onMessage(ConnectionInterface $from, $msg): void
    {
        echo "[M] Mensagem de {$from->resourceId}: " . substr($msg, 0, 100) . "\n";
        
        try {
            $data = json_decode($msg, true);
            
            if (!$data || !isset($data['type'])) {
                $from->send(json_encode([
                    'type' => 'error',
                    'message' => 'Formato de mensagem inválido'
                ]));
                return;
            }
            
            // Processa mensagem pelo handler
            $this->chatHandler->handle($from, $data);
            
        } catch (\Exception $e) {
            echo "[E] Erro: {$e->getMessage()}\n";
            
            $from->send(json_encode([
                'type' => 'error',
                'message' => 'Erro interno: ' . $e->getMessage()
            ]));
        }
    }
    
    /**
     * Conexão fechada
     */
    public function onClose(ConnectionInterface $conn): void
    {
        try { $this->chatHandler->onClose($conn); } catch (\Throwable $e) { /* nunca derruba o loop */ }
        $this->connections->remove($conn);
        echo "[-] Conexão fechada: {$conn->resourceId}\n";
    }
    
    /**
     * Erro na conexão
     */
    public function onError(ConnectionInterface $conn, \Exception $e): void
    {
        echo "[E] Erro na conexão {$conn->resourceId}: {$e->getMessage()}\n";
        $conn->close();
    }
    
    /**
     * Limpeza periódica
     */
    public function cleanup(): void
    {
        $removed = $this->connections->cleanupStale();
        if ($removed > 0) {
            echo "[*] Limpeza: {$removed} conexões removidas\n";
        }
    }
    
    /**
     * Broadcast para todos os clientes
     */
    public function broadcast(array $data, ?ConnectionInterface $except = null): void
    {
        $this->connections->broadcast($data, $except);
    }

    /**
     * Drena eventos do agente (gerados pelo queue-worker em agent_events)
     * e os entrega aos clientes conectados. Chamado por um timer do event loop.
     */
    public function drainAgentEvents(): void
    {
        $convIds = $this->connections->getActiveConversationIds();
        if (empty($convIds)) {
            return;
        }

        $db = \Ravi\Core\Database::getInstance();

        foreach ($convIds as $cid) {
            try {
                $rows = $db->fetchAll(
                    "SELECT id, payload FROM agent_events WHERE conversation_id = ? ORDER BY id ASC LIMIT 300",
                    [$cid]
                );
                if (empty($rows)) {
                    continue;
                }

                $maxId = 0;
                foreach ($rows as $row) {
                    $data = json_decode($row['payload'], true);
                    if (is_array($data)) {
                        $this->connections->sendToConversation((int) $cid, $data);
                    }
                    $maxId = max($maxId, (int) $row['id']);
                }

                $db->query("DELETE FROM agent_events WHERE conversation_id = ? AND id <= ?", [$cid, $maxId]);
            } catch (\Throwable $e) {
                // nunca derruba o event loop por causa de um evento
            }
        }
    }
    
    /**
     * Tempo do Dev autonomo: a cada segundo, para cada conversa ATIVA que esteja 'running',
     * incrementa work_seconds no banco (persistencia por segundo, nunca perde) e transmite o
     * total ao chat. O worker fica bloqueado no build, entao quem conta o tempo e este loop.
     */
    public function tickWorkSeconds(): void
    {
        try {
            $db = \Ravi\Core\Database::getInstance();
            $rows = $db->fetchAll("SELECT id, work_seconds FROM conversations WHERE status = 'running'");
            foreach ($rows as $row) {
                $cid = (int) $row['id'];
                $sec = (int) ($row['work_seconds'] ?? 0) + 1;
                $db->query('UPDATE conversations SET work_seconds = ? WHERE id = ?', [$sec, $cid]);
                $this->connections->sendToConversation($cid, ['type' => 'work_time', 'seconds' => $sec]);
            }
        } catch (\Throwable $e) { /* nunca derruba o loop */ }
    }

    /**
     * Obtém connection manager
     */
    public function getConnections(): ConnectionManager
    {
        return $this->connections;
    }
}
