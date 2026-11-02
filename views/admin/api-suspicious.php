<?php
/**
 * Admin: análise de fraude de um token do Gateway de API (token vazado). Layout do painel (d2).
 * Dados: AdminController@apiGatewaySuspicious. As tabelas são montadas pelo módulo da página.
 */
$this->layout('layouts/painel', [
    'title'       => t('gateway.analise_titulo', 'Análise de uso'),
    'currentPage' => 'admin-api',
    'area'        => 'gateway',
    'subtitulo'   => t('gateway.analise_sub', 'Sinais de que o token foi copiado ou usado fora do caminho oficial.'),
    'acoes'       => '<a class="d2-btn d2-btn-fantasma" href="/admin/api">' . d2icone('seta-esquerda') . '<span>' . e(t('gateway.voltar', 'Voltar ao Gateway')) . '</span></a>',
    'scripts'     => ['/assets/js/paginas/admin-gateway.js'],
    'estilos'     => ['/assets/css/paginas/admin-gateway.css'],
]);
$total    = (int) ($resumo['total'] ?? 0);
$semIdent = (int) ($resumo['sem_ident'] ?? 0);
$comIdent = max(0, $total - $semIdent);
$pctSem   = $total > 0 ? round($semIdent * 100 / $total) : 0;
$fmt = static fn($n): string => number_format((int) $n, 0, ',', '.');
$quando = static fn($d): string => $d ? date('d/m/Y H:i', strtotime((string) $d)) : '';
$dadosPagina = [
    'tela'     => 'analise',
    'sem_ident' => array_map(static fn($r) => ['ip' => (string) $r['ip'], 'n' => (int) $r['n'], 'ultima' => $quando($r['ultima'] ?? null)], $sem_ident ?? []),
    'por_ident' => array_map(static fn($r) => [
        'token'   => (string) $r['server_token'],
        'n'       => (int) $r['n'],
        'origens' => (int) $r['origens'],
        'confs'   => (int) $r['confs'],
        'ips_origem' => (string) ($r['ips_origem'] ?? ''),
        'ips_decl'   => (string) ($r['ips_decl'] ?? ''),
        'ultima'  => $quando($r['ultima'] ?? null),
    ], $por_ident ?? []),
];
?>
<script type="application/json" id="dados-pagina"><?= json_encode($dadosPagina, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<div class="gw-tela d2-pilha" data-tela="analise">
<?php include __DIR__ . '/../partials/gateway/token-cab.php'; ?>

<div class="d2-faixa">
    <?= d2icone('info') ?>
    <div class="d2-faixa-texto"><?= e(t('gateway.analise_ajuda', 'Depois de migrar a frota para um token novo, tudo que continuar chegando neste token é suspeito. Sinais de fraude: chamadas sem identidade (usaram o token cru, fora do caminho oficial) e a mesma identidade vindo de vários IPs de origem ou com conjuntos de IPs diferentes (token copiado para outra máquina).')) ?></div>
</div>

<section class="indicadores" aria-label="<?= e(t('gateway.numeros', 'Números')) ?>">
    <div class="d2-indicador" data-area="gateway">
        <span class="d2-indicador-rotulo"><?= d2icone('grafico') ?><span><?= e(t('gateway.total_chamadas', 'Chamadas (total)')) ?></span></span>
        <span class="d2-indicador-valor d2-num"><?= e($fmt($total)) ?></span>
    </div>
    <div class="d2-indicador" data-area="gateway"<?= $semIdent > 0 ? ' data-tom="erro"' : '' ?>>
        <span class="d2-indicador-rotulo"><?= d2icone('alerta') ?><span><?= e(t('gateway.sem_identidade', 'Sem identidade')) ?></span></span>
        <span class="d2-indicador-valor d2-num"><?= e($fmt($semIdent)) ?></span>
        <span class="gw-indicador-meta"><?= (int) $pctSem ?>% <?= e(t('gateway.do_total', 'do total')) ?></span>
    </div>
    <div class="d2-indicador" data-area="gateway">
        <span class="d2-indicador-rotulo"><?= d2icone('check') ?><span><?= e(t('gateway.com_identidade', 'Com identidade')) ?></span></span>
        <span class="d2-indicador-valor d2-num"><?= e($fmt($comIdent)) ?></span>
        <span class="gw-indicador-meta"><?= (int) ($resumo['identidades'] ?? 0) ?> <?= e(t('gateway.identidades', 'identidades')) ?> · <?= (int) ($resumo['origens'] ?? 0) ?> <?= e(t('gateway.ips_origem_min', 'IPs de origem')) ?></span>
    </div>
</section>

<section class="d2-painel" data-area="gateway" aria-labelledby="t-gw-sem">
    <header class="d2-painel-cab">
        <span class="d2-painel-icone" aria-hidden="true"><?= d2icone('alerta') ?></span>
        <h3 class="d2-painel-titulo" id="t-gw-sem"><?= e(t('gateway.sem_ident_titulo', 'Chamadas sem identidade (token cru)')) ?></h3>
    </header>
    <div class="d2-painel-corpo is-justo" id="gw-tabela-sem"></div>
</section>

<section class="d2-painel" data-area="gateway" aria-labelledby="t-gw-por">
    <header class="d2-painel-cab">
        <span class="d2-painel-icone" aria-hidden="true"><?= d2icone('usuarios') ?></span>
        <h3 class="d2-painel-titulo" id="t-gw-por"><?= e(t('gateway.por_ident_titulo', 'Por identidade (token Ravi)')) ?></h3>
        <span class="d2-painel-sub"><?= e(t('gateway.por_ident_sub', 'linhas em destaque vieram de mais de uma origem')) ?></span>
    </header>
    <div class="d2-painel-corpo is-justo" id="gw-tabela-por"></div>
</section>
</div>
