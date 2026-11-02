<?php
/**
 * Admin: monitoramento do servidor e dos ambientes ativos. Layout do painel (d2).
 * Os números vêm de GET /admin/monitoring/data a cada 1 s (módulo admin-monitoramento.js);
 * encerrar e reiniciar usam POST /admin/monitoring/kill e /admin/monitoring/restart.
 */
$aoVivo = '<span class="d2-selo d2-selo-ok" data-tom="ok" id="selo-ao-vivo" role="status" data-tooltip="' . e(t('admin.monit.ao_vivo_dica', 'Atualiza a cada segundo')) . '">'
    . '<span class="d2-ponto is-pulsando" aria-hidden="true"></span><span class="d2-selo-texto">' . e(t('admin.monit.ao_vivo', 'ao vivo')) . '</span></span>';
$this->layout('layouts/painel', [
    'title'       => t('admin.monit.titulo', 'Monitoramento'),
    'currentPage' => 'admin-monitoring',
    'area'        => 'monitoramento',
    'subtitulo'   => t('admin.monit.sub', 'Uso de CPU, memória, disco e rede do servidor e dos ambientes ativos. Atualiza a cada segundo.'),
    'acoes'       => $aoVivo,
    'scripts'     => ['/assets/js/paginas/admin-monitoramento.js'],
    'estilos'     => ['/assets/css/paginas/admin-monitoramento.css'],
]);
$indicadores = [
    'uptime'     => ['relogio', t('admin.monit.uptime', 'Ligado há')],
    'carga'      => ['grafico', t('admin.monit.carga', 'Carga média')],
    'ambientes'  => ['computador', t('admin.monit.ativos', 'Ambientes ativos')],
    'rede'       => ['wifi', t('admin.monit.rede', 'Rede')],
];
$medidores = [
    'cpu'   => t('admin.monit.cpu', 'CPU'),
    'ram'   => t('admin.monit.memoria', 'Memória'),
    'disco' => t('admin.monit.disco', 'Disco'),
];
?>

<section class="indicadores" aria-label="<?= e(t('admin.monit.resumo', 'Resumo do servidor')) ?>" aria-live="off">
    <?php foreach ($indicadores as $chave => [$icone, $rotulo]): ?>
    <div class="d2-indicador" data-area="monitoramento">
        <span class="d2-indicador-rotulo"<?= $chave === 'carga' ? ' data-tooltip="' . e(t('admin.monit.carga_dica', 'Carga do servidor em 1, 5 e 15 minutos')) . '"' : '' ?>><?= d2icone($icone) ?><span><?= e($rotulo) ?></span></span>
        <span class="d2-indicador-valor d2-num" data-indicador="<?= e($chave) ?>"><span class="d2-esqueleto-linha monit-esqueleto" aria-hidden="true"></span></span>
    </div>
    <?php endforeach; ?>
</section>

<section class="d2-painel" data-area="monitoramento" aria-labelledby="t-servidor">
    <header class="d2-painel-cab">
        <span class="d2-painel-icone" aria-hidden="true"><?= d2icone('dispositivo') ?></span>
        <h3 class="d2-painel-titulo" id="t-servidor"><?= e(t('admin.monit.servidor', 'Servidor')) ?></h3>
    </header>
    <div class="d2-painel-corpo">
        <div class="medidores" id="medidores-servidor">
            <?php foreach ($medidores as $chave => $rotulo): ?>
            <div class="medidor" data-medidor="<?= e($chave) ?>">
                <div class="medidor-topo"><span class="medidor-rotulo"><?= e($rotulo) ?></span><span class="medidor-valor d2-num" data-campo="pct"><span class="d2-esqueleto-linha monit-esqueleto" aria-hidden="true"></span></span></div>
                <div class="d2-progresso is-ok" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" aria-label="<?= e($rotulo) ?>">
                    <div class="d2-progresso-trilho"><div class="d2-progresso-barra" data-campo="barra"></div></div>
                </div>
                <span class="medidor-meta" data-campo="meta">&nbsp;</span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<div class="d2-grade-2 monit-graficos">
    <section class="d2-painel" data-area="monitoramento" aria-labelledby="t-hist-cpu">
        <header class="d2-painel-cab">
            <span class="d2-painel-icone" aria-hidden="true"><?= d2icone('grafico') ?></span>
            <h3 class="d2-painel-titulo" id="t-hist-cpu"><?= e(t('admin.monit.hist_cpu', 'CPU no último minuto')) ?></h3>
        </header>
        <div class="d2-painel-corpo" id="grafico-cpu" aria-busy="true"></div>
    </section>
    <section class="d2-painel" data-area="monitoramento" aria-labelledby="t-hist-rede">
        <header class="d2-painel-cab">
            <span class="d2-painel-icone" aria-hidden="true"><?= d2icone('wifi') ?></span>
            <h3 class="d2-painel-titulo" id="t-hist-rede"><?= e(t('admin.monit.hist_rede', 'Rede no último minuto')) ?></h3>
            <span class="d2-painel-sub" id="rede-interface"></span>
        </header>
        <div class="d2-painel-corpo" id="grafico-rede" aria-busy="true"></div>
    </section>
</div>

<section class="d2-painel" data-area="monitoramento" aria-labelledby="t-ambientes">
    <header class="d2-painel-cab">
        <span class="d2-painel-icone" aria-hidden="true"><?= d2icone('computador') ?></span>
        <h3 class="d2-painel-titulo" id="t-ambientes"><?= e(t('admin.monit.ambientes', 'Ambientes')) ?></h3>
        <span class="d2-painel-sub"><?= e(t('admin.monit.ambientes_sub', 'IDEs, dev autônomo, previews e terminais em execução.')) ?></span>
    </header>
    <div class="d2-painel-corpo" id="tabela-ambientes" aria-busy="true"></div>
</section>
