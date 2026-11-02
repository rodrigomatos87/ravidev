// Monitoramento (admin): servidor e ambientes ativos, atualizados a cada 1 s em GET /admin/monitoring/data.
// Encerrar e reiniciar usam POST /admin/monitoring/kill e /admin/monitoring/restart (campo "container").
import { chamar, D2UI } from '/assets/d2/js/casca.js';

const AMOSTRAS = 60;
const INTERVALO = 1000;
const tamanho = (b) => D2UI.formatar.tamanho(b);
const porSegundo = (b) => tamanho(b) + '/s';
const tom = (p) => (p >= 85 ? 'erro' : p >= 65 ? 'aviso' : 'ok');
const pct = (v) => Math.max(0, Math.min(100, Number(v) || 0));
const umaCasa = new Intl.NumberFormat('pt-BR', { minimumFractionDigits: 1, maximumFractionDigits: 1 });

function duracao(segundos) {
    const s = Math.max(0, Math.floor(Number(segundos) || 0));
    if (s < 60) return s + ' s';
    const d = Math.floor(s / 86400), h = Math.floor((s % 86400) / 3600), m = Math.floor((s % 3600) / 60);
    if (d > 0) return d + ' d ' + h + ' h';
    if (h > 0) return h + ' h ' + m + ' min';
    return m + ' min';
}
function hora(ts) {
    const d = ts ? new Date(ts * 1000) : new Date();
    return d.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
}

// Rótulo e tom de cada tipo de ambiente (o serviço devolve o tipo já classificado).
const TIPOS = {
    'IDE': ['IDE', 'info'],
    'Dev autônomo': ['Dev autônomo', 'aviso'],
    'Preview': ['Preview', 'ok'],
    'Terminal': ['Terminal', 'neutro'],
    'Infra': ['Sistema', 'neutro'],
};
const tipoDe = (c) => TIPOS[c.type] || ['Outro', 'neutro'];

// Limpeza da execução anterior na mesma raiz (re-execução da tela não duplica o polling).
const ativos = new WeakMap();

export function iniciar(raiz) {
    if (ativos.has(raiz)) ativos.get(raiz)();
    let encerrada = false;

    // ---------- Indicadores e medidores do servidor ----------
    function indicador(chave, texto) {
        const el = raiz.querySelector('[data-indicador="' + chave + '"]');
        if (el && el.textContent !== texto) el.textContent = texto;
    }
    function medidor(chave, valor, meta) {
        const m = raiz.querySelector('[data-medidor="' + chave + '"]');
        if (!m) return;
        const p = pct(valor);
        m.querySelector('[data-campo="pct"]').textContent = umaCasa.format(p) + '%';
        m.querySelector('[data-campo="barra"]').style.transform = 'scaleX(' + (p / 100).toFixed(3) + ')';
        const prog = m.querySelector('.d2-progresso');
        prog.className = 'd2-progresso is-' + tom(p);
        prog.setAttribute('aria-valuenow', String(Math.round(p)));
        m.querySelector('[data-campo="meta"]').textContent = meta;
    }

    // ---------- Histórico curto (60 amostras) ----------
    const historico = { rotulos: [], cpu: [], rx: [], tx: [] };
    const caixaCpu = raiz.querySelector('#grafico-cpu');
    const caixaRede = raiz.querySelector('#grafico-rede');
    caixaCpu.replaceChildren(D2UI.esqueleto(4, { bloco: true }));
    caixaRede.replaceChildren(D2UI.esqueleto(4, { bloco: true }));
    let graficoCpu = null;
    let graficoRede = null;

    function empurrar(lista, v) { lista.push(v); if (lista.length > AMOSTRAS) lista.shift(); }
    function desenharHistorico() {
        const rotulos = historico.rotulos.slice();
        const cpu = { rotulos, series: [{ nome: 'CPU', valores: historico.cpu.slice(), tom: 'info' }] };
        const rede = { rotulos, series: [
            { nome: 'Recebido', valores: historico.rx.slice(), tom: 'ok' },
            { nome: 'Enviado', valores: historico.tx.slice(), tom: 'aviso' },
        ] };
        if (!graficoCpu) {
            graficoCpu = D2UI.grafico({ tipo: 'linhas', rotulo: 'Uso de CPU do servidor no último minuto', rotuloEixo: 'Hora',
                formatar: (v) => D2UI.formatar.numero(Math.round(v * 10) / 10) + '%', ...cpu });
            graficoRede = D2UI.grafico({ tipo: 'linhas', rotulo: 'Tráfego de rede do servidor no último minuto', rotuloEixo: 'Hora',
                formatar: porSegundo, ...rede });
            caixaCpu.replaceChildren(graficoCpu.el);
            caixaRede.replaceChildren(graficoRede.el);
            caixaCpu.removeAttribute('aria-busy');
            caixaRede.removeAttribute('aria-busy');
            return;
        }
        graficoCpu.definir(cpu);
        graficoRede.definir(rede);
    }

    // ---------- Ambientes ----------
    let ambientes = [];          // última amostra
    let assinatura = null;       // conjunto de ambientes desenhado na tabela
    const porNome = new Map();

    const ACOES = {
        restart: {
            titulo: 'Reiniciar ambiente',
            texto: (r) => 'Reiniciar ' + r + '?\n\nOs processos travados são encerrados e o serviço sobe limpo. Arquivos, porta e sessão são preservados (nada de trabalho se perde).',
            rotuloOk: 'Reiniciar', perigo: false, ok: 'Ambiente reiniciado.', falha: 'Não foi possível reiniciar o ambiente.',
            url: '/admin/monitoring/restart',
        },
        kill: {
            titulo: 'Encerrar ambiente',
            texto: (r) => 'Encerrar ' + r + '?\n\nEle será removido. Ambientes de projeto (IDE, dev autônomo, preview, terminal) sobem de novo sob demanda.',
            rotuloOk: 'Encerrar', perigo: true, ok: 'Ambiente encerrado.', falha: 'Não foi possível encerrar o ambiente.',
            url: '/admin/monitoring/kill',
        },
    };
    function mensagemErro(acao, e) {
        if (!e || !e.status) return 'Sem resposta do servidor. Confira a conexão e tente de novo.';
        if (e.status === 400) return 'Ambiente inválido.';
        if (e.status === 403) return 'Este ambiente é essencial e não pode ser ' + (acao === 'kill' ? 'encerrado' : 'reiniciado') + ' por aqui.';
        if (e.status === 404) return acao === 'kill' ? 'Este ambiente não está mais em execução.' : 'Ambiente não encontrado.';
        return ACOES[acao].falha + ' A operação pode ter excedido o tempo; tente de novo em instantes.';
    }
    async function executar(acao, c) {
        const a = ACOES[acao];
        const descricao = tipoDe(c)[0] + (c.project ? ' · ' + c.project : '') + ' (' + c.name + ')';
        const ok = await D2UI.confirmar({ titulo: a.titulo, texto: a.texto(descricao), perigo: a.perigo, rotuloOk: a.rotuloOk });
        if (!ok) return;
        const prog = D2UI.progresso({ titulo: acao === 'kill' ? 'Encerrando o ambiente' : 'Reiniciando o ambiente' });
        try {
            await chamar(a.url, { metodo: 'POST', formulario: { container: c.name } });
            prog.concluir(a.ok);
            D2UI.toast(a.ok, 'ok');
            if (!encerrada) atualizar();
        } catch (e) {
            prog.falhar(mensagemErro(acao, e));
        }
    }

    // Células "vivas": marcadas com data-amb/data-vivo e reescritas a cada amostra sem recarregar a tabela.
    function vivo(c, campo, conteudo) {
        const s = document.createElement('span');
        s.dataset.amb = c.name;
        s.dataset.vivo = campo;
        preencher(s, c);
        return conteudo ? conteudo(s) : s;
    }
    function preencher(s, c) {
        switch (s.dataset.vivo) {
            case 'uptime': s.textContent = duracao(c.uptime_seconds); break;
            case 'cpu': {
                const v = Number(c.cpu_percent) || 0;
                s.textContent = umaCasa.format(v) + '%';
                s.className = 'monit-cpu d2-num is-' + tom(v);
                break;
            }
            case 'memoria': {
                const p = pct(c.mem_percent);
                const barra = s.querySelector('[data-campo="barra"]');
                if (barra) {
                    barra.style.transform = 'scaleX(' + (p / 100).toFixed(3) + ')';
                    const prog = s.querySelector('.d2-progresso');
                    prog.className = 'd2-progresso is-' + tom(p);
                    prog.setAttribute('aria-valuenow', String(Math.round(p)));
                    s.querySelector('[data-campo="texto"]').textContent = tamanho(c.mem_bytes) + (c.mem_limit_bytes ? ' / ' + tamanho(c.mem_limit_bytes) : '');
                }
                break;
            }
            case 'disco': s.textContent = tamanho(c.disk_rw_bytes); break;
            default: break;
        }
    }
    function celulaMemoria(c) {
        const s = document.createElement('span');
        s.className = 'monit-mem';
        s.dataset.amb = c.name;
        s.dataset.vivo = 'memoria';
        const prog = document.createElement('span');
        prog.className = 'd2-progresso is-ok';
        prog.setAttribute('role', 'progressbar');
        prog.setAttribute('aria-valuemin', '0');
        prog.setAttribute('aria-valuemax', '100');
        prog.setAttribute('aria-label', 'Memória de ' + c.name);
        const trilho = document.createElement('span');
        trilho.className = 'd2-progresso-trilho';
        const barra = document.createElement('span');
        barra.className = 'd2-progresso-barra';
        barra.dataset.campo = 'barra';
        trilho.append(barra);
        prog.append(trilho);
        const texto = document.createElement('span');
        texto.className = 'monit-mem-texto';
        texto.dataset.campo = 'texto';
        s.append(prog, texto);
        preencher(s, c);
        return s;
    }
    function celulaNome(c) {
        const s = document.createElement('span');
        s.className = 'monit-nome-linha';
        const nome = document.createElement('span');
        nome.className = 'monit-nome';
        nome.textContent = c.name;
        s.append(nome);
        if (c.state === 'paused') s.append(D2UI.selo('em standby', 'info', { ponto: false, dica: 'IDE congelada na memória; volta idêntica ao reabrir' }));
        return s;
    }

    const caixaTabela = raiz.querySelector('#tabela-ambientes');
    const atual = (c) => porNome.get(c.name) || c;
    const tabela = D2UI.tabela({
        id: 'admin-monitoramento',
        chave: 'name',
        colunas: [
            { chave: 'type', titulo: 'Tipo', fixa: true, ordenavel: true, render: (c) => { const [r, t] = tipoDe(c); return D2UI.selo(r, t, { ponto: false }); } },
            { chave: 'project', titulo: 'Projeto', ordenavel: true, render: (c) => c.project || '' },
            { chave: 'user', titulo: 'Usuário', ordenavel: true, render: (c) => c.user || '' },
            { chave: 'name', titulo: 'Nome', ordenavel: true, render: celulaNome },
            { chave: 'uptime_seconds', titulo: 'Ligado há', ordenavel: true, render: (c) => vivo(atual(c), 'uptime') },
            { chave: 'cpu_percent', titulo: 'CPU', alinhar: 'direita', ordenavel: true, render: (c) => vivo(atual(c), 'cpu') },
            { chave: 'mem_bytes', titulo: 'Memória', ordenavel: true, render: (c) => celulaMemoria(atual(c)) },
            { chave: 'disk_rw_bytes', titulo: 'Disco', alinhar: 'direita', ordenavel: true, render: (c) => vivo(atual(c), 'disco') },
        ],
        acoesLinha: [
            { rotulo: 'Reiniciar', icone: 'atualizar', bloqueio: (c) => (c.killable ? null : 'Ambiente essencial do sistema: não pode ser reiniciado por aqui'), fn: (c) => executar('restart', atual(c)) },
            { rotulo: 'Encerrar', icone: 'lixeira', perigo: true, bloqueio: (c) => (c.killable ? null : 'Ambiente essencial do sistema: não pode ser encerrado por aqui'), fn: (c) => executar('kill', atual(c)) },
        ],
        cartao: () => null,
        vazio: { chave: 'Nenhum ambiente em execução', texto: 'Os ambientes de projeto sobem sob demanda quando alguém abre a IDE, o dev autônomo, um preview ou um terminal.', icone: 'computador', acao: { rotulo: 'Atualizar agora', icone: 'atualizar', fn: () => atualizar() } },
        carregar: async ({ ordem }) => {
            const itens = ambientes.slice();
            if (ordem && ordem.chave) {
                const k = ordem.chave;
                const sinal = ordem.sentido === 'desc' ? -1 : 1;
                itens.sort((a, b) => {
                    const va = a[k], vb = b[k];
                    if (typeof va === 'number' || typeof vb === 'number') return ((Number(va) || 0) - (Number(vb) || 0)) * sinal;
                    return String(va || '').localeCompare(String(vb || ''), 'pt-BR') * sinal;
                });
            }
            return { itens, cursor_proximo: null };
        },
    });
    let primeiraCarga = true;
    caixaTabela.replaceChildren(D2UI.esqueleto(4));

    function atualizarAmbientes(lista) {
        ambientes = lista;
        porNome.clear();
        for (const c of lista) porNome.set(c.name, c);
        // Só redesenha a tabela quando o conjunto (ou o estado/permissão) muda; o resto é atualizado no lugar.
        const nova = lista.map((c) => c.name + '|' + c.state + '|' + (c.killable ? 1 : 0) + '|' + c.type + '|' + c.project + '|' + c.user).sort().join('\n');
        if (primeiraCarga) {
            primeiraCarga = false;
            assinatura = nova;
            caixaTabela.replaceChildren(tabela.el);
            caixaTabela.removeAttribute('aria-busy');
            // A tabela carregou ao ser criada, antes da primeira amostra (lista vazia): recarrega
            // agora com os ambientes reais, senão fica em "nenhum ambiente" até o conjunto mudar.
            tabela.recarregar();
            return;
        }
        if (nova !== assinatura) {
            assinatura = nova;
            tabela.recarregar();
            return;
        }
        for (const s of tabela.el.querySelectorAll('[data-vivo]')) {
            const c = porNome.get(s.dataset.amb);
            if (c) preencher(s, c);
        }
    }

    // ---------- Selo "ao vivo" ----------
    const selo = raiz.querySelector('#selo-ao-vivo');
    let falhas = 0;
    function estadoAoVivo(ok) {
        if (!selo) return;
        const ponto = selo.querySelector('.d2-ponto');
        const texto = selo.querySelector('.d2-selo-texto');
        if (ok) {
            selo.className = 'd2-selo d2-selo-ok';
            selo.dataset.tom = 'ok';
            if (ponto) ponto.classList.add('is-pulsando');
            texto.textContent = 'ao vivo';
            selo.dataset.tooltip = 'Atualiza a cada segundo';
        } else {
            selo.className = 'd2-selo d2-selo-erro';
            selo.dataset.tom = 'erro';
            if (ponto) ponto.classList.remove('is-pulsando');
            texto.textContent = 'sem resposta';
            selo.dataset.tooltip = 'O servidor não respondeu às últimas leituras; os números podem estar desatualizados';
        }
    }

    // ---------- Ciclo de leitura ----------
    let emAndamento = false;
    async function atualizar() {
        if (emAndamento || encerrada) return;
        emAndamento = true;
        try {
            const d = await chamar('/admin/monitoring/data');
            if (encerrada) return; // a tela fechou enquanto a leitura corria
            if (!d || !d.host) throw new Error('sem dados');
            if (falhas >= 3) estadoAoVivo(true);
            falhas = 0;
            const h = d.host;
            medidor('cpu', h.cpu_percent, (h.ncpu || '?') + ' núcleos');
            medidor('ram', h.ram_percent, tamanho(h.ram_used) + ' / ' + tamanho(h.ram_total));
            medidor('disco', h.disk_percent, tamanho(h.disk_used) + ' / ' + tamanho(h.disk_total));
            indicador('uptime', duracao(h.uptime_seconds));
            indicador('carga', (h.load || []).map((v) => D2UI.formatar.numero(v)).join('  ·  ') || '0');
            indicador('ambientes', String(d.count != null ? d.count : (d.containers || []).length));
            indicador('rede', '↓ ' + porSegundo(h.net_rx_bps) + '  ↑ ' + porSegundo(h.net_tx_bps));
            const iface = raiz.querySelector('#rede-interface');
            if (iface && h.net_iface && iface.textContent !== 'Interface ' + h.net_iface) iface.textContent = 'Interface ' + h.net_iface;
            empurrar(historico.rotulos, hora(d.ts));
            empurrar(historico.cpu, Number(h.cpu_percent) || 0);
            empurrar(historico.rx, Number(h.net_rx_bps) || 0);
            empurrar(historico.tx, Number(h.net_tx_bps) || 0);
            desenharHistorico();
            atualizarAmbientes(d.containers || []);
        } catch (e) {
            if (encerrada) return;
            falhas++;
            if (falhas === 3) estadoAoVivo(false);
        } finally {
            emAndamento = false;
        }
    }

    atualizar();
    const ciclo = setInterval(atualizar, INTERVALO);

    // Limpeza: para o polling de 1 s e descarta leituras que ainda estejam em voo.
    const limpar = () => {
        encerrada = true;
        clearInterval(ciclo);
        if (ativos.get(raiz) === limpar) ativos.delete(raiz);
    };
    ativos.set(raiz, limpar);
    return limpar;
}
