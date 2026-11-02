-- IDE persistente (pausar em vez de destruir).
-- paused_at = quando a IDE foi PAUSADA (docker stop). Usado pelo reap de longo prazo do
-- cleanupIdle para remover apenas as pausadas abandonadas há muitos dias.
ALTER TABLE ide_sessions ADD COLUMN IF NOT EXISTS paused_at TIMESTAMP NULL DEFAULT NULL AFTER last_used_at;

-- Limpa linhas 'stopped' LEGADAS: antes desta mudança, stop() destruía o container E marcava
-- status='stopped'. Essas linhas apontam para containers que NÃO existem mais; com a nova lógica
-- de resume elas seriam confundidas com pausadas retomáveis. Remover também libera as portas que
-- elas reservavam (allocatePort agora conta running + stopped).
DELETE FROM ide_sessions WHERE status = 'stopped';
