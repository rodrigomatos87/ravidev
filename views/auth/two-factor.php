<?php $this->layout('layouts/auth', ['title' => t('entrada.duas_etapas_titulo', 'Verificação em duas etapas')]); ?>
<?php $isEmail = ($method ?? 'totp') === 'email'; ?>
<header class="entrada-cab">
    <span class="entrada-cab-icone"><?= d2icone($isEmail ? 'email' : 'celular') ?></span>
    <h1 class="entrada-titulo"><?= e(t('entrada.duas_etapas', 'Duas etapas')) ?></h1>
    <?php if ($isEmail): ?>
        <?php /* Não revelamos QUAL e-mail: quem digitou a senha certa ainda não ganha essa informação. */ ?>
        <p class="entrada-texto"><?= e(t('entrada.duas_etapas_email', 'Enviamos um código de 6 dígitos para o e-mail cadastrado nesta conta.')) ?></p>
    <?php else: ?>
        <p class="entrada-texto"><?= e(t('entrada.duas_etapas_app', 'Abra seu app autenticador e digite o código de 6 dígitos.')) ?></p>
    <?php endif; ?>
</header>

<form method="POST" action="/login/2fa" class="entrada-form" id="tfa-form" autocomplete="off" data-entrada-form data-otp-form>
    <?= csrf_field() ?>
    <input type="hidden" name="code" id="tfa-code">
    <fieldset class="d2-field">
        <legend class="d2-oculto-leitor"><?= e(t('entrada.codigo_legenda', 'Código de verificação')) ?></legend>
        <div class="entrada-otp" id="otp-row">
            <?php for ($i = 0; $i < 6; $i++): ?>
                <?php /* size=1: sem ele o campo reserva 20 caracteres e estoura a tela no celular */ ?>
                <input type="text" class="entrada-otp-caixa" inputmode="numeric" pattern="[0-9]*" maxlength="1" size="1"
                       aria-label="<?= e(t('entrada.digito', 'Dígito') . ' ' . ($i + 1) . ' ' . t('entrada.de_seis', 'de 6')) ?>"
                       <?= $i === 0 ? 'autocomplete="one-time-code" autofocus' : 'autocomplete="off"' ?>>
            <?php endfor; ?>
        </div>
    </fieldset>
    <button type="submit" class="d2-btn d2-btn-primary entrada-enviar" id="tfa-submit" disabled>
        <span><?= e(t('entrada.verificar', 'Verificar')) ?></span><?= d2icone('check') ?>
    </button>
</form>

<div class="entrada-acoes-extra">
    <?php if ($isEmail): ?>
        <form method="POST" action="/login/2fa/resend" data-entrada-form>
            <?= csrf_field() ?>
            <button type="submit" class="d2-link"><?= e(t('entrada.reenviar_codigo', 'Não recebeu? Reenviar código')) ?></button>
        </form>
    <?php endif; ?>
    <a href="/login" class="d2-link"><?= e(t('entrada.voltar_ao_login', 'Voltar ao login')) ?></a>
</div>
