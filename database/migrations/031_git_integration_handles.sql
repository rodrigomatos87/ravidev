-- Handles de INTEGRAÇÃO do proxy git (bin/git-proxy.py, instância LAN ravi-git-proxy-lan):
-- deixa outro sistema seu (ex.: uma VM na mesma rede) usar git nos repos dos projetos
-- do RaviDev SEM nunca receber o token do GitHub — mesmo desenho dos handles da IDE
-- (ide_proxy_handles), mas sem exigir sessão de IDE: o escopo é a lista de projetos git
-- acessíveis ao usuário (ou uma lista explícita em `repos`). Vazar o handle ≠ vazar
-- credencial: só funciona via o proxy (firewall restringe a origem) e é revogável aqui.
CREATE TABLE IF NOT EXISTS git_integration_handles (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    handle       VARCHAR(128) NOT NULL,
    label        VARCHAR(100) NOT NULL,       -- quem consome (ex.: 'VM do site')
    user_id      INT NOT NULL,                -- ator: círculo de projetos/token DESTE usuário
    repos        TEXT NULL,                   -- JSON de full_names permitidos; NULL = todos os projetos git acessíveis
    can_write    TINYINT(1) NOT NULL DEFAULT 0, -- push permitido? (leitura sempre, dentro do escopo)
    last_used_at TIMESTAMP NULL,
    revoked_at   TIMESTAMP NULL,              -- revogado → proxy recusa na hora
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_handle (handle)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
