# PROCEDIMENTOS_PLANO — Plano de importação do Manual de Procedimentos e do Manual da Organização

Complemento de `PROCEDIMENTOS_INVENTARIO.md` (98 funcionalidades PRO-F, de que 87 de paridade) e `MANUAL_ORGANIZACAO_INVENTARIO.md` (67 funcionalidades MOR-F, de que 56 de paridade). Data: 2026-11-06, ramo `paridade-bd-front`. Este documento é **só planeamento**: nenhum código do projecto nem do legado foi alterado.

Processo seguido (o mesmo do POS): inventário do legado → estado do novo e lacunas → plano → ondas (fases) → relatórios por fase. Convenção de esforço do projecto: **S** ≤ 1 dia de agente, **M** 2–4 dias, **L** ≥ 1 semana (um agente a tempo inteiro, com testes).

Convenção de escrita: prosa em português pré-acordo; **nomes de tabelas, colunas e rotas seguem a convenção do projecto** (`ativo`, `objetivo_geral`, `atualizado_em`…), porque é o que já está no esquema.

---

## 0. Ponto de partida

### 0.1 O que o legado é (resumo)

- **Procedimentos**: documento gerado de 19 narrativas de fábrica (109 etapas, 84 cruzamentos), adaptáveis por empresa, mais processos próprios; 1 ecrã, 2 separadores, 3 diálogos; 3 tabelas Dexie (`proc_manual`, `proc_flows` e, em módulo irmão, `org_manual`).
- **Manual da Organização**: documento gerado a partir de Estrutura Orgânica (unidades, cargos, funções, tarefas, quadro de pessoal) + 8 textos livres + opções; 1 ecrã sem separadores.
- **Os dois partilham** o motor `DocumentoCorporativo` (capa, índice, folha A4, rodapé «n/total», assinaturas), o padrão «uma linha de definições por empresa» e **a mesma ausência**: versões, aprovação, vigência, distribuição, leitura, histórico, PDF no servidor e anexos (ver §1.2 dos inventários).

### 0.2 O que o sistema novo já tem e vamos **reutilizar**

| Peça | Onde | Uso |
|---|---|---|
| Árvore de Estrutura, mapa de pessoal, tarefas, entregáveis, delegações, multas | `ServicoEstruturaOrg::arvore()/mapaPessoal()`, `TarefaUnidadeOrganica`, ADR-078/079 | fonte do Manual da Organização (nada se duplica) |
| Funções de RH | `CargoFuncao` (`objetivo_geral`, `responsabilidades`, `descricao`) | «Descrição de funções» e conferência dos intervenientes |
| Narrativas de fábrica | `backend/app/Services/Gestao/Fluxos/narrativas.json` (14, sem `cruza`) consumido por `CatalogoFluxos::narrativa()` | **fonte única** (a completar para 19 + `cruza`) |
| PDF seguro no servidor | `dompdf/dompdf ^3`, `ServicoPdfA4POS` (HTML sanitizado, sem rede, sem ficheiros, «Página X de Y») | gerador do PDF dos manuais |
| Multi-empresa e RLS | `PertenceEmpresa`, `IsolamentoRls->aplicarATodas()`, GRANT a `erp_app` | todas as tabelas novas |
| Auditoria | `Auditavel` + `ServicoAuditoria` | histórico técnico |
| Notificações e fila | `NotificadorRH` → `Outbox` (`notificacao.rh`, ADR-080), `ServicoNotificacoes` (categorias por módulo) | avisos de aprovação, leitura e revisão |
| Portal | `Portal.tsx` (separadores), lista branca `ExigirAreaPortal` (`rh.portal.` cobre rotas novas), `ConfirmacaoAvaliacaoRH` (padrão de confirmação) | consulta e confirmação de leitura |
| Ficheiros seguros | `ValidarUploadsSeguros`, `ValidadorUploadSeguro`, padrão `documentos_projeto` (caminho, hash SHA-256, mime, versão) | PDFs e anexos |
| UI | `CabecalhoPagina`, `TabelaApi`, `useSeparadorUrl`, `larguraModal/scrollTabela`, `BotoesExportar`, motor `componentes/impressao`, `Markdown` (só IA) | ecrãs |

### 0.3 O que **não** existe (lacunas)

Tudo o que é específico: tabelas, modelos, serviços, rotas, permissões (`proc_manual`, `est_manual`), ecrãs, menu, pesquisa, testes. O catálogo `estrutura` tem só `est_estrutura`, `est_organigrama`, `est_mapa`; `geral` tem `dashboard`, `relatorios_gestao`, `fluxo_processos`, `ia_chat`. **Planeamento Estratégico não existe** (outro agente planeia-o).

---

## 1. Decisões de arquitectura (resumo e alternativas)

| # | Decisão | Alternativa descartada | Razão |
|---|---|---|---|
| A1 | **Núcleo partilhado `manuais`** (definições por empresa e `tipo`, versões, distribuição, leituras, histórico) usado pelos **dois** manuais; o conteúdo de cada um vive no seu domínio (`procedimentos*` ou Estrutura) | duas pilhas paralelas (uma por manual) | versionamento/aprovação/leitura são idênticos; evita duplicar 5 tabelas e 3 serviços; `tipo` extensível ao Documento do Plano Estratégico (coordenar com o outro agente) |
| A2 | **O documento gera-se no servidor a partir de DADOS** (não de HTML enviado pelo cliente): `Recolha*` → array → `Renderizador*` → HTML estático → (dompdf) PDF | aproveitar `componentes/impressao` e `ServicoPdfA4POS` (cliente envia HTML) | versões reprodutíveis (snapshot), hash, marca de água, portal; o HTML não fiável do cliente é um vector que já custou uma lista branca inteira no POS |
| A3 | **Versão = snapshot imutável** (`manuais_versoes.snapshot jsonb`) criado ao submeter; o PDF vigente guarda-se com hash | recalcular o documento a pedido | a estrutura muda; «ver 3.2.1» só tem sentido se o documento ficar fixo (M-05) |
| A4 | **Edições dos procedimentos: linha por processo + etapas/cruzamentos em tabelas filhas**; esconder = linha-bandeira **sem** texto (corrige B-02) | um único `jsonb` por processo (como `proc_flows`) | consultas (matriz, cobertura, lacunas) e integridade; a fábrica continua em ficheiro |
| A5 | **Fábrica = `narrativas.json` único** (completo: 19 + `cruza` + `modulo`), partilhado com o Fluxo | duplicar o texto num ficheiro «de manual» | uma só verdade; o Fluxo ignora o que não conhece |
| A6 | O **Manual da Organização é um ecrã de Estrutura Orgânica** (`est_manual`, módulo `estrutura`, 4.º item do menu); o **Manual de Procedimentos** é ecrã do módulo `geral` (`proc_manual`), item de topo ao lado de «Fluxo de Processos» | módulo novo «Procedimentos» | respeita a hierarquia pedida e a do legado; sem novo módulo de plano/licença |
| A7 | **Segurança por defeito**: tudo com `exigir()` no controlador (`proc_manual_view`, `est_manual_view`, tarefas); portal só vê versões **VIGENTES, `visivel_portal`, e nunca NOMINAL nem com custos** | confiar no menu (legado) | B-04, M-03, M-04; contrato de rotas OWASP |
| A8 | **Texto livre continua texto simples** (parágrafos por linha em branco; uma linha por item → lista), escapado; **sem editor rico** | WYSIWYG | o projecto não tem editor rico; o legado é texto simples; evita HTML não fiável |
| A9 | PDF principal com **dompdf**, layout **por tabelas** e **SVG embebido** (organigrama e diagrama de etapas) | reproduzir o CSS flex do legado | dompdf não tem flex/grid nem pseudo-elementos de ligação (R2) |
| A10 | Geração do PDF **vigente** assíncrona via **Outbox** (`manual.gerar_pdf`, idempotente) | gerar no pedido de aprovação | ADR-080: base + ficheiro sem atomicidade; o PDF pode falhar sem anular a aprovação |

---

## 2. Modelo de dados (tabelas novas, em português)

Migrações **aditivas e idempotentes** (`Schema::hasTable`/`hasColumn`, `CREATE … IF NOT EXISTS`), com GRANT a `erp_app` e `(new IsolamentoRls(DB::connection()))->aplicarATodas()` (padrão de `2026_11_12_100000_*`). Datas de ficheiro > `2026_12_12_090000` (a última existente). **Produção tem dados reais**: nenhuma tabela existente é alterada nas Ondas 1-2; as duas colunas aditivas da Onda 3 são nuláveis. As tabelas novas ficam **fora de `database/legado/esquema.json`** (como as da Fase J; o contrato só cobre as da matriz do legado) — documentar em ADR e no dicionário.

### 2.1 `manuais` — definições por empresa e tipo (substitui `proc_manual` e `org_manual`)

| Coluna | Tipo | Nulo | Omissão / regra |
|---|---|---|---|
| `id` | bigserial PK | não | |
| `empresa_id` | bigint FK `empresas` (restrict) | não | RLS |
| `tipo` | varchar(20) | não | CHECK ∈ (`PROCEDIMENTOS`,`ORGANIZACAO`) — extensível |
| `titulo` | varchar(200) | não | «Manual de Procedimentos» / «Manual da Organização» |
| `versao_rascunho` | varchar(20) | não | `'1.0'` (texto da capa; na Onda 2 propõe-se o próximo número) |
| `data_documento` | date | sim | |
| `elaborado_por`, `aprovado_por` | varchar(150) | sim | texto de capa (paridade); preenchidos pelo fluxo real na Onda 2 |
| `aprovado_em` | date | sim | |
| `textos` | jsonb | não | `{}`; chaves permitidas por tipo (Proc.: `apresentacao, objetivo, principios, notas`; Org.: + `enquadramento, missao, visao, valores`); cada valor ≤ 20 000 caracteres |
| `opcoes` | jsonb | não | `{}`; Proc.: `incluir_diagrama, incluir_matriz, incluir_controlos, incluir_legenda, incluir_cruzamentos, incluir_cobertura, conferir_funcoes, processos_excluidos[]` (guarda **exclusões**, corrige B-03); Org.: `quadro ∈ {NENHUM,RESUMO,NOMINAL}, usar_estrategia, incluir_objetivos, incluir_organigrama, incluir_unidades, incluir_funcoes, incluir_atribuicoes, incluir_inativas, incluir_custos` (**sem** `incluir_contactos`, M-01). Validado por FormRequest por tipo; CHECK `jsonb_typeof(opcoes)='object'` |
| `unidade_organica_raiz_id` | bigint FK `unidades_organicas` (set null) | sim | âmbito do Manual da Organização |
| `periodicidade_revisao_meses` | smallint | sim | CHECK 1–60 (O2) |
| `proxima_revisao` | date | sim | (O2) |
| `versao_vigente_id` | bigint FK `manuais_versoes` (set null, adicionada depois) | sim | (O2) |
| `criado_por`, `atualizado_por` | varchar(100) | sim | |
| `criado_em`, `atualizado_em` | timestamptz | não | |

**UNIQUE** `(empresa_id, tipo)`. Modelo `Manual` (`Base/ManualBase` + final), `Auditavel` (`moduloAuditoria = 'Manuais'`), `PertenceEmpresa`.

### 2.2 `procedimentos` — edições e processos próprios (substitui `proc_flows`)

| Coluna | Tipo | Nulo | Regra |
|---|---|---|---|
| `id` | bigserial PK | não | |
| `empresa_id` | bigint FK | não | RLS |
| `chave` | varchar(40) | não | CHECK `~ '^[a-z0-9_]{1,40}$'`; **UNIQUE `(empresa_id, chave)`** (corrige B-06); as chaves de fábrica e as externas (`estrutura_manual`, `estrategia_plano`) são **reservadas** a `origem = 'FABRICA'` (validado no serviço) |
| `origem` | varchar(8) | não | CHECK ∈ (`FABRICA`,`PROPRIO`) |
| `personalizado` | boolean | não | `false`: linha só-bandeira (esconder) sem texto; `true`: o texto desta empresa substitui o da fábrica (=EDITADO) |
| `oculto` | boolean | não | `false` |
| `titulo` | varchar(200) | sim | CHECK `NOT personalizado OR titulo IS NOT NULL` |
| `objetivo` | text | sim | |
| `intervenientes` | jsonb | não | `[]` (≤ 30 strings de ≤ 120) |
| `ordem` | integer | não | `0` (agora editável: reordenar) |
| `fluxo_id` | varchar(30) | sim | id do painel no `CatalogoFluxos` (`rh`, `lavandaria`…), para «Ver no Fluxo» |
| `modulo` | varchar(30) | sim | módulo que o processo pressupõe (p.ex. `imobiliario`) — copiado da fábrica |
| `base_hash` | char(64) | sim | SHA-256 do texto de fábrica quando se editou (deteta «o ERP actualizou este processo», PRO-F-095) |
| `criado_por`, `atualizado_por`, `criado_em`, `atualizado_em` | | | |

CHECK `origem = 'FABRICA' OR personalizado` (um próprio é sempre personalizado). **Hard delete** (repor = apagar a linha; não há `SoftDeletes`: as versões guardam o seu snapshot).

### 2.3 `procedimentos_etapas`

`id`; `empresa_id` (RLS); `procedimento_id` FK cascade; `ordem` smallint (≥ 1); `nome` varchar(200) **não nulo**; `quem` varchar(200); `descricao` text; `controlos` text; `resultado` text. **UNIQUE `(procedimento_id, ordem)`**; índice `(empresa_id, procedimento_id)`. A gravação **substitui o conjunto** numa transacção (semântica do legado: o array inteiro).

### 2.4 `procedimentos_cruzamentos`

`id`; `empresa_id`; `procedimento_id` FK cascade; `ordem` smallint; `com_chave` varchar(40) não nulo (outro processo **ou** externo; validado no serviço contra fábrica ∪ próprios ∪ `EXTERNOS`); `onde` varchar(200) (nome da etapa deste); `o_que` text. Índice `(empresa_id, procedimento_id)` e `(empresa_id, com_chave)`.

### 2.5 `manuais_versoes` (Onda 2)

| Coluna | Tipo | Nota |
|---|---|---|
| `id`, `empresa_id`, `manual_id` FK | | |
| `numero` | varchar(20) | `1.0`, `1.1`, `2.0`… **UNIQUE `(manual_id, numero)`** |
| `estado` | varchar(14) | CHECK ∈ (`EM_APROVACAO`,`REJEITADA`,`VIGENTE`,`SUBSTITUIDA`) |
| `motivo_alteracao` | text | obrigatório a partir da 2.ª versão (≥ 3 caracteres) |
| `snapshot` | jsonb não nulo | o resultado completo da recolha (opções, textos, processos/unidades, identidade da empresa, totais, avisos) |
| `hash_snapshot` | char(64) | SHA-256 do `snapshot` canónico |
| `pdf_caminho` varchar(500), `pdf_hash_sha256` char(64), `pdf_tamanho_bytes` bigint, `pdf_paginas` smallint | sim | preenchidos pelo evento `manual.gerar_pdf` |
| `visivel_portal` | boolean | CHECK: não pode ser `true` se `snapshot->opcoes->>quadro = 'NOMINAL'` ou `incluir_custos` |
| `leitura_obrigatoria` boolean; `prazo_leitura` date | | |
| `submetido_por` varchar(100), `submetido_por_utilizador_id`, `submetido_em` timestamptz | | |
| `decidido_por`, `decidido_por_utilizador_id`, `decidido_em`, `motivo_decisao` text | sim | |
| `vigente_desde`, `vigente_ate` date | sim | CHECK `estado <> 'VIGENTE' OR (decidido_em IS NOT NULL AND vigente_desde IS NOT NULL)` |

Índices únicos parciais: **um só `VIGENTE` por manual** (`WHERE estado='VIGENTE'`) e **um só `EM_APROVACAO`** (`WHERE estado='EM_APROVACAO'`). Endurecimento recomendado: *trigger* `BEFORE UPDATE` que recusa alterar `snapshot`, `hash_snapshot`, `numero`, `manual_id` (imutabilidade em PostgreSQL, S).

### 2.6 `manuais_destinatarios` (Onda 2)

`id`; `empresa_id`; `versao_id` FK cascade; `ambito` varchar(12) CHECK ∈ (`TODOS`,`UNIDADE`,`POSTO`,`COLABORADOR`); `referencia_id` bigint (unidade, posto ou colaborador); `incluir_subunidades` boolean (só `UNIDADE`). CHECK `ambito = 'TODOS' OR referencia_id IS NOT NULL`. UNIQUE `(versao_id, ambito, coalesce(referencia_id,0))`.

### 2.7 `manuais_leituras` (Onda 2)

`id`; `empresa_id`; `versao_id` FK; `colaborador_id` FK (restrict); `utilizador_id`; `lido_em` timestamptz; `hash_snapshot` char(64) (o que foi lido); `origem` varchar(10) (`PORTAL`/`ECRA`). **UNIQUE `(versao_id, colaborador_id)`** (idempotente). Padrão: `ConfirmacaoAvaliacaoRH`.

### 2.8 `manuais_historico` (Onda 2)

`id`; `empresa_id`; `manual_id`; `versao_id` (sim); `evento` varchar(30) (`SUBMETIDA`, `APROVADA`, `REJEITADA`, `SUBSTITUIDA`, `PDF_GERADO`, `PDF_DESCARREGADO`, `DISTRIBUIDA`, `LIDA`, `REVISAO_PEDIDA`…); `utilizador` varchar(100); `detalhe` jsonb; `criado_em`. Só inserção. É o **histórico visível ao utilizador** (o `Auditavel` fica o técnico).

### 2.9 `procedimentos_anexos` (Onda 3)

`id`; `empresa_id`; `procedimento_id` (e opcional `etapa_id`); `titulo` varchar(200); `caminho` varchar(500); `mime` varchar(100); `tamanho_bytes` bigint; `hash_sha256` char(64); `criado_por`, `criado_em`. Upload pelo validador central (PDF sem JavaScript, imagens, XLSX/DOCX sem macros); mesmo disco de `documentos_projeto`.

### 2.10 Colunas aditivas (Onda 3)

`tarefas_unidade_organica.procedimento_id` (nulo, FK `procedimentos` set null; **em complemento** de `url_instrucoes`, que fica) e `assuntos_metodo_trabalho.procedimento_id` (nulo, FK set null). Migração ensaiada na cópia (`erp_consulvolt` no PostgreSQL local) antes de produção.

### 2.11 Contagem

**Tabelas do legado: 3** (`proc_manual`, `proc_flows`, `org_manual`) **+ a fonte em código `NARRATIVAS`**. **Tabelas novas: 9** (`manuais`, `procedimentos`, `procedimentos_etapas`, `procedimentos_cruzamentos` na Onda 1; `manuais_versoes`, `manuais_destinatarios`, `manuais_leituras`, `manuais_historico` na Onda 2; `procedimentos_anexos` na Onda 3) **+ 2 colunas aditivas** (Onda 3).

### 2.12 Mapeamento legado → novo

| Legado | Novo |
|---|---|
| `proc_manual` (1 linha/empresa) | `manuais` `tipo='PROCEDIMENTOS'` (`textos.apresentacao/objetivo/principios/notas`; flags em `opcoes`; `processos` → `opcoes.processos_excluidos`) |
| `org_manual` | `manuais` `tipo='ORGANIZACAO'` (8 textos; `quadro`, flags; `raiz_unit_id` → `unidade_organica_raiz_id`) |
| `proc_flows` (1 linha/edição, arrays embutidos) | `procedimentos` + `procedimentos_etapas` + `procedimentos_cruzamentos` |
| `NARRATIVAS` (código) | `narrativas.json` completo (19, `cruza`, `modulo`) |
| `audit_logs` «Editar procedimento»… | `Auditavel` + `manuais_historico` |

---

## 3. Backend

### 3.1 Estrutura de ficheiros (proposta)

```
app/Models/Base/{Manual,VersaoManual,DestinatarioManual,LeituraManual,HistoricoManual,Procedimento,EtapaProcedimento,CruzamentoProcedimento,AnexoProcedimento}Base.php  (+ classes finais)
app/Contracts/FornecedorEstrategiaManual.php   app/Services/Manuais/FornecedorEstrategiaNulo.php
app/Services/Manuais/
    ServicoManuais.php                 definições (get/put por tipo), validação de textos/opções
    ServicoVersoesManual.php           submeter / aprovar / rejeitar / substituir (transacção + lockForUpdate)
    ServicoDistribuicaoManual.php      destinatários, resolução (unidade+subunidades, posto, colaborador), pendentes, lembretes
    ServicoLeituraManual.php           confirmar leitura (idempotente), relatório
    ServicoPdfManual.php               dompdf (reutiliza as opções seguras de ServicoPdfA4POS, extraídas)
    Documento/MontadorDocumentoCorporativo.php   capa, índice, rodapé, assinaturas, CSS (porte de documento_corporativo.js)
    Documento/IndiceCapitulos.php  TextoLivre.php (par/linhas/comoLista/escape)  MarcaAgua.php
    Documento/GeradorOrganigramaSvg.php  GeradorDiagramaEtapas.php
    Recolha/RecolhaProcedimentos.php   Recolha/RecolhaOrganizacao.php
    Renderizadores/RenderizadorProcedimentos.php   Renderizadores/RenderizadorOrganizacao.php
app/Services/Procedimentos/
    ServicoProcedimentos.php           listar (fábrica ∪ edições ∪ próprios), gravar, repor, ocultar, mostrar, reordenar
    CatalogoFabricaProcedimentos.php   lê narrativas.json, aplica `modulo`/plano, `base_hash`
    ServicoCoberturaProcedimentos.php  ServicoCruzamentosProcedimentos.php  ConferenciaFuncoes.php
app/Http/Controllers/Api/Manuais/{ManuaisController,VersoesManualController,DistribuicaoManualController,PortalManuaisController}.php
app/Http/Controllers/Api/Procedimentos/ProcedimentosController.php
app/Http/Requests/Manuais/…   app/Http/Resources/Manuais/…   app/Console/Commands/ManuaisLembretesCommand.php
routes/api/manuais.php             incluído no grupo com empresa activa (como fj_/fk_)
```

### 3.2 Rotas (prefixo `/api`; nomes `procedimentos.*`, `manuais.*`, `rh.portal.manuais.*`)

| Método e rota | Permissão (`exigir`) | Efeito | Onda |
|---|---|---|---|
| `GET procedimentos` | `proc_manual_view` | lista (origem FABRICA/EDITADO/PROPRIO, nº etapas/controlos, `tem_painel`, `modulo` disponível) | O1 |
| `GET procedimentos/opcoes` | `proc_manual_view` | catálogo para cruzamentos (processos + externos) e papéis das funções de RH | O1 |
| `GET procedimentos/cobertura` | `proc_manual_view` | painéis sem narrativa, módulos por cobrir (derivado do `CatalogoFluxos` e do plano) | O1 |
| `GET procedimentos/{chave}` | `proc_manual_view` | processo com etapas e cruzamentos | O1 |
| `POST procedimentos` | `proc_editar` | novo processo próprio (chave gerada ≤ 30; 1 etapa «Primeira etapa»); `PROCEDIMENTO_JA_EXISTE` | O1 |
| `PUT procedimentos/{chave}` | `proc_editar` | gravar (substitui etapas e cruzamentos); validações PRO-F-012…014; `lockForUpdate` | O1 |
| `DELETE procedimentos/{chave}` | `proc_editar` | repor (fábrica) ou apagar (próprio) | O1 |
| `POST procedimentos/{chave}/ocultar` · `/mostrar` | `proc_editar` | bandeira `oculto` (sem copiar texto) | O1 |
| `PUT procedimentos/ordem` | `proc_editar` | reordena (lista de chaves) | O1 |
| `GET manuais/{tipo}` | `proc_manual_view` \| `est_manual_view` | definições + estado (versão vigente, rascunho) | O1 |
| `PUT manuais/{tipo}` | `proc_editar` \| `est_manual_editar` | gravar definições (campos de privacidade filtrados por permissão: M-03, M-04) | O1 |
| `POST manuais/{tipo}/pre-visualizar` | view | corpo = formulário actual (não gravado) → `{html, avisos, totais, estrategia}`; `throttle:pesado`; HTML sem scripts | O1 |
| `GET manuais/{tipo}/pdf` | view | PDF do **rascunho** com marca de água «não controlado»; `throttle:pesado` | O1 |
| `GET manuais/{tipo}/versoes` · `GET …/versoes/{v}` | view | lista/detalhe (sem snapshot por omissão) | O2 |
| `POST manuais/{tipo}/versoes` | `proc_editar` \| `est_manual_editar` | **submeter** (cria snapshot `EM_APROVACAO`; `motivo_alteracao`) | O2 |
| `POST manuais/{tipo}/versoes/{v}/aprovar` · `/rejeitar` | `proc_publicar` \| `est_manual_publicar` | decide (autor ≠ aprovador; rejeitar exige motivo); aprovar → `VIGENTE`, a anterior → `SUBSTITUIDA`, regista evento `manual.gerar_pdf` | O2 |
| `GET manuais/{tipo}/versoes/{v}/pdf` | view | PDF guardado (vigente: cópia controlada; outras: marca de água) | O2 |
| `PUT manuais/{tipo}/versoes/{v}/distribuicao` | `proc_publicar` \| `est_manual_publicar` | destinatários, `visivel_portal`, `leitura_obrigatoria`, prazo | O2 |
| `GET manuais/{tipo}/versoes/{v}/leituras` · `POST …/lembrar` | view / publicar | quem leu / não leu; lembrete (notificação) | O2 |
| `GET manuais/{tipo}/historico` | view | eventos | O2 |
| `GET rh/portal/manuais` | **sem permissão de ecrã por desenho** (colaborador ligado) | versões vigentes, `visivel_portal`, de que sou destinatário, com «lida?» | O2 |
| `GET rh/portal/manuais/{versao}/pdf` · `POST …/ler` | idem | descarga (registada) e confirmação de leitura (idempotente) | O2 |

`{tipo}` ∈ `procedimentos|organizacao`, validado por `whereIn`; a permissão aplicada depende do tipo (um `match` no controlador). Rotas do portal usam o prefixo `rh.portal.` já presente na lista branca de `ExigirAreaPortal` (nada a acrescentar lá; confirmar em `ExigirAreaPortalTest`).

### 3.3 Classificação nos testes de rotas

- **`OwaspControloAcessoTest`**: as rotas de gestão chamam `$this->exigir(…)` → 403 sem permissão (apanhadas pelo padrão `AUTORIZACAO`). As **3 rotas do portal** entram em `SEM_PERMISSAO_POR_DESENHO` com motivo («portal do colaborador: só versões em que sou destinatário; `exigirColaborador` no serviço»), como `GET api/rh/portal/aprovacoes`.
- **`AcessoCruzadoGeradoTest`**: automático (a barreira de empresa é anterior ao controlador). Garantir que os `{v}` e `{chave}` usam `whereNumber`/`where` com regex que o teste consiga satisfazer (ou contam como inconclusivas).
- **`IsolamentoRlsContratoTest`**: toda a tabela com `empresa_id` tem política RLS → cobre as 8 tabelas com `empresa_id`.
- **`ExigirAreaPortalTest`** (unit): afirmar que `rh.portal.manuais.*` passa e `manuais.*` não.
- **`SistemaMenuTest` / `ServicoPermissoesTest` / contrato do catálogo**: actualizar para os ecrãs novos.
- Regra do projecto: as acções destrutivas e de aprovação são também **tarefas sensíveis** no catálogo.

### 3.4 Permissões novas (catálogo **editado à mão**; nunca regenerar do `v_boss`)

| Ecrã (módulo) | Tarefa | Sensível | Descrição |
|---|---|---|---|
| `proc_manual` («Manual de Procedimentos», módulo `geral`; vista `proc_manual`) | `proc_editar` | não | Adaptar as narrativas, criar processos próprios, esconder/repor e escrever o manual |
| | `proc_publicar` | **sim** | Submeter, aprovar e publicar versões; definir distribuição e leitura obrigatória |
| `est_manual` («Manual da Organização», módulo `estrutura`) | `est_manual_editar` | não | Escrever os textos e gravar as opções (nome do legado) |
| | `est_manual_publicar` | **sim** | Submeter, aprovar e publicar versões; distribuição e leitura |
| | `est_manual_nominal` | **sim** | Incluir o quadro de pessoal nominal (nomes) e gerar documentos com pessoas identificadas |
| (existente) `est_mapa` | `est_ver_salarios` | sim | **Reutilizada**: sem ela, `incluir_custos` é ignorado no servidor |

Notas do ecrã (literais do legado, adaptados): «As narrativas vêm do Fluxo de Processos e podem ser adaptadas à empresa. Editar a narrativa muda o manual, nunca o que o painel de acompanhamento mede.» / «O manual lê a estrutura do organigrama; aqui escreve-se só a apresentação, o enquadramento e os princípios. O quadro nominal leva nomes de pessoas.»

**Segregação** (`segregacao` do catálogo): `proc_editar` × `proc_publicar` («Escreve e aprova o mesmo manual»), `est_manual_editar` × `est_manual_publicar`. A regra autor ≠ aprovador impõe-se também **no serviço** (`AUTO_APROVACAO`), com excepção configurável para empresas de um só utilizador (Q4).

**Perfis-modelo** (`modelos`; aplicar com `ServicoPerfis::actualizarModelos` e `php artisan erp:perfis:actualizar-modelos`, só acrescenta, nunca retira): «Gestor de Estrutura Orgânica» + `est_manual_view`, `est_manual_editar`; «Direcção» + `proc_manual_view`, `est_manual_view`, `proc_publicar`, `est_manual_publicar`; «Técnico de RH» + `proc_manual_view`, `est_manual_view`; «Controller / Gestão Orçamental» + `proc_manual_view`; «Auditor / Consulta» + as duas vistas; «Colaborador (só portal)»: nada (o portal é automático para utilizadores ligados a um colaborador). Plano/licença: `geral` e `estrutura` já estão no catálogo de módulos (`estrutura` liga-se a `rh`, migração `2026_11_10_090000`); **confirmar** que `geral` é sempre incluído (`ModulosPlano`/`CatalogoModulosPlanoTest`).

### 3.5 Notificações, fila, agendamento

- `NotificadorRH` (Outbox, `notificacao.rh`, nunca falha a operação) com categoria nova `manuais` em `ServicoNotificacoes` (rótulo «Manuais», vistas `proc_manual`, `est_manual`). Eventos: versão submetida → aprovadores (`proc_publicar`/`est_manual_publicar`); decidida → autor; vigente → destinatários (leitura obrigatória, `link` para o portal); lembrete → não leitores; revisão a vencer → `proc_publicar`/`est_manual_publicar`. As notificações dirigidas chegam mesmo sem permissão para o módulo (é quem tem de agir).
- Evento Outbox `manual.gerar_pdf` (idempotente por `manual:pdf:{versao}`) → manipulador gera o PDF, grava ficheiro e hash e emite `PDF_GERADO`; falha → recuo exponencial do outbox, MORTO + aviso aos administradores (ADR-080).
- Agendador: `erp:manuais:lembretes` (diário; `withoutOverlapping`, `onOneServer`; por tenant via `ContextoTenant::porTenant`): leituras em atraso e revisões a vencer (30 dias antes por omissão).

### 3.6 Auditoria

`Auditavel` em todos os modelos (módulo «Manuais»). Eventos de negócio (descarregar PDF, ler, aprovar) em `manuais_historico` **e** `ServicoAuditoria`. Substitui `log()` do legado (B-01).

---

## 4. Versionamento, aprovação, vigência e leitura obrigatória (Onda 2)

```
(rascunho vivo em `manuais` + procedimentos/estrutura)
        │ Submeter (proc_editar | est_manual_editar) — cria snapshot, motivo_alteracao
        ▼
  EM_APROVACAO ──Rejeitar (motivo)──▶ REJEITADA        (nada muda; o autor corrige o rascunho e volta a submeter)
        │ Aprovar (proc_publicar | est_manual_publicar; ≠ autor)
        ▼
     VIGENTE ──nova versão aprovada──▶ SUBSTITUIDA
        │ (evento manual.gerar_pdf → PDF guardado + hash)
        ▼
   distribuição → notificações → leituras (confirmação no portal) → lembretes
```

Regras: um `VIGENTE` e um `EM_APROVACAO` por manual (índices parciais); numeração `major.minor` (propor `+0.1`; o aprovador pode subir a major); `motivo_alteracao` obrigatório a partir da 2.ª; aprovar **revalida** o snapshot (mesmo `hash_snapshot` que o submetido — qualquer alteração ao rascunho entre submeter e aprovar não conta); `visivel_portal` recusado se NOMINAL/custos; **revisão periódica**: `proxima_revisao` = `vigente_desde + periodicidade`; aviso 30 dias antes; versão vencida mostra «Revisão em atraso» (não bloqueia). **Manual da Organização**: além disso, indicador «desactualizado» = nº de unidades/cargos alterados desde `vigente_desde` (lido da auditoria/`atualizado_em`), MOR-F-063. **Leitura obrigatória**: destinatários resolvidos no momento de distribuir **e** ao ver relatório (quem entrou depois também deve ler: `leitura_obrigatoria` calcula pendentes dinamicamente por unidade/posto); confirmação grava `hash_snapshot`; relatório «quem leu / quem falta» (para RH e para a chefia da unidade).

---

## 5. Geração dos documentos

1. **Recolha** (`Recolha*`): produz o mesmo array que o `recolher()` do legado, mas **no servidor** — Procedimentos: processos (fábrica ∪ edições ∪ próprios, sem escondidos/excluídos), intervenientes, controlos, cruzamentos (pares, ligações, grau, isolados, central), cobertura, conferência com `cargos_funcoes`, totais, avisos (7); Organização: árvore (`arvore()`), unidades numeradas (`1`, `1.1`…), cargos com função, tarefas, funções em uso, cargos sem função, totais (`mapaPessoal`), estratégia (via `FornecedorEstrategiaManual`), avisos (9).
2. **Renderização** (`Renderizador*`): HTML **estático** (sem scripts) com a ordem e as condições de capítulos dos inventários §2, texto livre escapado (`TextoLivre`: `par`, `linhas`, `comoLista` — com testes de paridade de parágrafos/listas), numeração N.i.j, marcadores «por definir».
3. **Pré-visualização**: o mesmo HTML num `iframe sandbox=""` + `srcDoc` (sem scripts, sem same-origin; CSP `script-src 'self'` respeitada). A paginação «n / total» **não** é calculada no cliente: só existe no PDF.
4. **PDF** (`ServicoPdfManual`): dompdf **3** com as opções de `ServicoPdfA4POS` (sem PHP, JS, rede nem acesso a ficheiros; só imagens `data:`) extraídas para uma classe partilhada (`Support/Pdf/OpcoesDompdfSeguras`) — **coordenar com a linha POS**. CSS próprio de PDF (tabelas; fonte **DejaVu Sans/Serif** — inclui `▶ ● → ←`), capa de página inteira, rodapé em todas as páginas com a identidade da empresa (da BD, não do pedido) e «título · versão · data · Página X de Y» desenhado no canvas (`page_script`).
5. **Organigrama e diagrama de etapas**: SVG gerado no servidor (`GeradorOrganigramaSvg`: árvore «tidy», caixas com nome, código, responsável, «ocupados/vagas», tracejado roxo = apoio; `GeradorDiagramaEtapas`: caixas numeradas em linhas de até 6, com setas) embebido como `data:image/svg+xml;base64` (já aceite pelo sanitizador; marcas perigosas recusadas). O ecrã **Organigrama** do cliente não muda; testes de *snapshot* do SVG evitam divergência de layout (R3).
6. **Marca de água / cópia não controlada**: PDF de rascunho ou de versão não vigente → texto diagonal «RASCUNHO — NÃO CONTROLADO» / «VERSÃO SUBSTITUÍDA»; PDF da vigente → rodapé «Cópia controlada apenas em formato digital; impressa ou descarregada, é cópia não controlada — consulte a versão vigente no ERP». Texto final a validar (Q10).
7. **Ficheiros**: PDF vigente em `manuais/{empresa_id}/{manual_id}/{numero}.pdf` no mesmo disco de `documentos_projeto`; hash SHA-256 e tamanho na BD; descarga por rota autenticada (nunca URL pública), registada. Tamanho máximo do HTML recebido na pré-visualização: o corpo é só o formulário (< 200 KB), não HTML.
8. **Anexos e Word**: anexos só na Onda 3 (`procedimentos_anexos`); Word (.docx) fora de âmbito até decisão (Q9).

### 5.1 Fábrica de narrativas (pré-requisito da Onda 1)

`narrativas.json` tem hoje 14 narrativas, **sem `cruza`**, com chaves do Fluxo novo (`lavandaria`, `projetos`, `imobilizado`). Acção (F0/F1): script `ferramentas/procedimentos/extrair_narrativas.mjs` que avalia `NARRATIVAS` de `v_boss/New/v2/js/fluxo_narrativa.js` (numa `vm` isolada) e escreve o JSON novo com **19 narrativas / 109 etapas / 84 cruzamentos**, mapeando `lav→lavandaria`, `proj→projetos`, `imob→imobilizado` e acrescentando `fluxo_id` (painel, quando existe) e `modulo` (p.ex. `imobiliario` → módulo inexistente no plano). Mantém-se a **ordem das etapas** (o `ServicoFluxos` injecta a narrativa por posição). Teste de contrato: 19/109/84; todo o `cruza.com` existe na fábrica ou nos `EXTERNOS`; o teste existente `GestaoFluxosTest` continua verde (risco R4).

---

## 6. Portal do Colaborador (Onda 2)

Novo separador **«Manuais»** em `Portal.tsx` (rótulo `Manuais (n)` com n = por ler obrigatórios), `useSeparadorUrl` com chave `manuais`. Lista: título, tipo, versão, vigente desde, «Leitura obrigatória até …», estado (Lida/Por ler, com texto e ícone, não só cor), botões «Abrir» (PDF no separador do navegador) e **«Li e tomei conhecimento»** (padrão da avaliação 360º). Regras: só versões `VIGENTE` + `visivel_portal`; só se sou destinatário (`TODOS`, a minha unidade [e superiores quando `incluir_subunidades`], o meu posto ou eu); o Manual da Organização é sempre RESUMO sem custos. `resumo` do portal ganha `manuais_por_ler`; `ServicoInicio::pendentes()` ganha `manuais_leitura_pendente`. Sem permissão especial (cada colaborador só vê o seu, como `rh_portal_usar`); gestores vêem o relatório no ecrã de gestão.

---

## 7. Frontend

### 7.1 Ecrãs e componentes

| Ecrã / componente | Ficheiro (proposta) | Conteúdo |
|---|---|---|
| **Manual de Procedimentos** (`proc_manual`) | `frontend/src/modulos/geral/procedimentos/ManualProcedimentos.tsx` (registo em `modulos/geral/ecras.ts`) | `CabecalhoPagina` «Manual de Procedimentos» + `Tabs`: **Processos**, **Manual**, e (O2) **Versões** e **Leitura**; separador na URL (`useSeparadorUrl`, omissão `manual`) |
| Separador Processos | `…/ListaProcessos.tsx`, `EditorProcesso.tsx`, `EditorEtapas.tsx`, `EditorCruzamentos.tsx`, `CoberturaProcessos.tsx` | lista com `Tag` de origem («do ERP», «adaptado», «próprio»; «com painel») ; editor com `Form` (`Form.List` para etapas e cruzamentos; botões ↑ ↓ ✕ com `aria-label`); aviso `Alert` azul; «Repor/Apagar», «Esconder», «Gravar»; `Modal` de novo processo (chave gerada à vista); `Popconfirm`/`Modal.confirm` com os textos do legado; escondidos com «Voltar a incluir»; reordenar |
| Separador Manual | `…/DefinicoesManual.tsx` (genérico por tipo) + `componentes/manuais/PreviaDocumento.tsx` | formulário (documento, processos, «O que entra», 4 textos) à esquerda; pré-visualização sticky à direita (≥ lg) ou separador «Pré-visualização» (< lg); KPI; `Alert` de avisos; «Gravar», «Pré-visualizar», «PDF» (rascunho), «Imprimir» |
| **Manual da Organização** (`est_manual`) | `frontend/src/modulos/estrutura/manual/ManualOrganizacao.tsx` (registo em `estrutura/ecras.ts`) | cartões do inventário §3.2 (O documento, Quadro de pessoal em `Radio.Group` com ajuda, Planeamento estratégico com linha de estado, O que entra — «Encargo mensal» só com `pode('est_ver_salarios')`, «Quadro nominal» só com `pode('est_manual_nominal')`, 8 textos), botões «Ver organigrama» (navega a `est_organigrama`), «Pré-visualizar», «PDF», «Imprimir» |
| Versões e aprovação (O2) | `componentes/manuais/VersoesManual.tsx`, `SubmeterVersao.tsx`, `DecidirVersao.tsx`, `EstadoVersaoTag.tsx` | tabela com estado, `Steps` do ciclo, motivo, aprovar/rejeitar conforme tarefa, histórico |
| Distribuição e leitura (O2) | `DistribuicaoLeitura.tsx` | destinatários (unidades/postos/colaboradores), prazo, portal, relatório «quem leu / falta» com `BotoesExportar` (Excel), «Lembrar» |
| Portal (O2) | `modulos/rh/portal/PortalManuais.tsx` | ver §6 |
| Ligações (O3) | `EditorTarefa.tsx` (Estrutura): selector «Procedimento aplicável»; Fluxo: botão «Ver procedimento» por etapa | |

### 7.2 Menu e registo

- `layout/menuLegado.ts`: `e('geral', 'proc_manual', 'Manual de Procedimentos', 'book-open', { topo: true })` logo a seguir a «Fluxo de Processos» (`TOPO_LEGADO`), e `e('estrutura', 'est_manual', 'Manual da Organização', 'book')` como **4.º** item do grupo Estrutura Orgânica (depois de «Mapa de Pessoal»). Ícones Font Awesome do legado (`fa-book-open`, `fa-book`).
- `modulos/geral/ecras.ts` e `modulos/estrutura/ecras.ts`: `lazy(() => import(...))`.
- Pesquisa: regenerar `indicePesquisa.gerado.ts` (`ferramentas/pesquisa`) e correr `auditar_pesquisa.mjs`; o `indicePesquisa.test.ts` e `filtroMenu.test.ts` passam a cobrir os 2 ecrãs.

### 7.3 Responsividade e acessibilidade

- Seguir `docs/FRONTEND_RESPONSIVIDADE.md`: duas colunas só ≥ lg (o legado mudava a 1100 px); em xs/sm empilhado e pré-visualização num separador; tabelas com `scroll={scrollTabela()}`; `larguraModal(...)`; nada de larguras fixas > 340 px; botões de linha só com ícones em xs com `aria-label`.
- Acessibilidade (`docs/ACESSIBILIDADE.md`): `iframe title`, estado «Há alterações por gravar» em região `aria-live="polite"`, contraste dos `Tag`s e **sem cor como único sinal** (texto «adaptado», «Lida»), foco gerido nos modais, mover etapas também por teclado (botões, não só arrastar), `Alert`s com `role`. Correr o varrimento axe do projecto (`qualidade/axe_*.json`) nos dois ecrãs.
- **Erros seguros**: mensagens do `ErroApi` (`RespostaApi`), códigos de negócio estáveis (`PROCEDIMENTO_JA_EXISTE`, `AUTO_APROVACAO`, `VERSAO_EM_APROVACAO_EXISTENTE`, `PDF_FALHOU`, `MANUAL_NOMINAL_SEM_PERMISSAO`); nunca mostrar excepções, caminhos ou SQL; o conteúdo do `iframe` é HTML do servidor já escapado, em `sandbox=""`.
- UI com antd 6 e o design system (`UI_DESIGN_SYSTEM.md`): sem estilos próprios novos salvo o CSS do documento (que vive no servidor, não na app).

---

## 8. INTERLIGAÇÕES, DUPLICAÇÕES E FRONTEIRAS

### 8.1 Mapa de interligações (16)

| # | Módulo | Ligação no **legado** (evento / dados) | Proposta no **novo** | Duplicação? / Fronteira |
|---|---|---|---|---|
| I-01 | **Estrutura Orgânica** (unidades, cargos, mapa) | `EstruturaOrg.arvore()` lido ao gerar (unidades, cargos, ocupantes, vagas, custo); menu e rota dentro do módulo; botão «Ver organigrama» | `RecolhaOrganizacao` chama `ServicoEstruturaOrg::arvore()/mapaPessoal()`; ecrã `est_manual` no módulo; «Ver organigrama» navega a `est_organigrama` | **Dados**: não se duplicam. Duplicação de **apresentação**: organigrama (TS no cliente × SVG PHP) e quadro (mapa × quadro do manual) → o quadro usa `mapaPessoal`; organigrama com testes de snapshot. **Fronteira:** Estrutura = verdade; o Manual = documento (textos, versões) |
| I-02 | Estrutura › **Tarefas/entregáveis** | tarefas permanentes entram na ficha da unidade; `url_instrucoes` = «procedimento, manual ou instrução de trabalho»; narrativa `entregaveis` descreve o processo | tarefas permanentes na ficha (mesma regra de responsável); coluna opcional «Entregáveis» (O3); `tarefas_unidade_organica.procedimento_id` (O3) ao lado do URL | Sem duplicação; o URL livre mantém-se |
| I-03 | Estrutura › **Delegações / Multas** | nada | secção opcional «Delegação e substituição» (O3); o processo `entregaveis` descreve multas | só leitura |
| I-04 | **RH › Funções** (`cargos_funcoes`) | `roles`: descrição de funções no manual da Organização; **conferência** dos intervenientes dos procedimentos | `RecolhaOrganizacao` lê `CargoFuncao`; `ConferenciaFuncoes` (NFD sem acentos, partes «A / B») nos Procedimentos; aviso «sem função em RH» | A descrição vive **só** em RH (já é a regra do legado, `ED:175`); o manual nunca a edita |
| I-05 | **RH › Colaboradores / quadro nominal** | ocupantes por cargo, `semCargo`, massa salarial por membro | `arvore()`/`mapaPessoal()`; **nominal** com `est_manual_nominal`; custos com `est_ver_salarios` | privacidade: nunca no portal; snapshots nominais só visíveis a quem tem as duas permissões |
| I-06 | **RH › Portal do Colaborador** | (a avaliação 360º tem comunicado com «Li e tomei conhecimento dos procedimentos») — o manual **não** está no portal | separador «Manuais» + confirmação de leitura + pendentes no Início (O2) | Padrão da `ConfirmacaoAvaliacaoRH`; tabela própria (não unificar já) |
| I-07 | **RH › Avaliação 360º** | comunicado de abertura com procedimentos e confirmação de leitura | nada a migrar; reutilizar o padrão; opcional: anexar o procedimento `avaliacao` ao comunicado | sem duplicação |
| I-08 | **Projectos** | processo `proj` (7 etapas, 6 cruzamentos); `fluxo_projectos.js`; `documentos_projeto` existe | só narrativa + «Ver procedimento» a partir do Fluxo (O3); procedimentos podem ser anexados ao projecto como documento (futuro, fora de âmbito) | **Fronteira:** Projectos tem os seus documentos versionados (`documentos_projeto`); os manuais **não** usam essa tabela (ciclos de vida diferentes) |
| I-09 | **Método de Trabalho** (assuntos 5W1H) | sem ligação no legado | `assuntos_metodo_trabalho.procedimento_id` opcional (O3): «procedimento aplicável»; ao concluir um assunto com causa-raiz, sugerir «actualizar o procedimento» | **Fronteira:** Método = registo de assuntos pontuais; Procedimentos = norma permanente. Sem duplicação |
| I-10 | **Fluxo de Processos** | as narrativas de fábrica **são** as do painel (`FluxoNarrativa`); a edição no manual **não** altera o painel; impressão da narrativa de um caso no painel | `narrativas.json` único, completado; Fluxo continua a ler a fábrica; «Ver procedimento» por etapa (O3); `fluxo_id` liga o processo ao painel | **Duplicação principal (D1/D2):** o texto da etapa aparece no painel **e** no manual. Regra mantida: edição só no manual; o painel mostra sempre a fábrica (Q6). Cobertura derivada do `CatalogoFluxos` (corrige B-08) |
| I-11 | **Documentos / Anexos** | só `url_instrucoes`; sem anexos | PDF vigente + hash no disco de ficheiros; `procedimentos_anexos` (O3) pelo validador central | reutiliza o padrão de `documentos_projeto`; **não** reutiliza `documentos_anexos` (modelo legado de blob) |
| I-12 | **Notificações / Fila** | `window.Notificacoes` só para mensagens do ecrã | `NotificadorRH` + Outbox; categoria `manuais`; lembretes agendados | — |
| I-13 | **Auditoria** | `audit_logs` com campos errados (B-01) | `Auditavel` + `manuais_historico` | histórico visível ≠ auditoria técnica |
| I-14 | **Painel / KPIs / Início** | sem KPIs do manual | `PainelEstrutura`: «Manual da Organização — versão vigente / revisão em atraso / lacunas»; painel geral: «Procedimentos sem controlos»; `ServicoInicio::pendentes()`: `manuais_leitura_pendente`, `manuais_aprovar`, `manuais_revisao` | reutiliza `lacunas()` como fonte dos KPIs |
| I-15 | **Planeamento Estratégico** (outro agente) | `window.Estrategia`: plano activo, perspectivas, objectivos por unidade, missão/visão/valores; `estrategia_plano` como cruzamento externo; **mesmo motor** `DocumentoCorporativo` e CSS `pm-*` | **contrato** `FornecedorEstrategiaManual` (+ implementação nula); `tipo` de `manuais` extensível a `PLANO_ESTRATEGICO` (Q2); `EXTERNOS` mantém as chaves | **Duplicação potencial:** o kernel de documento (capa, índice, rodapé, versões) — se o outro agente o reconstruir à parte, divergem. Coordenar: este plano é o dono do `Documento/*` e dos `manuais*` |
| I-16 | **Identidade da empresa, Permissões/Planos, Pesquisa** | logótipo/NIF/CRC/capital/morada no rodapé; `permissoes.js`; menu | identidade da BD; catálogo à mão; planos (`geral`, `estrutura`); índice de pesquisa | — |

### 8.2 O que fica em Estrutura e o que é módulo próprio

| Fica em **Estrutura Orgânica** | Fica no módulo **Procedimentos** (`geral`) | Partilhado (`manuais*`, `Documento/*`) |
|---|---|---|
| Unidades, cargos (postos), organigrama, mapa, tarefas, entregáveis, multas, delegações | Narrativas de fábrica, edições, processos próprios, cruzamentos, matriz, cobertura, conferência com funções | Definições por empresa, versões, aprovação, vigência, distribuição, leituras, histórico, PDF, marca de água, portal |
| **Manual da Organização** = ecrã `est_manual` (textos, opções, âmbito, quadro) — **dentro** de Estrutura | **Manual de Procedimentos** = ecrã `proc_manual` | Permissões distintas por manual; segregação por manual |

---

## 9. Testes

### 9.1 PHPUnit (no contentor `erp_app_e2e`, **sem concorrência**)

`ProcedimentosFabricaTest` (19/109/84; chaves; `cruza.com` válidos; ordem) · `ProcedimentosEdicaoTest` (gravar, repor, apagar próprio, esconder **sem congelar** B-02, mostrar, chave reservada, UNIQUE, validações exactas, substituição de etapas, concorrência) · `ProcedimentosRecolhaTest` (intervenientes, controlos, matriz simétrica, central, isolados, conferência de funções com acentos e «A / B», 7 avisos) · `ProcedimentosCoberturaTest` · `ProcedimentosDocumentoTest` (capítulos condicionais, XSS nos textos, numeração N.i.j) · `ManualOrganizacaoRecolhaTest` (numeração, âmbito, inactivas, funções em uso, cargos sem função, três quadros, regra do responsável da tarefa, `FornecedorEstrategiaNulo`, precedência do texto, 9 avisos, M-02/M-08) · `ManualOrganizacaoPrivacidadeTest` (nominal sem `est_manual_nominal` → recusado; custos sem `est_ver_salarios` ignorados e valor conservado; portal nunca) · `ManuaisDefinicoesTest` · `ManuaisVersoesTest` (submeter, 1 `EM_APROVACAO`, aprovar, rejeitar com motivo, `AUTO_APROVACAO`, 1 `VIGENTE`, substituição, snapshot imutável e hash, concorrência) · `ManuaisPdfTest` (`%PDF`, páginas, marca de água, `Http::preventStrayRequests()`, HTML hostil, SVG perigoso recusado) · `ManuaisDistribuicaoLeituraTest` (resolução por unidade+subunidades/posto/colaborador, leitura idempotente, pendentes, lembretes, notificações via Outbox) · `PortalManuaisTest` (só vigente+visível+destinatário, isolamento entre colaboradores, lista branca do portal, NOMINAL → 404) · `ManuaisPermissoesTest` (403 por rota e tarefa; catálogo; perfis-modelo; segregação) · `MigracaoManuaisTest` (idempotente: `up()` duas vezes; RLS e GRANT presentes). Unit: `TextoLivreTest` (paridade `par/linhas/comoLista`), `IndiceCapitulosTest`, `GeradorOrganigramaSvgTest` (snapshot), `ConferenciaFuncoesTest`.
Contratos existentes a actualizar: `OwaspControloAcessoTest` (3 rotas do portal), `ExigirAreaPortalTest`, `SistemaMenuTest`, `ServicoPermissoesTest`, `GestaoFluxosTest`.

### 9.2 Vitest (frontend)

`ManualProcedimentos.test.tsx` (lista e etiquetas, estado vazio, editor: obrigatórios, mover/remover etapas e renumerar, cruzamentos com externos, «Novo» com as 2 mensagens de erro, confirmações) · `regras.test.ts` (chave `slug ≤ 30`, renumerar, mover) · `PreviaDocumento.test.tsx` (`sandbox=""`, `title`) · `ManualOrganizacao.test.tsx` (3 modos, «Encargo mensal» e «nominal» ocultos sem permissão, estado do plano nos 3 casos, imprimir grava antes) · `VersoesManual.test.tsx` (acções por tarefa e estado) · `PortalManuais.test.tsx` (confirmar leitura, estado por texto) · testes de contrato do menu/pesquisa/catálogo · varrimento axe dos 2 ecrãs. E2E (Playwright, 1 *smoke*): editar um processo → pré-visualizar → gravar.

### 9.3 Rotina de fecho (memória do projecto)

Suite backend+frontend completa, **sem dois `php artisan test` em paralelo**, nunca confiar no código de saída de comandos com `| tail`; no fim `npm run build` (o frontend de teste serve de `frontend/dist`). Commits por fase com `git add` só dos ficheiros da fase; sem push.

---

## 10. Migração de dados do legado (ETL)

- **Não há dados a migrar nos backups disponíveis**: os dois backups (`wstb_payroll_backup_2026-10-13.json`, `…-10-19.json`) são anteriores às tabelas, criadas no legado a 2026-11-06 (`grep` por `"proc_flows"|"proc_manual"|"org_manual"` = 0). **Pedir ao utilizador** um backup/exportação mais recente se houver edições reais (Q11).
- Para esse caso: comando `erp:manuais:importar-legado {ficheiro} --simular` (omissão: simular), **idempotente** por `(empresa_id, tipo)` e `(empresa_id, chave)`; `proc_flows` → `procedimentos` (+ etapas e cruzamentos), recusando chaves reservadas e etapas sem nome; `proc_manual`/`org_manual` → `manuais` (`processos` → `processos_excluidos` por complemento; `incluir_contactos` ignorado); `is_master_data=1` ignorado; relatório de linhas importadas/ignoradas com motivo; ensaiado na cópia da produção. Esforço S (só depois de haver dados).
- **Fábrica**: extracção única do legado para `narrativas.json` (§5.1) — é migração de **código**, não de dados.
- **Produção**: migrações aditivas (tabelas novas) ensaiadas na cópia PostgreSQL local; `erp:perfis:actualizar-modelos` depois do deploy (§3.4); nenhuma alteração a tabelas existentes nas Ondas 1-2.

---

## 11. FASES (F0…F8)

Ondas: **O1 = paridade** (F0–F4), **O2 = controlo documental** (F5–F6), **O3 = interligações e anexos** (F7), **F8 = fecho** (transversal, repete-se por onda).

| Fase | Objectivo | Conteúdo | Esforço | Depende de / paralelismo | Aceitação |
|---|---|---|---|---|---|
| **F0 — Spike e decisões** | eliminar o maior risco técnico e fechar decisões | spike dompdf (tabelas, DejaVu, ▶●→←, SVG de organigrama/diagrama, marca de água, «Página X de Y», 20+ páginas); responder às Q1–Q11; combinar com o agente do Planeamento Estratégico o dono do kernel; extrair `narrativas.json` (19/109/84) | S | primeira; só leitura + ferramentas/ | PDF de amostra com os dois documentos aprovado; JSON gerado e comparado com o legado |
| **F1 — Núcleo de documento e definições** | `manuais` + kernel de documento | migração `manuais`; `Manual`; `ServicoManuais`; `Documento/*` (índice, capa, rodapé, assinaturas, `TextoLivre`, marca de água); `OpcoesDompdfSeguras` extraídas de `ServicoPdfA4POS` (**sem alterar o comportamento do POS**: `POSPdfA4Test` verde); `GET/PUT manuais/{tipo}`; catálogo de permissões (ecrãs + tarefas + perfis); `ServicoNotificacoes` categoria | M | após F0. **F1 ‖ F2** (ficheiros distintos) | testes `ManuaisDefinicoesTest`, `TextoLivreTest`; `POSPdfA4Test` e RLS verdes |
| **F2 — Procedimentos: dados e regras** | fábrica + edições + próprios | migrações `procedimentos`, `_etapas`, `_cruzamentos`; `CatalogoFabricaProcedimentos` (+ `modulo`/plano); `ServicoProcedimentos` (listar/gravar/repor/ocultar/mostrar/reordenar com as mensagens exactas); `ServicoCobertura…`, `ServicoCruzamentos…`, `ConferenciaFuncoes`; rotas `procedimentos/*`; FormRequests e Resources | M | após F0. ‖ F1 | `ProcedimentosFabricaTest`, `…EdicaoTest`, `…RecolhaTest`, `…CoberturaTest` verdes; **B-02, B-03, B-06 provados** |
| **F3 — Procedimentos: documento e ecrãs** | paridade PRO-F-025…083 | `RecolhaProcedimentos` + `RenderizadorProcedimentos` + diagrama; `pre-visualizar` e `pdf` (rascunho); ecrã `proc_manual` (Processos, Manual); menu, `ecras.ts`, pesquisa; responsividade e a11y | L | F1 + F2. **F3 ‖ F4** | `ProcedimentosDocumentoTest`; vitest dos ecrãs; paridade PRO-F-001…087 marcada IGUAL/DIFERENTE-CORRIGIDA na matriz |
| **F4 — Manual da Organização** | paridade MOR-F-001…056 | `RecolhaOrganizacao` (sobre `arvore()`/`mapaPessoal()`/tarefas/funções), `FornecedorEstrategiaManual` + nulo, `RenderizadorOrganizacao`, `GeradorOrganigramaSvg`; ecrã `est_manual` dentro de Estrutura; `est_manual_nominal` e custos no servidor | L | F1 (kernel). ‖ F3 | `ManualOrganizacaoRecolhaTest`, `…PrivacidadeTest`, snapshot do SVG; M-02, M-03, M-04, M-08 provados |
| **F5 — Versões, aprovação e PDF vigente** | PRO-F-088…091, MOR-F-057…060 | `manuais_versoes`, `manuais_historico`; `ServicoVersoesManual`; evento Outbox `manual.gerar_pdf`; rotas de versões; UI Versões; marca de água/cópia controlada; trigger de imutabilidade | L | F3 e F4 | `ManuaisVersoesTest`, `ManuaisPdfTest`; aprovar gera PDF com hash; autor ≠ aprovador |
| **F6 — Distribuição, leitura, portal, revisão** | PRO-F-092…094, MOR-F-061…063 | `manuais_destinatarios`, `manuais_leituras`; `ServicoDistribuicao…`; portal «Manuais»; `ServicoInicio` pendentes; `erp:manuais:lembretes`; KPIs no painel | M | F5 | `ManuaisDistribuicaoLeituraTest`, `PortalManuaisTest`; lista branca do portal; nenhum nominal exposto |
| **F7 — Interligações e anexos (opcional)** | PRO-F-095…098, MOR-F-064…067 | colunas `procedimento_id` (tarefa, assunto); «Ver procedimento» no Fluxo; `procedimentos_anexos`; Delegação/Entregáveis opcionais no Manual; (Word se decidido) | M | F5/F6 | testes de ligação; migração aditiva ensaiada |
| **F8 — Fecho de cada onda** | ADR e relatórios | ADR-086 em `DECISOES.md` («Manuais e Procedimentos»); `docs/paridade/PROCEDIMENTOS_F{n}_RELATORIO.md`; dicionário (tabelas fora do contrato do legado); suite completa; `npm run build`; deploy no teste e **só depois** produção (autorização de actualizar teste e produção já dada; nunca force-push; ensaiar migrações) | S por fase | fecho de O1 (após F4), de O2 (após F6), de O3 (após F7) | suite verde; matriz actualizada; migrações ensaiadas na cópia |

**Caminho crítico:** F0 → (F1 ‖ F2) → (F3 ‖ F4) → F5 → F6. Paridade completa (O1) = F0–F4 ≈ **S + M + M + L + L**; controlo documental (O2) = **L + M**.

---

## 12. Riscos

| # | Risco | Mitigação |
|---|---|---|
| R1 | **Dados reais em produção** e migrações | só tabelas novas nas O1-O2; `hasTable`/`IF NOT EXISTS`; ensaio na cópia local; `down()` só apaga tabelas novas |
| R2 | **dompdf não suporta flex/grid/pseudo-elementos**; fontes e glifos (▶ ● → ←) | layout por tabelas + SVG; DejaVu; spike F0 antes de investir; manter HTML do ecrã como fallback de impressão pelo `componentes/impressao` |
| R3 | **Organigrama em dois sítios** (TS no cliente, PHP no manual) | testes de *snapshot* do SVG; o ecrã Organigrama não muda; considerar partilhar o layout por JSON no futuro |
| R4 | Completar `narrativas.json` pode partir `GestaoFluxosTest`/`ServicoFluxos` (injecção por posição) | manter a ordem; chaves novas só para o manual; correr os testes do Fluxo na F0 |
| R5 | **Privacidade** dos snapshots nominais/custos | CHECK de `visivel_portal`; permissões distintas; auditoria de descarga; RLS; nunca no portal |
| R6 | Plano/licença não inclui `geral`/`estrutura` para alguns clientes | confirmar `ModulosPlano` e `CatalogoModulosPlanoTest` na F1 |
| R7 | **Coordenação com o Planeamento Estratégico** (kernel `Documento/*` e contrato) | decidir o dono na F0; `FornecedorEstrategiaManual` por interface; sem dependência de código do outro módulo |
| R8 | Âmbito a crescer (versões, leitura, anexos, Word) | ondas separadas; O2/O3 só com decisão; paridade fecha primeiro |
| R9 | Valor legal da «cópia controlada» e da aprovação | o sistema regista quem aprovou e o hash, **não substitui** validação jurídica/qualidade (como a nota das multas, ADR-079) |
| R10 | Desempenho do PDF (muitas unidades/processos) | geração assíncrona via Outbox na vigente; `throttle:pesado` no rascunho; limite de páginas/tempo e mensagem `PDF_DEMASIADO_GRANDE` |

---

## 13. PERGUNTAS ao utilizador (decisões, com a minha recomendação)

1. **Âmbito.** Fazer só a paridade (Onda 1) ou também versões/aprovação/leitura obrigatória/portal (Onda 2)? **Recomendo O1 + O2** — o pedido refere versões, aprovação, distribuição e portal, que o legado **não** tem; fases independentes permitem parar após O1.
2. **Núcleo partilhado `manuais`** para os dois manuais e extensível ao Documento do Plano Estratégico? **Sim**; combinar com o agente do Planeamento quem é dono do kernel de documento (F0).
3. **PDF.** PDF gerado **no servidor** (dompdf) como formato principal + pré-visualização HTML, em vez de só imprimir do navegador? **Sim** (reprodutível, hash, marca de água, portal).
4. **Aprovação.** Exigir autor ≠ aprovador sempre? **Sim por omissão**, com excepção configurável para empresas de um só utilizador.
5. **Portal.** Os colaboradores podem consultar o Manual de Procedimentos (e a Organização só em RESUMO, sem custos nem nomes), com confirmação de leitura por unidade/posto? **Sim.**
6. **Edição vs Fluxo.** Editar uma narrativa deve alterar **também** o texto mostrado no painel do Fluxo? **Não** (regra do legado) — só o manual, com «Ver procedimento» a ligar os dois; a empresa nunca perde as melhorias de fábrica nos processos que não editou.
7. **Processos de módulos que a empresa não tem** (p.ex. Gestão Imobiliária, inexistente no novo) e as 5 narrativas sem painel (`entregaveis`, `imobiliario`, `stock`, `manutencao`, `fecho`): manter as 19 e **esconder por omissão** as de módulos fora do plano? **Sim.** E **permitir reordenar** os processos (o legado gravava `ordem` mas não tinha controlo)? **Sim.**
8. **Privacidade.** Quadro **nominal** com permissão própria (`est_manual_nominal`) e rasto de descarga; custos só com `est_ver_salarios`; nunca no portal? **Sim.**
9. **Word (.docx).** Necessário? **Não nesta fase** (PDF + HTML bastam; exigiria uma dependência nova); se sim, Onda 3.
10. **Marca de água e revisão.** Texto «CÓPIA NÃO CONTROLADA — consulte a versão vigente no ERP» no PDF não vigente/descarregado e revisão periódica de **12 meses** com aviso 30 dias antes? **Sim** (valores configuráveis por manual).
11. **Dados.** Existe uma exportação do legado **posterior a 2026-11-06** com edições reais de procedimentos ou textos do manual? Se não, **nada há a migrar** (só a extracção das narrativas de fábrica).

---

## 14. O que ficou por confirmar (declaração de limites)

- **Planeamento Estratégico:** não foi lido (outro agente); o contrato `FornecedorEstrategiaManual` deduz-se das chamadas de `estrutura_manual.js:228-255`.
- **Plano/licença:** não verifiquei em `ModulosPlano` se `geral` é sempre incluído; **fiz** a leitura da migração `2026_11_10_090000` que liga `estrutura` a `rh`.
- **dompdf 3:** a viabilidade do SVG embebido (via `data:`) e das fontes DejaVu está por provar no spike F0; o sanitizador de `ServicoPdfA4POS` já aceita `data:image/svg+xml;base64`.
- **Disco de ficheiros:** o nome/configuração exacta do disco de `ServicoDocumentosProjeto` não foi lido; reutilizar o mesmo.
- **Testes existentes a tocar** (`GestaoFluxosTest`, `ExigirAreaPortalTest`, `SistemaMenuTest`) não foram abertos; a lista é a esperada pelo padrão.
- **Conteúdo das 19 narrativas:** contado e amostrado, não transcrito (a extracção é por script na F0).
- **Backups do legado:** só os de 2026-10-13 e 2026-10-19 foram verificados (0 ocorrências de `proc_*`/`org_manual`).
- Produção (`producao_copia/`): o único ficheiro relacionado é o mesmo `narrativas.json`; `.env` não foi aberto.
