<?php /** Barra do topo do painel: área atual, notificações e tema. */ ?>
<header class="casca-topo">
    <button type="button" class="d2-iconbtn casca-topo-menu" id="casca-topo-menu" aria-controls="casca-menu" aria-expanded="false" aria-label="<?= e(t('d2.menu', 'Menu')) ?>"><?= d2icone('menu') ?></button>
    <div class="casca-topo-area">
        <?php if ($itemAtivo): ?>
            <span class="casca-topo-area-pastilha"><?= d2icone($itemAtivo['icone']) ?></span>
            <span class="casca-topo-area-texto">
                <span class="casca-topo-area-nome"><?= e($itemAtivo['titulo']) ?></span>
                <?php if (!empty($title) && $title !== $itemAtivo['titulo']): ?><span class="casca-topo-area-sub"><?= e($title) ?></span><?php endif; ?>
            </span>
        <?php elseif (isset($currentProject)): ?>
            <span class="casca-topo-area-pastilha"><?= d2icone('documento') ?></span>
            <span class="casca-topo-area-texto">
                <span class="casca-topo-area-nome"><?= e($currentProject->name) ?></span>
                <span class="casca-topo-area-sub"><?= e(t('nav.projects', 'Projetos')) ?></span>
            </span>
        <?php else: ?>
            <span class="casca-topo-area-texto"><span class="casca-topo-area-nome"><?= e($title ?? config('app.name')) ?></span></span>
        <?php endif; ?>
    </div>
    <div class="casca-topo-acoes">
        <div style="position:relative">
            <button type="button" class="d2-iconbtn casca-topo-btn casca-sino" id="casca-sino" aria-haspopup="true" aria-expanded="false" aria-controls="casca-notif" data-tooltip="<?= e(t('d2.notificacoes', 'Notificações')) ?>" aria-label="<?= e(t('d2.notificacoes', 'Notificações')) ?>">
                <?= d2icone('sino') ?>
                <span class="casca-selo" id="casca-notif-selo" hidden>0</span>
            </button>
            <div class="casca-suspenso" id="casca-notif" hidden>
                <div class="casca-suspenso-cab"><?= e(t('d2.notificacoes', 'Notificações')) ?></div>
                <div class="casca-suspenso-lista" id="casca-notif-lista"></div>
            </div>
        </div>
        <span class="casca-topo-sep"></span>
        <button type="button" class="d2-iconbtn casca-topo-btn casca-tema" id="casca-tema" data-tooltip="Tema" aria-label="Tema">
            <?= d2icone('sol', 'icone-claro') ?><?= d2icone('lua', 'icone-escuro') ?><?= d2icone('auto', 'icone-auto') ?>
        </button>
    </div>
</header>
