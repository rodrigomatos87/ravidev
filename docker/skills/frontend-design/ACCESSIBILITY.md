# Acessibilidade — checklist prático (WCAG 2.1 AA)

Acessibilidade não é etapa final; projete com ela. Mínimo obrigatório:

## Estrutura
- [ ] HTML **semântico**: `header`, `nav`, `main`, `footer`, `button` para ações, `a` para navegação.
- [ ] Um único `h1` por página; hierarquia de headings sem pular níveis.
- [ ] Landmarks e `label`/`aria-label` em controles sem texto visível.

## Cor e contraste
- [ ] Texto normal **≥ 4.5:1**; texto grande (≥ 24px ou 19px bold) **≥ 3:1**.
- [ ] Não comunique informação **só** por cor (use ícone/texto também).
- [ ] Estados de erro/sucesso têm texto, não apenas borda colorida.

## Teclado
- [ ] Tudo operável por teclado (Tab/Shift+Tab/Enter/Espaço/Esc).
- [ ] **Foco visível** sempre (`:focus-visible` com anel claro); nunca `outline: none` sem substituto.
- [ ] Ordem de foco lógica; sem armadilhas de foco; modais retornam o foco ao fechar.

## Alvos e toque
- [ ] Alvos de toque **≥ 44×44px** com espaçamento suficiente.

## Imagens e mídia
- [ ] `alt` descritivo em imagens informativas; `alt=""` em decorativas.
- [ ] Legendas/transcrição para áudio/vídeo quando houver.

## Movimento
- [ ] Respeite `prefers-reduced-motion: reduce` (reduza/desligue animações não essenciais).

## Formulários
- [ ] Todo input tem `label` associado; mensagens de erro ligadas via `aria-describedby`.
- [ ] Validação clara, com texto explicando como corrigir.

## Verificação rápida
- Navegue a tela inteira **só com teclado**.
- Rode um checker de contraste nos principais pares texto/fundo.
- Teste com zoom 200% sem quebrar layout.
