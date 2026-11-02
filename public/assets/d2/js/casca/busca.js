// Busca universal (Ctrl+K e /): telas do menu, ações da casca, janelas abertas e
// registros do sistema (projetos e conversas). Tudo pelo teclado.
import { Eventos } from '../eventos.js';
import { D2UI } from '../d2ui.js';
import { t, formatar, chamar, Preferencias } from './contexto.js';
import { Palco } from './palco.js';
import { h, ic } from './janela.js';
import { Menu, normalizar } from './menu.js';

const CHAVE_RECENTES = 'ravidev.busca.recentes';
const MAX_RECENTES = 6;
const acoes = [];
let aberta = null;
let atalhosCancelar = [];

// Ação da paleta: { id, icone, chave, palavras?, fn, visivel?() }.
export function registrarAcao(acao) {
    const i = acoes.findIndex((a) => a.id === acao.id);
    if (i >= 0) acoes.splice(i, 1, acao);
    else acoes.push(acao);
    return () => { const k = acoes.indexOf(acao); if (k >= 0) acoes.splice(k, 1); };
}

function lerRecentes() {
    try { const v = JSON.parse(localStorage.getItem(CHAVE_RECENTES) || '[]'); return Array.isArray(v) ? v : []; } catch (e) { return []; }
}

function lembrar(id) {
    const v = [id, ...lerRecentes().filter((x) => x !== id)].slice(0, MAX_RECENTES);
    try { localStorage.setItem(CHAVE_RECENTES, JSON.stringify(v)); } catch (e) { /* armazenamento indisponível */ }
}

// Destaca o trecho buscado sem usar HTML: o texto entra por nós de texto.
function realcar(texto, termo) {
    const frag = document.createDocumentFragment();
    const n = normalizar(texto);
    const q = normalizar(termo);
    const i = q ? n.indexOf(q) : -1;
    if (i < 0 || n.length !== String(texto).length) {
        frag.append(String(texto));
        return frag;
    }
    frag.append(texto.slice(0, i), h('mark', { class: 'busca-marca', texto: texto.slice(i, i + q.length) }), texto.slice(i + q.length));
    return frag;
}

function pontuar(textos, termo) {
    const q = normalizar(termo);
    if (!q) return 1;
    const partes = q.split(/\s+/);
    const alvo = normalizar(textos.join(' '));
    if (!partes.every((p) => alvo.includes(p))) return 0;
    const titulo = normalizar(textos[0]);
    if (titulo.startsWith(q)) return 3;
    if (titulo.includes(q)) return 2;
    return 1;
}

function candidatosTelas(termo) {
    return Menu.itens().filter(Menu.visivel).map((i) => {
        const nome = i.rotulo || '';
        const grupo = i.grupoRotulo || '';
        return { id: 'tela:' + i.id, icone: i.icone, area: i.area || null, titulo: nome, dica: grupo, pontos: pontuar([nome, grupo, i.dono || ''], termo), fn: (op) => Menu.abrirItem(i, op) };
    }).filter((c) => c.pontos > 0).sort((a, b) => b.pontos - a.pontos);
}

function candidatosAcoes(termo) {
    return acoes.filter((a) => !a.visivel || a.visivel()).map((a) => {
        const nome = t(a.chave);
        const extra = a.palavras ? t(a.palavras) : '';
        return { id: 'acao:' + a.id, icone: a.icone, titulo: nome, dica: a.atalho || '', kbd: Boolean(a.atalho), pontos: pontuar([nome, extra], termo), fn: a.fn };
    }).filter((c) => c.pontos > 0).sort((a, b) => b.pontos - a.pontos);
}

function candidatosJanelas(termo) {
    return Palco.janelas().filter((j) => !j.fundo).map((j) => ({
        id: 'janela:' + j.id, icone: j.nomeIcone || 'lista', area: j.el ? j.el.getAttribute('data-area') : null, titulo: j.textoTitulo || '', dica: j.minimizada ? t('casca.busca.minimizada') : t('casca.busca.aberta'),
        pontos: pontuar([j.textoTitulo || '', j.textoSubtitulo || ''], termo), fn: () => Palco.focar(j), semLembrar: true,
    })).filter((c) => c.pontos > 0);
}

function deRecentes() {
    const telas = candidatosTelas('');
    const todas = [...telas, ...candidatosAcoes('')];
    return lerRecentes().map((id) => todas.find((c) => c.id === id)).filter(Boolean);
}

function podeBuscarRegistros(termo) {
    return termo.length >= 2;
}

// Abre a paleta. texto: começa já com esse termo.
export function abrir(texto = '') {
    if (aberta) { aberta.campo.focus(); aberta.campo.select(); return aberta; }
    const anterior = document.activeElement;
    let itensAtuais = [];
    let ativoI = 0;
    let registros = { termo: '', estado: 'ocioso', itens: [], tipo: '' };
    let espera = 0;
    let seq = 0;
    const idLista = 'busca-lista';
    const campo = h('input', {
        type: 'text', class: 'busca-campo', role: 'combobox', 'aria-expanded': 'true', 'aria-controls': idLista,
        'aria-autocomplete': 'list', 'aria-label': t('casca.busca.rotulo'), placeholder: t('casca.busca.indicacao'),
        autocomplete: 'off', spellcheck: 'false', enterkeyhint: 'go',
    });
    const lista = h('div', { class: 'd2-paleta-lista', id: idLista, role: 'listbox', 'aria-label': t('casca.busca.resultados') });
    const vivo = h('div', { class: 'd2-oculto-leitor', 'aria-live': 'polite' });
    const rodape = h('div', { class: 'd2-paleta-rodape' },
        h('span', {}, h('kbd', { class: 'd2-kbd', texto: '↑' }), h('kbd', { class: 'd2-kbd', texto: '↓' }), t('casca.busca.navegar')),
        h('span', {}, h('kbd', { class: 'd2-kbd', texto: formatar.tecla('enter') }), t('casca.busca.abrir')),
        h('span', { class: 'busca-dica-lado' }, h('kbd', { class: 'd2-kbd', texto: formatar.tecla('shift') }), h('kbd', { class: 'd2-kbd', texto: formatar.tecla('enter') }), t('casca.busca.abrir_lado')),
        h('span', {}, h('kbd', { class: 'd2-kbd', texto: formatar.tecla('esc') }), t('casca.busca.fechar')));
    const caixa = h('div', { class: 'd2-paleta-caixa busca-caixa', role: 'dialog', 'aria-modal': 'true', 'aria-label': t('casca.busca.titulo') },
        h('div', { class: 'd2-paleta-busca' }, ic('busca'), campo,
            h('button', { type: 'button', class: 'd2-iconbtn d2-iconbtn-fantasma busca-fechar', 'aria-label': t('casca.busca.fechar'), 'data-tooltip': t('casca.busca.fechar'), onclick: () => fechar() }, ic('fechar'))),
        lista, rodape, vivo);
    const veu = h('div', { class: 'd2-paleta busca-paleta', onpointerdown: (ev) => { if (ev.target === veu) fechar(); } }, caixa);

    function grupo(chave, extra) {
        return h('div', { class: 'd2-paleta-grupo', role: 'presentation' }, t(chave), extra ? h('span', { class: 'busca-grupo-extra', texto: ' · ' + extra }) : null);
    }

    function opcao(c, i, termo) {
        const el = h('div', {
            class: 'd2-paleta-item busca-item', role: 'option', id: 'busca-op-' + i, 'aria-selected': i === ativoI ? 'true' : 'false',
            onpointermove: () => { if (ativoI !== i) marcar(i); },
            onclick: (ev) => escolher(c, ev.shiftKey),
        },
            // Telas e janelas levam a pastilha da área (cores.md, 3.8); o resto, o ícone solto.
            c.area ? h('span', { class: 'd2-pastilha busca-item-pastilha', 'data-area': c.area, 'aria-hidden': 'true' }, ic(c.icone || 'lista')) : ic(c.icone || 'lista'),
            h('span', { class: 'd2-paleta-item-texto' }, realcar(c.titulo, termo),
                c.sub ? h('span', { class: 'busca-item-sub', texto: c.sub }) : null),
            c.selo || null,
            c.dica ? (c.kbd ? h('kbd', { class: 'd2-kbd', texto: c.dica }) : h('span', { class: 'd2-paleta-item-dica', texto: c.dica })) : null);
        return el;
    }

    function desenhar() {
        const termo = campo.value.trim();
        const grupos = [];
        if (!termo) {
            const rec = deRecentes();
            if (rec.length) grupos.push(['casca.busca.grupo.recentes', rec]);
            const favs = (Preferencias.obter().favoritos || []).map((id) => Menu.item(id)).filter((i) => i && Menu.visivel(i))
                .map((i) => candidatosTelas('').find((c) => c.id === 'tela:' + i.id)).filter((c) => c && !rec.includes(c));
            if (favs.length) grupos.push(['casca.busca.grupo.favoritos', favs]);
            const jan = candidatosJanelas('');
            if (jan.length) grupos.push(['casca.busca.grupo.janelas', jan]);
            // Sem termo, as ações do sistema só aparecem pelas recentes (a lista completa é longa).
            grupos.push(['casca.busca.grupo.acoes', candidatosAcoes('').slice(0, 4).filter((c) => !rec.some((r) => r.id === c.id))]);
        } else {
            const regs = registros.termo === termo ? registros : null;
            const telas = candidatosTelas(termo).slice(0, 8);
            const jan = candidatosJanelas(termo);
            const acs = candidatosAcoes(termo).sort((a, b) => b.pontos - a.pontos).slice(0, 8);
            if (regs && regs.itens.length) grupos.push(['casca.busca.grupo.registros', regs.itens]);
            // Ação que bate pelo nome vem antes de tela que só bate por palavra associada.
            const acoesPrimeiro = acs.length && (!telas.length || acs[0].pontos > telas[0].pontos);
            if (acoesPrimeiro) grupos.push(['casca.busca.grupo.acoes', acs]);
            if (telas.length) grupos.push(['casca.busca.grupo.telas', telas]);
            if (jan.length) grupos.push(['casca.busca.grupo.janelas', jan]);
            if (acs.length && !acoesPrimeiro) grupos.push(['casca.busca.grupo.acoes', acs]);
        }
        itensAtuais = grupos.flatMap((g) => g[1]);
        if (ativoI >= itensAtuais.length) ativoI = Math.max(0, itensAtuais.length - 1);
        lista.textContent = '';
        let n = 0;
        for (const [chave, cs, extra] of grupos) {
            if (!cs.length) continue;
            lista.append(grupo(chave, extra), ...cs.map((c) => opcao(c, n++, termo)));
        }
        const buscando = termo && podeBuscarRegistros(termo) && (registros.termo !== termo || registros.estado === 'carregando');
        if (buscando) lista.append(h('div', { class: 'busca-estado', role: 'presentation' }, h('span', { class: 'd2-spin' }), h('span', { texto: t('casca.busca.buscando') })));
        else if (termo && registros.termo === termo && registros.estado === 'erro') {
            lista.append(h('div', { class: 'busca-estado is-erro', role: 'presentation' }, ic('alerta'), h('span', { texto: t('casca.busca.erro_registros') })));
        }
        if (!itensAtuais.length && !buscando) {
            lista.append(h('div', { class: 'busca-vazio', role: 'presentation' }, ic('busca'),
                h('strong', { texto: t('casca.busca.vazio_titulo') }), h('span', { texto: t('casca.busca.vazio_texto', { termo }) })));
        }
        vivo.textContent = termo ? t('casca.busca.qtd', { n: itensAtuais.length }) : '';
        marcar(ativoI, false);
    }

    function marcar(i, rolar = true) {
        ativoI = i;
        const ops = lista.querySelectorAll('[role="option"]');
        ops.forEach((o, k) => o.setAttribute('aria-selected', k === i ? 'true' : 'false'));
        const alvo = ops[i];
        if (alvo) {
            campo.setAttribute('aria-activedescendant', alvo.id);
            if (rolar) alvo.scrollIntoView({ block: 'nearest' });
        } else campo.removeAttribute('aria-activedescendant');
    }

    function escolher(c, aoLado = false) {
        if (!c) return;
        if (!c.semLembrar) lembrar(c.id);
        fechar(false);
        try { c.fn(aoLado ? { aoLado: true } : {}); } catch (e) { setTimeout(() => { throw e; }); }
    }

    async function buscarRegistros() {
        const termo = campo.value.trim();
        if (!podeBuscarRegistros(termo)) return;
        const minha = ++seq;
        registros = { termo, estado: 'carregando', itens: [], tipo: '' };
        try {
            const r = await chamar('/dashboard/search?q=' + encodeURIComponent(termo));
            if (minha !== seq || !aberta) return;
            const projetos = (r && Array.isArray(r.projects) ? r.projects : []).map((x) => ({
                id: 'projeto:' + x.id, icone: 'documento', titulo: String(x.name || ''), sub: t('casca.busca.tipo.projeto'), semLembrar: true,
                fn: (op) => (x.url ? Palco.abrir(x.url, op) : null),
            }));
            const conversas = (r && Array.isArray(r.conversations) ? r.conversations : []).map((x) => ({
                id: 'conversa:' + x.id, icone: 'lista', titulo: String(x.title || ''), sub: [x.project_name, t('casca.busca.tipo.conversa')].filter(Boolean).join(' · '), semLembrar: true,
                fn: (op) => (x.url ? Palco.abrir(x.url, op) : null),
            }));
            const itens = [...projetos, ...conversas].slice(0, 10);
            registros = { termo, estado: 'pronto', itens, tipo: '' };
        } catch (e) {
            if (minha !== seq || !aberta) return;
            registros = { termo, estado: 'erro', itens: [], tipo: '' };
        }
        desenhar();
    }

    campo.addEventListener('input', () => {
        ativoI = 0;
        clearTimeout(espera);
        desenhar();
        if (podeBuscarRegistros(campo.value.trim())) espera = setTimeout(buscarRegistros, 220);
    });
    campo.addEventListener('keydown', (ev) => {
        const n = itensAtuais.length;
        if (ev.key === 'ArrowDown') { ev.preventDefault(); if (n) marcar((ativoI + 1) % n); }
        else if (ev.key === 'ArrowUp') { ev.preventDefault(); if (n) marcar((ativoI - 1 + n) % n); }
        else if (ev.key === 'PageDown') { ev.preventDefault(); if (n) marcar(Math.min(n - 1, ativoI + 6)); }
        else if (ev.key === 'PageUp') { ev.preventDefault(); if (n) marcar(Math.max(0, ativoI - 6)); }
        else if (ev.key === 'Enter') { ev.preventDefault(); escolher(itensAtuais[ativoI], ev.shiftKey); }
        else if (ev.key === 'Escape') { ev.preventDefault(); ev.stopPropagation(); if (campo.value) { campo.value = ''; campo.dispatchEvent(new Event('input')); } else fechar(); }
        else if (ev.key === 'Tab') ev.preventDefault();
    });

    function fechar(devolverFoco = true) {
        if (!aberta) return;
        aberta = null;
        clearTimeout(espera);
        seq++;
        document.removeEventListener('keydown', escCaptura, true);
        veu.classList.add('is-saindo');
        setTimeout(() => veu.remove(), 160);
        if (devolverFoco && anterior && anterior.isConnected) anterior.focus({ preventScroll: true });
        Eventos.emitir('busca.fechada', {});
    }

    // A paleta é a camada de cima: Esc é dela antes de chegar às janelas.
    function escCaptura(ev) {
        if (ev.key === 'Escape' && !caixa.contains(ev.target)) { ev.preventDefault(); ev.stopPropagation(); fechar(); }
    }
    document.addEventListener('keydown', escCaptura, true);

    document.body.append(veu);
    aberta = { campo, fechar, el: veu };
    campo.value = texto;
    desenhar();
    if (texto) campo.dispatchEvent(new Event('input'));
    requestAnimationFrame(() => campo.focus());
    Eventos.emitir('busca.aberta', {});
    return aberta;
}

export function fechar() {
    if (aberta) aberta.fechar();
}

// Atalhos globais: Ctrl+K (também dentro de campos) e / (fora de campos).
export function ligar() {
    desligar();
    atalhosCancelar = [
        D2UI.atalhos.registrar('ctrl+k', () => (aberta ? fechar() : abrir()), { emCampo: true, descricao: 'casca.busca.titulo' }),
        D2UI.atalhos.registrar('/', () => { if (!aberta) abrir(); }, { descricao: 'casca.busca.titulo' }),
    ];
}

export function desligar() {
    atalhosCancelar.forEach((f) => f());
    atalhosCancelar = [];
    fechar();
}

export const Busca = { abrir, fechar, ligar, desligar, registrarAcao, aberta: () => Boolean(aberta) };
export default Busca;
