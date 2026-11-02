---
name: frontend-design
description: >-
  Guia de design para construir interfaces web de qualidade de produto — nível Stripe, Apple,
  Linear, Vercel — em vez de telas genéricas. Use SEMPRE que for criar ou revisar qualquer UI
  (HTML/CSS/JS, React, Vue, etc.). Cobre direção estética, tipografia, cor, espaçamento, layout,
  movimento e acessibilidade.
---

# Frontend Design — interfaces que parecem feitas por gente que se importa

Você está construindo uma interface real, não um rascunho. O padrão é **qualidade de produto**:
algo que poderia estar no ar na Stripe, Apple, Linear ou Vercel. A maioria das UIs geradas por IA
falha por serem **genéricas** — fundo branco, gradiente roxo, `Inter`, cantos arredondados padrão,
tudo centralizado, zero personalidade. Recuse isso.

> Este guia destila princípios de fontes abertas (Anthropic `frontend-design`, Apache-2.0;
> e práticas de design systems públicos). Adapte ao stack do projeto; os princípios são agnósticos.

## Proibições (invioláveis)

- **NUNCA** use `alert()`, `confirm()` ou `prompt()` do navegador. São bloqueantes, feios e ficam
  fora do design system. Para avisos, confirmações e mensagens use SEMPRE componentes próprios:
  um **modal/dialog acessível** (`role="dialog"`, `aria-modal="true"`, foco preso, fecha no `Esc` e
  no clique no backdrop) e **toasts/mensagens inline** para feedback.
- **NUNCA** use caminhos absolutos a partir da raiz (`/...`) para assets, links, `action` de
  formulário ou requisições. Use caminhos **relativos** e seja **base-path-aware** — a app pode ser
  servida sob um subdiretório (ex.: um preview em `/p/<token>/`), e URLs absolutas quebram isso.

## Processo (não pule)

1. **Propósito** — o que esta tela faz e para quem? Qual a UMA ação mais importante?
2. **Tom** — escolha uma direção estética explícita e comprometa-se com ela antes de codar
   (ex.: "editorial sóbrio e tipográfico", "técnico denso à la Linear", "minimalismo cálido à la Apple").
   Direção morna = resultado genérico. Decida e seja consistente.
3. **Restrições** — stack, marca, dados, dispositivos. Trabalhe com elas, não contra.
4. **Diferenciação** — escolha 1–2 detalhes memoráveis (uma tipografia distinta, um uso de cor
   ousado, uma microinteração) que dão identidade. Sem isso, vira mais uma tela.

## Regras por eixo

### Tipografia
- **Banido como default preguiçoso:** Arial, Helvetica padrão, e o `Inter` em tudo. Se usar uma
  sans neutra, justifique e pareie com um display distinto.
- Pareie um **display** com caráter (títulos) com uma **body** legível (texto). Use no máximo 2 famílias.
- Estabeleça uma **escala modular** (ex.: 1.250 ou 1.333) — tamanhos não são aleatórios.
- Cuide de `line-height` (corpo ~1.5–1.65; títulos ~1.05–1.2), `letter-spacing` em títulos grandes
  (levemente negativo) e largura de medida de leitura (~60–75 caracteres).

### Cor
- Defina cores como **variáveis CSS** / tokens, nunca hex espalhado. Um sistema, não improviso.
- Uma cor **dominante** + acentos **nítidos e escassos**. Evite arco-íris. Evite o clichê
  "gradiente roxo sobre branco".
- Garanta **contraste WCAG AA** (texto normal ≥ 4.5:1; grande ≥ 3:1). Suporte tema claro e escuro
  quando fizer sentido. Prefira espaços perceptualmente uniformes (OKLCH) para derivar a paleta.
- Veja `DESIGN-TOKENS.md` para um sistema de tokens pronto (cor/tipografia/espaçamento).

### Espaçamento e layout
- Use uma **grade de espaçamento** consistente (base 4px ou 8px). Espaço em branco é design, não desperdício.
- Fuja do "tudo empilhado e centralizado". Explore **assimetria, hierarquia, agrupamento e ritmo**.
- Alinhe a uma grade; quebre-a de propósito quando for criar foco. Componentes respiram.

### Movimento
- Movimento com **propósito**: revelar, orientar, dar feedback — nunca enfeite gratuito.
- HTML/CSS puro: transições e `@keyframes` discretas (entradas suaves, hovers que respondem).
  React/Vue: use uma lib de motion quando já estiver no projeto.
- Curvas de easing naturais, durações curtas (120–280ms para microinterações). Respeite
  `prefers-reduced-motion`. Detalhes em `MOTION.md`.

### Detalhes visuais
- Sombras suaves e em camadas (não a sombra padrão dura), bordas sutis, estados de foco visíveis,
  estados vazios e de carregamento cuidados, ícones consistentes. O acabamento está nos detalhes.

## Acessibilidade (não opcional)
- HTML semântico, foco visível, navegação por teclado, `alt`/labels, contraste AA, alvos de toque
  ≥ 44px. Checklist em `ACCESSIBILITY.md`.

## Antes de considerar pronto
- A tela tem **identidade** (não passaria por "template genérico")?
- Tipografia, cor e espaçamento seguem **um sistema** (tokens), não valores soltos?
- Passa em **contraste AA** e navegação por teclado?
- Estados de **hover, foco, vazio, carregando e erro** existem?
- Responsivo de verdade (mobile → desktop), sem quebrar?

## Arquivos de referência
- `DESIGN-TOKENS.md` — sistema de tokens (OKLCH, escalas de tipografia e espaçamento, claro/escuro).
- `BRAND-AESTHETICS.md` — o que torna Stripe/Apple/Linear/Vercel reconhecíveis, para inspirar a direção.
- `ACCESSIBILITY.md` — checklist WCAG prático.
- `MOTION.md` — durações, easing e padrões de animação com `prefers-reduced-motion`.
