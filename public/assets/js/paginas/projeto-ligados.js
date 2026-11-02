// Aba Projetos ligados: o Claude deste projeto passa a acessar os arquivos de outro projeto,
// só para leitura ou para leitura e escrita, enquanto a ligação estiver ativa.
import { tx, el, botao, interruptor, chamar, D2UI } from './projeto-comum.js';

const erroDe = (e, padrao) => (e && e.dados && (e.dados.error || (e.dados.data && e.dados.data.error))) || (e && e.message) || padrao;
const OPCOES_ACESSO = () => [
    { valor: 'read', rotulo: tx('projeto.ligado_leitura', 'Só leitura'), icone: 'olho' },
    { valor: 'write', rotulo: tx('projeto.ligado_escrita', 'Leitura e escrita'), icone: 'editar' },
];

export async function montarLigados({ base }) {
    const lista = el('div', {});
    let disponiveis = [];
    let outros = [];
    const p = D2UI.painel({
        titulo: tx('projeto.ligados', 'Projetos ligados'), icone: 'anexo', area: 'projetos',
        acoes: [botao(tx('projeto.ligar', 'Ligar projeto'), { icone: 'mais', tipo: 'primario', aoClicar: () => adicionar() })],
    }, el('p', { class: 'texto-mudo projeto-explica', texto: tx('projeto.ligados_sub', 'Ligue este projeto a outro para o Claude acessar os arquivos do outro sistema (útil para integração via API). Em Só leitura ele apenas entende como o outro funciona; em Leitura e escrita pode alterar os dois. Vale só enquanto a ligação está ativa. Os arquivos do outro projeto não aparecem na IDE.') }), lista);

    async function carregar() {
        lista.textContent = '';
        lista.append(D2UI.esqueleto(3));
        try {
            const d = await chamar(base + '/links');
            if (d && d.ok === false) throw new Error(d.error || '');
            disponiveis = (d && d.available) || [];
            outros = (d && d.others) || [];
            desenhar((d && d.links) || []);
        } catch (e) {
            lista.textContent = '';
            lista.append(D2UI.vazio({ chave: erroDe(e, tx('projeto.ligados_erro', 'Não foi possível carregar as ligações.')), icone: 'erro', tipo: 'erro', acao: { rotulo: tx('projeto.tentar_de_novo', 'Tentar de novo'), icone: 'atualizar', fn: carregar } }));
        }
    }

    async function alterar(ln, formulario, aoFalhar) {
        try {
            const d = await chamar(base + '/links/toggle', { metodo: 'POST', formulario: { id: ln.id, ...formulario } });
            if (d && d.ok === false) throw new Error(d.error || '');
            return true;
        } catch (e) {
            if (aoFalhar) aoFalhar();
            D2UI.toast(erroDe(e, tx('projeto.erro_generico', 'Não foi possível concluir.')), { tipo: 'erro' });
            return false;
        }
    }

    function desenhar(ligacoes) {
        lista.textContent = '';
        if (!ligacoes.length) {
            lista.append(D2UI.vazio({ chave: tx('projeto.ligados_vazio', 'Nenhum projeto ligado ainda.'), texto: tx('projeto.ligados_vazio_texto', 'Ligue outro projeto para o Claude entender ou alterar o outro sistema.'), icone: 'anexo', acao: { rotulo: tx('projeto.ligar', 'Ligar projeto'), icone: 'mais', tipo: 'primario', fn: () => adicionar() } }));
            return;
        }
        const ul = el('ul', { class: 'lista-acionavel projeto-ligados' });
        ligacoes.forEach((ln) => {
            const acesso = D2UI.campo({ tipo: 'selecao', nome: 'access_' + ln.id, rotulo: tx('projeto.ligado_acesso', 'Acesso'), obrigatorio: true, valor: ln.access, opcoes: OPCOES_ACESSO(),
                aoMudar: async (v) => {
                    if (!v || v === ln.access) return;
                    const anterior = ln.access;
                    if (await alterar(ln, { field: 'access', value: v }, () => acesso.definir(anterior))) { ln.access = v; D2UI.toast(tx('projeto.ligado_acesso_salvo', 'Acesso atualizado.'), { tipo: 'ok' }); }
                } });
            acesso.classList.add('projeto-ligado-acesso');
            ul.append(el('li', { class: 'lista-item projeto-ligado' },
                el('span', { class: 'lista-item-icone projeto-ligado-emoji', 'aria-hidden': 'true', texto: ln.icon || '📁' }),
                el('span', { class: 'lista-item-texto' },
                    el('span', { class: 'lista-item-titulo', texto: ln.name }),
                    ln.owner ? el('span', { class: 'lista-item-sub', texto: tx('projeto.ligado_de', 'de') + ' ' + ln.owner }) : null),
                acesso,
                interruptor(tx('projeto.servidor_ativo', 'Ativo'), ln.enabled, async (ligado, entrada) => {
                    entrada.disabled = true;
                    if (await alterar(ln, { field: 'enabled', on: ligado ? '1' : '0' }, () => { entrada.checked = !ligado; })) ln.enabled = ligado;
                    entrada.disabled = false;
                }),
                botao('', { icone: 'lixeira', tipo: 'fantasma', dica: tx('projeto.remover', 'Remover'), aoClicar: () => remover(ln) })));
        });
        lista.append(ul);
    }

    async function remover(ln) {
        const ok = await D2UI.confirmar({ titulo: tx('projeto.ligado_remover', 'Remover ligação'), texto: tx('projeto.ligado_remover_texto', 'Remover esta ligação? O Claude deixa de acessar os arquivos do outro projeto.'), perigo: true, palavra: ln.name, rotuloOk: tx('projeto.remover', 'Remover') });
        if (!ok) return;
        try {
            const d = await chamar(base + '/links/delete', { metodo: 'POST', formulario: { id: ln.id } });
            if (d && d.ok === false) throw new Error(d.error || '');
            D2UI.toast(tx('projeto.ligado_removido', 'Ligação removida.'), { tipo: 'ok' });
            carregar();
        } catch (e) {
            D2UI.toast(erroDe(e, tx('projeto.erro_generico', 'Não foi possível concluir.')), { tipo: 'erro' });
        }
    }

    function adicionar() {
        const opcoes = disponiveis.map((x) => ({ valor: String(x.id), rotulo: (x.icon || '📁') + ' ' + x.name }))
            .concat(outros.map((x) => ({ valor: String(x.id), rotulo: (x.icon || '📁') + ' ' + x.name, dica: tx('projeto.ligado_de', 'de') + ' ' + x.owner })));
        if (!opcoes.length) {
            D2UI.toast(tx('projeto.ligados_sem_opcao', 'Não há outro projeto disponível para ligar.'), { tipo: 'aviso' });
            return;
        }
        const projeto = D2UI.campo({ tipo: 'selecao', nome: 'linked_project_id', rotulo: tx('projeto.ligado_projeto', 'Projeto'), placeholder: tx('projeto.ligado_escolher', 'Escolher projeto…'), obrigatorio: true, comBusca: true, opcoes });
        const acesso = D2UI.campo({ tipo: 'selecao', nome: 'access', rotulo: tx('projeto.ligado_acesso', 'Acesso'), obrigatorio: true, valor: 'read', opcoes: OPCOES_ACESSO() });
        D2UI.modal({
            titulo: tx('projeto.ligar', 'Ligar projeto'), icone: 'anexo', largura: 560,
            corpo: el('div', { class: 'projeto-modal-pilha' }, projeto, acesso),
            botoes: [
                { rotulo: tx('projeto.cancelar', 'Cancelar'), tipo: 'fantasma', fecha: true },
                { rotulo: tx('projeto.ligar', 'Ligar projeto'), tipo: 'primario', icone: 'anexo', fn: async () => {
                    if (!projeto.valor()) { projeto.erro(tx('projeto.ligado_escolha', 'Escolha um projeto.')); return false; }
                    try {
                        const d = await chamar(base + '/links', { metodo: 'POST', formulario: { linked_project_id: projeto.valor(), access: acesso.valor() || 'read' } });
                        if (d && d.ok === false) throw new Error(d.error || '');
                        D2UI.toast(tx('projeto.ligado_ok', 'Projeto ligado.'), { tipo: 'ok' });
                        carregar();
                        return true;
                    } catch (e) {
                        projeto.erro(erroDe(e, tx('projeto.erro_generico', 'Não foi possível concluir.')));
                        return false;
                    }
                } },
            ],
        });
    }

    carregar();
    return p.el;
}
