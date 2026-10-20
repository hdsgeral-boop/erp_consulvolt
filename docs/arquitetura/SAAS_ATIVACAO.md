# Activar o SaaS (mudança para a role `erp_app`)

> Estado em 2026-10-20: **activado** em produção (`SAAS_ATIVO=true`, a aplicação liga como `erp_app`).
>
> Isolamento por cliente além da RLS das tabelas com `empresa_id`:
> - `utilizadores` e `perfis_utilizador`: filtro global no modelo pelo tenant activo (o tenant da plataforma vê todos).
>   Cada cliente tem a sua cópia dos perfis; um cliente novo recebe os perfis-modelo da plataforma.
> - `logs_auditoria`: `tenant_id` preenchido por trigger e RLS só de leitura (inserção sempre permitida; continua imutável).
> - Identidade do sistema (logótipo e nome) por cliente em `tenants.logotipo_sistema` / `nome_sistema`.
> - Partilhados e só da plataforma: catálogo de moedas, câmbios de âmbito «todas» (o BAI grava-os), restauro do legado.
> - Entrada pelo domínio base: valida as credenciais e reencaminha para `<slug>.<base>` com um código de uso único (60 s).

## Pré-requisitos
1. Fase 3 concluída (teste de enumeração verde).
2. Backup recente (`pg_dump -Fc`) e contagem de linhas por tabela.
3. Migrações aplicadas (inclui `2026_10_17_150000_saas_tenants_base` e `2026_10_17_160000_saas_rls_base`).

## Passos
1. No `.env` da raiz: `DB_APP_USERNAME=erp_app`, `DB_APP_PASSWORD=<palavra-passe forte>`, `SAAS_ATIVO=true`, `SAAS_LIGACAO_DONO=pgsql_owner`.
2. Definir a palavra-passe da role: `docker compose exec app php artisan erp:saas:aplicar-rls` (usa `DB_APP_PASSWORD` do ambiente do contentor; ou `ALTER ROLE erp_app PASSWORD ...`). O comando é idempotente. Antes de recriar os serviços, o contentor ainda não tem `DB_APP_PASSWORD`: definir a palavra-passe com `ALTER ROLE` neste passo.
3. Recriar `app`, `worker` e `scheduler`: `docker compose up -d app worker scheduler`.
4. Migrações a partir daqui: `php artisan migrate --database=pgsql_owner` (a role `erp_app` não tem DDL).
5. Verificar: login, uma venda, um lançamento, `/api/saude`, e `erp:saas:aplicar-rls --verificar`.

## Reverter
`SAAS_ATIVO=false`, retirar `DB_APP_USERNAME` e `DB_APP_PASSWORD`, recriar os três serviços. A RLS fica inerte para o superuser.

## Regra para migrações novas
Se criar uma tabela com `empresa_id`, terminar com `(new \App\Support\Tenancy\IsolamentoRls(DB::connection()))->aplicarATodas();`.
