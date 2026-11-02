-- Gateway de API compatível com a Anthropic (uso pessoal/intranet).
-- TOTALMENTE ISOLADO do produto: tabelas próprias com prefixo gw_, sem FKs para
-- projects/conversations/users. Gerenciado só pelo admin; servido por subdomínio dedicado.

-- Tokens de acesso ao gateway (formato sk-ravidev-<hex>). Guardamos só o hash sha256.
CREATE TABLE IF NOT EXISTS gw_tokens (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(120) NOT NULL,
    token_prefix  VARCHAR(40) NOT NULL,          -- ex.: sk-ravidev-ab12… (só para exibir na lista)
    token_hash    CHAR(64) NOT NULL,             -- sha256 do token completo
    is_active     TINYINT(1) NOT NULL DEFAULT 1,
    last_used_at  TIMESTAMP NULL,
    revoked_at    TIMESTAMP NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_gw_token_hash (token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Registro de cada requisição servida pelo gateway (para consumo/custo por token e por período).
-- Custo NÃO é gravado: é estimado na exibição via tarifa por modelo (re-tarifação retroativa).
CREATE TABLE IF NOT EXISTS gw_requests (
    id               BIGINT AUTO_INCREMENT PRIMARY KEY,
    token_id         INT NULL,                          -- NULL se token inválido (tentativa barrada)
    model_requested  VARCHAR(80) NULL,
    model_served     VARCHAR(80) NULL,
    backend          ENUM('max','official') NULL,        -- qual caminho de fato respondeu
    input_tokens     INT NOT NULL DEFAULT 0,
    output_tokens    INT NOT NULL DEFAULT 0,
    status           ENUM('ok','error') NOT NULL DEFAULT 'ok',
    error            VARCHAR(255) NULL,
    duration_ms      INT NOT NULL DEFAULT 0,
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_gw_req_token_created (token_id, created_at),
    KEY idx_gw_req_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
