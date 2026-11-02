<?php
/**
 * Admin: modelos permitidos e executor de um token do Gateway de API. Layout do painel (d2).
 * Dados: AdminController@apiGatewayModels.
 */
$tid = (int) ($token['id'] ?? 0);
$this->layout('layouts/painel', [
    'title'       => t('gateway.modelos_titulo', 'Modelos e execução'),
    'currentPage' => 'admin-api',
    'area'        => 'gateway',
    'subtitulo'   => t('gateway.modelos_sub', 'Quem executa as chamadas deste token e quais modelos ele pode pedir.'),
    'acoes'       => '<a class="d2-btn d2-btn-fantasma" href="/admin/api">' . d2icone('seta-esquerda') . '<span>' . e(t('gateway.voltar', 'Voltar ao Gateway')) . '</span></a>',
    'scripts'     => ['/assets/js/paginas/admin-gateway.js'],
    'estilos'     => ['/assets/css/paginas/admin-gateway.css'],
]);
$restrito  = is_array($allowed); // null = sem restrição
$bk        = (string) ($token['backend'] ?? '');
$dsOk      = !empty($deepseek_configured);
$gptAtivas = (int) ($chatgpt_ativas ?? 0);
$grupos = [
    'anthropic' => ['titulo' => t('gateway.anthropic', 'Anthropic'), 'desc' => t('gateway.anthropic_desc', 'Servidos pela assinatura Max.')],
    'deepseek'  => ['titulo' => t('gateway.deepseek', 'DeepSeek'), 'desc' => t('gateway.deepseek_desc', 'Servidos pela API da DeepSeek, pedidos pelo id direto (ex.: deepseek-flash).') . ($dsOk ? '' : ' ' . t('gateway.deepseek_sem', 'Sem chave cadastrada: as chamadas serão recusadas até cadastrar no Gateway de API.'))],
    'openai'    => ['titulo' => t('gateway.openai', 'OpenAI (ChatGPT)'), 'desc' => t('gateway.openai_desc', 'Servidos pelo Codex nas contas ChatGPT do painel, pela cota do plano.') . ($gptAtivas > 0 ? '' : ' ' . t('gateway.openai_sem', 'Sem conta ativa: as chamadas serão recusadas até cadastrar em Licenças.'))],
];
$opcoes = [
    ['valor' => '', 'titulo' => t('gateway.op_max', 'Max'), 'extra' => t('gateway.padrao', '(padrão)'), 'desc' => t('gateway.op_max_desc', 'Tudo pela assinatura Max, como sempre foi.'), 'precisaDs' => false],
    ['valor' => 'deepseek_haiku', 'titulo' => t('gateway.op_ds_haiku', 'DeepSeek só na família Haiku'), 'extra' => '', 'desc' => t('gateway.op_ds_haiku_desc', 'claude-haiku vira DeepSeek V4.1 Flash; sonnet e opus seguem no Max.'), 'precisaDs' => true],
    ['valor' => 'deepseek', 'titulo' => t('gateway.op_ds', 'DeepSeek em tudo'), 'extra' => '', 'desc' => t('gateway.op_ds_desc', 'Todas as chamadas claude-* deste token vão à DeepSeek (haiku e sonnet viram Flash; opus vira V4 Pro).'), 'precisaDs' => true],
    ['valor' => 'max_deepseek', 'titulo' => t('gateway.op_max_ds', 'Max até nos deepseek-*'), 'extra' => '', 'desc' => t('gateway.op_max_ds_desc', 'O contrário: pedidos deepseek-* deste token são servidos pela assinatura Max (Flash vira Haiku; Pro vira Sonnet), sem gastar a conta DeepSeek. O histórico registra o pedido original e o modelo servido.'), 'precisaDs' => false],
];
?>
<div class="gw-tela d2-pilha" data-tela="modelos" data-token="<?= $tid ?>">
<?php include __DIR__ . '/../partials/gateway/token-cab.php'; ?>

<section class="d2-painel" data-area="gateway" aria-labelledby="t-gw-exec">
    <header class="d2-painel-cab">
        <span class="d2-painel-icone" aria-hidden="true"><?= d2icone('computador') ?></span>
        <h3 class="d2-painel-titulo" id="t-gw-exec"><?= e(t('gateway.exec_titulo', 'Executor dos modelos Claude')) ?></h3>
        <span class="d2-painel-sub"><?= e(t('gateway.salva_na_hora', 'salva na hora')) ?></span>
    </header>
    <div class="d2-painel-corpo d2-pilha">
        <p class="texto-mudo"><?= e(t('gateway.exec_texto', 'Quem executa quando este token pede um modelo claude-*. Trocar para DeepSeek migra o sistema cliente sem mudar nada nele: ele segue pedindo claude e o Gateway serve o equivalente DeepSeek, registrando o modelo servido no histórico. Modelos deepseek-* pedidos pelo id vão à DeepSeek, a menos que a regra "Max até nos deepseek-*" esteja ligada, que inverte também esse caminho.')) ?></p>
        <div class="gw-opcoes" role="radiogroup" aria-labelledby="t-gw-exec">
            <?php foreach ($opcoes as $op):
                $bloqueada = $op['precisaDs'] && !$dsOk; ?>
            <label class="gw-opcao<?= $bloqueada ? ' is-desativada' : '' ?>">
                <input type="radio" name="gw-backend" value="<?= e($op['valor']) ?>" <?= $bk === $op['valor'] ? 'checked' : '' ?> <?= $bloqueada ? 'disabled' : '' ?>>
                <span class="gw-opcao-titulo"><?= e($op['titulo']) ?><?php if ($op['extra'] !== ''): ?> <span class="texto-mudo"><?= e($op['extra']) ?></span><?php endif; ?></span>
                <span class="gw-opcao-texto"><?= e($op['desc']) ?></span>
            </label>
            <?php endforeach; ?>
        </div>
        <?php if (!$dsOk): ?>
        <div class="d2-faixa d2-faixa-aviso">
            <?= d2icone('alerta') ?>
            <div class="d2-faixa-texto"><?= e(t('gateway.cadastre_ds', 'Cadastre a chave da DeepSeek no Gateway de API para liberar as opções DeepSeek.')) ?></div>
            <div class="d2-faixa-acoes"><a class="d2-btn d2-btn-sm d2-btn-secundario" href="/admin/api"><?= e(t('gateway.ir_gateway', 'Ir ao Gateway')) ?></a></div>
        </div>
        <?php endif; ?>
    </div>
</section>

<section class="d2-painel" data-area="gateway" aria-labelledby="t-gw-permitidos">
    <header class="d2-painel-cab">
        <span class="d2-painel-icone" aria-hidden="true"><?= d2icone('lista') ?></span>
        <h3 class="d2-painel-titulo" id="t-gw-permitidos"><?= e(t('gateway.permitidos', 'Modelos permitidos')) ?></h3>
    </header>
    <form method="POST" action="/admin/api/tokens/<?= $tid ?>/models" id="gw-form-modelos">
        <?= csrf_field() ?>
        <div class="d2-painel-corpo d2-pilha">
            <p class="texto-mudo"><?= e(t('gateway.permitidos_texto', 'Quais modelos este token pode pedir. Fora da lista, o Gateway recusa com erro genérico (o motivo fica só no registro). A regra compara por família: restringir claude-haiku-4-5 cobre qualquer id haiku, e o mesmo vale para os DeepSeek. Sem restrição, todo o catálogo é permitido.')) ?></p>
            <label class="d2-switch">
                <input type="checkbox" name="restringir" id="gw-restringir" value="1" <?= $restrito ? 'checked' : '' ?>>
                <span class="d2-switch-trilho" aria-hidden="true"></span>
                <span class="d2-switch-texto"><?= e(t('gateway.restringir', 'Restringir os modelos deste token')) ?></span>
            </label>
            <div class="d2-grade-3 gw-grupos<?= $restrito ? '' : ' is-desligado' ?>" id="gw-lista-modelos">
                <?php foreach ($grupos as $prov => $g): ?>
                <fieldset class="gw-grupo">
                    <legend class="gw-grupo-titulo">
                        <span><?= e($g['titulo']) ?></span>
                        <?php if ($prov === 'deepseek'): ?>
                            <span class="d2-selo <?= $dsOk ? 'd2-selo-ok' : 'd2-selo-neutro' ?>"><span class="d2-ponto"></span><?= e($dsOk ? t('gateway.chave_cadastrada', 'chave cadastrada') : t('gateway.sem_chave', 'sem chave')) ?></span>
                        <?php elseif ($prov === 'openai'): ?>
                            <span class="d2-selo <?= $gptAtivas > 0 ? 'd2-selo-ok' : 'd2-selo-neutro' ?>"><span class="d2-ponto"></span><?= e($gptAtivas > 0 ? $gptAtivas . ' ' . t('gateway.contas_ativas', 'conta(s) ativa(s)') : t('gateway.sem_conta', 'sem conta')) ?></span>
                        <?php endif; ?>
                    </legend>
                    <p class="texto-mudo"><?= e($g['desc']) ?></p>
                    <?php foreach ($catalog as $m):
                        if (($m['provider'] ?? 'anthropic') !== $prov) continue;
                        $mid = (string) $m['id'];
                        $marcado = $restrito && in_array($mid, $allowed, true); ?>
                    <label class="d2-check gw-modelo">
                        <input type="checkbox" name="models[]" value="<?= e($mid) ?>" <?= $marcado ? 'checked' : '' ?> <?= $restrito ? '' : 'disabled' ?>>
                        <span><?= e((string) ($m['display_name'] ?? $mid)) ?> <code class="d2-mono texto-mudo"><?= e($mid) ?></code></span>
                    </label>
                    <?php endforeach; ?>
                </fieldset>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="d2-painel-rodape form-acoes">
            <span class="texto-mudo" id="gw-modelos-dica"><?= e($restrito ? t('gateway.restrito_dica', 'Restrito aos marcados.') : t('gateway.livre_dica', 'Sem restrição: todos os modelos permitidos.')) ?></span>
            <button type="submit" class="d2-btn d2-btn-primario"><?= d2icone('check') ?><span><?= e(t('gateway.salvar', 'Salvar')) ?></span></button>
        </div>
    </form>
</section>
</div>
