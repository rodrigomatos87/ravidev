// Agendamentos: lista em D2UI.tabela, criação em modal, histórico em modal, executar agora,
// ligar e desligar, excluir com confirmação. Os dados iniciais vêm do PHP em #dados-pagina.
import { chamar, D2UI } from '/assets/d2/js/casca.js';

const fonte = document.getElementById('dados-pagina');
const dados = fonte ? JSON.parse(fonte.textContent || '{}') : {};
const projetos = dados.projetos || [];
let agendamentos = dados.agendamentos || [];

const novo = (tag, classe, texto) => { const n = document.createElement(tag); if (classe) n.className = classe; if (texto !== undefined && texto !== null) n.textContent = texto; return n; };
const falhou = (erro, padrao) => D2UI.toast((erro && erro.message && erro.message !== 'Não foi possível concluir.') ? erro.message : padrao, { tipo: 'erro' });

// "2026-11-01 08:00:00" vira "01/11/2026 08:00"; vazio vira travessão de tabela (sem texto).
function dataHora(s) {
    const m = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/.exec(s || '');
    return m ? `${m[3]}/${m[2]}/${m[1]} ${m[4]}:${m[5]}` : '';
}
function quando(a) {
    if (a.quando === 'daily') return `Todo dia às ${(a.horario || '').slice(0, 5)}`;
    const n = Number(a.minutos) || 0;
    if (n % 1440 === 0) return n === 1440 ? 'A cada 1 dia' : `A cada ${n / 1440} dias`;
    if (n % 60 === 0) return n === 60 ? 'A cada 1 hora' : `A cada ${n / 60} horas`;
    return n === 1 ? 'A cada 1 minuto' : `A cada ${n} minutos`;
}
function seloTipo(a) {
    const claude = a.tipo === 'claude';
    const s = novo('span', `d2-selo d2-selo-${claude ? 'info' : 'neutro'}`);
    const p = novo('span', 'd2-ponto'); p.setAttribute('aria-hidden', 'true');
    s.append(p, novo('span', 'd2-selo-texto', claude ? 'Claude' : 'Comando'));
    return s;
}
function mudo(texto) { return texto ? novo('span', 'd2-num', texto) : novo('span', 'texto-mudo', 'Nunca'); }

// Interruptor de ativo dentro da linha.
function interruptor(a) {
    const rotulo = novo('label', 'd2-switch');
    const cb = novo('input');
    cb.type = 'checkbox';
    cb.checked = !!a.ativo;
    cb.setAttribute('aria-label', `Ativo: ${a.titulo}`);
    const trilho = novo('span', 'd2-switch-trilho'); trilho.setAttribute('aria-hidden', 'true');
    rotulo.append(cb, trilho);
    rotulo.dataset.tooltip = 'Ligar ou desligar';
    cb.addEventListener('change', async () => {
        cb.disabled = true;
        try {
            const r = await chamar(`/schedules/${a.id}/toggle`, { metodo: 'POST', formulario: {} });
            a.ativo = r && typeof r.enabled === 'boolean' ? r.enabled : cb.checked;
            cb.checked = a.ativo;
            D2UI.toast(a.ativo ? 'Agendamento ligado.' : 'Agendamento desligado.', { tipo: 'ok', duracao: 2200 });
        } catch (erro) {
            cb.checked = !cb.checked;
            falhou(erro, 'Não foi possível alterar.');
        } finally {
            cb.disabled = false;
        }
    });
    return rotulo;
}

function celulaTitulo(a) {
    const caixa = novo('div', 'agenda-titulo');
    const linha = novo('span', 'agenda-titulo-linha');
    linha.append(seloTipo(a), novo('strong', null, a.titulo));
    const conteudo = a.conteudo.length > 160 ? a.conteudo.slice(0, 160) + '…' : a.conteudo;
    caixa.append(linha, novo('code', 'agenda-conteudo', conteudo));
    return caixa;
}

/* ---------- Histórico ---------- */
async function historico(a) {
    const corpo = novo('div', 'agenda-historico');
    corpo.append(D2UI.esqueleto(4));
    const m = D2UI.modal({ titulo: `Histórico: ${a.titulo}`, corpo, largura: 720, botoes: [{ rotulo: 'Fechar', tipo: 'secundario' }] });
    let execs;
    try {
        const r = await chamar(`/schedules/${a.id}/runs`);
        execs = (r && r.runs) || [];
    } catch (erro) {
        corpo.replaceChildren(D2UI.vazio({ chave: 'Não foi possível carregar o histórico', icone: 'erro', acao: { rotulo: 'Tentar de novo', icone: 'atualizar', fn: () => { m.fechar(); historico(a); } } }));
        return;
    }
    if (!execs.length) {
        corpo.replaceChildren(D2UI.vazio({ chave: 'Sem execuções ainda', texto: 'Rode agora para ver o resultado aqui.', icone: 'relogio', acao: { rotulo: 'Executar agora', icone: 'enviar', fn: () => { m.fechar(); executar(a); } } }));
        return;
    }
    const lista = novo('ol', 'agenda-execucoes');
    for (const ex of execs) {
        const ok = ex.status === 'success' || ex.status === 'ok';
        const tipo = ok ? 'ok' : (ex.status === 'running' ? 'info' : 'erro');
        const item = novo('li', `agenda-execucao is-${tipo}`);
        const cab = novo('div', 'agenda-execucao-cab');
        const selo = novo('span', `d2-selo d2-selo-${tipo}`);
        const p = novo('span', 'd2-ponto'); p.setAttribute('aria-hidden', 'true');
        const rotulo = ok ? 'Sucesso' : (ex.status === 'running' ? 'Em andamento' : (ex.status === 'error' || ex.status === 'failed' ? 'Falhou' : String(ex.status || '')));
        selo.append(p, novo('span', 'd2-selo-texto', rotulo));
        const meta = [dataHora(ex.created_at) || ex.created_at];
        if (ex.exit_code !== null && ex.exit_code !== undefined && ex.exit_code !== '') meta.push(`código de saída ${ex.exit_code}`);
        if (ex.duration_ms) meta.push(Number(ex.duration_ms) >= 1000 ? `${(Number(ex.duration_ms) / 1000).toLocaleString('pt-BR', { maximumFractionDigits: 1 })} s` : `${ex.duration_ms} ms`);
        cab.append(selo, novo('span', 'texto-mudo d2-num', meta.join(', ')));
        item.append(cab);
        if (ex.output) item.append(novo('pre', 'codigo agenda-saida', ex.output));
        lista.append(item);
    }
    corpo.replaceChildren(lista);
}

/* ---------- Executar agora ---------- */
async function executar(a) {
    const prog = D2UI.progresso({ titulo: 'Executando agora', texto: a.tipo === 'claude' ? 'O Claude pode levar alguns minutos.' : a.titulo });
    try {
        await chamar(`/schedules/${a.id}/run`, { metodo: 'POST', formulario: {} });
        prog.concluir('Executado.');
        a.ultima = new Date().toISOString().slice(0, 19).replace('T', ' ');
        tabela.recarregar();
        setTimeout(() => historico(a), 1000);
    } catch (erro) {
        // O detalhe técnico do servidor não vai para a tela.
        prog.fechar();
        if (erro && erro.status === 404) { D2UI.toast('Agendamento não encontrado.', { tipo: 'erro' }); return; }
        D2UI.modal({
            titulo: 'A execução falhou',
            corpo: novo('p', null, 'Não foi possível executar agora. Tente de novo em instantes.'),
            perigo: true,
            largura: 440,
            botoes: [
                { rotulo: 'Fechar', tipo: 'secundario' },
                { rotulo: 'Ver histórico', tipo: 'secundario', icone: 'relogio', fn: () => { setTimeout(() => historico(a), 0); } },
                { rotulo: 'Tentar de novo', tipo: 'primario', icone: 'atualizar', fn: () => { setTimeout(() => executar(a), 0); } },
            ],
        });
    }
}

/* ---------- Excluir ---------- */
async function excluir(a) {
    const ok = await D2UI.confirmar({
        titulo: 'Excluir agendamento',
        texto: `Excluir "${a.titulo}"? O histórico de execuções vai junto.`,
        perigo: true,
        palavra: 'excluir',
        rotuloOk: 'Excluir',
    });
    if (!ok) return;
    try {
        await chamar(`/schedules/${a.id}/delete`, { metodo: 'POST', formulario: {} });
        agendamentos = agendamentos.filter((x) => x.id !== a.id);
        tabela.recarregar();
        D2UI.toast('Agendamento excluído.', { tipo: 'ok', duracao: 2200 });
    } catch (erro) {
        falhou(erro, 'Não foi possível excluir.');
    }
}

/* ---------- Criar ---------- */
const TEXTOS = {
    command: { rotulo: 'Comando ou arquivo a executar', exemplo: 'Ex.: bash scripts/backup.sh ou python3 relatorio.py', dica: 'Roda dentro da pasta do projeto, no ambiente isolado dele.' },
    claude: { rotulo: 'O que pedir ao Claude', exemplo: 'Ex.: Gere o relatório de vendas de hoje e salve em relatorios/', dica: 'O Claude trabalha no projeto escolhido, como numa conversa.' },
};
function abrirCriar() {
    const corpo = novo('form', 'd2-pilha agenda-form');
    corpo.noValidate = true;
    corpo.innerHTML = `
        <div class="form-grade">
            <div class="d2-field">
                <label class="d2-label" for="ag-tipo">Tipo</label>
                <select class="d2-control" id="ag-tipo" name="kind">
                    <option value="command">Executar arquivo ou comando</option>
                    <option value="claude">Acionar o Claude</option>
                </select>
            </div>
            <div class="d2-field">
                <label class="d2-label" for="ag-projeto">Projeto</label>
                <select class="d2-control" id="ag-projeto" name="project_id"></select>
            </div>
            <div class="d2-field is-cheio">
                <label class="d2-label" for="ag-titulo">Título</label>
                <input type="text" class="d2-control" id="ag-titulo" name="title" maxlength="200" placeholder="Ex.: Backup diário ou Relatório matinal" aria-describedby="ag-titulo-erro">
                <div class="d2-field-erro" id="ag-titulo-erro"></div>
            </div>
            <div class="d2-field is-cheio">
                <label class="d2-label" for="ag-conteudo" id="ag-conteudo-rotulo"></label>
                <textarea class="d2-control d2-mono" id="ag-conteudo" name="payload" rows="3" aria-describedby="ag-conteudo-dica ag-conteudo-erro"></textarea>
                <span class="d2-field-hint" id="ag-conteudo-dica"></span>
                <div class="d2-field-erro" id="ag-conteudo-erro"></div>
            </div>
            <div class="d2-field">
                <label class="d2-label" for="ag-quando">Quando</label>
                <select class="d2-control" id="ag-quando" name="schedule_type">
                    <option value="interval">A cada intervalo</option>
                    <option value="daily">Todo dia em um horário</option>
                </select>
            </div>
            <div class="d2-field" id="ag-intervalo-caixa">
                <label class="d2-label" for="ag-intervalo">A cada (minutos)</label>
                <input type="number" class="d2-control d2-num" id="ag-intervalo" name="interval_minutes" value="60" min="1" max="43200" aria-describedby="ag-intervalo-dica ag-intervalo-erro">
                <span class="d2-field-hint" id="ag-intervalo-dica">De 1 minuto a 30 dias (43.200 minutos).</span>
                <div class="d2-field-erro" id="ag-intervalo-erro"></div>
            </div>
            <div class="d2-field" id="ag-horario-caixa" hidden>
                <label class="d2-label" for="ag-horario">Horário</label>
                <input type="time" class="d2-control d2-num" id="ag-horario" name="daily_time" value="08:00">
            </div>
        </div>`;
    const sel = corpo.querySelector('#ag-projeto');
    for (const p of projetos) { const o = novo('option', null, p.nome); o.value = String(p.id); sel.append(o); }
    const tipo = corpo.querySelector('#ag-tipo');
    const conteudo = corpo.querySelector('#ag-conteudo');
    const aplicarTipo = () => {
        const tx = TEXTOS[tipo.value] || TEXTOS.command;
        corpo.querySelector('#ag-conteudo-rotulo').textContent = tx.rotulo;
        conteudo.placeholder = tx.exemplo;
        corpo.querySelector('#ag-conteudo-dica').textContent = tx.dica;
    };
    aplicarTipo();
    tipo.addEventListener('change', aplicarTipo);
    const quandoSel = corpo.querySelector('#ag-quando');
    quandoSel.addEventListener('change', () => {
        corpo.querySelector('#ag-intervalo-caixa').hidden = quandoSel.value !== 'interval';
        corpo.querySelector('#ag-horario-caixa').hidden = quandoSel.value !== 'daily';
    });

    const erroEm = (id, msg) => {
        const el = corpo.querySelector(`#${id}-erro`);
        if (el) el.textContent = msg || '';
        const campo = corpo.querySelector(`#${id}`);
        if (campo) { if (msg) campo.setAttribute('aria-invalid', 'true'); else campo.removeAttribute('aria-invalid'); }
    };
    async function salvar() {
        const titulo = corpo.querySelector('#ag-titulo').value.trim();
        const texto = conteudo.value.trim();
        const minutos = parseInt(corpo.querySelector('#ag-intervalo').value, 10);
        erroEm('ag-titulo'); erroEm('ag-conteudo'); erroEm('ag-intervalo');
        let valido = true;
        if (!titulo) { erroEm('ag-titulo', 'Informe um título.'); valido = false; }
        if (!texto) { erroEm('ag-conteudo', tipo.value === 'claude' ? 'Informe o que pedir ao Claude.' : 'Informe o comando ou arquivo a executar.'); valido = false; }
        if (quandoSel.value === 'interval' && (Number.isNaN(minutos) || minutos < 1 || minutos > 43200)) { erroEm('ag-intervalo', 'Use um valor entre 1 e 43.200 minutos.'); valido = false; }
        if (!valido) { const primeiro = corpo.querySelector('[aria-invalid]'); if (primeiro) primeiro.focus(); return false; }
        try {
            await chamar('/schedules', {
                metodo: 'POST',
                formulario: {
                    kind: tipo.value,
                    project_id: sel.value,
                    title: titulo,
                    payload: texto,
                    schedule_type: quandoSel.value,
                    interval_minutes: String(Number.isNaN(minutos) ? 60 : minutos),
                    daily_time: corpo.querySelector('#ag-horario').value || '08:00',
                },
            });
        } catch (erro) {
            falhou(erro, 'Não foi possível criar o agendamento.');
            return false;
        }
        // A próxima execução é calculada no servidor: recarrega a lista pintada por ele.
        D2UI.progresso({ titulo: 'Agendamento criado', texto: 'Atualizando a lista' });
        location.reload();
        return false;
    }
    corpo.addEventListener('submit', (ev) => { ev.preventDefault(); });
    D2UI.modal({
        titulo: 'Novo agendamento',
        corpo,
        largura: 620,
        botoes: [
            { rotulo: 'Cancelar', tipo: 'secundario' },
            { rotulo: 'Criar agendamento', tipo: 'primario', icone: 'check', fn: salvar },
        ],
    });
    setTimeout(() => corpo.querySelector('#ag-titulo').focus(), 50);
}

/* ---------- Cartão (celular) ---------- */
function cartao(a) {
    const art = novo('article', 'd2-cartao agenda-cartao');
    const cab = novo('div', 'agenda-cartao-cab');
    cab.append(celulaTitulo(a), interruptor(a));
    const dl = novo('dl', 'd2-tabela-cartao-campos');
    const campo = (rotulo, valor) => { dl.append(novo('dt', null, rotulo)); const dd = novo('dd'); dd.append(valor); dl.append(dd); };
    campo('Projeto', a.projeto || '');
    campo('Quando', quando(a));
    campo('Última', mudo(dataHora(a.ultima)));
    campo('Próxima', a.ativo ? mudo(dataHora(a.proxima)) : novo('span', 'texto-mudo', 'Desligado'));
    art.append(cab, dl);
    return art;
}

/* ---------- Tabela ---------- */
const alvo = document.getElementById('agenda-tabela');
let tabela = null;
if (alvo) {
    tabela = D2UI.tabela({
        id: 'agendamentos',
        rotulo: 'Agendamentos',
        colunas: [
            { chave: 'titulo', titulo: 'Agendamento', fixa: true, render: celulaTitulo },
            { chave: 'projeto', titulo: 'Projeto' },
            { chave: 'quando', titulo: 'Quando', render: (a) => quando(a) },
            { chave: 'ultima', titulo: 'Última', render: (a) => mudo(dataHora(a.ultima)) },
            { chave: 'proxima', titulo: 'Próxima', render: (a) => (a.ativo ? mudo(dataHora(a.proxima)) : novo('span', 'texto-mudo', 'Desligado')) },
            { chave: 'ativo', titulo: 'Ativo', alinhar: 'centro', render: interruptor },
        ],
        carregar: async () => ({ itens: agendamentos.slice(), cursor_proximo: null }),
        cartao,
        chave: 'id',
        acoesLinha: [
            { rotulo: 'Executar agora', icone: 'enviar', fn: executar },
            { rotulo: 'Histórico', icone: 'relogio', fn: historico },
            { rotulo: 'Excluir', icone: 'lixeira', perigo: true, fn: excluir },
        ],
        vazio: {
            chave: 'Nenhum agendamento ainda',
            texto: 'Crie o primeiro para rodar um comando ou acionar o Claude sozinho.',
            icone: 'calendario',
            acao: { rotulo: 'Novo agendamento', icone: 'mais', fn: abrirCriar },
        },
    });
    alvo.append(tabela.el);
}
const botaoNovo = document.getElementById('agenda-novo');
if (botaoNovo) botaoNovo.addEventListener('click', abrirCriar);
