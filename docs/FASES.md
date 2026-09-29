# Plano de Fases

| Fase | Âmbito | Estado |
| :---: | :--- | :--- |
| **1** | Infraestrutura Docker, Laravel 12, multi-empresa, autenticação, envelope da API, auditoria, **dicionário DE/PARA das 154 tabelas**, inventário funcional do legado | ✅ Concluída (2026-09-29) |
| **2** | Migrations das 154 tabelas a partir do `mapa_de_para.json` (FKs, CHECKs, índices); estrutura das 29 tabelas sem dados, derivada do código JS; models Eloquent | ⏳ Seguinte |
| **3** | `php artisan erp:migrar-backup-legado` — ETL por streaming, pela ordem das dependências; regras de integridade; `ocorrencias_migracao` / `quarentena_migracao`; recalibração das sequences; relatório de validação (contagens, FKs, D−C) | Planeada |
| **4** | Services e endpoints por módulo (Sistema, Contabilidade, Terceiros, Logística, Vendas/AGT, Compras, RH/Salários, Tesouraria, POS, Activos, Projectos, Orçamento, A&D, CRM), cache e locks Redis, filas; catálogo de permissões | Planeada |
| **5** | Frontend React + TypeScript com o layout do legado (sidebar, top header, DataTables, modais, impressões A4), módulo a módulo, com a matriz de paridade | Planeada |
| **6** | Testes E2E, reconciliação contabilística cêntimo a cêntimo com o legado, homologação | Planeada |

---

## Fase 1 — Entregáveis e verificação

### Infraestrutura
- `docker-compose.yml` com 5 serviços: `app` (PHP 8.3-FPM), `web` (Nginx), `postgres` (16), `redis` (7) e `worker` (filas). Todos com healthchecks.
- As portas ficam presas a `127.0.0.1` e evitam as do XAMPP. Os segredos estão em `.env`, fora do controlo de versões.
- Extensões PHP: pdo_pgsql, redis, intl, bcmath, zip, opcache e pcntl. Hash de palavras-passe com Argon2id.
- Desempenho em desenvolvimento no Windows: de ~7 s para ~0,1 s por pedido (ver ADR-012).

### Backend
| Componente | Ficheiro |
| :--- | :--- |
| Envelope da API | `app/Support/Api/RespostaApi.php`, `app/Exceptions/ManipuladorExcecoesApi.php` |
| Multi-empresa (fail-closed) | `app/Support/Tenancy/ContextoEmpresa.php`, `app/Models/Scopes/EscopoEmpresa.php`, `app/Models/Concerns/PertenceEmpresa.php`, `app/Http/Middleware/ResolverEmpresaAtiva.php` |
| Autenticação + migração de hashes | `app/Services/Autenticacao/*`, `app/Models/TokenAcesso.php` |
| Permissões (semântica do legado) | `app/Services/Sistema/ServicoPermissoes.php` |
| Auditoria automática e particionada | `app/Models/Concerns/Auditavel.php`, `app/Services/Sistema/ServicoAuditoria.php`, `app/Console/Commands/CriarParticoesAuditoria.php` |
| Convenções de esquema | `app/Providers/BaseDadosServiceProvider.php` (macros `carimbosTemporais`, `monetario`, `quantidade`, `taxa`, `empresa`) |
| Tabelas | `empresas`, `perfis_utilizador`, `utilizadores`, `utilizador_empresa`, `tokens_acesso`, `logs_auditoria` (+ partições), `trabalhos_falhados`, `lotes_trabalhos` |

### Levantamento e dicionário
- 154 tabelas; 205 033 linhas no backup; 1 215 fictícias descartadas; **203 818 reais a migrar**.
- **1 652 colunas mapeadas**, 0 sem tradução, 0 colisões.
- 30 enumerações normalizadas, com todos os valores reais cobertos.
- 15 FKs com órfãos, cada uma com regra de tratamento; 29 tabelas sem dados reais.
- Inventário funcional do legado: 97 ecrãs no menu, cerca de 12 vistas escondidas, cerca de 60 separadores, 16 riscos de paridade.

### Testes (PostgreSQL real): 40 testes, 173 verificações, todos a passar
Cobrem os seguintes casos:
- Envelope da API em sucesso e em 404, 405, 422, 401 e 429.
- Login com o hash PBKDF2 do legado, com re-hash para Argon2id.
- Mensagem igual para utilizador inexistente e para palavra-passe errada.
- Nome de utilizador comparado de forma exacta.
- Limite de tentativas de login.
- Expiração por inactividade aos 15 minutos.
- Revogação de tokens quando o utilizador é desactivado.
- `X-Empresa-Id` obrigatório: sem acesso, empresa inactiva, acesso a todas as empresas.
- Isolamento entre empresas na leitura e na escrita.
- Auditoria automática, imutável e sem segredos.
- Partições anuais, com movimentação a partir da DEFAULT.
- Fuso horário.
- Semântica das permissões: superadmin, `all`, v2, acentos e portal automático.

## Decisões do utilizador (2026-09-29, após a Fase 1)
1. **Ecrãs vazios do legado** (Encomendas Clientes, Activos "Cadastro"): **corrigir** no sistema novo — ADR-014.
2. **Rotinas destrutivas escondidas**: **não portar**; substituir por relatórios de validação — ADR-015.
3. **Descontabilizar**: **estorno com rasto** em vez de apagar linhas — ADR-016.
4. **Regras salariais implícitas**: corrigir só o que está errado, mantendo as isenções que existem. A isenção de 30 000 Kz passa a depender da marcação `irt = conditional_30k` do infotipo e não do nome — ADR-017.
