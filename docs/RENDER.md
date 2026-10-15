# Deploy na Render.com (plano gratuito) + Supabase

> **Esta é a cópia `erp_laravel_render` (branch `render`).** A produção AWS continua no projeto `erp_laravel`
> (branch `main`) e não usa nenhum destes ficheiros.

## Arquitetura

| Componente | Serviço | Plano | Ficheiros |
|---|---|---|---|
| API Laravel 12 / PHP 8.3 | Render Web Service (Docker): nginx + PHP-FPM + supervisord, porta `$PORT` | free | `docker/render/*`, `render.yaml` |
| SPA Vite/React | Render Static Site (`frontend/` → `dist/`) | free | `render.yaml`, `frontend/src/api/cliente.ts` (`VITE_API_URL`) |
| PostgreSQL | Supabase (projeto gratuito, sem expiração aos 30 dias) | free | — |

O que muda em relação à produção AWS:

- **Sem Redis:** `CACHE_STORE=file` (os `Cache::lock` da numeração/séries funcionam numa só instância),
  `SESSION_DRIVER=cookie` (a API é só Bearer/Sanctum), `QUEUE_CONNECTION=sync` (os trabalhos correm no próprio pedido).
- **SPA noutra origem:** o frontend chama `${VITE_API_URL}/api`; o CORS do Laravel autoriza só `ERP_CORS_ORIGENS`.
- **Arranque:** `config:cache`, `route:cache`, `event:cache`, `view:cache` e `migrate --force` (com 5 tentativas).
- **`/api/saude`** já não exige Redis quando nenhum store/fila o usa. O health check da Render usa `/up`.
- **Scheduler** desligado (`ERP_SCHEDULER=false`); **AGT** desligada (não há chaves PEM neste ambiente).

### Limitações do plano gratuito (aceitar antes de usar com dados reais)

1. **Disco efémero:** tudo o que o ERP grava em `storage/app` (relatórios de migração, ficheiros importados, cópias
   de empresa) **perde-se** em cada deploy/reinício. A base de dados (Supabase) é persistente.
2. **Adormece após 15 min** sem pedidos; o primeiro pedido seguinte demora ~30–60 s (arranque + migrações).
3. **512 MB de RAM / 0,1 CPU:** relatórios pesados, PDFs em massa e importações grandes podem ser lentos ou falhar.
4. **Supabase gratuito** pausa o projeto após 7 dias sem atividade (reativa-se no painel) e não tem cópias PITR.
5. **750 h/mês** de instância gratuita por conta (chega para 1 serviço sempre ligado).

## Passo a passo

1. **Supabase:** criar projeto (região `eu-central-1` / Frankfurt, igual à Render). Guardar a palavra-passe da base.
   Em *Connect › Session pooler* copiar host, porta (5432) e utilizador (`postgres.<project-ref>`).
   Usar o **Session pooler** e não a ligação direta (`db.<ref>.supabase.co` é só IPv6, que a Render não suporta)
   nem o Transaction pooler (6543, incompatível com prepared statements do PDO).
2. **Gerar a `APP_KEY`** (uma vez; guardar num cofre):
   ```sh
   docker run --rm php:8.3-cli-alpine php -r 'echo "base64:".base64_encode(random_bytes(32)), PHP_EOL;'
   ```
3. **Publicar o branch:** `git push -u origin render` (a partir de `erp_laravel_render`).
4. **Render:** *New › Blueprint* › repositório `hdsgeral-boop/erp_consulvolt` › branch `render` › preencher as
   variáveis pedidas (tabela abaixo) › *Apply*.
5. **Confirmar os URLs reais** dos dois serviços. Se a Render acrescentou um sufixo ao nome, atualizar no `render.yaml`
   (ou no painel) `APP_URL`, `ERP_CORS_ORIGENS`, `VITE_API_URL` e o `connect-src` da CSP, e fazer novo deploy do Static Site
   (o `VITE_API_URL` é fixado no build).
6. **Dados:** a base fica vazia (só a estrutura). Para levar os dados da produção AWS:
   ```sh
   pg_dump --no-owner --no-acl --data-only --exclude-table=migracoes -Fc -f erp.dump <base AWS>
   pg_restore --no-owner --no-acl --data-only --disable-triggers -d "<URI do Session pooler do Supabase>" erp.dump
   ```
   (`--disable-triggers` exige o utilizador `postgres` do Supabase.) Em alternativa, importar o backup do legado com
   `php artisan erp:migrar-backup-legado` na *Shell* do serviço (não disponível no plano free — fazer a partir de um
   contentor local com as mesmas variáveis).
7. **Verificar:** `https://<api>/up` → 200; `https://<api>/api/saude` → `estado: OK`; abrir o Static Site e entrar.

## Proteção da API REST do Supabase (obrigatório)

O Supabase expõe o schema `public` pela API REST às roles `anon`/`authenticated` (a chave `anon` é pública).
O ERP liga-se como `postgres`, por isso estes privilégios foram retirados no projeto (já aplicado em 2026-10-15):

```sql
REVOKE ALL ON ALL TABLES    IN SCHEMA public FROM anon, authenticated;
REVOKE ALL ON ALL SEQUENCES IN SCHEMA public FROM anon, authenticated;
REVOKE ALL ON ALL FUNCTIONS IN SCHEMA public FROM anon, authenticated;
ALTER DEFAULT PRIVILEGES FOR ROLE postgres IN SCHEMA public REVOKE ALL ON TABLES    FROM anon, authenticated;
ALTER DEFAULT PRIVILEGES FOR ROLE postgres IN SCHEMA public REVOKE ALL ON SEQUENCES FROM anon, authenticated;
ALTER DEFAULT PRIVILEGES FOR ROLE postgres IN SCHEMA public REVOKE ALL ON FUNCTIONS FROM anon, authenticated;
REVOKE USAGE ON SCHEMA public FROM anon, authenticated;
```

Repetir num projeto Supabase novo **antes** das migrações. Reforço recomendado: *Project Settings › Data API* › desligar.

## Checklist de variáveis de ambiente

### API (`erp-consulvolt-render-api`) — a preencher no painel (`sync: false`)

| Variável | Onde obter | Exemplo |
|---|---|---|
| `APP_KEY` | gerar (passo 2). **Nunca mudar** depois de haver dados cifrados | `base64:3q2+7w…=` |
| `DB_PASSWORD` | palavra-passe definida ao criar o projeto Supabase | — |
| `ANTHROPIC_API_KEY` | opcional; vazio = assistente IA desligado | `sk-ant-…` |

### API — já pré-configuradas no `render.yaml` (rever)

| Variável | Valor | Rever quando |
|---|---|---|
| `APP_URL` | `https://erp-consulvolt-render-api.onrender.com` | o URL real da API for diferente |
| `DB_HOST` / `DB_USERNAME` | `aws-0-eu-west-1.pooler.supabase.com` / `postgres.ysjxfrolufcdjhqxuejo` | mudar de projeto Supabase |
| `ERP_CORS_ORIGENS` | `https://erp-consulvolt-render-web.onrender.com` | o URL real do Static Site for diferente / domínio próprio |
| `DB_PORT` / `DB_DATABASE` / `DB_SSLMODE` | `5432` / `postgres` / `require` | nunca (Session pooler) |
| `CACHE_STORE` / `SESSION_DRIVER` / `QUEUE_CONNECTION` | `file` / `cookie` / `sync` | se passar a ter Redis (Render Key Value pago) |
| `AGT_DRIVER` | `desligado` | nunca neste ambiente (sem chaves PEM) |
| `PHP_MEMORY_LIMIT` / `PHP_FPM_MAX_CHILDREN` | `256M` / `4` | se mudar de plano (mais RAM) |
| `ERP_MIGRAR_NO_ARRANQUE` | `1` | `0` para controlar migrações à mão |
| `ERP_SCHEDULER` | `false` | `true` para ciclo AGT / câmbios BAI automáticos (gasta RAM) |
| `APP_DEBUG` | `false` | nunca `true` em ambiente público (OWASP A05) |

### Static Site (`erp-consulvolt-render-web`)

| Variável | Valor | Nota |
|---|---|---|
| `VITE_API_URL` | `https://erp-consulvolt-render-api.onrender.com` | sem `/api` nem `/` no fim; lido no build |
| `NODE_VERSION` | `22` | igual ao `Dockerfile.prod` |

Também no Static Site: o cabeçalho `Content-Security-Policy` tem o URL da API no `connect-src` — tem de coincidir
com `VITE_API_URL`, senão o navegador bloqueia todos os pedidos.

## Testar a imagem localmente

```sh
cp docker/render/.env.render.example docker/render/.env.render   # preencher (ignorado pelo Git)
docker build -f docker/render/Dockerfile.render -t erp_consulvolt/render-api .
docker run --rm -p 10000:10000 --env-file docker/render/.env.render erp_consulvolt/render-api
curl -i http://localhost:10000/up
```
