-- Renovação SEMANAL dos limites de cada licença Max: as assinaturas renovam a cota semanal num
-- dia da semana + hora fixos (informados pela própria Anthropic na conta). O admin cadastra
-- esse horário por licença para o painel mostrar quando cada uma volta a ter limite cheio.
-- weekly_reset_dow: 0=domingo … 6=sábado (convenção do PHP date('w')). NULL = não informado.
-- weekly_reset_hour: 0–23 (hora local do servidor). NULL = não informado.
ALTER TABLE claude_licenses
    ADD COLUMN IF NOT EXISTS weekly_reset_dow TINYINT NULL AFTER note,
    ADD COLUMN IF NOT EXISTS weekly_reset_hour TINYINT NULL AFTER weekly_reset_dow;
