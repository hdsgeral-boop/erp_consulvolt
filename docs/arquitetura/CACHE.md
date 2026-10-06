# Cache Redis — inventário, invalidação e regras

> Estado a 2026-10-06. Fontes: `app/Support/Cache/*`, `config/{cache,database,erp,session}.php`, ADR-065/067/068,
> `docs/paridade/ANALISE_REGRAS_REDIS_2026-10-02.md` (riscos R1–R12). Testes que fixam estas regras:
> `InvalidacaoCacheTest`, `LimpezaCacheTest`, `CompressaoCacheRedisTest`, `RegrasCacheTest`, `SaudeTest`.

## 1. Topologia

| Base lógica | Ligação | Conteúdo | Prefixo |
|---|---|---|---|
| 0 | `default` | filas (`queues:*`), locks (`Cache::lock` usa `lock_connection=default`), mutex do agendador, batches | `REDIS_PREFIX` (vazio) |
| 1 | `cache` | toda a store `redis`: dados em cache, **RateLimiter** (`entrar`, `api`, OData), batimentos, `empresas:versao`, donos de operações | `CACHE_PREFIX=erp:` |
| 2 / 3 | E2E | iguais às anteriores, com prefixos `erp_e2e:` / `erp_e2e_cache:` | — |

As bases 0 e 1 **têm de continuar distintas**: `LimpezaCache` só faz `FLUSHDB` quando a base da cache é dedicada;
numa base partilhada apaga por `SCAN` só o prefixo da cache. Sessões: **não usam o Redis** (`SESSION_DRIVER=cookie`
desde 2026-10-06; a API é sem estado, Bearer Sanctum com `sanctum.guard=[]`, sem `statefulApi`, sem rotas web).

## 2. Inventário (o que está em cache)

Chave no Redis = `erp:` + chave lógica. TTL em `config/erp.php` (`erp.cache.ttl.*`) salvo indicação.

| Chave lógica | Dono (quem grava) | Âmbito | TTL | Tamanho típico | Invalidação |
|---|---|---|---|---|---|
| `utilizador:{id}:empresas:v{versão}` | `ServicoEmpresas::idsAcessiveis` (usado por `ResolverEmpresaAtiva` em todos os pedidos com empresa) | utilizador | 1800 s | < 1 KB | `invalidarUtilizador` (agora + depois do commit): `Utilizador::saved` (papel, acesso a todas, activo), `UtilizadorEmpresa::saved/deleted`, `ServicoUtilizadores`, `ServicoGestaoEmpresas`, `ServicoCopiaEmpresa`, `ServicoConsolidacaoGrupos`. Global: `invalidarTodos` sobe `empresas:versao` (`Empresa::saved/deleted/restored`, grupos de consolidação) |
| `empresas:versao` | `ServicoEmpresas::invalidarTodos` (`Cache::add` + `Cache::increment`, atómico — R7) | global | sem TTL | inteiro | é o próprio mecanismo de versionamento |
| `{empresa}:contabilidade:plano_contas` | `ServicoPlanoContas::todas` via `CacheComprimida` | empresa | 14 400 s | ~200 KB → **~26 KB comprimido** | `PlanoConta::saved/deleted/restored` (`InvalidacaoCache::esquecer`), `ServicoConsolidacao` (plano da holding), `ServicoCopiaEmpresa::invalidarCachesEmpresa`, `ServicoMigracaoDados` (rollback da simulação), `LimpezaCache` (ETL) |
| `{empresa}:logistica:catalogo_produtos` | `ServicoProdutos::catalogo` via `CacheComprimida` | empresa | 7200 s | 0,3–18 KB (comprime > 8 KB) | `Produto::saved/deleted/restored`, `ServicoSubstituicaoConta`, `ServicoCopiaEmpresa`, `ServicoMigracaoDados`, `LimpezaCache` |
| `gestao:painel:{md5(empresa, painel, mês, filtros, módulos visíveis, ver_salarios, empresas acessíveis)}` | `ServicoPaineis::painel` | empresa + permissões | 120 s (`ServicoPaineis::TTL_CACHE`) | 5–50 KB | só TTL e `actualizar=1` (R9, aceite) |
| `gestao:comparacao:{md5(utilizador, ids, mês)}` | `PaineisController::comparacao` | utilizador | 120 s | 5–30 KB | só TTL e `actualizar=1`. O acesso a cada empresa é verificado **antes** do `remember` (`ServicoComparacaoEmpresas::garantirAcesso`, ADR-069): um acesso retirado deixa de ver a comparação de imediato (`GestaoPaineisTest::comparacao_em_cache_deixa_de_ser_vista_logo_que_o_acesso_e_retirado`) |
| `fk_referencias:{versão do esquema}:{tabela}` | `VerificadorReferencias::referenciasPara` | global (metadados do esquema) | 3600 s | < 2 KB | **versionada pela última migração** (corrigido R8 em 2026-10-06) |
| `copia_empresa:tabelas:{versão do esquema}` | `ServicoCopiaEmpresa::tabelasEmpresa` | global (metadados) | 3600 s | < 4 KB | versionada pela última migração |
| `operacoes:{uuid do batch}` | `ServicoOperacoes::registarDono` | utilizador + empresa (no valor) | 7 dias | < 1 KB | expira; o dono é verificado em cada leitura |
| `batimento:{scheduler,worker}` | `Batimentos::registar` | global | sem TTL (`forever`) | inteiro | sobrescrito a cada batimento |
| limitadores (`entrar`, `api`, `odata:*`) | `RateLimiter` | IP / utilizador / token | janela (60 s) | inteiro | expira |

Reservados em `config/erp.php` mas **sem uso**: `permissoes_utilizador` (as permissões são memorizadas por pedido
em `ServicoPermissoes`, nunca no Redis) e `taxas_cambio`.

## 3. Quem invalida e quando

1. **Eventos de model** (`booted()` de `PlanoConta`, `Produto`, `Empresa`, `Utilizador`, `UtilizadorEmpresa`) chamam
   `InvalidacaoCache::esquecer` / `ServicoEmpresas::invalidar*`, que usam `InvalidacaoCache::agoraEDepoisDoCommit`:
   apaga **já** e **outra vez depois do COMMIT** (R2) — um pedido concorrente que, na janela da transacção, regrave
   os dados antigos (ainda os confirmados) não os deixa ficar. Sem transacção aberta, o `afterCommit` corre logo.
2. **Serviços** que escrevem sem eventos (`DB::table()->insert`, consolidação, cópia/clone de empresa, substituição
   de conta) invalidam explicitamente as chaves da empresa afectada, também em duplo tempo.
3. **ETL / migração do legado** (`ServicoMigracaoLegado`, `erp:pos-carga`): `TRUNCATE … RESTART IDENTITY` não dispara
   eventos e reutiliza ids → `LimpezaCache::limparTudo()` no fim (e no fim da simulação). Também obrigatório
   `php artisan cache:clear` depois de SQL directo ou restauro (PRODUCAO.md §13.4).
4. **Simulações** que fazem rollback (importação em massa) apagam as chaves que possam ter sido preenchidas.
5. **Mudanças globais** (estado de uma empresa) não fazem `SCAN/DEL`: sobem a versão (`empresas:versao`) e as chaves
   antigas morrem pelo TTL.

## 4. Regras de ouro

1. **A chave inclui sempre o âmbito**: `ChaveCache::empresa($empresaId, $modulo, $chave)` → `erp:{empresa_id}:{modulo}:{chave}`
   ou `ChaveCache::utilizador($id, $chave)`. Dados que dependem de permissões incluem as permissões (ou o utilizador)
   na chave. Só metadados do esquema/sistema podem ser globais. O padrão das chaves de `ChaveCache` não muda.
2. **Autorizar fora da cache**: a decisão de acesso (`exigir`, `podeAceder`) faz-se antes de ler a cache; a cache
   nunca é a fonte da autorização.
3. **Invalidar depois do commit**: sempre `InvalidacaoCache::esquecer`/`agoraEDepoisDoCommit` (nunca só `Cache::forget`
   dentro de uma transacção). Não alterar `InvalidacaoCache::agoraEDepoisDoCommit`.
4. **Versionar em vez de varrer**: para invalidar muitas chaves, mudar uma versão na chave (`empresas:versao`, versão
   do esquema). `SCAN/DEL` em massa só em `LimpezaCache` (operações administrativas).
5. **TTL = rede de segurança**, nunca o mecanismo de frescura. Cada chave tem uma invalidação explícita; o TTL só
   limita o pior caso e liberta memória de empresas pouco consultadas. Excepções aceites e documentadas: painéis e
   comparação (120 s, com «Actualizar»).
6. **Nada sensível em cache**: nem palavras-passe/hashes, tokens (Sanctum, BI, AGT, Anthropic), chaves, NIF/IBAN em
   massa, salários individuais fora dos painéis já autorizados (`ver_salarios` entra na chave). O valor também não
   pode ter objectos: a cache guarda arrays/escalares (a `CacheComprimida` desserializa com `allowed_classes=false`).
7. **Tamanho e compressão**: um valor > 8 KB serializado passa por `CacheComprimida::lembrar` (gzip nível 6, marcador
   de versão `\0erpgz1:`, leitura retrocompatível, envelope corrompido = falta de cache). Nunca activar
   `Redis::OPT_COMPRESSION` na ligação (ver §5). Valores > 1 MB não vão para a cache — paginar ou resumir.
8. **Cache stampede**: os conjuntos actuais custam < 50 ms a recalcular e não justificam lock. Para um cálculo caro
   (> 1 s) usar `Cache::flexible($chave, [fresco, máximo], fn)` (serve o antigo enquanto recalcula) ou
   `Cache::lock("lock:recalc:{chave}", 30)->block(5, …)` com a ligação de locks (base 0).
9. **Redis em baixo**: a API depende do Redis (decisão R6): `throttle:api`, `ResolverEmpresaAtiva` e os locks de
   numeração devolvem erro (500, integridade garantida pela BD; nenhum número perdido). `/api/saude` está fora do
   throttle e responde 503 com `redis: FALHA`. O código novo não deve acrescentar *fallbacks* silenciosos que
   contornem autorização ou limites; leituras de cache opcionais podem usar `rescue()` com registo.
10. **Contadores** (`Cache::increment`) só sobre valores gravados sem serialização/compressão (`Cache::add($k, 1)` com
    inteiro). Ler sempre com cast `(int)` — o phpredis devolve strings.

## 5. Compressão — decisão (2026-10-06)

- **Não** se activa `Redis::OPT_COMPRESSION` na ligação `cache`:
  1. a extensão phpredis 6.3.0 da imagem não tem LZ4, ZSTD, LZF nem ZLIB compilados — a cascata `LZ4 → ZSTD → ZLIB →
     NONE` resolveria sempre para `NONE` (ganho zero);
  2. se um dia tivesse, a compressão aplica-se a todos os valores: `Cache::add('empresas:versao', 1)` gravaria o «1»
     comprimido e o `INCRBY` seguinte falha (`ERR value is not an integer`); o phpredis devolve `false` sem lançar e a
     invalidação global das listas de empresas deixava de funcionar em silêncio (provado em `CompressaoCacheRedisTest`).
     O `RateLimiter` do Laravel 12 protege-se (`withoutSerializationOrCompression`), o código da aplicação não.
- **Sim** à compressão na aplicação, só nos conjuntos grandes (`CacheComprimida`), mesma chave, mesma invalidação.
  Medição com os dados reais (14 empresas, leitura da base, escrita só no Redis): plano de contas + catálogo
  **3,10 MB → 0,51 MB de `used_memory` (−83 %)**; por chave, ~229 KB → ~29 KB (`MEMORY USAGE`). Custo: +~2 ms de CPU
  numa leitura do plano de contas (descompressão + desserialização), contra a ida à BD de ~30 ms.

## 6. Política de memória do servidor

- `maxmemory 512mb`, `maxmemory-policy volatile-lru`, AOF ligado com `auto-aof-rewrite-percentage 100` e
  `auto-aof-rewrite-min-size 64mb` (explícitos; são também os valores por omissão do Redis 7).
- `volatile-lru` só despeja chaves **com TTL**. Nunca despejadas: filas (`queues:*`), batches, batimentos,
  `empresas:versao` — correcto, perder um trabalho da fila AGT seria grave. **Despejáveis**: cache, limitadores e
  também os **locks** (têm TTL) e os mutex do agendador. Um lock despejado sob pressão deixaria entrar dois processos
  na secção crítica (a numeração continua protegida pelo `FOR UPDATE` na BD; o ciclo AGT pelo `ShouldBeUnique` + lock).
  Por isso o `maxmemory` tem de ter folga: o uso real é ~2 MB; vigiar `evicted_keys` (tem de ser 0) e
  `used_memory` > 70 % do `maxmemory` como alerta.
- `allkeys-lru` despejaria filas (rejeitado); `noeviction` faria falhar todas as escritas, incluindo o limitador da
  API, quando a memória enchesse (rejeitado).

## 7. Lista de verificação para um novo `Cache::remember`

- [ ] A chave usa `ChaveCache::empresa`/`utilizador` (ou é metadado global justificado) e inclui permissões se o
      resultado depender delas.
- [ ] A autorização é verificada **antes** e fora do `remember`.
- [ ] Existe invalidação explícita em todos os caminhos de escrita (eventos de model **e** escritas sem eventos:
      `DB::table`, importações, cópia de empresa, ETL), com `InvalidacaoCache::esquecer` (depois do commit).
- [ ] O TTL vem de `config('erp.cache.ttl.*')` e é uma rede de segurança (horas, não segundos), salvo painéis.
- [ ] O valor é array/escalares, sem objectos, sem dados pessoais sensíveis nem segredos.
- [ ] Tamanho estimado; > 8 KB → `CacheComprimida::lembrar`; > 1 MB → não guardar.
- [ ] Recalcular custa > 1 s? → `Cache::flexible` ou lock anti-stampede.
- [ ] Teste de invalidação depois do commit (modelo: `InvalidacaoCacheTest`) e o ficheiro acrescentado ao inventário
      deste documento e a `RegrasCacheTest::AUTORIZADOS`.
