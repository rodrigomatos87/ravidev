<?php
/**
 * Abas das telas de licenças (Claude Code e ChatGPT).
 *   $abaAtiva     'claude' | 'chatgpt'
 *   $totalChatgpt int quantidade de licenças do ChatGPT (selo na aba)
 */
$abasLicencas = [
    'claude'  => ['/admin/licenses', t('licencas.aba_claude', 'Claude Code')],
    'chatgpt' => ['/admin/licenses/chatgpt', t('licencas.aba_chatgpt', 'ChatGPT (Codex)')],
];
?>
<nav class="d2-tabs-head licencas-abas" aria-label="<?= e(t('licencas.abas', 'Tipo de licença')) ?>">
    <?php foreach ($abasLicencas as $chaveAba => [$hrefAba, $rotuloAba]): ?>
        <a class="d2-tab<?= $abaAtiva === $chaveAba ? ' is-ativo' : '' ?>" href="<?= e($hrefAba) ?>"<?= $abaAtiva === $chaveAba ? ' aria-current="page"' : '' ?>>
            <span><?= e($rotuloAba) ?></span>
            <?php if ($chaveAba === 'chatgpt' && (int) ($totalChatgpt ?? 0) > 0): ?><span class="d2-tag"><?= (int) $totalChatgpt ?></span><?php endif; ?>
        </a>
    <?php endforeach; ?>
</nav>
