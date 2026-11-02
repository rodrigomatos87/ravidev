<?php
/**
 * Minha conta, uso: tokens e custo estimado (só para quem pode ver) e estatísticas do usuário.
 * Layout do painel (d2). Dados: SettingsController@usage.
 */
$this->layout('layouts/painel', [
    'title'       => t('conta.titulo', 'Minha conta'),
    'currentPage' => 'account',
    'area'        => 'conta',
    'subtitulo'   => t('conta.uso_sub', 'Acompanhamento do seu uso, sem limites.'),
    'estilos'     => ['/assets/css/paginas/conta.css'],
]);
$fl = $usageFlags ?? [];
?>

<div class="d2-pilha">
    <?php include __DIR__ . '/../partials/account-tabs.php'; ?>

    <section class="d2-painel" data-area="conta" aria-labelledby="t-uso">
        <header class="d2-painel-cab">
            <span class="d2-painel-icone" aria-hidden="true"><?= d2icone('dinheiro') ?></span>
            <h3 class="d2-painel-titulo" id="t-uso"><?= e(t('conta.uso', 'Uso')) ?></h3>
        </header>
        <div class="d2-painel-corpo d2-pilha">
            <?php if (empty($canView)): ?>
                <div class="d2-faixa">
                    <?= d2icone('info') ?>
                    <div class="d2-faixa-texto"><?= e(t('conta.uso_restrito', 'Os valores de uso e custo estão visíveis apenas para administradores.')) ?></div>
                </div>
            <?php else: ?>
                <p class="texto-mudo"><?= e(t('conta.uso_nota', 'Sem limites, apenas acompanhamento. O custo é uma estimativa (a cota Max não cobra por token) e conta só as origens ativadas nas Configurações.')) ?></p>
                <div class="indicadores">
                    <div class="d2-indicador" data-area="licencas">
                        <span class="d2-indicador-rotulo"><?= d2icone('grafico') ?><span><?= e(t('conta.uso_tokens', 'Tokens (origens ativas)')) ?></span></span>
                        <span class="d2-indicador-valor d2-num"><?= e(formatTokens($usage['total_tokens'] ?? 0)) ?></span>
                    </div>
                    <div class="d2-indicador" data-area="gateway">
                        <span class="d2-indicador-rotulo"><?= d2icone('dinheiro') ?><span><?= e(t('conta.uso_custo', 'Custo estimado')) ?></span></span>
                        <span class="d2-indicador-valor d2-num"><?= e(formatCost($usage['total_cost'] ?? 0)) ?></span>
                    </div>
                    <div class="d2-indicador" data-area="conta">
                        <span class="d2-indicador-rotulo"><?= d2icone('calendario') ?><span><?= e(t('conta.uso_mes', 'Tokens este mês')) ?></span></span>
                        <span class="d2-indicador-valor d2-num"><?= e(formatTokens($stats['tokens_this_month'] ?? 0)) ?></span>
                    </div>
                </div>
                <dl class="kv">
                    <dt><?= e(t('conta.uso_chat', 'Chat')) ?></dt>
                    <dd><span class="d2-num"><?= e(formatCost($usage['chat']['cost'] ?? 0)) ?></span><?= empty($fl['chat']) ? ' <span class="texto-mudo">(' . e(t('conta.desligado', 'desligado')) . ')</span>' : '' ?></dd>
                    <dt><?= e(t('conta.uso_api', 'API')) ?></dt>
                    <dd><span class="d2-num"><?= e(formatCost($usage['api']['cost'] ?? 0)) ?></span><?= empty($fl['api']) ? ' <span class="texto-mudo">(' . e(t('conta.desligado', 'desligado')) . ')</span>' : '' ?></dd>
                </dl>
            <?php endif; ?>
        </div>
    </section>

    <section class="d2-painel" data-area="conta" aria-labelledby="t-estat">
        <header class="d2-painel-cab">
            <span class="d2-painel-icone" aria-hidden="true"><?= d2icone('grafico') ?></span>
            <h3 class="d2-painel-titulo" id="t-estat"><?= e(t('conta.estatisticas', 'Estatísticas')) ?></h3>
        </header>
        <div class="d2-painel-corpo">
            <div class="indicadores">
                <div class="d2-indicador" data-area="projetos">
                    <span class="d2-indicador-rotulo"><?= d2icone('lista') ?><span><?= e(t('conta.projetos', 'Projetos')) ?></span></span>
                    <span class="d2-indicador-valor d2-num"><?= (int) ($stats['projects'] ?? 0) ?></span>
                </div>
                <div class="d2-indicador" data-area="ide">
                    <span class="d2-indicador-rotulo"><?= d2icone('atendimento') ?><span><?= e(t('conta.conversas', 'Conversas')) ?></span></span>
                    <span class="d2-indicador-valor d2-num"><?= (int) ($stats['conversations'] ?? 0) ?></span>
                </div>
                <div class="d2-indicador" data-area="ide">
                    <span class="d2-indicador-rotulo"><?= d2icone('enviar') ?><span><?= e(t('conta.mensagens', 'Mensagens')) ?></span></span>
                    <span class="d2-indicador-valor d2-num"><?= (int) ($stats['messages'] ?? 0) ?></span>
                </div>
                <div class="d2-indicador" data-area="agendamentos">
                    <span class="d2-indicador-rotulo"><?= d2icone('documento') ?><span><?= e(t('conta.arquivos', 'Arquivos gerados')) ?></span></span>
                    <span class="d2-indicador-valor d2-num"><?= (int) ($stats['files_generated'] ?? 0) ?></span>
                </div>
            </div>
        </div>
    </section>
</div>
