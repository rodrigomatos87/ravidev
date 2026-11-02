// Lista de projetos: botão IDE de cada linha e, para o administrador, o interruptor
// "Todos os usuários" (preferência guardada no servidor). A lista vem pintada pelo PHP.
import { chamar, D2UI, abrirIde, aplicarCores, tx, recarregarTela, irPara, parametroDaTela, novaVida } from './projeto-comum.js';

export async function iniciar(raiz) {
    const vida = novaVida(raiz, 'projetos');
    const { signal } = vida;
    aplicarCores(raiz);

    raiz.querySelectorAll('[data-abrir-ide]').forEach((b) => {
        b.addEventListener('click', () => abrirIde(b.getAttribute('data-abrir-ide'), b), { signal });
    });

    const todos = raiz.querySelector('#projetos-todos');
    if (todos) {
        todos.addEventListener('change', async () => {
            const ligado = todos.checked;
            todos.disabled = true;
            const soltar = () => { todos.disabled = false; };
            try {
                await chamar('/projects/all-users', { metodo: 'POST', formulario: { on: ligado ? '1' : '0' } });
                // ?all=1/0 na URL força a escolha só naquela visita: sai dela para valer a preferência gravada.
                if (parametroDaTela(raiz, 'all') === null) { recarregarTela(raiz); return; }
                const dono = raiz.closest('[data-url]');
                const u = new URL((dono && dono.dataset.url) || window.location.href, window.location.origin);
                u.searchParams.delete('all');
                irPara(u.pathname + u.search);
            } catch (e) {
                soltar();
                todos.checked = !ligado;
                D2UI.toast(e.message || tx('casca.erro_generico', 'Não foi possível concluir.'), { tipo: 'erro' });
            }
        }, { signal });
    }

    return () => vida.abort();
}
