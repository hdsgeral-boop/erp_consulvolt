# Desempenho do backend e da base de dados — medições (2026-11-05)

ADR-085 · Parte III do `Implementar.txt` (secções 8 e 9). Regra seguida: **medir antes de mexer, mexer só onde a medição o justifica, medir depois**.
Ambiente: PostgreSQL 18 (docker `erp_postgres`), PHP 8.5, Laravel 13, Redis; BD de testes (`*_testes`, schema completo, sem dados) e uma
BD sintética descartável (`erp_agente_seg_perf`: o schema da BD de desenvolvimento + 900 000 linhas de auditoria e 600 000 notificações em 40 empresas).
A BD de desenvolvimento (`erp_consulvolt`) não tem volume (todas as tabelas com 0 linhas, sem `pg_stat_statements`): não permite medir índices — daí a BD sintética.

## 1. Guarda de desempenho gerada de `route:list` (`tests/Feature/Desempenho/ConsultasPorEndpointTest`)

Com `Model::shouldBeStrict()` (já activo fora de produção) o lazy loading de relações falha nos testes; o resto (consultas em ciclo com `DB::`/`find()`)
é apanhado por este teste, que corre **todas as rotas GET com empresa activa e sem parâmetro de recurso** (323 rotas medidas) com um super-administrador e conta as consultas
por pedido (`enableQueryLog`):

| Medida | Valor |
|---|---|
| Rotas GET medidas | 323 |
| Consultas por pedido: mediana / p90 | **3 / 6** |
| Total de consultas (323 rotas) | 1 985 |
| Rotas acima do tecto geral (60) | 3, todas com tecto próprio e fixo (ver abaixo) |
| Mesma consulta repetida > 6× no mesmo pedido | **0** (antes: 1 — `gestao/cubo/conjuntos`, 18×) |

Pior caso (custo FIXO: não cresce com as linhas): `sistema/copias/exportar` 425 (exportação completa da empresa), `gestao/relatorios/todos` 115 e `resumo` 114
(agregam todos os módulos, dois períodos), `sistema/validacoes` 55, `sistema/notificacoes` 38 (polling de 30-35 s por utilizador: candidato a uma consulta de
resumo dedicada; **não** se pôs cache — os alertas dependem das permissões e do estado «lida», e a invalidação por utilizador não compensa face ao intervalo de polling),
`gestao/inicio` 36. Os tectos próprios estão em `TECTOS_PROPRIOS` no teste: subir um número é uma regressão a justificar.

Além do número absoluto, o teste detecta **N+1** de forma independente dos dados: mede as listagens principais com poucos e com muitos registos e exige o mesmo número de consultas.

## 2. Correcções feitas (com medição)

| Problema | Evidência | Correcção | Antes → depois |
|---|---|---|---|
| **N+1** em `GET /api/sistema/utilizadores`: uma consulta às ligações a empresas por cada utilizador (`ServicoUtilizadores::apresentar`) | 5 consultas com 1 utilizador, 33 com 26 (+1 por linha) | `ligacoesDeUtilizadores()`: todas as ligações da página numa só consulta | 33 → **8** consultas com 26 utilizadores; o número deixou de depender das linhas (guardado pelo teste) |
| Consulta duplicada `select e_consolidacao from empresas where id = ?` em `ServicoCubo::holding()` e `ServicoPaineis::holding()` | 18× no mesmo pedido de `GET /api/gestao/cubo/conjuntos` | memoização por empresa na instância (serviço por pedido; `ServicoPermissoes` já o fazia) | 20 → **3** consultas |
| Palavra-passe com hash bcrypt antigo → **500 no login** com `HASH_DRIVER=argon2id` (`Hash::check` lança excepção) | `CriptografiaTokensTest::um_hash_fraco_antigo…` | `password_verify` (lê qualquer algoritmo); o rehash para Argon2id no login mantém-se | 500 → 200 |

## 3. Índices

### 3.1 Aplicado — `idx_notificacoes_nao_lidas` (migração `2026_11_29_100000`, `CREATE INDEX CONCURRENTLY IF NOT EXISTS`, fora de transacção)

Consulta (centro de notificações, «só não lidas»): `WHERE empresa_id = ? AND lida = false AND (utilizador_id IS NULL OR utilizador_id = ?) ORDER BY id DESC LIMIT 80`.
Os índices existentes `(empresa_id, lida, criado_em)` e `(empresa_id, criado_em)` não servem a ordenação por `id`; quando a empresa já leu tudo (o caso normal)
o planeador percorre a chave primária para trás, linha a linha, filtrando.

| | Plano | Tempo (EXPLAIN ANALYZE) |
|---|---|---|
| Antes (600 000 linhas, 40 empresas, empresa com tudo lido) | `Index Scan Backward using notificacoes_sistema_pkey` · `Rows Removed by Filter: 600000` | **360,7 ms** (e 377,5 ms noutra empresa) |
| Depois: `(empresa_id, id DESC) WHERE lida = false` | `Index Scan using idx_notificacoes_nao_lidas` | **0,25 ms** (0,23 ms) |

Custo: índice parcial de **3,7 MB** (o `idx_notificacoes_empresa_lida` tem 41 MB). Escritas: só as notificações não lidas o mantêm. Reverter: `DROP INDEX CONCURRENTLY`.

### 3.2 Analisados e **não** criados (sem prova de utilidade)

- `logs_auditoria` (partida por ano): `WHERE empresa_id = ? ORDER BY id DESC LIMIT 25` usa o `*_pkey` de trás para a frente: 0,9 ms com 900 000 linhas, também para uma empresa esparsa (0,99 ms). Um índice `(empresa_id, id DESC)` nas partições não trazia ganho medido e `CREATE INDEX CONCURRENTLY` não é permitido na tabela-mãe partida.
- Notificações da empresa sem filtro de leitura: 1,8 ms com 600 000 linhas; empresa esparsa 0,55 ms.
- **63 chaves estrangeiras sem índice** (`utilizadores.perfil_utilizador_id`, tabelas das tarefas/entregáveis, `ia_*`, `outbox_eventos.empresa_id`, …): só têm custo no `DELETE`/`UPDATE` do pai e em *joins* por essa coluna; as tabelas são pequenas ou de escrita rara e a maioria já tem índices compostos com `empresa_id` à frente. Sem dados reais não há medição que justifique o custo de escrita de 63 índices. **Para rever em produção** com `pg_stat_statements` (extensão ainda não instalada: `CREATE EXTENSION pg_stat_statements` + `shared_preload_libraries`) e `pg_stat_user_tables.seq_scan`/`seq_tup_read` depois de algumas semanas de uso.

## 4. Cache Redis, filas, limites, serialização e paginação

- **Cache**: nenhuma cache nova de dados de negócio. A única escrita nova na cache é `mfa:passo:{utilizador}:{contador}` (TTL 120 s, só ids; torna o TOTP de uso único) — acrescentada ao inventário de `arquitetura/CACHE.md` e à lista de `RegrasCacheTest`. O inventário existente já isola por empresa/utilizador e invalida em duplo tempo (R2).
- **Filas**: todos os jobs (`app/Jobs/**`) declaram `tries` ou `backoff`; `after_commit` ligado; o agendador usa `withoutOverlapping`/`onOneServer`; o outbox tem lease, recuo exponencial e reprocessamento (ADR-080). Sem alterações.
- **Rate limiting** (limitadores nomeados em `AppServiceProvider`): `entrar` 5/min por utilizador+IP e 60/min por IP, `api` 300/min por utilizador, `externo` 6/min, `ia-chat`, `pesado` 30/min, `palavra-passe` 5/min (agora também `reautenticar`). Sem alterações.
- **Paginação com tecto**: `toda_a_listagem_paginada_tem_tecto_de_linhas_por_pagina` pede `por_pagina=999999` a todas as listagens GET e exige `metadados.paginacao.por_pagina <= 1000`.
- **Serialização**: não se reviram os Resources um a um (fica como melhoria futura: devolver só as colunas que cada ecrã usa); a ausência de `palavra_passe`, segredos e tokens nas respostas já é fixada pelo `OwaspSegurancaTest`. Sem alterações.

## 5. Como repetir as medições

```bash
export MSYS_NO_PATHCONV=1
# guarda de consultas (escreve storage/app/desempenho_consultas.json com o número por rota)
docker exec -e ERP_MEDIR_DESEMPENHO=1 erp_app_e2e php artisan test --filter=ConsultasPorEndpointTest
# plano de uma consulta numa BD com volume
psql -d erp_agente_seg_perf -c "explain (analyze, buffers) select * from notificacoes_sistema where empresa_id = 7 and lida = false order by id desc limit 80"
```
