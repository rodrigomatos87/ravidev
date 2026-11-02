<?php
/**
 * Minha conta, GitHub: conexão da conta do GitHub por usuário.
 * Layout do painel (d2). Dados: GitHubController@page. Comportamento: /assets/js/paginas/conta.js.
 */
$this->layout('layouts/painel', [
    'title'       => t('conta.titulo', 'Minha conta'),
    'currentPage' => 'account',
    'area'        => 'conta',
    'subtitulo'   => t('conta.gh_sub', 'Conecte sua conta do GitHub para versionar e sincronizar seus projetos.'),
    'scripts'     => ['/assets/js/paginas/conta.js'],
    'estilos'     => ['/assets/css/paginas/conta.css'],
]);
$ghSize = 18;
?>

<div class="d2-pilha">
    <?php include __DIR__ . '/../partials/account-tabs.php'; ?>

    <section class="d2-painel" data-area="conta" aria-labelledby="t-gh">
        <header class="d2-painel-cab">
            <span class="d2-painel-icone" aria-hidden="true"><?php include __DIR__ . '/../partials/icon-github.php'; ?></span>
            <h3 class="d2-painel-titulo" id="t-gh"><?= e(t('conta.gh', 'GitHub')) ?></h3>
            <?php if (!empty($configured)): ?>
                <div class="d2-painel-acoes">
                    <span class="d2-selo d2-selo-<?= !empty($connected) ? 'ok' : 'neutro' ?>"><span class="d2-ponto" aria-hidden="true"></span><span class="d2-selo-texto"><?= e(!empty($connected) ? t('conta.gh_conectado', 'Conectado') : t('conta.gh_desconectado', 'Não conectado')) ?></span></span>
                </div>
            <?php endif; ?>
        </header>
        <div class="d2-painel-corpo d2-pilha">
            <p class="texto-mudo"><?= e(t('conta.gh_texto', 'A conexão é por usuário e fica salva: você só precisa conectar uma vez.')) ?></p>

            <?php if (empty($configured)): ?>
                <div class="d2-faixa d2-faixa-aviso">
                    <?= d2icone('alerta') ?>
                    <div class="d2-faixa-texto">
                        <span class="d2-faixa-titulo"><?= e(t('conta.gh_nao_config', 'Integração ainda não configurada.')) ?></span>
                        <?= e(t('conta.gh_nao_config_texto', 'O administrador precisa cadastrar o aplicativo do GitHub antes de conectar contas.')) ?>
                    </div>
                    <div class="d2-faixa-acoes"><a class="d2-btn d2-btn-sm d2-btn-secundario" href="/settings/github"><?= d2icone('atualizar') ?><span><?= e(t('conta.recarregar', 'Verificar de novo')) ?></span></a></div>
                </div>
            <?php elseif (!empty($connected)): ?>
                <div class="conta-gh">
                    <?php if (!empty($githubAvatar)): ?>
                        <span class="d2-avatar d2-avatar-lg"><img src="<?= e($githubAvatar) ?>" alt=""></span>
                    <?php endif; ?>
                    <div class="lista-item-texto">
                        <span class="lista-item-titulo">@<?= e($githubLogin ?? '') ?></span>
                        <?php if (!empty($connectedAt)): ?>
                            <span class="lista-item-sub"><?= e(t('conta.gh_desde', 'Conectado em')) ?> <?= e(date('d/m/Y H:i', strtotime((string) $connectedAt))) ?></span>
                        <?php endif; ?>
                    </div>
                    <form method="POST" action="/settings/github/disconnect" id="gh-desconectar-form" class="conta-gh-acao">
                        <?= csrf_field() ?>
                        <button type="submit" class="d2-btn d2-btn-sm d2-btn-secundario"><?= d2icone('sair') ?><span><?= e(t('conta.gh_desconectar', 'Desconectar')) ?></span></button>
                    </form>
                </div>
                <div class="d2-faixa">
                    <?= d2icone('info') ?>
                    <div class="d2-faixa-texto"><?= e(t('conta.gh_proximo', 'Agora, em qualquer projeto, abra a seção Git para ligar a um repositório existente ou criar um novo.')) ?></div>
                    <div class="d2-faixa-acoes"><a class="d2-btn d2-btn-sm d2-btn-secundario" href="/projects"><?= d2icone('lista') ?><span><?= e(t('conta.gh_projetos', 'Ver projetos')) ?></span></a></div>
                </div>
            <?php else: ?>
                <div class="d2-vazio">
                    <span class="d2-vazio-icone" aria-hidden="true"><?php include __DIR__ . '/../partials/icon-github.php'; ?></span>
                    <p class="d2-vazio-titulo"><?= e(t('conta.gh_vazio', 'Você ainda não conectou o GitHub')) ?></p>
                    <p class="d2-vazio-texto"><?= e(t('conta.gh_vazio_texto', 'Você será levado ao GitHub para autorizar o acesso aos seus repositórios, inclusive privados. O acesso fica guardado de forma cifrada.')) ?></p>
                    <a href="/settings/github/connect" class="d2-btn d2-btn-primario" id="gh-conectar"><?php include __DIR__ . '/../partials/icon-github.php'; ?><span><?= e(t('conta.gh_conectar', 'Conectar com o GitHub')) ?></span></a>
                </div>
            <?php endif; ?>
        </div>
    </section>
</div>
