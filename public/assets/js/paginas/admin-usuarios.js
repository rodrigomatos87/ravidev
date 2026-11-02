// Admin, usuários da equipe: tabela com busca, cartões no celular e ações por linha.
// As ações que mudam dados continuam sendo POST de formulário (o controller redireciona
// com mensagem, que a casca mostra como aviso). Só o interruptor de licença usa chamar().
import { chamar, D2UI } from '/assets/d2/js/casca.js';

const dados = JSON.parse(document.getElementById('dados-pagina').textContent || '{}');
const usuarios = Array.isArray(dados.usuarios) ? dados.usuarios : [];
const idAtual = Number(dados.idAtual) || 0;
const formAcao = document.getElementById('form-acao');

// Monta elementos sem innerHTML (textos sempre por textContent).
function el(tag, atributos = {}, ...filhos) {
    const n = document.createElement(tag);
    for (const [k, v] of Object.entries(atributos)) {
        if (v === null || v === undefined || v === false) continue;
        if (k === 'texto') n.textContent = v;
        else if (k.startsWith('on') && typeof v === 'function') n.addEventListener(k.slice(2), v);
        else n.setAttribute(k, v === true ? '' : v);
    }
    for (const f of filhos.flat()) if (f !== null && f !== undefined && f !== false) n.append(f instanceof Node ? f : document.createTextNode(String(f)));
    return n;
}

// POST de formulário com o token da página: a navegação leva à mensagem do servidor.
// Devolve uma promessa que nunca resolve, para o botão do modal ficar carregando até a troca de página.
// Com aguardar (texto), mostra o progresso enquanto a página troca (ação disparada fora de um modal).
function enviar(acao, campos = {}, aguardar = '') {
    if (aguardar) D2UI.progresso({ titulo: aguardar });
    for (const i of [...formAcao.querySelectorAll('input')]) if (i.name !== '_csrf_token') i.remove();
    for (const [nome, valor] of Object.entries(campos)) {
        if (valor === null || valor === undefined) continue;
        formAcao.append(el('input', { type: 'hidden', name: nome, value: String(valor) }));
    }
    formAcao.action = acao;
    formAcao.submit();
    return new Promise(() => {});
}

const nomeDe = (u) => u.nome || u.email;
const ehEu = (u) => u.id === idAtual;

// Criar usuário
function abrirCriar() {
    const nome = D2UI.campo({ tipo: 'texto', nome: 'name', rotulo: 'Nome', obrigatorio: true, maximo: 100 });
    const email = D2UI.campo({ tipo: 'email', nome: 'email', rotulo: 'E-mail', obrigatorio: true });
    const papel = D2UI.campo({ tipo: 'selecao', nome: 'role', rotulo: 'Papel', valor: 'user', opcoes: [{ valor: 'user', rotulo: 'Membro' }, { valor: 'admin', rotulo: 'Administrador' }] });
    const radio = (valor, texto, marcado) => el('label', { class: 'd2-check' },
        el('input', { type: 'radio', name: 'onboard_escolha', value: valor, checked: marcado, onchange: sincronizar }), el('span', { texto }));
    const grupo = el('div', { class: 'd2-field', role: 'radiogroup', 'aria-labelledby': 'rotulo-acesso' },
        el('span', { class: 'd2-label', id: 'rotulo-acesso', texto: 'Como dar acesso?' }),
        radio('password', 'Definir uma senha agora', true),
        radio('invite', 'Convidar por e-mail (a pessoa define a própria senha)', false));
    const senha = D2UI.campo({ tipo: 'senha', nome: 'password', rotulo: 'Senha inicial', minimo: 8, autocomplete: 'new-password',
        dica: 'Mínimo 8 caracteres. O usuário pode trocá-la depois em Configurações.' });
    const boasVindas = el('input', { type: 'checkbox', value: '1' });
    const caixaBoasVindas = el('label', { class: 'd2-check' }, boasVindas, el('span', { texto: 'Também enviar e-mail de boas-vindas (requer e-mail configurado)' }));
    const notaConvite = el('div', { class: 'd2-faixa', hidden: true }, D2UI.icone('email'),
        el('div', { class: 'd2-faixa-texto' }, 'Um e-mail com link para definir a senha será enviado. Requer o envio de e-mail configurado em ',
            el('a', { class: 'd2-link', href: '/settings/email', texto: 'Configurar e-mail' }), '.'));
    const avisoSmtp = dados.smtpPronto ? null : el('div', { class: 'd2-faixa d2-faixa-aviso', hidden: true }, D2UI.icone('alerta'),
        el('div', { class: 'd2-faixa-texto' }, 'O envio de e-mail ainda não está configurado, então o convite não será enviado. ',
            el('a', { class: 'd2-link', href: '/settings/email', texto: 'Configure o e-mail' }), ' primeiro, ou defina uma senha agora.'));
    const corpo = el('div', { class: 'd2-form' }, nome.el, email.el, papel.el, grupo, senha.el, caixaBoasVindas, notaConvite, avisoSmtp);
    const modo = () => (corpo.querySelector('input[name="onboard_escolha"]:checked') || {}).value || 'password';
    function sincronizar() {
        const convite = modo() === 'invite';
        senha.el.hidden = convite;
        caixaBoasVindas.hidden = convite;
        notaConvite.hidden = !convite;
        if (avisoSmtp) avisoSmtp.hidden = !convite;
        if (convite) senha.definir('');
    }
    D2UI.modal({
        titulo: 'Novo usuário', icone: 'conta', corpo, enterConfirma: true,
        botoes: [
            { rotulo: 'Cancelar', tipo: 'secundario', fecha: true },
            { rotulo: 'Criar usuário', tipo: 'primario', icone: 'check', fn: () => {
                let ok = D2UI.form.validar(corpo);
                const convite = modo() === 'invite';
                if (!convite && !senha.valor()) { senha.erro('Informe a senha inicial.'); ok = false; }
                if (!ok) return false;
                return enviar('/admin/users/create', {
                    name: nome.valor(), email: email.valor(), role: papel.valor() || 'user', onboard: convite ? 'invite' : 'password',
                    password: convite ? '' : senha.valor(), send_email: !convite && boasVindas.checked ? '1' : null,
                });
            } },
        ],
    });
}

// Editar nome, e-mail e papel
function abrirEditar(u) {
    const nome = D2UI.campo({ tipo: 'texto', nome: 'name', rotulo: 'Nome', obrigatorio: true, maximo: 100, valor: u.nome });
    const email = D2UI.campo({ tipo: 'email', nome: 'email', rotulo: 'E-mail', obrigatorio: true, valor: u.email });
    const papel = D2UI.campo({ tipo: 'selecao', nome: 'role', rotulo: 'Papel', valor: u.papel, opcoes: [{ valor: 'user', rotulo: 'Membro' }, { valor: 'admin', rotulo: 'Administrador' }] });
    const corpo = el('div', { class: 'd2-form' }, nome.el, email.el, papel.el);
    D2UI.modal({
        titulo: 'Editar usuário', icone: 'editar', corpo, enterConfirma: true,
        botoes: [
            { rotulo: 'Cancelar', tipo: 'secundario', fecha: true },
            { rotulo: 'Salvar', tipo: 'primario', icone: 'check', fn: () => {
                if (!D2UI.form.validar(corpo)) return false;
                return enviar('/admin/users/' + u.id + '/update', { name: nome.valor(), email: email.valor(), role: papel.valor() || 'user' });
            } },
        ],
    });
}

// Trocar a senha
function abrirSenha(u) {
    const senha = D2UI.campo({ tipo: 'senha', nome: 'password', rotulo: 'Nova senha para ' + nomeDe(u), obrigatorio: true, minimo: 8,
        autocomplete: 'new-password', dica: 'Mínimo 8 caracteres.' });
    const corpo = el('div', { class: 'd2-form' }, senha.el);
    D2UI.modal({
        titulo: 'Redefinir senha', icone: 'cadeado', corpo, enterConfirma: true,
        botoes: [
            { rotulo: 'Cancelar', tipo: 'secundario', fecha: true },
            { rotulo: 'Salvar senha', tipo: 'primario', icone: 'check', fn: () => {
                if (!D2UI.form.validar(corpo)) return false;
                return enviar('/admin/users/' + u.id + '/password', { password: senha.valor() });
            } },
        ],
    });
}

async function alternarAtivo(u) {
    const ok = await D2UI.confirmar(u.ativo
        ? { titulo: 'Desativar conta', texto: 'Desativar a conta de ' + nomeDe(u) + '? Ele não poderá mais fazer login.', perigo: true, rotuloOk: 'Desativar' }
        : { titulo: 'Ativar conta', texto: 'Ativar a conta de ' + nomeDe(u) + '? Ele volta a poder fazer login.', rotuloOk: 'Ativar' });
    if (ok) enviar('/admin/users/' + u.id + '/toggle', {}, u.ativo ? 'Desativando a conta' : 'Ativando a conta');
}

async function entrarComo(u) {
    const ok = await D2UI.confirmar({ titulo: 'Entrar como ' + nomeDe(u), texto: 'Você vai navegar pelo painel com a conta de ' + nomeDe(u) + '. Para voltar à sua conta, use o aviso no topo da tela.', rotuloOk: 'Entrar como' });
    if (ok) enviar('/admin/impersonate/' + u.id, {}, 'Entrando como ' + nomeDe(u));
}

async function excluir(u) {
    const ok = await D2UI.confirmar({
        titulo: 'Excluir usuário', perigo: true, palavra: u.email, rotuloOk: 'Excluir',
        texto: 'Excluir ' + nomeDe(u) + ' permanentemente? Os projetos e conversas próprios dele serão removidos.\n\nEsta ação não pode ser desfeita.',
    });
    if (ok) enviar('/admin/users/' + u.id + '/delete', {}, 'Excluindo o usuário');
}

function maisAcoes(u, evento) {
    const alvo = evento && evento.currentTarget ? evento.currentTarget : evento && evento.target;
    const itens = [{ rotulo: 'Redefinir senha', icone: 'cadeado', fn: () => abrirSenha(u) }];
    if (ehEu(u)) {
        itens.push('separador', { grupo: 'Sua conta: não é possível desativar, entrar como ou excluir' });
    } else {
        itens.push(
            { rotulo: u.ativo ? 'Desativar' : 'Ativar', icone: u.ativo ? 'offline' : 'online', fn: () => alternarAtivo(u) },
            { rotulo: 'Entrar como', icone: 'olho', fn: () => entrarComo(u) },
            'separador',
            { rotulo: 'Excluir', icone: 'lixeira', perigo: true, fn: () => excluir(u) },
        );
    }
    D2UI.menuContexto(alvo, itens);
}

// Interruptor "Escolhe a conta": grava na hora, desfaz se o servidor recusar.
function interruptorLicenca(u) {
    const entrada = el('input', { type: 'checkbox', role: 'switch', checked: u.escolhe_licenca, 'aria-label': 'Escolhe a própria conta: ' + nomeDe(u) });
    const rotulo = el('label', { class: 'd2-switch', 'data-tooltip': 'Ligado: o usuário escolhe a própria conta do Claude no perfil dele, vendo o uso de cada uma. Desligado: ele usa a conta que você atribuir em Licenças.' },
        entrada, el('span', { class: 'd2-switch-trilho', 'aria-hidden': 'true' }));
    entrada.addEventListener('change', async () => {
        const ligado = entrada.checked;
        entrada.disabled = true;
        rotulo.classList.add('is-desativado');
        try {
            await chamar('/admin/users/' + u.id + '/license-choice', { metodo: 'POST', formulario: { enabled: ligado ? '1' : '0' } });
            u.escolhe_licenca = ligado;
            D2UI.toast(ligado ? nomeDe(u) + ' agora pode escolher a conta do Claude no próprio perfil.' : nomeDe(u) + ' volta a usar a conta definida por você.', { tipo: 'ok' });
        } catch (e) {
            entrada.checked = !ligado;
            D2UI.toast(e.message || 'Não foi possível salvar.', { tipo: 'erro' });
        } finally {
            entrada.disabled = false;
            rotulo.classList.remove('is-desativado');
        }
    });
    return rotulo;
}

function celulaUsuario(u) {
    return el('div', { class: 'usuario-celula' },
        D2UI.avatar(nomeDe(u), { tamanho: 'sm' }),
        el('div', { class: 'usuario-celula-texto' },
            el('span', { class: 'usuario-celula-nome' }, u.nome || u.email, !u.ativo ? ' ' : null, !u.ativo ? D2UI.selo('inativo', 'erro') : null,
                ehEu(u) ? ' ' : null, ehEu(u) ? D2UI.selo('você', 'info') : null),
            el('span', { class: 'texto-mudo', texto: u.email })));
}

const sem = (s) => String(s || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
const valorOrdem = (u, chave) => ({ usuario: sem(u.nome || u.email), papel: u.papel, projetos: u.projetos, tokens: u.tokens, custo: u.custo }[chave]);

const tabela = D2UI.tabela({
    id: 'admin-usuarios',
    rotulo: 'Usuários da equipe',
    chave: 'id',
    colunas: [
        { chave: 'usuario', titulo: 'Usuário', fixa: true, ordenavel: true, render: celulaUsuario },
        { chave: 'papel', titulo: 'Papel', ordenavel: true, render: (u) => D2UI.selo(u.papel === 'admin' ? 'Admin' : 'Membro', u.papel === 'admin' ? 'info' : 'neutro', { ponto: false }) },
        { chave: 'projetos', titulo: 'Projetos', alinhar: 'direita', ordenavel: true, render: (u) => String(u.projetos) },
        { chave: 'tokens', titulo: 'Tokens', alinhar: 'direita', ordenavel: true, render: (u) => u.tokens_texto },
        { chave: 'custo', titulo: 'Custo', alinhar: 'direita', ordenavel: true, render: (u) => u.custo_texto },
        { chave: 'licenca', titulo: 'Escolhe a conta', render: interruptorLicenca },
    ],
    acoesLinha: [
        { rotulo: 'Editar', icone: 'editar', fn: (u) => abrirEditar(u) },
        { rotulo: 'Mais ações', icone: 'reticencias', fn: (u, ev) => maisAcoes(u, ev) },
    ],
    cartao: () => null,
    vazio: { texto: 'Nenhum usuário encontrado.', icone: 'usuarios', acao: { rotulo: 'Novo usuário', icone: 'mais', fn: abrirCriar } },
    carregar: async ({ busca, ordem }) => {
        const termo = sem(busca).trim();
        let itens = termo ? usuarios.filter((u) => sem(u.nome).includes(termo) || sem(u.email).includes(termo)) : usuarios.slice();
        if (ordem && ordem.chave) {
            const o = ordem;
            const dir = o.sentido === 'desc' ? -1 : 1;
            itens.sort((a, b) => { const x = valorOrdem(a, o.chave); const y = valorOrdem(b, o.chave); return (x > y ? 1 : x < y ? -1 : 0) * dir; });
        }
        return { itens, cursor_proximo: null };
    },
});
const caixaTabela = document.getElementById('tabela-usuarios');
caixaTabela.append(tabela.el);
caixaTabela.setAttribute('aria-busy', 'false');

let espera = 0;
document.getElementById('busca-usuarios').addEventListener('input', (e) => {
    clearTimeout(espera);
    const v = e.target.value;
    espera = setTimeout(() => tabela.definirBusca(v), 150);
});
document.getElementById('btn-novo-usuario').addEventListener('click', abrirCriar);

// Pendentes: aprovar envia direto; rejeitar pede confirmação. Botão fica carregando até a página trocar.
for (const f of document.querySelectorAll('form.js-pendente')) {
    f.addEventListener('submit', async (e) => {
        const botao = f.querySelector('button[type="submit"]');
        if (f.classList.contains('js-rejeitar') && !f.dataset.confirmado) {
            e.preventDefault();
            const ok = await D2UI.confirmar({ titulo: 'Rejeitar cadastro', texto: 'Rejeitar o cadastro de ' + f.dataset.nome + '?', perigo: true, rotuloOk: 'Rejeitar' });
            if (!ok) return;
            f.dataset.confirmado = '1';
            botao.classList.add('d2-btn-carregando');
            f.submit();
            return;
        }
        botao.classList.add('d2-btn-carregando');
    });
}
