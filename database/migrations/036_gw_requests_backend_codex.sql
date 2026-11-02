-- O log de consumo ganha o backend 'codex' (Codex CLI nas contas ChatGPT do
-- painel, cota do plano) — sem isto o ENUM truncaria e a chamada ficaria sem
-- registro, como aconteceu com 'deepseek' na 035.
ALTER TABLE gw_requests MODIFY COLUMN backend ENUM('max','official','deepseek','codex') NULL;
