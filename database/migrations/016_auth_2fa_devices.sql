-- #7/#8 — 2FA (TOTP) + dispositivos confiáveis (manter conectado / confiar no navegador).
ALTER TABLE users
    ADD COLUMN totp_secret VARCHAR(255) NULL AFTER remember_token,
    ADD COLUMN totp_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER totp_secret;

-- Dispositivos confiáveis: login persistente (cookie selector:validator) + pular 2FA no aparelho.
CREATE TABLE IF NOT EXISTS auth_devices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    selector CHAR(24) NOT NULL,
    validator_hash CHAR(64) NOT NULL,
    label VARCHAR(255) NULL,
    ip VARCHAR(45) NULL,
    user_agent VARCHAR(500) NULL,
    trusted_2fa TINYINT(1) NOT NULL DEFAULT 1,
    last_used_at TIMESTAMP NULL,
    expires_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_selector (selector),
    KEY idx_user (user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
