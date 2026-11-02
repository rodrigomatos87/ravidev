'use strict';
/**
 * #1 — Extensão "Navegador ao vivo". Abre como ABA no EDITOR (à direita, onde se desenvolve),
 * NÃO na barra lateral. Abre SOZINHO quando o Claude usa o `ravi-browser` (o host marca atividade),
 * e tem um botão na barra de status + comando na paleta. O processo da extensão (Node, no container)
 * faz a ponte entre a aba (webview) e o host do navegador (127.0.0.1:9223): puxa os frames do
 * screencast e repassa, e encaminha os controles (navegar/clicar/digitar).
 *
 * v2 — ABAS: o host tem uma aba por sessão do Claude (+ a aba "Você" da barra de URL). O painel
 * mostra uma faixa de abas; no modo AUTO ele segue a aba com atividade mais recente (a sessão que
 * está dirigindo agora); clicar numa aba FIXA a visão nela (botão "auto" volta a seguir).
 */
const vscode = require('vscode');
const http = require('http');
const cp = require('child_process');

const PORT = parseInt(process.env.RAVI_BROWSER_PORT || '9223', 10);
const BASE = { host: '127.0.0.1', port: PORT };

function req(method, path, body) {
  return new Promise((resolve) => {
    const data = body ? JSON.stringify(body) : null;
    const r = http.request({ ...BASE, method, path, headers: data ? { 'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(data) } : {} }, (res) => {
      const chunks = [];
      res.on('data', c => chunks.push(c));
      res.on('end', () => resolve({ status: res.statusCode, buf: Buffer.concat(chunks) }));
    });
    r.on('error', () => resolve({ status: 0, buf: Buffer.alloc(0) }));
    if (data) r.write(data);
    r.end();
  });
}

let hostStarted = false;
function ensureHost() {
  if (hostStarted) return;
  hostStarted = true;
  try { cp.spawn('node', ['/usr/local/lib/ravi-browser/host.js'], { detached: true, stdio: 'ignore' }).unref(); } catch (_) {}
}

let panel = null;       // aba (WebviewPanel) no editor
let pumpAlive = false;
let extUri = null;
let viewTab = null;     // id da aba do host que o painel está mostrando
let pinned = false;     // true = usuário escolheu a aba à mão (não seguir a atividade)

// Decide qual aba mostrar: fixada pelo usuário (se ainda existir) ou a ativa (modo auto).
function pickTab(st) {
  const list = Array.isArray(st.tabs) ? st.tabs : [];
  if (pinned && viewTab && list.some(t => t.id === viewTab)) return viewTab;
  pinned = false;
  if (st.active && list.some(t => t.id === st.active)) return st.active;
  return list.length ? list[list.length - 1].id : null;
}

function startPump() {
  if (pumpAlive) return;
  pumpAlive = true;
  (async function pump() {
    while (pumpAlive && panel) {
      const stR = await req('GET', '/state');
      let st = null;
      if (stR.status === 200) { try { st = JSON.parse(stR.buf.toString()); } catch (_) {} }
      if (st) {
        if (Array.isArray(st.tabs)) viewTab = pickTab(st); // host v2
        else viewTab = null;                                // host v1 (sem abas): frame único
        try { panel.webview.postMessage({ type: 'state', state: st, view: viewTab, pinned }); } catch (_) {}
      }
      const r = await req('GET', '/frame.jpg' + (viewTab ? '?tab=' + encodeURIComponent(viewTab) : ''));
      if (r.status === 200 && r.buf.length) panel.webview.postMessage({ type: 'frame', data: r.buf.toString('base64') });
      await new Promise(res => setTimeout(res, 250));
    }
  })();
}

function openPanel(reveal) {
  ensureHost();
  if (panel) { if (reveal) panel.reveal(vscode.ViewColumn.Beside, true); return; }
  panel = vscode.window.createWebviewPanel(
    'raviBrowser', 'Navegador',
    { viewColumn: vscode.ViewColumn.Beside, preserveFocus: true },
    { enableScripts: true, retainContextWhenHidden: true }
  );
  // Ícone da ABA: branco no tema escuro (visível), o original no claro. Só o ícone da aba muda.
  try { panel.iconPath = { light: vscode.Uri.joinPath(extUri, 'media', 'globe.svg'), dark: vscode.Uri.joinPath(extUri, 'media', 'globe-white.svg') }; } catch (_) {}
  panel.webview.html = html();
  panel.onDidDispose(() => { panel = null; pumpAlive = false; });
  panel.webview.onDidReceiveMessage(async (m) => {
    // Controles do usuário vão para a aba que ele está VENDO. Sem aba (host v1 ou nenhuma aberta),
    // o host usa a aba em foco / cria a aba "main" (a do usuário).
    const tab = viewTab ? { tab: viewTab } : {};
    if (m.type === 'selectTab') { viewTab = m.tab; pinned = true; return; }
    if (m.type === 'autoTab') { pinned = false; return; }
    if (m.type === 'closeTab') { await req('POST', '/tab/close', { tab: m.tab }); if (viewTab === m.tab) { viewTab = null; pinned = false; } return; }
    if (m.type === 'navigate') {
      // URL digitada pelo usuário sem aba escolhida → aba "Você" (não sequestra a aba de uma sessão do Claude).
      const t = (pinned && viewTab) ? { tab: viewTab } : { tab: 'main' };
      if (!pinned) { viewTab = 'main'; pinned = true; }
      await req('POST', '/navigate', { url: m.url, ...t });
    }
    else if (m.type === 'click') await req('POST', '/click', { x: m.x, y: m.y, ...tab });
    else if (m.type === 'type') await req('POST', '/type', { text: m.text, ...tab });
    else if (m.type === 'key') await req('POST', '/key', { key: m.key, ...tab });
    else if (m.type === 'scroll') await req('POST', '/scroll', { dir: m.dir, amount: m.amount, ...tab });
    else if (m.type === 'device') await req('POST', '/device', { name: m.name, ...tab });
    else if (m.type === 'theme') await req('POST', '/theme', { scheme: m.scheme, ...tab });
    else if (['back', 'forward', 'reload'].includes(m.type)) await req('POST', '/' + m.type, { ...tab });
  });
  startPump();
}

// Ícone na barra lateral esquerda (Activity Bar). Ao clicar (view fica visível), abre o navegador
// na ABA À DIREITA e recolhe a barra lateral — então nada fica ocupando a área dos arquivos.
const launcher = {
  resolveWebviewView(wv) {
    wv.webview.options = { enableScripts: false };
    wv.webview.html = '<!DOCTYPE html><body style="font-family:var(--vscode-font-family);color:var(--vscode-descriptionForeground);padding:10px;font-size:12px">Abrindo o navegador na aba à direita…</body>';
    const trigger = () => { openPanel(true); setTimeout(() => { try { vscode.commands.executeCommand('workbench.action.closeSidebar'); } catch (_) {} }, 120); };
    wv.onDidChangeVisibility(() => { if (wv.visible) trigger(); });
    trigger();
  }
};

function activate(context) {
  extUri = context.extensionUri;

  context.subscriptions.push(vscode.commands.registerCommand('raviBrowser.open', () => openPanel(true)));
  context.subscriptions.push(
    vscode.window.registerWebviewViewProvider('raviBrowser.launcher', launcher, { webviewOptions: { retainContextWhenHidden: true } })
  );

  // ABRE SOZINHO: enquanto o Claude (ou usuário) navega, garante a aba aberta no editor.
  const timer = setInterval(async () => {
    const r = await req('GET', '/state');
    if (r.status !== 200) return;
    let st; try { st = JSON.parse(r.buf.toString()); } catch (_) { return; }
    const active = st.ready && typeof st.idleMs === 'number' && st.idleMs >= 0 && st.idleMs < 8000;
    if (active && !panel) openPanel(true);
  }, 1000);
  context.subscriptions.push({ dispose: () => clearInterval(timer) });
}
function deactivate() {}

function html() {
  return `<!DOCTYPE html><html><head><meta charset="utf-8">
<style>
  body { margin:0; padding:0; color: var(--vscode-foreground); font-family: var(--vscode-font-family); display:flex; flex-direction:column; height:100vh; }
  .tabs { display:flex; gap:2px; padding:4px 6px 0; align-items:flex-end; border-bottom:1px solid var(--vscode-panel-border); overflow-x:auto; min-height:26px; }
  .tabs:empty { display:none; }
  .tab { display:flex; align-items:center; gap:6px; padding:4px 8px; font-size:12px; border-radius:4px 4px 0 0; cursor:pointer; white-space:nowrap; background: var(--vscode-tab-inactiveBackground, #2d2d2d); color: var(--vscode-tab-inactiveForeground, #999); border:1px solid transparent; border-bottom:0; }
  .tab.sel { background: var(--vscode-tab-activeBackground, #1e1e1e); color: var(--vscode-tab-activeForeground, #fff); border-color: var(--vscode-panel-border); }
  .tab .dot { width:7px; height:7px; border-radius:50%; background: var(--vscode-descriptionForeground); opacity:.5; }
  .tab.busy .dot { background:#3fb950; opacity:1; }
  .tab .x { opacity:.6; padding:0 2px; } .tab .x:hover { opacity:1; }
  .tab .u { max-width:180px; overflow:hidden; text-overflow:ellipsis; opacity:.7; font-size:11px; }
  .tabs .auto { margin-left:auto; font-size:11px; padding:3px 7px; border-radius:4px; border:1px solid var(--vscode-panel-border); background:transparent; color: var(--vscode-descriptionForeground); cursor:pointer; }
  .tabs .auto.on { color: var(--vscode-button-foreground,#fff); background: var(--vscode-button-background,#0e639c); border-color:transparent; }
  .bar { display:flex; gap:4px; padding:6px; align-items:center; border-bottom:1px solid var(--vscode-panel-border); }
  .bar button { background: var(--vscode-button-secondaryBackground, #3a3d41); color: var(--vscode-button-foreground,#fff); border:0; border-radius:4px; padding:4px 8px; cursor:pointer; }
  .bar input { flex:1; min-width:0; background: var(--vscode-input-background); color: var(--vscode-input-foreground); border:1px solid var(--vscode-input-border,#3c3c3c); border-radius:4px; padding:5px 8px; }
  .bar select { background: var(--vscode-dropdown-background,#3a3d41); color: var(--vscode-dropdown-foreground,#fff); border:1px solid var(--vscode-dropdown-border,#3c3c3c); border-radius:4px; padding:4px 6px; cursor:pointer; }
  .screen-wrap { flex:1; overflow:auto; background:#111; display:flex; align-items:flex-start; justify-content:center; }
  #screen { max-width:100%; display:block; cursor:crosshair; }
  #screen.mob { margin-top:10px; border-radius:14px; box-shadow:0 0 0 8px #1c1c1c, 0 6px 22px rgba(0,0,0,.6); }
  .hint { font-size:11px; color: var(--vscode-descriptionForeground); padding:4px 8px; }
</style></head>
<body>
  <div class="tabs" id="tabs"></div>
  <div class="bar">
    <button id="back" title="Voltar">◀</button>
    <button id="fwd" title="Avançar">▶</button>
    <button id="reload" title="Recarregar">⟳</button>
    <input id="url" placeholder="Digite uma URL e Enter…" />
    <select id="device" title="Emular dispositivo (mobile/tablet)">
      <option value="desktop">Desktop</option>
      <option value="iphone">iPhone</option>
      <option value="iphone-max">iPhone Max</option>
      <option value="iphone-se">iPhone SE</option>
      <option value="ipad">iPad</option>
      <option value="ipad-pro">iPad Pro</option>
      <option value="pixel">Pixel</option>
      <option value="galaxy">Galaxy</option>
    </select>
    <button id="theme" title="Emular tema da página (auto/escuro/claro)">tema: auto</button>
    <button id="up" title="Rolar para cima">▲</button>
    <button id="down" title="Rolar para baixo">▼</button>
  </div>
  <div class="screen-wrap"><img id="screen" /></div>
  <div class="hint">O Claude também dirige este navegador (comando <code>ravi-browser</code>). Cada sessão do Claude tem a sua aba. Clique na imagem para clicar; roda do mouse rola a página.</div>
  <script>
    const vscode = acquireVsCodeApi();
    const screen = document.getElementById('screen');
    const url = document.getElementById('url');
    const deviceSel = document.getElementById('device');
    const tabsEl = document.getElementById('tabs');
    let natW = 1280, natH = 800;
    let lastTabsKey = '';
    function renderTabs(list, view, pinned) {
      const key = JSON.stringify(list.map(t => [t.id, t.label, t.url, t.idleMs < 8000])) + '|' + view + '|' + pinned;
      if (key === lastTabsKey) return;
      lastTabsKey = key;
      tabsEl.innerHTML = '';
      if (!list.length) return;
      for (const t of list) {
        const el = document.createElement('div');
        el.className = 'tab' + (t.id === view ? ' sel' : '') + (t.idleMs >= 0 && t.idleMs < 8000 ? ' busy' : '');
        el.title = (t.kind === 'claude' ? 'Sessão do Claude (pid ' + t.pid + ')' : 'Aba do usuário') + '\\n' + (t.url || '');
        const dot = document.createElement('span'); dot.className = 'dot'; el.appendChild(dot);
        const lb = document.createElement('span'); lb.textContent = t.label; el.appendChild(lb);
        if (t.url && t.url !== 'about:blank') { const u = document.createElement('span'); u.className = 'u'; u.textContent = t.url.replace(/^https?:\\/\\//, ''); el.appendChild(u); }
        if (t.kind !== 'claude') { const x = document.createElement('span'); x.className = 'x'; x.textContent = '×'; x.title = 'Fechar aba'; x.onclick = (e) => { e.stopPropagation(); vscode.postMessage({ type: 'closeTab', tab: t.id }); }; el.appendChild(x); }
        el.onclick = () => vscode.postMessage({ type: 'selectTab', tab: t.id });
        tabsEl.appendChild(el);
      }
      const auto = document.createElement('button');
      auto.className = 'auto' + (pinned ? '' : ' on');
      auto.textContent = pinned ? 'seguir atividade' : 'auto';
      auto.title = pinned ? 'Voltar a seguir a sessão que está usando o navegador' : 'Seguindo a sessão que está usando o navegador (clique numa aba para fixar)';
      auto.onclick = () => vscode.postMessage({ type: 'autoTab' });
      tabsEl.appendChild(auto);
    }
    window.addEventListener('message', (e) => {
      const m = e.data;
      if (m.type === 'frame') { screen.src = 'data:image/jpeg;base64,' + m.data; }
      else if (m.type === 'state' && m.state) {
        const list = Array.isArray(m.state.tabs) ? m.state.tabs : [];
        renderTabs(list, m.view, !!m.pinned);
        // Dados da aba VISTA (ou os de topo = aba em foco, no host antigo).
        const cur = list.find(t => t.id === m.view) || m.state;
        // Mantém as coordenadas de clique coladas no viewport real (muda no modo mobile).
        if (cur.w) natW = cur.w;
        if (cur.h) natH = cur.h;
        screen.classList.toggle('mob', !!cur.mobile);
        if (document.activeElement !== url) url.value = (!cur.url || cur.url === 'about:blank') ? '' : cur.url;
        if (document.activeElement !== deviceSel && cur.device) {
          for (const o of deviceSel.options) { if (o.value === cur.device) { deviceSel.value = cur.device; break; } }
        }
      }
    });
    deviceSel.addEventListener('change', () => vscode.postMessage({ type: 'device', name: deviceSel.value }));
    // Botão de tema: cicla auto -> escuro -> claro (emula prefers-color-scheme na página).
    const themeBtn = document.getElementById('theme');
    const THEMES = ['no-preference', 'dark', 'light'], THEME_LBL = { 'no-preference': 'auto', dark: 'escuro', light: 'claro' };
    let themeIx = 0;
    themeBtn.addEventListener('click', () => {
      themeIx = (themeIx + 1) % THEMES.length;
      const sc = THEMES[themeIx];
      themeBtn.textContent = 'tema: ' + THEME_LBL[sc];
      vscode.postMessage({ type: 'theme', scheme: sc === 'no-preference' ? 'auto' : sc });
    });
    url.addEventListener('keydown', (e) => { if (e.key === 'Enter') vscode.postMessage({ type: 'navigate', url: url.value }); });
    document.getElementById('back').onclick = () => vscode.postMessage({ type: 'back' });
    document.getElementById('fwd').onclick = () => vscode.postMessage({ type: 'forward' });
    document.getElementById('reload').onclick = () => vscode.postMessage({ type: 'reload' });
    document.getElementById('up').onclick = () => vscode.postMessage({ type: 'scroll', dir: 'up' });
    document.getElementById('down').onclick = () => vscode.postMessage({ type: 'scroll', dir: 'down' });
    screen.addEventListener('click', (e) => {
      const r = screen.getBoundingClientRect();
      const x = Math.round((e.clientX - r.left) / r.width * natW);
      const y = Math.round((e.clientY - r.top) / r.height * natH);
      vscode.postMessage({ type: 'click', x, y });
    });
    let wheelAcc = 0, wheelTimer = null;
    screen.parentElement.addEventListener('wheel', (e) => {
      wheelAcc += e.deltaY;
      if (wheelTimer) return;
      wheelTimer = setTimeout(() => {
        vscode.postMessage({ type: 'scroll', dir: wheelAcc >= 0 ? 'down' : 'up', amount: Math.min(900, Math.abs(Math.round(wheelAcc))) || 300 });
        wheelAcc = 0; wheelTimer = null;
      }, 120);
    }, { passive: true });
  </script>
</body></html>`;
}

module.exports = { activate, deactivate };
