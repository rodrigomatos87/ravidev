<?php
$isUser = $message->role === 'user';
$isToolUse = $message->role === 'tool_use';
$isToolResult = $message->role === 'tool_result';
$isAssistant = $message->role === 'assistant';
?>

<div class="message <?= $isUser ? 'message-user' : 'message-assistant' ?> <?= $isToolUse || $isToolResult ? 'message-tool' : '' ?>" 
     data-id="<?= e($message->uuid) ?>">
    
    <?php if (!$isUser): ?>
        <div class="message-avatar">
            <?php if ($isToolUse || $isToolResult): ?>
                ⚡
            <?php else: ?>
                🤖
            <?php endif; ?>
        </div>
    <?php endif; ?>
    
    <div class="message-content">
        <?php if ($isToolUse): ?>
            <!-- Tool Execution Card -->
            <div class="tool-card tool-use">
                <div class="tool-header">
                    <span class="tool-icon"><?= get_tool_icon($message->tool_name) ?></span>
                    <span class="tool-name"><?= e(format_tool_name($message->tool_name)) ?></span>
                    <span class="tool-status pending">
                        <svg class="spinner" width="14" height="14" viewBox="0 0 24 24">
                            <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" fill="none" stroke-dasharray="32" stroke-linecap="round"/>
                        </svg>
                        Executando...
                    </span>
                </div>
                
                <?php if ($message->tool_input): ?>
                    <div class="tool-input">
                        <button type="button" class="tool-toggle">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M9 18l6-6-6-6"/>
                            </svg>
                            <?= e(t('chat.ver_entrada', 'Ver entrada')) ?>
                        </button>
                        <pre class="tool-code"><code><?= e(format_tool_input($message->tool_name, $message->tool_input)) ?></code></pre>
                    </div>
                <?php endif; ?>
            </div>
            
        <?php elseif ($isToolResult): ?>
            <!-- Tool Result Card -->
            <div class="tool-card tool-result <?= str_contains($message->tool_output ?? '', 'Error') ? 'error' : 'success' ?>">
                <div class="tool-header">
                    <span class="tool-icon"><?= get_tool_icon($message->tool_name) ?></span>
                    <span class="tool-name"><?= e(format_tool_name($message->tool_name)) ?></span>
                    <?php if (str_contains($message->tool_output ?? '', 'Error')): ?>
                        <span class="tool-status error">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="10"/><path d="M15 9l-6 6M9 9l6 6"/>
                            </svg>
                            Erro
                        </span>
                    <?php else: ?>
                        <span class="tool-status success">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M20 6L9 17l-5-5"/>
                            </svg>
                            Concluído
                        </span>
                    <?php endif; ?>
                </div>
                
                <?php if ($message->tool_output): ?>
                    <div class="tool-output">
                        <button type="button" class="tool-toggle">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M9 18l6-6-6-6"/>
                            </svg>
                            <?= e(t('chat.ver_saida', 'Ver saída')) ?>
                        </button>
                        <pre class="tool-code"><code><?= e(truncate($message->tool_output, 2000)) ?></code></pre>
                    </div>
                <?php endif; ?>
            </div>
            
        <?php else: ?>
            <?php
            // Narração persistida dos agentes (passos e fala): sobrevive ao reload.
            $narr = [];
            if ($isAssistant) {
                $md = $message->metadata ?? null;
                if (is_string($md)) { $md = json_decode($md, true); }
                if (is_array($md) && !empty($md['narration']) && is_array($md['narration'])) {
                    $narr = $md['narration'];
                }
            }
            ?>
            <?php if (!empty($narr)): ?>
                <details class="message-narration">
                    <summary><?= e(t('chat.ver_processo', 'Ver processo')) ?> · <?= count($narr) ?> <?= e(t('chat.passos', 'passos')) ?></summary>
                    <div class="narration-lines">
                        <?php foreach ($narr as $ln): ?>
                            <?php $lt = $ln['type'] ?? 'step'; ?>
                            <div class="narr-line narr-<?= e($lt) ?>">
                                <?php if (!empty($ln['agent']['initial'])): ?>
                                    <?php $corAgente = (string) ($ln['agent']['color'] ?? ''); ?>
                                    <span class="narr-agent"<?= preg_match('/^#[0-9a-fA-F]{3,8}$/', $corAgente) ? ' style="--ag: ' . e($corAgente) . '"' : '' ?> data-tooltip="<?= e($ln['agent']['name'] ?? '') ?>"><?= e($ln['agent']['initial']) ?></span>
                                <?php elseif ($lt === 'phase'): ?>
                                    <span class="narr-bullet">▶</span>
                                <?php else: ?>
                                    <span class="narr-bullet">·</span>
                                <?php endif; ?>
                                <span class="narr-text"><?= e($ln['text'] ?? '') ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </details>
            <?php endif; ?>
            <!-- Regular Message -->
            <?php if ($isUser): ?>
                <?php /* Mensagem do usuário: texto literal, preservando as quebras de linha (sem markdown). */ ?>
                <div class="message-text"><?= nl2br(e($message->content)) ?></div>
            <?php else: ?>
                <div class="message-text markdown-body">
                    <?= parse_markdown($message->content) ?>
                </div>
            <?php endif; ?>
            
            <?php if ($message->thinking_content): ?>
                <details class="message-thinking">
                    <summary>
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10"/>
                            <path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/>
                            <point cx="12" cy="17"/>
                        </svg>
                        Ver raciocínio
                    </summary>
                    <div class="thinking-content">
                        <?= e($message->thinking_content) ?>
                    </div>
                </details>
            <?php endif; ?>
        <?php endif; ?>
        
        <!-- Message Footer -->
        <div class="message-footer">
            <span class="message-time" data-tooltip="<?= e($message->created_at) ?>">
                <?= time_ago($message->created_at) ?>
            </span>
            
            <?php if (!$isToolUse && !$isToolResult): ?>
                <div class="message-actions">
                    <button type="button" class="btn-icon btn-xs msg-copiar" data-tooltip="<?= e(t('chat.copiar', 'Copiar')) ?>" aria-label="<?= e(t('chat.copiar_mensagem', 'Copiar mensagem')) ?>">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="9" y="9" width="13" height="13" rx="2" ry="2"/>
                            <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>
                        </svg>
                    </button>
                </div>
            <?php endif; ?>
            
            <?php if ($message->tokens_used > 0): ?>
                <span class="message-tokens" data-tooltip="<?= e(t('chat.tokens_usados', 'Tokens utilizados')) ?>">
                    <?= number_format($message->tokens_used) ?> tokens
                </span>
            <?php endif; ?>
        </div>
    </div>
    
    <?php if ($isUser): ?>
        <div class="message-avatar user-avatar">
            <?php $mbAvatar = auth()->user()?->avatar_url ?? null; ?>
            <?php if ($mbAvatar): ?>
                <img class="avatar-foto" src="<?= e($mbAvatar) ?>" alt="">
            <?php else: ?>
                <?= strtoupper(substr(auth()->user()?->name ?? 'U', 0, 1)) ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
