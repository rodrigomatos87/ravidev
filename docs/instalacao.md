# Instalação

Guia completo para pôr o Ravi Developer de pé num servidor Linux com systemd. Os exemplos usam
Debian 12, a pasta `/var/www/ravi-developer` (chamada de RAIZ abaixo) e os domínios
`seu-dominio.com.br` (painel) e `api.seu-dominio.com.br` (Gateway de API, opcional). O PHP-FPM
e o nginx rodam como `www-data`.

## 1. Pacotes do sistema

```bash
apt install -y nginx mariadb-server certbot python3-certbot-nginx supervisor git unzip \
    php8.2-fpm php8.2-cli php8.2-mysql php8.2-mbstring php8.2-xml php8.2-curl \
    php8.2-zip php8.2-gd php8.2-intl php8.2-bcmath python3
# Composer: https://getcomposer.org/download/
# Docker Engine: https://docs.docker.com/engine/install/debian/
usermod -aG docker www-data      # o PHP chama o cliente docker direto (SandboxService, IdeService)
```

O `install.sh` faz a parte de PHP, MariaDB, nginx, certbot e supervisor, cria o banco, gera o
`.env`, roda as migrations e cria o administrador. Os passos 5 a 9 abaixo continuam necessários
depois dele.

## 2. Código, `.env` e banco

```bash
git clone <repositório> /var/www/ravi-developer && cd /var/www/ravi-developer
composer install --no-dev --optimize-autoloader
cp .env.example .env
sed -i "s|^APP_KEY=.*|APP_KEY=base64:$(php -r 'echo base64_encode(random_bytes(32));')|" .env
chown -R www-data:www-data storage
```

No `.env`, preencha no mínimo `APP_URL=https://seu-dominio.com.br`, `APP_ENV=production`,
`APP_DEBUG=false` e `DB_*`. Sem `DB_HOST`, `DB_DATABASE`, `DB_USERNAME` ou `APP_KEY` válida o
painel responde 500 e grava o motivo no log (`src/bootstrap.php`).

```bash
mariadb -e "CREATE DATABASE ravi_developer CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
mariadb -e "CREATE USER 'ravi'@'localhost' IDENTIFIED BY '<senha>'"
mariadb -e "GRANT ALL ON ravi_developer.* TO 'ravi'@'localhost'"

php bin/db-init.php --migrate --seed   # migrations do núcleo e dos módulos + system_settings
php bin/db-init.php --status           # confere o que foi aplicado (tabela schema_migrations)
php bin/criar-admin.php                # interativo; ou --nome="..." --email=... --senha=...
```

O seed não cria usuário: o primeiro administrador sai sempre do `bin/criar-admin.php` (se o
e-mail já existir, a conta é promovida a administrador).

## 3. nginx, certificados e proxies a partir dos modelos

```bash
bin/instalar-modelos.sh --dominio seu-dominio.com.br --dominio-api api.seu-dominio.com.br
```

O script lê os modelos em `docker/` e grava:

| Modelo | Destino |
|---|---|
| `docker/nginx/painel.conf.modelo` | `/etc/nginx/sites-available/<dominio>.conf` |
| `docker/nginx/gateway.conf.modelo` (só com `--dominio-api`) | `/etc/nginx/sites-available/<dominio-api>.conf` |
| `docker/ravi-ide-proxy.service` | `/etc/systemd/system/ravi-ide-proxy.service` |
| `docker/ravi-git-proxy.service` | `/etc/systemd/system/ravi-git-proxy.service` |

Ele não ativa nada: no fim imprime os comandos (`certbot certonly --nginx -d ...`,
`ln -sf ... sites-enabled/`, `nginx -t`, `systemctl reload nginx`,
`systemctl enable --now ravi-ide-proxy ravi-git-proxy`). Use `--raiz` se a pasta não for a atual,
`--prefixo` para gravar em outra árvore (teste) e `--forcar` para sobrescrever.

## 4. nginx da IDE e do preview

As IDEs ficam em `/ide/<token>/` e os previews em `/p/<token>/`. Cada um aponta para uma porta
local diferente, então o PHP mantém a lista em JSON e um script com sudo reescreve um snippet do
nginx. Essas peças **não estão no repositório** e precisam ser criadas à mão.

### 4.1 Mapa do Upgrade (WebSocket)

`/etc/nginx/conf.d/ravi-ws-map.conf`:

```nginx
map $http_upgrade $connection_upgrade {
    default upgrade;
    ''      close;
}
```

### 4.2 Includes no vhost do painel

No bloco `server { listen 443 ... }` gerado no passo 3, antes do `location /`:

```nginx
include /etc/nginx/snippets/ravi-previews.conf;
include /etc/nginx/snippets/ravi-ides.conf;
```

Crie os dois arquivos vazios para o `nginx -t` passar antes da primeira IDE:
`touch /etc/nginx/snippets/ravi-ides.conf /etc/nginx/snippets/ravi-previews.conf`.

### 4.3 Script `/usr/local/bin/ravi-ide-nginx`

O `IdeService::regenNginx()` grava `RAIZ/storage/ide-nginx.json` (lista de
`{"token": ..., "port": ...}`) e executa `IDE_NGINX_TRIGGER` (padrão
`sudo /usr/local/bin/ravi-ide-nginx`). O script precisa, para cada IDE, de um `location`
interno que chama `public/ide-auth.php` (valida o cookie de sessão e o token, ver
`docs/arquitetura.md`) e do `location` que faz o proxy com `auth_request`. Exemplo:

```python
#!/usr/bin/env python3
import json, os, re, shutil, subprocess
RAIZ = "/var/www/ravi-developer"
FPM = "unix:/run/php/php8.2-fpm.sock"
DESTINO = "/etc/nginx/snippets/ravi-ides.conf"

itens = json.load(open(f"{RAIZ}/storage/ide-nginx.json"))
blocos = []
for i in itens:
    t, porta = str(i["token"]), int(i["port"])
    if not re.fullmatch(r"[a-f0-9]+", t) or not 21100 <= porta <= 21199:
        continue
    blocos.append(f"""
location = /__ideauth_{t} {{
    internal;
    include fastcgi_params;
    fastcgi_pass {FPM};
    fastcgi_param SCRIPT_FILENAME {RAIZ}/public/ide-auth.php;
    fastcgi_param SCRIPT_NAME /ide-auth.php;
    fastcgi_param DOCUMENT_ROOT {RAIZ}/public;
    fastcgi_param QUERY_STRING token={t};
    fastcgi_param REQUEST_METHOD GET;
}}
location ^~ /ide/{t}/ {{
    auth_request /__ideauth_{t};
    proxy_pass http://127.0.0.1:{porta}/;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection $connection_upgrade;
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_read_timeout 86400;
    proxy_hide_header X-Frame-Options;
    proxy_redirect / /ide/{t}/;
    proxy_cookie_path / /ide/{t}/;
}}""")

if os.path.exists(DESTINO):
    shutil.copy(DESTINO, DESTINO + ".bak")
open(DESTINO, "w").write("\n".join(blocos) + "\n")
if subprocess.run(["nginx", "-t"], capture_output=True).returncode == 0:
    subprocess.run(["systemctl", "reload", "nginx"])
elif os.path.exists(DESTINO + ".bak"):
    shutil.move(DESTINO + ".bak", DESTINO)
```

Guarde o `.bak` fora de `/etc/nginx/` se a sua configuração incluir `snippets/*`.

### 4.4 Script `/usr/local/bin/ravi-preview-nginx`

Igual ao anterior, lendo `RAIZ/storage/previews-nginx.json` (gravado por
`PreviewService`), gravando `/etc/nginx/snippets/ravi-previews.conf`, com portas de 21000 a
21099, prefixo `/p/<token>/`, cabeçalho `proxy_set_header X-Forwarded-Prefix /p/<token>;` e
**sem** `auth_request` (o preview é público para quem tem o link).

### 4.5 sudoers

`/etc/sudoers.d/ravi` (permissão 0440):

```
www-data ALL=(root) NOPASSWD: /usr/local/bin/ravi-ide-nginx
www-data ALL=(root) NOPASSWD: /usr/local/bin/ravi-preview-nginx
```

Se preferir outro mecanismo, aponte `IDE_NGINX_TRIGGER` e `PREVIEW_NGINX_TRIGGER` para ele;
vazio desliga a chamada.

## 5. Imagens Docker

O contexto de build é a pasta `docker/`.

```bash
# base: Ubuntu 22.04, Python, Node 20, PHP, Go, Rust, clientes de banco, Playwright
docker build -t ravi-sandbox:latest -f docker/Dockerfile.sandbox docker
# camada com o Claude Code CLI (versão fixada), PHP 8.3, skills e acesso SSH
docker build -t ravi-sandbox:latest -f docker/Dockerfile.sandbox.claude docker
# IDE: code-server, navegador da IDE, extensão git, entrypoint
docker build -t ravi-ide:latest -f docker/Dockerfile.ide docker

# opcionais
docker build -t ravi-sandbox-mobile:latest -f docker/Dockerfile.sandbox-mobile docker   # projetos do tipo app
docker build -t ravi-codex:latest -f docker/Dockerfile.codex docker                     # backend Codex do Gateway
```

`docker/Dockerfile.sandbox.ssh` é uma camada incremental para reaplicar só as ferramentas de SSH
sem refazer a camada do Claude. Qualquer mudança em `docker/ide-entrypoint.sh` exige reconstruir
`ravi-ide` (o arquivo é copiado na imagem, não montado).

Banco e rede dos previews (o `PreviewService` usa, mas não cria):

```bash
docker network create ravi-preview-net
docker run -d --name ravi-preview-db --network ravi-preview-net --restart unless-stopped \
    -e MARIADB_ROOT_PASSWORD='<senha>' mariadb:10.11
# e no .env: PREVIEW_DB_ROOT_PASS=<senha>
```

## 6. Proxies de credencial

`bin/ide-proxy.py` (porta 8788) e `bin/git-proxy.py` (porta 8789) escutam só na bridge do
Docker (`172.17.0.1`), rodam como `www-data` e resolvem o handle do container chamando o PHP
(`bin/ide-proxy-token.php` e `bin/git-proxy-token.php`). Os units vêm do passo 3:

```bash
systemctl daemon-reload
systemctl enable --now ravi-ide-proxy ravi-git-proxy
```

O `.env` precisa apontar para eles como os containers os veem: `IDE_PROXY_URL` e
`GIT_PROXY_URL` (padrão `http://172.17.0.1:8788` e `:8789`). Se a bridge do seu Docker usar
outra faixa, ajuste o `Environment=` dos units e o `.env`. A pasta dos sockets do ssh-agent da
IDE é `IDE_SSH_AGENT_DIR` (padrão `/var/lib/ravi/ide-ssh`) e precisa ser gravável pelo
`www-data`.

## 7. WebSocket e queue-worker (supervisor)

`/etc/supervisor/conf.d/ravi.conf`:

```ini
[program:ravi-websocket]
command=/usr/bin/php /var/www/ravi-developer/bin/websocket-server.php
directory=/var/www/ravi-developer
user=www-data
autostart=true
autorestart=true
stderr_logfile=/var/log/ravi-websocket.err.log
stdout_logfile=/var/log/ravi-websocket.out.log

[program:ravi-queue-worker]
command=/usr/bin/php /var/www/ravi-developer/bin/queue-worker.php
directory=/var/www/ravi-developer
user=www-data
numprocs=1
autostart=true
autorestart=true
startsecs=3
stopwaitsecs=20
stderr_logfile=/var/log/ravi-queue-worker.err.log
stdout_logfile=/var/log/ravi-queue-worker.out.log
```

```bash
supervisorctl reread && supervisorctl update
```

O WebSocket escuta em `WEBSOCKET_HOST:WEBSOCKET_PORT` (padrão `127.0.0.1:8081`) e o vhost do
painel já faz o proxy de `/ws`. O queue-worker reabre, ao subir, os jobs que ficaram órfãos;
reinicie-o só quando não houver tarefa rodando.

## 8. Cron

`crontab -u www-data -e`:

```
* * * * *    cd /var/www/ravi-developer && php bin/scheduler.php >> storage/logs/scheduler.log 2>&1
*/15 * * * * cd /var/www/ravi-developer && php bin/cleanup-ides.php >> storage/logs/ide-cleanup.log 2>&1
*/15 * * * * cd /var/www/ravi-developer && php bin/cleanup-previews.php >> storage/logs/preview-cleanup.log 2>&1
* * * * *    cd /var/www/ravi-developer && php bin/ide-alert-monitor.php >> storage/logs/ide-alert.log 2>&1
*/5 * * * *  cd /var/www/ravi-developer && php bin/resource-alert-monitor.php >> storage/logs/resource-alert.log 2>&1
```

| Script | O que faz |
|---|---|
| `bin/scheduler.php` | roda os agendamentos vencidos (`ScheduleService::runDue`) e reconcilia ssh-agent das IDEs, contas ChatGPT e handles de credencial |
| `bin/cleanup-ides.php` | pausa IDEs ociosas há `IDE_IDLE_MINUTES` e remove as paradas há `IDE_REAP_DAYS` |
| `bin/cleanup-previews.php` | para previews ociosos há `PREVIEW_IDLE_MINUTES` |
| `bin/ide-alert-monitor.php` e `bin/resource-alert-monitor.php` | alertas (WhatsApp, quando configurado) |
| `bin/cleanup-sandboxes.php` | remove sandboxes antigos (opcional, rodar sob demanda ou no cron) |

## 9. Primeiro acesso

1. Entre em `https://seu-dominio.com.br` com o administrador criado no passo 2.
2. **Configurações**: SMTP. Com SMTP configurado, quem não tem TOTP recebe código por e-mail no
   login (sem SMTP e sem TOTP o login é só com senha).
3. **Licenças**: cadastre ao menos uma credencial de assinatura Claude, com os escopos que
   ela atende (API, IDE, Dev Autônomo). A IDE e o Gateway não usam chave do `.env`; o Dev
   Autônomo só recorre à `CLAUDE_API_KEY` quando o escopo não tem licença.
4. **Gateway de API** (opcional): endereço público, tokens e catálogo de modelos
   ([API-GATEWAY.md](API-GATEWAY.md)).
5. GitHub (opcional): [github-oauth.md](github-oauth.md).

## Problemas comuns

| Sintoma | Onde olhar |
|---|---|
| 500 em todas as páginas | `storage/logs/`, variáveis obrigatórias do `.env`, `APP_KEY` com 32 bytes |
| IDE abre e dá 404 em `/ide/...` | includes do passo 4.2, sudoers, saída de `sudo -u www-data sudo /usr/local/bin/ravi-ide-nginx` |
| IDE abre e dá 403 | cookie de sessão expirado ou IDE de outro usuário (`public/ide-auth.php`) |
| `claude` na IDE sem resposta | `systemctl status ravi-ide-proxy`, licença com escopo IDE em Administração > Licenças |
| Chat não recebe respostas | `supervisorctl status`, `/var/log/ravi-queue-worker.err.log`, proxy de `/ws` |
| Docker "permission denied" | `www-data` fora do grupo `docker` (reinicie o php-fpm e o supervisor depois do `usermod`) |
