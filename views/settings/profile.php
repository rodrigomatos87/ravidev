<?php
/**
 * Minha conta, perfil: foto, dados, modelo de IA, conta do Claude, senha, 2FA, aparelhos e exclusão.
 * Layout do painel (d2). Dados: SettingsController@profile. Comportamento: /assets/js/paginas/conta.js.
 */
$this->layout('layouts/painel', [
    'title'       => t('conta.titulo', 'Minha conta'),
    'currentPage' => 'account',
    'area'        => 'conta',
    'subtitulo'   => t('conta.perfil_sub', 'Seus dados, sua segurança e as suas preferências de IA.'),
    'scripts'     => ['/assets/js/paginas/conta.js'],
    'estilos'     => ['/assets/css/paginas/conta.css'],
]);
$nomeUsuario = (string) ($user['name'] ?? '');
$inicial = mb_strtoupper(mb_substr($nomeUsuario !== '' ? $nomeUsuario : 'U', 0, 1));
$rotuloAgentes = ($globalModelAgents ?? '') !== ''
    ? \Ravi\Services\ModelService::label($globalModelAgents)
    : t('conta.herda_autonomo', 'herda o Dev Autônomo');
$escoposModelo = [
    'ide'               => [t('conta.modelo_ide', 'IDE'), (string) ($userModelIde ?? ''), \Ravi\Services\ModelService::label($globalModelIde ?? '')],
    'autonomous'        => [t('conta.modelo_autonomo', 'Dev Autônomo (Arquiteto)'), (string) ($userModelAutonomous ?? ''), \Ravi\Services\ModelService::label($globalModelAutonomous ?? '')],
    'autonomous_agents' => [t('conta.modelo_agentes', 'Agentes do Dev Autônomo'), (string) ($userModelAgents ?? ''), $rotuloAgentes],
];
?>

<div class="d2-pilha">
    <?php include __DIR__ . '/../partials/account-tabs.php'; ?>

    <div class="d2-mosaico">

        <section class="d2-painel" data-area="conta" aria-labelledby="t-foto">
            <header class="d2-painel-cab">
                <span class="d2-painel-icone" aria-hidden="true"><?= d2icone('imagem') ?></span>
                <h3 class="d2-painel-titulo" id="t-foto"><?= e(t('conta.foto', 'Foto de perfil')) ?></h3>
            </header>
            <div class="d2-painel-corpo">
                <div class="conta-foto">
                    <span class="d2-avatar conta-avatar" id="avatar-previa" data-inicial="<?= e($inicial) ?>">
                        <?php if (!empty($avatarUrl)): ?><img src="<?= e($avatarUrl) ?>" alt="<?= e(t('conta.foto_alt', 'Sua foto')) ?>"><?php else: ?><?= e($inicial) ?><?php endif; ?>
                    </span>
                    <div class="d2-pilha conta-foto-acoes">
                        <p class="texto-mudo"><?= e(t('conta.foto_dica', 'JPG, PNG, WEBP ou GIF, até 4 MB. Aparece no seu avatar e no chat.')) ?></p>
                        <input type="file" id="avatar-arquivo" accept="image/png,image/jpeg,image/webp,image/gif" hidden>
                        <div class="form-acoes conta-acoes-inicio">
                            <button type="button" class="d2-btn d2-btn-sm d2-btn-primario" id="avatar-enviar"><?= d2icone('subir') ?><span><?= e(t('conta.foto_enviar', 'Enviar foto')) ?></span></button>
                            <button type="button" class="d2-btn d2-btn-sm d2-btn-fantasma" id="avatar-remover" <?= empty($avatarUrl) ? 'hidden' : '' ?>><?= d2icone('lixeira') ?><span><?= e(t('conta.foto_remover', 'Remover')) ?></span></button>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="d2-painel" data-area="conta" aria-labelledby="t-perfil">
            <header class="d2-painel-cab">
                <span class="d2-painel-icone" aria-hidden="true"><?= d2icone('conta') ?></span>
                <h3 class="d2-painel-titulo" id="t-perfil"><?= e(t('conta.dados', 'Informações do perfil')) ?></h3>
            </header>
            <div class="d2-painel-corpo">
                <form id="perfil-form" class="d2-pilha" novalidate>
                    <div class="form-grade">
                        <div class="d2-field">
                            <label class="d2-label" for="perfil-nome"><?= e(t('conta.nome', 'Nome')) ?></label>
                            <input type="text" class="d2-control" id="perfil-nome" name="name" value="<?= e($nomeUsuario) ?>" required maxlength="100" autocomplete="name" aria-describedby="perfil-nome-erro">
                            <div class="d2-field-erro" id="perfil-nome-erro" data-erro="name"></div>
                        </div>
                        <div class="d2-field">
                            <label class="d2-label" for="perfil-email"><?= e(t('conta.email', 'E-mail')) ?></label>
                            <input type="email" class="d2-control" id="perfil-email" name="email" value="<?= e($user['email'] ?? '') ?>" required autocomplete="email" aria-describedby="perfil-email-erro">
                            <div class="d2-field-erro" id="perfil-email-erro" data-erro="email"></div>
                        </div>
                    </div>
                    <div class="form-acoes">
                        <button type="submit" class="d2-btn d2-btn-primario"><?= d2icone('check') ?><span><?= e(t('conta.salvar', 'Salvar alterações')) ?></span></button>
                    </div>
                </form>
            </div>
        </section>

        <section class="d2-painel" data-area="conta" aria-labelledby="t-modelo">
            <header class="d2-painel-cab">
                <span class="d2-painel-icone" aria-hidden="true"><?= d2icone('estrela') ?></span>
                <h3 class="d2-painel-titulo" id="t-modelo"><?= e(t('conta.modelo', 'Modelo de IA')) ?></h3>
            </header>
            <div class="d2-painel-corpo d2-pilha">
                <p class="texto-mudo"><?= e(t('conta.modelo_sub', 'Sua IDE, seu Dev Autônomo (o Arquiteto) e os Agentes dele (especialistas das ondas, verificador, QA) podem usar modelos diferentes. Deixe em "Usar padrão do sistema" para seguir o que o administrador definiu, ou escolha o seu. Vale a partir da próxima abertura. Na IDE você ainda pode trocar ao vivo com /model.')) ?></p>
                <div class="form-grade">
                    <?php foreach ($escoposModelo as $escopo => $info): ?>
                        <div class="d2-field">
                            <label class="d2-label" for="modelo-<?= e($escopo) ?>"><?= e($info[0]) ?></label>
                            <select class="d2-control js-modelo-usuario" id="modelo-<?= e($escopo) ?>" data-escopo="<?= e($escopo) ?>">
                                <option value=""><?= e(t('conta.modelo_padrao', 'Usar padrão do sistema')) ?> (<?= e($info[2]) ?>)</option>
                                <?php foreach (($models ?? []) as $id => $rotulo): ?>
                                    <option value="<?= e($id) ?>" <?= $info[1] === $id ? 'selected' : '' ?>><?= e($rotulo) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <?php if (!empty($licenseChoice)): ?>
        <section class="d2-painel" data-area="conta" aria-labelledby="t-licenca">
            <header class="d2-painel-cab">
                <span class="d2-painel-icone" aria-hidden="true"><?= d2icone('chave') ?></span>
                <h3 class="d2-painel-titulo" id="t-licenca"><?= e(t('conta.licenca', 'Conta do Claude')) ?></h3>
            </header>
            <div class="d2-painel-corpo d2-pilha">
                <p class="texto-mudo"><?= e(t('conta.licenca_sub', 'O administrador liberou você para escolher qual conta do Claude usar. A escolha vale para a sua IDE (inclusive as já abertas, aplicada em até 60 segundos) e para o seu Dev Autônomo. As barras mostram quanto do limite de cada conta já foi usado; prefira uma com folga.')) ?></p>
                <?php if (empty($licenseOptions)): ?>
                    <div class="d2-vazio">
                        <span class="d2-vazio-icone" aria-hidden="true"><?= d2icone('chave') ?></span>
                        <p class="d2-vazio-titulo"><?= e(t('conta.licenca_vazio', 'Nenhuma conta disponível no momento')) ?></p>
                        <p class="d2-vazio-texto"><?= e(t('conta.licenca_vazio_texto', 'Fale com o administrador para liberar uma conta.')) ?></p>
                        <a class="d2-btn d2-btn-sm d2-btn-secundario" href="/settings/profile"><?= d2icone('atualizar') ?><span><?= e(t('conta.recarregar', 'Verificar de novo')) ?></span></a>
                    </div>
                <?php else: ?>
                    <div class="conta-licencas" id="licencas" role="radiogroup" aria-labelledby="t-licenca">
                        <button type="button" class="conta-licenca js-licenca" role="radio" aria-checked="<?= empty($userLicenseId) ? 'true' : 'false' ?>" data-id="0">
                            <span class="conta-licenca-nome"><?= d2icone('check', 'conta-licenca-marca') ?><span><?= e(t('conta.licenca_padrao', 'Padrão do sistema')) ?></span></span>
                            <span class="conta-licenca-sub"><?= $globalLicense ? e(t('conta.licenca_hoje', 'Hoje') . ': ' . $globalLicense['name']) : e(t('conta.licenca_definido', 'Definido pelo administrador')) ?></span>
                            <?php if ($globalLicense): ?>
                                <span class="conta-licenca-uso js-uso" data-id="<?= (int) $globalLicense['id'] ?>"><span class="texto-mudo"><?= e(t('conta.uso_carregando', 'Carregando uso')) ?></span></span>
                            <?php endif; ?>
                        </button>
                        <?php foreach ($licenseOptions as $opcao): ?>
                            <button type="button" class="conta-licenca js-licenca" role="radio" aria-checked="<?= (int) ($userLicenseId ?? 0) === (int) $opcao['id'] ? 'true' : 'false' ?>" data-id="<?= (int) $opcao['id'] ?>">
                                <span class="conta-licenca-nome"><?= d2icone('check', 'conta-licenca-marca') ?><span><?= e($opcao['name']) ?></span></span>
                                <span class="conta-licenca-sub"><?= e(t('conta.licenca_conta', 'Conta do Claude')) ?></span>
                                <span class="conta-licenca-uso js-uso" data-id="<?= (int) $opcao['id'] ?>"><span class="texto-mudo"><?= e(t('conta.uso_carregando', 'Carregando uso')) ?></span></span>
                            </button>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </section>
        <?php endif; ?>

        <section class="d2-painel" data-area="conta" aria-labelledby="t-senha">
            <header class="d2-painel-cab">
                <span class="d2-painel-icone" aria-hidden="true"><?= d2icone('cadeado') ?></span>
                <h3 class="d2-painel-titulo" id="t-senha"><?= e(t('conta.senha', 'Alterar senha')) ?></h3>
            </header>
            <div class="d2-painel-corpo">
                <form id="senha-form" class="d2-pilha" novalidate>
                    <div class="d2-field">
                        <label class="d2-label" for="senha-atual"><?= e(t('conta.senha_atual', 'Senha atual')) ?></label>
                        <input type="password" class="d2-control" id="senha-atual" name="current_password" required autocomplete="current-password" aria-describedby="senha-atual-erro">
                        <div class="d2-field-erro" id="senha-atual-erro" data-erro="current_password"></div>
                    </div>
                    <div class="form-grade">
                        <div class="d2-field">
                            <label class="d2-label" for="senha-nova"><?= e(t('conta.senha_nova', 'Nova senha')) ?></label>
                            <input type="password" class="d2-control" id="senha-nova" name="password" required minlength="8" autocomplete="new-password" aria-describedby="senha-nova-dica senha-nova-erro">
                            <span class="d2-field-hint" id="senha-nova-dica"><?= e(t('conta.senha_minimo', 'Mínimo de 8 caracteres.')) ?></span>
                            <div class="d2-field-erro" id="senha-nova-erro" data-erro="password"></div>
                        </div>
                        <div class="d2-field">
                            <label class="d2-label" for="senha-confirma"><?= e(t('conta.senha_confirma', 'Confirmar nova senha')) ?></label>
                            <input type="password" class="d2-control" id="senha-confirma" name="password_confirmation" required autocomplete="new-password" aria-describedby="senha-confirma-erro">
                            <div class="d2-field-erro" id="senha-confirma-erro" data-erro="password_confirmation"></div>
                        </div>
                    </div>
                    <div class="form-acoes">
                        <button type="submit" class="d2-btn d2-btn-primario"><?= d2icone('cadeado') ?><span><?= e(t('conta.senha_alterar', 'Alterar senha')) ?></span></button>
                    </div>
                </form>
            </div>
        </section>

        <section class="d2-painel" data-area="conta" aria-labelledby="t-2fa">
            <header class="d2-painel-cab">
                <span class="d2-painel-icone" aria-hidden="true"><?= d2icone('celular') ?></span>
                <h3 class="d2-painel-titulo" id="t-2fa"><?= e(t('conta.2fa', 'Verificação em duas etapas')) ?></h3>
                <div class="d2-painel-acoes">
                    <span class="d2-selo d2-selo-<?= !empty($twoFactorEnabled) ? 'ok' : 'neutro' ?>"><span class="d2-ponto" aria-hidden="true"></span><span class="d2-selo-texto"><?= e(!empty($twoFactorEnabled) ? t('conta.2fa_ativo', 'Ativada') : t('conta.2fa_inativo', 'Desativada')) ?></span></span>
                </div>
            </header>
            <div class="d2-painel-corpo d2-pilha">
                <p class="texto-mudo"><?= e(t('conta.2fa_sub', 'Um código extra do app autenticador (Google Authenticator, Authy e outros) ao entrar, exceto em navegadores marcados como confiáveis.')) ?></p>
                <?php if (empty($twoFactorEnabled)): ?>
                    <div id="tfa-config" class="d2-pilha" hidden>
                        <p class="texto-mudo"><?= e(t('conta.2fa_escaneie', 'Escaneie o QR code com seu app autenticador e digite o código gerado para confirmar.')) ?></p>
                        <div class="conta-qr" id="tfa-qr" aria-label="<?= e(t('conta.2fa_qr', 'QR code do autenticador')) ?>" role="img"></div>
                        <details class="conta-detalhes">
                            <summary><?= e(t('conta.2fa_manual', 'Não consegue escanear? Digite a chave manualmente')) ?></summary>
                            <div class="conta-chave">
                                <code class="codigo" id="tfa-segredo"></code>
                                <button type="button" class="d2-btn d2-btn-sm d2-btn-fantasma" id="tfa-copiar"><?= d2icone('copiar') ?><span><?= e(t('conta.copiar', 'Copiar')) ?></span></button>
                            </div>
                        </details>
                        <form id="tfa-confirmar-form" class="d2-pilha" novalidate>
                            <div class="d2-field conta-campo-curto">
                                <label class="d2-label" for="tfa-codigo"><?= e(t('conta.2fa_codigo', 'Código de 6 dígitos')) ?></label>
                                <input type="text" class="d2-control d2-mono" id="tfa-codigo" inputmode="numeric" maxlength="6" autocomplete="one-time-code" placeholder="000000">
                            </div>
                            <div class="form-acoes conta-acoes-inicio">
                                <button type="submit" class="d2-btn d2-btn-sm d2-btn-primario" id="tfa-confirmar"><?= d2icone('check') ?><span><?= e(t('conta.2fa_ativar', 'Ativar verificação')) ?></span></button>
                            </div>
                        </form>
                    </div>
                    <div class="form-acoes conta-acoes-inicio">
                        <button type="button" class="d2-btn d2-btn-sm d2-btn-primario" id="tfa-iniciar"><?= d2icone('qr') ?><span><?= e(t('conta.2fa_ativar', 'Ativar verificação')) ?></span></button>
                    </div>
                <?php else: ?>
                    <form id="tfa-desativar-form" class="d2-pilha" novalidate>
                        <div class="d2-field conta-campo-curto">
                            <label class="d2-label" for="tfa-senha"><?= e(t('conta.2fa_senha', 'Senha (para desativar)')) ?></label>
                            <input type="password" class="d2-control" id="tfa-senha" autocomplete="current-password">
                        </div>
                        <div class="form-acoes conta-acoes-inicio">
                            <button type="submit" class="d2-btn d2-btn-sm d2-btn-danger" id="tfa-desativar"><?= d2icone('fechar') ?><span><?= e(t('conta.2fa_desativar', 'Desativar verificação')) ?></span></button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </section>

        <section class="d2-painel" data-area="conta" aria-labelledby="t-aparelhos">
            <header class="d2-painel-cab">
                <span class="d2-painel-icone" aria-hidden="true"><?= d2icone('dispositivo') ?></span>
                <h3 class="d2-painel-titulo" id="t-aparelhos"><?= e(t('conta.aparelhos', 'Aparelhos conectados')) ?></h3>
            </header>
            <div class="d2-painel-corpo d2-pilha">
                <p class="texto-mudo"><?= e(t('conta.aparelhos_sub', 'Aparelhos onde você escolheu ficar conectado (telefone, computador). Cada um fica logado por muito tempo, sem precisar entrar de novo. Revogar um faz aquele aparelho pedir login e senha no próximo acesso.')) ?></p>
                <div id="aparelhos" aria-live="polite"></div>
            </div>
        </section>

        <section class="d2-painel is-largo" data-area="conta" aria-labelledby="t-perigo">
            <header class="d2-painel-cab">
                <span class="d2-painel-icone" aria-hidden="true"><?= d2icone('alerta') ?></span>
                <h3 class="d2-painel-titulo" id="t-perigo"><?= e(t('conta.perigo', 'Zona de perigo')) ?></h3>
            </header>
            <div class="d2-painel-corpo">
                <div class="d2-faixa d2-faixa-erro">
                    <?= d2icone('alerta') ?>
                    <div class="d2-faixa-texto"><?= e(t('conta.perigo_texto', 'Ao excluir sua conta, todos os seus projetos, conversas e arquivos gerados serão removidos para sempre.')) ?></div>
                    <div class="d2-faixa-acoes">
                        <button type="button" class="d2-btn d2-btn-sm d2-btn-danger" id="conta-excluir"><?= d2icone('lixeira') ?><span><?= e(t('conta.excluir', 'Excluir minha conta')) ?></span></button>
                    </div>
                </div>
            </div>
        </section>

    </div>
</div>
