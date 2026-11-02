-- Histórico detalhado por token + custo estimado correto.
--
-- (1) Conteúdo das chamadas: para o admin auditar "qual foi a chamada e qual foi
--     o retorno" de cada token (origem/IP + horário já existiam em created_at).
-- (2) Breakdown de tokens: o `input_tokens` existente soma prompt + cache do
--     scaffolding do Claude Code (~17,9k/chamada, cacheado). Para o custo estimado
--     refletir só o consumo REAL do cliente, guardamos o prompt real separado.

ALTER TABLE gw_requests
    ADD COLUMN client_ip          VARCHAR(45)  NULL        AFTER token_id,
    ADD COLUMN prompt_tokens      INT NOT NULL DEFAULT 0   AFTER output_tokens,
    ADD COLUMN cache_read_tokens  INT NOT NULL DEFAULT 0   AFTER prompt_tokens,
    ADD COLUMN cache_write_tokens INT NOT NULL DEFAULT 0   AFTER cache_read_tokens,
    ADD COLUMN request_text       MEDIUMTEXT   NULL        AFTER cache_write_tokens,
    ADD COLUMN response_text      MEDIUMTEXT   NULL        AFTER request_text;
