// Licenças do ChatGPT (Codex na IDE): cadastro e edição em modal, teste, exclusão com palavra
// e atribuição (padrão da IDE e por usuário).
import {
    chamar, D2UI, lerDados, criarTexto, abrirFormulario, ligarExclusao,
    testarLicenca, ligarSeletorQueSalva, ligarCopiar, abrirAjuda, ligarFormulariosComEspera,
} from '/assets/js/paginas/licencas-comum.js';

const dados = lerDados();
const tx = criarTexto(dados.textos);
const licencas = new Map((dados.licencas || []).map((l) => [String(l.id), l]));

// ---------- Cadastro e edição ----------
function abrirLicenca(id) {
    const l = id ? licencas.get(String(id)) : null;
    abrirFormulario({
        modeloId: 'modelo-form-licenca',
        titulo: l ? tx('editar_titulo') : tx('nova_titulo'),
        rotuloOk: l ? tx('salvar') : tx('cadastrar'),
        rotuloCancelar: tx('cancelar'),
        preencher: (form) => {
            const auth = form.querySelector('[name="auth"]');
            const rotulo = form.querySelector('label[for="lic-auth"]');
            if (l) {
                form.action = '/admin/licenses/chatgpt/' + l.id + '/update';
                form.querySelector('[name="name"]').value = l.nome || '';
                form.querySelector('[name="note"]').value = l.nota || '';
                auth.required = false;
                auth.placeholder = auth.dataset.phEditar || '';
                if (rotulo) rotulo.textContent = rotulo.dataset.rotuloEditar;
            } else {
                form.action = '/admin/licenses/chatgpt/create';
                auth.required = true;
                auth.placeholder = auth.dataset.phNovo || '';
                if (rotulo) rotulo.textContent = rotulo.dataset.rotuloNovo;
            }
        },
    });
}

document.addEventListener('click', (ev) => {
    const b = ev.target.closest('[data-acao]');
    if (!b) return;
    const acao = b.dataset.acao;
    if (acao === 'nova-licenca') abrirLicenca(null);
    else if (acao === 'editar') abrirLicenca(b.dataset.id);
    else if (acao === 'testar') testarLicenca(b, '/admin/licenses/chatgpt/' + b.dataset.id + '/test', tx);
    else if (acao === 'ajuda-auth') abrirAjuda('modelo-ajuda-auth', tx('ajuda_titulo'), tx('fechar'));
});

ligarExclusao({ titulo: tx('excluir_titulo'), texto: tx('excluir_texto'), rotuloOk: tx('excluir_ok') });
ligarFormulariosComEspera();
ligarCopiar(tx);

// ---------- Atribuição ----------
const padrao = document.querySelector('[data-atribuir-global]');
if (padrao) {
    ligarSeletorQueSalva(padrao, async (valor) => {
        await chamar('/admin/licenses/chatgpt/assign', { metodo: 'POST', formulario: { license_id: valor } });
        D2UI.toast(tx('salvo_global'), { tipo: 'ok' });
    });
}

document.querySelectorAll('[data-atribuir-usuario]').forEach((sel) => {
    const usuario = sel.dataset.atribuirUsuario;
    ligarSeletorQueSalva(sel, async (valor) => {
        await chamar('/admin/licenses/chatgpt/assign-user', { metodo: 'POST', formulario: { user_id: usuario, license_id: valor } });
        D2UI.toast(tx('salvo_usuario'), { tipo: 'ok' });
    });
});
