-- O histórico do gateway passa a guardar o max_tokens recebido no corpo da chamada,
-- para comparar com output_tokens (antes o teto era descartado e o CLI raciocinava
-- escondido: pedido de 300 tokens gastava 3.600 de saída). NULL = não informado.
ALTER TABLE gw_requests
    ADD COLUMN IF NOT EXISTS max_tokens INT UNSIGNED NULL AFTER cache_write_tokens;
