// Aba Compartilhar: membros do projeto, convite por busca de usuário, remoção,
// saída do projeto (membro) e transferência de titularidade (dono).
import { tx, el, botao, carregando, chamar, D2UI } from './projeto-comum.js';

const msgErro = (e, padrao) => (e && e.dados && e.dados.error) || (e && e.message && e.message !== 'Erro' ? e.message : '') || padrao;

// Campo de busca de usuários com sugestões (GET share/users?q=), usado no convite e na transferência.
function buscaUsuarios(base, { rotulo, placeholder, aoEscolher, aoEnterTexto }) {
    const id = 'busca-' + Math.random().toString(36).slice(2, 8);
    const entrada = el('input', { class: 'd2-control', id, type: 'search', autocomplete: 'off', placeholder, role: 'combobox', 'aria-expanded': 'false', 'aria-controls': id + '-lista', 'aria-autocomplete': 'list' });
    const sugestoes = el('ul', { class: 'projeto-sugestoes', id: id + '-lista', role: 'listbox', hidden: true });
    let atuais = [];
    let temporizador = null;
    let pedido = 0;
    const fechar = () => { sugestoes.hidden = true; entrada.setAttribute('aria-expanded', 'false'); };
    async function buscar() {
        const n = ++pedido;
        try {
            const d = await chamar(base + '/share/users?q=' + encodeURIComponent(entrada.value.trim()));
            if (n !== pedido) return;
            atuais = (d && d.users) || [];
        } catch (e) { atuais = []; }
        sugestoes.textContent = '';
        if (!atuais.length) {
            sugestoes.append(el('li', { class: 'projeto-sugestao is-vazia', texto: tx('projeto.share_nenhum', 'Nenhum usuário disponível.') }));
        }
        for (const u of atuais) {
            sugestoes.append(el('li', { role: 'option', class: 'projeto-sugestao', tabindex: '-1', onmousedown: (ev) => { ev.preventDefault(); escolher(u); } },
                el('span', { class: 'd2-avatar d2-avatar-sm', 'aria-hidden': 'true', texto: u.initial || (u.name || u.email || '?').charAt(0).toUpperCase() }),
                el('span', { class: 'lista-item-texto' }, el('span', { class: 'lista-item-titulo', texto: u.name || u.email }), el('span', { class: 'lista-item-sub', texto: u.email || '' }))));
        }
        sugestoes.hidden = false;
        entrada.setAttribute('aria-expanded', 'true');
    }
    function escolher(u) { fechar(); aoEscolher(u, entrada); }
    const agendar = () => { clearTimeout(temporizador); temporizador = setTimeout(buscar, 160); };
    entrada.addEventListener('input', agendar);
    entrada.addEventListener('focus', agendar);
    entrada.addEventListener('blur', () => setTimeout(fechar, 120));
    entrada.addEventListener('keydown', (ev) => {
        if (ev.key === 'Escape') { fechar(); return; }
        if (ev.key !== 'Enter') return;
        ev.preventDefault();
        if (atuais.length && !sugestoes.hidden) escolher(atuais[0]);
        else if (aoEnterTexto) aoEnterTexto(entrada.value.trim(), entrada);
    });
    const caixa = el('div', { class: 'd2-field projeto-busca' },
        el('label', { class: 'd2-label', for: id, texto: rotulo }),
        el('div', { class: 'd2-search' }, D2UI.icone('busca'), entrada),
        sugestoes);
    return { caixa, entrada };
}

export async function montarCompartilhar({ dados, base, raiz, recarregar }) {
    const membros = Array.isArray(dados.membros) ? [...dados.membros] : [];
    const listaMembros = el('ul', { class: 'lista-acionavel projeto-membros' });

    function desenharMembros() {
        listaMembros.textContent = '';
        listaMembros.append(el('li', { class: 'lista-item' },
            el('span', { class: 'd2-avatar d2-avatar-cor-5', 'aria-hidden': 'true' }, D2UI.icone('estrela-cheia')),
            el('span', { class: 'lista-item-texto' },
                el('span', { class: 'lista-item-titulo', texto: dados.donoNome || tx('projeto.share_dono', 'Dono do projeto') }),
                el('span', { class: 'lista-item-sub', texto: tx('projeto.share_dono', 'Dono do projeto') }))));
        membros.forEach((m, i) => {
            const remover = dados.dono ? botao('', {
                icone: 'fechar', tipo: 'fantasma', dica: tx('projeto.share_remover', 'Remover acesso'),
                rotuloAria: tx('projeto.share_remover', 'Remover acesso') + ' ' + (m.nome || m.email),
                aoClicar: async (ev) => {
                    const ok = await D2UI.confirmar({ titulo: tx('projeto.share_remover', 'Remover acesso'), texto: tx('projeto.share_remover_texto', 'Remover o acesso de %s a este projeto?').replace('%s', m.nome || m.email), perigo: true, rotuloOk: tx('projeto.share_remover_ok', 'Remover') });
                    if (!ok) return;
                    const soltar = carregando(ev.currentTarget || null);
                    try {
                        const d = await chamar(base + '/unshare', { metodo: 'POST', formulario: { user_id: m.id } });
                        if (!(d && d.ok)) throw new Error((d && d.error) || '');
                        const k = membros.findIndex((x) => x.id === m.id);
                        if (k >= 0) membros.splice(k, 1);
                        desenharMembros();
                        D2UI.toast(tx('projeto.share_removido', 'Acesso removido.'), { tipo: 'ok' });
                    } catch (e) {
                        soltar();
                        D2UI.toast(msgErro(e, tx('projeto.share_falha_remover', 'Falha ao remover.')), { tipo: 'erro' });
                    }
                },
            }) : null;
            listaMembros.append(el('li', { class: 'lista-item' },
                el('span', { class: 'd2-avatar d2-avatar-cor-' + ((i % 7) + 2), 'aria-hidden': 'true', texto: (m.nome || m.email || '?').charAt(0).toUpperCase() }),
                el('span', { class: 'lista-item-texto' }, el('span', { class: 'lista-item-titulo', texto: m.nome || m.email }), el('span', { class: 'lista-item-sub', texto: m.email })),
                remover));
        });
    }
    desenharMembros();

    const corpo = [];
    if (dados.dono) {
        const compartilhar = async (email, entrada) => {
            if (!email) return;
            entrada.disabled = true;
            try {
                const d = await chamar(base + '/share', { metodo: 'POST', formulario: { email } });
                if (!(d && d.ok)) throw new Error((d && d.error) || '');
                const u = d.user || {};
                if (u.id && !membros.some((x) => x.id === Number(u.id))) membros.push({ id: Number(u.id), nome: u.name || '', email: u.email || email, contexto: false });
                desenharMembros();
                entrada.value = '';
                D2UI.toast(tx('projeto.share_ok', 'Compartilhado com') + ' ' + (u.email || email), { tipo: 'ok' });
            } catch (e) {
                D2UI.toast(msgErro(e, tx('projeto.share_falha', 'Falha ao compartilhar.')), { tipo: 'erro' });
            } finally {
                entrada.disabled = false;
                entrada.focus();
            }
        };
        const convite = buscaUsuarios(base, {
            rotulo: tx('projeto.share_adicionar', 'Adicionar membro'),
            placeholder: tx('projeto.share_buscar', 'Buscar usuário por nome ou e-mail…'),
            aoEscolher: (u, entrada) => compartilhar(u.email, entrada),
            aoEnterTexto: (texto, entrada) => { if (texto.includes('@')) compartilhar(texto, entrada); },
        });
        corpo.push(convite.caixa);
    } else {
        const form = raiz.querySelector('#form-sair');
        corpo.push(el('div', { class: 'd2-faixa projeto-faixa' }, D2UI.icone('info'),
            el('div', { class: 'd2-faixa-texto', texto: tx('projeto.share_membro', 'Você é membro deste projeto compartilhado.') }),
            form ? el('div', { class: 'd2-faixa-acoes' }, botao(tx('projeto.share_sair', 'Sair do projeto'), {
                icone: 'sair', tipo: 'perigo',
                aoClicar: async (ev) => {
                    const ok = await D2UI.confirmar({ titulo: tx('projeto.share_sair', 'Sair do projeto'), texto: tx('projeto.share_sair_texto', 'Você deixa de ver este projeto e as conversas dele. Para voltar, o dono precisa compartilhar de novo.'), perigo: true, rotuloOk: tx('projeto.share_sair', 'Sair do projeto') });
                    if (!ok) return;
                    carregando(ev.currentTarget || null);
                    // requestSubmit dispara o submit: a casca intercepta dentro da janela.
                    form.requestSubmit();
                },
            })) : null));
    }
    corpo.push(listaMembros);

    const paineis = [D2UI.painel({
        titulo: tx('projeto.share_titulo', 'Membros'), icone: 'usuarios', area: 'usuarios',
    }, el('p', { class: 'texto-mudo projeto-explica', texto: tx('projeto.share_sub', 'Membros têm acesso total: abrem o projeto na conta deles e veem todos os chats.') }), ...corpo).el];

    if (dados.dono) {
        const transferir = buscaUsuarios(base, {
            rotulo: tx('projeto.transf_quem', 'Novo dono'),
            placeholder: tx('projeto.transf_buscar', 'Buscar quem vai receber o projeto…'),
            aoEscolher: async (u, entrada) => {
                entrada.value = u.name || u.email;
                const codigo = String(Math.floor(10000 + Math.random() * 90000));
                const ok = await D2UI.confirmar({
                    titulo: tx('projeto.transf_titulo', 'Transferir titularidade'),
                    texto: tx('projeto.transf_confirma', 'Transferir este projeto para %s? Ação irreversível: o controle total passa para essa pessoa e você continua como membro.').replace('%s', (u.name || u.email) + ' (' + u.email + ')'),
                    perigo: true, palavra: codigo, rotuloOk: tx('projeto.transf_ok', 'Transferir'),
                });
                if (!ok) { entrada.value = ''; return; }
                try {
                    const d = await chamar(base + '/transfer', { metodo: 'POST', formulario: { user_id: u.id } });
                    if (!(d && d.ok)) throw new Error((d && d.error) || '');
                    D2UI.toast(tx('projeto.transf_feito', 'Projeto transferido para') + ' ' + ((d.new_owner && d.new_owner.email) || u.email) + '.', { tipo: 'ok' });
                    setTimeout(recarregar, 900);
                } catch (e) {
                    entrada.value = '';
                    D2UI.toast(msgErro(e, tx('projeto.transf_falha', 'Falha ao transferir.')), { tipo: 'erro' });
                }
            },
        });
        paineis.push(D2UI.painel({ titulo: tx('projeto.transf_titulo', 'Transferir titularidade'), icone: 'chave', area: 'projetos', tom: 'aviso' },
            el('p', { class: 'texto-mudo', texto: tx('projeto.transf_texto', 'Passa o controle total deste projeto para outro usuário. Ação irreversível. Você continua como membro.') }),
            transferir.caixa).el);
    }
    return el('div', { class: 'projeto-pilha' }, paineis);
}
