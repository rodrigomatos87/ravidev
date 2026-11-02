<?php $this->layout('layouts/auth', ['title' => t('entrada.cadastro_titulo', 'Criar conta')]); ?>
<?php $erros = is_array($errors ?? null) ? array_filter($errors, 'is_scalar') : []; ?>
<header class="entrada-cab">
    <span class="entrada-cab-icone"><?= d2icone('usuarios') ?></span>
    <h1 class="entrada-titulo"><?= e(t('entrada.cadastro_titulo', 'Criar conta')) ?></h1>
    <p class="entrada-texto"><?= e(t('entrada.cadastro_texto', 'Preencha os dados para criar sua conta.')) ?></p>
</header>

<?php if ($erros): ?>
    <div class="entrada-faixas">
        <div class="d2-faixa d2-faixa-erro" role="alert">
            <?= d2icone('erro') ?>
            <ul class="d2-faixa-texto entrada-faixa-lista">
                <?php foreach ($erros as $erro): ?><li><?= e($erro) ?></li><?php endforeach; ?>
            </ul>
        </div>
    </div>
<?php endif; ?>

<form method="POST" action="/register" class="entrada-form" id="register-form" data-entrada-form>
    <?= csrf_field() ?>

    <div class="d2-field">
        <label for="name" class="d2-label"><?= e(t('entrada.nome', 'Nome completo')) ?></label>
        <input type="text" id="name" name="name" class="d2-control" placeholder="<?= e(t('entrada.nome_exemplo', 'Seu nome')) ?>"
               value="<?= e($old['name'] ?? '') ?>" autocomplete="name" required autofocus>
    </div>

    <div class="d2-field">
        <label for="email" class="d2-label"><?= e(t('entrada.email', 'E-mail')) ?></label>
        <input type="email" id="email" name="email" class="d2-control" placeholder="seu@email.com"
               value="<?= e($old['email'] ?? '') ?>" autocomplete="email" required>
    </div>

    <div class="d2-field">
        <label for="password" class="d2-label"><?= e(t('entrada.senha', 'Senha')) ?></label>
        <div class="d2-entrada">
            <input type="password" id="password" name="password" placeholder="<?= e(t('entrada.senha_minimo', 'Mínimo 8 caracteres')) ?>"
                   autocomplete="new-password" required minlength="8" aria-describedby="password-forca-texto password-caps">
            <button type="button" class="d2-iconbtn d2-entrada-sufixo" data-mostrar-senha="password" aria-pressed="false"
                    aria-label="<?= e(t('entrada.mostrar_senha', 'Mostrar senha')) ?>" data-tooltip="<?= e(t('entrada.mostrar_senha', 'Mostrar senha')) ?>">
                <?= d2icone('olho') ?>
            </button>
        </div>
        <div class="entrada-forca" data-forca-de="password">
            <span class="entrada-forca-trilho" aria-hidden="true"><span class="entrada-forca-barra"></span></span>
            <span class="entrada-forca-texto" id="password-forca-texto" aria-live="polite"></span>
        </div>
        <span class="d2-field-caps" id="password-caps" data-caps-de="password" aria-live="polite"></span>
    </div>

    <div class="d2-field">
        <label for="password_confirmation" class="d2-label"><?= e(t('entrada.confirmar_senha', 'Confirmar senha')) ?></label>
        <div class="d2-entrada">
            <input type="password" id="password_confirmation" name="password_confirmation" placeholder="<?= e(t('entrada.repita_senha', 'Repita a senha')) ?>"
                   autocomplete="new-password" required aria-describedby="password_confirmation-caps">
            <button type="button" class="d2-iconbtn d2-entrada-sufixo" data-mostrar-senha="password_confirmation" aria-pressed="false"
                    aria-label="<?= e(t('entrada.mostrar_senha', 'Mostrar senha')) ?>" data-tooltip="<?= e(t('entrada.mostrar_senha', 'Mostrar senha')) ?>">
                <?= d2icone('olho') ?>
            </button>
        </div>
        <span class="d2-field-caps" id="password_confirmation-caps" data-caps-de="password_confirmation" aria-live="polite"></span>
    </div>

    <label class="d2-check entrada-check">
        <input type="checkbox" name="terms" value="1" required>
        <span><?= e(t('entrada.concordo', 'Concordo com os')) ?>
            <a href="/terms" target="_blank" rel="noopener" class="d2-link"><?= e(t('entrada.termos', 'Termos de Uso')) ?></a>
            <?= e(t('entrada.e', 'e')) ?>
            <a href="/privacy" target="_blank" rel="noopener" class="d2-link"><?= e(t('entrada.privacidade', 'Política de Privacidade')) ?></a></span>
    </label>

    <button type="submit" class="d2-btn d2-btn-primary entrada-enviar" id="register-btn">
        <span><?= e(t('entrada.criar_conta', 'Criar conta')) ?></span><?= d2icone('seta-direita') ?>
    </button>
</form>

<div class="entrada-acoes-extra">
    <span><?= e(t('entrada.ja_tem_conta', 'Já tem uma conta?')) ?> <a href="/login" class="d2-link"><?= e(t('entrada.entrar', 'Entrar')) ?></a></span>
</div>
