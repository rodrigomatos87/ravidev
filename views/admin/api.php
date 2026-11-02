<?php
/**
 * Admin: Gateway de API (compatível com a Anthropic). Layout do painel (d2).
 * Dados: AdminController@apiGateway. Comportamento no módulo /assets/js/paginas/admin-gateway.js.
 */
$this->layout('layouts/painel', [
    'title'       => t('gateway.titulo', 'Gateway de API'),
    'currentPage' => 'admin-api',
    'area'        => 'gateway',
    'subtitulo'   => t('gateway.sub', 'Alternativa interna à API oficial da Anthropic, servida pela assinatura Max. Os clientes trocam só o token e o destino.'),
    'acoes'       => '<button type="button" class="d2-btn d2-btn-primario" data-acao="novo-token">' . d2icone('mais') . '<span>' . e(t('gateway.gerar_token', 'Gerar token')) . '</span></button>',
    'scripts'     => ['/assets/js/paginas/admin-gateway.js'],
    'estilos'     => ['/assets/css/paginas/admin-gateway.css'],
    'paginaLarga' => true,
]);

$periodos = [
    'today' => t('gateway.hoje', 'Hoje'),
    'week'  => t('gateway.sete_dias', 'Últimos 7 dias'),
    'month' => t('gateway.trinta_dias', 'Últimos 30 dias'),
];
$urlGateway = (string) ($gateway_url ?? '');
$endpoint   = $urlGateway . '/v1/messages';
$dominioApi = (string) (parse_url($urlGateway, PHP_URL_HOST) ?: '');
$origemUrl  = (string) ($gateway_url_origem ?? 'padrao');
$dsOk       = !empty($deepseek_configured);
$gptAtivas  = (int) ($chatgpt_ativas ?? 0);
$rotuloExecutor = static fn(string $bk): string => match ($bk) {
    ''               => t('gateway.exec_max', 'Max'),
    'deepseek_haiku' => t('gateway.exec_ds_haiku', 'DeepSeek (haiku)'),
    'max_deepseek'   => t('gateway.exec_max_ds', 'Max nos deepseek'),
    default          => t('gateway.exec_ds_tudo', 'DeepSeek (tudo)'),
};

// Linhas da tabela de tokens já prontas para o módulo (nenhum cálculo no navegador).
$linhas = [];
foreach (($tokens ?? []) as $tk) {
    $tid = (int) $tk['id'];
    $hoje = $usage['today']['by_token'][$tid] ?? ['cost' => 0.0, 'calls' => 0];
    $sete = $usage['week']['by_token'][$tid] ?? ['cost' => 0.0, 'calls' => 0];
    $trinta = $usage['month']['by_token'][$tid] ?? ['cost' => 0.0, 'calls' => 0];
    $modelos = json_decode((string) ($tk['allowed_models'] ?? ''), true);
    $campos = $field_counts[$tid] ?? ['n' => 0, 'req' => 0];
    $bk = (string) ($tk['backend'] ?? '');
    $linhas[] = [
        'id'          => $tid,
        'nome'        => (string) $tk['name'],
        'prefixo'     => (string) $tk['token_prefix'],
        'ativo'       => (int) $tk['is_active'] === 1 && empty($tk['revoked_at']),
        'licenca'     => (int) ($tk['license_id'] ?? 0),
        'executor'    => $bk,
        'executor_rotulo' => $rotuloExecutor($bk),
        'identidade'  => (int) ($tk['log_server_identity'] ?? 1) === 1,
        'campos'      => (int) $campos['n'],
        'campos_obrig'=> (int) $campos['req'],
        'modelos'     => is_array($modelos) ? count($modelos) : 0,
        'custo_hoje'  => formatCost((float) $hoje['cost']),
        'custo_7d'    => formatCost((float) $sete['cost']),
        'custo_30d'   => formatCost((float) $trinta['cost']),
        'custo_30d_num' => (float) $trinta['cost'],
        'chamadas_30d'=> (int) $trinta['calls'],
        'ultimo_uso'  => !empty($tk['last_used_at']) ? date('d/m/Y H:i', strtotime((string) $tk['last_used_at'])) : '',
    ];
}
$licencas = [];
foreach (($licenses ?? []) as $l) {
    $licencas[] = ['id' => (int) $l['id'], 'nome' => (string) $l['name'] . (!empty($l['is_active']) ? '' : ' ' . t('gateway.inativa', '(inativa)'))];
}
$dadosPagina = [
    'tela'      => 'painel',
    'tokens'    => $linhas,
    'licencas'  => $licencas,
    'serie'     => $serie_dia ?? [],
    'deepseek'  => $dsOk,
    'gateway'   => ['url' => $urlGateway, 'origem' => $origemUrl, 'painel' => (string) ($gateway_url_painel ?? '')],
];
?>
<script type="application/json" id="dados-pagina"><?= json_encode($dadosPagina, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>

<div class="gw-tela" data-tela="painel">

<?php if (!empty($new_token)): ?>
<div class="d2-faixa d2-faixa-ok gw-chave-nova" role="status">
    <?= d2icone('chave') ?>
    <div class="d2-faixa-texto">
        <span class="d2-faixa-titulo"><?= e(t('gateway.token_criado', 'Token criado. Copie agora: ele não será exibido de novo.')) ?></span>
        <code class="d2-mono gw-chave-valor" id="gw-chave-nova"><?= e($new_token) ?></code>
    </div>
    <div class="d2-faixa-acoes">
        <button type="button" class="d2-btn d2-btn-sm d2-btn-primario" data-copiar="#gw-chave-nova"><?= d2icone('copiar') ?><span><?= e(t('gateway.copiar', 'Copiar')) ?></span></button>
    </div>
</div>
<?php endif; ?>

<div class="d2-faixa gw-endpoint">
    <?= d2icone('plataforma') ?>
    <div class="d2-faixa-texto">
        <span class="d2-faixa-titulo"><?= e(t('gateway.endpoint', 'Endpoint')) ?>: <code class="d2-mono" id="gw-endpoint"><?= e($endpoint) ?></code></span>
        <span class="texto-mudo"><?= e(t('gateway.endpoint_dica', 'Use o token como x-api-key, com o header anthropic-version: 2023-06-01. Aceita anexos: até 4 imagens (5 MB cada) e 1 PDF (10 MB) por chamada.')) ?></span>
    </div>
    <div class="d2-faixa-acoes">
        <button type="button" class="d2-iconbtn d2-iconbtn-fantasma" data-copiar="#gw-endpoint" aria-label="<?= e(t('gateway.copiar_endpoint', 'Copiar o endpoint')) ?>" data-tooltip="<?= e(t('gateway.copiar_endpoint', 'Copiar o endpoint')) ?>"><?= d2icone('copiar') ?></button>
    </div>
</div>

<section class="indicadores" aria-label="<?= e(t('gateway.consumo_periodo', 'Consumo por período')) ?>">
    <?php foreach ($periodos as $chave => $rotulo):
        $g = $usage[$chave]['global'] ?? ['in' => 0, 'out' => 0, 'calls' => 0, 'errors' => 0, 'cost' => 0.0]; ?>
    <div class="d2-indicador" data-area="gateway">
        <span class="d2-indicador-rotulo"><?= d2icone($chave === 'today' ? 'relogio' : 'calendario') ?><span><?= e($rotulo) ?></span></span>
        <span class="d2-indicador-valor d2-num" data-tooltip="<?= e(t('gateway.custo_estimado', 'Custo estimado na API oficial')) ?>"><?= e(formatCost((float) $g['cost'])) ?></span>
        <span class="gw-indicador-meta">
            <span class="d2-num"><?= e(number_format((int) $g['calls'], 0, ',', '.')) ?></span> <?= e(t('gateway.chamadas_min', 'chamadas')) ?>
            · <span class="d2-num"><?= e(formatTokens((int) $g['in'])) ?></span> in · <span class="d2-num"><?= e(formatTokens((int) $g['out'])) ?></span> out
            <?php if ((int) $g['errors'] > 0): ?><span class="d2-indicador-variacao is-erro"><?= (int) $g['errors'] ?> <?= e(t('gateway.erros_min', 'erro(s)')) ?></span><?php endif; ?>
        </span>
    </div>
    <?php endforeach; ?>
</section>

<section class="d2-painel" data-area="gateway" aria-labelledby="t-gw-consumo">
    <header class="d2-painel-cab">
        <span class="d2-painel-icone" aria-hidden="true"><?= d2icone('grafico') ?></span>
        <h3 class="d2-painel-titulo" id="t-gw-consumo"><?= e(t('gateway.consumo_dia', 'Consumo por dia')) ?></h3>
        <span class="d2-painel-sub"><?= e(t('gateway.ultimos_30', 'últimos 30 dias')) ?></span>
        <div class="d2-painel-acoes gw-alternar" role="group" aria-label="<?= e(t('gateway.medida', 'Medida do gráfico')) ?>">
            <button type="button" class="d2-btn d2-btn-sm d2-btn-secundario" data-medida="custo" aria-pressed="true"><?= e(t('gateway.custo', 'Custo')) ?></button>
            <button type="button" class="d2-btn d2-btn-sm d2-btn-fantasma" data-medida="chamadas" aria-pressed="false"><?= e(t('gateway.chamadas', 'Chamadas')) ?></button>
        </div>
    </header>
    <div class="d2-painel-corpo" id="gw-grafico"></div>
    <div class="d2-painel-rodape texto-mudo"><?= e(t('gateway.custo_explica', 'O custo é a estimativa do que seria gasto na API oficial, calculada só sobre o prompt real do cliente (sem o contexto interno do agente, que é reaproveitado e não existe na API oficial).')) ?></div>
</section>

<section class="d2-painel" data-area="gateway" aria-labelledby="t-gw-tokens">
    <header class="d2-painel-cab">
        <span class="d2-painel-icone" aria-hidden="true"><?= d2icone('chave') ?></span>
        <h3 class="d2-painel-titulo" id="t-gw-tokens"><?= e(t('gateway.tokens', 'Tokens')) ?></h3>
        <span class="d2-painel-sub"><?= e(t('gateway.tokens_sub', 'Histórico, análise, modelos e campos de cada token pelas ações da linha')) ?></span>
        <div class="d2-painel-acoes"><button type="button" class="d2-btn d2-btn-sm d2-btn-fantasma" data-acao="novo-token"><?= d2icone('mais') ?><span><?= e(t('gateway.gerar_token', 'Gerar token')) ?></span></button></div>
    </header>
    <div class="d2-painel-corpo is-justo" id="gw-tokens"></div>
</section>

<div class="d2-mosaico">
    <section class="d2-painel" data-area="gateway" aria-labelledby="t-gw-endereco">
        <header class="d2-painel-cab">
            <span class="d2-painel-icone" aria-hidden="true"><?= d2icone('wifi') ?></span>
            <h3 class="d2-painel-titulo" id="t-gw-endereco"><?= e(t('gateway.endereco', 'Endereço do Gateway')) ?></h3>
            <span class="d2-painel-sub" id="gw-endereco-origem"><?= e(match ($origemUrl) {
                'painel' => t('gateway.origem_painel', 'definido aqui no painel'),
                'env'    => t('gateway.origem_env', 'definido na instalação'),
                default  => t('gateway.origem_padrao', 'padrão: api. + domínio do painel'),
            }) ?></span>
        </header>
        <div class="d2-painel-corpo d2-pilha">
            <form id="gw-form-endereco" class="d2-pilha" novalidate>
                <div class="d2-field">
                    <label class="d2-label" for="gw-url"><?= e(t('gateway.url_publica', 'Endereço público')) ?></label>
                    <input class="d2-control d2-mono" type="url" id="gw-url" name="url" inputmode="url" autocomplete="off" spellcheck="false"
                           placeholder="https://api.seu-dominio.com.br" value="<?= e((string) ($gateway_url_painel ?? '')) ?>" aria-describedby="gw-url-dica">
                    <span class="d2-field-hint" id="gw-url-dica"><?= e(t('gateway.url_dica', 'Em branco, volta ao padrão da instalação. Em uso agora:')) ?> <span class="d2-mono" id="gw-url-efetiva"><?= e($urlGateway) ?></span></span>
                </div>
                <div class="form-acoes">
                    <button type="button" class="d2-btn d2-btn-secundario" id="gw-verificar"><?= d2icone('atualizar') ?><span><?= e(t('gateway.verificar', 'Verificar')) ?></span></button>
                    <button type="submit" class="d2-btn d2-btn-primario" id="gw-salvar-url"><?= d2icone('check') ?><span><?= e(t('gateway.salvar', 'Salvar')) ?></span></button>
                </div>
                <div id="gw-verificacao" aria-live="polite"></div>
            </form>

            <h4 class="gw-subtitulo"><?= e(t('gateway.como_publicar', 'Como publicar')) ?></h4>
            <ol class="gw-passos">
                <li>
                    <strong><?= e(t('gateway.passo1', 'Aponte o DNS')) ?></strong>
                    <p class="texto-mudo"><?= e(t('gateway.passo1_texto', 'No seu provedor de domínio, crie um registro A (ou CNAME) de')) ?> <span class="d2-mono" data-dominio><?= e($dominioApi) ?></span> <?= e(t('gateway.passo1_texto2', 'para o IP público deste servidor.')) ?></p>
                </li>
                <li>
                    <strong><?= e(t('gateway.passo2', 'Publique o site e o certificado')) ?></strong>
                    <p class="texto-mudo"><?= e(t('gateway.passo2_texto', 'No servidor, como administrador, na pasta da instalação. O primeiro comando gera a configuração do site e mostra no fim como ativá-la; o segundo emite o certificado.')) ?></p>
                    <div class="gw-codigo">
                        <pre class="codigo" id="gw-cmd-modelos">bin/instalar-modelos.sh --dominio-api <span data-dominio><?= e($dominioApi) ?></span></pre>
                        <button type="button" class="d2-iconbtn d2-iconbtn-fantasma" data-copiar="#gw-cmd-modelos" aria-label="<?= e(t('gateway.copiar_comando', 'Copiar o comando')) ?>" data-tooltip="<?= e(t('gateway.copiar_comando', 'Copiar o comando')) ?>"><?= d2icone('copiar') ?></button>
                    </div>
                    <div class="gw-codigo">
                        <pre class="codigo" id="gw-cmd-certificado">certbot certonly --nginx -d <span data-dominio><?= e($dominioApi) ?></span></pre>
                        <button type="button" class="d2-iconbtn d2-iconbtn-fantasma" data-copiar="#gw-cmd-certificado" aria-label="<?= e(t('gateway.copiar_comando', 'Copiar o comando')) ?>" data-tooltip="<?= e(t('gateway.copiar_comando', 'Copiar o comando')) ?>"><?= d2icone('copiar') ?></button>
                    </div>
                </li>
                <li>
                    <strong><?= e(t('gateway.passo3', 'Teste a chamada')) ?></strong>
                    <p class="texto-mudo"><?= e(t('gateway.passo3_texto', 'Clique em Verificar acima. Depois, os sistemas clientes chamam o endpoint abaixo com a chave de um token:')) ?></p>
                    <div class="gw-codigo">
                        <pre class="codigo" id="gw-exemplo">curl <span data-endpoint><?= e($endpoint) ?></span> \
  -H "x-api-key: SUA_CHAVE" \
  -H "anthropic-version: 2023-06-01" \
  -H "content-type: application/json" \
  -d '{"model":"claude-haiku-4-5","max_tokens":64,"messages":[{"role":"user","content":"Olá"}]}'</pre>
                        <button type="button" class="d2-iconbtn d2-iconbtn-fantasma" data-copiar="#gw-exemplo" aria-label="<?= e(t('gateway.copiar_exemplo', 'Copiar o exemplo')) ?>" data-tooltip="<?= e(t('gateway.copiar_exemplo', 'Copiar o exemplo')) ?>"><?= d2icone('copiar') ?></button>
                    </div>
                </li>
            </ol>
        </div>
    </section>

    <section class="d2-painel" data-area="gateway" aria-labelledby="t-gw-executores">
        <header class="d2-painel-cab">
            <span class="d2-painel-icone" aria-hidden="true"><?= d2icone('computador') ?></span>
            <h3 class="d2-painel-titulo" id="t-gw-executores"><?= e(t('gateway.executores', 'Executores')) ?></h3>
            <span class="d2-painel-sub"><?= e(t('gateway.executores_sub', 'quem usa o quê é escolhido por token, em Modelos')) ?></span>
        </header>
        <div class="d2-painel-corpo is-justo">
            <ul class="lista-acionavel gw-executores">
                <li class="gw-executor">
                    <div class="gw-executor-cab">
                        <strong><?= e(t('gateway.claude_max', 'Claude Max')) ?></strong>
                        <span class="d2-selo d2-selo-ok"><span class="d2-ponto"></span><?= e(t('gateway.assinatura', 'assinatura')) ?></span>
                    </div>
                    <p class="texto-mudo"><?= e(t('gateway.max_texto', 'Executor padrão de todos os tokens: modelos claude-* pela assinatura Max, com o roteamento de licenças do painel.')) ?></p>
                </li>
                <li class="gw-executor">
                    <div class="gw-executor-cab">
                        <strong><?= e(t('gateway.deepseek', 'DeepSeek')) ?></strong>
                        <?php if ($dsOk): ?>
                            <span class="d2-selo d2-selo-ok"><span class="d2-ponto"></span><?= e(t('gateway.chave_cadastrada', 'chave cadastrada')) ?></span>
                        <?php else: ?>
                            <span class="d2-selo d2-selo-neutro"><span class="d2-ponto"></span><?= e(t('gateway.sem_chave', 'sem chave')) ?></span>
                        <?php endif; ?>
                        <span class="gw-executor-acoes">
                            <?php if ($dsOk): ?>
                                <button type="button" class="d2-btn d2-btn-sm d2-btn-fantasma" id="gw-ds-testar"><?= d2icone('atualizar') ?><span><?= e(t('gateway.testar', 'Testar')) ?></span></button>
                            <?php endif; ?>
                            <button type="button" class="d2-btn d2-btn-sm d2-btn-secundario" data-acao="chave-deepseek"><?= d2icone('chave') ?><span><?= e($dsOk ? t('gateway.trocar_chave', 'Trocar chave') : t('gateway.salvar_chave', 'Salvar chave')) ?></span></button>
                        </span>
                    </div>
                    <p class="texto-mudo"><?= e(t('gateway.ds_texto', 'Modelos deepseek-* pela API oficial da DeepSeek. A chave fica cifrada e sozinha não faz nada: quem liga o uso é a regra de cada token em Modelos.')) ?></p>
                </li>
                <li class="gw-executor">
                    <div class="gw-executor-cab">
                        <strong><?= e(t('gateway.chatgpt', 'ChatGPT (Codex)')) ?></strong>
                        <span class="d2-selo <?= $gptAtivas > 0 ? 'd2-selo-ok' : 'd2-selo-neutro' ?>"><span class="d2-ponto"></span><?= e($gptAtivas > 0 ? $gptAtivas . ' ' . t('gateway.contas_ativas', 'conta(s) ativa(s)') : t('gateway.sem_conta', 'sem conta')) ?></span>
                        <span class="gw-executor-acoes"><a class="d2-btn d2-btn-sm d2-btn-fantasma" href="/admin/licenses"><?= e(t('gateway.ver_licencas', 'Licenças')) ?></a></span>
                    </div>
                    <p class="texto-mudo"><?= e(t('gateway.gpt_texto', 'Modelos gpt-* nas contas ChatGPT do painel, pela cota do plano (custo zero por token). As contas são as mesmas de Licenças; várias contas viram cadeia, como as licenças Max.')) ?></p>
                </li>
            </ul>
        </div>
    </section>
</div>

</div>
