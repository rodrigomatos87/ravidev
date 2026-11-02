<?php
/**
 * Minha conta, notificações: avisos por e-mail e aviso de trabalho longo no WhatsApp.
 * Layout do painel (d2). Dados: SettingsController@notifications. Comportamento: /assets/js/paginas/conta.js.
 */
$this->layout('layouts/painel', [
    'title'       => t('conta.titulo', 'Minha conta'),
    'currentPage' => 'account',
    'area'        => 'conta',
    'subtitulo'   => t('conta.notif_sub', 'Escolha quais avisos você quer receber.'),
    'scripts'     => ['/assets/js/paginas/conta.js'],
    'estilos'     => ['/assets/css/paginas/conta.css'],
]);
?>

<div class="d2-pilha">
    <?php include __DIR__ . '/../partials/account-tabs.php'; ?>

    <div class="d2-mosaico">
        <section class="d2-painel" data-area="conta" aria-labelledby="t-notif-email">
            <header class="d2-painel-cab">
                <span class="d2-painel-icone" aria-hidden="true"><?= d2icone('email') ?></span>
                <h3 class="d2-painel-titulo" id="t-notif-email"><?= e(t('conta.notif_email', 'Notificações por e-mail')) ?></h3>
            </header>
            <div class="d2-painel-corpo d2-pilha">
                <?php if (empty($smtpConfigured)): ?>
                    <div class="d2-faixa d2-faixa-aviso">
                        <?= d2icone('alerta') ?>
                        <div class="d2-faixa-texto"><?= e(t('conta.notif_sem_smtp', 'O envio de e-mail ainda não foi configurado pelo administrador. Suas escolhas ficam salvas e passam a valer quando ele for configurado.')) ?></div>
                    </div>
                <?php endif; ?>
                <?php if (empty($prefs)): ?>
                    <div class="d2-vazio">
                        <span class="d2-vazio-icone" aria-hidden="true"><?= d2icone('sino') ?></span>
                        <p class="d2-vazio-titulo"><?= e(t('conta.notif_vazio', 'Nenhum aviso por e-mail disponível')) ?></p>
                        <a class="d2-btn d2-btn-sm d2-btn-secundario" href="/settings/profile"><?= d2icone('conta') ?><span><?= e(t('conta.ir_perfil', 'Ir para o perfil')) ?></span></a>
                    </div>
                <?php else: ?>
                    <ul class="lista-acionavel">
                        <?php foreach ($prefs as $chave => $pref): ?>
                            <li>
                                <div class="lista-item">
                                    <div class="lista-item-texto">
                                        <span class="lista-item-titulo" id="pref-<?= e($chave) ?>"><?= e($pref['label']) ?></span>
                                    </div>
                                    <label class="d2-switch">
                                        <input type="checkbox" class="js-pref" data-pref="<?= e($chave) ?>" aria-labelledby="pref-<?= e($chave) ?>" <?= !empty($pref['on']) ? 'checked' : '' ?>>
                                        <span class="d2-switch-trilho" aria-hidden="true"></span>
                                    </label>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </section>

        <section class="d2-painel" data-area="conta" aria-labelledby="t-notif-wa">
            <header class="d2-painel-cab">
                <span class="d2-painel-icone" aria-hidden="true"><?= d2icone('atendimento') ?></span>
                <h3 class="d2-painel-titulo" id="t-notif-wa"><?= e(t('conta.notif_wa', 'WhatsApp')) ?></h3>
            </header>
            <div class="d2-painel-corpo d2-pilha">
                <p class="texto-mudo"><?= e(t('conta.notif_wa_sub', 'Aviso no WhatsApp quando um trabalho na IDE demora.')) ?></p>
                <?php if (empty($waConnected)): ?>
                    <div class="d2-faixa d2-faixa-aviso">
                        <?= d2icone('alerta') ?>
                        <div class="d2-faixa-texto"><?= e(t('conta.notif_wa_conecte', 'Conecte seu WhatsApp para os avisos saírem.')) ?></div>
                        <div class="d2-faixa-acoes"><a class="d2-btn d2-btn-sm d2-btn-secundario" href="/whatsapp"><?= d2icone('qr') ?><span><?= e(t('conta.notif_wa_conectar', 'Conectar WhatsApp')) ?></span></a></div>
                    </div>
                <?php endif; ?>
                <ul class="lista-acionavel">
                    <li>
                        <div class="lista-item">
                            <div class="lista-item-texto">
                                <span class="lista-item-titulo" id="pref-notify_ide_long"><?= e($ideLongLabel ?? t('conta.notif_ide_longo', 'IDE: avisar quando um trabalho longo terminar ou pausar')) ?></span>
                            </div>
                            <label class="d2-switch">
                                <input type="checkbox" class="js-pref" data-pref="notify_ide_long" aria-labelledby="pref-notify_ide_long" <?= !empty($ideLongOn) ? 'checked' : '' ?>>
                                <span class="d2-switch-trilho" aria-hidden="true"></span>
                            </label>
                        </div>
                    </li>
                    <li>
                        <div class="lista-item">
                            <div class="lista-item-texto">
                                <label class="lista-item-titulo" for="ide-alerta-min"><?= e(t('conta.notif_minutos', 'Avisar quando um único trabalho passar de')) ?></label>
                                <span class="lista-item-sub"><?= e(t('conta.notif_minutos_dica', 'De 1 a 240 minutos.')) ?></span>
                            </div>
                            <span class="conta-minutos">
                                <input type="number" class="d2-control d2-num" id="ide-alerta-min" min="1" max="240" value="<?= (int) ($ideAlertMin ?? 10) ?>">
                                <span class="texto-mudo"><?= e(t('conta.min', 'min')) ?></span>
                            </span>
                        </div>
                    </li>
                </ul>
                <p class="texto-mudo"><?= e(t('conta.notif_rodape', 'Os dois casos são separados: o Claude pausou aguardando você (bolinha azul) ou finalizou (entrega ou turno concluído). Trabalhos curtos não geram aviso.')) ?></p>
            </div>
        </section>
    </div>
</div>
