-- Integração Git/GitHub (2026-06-09)
-- Conta GitHub por USUÁRIO (OAuth App clássico, token persistente cifrado AES-256-GCM)
-- + Git OPCIONAL por PROJETO (ligar repo existente / criar novo / auto-sync).
-- Idempotente (ADD COLUMN IF NOT EXISTS — MariaDB 10.11).

-- Usuário: conexão GitHub (token guardado cifrado, como api_key_encrypted)
ALTER TABLE users
  ADD COLUMN IF NOT EXISTS github_token_encrypted TEXT NULL,
  ADD COLUMN IF NOT EXISTS github_login VARCHAR(100) NULL,
  ADD COLUMN IF NOT EXISTS github_avatar_url VARCHAR(500) NULL,
  ADD COLUMN IF NOT EXISTS github_scope VARCHAR(255) NULL,
  ADD COLUMN IF NOT EXISTS github_connected_at TIMESTAMP NULL DEFAULT NULL;

-- Projeto: vínculo opcional com um repositório (1:1 em colunas, sem tabela nova)
ALTER TABLE projects
  ADD COLUMN IF NOT EXISTS git_enabled TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS git_repo_full_name VARCHAR(255) NULL,
  ADD COLUMN IF NOT EXISTS git_branch VARCHAR(120) NOT NULL DEFAULT 'main',
  ADD COLUMN IF NOT EXISTS git_auto_sync TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS git_last_synced_sha VARCHAR(64) NULL,
  ADD COLUMN IF NOT EXISTS git_last_synced_at TIMESTAMP NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS git_pending TINYINT(1) NOT NULL DEFAULT 0;
