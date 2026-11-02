<?php

declare(strict_types=1);

namespace Ravi\WebSocket;

use Ratchet\ConnectionInterface;
use SplObjectStorage;

/**
 * Gerenciador de conexões WebSocket
 */
class ConnectionManager
{
    private SplObjectStorage $connections;
    private array $userConnections = [];
    private array $conversationConnections = [];
    private array $metadata = [];
    
    public function __construct()
    {
        $this->connections = new SplObjectStorage();
    }
    
    /**
     * Adiciona conexão
     */
    public function add(ConnectionInterface $conn): void
    {
        $this->connections->attach($conn);
        $this->metadata[$conn->resourceId] = [
            'connected_at' => time(),
            'last_activity' => time(),
            'user_id' => null,
            'conversation_id' => null
        ];
    }
    
    /**
     * Remove conexão
     */
    public function remove(ConnectionInterface $conn): void
    {
        $resourceId = $conn->resourceId;
        
        // Remove de user connections
        if (isset($this->metadata[$resourceId]['user_id'])) {
            $userId = $this->metadata[$resourceId]['user_id'];
            unset($this->userConnections[$userId][$resourceId]);
            if (empty($this->userConnections[$userId])) {
                unset($this->userConnections[$userId]);
            }
        }
        
        // Remove de conversation connections
        if (isset($this->metadata[$resourceId]['conversation_id'])) {
            $convId = $this->metadata[$resourceId]['conversation_id'];
            unset($this->conversationConnections[$convId][$resourceId]);
            if (empty($this->conversationConnections[$convId])) {
                unset($this->conversationConnections[$convId]);
            }
        }
        
        $this->connections->detach($conn);
        unset($this->metadata[$resourceId]);
    }
    
    /**
     * Autentica conexão
     */
    public function authenticate(ConnectionInterface $conn, int $userId): void
    {
        $resourceId = $conn->resourceId;
        
        $this->metadata[$resourceId]['user_id'] = $userId;
        
        if (!isset($this->userConnections[$userId])) {
            $this->userConnections[$userId] = [];
        }
        $this->userConnections[$userId][$resourceId] = $conn;
    }
    
    /**
     * Associa conexão a uma conversa
     */
    public function joinConversation(ConnectionInterface $conn, int $conversationId): void
    {
        $resourceId = $conn->resourceId;
        
        // Remove de conversa anterior se houver
        if (isset($this->metadata[$resourceId]['conversation_id'])) {
            $oldConvId = $this->metadata[$resourceId]['conversation_id'];
            unset($this->conversationConnections[$oldConvId][$resourceId]);
        }
        
        $this->metadata[$resourceId]['conversation_id'] = $conversationId;
        
        if (!isset($this->conversationConnections[$conversationId])) {
            $this->conversationConnections[$conversationId] = [];
        }
        $this->conversationConnections[$conversationId][$resourceId] = $conn;
    }
    
    /**
     * IDs das conversas que têm pelo menos uma conexão ativa
     */
    public function getActiveConversationIds(): array
    {
        $ids = [];
        foreach ($this->conversationConnections as $convId => $conns) {
            if (!empty($conns)) {
                $ids[] = (int) $convId;
            }
        }
        return $ids;
    }

    /**
     * Remove conexão de conversa
     */
    public function leaveConversation(ConnectionInterface $conn): void
    {
        $resourceId = $conn->resourceId;
        
        if (isset($this->metadata[$resourceId]['conversation_id'])) {
            $convId = $this->metadata[$resourceId]['conversation_id'];
            unset($this->conversationConnections[$convId][$resourceId]);
            $this->metadata[$resourceId]['conversation_id'] = null;
        }
    }
    
    /**
     * Atualiza atividade
     */
    public function touch(ConnectionInterface $conn): void
    {
        if (isset($this->metadata[$conn->resourceId])) {
            $this->metadata[$conn->resourceId]['last_activity'] = time();
        }
    }
    
    /**
     * Broadcast para todos
     */
    public function broadcast(array $data, ?ConnectionInterface $except = null): void
    {
        $json = json_encode($data);
        
        foreach ($this->connections as $conn) {
            if ($except && $conn === $except) {
                continue;
            }
            $conn->send($json);
        }
    }
    
    /**
     * Envia para usuário específico
     */
    public function sendToUser(int $userId, array $data): void
    {
        if (!isset($this->userConnections[$userId])) {
            return;
        }
        
        $json = json_encode($data);
        
        foreach ($this->userConnections[$userId] as $conn) {
            $conn->send($json);
        }
    }
    
    /**
     * Envia para conexões de uma conversa
     */
    public function sendToConversation(int $conversationId, array $data): void
    {
        if (!isset($this->conversationConnections[$conversationId])) {
            return;
        }
        
        $json = json_encode($data);
        
        foreach ($this->conversationConnections[$conversationId] as $conn) {
            $conn->send($json);
        }
    }
    
    /**
     * Obtém conexão por ID
     */
    public function get(int $resourceId): ?ConnectionInterface
    {
        foreach ($this->connections as $conn) {
            if ($conn->resourceId === $resourceId) {
                return $conn;
            }
        }
        return null;
    }
    
    /**
     * Obtém todas as conexões
     */
    public function all(): SplObjectStorage
    {
        return $this->connections;
    }
    
    /**
     * Conta conexões
     */
    public function count(): int
    {
        return $this->connections->count();
    }
    
    /**
     * Conta conexões de um usuário
     */
    public function countUserConnections(int $userId): int
    {
        return count($this->userConnections[$userId] ?? []);
    }
    
    /**
     * Obtém metadata de uma conexão
     */
    public function getMetadata(ConnectionInterface $conn): ?array
    {
        return $this->metadata[$conn->resourceId] ?? null;
    }
    
    /**
     * Limpa conexões inativas
     */
    public function cleanupStale(int $maxIdleSeconds = 300): int
    {
        $removed = 0;
        $now = time();
        
        foreach ($this->connections as $conn) {
            $meta = $this->metadata[$conn->resourceId] ?? null;
            
            if ($meta && ($now - $meta['last_activity']) > $maxIdleSeconds) {
                $conn->close();
                $this->remove($conn);
                $removed++;
            }
        }
        
        return $removed;
    }
    
    /**
     * Verifica se usuário está online
     */
    public function isUserOnline(int $userId): bool
    {
        return !empty($this->userConnections[$userId]);
    }
    
    /**
     * Lista usuários online
     */
    public function getOnlineUsers(): array
    {
        return array_keys($this->userConnections);
    }
}
