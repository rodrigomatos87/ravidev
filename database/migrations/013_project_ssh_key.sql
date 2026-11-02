-- Chave SSH ÚNICA por projeto: gerada sob demanda. A privada (cifrada) só é injetada nos
-- containers DESTE projeto; a pública é mostrada num comando para o usuário instalar no servidor.
ALTER TABLE projects
    ADD COLUMN IF NOT EXISTS ssh_private_key_encrypted LONGTEXT NULL,
    ADD COLUMN IF NOT EXISTS ssh_public_key TEXT NULL;
