-- IDE por (projeto, USUÁRIO): cada usuário tem seu próprio container/sessão de VS Code.
-- Necessário p/ segurança: o token do GitHub do DONO só é injetado no container DELE; membros
-- têm o próprio container (sem o token do dono). (2026-06-09)
ALTER TABLE ide_sessions
  ADD COLUMN IF NOT EXISTS user_id INT NULL AFTER project_id,
  ADD INDEX idx_proj_user (project_id, user_id);
