// Aba Servidores: servidores externos que o Claude acessa pelo nome e onde as entregas
// podem ser sincronizadas. Mais o acesso de cada membro às memórias e aos servidores.
import { tx, el, botao, carregando, interruptor, chamar, D2UI } from './projeto-comum.js';

const erroDe = (e, padrao) => (e && e.dados && e.dados.error) || (e && e.message) || padrao;

export async function montarServidores({ dados, base }) {
    const lista = el('div', {});
    let servidores = [];
    let comandoChave = '';
    const p = D2UI.painel({
        titulo: tx('projeto.servidores', 'Servidores externos'), icone: 'rede', area: 'projetos',
        acoes: [botao(tx('projeto.servidor_novo', 'Adicionar servidor'), { icone: 'mais', tipo: 'primario', aoClicar: () => abrir(null) })],
    }, el('p', { class: 'texto-mudo projeto-explica', texto: tx('projeto.servidores_sub', 'Dê um nome ao servidor (ex.: "Produção"). Com Ativo, o Claude acessa quando você pedir no chat ou na IDE. Com Auto sync, cada entrega sincroniza o projeto nesse servidor e a IDE já vem com o acesso configurado.') }), lista);

    async function carregar() {
        lista.textContent = '';
        lista.append(D2UI.esqueleto(3));
        try {
            const d = await chamar(base + '/servers');
            if (d && d.ok === false) throw new Error(d.error || '');
            servidores = (d && d.servers) || [];
            comandoChave = (d && d.install_command) || '';
            desenhar();
        } catch (e) {
            lista.textContent = '';
            lista.append(D2UI.vazio({ chave: erroDe(e, tx('projeto.servidores_erro', 'Não foi possível carregar os servidores.')), icone: 'erro', tipo: 'erro', acao: { rotulo: tx('projeto.tentar_de_novo', 'Tentar de novo'), icone: 'atualizar', fn: carregar } }));
        }
    }

    async function alternar(s, campo, ligado, entrada) {
        entrada.disabled = true;
        try {
            const d = await chamar(base + '/servers/toggle', { metodo: 'POST', formulario: { id: s.id, field: campo, on: ligado ? '1' : '0' } });
            if (d && d.ok === false) throw new Error(d.error || '');
            s[campo] = ligado;
        } catch (e) {
            entrada.checked = !ligado;
            D2UI.toast(erroDe(e, tx('projeto.erro_generico', 'Não foi possível concluir.')), { tipo: 'erro' });
        } finally { entrada.disabled = false; }
    }

    function desenhar() {
        lista.textContent = '';
        if (!servidores.length) {
            lista.append(D2UI.vazio({ chave: tx('projeto.servidores_vazio', 'Nenhum servidor cadastrado.'), texto: tx('projeto.servidores_vazio_texto', 'Cadastre um servidor para o Claude acessar ou para receber as entregas.'), icone: 'rede', acao: { rotulo: tx('projeto.servidor_novo', 'Adicionar servidor'), icone: 'mais', tipo: 'primario', fn: () => abrir(null) } }));
            return;
        }
        const ul = el('ul', { class: 'lista-acionavel projeto-servidores' });
        servidores.forEach((s) => {
            const meta = [s.username + '@' + s.host + ':' + s.port, s.remote_dir || null, s.auth_type === 'key' ? tx('projeto.servidor_por_chave', 'chave') : null].filter(Boolean).join(' · ');
            ul.append(el('li', { class: 'lista-item projeto-servidor' },
                el('span', { class: 'lista-item-texto' },
                    el('span', { class: 'lista-item-titulo' }, D2UI.selo(s.protocol === 'ftp' ? 'FTP' : 'SSH', s.protocol === 'ftp' ? 'aviso' : 'info', { ponto: false }), ' ', s.name),
                    el('span', { class: 'lista-item-sub', texto: meta })),
                el('span', { class: 'projeto-servidor-chaves' },
                    interruptor(tx('projeto.servidor_ativo', 'Ativo'), s.enabled, (v, i) => alternar(s, 'enabled', v, i)),
                    interruptor(tx('projeto.servidor_auto', 'Auto sync'), s.auto_deploy, (v, i) => alternar(s, 'auto_deploy', v, i))),
                el('span', { class: 'projeto-arquivo-acoes' },
                    botao('', { icone: 'editar', tipo: 'fantasma', dica: tx('projeto.editar', 'Editar'), aoClicar: () => abrir(s) }),
                    botao('', { icone: 'lixeira', tipo: 'fantasma', dica: tx('projeto.excluir', 'Excluir'), aoClicar: () => excluir(s) }))));
        });
        lista.append(ul);
    }

    async function excluir(s) {
        const ok = await D2UI.confirmar({ titulo: tx('projeto.servidor_remover', 'Remover servidor'), texto: tx('projeto.servidor_remover_texto', 'Remover este servidor?'), perigo: true, palavra: s.name, rotuloOk: tx('projeto.remover', 'Remover') });
        if (!ok) return;
        try {
            const d = await chamar(base + '/servers/delete', { metodo: 'POST', formulario: { id: s.id } });
            if (d && d.ok === false) throw new Error(d.error || '');
            D2UI.toast(tx('projeto.servidor_removido', 'Servidor removido.'), { tipo: 'ok' });
            carregar();
        } catch (e) {
            D2UI.toast(erroDe(e, tx('projeto.erro_generico', 'Não foi possível concluir.')), { tipo: 'erro' });
        }
    }

    function abrir(s) {
        const ftpInicial = Boolean(s && s.protocol === 'ftp');
        const tipo = D2UI.campo({ tipo: 'selecao', nome: 'protocol', rotulo: tx('projeto.servidor_tipo', 'Tipo'), obrigatorio: true, valor: ftpInicial ? 'ftp' : 'ssh', opcoes: [
            { valor: 'ssh', rotulo: tx('projeto.servidor_ssh', 'SSH / SFTP'), dica: tx('projeto.servidor_ssh_dica', 'Acesso do Claude e sincronização das entregas') },
            { valor: 'ftp', rotulo: 'FTP', dica: tx('projeto.servidor_ftp_dica', 'Só envio dos arquivos, hospedagem comum') },
        ], aoMudar: () => aplicarTipo(true) });
        const nome = D2UI.campo({ tipo: 'texto', nome: 'name', rotulo: tx('projeto.servidor_nome', 'Nome'), placeholder: tx('projeto.servidor_nome_ph', 'Nome do servidor'), obrigatorio: true, maximo: 150, valor: s ? s.name : '' });
        const host = D2UI.campo({ tipo: 'texto', nome: 'host', rotulo: tx('projeto.servidor_host', 'Host ou IP'), placeholder: '203.0.113.10', obrigatorio: true, maximo: 255, valor: s ? s.host : '' });
        const porta = D2UI.campo({ tipo: 'numero', nome: 'port', rotulo: tx('projeto.servidor_porta', 'Porta'), obrigatorio: true, valor: s ? s.port : 22 });
        const usuario = D2UI.campo({ tipo: 'texto', nome: 'username', rotulo: tx('projeto.servidor_usuario', 'Usuário'), placeholder: 'root', obrigatorio: true, maximo: 150, valor: s ? s.username : '' });
        const pasta = D2UI.campo({ tipo: 'texto', nome: 'remote_dir', rotulo: tx('projeto.servidor_pasta', 'Diretório'), placeholder: '/var/www/projeto', opcional: true, maximo: 500, valor: s ? s.remote_dir : '' });
        const autenticacao = D2UI.campo({ tipo: 'selecao', nome: 'auth_type', rotulo: tx('projeto.servidor_autenticacao', 'Autenticação'), obrigatorio: true, valor: s && s.auth_type === 'key' ? 'key' : 'password', opcoes: [
            { valor: 'password', rotulo: tx('projeto.servidor_senha', 'Senha'), icone: 'cadeado' },
            { valor: 'key', rotulo: tx('projeto.servidor_chave', 'Chave SSH'), icone: 'chave' },
        ], aoMudar: () => aplicarAutenticacao() });
        const senha = D2UI.campo({ tipo: 'senha', nome: 'password', rotulo: tx('projeto.servidor_senha', 'Senha'), placeholder: s ? tx('projeto.servidor_senha_manter', '(deixe em branco p/ manter)') : '', opcional: Boolean(s), autocomplete: 'new-password' });
        const notaFtp = el('div', { class: 'd2-faixa is-cheio', hidden: true },
            D2UI.icone('info'), el('div', { class: 'd2-faixa-texto', texto: tx('projeto.servidor_ftp_nota', 'FTP é só para envio dos arquivos nas entregas (Auto sync). O Claude não acessa por FTP; para o Claude operar no servidor, use SSH.') }));
        const comando = el('pre', { class: 'codigo projeto-comando' }, el('code', { texto: comandoChave || tx('projeto.servidor_gerando_chave', 'Gerando a chave do projeto…') }));
        const blocoChave = el('div', { class: 'is-cheio projeto-bloco-chave', hidden: true },
            el('p', { class: 'd2-label', texto: tx('projeto.servidor_chave_titulo', 'Autorize a chave deste projeto no servidor') }),
            el('p', { class: 'texto-mudo', texto: tx('projeto.servidor_chave_texto', 'Cada projeto tem sua própria chave. Rode este comando no servidor (logado como o usuário acima) para instalar a chave pública do projeto. Depois clique em Testar conexão:') }),
            comando,
            el('div', { class: 'form-acoes' }, botao(tx('projeto.copiar', 'Copiar'), { icone: 'copiar', aoClicar: async () => {
                try { await navigator.clipboard.writeText(comandoChave); D2UI.toast(tx('projeto.copiado', 'Copiado.'), { tipo: 'ok' }); }
                catch (e) { D2UI.toast(tx('projeto.copiar_falhou', 'Não foi possível copiar. Selecione o texto e copie.'), { tipo: 'aviso' }); }
            } })),
            el('p', { class: 'texto-mudo', texto: tx('projeto.servidor_chave_nota', 'A chave privada fica só neste projeto e não funciona em outro.') }));
        const resultado = el('div', { class: 'is-cheio', role: 'status', 'aria-live': 'polite' });
        const caixaSenha = el('div', {}, senha);
        const caixaAutenticacao = el('div', {}, autenticacao);

        function ehFtp() { return tipo.valor() === 'ftp'; }
        function aplicarTipo(trocouAgora) {
            const ftp = ehFtp();
            caixaAutenticacao.hidden = ftp;
            notaFtp.hidden = !ftp;
            if (ftp) autenticacao.definir('password');
            if (trocouAgora) {
                const atual = String(porta.valor() || '');
                if (ftp && (atual === '' || atual === '22')) porta.definir(21);
                if (!ftp && (atual === '' || atual === '21')) porta.definir(22);
            }
            aplicarAutenticacao();
        }
        function aplicarAutenticacao() {
            const chave = !ehFtp() && autenticacao.valor() === 'key';
            caixaSenha.hidden = chave;
            blocoChave.hidden = !chave;
        }
        function parametros() {
            const ftp = ehFtp();
            return {
                id: s ? s.id : '', protocol: ftp ? 'ftp' : 'ssh',
                name: String(nome.valor() || '').trim(), host: String(host.valor() || '').trim(),
                port: String(porta.valor() || (ftp ? '21' : '22')),
                username: String(usuario.valor() || '').trim(), remote_dir: String(pasta.valor() || '').trim(),
                auth_type: ftp ? 'password' : (autenticacao.valor() || 'password'), password: senha.valor() || '',
            };
        }
        function faixa(tipoFaixa, texto) {
            resultado.textContent = '';
            resultado.append(el('div', { class: 'd2-faixa d2-faixa-' + tipoFaixa }, D2UI.icone(tipoFaixa === 'ok' ? 'check' : 'erro'), el('div', { class: 'd2-faixa-texto', texto })));
        }

        const corpo = el('div', { class: 'form-grade' }, tipo, nome, host, porta, usuario, pasta, caixaAutenticacao, caixaSenha, notaFtp, blocoChave, resultado);
        aplicarTipo(false);
        D2UI.modal({
            titulo: s ? tx('projeto.servidor_editar', 'Editar servidor') : tx('projeto.servidor_novo', 'Adicionar servidor'),
            icone: 'rede', corpo, largura: 760,
            botoes: [
                { rotulo: tx('projeto.servidor_testar', 'Testar conexão'), tipo: 'secundario', icone: 'online', fn: async () => {
                    resultado.textContent = '';
                    try {
                        const d = await chamar(base + '/servers/test', { metodo: 'POST', formulario: parametros() });
                        if (!(d && d.ok)) throw new Error((d && (d.error || d.message)) || '');
                        faixa('ok', (d && d.message) || tx('projeto.servidor_conexao_ok', 'Conexão OK'));
                    } catch (e) {
                        faixa('erro', erroDe(e, tx('projeto.servidor_conexao_falhou', 'Falhou')));
                    }
                    return false;
                } },
                { rotulo: tx('projeto.cancelar', 'Cancelar'), tipo: 'fantasma', fecha: true },
                { rotulo: tx('projeto.salvar', 'Salvar'), tipo: 'primario', icone: 'check', fn: async () => {
                    const validos = [nome, host, usuario].map((c) => c.validar()).every(Boolean);
                    if (!validos) return false;
                    try {
                        const d = await chamar(base + '/servers', { metodo: 'POST', formulario: parametros() });
                        if (d && d.ok === false) throw new Error(d.error || '');
                        D2UI.toast(tx('projeto.servidor_salvo', 'Servidor salvo.'), { tipo: 'ok' });
                        carregar();
                        return true;
                    } catch (e) {
                        faixa('erro', erroDe(e, tx('projeto.servidor_falha_salvar', 'Falha ao salvar.')));
                        return false;
                    }
                } },
            ],
        });
        setTimeout(() => nome.focar(), 50);
    }

    carregar();
    const pilha = el('div', { class: 'projeto-pilha' }, p.el);

    // Acesso de cada membro às memórias e aos servidores (só o dono, com membros).
    const membros = dados.membros || [];
    if (dados.dono && membros.length) {
        const ul = el('ul', { class: 'lista-acionavel' });
        membros.forEach((m) => {
            ul.append(el('li', { class: 'lista-item' },
                el('span', { class: 'lista-item-texto' },
                    el('span', { class: 'lista-item-titulo', texto: m.nome || m.email }),
                    el('span', { class: 'lista-item-sub', texto: m.email })),
                interruptor('', m.contexto, async (ligado, entrada) => {
                    entrada.disabled = true;
                    try {
                        const d = await chamar(base + '/context/member-access', { metodo: 'POST', formulario: { user_id: m.id, on: ligado ? '1' : '0' } });
                        if (d && d.ok === false) throw new Error(d.error || '');
                        m.contexto = ligado;
                    } catch (e) {
                        entrada.checked = !ligado;
                        D2UI.toast(erroDe(e, tx('projeto.erro_generico', 'Não foi possível concluir.')), { tipo: 'erro' });
                    } finally { entrada.disabled = false; }
                }, { dica: tx('projeto.membro_acesso_de', 'Acesso de') + ' ' + (m.nome || m.email) })));
        });
        pilha.append(D2UI.painel({
            titulo: tx('projeto.membros_acesso', 'Quem pode ver e usar (membros)'), icone: 'usuarios', area: 'projetos',
        }, el('p', { class: 'texto-mudo projeto-explica', texto: tx('projeto.membros_acesso_sub', 'Membros sem acesso não veem isto e o Claude da IDE deles não enxerga as memórias nem os servidores.') }), ul).el);
    }
    return pilha;
}
