# API do Gateway

Gateway público no formato da **Messages API da Anthropic**, servido pelas licenças de
assinatura cadastradas no painel (Claude Code CLI em container efêmero, ver
`src/Gateway/GatewayClaudeService.php`). É uma alternativa à API oficial: o cliente troca
só o destino e o token.

- Endpoint: `https://api.seu-dominio.com.br` (o endereço configurado no painel; ver abaixo)
- Autenticação: header `x-api-key: sk-ravidev-...` (ou `Authorization: Bearer ...`).
  Tokens são criados no painel em **Admin → Gateway de API**.
- Header recomendado: `anthropic-version: 2023-06-01` (aceito e ignorado; mantém o
  cliente compatível com a API oficial).

## Endereço configurado no painel

O endereço público vem, nesta ordem: campo **Endereço do Gateway** em Admin, Gateway de API
(setting `gateway_public_url`); variável `GW_PUBLIC_URL` do `.env`; padrão `https://api.` +
domínio do painel. O código lê sempre `GatewayService::urlPublica()`.

O endereço precisa ser `https://` com domínio (sem caminho, sem IP). Para publicar um domínio novo:

1. Aponte o DNS (registro A) do domínio para o servidor.
2. `bin/instalar-modelos.sh --dominio-api <dominio>` (gera o site da API no servidor web).
3. `certbot certonly --nginx -d <dominio>` (certificado).

O botão **Verificar** chama `<endereço>/v1/health` e diz se o Gateway respondeu.

## Endpoints

| Método | Caminho | Descrição |
|---|---|---|
| GET | `/v1/health` | Health check (público, sem token) |
| GET | `/v1/models` | Catálogo de modelos (formato Anthropic) |
| GET | `/v1/models/{id}` | Detalhe de um modelo |
| POST | `/v1/messages` | Criação de mensagem (turno único) |
| POST | `/v1/messages/count_tokens` | Estimativa de tokens de entrada (~4 chars/token) |

## POST /v1/messages

Corpo no formato Messages API. O gateway achata `system` + `messages` em um turno único
(históricos com várias mensagens são concatenados com prefixo de papel).

### Texto simples

```bash
curl -sS https://api.seu-dominio.com.br/v1/messages \
  -H "x-api-key: sk-ravidev-..." \
  -H "anthropic-version: 2023-06-01" \
  -H "content-type: application/json" \
  -d '{
    "model": "claude-sonnet-4-6",
    "max_tokens": 200,
    "messages": [{"role": "user", "content": "Diga olá."}]
  }'
```

### Imagens (visão)

Blocos `image` com source `base64` são anexados de verdade ao modelo (visão real).

```json
{
  "model": "claude-sonnet-4-6",
  "max_tokens": 300,
  "messages": [{
    "role": "user",
    "content": [
      {"type": "image", "source": {"type": "base64", "media_type": "image/png", "data": "<base64>"}},
      {"type": "text", "text": "O que está escrito nesta imagem?"}
    ]
  }]
}
```

### PDF (documento)

Blocos `document` com PDF em base64 também são anexados (o modelo lê o conteúdo).

```json
{
  "model": "claude-sonnet-4-6",
  "max_tokens": 500,
  "messages": [{
    "role": "user",
    "content": [
      {"type": "document", "source": {"type": "base64", "media_type": "application/pdf", "data": "<base64>"}},
      {"type": "text", "text": "Resuma este documento."}
    ]
  }]
}
```

### Tetos de anexos

| Regra | Valor |
|---|---|
| Imagens por requisição | até **4** |
| Tamanho por imagem (decodificada) | até **5 MB** |
| Dimensões por imagem | até **8000x8000** e **25 megapixels** |
| Mimes de imagem aceitos | `image/png`, `image/jpeg`, `image/webp`, `image/gif` |
| Documentos por requisição | até **1** |
| Mime de documento aceito | `application/pdf` |
| Tamanho do PDF (decodificado) | até **10 MB** |
| Source dos anexos | só `base64` (URL não é suportada) |
| Corpo total da requisição | até **40 MB** (nginx; base64 infla ~37%) |

Anexo fora das regras devolve **400 `invalid_request_error`** com mensagem clara (não é
descartado em silêncio). Requisições só-texto seguem funcionando exatamente como antes.

Imagem acima de ~3,7 MB decodificada é **recomprimida automaticamente** no gateway
(reencodada como JPEG e reduzida até caber): é o teto real do backend, porque o modelo
recusa imagem acima de 5 MB **em base64**. Transparência vira fundo branco e GIF perde a
animação nesse caso; a visão não é afetada de forma relevante. Sem esse ajuste, um PNG de
4,3 MB derrubava a chamada em timeout.

### Respostas de erro (formato Anthropic)

| HTTP | type | Quando |
|---|---|---|
| 400 | `invalid_request_error` | JSON inválido, model/messages ausentes, anexo fora das regras |
| 401 | `authentication_error` | Token inválido ou revogado |
| 404 | `not_found_error` | Endpoint/modelo inexistente |
| 405 | `invalid_request_error` | Método errado |
| 413 | `invalid_request_error` | Corpo acima de 40 MB (respondido pelo nginx, já em JSON) |
| 429 | `rate_limit_error` | Rate limit por IP (nginx) |
| 502 | `api_error` | Falha no backend (todas as licenças da cadeia falharam) |
| 529 | `overloaded_error` | Gateway saturado (semáforo de containers) |

### Consumo

Cada chamada é registrada em `gw_requests` (ledger por token/licença) com o breakdown de
tokens (`prompt`, `cache_read`, `cache_write`, `output`). Anexos aumentam o `input_tokens`
normalmente (imagem consome muitos tokens de entrada). Os arquivos dos anexos vivem só no
workspace efêmero da chamada e são apagados ao final (sucesso ou erro): nada fica em disco.

## Observações

- O `usage` devolvido em `input_tokens` é o TOTAL processado (prompt + cache); o breakdown
  fica no ledger do painel.
- Pedido de Opus com falha em toda a cadeia de licenças faz fallback automático para Sonnet
  (o campo `model` da resposta informa o modelo realmente servido).
- `max_tokens`, `temperature` e `stream` não são aplicados pelo backend CLI (turno único,
  resposta completa).
- PDFs muito longos (centenas de páginas) podem não ser lidos por inteiro pelo backend;
  para relatórios extensos, prefira enviar as páginas relevantes.

## Backends

O executor de cada chamada é decidido em `GatewayService` (`src/Gateway/GatewayService.php`):

| Backend | Quando | Código |
|---|---|---|
| Claude Code CLI em container efêmero | padrão para modelos Claude | `GatewayClaudeService` |
| Proxy fino para a Messages API | só com `GW_PROXY_ENABLED=true` | `GatewayHttpService` |
| Codex CLI (contas ChatGPT do painel) | modelos `gpt-*` do catálogo | `GatewayCodexService` (imagem `ravi-codex:latest`) |
| DeepSeek | modelos `deepseek-*` ou regra do token (`gw_tokens.backend`) | `GatewayDeepSeekService` (chave em `app_settings`) |

A concorrência é limitada por `GatewaySlots` (semáforo com `flock`): `GW_MAX_CONCURRENCY`
chamadas ao mesmo tempo, com uma faixa separada de `GW_LONGAS_MAX` para chamadas longas
pedidas pelo header `x-ravi-tempo-limite`. Sem vaga em `GW_SLOT_WAIT` segundos, a resposta é
529. A ordem das licenças segue o roteamento da API configurado em Admin (`off`, `failover`
ou `balance`, ver `LicenseService`).
