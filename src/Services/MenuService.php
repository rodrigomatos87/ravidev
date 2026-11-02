<?php

declare(strict_types=1);

namespace Ravi\Services;

use Ravi\Core\Database;
use Ravi\Core\Modulos;
use Ravi\Models\Conversation;
use Ravi\Models\Project;
use Ravi\Models\User;

/**
 * Dados do menu lateral do painel (layouts/painel): quem é o usuário, os projetos dele e os
 * itens de navegação com área (cor) e ícone do sprite. As views só desenham; nenhuma consulta
 * nasce dentro de template.
 */
final class MenuService
{
    /** @return array{usuario: ?User, admin: bool, todos: bool, projetos: array<int, array>, secoes: array<int, array>} */
    public function montar(int $userId, ?string $paginaAtual = null, ?int $projetoAtualId = null): array
    {
        $usuario = $userId ? User::find($userId) : null;
        $admin   = $usuario !== null && $usuario->isAdmin();
        $todos   = $admin && Project::adminSeesAll($userId);

        $projetos = [];
        if ($userId) {
            $lista = $todos ? Project::findAllForAdmin() : Project::findByUser($userId);
            $donos = $todos ? $this->donos($lista) : [];
            foreach ($lista as $p) {
                $projetos[] = [
                    'uuid'       => (string) $p->uuid,
                    'nome'       => (string) $p->name,
                    'icone'      => (string) ($p->icon ?: ''),
                    'dono'       => ($todos && (int) $p->user_id !== $userId) ? ($donos[(int) $p->user_id] ?? null) : null,
                    'ativo'      => $projetoAtualId !== null && (int) $p->id === $projetoAtualId,
                    'trabalhando'=> !empty($p->is_working)
                        || Conversation::where('project_id', '=', $p->id)->where('status', '=', 'running')->count() > 0,
                ];
            }
        }

        $item = static fn(string $url, string $pagina, string $titulo, string $icone, string $area): array => [
            'url' => $url, 'pagina' => $pagina, 'titulo' => $titulo, 'icone' => $icone, 'area' => $area,
            'ativo' => $paginaAtual === $pagina,
        ];

        $secoes = [[
            'titulo' => t('nav.quick_actions', 'Navegação'),
            'itens'  => [
                $item('/dashboard', 'dashboard', t('nav.dashboard', 'Início'), 'painel', 'painel'),
                $item('/projects', 'projects', t('nav.projects', 'Projetos'), 'lista', 'projetos'),
                $item('/ide', 'ide', t('nav.ide', 'IDE'), 'computador', 'ide'),
                $item('/schedules', 'schedules', t('nav.schedules', 'Agendamentos'), 'calendario', 'agendamentos'),
                $item('/settings/profile', 'account', t('nav.my_account', 'Minha conta'), 'conta', 'conta'),
            ],
        ]];

        if ($admin) {
            $itens = [
                $item('/admin/users', 'admin', t('nav.users', 'Usuários'), 'usuarios', 'usuarios'),
                $item('/admin/licenses', 'admin-licenses', t('nav.licenses', 'Licenças'), 'chave', 'licencas'),
                $item('/admin/api', 'admin-api', t('nav.api', 'Gateway de API'), 'plataforma', 'gateway'),
                $item('/admin/monitoring', 'admin-monitoring', t('nav.monitoring', 'Monitoramento'), 'grafico', 'monitoramento'),
            ];
            foreach (Modulos::menuAdmin() as $m) {
                $itens[] = $item((string) $m['url'], (string) ($m['pagina'] ?? ''), (string) $m['titulo'], (string) ($m['icone_sprite'] ?? 'cadeado'), (string) ($m['area'] ?? 'configuracoes'));
            }
            $itens[] = $item('/settings', 'settings', t('nav.settings', 'Configurações'), 'engrenagem', 'configuracoes');
            $secoes[] = ['titulo' => t('nav.administration', 'Administração'), 'itens' => $itens];
        }

        return ['usuario' => $usuario, 'admin' => $admin, 'todos' => $todos, 'projetos' => $projetos, 'secoes' => $secoes];
    }

    /** Área (cor) e item ativo da página atual, para a barra do topo. */
    public static function itemAtivo(array $menu): ?array
    {
        foreach ($menu['secoes'] as $s) {
            foreach ($s['itens'] as $i) {
                if (!empty($i['ativo'])) {
                    return $i;
                }
            }
        }
        return null;
    }

    /** @param array<int, Project> $projetos @return array<int, string> */
    private function donos(array $projetos): array
    {
        $ids = [];
        foreach ($projetos as $p) {
            $ids[(int) $p->user_id] = true;
        }
        if (!$ids) {
            return [];
        }
        $marcadores = implode(',', array_fill(0, count($ids), '?'));
        $saida = [];
        foreach (Database::getInstance()->fetchAll("SELECT id, name, email FROM users WHERE id IN ($marcadores)", array_keys($ids)) as $r) {
            $saida[(int) $r['id']] = (string) ($r['name'] ?: $r['email']);
        }
        return $saida;
    }
}
