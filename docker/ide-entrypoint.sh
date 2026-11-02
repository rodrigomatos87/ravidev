#!/bin/bash
set -e
# IMPORTANTE: usar o HOME REAL do runtime (a imagem base sandbox define HOME=/home/sandbox
# mesmo rodando como root). O code-server lê user-data/extensões de $HOME/.local/share/code-server.
DD="$HOME/.local/share/code-server"
DIR="$DD/User"
mkdir -p "$DIR" "$DD/extensions"

# PERSISTÊNCIA: o user-data ($DD) pode estar num volume persistente (montado pelo IdeService),
# que sobrevive a recriações do container. Semeamos as extensões PRÉ-INSTALADAS a partir de
# /opt/ide-seed (caminho da imagem, não escondido pelo volume) — sem apagar as que o usuário
# instalou. Depois forçamos um rescan (remove o índice) para indexar pré-instaladas + do usuário.
if [ -d /opt/ide-seed/extensions ]; then
  cp -rf /opt/ide-seed/extensions/. "$DD/extensions/" 2>/dev/null || true
  rm -f "$DD/extensions/extensions.json" 2>/dev/null || true
fi

# Envelopa o `claude` (PATH + binário da extensão no volume persistente) com o wrapper de estado
# working/idle. RE-APLICA a cada boot: a extensão vive no volume e pode voltar ao original
# (reinstalação/atualização) — o script é idempotente (pula o que já está envelopado).
[ -x /usr/local/bin/wrap-claude.sh ] && /usr/local/bin/wrap-claude.sh || true

# Contexto permanente do projeto (memórias + fontes externas SSH), injetado por env base64 pelo
# IdeService. Fica em ~/.claude/CLAUDE.md (lido por padrão pelo Claude Code, FORA do /workspace →
# não aparece na árvore da IDE) e ~/.ravi/servers.json (lido pelo `ravi-ssh "<nome>"`).
mkdir -p "$HOME/.claude" "$HOME/.ravi"
if [ -n "$RAVI_CLAUDE_MD_B64" ]; then
  printf %s "$RAVI_CLAUDE_MD_B64" | base64 -d > "$HOME/.claude/CLAUDE.md" 2>/dev/null || true
else
  rm -f "$HOME/.claude/CLAUDE.md" 2>/dev/null || true
fi
# #1 — Ensina o Claude (na IDE) a usar o navegador ao vivo (painel "Navegador"). Só existe na IDE.
{
  printf '\n# Navegador ao vivo — VOCÊ TEM UM NAVEGADOR REAL (somente na IDE)\n'
  printf 'IMPORTANTE: SEMPRE que precisar testar/ver uma página, uma tela, uma UI, ou conferir se algo funciona no navegador, USE o comando `ravi-browser`. Ele controla um Chromium REAL que abre automaticamente como uma ABA no editor (à direita) — o usuário vê ao vivo. Não diga que não consegue navegar: você consegue, com `ravi-browser`.\n'
  printf 'Comandos:\n'
  printf '  ravi-browser open <url>      (abre/navega)\n  ravi-browser click <x> <y>  (clica nas coordenadas da imagem; 1280x800)\n  ravi-browser type "<texto>" | ravi-browser key Enter | ravi-browser back|forward|reload|url\n'
  printf '  ravi-browser scroll [down|up|top|bottom] [pixels]  -> ROLE a página você mesmo quando precisar ver o que está abaixo da dobra (sem argumentos = rola uma tela para baixo)\n'
  printf 'CLICAR/PREENCHER POR SELETOR (mais confiável que pixel — PREFIRA isto a click x y):\n'
  printf '  ravi-browser clickel "<seletor CSS>"  | ravi-browser clicktext "<texto visível>"  -> clica no elemento certo\n'
  printf '  ravi-browser fill "<seletor>" "<valor>"   -> preenche um campo\n'
  printf '  ravi-browser waitfor "<seletor>"          -> espera o elemento aparecer\n'
  printf 'MOBILE/TABLET (testar telas de celular e tablet):\n'
  printf '  ravi-browser mobile [iphone|iphone-max|iphone-se|pixel|galaxy]  | ravi-browser device [ipad|ipad-pro]  | ravi-browser desktop\n'
  printf '  ravi-browser viewport <larg> <alt> [mobile]   -> viewport custom (ex.: `ravi-browser viewport 360 740 1`). Tira screenshot depois para VER no tamanho do device.\n'
  printf 'TEMA e LOGIN:\n'
  printf '  ravi-browser theme <dark|light|auto>   -> emula prefers-color-scheme (testa os dois temas sem mexer no SO)\n'
  printf '  ravi-browser auth-save [arquivo]  | ravi-browser auth-load [arquivo]  -> salva/injeta cookies+localStorage (faça login UMA vez, salve, e reuse para pular o login nos testes)\n'
  printf '  ravi-browser waitidle [ms]  -> espera a REDE aquietar (use após navegar/clicar antes do screenshot; o shot/diag já fazem um settle curto automático)\n'
  printf 'INSPECIONAR (você consegue VER e diagnosticar, não só dirigir):\n'
  printf '  ravi-browser diag           -> DIAGNÓSTICO EM 1 CHAMADA: screenshot + erros de console + requests falhos + url/título juntos. USE quando algo parecer errado (depois LEIA o png).\n'
  printf 'VERIFICAR SEM SCREENSHOT (asserções rápidas — prefira a tirar print quando só precisa confirmar um fato):\n'
  printf '  ravi-browser exists "<seletor>"   -> {exists,count,visible}   |   ravi-browser count "<seletor>"   -> quantos\n'
  printf '  ravi-browser gettext "<seletor>"  -> texto do elemento (ex.: conferir um toast, um valor, um título)\n'
  printf '  ravi-browser request "<trecho-da-url>"  -> detalhe do request (status + corpo + headers/payload; corpo de API mesmo em 2xx)\n'
  printf '  ravi-browser elements       -> lista os elementos clicáveis com texto e coordenadas (ache o seletor/posição certos)\n'
  printf '  ravi-browser shotel "<seletor>" [arquivo]  -> screenshot de UM elemento (inspecionar um componente de perto)\n'
  printf '  ravi-browser snapshot       -> árvore ARIA (estrutura semântica da página, legível) — ótimo para entender a tela sem adivinhar\n'
  printf '  ravi-browser eval "<js>"    -> roda JS na página e devolve o resultado (ex.: `ravi-browser eval "return getComputedStyle(document.body).backgroundColor"`)\n'
  printf '  ravi-browser console        -> erros de console/JS + requests que falharam (para achar bugs)\n'
  printf '  ravi-browser network        -> log de rede (requests/respostas + CORPO das que falham — ótimo para depurar API)\n'
  printf '  ravi-browser shot [arquivo] [full] -> screenshot PNG; depois LEIA o arquivo (você tem visão) para VER o layout. Use "full" (ex.: `ravi-browser shot tela.png full`) para capturar a PÁGINA INTEIRA de uma vez, em vez de só a área visível\n'
  printf '  ravi-browser text | html    -> texto visível / HTML da página\n'
  printf 'O painel "Navegador" ABRE SOZINHO quando você usa o ravi-browser, e esconde quando você para.\n'
  printf 'ABAS: cada sessão do Claude tem a SUA PRÓPRIA aba no navegador (isolada das outras sessões e da aba do usuário) — o ravi-browser descobre a sua aba sozinho; não há como outra sessão interferir na sua página. A aba fecha sozinha quando a sua sessão termina. `ravi-browser tabs` lista as abas.\n\n'
  printf '## Como testar de verdade no navegador (importante)\n'
  printf 'Esta IDE é isolada e NÃO sobe o sistema completo (banco + serviços) localmente. Para validar VISUALMENTE no navegador real:\n'
  printf '  1) Aplique suas mudanças no SERVIDOR DE DEPLOY (use o fluxo de deploy do projeto, ou `ravi-ssh "deploy" "<comando>"` para o servidor cadastrado).\n'
  printf '  2) Abra a URL real do sistema com o ravi-browser e confira na prática (a IDE alcança a LAN e a internet).\n'
  printf 'Faça isso quando o usuário pedir para testar/validar no navegador. Para depurar o código em si, continue usando seus meios habituais.\n'
} >> "$HOME/.claude/CLAUDE.md" 2>/dev/null || true
if [ -n "$RAVI_SERVERS_B64" ]; then
  printf %s "$RAVI_SERVERS_B64" | base64 -d > "$HOME/.ravi/servers.json" 2>/dev/null && chmod 600 "$HOME/.ravi/servers.json" || true
else
  rm -f "$HOME/.ravi/servers.json" 2>/dev/null || true
fi
# Materializa as chaves privadas (auth=key) em ~/.ravi/keys/<id>, chmod 600 (p/ ravi-ssh e SFTP).
ravi-ctx-keys 2>/dev/null || true

LOCALE="${IDE_LOCALE:-pt-br}"
THEME="${IDE_THEME:-dark}"
# Tema padrão: Dracula no escuro (preferência do usuário); claro mantém um tema claro.
# ATENÇÃO: o LABEL do tema é "Dracula Theme" (não "Dracula") — usar o nome errado faz o VS Code
# não achar o tema e cair no padrão/claro.
if [ "$THEME" = "light" ]; then COLOR="Default Light Modern"; else COLOR="Dracula Theme"; fi

cat > "$DIR/settings.json" <<EOF
{
  "workbench.colorTheme": "$COLOR",
  "workbench.iconTheme": "material-icon-theme",
  "chat.disableAIFeatures": true,
  "telemetry.telemetryLevel": "off",
  "terminal.integrated.defaultProfile.linux": "bash",
  "terminal.integrated.profiles.linux": { "bash": { "path": "bash" }, "claude": { "path": "bash", "args": ["-lc", "/usr/bin/claude.real --continue 2>/dev/null || /usr/bin/claude.real; exec bash -l"] } },
  "terminal.integrated.enablePersistentSessions": true,
  "terminal.integrated.persistentSessionReviveProcess": "onExitAndWindowClose",
  "window.restoreWindows": "all",
  "workbench.editor.restoreViewState": true,
  "files.hotExit": "onExitAndWindowClose",
  "security.workspace.trust.enabled": false,
  "git.openRepositoryInParentFolders": "always",
  "git.confirmSync": false,
  "update.mode": "none",
  "extensions.autoCheckUpdates": false,
  "claudeCode.initialPermissionMode": "bypassPermissions",
  "claudeCode.allowDangerouslySkipPermissions": true,
  "locale": "$LOCALE"
}
EOF
printf '{ "locale": "%s" }\n' "$LOCALE" > "$DIR/argv.json"

# Claude Code >= 2.1.258: as settings claudeCode.initialPermissionMode e
# claudeCode.allowDangerouslySkipPermissions viraram escopo "machine" — no code-server elas
# passam a ser lidas de Machine/settings.json (o User/settings.json e ignorado para elas).
# Sem este arquivo a extensao esconde a opcao de bypass. Mantemos tambem no User (compat).
mkdir -p "$DD/Machine"
cat > "$DD/Machine/settings.json" <<EOF
{
  "claudeCode.initialPermissionMode": "bypassPermissions",
  "claudeCode.allowDangerouslySkipPermissions": true
}
EOF

# Git via PROXY do host — NENHUM token entra no container (nem do dono, nem de membro). O
# container só tem o HANDLE opaco (o mesmo do proxy do Claude, em ~/.ravi/claude-oauth-token).
# Um rewrite `url.insteadOf` (SÓ no gitconfig global DESTE container; o .git/config do workspace
# compartilhado fica com a URL limpa do GitHub) manda todo https://github.com/... para o
# ravi-git-proxy, e um credential helper entrega o handle como senha. O proxy valida dono/membro
# e injeta o token do DONO lá no host. Leitura de repo público NÃO autorizado passa anônima
# (dependências npm/composer via github continuam funcionando). Autor dos commits = o ATOR.
if [ -n "$GIT_REMOTE_URL" ]; then
  git config --global user.name "${GIT_USER_NAME:-Ravi Developer}"
  git config --global user.email "${GIT_USER_EMAIL:-noreply@localhost}"
  git config --global --add safe.directory '*'

  GITPROXY="${RAVI_GIT_PROXY:-http://172.17.0.1:8789}"
  # Helper lê o handle do ARQUIVO (o code-server não repassa o env do container aos filhos).
  cat > /usr/local/bin/ravi-git-credential <<'GEOF'
#!/bin/sh
[ "$1" = "get" ] || exit 0
H="$(cat "$HOME/.ravi/claude-oauth-token" 2>/dev/null)"
[ -n "$H" ] || exit 0
printf 'username=ravi\npassword=%s\n' "$H"
GEOF
  chmod +x /usr/local/bin/ravi-git-credential
  git config --global --unset-all credential.helper 2>/dev/null || true  # nunca cair num store herdado
  git config --global credential."$GITPROXY".helper '!/usr/local/bin/ravi-git-credential'
  git config --global "url.$GITPROXY/git/.insteadOf" "https://github.com/"
  export GIT_TERMINAL_PROMPT=0

  # Garante que o workspace seja um repo git com o remote certo (URL LIMPA do GitHub — o rewrite
  # acima só existe dentro do container). Init só se ainda não houver .git.
  ( cd /workspace 2>/dev/null && {
      if [ ! -d .git ]; then git init -q && git checkout -q -B "${GIT_BRANCH:-main}" 2>/dev/null || true; fi
      git remote remove origin 2>/dev/null || true
      git remote add origin "$GIT_REMOTE_URL" 2>/dev/null || true
    } ) || true
fi
# Purga resíduos do esquema antigo (containers migrados): token cru e credential store em arquivo.
rm -f "$HOME/.ravi/gh-token" "$HOME/.ravi/git-credentials" /usr/local/bin/gh-askpass 2>/dev/null || true

# ── Fragmentos de módulos opcionais do host (modulos/<nome>/ide/entrypoint.d/*.sh) ──────────
# O IdeService monta a pasta de cada módulo em /etc/ravi/entrypoint.d (somente leitura). Cada
# fragmento roda aqui com o ambiente já pronto (HOME, CLAUDE.md semeado, git configurado).
# Sem módulos, a pasta não existe e nada acontece.
if [ -d /etc/ravi/entrypoint.d ]; then
  for _frag in /etc/ravi/entrypoint.d/*.sh; do
    [ -r "$_frag" ] && . "$_frag" || true
  done
fi

# ── Claude Code: retomada limpa (sem onboarding/login/trust) ─────────────────────────────────
# O ~/.claude.json fica no HOME efêmero (não montado) → recriado a cada container. Semeamos aqui,
# a cada boot, para que o `claude` (terminal e extensão) NUNCA mostre seletor de tema, "Select
# login method" nem "trust this folder" — a IDE volta direto à conversa. Merge preservando o resto.
node -e '
  const fs=require("fs");
  const p=(process.env.HOME||"/home/sandbox")+"/.claude.json";
  let c={}; try{ c=JSON.parse(fs.readFileSync(p,"utf8")); }catch(e){}
  c.hasCompletedOnboarding=true;
  c.projects=c.projects||{};
  const w=c.projects["/workspace"]||{};
  w.hasTrustDialogAccepted=true;
  w.projectOnboardingSeenCount=Math.max(1, w.projectOnboardingSeenCount||0);
  w.hasClaudeMdExternalIncludesApproved=true;
  w.hasClaudeMdExternalIncludesWarningShown=true;
  c.projects["/workspace"]=w;
  fs.writeFileSync(p, JSON.stringify(c,null,2));
' 2>/dev/null || true
chmod 600 "$HOME/.claude.json" 2>/dev/null || true

# Modelo ATIVO do Claude Code para a EXTENSÃO: a partir do CLI 2.1.220 a extensão NÃO aplica mais o
# ANTHROPIC_MODEL como modelo ativo (só o oferece como entrada no picker) e cai no "Default"
# (Sonnet 5). O que a extensão respeita é a chave `model` em ~/.claude/settings.json. Semeamos a
# cada boot (o HOME é efêmero) com merge, preservando o resto do arquivo.
# Junto vai o `modelPicker` (CLI >= 2.1.258): a lineup completa do seletor do Ravi como linhas do
# /model picker (extensão E terminal), vinda do IdeService em RAVI_MODEL_PICKER (base64 de JSON).
if [ -n "$ANTHROPIC_MODEL" ] || [ -n "$RAVI_MODEL_PICKER" ]; then
  mkdir -p "$HOME/.claude" 2>/dev/null || true
  node -e '
    const fs=require("fs");
    const p=(process.env.HOME||"/home/sandbox")+"/.claude/settings.json";
    let c={}; try{ c=JSON.parse(fs.readFileSync(p,"utf8")); }catch(e){}
    if(process.env.ANTHROPIC_MODEL) c.model=process.env.ANTHROPIC_MODEL;
    try{
      const rows=JSON.parse(Buffer.from(process.env.RAVI_MODEL_PICKER||"",
        "base64").toString("utf8"));
      // replaceBuiltInOptions (CLI >= 2.1.281): sem isto o picker mostra PRIMEIRO as linhas
      // builtin do CLI — que incluem "Fable 5.1" SEM o [1m] (abre em 200k) — e a lineup do
      // Ravi vira "+N models" escondida. Com a flag, o picker é só Default + a lineup do Ravi.
      if(Array.isArray(rows)&&rows.length) c.modelPicker={options:rows,replaceBuiltInOptions:true};
    }catch(e){}
    fs.writeFileSync(p, JSON.stringify(c,null,2));
  ' 2>/dev/null || true
  chmod 600 "$HOME/.claude/settings.json" 2>/dev/null || true
fi

# Credencial do Claude Code para o TERMINAL/binário real: o code-server NÃO repassa o env do
# container aos processos filhos (mesmo motivo do GITHUB_TOKEN acima) → gravamos num arquivo e
# exportamos via /etc/profile.d (login) e ~/.bashrc (não-login). Sem isto, um `claude` no terminal
# pede login. IMPORTANTE: CLAUDE_CODE_OAUTH_TOKEN aqui é um HANDLE OPACO, não o token real — o token
# fica no host e o proxy (ANTHROPIC_BASE_URL) o injeta. Por isso exportamos também o base URL.
if [ -n "$CLAUDE_CODE_OAUTH_TOKEN" ]; then
  printf '%s' "$CLAUDE_CODE_OAUTH_TOKEN" > "$HOME/.ravi/claude-oauth-token"
  chmod 600 "$HOME/.ravi/claude-oauth-token"
  {
    printf '%s\n' '[ -f "$HOME/.ravi/claude-oauth-token" ] && export CLAUDE_CODE_OAUTH_TOKEN="$(cat "$HOME/.ravi/claude-oauth-token")"'
    [ -n "$ANTHROPIC_BASE_URL" ] && printf 'export ANTHROPIC_BASE_URL=%s\n' "$ANTHROPIC_BASE_URL"
    [ -n "$ANTHROPIC_MODEL" ] && printf 'export ANTHROPIC_MODEL=%s\n' "$ANTHROPIC_MODEL"
    # ssh-agent do HOST (chaves nunca entram no container): ravi-ssh/ssh/sftp assinam por aqui.
    printf '%s\n' '[ -S "$HOME/.ravi-ssh/agent.sock" ] && export SSH_AUTH_SOCK="$HOME/.ravi-ssh/agent.sock"'
    printf 'export GIT_TERMINAL_PROMPT=0\n'
  } > /etc/profile.d/ravi-claude.sh
  chmod 644 /etc/profile.d/ravi-claude.sh
fi
# ~/.bashrc (terminal não-login): exporta o token e faz `claude` = binário REAL no terminal. O
# wrapped (shim do claude-wrapper.js) canaliza o stdin por pipe e QUEBRA o uso interativo no
# terminal; o real preserva a TTY. A extensão segue usando o wrapped (bolinha de estado).
if ! grep -q 'RAVI_CLAUDE_RC' "$HOME/.bashrc" 2>/dev/null; then
  cat >> "$HOME/.bashrc" <<'BRC'
# RAVI_CLAUDE_RC
[ -f /etc/profile.d/ravi-claude.sh ] && . /etc/profile.d/ravi-claude.sh
[ -x /usr/bin/claude.real ] && alias claude='/usr/bin/claude.real'
BRC
fi

exec code-server --bind-addr 0.0.0.0:8080 --auth none --disable-telemetry --disable-update-check --locale "$LOCALE" /workspace
