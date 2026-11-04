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


## Lições de operação (integradas da produção, 2026-11)

- **Ficheiros de ambiente.** `.env` na raiz: lido pelo `docker compose` (recriar os serviços ao alterar). `backend/.env`: lido
  pela aplicação dentro do contentor; o PHP-FPM corre como `www-data` (gid 82), por isso o ficheiro **tem de ser legível por esse
  grupo**: `chgrp 82 backend/.env && chmod 640 backend/.env`. Se não for legível a aplicação ignora-o em silêncio (a cache cai
  para `database` e todos os logins dão `ERRO_INTERNO`). Confirmar sempre depois de editar ou recriar o ficheiro.
- **`DB_APP_PASSWORD` só por `config()`.** O código lê `config('erp.saas.db_app_password')`: com `php artisan optimize` (config em
  cache) `env()` fora de `config/` devolve vazio e a palavra-passe da role `erp_app` ficaria por definir. O teste
  `SemEnvForaDaConfigTest` impede `env()` fora de `config/`.
- **Migrações depois da activação**: sempre com a ligação do dono (`--database=pgsql_owner`), porque `erp_app` não tem DDL.
- **Cache de empresas por utilizador** (`ServicoEmpresas`, chave de versão `empresas:versao`): uma lista calculada sem contexto de
  tenant (sob RLS devolve `[]`) ficaria vazia até expirar. No teste o login define o tenant assim que identifica o utilizador
  (`ServicoAutenticacao::entrar`), e `LoginEmpresasSobRlsTest` (com `SET ROLE erp_app`) cobre a regressão. Se uma lista estiver
  errada, invalidar com `php artisan erp:empresas:invalidar-cache` (correr também a cada deploy).
- **Diagnóstico do isolamento.** `IsolamentoRls::estado()` (devolvido em `GET /api/plataforma/saas/estado`, chave `isolamento`) só dá
  «activo» se: SaaS ligado, a ligação não for superutilizador nem `BYPASSRLS`, não for dona das tabelas isoladas, `row_security`
  estiver `on` e todas as tabelas tiverem política. Com ligação do dono (testes, migrações) nunca está activo, por desenho.
- **Verificação pós-activação/deploy**: `ferramentas/operacao/verificar_saas.sh` (só leitura; credenciais por variáveis de ambiente, contas sem MFA
  e sem troca de palavra-passe pendente): login em cada host, token do cliente A recusado (403) no B e na plataforma,
  lista de empresas não vazia, `/api/saude`.
- **Simulação de pagamento.** `POST /api/licenca/subscricoes/{id}/simular-confirmacao` só responde com `SAAS_SIMULACAO_PAGAMENTO=true`
  (não depende de `APP_ENV`: o servidor de teste corre com `APP_ENV=production`). Em produção real deixar `false`.
- **Referência (não activada).** Os documentos de deploy da produção (Caddy, RDS, scripts de instalação/actualização) estão em
  `docs/paridade/producao/`.
