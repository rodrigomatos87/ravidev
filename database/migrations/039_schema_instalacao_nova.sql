-- Schema que o código usa e que nenhuma migration criava (as tabelas nasceram à mão no servidor).
-- Sem isto, uma instalação NOVA a partir das migrations não chega a um banco funcional.
-- Formato copiado de um banco em produção (MariaDB 10.11, conferido em 01/11/2026)
-- e cruzado com o código que lê e grava cada tabela. Tudo idempotente: num banco que já tem
-- as tabelas e colunas, nada muda (CREATE TABLE IF NOT EXISTS / ADD COLUMN IF NOT EXISTS,
-- sintaxe do MariaDB, que é o banco oficial: 10.6 ou superior).

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- app_settings: configurações chave/valor da aplicação.
-- Código: Models/Setting.php (SELECT skey, svalue / REPLACE INTO), LicenseService::freshSetting.
-- Guarda SMTP, WhatsApp, idioma, licenças padrão, chave cifrada da DeepSeek e CACHES em JSON
-- (LicenseUsageService, UsageService cost_ide_cache_json, fila de reservas do Gateway).
-- svalue é MEDIUMTEXT (produção usa TEXT, 64 KB): os caches JSON crescem com o número de licenças.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS app_settings (
    skey VARCHAR(100) NOT NULL,
    svalue MEDIUMTEXT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (skey)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- user_prefs: preferências por usuário (chave/valor).
-- Código: Models/UserPref.php (SELECT pref_key, pref_value WHERE user_id / REPLACE INTO
-- (user_id, pref_key, pref_value)); o REPLACE exige a chave primária (user_id, pref_key).
-- pref_value VARCHAR(255): tamanho definido pela 022 (ids de modelo, ids de licença, '0'/'1').
-- A 022 também cria esta tabela (ela faz ALTER e roda antes desta); aqui fica por garantia.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS user_prefs (
    user_id INT NOT NULL,
    pref_key VARCHAR(64) NOT NULL,
    pref_value VARCHAR(255) NULL,
    PRIMARY KEY (user_id, pref_key),
    CONSTRAINT fk_user_prefs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- previews: previews web/mobile publicados em /p/<token>/.
-- Código: Services/PreviewService.php (insert com token, project_uuid, conversation_id,
-- container_name, port, db_name, db_user, url, status, requires_login, demo_user, demo_password;
-- ORDER BY started_at; last_seen_at no touch/cleanupIdle), ChatController::mobileFrames,
-- AdminController, MonitoringService. token = bin2hex(8) (16 chars); 40 dá folga.
-- error_msg existe em produção e não é usada hoje; mantida para paridade.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS previews (
    id INT AUTO_INCREMENT PRIMARY KEY,
    token VARCHAR(40) NOT NULL,
    project_uuid VARCHAR(64) NOT NULL,
    conversation_id INT NULL,
    container_name VARCHAR(100) NOT NULL,
    port INT NOT NULL,
    db_name VARCHAR(64) NULL,
    db_user VARCHAR(64) NULL,
    url VARCHAR(255) NOT NULL,
    status ENUM('starting', 'running', 'stopped', 'error') DEFAULT 'starting',
    requires_login TINYINT(1) DEFAULT 0,
    demo_user VARCHAR(100) NULL,
    demo_password VARCHAR(100) NULL,
    error_msg TEXT NULL,
    started_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_previews_token (token),
    KEY idx_previews_project (project_uuid),
    KEY idx_previews_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- agent_events: fila de eventos do Dev Autônomo (queue-worker grava, servidor WebSocket drena).
-- Código: bin/queue-worker.php (INSERT conversation_id, payload = json_encode),
-- WebSocket/WebSocketServer::drainAgentEvents (SELECT id, payload ... ORDER BY id; DELETE id <= ?).
-- Sem FK de propósito: a fila é transitória e o worker não pode falhar por causa dela.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS agent_events (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    conversation_id INT NOT NULL,
    payload JSON NOT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_agent_events_conv (conversation_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- api_usage: uso diário da API antiga por usuário.
-- Código: Services/UsageService::apiUsage (só LEITURA: SUM(tokens_input), SUM(tokens_output),
-- filtro por user_id e date). Nenhum ponto do código grava nela hoje; existe para a consulta
-- não cair no catch. Formato igual ao de produção.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS api_usage (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    date DATE NOT NULL,
    tokens_input INT DEFAULT 0,
    tokens_output INT DEFAULT 0,
    requests_count INT DEFAULT 0,
    cost_usd DECIMAL(10,4) DEFAULT 0.0000,
    UNIQUE KEY uk_api_usage_user_date (user_id, date),
    CONSTRAINT fk_api_usage_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- users: papel, aprovação de cadastro e confirmação de e-mail.
-- role: 'user' | 'admin' (AdminMiddleware via User::isAdmin, AdminController::store/update
--   grava só 'admin' ou 'user', NotificationService busca role = 'admin').
-- approval_status: 'pending' | 'approved' | 'rejected' (AuthController::register grava 'pending',
--   AdminController::approveUser grava 'approved', AuthController::login trata 'rejected';
--   o código assume 'approved' quando ausente, por isso é o padrão).
-- email_verify_token: sha256 hex (64) do link de confirmação (AuthController::register/verifyEmail).
-- ---------------------------------------------------------------------------
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS role ENUM('user', 'admin') NOT NULL DEFAULT 'user' AFTER tokens_limit,
    ADD COLUMN IF NOT EXISTS approval_status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'approved' AFTER role,
    ADD COLUMN IF NOT EXISTS email_verify_token VARCHAR(64) NULL AFTER email_verified_at;

ALTER TABLE users
    ADD INDEX IF NOT EXISTS idx_users_email_verify_token (email_verify_token),
    ADD INDEX IF NOT EXISTS idx_users_role (role);

-- ---------------------------------------------------------------------------
-- messages.tokens_used: Message::createAssistantMessage grava (AgentService, MultiAgentService)
-- e message-bubble.php exibe. Existe em produção, faltava na 001.
-- ---------------------------------------------------------------------------
ALTER TABLE messages
    ADD COLUMN IF NOT EXISTS tokens_used INT DEFAULT 0 AFTER tokens_output;

-- ---------------------------------------------------------------------------
-- user_sessions.ws_token: token do WebSocket (64 hex de ws_token()), gravado pelo
-- AuthMiddleware (REPLACE INTO user_sessions ... ws_token) e buscado pelo ChatHandler.
-- ---------------------------------------------------------------------------
ALTER TABLE user_sessions
    ADD COLUMN IF NOT EXISTS ws_token VARCHAR(64) NULL AFTER last_activity,
    ADD INDEX IF NOT EXISTS idx_user_sessions_ws_token (ws_token);

-- ---------------------------------------------------------------------------
-- Reparo: project_ssh_sources.discovered_ips é criada pela 023, mas o banco de produção não
-- tem a coluna (a 023 não foi aplicada lá). ProjectSshSource lê e ProjectController grava.
-- No-op onde a 023 já rodou.
-- ---------------------------------------------------------------------------
ALTER TABLE project_ssh_sources
    ADD COLUMN IF NOT EXISTS discovered_ips TEXT NULL AFTER host;
