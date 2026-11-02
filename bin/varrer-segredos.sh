#!/usr/bin/env bash
# Varre uma árvore de arquivos atrás de segredos e de vestígios da operação interna.
# Sai com 1 se achar algo BLOQUEANTE (credencial, chave, IP ou host interno, nome de pessoa).
# Avisos (padrões que merecem olhar, mas podem ser legítimos) não bloqueiam.
#
# Uso: bin/varrer-segredos.sh [pasta]   (padrão: a raiz do repositório)
set -uo pipefail

RAIZ="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ALVO="${1:-$RAIZ}"

# Credenciais e chaves (formato), infraestrutura interna e pessoas da casa.
BLOQUEANTES=(
  'sk-ant-[A-Za-z0-9_-]{20,}'
  'sk-ravidev-[A-Za-z0-9]{6,}'
  'AKIA[0-9A-Z]{16}'
  'gh[pousr]_[A-Za-z0-9]{20,}'
  'xox[baprs]-[A-Za-z0-9-]{10,}'
  'BEGIN (RSA |EC |OPENSSH |DSA )?PRIVATE KEY'
  'PrivateKey *= *[A-Za-z0-9+/]{40,}'
  '192\.168\.[0-9]+\.[0-9]+'
  '10\.10\.[0-9]+\.[0-9]+'
  '10\.99\.[0-9]+\.[0-9]+'
  '38\.4\.136\.[0-9]+'
  '200\.11\.121\.[0-9]+'
  'proxmox\.ravisystems'
  'hostdime'
  'ravimonitor\.com\.br'
  'ravimonitoramento@'
  '[Jj]onny'
  'r0drig0'
  'Financeira'
  'RaviMail'
  'ChatISP'
  'RaviGrowth'
  'Ravicor'
  'RaviDev IDE'
  'Ravi Monitor'
)

# Vale a pena olhar: domínio e caminho da nossa instalação como padrão, senhas de exemplo etc.
AVISOS=(
  'ravidev\.com\.br'
  '/var/www/ravidev'
  '(password|senha|secret)[a-z_]* *[:=] *["'"'"'][^"'"'"' ]{6,}'
  'RaviDev@2025'
  'demo123'
)

# Só o que interessa: nada de vendor, .git, binários, fontes.
listar() {
  find "$ALVO" -type f \
    -not -path '*/.git/*' -not -path '*/vendor/*' -not -path '*/node_modules/*' \
    -not -name '*.png' -not -name '*.jpg' -not -name '*.jpeg' -not -name '*.gif' -not -name '*.ico' \
    -not -name '*.woff' -not -name '*.woff2' -not -name '*.ttf' -not -name '*.zip' -not -name '*.gz' \
    -not -name '*.lock' -not -name 'varrer-segredos.sh' -print0
}

bloqueios=0
avisos=0

echo "Alvo: $ALVO"
echo
echo "== BLOQUEANTES =="
for p in "${BLOQUEANTES[@]}"; do
  saida="$(listar | xargs -0 grep -HnIE -- "$p" 2>/dev/null | sed -E 's/(sk-ant-[A-Za-z0-9_-]{6})[A-Za-z0-9_-]+/\1***/g; s/(PrivateKey *= *[A-Za-z0-9+\/]{6})[A-Za-z0-9+\/=]+/\1***/g' | head -40 || true)"
  if [ -n "$saida" ]; then
    n="$(printf '%s\n' "$saida" | wc -l)"
    bloqueios=$((bloqueios + n))
    echo "-- padrão: $p ($n ocorrência(s))"
    printf '%s\n' "$saida" | sed "s#^$ALVO/##" | sed 's/^/   /'
  fi
done
[ "$bloqueios" -eq 0 ] && echo "   nenhum"

echo
echo "== AVISOS =="
for p in "${AVISOS[@]}"; do
  saida="$(listar | xargs -0 grep -HnIE -- "$p" 2>/dev/null | head -40 || true)"
  if [ -n "$saida" ]; then
    n="$(printf '%s\n' "$saida" | wc -l)"
    avisos=$((avisos + n))
    echo "-- padrão: $p ($n ocorrência(s))"
    printf '%s\n' "$saida" | sed "s#^$ALVO/##" | cut -c1-160 | sed 's/^/   /'
  fi
done
[ "$avisos" -eq 0 ] && echo "   nenhum"

echo
echo "Resumo: $bloqueios bloqueante(s), $avisos aviso(s)"
[ "$bloqueios" -eq 0 ] || exit 1
exit 0
