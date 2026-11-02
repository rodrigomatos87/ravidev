// Licenças do Claude Code: cadastro e edição em modal, teste, exclusão com palavra,
// atribuição (global e por usuário), roteamento e barras de uso do plano.
import {
    chamar, D2UI, criarTexto, el, comEspera, abrirFormulario, ligarExclusao,
    testarLicenca, ligarSeletorQueSalva, ligarCopiar, abrirAjuda, ligarFormulariosComEspera,
} from '/assets/js/paginas/licencas-comum.js';

// Limpeza da execução anterior na mesma raiz (re-execução da tela não duplica ouvintes).
const ativos = new WeakMap();

export function iniciar(raiz, dados) {
    if (ativos.has(raiz)) ativos.get(raiz)();
    const controle = new AbortController();
    const sinal = controle.signal;
    dados = dados || {};
    const tx = criarTexto(dados.textos);
    const licencas = new Map((dados.licencas || []).map((l) => [String(l.id), l]));

    // ---------- Cadastro e edição ----------
    function abrirLicenca(id) {
        const l = id ? licencas.get(String(id)) : null;
        abrirFormulario(raiz, {
            modeloId: 'modelo-form-licenca',
            titulo: l ? tx('editar_titulo') : tx('nova_titulo'),
            rotuloOk: l ? tx('salvar') : tx('cadastrar'),
            rotuloCancelar: tx('cancelar'),
            preencher: (form) => {
                const token = form.querySelector('[name="token"]');
                const rotuloToken = form.querySelector('label[for="lic-token"]');
                if (l) {
                    form.action = '/admin/licenses/' + l.id + '/update';
                    form.querySelector('[name="name"]').value = l.nome || '';
                    form.querySelector('[name="note"]').value = l.nota || '';
                    form.querySelector('[name="reset_dow"]').value = l.reset_dia === null ? '' : String(l.reset_dia);
                    form.querySelector('[name="reset_hour"]').value = l.reset_hora === null ? '' : String(l.reset_hora);
                    form.querySelector('[name="limit_pct"]').value = l.teto === null ? '' : String(l.teto);
                    token.required = false;
                    if (rotuloToken) rotuloToken.textContent = rotuloToken.dataset.rotuloEditar;
                } else {
                    form.action = '/admin/licenses/create';
                    token.required = true;
                    if (rotuloToken) rotuloToken.textContent = rotuloToken.dataset.rotuloNovo;
                }
            },
        });
    }

    raiz.addEventListener('click', (ev) => {
        const b = ev.target.closest('[data-acao]');
        if (!b || !raiz.contains(b)) return;
        const acao = b.dataset.acao;
        if (acao === 'nova-licenca') abrirLicenca(null);
        else if (acao === 'editar') abrirLicenca(b.dataset.id);
        else if (acao === 'testar') testarLicenca(b, '/admin/licenses/' + b.dataset.id + '/test', tx);
        else if (acao === 'ajuda-token') abrirAjuda(raiz, 'modelo-ajuda-token', tx('ajuda_titulo'), tx('fechar'), tx);
        else if (acao === 'add-failover') adicionarLinha('lista-failover', 'modelo-failover');
        else if (acao === 'add-modelo') adicionarLinha('lista-modelos', 'modelo-backup-modelo');
    }, { signal: sinal });

    ligarExclusao(raiz, sinal, { titulo: tx('excluir_titulo'), texto: tx('excluir_texto'), rotuloOk: tx('excluir_ok') });
    ligarFormulariosComEspera(raiz, sinal);
    ligarCopiar(raiz, tx, sinal);

    // ---------- Atribuição ----------
    raiz.querySelectorAll('[data-atribuir-global]').forEach((sel) => {
        const escopo = sel.dataset.atribuirGlobal;
        ligarSeletorQueSalva(sel, async (valor) => {
            await chamar('/admin/licenses/assign', { metodo: 'POST', formulario: { scope: escopo, license_id: valor } });
            D2UI.toast(tx('salvo'), { tipo: 'ok' });
        }, sinal);
    });

    raiz.querySelectorAll('[data-atribuir-usuario]').forEach((sel) => {
        const usuario = sel.dataset.atribuirUsuario;
        const escopo = sel.dataset.escopo;
        ligarSeletorQueSalva(sel, async (valor) => {
            if (escopo === 'chatgpt') {
                await chamar('/admin/licenses/chatgpt/assign-user', { metodo: 'POST', formulario: { user_id: usuario, license_id: valor } });
                D2UI.toast(tx('salvo_gpt_usuario'), { tipo: 'ok' });
            } else {
                await chamar('/admin/licenses/assign-user', { metodo: 'POST', formulario: { user_id: usuario, scope: escopo, license_id: valor } });
                D2UI.toast(tx('salvo_usuario'), { tipo: 'ok' });
            }
        }, sinal);
    });

    // ---------- Roteamento: modos e linhas dinâmicas ----------
    function ligarModos(formId, atributo) {
        const form = raiz.querySelector('#' + formId);
        if (!form) return;
        const mostrar = () => {
            const modo = (form.querySelector('input[name="mode"]:checked') || {}).value || 'off';
            form.querySelectorAll('[' + atributo + ']').forEach((bloco) => { bloco.hidden = bloco.getAttribute(atributo) !== modo; });
        };
        form.querySelectorAll('input[name="mode"]').forEach((r) => r.addEventListener('change', mostrar, { signal: sinal }));
        mostrar();
    }
    ligarModos('rot-api', 'data-modo-api');
    ligarModos('rot-auto', 'data-modo-auto');

    function adicionarLinha(listaId, modeloId) {
        const lista = raiz.querySelector('#' + listaId);
        const modelo = raiz.querySelector('#' + modeloId);
        if (!lista || !modelo) return;
        lista.appendChild(modelo.content.cloneNode(true));
        const nova = lista.lastElementChild;
        const campo = nova && nova.querySelector('select');
        if (campo) campo.focus();
    }

    ['lista-failover', 'lista-modelos'].forEach((id) => {
        const lista = raiz.querySelector('#' + id);
        if (!lista) return;
        lista.addEventListener('click', (ev) => {
            const b = ev.target.closest('[data-linha]');
            if (!b) return;
            const linha = b.closest('.licencas-linha');
            if (!linha) return;
            D2UI.dica.esconder();
            if (b.dataset.linha === 'remover') linha.remove();
            else if (b.dataset.linha === 'subir' && linha.previousElementSibling) lista.insertBefore(linha, linha.previousElementSibling);
            else if (b.dataset.linha === 'descer' && linha.nextElementSibling) lista.insertBefore(linha.nextElementSibling, linha);
        }, { signal: sinal });
    });

    // ---------- Uso do plano ----------
    const caixasUso = raiz.querySelectorAll('[data-uso-licenca]');
    const botaoUso = raiz.querySelector('#uso-atualizar');
    const quandoUso = raiz.querySelector('#uso-quando');

    function nivel(u, status, teto) {
        if (status === 'rejected' || u >= 0.9 || (teto && Math.floor(u * 100) >= teto)) return 'erro';
        if (status === 'allowed_warning' || u >= 0.75) return 'aviso';
        return 'ok';
    }
    function dataCurta(epoch) {
        return new Date(epoch * 1000).toLocaleString('pt-BR', { weekday: 'short', day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
    }
    function faltaPara(epoch) {
        const s = epoch - Math.floor(Date.now() / 1000);
        if (s <= 0) return tx('uso_renovando');
        const d = Math.floor(s / 86400), h = Math.floor((s % 86400) / 3600), m = Math.floor((s % 3600) / 60);
        let tempo;
        if (d > 0) tempo = d + 'd ' + h + 'h';
        else if (h > 0) tempo = h + 'h ' + String(m).padStart(2, '0') + 'min';
        else tempo = Math.max(m, 1) + ' min';
        return tx('uso_renova', { tempo });
    }
    function perto(epoch) {
        const s = epoch - Math.floor(Date.now() / 1000);
        return s > 0 && s <= 900;
    }
    function atualizarContagens() {
        raiz.querySelectorAll('.licenca-uso-eta[data-reset]').forEach((e) => {
            const r = Number(e.dataset.reset) || 0;
            e.textContent = faltaPara(r);
            e.classList.toggle('is-perto', perto(r));
        });
    }

    function linhaUso(rotulo, janela, extra, teto) {
        const u = Math.max(0, Math.min(1, Number(janela.utilization) || 0));
        const pct = Math.round(u * 100);
        const tom = nivel(u, janela.status, teto);
        let dica = tx('uso_dica', { rotulo, pct });
        if (janela.reset) dica += ', ' + tx('uso_dica_renova', { quando: dataCurta(Number(janela.reset)) });
        if (janela.status === 'rejected') dica += ' ' + tx('uso_rejeitado');
        if (teto) dica += '. ' + (Math.floor(u * 100) >= teto ? tx('uso_no_teto', { n: teto }) : tx('uso_teto', { n: teto }));
        if (extra) dica += '. ' + extra;
        const escala = (pct > 0 ? Math.max(pct, 2) : 0) / 100;
        const eta = janela.reset
            ? el('span', { class: 'licenca-uso-eta' + (perto(Number(janela.reset) || 0) ? ' is-perto' : ''), 'data-reset': String(Number(janela.reset) || 0), texto: faltaPara(Number(janela.reset) || 0) })
            : null;
        return el('div', { class: 'd2-progresso licenca-uso-linha is-' + tom, role: 'progressbar', 'aria-valuemin': '0', 'aria-valuemax': '100', 'aria-valuenow': String(pct), 'aria-label': rotulo, 'data-tooltip': dica },
            el('div', { class: 'd2-progresso-info' },
                el('span', { class: 'licenca-uso-rotulo' }, el('span', { texto: rotulo }), eta),
                el('span', { class: 'd2-progresso-pct', texto: pct + '%' })),
            el('div', { class: 'd2-progresso-trilho' },
                el('div', { class: 'd2-progresso-barra', style: 'transform: scaleX(' + escala.toFixed(3) + ')' }),
                teto ? el('span', { class: 'licenca-uso-teto', style: 'left: ' + teto + '%', 'aria-hidden': 'true' }) : null));
    }

    function aviso(texto, dica) {
        return el('p', { class: 'licenca-uso-aviso', texto, 'data-tooltip': dica || null });
    }

    function desenharUso(uso) {
        let maisAntigo = null;
        let maiorSessao = null;
        let maiorSemana = null;
        caixasUso.forEach((caixa) => {
            const snap = uso[caixa.dataset.usoLicenca];
            caixa.replaceChildren();
            if (!snap) { caixa.append(aviso(tx('uso_sem_dados'))); return; }
            if (!snap.ok) { caixa.append(aviso(tx('uso_indisponivel'), snap.error || '')); return; }
            const teto = Number(caixa.dataset.teto) || 0;
            const linhas = [];
            if (snap.five_hour) {
                linhas.push(linhaUso(tx('uso_sessao'), snap.five_hour, '', teto));
                maiorSessao = Math.max(maiorSessao ?? 0, Number(snap.five_hour.utilization) || 0);
            }
            if (snap.seven_day) {
                linhas.push(linhaUso(tx('uso_semana'), snap.seven_day, '', teto));
                maiorSemana = Math.max(maiorSemana ?? 0, Number(snap.seven_day.utilization) || 0);
            }
            if (snap.fable) {
                const lido = snap.fable.observed_at
                    ? tx('uso_lido_em', { quando: new Date(snap.fable.observed_at * 1000).toLocaleString('pt-BR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }) })
                    : '';
                linhas.push(linhaUso(tx('uso_fable'), snap.fable, snap.fable.renewed ? tx('uso_fable_renovou') : lido, 0));
            } else if (linhas.length) {
                linhas.push(el('div', { class: 'licenca-uso-sem', 'data-tooltip': tx('uso_sem_fable_dica') },
                    el('span', { texto: tx('uso_fable') }), el('span', { class: 'texto-mudo', texto: tx('uso_sem_fable') })));
            }
            if (!linhas.length) { caixa.append(aviso(tx('uso_sem_dados'))); return; }
            caixa.append(...linhas);
            if (snap.fetched_at && (maisAntigo === null || snap.fetched_at < maisAntigo)) maisAntigo = snap.fetched_at;
        });
        if (quandoUso && maisAntigo) {
            quandoUso.hidden = false;
            quandoUso.textContent = tx('uso_dados_de', { hora: new Date(maisAntigo * 1000).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' }) });
        }
        const pinta = (chave, v) => {
            const alvo = raiz.querySelector('[data-indicador="' + chave + '"]');
            if (alvo) alvo.textContent = v === null ? '-' : Math.round(v * 100) + '%';
        };
        pinta('sessao', maiorSessao);
        pinta('semana', maiorSemana);
    }

    async function carregarUso(forcar) {
        if (!caixasUso.length) return;
        caixasUso.forEach((c) => c.replaceChildren(D2UI.esqueleto(2)));
        const tarefa = async () => {
            try {
                const r = await chamar('/admin/licenses/usage' + (forcar ? '?refresh=1' : ''));
                if (sinal.aborted) return;
                desenharUso((r && r.usage) || {});
            } catch (e) {
                if (sinal.aborted) return;
                caixasUso.forEach((c) => c.replaceChildren(aviso(tx('uso_falha'), e && e.message ? e.message : '')));
            }
        };
        if (botaoUso) await comEspera(botaoUso, tarefa); else await tarefa();
    }

    let relogio = 0;
    if (caixasUso.length) {
        if (botaoUso) botaoUso.addEventListener('click', () => carregarUso(true), { signal: sinal });
        carregarUso(false);
        relogio = setInterval(atualizarContagens, 30000);
    } else if (botaoUso) {
        botaoUso.hidden = true;
    }

    const limpar = () => {
        controle.abort();
        clearInterval(relogio);
        if (ativos.get(raiz) === limpar) ativos.delete(raiz);
    };
    ativos.set(raiz, limpar);
    return limpar;
}
