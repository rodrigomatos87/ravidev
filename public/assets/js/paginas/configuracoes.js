// Configurações do sistema (admin). A página vem pintada pelo PHP; aqui ficam os salvamentos
// automáticos (interruptores, seleções, controles deslizantes) e o envio de idioma.
import { chamar, D2UI } from '/assets/d2/js/casca.js';

const salvo = (texto) => D2UI.toast(texto, { tipo: 'ok', duracao: 2200 });
const falhou = (erro, padrao) => D2UI.toast((erro && erro.message) || padrao, { tipo: 'erro' });

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
    });
}

// Notificações do sistema
for (const cb of document.querySelectorAll('.js-pref')) {
    interruptor(cb, '/settings/notifications', (c) => ({ key: c.dataset.pref, enabled: c.checked ? '1' : '0' }), 'Preferência salva.');
}
// Origens do custo, visibilidade, entrada do sistema e janela de 1M
for (const cb of document.querySelectorAll('.js-flag')) {
    interruptor(cb, '/settings/cost-flag', (c) => ({ key: c.dataset.chave, enabled: c.checked ? '1' : '0' }), 'Preferência salva.');
}

// Zerar contadores de custo
const btnZerar = document.getElementById('custo-zerar');
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
            const total = document.getElementById('custo-total');
            if (total && d && d.total_cost !== undefined) {
                const v = Number(d.total_cost) || 0;
                total.textContent = v <= 0 ? '$0.00' : v < 0.01 ? '<$0.01' : '$' + v.toFixed(2);
            }
            const em = document.getElementById('custo-zerado-em');
            if (em) em.textContent = 'Zerados agora';
            salvo('Contadores zerados.');
        } catch (erro) {
            falhou(erro, 'Não foi possível zerar.');
        } finally {
            btnZerar.classList.remove('d2-btn-carregando');
            btnZerar.disabled = false;
        }
    });
}

// Idioma ativo
const selIdioma = document.getElementById('idioma-ativo');
const linkBaixar = document.getElementById('idioma-baixar');
if (selIdioma) {
    let anterior = selIdioma.value;
    selIdioma.addEventListener('change', async () => {
        if (linkBaixar) linkBaixar.href = '/settings/language/download?lang=' + encodeURIComponent(selIdioma.value);
        selIdioma.disabled = true;
        try {
            await chamar('/settings/language', { metodo: 'POST', formulario: { lang: selIdioma.value } });
            D2UI.progresso({ titulo: 'Aplicando o idioma' });
            location.reload();
        } catch (erro) {
            selIdioma.value = anterior;
            if (linkBaixar) linkBaixar.href = '/settings/language/download?lang=' + encodeURIComponent(anterior);
            falhou(erro, 'Não foi possível trocar o idioma.');
            selIdioma.disabled = false;
            return;
        }
        anterior = selIdioma.value;
    });
}

// Envio de um novo idioma
const formIdioma = document.getElementById('idioma-form');
if (formIdioma) {
    formIdioma.addEventListener('submit', async (ev) => {
        ev.preventDefault();
        const nome = document.getElementById('idioma-nome');
        const arquivo = document.getElementById('idioma-arquivo');
        const btn = document.getElementById('idioma-enviar');
        if (!nome.value.trim()) { D2UI.toast('Informe um nome para o idioma (ex.: en).', { tipo: 'aviso' }); nome.focus(); return; }
        if (!arquivo.files || !arquivo.files[0]) { D2UI.toast('Escolha um arquivo .json.', { tipo: 'aviso' }); arquivo.focus(); return; }
        const fd = new FormData();
        fd.append('name', nome.value.trim());
        fd.append('file', arquivo.files[0]);
        btn.classList.add('d2-btn-carregando');
        btn.disabled = true;
        try {
            await chamar('/settings/language/upload', { metodo: 'POST', formulario: fd });
            D2UI.progresso({ titulo: 'Idioma enviado', texto: 'Atualizando a página.' });
            location.reload();
        } catch (erro) {
            falhou(erro, 'Não foi possível enviar o idioma.');
            btn.classList.remove('d2-btn-carregando');
            btn.disabled = false;
        }
    });
}

// Modelo padrão por escopo
for (const sel of document.querySelectorAll('select[data-escopo]')) {
    let anterior = sel.value;
    sel.addEventListener('change', async () => {
        sel.disabled = true;
        try {
            await chamar('/settings/model', { metodo: 'POST', formulario: { scope: sel.dataset.escopo, model: sel.value } });
            anterior = sel.value;
            salvo('Modelo padrão salvo. Vale para as sessões abertas a partir de agora.');
        } catch (erro) {
            sel.value = anterior;
            falhou(erro, 'Não foi possível salvar o modelo.');
        } finally {
            sel.disabled = false;
        }
    });
}

// Recursos dos ambientes (memória e CPU)
const fmtMem = (mb) => { mb = Number(mb); return mb >= 1024 ? (mb / 1024).toFixed(mb % 1024 ? 1 : 0).replace('.', ',') + ' GB' : mb + ' MB'; };
const recursos = [...document.querySelectorAll('.js-recurso')];
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
    const rotulo = document.getElementById(r.id + '-valor');
    const pintar = () => { if (rotulo) rotulo.textContent = r.dataset.tipo === 'mem' ? fmtMem(r.value) : r.value; };
    pintar();
    r.addEventListener('input', pintar);
    r.addEventListener('change', () => { clearTimeout(esperaRecursos); esperaRecursos = setTimeout(salvarRecursos, 250); });
}

// Alerta de uso do servidor
const alertaAtivo = document.getElementById('alerta-ativo');
const alertaDisco = document.getElementById('alerta-disco');
const alertaRam = document.getElementById('alerta-ram');
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
    alertaDisco.addEventListener('input', () => { document.getElementById('alerta-disco-valor').textContent = alertaDisco.value; });
    alertaRam.addEventListener('input', () => { document.getElementById('alerta-ram-valor').textContent = alertaRam.value; });
    for (const r of [alertaDisco, alertaRam]) r.addEventListener('change', () => { clearTimeout(esperaAlerta); esperaAlerta = setTimeout(salvarAlerta, 250); });
    alertaAtivo.addEventListener('change', salvarAlerta);
}

// Formulários enviados por POST normal: o botão mostra que está trabalhando até a página trocar.
for (const f of document.querySelectorAll('form[method="POST"]')) {
    f.addEventListener('submit', () => {
        const b = f.querySelector('button[type="submit"]');
        if (b) { b.classList.add('d2-btn-carregando'); b.setAttribute('aria-busy', 'true'); }
    });
}

// Servidor de e-mail: teste de envio
const formTeste = document.getElementById('email-teste-form');
if (formTeste) {
    const para = document.getElementById('email-teste-para');
    const resultado = document.getElementById('email-teste-resultado');
    const btn = document.getElementById('email-teste-btn');
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
    });
}
