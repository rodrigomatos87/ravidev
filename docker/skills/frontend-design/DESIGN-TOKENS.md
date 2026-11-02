# Design Tokens — um sistema, não valores soltos

Use tokens como fonte única de verdade. Abaixo, um sistema agnóstico (CSS variables) que você
pode copiar e adaptar. Em projetos Tailwind v4, exponha-os via `@theme`. Em projetos sem build,
declare em `:root`.

## Cor (OKLCH — perceptualmente uniforme)

Derive a paleta de uma ÚNICA matiz de marca e construa neutros + acentos a partir dela.

```css
:root {
  /* Matiz de marca: troque o ângulo (0–360) para reposicionar toda a identidade */
  --brand-hue: 265;

  /* Marca */
  --color-primary:        oklch(0.62 0.19 var(--brand-hue));
  --color-primary-hover:  oklch(0.56 0.19 var(--brand-hue));
  --color-accent:         oklch(0.72 0.17 calc(var(--brand-hue) + 150)); /* acento complementar */

  /* Neutros (cinzas com leve temperatura da marca, não cinza morto) */
  --color-bg:        oklch(0.99 0.004 var(--brand-hue));
  --color-surface:   oklch(0.97 0.006 var(--brand-hue));
  --color-border:    oklch(0.90 0.008 var(--brand-hue));
  --color-text:      oklch(0.25 0.01  var(--brand-hue));
  --color-text-muted:oklch(0.52 0.01  var(--brand-hue));

  /* Semânticos */
  --color-success: oklch(0.70 0.16 150);
  --color-warning: oklch(0.80 0.15 80);
  --color-danger:  oklch(0.62 0.20 25);
}

/* Tema escuro: inverta luminância, mantenha a matiz */
@media (prefers-color-scheme: dark) {
  :root {
    --color-bg:        oklch(0.17 0.01 var(--brand-hue));
    --color-surface:   oklch(0.21 0.012 var(--brand-hue));
    --color-border:    oklch(0.30 0.012 var(--brand-hue));
    --color-text:      oklch(0.95 0.006 var(--brand-hue));
    --color-text-muted:oklch(0.70 0.01  var(--brand-hue));
  }
}
```

Regra de contraste: texto sobre fundo precisa de **≥ 4.5:1** (AA). Teste antes de fechar.

## Tipografia (escala modular)

Escolha uma razão (1.200 minor third para denso, 1.333 perfect fourth para editorial).

```css
:root {
  --font-display: 'Newsreader', 'Fraunces', Georgia, serif;     /* ex.: editorial */
  --font-body:    'Geist', system-ui, -apple-system, sans-serif;/* corpo legível  */
  --font-mono:    'Geist Mono', ui-monospace, monospace;

  --text-xs:   0.79rem;
  --text-sm:   0.889rem;
  --text-base: 1rem;
  --text-lg:   1.125rem;
  --text-xl:   1.333rem;
  --text-2xl:  1.777rem;
  --text-3xl:  2.369rem;
  --text-4xl:  3.157rem;

  --leading-tight: 1.15;
  --leading-body:  1.6;
  --tracking-tight:-0.02em; /* títulos grandes */
}
```

Diretrizes: corpo 16px+; medida de leitura 60–75ch; títulos grandes com `letter-spacing` negativo leve.

## Espaçamento (grade base 4px)

```css
:root {
  --space-1: 0.25rem; --space-2: 0.5rem;  --space-3: 0.75rem; --space-4: 1rem;
  --space-6: 1.5rem;  --space-8: 2rem;    --space-12: 3rem;   --space-16: 4rem;
  --space-24: 6rem;
}
```

Todo padding/margin/gap sai daqui. Nada de `13px` aleatório.

## Raio, sombra e foco

```css
:root {
  --radius-sm: 6px; --radius-md: 10px; --radius-lg: 16px; --radius-full: 999px;

  /* Sombras em camadas (suaves), não a sombra dura padrão */
  --shadow-sm: 0 1px 2px oklch(0 0 0 / 0.06);
  --shadow-md: 0 4px 12px oklch(0 0 0 / 0.08), 0 1px 3px oklch(0 0 0 / 0.06);
  --shadow-lg: 0 12px 32px oklch(0 0 0 / 0.12), 0 2px 8px oklch(0 0 0 / 0.06);

  --focus-ring: 0 0 0 3px oklch(0.62 0.19 var(--brand-hue) / 0.4);
}

:focus-visible { outline: none; box-shadow: var(--focus-ring); }
```
