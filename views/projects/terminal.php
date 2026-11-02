<?php
/**
 * Terminal web do projeto no painel d2. O xterm (vendor) e o módulo da página vêm por 'scripts';
 * a conexão usa a meta ws-token do layout, no mesmo protocolo da tela anterior.
 */
$uuid = (string) $project->uuid;
$this->layout('layouts/painel', [
    'title'          => t('terminal.titulo', 'Terminal') . ': ' . $project->name,
    'currentPage'    => 'projects',
    'currentProject' => $project,
    'area'           => 'projetos',
    'hidePageTitle'  => true,
    'scripts'        => [
        '/assets/vendor/xterm/xterm.js',
        '/assets/vendor/xterm/xterm-addon-fit.js',
        '/assets/js/paginas/terminal.js',
    ],
    'estilos'        => ['/assets/vendor/xterm/xterm.css', '/assets/css/paginas/projeto.css'],
]);
?>

<header class="projeto-cab">
    <span class="projeto-emoji projeto-emoji-grande" data-cor="<?= e((string) ($project->color ?? '')) ?>" aria-hidden="true"><?= e(projectIcon($project->icon ?? null)) ?></span>
    <div class="projeto-cab-texto">
        <div class="projeto-cab-linha">
            <h1 class="projeto-cab-titulo"><?= e(t('terminal.titulo', 'Terminal')) ?></h1>
            <span id="terminal-estado" role="status" aria-live="polite">
                <span class="d2-selo d2-selo-neutro" data-tom="neutro"><span class="d2-ponto is-pulsando" aria-hidden="true"></span><span class="d2-selo-texto"><?= e(t('terminal.conectando', 'Conectando…')) ?></span></span>
            </span>
        </div>
        <p class="projeto-cab-desc"><?= e($project->name) ?> · <?= e(t('terminal.dica', 'Mesmo ambiente de trabalho usado pelo agente neste projeto')) ?></p>
    </div>
    <div class="projeto-cab-acoes">
        <a class="d2-btn d2-btn-secundario" href="/projects/<?= e($uuid) ?>"><?= d2icone('seta-esquerda') ?><span><?= e(t('terminal.voltar', 'Voltar ao projeto')) ?></span></a>
        <button type="button" class="d2-btn d2-btn-primario" id="terminal-reconectar"><?= d2icone('atualizar') ?><span><?= e(t('terminal.reconectar', 'Reconectar')) ?></span></button>
    </div>
</header>

<section class="d2-painel terminal-painel" data-area="projetos" aria-label="<?= e(t('terminal.titulo', 'Terminal')) ?>">
    <div class="d2-painel-corpo">
        <div class="terminal-caixa" id="terminal"></div>
    </div>
</section>

<script type="application/json" id="dados-pagina"><?= json_encode(['uuid' => $uuid], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>
