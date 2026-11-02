<?php
/** Admin: licenças do ChatGPT (Codex na IDE), auth.json por conta e escolha por usuário. */
$this->layout('layouts/painel', [
    'title'       => $title ?? t('licencas.titulo_gpt', 'Licenças do ChatGPT'),
    'pageTitle'   => t('licencas.titulo_gpt', 'Licenças do ChatGPT'),
    'currentPage' => 'admin-licenses',
    'area'        => 'licencas',
    'subtitulo'   => t('licencas.sub_gpt', 'Contas ChatGPT (Plus, Pro, Business, Enterprise) e qual delas o Codex usa na IDE de cada usuário: a extensão oficial do Codex e o comando codex do terminal já abrem logados. As mudanças chegam às IDEs abertas em até 1 minuto.'),
    'acoes'       => '<button type="button" class="d2-btn d2-btn-fantasma" data-acao="ajuda-auth">' . d2icone('info') . '<span>' . e(t('licencas.gpt_como_obter', 'Como obter a credencial')) . '</span></button>'
                   . '<button type="button" class="d2-btn d2-btn-primario" data-acao="nova-licenca">' . d2icone('mais') . '<span>' . e(t('licencas.nova', 'Nova licença')) . '</span></button>',
    'estilos'     => ['/assets/css/paginas/licencas.css'],
    'scripts'     => ['/assets/js/paginas/admin-licencas-chatgpt.js'],
]);

$licenses = $licenses ?? [];
$opcoesLicencas = $opcoesLicencas ?? [];
$usoPorLicenca = $usoPorLicenca ?? [];
$userRows = $userRows ?? [];

$rotuloPlano = static function (string $plano): string {
    $mapa = ['plus' => 'Plus', 'pro' => 'Pro', 'team' => 'Business', 'business' => 'Business', 'enterprise' => 'Enterprise', 'edu' => 'Edu', 'free' => 'Free'];
    return $mapa[strtolower($plano)] ?? ($plano !== '' ? ucfirst($plano) : '');
};

$totalAtivas = count(array_filter($licenses, static fn($l) => !empty($l['is_active'])));
$totalEmUso = count(array_filter($licenses, static fn($l) => !empty($usoPorLicenca[(int) $l['id']])));
$totalComEscolha = count(array_filter($userRows, static fn($u) => !empty($u['chatgpt']) && isset($opcoesLicencas[(int) $u['chatgpt']])));

$dadosPagina = [
    'licencas' => array_map(static fn($l) => [
        'id'   => (int) $l['id'],
        'nome' => (string) $l['name'],
        'nota' => (string) ($l['note'] ?? ''),
    ], $licenses),
    'textos' => [
        'nova_titulo'    => t('licencas.gpt_nova', 'Nova licença do ChatGPT'),
        'editar_titulo'  => t('licencas.editar_titulo', 'Editar licença'),
        'cadastrar'      => t('licencas.cadastrar', 'Cadastrar licença'),
        'salvar'         => t('licencas.salvar', 'Salvar'),
        'cancelar'       => t('licencas.cancelar', 'Cancelar'),
        'fechar'         => t('licencas.fechar', 'Fechar'),
        'ajuda_titulo'   => t('licencas.gpt_ajuda_titulo', 'Como obter a credencial de uma licença'),
        'copiado'        => t('licencas.copiado', 'Copiado.'),
        'copiar_falhou'  => t('licencas.copiar_falhou', 'Não foi possível copiar. Selecione o texto e copie manualmente.'),
        'excluir_titulo' => t('licencas.excluir_titulo', 'Excluir licença'),
        'excluir_texto'  => t('licencas.gpt_excluir_texto', 'Excluir ":nome"? As IDEs que a usavam ficam com o Codex deslogado.'),
        'excluir_ok'     => t('licencas.excluir', 'Excluir'),
        'teste_ok'       => t('licencas.teste_ok', 'Licença válida e autenticada.'),
        'teste_falha'    => t('licencas.teste_falha', 'Falha ao autenticar a licença.'),
        'teste_detalhe'  => t('licencas.teste_detalhe', 'Detalhe do teste'),
        'teste_erro'     => t('licencas.teste_erro', 'Não foi possível testar agora.'),
        'salvo_global'   => t('licencas.gpt_salvo_global', 'Licença padrão salva. As IDEs recebem a mudança em até 1 minuto.'),
        'salvo_usuario'  => t('licencas.salvo_gpt_usuario', 'Atribuição salva. A IDE do usuário recebe a licença em até 1 minuto.'),
    ],
];
?>

<?php $abaAtiva = 'chatgpt'; $totalChatgpt = count($licenses); include __DIR__ . '/../partials/painel/abas-licencas.php'; ?>

<div class="d2-pilha licencas">
    <section class="indicadores" aria-label="<?= e(t('licencas.numeros', 'Resumo das licenças')) ?>">
        <div class="d2-indicador" data-area="licencas">
            <span class="d2-indicador-rotulo"><?= d2icone('chave') ?><span><?= e(t('licencas.ind_ativas', 'Licenças ativas')) ?></span></span>
            <span class="d2-indicador-valor d2-num"><?= (int) $totalAtivas ?> <span class="licencas-ind-de"><?= e(t('licencas.ind_de', 'de :n', ['n' => count($licenses)])) ?></span></span>
        </div>
        <div class="d2-indicador" data-area="licencas" data-tooltip="<?= e(t('licencas.gpt_ind_em_uso_dica', 'Licenças escolhidas como padrão da IDE ou por algum usuário')) ?>">
            <span class="d2-indicador-rotulo"><?= d2icone('usuarios') ?><span><?= e(t('licencas.ind_em_uso', 'Em uso')) ?></span></span>
            <span class="d2-indicador-valor d2-num"><?= (int) $totalEmUso ?></span>
        </div>
        <div class="d2-indicador" data-area="licencas" data-tooltip="<?= e(t('licencas.gpt_ind_usuarios_dica', 'Usuários com licença própria do ChatGPT (os demais herdam o padrão)')) ?>">
            <span class="d2-indicador-rotulo"><?= d2icone('conta') ?><span><?= e(t('licencas.gpt_ind_usuarios', 'Escolha própria')) ?></span></span>
            <span class="d2-indicador-valor d2-num"><?= (int) $totalComEscolha ?> <span class="licencas-ind-de"><?= e(t('licencas.ind_de', 'de :n', ['n' => count($userRows)])) ?></span></span>
        </div>
    </section>

    <?php if (empty($licenses)): ?>
        <section class="d2-painel" aria-labelledby="t-licencas-vazio">
            <header class="d2-painel-cab">
                <span class="d2-painel-icone" aria-hidden="true"><?= d2icone('chave') ?></span>
                <h2 class="d2-painel-titulo" id="t-licencas-vazio"><?= e(t('licencas.cadastradas', 'Licenças cadastradas')) ?></h2>
            </header>
            <div class="d2-painel-corpo">
                <div class="d2-vazio">
                    <span class="d2-vazio-icone" aria-hidden="true"><?= d2icone('chave') ?></span>
                    <p class="d2-vazio-titulo"><?= e(t('licencas.gpt_vazio_titulo', 'Nenhuma licença do ChatGPT cadastrada')) ?></p>
                    <p class="d2-vazio-texto"><?= e(t('licencas.gpt_vazio_texto', 'Sem licença, o Codex da IDE abre deslogado (cada usuário pode entrar com a própria conta, se quiser).')) ?></p>
                    <button type="button" class="d2-btn d2-btn-primario" data-acao="nova-licenca"><?= d2icone('mais') ?><span><?= e(t('licencas.cadastrar', 'Cadastrar licença')) ?></span></button>
                </div>
            </div>
        </section>
    <?php else: ?>
        <h2 class="licencas-secao-titulo"><?= e(t('licencas.cadastradas', 'Licenças cadastradas')) ?></h2>
        <div class="d2-mosaico licencas-lista">
            <?php foreach ($licenses as $l): ?>
                <?php
                $lid = (int) $l['id'];
                $ativa = !empty($l['is_active']);
                $usos = $usoPorLicenca[$lid] ?? [];
                $modo = (string) ($l['auth_mode'] ?? '');
                $plano = $rotuloPlano((string) ($l['plan_type'] ?? ''));
                $tipoConta = ($modo === 'chatgpt' || $modo === '')
                    ? trim('ChatGPT ' . $plano)
                    : t('licencas.gpt_api_key', 'Chave de API (cobrança por uso)');
                ?>
                <section class="d2-painel licenca<?= $ativa ? '' : ' is-inativa' ?>" data-area="licencas" aria-labelledby="t-lic-<?= $lid ?>">
                    <header class="d2-painel-cab">
                        <span class="d2-painel-icone" aria-hidden="true"><?= d2icone('chave') ?></span>
                        <h3 class="d2-painel-titulo licenca-nome" id="t-lic-<?= $lid ?>"><?= e($l['name']) ?></h3>
                        <div class="d2-painel-acoes">
                            <button type="button" class="d2-btn d2-btn-sm d2-btn-secundario" data-acao="testar" data-id="<?= $lid ?>"><?= d2icone('check') ?><span><?= e(t('licencas.testar', 'Testar')) ?></span></button>
                        </div>
                    </header>
                    <div class="d2-painel-corpo">
                        <div class="licenca-selos">
                            <?php if ($ativa): ?>
                                <span class="d2-selo d2-selo-ok"><span class="d2-ponto" aria-hidden="true"></span><span class="d2-selo-texto"><?= e(t('licencas.ativa', 'Ativa')) ?></span></span>
                            <?php else: ?>
                                <span class="d2-selo d2-selo-neutro"><span class="d2-ponto" aria-hidden="true"></span><span class="d2-selo-texto"><?= e(t('licencas.inativa', 'Inativa')) ?></span></span>
                            <?php endif; ?>
                            <span class="d2-selo d2-selo-info"><span class="d2-selo-texto"><?= e($tipoConta) ?></span></span>
                        </div>

                        <dl class="kv licenca-kv">
                            <dt><?= e(t('licencas.gpt_conta', 'Conta')) ?></dt>
                            <dd><?= ($l['account_email'] ?? '') !== '' ? e($l['account_email']) : '<span class="texto-mudo">' . e(t('licencas.gpt_sem_email', 'não informada na credencial')) . '</span>' ?></dd>
                            <dt><?= e(t('licencas.gpt_credencial', 'Credencial')) ?></dt>
                            <dd><code class="codigo"><?= e($l['hint']) ?></code></dd>
                            <?php if (!empty($l['note'])): ?>
                                <dt><?= e(t('licencas.nota', 'Nota')) ?></dt>
                                <dd><?= e($l['note']) ?></dd>
                            <?php endif; ?>
                            <dt><?= e(t('licencas.gpt_renovada', 'Renovada em')) ?></dt>
                            <dd><?= !empty($l['last_refresh'])
                                ? e(date('d/m/Y H:i', strtotime((string) $l['last_refresh'] . ' UTC')))
                                : '<span class="texto-mudo">' . e(t('licencas.nao_informada', 'não informada')) . '</span>' ?></dd>
                            <dt><?= e(t('licencas.ultimo_uso', 'Último uso')) ?></dt>
                            <dd><?= !empty($l['last_used_at'])
                                ? e(date('d/m/Y H:i', strtotime((string) $l['last_used_at'])))
                                : '<span class="texto-mudo">' . e(t('licencas.nunca', 'nunca')) . '</span>' ?></dd>
                            <dt><?= e(t('licencas.em_uso_por', 'Em uso por')) ?></dt>
                            <dd><?= $usos ? e(implode(', ', $usos)) : '<span class="texto-mudo">' . e(t('licencas.nenhum_consumidor', 'nenhum consumidor')) . '</span>' ?></dd>
                        </dl>
                    </div>
                    <footer class="d2-painel-rodape licenca-rodape">
                        <button type="button" class="d2-btn d2-btn-sm d2-btn-fantasma" data-acao="editar" data-id="<?= $lid ?>"><?= d2icone('editar') ?><span><?= e(t('licencas.editar', 'Editar')) ?></span></button>
                        <form method="POST" action="/admin/licenses/chatgpt/<?= $lid ?>/toggle" class="licenca-form-inline" data-carregando>
                            <?= csrf_field() ?>
                            <button type="submit" class="d2-btn d2-btn-sm d2-btn-secundario"><?= d2icone($ativa ? 'offline' : 'online') ?><span><?= e($ativa ? t('licencas.desativar', 'Desativar') : t('licencas.ativar', 'Ativar')) ?></span></button>
                        </form>
                        <form method="POST" action="/admin/licenses/chatgpt/<?= $lid ?>/delete" class="licenca-form-inline" data-excluir="<?= e($l['name']) ?>">
                            <?= csrf_field() ?>
                            <button type="submit" class="d2-iconbtn d2-iconbtn-perigo d2-iconbtn-sm" aria-label="<?= e(t('licencas.excluir_aria', 'Excluir :nome', ['nome' => $l['name']])) ?>" data-tooltip="<?= e(t('licencas.excluir', 'Excluir')) ?>"><?= d2icone('lixeira') ?></button>
                        </form>
                    </footer>
                </section>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- Licença padrão da IDE -->
    <section class="d2-painel" data-area="licencas" aria-labelledby="t-gpt-padrao">
        <header class="d2-painel-cab">
            <span class="d2-painel-icone" aria-hidden="true"><?= d2icone('plataforma') ?></span>
            <h2 class="d2-painel-titulo" id="t-gpt-padrao"><?= e(t('licencas.gpt_padrao', 'Licença padrão da IDE')) ?></h2>
        </header>
        <div class="d2-painel-corpo">
            <p class="texto-mudo licencas-explica"><?= e(t('licencas.gpt_padrao_texto', 'Qual licença do ChatGPT o Codex usa na IDE de quem não tem uma escolha própria (tabela abaixo). "Nenhuma" deixa o Codex deslogado por padrão.')) ?></p>
            <div class="form-grade">
                <div class="d2-field">
                    <label class="d2-label" for="gpt-global"><?= e(t('licencas.gpt_ide_codex', 'IDE (Codex)')) ?></label>
                    <select class="d2-control" id="gpt-global" data-atribuir-global <?= empty($licenses) ? 'disabled' : '' ?>>
                        <?php $opcoes = $opcoesLicencas; $selecionada = $global ?? null; $rotuloZero = t('licencas.gpt_nenhuma', 'nenhuma (Codex deslogado)'); include __DIR__ . '/../partials/painel/opcoes-licenca.php'; ?>
                    </select>
                    <?php if (empty($licenses)): ?><span class="d2-field-hint"><?= e(t('licencas.gpt_padrao_vazio', 'Cadastre uma licença para escolher o padrão.')) ?></span><?php endif; ?>
                </div>
            </div>
        </div>
    </section>

    <!-- Licença por usuário -->
    <section class="d2-painel" data-area="licencas" aria-labelledby="t-por-usuario">
        <header class="d2-painel-cab">
            <span class="d2-painel-icone" aria-hidden="true"><?= d2icone('usuarios') ?></span>
            <h2 class="d2-painel-titulo" id="t-por-usuario"><?= e(t('licencas.por_usuario', 'Licença por usuário')) ?></h2>
        </header>
        <?php if (empty($hasActive)): ?>
            <div class="d2-painel-corpo">
                <div class="d2-vazio">
                    <span class="d2-vazio-icone" aria-hidden="true"><?= d2icone('usuarios') ?></span>
                    <p class="d2-vazio-titulo"><?= e(t('licencas.gpt_sem_ativa_titulo', 'Nenhuma licença do ChatGPT ativa')) ?></p>
                    <p class="d2-vazio-texto"><?= e(t('licencas.gpt_sem_ativa_texto', 'A escolha por usuário aparece quando houver ao menos uma licença do ChatGPT ativa. A licença do Claude de cada usuário continua na aba Claude Code.')) ?></p>
                    <?php if (empty($licenses)): ?>
                        <button type="button" class="d2-btn d2-btn-primario" data-acao="nova-licenca"><?= d2icone('mais') ?><span><?= e(t('licencas.cadastrar', 'Cadastrar licença')) ?></span></button>
                    <?php else: ?>
                        <a class="d2-btn d2-btn-secundario" href="/admin/licenses"><?= e(t('licencas.ir_claude', 'Ir para Claude Code')) ?></a>
                    <?php endif; ?>
                </div>
            </div>
        <?php else: ?>
            <div class="d2-painel-corpo">
                <p class="texto-mudo licencas-explica"><?= e(t('licencas.gpt_por_usuario_texto', 'Sobrescreve o padrão só para o usuário escolhido. A licença do Claude de cada usuário fica na aba Claude Code (a coluna IDE (ChatGPT) também está disponível lá).')) ?></p>
            </div>
            <?php if (empty($userRows)): ?>
                <div class="d2-painel-corpo">
                    <div class="d2-vazio">
                        <span class="d2-vazio-icone" aria-hidden="true"><?= d2icone('usuarios') ?></span>
                        <p class="d2-vazio-titulo"><?= e(t('licencas.sem_usuarios', 'Nenhum usuário cadastrado')) ?></p>
                        <a class="d2-btn d2-btn-primario" href="/admin/users"><?= e(t('licencas.ir_usuarios', 'Ir para Usuários')) ?></a>
                    </div>
                </div>
            <?php else: ?>
                <div class="d2-tabela-caixa">
                    <table class="d2-tabela licencas-tabela-usuarios">
                        <thead class="d2-tabela-cab">
                            <tr>
                                <th scope="col"><?= e(t('licencas.usuario', 'Usuário')) ?></th>
                                <th scope="col"><?= e(t('licencas.ide_chatgpt', 'IDE (ChatGPT)')) ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($userRows as $u): ?>
                                <tr class="d2-tabela-linha">
                                    <th scope="row" class="d2-tabela-cel licencas-usuario">
                                        <span class="licencas-usuario-nome"><?= e($u['name']) ?></span>
                                        <span class="texto-mudo"><?= e($u['email']) ?></span>
                                    </th>
                                    <td class="d2-tabela-cel" data-rotulo="<?= e(t('licencas.ide_chatgpt', 'IDE (ChatGPT)')) ?>">
                                        <select class="d2-control" data-atribuir-usuario="<?= (int) $u['id'] ?>" aria-label="<?= e(t('licencas.ide_chatgpt_de', 'IDE (ChatGPT) de :nome', ['nome' => $u['name']])) ?>">
                                            <?php $opcoes = $opcoesLicencas; $selecionada = $u['chatgpt'] ?? null; $rotuloZero = t('licencas.herda', 'Herda o padrão'); include __DIR__ . '/../partials/painel/opcoes-licenca.php'; ?>
                                        </select>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </section>
</div>

<!-- Formulário de cadastro e edição (aberto num modal pelo módulo da página) -->
<template id="modelo-form-licenca">
    <form method="POST" action="/admin/licenses/chatgpt/create" class="licencas-form" novalidate>
        <?= csrf_field() ?>
        <div class="form-grade">
            <div class="d2-field">
                <label class="d2-label" for="lic-nome"><?= e(t('licencas.nome', 'Nome')) ?></label>
                <input class="d2-control" type="text" id="lic-nome" name="name" maxlength="100" required placeholder="<?= e(t('licencas.gpt_nome_ex', 'ex.: ChatGPT Pro, conta principal')) ?>">
            </div>
            <div class="d2-field">
                <label class="d2-label" for="lic-nota"><?= e(t('licencas.nota_opcional', 'Nota (opcional)')) ?></label>
                <input class="d2-control" type="text" id="lic-nota" name="note" maxlength="255" placeholder="<?= e(t('licencas.nota_ex', 'ex.: conta do financeiro')) ?>">
            </div>
            <div class="d2-field is-cheio">
                <label class="d2-label" for="lic-auth" data-rotulo-novo="<?= e(t('licencas.gpt_auth_campo', 'Conteúdo do ~/.codex/auth.json')) ?>" data-rotulo-editar="<?= e(t('licencas.gpt_auth_novo', 'Novo auth.json (deixe vazio para manter o atual)')) ?>"><?= e(t('licencas.gpt_auth_campo', 'Conteúdo do ~/.codex/auth.json')) ?></label>
                <textarea class="d2-control licencas-token" id="lic-auth" name="auth" rows="5" autocomplete="off" spellcheck="false" data-ph-novo="<?= e(t('licencas.gpt_auth_ph', 'Cole aqui o JSON inteiro: {"auth_mode":"chatgpt","tokens":{...},"last_refresh":"..."}')) ?>" data-ph-editar="<?= e(t('licencas.gpt_auth_ph_editar', 'Cole um novo auth.json só se quiser substituir (ex.: depois de refazer o codex login)')) ?>"></textarea>
                <span class="d2-field-hint"><?= e(t('licencas.gpt_auth_dica', 'Trate o conteúdo como senha. Veja "Como obter a credencial".')) ?></span>
            </div>
        </div>
    </form>
</template>

<!-- Ajuda: como obter o auth.json -->
<template id="modelo-ajuda-auth">
    <div class="licencas-ajuda">
        <ol>
            <li><?= e(t('licencas.gpt_ajuda_1', 'Numa máquina com navegador, instale o Codex:')) ?>
                <div class="licencas-comando">
                    <pre class="codigo"><code>npm i -g @openai/codex</code></pre>
                    <button type="button" class="d2-iconbtn d2-iconbtn-fantasma d2-iconbtn-sm" data-copiar="npm i -g @openai/codex" aria-label="<?= e(t('licencas.copiar', 'Copiar')) ?>" data-tooltip="<?= e(t('licencas.copiar', 'Copiar')) ?>"><?= d2icone('copiar') ?></button>
                </div>
            </li>
            <li><?= e(t('licencas.gpt_ajuda_2', 'Entre com a conta ChatGPT da licença que você quer usar:')) ?>
                <div class="licencas-comando">
                    <pre class="codigo"><code>codex login</code></pre>
                    <button type="button" class="d2-iconbtn d2-iconbtn-fantasma d2-iconbtn-sm" data-copiar="codex login" aria-label="<?= e(t('licencas.copiar', 'Copiar')) ?>" data-tooltip="<?= e(t('licencas.copiar', 'Copiar')) ?>"><?= d2icone('copiar') ?></button>
                </div>
            </li>
            <li><?= e(t('licencas.gpt_ajuda_3', 'O Codex grava a credencial em ~/.codex/auth.json. Se a sua máquina usa o cofre do sistema em vez do arquivo, ponha a linha abaixo no ~/.codex/config.toml e repita o login:')) ?>
                <div class="licencas-comando">
                    <pre class="codigo"><code>cli_auth_credentials_store = "file"</code></pre>
                    <button type="button" class="d2-iconbtn d2-iconbtn-fantasma d2-iconbtn-sm" data-copiar='cli_auth_credentials_store = "file"' aria-label="<?= e(t('licencas.copiar', 'Copiar')) ?>" data-tooltip="<?= e(t('licencas.copiar', 'Copiar')) ?>"><?= d2icone('copiar') ?></button>
                </div>
            </li>
            <li><?= e(t('licencas.gpt_ajuda_4', 'Copie o conteúdo inteiro do arquivo e cole em "Nova licença":')) ?>
                <div class="licencas-comando">
                    <pre class="codigo"><code>cat ~/.codex/auth.json</code></pre>
                    <button type="button" class="d2-iconbtn d2-iconbtn-fantasma d2-iconbtn-sm" data-copiar="cat ~/.codex/auth.json" aria-label="<?= e(t('licencas.copiar', 'Copiar')) ?>" data-tooltip="<?= e(t('licencas.copiar', 'Copiar')) ?>"><?= d2icone('copiar') ?></button>
                </div>
            </li>
        </ol>
        <p class="texto-mudo"><?= e(t('licencas.gpt_ajuda_nota', 'É o caminho documentado pela OpenAI para máquinas sem navegador. Trate o conteúdo como senha. O Codex renova os tokens sozinho a cada 8 dias, mais ou menos, e o sistema guarda a versão renovada aqui; se uma licença parar de autenticar (conta deslogada, senha trocada, token revogado), refaça o codex login e cole o novo auth.json em "Editar".')) ?></p>
    </div>
</template>

<script type="application/json" id="dados-pagina"><?= json_encode($dadosPagina, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
