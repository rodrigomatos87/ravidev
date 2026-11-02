// Lista de projetos: botão IDE de cada linha e, para o administrador, o interruptor
// "Todos os usuários" (preferência guardada no servidor). A lista vem pintada pelo PHP.
import { chamar, D2UI, abrirIde, aplicarCores, tx } from './projeto-comum.js';

aplicarCores();

document.querySelectorAll('[data-abrir-ide]').forEach((b) => {
    b.addEventListener('click', () => abrirIde(b.getAttribute('data-abrir-ide'), b));
});

const todos = document.getElementById('projetos-todos');
if (todos) {
    todos.addEventListener('change', async () => {
        const ligado = todos.checked;
        todos.disabled = true;
        const soltar = () => { todos.disabled = false; };
        try {
            await chamar('/projects/all-users', { metodo: 'POST', formulario: { on: ligado ? '1' : '0' } });
            const u = new URL(window.location.href);
            u.searchParams.delete('all');
            window.location.href = u.toString();
        } catch (e) {
            soltar();
            todos.checked = !ligado;
            D2UI.toast(e.message || tx('casca.erro_generico', 'Não foi possível concluir.'), { tipo: 'erro' });
        }
    });
}
