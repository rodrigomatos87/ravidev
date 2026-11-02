# Arquitetura

Como as peças conversam, fluxo por fluxo. Os caminhos citados são relativos à raiz do
repositório.

## Visão geral

| Processo | Entrada | Papel |
|---|---|---|
| PHP-FPM | `public/index.php` | painel: `src/bootstrap.php` carrega `.env`, configs, sessão e módulos; `src/routes.php` despacha para `src/Controllers/` |
| PHP-FPM (vhost da API) | `gateway-public/index.php` | Gateway de API isolado, sem sessão nem rotas do painel |
| WebSocket | `bin/websocket-server.php` | chat em tempo real (Ratchet, `src/WebSocket/`) |
| queue-worker | `bin/queue-worker.php` | executa os jobs de `job_queue` |
| scheduler | `bin/scheduler.php` (cron, 1 por minuto) | agendamentos e reconciliações |
| ravi-ide-proxy | `bin/ide-proxy.py` | troca o handle do container pela credencial Claude real |
| ravi-git-proxy | `bin/git-proxy.py` | troca o handle do container pelo token do GitHub do dono do projeto |
| containers | imagens de `docker/` | IDE (`ravi-ide`), execução do agente (`ravi-sandbox`), preview, Codex |

## Login e segundo fator

Código: `src/Controllers/AuthController.php`, `src/Services/AuthDeviceService.php`,
`src/Services/LoginThrottle.php`, `src/Services/TotpService.php`, `src/Services/EmailService.php`.

1. `POST /login` (com CSRF). `LoginThrottle` bloqueia o e-mail depois de tentativas erradas
   seguidas; conta inativa não entra.
2. Se o cookie de dispositivo (`AuthDeviceService::fromCookie`, formato seletor e validador em
   `auth_devices`) é do mesmo usuário **e** está marcado `trusted_2fa`, o login termina sem
   segundo fator. "Manter conectado" sozinho não basta: só vira confiável o navegador que já
   cumpriu o segundo fator.
3. Usuário com TOTP ativo vai para `/login/2fa` e digita o código do aplicativo.
4. Sem TOTP e com SMTP configurado: um código de 6 dígitos é enviado por e-mail, guardado na
   sessão só como SHA-256, vale 10 minutos e aceita 5 erros; `/login/2fa/resend` reenvia. Se o
   e-mail não sai, o login é negado.
5. Sem TOTP e sem SMTP o login termina só com a senha.
6. `completeLogin` regenera a sessão, grava `user_sessions` (com o `ws_token` usado pelo chat) e,
   se pedido, o dispositivo em `auth_devices`.

Recuperação de senha: `PasswordResetService` com tokens em `password_resets`. Sessões ficam em
`user_sessions` e podem ser revogadas pelo próprio usuário em Minha conta.

## Criação de projeto

Código: `ProjectController::store` e `ProjectController` (importação), `src/Models/Project.php`,
`src/Services/ProjectImportService.php`.

1. Valida nome, descrição, tipo (`web`, `app` ou `both`), ícone e cor e cria a linha em
   `projects`.
2. Cria o workspace em `storage/sandbox/<uuid>/` (configurável em `config/sandbox.php`).
3. Gera a chave SSH própria do projeto (`Project::ensureSshKey`), usada nas fontes externas.
4. Origem GitHub: liga o repositório ao projeto e enfileira `process_project_import` com o
   token do dono. Origem `.zip`: o arquivo é recebido e o mesmo job organiza o conteúdo. O
   queue-worker clona ou extrai e prepara o ambiente.

Membros (`project_members`), memórias (`project_memories`), servidores externos por SSH
(`project_ssh_sources`) e links (`project_links`) ficam em telas próprias do projeto.

## IDE no navegador e proxy de credencial

Código: `src/Services/IdeService.php`, `public/ide-auth.php`, `bin/ide-proxy.py`,
`bin/ide-proxy-token.php`, `bin/git-proxy.py`, `bin/git-proxy-token.php`,
`src/Services/IdeSshAgentService.php`, `docker/Dockerfile.ide`, `docker/ide-entrypoint.sh`.

1. `IdeService::start` escolhe uma porta livre entre `IDE_PORT_MIN` e `IDE_PORT_MAX` (padrão
   21100 a 21199), gera um token e sobe `ravi-ide-<token>` a partir de `ravi-ide:latest`, com o
   workspace montado em `/workspace` e a porta publicada só em `127.0.0.1`. A sessão vai para
   `ide_sessions`.
2. Credencial Claude: o container recebe um **handle** opaco em `CLAUDE_CODE_OAUTH_TOKEN` (no
   formato que o CLI aceita) e `ANTHROPIC_BASE_URL=IDE_PROXY_URL`. O handle fica em
   `ide_proxy_handles` ligado à licença e ao usuário. O `ide-proxy.py` recebe a chamada do
   `claude`, resolve o handle pelo `ide-proxy-token.php` (que decifra a licença) e troca só o
   cabeçalho `Authorization`. Licença no teto de uso configurado: o resolvedor sai com código 3
   e o proxy responde 429.
3. Git: dentro do container o `github.com` é reescrito para o `git-proxy.py`, que recebe o
   mesmo handle como senha. O `git-proxy-token.php` confere handle vivo, sessão de IDE, projeto,
   repositório igual ao do projeto e ator dono ou membro; então repassa com o token do dono.
   Leitura não autorizada segue sem credencial (repositórios públicos); escrita não autorizada
   recebe 403.
4. SSH: `IdeSshAgentService` mantém um `ssh-agent` por container no host (pasta
   `IDE_SSH_AGENT_DIR`); só o socket é montado, a chave nunca entra.
5. Módulos podem acrescentar variáveis e volumes pelo gancho `ide.container.env`.
6. `regenNginx` grava `storage/ide-nginx.json` e chama `IDE_NGINX_TRIGGER`, que reescreve o
   snippet do nginx (ver `docs/instalacao.md`).
7. Cada acesso a `/ide/<token>/` passa pelo `auth_request` em `public/ide-auth.php`: cookie de
   sessão válido em `user_sessions`, token `running` em `ide_sessions`, sessão da IDE do próprio
   usuário e usuário dono ou membro do projeto. Também renova o `last_used_at`.
8. `bin/cleanup-ides.php` pausa IDEs ociosas e remove as antigas; o scheduler reamarra os
   handles vivos à licença atual (`IdeService::reconcileClaudeHandles`).

O tempo de trabalho na IDE é somado em `ide_work_seconds`.

## Chat, Dev Autônomo (Maestro) e queue-worker

Código: `src/WebSocket/ChatHandler.php`, `src/WebSocket/WebSocketServer.php`,
`bin/queue-worker.php`, `src/Services/MaestroService.php`, `src/Services/ClaudeCodeService.php`,
`src/Agent/SpecialistRegistry.php`, `src/Services/QaVisualService.php`.

### Transporte

1. A página do chat abre o WebSocket em `/ws` e se autentica com o `ws_token` da sessão
   (`user_sessions.ws_token`).
2. `send_message` grava a mensagem, marca a conversa como `running` e enfileira
   `process_agent_task` em `job_queue`. Mensagem enviada com a tarefa em andamento é anotada
   para o Maestro ler entre as etapas.
3. O queue-worker executa o job e grava cada evento em `agent_events`. O servidor WebSocket
   drena essa tabela (`drainAgentEvents`) e entrega ao navegador, então o chat sobrevive a
   recarregar a página.
4. `tickWorkSeconds` soma 1 segundo por vez em `conversations.work_seconds` para cada conversa
   `running` e transmite o tempo ao chat.

### Executor

O queue-worker escolhe, nesta ordem: `MaestroService` se `MAESTRO_ENABLED`; senão
`ClaudeCodeService` puro se `CLAUDE_CODE_ENABLED`; senão o agente legado (`AgentService` ou
`MultiAgentService`).

`ClaudeCodeService` roda `claude -p --output-format stream-json` num `docker run --rm` da imagem
`ravi-sandbox` (usuário `sandbox`, sistema de arquivos só leitura, `/tmp` em tmpfs, workspace
montado em `/workspace`). `runMany` dispara ondas paralelas limitadas por
`SPECIALISTS_MAX_PARALLEL`.

### Maestro

1. `runTask` chama `decideEntry`: conversar ou planejar. Depois de uma entrega, mensagens novas
   vão para `runFollowup` (ajuste aplicado na hora, resposta, ou plano novo se o pedido for
   grande).
2. `doPlan` abre a sessão do Claude (`--session-id`) e gera o `PLAN.md`, que precisa de
   aprovação do usuário no chat.
3. Aprovado, `planWave` retoma a sessão (`--resume`) e decide as ondas. Cada especialista do
   `SpecialistRegistry` roda como `--resume <id> --fork-session`, herdando o contexto.
4. Portões de verificação (`verifyGate`) para construção, testes, segurança e entrega, com
   tentativas limitadas por `MAESTRO_MAX_ATTEMPTS` e ondas por `MAESTRO_MAX_WAVES`. Em entrega
   web, `QaVisualService` testa no navegador real (`MAESTRO_QA_VISUAL`).
5. Estado em `/workspace/.maestro/conv-<id>.json`; transcripts em
   `storage/claude-sessions/<convId>/`; com `CLAUDE_CODE_DEBUG_PROMPTS`, o que cada agente
   recebeu fica em `.maestro/debug/`.
6. Preview: quando `.maestro/app.json` descreve uma aplicação web, `PreviewService` sobe um
   container persistente na rede `PREVIEW_NET`, com banco no `ravi-preview-db`, porta entre
   `PREVIEW_PORT_MIN` e `PREVIEW_PORT_MAX`, servido em `/p/<token>/` (tabela `previews`).

Outros jobs do worker: `process_project_import`, `send_email`, `cleanup_sandbox`, `git_sync`,
`git_revert` e `server_deploy`. Ao subir, o worker reabre jobs que ficaram presos.

## Licenças e roteamento

Código: `src/Services/LicenseService.php`, `src/Services/LicenseUsageService.php`,
`src/Services/ChatgptLicenseService.php`, `src/Services/ModelService.php`.

- `claude_licenses` guarda as credenciais cifradas e os escopos que cada uma atende: `api`
  (Gateway), `ide` e `autonomous` (Dev Autônomo). A licença de um usuário pode ser fixada por
  escopo; sem isso vale a global do escopo.
- Gateway: modo em `app_settings.api_license_mode`: `off` (só a licença da API), `failover`
  (principal e backups em ordem) ou `balance` (duas licenças dividindo a carga).
- Dev Autônomo: `autonomous_license_mode` em `off` ou `balance` (agentes sorteados entre as
  licenças marcadas, com período de espera quando uma bate o limite).
- `LicenseUsageService` lê o uso de cada licença (janelas de 5 horas e 7 dias) pelos cabeçalhos
  `anthropic-ratelimit-unified-*`, com sondas pequenas e com o que o `ide-proxy` observa. O
  administrador pode definir tetos de uso por licença.
- `chatgpt_licenses` guarda contas ChatGPT; `ChatgptLicenseService` grava o `auth.json` do
  Codex nas IDEs e é reconciliado pelo scheduler.
- `ModelService` decide o modelo padrão de cada escopo.

## Gateway de API

Código: `gateway-public/index.php`, `src/Gateway/`. Referência de uso em
[API-GATEWAY.md](API-GATEWAY.md).

1. O vhost da API aponta para `gateway-public/`, que carrega só o necessário e chama
   `GatewayController::dispatch`.
2. `/v1/health` é público. As demais rotas exigem `x-api-key` (ou `Authorization: Bearer`),
   comparado por SHA-256 com `gw_tokens.token_hash` (token ativo e não revogado). Tokens são
   criados em Administração > Gateway de API com o prefixo `sk-ravidev-`.
3. `GatewayService` escolhe o backend: proxy fino para a Messages API da assinatura
   (`GatewayHttpService`, padrão; percorre a cadeia de licenças em 429), Claude Code CLI em
   container efêmero (`GatewayClaudeService`, reserva quando o proxy falha ou com
   `GW_PROXY_ENABLED=false`), Codex (`GatewayCodexService`, modelos `gpt-*`) ou DeepSeek
   (`GatewayDeepSeekService`, modelos `deepseek-*` ou regra do token em `gw_tokens.backend`).
4. `GatewaySlots` limita os containers simultâneos (`GW_MAX_CONCURRENCY` e faixa longa
   `GW_LONGAS_MAX`); sem vaga, 529.
5. Cada chamada vai para `gw_requests` com tokens, licença, backend e tempo. Campos extras por
   token ficam em `gw_token_fields`.
6. O endereço público vem de `GatewayService::urlPublica()`: `app_settings.gateway_public_url`,
   depois `GW_PUBLIC_URL`, depois `https://api.` mais o domínio do painel.

## Agendamentos

Código: `src/Services/ScheduleService.php`, `src/Controllers/ScheduleController.php`,
`bin/scheduler.php`.

Um agendamento (`schedules`) roda um comando no container do projeto ou envia um prompt ao
Claude, em horário marcado. O cron chama `bin/scheduler.php` a cada minuto, que executa
`ScheduleService::runDue` e grava cada execução em `schedule_runs`. O mesmo script reconcilia o
ssh-agent das IDEs, as contas ChatGPT e os handles de credencial.

## Módulos

`src/Core/Modulos.php` carrega `modulos/*/modulo.php` no bootstrap e injeta rotas, menu, views,
migrations e ganchos. Ver [modulos.md](modulos.md).

## Tabelas principais

| Tabela | Conteúdo |
|---|---|
| `users` | contas, papel, TOTP, token do GitHub cifrado |
| `user_sessions` | sessões ativas, com `ws_token` do chat |
| `auth_devices` | navegadores lembrados e se já cumpriram o segundo fator |
| `password_resets` | tokens de recuperação de senha |
| `user_prefs` | preferências por usuário |
| `projects`, `project_members` | projetos e quem tem acesso |
| `project_memories`, `project_links`, `project_ssh_sources`, `project_contexts` | memórias, ligações, servidores externos e contexto do projeto |
| `conversations`, `messages` | chat e histórico, com `work_seconds` |
| `agent_events` | fila de eventos do worker para o WebSocket |
| `job_queue` | jobs do queue-worker |
| `generated_files`, `execution_logs` | arquivos gerados e execuções de ferramentas |
| `ide_sessions`, `ide_proxy_handles`, `ide_work_seconds` | IDEs, handles de credencial e tempo de uso |
| `git_integration_handles` | handles de integração para o git-proxy |
| `previews`, `sandbox_instances` | previews e sandboxes |
| `claude_licenses`, `chatgpt_licenses` | credenciais cifradas |
| `usage_ledger`, `usage_statistics`, `api_usage` | consumo de tokens |
| `gw_tokens`, `gw_token_fields`, `gw_requests` | Gateway de API |
| `api_tokens` | tokens da API REST do painel |
| `schedules`, `schedule_runs` | agendamentos |
| `app_settings`, `system_settings` | configurações editadas no painel |
| `audit_logs` | auditoria |
| `user_whatsapp_*`, `whatsapp_notifications` | alertas por WhatsApp |
| `schema_migrations` | migrations aplicadas (gerida por `bin/db-init.php`) |

Tabelas de módulos opcionais são criadas pelas migrations do próprio módulo.
