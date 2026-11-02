<?php
/**
 * Hub do projeto no painel (d2): cabeçalho com nome, ícone e situação; ações principais
 * (IDE, nova conversa, configurações) e as seções em abas (visão geral, arquivos, conversas,
 * compartilhar, Git, memórias, servidores, projetos ligados).
 * A visão geral e as conversas saem prontas daqui; as demais abas são montadas por
 * /assets/js/paginas/projeto.js a partir dos mesmos endpoints de sempre.
 * Dados: ProjectController@show.
 */
$import        = $import ?? null;
$importState   = $import['status'] ?? null;          // running | done | failed | null
$processando   = $importState === 'running';
$importEtapas  = (array) ($import['stages'] ?? []);
$importResumo  = trim((string) ($import['summary'] ?? ''));
$importPontos  = array_values(array_filter((array) ($import['key_points'] ?? [])));
$dono          = !empty($is_owner);
$administrador = !empty($is_admin);
$contexto      = !empty($context_access);
$arquivado     = ($project->status ?? 'active') === 'archived';
$membros       = (array) ($members ?? []);
$git           = (array) ($git ?? []);
$conversas     = (array) ($conversations ?? []);
$uuid          = (string) $project->uuid;

// Duração curta: 2h 5min, 12min, 40s.
$duracao = function (int $s): string {
    if ($s <= 0) { return '0s'; }
    $h = intdiv($s, 3600); $m = intdiv($s % 3600, 60); $x = $s % 60;
    $p = [];
    if ($h) { $p[] = $h . 'h'; }
    if ($m) { $p[] = $m . 'min'; }
    if (!$h && $x) { $p[] = $x . 's'; }
    return $p ? implode(' ', $p) : '0s';
};

if ($processando) {
    $selo = ['aviso', t('projeto.situacao_processando', 'Processando'), true];
} elseif ($importState === 'failed') {
    $selo = ['erro', t('projeto.situacao_falhou', 'Importação falhou'), false];
} elseif ($arquivado) {
    $selo = ['neutro', t('projeto.situacao_arquivado', 'Arquivado'), false];
} else {
    $selo = ['ok', t('projeto.situacao_ativo', 'Ativo'), false];
}

$dadosPagina = [
    'uuid'        => $uuid,
    'nome'        => (string) $project->name,
    'descricao'   => (string) ($project->description ?? ''),
    'tipo'        => (string) ($project->type ?? 'web'),
    'icone'       => projectIcon($project->icon ?? null),
    'cor'         => (string) ($project->color ?? ''),
    'arquivado'   => $arquivado,
    'processando' => $processando,
    'importacao'  => $importState,
    'dono'        => $dono,
    'admin'       => $administrador,
    'contexto'    => $contexto,
    'ligados'     => $dono || $administrador,
    'donoNome'    => (string) ($owner_name ?? ''),
    'git' => [
        'configurado' => !empty($gitConfigured),
        'conectado'   => !empty($gitOwnerConnected),
        'ativo'       => !empty($git['enabled']),
        'repo'        => (string) ($git['repo'] ?? ''),
        'branch'      => (string) ($git['branch'] ?? 'main'),
        'auto'        => !empty($git['auto_sync']),
        'pendente'    => !empty($git['pending']),
    ],
    'membros' => array_map(fn ($m) => [
        'id'       => (int) $m['user_id'],
        'nome'     => (string) ($m['name'] ?? ''),
        'email'    => (string) ($m['email'] ?? ''),
        'contexto' => !empty($m['context_access']),
    ], $membros),
    'icones' => [
        ['valor' => '📁', 'rotulo' => t('projetos.icone_pasta', 'Pasta')],
        ['valor' => '🚀', 'rotulo' => t('projetos.icone_foguete', 'Foguete')],
        ['valor' => '💻', 'rotulo' => t('projetos.icone_computador', 'Computador')],
        ['valor' => '🌐', 'rotulo' => t('projetos.icone_web', 'Web')],
        ['valor' => '📱', 'rotulo' => t('projetos.icone_mobile', 'Celular')],
        ['valor' => '🔧', 'rotulo' => t('projetos.icone_ferramenta', 'Ferramenta')],
        ['valor' => '📊', 'rotulo' => t('projetos.icone_painel', 'Painel')],
        ['valor' => '🤖', 'rotulo' => t('projetos.icone_bot', 'Robô')],
    ],
    'matizes' => [
        ['valor' => 'indigo',  'rotulo' => t('projetos.cor_indigo', 'Índigo')],
        ['valor' => 'azul',    'rotulo' => t('projetos.cor_azul', 'Azul')],
        ['valor' => 'ciano',   'rotulo' => t('projetos.cor_ciano', 'Ciano')],
        ['valor' => 'teal',    'rotulo' => t('projetos.cor_teal', 'Verde-azulado')],
        ['valor' => 'roxo',    'rotulo' => t('projetos.cor_roxo', 'Roxo')],
        ['valor' => 'magenta', 'rotulo' => t('projetos.cor_magenta', 'Magenta')],
        ['valor' => 'rosa',    'rotulo' => t('projetos.cor_rosa', 'Rosa')],
        ['valor' => 'ardosia', 'rotulo' => t('projetos.cor_ardosia', 'Ardósia')],
    ],
    'tipos' => [
        ['valor' => 'web',  'rotulo' => t('projetos.tipo_web', 'Web'), 'dica' => t('projetos.tipo_web_desc', 'Site ou sistema web')],
        ['valor' => 'app',  'rotulo' => t('projetos.tipo_app', 'App'), 'dica' => t('projetos.tipo_app_desc', 'Android e iOS')],
        ['valor' => 'both', 'rotulo' => t('projetos.tipo_ambos', 'Ambos'), 'dica' => t('projetos.tipo_ambos_desc', 'Web e app')],
    ],
    'contagens' => [
        'conversas' => count($conversas),
        'membros'   => count($membros),
    ],
];

$this->layout('layouts/painel', [
    'title'          => $project->name ?? t('projeto.titulo', 'Projeto'),
    'currentPage'    => 'projects',
    'currentProject' => $project,
    'area'           => 'projetos',
    'hidePageTitle'  => true,
    'scripts'        => ['/assets/js/paginas/projeto.js'],
    'estilos'        => ['/assets/css/paginas/projeto.css'],
]);
?>

<header class="projeto-cab">
    <span class="projeto-emoji projeto-emoji-grande" data-cor="<?= e((string) ($project->color ?? '')) ?>" aria-hidden="true"><?= e(projectIcon($project->icon ?? null)) ?></span>
    <div class="projeto-cab-texto">
        <div class="projeto-cab-linha">
            <h1 class="projeto-cab-titulo"><?= e($project->name) ?></h1>
            <span class="d2-selo d2-selo-<?= e($selo[0]) ?>" data-tom="<?= e($selo[0]) ?>"><span class="d2-ponto<?= $selo[2] ? ' is-pulsando' : '' ?>" aria-hidden="true"></span><span class="d2-selo-texto"><?= e($selo[1]) ?></span></span>
        </div>
        <p class="projeto-cab-desc"><?= e($project->description ?: t('projetos.sem_descricao', 'Sem descrição')) ?></p>
    </div>
    <div class="projeto-cab-acoes">
        <?php if ($processando): ?>
            <button type="button" class="d2-btn d2-btn-primario" disabled data-tooltip="<?= e(t('projeto.aguarde_processamento', 'Aguarde o processamento do projeto')) ?>"><?= d2icone('computador') ?><span>IDE</span></button>
            <button type="button" class="d2-btn d2-btn-secundario" disabled data-tooltip="<?= e(t('projeto.aguarde_processamento', 'Aguarde o processamento do projeto')) ?>"><?= d2icone('atendimento') ?><span><?= e(t('projeto.nova_conversa', 'Nova conversa')) ?></span></button>
        <?php else: ?>
            <button type="button" class="d2-btn d2-btn-primario" id="acao-ide" data-tooltip="<?= e(t('projeto.ide_dica', 'Abrir o editor no navegador, com o Claude')) ?>"><?= d2icone('computador') ?><span>IDE</span></button>
            <a class="d2-btn d2-btn-secundario" href="/chat/<?= e($uuid) ?>" data-tooltip="<?= e(t('projeto.nova_conversa_dica', 'Conversar com o Dev autônomo neste projeto')) ?>"><?= d2icone('atendimento') ?><span><?= e(t('projeto.nova_conversa', 'Nova conversa')) ?></span></a>
        <?php endif; ?>
        <button type="button" class="d2-btn d2-btn-secundario" id="acao-config"><?= d2icone('engrenagem') ?><span><?= e(t('projeto.configuracoes', 'Configurações')) ?></span><?= d2icone('chevron-baixo') ?></button>
    </div>
</header>

<?php if (!empty($admin_view)): ?>
    <div class="d2-faixa d2-faixa-aviso projeto-faixa" role="note">
        <?= d2icone('alerta') ?>
        <div class="d2-faixa-texto">
            <strong class="d2-faixa-titulo"><?= e(t('projeto.admin_titulo', 'Visão de administrador')) ?></strong>
            <?= e(sprintf(t('projeto.admin_texto', 'Este projeto é de %s; você o está vendo por ser administrador do sistema (não é dono nem membro dele).'), $owner_name ?? t('projeto.outro_usuario', 'outro usuário'))) ?>
        </div>
    </div>
<?php endif; ?>

<div id="projeto-abas" class="projeto-abas" aria-busy="true">
    <div class="d2-esqueleto" role="status" aria-busy="true"><span class="d2-oculto-leitor"><?= e(t('projeto.carregando', 'Carregando')) ?></span><div class="d2-esqueleto-linha" aria-hidden="true"></div><div class="d2-esqueleto-linha" aria-hidden="true"></div><div class="d2-esqueleto-linha" aria-hidden="true"></div></div>
</div>

<!-- Visão geral: o JS move para a aba correspondente. -->
<section class="projeto-secao" data-aba="visao" hidden>
    <?php if ($processando): ?>
        <div class="d2-painel" data-area="projetos" id="painel-processamento">
            <header class="d2-painel-cab">
                <span class="d2-painel-icone" aria-hidden="true"><?= d2icone('atualizar') ?></span>
                <h2 class="d2-painel-titulo"><?= e(t('projeto.processando_titulo', 'Processando o projeto importado')) ?></h2>
                <span class="d2-painel-sub"><?= e(t('projeto.processando_sub', 'A IA está analisando e preparando o ambiente. As conversas abrem quando terminar.')) ?></span>
            </header>
            <div class="d2-painel-corpo">
                <div class="d2-progresso is-indeterminado" role="progressbar" aria-label="<?= e(t('projeto.processando_titulo', 'Processando o projeto importado')) ?>"><div class="d2-progresso-trilho"><span class="d2-progresso-barra"></span></div></div>
                <ol class="projeto-etapas" id="etapas-importacao">
                    <?php foreach ($importEtapas as $st): $estado = (string) ($st['state'] ?? 'pending'); ?>
                        <li class="projeto-etapa is-<?= e($estado) ?>"><span class="projeto-etapa-marca" aria-hidden="true"></span><span><?= e($st['label'] ?? $st['key'] ?? '') ?></span></li>
                    <?php endforeach; ?>
                </ol>
            </div>
        </div>
    <?php elseif ($importState === 'failed'): ?>
        <div class="d2-faixa d2-faixa-erro projeto-faixa" role="alert">
            <?= d2icone('erro') ?>
            <div class="d2-faixa-texto">
                <strong class="d2-faixa-titulo"><?= e(t('projeto.falhou_titulo', 'O processamento falhou')) ?></strong>
                <?= e((string) ($import['error'] ?? t('projeto.erro_desconhecido', 'Erro desconhecido.'))) ?>
                <?= e(t('projeto.falhou_dica', 'Você pode tentar importar o .zip novamente.')) ?>
            </div>
            <div class="d2-faixa-acoes"><button type="button" class="d2-btn d2-btn-sm d2-btn-secundario" data-importar-zip><?= d2icone('subir') ?><span><?= e(t('projeto.importar_de_novo', 'Importar de novo')) ?></span></button></div>
        </div>
    <?php endif; ?>

    <?php if (!$processando && ($importResumo !== '' || !empty($importPontos))): ?>
        <div class="d2-painel" data-area="projetos">
            <header class="d2-painel-cab">
                <span class="d2-painel-icone" aria-hidden="true"><?= d2icone('documento') ?></span>
                <h2 class="d2-painel-titulo"><?= e(t('projeto.resumo_titulo', 'Resumo do projeto')) ?></h2>
                <span class="d2-selo d2-selo-info" data-tom="info"><span class="d2-selo-texto">IA</span></span>
            </header>
            <div class="d2-painel-corpo projeto-resumo">
                <?php if ($importResumo !== ''): ?><p><?= e($importResumo) ?></p><?php endif; ?>
                <?php if (!empty($importPontos)): ?>
                    <ul><?php foreach ($importPontos as $p): ?><li><?= e((string) $p) ?></li><?php endforeach; ?></ul>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="indicadores">
        <div class="d2-indicador" data-area="ide">
            <span class="d2-indicador-rotulo"><?= d2icone('atendimento') ?><span><?= e(t('projeto.conversas', 'Conversas')) ?></span></span>
            <span class="d2-indicador-valor d2-num"><?= (int) ($stats['conversation_count'] ?? 0) ?></span>
        </div>
        <div class="d2-indicador" data-tom="ok">
            <span class="d2-indicador-rotulo"><?= d2icone('check') ?><span><?= e(t('projeto.concluidas', 'Concluídas')) ?></span></span>
            <span class="d2-indicador-valor d2-num"><?= (int) ($stats['completed_count'] ?? 0) ?></span>
        </div>
        <div class="d2-indicador" data-area="projetos">
            <span class="d2-indicador-rotulo"><?= d2icone('documento') ?><span><?= e(t('projeto.arquivos', 'Arquivos')) ?></span></span>
            <span class="d2-indicador-valor d2-num"><?= (int) ($stats['files_count'] ?? 0) ?></span>
        </div>
        <div class="d2-indicador" data-area="monitoramento">
            <span class="d2-indicador-rotulo"><?= d2icone('grafico') ?><span><?= e(t('projeto.tokens', 'Tokens')) ?></span></span>
            <span class="d2-indicador-valor d2-num"><?= e(formatTokens($stats['total_tokens'] ?? 0)) ?></span>
        </div>
        <div class="d2-indicador" data-area="licencas" data-tooltip="<?= e(t('projeto.custo_dica', 'Custo somando todos os chats do projeto')) ?>">
            <span class="d2-indicador-rotulo"><?= d2icone('dinheiro') ?><span><?= e(t('projeto.custo', 'Custo')) ?></span></span>
            <span class="d2-indicador-valor d2-num"><?= e(formatCost($stats['total_cost'] ?? 0)) ?></span>
        </div>
    </div>

    <?php if (!empty($contributions) && count($contributions) > 1): ?>
        <div class="d2-painel" data-area="usuarios">
            <header class="d2-painel-cab">
                <span class="d2-painel-icone" aria-hidden="true"><?= d2icone('usuarios') ?></span>
                <h2 class="d2-painel-titulo"><?= e(t('projeto.contribuicao', 'Contribuição da equipe')) ?></h2>
                <span class="d2-painel-sub"><?= e(t('projeto.contribuicao_sub', 'Peso relativo de cada membro (tarefas + custo)')) ?></span>
            </header>
            <div class="d2-painel-corpo">
                <ul class="projeto-barras">
                    <?php foreach ($contributions as $i => $c): $cor = ($i % 8) + 1; $tarefas = (int) $c['tasks']; ?>
                        <li class="projeto-barra-linha">
                            <span class="d2-avatar d2-avatar-cor-<?= $cor ?>" aria-hidden="true"><?= e($c['initial']) ?></span>
                            <div class="projeto-barra-corpo">
                                <div class="projeto-barra-topo">
                                    <span class="projeto-barra-nome"><?= e($c['name']) ?></span>
                                    <span class="projeto-barra-valor d2-num"><?= rtrim(rtrim(number_format((float) $c['pct'], 1, ',', ''), '0'), ',') ?>%</span>
                                </div>
                                <div class="projeto-barra-trilho"><span class="projeto-barra-preenchida projeto-cor-<?= $cor ?>" style="width: <?= max(2, (float) $c['pct']) ?>%"></span></div>
                                <span class="projeto-barra-meta"><?= $tarefas ?> <?= e($tarefas === 1 ? t('projeto.tarefa', 'tarefa') : t('projeto.tarefas', 'tarefas')) ?> · <?= e(formatCost($c['cost'])) ?></span>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!empty($efficiency)):
        $maxSeg = 0; $totalSeg = 0;
        foreach ($efficiency as $ef) { $maxSeg = max($maxSeg, (int) $ef['seconds']); $totalSeg += (int) $ef['seconds']; }
    ?>
        <div class="d2-painel" data-area="agendamentos">
            <header class="d2-painel-cab">
                <span class="d2-painel-icone" aria-hidden="true"><?= d2icone('relogio') ?></span>
                <h2 class="d2-painel-titulo"><?= e(t('projeto.tempo', 'Tempo trabalhado')) ?></h2>
                <div class="d2-painel-acoes"><span class="projeto-total"><strong class="d2-num"><?= e($duracao($totalSeg)) ?></strong> <?= e(t('projeto.no_total', 'no total')) ?></span></div>
            </header>
            <div class="d2-painel-corpo">
                <p class="texto-mudo projeto-explica"><?= e(t('projeto.tempo_sub', 'Tempo de trabalho por participante (IDE dos membros e Dev autônomo)')) ?></p>
                <ul class="projeto-barras">
                    <?php foreach ($efficiency as $i => $ef):
                        $agente = !empty($ef['is_agent']);
                        $cor = $agente ? 5 : ($i % 8) + 1;
                        $seg = (int) $ef['seconds'];
                        $larg = ($seg > 0 && $maxSeg > 0) ? max(2, (int) round($seg / $maxSeg * 100)) : 0;
                    ?>
                        <li class="projeto-barra-linha">
                            <span class="d2-avatar d2-avatar-cor-<?= $cor ?>" aria-hidden="true"><?= e($ef['initial']) ?></span>
                            <div class="projeto-barra-corpo">
                                <div class="projeto-barra-topo">
                                    <span class="projeto-barra-nome"><?= e($ef['name']) ?><?php if ($agente): ?> <span class="d2-selo d2-selo-info" data-tom="info"><span class="d2-selo-texto"><?= e(t('projeto.automatico', 'automático')) ?></span></span><?php endif; ?></span>
                                    <span class="projeto-barra-valor d2-num"><?= e($duracao($seg)) ?></span>
                                </div>
                                <div class="projeto-barra-trilho"><?php if ($larg > 0): ?><span class="projeto-barra-preenchida projeto-cor-<?= $cor ?>" style="width: <?= $larg ?>%"></span><?php endif; ?></div>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!$processando): ?>
        <div class="projeto-zona" id="zona-importar" tabindex="0" role="button" aria-label="<?= e(t('projeto.importar_titulo', 'Importar um projeto pronto')) ?>">
            <span class="projeto-zona-icone" aria-hidden="true"><?= d2icone('subir') ?></span>
            <span class="projeto-zona-texto">
                <strong><?= e(t('projeto.importar_titulo', 'Importar um projeto pronto')) ?></strong>
                <span><?= e(t('projeto.importar_texto', 'Arraste um .zip aqui ou clique para escolher. A IA descompacta, analisa a documentação e prepara o ambiente automaticamente.')) ?></span>
            </span>
        </div>
    <?php endif; ?>
</section>

<!-- Conversas do projeto. -->
<section class="projeto-secao" data-aba="conversas" hidden>
    <div class="d2-painel" data-area="ide">
        <header class="d2-painel-cab">
            <span class="d2-painel-icone" aria-hidden="true"><?= d2icone('atendimento') ?></span>
            <h2 class="d2-painel-titulo"><?= e(t('projeto.conversas', 'Conversas')) ?></h2>
            <span class="d2-painel-sub"><?= count($conversas) ?> <?= e(count($conversas) === 1 ? t('projeto.conversa', 'conversa') : t('projeto.conversas_min', 'conversas')) ?></span>
            <?php if (!$processando): ?>
                <div class="d2-painel-acoes"><a class="d2-btn d2-btn-sm d2-btn-primario" href="/chat/<?= e($uuid) ?>"><?= d2icone('mais') ?><span><?= e(t('projeto.nova_conversa', 'Nova conversa')) ?></span></a></div>
            <?php endif; ?>
        </header>
        <div class="d2-painel-corpo">
            <?php if (empty($conversas)): ?>
                <div class="d2-vazio">
                    <span class="d2-vazio-icone" aria-hidden="true"><?= d2icone('atendimento') ?></span>
                    <p class="d2-vazio-titulo"><?= e(t('projeto.sem_conversas', 'Nenhuma conversa ainda')) ?></p>
                    <p class="d2-vazio-texto"><?= e($processando ? t('projeto.sem_conversas_processando', 'As conversas ficam disponíveis assim que o processamento terminar.') : t('projeto.sem_conversas_texto', 'Peça ao Dev autônomo o que quer construir; ele planeja, programa e testa.')) ?></p>
                    <?php if (!$processando): ?>
                        <a class="d2-btn d2-btn-primario" href="/chat/<?= e($uuid) ?>"><?= d2icone('mais') ?><span><?= e(t('projeto.comecar_conversa', 'Começar uma conversa')) ?></span></a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="d2-tabela-caixa">
                    <table class="d2-tabela projeto-tabela-conversas">
                        <caption class="d2-oculto-leitor"><?= e(t('projeto.conversas', 'Conversas')) ?></caption>
                        <thead class="d2-tabela-cab">
                            <tr>
                                <th scope="col"><?= e(t('projeto.col_titulo', 'Título')) ?></th>
                                <th scope="col"><?= e(t('projeto.col_situacao', 'Situação')) ?></th>
                                <th scope="col" class="is-direita"><?= e(t('projeto.col_tempo', 'Tempo')) ?></th>
                                <th scope="col" class="is-direita"><?= e(t('projeto.custo', 'Custo')) ?></th>
                                <th scope="col" class="is-direita"><?= e(t('projeto.col_atualizada', 'Atualizada')) ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($conversas as $c):
                                $st = (string) ($c->status ?? 'active');
                                $tom = ['active' => 'ok', 'completed' => 'info', 'archived' => 'neutro'][$st] ?? 'neutro';
                                $rot = ['active' => t('projeto.conv_ativa', 'Ativa'), 'completed' => t('projeto.conv_concluida', 'Concluída'), 'archived' => t('projeto.conv_arquivada', 'Arquivada')][$st] ?? $st;
                            ?>
                                <tr class="d2-tabela-linha">
                                    <th scope="row" class="d2-tabela-cel"><a class="projeto-link" href="/chat/<?= e($uuid) ?>/<?= e($c->uuid) ?>"><?= e($c->title ?: t('projeto.sem_titulo', 'Sem título')) ?></a></th>
                                    <td class="d2-tabela-cel"><span class="d2-selo d2-selo-<?= e($tom) ?>" data-tom="<?= e($tom) ?>"><span class="d2-ponto" aria-hidden="true"></span><span class="d2-selo-texto"><?= e($rot) ?></span></span></td>
                                    <td class="d2-tabela-cel is-direita d2-num"><?= e($duracao((int) ($c->work_seconds ?? 0))) ?></td>
                                    <td class="d2-tabela-cel is-direita d2-num"><?= e(formatCost((float) ($c->total_cost ?? 0))) ?></td>
                                    <td class="d2-tabela-cel is-direita texto-mudo"><?= e(formatRelativeTime($c->updated_at)) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php if (!$dono && !$processando): ?>
    <!-- Sair do projeto (membro): formulário nativo, como antes. -->
    <form method="POST" action="/projects/<?= e($uuid) ?>/leave" id="form-sair" hidden>
        <?= csrf_field() ?>
    </form>
<?php endif; ?>

<input type="file" id="entrada-zip" accept=".zip,application/zip" hidden>

<script type="application/json" id="dados-pagina"><?= json_encode($dadosPagina, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
