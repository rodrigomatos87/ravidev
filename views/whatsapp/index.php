<?php
/**
 * Minha conta, alertas no WhatsApp: conexão por QR, contatos que recebem e quais alertas enviar.
 * Layout do painel (d2). Dados: WhatsAppController@index. Comportamento: /assets/js/paginas/whatsapp.js.
 */
$this->layout('layouts/painel', [
    'title'       => t('conta.titulo', 'Minha conta'),
    'currentPage' => 'account',
    'area'        => 'conta',
    'subtitulo'   => t('wa.sub', 'Receba no WhatsApp um aviso quando uma tarefa terminar ou falhar.'),
    'scripts'     => ['/assets/js/paginas/whatsapp.js'],
    'estilos'     => ['/assets/css/paginas/conta.css', '/assets/css/paginas/whatsapp.css'],
]);
$conectado = ($connection && ($connection['status'] ?? '') === 'connected');
?>

<div class="d2-pilha">
    <?php include __DIR__ . '/../partials/account-tabs.php'; ?>

    <div class="d2-mosaico">
        <section class="d2-painel" data-area="conta" aria-labelledby="t-wa-conexao">
            <header class="d2-painel-cab">
                <span class="d2-painel-icone" aria-hidden="true"><?= d2icone('atendimento') ?></span>
                <h3 class="d2-painel-titulo" id="t-wa-conexao"><?= e(t('wa.conexao', 'Conexão')) ?></h3>
                <?php if ($conectado): ?>
                    <span class="d2-selo d2-selo-ok"><span class="d2-ponto" aria-hidden="true"></span><span class="d2-selo-texto"><?= e(t('wa.conectado', 'Conectado')) ?></span></span>
                <?php else: ?>
                    <span class="d2-selo d2-selo-neutro"><span class="d2-ponto" aria-hidden="true"></span><span class="d2-selo-texto"><?= e(t('wa.desconectado', 'Não conectado')) ?></span></span>
                <?php endif; ?>
            </header>
            <div class="d2-painel-corpo d2-pilha">
                <?php if ($conectado): ?>
                    <div class="d2-faixa d2-faixa-ok">
                        <?= d2icone('check') ?>
                        <div class="d2-faixa-texto">
                            <span class="d2-faixa-titulo"><?= e(t('wa.conectado_titulo', 'WhatsApp conectado')) ?></span>
                            <?php if (!empty($connection['phone_number'])): ?><span class="d2-num wa-linha"><?= e($connection['phone_number']) ?></span><?php endif; ?>
                            <?php if (!empty($connection['connected_at'])): ?><span class="texto-mudo d2-num wa-linha"><?= e(t('wa.desde', 'Desde')) ?> <?= e(date('d/m/Y \à\s H:i', strtotime($connection['connected_at']))) ?></span><?php endif; ?>
                        </div>
                    </div>
                    <div class="wa-acoes">
                        <button type="button" class="d2-btn d2-btn-secundario" id="wa-testar"><?= d2icone('enviar') ?><span><?= e(t('wa.testar', 'Enviar teste')) ?></span></button>
                        <form method="POST" action="/whatsapp/disconnect" id="wa-desconectar-form">
                            <?= csrf_field() ?>
                            <button type="submit" class="d2-btn d2-btn-danger" id="wa-desconectar"><?= d2icone('offline') ?><span><?= e(t('wa.desconectar', 'Desconectar')) ?></span></button>
                        </form>
                    </div>
                <?php else: ?>
                    <div id="wa-convite" class="d2-vazio">
                        <span class="d2-vazio-icone" aria-hidden="true"><?= d2icone('qr') ?></span>
                        <p class="d2-vazio-titulo"><?= e(t('wa.convite_titulo', 'Conecte seu WhatsApp')) ?></p>
                        <p class="d2-vazio-texto"><?= e(t('wa.convite_texto', 'Para receber os alertas direto no celular.')) ?></p>
                        <button type="button" class="d2-btn d2-btn-primario" id="wa-conectar"><?= d2icone('qr') ?><span><?= e(t('wa.conectar', 'Conectar WhatsApp')) ?></span></button>
                    </div>
                    <div id="wa-qr-bloco" class="wa-qr-bloco" hidden>
                        <p class="texto-mudo"><?= e(t('wa.qr_instrucao', 'Escaneie o código com o seu WhatsApp:')) ?></p>
                        <div class="wa-qr" id="wa-qr" role="img" aria-label="<?= e(t('wa.qr_rotulo', 'Código QR para conectar o WhatsApp')) ?>"></div>
                        <ol class="wa-passos">
                            <li><?= e(t('wa.passo1', 'Abra o WhatsApp no celular')) ?></li>
                            <li><?= t('wa.passo2_html', 'Vá em <strong>Configurações</strong>, depois <strong>Aparelhos conectados</strong>') ?></li>
                            <li><?= t('wa.passo3_html', 'Toque em <strong>Conectar um aparelho</strong>') ?></li>
                            <li><?= e(t('wa.passo4', 'Aponte a câmera para o código')) ?></li>
                        </ol>
                        <p class="wa-aguardando" id="wa-qr-estado" role="status" aria-live="polite"><span class="wa-giro" aria-hidden="true"></span><span><?= e(t('wa.aguardando', 'Aguardando a leitura do código')) ?></span></p>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <section class="d2-painel" data-area="conta" aria-labelledby="t-wa-contatos">
            <header class="d2-painel-cab">
                <span class="d2-painel-icone" aria-hidden="true"><?= d2icone('telefone') ?></span>
                <h3 class="d2-painel-titulo" id="t-wa-contatos"><?= e(t('wa.contatos', 'Contatos que recebem os alertas')) ?></h3>
            </header>
            <div class="d2-painel-corpo d2-pilha">
                <?php if (!$conectado): ?>
                    <div class="d2-vazio">
                        <span class="d2-vazio-icone" aria-hidden="true"><?= d2icone('telefone') ?></span>
                        <p class="d2-vazio-titulo"><?= e(t('wa.contatos_conecte', 'Conecte o WhatsApp para cadastrar os contatos')) ?></p>
                        <button type="button" class="d2-btn d2-btn-sm d2-btn-secundario js-wa-conectar-atalho"><?= d2icone('qr') ?><span><?= e(t('wa.conectar', 'Conectar WhatsApp')) ?></span></button>
                    </div>
                <?php else: ?>
                    <?php if (empty($alertPhones)): ?>
                        <div class="d2-vazio">
                            <span class="d2-vazio-icone" aria-hidden="true"><?= d2icone('telefone') ?></span>
                            <p class="d2-vazio-titulo"><?= e(t('wa.contatos_vazio', 'Nenhum contato ainda')) ?></p>
                            <p class="d2-vazio-texto"><?= e(t('wa.contatos_vazio_texto', 'Adicione abaixo o número que vai receber os avisos.')) ?></p>
                            <button type="button" class="d2-btn d2-btn-sm d2-btn-secundario" id="wa-focar-telefone"><?= d2icone('mais') ?><span><?= e(t('wa.adicionar_contato', 'Adicionar contato')) ?></span></button>
                        </div>
                    <?php else: ?>
                        <ul class="lista-acionavel">
                            <?php foreach ($alertPhones as $ap): ?>
                                <li>
                                    <div class="lista-item">
                                        <div class="lista-item-texto">
                                            <span class="lista-item-titulo d2-num"><?= e($ap['phone']) ?></span>
                                            <?php if (!empty($ap['label'])): ?><span class="lista-item-sub"><?= e($ap['label']) ?></span><?php endif; ?>
                                        </div>
                                        <form method="POST" action="/whatsapp/phones/<?= (int) $ap['id'] ?>/delete" class="js-wa-remover" data-telefone="<?= e($ap['phone']) ?>">
                                            <?= csrf_field() ?>
                                            <button type="submit" class="d2-btn d2-btn-sm d2-btn-fantasma" aria-label="<?= e(t('wa.remover_contato', 'Remover contato')) ?> <?= e($ap['phone']) ?>" data-tooltip="<?= e(t('wa.remover', 'Remover')) ?>"><?= d2icone('lixeira') ?></button>
                                        </form>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    <form method="POST" action="/whatsapp/phones" id="wa-contato-form" class="form-grade" novalidate>
                        <?= csrf_field() ?>
                        <div class="d2-field">
                            <label class="d2-label" for="wa-telefone"><?= e(t('wa.celular', 'Celular com DDD')) ?></label>
                            <input type="tel" class="d2-control d2-num" name="phone" id="wa-telefone" inputmode="numeric" autocomplete="off" placeholder="+55 (11) 99999-9999" required aria-describedby="wa-telefone-dica">
                            <span class="d2-field-hint wa-dica" id="wa-telefone-dica" aria-live="polite"><?= e(t('wa.celular_dica', 'Ex.: (11) 99999-9999')) ?></span>
                        </div>
                        <div class="d2-field">
                            <label class="d2-label" for="wa-rotulo"><?= e(t('wa.rotulo', 'Rótulo (opcional)')) ?></label>
                            <input type="text" class="d2-control" name="label" id="wa-rotulo" maxlength="100" placeholder="<?= e(t('wa.rotulo_ex', 'Ex.: Meu celular')) ?>">
                        </div>
                        <div class="form-acoes is-cheio">
                            <button type="submit" class="d2-btn d2-btn-primario" id="wa-adicionar" disabled><?= d2icone('mais') ?><span><?= e(t('wa.adicionar', 'Adicionar')) ?></span></button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </section>

        <section class="d2-painel" data-area="conta" aria-labelledby="t-wa-alertas">
            <header class="d2-painel-cab">
                <span class="d2-painel-icone" aria-hidden="true"><?= d2icone('sino') ?></span>
                <h3 class="d2-painel-titulo" id="t-wa-alertas"><?= e(t('wa.alertas', 'Quais alertas enviar')) ?></h3>
            </header>
            <div class="d2-painel-corpo">
                <form method="POST" action="/whatsapp/preferences" id="wa-prefs-form" class="d2-pilha">
                    <?= csrf_field() ?>
                    <ul class="lista-acionavel">
                        <?php foreach ($preferences as $tipo => $p): ?>
                            <li>
                                <div class="lista-item">
                                    <div class="lista-item-texto">
                                        <span class="lista-item-titulo" id="wa-pref-<?= e($tipo) ?>"><?= e($p['label']) ?></span>
                                    </div>
                                    <label class="d2-switch">
                                        <input type="checkbox" name="alert_<?= e($tipo) ?>" aria-labelledby="wa-pref-<?= e($tipo) ?>" <?= $p['enabled'] ? 'checked' : '' ?>>
                                        <span class="d2-switch-trilho" aria-hidden="true"></span>
                                    </label>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <div class="form-acoes">
                        <button type="submit" class="d2-btn d2-btn-primario" id="wa-prefs-salvar"><?= d2icone('check') ?><span><?= e(t('wa.salvar_prefs', 'Salvar preferências')) ?></span></button>
                    </div>
                </form>
            </div>
        </section>
    </div>
</div>
