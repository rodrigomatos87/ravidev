'use strict';

/**
 * Ravi Developer — Harness de QA Visual (navegador real + screencast ao vivo).
 *
 * Abre um Chromium REAL (Playwright) contra a app entregue, faz LOGIN de verdade
 * (credenciais demo do app.json), navega por cada rota e dá cliques "seguros" na UI.
 * Enquanto navega, grava um SCREENCAST via CDP: o frame mais recente é escrito de
 * forma ATÔMICA em <out>/frame.jpg (tmp + rename), com legenda/URL em status.json e
 * um log em captions.jsonl — é isso que o chat exibe ao vivo (~4fps).
 *
 * Ao final, escreve report.json com os erros vistos (console, exceções de página,
 * requisições falhas/HTTP>=400) e um resumo. O AGENTE de QA lê os screenshots
 * (visão) + esse report para corrigir a UI no loop de polimento.
 *
 * É um SCRIPT autônomo: roda dentro do container de QA (--user root) com o Chromium
 * do Playwright já presente na imagem. Não tem dependências além de 'playwright'
 * (resolvido por NODE_PATH=/usr/lib/node_modules) — ver QaVisualService no PHP.
 *
 * Entradas (via env):
 *   QA_BASE_URL       URL onde a app responde (ex.: http://127.0.0.1:8080). Obrigatório.
 *   QA_OUT_DIR        Dir de saída do screencast/report. Default: /workspace/.maestro/screencast
 *   QA_CONFIG         Caminho de um JSON com overrides (rotas, login, fps...). Opcional.
 *   QA_REQUIRES_LOGIN "1" para tentar login. Default: do config.
 *   QA_USER/QA_PASS   Credenciais demo (também aceitas via config.login).
 *   QA_FPS            Frames/s gravados em disco (default 4).
 *   QA_QUALITY        Qualidade JPEG do screencast (default 55).
 *   QA_MAX_WIDTH      Largura máx. do frame (default 900).
 *   QA_MAX_ROUTES     Máx. de rotas visitadas (default 12).
 *   QA_BUDGET_MS      Orçamento total de navegação em ms (default 90000).
 */

const fs = require('fs');
const path = require('path');

// ── Config ───────────────────────────────────────────────────────────────────
const cfgFromFile = (() => {
  const p = process.env.QA_CONFIG;
  if (p && fs.existsSync(p)) {
    try { return JSON.parse(fs.readFileSync(p, 'utf8')) || {}; } catch (_) { return {}; }
  }
  return {};
})();

const BASE_URL = (process.env.QA_BASE_URL || cfgFromFile.base_url || '').replace(/\/+$/, '');
const OUT_DIR = process.env.QA_OUT_DIR || cfgFromFile.out_dir || '/workspace/.maestro/screencast';
const FPS = parseInt(process.env.QA_FPS || cfgFromFile.fps || '4', 10);
const QUALITY = parseInt(process.env.QA_QUALITY || cfgFromFile.quality || '55', 10);
const MAX_WIDTH = parseInt(process.env.QA_MAX_WIDTH || cfgFromFile.max_width || '900', 10);
const MAX_ROUTES = parseInt(process.env.QA_MAX_ROUTES || cfgFromFile.max_routes || '12', 10);
const BUDGET_MS = parseInt(process.env.QA_BUDGET_MS || cfgFromFile.budget_ms || '90000', 10);

const login = cfgFromFile.login || {};
const REQUIRES_LOGIN = (process.env.QA_REQUIRES_LOGIN === '1') ||
  (process.env.QA_REQUIRES_LOGIN !== '0' && !!cfgFromFile.requires_login);
const USER = process.env.QA_USER || login.user || '';
const PASS = process.env.QA_PASS || login.password || '';
const LOGIN_PATHS = (login.paths && login.paths.length)
  ? login.paths
  : ['/', '/login', '/login.php', '/index.php?r=login', '/?r=login', '/entrar', '/auth/login'];
const CONFIG_ROUTES = Array.isArray(cfgFromFile.routes) ? cfgFromFile.routes : null;

// Palavras que marcam ações DESTRUTIVAS — nunca clicamos nelas (preservar estado).
const DESTRUCTIVE = /(excluir|deletar|delete|remover|remove|apagar|sair|logout|sign\s*out|desconectar|cancelar\s*conta|zerar|reset|limpar|esvaziar|drop)/i;

const FRAME_INTERVAL_MS = Math.max(60, Math.floor(1000 / Math.max(1, FPS)));

// ── Saída atômica ──────────────────────────────────────────────────────────────
fs.mkdirSync(OUT_DIR, { recursive: true });
try { fs.chmodSync(OUT_DIR, 0o777); } catch (_) {}

const FRAME_PATH = path.join(OUT_DIR, 'frame.jpg');
const STATUS_PATH = path.join(OUT_DIR, 'status.json');
const CAPTIONS_PATH = path.join(OUT_DIR, 'captions.jsonl');
const REPORT_PATH = path.join(OUT_DIR, 'report.json');
// Stills por rota (página inteira), em alta — é o que o AGENTE de QA lê com visão.
// Separados do frame.jpg (efêmero, do streaming ao vivo para o humano).
const SHOTS_DIR = path.join(OUT_DIR, 'shots');
fs.mkdirSync(SHOTS_DIR, { recursive: true });
try { fs.chmodSync(SHOTS_DIR, 0o777); } catch (_) {}

let frameSeq = 0;
function writeAtomic(target, buf) {
  const tmp = target + '.tmp-' + process.pid;
  fs.writeFileSync(tmp, buf);
  try { fs.chmodSync(tmp, 0o666); } catch (_) {}
  fs.renameSync(tmp, target); // rename é atômico no mesmo filesystem
}

const state = {
  url: BASE_URL || '',
  caption: 'Iniciando…',
  step: 0,
  total: 0,
  done: false,
  frames: 0,
  started_at: Date.now(),
};
function writeStatus() {
  const s = { ...state, ts: Date.now() };
  try { writeAtomic(STATUS_PATH, Buffer.from(JSON.stringify(s))); } catch (_) {}
}
function caption(text, extra) {
  state.caption = text;
  writeStatus();
  try {
    fs.appendFileSync(CAPTIONS_PATH, JSON.stringify({ ts: Date.now(), url: state.url, caption: text, ...(extra || {}) }) + '\n');
  } catch (_) {}
  log('· ' + text);
}
function setUrl(u) { state.url = u; writeStatus(); }

function log(...a) { try { process.stdout.write(a.join(' ') + '\n'); } catch (_) {} }

// ── Coleta de erros ──────────────────────────────────────────────────────────
const report = {
  ok: false,
  base_url: BASE_URL,
  login: { required: REQUIRES_LOGIN, attempted: false, success: null, detail: '' },
  visited: [],          // [{path, url, status, ok}]
  console_errors: [],   // [{url, text}]
  page_errors: [],      // [{url, text}]
  failed_requests: [],  // [{url, method, error}]
  http_errors: [],      // [{url, status}]
  clicks: [],           // [{path, label}]
  shots: [],            // [{file, path, caption}] — stills que o agente de QA lê (visão)
  steps: [],            // narrativa
  summary: '',
  started_at: new Date(state.started_at).toISOString(),
};
const seenConsole = new Set();
function pushUnique(arr, key, item) {
  if (seenConsole.has(key)) return;
  seenConsole.add(key);
  arr.push(item);
}
function currentErrorCount() {
  return report.console_errors.length + report.page_errors.length +
    report.failed_requests.length + report.http_errors.length;
}
function writeReport() {
  report.frames = state.frames;
  report.finished_at = new Date().toISOString();
  report.duration_ms = Date.now() - state.started_at;
  report.error_count = currentErrorCount();
  report.ok = report.error_count === 0 && (!REQUIRES_LOGIN || report.login.success !== false);
  try { writeAtomic(REPORT_PATH, Buffer.from(JSON.stringify(report, null, 2))); } catch (_) {}
}

// ── Util ────────────────────────────────────────────────────────────────────
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

async function settle(page, ms) {
  // Pequena espera "viva" para o screencast capturar a página renderizada.
  try { await page.waitForLoadState('networkidle', { timeout: Math.min(ms, 4000) }); } catch (_) {}
  await sleep(Math.min(ms, 1200));
}

let shotSeq = 0;
function slug(s) {
  return String(s || 'page').replace(/^https?:\/\/[^/]+/i, '').replace(/[^\w]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 40) || 'page';
}
// Tira um still de PÁGINA INTEIRA (alta fidelidade) para o agente de QA inspecionar com visão.
async function shot(page, label) {
  shotSeq++;
  const idx = String(shotSeq).padStart(2, '0');
  const file = idx + '-' + slug(label) + '.jpg';
  const abs = path.join(SHOTS_DIR, file);
  try {
    await page.screenshot({ path: abs, type: 'jpeg', quality: 72, fullPage: true });
    try { fs.chmodSync(abs, 0o666); } catch (_) {}
    report.shots.push({ file, path: 'shots/' + file, caption: label });
  } catch (_) {}
}

async function gentleScroll(page) {
  try {
    await page.evaluate(async () => {
      const h = document.body ? document.body.scrollHeight : 0;
      const steps = 6;
      for (let i = 1; i <= steps; i++) {
        window.scrollTo(0, (h * i) / steps);
        await new Promise((r) => setTimeout(r, 180));
      }
      window.scrollTo(0, 0);
    });
  } catch (_) {}
}

// ── Main ──────────────────────────────────────────────────────────────────────
(async () => {
  if (!BASE_URL) {
    report.summary = 'QA_BASE_URL ausente — nada para navegar.';
    writeReport();
    log('ERRO: QA_BASE_URL ausente');
    process.exit(2);
  }

  let playwright;
  try {
    playwright = require('playwright');
  } catch (e) {
    report.summary = 'Módulo playwright não encontrado: ' + e.message;
    writeReport();
    log('ERRO: ' + e.message);
    process.exit(3);
  }
  const { chromium } = playwright;

  caption('Abrindo o navegador…');
  const browser = await chromium.launch({
    headless: true,
    args: ['--no-sandbox', '--disable-dev-shm-usage', '--disable-gpu', '--hide-scrollbars'],
  });
  const context = await browser.newContext({
    viewport: { width: 1280, height: 800 },
    ignoreHTTPSErrors: true,
    locale: 'pt-BR',
  });
  const page = await context.newPage();

  // Hooks de erro (capturam a URL corrente).
  page.on('console', (msg) => {
    if (msg.type() !== 'error') return;
    const text = msg.text() || '';
    if (/favicon/i.test(text)) return;
    pushUnique(report.console_errors, 'c:' + text, { url: state.url, text: text.slice(0, 500) });
  });
  page.on('pageerror', (err) => {
    const text = (err && (err.stack || err.message)) || String(err);
    pushUnique(report.page_errors, 'p:' + text.slice(0, 120), { url: state.url, text: text.slice(0, 800) });
  });
  page.on('requestfailed', (req) => {
    const f = req.failure();
    const err = (f && f.errorText) || 'failed';
    if (/ERR_ABORTED/i.test(err)) return; // navegação cancelada por outra: ruído
    pushUnique(report.failed_requests, 'f:' + req.url(), { url: req.url().slice(0, 300), method: req.method(), error: err });
  });
  page.on('response', (resp) => {
    const s = resp.status();
    if (s >= 400) {
      const u = resp.url();
      if (/favicon/i.test(u)) return;
      pushUnique(report.http_errors, 'h:' + u + ':' + s, { url: u.slice(0, 300), status: s });
    }
  });

  // ── Screencast via CDP ───────────────────────────────────────────────────────
  let lastWrite = 0;
  const client = await context.newCDPSession(page);
  client.on('Page.screencastFrame', async (ev) => {
    // Sempre confirma para manter o fluxo; grava em disco respeitando o fps alvo.
    try { await client.send('Page.screencastFrameAck', { sessionId: ev.sessionId }); } catch (_) {}
    const now = Date.now();
    if (now - lastWrite < FRAME_INTERVAL_MS) return;
    lastWrite = now;
    try {
      writeAtomic(FRAME_PATH, Buffer.from(ev.data, 'base64'));
      frameSeq++;
      state.frames = frameSeq;
    } catch (_) {}
  });
  try {
    await client.send('Page.startScreencast', {
      format: 'jpeg', quality: QUALITY, maxWidth: MAX_WIDTH, maxHeight: 720, everyNthFrame: 1,
    });
  } catch (e) {
    log('AVISO: screencast não iniciou: ' + e.message);
  }

  const deadline = state.started_at + BUDGET_MS;
  const timeLeft = () => deadline - Date.now();

  async function go(url, capText) {
    setUrl(url);
    if (capText) caption(capText);
    let status = null, ok = false;
    try {
      const resp = await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 20000 });
      status = resp ? resp.status() : null;
      ok = !!resp && resp.ok();
      setUrl(page.url());
    } catch (e) {
      report.steps.push('Falha ao abrir ' + url + ': ' + e.message);
    }
    await settle(page, 1500);
    return { status, ok };
  }

  try {
    // 1) Home / raiz
    const root = await go(BASE_URL + '/', 'Abrindo a página inicial');
    report.visited.push({ path: '/', url: page.url(), status: root.status, ok: root.ok });
    await gentleScroll(page);
    await shot(page, 'home');

    // 2) Login de verdade (se aplicável)
    if (REQUIRES_LOGIN && USER && PASS) {
      await doLogin(page);
      await shot(page, 'pos-login');
    } else if (REQUIRES_LOGIN) {
      report.login.detail = 'requires_login, mas sem credenciais demo — pulei o login.';
      report.steps.push(report.login.detail);
    }

    // 3) Descobre rotas e visita cada uma
    const routes = await discoverRoutes(page);
    report.steps.push('Rotas para visitar: ' + routes.join(', '));
    state.total = routes.length + 1;
    state.step = 1; writeStatus();

    for (const r of routes) {
      if (timeLeft() < 4000) { report.steps.push('Orçamento de tempo encerrado.'); break; }
      const abs = absUrl(r);
      state.step++; writeStatus();
      const res = await go(abs, 'Visitando ' + shortPath(r));
      report.visited.push({ path: r, url: page.url(), status: res.status, ok: res.ok });
      await gentleScroll(page);
      await shot(page, shortPath(r));
      // 4) Clique "seguro" na UI (não destrutivo)
      if (timeLeft() > 6000) await safeClick(page, r);
    }

    report.summary = buildSummary();
    const clean = currentErrorCount() === 0 && (!REQUIRES_LOGIN || report.login.success !== false);
    caption(clean ? 'QA concluído — sem erros aparentes' : 'QA concluído — encontrei pontos a corrigir');
  } catch (e) {
    report.steps.push('Erro inesperado no harness: ' + (e.stack || e.message));
    report.summary = 'Harness interrompido: ' + e.message;
  } finally {
    try { await client.send('Page.stopScreencast'); } catch (_) {}
    state.done = true; writeStatus();
    writeReport();
    // Deixa o último frame visível por um instante antes de fechar.
    await sleep(400);
    try { await browser.close(); } catch (_) {}
    log('FIM — frames=' + state.frames + ' erros=' + report.error_count + ' ok=' + report.ok);
  }
})().catch((e) => {
  try { report.summary = 'Falha fatal: ' + (e && (e.stack || e.message)); writeReport(); } catch (_) {}
  log('FATAL: ' + (e && e.message));
  process.exit(1);
});

// ── Login ──────────────────────────────────────────────────────────────────────
async function doLogin(page) {
  report.login.attempted = true;
  caption('Procurando a tela de login…');

  // Tenta achar um campo de senha na página atual; senão percorre caminhos comuns.
  let formPage = null;
  if (await hasPasswordField(page)) {
    formPage = page.url();
  } else {
    for (const p of LOGIN_PATHS) {
      if (Date.now() - state.started_at > BUDGET_MS) break;
      const u = absUrl(p);
      setUrl(u);
      try {
        await page.goto(u, { waitUntil: 'domcontentloaded', timeout: 15000 });
      } catch (_) { continue; }
      await settle(page, 800);
      if (await hasPasswordField(page)) { formPage = page.url(); break; }
    }
  }

  if (!formPage) {
    report.login.success = false;
    report.login.detail = 'Não encontrei um formulário de login (campo de senha).';
    report.steps.push(report.login.detail);
    caption('Não achei a tela de login');
    return;
  }

  caption('Fazendo login como ' + USER);
  try {
    // Campo de usuário: e-mail/usuário/login.
    const userSel = await firstSelector(page, [
      'input[type=email]',
      'input[name*="email" i]',
      'input[name*="user" i]',
      'input[name*="login" i]',
      'input[id*="email" i]',
      'input[id*="user" i]',
      'input[type=text]:not([type=hidden])',
    ]);
    const passSel = await firstSelector(page, [
      'input[type=password]',
      'input[name*="senha" i]',
      'input[name*="pass" i]',
    ]);
    if (!userSel || !passSel) {
      report.login.success = false;
      report.login.detail = 'Formulário incompleto (faltou campo de usuário ou senha).';
      caption('Formulário de login incompleto');
      return;
    }
    await page.fill(userSel, USER);
    await page.fill(passSel, PASS);
    await sleep(300);

    // Submete: botão de submit/entrar, ou Enter no campo de senha.
    const before = page.url();
    const submitSel = await firstSelector(page, [
      'button[type=submit]',
      'input[type=submit]',
      'button:has-text("Entrar")',
      'button:has-text("Login")',
      'button:has-text("Acessar")',
    ]);
    if (submitSel) {
      await Promise.all([
        page.waitForLoadState('networkidle', { timeout: 12000 }).catch(() => {}),
        page.click(submitSel).catch(() => {}),
      ]);
    } else {
      await page.press(passSel, 'Enter').catch(() => {});
      await page.waitForLoadState('networkidle', { timeout: 12000 }).catch(() => {});
    }
    await settle(page, 1200);
    setUrl(page.url());

    // Sucesso heurístico: sumiu o campo de senha OU a URL mudou para fora do login.
    const stillHasPass = await hasPasswordField(page);
    const moved = page.url() !== before;
    report.login.success = !stillHasPass || moved;
    report.login.detail = report.login.success
      ? 'Login aparentemente OK (' + page.url() + ').'
      : 'Login pode ter falhado (continua na tela de senha).';
    report.steps.push(report.login.detail);
    caption(report.login.success ? 'Login efetuado' : 'Login não confirmado');
  } catch (e) {
    report.login.success = false;
    report.login.detail = 'Erro no login: ' + e.message;
    report.steps.push(report.login.detail);
  }
}

async function hasPasswordField(page) {
  try { return (await page.$('input[type=password]')) !== null; } catch (_) { return false; }
}
async function firstSelector(page, sels) {
  for (const s of sels) {
    try { if (await page.$(s)) return s; } catch (_) {}
  }
  return null;
}

// ── Descoberta de rotas ──────────────────────────────────────────────────────
function absUrl(href) {
  try { return new URL(href, BASE_URL + '/').toString(); } catch (_) { return BASE_URL + '/'; }
}
function shortPath(href) {
  try { const u = new URL(href, BASE_URL + '/'); return (u.pathname + u.search) || '/'; } catch (_) { return href; }
}

async function discoverRoutes(page) {
  if (CONFIG_ROUTES && CONFIG_ROUTES.length) {
    return CONFIG_ROUTES.slice(0, MAX_ROUTES);
  }
  let hrefs = [];
  try {
    hrefs = await page.$$eval('a[href]', (as) => as.map((a) => ({ href: a.getAttribute('href') || '', text: (a.textContent || '').trim().slice(0, 40) })));
  } catch (_) {}
  const origin = new URL(BASE_URL + '/').origin;
  const seen = new Set();
  const out = [];
  for (const { href, text } of hrefs) {
    if (!href) continue;
    if (/^(mailto:|tel:|javascript:|#)/i.test(href)) continue;
    let u;
    try { u = new URL(href, BASE_URL + '/'); } catch (_) { continue; }
    if (u.origin !== origin) continue;           // só mesma origem
    if (DESTRUCTIVE.test(href) || DESTRUCTIVE.test(text)) continue; // evita logout/excluir
    const key = u.pathname + u.search;
    if (key === '/' || key === '') continue;     // raiz já visitada
    if (seen.has(key)) continue;
    seen.add(key);
    out.push(key);
    if (out.length >= MAX_ROUTES) break;
  }
  return out;
}

// ── Clique seguro ───────────────────────────────────────────────────────────────
async function safeClick(page, routePath) {
  try {
    const candidates = await page.$$('button, [role=button], .btn, a.button');
    for (const el of candidates) {
      let label = '';
      try { label = ((await el.innerText()) || '').trim(); } catch (_) {}
      if (!label) continue;
      if (DESTRUCTIVE.test(label)) continue;
      // Não submeter formulários (evita POSTs que mudam estado): pula type=submit.
      let type = '';
      try { type = (await el.getAttribute('type')) || ''; } catch (_) {}
      if (/submit/i.test(type)) continue;
      const visible = await el.isVisible().catch(() => false);
      if (!visible) continue;
      caption('Clicando em "' + label.slice(0, 30) + '"');
      await el.click({ timeout: 2500 }).catch(() => {});
      report.clicks.push({ path: routePath, label: label.slice(0, 40) });
      await settle(page, 900);
      return; // um clique por rota basta para exercitar a UI
    }
  } catch (_) {}
}

function buildSummary() {
  const errs = report.console_errors.length + report.page_errors.length +
    report.failed_requests.length + report.http_errors.length;
  const parts = [];
  parts.push(report.visited.length + ' páginas visitadas');
  if (REQUIRES_LOGIN) parts.push('login ' + (report.login.success ? 'OK' : 'NÃO confirmado'));
  parts.push(errs + ' erro(s)');
  return parts.join(' · ');
}
