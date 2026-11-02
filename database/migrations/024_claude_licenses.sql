-- Licenças do Claude Code (assinaturas Max). Cada linha é um CLAUDE_CODE_OAUTH_TOKEN de uma conta
-- Max, guardado CRIPTOGRAFADO (AES-256-GCM com APP_KEY, mesma camada do api_key_encrypted). O admin
-- cadastra várias e escolhe qual licença cada consumidor usa (API/IDE/Dev autônomo) — global por
-- escopo (app_settings: license_api|license_ide|license_autonomous) e override por usuário
-- (user_prefs: license_ide|license_autonomous). A licença atual do .env é importada como "Licença atual".
CREATE TABLE IF NOT EXISTS claude_licenses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    token_encrypted TEXT NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    note VARCHAR(255) NULL,
    last_used_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
