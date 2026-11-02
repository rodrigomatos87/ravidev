# RaviDev WhatsApp (servidor Go)

Servidor que conecta a conta de WhatsApp do usuário (via whatsmeow) e envia
os alertas do Ravi Developer para um contato ou grupo escolhido.

Portado do Ravi Mail. Endpoints (auth via header `X-API-Key`):
- `POST /conectar`        {usuario_id, conexao_id} → gera QR
- `GET  /status/{id}`     → desconectado | conectando | conectado (+numero, nome)
- `POST /enviar`          {conexao_id, telefone, mensagem} (telefone pode ser nº ou ID de grupo terminado em @g.us)
- `POST /desconectar`     {conexao_id}
- `GET  /health`

## Build
    cd whatsapp-go && go build -o ravidev-whatsapp .

## Rodar
Configurar `config.json` (porta 8090, api_key). Roda como serviço (supervisor).
As sessões do WhatsApp ficam em `sessions/user_X_conn_Y/session.db`.
