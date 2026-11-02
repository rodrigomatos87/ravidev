// Configurações do sistema (admin) e servidor de e-mail. A tela vem pintada pelo PHP; aqui ficam
// os salvamentos automáticos (interruptores, seleções, controles deslizantes), o envio de idioma
// e o teste de e-mail. Tudo relativo à raiz da tela; a limpeza solta os ouvintes e as esperas.
import { chamar, D2UI } from '/assets/d2/js/casca.js';

const salvo = (texto) => D2UI.toast(texto, { tipo: 'ok', duracao: 2200 });
const falhou = (erro, padrao) => D2UI.toast((erro && erro.message) || padrao, { tipo: 'erro' });
const fmtMem = (mb) => { mb = Number(mb); return mb >= 1024 ? (mb / 1024).toFixed(mb % 1024 ? 1 : 0).replace('.', ',') + ' GB' : mb + ' MB'; };

// Uma inicialização por raiz: rodar de novo na mesma raiz limpa a anterior antes.
const ativos = new WeakMap();

// Recarrega a tela: na janela, só ela; na página cheia, a página.
function recarregarTela(raiz, titulo, texto) {
    const casca = window.Casca;
    const prog = D2UI.progresso({ titulo, texto });
    if (casca && typeof casca.recarregar === 'function') {
        Promise.resolve(casca.recarregar(raiz)).catch(() => {}).finally(() => prog.fechar());
        return;
    }
    location.reload();
}

export async function iniciar(raiz) {
    const anterior = ativos.get(raiz);
    if (anterior) anterior();

    const sinal = new AbortController();
    const op = { signal: sinal.signal };
    const esperas = new Set();
    const esperar = (fn, ms) => { const id = setTimeout(() => { esperas.delete(id); fn(); }, ms); esperas.add(id); return id; };
    const cancelar = (id) => { if (id) { clearTimeout(id); esperas.delete(id); } };
    const $ = (id) => raiz.querySelector('#' + id);

    // Interruptor que salva sozinho: desativa enquanto envia e volta atrás se falhar.
    function interruptor(cb, url, campos, textoOk) {
        cb.addEventListener('change', async () => {
            cb.disabled = true;
            try {
                await chamar(url, { metodo: 'POST', formulario: campos(cb) });
                salvo(textoOk);
            } catch (erro) {
                cb.checked = !cb.checked;
                falhou(erro, 'Não foi possível salvar.');
            } finally {
                cb.disabled = false;
            }
        }, op);
    }

    // Notificações do sistema
    for (const cb of raiz.querySelectorAll('.js-pref')) {
        interruptor(cb, '/settings/notifications', (c) => ({ key: c.dataset.pref, enabled: c.checked ? '1' : '0' }), 'Preferência salva.');
    }
    // Origens do custo, visibilidade, entrada do sistema e janela de 1M
    for (const cb of raiz.querySelectorAll('.js-flag')) {
        interruptor(cb, '/settings/cost-flag', (c) => ({ key: c.dataset.chave, enabled: c.checked ? '1' : '0' }), 'Preferência salva.');
    }

    // Zerar contadores de custo
    const btnZerar = $('custo-zerar');
    if (btnZerar) {
        btnZerar.addEventListener('click', async () => {
            const ok = await D2UI.confirmar({
                titulo: 'Zerar contadores',
                texto: 'Zerar os contadores de custo estimado? A contagem recomeça a partir de agora. O histórico não é apagado.',
                rotuloOk: 'Zerar',
            });
            if (!ok) return;
            btnZerar.classList.add('d2-btn-carregando');
            btnZerar.disabled = true;
            try {
                const d = await chamar('/settings/cost-reset', { metodo: 'POST', formulario: {} });
                const total = $('custo-total');
                if (total && d && d.total_cost !== undefined) {
                    const v = Number(d.total_cost) || 0;
                    total.textContent = v <= 0 ? '$0.00' : v < 0.01 ? '<$0.01' : '$' + v.toFixed(2);
                }
                const em = $('custo-zerado-em');
                if (em) em.textContent = 'Zerados agora';
                salvo('Contadores zerados.');
            } catch (erro) {
                falhou(erro, 'Não foi possível zerar.');
            } finally {
                btnZerar.classList.remove('d2-btn-carregando');
                btnZerar.disabled = false;
            }
        }, op);
    }

    // Idioma ativo: muda os textos da casca inteira (menu, topo), por isso recarrega a página toda.
    const selIdioma = $('idioma-ativo');
    const linkBaixar = $('idioma-baixar');
    if (selIdioma) {
        let anteriorIdioma = selIdioma.value;
        selIdioma.addEventListener('change', async () => {
            if (linkBaixar) linkBaixar.href = '/settings/language/download?lang=' + encodeURIComponent(selIdioma.value);
            selIdioma.disabled = true;
            try {
                await chamar('/settings/language', { metodo: 'POST', formulario: { lang: selIdioma.value } });
                D2UI.progresso({ titulo: 'Aplicando o idioma' });
                location.reload();
            } catch (erro) {
                selIdioma.value = anteriorIdioma;
                if (linkBaixar) linkBaixar.href = '/settings/language/download?lang=' + encodeURIComponent(anteriorIdioma);
                falhou(erro, 'Não foi possível trocar o idioma.');
                selIdioma.disabled = false;
                return;
            }
            anteriorIdioma = selIdioma.value;
        }, op);
    }

    // Envio de um novo idioma
    const formIdioma = $('idioma-form');
    if (formIdioma) {
        formIdioma.addEventListener('submit', async (ev) => {
            ev.preventDefault();
            ev.stopPropagation();
            const nome = $('idioma-nome');
            const arquivo = $('idioma-arquivo');
            const btn = $('idioma-enviar');
            if (!nome.value.trim()) { D2UI.toast('Informe um nome para o idioma (ex.: en).', { tipo: 'aviso' }); nome.focus(); return; }
            if (!arquivo.files || !arquivo.files[0]) { D2UI.toast('Escolha um arquivo .json.', { tipo: 'aviso' }); arquivo.focus(); return; }
            const fd = new FormData();
            fd.append('name', nome.value.trim());
            fd.append('file', arquivo.files[0]);
            btn.classList.add('d2-btn-carregando');
            btn.disabled = true;
            try {
                await chamar('/settings/language/upload', { metodo: 'POST', formulario: fd });
                recarregarTela(raiz, 'Idioma enviado', 'Atualizando a tela.');
            } catch (erro) {
                falhou(erro, 'Não foi possível enviar o idioma.');
                btn.classList.remove('d2-btn-carregando');
                btn.disabled = false;
            }
        }, op);
    }

    // Modelo padrão por escopo
    for (const sel of raiz.querySelectorAll('select[data-escopo]')) {
        let anteriorModelo = sel.value;
        sel.addEventListener('change', async () => {
            sel.disabled = true;
            try {
                await chamar('/settings/model', { metodo: 'POST', formulario: { scope: sel.dataset.escopo, model: sel.value } });
                anteriorModelo = sel.value;
                salvo('Modelo padrão salvo. Vale para as sessões abertas a partir de agora.');
            } catch (erro) {
                sel.value = anteriorModelo;
                falhou(erro, 'Não foi possível salvar o modelo.');
            } finally {
                sel.disabled = false;
            }
        }, op);
    }

    // Recursos dos ambientes (memória e CPU)
    const recursos = [...raiz.querySelectorAll('.js-recurso')];
    const valorRecurso = (alvo, tipo) => { const r = recursos.find((x) => x.dataset.alvo === alvo && x.dataset.tipo === tipo); return r ? r.value : ''; };
    let esperaRecursos = null;
    async function salvarRecursos() {
        try {
            await chamar('/settings/resources', { metodo: 'POST', formulario: {
                sandbox_mem: valorRecurso('sandbox', 'mem'), sandbox_cpu: valorRecurso('sandbox', 'cpu'),
                ide_mem: valorRecurso('ide', 'mem'), ide_cpu: valorRecurso('ide', 'cpu'),
            } });
            salvo('Recursos salvos. Valem para os ambientes abertos a partir de agora.');
        } catch (erro) {
            falhou(erro, 'Não foi possível salvar os recursos.');
        }
    }
    for (const r of recursos) {
        const rotulo = $(r.id + '-valor');
        const pintar = () => { if (rotulo) rotulo.textContent = r.dataset.tipo === 'mem' ? fmtMem(r.value) : r.value; };
        pintar();
        r.addEventListener('input', pintar, op);
        r.addEventListener('change', () => { cancelar(esperaRecursos); esperaRecursos = esperar(salvarRecursos, 250); }, op);
    }

    // Alerta de uso do servidor
    const alertaAtivo = $('alerta-ativo');
    const alertaDisco = $('alerta-disco');
    const alertaRam = $('alerta-ram');
    if (alertaAtivo && alertaDisco && alertaRam) {
        let esperaAlerta = null;
        const salvarAlerta = async () => {
            try {
                await chamar('/settings/resource-alerts', { metodo: 'POST', formulario: {
                    enabled: alertaAtivo.checked ? '1' : '0', disk_pct: alertaDisco.value, ram_pct: alertaRam.value,
                } });
                salvo('Alerta de uso salvo.');
            } catch (erro) {
                falhou(erro, 'Não foi possível salvar o alerta.');
            }
        };
        const rotuloDisco = $('alerta-disco-valor');
        const rotuloRam = $('alerta-ram-valor');
        alertaDisco.addEventListener('input', () => { if (rotuloDisco) rotuloDisco.textContent = alertaDisco.value; }, op);
        alertaRam.addEventListener('input', () => { if (rotuloRam) rotuloRam.textContent = alertaRam.value; }, op);
        for (const r of [alertaDisco, alertaRam]) r.addEventListener('change', () => { cancelar(esperaAlerta); esperaAlerta = esperar(salvarAlerta, 250); }, op);
        alertaAtivo.addEventListener('change', salvarAlerta, op);
    }

    // Formulários enviados por POST: o botão mostra que está trabalhando até a resposta chegar.
    for (const f of raiz.querySelectorAll('form[method="POST"]')) {
        f.addEventListener('submit', () => {
            const b = f.querySelector('button[type="submit"]');
            if (b) { b.classList.add('d2-btn-carregando'); b.setAttribute('aria-busy', 'true'); }
        }, op);
    }

    // Servidor de e-mail: teste de envio
    const formTeste = $('email-teste-form');
    if (formTeste) {
        const para = $('email-teste-para');
        const resultado = $('email-teste-resultado');
        const btn = $('email-teste-btn');
        const faixa = (tipo, icone, texto) => {
            const f = document.createElement('div');
            f.className = 'd2-faixa d2-faixa-' + tipo;
            f.append(D2UI.icone(icone));
            const t = document.createElement('div');
            t.className = 'd2-faixa-texto';
            t.textContent = texto;
            f.append(t);
            return f;
        };
        formTeste.addEventListener('submit', async (ev) => {
            ev.preventDefault();
            ev.stopPropagation();
            const destino = para.value.trim();
            resultado.replaceChildren();
            if (!destino || !para.checkValidity()) {
                para.setAttribute('aria-invalid', 'true');
                resultado.append(faixa('erro', 'erro', 'Informe um e-mail válido de uma caixa que exista.'));
                para.focus();
                return;
            }
            para.removeAttribute('aria-invalid');
            btn.classList.add('d2-btn-carregando');
            btn.disabled = true;
            try {
                await chamar('/settings/email/test', { metodo: 'POST', formulario: { to: destino } });
                resultado.append(faixa('ok', 'check', 'E-mail de teste enviado para ' + destino + '. Confira a caixa de entrada.'));
            } catch (erro) {
                resultado.append(faixa('erro', 'erro', erro.message || 'Falha ao enviar.'));
            } finally {
                btn.classList.remove('d2-btn-carregando');
                btn.disabled = false;
            }
        }, op);
    }

    const limpar = () => {
        sinal.abort();
        for (const id of esperas) clearTimeout(id);
        esperas.clear();
        if (ativos.get(raiz) === limpar) ativos.delete(raiz);
    };
    ativos.set(raiz, limpar);
    return limpar;
}
