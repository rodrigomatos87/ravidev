<?php

declare(strict_types=1);

namespace Ravi\Core;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Conexão PDO Singleton
 */
class Database
{
    private static ?self $instance = null;
    private ?PDO $pdo = null;
    
    private function __construct()
    {
        $this->connect();
    }
    
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        
        return self::$instance;
    }
    
    private function connect(): void
    {
        $config = require __DIR__ . '/../../config/database.php';
        $connection = $config['default'];
        $settings = $config['connections'][$connection] ?? null;
        
        if ($settings === null) {
            throw new RuntimeException("Database connection '{$connection}' not configured");
        }
        
        try {
            $dsn = $this->buildDsn($settings);
            
            $this->pdo = new PDO(
                $dsn,
                $settings['username'] ?? null,
                $settings['password'] ?? null,
                $settings['options'] ?? []
            );
        } catch (PDOException $e) {
            throw new RuntimeException("Database connection failed: {$e->getMessage()}");
        }
    }
    
    private function buildDsn(array $settings): string
    {
        return match ($settings['driver']) {
            'mysql' => sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                $settings['host'],
                $settings['port'],
                $settings['database'],
                $settings['charset'] ?? 'utf8mb4'
            ),
            'pgsql' => sprintf(
                'pgsql:host=%s;port=%s;dbname=%s',
                $settings['host'],
                $settings['port'],
                $settings['database']
            ),
            'sqlite' => sprintf('sqlite:%s', $settings['database']),
            default => throw new RuntimeException("Unsupported database driver: {$settings['driver']}"),
        };
    }
    
    public function getPdo(): PDO
    {
        return $this->pdo;
    }
    
    /**
     * Executa query preparada e retorna statement
     */
    public function query(string $sql, array $params = []): \PDOStatement
    {
        // PDO (emulated prepares) converte bool false em '' — inválido para colunas INT/BOOLEAN.
        // Normaliza booleanos para 0/1.
        foreach ($params as $key => $value) {
            if (is_bool($value)) {
                $params[$key] = $value ? 1 : 0;
            }
        }

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (\PDOException $e) {
            // Reconecta se o MySQL "foi embora" (processos longos: WebSocket/queue worker)
            if ($this->isConnectionLost($e)) {
                $this->connect();
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute($params);
                return $stmt;
            }
            throw $e;
        }
    }

    /**
     * Detecta perda de conexão (idle timeout) para reconectar
     */
    private function isConnectionLost(\PDOException $e): bool
    {
        $code = $e->errorInfo[1] ?? null;
        $msg = $e->getMessage();

        return in_array($code, [2006, 2013], true)
            || stripos($msg, 'gone away') !== false
            || stripos($msg, 'Lost connection') !== false;
    }

    /**
     * Executa um statement (INSERT/UPDATE/DELETE) e retorna o nº de linhas afetadas.
     * Alias usado em diversos pontos do código.
     */
    public function execute(string $sql, array $params = []): int
    {
        return $this->query($sql, $params)->rowCount();
    }

    /**
     * Busca um único registro (alias de fetch()).
     */
    public function queryOne(string $sql, array $params = []): ?array
    {
        return $this->fetch($sql, $params);
    }

    /**
     * Busca todos os registros
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }
    
    /**
     * Busca um registro
     */
    public function fetch(string $sql, array $params = []): ?array
    {
        $result = $this->query($sql, $params)->fetch();
        
        return $result !== false ? $result : null;
    }
    
    /**
     * Busca valor de uma coluna
     */
    public function fetchColumn(string $sql, array $params = [], int $column = 0): mixed
    {
        return $this->query($sql, $params)->fetchColumn($column);
    }
    
    /**
     * Executa INSERT e retorna ID
     */
    public function insert(string $table, array $data): int|string
    {
        $columns = array_keys($data);
        $placeholders = array_map(fn($col) => ":{$col}", $columns);
        
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $columns),
            implode(', ', $placeholders)
        );
        
        $this->query($sql, $data);
        
        return $this->pdo->lastInsertId();
    }
    
    /**
     * Executa UPDATE
     */
    public function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $sets = array_map(fn($col) => "{$col} = :{$col}", array_keys($data));
        
        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s',
            $table,
            implode(', ', $sets),
            $where
        );
        
        $stmt = $this->query($sql, array_merge($data, $whereParams));
        
        return $stmt->rowCount();
    }
    
    /**
     * Executa DELETE
     */
    public function delete(string $table, string $where, array $params = []): int
    {
        $sql = sprintf('DELETE FROM %s WHERE %s', $table, $where);
        $stmt = $this->query($sql, $params);
        
        return $stmt->rowCount();
    }
    
    /**
     * Inicia transação
     */
    public function beginTransaction(): bool
    {
        return $this->pdo->beginTransaction();
    }
    
    /**
     * Confirma transação
     */
    public function commit(): bool
    {
        return $this->pdo->commit();
    }
    
    /**
     * Desfaz transação
     */
    public function rollBack(): bool
    {
        return $this->pdo->rollBack();
    }
    
    /**
     * Executa callback dentro de transação
     */
    public function transaction(callable $callback): mixed
    {
        $this->beginTransaction();
        
        try {
            $result = $callback($this);
            $this->commit();
            
            return $result;
        } catch (\Throwable $e) {
            $this->rollBack();
            throw $e;
        }
    }
    
    /**
     * Escapa identificador (tabela/coluna)
     */
    public function escapeIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}
