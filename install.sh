#!/bin/bash

# =============================================================================
# Ravi Developer - Script de Instalação para Produção
# Sistema: Debian 12 (Bookworm)
# 
# Uso: bash install.sh (executar como root)
#
# Pergunta o domínio do painel, o domínio do gateway (opcional) e a pasta de
# instalação. Sem terminal interativo, leia das variáveis de ambiente
# DOMINIO, DOMINIO_API e RAIZ (ex.: DOMINIO=dev.empresa.com.br bash install.sh).
# =============================================================================

set -e

# Cores para output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Pasta de onde o instalador está rodando (o código-fonte)
ORIGEM="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Configurações
APP_USER="www-data"
DB_NAME="ravi_developer"
DB_USER="ravi"
DB_PASS=$(openssl rand -base64 24 | tr -dc 'a-zA-Z0-9' | head -c 20)

echo -e "${BLUE}"
echo "╔═══════════════════════════════════════════════════════════════╗"
echo "║                                                               ║"
echo "║   🌟  RAVI DEVELOPER - Instalação para Produção              ║"
echo "║                                                               ║"
echo "╚═══════════════════════════════════════════════════════════════╝"
echo -e "${NC}"

# Verificar se é root
if [ "$EUID" -ne 0 ]; then
    echo -e "${RED}Por favor, execute como root: bash install.sh${NC}"
    exit 1
fi

# Domínios e pasta da instalação
pergunta() {
    # pergunta <variável> <texto> <padrão>
    local var="$1" texto="$2" padrao="$3" valor="${!1:-}"
    if [ -z "$valor" ] && [ -t 0 ]; then
        read -r -p "${texto}${padrao:+ [${padrao}]}: " valor
    fi
    printf -v "$var" '%s' "${valor:-$padrao}"
}
pergunta DOMINIO     "Domínio do painel (ex.: dev.empresa.com.br)" ""
pergunta DOMINIO_API "Domínio do gateway de API (opcional, Enter para pular)" ""
pergunta RAIZ        "Pasta de instalação" "/var/www/ravi-developer"
if [ -z "$DOMINIO" ]; then
    echo -e "${RED}Domínio do painel é obrigatório (defina DOMINIO=...).${NC}"
    exit 1
fi
APP_DIR="${RAIZ%/}"
case "$APP_DIR" in /*) ;; *) echo -e "${RED}A pasta de instalação precisa ser caminho absoluto.${NC}"; exit 1 ;; esac

echo -e "${YELLOW}[1/10] Atualizando sistema...${NC}"
apt update && apt upgrade -y

echo -e "${YELLOW}[2/10] Instalando dependências...${NC}"
apt install -y \
    nginx \
    mariadb-server \
    php8.2-fpm \
    php8.2-cli \
    php8.2-mysql \
    php8.2-mbstring \
    php8.2-xml \
    php8.2-curl \
    php8.2-zip \
    php8.2-gd \
    php8.2-intl \
    php8.2-bcmath \
    unzip \
    curl \
    git \
    certbot \
    python3-certbot-nginx \
    supervisor

echo -e "${YELLOW}[3/10] Instalando Composer...${NC}"
if [ ! -f /usr/local/bin/composer ]; then
    curl -sS https://getcomposer.org/installer | php
    mv composer.phar /usr/local/bin/composer
fi

echo -e "${YELLOW}[4/10] Configurando MariaDB...${NC}"
# Criar banco e usuário
mariadb -e "CREATE DATABASE IF NOT EXISTS ${DB_NAME} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mariadb -e "CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';"
mariadb -e "GRANT ALL PRIVILEGES ON ${DB_NAME}.* TO '${DB_USER}'@'localhost';"
mariadb -e "FLUSH PRIVILEGES;"

echo -e "${GREEN}  ✓ Banco: ${DB_NAME}${NC}"
echo -e "${GREEN}  ✓ Usuário: ${DB_USER}${NC}"
echo -e "${GREEN}  ✓ Senha: ${DB_PASS}${NC}"

echo -e "${YELLOW}[5/10] Criando diretório da aplicação...${NC}"
mkdir -p ${APP_DIR}

# Copia o código da pasta do instalador (inclui arquivos ocultos como .env.example)
if [ -f "${ORIGEM}/composer.json" ] && [ "$(cd "${APP_DIR}" && pwd)" != "${ORIGEM}" ]; then
    echo "  Copiando arquivos da aplicação..."
    cp -a "${ORIGEM}/." "${APP_DIR}/"
elif [ -f "${APP_DIR}/composer.json" ]; then
    echo "  Código já está em ${APP_DIR}"
else
    echo -e "${RED}  ATENÇÃO: Copie os arquivos do Ravi Developer para ${APP_DIR}${NC}"
fi

echo -e "${YELLOW}[6/10] Configurando permissões...${NC}"
chown -R ${APP_USER}:${APP_USER} ${APP_DIR}
chmod -R 755 ${APP_DIR}
chmod -R 775 ${APP_DIR}/storage

echo -e "${YELLOW}[7/10] Configurando ambiente...${NC}"
if [ ! -f "${APP_DIR}/.env" ]; then
    cp ${APP_DIR}/.env.example ${APP_DIR}/.env
    
    # Gerar APP_KEY
    APP_KEY=$(openssl rand -base64 32)
    
    # Atualizar .env
    sed -i "s|APP_ENV=local|APP_ENV=production|g" ${APP_DIR}/.env
    sed -i "s|APP_DEBUG=true|APP_DEBUG=false|g" ${APP_DIR}/.env
    sed -i "s|^APP_URL=.*|APP_URL=https://${DOMINIO}|" ${APP_DIR}/.env
    sed -i "s|^IDE_BASE_URL=.*|IDE_BASE_URL=https://${DOMINIO}/ide|" ${APP_DIR}/.env
    sed -i "s|^PREVIEW_BASE_URL=.*|PREVIEW_BASE_URL=https://${DOMINIO}/p|" ${APP_DIR}/.env
    sed -i "s|^WEBSOCKET_URL=.*|WEBSOCKET_URL=wss://${DOMINIO}/ws|" ${APP_DIR}/.env
    sed -i "s|^MAIL_FROM_ADDRESS=.*|MAIL_FROM_ADDRESS=noreply@${DOMINIO}|" ${APP_DIR}/.env
    if [ -n "${DOMINIO_API}" ]; then
        sed -i "s|^GW_PUBLIC_URL=.*|GW_PUBLIC_URL=https://${DOMINIO_API}|" ${APP_DIR}/.env
    else
        sed -i "s|^GW_PUBLIC_URL=.*|GW_PUBLIC_URL=|" ${APP_DIR}/.env
    fi
    sed -i "s|APP_KEY=.*|APP_KEY=${APP_KEY}|g" ${APP_DIR}/.env
    sed -i "s|DB_HOST=.*|DB_HOST=127.0.0.1|g" ${APP_DIR}/.env
    sed -i "s|DB_DATABASE=.*|DB_DATABASE=${DB_NAME}|g" ${APP_DIR}/.env
    sed -i "s|DB_USERNAME=.*|DB_USERNAME=${DB_USER}|g" ${APP_DIR}/.env
    sed -i "s|DB_PASSWORD=.*|DB_PASSWORD=${DB_PASS}|g" ${APP_DIR}/.env
    sed -i "s|WEBSOCKET_HOST=.*|WEBSOCKET_HOST=127.0.0.1|g" ${APP_DIR}/.env
    
    echo -e "${GREEN}  ✓ Arquivo .env criado${NC}"
    echo -e "${YELLOW}  ⚠ Não esqueça de configurar CLAUDE_API_KEY no .env!${NC}"
fi

echo -e "${YELLOW}[8/10] Instalando dependências PHP...${NC}"
cd ${APP_DIR}
composer install --no-dev --optimize-autoloader
chown -R ${APP_USER}:${APP_USER} ${APP_DIR}/vendor

echo -e "${YELLOW}[9/10] Gerando nginx e serviços a partir dos modelos...${NC}"
# Grava em /etc/nginx/sites-available e /etc/systemd/system, sem ativar.
# Os comandos de ativação ficam em ${APP_DIR}/storage/ativar-modelos.txt.
MODELOS_ARGS=(--raiz "${APP_DIR}" --dominio "${DOMINIO}")
[ -z "${DOMINIO_API}" ] || MODELOS_ARGS+=(--dominio-api "${DOMINIO_API}")
bash "${APP_DIR}/bin/instalar-modelos.sh" "${MODELOS_ARGS[@]}" | tee "${APP_DIR}/storage/ativar-modelos.txt"

echo -e "${YELLOW}[10/10] Configurando Supervisor para WebSocket...${NC}"
cat > /etc/supervisor/conf.d/ravi-websocket.conf << EOF
[program:ravi-websocket]
command=/usr/bin/php ${APP_DIR}/bin/websocket-server.php
directory=${APP_DIR}
user=${APP_USER}
autostart=true
autorestart=true
stderr_logfile=/var/log/ravi-websocket.err.log
stdout_logfile=/var/log/ravi-websocket.out.log
EOF

supervisorctl reread
supervisorctl update

echo -e "${YELLOW}Iniciando serviços...${NC}"
systemctl restart php8.2-fpm
systemctl restart nginx
supervisorctl restart ravi-websocket

echo -e "${YELLOW}Executando migrations...${NC}"
cd ${APP_DIR}
php bin/db-init.php --migrate --seed

echo -e "${YELLOW}Criando o administrador...${NC}"
if [ -t 0 ]; then
    php bin/criar-admin.php || echo -e "${RED}Administrador não criado. Rode depois: php bin/criar-admin.php${NC}"
else
    echo -e "${YELLOW}Sem terminal interativo: rode depois ${NC}php ${APP_DIR}/bin/criar-admin.php"
fi

echo ""
echo -e "${GREEN}╔═══════════════════════════════════════════════════════════════╗${NC}"
echo -e "${GREEN}║                                                               ║${NC}"
echo -e "${GREEN}║   ✅ INSTALAÇÃO CONCLUÍDA!                                    ║${NC}"
echo -e "${GREEN}║                                                               ║${NC}"
echo -e "${GREEN}╚═══════════════════════════════════════════════════════════════╝${NC}"
echo ""
echo -e "${BLUE}Informações do Banco de Dados:${NC}"
echo -e "  Host: 127.0.0.1"
echo -e "  Banco: ${DB_NAME}"
echo -e "  Usuário: ${DB_USER}"
echo -e "  Senha: ${DB_PASS}"
echo ""
echo -e "${BLUE}Próximos passos:${NC}"
echo -e "  1. Aponte o DNS de ${DOMINIO}${DOMINIO_API:+ e ${DOMINIO_API}} para este servidor"
echo -e "  2. Ative nginx, certificados e serviços com os comandos de:"
echo -e "     ${YELLOW}${APP_DIR}/storage/ativar-modelos.txt${NC}"
echo -e "  3. Edite ${APP_DIR}/.env e adicione sua CLAUDE_API_KEY"
echo -e "  4. Acesse: https://${DOMINIO}"
echo ""
echo -e "${BLUE}Administrador:${NC}"
echo -e "  Nenhum usuário vem pronto. Para criar ou promover um admin:"
echo -e "  ${YELLOW}php ${APP_DIR}/bin/criar-admin.php${NC}"
echo ""
echo -e "${BLUE}Comandos úteis:${NC}"
echo -e "  Ver logs do WebSocket: ${YELLOW}tail -f /var/log/ravi-websocket.out.log${NC}"
echo -e "  Ver logs do Nginx:     ${YELLOW}tail -f /var/log/nginx/${DOMINIO}.*.log${NC}"
echo -e "  Reiniciar WebSocket:   ${YELLOW}supervisorctl restart ravi-websocket${NC}"
echo ""
