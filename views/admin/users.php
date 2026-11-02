<?php
/**
 * Admin: usuários da equipe e gasto de tokens. Layout do painel (d2).
 * Dados: AdminController@users. A tabela é montada pelo módulo admin-usuarios.js a partir do JSON abaixo;
 * as ações continuam sendo POST de formulário (o controller redireciona com mensagem).
 */
$this->layout('layouts/painel', [
    'title'       => t('admin.usuarios.titulo', 'Usuários da equipe'),
    'currentPage' => 'admin',
    'area'        => 'usuarios',
    'subtitulo'   => t('admin.usuarios.sub', 'Gasto real de tokens por usuário (estimativa de API com cache).'),
    'acoes'       => '<button type="button" class="d2-btn d2-btn-primario" id="btn-novo-usuario">' . d2icone('mais') . '<span>' . e(t('admin.usuarios.novo', 'Novo usuário')) . '</span></button>',
    'scripts'     => ['/assets/js/paginas/admin-usuarios.js'],
    'estilos'     => ['/assets/css/paginas/admin-usuarios.css'],
]);
$linhas = (array) ($rows ?? []);
$pendentes = array_values(array_filter($linhas, static fn($r) => ($r['approval'] ?? 'approved') === 'pending'));
$equipe = array_values(array_filter($linhas, static fn($r) => ($r['approval'] ?? 'approved') !== 'pending'));
$dados = [
    'usuarios' => array_map(static fn($r) => [
        'id'              => (int) $r['id'],
        'nome'            => (string) ($r['name'] ?? ''),
        'email'           => (string) ($r['email'] ?? ''),
        'papel'           => (string) ($r['role'] ?? 'user'),
        'ativo'           => (bool) ($r['is_active'] ?? true),
        'projetos'        => (int) ($r['projects'] ?? 0),
        'tokens'          => (int) ($r['tokens'] ?? 0),
        'tokens_texto'    => formatTokens((int) ($r['tokens'] ?? 0)),
        'custo'           => (float) ($r['cost'] ?? 0),
        'custo_texto'     => formatCost((float) ($r['cost'] ?? 0)),
        'escolhe_licenca' => (bool) ($r['license_choice'] ?? false),
    ], $equipe),
    'idAtual'     => (int) ($currentId ?? 0),
    'smtpPronto'  => !empty($smtpConfigured),
];
?>

<section class="indicadores" aria-label="<?= e(t('admin.usuarios.totais', 'Totais da equipe')) ?>">
    <div class="d2-indicador" data-area="usuarios">
        <span class="d2-indicador-rotulo"><?= d2icone('usuarios') ?><span><?= e(t('admin.usuarios.qtd', 'Usuários')) ?></span></span>
        <span class="d2-indicador-valor d2-num"><?= count($equipe) ?></span>
    </div>
    <div class="d2-indicador" data-area="licencas">
        <span class="d2-indicador-rotulo"><?= d2icone('grafico') ?><span><?= e(t('admin.usuarios.tokens_equipe', 'Tokens (equipe)')) ?></span></span>
        <span class="d2-indicador-valor d2-num"><?= e(formatTokens($total_tokens ?? 0)) ?></span>
    </div>
    <div class="d2-indicador" data-area="gateway">
        <span class="d2-indicador-rotulo"><?= d2icone('dinheiro') ?><span><?= e(t('admin.usuarios.custo_equipe', 'Custo (equipe)')) ?></span></span>
        <span class="d2-indicador-valor d2-num"><?= e(formatCost($total_cost ?? 0)) ?></span>
    </div>
</section>

<?php if (!empty($pendentes)): ?>
<section class="d2-painel" data-area="usuarios" aria-labelledby="t-pendentes">
    <header class="d2-painel-cab">
        <span class="d2-painel-icone" aria-hidden="true"><?= d2icone('relogio') ?></span>
        <h3 class="d2-painel-titulo" id="t-pendentes"><?= e(t('admin.usuarios.pendentes', 'Pendentes de aprovação')) ?></h3>
        <span class="d2-selo d2-selo-aviso" data-tom="aviso"><span class="d2-selo-texto"><?= count($pendentes) ?></span></span>
        <span class="d2-painel-sub"><?= e(t('admin.usuarios.pendentes_sub', 'Cadastros aguardando liberação. Aprove apenas quem é da equipe.')) ?></span>
    </header>
    <div class="d2-painel-corpo">
        <ul class="lista-acionavel">
            <?php foreach ($pendentes as $u): ?>
            <li class="lista-item">
                <span class="lista-item-icone" aria-hidden="true"><?= d2icone('conta') ?></span>
                <span class="lista-item-texto">
                    <span class="lista-item-titulo"><?= e($u['name']) ?></span>
                    <span class="lista-item-sub"><?= e($u['email']) ?></span>
                </span>
                <form method="POST" action="/admin/users/<?= (int) $u['id'] ?>/approve" class="js-pendente">
                    <?= csrf_field() ?>
                    <button type="submit" class="d2-btn d2-btn-sm d2-btn-primario"><?= d2icone('check') ?><span><?= e(t('admin.usuarios.aprovar', 'Aprovar')) ?></span></button>
                </form>
                <form method="POST" action="/admin/users/<?= (int) $u['id'] ?>/reject" class="js-pendente js-rejeitar" data-nome="<?= e($u['name'] ?: $u['email']) ?>">
                    <?= csrf_field() ?>
                    <button type="submit" class="d2-btn d2-btn-sm d2-btn-fantasma"><?= d2icone('fechar') ?><span><?= e(t('admin.usuarios.rejeitar', 'Rejeitar')) ?></span></button>
                </form>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
</section>
<?php endif; ?>

<section class="d2-painel" data-area="usuarios" aria-labelledby="t-equipe">
    <header class="d2-painel-cab">
        <span class="d2-painel-icone" aria-hidden="true"><?= d2icone('usuarios') ?></span>
        <h3 class="d2-painel-titulo" id="t-equipe"><?= e(t('admin.usuarios.equipe', 'Equipe')) ?></h3>
        <div class="d2-painel-acoes">
            <label class="d2-search">
                <?= d2icone('busca') ?>
                <span class="d2-oculto-leitor"><?= e(t('admin.usuarios.buscar', 'Buscar por nome ou e-mail')) ?></span>
                <input type="search" class="d2-control" id="busca-usuarios" placeholder="<?= e(t('admin.usuarios.buscar', 'Buscar por nome ou e-mail')) ?>" autocomplete="off">
            </label>
        </div>
    </header>
    <div class="d2-painel-corpo" id="tabela-usuarios" aria-busy="true"></div>
</section>

<form method="POST" id="form-acao" hidden><?= csrf_field() ?></form>

<script type="application/json" id="dados-pagina"><?= json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
