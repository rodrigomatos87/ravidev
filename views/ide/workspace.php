<?php
/**
 * Área de IDEs com abas — cada projeto aberto vira uma aba (iframe do code-server).
 * Página full-screen própria (sem o layout/sidebar do app).
 */
$sessions = $sessions ?? [];
$active = $active ?? '';
if ($active === '' && !empty($sessions)) {
    $active = $sessions[count($sessions) - 1]['uuid']; // default: a última aberta
}
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="<?= csrf_token() ?>">
<title>IDE — Ravi Developer</title>
<script>try{var t=localStorage.getItem("ravi-theme");if(t)document.documentElement.setAttribute("data-theme",t);}catch(e){}</script>
<style>
:root{--bar-bg:#16161c;--bar-border:#2a2a35;--txt:#e5e5e7;--txt-dim:#9a9aa8;--accent:#7c6cff;--hover:rgba(255,255,255,.07);--tab-active:#0e0e13;}
[data-theme="light"]{--bar-bg:#ffffff;--bar-border:#e3e3ee;--txt:#1a1b2b;--txt-dim:#6b6d86;--accent:#7c6cff;--hover:rgba(0,0,0,.05);--tab-active:#f3f4fb;}
*{box-sizing:border-box;margin:0;padding:0;}
html,body{height:100%;}
/* height: usa 100dvh (dynamic viewport height) no mobile — o 100vh inclui a área atrás da barra
   inferior do navegador, jogando o rodapé do iframe (campo do Claude) para fora da tela visível. */
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:var(--tab-active);color:var(--txt);display:flex;flex-direction:column;height:100vh;height:100dvh;overflow:hidden;}
.ide-bar{display:flex;align-items:center;gap:.25rem;height:44px;min-height:44px;background:var(--bar-bg);border-bottom:1px solid var(--bar-border);padding:0 .5rem;}
.ide-home{display:flex;align-items:center;gap:.4rem;padding:.4rem .7rem;color:var(--txt-dim);text-decoration:none;font-size:.85rem;border-radius:8px;white-space:nowrap;}
.ide-home:hover{background:var(--hover);color:var(--txt);}
.ide-tabs{display:flex;align-items:center;gap:.25rem;flex:1;overflow-x:auto;height:100%;}
.ide-tabs::-webkit-scrollbar{height:0;}
.ide-tab{display:flex;align-items:center;gap:.5rem;padding:.45rem .7rem;border-radius:8px;cursor:pointer;font-size:.85rem;color:var(--txt-dim);white-space:nowrap;border:1px solid transparent;}
.ide-tab:hover{background:var(--hover);color:var(--txt);}
.ide-tab.active{background:var(--tab-active);color:var(--txt);border-color:var(--bar-border);}
.ide-tab .dot{width:6px;height:6px;border-radius:50%;background:var(--txt-dim);opacity:.3;flex:0 0 auto;transition:background .2s,opacity .2s;}
.ide-tab .dot.busy{background:#2ecc71;opacity:1;}
/* AZUL = o Claude está pausado aguardando você (permissão/pergunta). Pulsa de leve p/ chamar. */
.ide-tab .dot.waiting{background:#3b82f6;opacity:1;animation:ide-dot-pulse 1.4s ease-in-out infinite;}
@keyframes ide-dot-pulse{0%,100%{box-shadow:0 0 0 0 rgba(59,130,246,.55);}50%{box-shadow:0 0 0 4px rgba(59,130,246,0);}}
.ide-tab .x{display:flex;width:16px;height:16px;align-items:center;justify-content:center;border-radius:4px;font-size:1rem;line-height:1;color:var(--txt-dim);}
.ide-tab .x:hover{background:var(--hover);color:var(--txt);}
.ide-spacer{flex:0 0 auto;}
.ide-btn{display:flex;align-items:center;justify-content:center;width:34px;height:34px;border-radius:8px;cursor:pointer;color:var(--txt-dim);background:none;border:0;}
.ide-btn:hover{background:var(--hover);color:var(--txt);}
.ide-worktime{display:flex;align-items:center;gap:.35rem;margin-left:auto;padding:0 .5rem;font-size:.76rem;color:var(--txt-dim);white-space:nowrap;cursor:pointer;border-radius:8px;user-select:none;}
.ide-worktime:hover{background:var(--hover);}
.ide-worktime .wt-full{display:flex;align-items:center;gap:.35rem;}
.ide-worktime .wt-label{opacity:.6;}
.ide-worktime .wt-val{font-variant-numeric:tabular-nums;color:var(--txt);font-weight:600;}
/* Modo recolhido: só um relógio. Apagado quando ocioso; ACESO (accent + leve glow + ponteiro girando) quando está computando o tempo. */
.ide-worktime.collapsed{padding:0 .35rem;}
.ide-worktime .wt-clock{display:none;width:30px;height:30px;align-items:center;justify-content:center;border-radius:8px;color:var(--txt-dim);opacity:.5;transition:color .2s,opacity .2s;}
.ide-worktime.collapsed .wt-full{display:none;}
.ide-worktime.collapsed .wt-clock{display:flex;}
.ide-worktime .wt-clock.lit{color:var(--accent);opacity:1;filter:drop-shadow(0 0 5px color-mix(in srgb,var(--accent) 60%,transparent));}
.ide-worktime .wt-clock.lit .wt-hand{transform-origin:12px 12px;animation:wt-tick 2s steps(8) infinite;}
@keyframes wt-tick{to{transform:rotate(360deg);}}
.ide-frames{flex:1;position:relative;background:var(--tab-active);}
/* Cada IDE vive numa .ide-pane (loader + iframe + divisória). O iframe NUNCA muda de lugar no DOM
   ao alternar o layout (reparentar um iframe RECARREGA a IDE dentro dele); só o CSS muda. */
.ide-pane{background:var(--tab-active);}
#ide-frames:not(.split) .ide-pane{position:absolute;inset:0;display:none;}
#ide-frames:not(.split) .ide-pane.active{display:block;}
.ide-pane iframe{position:absolute;inset:0;width:100%;height:100%;border:0;display:block;background:var(--tab-active);}
/* Modo dividido: panes lado a lado (largura ajustável), rolagem horizontal embaixo para escolher
   o trecho visível quando a soma passa da tela. */
#ide-frames.split{display:flex;align-items:stretch;overflow-x:auto;overflow-y:hidden;}
#ide-frames.split .ide-pane{position:relative;flex:0 0 auto;height:100%;min-width:280px;border-right:1px solid var(--bar-border);}
#ide-frames.split .ide-pane.active::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:var(--accent);z-index:6;}
#ide-frames.split::-webkit-scrollbar{height:11px;}
#ide-frames.split::-webkit-scrollbar-track{background:var(--bar-bg);}
#ide-frames.split::-webkit-scrollbar-thumb{background:var(--bar-border);border-radius:6px;}
#ide-frames.split::-webkit-scrollbar-thumb:hover{background:var(--txt-dim);}
/* Divisória arrastável na borda direita de cada pane (aparece só no modo dividido). */
.ide-pane .pane-grip{display:none;position:absolute;top:0;right:-4px;width:9px;height:100%;cursor:col-resize;z-index:8;touch-action:none;}
#ide-frames.split .ide-pane .pane-grip{display:block;}
.ide-pane .pane-grip::after{content:'';position:absolute;left:3px;top:0;width:2px;height:100%;background:transparent;transition:background .15s;}
.ide-pane .pane-grip:hover::after,.ide-pane .pane-grip.on::after{background:var(--accent);}
/* Durante o arrasto os iframes não podem "engolir" o mouse. */
body.ide-resizing{user-select:none;}
body.ide-resizing iframe{pointer-events:none;}
/* Botões de layout (1 tela cheia / dividido) */
.ide-layouts{display:flex;align-items:center;gap:.1rem;flex:0 0 auto;}
.ide-btn.on{color:var(--accent);background:var(--hover);}
/* Preloader por IDE: cobre o iframe até o ambiente (code-server) estar pronto, evitando a tela
   de "Tentando reconectar"/branca que obrigava a dar refresh ao voltar de um standby. */
.ide-pane .ide-loader{position:absolute;inset:0;z-index:5;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:1rem;background:var(--tab-active);color:var(--txt-dim);}
.ide-pane .ide-loader.done{display:none!important;}
.ide-spinner{width:34px;height:34px;border-radius:50%;border:3px solid var(--bar-border);border-top-color:var(--accent);animation:ide-spin .8s linear infinite;}
@keyframes ide-spin{to{transform:rotate(360deg);}}
.ide-loader-txt{font-size:.85rem;}
.ide-empty{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:1rem;color:var(--txt-dim);}
.ide-empty a{color:var(--accent);text-decoration:none;}
/* Botão "+" ao lado das abas: abre outra IDE SEM recarregar a página (as demais não perdem nada). */
.ide-add{display:flex;align-items:center;justify-content:center;flex:0 0 auto;width:28px;height:28px;border-radius:8px;cursor:pointer;color:var(--txt-dim);background:none;border:0;font-size:1.15rem;line-height:1;}
.ide-add:hover{background:var(--hover);color:var(--txt);}
/* Modal de seleção de projeto */
.ide-modal-ov{position:fixed;inset:0;z-index:50;display:none;align-items:flex-start;justify-content:center;padding-top:12vh;background:rgba(0,0,0,.55);}
.ide-modal-ov.open{display:flex;}
.ide-modal{width:min(420px,calc(100vw - 2rem));max-height:70vh;display:flex;flex-direction:column;background:var(--bar-bg);border:1px solid var(--bar-border);border-radius:14px;box-shadow:0 18px 50px rgba(0,0,0,.45);overflow:hidden;}
.ide-modal-head{display:flex;align-items:center;justify-content:space-between;gap:1rem;padding:.9rem 1rem .6rem;}
.ide-modal-head strong{font-size:.95rem;}
.ide-modal-close{display:flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:8px;cursor:pointer;color:var(--txt-dim);background:none;border:0;font-size:1.1rem;}
.ide-modal-close:hover{background:var(--hover);color:var(--txt);}
.ide-modal-search{margin:0 1rem .6rem;padding:.5rem .7rem;border:1px solid var(--bar-border);border-radius:9px;background:var(--tab-active);color:var(--txt);font-size:.85rem;outline:none;}
.ide-modal-search:focus{border-color:var(--accent);}
.ide-modal-list{flex:1;overflow-y:auto;padding:0 .6rem .7rem;display:flex;flex-direction:column;gap:.15rem;}
.ide-modal-item{display:flex;align-items:center;gap:.6rem;padding:.55rem .6rem;border-radius:9px;cursor:pointer;font-size:.88rem;color:var(--txt);background:none;border:0;text-align:left;width:100%;}
.ide-modal-item:hover{background:var(--hover);}
.ide-modal-item[disabled]{cursor:default;color:var(--txt-dim);background:none;}
.ide-modal-item .pj-nm{flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.ide-modal-item .pj-tag{flex:0 0 auto;font-size:.7rem;color:var(--txt-dim);border:1px solid var(--bar-border);border-radius:6px;padding:.1rem .4rem;}
.ide-modal-msg{padding:.8rem 1rem 1rem;font-size:.85rem;color:var(--txt-dim);text-align:center;}
/* Conta do Claude (engrenagem ao lado dos botões de layout) — submenu suspenso no hover com os
   cards das contas + barras de uso (mesma linguagem dos cards do perfil). */
.ide-lic-wrap{position:relative;flex:0 0 auto;display:flex;align-items:center;}
.ide-lic-menu{position:absolute;top:calc(100% + 6px);right:0;z-index:60;width:300px;max-height:min(70vh,520px);overflow-y:auto;
    background:var(--bar-bg);border:1px solid var(--bar-border);border-radius:14px;box-shadow:0 18px 50px rgba(0,0,0,.45);
    padding:.55rem;display:none;flex-direction:column;gap:.4rem;}
.ide-lic-wrap.open .ide-lic-menu{display:flex;}
.ide-lic-wrap.open > .ide-btn{background:var(--hover);color:var(--txt);}
/* ponte invisível entre o botão e o menu: o mouse atravessa sem "cair" e fechar o hover */
.ide-lic-menu::before{content:'';position:absolute;top:-8px;left:0;right:0;height:8px;}
.ide-lic-head{font-size:.8rem;font-weight:700;color:var(--txt);padding:.25rem .35rem 0;}
.ide-lic-card{position:relative;display:flex;flex-direction:column;gap:.22rem;text-align:left;padding:.6rem .7rem;
    background:var(--tab-active);border:1px solid var(--bar-border);border-radius:10px;color:var(--txt);
    cursor:pointer;font:inherit;transition:border-color .15s,box-shadow .15s;}
.ide-lic-card:hover{border-color:var(--accent);}
.ide-lic-card.selected{border-color:var(--accent);box-shadow:0 0 0 2px color-mix(in srgb,var(--accent) 22%,transparent);}
/* Conta NO TETO de uso do admin: não dá para selecionar (o servidor também recusa) — só a já
   selecionada continua interativa, para o usuário poder sair dela. */
.ide-lic-card.blocked:not(.selected){opacity:.5;cursor:not-allowed;}
.ide-lic-card.blocked:not(.selected):hover{border-color:var(--bar-border);}
.ide-lic-menu.saving .ide-lic-card{opacity:.6;pointer-events:none;}
.ide-lic-check{position:absolute;top:.55rem;right:.55rem;width:16px;height:16px;border-radius:50%;border:2px solid var(--bar-border);}
.ide-lic-card.selected .ide-lic-check{border-color:var(--accent);background:var(--accent);}
.ide-lic-card.selected .ide-lic-check::after{content:"✓";display:block;color:#fff;font-size:.62rem;font-weight:700;line-height:12px;text-align:center;}
.ide-lic-name{font-weight:700;font-size:.84rem;padding-right:1.5rem;}
.ide-lic-sub{font-size:.7rem;color:var(--txt-dim);}
.ide-lic-sub .lic-inuse{color:var(--accent);font-weight:700;}
.ide-lic-sub .lic-applying{color:#f59e0b;font-weight:700;font-variant-numeric:tabular-nums;}
.ide-lic-usage{display:flex;flex-direction:column;gap:.24rem;margin-top:.35rem;}
.lc-loading{font-size:.7rem;color:var(--txt-dim);font-style:italic;}
.lc-row{display:flex;align-items:center;gap:.45rem;}
.lc-label{flex:0 0 4.2em;font-size:.66rem;color:var(--txt-dim);white-space:nowrap;}
.lc-bar{position:relative;flex:1;height:6px;border-radius:999px;background:var(--hover);border:1px solid var(--bar-border);overflow:hidden;}
.lc-mark{position:absolute;top:0;bottom:0;width:2px;margin-left:-1px;background:#f59e0b;opacity:.95;}
.lc-limit{font-size:.64rem;color:var(--txt-dim);margin-top:.06rem;}
.lc-limit-hit{color:#ef4444;font-weight:600;}
.lc-fill{display:block;height:100%;border-radius:999px;}
.lc-ok{background:#10b981;}.lc-warn{background:#f59e0b;}.lc-bad{background:#ef4444;}
.lc-pct{flex:0 0 2.6em;font-size:.66rem;font-weight:600;text-align:right;font-variant-numeric:tabular-nums;}
.lc-pct-ok{color:var(--txt-dim);}.lc-pct-warn{color:#f59e0b;}.lc-pct-bad{color:#ef4444;}
.lc-renew{font-size:.64rem;color:var(--txt-dim);margin-top:.06rem;}
.ide-lic-foot{font-size:.68rem;color:var(--txt-dim);padding:.15rem .35rem .3rem;}
/* Selo da contagem no próprio botão (visível mesmo com o menu fechado): "59" caindo até aplicar. */
.ide-lic-badge{position:absolute;top:-3px;right:-3px;min-width:17px;height:17px;padding:0 3px;border-radius:999px;
    background:#f59e0b;color:#1a1204;font-size:.62rem;font-weight:800;line-height:17px;text-align:center;
    font-variant-numeric:tabular-nums;pointer-events:none;}
.ide-lic-badge.done{background:#10b981;color:#04170e;}
</style>
</head>
<body>
<div class="ide-bar">
    <?php $homeUuid = $active ?: ($sessions[0]['uuid'] ?? ''); ?>
    <a class="ide-home" id="ide-home" href="<?= $homeUuid ? '/projects/' . e($homeUuid) : '/dashboard' ?>" title="Voltar ao projeto">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 18l-6-6 6-6"/></svg>
        Projeto
    </a>
    <div class="ide-tabs" id="ide-tabs">
        <?php foreach ($sessions as $s): ?>
        <?php $st = $s['state'] ?? (!empty($s['busy']) ? 'working' : 'idle');
              $dotCls = $st === 'waiting' ? 'waiting' : ($st === 'working' ? 'busy' : '');
              $dotTitle = $st === 'waiting' ? 'Aguardando sua resposta' : ($st === 'working' ? 'Processando' : ''); ?>
        <div class="ide-tab <?= $s['uuid'] === $active ? 'active' : '' ?>" data-uuid="<?= e($s['uuid']) ?>" data-work="<?= (int) ($s['work_seconds'] ?? 0) ?>">
            <span class="dot <?= $dotCls ?>" title="<?= e($dotTitle) ?>"></span>
            <span class="nm"><?= e($s['name']) ?></span>
            <span class="x" title="Fechar IDE">&times;</span>
        </div>
        <?php endforeach; ?>
        <button class="ide-add" id="ide-add" title="Abrir outro projeto nesta tela">+</button>
    </div>
    <div class="ide-layouts">
        <button class="ide-btn" id="ide-layout-single" title="Uma IDE por vez (tela cheia)">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="4" y="4" width="16" height="16" rx="2"/></svg>
        </button>
        <button class="ide-btn" id="ide-layout-split" title="Dividir a tela entre os projetos abertos (clique de novo para igualar os tamanhos)">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3.5" y="3.5" width="7" height="7" rx="1.5"/><rect x="13.5" y="3.5" width="7" height="7" rx="1.5"/><rect x="3.5" y="13.5" width="7" height="7" rx="1.5"/><rect x="13.5" y="13.5" width="7" height="7" rx="1.5"/></svg>
        </button>
    </div>
    <?php if ((!empty($licenseChoice) && !empty($licenseOptions)) || !empty($limitCard)): ?>
    <div class="ide-lic-wrap" id="ide-lic-wrap">
        <button class="ide-btn" id="ide-lic-btn" title="<?= !empty($licenseChoice) ? 'Conta do Claude' : 'Uso do plano' ?>" aria-haspopup="true" aria-expanded="false">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09a1.65 1.65 0 0 0-1-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09a1.65 1.65 0 0 0 1.51-1 1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33h.01a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51h.01a1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82v.01a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
            <span class="ide-lic-badge" id="ide-lic-badge" hidden></span>
        </button>
        <?php if (!empty($licenseChoice)): ?>
        <div class="ide-lic-menu" id="ide-lic-menu" role="menu" aria-label="Conta do Claude">
            <div class="ide-lic-head">Conta do Claude</div>
            <?php // "Em uso" = a conta EFETIVA: a escolhida pelo usuário ou, sem escolha, a padrão de hoje.
                  $curLicId = (int) ($userLicenseId ?? 0) ?: (int) ($globalLicense['id'] ?? 0); ?>
            <?php foreach ($licenseOptions as $opt): ?>
            <button type="button" class="ide-lic-card js-ide-lic<?= $curLicId === $opt['id'] ? ' selected' : '' ?>" data-id="<?= $opt['id'] ?>">
                <span class="ide-lic-check" aria-hidden="true"></span>
                <span class="ide-lic-name"><?= e($opt['name']) ?></span>
                <span class="ide-lic-sub js-lic-sub" data-sub=""></span>
                <span class="ide-lic-usage js-lic-usage" data-id="<?= $opt['id'] ?>"><span class="lc-loading">carregando uso…</span></span>
            </button>
            <?php endforeach; ?>
            <div class="ide-lic-foot">A troca vale também para as IDEs já abertas, aplicada em até 60 segundos.</div>
        </div>
        <?php else: ?>
        <!-- Somente leitura: a conta efetiva da IDE tem TETO de uso configurado pelo admin; o
             usuário acompanha o consumo e sabe quando a IDE pausa (sem escolher conta). -->
        <div class="ide-lic-menu" id="ide-lic-menu" role="menu" aria-label="Uso do plano">
            <div class="ide-lic-head">Uso do plano</div>
            <div class="ide-lic-card" style="cursor:default">
                <span class="ide-lic-usage js-lic-usage" data-id="<?= (int) $limitCard['id'] ?>"><span class="lc-loading">carregando uso…</span></span>
            </div>
            <div class="ide-lic-foot">Ao chegar no teto de <?= (int) $limitCard['limit'] ?>%, a IDE pausa até a janela renovar (a folga fica reservada para as APIs).</div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <span class="ide-worktime" id="ide-worktime" title="Tempo trabalhado neste projeto (clique para recolher/expandir)">
        <span class="wt-full"><span class="wt-label">Trabalhado</span> <span class="wt-val" id="ide-worktime-val">00:00</span></span>
        <span class="wt-clock" id="wt-clock" aria-label="Tempo trabalhado">
            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><path class="wt-hand" d="M12 12V7"/><path d="M12 12l3.5 2"/></svg>
        </span>
    </span>
    <button class="ide-btn" id="ide-theme" title="Alternar tema da IDE">
        <svg class="ic-sun" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="5"/><path d="M12 1v2M12 21v2M4.2 4.2l1.4 1.4M18.4 18.4l1.4 1.4M1 12h2M21 12h2M4.2 19.8l1.4-1.4M18.4 5.6l1.4-1.4"/></svg>
    </button>
</div>

<div class="ide-frames" id="ide-frames">
    <?php if (empty($sessions)): ?>
    <div class="ide-empty" id="ide-empty">
        <p>Nenhuma IDE aberta.</p>
        <a href="/dashboard">Abrir um projeto no Dashboard →</a>
    </div>
    <?php endif; ?>
    <?php foreach ($sessions as $s): ?>
    <div class="ide-pane <?= $s['uuid'] === $active ? 'active' : '' ?>" data-uuid="<?= e($s['uuid']) ?>">
        <div class="ide-loader" data-uuid="<?= e($s['uuid']) ?>">
            <div class="ide-spinner"></div>
            <div class="ide-loader-txt">Preparando o ambiente…</div>
        </div>
        <iframe data-uuid="<?= e($s['uuid']) ?>" data-src="<?= e($s['url']) ?>" allow="clipboard-read; clipboard-write; fullscreen"></iframe>
        <span class="pane-grip" title="Arraste para redimensionar"></span>
    </div>
    <?php endforeach; ?>
</div>

<div class="ide-modal-ov" id="ide-modal-ov">
    <div class="ide-modal" role="dialog" aria-label="Abrir projeto na IDE">
        <div class="ide-modal-head">
            <strong>Abrir projeto na IDE</strong>
            <button class="ide-modal-close" id="ide-modal-close" title="Fechar">&times;</button>
        </div>
        <input type="text" class="ide-modal-search" id="ide-modal-search" placeholder="Filtrar projetos…" autocomplete="off">
        <div class="ide-modal-list" id="ide-modal-list"></div>
        <div class="ide-modal-msg" id="ide-modal-msg" style="display:none;"></div>
    </div>
</div>

<script>
(function(){
    var CSRF = <?= json_encode(csrf_token()) ?>;
    var active = <?= json_encode($active) ?>;
    function post(url, body){ return fetch(url,{method:'POST',headers:{'X-CSRF-Token':CSRF,'X-Requested-With':'XMLHttpRequest','Content-Type':'application/x-www-form-urlencoded'},credentials:'same-origin',body:body}).then(function(r){return r.json();}).catch(function(){return null;}); }

    // ── Tempo trabalhado (topo): conta 1/s enquanto a bolinha verde (working) aparece, mostra e salva ──
    var workSecs = {}, pendDelta = {}, wtFlushing = false;
    function fmtHMS(s){ s=Math.max(0,Math.floor(s)); var h=Math.floor(s/3600), m=Math.floor((s%3600)/60), x=s%60; function p(n){return (n<10?'0':'')+n;} return (h>0?(h+':'):'')+p(m)+':'+p(x); }
    function curUuid(){ if (active) return active; var f=document.querySelector('.ide-tab'); return f?f.getAttribute('data-uuid'):''; }
    function curWorking(){ var u=curUuid(); if(!u) return false; var t=document.querySelector('.ide-tab[data-uuid="'+u+'"]'); var d=t&&t.querySelector('.dot'); return !!(d&&d.classList.contains('busy')); }
    function renderWorktime(){
        var el=document.getElementById('ide-worktime-val');
        var clk=document.getElementById('wt-clock'); if(clk) clk.classList.toggle('lit', curWorking());
        if(!el) return; var u=curUuid(); el.textContent = u ? fmtHMS(workSecs[u]||0) : '00:00';
    }
    // Recolher/expandir: clique alterna entre "Trabalhado MM:SS" e só o relógio (preferência salva).
    (function(){ var wt=document.getElementById('ide-worktime'); if(!wt) return;
        if(localStorage.getItem('ide-wt-collapsed')==='1') wt.classList.add('collapsed');
        wt.addEventListener('click', function(){ localStorage.setItem('ide-wt-collapsed', wt.classList.toggle('collapsed')?'1':'0'); });
    })();
    function flushWork(){
        if (wtFlushing) return;
        var snapshot={}, any=false;
        Object.keys(pendDelta).forEach(function(u){ if(pendDelta[u]>0){ snapshot[u]=pendDelta[u]; any=true; } });
        if (!any) return;
        var body=new URLSearchParams();
        Object.keys(snapshot).forEach(function(u){ body.set('deltas['+u+']', snapshot[u]); pendDelta[u]=0; });
        wtFlushing=true;
        post('/ide/worktime', body.toString()).then(function(res){
            wtFlushing=false;
            if (!res || res.ok===false){ Object.keys(snapshot).forEach(function(u){ pendDelta[u]=(pendDelta[u]||0)+snapshot[u]; }); }
        });
    }
    document.querySelectorAll('.ide-tab').forEach(function(t){ workSecs[t.getAttribute('data-uuid')] = parseInt(t.getAttribute('data-work')||'0',10)||0; });
    renderWorktime();
    setInterval(function(){
        document.querySelectorAll('.ide-tab').forEach(function(t){
            var dot=t.querySelector('.dot');
            if (dot && dot.classList.contains('busy')){ // bolinha VERDE (working)
                var u=t.getAttribute('data-uuid');
                workSecs[u]=(workSecs[u]||0)+1;
                pendDelta[u]=(pendDelta[u]||0)+1;
            }
        });
        renderWorktime();
        flushWork();
    }, 1000);

    var framesEl = document.getElementById('ide-frames');
    function setActive(uuid){
        active = uuid;
        document.querySelectorAll('.ide-tab').forEach(function(t){ t.classList.toggle('active', t.getAttribute('data-uuid')===uuid); });
        document.querySelectorAll('#ide-frames .ide-pane').forEach(function(p){ p.classList.toggle('active', p.getAttribute('data-uuid')===uuid); });
        // No modo dividido, escolher a aba rola o pane daquele projeto para a área visível.
        if (framesEl.classList.contains('split')){
            var ap = framesEl.querySelector('.ide-pane.active');
            if (ap) ap.scrollIntoView({behavior:'smooth', inline:'nearest', block:'nearest'});
        }
        // O botão "voltar" leva à página do projeto da aba ATIVA (não ao dashboard).
        var home = document.getElementById('ide-home');
        if (home && uuid) home.setAttribute('href', '/projects/' + uuid);
        try { history.replaceState(null,'','/ide?active='+encodeURIComponent(uuid)); } catch(e){}
        renderWorktime();
    }

    // ── LAYOUT: 1 tela cheia (padrão) ou dividido entre os projetos abertos ─────
    // No dividido cada pane começa com 1/N da tela (mínimo 280px); as divisórias entre os panes
    // são arrastáveis e, quando a soma passa da tela, a barra de rolagem de baixo escolhe o trecho
    // visível. Clicar de novo no botão do modo dividido re-iguala os tamanhos.
    var layoutMode = 'single';
    function splitDefaultWidth(){
        var n = framesEl.querySelectorAll('.ide-pane').length || 1;
        return Math.max(280, Math.floor(framesEl.clientWidth / n));
    }
    function applyLayout(mode, recompute){
        var split = (mode === 'split');
        layoutMode = split ? 'split' : 'single';
        framesEl.classList.toggle('split', split);
        var b1 = document.getElementById('ide-layout-single'), b2 = document.getElementById('ide-layout-split');
        if (b1) b1.classList.toggle('on', !split);
        if (b2) b2.classList.toggle('on', split);
        var panes = framesEl.querySelectorAll('.ide-pane');
        if (split){
            var w = splitDefaultWidth();
            panes.forEach(function(p){ if (recompute || !p.style.width) p.style.width = w + 'px'; });
            var ap = framesEl.querySelector('.ide-pane.active');
            if (ap) ap.scrollIntoView({inline:'nearest', block:'nearest'});
        } else {
            panes.forEach(function(p){ p.style.width = ''; });
        }
        try{ localStorage.setItem('ide-layout', layoutMode); }catch(e){}
    }
    var lyS = document.getElementById('ide-layout-single'), lyG = document.getElementById('ide-layout-split');
    if (lyS) lyS.addEventListener('click', function(){ applyLayout('single'); });
    if (lyG) lyG.addEventListener('click', function(){ applyLayout('split', true); });
    // Arrasto das divisórias (pointer events cobrem mouse e toque).
    framesEl.addEventListener('pointerdown', function(e){
        var g = e.target.closest('.pane-grip');
        if (!g || !framesEl.classList.contains('split')) return;
        e.preventDefault();
        var pane = g.parentElement;
        var startX = e.clientX, startW = pane.getBoundingClientRect().width;
        g.classList.add('on');
        document.body.classList.add('ide-resizing');
        try{ g.setPointerCapture(e.pointerId); }catch(err){}
        function mv(ev){ pane.style.width = Math.max(280, Math.round(startW + (ev.clientX - startX))) + 'px'; }
        function up(){
            g.removeEventListener('pointermove', mv); g.removeEventListener('pointerup', up); g.removeEventListener('pointercancel', up);
            g.classList.remove('on');
            document.body.classList.remove('ide-resizing');
        }
        g.addEventListener('pointermove', mv);
        g.addEventListener('pointerup', up);
        g.addEventListener('pointercancel', up);
    });
    // Preferência lembrada entre visitas.
    try{ if (localStorage.getItem('ide-layout') === 'split' && framesEl.querySelector('.ide-pane')) applyLayout('split', true); else applyLayout('single'); }catch(e){ applyLayout('single'); }

    // Trocar de aba
    document.getElementById('ide-tabs').addEventListener('click', function(e){
        var x = e.target.closest('.x');
        var tab = e.target.closest('.ide-tab');
        if (!tab) return;
        var uuid = tab.getAttribute('data-uuid');
        if (x){ // fechar IDE
            e.stopPropagation();
            recentlyClosed[uuid] = Date.now();
            post('/projects/'+uuid+'/ide/stop','_=1');
            var pn = document.querySelector('#ide-frames .ide-pane[data-uuid="'+uuid+'"]'); if (pn) pn.remove();
            tab.remove();
            var rest = document.querySelectorAll('.ide-tab');
            if (!rest.length){ location.href='/projects/'+uuid; return; }
            if (uuid===active) setActive(rest[0].getAttribute('data-uuid'));
            return;
        }
        setActive(uuid);
    });

    // Botão "voltar": sempre vai ao projeto da IDE SELECIONADA no momento do clique.
    var homeLink = document.getElementById('ide-home');
    if (homeLink) homeLink.addEventListener('click', function(e){
        if (active){ e.preventDefault(); location.href = '/projects/' + active; }
    });

    // Alternar tema da IDE ativa (ao vivo) — herda o nome certo do tema no servidor.
    var themeBtn = document.getElementById('ide-theme');
    var ideTheme = (document.documentElement.dataset.theme==='light')?'light':'dark';
    if (themeBtn) themeBtn.addEventListener('click', function(){
        if (!active) return;
        ideTheme = (ideTheme==='dark')?'light':'dark';
        var b = new URLSearchParams(); b.set('theme', ideTheme);
        // também alterna o tema do TOPO (chrome) p/ acompanhar
        document.documentElement.setAttribute('data-theme', ideTheme);
        try{ localStorage.setItem('ravi-theme', ideTheme); }catch(e){}
        post('/projects/'+active+'/ide/theme', b.toString()).then(function(){
            var fr = document.querySelector('#ide-frames iframe[data-uuid="'+active+'"]');
            if (fr){ try{ fr.contentWindow.location.reload(); }catch(e){ fr.src=fr.src; } }
        });
    });

    // ── CONTA DO CLAUDE (engrenagem) ─────────────────────────────────────────
    // Submenu no hover com as contas disponíveis + barras de uso (mesma leitura dos cards do
    // perfil). Trocar salva na hora e mostra "Aplicando em 59s…": o reconcile repassa a conta
    // para as IDEs abertas em até 60 segundos, sem reiniciar nada.
    (function(){
        var wrap = document.getElementById('ide-lic-wrap');
        if (!wrap) return; // admin não liberou a escolha para este usuário
        var btn = document.getElementById('ide-lic-btn');
        var menu = document.getElementById('ide-lic-menu');
        var badge = document.getElementById('ide-lic-badge');
        var closeT = null, usageAt = 0, usageBusy = false, usageT = null, cdT = null, saving = false;

        function renderSubs(){
            menu.querySelectorAll('.js-ide-lic').forEach(function(card){
                var sub = card.querySelector('.js-lic-sub');
                if (!sub || card.classList.contains('applying')) return;
                var base = sub.getAttribute('data-sub') || '';
                sub.innerHTML = '';
                if (base) sub.appendChild(document.createTextNode(base));
                if (card.classList.contains('selected')){
                    var em = document.createElement('span'); em.className='lic-inuse'; em.textContent=(base?' · ':'')+'Em uso';
                    sub.appendChild(em);
                }
            });
        }
        function openMenu(){
            clearTimeout(closeT);
            wrap.classList.add('open');
            btn.setAttribute('aria-expanded','true');
            loadUsage();
            // Menu aberto continua VIVO: re-lê o uso a cada 30s (o servidor mescla a leitura de
            // passagem do proxy, então as barras acompanham o consumo real da conta em uso).
            if (!usageT) usageT = setInterval(loadUsage, 30000);
        }
        function closeMenu(){
            wrap.classList.remove('open');
            btn.setAttribute('aria-expanded','false');
            if (usageT){ clearInterval(usageT); usageT = null; }
        }
        wrap.addEventListener('mouseenter', openMenu);
        wrap.addEventListener('mouseleave', function(){ clearTimeout(closeT); closeT = setTimeout(closeMenu, 250); });
        btn.addEventListener('click', function(){ wrap.classList.contains('open') ? closeMenu() : openMenu(); }); // toque
        document.addEventListener('keydown', function(e){ if (e.key === 'Escape') closeMenu(); });

        // Uso do plano (uma vez por visita ao menu aberto; cache de 10 min no servidor)
        function esc(s){ return String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;'); }
        function lvl(u, status, limit){ if (status === 'rejected' || u >= .9 || (limit && Math.floor(u * 100) >= limit)) return 'bad'; if (status === 'allowed_warning' || u >= .75) return 'warn'; return 'ok'; }
        function eta(epoch){
            var s = epoch - Math.floor(Date.now()/1000);
            if (s <= 0) return 'renovando…';
            var d = Math.floor(s/86400), h = Math.floor((s%86400)/3600), m = Math.floor((s%3600)/60);
            if (d > 0) return 'renova em ' + d + 'd ' + h + 'h';
            if (h > 0) return 'renova em ' + h + 'h ' + (m < 10 ? '0' : '') + m + 'min';
            return 'renova em ' + Math.max(m,1) + ' min';
        }
        // Data ABSOLUTA da renovação semanal (linha embaixo das barras): "sáb 20/09 às 14:00".
        function fmtRenova(epoch){
            var d = new Date(epoch * 1000);
            var dias = ['dom','seg','ter','qua','qui','sex','sáb'];
            function p(n){ return (n < 10 ? '0' : '') + n; }
            return dias[d.getDay()] + ' ' + p(d.getDate()) + '/' + p(d.getMonth() + 1) + ' às ' + p(d.getHours()) + ':' + p(d.getMinutes());
        }
        function row(label, w, limit){
            var u = Math.max(0, Math.min(1, Number(w.utilization) || 0));
            var pct = Math.round(u * 100), k = lvl(u, w.status, limit);
            var tip = label + ': ' + pct + '% do limite usado' + (w.reset ? ' — ' + eta(Number(w.reset)) : '') + (w.status === 'rejected' ? ' (limite atingido)' : '');
            if (limit) tip += Math.floor(u * 100) >= limit ? ' — no teto de ' + limit + '% (pausado até renovar)' : ' — teto: ' + limit + '%';
            return '<span class="lc-row" title="' + esc(tip) + '">'
                 + '<span class="lc-label">' + label + '</span>'
                 + '<span class="lc-bar"><span class="lc-fill lc-' + k + '" style="width:' + Math.max(pct, u > 0 ? 2 : 0) + '%"></span>'
                 + (limit ? '<span class="lc-mark" style="left:' + limit + '%"></span>' : '') + '</span>'
                 + '<span class="lc-pct lc-pct-' + k + '">' + pct + '%</span></span>';
        }
        function loadUsage(){
            // Atualiza SEMPRE que o menu abre (com folga mínima de 20s entre buscas); as barras
            // já mostradas ficam no lugar até a resposta nova chegar (refresh silencioso).
            if (usageBusy || (Date.now() - usageAt) < 20000) return;
            usageBusy = true;
            fetch('/settings/licenses/usage', { credentials:'same-origin', headers:{'X-Requested-With':'XMLHttpRequest'} })
                .then(function(r){ return r.json(); })
                .then(function(j){
                    usageBusy = false; usageAt = Date.now();
                    var usage = (j && j.data && j.data.usage) || {};
                    menu.querySelectorAll('.js-lic-usage').forEach(function(cell){
                        var s = usage[cell.dataset.id];
                        if (!s || !s.ok) { cell.innerHTML = '<span class="lc-loading">uso indisponível</span>'; return; }
                        var html = '';
                        var lim = Number(s.limit) || 0; // teto do admin (só janelas do plano: 5h/semana)
                        if (s.five_hour) html += row('Sessão 5h', s.five_hour, lim);
                        if (s.seven_day) html += row('Semana', s.seven_day, lim);
                        if (s.fable) html += row('Fable', s.fable);
                        // Data em que o limite semanal renova (o reset da janela de 7 dias; a do
                        // Fable é a mesma janela e serve de reserva quando a "Semana" vier sem reset).
                        var wk = (s.seven_day && s.seven_day.reset) || (s.fable && s.fable.reset) || null;
                        if (html && wk) html += '<span class="lc-renew">Renova ' + fmtRenova(Number(wk)) + '</span>';
                        var atLim = false;
                        if (html && lim) {
                            atLim = (s.five_hour && Math.floor((Number(s.five_hour.utilization) || 0) * 100) >= lim)
                                 || (s.seven_day && Math.floor((Number(s.seven_day.utilization) || 0) * 100) >= lim);
                            html += '<span class="lc-limit' + (atLim ? ' lc-limit-hit' : '') + '">'
                                  + (atLim ? 'No teto de ' + lim + '% — pausado até a janela renovar' : 'Teto definido pelo administrador: ' + lim + '%')
                                  + '</span>';
                        }
                        cell.innerHTML = html || '<span class="lc-loading">uso indisponível</span>';
                        // Conta no teto não pode ser ESCOLHIDA (o clique ignora e o servidor recusa).
                        var card = cell.closest('.js-ide-lic');
                        if (card) card.classList.toggle('blocked', atLim);
                    });
                })
                .catch(function(){
                    usageBusy = false; // tenta de novo na próxima abertura
                    menu.querySelectorAll('.js-lic-usage').forEach(function(cell){
                        if (cell.querySelector('.lc-loading')) cell.innerHTML = '<span class="lc-loading">uso indisponível</span>';
                    });
                });
        }

        // Contagem regressiva "Aplicando em 59s…" no card escolhido + selo no botão da engrenagem
        // (o selo fica visível mesmo com o menu fechado). Ao zerar: ✓ verde por alguns segundos.
        function startCountdown(card){
            clearTimeout(cdT);
            menu.querySelectorAll('.js-ide-lic.applying').forEach(function(c){ c.classList.remove('applying'); });
            card.classList.add('applying');
            var sub = card.querySelector('.js-lic-sub');
            var left = 60;
            badge.hidden = false; badge.classList.remove('done');
            function tick(){
                if (left <= 0){
                    card.classList.remove('applying');
                    renderSubs();
                    badge.textContent = '✓'; badge.classList.add('done');
                    cdT = setTimeout(function(){ badge.hidden = true; badge.classList.remove('done'); }, 4000);
                    return;
                }
                if (sub) sub.innerHTML = '<span class="lic-applying">Aplicando em ' + left + 's…</span>';
                badge.textContent = left;
                left--; cdT = setTimeout(tick, 1000);
            }
            tick();
        }

        menu.querySelectorAll('.js-ide-lic').forEach(function(card){
            card.addEventListener('click', function(){
                if (saving || card.classList.contains('selected')) return;
                if (card.classList.contains('blocked')){
                    var sub = card.querySelector('.js-lic-sub');
                    if (sub){ sub.innerHTML = '<span class="lc-pct-bad">No teto de uso — indisponível até a janela renovar.</span>'; setTimeout(renderSubs, 3000); }
                    return;
                }
                saving = true;
                menu.classList.add('saving');
                var b = new URLSearchParams(); b.set('license_id', card.dataset.id);
                post('/settings/license', b.toString()).then(function(res){
                    saving = false;
                    menu.classList.remove('saving');
                    var ok = !!(res && res.success);
                    if (!ok){
                        var sub = card.querySelector('.js-lic-sub');
                        if (sub){ sub.innerHTML = '<span class="lc-pct-bad">Falha ao salvar — tente de novo.</span>'; setTimeout(renderSubs, 3000); }
                        return;
                    }
                    menu.querySelectorAll('.js-ide-lic').forEach(function(c){ c.classList.toggle('selected', c === card); });
                    renderSubs();
                    startCountdown(card);
                });
            });
        });
        renderSubs();
    })();

    // ── PRELOADER por IDE ────────────────────────────────────────────────────
    // Ao voltar de um standby (a IDE foi descongelada/religada pelo servidor ao abrir /ide), o
    // code-server pode levar um instante para servir HTTP. Em vez de carregar o iframe cedo demais
    // (tela branca / "Tentando reconectar" → refresh manual), os iframes começam SEM src; sondamos
    // /ide/ready e só damos src (carrega) quando o ambiente daquela IDE responde. O loader cobre até lá.
    var loaded = {}; // uuid => true quando o iframe terminou de carregar
    function markLoaded(uuid){
        loaded[uuid] = true;
        var ld = document.querySelector('#ide-frames .ide-loader[data-uuid="'+uuid+'"]');
        if (ld) ld.classList.add('done');
    }
    function attachFrameLoad(f){
        f.addEventListener('load', function(){
            if (!f.getAttribute('src')) return; // ignora o estado em branco (antes de dar src)
            markLoaded(f.getAttribute('data-uuid'));
        });
    }
    document.querySelectorAll('#ide-frames iframe').forEach(attachFrameLoad);
    function allLoaded(){
        var ok = true;
        document.querySelectorAll('#ide-frames iframe').forEach(function(f){ if(!loaded[f.getAttribute('data-uuid')]) ok=false; });
        return ok;
    }
    function pollReady(){
        fetch('/ide/ready',{headers:{'X-Requested-With':'XMLHttpRequest'},credentials:'same-origin'})
            .then(function(r){return r.json();}).then(function(j){
                var d=(j&&j.data)?j.data:j; var rd=(d&&d.ready)||{};
                document.querySelectorAll('#ide-frames iframe').forEach(function(f){
                    var u=f.getAttribute('data-uuid');
                    if (loaded[u] || f.getAttribute('src')) return; // já carregando/carregado
                    if (rd[u]) f.setAttribute('src', f.getAttribute('data-src')); // ambiente pronto → carrega
                });
            }).catch(function(){});
    }
    var pollerOn = false;
    function startPoller(){
        if (pollerOn) return; pollerOn = true; pollReady();
        var tries = 0;
        var t = setInterval(function(){
            if (allLoaded() || ++tries > 60){ clearInterval(t); pollerOn = false; return; } // teto ~72s
            pollReady();
        }, 1200);
    }
    // Recarrega uma IDE pelo preloader (loader + sonda + dá src quando pronta) — usado ao voltar do standby.
    function reloadFrame(uuid){
        var f = document.querySelector('#ide-frames iframe[data-uuid="'+uuid+'"]'); if (!f) return;
        f.removeAttribute('src'); loaded[uuid] = false;
        var ld = document.querySelector('#ide-frames .ide-loader[data-uuid="'+uuid+'"]'); if (ld) ld.classList.remove('done');
        startPoller();
    }
    if (document.querySelector('#ide-frames iframe')) startPoller();

    // ── BOTÃO "+" — abre outra IDE NESTA tela, sem refresh ───────────────────
    // Cria a aba + loader + iframe dinamicamente (o iframe entra sem src e o preloader dá o src
    // quando o ambiente responde). As IDEs já abertas não recarregam: nada de sessão perdida.
    var recentlyAdded = {};  // uuid => timestamp; protege a aba nova de um poll antigo (em voo) removê-la
    var recentlyClosed = {}; // uuid => timestamp; protege a aba fechada no X de um poll antigo (em voo) recriá-la
    function addTab(s, activate){
        if (activate === undefined) activate = true;
        var tabs = document.getElementById('ide-tabs');
        if (tabs.querySelector('.ide-tab[data-uuid="'+s.uuid+'"]')){ if (activate) setActive(s.uuid); return; }
        recentlyAdded[s.uuid] = Date.now();
        workSecs[s.uuid] = parseInt(s.work_seconds||0,10)||0;

        var tab = document.createElement('div');
        tab.className = 'ide-tab';
        tab.setAttribute('data-uuid', s.uuid);
        tab.setAttribute('data-work', String(workSecs[s.uuid]));
        var dot = document.createElement('span'); dot.className='dot';
        var nm = document.createElement('span'); nm.className='nm'; nm.textContent = s.name;
        var x = document.createElement('span'); x.className='x'; x.title='Fechar IDE'; x.innerHTML='&times;';
        tab.appendChild(dot); tab.appendChild(nm); tab.appendChild(x);
        tabs.insertBefore(tab, document.getElementById('ide-add'));

        var empty = document.getElementById('ide-empty'); if (empty) empty.remove();
        var pane = document.createElement('div');
        pane.className='ide-pane'; pane.setAttribute('data-uuid', s.uuid);
        var ld = document.createElement('div');
        ld.className='ide-loader'; ld.setAttribute('data-uuid', s.uuid);
        ld.innerHTML = '<div class="ide-spinner"></div><div class="ide-loader-txt">Preparando o ambiente…</div>';
        pane.appendChild(ld);
        var f = document.createElement('iframe');
        f.setAttribute('data-uuid', s.uuid);
        f.setAttribute('data-src', s.url);
        f.setAttribute('allow', 'clipboard-read; clipboard-write; fullscreen');
        attachFrameLoad(f);
        pane.appendChild(f);
        var grip = document.createElement('span');
        grip.className='pane-grip'; grip.title='Arraste para redimensionar';
        pane.appendChild(grip);
        framesEl.appendChild(pane);
        if (layoutMode === 'split') pane.style.width = splitDefaultWidth() + 'px';

        if (activate || !document.querySelector('.ide-tab.active')) setActive(s.uuid);
        startPoller();
    }

    var modalOv = document.getElementById('ide-modal-ov');
    var modalList = document.getElementById('ide-modal-list');
    var modalMsg = document.getElementById('ide-modal-msg');
    var modalSearch = document.getElementById('ide-modal-search');
    var modalBusy = false; // true enquanto uma IDE está sendo aberta (trava a lista)

    function setMsg(t){ modalMsg.textContent = t||''; modalMsg.style.display = t ? '' : 'none'; }
    function closeModal(){ if (modalBusy) return; modalOv.classList.remove('open'); }

    function renderProjects(projects){
        modalList.innerHTML = '';
        var filter = modalSearch.value.trim().toLowerCase();
        var shown = 0;
        projects.forEach(function(p){
            if (filter && p.name.toLowerCase().indexOf(filter) === -1) return;
            var open = !!document.querySelector('.ide-tab[data-uuid="'+p.uuid+'"]');
            var b = document.createElement('button');
            b.type='button'; b.className='ide-modal-item';
            var nm = document.createElement('span'); nm.className='pj-nm'; nm.textContent = p.name;
            b.appendChild(nm);
            if (open){
                b.disabled = true;
                var tg = document.createElement('span'); tg.className='pj-tag'; tg.textContent='aberta';
                b.appendChild(tg);
            } else {
                b.addEventListener('click', function(){ openProject(p, b); });
            }
            modalList.appendChild(b); shown++;
        });
        setMsg(shown ? '' : (filter ? 'Nenhum projeto encontrado.' : 'Nenhum projeto disponível.'));
    }

    var projectsCache = null;
    function openModal(){
        modalOv.classList.add('open');
        modalSearch.value=''; modalList.innerHTML=''; setMsg('Carregando projetos…');
        fetch('/ide/projects',{headers:{'X-Requested-With':'XMLHttpRequest'},credentials:'same-origin'})
            .then(function(r){return r.json();}).then(function(j){
                var d=(j&&j.data)?j.data:j;
                projectsCache = (d && d.projects) || [];
                setMsg(''); renderProjects(projectsCache);
                modalSearch.focus();
            }).catch(function(){ setMsg('Não foi possível carregar os projetos.'); });
    }

    function openProject(p, btn){
        if (modalBusy) return;
        modalBusy = true;
        btn.disabled = true;
        btn.querySelector('.pj-nm').textContent = 'Abrindo ' + p.name + '…';
        var body = new URLSearchParams(); body.set('theme', ideTheme);
        post('/projects/'+p.uuid+'/ide/start', body.toString()).then(function(resp){
            var d = (resp && resp.data) ? resp.data : resp;
            if (!d || !d.ok){
                modalBusy = false;
                setMsg((d && d.error) || 'Falha ao abrir a IDE.');
                renderProjects(projectsCache||[]);
                return;
            }
            // Busca a sessão recém-criada (nome, url, tempo trabalhado) e cria a aba ao vivo.
            fetch('/ide/sessions',{headers:{'X-Requested-With':'XMLHttpRequest'},credentials:'same-origin'})
                .then(function(r){return r.json();}).then(function(j){
                    var sd=(j&&j.data)?j.data:j;
                    var s=((sd&&sd.sessions)||[]).filter(function(v){return v.uuid===p.uuid;})[0];
                    modalBusy = false;
                    if (!s){ s = { uuid:p.uuid, name:p.name, url:d.url, work_seconds:0 }; }
                    modalOv.classList.remove('open');
                    addTab(s);
                }).catch(function(){
                    modalBusy = false;
                    modalOv.classList.remove('open');
                    addTab({ uuid:p.uuid, name:p.name, url:d.url, work_seconds:0 });
                });
        });
    }

    document.getElementById('ide-add').addEventListener('click', openModal);
    document.getElementById('ide-modal-close').addEventListener('click', closeModal);
    modalOv.addEventListener('click', function(e){ if (e.target === modalOv) closeModal(); });
    document.addEventListener('keydown', function(e){ if (e.key === 'Escape' && modalOv.classList.contains('open')) closeModal(); });
    modalSearch.addEventListener('input', function(){ if (projectsCache) renderProjects(projectsCache); });

    // Atualiza as bolinhas verdes (processando), remove abas que sumiram e RECOMPÕE as que faltam.
    // Só roda com a página VISÍVEL: o pedido leva visible=1, e o servidor conta isso como USO de todas
    // as IDEs do usuário (adia o standby) e religa as que já estavam pausadas. Antes, com a página
    // aberta, as abas sem Claude rodando iam para standby após 2 h e o poll as apagava — só um
    // refresh trazia de volta. Em segundo plano nada é removido; ao voltar, o visibilitychange resolve.
    function poll(){
        if (document.hidden) return;
        fetch('/ide/sessions?visible=1',{headers:{'X-Requested-With':'XMLHttpRequest'},credentials:'same-origin'})
            .then(function(r){return r.json();}).then(function(j){
                var d=(j&&j.data)?j.data:j; if(!d||!d.sessions) return;
                var byUuid={}; d.sessions.forEach(function(s){ byUuid[s.uuid]=s; });
                document.querySelectorAll('.ide-tab').forEach(function(t){
                    var u=t.getAttribute('data-uuid'); var s=byUuid[u];
                    if(!s){ // sumiu (parada/expirada)
                        // Aba recém-aberta pelo "+": uma resposta antiga (disparada antes do start
                        // concluir) pode não trazê-la ainda — não remove nesse curto intervalo.
                        if (recentlyAdded[u] && (Date.now() - recentlyAdded[u]) < 15000) return;
                        var pn=document.querySelector('#ide-frames .ide-pane[data-uuid="'+u+'"]'); if(pn) pn.remove();
                        t.remove();
                        // Era a aba ATIVA: seleciona a primeira que sobrou (como no fechar pelo X).
                        if (u===active){ var rest=document.querySelector('.ide-tab'); if (rest) setActive(rest.getAttribute('data-uuid')); }
                        return;
                    }
                    var dot=t.querySelector('.dot');
                    if(dot){
                        var st=s.state||(s.busy?'working':'idle');
                        dot.classList.toggle('busy', st==='working');
                        dot.classList.toggle('waiting', st==='waiting');
                        dot.title = st==='waiting'?'Aguardando sua resposta':(st==='working'?'Processando':'');
                    }
                });
                // IDE do usuário SEM aba aqui (religada do standby, ou aberta por outra janela):
                // recria a aba sem roubar o foco. Aba fechada no X há pouco fica de fora (o stop
                // pode ainda não ter chegado ao banco quando este pedido saiu).
                d.sessions.forEach(function(s){
                    if (document.querySelector('.ide-tab[data-uuid="'+s.uuid+'"]')) return;
                    if (recentlyClosed[s.uuid] && (Date.now() - recentlyClosed[s.uuid]) < 15000) return;
                    addTab(s, false);
                });
            }).catch(function(){});
    }
    setInterval(poll, 4000);

    // RECUPERAÇÃO DE CONEXÃO: o navegador congela a aba em 2º plano e o code-server perde o
    // WebSocket; ao voltar ele às vezes não reconecta sozinho (mostra "Tentando reconectar…")
    // e o usuário precisava dar refresh. Como o iframe é MESMA-ORIGEM, ao voltar pra aba nós
    // checamos se esse diálogo está aberto na IDE ativa e, se estiver, recarregamos SÓ esse
    // iframe (o code-server restaura abas/terminais; a extensão reconecta ao processo do Claude,
    // que segue vivo no container). Recarrega apenas quando REALMENTE detecta a desconexão.
    var hiddenAt = null, checking = false;
    function activeFrame(){ return document.querySelector('#ide-frames iframe[data-uuid="'+active+'"]'); }
    function reconnectFrame(fr){
        if (!fr) return;
        var broken = false;
        try {
            var doc = fr.contentDocument || (fr.contentWindow && fr.contentWindow.document);
            var t = (doc && doc.body && doc.body.innerText) || '';
            // diálogo de reconexão do VS Code: PT "Tentando reconectar em N segundos" / EN equivalente.
            if (/recon/i.test(t) && /\d+\s*(segundo|second)/i.test(t)) broken = true;
        } catch(e){ /* mesma-origem não deve lançar; se lançar, não força reload (evita loop) */ }
        if (broken){ try { fr.contentWindow.location.reload(); } catch(e){ fr.src = fr.src; } }
    }
    function reconnectIfNeeded(){
        // No modo dividido TODOS os panes estão visíveis: verifica cada um, não só o ativo.
        if (framesEl.classList.contains('split')){
            document.querySelectorAll('#ide-frames iframe').forEach(reconnectFrame);
            return;
        }
        reconnectFrame(activeFrame());
    }
    document.addEventListener('visibilitychange', function(){
        if (document.hidden){ hiddenAt = Date.now(); return; }
        if (hiddenAt === null || checking) return;
        var away = Date.now() - hiddenAt; hiddenAt = null;
        if (away < 15000) return;             // ausência curta: a conexão costuma sobreviver
        checking = true;
        // Você voltou para a aba: tira do STANDBY o que pausou enquanto esteve fora e recarrega SÓ
        // essas IDEs pelo preloader (loader até o ambiente responder). Para uma IDE que seguiu
        // rodando mas perdeu o WebSocket, cai no reconnect nativo.
        post('/ide/resume','_=1').then(function(resp){
            var d = (resp && resp.data) ? resp.data : resp;
            var resumed = (d && d.resumed) || [];
            resumed.forEach(reloadFrame);
            poll(); // recompõe abas que faltem e volta a marcar uso (o poll não roda em segundo plano)
            setTimeout(function(){ checking = false; if (resumed.indexOf(active) === -1) reconnectIfNeeded(); }, 2500);
        });
    });
})();
</script>
</body>
</html>
