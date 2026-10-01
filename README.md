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

Regenerar o esquema (migrations + `app/Models/Base/*` + `database/legado/esquema.json`) após alterar o glossário ou `esquema_extra.mjs`:

```bash
node --max-old-space-size=4096 ferramentas/gerador/gerar_esquema.mjs ../wstb_payroll_backup_2026-09-22.json
docker compose exec app vendor/bin/pint && docker compose exec app php artisan migrate:fresh && docker compose exec app php artisan test
```

`app/Models/<Model>.php` nunca é sobrescrito (código de negócio); `app/Models/Base/<Model>Base.php` é sempre regenerado.

## Migração do backup legado

```bash
# simulação completa (valida tudo e desfaz no fim)
docker compose exec app php artisan erp:migrar-backup-legado /dados/legado/wstb_payroll_backup_2026-09-22.json --simular
# migração real (COMMIT); --substituir --force apaga os dados de negócio existentes antes
docker compose exec app php artisan erp:migrar-backup-legado /dados/legado/wstb_payroll_backup_2026-09-22.json
```

O relatório fica em `backend/storage/app/private/migracao/` e em `execucoes_migracao.relatorio`. O detalhe de cada correcção está em `ocorrencias_migracao` e as linhas rejeitadas em `quarentena_migracao`.

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
| POST | `/api/orcamento/verificar` | Simulação do controlo orçamental de um documento |
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
| GET/POST/PUT/DELETE | `/api/contabilidade/plano-contas[/{id}]` | Plano de contas (cache Redis) |
| GET/POST | `/api/contabilidade/diarios` | Diários |
| GET/POST | `/api/contabilidade/lancamentos` | Linhas de lançamentos (filtros) / novo lançamento equilibrado |
| GET | `/api/contabilidade/lancamentos/{id}` | Lançamento completo a que a linha pertence |
| POST | `/api/contabilidade/lancamentos/{id}/estornar` | Estorno com rasto (`motivo`) |
| GET | `/api/contabilidade/relatorios/balancete` | `data_inicio`, `data_fim`, `nivel`, `prefixo`, `excluir_estornos`, `so_com_saldo` |
| GET | `/api/contabilidade/relatorios/razao` | `codigo_conta`, `data_inicio`, `data_fim`, `terceiro_id` |
| GET | `/api/contabilidade/relatorios/desequilibrios` | Lançamentos com Σ D ≠ Σ C |

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
