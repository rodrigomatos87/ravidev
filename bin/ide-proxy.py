#!/usr/bin/env python3
"""
Proxy de credencial da IDE (ravi-ide-proxy) — estanca o vazamento de token do Claude Code.

O container da IDE recebe APENAS um handle opaco (CLAUDE_CODE_OAUTH_TOKEN=<handle>, formato
sk-ant-oat01-… para o CLI aceitar) + ANTHROPIC_BASE_URL apontando para este proxy. O token real
NUNCA entra no container. Este daemon:

  1. Recebe o request do `claude` (o CLI já monta o request de assinatura perfeito: /v1/messages?beta=true,
     x-app: cli, lista anthropic-beta completa, x-stainless-*, session-id).
  2. Lê o handle do header Authorization: Bearer <handle>.
  3. Resolve handle → token real chamando o PHP (bin/ide-proxy-token.php) — o decrypt fica no PHP.
     Cache curto em memória por handle (evita bootstrap do PHP a cada request).
  4. Troca SÓ o header Authorization (handle → token real) e repassa TODO o resto verbatim para a
     Anthropic, com streaming. Como só a autenticação muda, o bucket da assinatura é preservado.

Escuta só na bridge do Docker (containers alcançam; a LAN não). Roda como www-data (systemd).
Nunca loga o token — só prefixo do handle + status, para auditoria (qual licença serviu fica no
last_used_at das tabelas via o resolver).
"""

import http.server
import socketserver
import http.client
import json
import os
import ssl
import subprocess
import sys
import threading
import time

LISTEN   = os.environ.get("IDE_PROXY_LISTEN", "172.17.0.1")
PORT     = int(os.environ.get("IDE_PROXY_PORT", "8788"))
RESOLVER = os.environ.get("IDE_PROXY_RESOLVER", os.path.join(os.path.dirname(os.path.abspath(__file__)), "ide-proxy-token.php"))
PHP_BIN  = os.environ.get("IDE_PROXY_PHP", "php")
UPSTREAM = os.environ.get("IDE_PROXY_UPSTREAM", "api.anthropic.com")
CACHE_TTL = float(os.environ.get("IDE_PROXY_CACHE_TTL", "45"))
# Licença no TETO de uso (limite do admin p/ IDE/Dev Autônomo): o bloqueio fica em cache curto,
# para re-perguntar logo ao PHP e liberar assim que a janela renovar / o uso baixar.
BLOCK_TTL = float(os.environ.get("IDE_PROXY_BLOCK_TTL", "30"))

# Headers que NÃO devem ser repassados (hop-by-hop) + Authorization (trocado) + Host (recalculado).
HOP = {"host", "authorization", "connection", "keep-alive", "transfer-encoding",
       "proxy-connection", "te", "trailer", "upgrade", "content-length"}

_cache = {}          # handle -> (entry, expiry_ts); entry = {token, lic, limit} ou {block:(uso,teto,reset), lic}
_cache_lock = threading.Lock()
_ssl_ctx = ssl.create_default_context()

# Uso do plano observado de passagem (gráficos de Admin → Licenças). A Anthropic devolve em toda
# resposta os headers anthropic-ratelimit-unified-* (sessão 5h, semana e, SÓ em respostas de
# requests Fable reais do Claude Code, a janela do Fable "7d_oi"). Como toda request da IDE passa
# aqui, anotamos esses headers por licença — custo zero, sem sonda extra.
OBSERVED = os.environ.get("IDE_PROXY_OBSERVED", os.path.join(
    os.path.dirname(os.path.dirname(os.path.abspath(RESOLVER))), "storage", "cache", "license-usage-observed.json"))
_obs_lock = threading.Lock()
_obs_data = None  # cópia em memória do OBSERVED para o corte ao vivo do teto (carregada sob demanda)


def _obs_load():
    """Observações em memória (semeadas do arquivo na primeira leitura). Chamar com _obs_lock."""
    global _obs_data
    if _obs_data is None:
        try:
            with open(OBSERVED, "r") as f:
                d = json.load(f)
            _obs_data = d if isinstance(d, dict) else {}
        except Exception:
            _obs_data = {}
    return _obs_data


def usage_over(lic: str, limit: int):
    """Corte AO VIVO pelo teto do admin (IDE/Dev Autônomo): a última leitura de passagem desta
    licença mostra alguma janela do plano (5h/7d) em >= teto? Devolve (uso, teto, reset_epoch)
    ou None. Janela com reset já vencido renovou — não conta. Complementa o bloqueio do resolver:
    aqui o corte acontece no meio do uso, sem esperar o cache do handle vencer."""
    if not lic or not limit:
        return None
    with _obs_lock:
        ent = _obs_load().get(lic)
    hdrs = ent.get("headers") if isinstance(ent, dict) else None
    if not isinstance(hdrs, dict):
        return None
    now = int(time.time())
    worst = None
    for key in ("5h", "7d"):
        u = hdrs.get("anthropic-ratelimit-unified-%s-utilization" % key)
        if not (isinstance(u, list) and u):
            continue
        try:
            pct = int(float(u[0]) * 100)
        except (TypeError, ValueError):
            continue
        reset = 0
        r = hdrs.get("anthropic-ratelimit-unified-%s-reset" % key)
        if isinstance(r, list) and r:
            try:
                reset = int(r[0])
            except (TypeError, ValueError):
                reset = 0
        if reset and reset <= now:
            continue  # a janela renovou desde a última leitura
        if pct >= limit and (worst is None or pct > worst[0]):
            worst = (pct, limit, reset)
    return worst


def resolve(handle: str):
    """handle -> dict (cache curto): {token, lic, limit} ou, licença no teto de uso do admin,
    {block: (uso, teto, reset_epoch), lic}. None se handle inválido/revogado/licença inativa."""
    now = time.time()
    with _cache_lock:
        hit = _cache.get(handle)
        if hit and hit[1] > now:
            return hit[0]
    try:
        out = subprocess.run([PHP_BIN, RESOLVER, handle],
                             capture_output=True, timeout=15)
    except Exception:
        return None
    lic = ""
    limit = 0
    block = None
    for line in out.stderr.decode("utf-8", "ignore").splitlines():
        if line.startswith("license_id="):
            lic = line[len("license_id="):].strip()
        elif line.startswith("usage_limit="):
            try:
                limit = int(line[len("usage_limit="):].strip())
            except ValueError:
                limit = 0
        elif line.startswith("limit_block="):
            try:
                uso, teto, reset = line[len("limit_block="):].strip().split(":")
                block = (int(uso), int(teto), int(reset))
            except Exception:
                block = (0, limit, 0)
    if out.returncode == 3 and block is not None:
        entry = {"block": block, "lic": lic}
        with _cache_lock:
            _cache[handle] = (entry, now + BLOCK_TTL)
        return entry
    token = out.stdout.decode("utf-8", "ignore").strip() if out.returncode == 0 else ""
    if token == "":
        return None
    entry = {"token": token, "lic": lic, "limit": limit}
    with _cache_lock:
        _cache[handle] = (entry, now + CACHE_TTL)
    return entry


def observe(lic: str, model: str, status: int, headers):
    """Anota por licença os headers de uso do plano da resposta. Best-effort: nunca derruba o proxy.
    Guarda por header [valor, quando, modelo], mesclando: uma resposta Haiku/Opus renova as janelas
    5h/7d sem apagar a última leitura do Fable (7d_oi), que só vem em respostas Fable."""
    if not lic:
        return
    rl = {k.lower(): v for k, v in headers if k.lower().startswith("anthropic-ratelimit-unified-")}
    if not rl:
        return
    now = int(time.time())
    with _obs_lock:
        try:
            with open(OBSERVED, "r") as f:
                data = json.load(f)
            if not isinstance(data, dict):
                data = {}
        except Exception:
            data = {}
        ent = data.get(lic) if isinstance(data.get(lic), dict) else {}
        hdrs = ent.get("headers") if isinstance(ent.get("headers"), dict) else {}
        for k, v in rl.items():
            hdrs[k] = [v, now, model]
        data[lic] = {"headers": hdrs, "last": now, "model": model, "status": status}
        tmp = OBSERVED + ".tmp"
        with open(tmp, "w") as f:
            json.dump(data, f)
        os.replace(tmp, OBSERVED)
        global _obs_data
        _obs_data = data  # mantém o corte ao vivo do teto com a leitura mais fresca


class Handler(http.server.BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"

    def _reject(self, code: int, msg: str, etype: str = "authentication_error"):
        body = json.dumps({"type": "error", "error": {"type": etype, "message": msg}}).encode()
        self.send_response(code)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.send_header("Connection", "close")
        self.end_headers()
        self.wfile.write(body)

    def _reject_limit(self, info):
        """429 com explicação clara quando a licença está no teto de uso definido pelo admin."""
        uso, teto, reset = info
        quando = ""
        if reset:
            quando = time.strftime(" A janela renova %d/%m às %H:%M.", time.localtime(reset))
        msg = ("Conta do Claude pausada pelo administrador: o uso chegou a %d%% e o teto para a IDE "
               "e o Dev Autônomo é %d%% (a folga restante fica reservada para a API).%s") % (uso, teto, quando)
        return self._reject(429, msg, "rate_limit_error")

    def _proxy(self):
        self.close_connection = True
        # corpo do request
        length = int(self.headers.get("Content-Length", 0) or 0)
        body = self.rfile.read(length) if length else b""

        # handle do Authorization
        auth = self.headers.get("Authorization", "")
        handle = auth[7:].strip() if auth.lower().startswith("bearer ") else ""
        if not handle:
            self._log(handle, "no-handle")
            return self._reject(401, "handle ausente")
        res = resolve(handle)
        if not res:
            self._log(handle, "reject")
            return self._reject(401, "handle invalido ou revogado")
        if "block" in res:
            self._log(handle, "limit-block")
            return self._reject_limit(res["block"])
        token, lic, limit = res["token"], res["lic"], res.get("limit", 0)

        # Corte AO VIVO pelo teto: dentro do cache do handle, a última resposta observada já
        # pode ter cruzado o teto — recusa aqui mesmo, sem esperar o resolver re-checar.
        if limit:
            over = usage_over(lic, limit)
            if over:
                self._log(handle, "limit-live")
                return self._reject_limit(over)

        # modelo pedido (só para etiquetar a observação de uso; o corpo segue intacto)
        model = ""
        if body[:1] == b"{":
            try:
                model = str(json.loads(body).get("model") or "")
            except Exception:
                model = ""

        # repassa tudo verbatim, trocando só Authorization
        fwd = {k: v for k, v in self.headers.items() if k.lower() not in HOP}
        fwd["Authorization"] = "Bearer " + token
        fwd["Content-Length"] = str(len(body))

        try:
            up = http.client.HTTPSConnection(UPSTREAM, 443, timeout=600, context=_ssl_ctx)
            up.request(self.command, self.path, body=body, headers=fwd)
            resp = up.getresponse()
        except Exception as e:
            self._log(handle, "upstream-fail")
            return self._reject(502, "falha upstream")

        try:
            observe(lic, model, resp.status, resp.getheaders())
        except Exception:
            pass

        # resposta: streaming em chunks; Connection: close (cliente lê até EOF).
        self.send_response(resp.status)
        for k, v in resp.getheaders():
            if k.lower() in ("transfer-encoding", "content-length", "connection", "keep-alive"):
                continue
            self.send_header(k, v)   # preserva Content-Type e Content-Encoding verbatim
        self.send_header("Connection", "close")
        self.end_headers()
        try:
            while True:
                chunk = resp.read(8192)
                if not chunk:
                    break
                self.wfile.write(chunk)
                self.wfile.flush()
        except Exception:
            pass
        finally:
            up.close()
        self._log(handle, "ok", resp.status)

    do_POST = _proxy
    do_GET = _proxy
    do_DELETE = _proxy

    def _log(self, handle, what, status=""):
        pfx = (handle[:16] + "…") if handle else "-"
        sys.stderr.write("ide-proxy %s handle=%s %s\n" % (what, pfx, status))
        sys.stderr.flush()

    def log_message(self, *a):
        pass


class Server(socketserver.ThreadingMixIn, http.server.HTTPServer):
    daemon_threads = True
    allow_reuse_address = True


def main():
    srv = Server((LISTEN, PORT), Handler)
    sys.stderr.write("ide-proxy escutando em %s:%d -> %s\n" % (LISTEN, PORT, UPSTREAM))
    sys.stderr.flush()
    try:
        srv.serve_forever()
    except KeyboardInterrupt:
        pass


if __name__ == "__main__":
    main()
