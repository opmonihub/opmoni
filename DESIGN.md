---
name: Opmoni
description: "Mesa de trabalho contábil: organizada, sóbria e precisa."
colors:
  primary-light: "#016538"
  primary-dark: "#00DC82"
  success-light: "#016538"
  success-dark: "#00DC82"
  warning-light: "oklch(47.6% 0.114 61.907)"
  warning-dark: "oklch(85.2% 0.199 91.936)"
  error-light: "oklch(63.7% 0.237 25.331)"
  error-dark: "oklch(70.4% 0.191 22.216)"
  info-light: "oklch(62.3% 0.214 259.815)"
  info-dark: "oklch(70.7% 0.165 254.624)"
  neutral-bg-light: "#ffffff"
  neutral-bg-dark: "oklch(21% 0.006 285.885)"
  neutral-bg-muted-light: "oklch(98.5% 0 none)"
  neutral-bg-muted-dark: "oklch(27.4% 0.006 286.033)"
  neutral-bg-elevated-light: "oklch(96.7% 0.001 286.375)"
  neutral-bg-elevated-dark: "oklch(27.4% 0.006 286.033)"
  neutral-bg-accented-light: "oklch(92% 0.004 286.32)"
  neutral-bg-accented-dark: "oklch(37% 0.013 285.805)"
  neutral-text-light: "oklch(37% 0.013 285.805)"
  neutral-text-dark: "oklch(92% 0.004 286.32)"
  neutral-text-muted-light: "oklch(44.2% 0.017 285.786)"
  neutral-text-muted-dark: "oklch(70.5% 0.015 286.067)"
  neutral-text-highlighted-light: "oklch(21% 0.006 285.885)"
  neutral-text-highlighted-dark: "#ffffff"
  neutral-border-light: "oklch(92% 0.004 286.32)"
  neutral-border-dark: "oklch(27.4% 0.006 286.033)"
  neutral-border-accented-light: "oklch(87.1% 0.006 286.286)"
  neutral-border-accented-dark: "oklch(37% 0.013 285.805)"
typography:
  title:
    fontFamily: "Public Sans, sans-serif"
    fontSize: "16px"
    fontWeight: 600
    lineHeight: "24px"
  body:
    fontFamily: "Public Sans, sans-serif"
    fontSize: "16px"
    fontWeight: 400
    lineHeight: "24px"
  body-small:
    fontFamily: "Public Sans, sans-serif"
    fontSize: "14px"
    fontWeight: 400
    lineHeight: "20px"
  description:
    fontFamily: "Public Sans, sans-serif"
    fontSize: "15px"
    fontWeight: 400
  label:
    fontFamily: "Public Sans, sans-serif"
    fontSize: "14px"
    fontWeight: 500
    lineHeight: "20px"
  label-small:
    fontFamily: "Public Sans, sans-serif"
    fontSize: "12px"
    fontWeight: 500
    lineHeight: "16px"
  label-micro:
    fontFamily: "Public Sans, sans-serif"
    fontSize: "10px"
    fontWeight: 500
    lineHeight: "12px"
  input-mobile:
    fontFamily: "Public Sans, sans-serif"
    fontSize: "16px"
    fontWeight: 400
    lineHeight: "20px"
  input-desktop:
    fontFamily: "Public Sans, sans-serif"
    fontSize: "14px"
    fontWeight: 400
    lineHeight: "20px"
  table-header:
    fontFamily: "Public Sans, sans-serif"
    fontSize: "12px"
    fontWeight: 600
    lineHeight: "16px"
rounded:
  sm: "4px"
  md: "6px"
  lg: "8px"
spacing:
  "1": "4px"
  "1.5": "6px"
  "2": "8px"
  "3": "12px"
  "4": "16px"
  "5": "20px"
  "6": "24px"
  "8": "32px"
components:
  button-neutral-solid:
    backgroundColor: "var(--ui-bg-inverted)"
    textColor: "var(--ui-text-inverted)"
    typography: "{typography.label}"
    rounded: "{rounded.md}"
    padding: "6px 10px"
  button-neutral-outline:
    backgroundColor: "var(--ui-bg)"
    textColor: "var(--ui-text)"
    typography: "{typography.label}"
    rounded: "{rounded.md}"
    padding: "6px 10px"
  button-neutral-ghost:
    backgroundColor: "transparent"
    textColor: "var(--ui-text)"
    typography: "{typography.label}"
    rounded: "{rounded.md}"
    padding: "6px 10px"
  button-primary-outline:
    backgroundColor: "transparent"
    textColor: "var(--ui-primary)"
    typography: "{typography.label}"
    rounded: "{rounded.md}"
    padding: "6px 10px"
  button-primary-solid:
    backgroundColor: "var(--ui-primary)"
    textColor: "var(--ui-text-inverted)"
    typography: "{typography.label}"
    rounded: "{rounded.md}"
    padding: "6px 10px"
  input-outline:
    backgroundColor: "var(--ui-bg)"
    textColor: "var(--ui-text-highlighted)"
    typography: "{typography.input-mobile}"
    rounded: "{rounded.md}"
    padding: "6px 10px"
  badge-success-soft:
    backgroundColor: "color-mix(in oklab, var(--ui-success) 10%, transparent)"
    textColor: "var(--ui-success)"
    typography: "{typography.label-small}"
    rounded: "{rounded.md}"
    padding: "4px 8px"
  navigation-link:
    backgroundColor: "transparent"
    textColor: "var(--ui-text-muted)"
    typography: "{typography.label}"
    rounded: "{rounded.md}"
    padding: "6px 10px"
  panel-list:
    backgroundColor: "color-mix(in oklab, var(--ui-bg-elevated) 50%, transparent)"
    textColor: "var(--ui-text)"
    typography: "{typography.body-small}"
    rounded: "{rounded.lg}"
    padding: "16px"
  sheet-table:
    backgroundColor: "transparent"
    textColor: "var(--ui-text-muted)"
    typography: "{typography.body-small}"
    padding: "6px 12px"
  modal-content:
    backgroundColor: "var(--ui-bg)"
    textColor: "var(--ui-text)"
    typography: "{typography.body}"
    rounded: "{rounded.lg}"
    padding: "16px"
---

# Design System: Opmoni

## Overview

**Creative North Star: "Mesa de trabalho contábil"**

A Mesa de trabalho contábil é organizada, sóbria e precisa; informação e ações vêm antes da decoração. A interface favorece leitura operacional, orientação pelo contexto e ações reconhecíveis, com voz direta, clara e profissional em português brasileiro.

Discretos e precisos: controles familiares do Nuxt UI, arredondamento moderado e hierarquia por peso visual. A identidade parte do template `.dashboard` e preserva as adaptações atuais do Opmoni; este registro descreve o sistema existente, sem redesenhar telas nem transformar exceções locais em regras universais.

**Key Characteristics:**
- Organização sóbria e precisa para o trabalho contábil.
- Public Sans e controles familiares do Nuxt UI.
- Verde e neutros Zinc, com papéis semânticos nos dois temas.
- Profundidade funcional e densidade adequada à tarefa.

**Proveniência e autoridade.** Extração do checkout e de estilos calculados em 29/10/2026. A referência local `.dashboard/package.json` declara Nuxt UI `^4.11.3` e Nuxt `^4.6.0`, assim como `frontend/package.json`; a versão instalada de Nuxt UI em `frontend/node_modules/@nuxt/ui/package.json` é `4.11.3`. `.dashboard/app/app.config.ts` e `.dashboard/app/assets/css/main.css` confirmam a paleta e a fonte de origem. No aplicativo, a ordem de autoridade é configuração local do componente → `frontend/app/app.config.ts` → temas instalados em `frontend/.nuxt/ui/`. As páginas oficiais de [Button](https://ui.nuxt.com/docs/components/button), [Input](https://ui.nuxt.com/docs/components/input), [PageCard](https://ui.nuxt.com/docs/components/page-card), [Modal](https://ui.nuxt.com/docs/components/modal) e [CSS Variables](https://ui.nuxt.com/docs/getting-started/theme/css-variables) foram consultadas pelo MCP oficial `user-nuxt-ui`; documentação corrente, inclusive novidades 4.12, não comprova comportamento disponível na versão local.

O frontmatter registra os valores extraídos; os componentes usam variáveis semânticas para acompanhar o tema. `.impeccable/design.json` acrescenta rampas existentes, movimento, sombras, breakpoints e amostras visuais independentes de framework. Não substitui os componentes Vue nem seus comportamentos de negócio.

## Colors

A nomenclatura segue os papéis do template: `primary`, `neutral`, `success`, `warning`, `error` e `info`, sem novos nomes de fantasia ou novas cores de marca.

### Primary

O verde é o mesmo da referência `.dashboard`: toda a escala de onze tons, de 50 a 950, está em ambos os arquivos `app/assets/css/main.css`. `primary-light` e `primary-dark` registram o papel efetivo em cada tema; a rampa completa permanece no sidecar. O verde identifica destaques, navegação ativa e variantes explicitamente escolhidas. Não há evidência para inventar uma identidade secundária ou terciária.

**Diferenças reais em relação ao template.** A referência configura `green` e `zinc`, sem redefinir papéis claros ou defaults de botão. O Opmoni conserva essa base e acrescenta:
- No tema claro, `primary`, `success` e `warning` usam o tom 800 em vez do 500 padrão da biblioteca; `text-muted` e `text-dimmed` usam neutral 600.
- No escuro, `text-dimmed` usa neutral 400; primário e sucesso permanecem no tom 400 da biblioteca.
- `UButton` tem cor padrão `neutral`, não `primary`. Variantes solid verdes explícitas têm hover/active a 90% no claro e 75% no escuro; foco de botões `primary`/`success` usa a respectiva cor integral por override local.
- O material translúcido é uma adaptação do calendário, não parte do CSS-base de `.dashboard`.

A escolha confirmada de usar o template significa preservar sua paleta e seus nomes funcionais, não reverter essas adaptações. Fontes: `frontend/app/app.config.ts` e `frontend/app/assets/css/main.css`.

### Neutral

Zinc fornece fundo, texto e separação. Use `--ui-bg`, `--ui-bg-muted`, `--ui-bg-elevated`, `--ui-bg-accented`, `--ui-text`, `--ui-text-muted`, `--ui-text-highlighted`, `--ui-border` e `--ui-border-accented`, ou as utilities semânticas equivalentes. Os tokens com sufixo de tema são uma fotografia dos papéis; não devem virar valores fixos em novas telas.

`--ui-bg-inverted` e `--ui-text-inverted` formam o par contrastante dos botões neutral solid. `--ui-text-dimmed` coincide com o texto secundário extraído nos dois temas; `--ui-text-toned` usa neutral 600 no claro e neutral 300 no escuro. Superfícies elevadas também podem aparecer com opacidade de 50%, sem introduzir outra cor-base.

### Estados funcionais

Sucesso compartilha a escala verde; warning, error e info conservam as escalas semânticas da biblioteca. Cor acompanha rótulos e estados reais; não é uma nova paleta decorativa. Os valores OKLCH dos neutros e estados foram preservados, sem conversões aproximadas para hex.

**The Tema Semântico Rule.** Use os papéis semânticos do tema ativo; os valores claro/escuro documentados não autorizam fixar a aparência de uma tela em um único tema.

## Typography

**Família:** Public Sans, com fallback `sans-serif`, definida no template e no aplicativo. Não foi identificada família independente para display ou labels; a hierarquia vem de tamanho, peso e contexto, sem escala matemática adicional comprovada.

- **Título compacto:** papel `title`, usado em títulos de `UPageCard` e nos cabeçalhos observados da Gestão ERP. Não define o tamanho universal de todo título do produto.
- **Corpo:** `body` é a base; `body-small` atende valores de tabela e texto operacional compacto.
- **Descrição:** `UPageCard` usa o papel `description`; composições locais podem usar corpo pequeno. Não foi imposto um entrelinhamento independente ao tamanho arbitrário da descrição.
- **Labels e navegação:** `label` corresponde ao botão `md`, ao label `UFormField` padrão e à navegação. `label-small` corresponde ao botão `sm` e ao badge `md`; `label-micro`, ao badge `sm`.
- **Inputs:** o `md` usa `input-mobile` abaixo de 768 px e `input-desktop` a partir de 768 px. Esse ajuste não altera automaticamente o tamanho dos botões.
- **Tabelas:** cabeçalhos sheet/ERP usam `table-header`; células usam `body-small`.
- **Badges:** a família da biblioteca inclui `xs` (8/12 px), `sm` (10/12 px), `md` (12/16 px), `lg` (14/20 px) e `xl` (16/24 px), peso 500. Os tamanhos pequenos não são uma regra para texto corrido.

Fontes: `frontend/.nuxt/ui/{button,input,form-field,badge,page-card,navigation-menu}.ts`, `frontend/app/components/data-table/sheet.ts` e `frontend/app/components/office-erp/companyList.ts`. Public Sans foi confirmada nos elementos medidos do navegador.

**The Escopo da Densidade Rule.** Reutilize a hierarquia da composição equivalente; não transforme os títulos compactos e as células da Gestão ERP em limites tipográficos de todas as superfícies.

## Layout

A unidade espacial é de 4 px; meios passos de 6 px acompanham controles e grupos compactos. O frontmatter registra os passos reutilizados, não uma nova escala.

**Medidas e scroll.** `frontend/app/utils/pageShell.ts` distingue listas centradas (máximo 64 rem), formulários (48 rem), detalhes (72 rem), páginas de scroll único e páginas que reservam o scroll à tabela. O container genérico da biblioteca (80 rem) não substitui essas medidas operacionais. O header-base mede 4 rem.

- Shell de página comum: padding de 12 px, 16 px a partir de `sm` e 20 px a partir de `lg`; gaps de 12/16 px ou 16/20 px conforme o shell.
- Detalhe de registro: padding de 12/16 px e gap de 12 px.
- Painel de lista: heading separado; toolbar com padding de 16 px; corpo com 16/24 px; footer com regra superior e afastamento de 16 px.
- Sheet: ocupa a altura disponível, mantém ações/toolbar fora da rolagem da tabela e possui scroll próprio. Não deve ser envolvida automaticamente no card de um painel.
- **Exceção Gestão ERP:** corpo com padding de 16/24/32 px; listas limitadas a 64 rem e resumo com largura disponível, sem o mesmo limite. O resumo usa 16/24 px. No modo lista, `OfficeErpPanel` apresenta heading e toolbar sem card externo.

**Breakpoints existentes:** `sm` 640 px, `md` 768 px, `lg` 1024 px e `xl` 1280 px. A sidebar de desktop aparece a partir de `lg`; abaixo disso, usa o controle de abertura. Na Gestão ERP, o seletor de seção permanece até `xl`, inclusive em tablet; o menu horizontal aparece a partir de 1280 px. Informação deslocada em telas estreitas precisa continuar alcançável em expansão ou scroll apropriado, conforme o contrato da tarefa.

Fontes: `pageShell.ts`, `components/data-table/{panel,sheet}.ts`, `components/office-erp/{OfficeErpPanel.vue,companyList.ts}` e `pages/gestao-erp.vue`. A extração calculada cobriu viewport de 1366 × 900 px e o viewport original de 678 × 604 px; celular estreito não teve validação visual completa nesta documentação.

## Elevation & Depth

Profundidade funcional: painéis discretos; sombras marcam sobreposições; translucidez fica restrita ao calendário. Planos tonais e bordas finas organizam a operação; overlays recebem elevação quando se sobrepõem ao conteúdo. Não é um sistema globalmente sem sombras.

- **Elevação pequena:** sombra `sm` da biblioteca para superfícies sobrepostas menores, como conteúdo de navegação colapsada. O valor completo está no sidecar.
- **Elevação de modal:** sombra `lg`, borda semântica e fundo padrão, sem blur obrigatório. O backdrop usa fundo elevado a 75%.
- **Movimento funcional:** transições de cor de 150 ms com easing padrão; modal com fade/scale de 200 ms e ease-out; disclosures e ícones preservam os tratamentos locais de movimento reduzido. Não foi estabelecida uma nova política global de animação.
- **Exceção calendário:** `glass-material` aplica blur de 24 px no claro e 28 px no escuro, saturação/brilho e fundo translúcido próprios. `prefers-reduced-transparency: reduce` troca esse fundo pelo semântico e zera o blur. A exceção não autoriza distribuir vidro pelo restante da aplicação.

Fontes: `frontend/.nuxt/ui/{modal,navigation-menu}.ts`, tokens calculados de sombra/movimento, `frontend/app/assets/css/main.css` e `frontend/app/pages/work/calendario.vue`.

**The Profundidade Funcional Rule.** Separe painéis por tom e borda, reserve sombras para sobreposições e mantenha o material translúcido no calendário.

## Shapes

Arredondamento moderado, sem transformar todos os elementos em cápsulas. A base runtime é `--ui-radius: 0.25rem`: `sm` equivale à base, `md` a 1,5 vez e `lg` a duas vezes. Os valores efetivos estão no frontmatter.

Controles usam `md`; cards e modais usam `lg`; badges pequenos usam `sm`. Grupos de campos removem cantos internos para formar uma unidade. Tabelas podem arredondar apenas as extremidades do cabeçalho, sem criar um novo card externo. Bordas/rings finos usam os papéis semânticos; foco visível é um estado distinto, não decoração permanente.

Fontes: temas gerados de `button`, `input`, `badge`, `page-card`, `card` e `modal`; `components/data-table/{panel,sheet}.ts`. Não confundir o raio-base de 4 px com o raio efetivo de cada componente nem presumir a existência de `--radius-md` no runtime.

## Components

### Buttons

Discretos e precisos, com hierarquia por peso visual. O default do aplicativo é `neutral` + `solid` + `md`: preenchimento invertido, texto invertido e hover/active a 90%. `outline` usa fundo padrão e ring acentuado, mudando para fundo elevado; `ghost` mantém repouso transparente e ganha fundo elevado na interação.

O padding-base `md` e `sm` é o mesmo; seus papéis tipográficos diferem. Ícones `md` medem 20 px, `sm` 16 px; botões quadrados usam padding próprio. `primary` solid é uma variante explícita verde, não sinônimo de toda ação principal. Os overrides de opacidade e foco dessa variante estão descritos em Colors. Neutral usa outline de foco de 3 px com cor invertida a 25%; disabled mantém semântica, cursor e opacidade de 75%.

### Inputs / Fields

Campos familiares do Nuxt UI: fundo padrão, texto destacado, ring acentuado e raio moderado. A variante padrão é `outline`, cor de foco `primary` e tamanho `md`. A busca acrescenta ícone de 20 px e padding inicial de 36 px. O foco troca o ring para primary e mostra outline de 3 px a 25%; erro usa o papel `error`; disabled reduz opacidade a 75% e não deve reagir como campo disponível. Labels, erros e ajuda permanecem associados ao campo. Validação e foco efetivo pertencem ao componente real, não às amostras estáticas.

### Chips / Badges

Badges compactos comunicam estado, não são botões por aparência. Variantes `solid`, `outline`, `soft` e `subtle` seguem os papéis semânticos; `soft` usa a respectiva cor a 10%, e `subtle` acrescenta ring a 25%. O `md` usa padding de 4 × 8 px e raio `md`; `sm` usa 4 × 6 px e raio `sm`. Não atribuir estados de clique a um badge meramente informativo.

### Cards / Containers

`DataTablePanelList` combina heading `UPageCard` naked com conteúdo `subtle`: superfície elevada a 50%, ring semântico e separação de toolbar/corpo. O heading não é um card de conteúdo preenchido. `panel.ts` e `tableChrome.ts` centralizam regras finas e a faixa elevada do cabeçalho.

A família sheet possui cabeçalhos compactos, células com padding de 6 × 12 px e scroll próprio. A lista ERP usa células com padding de 8 × 12 px e composição sem card externo no modo lista. Preserve a família da tarefa em vez de aplicar uma única caixa a todos os dados.

### Navigation

`UNavigationMenu` usa labels médios, ícones familiares e cor ativa primary. `link` pode indicar a rota por linha; `pill` acrescenta superfície tonal quando aplicável. Estado ativo, hover e foco permanecem distintos. A Gestão ERP usa `link` com highlight no menu principal; seletor compacto substitui o menu abaixo de `xl`, sem retirar destinos.

### Modals

`UModal` usa fundo padrão, bordas/divisores e elevação `lg`. O padrão não fullscreen limita a largura a 32 rem, com margem horizontal total de 2 rem, e mantém corpo rolável; fluxos reais podem ampliar essa medida localmente. Header/footer têm padding de 16 px, com 24 px horizontais a partir de `sm`; corpo usa 16/24 px. Formulários delimitados reutilizam o editor modal; fluxos longos conservam a composição de página apropriada. Focus containment/return, validação e recuperação permanecem nos componentes reais.

Fontes destas famílias: `frontend/.nuxt/ui/`, `frontend/app/components/data-table/`, `frontend/app/components/office-erp/` e `openspec/specs/frontend-ui-patterns/spec.md`. As dez amostras do sidecar representam a aparência dessas famílias sem dependências de Vue, Tailwind ou dados reais; não implementam navegação, submissão ou gerenciamento de foco de modal.

## Do's and Don'ts

### Do:
- Do reutilizar Nuxt UI, os papéis semânticos e a hierarquia da composição equivalente.
- Do preservar as diferenças documentadas entre painel, sheet e lista ERP, inclusive medidas e dono do scroll.
- Do manter labels, foco visível, estados verdadeiros e ações alcançáveis nos dois temas e em telas estreitas.
- Do usar Public Sans, a escala espacial existente e arredondamento moderado antes de criar novos tratamentos.

### Don't:
- Don't fixar cores de um único tema quando existe um papel semântico correspondente.
- Don't transformar o botão verde, o título compacto ERP ou o card de painel em padrão obrigatório para todas as telas.
- Don't espalhar o material translúcido do calendário pelo sistema ou eliminar as sombras funcionais dos overlays.
- Don't reverter customizações atuais para igualar o template nem inventar cores, anti-referências ou garantias de acessibilidade não confirmadas.
