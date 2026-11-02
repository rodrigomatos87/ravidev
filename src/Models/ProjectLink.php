<?php

declare(strict_types=1);

namespace Ravi\Models;

/**
 * Ligação entre projetos (#3). O projeto `project_id` (A) ganha acesso aos arquivos do projeto
 * `linked_project_id` (B) — só leitura ('read') ou leitura+escrita ('write') — enquanto `enabled`.
 * Montado em /linked/<slug> nos containers de A (chat + IDE). B segue sendo um sistema separado.
 */
class ProjectLink extends Model
{
    protected static string $table = 'project_links';

    /** A tabela só tem created_at (sem updated_at). */
    protected static bool $usesUpdatedAt = false;

    protected static array $fillable = [
        'project_id', 'linked_project_id', 'access', 'enabled',
    ];

    public function isWrite(): bool
    {
        return (string) $this->access === 'write';
    }

    /** Links (ativos por padrão) de um projeto A. */
    public static function forProject(int $projectId, bool $onlyEnabled = false): array
    {
        $q = static::where('project_id', '=', $projectId);
        if ($onlyEnabled) {
            $q = $q->where('enabled', '=', 1);
        }
        return $q->orderBy('id', 'ASC')->get();
    }
}
