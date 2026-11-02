// Aba Git: ligar repositório (existente ou novo), sincronizar, histórico com volta de versão,
// sincronização automática e desvínculo. Mesmos endpoints /projects/{uuid}/git/*.
import { tx, el, botao, carregando, interruptor, chamar, D2UI } from './projeto-comum.js';

const erroDe = (e, padrao) => (e && e.dados && e.dados.error) || (e && e.message) || padrao;
const espera = (ms) => new Promise((r) => setTimeout(r, ms));

// Consulta o estado até não haver mudança pendente (2 s entre consultas, no máximo 40).
async function aguardarSincronia(base) {
    for (let i = 0; i < 40; i++) {
        await espera(2000);
        try {
            const d = await chamar(base + '/git/status');
            if (d && d.git && !d.git.pending) return true;
        } catch (e) { /* tenta de novo */ }
    }
    return false;
}

function aviso(texto, acao) {
    return D2UI.vazio({ chave: tx('projeto.git_sem_vinculo', 'Sem repositório ligado'), texto, icone: 'plataforma', acao });
}

export async function montarGit({ dados, base, recarregar: recarregarTela, irPara, ativarAba }) {
    const git = dados.git || {};
    const painel = (corpo, opcoes = {}) => D2UI.painel({ titulo: 'Git', icone: 'plataforma', area: 'projetos', ...opcoes }, ...[].concat(corpo)).el;

    if (!git.ativo) {
        if (!git.configurado) {
            return painel(aviso(tx('projeto.git_nao_configurado', 'A integração GitHub ainda não foi configurada pelo administrador.'),
                { rotulo: tx('projeto.git_ver_perfil', 'Abrir meu perfil do GitHub'), icone: 'externo', fn: () => { irPara('/settings/github'); } }));
        }
        if (!dados.dono) {
            return painel(aviso(tx('projeto.git_so_dono', 'Este projeto ainda não está ligado a um repositório. Só o dono pode configurar.'),
                { rotulo: tx('projeto.aba_visao', 'Visão geral'), icone: 'painel', fn: () => { ativarAba('visao'); } }));
        }
        if (!git.conectado) {
            return painel(aviso(tx('projeto.git_conectar_texto', 'Para versionar este projeto, conecte sua conta do GitHub no seu perfil.'),
                { rotulo: tx('projeto.git_conectar', 'Conectar GitHub no seu perfil'), icone: 'plataforma', tipo: 'primario', fn: () => { irPara('/settings/github'); } }));
        }
        return painel(formularioVinculo(base, recarregarTela), { sub: tx('projeto.git_sub', 'Versione o projeto num repositório do GitHub') });
    }
    return painelVinculado(dados, base, painel, recarregarTela);
}

function formularioVinculo(base, recarregarTela) {
    // Ligar repositório existente
    const repo = D2UI.campo({ tipo: 'selecao', nome: 'repo_full_name', rotulo: tx('projeto.git_repo', 'Repositório'), obrigatorio: true, comBusca: true, opcoes: [], placeholder: tx('projeto.git_carregando', 'Carregando repositórios…') });
    const autoExistente = D2UI.campo({ tipo: 'switch', nome: 'auto_sync', rotulo: tx('projeto.git_auto', 'Aplicar tudo automaticamente (commit + push a cada entrega)'), valor: true });
    let ramos = {};
    const recarregar = botao('', { icone: 'atualizar', dica: tx('projeto.git_recarregar', 'Recarregar repositórios'), aoClicar: () => carregarRepos() });
    async function carregarRepos() {
        const soltar = carregando(recarregar);
        try {
            const d = await chamar(base + '/git/repos');
            if (!(d && d.ok)) throw new Error((d && d.error) || '');
            const repos = d.repos || [];
            ramos = {};
            repos.forEach((r) => { ramos[r.full_name] = r.default_branch || 'main'; });
            repo.definirOpcoes(repos.map((r) => ({ valor: r.full_name, rotulo: r.full_name, icone: r.private ? 'cadeado' : null, dica: r.private ? tx('projeto.git_privado', 'Privado') : tx('projeto.git_publico', 'Público') })));
            repo.erro(repos.length ? null : tx('projeto.git_nenhum_repo', 'Nenhum repositório encontrado'));
        } catch (e) {
            repo.erro(erroDe(e, tx('projeto.git_erro_repos', 'Não foi possível carregar os repositórios.')));
        } finally { soltar(); }
    }
    const botaoLigar = botao(tx('projeto.git_ligar', 'Ligar repositório'), {
        icone: 'anexo', tipo: 'primario',
        aoClicar: async () => {
            const nome = repo.valor();
            if (!nome) { repo.erro(tx('projeto.git_selecione', 'Selecione um repositório.')); return; }
            const soltar = carregando(botaoLigar);
            try {
                const d = await chamar(base + '/git/link', { metodo: 'POST', formulario: { mode: 'existing', repo_full_name: nome, branch: ramos[nome] || '', auto_sync: autoExistente.valor() ? '1' : '0' } });
                if (!(d && d.ok)) throw new Error((d && d.error) || '');
                D2UI.toast(tx('projeto.git_vinculado', 'Repositório vinculado.'), { tipo: 'ok' });
                setTimeout(recarregarTela, 600);
            } catch (e) {
                soltar();
                D2UI.toast(erroDe(e, tx('projeto.git_erro_ligar', 'Não foi possível ligar o repositório.')), { tipo: 'erro' });
            }
        },
    });
    const existente = el('div', { class: 'form-grade' },
        el('div', { class: 'is-cheio projeto-linha-campo' }, repo, recarregar),
        el('div', { class: 'is-cheio' }, autoExistente),
        el('div', { class: 'form-acoes is-cheio' }, botaoLigar));
    carregarRepos();

    // Criar repositório novo
    const nomeNovo = D2UI.campo({ tipo: 'texto', nome: 'name', rotulo: tx('projeto.git_nome_repo', 'Nome do repositório'), placeholder: 'nome-do-repositorio', obrigatorio: true, maximo: 100 });
    const visibilidade = D2UI.campo({ tipo: 'selecao', nome: 'private', rotulo: tx('projeto.git_visibilidade', 'Visibilidade'), obrigatorio: true, valor: '1', opcoes: [
        { valor: '1', rotulo: tx('projeto.git_privado', 'Privado'), icone: 'cadeado' },
        { valor: '0', rotulo: tx('projeto.git_publico', 'Público'), icone: 'olho' },
    ] });
    const autoNovo = D2UI.campo({ tipo: 'switch', nome: 'auto_sync', rotulo: tx('projeto.git_auto', 'Aplicar tudo automaticamente (commit + push a cada entrega)'), valor: true });
    const botaoCriar = botao(tx('projeto.git_criar', 'Criar e ligar'), {
        icone: 'mais', tipo: 'primario',
        aoClicar: async () => {
            if (!nomeNovo.valor()) { nomeNovo.erro(tx('projeto.git_informe_nome', 'Informe o nome do repositório.')); nomeNovo.focar(); return; }
            const soltar = carregando(botaoCriar);
            try {
                const d = await chamar(base + '/git/link', { metodo: 'POST', formulario: { mode: 'create', name: nomeNovo.valor(), private: visibilidade.valor() || '1', auto_sync: autoNovo.valor() ? '1' : '0' } });
                if (!(d && d.ok)) throw new Error((d && d.error) || '');
                D2UI.toast(tx('projeto.git_criado', 'Repositório criado e vinculado.'), { tipo: 'ok' });
                setTimeout(recarregarTela, 600);
            } catch (e) {
                soltar();
                D2UI.toast(erroDe(e, tx('projeto.git_erro_criar', 'Não foi possível criar o repositório.')), { tipo: 'erro' });
            }
        },
    });
    const novo = el('div', { class: 'form-grade' },
        nomeNovo, visibilidade,
        el('div', { class: 'is-cheio' }, autoNovo),
        el('div', { class: 'form-acoes is-cheio' }, botaoCriar));

    return D2UI.abas([
        { id: 'existente', rotulo: tx('projeto.git_aba_existente', 'Ligar repositório existente'), icone: 'anexo', conteudo: existente },
        { id: 'novo', rotulo: tx('projeto.git_aba_novo', 'Criar repositório novo'), icone: 'mais', conteudo: novo },
    ], { rotulo: tx('projeto.git_como', 'Como ligar'), classe: 'projeto-abas-internas' });
}

function painelVinculado(dados, base, painel, recarregarTela) {
    const git = dados.git;
    let pendente = Boolean(git.pendente);
    const caixaSelo = el('span', {});
    const desenharSelo = () => {
        caixaSelo.textContent = '';
        caixaSelo.append(pendente
            ? D2UI.selo(tx('projeto.git_pendente', 'Mudanças pendentes'), 'aviso')
            : D2UI.selo(tx('projeto.git_sincronizado', 'Sincronizado'), 'ok'));
    };
    desenharSelo();

    const historico = el('div', { class: 'projeto-historico', hidden: true });

    const botaoSync = botao(tx('projeto.git_sincronizar', 'Sincronizar agora'), {
        icone: 'atualizar', tipo: 'primario',
        aoClicar: async () => {
            const soltar = carregando(botaoSync);
            try {
                const d = await chamar(base + '/git/sync', { metodo: 'POST', formulario: { _: '1' } });
                if (!(d && d.ok)) throw new Error((d && d.error) || '');
                D2UI.toast(tx('projeto.git_sincronizando', 'Sincronizando…'), { tipo: 'info' });
                const ok = await aguardarSincronia(base);
                if (ok) { pendente = false; desenharSelo(); D2UI.toast(tx('projeto.git_sincronizado', 'Sincronizado'), { tipo: 'ok' }); if (!historico.hidden) carregarHistorico(); }
                else D2UI.toast(tx('projeto.git_demorando', 'A sincronização ainda está em andamento. Confira de novo em instantes.'), { tipo: 'aviso' });
            } catch (e) {
                D2UI.toast(erroDe(e, tx('projeto.git_falha_sync', 'Falha ao sincronizar.')), { tipo: 'erro' });
            } finally { soltar(); }
        },
    });
    const botaoHistorico = botao(tx('projeto.git_historico', 'Histórico'), {
        icone: 'relogio',
        aoClicar: () => { historico.hidden = !historico.hidden; botaoHistorico.setAttribute('aria-expanded', String(!historico.hidden)); if (!historico.hidden) carregarHistorico(); },
    });
    botaoHistorico.setAttribute('aria-expanded', 'false');

    async function carregarHistorico() {
        historico.textContent = '';
        historico.append(D2UI.esqueleto(4));
        try {
            const d = await chamar(base + '/git/commits');
            if (!(d && d.ok)) throw new Error((d && d.error) || '');
            const commits = d.commits || [];
            historico.textContent = '';
            if (!commits.length) {
                historico.append(D2UI.vazio({ chave: tx('projeto.git_sem_commits', 'Sem versões ainda'), texto: tx('projeto.git_sem_commits_texto', 'Sem commits ainda, sincronize primeiro.'), icone: 'relogio', acao: { rotulo: tx('projeto.git_sincronizar', 'Sincronizar agora'), icone: 'atualizar', tipo: 'primario', fn: () => botaoSync.click() } }));
                return;
            }
            const ul = el('ul', { class: 'lista-acionavel projeto-commits' });
            commits.forEach((c, i) => {
                const quando = c.date ? new Date(c.date).toLocaleString('pt-BR') : '';
                ul.append(el('li', { class: 'lista-item' },
                    el('code', { class: 'projeto-commit-sha', texto: c.short || String(c.sha || '').slice(0, 7) }),
                    el('span', { class: 'lista-item-texto' },
                        el('span', { class: 'lista-item-titulo', texto: c.message || tx('projeto.git_sem_mensagem', '(sem mensagem)') }),
                        el('span', { class: 'lista-item-sub', texto: [c.author, quando].filter(Boolean).join(' · ') })),
                    i === 0 ? D2UI.selo(tx('projeto.git_atual', 'atual'), 'info') : null,
                    i > 0 && dados.dono ? botao(tx('projeto.git_voltar', 'Voltar para esta versão'), { icone: 'restaurar', aoClicar: () => reverter(c) }) : null));
            });
            historico.append(ul);
        } catch (e) {
            historico.textContent = '';
            historico.append(D2UI.vazio({ chave: erroDe(e, tx('projeto.git_erro_historico', 'Não foi possível carregar o histórico.')), icone: 'erro', tipo: 'erro', acao: { rotulo: tx('projeto.tentar_de_novo', 'Tentar de novo'), icone: 'atualizar', fn: carregarHistorico } }));
        }
    }

    async function reverter(c) {
        const curto = c.short || String(c.sha || '').slice(0, 7);
        const ok = await D2UI.confirmar({
            titulo: tx('projeto.git_voltar', 'Voltar para esta versão'),
            texto: tx('projeto.git_voltar_texto', 'Voltar o projeto para a versão %s? Será criado um novo commit no topo com esse conteúdo (o histórico é preservado).').replace('%s', curto),
            perigo: true, palavra: curto, rotuloOk: tx('projeto.git_voltar_ok', 'Voltar versão'),
        });
        if (!ok) return;
        const p = D2UI.progresso({ titulo: tx('projeto.git_revertendo', 'Revertendo… aguarde.') });
        try {
            const d = await chamar(base + '/git/revert', { metodo: 'POST', formulario: { sha: c.sha } });
            if (!(d && d.ok)) throw new Error((d && d.error) || '');
            const pronto = await aguardarSincronia(base);
            p.concluir(pronto ? tx('projeto.git_restaurado', 'Projeto restaurado.') : tx('projeto.git_demorando', 'A sincronização ainda está em andamento. Confira de novo em instantes.'));
            setTimeout(recarregarTela, 1000);
        } catch (e) {
            p.falhar(erroDe(e, tx('projeto.git_falha_reverter', 'Falha ao reverter.')));
        }
    }

    const kv = el('dl', { class: 'kv' },
        el('dt', { texto: tx('projeto.git_repo', 'Repositório') }),
        el('dd', {}, el('a', { href: 'https://github.com/' + git.repo, target: '_blank', rel: 'noopener', class: 'projeto-link' }, git.repo, ' ', D2UI.icone('externo'))),
        el('dt', { texto: tx('projeto.git_branch', 'Branch') }), el('dd', {}, el('code', { texto: git.branch || 'main' })),
        el('dt', { texto: tx('projeto.git_estado', 'Estado') }), el('dd', {}, caixaSelo));

    const corpo = [kv, el('div', { class: 'form-acoes projeto-acoes-esquerda' }, botaoSync, botaoHistorico)];
    if (dados.dono) {
        const auto = interruptor(tx('projeto.git_auto', 'Aplicar tudo automaticamente (commit + push a cada entrega)'), git.auto, async (ligado, entrada) => {
            entrada.disabled = true;
            try {
                const d = await chamar(base + '/git/auto-sync', { metodo: 'POST', formulario: { on: ligado ? '1' : '0' } });
                if (!(d && d.ok)) throw new Error((d && d.error) || '');
                D2UI.toast(ligado ? tx('projeto.git_auto_on', 'Auto-sync ligado.') : tx('projeto.git_auto_off', 'Auto-sync desligado.'), { tipo: 'ok' });
            } catch (e) {
                entrada.checked = !ligado;
                D2UI.toast(erroDe(e, tx('projeto.erro_generico', 'Não foi possível concluir.')), { tipo: 'erro' });
            } finally { entrada.disabled = false; }
        });
        corpo.push(auto);
    }
    corpo.push(historico);
    const acoes = dados.dono ? [botao(tx('projeto.git_desvincular', 'Desvincular'), {
        icone: 'fechar', tipo: 'fantasma',
        aoClicar: async () => {
            const ok = await D2UI.confirmar({ titulo: tx('projeto.git_desvincular', 'Desvincular'), texto: tx('projeto.git_desvincular_texto', 'Desvincular o Git deste projeto? O repositório no GitHub não é apagado.'), perigo: true, rotuloOk: tx('projeto.git_desvincular', 'Desvincular') });
            if (!ok) return;
            try {
                const d = await chamar(base + '/git/unlink', { metodo: 'POST', formulario: { _: '1' } });
                if (!(d && d.ok)) throw new Error((d && d.error) || '');
                D2UI.toast(tx('projeto.git_desvinculado', 'Git desvinculado.'), { tipo: 'ok' });
                setTimeout(recarregarTela, 600);
            } catch (e) {
                D2UI.toast(erroDe(e, tx('projeto.erro_generico', 'Não foi possível concluir.')), { tipo: 'erro' });
            }
        },
    })] : null;
    return painel(corpo, { acoes, sub: tx('projeto.git_ligado_sub', 'Projeto versionado no GitHub') });
}
