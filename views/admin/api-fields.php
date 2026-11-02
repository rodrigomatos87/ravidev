<?php
/**
 * Admin: campos de identidade personalizados de um token do Gateway de API. Layout do painel (d2).
 * Dados: AdminController@apiGatewayFields. A tabela e o modal de adicionar ficam no módulo da página.
 */
$tid = (int) ($token['id'] ?? 0);
$this->layout('layouts/painel', [
    'title'       => t('gateway.campos_titulo', 'Campos de identidade'),
    'currentPage' => 'admin-api',
    'area'        => 'gateway',
    'subtitulo'   => t('gateway.campos_sub', 'O que cada chamada deste token precisa trazer para identificar o sistema de origem.'),
    'acoes'       => '<a class="d2-btn d2-btn-fantasma" href="/admin/api">' . d2icone('seta-esquerda') . '<span>' . e(t('gateway.voltar', 'Voltar ao Gateway')) . '</span></a>'
                   . '<button type="button" class="d2-btn d2-btn-primario" data-acao="novo-campo">' . d2icone('mais') . '<span>' . e(t('gateway.adicionar_campo', 'Adicionar campo')) . '</span></button>',
    'scripts'     => ['/assets/js/paginas/admin-gateway.js'],
    'estilos'     => ['/assets/css/paginas/admin-gateway.css'],
]);
$dadosPagina = [
    'tela'   => 'campos',
    'token'  => $tid,
    'campos' => array_map(static fn($f) => [
        'id'          => (int) $f['id'],
        'chave'       => (string) $f['field_key'],
        'cabecalho'   => 'x-ravi-' . str_replace('_', '-', (string) $f['field_key']),
        'rotulo'      => (string) ($f['label'] ?? ''),
        'obrigatorio' => (int) $f['required'] === 1,
    ], $fields ?? []),
];
?>
<script type="application/json" id="dados-pagina"><?= json_encode($dadosPagina, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<div class="gw-tela d2-pilha" data-tela="campos" data-token="<?= $tid ?>">
<?php include __DIR__ . '/../partials/gateway/token-cab.php'; ?>

<div class="d2-faixa">
    <?= d2icone('info') ?>
    <div class="d2-faixa-texto"><?= e(t('gateway.campos_ajuda', 'Cada campo é enviado pelo cliente no header x-ravi-<chave>. Marque obrigatório para o Gateway rejeitar qualquer chamada deste token que não traga o campo preenchido. Os campos tokenravi (token do sistema Ravi, diferente do token da API) e server_ips já são enviados por sistemas de monitoramento compatíveis.')) ?></div>
</div>

<section class="d2-painel" data-area="gateway" aria-labelledby="t-gw-campos">
    <header class="d2-painel-cab">
        <span class="d2-painel-icone" aria-hidden="true"><?= d2icone('lista') ?></span>
        <h3 class="d2-painel-titulo" id="t-gw-campos"><?= e(t('gateway.campos_token', 'Campos deste token')) ?></h3>
        <span class="d2-painel-sub"><?= e(t('gateway.obrigatorio_na_hora', 'obrigatório salva na hora')) ?></span>
    </header>
    <div class="d2-painel-corpo is-justo" id="gw-tabela-campos"></div>
</section>
</div>
