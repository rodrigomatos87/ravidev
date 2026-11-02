'use strict';
/**
 * Extensão "Ravi — Sincronizar Git". Adiciona um ícone (sincronizar) na barra de título do
 * Explorador, no topo. Ao clicar: commit + push do /workspace para o GitHub, usando a credencial
 * já configurada no container (token do dono). Sem login/upstream do git nativo.
 * O ícone tem versão clara (light) e BRANCA (dark) para ficar visível no tema escuro.
 * Extensão INDEPENDENTE — sem relação com a do navegador ao vivo.
 */
const vscode = require('vscode');
const cp = require('child_process');

function sync() {
  return vscode.window.withProgress(
    { location: vscode.ProgressLocation.Notification, title: 'Sincronizando com o GitHub…' },
    () => new Promise((resolve) => {
      // add tudo (respeita .gitignore) → commit (ok se não houver nada) → push (com fallback de upstream).
      const sh = 'cd /workspace && git add -A && '
        + '(git commit -m "Sincronização via IDE ($(date \'+%Y-%m-%d %H:%M\'))" || true) && '
        + '(git push || git push -u origin HEAD)';
      cp.exec(sh, { maxBuffer: 8 * 1024 * 1024 }, (err, stdout, stderr) => {
        if (err) {
          const msg = String(stderr || err.message || '').split('\n').filter(Boolean).pop() || 'erro desconhecido';
          vscode.window.showErrorMessage('Falha ao sincronizar com o GitHub: ' + msg);
        } else {
          vscode.window.showInformationMessage('Projeto sincronizado com o GitHub.');
        }
        resolve();
      });
    })
  );
}

function activate(context) {
  context.subscriptions.push(vscode.commands.registerCommand('raviGit.sync', sync));
}
function deactivate() {}

module.exports = { activate, deactivate };
