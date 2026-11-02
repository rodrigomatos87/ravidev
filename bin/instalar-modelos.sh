#!/usr/bin/env bash
# Renderiza os modelos de infraestrutura do Ravi Developer para esta instalação.
#
# Modelos e destinos:
#   docker/nginx/painel.conf.modelo   -> /etc/nginx/sites-available/<dominio>
#   docker/nginx/gateway.conf.modelo  -> /etc/nginx/sites-available/<dominio-api>  (só com --dominio-api)
#   docker/nginx/ravi-ide-nginx.modelo e ravi-preview-nginx.modelo -> /usr/local/bin/ (snippets das IDEs e previews)
#   docker/nginx/ravi-ws-map.conf -> /etc/nginx/conf.d/ ; docker/nginx/ravi-ide.sudoers -> /etc/sudoers.d/ravi-ide
#   docker/ravi-ide-proxy.service     -> /etc/systemd/system/ravi-ide-proxy.service
#   docker/ravi-git-proxy.service     -> /etc/systemd/system/ravi-git-proxy.service
#
# Marcadores trocados: __RAIZ__, __DOMINIO__, __DOMINIO_API__.
# NÃO ativa nada: no fim imprime os comandos para ativar.
#
# Uso:
#   bin/instalar-modelos.sh [--raiz PASTA] [--dominio DOMINIO] [--dominio-api DOMINIO]
#                           [--prefixo PASTA] [--forcar]
#
#   --raiz         pasta da instalação (padrão: a pasta acima deste script)
#   --dominio      domínio do painel (padrão: host do APP_URL no <raiz>/.env)
#   --dominio-api  domínio do gateway (padrão: host do GW_PUBLIC_URL no <raiz>/.env;
#                  sem valor, o vhost do gateway não é gerado)
#   --prefixo      grava sob esta pasta em vez de / (para conferir antes de instalar)
#   --forcar       sobrescreve destino que já existe com conteúdo diferente
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
RAIZ=""
DOMINIO=""
DOMINIO_API=""
PREFIXO=""
FORCAR=0

erro() { echo "Erro: $*" >&2; exit 1; }

while [ $# -gt 0 ]; do
    case "$1" in
        --raiz)        RAIZ="${2:-}"; shift 2 ;;
        --dominio)     DOMINIO="${2:-}"; shift 2 ;;
        --dominio-api) DOMINIO_API="${2:-}"; shift 2 ;;
        --prefixo)     PREFIXO="${2:-}"; shift 2 ;;
        --forcar)      FORCAR=1; shift ;;
        -h|--help)     sed -n '2,24p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *)             erro "opção desconhecida: $1 (veja --help)" ;;
    esac
done

[ -n "$RAIZ" ] || RAIZ="$(cd "$SCRIPT_DIR/.." && pwd)"
RAIZ="${RAIZ%/}"

# Lê uma chave do .env da instalação (sem aspas, sem comentário no fim).
ler_env() {
    local chave="$1" arq="$RAIZ/.env"
    [ -f "$arq" ] || return 0
    grep -E "^${chave}=" "$arq" | tail -n1 | cut -d= -f2- \
        | sed -E 's/[[:space:]]+#.*$//; s/^["'\'']//; s/["'\'']$//; s/[[:space:]]+$//'
}

# Host de uma URL (https://host:porta/caminho -> host).
host_da_url() {
    printf '%s' "$1" | sed -E 's#^[a-zA-Z]+://##; s#[/:?].*$##'
}

if [ -z "$DOMINIO" ]; then
    DOMINIO="$(host_da_url "$(ler_env APP_URL)")"
fi
if [ -z "$DOMINIO_API" ]; then
    DOMINIO_API="$(host_da_url "$(ler_env GW_PUBLIC_URL)")"
fi

# Validação: o valor vai para dentro de arquivo de configuração, então só
# caracteres seguros (também protege o sed abaixo).
case "$RAIZ" in /*) ;; *) erro "--raiz precisa ser caminho absoluto: $RAIZ" ;; esac
[[ "$RAIZ" =~ ^[A-Za-z0-9._/-]+$ ]] || erro "--raiz com caractere não aceito: $RAIZ"
[ -n "$DOMINIO" ] || erro "informe --dominio (ou APP_URL no $RAIZ/.env)"
valida_dominio() {
    [[ "$1" =~ ^[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?$ ]] || erro "domínio inválido: $1"
    case "$1" in localhost|*seu-dominio*) erro "domínio de exemplo ou local não serve para o vhost: $1" ;; esac
}
valida_dominio "$DOMINIO"
[ -z "$DOMINIO_API" ] || valida_dominio "$DOMINIO_API"
if [ -n "$DOMINIO_API" ] && [ "$DOMINIO_API" = "$DOMINIO" ]; then
    erro "o gateway precisa de domínio próprio (--dominio-api igual ao --dominio)"
fi

MODELOS="$RAIZ/docker"
[ -d "$MODELOS" ] || MODELOS="$SCRIPT_DIR/../docker"
[ -f "$MODELOS/nginx/painel.conf.modelo" ] || erro "modelos não encontrados em $MODELOS"

DEST_NGINX="${PREFIXO%/}/etc/nginx/sites-available"
DEST_SYSTEMD="${PREFIXO%/}/etc/systemd/system"
mkdir -p "$DEST_NGINX" "$DEST_SYSTEMD"

GERADOS=()
render() {
    local origem="$1" destino="$2" tmp
    tmp="$(mktemp)"
    sed -e "s|__DOMINIO_API__|${DOMINIO_API}|g" \
        -e "s|__DOMINIO__|${DOMINIO}|g" \
        -e "s|__RAIZ__|${RAIZ}|g" "$origem" > "$tmp"
    if grep -q '__[A-Z_]*__' "$tmp"; then
        rm -f "$tmp"; erro "marcador sem valor em $origem"
    fi
    if [ -f "$destino" ] && ! cmp -s "$tmp" "$destino"; then
        if [ "$FORCAR" -ne 1 ]; then
            rm -f "$tmp"
            echo "  mantido (já existe e difere; use --forcar): $destino"
            return 0
        fi
    fi
    install -m 0644 "$tmp" "$destino"
    rm -f "$tmp"
    GERADOS+=("$destino")
    echo "  gerado: $destino"
}

echo "Raiz: $RAIZ"
echo "Painel: $DOMINIO"
echo "Gateway: ${DOMINIO_API:-(não gerado)}"
render "$MODELOS/nginx/painel.conf.modelo" "$DEST_NGINX/$DOMINIO"
if [ -n "$DOMINIO_API" ]; then
    render "$MODELOS/nginx/gateway.conf.modelo" "$DEST_NGINX/$DOMINIO_API"
fi
render "$MODELOS/ravi-ide-proxy.service" "$DEST_SYSTEMD/ravi-ide-proxy.service"
render "$MODELOS/ravi-git-proxy.service" "$DEST_SYSTEMD/ravi-git-proxy.service"

# IDEs e previews: scripts que regeram os snippets do nginx, mapa do Upgrade e sudoers
DEST_BIN="${PREFIXO%/}/usr/local/bin"
DEST_SNIPPETS="${PREFIXO%/}/etc/nginx/snippets"
DEST_CONFD="${PREFIXO%/}/etc/nginx/conf.d"
DEST_SUDOERS="${PREFIXO%/}/etc/sudoers.d"
mkdir -p "$DEST_BIN" "$DEST_SNIPPETS" "$DEST_CONFD" "$DEST_SUDOERS"
render "$MODELOS/nginx/ravi-ide-nginx.modelo" "$DEST_BIN/ravi-ide-nginx"
render "$MODELOS/nginx/ravi-preview-nginx.modelo" "$DEST_BIN/ravi-preview-nginx"
chmod 755 "$DEST_BIN/ravi-ide-nginx" "$DEST_BIN/ravi-preview-nginx"
render "$MODELOS/nginx/ravi-ws-map.conf" "$DEST_CONFD/ravi-ws-map.conf"
render "$MODELOS/nginx/ravi-ide.sudoers" "$DEST_SUDOERS/ravi-ide"
chmod 440 "$DEST_SUDOERS/ravi-ide"
for s in ravi-ides.conf ravi-previews.conf; do [ -f "$DEST_SNIPPETS/$s" ] || : > "$DEST_SNIPPETS/$s"; done

CERTS="-d $DOMINIO"
[ -z "$DOMINIO_API" ] || CERTS="$CERTS -d $DOMINIO_API"

cat <<FIM

Nada foi ativado. Para ativar (como root, com o DNS já apontando para este servidor):

  # 1. Certificado (os vhosts usam /etc/letsencrypt/live/<dominio>/)
  certbot certonly --nginx -d $DOMINIO
FIM
[ -z "$DOMINIO_API" ] || echo "  certbot certonly --nginx -d $DOMINIO_API"
cat <<FIM

  # 2. Vhosts
  ln -sf /etc/nginx/sites-available/$DOMINIO /etc/nginx/sites-enabled/$DOMINIO
FIM
[ -z "$DOMINIO_API" ] || echo "  ln -sf /etc/nginx/sites-available/$DOMINIO_API /etc/nginx/sites-enabled/$DOMINIO_API"
cat <<FIM
  nginx -t && systemctl reload nginx

  # 3. Proxies de credencial e de git das IDEs
  systemctl daemon-reload
  systemctl enable --now ravi-ide-proxy ravi-git-proxy
FIM
