#!/bin/sh
# wrap-claude.sh — envelopa o(s) binário(s) `claude` com o claude-wrapper.js (estado working/idle).
#
# Envelopa OS DOIS pontos de entrada:
#   1) o `claude` do PATH (usado pelo TERMINAL integrado do VS Code);
#   2) o binário nativo da EXTENSÃO Claude Code
#      (.../extensions/anthropic.claude-code-*/resources/native-binary/claude).
#
# IDEMPOTENTE e re-aplicável no boot: a extensão fica no volume persistente por usuário/projeto
# e pode voltar ao original (reinstalação/atualização) — por isso o entrypoint chama isto a cada
# boot. Detecta "já envelopado" pelo marcador RAVI_CLAUDE_WRAPPER no shim; se o alvo for um
# binário real (sem marcador), (re)envelopa, sobrescrevendo um .real eventualmente velho.

WRAPPER="/usr/local/lib/ravi/claude-wrapper.js"
NODE_BIN="$(command -v node 2>/dev/null || echo /usr/bin/node)"

wrap_one() {
    target="$1"
    [ -e "$target" ] || return 0
    # Já é o nosso shim? (grep -a: tratar binário como texto). Então nada a fazer.
    if grep -qa RAVI_CLAUDE_WRAPPER "$target" 2>/dev/null; then
        return 0
    fi
    real="$target.real"
    # Move o real para .real (sobrescreve um .real antigo de uma versão anterior) e instala o shim.
    mv -f "$target" "$real" 2>/dev/null || return 0
    cat > "$target" <<EOF
#!/bin/sh
# RAVI_CLAUDE_WRAPPER — envelope de estado working/idle (não remover).
exec "$NODE_BIN" "$WRAPPER" "$real" "\$@"
EOF
    chmod +x "$target" 2>/dev/null || true
    echo "wrap-claude: envelopado $target (real em $real)"
}

# 1) PATH (terminal)
PATH_CLAUDE="$(command -v claude 2>/dev/null)"
[ -n "$PATH_CLAUDE" ] && wrap_one "$PATH_CLAUDE"

# 2) Binário(s) da extensão (no volume persistente do usuário e/ou na seed da imagem).
DD="${HOME:-/root}/.local/share/code-server"
for bin in \
    "$DD"/extensions/anthropic.claude-code-*/resources/native-binary/claude \
    /opt/ide-seed/extensions/anthropic.claude-code-*/resources/native-binary/claude
do
    [ -e "$bin" ] && wrap_one "$bin"
done

exit 0
