<?php
/**
 * Projetos: lista compacta agrupada por dono, no layout do painel (d2).
 * Ação principal = IDE (abre o editor do projeto no navegador); "Abrir" leva ao hub do projeto.
 * Só administrador: interruptor "Todos os usuários" (preferência guardada) mostra os projetos
 * de todo o sistema, um grupo por usuário.
 * Dados: ProjectController@index.
 */
$status = $currentStatus ?? 'active';
$total  = count($projects ?? []);
$todos  = !empty($all_users);

$this->layout('layouts/painel', [
    'title'       => $title ?? t('projetos.titulo', 'Projetos'),
    'pageTitle'   => $todos ? t('projetos.todos_titulo', 'Todos os projetos') : t('projetos.meus_titulo', 'Meus projetos'),
    'currentPage' => 'projects',
    'area'        => 'projetos',
    'subtitulo'   => t('projetos.sub', 'Abra a IDE, converse com o agente e acompanhe cada projeto.'),
    'acoes'       => '<a class="d2-btn d2-btn-primario" href="/projects/create">' . d2icone('mais') . '<span>' . e(t('nav.new_project', 'Novo projeto')) . '</span></a>',
    'scripts'     => ['/assets/js/paginas/projetos.js'],
    'estilos'     => ['/assets/css/paginas/projeto.css'],
]);
?>

<div class="projetos-barra">
    <nav class="d2-btn-grupo" aria-label="<?= e(t('projetos.filtro', 'Filtrar por situação')) ?>">
        <a class="d2-btn d2-btn-sm<?= $status === 'active' ? ' is-ativo' : '' ?>" href="/projects"<?= $status === 'active' ? ' aria-current="page"' : '' ?>><?= e(t('projetos.ativos', 'Ativos')) ?></a>
        <a class="d2-btn d2-btn-sm<?= $status === 'archived' ? ' is-ativo' : '' ?>" href="/projects?status=archived"<?= $status === 'archived' ? ' aria-current="page"' : '' ?>><?= e(t('projetos.arquivados', 'Arquivados')) ?></a>
    </nav>
    <?php if (!empty($is_admin)): ?>
        <label class="d2-switch" data-tooltip="<?= e(t('projetos.todos_dica', 'Só administrador: ver os projetos de todos os usuários do sistema')) ?>">
            <input type="checkbox" role="switch" id="projetos-todos" <?= $todos ? 'checked' : '' ?>>
            <span class="d2-switch-trilho" aria-hidden="true"></span>
            <span class="d2-switch-texto"><?= e(t('projetos.todos_usuarios', 'Todos os usuários')) ?> <span class="texto-mudo"><?= e(t('projetos.administrador', '(administrador)')) ?></span></span>
        </label>
    <?php endif; ?>
</div>

<?php if ($total === 0): ?>
    <section class="d2-painel" data-area="projetos">
        <div class="d2-painel-corpo">
            <div class="d2-vazio">
                <span class="d2-vazio-icone" aria-hidden="true"><?= d2icone('lista') ?></span>
                <?php if ($status === 'archived'): ?>
                    <p class="d2-vazio-titulo"><?= e(t('projetos.vazio_arquivados', 'Nenhum projeto arquivado')) ?></p>
                    <p class="d2-vazio-texto"><?= e(t('projetos.vazio_arquivados_texto', 'Projetos arquivados aparecem aqui e podem ser restaurados quando quiser.')) ?></p>
                    <a class="d2-btn d2-btn-secundario" href="/projects"><?= d2icone('seta-esquerda') ?><span><?= e(t('projetos.ver_ativos', 'Ver projetos ativos')) ?></span></a>
                <?php elseif ($todos): ?>
                    <p class="d2-vazio-titulo"><?= e(t('projetos.vazio_sistema', 'Nenhum projeto no sistema')) ?></p>
                    <p class="d2-vazio-texto"><?= e(t('projetos.vazio_sistema_texto', 'Nenhum usuário criou projetos ainda.')) ?></p>
                    <a class="d2-btn d2-btn-primario" href="/projects/create"><?= d2icone('mais') ?><span><?= e(t('nav.create_first_project', 'Criar o primeiro projeto')) ?></span></a>
                <?php else: ?>
                    <p class="d2-vazio-titulo"><?= e(t('nav.no_projects', 'Nenhum projeto ainda')) ?></p>
                    <p class="d2-vazio-texto"><?= e(t('projetos.vazio_texto', 'Crie seu primeiro projeto para começar a desenvolver com o Ravi.')) ?></p>
                    <a class="d2-btn d2-btn-primario" href="/projects/create"><?= d2icone('mais') ?><span><?= e(t('nav.create_first_project', 'Criar o primeiro projeto')) ?></span></a>
                <?php endif; ?>
            </div>
        </div>
    </section>
<?php else: ?>
    <div class="projetos-grupos">
        <?php foreach ($groups as $gi => $g): $qtd = count($g['projects']); ?>
            <section class="d2-painel" data-area="projetos" aria-labelledby="grupo-<?= (int) $gi ?>">
                <header class="d2-painel-cab">
                    <span class="d2-painel-icone" aria-hidden="true"><?= d2icone($g['mine'] ? 'lista' : 'conta') ?></span>
                    <h3 class="d2-painel-titulo" id="grupo-<?= (int) $gi ?>"><?= e($g['label']) ?></h3>
                    <span class="d2-painel-sub"><?= $qtd ?> <?= e($qtd === 1 ? t('projetos.projeto', 'projeto') : t('projetos.projetos', 'projetos')) ?></span>
                </header>
                <div class="d2-painel-corpo">
                    <ul class="lista-acionavel">
                        <?php foreach ($g['projects'] as $project): $trabalhando = !empty($project->is_working); ?>
                            <li class="projeto-linha" data-uuid="<?= e($project->uuid) ?>">
                                <a class="lista-item projeto-linha-link" href="/projects/<?= e($project->uuid) ?>">
                                    <span class="lista-item-icone projeto-emoji" data-cor="<?= e((string) ($project->color ?? '')) ?>" aria-hidden="true"><?= e(projectIcon($project->icon ?? null)) ?></span>
                                    <span class="lista-item-texto">
                                        <span class="lista-item-titulo">
                                            <?php if ($trabalhando): ?><span class="d2-ponto d2-ponto-ok is-pulsando" data-tooltip="<?= e(t('projetos.trabalhando', 'Há um chat trabalhando neste projeto agora')) ?>"></span><?php endif; ?>
                                            <?= e($project->name) ?>
                                        </span>
                                        <span class="lista-item-sub"><?= e(truncate($project->description ?: t('projetos.sem_descricao', 'Sem descrição'), 90)) ?></span>
                                    </span>
                                </a>
                                <span class="projeto-linha-meta">
                                    <span data-tooltip="<?= e(t('projetos.conversas', 'Conversas')) ?>"><?= d2icone('atendimento') ?><?= (int) ($project->conversation_count ?? 0) ?></span>
                                    <?php if ((float) ($project->total_cost ?? 0) > 0): ?>
                                        <span data-tooltip="<?= e(t('projetos.custo_dica', 'Custo somando todos os chats')) ?>"><?= d2icone('dinheiro') ?><?= e(formatCost($project->total_cost)) ?></span>
                                    <?php endif; ?>
                                    <span data-tooltip="<?= e(t('projetos.ultima_atividade', 'Última atividade')) ?>"><?= d2icone('relogio') ?><?= e(formatRelativeTime($project->updated_at)) ?></span>
                                </span>
                                <span class="projeto-linha-acoes">
                                    <?php if ($status === 'active'): ?>
                                        <button type="button" class="d2-btn d2-btn-sm d2-btn-primario" data-abrir-ide="<?= e($project->uuid) ?>"
                                                data-tooltip="<?= e(t('projetos.ide_dica', 'Abrir o editor no navegador, com o Claude')) ?>"><?= d2icone('computador') ?><span>IDE</span></button>
                                    <?php endif; ?>
                                    <a class="d2-btn d2-btn-sm d2-btn-secundario" href="/projects/<?= e($project->uuid) ?>"><?= e(t('projetos.abrir', 'Abrir')) ?></a>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </section>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
