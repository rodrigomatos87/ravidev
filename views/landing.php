<?php
/**
 * Página inicial pública (visitante deslogado) no design system d2.
 * Sem CSS nem JS inline: estilos em /assets/css/landing.css; o tema vem do tema-inicial.js.
 */
$versao = static fn(string $caminho): string => $caminho . '?v=' . (@filemtime(PUBLIC_PATH . $caminho) ?: '1');
$idioma = \Ravi\Core\Lang::active();
$nomeApp = (string) config('app.name', 'Ravi Developer');
$recursos = [
    ['icone' => 'usuarios',   'titulo' => t('landing.rec1_titulo', 'Time de especialistas'), 'texto' => t('landing.rec1_texto', 'Arquiteto, Frontend, Backend, Banco, QA e Segurança: coordenados e trabalhando em paralelo.')],
    ['icone' => 'cadeado',    'titulo' => t('landing.rec2_titulo', 'Ambiente isolado'),     'texto' => t('landing.rec2_texto', 'Cada tarefa roda num ambiente próprio e descartável, com liberdade para instalar e compilar.')],
    ['icone' => 'olho',       'titulo' => t('landing.rec3_titulo', 'Prévia ao vivo'),        'texto' => t('landing.rec3_texto', 'Veja o sistema funcionando num link antes de baixar, com banco e usuário de demonstração.')],
    ['icone' => 'exportar',   'titulo' => t('landing.rec4_titulo', 'Entrega profissional'),  'texto' => t('landing.rec4_texto', 'Projeto completo em .zip com README, guia de instalação e tudo pronto para implantar.')],
];
$passos = [
    ['titulo' => t('landing.passo1_titulo', 'Você descreve'),  'texto' => t('landing.passo1_texto', 'Conte no chat o que quer construir ou ajustar, em linguagem natural.')],
    ['titulo' => t('landing.passo2_titulo', 'Aprova o plano'), 'texto' => t('landing.passo2_texto', 'O Arquiteto monta o plano e espera o seu OK antes de desenvolver.')],
    ['titulo' => t('landing.passo3_titulo', 'Recebe pronto'),  'texto' => t('landing.passo3_texto', 'O time desenvolve, testa e entrega, com link de prévia e download.')],
];
?>
<!DOCTYPE html>
<html lang="<?= e(['pt' => 'pt-BR', 'en' => 'en-US', 'es' => 'es-ES'][$idioma] ?? 'pt-BR') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($nomeApp) ?>: <?= e(t('landing.titulo_pagina', 'plataforma interna de desenvolvimento com IA')) ?></title>
    <meta name="description" content="<?= e(t('landing.descricao', 'Plataforma interna da Ravi Systems: um time de agentes de IA que planeja, programa, testa e entrega software num ambiente isolado.')) ?>">
    <link rel="icon" type="image/svg+xml" href="/assets/images/logo.svg">
    <link rel="preload" href="/assets/d2/fontes/ibmplexsans-variavel-latin.woff2" as="font" type="font/woff2" crossorigin>
    <script src="<?= $versao('/assets/d2/js/tema-inicial.js') ?>"></script>
    <link rel="stylesheet" href="<?= $versao('/assets/d2/css/d2.css') ?>">
    <link rel="stylesheet" href="<?= $versao('/assets/css/landing.css') ?>">
</head>
<body class="landing">
    <header class="landing-topo">
        <div class="landing-faixa landing-nav">
            <a href="/" class="landing-marca" aria-label="<?= e($nomeApp) ?>">
                <span class="landing-marca-selo" aria-hidden="true">R</span>
                <span><?= e($nomeApp) ?></span>
            </a>
            <nav class="landing-nav-acoes" aria-label="<?= e(t('landing.acesso', 'Acesso')) ?>">
                <a href="/login" class="d2-btn d2-btn-fantasma landing-so-largo"><?= e(t('landing.entrar', 'Entrar')) ?></a>
                <a href="/register" class="d2-btn d2-btn-primary"><?= e(t('landing.solicitar_acesso', 'Solicitar acesso')) ?></a>
            </nav>
        </div>
    </header>

    <main class="landing-faixa">
        <section class="landing-hero">
            <span class="landing-pilula"><span class="landing-pilula-ponto" aria-hidden="true"></span><?= e(t('landing.pilula', 'Plataforma interna da Ravi Systems')) ?></span>
            <h1><?= e(t('landing.hero_titulo_1', 'Software pronto, construído por um')) ?><br><span class="landing-destaque"><?= e(t('landing.hero_titulo_2', 'time de agentes de IA')) ?></span></h1>
            <p class="landing-hero-texto"><?= e(t('landing.hero_texto', 'Descreva o que você precisa. O Ravi planeja, programa, testa e entrega num ambiente isolado, com prévia ao vivo e o projeto pronto para baixar.')) ?></p>
            <div class="landing-chamadas">
                <a href="/login" class="d2-btn d2-btn-primary d2-btn-lg"><?= e(t('landing.entrar_plataforma', 'Entrar na plataforma')) ?></a>
                <a href="/register" class="d2-btn d2-btn-secundario d2-btn-lg"><?= e(t('landing.solicitar_acesso', 'Solicitar acesso')) ?><?= d2icone('seta-direita') ?></a>
            </div>
            <p class="landing-nota"><?= d2icone('cadeado', 'd2-icone-sm') ?> <?= t('landing.nota_acesso', 'O acesso é <strong>restrito à equipe</strong>. Solicite o acesso e um administrador aprova.') ?></p>
        </section>

        <section class="landing-recursos" aria-label="<?= e(t('landing.recursos', 'Recursos')) ?>">
            <?php foreach ($recursos as $rec): ?>
            <article class="landing-cartao">
                <span class="landing-cartao-icone"><?= d2icone($rec['icone'], 'd2-icone-lg') ?></span>
                <h3><?= e($rec['titulo']) ?></h3>
                <p><?= e($rec['texto']) ?></p>
            </article>
            <?php endforeach; ?>
        </section>

        <section class="landing-passos" aria-labelledby="landing-como-funciona">
            <h2 id="landing-como-funciona"><?= e(t('landing.como_funciona', 'Como funciona')) ?></h2>
            <ol class="landing-passos-lista">
                <?php foreach ($passos as $i => $passo): ?>
                <li class="landing-cartao">
                    <span class="landing-passo-numero" aria-hidden="true"><?= $i + 1 ?></span>
                    <h3><?= e($passo['titulo']) ?></h3>
                    <p><?= e($passo['texto']) ?></p>
                </li>
                <?php endforeach; ?>
            </ol>
        </section>

        <section class="landing-final">
            <h2><?= e(t('landing.final_titulo', 'Pronto para começar?')) ?></h2>
            <p><?= e(t('landing.final_texto', 'Entre com a sua conta ou solicite acesso à equipe.')) ?></p>
            <div class="landing-chamadas">
                <a href="/login" class="d2-btn d2-btn-primary d2-btn-lg"><?= e(t('landing.entrar', 'Entrar')) ?></a>
                <a href="/register" class="d2-btn d2-btn-secundario d2-btn-lg"><?= e(t('landing.solicitar_acesso', 'Solicitar acesso')) ?></a>
            </div>
        </section>
    </main>

    <footer class="landing-rodape">
        <div class="landing-faixa landing-rodape-linha">
            <span>&copy; <?= date('Y') ?> Ravi Systems · <?= e($nomeApp) ?></span>
            <span><?= e(t('landing.rodape', 'Desenvolvimento autônomo com IA')) ?></span>
        </div>
    </footer>
</body>
</html>
