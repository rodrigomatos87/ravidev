// Montagem da casca do painel: menu lateral, barra do topo, palco de janelas, bandeja de
// minimizadas, rodapé, central de avisos, paleta de busca e modo celular. A sessão é a do
// PHP: o layout entrega a configuração em window.RAVI_CASCA e a tela pedida em #tela-inicial.
import { Eventos } from '../eventos.js';
import { D2UI } from '../d2ui.js';
import { Tema } from '../tema.js';
import { t, cfg, Idioma, IDIOMAS, formatar, Preferencias, Usuario, appNome, versao } from './contexto.js';
import { Palco, ePainel, normalizarUrl } from './palco.js';
import { Roteador } from './roteador.js';
import { Menu } from './menu.js';
import { Busca } from './busca.js';
import { Notificacoes } from './notificacoes.js';
import { Celular } from './celular.js';
import { h, ic } from './janela.js';

const raizHtml = document.documentElement;
const ICONE_TEMA = { auto: 'auto', claro: 'sol', escuro: 'lua' };
let casca = null;
let ultimaFalha = 0;
let tarefasNaFila = 0;
const cancelarCasca = [];

Tema.definirPersistencia((p) => Preferencias.salvar('tema', p).catch(() => {}));

// Erro sem tratamento vira aviso discreto (nunca pilha nem detalhe técnico na tela).
function errosGlobais() {
    const avisar = (motivo) => {
        if (motivo && motivo.name === 'AbortError') return;
        const agora = Date.now();
        if (agora - ultimaFalha < 4000) return;
        ultimaFalha = agora;
        D2UI.toast(t('casca.erro.inesperado'), { tipo: 'erro' });
    };
    window.addEventListener('unhandledrejection', (ev) => { avisar(ev.reason); });
    window.addEventListener('error', (ev) => { if (ev.error) avisar(ev.error); });
}

function aplicarPreferencias() {
    const p = Preferencias.obter();
    if (p.tema && p.tema !== Tema.preferencia()) Tema.aplicar(p.tema, { salvar: false });
}

function ligarEventosGlobais() {
    Eventos.ouvir('tema.trocado', () => { if (casca) atualizarBotaoTema(); });
    const mudouConexao = () => {
        const online = navigator.onLine !== false;
        if (online === !raizHtml.classList.contains('sem-conexao')) return;
        raizHtml.classList.toggle('sem-conexao', !online);
        atualizarRodape();
        if (online) D2UI.toast(t('casca.conexao.recuperada'), { tipo: 'ok' });
        else D2UI.toast(t('casca.conexao.perdida'), { tipo: 'aviso', titulo: t('casca.conexao.perdida_titulo') });
    };
    window.addEventListener('online', mudouConexao);
    window.addEventListener('offline', mudouConexao);
}

// Monta a casca inteira dentro de #casca.
async function montarCasca() {
    const el = document.getElementById('casca') || document.body.appendChild(h('div', { id: 'casca', class: 'casca' }));
    const menu = h('aside', { class: 'casca-menu d2-noite', id: 'casca-menu' });
    const veu = h('div', { class: 'casca-veu', hidden: '' });
    const topo = h('header', { class: 'casca-topo' });
    const palco = h('main', { class: 'palco', id: 'palco', tabindex: '-1' });
    const bandeja = h('div', { class: 'palco-bandeja', role: 'region' }, h('ul', { class: 'palco-bandeja-lista' }));
    const rodape = h('footer', { class: 'casca-rodape' });
    const pular = h('button', { type: 'button', class: 'casca-pular', onclick: () => palco.focus() });
    // A faixa de quem está vendo como outro usuário (vem pronta do layout) fica no alto da área principal.
    const faixa = document.querySelector('.casca-faixa');
    const principal = h('div', { class: 'casca-principal' }, faixa, topo, h('div', { class: 'casca-area' }, palco, bandeja), rodape);
    const barra = h('nav', { class: 'barra-inferior' });
    el.replaceChildren(pular, menu, veu, principal, barra);
    el.hidden = false;
    casca = { el, menu, veu, topo, palco, bandeja, rodape, pular, principal, barra };
    raizHtml.classList.add('casca-ativa');
    rotularCasca();
    Menu.montar(menu, { aoEscolher: () => Celular.fecharGaveta() });
    redesenharTopo();
    atualizarRodape();
    const inicial = telaInicial();
    const naInicial = inicial && ePainel(inicial.url);
    await Palco.iniciar(palco, bandeja, { inicial: naInicial ? inicial.janela : null });
    Celular.iniciar({ menu, veu, barra, principal, palco }, {
        aoBusca: () => Busca.abrir(),
        aoNotificacoes: () => Notificacoes.abrirPainel(),
    });
    Busca.ligar();
    registrarAcoes();
    registrarAtalhos();
    Notificacoes.iniciar();
    cancelarCasca.push(
        Eventos.ouvir('notificacoes.contagem', ({ naoLidas }) => Celular.atualizarBarra({ avisos: naoLidas })),
        Eventos.ouvir('palco.lista', () => atualizarRodape()),
    );
    await abrirInicio(naInicial ? null : inicial);
    Roteador.ligar();
}

// A tela que o PHP já desenhou (#tela-inicial) com os dados dela (RAVI_CASCA.tela).
function telaInicial() {
    const el = document.getElementById('tela-inicial');
    const dados = cfg.tela || null;
    if (!el || !dados) return null;
    const url = normalizarUrl(el.dataset.url || dados.url || location.pathname + location.search) || '/dashboard';
    return { url, janela: { dados, el } };
}

// Disposição salva, a tela pedida pelo endereço da página e o endereço com hash.
async function abrirInicio(inicial) {
    const destino = Roteador.capturar();
    const d = Preferencias.obter().disposicao;
    if (d && Array.isArray(d.janelas) && d.janelas.length) {
        try { await Palco.restaurar(d); } catch (e) { /* disposição antiga ou inválida: começa limpo */ }
    }
    let alvo = null;
    if (inicial && !ePainel(inicial.url)) {
        // A página pedida (ex.: /users) abre como janela, aproveitando o HTML que já veio.
        const j = await Palco.abrir(inicial.url, { inicial: inicial.janela, semHistorico: true });
        if (j && !j.fundo) alvo = j;
        else if (inicial.janela.el) inicial.janela.el.remove();
    }
    if (destino && destino.url && !ePainel(destino.url)) {
        try {
            const j = await Palco.abrir(destino.url, { semHistorico: true });
            if (j && !j.fundo) alvo = j;
        } catch (e) { /* endereço indisponível: o palco já avisou */ }
    }
    // A barra passa a mostrar o painel com a janela em foco no hash (recarregar volta ao mesmo lugar).
    const f = alvo || Palco.focada();
    history.replaceState(history.state, '', '/dashboard' + (f && !f.fundo ? f.endereco() : '#/'));
}

function rotularCasca() {
    if (!casca) return;
    casca.pular.textContent = t('casca.pular');
    casca.palco.setAttribute('aria-label', t('casca.palco.rotulo'));
    casca.bandeja.setAttribute('aria-label', t('casca.bandeja.rotulo'));
    casca.barra.setAttribute('aria-label', t('casca.barra.rotulo'));
    casca.rodape.setAttribute('aria-label', t('casca.rodape.rotulo'));
}

function botaoTopo(icone, rotulo, onclick, classe = '') {
    return h('button', {
        type: 'button', class: ('d2-iconbtn d2-iconbtn-fantasma casca-topo-btn ' + classe).trim(),
        'aria-label': rotulo, 'data-tooltip': rotulo, onclick,
    }, ic(icone));
}

function rotuloTema() {
    return t('casca.tema.rotulo', { tema: t('casca.tema.' + Tema.preferencia()) });
}

function primeiroNome(nome) {
    return String(nome || '').trim().split(/\s+/)[0] || '';
}

// Barra do topo: marca, busca universal, organizar, avisos, idioma, tema e perfil.
function redesenharTopo() {
    if (!casca) return;
    casca.topo.setAttribute('aria-label', t('casca.topo.rotulo'));
    const marca = h('button', { type: 'button', class: 'casca-topo-marca', 'aria-label': t('menu.ir_painel'), 'data-tooltip': t('menu.ir_painel'), onclick: () => Palco.mostrarPainel() },
        h('img', { class: 'casca-topo-marca-icone', src: '/assets/images/logo.svg', alt: '', width: 30, height: 30, decoding: 'async' }));
    const busca = h('button', {
        type: 'button', class: 'casca-busca', 'aria-label': t('casca.busca.rotulo'), 'aria-keyshortcuts': 'Control+K', onclick: () => Busca.abrir(),
    }, ic('busca', 'casca-busca-icone'), h('span', { class: 'casca-busca-texto', texto: t('casca.busca.indicacao') }),
    h('span', { class: 'casca-busca-atalho', 'aria-hidden': 'true' }, h('kbd', { class: 'd2-kbd', texto: formatar.tecla('ctrl') }), h('kbd', { class: 'd2-kbd', texto: formatar.tecla('k') })));
    const organizar = botaoTopo('grade', t('casca.organizar.rotulo'), (ev) => Palco.menuOrganizar(ev.currentTarget), 'casca-organizar');
    organizar.setAttribute('aria-haspopup', 'menu');
    const sino = botaoTopo('sino', t('casca.notificacoes.titulo'), null, 'casca-sino');
    Notificacoes.montar(sino);
    const idioma = h('button', {
        type: 'button', class: 'd2-iconbtn d2-iconbtn-fantasma casca-topo-btn casca-idioma', 'aria-haspopup': 'menu',
        'aria-label': t('casca.idioma.rotulo'), 'data-tooltip': t('casca.idioma.rotulo'), onclick: (ev) => menuIdiomas(ev.currentTarget),
    }, ic('idioma'), h('span', { class: 'casca-idioma-sigla', 'aria-hidden': 'true', texto: t('casca.idioma.sigla.' + Idioma.atual()) }));
    const tema = botaoTopo(ICONE_TEMA[Tema.preferencia()] || 'auto', rotuloTema(), () => Tema.aplicar(Tema.proximo()), 'casca-tema');
    const nome = Usuario.nome();
    const perfil = h('button', {
        type: 'button', class: 'casca-perfil', 'aria-haspopup': 'menu', 'aria-label': t('casca.perfil.rotulo', { nome }),
        'data-tooltip': t('casca.perfil.dica'), onclick: (ev) => menuPerfil(ev.currentTarget),
    }, D2UI.avatar(nome || '?', { tamanho: 'sm' }), h('span', { class: 'casca-perfil-nome', texto: primeiroNome(nome) }), ic('chevron-baixo', 'casca-perfil-seta'));
    casca.topo.replaceChildren(marca, busca, h('div', { class: 'casca-topo-acoes' },
        organizar, sino, idioma, tema, h('span', { class: 'casca-topo-sep', 'aria-hidden': 'true' }), perfil));
}

function atualizarBotaoTema() {
    const b = casca && casca.topo.querySelector('.casca-tema');
    if (!b) return;
    b.replaceChildren(ic(ICONE_TEMA[Tema.preferencia()] || 'auto'));
    b.setAttribute('aria-label', rotuloTema());
    b.setAttribute('data-tooltip', rotuloTema());
}

// Trocar o idioma recarrega a página (os textos do servidor vêm no idioma novo);
// o palco grava a disposição antes, pela guarda do idioma.
async function trocarIdioma(codigo) {
    if (codigo === Idioma.atual()) return;
    raizHtml.classList.add('idioma-trocando');
    try { await Idioma.trocar(codigo); } catch (e) {
        raizHtml.classList.remove('idioma-trocando');
        D2UI.toast(t('casca.erro.conexao'), { tipo: 'erro' });
    }
}

function menuIdiomas(alvo) {
    D2UI.menuContexto(alvo, [
        { grupo: t('casca.idioma.rotulo') },
        ...IDIOMAS.map((c) => ({ icone: c === Idioma.atual() ? 'check' : 'idioma', rotulo: t('casca.idioma.nome.' + c), fn: () => trocarIdioma(c) })),
    ]);
}

function menuPerfil(alvo) {
    const celular = window.matchMedia('(max-width: 759.98px)').matches;
    D2UI.menuContexto(alvo, [
        { grupo: Usuario.nome() },
        { icone: 'conta', rotulo: t('casca.perfil.minha_conta'), fn: () => Palco.abrir('/settings/profile') },
        celular ? { icone: 'idioma', rotulo: t('casca.idioma.rotulo'), fn: () => menuIdiomas(alvo) } : null,
        celular ? { icone: ICONE_TEMA[Tema.preferencia()] || 'auto', rotulo: rotuloTema(), fn: () => Tema.aplicar(Tema.proximo()) } : null,
        celular ? null : { icone: 'lista', rotulo: t('casca.atalhos.titulo'), atalho: '?', fn: () => mostrarAtalhos() },
        'separador',
        { icone: 'sair', rotulo: t('casca.perfil.sair'), perigo: true, fn: () => sair() },
    ].filter(Boolean));
}

// Rodapé: marca e versão, janelas abertas, projetos trabalhando, atalhos e conexão.
function atualizarRodape() {
    if (!casca) return;
    const v = versao();
    const projetos = (cfg.menu && Array.isArray(cfg.menu.projetos)) ? cfg.menu.projetos : [];
    tarefasNaFila = projetos.filter((p) => p && p.trabalhando).length;
    const fila = tarefasNaFila > 0 ? t('casca.rodape.fila', { n: tarefasNaFila }) : t('casca.rodape.fila_vazia');
    const online = !raizHtml.classList.contains('sem-conexao');
    const qtd = Palco.ativo() ? Palco.janelas().filter((j) => !j.fundo).length : 0;
    casca.rodape.replaceChildren(
        h('span', { class: 'casca-rodape-marca' }, h('img', { class: 'casca-rodape-marca-icone', src: '/assets/images/logo.svg', alt: '', width: 14, height: 14, decoding: 'async' }), h('span', { texto: appNome() }),
            v ? h('span', { class: 'casca-rodape-versao', texto: t('casca.rodape.versao', { versao: v }) }) : null),
        h('span', { class: 'casca-rodape-espaco', 'aria-hidden': 'true' }),
        h('span', { class: 'casca-rodape-janelas' }, ic('grade'), h('span', { class: 'casca-rodape-texto', texto: t('casca.rodape.janelas', { n: qtd }) })),
        h('span', { class: 'casca-rodape-fila' }, h('button', {
            type: 'button', class: 'casca-rodape-fila-btn' + (tarefasNaFila > 0 ? ' is-ativa' : ''),
            'aria-label': fila + '. ' + t('casca.rodape.fila_abrir'), 'data-tooltip': t('casca.rodape.fila_abrir'),
            onclick: () => Notificacoes.abrirPainel(),
        }, ic('relogio'), h('span', { class: 'casca-rodape-texto', texto: fila }))),
        h('span', { class: 'casca-rodape-atalho' }, h('kbd', { class: 'd2-kbd', texto: '?' }), h('span', { class: 'casca-rodape-texto', texto: t('casca.atalhos.titulo') })),
        h('span', { class: 'casca-rodape-conexao ' + (online ? 'is-online' : 'is-offline'), role: 'status' },
            h('span', { class: 'casca-rodape-ponto', 'aria-hidden': 'true' }), h('span', { texto: t(online ? 'casca.conexao.online' : 'casca.conexao.offline') })));
}

// Sair não pede confirmação: a disposição do palco já fica salva e volta no próximo acesso.
async function sair() {
    raizHtml.classList.add('casca-saindo');
    try { await Palco.gravarAgora(); } catch (e) { /* sai mesmo assim */ }
    const f = document.getElementById('form-sair');
    if (f) f.submit();
    else location.href = '/login';
}

// Ações da paleta de busca (Ctrl+K): tudo o que a casca faz sem abrir menu.
function registrarAcoes() {
    const temJanela = () => Palco.janelas().some((j) => !j.fundo && !j.minimizada);
    const acoes = [
        ['painel', 'painel', () => Palco.mostrarPainel(), 'alt+h'],
        ['conta', 'conta', () => Palco.abrir('/settings/profile')],
        ['notificacoes', 'sino', () => Notificacoes.abrirPainel(), 'alt+n'],
        ['organizar_quartos', 'grade', () => Palco.organizarQuartos(), null, () => Palco.janelas().filter((j) => !j.fundo && !j.minimizada).length > 1],
        ['organizar_cascata', 'duplicar', () => Palco.organizarCascata(), null, temJanela],
        ['minimizar_todas', 'minimizar', () => Palco.minimizarTodas(), null, temJanela],
        ['fechar_todas', 'fechar', () => Palco.confirmarFecharTodas(), null, () => Palco.janelas().some((j) => !j.fundo)],
        ['recolher_menu', 'menu', () => Menu.alternarRecolhido(), 'alt+m'],
        ['tema_claro', 'sol', () => Tema.aplicar('claro'), null, () => Tema.preferencia() !== 'claro'],
        ['tema_escuro', 'lua', () => Tema.aplicar('escuro'), null, () => Tema.preferencia() !== 'escuro'],
        ['tema_auto', 'auto', () => Tema.aplicar('auto'), null, () => Tema.preferencia() !== 'auto'],
        ...IDIOMAS.map((c) => ['idioma_' + c, 'idioma', () => trocarIdioma(c), null, () => Idioma.atual() !== c]),
        ['atalhos', 'lista', () => mostrarAtalhos(), '?'],
        ['sair', 'sair', () => sair()],
    ];
    for (const [id, icone, fn, atalho, visivel] of acoes) {
        const chave = 'casca.acao.' + id;
        cancelarCasca.push(Busca.registrarAcao({
            id, icone, chave, fn, atalho: atalho ? formatar.atalho(atalho) : null, visivel: visivel || null,
            palavras: Idioma.existe(chave + '.palavras') ? chave + '.palavras' : null,
        }));
    }
}

function registrarAtalhos() {
    const A = D2UI.atalhos;
    cancelarCasca.push(
        A.registrar('alt+j', () => Palco.alternar(), { descricao: 'casca.atalho.alternar' }),
        A.registrar('alt+shift+j', () => Palco.alternar(true), { descricao: 'casca.atalho.alternar_volta' }),
        A.registrar('alt+w', () => { const j = Palco.focada(); if (j && !j.fundo) j.fechar(); }, { descricao: 'casca.atalho.fechar_janela' }),
        A.registrar('alt+h', () => Palco.mostrarPainel(), { descricao: 'casca.atalho.painel' }),
        A.registrar('alt+m', () => Menu.alternarRecolhido(), { descricao: 'casca.atalho.menu' }),
        A.registrar('alt+n', () => Notificacoes.abrirPainel(), { descricao: 'casca.atalho.notificacoes' }),
        A.registrar('alt+b', () => Menu.focarBusca(), { descricao: 'casca.atalho.busca_menu' }),
        A.registrar('?', () => mostrarAtalhos(), { descricao: 'casca.atalho.lista' }),
    );
}

// Lista viva dos atalhos registrados (casca e telas), num modal do D2UI.
function mostrarAtalhos() {
    const porDescricao = new Map();
    for (const a of D2UI.atalhos.listar()) {
        if (!a.descricao) continue;
        const combos = porDescricao.get(a.descricao) || [];
        if (!combos.includes(a.combo)) combos.push(a.combo);
        porDescricao.set(a.descricao, combos);
    }
    const tecla = (p) => h('kbd', { class: 'd2-kbd', texto: formatar.tecla(p) });
    const linhas = [];
    for (const [descricao, combos] of porDescricao) {
        const teclas = [];
        combos.forEach((combo, i) => {
            if (i) teclas.push(h('span', { class: 'casca-atalho-ou', texto: t('casca.atalhos.ou') }));
            teclas.push(...combo.split('+').map(tecla));
        });
        linhas.push(h('li', { class: 'casca-atalho' }, h('span', { class: 'casca-atalho-texto', texto: t(descricao) }), h('span', { class: 'casca-atalho-teclas' }, ...teclas)));
    }
    D2UI.modal({ titulo: t('casca.atalhos.titulo'), largura: 520, classe: 'casca-atalhos-modal', corpo: h('ul', { class: 'casca-atalhos' }, ...linhas) });
}

// Arranque: devolve a promessa da casca montada (quem importa pode esperar).
export async function iniciar() {
    D2UI.configurar({ t, idioma: Idioma.atual(), sprite: cfg.sprite || '/assets/d2/icones/sprite.svg', hoje: () => formatar.hoje() });
    Tema.iniciar();
    aplicarPreferencias();
    errosGlobais();
    ligarEventosGlobais();
    // Flash sem tela inicial (a tela inicial mostra o próprio flash ao montar).
    if (!document.getElementById('tela-inicial')) for (const f of cfg.flash || []) D2UI.toast(f.texto, { tipo: f.tipo });
    await montarCasca();
    raizHtml.classList.add('casca-pronta');
}

export const App = { iniciar, mostrarAtalhos, sair };
export default App;
