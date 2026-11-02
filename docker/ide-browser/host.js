#!/usr/bin/env node
/**
 * #1 — Host do "Navegador ao vivo" da IDE. Sobe um Chromium real (Playwright) e:
 *   - grava um SCREENCAST via CDP, mantendo o último frame JPEG em memória;
 *   - expõe um HTTP de controle em 127.0.0.1:9223 (navegar/clicar/digitar/voltar).
 * A extensão do VS Code mostra os frames (via proxy do code-server) e o `ravi-browser` (CLI) deixa
 * o Claude DIRIGIR o mesmo navegador — então o usuário vê, ao vivo e dentro da IDE, o que o Claude faz.
 *
 * v2 — ABAS POR SESSÃO: cada sessão do Claude (e o usuário) tem a sua PRÓPRIA aba (contexto +
 * página + screencast isolados no mesmo Chromium). Duas sessões trabalhando ao mesmo tempo não se
 * atrapalham mais. Toda requisição aceita `tab=<id>` (query ou corpo); sem `tab`, cai na aba em
 * FOCO (a de atividade mais recente) — é o que a extensão/CLI antigas mandam, então segue
 * compatível. Abas `claude-<pid>` fecham sozinhas quando o processo do Claude morre.
 *
 * Recursos: emulação de device (mobile/tablet), ações/inspeção por SELETOR (não só por pixel) e
 * captura de rede rica (requests/respostas + corpo das que falham).
 */
'use strict';
// Resolve o playwright (instalado global) e o chromium (baixado no build da imagem) — iguais ao
// harness de QA do chat. Sem isto, require('playwright') falha fora de um node_modules local.
try {
  process.env.NODE_PATH = (process.env.NODE_PATH ? process.env.NODE_PATH + ':' : '') + '/usr/lib/node_modules';
  require('module').Module._initPaths();
} catch (_) {}
if (!process.env.PLAYWRIGHT_BROWSERS_PATH) {
  process.env.PLAYWRIGHT_BROWSERS_PATH = '/root/.cache/ms-playwright';
}
const http = require('http');
const fs = require('fs');

const VERSION = 2;
const PORT = parseInt(process.env.RAVI_BROWSER_PORT || '9223', 10);
const MAX_TABS = parseInt(process.env.RAVI_BROWSER_MAX_TABS || '8', 10);
const MAIN_TAB = 'main'; // aba do usuário (barra de URL da extensão) / de quem roda a CLI fora do Claude

const DESKTOP = { label: 'desktop', viewport: { width: 1280, height: 800 }, deviceScaleFactor: 1, isMobile: false, hasTouch: false };

let browser = null, booting = null;
const tabs = new Map(); // id -> Tab
let tabSeq = 0;

// Resolve um descritor de device a partir dos parâmetros. name=preset (ou alias), ou w/h/mobile/scale/ua custom.
function resolveDevice(p) {
  const name = String(p.name || p.device || '').trim();
  // Custom por dimensões, ou desktop.
  if (!name || /^desktop$/i.test(name)) {
    const w = parseInt(p.w || p.width, 10), h = parseInt(p.h || p.height, 10);
    if (w > 0 && h > 0) {
      const mob = String(p.mobile || '') === '1' || String(p.mobile || '') === 'true';
      return { label: 'custom ' + w + 'x' + h, viewport: { width: w, height: h }, deviceScaleFactor: Number(p.scale) || 1, isMobile: mob, hasTouch: mob, userAgent: p.ua || undefined };
    }
    return { ...DESKTOP };
  }
  // Preset do Playwright (nome exato) ou alias amigável.
  let devs = {};
  try { devs = require('playwright').devices || {}; } catch (_) {}
  const ALIAS = {
    iphone: ['iPhone 14', 'iPhone 13', 'iPhone 12', 'iPhone 11'],
    'iphone-max': ['iPhone 14 Pro Max', 'iPhone 13 Pro Max', 'iPhone 12 Pro Max'],
    'iphone-pro': ['iPhone 14 Pro', 'iPhone 13 Pro'],
    'iphone-se': ['iPhone SE'],
    pixel: ['Pixel 7', 'Pixel 5'],
    android: ['Pixel 7', 'Pixel 5'],
    galaxy: ['Galaxy S9+', 'Galaxy S8'],
    ipad: ['iPad (gen 7)', 'iPad Mini', 'iPad Pro 11'],
    'ipad-pro': ['iPad Pro 11'],
    tablet: ['iPad (gen 7)', 'iPad Pro 11'],
  };
  let dev = devs[name];
  if (!dev) {
    for (const c of (ALIAS[name.toLowerCase()] || [])) { if (devs[c]) { dev = devs[c]; break; } }
  }
  if (!dev) { return null; } // desconhecido
  return { label: name, viewport: dev.viewport, deviceScaleFactor: dev.deviceScaleFactor, isMobile: dev.isMobile, hasTouch: dev.hasTouch, userAgent: dev.userAgent };
}

async function ensureBrowser() {
  if (browser) return browser;
  if (booting) return booting;
  booting = (async () => {
    const { chromium } = require('playwright');
    browser = await chromium.launch({ headless: true, args: ['--no-sandbox', '--disable-dev-shm-usage'] });
    browser.on('disconnected', () => { browser = null; for (const t of tabs.values()) t.dead(); tabs.clear(); });
    return browser;
  })();
  try { return await booting; } finally { booting = null; }
}

/** Uma ABA: contexto + página + screencast + logs, isolados. */
class Tab {
  constructor(id) {
    this.id = id;
    this.seq = ++tabSeq;
    const m = /^claude-(\d+)$/.exec(id);
    this.ownerPid = m ? parseInt(m[1], 10) : 0;   // pid do processo do Claude dono da aba (0 = ninguém: aba do usuário/custom)
    this.kind = this.ownerPid ? 'claude' : 'user';
    this.label = this.ownerPid ? ('Claude ' + this.seq) : (id === MAIN_TAB ? 'Você' : id);
    this.createdAt = Date.now();
    this.device = { ...DESKTOP };
    this.media = { colorScheme: null, reducedMotion: null };
    this.storageStatePath = null;
    this.context = null; this.page = null; this.cdp = null;
    this.lastFrame = null; this.currentUrl = 'about:blank';
    this.lastActivityAt = Date.now();
    this.consoleLogs = []; this.netLog = [];
    this.ready = null; // promise da criação da página
    this.closed = false;
  }
  touch() { this.lastActivityAt = Date.now(); }
  pushLog(type, text) {
    this.consoleLogs.push({ type, text: String(text).slice(0, 2000), at: new Date().toISOString() });
    if (this.consoleLogs.length > 300) this.consoleLogs.shift();
  }
  pushNet(entry) {
    this.netLog.push(entry);
    if (this.netLog.length > 400) this.netLog.shift();
  }
  ownerAlive() {
    if (!this.ownerPid) return true;
    try { return fs.existsSync('/proc/' + this.ownerPid); } catch (_) { return true; }
  }

  // (Re)cria o contexto + página + screencast para o `device` atual. Reaproveita o `browser`.
  async makeContextAndPage(navigateBackTo) {
    await ensureBrowser();
    if (this.cdp) { try { await this.cdp.detach(); } catch (_) {} this.cdp = null; }
    if (this.page) { try { await this.page.close(); } catch (_) {} this.page = null; }
    if (this.context) { try { await this.context.close(); } catch (_) {} this.context = null; }

    const device = this.device, media = this.media;
    const vw = device.viewport.width, vh = device.viewport.height;
    const context = await browser.newContext({
      viewport: { width: vw, height: vh },
      deviceScaleFactor: device.deviceScaleFactor || 1,
      isMobile: !!device.isMobile,
      hasTouch: !!device.hasTouch,
      userAgent: device.userAgent || undefined,
      ignoreHTTPSErrors: true,
      storageState: this.storageStatePath || undefined,
    });
    const page = await context.newPage();
    this.context = context; this.page = page;
    // Reaplica a emulação de tema/movimento (é por página → some ao recriar o contexto).
    if (media.colorScheme || media.reducedMotion) {
      try { await page.emulateMedia({ colorScheme: media.colorScheme || undefined, reducedMotion: media.reducedMotion || undefined }); } catch (_) {}
    }
    page.on('framenavigated', f => { if (f === page.mainFrame()) this.currentUrl = page.url(); });
    // Captura para o Claude inspecionar: console, erros de JS e rede.
    page.on('console', m => { const t = m.type(); if (t === 'error' || t === 'warning') this.pushLog('console.' + t, m.text()); });
    page.on('pageerror', e => this.pushLog('pageerror', e && e.message ? e.message : String(e)));
    page.on('requestfailed', r => {
      try {
        const err = (r.failure() && r.failure().errorText) || 'falhou';
        this.pushLog('requestfailed', r.url() + ' — ' + err);
        this.pushNet({ method: r.method(), url: r.url(), status: 'failed', type: r.resourceType(), error: err, at: new Date().toISOString() });
      } catch (_) {}
    });
    page.on('response', async (r) => {
      try {
        const rt = r.request().resourceType();
        const st = r.status();
        const entry = { method: r.request().method(), url: r.url(), status: st, type: rt, at: new Date().toISOString() };
        if (st >= 400) this.pushLog('http' + st, r.url());
        // Captura corpo/headers das chamadas de API (xhr/fetch/document) em QUALQUER status — p/ depurar 200 com dado errado.
        if (rt === 'xhr' || rt === 'fetch' || rt === 'document' || st >= 400) {
          try { entry.body = String(await r.text()).slice(0, 4000); } catch (_) {}
          try { entry.contentType = (r.headers() || {})['content-type'] || ''; } catch (_) {}
          try { const pd = r.request().postData(); if (pd) entry.reqBody = String(pd).slice(0, 2000); } catch (_) {}
        }
        this.pushNet(entry);
      } catch (_) {}
    });
    const cdp = await context.newCDPSession(page);
    this.cdp = cdp;
    cdp.on('Page.screencastFrame', async (ev) => {
      this.lastFrame = Buffer.from(ev.data, 'base64');
      try { await cdp.send('Page.screencastFrameAck', { sessionId: ev.sessionId }); } catch (_) {}
    });
    const dest = navigateBackTo && navigateBackTo !== 'about:blank' ? navigateBackTo : 'about:blank';
    await page.goto(dest, { waitUntil: 'domcontentloaded', timeout: 30000 }).catch(() => {});
    await cdp.send('Page.startScreencast', { format: 'jpeg', quality: 60, maxWidth: vw, maxHeight: vh, everyNthFrame: 1 });
  }

  async ensure() {
    if (this.page) return;
    if (!this.ready) {
      this.ready = this.makeContextAndPage(null).finally(() => { this.ready = null; });
    }
    await this.ready;
  }

  dead() { this.page = null; this.context = null; this.cdp = null; }

  async close() {
    this.closed = true;
    if (this.cdp) { try { await this.cdp.detach(); } catch (_) {} }
    if (this.page) { try { await this.page.close(); } catch (_) {} }
    if (this.context) { try { await this.context.close(); } catch (_) {} }
    this.dead();
  }

  info() {
    return {
      id: this.id, label: this.label, kind: this.kind, pid: this.ownerPid || undefined,
      url: this.currentUrl, ready: !!this.page, idleMs: Date.now() - this.lastActivityAt,
      w: this.device.viewport.width, h: this.device.viewport.height, device: this.device.label, mobile: !!this.device.isMobile,
      colorScheme: this.media.colorScheme || 'no-preference', authLoaded: !!this.storageStatePath,
    };
  }
}

/** Aba em FOCO: a de atividade mais recente (é a que a extensão/CLI antigas, sem `tab`, enxergam). */
function focusTab() {
  let best = null;
  for (const t of tabs.values()) { if (!best || t.lastActivityAt > best.lastActivityAt) best = t; }
  return best;
}

/** Obtém (ou cria) a aba pedida. Sem id → aba em foco; se não houver nenhuma, a aba do usuário. */
async function getTab(id, create) {
  id = String(id || '').trim();
  if (!id) {
    const f = focusTab();
    if (f) return f;
    id = MAIN_TAB;
  }
  let t = tabs.get(id);
  if (!t) {
    if (!create) return null;
    // Teto de abas: derruba a mais ociosa (nunca a que está sendo pedida).
    while (tabs.size >= MAX_TABS) {
      let victim = null;
      for (const c of tabs.values()) { if (!victim || c.lastActivityAt < victim.lastActivityAt) victim = c; }
      if (!victim) break;
      tabs.delete(victim.id);
      victim.close().catch(() => {});
    }
    t = new Tab(id);
    tabs.set(id, t);
  }
  await t.ensure();
  return t;
}

async function closeTab(id) {
  const t = tabs.get(id);
  if (!t) return false;
  tabs.delete(id);
  await t.close();
  return true;
}

// Ceifador: aba de sessão do Claude cujo processo morreu fecha sozinha (libera memória e some da lista).
setInterval(() => {
  for (const t of Array.from(tabs.values())) {
    if (!t.ownerAlive()) closeTab(t.id).catch(() => {});
  }
}, 3000).unref();

function readBody(req) {
  return new Promise(resolve => { let b = ''; req.on('data', c => b += c); req.on('end', () => resolve(b)); });
}
function json(res, code, obj) { res.writeHead(code, { 'Content-Type': 'application/json' }); res.end(JSON.stringify(obj)); }

function stateObj() {
  const f = focusTab();
  const list = Array.from(tabs.values()).sort((a, b) => a.seq - b.seq).map(t => t.info());
  const base = f ? f.info() : { url: 'about:blank', ready: false, idleMs: -1, w: DESKTOP.viewport.width, h: DESKTOP.viewport.height, device: 'desktop', mobile: false, colorScheme: 'no-preference', authLoaded: false };
  // Campos de topo = aba em foco (compatível com a extensão v1). `tabs`/`active` = novidade da v2.
  return { v: VERSION, ...base, tabs: list, active: f ? f.id : null };
}

const server = http.createServer(async (req, res) => {
  const u = new URL(req.url, 'http://localhost');
  try {
    if (u.pathname === '/version') { return json(res, 200, { v: VERSION, tabs: tabs.size }); }
    if (u.pathname === '/state') {
      // Com `tab`: os campos de topo são DESSA aba (a CLI vê a própria sessão); sem: aba em foco (extensão v1).
      const st = stateObj();
      const own = tabs.get(u.searchParams.get('tab') || '');
      return json(res, 200, own ? { ...st, ...own.info() } : st);
    }
    if (u.pathname === '/tabs') { return json(res, 200, { ok: true, tabs: stateObj().tabs, active: stateObj().active }); }

    const qtab = u.searchParams.get('tab') || '';

    if (u.pathname === '/frame.jpg') {
      // Frame da aba pedida (sem criar aba nova só por olhar); sem `tab` → aba em foco.
      const t = qtab ? tabs.get(qtab) : focusTab();
      if (!t || !t.lastFrame) { res.writeHead(204); return res.end(); }
      res.writeHead(200, { 'Content-Type': 'image/jpeg', 'Cache-Control': 'no-store' });
      return res.end(t.lastFrame);
    }
    if (u.pathname === '/console') { const t = await getTab(qtab, false); return json(res, 200, { logs: t ? t.consoleLogs : [] }); }
    if (u.pathname === '/network') { const t = await getTab(qtab, false); return json(res, 200, { requests: t ? t.netLog : [] }); }
    if (u.pathname === '/request') {
      // Detalhe do ÚLTIMO request cuja URL casa (substring) — inclui corpo/headers/payload de API.
      const t = await getTab(qtab, false);
      const q = (u.searchParams.get('url') || '').toLowerCase();
      const matches = (t ? t.netLog : []).filter(e => !q || String(e.url).toLowerCase().includes(q));
      return json(res, 200, { ok: true, count: matches.length, match: matches.length ? matches[matches.length - 1] : null });
    }
    if (u.pathname === '/exists' || u.pathname === '/count' || u.pathname === '/gettext') {
      // Verificação por SELETOR sem screenshot (asserções). selector vem na query (?selector=).
      const t = await getTab(qtab, true); const page = t.page;
      const sel = u.searchParams.get('selector') || '';
      if (u.pathname === '/count') {
        let n = 0; try { n = await page.locator(sel).count(); } catch (_) {}
        return json(res, 200, { ok: true, count: n });
      }
      if (u.pathname === '/gettext') {
        let tx = '';
        try { tx = await page.locator(sel).first().innerText({ timeout: 3000 }); }
        catch (_) { try { tx = (await page.locator(sel).first().textContent({ timeout: 1500 })) || ''; } catch (__) {} }
        return json(res, 200, { ok: true, text: String(tx).slice(0, 4000) });
      }
      let n = 0, vis = false;
      try { n = await page.locator(sel).count(); if (n > 0) vis = await page.locator(sel).first().isVisible().catch(() => false); } catch (_) {}
      return json(res, 200, { ok: true, exists: n > 0, count: n, visible: vis });
    }
    if (u.pathname === '/elements') {
      const t = await getTab(qtab, true); const page = t.page;
      const els = await page.evaluate(() => {
        const sel = 'a,button,input,textarea,select,[role=button],[role=link],[onclick],label,summary';
        const out = [];
        document.querySelectorAll(sel).forEach(el => {
          const r = el.getBoundingClientRect();
          if (r.width < 1 || r.height < 1) return;
          const vis = r.bottom > 0 && r.right > 0 && r.top < (window.innerHeight + 600) && r.left < window.innerWidth;
          if (!vis) return;
          const txt = (el.innerText || el.value || el.getAttribute('aria-label') || el.getAttribute('placeholder') || el.getAttribute('name') || '').trim().slice(0, 80);
          out.push({ tag: el.tagName.toLowerCase(), type: el.getAttribute('type') || '', text: txt, x: Math.round(r.left + r.width / 2), y: Math.round(r.top + r.height / 2), w: Math.round(r.width), h: Math.round(r.height) });
        });
        return out.slice(0, 150);
      }).catch(() => []);
      return json(res, 200, { ok: true, count: els.length, elements: els });
    }
    if (u.pathname === '/snapshot') {
      // Árvore ARIA (estrutura semântica da página, legível) — mais robusto que ler o HTML cru.
      // page.accessibility foi removido no Playwright novo; usamos locator.ariaSnapshot().
      const t = await getTab(qtab, true);
      let snap = '';
      try { snap = await t.page.locator('body').ariaSnapshot(); } catch (_) { snap = ''; }
      return json(res, 200, { ok: true, snapshot: String(snap).slice(0, 12000) });
    }
    if (u.pathname === '/text') {
      const t = await getTab(qtab, false);
      const tx = (t && t.page) ? await t.page.evaluate(() => (document.body ? document.body.innerText : '')).catch(() => '') : '';
      return json(res, 200, { text: String(tx).slice(0, 20000) });
    }
    if (u.pathname === '/html') {
      const t = await getTab(qtab, false);
      const h = (t && t.page) ? await t.page.content().catch(() => '') : '';
      return json(res, 200, { html: String(h).slice(0, 60000) });
    }

    if (req.method === 'POST') {
      const body = await readBody(req);
      // Aceita JSON (extensão) OU form-urlencoded (CLI ravi-browser).
      let data = {};
      if (body) {
        try { data = JSON.parse(body); }
        catch (_) { data = Object.fromEntries(new URLSearchParams(body)); }
      }
      const q = Object.fromEntries(u.searchParams);
      const p = { ...q, ...data };

      if (u.pathname === '/tab/close') {
        const id = String(p.tab || '');
        const ok = id ? await closeTab(id) : false;
        return json(res, 200, { ok, tab: id });
      }

      const t = await getTab(p.tab, true);
      const page = t.page;
      t.touch(); // qualquer comando de controle conta como atividade (abre o painel na extensão / foca a aba)

      // ── Emulação de device (mobile/tablet/custom). Recria o contexto e volta para a mesma URL. ──
      if (u.pathname === '/device' || u.pathname === '/viewport') {
        const d = resolveDevice(p);
        if (!d) { return json(res, 400, { ok: false, error: 'device desconhecido: ' + (p.name || p.device || '') }); }
        t.device = d;
        await t.makeContextAndPage(t.currentUrl);
        return json(res, 200, { ok: true, device: t.device.label, w: t.device.viewport.width, h: t.device.viewport.height, mobile: !!t.device.isMobile });
      }

      // ── Tema: emula prefers-color-scheme (dark/light/auto) e prefers-reduced-motion. ──
      if (u.pathname === '/theme') {
        const sc = String(p.scheme || p.colorScheme || '').toLowerCase();
        const mo = String(p.motion || p.reducedMotion || '').toLowerCase();
        if (sc) t.media.colorScheme = (sc === 'dark') ? 'dark' : (sc === 'light') ? 'light' : 'no-preference';
        if (mo) t.media.reducedMotion = (mo === 'reduce' || mo === 'reduced') ? 'reduce' : 'no-preference';
        try { await page.emulateMedia({ colorScheme: t.media.colorScheme || undefined, reducedMotion: t.media.reducedMotion || undefined }); }
        catch (e) { return json(res, 200, { ok: false, error: String(e && e.message || e) }); }
        return json(res, 200, { ok: true, colorScheme: t.media.colorScheme, reducedMotion: t.media.reducedMotion });
      }

      // ── Auth/storage state: salva (cookies+localStorage) e injeta p/ pular login nos testes. ──
      if (u.pathname === '/storage-save') {
        const out = String(p.path || '/tmp/ravi-auth.json');
        try { await t.context.storageState({ path: out }); return json(res, 200, { ok: true, path: out }); }
        catch (e) { return json(res, 200, { ok: false, error: String(e && e.message || e) }); }
      }
      if (u.pathname === '/storage-load') {
        const f = String(p.path || '/tmp/ravi-auth.json');
        if (!fs.existsSync(f)) { return json(res, 200, { ok: false, error: 'arquivo não existe: ' + f }); }
        t.storageStatePath = f;
        await t.makeContextAndPage(t.currentUrl);
        return json(res, 200, { ok: true, path: f });
      }
      if (u.pathname === '/storage-clear') {
        t.storageStatePath = null;
        await t.makeContextAndPage(t.currentUrl);
        return json(res, 200, { ok: true });
      }

      if (u.pathname === '/navigate') {
        let url = String(p.url || '').trim();
        if (url && !/^(https?:\/\/|about:|data:|file:\/\/)/i.test(url)) url = 'http://' + url;
        t.consoleLogs = []; t.netLog = []; // erros/rede são por página → zera ao navegar
        await page.goto(url || 'about:blank', { waitUntil: 'domcontentloaded', timeout: 30000 }).catch(e => t.pushLog('navigate', e && e.message ? e.message : String(e)));
        return json(res, 200, { ok: true, url: page.url(), tab: t.id });
      }
      if (u.pathname === '/screenshot') {
        const out = String(p.path || '/tmp/ravi-shot.png');
        try { fs.mkdirSync(require('path').dirname(out), { recursive: true }); } catch (_) {}
        // Settle: espera a rede aquietar (cap 2s, não falha) p/ não capturar página meio-carregada.
        if (String(p.nosettle || '') !== '1') { await page.waitForLoadState('networkidle', { timeout: 2000 }).catch(() => {}); }
        try {
          if (p.selector) { await page.locator(String(p.selector)).first().screenshot({ path: out }); }
          else { await page.screenshot({ path: out, fullPage: String(p.full || '') === '1' }); }
        } catch (e) { return json(res, 200, { ok: false, error: String(e && e.message || e) }); }
        return json(res, 200, { ok: true, path: out });
      }
      if (u.pathname === '/waitidle') {
        const to = Number(p.timeout) || 8000;
        try { await page.waitForLoadState('networkidle', { timeout: to }); return json(res, 200, { ok: true }); }
        catch (e) { return json(res, 200, { ok: true, note: 'ainda com rede ativa', error: String(e && e.message || e) }); }
      }
      if (u.pathname === '/diag') {
        // Diagnóstico em UMA chamada: screenshot + erros de console + requests falhos + url/título.
        const out = String(p.path || '/tmp/ravi-diag.png');
        try { fs.mkdirSync(require('path').dirname(out), { recursive: true }); } catch (_) {}
        if (String(p.nosettle || '') !== '1') { await page.waitForLoadState('networkidle', { timeout: 2500 }).catch(() => {}); }
        await page.screenshot({ path: out, fullPage: String(p.full || '') === '1' }).catch(() => {});
        const title = await page.title().catch(() => '');
        const errs = t.consoleLogs.filter(l => /error|pageerror|requestfailed|^http[45]/.test(l.type));
        const failed = t.netLog.filter(e => e.status === 'failed' || (typeof e.status === 'number' && e.status >= 400));
        return json(res, 200, { ok: true, path: out, url: t.currentUrl, title, device: t.device.label, colorScheme: t.media.colorScheme || 'no-preference', console: errs, network_failures: failed });
      }

      // ── Ações por SELETOR (mais confiável que pixel). click aceita selector/text OU x,y. ──
      if (u.pathname === '/click') {
        if (p.selector) {
          try { await page.locator(String(p.selector)).first().click({ timeout: Number(p.timeout) || 8000 }); return json(res, 200, { ok: true, by: 'selector' }); }
          catch (e) { return json(res, 200, { ok: false, error: String(e && e.message || e) }); }
        }
        if (p.text) {
          try { await page.getByText(String(p.text), { exact: false }).first().click({ timeout: Number(p.timeout) || 8000 }); return json(res, 200, { ok: true, by: 'text' }); }
          catch (e) { return json(res, 200, { ok: false, error: String(e && e.message || e) }); }
        }
        await page.mouse.click(Number(p.x) || 0, Number(p.y) || 0);
        return json(res, 200, { ok: true, by: 'xy' });
      }
      if (u.pathname === '/fill') {
        try { await page.locator(String(p.selector)).first().fill(String(p.value != null ? p.value : '')); return json(res, 200, { ok: true }); }
        catch (e) { return json(res, 200, { ok: false, error: String(e && e.message || e) }); }
      }
      if (u.pathname === '/waitfor') {
        try { await page.waitForSelector(String(p.selector), { timeout: Number(p.timeout) || 10000 }); return json(res, 200, { ok: true }); }
        catch (e) { return json(res, 200, { ok: false, error: String(e && e.message || e) }); }
      }
      if (u.pathname === '/eval') {
        const code = String(p.js || p.code || '');
        try {
          const out = await page.evaluate(`(async()=>{ ${code} })()`);
          let s; try { s = JSON.stringify(out); } catch (_) { s = String(out); }
          return json(res, 200, { ok: true, result: (s == null ? 'undefined' : String(s)).slice(0, 8000) });
        } catch (e) { return json(res, 200, { ok: false, error: String(e && e.message || e) }); }
      }

      if (u.pathname === '/type') { await page.keyboard.type(String(p.text || '')); return json(res, 200, { ok: true }); }
      if (u.pathname === '/key')  { await page.keyboard.press(String(p.key || 'Enter')); return json(res, 200, { ok: true }); }
      if (u.pathname === '/back') { await page.goBack().catch(() => {}); return json(res, 200, { ok: true }); }
      if (u.pathname === '/forward') { await page.goForward().catch(() => {}); return json(res, 200, { ok: true }); }
      if (u.pathname === '/reload') { await page.reload().catch(() => {}); return json(res, 200, { ok: true }); }
      if (u.pathname === '/scroll') {
        // Rola a página: dir = down|up|top|bottom (default down); amount = pixels (default ~90% do viewport).
        const dir = String(p.dir || 'down').toLowerCase();
        const raw = (p.amount != null && p.amount !== '') ? Number(p.amount) : NaN;
        const info = await page.evaluate(({ dir, raw }) => {
          const step = (!isNaN(raw) && raw > 0) ? raw : Math.round(window.innerHeight * 0.9);
          if (dir === 'top') window.scrollTo(0, 0);
          else if (dir === 'bottom') window.scrollTo(0, document.documentElement.scrollHeight);
          else if (dir === 'up') window.scrollBy(0, -step);
          else window.scrollBy(0, step);
          const max = Math.max(0, document.documentElement.scrollHeight - window.innerHeight);
          const y = Math.round(window.scrollY);
          return { y, max, atTop: y <= 0, atBottom: y >= max - 1 };
        }, { dir, raw }).catch(() => ({}));
        await new Promise(r => setTimeout(r, 150)); // deixa o screencast capturar a posição nova
        return json(res, 200, { ok: true, ...info });
      }
      if (u.pathname === '/touch') { return json(res, 200, { ok: true, tab: t.id }); } // só marca atividade/cria a aba
    }
    res.writeHead(404); res.end('not found');
  } catch (e) {
    json(res, 500, { ok: false, error: String(e && e.message || e) });
  }
});

server.listen(PORT, '127.0.0.1', () => console.log('[ravi-browser] host v' + VERSION + ' em http://127.0.0.1:' + PORT));
