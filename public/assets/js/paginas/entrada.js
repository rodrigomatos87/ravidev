// Telas de entrada (login, cadastro, senha, duas etapas) e páginas de erro.
// Liga: tema, notificações do flash, mostrar senha, aviso de Caps Lock, botão em espera no envio,
// força da senha, código de 6 dígitos e reenvio da confirmação de e-mail.
import { D2UI } from '/assets/d2/js/d2ui.js';
import { Tema } from '/assets/d2/js/tema.js';

const dados = (() => {
    try { return JSON.parse(document.getElementById('dados-pagina')?.textContent || '{}'); } catch (e) { return {}; }
})();
const textos = dados.textos || {};
const tx = (chave, padrao) => textos[chave] || padrao;
const SPRITE = '/assets/d2/icones/sprite.svg';

D2UI.configurar({
    t: (chave, params) => {
        const v = textos[chave];
        if (typeof v !== 'string') return '';
        return params ? v.replace(/\{(\w+)\}/g, (m, k) => (params[k] ?? m)) : v;
    },
    idioma: dados.idioma || 'pt',
    sprite: SPRITE,
});

function trocarIcone(svg, nome) {
    const uso = svg?.querySelector('use');
    if (uso) uso.setAttribute('href', `${SPRITE}#${nome}`);
}

// Tema: claro, escuro ou automático, no botão discreto do rodapé
function ligarTema() {
    Tema.iniciar();
    const botao = document.getElementById('entrada-tema');
    if (!botao) return;
    const icones = { auto: 'auto', claro: 'sol', escuro: 'lua' };
    const rotulos = {
        auto: tx('entrada.tema_auto', 'Tema: automático'),
        claro: tx('entrada.tema_claro', 'Tema: claro'),
        escuro: tx('entrada.tema_escuro', 'Tema: escuro'),
    };
    const pintar = () => {
        const p = Tema.preferencia();
        trocarIcone(botao.querySelector('svg'), icones[p] || 'auto');
        botao.setAttribute('aria-label', rotulos[p]);
        botao.setAttribute('data-tooltip', rotulos[p]);
    };
    botao.addEventListener('click', () => { Tema.aplicar(Tema.proximo()); pintar(); });
    pintar();
}

// Mostrar e ocultar a senha
function ligarOlhos() {
    document.querySelectorAll('[data-mostrar-senha]').forEach((botao) => {
        const campo = document.getElementById(botao.dataset.mostrarSenha);
        if (!campo) return;
        botao.addEventListener('click', () => {
            const mostrar = campo.type === 'password';
            campo.type = mostrar ? 'text' : 'password';
            botao.setAttribute('aria-pressed', mostrar ? 'true' : 'false');
            const rotulo = mostrar ? tx('entrada.ocultar_senha', 'Ocultar senha') : tx('entrada.mostrar_senha', 'Mostrar senha');
            botao.setAttribute('aria-label', rotulo);
            botao.setAttribute('data-tooltip', rotulo);
            trocarIcone(botao.querySelector('svg'), mostrar ? 'olho-fechado' : 'olho');
            campo.focus({ preventScroll: true });
        });
    });
}

// Aviso de Caps Lock ligado enquanto digita a senha
function ligarCaps() {
    document.querySelectorAll('[data-caps-de]').forEach((aviso) => {
        const campo = document.getElementById(aviso.dataset.capsDe);
        if (!campo) return;
        const conferir = (ev) => {
            if (typeof ev.getModifierState !== 'function') return;
            const ligado = ev.getModifierState('CapsLock');
            if (ligado && !aviso.childElementCount) {
                aviso.replaceChildren(D2UI.icone('alerta'), document.createTextNode(tx('entrada.caps', 'Caps Lock ativado')));
            } else if (!ligado && aviso.childElementCount) {
                aviso.replaceChildren();
            }
        };
        campo.addEventListener('keydown', conferir);
        campo.addEventListener('keyup', conferir);
        campo.addEventListener('blur', () => aviso.replaceChildren());
    });
}

// Botão em espera ao enviar (e sem envio duplo)
function marcarEnviando(form) {
    const botao = form.querySelector('button[type="submit"]');
    if (!botao) return;
    if (botao.classList.contains('d2-btn')) botao.classList.add('d2-btn-carregando');
    botao.setAttribute('aria-busy', 'true');
    // Desabilita no próximo ciclo: desabilitar já no submit tiraria o botão do envio.
    setTimeout(() => { botao.disabled = true; }, 0);
}
function ligarEnvios() {
    document.querySelectorAll('form[data-entrada-form]').forEach((form) => {
        form.addEventListener('submit', (ev) => {
            if (form.dataset.enviando === '1') { ev.preventDefault(); return; }
            form.dataset.enviando = '1';
            marcarEnviando(form);
        });
    });
    // Voltando pelo histórico, a página volta do cache com o botão em espera: libera.
    window.addEventListener('pageshow', (ev) => {
        if (!ev.persisted) return;
        document.querySelectorAll('form[data-entrada-form]').forEach((form) => {
            delete form.dataset.enviando;
            const botao = form.querySelector('button[type="submit"]');
            if (!botao) return;
            botao.classList.remove('d2-btn-carregando');
            botao.removeAttribute('aria-busy');
            botao.disabled = form.hasAttribute('data-otp-form') && (document.getElementById('tfa-code')?.value || '').length !== 6;
        });
    });
}

// Força da senha no cadastro (mesma pontuação da tela anterior)
function ligarForca() {
    document.querySelectorAll('[data-forca-de]').forEach((caixa) => {
        const campo = document.getElementById(caixa.dataset.forcaDe);
        const texto = caixa.querySelector('.entrada-forca-texto');
        if (!campo || !texto) return;
        const niveis = [0, 1, 2, 3, 4].map((n) => tx(`entrada.forca_${n}`, ''));
        campo.addEventListener('input', () => {
            const s = campo.value;
            if (!s) { caixa.removeAttribute('data-nivel'); texto.textContent = ''; return; }
            let nota = 0;
            if (s.length >= 8) nota++;
            if (/[a-z]/.test(s) && /[A-Z]/.test(s)) nota++;
            if (/\d/.test(s)) nota++;
            if (/[^a-zA-Z\d]/.test(s)) nota++;
            caixa.dataset.nivel = String(nota);
            texto.textContent = niveis[nota];
        });
    });
}

// Código de 6 dígitos: espalha ao digitar ou colar, Backspace volta, setas navegam, envia sozinho ao completar
function ligarCodigo() {
    const form = document.querySelector('form[data-otp-form]');
    if (!form) return;
    const caixas = Array.from(form.querySelectorAll('.entrada-otp-caixa'));
    const linha = form.querySelector('.entrada-otp');
    const oculto = document.getElementById('tfa-code');
    const enviar = form.querySelector('button[type="submit"]');
    let enviado = false;

    const codigo = () => caixas.map((c) => c.value).join('');
    const sincronizar = () => {
        oculto.value = codigo();
        enviar.disabled = oculto.value.length !== 6;
        caixas.forEach((c) => c.classList.toggle('is-preenchida', c.value !== ''));
        linha.classList.remove('is-invalido');
    };
    const espalhar = (inicio, digitos) => {
        for (let i = 0; i < digitos.length && inicio + i < caixas.length; i++) caixas[inicio + i].value = digitos[i];
        caixas[Math.min(inicio + digitos.length, caixas.length - 1)].focus();
        sincronizar();
        if (codigo().length === 6 && !enviado) {
            enviado = true;
            if (typeof form.requestSubmit === 'function') form.requestSubmit();
            else { marcarEnviando(form); form.submit(); }
        }
    };

    caixas.forEach((caixa, i) => {
        caixa.addEventListener('input', () => {
            const d = (caixa.value || '').replace(/\D/g, '');
            caixa.value = '';
            if (d) espalhar(i, d); else sincronizar();
        });
        caixa.addEventListener('keydown', (ev) => {
            if (ev.key === 'Backspace' && caixa.value === '' && i > 0) {
                caixas[i - 1].value = ''; caixas[i - 1].focus(); sincronizar(); ev.preventDefault();
            } else if (ev.key === 'ArrowLeft' && i > 0) { caixas[i - 1].focus(); ev.preventDefault(); }
            else if (ev.key === 'ArrowRight' && i < caixas.length - 1) { caixas[i + 1].focus(); ev.preventDefault(); }
        });
        caixa.addEventListener('paste', (ev) => {
            ev.preventDefault();
            const d = (ev.clipboardData || window.clipboardData).getData('text').replace(/\D/g, '');
            if (d) espalhar(0, d);
        });
        caixa.addEventListener('focus', () => caixa.select());
    });

    // Código errado (faixa de erro na tela): balança e marca as caixas para tentar de novo.
    if (document.querySelector('.entrada-faixas .d2-faixa-erro')) {
        linha.classList.add('is-balancando', 'is-invalido');
        setTimeout(() => linha.classList.remove('is-balancando'), 400);
    }
    sincronizar();
    if (document.querySelector('.entrada-faixas .d2-faixa-erro')) linha.classList.add('is-invalido');
}

// Reenvio da confirmação de e-mail no login: usa o e-mail digitado; vazio, pede o e-mail
function ligarReenvioConfirmacao() {
    const form = document.querySelector('form[data-reenviar-confirmacao]');
    if (!form) return;
    form.addEventListener('submit', (ev) => {
        const campo = document.getElementById('email');
        const email = (campo?.value || '').trim();
        if (!email) {
            ev.preventDefault();
            campo?.focus();
            D2UI.toast(tx('entrada.informe_email', 'Informe seu e-mail acima para reenviar a confirmação.'), { tipo: 'aviso' });
            return;
        }
        document.getElementById('resend-email').value = email;
    });
}

// Páginas de erro: voltar à página anterior (sem histórico, vai ao início)
function ligarVoltar() {
    document.querySelectorAll('[data-acao="voltar"]').forEach((botao) => {
        botao.addEventListener('click', () => {
            if (window.history.length > 1) window.history.back();
            else window.location.href = '/dashboard';
        });
    });
}

ligarTema();
ligarOlhos();
ligarCaps();
ligarEnvios();
ligarForca();
ligarCodigo();
ligarReenvioConfirmacao();
ligarVoltar();
(dados.toasts || []).forEach((m) => D2UI.toast(m.texto, { tipo: m.tipo, duracao: 8000 }));
