-- Allow-list de modelos por token de API.
-- NULL/vazio = sem restrição (todos os modelos permitidos). Quando preenchido, é um
-- JSON com os ids de modelo permitidos (ex.: ["claude-opus-4-8","claude-haiku-4-5"]).
-- Uma chamada pedindo um modelo fora da lista é recusada com erro GENÉRICO (o motivo
-- fica só no log do servidor), igual ao gate de identidade.
ALTER TABLE gw_tokens
    ADD COLUMN allowed_models TEXT NULL AFTER log_server_identity;
