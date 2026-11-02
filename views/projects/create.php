<?php
/**
 * Novo projeto no layout do painel (d2): nome, descrição, ícone e cor, tipo, origem no GitHub
 * (em branco, clonar um repositório ou criar um repositório novo) e importação de um .zip.
 * Sem arquivo, o formulário vai pelo POST normal (/projects); com arquivo, o JS cria o projeto
 * e envia o .zip em seguida, com progresso. Dados: ProjectController@create.
 */
$github = !empty($github_connected);
$this->layout('layouts/painel', [
    'title'       => t('projetos.novo', 'Novo projeto'),
    'currentPage' => 'projects',
    'area'        => 'projetos',
    'subtitulo'   => t('projetos.novo_sub', 'Organize as conversas, os arquivos e a IDE de um sistema num lugar só.'),
    'scripts'     => ['/assets/js/paginas/projeto-criar.js'],
    'estilos'     => ['/assets/css/paginas/projeto.css'],
]);
$icones = [
    '📁' => t('projetos.icone_pasta', 'Pasta'),
    '🚀' => t('projetos.icone_foguete', 'Foguete'),
    '💻' => t('projetos.icone_computador', 'Computador'),
    '🌐' => t('projetos.icone_web', 'Web'),
    '📱' => t('projetos.icone_mobile', 'Celular'),
    '🔧' => t('projetos.icone_ferramenta', 'Ferramenta'),
    '📊' => t('projetos.icone_painel', 'Painel'),
    '🤖' => t('projetos.icone_bot', 'Robô'),
];
$matizes = [
    'indigo'  => t('projetos.cor_indigo', 'Índigo'),
    'azul'    => t('projetos.cor_azul', 'Azul'),
    'ciano'   => t('projetos.cor_ciano', 'Ciano'),
    'teal'    => t('projetos.cor_teal', 'Verde-azulado'),
    'roxo'    => t('projetos.cor_roxo', 'Roxo'),
    'magenta' => t('projetos.cor_magenta', 'Magenta'),
    'rosa'    => t('projetos.cor_rosa', 'Rosa'),
    'ardosia' => t('projetos.cor_ardosia', 'Ardósia'),
];
$tipos = [
    'web'  => [t('projetos.tipo_web', 'Web'), t('projetos.tipo_web_desc', 'Site ou sistema web'), 'computador'],
    'app'  => [t('projetos.tipo_app', 'App'), t('projetos.tipo_app_desc', 'Android e iOS'), 'celular'],
    'both' => [t('projetos.tipo_ambos', 'Ambos'), t('projetos.tipo_ambos_desc', 'Web e app'), 'dispositivo'],
];
?>

<form id="form-criar" class="projeto-criar" method="POST" action="/projects" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="source" id="criar-origem-valor" value="">
    <input type="hidden" name="color" id="criar-cor-valor" value="" disabled>

    <section class="d2-painel" data-area="projetos" aria-labelledby="t-identificacao">
        <header class="d2-painel-cab">
            <span class="d2-painel-icone" aria-hidden="true"><?= d2icone('editar') ?></span>
            <h3 class="d2-painel-titulo" id="t-identificacao"><?= e(t('projetos.identificacao', 'Identificação')) ?></h3>
        </header>
        <div class="d2-painel-corpo">
            <div class="form-grade">
                <div class="d2-field is-cheio">
                    <label class="d2-label" for="criar-nome"><span><?= e(t('projetos.nome', 'Nome do projeto')) ?></span><span class="d2-obrigatorio" aria-hidden="true">*</span></label>
                    <input class="d2-control" type="text" id="criar-nome" name="name" maxlength="200" required aria-required="true"
                           placeholder="<?= e(t('projetos.nome_exemplo', 'Ex.: Meu app web')) ?>" value="<?= e((string) old('name', '')) ?>" autocomplete="off" aria-describedby="criar-nome-erro">
                    <div class="d2-field-erro" id="criar-nome-erro" aria-live="polite"></div>
                </div>
                <div class="d2-field is-cheio">
                    <label class="d2-label" for="criar-descricao"><span><?= e(t('projetos.descricao', 'Descrição')) ?></span><span class="d2-opcional"><?= e(t('d2.opcional', 'opcional')) ?></span></label>
                    <textarea class="d2-control" id="criar-descricao" name="description" rows="3" maxlength="2000"
                              placeholder="<?= e(t('projetos.descricao_exemplo', 'Descreva brevemente o projeto')) ?>"><?= e((string) old('description', '')) ?></textarea>
                </div>
            </div>
        </div>
    </section>

    <section class="d2-painel" data-area="projetos" aria-labelledby="t-aparencia">
        <header class="d2-painel-cab">
            <span class="d2-painel-icone" aria-hidden="true"><?= d2icone('estrela') ?></span>
            <h3 class="d2-painel-titulo" id="t-aparencia"><?= e(t('projetos.aparencia', 'Ícone, cor e tipo')) ?></h3>
        </header>
        <div class="d2-painel-corpo projeto-criar-aparencia">
            <fieldset class="projeto-escolha">
                <legend class="d2-label"><?= e(t('projetos.icone', 'Ícone')) ?></legend>
                <div class="projeto-icones">
                    <?php $primeiro = true; foreach ($icones as $emoji => $rotulo): ?>
                        <label class="projeto-icone-opcao" data-tooltip="<?= e($rotulo) ?>">
                            <input type="radio" name="icon" value="<?= e($emoji) ?>" <?= $primeiro ? 'checked' : '' ?> aria-label="<?= e($rotulo) ?>">
                            <span aria-hidden="true"><?= e($emoji) ?></span>
                        </label>
                    <?php $primeiro = false; endforeach; ?>
                </div>
            </fieldset>
            <fieldset class="projeto-escolha">
                <legend class="d2-label"><?= e(t('projetos.cor', 'Cor')) ?></legend>
                <div class="projeto-cores">
                    <label class="projeto-cor-opcao" data-tooltip="<?= e(t('projetos.cor_padrao', 'Padrão')) ?>">
                        <input type="radio" name="matiz" value="" checked aria-label="<?= e(t('projetos.cor_padrao', 'Padrão')) ?>">
                        <span class="projeto-cor-amostra is-padrao" aria-hidden="true"></span>
                    </label>
                    <?php foreach ($matizes as $matiz => $rotulo): ?>
                        <label class="projeto-cor-opcao" data-tooltip="<?= e($rotulo) ?>">
                            <input type="radio" name="matiz" value="<?= e($matiz) ?>" aria-label="<?= e($rotulo) ?>">
                            <span class="projeto-cor-amostra matiz-<?= e($matiz) ?>" aria-hidden="true"></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </fieldset>
            <fieldset class="projeto-escolha">
                <legend class="d2-label"><?= e(t('projetos.tipo', 'Tipo de projeto')) ?></legend>
                <div class="projeto-cartoes">
                    <?php foreach ($tipos as $valor => [$rotulo, $desc, $icone]): ?>
                        <label class="projeto-cartao-opcao">
                            <input type="radio" name="type" value="<?= e($valor) ?>" <?= $valor === 'web' ? 'checked' : '' ?>>
                            <span class="projeto-cartao-icone" aria-hidden="true"><?= d2icone($icone) ?></span>
                            <span class="projeto-cartao-texto"><strong><?= e($rotulo) ?></strong><small><?= e($desc) ?></small></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </fieldset>
        </div>
    </section>

    <section class="d2-painel" data-area="projetos" aria-labelledby="t-origem">
        <header class="d2-painel-cab">
            <span class="d2-painel-icone" aria-hidden="true"><?= d2icone('plataforma') ?></span>
            <h3 class="d2-painel-titulo" id="t-origem"><?= e(t('projetos.como_comecar', 'Como começar')) ?></h3>
        </header>
        <div class="d2-painel-corpo">
            <?php if ($github): ?>
                <fieldset class="projeto-escolha">
                    <legend class="d2-oculto-leitor"><?= e(t('projetos.como_comecar', 'Como começar')) ?></legend>
                    <div class="projeto-cartoes">
                        <label class="projeto-cartao-opcao">
                            <input type="radio" name="origem" value="" checked>
                            <span class="projeto-cartao-icone" aria-hidden="true"><?= d2icone('mais') ?></span>
                            <span class="projeto-cartao-texto"><strong><?= e(t('projetos.origem_branco', 'Projeto em branco')) ?></strong><small><?= e(t('projetos.origem_branco_desc', 'Começar do zero ou de um .zip')) ?></small></span>
                        </label>
                        <label class="projeto-cartao-opcao">
                            <input type="radio" name="origem" value="git">
                            <span class="projeto-cartao-icone" aria-hidden="true"><?= d2icone('duplicar') ?></span>
                            <span class="projeto-cartao-texto"><strong><?= e(t('projetos.origem_clonar', 'Clonar do GitHub')) ?></strong><small><?= e(t('projetos.origem_clonar_desc', 'De um repositório existente')) ?></small></span>
                        </label>
                        <label class="projeto-cartao-opcao">
                            <input type="radio" name="origem" value="github_new">
                            <span class="projeto-cartao-icone" aria-hidden="true"><?= d2icone('plataforma') ?></span>
                            <span class="projeto-cartao-texto"><strong><?= e(t('projetos.origem_novo', 'Criar no GitHub')) ?></strong><small><?= e(t('projetos.origem_novo_desc', 'Cria um repositório novo')) ?></small></span>
                        </label>
                    </div>
                </fieldset>

                <div class="projeto-criar-caixa" id="caixa-clonar" hidden>
                    <div class="d2-field">
                        <label class="d2-label" for="criar-repo"><span><?= e(t('projetos.repo_clonar', 'Repositório para clonar')) ?></span><span class="d2-obrigatorio" aria-hidden="true">*</span></label>
                        <select class="d2-control" id="criar-repo" name="git_repo_full_name" disabled aria-describedby="criar-repo-erro">
                            <option value=""><?= e(t('projetos.repos_carregando', 'Carregando repositórios...')) ?></option>
                        </select>
                        <div class="d2-field-erro" id="criar-repo-erro" aria-live="polite"></div>
                    </div>
                    <label class="d2-switch">
                        <input type="checkbox" role="switch" name="git_auto_sync" value="1" disabled>
                        <span class="d2-switch-trilho" aria-hidden="true"></span>
                        <span class="d2-switch-texto"><?= e(t('projetos.auto_sync_clonar', 'Aplicar tudo automaticamente (sincroniza a cada entrega)')) ?></span>
                    </label>
                    <p class="texto-mudo"><?= e(t('projetos.clonar_dica', 'A IA vai clonar, analisar e preparar o ambiente (dependências, configuração e banco), deixando pronto para rodar.')) ?></p>
                </div>

                <div class="projeto-criar-caixa" id="caixa-novo-repo" hidden>
                    <div class="d2-field">
                        <label class="d2-label" for="criar-repo-nome"><span><?= e(t('projetos.repo_nome', 'Nome do repositório')) ?></span></label>
                        <input class="d2-control d2-mono" type="text" id="criar-repo-nome" name="gh_new_name" placeholder="meu-projeto" maxlength="100" autocomplete="off" disabled>
                        <div class="d2-field-hint"><?= e(t('projetos.repo_nome_dica', 'Acompanha o nome do projeto até você editar.')) ?></div>
                    </div>
                    <fieldset class="projeto-escolha">
                        <legend class="d2-label"><?= e(t('projetos.visibilidade', 'Visibilidade')) ?></legend>
                        <div class="projeto-cartoes is-dois">
                            <label class="projeto-cartao-opcao">
                                <input type="radio" name="gh_new_private" value="1" checked disabled>
                                <span class="projeto-cartao-icone" aria-hidden="true"><?= d2icone('cadeado') ?></span>
                                <span class="projeto-cartao-texto"><strong><?= e(t('projetos.privado', 'Privado')) ?></strong><small><?= e(t('projetos.privado_desc', 'Só você e convidados')) ?></small></span>
                            </label>
                            <label class="projeto-cartao-opcao">
                                <input type="radio" name="gh_new_private" value="0" disabled>
                                <span class="projeto-cartao-icone" aria-hidden="true"><?= d2icone('rede') ?></span>
                                <span class="projeto-cartao-texto"><strong><?= e(t('projetos.publico', 'Público')) ?></strong><small><?= e(t('projetos.publico_desc', 'Visível para todos')) ?></small></span>
                            </label>
                        </div>
                    </fieldset>
                    <label class="d2-switch">
                        <input type="checkbox" role="switch" name="gh_new_auto_sync" value="1" disabled>
                        <span class="d2-switch-trilho" aria-hidden="true"></span>
                        <span class="d2-switch-texto"><?= e(t('projetos.auto_sync_novo', 'Sincronizar automaticamente (envia a cada entrega)')) ?></span>
                    </label>
                    <p class="texto-mudo"><?= e(t('projetos.novo_repo_dica', 'Cria o repositório na sua conta do GitHub e já vincula ao projeto.')) ?></p>
                </div>
            <?php else: ?>
                <div class="d2-faixa" role="note">
                    <?= d2icone('info') ?>
                    <div class="d2-faixa-texto">
                        <span class="d2-faixa-titulo"><?= e(t('projetos.github_desconectado', 'Quer clonar ou criar um repositório?')) ?></span>
                        <?= e(t('projetos.github_desconectado_texto', 'Conecte sua conta do GitHub para começar a partir de um repositório. Sem isso, o projeto começa em branco ou de um .zip.')) ?>
                    </div>
                    <div class="d2-faixa-acoes"><a class="d2-btn d2-btn-sm d2-btn-secundario" href="/settings/github"><?= e(t('projetos.conectar_github', 'Conectar GitHub')) ?></a></div>
                </div>
            <?php endif; ?>

            <div class="projeto-criar-caixa" id="caixa-arquivo">
                <div id="campo-arquivo"></div>
                <div class="d2-field" id="caixa-instrucoes" hidden>
                    <label class="d2-label" for="criar-instrucoes"><span><?= e(t('projetos.instrucoes', 'Instruções para a IA')) ?></span><span class="d2-opcional"><?= e(t('d2.opcional', 'opcional')) ?></span></label>
                    <textarea class="d2-control" id="criar-instrucoes" name="instructions" rows="3" maxlength="4000" placeholder="<?= e(t('projetos.instrucoes_exemplo', 'Ex.: o banco fica em config/db.php; prepare para rodar com PHP 8.2')) ?>"></textarea>
                </div>
            </div>
        </div>
    </section>

    <div class="form-acoes">
        <a class="d2-btn d2-btn-secundario" href="/projects"><?= e(t('comum.cancelar', 'Cancelar')) ?></a>
        <button type="submit" class="d2-btn d2-btn-primario" id="criar-enviar"><?= d2icone('check') ?><span><?= e(t('projetos.criar', 'Criar projeto')) ?></span></button>
    </div>
</form>

<script type="application/json" id="dados-pagina"><?= json_encode(['github' => $github], JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
