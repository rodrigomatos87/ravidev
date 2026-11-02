<?php /** Menu lateral do painel. Dados em $menu (MenuService::montar). */ ?>
<nav class="casca-menu d2-noite" id="casca-menu" aria-label="<?= e(t('casca.menu_principal', 'Menu principal')) ?>">
    <div class="menu-topo">
        <a class="menu-marca" href="/dashboard">
            <img class="menu-marca-icone" src="/assets/images/logo.svg" alt="">
            <span class="menu-marca-nome"><?= e(config('app.name')) ?></span>
        </a>
        <button type="button" class="d2-iconbtn menu-recolher" id="menu-recolher" data-tooltip="<?= e(t('casca.recolher_menu', 'Recolher menu')) ?>" aria-label="<?= e(t('casca.recolher_menu', 'Recolher menu')) ?>">
            <?= d2icone('chevron-esquerda') ?>
        </button>
    </div>
    <div class="menu-rolagem">
        <div class="menu-secao">
            <span class="menu-secao-texto"><?= e(t('nav.projects', 'Projetos')) ?><?= $menu['todos'] ? ' · ' . e(t('nav.all_users_short', 'todos')) : '' ?></span>
            <?php if ($menu['admin']): ?>
                <button type="button" class="menu-secao-btn <?= $menu['todos'] ? 'is-ativo' : '' ?>" id="menu-todos-projetos" data-on="<?= $menu['todos'] ? '1' : '0' ?>"
                        data-tooltip="<?= e($menu['todos'] ? t('nav.all_users_off', 'Ver só os meus projetos') : t('nav.all_users_on', 'Ver os projetos de todos os usuários')) ?>" aria-pressed="<?= $menu['todos'] ? 'true' : 'false' ?>">
                    <?= d2icone('usuarios') ?>
                </button>
            <?php endif; ?>
            <a class="menu-secao-btn is-novo" href="/projects/create" data-tooltip="<?= e(t('nav.new_project', 'Novo projeto')) ?>" aria-label="<?= e(t('nav.new_project', 'Novo projeto')) ?>"><?= d2icone('mais') ?></a>
        </div>
        <ul class="menu-lista" id="menu-projetos" data-area="projetos">
            <?php foreach ($menu['projetos'] as $p): ?>
                <li class="menu-li">
                    <a class="menu-item <?= $p['ativo'] ? 'is-ativo' : '' ?>" href="/projects/<?= e($p['uuid']) ?>" <?= $p['ativo'] ? 'aria-current="page"' : '' ?> data-tooltip="<?= e($p['nome']) ?><?= $p['dono'] ? ' · ' . e($p['dono']) : '' ?>">
                        <span class="menu-item-pastilha"><?php if ($p['icone'] !== ''): ?><span class="menu-item-emoji" aria-hidden="true"><?= e($p['icone']) ?></span><?php else: ?><?= d2icone('documento', 'menu-item-icone') ?><?php endif; ?></span>
                        <span class="menu-item-texto">
                            <span class="menu-item-nome"><?= e($p['nome']) ?></span>
                            <?php if ($p['dono']): ?><span class="menu-item-sub"><?= e($p['dono']) ?></span><?php endif; ?>
                        </span>
                        <?php if ($p['trabalhando']): ?><span class="menu-ponto" data-tooltip="<?= e(t('nav.working', 'Há um chat trabalhando neste projeto')) ?>"></span><?php endif; ?>
                    </a>
                </li>
            <?php endforeach; ?>
            <?php if (!$menu['projetos']): ?>
                <li class="menu-vazio">
                    <?= d2icone('documento') ?>
                    <span><?= e(t('nav.no_projects', 'Nenhum projeto ainda')) ?></span>
                    <a class="d2-btn d2-btn-sm d2-btn-primario" href="/projects/create"><?= e(t('nav.create_first_project', 'Criar o primeiro')) ?></a>
                </li>
            <?php endif; ?>
        </ul>
        <?php foreach ($menu['secoes'] as $secao): ?>
            <div class="menu-secao"><span class="menu-secao-texto"><?= e($secao['titulo']) ?></span></div>
            <ul class="menu-lista">
                <?php foreach ($secao['itens'] as $i): ?>
                    <li class="menu-li" data-area="<?= e($i['area']) ?>">
                        <a class="menu-item <?= $i['ativo'] ? 'is-ativo' : '' ?>" href="<?= e($i['url']) ?>" <?= $i['ativo'] ? 'aria-current="page"' : '' ?> data-tooltip="<?= e($i['titulo']) ?>">
                            <span class="menu-item-pastilha"><?= d2icone($i['icone'], 'menu-item-icone') ?></span>
                            <span class="menu-item-texto"><span class="menu-item-nome"><?= e($i['titulo']) ?></span></span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endforeach; ?>
    </div>
    <div class="menu-rodape">
        <a class="menu-perfil" href="/settings/profile" data-tooltip="<?= e(t('nav.my_account', 'Minha conta')) ?>">
            <?php $avatar = auth()->user()?->avatar_url ?? null; $nome = (string) (auth()->user()?->name ?? 'U'); ?>
            <span class="d2-avatar" data-tom="neutro"><?php if ($avatar): ?><img src="<?= e($avatar) ?>" alt=""><?php else: ?><?= e(mb_strtoupper(mb_substr($nome, 0, 1))) ?><?php endif; ?></span>
            <span class="menu-perfil-texto">
                <span class="menu-perfil-nome"><?= e($nome) ?></span>
                <span class="menu-perfil-papel"><?= e($menu['admin'] ? t('role.admin', 'Administrador') : t('role.member', 'Membro')) ?></span>
            </span>
        </a>
        <button type="button" class="d2-iconbtn menu-sair" id="menu-sair" data-tooltip="<?= e(t('nav.logout', 'Sair')) ?>" aria-label="<?= e(t('nav.logout', 'Sair')) ?>"><?= d2icone('sair') ?></button>
    </div>
</nav>
