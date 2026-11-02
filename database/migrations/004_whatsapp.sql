-- WhatsApp: conexão da conta do usuário (via servidor Go/whatsmeow) + destinos + preferências.
-- Portado do Ravi Mail e adaptado ao schema do Ravi Developer.

CREATE TABLE IF NOT EXISTS user_whatsapp_connections (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    user_id       INT NOT NULL,
    session_id    VARCHAR(120) NULL,
    status        ENUM('disconnected','qr_pending','connected') NOT NULL DEFAULT 'disconnected',
    qr_code_data  TEXT NULL,
    phone_number  VARCHAR(40) NULL,
    name          VARCHAR(120) NULL,
    connected_at  TIMESTAMP NULL,
    last_seen     TIMESTAMP NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_wa_conn_user (user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Destinos dos alertas: um contato (telefone) ou um grupo (ID terminado em @g.us).
CREATE TABLE IF NOT EXISTS user_whatsapp_alert_phones (
    id                     INT AUTO_INCREMENT PRIMARY KEY,
    user_id                INT NOT NULL,
    whatsapp_connection_id INT NOT NULL,
    phone                  VARCHAR(120) NOT NULL,
    label                  VARCHAR(120) NULL,
    created_at             TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_wa_phone_user (user_id),
    KEY idx_wa_phone_conn (whatsapp_connection_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Preferências por tipo de alerta (default = habilitado).
CREATE TABLE IF NOT EXISTS user_whatsapp_alert_preferences (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT NOT NULL,
    alert_type VARCHAR(60) NOT NULL,
    enabled    TINYINT(1) NOT NULL DEFAULT 1,
    UNIQUE KEY uq_wa_pref (user_id, alert_type),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Log de envios (diagnóstico).
CREATE TABLE IF NOT EXISTS whatsapp_notifications (
    id                     INT AUTO_INCREMENT PRIMARY KEY,
    user_id                INT NOT NULL,
    whatsapp_connection_id INT NULL,
    trigger_event          VARCHAR(60) NULL,
    phone                  VARCHAR(120) NULL,
    message                TEXT NULL,
    status                 ENUM('sent','failed') NOT NULL DEFAULT 'sent',
    external_message_id    VARCHAR(120) NULL,
    created_at             TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_wa_notif_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
