# Paridade do esquema da produção com o código (2026-11)

Só estrutura (catálogo `pg_catalog`); nenhuma linha de negócio lida, nada escrito na produção.

## Método
- A = produção (RDS `meuerp-db`, BD `erp_consulvolt`, 128 migrações registadas), lida por SSM com a ligação `pgsql_owner`.
- B = referência do código: BD temporária vazia no PostgreSQL local (PG 18) onde se correu `php artisan migrate --database=pgsql_owner` (115 migrações do repositório; BD apagada no fim). A BD `erp_consulvolt_e2e` não serve de referência: está sem tabela `migracoes`.
- C = BD de desenvolvimento local `erp_consulvolt` (115 migrações).
- Factos extraídos e comparados: tabelas/partições/views, colunas (tipo, NOT NULL, default, identity/generated), PK/UNIQUE/FK/CHECK, índices (ignorando o nome), sequências, funções (assinatura, resultado, SECURITY DEFINER, MD5 do corpo), triggers, políticas RLS e flags `relrowsecurity/relforcerowsecurity`, GRANTs de tabela/sequência/função a `erp_app` e `erp_utilizador`, ACL de colunas, ACL por omissão e do schema, donos, extensões, enums, roles.
- Totais B: 255 tabelas, 3990 colunas, 1095 restrições, 1026 índices, 238 sequências, 216 políticas, 13 funções próprias. Totais A: 258 / 4020 / 1100 / 1037 / 239 / 219 / 15 (+7 triggers que B não tem).

## Veredicto
**Produção compatível.** Não falta na produção nenhuma tabela, coluna, índice, restrição, sequência, política, trigger ou flag RLS que o código espera; nenhuma coluna diverge em tipo, nulidade ou default; todas as tabelas com `empresa_id` e RLS no código têm RLS (e FORCE) igual na produção. Única lacuna: a ACL de 4 funções SECURITY DEFINER (endurecimento, sem falha funcional), corrigida pela migração nova.

## Contagens por classe (A x B)
| Classe | Nº | Notas |
|---|---|---|
| FALTA_NA_PRODUCAO (objectos) | 0 | |
| TIPO_DIVERGE | 0 | 87 CHECK e 3 índices parciais de `vendas` diferem só no texto de `pg_get_constraintdef/indexdef` (PG do RDS vs PG 18: `ARRAY[(x)::text]` vs `(ARRAY[x])::text[]`); normalizados são idênticos |
| INDICE_RESTRICAO_DIVERGE | 0 em falta | A tem 11 índices e 5 restrições a mais (ver A_MAIS) |
| RLS_GRANT_DIVERGE | 4 (ACL de funções) | grants de tabelas/sequências/colunas, ACL por omissão e do schema, donos: iguais |
| FUNCAO_TRIGGER_DIVERGE | 0 em falta | corpos MD5 idênticos nas 13 funções comuns |
| A_MAIS_NA_PRODUCAO | 3 tabelas, 30 colunas, 11 índices, 5 restrições, 1 sequência, 2 funções, 7 triggers, 3 políticas, 1 flag RLS, 8 grants, 2 extensões | ver tabela abaixo |

## Diferença que exige acção (RLS_GRANT / FUNÇÃO)
| Objecto | Código | Produção | Impacto |
|---|---|---|---|
| `app_empresa_por_token_bi(text)`, `app_empresa_por_webhook_token(text)`, `plataforma_empresas()`, `plataforma_empresas_por_tenant()` (SECURITY DEFINER, `search_path=public`) | EXECUTE só para `erp_app` e dono | ACL nula = EXECUTE para PUBLIC | Nada falha (erp_app continua a executar). Risco de segurança: função que lê através da RLS aberta a qualquer role da base; a única role de aplicação é `erp_app`, logo a exposição real é baixa. Corrigido pela migração `2026_11_22_090000_paridade_esquema_producao.php`. As outras 9 funções têm ACL idêntica. |

## A_MAIS_NA_PRODUCAO (herança da linha paralela): mantidos, não usados
Verificado por pesquisa em `app/`, `routes/`, `config/`, `database/`, `tests/`.
| Objecto na produção | Usado pelo código? | Efeito na escrita do código novo |
|---|---|---|
| `tenants.licenca_inicio/licenca_fim` (date NULL), `licenca_origem` (varchar(20) NOT NULL default 'SEM_LIMITE' + CHECK `ck_tenants_licenca_origem`) | Não: o código usa `empresas.licenca_*`. Mantidos, não usados | INSERT em `tenants` não falha (nulos/default) |
| `utilizadores.alterar_palavra_passe` (bool NOT NULL default false) | Não (zero referências). Mantido, não usado | o default cobre o INSERT |
| `perfis_utilizador.tenant_id` (bigint NULL, sem default, FK `perfis_utilizador_tenant_id_fkey`, índice) | Não escreve nem lê; a migração 2026_11_21 fundiu os perfis e pô-la a NULL. Mantido, não usado | INSERT sem a coluna passa |
| `perfis_utilizador_pre_fusao`, `utilizadores_perfil_pre_fusao` (+ grants) | Só a migração 2026_11_21 (cópia de segurança para reposição). Mantidas, não usadas | n/a |
| `recuperacoes_senha` (+ sequência, PK, UNIQUE token_hash, FK, índice, grants) | Não (0 referências): tabela de recuperação antiga. Mantida, não usada | n/a |
| `logs_auditoria.tenant_id` (na tabela mãe, 6 partições e `_padrao`), índices `*_tenant_id_*`, função `erp_logs_auditoria_tenant()`, trigger `logs_auditoria_tenant` (BEFORE INSERT, 7), RLS ligada com políticas `auditoria_inserir` (WITH CHECK true), `auditoria_ler_tenant` e `auditoria_ler_plataforma` | Usados implicitamente: o trigger preenche `tenant_id` nos INSERT do código; as políticas aplicam-se às leituras como `erp_app`. Mantidos. É a única tabela com `empresa_id` sem RLS no código | `erp_app` só tem INSERT,SELECT (igual ao código). Leituras fora de contexto de tenant (jobs) não vêem linhas; a plataforma (tenant 1) vê tudo via `auditoria_ler_plataforma` |
| `plataforma_empresas_licencas()` (SECURITY DEFINER, ACL nula) | Não (0 referências). Mantida, não usada | sem efeito; fica aberta a PUBLIC (não incluída na migração por não pertencer ao código; revogar só se o coordenador quiser) |
| Extensões `pg_trgm`, `unaccent` (e as suas funções/operadores) | Não (nenhum `unaccent`/`similarity` no código; nenhum índice trigram em A) | n/a |

## C x B (BD local de desenvolvimento contra as migrações)
Tabelas, funções, triggers, políticas, grants e sequências: idênticos. Diferenças (o código não depende delas):
- 13 colunas com tipo/default de uma versão anterior da migração, depois editada: `anexos_suporte.criado_em`, `etiquetas_catalogo_projeto.criado_em/atualizado_em`, `notas_internas_suporte.criado_em`, `projeto_etiqueta.criado_em`, `projeto_sinalizacao.levantada_em`, `respostas_rapidas_suporte.criado_em/atualizado_em`, `sinalizacoes_catalogo_projeto.criado_em/atualizado_em` (dev: `timestamptz` sem precisão e default `now()`; migração: `timestamp(0)` e `CURRENT_TIMESTAMP`), `tickets_suporte.prazo_sla/primeira_resposta_em` (dev sem precisão (0)), `modelos_cartas_pagamento.predefinido` (dev anulável; migração NOT NULL default false). A produção coincide com a migração, não com a BD local.
- Nomes de PK/índices: dev `projeto_etiqueta_pkey`/`projeto_sinalizacao_pkey`, migração `pk_*`; dev `uq_cartas_pagamento_bancario_empresa_id_ano_sequencia` e `uq_modelos_cartas_pagamento_empresa_id_tipo`, migração `ux_cartas_pagamento_bancario_numeracao` e `ux_modelos_cartas_pagamento_predefinido`. Indica migrações editadas depois de corridas em dev; a produção é igual à migração.
- Extensões `pg_trgm` e `unaccent` presentes na dev e na produção, ausentes das migrações (objectos fora das migrações; o código não os usa).

## Correcção
Migração aditiva e idempotente `backend/database/migrations/2026_11_22_090000_paridade_esquema_producao.php`: para cada uma das 4 funções, se existe e PUBLIC tem EXECUTE (ACL nula ou explícita), faz `GRANT EXECUTE ... TO erp_app` e depois `REVOKE ALL ... FROM PUBLIC`. No-op em dev/teste. Não toca em dados, tabelas nem RLS. `down()` vazio. Teste `backend/tests/Feature/ParidadeEsquemaProducaoTest.php` (3 casos, 30 asserções: no-op, simulação da ACL nula + efeito + segunda corrida idempotente, função inexistente). Não aplicada em produção.

## Por validar
- Ensaiar a migração numa cópia restaurada da BD de produção (coordenador) e confirmar depois `has_function_privilege('erp_app', ..., 'EXECUTE')` e login, webhooks e painel da plataforma.
- Dados e privilégios com conteúdo não foram comparados por desenho; só catálogo.
- Efeito real da RLS de `logs_auditoria` com `erp_app` em jobs agendados sem tenant (leitura vazia): ensaio funcional.
- Versão do PostgreSQL do RDS não comparada com a local (PG 18): só diferenças de formatação.
