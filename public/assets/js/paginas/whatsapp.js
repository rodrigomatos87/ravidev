// Minha conta, alertas no WhatsApp: conectar por QR (com acompanhamento a cada 2,5 s),
// enviar teste, desconectar e remover contato com confirmação, máscara do celular.
// A limpeza para o acompanhamento do status e solta os ouvintes.
import { chamar, D2UI } from '/assets/d2/js/casca.js';

// Uma inicialização por raiz: rodar de novo na mesma raiz limpa a anterior antes.
const ativos = new WeakMap();

function esperando(botao, ligado) {
    if (!botao) return;
    botao.disabled = ligado;
    botao.classList.toggle('d2-btn-carregando', ligado);
    botao.setAttribute('aria-busy', ligado ? 'true' : 'false');
}

// Gerador de QR carregado só quando precisa (uma vez por página).
let qrPronto = null;
function carregarQr() {
    if (window.QRCode) return Promise.resolve();
    if (!qrPronto) {
        qrPronto = new Promise((ok, falha) => {
            const s = document.createElement('script');
            s.src = '/assets/js/vendor/qrcode.min.js';
            s.onload = ok;
            s.onerror = () => { qrPronto = null; falha(new Error('Não foi possível carregar o código QR.')); };
            document.head.append(s);
        });
    }
    return qrPronto;
}

// Recarrega a tela com aviso de espera: na janela, só ela; na página cheia, a página.
function recarregarTela(raiz, titulo) {
    const casca = window.Casca;
    const prog = D2UI.progresso({ titulo });
    if (casca && typeof casca.recarregar === 'function') {
        Promise.resolve(casca.recarregar(raiz)).catch(() => {}).finally(() => prog.fechar());
        return;
    }
    location.reload();
}

// Envio já confirmado: requestSubmit dispara o evento de envio, então na janela a casca envia por
// fetch e na página cheia o envio é o normal. A marca evita pedir a confirmação de novo.
function enviarConfirmado(form) {
    form.dataset.confirmado = '1';
    form.requestSubmit();
    form.dataset.confirmado = '0';
}

export async function iniciar(raiz) {
    const anterior = ativos.get(raiz);
    if (anterior) anterior();

    const sinal = new AbortController();
    const op = { signal: sinal.signal };
    let encerrado = false;
    const $ = (sel) => raiz.querySelector(sel);

    /* ---------- Conectar ---------- */
    const botaoConectar = $('#wa-conectar');
    let temporizador = null;
    let esperaRecarga = null;

    function acompanhar(idConexao) {
        clearInterval(temporizador);
        temporizador = setInterval(async () => {
            try {
                const d = await chamar(`/whatsapp/status/${encodeURIComponent(idConexao)}`);
                // A resposta pode chegar depois da limpeza: não mexe numa tela que já fechou.
                if (encerrado) return;
                if (d && d.status === 'connected') {
                    clearInterval(temporizador);
                    temporizador = null;
                    const estado = $('#wa-qr-estado');
                    if (estado) estado.textContent = 'Conectado. Atualizando a tela.';
                    D2UI.toast('WhatsApp conectado.', { tipo: 'ok' });
                    esperaRecarga = setTimeout(() => recarregarTela(raiz, 'Atualizando'), 900);
                }
            } catch (e) { /* tenta de novo no próximo ciclo */ }
        }, 2500);
    }

    async function conectar() {
        esperando(botaoConectar, true);
        let d;
        try {
            d = await chamar('/whatsapp/connect', { metodo: 'POST', dados: {} });
        } catch (erro) {
            esperando(botaoConectar, false);
            const msg = (erro.dados && erro.dados.data && erro.dados.data.error) || 'Não foi possível conectar. Tente novamente.';
            D2UI.toast(msg, { tipo: 'erro' });
            return;
        }
        if (encerrado) return;
        if (d && d.already_connected) { recarregarTela(raiz, 'Atualizando'); return; }
        $('#wa-convite').hidden = true;
        $('#wa-qr-bloco').hidden = false;
        const caixa = $('#wa-qr');
        caixa.replaceChildren(D2UI.esqueleto(1));
        if (d && d.qr_code) {
            let texto = d.qr_code;
            const i = texto.indexOf('base64,');
            if (i !== -1) { try { texto = atob(texto.slice(i + 7)); } catch (e) { /* usa como veio */ } }
            try {
                await carregarQr();
                if (encerrado) return;
                caixa.replaceChildren();
                // eslint-disable-next-line no-new
                new window.QRCode(caixa, { text: texto, width: 212, height: 212 });
            } catch (erro) {
                caixa.replaceChildren();
                D2UI.toast(erro.message, { tipo: 'erro' });
            }
        }
        if (d && d.connection_id) acompanhar(d.connection_id);
    }
    if (botaoConectar) botaoConectar.addEventListener('click', conectar, op);
    raiz.querySelectorAll('.js-wa-conectar-atalho').forEach((b) => b.addEventListener('click', () => {
        if (botaoConectar) { botaoConectar.scrollIntoView({ behavior: 'smooth', block: 'center' }); botaoConectar.focus(); if (!botaoConectar.disabled) conectar(); }
    }, op));

    /* ---------- Enviar teste ---------- */
    const botaoTeste = $('#wa-testar');
    if (botaoTeste) {
        botaoTeste.addEventListener('click', async () => {
            esperando(botaoTeste, true);
            try {
                await chamar('/whatsapp/test', { metodo: 'POST', dados: {} });
                D2UI.toast('Mensagem de teste enviada.', { tipo: 'ok' });
            } catch (erro) {
                D2UI.toast((erro.dados && erro.dados.message) || 'Falha ao enviar. Verifique a conexão e os contatos.', { tipo: 'erro' });
            } finally {
                esperando(botaoTeste, false);
            }
        }, op);
    }

    /* ---------- Desconectar ---------- */
    const formDesconectar = $('#wa-desconectar-form');
    if (formDesconectar) {
        formDesconectar.addEventListener('submit', async (ev) => {
            if (formDesconectar.dataset.confirmado === '1') return;
            // Segura o envio (inclusive o da casca) até confirmar.
            ev.preventDefault();
            ev.stopPropagation();
            const ok = await D2UI.confirmar({
                titulo: 'Desconectar WhatsApp',
                texto: 'Desvincular o WhatsApp desta conta? Para voltar a receber alertas será preciso ler o código QR de novo.',
                perigo: true,
                palavra: 'desconectar',
                rotuloOk: 'Desconectar',
            });
            if (!ok) return;
            esperando($('#wa-desconectar'), true);
            enviarConfirmado(formDesconectar);
        }, op);
    }

    /* ---------- Remover contato ---------- */
    raiz.querySelectorAll('form.js-wa-remover').forEach((form) => {
        form.addEventListener('submit', async (ev) => {
            if (form.dataset.confirmado === '1') return;
            ev.preventDefault();
            ev.stopPropagation();
            const ok = await D2UI.confirmar({
                titulo: 'Remover contato',
                texto: `Remover ${form.dataset.telefone || 'este contato'}? Ele deixa de receber os alertas.`,
                perigo: true,
                rotuloOk: 'Remover',
            });
            if (!ok) return;
            esperando(form.querySelector('button'), true);
            enviarConfirmado(form);
        }, op);
    });

    /* ---------- Celular: máscara e validação (DDD + 9 + 8 dígitos) ---------- */
    const campo = $('#wa-telefone');
    if (campo) {
        const dica = $('#wa-telefone-dica');
        const botao = $('#wa-adicionar');
        const form = $('#wa-contato-form');
        // O prefixo +55 que a própria máscara escreve não conta como dígito digitado (senão vira DDD 55).
        const nacional = (v) => {
            let s = String(v || '').trim();
            if (s.startsWith('+55')) s = s.slice(3);
            let d = s.replace(/\D/g, '');
            if (d.startsWith('55') && d.length > 11) d = d.slice(2);
            return d.slice(0, 11);
        };
        const formatar = (v) => {
            const n = nacional(v);
            if (!n) return '';
            let s = '+55 (' + n.slice(0, 2);
            if (n.length >= 2) s += ') ';
            if (n.length > 2) s += n.slice(2, 7);
            if (n.length > 7) s += '-' + n.slice(7, 11);
            return s;
        };
        const valido = (v) => /^[1-9]{2}9[0-9]{8}$/.test(nacional(v));
        const atualizar = () => {
            const n = nacional(campo.value);
            campo.value = formatar(campo.value);
            const ok = valido(campo.value);
            if (n && !ok) campo.setAttribute('aria-invalid', 'true'); else campo.removeAttribute('aria-invalid');
            dica.classList.toggle('is-ok', ok);
            dica.classList.toggle('is-erro', !!n && !ok);
            dica.textContent = !n ? 'Ex.: (11) 99999-9999' : (ok ? 'Número válido.' : 'Número incompleto: DDD, 9 e mais 8 dígitos (11 no total).');
            botao.disabled = !ok;
        };
        campo.addEventListener('input', atualizar, op);
        campo.addEventListener('blur', atualizar, op);
        form.addEventListener('submit', (ev) => {
            if (!valido(campo.value)) { ev.preventDefault(); ev.stopPropagation(); atualizar(); campo.focus(); return; }
            esperando(botao, true);
        }, op);
        atualizar();
        const focar = $('#wa-focar-telefone');
        if (focar) focar.addEventListener('click', () => campo.focus(), op);
    }

    /* ---------- Preferências ---------- */
    const formPrefs = $('#wa-prefs-form');
    if (formPrefs) formPrefs.addEventListener('submit', () => esperando($('#wa-prefs-salvar'), true), op);

    const limpar = () => {
        encerrado = true;
        clearInterval(temporizador);
        clearTimeout(esperaRecarga);
        temporizador = null;
        sinal.abort();
        if (ativos.get(raiz) === limpar) ativos.delete(raiz);
    };
    ativos.set(raiz, limpar);
    return limpar;
}
