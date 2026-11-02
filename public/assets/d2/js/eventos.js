// Barramento de eventos da tela: módulos conversam sem se conhecer.
// ouvir() devolve a função que cancela a inscrição.

const ouvintes = new Map();

function ouvir(nome, fn) {
    if (typeof fn !== 'function') return () => {};
    if (!ouvintes.has(nome)) ouvintes.set(nome, new Set());
    ouvintes.get(nome).add(fn);
    return () => {
        const lista = ouvintes.get(nome);
        if (lista) lista.delete(fn);
    };
}

function uma(nome, fn) {
    const cancelar = ouvir(nome, (dados) => {
        cancelar();
        fn(dados);
    });
    return cancelar;
}

function emitir(nome, dados) {
    const lista = ouvintes.get(nome);
    if (!lista || !lista.size) return;
    for (const fn of [...lista]) {
        try {
            fn(dados);
        } catch (erro) {
            // Um ouvinte com defeito nunca derruba os demais.
            setTimeout(() => { throw erro; });
        }
    }
}

export const Eventos = { ouvir, uma, emitir };
export default Eventos;
