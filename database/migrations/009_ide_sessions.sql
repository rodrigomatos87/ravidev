-- IDE no navegador (code-server por projeto). Registro das sessões ativas (1 por projeto),
-- espelhando o padrão da tabela `previews`. (2026-06-09)
CREATE TABLE IF NOT EXISTS ide_sessions (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    project_id    INT NOT NULL,
    token         VARCHAR(64) NOT NULL UNIQUE,
    container     VARCHAR(100) NOT NULL,
    port          INT NOT NULL,
    status        ENUM('running','stopped') NOT NULL DEFAULT 'running',
    last_used_at  TIMESTAMP NULL DEFAULT NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (project_id),
    INDEX (status)
);
