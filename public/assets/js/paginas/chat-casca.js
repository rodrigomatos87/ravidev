// Prepara a configuração da casa (window.RAVI_CASCA) antes de o casca.js ser avaliado.
// O layout do chat não tem a casca do painel e não usa script inline: os dados vêm em #dados-casca.
const el = document.getElementById('dados-casca');
if (el && !window.RAVI_CASCA) {
    try { window.RAVI_CASCA = JSON.parse(el.textContent || '{}'); } catch (e) { window.RAVI_CASCA = {}; }
}
