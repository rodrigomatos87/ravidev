<?php
/** @var string $token */
/** @var string $email */
/** @var array $errors */
$errors = $errors ?? [];
$token  = $token ?? '';
$email  = $email ?? '';
$this->layout('layouts/auth', ['title' => t('entrada.redefinir_titulo', 'Redefinir senha')]);
$err = fn($k) => isset($errors[$k]) ? (is_array($errors[$k]) ? $errors[$k][0] : $errors[$k]) : null;
?>
<header class="entrada-cab">
    <span class="entrada-cab-icone"><?= d2icone('cadeado') ?></span>
    <h1 class="entrada-titulo"><?= e(t('entrada.redefinir_titulo', 'Redefinir senha')) ?></h1>
    <p class="entrada-texto"><?= e(t('entrada.redefinir_texto', 'Crie uma nova senha para sua conta.')) ?></p>
</header>

<form method="POST" action="/reset-password" class="entrada-form" data-entrada-form>
    <?= csrf_field() ?>
    <input type="hidden" name="token" value="<?= e($token) ?>">
    <input type="hidden" name="email" value="<?= e($email) ?>">

    <div class="d2-field">
        <label class="d2-label" for="email_display"><?= e(t('entrada.email', 'E-mail')) ?></label>
        <input type="email" id="email_display" class="d2-control" value="<?= e($email) ?>" autocomplete="username" disabled>
    </div>

    <div class="d2-field<?= $err('password') ? ' is-invalid' : '' ?>">
        <label class="d2-label" for="password"><?= e(t('entrada.nova_senha', 'Nova senha')) ?></label>
        <div class="d2-entrada">
            <input type="password" id="password" name="password" placeholder="••••••••" minlength="8" autocomplete="new-password" required autofocus
                   aria-describedby="password-dica password-caps<?= $err('password') ? ' password-erro' : '' ?>"<?= $err('password') ? ' aria-invalid="true"' : '' ?>>
            <button type="button" class="d2-iconbtn d2-entrada-sufixo" data-mostrar-senha="password" aria-pressed="false"
                    aria-label="<?= e(t('entrada.mostrar_senha', 'Mostrar senha')) ?>" data-tooltip="<?= e(t('entrada.mostrar_senha', 'Mostrar senha')) ?>">
                <?= d2icone('olho') ?>
            </button>
        </div>
        <?php if ($err('password')): ?>
            <span class="d2-field-erro" id="password-erro"><?= d2icone('erro') ?><?= e($err('password')) ?></span>
        <?php endif; ?>
        <span class="d2-field-caps" id="password-caps" data-caps-de="password" aria-live="polite"></span>
        <span class="d2-field-hint" id="password-dica"><?= e(t('entrada.senha_dica', 'Mínimo de 8 caracteres. Recomendado: letras, números e símbolos.')) ?></span>
    </div>

    <div class="d2-field<?= $err('password_confirmation') ? ' is-invalid' : '' ?>">
        <label class="d2-label" for="password_confirmation"><?= e(t('entrada.confirmar_nova_senha', 'Confirmar nova senha')) ?></label>
        <div class="d2-entrada">
            <input type="password" id="password_confirmation" name="password_confirmation" placeholder="••••••••" minlength="8" autocomplete="new-password" required
                   aria-describedby="password_confirmation-caps<?= $err('password_confirmation') ? ' password_confirmation-erro' : '' ?>"<?= $err('password_confirmation') ? ' aria-invalid="true"' : '' ?>>
            <button type="button" class="d2-iconbtn d2-entrada-sufixo" data-mostrar-senha="password_confirmation" aria-pressed="false"
                    aria-label="<?= e(t('entrada.mostrar_senha', 'Mostrar senha')) ?>" data-tooltip="<?= e(t('entrada.mostrar_senha', 'Mostrar senha')) ?>">
                <?= d2icone('olho') ?>
            </button>
        </div>
        <?php if ($err('password_confirmation')): ?>
            <span class="d2-field-erro" id="password_confirmation-erro"><?= d2icone('erro') ?><?= e($err('password_confirmation')) ?></span>
        <?php endif; ?>
        <span class="d2-field-caps" id="password_confirmation-caps" data-caps-de="password_confirmation" aria-live="polite"></span>
    </div>

    <button type="submit" class="d2-btn d2-btn-primary entrada-enviar">
        <span><?= e(t('entrada.redefinir_titulo', 'Redefinir senha')) ?></span><?= d2icone('check') ?>
    </button>
</form>

<div class="entrada-acoes-extra">
    <a href="/login" class="d2-link"><?= e(t('entrada.voltar_login', 'Voltar para o login')) ?></a>
</div>
