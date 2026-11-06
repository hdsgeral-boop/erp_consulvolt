# Planeamento Estratégico (e Documento do Plano) — plano de importação para o sistema novo

Data: 2026-11-06, ramo `paridade-bd-front`. Só planeamento: nenhum código foi alterado. Base: `ESTRATEGIA_INVENTARIO.md` (140 funcionalidades EST-F, 25 fórmulas EST-C, 26 defeitos EST-D, 14 interligações I-xx — todas referenciadas aqui). Processo: igual ao do POS (`POS_PLANO_RECONSTRUCAO.md`): inventário → plano → fases com critérios de aceitação. Estimativas: S ≤ 1 dia de agente, M 2–4 dias, L ≥ 1 semana (um agente a tempo inteiro, com testes).

## 0. Resumo

- **O que se importa:** um módulo novo licenciável **«Planeamento Estratégico»** (`estrategia`), 9 ecrãs, 11 tabelas do legado → **17 tabelas novas** (mais normalizadas e com integridade na BD), ≈ 73 endpoints, motor de medição no servidor, e um **Documento do Plano** gerado no servidor (HTML → PDF `dompdf`), com **versões emitidas imutáveis**.
- **O que NÃO se faz:** não se grava em nenhum outro módulo (a estratégia só **lê** Orçamento, Projectos, Estrutura, Contabilidade, CRM, Vendas, RH); não se duplica o planeamento orçamental já portado (ADR-044/045/046); não se porta o Manual da Organização nem o de Procedimentos (mas deixam-se prontos os pontos de consumo).
- **Fases:** F0 alicerces → F1 plano/perspectivas/objectivos → F2 indicadores, metas, medição e painel → (F3 iniciativas ∥ F6 diagnóstico ∥ F7a documento base) → F4 desdobramento → F5 revisões → F7b documento completo e versões → F8 integrações transversais → F9 endurecimento/ETL. **Esforço total ≈ 6–7 semanas de um agente** (ou ≈ 4 com paralelismo de 2). §13.
- **Decisões que pedem resposta antes de F1:** 12 perguntas no §15 (as 4 que bloqueiam: Q1 módulo/ids, Q2 plano de licença, Q3 visibilidade de valores financeiros, Q5 aprovação/congelamento).

---

## 1. Regras do sistema novo que condicionam esta importação (lidas)

| Regra | Fonte | Aplicação no módulo |
|---|---|---|
| Directiva 100 % em português; DE/PARA gerado; enumerações = código normalizado + texto | ADR-001/002/004 | tabelas, colunas, modelos, rotas em português; códigos `RASCUNHO`, `ACTIVO`, `VERDE`… como no legado |
| Multi-empresa fail-closed, RLS por `empresa_id`, `PertenceEmpresa`, `ContextoEmpresa` | ADR-006/073; `IsolamentoRls::aplicarATodas()` | todas as 17 tabelas com `empresa_id` + política RLS + GRANT a `erp_app`; contrato `IsolamentoRlsContratoTest` |
| Permissões com a mesma semântica do legado; catálogo **editado à mão** (nunca regenerar do v_boss); tarefas `sensivel`; perfis-modelo e `erp:perfis:actualizar-modelos` | ADR-008/024/077/079; memória «catálogo manual» | ids de ecrã/tarefa do legado (`est_plano`, `est_plano_editar`…) + tarefas novas; edição directa de `catalogo.json` |
| Módulo novo tem de entrar em **plano de licença** (`erp.planos_catalogo.modulos`), `ModulosPlano`, `ServicoNotificacoes`, `ServicoPaineis`, `menuLegado.ts`, `registo.tsx` | migração `2026_11_10_090000_planos_modulos_estrutura_metodo_acrescimos` (lição: sem isto «nenhum cliente com plano os via») | checklist de F0 |
| `exigir()` **no corpo da acção, antes de validar**; 403/404 sem revelar dados | `AssuntosMetodoController`, `OwaspControloAcessoTest` (varre TODAS as rotas com um utilizador sem permissões; regex `exigir(\|Gate::…`) | todas as acções; excepções só em `SEM_PERMISSAO_POR_DESENHO` com motivo |
| Acesso cruzado de empresa recusado | `AcessoCruzadoGeradoTest` | `EscopoEmpresa`; ids de outra empresa → 404 |
| Mensagens de erro seguras: só `ErroNegocio` e texto de validação; nada técnico | `MensagemSegura`, ADR-083/085 | todas as regras da §7 do inventário como `ErroNegocio(mensagem, CODIGO, 422)` |
| `SanitizarEntrada` global; uploads pelo validador central; CSP sem scripts inline | ADR-083 | texto livre do documento passa por escape; sem HTML aceite; sem scripts no documento |
| Auditoria automática dos modelos (`Auditavel`) + eventos de domínio | ADR-009; `Auditavel` | `moduloAuditoria = 'Estratégia'`; medições em lote registam 1 evento |
| Dual write: efeitos fora da BD por **Outbox** (`Outbox::registar` na transacção; manipulador idempotente); `after_commit` na fila | ADR-080 | notificações `notificacao.estrategia`; jobs com `ExecucaoIdempotente` |
| Cache: chave com âmbito (`ChaveCache::empresa`), autorizar fora da cache, invalidar depois do commit, versionar, TTL rede de segurança; lista de verificação do §7 de `CACHE.md` | `docs/arquitetura/CACHE.md` | só o catálogo de KPIs de gestão e o catálogo de fontes são cacheáveis |
| Dinheiro: 2 casas, arredondamento documentado | ADR-022 | monetários `numeric(18,2)`; valores de indicador `numeric(20,4)` (§3, decisão Q6); `bcmath` |
| Guardas de desempenho: máx. consultas por GET, sem N+1 | `Desempenho/ConsultasPorEndpointTest` | painel/mapa/desdobramento carregam em lote (3–6 consultas) |
| Operações longas em segundo plano com acompanhamento | `ServicoOperacoes` + `componentes/operacoes` | «Medir este período» e «Emitir versão» |
| Frontend: `/m/{modulo}/{ecra}`, ecrãs lazy em `ecras.ts`, `menuLegado.ts`, pesquisa gerada, `TabelaLegado`/`GradeCartoes`, motor de impressão, responsividade (xs…xxl), acessibilidade | `registo.tsx`, `docs/FRONTEND_RESPONSIVIDADE.md`, `docs/UI_DESIGN_SYSTEM.md`, `docs/ACESSIBILIDADE.md`, `componentes/impressao/README.md` | §9 |
| Migrações aditivas e idempotentes, CHECK/UNIQUE criados com a tabela (tabelas novas), `CREATE INDEX IF NOT EXISTS`, ensaiar em cópia; **produção tem dados reais** | POS F0, ADR-065 | tabelas novas (sem dados pré-existentes) → risco baixo; única alteração a tabela existente: nenhuma DDL (só validação em serviço, §5.4) |
| Sem push; commits por fase; após suite massiva `npm run build` (o frontend de teste serve de `frontend/dist`) | memória do projecto | critério de fecho de cada fase |
| Testes PHPUnit só via Docker (`docker exec erp_app_e2e php artisan test`), nunca dois em paralelo | memória do projecto | — |

---

## 2. Decisões de arquitectura (AD-EST)

| Id | Decisão | Porquê |
|---|---|---|
| AD-EST-01 | **Módulo próprio `estrategia`** (rota `/m/estrategia/{ecra}`, grupo de menu «Planeamento Estratégico»), com módulo de plano `estrategia` | o legado já o regista assim (`modulos_registo.js:20`) mas guarda as permissões em «Geral»; no novo cada módulo tem plano/menu/ícone próprios (pergunta Q1/Q2) |
| AD-EST-02 | **Manter os ids de ecrã e de tarefa do legado** (`est_plano`, `est_painel`, …, `est_casc_decidir`…) e acrescentar tarefas novas | perfis restaurados de um backup legado ≥ Dexie 1285 trazem estas chaves (ADR-008); não colidem com `est_estrutura`/`est_mapa`/`est_organigrama`/`est_ver_salarios`/`est_validar_entregas`/`est_multas_gerir`/`est_delegar` (verificado) |
| AD-EST-03 | **Todas as regras no servidor** (Laravel); o cliente só apresenta | EST-D-01: o legado não aplica nada no servidor |
| AD-EST-04 | **Só leitura sobre outros módulos, por serviços existentes** (nunca SQL cru sobre tabelas alheias, excepto a allowlist de fontes `MODULO`, §3.6) | evita duplicar regras (progresso de projecto, realizado orçamental, entregáveis validados) |
| AD-EST-05 | **17 tabelas normalizadas** com FK reais, UNIQUE e CHECK; `jsonb` só para a parte polimórfica da `fonte` do indicador e para o snapshot do documento | EST-D-05/06/14: o legado não tem integridade; `VerificadorReferencias` lê FK reais para bloquear «em uso» |
| AD-EST-06 | **Valores de indicador em `numeric(20,4)`**, cálculo com `bcmath` (escala 6) e arredondamento só na apresentação | EST-D-12/24/26: `8,1/9×100 = 89,99999999999999` em float marca VERMELHO um valor que é exactamente 90 % (AMARELO) |
| AD-EST-07 | **Medição gravada** (como no legado) com `UNIQUE` e upsert; histórico de re-medições pela auditoria | mantém a regra-mãe (DAD:14-16) sem tabela de histórico extra |
| AD-EST-08 | **Documento do Plano gerado no servidor** (Blade → HTML → `dompdf`), com **versões emitidas imutáveis** (snapshot `jsonb` + PDF + hash) | o legado imprime ao vivo sem versões e com script de paginação incompatível com a CSP (EST-D-20); o precedente do Relatório e Contas (RASCUNHO→APROVADO com fotografia) |
| AD-EST-09 | **Um só modelo de documento com dois perfis** (Executivo = documento A; Completo = A + secções do B) | elimina a duplicação A/B (§12.1 do inventário) |
| AD-EST-10 | **Responsáveis por FK** (`colaborador_id`) com texto livre só para externos | EST-D-14; permite notificações e permissões |
| AD-EST-11 | **Desdobramento em tabela própria** (`contributos_unidade` + `movimentos_contributo` imutável) | EST-D-05: separa meta da empresa de contributo; o histórico passa de JSON a linhas |
| AD-EST-12 | **Acções correctivas leves** em `linhas_revisao_estrategica`, com atalho opcional para o Método de Trabalho | I-13; paridade primeiro (pergunta Q9) |

---

## 3. Modelo de dados novo

### 3.1 Convenções (iguais às das tabelas recentes — `movimentos_caixa_pos`, `multas_entregavel`)
`id` bigserial; `empresa_id bigint NOT NULL` FK `empresas` RESTRICT; `criado_em`/`atualizado_em timestamptz` (`useCurrent`); `criado_por`/`atualizado_por varchar(150)` (nome do operador, como as outras); eliminação lógica `eliminado_em timestamptz NULL` **só** onde faz sentido (plano, objectivo, indicador sem medições); texto curto `varchar(n)`, longo `text`; datas de dia `date`; períodos `varchar(8)` com CHECK `^[0-9]{4}(-(0[1-9]|1[0-2]|T[1-4]|S[12]))?$` (chave do legado, ordenável); enumerações `varchar` + CHECK; FK `RESTRICT` (nunca `CASCADE` sobre dados de negócio), nomes `fk_<tabela>_<coluna>`, `ck_<tabela>_<regra>`, `ix_/ux_<tabela>_<…>`. Modelos: `Base/*Base.php` + classe final (padrão ADR-078: escritos à mão, o gerador só cobre a matriz do legado), `use Auditavel, PertenceEmpresa`, `protected string $moduloAuditoria = 'Estratégia'`.

### 3.2 Tabelas

| # | Tabela (modelo) | Colunas principais (tipo; nulidade; omissão) | Chaves, UNIQUE, CHECK, índices |
|---|---|---|---|
| 1 | `planos_estrategicos` (`PlanoEstrategico`) | `nome varchar(200)`; `ano_inicio smallint`; `ano_fim smallint`; `missao`, `visao`, `valores` `text` NULL; `estado varchar(12) default 'RASCUNHO'`; `versao smallint default 1`; `plano_anterior_id` FK self NULL (versões do plano, Q5); `aprovado_em/aprovado_por`, `encerrado_em/encerrado_por`; `motivo_reabertura varchar(300)` NULL; `eliminado_em` | CHECK `estado IN ('RASCUNHO','APROVADO','ENCERRADO')`; CHECK `ano_inicio > 1900 AND ano_fim >= ano_inicio AND ano_fim - ano_inicio <= 14` (o limite de 15 anos é novo: protege os ciclos de períodos); UNIQUE parcial `(empresa_id, nome, versao) WHERE eliminado_em IS NULL`; **UNIQUE parcial `(empresa_id) WHERE estado='APROVADO' AND eliminado_em IS NULL`** (se Q4 = sim); índice `(empresa_id, estado)` |
| 2 | `perspectivas_estrategicas` | `plano_estrategico_id` FK; `ordem smallint default 0`; `nome varchar(120)`; `descricao varchar(300)` NULL; `cor char(7) default '#475569'`; `icone varchar(40) default 'fa-bullseye'` (nome FontAwesome como no legado; o frontend já tem `IconeFa`) | CHECK `cor ~ '^#[0-9A-Fa-f]{6}$'` e `icone ~ '^fa-[a-z0-9-]+$'`; UNIQUE `(plano_estrategico_id, nome)`; índice `(plano_estrategico_id, ordem)` |
| 3 | `objectivos_estrategicos` | `plano_estrategico_id`, `perspectiva_id` FK; `objectivo_pai_id` FK self NULL; `unidade_organica_id` FK NULL; `responsavel_colaborador_id` FK `colaboradores` NULL; `responsavel_externo varchar(150)` NULL; `nome varchar(300)`; `descricao text` NULL; `horizonte varchar(10) default 'PLANO'`; `periodo varchar(8)` NULL; `estado varchar(12) default 'ACTIVO'`; `ordem int default 0`; `posicao_mapa numeric(5,2)` NULL; `eliminado_em` | CHECK horizonte IN (PLANO,TRIMESTRE); **CHECK `(horizonte='TRIMESTRE') = (periodo IS NOT NULL)`** e `periodo ~ '^[0-9]{4}-T[1-4]$'`; CHECK estado IN (ACTIVO,SUSPENSO,CONCLUIDO,ABANDONADO); CHECK `objectivo_pai_id <> id`; CHECK `posicao_mapa BETWEEN 0 AND 100`; índices `(plano_estrategico_id, perspectiva_id)`, `(objectivo_pai_id)`, `(unidade_organica_id)`; a perspectiva tem de ser do mesmo plano (verificado no serviço; trigger não) |
| 4 | `indicadores_estrategicos` | `plano_estrategico_id`, `objectivo_id` FK; `nome varchar(200)`; `descricao text`; `origem varchar(12)`; `rubrica_orcamental_id` FK NULL (parte da `fonte` com FK real); `fonte jsonb NOT NULL default '{}'` (restante configuração: `{modulo,chave}` \| `{prefixos[],natureza}` \| `{medida}` \| `{fonte,operacao,campo,filtros[]}`); `polaridade`, `tipo`, `formato`, `frequencia`, `agregacao` `varchar`; `unidade_medida varchar(30)` NULL; `linha_base numeric(20,4)` NULL; `linha_base_periodo varchar(8)` NULL; `dono_colaborador_id` FK NULL; `dono_externo varchar(150)` NULL; `prazo_dias smallint` NULL; `tolerancia_verde numeric(6,2) default 100`; `tolerancia_amarela numeric(6,2) default 90`; `ativo boolean default true`; `ordem int`; `eliminado_em` | CHECKs de todas as enumerações; `jsonb_typeof(fonte)='object'`; **CHECK `origem <> 'MANUAL' OR dono_colaborador_id IS NOT NULL OR dono_externo IS NOT NULL`**; **CHECK `(origem='ORCAMENTO') = (rubrica_orcamental_id IS NOT NULL)`**; CHECK `tolerancia_amarela > 0 AND tolerancia_verde >= tolerancia_amarela`; CHECK `prazo_dias BETWEEN 0 AND 120`; índices `(plano_estrategico_id, objectivo_id)`, `(origem)`, `(dono_colaborador_id)` |
| 5 | `metas_indicadores` (meta **da empresa**) | `indicador_id` FK; `periodo varchar(8)`; `valor numeric(20,4) NOT NULL`; `valor_minimo numeric(20,4)` NULL; `nota varchar(300)` NULL | **UNIQUE `(indicador_id, periodo)`**; CHECK formato do período; o período tem de ser da frequência do indicador (serviço) |
| 6 | `contributos_unidade` (desdobramento) | `indicador_id`, `unidade_organica_id` FK; `periodo`; `estado varchar(10) default 'PEDIDO'`; `valor_proposto numeric(20,4)` NULL; `valor_aceite numeric(20,4)` NULL; `fundamentacao varchar(500)`; `motivo_decisao varchar(500)`; `pedido_por/pedido_em`, `proposto_por/proposto_em`, `decidido_por/decidido_em` | **UNIQUE `(indicador_id, periodo, unidade_organica_id)`**; CHECK estado IN (PEDIDO,PROPOSTO,DEVOLVIDO,ACEITE); CHECK `estado <> 'ACEITE' OR valor_aceite IS NOT NULL`; CHECK `estado NOT IN ('PROPOSTO','ACEITE') OR valor_proposto IS NOT NULL`; índice `(unidade_organica_id, estado)` |
| 7 | `movimentos_contributo` (**imutável**) | `contributo_id` FK; `accao varchar(10)` (PEDIDO,PROPOSTO,ACEITE,DEVOLVIDO,REABERTO); `valor numeric(20,4)` NULL; `motivo varchar(500)`; `por varchar(150)`; `em timestamptz` | GRANT só `SELECT, INSERT` a `erp_app` (imutável por privilégio); índice `(contributo_id, em)` |
| 8 | `medicoes_indicadores` | `indicador_id` FK; `periodo`; `unidade_organica_id` FK NULL (nulo = empresa); `valor numeric(20,4) NOT NULL`; `origem varchar(12)`; `nota varchar(300)`; `detalhe varchar(300)`; `medido_em timestamptz`; `medido_por varchar(150)` | **dois UNIQUE parciais**: `(indicador_id, periodo) WHERE unidade_organica_id IS NULL` e `(indicador_id, periodo, unidade_organica_id) WHERE unidade_organica_id IS NOT NULL` (upsert `ON CONFLICT`); índice `(indicador_id, periodo)`. Medição sem valor **não se grava** (o legado gravava `null`; o semáforo era o mesmo, «por medir») |
| 9 | `ligacoes_objectivos` (causa→efeito) | `plano_estrategico_id`, `de_objectivo_id`, `para_objectivo_id` FK; `nota varchar(300)` | UNIQUE `(de_objectivo_id, para_objectivo_id)`; CHECK `de <> para`; ciclos recusados no serviço sob `lockForUpdate` do plano |
| 10 | `iniciativas_estrategicas` | `plano_estrategico_id`, `objectivo_id` FK; `nome varchar(300)`; `descricao text`; `tipo varchar(10)`; `projeto_id` FK NULL; `entregavel_id` FK `entregaveis_tarefa_unidade` NULL; `rubrica_orcamental_id` FK NULL; `responsavel_colaborador_id`, `responsavel_externo`; `unidade_organica_id` FK NULL; `data_inicio`, `data_fim` `date`; `orcamento_previsto numeric(18,2)` NULL; `estado varchar(10) default 'PREVISTA'`; `execucao_declarada numeric(5,2)` NULL; `assunto_metodo_trabalho_id` FK NULL (Q9, opcional) | CHECK tipo IN (PROJECTO,ENTREGAVEL,RUBRICA,LIVRE); **CHECK que exactamente a FK do tipo está preenchida** (`(tipo='PROJECTO')=(projeto_id IS NOT NULL)`, idem para os outros) e `execucao_declarada` só em LIVRE e entre 0 e 100; CHECK `data_fim >= data_inicio`; CHECK estado IN (PREVISTA,EM_CURSO,CONCLUIDA,SUSPENSA,CANCELADA); índices `(plano, objectivo)`, `(projeto_id)`, `(entregavel_id)`, `(rubrica_orcamental_id)` |
| 11 | `revisoes_estrategicas` | `plano_estrategico_id`; `periodo`; `estado varchar(8) default 'ABERTA'`; `data_reuniao date`; `participantes text`; `notas text`; `fechada_em/fechada_por`; `reaberta_em/reaberta_por`; `motivo_reabertura varchar(300)` | UNIQUE `(plano_estrategico_id, periodo)`; CHECK estado IN (ABERTA,FECHADA); o período tem de estar no ciclo do plano (serviço) |
| 12 | `linhas_revisao_estrategica` | `revisao_id` FK; `indicador_id` FK; `unidade_organica_id` FK NULL; **snapshot**: `objectivo_id`, `indicador_nome varchar(200)`, `formato`, `polaridade`, `periodo`, `meta numeric(20,4)`, `valor numeric(20,4)`, `semaforo varchar(10)`, `cumprimento numeric(9,2)`; `causa text`; `accao text`; `accao_responsavel_colaborador_id` FK, `accao_responsavel_externo`; `accao_prazo date`; `accao_estado varchar(10) default 'SEM_ACCAO'`; `accao_concluida_em date`; `accao_nota varchar(500)` | UNIQUE `(revisao_id, indicador_id, COALESCE(unidade_organica_id,0))`; CHECK semaforo IN (5), accao_estado IN (4); **CHECK `(accao_estado = 'SEM_ACCAO') = (accao IS NULL OR accao = '')`** e `accao_estado = 'SEM_ACCAO' OR (accao_prazo IS NOT NULL AND (accao_responsavel_colaborador_id IS NOT NULL OR accao_responsavel_externo IS NOT NULL))`; índice parcial `(accao_prazo) WHERE accao_estado = 'ABERTA'` (alertas) |
| 13 | `factores_diagnostico` | `plano_estrategico_id`; `tipo varchar(5)`; `categoria varchar(15)`; `texto varchar(500)`; `detalhe text`; `impacto smallint default 3`; `probabilidade smallint default 3`; `influencia smallint default 3`; `interesse smallint default 3`; `resposta text`; `ordem int` | CHECK tipo IN (SWOT,PESTEL,PARTE,TOWS); **CHECK `(tipo,categoria)` ∈ combinações válidas** (lista do §3 do inventário); CHECK 1..5 nos quatro níveis; índice `(plano_estrategico_id, tipo)`. A **prioridade não se grava** (calcula-se, EST-C-19) |
| 14 | `factores_objectivos` (pivô) | `factor_id`, `objectivo_id`, `empresa_id` | PK composta; FK RESTRICT (o legado deixava `objective_ids` órfãos) |
| 15 | `factores_origens` (pivô TOWS→SWOT) | `factor_id` (TOWS), `factor_origem_id` (SWOT), `empresa_id` | PK composta; serviço valida os tipos |
| 16 | `documentos_plano_estrategico` (modelo editável, 1:1 com o plano) | `plano_estrategico_id` UNIQUE; `titulo varchar(200)`; `etiqueta_versao varchar(20) default '1.0'`; `data_documento date` NULL; `elaborado_por`, `aprovado_por` `varchar(150)`; `aprovado_em date` NULL; `perfil varchar(12) default 'EXECUTIVO'`; `opcoes jsonb` (`incluir_mapa`, `incluir_indicadores`, `incluir_iniciativas`, `incluir_unidades`, `incluir_medicoes`, + novos `incluir_diagnostico`, `incluir_desdobramento`, `incluir_situacao`, `incluir_revisoes`); `textos jsonb` (`apresentacao`, `diagnostico`, `metodologia`, `monitorizacao`, `notas`; cada um ≤ 20 000 caracteres) | CHECK perfil IN (EXECUTIVO,COMPLETO); `jsonb_typeof = 'object'`; chaves validadas no FormRequest com lista fechada |
| 17 | `versoes_documento_plano` (**imutável** excepto anulação) | `plano_estrategico_id`, `documento_id` FK; `sequencia int`; `etiqueta varchar(20)`; `perfil`; `periodo_referencia varchar(8)`; `titulo varchar(200)`; `estado varchar(8) default 'EMITIDA'`; `dados jsonb` (snapshot completo ≤ 2 MB); `ficheiro varchar(300)` (disco privado); `tamanho int`; `sha256 char(64)`; `paginas smallint`; `emitido_por/emitido_em`; `anulado_por/anulado_em`; `motivo_anulacao varchar(300)` | UNIQUE `(plano_estrategico_id, sequencia)`; CHECK estado IN (EMITIDA,ANULADA); CHECK `(estado='ANULADA') = (anulado_em IS NOT NULL)`; GRANT `SELECT, INSERT, UPDATE` (UPDATE só pela anulação, verificado no serviço; o conteúdo nunca se altera) |

**Totais:** 17 tabelas (6 núcleo + 2 desdobramento + 2 estrutura (ligações, iniciativas) + 2 revisões + 3 diagnóstico + 2 documento). Todas com `empresa_id` e RLS.

### 3.3 RLS, GRANT e migrações
Cada migração termina com `(new IsolamentoRls(DB::connection()))->aplicarATodas()` e GRANT idempotente a `erp_app` (padrão de `2026_12_09_090000_pos_movimentos_caixa.php`: `DO $$ … IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname='erp_app') … $$`, sequências incluídas). `Schema::hasTable` em volta de cada `create`; CHECK/UNIQUE definidos com a tabela (sem dados anteriores não há que limpar duplicados); `CREATE INDEX IF NOT EXISTS` para os parciais; `down()` vazio (aditivo). Nomes e ordem (datas ≥ `2026_12_13`): `…_estrategia_nucleo` (1–5, 8, 9), `…_estrategia_desdobramento` (6, 7), `…_estrategia_iniciativas_revisoes` (10–12), `…_estrategia_diagnostico` (13–15), `…_estrategia_documento` (16, 17), `…_estrategia_plano_licenca` (acrescenta `estrategia` às versões de plano que têm `orcamento` **ou** `rh`, como a migração de Novembro; `ModulosPlano::invalidar()`).
**Produção tem dados reais:** nenhuma migração toca em tabelas existentes (a delegação de chefia ganha o âmbito `ESTRATEGIA` **só no serviço**: `delegacoes_chefia.ambito` é `jsonb` sem CHECK). Ensaio obrigatório: aplicar na cópia local (PG 18 nativo) e correr `IsolamentoRlsContratoTest`.
**Fora do `esquema.json`:** as 17 tabelas não existem no legado documentado (como as da Fase J, ADR-079 «Fora de âmbito»): o contrato `esquema.json`/`CoberturaCampos*` só cobre a matriz do legado — confirmar na F0 que nenhum teste de contrato falha por tabelas «desconhecidas» e, se necessário, declará-las na lista de excepções desses testes.

### 3.4 Mapeamento campo a campo legado → novo (ETL e contrato)
| Legado | Novo | Notas |
|---|---|---|
| `est_plans.est_company_id/nome/ano_inicio/ano_fim/missao/visao/valores/estado/grupo_id` | `planos_estrategicos.empresa_id/nome/ano_inicio/ano_fim/missao/visao/valores/estado` (+`versao=1`) | `grupo_id` **descartado** (nunca usado) |
| `est_plans.doc{…}` | `documentos_plano_estrategico` (`titulo`, `etiqueta_versao`←`versao`, `data_documento`, `elaborado_por`, `aprovado_por`, `aprovado_em`, `opcoes{incluir_*}`, `textos{…}`) | `incluir_*` ausentes → omissões do legado |
| `est_perspectives.*` | `perspectivas_estrategicas` (`icone` igual, validado por regex) | |
| `est_objectives.perspective_id/pai_id/unit_id/responsavel/mapa_x/…` | `perspectiva_id/objectivo_pai_id/unidade_organica_id/responsavel_colaborador_id∨responsavel_externo/posicao_mapa` | `responsavel` texto: tenta `colaboradores.nome_completo` exacto e único, senão `responsavel_externo` |
| `est_indicators.fonte{rubric_id}` | `rubrica_orcamental_id` (mapa `orc_rubrics`→`rubricas_orcamentais`) | os restantes campos ficam em `fonte` jsonb com os **ids novos de módulo/fonte** (§3.5) |
| `est_indicators.dono` | `dono_colaborador_id` ∨ `dono_externo` | idem responsável |
| `est_indicators.tol_verde/tol_amarelo/activo` | `tolerancia_verde/tolerancia_amarela/ativo` | |
| `est_targets` sem `unit_id` | `metas_indicadores` | `valor`, `minimo`→`valor_minimo`, `nota` |
| `est_targets` com `unit_id` | `contributos_unidade` (`proposta`→`valor_proposto`; `valor` com ACEITE→`valor_aceite`; `motivo`→`fundamentacao`/`motivo_decisao` conforme o último movimento) + `historico[]`→`movimentos_contributo` | `unit_id` via mapa `org_units`→`unidades_organicas` |
| `est_measures` | `medicoes_indicadores` (`valor` nulo → **não migra**) | |
| `est_links` | `ligacoes_objectivos` | ciclos pré-existentes: relatar e não importar a aresta que fecha o ciclo |
| `est_initiatives.tipo/ref_id` | `tipo` + `projeto_id` ∨ `entregavel_id` ∨ `rubrica_orcamental_id` | `ref_id` que já não existe → importa como LIVRE com `execucao_declarada = 0` e aviso |
| `est_reviews`, `est_review_lines` | `revisoes_estrategicas`, `linhas_revisao_estrategica` | `accao_responsavel` texto como acima |
| `est_context` | `factores_diagnostico` + `factores_objectivos` (de `objective_ids`) + `factores_origens` (de `origem_ids`) | ids que já não existem: descartados e relatados |

### 3.5 Fontes de indicadores — mapeamento e allowlist (`CatalogoFontesModulo`)
O cliente **nunca** envia nomes de tabela/coluna: envia o **id da fonte** e um **id de campo/filtro**; o servidor resolve a partir de uma lista fechada em PHP (com a coluna da empresa, a coluna de data e as expressões de valor como constantes). Mapeamento proposto (a confirmar contra o esquema real na F2):

| Id (legado → novo) | Tabela nova | Data | Valores | Filtros | Unidade orgânica |
|---|---|---|---|---|---|
| `sales` → `vendas` | `vendas` | `data_emissao` | `total_bruto`, `total_liquido` | `tipo_documento`, `estado`, `terceiro_id` | — |
| `purchases` → `compras` | `faturas_compra` | `data` | `montante_total`, `montante_total − total_imposto` («sem IVA») | `estado`, `fornecedor_id` | — |
| `projects` → `projectos` | `projetos` (**sem** `start_date`/`progress` no novo: o progresso vem de `RegrasMetasProjetos`/tarefas; se não for viável por SQL, fonte só por **contagem** e `estado`) | `criado_em` (a confirmar) | — | `estado`, `tipo` | — |
| `proj_tasks` → `tarefas_projeto` | `tarefas_projeto` | `data_fim` | `percentagem_execucao` | `estado`, `projeto_id` | — |
| `crm_opp` → `oportunidades_crm` | `oportunidades_venda_crm` | `data_fecho_prevista` | `valor`, `probabilidade` | `estado`, `funil_vendas_crm_id` | — |
| `crm_act` → `actividades_crm` | `atividades_comerciais_crm` | `data_prevista` | — | `tipo`, `concluida` | — |
| `entregaveis` | `execucoes_entregavel` | `data` | `quantidade` | `validacao`, `unidade_organica_id`, `colaborador_id` | `unidade_organica_id` |
| `colaboradores` | `colaboradores` | `data_admissao` | **sem valores** (`base_salary` do legado **não** se porta: é dado sensível e nem existe na tabela) | `estado`, `unidade_organica_id` | `unidade_organica_id` |
| `ausencias` | `ausencias_faltas_colaboradores` | `data_inicio` | `dias` | `tipo`, `estado` | — |
| `manutencao` | `ordens_manutencao_ativos` | `data_prevista` | `custo_mao_obra + custo_materiais + custo_servicos` | `estado`, `tipo` | — |
| `formacao` | **sem tabela equivalente** → fonte **omitida** (não inventar) | | | | |

Os módulos de **KPI de gestão** (`origem = KPI_GESTAO`) usam os ids dos módulos de `ServicoRelatoriosGestao` (`financas`, `vendas`, `compras`, `tesouraria`, `stock`, `rh`, `activos`, `projectos`, `servicos`; ~112 `k()` no código novo contra «116» no legado). **Catálogo estático**: `GET /estrategia/catalogo/kpis` devolve `{modulo, nome, kpis[{chave, rotulo, formato, sentido}]}` calculado **uma vez** por (empresa, versão do código) com `Cache::flexible` (regras de `CACHE.md` §4/§7; chave `ChaveCache::empresa(…,'estrategia','catalogo_kpis')`, sem valores, só metadados) — corrige EST-D-16. Polaridade de um KPI: `sobe`→SOBE, `desce`→DESCE, `neutro`→ALVO (o legado usava `melhor`).

---

## 4. Backend

### 4.1 Serviços (`App\Services\Estrategia`)
| Classe | Responsabilidade | Funcionalidades |
|---|---|---|
| `Periodos` (puro, estático) | chave↔intervalo↔rótulo, `periodosDe(freq, ano)`, `equivalente(freq, periodo)`, `anterior`, `actual`, ciclos | EST-C-06/07/08 |
| `CalculadoraDesempenho` (puro, `bcmath`) | `cumprimento(pol, v, m)`, `semaforo(ind, v, m)`, `piorCor`, `escada`, `prioridade`, `tratamento` | EST-C-01…05,14,19,20,22 |
| `ServicoPlanosEstrategicos` | CRUD, máquina de estados, plano activo, nova versão do plano (Q5), apagar rascunho | F-001…012 |
| `ServicoObjectivosEstrategicos` | perspectivas, objectivos, OKR, pai sem ciclos, apagar com bloqueios, ordenar | F-013…024 |
| `ServicoLigacoesObjectivos` | causa-efeito sem ciclos (`lockForUpdate`), posições do mapa | F-061…066 |
| `ServicoIndicadoresEstrategicos` | CRUD, validação por origem (uma `Rule` por origem), testar medição, catálogos | F-025…039 |
| `ServicoMetasIndicadores` | gravar/limpar metas em lote, validar período×frequência e ciclo | F-040…043 |
| `Fontes\FonteIndicador` (interface) + `FonteKpiGestao`, `FonteContas`, `FonteOrcamento`, `FonteModulo`, `FonteManual` | cada uma devolve `{valor, detalhe}` ou `ErroNegocio`; leem por serviços/consultas allowlisted | F-044…048 |
| `MotorMedicao` | contexto partilhado por corrida (cache por módulo+período), medir um/um plano, upsert da medição, nunca lança por indicador | F-044…051 |
| `ServicoPainelEstrategico` | painel, série, mapa, resumos para Início/Portal/Relatório e Contas, em lote | F-052…057, 060, 128 |
| `ServicoIniciativas` + `Execucao\*` | CRUD; leitores `ExecucaoProjecto` (`RegrasMetasProjetos`, `ServicoFinanceiroProjetos`, `ServicoOrcamentoProjetos`), `ExecucaoEntregavel` (`ServicoEntregaveis::resumoPeriodo`), `ExecucaoRubrica` (`ServicoExecucaoOrcamental::realizado`), `ExecucaoLivre` | F-068…076 |
| `ServicoDesdobramento` | contributos (máquina de estados), cobertura, `podePropor` (chefe da unidade ou delegado, `ServicoEntregaveis::unidadesPorQueRespondo`/`ServicoDelegacoesChefia`), realizado da unidade | F-077…088 |
| `ServicoRevisoesEstrategicas` | abrir (mede + fotografia), linhas, regras, fechar/reabrir/apagar, acções abertas | F-089…102, 140 |
| `ServicoDiagnostico` | factores, prioridade, por tratar, gerar objectivo, ligar | F-103…115 |
| `ServicoDocumentoPlano` + `RenderizadorDocumentoPlano` | modelo, recolha, avisos de lacunas, renderização Blade, PDF, versões | F-116…127 |
| `Pontes\ObjectivosParaAvaliacao`, `Pontes\MeusObjectivosPortal`, `Pontes\ResumoAnualPlano` | consumidores: RH › Avaliação, Portal, Relatório e Contas | F-130…133 |
| `NotificadorEstrategia`, `Console\AlertasEstrategiaCommand` | notificações (Outbox) e alertas de prazos | §7 |

Padrão: serviços transaccionais (`DB::transaction`), `ErroNegocio(mensagem, CODIGO, 422)` para regras de negócio (códigos novos: `PLANO_SEM_ORIGEM`, `PLANO_MANUAL_SEM_DONO`, `PLANO_FECHADO`, `PERIODO_INVALIDO`, `LIGACAO_CICLO`, `CONTRIBUTO_ESTADO`, `AUTO_DECISAO`, `REVISAO_INCOMPLETA`, `REVISAO_FECHADA`, `INDICADOR_COM_MEDICOES`, `PLANO_APROVADO_BLOQUEADO`, …), `lockForUpdate` onde há corrida (ciclo, estado de contributo, fecho de revisão, emissão de versão).

### 4.2 Regras que mudam face ao legado (todas com teste)
1. **Máquina de estados do plano** explícita: RASCUNHO→APROVADO, APROVADO→RASCUNHO (reabrir, com motivo), APROVADO→ENCERRADO; ENCERRADO só de leitura (EST-D-02).
2. **Aprovar** exige o que o legado exige (origem em todos os indicadores activos; dono nos MANUAL) **e** (novo) ≥ 1 objectivo ACTIVO, todos os objectivos activos com ≥ 1 indicador activo, e todos os indicadores activos com ≥ 1 meta (mensagem lista os 3 primeiros) — «um objectivo que não se mede não se cumpre» (DOC:162). *Decisão Q5.*
3. **Plano APROVADO**: estrutura (objectivos, indicadores, ligações) e metas só editáveis com `est_plano_aprovar` e motivo registado, ou por **nova versão do plano** (cópia RASCUNHO) — Q5. Medições, contributos, revisões, iniciativas e diagnóstico continuam livres.
4. **Um APROVADO por empresa** (UNIQUE parcial) — Q4.
5. **Revisão (EST-D-07):** abrir mede e **fotografa** (meta, valor, semáforo, cumprimento) como no legado; **enquanto ABERTA** há «Actualizar fotografia» (volta a ler as medições gravadas e refresca só as linhas sem causa/acção escritas); **fechar sela** o que está. Assim o comentário do esquema legado («do momento do fecho») passa a ser verdade sem recalcular o Diário. *Decisão Q7.*
6. **ALVO** na cobertura: `|assumido − meta| ≤ meta × (100 − tolerancia_amarela)/100` (EST-D-10).
7. **`ciclos no pai`** recusados (`objectivo_pai_id`), como as ligações (EST-D-08).
8. **Comparações em decimal** (EST-D-26): `pct = bcdiv(v, m, 6) × 100`; `≥` com `bccomp`.
9. **Segregação:** quem propôs um contributo não o aceita (`AUTO_DECISAO`), como as multas (ADR-079); quem lança uma medição manual não a pode apagar noutra pessoa (só substituir; a auditoria guarda o valor anterior).
10. **Quem pode propor** é verificado no servidor (EST-D-01): `colaborador_responsavel_id` da unidade, descendentes sob a sua chefia, delegado com âmbito `ESTRATEGIA` (ADR-079, **sem cadeia**), ou — se a unidade não tem responsável — membro da unidade; quem tem `est_casc_propor` mas não é nenhum destes recebe 403 (`FORA_DA_UNIDADE`).
11. **Valores financeiros** (origens CONTA, ORCAMENTO, KPI_GESTAO de `financas|tesouraria`) só são devolvidos a quem tem `est_ver_financeiros` **ou** a vista do módulo de origem (Q3); caso contrário `valor/meta` vêm `null` com `oculto: true` e o semáforo mantém-se (o semáforo não revela o valor).

---

## 5. API, rotas, autorização e testes de contrato

### 5.1 Rotas (`routes/api/estrategia.php`, incluído por `routes/api.php` no grupo `auth:sanctum` + `empresa`; nomes `estrategia.*`)
Convenção do projecto (`/api/{modulo}/{recurso}`), verbos e `whereNumber`. Cada acção começa por `$this->exigir(...)` com a tarefa/vista da tabela §6 **antes** de validar.

| Grupo | Rotas (método caminho → acção) | Exige |
|---|---|---|
| Planos | `GET planos`, `POST planos`, `GET planos/{p}`, `PUT planos/{p}`, `POST planos/{p}/aprovar`, `…/reabrir`, `…/encerrar`, `POST planos/{p}/nova-versao`, `DELETE planos/{p}` (só RASCUNHO sem dados) | `est_plano_view` (ler); `est_plano_editar`; `est_plano_aprovar` (estados) |
| Estrutura | `GET planos/{p}/estrutura`; `POST/PUT/DELETE planos/{p}/perspectivas[/{id}]`, `POST …/perspectivas/ordenar`; `POST/PUT/DELETE planos/{p}/objectivos[/{id}]` | `est_plano_view` / `est_plano_editar` |
| Mapa | `GET planos/{p}/mapa?periodo=`; `PUT planos/{p}/mapa/posicoes` (lote); `POST/DELETE planos/{p}/ligacoes[/{id}]` | `est_mapa_estrategico_view` / `est_mapa_editar` |
| Indicadores e metas | `GET planos/{p}/indicadores`; `POST/PUT/DELETE planos/{p}/indicadores[/{id}]`; `POST indicadores/{i}/testar`; `GET/PUT indicadores/{i}/metas`; `GET estrategia/catalogo/{kpis|fontes|rubricas}` | `est_indicadores_view`; `est_ind_editar`; `est_metas_editar` |
| Painel e medição | `GET planos/{p}/painel?periodo=`; `GET indicadores/{i}/serie?ano=`; `POST planos/{p}/medicoes` (**202** + operação em segundo plano); `POST indicadores/{i}/medicoes` (entrega manual) | `est_painel_view`; `est_medir`; `est_entregar` |
| Iniciativas | `GET planos/{p}/iniciativas`; `POST/PUT/DELETE planos/{p}/iniciativas[/{id}]`; `GET estrategia/iniciativas/alvos?tipo=` | `est_iniciativas_view`; `est_inic_editar` |
| Desdobramento | `GET planos/{p}/desdobramento?periodo=`; `POST indicadores/{i}/contributos`; `POST contributos/{c}/propor|aceitar|devolver|reabrir|realizado`; `DELETE contributos/{c}` | `est_cascata_view`; `est_casc_pedir|propor|decidir|medir` |
| Revisões | `GET planos/{p}/revisoes`; `POST planos/{p}/revisoes` (**202**); `GET/PUT revisoes/{r}`; `PUT revisoes/{r}/linhas/{l}`; `POST revisoes/{r}/fechar|reabrir`; `DELETE revisoes/{r}`; `GET planos/{p}/accoes`; `POST linhas/{l}/accao/concluir|cancelar` | `est_revisoes_view`; `est_rev_abrir|escrever|fechar|accoes` |
| Diagnóstico | `GET planos/{p}/factores`; `POST/PUT/DELETE planos/{p}/factores[/{id}]`; `POST factores/{f}/gerar-objectivo`; `PUT factores/{f}/objectivos` | `est_diagnostico_view`; `est_diag_editar|objectivo` |
| Documento | `GET planos/{p}/documento`; `PUT planos/{p}/documento`; `GET planos/{p}/documento/previa?periodo=&perfil=` (JSON: `html` já sanitizado + `avisos` + `totais`); `GET planos/{p}/documento/versoes`; `POST planos/{p}/documento/versoes` (**202**, emite); `GET documento/versoes/{v}/pdf`; `POST documento/versoes/{v}/anular` | `est_documento_view`; `est_doc_editar`; `est_doc_emitir` |
| Consumidores | `GET estrategia/portal/meus-objectivos`; `GET estrategia/resumo-anual/{ano}` (Relatório e Contas); `GET estrategia/inicio` (3+2 contadores) | `rh_portal_view` + serviço; `relatorio_contas_view`; `est_painel_view`/`est_revisoes_view`/`est_cascata_view` |

(≈ 73 endpoints; prefixo `estrategia/`.) **Excel/impressão das tabelas** continuam no cliente (motor `componentes/impressao`, `gerarXlsx`); o servidor só gera o PDF do Documento do Plano.

### 5.2 Classificação nos testes de rotas
- `OwaspControloAcessoTest`: todas as acções têm `$this->exigir(` → passam. **Única entrada nova em `SEM_PERMISSAO_POR_DESENHO`**: `GET api/estrategia/portal/meus-objectivos` («portal do colaborador: só os objectivos da unidade do próprio; `exigirColaborador` no serviço»), e **só se** o endpoint não exigir `rh_portal_view` (preferível exigir e não listar).
- `AcessoCruzadoGeradoTest`: varre as rotas com empresa de outro cliente/inexistente → `EscopoEmpresa` + `PertenceEmpresa` (404/403); sem trabalho extra se os modelos usam o trait.
- `ConsultasPorEndpointTest` (guarda gerada de `route:list`): painel, mapa, desdobramento e `estrutura` com consultas **em lote** (sem N+1) — fixtures com 4 perspectivas, 20 objectivos, 60 indicadores, 700 metas/medições; tectos a definir na F2 por medição.
- `EntradasMaliciosasListagensTest` (varre `q|pesquisa|filtro*|ordenar*`): as listagens com `pesquisa` usam `ILIKE` com escape (`str_replace` de `\ % _` como em `AssuntosMetodoController::artigos`) e `Rule::in` nas ordenações.
- `IsolamentoRlsContratoTest`: as 17 tabelas com política RLS; teste de leitura cruzada.
- Portal: acrescentar os **nomes de rota** `estrategia.portal.*` à lista branca de `ExigirAreaPortal` (lição da Fase J: «a lista branca do subdomínio do portal bloqueava…»).
- `ferramentas/paridade/rotas.json`/`indice de pesquisa`/`DICIONARIO_DADOS.md`: regenerar com os comandos do projecto no fecho de F8.

### 5.3 Contrato de resposta
`RespostaApi::sucesso($dados, 'Mensagem.')`; listas paginadas (25) onde crescem (factores, revisões, versões) e **não paginadas** onde o ecrã precisa do plano inteiro (estrutura, painel, mapa — com tecto de 500 objectivos/indicadores). Resources: `PlanoEstrategicoResource`, `ObjectivoEstrategicoResource`, `IndicadorEstrategicoResource` (devolve `fonte` com ids e rótulos, **nunca** nomes de tabela), `LinhaPainelResource`, `ContributoUnidadeResource` (com `movimentos` quando pedido), `RevisaoEstrategicaResource`, `FactorDiagnosticoResource` (inclui `prioridade`, `tratamento`), `VersaoDocumentoPlanoResource` (sem `dados`; o snapshot só para `est_doc_emitir`). Datas de dia como `AAAA-MM-DD` (`date:Y-m-d`), instantes ISO-8601.

### 5.4 Pequenas alterações a código existente (sem DDL)
`ServicoDelegacoesChefia` (+ âmbito `ESTRATEGIA` e `FormRequest` correspondente); `ServicoAvaliacao` (+ ponte `EST-*`, I-04); `ServicoPortalColaborador` (+ separador); `ServicoInicio::pendentes()` (+5 contadores); `ServicoPaineis` (+`PainelEstrategia`); `ServicoNotificacoes` (+categoria `estrategia`); `ServicoRelatorioContas`/`RelatorioContas.tsx` (+ capítulo opcional); `Gestao/Fluxos/narrativas.json` (+ processo e 5 ligações); `ExigirAreaPortal`; `config/erp.php` (módulo no catálogo de planos; bloco `estrategia` com os limiares dos alertas).

---

## 6. Permissões (catálogo editado à mão)

**Módulo novo `estrategia` («Planeamento Estratégico») em `catalogo.json`** com 9 ecrãs (ids do legado) e as 19 tarefas do legado com os mesmos rótulos e `sensivel` (3), mais **2 tarefas novas**:

| Ecrã | Vistas | Tarefas (chave — rótulo — sensível) |
|---|---|---|
| `est_plano` | `est_plano` | `est_plano_editar` — Criar e editar planos, perspectivas e objectivos · `est_plano_aprovar` — Aprovar, reabrir e encerrar o plano; **alterar a estrutura de um plano aprovado** — **sensível** |
| `est_diagnostico` | idem | `est_diag_editar`, `est_diag_objectivo` |
| `est_mapa_estrategico` | idem | `est_mapa_editar` |
| `est_documento` | idem | `est_doc_editar`; **novo** `est_doc_emitir` — Emitir e anular versões do documento do plano — **sensível** |
| `est_iniciativas` | idem | `est_inic_editar` |
| `est_cascata` | idem | `est_casc_pedir`, `est_casc_propor`, `est_casc_decidir` (**sensível**), `est_casc_medir` |
| `est_revisoes` | idem | `est_rev_abrir`, `est_rev_escrever`, `est_rev_fechar` (**sensível**), `est_rev_accoes` |
| `est_indicadores` | idem | `est_ind_editar`, `est_metas_editar` |
| `est_painel` | idem | `est_medir`, `est_entregar`; **novo (Q3)** `est_ver_financeiros` — Ver valores de indicadores de origem financeira (contas, orçamento, indicadores de finanças e tesouraria) — **sensível**, no ecrã `est_painel` |

Total no novo: **9 ecrãs, 21 tarefas (5 sensíveis)**. Efeito: `ModulosPlano` capa o menu e o Gate ao plano de licença; `ServicoNotificacoes::MODULOS_ECRAS['estrategia']` = as 9 vistas.
**Notas do catálogo** (do legado, mantidas) + acrescentar em `est_plano`: «Quem vê o plano vê o semáforo de todos os indicadores; os valores financeiros só com `est_ver_financeiros` ou com acesso ao módulo de origem.»
**Perfis-modelo** (editar à mão e depois `erp:perfis:actualizar-modelos --simular`): novo **«Gestor de Estratégia»** (todas as `est_*` do módulo, **sem** `est_plano_aprovar`/`est_casc_decidir`/`est_rev_fechar`/`est_doc_emitir`); **Direcção** (+ `est_*_view`, as 5 sensíveis, `est_ver_financeiros`); **Controller / Gestão Orçamental** (+ vistas, `est_ind_editar`, `est_metas_editar`, `est_medir`, `est_entregar`, `est_ver_financeiros`); **Responsável de Departamento** (+ `est_painel_view`, `est_cascata_view`, `est_casc_propor`, `est_rev_accoes`); **Auditor / Consulta** (+ vistas, **sem** `est_ver_financeiros`); **Colaborador (só portal)**: nada (o separador do portal depende de `rh_portal`).
**Segregação** (`segregacao`, proposta, a decidir em Q5): `est_plano_editar` × `est_plano_aprovar` («Edita e aprova o mesmo plano») e `est_casc_propor` × `est_casc_decidir`.
Nenhuma tarefa é herdada do legado «Geral»; **o ETL de perfis** (`perfis_convertidos.json`) é regenerado só para as chaves `est_*` se o backup legado as trouxer (§12).

---

## 7. Notificações, alertas, Início e Painel

**Notificações** (`NotificadorEstrategia`, `DB::afterCommit` + Outbox `notificacao.estrategia`, idempotente por `chave_origem = est:{tipo}:{id}:{utilizador}`; destinatário = utilizador ligado ao colaborador; nunca falha a operação, ADR-079/080):
| Evento | Destinatário | Gravidade | Ligação |
|---|---|---|---|
| Contributo pedido | chefe da unidade (e delegados) | AVISO | `/m/estrategia/est_cascata` |
| Contributo proposto | quem tem `est_casc_decidir` | AVISO | idem |
| Contributo aceite/devolvido | quem propôs | SUCESSO/AVISO | idem |
| Acção correctiva atribuída | responsável da acção | AVISO | `/m/estrategia/est_revisoes` |
| Plano aprovado/reaberto/encerrado | `est_plano_view` | SUCESSO/AVISO | `est_plano` |
| Indicador passou a VERMELHO ao medir | dono, responsável do objectivo | URGENTE | `est_painel` |
| Revisão fechada/reaberta | participantes com utilizador | SUCESSO/AVISO | `est_revisoes` |
**Alertas de prazos** (`erp:estrategia:alertas`, diário às 07:30, `withoutOverlapping`, `onOneServer`; limiares em `config('erp.estrategia')`): acção correctiva a vencer em ≤ 3 dias e vencida; indicador MANUAL por entregar passado o `prazo_dias` (ou 5 dias, omissão) do fim do período; contributo PEDIDO/DEVOLVIDO sem proposta há > 7 dias; revisão do período anterior por abrir a partir do dia 5; metas em risco (AMARELO) no resumo semanal ao dono do objectivo. Funcionalidade **nova** (o legado só tinha contadores e uma função `alertas()` sem consumidor, EST-F-129).
**Início** (`ServicoInicio::pendentes()`, cada contador só com a permissão da vista): `estrategia_nao_cumpre` (VERMELHO do mês), `estrategia_por_medir`, `estrategia_accoes_vencidas` (os 3 do legado) + `estrategia_contributos_por_decidir` e `estrategia_contributos_por_propor` (novos). Calculados por **uma consulta agregada** sobre medições/metas gravadas (EST-D-17), sem recalcular nem carregar o plano.
**Painel de módulo** (`PainelEstrategia`, `ServicoPaineis`, `so_empresa = true`): KPIs (cumpridas/em risco/não cumpridas/por medir/sem meta, objectivos sem indicador, acções vencidas, cobertura média do desdobramento), gráfico por perspectiva (% cumprido) e tabela de acções; atalhos para os ecrãs.

---

## 8. Documento do Plano (arquitectura alvo)

### 8.1 Modelo
Um **documento por plano** (`documentos_plano_estrategico`) com **perfil** `EXECUTIVO` (capítulos do documento A do legado) ou `COMPLETO` (A + secções do B: diagnóstico estruturado, desdobramento, situação, revisões). Capítulos (numeração dinâmica, só entra o que existe):

| Chave | Capítulo | Perfil | Origem |
|---|---|---|---|
| `apresentacao` | Mensagem de abertura | ambos | `textos.apresentacao` |
| `identidade` | Missão, visão e valores | ambos | plano |
| `diagnostico` | Diagnóstico e contexto | ambos | `textos.diagnostico` (texto) **+** (se `incluir_diagnostico`) tabelas SWOT/PESTEL/partes/TOWS e «por tratar» de `factores_diagnostico` |
| `metodologia` | Como foi construído | ambos | `textos.metodologia` |
| `mapa` | Mapa estratégico | ambos | `incluir_mapa` |
| `objectivos` | Objectivos, indicadores e metas | ambos | objectivos ACTIVO/SUSPENSO, indicadores activos, metas da empresa, (opção) última medição, (opção) unidade responsável, (opção) iniciativas |
| `desdobramento` | Desdobramento pelas unidades | COMPLETO | `contributos_unidade` + cobertura |
| `situacao` | Situação em {período} | COMPLETO | painel do período escolhido |
| `revisoes` | Revisões e acções | COMPLETO | revisões + acções por concluir |
| `monitorizacao` | Como se acompanha | ambos | `textos.monitorizacao` |
| `notas` | Disposições finais (+ assinaturas) | ambos | `textos.notas`, `elaborado_por`, `aprovado_por`, `aprovado_em` |
Capa (logótipo, título, subtítulo «Perspectivas, objectivos, indicadores e metas para o período de {ano_inicio} a {ano_fim}», ficha: Período, Estado, Versão, Data, Elaborado por, Aprovado por, Conteúdo), **índice** (com números de página só se a medição por duas passagens — renderizar cada capítulo para contar as suas páginas — for fiável no dompdf; **risco técnico a validar no início de F7b**, com recurso a índice numerado sem páginas, que é o que o legado já fazia), rodapé com identidade da empresa (`ServicoPdfA4POS::identidade()`, extraído para `Support\Pdf\IdentidadePdf` na F0 se o refactor for seguro) e «Página X de Y» (`page_text`), banner «Documento em {estado}…» quando o plano não está APROVADO, aviso de **lacunas** (regras do legado DOC:156-176 + as novas).
**Variáveis de dados:** os blocos de dados vêm do snapshot (`dados`); nos textos livres admitem-se **fichas fixas** `{{plano}}`, `{{empresa}}`, `{{ano_inicio}}`, `{{ano_fim}}`, `{{n_objectivos}}`, `{{n_indicadores}}`, `{{data}}` substituídas por uma lista branca (sem avaliação de código; fora da lista fica literal) — melhoria opcional (F7b, Q8).

### 8.2 Geração e segurança
1. `ServicoDocumentoPlano::dados($plano, $opcoes, $periodo)` reúne tudo **em lote** (≤ 12 consultas) a partir dos serviços da §4 — é a **única** fonte para a pré-visualização e para a emissão.
2. `RenderizadorDocumentoPlano` renderiza com **Blade** (`resources/views/estrategia/documento/*.blade.php`): `{{ }}` escapa tudo; texto livre passa por `Paragrafos::html()` (escape + parágrafos + listas por linhas começadas por `-•*–`), **nunca HTML bruto**; imagens só `data:` da identidade da empresa; sem scripts.
3. Pré-visualização: `GET …/documento/previa` devolve o HTML (já sanitizado pelo mesmo `ServicoPdfA4`) que o frontend mostra num `iframe sandbox` (sem scripts) por `srcdoc`.
4. PDF: `dompdf` com `isRemoteEnabled=false`, `isPhpEnabled=false`, `isJavascriptEnabled=false`, `chroot` restrito (as opções de `ServicoPdfA4POS::opcoes()`); tamanho máximo do HTML 1,5 MB; falha → `ErroNegocio('Não foi possível gerar o PDF deste documento.', 'PDF_FALHOU', 422)`.
5. **Emitir versão** (`est_doc_emitir`, **202** + `ServicoOperacoes`, `ExecucaoIdempotente` com chave `est:doc:{plano}:{sequencia}`): numa transacção com `lockForUpdate` do plano — calcula `dados`, renderiza, gera PDF, grava o ficheiro no disco privado (`estrategia/{empresa}/{plano}/v{seq}.pdf`), calcula `sha256`, insere `versoes_documento_plano` (sequência = máx+1) e regista o evento de auditoria «Emitiu versão». Pode emitir-se em RASCUNHO (o PDF leva o banner «Proposta» e a marca de água) — como no legado.
6. **Descarregar:** `GET …/pdf` com `Content-Disposition: attachment`, `Cache-Control: no-store`, verificação de `empresa_id` e da tarefa; recalcula o `sha256` e recusa (`INTEGRIDADE_FALHOU`) se não coincidir.
7. **Anular:** `POST …/anular` com motivo; a versão fica `ANULADA` (continua descarregável com marca «ANULADA»), nunca se apaga nem se altera.
8. **Anexos** (ficha de aprovação assinada pelo Conselho): reaproveitar `documentos_anexos` (`tipo_entidade='versao_documento_plano'`) com o validador central de uploads (ADR-083) — **fase opcional F7c** (Q8).
9. **Exportação:** PDF (servidor) e **impressão do HTML** (cliente, `motor de impressão`) — o Word **não** se porta (o legado nunca o teve; pergunta Q8).

### 8.3 Edição e aprovação
Ecrã `est_documento` (§9): formulário (título, etiqueta de versão, data, elaborado por, aprovado por, aprovado em, perfil, 9 opções, 5 textos) + pré-visualização; aviso de alterações por gravar ao mudar de plano (EST-D-18). **Aprovação formal:** os campos «Aprovado por/em» mantêm-se como registo do órgão aprovador (texto/data); o estado do plano `APROVADO` é independente (como no legado), mas a **emissão** mostra um aviso se divergirem (plano em RASCUNHO com «Aprovado por» preenchido).

---

## 9. Frontend (React 19 · antd 6 · TanStack Query · react-router)

### 9.1 Estrutura e registo
`frontend/src/modulos/estrategia/`: `ecras.ts` (9 `lazy`, ids do catálogo) · `PlanoObjectivos.tsx` (`est_plano`) · `Indicadores.tsx` (`est_indicadores`, com `FormularioIndicador.tsx`, `ModalMetas.tsx`, `ConfigOrigem*.tsx`) · `Painel.tsx` (`est_painel`) · `MapaEstrategico.tsx` (`est_mapa_estrategico`) · `Iniciativas.tsx` · `Desdobramento.tsx` · `Revisoes.tsx` (lista + revisão aberta) · `Diagnostico.tsx` (4 separadores) · `DocumentoPlano.tsx` · `comum/` (`SeletorPlano`, `SeletorPeriodo`, `PastilhaSemaforo`, `formatarValor.ts`, `periodos.ts`, `escada.ts`, `regras.ts`, `tipos.ts`, hooks `usePlanoActivo`, `usePeriodoUrl`). Registo: `registo.tsx` (+`estrategia`), `layout/menuLegado.ts` (**grupo «Planeamento Estratégico»**, ícone `compass`, itens pela ordem do legado: Painel Estratégico, Diagnóstico, Mapa Estratégico, Documento do Plano, Plano e Objectivos, Indicadores e Metas, Iniciativas, Desdobramento, Revisões; `CASA_MODULO.estrategia = 'grupo:estrategia'`, `NOME_MODULO_LEGADO.estrategia`), `itensMenu.tsx` se o grupo precisar de ícone próprio. O menu continua a mostrar só o que `GET /sistema/menu` devolve.
**Estado partilhado entre ecrãs:** plano e período escolhidos viajam na **query string** (`?plano=&periodo=`) em vez de uma variável de módulo (o `st` do legado, UI:42) — sobrevive a recargas, é partilhável e testável (`useSeparadorUrl` já existe).

### 9.2 Ecrãs — mapeamento do legado
| Ecrã | Componentes antd / comuns | Notas de paridade e de melhoria |
|---|---|---|
| Plano e Objectivos | `CabecalhoPagina`, `SeletorPlano`, 3 `Card` (missão/visão/valores), grelha `auto-fit` por perspectiva, `ModalFormulario` (plano, perspectivas, objectivo) | mesmos botões e rótulos (§4.1 do inventário); OKR a tracejado com `Tag`; aprovar/reabrir/encerrar por `Popconfirm`; mensagens de bloqueio do servidor mostradas tal e qual (`ErroNegocio`) |
| Indicadores e Metas | `TabelaLegado` (colunas do §4.2; filtros por coluna; vistas Linhas/Grade com `GradeCartoes`), `Alert` de avisos, `ModalFormulario` por secções, grelha de metas por ano | o selector de origem muda o sub-formulário (5 componentes); «Testar medição» chama `POST …/testar`; filtros do MODULO por ids da allowlist; metas «em escada» calculadas no cliente com `escada.ts` (mesma função testada contra a fixture do servidor) |
| Painel Estratégico | 5 `CartaoKpi` (`GrelhaKpi`), grupos por objectivo (`Collapse`/cartões), `PastilhaSemaforo`, seta de tendência | **semáforo = cor + ícone + texto** (nunca só cor, WCAG 1.4.1); «Medir este período» abre a operação em segundo plano (`componentes/operacoes`); «Entregar» em `Modal` (valor + origem do valor) |
| Mapa Estratégico | faixas (`div` flex) + `svg` de setas; cartões posicionados em `%` | arrastar por **pointer events** (rato/toque) **e teclado** (foco no cartão; ← → movem 2 %; Enter abre; botão «Ligar a…» com `Select` como alternativa ao duplo clique — como o Gantt do ADR-071); `ResizeObserver` para redesenhar setas; impressão pelo motor (`BotoesExportar`) |
| Iniciativas | `TabelaLegado`, barra `Progress` (cores 100/60/30), `ModalFormulario` | «Ligada a» com `Select` pesquisável alimentado por `GET …/iniciativas/alvos`; execução e custo lidos do servidor (nunca escritos) |
| Desdobramento | blocos por indicador (`Card` + `Table`), `Modal` de pedir contributos, `Modal` de propor/devolver | só mostra os botões a que o utilizador tem direito **e** que o servidor aceitaria (`pode_propor` vem na resposta); «Retirar a unidade» com confirmação |
| Revisões | lista (`Table`) + vista da revisão (`Collapse` por linha) | aviso persistente «N desvios sem causa e acção»; botões «Actualizar fotografia», «Fechar revisão», «Reabrir» (motivo) |
| Diagnóstico | `Tabs` (4), quadrantes responsivos, `Modal` de factor | peso com `Tag` (cor + número); «Transformar em objectivo» com 2 passos num só `Modal` |
| Documento do Plano | formulário (2 colunas ≥ lg; empilha < lg) + `iframe sandbox` com `srcdoc` | botões «Gravar», «Pré-visualizar», «Imprimir» (motor de impressão sobre o HTML), **«Emitir versão»**, lista de versões com «Descarregar PDF» e «Anular» |

### 9.3 Responsividade e acessibilidade
Pontos de quebra xs…xxl de `FRONTEND_RESPONSIVIDADE.md`; valores nunca partem (`ValorMonetario`/`.erp-valor`; `tabular-nums`); `Col span` com `xs`; modais com `larguraModal(px)`; tabelas com `scroll={{x:'max-content'}}` e `erp-quebra` nas colunas de texto livre; **o mapa** em < 768 px passa a **lista por perspectiva** (as faixas horizontais não cabem) com a mesma informação e as ligações em texto («A → B»). Acessibilidade (`docs/ACESSIBILIDADE.md`, `AjudasAcessibilidade`): todos os controlos com rótulo; foco visível; regiões `aria-live` para mensagens de operação; contraste das pastilhas ≥ 4,5:1 (tokens do tema, não as cores fixas do legado); botões só-ícone com `aria-label`/`Tooltip` (os do legado só têm `title`).
**Mensagens de erro seguras:** o cliente mostra a mensagem da API (sempre texto escrito para o utilizador, `MensagemSegura`); nunca `String(e)`; falhas de rede → texto genérico do cliente. **Texto livre**: só texto (React escapa); o documento é HTML do servidor num `iframe sandbox=""`.

### 9.4 Impressão, Excel e exportações (cliente)
Impressão do painel, do mapa (lista), da revisão e do diagnóstico pelo **motor comum** (`construirDocumento`, `tabelaHtml`, paginação com «Página X de Y», cabeçalho de empresa) em vez do `window.open` + `print()` artesanal (UI:1562-1589). Excel pelo `gerarXlsx`/`montarFolha` existente com as mesmas colunas (painel: 10; revisão: 13). O **Documento do Plano** usa o PDF do servidor para arquivo e o motor do navegador só para «Imprimir» rápido.

---

## 10. Pesquisa, ajuda, painel de módulos e relatórios

- **Pesquisa (Ctrl+K):** executar `node ferramentas/pesquisa/gerar_indice_pesquisa.mjs` no fecho de F8 (gera `indicePesquisa.gerado.ts` a partir de `src/modulos/**` + catálogo): entradas de ecrã (9), botões (`Novo plano`, `Aprovar plano`, `Medir este período`, `Pedir contributos`, `Abrir revisão de um período`, `Emitir versão`…), janelas (objectivo, indicador, metas, factor…) e separadores do diagnóstico. Ver `PESQUISA_COBERTURA_2026-11.md`.
- **Ajuda contextual** (`componentes/ajuda`): uma entrada por ecrã com as ajudas do legado (as frases de ajuda dos §4/§5 do inventário).
- **Painel de módulos** (`PainelEstrategia`) e **Início** (5 contadores): §7.
- **Relatórios existentes que passam a consumir:** Relatório e Contas (capítulo «Execução do Plano Estratégico», só leitura, com fotografia na conclusão do relatório), Início, Portal.
- **Exportações novas:** nenhuma além das do legado (Excel painel/revisão, impressões) + PDF do Documento do Plano.
- **Gráficos:** o legado não tinha. Proposta mínima no Painel (`componentes/graficos`): barras empilhadas por perspectiva (verde/amarelo/vermelho) e, no detalhe de um indicador, a **série meta × valor** (EST-F-056 passa a ter UI — pequena melhoria).

---

## 11. Testes

### 11.1 PHPUnit (via Docker `erp_app_e2e`; nunca duas suites em paralelo)
Estrutura `tests/Feature/Estrategia/`: `PlanosEstrategicosTest`, `ObjectivosEstrategicosTest`, `IndicadoresEstrategicosTest`, `MetasIndicadoresTest`, `MedicaoIndicadoresTest`, `PainelEstrategicoTest`, `MapaEstrategicoTest`, `IniciativasEstrategicasTest`, `DesdobramentoTest`, `RevisoesEstrategicasTest`, `DiagnosticoEstrategicoTest`, `DocumentoPlanoTest`, `PontesEstrategiaTest`, `PermissoesEstrategiaTest`, `IsolamentoEstrategiaTest` e `tests/Unit/Estrategia/`: `PeriodosTest`, `CalculadoraDesempenhoTest`, `CatalogoFontesModuloTest`. **Cada teste de cálculo usa números fixados à mão** (abaixo), nunca recalculados no próprio teste.

**Fixtures de números calculados à mão (a partir das fórmulas EST-C):**

| Fórmula | Entrada | Resultado esperado |
|---|---|---|
| EST-C-01/04 (SOBE, tol 100/90) | meta 100 | valor 120 → 120 % VERDE · 100 → 100 % VERDE · 95 → 95 % AMARELO · 89,99 → 89,99 % VERMELHO · meta 0, valor 5 → 100 % VERDE · meta 0, valor −1 → 0 % VERMELHO |
| EST-D-26 (fronteira decimal) | SOBE meta 9, valor 8,1 | **90,000000 % → AMARELO** (o legado dava 89,99999999999999 → VERMELHO) |
| EST-C-02 (DESCE) | meta 10 | valor 12,5 → 80 % VERMELHO · 10 → 100 % VERDE · 0 → 100 % VERDE · meta 0, valor 3 → 0 % VERMELHO |
| EST-C-03 (ALVO) | meta 50 | valor 45 → 90 % AMARELO · 60 → 80 % VERMELHO · 100 → 0 % VERMELHO · meta 0, valor 0 → 100 % VERDE |
| EST-C-05 (cor do objectivo) | indicadores VERDE, AMARELO, SEM_MEDIDA | AMARELO; com um VERMELHO → VERMELHO; sem indicadores → SEM_META |
| EST-C-06 | período `2027-08` | ANUAL `2027` · SEMESTRAL `2027-S2` · TRIMESTRAL `2027-T3` · MENSAL `2027-08` |
| EST-C-07 | anterior de `2027-01` mensal / `2027-T1` / `2027-S1` / `2027` | `2026-12` / `2026-T4` / `2026-S2` / `2026` |
| EST-C-08 | `2028-02` / `2027-T4` / `2027-S1` | 2028-02-01 a **2028-02-29** (bissexto) / 2027-10-01 a 2027-12-31 / 2027-01-01 a 2027-06-30 |
| EST-C-09 (CONTA, prefixo 62, CUSTO) | Diário mar/2027: 62.1 D 300 000; 62.2 D 100 000; 62.1 C 20 000 | **380 000,00**; PROVEITO sobre 71 com C 1 000 e D 100 → **900,00**; MOVIMENTO = D−C; SALDO = saldo acumulado até 31/03 |
| EST-C-10 (ORCAMENTO) | rubrica R, orçado jan–mar 100/100/100, realizado 90/110/120 | 2027-T1: ORCADO 300; REALIZADO 320; DESVIO **20**; EXECUCAO_PCT **106,67**; 2027-02: 100 / 110 / 10 / 110,00; ano sem orçamento aprovado → «Não há orçamento aprovado para 2027.» |
| EST-C-11 (MODULO vendas, SOMA total_liquido, filtro tipo = FT) | mar/2027: FT 400 000 + FT 350 000, NC 50 000 (filtrada), FT de 30/04 | **750 000,00**; CONTAGEM = 2; MEDIA = 375 000,00; período vazio: SOMA 0,00 · MEDIA nulo (não grava) |
| EST-C-13 (cobertura SOMA, SOBE) | meta empresa 1 000; aceites A 400, B 350; C proposto 200 | assumido **750**, difere **−250**, chega **não**; com C aceite 200 → 950 / −50 / não; com D aceite 50 → **1 000 / 0 / sim** |
| EST-C-13 (MEDIA) | meta 80; aceites 70 e 90 | assumido **80**, chega **sim**; só 70 aceite → 70 / −10 / não |
| EST-C-13 (DESCE, SOMA) | meta 10; aceites 4 e 5 | 9 / −1 / **sim** (≤) |
| EST-D-10 (ALVO) | meta 50, tolerância amarela 90 → janela ±5; aceites (média) 48 | **sim**; 44 → não |
| EST-C-16 (ENTREGAVEL) | meta 200; validadas 50+30+20; pendente 60 | **50 %**; sem meta e 1 validada → 100 %; sem execuções → 0 % |
| EST-C-17 (RUBRICA, ano corrente) | orçado 12×100 = 1 200; consumo 540 | **45 %**, custo 540,00, orçado 1 200,00 |
| EST-C-19/20 (prioridade e Mendelow) | SWOT FORÇA impacto 4 → **20**; AMEAÇA 4×3 → **12** (por tratar se sem objectivo); OPORTUNIDADE 3×3 → **9** (classe média; não por tratar); PESTEL 5×4 → **20**; PARTE 4×5 → **20** «Gerir de perto»; 4×3 → 12 «Manter satisfeita»; 2×4 → 8 «Manter informada»; 3×3 → 9 «Acompanhar» | |
| EST-C-22 (escada) | v₀ 100, 4 períodos | passo +10: 100 / 110 / 120 / 130; passo 5 %: 100 / 105 / **110,25** / **115,76** (115,7625) |
| EST-D-12 (arredondamento) | desvio −0,125 | armazenado −0,1250; apresentado **−0,13** (o legado: −0,12) |
| Painel integrado (feature) | plano 2027-2029 APROVADO; I1 «Vendas» (MODULO, SOBE, mensal, meta mar/27 = 1 000 000, medido 750 000); I2 «Custos de pessoal» (CONTA 62 CUSTO, DESCE, mensal, meta 400 000, medido 380 000); I3 «Conclusão» (MANUAL, SOBE, trimestral, meta 90, entregue 85 em `2027-T1`); I4 sem meta; I5 com meta e sem medição | período `2027-03`: I1 **75 % VERMELHO**; I2 **105,26 % VERDE** (400 000 / 380 000); I3 (período equivalente `2027-T1`) **94,44 % AMARELO**; I4 SEM_META; I5 SEM_MEDIDA; resumo `{verde:1, amarelo:1, vermelho:1, semMeta:1, porMedir:1}`; cor do objectivo VERMELHO |
| Resumo anual (Relatório e Contas) | ano 2027 com I2 medido em jan–jun e meta mensal | usa **o último período com medição** (`2027-06`), não o dezembro |

Casos de integridade/segurança obrigatórios: UNIQUE de meta/medição/contributo (segunda inserção concorrente → upsert/409); CHECKs (tipo×FK da iniciativa, tolerâncias, período×horizonte, ciclo ≤ 15 anos); ligação que fecha ciclo → `LIGACAO_CICLO`; apagar perspectiva/objectivo/indicador em uso → mensagens do inventário; **dois utilizadores a aceitar o mesmo contributo** (lock); `AUTO_DECISAO`; propor fora da unidade → 403; plano ENCERRADO só leitura; aprovar sem origem/dono/meta → `PLANO_SEM_ORIGEM`…; empresa B não vê nem escreve nada da empresa A (para cada tabela); utilizador sem permissão → 403 em cada rota (`OwaspControloAcessoTest`); valores financeiros ocultos sem `est_ver_financeiros`; PDF: HTML com `<script>`/`<iframe>`/`onerror` nos textos livres sai escapado; `sha256` verificado no download; versão ANULADA continua imutável.

### 11.2 Vitest (frontend)
`periodos.test.ts` (as mesmas linhas das fixtures EST-C-06/07/08, com **fixtures partilhadas** em JSON entre servidor e cliente para não divergirem), `escada.test.ts`, `formatarValor.test.ts` (7 formatos), `regras.test.ts` (cores do semáforo, rótulos), `Ecras.test.tsx` (os 9 ecrãs renderizam com dados simulados, estado vazio «Ainda não há nenhum plano…», botões conforme permissões), `MapaEstrategico.test.tsx` (posição por `%`, teclado, lista < 768 px), `FormularioIndicador.test.tsx` (sub-formulário por origem, herança de polaridade/formato do KPI), `Desdobramento.test.tsx` (botões por estado/permissão), `DocumentoPlano.test.tsx` (iframe sandbox, aviso de alterações por gravar), guarda `navegacaoPorUrl.test.tsx` (gerada do catálogo → apanha o módulo novo), `menuLegado.test.ts`. Playwright: **1 e2e** (`estrategia.e2e.ts`): criar plano → objectivo → indicador MANUAL → meta → entregar valor → painel mostra o semáforo → emitir versão e descarregar o PDF.
No fecho de cada fase: `docker exec erp_app_e2e php artisan test` (suite da fase), vitest, `npm run build` (o frontend de teste serve `frontend/dist`).

---

## 12. Migração de dados do legado (ETL) — opcional e condicionada

- **Pré-condição (Q10):** confirmar se o backup/produção legado tem as 11 tabelas `est_*` (Dexie ≥ 1285). O backup de migração conhecido (ADR-071) é Dexie 1277 → **provavelmente não há dados**; o módulo nasce vazio e o ETL fica para quando houver backup actual. **Não bloqueia F0–F8.**
- **Se houver:** `ServicoMigracaoEstrategia` no padrão do ETL (ADR-023: três etapas, transacção única, validação antes do COMMIT, **simulação por omissão**) e idempotente: coluna `legado_id bigint NULL` + UNIQUE parcial `(empresa_id, legado_id) WHERE legado_id IS NOT NULL` em cada tabela migrada (migração aditiva própria, só nessa fase); mapas `org_units`→`unidades_organicas`, `orc_rubrics`→`rubricas_orcamentais`, `projects`→`projetos`, entregáveis→`entregaveis_tarefa_unidade`, `employees`→`colaboradores` (por nome exacto e único; senão texto externo); regras do §3.4; **ordem:** planos → perspectivas → objectivos (pais por segunda passagem) → indicadores → metas/contributos(+movimentos) → medições → ligações → iniciativas → revisões(+linhas) → factores(+pivôs) → documento. **Relatório** de tudo o que não migra (ícones desconhecidos, ids órfãos, valores nulos, ciclos, responsáveis por casar) e contagens origem × destino. Perfis: as chaves `est_*` entram nos perfis convertidos **editando à mão** (memória: nunca regenerar o catálogo).
- **Validação:** compara, para cada plano migrado, `painel(periodo)` do novo com o `Estrategia.painel` do legado sobre o mesmo backup (fixtures), tolerando as diferenças esperadas (EST-D-12/26).

---

## 13. Fases (F0…F9)

Legenda: **Dep.** = depende de; **∥** = pode correr em paralelo com. Esforço S/M/L (§0). Cada fase fecha com commit próprio (`git add` só dos ficheiros da fase), suite backend + vitest + `npm run build`.

| Fase | Conteúdo (F-xxx) | Entregas | Dep. / ∥ | Esforço | Critério de aceitação |
|---|---|---|---|---|---|
| **F0 Alicerces** | catálogo (`catalogo.json`: módulo, 9 ecrãs, 21 tarefas, perfis-modelo), `config/erp.php` (módulo no catálogo de planos + bloco `estrategia`), migração de licença, 5 migrações das 17 tabelas, 17 modelos (`Base` + final), `Periodos`, `CalculadoraDesempenho` (puros), rotas vazias com `exigir`, `registo.tsx`/`menuLegado.ts`/`ecras.ts` com «em construção», `ServicoNotificacoes`/`ServicoPaineis` (entradas), extracção opcional de `IdentidadePdf` | migrações, modelos, serviços puros, esqueleto | — | **M** | migrações idempotentes na cópia local; `IsolamentoRlsContratoTest` verde com as 17 tabelas; `PeriodosTest` e `CalculadoraDesempenhoTest` verdes com **todas** as linhas das fixtures; menu mostra o grupo só a quem tem permissão; `OwaspControloAcessoTest` verde |
| **F1 Plano, perspectivas, objectivos** | F-001…024 | `ServicoPlanosEstrategicos`, `ServicoObjectivosEstrategicos`, controllers/requests/resources, ecrã `est_plano` (+modais) | F0 | **M** | máquina de estados completa; apagar com bloqueios; OKR e pai sem ciclos; ecrã com os rótulos do inventário; testes de bloqueio e de transições inválidas |
| **F2a Indicadores e metas** | F-025…043 | `ServicoIndicadoresEstrategicos`, `ServicoMetasIndicadores`, `CatalogoFontesModulo`, catálogo de KPIs (cache), ecrã `est_indicadores` | F1 | **M–L** | as 5 origens validam como `validarOrigem`; allowlist sem nomes do cliente; metas por período/frequência; «Testar medição»; escada igual no cliente e no servidor |
| **F2b Medição e painel** | F-044…059, 128 (parte), 138 | `Fontes\*`, `MotorMedicao`, `ServicoPainelEstrategico`, operação em segundo plano, ecrã `est_painel`, Excel/impressão | F2a; Orçamento, Contabilidade, Gestão (leitura) | **L** | todas as fixtures EST-C-09…12 e o «Painel integrado»; guarda de consultas por endpoint; valores financeiros ocultos sem permissão; «Medir» não bloqueia (202) |
| **F3 Mapa e iniciativas** | F-060…076 | `ServicoLigacoesObjectivos`, `ServicoIniciativas`+`Execucao\*`, ecrãs `est_mapa_estrategico`, `est_iniciativas` | mapa: F2b (semáforo); iniciativas: **só F1** → **∥ F2a/F2b** | **M** | ciclos recusados sob concorrência; execução por tipo com as fixtures EST-C-15…18; mapa utilizável por teclado e em < 768 px |
| **F4 Desdobramento** | F-077…088 | `ServicoDesdobramento`, `movimentos_contributo`, delegação `ESTRATEGIA`, notificações de contributos, ecrã `est_cascata` | F2b; Estrutura Orgânica; ∥ F6 | **M–L** | fixtures EST-C-13/D-10; `AUTO_DECISAO`; propor fora da unidade → 403; delegação sem cadeia; histórico imutável por privilégio |
| **F5 Revisões e acções** | F-089…102, 140 | `ServicoRevisoesEstrategicas`, alertas de acções, ecrã `est_revisoes`, Excel/impressão | F2b (+F4 para linhas por unidade) | **M** | não fecha com desvios por explicar; reabrir com motivo; «Actualizar fotografia»; acções vencidas no Início |
| **F6 Diagnóstico** | F-103…115 | `ServicoDiagnostico`, ecrã `est_diagnostico`, impressão | F1; **∥ F2–F5** | **M** | prioridades/Mendelow das fixtures; «por tratar»; gerar objectivo liga factor↔objectivo; ligar a objectivo existente com UI (EST-F-111) |
| **F7a Documento — base** | F-116…124 | `documentos_plano_estrategico`, `ServicoDocumentoPlano`, Blade (perfil Executivo), pré-visualização, ecrã `est_documento`, avisos de lacunas | F1 (rico com F2) ; **∥** | **M** | HTML escapado (teste com `<script>`); lacunas iguais às do legado; pré-visualização em `iframe sandbox` |
| **F7b Documento — completo e versões** | F-125…127 + versões | perfil Completo, índice com páginas, PDF `dompdf`, `versoes_documento_plano`, emissão idempotente, hash, anulação, `est_doc_emitir`; fichas `{{…}}` (opcional) | F7a, F2b, F4, F5, F6 | **L** | PDF com capa, índice, «Página X de Y», assinaturas; `sha256` verificado; versão imutável; emissão repetida não duplica |
| **F7c Anexos** (opcional) | — | anexos à versão (`documentos_anexos`) | F7b | **S** | validador central de uploads |
| **F8 Integrações transversais** | F-128…134 | `Pontes\*` (Avaliação `EST-*`, Portal, Relatório e Contas), `ServicoInicio` (5 contadores), `PainelEstrategia`, `narrativas.json`, `AlertasEstrategiaCommand`, `NotificadorEstrategia`, pesquisa regenerada, lista branca do portal, `rotas.json`/dicionário regenerados | F2b, F4, F5 | **M** | avaliação mostra os objectivos `EST-…` só com metas ACEITES; portal só com `rh_portal`; Relatório e Contas sem escrita; contadores por consulta agregada (guarda de consultas); alertas idempotentes |
| **F9 Endurecimento, ETL e extras** | gráfico série/perspectiva, e2e, ETL (se houver dados), medição agendada, atalho para o Método (Q9), nova versão do plano (Q5) | testes E2E, `ServicoMigracaoEstrategia`, relatórios de paridade | tudo | **S–M** | e2e verde; ETL em simulação com relatório; `docs` e ADR-086 escritos |

**Caminho crítico:** F0 → F1 → F2a → F2b → (F4, F5) → F7b → F8. **Paralelo viável (2 agentes):** agente A = F0→F1→F2a→F2b→F4→F5; agente B = (após F0) F6 ∥ F3-iniciativas ∥ F7a, depois F3-mapa, F7b. **Colisões** a coordenar: `catalogo.json`, `registo.tsx`, `menuLegado.ts`, `routes/api.php`, `config/erp.php` e `ServicoInicio` (um só agente toca de cada vez; fazer F0 inteiro primeiro e deixar as entradas já criadas).
**Esforço total:** F0 M + F1 M + F2a M–L + F2b L + F3 M + F4 M–L + F5 M + F6 M + F7a M + F7b L + F8 M + F9 S–M ≈ **6–7 semanas** de um agente a tempo inteiro (≈ 4 com dois agentes).

---

## 14. Riscos

| Risco | Prob. | Impacto | Mitigação |
|---|---|---|---|
| Valores financeiros do plano visíveis a quem não vê a contabilidade | M | A | Q3 + `est_ver_financeiros`; semáforo sem valor; teste |
| `CatalogoFontesModulo` com nomes de colunas errados (esquema novo ≠ legado) | A | M | mapeamento da §3.5 verificado contra `esquema.json` na F2a; teste que executa **cada** fonte numa BD de teste; fontes sem equivalente omitidas |
| Custo de medir o plano (Diário + KPI de gestão de 9 módulos) | M | M | contexto partilhado por corrida (cache por módulo+período), operação em 2.º plano, índices; catálogo de KPIs em `Cache::flexible` |
| Divergência cliente × servidor nos períodos e na escada | M | B | fixtures JSON partilhadas e testadas nos dois lados |
| Dois documentos do legado fundidos (A+B) desagradam | B | M | Q8; perfis Executivo/Completo mantêm ambos os conteúdos |
| Refactor da identidade/dompdf de `ServicoPdfA4POS` partir o POS | B | A | só extrair se a suite do POS ficar verde sem alterações; senão duplicar a função `identidade()` (pequena) |
| Prefixo `est_` confundido com Estrutura Orgânica | M | B | documentado; módulos e menus separados; teste de unicidade de ids de ecrã/tarefa no catálogo |
| Plano de licença: módulo invisível aos clientes existentes | A (já aconteceu) | A | migração `…_estrategia_plano_licenca` + teste que um cliente com plano com `orcamento` vê o módulo |
| Mapa arrastável não utilizável em toque/teclado | M | M | pointer events + alternativa por teclado e lista em < 768 px; teste |
| Ligações do legado a tabelas que não existem (`formacao`, `projetos.progress`) | A | B | omitir e documentar; não inventar |
| Regras novas (aprovar exige metas, congelar estrutura) bloqueiam o fluxo habitual do utilizador | M | M | Q5; mensagens claras; excepção por `est_plano_aprovar` com motivo |
| Medições com `Auditavel` geram muitas linhas de auditoria | B | B | 1 evento por corrida; medições em lote sem eventos de modelo + evento agregado |

---

## 15. Perguntas ao utilizador (decisões, com recomendação)

| # | Pergunta | Recomendação |
|---|---|---|
| **Q1** | O Planeamento Estratégico passa a **módulo próprio** `estrategia` (menu, plano de licença, painel) mantendo os **ids de ecrã/tarefa do legado** (`est_plano`, `est_painel`, …)? (no legado vivia dentro de «Geral») | **Sim.** Módulo próprio com os ids do legado (compatíveis com perfis restaurados) |
| **Q2** | Em que planos de licença entra `estrategia`? | Nos planos que já têm **Orçamento** (a estratégia lê-o) — e disponível para activar à parte; não nos planos básicos |
| **Q3** | Quem vê os **valores** de indicadores financeiros (contas, orçamento, KPIs de finanças e tesouraria)? O legado diz «quem vê o plano vê esses valores». | Tarefa sensível `est_ver_financeiros` (ou acesso ao módulo de origem); sem ela vê-se o semáforo mas não o valor |
| **Q4** | **Um plano APROVADO por empresa** e ciclo máximo de 15 anos? (o legado permite vários e sem limite) | **Sim**, com UNIQUE parcial |
| **Q5** | **Plano aprovado fica congelado?** (estrutura e metas só alteráveis com `est_plano_aprovar` + motivo, ou por «nova versão do plano»); e **segregação** `est_plano_editar × est_plano_aprovar` e `est_casc_propor × est_casc_decidir` | **Sim** ao congelamento (excepção com motivo auditado); nova versão do plano em F9 se for pedida; segregação como **aviso** no catálogo |
| **Q6** | Valores de indicador em **4 casas decimais** (`numeric(20,4)`) e cálculo decimal (corrige a fronteira 90 % → AMARELO)? | **Sim** |
| **Q7** | Revisões: fotografar ao **abrir** (legado) com «Actualizar fotografia» enquanto aberta e **selar ao fechar**? | **Sim** (resolve a contradição do comentário do legado sem recalcular o Diário) |
| **Q8** | **Documento do Plano:** (a) fundir os dois documentos do legado num só com perfis Executivo/Completo; (b) **versões emitidas imutáveis** com PDF no servidor; (c) Word fora de âmbito; (d) fichas `{{plano}}`/`{{ano_inicio}}`… nos textos; (e) anexos (ficha de aprovação assinada) | (a) sim; (b) sim; (c) sim, sem Word; (d) sim, pequeno; (e) fase opcional |
| **Q9** | **Acção correctiva** de uma revisão: manter leve (como no legado) ou transformá-la em **assunto 5W1H do Método de Trabalho**? | Manter **leve** e acrescentar depois um atalho «Abrir assunto no Método de Trabalho» (F9) |
| **Q10** | **Dados reais:** há um backup legado com as tabelas `est_*` (Dexie ≥ 1285) para migrar? | Pedir o backup actual; **só então** fazer o ETL (F9). Sem dados, o módulo arranca vazio |
| **Q11** | Aceita as **notificações e alertas novos** (contributos, acções a vencer, indicadores manuais por entregar, indicador a vermelho) com os limiares propostos (3/5/7 dias; dia 5 do mês), e a **medição automática agendada** no fim do mês (só indicadores automáticos)? | Notificações **sim**; medição agendada **opcional**, desligada por omissão |
| **Q12** | **Plano de grupo (holding):** manter a ideia de `grupo_id`? | **Não** por agora (nunca foi usado; a consolidação ADR-057 já trata o grupo de empresas) |

---

## 16. Contagens finais e fronteiras

- **Ecrãs:** 9 (+2 vistas: revisão aberta e «Imprimir o plano», que passa a ser o perfil Completo do Documento). **Modais próprios:** 9. **Diálogos de uma pergunta:** 27.
- **Funcionalidades atómicas:** 140 (EST-F-001…140). **Fórmulas/regras de cálculo:** 25 (EST-C-01…25). **Defeitos/discrepâncias do legado:** 26 (EST-D-01…26). **Interligações:** 14 (I-01…I-14).
- **Tabelas do legado:** 11 (`est_*`). **Tabelas novas propostas:** 17. **Endpoints propostos:** ≈ 73. **Permissões:** 9 ecrãs, 21 tarefas (5 sensíveis; 19 do legado + 2 novas).
- **Fronteiras:** Orçamento = valores por rubrica (a estratégia lê; não duplica previsões/cenários/excesso) · Projectos = execução física e custo (a estratégia lê o progresso/custo/orçado; não toca nas metas de tarefa) · Estrutura Orgânica = quem (a estratégia define o quê e quanto; liga a unidades/responsáveis/delegações) · RH = nota do colaborador fica na Avaliação (a estratégia fornece objectivos virtuais `EST-…`) · Contabilidade/Gestão = fontes de valor (leitura) e Relatório e Contas = consumidor · CRM/Vendas = fontes (leitura) · Método de Trabalho = sem ligação obrigatória (atalho opcional) · Documentos = o PDF e as versões são do módulo; o Manual da Organização/Procedimentos ficam fora.
