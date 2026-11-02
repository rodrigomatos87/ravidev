<?php
/**
 * Admin: histórico de chamadas de um token do Gateway de API. Layout do painel (d2).
 * Dados: AdminController@apiGatewayHistory (até 200 chamadas e as 10 origens que mais chamam).
 */
$this->layout('layouts/painel', [
    'title'       => t('gateway.historico_titulo', 'Histórico de chamadas'),
    'currentPage' => 'admin-api',
    'area'        => 'gateway',
    'subtitulo'   => t('gateway.historico_sub', 'Últimas chamadas deste token (máximo de 200), com origem, horário, chamada e retorno.'),
    'acoes'       => '<a class="d2-btn d2-btn-fantasma" href="/admin/api">' . d2icone('seta-esquerda') . '<span>' . e(t('gateway.voltar', 'Voltar ao Gateway')) . '</span></a>',
    'scripts'     => ['/assets/js/paginas/admin-gateway.js'],
    'estilos'     => ['/assets/css/paginas/admin-gateway.css'],
]);
$fmt = static fn($n): string => number_format((int) $n, 0, ',', '.');
?>
<div class="gw-tela d2-pilha" data-tela="historico">
<?php include __DIR__ . '/../partials/gateway/token-cab.php'; ?>

<?php if (!empty($top_origins)): ?>
<section class="d2-painel" data-area="gateway" aria-labelledby="t-gw-origens">
    <header class="d2-painel-cab">
        <span class="d2-painel-icone" aria-hidden="true"><?= d2icone('wifi') ?></span>
        <h3 class="d2-painel-titulo" id="t-gw-origens"><?= e(t('gateway.top_origens', 'As 10 origens que mais chamam')) ?></h3>
    </header>
    <div class="d2-painel-corpo is-justo">
        <ol class="lista-acionavel gw-origens">
            <?php foreach ($top_origins as $i => $o): ?>
            <li class="gw-origem">
                <span class="gw-origem-pos d2-num">#<?= $i + 1 ?></span>
                <code class="d2-mono"><?= e((string) $o['ip']) ?></code>
                <span class="d2-num gw-origem-total"><?= e($fmt($o['calls'])) ?> <?= e(t('gateway.chamadas_min', 'chamadas')) ?></span>
                <span class="gw-origem-modelos">
                    <?php foreach (($o['models'] ?? []) as $modelo => $n): ?>
                        <span class="d2-tag"><span class="d2-mono"><?= e((string) $modelo) ?></span> <span class="d2-num"><?= e($fmt($n)) ?></span></span>
                    <?php endforeach; ?>
                </span>
            </li>
            <?php endforeach; ?>
        </ol>
    </div>
</section>
<?php endif; ?>

<section class="d2-painel" data-area="gateway" aria-labelledby="t-gw-chamadas">
    <header class="d2-painel-cab">
        <span class="d2-painel-icone" aria-hidden="true"><?= d2icone('lista') ?></span>
        <h3 class="d2-painel-titulo" id="t-gw-chamadas"><?= e(t('gateway.chamadas', 'Chamadas')) ?></h3>
        <span class="d2-painel-sub"><?= e($fmt(count($rows))) ?> <?= e(t('gateway.registradas', 'registradas')) ?></span>
    </header>
    <div class="d2-painel-corpo<?= empty($rows) ? '' : ' is-justo' ?>">
        <?php if (empty($rows)): ?>
            <div class="d2-vazio">
                <span class="d2-vazio-icone" aria-hidden="true"><?= d2icone('lista') ?></span>
                <p class="d2-vazio-titulo"><?= e(t('gateway.sem_chamadas', 'Nenhuma chamada registrada para este token ainda')) ?></p>
                <p class="d2-vazio-texto"><?= e(t('gateway.sem_chamadas_texto', 'Assim que um sistema usar este token, as chamadas aparecem aqui.')) ?></p>
                <a class="d2-btn d2-btn-primario" href="/admin/api"><?= e(t('gateway.ver_endpoint', 'Ver endpoint e exemplo')) ?></a>
            </div>
        <?php else: ?>
        <ul class="lista-acionavel gw-historico">
            <?php foreach ($rows as $r):
                $ok = ($r['status'] ?? '') === 'ok';
                $quando = !empty($r['created_at']) ? date('d/m/Y H:i:s', strtotime((string) $r['created_at'])) : '';
                $extra = !empty($r['identity_json']) ? json_decode((string) $r['identity_json'], true) : null;
            ?>
            <li class="gw-chamada<?= $ok ? '' : ' is-erro' ?>">
                <div class="gw-chamada-cab">
                    <span class="d2-num gw-chamada-hora"><?= e($quando) ?></span>
                    <span class="d2-tag" data-tooltip="<?= e(t('gateway.origem_ip', 'Origem (IP da conexão)')) ?>"><?= d2icone('wifi') ?><span class="d2-mono"><?= e((string) ($r['client_ip'] ?: '')) ?></span></span>
                    <?php if (!empty($r['server_token'])): ?>
                        <span class="d2-tag" data-tooltip="<?= e(t('gateway.token_servidor', 'Token do servidor Ravi de origem')) ?>"><?= d2icone('chave') ?><span class="d2-mono"><?= e((string) $r['server_token']) ?></span></span>
                    <?php endif; ?>
                    <?php if (!empty($r['server_ips'])): ?>
                        <span class="d2-tag" data-tooltip="<?= e(t('gateway.ips_servidor', 'IPs reais declarados pelo servidor')) ?>"><?= d2icone('computador') ?><span class="d2-mono"><?= e((string) $r['server_ips']) ?></span></span>
                    <?php endif; ?>
                    <?php if (is_array($extra)): foreach ($extra as $ek => $ev):
                        if (in_array($ek, ['tokenravi', 'server_token', 'server_ips'], true) || $ev === null || $ev === '') continue; ?>
                        <span class="d2-tag" data-tooltip="<?= e(t('gateway.campo', 'Campo') . ' ' . $ek) ?>"><span class="d2-mono"><?= e((string) $ek) ?>=<?= e(is_scalar($ev) ? (string) $ev : json_encode($ev)) ?></span></span>
                    <?php endforeach; endif; ?>
                    <span class="d2-mono gw-chamada-modelo"><?= e((string) ($r['model_served'] ?: ($r['model_requested'] ?: ''))) ?></span>
                    <span class="d2-selo <?= $ok ? 'd2-selo-ok' : 'd2-selo-erro' ?>"><span class="d2-ponto"></span><?= e($ok ? t('gateway.ok', 'ok') : t('gateway.erro', 'erro')) ?></span>
                    <span class="texto-mudo d2-num gw-chamada-meta"><?= e($fmt($r['prompt_tokens'])) ?> in · <?= e($fmt($r['output_tokens'])) ?> out · <?= e($fmt($r['duration_ms'])) ?> ms</span>
                </div>
                <?php if (!$ok && !empty($r['error'])): ?>
                    <p class="gw-chamada-erro"><?= d2icone('alerta') ?><span><?= e((string) $r['error']) ?></span></p>
                <?php endif; ?>
                <details class="gw-detalhe">
                    <summary><?= e(t('gateway.ver_chamada', 'Ver chamada (prompt enviado)')) ?></summary>
                    <pre class="codigo"><?= e((string) ($r['request_text'] ?? t('gateway.nao_registrado', '(não registrado)'))) ?></pre>
                </details>
                <details class="gw-detalhe">
                    <summary><?= e(t('gateway.ver_retorno', 'Ver retorno (resposta)')) ?></summary>
                    <pre class="codigo"><?= e((string) ($r['response_text'] ?? t('gateway.sem_resposta', '(sem resposta)'))) ?></pre>
                </details>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </div>
</section>
</div>
