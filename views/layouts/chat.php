<?php
/**
 * Layout do chat (tela cheia, sem a casca do painel) no design system d2.
 * Ordem dos scripts: os clássicos com defer (api, markdown, realce, chat.js) rodam na ordem do
 * documento e, por último, o módulo da página (chat-boot.js), que liga o D2UI e o tema e
 * instancia o RaviChat. Sem JS nem CSS inline: dados da página vão em #dados-pagina.
 */
$versao = static fn(string $caminho): string => $caminho . '?v=' . (@filemtime(PUBLIC_PATH . $caminho) ?: '1');
$idioma = \Ravi\Core\Lang::active();
$textosD2 = json_decode((string) @file_get_contents(PUBLIC_PATH . '/assets/d2/idiomas/' . (in_array($idioma, ['pt', 'en', 'es'], true) ? $idioma : 'pt') . '.json'), true) ?: [];
$casca = [
    'idioma' => $idioma,
    'sprite' => '/assets/d2/icones/sprite.svg',
    'csrf'   => csrf_token(),
    'flash'  => [],
    'textos' => $textosD2 + [
        'casca.erro_generico' => t('casca.erro_generico', 'Não foi possível concluir.'),
    ],
];
?>
<!DOCTYPE html>
<html lang="<?= e(['pt' => 'pt-BR', 'en' => 'en-US', 'es' => 'es-ES'][$idioma] ?? 'pt-BR') ?>" data-area="projetos">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#f2f6f8">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <meta name="ws-token" content="<?= e(ws_token()) ?>">
    <title><?= e($title ?? 'Chat') ?> · <?= e(config('app.name')) ?></title>
    <link rel="icon" type="image/svg+xml" href="/assets/images/logo.svg">
    <link rel="preload" href="/assets/d2/fontes/ibmplexsans-variavel-latin.woff2" as="font" type="font/woff2" crossorigin>
    <script src="<?= $versao('/assets/d2/js/tema-inicial.js') ?>"></script>
    <link rel="stylesheet" href="<?= $versao('/assets/d2/css/d2.css') ?>">
    <link rel="stylesheet" href="<?= $versao('/assets/css/chat.css') ?>">
</head>
<body class="chat-page">
    <?= $content ?? '' ?>

    <script type="application/json" id="dados-casca"><?= json_encode($casca, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
    <script defer src="<?= $versao('/assets/js/api.js') ?>"></script>
    <script defer src="<?= $versao('/assets/js/markdown.js') ?>"></script>
    <script defer src="<?= $versao('/assets/js/syntax-highlight.js') ?>"></script>
    <script defer src="<?= $versao('/assets/js/chat.js') ?>"></script>
    <script type="module" src="<?= $versao('/assets/js/paginas/chat-boot.js') ?>"></script>
</body>
</html>
