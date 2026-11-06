# PROCEDIMENTOS — Relatório da Onda 1 (paridade do Manual de Procedimentos)

Data: 2026-11-06, ramo `paridade-bd-front`. Complemento de `PROCEDIMENTOS_PLANO.md` e `PROCEDIMENTOS_INVENTARIO.md`. Esta fatia é fechada e funcional de ponta a ponta: dados, tabelas, API, permissões, ecrã, impressão e testes.

## 1. O que ficou feito

| Parte | Onde |
|---|---|
| Dados de fábrica extraídos do legado **por script** (19 narrativas, 109 etapas, 84 cruzamentos; `lav→lavandaria`, `proj→projetos`, `imob→imobilizado`; `fluxo_id` e `modulo`) | `ferramentas/paridade/extrair_narrativas_procedimentos.mjs` → `backend/resources/procedimentos/narrativas.json` (`--verificar` compara com o legado) |
| Migração aditiva e idempotente, RLS e GRANT: `manuais`, `procedimentos`, `procedimentos_etapas`, `procedimentos_cruzamentos` | `backend/database/migrations/2026_12_14_090000_manual_procedimentos_onda1.php` |
| Modelos `Manual`, `Procedimento`, `EtapaProcedimento`, `CruzamentoProcedimento` | `backend/app/Models/` |
| Serviços: fábrica, edição/próprios/esconder/repor, análise (cruzamentos, cobertura, 7 avisos), definições e recolha do documento | `backend/app/Services/Procedimentos/` |
| API (10 rotas), FormRequests, Resource (`mixed`), controlador | `routes/api/procedimentos_manual.php`, `app/Http/{Controllers/Api,Requests,Resources}/Procedimentos` |
| Permissões: ecrã `proc_manual` (módulo `geral`), tarefas `proc_editar`, `proc_imprimir` (nenhuma sensível); perfis-modelo Direcção (as 3), Técnico de RH e Auditor/Consulta (ver + imprimir), Controller (ver) | `backend/resources/permissoes/catalogo.json` (edição à mão) |
| Ecrã `proc_manual` ao lado de «Fluxo de Processos» (item de topo), 2 separadores (Processos, Manual), 3 diálogos (novo, repor/apagar, esconder), editor de etapas/cruzamentos, cobertura, escondidos, pré-visualização e impressão pelo motor comum | `frontend/src/modulos/geral/procedimentos/` |

## 2. Rotas

`GET procedimentos` · `GET procedimentos/cobertura` · `POST procedimentos` · `PUT|DELETE procedimentos/{chave}` · `POST procedimentos/{chave}/ocultar|mostrar` · `GET|PUT manuais/procedimentos` · `POST manuais/procedimentos/recolha` (só leitura; `throttle:pesado`). Ver = `proc_manual_view`; escrever = `proc_editar` (verificado no servidor, antes da validação). O Fluxo de Processos e o seu `narrativas.json` (14 narrativas) **não foram alterados**.

## 3. Correcções deliberadas do legado (provadas por testes)

| # | Legado | Novo |
|---|---|---|
| B-02 | esconder um processo de fábrica copiava o texto (ficava «adaptado» para sempre) | linha-bandeira sem texto (`personalizado=false`, `titulo` nulo); «voltar a incluir» apaga-a e o processo volta a acompanhar o ERP |
| B-03 | o manual guardava a lista dos processos incluídos; os novos do ERP ficavam de fora | guarda-se `processos_excluidos`; o ecrã envia as exclusões |
| B-04 | permissões só no menu | `exigir` nos controladores/pedidos (403 sem `proc_editar`) |
| B-06 | duplicado verificado só entre os visíveis | `UNIQUE (empresa_id, chave)` na BD e verificação que inclui escondidos, chaves de fábrica e dos documentos externos |
| B-01 | `audit_logs` com campos inexistentes | `Auditavel` nos modelos `Manual` e `Procedimento` (módulo «Manuais») |
| — | `tem_painel` era sempre verdadeiro para a fábrica | só os processos com painel no Fluxo de Processos do ERP novo (`CatalogoFluxos::FLUXOS`); `entregaveis`, `fecho`, `stock`, `manutencao` e `imobiliario` não têm painel |

Outras decisões: um cruzamento só pode apontar a outro processo (fábrica ∪ próprios ∪ escondidos) ou a `estrutura_manual`/`estrategia_plano`, nunca a si próprio; a gravação substitui o conjunto de etapas e de cruzamentos numa transacção com `lockForUpdate`; as mensagens de negócio são as do legado, via `ErroNegocio` com código estável (`PROCEDIMENTO_SEM_TITULO`, `…_SEM_ETAPAS`, `…_JA_EXISTE`, `…_CRUZAMENTO_INVALIDO`, `…_NAO_ENCONTRADO`, `…_SEM_EDICAO`); no ecrã os erros passam por `notificarErro`.

## 4. Impressão

Sem PDF no servidor. O servidor recolhe e calcula (processos visíveis, intervenientes e conferência com RH › Funções, controlos, cruzamentos, cobertura, totais, avisos); o ecrã desenha o documento (`DocumentoProcedimentos`: capa, índice, capítulos pela ordem e condições do legado, numeração N.i.j, diagrama, matrizes, assinaturas) e entrega-o em HTML estático (`renderToStaticMarkup`, sem scripts) ao motor comum `componentes/impressao`, que acrescenta a identidade da empresa, o rodapé e «Página X de Y». Imprimir grava primeiro o que estiver por gravar (como o legado). `proc_imprimir` controla o botão no ecrã (a leitura dos dados já exige `proc_manual_view`).

## 5. Testes

- PHPUnit `ProcedimentosManualTest`: 14 testes, 313 asserções (contagens 19/109/84 e integridade dos cruzamentos; 14 narrativas do Fluxo intactas; fábrica sem gravar nada; permissões 403 em todas as escritas; edição que substitui etapas/cruzamentos; validações com a mensagem do legado; B-02; repor/apagar; chave única com escondidos; próprio na matriz com externo; B-03 e recolha; avisos; cobertura; isolamento por empresa; migração idempotente com RLS). Com `GestaoFluxosTest`: 22 testes verdes.
- Varredores de rotas (um a um): `OwaspControloAcessoTest` 2, `AcessoCruzadoGeradoTest` 3, `IsolamentoRlsContratoTest` 6, `ConsultasPorEndpointTest` 3; `ServicoPermissoesTest` 8, `CatalogoModulosPlanoTest` 1, `FkPerfisModelosTest` 2, `SistemaMenuTest` 3 — todos verdes. Pint limpo.
- Vitest: `logica.test.ts` (4) e `ManualProcedimentos.test.tsx` (8): 12 testes; `tsc --noEmit` limpo; `src/layout` verde excepto o índice da pesquisa (ver §7).

## 6. O que NÃO ficou (continua no plano)

Manual da Organização (`est_manual`); controlo documental (versões, aprovação, vigência, distribuição, leitura obrigatória, histórico); PDF no servidor e marca de água; portal do colaborador; anexos; interligações com Estrutura (tarefas, Método de Trabalho); ETL de dados de utilizadores; reordenação de processos (a coluna `ordem` existe mas não há botão: o legado também não tinha); `base_hash` está gravado ao editar mas ainda não há aviso «o ERP actualizou este processo»; filtro por módulos do plano (`modulo` está copiado da fábrica mas não é aplicado).

## 7. Para o coordenador

1. **Ensaiar a migração** `2026_12_14_090000_manual_procedimentos_onda1` na cópia (`erp_consulvolt`) antes de produção: só cria 4 tabelas novas (sem tocar nas existentes), com RLS, GRANT a `erp_app` e índices únicos.
2. **Regenerar o índice da pesquisa** (`node ferramentas/pesquisa/gerar_indice_pesquisa.mjs`): `indicePesquisa.gerado.ts` não foi tocado, por instrução, pelo que `indicePesquisa.test.ts` falha em 2 testes (o ecrã `proc_manual` falta no índice; o ficheiro está desactualizado). Depois, `npm run build` do frontend.
3. Aplicar os perfis-modelo às empresas existentes: `php artisan erp:perfis:actualizar-modelos` (só acrescenta).
