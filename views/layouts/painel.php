<?php
/**
 * Layout do painel (design system d2): a casca de janelas (casca/app.js) e a tela pedida.
 *
 * A view chama $this->layout('layouts/painel', [
 *   'title'       => 'Usuários',           título da página (aba do navegador e cabeçalho)
 *   'currentPage' => 'admin',              item ativo do menu (ver MenuService)
 *   'area'        => 'usuarios',           cor da área (data-area do d2); sem ela, vem do item ativo
 *   'subtitulo'   => 'Quem acessa o painel', (opcional) linha abaixo do título
 *   'hidePageTitle' => true,               (opcional) a view desenha o próprio cabeçalho
 *   'scripts'     => ['/assets/js/paginas/usuarios.js'], (opcional) módulos ES da página
 *   'estilos'     => ['/assets/css/paginas/usuarios.css'],
 * ]);
 */
$versao = static fn(string $caminho): string => $caminho . '?v=' . (@filemtime(PUBLIC_PATH . $caminho) ?: '1');
$menu = (new \Ravi\Services\MenuService())->montar((int) (auth()->id() ?? 0), $currentPage ?? null, isset($currentProject) ? (int) $currentProject->id : null);
$itemAtivo = \Ravi\Services\MenuService::itemAtivo($menu);
$area = $area ?? ($itemAtivo['area'] ?? 'painel');
$idioma = \Ravi\Core\Lang::active();
$textosD2 = json_decode((string) @file_get_contents(PUBLIC_PATH . '/assets/d2/idiomas/' . (in_array($idioma, ['pt', 'en', 'es'], true) ? $idioma : 'pt') . '.json'), true) ?: [];
$flashes = [];
foreach (['error' => 'erro', 'success' => 'ok', 'info' => 'info', 'warning' => 'aviso'] as $chave => $tipo) {
    if (!empty($flash[$chave])) {
        $flashes[] = ['tipo' => $tipo, 'texto' => (string) $flash[$chave]];
    }
}
$usuarioNome = (string) (auth()->user()?->name ?? auth()->user()?->email ?? 'Usuário');
$impersonator = \Ravi\Core\Session::get('impersonator');

// Cabeçalho da tela (título, resumo e ações) como HTML, reaproveitado no modo janela.
$cabecalhoTela = '';
if (empty($hidePageTitle)) {
    $cabecalhoTela = '<header class="tela-cab"><div class="tela-cab-texto"><h1 class="tela-titulo">' . e($pageTitle ?? $title ?? '') . '</h1>'
        . (!empty($subtitulo) ? '<p class="tela-sub">' . e($subtitulo) . '</p>' : '') . '</div>'
        . (!empty($acoes) ? '<div class="tela-cab-acoes">' . $acoes . '</div>' : '') . '</header>';
}
$dadosTela = [
    'titulo'      => (string) ($title ?? config('app.name')),
    'subtitulo'   => (string) ($subtitulo ?? ''),
    'area'        => (string) $area,
    'icone'       => (string) ($icone ?? ($itemAtivo['icone'] ?? 'grade')),
    'pagina'      => (string) ($currentPage ?? ''),
    'url'         => (string) ($_SERVER['REQUEST_URI'] ?? '/'),
    'larga'       => !empty($paginaLarga),
    'scripts'     => array_values(array_map($versao, (array) ($scripts ?? []))),
    'estilos'     => array_values(array_map($versao, (array) ($estilos ?? []))),
    'flash'       => $flashes,
];

// Pedida por uma janela do palco: só o fragmento da tela, em JSON (Response::view troca o Content-Type).
if (janela_pedida()) {
    echo json_encode($dadosTela + ['html' => $cabecalhoTela . ($content ?? '')], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return;
}
$preferencias = auth()->id() ? \Ravi\Controllers\PreferenciaController::daCasca((int) auth()->id()) : [];
?>
<!DOCTYPE html>
<html lang="<?= e(['pt' => 'pt-BR', 'en' => 'en-US', 'es' => 'es-ES'][$idioma] ?? 'pt-BR') ?>" class="casca-ativa" data-area="<?= e($area) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#f2f6f8">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <meta name="ws-token" content="<?= e(ws_token()) ?>">
    <title><?= e($title ?? config('app.name')) ?> · <?= e(config('app.name')) ?></title>
    <link rel="icon" type="image/svg+xml" href="/assets/images/logo.svg">
    <link rel="preload" href="/assets/d2/fontes/ibmplexsans-variavel-latin.woff2" as="font" type="font/woff2" crossorigin>
    <script src="<?= $versao('/assets/d2/js/tema-inicial.js') ?>"></script>
    <?php // Import map: os módulos das páginas importam '/assets/d2/js/casca.js' sem ?v; o mapa leva todos à
          // MESMA URL versionada, senão o navegador instancia a casca duas vezes (toasts e listeners em dobro). ?>
    <script type="importmap"><?= json_encode(['imports' => [
        '/assets/d2/js/casca.js'   => $versao('/assets/d2/js/casca.js'),
        '/assets/d2/js/d2ui.js'    => $versao('/assets/d2/js/d2ui.js'),
        '/assets/d2/js/tema.js'    => $versao('/assets/d2/js/tema.js'),
        '/assets/d2/js/eventos.js' => $versao('/assets/d2/js/eventos.js'),
    ] + array_reduce(glob(PUBLIC_PATH . '/assets/d2/js/casca/*.js') ?: [], static function (array $mapa, string $arquivo) use ($versao): array {
        $url = '/assets/d2/js/casca/' . basename($arquivo);
        $mapa[$url] = $versao($url);
        return $mapa;
    }, [])], JSON_UNESCAPED_SLASHES) ?></script>
    <link rel="stylesheet" href="<?= $versao('/assets/d2/css/d2.css') ?>">
    <link rel="stylesheet" href="<?= $versao('/assets/d2/css/casca.css') ?>">
    <link rel="stylesheet" href="<?= $versao('/assets/d2/css/paginas.css') ?>">
    <?php foreach ((array) ($estilos ?? []) as $css): ?>
        <link rel="stylesheet" href="<?= e($versao($css)) ?>">
    <?php endforeach; ?>
</head>
<body>
<?php // A casca (menu, topo, palco de janelas, rodapé) é montada por casca/app.js dentro de #casca. ?>
<div id="casca" class="casca"></div>
<?php if ($impersonator): ?>
    <div class="casca-faixa" role="status">
        <?= d2icone('olho') ?>
        <span><?= e(t('casca.impersonando', 'Você está vendo como')) ?> <strong><?= e($usuarioNome) ?></strong> (<?= e($impersonator['name'] ?? 'admin') ?>).</span>
        <form method="POST" action="/impersonation/stop" data-pagina-cheia>
            <?= csrf_field() ?>
            <button type="submit" class="d2-btn d2-btn-sm d2-btn-secundario"><?= e(t('casca.voltar_conta', 'Voltar para minha conta')) ?></button>
        </form>
    </div>
<?php endif; ?>
<?php // Tela pedida, já desenhada pelo PHP: a casca a abre como janela (ou como painel ao fundo). ?>
<div class="pagina<?= !empty($paginaLarga) ? ' is-larga' : '' ?>" id="tela-inicial" hidden data-url="<?= e($dadosTela['url']) ?>">
    <?= $cabecalhoTela ?>
    <?= $content ?? '' ?>
</div>
<form id="form-sair" action="/logout" method="POST" hidden><?= csrf_field() ?></form>
<script>
window.RAVI_CASCA = <?= json_encode([
    'idioma'  => $idioma,
    'sprite'  => '/assets/d2/icones/sprite.svg',
    'csrf'    => csrf_token(),
    'wsUrl'   => websocket_url(),
    'usuario' => ['id' => auth()->id(), 'nome' => $usuarioNome, 'admin' => $menu['admin']],
    'versao'  => RAVI_VERSION,
    'appNome' => config('app.name'),
    'flash'   => $flashes,
    // Menu (montado pelo MenuService), preferências da casca e a tela inicial (já no HTML, em #tela-inicial)
    'menu'    => ['projetos' => $menu['projetos'], 'secoes' => $menu['secoes'], 'todos' => $menu['todos']],
    'preferencias' => $preferencias,
    'tela'    => $dadosTela,
    // Páginas que saem da casca (têm layout próprio): a casca navega de verdade em vez de abrir janela
    'paginasCheias' => ['/ide', '/chat/', '/logout', '/p/', '/login', '/admin/impersonate/', '/impersonation/stop'],
    'textos'  => $textosD2 + [
        'casca.sem_notificacoes' => t('casca.sem_notificacoes', 'Nenhuma tarefa recente.'),
        'casca.concluida' => t('casca.concluida', 'Concluída'),
        'casca.falhou' => t('casca.falhou', 'Falhou'),
        'casca.erro_generico' => t('casca.erro_generico', 'Não foi possível concluir.'),
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
</script>
<script type="module">import '/assets/d2/js/casca.js';</script>
</body>
</html>
