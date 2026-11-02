<?php
/** Acesso negado: tela independente, no visual de entrada (sem o painel). */
$entradaErro = [
    'codigo' => '403',
    'icone' => 'cadeado',
    'tom' => 'aviso',
    'titulo' => ['erro.403_titulo', 'Acesso negado'],
    'texto' => ['erro.403_texto', 'Você não tem permissão para acessar esta página ou recurso.'],
];
include __DIR__ . '/../partials/entrada/erro.php';
