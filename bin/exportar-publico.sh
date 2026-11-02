#!/usr/bin/env bash
# Monta a árvore da VERSÃO PÚBLICA do Ravi Developer a partir deste repositório (privado).
#
# O público é sempre derivado do privado, nunca editado à mão:
#   1. só arquivos versionados (git ls-files), nunca o que está solto na pasta;
#   2. fora: módulos só da casa, privado/, configuração local do editor e do Claude;
#   3. varredura de segredos (bin/varrer-segredos.sh) no resultado; se acusar, não publica.
#
# Uso:
#   bin/exportar-publico.sh [destino]          (padrão: publico-build/)
#   bin/exportar-publico.sh --so-varrer        (só roda a varredura no destino já montado)
set -euo pipefail

RAIZ="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DESTINO="${1:-$RAIZ/publico-build}"
SO_VARRER=0
if [ "${1:-}" = "--so-varrer" ]; then SO_VARRER=1; DESTINO="${2:-$RAIZ/publico-build}"; fi

# O que NUNCA vai para o público (prefixos relativos à raiz do repositório).
EXCLUIR=(
  "modulos/vpn/"
  "modulos/ioncube/"
  "modulos/ravigrowth/"
  "privado/"
  ".claude/"
  ".vscode/"
  "PROGRESS.md"
)

if [ "$SO_VARRER" -eq 0 ]; then
  cd "$RAIZ"
  if [ -e "$DESTINO" ]; then
    echo "Destino já existe: $DESTINO (apague antes para uma exportação limpa)" >&2
    exit 2
  fi
  mkdir -p "$DESTINO"

  git ls-files -z | while IFS= read -r -d '' arquivo; do
    for ex in "${EXCLUIR[@]}"; do
      case "$arquivo" in
        "$ex"*) continue 2 ;;
      esac
    done
    [ -f "$arquivo" ] || continue   # apagado na árvore e ainda não commitado
    mkdir -p "$DESTINO/$(dirname "$arquivo")"
    cp -p "$arquivo" "$DESTINO/$arquivo"
  done

  # Pastas vazias que o sistema espera (ficam fora do git por conter dados)
  for d in storage/logs storage/sessions storage/cache storage/uploads storage/sandbox; do
    mkdir -p "$DESTINO/$d"; touch "$DESTINO/$d/.gitkeep"
  done

  echo "Árvore pública montada em: $DESTINO ($(find "$DESTINO" -type f | wc -l) arquivos)"
fi

echo
echo "Varredura de segredos e vestígios internos:"
"$RAIZ/bin/varrer-segredos.sh" "$DESTINO"
