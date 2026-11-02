<?php
/**
 * Configurações do sistema (somente admin). Layout do painel (d2).
 * Dados: SettingsController@index. Comportamento: /assets/js/paginas/configuracoes.js.
 */
$this->layout('layouts/painel', [
    'title'       => t('config.titulo', 'Configurações do sistema'),
    'currentPage' => 'settings',
    'area'        => 'configuracoes',
    'subtitulo'   => t('config.sub', 'Ajustes gerais da plataforma, visíveis só para administradores.'),
    'scripts'     => ['/assets/js/paginas/configuracoes.js'],
    'estilos'     => ['/assets/css/paginas/configuracoes.css'],
]);
$smtpOk = !empty($smtp['configured']);
$fmtMem = static function (int $mb): string {
    return $mb >= 1024 ? rtrim(rtrim(number_format($mb / 1024, 1, ',', ''), '0'), ',') . ' GB' : $mb . ' MB';
};
$selectModelos = static function (string $escopo, string $atual, ?string $opcaoVazia) use ($models): string {
    $html = '<select class="d2-control" id="modelo-' . e($escopo) . '" data-escopo="' . e($escopo) . '">';
    if ($opcaoVazia !== null) {
        $html .= '<option value=""' . ($atual === '' ? ' selected' : '') . '>' . e($opcaoVazia) . '</option>';
    }
    foreach (($models ?? []) as $id => $rotulo) {
        $html .= '<option value="' . e($id) . '"' . ($atual === $id ? ' selected' : '') . '>' . e($rotulo) . '</option>';
    }
    return $html . '</select>';
};
$custos = [
    ['chave' => 'cost_track_chat', 'rotulo' => t('config.custo_chat', 'Chat (Arquiteto)'), 'on' => !empty($usageFlags['chat']), 'src' => $usage['chat'] ?? null],
    ['chave' => 'cost_track_api',  'rotulo' => t('config.custo_api', 'API (Gateway)'),     'on' => !empty($usageFlags['api']),  'src' => $usage['api'] ?? null],
    ['chave' => 'cost_track_ide',  'rotulo' => t('config.custo_ide', 'IDE'),               'on' => !empty($usageFlags['ide']),  'src' => $usage['ide'] ?? null],
];
$recursos = [
    ['chave' => 'sandbox', 'rotulo' => t('config.rec_autonomo', 'Dev Autônomo'), 'mem' => (int) $resources['sandbox_mem_mb'], 'cpu' => (int) $resources['sandbox_cpu']],
    ['chave' => 'ide',     'rotulo' => t('config.rec_ide', 'IDE'),               'mem' => (int) $resources['ide_mem_mb'],     'cpu' => (int) $resources['ide_cpu']],
];
?>

<div class="d2-mosaico">

    <section class="d2-painel" data-area="configuracoes" aria-labelledby="t-smtp">
        <header class="d2-painel-cab">
            <span class="d2-painel-icone" aria-hidden="true"><?= d2icone('email') ?></span>
            <h3 class="d2-painel-titulo" id="t-smtp"><?= e(t('config.smtp', 'Servidor de e-mail')) ?></h3>
            <div class="d2-painel-acoes">
                <span class="d2-selo d2-selo-<?= $smtpOk ? 'ok' : 'neutro' ?>"><span class="d2-ponto" aria-hidden="true"></span><span class="d2-selo-texto"><?= e($smtpOk ? t('config.configurado', 'Configurado') : t('config.nao_configurado', 'Não configurado')) ?></span></span>
            </div>
        </header>
        <div class="d2-painel-corpo d2-pilha">
            <p class="texto-mudo"><?= e(t('config.smtp_sub', 'Usado para enviar alertas, convites e redefinição de senha.')) ?></p>
            <dl class="kv">
                <dt><?= e(t('config.smtp_servidor', 'Servidor')) ?></dt><dd><?= e(($smtp['host'] ?? '') !== '' ? $smtp['host'] : t('config.nao_definido', 'Não definido')) ?></dd>
                <dt><?= e(t('config.smtp_remetente', 'Enviar como')) ?></dt><dd><?= e(($smtp['from'] ?? '') !== '' ? $smtp['from'] : t('config.nao_definido', 'Não definido')) ?></dd>
            </dl>
            <div class="form-acoes">
                <a href="/settings/email" class="d2-btn d2-btn-sm d2-btn-secundario"><?= d2icone('engrenagem') ?><span><?= e(t('config.smtp_configurar', 'Configurar e-mail')) ?></span></a>
            </div>
        </div>
    </section>

    <section class="d2-painel" data-area="configuracoes" aria-labelledby="t-notif">
        <header class="d2-painel-cab">
            <span class="d2-painel-icone" aria-hidden="true"><?= d2icone('sino') ?></span>
            <h3 class="d2-painel-titulo" id="t-notif"><?= e(t('config.notif', 'Notificações do sistema')) ?></h3>
        </header>
        <div class="d2-painel-corpo d2-pilha">
            <p class="texto-mudo"><?= e(t('config.notif_sub', 'Avisos administrativos, enviados para você como administrador.')) ?></p>
            <?php if (!$smtpOk): ?>
                <div class="d2-faixa d2-faixa-aviso"><?= d2icone('alerta') ?><div class="d2-faixa-texto"><?= e(t('config.notif_requer_smtp', 'Requer o servidor de e-mail configurado.')) ?> <a class="d2-link" href="/settings/email"><?= e(t('config.smtp_configurar', 'Configurar e-mail')) ?></a></div></div>
            <?php endif; ?>
            <ul class="lista-acionavel">
                <?php foreach ($prefs as $chave => $p): ?>
                    <li><div class="lista-item">
                        <div class="lista-item-texto"><span class="lista-item-titulo"><?= e($p['label']) ?></span></div>
                        <label class="d2-switch"><input type="checkbox" class="js-pref" data-pref="<?= e($chave) ?>" aria-label="<?= e($p['label']) ?>" <?= $p['on'] ? 'checked' : '' ?>><span class="d2-switch-trilho"></span></label>
                    </div></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </section>

    <section class="d2-painel" data-area="configuracoes" aria-labelledby="t-custo">
        <header class="d2-painel-cab">
            <span class="d2-painel-icone" aria-hidden="true"><?= d2icone('dinheiro') ?></span>
            <h3 class="d2-painel-titulo" id="t-custo"><?= e(t('config.custo', 'Custo estimado de uso')) ?></h3>
        </header>
        <div class="d2-painel-corpo d2-pilha">
            <p class="texto-mudo"><?= e(t('config.custo_sub', 'Escolha quais origens entram na conta do custo estimado mostrado no sistema. O valor é sempre uma estimativa (tokens vezes a tarifa). Por padrão, só a API é contabilizada.')) ?></p>
            <ul class="lista-acionavel">
                <?php foreach ($custos as $c):
                    $src = $c['src'] ?? ['cost' => 0, 'tokens_in' => 0, 'tokens_out' => 0]; ?>
                    <li><div class="lista-item">
                        <div class="lista-item-texto">
                            <span class="lista-item-titulo"><?= e($c['rotulo']) ?></span>
                            <span class="lista-item-sub d2-num"><?= e(formatCost($src['cost'] ?? 0)) ?> · <?= e(formatTokens(($src['tokens_in'] ?? 0) + ($src['tokens_out'] ?? 0))) ?> <?= e(t('config.tokens', 'tokens')) ?></span>
                        </div>
                        <label class="d2-switch"><input type="checkbox" class="js-flag" data-chave="<?= e($c['chave']) ?>" aria-label="<?= e($c['rotulo']) ?>" <?= $c['on'] ? 'checked' : '' ?>><span class="d2-switch-trilho"></span></label>
                    </div></li>
                <?php endforeach; ?>
            </ul>
            <dl class="kv">
                <dt><?= e(t('config.custo_total', 'Total contabilizado (origens ativas)')) ?></dt>
                <dd><strong class="d2-num" id="custo-total"><?= e(formatCost($usage['total_cost'] ?? 0)) ?></strong></dd>
                <dt><?= e(t('config.custo_zerado', 'Contadores')) ?></dt>
                <dd id="custo-zerado-em"><?= e($usageReset ? t('config.zerado_em', 'Zerados em') . ' ' . $usageReset : t('config.nunca_zerado', 'Nunca zerados')) ?></dd>
            </dl>
            <div class="form-acoes">
                <button type="button" class="d2-btn d2-btn-sm d2-btn-secundario" id="custo-zerar"><?= d2icone('atualizar') ?><span><?= e(t('config.zerar', 'Zerar contadores')) ?></span></button>
            </div>
        </div>
    </section>

    <section class="d2-painel" data-area="configuracoes" aria-labelledby="t-visib">
        <header class="d2-painel-cab">
            <span class="d2-painel-icone" aria-hidden="true"><?= d2icone('olho') ?></span>
            <h3 class="d2-painel-titulo" id="t-visib"><?= e(t('config.visib', 'Visibilidade do uso')) ?></h3>
        </header>
        <div class="d2-painel-corpo d2-pilha">
            <p class="texto-mudo"><?= e(t('config.visib_sub', 'Por padrão, só administradores veem os valores de uso e custo. Ative para que os membros também vejam.')) ?></p>
            <ul class="lista-acionavel">
                <li><div class="lista-item">
                    <div class="lista-item-texto"><span class="lista-item-titulo"><?= e(t('config.visib_membros', 'Membros podem ver uso e custo estimado')) ?></span></div>
                    <label class="d2-switch"><input type="checkbox" class="js-flag" data-chave="usage_visible_members" aria-label="<?= e(t('config.visib_membros', 'Membros podem ver uso e custo estimado')) ?>" <?= !empty($usageFlags['members_see']) ? 'checked' : '' ?>><span class="d2-switch-trilho"></span></label>
                </div></li>
            </ul>
        </div>
    </section>

    <section class="d2-painel" data-area="configuracoes" aria-labelledby="t-entrada">
        <header class="d2-painel-cab">
            <span class="d2-painel-icone" aria-hidden="true"><?= d2icone('externo') ?></span>
            <h3 class="d2-painel-titulo" id="t-entrada"><?= e(t('config.entrada', 'Entrada do sistema')) ?></h3>
        </header>
        <div class="d2-painel-corpo d2-pilha">
            <p class="texto-mudo"><?= e(t('config.entrada_sub', 'Ao acessar o endereço principal sem estar logado: mostrar a página de apresentação ou ir direto para o login.')) ?></p>
            <ul class="lista-acionavel">
                <li><div class="lista-item">
                    <div class="lista-item-texto">
                        <span class="lista-item-titulo"><?= e(t('config.entrada_home', 'Mostrar a página de apresentação')) ?></span>
                        <span class="lista-item-sub"><?= e(t('config.entrada_home_sub', 'Desligado: vai direto ao login')) ?></span>
                    </div>
                    <label class="d2-switch"><input type="checkbox" class="js-flag" data-chave="show_homepage" aria-label="<?= e(t('config.entrada_home', 'Mostrar a página de apresentação')) ?>" <?= !empty($showHomepage) ? 'checked' : '' ?>><span class="d2-switch-trilho"></span></label>
                </div></li>
            </ul>
        </div>
    </section>

    <section class="d2-painel" data-area="configuracoes" aria-labelledby="t-idioma">
        <header class="d2-painel-cab">
            <span class="d2-painel-icone" aria-hidden="true"><?= d2icone('documento') ?></span>
            <h3 class="d2-painel-titulo" id="t-idioma"><?= e(t('config.idioma', 'Idioma do sistema')) ?></h3>
        </header>
        <div class="d2-painel-corpo d2-pilha">
            <p class="texto-mudo"><?= e(t('config.idioma_sub', 'Para adicionar outro idioma: baixe o arquivo do português, traduza os textos e envie com um nome novo (ex.: en, es). A IDE fica em inglês quando o idioma não é português; você pode instalar um pacote de idioma nela.')) ?></p>
            <div class="form-grade">
                <div class="d2-field">
                    <label class="d2-label" for="idioma-ativo"><?= e(t('config.idioma_ativo', 'Idioma ativo')) ?></label>
                    <select class="d2-control" id="idioma-ativo">
                        <?php foreach (($languages ?? ['pt']) as $lg): ?>
                            <option value="<?= e($lg) ?>" <?= ($activeLang ?? 'pt') === $lg ? 'selected' : '' ?>><?= e(strtoupper($lg)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="d2-field config-alinhar-base">
                    <a class="d2-btn d2-btn-secundario" id="idioma-baixar" href="/settings/language/download?lang=<?= e($activeLang ?? 'pt') ?>"><?= d2icone('exportar') ?><span><?= e(t('config.idioma_baixar', 'Baixar o arquivo do idioma')) ?></span></a>
                </div>
            </div>
            <form class="form-grade" id="idioma-form" novalidate>
                <div class="d2-field">
                    <label class="d2-label" for="idioma-nome"><?= e(t('config.idioma_nome', 'Nome do novo idioma')) ?></label>
                    <input type="text" class="d2-control" id="idioma-nome" maxlength="20" placeholder="<?= e(t('config.idioma_nome_ex', 'ex.: en')) ?>">
                </div>
                <div class="d2-field">
                    <label class="d2-label" for="idioma-arquivo"><?= e(t('config.idioma_arquivo', 'Arquivo de textos (.json)')) ?></label>
                    <input type="file" class="d2-control" id="idioma-arquivo" accept=".json,application/json">
                </div>
                <div class="form-acoes is-cheio">
                    <button type="submit" class="d2-btn d2-btn-sm d2-btn-primario" id="idioma-enviar"><?= d2icone('enviar') ?><span><?= e(t('config.idioma_enviar', 'Enviar idioma')) ?></span></button>
                </div>
            </form>
        </div>
    </section>

    <section class="d2-painel is-largo" data-area="configuracoes" aria-labelledby="t-modelo">
        <header class="d2-painel-cab">
            <span class="d2-painel-icone" aria-hidden="true"><?= d2icone('estrela') ?></span>
            <h3 class="d2-painel-titulo" id="t-modelo"><?= e(t('config.modelo', 'Modelo de IA padrão')) ?></h3>
        </header>
        <div class="d2-painel-corpo d2-pilha">
            <p class="texto-mudo"><?= e(t('config.modelo_sub', 'IDE, Dev Autônomo e Agentes do Dev Autônomo têm modelos separados. O modelo do Dev Autônomo é o do Arquiteto (conversa, plano, integração); o dos Agentes vale para os especialistas das ondas paralelas, o verificador e o QA. Deixe em "Herdar" para usarem o mesmo do Dev Autônomo. Cada usuário pode trocar no próprio perfil, e na IDE também dá para trocar ao vivo com /model. Vale para as sessões abertas a partir de agora.')) ?></p>
            <div class="form-grade">
                <div class="d2-field">
                    <label class="d2-label" for="modelo-ide"><?= e(t('config.modelo_ide', 'IDE')) ?></label>
                    <?= $selectModelos('ide', (string) ($globalModelIde ?? ''), null) ?>
                </div>
                <div class="d2-field">
                    <label class="d2-label" for="modelo-autonomous"><?= e(t('config.modelo_autonomo', 'Dev Autônomo (Arquiteto)')) ?></label>
                    <?= $selectModelos('autonomous', (string) ($globalModelAutonomous ?? ''), null) ?>
                </div>
                <div class="d2-field">
                    <label class="d2-label" for="modelo-autonomous_agents"><?= e(t('config.modelo_agentes', 'Agentes do Dev Autônomo')) ?></label>
                    <?= $selectModelos('autonomous_agents', (string) ($globalModelAgents ?? ''), t('config.modelo_herdar', 'Herdar do Dev Autônomo')) ?>
                </div>
            </div>
            <ul class="lista-acionavel">
                <li><div class="lista-item">
                    <div class="lista-item-texto">
                        <span class="lista-item-titulo"><?= e(t('config.sonnet1m', 'Sonnet 4.6 com janela de 1M de contexto')) ?></span>
                        <span class="lista-item-sub"><?= e(t('config.sonnet1m_sub', 'Padrão: 200k tokens')) ?></span>
                    </div>
                    <label class="d2-switch"><input type="checkbox" class="js-flag" data-chave="sonnet_1m" aria-label="<?= e(t('config.sonnet1m', 'Sonnet 4.6 com janela de 1M de contexto')) ?>" <?= !empty($sonnet1m) ? 'checked' : '' ?>><span class="d2-switch-trilho"></span></label>
                </div></li>
            </ul>
            <div class="d2-faixa d2-faixa-aviso"><?= d2icone('alerta') ?>
                <div class="d2-faixa-texto">
                    <span class="d2-faixa-titulo"><?= e(t('config.sonnet1m_req', 'Pré-requisito: uso adicional habilitado na conta Anthropic')) ?></span>
                    <?= e(t('config.sonnet1m_texto', 'O 1M do Sonnet é cobrado por uso, à parte da assinatura, quando o contexto passa de 200k. Sem o uso adicional habilitado, ligar isto faz o Sonnet falhar. O Opus 4.8 já usa 1M, incluído no plano Max, sem custo extra. Vale para as sessões abertas a partir de agora.')) ?>
                    <a class="d2-link" href="https://claude.ai/settings/usage" target="_blank" rel="noopener"><?= e(t('config.sonnet1m_link', 'Abrir o uso adicional da conta')) ?></a>
                </div>
            </div>
        </div>
    </section>

    <section class="d2-painel is-largo" data-area="configuracoes" aria-labelledby="t-recursos"
             data-host-mem="<?= (int) $resources['host_mem_mb'] ?>" data-host-cpu="<?= (int) $resources['host_cpu'] ?>">
        <header class="d2-painel-cab">
            <span class="d2-painel-icone" aria-hidden="true"><?= d2icone('computador') ?></span>
            <h3 class="d2-painel-titulo" id="t-recursos"><?= e(t('config.recursos', 'Recursos dos ambientes')) ?></h3>
            <span class="d2-painel-sub"><?= e(t('config.recursos_servidor', 'Este servidor')) ?>: <?= (int) $resources['host_cpu'] ?> <?= e(t('config.nucleos', 'núcleos')) ?> · <?= e($fmtMem((int) $resources['host_mem_mb'])) ?></span>
        </header>
        <div class="d2-painel-corpo d2-pilha">
            <p class="texto-mudo"><?= e(t('config.recursos_sub', 'Quanta CPU e memória cada ambiente de trabalho recebe. Vale para os ambientes abertos a partir de agora; os que já estão abertos mantêm o limite atual até serem reabertos. O máximo é a capacidade deste servidor.')) ?></p>
            <div class="config-recursos">
                <?php foreach ($recursos as $r): ?>
                    <div class="config-recurso">
                        <h4 class="config-recurso-titulo"><?= e($r['rotulo']) ?></h4>
                        <div class="d2-field">
                            <div class="config-faixa-cab">
                                <label class="d2-label" for="rec-<?= e($r['chave']) ?>-mem"><?= e(t('config.memoria', 'Memória')) ?></label>
                                <strong class="d2-num" id="rec-<?= e($r['chave']) ?>-mem-valor"><?= e($fmtMem($r['mem'])) ?></strong>
                            </div>
                            <input type="range" class="config-faixa js-recurso" id="rec-<?= e($r['chave']) ?>-mem" data-alvo="<?= e($r['chave']) ?>" data-tipo="mem"
                                   min="512" max="<?= (int) $resources['host_mem_mb'] ?>" step="256" value="<?= $r['mem'] ?>">
                        </div>
                        <div class="d2-field">
                            <div class="config-faixa-cab">
                                <label class="d2-label" for="rec-<?= e($r['chave']) ?>-cpu"><?= e(t('config.cpu', 'CPU (núcleos)')) ?></label>
                                <strong class="d2-num" id="rec-<?= e($r['chave']) ?>-cpu-valor"><?= (int) $r['cpu'] ?></strong>
                            </div>
                            <input type="range" class="config-faixa js-recurso" id="rec-<?= e($r['chave']) ?>-cpu" data-alvo="<?= e($r['chave']) ?>" data-tipo="cpu"
                                   min="1" max="<?= (int) $resources['host_cpu'] ?>" step="1" value="<?= $r['cpu'] ?>">
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="config-recurso">
                <div class="config-faixa-cab">
                    <div>
                        <h4 class="config-recurso-titulo"><?= e(t('config.alerta', 'Alerta de uso do servidor')) ?></h4>
                        <p class="texto-mudo"><?= e(t('config.alerta_sub', 'Avisa os administradores por WhatsApp e e-mail quando o uso de disco ou memória passar do limite. Verificado a cada 5 minutos.')) ?></p>
                    </div>
                    <label class="d2-switch"><input type="checkbox" id="alerta-ativo" <?= !empty($resourceAlerts['enabled']) ? 'checked' : '' ?>><span class="d2-switch-trilho"></span><span class="d2-switch-texto"><?= e(t('config.ativo', 'Ativo')) ?></span></label>
                </div>
                <div class="form-grade">
                    <div class="d2-field">
                        <div class="config-faixa-cab">
                            <label class="d2-label" for="alerta-disco"><?= e(t('config.disco', 'Disco')) ?></label>
                            <strong class="d2-num"><span id="alerta-disco-valor"><?= (int) $resourceAlerts['disk_pct'] ?></span>%</strong>
                        </div>
                        <input type="range" class="config-faixa" id="alerta-disco" min="50" max="99" step="1" value="<?= (int) $resourceAlerts['disk_pct'] ?>">
                    </div>
                    <div class="d2-field">
                        <div class="config-faixa-cab">
                            <label class="d2-label" for="alerta-ram"><?= e(t('config.memoria', 'Memória')) ?></label>
                            <strong class="d2-num"><span id="alerta-ram-valor"><?= (int) $resourceAlerts['ram_pct'] ?></span>%</strong>
                        </div>
                        <input type="range" class="config-faixa" id="alerta-ram" min="50" max="99" step="1" value="<?= (int) $resourceAlerts['ram_pct'] ?>">
                    </div>
                </div>
            </div>
        </div>
    </section>

</div>
