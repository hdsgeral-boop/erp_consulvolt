# Plano de Fases

| Fase | Âmbito | Estado |
| :---: | :--- | :--- |
| **1** | Infraestrutura Docker, Laravel 12, multi-empresa, autenticação, envelope da API, auditoria, **dicionário DE/PARA das 154 tabelas**, inventário funcional do legado | ✅ Concluída (2026-09-29) |
| **2** | Migrations das 154 tabelas a partir do `mapa_de_para.json` (FKs, CHECKs, índices); estrutura das 29 tabelas sem dados, derivada do código JS; models Eloquent | ✅ Concluída (2026-09-30) |
| **3** | `php artisan erp:migrar-backup-legado` — ETL por streaming, pela ordem das dependências; regras de integridade; `ocorrencias_migracao` / `quarentena_migracao`; recalibração das sequences; relatório de validação (contagens, FKs, D−C) | ✅ Concluída (2026-09-29) |
| **4** ⏳ | Services e endpoints por módulo (Sistema, Contabilidade, Terceiros, Logística, Vendas/AGT, Compras, RH/Salários, Tesouraria, POS, Activos, Projectos, Orçamento, A&D, CRM), cache e locks Redis, filas; catálogo de permissões | Planeada |
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

## Fase 2 — Entregáveis e verificação
- **Gerador de esquema** (`ferramentas/gerador/gerar_esquema.mjs`, ADR-018) a partir de:
  - o dicionário DE/PARA;
  - `docs/dicionario/campos_codigo_legado.json`, com os campos extraídos do código JS. Cobre as 29 tabelas sem dados e 108 campos que o backup não tem, por exemplo `fe_*` (AGT) nas vendas e `he_*` (horas extra) nas empresas;
  - as regras de `esquema_extra.mjs`.
- **Esquema:**
  - 156 tabelas: as 154 da matriz, 4 tabelas pivô, `ocorrencias_migracao` e `quarentena_migracao`. As 4 da Fase 1 não são geradas.
  - **2 371 colunas**, **479 FKs** (DEFERRABLE, com RESTRICT ou CASCADE) e **26 chaves únicas**, todas verificadas contra os dados reais.
  - **36 CHECKs**: domínios das enumerações, D/C, valor ≥ 0 e polimórficos resolvidos.
  - Índices em todas as FKs e índices compostos para as consultas frequentes.
- **Models:** 152 models, cada um com uma base regenerável e uma classe de negócio. Todos têm casts, relações belongsTo/hasMany, isolamento por empresa e auditoria.
- **Testes:** 45 a passar (180 verificações). O `EsquemaContratoTest` compara a base real com o contrato.
- **Correcções:** os testes estavam a apagar a base principal; ficou resolvido e protegido (ADR-019).

## Fase 3 — Entregáveis e verificação
- **Comando** `php artisan erp:migrar-backup-legado <ficheiro> [--simular] [--substituir --force]` (ADR-023), implementado em `app/Services/Migracao/`:
  - `LeitorBackupDexie`: streaming;
  - `ConversorTipos`: tipos, datas "AAAA-MM", DD-MM-AAAA, NaN e arredondamento;
  - `RegistoOcorrencias`;
  - `ServicoMigracaoLegado`.
- **Migração real do backup de 2026-09-22 gravada:**
  - 14 empresas e 44 400 lançamentos;
  - 106 221 registos de auditoria e 4 utilizadores com perfis e ligações;
  - 31 linhas em quarentena, 0 órfãos e 0 divergências de contagem.
- **Equilíbrio D−C por empresa:** as empresas 5 (+200 000,00), 8 (−521 899,98) e 10 (−0,01) estão desequilibradas **no próprio legado**. As empresas 1 (−0,03), 3, 8 e 22 têm um efeito de arredondamento de ≤ 3 cêntimos (ADR-022). Tudo aparece discriminado no relatório.
- **Testes:** 50 a passar (233 verificações). O `MigracaoLegadoTest` cobre, com um backup sintético, cada regra de integridade, o login com a palavra-passe do legado após a migração, a simulação e a recusa de ficheiros inválidos ou de destino com dados.
- **Verificação ponta a ponta pela API:** com os dados migrados, lista as empresas e consulta a auditoria com `X-Empresa-Id`.

## Fase 4 — em curso (módulo a módulo)
| Módulo | Estado | Endpoints | Notas |
| :--- | :--- | :--- | :--- |
| Núcleo transversal | ✅ | — | Catálogo de permissões do legado e conversão de perfis (ADR-024); numeração segura (ADR-026); lock de exercício encerrado |
| **Contabilidade** | ✅ | plano-contas (CRUD, cache Redis), diarios, lancamentos (listar, detalhe, criar, **estornar**), relatorios/balancete, relatorios/razao, relatorios/desequilibrios | Partidas dobradas em decimal exacto; estorno com rasto (ADR-016); chave do lançamento (ADR-025) |
| **Sistema › Validações de dados** | ✅ | sistema/validacoes, sistema/validacoes/{codigo} | 12 validações só de leitura que substituem as rotinas destrutivas (ADR-015) |
| **Terceiros e Produtos** | ✅ | terceiros (CRUD por papel CLIENTE/FORNECEDOR), logistica/produtos (CRUD, bloquear, catálogo em cache), logistica/categorias-produtos | Paridade saveCustomer/saveSupplier/saveProduct; stock só por movimentos (ADR-028) |
| **Vendas / Facturação AGT (parte 1)** | ✅ | vendas/documentos (emitir FT/FR/NC/OR/PF/NE, converter, anular, contabilizar, descontabilizar), vendas/recibos (emitir, anular, contabilizar, descontabilizar), vendas/configuracao/{contas,series} | Séries AGT sem colisões, cálculo exacto, selagem, NC abate a factura, estorno com rasto (ADR-029) |
| Vendas / Facturação AGT (parte 2) | ⏳ | envio AGT, QR, assinatura SAF-T, multi-moeda; guias GR/GD com Logística | |
| Compras | ⏳ | | |
| Tesouraria | ⏳ | | |
| RH / Salários | ⏳ | | Motor salarial com não-regressão contra as folhas validadas (ADR-017) |
| Logística, POS, Activos, Projectos, Orçamento, A&D, CRM | ⏳ | | |

Os testes são agora 67 (403 verificações). Com os dados reais, os endpoints da Contabilidade respondem em 0,2–0,7 s.

## Decisões do utilizador (2026-09-29, após a Fase 1)
1. **Ecrãs vazios do legado** (Encomendas Clientes, Activos "Cadastro"): **corrigir** no sistema novo — ADR-014.
2. **Rotinas destrutivas escondidas**: **não portar**; substituir por relatórios de validação — ADR-015.
3. **Descontabilizar**: **estorno com rasto** em vez de apagar linhas — ADR-016.
4. **Regras salariais implícitas**: corrigir só o que está errado, mantendo as isenções que existem. A isenção de 30 000 Kz passa a depender da marcação `irt = conditional_30k` do infotipo e não do nome — ADR-017.
