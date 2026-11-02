#!/usr/bin/env node
'use strict';

/*
 * claude-wrapper.js — envelope FINO do binário `claude` real.
 *
 * Objetivo: saber com PRECISÃO se o Claude está "trabalhando" (gerando/aguardando a API
 * dentro de um turno) ou "ocioso" (sessão aberta parada), sem depender de delta de CPU
 * (que pisca durante as esperas da API).
 *
 * Como: faz spawn do `claude` real repassando stdin/stdout/stderr bit-a-bit e, observando o
 * protocolo stream-json que a extensão e o terminal usam, grava o estado num arquivo por
 * invocação em /tmp/claude-state/<pid-do-wrapper>:
 *   - mensagem de usuário no stdin                          => "working" (início/continuação de turno)
 *   - control_request "can_use_tool" no stdout (pede        => "waiting" (PAUSADO aguardando VOCÊ:
 *     aprovação de ferramenta / pergunta ao usuário)                    permissão ou resposta)
 *   - control_response no stdin enquanto "waiting"          => "working" (você respondeu → retoma)
 *   - evento {"type":"result",...} no stdout                => "idle"    (fim do turno)
 *   - processo sair                                         => remove o arquivo (idle)
 *
 * Três estados → bolinha no /ide: working=verde, waiting=AZUL (precisa de você), idle=cinza.
 *
 * No modo simples (`claude -p "..."` SEM --input-format stream-json) não há turnos
 * interativos: o processo roda uma tarefa e sai. Aí "working" = processo vivo (cobre a espera
 * da API igualmente, pois o processo segue vivo durante toda a geração).
 *
 * Invocação: node claude-wrapper.js <caminho-do-claude-real> [args do claude...]
 */

const { spawn } = require('child_process');
const fs = require('fs');
const path = require('path');

const REAL = process.argv[2];
const ARGS = process.argv.slice(3);

const STATE_DIR = '/tmp/claude-state';
const STATE_FILE = path.join(STATE_DIR, String(process.pid));

// Detecta o modo stream-json no STDIN (a extensão Claude Code chama assim) e o modo -p/--print
// (one-shot, ex.: ClaudeCodeService). Só nesses modos sabemos o estado com precisão.
// O `claude` INTERATIVO no terminal (TUI, sem stream-json e sem -p) NÃO é rastreável aqui —
// não escrevemos arquivo e o IdeService cai no fallback de CPU (needle 'claude') para ele.
let streamInput = false;
let printMode = false;
for (let i = 0; i < ARGS.length; i++) {
    if (ARGS[i] === '--input-format' && ARGS[i + 1] === 'stream-json') streamInput = true;
    if (ARGS[i] === '--input-format=stream-json') streamInput = true;
    if (ARGS[i] === '-p' || ARGS[i] === '--print') printMode = true;
}
const trackable = streamInput || printMode;

// Captura de depuração (opcional): RAVI_CLAUDE_DEBUG=1 grava as transições de estado e as
// linhas de control_* observadas em /tmp/claude-state/debug-<pid>.log. Serve para CONFIRMAR,
// contra a extensão real, qual evento marca "aguardando você". Desligado por padrão.
const DEBUG = process.env.RAVI_CLAUDE_DEBUG === '1';
const DEBUG_FILE = path.join(STATE_DIR, 'debug-' + process.pid + '.log');
function dbg(msg) {
    if (!DEBUG) return;
    try { fs.mkdirSync(STATE_DIR, { recursive: true }); fs.appendFileSync(DEBUG_FILE, msg + '\n'); } catch (e) {}
}

let cur = '';
function writeState(s) {
    if (s !== cur) dbg('STATE ' + (cur || '-') + ' -> ' + s);
    cur = s;
    try {
        fs.mkdirSync(STATE_DIR, { recursive: true });
        fs.writeFileSync(STATE_FILE, s);
    } catch (e) { /* não pode quebrar o claude por causa do telemetria */ }
}
function clearState() {
    try { fs.unlinkSync(STATE_FILE); } catch (e) {}
}

// Estado inicial: stream-json (extensão) começa ocioso (aguardando o 1º input); -p/--print
// (one-shot) já está trabalhando (roda e sai). TUI interativo: não escreve nada (fallback de CPU).
if (trackable) {
    writeState(streamInput ? 'idle' : 'working');
}

const child = spawn(REAL, ARGS, { stdio: ['pipe', 'pipe', 'inherit'] });

// --- stdin: processo pai -> claude real (com observação de início de turno) ---
let inBuf = '';
process.stdin.on('data', (chunk) => {
    const ok = child.stdin.write(chunk);
    if (!ok) { process.stdin.pause(); child.stdin.once('drain', () => process.stdin.resume()); }
    if (streamInput) {
        inBuf += chunk.toString('utf8');
        let nl;
        while ((nl = inBuf.indexOf('\n')) >= 0) {
            const line = inBuf.slice(0, nl);
            inBuf = inBuf.slice(nl + 1);
            // Mensagem de usuário = início/continuação de turno → trabalhando.
            if (line.indexOf('"type":"user"') >= 0 || line.indexOf('"type": "user"') >= 0) {
                writeState('working');
            // Resposta a um control_request (você aprovou/respondeu) enquanto pausado → retoma.
            } else if (cur === 'waiting' &&
                       (line.indexOf('"type":"control_response"') >= 0 || line.indexOf('"type": "control_response"') >= 0)) {
                writeState('working');
            }
        }
    }
});
process.stdin.on('end', () => { try { child.stdin.end(); } catch (e) {} });
process.stdin.on('error', () => {});
child.stdin.on('error', () => {});

// --- stdout: claude real -> processo pai (com observação de fim de turno) ---
let outBuf = '';
child.stdout.on('data', (chunk) => {
    const ok = process.stdout.write(chunk);
    if (!ok) { child.stdout.pause(); process.stdout.once('drain', () => child.stdout.resume()); }
    if (streamInput) {
        outBuf += chunk.toString('utf8');
        let nl;
        while ((nl = outBuf.indexOf('\n')) >= 0) {
            const line = outBuf.slice(0, nl);
            outBuf = outBuf.slice(nl + 1);
            // O evento "result" marca o fim do turno → ocioso (até o próximo input).
            if (line.indexOf('"type":"result"') >= 0 || line.indexOf('"type": "result"') >= 0) {
                writeState('idle');
            // O claude PEDE permissão de ferramenta / faz pergunta (control_request can_use_tool):
            // está PAUSADO aguardando você → "waiting" (bolinha azul, "venha responder").
            } else if ((line.indexOf('"type":"control_request"') >= 0 || line.indexOf('"type": "control_request"') >= 0)
                       && line.indexOf('can_use_tool') >= 0) {
                writeState('waiting');
            } else if (DEBUG && line.indexOf('control_request') >= 0) {
                dbg('CTRL_REQ ' + line.slice(0, 400)); // confirma o schema real contra a extensão
            }
        }
        // Trava de segurança: não deixar o buffer crescer sem limite se não houver \n.
        if (outBuf.length > 1 << 20) outBuf = outBuf.slice(-4096);
    }
});

child.on('exit', (code, signal) => {
    clearState();
    if (signal) { process.kill(process.pid, signal); return; }
    process.exit(code == null ? 0 : code);
});
child.on('error', (e) => {
    clearState();
    try { process.stderr.write('claude-wrapper: ' + e.message + '\n'); } catch (e2) {}
    process.exit(127);
});

// Repassa sinais ao filho (Ctrl-C no terminal, parada do code-server, etc.).
['SIGINT', 'SIGTERM', 'SIGHUP'].forEach((sig) => {
    process.on(sig, () => { try { child.kill(sig); } catch (e) {} });
});

process.on('exit', clearState);
