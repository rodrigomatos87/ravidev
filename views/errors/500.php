<?php
/**
 * Erro interno: tela independente, no visual de entrada (sem o painel).
 * Também é incluída pelo tratador de exceções do bootstrap, fora da View.
 */
$entradaErro = [
    'codigo' => '500',
    'icone' => 'alerta',
    'tom' => 'erro',
    'titulo' => ['erro.500_titulo', 'Erro interno do servidor'],
    'texto' => ['erro.500_texto', 'Algo deu errado. Nossa equipe foi notificada e está trabalhando para resolver o problema.'],
];
include __DIR__ . '/../partials/entrada/erro.php';
