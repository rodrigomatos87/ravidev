<?php
/** Faixa com a identificação do token (nome, prefixo e situação) nas telas filhas do Gateway. Espera $token. */
$ativoTk = (int) ($token['is_active'] ?? 0) === 1 && empty($token['revoked_at']);
?>
<div class="gw-token-cab">
    <span class="gw-token-cab-icone" aria-hidden="true"><?= d2icone('chave') ?></span>
    <strong><?= e((string) ($token['name'] ?? '')) ?></strong>
    <code class="d2-mono texto-mudo"><?= e((string) ($token['token_prefix'] ?? '')) ?></code>
    <?php if ($ativoTk): ?>
        <span class="d2-selo d2-selo-ok"><span class="d2-ponto"></span><?= e(t('gateway.ativo', 'ativo')) ?></span>
    <?php else: ?>
        <span class="d2-selo d2-selo-erro"><span class="d2-ponto"></span><?= e(t('gateway.revogado', 'revogado')) ?></span>
    <?php endif; ?>
</div>
