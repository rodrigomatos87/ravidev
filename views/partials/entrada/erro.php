<?php
/**
 * Conteúdo das páginas de erro (403, 404, 500) dentro da casca de entrada.
 * Recebe $entradaErro = ['codigo', 'icone', 'tom' (aviso|erro), 'titulo' => [chave, padrão], 'texto' => [chave, padrão]].
 * Defensivo: pode rodar sem a View e sem os helpers (tratador de exceções do bootstrap).
 */
$errT = static function (array $par): string {
    [$chave, $padrao] = $par;
    if (function_exists('t')) {
        try { return t($chave, $padrao); } catch (\Throwable $erro) { /* idioma indisponível */ }
    }
    return $padrao;
};
$errE = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$errIcone = static fn(string $nome): string => '<svg class="d2-icone" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><use href="/assets/d2/icones/sprite.svg#' . $errE($nome) . '"/></svg>';

$entradaTitulo = $errT($entradaErro['titulo']);
ob_start();
?>
<header class="entrada-cab">
    <span class="entrada-cab-icone is-<?= $errE($entradaErro['tom']) ?>"><?= $errIcone($entradaErro['icone']) ?></span>
    <span class="entrada-erro-codigo" aria-hidden="true"><?= $errE($entradaErro['codigo']) ?></span>
    <h1 class="entrada-titulo"><?= $errE($entradaTitulo) ?></h1>
    <p class="entrada-texto"><?= $errE($errT($entradaErro['texto'])) ?></p>
</header>
<div class="entrada-erro-acoes">
    <a href="/dashboard" class="d2-btn d2-btn-primary"><?= $errIcone('painel') ?><span><?= $errE($errT(['erro.ir_inicio', 'Ir para o início'])) ?></span></a>
    <button type="button" class="d2-btn d2-btn-fantasma" data-acao="voltar"><?= $errIcone('seta-esquerda') ?><span><?= $errE($errT(['erro.voltar', 'Voltar à página anterior'])) ?></span></button>
</div>
<?php
$entradaConteudo = ob_get_clean();
$entradaFlash = [];
include __DIR__ . '/casca.php';
