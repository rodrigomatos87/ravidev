-- Fontes externas (SSH): autenticação por CHAVE como alternativa à senha.
-- A chave privada é cifrada (AES-256-GCM) e injetada SOMENTE no container daquele projeto/usuário
-- (~/.ravi/keys/<id>, chmod 600) e usada com `ssh -i ... -o IdentitiesOnly=yes` → nunca vaza p/
-- outro projeto (cada projeto injeta só as suas fontes).
ALTER TABLE project_ssh_sources
    ADD COLUMN IF NOT EXISTS auth_type VARCHAR(10) NOT NULL DEFAULT 'password',
    ADD COLUMN IF NOT EXISTS private_key_encrypted LONGTEXT NULL,
    ADD COLUMN IF NOT EXISTS key_passphrase_encrypted TEXT NULL;
