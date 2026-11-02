<?php
$this->layout('layouts/chat', [
    'title' => $conversation->title ?? t('chat.nova_conversa', 'Nova conversa'),
]);

$sbCollapsed = (($_COOKIE['ravi_sidebar'] ?? 'mini') !== 'open');
$__u = auth()->user();
$userInitial = mb_strtoupper(mb_substr(trim((string)($__u->name ?? 'U')), 0, 1));
$chatCostVisible = !empty($custoVisivel);
$entregue = !empty($delivery['zip']) || !empty($delivery['previewable']) || !empty($delivery['mobile']);
// Dados para o módulo da página (public/assets/js/paginas/chat-boot.js).
$dadosPagina = [
    'wsUrl' => $wsUrl ?? '',
    'conversationId' => $conversation->uuid ?? '',
    'projectId' => $project->uuid,
    'projectName' => $project->name,
    'isNew' => !isset($conversation->id),
    'status' => $conversation->status ?? '',
    'workSeconds' => (int) ($conversation->work_seconds ?? 0),
    'mobile' => !empty($delivery['mobile']),
    'delivered' => $entregue,
    'previewable' => !empty($delivery['previewable']) || !empty($delivery['mobile']),
    'zip' => $delivery['zip'] ?? '',
    'demo' => $delivery['demo'] ?? null,
];
?>
<script type="application/json" id="dados-pagina"><?= json_encode($dadosPagina, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>

<div class="chat-container<?= $sbCollapsed ? ' sidebar-collapsed' : '' ?>"
     data-conversation-id="<?= e($conversation->uuid ?? '') ?>"
     data-project-id="<?= e($project->uuid) ?>"
     data-user-initial="<?= e($userInitial) ?>"
     data-user-avatar="<?= e($__u->avatar_url ?? '') ?>">

    <!-- Lateral: conversas do projeto -->
    <aside class="chat-sidebar" id="chat-sidebar">
        <div class="chat-sidebar-header">
            <a href="/projects/<?= e($project->uuid) ?>" class="chat-back-link" data-tooltip="<?= e(t('chat.voltar_projeto', 'Voltar ao projeto')) ?>" aria-label="<?= e(t('chat.voltar_projeto', 'Voltar ao projeto')) ?>">
                <?= d2icone('seta-esquerda') ?>
            </a>
            <h3><?= e($project->icon) ?> <?= e($project->name) ?></h3>
            <button type="button" class="d2-btn d2-btn-primary d2-btn-sm" id="new-conversation-btn">
                <?= d2icone('mais') ?>
                <span><?= e(t('chat.nova', 'Nova')) ?></span>
            </button>
        </div>

        <div class="chat-sidebar-search">
            <?= d2icone('busca') ?>
            <label class="d2-oculto-leitor" for="conversation-search"><?= e(t('chat.buscar_conversas', 'Buscar conversas')) ?></label>
            <input type="search" placeholder="<?= e(t('chat.buscar_conversas_ph', 'Buscar conversas...')) ?>" id="conversation-search" autocomplete="off">
        </div>

        <nav class="conversations-list" id="conversations-list" aria-label="<?= e(t('chat.conversas', 'Conversas')) ?>">
            <?php if (isset($conversations) && count($conversations) > 0): ?>
                <?php foreach ($conversations as $conv): ?>
                    <a href="/chat/<?= e($project->uuid) ?>/<?= e($conv->uuid) ?>"
                       class="conversation-item <?= (isset($conversation) && $conversation->id === $conv->id) ? 'active' : '' ?> <?= $conv->status === 'running' ? 'is-working' : '' ?>"
                       data-id="<?= e($conv->uuid) ?>">
                        <span class="conv-avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr(trim((string)($conv->title ?: 'C')), 0, 1))) ?></span>
                        <div class="conversation-item-content">
                            <span class="conversation-title"><?= e(truncate($conv->title, 40)) ?></span>
                            <span class="conversation-meta"><?= time_ago($conv->updated_at) ?></span>
                        </div>
                        <span class="conversation-status status-<?= e($conv->status) ?>"></span>
                        <button class="conv-delete" type="button" data-del="<?= e($conv->uuid) ?>" data-tooltip="<?= e(t('chat.excluir_conversa', 'Excluir conversa')) ?>" aria-label="<?= e(t('chat.excluir_conversa', 'Excluir conversa')) ?>">
                            <?= d2icone('lixeira') ?>
                        </button>
                    </a>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-conversations d2-vazio">
                    <p class="d2-vazio-texto"><?= e(t('chat.sem_conversas', 'Nenhuma conversa ainda')) ?></p>
                    <button type="button" class="d2-btn d2-btn-sm" data-acao="nova-conversa"><?= d2icone('mais') ?> <?= e(t('chat.comecar_conversa', 'Começar uma conversa')) ?></button>
                </div>
            <?php endif; ?>
        </nav>
    </aside>

    <!-- Área principal -->
    <div class="chat-main">
        <header class="chat-header">
            <button type="button" class="btn-icon chat-sidebar-toggle" id="chat-sidebar-toggle" data-tooltip="<?= e(t('chat.recolher_menu', 'Recolher menu')) ?>" aria-label="<?= e(t('chat.recolher_menu', 'Recolher menu')) ?>">
                <!-- Menu ABERTO (clique recolhe): painel com seta para a esquerda -->
                <svg class="sb-icon sb-icon-close" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <rect x="3" y="4" width="18" height="16" rx="2"/><path d="M9 4v16"/><path d="M15.5 9.5 13 12l2.5 2.5"/>
                </svg>
                <!-- Menu RECOLHIDO (clique abre): painel com seta para a direita -->
                <svg class="sb-icon sb-icon-open" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <rect x="3" y="4" width="18" height="16" rx="2"/><path d="M9 4v16"/><path d="M13 9.5 15.5 12 13 14.5"/>
                </svg>
            </button>

            <div class="chat-header-info">
                <h2 id="chat-title" data-tooltip="<?= e(t('chat.duplo_clique_renomear', 'Duplo clique para renomear')) ?>"><?= e($conversation->title ?? t('chat.nova_conversa', 'Nova conversa')) ?></h2>
                <span class="chat-status" id="chat-status">
                    <span class="status-text"><?= (($conversation->status ?? '') === 'running') ? e(t('chat.trabalhando', 'Trabalhando')) : e(t('chat.parado', 'Parado')) ?></span>
                    <?php $convChipCost = (float) ($conversation->total_cost ?? 0); ?>
                    <?php if ($chatCostVisible): ?>
                    <span class="chat-cost" id="chat-cost" data-base="<?= number_format($convChipCost, 6, '.', '') ?>" data-tooltip="<?= e(t('chat.custo_conversa', 'Custo desta conversa (com cache)')) ?>"<?= ($convChipCost > 0) ? '' : ' style="display:none"' ?>><?= formatCost($convChipCost) ?></span>
                    <?php endif; ?>
                </span>
                <span class="chat-conexao" id="chat-conexao" role="status" aria-live="polite" hidden></span>
            </div>

            <?php $wsec = (int) ($conversation->work_seconds ?? 0); $wh = floor($wsec/3600); $wm = floor(($wsec%3600)/60); $wx = $wsec%60; $whms = ($wh > 0 ? ($wh.':') : '') . str_pad((string)$wm, 2, '0', STR_PAD_LEFT) . ':' . str_pad((string)$wx, 2, '0', STR_PAD_LEFT); ?>
            <span class="chat-worktime" id="chat-worktime" data-tooltip="<?= e(t('chat.tempo_trabalho', 'Tempo de trabalho do Dev autônomo nesta conversa (conta enquanto processa)')) ?>"><span class="wt-label"><?= e(t('chat.trabalhado', 'Trabalhado')) ?></span> <span class="wt-val" id="chat-worktime-val"><?= e($whms) ?></span></span>

            <div class="chat-header-actions">
                <button type="button" class="btn-icon" id="theme-toggle" data-tooltip="<?= e(t('chat.alternar_tema', 'Alternar tema')) ?>" aria-label="<?= e(t('chat.alternar_tema', 'Alternar tema')) ?>">
                    <?= d2icone('sol', 'tema-sol') ?><?= d2icone('lua', 'tema-lua') ?><?= d2icone('auto', 'tema-auto') ?>
                </button>
                <button type="button" class="btn-icon" id="files-toggle" data-tooltip="<?= e(t('chat.arquivos_gerados', 'Arquivos gerados')) ?>" aria-label="<?= e(t('chat.arquivos_gerados', 'Arquivos gerados')) ?>">
                    <?= d2icone('documento') ?>
                    <span class="badge" id="files-count" style="display: none;">0</span>
                </button>
                <button type="button" class="btn-icon" id="chat-settings" data-tooltip="<?= e(t('chat.opcoes', 'Opções')) ?>" aria-label="<?= e(t('chat.opcoes_conversa', 'Opções da conversa')) ?>" aria-haspopup="menu">
                    <?= d2icone('reticencias') ?>
                </button>
            </div>
        </header>

        <!-- Mensagens -->
        <div class="chat-messages" id="chat-messages">
            <?php if (isset($messages) && count($messages) > 0): ?>
                <?php foreach ($messages as $message): ?>
                    <?php include __DIR__ . '/../partials/message-bubble.php'; ?>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="chat-welcome" id="chat-welcome">
                    <h3><?= e(t('chat.dev_autonomo', 'Dev autônomo')) ?></h3>
                    <p class="welcome-sub"><?= e(t('chat.boas_vindas', 'Isto não é um chat comum: descreva o que você quer construir e o sistema planeja, programa, testa, revisa a segurança e entrega o projeto pronto, com preview ao vivo e download.')) ?></p>
                    <div class="welcome-examples-label"><?= e(t('chat.experimente', 'Experimente pedir')) ?></div>
                    <div class="example-prompts">
                        <button type="button" class="example-prompt" data-prompt="Crie uma API REST em PHP para gerenciar tarefas com CRUD completo, validação e testes">
                            <?= e(t('chat.exemplo_api', 'API REST de tarefas em PHP')) ?>
                        </button>
                        <button type="button" class="example-prompt" data-prompt="Crie um painel web bonito com login, listagem de usuários e gráficos, usando PHP e SQLite">
                            <?= e(t('chat.exemplo_painel', 'Painel web com login e gráficos')) ?>
                        </button>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Indicador de digitação -->
            <div class="typing-indicator" id="typing-indicator" style="display: none;">
                <div class="typing-avatar" aria-hidden="true">🤖</div>
                <div class="typing-dots">
                    <span></span><span></span><span></span>
                </div>
            </div>

            <?php /* Card de ENTREGA (hero) renderizado no SERVIDOR, dentro do thread: aparece SEMPRE que
                     houver entrega (não depende de timing do JS). O chat.js só posiciona no fim, popula as
                     credenciais e gerencia o ciclo (some ao reabrir um build, volta na nova entrega). */ ?>
            <?php if ($entregue):
                $dPrev = !empty($delivery['previewable']); $dMob = !empty($delivery['mobile']);
                $dSub  = $dPrev ? t('chat.entrega_sub_preview', 'Tudo pronto: abra no navegador, teste de verdade e baixe o pacote completo.')
                                : t('chat.entrega_sub', 'Tudo pronto: baixe o pacote completo para implantar onde quiser.');
            ?>
            <div class="delivery-hero" id="delivery-banner"<?= $dMob ? ' data-mobile="1"' : '' ?>>
                <div class="dh-head">
                    <span class="dh-badge"><?= d2icone('check') ?></span>
                    <div class="dh-titles"><div class="dh-title"><?= e(t('chat.projeto_entregue', 'Projeto entregue')) ?></div><div class="dh-sub"><?= e($dSub) ?></div></div>
                </div>
                <div class="dh-actions">
                    <?php if ($dPrev): ?><div class="du-preview-body"><button class="btn btn-primary du-preview-open" type="button"><?= e(t('chat.abrir_preview', 'Abrir preview ao vivo')) ?></button></div><?php endif; ?>
                    <?php if ($dMob): ?><div class="du-apk-body"></div><div class="du-expo-body"></div><?php endif; ?>
                    <?php if (!empty($delivery['zip'])): ?><a class="btn btn-secondary" href="/chat/<?= e($project->uuid) ?>/download?file=<?= urlencode($delivery['zip']) ?>"><?= e(t('chat.baixar_projeto', 'Baixar projeto (.zip)')) ?></a><?php else: ?><button class="btn btn-secondary du-files" type="button"><?= e(t('chat.ver_arquivos', 'Ver arquivos')) ?></button><?php endif; ?>
                </div>
                <div class="du-creds-slot"></div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Entrada -->
        <div class="chat-input-container">
            <div class="attach-chips" id="attach-chips"></div>
            <div class="chat-input-wrapper">
                <div class="chat-input-actions">
                    <button type="button" class="btn-icon" id="attach-file" data-tooltip="<?= e(t('chat.anexar', 'Anexar arquivo')) ?>" aria-label="<?= e(t('chat.anexar', 'Anexar arquivo')) ?>">
                        <?= d2icone('anexo') ?>
                    </button>
                </div>

                <label class="d2-oculto-leitor" for="message-input"><?= e(t('chat.mensagem', 'Mensagem')) ?></label>
                <textarea id="message-input"
                          placeholder="<?= e(t('chat.descreva_tarefa', 'Descreva sua tarefa de desenvolvimento...')) ?>"
                          rows="1"
                          autofocus></textarea>

                <div class="chat-input-send">
                    <button type="button" class="btn btn-primary btn-icon-lg" id="send-message" data-tooltip="<?= e(t('chat.enviar_enter', 'Enviar (Enter)')) ?>" aria-label="<?= e(t('chat.enviar', 'Enviar')) ?>">
                        <?= d2icone('enviar') ?>
                    </button>
                    <button type="button" class="btn btn-danger btn-icon-lg" id="cancel-task" data-tooltip="<?= e(t('chat.cancelar_tarefa', 'Cancelar tarefa')) ?>" aria-label="<?= e(t('chat.cancelar_tarefa', 'Cancelar tarefa')) ?>" style="display: none;">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <rect x="5" y="5" width="14" height="14" rx="2" ry="2"/>
                        </svg>
                    </button>
                </div>
            </div>

            <div class="chat-input-footer">
                <span class="char-count"><span class="model-label" data-tooltip="<?= e(t('chat.modelo_em_uso', 'Modelo de IA em uso')) ?>"><?= e($rotuloModelo ?? '') ?></span></span>
                <span class="input-hint"><?= e(t('chat.dica_enter', 'Enter para enviar, Shift+Enter para nova linha')) ?></span>
            </div>
        </div>
    </div>

    <!-- Painel de arquivos -->
    <aside class="files-panel" id="files-panel" aria-label="<?= e(t('chat.arquivos_gerados', 'Arquivos gerados')) ?>">
        <div class="files-panel-header">
            <div class="files-panel-titles">
                <h3><?= e(t('chat.arquivos_gerados', 'Arquivos gerados')) ?></h3>
                <span class="files-total" id="files-total"></span>
            </div>
            <button type="button" class="btn-icon" id="files-close" data-tooltip="<?= e(t('chat.fechar', 'Fechar')) ?>" aria-label="<?= e(t('chat.fechar_arquivos', 'Fechar arquivos')) ?>">
                <?= d2icone('fechar') ?>
            </button>
        </div>

        <div class="files-panel-content">
            <div class="files-tree" id="files-tree">
                <!-- Preenchido pelo chat.js -->
            </div>
        </div>

        <div class="files-panel-footer">
            <button type="button" class="d2-btn d2-btn-sm d2-btn-bloco" id="download-all" disabled>
                <?= d2icone('exportar') ?>
                <?= e(t('chat.baixar_tudo', 'Baixar tudo (.zip)')) ?>
            </button>
        </div>
    </aside>
</div>

<!-- Modal de código (ver, editar, salvar, baixar): fecha só pelo X -->
<div id="code-modal" class="code-modal" role="dialog" aria-modal="true" aria-labelledby="code-modal-title">
    <div class="code-modal-box">
        <div class="code-modal-header">
            <span class="code-modal-filename" id="code-modal-title"><?= e(t('chat.arquivo', 'arquivo')) ?></span>
            <span class="code-modal-info" id="code-modal-info"></span>
            <div class="code-modal-actions">
                <button class="d2-btn d2-btn-sm d2-btn-primary" id="code-modal-save" type="button" disabled><?= e(t('chat.salvar', 'Salvar')) ?></button>
                <button class="d2-btn d2-btn-sm" id="code-modal-download" type="button"><?= d2icone('exportar') ?> <?= e(t('chat.baixar', 'Baixar')) ?></button>
                <button class="btn-icon" id="code-modal-close" type="button" data-tooltip="<?= e(t('chat.fechar', 'Fechar')) ?>" aria-label="<?= e(t('chat.fechar', 'Fechar')) ?>">
                    <?= d2icone('fechar') ?>
                </button>
            </div>
        </div>
        <div class="code-modal-body">
            <label class="d2-oculto-leitor" for="code-modal-area"><?= e(t('chat.conteudo_arquivo', 'Conteúdo do arquivo')) ?></label>
            <textarea id="code-modal-area" spellcheck="false" wrap="off"></textarea>
        </div>
    </div>
</div>
