<?php $this->layout('layouts/auth', ['title' => t('entrada.login_titulo', 'Entrar')]); ?>
<?php
$erros = is_array($errors ?? null) ? $errors : [];
$errosGerais = array_filter($erros, static fn($v, $k) => !in_array($k, ['email', 'password'], true) && is_scalar($v), ARRAY_FILTER_USE_BOTH);
?>
<header class="entrada-cab">
    <span class="entrada-cab-icone"><?= d2icone('conta') ?></span>
    <h1 class="entrada-titulo"><?= e(t('entrada.login_titulo', 'Entrar')) ?></h1>
    <p class="entrada-texto"><?= e(t('entrada.login_texto', 'Acesse sua conta para continuar.')) ?></p>
</header>

<?php if ($errosGerais): ?>
    <div class="entrada-faixas">
        <div class="d2-faixa d2-faixa-erro" role="alert">
            <?= d2icone('erro') ?>
            <ul class="d2-faixa-texto entrada-faixa-lista">
                <?php foreach ($errosGerais as $erro): ?><li><?= e($erro) ?></li><?php endforeach; ?>
            </ul>
        </div>
    </div>
<?php endif; ?>

<form method="POST" action="/login" class="entrada-form" id="login-form" data-entrada-form>
    <?= csrf_field() ?>

    <div class="d2-field<?= isset($erros['email']) ? ' is-invalid' : '' ?>">
        <label for="email" class="d2-label"><?= e(t('entrada.email', 'E-mail')) ?></label>
        <input type="email" id="email" name="email" class="d2-control" placeholder="seu@email.com"
               value="<?= e($old['email'] ?? '') ?>" autocomplete="username" required autofocus
               <?= isset($erros['email']) ? 'aria-invalid="true" aria-describedby="email-erro"' : '' ?>>
        <?php if (isset($erros['email'])): ?>
            <span class="d2-field-erro" id="email-erro"><?= d2icone('erro') ?><?= e($erros['email']) ?></span>
        <?php endif; ?>
    </div>

    <div class="d2-field<?= isset($erros['password']) ? ' is-invalid' : '' ?>">
        <div class="entrada-rotulo-linha">
            <label for="password" class="d2-label"><?= e(t('entrada.senha', 'Senha')) ?></label>
            <a href="/forgot-password" class="d2-link entrada-link"><?= e(t('entrada.esqueceu', 'Esqueceu a senha?')) ?></a>
        </div>
        <div class="d2-entrada">
            <input type="password" id="password" name="password" placeholder="••••••••" autocomplete="current-password" required
                   aria-describedby="password-caps<?= isset($erros['password']) ? ' password-erro' : '' ?>">
            <button type="button" class="d2-iconbtn d2-entrada-sufixo" data-mostrar-senha="password" aria-pressed="false"
                    aria-label="<?= e(t('entrada.mostrar_senha', 'Mostrar senha')) ?>" data-tooltip="<?= e(t('entrada.mostrar_senha', 'Mostrar senha')) ?>">
                <?= d2icone('olho') ?>
            </button>
        </div>
        <span class="d2-field-caps" id="password-caps" data-caps-de="password" aria-live="polite"></span>
        <?php if (isset($erros['password'])): ?>
            <span class="d2-field-erro" id="password-erro"><?= d2icone('erro') ?><?= e($erros['password']) ?></span>
        <?php endif; ?>
    </div>

    <label class="d2-check entrada-check">
        <input type="checkbox" name="remember" value="1">
        <span><?= e(t('entrada.manter', 'Manter conectado')) ?> <span class="entrada-check-dica"><?= e(t('entrada.manter_dica', '(confiar neste navegador)')) ?></span></span>
    </label>

    <button type="submit" class="d2-btn d2-btn-primary entrada-enviar" id="login-btn">
        <span><?= e(t('entrada.entrar', 'Entrar')) ?></span><?= d2icone('seta-direita') ?>
    </button>
</form>

<div class="entrada-acoes-extra">
    <span><?= e(t('entrada.sem_conta', 'Não tem uma conta?')) ?> <a href="/register" class="d2-link"><?= e(t('entrada.criar_conta', 'Criar conta')) ?></a></span>
    <form method="POST" action="/resend-verification" id="resend-form" data-reenviar-confirmacao>
        <?= csrf_field() ?>
        <input type="hidden" name="email" id="resend-email">
        <span><?= e(t('entrada.sem_confirmacao', 'Não recebeu a confirmação de e-mail?')) ?>
            <button type="submit" class="d2-link" id="resend-verify-link"><?= e(t('entrada.reenviar', 'Reenviar')) ?></button></span>
    </form>
</div>
