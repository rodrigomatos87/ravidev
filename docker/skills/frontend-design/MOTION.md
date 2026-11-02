# Movimento — com propósito, nunca enfeite

Animação serve para **revelar, orientar e dar feedback**. Se não faz nada disso, não anime.

## Durações e easing
- Microinterações (hover, toggle, foco): **120–200ms**.
- Transições de elemento (entrada de card, abertura de menu): **180–280ms**.
- Transições de tela maiores: **280–400ms** — raramente mais.
- Easing natural: `cubic-bezier(0.4, 0, 0.2, 1)` (padrão), saída mais rápida que entrada.
- Evite `linear` (robótico) e durações longas (parece lento/pesado).

## Padrões úteis
- **Entrada suave** de conteúdo: opacidade 0→1 + leve translateY (8–12px).
- **Reveal escalonado** (stagger) de listas: 30–60ms entre itens — dá ritmo sem atrasar.
- **Feedback de ação**: botão responde no hover/active; estados de carregamento com skeleton/spinner discreto.
- **Hover com personalidade**: um detalhe inesperado e sutil marca identidade — sem exagero.

## Sempre respeite a preferência do usuário

```css
@media (prefers-reduced-motion: reduce) {
  *, *::before, *::after {
    animation-duration: 0.01ms !important;
    animation-iteration-count: 1 !important;
    transition-duration: 0.01ms !important;
    scroll-behavior: auto !important;
  }
}
```

## Exemplo (CSS puro)

```css
.card {
  opacity: 0;
  transform: translateY(10px);
  animation: rise 240ms cubic-bezier(0.4, 0, 0.2, 1) forwards;
}
@keyframes rise { to { opacity: 1; transform: none; } }

.btn { transition: background-color 160ms ease, transform 120ms ease; }
.btn:active { transform: translateY(1px); }
```

Em React/Vue, use a lib de motion já presente no projeto (ex.: Motion/Framer) com as mesmas durações e curvas.
