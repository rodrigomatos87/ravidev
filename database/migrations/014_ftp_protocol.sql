-- #14 — Suporte a FTP além de SSH nos servidores remotos do projeto.
-- protocol = 'ssh' (padrão, com rsync/chave) ou 'ftp' (upload via FTP, só senha).
ALTER TABLE project_ssh_sources
    ADD COLUMN protocol VARCHAR(10) NOT NULL DEFAULT 'ssh' AFTER name;
