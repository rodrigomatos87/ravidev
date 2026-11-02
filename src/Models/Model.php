<?php

declare(strict_types=1);

namespace Ravi\Models;

use Ravi\Core\Database;
use PDO;

/**
 * Base Model com métodos CRUD comuns
 */
abstract class Model
{
    protected static string $table;
    protected static string $primaryKey = 'id';

    /** Define se a tabela possui coluna updated_at (mensagens são append-only e não têm) */
    protected static bool $usesUpdatedAt = true;
    protected static array $fillable = [];
    protected static array $hidden = [];
    protected static array $casts = [];
    
    protected array $attributes = [];
    protected array $original = [];
    protected bool $exists = false;
    
    public function __construct(array $attributes = [])
    {
        $this->fill($attributes);
    }
    
    protected static function db(): Database
    {
        return Database::getInstance();
    }
    
    protected static function query(): QueryBuilder
    {
        return new QueryBuilder(static::$table, static::class);
    }
    
    // ========================================
    // CRUD estático
    // ========================================
    
    public static function all(): array
    {
        return static::query()->get();
    }
    
    public static function find(int|string $id): ?static
    {
        return static::query()
            ->where(static::$primaryKey, '=', $id)
            ->first();
    }
    
    public static function findOrFail(int|string $id): static
    {
        $model = static::find($id);
        
        if ($model === null) {
            throw new \RuntimeException(static::class . " with ID {$id} not found");
        }
        
        return $model;
    }
    
    public static function findBy(string $column, mixed $value): ?static
    {
        return static::query()
            ->where($column, '=', $value)
            ->first();
    }
    
    public static function where(string $column, string $operator, mixed $value): QueryBuilder
    {
        return static::query()->where($column, $operator, $value);
    }

    public static function whereIn(string $column, array $values): QueryBuilder
    {
        return static::query()->whereIn($column, $values);
    }

    public static function whereNull(string $column): QueryBuilder
    {
        return static::query()->whereNull($column);
    }

    public static function whereNotNull(string $column): QueryBuilder
    {
        return static::query()->whereNotNull($column);
    }

    public static function orderBy(string $column, string $direction = 'ASC'): QueryBuilder
    {
        return static::query()->orderBy($column, $direction);
    }

    public static function create(array $attributes): static
    {
        $model = new static($attributes);
        $model->save();
        
        return $model;
    }
    
    // ========================================
    // Instância
    // ========================================
    
    public function fill(array $attributes): self
    {
        foreach ($attributes as $key => $value) {
            if (empty(static::$fillable) || in_array($key, static::$fillable)) {
                $this->setAttribute($key, $value);
            }
        }
        
        return $this;
    }
    
    public function setAttribute(string $key, mixed $value): self
    {
        $this->attributes[$key] = $value;
        return $this;
    }
    
    public function getAttribute(string $key): mixed
    {
        $value = $this->attributes[$key] ?? null;

        // Aplica cast se definido
        if (isset(static::$casts[$key]) && $value !== null) {
            $value = $this->castAttribute($key, $value);
        }

        // A primary key (id) sempre é int — o PDO retorna colunas como string por padrão
        if ($key === static::$primaryKey && is_numeric($value)) {
            $value = (int) $value;
        }

        return $value;
    }
    
    protected function castAttribute(string $key, mixed $value): mixed
    {
        return match (static::$casts[$key]) {
            'int', 'integer' => (int) $value,
            'float', 'double' => (float) $value,
            'bool', 'boolean' => (bool) $value,
            'string' => (string) $value,
            'array' => is_array($value) ? $value : json_decode($value, true) ?? [],
            'json' => is_string($value) ? json_decode($value, true) : $value,
            'datetime' => $value instanceof \DateTimeInterface ? $value : new \DateTimeImmutable($value),
            default => $value,
        };
    }
    
    public function __get(string $key): mixed
    {
        return $this->getAttribute($key);
    }
    
    public function __set(string $key, mixed $value): void
    {
        $this->setAttribute($key, $value);
    }
    
    public function __isset(string $key): bool
    {
        return isset($this->attributes[$key]);
    }
    
    public function save(): bool
    {
        if ($this->exists) {
            return $this->performUpdate();
        }
        
        return $this->performInsert();
    }
    
    protected function performInsert(): bool
    {
        // Gera UUID se tiver campo uuid
        if (in_array('uuid', static::$fillable) || isset($this->attributes['uuid'])) {
            if (empty($this->attributes['uuid'])) {
                $this->attributes['uuid'] = uuid();
            }
        }
        
        // Timestamps
        $now = date('Y-m-d H:i:s');
        if (!isset($this->attributes['created_at'])) {
            $this->attributes['created_at'] = $now;
        }
        if (static::$usesUpdatedAt && !isset($this->attributes['updated_at'])) {
            $this->attributes['updated_at'] = $now;
        }
        
        $id = static::db()->insert(static::$table, $this->attributes);
        
        $this->attributes[static::$primaryKey] = $id;
        $this->original = $this->attributes;
        $this->exists = true;
        
        return true;
    }
    
    protected function performUpdate(): bool
    {
        $dirty = $this->getDirty();
        
        if (empty($dirty)) {
            return true;
        }
        
        if (static::$usesUpdatedAt) {
            $dirty['updated_at'] = date('Y-m-d H:i:s');
        }

        $pk = static::$primaryKey;
        $id = $this->attributes[$pk];
        
        static::db()->update(
            static::$table,
            $dirty,
            "{$pk} = :_pk",
            ['_pk' => $id]
        );
        
        $this->original = $this->attributes;
        
        return true;
    }
    
    public function delete(): bool
    {
        if (!$this->exists) {
            return false;
        }
        
        $pk = static::$primaryKey;
        $id = $this->attributes[$pk];
        
        static::db()->delete(
            static::$table,
            "{$pk} = :_pk",
            ['_pk' => $id]
        );
        
        $this->exists = false;

        return true;
    }

    /**
     * Atualiza atributos e salva (Active Record)
     */
    public function update(array $attributes): bool
    {
        $this->fill($attributes);
        return $this->save();
    }

    public function getDirty(): array
    {
        $dirty = [];
        
        foreach ($this->attributes as $key => $value) {
            if (!array_key_exists($key, $this->original) || $this->original[$key] !== $value) {
                $dirty[$key] = $value;
            }
        }
        
        return $dirty;
    }
    
    public function isDirty(?string $key = null): bool
    {
        if ($key === null) {
            return !empty($this->getDirty());
        }
        
        return array_key_exists($key, $this->getDirty());
    }
    
    public function refresh(): self
    {
        $fresh = static::find($this->attributes[static::$primaryKey]);
        
        if ($fresh !== null) {
            $this->attributes = $fresh->attributes;
            $this->original = $fresh->original;
        }
        
        return $this;
    }
    
    public function toArray(): array
    {
        $array = $this->attributes;
        
        // Remove campos hidden
        foreach (static::$hidden as $key) {
            unset($array[$key]);
        }
        
        return $array;
    }
    
    public function toJson(int $flags = 0): string
    {
        return json_encode($this->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | $flags);
    }
    
    /**
     * Preenche model a partir de resultado do banco
     */
    public static function hydrate(array $attributes): static
    {
        $model = new static();
        $model->attributes = $attributes;
        $model->original = $attributes;
        $model->exists = true;
        
        return $model;
    }
    
    /**
     * Hidrata múltiplos resultados
     */
    public static function hydrateMany(array $results): array
    {
        return array_map(fn($row) => static::hydrate($row), $results);
    }
}

/**
 * Query Builder simplificado
 */
class QueryBuilder
{
    private string $table;
    private string $modelClass;
    private array $wheres = [];
    private array $bindings = [];
    private ?string $orderBy = null;
    private ?int $limit = null;
    private ?int $offset = null;
    private array $select = ['*'];
    
    public function __construct(string $table, string $modelClass)
    {
        $this->table = $table;
        $this->modelClass = $modelClass;
    }
    
    public function select(array|string $columns): self
    {
        $this->select = is_array($columns) ? $columns : func_get_args();
        return $this;
    }
    
    public function where(string $column, string $operator, mixed $value): self
    {
        $placeholder = ':w' . count($this->bindings);
        $this->wheres[] = "{$column} {$operator} {$placeholder}";
        $this->bindings[$placeholder] = $value;
        
        return $this;
    }
    
    public function whereIn(string $column, array $values): self
    {
        if (empty($values)) {
            $this->wheres[] = '1 = 0'; // Sempre false
            return $this;
        }
        
        $placeholders = [];
        foreach ($values as $i => $value) {
            $key = ':win' . count($this->bindings);
            $placeholders[] = $key;
            $this->bindings[$key] = $value;
        }
        
        $this->wheres[] = "{$column} IN (" . implode(', ', $placeholders) . ')';
        
        return $this;
    }
    
    public function whereNull(string $column): self
    {
        $this->wheres[] = "{$column} IS NULL";
        return $this;
    }
    
    public function whereNotNull(string $column): self
    {
        $this->wheres[] = "{$column} IS NOT NULL";
        return $this;
    }
    
    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $this->orderBy = "{$column} {$direction}";
        return $this;
    }
    
    public function limit(int $limit): self
    {
        $this->limit = $limit;
        return $this;
    }
    
    public function offset(int $offset): self
    {
        $this->offset = $offset;
        return $this;
    }
    
    public function get(): array
    {
        $sql = $this->buildSelect();
        $results = Database::getInstance()->fetchAll($sql, $this->bindings);
        
        return ($this->modelClass)::hydrateMany($results);
    }
    
    public function first(): ?object
    {
        $this->limit(1);
        $results = $this->get();
        
        return $results[0] ?? null;
    }
    
    public function count(): int
    {
        $this->select = ['COUNT(*) as count'];
        $sql = $this->buildSelect();
        
        return (int) Database::getInstance()->fetchColumn($sql, $this->bindings);
    }
    
    public function exists(): bool
    {
        return $this->count() > 0;
    }
    
    public function pluck(string $column): array
    {
        $this->select = [$column];
        $sql = $this->buildSelect();
        $results = Database::getInstance()->fetchAll($sql, $this->bindings);
        
        return array_column($results, $column);
    }
    
    private function buildSelect(): string
    {
        $columns = implode(', ', $this->select);
        $sql = "SELECT {$columns} FROM {$this->table}";
        
        if (!empty($this->wheres)) {
            $sql .= ' WHERE ' . implode(' AND ', $this->wheres);
        }
        
        if ($this->orderBy !== null) {
            $sql .= " ORDER BY {$this->orderBy}";
        }
        
        if ($this->limit !== null) {
            $sql .= " LIMIT {$this->limit}";
        }
        
        if ($this->offset !== null) {
            $sql .= " OFFSET {$this->offset}";
        }
        
        return $sql;
    }
    
    public function delete(): int
    {
        $sql = "DELETE FROM {$this->table}";
        
        if (!empty($this->wheres)) {
            $sql .= ' WHERE ' . implode(' AND ', $this->wheres);
        }
        
        $stmt = Database::getInstance()->query($sql, $this->bindings);
        
        return $stmt->rowCount();
    }
    
    public function update(array $data): int
    {
        $sets = [];
        $updateBindings = [];
        
        foreach ($data as $column => $value) {
            $key = ':u_' . $column;
            $sets[] = "{$column} = {$key}";
            $updateBindings[$key] = $value;
        }
        
        $sql = "UPDATE {$this->table} SET " . implode(', ', $sets);
        
        if (!empty($this->wheres)) {
            $sql .= ' WHERE ' . implode(' AND ', $this->wheres);
        }
        
        $stmt = Database::getInstance()->query($sql, array_merge($updateBindings, $this->bindings));
        
        return $stmt->rowCount();
    }
}
