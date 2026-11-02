// Minha conta (perfil, notificações e GitHub). A página vem pintada pelo PHP; aqui ficam os
// salvamentos e as ações. Cada bloco só liga se o elemento dele existir na tela atual.
import { chamar, D2UI } from '/assets/d2/js/casca.js';

const $ = (id) => document.getElementById(id);
const salvo = (texto) => D2UI.toast(texto, { tipo: 'ok', duracao: 2400 });
const falhou = (erro, padrao) => D2UI.toast((erro && erro.message && erro.message !== 'Não foi possível concluir.') ? erro.message : padrao, { tipo: 'erro' });

// Botão em espera: desativa e mostra o carregando enquanto a promessa corre.
async function esperando(botao, tarefa) {
    if (botao) { botao.disabled = true; botao.classList.add('d2-btn-carregando'); botao.setAttribute('aria-busy', 'true'); }
    try { return await tarefa(); } finally {
        if (botao) { botao.disabled = false; botao.classList.remove('d2-btn-carregando'); botao.removeAttribute('aria-busy'); }
    }
}

// Recarrega com aviso de espera (o resultado aparece na página nova).
function recarregar(texto) {
    D2UI.progresso({ titulo: texto || 'Atualizando' });
    location.reload();
}

// Erros por campo (422): {campo: [mensagens]} pintados nos .d2-field-erro[data-erro].
function pintarErros(form, erros) {
    for (const el of form.querySelectorAll('[data-erro]')) {
        const lista = erros && erros[el.dataset.erro];
        el.textContent = lista ? [].concat(lista)[0] : '';
        const campo = form.querySelector(`[name="${el.dataset.erro}"]`);
        if (campo) campo.toggleAttribute('aria-invalid', !!lista);
    }
}

/* ---------- Foto de perfil ---------- */
const avatarArquivo = $('avatar-arquivo');
const avatarEnviar = $('avatar-enviar');
const avatarRemover = $('avatar-remover');
const avatarPrevia = $('avatar-previa');
if (avatarArquivo && avatarEnviar) {
    avatarEnviar.addEventListener('click', () => avatarArquivo.click());
    avatarArquivo.addEventListener('change', async () => {
        const arquivo = avatarArquivo.files && avatarArquivo.files[0];
        if (!arquivo) return;
        const fd = new FormData();
        fd.append('avatar', arquivo);
        try {
            const r = await esperando(avatarEnviar, () => chamar('/settings/avatar', { metodo: 'POST', formulario: fd }));
            if (r && r.avatar_url && avatarPrevia) {
                const img = document.createElement('img');
                img.src = r.avatar_url;
                img.alt = 'Sua foto';
                avatarPrevia.replaceChildren(img);
                for (const outro of document.querySelectorAll('.d2-topo .d2-avatar img')) outro.src = r.avatar_url;
            }
            if (avatarRemover) avatarRemover.hidden = false;
            salvo('Foto atualizada.');
        } catch (erro) {
            falhou(erro, 'Não foi possível enviar a foto.');
        } finally {
            avatarArquivo.value = '';
        }
    });
}
if (avatarRemover) {
    avatarRemover.addEventListener('click', async () => {
        const ok = await D2UI.confirmar({ titulo: 'Remover foto', texto: 'Remover sua foto de perfil? No lugar dela aparece a inicial do seu nome.', rotuloOk: 'Remover', perigo: true });
        if (!ok) return;
        try {
            await esperando(avatarRemover, () => chamar('/settings/avatar/remove', { metodo: 'POST', formulario: {} }));
            if (avatarPrevia) avatarPrevia.textContent = avatarPrevia.dataset.inicial || '';
            avatarRemover.hidden = true;
            salvo('Foto removida.');
        } catch (erro) {
            falhou(erro, 'Não foi possível remover a foto.');
        }
    });
}

/* ---------- Dados do perfil ---------- */
const perfilForm = $('perfil-form');
if (perfilForm) {
    perfilForm.addEventListener('submit', async (ev) => {
        ev.preventDefault();
        const botao = perfilForm.querySelector('[type="submit"]');
        pintarErros(perfilForm, null);
        try {
            await esperando(botao, () => chamar('/settings/profile', {
                metodo: 'POST',
                formulario: { _method: 'PUT', name: perfilForm.elements.name.value.trim(), email: perfilForm.elements.email.value.trim() },
            }));
            salvo('Perfil atualizado.');
        } catch (erro) {
            if (erro.dados && erro.dados.errors) { pintarErros(perfilForm, erro.dados.errors); D2UI.toast('Confira os campos destacados.', { tipo: 'erro' }); }
            else falhou(erro, 'Não foi possível salvar o perfil.');
        }
    });
}

/* ---------- Modelo de IA do usuário ---------- */
for (const sel of document.querySelectorAll('.js-modelo-usuario')) {
    let anterior = sel.value;
    sel.addEventListener('change', async () => {
        sel.disabled = true;
        try {
            await chamar('/settings/user-model', { metodo: 'POST', formulario: { scope: sel.dataset.escopo, model: sel.value } });
            anterior = sel.value;
            salvo('Modelo salvo. Vale a partir da próxima abertura.');
        } catch (erro) {
            sel.value = anterior;
            falhou(erro, 'Não foi possível salvar o modelo.');
        } finally {
            sel.disabled = false;
        }
    });
}

/* ---------- Conta do Claude: escolha e uso ---------- */
const licencas = $('licencas');
if (licencas) {
    const cartoes = [...licencas.querySelectorAll('.js-licenca')];
    for (const cartao of cartoes) {
        cartao.addEventListener('click', async () => {
            if (cartao.getAttribute('aria-checked') === 'true') return;
            if (cartao.classList.contains('is-bloqueada')) {
                D2UI.toast('Esta conta está no teto de uso definido pelo administrador. Fica indisponível até a janela renovar.', { tipo: 'aviso' });
                return;
            }
            licencas.setAttribute('aria-busy', 'true');
            cartao.classList.add('is-salvando');
            try {
                await chamar('/settings/license', { metodo: 'POST', formulario: { license_id: cartao.dataset.id } });
                for (const c of cartoes) c.setAttribute('aria-checked', c === cartao ? 'true' : 'false');
                salvo('Conta selecionada. Nas IDEs já abertas a troca é aplicada em até 60 segundos.');
            } catch (erro) {
                falhou(erro, 'Não foi possível salvar a conta.');
            } finally {
                licencas.removeAttribute('aria-busy');
                cartao.classList.remove('is-salvando');
            }
        });
    }

    const nivel = (u, status, teto) => {
        if (status === 'rejected' || u >= 0.9 || (teto && Math.floor(u * 100) >= teto)) return 'erro';
        if (status === 'allowed_warning' || u >= 0.75) return 'aviso';
        return 'ok';
    };
    const renova = (epoch) => {
        const s = epoch - Math.floor(Date.now() / 1000);
        if (s <= 0) return 'renovando agora';
        const d = Math.floor(s / 86400), h = Math.floor((s % 86400) / 3600), m = Math.floor((s % 3600) / 60);
        if (d > 0) return `renova em ${d}d ${h}h`;
        if (h > 0) return `renova em ${h}h ${String(m).padStart(2, '0')}min`;
        return `renova em ${Math.max(m, 1)} min`;
    };
    const el = (tag, classe, texto) => { const n = document.createElement(tag); if (classe) n.className = classe; if (texto !== undefined) n.textContent = texto; return n; };
    function linha(rotulo, janela, teto) {
        const u = Math.max(0, Math.min(1, Number(janela.utilization) || 0));
        const pct = Math.round(u * 100);
        const k = nivel(u, janela.status, teto);
        let dica = `${rotulo}: ${pct}% do limite usado`;
        if (janela.reset) dica += `, ${renova(Number(janela.reset))}`;
        if (janela.status === 'rejected') dica += ' (limite atingido)';
        if (teto) dica += Math.floor(u * 100) >= teto ? `. No teto de ${teto}%, pausada até renovar.` : `. Teto: ${teto}%.`;
        const linhaEl = el('span', 'conta-uso-linha');
        linhaEl.dataset.tooltip = dica;
        const prog = el('span', `d2-progresso conta-uso-barra is-${k}`);
        const trilho = el('span', 'd2-progresso-trilho');
        trilho.setAttribute('role', 'progressbar');
        trilho.setAttribute('aria-valuemin', '0');
        trilho.setAttribute('aria-valuemax', '100');
        trilho.setAttribute('aria-valuenow', String(pct));
        trilho.setAttribute('aria-label', dica);
        const barra = el('span', 'd2-progresso-barra');
        barra.style.transform = `scaleX(${u > 0 ? Math.max(u, 0.02) : 0})`;
        trilho.append(barra);
        if (teto) { const marca = el('span', 'conta-uso-teto'); marca.style.insetInlineStart = `${teto}%`; trilho.append(marca); }
        prog.append(trilho);
        linhaEl.append(el('span', 'conta-uso-rotulo', rotulo), prog, el('span', `conta-uso-pct d2-num is-${k}`, `${pct}%`));
        return linhaEl;
    }
    const indisponivel = (cel) => cel.replaceChildren(el('span', 'texto-mudo', 'Uso indisponível'));

    (async () => {
        const celulas = licencas.querySelectorAll('.js-uso');
        if (!celulas.length) return;
        let uso = {};
        try {
            const r = await chamar('/settings/licenses/usage');
            uso = (r && r.usage) || {};
        } catch (e) {
            celulas.forEach(indisponivel);
            return;
        }
        for (const cel of celulas) {
            const s = uso[cel.dataset.id];
            if (!s || !s.ok) { indisponivel(cel); continue; }
            const teto = Number(s.limit) || 0;
            const partes = [];
            if (s.five_hour) partes.push(linha('Sessão 5h', s.five_hour, teto));
            if (s.seven_day) partes.push(linha('Semana', s.seven_day, teto));
            if (s.fable) partes.push(linha('Fable', s.fable, 0));
            if (!partes.length) { indisponivel(cel); continue; }
            let noTeto = false;
            if (teto) {
                const passou = (j) => j && Math.floor((Number(j.utilization) || 0) * 100) >= teto;
                noTeto = passou(s.five_hour) || passou(s.seven_day);
                partes.push(el('span', 'conta-uso-nota' + (noTeto ? ' is-erro' : ''),
                    noTeto ? `No teto de ${teto}%, pausada até a janela renovar` : `Teto definido pelo administrador: ${teto}%`));
            }
            cel.replaceChildren(...partes);
            // O cartão "Padrão do sistema" nunca bloqueia: é a escolha de voltar ao padrão.
            const cartao = cel.closest('.js-licenca');
            if (cartao && cartao.dataset.id !== '0') {
                cartao.classList.toggle('is-bloqueada', noTeto);
                cartao.setAttribute('aria-disabled', noTeto ? 'true' : 'false');
            }
        }
    })();
}

/* ---------- Senha ---------- */
const senhaForm = $('senha-form');
if (senhaForm) {
    senhaForm.addEventListener('submit', async (ev) => {
        ev.preventDefault();
        const f = senhaForm.elements;
        pintarErros(senhaForm, null);
        const locais = {};
        if (!f.current_password.value) locais.current_password = ['Informe a senha atual.'];
        if (f.password.value.length < 8) locais.password = ['A nova senha precisa de pelo menos 8 caracteres.'];
        if (f.password.value !== f.password_confirmation.value) locais.password_confirmation = ['A confirmação não confere com a nova senha.'];
        if (Object.keys(locais).length) { pintarErros(senhaForm, locais); return; }
        try {
            await esperando(senhaForm.querySelector('[type="submit"]'), () => chamar('/settings/password', {
                metodo: 'POST',
                formulario: { _method: 'PUT', current_password: f.current_password.value, password: f.password.value, password_confirmation: f.password_confirmation.value },
            }));
            senhaForm.reset();
            salvo('Senha alterada.');
        } catch (erro) {
            if (erro.dados && erro.dados.errors) pintarErros(senhaForm, erro.dados.errors);
            else falhou(erro, 'Não foi possível alterar a senha.');
        }
    });
}

/* ---------- Verificação em duas etapas ---------- */
function carregarQr() {
    if (window.QRCode) return Promise.resolve(true);
    return new Promise((ok) => {
        const s = document.createElement('script');
        s.src = '/assets/js/vendor/qrcode.min.js';
        s.onload = () => ok(true);
        s.onerror = () => ok(false); // sem a biblioteca sobra a chave manual: o fluxo não quebra
        document.head.appendChild(s);
    });
}
const tfaIniciar = $('tfa-iniciar');
if (tfaIniciar) {
    tfaIniciar.addEventListener('click', async () => {
        try {
            const d = await esperando(tfaIniciar, () => chamar('/settings/2fa/setup', { metodo: 'POST', formulario: {} }));
            if (!d || !d.secret) throw new Error('');
            $('tfa-segredo').textContent = d.secret;
            if (d.uri && await carregarQr() && window.QRCode) {
                const caixa = $('tfa-qr');
                caixa.replaceChildren();
                new window.QRCode(caixa, { text: d.uri, width: 176, height: 176, correctLevel: window.QRCode.CorrectLevel.M });
            } else {
                $('tfa-qr').hidden = true;
                $('tfa-config').querySelector('details').open = true;
            }
            $('tfa-config').hidden = false;
            tfaIniciar.hidden = true;
            $('tfa-codigo').focus();
        } catch (erro) {
            falhou(erro, 'Não foi possível iniciar a verificação.');
        }
    });
    $('tfa-copiar').addEventListener('click', async () => {
        try {
            await navigator.clipboard.writeText($('tfa-segredo').textContent || '');
            salvo('Chave copiada.');
        } catch (e) {
            D2UI.toast('Não foi possível copiar. Selecione a chave e copie manualmente.', { tipo: 'aviso' });
        }
    });
    $('tfa-codigo').addEventListener('input', (ev) => { ev.target.value = ev.target.value.replace(/\D/g, '').slice(0, 6); });
    $('tfa-confirmar-form').addEventListener('submit', async (ev) => {
        ev.preventDefault();
        const codigo = $('tfa-codigo').value.trim();
        if (codigo.length !== 6) { D2UI.toast('Digite os 6 dígitos do app autenticador.', { tipo: 'aviso' }); return; }
        try {
            await esperando($('tfa-confirmar'), () => chamar('/settings/2fa/enable', { metodo: 'POST', formulario: { code: codigo } }));
            recarregar('Verificação ativada');
        } catch (erro) {
            falhou(erro, 'Código inválido.');
        }
    });
}
const tfaDesativar = $('tfa-desativar-form');
if (tfaDesativar) {
    tfaDesativar.addEventListener('submit', async (ev) => {
        ev.preventDefault();
        const senha = $('tfa-senha').value;
        if (!senha) { D2UI.toast('Informe sua senha para desativar.', { tipo: 'aviso' }); $('tfa-senha').focus(); return; }
        const ok = await D2UI.confirmar({ titulo: 'Desativar verificação', texto: 'Sem a verificação em duas etapas, basta a senha para entrar na sua conta. Desativar mesmo assim?', rotuloOk: 'Desativar', perigo: true });
        if (!ok) return;
        try {
            await esperando($('tfa-desativar'), () => chamar('/settings/2fa/disable', { metodo: 'POST', formulario: { password: senha } }));
            recarregar('Verificação desativada');
        } catch (erro) {
            falhou(erro, 'Senha incorreta.');
        }
    });
}

/* ---------- Aparelhos conectados ---------- */
const aparelhos = $('aparelhos');
if (aparelhos) {
    const novo = (tag, classe, texto) => { const n = document.createElement(tag); if (classe) n.className = classe; if (texto !== undefined) n.textContent = texto; return n; };
    const selo = (texto, tipo) => {
        const s = novo('span', `d2-selo d2-selo-${tipo}`);
        const p = novo('span', 'd2-ponto'); p.setAttribute('aria-hidden', 'true');
        s.append(p, novo('span', 'd2-selo-texto', texto));
        return s;
    };
    function item(titulo, seloEl, sub, botao) {
        const li = document.createElement('li');
        const linha = novo('div', 'lista-item');
        const texto = novo('div', 'lista-item-texto');
        const tit = novo('span', 'lista-item-titulo conta-aparelho-titulo');
        tit.append(document.createTextNode(titulo), seloEl);
        texto.append(tit, novo('span', 'lista-item-sub', sub));
        linha.append(texto, botao);
        li.append(linha);
        return li;
    }
    async function carregarAparelhos() {
        aparelhos.replaceChildren(D2UI.esqueleto(2));
        let d;
        try {
            d = await chamar('/settings/sessions');
        } catch (erro) {
            aparelhos.replaceChildren(D2UI.vazio({ chave: 'Não foi possível carregar os aparelhos', icone: 'erro', acao: { rotulo: 'Tentar de novo', icone: 'atualizar', fn: carregarAparelhos } }));
            return;
        }
        const lista = novo('ul', 'lista-acionavel');
        if (d && !d.current_trusted) {
            const c = d.current || {};
            const btn = novo('button', 'd2-btn d2-btn-sm d2-btn-primario', 'Confiar neste navegador');
            btn.type = 'button';
            btn.addEventListener('click', async () => {
                try {
                    await esperando(btn, () => chamar('/settings/sessions/trust', { metodo: 'POST', formulario: {} }));
                    salvo('Navegador confiável. Você fica conectado neste aparelho.');
                    carregarAparelhos();
                } catch (erro) { falhou(erro, 'Não foi possível salvar este navegador.'); }
            });
            lista.append(item(c.label || 'Este navegador', selo('este aparelho', 'info'),
                'Não está salvo' + (c.ip ? `, IP ${c.ip}` : '') + ', vai pedir login quando a sessão expirar', btn));
        }
        for (const ap of (d && d.devices) || []) {
            const btn = novo('button', 'd2-btn d2-btn-sm d2-btn-fantasma conta-revogar', 'Revogar');
            btn.type = 'button';
            btn.setAttribute('aria-label', `Revogar ${ap.label}`);
            btn.addEventListener('click', async () => {
                const ok = await D2UI.confirmar({ titulo: 'Revogar aparelho', texto: 'Esse aparelho vai precisar entrar com login e senha de novo no próximo acesso. Revogar?', rotuloOk: 'Revogar', perigo: true });
                if (!ok) return;
                try {
                    await esperando(btn, () => chamar('/settings/sessions/revoke', { metodo: 'POST', formulario: { type: 'device', id: String(ap.id) } }));
                    salvo('Aparelho revogado.');
                    carregarAparelhos();
                } catch (erro) { falhou(erro, 'Não foi possível revogar o aparelho.'); }
            });
            const sub = [ap.ip ? `IP ${ap.ip}` : '', ap.last ? `último acesso ${ap.last}` : ''].filter(Boolean).join(', ');
            lista.append(item(ap.label, ap.current ? selo('este aparelho', 'info') : selo('confiável', 'ok'), sub, btn));
        }
        if (!lista.children.length) {
            aparelhos.replaceChildren(D2UI.vazio({
                chave: 'Nenhum aparelho confiável ainda',
                texto: 'Marque "manter conectado" ao entrar para ficar logado sem precisar entrar de novo.',
                icone: 'dispositivo',
                acao: { rotulo: 'Atualizar', icone: 'atualizar', fn: carregarAparelhos },
            }));
            return;
        }
        aparelhos.replaceChildren(lista);
    }
    carregarAparelhos();
}

/* ---------- Excluir conta ---------- */
const excluir = $('conta-excluir');
if (excluir) {
    excluir.addEventListener('click', () => {
        const corpo = document.createElement('div');
        corpo.className = 'd2-pilha';
        const aviso = document.createElement('p');
        aviso.textContent = 'Todos os seus projetos, conversas e arquivos gerados serão removidos para sempre. Esta ação não pode ser desfeita. Confirme com a sua senha.';
        const campo = document.createElement('div');
        campo.className = 'd2-field';
        campo.innerHTML = '<label class="d2-label" for="excluir-senha">Senha</label>'
            + '<input type="password" class="d2-control" id="excluir-senha" autocomplete="current-password">'
            + '<div class="d2-field-erro" id="excluir-senha-erro" role="alert"></div>';
        corpo.append(aviso, campo);
        D2UI.modal({
            titulo: 'Excluir minha conta',
            corpo,
            perigo: true,
            largura: 460,
            botoes: [
                { rotulo: 'Cancelar', tipo: 'secundario' },
                {
                    rotulo: 'Excluir conta', tipo: 'perigo', icone: 'lixeira',
                    fn: async () => {
                        const entrada = corpo.querySelector('#excluir-senha');
                        const erroEl = corpo.querySelector('#excluir-senha-erro');
                        erroEl.textContent = '';
                        if (!entrada.value) { erroEl.textContent = 'Confirme sua senha para excluir a conta.'; entrada.focus(); return false; }
                        try {
                            await chamar('/settings/account', { metodo: 'DELETE', dados: { password: entrada.value } });
                            D2UI.progresso({ titulo: 'Conta excluída' });
                            location.href = '/login';
                            return false;
                        } catch (erro) {
                            const lista = erro.dados && erro.dados.errors && erro.dados.errors.password;
                            erroEl.textContent = lista ? [].concat(lista)[0] : ((erro && erro.message) || 'Não foi possível excluir a conta.');
                            entrada.focus();
                            return false;
                        }
                    },
                },
            ],
        });
        setTimeout(() => { const e = corpo.querySelector('#excluir-senha'); if (e) e.focus(); }, 50);
    });
}

/* ---------- Notificações ---------- */
for (const cb of document.querySelectorAll('.js-pref')) {
    cb.addEventListener('change', async () => {
        cb.disabled = true;
        try {
            await chamar('/settings/notifications', { metodo: 'POST', formulario: { key: cb.dataset.pref, enabled: cb.checked ? '1' : '0' } });
            salvo('Preferência salva.');
        } catch (erro) {
            cb.checked = !cb.checked;
            falhou(erro, 'Não foi possível salvar.');
        } finally {
            cb.disabled = false;
        }
    });
}
const minutos = $('ide-alerta-min');
if (minutos) {
    let espera;
    let ultimo = minutos.value;
    const salvarMinutos = async () => {
        let v = parseInt(minutos.value, 10);
        if (Number.isNaN(v) || v < 1) v = 10;
        if (v > 240) v = 240;
        minutos.value = String(v);
        if (String(v) === ultimo) return;
        minutos.setAttribute('aria-busy', 'true');
        try {
            await chamar('/settings/notifications', { metodo: 'POST', formulario: { key: 'ide_alert_minutes', value: String(v) } });
            ultimo = String(v);
            salvo('Preferência salva.');
        } catch (erro) {
            falhou(erro, 'Não foi possível salvar.');
        } finally {
            minutos.removeAttribute('aria-busy');
        }
    };
    minutos.addEventListener('change', () => { clearTimeout(espera); salvarMinutos(); });
    minutos.addEventListener('input', () => { clearTimeout(espera); espera = setTimeout(salvarMinutos, 700); });
}

/* ---------- GitHub ---------- */
const ghDesconectar = $('gh-desconectar-form');
if (ghDesconectar) {
    ghDesconectar.addEventListener('submit', async (ev) => {
        if (ghDesconectar.dataset.confirmado === '1') return;
        ev.preventDefault();
        const ok = await D2UI.confirmar({ titulo: 'Desconectar GitHub', texto: 'Desconectar sua conta do GitHub? Os projetos já vinculados deixam de sincronizar até você conectar de novo.', rotuloOk: 'Desconectar', perigo: true });
        if (!ok) return;
        ghDesconectar.dataset.confirmado = '1';
        const botao = ghDesconectar.querySelector('[type="submit"]');
        botao.classList.add('d2-btn-carregando');
        botao.disabled = true;
        ghDesconectar.submit();
    });
}
const ghConectar = $('gh-conectar');
if (ghConectar) ghConectar.addEventListener('click', () => ghConectar.classList.add('d2-btn-carregando'));
