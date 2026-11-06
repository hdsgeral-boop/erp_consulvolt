# MANUAL_ORGANIZACAO_INVENTARIO — Inventário funcional do Manual da Organização (Estrutura Orgânica) do sistema legado

Origem: `C:\xampp\htdocs\ERP_CONSULVOLT_MEU\v_boss\New\v2` (HTML/JS, Dexie/IndexedDB). Análise só de leitura. Data: 2026-11-06, ramo `paridade-bd-front`. **Não altera código.** Plano em `PROCEDIMENTOS_PLANO.md`; módulo irmão em `PROCEDIMENTOS_INVENTARIO.md` (partilham o motor de documentos, as definições por empresa e a mesma lacuna: versões/aprovação).

**Convenções.** Referências `ficheiro:linha` relativas a `v_boss/New/v2/`. **EM** = `js/modules/estrutura/estrutura_manual.js` (573 linhas, lido na íntegra); **EUI** = `js/modules/estrutura/estrutura_manual_ui.js` (271 linhas, lido na íntegra); **DC** = `js/shared/documento_corporativo.js` (ver o inventário de Procedimentos §2.3); **ED** = `js/modules/estrutura/estrutura_dados.js` (lido onde o manual o consome: `TIPOS` 27-37, `arvore()` 69-144). «[sic]» = literal do legado. «BUG?» = comportamento aparente de erro a decidir.

---

## 1. VISÃO GERAL

### 1.1 O que é

O **Manual da Organização** é o documento que responde, num só sítio, a **«quem faz o quê nesta empresa»**: as unidades orgânicas, o que cada uma existe para fazer (missão, atribuições, tarefas permanentes), os cargos de cada unidade, a descrição de cada função (de RH) e o **quadro de pessoal** (lugares previstos × providos) (`EM:4-7`). **Tudo o que é estrutura é lido do organigrama e não se escreve duas vezes** (`EM:8-10`); só o que uma pessoa tem de escrever (apresentação, enquadramento, princípios, disposições) se guarda em `org_manual`, **uma linha por empresa** (`EM:10-12`, `db_v2.js:1380-1386`). É uma **leitura** da Estrutura Orgânica, não um módulo de dados novo.

Decisão de privacidade explícita do legado: o **quadro de pessoal** tem 3 modos e o nominal «é uma escolha explícita de quem imprime, nunca o que acontece por omissão» (`EM:14-16`, omissão `RESUMO`).

### 1.2 O que o legado não tem (âmbito)

Tal como em Procedimentos (ver `PROCEDIMENTOS_INVENTARIO.md` §1.2): **sem versões, sem fluxo de aprovação, sem vigência/revisão periódica, sem distribuição nem leitura obrigatória, sem histórico próprio, sem PDF no servidor, sem anexos, sem marca de água**. Há apenas os textos de capa «Elaborado por / Aprovado por / Aprovado em» e a «Versão» como texto livre (`EM:70-73, 106-112`). Estas capacidades são **melhorias** propostas (Onda 2-3), a decidir pelo utilizador.

### 1.3 Onde vive no legado

| Onde | O quê | Ref |
|---|---|---|
| Menu | «Manual da Organização» (ícone `fa-book`), 4.º item do grupo «Estrutura Orgânica» (após Unidades e Cargos, Organigrama, Mapa de Pessoal) | `index_arrumado.html:361` |
| Ecrã | `data-target="est_manual"` → `window.renderEstManual` | `js/app_v2.js:1123` |
| Módulo | `estrutura`: `ecras: { est_estrutura:'unidades', est_organigrama:'organigrama', est_mapa:'mapa-pessoal', est_manual:'manual' }` (rota `#/estrutura/manual`) | `js/core/modulos_registo.js:51` |
| Scripts | `estrutura_manual.js`, `estrutura_manual_ui.js` (v=20261106man2), depois de `documento_corporativo.js` | `index_arrumado.html:726-728` |
| Dados | Dexie 1299: `org_manual: '++id, org_company_id'` | `js/db_v2.js:1380-1386` |
| Permissões | Ecrã `est_manual` («Manual da Organização») com a tarefa `est_manual_editar`; a massa salarial exige `est_ver_salarios` (do ecrã `est_mapa`) | `js/permissoes.js:600-603` |
| Docs | O «Manual de Formação — Estrutura Orgânica» (`docs/manual_estrutura_organica.html`, 1374 linhas, índice 1 «O modelo e os ecrãs» … 8 «Exercícios de formação», com «Imprimir» e exportação .pptx) é **documentação de ajuda**, não esta funcionalidade; descreve as unidades («Missão · Atribuições … saem no manual de funções e na ficha da unidade», linha 415) e as funções («Responsabilidades — Descrição de funções. É o embrião do manual de funções da empresa», 460, 670) | fora de âmbito |

### 1.4 Como o Manual se alimenta da Estrutura Orgânica no legado

`recolher()` (`EM:139-218`) usa:

| Fonte | Chamada | Dados usados | Ref |
|---|---|---|---|
| Árvore da estrutura | `EstruturaOrg.arvore()` → `{raizes, nos, semUnidade, emps, roles, ccs, bus, massa}` | por unidade: `id, codigo, nome, tipo, apoio, activo, pai_id, missao, atribuicoes, responsavel{id,nome,funcao}, cc, un, filhos[], cargos[{id,titulo,nome,vagas,chefia,ordem,role_id,responsabilidades,ocupantes[]}], membros[], semCargo[], vagas, ocupados, excedentes, custo` | `EM:142`, `ED:69-120` |
| Funções de RH | `db.roles` da empresa | `name`, `objectivo_geral`, `responsabilidades`, `description` | `EM:143-146` |
| Empresa | `db.companies.get` | logótipo, nome, NIF, CRC, capital social, morada, contactos (capa e rodapé) | `EM:145`, `DC:121-154` |
| Tarefas permanentes | `EstruturaTarefas.tarefas()` (activas, `activo !== 0`) | `codigo, nome, descricao, periodicidade, responsavel_employee_id, position_id, unit_id` | `EM:149-153` |
| Planeamento Estratégico (opcional) | `Estrategia.planoActivo/perspectivas/objectivos` | plano `nome, ano_inicio, ano_fim, missao, visao, valores`; objectivos `nome, descricao, unit_id, perspective_id, estado` | `EM:228-255` |

**Semânticas de `arvore()` que o documento herda** (`ED:69-120`): `ocupados` por unidade = Σ `min(vagas || n.º ocupantes, n.º ocupantes)` (**limitado às vagas**); `excedentes` = Σ `max(0, ocupantes − vagas)`; filhos ordenados por `ordem` e depois nome (pt); raízes por `ordem`; cargo sem `titulo` herda o nome da função; um colaborador sem cargo da unidade vai para `semCargo`; `custo` = Σ da massa salarial por membro (`massaSalarial`).

---

## 2. ESTRUTURA DO DOCUMENTO GERADO

Capítulos, por ordem e condição (`EM:450-460`; índice só com o que entra):

| N.º | Capítulo | Entra quando | Conteúdo | Ref |
|---|---|---|---|---|
| — | **Capa** | sempre | logótipo/nome, título, subtítulo fixo «Estrutura orgânica, atribuições das unidades, descrição de funções e quadro de pessoal», **ficha**: Versão, Data, Elaborado por, Aprovado por [· data], **Âmbito** («Toda a empresa» ou nome da unidade raiz — «N unidade(s), N cargo(s)»), **Quadro de pessoal** (rótulo do modo), **Alinhado com** («{plano} (ano–ano)» se plano ligado) | `EM:540-552` |
| — | **Índice** | sempre | | |
| 1 | **Apresentação** | `apresentacao` | parágrafos; «a única parte do manual escrita na primeira pessoa» | `EM:451, 498` |
| 2 | **Objectivo e âmbito** | `objectivo` | | `EM:452, 499` |
| 3 | **Enquadramento da empresa** | `enquadramento` **ou** missão/visão/valores (próprios ou herdados) | texto; «Missão», «Visão» (parágrafos), «Valores» (lista, um por linha); nota «{missão, visão, valores} — do «{plano}».» quando algo veio do plano | `EM:453, 500-505` |
| 4 | **Orientação estratégica** | plano ligado + `incluir_objectivos` + há objectivos | «A estrutura orgânica existe para executar a estratégia. Reproduzem-se os objectivos do **{plano}** (ano–ano), indicando a unidade responsável por cada um.»; por perspectiva: tabela Objectivo / Unidade responsável («N. Nome» ou «não atribuído»); «Sem perspectiva» para os órfãos | `EM:454, 506-522` |
| 5 | **Princípios de organização** | `principios` | | `EM:455, 523` |
| 6 | **Organigrama** | `incluir_organigrama` | árvore desenhada só com CSS (caixas com nome, código, responsável ou «responsável por designar», «ocupados/vagas lugares»; tracejado roxo = órgão de apoio); nota «As caixas a tracejado são órgãos de apoio ou assessoria, que não constituem nível hierárquico. [Em cada caixa indica-se o número de lugares providos sobre os previstos.]» | `EM:456, 524-526`, `EM:297-313` |
| 7 | **Unidades orgânicas** | `incluir_unidades` | «Descrevem-se em seguida as N unidades que compõem a estrutura, pela ordem hierárquica do organigrama.» + uma **ficha por unidade** (§2.1) | `EM:457, 527-528` |
| 8 | **Descrição de funções** | `incluir_funcoes` | «Descrevem-se as N funções em exercício na estrutura. A descrição é a mesma em toda a empresa, independentemente da unidade onde a função é exercida.» + ficha por função (§2.2) + «Cargos sem função associada» | `EM:458, 529-536` |
| 9 | **Quadro de pessoal** | `quadro ≠ NENHUM` | tabela única da empresa (§2.3) | `EM:459, 537` |
| 10 | **Disposições finais** | `notas` | texto + assinaturas | `EM:460, 538` |

### 2.1 Ficha de unidade (`unidadeHtml`, `EM:316-362`)

Cabeçalho «{numero}. {nome} [(inactiva)]» (numeração hierárquica `1`, `1.1`, `1.1.1`… por ordem de leitura da árvore, `EM:163-188`). Tabela de identificação: **Código · Natureza** («{tipo} · órgão de apoio») **· Depende de** («Não depende de nenhuma unidade» ou o nome do pai) **· Responsável** («Nome (função)» ou «por designar») · [Centro de custo · Unidade de negócio]. Depois: **Missão** (ou «Por definir.»); **Atribuições** (lista: uma por linha; ou «Por definir.»); [**Objectivos estratégicos a cargo desta unidade** (Perspectiva / Objectivo+descrição)]; [**Tarefas permanentes** (Código, Tarefa+descrição, Periodicidade, Responsável)]; **Cargos**: tabela Cargo (+ marca «chefia» a azul) / Função / [Lugares / Providos / Por prover] / [Ocupantes (nominal: nomes ou «vago»)] com rodapé «Total da unidade»; [«Sem cargo atribuído: …» no nominal]; [«Encargo mensal com pessoal: {valor} Kz.» se `incluir_custos`]. Cargos ordenados: **chefia primeiro**, depois `ordem`.

Responsável de uma tarefa (`nomeDoResponsavel`, `EM:364-376`): colaborador indicado (`responsavel_employee_id`) → senão o **cargo** (`position_id`) → senão o **responsável da unidade** («regra de delegação da própria estrutura») → senão «Responsável da unidade».

### 2.2 Ficha de função (`funcaoHtml`, `EM:379-396`)

Só as **funções em uso** na estrutura incluída (`EM:190-201`), por ordem alfabética: «{i}. {nome}»; **Objectivo geral** (ou «Por definir em RH › Funções.»); **Responsabilidades** (`responsabilidades` **ou**, na falta, `description`); **Onde se exerce** (Unidade, Cargo [+chefia], Lugares, Providos); «Responsabilidades específicas do cargo» (de `org_positions.responsabilidades`, por unidade). Os cargos sem função entram numa tabela à parte (`EM:203-205, 532-535`).

### 2.3 Quadro de pessoal (`quadroHtml`, `EM:399-432`)

Linhas agrupadas por unidade («N. Nome (código)»); colunas Cargo, Função, **Lugares, Providos, Por prover** (+ **Ocupantes** no nominal); excesso mostra «+N» a vermelho; rodapé «Total» com totais e «N pessoa(s)»; nota «“Lugares” são os postos previstos no quadro; “providos” os que estão preenchidos. {Este quadro identifica pessoas: destina-se a uso interno. | Este quadro não identifica pessoas.}»; «Encargo mensal total com pessoal» se `incluir_custos`. No nominal acrescenta a linha «(sem cargo atribuído)».

### 2.4 Os três modos do quadro (`QUADRO`, `EM:63-67`)

| Chave | Rótulo | Ajuda | Mostra |
|---|---|---|---|
| `NENHUM` | «Não incluir» | «O manual fica só com a estrutura e as funções.» | sem capítulo 9 e sem colunas de lugares nas fichas |
| `RESUMO` (omissão) | «Resumo — só vagas e ocupação» | «Quantos lugares estão previstos e quantos estão preenchidos, sem nomes. É o que se entrega fora da empresa.» | contagens |
| `NOMINAL` | «Nominal — com os nomes» | «Quem ocupa cada lugar. Para uso interno: leva dados de pessoas identificadas.» | nomes dos ocupantes e dos «sem cargo» |

---

## 3. ECRÃ, CARTÕES, BOTÕES E MENSAGENS

Um ecrã (`est_manual`), **sem separadores**, layout em 2 colunas (`minmax(320px,440px) | 1fr`; ≤ 1100 px passa a 1 coluna, `EUI:64`). Estado local `st = { previsto:false, sujo:false }` (`EUI:32`). Erro de módulo: «O módulo do manual não está carregado.» (`EUI:77`).

### 3.1 Cabeçalho (`EUI:86-96`)

Título «Manual da Organização»; descrição «O documento que responde, num sítio só, a **quem faz o quê**: as unidades, as suas atribuições, a descrição de cada função e o quadro de pessoal. A estrutura vem toda do organigrama — aqui escreve-se só o que não se pode gerar.» Botões: «Ver organigrama» (`fa-sitemap`; `navigateTo('est_organigrama')`), «Pré-visualizar» (`#man-prev`), «Imprimir» (`#man-imp`, primário).

### 3.2 Coluna esquerda — cartões

| Cartão | Campos / opções (rótulo exacto) | Tipo, omissão, regra | Ref |
|---|---|---|---|
| **O documento** | «Título» · «Versão» · «Data» · «**Âmbito**» (select: «Toda a empresa» + todas as unidades «código · nome»; ajuda «Para o manual de uma direcção só.») · «Elaborado por» · «Aprovado por» · «Aprovado em» (ajuda «Quem elabora e quem aprova aparecem na capa e no espaço de assinaturas no fim.») | texto; texto; `date` (hoje se vazia); select; texto; texto; `date`. Omissões: título «Manual da Organização», versão «1.0». Sem validação | `EUI:99-114`, `EM:70-73` |
| **Quadro de pessoal** | 3 «radios» em cartão (rótulo + ajuda de `QUADRO`); o seleccionado ganha destaque azul | omissão `RESUMO`; valor inválido grava `RESUMO` | `EUI:116-121`, `EM:113` |
| **Planeamento estratégico** | caixa «**Aproveitar o plano estratégico**» («A missão, a visão e os valores vêm do plano quando não estiverem escritos aqui — assim os dois documentos nunca divergem.») · caixa «**Capítulo com os objectivos estratégicos**» («Por perspectiva, com a unidade responsável — e na ficha de cada unidade, os objectivos a seu cargo.») · linha de estado `#man-est-estado` | booleanos; omissão **ambos ligados** (`usar_estrategia`, `incluir_objectivos`) | `EUI:123-132`, `EM:77-78` |
| **O que entra** | «Organigrama desenhado» · «Ficha de cada unidade — missão, atribuições, cargos» · «Descrição de funções — objectivo e responsabilidades, de RH › Funções» · «Tarefas permanentes das unidades» · «Incluir unidades inactivas» · «**Encargo mensal com pessoal** — não ponha num manual que circula» (**só visível se `pode('est_ver_salarios')`**) | booleanos; omissão: as 4 primeiras ligadas; inactivas e custos desligados | `EUI:134-148`, `EM:79-85` |
| **Texto que se escreve à mão** | 8 textareas (2 linhas em Missão e Visão, lado a lado; 4 nas restantes): «Apresentação» · «Objectivo e âmbito» · «Enquadramento da empresa» · «Missão» · «Visão» · «Valores» · «Princípios de organização» · «Disposições finais», cada uma com ajuda e **exemplo como placeholder** | texto livre; omissão vazia. Valores: «Um por linha.» | `EUI:150-159`, `EM:37-61` |
| Acções | «Gravar» (`#man-grav`) + `#man-estado` | | `EUI:161-165` |

Textos de ajuda e exemplo de cada campo estão em `EM:37-61` (p.ex. «Apresentação»: «A mensagem de abertura — normalmente do Conselho de Administração ou da Direcção-Geral. É a única parte do manual escrita na primeira pessoa.»).

### 3.3 Coluna direita — pré-visualização (`EUI:167-176, 220-260`)

Cabeçalho «Pré-visualização» + KPI «**N** unidades · **N** cargos · **N** funções [· **ocupados**/**vagas** lugares (se quadro ≠ NENHUM)]». Estado vazio «Carregue em **Pré-visualizar** para ver o manual tal como sai na impressora.» + botão «Pré-visualizar agora» (na prática nunca aparece: `renderEstManual` chama `previsualizar()` no fim, `EUI:186`). Se há avisos: caixa «**O manual sai, mas com partes por preencher:**» com a lista. O documento aparece num `iframe` (título «Pré-visualização do manual») escrito por `document.write` — «é exactamente o que sai na impressora» (`EUI:9-10`).

**Estado do plano** (`#man-est-estado`, `EUI:229-238`): desligado → vazio; ligado mas indisponível → ícone laranja + motivo (`Opção desligada.` / «O módulo de Planeamento Estratégico não está carregado.» / «Não há plano estratégico aprovado nem em rascunho.»); ligado → verde ««{plano}» — N objectivo(s); {missão, visão, valores} vêm do plano» **ou** «; missão, visão e valores escritos aqui têm precedência sobre o plano».

### 3.4 Comportamento

- Qualquer `input|change` em qualquer `input/select/textarea` do ecrã → `sujo = true`, «Há alterações por gravar.» (`EUI:182, 189-193`); mudar o rádio do quadro actualiza o destaque (`EUI:178-181`).
- «Gravar»: `gravarDefinicoes(lerForm())` → «Gravado.» + «Manual gravado.» (`EUI:210-218`). Falha: mensagem do erro.
- «Pré-visualizar»: `previsualizar()`; erro → caixa laranja com a mensagem.
- «Imprimir»: **grava primeiro se estiver sujo** («poupa a surpresa de o documento sair com o texto antigo», `EUI:264`); `imprimir(lerForm())` (janela + `print()` aos 500 ms); com avisos: «O manual foi aberto para impressão, mas tem N aviso(s) de partes por preencher.» (tipo aviso) (`EUI:262-270`, `EM:556-560`).
- **Nota:** a caixa «Encargo mensal» só existe no DOM com `est_ver_salarios`; sem a permissão `lerForm` devolve `incluir_custos=false` e, ao gravar, **apaga** o valor que outro utilizador tenha guardado (M-03).

---

## 4. MODELO DE DADOS DO LEGADO

### 4.1 `org_manual` (Dexie 1299; **uma linha por empresa**) — `db_v2.js:1385`, `EM:69-130`

| Campo | Tipo | Omissão | Regra | Ref |
|---|---|---|---|---|
| `id` | int auto | — | PK | |
| `org_company_id` | int | empresa activa | índice; leitura tolerante a `parseInt/String` | `EM:92, 106` |
| `titulo` | string | «Manual da Organização» | | 107 |
| `versao` | string | «1.0» | texto livre | 108 |
| `data_documento` | string `AAAA-MM-DD` | `''` | | 109 |
| `elaborado_por`, `aprovado_por`, `aprovado_em` | string | `''` | | 110-112 |
| `quadro` | enum | `RESUMO` | ∈ {NENHUM, RESUMO, NOMINAL}; inválido → RESUMO | 113 |
| `usar_estrategia` | bool | true | | 114 |
| `incluir_objectivos` | bool | true | | 115 |
| `incluir_organigrama`, `incluir_unidades`, `incluir_funcoes`, `incluir_atribuicoes` | bool | true | | 116-119 |
| `incluir_inactivas` | bool | false | | 120 |
| `incluir_custos` | bool | false | exige `est_ver_salarios` **só na UI** | 121 |
| `incluir_contactos` | bool | false | **BUG? M-01:** declarado em `PADRAO` (85) e gravado (122), mas sem controlo na UI e **nunca usado** no documento | 85, 122 |
| `raiz_unit_id` | int \| null | null | âmbito (ramo); `parseInt` | 123 |
| `apresentacao`, `objectivo`, `enquadramento`, `missao`, `visao`, `valores`, `principios`, `notas` | string | `''` | os 8 `TEXTOS` | 127 |
| `criado_em/por`, `actualizado_em/por`, `is_master_data` | | | `criado_*` só ao criar; linhas `is_master_data` ignoradas | 124-129 |

### 4.2 Dados que o manual **lê** (do domínio da Estrutura, e seu equivalente no sistema novo)

| Legado | Campos usados | Novo (tabela / modelo) | Nota de dados reais (`DICIONARIO_DADOS.md`, backup) |
|---|---|---|---|
| `org_units` | `codigo, nome, tipo, pai_id, apoio, activo, ordem, missao, atribuicoes, responsavel_employee_id, cost_center_id, business_unit_id` | `unidades_organicas` (`UnidadeOrganica`: `codigo, nome, tipo, tipo_original, unidade_organica_pai_id, colaborador_responsavel_id, missao, atribuicoes, centro_custo_id, unidade_negocio_id, ordem, ativo, apoio`) | 21 linhas; **`missao` 0 % preenchida; `atribuicoes` 19 %; `colaborador_responsavel_id` 33 %** |
| `org_positions` («cargos» no ecrã) | `unit_id, role_id, titulo, vagas, chefia, ordem, responsabilidades, reporta_a_position_id` | `postos_trabalho` (`PostoTrabalho`: `unidade_organica_id, cargo_funcao_id, titulo, vagas, posto_superior_id, responsabilidades, chefia, ordem`) | 12 linhas; `cargo_funcao_id` 67 %; `titulo` 83 % (varchar 30); `responsabilidades` 8 % |
| `roles` («funções» de RH) | `name, objectivo_geral, responsabilidades, description` | `cargos_funcoes` (`CargoFuncao`: `nome, objetivo_geral, responsabilidades, descricao`) | 100 linhas; **`objetivo_geral` 1 %, `responsabilidades` 6 %** |
| `employees` | `org_unit_id, org_position_id, role_id, manager_employee_id, name` | `colaboradores` (`unidade_organica_id`, `posto_trabalho_id`, `cargo_funcao_id`, `colaborador_gestor_id`) | |
| `org_unit_tasks` | `unit_id, codigo, nome, descricao, periodicidade, responsavel_employee_id, position_id, activo, ordem` | `tarefas_unidade_organica` (`TarefaUnidadeOrganica`; periodicidades PONTUAL/DIARIA/SEMANAL/MENSAL/TRIMESTRAL/ANUAL) | já portada (ADR-078) |
| massa salarial | `massaSalarial(cid)` | `ServicoEstruturaOrg::mapaPessoal(verSalarios)` (ilíquido do último período FECHADO/VALIDADO) | exige `est_ver_salarios` no servidor |
| `companies` | logótipo, nome, NIF, CRC, capital social, morada, contactos | `GET sistema/identidade` / identidade usada em `ServicoPdfA4POS` | |
| Plano estratégico | ver §1.4 | **não existe ainda** (outro agente planeia o módulo) | integrar por contrato |

**Consequência prática (dados reais):** com os dados de produção de hoje, o manual sairia com 100 % das unidades «sem missão», ~80 % «sem atribuições», 2/3 das unidades «sem responsável» e quase todas as funções «sem objectivo nem responsabilidades» — o motor de **avisos** (§5) e uma lista de «lacunas a preencher» com ligação ao ecrã de edição são portanto **funcionalidade central**, não acessória.

### 4.3 Enumerações

- `TIPOS` de unidade (`ED:27-37`): `ORGAO_SOCIAL` «Órgão social», `DIRECCAO_GERAL` «Direcção-Geral / Administração», `DIRECCAO` «Direcção», `DEPARTAMENTO` «Departamento», `GABINETE` «Gabinete / Assessoria (apoio)», `SECCAO` «Secção / Serviço», `EQUIPA` «Equipa / Núcleo», `OUTRO` «Outra unidade» — o manual mostra `TIPOS[tipo].nome` na «Natureza».
- `QUADRO`: NENHUM · RESUMO · NOMINAL.
- Estado de objectivo estratégico: ACTIVO · SUSPENSO · CONCLUIDO · ABANDONADO (entram só ACTIVO e SUSPENSO, `EM:250`).

---

## 5. REGRAS DE NEGÓCIO

| # | Regra | Ref |
|---|---|---|
| R1 | Estrutura nunca se reescreve no manual: unidades, cargos, funções e pessoal são leitura do organigrama | `EM:8-10` |
| R2 | Quadro nominal só por escolha explícita; omissão RESUMO | `EM:14-16, 74` |
| R3 | **Âmbito**: empresa inteira ou ramo a partir de uma unidade; se a unidade escolhida já não existe, cai para a empresa inteira sem aviso (M-08) | `EM:155-160` |
| R4 | Unidades **inactivas** só entram com «Incluir unidades inactivas» (e os seus filhos inactivos idem) | `EM:161, 185` |
| R5 | **Numeração** hierárquica `1, 1.1, 1.1.1…` pela ordem da árvore (torna o documento citável «ver 3.2.1») | `EM:163-188` |
| R6 | As **funções descritas** são só as em uso na estrutura incluída: «um manual não é o catálogo de RH, é o retrato de quem a empresa tem organizado» | `EM:190-201` |
| R7 | Cargos sem função entram à parte (a descrição vive no próprio cargo) | `EM:203-205` |
| R8 | **Precedência do texto**: o que está escrito à mão no manual **ganha** ao plano estratégico («não é o sistema que a vai substituir pelas costas»); só o vazio herda; a capa/nota diz o que veio do plano | `EM:224-227, 241-243, 504-505` |
| R9 | Só objectivos ACTIVO/SUSPENSO entram («um manual descreve o que está em vigor») | `EM:246-250` |
| R10 | O índice só numera capítulos que entram | `EM:438-439, 450-460` |
| R11 | Tarefas: só `activo !== 0`; responsável por regra de delegação (colaborador → cargo → chefe da unidade) | `EM:151, 364-376` |
| R12 | Ordem dos cargos: **chefia primeiro**, depois `ordem` | `EM:318, 405` |
| R13 | «Providos» por cargo = n.º de ocupantes; «Por prover» = max(0, vagas − ocupantes); excesso em «+N» | `EM:346-352` |
| R14 | Texto livre sempre com escape HTML; lista = uma linha por item | `DC:23-26` |
| R15 | **Avisos** (não bloqueiam): sem unidades; plano pedido e não usado; objectivos sem unidade; unidades sem missão (lista até 4 + «…»); sem atribuições nem tarefas; sem responsável designado; sem cargos; funções sem objectivo/responsabilidades/descrição; unidades com mais ocupantes do que vagas | `EM:261-284` |
| R16 | Encargo mensal só com `est_ver_salarios` (na UI) e a opção ligada | `EUI:146` |
| R17 | Gravar/imprimir/pré-visualizar usam sempre o estado actual do formulário (não o gravado) | `EUI:195-208, 267` |

---

## 6. ESTADOS E TRANSIÇÕES

Não há estados do documento. Só: formulário limpo ⇄ sujo (gravar/imprimir limpam); estado do plano (desligado | ligado-indisponível | ligado-com-plano). O «ciclo de vida» do manual **não existe** (ver §1.2).

---

## 7. GERAÇÃO, IMPRESSÃO E EXPORTAÇÃO

Igual a Procedimentos (`PROCEDIMENTOS_INVENTARIO.md` §7): HTML A4 por `DocumentoCorporativo.montar`, cor `#1e3a8a`, pré-visualização em iframe, impressão por janela nova + `print()`; sem PDF servidor, sem Word, sem marca de água, sem anexos. O **organigrama** é CSS puro (`ul/li` com `::before/::after`, `display:flex`; `EM:464-495`) — «imprime sem depender de nada» — o que **não é portável para dompdf** (sem flex nem pseudo-elementos de ligação; ver plano, R2).

---

## 8. PERMISSÕES E PERFIS

| Ecrã | Tarefa | Acção declarada | Ref |
|---|---|---|---|
| `est_manual` | `est_manual_editar` — «Escrever os textos do manual e gravar as opções» | `estManualGravar` (função **inexistente** no código) | `js/permissoes.js:600-603` |
| `est_mapa` | `est_ver_salarios` — «Ver a massa salarial por unidade e cargo» (sensível) | — | `js/permissoes.js` (ecrã `est_mapa`) |

Nota do ecrã (literal): «O manual lê a estrutura do organigrama; aqui escreve-se só a apresentação, o enquadramento e os princípios. O quadro de pessoal nominal leva nomes de pessoas: é escolha de quem imprime, e a massa salarial exige est_ver_salarios.» Nenhum perfil-modelo do legado inclui `est_manual`. **Como em Procedimentos**, `pode()` só é usado para esconder a caixa dos custos; a protecção real era o menu (B-04 análogo) — no novo é no servidor. O **quadro nominal** não tem permissão própria (qualquer utilizador com o ecrã gera um documento com nomes): lacuna de privacidade a fechar (M-04).

---

## 9. LISTA NUMERADA DE FUNCIONALIDADES ATÓMICAS (MOR-F-xxx)

«Novo» = a funcionalidade equivalente **não existe** no novo; o inventário de Estrutura (ecrãs `est_estrutura/organigrama/mapa`, tarefas, entregáveis, multas, delegações) já está portado e é **fonte** do manual, não duplicado.

### A. Definições
| ID | Funcionalidade | Ref legado | Onda |
|---|---|---|---|
| MOR-F-001 | Definições do manual por empresa (uma linha), com omissões (PADRAO) | `EM:69-99` | O1 |
| MOR-F-002 | Oito textos livres: apresentação, objectivo, enquadramento, missão, visão, valores, princípios, notas — com ajuda e exemplo | `EM:37-61` | O1 |
| MOR-F-003 | Modo do quadro: NENHUM / RESUMO (omissão) / NOMINAL com rótulos e ajudas | `EM:63-67` | O1 |
| MOR-F-004 | Gravar definições: título «Manual da Organização» e versão «1.0» por omissão; quadro inválido → RESUMO; âmbito por unidade | `EM:101-130` | O1 |
| MOR-F-005 | Âmbito: empresa inteira ou ramo a partir de uma unidade | `EM:155-160` | O1 |
| MOR-F-006 | Se a unidade de âmbito deixou de existir, avisar em vez de voltar à empresa inteira em silêncio (corrige M-08) | `EM:157-160` | O1 (correcção) |

### B. Recolha
| ID | Funcionalidade | Ref | Onda |
|---|---|---|---|
| MOR-F-007 | Ler a árvore de unidades (raízes, filhos ordenados por `ordem` e nome) | `EM:142`, `ED:69-120` | O1 |
| MOR-F-008 | Ler os cargos da unidade (nome = título ou função), ocupantes, vagas, chefia | `ED:73-77` | O1 |
| MOR-F-009 | Ler as funções de RH (objectivo geral, responsabilidades, descrição) | `EM:143-146` | O1 |
| MOR-F-010 | Ler as tarefas permanentes activas por unidade | `EM:149-153` | O1 |
| MOR-F-011 | Visibilidade: inactivas só se pedido | `EM:161, 185` | O1 |
| MOR-F-012 | Numeração hierárquica das unidades (`1`, `1.1`, …) | `EM:163-188` | O1 |
| MOR-F-013 | Enriquecer cada unidade: nível, número, caminho, «depende de», cargos com função, tarefas, objectivos | `EM:175-184` | O1 |
| MOR-F-014 | Funções em uso (só as presentes na estrutura incluída), por nome (pt) | `EM:190-201` | O1 |
| MOR-F-015 | Cargos sem função associada | `EM:203-205` | O1 |
| MOR-F-016 | Totais: unidades, cargos, vagas, ocupados, pessoas, custo, em aberto | `EM:207-215` | O1 |
| MOR-F-017 | Planeamento estratégico opcional: plano activo, perspectivas, objectivos ACTIVO/SUSPENSO, herança de missão/visão/valores só se vazios, motivo quando indisponível | `EM:228-255` | O1 (por contrato; depende do módulo Estratégia) |
| MOR-F-018 | Precedência: texto à mão > plano | `EM:224-227` | O1 |
| MOR-F-019 | Avisos de lacunas (9 tipos) | `EM:261-284` | O1 |

### C. Documento gerado
| ID | Funcionalidade | Ref | Onda |
|---|---|---|---|
| MOR-F-020 | Índice só com os capítulos incluídos | `EM:450-461` | O1 |
| MOR-F-021 | Capa com ficha (versão, data, elaborado, aprovado, âmbito, quadro, alinhado com) | `EM:540-552` | O1 |
| MOR-F-022 | Capítulos de texto: Apresentação, Objectivo e âmbito, Princípios, Disposições finais | `EM:498-499, 523, 538` | O1 |
| MOR-F-023 | Enquadramento: texto + Missão + Visão + Valores (lista) + nota de origem do plano | `EM:500-505` | O1 |
| MOR-F-024 | Orientação estratégica: objectivos por perspectiva com unidade responsável; «Sem perspectiva» | `EM:506-522` | O1 |
| MOR-F-025 | Organigrama desenhado (caixas com nome, código, responsável, lugares; apoio tracejado; nota) | `EM:297-313, 524-526` | O1 |
| MOR-F-026 | Introdução das unidades «Descrevem-se em seguida as N unidades…» | `EM:527` | O1 |
| MOR-F-027 | Ficha de unidade: identificação (código, natureza, depende de, responsável (função), CC, UN) | `EM:322-328` | O1 |
| MOR-F-028 | Missão e Atribuições (lista) com «Por definir.» | `EM:329-332` | O1 |
| MOR-F-029 | Objectivos estratégicos a cargo da unidade | `EM:333-336` | O1 |
| MOR-F-030 | Tarefas permanentes (código, tarefa+descrição, periodicidade, responsável) | `EM:337-341` | O1 |
| MOR-F-031 | Responsável da tarefa: colaborador → cargo → chefe da unidade → «Responsável da unidade» | `EM:364-376` | O1 |
| MOR-F-032 | Cargos da unidade (chefia primeiro; marca «chefia»; lugares/providos/por prover conforme o quadro; rodapé «Total da unidade»; «+N» de excesso) | `EM:342-358` | O1 |
| MOR-F-033 | Quadro nominal: ocupantes («vago»), «Sem cargo atribuído» | `EM:352, 359` | O1 |
| MOR-F-034 | Encargo mensal com pessoal na unidade (se `incluir_custos`) | `EM:360` | O1 |
| MOR-F-035 | Descrição de funções: objectivo geral, responsabilidades (ou descrição), onde se exerce, responsabilidades do cargo | `EM:379-396` | O1 |
| MOR-F-036 | «Cargos sem função associada» e aviso quando nenhum cargo tem função | `EM:532-536` | O1 |
| MOR-F-037 | Quadro de pessoal da empresa (por unidade, totais, nota de privacidade conforme o modo) | `EM:399-432` | O1 |
| MOR-F-038 | Encargo mensal total no quadro | `EM:429` | O1 |
| MOR-F-039 | Assinaturas «Elaborado por / Aprovado por · data» | `EM:538`, `DC:224-230` | O1 |
| MOR-F-040 | Rodapé em todas as páginas e numeração «n / total» | `DC:140-154, 165-222` | O1 |
| MOR-F-041 | Texto livre com escape HTML, parágrafos e listas | `DC:23-26` | O1 |
| MOR-F-042 | Quadro de pessoal com contagem consistente (usar `mapaPessoal`: ocupados sem limite + «acima» à parte; corrige M-02) | `EM:350-357` vs `ED:96-97` | O1 (correcção) |

### D. Ecrã
| ID | Funcionalidade | Ref | Onda |
|---|---|---|---|
| MOR-F-043 | Cabeçalho, descrição e botões «Ver organigrama», «Pré-visualizar», «Imprimir» | `EUI:86-96` | O1 |
| MOR-F-044 | Cartão «O documento»: Título, Versão, Data (hoje se vazia), Âmbito, Elaborado por, Aprovado por, Aprovado em | `EUI:99-114` | O1 |
| MOR-F-045 | Cartão «Quadro de pessoal»: 3 opções com destaque | `EUI:116-121, 178-181` | O1 |
| MOR-F-046 | Cartão «Planeamento estratégico»: 2 opções + linha de estado (3 casos) | `EUI:123-132, 229-238` | O1 |
| MOR-F-047 | Cartão «O que entra»: organigrama, fichas, funções, tarefas, inactivas; «Encargo mensal» só com `est_ver_salarios` | `EUI:134-148` | O1 |
| MOR-F-048 | Cartão «Texto que se escreve à mão»: 8 áreas | `EUI:150-159` | O1 |
| MOR-F-049 | Gravar; indicador «Há alterações por gravar.» / «Gravado.» e aviso «Manual gravado.» | `EUI:161-165, 189-218` | O1 |
| MOR-F-050 | Pré-visualização automática ao abrir e por botão, com KPI e painel de avisos | `EUI:186, 220-260` | O1 |
| MOR-F-051 | Imprimir: grava se houver alterações; abre o documento; avisa n.º de avisos | `EUI:262-270` | O1 |
| MOR-F-052 | O encargo mensal não é apagado quando um utilizador sem permissão grava (corrige M-03) | `EUI:146, 205` | O1 (correcção) |

### E. Permissões e menu
| ID | Funcionalidade | Ref | Onda |
|---|---|---|---|
| MOR-F-053 | Ecrã `est_manual` com consulta e tarefa de edição aplicadas no servidor | `permissoes.js:600-603` | O1 |
| MOR-F-054 | Item «Manual da Organização» no grupo Estrutura Orgânica, depois de «Mapa de Pessoal» | `index_arrumado.html:361` | O1 |
| MOR-F-055 | Massa salarial/custos só com `est_ver_salarios`, validado no servidor | `permissoes.js` | O1 |
| MOR-F-056 | Quadro nominal exige permissão própria e fica registado (corrige M-04) | — | O1 (correcção) |

### F. Melhorias propostas (NÃO existem no legado — dependem de decisão)
| ID | Funcionalidade | Onda |
|---|---|---|
| MOR-F-057 | NOVO — Marca de água/rodapé «Cópia não controlada» em PDF não vigente | O2 |
| MOR-F-058 | NOVO — Versões imutáveis (snapshot da estrutura no momento), motivo da alteração e histórico | O2 |
| MOR-F-059 | NOVO — Submeter/aprovar/rejeitar; vigência; substituição da versão anterior (autor ≠ aprovador) | O2 |
| MOR-F-060 | NOVO — PDF da versão vigente gerado no servidor, guardado, com hash e descarga | O2 |
| MOR-F-061 | NOVO — Distribuição e leitura obrigatória (todos / unidade / cargo), prazo e confirmação no portal | O2 |
| MOR-F-062 | NOVO — Consulta da versão vigente no Portal do Colaborador (sempre em modo RESUMO, sem custos) | O2 |
| MOR-F-063 | NOVO — Revisão periódica e aviso «manual desactualizado» quando a estrutura mudou depois da versão vigente (n.º de alterações a unidades/cargos desde então) | O2 |
| MOR-F-064 | NOVO — Lista de lacunas a preencher com ligação directa ao ecrã de edição da unidade/função | O1/O2 |
| MOR-F-065 | NOVO — Secção opcional «Delegação e substituição» (a partir de delegações de chefia) | O3 |
| MOR-F-066 | NOVO — Coluna opcional «Entregáveis» nas tarefas permanentes | O3 |
| MOR-F-067 | NOVO — Exportação Word (.docx) | O3 (decisão Q9) |

**Contagem:** 56 funcionalidades de paridade (MOR-F-001…056; 4 são correcções deliberadas: 006, 042, 052, 056) + 11 melhorias propostas (057…067) = **67**.

---

## 10. COMO O MANUAL SE ALIMENTARÁ DE ESTRUTURA ORGÂNICA NO NOVO SISTEMA

O manual é uma **vista de leitura** sobre serviços que já existem; nada da estrutura se duplica.

| Dado do manual | Fonte no novo | Reutilizar | Nota |
|---|---|---|---|
| Árvore, responsáveis, vagas/ocupados/livres | `ServicoEstruturaOrg::arvore()` | sim (inalterado) | devolve `unidades[{…, membros, postos[{vagas, ocupados, livres}]}]` e `sem_unidade` |
| Quadro de pessoal e massa salarial | `ServicoEstruturaOrg::mapaPessoal($verSalarios)` | sim | semântica `ocupados` sem tecto + `em_aberto` + `acima`; custos só com `est_ver_salarios` |
| Funções (descrição) | `CargoFuncao` (RH › Cargos e funções) | sim | `objetivo_geral`, `responsabilidades`, `descricao` |
| Tarefas permanentes | `TarefaUnidadeOrganica` (activas) | sim | `periodicidade`, responsável (`colaborador_responsavel_id`/`posto_trabalho_id`) |
| Delegação (opcional) | `delegacoes_chefia` | futuro | MOR-F-065 |
| Identidade da empresa | identidade da empresa activa (como `ServicoPdfA4POS::identidade()`) | sim | logótipo, NIF, CRC, capital, morada |
| Planeamento Estratégico | **contrato** `FornecedorEstrategiaManual` (plano activo, perspectivas, objectivos por `unidade_organica_id`) | por referência | implementação nula enquanto o módulo não existir; o manual diz «O módulo de Planeamento Estratégico não está disponível.» |
| Organigrama desenhado | **novo gerador SVG no servidor** (a partir da árvore) | não há em PHP | o frontend tem `Organigrama.tsx`/`arvore.ts` (só cliente) |

**Fronteira:** Estrutura Orgânica é a **fonte da verdade** dos dados; o Manual da Organização é o **documento** (textos, opções, versões, aprovação, distribuição). O Manual **vive dentro de Estrutura Orgânica** (ecrã `est_manual`, módulo `estrutura`) — mas as tabelas de versões/aprovação/leitura são partilhadas com o Manual de Procedimentos (núcleo `manuais`, ver plano).

---

## 11. ESTADO NO SISTEMA NOVO

| Peça | Existe? |
|---|---|
| Ecrã `est_manual`, tarefa `est_manual_editar`, tabela, rotas, serviço | **Não.** O catálogo (`backend/resources/permissoes/catalogo.json`) tem para `estrutura` apenas `est_estrutura`, `est_organigrama`, `est_mapa`; `frontend/src/modulos/estrutura/ecras.ts` regista só esses 3; `layout/menuLegado.ts:154-156` só esses 3 |
| Fontes de dados (árvore, mapa, tarefas, funções) | **Sim**, completas (ADR-040, 064, 078, 079) |
| Notificações da categoria `estrutura` | Sim (`ServicoNotificacoes`: `estrutura` → `est_estrutura/est_organigrama/est_mapa`; a mapear também `est_manual`) |
| Painel `PainelEstrutura` | Sim (KPIs de vagas, entregáveis…); sem KPIs do manual |
| Planeamento Estratégico | **Não** (outro agente planeia-o) |

---

## 12. BUGS E PONTOS A DECIDIR DO LEGADO

| # | Descrição | Ref | Proposta |
|---|---|---|---|
| M-01 | `incluir_contactos` declarado e gravado mas sem UI e sem uso | `EM:85, 122` | Não portar |
| M-02 | «Providos» por cargo conta ocupantes sem limite; o total da unidade e o do organigrama usam `ocupados` **limitado às vagas** → totais que não batem com a soma das linhas quando há excedentes | `EM:350-357, 428` vs `ED:96-97` | Usar `mapaPessoal` (ocupados sem limite, `acima` à parte) |
| M-03 | Utilizador sem `est_ver_salarios` que grava, apaga `incluir_custos` de outro | `EUI:146, 205` | Servidor ignora o campo sem permissão e conserva o valor |
| M-04 | O quadro nominal (nomes de pessoas) não exige permissão própria nem deixa rasto | `EM:14-16`, `permissoes.js:600-603` | `est_manual_nominal` (sensível) + registo em auditoria |
| M-05 | A numeração «3.2.1» muda quando a estrutura muda; citações perdem-se | `EM:163-188` | Versões imutáveis (snapshot) |
| M-06 | A pré-visualização só mostra o estado do plano depois de pré-visualizar | `EUI:229-238` | Calcular no servidor numa só chamada |
| M-07 | Descrição de funções: `responsabilidades || description`, mas o aviso de lacunas testa também `objectivo_geral` — uma função só com `objectivo_geral` não gera aviso | `EM:278, 381` | Aviso por campo em falta |
| M-08 | Âmbito por unidade removida volta à empresa inteira sem avisar | `EM:157-160` | Avisar |
| M-09 | Objectivos CONCLUIDO/ABANDONADO nunca entram (por desenho; não configurável) | `EM:250` | Manter e dizê-lo na ficha |
| M-10 | Só `tarefas` permanentes: faltam entregáveis/metas mensuráveis (já existem na Estrutura) | `EM:337-341` | Coluna opcional (MOR-F-066) |

---

## 13. ÍNDICE DE FICHEIROS-FONTE LIDOS E O QUE FICOU POR LER/CONFIRMAR

**Lidos na íntegra:** `estrutura_manual.js` (1-573), `estrutura_manual_ui.js` (1-271), `js/shared/documento_corporativo.js` (1-269).

**Lidos em parte (o necessário ao manual):** `estrutura_dados.js` (1-40, 69-180, 288, 310; `arvore()` completa); `estrutura_tarefas.js` (`tarefas()`, `PERIODICIDADES`); `js/permissoes.js` (589-606); `js/core/modulos_registo.js` (1-30, 51); `index_arrumado.html` (352-366, 720-730); `js/db_v2.js` (1370-1400); `docs/manual_estrutura_organica.html` (índice e referências a «manual»).

**Não lidos linha a linha:** `estrutura_ui.js` (756), `estrutura_tarefas.js` (restante, 1180), `estrutura_tarefas_ui.js` (873), `entregaveis_crm.js` (287) — já portados (ADR-078/079); o manual só consome `arvore()` e `tarefas()`. `js/modules/estrategia/*` **não foi lido** (outro agente): o contrato do §1.4 foi deduzido das chamadas que `EM:228-255` faz (`planoActivo`, `perspectivas(planoId)`, `objectivos(planoId)` e os campos `nome, ano_inicio, ano_fim, missao, visao, valores, unit_id, perspective_id, estado`). **Por confirmar:** a função `massaSalarial(cid)` do legado (definição não lida) — no novo usa-se `mapaPessoal`.

Pesquisa por `procedimento|manual|fluxograma|norma|política|SOP|competência|descrição de funções|organização` em `js/modules/projectos/*`, `js/projectos_*.js`, `js/fluxo_*.js`, `js/shared/*`, `js/core/*`, `js/print_docs.js`, `css/`, `docs/`, `Regras/`: nenhuma referência ao Manual da Organização além das listadas; as expressões «descrição de funções»/«manual de funções» só aparecem no guia de formação e em comentários.
