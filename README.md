# ERP Consulvolt — Nova Arquitectura

Migração do ERP legado `payroll_system_web` (Vanilla JS + Dexie/IndexedDB) para
**Laravel 12 · PHP 8.3 · PostgreSQL 16 · Redis 7 · React (Fase 5) · Docker**, com base de dados, models e API 100% em português.

| Documento | Conteúdo |
| :--- | :--- |
| [docs/FASES.md](docs/FASES.md) | Plano de fases e estado de cada entrega |
| [docs/arquitetura/DECISOES.md](docs/arquitetura/DECISOES.md) | Decisões de arquitectura (ADR) e respectivos porquês |
| [docs/dicionario/DICIONARIO_DADOS.md](docs/dicionario/DICIONARIO_DADOS.md) | DE/PARA das 154 tabelas e 1 652 colunas (gerado) |
| [docs/paridade/INVENTARIO_FUNCIONAL_LEGADO.md](docs/paridade/INVENTARIO_FUNCIONAL_LEGADO.md) | Catálogo de views e funcionalidades do legado a reproduzir |

## Arranque

Requisitos: Docker Desktop. O PHP local (XAMPP 8.0) **não** é usado — tudo corre nos contentores.

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
docker compose exec app php artisan erp:auditoria:particoes   # partições anuais de logs_auditoria
```

Regenerar o dicionário de dados a partir do backup (Node 18+):

```bash
node --max-old-space-size=4096 ferramentas/levantamento/levantar_colunas.mjs ../wstb_payroll_backup_2026-09-22.json docs/dicionario/levantamento_colunas_legado.json
node --max-old-space-size=4096 ferramentas/levantamento/gerar_dicionario.mjs ../wstb_payroll_backup_2026-09-22.json docs/dicionario/levantamento_colunas_legado.json docs/dicionario
```

## API

Todas as respostas usam o envelope:

```json
{ "sucesso": true, "mensagem": "…", "dados": {…}, "metadados": { "empresa_id": 5, "executado_em": "2026-09-29T09:15:00Z" } }
```

Erros acrescentam `codigo` (estável, para o frontend) e `erros` (por campo, em validações).
Autenticação por `Authorization: Bearer <token>`; dados de empresa exigem `X-Empresa-Id: <id>`.

| Método | Endpoint | Descrição |
| :--- | :--- | :--- |
| GET | `/api/saude` | Estado de PostgreSQL e Redis |
| POST | `/api/autenticacao/entrar` | Login (`nome_utilizador`, `palavra_passe`, `dispositivo`) → token |
| GET | `/api/autenticacao/eu` | Utilizador, permissões efectivas e empresas acessíveis |
| POST | `/api/autenticacao/sair` | Revoga o token actual |
| GET | `/api/sistema/empresas` | Empresas acessíveis (selector do Top Header) |
| GET | `/api/sistema/empresas/{id}` | Detalhe (inclui logótipo) |
| GET | `/api/sistema/logs` | Auditoria da empresa activa (filtros, paginação) — requer `config_logs_view` |

## Estrutura

```
erp_laravel/
├── backend/                 Laravel 12 (API)
│   ├── app/Http/{Controllers/Api,Requests,Resources,Middleware}
│   ├── app/Services/{Modulo}/          regras de negócio (controllers finos)
│   ├── app/Models/ (+ Concerns/, Scopes/)   Eloquent em português, multi-empresa
│   ├── app/Support/{Api,Tenancy,Cache}      envelope, contexto de empresa, chaves Redis
│   └── database/migrations/
├── frontend/                React + TypeScript (Fase 5); dist/ servido pelo Nginx
├── docker/                  php (Dockerfile, ini, entrypoint), nginx, postgres/init
├── ferramentas/levantamento/  levantamento do backup e gerador do dicionário DE/PARA
└── docs/
```
