// Início: medidores do servidor ao vivo (só admin). A tela já vem pintada pelo PHP;
// aqui só se atualizam os números a cada 5 s, sem bloquear nada. A limpeza para o polling.
import { chamar } from '/assets/d2/js/casca.js';

// Uma inicialização por raiz: rodar de novo na mesma raiz limpa a anterior antes.
const ativos = new WeakMap();

const gb = (b) => (Number(b) / 1073741824).toFixed(1) + ' GB';
const tom = (p) => (p >= 85 ? 'erro' : p >= 65 ? 'aviso' : 'ok');

export async function iniciar(raiz) {
    const anterior = ativos.get(raiz);
    if (anterior) anterior();

    const caixa = raiz.querySelector('#servidor-medidores');
    if (!caixa) return undefined;

    const textos = window.RAVI_CASCA && window.RAVI_CASCA.textos ? window.RAVI_CASCA.textos : {};
    let encerrado = false;

    function definir(chave, pct, meta) {
        const m = caixa.querySelector('[data-medidor="' + chave + '"]');
        if (!m) return;
        const p = Math.max(0, Math.min(100, Number(pct) || 0));
        m.querySelector('[data-campo="pct"]').textContent = p.toFixed(1) + '%';
        const barra = m.querySelector('[data-campo="barra"]');
        barra.style.transform = 'scaleX(' + (p / 100).toFixed(3) + ')';
        const prog = m.querySelector('.d2-progresso');
        prog.className = 'd2-progresso is-' + tom(p);
        prog.setAttribute('aria-valuenow', String(Math.round(p)));
        m.querySelector('[data-campo="meta"]').textContent = meta;
    }
    async function atualizar() {
        try {
            const d = await chamar('/dashboard/server');
            // A resposta pode chegar depois da limpeza: não pinta numa tela que já fechou.
            if (encerrado || !d || !d.cpu) return;
            definir('cpu', d.cpu.pct, d.cpu.cores + ' ' + (textos['inicio.nucleos'] || 'núcleos') + ' · ' + (textos['inicio.carga'] || 'carga') + ' ' + d.cpu.load);
            definir('ram', d.ram.pct, gb(d.ram.used) + ' / ' + gb(d.ram.total));
            definir('disk', d.disk.pct, gb(d.disk.used) + ' / ' + gb(d.disk.total));
        } catch (e) { /* próxima rodada tenta de novo */ }
    }
    const intervalo = setInterval(atualizar, 5000);

    const limpar = () => {
        encerrado = true;
        clearInterval(intervalo);
        if (ativos.get(raiz) === limpar) ativos.delete(raiz);
    };
    ativos.set(raiz, limpar);
    return limpar;
}
