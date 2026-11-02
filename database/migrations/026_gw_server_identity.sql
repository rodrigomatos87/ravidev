-- Identidade do servidor Ravi de origem em cada requisição da API.
-- O cliente (sistemas consumidores) envia em cada chamada, além da credencial:
--   x-ravi-server-token : token do servidor (system.tokenRAVI)
--   x-ravi-server-ips   : IP(s) reais da máquina, lidos de /etc/network/interfaces (CSV)
-- Registrar isso é CONFIGURÁVEL por token (log_server_identity; default ligado).

ALTER TABLE gw_requests
    ADD COLUMN server_token VARCHAR(191) NULL AFTER client_ip,
    ADD COLUMN server_ips   VARCHAR(255) NULL AFTER server_token;

ALTER TABLE gw_tokens
    ADD COLUMN log_server_identity TINYINT(1) NOT NULL DEFAULT 1 AFTER license_id;

CREATE INDEX idx_gw_req_server_token ON gw_requests (server_token);
