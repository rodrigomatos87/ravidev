<?php
/**
 * Configurações, servidor de e-mail (somente admin). Layout do painel (d2).
 * Dados: SettingsController@email. Teste de envio: /assets/js/paginas/configuracoes.js.
 */
$this->layout('layouts/painel', [
    'title'       => t('email.titulo', 'Servidor de e-mail'),
    'currentPage' => 'settings',
    'area'        => 'configuracoes',
    'subtitulo'   => t('email.sub', 'Usado para alertas, convites e redefinição de senha. Os dados salvos aqui valem no lugar da configuração da instalação.'),
    'acoes'       => '<a class="d2-btn d2-btn-fantasma" href="/settings">' . d2icone('seta-esquerda') . '<span>' . e(t('email.voltar', 'Configurações')) . '</span></a>',
    'scripts'     => ['/assets/js/paginas/configuracoes.js'],
    'estilos'     => ['/assets/css/paginas/configuracoes.css'],
]);
?>

<div class="d2-pilha">
    <?php if (!empty($configured)): ?>
        <div class="d2-faixa d2-faixa-ok" role="status"><?= d2icone('check') ?><div class="d2-faixa-texto"><?= e(t('email.pronto', 'Servidor de e-mail configurado e pronto para enviar.')) ?></div></div>
    <?php else: ?>
        <div class="d2-faixa d2-faixa-aviso" role="status"><?= d2icone('alerta') ?><div class="d2-faixa-texto"><?= e(t('email.incompleto', 'O servidor de e-mail ainda não está completo: faltam servidor, usuário ou senha.')) ?></div></div>
    <?php endif; ?>

    <div class="d2-mosaico">
        <section class="d2-painel" data-area="configuracoes" aria-labelledby="t-servidor">
            <header class="d2-painel-cab">
                <span class="d2-painel-icone" aria-hidden="true"><?= d2icone('email') ?></span>
                <h3 class="d2-painel-titulo" id="t-servidor"><?= e(t('email.servidor', 'Servidor')) ?></h3>
            </header>
            <div class="d2-painel-corpo">
                <form method="POST" action="/settings/email" class="d2-pilha" id="email-form">
                    <?= csrf_field() ?>
                    <div class="form-grade">
                        <div class="d2-field is-cheio">
                            <label class="d2-label" for="email-host"><?= e(t('email.host', 'Servidor SMTP')) ?></label>
                            <input type="text" class="d2-control" id="email-host" name="host" value="<?= e($cfg['host']) ?>" placeholder="smtp.zoho.com" required>
                        </div>
                        <div class="d2-field">
                            <label class="d2-label" for="email-porta"><?= e(t('email.porta', 'Porta')) ?></label>
                            <input type="number" class="d2-control" id="email-porta" name="port" value="<?= e($cfg['port']) ?>" placeholder="587" required>
                        </div>
                        <div class="d2-field">
                            <label class="d2-label" for="email-cripto"><?= e(t('email.cripto', 'Criptografia')) ?></label>
                            <select class="d2-control" id="email-cripto" name="encryption">
                                <option value="tls" <?= $cfg['encryption'] === 'tls' ? 'selected' : '' ?>><?= e(t('email.tls', 'TLS (STARTTLS, porta 587)')) ?></option>
                                <option value="ssl" <?= $cfg['encryption'] === 'ssl' ? 'selected' : '' ?>><?= e(t('email.ssl', 'SSL (porta 465)')) ?></option>
                                <option value="none" <?= $cfg['encryption'] === 'none' ? 'selected' : '' ?>><?= e(t('email.nenhuma', 'Nenhuma')) ?></option>
                            </select>
                        </div>
                        <div class="d2-field is-cheio">
                            <label class="d2-label" for="email-usuario"><?= e(t('email.usuario', 'Usuário (login)')) ?></label>
                            <input type="text" class="d2-control" id="email-usuario" name="username" value="<?= e($cfg['username']) ?>" placeholder="voce@suaempresa.com" autocomplete="off" required>
                        </div>
                        <div class="d2-field is-cheio">
                            <label class="d2-label" for="email-senha"><?= e(t('email.senha', 'Senha')) ?></label>
                            <input type="password" class="d2-control" id="email-senha" name="password" autocomplete="new-password"
                                   placeholder="<?= e(!empty($cfg['has_password']) ? t('email.senha_manter_ph', 'Mantém a atual se ficar vazio') : t('email.senha_ph', 'Senha ou senha de app')) ?>">
                            <span class="d2-field-hint"><?= e(!empty($cfg['has_password']) ? t('email.senha_manter', 'Deixe em branco para manter a senha já salva.') : t('email.senha_app', 'Para Zoho ou Gmail, use uma senha de app.')) ?></span>
                        </div>
                        <div class="d2-field">
                            <label class="d2-label" for="email-remetente"><?= e(t('email.remetente', 'Enviar como (e-mail)')) ?></label>
                            <input type="email" class="d2-control" id="email-remetente" name="from_address" value="<?= e($cfg['from_address']) ?>" placeholder="noreply@suaempresa.com" required>
                        </div>
                        <div class="d2-field">
                            <label class="d2-label" for="email-nome"><?= e(t('email.nome', 'Nome do remetente')) ?></label>
                            <input type="text" class="d2-control" id="email-nome" name="from_name" value="<?= e($cfg['from_name']) ?>" placeholder="Ravi Developer">
                        </div>
                    </div>
                    <div class="form-acoes">
                        <button type="submit" class="d2-btn d2-btn-primario"><?= d2icone('check') ?><span><?= e(t('email.salvar', 'Salvar configuração')) ?></span></button>
                    </div>
                </form>
            </div>
        </section>

        <section class="d2-painel" data-area="configuracoes" aria-labelledby="t-teste">
            <header class="d2-painel-cab">
                <span class="d2-painel-icone" aria-hidden="true"><?= d2icone('enviar') ?></span>
                <h3 class="d2-painel-titulo" id="t-teste"><?= e(t('email.teste', 'Testar envio')) ?></h3>
            </header>
            <div class="d2-painel-corpo">
                <form class="d2-pilha" id="email-teste-form" novalidate>
                    <p class="texto-mudo"><?= e(t('email.teste_sub', 'Envia um e-mail de teste para confirmar que as credenciais funcionam. Salve a configuração antes de testar e use uma caixa que exista.')) ?></p>
                    <div class="d2-field">
                        <label class="d2-label" for="email-teste-para"><?= e(t('email.teste_para', 'Enviar teste para')) ?></label>
                        <input type="email" class="d2-control" id="email-teste-para" value="<?= e($adminEmail) ?>" placeholder="seu@email.com" required>
                    </div>
                    <div id="email-teste-resultado" aria-live="polite"></div>
                    <div class="form-acoes">
                        <button type="submit" class="d2-btn d2-btn-secundario" id="email-teste-btn"><?= d2icone('enviar') ?><span><?= e(t('email.teste_btn', 'Enviar e-mail de teste')) ?></span></button>
                    </div>
                </form>
            </div>
        </section>
    </div>
</div>
