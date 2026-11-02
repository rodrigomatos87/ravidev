-- Histórico (ledger) de uso de tokens/custo — uma linha por tarefa processada.
-- Atribui o consumo ao usuário que EXECUTOU a tarefa (relevante p/ projetos compartilhados).
CREATE TABLE IF NOT EXISTS usage_ledger (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    project_id INT NULL,
    conversation_id INT NULL,
    model VARCHAR(60) NULL,
    input_tokens BIGINT NOT NULL DEFAULT 0,
    cache_write_tokens BIGINT NOT NULL DEFAULT 0,
    cache_read_tokens BIGINT NOT NULL DEFAULT 0,
    output_tokens BIGINT NOT NULL DEFAULT 0,
    cost_usd DECIMAL(12,6) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ledger_user (user_id),
    INDEX idx_ledger_project (project_id),
    INDEX idx_ledger_conversation (conversation_id),
    INDEX idx_ledger_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
