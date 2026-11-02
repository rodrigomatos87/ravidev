<?php
/**
 * Agendamentos: executar um arquivo ou comando do projeto periodicamente, ou acionar o Claude num horário.
 * Layout do painel (d2). Dados: ScheduleController@index. Comportamento: /assets/js/paginas/agendamentos.js.
 */
$temProjetos = !empty($projects);
$this->layout('layouts/painel', [
    'title'       => t('agenda.titulo', 'Agendamentos'),
    'currentPage' => 'schedules',
    'area'        => 'agendamentos',
    'subtitulo'   => t('agenda.sub', 'Execute um arquivo ou comando do projeto periodicamente, ou acione o Claude num horário. Com histórico de cada execução.'),
    'acoes'       => $temProjetos
        ? '<button type="button" class="d2-btn d2-btn-primario" id="agenda-novo">' . d2icone('mais') . '<span>' . e(t('agenda.novo', 'Novo agendamento')) . '</span></button>'
        : '',
    'scripts'     => ['/assets/js/paginas/agendamentos.js'],
    'estilos'     => ['/assets/css/paginas/agendamentos.css'],
]);

$dados = [
    'projetos' => array_map(fn ($p) => ['id' => (int) $p['id'], 'nome' => (string) $p['name']], $projects ?? []),
    'agendamentos' => array_map(fn ($s) => [
        'id'        => (int) $s['id'],
        'tipo'      => ($s['kind'] ?? '') === 'claude' ? 'claude' : 'command',
        'titulo'    => (string) ($s['title'] ?? ''),
        'conteudo'  => (string) ($s['payload'] ?? ''),
        'projeto'   => (string) ($s['project_name'] ?? ''),
        'quando'    => ($s['schedule_type'] ?? '') === 'daily' ? 'daily' : 'interval',
        'minutos'   => (int) ($s['interval_minutes'] ?? 0),
        'horario'   => (string) ($s['daily_time'] ?? ''),
        'ultima'    => (string) ($s['last_run_at'] ?? ''),
        'proxima'   => (string) ($s['next_run_at'] ?? ''),
        'ativo'     => (int) ($s['enabled'] ?? 0) === 1,
    ], $schedules ?? []),
];
?>

<?php if (!$temProjetos): ?>
    <section class="d2-painel" data-area="agendamentos">
        <div class="d2-painel-corpo">
            <div class="d2-vazio">
                <span class="d2-vazio-icone" aria-hidden="true"><?= d2icone('calendario') ?></span>
                <p class="d2-vazio-titulo"><?= e(t('agenda.sem_projeto', 'Você precisa de ao menos um projeto')) ?></p>
                <p class="d2-vazio-texto"><?= e(t('agenda.sem_projeto_texto', 'Os agendamentos rodam dentro de um projeto. Crie um para começar.')) ?></p>
                <a class="d2-btn d2-btn-primario" href="/projects/create"><?= d2icone('mais') ?><span><?= e(t('agenda.criar_projeto', 'Criar projeto')) ?></span></a>
            </div>
        </div>
    </section>
<?php else: ?>
    <section class="d2-painel" data-area="agendamentos" aria-labelledby="t-agenda">
        <header class="d2-painel-cab">
            <span class="d2-painel-icone" aria-hidden="true"><?= d2icone('calendario') ?></span>
            <h3 class="d2-painel-titulo" id="t-agenda"><?= e(t('agenda.lista', 'Seus agendamentos')) ?></h3>
            <span class="d2-painel-sub"><?= e(t('agenda.lista_sub', 'Ligue ou desligue sem perder a configuração.')) ?></span>
        </header>
        <div class="d2-painel-corpo" id="agenda-tabela"></div>
    </section>
<?php endif; ?>

<script type="application/json" id="dados-pagina"><?= json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
