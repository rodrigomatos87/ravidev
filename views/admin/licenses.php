<?php
/** Admin: licenças do Claude Code (assinaturas Max), atribuição, roteamento e uso do plano. */
$this->layout('layouts/painel', [
    'title'       => $title ?? t('licencas.titulo', 'Licenças'),
    'pageTitle'   => t('licencas.titulo_claude', 'Licenças do Claude Code'),
    'currentPage' => 'admin-licenses',
    'area'        => 'licencas',
    'subtitulo'   => t('licencas.sub_claude', 'Assinaturas Max e qual licença cada consumidor usa: API, IDE e Dev Autônomo, com escolha por usuário.'),
    'acoes'       => '<button type="button" class="d2-btn d2-btn-fantasma" data-acao="ajuda-token">' . d2icone('info') . '<span>' . e(t('licencas.como_obter', 'Como obter o token')) . '</span></button>'
                   . '<button type="button" class="d2-btn d2-btn-primario" data-acao="nova-licenca">' . d2icone('mais') . '<span>' . e(t('licencas.nova', 'Nova licença')) . '</span></button>',
    'estilos'     => ['/assets/css/paginas/licencas.css'],
    'scripts'     => ['/assets/js/paginas/admin-licencas.js'],
]);

$licenses = $licenses ?? [];
$opcoesLicencas = $opcoesLicencas ?? [];
$opcoesChatgpt = $opcoesChatgpt ?? [];
$usoPorLicenca = $usoPorLicenca ?? [];
$cooldowns = $cooldowns ?? [];
$global = $global ?? [];

$diasSemana = [
    t('licencas.dia_0', 'Domingo'), t('licencas.dia_1', 'Segunda-feira'), t('licencas.dia_2', 'Terça-feira'),
    t('licencas.dia_3', 'Quarta-feira'), t('licencas.dia_4', 'Quinta-feira'), t('licencas.dia_5', 'Sexta-feira'), t('licencas.dia_6', 'Sábado'),
];
$diasCurto = [
    t('licencas.dia_curto_0', 'Dom'), t('licencas.dia_curto_1', 'Seg'), t('licencas.dia_curto_2', 'Ter'), t('licencas.dia_curto_3', 'Qua'),
    t('licencas.dia_curto_4', 'Qui'), t('licencas.dia_curto_5', 'Sex'), t('licencas.dia_curto_6', 'Sáb'),
];

$totalAtivas = count(array_filter($licenses, static fn($l) => !empty($l['is_active'])));
$totalEmUso = count(array_filter($licenses, static fn($l) => !empty($usoPorLicenca[(int) $l['id']])));

// A principal da API (licença global do escopo api) é sempre tentada primeiro: fica fora da lista de backups.
$apiPrincipal = (int) ($global['api'] ?? 0);
$principalNome = null;
foreach ($licenses as $l) {
    if ((int) $l['id'] === $apiPrincipal) { $principalNome = (string) $l['name']; break; }
}
$gwModelos = array_column($gwCatalog ?? [], 'display_name', 'id');

$dadosPagina = [
    'licencas' => array_map(static fn($l) => [
        'id'         => (int) $l['id'],
        'nome'       => (string) $l['name'],
        'nota'       => (string) ($l['note'] ?? ''),
        'ativa'      => !empty($l['is_active']),
        'reset_dia'  => $l['reset_dow'] !== null ? (int) $l['reset_dow'] : null,
        'reset_hora' => $l['reset_hour'] !== null ? (int) $l['reset_hour'] : null,
        'teto'       => ($l['limit_pct'] ?? null) !== null ? (int) $l['limit_pct'] : null,
    ], $licenses),
    'textos' => [
        'nova_titulo'      => t('licencas.nova', 'Nova licença'),
        'editar_titulo'    => t('licencas.editar_titulo', 'Editar licença'),
        'cadastrar'        => t('licencas.cadastrar', 'Cadastrar licença'),
        'salvar'           => t('licencas.salvar', 'Salvar'),
        'cancelar'         => t('licencas.cancelar', 'Cancelar'),
        'fechar'           => t('licencas.fechar', 'Fechar'),
        'ajuda_titulo'     => t('licencas.ajuda_titulo', 'Como obter o token de uma licença'),
        'copiado'          => t('licencas.copiado', 'Copiado.'),
        'copiar_falhou'    => t('licencas.copiar_falhou', 'Não foi possível copiar. Selecione o texto e copie manualmente.'),
        'excluir_titulo'   => t('licencas.excluir_titulo', 'Excluir licença'),
        'excluir_texto'    => t('licencas.excluir_texto', 'Excluir ":nome"? Quem a usava fica SEM licença (deixa de funcionar até você atribuir outra).'),
        'excluir_ok'       => t('licencas.excluir', 'Excluir'),
        'teste_ok'         => t('licencas.teste_ok', 'Licença válida e autenticada.'),
        'teste_falha'      => t('licencas.teste_falha', 'Falha ao autenticar a licença.'),
        'teste_detalhe'    => t('licencas.teste_detalhe', 'Detalhe do teste'),
        'teste_erro'       => t('licencas.teste_erro', 'Não foi possível testar agora.'),
        'salvo'            => t('licencas.salvo', 'Atribuição salva. Vale para o que for aberto a partir de agora.'),
        'salvo_usuario'    => t('licencas.salvo_usuario', 'Atribuição salva.'),
        'salvo_gpt_usuario'=> t('licencas.salvo_gpt_usuario', 'Atribuição salva. A IDE do usuário recebe a licença em até 1 minuto.'),
        'erro_salvar'      => t('licencas.erro_salvar', 'Não foi possível salvar a atribuição.'),
        'uso_sessao'       => t('licencas.uso_sessao', 'Sessão 5h'),
        'uso_semana'       => t('licencas.uso_semana', 'Semana'),
        'uso_fable'        => t('licencas.uso_fable', 'Fable'),
        'uso_sem_dados'    => t('licencas.uso_sem_dados', 'sem dados'),
        'uso_indisponivel' => t('licencas.uso_indisponivel', 'indisponível'),
        'uso_falha'        => t('licencas.uso_falha', 'falha ao consultar'),
        'uso_sem_fable'    => t('licencas.uso_sem_fable', 'sem uso observado'),
        'uso_sem_fable_dica' => t('licencas.uso_sem_fable_dica', 'A Anthropic só informa a janela do Fable em respostas de pedidos Fable reais do Claude Code; esta licença ainda não passou nenhum pela IDE.'),
        'uso_fable_renovou'=> t('licencas.uso_fable_renovou', 'a janela renovou desde a última leitura; sem uso novo observado'),
        'uso_lido_em'      => t('licencas.uso_lido_em', 'lido em :quando'),
        'uso_dica'         => t('licencas.uso_dica', ':rotulo: :pct% do limite usado'),
        'uso_dica_renova'  => t('licencas.uso_dica_renova', 'renova :quando'),
        'uso_rejeitado'    => t('licencas.uso_rejeitado', '(limite atingido)'),
        'uso_no_teto'      => t('licencas.uso_no_teto', 'NO TETO de :n% (IDE e Dev Autônomo pausados até renovar; folga reservada à API)'),
        'uso_teto'         => t('licencas.uso_teto', 'teto para IDE e Dev Autônomo: :n%'),
        'uso_renovando'    => t('licencas.uso_renovando', 'renovando'),
        'uso_renova'       => t('licencas.uso_renova', 'renova em :tempo'),
        'uso_dados_de'     => t('licencas.uso_dados_de', 'dados de :hora'),
        'uso_atualizar'    => t('licencas.uso_atualizar', 'Atualizar uso'),
        'uso_consultando'  => t('licencas.uso_consultando', 'Consultando'),
        'remover'          => t('licencas.remover', 'Remover'),
    ],
];
?>

<?php $abaAtiva = 'claude'; $totalChatgpt = count($chatgptLicenses ?? []); include __DIR__ . '/../partials/painel/abas-licencas.php'; ?>

<div class="d2-pilha licencas">
    <section class="indicadores" aria-label="<?= e(t('licencas.numeros', 'Resumo das licenças')) ?>">
        <div class="d2-indicador" data-area="licencas">
            <span class="d2-indicador-rotulo"><?= d2icone('chave') ?><span><?= e(t('licencas.ind_ativas', 'Licenças ativas')) ?></span></span>
            <span class="d2-indicador-valor d2-num"><?= (int) $totalAtivas ?> <span class="licencas-ind-de"><?= e(t('licencas.ind_de', 'de :n', ['n' => count($licenses)])) ?></span></span>
        </div>
        <div class="d2-indicador" data-area="licencas" data-tooltip="<?= e(t('licencas.ind_em_uso_dica', 'Licenças escolhidas por algum consumidor, usuário ou roteamento')) ?>">
            <span class="d2-indicador-rotulo"><?= d2icone('usuarios') ?><span><?= e(t('licencas.ind_em_uso', 'Em uso')) ?></span></span>
            <span class="d2-indicador-valor d2-num"><?= (int) $totalEmUso ?></span>
        </div>
        <div class="d2-indicador" data-area="licencas" data-tooltip="<?= e(t('licencas.ind_sessao_dica', 'Maior uso da sessão de 5 horas entre as licenças ativas')) ?>">
            <span class="d2-indicador-rotulo"><?= d2icone('relogio') ?><span><?= e(t('licencas.ind_sessao', 'Sessão 5h (maior)')) ?></span></span>
            <span class="d2-indicador-valor d2-num" data-indicador="sessao">-</span>
        </div>
        <div class="d2-indicador" data-area="licencas" data-tooltip="<?= e(t('licencas.ind_semana_dica', 'Maior uso do limite semanal entre as licenças ativas')) ?>">
            <span class="d2-indicador-rotulo"><?= d2icone('calendario') ?><span><?= e(t('licencas.ind_semana', 'Semana (maior)')) ?></span></span>
            <span class="d2-indicador-valor d2-num" data-indicador="semana">-</span>
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
                    <p class="d2-vazio-titulo"><?= e(t('licencas.vazio_titulo', 'Nenhuma licença cadastrada')) ?></p>
                    <p class="d2-vazio-texto"><?= e(!empty($hasEnv)
                        ? t('licencas.vazio_env', 'Nenhuma licença ainda. A licença atual do sistema será importada automaticamente.')
                        : t('licencas.vazio_texto', 'Cadastre a primeira: sem licença, nada autentica.')) ?></p>
                    <button type="button" class="d2-btn d2-btn-primario" data-acao="nova-licenca"><?= d2icone('mais') ?><span><?= e(t('licencas.cadastrar', 'Cadastrar licença')) ?></span></button>
                </div>
            </div>
        </section>
    <?php else: ?>
        <div class="licencas-uso-cab">
            <h2 class="licencas-secao-titulo"><?= e(t('licencas.cadastradas', 'Licenças cadastradas')) ?></h2>
            <span class="texto-mudo" id="uso-quando" hidden></span>
            <button type="button" class="d2-btn d2-btn-sm d2-btn-secundario" id="uso-atualizar" data-tooltip="<?= e(t('licencas.uso_atualizar_dica', 'Consulta cada licença na Anthropic agora (as barras mostram o dado mais recente guardado)')) ?>"><?= d2icone('atualizar') ?><span><?= e(t('licencas.uso_atualizar', 'Atualizar uso')) ?></span></button>
        </div>

        <div class="d2-mosaico licencas-lista">
            <?php foreach ($licenses as $l): ?>
                <?php
                $lid = (int) $l['id'];
                $ativa = !empty($l['is_active']);
                $teto = ($l['limit_pct'] ?? null) !== null ? (int) $l['limit_pct'] : null;
                $usos = $usoPorLicenca[$lid] ?? [];
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
                            <?php if (isset($cooldowns[$lid])): ?>
                                <span class="d2-selo d2-selo-aviso" data-tooltip="<?= e(t('licencas.esgotada_dica', 'Atingiu o limite de uso; o balance do Dev Autônomo desvia os agentes até este horário')) ?>"><?= d2icone('relogio') ?><span class="d2-selo-texto"><?= e(t('licencas.esgotada_ate', 'Esgotada até :hora', ['hora' => date('H:i', (int) $cooldowns[$lid])])) ?></span></span>
                            <?php endif; ?>
                            <?php if ($teto !== null): ?>
                                <span class="d2-selo d2-selo-info" data-tooltip="<?= e(t('licencas.teto_dica', 'Teto de uso para a IDE e o Dev Autônomo: ao chegar em :n% das janelas do plano eles pausam até renovar, reservando a folga para a API', ['n' => $teto])) ?>"><span class="d2-selo-texto"><?= e(t('licencas.teto_selo', 'Teto IDE/Dev :n%', ['n' => $teto])) ?></span></span>
                            <?php endif; ?>
                        </div>

                        <dl class="kv licenca-kv">
                            <dt><?= e(t('licencas.token', 'Token')) ?></dt>
                            <dd><code class="codigo"><?= e($l['hint']) ?></code></dd>
                            <?php if (!empty($l['note'])): ?>
                                <dt><?= e(t('licencas.nota', 'Nota')) ?></dt>
                                <dd><?= e($l['note']) ?></dd>
                            <?php endif; ?>
                            <dt><?= e(t('licencas.renovacao', 'Renovação semanal')) ?></dt>
                            <dd><?= ($l['reset_dow'] !== null && $l['reset_hour'] !== null)
                                ? e(($diasCurto[(int) $l['reset_dow']] ?? '') . ' ' . sprintf('%02d:00', (int) $l['reset_hour']))
                                : '<span class="texto-mudo">' . e(t('licencas.nao_informada', 'não informada')) . '</span>' ?></dd>
                            <dt><?= e(t('licencas.ultimo_uso', 'Último uso')) ?></dt>
                            <dd><?= !empty($l['last_used_at'])
                                ? e(date('d/m/Y H:i', strtotime((string) $l['last_used_at'])))
                                : '<span class="texto-mudo">' . e(t('licencas.nunca', 'nunca')) . '</span>' ?></dd>
                            <dt><?= e(t('licencas.em_uso_por', 'Em uso por')) ?></dt>
                            <dd><?= $usos ? e(implode(', ', $usos)) : '<span class="texto-mudo">' . e(t('licencas.nenhum_consumidor', 'nenhum consumidor')) . '</span>' ?></dd>
                        </dl>

                        <?php if ($ativa): ?>
                            <div class="licenca-uso" data-uso-licenca="<?= $lid ?>" data-teto="<?= (int) ($teto ?? 0) ?>" aria-live="polite"></div>
                        <?php endif; ?>
                    </div>
                    <footer class="d2-painel-rodape licenca-rodape">
                        <button type="button" class="d2-btn d2-btn-sm d2-btn-fantasma" data-acao="editar" data-id="<?= $lid ?>"><?= d2icone('editar') ?><span><?= e(t('licencas.editar', 'Editar')) ?></span></button>
                        <form method="POST" action="/admin/licenses/<?= $lid ?>/toggle" class="licenca-form-inline" data-carregando>
                            <?= csrf_field() ?>
                            <button type="submit" class="d2-btn d2-btn-sm d2-btn-secundario"><?= d2icone($ativa ? 'offline' : 'online') ?><span><?= e($ativa ? t('licencas.desativar', 'Desativar') : t('licencas.ativar', 'Ativar')) ?></span></button>
                        </form>
                        <form method="POST" action="/admin/licenses/<?= $lid ?>/delete" class="licenca-form-inline" data-excluir="<?= e($l['name']) ?>">
                            <?= csrf_field() ?>
                            <button type="submit" class="d2-iconbtn d2-iconbtn-perigo d2-iconbtn-sm" aria-label="<?= e(t('licencas.excluir_aria', 'Excluir :nome', ['nome' => $l['name']])) ?>" data-tooltip="<?= e(t('licencas.excluir', 'Excluir')) ?>"><?= d2icone('lixeira') ?></button>
                        </form>
                    </footer>
                </section>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="d2-mosaico">
        <!-- Atribuição global por escopo -->
        <section class="d2-painel" data-area="licencas" aria-labelledby="t-consumidor">
            <header class="d2-painel-cab">
                <span class="d2-painel-icone" aria-hidden="true"><?= d2icone('plataforma') ?></span>
                <h2 class="d2-painel-titulo" id="t-consumidor"><?= e(t('licencas.por_consumidor', 'Licença por consumidor (padrão global)')) ?></h2>
            </header>
            <div class="d2-painel-corpo">
                <p class="texto-mudo licencas-explica"><?= e(t('licencas.por_consumidor_texto', 'Qual licença cada consumidor usa por padrão. Não existe reserva automática: consumidor sem licença definida não funciona (por segurança, o sistema nunca usa uma licença que você não escolheu).')) ?></p>
                <div class="form-grade">
                    <?php foreach (($scopes ?? []) as $sc => $rotuloEscopo): ?>
                        <div class="d2-field">
                            <label class="d2-label" for="lic-global-<?= e($sc) ?>"><?= e($rotuloEscopo) ?></label>
                            <select class="d2-control" id="lic-global-<?= e($sc) ?>" data-atribuir-global="<?= e($sc) ?>">
                                <?php $opcoes = $opcoesLicencas; $selecionada = $global[$sc] ?? null; $rotuloZero = t('licencas.nenhuma_desativado', 'nenhuma (desativado)'); include __DIR__ . '/../partials/painel/opcoes-licenca.php'; ?>
                            </select>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <!-- Roteamento da API -->
        <section class="d2-painel" data-area="licencas" aria-labelledby="t-rot-api">
            <header class="d2-painel-cab">
                <span class="d2-painel-icone" aria-hidden="true"><?= d2icone('seta-direita') ?></span>
                <h2 class="d2-painel-titulo" id="t-rot-api"><?= e(t('licencas.rot_api', 'Roteamento da API')) ?></h2>
            </header>
            <form method="POST" action="/admin/licenses/api-routing" id="rot-api" data-carregando>
                <?= csrf_field() ?>
                <div class="d2-painel-corpo">
                    <p class="texto-mudo licencas-explica"><?= e(t('licencas.rot_api_texto', 'A principal de cada requisição é a licença própria da chave de API ou, se ela não tiver, a licença da API definida por consumidor. A API tenta apenas licenças escolhidas aqui: se nenhuma responder, a requisição falha com erro.')) ?></p>
                    <fieldset class="licencas-modos">
                        <legend class="d2-oculto-leitor"><?= e(t('licencas.modo', 'Modo')) ?></legend>
                        <?php foreach ([
                            'off'      => [t('licencas.modo_off', 'Desligado'), t('licencas.rot_api_off', 'Cada requisição usa só a principal (licença própria da chave, ou a licença da API).')],
                            'failover' => [t('licencas.modo_failover', 'Failover'), t('licencas.rot_api_failover', 'Principal e backups numa lista ordenada. Tenta na ordem e para na primeira que responder.')],
                            'balance'  => [t('licencas.modo_balance', 'Balance'), t('licencas.rot_api_balance', 'Duas licenças escolhidas dividem a carga (sorteio a cada requisição); se a sorteada falhar, usa a parceira. Chave com licença própria fica fora do balance (usa só a dela).')],
                        ] as $modo => [$rotuloModo, $textoModo]): ?>
                            <label class="licencas-modo">
                                <input type="radio" name="mode" value="<?= e($modo) ?>" <?= ($apiMode ?? 'off') === $modo ? 'checked' : '' ?>>
                                <span class="licencas-modo-texto"><strong><?= e($rotuloModo) ?></strong><span><?= e($textoModo) ?></span></span>
                            </label>
                        <?php endforeach; ?>
                    </fieldset>

                    <div data-modo-api="failover" <?= ($apiMode ?? 'off') === 'failover' ? '' : 'hidden' ?>>
                        <p class="texto-mudo licencas-explica"><?= e($principalNome !== null
                            ? t('licencas.backups_texto_principal', 'Backups, na ordem de tentativa (valem depois da principal). A principal (":nome") já é tentada primeiro e por isso não aparece na lista.', ['nome' => $principalNome])
                            : t('licencas.backups_texto', 'Backups, na ordem de tentativa (valem depois da principal de cada requisição).')) ?></p>
                        <ol class="licencas-linhas" id="lista-failover">
                            <?php foreach (($apiBackups ?? []) as $bid): ?>
                                <li class="licencas-linha">
                                    <select class="d2-control" name="failover[]" aria-label="<?= e(t('licencas.backup', 'Licença de backup')) ?>">
                                        <?php $opcoes = $opcoesLicencas; $selecionada = (int) $bid; $rotuloZero = t('licencas.escolha', 'escolha'); $excluir = $apiPrincipal; include __DIR__ . '/../partials/painel/opcoes-licenca.php'; ?>
                                    </select>
                                    <button type="button" class="d2-iconbtn d2-iconbtn-fantasma d2-iconbtn-sm" data-linha="subir" aria-label="<?= e(t('licencas.subir', 'Subir')) ?>" data-tooltip="<?= e(t('licencas.subir', 'Subir')) ?>"><?= d2icone('seta-cima') ?></button>
                                    <button type="button" class="d2-iconbtn d2-iconbtn-fantasma d2-iconbtn-sm" data-linha="descer" aria-label="<?= e(t('licencas.descer', 'Descer')) ?>" data-tooltip="<?= e(t('licencas.descer', 'Descer')) ?>"><?= d2icone('seta-baixo') ?></button>
                                    <button type="button" class="d2-iconbtn d2-iconbtn-fantasma d2-iconbtn-sm" data-linha="remover" aria-label="<?= e(t('licencas.remover', 'Remover')) ?>" data-tooltip="<?= e(t('licencas.remover', 'Remover')) ?>"><?= d2icone('fechar') ?></button>
                                </li>
                            <?php endforeach; ?>
                        </ol>
                        <button type="button" class="d2-btn d2-btn-sm d2-btn-secundario" data-acao="add-failover"><?= d2icone('mais') ?><span><?= e(t('licencas.add_backup', 'Adicionar backup')) ?></span></button>
                        <template id="modelo-failover">
                            <li class="licencas-linha">
                                <select class="d2-control" name="failover[]" aria-label="<?= e(t('licencas.backup', 'Licença de backup')) ?>">
                                    <?php $opcoes = $opcoesLicencas; $selecionada = null; $rotuloZero = t('licencas.escolha', 'escolha'); $excluir = $apiPrincipal; include __DIR__ . '/../partials/painel/opcoes-licenca.php'; ?>
                                </select>
                                <button type="button" class="d2-iconbtn d2-iconbtn-fantasma d2-iconbtn-sm" data-linha="subir" aria-label="<?= e(t('licencas.subir', 'Subir')) ?>" data-tooltip="<?= e(t('licencas.subir', 'Subir')) ?>"><?= d2icone('seta-cima') ?></button>
                                <button type="button" class="d2-iconbtn d2-iconbtn-fantasma d2-iconbtn-sm" data-linha="descer" aria-label="<?= e(t('licencas.descer', 'Descer')) ?>" data-tooltip="<?= e(t('licencas.descer', 'Descer')) ?>"><?= d2icone('seta-baixo') ?></button>
                                <button type="button" class="d2-iconbtn d2-iconbtn-fantasma d2-iconbtn-sm" data-linha="remover" aria-label="<?= e(t('licencas.remover', 'Remover')) ?>" data-tooltip="<?= e(t('licencas.remover', 'Remover')) ?>"><?= d2icone('fechar') ?></button>
                            </li>
                        </template>
                    </div>

                    <div class="form-grade" data-modo-api="balance" <?= ($apiMode ?? 'off') === 'balance' ? '' : 'hidden' ?>>
                        <div class="d2-field">
                            <label class="d2-label" for="balance-a"><?= e(t('licencas.licenca_a', 'Licença A')) ?></label>
                            <select class="d2-control" id="balance-a" name="balance_a">
                                <?php $opcoes = $opcoesLicencas; $selecionada = $apiPair[0] ?? null; $rotuloZero = t('licencas.escolha', 'escolha'); include __DIR__ . '/../partials/painel/opcoes-licenca.php'; ?>
                            </select>
                        </div>
                        <div class="d2-field">
                            <label class="d2-label" for="balance-b"><?= e(t('licencas.licenca_b', 'Licença B')) ?></label>
                            <select class="d2-control" id="balance-b" name="balance_b">
                                <?php $opcoes = $opcoesLicencas; $selecionada = $apiPair[1] ?? null; $rotuloZero = t('licencas.escolha', 'escolha'); include __DIR__ . '/../partials/painel/opcoes-licenca.php'; ?>
                            </select>
                        </div>
                    </div>
                </div>
                <footer class="d2-painel-rodape">
                    <button type="submit" class="d2-btn d2-btn-sm d2-btn-primario"><?= e(t('licencas.salvar_roteamento', 'Salvar roteamento')) ?></button>
                </footer>
            </form>
        </section>

        <!-- Roteamento do Dev Autônomo -->
        <section class="d2-painel" data-area="licencas" aria-labelledby="t-rot-auto">
            <header class="d2-painel-cab">
                <span class="d2-painel-icone" aria-hidden="true"><?= d2icone('grade') ?></span>
                <h2 class="d2-painel-titulo" id="t-rot-auto"><?= e(t('licencas.rot_auto', 'Roteamento do Dev Autônomo')) ?></h2>
            </header>
            <form method="POST" action="/admin/licenses/autonomous-routing" id="rot-auto" data-carregando>
                <?= csrf_field() ?>
                <div class="d2-painel-corpo">
                    <p class="texto-mudo licencas-explica"><?= e(t('licencas.rot_auto_texto', 'O Dev Autônomo sobe vários agentes ao mesmo tempo e, numa licença só, as ondas paralelas estouram o limite de sessão da conta. Com o balance, cada agente sai pela licença menos carregada entre as marcadas e fica nela até terminar. Licença que atingir o limite entra em pausa até o horário de renovação (selo "Esgotada") e os próximos agentes usam as demais. Usuário com licença própria de Dev Autônomo fica fora do balance.')) ?></p>
                    <fieldset class="licencas-modos">
                        <legend class="d2-oculto-leitor"><?= e(t('licencas.modo', 'Modo')) ?></legend>
                        <?php foreach ([
                            'off'     => [t('licencas.modo_off', 'Desligado'), t('licencas.rot_auto_off', 'Todos os agentes usam a licença do Dev Autônomo (a do usuário, ou o padrão global).')],
                            'balance' => [t('licencas.modo_balance', 'Balance'), t('licencas.rot_auto_balance', 'Marque 2 ou mais licenças; cada agente novo vai para a menos usada no momento.')],
                        ] as $modo => [$rotuloModo, $textoModo]): ?>
                            <label class="licencas-modo">
                                <input type="radio" name="mode" value="<?= e($modo) ?>" <?= ($autoMode ?? 'off') === $modo ? 'checked' : '' ?>>
                                <span class="licencas-modo-texto"><strong><?= e($rotuloModo) ?></strong><span><?= e($textoModo) ?></span></span>
                            </label>
                        <?php endforeach; ?>
                    </fieldset>
                    <fieldset data-modo-auto="balance" class="licencas-pool" <?= ($autoMode ?? 'off') === 'balance' ? '' : 'hidden' ?>>
                        <legend class="texto-mudo"><?= e(t('licencas.pool_texto', 'Licenças participantes (mínimo 2; inativas não entram no sorteio mesmo marcadas):')) ?></legend>
                        <div class="licencas-pool-itens">
                            <?php foreach ($licenses as $l): ?>
                                <label class="d2-chip licencas-pool-item<?= !empty($l['is_active']) ? '' : ' is-inativa' ?>">
                                    <input type="checkbox" name="pool[]" value="<?= (int) $l['id'] ?>" <?= in_array((int) $l['id'], $autoPool ?? [], true) ? 'checked' : '' ?>>
                                    <span><?= e($opcoesLicencas[(int) $l['id']] ?? $l['name']) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </fieldset>
                </div>
                <footer class="d2-painel-rodape">
                    <button type="submit" class="d2-btn d2-btn-sm d2-btn-primario"><?= e(t('licencas.salvar_roteamento', 'Salvar roteamento')) ?></button>
                </footer>
            </form>
        </section>

        <!-- Backup de modelo do gateway -->
        <section class="d2-painel" data-area="licencas" aria-labelledby="t-backup-modelo">
            <header class="d2-painel-cab">
                <span class="d2-painel-icone" aria-hidden="true"><?= d2icone('atualizar') ?></span>
                <h2 class="d2-painel-titulo" id="t-backup-modelo"><?= e(t('licencas.backup_modelo', 'Backup de modelo (gateway)')) ?></h2>
            </header>
            <form method="POST" action="/admin/licenses/model-backups" data-carregando>
                <?= csrf_field() ?>
                <div class="d2-painel-corpo">
                    <p class="texto-mudo licencas-explica"><?= e(t('licencas.backup_modelo_texto', 'Se a execução do modelo titular falhar (sem crédito, fora do ar, licenças esgotadas), o gateway percorre as reservas na ordem (1ª, depois a 2ª) e registra no histórico o pedido e o servido. Sem reserva configurada, a falha volta como erro claro. As reservas respeitam os modelos permitidos de cada chave.')) ?></p>
                    <?php if (empty($deepseekConfigured)): ?>
                        <div class="d2-faixa d2-faixa-aviso licencas-explica" role="note">
                            <?= d2icone('alerta') ?>
                            <span class="d2-faixa-texto"><?= e(t('licencas.deepseek_falta', 'A chave da DeepSeek ainda não está cadastrada (Gateway de API): uma reserva DeepSeek falharia até cadastrar.')) ?></span>
                        </div>
                    <?php endif; ?>
                    <?php
                    $seletorModelo = static function (string $nomeCampo, ?string $sel, string $vazio, string $rotulo) use ($gwModelos): string {
                        $html = '<select class="d2-control" name="' . e($nomeCampo) . '" aria-label="' . e($rotulo) . '"><option value="">' . e($vazio) . '</option>';
                        foreach ($gwModelos as $mid => $nome) {
                            $html .= '<option value="' . e((string) $mid) . '"' . ((string) $mid === $sel ? ' selected' : '') . '>' . e((string) $nome) . '</option>';
                        }
                        return $html . '</select>';
                    };
                    $linhaModelo = static function (?string $de, ?string $p1, ?string $p2) use ($seletorModelo): string {
                        return '<li class="licencas-linha licencas-linha-modelo">'
                            . '<div class="d2-field"><span class="d2-label">' . e(t('licencas.modelo_titular', 'Modelo')) . '</span>' . $seletorModelo('backup_de[]', $de, t('licencas.escolha', 'escolha'), t('licencas.modelo_titular', 'Modelo')) . '</div>'
                            . '<div class="d2-field"><span class="d2-label">' . e(t('licencas.reserva_1', '1ª reserva')) . '</span>' . $seletorModelo('backup_p1[]', $p1, t('licencas.escolha', 'escolha'), t('licencas.reserva_1', '1ª reserva')) . '</div>'
                            . '<div class="d2-field"><span class="d2-label">' . e(t('licencas.reserva_2', '2ª reserva')) . '</span>' . $seletorModelo('backup_p2[]', $p2, t('licencas.sem_2a', 'sem 2ª'), t('licencas.reserva_2', '2ª reserva')) . '</div>'
                            . '<button type="button" class="d2-iconbtn d2-iconbtn-fantasma d2-iconbtn-sm" data-linha="remover" aria-label="' . e(t('licencas.remover', 'Remover')) . '" data-tooltip="' . e(t('licencas.remover', 'Remover')) . '">' . d2icone('fechar') . '</button>'
                            . '</li>';
                    };
                    ?>
                    <ul class="licencas-linhas" id="lista-modelos">
                        <?php foreach (($modelBackups ?? []) as $de => $fila): $fila = (array) $fila; ?>
                            <?= $linhaModelo((string) $de, isset($fila[0]) ? (string) $fila[0] : null, isset($fila[1]) ? (string) $fila[1] : null) ?>
                        <?php endforeach; ?>
                    </ul>
                    <button type="button" class="d2-btn d2-btn-sm d2-btn-secundario" data-acao="add-modelo"><?= d2icone('mais') ?><span><?= e(t('licencas.add_backup_modelo', 'Adicionar backup de modelo')) ?></span></button>
                    <template id="modelo-backup-modelo"><?= $linhaModelo(null, null, null) ?></template>
                </div>
                <footer class="d2-painel-rodape">
                    <button type="submit" class="d2-btn d2-btn-sm d2-btn-primario"><?= e(t('licencas.salvar_backups', 'Salvar backups de modelo')) ?></button>
                </footer>
            </form>
        </section>
    </div>

    <!-- Atribuição por usuário -->
    <section class="d2-painel" data-area="licencas" aria-labelledby="t-por-usuario">
        <header class="d2-painel-cab">
            <span class="d2-painel-icone" aria-hidden="true"><?= d2icone('usuarios') ?></span>
            <h2 class="d2-painel-titulo" id="t-por-usuario"><?= e(t('licencas.por_usuario', 'Licença por usuário')) ?></h2>
        </header>
        <div class="d2-painel-corpo">
            <p class="texto-mudo licencas-explica"><?= e(t('licencas.por_usuario_texto', 'Sobrescreve o padrão global só para o usuário escolhido. "Herda o padrão" usa a licença global do escopo. IDE e Dev Autônomo são independentes.')) ?><?= !empty($chatgptHasActive) ? ' ' . e(t('licencas.por_usuario_gpt', 'A coluna IDE (ChatGPT) escolhe a licença do ChatGPT que o Codex usa na IDE desse usuário.')) : '' ?></p>
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
                            <th scope="col"><?= e(t('licencas.ide_claude', 'IDE (Claude)')) ?></th>
                            <th scope="col"><?= e(t('licencas.dev_autonomo', 'Dev Autônomo')) ?></th>
                            <?php if (!empty($chatgptHasActive)): ?><th scope="col"><?= e(t('licencas.ide_chatgpt', 'IDE (ChatGPT)')) ?></th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($userRows as $u): ?>
                            <tr class="d2-tabela-linha">
                                <th scope="row" class="d2-tabela-cel licencas-usuario">
                                    <span class="licencas-usuario-nome"><?= e($u['name']) ?></span>
                                    <span class="texto-mudo"><?= e($u['email']) ?></span>
                                </th>
                                <td class="d2-tabela-cel" data-rotulo="<?= e(t('licencas.ide_claude', 'IDE (Claude)')) ?>">
                                    <select class="d2-control" data-atribuir-usuario="<?= (int) $u['id'] ?>" data-escopo="ide" aria-label="<?= e(t('licencas.ide_claude_de', 'IDE (Claude) de :nome', ['nome' => $u['name']])) ?>">
                                        <?php $opcoes = $opcoesLicencas; $selecionada = $u['ide'] ?? null; $rotuloZero = t('licencas.herda', 'Herda o padrão'); include __DIR__ . '/../partials/painel/opcoes-licenca.php'; ?>
                                    </select>
                                </td>
                                <td class="d2-tabela-cel" data-rotulo="<?= e(t('licencas.dev_autonomo', 'Dev Autônomo')) ?>">
                                    <select class="d2-control" data-atribuir-usuario="<?= (int) $u['id'] ?>" data-escopo="autonomous" aria-label="<?= e(t('licencas.dev_autonomo_de', 'Dev Autônomo de :nome', ['nome' => $u['name']])) ?>">
                                        <?php $opcoes = $opcoesLicencas; $selecionada = $u['autonomous'] ?? null; $rotuloZero = t('licencas.herda', 'Herda o padrão'); include __DIR__ . '/../partials/painel/opcoes-licenca.php'; ?>
                                    </select>
                                </td>
                                <?php if (!empty($chatgptHasActive)): ?>
                                    <td class="d2-tabela-cel" data-rotulo="<?= e(t('licencas.ide_chatgpt', 'IDE (ChatGPT)')) ?>">
                                        <select class="d2-control" data-atribuir-usuario="<?= (int) $u['id'] ?>" data-escopo="chatgpt" aria-label="<?= e(t('licencas.ide_chatgpt_de', 'IDE (ChatGPT) de :nome', ['nome' => $u['name']])) ?>">
                                            <?php $opcoes = $opcoesChatgpt; $selecionada = $u['chatgpt'] ?? null; $rotuloZero = t('licencas.herda', 'Herda o padrão'); include __DIR__ . '/../partials/painel/opcoes-licenca.php'; ?>
                                        </select>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>

<!-- Formulário de cadastro e edição (aberto num modal pelo módulo da página) -->
<template id="modelo-form-licenca">
    <form method="POST" action="/admin/licenses/create" class="licencas-form" novalidate>
        <?= csrf_field() ?>
        <div class="form-grade">
            <div class="d2-field">
                <label class="d2-label" for="lic-nome"><?= e(t('licencas.nome', 'Nome')) ?></label>
                <input class="d2-control" type="text" id="lic-nome" name="name" maxlength="100" required placeholder="<?= e(t('licencas.nome_ex', 'ex.: Max 2, conta suporte')) ?>">
            </div>
            <div class="d2-field">
                <label class="d2-label" for="lic-nota"><?= e(t('licencas.nota_opcional', 'Nota (opcional)')) ?></label>
                <input class="d2-control" type="text" id="lic-nota" name="note" maxlength="255" placeholder="<?= e(t('licencas.nota_ex', 'ex.: conta do financeiro')) ?>">
            </div>
            <div class="d2-field is-cheio">
                <label class="d2-label" for="lic-token" data-rotulo-novo="<?= e(t('licencas.token_campo', 'Token da licença')) ?>" data-rotulo-editar="<?= e(t('licencas.token_novo', 'Novo token (deixe vazio para manter o atual)')) ?>"><?= e(t('licencas.token_campo', 'Token da licença')) ?></label>
                <textarea class="d2-control licencas-token" id="lic-token" name="token" rows="3" autocomplete="off" spellcheck="false" placeholder="<?= e(t('licencas.token_ph', 'Cole aqui o token gerado (veja "Como obter o token")')) ?>"></textarea>
            </div>
            <div class="d2-field">
                <label class="d2-label" for="lic-dia"><?= e(t('licencas.renovacao_dia', 'Renovação semanal: dia')) ?></label>
                <select class="d2-control" id="lic-dia" name="reset_dow">
                    <option value=""><?= e(t('licencas.nao_informado', 'não informado')) ?></option>
                    <?php foreach ($diasSemana as $i => $nomeDia): ?><option value="<?= (int) $i ?>"><?= e($nomeDia) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="d2-field">
                <label class="d2-label" for="lic-hora"><?= e(t('licencas.renovacao_hora', 'Renovação semanal: hora')) ?></label>
                <select class="d2-control" id="lic-hora" name="reset_hour">
                    <option value=""><?= e(t('licencas.nao_informada', 'não informada')) ?></option>
                    <?php for ($i = 0; $i < 24; $i++): ?><option value="<?= $i ?>"><?= sprintf('%02d:00', $i) ?></option><?php endfor; ?>
                </select>
            </div>
            <p class="d2-field-hint is-cheio"><?= e(t('licencas.renovacao_dica', 'Quando esta assinatura renova os limites semanais (a Anthropic informa o dia e a hora na própria conta ou na mensagem de limite). Opcional; se preencher, informe dia e hora.')) ?></p>
            <div class="d2-field is-cheio">
                <label class="d2-label" for="lic-teto"><?= e(t('licencas.teto_campo', 'Teto de uso para IDE e Dev Autônomo (%)')) ?></label>
                <input class="d2-control licencas-teto-campo" type="number" id="lic-teto" name="limit_pct" min="1" max="99" step="1" placeholder="<?= e(t('licencas.teto_ph', 'ex.: 90; vazio = sem teto')) ?>">
                <span class="d2-field-hint"><?= e(t('licencas.teto_campo_dica', 'Quando o uso do plano (sessão de 5h ou semana) chegar neste percentual, a IDE e o Dev Autônomo param de consumir esta licença até a janela renovar; a folga que sobra fica reservada para a API, que nunca é bloqueada pelo teto. Vazio = sem teto.')) ?></span>
            </div>
        </div>
    </form>
</template>

<!-- Ajuda: como obter o token -->
<template id="modelo-ajuda-token">
    <div class="licencas-ajuda">
        <ol>
            <li><?= e(t('licencas.ajuda_1', 'Numa máquina com o Claude Code instalado (pode ser um terminal da própria IDE), rode o comando abaixo:')) ?>
                <div class="licencas-comando">
                    <pre class="codigo"><code>claude setup-token</code></pre>
                    <button type="button" class="d2-iconbtn d2-iconbtn-fantasma d2-iconbtn-sm" data-copiar="claude setup-token" aria-label="<?= e(t('licencas.copiar', 'Copiar')) ?>" data-tooltip="<?= e(t('licencas.copiar', 'Copiar')) ?>"><?= d2icone('copiar') ?></button>
                </div>
            </li>
            <li><?= e(t('licencas.ajuda_2', 'Abra o link que aparecer, faça login com a conta Max da licença que você quer usar e aprove.')) ?></li>
            <li><?= e(t('licencas.ajuda_3', 'Se o navegador mostrar um código em vez de voltar sozinho, cole o código de volta no terminal.')) ?></li>
            <li><?= e(t('licencas.ajuda_4', 'O comando mostra um token de 1 ano que puxa da cota daquela assinatura (sem cobrança por token). Copie e cole em "Nova licença".')) ?></li>
        </ol>
        <p class="texto-mudo"><?= e(t('licencas.ajuda_nota', 'É o mesmo tipo de token que o sistema já usa. Trate como senha. Para trocar de conta, o comando abre um login novo, independentemente do que já estiver logado na máquina.')) ?></p>
    </div>
</template>

<script type="application/json" id="dados-pagina"><?= json_encode($dadosPagina, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
