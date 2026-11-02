<?php
/**
 * Layout das telas de entrada (design system d2): login, cadastro, recuperação de senha e duas etapas.
 * A view chama $this->layout('layouts/auth', ['title' => '...']) e desenha só o conteúdo do cartão.
 * Estrutura, vitrine e rodapé ficam em views/partials/entrada/casca.php (compartilhado com as páginas de erro).
 */
$entradaTitulo = $title ?? config('app.name');
$entradaConteudo = $content ?? '';
$entradaFlash = is_array($flash ?? null) ? $flash : [];
include __DIR__ . '/../partials/entrada/casca.php';
