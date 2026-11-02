# Integração com o GitHub: configurar o OAuth App

A integração Git/GitHub já vem pronta no código. Para ligá-la, o administrador da
instalação registra um OAuth App no GitHub, uma única vez, e cola as credenciais no
`.env`. Depois disso é self-service: cada usuário conecta a própria conta em
**Minha Conta > GitHub**.

Nos exemplos abaixo, troque `https://seu-dominio.com.br` pelo `APP_URL` da sua
instalação e `/caminho/da/instalacao` pela pasta onde ela está.

## 1. Criar o OAuth App no GitHub

1. Acesse <https://github.com/settings/developers> > **New OAuth App**
   (ou, em uma organização: *Settings > Developer settings > OAuth Apps > New*).
2. Preencha:
   - **Application name:** o nome da sua instalação (ex.: `Ravi Developer`)
   - **Homepage URL:** `https://seu-dominio.com.br`
   - **Authorization callback URL:** `https://seu-dominio.com.br/settings/github/callback`
3. **Register application.**
4. Copie o **Client ID**, gere um **Client Secret** (*Generate a new client secret*) e copie.

## 2. Colar no `.env` do servidor

No `.env` em `/caminho/da/instalacao/.env` (as chaves já existem, vazias):

```
GITHUB_CLIENT_ID=<seu client id>
GITHUB_CLIENT_SECRET=<seu client secret>
```

Depois recarregue o PHP para ler o `.env`:

```
systemctl reload php8.2-fpm
```

O queue-worker lê as mesmas chaves via `config/github.php`. Se ele já estava de pé, reinicie quando não houver tarefa rodando (`supervisorctl restart ravi-queue-worker`).

## 3. Usar

- **Conectar:** Minha Conta > **GitHub** > *Conectar com o GitHub* (autoriza uma vez; o token fica cifrado e persistente).
- **Por projeto:** na página do projeto, botão **Git** > ligar a um repo existente, criar um novo, ou marcar **auto-sync**.
- **Sincronizar:** botão *Sincronizar agora* (ou automático a cada entrega, se auto-sync ligado). Commits saem como sua conta; `.env`, `vendor/`, `node_modules/`, `.maestro/`, `delivery/` ficam de fora.
- **Criar clonando:** com o GitHub conectado, ao criar projeto aparece *Clonar do GitHub*; a IA clona, analisa e prepara o ambiente.

> Escopo solicitado: `repo` (cobre repositórios privados, ajustável em `GITHUB_SCOPE`). O token
> de cada usuário fica cifrado (AES-256-GCM) em `users.github_token_encrypted` e nunca entra em
> container nem em logs: dentro da IDE o git fala com `bin/git-proxy.py`, que troca o handle do
> container pelo token do dono do projeto (ver `docs/arquitetura.md`).
