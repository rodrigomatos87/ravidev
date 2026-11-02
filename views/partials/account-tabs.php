<?php
/**
 * Sub-menu da área pessoal "Minha conta" (d2): links em .d2-tabs-head, aba ativa pelo caminho atual.
 * Itens por usuário (nada de admin aqui). Usado pelas telas da conta no layout do painel.
 */
$__caminho = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
$__abas = [
    ['k' => 'profile',       'rotulo' => t('conta.aba_perfil', 'Perfil'),             'url' => '/settings/profile',       'icone' => 'conta'],
    ['k' => 'notifications', 'rotulo' => t('conta.aba_notificacoes', 'Notificações'), 'url' => '/settings/notifications', 'icone' => 'sino'],
    ['k' => 'github',        'rotulo' => t('conta.aba_github', 'GitHub'),             'url' => '/settings/github',        'icone' => ''],
    ['k' => 'whatsapp',      'rotulo' => t('conta.aba_whatsapp', 'WhatsApp'),         'url' => '/whatsapp',               'icone' => 'atendimento'],
    ['k' => 'usage',         'rotulo' => t('conta.aba_uso', 'Uso'),                   'url' => '/settings/usage',         'icone' => 'grafico'],
];
?>
<nav class="d2-tabs-head conta-abas" aria-label="<?= e(t('conta.abas', 'Minha conta')) ?>">
    <?php foreach ($__abas as $__a):
        $__ativa = $__caminho === $__a['url'] || ($__a['k'] === 'whatsapp' && str_starts_with($__caminho, '/whatsapp'));
    ?>
        <a class="d2-tab" href="<?= e($__a['url']) ?>" aria-selected="<?= $__ativa ? 'true' : 'false' ?>"<?= $__ativa ? ' aria-current="page"' : '' ?>>
            <?php if ($__a['icone'] !== ''): ?><?= d2icone($__a['icone']) ?><?php else: ?><svg class="d2-icone" viewBox="0 0 16 16" aria-hidden="true" focusable="false" fill="currentColor"><path d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82a7.65 7.65 0 0 1 2-.27c.68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.013 8.013 0 0 0 16 8c0-4.42-3.58-8-8-8z"/></svg><?php endif; ?>
            <span><?= e($__a['rotulo']) ?></span>
        </a>
    <?php endforeach; ?>
</nav>
