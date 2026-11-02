-- Campos personalizados por token de API do gateway.
-- Cada token define uma lista livre de campos de identidade (chave + rótulo + obrigatório).
-- O cliente envia cada campo no header x-ravi-<field_key> (hífens no lugar de _).
-- O gateway REJEITA a chamada se um campo obrigatório vier ausente/vazio, e registra
-- os valores recebidos em gw_requests.identity_json (respeitando log_server_identity).
--
-- Os dois campos de identidade já existentes (server_token, server_ips) são apenas
-- campos com essas chaves; clientes de monitoramento já os enviam hoje.

CREATE TABLE IF NOT EXISTS gw_token_fields (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    token_id   INT NOT NULL,
    field_key  VARCHAR(64)  NOT NULL,          -- ex.: server_token, server_ips, regiao
    label      VARCHAR(120) NOT NULL,
    required   TINYINT(1)   NOT NULL DEFAULT 0,
    sort_order INT          NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_token_field (token_id, field_key),
    KEY idx_gtf_token (token_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Todos os campos definidos recebidos naquela requisição (JSON {field_key: valor}).
ALTER TABLE gw_requests
    ADD COLUMN identity_json TEXT NULL AFTER server_ips;
