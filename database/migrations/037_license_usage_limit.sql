-- Teto de uso por licença para a IDE e o Dev Autônomo: percentual máximo das janelas do plano
-- (sessão de 5h / semana) que esses consumidores podem atingir. Ao chegar no teto, a IDE e o
-- Dev Autônomo param de consumir a licença até a janela renovar — a folga restante fica
-- reservada para a API (gateway), que nunca é limitada por esta coluna.
-- usage_limit_pct: 1–99. NULL = sem teto (comportamento de sempre).
ALTER TABLE claude_licenses
    ADD COLUMN IF NOT EXISTS usage_limit_pct TINYINT UNSIGNED NULL AFTER weekly_reset_hour;
