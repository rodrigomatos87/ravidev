#!/usr/bin/env python3
"""
Proxy git da IDE (ravi-git-proxy) — o token do GitHub NUNCA entra em container nenhum.

Mesmo padrão do ravi-ide-proxy (token do Claude): o container tem só o HANDLE opaco (o mesmo de
ide_proxy_handles) e um rewrite de git (`url.insteadOf`) que aponta github.com para este daemon.
Um credential helper no container manda o handle como senha (Basic). Este daemon:

  1. Recebe o request do git smart HTTP (/git/<owner>/<repo>.git/info/refs, git-upload-pack,
     git-receive-pack) vindo do container.
  2. Lê o handle do Basic auth e resolve handle+repo → token do GitHub do DONO do projeto,
     chamando o PHP (bin/git-proxy-token.php). O PHP valida: handle vivo → sessão de IDE →
     projeto → repo BATE com o do projeto → ator é dono OU MEMBRO. Decrypt fica no PHP.
  3. Autorizado: repassa para github.com com Authorization Basic x-access-token:<token do dono>.
     NÃO autorizado e é LEITURA (upload-pack): repassa SEM auth — clone de repo PÚBLICO
     (dependências npm/composer via https://github.com) continua funcionando. Escrita sem
     autorização: 403.

Assim: membro do projeto pusha com a credencial do dono SEM nunca vê-la; revogou o membro (ou o
handle) → o proxy recusa na hora. Escuta só na bridge do Docker (a instância LAN,
ravi-git-proxy-lan, escuta no IP da LAN com firewall por origem — mesmo daemon). Nunca loga token.

Além do smart HTTP, GET /repos devolve (para handles de INTEGRAÇÃO de outro sistema) o JSON dos
repos que o handle alcança, via o mesmo resolvedor PHP (op "list") — é o que alimenta a lista de
projetos na tela do sistema consumidor.
"""

import base64
import http.server
import socketserver
import http.client
import os
import re
import ssl
import subprocess
import sys
import tempfile
import threading
import time
from urllib.parse import urlparse, parse_qs

LISTEN    = os.environ.get("GIT_PROXY_LISTEN", "172.17.0.1")
PORT      = int(os.environ.get("GIT_PROXY_PORT", "8789"))
RESOLVER  = os.environ.get("GIT_PROXY_RESOLVER", os.path.join(os.path.dirname(os.path.abspath(__file__)), "git-proxy-token.php"))
PHP_BIN   = os.environ.get("GIT_PROXY_PHP", "php")
UPSTREAM  = os.environ.get("GIT_PROXY_UPSTREAM", "github.com")
CACHE_TTL = float(os.environ.get("GIT_PROXY_CACHE_TTL", "30"))
# Corpo do push é bufferizado (spool memória→disco) para reenviar com Content-Length.
MAX_BODY  = int(os.environ.get("GIT_PROXY_MAX_BODY", str(1024 * 1024 * 1024)))  # 1 GiB

HOP = {"host", "authorization", "connection", "keep-alive", "transfer-encoding",
       "proxy-connection", "te", "trailer", "upgrade", "content-length", "expect"}

PATH_RE = re.compile(r"^/git/([^/]+)/([^/]+?)(?:\.git)?(/(?:info/refs|git-upload-pack|git-receive-pack))$")

_cache = {}          # (handle, repo, op) -> (token|None, expiry)
_cache_lock = threading.Lock()
_ssl_ctx = ssl.create_default_context()


def resolve(handle: str, repo: str, op: str):
    """(handle, owner/repo, read|write) -> token do dono, ou None se não autorizado."""
    key = (handle, repo, op)
    now = time.time()
    with _cache_lock:
        hit = _cache.get(key)
        if hit and hit[1] > now:
            return hit[0]
    token = None
    try:
        out = subprocess.run([PHP_BIN, RESOLVER, handle, repo, op],
                             capture_output=True, timeout=15)
        if out.returncode == 0:
            t = out.stdout.decode("utf-8", "ignore").strip()
            token = t or None
    except Exception:
        token = None
    with _cache_lock:
        _cache[key] = (token, now + CACHE_TTL)
    return token


class Handler(http.server.BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"

    def _deny(self, code: int, msg: str, basic_challenge: bool = False):
        body = (msg + "\n").encode()
        self.send_response(code)
        if basic_challenge:
            self.send_header("WWW-Authenticate", 'Basic realm="ravi-git"')
        self.send_header("Content-Type", "text/plain")
        self.send_header("Content-Length", str(len(body)))
        self.send_header("Connection", "close")
        self.end_headers()
        try:
            self.wfile.write(body)
        except Exception:
            pass

    def _read_body(self):
        """Lê o corpo (Content-Length OU chunked) para um spool. Devolve (spool, tamanho) ou None se exceder."""
        spool = tempfile.SpooledTemporaryFile(max_size=32 * 1024 * 1024)
        total = 0
        te = (self.headers.get("Transfer-Encoding") or "").lower()
        if "chunked" in te:
            while True:
                line = self.rfile.readline(64)
                try:
                    size = int(line.split(b";")[0].strip() or b"0", 16)
                except ValueError:
                    return None
                if size == 0:
                    self.rfile.readline()  # CRLF final (ignora trailers)
                    break
                remaining = size
                while remaining > 0:
                    chunk = self.rfile.read(min(remaining, 65536))
                    if not chunk:
                        return None
                    spool.write(chunk)
                    total += len(chunk)
                    remaining -= len(chunk)
                    if total > MAX_BODY:
                        return None
                self.rfile.read(2)  # CRLF do chunk
        else:
            length = int(self.headers.get("Content-Length", 0) or 0)
            if length > MAX_BODY:
                return None
            remaining = length
            while remaining > 0:
                chunk = self.rfile.read(min(remaining, 65536))
                if not chunk:
                    break
                spool.write(chunk)
                total += len(chunk)
                remaining -= len(chunk)
        spool.seek(0)
        return (spool, total)

    def _handle_auth(self) -> str:
        """Handle do Basic auth (senha; o usuário é ignorado)."""
        auth = self.headers.get("Authorization", "")
        if auth.lower().startswith("basic "):
            try:
                cred = base64.b64decode(auth[6:].strip()).decode("utf-8", "ignore")
                return cred.split(":", 1)[1] if ":" in cred else ""
            except Exception:
                return ""
        return ""

    def _repos(self):
        """/repos (handles de INTEGRAÇÃO): GET lista os repos que o handle alcança;
        POST {"nome": "..."} cria um repo novo + projeto no RaviDev (resolvedor op create)."""
        handle = self._handle_auth()
        if not handle:
            return self._deny(401, "credencial ausente", basic_challenge=True)
        if self.command == "POST":
            raw = self._read_body()
            if raw is None:
                return self._deny(413, "corpo grande demais")
            spool, total = raw
            try:
                import json
                nome = str(json.loads(spool.read().decode("utf-8", "ignore") or "{}").get("nome", ""))
            except Exception:
                nome = ""
            finally:
                spool.close()
            if not nome:
                return self._deny(400, "nome ausente")
            args, oplabel = [PHP_BIN, RESOLVER, handle, nome, "create"], "create"
        else:
            args, oplabel = [PHP_BIN, RESOLVER, handle, "-", "list"], "list"
        try:
            out = subprocess.run(args, capture_output=True, timeout=40)
        except Exception:
            out = None
        if out is None or out.returncode != 0 or not out.stdout.strip():
            self._log(handle, "-", "deny-" + oplabel)
            return self._deny(403, "handle sem permissao para " + oplabel)
        body = out.stdout
        self.send_response(200)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(body)))
        self.send_header("Connection", "close")
        self.end_headers()
        try:
            self.wfile.write(body)
        except Exception:
            pass
        self._log(handle, "-", "ok-" + oplabel)

    def _proxy(self):
        self.close_connection = True
        url = urlparse(self.path)
        if url.path == "/repos" and self.command in ("GET", "POST"):
            return self._repos()
        m = PATH_RE.match(url.path)
        if not m:
            return self._deny(404, "not found")
        owner, name, tail = m.group(1), m.group(2), m.group(3)
        repo = owner + "/" + name

        # Operação: receive-pack = escrita (push); upload-pack = leitura (clone/fetch/ls-remote).
        svc = (parse_qs(url.query).get("service") or [""])[0]
        write = tail.endswith("git-receive-pack") or svc == "git-receive-pack"
        op = "write" if write else "read"

        # Sem credencial → desafia (o git então consulta o credential helper e repete com o handle).
        handle = self._handle_auth()
        if not handle:
            return self._deny(401, "credencial ausente", basic_challenge=True)

        token = resolve(handle, repo, op)
        if token is None and write:
            self._log(handle, repo, "deny-write")
            return self._deny(403, "sem permissao para enviar a este repositorio")

        body = self._read_body()
        if body is None:
            self._log(handle, repo, "body-too-big")
            return self._deny(413, "corpo grande demais")
        spool, total = body

        fwd = {k: v for k, v in self.headers.items() if k.lower() not in HOP}
        fwd["Host"] = UPSTREAM
        fwd["Content-Length"] = str(total)
        if token is not None:
            fwd["Authorization"] = "Basic " + base64.b64encode(
                ("x-access-token:" + token).encode()).decode()
        # token None + read → segue anônimo (repo público).

        upstream_path = "/" + owner + "/" + name + ".git" + tail
        if url.query:
            upstream_path += "?" + url.query

        try:
            up = http.client.HTTPSConnection(UPSTREAM, 443, timeout=600, context=_ssl_ctx)
            up.request(self.command, upstream_path, body=spool if total else None, headers=fwd)
            resp = up.getresponse()
        except Exception:
            self._log(handle, repo, "upstream-fail")
            return self._deny(502, "falha ao falar com o github")
        finally:
            try:
                spool.close()
            except Exception:
                pass

        self.send_response(resp.status)
        for k, v in resp.getheaders():
            if k.lower() in ("transfer-encoding", "content-length", "connection", "keep-alive"):
                continue
            self.send_header(k, v)
        self.send_header("Connection", "close")
        self.end_headers()
        try:
            while True:
                chunk = resp.read(65536)
                if not chunk:
                    break
                self.wfile.write(chunk)
                self.wfile.flush()
        except Exception:
            pass
        finally:
            up.close()
        self._log(handle, repo, "ok-" + op + ("-auth" if token else "-anon"), resp.status)

    do_GET = _proxy
    do_POST = _proxy

    def _log(self, handle, repo, what, status=""):
        pfx = (handle[:16] + "…") if handle else "-"
        sys.stderr.write("git-proxy %s handle=%s repo=%s %s\n" % (what, pfx, repo, status))
        sys.stderr.flush()

    def log_message(self, *a):
        pass


class Server(socketserver.ThreadingMixIn, http.server.HTTPServer):
    daemon_threads = True
    allow_reuse_address = True


def main():
    srv = Server((LISTEN, PORT), Handler)
    sys.stderr.write("git-proxy escutando em %s:%d -> %s\n" % (LISTEN, PORT, UPSTREAM))
    sys.stderr.flush()
    try:
        srv.serve_forever()
    except KeyboardInterrupt:
        pass


if __name__ == "__main__":
    main()
