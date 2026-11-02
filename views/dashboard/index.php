<?php
/**
 * Início: visão geral dos projetos, do uso e do servidor (admin). Layout do painel (d2).
 * Dados: DashboardController@index.
 */
$this->layout('layouts/painel', [
    'title'       => t('inicio.titulo', 'Início'),
    'currentPage' => 'dashboard',
    'subtitulo'   => t('inicio.sub', 'Seus projetos, o uso das suas origens e a saúde do servidor.'),
    'acoes'       => '<a class="d2-btn d2-btn-primario" href="/projects/create">' . d2icone('mais') . '<span>' . e(t('nav.new_project', 'Novo projeto')) . '</span></a>',
    'scripts'     => ['/assets/js/paginas/inicio.js'],
]);
$fmtGB = static fn($b): string => number_format(((float) $b) / 1073741824, 1) . ' GB';
$tomPct = static fn($p): string => $p >= 85 ? 'erro' : ($p >= 65 ? 'aviso' : 'ok');
?>

<section class="indicadores" aria-label="<?= e(t('inicio.numeros', 'Números')) ?>">
    <div class="d2-indicador" data-area="projetos">
        <span class="d2-indicador-rotulo"><?= d2icone('lista') ?><span><?= e(t('inicio.projetos', 'Projetos')) ?></span></span>
        <span class="d2-indicador-valor d2-num"><?= (int) ($stats['total_projects'] ?? 0) ?></span>
    </div>
    <div class="d2-indicador" data-area="ide">
        <span class="d2-indicador-rotulo"><?= d2icone('atendimento') ?><span><?= e(t('inicio.conversas', 'Conversas')) ?></span></span>
        <span class="d2-indicador-valor d2-num"><?= (int) ($stats['total_conversations'] ?? 0) ?></span>
    </div>
    <div class="d2-indicador" data-area="agendamentos">
        <span class="d2-indicador-rotulo"><?= d2icone('documento') ?><span><?= e(t('inicio.arquivos', 'Arquivos gerados')) ?></span></span>
        <span class="d2-indicador-valor d2-num"><?= (int) ($stats['total_files'] ?? 0) ?></span>
    </div>
    <div class="d2-indicador" data-area="licencas" data-tooltip="<?= e(t('inicio.tokens_dica', 'Tokens das origens ativas: chat, IDE e API')) ?>">
        <span class="d2-indicador-rotulo"><?= d2icone('grafico') ?><span><?= e(t('inicio.tokens', 'Tokens')) ?></span></span>
        <span class="d2-indicador-valor d2-num"><?= !empty($canViewUsage) ? e(formatTokens($usageBreakdown['total_tokens'] ?? 0)) : '—' ?></span>
    </div>
    <?php if (!empty($canViewUsage)): ?>
    <div class="d2-indicador" data-area="gateway">
        <span class="d2-indicador-rotulo"><?= d2icone('dinheiro') ?><span><?= e(t('inicio.custo', 'Custo estimado')) ?></span></span>
        <span class="d2-indicador-valor d2-num"><?= e(formatCost((float) ($usageBreakdown['total_cost'] ?? 0))) ?></span>
    </div>
    <?php endif; ?>
    <?php if (!empty($isAdmin)): ?>
    <div class="d2-indicador" data-area="gateway">
        <span class="d2-indicador-rotulo"><?= d2icone('plataforma') ?><span><?= e(t('inicio.custo_api', 'Custo do Gateway')) ?></span></span>
        <span class="d2-indicador-valor d2-num"><?= e(formatCost((float) ($gwSpend ?? 0))) ?></span>
    </div>
    <div class="d2-indicador" data-area="gateway">
        <span class="d2-indicador-rotulo"><?= d2icone('chave') ?><span><?= e(t('inicio.apis_ativas', 'Chaves de API ativas')) ?></span></span>
        <span class="d2-indicador-valor d2-num"><?= (int) ($gwActiveKeys ?? 0) ?></span>
    </div>
    <?php endif; ?>
</section>

<?php if (!empty($isAdmin) && !empty($server)): ?>
<section class="d2-painel" data-area="monitoramento" aria-labelledby="t-servidor">
    <header class="d2-painel-cab">
        <span class="d2-painel-icone" aria-hidden="true"><?= d2icone('computador') ?></span>
        <h3 class="d2-painel-titulo" id="t-servidor"><?= e(t('inicio.servidor', 'Servidor')) ?></h3>
        <span class="d2-painel-sub"><?= e(t('inicio.ao_vivo', 'ao vivo')) ?></span>
        <div class="d2-painel-acoes"><a class="d2-btn d2-btn-sm d2-btn-fantasma" href="/admin/monitoring"><?= e(t('inicio.ver_monitoramento', 'Monitoramento')) ?></a></div>
    </header>
    <div class="d2-painel-corpo">
        <div class="medidores" id="servidor-medidores">
            <?php foreach (['cpu' => t('inicio.cpu', 'CPU'), 'ram' => t('inicio.memoria', 'Memória'), 'disk' => t('inicio.disco', 'Disco')] as $k => $rotulo):
                $pct = (float) ($server[$k]['pct'] ?? 0);
                $meta = $k === 'cpu'
                    ? (int) ($server['cpu']['cores'] ?? 0) . ' ' . t('inicio.nucleos', 'núcleos') . ' · ' . t('inicio.carga', 'carga') . ' ' . ($server['cpu']['load'] ?? '')
                    : $fmtGB($server[$k]['used'] ?? 0) . ' / ' . $fmtGB($server[$k]['total'] ?? 0);
            ?>
            <div class="medidor" data-medidor="<?= e($k) ?>">
                <div class="medidor-topo"><span class="medidor-rotulo"><?= e($rotulo) ?></span><span class="medidor-valor" data-campo="pct"><?= e(number_format($pct, 1)) ?>%</span></div>
                <div class="d2-progresso is-<?= e($tomPct($pct)) ?>" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= e((string) round($pct)) ?>" aria-label="<?= e($rotulo) ?>">
                    <div class="d2-progresso-trilho"><div class="d2-progresso-barra" data-campo="barra" style="transform: scaleX(<?= e(number_format(min(100, $pct) / 100, 3, '.', '')) ?>)"></div></div>
                </div>
                <span class="medidor-meta" data-campo="meta"><?= e($meta) ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<div class="d2-mosaico">
    <section class="d2-painel" data-area="projetos" aria-labelledby="t-projetos">
        <header class="d2-painel-cab">
            <span class="d2-painel-icone" aria-hidden="true"><?= d2icone('lista') ?></span>
            <h3 class="d2-painel-titulo" id="t-projetos"><?= e(t('inicio.projetos_recentes', 'Projetos recentes')) ?></h3>
            <div class="d2-painel-acoes"><a class="d2-btn d2-btn-sm d2-btn-fantasma" href="/projects"><?= e(t('inicio.ver_todos', 'Ver todos')) ?></a></div>
        </header>
        <div class="d2-painel-corpo">
            <?php if (!empty($recentProjects)): ?>
                <ul class="lista-acionavel">
                    <?php foreach ($recentProjects as $project): ?>
                        <li>
                            <a class="lista-item" href="/projects/<?= e($project->uuid) ?>">
                                <span class="lista-item-icone" aria-hidden="true"><?= $project->icon ? e($project->icon) : d2icone('documento') ?></span>
                                <span class="lista-item-texto">
                                    <span class="lista-item-titulo"><?= e($project->name) ?></span>
                                    <span class="lista-item-sub"><?= (int) ($project->conversations_count ?? 0) ?> <?= e(t('inicio.conversas_min', 'conversas')) ?> · <?= e(time_ago($project->updated_at)) ?></span>
                                </span>
                                <?= d2icone('chevron-direita', 'lista-item-seta') ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <div class="d2-vazio">
                    <span class="d2-vazio-icone" aria-hidden="true"><?= d2icone('documento') ?></span>
                    <p class="d2-vazio-titulo"><?= e(t('nav.no_projects', 'Nenhum projeto ainda')) ?></p>
                    <p class="d2-vazio-texto"><?= e(t('inicio.vazio_projetos', 'Crie um projeto para abrir a IDE e começar a conversar com o agente.')) ?></p>
                    <a class="d2-btn d2-btn-primario" href="/projects/create"><?= e(t('nav.create_first_project', 'Criar o primeiro projeto')) ?></a>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <section class="d2-painel" data-area="ide" aria-labelledby="t-conversas">
        <header class="d2-painel-cab">
            <span class="d2-painel-icone" aria-hidden="true"><?= d2icone('atendimento') ?></span>
            <h3 class="d2-painel-titulo" id="t-conversas"><?= e(t('inicio.conversas_recentes', 'Conversas recentes')) ?></h3>
        </header>
        <div class="d2-painel-corpo">
            <?php if (!empty($recentConversations)): ?>
                <ul class="lista-acionavel">
                    <?php foreach ($recentConversations as $c): ?>
                        <?php $tom = ['completed' => 'ok', 'running' => 'info', 'failed' => 'erro', 'cancelled' => 'neutro'][$c->status ?? ''] ?? 'neutro'; ?>
                        <li>
                            <a class="lista-item" href="/chat/<?= e($c->uuid) ?>">
                                <span class="lista-item-icone" data-tom="<?= e($tom) ?>" aria-hidden="true"><?= d2icone($c->status === 'running' ? 'relogio' : ($c->status === 'failed' ? 'erro' : 'check')) ?></span>
                                <span class="lista-item-texto">
                                    <span class="lista-item-titulo"><?= e(truncate((string) ($c->title ?: t('inicio.sem_titulo', 'Sem título')), 60)) ?></span>
                                    <span class="lista-item-sub"><?= e(time_ago($c->updated_at)) ?></span>
                                </span>
                                <span class="d2-selo d2-selo-<?= e($tom) ?>" data-tom="<?= e($tom) ?>"><span class="d2-ponto" aria-hidden="true"></span><span class="d2-selo-texto"><?= e(t('conversa.status.' . ($c->status ?? ''), (string) ($c->status ?? ''))) ?></span></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <div class="d2-vazio">
                    <span class="d2-vazio-icone" aria-hidden="true"><?= d2icone('atendimento') ?></span>
                    <p class="d2-vazio-titulo"><?= e(t('inicio.vazio_conversas', 'Nenhuma conversa ainda')) ?></p>
                    <p class="d2-vazio-texto"><?= e(t('inicio.vazio_conversas_texto', 'As conversas com o agente aparecem aqui assim que você abrir um projeto.')) ?></p>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <section class="d2-painel" data-area="painel" aria-labelledby="t-atalhos">
        <header class="d2-painel-cab">
            <span class="d2-painel-icone" aria-hidden="true"><?= d2icone('estrela') ?></span>
            <h3 class="d2-painel-titulo" id="t-atalhos"><?= e(t('inicio.atalhos', 'Começar por aqui')) ?></h3>
        </header>
        <div class="d2-painel-corpo">
            <div class="atalhos">
                <a class="atalho" href="/projects/create" data-area="projetos">
                    <span class="atalho-icone"><?= d2icone('mais') ?></span>
                    <span class="atalho-titulo"><?= e(t('nav.new_project', 'Novo projeto')) ?></span>
                    <span class="atalho-desc"><?= e(t('inicio.atalho_projeto', 'Crie um projeto novo ou envie um existente.')) ?></span>
                </a>
                <a class="atalho" href="/ide" data-area="ide">
                    <span class="atalho-icone"><?= d2icone('computador') ?></span>
                    <span class="atalho-titulo"><?= e(t('nav.ide', 'IDE')) ?></span>
                    <span class="atalho-desc"><?= e(t('inicio.atalho_ide', 'Abra a IDE no navegador com o agente pronto.')) ?></span>
                </a>
                <a class="atalho" href="/schedules" data-area="agendamentos">
                    <span class="atalho-icone"><?= d2icone('calendario') ?></span>
                    <span class="atalho-titulo"><?= e(t('nav.schedules', 'Agendamentos')) ?></span>
                    <span class="atalho-desc"><?= e(t('inicio.atalho_agendamentos', 'Tarefas que o agente executa sozinho, no horário.')) ?></span>
                </a>
                <a class="atalho" href="/settings/profile" data-area="conta">
                    <span class="atalho-icone"><?= d2icone('conta') ?></span>
                    <span class="atalho-titulo"><?= e(t('nav.my_account', 'Minha conta')) ?></span>
                    <span class="atalho-desc"><?= e(t('inicio.atalho_conta', 'Perfil, segurança e a conta de IA que você usa.')) ?></span>
                </a>
            </div>
        </div>
    </section>
</div>
