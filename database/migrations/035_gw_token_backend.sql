-- Backend de execução por token do gateway. NULL/vazio = Max via CLI (o caminho
-- de sempre, nada muda para os tokens existentes); 'deepseek_haiku' roteia só a
-- família haiku para a DeepSeek; 'deepseek' roteia todas as chamadas do token.
-- A chave da API da DeepSeek fica cifrada em app_settings (deepseek_api_key).
ALTER TABLE gw_tokens ADD COLUMN backend VARCHAR(20) NULL AFTER allowed_models;

-- O log de consumo precisa aceitar o backend novo (o ENUM antigo truncava
-- 'deepseek' e a chamada ficava sem registro).
ALTER TABLE gw_requests MODIFY COLUMN backend ENUM('max','official','deepseek') NULL;
