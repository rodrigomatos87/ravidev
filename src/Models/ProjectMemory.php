<?php

declare(strict_types=1);

namespace Ravi\Models;

/**
 * Memória permanente do projeto — um arquivo de instrução (markdown) que o Claude Code passa a
 * usar por padrão em TODO chat/IDE do projeto (injetado em ~/.claude/CLAUDE.md, fora do workspace,
 * então NÃO aparece na árvore de arquivos da IDE). Gerenciado na página do projeto.
 */
class ProjectMemory extends Model
{
    protected static string $table = 'project_memories';

    protected static array $fillable = [
        'project_id', 'name', 'content', 'position',
    ];

    /** Memórias de um projeto, ordenadas. */
    public static function forProject(int $projectId): array
    {
        return static::where('project_id', '=', $projectId)
            ->orderBy('position', 'ASC')
            ->orderBy('id', 'ASC')
            ->get();
    }
}
