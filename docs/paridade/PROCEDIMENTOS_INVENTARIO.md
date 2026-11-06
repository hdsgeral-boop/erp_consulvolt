# PROCEDIMENTOS_INVENTARIO — Inventário funcional do Manual de Procedimentos do sistema legado

Origem: `C:\xampp\htdocs\ERP_CONSULVOLT_MEU\v_boss\New\v2` (HTML/JS, base no navegador: Dexie/IndexedDB). Análise só de leitura, feita a partir do código. Data: 2026-11-06, ramo `paridade-bd-front`. Este documento **não altera código**; o plano está em `PROCEDIMENTOS_PLANO.md` e o inventário do módulo irmão em `MANUAL_ORGANIZACAO_INVENTARIO.md`.

**Convenções.** Referências `ficheiro:linha` relativas a `v_boss/New/v2/`. Abreviaturas de ficheiros: **PM** = `js/modules/procedimentos/procedimentos_manual.js` (625 linhas, lido na íntegra); **PUI** = `js/modules/procedimentos/procedimentos_manual_ui.js` (446 linhas, lido na íntegra); **DC** = `js/shared/documento_corporativo.js` (269 linhas, lido na íntegra); **FN** = `js/fluxo_narrativa.js` (532 linhas; lido o cabeçalho, as 19 narrativas por script e a função de impressão 480-520). «[sic]» = texto do legado reproduzido tal e qual. «BUG?» = comportamento aparente de erro a decidir. Rótulos entre «» são os literais do ecrã.

**Aviso de completude** (o que ficou por ler ou por confirmar) — ver §12.

---

## 1. VISÃO GERAL

### 1.1 O que é

O **Manual de Procedimentos** é um documento A4 gerado **a partir de dados que já existem**: as narrativas dos processos do ERP (`window.FluxoNarrativa.NARRATIVAS`, `FN:21`, 19 processos, 109 etapas, 84 cruzamentos), eventualmente **adaptadas pela empresa** (tabela `proc_flows`), mais **processos próprios** criados de raiz. Cada processo descreve-se por **etapas** (nome, quem executa, o que se faz, controlos, resultado), **intervenientes** (papéis) e **cruzamentos** com outros processos (em que etapa e o que passa). O ecrã tem dois separadores: **Processos** (editar) e **Manual** (capa, textos, opções, pré-visualização, impressão).

Três camadas, por esta ordem (`PM:8-19`):
1. narrativas **de fábrica** (código; acompanham as actualizações do ERP);
2. **edições** da empresa gravadas em `proc_flows` («só o que foi mesmo tocado»);
3. processos **próprios** (criados de raiz, sem painel de acompanhamento).

Regra de ouro do legado: *editar a narrativa muda o MANUAL, nunca o que o painel do Fluxo de Processos mede* (`PM:16-18`; aviso azul em `PUI:171-174`).

### 1.2 O que o legado **não** tem (importante para o âmbito)

O pedido menciona capítulos/secções, formulários, indicadores, riscos, versões, aprovações, distribuição/leitura obrigatória e histórico. **Confirmado por leitura integral do código: o legado NÃO tem nenhum destes**, com as excepções abaixo:

| Conceito pedido | No legado | Evidência |
|---|---|---|
| Capítulos | Só os capítulos **fixos** do documento gerado (apresentação, objectivo, como ler, princípios, mapa, intervenientes, matriz geral, cobertura, procedimentos, controlos, disposições) | `PM:502-513` |
| Secções | Cada **processo** é uma secção do capítulo «Os procedimentos»; não há hierarquia de secções editáveis | `PM:433-463` |
| Passos | **Etapas** (5 campos) | `PM:79-80, 139-141` |
| Responsáveis | Texto livre «Quem executa (papel)» e lista de «Intervenientes»; **não** há ligação a cargos/funções — só um **confronto** informativo com RH › Funções | `PM:363-376` |
| Fluxogramas | **Diagrama linear** das etapas (caixas numeradas em fila); não há decisões, ramos nem desenho editável | `PM:425-431` |
| Formulários / anexos | Nenhum (só, nas tarefas das unidades, um URL livre `url_instrucoes`: «procedimento, manual ou instrução de trabalho») | `estrutura_tarefas_ui.js:215` |
| Indicadores | Nenhum no manual (os indicadores estão no painel do Fluxo, `FN:480-520`) | — |
| Riscos | Nenhum; o mais próximo é o campo «Controlos» | `PM:139-141` |
| Versões | Um **texto livre** «Versão» (omissão «1.0»); sem histórico de versões, sem snapshot | `PM:65, 225` |
| Aprovações | Três **textos de capa**: «Elaborado por», «Aprovado por», «Aprovado em»; sem fluxo, sem estados, sem aprovador autenticado | `PM:67, 228` |
| Distribuição / leitura obrigatória | Nenhuma | — |
| Histórico | Só linhas em `audit_logs` («Editar procedimento», «Repor procedimento de fábrica», «Eliminar procedimento próprio») | `PM:35-40, 170, 181` |
| Vigência / revisão periódica | Nenhuma (só o texto de exemplo «é revisto sempre que um processo seja alterado», `PM:59`) | — |
| Word | Nenhum (só impressão do navegador; o único .pptx do legado é do *manual de formação* da Estrutura, fora de âmbito) | `PM:607-611` |

Consequência para o plano: **paridade = Onda 1** (reproduz o que existe); versões, aprovação, vigência, leitura obrigatória, histórico e anexos são **melhorias** (Ondas 2-3) propostas com recomendação, **sujeitas à decisão do utilizador** (Q2-Q6 em `PROCEDIMENTOS_PLANO.md`).

### 1.3 Onde vive no legado

| Onde | O quê | Ref |
|---|---|---|
| Menu | Item de topo «Manual de Procedimentos» (ícone `fa-book-open`), abaixo de «Fluxo de Processos» | `index_arrumado.html:110-112` |
| Ecrã | `data-target="proc_manual"`; vista registada em `window._views['proc_manual']` → `window.renderProcManual` | `js/app_v2.js:1071` |
| Módulo | Pertence ao módulo `fluxos` («Fluxo de Processos», pacote `fluxos`): `ecras: { fluxo_processos: 'fluxos', proc_manual: 'manual-procedimentos' }` (rota `#/manual-procedimentos`) | `js/core/modulos_registo.js:17` |
| Scripts | `procedimentos_manual.js` e `procedimentos_manual_ui.js` (v=20261106man2) carregados no `index_arrumado.html`, **depois** de `documento_corporativo.js`; o pacote `fluxos` (narrativas) carrega-se sob pedido | `index_arrumado.html:726-730`, `PM:85-90` |
| Dados | Dexie versão 1300: `proc_manual`, `proc_flows` | `js/db_v2.js:1388-1395` |
| Permissões | Ecrã `proc_manual` («Manual de Procedimentos») com 1 tarefa | `js/permissoes.js:136-138` |

Os ficheiros `js/projectos_*.js` (a pasta `js/modules/projectos/` não existe em `v2`), `js/modules/metodo/*`, `js/modules/estrategia/*` (excepto a linha citada abaixo), `js/data/repositorios.js`, `js/ui_painel_modulos.js`, `js/rotas.js`, `js/modulos_manifesto.js` e a pasta `Regras/` (só PDFs contabilísticos: apuramento de resultados, Relatório e Contas 2024) **não referenciam** o Manual de Procedimentos (pesquisa `grep -rIl` por `procedimento|manual_estrutura|estrutura_manual|procedimentos_manual` em todo `v_boss/New/v2` devolveu apenas: `docs/manual_estrutura_organica.html`, `docs/manual_rh_salarios.html`, `index_arrumado.html`, `js/core/modulos_registo.js`, `js/db_v2.js`, `js/fluxo_narrativa.js`, `js/modules/estrategia/estrategia_documento_ui.js`, `js/modules/estrutura/estrutura_tarefas_ui.js`, os dois ficheiros do módulo, `js/modules/rh/aval360_dados.js`, `aval360_ui.js`, `js/permissoes.js`, `js/shared/documento_corporativo.js`). O sentido de cada referência:

| Ficheiro | Referência | Natureza |
|---|---|---|
| `docs/manual_estrutura_organica.html` | «Manual de Formação — Estrutura Orgânica» (guia do utilizador, 1374 linhas, com «Imprimir» e exportação .pptx) | **Documentação de ajuda**, não é o Manual da Organização; fora de âmbito (vai para a Ajuda, `frontend/src/componentes/ajuda`) |
| `docs/manual_rh_salarios.html` | idem, RH | idem |
| `js/modules/estrategia/estrategia_documento_ui.js:31` | «Reaproveita a folha do Manual de Procedimentos: são o mesmo tipo de ecrã» (reutiliza os estilos `proc-estilos`/`pm-*`) | acoplamento de **CSS** entre Procedimentos e o Documento do Plano Estratégico |
| `js/modules/estrutura/estrutura_tarefas_ui.js:215` | placeholder «https://… procedimento, manual ou instrução de trabalho» | campo `url_instrucoes` da tarefa da unidade |
| `js/modules/rh/aval360_dados.js:5-13,146,175` e `aval360_ui.js:118,210-213,250,303,451-452` | «comunicado com os procedimentos no Portal … com confirmação de leitura» («Li e tomei conhecimento dos procedimentos») | **padrão de confirmação de leitura** já existente na avaliação 360º (não é o manual) |
| `js/fluxo_narrativa.js` | fonte das narrativas e dos cruzamentos externos `estrutura_manual`/`estrategia_plano` (`FN:71,233,237,253,291,292,369`) | **dados de fábrica** do manual |

### 1.4 Dependências de execução do legado

- `window.FluxoNarrativa.NARRATIVAS` (fábrica) — `PM:23-25, 85-90`.
- `window.EstruturaOrg` (declarado em `PM:25`, **não usado** no ficheiro — BUG? resto morto).
- `window.DocumentoCorporativo` (capa, índice, folha, assinaturas) — `PM:420-422`.
- Tabelas Dexie `proc_flows`, `proc_manual`, `roles` (RH › Funções), `companies`, `audit_logs`.
- `window.Dialogo.pedir/confirmar`, `window.Notificacoes` (erro/sucesso/aviso), `window.getLocalDate`, `window.currentUser`, `window.activeCompanyId`.

---

## 2. ESTRUTURA DO DOCUMENTO GERADO (o «manual»)

Ordem e condição de cada capítulo (`PM:502-513`; o índice só numera o que entra — `DC:33-51`):

| N.º | Capítulo | Entra quando | Conteúdo | Ref |
|---|---|---|---|---|
| — | **Capa** | sempre | logótipo (ou nome da empresa), título, linha azul, subtítulo fixo «Como se executa cada processo: etapas, responsáveis, controlos e resultado esperado», **ficha** (Versão, Data, Elaborado por, Aprovado por [· data], Âmbito «N processo(s), N etapa(s), N controlo(s)», Adaptação «N processo(s) adaptados [e N próprios da empresa]») | `PM:592-604`, `DC:121-134` |
| — | **Índice** | sempre | lista dos capítulos incluídos | `DC:46-49` |
| 1 | **Apresentação** | texto `apresentacao` não vazio | parágrafos | `PM:503, 518` |
| 2 | **Objectivo e âmbito** | texto `objectivo` | parágrafos | `PM:504, 519` |
| 3 | **Como ler este manual** | `incluir_legenda` | tabela com 5 linhas (Etapa, Quem executa, O que se faz, Controlos, Resultado) + nota sobre processos «acompanhados» | `PM:505, 520-528` |
| 4 | **Princípios e regras gerais** | texto `principios` | parágrafos | `PM:506, 529` |
| 5 | **Mapa dos processos** | sempre | «A empresa tem N processo(s) descritos neste manual.» + tabela `#`, Processo (marca «processo próprio da empresa»), Objectivo, Etapas, Painel (sim/—) | `PM:507, 530-536` |
| 6 | **Intervenientes por processo** | `incluir_matriz` e há intervenientes | matriz papéis × processos com ● e total; marca «(sem função em RH)» | `PM:508, 537-542` |
| 7 | **Matriz geral — onde os processos se encontram** | `incluir_cruzamentos` e há ligações | matriz processo × processo com ● + coluna «Liga-se a»; nota do processo central; nota de isolados; tabela «Os pontos de encontro, um a um» (Entrega, Na etapa, Recebe, O que passa; linhas de externos em itálico «(fora deste manual)») | `PM:509, 543-568` |
| 8 | **Cobertura: módulos do ERP e processos descritos** | `incluir_cobertura` | tabela Módulo / Processos descritos / Por descrever / Estado (✓/«incompleto»/«sem processo próprio») + notas de painéis sem narrativa e narrativas sem painel | `PM:510, 569-584` |
| 9 | **Os procedimentos** | sempre | uma ficha por processo (§2.1) | `PM:511, 585` |
| 10 | **Controlos-chave** | `incluir_controlos` e há controlos | «Reúnem-se aqui os N controlos…» + tabela Processo/Etapa/Quem/Controlo | `PM:512, 586-589` |
| 11 | **Disposições finais** | texto `notas` | parágrafos + **assinaturas** (Elaborado por / Aprovado por · data) | `PM:513, 590`, `DC:224-230` |

### 2.1 Ficha de um procedimento (`processoHtml`, `PM:433-463`)

Numeração `N.i` (N = n.º do capítulo «Os procedimentos», i = posição). Blocos: título «N.i. {título}»; objectivo em itálico (ou «Objectivo por definir.»); «Intervenientes: a · b»; **diagrama** (se `incluir_diagrama`); tabela de etapas `#`(N.i.j) | Etapa | Quem executa | O que se faz | Controlos | Resultado (campos vazios mostram «—» ou «por definir» a laranja); e, se `incluir_cruzamentos` e existem, «Onde se cruza com outros processos» (colunas Sentido, Processo, Nesta etapa, O que passa; **→** o que entrega, **←** o que recebe, este em fundo cinza e itálico «em «etapa»») + legenda «→ o que este processo entrega a outro · ← o que recebe de outro.». A ficha não se parte entre páginas (`.pr {page-break-inside: avoid}`).

### 2.2 Diagrama (`diagramaHtml`, `PM:425-431`)

Sequência de caixas numeradas (nome da etapa em negrito, papel em letra pequena, «—» sem papel) ligadas por ▶; `display:flex` com quebra de linha. Não há ramos, decisões nem estados: **é uma lista horizontal, não um fluxograma**. (Crítico para o PDF no servidor: dompdf não suporta flexbox — ver plano, risco R2.)

### 2.3 Motor partilhado `DocumentoCorporativo` (`DC`)

Capa, índice numerado, folha A4 com `@page {A4 portrait; margens 22/16/18/18 mm}`, rodapé fixo em todas as páginas com **identificação da empresa** (nome, NIF, CRC, capital social, morada, contactos) à esquerda e **título/versão/data/«n / total páginas»** à direita (`DC:140-154`), assinaturas e `abrir(html)` (janela nova; erro «O navegador bloqueou a janela de impressão. Autorize as janelas para este endereço.»). O total de páginas é **medido em JavaScript** simulando o enchimento (`DC:165-222`) porque `counter(pages)` não funciona fora de `@page`. Texto livre: `par` (parágrafos por linha em branco, `\n`→`<br>`) e `comoLista` (uma linha por item → `<ul>`; remove marcadores `- • * –`), **sempre com escape HTML** (`DC:23-26`). Cor de destaque do manual de procedimentos: `#0f766e` (verde-azulado); do da Organização: `#1e3a8a`.

---

## 3. ECRÃS, SEPARADORES, MODAIS E BOTÕES

Um **ecrã** (`proc_manual`), **2 separadores**, **3 diálogos** + a pré-visualização. Estado do ecrã: `st = { aba: 'manual', chave: null, sujo: false, escolhidos: null }` (`PUI:31`).

### 3.1 Cabeçalho comum (`PUI:93-105`)

| Elemento | Rótulo | Notas |
|---|---|---|
| Título (`h2.view-title`) | «Manual de Procedimentos» | |
| Descrição | «Como se executa cada processo da empresa: etapas, quem as faz, o que se controla e o que fica feito. Os processos vêm do **Fluxo de Processos** e podem ser adaptados, escondidos ou criados de raiz.» | |
| Botão-separador | «Processos» (`fa-diagram-project`) | `procAba('processos')`; `btn-primary` quando activo |
| Botão-separador | «Manual» (`fa-book`) | `procAba('manual')`; **separador por omissão** |
| Corpo | «A carregar…» (spinner) enquanto monta | |
| Erro | «O módulo do manual de procedimentos não está carregado.» | `PUI:91` |

### 3.2 Separador «Processos» (`painelProcessos`, `PUI:115-156`)

Layout em 2 colunas (`minmax(320px,440px) | 1fr`); abaixo de 1100 px passa a 1 coluna (`PUI:72`).

**Coluna esquerda**

| Bloco | Conteúdo | Ref |
|---|---|---|
| Cartão «Processos (n)» + botão **«Novo»** (`fa-plus`, `procNovo()`) | lista (`max-height: calc(100vh - 260px)`), um item por processo (título ou chave; «{n} etapa(s) · {m} controlo(s)»; etiqueta de origem; etiqueta «com painel» azul se `tem_painel`) ; clicar → `procAbrir(chave)` | `PUI:122-136` |
| Etiquetas de origem | **do ERP** (cinza, FABRICA) · **adaptado** (verde, EDITADO) · **próprio** (roxo, PROPRIO) | `PUI:80-84` |
| Cartão «Cobertura» | só aparece se há painéis sem narrativa ou módulos por cobrir: caixa laranja «**Com painel e sem procedimento escrito:** a, b. O ERP já acompanha estes processos, mas ninguém escreveu como se fazem.» e «**Módulos por cobrir:** Nome (chaves em falta) · …» | `PUI:137-144` |
| Cartão «Escondidos do manual (n)» | só se existem; por linha o título e botão «Voltar a incluir» (`procMostrar(chave)`) | `PUI:145-150` |

**Coluna direita** (`#proc-editor`): estado vazio «Escolha um processo à esquerda para ver e adaptar as suas etapas.» (`PUI:152-154`) ou o **editor** (`editorHtml`, `PUI:158-193`):

| Elemento | Rótulo / comportamento | Ref |
|---|---|---|
| Cabeçalho | ícone + título (ou chave) + etiqueta de origem | 162-163 |
| Botão «Repor» (`fa-rotate-left`) | só se `editado`; `title` «Voltar ao texto que vem com o ERP»; num processo próprio o botão chama-se **«Apagar»** com `title` «Apagar este processo» | 165-166 |
| Botão «Esconder» (`fa-eye-slash`) | `procOcultar(chave)` | 167 |
| Botão «Gravar» (`fa-save`, primário) | `procGravar()` | 168 |
| Aviso azul (se `tem_painel`) | «Este processo tem painel de acompanhamento no **Fluxo de Processos**. O que escrever aqui muda o **manual**; o painel continua a medir os dados reais. Acrescentar ou retirar etapas faz o manual deixar de acompanhar o painel passo a passo.» | 171-174 |
| Campo oculto `pe-chave` | chave (não editável) | 175 |
| Campo **«Título do processo \*»** | texto; obrigatório (validado ao gravar) | 176 |
| Campo **«Objectivo»** | textarea 2 linhas; ajuda «Uma frase: para que existe este processo.» | 177-178 |
| Campo **«Intervenientes»** | textarea 2 linhas, **um papel por linha**; ajuda «Papéis, não pessoas — o manual não deve envelhecer a cada mudança.» | 179-180 |
| Sub-cabeçalho «Etapas» + botão «+ Etapa» (`procEtapa`) | acrescenta cartão em branco no fim e renumera | 181-185, 245 |
| Cartão de etapa (`etapaHtml`, 223-242) | cabeçalho «Etapa n» + botões ↑ «Subir», ↓ «Descer», 🗑 «Remover» (`title`); `input` placeholder «**Nome da etapa \***»; `input` «**Quem executa (papel)**»; `textarea` «**O que se faz nesta etapa**»; `textarea` «**Controlos — o que tem de ser verdade**»; `textarea` «**Resultado — o que fica feito**» (os dois últimos lado a lado) | 223-242 |
| Sub-cabeçalho «Onde se cruza com outros processos» + botão «+ Cruzamento» (`procCruz`) | texto de apoio «O que este processo **entrega** a outro, e em que etapa. É daqui que sai a matriz geral da empresa.» | 186-190 |
| Cartão de cruzamento (`cruzHtml`, 195-216) | `select` «— processo —» com **os outros processos** + 2 opções externas «**Estrutura Orgânica (outro documento)**» (`estrutura_manual`) e «**Plano Estratégico (outro documento)**» (`estrategia_plano`); `select` «— em que etapa deste —» com os **nomes** das etapas actuais; `input` «O que passa de um para o outro»; botão 🗑 remove o cartão | 195-216 |

**Comportamento dos botões** (todos `window.*`, sem `pode()` — ver §8):

| Acção | Efeito | Mensagens | Ref |
|---|---|---|---|
| `procAbrir(chave)` | carrega o processo (`P().processo`), mostra editor, marca o item activo | — | 244 |
| `procEtapa()` | acrescenta etapa vazia; `renumerar()` | — | 245, 254 |
| `procMoverEtapa(b,±1)` | troca com a vizinha; não faz nada nos extremos; renumera | — | 247-253 |
| `procApagarEtapa(b)` | remove o cartão; renumera | — | 246 |
| `procCruz()` | acrescenta cartão de cruzamento (lê o processo para oferecer as etapas) | — | 217-221 |
| `procGravar()` | lê etapas e cruzamentos do DOM (cruzamentos sem processo descartados), chama `gravarProcesso`, recarrega | sucesso «Processo gravado.»; erro = `e.message` | 272-290 |
| `procNovo()` | ver abaixo | ver abaixo | 256-270 |
| `procRepor(chave)` | confirmar → `reporProcesso` | ver abaixo | 292-304 |
| `procOcultar(chave)` | confirmar → `ocultarProcesso(chave,true)` | ver abaixo | 306-316 |
| `procMostrar(chave)` | `ocultarProcesso(chave,false)` | «Voltou a incluir-se no manual.» | 317-320 |

### 3.3 Diálogos

| # | Diálogo | Título / mensagem / botões | Validações e efeito | Ref |
|---|---|---|---|---|
| D1 | **Novo processo** (`Dialogo.pedir`) | título «Novo processo»; «Como se chama? (ex.: «Recrutamento e selecção»)»; obrigatório | chave = nome sem acentos, minúsculas, `[^a-z0-9]+`→`_`, sem `_` nas pontas, **máx. 30 caracteres**; vazia → «Esse nome não dá uma chave válida. Use letras.» (erro); chave existente (entre os processos **visíveis**) → «Já existe um processo com esse nome.» (erro); grava `{titulo, objectivo:'', intervenientes:[], etapas:[{nome:'Primeira etapa',…}]}`; selecciona-o; «Processo criado. Preencha as etapas.» | `PUI:256-270` |
| D2 | **Repor / Apagar** (`Dialogo.confirmar`, `perigo: true`) | processo próprio: título «Apagar o processo», «Apagar «{título}»? O que escreveu perde-se.», botões «Apagar»/«Manter»; de fábrica: título «Repor o texto do ERP», ««{título}» volta ao texto que vem com o ERP e as suas alterações perdem-se.», «Repor»/«Manter» | `reporProcesso`; após apagar um próprio, `st.chave = null`; «Processo apagado.» / «Texto reposto.» | `PUI:292-304` |
| D3 | **Esconder do manual** (`Dialogo.confirmar`) | título «Esconder do manual»; ««{título}» deixa de sair no manual. Continua a existir no ERP e pode voltar a incluí-lo quando quiser.»; «Esconder»/«Manter» | `ocultarProcesso(chave,true)`; `st.chave=null`; «Escondido do manual.» | `PUI:306-316` |

### 3.4 Separador «Manual» (`painelManual`, `PUI:324-382`)

Coluna esquerda (formulário) + coluna direita (**pré-visualização** sticky em `iframe`).

| Cartão | Campos / opções (rótulo exacto) | Tipo, omissão, validação | Ref |
|---|---|---|---|
| **O documento** (`fa-file-signature`) | «Título» · «Versão» · «Data» · «Aprovado em» · «Elaborado por» · «Aprovado por» | texto; texto; `date` (se vazia usa **hoje**); `date`; texto; texto. Omissões: título «Manual de Procedimentos», versão «1.0». Sem validação de formato (versão é texto livre) | 335-344 |
| **Processos no manual** (`fa-check-double`) | botões «Todos» / «Nenhum» (`procTodos`); uma caixa por processo «{título} ({n} etapas)» (lista com scroll 220 px) | omissão: todos marcados (se `processos` gravado, essa lista) | 346-355 |
| **O que entra** (`fa-list-check`) | 7 caixas: «Diagrama das etapas em cada processo» · ««Como ler este manual» — explica os campos» · «Matriz de intervenientes por processo» · «Quadro dos controlos-chave — a lista por onde se audita» · «**Matriz geral** — onde os processos se encontram — e, em cada ficha, o que entrega e o que recebe» · «Cobertura: módulos do ERP × processos descritos — útil na revisão, não no manual que circula» · «Conferir os intervenientes com RH › Funções» | booleanos; omissão: **todas ligadas excepto Cobertura (desligada)** | 357-365, `PM:69-75` |
| **Texto que se escreve à mão** (`fa-pen-nib`) | 4 textareas de 4 linhas: «Apresentação» · «Objectivo e âmbito» · «Princípios e regras gerais» · «Disposições finais», cada uma com ajuda e **exemplo como placeholder** (não como valor) | texto livre; omissão vazia («um manual que sai com texto inventado pelo sistema é pior do que um manual com um espaço em branco assumido») | 367-369, `PM:44-61` |
| Barra de acções | «Gravar» (`#pm-grav`), «Pré-visualizar» (`#pm-prev`), «Imprimir» (`#pm-imp`) + `#pm-estado` («Há alterações por gravar.» / «Gravado.») | | 370-375 |
| **Pré-visualização** | cabeçalho «Pré-visualização» + KPI «**N** processos · **N** etapas · **N** controlos · **N** papéis · **N** cruzamentos»; caixa laranja «**O manual sai, mas com partes por preencher:**» + lista de avisos; `iframe` (título «Pré-visualização») com o HTML verdadeiro (`document.write`) | automática ao abrir o separador (`renderProcManual`) e a cada «Pré-visualizar» | 377-381, 420-437, 108 |

**Acções**

- Qualquer `input|change` nos cartões marca `st.sujo = true` e escreve «Há alterações por gravar.» (`PUI:386-393`). **Nota:** só os elementos dentro de `.pm-cx`.
- «Gravar» → `gravarDefinicoes(lerForm())` → `st.sujo=false`; «Gravado.» + «Manual gravado.» (`PUI:411-418`).
- «Imprimir» → se sujo, grava primeiro; `imprimir(lerForm())` abre janela e chama `window.print()` aos 500 ms; se há avisos: «Aberto para impressão, com N aviso(s) de partes por preencher.» (tipo aviso) (`PUI:439-445`, `PM:607-611`).
- Escolha de processos: `lerForm` lê as caixas marcadas e põe-nas em `st.escolhidos`; **grava sempre uma lista explícita** (`processos: [...]`) (`PUI:400,407`) — ver bug B-03.

---

## 4. MODELO DE DADOS DO LEGADO (Dexie)

`js/db_v2.js:1388-1395` (versão 1300, 2026-11-06). Índices Dexie: `proc_manual: '++id, proc_company_id'`; `proc_flows: '++id, proc_company_id, chave, [proc_company_id+chave]'`. Não há FKs (IndexedDB). O módulo irmão tem `org_manual` (versão 1299, ver `MANUAL_ORGANIZACAO_INVENTARIO.md`).

### 4.1 `proc_manual` — definições do documento (uma linha por empresa)

| Campo | Tipo | Obrig. | Omissão | Valores / regra | Ref |
|---|---|---|---|---|---|
| `id` | int auto | — | — | PK | |
| `proc_company_id` | int/string | sim | empresa activa | índice; leitura tolera `parseInt/String/raw` (`ids()`, `PM:33`) | `PM:211, 224` |
| `titulo` | string | — | «Manual de Procedimentos» | | 225 |
| `versao` | string | — | «1.0» | texto livre | 226 |
| `data_documento` | string `AAAA-MM-DD` | não | `''` (a UI mostra hoje) | | 227 |
| `elaborado_por`, `aprovado_por` | string | não | `''` | | 228 |
| `aprovado_em` | string `AAAA-MM-DD` | não | `''` | | 228 |
| `processos` | `string[]` ou `null` | não | `null` = todos | chaves a incluir | 229 |
| `incluir_diagrama` | bool | — | true | | 230 |
| `incluir_matriz` | bool | — | true | | 231 |
| `incluir_controlos` | bool | — | true | | 232 |
| `incluir_legenda` | bool | — | true | | 233 |
| `incluir_cruzamentos` | bool | — | true | | 234 |
| `incluir_cobertura` | bool | — | **false** | | 235 |
| `conferir_funcoes` | bool | — | true | | 236 |
| `apresentacao`, `objectivo`, `principios`, `notas` | string | não | `''` | texto livre (`TEXTOS`) | 239 |
| `criado_em/por`, `actualizado_em/por` | ISO / string | — | agora / utilizador | `criado_*` só na criação | 240-241 |
| `is_master_data` | 0/1 | — | 0 | linhas com 1 são **ignoradas** na leitura | 212 |

### 4.2 `proc_flows` — edições e processos próprios

| Campo | Tipo | Obrig. | Regra | Ref |
|---|---|---|---|---|
| `id` | int auto | — | PK | |
| `proc_company_id` | int | sim | índice | 153, 163 |
| `chave` | string | sim | `toLowerCase().replace(/[^a-z0-9_]/g,'_')`; **única por empresa** (índice composto, mas **não imposto** — a unicidade é por convenção do código) | 154, 161 |
| `titulo` | string | sim | «Dê um título ao processo.» | 157 |
| `objectivo` | string | não | | 136 |
| `intervenientes` | `string[]` | não | aceita string com linhas; normaliza | 137-138 |
| `etapas` | `[{nome,quem,descricao,controlos,resultado}]` | **≥ 1** | etapas sem `nome` descartadas | 139-142 |
| `cruza` | `[{com,onde,o_que}]` | não | `com` = chave de outro processo **ou** `estrutura_manual` / `estrategia_plano`; sem `com` descarta | 145-148 |
| `oculto` | 0/1 | — | 1 = escondido do manual | 163, 190, 194 |
| `ordem` | int | — | `num(dados.ordem) \|\| ordem anterior \|\| 0`; **nunca editável na UI** | 164 |
| `criado_em/por`, `actualizado_em/por`, `is_master_data` | | | | 165, 169 |

**Origem** (derivada, não gravada): `FABRICA` (não existe linha), `EDITADO` (há linha para uma chave que existe na fábrica), `PROPRIO` (há linha sem equivalente na fábrica) — `PM:108-119`. `tem_painel = true` só para FABRICA/EDITADO.

### 4.3 Fonte de fábrica `NARRATIVAS` (código, não BD) — `FN:21-…`

`NARRATIVAS[chave] = { titulo, objectivo, intervenientes[], etapas[{nome,quem,descricao,controlos,resultado}], cruza[{com,onde,o_que}] }` (`FN:6-7,15-18`). **19 processos / 109 etapas / 84 cruzamentos** (contagem por script sobre `FN:21-380`):

| Chave | Título | Etapas | Cruzam. | | Chave | Título | Etapas | Cruzam. |
|---|---|---:|---:|---|---|---|---:|---:|
| `rh` | Processamento salarial | 6 | 5 | | `bancos` | Conta bancária (43) | 4 | 5 |
| `ferias` | Plano de férias | 6 | 2 | | `caixa` | Sessão da Folha de Caixa | 5 | 3 |
| `avaliacao` | Avaliação de desempenho 360º | 6 | 3 | | `orcamento` | Ciclo orçamental | 6 | 5 |
| `vendas` | Processo de venda | 6 | 6 | | `crm` | Oportunidade comercial (CRM) | 6 | 2 |
| `pos` | Sessão de caixa POS | 6 | 4 | | `acrescimos` | Acréscimos e diferimentos | 6 | 3 |
| `lav` | Ordem de lavandaria e alfaiataria | 6 | 3 | | `entregaveis` | Entregáveis das unidades | 5 | 4 |
| `proj` | Projecto ou obra | 7 | 6 | | `imobiliario` | Arrendamento de imóvel | 6 | 5 |
| `compras` | Processo de compra | 6 | 7 | | `stock` | Movimento de armazém | 5 | 6 |
| `imob` | Aquisição de imobilizado | 6 | 3 | | `manutencao` | Manutenção de activos | 5 | 5 |
| | | | | | `fecho` | Encerramento do exercício | 6 | 7 |

Cruzamentos **externos** (apontam a outros documentos): `estrategia_plano` (`FN:71,237,253,291,369`) e `estrutura_manual` (`FN:233,292`); são tratados em `EXTERNOS` (`PM:127-130`) para não aparecerem como ligação partida.

Impressão da narrativa de **um** processo no painel do Fluxo (`FN:480-520`): A4 paisagem, diagrama com estado do caso seleccionado, indicadores, tabela de narrativa e pendências — é funcionalidade do **Fluxo de Processos**, não do manual.

### 4.4 Registos de auditoria escritos

`log(accao, ref, detalhe)` (`PM:35-40`) grava em `audit_logs` `{company_id, action, ref_id, detail, user, date, is_master_data:0}`. Acções: «Editar procedimento» (`PM:170`, detalhe `chave: título [(N etapa(s) sem responsável)]`), «Repor procedimento de fábrica» / «Eliminar procedimento próprio» (`PM:181`). **BUG? B-01:** o esquema de `audit_logs` é `++id, company_id, timestamp, user, module, action, record_id, details` (`js/db_v2.js:26`); os campos usados aqui (`ref_id`, `detail`, `date`; sem `module`/`timestamp`) **não coincidem**, logo estas linhas não aparecem nas vistas de auditoria por módulo/data. Falhas da auditoria são engolidas (`catch`). Ocultar/mostrar e gravar definições **não** auditam por esta via (há o gancho genérico de `db_v2.js:530-542` para criar/alterar/eliminar linhas, não confirmado em detalhe).

---

## 5. REGRAS DE NEGÓCIO

| # | Regra | Ref |
|---|---|---|
| R1 | Um processo de fábrica **não editado** vem sempre do código; quando o ERP melhorar a descrição, a empresa recebe-a sem fazer nada. Quem editou fica com o seu texto e vê «adaptado» | `PM:11-15, 111-113` |
| R2 | Editar a narrativa **não** altera a lógica nem as etapas do painel (calculadas dos dados reais) | `PM:16-18` |
| R3 | Chave: minúsculas `[a-z0-9_]`; no «Novo» sem acentos e ≤ 30 caracteres | `PM:154`, `PUI:261` |
| R4 | Título obrigatório; ≥ 1 etapa; etapa sem nome é descartada; etapa sem «quem» **permitida** (só aviso no log e no manual) | `PM:157-159` |
| R5 | «Quem executa» deve ser **papel**, não pessoa («para o manual não envelhecer») | `PM:523`, `PUI:180` |
| R6 | Esconder ≠ apagar; esconder um de fábrica **grava uma cópia** com `oculto=1` (ver B-02) | `PM:185-197` |
| R7 | Repor = apagar a linha; num próprio, apagar é definitivo | `PM:175-183` |
| R8 | A matriz de intervenientes agrega `intervenientes` **∪** `etapas[].quem` por processo; ordena por n.º de processos desc., depois nome (pt) | `PM:346-355` |
| R9 | Conferência com RH › Funções: normalização NFD sem acentos e minúsculas; um interveniente «A / B» conta como encontrado se **qualquer** parte existir; `naFuncoes = null` se RH não tem funções | `PM:363-376` |
| R10 | Cruzamentos: cada par A→B guarda o sentido (quem entrega a quem); a matriz geral é **simétrica** (par não ordenado); grau = n.º de ligações; «central» = maior grau; «isolados» = grau 0 | `PM:304-331` |
| R11 | Cruzamento para chave desconhecida e não-externa mostra a própria chave (ligação partida visível) | `PM:467-471` |
| R12 | Avisos (não bloqueiam): sem processos; processos sem cruzamento; sem objectivo; etapas sem responsável; processos sem controlos (se controlos incluídos); etapas sem resultado; intervenientes sem função em RH | `PM:392-414` |
| R13 | O índice só numera capítulos que entram; títulos de texto livre vêm de `TEXTOS[k].rot` | `PM:502-513` |
| R14 | Cobertura: `paineis` fixos (rh, ferias, avaliacao, vendas, pos, lav, compras, imob, bancos, caixa, orcamento, crm, acrescimos, proj, entregaveis) e `POR_MODULO` fixo (13 módulos) — **listas escritas à mão no código** | `PM:258-276` |
| R15 | Texto livre nunca aceita HTML (escape) | `DC:23-26` |
| R16 | **Sem** numeração de documento, **sem** versionamento, **sem** aprovação, **sem** vigência, **sem** revisão periódica (ver §1.2) | — |

---

## 6. ESTADOS E TRANSIÇÕES

Não há máquina de estados. Os únicos «estados» são o **estado de um processo** (derivado) e o **estado do formulário**:

```
FABRICA ──editar/gravar──▶ EDITADO ──repor──▶ FABRICA
FABRICA ──esconder──▶ (cópia, oculto=1) ──voltar a incluir──▶ EDITADO (texto congelado!)   ← B-02
(novo) ──gravar──▶ PROPRIO ──apagar──▶ (inexistente)
qualquer ──esconder/voltar a incluir──▶ oculto 1/0
Formulário Manual: limpo ──input──▶ sujo ──gravar|imprimir──▶ limpo
```

---

## 7. GERAÇÃO, IMPRESSÃO E EXPORTAÇÃO

| Aspecto | Legado | Ref |
|---|---|---|
| Formato | HTML A4 completo (`<!doctype html>`), 1 ficheiro; **HTML → impressão do navegador** («Guardar como PDF» pelo utilizador) | `DC:239-254` |
| Pré-visualização | o mesmo HTML num `iframe` (`contentDocument.write`) | `PUI:434-435` |
| Impressão | `D().abrir(html + script de print aos 500 ms)`; bloqueio de pop-ups → erro | `PM:607-611`, `DC:257-263` |
| Paginação | `@page A4`, rodapé fixo com «n / total» (total medido em JS) | `DC:54, 87-100, 165-222` |
| Marca de água / «cópia não controlada» | **Nenhuma** | — |
| PDF no servidor / Word / Excel | **Nenhum** | — |
| Anexos | Nenhum | — |
| Cor | `#0f766e` | `PM:593` |

---

## 8. PERMISSÕES E PERFIS

| Ecrã | Tarefas | Acções declaradas | Nota | Ref |
|---|---|---|---|---|
| `proc_manual` | `proc_editar` — «Adaptar as narrativas, criar processos próprios e escrever o manual» | `procGravar`, `procNovo`, `procRepor`, `procOcultar`, `procMostrar`, `procGravarManual` | «As narrativas vêm do Fluxo de Processos e podem ser adaptadas à empresa. Editar a narrativa muda o manual, nunca o que o painel de acompanhamento mede.» | `js/permissoes.js:136-138` |

- **BUG? B-04 (permissões declarativas):** o código da UI define `const pode = k => window.can(k)` (`PUI:22`) mas **nunca o chama**; `procGravarManual` não existe como função. Logo o legado **não impede** um utilizador sem `proc_editar` de ver os botões de edição — a protecção era só o menu (`hasView('proc_manual')`). No novo sistema tem de ser aplicada **no servidor** (`exigir('proc_editar')`).
- Nenhum perfil-modelo do legado (`js/permissoes.js:700-730`) inclui `proc_manual`. Visualização = permissão de consulta do ecrã (`hasView`).
- Não há permissão de leitura distinta; todos os que vêem o ecrã podem imprimir.

---

## 9. LISTA NUMERADA DE FUNCIONALIDADES ATÓMICAS (PRO-F-xxx)

Cada item é testável isoladamente. «Onda» refere-se ao `PROCEDIMENTOS_PLANO.md` (O1 = paridade; O2/O3 = melhorias propostas, assinaladas «NOVO»). Estado no novo sistema: **todas EM FALTA** (nada existe — verificado: sem tabelas, rotas, catálogo nem ecrãs; a única peça reutilizável é `backend/app/Services/Gestao/Fluxos/narrativas.json`, incompleta, §10).

### A. Catálogo de processos
| ID | Funcionalidade | Ref legado | Onda |
|---|---|---|---|
| PRO-F-001 | Listar os processos do manual = fábrica + edições aplicadas + próprios, ordenados por `ordem` e depois título (pt) | `PM:102-121` | O1 |
| PRO-F-002 | Fonte de fábrica carregada sob pedido (19 narrativas, 109 etapas, 84 cruzamentos) | `PM:85-90`, `FN:21` | O1 |
| PRO-F-003 | Processo de fábrica **não editado** vem sempre da fábrica e acompanha as actualizações | `PM:111-112` | O1 |
| PRO-F-004 | Processo de fábrica editado → origem EDITADO, `ordem` gravada ou posição×10 | `PM:113` | O1 |
| PRO-F-005 | Processo próprio → origem PROPRIO, sem painel, `ordem` por omissão 9000 | `PM:117-119` | O1 |
| PRO-F-006 | Processo de fábrica tem `tem_painel = true`; próprio não | `PM:111, 118` | O1 |
| PRO-F-007 | Processo escondido não aparece na lista nem no manual | `PM:110, 117` | O1 |
| PRO-F-008 | Normalização (`limpar`): trim, intervenientes (texto→linhas), descarta etapas sem nome e cruzamentos sem `com` | `PM:132-149` | O1 |
| PRO-F-009 | Obter um processo por chave | `PM:123` | O1 |
| PRO-F-010 | Reconhecer as chaves externas `estrutura_manual` e `estrategia_plano` | `PM:127-130` | O1 |

### B. Edição
| ID | Funcionalidade | Ref | Onda |
|---|---|---|---|
| PRO-F-011 | Gravar edição de processo (fábrica ou próprio), com chave normalizada `[^a-z0-9_]`→`_`, criando ou actualizando | `PM:152-172` | O1 |
| PRO-F-012 | Recusar chave vazia: «O processo precisa de uma chave (ex.: «recrutamento»).» | `PM:155` | O1 |
| PRO-F-013 | Recusar título vazio: «Dê um título ao processo.» | `PM:157` | O1 |
| PRO-F-014 | Recusar processo sem etapas: «Um procedimento sem etapas não descreve nada. Acrescente pelo menos uma.» | `PM:158` | O1 |
| PRO-F-015 | Aceitar etapas sem «quem»; registar na auditoria «(N etapa(s) sem responsável)» | `PM:159, 170` | O1 |
| PRO-F-016 | Auditar «Editar procedimento» com `chave: título` | `PM:170` | O1 |
| PRO-F-017 | Repor um processo de fábrica ao texto original (apagar a edição) | `PM:175-183` | O1 |
| PRO-F-018 | Eliminar um processo próprio | `PM:175-183` | O1 |
| PRO-F-019 | Auditar «Repor procedimento de fábrica» / «Eliminar procedimento próprio» | `PM:181` | O1 |
| PRO-F-020 | Esconder um processo (de fábrica ou próprio) sem o apagar; «Processo não encontrado.» se não existe | `PM:186-197` | O1 |
| PRO-F-021 | Voltar a incluir um processo escondido | `PM:190, PUI:317-320` | O1 |
| PRO-F-022 | Listar os escondidos (chave + título) | `PM:200-204` | O1 |
| PRO-F-023 | Esconder um de fábrica **não congela** o texto (corrige B-02) | — | O1 (correcção) |
| PRO-F-024 | Reservar as chaves de fábrica e as externas; um próprio não as pode usar (corrige colisão em «Novo» com escondidos) | `PUI:263` | O1 (correcção) |

### C. Ecrã — separador «Processos»
| ID | Funcionalidade | Ref | Onda |
|---|---|---|---|
| PRO-F-025 | Cabeçalho: título e descrição do ecrã | `PUI:95-98` | O1 |
| PRO-F-026 | Botões-separador «Processos»/«Manual»; separador por omissão «Manual» | `PUI:31,100-101,111` | O1 |
| PRO-F-027 | Cartão «Processos (n)» com lista e botão «Novo» | `PUI:122-136` | O1 |
| PRO-F-028 | Item: título, «n etapa(s) · m controlo(s)», etiquetas do ERP/adaptado/próprio e «com painel» | `PUI:127-135` | O1 |
| PRO-F-029 | Seleccionar um processo abre o editor e realça o item | `PUI:129,244` | O1 |
| PRO-F-030 | Cartão «Cobertura» (painéis sem narrativa; módulos por cobrir) — só se há o que mostrar | `PUI:137-144` | O1 |
| PRO-F-031 | Cartão «Escondidos do manual (n)» com «Voltar a incluir» | `PUI:145-150` | O1 |
| PRO-F-032 | Estado vazio do editor «Escolha um processo à esquerda para ver e adaptar as suas etapas.» | `PUI:152-154` | O1 |
| PRO-F-033 | Editor: botões «Repor»/«Apagar» (só se editado; rótulo conforme origem), «Esconder», «Gravar» | `PUI:160-170` | O1 |
| PRO-F-034 | Aviso azul se o processo tem painel | `PUI:171-174` | O1 |
| PRO-F-035 | Campos Título \*, Objectivo, Intervenientes (um por linha) com as ajudas exactas | `PUI:176-180` | O1 |
| PRO-F-036 | Adicionar etapa («+ Etapa») e renumerar | `PUI:181-185,245,254` | O1 |
| PRO-F-037 | Campos da etapa: Nome \*, Quem executa (papel), O que se faz, Controlos, Resultado | `PUI:223-242` | O1 |
| PRO-F-038 | Mover etapa para cima/baixo (noop nos extremos) | `PUI:247-253` | O1 |
| PRO-F-039 | Remover etapa | `PUI:246` | O1 |
| PRO-F-040 | Adicionar cruzamento («+ Cruzamento») | `PUI:186-191,217-221` | O1 |
| PRO-F-041 | Campos do cruzamento: processo (outros + 2 externos), etapa deste (pelos nomes), «O que passa» | `PUI:195-216` | O1 |
| PRO-F-042 | Remover cruzamento; cruzamentos sem processo são descartados ao gravar | `PUI:213,282` | O1 |
| PRO-F-043 | «Novo processo»: diálogo com nome obrigatório | `PUI:256-260` | O1 |
| PRO-F-044 | «Novo»: gerar chave (sem acentos, `_`, ≤ 30) e recusar vazia / existente com as mensagens exactas | `PUI:261-263` | O1 |
| PRO-F-045 | «Novo»: criar com 1 etapa «Primeira etapa», seleccionar e avisar «Processo criado. Preencha as etapas.» | `PUI:264-269` | O1 |
| PRO-F-046 | «Gravar»: mensagem «Processo gravado.»; erros do serviço mostrados | `PUI:272-290` | O1 |
| PRO-F-047 | «Repor»/«Apagar»: confirmação perigosa com texto por origem; «Texto reposto.» / «Processo apagado.» | `PUI:292-304` | O1 |
| PRO-F-048 | «Esconder»: confirmação; «Escondido do manual.» | `PUI:306-316` | O1 |
| PRO-F-049 | «Voltar a incluir»: «Voltou a incluir-se no manual.» | `PUI:317-320` | O1 |

### D. Ecrã — separador «Manual» (definições e pré-visualização)
| ID | Funcionalidade | Ref | Onda |
|---|---|---|---|
| PRO-F-050 | Definições por empresa com omissões (PADRAO) | `PM:63-76, 208-217` | O1 |
| PRO-F-051 | Campos do documento: Título, Versão, Data (hoje se vazia), Aprovado em, Elaborado por, Aprovado por | `PUI:335-344` | O1 |
| PRO-F-052 | Gravar definições (título «Manual de Procedimentos» e versão «1.0» por omissão se vazios) | `PM:219-242` | O1 |
| PRO-F-053 | Selecção de processos: caixa por processo, «Todos»/«Nenhum»; omissão todos | `PUI:346-355,394` | O1 |
| PRO-F-054 | Sete opções «O que entra» com as omissões do legado (cobertura desligada) | `PUI:357-365`, `PM:69-75` | O1 |
| PRO-F-055 | Quatro textos livres (Apresentação, Objectivo e âmbito, Princípios e regras gerais, Disposições finais) com ajuda e exemplo como placeholder | `PM:44-61`, `PUI:367-369` | O1 |
| PRO-F-056 | Indicador «Há alterações por gravar.» / «Gravado.» | `PUI:393,415` | O1 |
| PRO-F-057 | Pré-visualização automática ao abrir o separador e por «Pré-visualizar» | `PUI:108,420-437` | O1 |
| PRO-F-058 | KPI da pré-visualização: processos, etapas, controlos, papéis, cruzamentos | `PUI:428-430` | O1 |
| PRO-F-059 | Painel «O manual sai, mas com partes por preencher:» com os avisos | `PUI:431-433` | O1 |
| PRO-F-060 | Imprimir: grava se houver alterações; abre o documento; aviso com n.º de avisos | `PUI:439-445` | O1 |

### E. Recolha de dados do manual
| ID | Funcionalidade | Ref | Onda |
|---|---|---|---|
| PRO-F-061 | Recolher só os processos escolhidos (lista vazia ou `null` = todos) | `PM:337-341` | O1 |
| PRO-F-062 | Intervenientes agregados (`intervenientes` ∪ `quem`), ordenados por n.º de processos desc. e nome | `PM:346-355` | O1 |
| PRO-F-063 | Quadro de controlos-chave (processo, etapa, n.º) | `PM:357-361` | O1 |
| PRO-F-064 | Conferência com as funções de RH (normalização, partes «A / B», `null` sem funções) | `PM:363-376` | O1 |
| PRO-F-065 | Totais: processos, etapas, controlos, intervenientes, editados, próprios, ligações | `PM:378-389` | O1 |
| PRO-F-066 | Cruzamentos: pares, externos, ligações simétricas, grau, isolados, central | `PM:304-331` | O1 |
| PRO-F-067 | Cobertura: módulos × processos esperados; painéis sem narrativa; narrativas sem painel | `PM:254-297` | O1 |
| PRO-F-068 | Avisos de lacunas (7 tipos) | `PM:392-414` | O1 |

### F. Documento gerado
| ID | Funcionalidade | Ref | Onda |
|---|---|---|---|
| PRO-F-069 | Capa com logótipo, título, subtítulo fixo e ficha (versão, data, elaborado, aprovado, âmbito, adaptação) | `PM:592-604` | O1 |
| PRO-F-070 | Índice numerado só com os capítulos incluídos | `PM:502-513`, `DC:33-51` | O1 |
| PRO-F-071 | Capítulos de texto: Apresentação, Objectivo, Princípios, Disposições (só se há texto) | `PM:503-506,513` | O1 |
| PRO-F-072 | «Como ler este manual» | `PM:520-528` | O1 |
| PRO-F-073 | «Mapa dos processos» | `PM:530-536` | O1 |
| PRO-F-074 | Matriz «Intervenientes por processo» (● e totais; «(sem função em RH)») | `PM:537-542` | O1 |
| PRO-F-075 | «Matriz geral» processo × processo + «Liga-se a», processo central, isolados e quadro de pontos de encontro (com externos) | `PM:543-568` | O1 |
| PRO-F-076 | «Cobertura» (tabela + notas) | `PM:569-584` | O1 |
| PRO-F-077 | Ficha de procedimento: objectivo, intervenientes, diagrama, tabela de etapas numeradas N.i.j, marcadores «por definir» | `PM:433-463` | O1 |
| PRO-F-078 | Secção «Onde se cruza» com sentidos → e ← e legenda | `PM:453-461` | O1 |
| PRO-F-079 | Diagrama linear das etapas | `PM:425-431` | O1 |
| PRO-F-080 | «Controlos-chave» | `PM:586-589` | O1 |
| PRO-F-081 | Assinaturas (Elaborado por / Aprovado por · data) | `PM:590`, `DC:224-230` | O1 |
| PRO-F-082 | Rodapé em todas as páginas: empresa (NIF, CRC, capital social, morada, contactos) e «título · versão · data · página n / total» | `DC:140-154` | O1 |
| PRO-F-083 | Texto livre com escape HTML, parágrafos e listas | `DC:23-26` | O1 |

### G. Permissões, menu e integração
| ID | Funcionalidade | Ref | Onda |
|---|---|---|---|
| PRO-F-084 | Ecrã `proc_manual` com a tarefa `proc_editar` (e consulta separada) aplicadas **no servidor** | `js/permissoes.js:136-138`, B-04 | O1 |
| PRO-F-085 | Item de menu de topo «Manual de Procedimentos» (`book-open`) junto do Fluxo de Processos | `index_arrumado.html:110-112` | O1 |
| PRO-F-086 | A edição do manual não altera o painel do Fluxo de Processos | `PM:16-18` | O1 |
| PRO-F-087 | Fábrica partilhada com o Fluxo: uma só fonte de narrativas | `PM:85-90`, `FN` | O1 |

### H. Melhorias propostas (NÃO existem no legado — dependem de decisão)
| ID | Funcionalidade | Onda |
|---|---|---|
| PRO-F-088 | NOVO — Marca de água e rodapé «Cópia não controlada» em PDF não vigente/impresso | O2 |
| PRO-F-089 | NOVO — Versões imutáveis do manual (snapshot), com número, motivo da alteração e histórico | O2 |
| PRO-F-090 | NOVO — Submeter / aprovar / rejeitar uma versão (autor ≠ aprovador), vigência e substituição da anterior | O2 |
| PRO-F-091 | NOVO — PDF no servidor da versão vigente (guardado, com hash) e descarga | O2 |
| PRO-F-092 | NOVO — Leitura obrigatória: distribuição por unidade/cargo/colaborador, prazo, confirmação «Li e tomei conhecimento», lembretes | O2 |
| PRO-F-093 | NOVO — Consulta no Portal do Colaborador (só versão vigente) | O2 |
| PRO-F-094 | NOVO — Revisão periódica (próxima revisão, aviso a vencer) | O2 |
| PRO-F-095 | NOVO — Indicação «O ERP actualizou este processo» quando a fábrica muda depois da edição | O2 |
| PRO-F-096 | NOVO — Anexos/formulários por procedimento (ficheiros seguros) | O3 |
| PRO-F-097 | NOVO — Ligação tarefa da unidade → procedimento («Ver procedimento») | O3 |
| PRO-F-098 | NOVO — Exportação Word (.docx) | O3 (decisão Q9) |

**Contagem:** 87 funcionalidades de paridade (PRO-F-001…087, das quais 2 são correcções deliberadas: 023, 024) + 11 melhorias propostas (088…098) = **98**.

---

## 10. ESTADO NO SISTEMA NOVO (resumo para o plano)

| Peça | Existe? |
|---|---|
| Tabelas (`manuais`, `procedimentos`…), modelos, rotas, serviços, FormRequests, ecrãs, catálogo de permissões `proc_manual*` | **Não** (grep em `backend/app`, `backend/routes`, `backend/resources`, `frontend/src`, `docs`: zero ocorrências de «Manual de Procedimentos» / `proc_manual`) |
| Produção (`producao_copia/`) | **Não** tem o módulo; só o mesmo `narrativas.json` (ver abaixo) |
| Narrativas de fábrica | **Parcial**: `backend/app/Services/Gestao/Fluxos/narrativas.json` tem **14** narrativas (chaves do novo Fluxo: `lavandaria`, `projetos`, `imobilizado` em vez de `lav`, `proj`, `imob`), **sem `cruza`** e **sem** `entregaveis`, `imobiliario`, `stock`, `manutencao`, `fecho` (extraído de uma versão mais antiga do legado, `NARRATIVAS` linhas 17-200). É a única fonte e é consumida por `CatalogoFluxos::narrativa()` / `ServicoFluxos` |
| Motor de impressão (cliente) | `frontend/src/componentes/impressao` (HTML→iframe→«Guardar como PDF»; paginação própria) |
| PDF no servidor | `dompdf/dompdf ^3` (`ServicoPdfA4POS`, F7 do POS) — padrão seguro reutilizável |
| Padrão de confirmação de leitura | `ConfirmacaoAvaliacaoRH` (ciclo + colaborador) na Avaliação 360º |
| Dados que o manual consome | RH › Funções (`cargos_funcoes`: `objetivo_geral`, `responsabilidades`, `descricao`) — **já existem** |

---

## 11. BUGS, DIVERGÊNCIAS E PONTOS A DECIDIR DO LEGADO

| # | Descrição | Ref | Proposta |
|---|---|---|---|
| B-01 | `log()` grava `audit_logs` com campos que não existem no esquema (`ref_id`, `detail`, `date`; sem `module`/`timestamp`) | `PM:38`, `db_v2.js:26` | No novo: `Auditavel` + `ServicoAuditoria` (módulo «Procedimentos») |
| B-02 | «Esconder» um processo de fábrica grava uma **cópia do texto** (`limpar(fab[chave])`); depois de «Voltar a incluir» o processo aparece como EDITADO e **deixa de acompanhar** as melhorias do ERP, contra a regra R1 | `PM:193-196` | Linha-bandeira sem texto (`personalizado = false`) |
| B-03 | O separador Manual grava sempre `processos: [lista explícita]`; processos de fábrica **acrescentados depois** pelo ERP ficam excluídos em silêncio | `PUI:400,407`, `PM:229,339-341` | Guardar as **exclusões** (ou flag «todos»), não as inclusões |
| B-04 | Permissões só declarativas: `pode()` definido e não usado; `procGravarManual` inexistente | `PUI:22`, `permissoes.js:137` | Aplicar no servidor |
| B-05 | `window.EstruturaOrg` declarado e não usado | `PM:25` | Ignorar |
| B-06 | Em «Novo», a verificação de duplicado usa a lista de **visíveis**: um processo escondido com a mesma chave seria reaproveitado/sobrescrito (e reposto como visível) | `PUI:263`, `PM:161,163` | Chave única por empresa na BD (UNIQUE) + incluir escondidos na verificação |
| B-07 | `ordem` é gravada mas **não há controlo na UI**; a ordem final = ordem da fábrica, próprios por omissão no fim (9000) | `PM:164` | Permitir reordenar na lista (O1 ou O2, Q7) |
| B-08 | `cobertura()` tem listas de painéis e de módulos **escritas à mão** que divergem com o crescimento do ERP (ex.: `stock`, `fecho`, `manutencao`, `imobiliario` têm narrativa mas não painel; `estrategia` sem processo) | `PM:258-276` | Derivar do catálogo do Fluxo (`CatalogoFluxos::FLUXOS`) e do catálogo de módulos do plano |
| B-09 | O manual referencia processos que dependem de módulos que a empresa pode não ter (ex.: `imobiliario` — Gestão Imobiliária **não existe** no novo catálogo) | `FN:295` | Campo `modulo` por narrativa; esconder por omissão os de módulos fora do plano |
| B-10 | O `iframe` de pré-visualização recebe HTML por `document.write` com scripts (paginação) — no novo, a CSP `script-src 'self'` impede-o | `PUI:434`, `DC:165` | HTML estático do servidor, sem scripts, `sandbox` |

---

## 12. ÍNDICE DE FICHEIROS-FONTE LIDOS E O QUE FICOU POR LER/CONFIRMAR

**Lidos na íntegra:** `js/modules/procedimentos/procedimentos_manual.js` (1-625), `procedimentos_manual_ui.js` (1-446), `js/shared/documento_corporativo.js` (1-269).

**Lidos em blocos / por pesquisa dirigida (relevância indirecta):** `js/fluxo_narrativa.js` (cabeçalho 1-60, contagem das 19 narrativas por script sobre 21-380, cruzamentos externos por `grep`, impressão 480-532); `js/permissoes.js` (125-150, 589-606, 700-730); `js/db_v2.js` (26, 1370-1400); `js/core/modulos_registo.js` (1-30); `index_arrumado.html` (100-114, 352-366, 720-730); `js/app_v2.js` (1071, 1123 por `grep`); `js/modules/estrutura/estrutura_dados.js` (cabeçalho, `TIPOS`, `arvore()` 69-144 — usado pelo módulo irmão); `js/modules/estrutura/estrutura_tarefas.js` (`tarefas()` 158-164, `PERIODICIDADES`); `docs/manual_estrutura_organica.html` (índice e referências ao «manual»); `js/modules/rh/aval360_*.js` (só as linhas «procedimentos»/confirmação de leitura); `js/modules/estrategia/estrategia_documento_ui.js` (linhas 20-45).

**Não lidos linha a linha (declaro-o):** `js/modules/estrutura/estrutura_tarefas.js` (1180 linhas) e `estrutura_tarefas_ui.js` (873), `estrutura_ui.js` (756), `entregaveis_crm.js` (287) — só pesquisados por referências a «manual/procedimento» (nenhuma relevante além de `url_instrucoes`); estas funcionalidades já foram portadas (ADR-078/079). `js/rotas.js`, `js/modulos_manifesto.js`, `js/ui_painel_modulos.js`, `js/data/repositorios.js`, `js/modules/projectos/*`, `js/projectos_*.js`, `js/fluxo_*.js` (excepto `fluxo_narrativa.js`), `js/core/*` (excepto `modulos_registo.js`), `css/`: pesquisa por `procedimento|manual|fluxograma|norma|política|SOP|competência|descrição de funções|organização` — **nenhuma referência ao Manual de Procedimentos**; as ocorrências de «norma/política/organização» em ficheiros de fiscalidade/contabilidade são de outro âmbito. `Regras/` contém apenas PDFs contabilísticos (não abertos; sem relação).

**Por confirmar:** (a) o gancho genérico de auditoria `db_v2.js:530-542` (se audita `proc_flows`/`proc_manual`); (b) o conteúdo exacto das 19 narrativas foi **contado** e amostrado (rh, ferias) mas não transcrito etapa a etapa — a transcrição fiel faz-se por script na Fase F1 (extracção de `FN` para JSON); (c) não existem backups do legado com `proc_*`/`org_manual` (os dois backups disponíveis, de 2026-10-13 e 2026-10-19, são anteriores às tabelas criadas a 2026-11-06 — `grep` por `"proc_flows"|"proc_manual"|"org_manual"` devolveu 0), por isso **não há dados a migrar**.
