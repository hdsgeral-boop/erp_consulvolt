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
sh ferramentas/testes/correr_backend.sh           # suite PHPUnit COMPLETA em ambiente local Windows (ver nota)
sh ferramentas/testes/correr_backend.sh --filter=Owasp   # argumentos extra seguem para o PHPUnit
```

> **Nota (Docker Desktop em Windows):** na pasta montada do Windows, o `DirectoryIterator` do PHP só devolve parte
> das entradas de `backend/tests/Feature` (48 de 90), embora `ls`, `glob()`, `scandir()` e o Finder do Symfony vejam todas.
> O PHPUnit descobre os testes iterando a pasta, por isso `php artisan test` localmente corre só ~44 testes e parece verde.
> `ferramentas/testes/correr_backend.sh` passa a lista explícita de ficheiros (`tests/Feature/*.php tests/Unit/*.php`, expandida pela shell)
> e deve ser usado sempre que se queira a suite completa localmente. O CI e a produção (sistema de ficheiros Linux) não são
> afectados. Código que precise de listar pastas usa `scandir()`/`glob()` (como `RegrasCacheTest` e `ChavesAgt`). ADR-069.

Regenerar o dicionário de dados a partir do backup (Node 18+):

```bash
node --max-old-space-size=4096 ferramentas/levantamento/levantar_colunas.mjs ../wstb_payroll_backup_2026-09-22.json docs/dicionario/levantamento_colunas_legado.json
node --max-old-space-size=4096 ferramentas/levantamento/gerar_dicionario.mjs ../wstb_payroll_backup_2026-09-22.json docs/dicionario/levantamento_colunas_legado.json docs/dicionario
```

Regenerar o esquema (migrations + `app/Models/Base/*` + `database/legado/esquema.json`) após alterar o glossário ou `esquema_extra.mjs`:

```bash
node --max-old-space-size=4096 ferramentas/gerador/gerar_esquema.mjs ../wstb_payroll_backup_2026-09-22.json
docker compose exec app vendor/bin/pint && docker compose exec app php artisan migrate:fresh && docker compose exec app php artisan test
```

`app/Models/<Model>.php` nunca é sobrescrito (código de negócio); `app/Models/Base/<Model>Base.php` é sempre regenerado.

## Frontend (React — Fase 5)

React 18 + TypeScript + Ant Design 5 (Vite), em `frontend/`. Em produção o nginx serve `frontend/dist` em http://127.0.0.1:8080 e encaminha `/api` para o Laravel.

```bash
cd frontend
npm install                  # npm 11: aprovar o script do esbuild — npm approve-scripts esbuild
npm run dev                  # http://127.0.0.1:5173 (proxy de /api para :8080)
npm run build                # verifica tipos e gera frontend/dist (servido pelo nginx)
npm run testes               # Vitest
```

Estrutura: `src/api` (cliente HTTP: token Bearer, `X-Empresa-Id`, envelope e erros), `src/sessao` (entrar, empresa activa, permissões, inactividade), `src/layout` (menu de `GET /api/sistema/menu`), `src/componentes` (tabela paginada da API, cabeçalho, `Accoes` — acções com mensagem e motivo —, `graficos` SVG), `src/utilitarios` (formatação, erros, `decimal` em cêntimos, `csv`), `src/modulos/<módulo>` (ecrãs registados em `src/modulos/registo.tsx` pelo id do ecrã do catálogo de permissões; rota `/m/{modulo}/{ecra}`).

## Migração do backup legado

```bash
# simulação completa (valida tudo e desfaz no fim)
docker compose exec app php artisan erp:migrar-backup-legado /dados/legado/wstb_payroll_backup_2026-09-22.json --simular
# migração real (COMMIT); --substituir --force apaga os dados de negócio existentes antes
docker compose exec app php artisan erp:migrar-backup-legado /dados/legado/wstb_payroll_backup_2026-09-22.json
```

Depois do COMMIT a migração aplica os passos pós-carga (ADR-068): tarefas novas do catálogo atribuídas aos perfis que já faziam a acção e contas de diferenças de câmbio 6621/7621; se falharem, repetir com `php artisan erp:migracao:pos-carga` (idempotente).

O relatório fica em `backend/storage/app/private/migracao/` e em `execucoes_migracao.relatorio`. O detalhe de cada correcção está em `ocorrencias_migracao` e as linhas rejeitadas em `quarentena_migracao`.

## Testes ponta-a-ponta (Playwright)

Ambiente isolado (base `erp_consulvolt_e2e` com dados fictícios, recriada antes de cada execução; nunca toca na base de desenvolvimento):

```powershell
docker compose -f docker-compose.yml -f docker-compose.e2e.yml up -d app_e2e web_e2e   # http://127.0.0.1:8081
cd frontend; npm install; npx playwright install chromium; npm run build
npm run e2e                                   # recria a base E2E e corre tudo
npx playwright test vendas                    # um ficheiro
npm run e2e:relatorio                         # relatório HTML da última execução
```

Utilizadores fictícios (`e2e.admin`, `e2e.vendas`, `e2e.pos`, `e2e.rh`, `e2e.aprovador`) e detalhes em [frontend/e2e/README.md](frontend/e2e/README.md).

## Impressão, PDF e responsividade (ADR-066)

- **Imprimir/PDF:** motor comum em `frontend/src/componentes/impressao` (ver o `README.md` da pasta). Cabeçalho com o logótipo e o nome da empresa (`GET /api/sistema/identidade`); folha e orientação automáticas (A4 retrato → A4 paisagem → A3 paisagem → escala); sem barras de deslocação; o PDF é o «Guardar como PDF» do navegador.
- **Responsivo:** base em `frontend/src/componentes/responsivo` (ver o `README.md` da pasta); menu em gaveta abaixo de 992 px; `e2e/responsivo.e2e.ts` confirma os 116 ecrãs a 375 px.

## Produção e integração contínua

Runbook completo: [docs/PRODUCAO.md](docs/PRODUCAO.md) (instalação, HTTPS, deploy, rollback, cópias e restauro, monitorização, chaves AGT e o plano da migração definitiva).

```bash
cp .env.prod.example .env.prod && chmod 600 .env.prod      # preencher segredos (nunca no Git)
sh ferramentas/operacao/prod.sh build                      # imagens app / web / copias (docker/php/Dockerfile.prod)
sh ferramentas/operacao/prod.sh up -d --wait postgres redis
sh ferramentas/operacao/prod.sh run --rm --no-deps app php artisan migrate --force
sh ferramentas/operacao/prod.sh up -d --wait
sh ferramentas/operacao/deploy.sh                          # novas versões: build, cópia, migrate, recriação, verificação
sh ferramentas/operacao/prod.sh run --rm --no-deps copias /operacao/backup.sh                       # cópia imediata
sh ferramentas/operacao/prod.sh run --rm --no-deps copias /operacao/restaurar.sh /copias/diarias/<cópia> <base_nova>
```

| Produção | Desenvolvimento |
| :--- | :--- |
| Código, `vendor` (sem dev) e `frontend/dist` dentro das imagens; OPcache sem revalidação | Código montado; OPcache revalida a cada 2 s |
| PHP-FPM e nginx sem root; FS só de leitura; tini | root; FS gravável |
| PostgreSQL/Redis sem portas publicadas; web só em 127.0.0.1 atrás do reverse proxy HTTPS | portas em 127.0.0.1 |
| Logs JSON (stderr) com rotação; serviço `copias` agendado | logs diários em ficheiro |

CI (GitHub Actions, `.github/workflows/ci.yml`) em cada push/PR para `main`: backend (PHP 8.3, PostgreSQL 16, Redis 7, `composer audit`, Pint, PHPUnit), frontend (Node 22, `npm audit --omit=dev --audit-level=high`, tsc, Vitest, build) e build das imagens de produção (sem publicação); *actions* fixadas por SHA de commit (ADR-069). Usa só dados fictícios.

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
| GET | `/api/sistema/validacoes`, `/api/sistema/validacoes/{codigo}` | Validações de dados (só leitura, ADR-015) |
| GET/POST/PUT/DELETE | `/api/terceiros[/{id}]` | Clientes/fornecedores (`papel`: CLIENTE ou FORNECEDOR) |
| GET/POST/PUT/DELETE | `/api/logistica/produtos[/{id}]` | Produtos e serviços; `POST /{id}/bloquear`; `GET /catalogo` (cache) |
| GET/POST | `/api/vendas/documentos[/{id}]` | FT, FR, NC, OR, PF, NE; `POST /{id}/converter`, `/anular`, `/contabilizar`, `/descontabilizar` (estorno) |
| GET/POST | `/api/vendas/recibos[/{id}]` | Recibos de clientes; `POST /{id}/anular`, `/contabilizar`, `/descontabilizar` |
| GET/PUT | `/api/vendas/configuracao/contas` · GET `/api/vendas/configuracao/series` | Contas de vendas e séries de numeração |
| POST/PUT/DELETE | `/api/vendas/configuracao/series[/{id}]` · POST `/{id}/solicitar-agt` | Séries (regras do legado) e pedido do código à AGT |
| GET/PUT | `/api/vendas/faturacao-eletronica/configuracao` | Regime, estabelecimentos, isenção por omissão, envio automático, exigir séries AGT |
| GET | `/api/vendas/faturacao-eletronica/{ligacao,resumo}` | Estado da ligação à AGT (sem segredos) e contagem por estado de envio |
| POST | `/api/vendas/faturacao-eletronica/{enviar,consultar}` | Envio dos pendentes e consulta dos estados |
| POST/GET | `/api/vendas/documentos/{id}/revalidar` · `/pedido-assinado` · `/qr?formato=png\|svg` | Corrigir e reenviar; pré-visualizar o pedido assinado; QR code |
| GET | `/api/vendas/saft?inicio=&fim=` | Ficheiro SAF-T(AO) de facturação (XML) |
| GET/POST | `/api/compras/pedidos[/{id}]` · POST `/{id}/decidir` · `/{id}/anular` · GET `/{id}/comparacao` | Pedidos de compra, deliberação e comparação de propostas |
| GET/PUT | `/api/compras/deliberacao/escaloes` | Escalões de aprovação |
| GET/POST | `/api/compras/propostas[/{id}]` · POST `/{id}/propor` · `/cancelar-proposta` · `/adjudicar` · `/anular` | Propostas e adjudicação (gera a encomenda) |
| GET | `/api/compras/encomendas[/{id}]` · POST `/{id}/anular` · `/{id}/rececoes` · `/{id}/faturas` | Encomendas; registar recepção; facturar |
| GET/POST | `/api/compras/rececoes[/{id}]` · POST `/{id}/validar` · `/reverter-validacao` · `/anular` | Recepções (validação no armazém: stock e contabilidade) |
| GET/POST | `/api/compras/faturas[/{id}]` · POST `/{id}/contabilizar` · `/descontabilizar` · `/anular` | Facturas de fornecedor (directas: só serviços/imobilizado) |
| GET/PUT | `/api/compras/configuracao/contas` | Contas de compras por omissão |
| GET/POST/PUT | `/api/compras/contratos[/{id}]` · POST `/{id}/encomendas` · `/{id}/marcos` · `/{id}/marcos/{m}/fatura` · `/{id}/cancelar` | Contratos de fornecedores e marcos |
| GET/POST | `/api/compras/encomendas-clientes` · POST `/encomendas-clientes/pedido` | Encomendas de clientes → pedido de compra consolidado |
| GET/POST/PUT | `/api/tesouraria/documentos[/{id}]` · POST `/{id}/integrar` · `/desintegrar` · `/anular` | Pagamentos e recebimentos (integração no diário BD/CX, estorno) |
| GET | `/api/tesouraria/pendentes` | Documentos em aberto de clientes e fornecedores (ligados à venda/factura) |
| GET/POST/PUT/DELETE | `/api/tesouraria/meios-pagamento[/{id}]` | Meios de pagamento (IBAN validado, um predefinido) |
| GET/POST | `/api/tesouraria/extrato` · POST `/extrato/importar` · `/extrato/{id}/anular` | Extractos bancários (XLSX/XLS/CSV, sem duplicados) |
| GET/POST | `/api/tesouraria/reconciliacao` · GET `/sugestoes` · `/mapa` · POST `/{codigo}/anular` | Reconciliação bancária |
| GET/POST/DELETE | `/api/tesouraria/caixa/sessoes[/{id}]` · POST `/{id}/movimentos` · `/fechar` · `/contabilizar` · `/descontabilizar` | Folha de caixa |
| GET/POST/PUT | `/api/tesouraria/conferencias[/{id}]` · POST `/{id}/finalizar` · `/assinar` · `/reabrir` | Conferência de caixa |
| GET/PUT | `/api/tesouraria/configuracao/contas` | Contas de sobras/quebras e diferenças de câmbio |
| GET/POST | `/api/rh/salarios/periodos` · GET `/{id}` | Processamentos salariais (resultados e totais; fotografia quando encerrado) |
| GET/POST/DELETE | `/api/rh/salarios/periodos/{id}/lancamentos[/{lancamento}]` · POST `/{id}/importar-contratos` | Lançamentos do mês (só em ABERTO) |
| POST | `/api/rh/salarios/periodos/{id}/encerrar` · `/validar` · `/reabrir` · `/contabilizar` · `/descontabilizar` | Ciclo do processamento (estorno ao descontabilizar) |
| GET | `/api/rh/salarios/periodos/{id}/recibos/{colaborador}` · `/api/rh/salarios/verificacao-legado` | Recibo de vencimento; conferência das folhas migradas com o diário |
| GET | `/api/rh/salarios/periodos/{id}/ordem-pagamento` | Ordem de pagamento bancária (banco, IBAN, líquido) |
| POST/GET/DELETE | `/api/rh/salarios/periodos/{id}/cartas` · `/api/rh/salarios/cartas[/{carta}]` · POST `/cartas/{carta}/pagamento` | Cartas de pagamento e pagamento pela tesouraria |
| GET/POST/PUT/DELETE | `/api/rh/colaboradores[/{id}]` · PUT/DELETE `/{id}/coordenada-bancaria` · GET `/api/rh/coordenadas-bancarias` | Colaboradores, ficha e IBAN |
| GET/POST/PUT/DELETE | `/api/rh/contratos[/{id}]` · POST `/{id}/terminar` | Contratos de trabalho (histórico sem sobreposição) |
| GET/POST/PUT/DELETE | `/api/rh/infotipos` · `/api/rh/tipos-organizacao` · `/api/rh/bancos` `[/{id}]` | Rubricas, tipos de organização e bancos |
| GET/PUT | `/api/rh/mapeamentos-contabeis` | Mapeamento contabilístico dos salários |
| GET/PUT | `/api/rh/assiduidade/configuracao` | Dias úteis, feriados, tolerâncias e compensação |
| GET/POST/DELETE | `/api/rh/assiduidade/registos[/{id}]` · POST `/registos/importar` | Efectividade (manual ou ficheiro do relógio) |
| GET/POST | `/api/rh/assiduidade/meses/{AAAA-MM}` · POST `/detectar-faltas` · `/fechar` · `/reabrir` · GET `/fechos` | Apuramento e fecho mensal |
| GET/POST | `/api/rh/assiduidade/ausencias` · POST `/{id}/justificar` · `/decidir` · `/cancelar` · GET `/tipos-ausencia` | Ausências (Lei 12/23) |
| POST | `/api/rh/salarios/periodos/{id}/importar-efectividade` | Horas extra e de falta do mês fechado → processamento |
| GET/POST/PUT/DELETE | `/api/rh/ferias[/{id}]` · POST `/{id}/estado` | Plano de férias, direito e saldo |
| GET/POST/PUT/DELETE | `/api/rh/produtividade/itens[/{id}]` | Itens do subsídio de produtividade |
| GET/POST/PUT | `/api/rh/produtividade/periodos[/{id}]` · POST `/fechar` · `/reabrir` · `/registos[/{registo}]` | Períodos e registos de produtividade |
| POST | `/api/rh/salarios/periodos/{id}/importar-produtividade` | Produtividade do período fechado → processamento |
| GET/POST/PUT/DELETE | `/api/rh/estrutura` · `/estrutura/unidades[/{id}]` · `/estrutura/postos[/{id}]` · POST `/estrutura/afectacao` · GET `/estrutura/chefia/{colaborador}` | Estrutura orgânica |
| GET/POST/PUT/DELETE | `/api/rh/cargos[/{id}]` | Cargos e funções |
| GET/POST | `/api/rh/portal/resumo` · `/meus-pedidos` · `/recibos` · POST `/pedidos` · `/pedidos/{id}/cancelar` | Portal do colaborador (o próprio) |
| GET/POST | `/api/rh/portal/aprovacoes` · `/pedidos` · POST `/pedidos/{id}/decidir` · GET `/pedidos/{id}/proposta` · POST `/pedidos/{id}/emitir` · `/ligacoes` | Aprovações (chefia e RH) |
| GET/PUT/DELETE | `/api/rh/portal/modelos[/{codigo}]` | Modelos de documentos do RH |
| GET/POST/PUT/DELETE | `/api/rh/avaliacao/itens[/{id}]` | Itens de avaliação (critérios e objectivos) |
| GET/POST/DELETE | `/api/rh/avaliacao/avaliacoes[/{id}]` · POST `/{id}/reabrir` · `/conhecimento` · `/contestar` · `/parecer` · `/decidir-contestacao` · GET `/{id}/resultado-360` | Avaliação de desempenho |
| GET/POST/PUT | `/api/rh/avaliacao/ciclos[/{id}]` · POST `/abrir` · `/fechar` · `/confirmar-comunicado` · `/bonificacoes/calcular` · `/aprovar` · `/lancar` | Ciclos 360º e bonificação |
| POST | `/api/rh/avaliacao/bonificacoes/{id}/anular` · `/feedbacks` · `/feedbacks/{id}/confirmar` | Anulação de bónus e acompanhamento |
| GET/POST/PUT | `/api/rh/avaliacao/360/tarefas` · `/360/respostas` · `/autoavaliacao` · `/ascendente[/{colaborador}]` | O próprio: 360º, autoavaliação e avaliação da chefia |
| GET/POST/PUT/DELETE | `/api/logistica/categorias-produtos[/{id}]` | Categorias de produtos |
| GET/POST/PUT/DELETE | `/api/logistica/armazens[/{id}]` | Armazéns (predefinido explícito) |
| GET | `/api/logistica/stock` · `/movimentos` · `/produtos/{id}/extracto` | Stock por armazém, valorização, movimentos e extracto do artigo |
| POST | `/api/logistica/transferencias` · `/ajustes` | Transferências entre armazéns e ajustes manuais |
| GET/PUT | `/api/logistica/configuracao/contas` | Contas da logística (CMV, sobras, quebras) |
| GET/POST | `/api/logistica/inventarios[/{id}]` · POST `/contagem` · `/concluir-contagem` · `/revisao` · `/voltar-contagem` · `/aprovar` · `/reabrir` · `/anular` | Inventário físico |
| GET/POST | `/api/logistica/guias-saida[/{id}]` · POST `/contabilizar` · `/descontabilizar` · `/anular` | Guias de consumo interno |
| GET/POST/PUT/DELETE | `/api/orcamento/rubricas[/{id}]` · POST `/rubricas/base` | Rubricas orçamentais (exploração e tesouraria) |
| GET/POST/PUT/DELETE | `/api/orcamento/orcamentos[/{id}]` · PUT `/valores` · POST `/submeter` · `/aprovar` · `/devolver` · `/nova-versao` · `/repartir` · `/contributos` · `/consolidar` | Orçamentos e hierarquia |
| GET | `/api/orcamento/orcamentos/{id}/controlo` | Orçado × realizado (mês, acumulado, ano) |
| POST | `/api/orcamento/verificar` | Simulação do controlo orçamental de um documento (exige uma das permissões de `pedidos-excesso` — ADR-069) |
| GET/POST | `/api/orcamento/pedidos-excesso` · POST `/{id}/decidir` | Pedidos de aprovação de excesso |
| GET | `/api/orcamento/alertas` · `/api/orcamento/monitor` | Registo de alertas e consumo orçamental |
| GET/POST/PUT/DELETE | `/api/orcamento/previsoes[/{id}]` · POST `/revisao` · `/publicar` | Previsões deslizantes (12 meses) |
| GET/POST/PUT/DELETE | `/api/orcamento/orcamentos/{id}/cenarios` · `/cenarios/padrao` · `/api/orcamento/cenarios[/{id}]` · POST `/gerar-versao` | Cenários what-if |
| GET | `/api/orcamento/orcamentos/{id}/desvios/{rubrica}` | Análise do desvio de uma rubrica |
| GET/POST/PUT/DELETE | `/api/pos/terminais[/{id}]` · POST `/copiar-meios` · `/ativo` | Terminais POS e meios de pagamento |
| GET/PUT | `/api/pos/definicoes` | Contas de sobras/quebras/operador, tolerância e diário |
| POST | `/api/pos/terminais/{id}/sessoes` · GET `/api/pos/sessoes[/{id}]` · `/relatorio-x` · POST `/fechar` | Abertura, relatório X e fecho Z |
| POST | `/api/pos/sessoes/{id}/vendas` | Venda POS (factura-recibo) |
| POST | `/api/pos/sessoes/{id}/contabilizar` · `/descontabilizar` · `/deliberacao` · `/deliberacao/anular` | Integração e desvios de caixa |
| GET | `/api/pos/prestacao` · `/api/pos/sessoes/{id}/prestacao` | Prestação de contas por regularizar (itens NUM/TPA/TRF) |
| POST | `/api/pos/sessoes/{id}/prestacao` · `/prestacao/transferencias` | Liquidar numerário (folha de caixa), TPA com comissão e transferências (tesouraria) |
| GET/POST | `/api/pos/liquidacoes[/{id}]` · POST `/{id}/anular` | Histórico e anulação de liquidações |
| GET | `/api/pos/relatorios` · `/api/pos/relatorios/servicos` | Relatórios do POS (período, terminal, operador) e bloco «POS e Serviços» |
| GET/PUT/POST | `/api/pos/lavandaria/definicoes` · `/pecas` · `/servicos` | Tabelas e definições da lavandaria |
| GET/POST | `/api/pos/lavandaria/ordens[/{id}]` · `/sessoes/{id}/ordens` · `/orcamentos` · `/ordens/estado` · `/ordens/atribuir` · `/ordens/{id}/anular` | Ordens de serviço |
| POST | `/api/pos/lavandaria/sessoes/{s}/ordens/{o}/pagamentos` · `/faturar` · `/entregar` · `/pagamentos/{id}/anular` | Recebimentos, facturação e entrega |
| GET/POST | `/api/pos/lavandaria/reclamacoes` · `/{id}/decidir` · `/{id}/pagar` · GET `/relatorio` | Danos e relatório |
| GET/PUT | `/api/pos/hotelaria/quartos[/{produto}]` | Mapa de quartos e tarifas |
| GET/PUT | `/api/pos/hotelaria/estadias[/{id}]` · PUT `/consumos` · POST `/anular` | Estadias |
| POST | `/api/pos/hotelaria/sessoes/{id}/checkin` · `/checkout` · `/checkout/simular` | Check-in e check-out com factura-recibo POS |
| GET/POST | `/api/pos/armazem/vendas[/{id}]` · GET `/api/pos/armazem/stock` | Venda ao balcão (guia de saída com CMV) |
| GET/POST | `/api/pos/armazem/picking[/{id}]` · POST `/expedir` | Picking e expedição de encomendas (NE → GR) |
| GET/POST/PUT/DELETE | `/api/ativos/categorias[/{id}]` · `/api/ativos/bens[/{id}]` · POST `/importar`, `/edicao-massa`, `/eliminar` | Categorias e cadastro de activos |
| GET/POST/PUT/DELETE | `/api/ativos/afetacoes[/{id}]` · `/api/ativos/manutencoes[/{id}]` · POST `/api/ativos/bens/{id}/transferencias` | Afectações, manutenções e transferências de centro de custo |
| GET/POST | `/api/ativos/aquisicoes-pendentes` · `/{linha}/inventariar` · `/{linha}/ligar` · `/api/ativos/abates` (+`/simulacao`, `/{id}/anular`) | Inventariação e abates/vendas |
| GET/POST/PUT | `/api/ativos/amortizacoes` (+`/pendentes`, `/pre-visualizacao`, `/verificacao`, `/calcular`, `/quota`, `/integrar`, `/reabrir`) · `/api/ativos/mapas/*` | Amortizações e mapas |
| GET/POST/PUT/DELETE | `/api/projetos[/{id}]` (+`/estado`, `/resumo`, `/wbs`, `/kanban`, `/equipa`, `/organigrama`, `/orcamento`, `/aditamentos`, `/horas`, `/requisicoes`, `/revisoes`) · `/gantt`, `/extracto`, `/rentabilidade` | Projectos |
| GET/POST/PUT/DELETE | `/api/acrescimos/definicoes` · `/itens[/{id}]` (+`/regularizar`, `/terminar`) · `/quotas` · `/proposta` (+`/contabilizar`) · `/lancamentos` · `/reconciliacao` · `/recolha` | Acréscimos e diferimentos |
| GET/POST/PUT/DELETE | `/api/crm/configuracao` · `/funis` · `/modelos-email` · `/sequencias` · `/contas` · `/oportunidades` (+`/etapa`, `/conversao`, `/documentos`) · `/atividades` · `/agenda` · `/emails` · `/campanhas` · `/previsao` · `/indicadores` | CRM |
| GET | `/api/contabilidade/relatorios/balanco` · `/demonstracao-resultados` · `/fluxo-caixa` · `/extrato` · `/evolucao` · `/iva` · `/movimentos-sem-nota` · POST `/iva/reconciliacao-agt` | Demonstrações financeiras e mapas |
| GET/POST/DELETE | `/api/contabilidade/compensacoes` · `/api/contabilidade/relatorio-contas/{ano}` (+`/concluir`, `/reabrir`) | Compensações e Relatório e Contas |
| GET/POST/PUT/DELETE | `/api/contabilidade/tabelas/{diarios,notas-demonstracao,notas-fluxo-caixa,centros-custo}` · `/reciclagem` · POST `/lancamentos/importar` · `/saldos-historicos/{ano}` | Tabelas auxiliares, reciclagem e importações |
| GET/POST | `/api/contabilidade/encerramento[/{ano}]` (+`/passos/{n}`, `/validacoes`, `/encerrar`, `/reabrir`, `/cancelar-apuramento`, `/mapa`) | Encerramento do exercício |
| GET/POST | `/api/contabilidade/rotinas/*` (capitalização, compensação, transferência, imposto-selo, actualização em massa, histórico, anular) | Rotinas contabilísticas |
| GET/POST/PUT/DELETE | `/api/consolidacao/grupos[/{id}]` (+`/executar`, `/mapa`) · `/execucoes/{id}` | Consolidação |
| GET/POST/PUT/DELETE | `/api/sistema/utilizadores` · `/perfis` · `/gestao-empresas` · `/empresas` · `/moedas` · `/cambios` (+`/bai`, `/importar`) · `/unidades-negocio` | Administração |
| POST/GET | `/api/sistema/plano-contas/substituir[/simular]` · `/manutencao/*` · `/copias/{exportar,importar,clonar}` · `/migracao/*` · GET `/api/sistema/logotipo-login` (público) | Substituir conta, manutenção de dados, cópias e migração |
| GET/POST | `/api/gestao/inicio` · `/api/gestao/paineis[/{modulo}]` · `/paineis/comparacao` · `/api/gestao/cubo/{conjuntos,valores,consultar}` · `/api/gestao/bi[/consultar]` | Página inicial, painéis, análise dinâmica e BI |
| GET | `/api/gestao/relatorios[/{periodos,resumo,todos,{modulo}}]` · `/api/gestao/fluxos[/{fluxo}[/processos[/{chave}]]]` | Relatórios de gestão e fluxo de processos |
| GET | `/api/sistema/menu` | Menu (módulos e ecrãs visíveis) e permissões efectivas na empresa activa — frontend |
| GET/POST | `/api/tesouraria/disponibilidades` · `/extrato-conta` · POST `/documentos/integrar` | Mapas de tesouraria (`teso_gestao_mapas_view`) e integração em lote |
| POST | `/api/logistica/stock/recalcular-valorizacoes` | Recálculo do custo médio (simulação por omissão; `aplicar` grava, sem tocar no contabilizado) |
| GET | `/api/vendas/relatorios/resumo` · `/api/vendas/saft/validar` | Indicadores de vendas no servidor; validação do SAF-T antes do ficheiro |
| GET | `/api/sistema/moedas/funcional` | Moeda funcional da empresa activa |
| POST | `/api/sistema/migracao/importar/{entidade}` · `/api/sistema/cambios/importar` | Aceitam também o `.xlsx` do modelo (multipart, campo `ficheiro`) |
| GET | `/api/rh/portal/{ausencias,dependentes,avaliacoes,utilizadores}` · `/api/rh/estrutura/mapa` | Portal do colaborador; mapa de pessoal (massa salarial só com `est_ver_salarios`) |
| GET | `/api/projetos/{id}/equipamentos` · `/api/pos/lavandaria/colaboradores` | Equipamentos do projecto; colaboradores para atribuir ordens |
| GET/POST/PUT/DELETE | `/api/contabilidade/plano-contas[/{id}]` | Plano de contas (cache Redis) |
| GET/POST | `/api/contabilidade/diarios` | Diários |
| GET/POST | `/api/contabilidade/lancamentos` | Linhas de lançamentos (filtros) / novo lançamento equilibrado |
| GET | `/api/contabilidade/lancamentos/{id}` | Lançamento completo a que a linha pertence |
| POST | `/api/contabilidade/lancamentos/{id}/estornar` | Estorno com rasto (`motivo`) |
| GET | `/api/contabilidade/relatorios/balancete` | `data_inicio`, `data_fim`, `nivel`, `prefixo`, `excluir_estornos`, `so_com_saldo` |
| GET | `/api/contabilidade/relatorios/razao` | `codigo_conta`, `data_inicio`, `data_fim`, `terceiro_id` |
| GET | `/api/contabilidade/relatorios/desequilibrios` | Lançamentos com Σ D ≠ Σ C |

**Ronda 2 das lacunas (ADR-068)** — principais endpoints novos:

| Método | Endpoint | Descrição |
| :--- | :--- | :--- |
| POST | `/api/contabilidade/lancamentos/classificacao` · `/lancamentos/{id}/transferir` | Notas/classificação em massa (A-05); transferir para outra empresa por estorno + criação (M-09) |
| GET/POST | `/api/contabilidade/encerramento/{ano}/plano` · POST `/plano/corrigir` | Diagnóstico e correcção assistida do plano antes do encerramento (decisão 20) |
| GET/PUT/POST/DELETE | `/api/contabilidade/assistente` · `/configuracao` · POST `/propor` · `/regras[/{id}]` | Assistente IA (Claude) — só propõe; regras internas (decisão 26, M-03) |
| GET/POST/PUT/DELETE | `/api/pos/terminais/{t}/mesas` · `/api/pos/mesas/{m}[/conta]` · POST `/api/pos/sessoes/{s}/mesas/{m}/cobrar` | Mesas do restaurante e contas por mesa no servidor (decisão 14, M-15) |
| POST/GET | `/api/pos/lavandaria/importar` · `/api/pos/lavandaria/ordens/{o}/documentos/{v}` | Importação de tabelas da lavandaria e documentos da ordem (M-16) |
| POST/PUT/DELETE | `/api/rh/salarios/periodos/{id}/copiar` · `/lancamentos/lote` · `/importar-excel` · DELETE `/periodos/{id}` | Cálculo: copiar mês anterior, lote, importação Excel, eliminar período aberto (A-08) |
| GET | `/api/rh/salarios/periodos/{id}/recibos/{colaborador}/pdf` · `/recibos-zip` | Recibo em PDF (2 vias, extenso, IBAN) e ZIP com um PDF por colaborador (A-10) |
| GET/PUT/DELETE | `/api/rh/configuracao` · `/api/rh/tabela-irt` · GET `/api/rh/assiduidade/feriados-nacionais` | Configuração de RH (segregação, férias LGT), tabela de IRT configurável, feriados de Angola (decisões 1, 5, 6, 7) |
| GET/POST | `/api/rh/importacoes/{modelos/{entidade},colaboradores,contratos}` · POST `/api/rh/contratos/massa` | Importação de colaboradores e contratos; contratos em massa (A-09) |
| GET/PUT/POST | `/api/sistema/cambios-manuais/tolerancia` · POST `/validar` | Tolerância do câmbio manual e pré-verificação (decisão 9) |
| GET/PUT/POST | `/api/sistema/cambios/bai/automatico[/obter]` · POST `/pendentes/{validar,rejeitar}` | Câmbios do BAI automáticos, pendentes de validação |
| GET/PUT/DELETE | `/api/sistema/preferencias/{tipo}[/{nome}]` · GET `/api/sistema/operacoes/{id}` · POST `/cancelar` | Preferências do utilizador no servidor (M-19); operações em segundo plano (M-05) |
| GET/POST | `/api/bi/tokens` · POST `/{id}/revogar` · GET `/api/bi/odata[/$metadata\|/{conjunto}]` | Power BI: tokens de leitura por empresa e feed OData v4 (decisão 25, M-02) |
| PUT | `/api/compras/propostas/{id}/iva` · `/api/compras/encomendas/{id}/iva` | Corrigir o IVA antes de adjudicar/facturar (M-17) |
| GET/POST/PUT/DELETE | `/api/tesouraria/documentos/{modelo-importacao,importar,anular,desintegrar}` · `/reconciliacao/{rascunhos,historico,{codigo}/detalhe}` · PUT `/extrato/{id}` | Importação de documentos (A-12), lotes, rascunhos e histórico da reconciliação (M-08) |
| GET/POST | `/api/logistica/importacao/{produtos,categorias}[/modelo]` | Importação de produtos e categorias (M-07) |
| POST | `/api/vendas/documentos/{faturar-guias,contabilizar,descontabilizar}` · `/api/vendas/recibos/{contabilizar,descontabilizar}` · `/recibos/{id}/alocar` | Factura de várias guias e adiantamentos (M-18); contabilização em lote (M-06) |

**Simulações e pré-visualizações (ADR-069)** — nada é gravado:

| Método | Endpoint | Descrição |
| :--- | :--- | :--- |
| GET | `/api/vendas/documentos/{venda}/contabilizacao/pre-visualizacao` | Lançamento que a contabilização do documento de venda vai gerar (mesma montagem e validações; `vendas_fat_contabilizar`) |
| GET | `/api/compras/faturas/{id}/contabilizacao/pre-visualizacao` | Lançamento que a contabilização da factura de fornecedor vai gerar (`compras_fact_contabilizar`) |

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

## Facturação electrónica AGT (ADR-030)

1. Preencha as variáveis `AGT_*` em `backend/.env` (ver `backend/.env.example`), com `AGT_DRIVER=direto`.
2. Coloque as chaves PEM (RSA de 2048 bits ou mais) em `.segredos/agt/` (ou na pasta indicada em `AGT_PASTA_SEGREDOS`). Esta pasta nunca entra no Git:
   - `produtor_privada.pem` — chave do produtor de software; a pública submete-se no Portal do Parceiro;
   - `contribuintes/<NIF>.pem` — chave de cada empresa, descarregada no Portal do Contribuinte;
   - `saft_privada.pem` — assinatura SAF-T dos documentos (Hash), depois da certificação.
3. Execute `docker compose up -d`. O serviço `scheduler` corre `erp:agt:ciclo` de 2 em 2 minutos para as empresas com envio automático; a fila `agt` é processada pelo `worker`.
4. Em **Vendas › Facturação electrónica**, active o regime e confirme o estado em `GET /api/vendas/faturacao-eletronica/ligacao`.

Para continuar a usar o serviço intermédio do legado (`servico_agt`), defina `AGT_DRIVER=intermedio`, `AGT_INTERMEDIO_URL` e `AGT_INTERMEDIO_TOKEN`.
