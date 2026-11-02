// Gera out/nls.messages.js em pt-BR a partir do language pack (igual ao build oficial do VS Code).
const fs = require('fs');
const base = '/usr/lib/code-server/lib/vscode/out';
const packPath = process.argv[2];
const keys = JSON.parse(fs.readFileSync(base + '/nls.keys.json', 'utf8'));
const en = JSON.parse(fs.readFileSync(base + '/nls.messages.json', 'utf8'));
const pack = (JSON.parse(fs.readFileSync(packPath, 'utf8')).contents) || {};
const out = [];
let i = 0, tr = 0;
for (const [mod, ks] of keys) {
  const t = pack[mod] || {};
  for (const k of ks) {
    let msg = en[i];
    if (Object.prototype.hasOwnProperty.call(t, k)) { msg = t[k]; tr++; }
    out.push(msg);
    i++;
  }
}
if (i !== en.length) { console.error('MISMATCH ' + i + ' != ' + en.length + ' — abortado'); process.exit(2); }
fs.writeFileSync(base + '/nls.messages.js', 'globalThis._VSCODE_NLS_MESSAGES=' + JSON.stringify(out) + ';\n');
fs.writeFileSync(base + '/nls.messages.json', JSON.stringify(out));
console.error('NLS pt-BR: ' + tr + '/' + out.length + ' traduzidos');
