-- Licença por token de API do gateway: cada cliente (sistemas que consomem o gateway) pode sair por uma
-- conta Max diferente, espalhando a carga. NULL = usa a licença GLOBAL da API (app_settings.license_api),
-- que por sua vez cai no token do .env. Aditivo e anulável.
ALTER TABLE gw_tokens
    ADD COLUMN license_id INT NULL AFTER is_active;
