# MEU ERP

ERP multi-empresa e SaaS multi-cliente para Angola (contabilidade, vendas, compras, tesouraria, logística, RH e salários, POS, activos, projectos, orçamento, CRM, consolidação), com facturação electrónica AGT.
Migração do ERP legado `payroll_system_web` (Vanilla JS + IndexedDB) para uma arquitectura de servidor, com base de dados, models e API em português.

**Stack:** Laravel 12 · PHP 8.3 · PostgreSQL 16 · Redis 7 · React 18 + TypeScript + Ant Design 5 (Vite) · Docker.

## Documentação

| Documento | Conteúdo |
| :--- | :--- |
| [docs/FASES.md](docs/FASES.md) | Plano de fases e estado de cada entrega |
| [docs/arquitetura/DECISOES.md](docs/arquitetura/DECISOES.md) | Decisões de arquitectura (ADR) e respectivos porquês |
| [docs/API.md](docs/API.md) | Referência dos endpoints da API |
| [docs/PRODUCAO.md](docs/PRODUCAO.md) | Runbook de produção: instalação, HTTPS, deploy, rollback, cópias e restauro, monitorização |
| [docs/DEPLOY_AWS.md](docs/DEPLOY_AWS.md) | Servidor na AWS (EC2 + RDS) e deploy automático |
| [docs/plataforma-saas/README.md](docs/plataforma-saas/README.md) | Plataforma SaaS: estado, activação e runbook |
| [docs/arquitetura/SAAS_ATIVACAO.md](docs/arquitetura/SAAS_ATIVACAO.md) | Activar o isolamento por cliente (RLS) |
| [docs/arquitetura/SEGURANCA_OWASP.md](docs/arquitetura/SEGURANCA_OWASP.md) · [CACHE.md](docs/arquitetura/CACHE.md) | Segurança e cache |
| [docs/integracoes/agt_faturacao_eletronica.md](docs/integracoes/agt_faturacao_eletronica.md) | Facturação electrónica AGT |
| [docs/dicionario/DICIONARIO_DADOS.md](docs/dicionario/DICIONARIO_DADOS.md) | DE/PARA das tabelas e colunas do legado (gerado) |
| [docs/paridade/INVENTARIO_FUNCIONAL_LEGADO.md](docs/paridade/INVENTARIO_FUNCIONAL_LEGADO.md) | Funcionalidades do legado a reproduzir |

## Arranque (desenvolvimento)

Requisito: Docker Desktop. O PHP local **não** é usado — tudo corre nos contentores.

```bash
cp .env.example .env                 # definir POSTGRES_PASSWORD e REDIS_PASSWORD
cp backend/.env.example backend/.env
docker compose up -d --build
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate
```

| Serviço | Contentor | Endereço (só localhost) |
| :--- | :--- | :--- |
| Nginx (SPA + proxy `/api`) | `erp_web` | http://127.0.0.1:8080 |
| Laravel PHP 8.3-FPM | `erp_app` | interno `app:9000` |
| Worker de filas | `erp_worker` | — |
| PostgreSQL 16 | `erp_postgres` | 127.0.0.1:5433 |
| Redis 7 | `erp_redis` | 127.0.0.1:6380 |

Verificação: `curl http://127.0.0.1:8080/api/saude`.

## Comandos do dia-a-dia

```bash
docker compose exec app php artisan test          # testes (PostgreSQL real, base erp_consulvolt_testes)
docker compose exec app vendor/bin/pint           # formatação PSR-12/Laravel
docker compose exec app composer require <pacote> # dependências SEMPRE dentro do contentor
docker compose exec app php artisan route:list    # todas as rotas da API
docker compose exec app php artisan erp:auditoria:particoes   # partições anuais de logs_auditoria
sh ferramentas/testes/correr_backend.sh           # suite PHPUnit COMPLETA em Windows (ver nota)
```

> **Nota (Docker Desktop em Windows):** na pasta montada do Windows, o `DirectoryIterator` do PHP só devolve parte
> das entradas de `backend/tests/Feature`, por isso `php artisan test` corre localmente só uma parte dos testes e parece verde.
> `ferramentas/testes/correr_backend.sh` passa a lista explícita de ficheiros e deve ser usado para a suite completa.
> O CI e a produção (Linux) não são afectados. Código que liste pastas usa `scandir()`/`glob()`. ADR-069.

## Frontend

React 18 + TypeScript + Ant Design 5 (Vite) em `frontend/`. Não há HTML de entrada: as páginas são duas views Blade (`backend/resources/views/erp.blade.php` e `plataforma.blade.php`) que carregam o JavaScript com `@vite()`. O Laravel escolhe a view pelo host (`DominioPedido`): `<DOMINIO_PLATAFORMA>.<DOMINIO_BASE>` é a plataforma SaaS, qualquer outro é o ERP. O nginx serve os activos compilados (`frontend/dist`, em `/build/`) e encaminha o resto para o Laravel.

```bash
cd frontend
npm install
npm run dev                  # HMR; abrir a aplicação pelo nginx (http://127.0.0.1:8080), não por :5173
npm run build                # verifica tipos e gera frontend/dist (+ manifest.json)
npm run testes               # Vitest
```

Estrutura de `src/`: `api` (cliente HTTP: token Bearer, `X-Empresa-Id`, envelope e erros), `sessao`, `layout` (menu vindo de `GET /api/sistema/menu`), `componentes` (tabelas, cabeçalho, gráficos SVG, impressão, responsivo), `utilitarios`, `modulos/<módulo>` (ecrãs registados pelo id do catálogo de permissões; rota `/m/{modulo}/{ecra}`) e `plataforma` (consola SaaS).

- **Imprimir/PDF:** motor comum em `frontend/src/componentes/impressao` (ver o `README.md` da pasta). O PDF é o «Guardar como PDF» do navegador.
- **Responsivo:** base em `frontend/src/componentes/responsivo`; menu em gaveta abaixo de 992 px.

## Plataforma SaaS

- Cada cliente é um **tenant** com os seus utilizadores e empresas, isolados por RLS do PostgreSQL (activar: `SAAS_ATIVO=true`, ver [SAAS_ATIVACAO](docs/arquitetura/SAAS_ATIVACAO.md)).
- A consola da plataforma (clientes, planos e licenças, suporte, IA, auditoria) está em `<DOMINIO_PLATAFORMA>.<DOMINIO_BASE>`. O tenant da própria plataforma (`PLATAFORMA_TENANT_ID`) é a equipa que a opera e **não conta como cliente** em nenhuma lista, indicador ou relatório.
- **IA:** o fornecedor (Anthropic, OpenAI ou Gemini), a chave (cifrada na base de dados), o modelo e os preços configuram-se em *Plataforma › IA* e valem para o chat e para o assistente de lançamentos; trocar lá troca em todo o lado. As variáveis `ANTHROPIC_API_KEY` e `ERP_IA_*` só servem de último recurso.
- **Variáveis de ambiente:** lidas apenas em `backend/config/*.php` e usadas com `config('…')`. Com a configuração em cache (`artisan optimize`, como no deploy), `env()` fora de `config/` devolve vazio; um teste impede-o (`SemEnvForaDaConfigTest`). Modelo de produção: `.env.prod.example`.

## Testes

```bash
docker compose exec app php artisan test                      # backend (PHPUnit)
cd frontend && npm run testes                                  # frontend (Vitest)
```

**Ponta-a-ponta (Playwright)** — ambiente isolado (base `erp_consulvolt_e2e` com dados fictícios, recriada a cada execução; nunca toca na base de desenvolvimento):

```powershell
docker compose -f docker-compose.yml -f docker-compose.e2e.yml up -d app_e2e web_e2e   # http://127.0.0.1:8081
cd frontend; npm install; npx playwright install chromium; npm run build
npm run e2e                                   # recria a base E2E e corre tudo
npx playwright test vendas                    # um ficheiro
```

Utilizadores fictícios e detalhes em [frontend/e2e/README.md](frontend/e2e/README.md).

**CI** (`.github/workflows/ci.yml`, em cada push/PR para `main`): backend (PHP 8.3, PostgreSQL 16, Redis 7, `composer audit`, Pint, PHPUnit), frontend (Node 22, `npm audit`, tsc, Vitest, build) e build das imagens de produção. *Actions* fixadas por SHA (ADR-069). Só dados fictícios.

## Produção e deploy

Runbook completo em [docs/PRODUCAO.md](docs/PRODUCAO.md).

**Na AWS** (EC2 + RDS), o deploy corre pelo workflow `.github/workflows/deploy-aws.yml`: compila o frontend, envia o pacote para o S3 e actualiza o servidor por SSM, sem SSH (migrações, `optimize` e verificação do site). Dispara-se a cada push em `main` depois do CI ou à mão:

```bash
gh workflow run deploy-aws.yml --ref main
```

**Com Docker Compose de produção** (imagens com o código dentro, FS só de leitura, sem root):

```bash
cp .env.prod.example .env.prod && chmod 600 .env.prod      # preencher segredos (nunca no Git)
sh ferramentas/operacao/prod.sh build
sh ferramentas/operacao/prod.sh up -d --wait postgres redis
sh ferramentas/operacao/prod.sh run --rm --no-deps app php artisan migrate --force
sh ferramentas/operacao/prod.sh up -d --wait
sh ferramentas/operacao/deploy.sh                          # novas versões: build, cópia, migrate, recriação, verificação
```

Cópias e restauro: `prod.sh run --rm --no-deps copias /operacao/backup.sh` e `/operacao/restaurar.sh <cópia> <base_nova>`.

## Facturação electrónica AGT (ADR-030)

1. Preencher as variáveis `AGT_*` em `backend/.env` (ver `backend/.env.example`), com `AGT_DRIVER=direto`.
2. Colocar as chaves PEM (RSA de 2048 bits ou mais) em `.segredos/agt/` (ou na pasta de `AGT_PASTA_SEGREDOS`); nunca entra no Git:
   - `produtor_privada.pem` — chave do produtor de software (a pública submete-se no Portal do Parceiro);
   - `contribuintes/<NIF>.pem` — chave de cada empresa (Portal do Contribuinte);
   - `saft_privada.pem` — assinatura SAF-T dos documentos, depois da certificação.
3. `docker compose up -d`. O `scheduler` corre `erp:agt:ciclo` de 2 em 2 minutos para as empresas com envio automático; a fila `agt` é processada pelo `worker`.
4. Em **Vendas › Facturação electrónica**, activar o regime e confirmar o estado em `GET /api/vendas/faturacao-eletronica/ligacao`.

Para continuar a usar o serviço intermédio do legado: `AGT_DRIVER=intermedio`, `AGT_INTERMEDIO_URL` e `AGT_INTERMEDIO_TOKEN`.

## Migração do legado e ferramentas

```bash
# migração do backup (simulação completa, valida tudo e desfaz no fim)
docker compose exec app php artisan erp:migrar-backup-legado /dados/legado/wstb_payroll_backup_2026-09-22.json --simular
# migração real (COMMIT); --substituir --force apaga antes os dados de negócio existentes
docker compose exec app php artisan erp:migrar-backup-legado /dados/legado/wstb_payroll_backup_2026-09-22.json
```

Depois do COMMIT aplicam-se os passos pós-carga (ADR-068); se falharem, repetir com `php artisan erp:migracao:pos-carga` (idempotente). O relatório fica em `backend/storage/app/private/migracao/` e em `execucoes_migracao.relatorio`; as correcções em `ocorrencias_migracao` e as linhas rejeitadas em `quarentena_migracao`.

Regenerar o dicionário de dados e o esquema (Node 18+, a partir do backup):

```bash
node --max-old-space-size=4096 ferramentas/levantamento/levantar_colunas.mjs ../wstb_payroll_backup_2026-09-22.json docs/dicionario/levantamento_colunas_legado.json
node --max-old-space-size=4096 ferramentas/levantamento/gerar_dicionario.mjs ../wstb_payroll_backup_2026-09-22.json docs/dicionario/levantamento_colunas_legado.json docs/dicionario
node --max-old-space-size=4096 ferramentas/gerador/gerar_esquema.mjs ../wstb_payroll_backup_2026-09-22.json   # migrations + app/Models/Base/*
docker compose exec app vendor/bin/pint && docker compose exec app php artisan migrate:fresh && docker compose exec app php artisan test
```

`app/Models/<Model>.php` nunca é sobrescrito (código de negócio); `app/Models/Base/<Model>Base.php` é sempre regenerado.

## Estrutura do repositório

```
├── backend/                 Laravel 12 (API)
│   ├── app/Http/{Controllers/Api,Requests,Resources,Middleware}
│   ├── app/Services/<Módulo>/     regras de negócio (controllers finos)
│   ├── app/Models/ (+ Base/, Concerns/, Scopes/)   Eloquent em português, multi-empresa
│   ├── app/Support/{Api,Tenancy,Cache}   envelope, contexto de empresa e tenant, chaves Redis
│   ├── config/  database/migrations/  routes/api/  tests/
├── frontend/                React + TypeScript (dist/ servido pelo Nginx; e2e/ = Playwright)
├── docker/                  Dockerfiles (dev e produção), nginx, PHP
├── deploy/                  aws/ (scripts de instalação e actualização), caddy/, cloudflared/
├── ferramentas/             levantamento, gerador, operacao (prod/deploy/cópias), testes, pesquisa
├── docs/                    documentação (ver o quadro no início)
└── docker-compose*.yml      dev, aws, prod, e2e, teste
```
