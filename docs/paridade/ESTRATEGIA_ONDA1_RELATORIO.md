# Planeamento Estratégico — Onda 1 (F0 + F1 + F2a): relatório

Data: 2026-11-06, ramo `paridade-bd-front`. Base: `ESTRATEGIA_PLANO.md` e `ESTRATEGIA_INVENTARIO.md`. Decisões do utilizador P1…P12 aplicadas (módulo próprio `estrategia`, ids do legado, valores financeiros só com `est_ver_financeiros`, um plano em vigor por empresa, ciclo máximo de 15 anos, congelamento com excepção auditada, 4 casas decimais em `numeric(20,4)` com cálculo decimal, sem ETL, sem `grupo_id`).

## 1. O que ficou funcional

| Parte | Entrega |
|---|---|
| F0 alicerces | módulo `estrategia` no catálogo de permissões (**9 ecrãs, 21 tarefas, 5 sensíveis**: `est_plano_aprovar`, `est_doc_emitir`, `est_casc_decidir`, `est_rev_fechar`, `est_ver_financeiros`); perfil-modelo novo «Gestor de Estratégia» e acrescentos a Direcção, Controller, Responsável de Departamento e Auditor; 2 pares de segregação (`est_plano_editar`×`est_plano_aprovar`, `est_casc_propor`×`est_casc_decidir`); entrada em `planos_catalogo` e migração que acrescenta `estrategia` aos planos que têm Orçamento ou RH; grupo «Planeamento Estratégico» no menu, ícone e `ecras.ts` (2 ecrãs reais + 7 «em construção»); `ServicoNotificacoes` com o módulo e as 9 vistas. Os ids `est_*` não colidem com os da Estrutura Orgânica (`est_estrutura`, `est_organigrama`, `est_mapa`, `est_editar`…). |
| F0 cálculo | `Periodos` (EST-C-06/07/08), `CalculadoraDesempenho` (EST-C-01…05, 14, 22; resumo por cor), `Vocabulario`. **Decimal puro (bcmath)**: o semáforo compara por multiplicação cruzada, sem divisões; o caso 8,1 ÷ 9 × 100 dá **AMARELO** (em float dava 89,99999999999999 → VERMELHO). |
| F1 plano | CRUD, **RASCUNHO → APROVADO → EM_EXECUCAO → ENCERRADO** (reabrir só de APROVADO, com motivo); um plano em vigor por empresa (índice único parcial + mensagem); aprovar exige ≥ 1 objectivo activo, cada objectivo activo com ≥ 1 indicador activo e cada indicador activo com ≥ 1 meta (lista os 3 primeiros problemas); **segregação**: quem criou ou editou o plano não o aprova (`AUTO_APROVACAO`), salvo excepção com motivo ≥ 10 caracteres, guardada no plano e na auditoria; **congelamento**: plano aprovado/em execução só se altera com `est_plano_aprovar` + `motivo_excepcao` (auditado), encerrado nunca; apagar só rascunho sem dados; quatro perspectivas de omissão com os ícones certos (o legado regravava `fa-bullseye` em todas). |
| F1 estrutura | perspectivas (nome único, apagar bloqueado com objectivos) e objectivos/OKR (perspectiva e pai do mesmo plano, **pai sem ciclos a qualquer profundidade**, OKR com trimestre dentro do ciclo, apagar bloqueado por indicadores e dependentes). |
| F2a indicadores | origens **MANUAL** (dono obrigatório) e **MODULO** (entregáveis e colaboradores, por **lista fechada** `CatalogoFontesModulo`: o cliente envia ids, nunca tabelas ou colunas; filtros validados por tipo; `ILIKE` com escape); polaridade, tipo, formato, frequência, agregação por omissão, tolerâncias, linha de base, prazo. «Testar medição» lê o módulo **sem gravar**. As origens KPI_GESTAO, CONTA e ORCAMENTO existem na BD e no vocabulário mas são recusadas (`ORIGEM_INDISPONIVEL`) até à onda do painel. |
| F2a metas e medição | metas da empresa por período (frequência e ciclo validados, valor vazio apaga, idempotente); **medição manual** (só MANUAL, activo, período já iniciado, substitui sem apagar, livre até ao encerramento); série anual com meta, valor, cumprimento e semáforo; valores em kwanzas só com `est_ver_financeiros` (`oculto: true`, semáforo mantém-se). |
| Frontend | `Plano e Objectivos` (3 cartões, grelha por perspectiva, OKR a tracejado, modais de plano, perspectivas e objectivo, transições com confirmação, motivo para plano congelado e excepção de auto-aprovação); `Indicadores e Metas` (tabela com procura, alertas, formulário com sub-formulário por origem e filtros, metas por ano com «Preencher em escada» em aritmética decimal igual à do servidor, série com semáforo cor + ícone + texto, entrega de valores). Plano na query string (`?plano=`). |

## 2. Migrações novas (para o coordenador ensaiar na cópia local)

1. `backend/database/migrations/2026_12_16_090000_estrategia_nucleo.php` — 6 tabelas novas: `planos_estrategicos`, `perspectivas_estrategicas`, `objectivos_estrategicos`, `indicadores_estrategicos`, `metas_indicadores`, `medicoes_indicadores`. Aditiva e idempotente (`hasTable`, `CREATE … IF NOT EXISTS`), todas com `empresa_id`, CHECK/UNIQUE, RLS (`IsolamentoRls::aplicarATodas`) e GRANT a `erp_app`. Não toca em nenhuma tabela existente.
2. `backend/database/migrations/2026_12_16_090100_estrategia_plano_licenca.php` — acrescenta `estrategia` às versões de plano de licença com `orcamento` ou `rh` (idempotente, invalida `ModulosPlano`).

Ensaio sugerido: aplicar na cópia local, correr `IsolamentoRlsContratoTest` e `CatalogoModulosPlanoTest`. Sem `down()` (aditiva).

## 3. Decisões tomadas nesta onda

- **Quatro estados** (o utilizador pediu «em execução»; o plano falava de três): `APROVADO` é «aprovado, ainda não iniciado» e `EM_EXECUCAO` o plano em vigor a decorrer; ambos contam como «em vigor» e ficam congelados.
- **Eliminação física** (não lógica): FK `RESTRICT` e regras no serviço bastam; as colunas `eliminado_em` previstas no plano não foram criadas.
- **Só 6 das 17 tabelas** (as das partes entregues). As restantes entram com a sua onda.
- `fonte` (jsonb) é sempre um objecto: o modelo grava `{}` para vazio (um array `[]` viola o CHECK).
- A lista de unidades e de colaboradores para os selectores vem em `GET /estrategia/catalogos` (só id e nome), para não exigir permissões de RH/Estrutura a quem só vê a estratégia.
- Período equivalente (EST-C-06) usa o **primeiro mês** do período de origem (o legado não o define para períodos não mensais).
- Valores em kwanzas ficam reservados quando `formato = kz` (além das origens financeiras futuras): decisão conservadora de P3.

## 4. Testes (números)

- PHPUnit: `Tests\Unit\Estrategia\CalculadoraDesempenhoTest` + `PeriodosTest` (**31 testes, 131 asserções**, números calculados à mão, incluindo 8,1 ÷ 9); `Tests\Feature\EstrategiaOnda1Test` (**16 testes, 434 asserções**: permissões e 403 antes de validar, estados e transições inválidas, congelamento com e sem excepção, segregação, ciclos no pai, OKR, bloqueios de apagar, validações dos indicadores, fonte fechada contra tabelas/colunas/filtros maliciosos, metas, medição manual e semáforo, financeiros ocultos, fonte de módulo com somas/contagens/média, isolamento por empresa, migração idempotente e RLS).
- Varredores, um a um: `OwaspControloAcessoTest` (2 passed), `AcessoCruzadoGeradoTest` (3), `ConsultasPorEndpointTest` (3), `EntradasMaliciosasListagensTest` (3), `ContaRootTest` (22): todos verdes com as 25 rotas novas.
- Vitest: `Estrategia.test.tsx` (**11 testes**: escada igual à fixture do servidor, rótulos, ecrã do plano por permissões e estado, motivo em plano congelado, indicadores, procura, metas em escada com gravação, série e entrega, só consulta). `tsc --noEmit` limpo; Pint limpo nos ficheiros novos.
- Fora do meu âmbito, a falhar por culpa do estado partilhado do ramo e **não** corrigidos: `CatalogoModulosPlanoTest` e `iconesModulos.test.tsx` (falta o módulo `imobiliario`, do outro agente); `indicePesquisa.test.ts` (2 testes: o índice gerado tem de ser regenerado com `node ferramentas/pesquisa/gerar_indice_pesquisa.mjs`, para os 9 ecrãs da estratégia e os da imobiliária; não toquei em `indicePesquisa.gerado.ts`).

## 5. Rotas novas (25, prefixo `api/estrategia`, ficheiro `routes/api/estrategia.php`)

`GET catalogos` · `GET|POST planos` · `GET|PUT|DELETE planos/{plano}` · `POST planos/{plano}/aprovar|iniciar-execucao|reabrir|encerrar` · `POST planos/{plano}/perspectivas` · `PUT|DELETE planos/{plano}/perspectivas/{id}` · `POST planos/{plano}/objectivos` · `PUT|DELETE planos/{plano}/objectivos/{id}` · `GET|POST planos/{plano}/indicadores` · `PUT|DELETE planos/{plano}/indicadores/{indicador}` · `POST indicadores/{indicador}/testar` · `GET|PUT indicadores/{indicador}/metas` · `GET indicadores/{indicador}/serie` · `POST indicadores/{indicador}/medicoes`.

## 6. O que falta (ondas seguintes, por ordem do plano)

- **F2b** medição agendada/em segundo plano (202) das origens MODULO (gravar), KPI_GESTAO, CONTA e ORCAMENTO, painel estratégico (`est_painel`), semáforo por objectivo (EST-C-05 já existe em `piorCor`), valores financeiros por origem.
- **F3** mapa estratégico e ligações causa-efeito (tabela 9) e iniciativas (tabela 10) · **F4** desdobramento (tabelas 6 e 7, delegação `ESTRATEGIA`) · **F5** revisões e acções correctivas (tabelas 11 e 12) · **F6** diagnóstico (13 a 15) · **F7** Documento do Plano, PDF e versões (16 e 17) · **F8** integrações (Avaliação, Portal, Relatório e Contas, Início, alertas, notificações, lista branca do portal, regenerar pesquisa/rotas) · **F9** endurecimento, e2e.
- Nesta onda não há notificações nem alertas (P11), nem contadores no Início.
- Regenerar o índice da pesquisa e adicionar o ícone do módulo `imobiliario` (outro agente) antes de fechar o ramo.
- Perfis-modelo: depois de integrar, correr `erp:perfis:actualizar-modelos --simular` (edição feita à mão no `catalogo.json`, como a regra do projecto).
