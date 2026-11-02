/**
 * Ravi Developer - Chat Client
 * Gerenciamento do chat com WebSocket para streaming em tempo real
 */

class RaviChat {
    constructor(options = {}) {
        this.wsUrl = options.wsUrl || `ws://${window.location.hostname}:8081`;
        this.conversationUuid = options.conversationUuid || null;
        this.projectUuid = options.projectUuid || null;
        this.conversationStatus = options.conversationStatus || '';
        // Estado de entrega (vindo do servidor): controla o card de entrega in-thread.
        this._delivered = !!options.delivered;
        this._previewable = options.previewable !== false;
        this.currentZip = options.zip || null;
        this._demo = options.demo || null; // credenciais de demonstração (app.json) p/ o card de entrega

        this.ws = null;
        this.reconnectAttempts = 0;
        this.maxReconnectAttempts = 30;   // tolera restarts do servidor sem precisar recarregar a página
        this.reconnectDelay = 1000;
        this.maxReconnectDelay = 5000;    // teto do backoff
        this.pingInterval = null;
        this.isConnected = false;
        this.messageQueue = [];
        
        // DOM Elements (IDs conforme views/chat/index.php)
        this.messagesContainer = document.getElementById('chat-messages');
        this.messageInput = document.getElementById('message-input');
        this.sendButton = document.getElementById('send-message');
        this.statusIndicator = document.getElementById('chat-status');
        this.typingIndicator = document.getElementById('typing-indicator');
        
        // Callbacks
        this.onMessage = options.onMessage || (() => {});
        this.onToolExecution = options.onToolExecution || (() => {});
        this.onStatusChange = options.onStatusChange || (() => {});
        this.onError = options.onError || (() => {});
        this.onFileCreated = options.onFileCreated || (() => {});
        this.onTaskComplete = options.onTaskComplete || (() => {});
        
        // State
        this.currentAssistantMessage = null;
        this.currentToolExecution = null;
        this.isProcessing = false;
        this.pendingAttachments = [];
        this.agentRows = new Map(); // id do job → linha do agente na fila do chat
        const _cc = document.querySelector('.chat-container');
        this.userInitial = (_cc && _cc.dataset.userInitial) ? _cc.dataset.userInitial : '';
        this.userAvatar = (_cc && _cc.dataset.userAvatar) ? _cc.dataset.userAvatar : '';
        this.workSeconds = (window.CHAT_CONFIG && CHAT_CONFIG.workSeconds) ? CHAT_CONFIG.workSeconds : 0;

        this.init();
    }

    init() {
        this.bindEvents();
        this.connect();
        // "Grudar no fim": só rola sozinho se o usuário JÁ está perto do fim. Se ele rolou pra
        // cima para ler, paramos de puxar (evita o chat pular com muitos agentes postando).
        this._stick = true;
        if (this.messagesContainer) {
            this.messagesContainer.addEventListener('scroll', () => { this._stick = this.isAtBottom(); });
            // Quando o Claude cita um arquivo (link markdown p/ caminho relativo), clicar abria uma
            // ABA NOVA quebrada (href relativo → 404). Interceptamos e abrimos no modal de arquivo.
            this.messagesContainer.addEventListener('click', (e) => {
                const a = e.target.closest('a[href]');
                if (!a) return;
                const raw = (a.getAttribute('href') || '').trim();
                if (!raw) return;
                // Deixa links externos/app/âncoras seguirem normalmente.
                if (/^(https?:|mailto:|tel:|data:|\/\/|\/|#)/i.test(raw)) return;
                // Trata como arquivo do projeto se parece um caminho (tem '/' ou extensão de código/texto).
                const fileExt = /\.(php|js|ts|jsx|tsx|css|scss|sass|less|html?|json|md|markdown|txt|py|go|rs|rb|java|kt|c|cpp|cc|h|hpp|sql|ya?ml|env|sh|bash|vue|svelte|xml|ini|conf|cfg|toml|lock|csv|dockerfile|gitignore)$/i;
                if (!raw.includes('/') && !fileExt.test(raw)) return;
                e.preventDefault();
                const path = raw.replace(/^\.\//, '').replace(/^\/+/, '');
                this.openFileModal(path);
            });
        }
        // Se a conversa está EM EXECUÇÃO ao carregar a página, trava o campo de envio (não dá
        // pra mandar comando no meio do build) e restaura a narração em andamento.
        if (this.conversationStatus === 'running') {
            this.setProcessing(true);
            this.loadLiveNarration();
        }
        // Hidrata painel de arquivos + card de entrega/preview + card de aprovação JÁ no load,
        // sem depender do WebSocket (era isso que sumia no refresh / ao reabrir o navegador).
        this.loadWorkspaceState();
        this._consumePendingMessage();
    }

    // Restaura a narração (passos/fala dos agentes) de uma tarefa AINDA em execução, que o worker
    // persiste em tempo real — senão, ao recarregar no meio do build, o histórico vinha vazio.
    loadLiveNarration() {
        if (!this.projectUuid || !this.conversationUuid) return;
        fetch('/chat/' + this.projectUuid + '/live?c=' + encodeURIComponent(this.conversationUuid),
            { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
            .then(r => r.ok ? r.json() : null)
            .then(resp => {
                const d = (resp && resp.data) ? resp.data : resp;
                if (!d || !d.running || !Array.isArray(d.narration)) return;
                this.hideWelcome();
                d.narration.forEach(line => {
                    if (line.type === 'agent_text') this.appendAgentText(line.text, line.agent);
                    else this.appendStepLine(line.text, line.agent);
                });
                this.scrollToBottom();
            }).catch(() => {});
    }

    bindEvents() {
        // Send message on button click
        if (this.sendButton) {
            this.sendButton.addEventListener('click', () => this.sendUserMessage());
        }

        // Menu lateral: o ESTADO inicial (recolhido/aberto) já vem do SERVIDOR via cookie
        // (evita o "piscar" ao recarregar/criar conversa). Aqui o botão só alterna e troca o
        // rótulo; a classe vai no CONTAINER (grid) para a coluna encolher e o conteúdo ocupar.
        const sbToggle = document.getElementById('chat-sidebar-toggle');
        const container = document.querySelector('.chat-container');
        const syncSbLabel = () => {
            if (!sbToggle || !container) return;
            const collapsed = container.classList.contains('sidebar-collapsed');
            const rotuloSb = collapsed ? 'Abrir menu' : 'Recolher menu';
            sbToggle.setAttribute('data-tooltip', rotuloSb);
            sbToggle.setAttribute('aria-label', rotuloSb);
        };
        syncSbLabel();
        if (sbToggle && container) {
            sbToggle.addEventListener('click', () => {
                container.classList.toggle('sidebar-collapsed');
                const collapsed = container.classList.contains('sidebar-collapsed');
                document.cookie = 'ravi_sidebar=' + (collapsed ? 'mini' : 'open') + ';path=/;max-age=31536000;samesite=lax';
                syncSbLabel();
            });
        }

        // Anexo de arquivos no chat (sobe para o workspace do projeto, em uploads/).
        const attachBtn = document.getElementById('attach-file');
        if (attachBtn) {
            this._attachInput = document.createElement('input');
            this._attachInput.type = 'file';
            this._attachInput.multiple = true;
            this._attachInput.style.display = 'none';
            document.body.appendChild(this._attachInput);
            attachBtn.addEventListener('click', () => this._attachInput.click());
            this._attachInput.addEventListener('change', () => {
                Array.from(this._attachInput.files || []).forEach((f) => this.attachFile(f));
                this._attachInput.value = '';
            });
        }

        // Botão Parar (interrompe a tarefa em execução)
        const cancelBtn = document.getElementById('cancel-task');
        if (cancelBtn) {
            cancelBtn.addEventListener('click', () => {
                this.cancelTask();
                this.appendSystemLine('Interrompendo…');
            });
        }

        // Send message on Enter (Shift+Enter for newline)
        if (this.messageInput) {
            this.messageInput.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    this.sendUserMessage();
                }
            });

            // Auto-resize textarea
            this.messageInput.addEventListener('input', () => {
                this.messageInput.style.height = 'auto';
                this.messageInput.style.height = Math.min(this.messageInput.scrollHeight, 200) + 'px';
            });
        }

        // Handle page visibility for reconnection
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible' && !this.isConnected) {
                this.connect();
            }
        });
    }

    // ==================== WebSocket Connection ====================

    connect() {
        if (this.ws && this.ws.readyState === WebSocket.OPEN) {
            return;
        }

        try {
            this.ws = new WebSocket(this.wsUrl);
            
            this.ws.onopen = () => this.handleOpen();
            this.ws.onmessage = (e) => this.handleMessage(e);
            this.ws.onclose = (e) => this.handleClose(e);
            this.ws.onerror = (e) => this.handleError(e);
        } catch (error) {
            console.error('WebSocket connection error:', error);
            this.scheduleReconnect();
        }
    }

    handleOpen() {
        console.log('WebSocket connected');
        this.isConnected = true;
        this.reconnectAttempts = 0;
        this.updateStatus('online');
        
        // Authenticate using session token
        this.send({
            type: 'auth',
            ws_token: this.getSessionToken()
        });

        // Start ping interval
        this.pingInterval = setInterval(() => {
            if (this.isConnected) {
                this.send({ type: 'ping' });
            }
        }, 30000);

        // Process queued messages
        while (this.messageQueue.length > 0) {
            const msg = this.messageQueue.shift();
            this.send(msg);
        }
    }

    handleMessage(event) {
        try {
            const data = JSON.parse(event.data);
            this.processMessage(data);
        } catch (error) {
            console.error('Error parsing message:', error);
        }
    }

    handleClose(event) {
        console.log('WebSocket closed:', event.code, event.reason);
        this.isConnected = false;
        this.updateStatus('offline');
        
        if (this.pingInterval) {
            clearInterval(this.pingInterval);
            this.pingInterval = null;
        }

        // Reconnect if not intentional close
        if (event.code !== 1000) {
            this.scheduleReconnect();
        }
    }

    handleError(error) {
        console.error('WebSocket error:', error);
        this.onError(error);
    }

    scheduleReconnect() {
        if (this.reconnectAttempts >= this.maxReconnectAttempts) {
            console.log('Max reconnection attempts reached');
            this.updateStatus('error');
            return;
        }

        this.reconnectAttempts++;
        const delay = Math.min(this.reconnectDelay * Math.pow(2, this.reconnectAttempts - 1), this.maxReconnectDelay);

        console.log(`Reconnecting in ${delay}ms (attempt ${this.reconnectAttempts})`);
        this.updateStatus('reconnecting');
        
        setTimeout(() => this.connect(), delay);
    }

    send(data) {
        if (this.ws && this.ws.readyState === WebSocket.OPEN) {
            this.ws.send(JSON.stringify(data));
        } else {
            this.messageQueue.push(data);
        }
    }

    disconnect() {
        if (this.ws) {
            this.ws.close(1000, 'User disconnect');
        }
    }

    getSessionToken() {
        // Token do WebSocket emitido pela página (meta ws-token); nunca o cookie de sessão.
        return document.querySelector('meta[name="ws-token"]')?.content || '';
    }

    // ==================== Message Processing ====================

    processMessage(data) {
        switch (data.type) {
            case 'auth_success':
                console.log('Authenticated successfully');
                if (this.conversationUuid) {
                    this.joinConversation(this.conversationUuid);
                }
                break;

            case 'auth_error':
                console.error('Authentication failed:', data.message);
                this.onError(new Error(data.message));
                break;

            case 'conversation_joined':
                console.log('Joined conversation:', data.conversation_uuid);
                if (data.messages) {
                    this.loadMessages(data.messages);
                }
                this.loadWorkspaceState();
                break;

            case 'message_history':
                this.loadMessages(data.messages);
                this.loadWorkspaceState();
                break;

            case 'user_message':
                this.appendUserMessage(data);
                break;

            case 'assistant_start':
                this.startAssistantMessage(data);
                break;

            case 'assistant_chunk':
                this.appendAssistantChunk(data);
                break;

            case 'assistant_end':
                this.endAssistantMessage(data);
                break;

            case 'tool_start':
                this.startToolExecution(data);
                break;

            case 'tool_progress':
                this.updateToolProgress(data);
                break;

            case 'tool_result':
                this.endToolExecution(data);
                break;

            case 'file_created':
                this.handleFileCreated(data);
                break;

            case 'task_complete':
                this.handleTaskComplete(data);
                break;

            case 'task_error':
                this.handleTaskError(data);
                break;

            case 'status_update':
                this.updateConversationStatus(data);
                break;

            case 'pong':
                // Keep-alive response
                break;

            case 'error':
                this.setProcessing(false);
                this.markChatIdle();
                this.handleServerError(data);
                break;

            // ===== Protocolo real do servidor/agente Ravi =====
            case 'connected':
                break;
            case 'authenticated':
                this.updateStatus('online');
                if (this.conversationUuid) this.joinConversation(this.conversationUuid);
                break;
            case 'joined_conversation':
                if (data.messages) this.loadMessages(data.messages);
                this.loadWorkspaceState();
                break;
            case 'message_received':
                this.setProcessing(true);
                break; // a mensagem do usuário já foi exibida localmente
            case 'agent_mode_info':
                break; // redundante: a linha "Opus 4.8 trabalhando" já mostra que está rodando
            case 'status':
            case 'log':
                // Mensagem geral de status → alimenta a atividade da linha ativa (ou da "pensando").
                this.ensureThinkingRow(data.message || data.text || 'pensando…');
                this.routeActivity(data, data.message || data.text || '');
                break;
            case 'agent_model':
                this.appendSystemLine('🧠 ' + this.cap(data.agent || 'agente') + ' usando ' + (data.model || ''));
                break;
            case 'agent_iteration':
            case 'iteration':
                break; // silencioso (ruído)
            case 'phase':
                this.routeActivity(data, (data.status || data.phase || ''));
                this.refreshFiles();
                break;
            case 'agents_reset':
                // Início/retomada de um run: zera o painel de agentes ao vivo para não deixar
                // cards ÓRFÃOS de um run anterior (ex.: worker reiniciou e os agentes nunca
                // emitiram agent_done). Os agentes desta rodada repovoam a barra do zero.
                this.clearAgentsPanel();
                break;
            case 'agent_start':
                this.agentStarted(data.id, data.agent);
                break;
            case 'agent_done':
                this.agentFinished(data.id);
                break;
            case 'orchestrator_start':
                break;
            case 'step_start':
                break;
            case 'agent_text':
            case 'text':
            case 'content':
                if (data.persist) {
                    // Fala do ARQUITETO para o USUÁRIO (plano/onda/entrega/resposta) → mensagem permanente.
                    this.appendAgentText(data.text || data.content, data.agent);
                    this.keepRailLast();
                } else {
                    // Narração interna → vai para a LINHA DO PRÓPRIO ESPECIALISTA (uma linha por
                    // especialista trabalhando), atualizando e sumindo. Não vira mensagem permanente.
                    this.routeAgentNarration(data, data.text || data.content || '');
                }
                break;
            case 'agent_text_delta':
            case 'text_delta':
                this.appendAgentDelta(data.text || data.content || '');
                break;
            case 'agent_tool_start':
            case 'tool_use':
                // Mostra a ferramenta como atividade do agente (no lugar, sem poluir o chat).
                this.routeActivity(data, this.toolActivityLabel(data.tool || data.tool_name || '', data.input));
                break;
            case 'agent_tool_result':
                break; // resultado de ferramenta não vira linha no chat (a atividade já mostrou)
            case 'agent_error':
                this.agentFinished(data.id);
                this.appendSystemLine(data.error || data.message || '');
                break;
            case 'step':
                this.routeActivity(data, data.text || '');
                // Atualiza o painel de arquivos quando o passo cria ou edita um arquivo.
                if (/^(Criou|Editou)/.test(data.text || '')) this.refreshFiles();
                break;
            case 'clarify_questions':
                this.renderClarifyCard(data);
                break;
            case 'plan_ready':
                this.renderPlanCard(data);
                this.refreshFiles();
                break;
            case 'preview_available':
                this._delivered = true;
                this._previewable = true;
                this.ensureDeliveryBanner();
                break;
            case 'screencast_start':
                this.showScreencastCard(data && data.label);
                break;
            case 'screencast_stop':
                this.hideScreencastCard(true); // mantém o último frame por alguns segundos
                break;
            case 'work_time':
                if (typeof data.seconds === 'number') this.workSeconds = data.seconds;
                this.renderWorkTotal();
                break;
            case 'usage':
                this.updateCost(data);
                break;
            case 'usage_live':
                this.updateLiveCost(data);
                break;
            case 'title':
                this.updateTitle(data.title);
                break;
            case 'task_completed':
                this.setProcessing(false);
                this.markChatIdle();
                this.currentAssistantMessage = null;
                if (data.result && data.result.cancelled) {
                    this.appendSystemLine(data.message || 'Tarefa interrompida.');
                } else if (data.result && data.result.conversational) {
                    // Resposta de CONVERSA (saudacao/pergunta): sem entrega, sem banner, sem 'Tarefa concluida'.
                } else {
                    this._delivered = true;          // entrega real → libera o banner
                    this.refreshFiles(true);
                    // Se a tarja existia antes (projeto já entregue), recria-a ao concluir — cobre
                    // tarefas rápidas que não reemitem preview_available. Idempotente.
                    if (this._hadDeliveryBanner) this.ensureDeliveryBanner();
                    this.appendSystemLine('Tarefa concluída');
                }
                break;

            case 'git_synced':
                this.appendSystemLine('Sincronizando com o GitHub' + (data.repo ? ' (' + data.repo + ')' : '') + '…');
                break;
            case 'git_pending':
                this.appendSystemLine('Mudanças pendentes de sincronizar com o GitHub' + (data.repo ? ' (' + data.repo + ')' : '') + ' — sincronize na página do projeto.');
                break;

            case 'server_deploying':
                this.appendSystemLine('Sincronizando no servidor' + (data.server ? ' ' + data.server : '') + '…');
                break;
            case 'server_deployed':
                this.appendSystemLine('Sincronizado no servidor' + (data.server ? ' ' + data.server : '') + '.');
                break;
            case 'server_deploy_failed':
                this.appendSystemLine('Falha ao sincronizar no servidor' + (data.server ? ' ' + data.server : '') + (data.error ? ' — ' + data.error : '') + '.');
                break;

            default:
                console.log('Unknown message type:', data.type);
        }
    }

    // Atualiza o título da conversa (gerado pela IA) no header, na aba e na sidebar.
    updateTitle(title) {
        title = (title || '').trim();
        if (!title) return;
        var h = document.getElementById('chat-title');
        if (h) h.textContent = title;
        try { document.title = title + ' — Ravi Developer'; } catch (e) {}
        var convId = (this.conversationId || (document.querySelector('[data-conversation-id]') || {}).getAttribute && document.querySelector('[data-conversation-id]').getAttribute('data-conversation-id'));
        if (convId) {
            var item = document.querySelector('.conversation-item[data-id="' + convId + '"]');
            if (item) {
                var t = item.querySelector('.conversation-title, h3, .conv-title') || item;
                if (t) t.textContent = title;
            }
        }
    }

    // Atualiza o chip de custo estimado da conversa no header (evento 'usage').
    updateCost(data) {
        const el = document.getElementById('chat-cost');
        if (!el || !data || typeof data.cost !== 'number') return;
        let label;
        if (data.cost <= 0)        label = '$0.00';
        else if (data.cost < 0.01) label = '<$0.01';
        else                       label = '$' + data.cost.toFixed(2);
        el.textContent = label;
        el.style.display = (data.cost > 0) ? '' : 'none';
        el.setAttribute('data-tooltip', 'Custo estimado desta conversa: ' + label);
    }

    // Custo EM TEMPO REAL durante o build: soma o custo-base da conversa (já gravado) ao custo
    // acumulado do job atual que o worker emite a cada rodada.
    updateLiveCost(data) {
        const el = document.getElementById('chat-cost');
        if (!el || !data) return;
        const base = parseFloat(el.dataset.base || '0') || 0;
        const job = (typeof data.job_cost === 'number') ? data.job_cost : 0;
        this.updateCost({ cost: base + job });
    }

    // Marca a conversa ATUAL como "Trabalhando" na lista lateral (badge pulsante) — quando o
    // build começa ou é retomado. Sincroniza ao vivo (antes só vinha do servidor no load).
    // Tempo de trabalho do Dev: contador AO VIVO no topo enquanto processa (estilo IDE).
    _fmtDur(s) {
        s = Math.max(0, Math.round(s));
        if (s < 60) return s + 's';
        var m = Math.floor(s / 60), ss = s % 60;
        if (s < 3600) return m + 'min' + (ss ? (' ' + ss + 's') : '');
        return Math.floor(s / 3600) + 'h ' + Math.floor((s % 3600) / 60) + 'min';
    }
    startWorkTimer() {
        this.stopWorkTimer();
        var el = document.getElementById('chat-worktime');
        if (!el) return;
        var base = this.workSeconds || 0; // CONTINUA do total acumulado, nao do zero
        this._wt0 = Date.now();
        var self = this;
        var tick = function () {
            var s = base + Math.floor((Date.now() - self._wt0) / 1000);
            el.textContent = self._fmtDur(s); // so o tempo (o status-text ja diz 'Trabalhando')
            el.style.display = '';
        };
        tick();
        this._wtTimer = setInterval(tick, 1000);
    }
    stopWorkTimer() { if (this._wtTimer) { clearInterval(this._wtTimer); this._wtTimer = null; } }
    _fmtHMS(s) {
        s = Math.max(0, Math.floor(s));
        var h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), x = s % 60;
        function p(n) { return (n < 10 ? '0' : '') + n; }
        return (h > 0 ? (h + ':') : '') + p(m) + ':' + p(x);
    }
    renderWorkTotal() {
        var wrap = document.getElementById('chat-worktime');
        var el = document.getElementById('chat-worktime-val');
        if (wrap) wrap.style.display = ''; // nunca some
        if (el) el.textContent = this._fmtHMS(this.workSeconds || 0);
    }

    markChatWorking() {
        const item = document.querySelector('.conversation-item.active');
        if (item) {
            item.classList.add('is-working');
            // Só acende o ponto verde pulsante (sem texto "Trabalhando").
            const cdot = item.querySelector('.conversation-status');
            if (cdot) cdot.className = 'conversation-status status-running';
        }
        const stxt = document.querySelector('.chat-header .status-text');
        if (stxt) stxt.textContent = 'Trabalhando';
    }

    // Tira o estado "Trabalhando" (parou / aguardando aprovação / concluído) e libera o campo.
    markChatIdle() {
        const item = document.querySelector('.conversation-item.active');
        if (item) {
            item.classList.remove('is-working');
            // Apaga o ponto verde (parou de trabalhar → some).
            const cdot = item.querySelector('.conversation-status');
            if (cdot) cdot.className = 'conversation-status status-active';
        }
        const stxt = document.querySelector('.chat-header .status-text');
        if (stxt) stxt.textContent = 'Parado';
    }

    // Aguardando VOCE (ex.: plano aguardando aprovacao): lateral azul, topo texto 'Aguardando'.
    markChatWaiting() {
        const item = document.querySelector('.conversation-item.active');
        if (item) {
            item.classList.remove('is-working');
            const cdot = item.querySelector('.conversation-status');
            if (cdot) cdot.className = 'conversation-status status-waiting';
        }
        const stxt = document.querySelector('.chat-header .status-text');
        if (stxt) stxt.textContent = 'Aguardando';
    }

    // ==================== Conversation Management ====================

    joinConversation(uuid) {
        this.conversationUuid = uuid;
        this.send({
            type: 'join_conversation',
            conversation_uuid: uuid
        });
    }

    leaveConversation() {
        if (this.conversationUuid) {
            this.send({
                type: 'leave_conversation',
                conversation_uuid: this.conversationUuid
            });
            this.conversationUuid = null;
        }
    }

    // ==================== Anexos ====================
    attachFile(file) {
        if (!file || !this.projectUuid) return;
        const token = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
        const fd = new FormData();
        fd.append('file', file);
        fd.append('_token', token);
        this._renderAttachChip(file.name, 'busy');
        fetch('/chat/' + this.projectUuid + '/attach', {
            method: 'POST',
            headers: { 'X-CSRF-Token': token, 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            body: fd
        }).then(r => r.json()).then(resp => {
            const d = (resp && resp.data) ? resp.data : resp;
            if (d && d.ok) {
                this.pendingAttachments.push({ name: d.name, path: d.path });
                this._renderAttachChip(file.name, 'ok', d.name);
            } else {
                this._removeAttachChip(file.name);
                window.D2UI.toast((d && d.error) || 'Falha ao anexar.', { tipo: 'erro' });
            }
        }).catch(() => { this._removeAttachChip(file.name); window.D2UI.toast('Erro de rede no anexo.', { tipo: 'erro' }); });
    }

    _attachChipsEl() { return document.getElementById('attach-chips'); }
    _shortName(name, max) {
        max = max || 24; name = String(name || '');
        if (name.length <= max) return name;
        var dot = name.lastIndexOf('.');
        var ext = (dot > 0 && (name.length - dot) <= 8) ? name.slice(dot) : '';
        var base = ext ? name.slice(0, name.length - ext.length) : name;
        var keep = Math.max(6, max - ext.length - 1);
        return base.slice(0, keep) + '…' + ext;
    }
    _renderAttachChip(tmpName, state, finalName) {
        const box = this._attachChipsEl();
        if (!box) return;
        const key = finalName || tmpName;
        let chip = box.querySelector('[data-chip="' + CSS.escape(tmpName) + '"]');
        if (!chip) {
            chip = document.createElement('span');
            chip.className = 'attach-chip';
            chip.setAttribute('data-chip', tmpName);
            box.appendChild(chip);
        }
        chip.setAttribute('data-name', key);
        chip.classList.toggle('busy', state === 'busy');
        if (state === 'busy') {
            chip.setAttribute('title', tmpName);
            chip.innerHTML = '<span class="ac-spin"></span>' + this._esc(this._shortName(tmpName));
        } else {
            chip.setAttribute('title', key);
            chip.innerHTML = '📎 ' + this._esc(this._shortName(key)) + ' <button type="button" class="ac-x" data-tooltip="Remover" aria-label="Remover">✕</button>';
            chip.querySelector('.ac-x').addEventListener('click', () => {
                this.pendingAttachments = this.pendingAttachments.filter(a => a.name !== key);
                chip.remove();
            });
        }
    }
    _removeAttachChip(tmpName) {
        const box = this._attachChipsEl();
        const chip = box && box.querySelector('[data-chip="' + CSS.escape(tmpName) + '"]');
        if (chip) chip.remove();
    }
    _esc(s) { return String(s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c])); }

    _createFirstConversationAndSend(content) {
        if (!this.projectUuid || !window.api) { window.D2UI.toast('Não foi possível criar a conversa.', { tipo: 'erro' }); return; }
        if (this.sendButton) this.sendButton.disabled = true;
        if (this.messageInput) this.messageInput.disabled = true;
        api.createConversation(this.projectUuid, { title: 'Nova conversa' }).then((resp) => {
            var c = (resp && resp.conversation) ? resp.conversation : ((resp && resp.data) ? resp.data : resp);
            var uuid = c && (c.uuid || c.conversation_uuid || c.id);
            if (!uuid) throw new Error('sem uuid');
            try { sessionStorage.setItem('ravi_pending_msg_' + uuid, content || ''); } catch (e) {}
            window.location.href = '/chat/' + this.projectUuid + '/' + uuid;
        }).catch(() => {
            window.D2UI.toast('Erro ao criar a conversa.', { tipo: 'erro' });
            if (this.sendButton) this.sendButton.disabled = false;
            if (this.messageInput) this.messageInput.disabled = false;
        });
    }
    _consumePendingMessage() {
        if (!this.conversationUuid) return;
        var pending = null;
        try { pending = sessionStorage.getItem('ravi_pending_msg_' + this.conversationUuid); } catch (e) {}
        if (pending === null) return;
        try { sessionStorage.removeItem('ravi_pending_msg_' + this.conversationUuid); } catch (e) {}
        if (!pending) return;
        if (this.messageInput) this.messageInput.value = pending;
        var self = this, tries = 0;
        var t = setInterval(function () {
            tries++;
            if (self.ws && self.ws.readyState === 1 && !self.isProcessing && tries >= 3) {
                clearInterval(t);
                self.sendUserMessage();
            } else if (tries > 60) { clearInterval(t); }
        }, 250);
    }

    sendUserMessage() {
        let content = this.messageInput?.value?.trim();
        if ((!content && !this.pendingAttachments.length) || this.isProcessing) return;

        if (!this.conversationUuid) {
            // Primeira conversa do projeto: cria agora e reabre ja com ela, enviando esta mensagem.
            this._createFirstConversationAndSend(content || '');
            return;
        }

        // Inclui os anexos enviados (o agente os encontra em uploads/ no workspace).
        // displayContent = o que o usuário vê (limpo); content = o que vai para o agente (com a nota).
        let displayContent = content || '';
        if (this.pendingAttachments.length) {
            const list = this.pendingAttachments.map(a => '- ' + a.path).join('\n');
            const names = this.pendingAttachments.map(a => a.name).join(', ');
            content = (content || 'Veja os arquivos que anexei.')
                + '\n\n[Arquivos anexados pelo usuário, disponíveis no diretório uploads/ do projeto:\n' + list + '\n]';
            displayContent = (displayContent ? displayContent + '\n\n' : '') + '📎 ' + names;
            this.pendingAttachments = [];
            const box = this._attachChipsEl(); if (box) box.innerHTML = '';
        }

        // Clear input
        this.messageInput.value = '';
        this.messageInput.style.height = 'auto';

        // Send via WebSocket
        this.send({
            type: 'send_message',
            conversation_uuid: this.conversationUuid,
            content: content
        });

        // Optimistically add message to UI
        this.appendUserMessage({
            content: displayContent,
            created_at: new Date().toISOString()
        });

        this.setProcessing(true);
    }

    cancelTask() {
        if (this.conversationUuid) {
            this.send({
                type: 'cancel_task',
                conversation_uuid: this.conversationUuid
            });
        }
    }

    // ==================== UI Updates ====================

    loadMessages(messages) {
        if (!this.messagesContainer) return;

        messages = Array.isArray(messages) ? messages : [];
        // Conversa vazia: NÃO limpa o container (preserva a tela de boas-vindas).
        if (messages.length === 0) {
            return;
        }

        this.hideWelcome();
        // Remove apenas as bolhas de mensagem já renganizadas (preserva welcome/typing).
        this.messagesContainer.querySelectorAll('.message, .tool-execution, .agent-step, .plan-approval-card, .delivery-card, .delivery-unified, .task-complete, .task-error').forEach(el => el.remove());

        messages.forEach(msg => {
            switch (msg.role) {
                case 'user':
                    this.appendUserMessage(msg, false);
                    break;
                case 'assistant':
                    this.appendAssistantMessage(msg, false);
                    break;
                case 'tool_use':
                case 'tool_result':
                    this.appendToolMessage(msg, false);
                    break;
            }
        });

        // Card de entrega (renderizado no servidor): mantém no FIM do thread após re-renderizar as
        // mensagens, e wira/popula credenciais. Se um build estiver em curso, esconde.
        const dh = document.getElementById('delivery-banner');
        if (dh) {
            if (this.isProcessing) { this.removeDeliveryBanner(); }
            else { this.messagesContainer.appendChild(dh); this.wireDeliveryBanner(); }
        }

        this.scrollToBottom();
    }

    appendUserMessage(data, scroll = true) {
        if (!this.messagesContainer) return;
        this.hideWelcome();

        const messageEl = document.createElement('div');
        messageEl.className = 'message message-user';
        const userAvatar = this.userAvatar
            ? `<img src="${this.escapeHtml(this.userAvatar)}" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:inherit;">`
            : (this.userInitial
                ? `<span class="avatar-mono user-mono">${this.escapeHtml(this.userInitial)}</span>`
                : '<span class="avatar-icon">👤</span>');
        messageEl.innerHTML = `
            <div class="message-avatar">
                ${userAvatar}
            </div>
            <div class="message-content">
                <div class="message-text">${this.escapeHtml(data.content).replace(/\n/g, '<br>')}</div>
                <div class="message-time">${this.formatTime(data.created_at)}</div>
            </div>
        `;

        this.placeInChat(messageEl);
        if (scroll) this.scrollToBottom(true); // mensagem do usuário: sempre desce
    }

    // Cor segura (#hex) a partir da identidade do agente.
    safeColor(c) {
        return (typeof c === 'string' && /^#[0-9a-fA-F]{3,8}$/.test(c)) ? c : 'var(--d2-brand)';
    }

    // ==================== Painel de agentes ao vivo (estilo Manus) ====================

    // Indicador "Claude pensando": glifo que se transforma (·→✢→✳→✶→✻→✽→…) em loop.
    // Usa currentColor (preto no tema claro, branco no escuro). Cadência suave (1.4s).
    thinkingSVG(size = 20) {
        const frames = [
            { g: '·', v: '1;0;0',   kt: '0;0.1;1' },
            { g: '✢', v: '0;1;0;0', kt: '0;0.1;0.2;1' },
            { g: '✳', v: '0;1;0;0', kt: '0;0.2;0.3;1' },
            { g: '✶', v: '0;1;0;0', kt: '0;0.3;0.4;1' },
            { g: '✻', v: '0;1;0;0', kt: '0;0.4;0.5;1' },
            { g: '✽', v: '0;1;0;0', kt: '0;0.5;0.6;1' },
            { g: '✻', v: '0;1;0;0', kt: '0;0.6;0.7;1' },
            { g: '✶', v: '0;1;0;0', kt: '0;0.7;0.8;1' },
            { g: '✳', v: '0;1;0;0', kt: '0;0.8;0.9;1' },
            { g: '✢', v: '0;1;0',   kt: '0;0.9;1' },
        ];
        const dur = '1.4s';
        const texts = frames.map(f =>
            `<text x="32" y="32" text-anchor="middle" dominant-baseline="central" font-size="44" opacity="0">${f.g}`
            + `<animate attributeName="opacity" calcMode="discrete" values="${f.v}" keyTimes="${f.kt}" dur="${dur}" repeatCount="indefinite"/></text>`
        ).join('');
        return `<svg class="ravi-think" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" width="${size}" height="${size}" fill="currentColor" font-family="'Apple Symbols','Segoe UI Symbol','Noto Sans Symbols2',sans-serif" aria-hidden="true">${texts}</svg>`;
    }

    // "Fagulha" do Claude: raios radiais que pulsam e giram suavemente (indicador de
    // processamento). Gerada em SVG para ficar nítida e leve. `color` tinge os raios.
    sparkSVG(size = 18, color = 'currentColor') {
        const cx = 12, cy = 12, rays = 12;
        let petals = '';
        for (let i = 0; i < rays; i++) {
            const ang = (i * 360 / rays);
            const long = (i % 2 === 0);
            const len = long ? 7.0 : 4.4;   // comprimento do raio
            const w = long ? 1.7 : 1.25;    // largura do raio
            const y1 = (cy - len);
            const h = (len - 2.0);          // do raio interno (2px) até a ponta
            petals += `<rect x="${(cx - w / 2).toFixed(2)}" y="${y1.toFixed(2)}" width="${w}" height="${h.toFixed(2)}" rx="${(w / 2).toFixed(2)} " transform="rotate(${ang} ${cx} ${cy})"/>`;
        }
        return `<svg class="ravi-spark" viewBox="0 0 24 24" width="${size}" height="${size}" aria-hidden="true" style="color:${this.safeColor(color)}"><g fill="currentColor">${petals}</g></svg>`;
    }

    // Garante a "fila de agentes" DENTRO do chat (no fim das mensagens). Cada agente vira uma
    // linha; some quando termina. Fica sempre por último (abaixo das mensagens já postadas).
    ensureAgentsRail() {
        if (!this.messagesContainer) return null;
        if (!this.agentsRailEl || !this.agentsRailEl.isConnected) {
            this.agentsRailEl = document.createElement('div');
            this.agentsRailEl.className = 'agents-rail';
        }
        // Só move pro fim se ainda NÃO estiver lá (mover sem necessidade causa o "pulo").
        if (this.messagesContainer.lastElementChild !== this.agentsRailEl) {
            this.messagesContainer.appendChild(this.agentsRailEl);
        }
        return this.agentsRailEl;
    }

    // Mantém a fila por último SEM movê-la à toa (as mensagens já entram antes dela via
    // placeInChat). Só reposiciona se de fato não estiver no fim.
    keepRailLast() {
        if (this.agentsRailEl && this.agentsRailEl.parentNode === this.messagesContainer
            && this.messagesContainer.lastElementChild !== this.agentsRailEl) {
            this.messagesContainer.appendChild(this.agentsRailEl);
        }
    }

    // Cria uma linha na fila do chat (uso interno: agentes reais e a linha "Opus 4.8 pensando").
    addAgentRow(id, agent, activity) {
        id = String(id);
        if (!this.agentRows) this.agentRows = new Map();
        this.hideWelcome();
        this.showTyping(false);
        const rail = this.ensureAgentsRail();
        if (!rail) return;
        if (this.agentRows.has(id)) { if (activity) this.setAgentActivity(id, activity); return; }
        const color = this.safeColor(agent.color);
        const esc = (s) => String(s == null ? '' : s).replace(/[<>&"]/g, c => ({ '<': '&lt;', '>': '&gt;', '&': '&amp;', '"': '&quot;' }[c]));
        const row = document.createElement('div');
        row.className = 'agent-row';
        row.dataset.id = id;
        row.style.setProperty('--ag', color);
        row.innerHTML =
            `<span class="agent-row__spark">${this.thinkingSVG(19)}</span>`
            + `<span class="agent-row__name">${esc(agent.name || 'Agente')}</span>`
            + `<span class="agent-row__activity">${esc(activity || 'trabalhando…')}</span>`
            + `<span class="agent-row__time">0s</span>`;
        rail.appendChild(row);
        this.agentRows.set(id, { el: row, agent, startedAt: Date.now() });
        this.startAgentTick();
        this.scrollToBottom();
    }

    // Quantos agentes REAIS (fora a linha "pensando") estão na fila.
    realRowCount() {
        if (!this.agentRows) return 0;
        let n = 0;
        for (const k of this.agentRows.keys()) if (k !== '__think__') n++;
        return n;
    }

    // Linha fixa "Opus 4.8 pensando…" — aparece sempre que está processando mas nenhum agente
    // específico está ativo (ex.: Arquiteto planejando a próxima onda em silêncio). Nunca deixa a
    // tela parada e mostra visualmente que é o Opus 4.8 trabalhando.
    ensureThinkingRow(label) {
        if (!this.isProcessing) return;
        if (this.realRowCount() > 0) return;
        if (this.agentRows && this.agentRows.has('__think__')) {
            if (label) this.setAgentActivity('__think__', label);
            return;
        }
        this.addAgentRow('__think__', { name: 'Arquiteto', color: 'var(--d2-brand)' }, label || 'pensando…');
        this.keepRailLast();
    }

    removeThinkingRow() {
        if (!this.agentRows) return;
        const r = this.agentRows.get('__think__');
        if (r) { r.el.remove(); this.agentRows.delete('__think__'); }
    }

    // Um agente entrou em campo → cria a linha dele (e tira a linha genérica "pensando").
    agentStarted(id, agent) {
        if (id == null || !agent) return;
        this.removeThinkingRow();
        this.addAgentRow(id, agent, 'iniciando…');
    }

    // Atualiza, NO LUGAR (sem pular linha), o que um agente está fazendo.
    setAgentActivity(id, text) {
        if (id == null || !text || !this.agentRows) return;
        const r = this.agentRows.get(String(id));
        if (!r) return;
        const a = r.el.querySelector('.agent-row__activity');
        if (a) a.textContent = String(text).replace(/\s+/g, ' ').trim();
    }

    // Roteia uma atividade (passo/fase/ferramenta) para a linha do agente certo.
    routeActivity(data, text) {
        if (!text) return;
        let id = (data && data.id != null) ? String(data.id) : null;
        if ((id === null || !this.agentRows || !this.agentRows.has(id)) && this.agentRows) {
            // sem id válido: se há só 1 agente ativo, é dele; senão tenta casar pelo nome.
            if (this.agentRows.size === 1) {
                id = this.agentRows.keys().next().value;
            } else if (data && data.agent && data.agent.name) {
                for (const [k, v] of this.agentRows) {
                    if (v.agent && v.agent.name === data.agent.name) { id = k; break; }
                }
            }
        }
        if (id != null) this.setAgentActivity(id, text);
    }

    // Narração de um especialista → garante UMA LINHA por especialista trabalhando e atualiza a
    // atividade NA LINHA DELE (efêmera). Cada evento vem carimbado com id + identidade do agente.
    routeAgentNarration(data, text) {
        if (!text) return;
        const agent = (data && data.agent && data.agent.name) ? data.agent : null;
        const id = (data && data.id != null) ? String(data.id) : null;

        // 1) Linha desse slot já existe (criada pelo agent_start) → atualiza a atividade dela.
        if (id && this.agentRows && this.agentRows.has(id)) { this.setAgentActivity(id, text); return; }
        // 2) Sem id casável: tenta achar a linha pelo NOME do especialista.
        if (agent && this.agentRows) {
            for (const [k, v] of this.agentRows) {
                if (k !== '__think__' && v.agent && v.agent.name === agent.name) { this.setAgentActivity(k, text); return; }
            }
        }
        // 3) Ainda não há linha pra esse especialista → CRIA uma (uma por especialista trabalhando).
        if (agent) {
            this.removeThinkingRow();
            this.addAgentRow(id || ('ag-' + agent.name), agent, text);
            this.keepRailLast();
            return;
        }
        // 4) Sem agente identificável → cai no status genérico (linha do arquiteto/pensando).
        this.routeActivity(data, text);
    }

    // Um agente terminou → sai da fila. Se não sobrar nenhum agente real e ainda estamos
    // processando, mostra a linha "Opus 4.8 pensando" (não deixa a tela vazia entre as ondas).
    agentFinished(id) {
        if (id == null || !this.agentRows) return;
        const r = this.agentRows.get(String(id));
        if (r) { r.el.remove(); this.agentRows.delete(String(id)); }
        if (this.realRowCount() === 0) {
            if (this.isProcessing) {
                this.ensureThinkingRow('preparando a próxima etapa…');
                this.keepRailLast();
            } else {
                this.stopAgentTick();
                if (this.agentsRailEl) { this.agentsRailEl.remove(); }
            }
        } else {
            this.keepRailLast();
        }
    }

    clearAgentsPanel() {
        if (this.agentRows) this.agentRows.clear();
        this.stopAgentTick();
        if (this.agentsRailEl) { this.agentsRailEl.remove(); this.agentsRailEl = null; }
    }

    // Tic-tac: atualiza o tempo de cada agente a cada 1s (garante movimento mesmo em silêncio).
    startAgentTick() {
        if (this._agentTick) return;
        this._agentTick = setInterval(() => this.tickAgents(), 1000);
    }
    stopAgentTick() {
        if (this._agentTick) { clearInterval(this._agentTick); this._agentTick = null; }
    }
    tickAgents() {
        if (!this.agentRows) return;
        const now = Date.now();
        for (const r of this.agentRows.values()) {
            const t = r.el.querySelector('.agent-row__time');
            if (t) t.textContent = this.shortElapsed(now - r.startedAt);
        }
    }
    shortElapsed(ms) {
        const s = Math.max(0, Math.floor(ms / 1000));
        if (s < 60) return s + 's';
        const m = Math.floor(s / 60), r = s % 60;
        return r ? `${m}min ${r}s` : `${m}min`;
    }

    // Rótulo amigável (PT) da ferramenta em uso, para a atividade do agente.
    toolActivityLabel(tool, input) {
        const t = String(tool || '').toLowerCase();
        const map = {
            bash: 'executando comando', write: 'escrevendo arquivo', edit: 'editando arquivo',
            read: 'lendo arquivo', glob: 'procurando arquivos', grep: 'buscando no código',
            ls: 'listando arquivos', webfetch: 'consultando a web', websearch: 'pesquisando na web',
            task: 'delegando subtarefa', todowrite: 'organizando tarefas',
        };
        let label = map[t] || (t ? ('usando ' + t) : 'trabalhando');
        // Anexa o alvo quando dá (descrição do bash, ou caminho do arquivo).
        const path = input && (input.file_path || input.path || input.notebook_path);
        const desc = input && (input.description || input.pattern || input.query);
        if (path) label += ': ' + String(path).split('/').slice(-1)[0];
        else if (desc) label += ': ' + String(desc);
        return label;
    }

    // Avatar de quem fala: monograma sóbrio do especialista, ou a estrela padrão.
    avatarHtml(agent) {
        if (agent && agent.initial) {
            const initial = String(agent.initial).replace(/[<>&]/g, '').slice(0, 1).toUpperCase();
            return `<span class="avatar-mono" style="background:${this.safeColor(agent.color)}">${initial}</span>`;
        }
        return '<span class="avatar-icon">🌟</span>';
    }

    // Linha com o nome do especialista (some quando é a estrela genérica).
    agentNameHtml(agent) {
        if (agent && agent.name) {
            const name = String(agent.name).replace(/[<>&]/g, '');
            return `<div class="message-agent-name" style="color:${this.safeColor(agent.color)}">${name}</div>`;
        }
        return '';
    }

    appendAssistantMessage(data, scroll = true) {
        if (!this.messagesContainer) return;

        const messageEl = document.createElement('div');
        messageEl.className = 'message message-assistant';
        messageEl.dataset.uuid = data.uuid || '';
        messageEl.innerHTML = `
            <div class="message-avatar">
                ${this.avatarHtml(data.agent)}
            </div>
            <div class="message-content">
                ${this.agentNameHtml(data.agent)}
                <div class="message-text">${this.renderMarkdown(data.content)}</div>
                <div class="message-time">${this.formatTime(data.created_at)}</div>
            </div>
        `;

        this.placeInChat(messageEl); // insere ANTES da fila de agentes (não move a fila)
        if (scroll) this.scrollToBottom();

        // Highlight code blocks
        messageEl.querySelectorAll('pre code').forEach(block => {
            if (window.hljs) {
                hljs.highlightElement(block);
            }
        });
    }

    startAssistantMessage(data) {
        if (!this.messagesContainer) return;

        this.showTyping(false);
        
        const messageEl = document.createElement('div');
        messageEl.className = 'message message-assistant streaming';
        messageEl.id = 'current-assistant-message';
        messageEl.innerHTML = `
            <div class="message-avatar">
                <span class="avatar-icon">🌟</span>
            </div>
            <div class="message-content">
                <div class="message-text"></div>
                <div class="message-cursor"></div>
            </div>
        `;

        this.messagesContainer.appendChild(messageEl);
        this.currentAssistantMessage = messageEl.querySelector('.message-text');
        this.scrollToBottom();
    }

    appendAssistantChunk(data) {
        if (this.currentAssistantMessage && data.content) {
            const currentHtml = this.currentAssistantMessage.innerHTML;
            this.currentAssistantMessage.innerHTML = this.renderMarkdown(
                this.extractTextFromHtml(currentHtml) + data.content
            );
            this.scrollToBottom();
        }
    }

    endAssistantMessage(data) {
        const messageEl = document.getElementById('current-assistant-message');
        if (messageEl) {
            messageEl.classList.remove('streaming');
            messageEl.id = '';
            
            // Remove cursor
            const cursor = messageEl.querySelector('.message-cursor');
            if (cursor) cursor.remove();

            // Add timestamp
            const contentEl = messageEl.querySelector('.message-content');
            const timeEl = document.createElement('div');
            timeEl.className = 'message-time';
            timeEl.textContent = this.formatTime(new Date().toISOString());
            contentEl.appendChild(timeEl);

            // Highlight code
            messageEl.querySelectorAll('pre code').forEach(block => {
                if (window.hljs) {
                    hljs.highlightElement(block);
                }
            });
        }

        this.currentAssistantMessage = null;
    }

    appendToolMessage(data, scroll = true) {
        if (!this.messagesContainer) return;

        const toolEl = document.createElement('div');
        toolEl.className = `tool-execution ${data.status || 'completed'}`;
        toolEl.innerHTML = this.renderToolExecution(data);

        this.messagesContainer.appendChild(toolEl);
        if (scroll) this.scrollToBottom();
    }

    startToolExecution(data) {
        if (!this.messagesContainer) return;

        const toolEl = document.createElement('div');
        toolEl.className = 'tool-execution running';
        toolEl.id = `tool-${data.tool_id || Date.now()}`;
        toolEl.innerHTML = `
            <div class="tool-header">
                <span class="tool-icon">${this.getToolIcon(data.tool_name)}</span>
                <span class="tool-name">${this.escapeHtml(data.tool_name)}</span>
                <span class="tool-status">
                    <span class="spinner"></span> Executando...
                </span>
            </div>
            <div class="tool-input">
                <pre><code>${this.escapeHtml(JSON.stringify(data.input, null, 2))}</code></pre>
            </div>
            <div class="tool-output"></div>
        `;

        this.messagesContainer.appendChild(toolEl);
        this.currentToolExecution = toolEl;
        this.scrollToBottom();
        
        this.onToolExecution(data);
    }

    updateToolProgress(data) {
        if (this.currentToolExecution) {
            const outputEl = this.currentToolExecution.querySelector('.tool-output');
            if (outputEl && data.output) {
                outputEl.innerHTML = `<pre>${this.escapeHtml(data.output)}</pre>`;
            }
        }
    }

    endToolExecution(data) {
        const toolEl = document.getElementById(`tool-${data.tool_id}`) || this.currentToolExecution;
        
        if (toolEl) {
            toolEl.classList.remove('running');
            toolEl.classList.add(data.success ? 'success' : 'error');
            
            const statusEl = toolEl.querySelector('.tool-status');
            if (statusEl) {
                statusEl.innerHTML = data.success 
                    ? '<span class="status-icon">✅</span> Concluído'
                    : '<span class="status-icon">❌</span> Erro';
            }

            const outputEl = toolEl.querySelector('.tool-output');
            if (outputEl && data.output) {
                const truncated = data.output.length > 2000 
                    ? data.output.substring(0, 2000) + '\n... (truncado)'
                    : data.output;
                outputEl.innerHTML = `<pre>${this.escapeHtml(truncated)}</pre>`;
            }
        }

        this.currentToolExecution = null;
    }

    renderToolExecution(data) {
        const icon = this.getToolIcon(data.tool_name);
        const statusClass = data.success !== false ? 'success' : 'error';
        const statusIcon = data.success !== false ? '✅' : '❌';
        
        let inputHtml = '';
        if (data.tool_input) {
            const input = typeof data.tool_input === 'string' 
                ? data.tool_input 
                : JSON.stringify(data.tool_input, null, 2);
            inputHtml = `
                <div class="tool-input">
                    <pre><code>${this.escapeHtml(input)}</code></pre>
                </div>
            `;
        }

        let outputHtml = '';
        if (data.tool_output) {
            const output = data.tool_output.length > 1000 
                ? data.tool_output.substring(0, 1000) + '\n... (truncado)'
                : data.tool_output;
            outputHtml = `
                <div class="tool-output">
                    <pre>${this.escapeHtml(output)}</pre>
                </div>
            `;
        }

        return `
            <div class="tool-header">
                <span class="tool-icon">${icon}</span>
                <span class="tool-name">${this.escapeHtml(data.tool_name)}</span>
                <span class="tool-status ${statusClass}">
                    <span class="status-icon">${statusIcon}</span>
                </span>
            </div>
            ${inputHtml}
            ${outputHtml}
        `;
    }

    handleFileCreated(data) {
        this.onFileCreated(data);
        
        // Add file indicator to UI if needed
        if (this.currentAssistantMessage) {
            const fileEl = document.createElement('div');
            fileEl.className = 'file-created';
            fileEl.innerHTML = `
                <span class="file-icon">📄</span>
                <span class="file-name">${this.escapeHtml(data.file_name)}</span>
            `;
            this.currentAssistantMessage.appendChild(fileEl);
        }
    }

    handleTaskComplete(data) {
        this.setProcessing(false);
        
        const completeEl = document.createElement('div');
        completeEl.className = 'task-complete';
        completeEl.innerHTML = `
            <div class="task-complete-header">
                <span class="complete-icon">✨</span>
                <span class="complete-title">Tarefa Concluída</span>
            </div>
            <div class="task-summary">${this.renderMarkdown(data.summary || '')}</div>
            ${data.files_created?.length ? `
                <div class="task-files">
                    <strong>Arquivos criados:</strong>
                    <ul>
                        ${data.files_created.map(f => `<li>📄 ${this.escapeHtml(f)}</li>`).join('')}
                    </ul>
                </div>
            ` : ''}
            ${data.next_steps?.length ? `
                <div class="task-next-steps">
                    <strong>Próximos passos sugeridos:</strong>
                    <ul>
                        ${data.next_steps.map(s => `<li>→ ${this.escapeHtml(s)}</li>`).join('')}
                    </ul>
                </div>
            ` : ''}
        `;

        if (this.messagesContainer) {
            this.messagesContainer.appendChild(completeEl);
            this.scrollToBottom();
        }

        this.onTaskComplete(data);
    }

    handleTaskError(data) {
        this.setProcessing(false);
        
        const errorEl = document.createElement('div');
        errorEl.className = 'task-error';
        errorEl.innerHTML = `
            <div class="error-header">
                <span class="error-icon">❌</span>
                <span class="error-title">Erro na Tarefa</span>
            </div>
            <div class="error-message">${this.escapeHtml(data.message || 'Erro desconhecido')}</div>
        `;

        if (this.messagesContainer) {
            this.messagesContainer.appendChild(errorEl);
            this.scrollToBottom();
        }

        window.D2UI.toast(data.message || 'Erro ao processar tarefa.', { tipo: 'erro' });
    }

    handleServerError(data) {
        window.D2UI.toast(data.message || 'Erro no servidor.', { tipo: 'erro' });
        this.onError(new Error(data.message));
    }

    // ==================== UI Helpers ====================

    updateStatus(status) {
        // O texto do topo e o ESTADO DE TRABALHO (Parado/Trabalhando/Aguardando), nao a conexao WS.
        // Antes a conexao escrevia 'Online' por cima; agora nao sobrescreve mais.
        this.onStatusChange(status);
    }

    // ===== Renderizadores genéricos p/ eventos do agente (Ravi) =====
    hideWelcome() {
        const w = document.getElementById('chat-welcome');
        if (w) w.style.display = 'none';
    }
    cap(s) { s = String(s || ''); return s.charAt(0).toUpperCase() + s.slice(1); }

    appendSystemLine(text) {
        if (!this.messagesContainer || !text) return;
        this.hideWelcome();
        const el = document.createElement('div');
        el.className = 'message message-system';
        el.style.cssText = 'font-size:.8rem;padding:.3rem .6rem;color:var(--d2-text-muted)';
        el.textContent = String(text);
        this.placeInChat(el);
        this.scrollToBottom();
    }

    // Status efêmero: uma linha de atividade que se SUBSTITUI (não polui o chat).
    // Mostra quem está trabalhando (especialista ativo) à esquerda do status.
    setLiveStatus(text, agent) {
        if (!this.messagesContainer || !text) return;
        this.hideWelcome();
        this.showTyping(false); // o status vivo substitui os 3 pontinhos

        if (!this.liveStatusEl) {
            this.liveStatusEl = document.createElement('div');
            this.liveStatusEl.className = 'live-status';
            this.liveStatusEl.style.cssText = 'display:flex;align-items:center;gap:.55rem;font-size:.85rem;color:var(--d2-text-muted);padding:.4rem .75rem;margin:.1rem 0 .1rem 2.5rem';
            this.liveStatusEl.innerHTML = '<span class="live-badge"></span><span class="live-dot"></span><span class="live-text"></span>';
        }
        this.messagesContainer.appendChild(this.liveStatusEl); // mantém no fim
        // Selo do especialista ativo (monograma + nome), some quando não há agente.
        const badge = this.liveStatusEl.querySelector('.live-badge');
        if (agent && agent.initial) {
            const initial = String(agent.initial).replace(/[<>&]/g, '').slice(0, 1).toUpperCase();
            const name = String(agent.name || '').replace(/[<>&]/g, '');
            badge.innerHTML = `<span class="live-mono" style="background:${this.safeColor(agent.color)}">${initial}</span>`
                + `<span style="font-weight:600;color:${this.safeColor(agent.color)};margin:0 .15rem 0 .35rem">${name}</span>`;
            badge.style.cssText = 'display:inline-flex;align-items:center';
        } else {
            badge.innerHTML = '';
        }
        this.liveStatusEl.querySelector('.live-text').textContent = String(text);
        this.liveStatusEl.style.display = 'flex';
        this.scrollToBottom();
    }

    clearLiveStatus() {
        if (this.liveStatusEl) { this.liveStatusEl.remove(); this.liveStatusEl = null; }
    }

    appendAgentText(text, agent) {
        if (!text) return;
        this.hideWelcome();
        this.clearLiveStatus(); // mensagem real chegou; encerra o status efêmero
        this.appendAssistantMessage({ content: String(text), created_at: new Date().toISOString(), agent });
        this.currentAssistantMessage = null;
    }

    appendAgentDelta(text) {
        if (!text) return;
        this.hideWelcome();
        if (!this.currentAssistantMessage) { this.startAssistantMessage({}); }
        if (this.currentAssistantMessage) {
            const cur = this.extractTextFromHtml(this.currentAssistantMessage.innerHTML || '');
            this.currentAssistantMessage.innerHTML = this.renderMarkdown(cur + text);
            this.scrollToBottom();
        }
    }

    // Passo limpo (estilo Manus): uma linha curta com ícone, sem código.
    appendStepLine(text) {
        if (!this.messagesContainer || !text) return;
        this.hideWelcome();
        const el = document.createElement('div');
        el.className = 'agent-step';
        el.style.cssText = 'display:flex;align-items:center;gap:.5rem;font-size:.9rem;padding:.35rem .75rem;margin:.15rem 0 .15rem 2.5rem;color:var(--d2-text-muted)';
        el.textContent = String(text);
        this.messagesContainer.appendChild(el);
        this.scrollToBottom();
    }

    // POST JSON com CSRF (mesmo padrão do salvar arquivo); devolve o objeto data ou null.
    postJson(url, body) {
        const token = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': token },
            credentials: 'same-origin',
            body: JSON.stringify(body || {})
        }).then(r => r.json()).then(resp => (resp && resp.data) ? resp.data : resp).catch(() => null);
    }

    // ── QA Visual ao vivo: card "Navegador ao vivo" (screencast efêmero, ~4fps) ──────
    // Mostra o navegador de QA navegando de verdade na app, com barra de URL e legenda.
    // É EFÊMERO: some quando o QA termina (screencast_stop) e NÃO acumula nada na tela.
    showScreencastCard(label) {
        if (!this.messagesContainer || !this.projectUuid) return;
        this.hideWelcome();
        let card = document.getElementById('screencast-card');
        if (!card) {
            card = document.createElement('div');
            card.id = 'screencast-card';
            card.className = 'screencast-card';
            // flex-shrink:0 é CRÍTICO: o #chat-messages é flex coluna e, com overflow:hidden, o card
            // vira "scroll container" (min-size automático 0) e o flexbox o esmaga para ~2px (uma linha).
            card.style.cssText = 'flex:0 0 auto;flex-shrink:0;display:block;width:100%;max-width:820px;box-sizing:border-box;margin:.75rem 0 .75rem 2.5rem;background:var(--d2-bg-subtle);border:1px solid var(--d2-border);border-left:3px solid var(--d2-brand);border-radius:12px;overflow:hidden';
            card.innerHTML =
                '<div style="display:flex;align-items:center;gap:.5rem;padding:.55rem .75rem;border-bottom:1px solid var(--d2-border)">'
                + '<span class="sc-live" style="display:inline-flex;align-items:center;gap:.35rem;font-size:.72rem;font-weight:700;color:var(--d2-brand-texto);text-transform:uppercase;letter-spacing:.03em;white-space:nowrap"><span style="width:8px;height:8px;border-radius:50%;background:var(--d2-brand);animation:scpulse 1.4s infinite"></span>Navegador ao vivo</span>'
                + '<span class="sc-url" style="flex:1;min-width:0;font-size:.78rem;color:var(--d2-text-muted);background:var(--d2-bg-hover);padding:.2rem .5rem;border-radius:6px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"></span>'
                + '</div>'
                + '<div style="position:relative;width:100%;background:var(--d2-superficie-2);aspect-ratio:16/10;min-height:320px;display:flex;align-items:center;justify-content:center">'
                + '<img class="sc-img" alt="navegador" style="width:100%;height:100%;object-fit:contain;display:block"/>'
                + '<div class="sc-wait" style="position:absolute;font-size:.9rem;color:var(--d2-text-muted);display:flex;align-items:center;gap:.5rem"><span class="sc-spin" style="width:14px;height:14px;border:2px solid var(--d2-border-strong);border-top-color:var(--d2-brand-texto);border-radius:50%;animation:scspin .8s linear infinite"></span>Abrindo o navegador…</div>'
                + '</div>'
                + '<div class="sc-caption" style="padding:.5rem .75rem;font-size:.83rem;color:var(--d2-text-muted);min-height:1.2em"></div>';
            // Animações (scpulse, scspin) ficam em /assets/css/chat.css.
            this.messagesContainer.appendChild(card);
        }
        if (label) {
            const cap = card.querySelector('.sc-caption');
            if (cap && !cap.textContent) cap.textContent = String(label);
        }
        this._scClosing = false;
        // Garante que o card fique VISÍVEL quando abre (mesmo com mensagens rolando).
        try { card.scrollIntoView({ behavior: 'smooth', block: 'center' }); } catch (_) { this.scrollToBottom(); }
        this._startScreencastPolling();
    }

    _startScreencastPolling() {
        if (this._scFrameTimer) return; // já rodando
        const base = '/chat/' + this.projectUuid + '/screencast';
        let tick = 0;
        // ~4fps no frame; status (url/legenda/done) a cada ~600ms.
        this._scFrameTimer = setInterval(() => {
            const card = document.getElementById('screencast-card');
            if (!card) { this._stopScreencastPolling(); return; }
            const img = card.querySelector('.sc-img');
            if (img) {
                const probe = new Image();
                probe.onload = () => {
                    img.src = probe.src;
                    const wait = card.querySelector('.sc-wait');
                    if (wait) wait.style.display = 'none';
                };
                probe.src = base + '?t=' + Date.now();
            }
            if ((tick++ % 3) === 0) this._pollScreencastStatus(base, card);
        }, 250);
    }

    _pollScreencastStatus(base, card) {
        fetch(base + '/status', { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(r => r.json()).then(resp => {
                const d = (resp && resp.data) ? resp.data : resp;
                if (!d) return;
                const urlEl = card.querySelector('.sc-url');
                const capEl = card.querySelector('.sc-caption');
                if (urlEl && d.url) urlEl.textContent = d.url;
                if (capEl && d.caption) capEl.textContent = d.caption;
                if (d.done) {
                    // O harness terminou: congela o último frame e fecha com folga.
                    this.hideScreencastCard(true);
                }
            }).catch(() => {});
    }

    _stopScreencastPolling() {
        if (this._scFrameTimer) { clearInterval(this._scFrameTimer); this._scFrameTimer = null; }
    }

    hideScreencastCard(graceful) {
        const card = document.getElementById('screencast-card');
        if (!card) { this._stopScreencastPolling(); return; }
        // Fechamento suave: para de buscar frames novos, CONGELA o último frame já carregado
        // (mostra mesmo que o servidor já tenha apagado o frame.jpg) e fecha depois de alguns
        // segundos — para a navegação não "sumir num piscar" quando o QA passa rápido.
        if (graceful && !this._scClosing) {
            this._scClosing = true;
            this._stopScreencastPolling();
            const live = card.querySelector('.sc-live');
            if (live) live.innerHTML = '<span style="width:8px;height:8px;border-radius:50%;background:var(--d2-ok);display:inline-block"></span>Teste no navegador concluído';
            const wait = card.querySelector('.sc-wait'); if (wait) wait.style.display = 'none';
            try { card.scrollIntoView({ behavior: 'smooth', block: 'center' }); } catch (_) {}
            setTimeout(() => { const c = document.getElementById('screencast-card'); if (c) c.remove(); this._scClosing = false; }, 4000);
            return;
        }
        if (!graceful) {
            this._stopScreencastPolling();
            card.remove();
        }
    }

    // Reidrata o card se o QA já estiver rodando ao (re)carregar a página.
    checkScreencastStatus() {
        if (!this.projectUuid) return;
        fetch('/chat/' + this.projectUuid + '/screencast/status', { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(r => r.json()).then(resp => {
                const d = (resp && resp.data) ? resp.data : resp;
                if (d && d.running) this.showScreencastCard(d.caption);
            }).catch(() => {});
    }

    // Card "Pré-visualização ao vivo" (botão abrir/parar + link + credenciais).
    renderPreviewCard(state) {
        if (!this.messagesContainer || !this.projectUuid) return;
        this.hideWelcome();
        let card = document.getElementById('preview-card');
        if (!card) {
            card = document.createElement('div');
            card.id = 'preview-card';
            card.className = 'preview-card';
            card.style.cssText = 'margin:.75rem 0 .75rem 2.5rem;padding:1rem 1.15rem;background:var(--d2-bg-subtle);border:1px solid var(--d2-border);border-left:3px solid var(--d2-ok);border-radius:12px';
            this.messagesContainer.appendChild(card);
        }
        this._renderPreviewBody(card, state || { mode: 'idle' });
        this.scrollToBottom();
    }

    _renderPreviewBody(card, state) {
        const title = '<div style="font-weight:600;margin-bottom:.4rem">Pré-visualização ao vivo</div>';
        if (state.mode === 'running') {
            const creds = state.demo
                ? `<div style="margin:.55rem 0;font-size:.85rem;background:var(--d2-bg-hover);padding:.5rem .6rem;border-radius:8px"><strong>Login de demonstração</strong><br>usuário: <code>${state.demo.user || ''}</code> &nbsp; senha: <code>${state.demo.password || ''}</code></div>`
                : '';
            card.innerHTML = title
                + `<a href="${state.url}" target="_blank" rel="noopener" class="btn btn-primary" style="display:inline-block;margin-bottom:.5rem">Abrir no navegador ↗</a>`
                + `<div style="font-size:.8rem;color:var(--d2-text-muted);word-break:break-all;margin-bottom:.5rem">${state.url}</div>`
                + creds
                + `<button class="btn preview-stop-btn" type="button" style="font-size:.83rem">Parar preview</button>`;
            card.querySelector('.preview-stop-btn').addEventListener('click', () => this.stopPreview());
        } else if (state.mode === 'loading') {
            card.innerHTML = title + `<div style="font-size:.88rem;color:var(--d2-text-muted)">${state.text || 'Subindo o preview…'}</div>`;
        } else if (state.mode === 'error') {
            card.innerHTML = title
                + `<div style="font-size:.88rem;color:var(--d2-erro);margin-bottom:.5rem">${state.text || 'Não foi possível abrir o preview.'}</div>`
                + `<button class="btn btn-primary preview-open-btn" type="button">Tentar de novo</button>`;
            card.querySelector('.preview-open-btn').addEventListener('click', () => this.startPreview());
        } else {
            card.innerHTML = title
                + `<div style="font-size:.85rem;color:var(--d2-text-muted);margin-bottom:.7rem">Abra o sistema rodando num link, sem precisar baixar.</div>`
                + `<button class="btn btn-primary preview-open-btn" type="button">Abrir preview ao vivo</button>`;
            card.querySelector('.preview-open-btn').addEventListener('click', () => this.startPreview());
        }
    }

    startPreview() {
        this.renderPreviewCard({ mode: 'loading', text: 'Subindo o preview… (pode levar alguns segundos)' });
        const card = document.getElementById('preview-card');
        this.postJson('/chat/' + this.projectUuid + '/preview/start', {}).then(res => {
            if (res && res.ok && res.url) {
                this._renderPreviewBody(card, { mode: 'running', url: res.url, demo: res.demo });
            } else {
                this._renderPreviewBody(card, { mode: 'error', text: (res && res.error) || 'Falha ao abrir o preview.' });
            }
        });
    }

    stopPreview() {
        const card = document.getElementById('preview-card');
        if (card) this._renderPreviewBody(card, { mode: 'loading', text: 'Parando o preview…' });
        this.postJson('/chat/' + this.projectUuid + '/preview/stop', {}).then(() => {
            if (card) this._renderPreviewBody(card, { mode: 'idle' });
        });
    }

    // Restaura o card se já houver um preview rodando para este projeto (ao reabrir a conversa).
    checkPreviewStatus() {
        if (!this.projectUuid) return;
        fetch('/chat/' + this.projectUuid + '/preview/status', { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
            .then(r => r.ok ? r.json() : null)
            .then(resp => {
                const d = (resp && resp.data) ? resp.data : resp;
                if (d && d.running) this.renderDeliveryUnified();
            }).catch(() => {});
    }

    // Liga o banner de entrega (renderizado no SERVIDOR em #delivery-banner): wireia o botão
    // de preview e atualiza o estado se já houver preview rodando. Robusto, não recria nada.
    wireDeliveryBanner() {
        const banner = document.getElementById('delivery-banner');
        if (!banner) return;
        const ob = banner.querySelector('.du-preview-open');
        if (ob && !ob._wired) { ob._wired = true; ob.addEventListener('click', () => this.startPreviewIn(banner)); }
        const fb = banner.querySelector('.du-files');
        if (fb && !fb._wired) { fb._wired = true; fb.addEventListener('click', () => this.openFilesPanel()); }
        this._renderCreds(banner, this._demo);   // usuário/senha de demo já visíveis (do app.json)
        this._refreshPreviewHalf(banner);         // se o preview estiver rodando, atualiza botões/creds
        this.refreshApkSlot(banner);
        this.refreshExpoSlot(banner);
    }

    // ===== Expo Go (testar no celular real via QR) =====
    refreshExpoSlot(banner) {
        const body = (banner || document).querySelector('.du-expo-body');
        if (!body || !this.projectUuid) return;
        fetch('/chat/' + this.projectUuid + '/expo/status', { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
            .then(r => r.ok ? r.json() : null)
            .then(resp => {
                const d = (resp && resp.data) ? resp.data : resp;
                this.renderExpoSlot(body, d || { status: 'idle' });
            }).catch(() => {});
    }

    renderExpoSlot(body, state) {
        if (!body) return;
        const st = state.status || 'idle';
        if (st === 'ready' && state.url) {
            const qr = 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=' + encodeURIComponent(state.url);
            body.innerHTML =
                '<div style="display:flex;gap:.8rem;align-items:flex-start;flex-wrap:wrap">' +
                  '<img src="' + qr + '" alt="QR Expo Go" width="160" height="160" class="du-qr"/>' +
                  '<div style="font-size:.82rem;color:var(--d2-text-muted);max-width:240px">' +
                    'Instale o app <strong>Expo Go</strong> no celular e escaneie o QR (no iPhone, use a câmera).' +
                    '<div style="margin:.5rem 0;word-break:break-all"><code>' + state.url + '</code></div>' +
                    '<button class="btn btn-secondary du-expo-stop" type="button" style="font-size:.8rem">Parar</button>' +
                  '</div>' +
                '</div>';
            const sb = body.querySelector('.du-expo-stop');
            if (sb) sb.addEventListener('click', () => this.stopExpo(body));
        } else if (st === 'starting') {
            body.innerHTML = '<div style="font-size:.85rem;color:var(--d2-text-muted)">Iniciando o túnel… (alguns segundos)</div>';
            clearTimeout(this._expoTimer);
            this._expoTimer = setTimeout(() => this.refreshExpoSlot(body.closest('.delivery-unified') || document), 4000);
        } else if (st === 'error') {
            body.innerHTML = '<div style="font-size:.83rem;color:var(--d2-erro);margin-bottom:.4rem">' + (state.error || 'Falha ao iniciar.') + '</div>' +
                '<button class="btn btn-secondary du-expo-start" type="button">Tentar de novo</button>';
            const b = body.querySelector('.du-expo-start');
            if (b) b.addEventListener('click', () => this.startExpo(body));
        } else {
            body.innerHTML = '<button class="btn btn-secondary du-expo-start" type="button">Gerar QR (Expo Go)</button>';
            const b = body.querySelector('.du-expo-start');
            if (b) b.addEventListener('click', () => this.startExpo(body));
        }
    }

    startExpo(body) {
        this.renderExpoSlot(body, { status: 'starting' });
        this.postJson('/chat/' + this.projectUuid + '/expo/start', {}).then(res => {
            if (res && res.ok) this.renderExpoSlot(body, { status: res.status || 'starting', url: res.url });
            else this.renderExpoSlot(body, { status: 'error', error: (res && res.error) || 'Não foi possível iniciar.' });
        });
    }

    stopExpo(body) {
        this.renderExpoSlot(body, { status: 'starting' });
        this.postJson('/chat/' + this.projectUuid + '/expo/stop', {}).then(() => {
            this.renderExpoSlot(body, { status: 'idle' });
        });
    }

    // ===== APK Android (build sob demanda) =====
    refreshApkSlot(banner) {
        const body = (banner || document).querySelector('.du-apk-body');
        if (!body || !this.projectUuid) return;
        fetch('/chat/' + this.projectUuid + '/apk/status', { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
            .then(r => r.ok ? r.json() : null)
            .then(resp => {
                const d = (resp && resp.data) ? resp.data : resp;
                this.renderApkSlot(body, d || { status: 'idle' });
            }).catch(() => {});
    }

    renderApkSlot(body, state) {
        if (!body) return;
        const st = state.status || 'idle';
        if (st === 'ready' && state.apk) {
            body.innerHTML = '<a class="btn btn-primary" href="/chat/' + this.projectUuid + '/download?file=' + encodeURIComponent(state.apk) + '">Baixar APK (Android)</a>';
        } else if (st === 'building') {
            body.innerHTML = '<div style="font-size:.85rem;color:var(--d2-text-muted)">Gerando o APK… isso leva alguns minutos. Pode continuar usando o chat.</div>';
            clearTimeout(this._apkTimer);
            this._apkTimer = setTimeout(() => this.refreshApkSlot(body.closest('.delivery-unified') || document), 6000);
        } else if (st === 'error') {
            body.innerHTML = '<div style="font-size:.83rem;color:var(--d2-erro);margin-bottom:.4rem">' + (state.error || 'O build do APK falhou.') + '</div>' +
                '<button class="btn btn-secondary du-apk-build" type="button">Tentar de novo</button>';
            const b = body.querySelector('.du-apk-build');
            if (b) b.addEventListener('click', () => this.buildApk(body));
        } else {
            body.innerHTML = '<button class="btn btn-secondary du-apk-build" type="button">Gerar APK</button>';
            const b = body.querySelector('.du-apk-build');
            if (b) b.addEventListener('click', () => this.buildApk(body));
        }
    }

    buildApk(body) {
        this.renderApkSlot(body, { status: 'building' });
        this.postJson('/chat/' + this.projectUuid + '/apk/build', {}).then(res => {
            if (res && res.ok) this.renderApkSlot(body, { status: res.status || 'building', apk: res.apk });
            else this.renderApkSlot(body, { status: 'error', error: (res && res.error) || 'Não foi possível iniciar o build.' });
        });
    }

    // Garante o banner de entrega: se o servidor já renderizou, só wireia; se não (entrega AO VIVO
    // no meio da sessão), insere a mesma barra acima do input. Independe de timing/WebSocket.
    // Esconde a tarja "Projeto entregue" (ex.: quando um novo build começa). Lembra que ela existia
    // para poder recriá-la ao concluir uma tarefa que não reemite preview_available.
    removeDeliveryBanner() {
        const b = document.getElementById('delivery-banner');
        if (b) { this._hadDeliveryBanner = true; b.remove(); }
    }

    // Card de ENTREGA (hero) renderizado DENTRO do chat: largo, horizontal e celebrativo — o
    // momento em que o que foi pedido fica pronto. Idempotente (id único); recriado a cada entrega.
    ensureDeliveryBanner() {
        const existing = document.getElementById('delivery-banner');
        if (existing) {
            // Já existe (render do servidor ou anterior): garante que fica no FIM do thread e wira.
            if (this.messagesContainer) this.messagesContainer.appendChild(existing);
            this.wireDeliveryBanner();
            return;
        }
        if (!this.messagesContainer || !this.projectUuid) return;
        this.hideWelcome();
        const isMobile = !!(window.CHAT_CONFIG && window.CHAT_CONFIG.mobile);
        const card = document.createElement('div');
        card.className = 'delivery-hero';
        card.id = 'delivery-banner';
        if (isMobile) card.setAttribute('data-mobile', '1');

        const previewSlot = (this._previewable !== false)
            ? '<div class="du-preview-body"><button class="btn btn-primary du-preview-open" type="button">Abrir preview ao vivo</button></div>'
            : '';
        const dl = this.currentZip
            ? '<a class="btn btn-secondary" href="/chat/' + this.projectUuid + '/download?file=' + encodeURIComponent(this.currentZip) + '">Baixar projeto (.zip)</a>'
            : '<button class="btn btn-secondary du-files" type="button">Ver arquivos</button>';
        const apkSlot = isMobile ? '<div class="du-apk-body"></div>' : '';
        const expoSlot = isMobile ? '<div class="du-expo-body"></div>' : '';
        const sub = (this._previewable !== false)
            ? 'Tudo pronto — abra no navegador, teste de verdade e baixe o pacote completo.'
            : 'Tudo pronto — baixe o pacote completo para implantar onde quiser.';

        card.innerHTML =
            '<div class="dh-head">'
              + '<span class="dh-badge"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg></span>'
              + '<div class="dh-titles"><div class="dh-title">Projeto entregue</div><div class="dh-sub">' + sub + '</div></div>'
            + '</div>'
            + '<div class="dh-actions">' + previewSlot + apkSlot + expoSlot + dl + '</div>'
            + '<div class="du-creds-slot"></div>';

        this.messagesContainer.appendChild(card);
        const fb = card.querySelector('.du-files');
        if (fb) fb.addEventListener('click', () => this.openFilesPanel());
        this._renderCreds(card, this._demo); // usuário/senha de demo já visíveis no card
        this.wireDeliveryBanner();
        this.scrollToBottom();
    }

    // Popula o slot de credenciais (chips de copiar) com o usuário/senha de demonstração.
    _renderCreds(card, demo) {
        const slot = card ? card.querySelector('.du-creds-slot') : null;
        if (!slot) return;
        if (demo && (demo.user || demo.password)) {
            slot.innerHTML = this._buildCredsHtml(demo); // traz "Acesso de demonstração" + chips
            slot.querySelectorAll('.du-cred').forEach(b =>
                b.addEventListener('click', () => this.copyToClipboard(b.getAttribute('data-copy') || '', b)));
        } else {
            slot.innerHTML = '';
        }
    }

    // ===== (legado) Card de entrega no thread — substituído pelo banner persistente =====
    renderDeliveryUnified() {
        if (!this.messagesContainer || !this.projectUuid) return;
        this.hideWelcome();
        this.messagesContainer.querySelectorAll('.delivery-card, .preview-card, .delivery-unified').forEach(el => el.remove());
        const hasZip = !!this.currentZip;
        const card = document.createElement('div');
        card.className = 'delivery-unified';
        card.innerHTML =
            '<div class="du-head"><span class="du-check">✓</span> Projeto entregue</div>' +
            '<div class="du-sub">Pronto para visualizar e baixar.</div>' +
            '<div class="du-half">' +
                '<span class="du-label">Visualizar</span>' +
                '<span class="du-desc">Abra o sistema rodando, sem instalar nada.</span>' +
                '<div class="du-preview-body"><button class="btn btn-primary du-preview-open" type="button">Abrir preview ao vivo</button></div>' +
            '</div>' +
            '<div class="du-half">' +
                '<span class="du-label">Download</span>' +
                '<span class="du-desc">Projeto completo, pronto para implantar em outro lugar.</span>' +
                (hasZip
                    ? '<button class="btn btn-secondary du-zip" type="button">Baixar projeto (.zip)</button>'
                    : '<button class="btn btn-secondary du-files" type="button">Ver arquivos</button>') +
            '</div>';
        this.messagesContainer.appendChild(card);
        const zb = card.querySelector('.du-zip');
        if (zb) zb.addEventListener('click', () => { window.location = '/chat/' + this.projectUuid + '/download?file=' + encodeURIComponent(this.currentZip); });
        const fb = card.querySelector('.du-files');
        if (fb) fb.addEventListener('click', () => this.openFilesPanel());
        const ob = card.querySelector('.du-preview-open');
        if (ob) ob.addEventListener('click', () => this.startPreviewIn(card));
        this._refreshPreviewHalf(card);
        this.scrollToBottom();
    }

    _refreshPreviewHalf(card) {
        if (!card || !this.projectUuid) return;
        fetch('/chat/' + this.projectUuid + '/preview/status', { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
            .then(r => r.ok ? r.json() : null)
            .then(resp => {
                const d = (resp && resp.data) ? resp.data : resp;
                if (d && d.running && d.url) this._renderPreviewHalf(card, { mode: 'running', url: d.url, demo: d.demo, mobile: d.mobile });
            }).catch(() => {});
    }

    _esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    }

    // Credenciais de demonstração como CHIPS clicáveis (copiam usuário/senha num clique).
    _buildCredsHtml(demo) {
        const chip = (k, v) => v
            ? '<button type="button" class="du-cred" data-copy="' + this._esc(v) + '" data-tooltip="Copiar ' + k + '">'
                + '<span class="du-cred-k">' + k + '</span>'
                + '<code class="du-cred-v">' + this._esc(v) + '</code>'
                + '<span class="du-cred-ic" aria-hidden="true">⧉</span>'
              + '</button>'
            : '';
        return '<div class="du-creds">'
            + '<span class="du-creds-label">Acesso de demonstração</span>'
            + '<div class="du-cred-chips">' + chip('usuário', demo.user) + chip('senha', demo.password) + '</div>'
            + '</div>';
    }

    copyToClipboard(text, btn) {
        if (!text) return;
        const ok = () => this._flashCopied(btn);
        try {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(ok).catch(() => this._legacyCopy(text, ok));
            } else {
                this._legacyCopy(text, ok);
            }
        } catch (_) { this._legacyCopy(text, ok); }
    }

    _legacyCopy(text, ok) {
        try {
            const ta = document.createElement('textarea');
            ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
            document.body.appendChild(ta); ta.focus(); ta.select();
            document.execCommand('copy'); document.body.removeChild(ta);
            ok();
        } catch (_) {}
    }

    _flashCopied(btn) {
        if (!btn) return;
        btn.classList.add('copied');
        const ic = btn.querySelector('.du-cred-ic');
        const prev = ic ? ic.textContent : '';
        if (ic) ic.textContent = '✓';
        clearTimeout(btn._copyT);
        btn._copyT = setTimeout(() => { btn.classList.remove('copied'); if (ic) ic.textContent = prev; }, 1300);
    }

    _renderPreviewHalf(card, state) {
        const body = card.querySelector('.du-preview-body');
        if (!body) return;
        // Credenciais (chips de copiar) no slot full-width: as do preview rodando OU as do app.json.
        this._renderCreds(card, state.demo || this._demo);
        if (state.mode === 'running') {
            const openLabel = state.mobile ? 'Abrir preview (Android + iPhone) ↗' : 'Abrir no navegador ↗';
            body.innerHTML =
                '<a href="' + state.url + '" target="_blank" rel="noopener" class="btn btn-primary">' + openLabel + '</a>' +
                '<button class="btn btn-secondary du-preview-stop" type="button">Parar preview</button>';
            const sb = body.querySelector('.du-preview-stop');
            if (sb) sb.addEventListener('click', () => this.stopPreviewIn(card));
        } else if (state.mode === 'loading') {
            body.innerHTML = '<div class="dh-muted">' + (state.text || 'Subindo…') + '</div>';
        } else if (state.mode === 'error') {
            body.innerHTML = '<div class="dh-err">' + (state.text || 'Não foi possível abrir.') + '</div>' +
                '<button class="btn btn-primary du-preview-open" type="button">Tentar de novo</button>';
            const ob = body.querySelector('.du-preview-open');
            if (ob) ob.addEventListener('click', () => this.startPreviewIn(card));
        } else {
            body.innerHTML = '<button class="btn btn-primary du-preview-open" type="button">Abrir preview ao vivo</button>';
            const ob = body.querySelector('.du-preview-open');
            if (ob) ob.addEventListener('click', () => this.startPreviewIn(card));
        }
    }

    startPreviewIn(card) {
        this._renderPreviewHalf(card, { mode: 'loading', text: 'Subindo o preview… (alguns segundos)' });
        this.postJson('/chat/' + this.projectUuid + '/preview/start', {}).then(res => {
            if (res && res.ok && res.url) this._renderPreviewHalf(card, { mode: 'running', url: res.url, demo: res.demo, mobile: res.mobile });
            else this._renderPreviewHalf(card, { mode: 'error', text: (res && res.error) || 'Falha ao abrir o preview.' });
        });
    }

    stopPreviewIn(card) {
        this._renderPreviewHalf(card, { mode: 'loading', text: 'Parando…' });
        this.postJson('/chat/' + this.projectUuid + '/preview/stop', {}).then(() => {
            this._renderPreviewHalf(card, { mode: 'idle' });
        });
    }

    // Card de aprovação do plano com botão "Aprovar".
    renderPlanCard(data) {
        if (!this.messagesContainer) return;
        this.hideWelcome();
        this.setProcessing(false);
        this.markChatWaiting(); // plano na mesa: aguardando sua aprovacao (lateral azul, topo 'Aguardando')
        this.currentAssistantMessage = null;
        this.messagesContainer.querySelectorAll('.plan-approval-card').forEach(el => el.remove()); // idempotente

        let filesHtml = '';
        if (Array.isArray(data.files) && data.files.length) {
            const n = data.files.length;
            filesHtml =
                '<div class="plan-files" style="margin:.2rem 0 .9rem">' +
                '<div style="font-size:.82rem;font-weight:600;color:var(--d2-text-muted);margin-bottom:.4rem">' +
                    'Estrutura de arquivos esperada ' +
                    '<span style="font-weight:700;font-size:.72rem;color:var(--d2-brand-texto);background:var(--d2-brand-fundo);padding:.05rem .45rem;border-radius:999px">' + n + ' arquivo' + (n > 1 ? 's' : '') + '</span>' +
                '</div>' +
                '<pre style="margin:0;padding:.7rem .85rem;background:var(--d2-bg-elevated);border:1px solid var(--d2-border);border-radius:8px;font-family:var(--d2-font-mono);font-size:.8rem;line-height:1.5;color:var(--d2-text);overflow:auto;max-height:260px">' +
                    this.buildFileTree(data.files) +
                '</pre></div>';
        }

        const card = document.createElement('div');
        card.className = 'plan-approval-card';
        card.style.cssText = 'margin:.75rem 0 .75rem 2.5rem;padding:1rem 1.15rem;background:var(--d2-bg-subtle);border:1px solid var(--d2-border);border-left:3px solid var(--d2-brand);border-radius:12px';
        card.innerHTML = `
            <div style="font-weight:600;margin-bottom:.4rem">Plano pronto para aprovação</div>
            <div class="plan-approval-msg" style="font-size:.92rem;color:var(--d2-text-muted);margin-bottom:.85rem"></div>
            ${filesHtml}
            <div style="display:flex;gap:.6rem;align-items:center;flex-wrap:wrap">
                <button class="btn btn-primary plan-approve-btn" type="button">Aprovar e desenvolver</button>
                <button class="btn btn-secondary plan-view-btn" type="button">📄 Ver plano completo</button>
                <span style="font-size:.82rem;color:var(--d2-text-muted)">ou digite abaixo o que quer ajustar</span>
            </div>
        `;
        card.querySelector('.plan-approval-msg').innerHTML = this.renderMarkdown(data.message || 'Revise o plano acima e aprove para eu seguir sozinho.');
        const btn = card.querySelector('.plan-approve-btn');
        btn.addEventListener('click', () => this.approvePlan(card));
        const viewBtn = card.querySelector('.plan-view-btn');
        if (viewBtn) viewBtn.addEventListener('click', () => this.openFileModal('PLAN.md'));

        this.messagesContainer.appendChild(card);
        this.scrollToBottom();
    }

    // Monta uma árvore ASCII (pastas primeiro) a partir da lista plana de caminhos do plano.
    buildFileTree(files) {
        const root = {};
        (files || []).forEach(p => {
            const parts = String(p).split('/').filter(Boolean);
            let node = root;
            parts.forEach((part, i) => {
                const isFile = (i === parts.length - 1);
                if (!node[part]) node[part] = { dir: !isFile, children: {} };
                if (!isFile) node[part].dir = true;
                node = node[part].children;
            });
        });
        const esc = (s) => String(s).replace(/[&<>]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c]));
        const lines = [];
        const walk = (node, prefix) => {
            const keys = Object.keys(node).sort((a, b) => {
                if (node[a].dir !== node[b].dir) return node[a].dir ? -1 : 1; // pastas primeiro
                return a.localeCompare(b);
            });
            keys.forEach((k, idx) => {
                const last = idx === keys.length - 1;
                lines.push(prefix + (last ? '└─ ' : '├─ ') + (node[k].dir ? '📁 ' : '📄 ') + esc(k));
                if (node[k].dir) walk(node[k].children, prefix + (last ? '   ' : '│  '));
            });
        };
        walk(root, '');
        return lines.join('\n');
    }

    approvePlan(card) {
        if (card) {
            const btn = card.querySelector('.plan-approve-btn');
            if (btn) { btn.disabled = true; btn.textContent = 'Aprovado — desenvolvendo…'; }
        }
        // Não exibe como se o usuário tivesse digitado — só uma linha discreta.
        this.appendSystemLine('Plano aprovado, seguindo com o desenvolvimento.');
        this.send({ type: 'send_message', conversation_uuid: this.conversationUuid, content: 'aprovar' });
        this.setProcessing(true);
    }

    // ==================== Perguntas de esclarecimento (estilo VSCode) ====================

    // Card com ABAS (uma por pergunta). Cada pergunta é aberta (texto livre), de escolha única
    // ou múltipla, sempre com a opção "Outro". Ao enviar, monta um texto e manda como mensagem.
    renderClarifyCard(data) {
        if (!this.messagesContainer) return;
        this.hideWelcome();
        this.setProcessing(false);
        this.currentAssistantMessage = null;
        this.messagesContainer.querySelectorAll('.clarify-card').forEach(el => el.remove());
        this.injectClarifyStyles();

        const questions = Array.isArray(data.questions) ? data.questions : [];
        if (!questions.length) return;
        const esc = (s) => String(s == null ? '' : s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

        const card = document.createElement('div');
        card.className = 'clarify-card';
        card._answers = questions.map(q => ({ value: null, values: [], text: '', other: '' }));

        let tabs = '';
        questions.forEach((q, i) => {
            tabs += `<button type="button" class="clarify-tab${i === 0 ? ' active' : ''}" data-i="${i}"><span class="clarify-tab-num">${i + 1}</span>${esc(q.header || ('Pergunta ' + (i + 1)))}<span class="clarify-tab-check">✓</span></button>`;
        });
        card.innerHTML =
            `<div class="clarify-head">🧭 Antes de planejar, preciso alinhar ${questions.length > 1 ? (questions.length + ' pontos') : 'um ponto'} com você:</div>` +
            `<div class="clarify-tabs">${tabs}</div>` +
            `<div class="clarify-body"></div>` +
            `<div class="clarify-foot"><span class="clarify-progress"></span>` +
            `<div class="clarify-foot-actions"><button type="button" class="btn btn-secondary clarify-prev">←</button>` +
            `<button type="button" class="btn btn-secondary clarify-next">→</button>` +
            `<button type="button" class="btn btn-primary clarify-send" disabled>Enviar respostas</button></div></div>`;

        const body = card.querySelector('.clarify-body');
        let active = 0;

        const isAnswered = (i) => {
            const q = questions[i], a = card._answers[i];
            if (q.type === 'open') return (a.text || '').trim() !== '';
            if (q.type === 'multi') return a.values.length > 0 && (!a.values.includes('other') || (a.other || '').trim() !== '');
            return a.value !== null && (a.value !== 'other' || (a.other || '').trim() !== '');
        };
        const updateChecks = () => {
            const tabsEls = card.querySelectorAll('.clarify-tab');
            questions.forEach((q, i) => tabsEls[i].classList.toggle('done', isAnswered(i)));
            card.querySelector('.clarify-send').disabled = !questions.every((q, i) => isAnswered(i));
        };
        const renderQ = (i) => {
            active = i;
            const q = questions[i], a = card._answers[i];
            let html = `<div class="clarify-q">${esc(q.question)}</div>`;
            if (q.type === 'open') {
                html += `<textarea class="clarify-open" rows="3" placeholder="Sua resposta…">${esc(a.text || '')}</textarea>`;
            } else {
                const multi = q.type === 'multi';
                (q.options || []).forEach((opt, oi) => {
                    const sel = multi ? a.values.includes(oi) : a.value === oi;
                    html += `<label class="clarify-opt${sel ? ' sel' : ''}" data-oi="${oi}"><span class="clarify-opt-mark">${multi ? '☑' : '◉'}</span><span class="clarify-opt-body"><span class="clarify-opt-label">${esc(opt.label)}</span>${opt.description ? `<span class="clarify-opt-desc">${esc(opt.description)}</span>` : ''}</span></label>`;
                });
                const oSel = multi ? a.values.includes('other') : a.value === 'other';
                html += `<label class="clarify-opt${oSel ? ' sel' : ''}" data-oi="other"><span class="clarify-opt-mark">${multi ? '☑' : '◉'}</span><span class="clarify-opt-body"><span class="clarify-opt-label">Outro</span><input type="text" class="clarify-other" placeholder="escreva…" value="${esc(a.other || '')}"></span></label>`;
            }
            body.innerHTML = html;
            if (q.type === 'open') {
                body.querySelector('.clarify-open').addEventListener('input', e => { a.text = e.target.value; updateChecks(); });
            } else {
                body.querySelectorAll('.clarify-opt').forEach(lbl => {
                    lbl.addEventListener('click', (e) => {
                        if (e.target.classList.contains('clarify-other')) return;
                        const raw = lbl.getAttribute('data-oi');
                        const key = (raw === 'other') ? 'other' : parseInt(raw, 10);
                        if (q.type === 'multi') {
                            const idx = a.values.indexOf(key);
                            if (idx >= 0) a.values.splice(idx, 1); else a.values.push(key);
                        } else { a.value = key; }
                        renderQ(i); updateChecks();
                    });
                });
                const oth = body.querySelector('.clarify-other');
                if (oth) oth.addEventListener('input', e => {
                    a.other = e.target.value;
                    if (q.type === 'single') a.value = 'other';
                    else if (!a.values.includes('other')) a.values.push('other');
                    updateChecks();
                });
            }
            card.querySelectorAll('.clarify-tab').forEach((t, ti) => t.classList.toggle('active', ti === i));
            card.querySelector('.clarify-progress').textContent = `Pergunta ${i + 1} de ${questions.length}`;
        };

        card.querySelectorAll('.clarify-tab').forEach(t => t.addEventListener('click', () => renderQ(parseInt(t.getAttribute('data-i'), 10))));
        card.querySelector('.clarify-prev').addEventListener('click', () => renderQ(Math.max(0, active - 1)));
        card.querySelector('.clarify-next').addEventListener('click', () => renderQ(Math.min(questions.length - 1, active + 1)));
        card.querySelector('.clarify-send').addEventListener('click', () => this.submitClarifyAnswers(card, questions));

        this.messagesContainer.appendChild(card);
        renderQ(0); updateChecks();
        this.scrollToBottom();
    }

    submitClarifyAnswers(card, questions) {
        const lines = ['Respostas às suas perguntas:', ''];
        questions.forEach((q, i) => {
            const a = card._answers[i];
            let val = '';
            if (q.type === 'open') val = (a.text || '').trim();
            else if (q.type === 'multi') val = a.values.map(v => v === 'other' ? (a.other || '').trim() : ((q.options[v] || {}).label || '')).filter(Boolean).join(', ');
            else val = a.value === 'other' ? (a.other || '').trim() : ((q.options[a.value] || {}).label || '');
            // Resposta AUTOEXPLICATIVA: inclui a pergunta, para o histórico ficar claro mesmo após
            // o card sumir (e o arquiteto manter o contexto completo nos próximos turnos).
            lines.push((i + 1) + '. ' + q.question);
            lines.push('   → ' + val);
        });
        const btn = card.querySelector('.clarify-send');
        if (btn) { btn.disabled = true; btn.textContent = 'Enviando…'; }
        card.querySelectorAll('.clarify-tab,.clarify-prev,.clarify-next').forEach(b => b.disabled = true);
        this.appendSystemLine('Respostas enviadas — montando o plano.');
        this.send({ type: 'send_message', conversation_uuid: this.conversationUuid, content: lines.join('\n') });
        this.setProcessing(true);
        card.remove();
    }

    // Os estilos do questionário ficam em /assets/css/chat.css (design system d2).
    injectClarifyStyles() {}

    // ==================== Painel de arquivos (direita) ====================

    // Ao (re)carregar a conversa: popula o painel de arquivos e restaura o card de preview.
    // (O painel não populava no refresh — só em eventos ao vivo — por isso parecia "sem arquivos".)
    loadWorkspaceState() {
        // Sem throttle: roda no init E após cada loadMessages, garantindo que o card de
        // entrega/preview seja (re)renderizado SEMPRE no fim do thread, em qualquer timing.
        // São poucos fetches leves, só no carregamento da conversa.
        this.refreshFiles(true);
        // Card de entrega in-thread: cria no fim do thread se o projeto já foi entregue.
        if (this._delivered) this.ensureDeliveryBanner(); else this.wireDeliveryBanner();
        this.checkMaestroStatus();
        this.checkScreencastStatus(); // reidrata o card "Navegador ao vivo" se o QA estiver rodando
    }

    // Restaura o card de aprovação (efêmero) se houver um plano aguardando aprovação.
    checkMaestroStatus() {
        if (!this.projectUuid || !this.conversationUuid) return;
        fetch('/chat/' + this.projectUuid + '/maestro/status?c=' + encodeURIComponent(this.conversationUuid),
            { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
            .then(r => r.ok ? r.json() : null)
            .then(resp => {
                const d = (resp && resp.data) ? resp.data : resp;
                if (d && d.awaiting_clarification && Array.isArray(d.questions) && d.questions.length) {
                    this.renderClarifyCard({ questions: d.questions });
                } else if (d && d.awaiting_approval) {
                    this.renderPlanCard({
                        message: 'O plano está pronto (veja acima). Clique em **Aprovar** para eu desenvolver até a entrega — ou digite aqui um ajuste.',
                        files: Array.isArray(d.files) ? d.files : []
                    });
                }
            }).catch(() => {});
    }

    refreshFiles(isFinal = false) {
        if (!this.projectUuid) return;
        // Throttle leve para não buscar a cada passo.
        const now = Date.now();
        if (!isFinal && this._lastFilesFetch && (now - this._lastFilesFetch) < 2500) return;
        this._lastFilesFetch = now;

        fetch('/chat/' + this.projectUuid + '/files', { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
            .then(r => r.ok ? r.json() : null)
            .then(resp => {
                if (!resp) return;
                const data = resp.data ? resp.data : resp;
                const files = Array.isArray(data.files) ? data.files : [];
                this.currentZip = data.zip || null;
                this.renderFilesTree(files);
                this.updateFilesCount(files.length);
                this.updateFilesTotal(data.total_size || 0, files.length);
                this.wireDownloadAll();
                // O banner "Projeto entregue" NÃO deve aparecer só porque há arquivos no
                // workspace (ex.: PLAN.md/PROGRESS.md no estágio de plano, ou entrega antiga de
                // outra conversa do mesmo projeto). Só quando ESTA conversa realmente entregou.
                if (this._delivered) {
                    this.ensureDeliveryBanner();
                }
            })
            .catch(() => {});
    }

    renderFilesTree(files) {
        const tree = document.getElementById('files-tree');
        if (!tree) return;
        if (!files.length) { tree.innerHTML = '<div style="padding:1rem;color:var(--d2-text-muted);font-size:.85rem">Nenhum arquivo gerado ainda.</div>'; return; }

        const root = {};
        files.forEach(f => {
            const parts = String(f.path).split('/');
            let cur = root;
            parts.forEach((part, i) => {
                if (!cur[part]) cur[part] = { _children: {}, _file: false };
                if (i === parts.length - 1) { cur[part]._file = true; cur[part]._path = f.path; cur[part]._size = f.size; }
                cur = cur[part]._children;
            });
        });
        tree.innerHTML = this.renderTreeNode(root, 0);

        tree.querySelectorAll('.file-row').forEach(row => {
            row.addEventListener('click', () => {
                const p = row.getAttribute('data-path');
                if (p) this.openFileModal(p);
            });
        });
    }

    renderTreeNode(node, depth) {
        const keys = Object.keys(node).sort((a, b) => {
            const fa = node[a]._file, fb = node[b]._file;
            if (fa !== fb) return fa ? 1 : -1;
            return a.localeCompare(b);
        });
        let html = '';
        keys.forEach(k => {
            const n = node[k];
            const pad = 'padding-left:' + (8 + depth * 14) + 'px';
            if (n._file) {
                // Só a INDENTAÇÃO fica inline; largura/hover/clique da LINHA INTEIRA vêm do CSS (.file-row).
                html += '<div class="file-row" data-path="' + this.escapeHtml(n._path) + '" data-tooltip="Abrir ' + this.escapeHtml(n._path) + '" '
                    + 'style="' + pad + '">'
                    + '<span class="fr-ic">📄</span><span class="fr-name">' + this.escapeHtml(k) + '</span>'
                    + '<span class="fr-size">' + this._fmtSize(n._size || 0) + '</span></div>';
            } else {
                html += '<div style="' + pad + ';display:flex;align-items:center;gap:.4rem;font-weight:600;opacity:.85;padding-top:.35rem;font-size:.85rem">'
                    + '<span>📁</span><span>' + this.escapeHtml(k) + '</span></div>';
                html += this.renderTreeNode(n._children, depth + 1);
            }
        });
        return html;
    }

    _fmtSize(n) {
        n = n || 0;
        if (n < 1024) return n + ' B';
        if (n < 1048576) return (n / 1024).toFixed(1) + ' KB';
        if (n < 1073741824) return (n / 1048576).toFixed(1) + ' MB';
        return (n / 1073741824).toFixed(2) + ' GB';
    }

    // Mostra "N arquivos · TAMANHO no disco" no topo do painel de arquivos.
    updateFilesTotal(totalSize, count) {
        const el = document.getElementById('files-total');
        if (!el) return;
        const parts = [];
        if (typeof count === 'number') parts.push(count + ' arquivo' + (count === 1 ? '' : 's'));
        if (totalSize) parts.push(this._fmtSize(totalSize) + ' no disco');
        el.textContent = parts.join(' · ');
    }

    updateFilesCount(n) {
        const badge = document.getElementById('files-count');
        if (badge) {
            badge.textContent = String(n);
            badge.style.display = n > 0 ? '' : 'none';
        }
    }

    wireDownloadAll() {
        const btn = document.getElementById('download-all');
        if (!btn) return;
        if (this.currentZip) {
            btn.disabled = false;
            btn.onclick = () => { window.location = '/chat/' + this.projectUuid + '/download?file=' + encodeURIComponent(this.currentZip); };
        } else {
            btn.disabled = true;
            btn.onclick = null;
        }
    }

    openFilesPanel() {
        const c = document.querySelector('.chat-container');
        if (c) c.classList.add('files-open');
    }

    renderDeliveryCard(fileCount) {
        if (!this.messagesContainer) return;
        this.messagesContainer.querySelectorAll('.delivery-card').forEach(el => el.remove()); // idempotente
        const card = document.createElement('div');
        card.className = 'delivery-card';
        card.style.cssText = 'margin:.75rem 0 .75rem 2.5rem;padding:1rem 1.15rem;background:var(--d2-bg-subtle);border:1px solid var(--d2-border);border-left:3px solid var(--d2-ok);border-radius:12px';
        const zipBtn = this.currentZip
            ? '<button class="btn btn-primary" type="button" id="delivery-zip-btn">Baixar projeto (.zip)</button>'
            : '';
        card.innerHTML = '<div style="font-weight:600;margin-bottom:.5rem">Projeto entregue</div>'
            + '<div style="font-size:.9rem;color:var(--d2-text-muted);margin-bottom:.8rem">' + fileCount + ' arquivo(s) gerado(s). Veja tudo no painel à direita.</div>'
            + '<div style="display:flex;gap:.6rem;flex-wrap:wrap">' + zipBtn
            + '<button class="btn btn-secondary" type="button" id="delivery-files-btn">Ver arquivos</button></div>';
        this.messagesContainer.appendChild(card);
        const zb = card.querySelector('#delivery-zip-btn');
        if (zb) zb.addEventListener('click', () => { window.location = '/chat/' + this.projectUuid + '/download?file=' + encodeURIComponent(this.currentZip); });
        const fb = card.querySelector('#delivery-files-btn');
        if (fb) fb.addEventListener('click', () => this.openFilesPanel());
        this.scrollToBottom();
    }

    // ==================== Modal de código (ver/editar/salvar/baixar) ====================

    openFileModal(path) {
        const modal = document.getElementById('code-modal');
        if (!modal) return;
        const titleEl = modal.querySelector('#code-modal-title');
        const areaEl = modal.querySelector('#code-modal-area');
        const infoEl = modal.querySelector('#code-modal-info');
        const saveBtn = modal.querySelector('#code-modal-save');

        this._modalPath = path;
        this._modalDirty = false;
        titleEl.textContent = path;
        areaEl.value = 'Carregando…';
        areaEl.readOnly = true;
        if (saveBtn) saveBtn.disabled = true;
        if (infoEl) infoEl.textContent = '';
        modal.classList.add('open');
        document.body.style.overflow = 'hidden';

        fetch('/chat/' + this.projectUuid + '/view?file=' + encodeURIComponent(path), { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
            .then(r => r.ok ? r.json() : null)
            .then(resp => {
                const data = resp && resp.data ? resp.data : resp;
                if (!data) { areaEl.value = 'Erro ao carregar o arquivo.'; return; }
                if (data.binary) {
                    areaEl.value = '[' + (data.reason || 'Arquivo binário') + ']\n\nUse o botão Baixar para obtê-lo.';
                    areaEl.readOnly = true;
                    return;
                }
                areaEl.value = data.content || '';
                areaEl.readOnly = false;
                if (saveBtn) saveBtn.disabled = true;
                this._modalDirty = false;
            })
            .catch(() => { areaEl.value = 'Erro ao carregar o arquivo.'; });
    }

    markModalDirty() {
        this._modalDirty = true;
        const saveBtn = document.querySelector('#code-modal-save');
        if (saveBtn) saveBtn.disabled = false;
        const infoEl = document.querySelector('#code-modal-info');
        if (infoEl) infoEl.textContent = '● alterações não salvas';
    }

    saveFileModal() {
        if (!this._modalPath) return;
        const areaEl = document.querySelector('#code-modal-area');
        const infoEl = document.querySelector('#code-modal-info');
        const saveBtn = document.querySelector('#code-modal-save');
        const content = areaEl ? areaEl.value : '';
        const token = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

        if (saveBtn) saveBtn.disabled = true;
        if (infoEl) infoEl.textContent = 'salvando…';

        fetch('/chat/' + this.projectUuid + '/save', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': token },
            credentials: 'same-origin',
            body: JSON.stringify({ file: this._modalPath, content: content, _token: token })
        })
            .then(r => r.ok ? r.json() : Promise.reject())
            .then(() => {
                this._modalDirty = false;
                if (infoEl) infoEl.textContent = '✓ salvo';
                window.D2UI.toast('Arquivo salvo.', { tipo: 'ok' });
                this.refreshFiles();
            })
            .catch(() => {
                if (saveBtn) saveBtn.disabled = false;
                if (infoEl) infoEl.textContent = 'erro ao salvar';
                window.D2UI.toast('Erro ao salvar o arquivo.', { tipo: 'erro' });
            });
    }

    downloadFileModal() {
        if (this._modalPath) window.location = '/chat/' + this.projectUuid + '/download?file=' + encodeURIComponent(this._modalPath);
    }

    closeFileModal() {
        if (this._modalDirty) {
            window.D2UI.confirmar({ titulo: 'Descartar alterações', texto: 'Você editou o arquivo e não salvou. As alterações serão perdidas. Fechar mesmo assim?', perigo: true, rotuloOk: 'Descartar' })
                .then((ok) => { if (ok) this._doCloseFileModal(); });
            return;
        }
        this._doCloseFileModal();
    }

    _doCloseFileModal() {
        const modal = document.getElementById('code-modal');
        if (modal) modal.classList.remove('open');
        document.body.style.overflow = '';
        this._modalPath = null;
        this._modalDirty = false;
    }

    appendToolLine(kind, name, payload) {
        if (!this.messagesContainer) return;
        this.hideWelcome();
        const el = document.createElement('div');
        el.className = 'message message-tool';
        el.style.cssText = 'font-size:.8rem;padding:.45rem .65rem;margin:.2rem 0;background:var(--d2-bg-subtle);border:1px solid var(--d2-border);border-radius:8px;font-family:var(--d2-font-mono)';
        let body;
        if (kind === 'start') {
            body = '🔧 <strong>' + this.escapeHtml(name) + '</strong>';
            if (payload && typeof payload === 'object') {
                body += ' <span style="opacity:.7">' + this.escapeHtml(JSON.stringify(payload).slice(0, 200)) + '</span>';
            }
        } else {
            body = '<span style="opacity:.85">' + this.escapeHtml(String(payload || '').slice(0, 1500)) + '</span>';
        }
        el.innerHTML = body;
        this.messagesContainer.appendChild(el);
        this.scrollToBottom();
    }

    updateConversationStatus(data) {
        const statusBadge = document.getElementById('chat-status');
        if (statusBadge) {
            statusBadge.className = `status-badge status-${data.status}`;
            statusBadge.textContent = {
                'pending': 'Pendente',
                'running': 'Em execução',
                'completed': 'Concluído',
                'failed': 'Falhou',
                'cancelled': 'Cancelado'
            }[data.status] || data.status;
        }
    }

    showTyping(show = true) {
        if (this.typingIndicator) {
            // Garante que o indicador "pensando" fique no FIM (abaixo das mensagens), não no topo.
            if (show && this.messagesContainer) {
                this.messagesContainer.appendChild(this.typingIndicator);
            }
            this.typingIndicator.style.display = show ? 'flex' : 'none';
            if (show) this.scrollToBottom();
        }
    }

    setProcessing(processing) {
        this.isProcessing = processing;

        // Voltou a trabalhar → a entrega anterior não vale mais: esconde a tarja "Projeto entregue".
        // Ela reaparece ao concluir (preview_available numa entrega nova, ou task_completed se já existia).
        if (processing) { this._delivered = false; this.removeDeliveryBanner(); }

        // Sincroniza o badge "Trabalhando" da lista lateral + status do header AO VIVO.
        if (processing) this.markChatWorking(); else this.markChatIdle();
        this.renderWorkTotal(); // tempo e dirigido pelo servidor (evento work_time a cada segundo)

        // Começou a processar → mostra já a linha "Opus 4.8 pensando" (nunca tela vazia).
        // Parou → limpa a fila de agentes do chat.
        if (processing) this.ensureThinkingRow('pensando…'); else this.clearAgentsPanel();

        // Polling do painel de arquivos enquanto a tarefa roda (mantém o badge sincronizado).
        if (processing) {
            if (!this._filesPoll) {
                this._filesPoll = setInterval(() => this.refreshFiles(), 3000);
            }
        } else if (this._filesPoll) {
            clearInterval(this._filesPoll);
            this._filesPoll = null;
            this.refreshFiles(); // uma atualização final
        }
        if (!processing) {
            this.clearLiveStatus();
        }

        // Send e Pause: só UM visível por vez (antes ficavam os dois).
        if (this.sendButton) {
            this.sendButton.disabled = processing;
            this.sendButton.style.display = processing ? 'none' : 'inline-flex';
        }

        if (this.messageInput) {
            this.messageInput.disabled = processing;
        }

        // Os 3 pontinhos (🤖) ficaram redundantes: a fila "Opus 4.8 trabalhando" já indica o
        // processamento. Mantém sempre escondido.
        this.showTyping(false);

        const cancelBtn = document.getElementById('cancel-task');
        if (cancelBtn) {
            cancelBtn.style.display = processing ? 'inline-flex' : 'none';
        }
    }

    // Rola pro fim só se o usuário está "grudado" no fim (ou se forçado, ex.: ao enviar msg).
    scrollToBottom(force = false) {
        if (!this.messagesContainer) return;
        if (force || this._stick !== false) {
            this.messagesContainer.scrollTop = this.messagesContainer.scrollHeight;
            this._stick = true;
        }
    }

    // O usuário está perto do fim do chat? (tolerância de 140px)
    isAtBottom() {
        const c = this.messagesContainer;
        if (!c) return true;
        return (c.scrollHeight - c.scrollTop - c.clientHeight) < 140;
    }

    // Insere um elemento no chat MANTENDO a fila de agentes por último, SEM mover a fila
    // (inserir antes dela evita o "pulo" que mover o bloco inteiro causava).
    placeInChat(el) {
        const c = this.messagesContainer;
        if (!c) return;
        if (this.agentsRailEl && this.agentsRailEl.parentNode === c) {
            c.insertBefore(el, this.agentsRailEl);
        } else {
            c.appendChild(el);
        }
    }

    // ==================== Utilities ====================

    getToolIcon(toolName) {
        const icons = {
            'bash_execute': '⚡',
            'file_read': '📖',
            'file_write': '📝',
            'file_list': '📁',
            'web_search': '🔍',
            'web_fetch': '🌐',
            'browser_navigate': '🧭',
            'browser_screenshot': '📸',
            'browser_click': '👆',
            'browser_type': '⌨️',
            'python_execute': '🐍',
            'node_execute': '💚',
            'task_complete': '✅'
        };
        return icons[toolName] || '🔧';
    }

    formatTime(dateStr) {
        if (!dateStr) return '';
        const date = new Date(dateStr);
        return date.toLocaleTimeString('pt-BR', { 
            hour: '2-digit', 
            minute: '2-digit' 
        });
    }

    escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    extractTextFromHtml(html) {
        const div = document.createElement('div');
        div.innerHTML = html;
        return div.textContent || '';
    }

    renderMarkdown(text) {
        if (!text) return '';
        
        // Use marked if available, otherwise basic conversion
        if (window.marked) {
            return marked.parse(text);
        }

        // Basic markdown conversion
        return text
            // Code blocks
            .replace(/```(\w*)\n([\s\S]*?)```/g, '<pre><code class="language-$1">$2</code></pre>')
            // Inline code
            .replace(/`([^`]+)`/g, '<code>$1</code>')
            // Bold
            .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
            // Italic
            .replace(/\*([^*]+)\*/g, '<em>$1</em>')
            // Links
            .replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2" target="_blank">$1</a>')
            // Line breaks
            .replace(/\n/g, '<br>');
    }
}

// Export
window.RaviChat = RaviChat;
