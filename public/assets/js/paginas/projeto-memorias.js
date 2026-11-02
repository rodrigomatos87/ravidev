// Aba Memórias: instruções permanentes do Claude neste projeto (as mesmas no chat e na IDE).
import { tx, el, botao, carregando, chamar, D2UI } from './projeto-comum.js';

const erroDe = (e, padrao) => (e && e.dados && e.dados.error) || (e && e.message) || padrao;

export async function montarMemorias({ base }) {
    const lista = el('div', { class: 'projeto-memorias' });
    const p = D2UI.painel({
        titulo: tx('projeto.memorias', 'Memórias'), icone: 'documento', area: 'projetos',
        acoes: [botao(tx('projeto.memoria_nova', 'Nova memória'), { icone: 'mais', tipo: 'primario', aoClicar: () => abrir(null) })],
    }, el('p', { class: 'texto-mudo projeto-explica', texto: tx('projeto.memorias_sub', 'A memória do Claude neste projeto, as MESMAS no chat e na IDE, e que o próprio Claude salva enquanto trabalha. Ficam guardadas com segurança (não somem) e fora da árvore de arquivos.') }), lista);

    async function carregar() {
        lista.textContent = '';
        lista.append(D2UI.esqueleto(3));
        try {
            const d = await chamar(base + '/memories');
            if (d && d.ok === false) throw new Error(d.error || '');
            desenhar((d && d.memories) || []);
        } catch (e) {
            lista.textContent = '';
            lista.append(D2UI.vazio({ chave: erroDe(e, tx('projeto.memorias_erro', 'Não foi possível carregar as memórias.')), icone: 'erro', tipo: 'erro', acao: { rotulo: tx('projeto.tentar_de_novo', 'Tentar de novo'), icone: 'atualizar', fn: carregar } }));
        }
    }

    function desenhar(memorias) {
        lista.textContent = '';
        if (!memorias.length) {
            lista.append(D2UI.vazio({ chave: tx('projeto.memorias_vazio', 'Nenhuma memória ainda'), texto: tx('projeto.memorias_vazio_texto', 'Crie uma para o Claude usar por padrão.'), icone: 'documento', acao: { rotulo: tx('projeto.memoria_nova', 'Nova memória'), icone: 'mais', tipo: 'primario', fn: () => abrir(null) } }));
            return;
        }
        const ul = el('ul', { class: 'projeto-cartoes-memoria' });
        memorias.forEach((m) => {
            const conteudo = String(m.content || '').trim();
            const trecho = conteudo ? (conteudo.length > 140 ? conteudo.slice(0, 140) + '…' : conteudo) : tx('projeto.memoria_vazia', '(vazia)');
            ul.append(el('li', {},
                el('button', { type: 'button', class: 'projeto-memoria', onclick: () => abrir(m) },
                    el('span', { class: 'projeto-memoria-topo' }, D2UI.icone('documento'), el('strong', { class: 'projeto-memoria-nome', texto: m.name || '' })),
                    m.updated ? el('span', { class: 'texto-mudo projeto-memoria-data', texto: tx('projeto.memoria_atualizada', 'Atualizada em') + ' ' + m.updated }) : null,
                    el('span', { class: 'projeto-memoria-trecho' + (conteudo ? '' : ' texto-mudo'), texto: trecho }))));
        });
        lista.append(ul);
    }

    function abrir(m) {
        const nome = D2UI.campo({ tipo: 'texto', nome: 'name', rotulo: tx('projeto.memoria_nome', 'Nome'), placeholder: tx('projeto.memoria_nome_ph', 'Nome da memória'), obrigatorio: true, maximo: 150, valor: m ? m.name : '' });
        const area = el('textarea', { class: 'd2-control projeto-editor projeto-memoria-area', id: 'memoria-conteudo', spellcheck: 'false', placeholder: tx('projeto.memoria_conteudo_ph', 'Escreva em markdown as instruções permanentes que o Claude deve seguir neste projeto…') });
        area.value = m ? (m.content || '') : '';
        const corpo = el('div', { class: 'projeto-modal-pilha' }, nome,
            el('div', { class: 'd2-field' }, el('label', { class: 'd2-label', for: 'memoria-conteudo', texto: tx('projeto.memoria_conteudo', 'Conteúdo') }), area));
        const botoes = [];
        if (m) {
            botoes.push({ rotulo: tx('projeto.excluir', 'Excluir'), tipo: 'perigo', icone: 'lixeira', fn: async (modal) => {
                const ok = await D2UI.confirmar({ titulo: tx('projeto.memoria_excluir', 'Excluir memória'), texto: tx('projeto.memoria_excluir_texto', 'Excluir esta memória?'), perigo: true, palavra: tx('projeto.palavra_excluir', 'EXCLUIR'), rotuloOk: tx('projeto.excluir', 'Excluir') });
                if (!ok) return false;
                try {
                    const d = await chamar(base + '/memories/delete', { metodo: 'POST', formulario: { id: m.id } });
                    if (d && d.ok === false) throw new Error(d.error || '');
                    D2UI.toast(tx('projeto.memoria_excluida', 'Memória excluída.'), { tipo: 'ok' });
                    modal.fechar();
                    carregar();
                } catch (e) {
                    D2UI.toast(erroDe(e, tx('projeto.erro_generico', 'Não foi possível concluir.')), { tipo: 'erro' });
                }
                return false;
            } });
        }
        botoes.push({ rotulo: tx('projeto.cancelar', 'Cancelar'), tipo: 'fantasma', fecha: true });
        botoes.push({ rotulo: tx('projeto.salvar', 'Salvar'), tipo: 'primario', icone: 'check', fn: async () => {
            if (!String(nome.valor() || '').trim()) { nome.erro(tx('projeto.memoria_sem_nome', 'Dê um nome à memória.')); nome.focar(); return false; }
            try {
                const d = await chamar(base + '/memories', { metodo: 'POST', formulario: { id: m ? m.id : '', name: nome.valor(), content: area.value } });
                if (d && d.ok === false) throw new Error(d.error || '');
                D2UI.toast(tx('projeto.memoria_salva', 'Memória salva.'), { tipo: 'ok' });
                carregar();
                return true;
            } catch (e) {
                D2UI.toast(erroDe(e, tx('projeto.erro_generico', 'Não foi possível concluir.')), { tipo: 'erro' });
                return false;
            }
        } });
        D2UI.modal({ titulo: m ? tx('projeto.memoria_editar', 'Memória') : tx('projeto.memoria_nova', 'Nova memória'), icone: 'documento', corpo, botoes, largura: 860, classe: 'projeto-modal-editor' });
        setTimeout(() => { if (m) area.focus(); else nome.focar(); }, 50);
    }

    carregar();
    return p.el;
}
