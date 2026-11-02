-- Licenças do ChatGPT (assinaturas Plus/Pro/Business/Enterprise) para o Codex — a extensão oficial
-- "Codex – OpenAI's coding agent" (openai.chatgpt) e o CLI `codex` na IDE. Cada linha guarda o
-- conteúdo do ~/.codex/auth.json de uma conta (tokens OAuth do "Sign in with ChatGPT"), CRIPTOGRAFADO
-- (AES-256-GCM com APP_KEY, mesma camada das licenças do Claude). O admin cadastra várias e escolhe
-- qual cada usuário usa na IDE: padrão global (app_settings: chatgpt_license_ide) + override por
-- usuário (user_prefs: chatgpt_license_ide). O Codex renova os tokens sozinho (~8 dias) e o
-- scheduler copia o auth.json renovado de volta para cá (last_refresh), senão a licença expiraria.
CREATE TABLE IF NOT EXISTS chatgpt_licenses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    auth_encrypted TEXT NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    note VARCHAR(255) NULL,
    account_email VARCHAR(255) NULL,
    plan_type VARCHAR(50) NULL,
    auth_mode VARCHAR(30) NULL,
    last_refresh DATETIME NULL,
    last_used_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
