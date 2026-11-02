<?php
/** Página não encontrada: tela independente, no visual de entrada (sem o painel). */
$entradaErro = [
    'codigo' => '404',
    'icone' => 'busca',
    'tom' => 'aviso',
    'titulo' => ['erro.404_titulo', 'Página não encontrada'],
    'texto' => ['erro.404_texto', 'A página que você está procurando não existe ou foi movida para outro endereço.'],
];
include __DIR__ . '/../partials/entrada/erro.php';
