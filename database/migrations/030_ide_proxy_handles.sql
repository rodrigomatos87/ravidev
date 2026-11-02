-- Handles opacos do PROXY da IDE. Estanca o vazamento de token: o container NUNCA recebe o
-- CLAUDE_CODE_OAUTH_TOKEN real — recebe só um HANDLE aleatório (formato sk-ant-oat01-… para o
-- CLI aceitar) + ANTHROPIC_BASE_URL apontando para o proxy do host (ravi-ide-proxy, systemd).
-- O proxy troca handle→token real (decrypt no PHP) e repassa o resto do request verbatim → a
-- Anthropic vê o request de assinatura perfeito que o próprio CLI monta (bucket preservado).
-- Vazar o handle ≠ vazar credencial: só serve via o proxy, do container, e é revogável aqui.
CREATE TABLE IF NOT EXISTS ide_proxy_handles (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    handle       VARCHAR(128) NOT NULL,
    license_id   INT NOT NULL,           -- licença que este handle resolve (claude_licenses.id)
    user_id      INT NOT NULL,
    container    VARCHAR(100) NOT NULL,  -- container dono do handle (auditoria/revogação)
    last_used_at TIMESTAMP NULL,
    revoked_at   TIMESTAMP NULL,         -- handle revogado → proxy recusa (401)
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_handle (handle),
    KEY idx_container (container)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
