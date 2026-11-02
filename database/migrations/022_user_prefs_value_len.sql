-- user_prefs.pref_value era VARCHAR(16): curto demais para guardar IDs de modelo
-- (ex.: "claude-sonnet-4-6" tem 17 chars). Alarga para caber prefs maiores.
-- Numa instalação nova a tabela ainda não existia (nasceu à mão no servidor): cria aqui,
-- já no formato final, para o ALTER abaixo não falhar. Mesmo formato da 039.
CREATE TABLE IF NOT EXISTS user_prefs (
    user_id INT NOT NULL,
    pref_key VARCHAR(64) NOT NULL,
    pref_value VARCHAR(255) NULL,
    PRIMARY KEY (user_id, pref_key),
    CONSTRAINT fk_user_prefs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE user_prefs MODIFY COLUMN pref_value VARCHAR(255) NULL;
