// Aba Arquivos do hub: navegar, baixar, enviar (botão ou arrastar), ver, editar e excluir.
// Endpoints: /chat/{uuid}/ls, /download, /upload-file, /view, /save, /delete (os mesmos da tela antiga).
import { tx, el, botao, chamar, D2UI } from './projeto-comum.js';

export async function montarArquivos({ uuid }) {
    const raizChat = '/chat/' + encodeURIComponent(uuid);
    const csrf = (window.RAVI_CASCA && window.RAVI_CASCA.csrf) || '';
    let pastaAtual = '';
    let entradas = { dirs: [], files: [] };
    let zip = null;

    const migalhas = el('nav', { class: 'projeto-migalhas', 'aria-label': tx('projeto.arq_caminho', 'Caminho') });
    const lista = el('ul', { class: 'lista-acionavel projeto-arquivos' });
    const rodape = el('span', { class: 'texto-mudo' });
    const entradaArquivos = el('input', { type: 'file', multiple: true, hidden: true });

    const botaoBaixarTudo = botao(tx('projeto.arq_baixar_tudo', 'Baixar tudo (.zip)'), { icone: 'seta-baixo', aoClicar: () => { if (zip) baixar(zip); } });
    botaoBaixarTudo.hidden = true;
    const botaoEnviar = botao(tx('projeto.arq_enviar', 'Enviar arquivos'), { icone: 'subir', tipo: 'primario', aoClicar: () => entradaArquivos.click() });
    const botaoAtualizar = botao('', { icone: 'atualizar', dica: tx('projeto.atualizar', 'Atualizar'), aoClicar: () => carregar(pastaAtual) });

    const p = D2UI.painel({
        titulo: tx('projeto.aba_arquivos', 'Arquivos'), icone: 'grade', area: 'projetos',
        sub: tx('projeto.arq_sub', 'Arraste arquivos para enviar à pasta aberta'),
        acoes: [botaoAtualizar, botaoBaixarTudo, botaoEnviar],
        rodape,
    }, migalhas, lista, entradaArquivos);
    p.el.classList.add('projeto-arquivos-painel');

    const baixar = (caminho) => { window.location = raizChat + '/download?file=' + encodeURIComponent(caminho); };
    const juntar = (nome) => (pastaAtual ? pastaAtual + '/' + nome : nome);

    function desenharMigalhas() {
        migalhas.textContent = '';
        const partes = pastaAtual ? pastaAtual.split('/') : [];
        const itens = [{ rotulo: tx('projeto.arq_raiz', 'Projeto'), caminho: '' }];
        partes.forEach((parte, i) => itens.push({ rotulo: parte, caminho: partes.slice(0, i + 1).join('/') }));
        itens.forEach((it, i) => {
            if (i) migalhas.append(el('span', { class: 'projeto-migalha-sep', 'aria-hidden': 'true' }, D2UI.icone('chevron-direita')));
            if (i === itens.length - 1) migalhas.append(el('span', { class: 'projeto-migalha is-atual', 'aria-current': 'page', texto: it.rotulo }));
            else migalhas.append(el('button', { type: 'button', class: 'projeto-migalha', texto: it.rotulo, onclick: () => carregar(it.caminho) }));
        });
    }

    function linha({ icone, titulo, sub, aoAbrir, acoes = [], rotulo }) {
        const principal = el('button', { type: 'button', class: 'projeto-arquivo-abrir', onclick: aoAbrir, 'aria-label': rotulo || titulo },
            el('span', { class: 'lista-item-icone', 'aria-hidden': 'true' }, D2UI.icone(icone)),
            el('span', { class: 'lista-item-texto' }, el('span', { class: 'lista-item-titulo', texto: titulo }), sub ? el('span', { class: 'lista-item-sub', texto: sub }) : null));
        return el('li', { class: 'lista-item projeto-arquivo' }, principal, acoes.length ? el('span', { class: 'projeto-arquivo-acoes' }, acoes) : null);
    }

    function desenhar() {
        desenharMigalhas();
        lista.textContent = '';
        const total = entradas.dirs.length + entradas.files.length;
        if (pastaAtual) {
            const acima = pastaAtual.includes('/') ? pastaAtual.slice(0, pastaAtual.lastIndexOf('/')) : '';
            lista.append(linha({ icone: 'seta-cima', titulo: '..', rotulo: tx('projeto.arq_subir', 'Voltar para a pasta anterior'), aoAbrir: () => carregar(acima) }));
        }
        if (!total) {
            lista.append(el('li', { class: 'projeto-arquivos-vazio' }, D2UI.vazio({
                chave: pastaAtual ? tx('projeto.arq_pasta_vazia', 'Pasta vazia') : tx('projeto.arq_nenhum', 'Nenhum arquivo ainda'),
                texto: pastaAtual
                    ? tx('projeto.arq_pasta_vazia_texto', 'Arraste arquivos para enviar aqui.')
                    : tx('projeto.arq_nenhum_texto', 'Use o chat, importe um .zip ou arraste arquivos aqui.'),
                icone: 'grade',
                acao: { rotulo: tx('projeto.arq_enviar', 'Enviar arquivos'), icone: 'subir', tipo: 'primario', fn: () => entradaArquivos.click() },
            })));
        }
        for (const d of entradas.dirs) {
            lista.append(linha({ icone: 'grade', titulo: d.name, aoAbrir: () => carregar(juntar(d.name)) }));
        }
        for (const f of entradas.files) {
            const caminho = juntar(f.name);
            lista.append(linha({
                icone: 'documento', titulo: f.name, sub: D2UI.formatar.tamanho(f.size || 0), aoAbrir: () => abrir(caminho),
                acoes: [
                    botao('', { icone: 'seta-baixo', tipo: 'fantasma', dica: tx('projeto.arq_baixar', 'Baixar'), rotuloAria: tx('projeto.arq_baixar', 'Baixar') + ' ' + f.name, aoClicar: () => baixar(caminho) }),
                    botao('', { icone: 'lixeira', tipo: 'fantasma', dica: tx('projeto.arq_excluir', 'Excluir'), rotuloAria: tx('projeto.arq_excluir', 'Excluir') + ' ' + f.name, aoClicar: () => excluir(caminho) }),
                ],
            }));
        }
        const itens = total === 1 ? tx('projeto.arq_item', 'item') : tx('projeto.arq_itens', 'itens');
        rodape.textContent = total + ' ' + itens + (!pastaAtual && totalDisco ? ' · ' + D2UI.formatar.tamanho(totalDisco) + ' ' + tx('projeto.arq_no_disco', 'no disco') : '');
    }

    let totalDisco = 0;
    async function carregar(caminho) {
        lista.textContent = '';
        lista.append(el('li', {}, D2UI.esqueleto(3)));
        try {
            const d = await chamar(raizChat + '/ls?path=' + encodeURIComponent(caminho || ''));
            if (d && d.ok === false) throw new Error(d.error || '');
            pastaAtual = (d && d.path) || '';
            entradas = { dirs: (d && d.dirs) || [], files: (d && d.files) || [] };
            if (!pastaAtual) {
                zip = (d && d.zip) || null;
                totalDisco = Number((d && d.total_size) || 0);
                botaoBaixarTudo.hidden = !zip;
            }
            desenhar();
        } catch (e) {
            lista.textContent = '';
            lista.append(el('li', { class: 'projeto-arquivos-vazio' }, D2UI.vazio({
                chave: tx('projeto.arq_erro', 'Erro ao listar arquivos.'), texto: e.message || null, icone: 'erro', tipo: 'erro',
                acao: { rotulo: tx('projeto.tentar_de_novo', 'Tentar de novo'), icone: 'atualizar', fn: () => carregar(caminho) },
            })));
        }
    }

    // ── Envio para a pasta aberta ─────────────────────────────────────
    async function enviarUm(arquivo, substituir) {
        const fd = new FormData();
        fd.set('file', arquivo); fd.set('_token', csrf); fd.set('path', pastaAtual);
        if (substituir) fd.set('overwrite', '1');
        try {
            const d = await chamar(raizChat + '/upload-file', { metodo: 'POST', formulario: fd });
            if (!(d && d.ok) && !(d && d.conflict)) D2UI.toast((d && d.error) || tx('projeto.arq_falha_envio', 'Falha ao enviar') + ' ' + arquivo.name, { tipo: 'erro' });
        } catch (e) {
            D2UI.toast(e.message || tx('projeto.arq_erro_rede', 'Erro de rede ao enviar') + ' ' + arquivo.name, { tipo: 'erro' });
        }
    }
    async function enviarVarios(arquivos, substituir) {
        if (!arquivos.length) return;
        const prog = D2UI.progresso({ titulo: tx('projeto.arq_enviando', 'Enviando arquivos') });
        let feitos = 0;
        for (const f of arquivos) {
            prog.atualizar(Math.round((feitos / arquivos.length) * 100), f.name);
            await enviarUm(f, substituir);
            feitos++;
        }
        prog.concluir();
    }
    async function enviar(lista2) {
        const arquivos = Array.from(lista2 || []);
        if (!arquivos.length) return;
        const existentes = new Set(entradas.files.map((f) => String(f.name || '').toLowerCase()));
        const conflitos = arquivos.filter((f) => existentes.has(String(f.name || '').toLowerCase()));
        const novos = arquivos.filter((f) => !existentes.has(String(f.name || '').toLowerCase()));
        await enviarVarios(novos, false);
        if (conflitos.length) {
            const ok = await D2UI.confirmar({
                titulo: tx('projeto.arq_substituir_titulo', 'Arquivos já existem'),
                texto: tx('projeto.arq_substituir', 'Já existe nesta pasta:') + ' ' + conflitos.map((f) => f.name).join(', ') + '. ' + tx('projeto.arq_substituir_pergunta', 'Substituir?'),
                rotuloOk: tx('projeto.arq_substituir_ok', 'Substituir'),
            });
            if (ok) await enviarVarios(conflitos, true);
        }
        carregar(pastaAtual);
    }
    entradaArquivos.addEventListener('change', () => { const fs = Array.from(entradaArquivos.files || []); entradaArquivos.value = ''; enviar(fs); });
    ['dragenter', 'dragover'].forEach((t) => p.el.addEventListener(t, (ev) => {
        if (!ev.dataTransfer || ![...(ev.dataTransfer.types || [])].includes('Files')) return;
        ev.preventDefault(); ev.stopPropagation(); p.el.classList.add('is-arrastando');
    }));
    p.el.addEventListener('dragleave', (ev) => { if (!p.el.contains(ev.relatedTarget)) p.el.classList.remove('is-arrastando'); });
    p.el.addEventListener('drop', (ev) => {
        ev.preventDefault(); ev.stopPropagation(); p.el.classList.remove('is-arrastando');
        const dt = ev.dataTransfer;
        if (dt && dt.files && dt.files.length) enviar(dt.files);
    });

    // ── Ver e editar ──────────────────────────────────────────────────
    async function abrir(caminho) {
        let d;
        try {
            d = await chamar(raizChat + '/view?file=' + encodeURIComponent(caminho));
        } catch (e) {
            D2UI.toast(e.message || tx('projeto.arq_erro_abrir', 'Não foi possível abrir o arquivo.'), { tipo: 'erro' });
            return;
        }
        const binario = !d || d.binary;
        const original = binario ? '' : String(d.content || '');
        let area = null;
        let sujo = false;
        const marcaSujo = el('span', { class: 'd2-selo d2-selo-aviso projeto-editor-sujo', 'data-tom': 'aviso', hidden: true },
            el('span', { class: 'd2-ponto', 'aria-hidden': 'true' }), el('span', { class: 'd2-selo-texto', texto: tx('projeto.arq_nao_salvo', 'Não salvo') }));
        let corpo;
        if (binario) {
            corpo = D2UI.vazio({
                chave: (d && d.reason) || tx('projeto.arq_binario', 'Arquivo binário, não editável aqui.'), icone: 'documento',
                acao: { rotulo: tx('projeto.arq_baixar', 'Baixar'), icone: 'seta-baixo', tipo: 'primario', fn: () => baixar(caminho) },
            });
        } else {
            area = el('textarea', { class: 'd2-control projeto-editor', spellcheck: 'false', 'aria-label': caminho });
            area.value = original;
            area.addEventListener('input', () => { sujo = area.value !== original; marcaSujo.hidden = !sujo; });
            area.addEventListener('keydown', (ev) => {
                if (ev.key === 'Tab') { ev.preventDefault(); const i = area.selectionStart; area.setRangeText('    ', i, area.selectionEnd, 'end'); area.dispatchEvent(new Event('input')); }
            });
            corpo = el('div', { class: 'projeto-editor-caixa' }, el('div', { class: 'projeto-editor-topo' }, el('code', { class: 'projeto-editor-caminho', texto: caminho }), marcaSujo), area);
        }
        const fecharComCuidado = async (m) => {
            if (sujo) {
                const ok = await D2UI.confirmar({ titulo: tx('projeto.arq_descartar_titulo', 'Alterações não salvas'), texto: tx('projeto.arq_descartar', 'Descartar alterações não salvas?'), rotuloOk: tx('projeto.arq_descartar_ok', 'Descartar'), perigo: true });
                if (!ok) return false;
            }
            m.fechar();
            return false;
        };
        const botoes = [
            { rotulo: tx('projeto.arq_excluir', 'Excluir'), tipo: 'perigo', icone: 'lixeira', fn: async (m) => { const r = await excluir(caminho); if (r) m.fechar(); return false; } },
            { rotulo: tx('projeto.arq_baixar', 'Baixar'), icone: 'seta-baixo', fn: () => { baixar(caminho); return false; } },
            { rotulo: tx('projeto.fechar', 'Fechar'), fn: (m) => fecharComCuidado(m) },
        ];
        if (!binario) {
            botoes.push({
                rotulo: tx('projeto.salvar', 'Salvar'), tipo: 'primario', icone: 'check',
                fn: async () => {
                    try {
                        const r = await chamar(raizChat + '/save', { metodo: 'POST', formulario: { file: caminho, content: area.value, _token: csrf } });
                        if (!(r && r.saved)) throw new Error((r && r.error) || '');
                        sujo = false; marcaSujo.hidden = true;
                        D2UI.toast(tx('projeto.arq_salvo', 'Arquivo salvo.'), { tipo: 'ok' });
                        carregar(pastaAtual);
                    } catch (e) {
                        D2UI.toast(e.message || tx('projeto.arq_erro_salvar', 'Não foi possível salvar.'), { tipo: 'erro' });
                    }
                    return false;
                },
            });
        }
        D2UI.modal({ titulo: caminho.split('/').pop(), icone: 'documento', corpo, largura: binario ? 520 : 960, fechavel: false, classe: 'projeto-modal-editor', botoes });
        if (area) setTimeout(() => area.focus(), 50);
    }

    async function excluir(caminho) {
        const ok = await D2UI.confirmar({
            titulo: tx('projeto.arq_excluir_titulo', 'Excluir arquivo'),
            texto: tx('projeto.arq_excluir_texto', 'Excluir "%s"? Esta ação não pode ser desfeita.').replace('%s', caminho),
            perigo: true, rotuloOk: tx('projeto.arq_excluir', 'Excluir'),
        });
        if (!ok) return false;
        try {
            const d = await chamar(raizChat + '/delete', { metodo: 'POST', formulario: { file: caminho, _token: csrf } });
            if (!(d && d.deleted)) throw new Error((d && d.error) || '');
            D2UI.toast(tx('projeto.arq_excluido', 'Arquivo excluído.'), { tipo: 'ok' });
            carregar(pastaAtual);
            return true;
        } catch (e) {
            D2UI.toast(e.message || tx('projeto.arq_erro_excluir', 'Não foi possível excluir.'), { tipo: 'erro' });
            return false;
        }
    }

    carregar('');
    return p.el;
}

