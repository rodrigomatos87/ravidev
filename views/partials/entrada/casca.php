<?php
/**
 * Casca das telas de entrada (login, cadastro, senha, duas etapas) e das páginas de erro.
 * Vitrine com a marca à esquerda, cartão do formulário à direita; abaixo de 1024 px, coluna única.
 *
 * Usada pelo layout views/layouts/auth.php e pelas páginas views/errors/*.php. Também é incluída
 * pelo tratador de exceções do bootstrap (fora da View), por isso tudo aqui é defensivo.
 *
 * Variáveis:
 *   $entradaTitulo    título da aba do navegador
 *   $entradaConteudo  HTML do cartão (já escapado pela view)
 *   $entradaFlash     (opcional) ['error' => ..., 'success' => ..., 'info' => ..., 'warning' => ...]
 */
$entT = static function (string $chave, string $padrao): string {
    if (function_exists('t')) {
        try { return t($chave, $padrao); } catch (\Throwable $erro) { /* idioma indisponível */ }
    }
    return $padrao;
};
$entE = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$entIcone = static function (string $nome, string $classe = '') use ($entE): string {
    return '<svg class="' . $entE(trim('d2-icone ' . $classe)) . '" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><use href="/assets/d2/icones/sprite.svg#' . $entE($nome) . '"/></svg>';
};
$entPublico = defined('PUBLIC_PATH') ? PUBLIC_PATH : dirname(__DIR__, 3) . '/public';
$entVersao = static fn(string $caminho): string => $caminho . '?v=' . (@filemtime($entPublico . $caminho) ?: '1');
$entApp = 'Ravi Developer';
if (function_exists('config')) {
    try { $entApp = (string) (config('app.name') ?: $entApp); } catch (\Throwable $erro) { /* configuração indisponível */ }
}
$entIdioma = 'pt';
if (class_exists('\Ravi\Core\Lang', false)) {
    try { $entIdioma = (string) \Ravi\Core\Lang::active(); } catch (\Throwable $erro) { /* idioma indisponível */ }
}
$entIdioma = in_array($entIdioma, ['pt', 'en', 'es'], true) ? $entIdioma : 'pt';
$entCsrf = '';
if (function_exists('csrf_token')) {
    try { $entCsrf = (string) csrf_token(); } catch (\Throwable $erro) { /* sem sessão */ }
}

// Mensagens do flash: erro e aviso ficam fixos no cartão; sucesso também (costuma trazer instrução);
// informação vira notificação passageira.
$entFaixas = [];
$entToasts = [];
foreach (['error' => ['erro', 'erro'], 'warning' => ['aviso', 'alerta'], 'success' => ['ok', 'check']] as $chave => [$tipo, $icone]) {
    if (!empty($entradaFlash[$chave]) && is_scalar($entradaFlash[$chave])) {
        $entFaixas[] = ['tipo' => $tipo, 'icone' => $icone, 'texto' => (string) $entradaFlash[$chave]];
    }
}
if (!empty($entradaFlash['info']) && is_scalar($entradaFlash['info'])) {
    $entToasts[] = ['tipo' => 'info', 'texto' => (string) $entradaFlash['info']];
}

$entTextosD2 = json_decode((string) @file_get_contents($entPublico . '/assets/d2/idiomas/' . $entIdioma . '.json'), true) ?: [];
$entDados = [
    'idioma' => $entIdioma,
    'toasts' => $entToasts,
    'textos' => array_merge($entTextosD2, [
        'entrada.mostrar_senha' => $entT('entrada.mostrar_senha', 'Mostrar senha'),
        'entrada.ocultar_senha' => $entT('entrada.ocultar_senha', 'Ocultar senha'),
        'entrada.caps' => $entT('entrada.caps', 'Caps Lock ativado'),
        'entrada.tema_auto' => $entT('entrada.tema_auto', 'Tema: automático'),
        'entrada.tema_claro' => $entT('entrada.tema_claro', 'Tema: claro'),
        'entrada.tema_escuro' => $entT('entrada.tema_escuro', 'Tema: escuro'),
        'entrada.informe_email' => $entT('entrada.informe_email', 'Informe seu e-mail acima para reenviar a confirmação.'),
        'entrada.forca_0' => $entT('entrada.forca_0', 'Muito fraca'),
        'entrada.forca_1' => $entT('entrada.forca_1', 'Fraca'),
        'entrada.forca_2' => $entT('entrada.forca_2', 'Razoável'),
        'entrada.forca_3' => $entT('entrada.forca_3', 'Forte'),
        'entrada.forca_4' => $entT('entrada.forca_4', 'Muito forte'),
    ]),
];
$entAno = date('Y');
?>
<!DOCTYPE html>
<html lang="<?= $entE(['pt' => 'pt-BR', 'en' => 'en-US', 'es' => 'es-ES'][$entIdioma]) ?>" data-area="painel">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#f2f6f8">
    <meta name="robots" content="noindex">
    <?php if ($entCsrf !== ''): ?><meta name="csrf-token" content="<?= $entE($entCsrf) ?>"><?php endif; ?>
    <title><?= $entE($entradaTitulo ?? $entApp) ?> · <?= $entE($entApp) ?></title>
    <link rel="icon" type="image/svg+xml" href="/assets/images/logo.svg">
    <link rel="preload" href="/assets/d2/fontes/ibmplexsans-variavel-latin.woff2" as="font" type="font/woff2" crossorigin>
    <script src="<?= $entE($entVersao('/assets/d2/js/tema-inicial.js')) ?>"></script>
    <link rel="stylesheet" href="<?= $entE($entVersao('/assets/d2/css/d2.css')) ?>">
    <link rel="stylesheet" href="<?= $entE($entVersao('/assets/d2/css/entrada.css')) ?>">
</head>
<body>
<div class="entrada">
    <aside class="entrada-vitrine d2-noite" aria-label="<?= $entE($entApp) ?>">
        <a class="entrada-marca" href="/">
            <img class="entrada-marca-icone" src="/assets/images/logo.svg" alt="" width="38" height="38">
            <span class="entrada-marca-nome"><?= $entE($entApp) ?></span>
        </a>
        <div class="entrada-vitrine-centro">
            <h2 class="entrada-vitrine-titulo"><?= $entE($entT('entrada.vitrine_titulo', 'Um time de agentes que constrói seu software.')) ?></h2>
            <p class="entrada-vitrine-texto"><?= $entE($entT('entrada.vitrine_texto', 'O ambiente único da sua equipe: desenvolva, versione e entregue, tudo controlado num só lugar.')) ?></p>
            <ul class="entrada-destaques">
                <li class="entrada-destaque">
                    <span class="entrada-destaque-icone d2-pastilha" data-area="usuarios"><?= $entIcone('usuarios') ?></span>
                    <span class="entrada-destaque-textos">
                        <span class="entrada-destaque-titulo"><?= $entE($entT('entrada.destaque_time', 'Time de especialistas')) ?></span>
                        <span class="entrada-destaque-texto"><?= $entE($entT('entrada.destaque_time_texto', 'Planejamento, código, revisão e testes trabalhando em paralelo.')) ?></span>
                    </span>
                </li>
                <li class="entrada-destaque">
                    <span class="entrada-destaque-icone d2-pastilha" data-area="projetos"><?= $entIcone('computador') ?></span>
                    <span class="entrada-destaque-textos">
                        <span class="entrada-destaque-titulo"><?= $entE($entT('entrada.destaque_ide', 'Ambiente de desenvolvimento real')) ?></span>
                        <span class="entrada-destaque-texto"><?= $entE($entT('entrada.destaque_ide_texto', 'Editor e navegador de verdade, direto no seu projeto.')) ?></span>
                    </span>
                </li>
                <li class="entrada-destaque">
                    <span class="entrada-destaque-icone d2-pastilha" data-area="monitoramento"><?= $entIcone('online') ?></span>
                    <span class="entrada-destaque-textos">
                        <span class="entrada-destaque-titulo"><?= $entE($entT('entrada.destaque_entrega', 'Pré-visualização e entrega')) ?></span>
                        <span class="entrada-destaque-texto"><?= $entE($entT('entrada.destaque_entrega_texto', 'Veja o resultado ao vivo e entregue com histórico de versões.')) ?></span>
                    </span>
                </li>
            </ul>
        </div>
        <div class="entrada-fluxo" aria-hidden="true">
            <div class="entrada-fluxo-cab">
                <span class="entrada-fluxo-titulo"><?= $entE($entT('entrada.fluxo_titulo', 'Como uma tarefa anda')) ?></span>
                <span class="entrada-fluxo-vivo"><span class="entrada-fluxo-pulso"></span><?= $entE($entT('entrada.fluxo_vivo', 'Em andamento')) ?></span>
            </div>
            <ol class="entrada-etapas">
                <li class="entrada-etapa" data-area="painel"><?= $entIcone('lista') ?><span class="entrada-etapa-nome"><?= $entE($entT('entrada.etapa_planejar', 'Planejar')) ?></span><span class="entrada-etapa-estado"><?= $entE($entT('entrada.etapa_feito', 'Feito')) ?></span></li>
                <li class="entrada-etapa" data-area="projetos"><?= $entIcone('documento') ?><span class="entrada-etapa-nome"><?= $entE($entT('entrada.etapa_implementar', 'Implementar')) ?></span><span class="entrada-etapa-estado"><?= $entE($entT('entrada.etapa_feito', 'Feito')) ?></span></li>
                <li class="entrada-etapa is-atual"><?= $entIcone('busca') ?><span class="entrada-etapa-nome"><?= $entE($entT('entrada.etapa_revisar', 'Revisar')) ?></span><span class="entrada-etapa-estado"><?= $entE($entT('entrada.etapa_agora', 'Agora')) ?></span></li>
                <li class="entrada-etapa is-espera" data-area="monitoramento"><?= $entIcone('check') ?><span class="entrada-etapa-nome"><?= $entE($entT('entrada.etapa_testar', 'Testar')) ?></span><span class="entrada-etapa-estado"><?= $entE($entT('entrada.etapa_depois', 'Depois')) ?></span></li>
                <li class="entrada-etapa is-espera" data-area="licencas"><?= $entIcone('enviar') ?><span class="entrada-etapa-nome"><?= $entE($entT('entrada.etapa_entregar', 'Entregar')) ?></span><span class="entrada-etapa-estado"><?= $entE($entT('entrada.etapa_depois', 'Depois')) ?></span></li>
            </ol>
        </div>
        <p class="entrada-vitrine-rodape">© <?= $entE($entAno) ?> <?= $entE($entApp) ?>. <?= $entE($entT('entrada.acesso_restrito', 'Acesso restrito à equipe.')) ?></p>
    </aside>

    <div class="entrada-lado">
        <header class="entrada-topo">
            <a class="entrada-marca entrada-marca-movel" href="/">
                <img class="entrada-marca-icone" src="/assets/images/logo.svg" alt="" width="38" height="38">
                <span class="entrada-marca-nome"><?= $entE($entApp) ?></span>
            </a>
        </header>
        <main class="entrada-centro" id="conteudo">
            <section class="entrada-cartao">
                <?php if ($entFaixas): ?>
                    <div class="entrada-faixas">
                        <?php foreach ($entFaixas as $faixa): ?>
                            <div class="d2-faixa d2-faixa-<?= $entE($faixa['tipo']) ?>" role="<?= $faixa['tipo'] === 'ok' ? 'status' : 'alert' ?>">
                                <?= $entIcone($faixa['icone']) ?>
                                <span class="d2-faixa-texto"><?= $entE($faixa['texto']) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <?= $entradaConteudo ?? '' ?>
            </section>
        </main>
        <footer class="entrada-rodape">
            <span class="entrada-rodape-seguro"><?= $entIcone('cadeado') ?><?= $entE($entT('entrada.conexao_protegida', 'Conexão protegida')) ?></span>
            <span class="entrada-rodape-fim">
                <span>© <?= $entE($entAno) ?> <?= $entE($entApp) ?></span>
                <button type="button" class="d2-iconbtn d2-iconbtn-fantasma entrada-tema" id="entrada-tema"
                        aria-label="<?= $entE($entT('entrada.tema_trocar', 'Trocar tema')) ?>" data-tooltip="<?= $entE($entT('entrada.tema_trocar', 'Trocar tema')) ?>">
                    <?= $entIcone('auto') ?>
                </button>
            </span>
        </footer>
    </div>
</div>
<script type="application/json" id="dados-pagina"><?= json_encode($entDados, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script type="module" src="<?= $entE($entVersao('/assets/js/paginas/entrada.js')) ?>"></script>
</body>
</html>
