# Activar o SaaS (mudança para a role `erp_app`)

> Estado em 2026-10-17: **não activado**. A aplicação liga como superuser com `SAAS_ATIVO=false`. Não activar antes da fase 3 (caminhos sem sessão), senão o webhook AGT, o feed OData, os comandos e o agendador deixam de ver dados.

## Pré-requisitos
1. Fase 3 concluída (teste de enumeração verde).
2. Backup recente (`pg_dump -Fc`) e contagem de linhas por tabela.
3. Migrações aplicadas (inclui `2026_10_17_150000_saas_tenants_base` e `2026_10_17_160000_saas_rls_base`).

## Passos
1. No `.env` da raiz: `DB_APP_USERNAME=erp_app`, `DB_APP_PASSWORD=<palavra-passe forte>`, `SAAS_ATIVO=true`, `SAAS_LIGACAO_DONO=pgsql_owner`.
2. Definir a palavra-passe da role: `docker compose exec app php artisan erp:saas:aplicar-rls` (usa `DB_APP_PASSWORD` do ambiente do contentor; ou `ALTER ROLE erp_app PASSWORD ...`). O comando é idempotente. Antes de recriar os serviços, o contentor ainda não tem `DB_APP_PASSWORD`: definir a palavra-passe com `ALTER ROLE` neste passo.
3. Recriar `app`, `worker` e `scheduler`: `docker compose up -d app worker scheduler`.
4. Migrações a partir daqui: `php artisan migrate --database=pgsql_owner` (a role `erp_app` não tem DDL).
5. **`docker compose exec app php artisan cache:clear`** (obrigatório, nunca opcional). As listas de empresas
   acessíveis por utilizador (`ServicoEmpresas::idsAcessiveis`, docs/arquitetura/CACHE.md §2) e outras chaves
   calculadas antes deste passo foram computadas sem RLS (ligação superuser): ficam em cache até ao TTL com o
   resultado "sem restrição" de antes, e passam a divergir da leitura real agora filtrada por tenant — na prática,
   um utilizador com `acesso_todas_empresas`/perfil total via a sua lista de empresas ficar vazia até a chave
   expirar (até 1800 s), porque a consulta already-cached nunca correu já com a tenant a sério. A mudança de
   `erp.saas.ativo` é uma mudança de infra-estrutura que a cache não observa (só reage a escritas nos modelos) —
   por isso aqui, tal como no ETL/restauro (PRODUCAO.md §13.4), a limpeza é manual.
6. Verificar: login, uma venda, um lançamento, `/api/saude`, e `erp:saas:aplicar-rls --verificar`.

## Reverter
`SAAS_ATIVO=false`, retirar `DB_APP_USERNAME` e `DB_APP_PASSWORD`, recriar os três serviços, `cache:clear` outra vez
(pela mesma razão do passo 5: a cache pode ter chaves calculadas já com RLS que ficam incorrectas sem ele). A RLS
fica inerte para o superuser.

## Regra para migrações novas
Se criar uma tabela com `empresa_id`, terminar com `(new \App\Support\Tenancy\IsolamentoRls(DB::connection()))->aplicarATodas();`.
