<?php
/** Página standalone: mostra o app (export web do Expo) em duas molduras navegáveis. */
$app = e($appUrl);
?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Preview Mobile — Ravi Developer</title>
<style>
  :root { --bg:#0b0b0f; --panel:#15151c; --line:#2a2a35; --text:#e8e8ee; --muted:#9a9aa8; --accent:#7c6cff; }
  * { box-sizing: border-box; }
  html, body { margin:0; height:100%; }
  body { background: radial-gradient(1200px 600px at 50% -10%, #1a1a26, var(--bg)); color: var(--text);
         font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
  header { display:flex; align-items:center; gap:.75rem; padding:.8rem 1.2rem; border-bottom:1px solid var(--line); }
  header .dot { width:10px; height:10px; border-radius:50%; background:#22c55e; box-shadow:0 0 8px #22c55e; }
  header h1 { font-size:1rem; font-weight:600; margin:0; }
  header .spacer { flex:1; }
  header a { color: var(--muted); font-size:.85rem; text-decoration:none; border:1px solid var(--line);
             padding:.35rem .7rem; border-radius:8px; }
  header a:hover { color: var(--text); border-color: var(--accent); }
  .stage { display:flex; gap:2.5rem; justify-content:center; align-items:flex-start;
           flex-wrap:wrap; padding:2rem 1rem 3rem; }
  .device { display:flex; flex-direction:column; align-items:center; gap:.7rem; }
  .device .label { font-size:.85rem; color:var(--muted); letter-spacing:.02em; }
  /* Moldura genérica */
  .phone { position:relative; background:#000; box-shadow:0 20px 60px rgba(0,0,0,.5); }
  .phone iframe { width:100%; height:100%; border:0; background:#fff; display:block; }
  /* iPhone: cantos bem arredondados + notch */
  .iphone { width:390px; height:844px; border-radius:46px; padding:14px; border:2px solid #2c2c34; }
  .iphone iframe { border-radius:32px; }
  .iphone .notch { position:absolute; top:14px; left:50%; transform:translateX(-50%);
                   width:150px; height:26px; background:#000; border-radius:0 0 16px 16px; z-index:2; }
  /* Android: cantos menos arredondados + furo da câmera */
  .android { width:360px; height:800px; border-radius:30px; padding:10px; border:2px solid #2c2c34; }
  .android iframe { border-radius:22px; }
  .android .punch { position:absolute; top:20px; left:50%; transform:translateX(-50%);
                    width:12px; height:12px; background:#000; border-radius:50%; z-index:2; outline:2px solid #1a1a1a; }
  @media (max-width: 880px) { .iphone, .android { transform: scale(.82); transform-origin: top center; } }
</style>
</head>
<body>
  <header>
    <span class="dot"></span>
    <h1>Preview do app — Android e iPhone</h1>
    <span class="spacer"></span>
    <a href="<?= $app ?>" target="_blank" rel="noopener">Abrir só o app ↗</a>
  </header>

  <div class="stage">
    <div class="device">
      <div class="phone android">
        <span class="punch"></span>
        <iframe src="<?= $app ?>" title="Preview Android" loading="eager"></iframe>
      </div>
      <div class="label">Android</div>
    </div>

    <div class="device">
      <div class="phone iphone">
        <span class="notch"></span>
        <iframe src="<?= $app ?>" title="Preview iPhone" loading="eager"></iframe>
      </div>
      <div class="label">iPhone</div>
    </div>
  </div>
</body>
</html>
