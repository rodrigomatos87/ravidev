// Janela do palco: cabeçalho com ações, corpo rolável, barra de ferramentas,
// rodapé, faixa de alerta e véu de ocupado. Arrasta por transform e só grava
// a posição ao soltar; redimensiona por todas as bordas respeitando o mínimo.
import { Eventos } from '../eventos.js';
import { D2UI } from '../d2ui.js';
import { t, formatar } from './contexto.js';

// Elementos sem HTML: atributos, texto (textContent) e filhos.
export function h(tag, attrs, ...filhos) {
    const e = document.createElement(tag);
    if (attrs) {
        for (const [k, v] of Object.entries(attrs)) {
            if (v === null || v === undefined || v === false) continue;
            if (k === 'class') e.className = v;
            else if (k === 'texto') e.textContent = v;
            else if (k === 'dados') Object.assign(e.dataset, v);
            else if (k.startsWith('on') && typeof v === 'function') e.addEventListener(k.slice(2), v);
            else if (v === true) e.setAttribute(k, '');
            else e.setAttribute(k, String(v));
        }
    }
    for (const f of filhos.flat(Infinity)) {
        if (f === null || f === undefined || f === false) continue;
        e.append(f instanceof Node ? f : String(f));
    }
    return e;
}

export const ic = (nome, classe) => D2UI.icone(nome, classe);

export function botaoIcone(icone, rotulo, onclick, classe = '') {
    return h('button', {
        type: 'button', class: ('d2-iconbtn d2-iconbtn-fantasma ' + classe).trim(),
        'aria-label': rotulo, 'data-tooltip': rotulo, onclick,
    }, ic(icone));
}

// Rótulo (aria-label e dica) por chave de idioma: a chave fica guardada no botão para
// que a troca de idioma refaça o texto sem remontar a janela.
export function rotularBotao(btn, chave) {
    const r = t(chave);
    btn.dataset.rotuloChave = chave;
    btn.setAttribute('aria-label', r);
    btn.setAttribute('data-tooltip', r);
    return btn;
}

const botaoChave = (icone, chave, onclick, classe) => rotularBotao(botaoIcone(icone, t(chave), onclick, classe), chave);

// Some com a dica flutuante aberta (o D2UI esconde a dica quando o ponteiro deixa o
// documento). Usado quando a janela muda de estado sob o ponteiro parado: o botão
// troca de rótulo ou de lugar e a dica antiga ficaria presa na tela.
export function esconderDicas() {
    try {
        document.documentElement.dispatchEvent(new PointerEvent('pointerleave', { pointerType: 'mouse' }));
    } catch (e) { /* navegador sem PointerEvent: a dica some no próximo movimento */ }
}

// Área do sistema da janela (cor do d2): a definição da tela traz a área quando já sabe;
// senão o menu registra quem resolve pelo endereço, para não haver importação circular.
let resolverArea = () => null;
export function registrarArea(fn) { if (typeof fn === 'function') resolverArea = fn; }
export function areaDe(modulo, params) {
    try { return resolverArea(modulo, params || {}) || null; } catch (e) { return null; }
}

export const celular = () => window.matchMedia('(max-width: 759.98px)').matches;
export const movimentoReduzido = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;

const BORDAS = ['n', 's', 'e', 'w', 'ne', 'nw', 'se', 'sw'];
const LARGURA_COMPACTA = 640;
let contador = 0;

export class Janela {
    constructor(palco, { modulo, tela, params = {}, def = {}, fundo = false } = {}) {
        this.palco = palco;
        this.id = fundo ? 'fundo' : 'j' + (++contador);
        this.modulo = modulo;
        this.tela = tela;
        this.params = { ...params };
        this.def = def;
        this.fundo = fundo;
        this.fixa = false;
        this.minimizada = false;
        this.maximizada = false;
        this.zona = null;
        this.zonaAntes = null;
        this.ret = { x: 0, y: 0, largura: def.largura || 760, altura: def.altura || 560 };
        this.retAntes = null;
        this.minimo = { largura: def.minLargura || 320, altura: def.minAltura || 240 };
        this.textoTitulo = def.titulo ? t(def.titulo) : '';
        // Título vindo da definição (traduzido): a troca de idioma o refaz mesmo minimizada.
        this.tituloDaDef = this.textoTitulo;
        this.textoSubtitulo = '';
        this.nomeIcone = def.icone || 'grade';
        this.limpezas = new Set();
        this.guardas = new Set();
        this.ouvintesTamanho = new Set();
        this.montagem = 0;
        this.fechada = false;
        this.construir();
    }

    construir() {
        if (this.fundo) {
            this.corpo = h('div', { class: 'janela-corpo palco-fundo-corpo' });
            this.areaToolbar = h('div', { class: 'janela-toolbar', hidden: true });
            this.areaAlerta = h('div', { class: 'janela-alerta', hidden: true });
            this.areaRodape = h('div', { class: 'janela-rodape', hidden: true });
            this.el = h('section', { class: 'palco-fundo', 'data-janela': this.id, 'data-area': 'painel', 'aria-label': this.textoTitulo || t('casca.painel') },
                this.areaAlerta, this.areaToolbar, this.corpo, this.areaRodape);
            this.observarTamanho();
            return;
        }
        const idTitulo = this.id + '-titulo';
        this.elIcone = h('span', { class: 'janela-icone d2-pastilha', 'aria-hidden': 'true' }, ic(this.nomeIcone));
        this.elTitulo = h('h2', { class: 'janela-titulo', id: idTitulo, texto: this.textoTitulo });
        this.elSubtitulo = h('p', { class: 'janela-subtitulo', hidden: true });
        this.btnFixar = botaoChave('fixar', 'casca.janela.fixar', () => this.alternarFixa(), 'janela-acao-extra janela-btn-fixar');
        this.btnFixar.setAttribute('aria-pressed', 'false');
        const btnAba = botaoChave('externo', 'casca.janela.abrir_aba', () => this.abrirEmAba(), 'janela-acao-extra');
        const btnMais = botaoChave('reticencias', 'casca.janela.mais_acoes', (ev) => this.menuAcoes(ev.currentTarget), 'janela-acao-mais');
        const btnMin = botaoChave('minimizar', 'casca.janela.minimizar', () => this.palco.minimizar(this));
        this.btnMax = botaoChave('maximizar', 'casca.janela.maximizar', () => this.palco.alternarMaximizar(this), 'janela-btn-max');
        const btnFechar = botaoChave('fechar', 'casca.janela.fechar', () => this.fechar(), 'janela-btn-fechar');
        this.cab = h('header', { class: 'janela-cab' },
            this.elIcone,
            h('div', { class: 'janela-titulos' }, this.elTitulo, this.elSubtitulo),
            h('div', { class: 'janela-acoes' }, this.btnFixar, btnAba, btnMais,
                h('span', { class: 'janela-acoes-sep', 'aria-hidden': 'true' }), btnMin, this.btnMax, btnFechar));
        this.areaAlerta = h('div', { class: 'janela-alerta', hidden: true });
        this.areaToolbar = h('div', { class: 'janela-toolbar', hidden: true });
        this.corpo = h('div', { class: 'janela-corpo', tabindex: '-1' });
        this.areaRodape = h('footer', { class: 'janela-rodape', hidden: true });
        this.veu = h('div', { class: 'janela-ocupado', hidden: true, 'aria-hidden': 'true' },
            h('span', { class: 'janela-ocupado-giro' }, h('span', { class: 'd2-spin d2-spin-lg' })));
        const bordas = BORDAS.map((b) => h('div', { class: 'janela-borda janela-borda-' + b, dados: { borda: b }, 'aria-hidden': 'true' }));
        this.el = h('section', {
            class: 'janela', 'data-janela': this.id, 'data-area': this.def.area || areaDe(this.modulo, this.params), role: 'dialog', 'aria-modal': 'false',
            'aria-labelledby': idTitulo, tabindex: '-1',
        }, this.cab, this.areaAlerta, this.areaToolbar, this.corpo, this.areaRodape, this.veu, bordas);
        this.el.janela = this;
        this.ligarArrasto();
        this.ligarBordas();
        this.ligarTeclado();
        this.cab.addEventListener('contextmenu', (ev) => {
            if (ev.target.closest('button')) return;
            this.menuAcoes(ev);
        });
        this.el.addEventListener('pointerdown', () => this.palco.focar(this), true);
        this.el.addEventListener('focusin', () => { if (this.palco.focada() !== this && !this.palco.focoAlheio()) this.palco.focar(this, { semRolar: true }); });
        this.observarTamanho();
    }

    // API usada pelos módulos (contrato, seção 7).
    titulo(texto) {
        this.chaveTitulo = null;
        this.textoTitulo = texto === undefined || texto === null ? '' : String(texto);
        if (this.elTitulo) this.elTitulo.textContent = this.textoTitulo;
        else if (this.fundo) this.el.setAttribute('aria-label', this.textoTitulo || t('casca.painel'));
        this.palco.janelaMudou(this);
        return this;
    }

    // Título a partir de uma chave de idioma: a troca de idioma o refaz mesmo com a
    // janela minimizada (sem remontar), e a lista de janelas nunca fica no idioma antigo.
    tituloPorChave(chave, vars) {
        this.titulo(t(chave, vars));
        this.chaveTitulo = { chave, vars, texto: this.textoTitulo };
        return this;
    }

    // Refaz o título no idioma atual: o da chave dada pelo módulo ou, sem forçar, o da
    // definição só se o módulo não o trocou.
    retraduzirTitulo(forcar = false) {
        if (this.fundo) return;
        const k = this.chaveTitulo;
        if (!forcar && k && this.textoTitulo === k.texto) {
            this.tituloPorChave(k.chave, k.vars);
            return;
        }
        if (!this.def.titulo) return;
        if (!forcar && this.textoTitulo !== this.tituloDaDef) return;
        this.titulo(t(this.def.titulo));
        this.tituloDaDef = this.textoTitulo;
    }

    // Refaz no idioma atual os rótulos e dicas dos controles da própria janela (barra de
    // título e faixas de alerta); o conteúdo do módulo é refeito pela remontagem.
    retraduzirControles() {
        for (const area of [this.cab, this.areaAlerta]) {
            if (!area) continue;
            for (const btn of area.querySelectorAll('[data-rotulo-chave]')) rotularBotao(btn, btn.dataset.rotuloChave);
        }
    }

    subtitulo(texto) {
        this.textoSubtitulo = texto === undefined || texto === null ? '' : String(texto);
        if (this.elSubtitulo) {
            this.elSubtitulo.textContent = this.textoSubtitulo;
            this.elSubtitulo.hidden = !this.textoSubtitulo;
        }
        this.palco.janelaMudou(this);
        return this;
    }

    icone(nome) {
        if (!nome) return this;
        this.nomeIcone = String(nome);
        if (this.elIcone) this.elIcone.replaceChildren(ic(this.nomeIcone));
        this.palco.janelaMudou(this);
        return this;
    }

    toolbar(el) {
        this.areaToolbar.replaceChildren();
        if (el instanceof Node) this.areaToolbar.append(el);
        this.areaToolbar.hidden = !(el instanceof Node);
        return this;
    }

    // Rodapé fixo na base da janela; no celular, base do polegar (acima da barra inferior).
    // opcoes.soCelular: o rodapé só aparece quando a janela é folha de celular.
    rodape(el, opcoes = {}) {
        this.areaRodape.replaceChildren();
        if (el instanceof Node) this.areaRodape.append(el);
        this.areaRodape.hidden = !(el instanceof Node);
        this.areaRodape.classList.toggle('is-so-celular', Boolean(opcoes && opcoes.soCelular));
        return this;
    }

    ocupado(sim) {
        const v = Boolean(sim);
        this.el.toggleAttribute('aria-busy', v);
        if (this.veu) {
            this.veu.hidden = !v;
            this.el.classList.toggle('is-ocupada', v);
        } else {
            this.el.classList.toggle('is-ocupada', v);
        }
        return this;
    }

    // Faixa de alerta no topo da janela: chave de idioma ou texto pronto.
    alerta(chaveOuTexto, tipo = 'info') {
        this.areaAlerta.replaceChildren();
        if (!chaveOuTexto) {
            this.areaAlerta.hidden = true;
            return { fechar: () => {} };
        }
        const tp = ['ok', 'aviso', 'erro', 'info'].includes(tipo) ? tipo : 'info';
        const icones = { ok: 'check', aviso: 'alerta', erro: 'erro', info: 'info' };
        const texto = /^[a-z0-9_]+(\.[a-z0-9_-]+)+$/.test(chaveOuTexto) ? t(chaveOuTexto) : String(chaveOuTexto);
        const fechar = () => { faixa.remove(); this.areaAlerta.hidden = !this.areaAlerta.children.length; };
        const faixa = h('div', { class: 'd2-faixa d2-faixa-' + tp + ' janela-faixa', role: tp === 'erro' ? 'alert' : 'status' },
            ic(icones[tp]),
            h('p', { class: 'd2-faixa-texto', texto }),
            botaoChave('fechar', 'casca.janela.fechar_alerta', fechar, 'd2-iconbtn-sm janela-faixa-x'));
        this.areaAlerta.append(faixa);
        this.areaAlerta.hidden = false;
        return { el: faixa, fechar };
    }

    aoFechar(fn) {
        if (typeof fn !== 'function') return () => {};
        this.limpezas.add(fn);
        return () => this.limpezas.delete(fn);
    }

    // Guarda opcional: devolver false (ou Promise de false) cancela o fechamento.
    // Recebe { motivo }: 'fechar' (a janela vai fechar) ou 'idioma' (a tela vai ser
    // remontada no novo idioma), para o módulo escolher o texto da confirmação.
    antesDeFechar(fn) {
        if (typeof fn !== 'function') return () => {};
        this.guardas.add(fn);
        return () => this.guardas.delete(fn);
    }

    aoRedimensionar(fn) {
        if (typeof fn !== 'function') return () => {};
        this.ouvintesTamanho.add(fn);
        return () => this.ouvintesTamanho.delete(fn);
    }

    // Tamanho de layout (offsetWidth ignora o scale da animação de maximizar e restaurar).
    largura() {
        return Math.round(this.el.offsetWidth || this.ret.largura);
    }

    altura() {
        return Math.round(this.el.offsetHeight || this.ret.altura);
    }

    compacta() {
        return celular() || this.largura() < LARGURA_COMPACTA;
    }

    focar() {
        this.palco.focar(this);
        return this;
    }

    async fechar(opcoes = {}) {
        if (this.fechada || this.fundo) return false;
        if (!opcoes.forcar) {
            for (const g of [...this.guardas]) {
                try {
                    if ((await g({ motivo: opcoes.motivo || 'fechar' })) === false) return false;
                } catch (e) { return false; }
            }
        }
        this.palco.removerJanela(this, opcoes);
        return true;
    }

    // Roda as limpezas registradas (fechar e remontar).
    limpar() {
        for (const fn of [...this.limpezas]) {
            try { fn(); } catch (e) { setTimeout(() => { throw e; }); }
        }
        this.limpezas.clear();
        this.guardas.clear();
        this.ouvintesTamanho.clear();
    }

    identidade() {
        const f = this.def && typeof this.def.identidade === 'function' ? this.def.identidade : null;
        const v = f ? f(this.params) : this.params.id;
        return v === undefined || v === null ? '' : String(v);
    }

    chave() {
        return this.modulo + '/' + this.tela + '/' + this.identidade();
    }

    endereco(extras = {}) {
        return this.palco.enderecoDe(this, extras);
    }

    // Abre a tela no endereço real dela (página inteira, numa aba nova do navegador).
    abrirEmAba() {
        const url = location.origin + (this.params.url || '/dashboard');
        const nova = window.open(url, '_blank', 'noopener');
        if (nova) nova.opener = null;
    }

    alternarFixa(valor) {
        this.fixa = valor === undefined ? !this.fixa : Boolean(valor);
        this.el.classList.toggle('is-fixa', this.fixa);
        if (this.btnFixar) {
            this.btnFixar.setAttribute('aria-pressed', String(this.fixa));
            rotularBotao(this.btnFixar, this.fixa ? 'casca.janela.desafixar' : 'casca.janela.fixar');
        }
        this.palco.reempilhar();
        this.palco.agendarSalvar();
    }

    menuAcoes(alvo) {
        const p = this.palco;
        const outras = p.janelas().filter((j) => j !== this && !j.fechada);
        // No celular a janela é uma folha em tela cheia: arranjo, encaixe e fixar não se aplicam.
        if (celular()) {
            D2UI.menuContexto(alvo, [
                { icone: 'externo', rotulo: t('casca.janela.abrir_aba'), fn: () => this.abrirEmAba() },
                'separador',
                outras.length ? { icone: 'fechar', rotulo: t('casca.janela.fechar_outras'), fn: () => p.fecharOutras(this) } : null,
                { icone: 'fechar', rotulo: t('casca.janela.fechar'), perigo: true, fn: () => this.fechar() },
            ]);
            return;
        }
        D2UI.menuContexto(alvo, [
            { icone: this.maximizada ? 'restaurar' : 'maximizar', rotulo: t(this.maximizada ? 'casca.janela.restaurar' : 'casca.janela.maximizar'), fn: () => p.alternarMaximizar(this) },
            { icone: 'minimizar', rotulo: t('casca.janela.minimizar'), fn: () => p.minimizar(this) },
            { grupo: t('casca.janela.encaixar') },
            { icone: 'lado-a-lado', rotulo: t('casca.janela.encaixe.esquerda'), fn: () => p.encaixar(this, 'esquerda') },
            { icone: 'lado-a-lado', rotulo: t('casca.janela.encaixe.direita'), fn: () => p.encaixar(this, 'direita') },
            { icone: 'grade', rotulo: t('casca.janela.encaixe.quartos'), fn: () => p.organizarQuartos() },
            outras.length ? { icone: 'colunas', rotulo: t('casca.janela.lado_a_lado'), fn: () => p.ladoALado(outras[outras.length - 1], this) } : null,
            'separador',
            { icone: this.fixa ? 'estrela-cheia' : 'fixar', rotulo: t(this.fixa ? 'casca.janela.desafixar' : 'casca.janela.fixar'), fn: () => this.alternarFixa() },
            { icone: 'externo', rotulo: t('casca.janela.abrir_aba'), fn: () => this.abrirEmAba() },
            'separador',
            outras.length ? { icone: 'fechar', rotulo: t('casca.janela.fechar_outras'), fn: () => p.fecharOutras(this) } : null,
            { icone: 'fechar', rotulo: t('casca.janela.fechar'), atalho: formatar.tecla('esc'), perigo: true, fn: () => this.fechar() },
        ]);
    }

    // Monta o conteúdo da tela. O esqueleto fica numa camada própria por cima
    // do corpo e sai quando a tela põe conteúdo ou termina de montar.
    async montar() {
        const n = ++this.montagem;
        this.corpo.replaceChildren();
        this.areaToolbar.replaceChildren();
        this.areaToolbar.hidden = true;
        this.areaRodape.replaceChildren();
        this.areaRodape.hidden = true;
        this.areaAlerta.replaceChildren();
        this.areaAlerta.hidden = true;
        const esqueleto = h('div', { class: 'janela-esqueleto', 'aria-hidden': 'true' }, D2UI.esqueleto(7));
        this.corpo.after(esqueleto);
        this.el.classList.add('is-carregando');
        let removido = false;
        const tirar = () => {
            if (removido) return;
            removido = true;
            observador.disconnect();
            esqueleto.classList.add('is-saindo');
            setTimeout(() => esqueleto.remove(), 160);
            this.el.classList.remove('is-carregando');
        };
        const observador = new MutationObserver(() => { if (this.corpo.childNodes.length) tirar(); });
        observador.observe(this.corpo, { childList: true });
        try {
            if (typeof this.def.montar !== 'function') throw new Error('tela sem montar');
            await this.def.montar(this, this.params);
        } catch (erro) {
            if (n === this.montagem && !this.fechada) {
                this.ocupado(false);
                this.corpo.replaceChildren(D2UI.vazio({
                    chave: 'casca.janela.erro_montar', icone: 'erro',
                    acao: { rotulo: t('casca.tentar_novamente'), icone: 'atualizar', fn: () => this.remontar() },
                }));
                Eventos.emitir('janela.erro', { janela: this, erro });
            }
        } finally {
            if (n === this.montagem) tirar();
        }
    }

    // Remonta no mesmo lugar (troca de idioma, nova tentativa), mantendo a rolagem.
    async remontar() {
        if (this.fechada) return;
        const rolagem = this.corpo.scrollTop;
        this.limpar();
        this.retraduzirControles();
        if (!this.fundo && this.def.titulo) this.retraduzirTitulo(true);
        await this.montar();
        requestAnimationFrame(() => { this.corpo.scrollTop = rolagem; });
    }

    observarTamanho() {
        if (!window.ResizeObserver) return;
        let anterior = '';
        let espera = 0;
        this.observador = new ResizeObserver(() => {
            clearTimeout(espera);
            espera = setTimeout(() => {
                if (!this.el.isConnected) return;
                const largura = this.largura();
                const altura = this.altura();
                const compacta = this.compacta();
                this.el.classList.toggle('is-compacta', compacta);
                const marca = largura + 'x' + altura;
                if (marca === anterior) return;
                anterior = marca;
                for (const fn of [...this.ouvintesTamanho]) {
                    try { fn({ largura, altura, compacta }); } catch (e) { setTimeout(() => { throw e; }); }
                }
            }, 60);
        });
        this.observador.observe(this.el);
    }

    // Geometria: posição por transform, tamanho por largura e altura.
    limitarPos(x, y, largura) {
        const area = this.palco.tamanhoArea();
        const visivel = Math.min(140, largura);
        return {
            x: Math.round(Math.min(Math.max(x, visivel - largura), area.largura - visivel)),
            y: Math.round(Math.min(Math.max(y, 0), Math.max(0, area.altura - 44))),
        };
    }

    limitarRet(r) {
        const area = this.palco.tamanhoArea();
        const largura = Math.round(Math.max(Math.min(this.minimo.largura, area.largura), Math.min(r.largura, area.largura)));
        const altura = Math.round(Math.max(Math.min(this.minimo.altura, area.altura), Math.min(r.altura, area.altura)));
        const p = this.limitarPos(r.x, r.y, largura);
        return { x: p.x, y: p.y, largura, altura };
    }

    // Cabe inteira na área (restaurar do maximizado nunca devolve a janela meio fora do palco).
    limitarDentro(r) {
        const area = this.palco.tamanhoArea();
        const n = this.limitarRet(r);
        n.x = Math.round(Math.min(Math.max(0, n.x), Math.max(0, area.largura - n.largura)));
        n.y = Math.round(Math.min(Math.max(0, n.y), Math.max(0, area.altura - n.altura)));
        return n;
    }

    pintar(r) {
        if (this.fundo || this.palco.modoCelular()) return;
        this.el.style.width = r.largura + 'px';
        this.el.style.height = r.altura + 'px';
        this.el.style.transform = `translate3d(${r.x}px, ${r.y}px, 0)`;
    }

    // Aplica o retângulo; com animar, faz a transição só por transform (FLIP).
    aplicarRet(r, { animar = false, limitar = true } = {}) {
        if (this.fundo) return;
        const novo = limitar ? this.limitarRet(r) : { ...r };
        if (this.palco.modoCelular()) {
            this.ret = novo;
            return;
        }
        const conectado = this.el.isConnected && !this.minimizada;
        if (!animar || !conectado || movimentoReduzido()) {
            this.ret = novo;
            this.pintar(novo);
            return;
        }
        const antes = this.el.getBoundingClientRect();
        this.ret = novo;
        this.el.classList.remove('is-animando');
        this.pintar(novo);
        const depois = this.el.getBoundingClientRect();
        if (!depois.width || !depois.height) return;
        const sx = antes.width / depois.width;
        const sy = antes.height / depois.height;
        const dx = antes.left - depois.left;
        const dy = antes.top - depois.top;
        this.el.style.transform = `translate3d(${novo.x + dx}px, ${novo.y + dy}px, 0) scale(${sx}, ${sy})`;
        this.el.getBoundingClientRect();
        this.el.classList.add('is-animando');
        this.el.style.transform = `translate3d(${novo.x}px, ${novo.y}px, 0)`;
        clearTimeout(this.esperaAnimacao);
        this.esperaAnimacao = setTimeout(() => this.el.classList.remove('is-animando'), 280);
    }

    marcarMaximizada(sim) {
        if (Boolean(sim) !== this.maximizada) esconderDicas();
        this.maximizada = Boolean(sim);
        this.el.classList.toggle('is-maximizada', this.maximizada);
        if (this.btnMax) {
            this.btnMax.replaceChildren(ic(this.maximizada ? 'restaurar' : 'maximizar'));
            rotularBotao(this.btnMax, this.maximizada ? 'casca.janela.restaurar' : 'casca.janela.maximizar');
        }
    }

    ligarArrasto() {
        this.cab.addEventListener('dblclick', (ev) => {
            if (!ev.target.closest('button') && !celular()) this.palco.alternarMaximizar(this);
        });
        this.cab.addEventListener('pointerdown', (ev) => {
            if (ev.button !== 0 || ev.target.closest('button, a, input') || celular()) return;
            const area = this.palco.retArea();
            const inicioX = ev.clientX;
            const inicioY = ev.clientY;
            let base = { ...this.ret };
            let movendo = false;
            let zona = null;
            let quadro = 0;
            let pos = { x: base.x, y: base.y };
            const mover = (e) => {
                const dx = e.clientX - inicioX;
                const dy = e.clientY - inicioY;
                if (!movendo) {
                    if (Math.abs(dx) + Math.abs(dy) < 5) return;
                    movendo = true;
                    this.el.classList.add('is-arrastando');
                    document.documentElement.classList.add('palco-arrastando');
                    if (this.maximizada || this.zona) {
                        // Solta do encaixe: volta ao tamanho anterior sob o ponteiro.
                        const ant = this.retAntes || { largura: this.def.largura || 760, altura: this.def.altura || 560 };
                        const fracao = (inicioX - area.left - this.ret.x) / Math.max(1, this.ret.largura);
                        const largura = Math.min(ant.largura, area.width);
                        base = { x: inicioX - area.left - fracao * largura, y: this.ret.y, largura, altura: Math.min(ant.altura, area.height) };
                        this.zona = null;
                        this.zonaAntes = null;
                        this.marcarMaximizada(false);
                        this.pintar(base);
                    }
                }
                zona = this.palco.zonaEncaixe(e.clientX, e.clientY);
                this.palco.mostrarEncaixe(zona);
                pos = this.limitarPos(base.x + dx, base.y + dy, base.largura);
                cancelAnimationFrame(quadro);
                quadro = requestAnimationFrame(() => {
                    this.el.style.transform = `translate3d(${pos.x}px, ${pos.y}px, 0)`;
                });
            };
            const soltar = () => {
                window.removeEventListener('pointermove', mover);
                window.removeEventListener('pointerup', soltar);
                window.removeEventListener('pointercancel', soltar);
                cancelAnimationFrame(quadro);
                this.palco.mostrarEncaixe(null);
                document.documentElement.classList.remove('palco-arrastando');
                if (!movendo) return;
                this.el.classList.remove('is-arrastando');
                if (zona) {
                    this.retAntes = { ...base, ...pos };
                    this.palco.encaixar(this, zona);
                } else {
                    this.aplicarRet({ ...base, ...pos });
                    this.palco.agendarSalvar();
                }
            };
            window.addEventListener('pointermove', mover);
            window.addEventListener('pointerup', soltar);
            window.addEventListener('pointercancel', soltar);
        });
    }

    ligarBordas() {
        this.el.addEventListener('pointerdown', (ev) => {
            const borda = ev.target.closest('.janela-borda');
            if (!borda || ev.button !== 0 || this.maximizada || celular()) return;
            ev.preventDefault();
            const dir = borda.dataset.borda;
            const area = this.palco.tamanhoArea();
            const base = { ...this.ret };
            const inicioX = ev.clientX;
            const inicioY = ev.clientY;
            let atual = { ...base };
            let quadro = 0;
            document.documentElement.classList.add('palco-redimensionando');
            document.documentElement.dataset.borda = dir;
            this.el.classList.add('is-redimensionando');
            const mover = (e) => {
                const dx = e.clientX - inicioX;
                const dy = e.clientY - inicioY;
                let { x, y, largura, altura } = base;
                if (dir.includes('e')) largura = Math.min(base.largura + dx, area.largura - base.x);
                if (dir.includes('s')) altura = Math.min(base.altura + dy, area.altura - base.y);
                if (dir.includes('w')) largura = Math.min(base.largura - dx, base.x + base.largura);
                if (dir.includes('n')) altura = Math.min(base.altura - dy, base.y + base.altura);
                largura = Math.max(this.minimo.largura, largura);
                altura = Math.max(this.minimo.altura, altura);
                if (dir.includes('w')) x = base.x + base.largura - largura;
                if (dir.includes('n')) y = base.y + base.altura - altura;
                atual = { x: Math.round(x), y: Math.round(y), largura: Math.round(largura), altura: Math.round(altura) };
                cancelAnimationFrame(quadro);
                quadro = requestAnimationFrame(() => this.pintar(atual));
            };
            const soltar = () => {
                window.removeEventListener('pointermove', mover);
                window.removeEventListener('pointerup', soltar);
                window.removeEventListener('pointercancel', soltar);
                cancelAnimationFrame(quadro);
                document.documentElement.classList.remove('palco-redimensionando');
                delete document.documentElement.dataset.borda;
                this.el.classList.remove('is-redimensionando');
                this.zona = null;
                this.zonaAntes = null;
                this.aplicarRet(atual);
                this.palco.agendarSalvar();
            };
            window.addEventListener('pointermove', mover);
            window.addEventListener('pointerup', soltar);
            window.addEventListener('pointercancel', soltar);
        });
    }

    // Esc fecha a janela focada (modal e menus abertos respondem antes, pelo D2UI).
    // Num campo, o primeiro Esc só sai do campo, para não perder o que foi digitado.
    ligarTeclado() {
        this.el.addEventListener('keydown', (ev) => {
            if ((ev.key === 'F10' && ev.shiftKey) || ev.key === 'ContextMenu') {
                if (ev.target.closest('.janela-corpo')) return;
                ev.preventDefault();
                this.menuAcoes(this.btnMax);
                return;
            }
            if (ev.key !== 'Escape' || ev.defaultPrevented) return;
            const alvo = ev.target;
            if (alvo instanceof Element && alvo.matches('input, textarea, select, [contenteditable="true"]')) {
                ev.preventDefault();
                alvo.blur();
                this.el.focus({ preventScroll: true });
                return;
            }
            ev.preventDefault();
            this.fechar();
        });
    }

    destruir() {
        this.fechada = true;
        this.montagem++;
        if (this.observador) this.observador.disconnect();
        this.limpar();
        this.el.remove();
    }
}

export default Janela;
