<?php
/** @var array $errors */
$errors = $errors ?? [];
$this->layout('layouts/auth', ['title' => t('entrada.esqueci_titulo', 'Esqueceu a senha')]);
$erroEmail = isset($errors['email']) ? (is_array($errors['email']) ? $errors['email'][0] : $errors['email']) : null;
?>
<header class="entrada-cab">
    <span class="entrada-cab-icone"><?= d2icone('chave') ?></span>
    <h1 class="entrada-titulo"><?= e(t('entrada.esqueci_titulo_pergunta', 'Esqueceu a senha?')) ?></h1>
    <p class="entrada-texto"><?= e(t('entrada.esqueci_texto', 'Digite seu e-mail para receber as instruções de redefinição.')) ?></p>
</header>

<form method="POST" action="/forgot-password" class="entrada-form" data-entrada-form>
    <?= csrf_field() ?>

    <div class="d2-field<?= $erroEmail ? ' is-invalid' : '' ?>">
        <label class="d2-label" for="email"><?= e(t('entrada.email', 'E-mail')) ?></label>
        <input type="email" id="email" name="email" class="d2-control" placeholder="seu@email.com"
               value="<?= e($_SESSION['flash']['old']['email'] ?? '') ?>" autocomplete="email" required autofocus
               <?= $erroEmail ? 'aria-invalid="true" aria-describedby="email-erro"' : '' ?>>
        <?php if ($erroEmail): ?>
            <span class="d2-field-erro" id="email-erro"><?= d2icone('erro') ?><?= e($erroEmail) ?></span>
        <?php endif; ?>
    </div>

    <button type="submit" class="d2-btn d2-btn-primary entrada-enviar">
        <span><?= e(t('entrada.enviar_link', 'Enviar link de recuperação')) ?></span><?= d2icone('enviar') ?>
    </button>
</form>

<div class="entrada-acoes-extra">
    <a href="/login" class="d2-link"><?= e(t('entrada.voltar_login', 'Voltar para o login')) ?></a>
</div>
