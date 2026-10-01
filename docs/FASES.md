# Plano de Fases

| Fase | Âmbito | Estado |
| :---: | :--- | :--- |
| **1** | Infraestrutura Docker, Laravel 12, multi-empresa, autenticação, envelope da API, auditoria, **dicionário DE/PARA das 154 tabelas**, inventário funcional do legado | ✅ Concluída (2026-09-29) |
| **2** | Migrations das 154 tabelas a partir do `mapa_de_para.json` (FKs, CHECKs, índices); estrutura das 29 tabelas sem dados, derivada do código JS; models Eloquent | ✅ Concluída (2026-09-30) |
| **3** | `php artisan erp:migrar-backup-legado` — ETL por streaming, pela ordem das dependências; regras de integridade; `ocorrencias_migracao` / `quarentena_migracao`; recalibração das sequences; relatório de validação (contagens, FKs, D−C) | ✅ Concluída (2026-09-29) |
| **4** ✅ | Services e endpoints por módulo (Sistema, Contabilidade, Terceiros, Logística, Vendas/AGT, Compras, RH/Salários, Tesouraria, POS, Activos, Projectos, Orçamento, A&D, CRM), cache e locks Redis, filas; catálogo de permissões | Planeada |
| **5** | Frontend React + TypeScript com o layout do legado (sidebar, top header, DataTables, modais, impressões A4), módulo a módulo, com a matriz de paridade | Em curso (Ant Design + TypeScript; base + Vendas piloto concluídos, ADR-061) |
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
| **Vendas / Facturação AGT (parte 2)** | ✅ | vendas/faturacao-eletronica (configuração, ligação, resumo, enviar, consultar), documentos/{id}/{revalidar,pedido-assinado,qr}, configuracao/series (CRUD + solicitar-agt), vendas/saft | Envio à AGT no servidor (JWS RS256), Hash SAF-T na emissão, SAF-T(AO) correcto, QR, multi-moeda (ADR-030) |
| Vendas: guias GR/GD e stock | ⏳ | com o módulo Logística (movimentos de inventário) | |
| **Compras (parte 1)** | ✅ | compras/pedidos (+decidir, comparacao), deliberacao/escaloes, propostas (+propor, adjudicar), encomendas, rececoes (+validar, reverter), faturas (+contabilizar, descontabilizar), configuracao/contas | Adjudicação única e atómica, ligação linha a linha, stock com custo médio, conta transitória, estorno (ADR-031) |
| **Compras (parte 2)** | ✅ | compras/contratos (+encomendas, marcos, fatura do marco, cancelar), compras/encomendas-clientes (+pedido) | Encomendas do mesmo fornecedor e num só contrato, marcos com estado pela factura, expiração automática, pedido consolidado (ADR-035); controlo orçamental → módulo Orçamento |
| **Tesouraria (parte 1)** | ✅ | tesouraria/documentos (+integrar, desintegrar, anular), tesouraria/pendentes, tesouraria/meios-pagamento | Ligação explícita às facturas, sem pagar duas vezes, estorno bloqueado por reconciliação (ADR-032) |
| **Tesouraria (parte 2)** | ✅ | tesouraria/extrato (+importar), reconciliacao (+sugestoes, mapa, anular), caixa/sessoes (+movimentos, fechar, contabilizar), conferencias (+finalizar, assinar, reabrir), configuracao/contas | Importação sem duplicados, sugestões indexadas, compensações libertadas no estorno, caixa por conta, contas de sobras/quebras correctas (ADR-033) |
| **Tesouraria (parte 3)** | ✅ | multi-moeda nos documentos de tesouraria e pendentes (saldo em moeda) | Valor histórico, diferenças de câmbio pelas contas configuradas, moeda nos lançamentos de vendas/compras (ADR-034) |
| Compras | ⏳ | | |
| Tesouraria | ⏳ | | |
| **RH / Salários (parte 1a)** | ✅ | rh/salarios/periodos (+lancamentos, importar-contratos, encerrar, validar, reabrir, contabilizar, descontabilizar, recibos), verificacao-legado | Motor bcmath LEGADO/ATUAL, fotografia imutável ao encerrar, contabilização agregada e estorno; 38/44 folhas conferem com o diário (ADR-017, ADR-036) |
| **RH / Salários (parte 1b)** | ✅ | rh/colaboradores (+coordenada-bancaria), rh/coordenadas-bancarias, rh/contratos (+terminar), rh/infotipos, rh/tipos-organizacao, rh/bancos, rh/mapeamentos-contabeis, rh/salarios/periodos/{id}/ordem-pagamento, /cartas, rh/salarios/cartas (+pagamento) | Eliminação só sem utilizações (FKs reais), IBAN com dígitos de controlo, histórico de contratos sem sobreposição, contas de movimento no mapeamento, cartas de pagamento e pagamento pela tesouraria (ADR-037) |
| **RH (parte 2a)** | ✅ | rh/assiduidade (configuracao, registos +importar, meses/{mes} +detectar-faltas, fechar, reabrir, fechos, tipos-ausencia, ausencias +justificar, decidir, cancelar), rh/salarios/periodos/{id}/importar-efectividade | Calendário único, apuramento igual ao legado nos 2 fechos reais, só activos e dentro do contrato, recálculo ao lançar com retirada de lançamentos obsoletos, RH regista/justifica ausências (ADR-038) |
| **RH (parte 2b)** | ✅ | rh/ferias (+estado), rh/produtividade/itens, rh/produtividade/periodos (+fechar, reabrir, registos), rh/salarios/periodos/{id}/importar-produtividade | Férias pelo calendário da empresa e sem sobreposições; mínimo/máximo da produtividade no total; relançar retira obsoletos; valores reais iguais ao legado (ADR-039) |
| **RH (parte 3a)** | ✅ | rh/estrutura (+unidades, postos, afectacao, chefia), rh/cargos, rh/portal (resumo, meus-pedidos, recibos, pedidos +cancelar, decidir, proposta, emitir, aprovacoes, ligacoes, modelos) | Sem ciclos na chefia, afectação em massa preserva o gestor, decisões atómicas com segregação chefia/RH, numeração de documentos atómica, recibos da fotografia (ADR-040) |
| **RH (parte 3b)** | ✅ | rh/avaliacao (itens, avaliacoes +reabrir, conhecimento, contestar, parecer, decidir-contestacao, resultado-360; ciclos +abrir, fechar, confirmar-comunicado, bonificacoes +calcular, aprovar, lancar, anular; feedbacks; 360/tarefas, 360/respostas; autoavaliacao; ascendente) | Fórmulas iguais ao legado, ninguém se avalia, resultados anónimos só após o prazo, bónus sem duplicar no processamento (ADR-041) |
| **Logística (parte 1)** | ✅ | logistica/armazens, stock, movimentos, produtos/{id}/extracto, transferencias, ajustes, configuracao/contas, inventarios (+contagem, concluir-contagem, revisao, voltar-contagem, aprovar, reabrir, anular) | Motor de stock único com sentido/valor/documento, custo médio real, transferências, armazém congelado durante o inventário, regularização contabilizada e reaberta por estorno, stock migrado reconciliado (ADR-042) |
| **Logística (parte 2)** | ✅ | vendas/documentos GR e GD (+converter NE → GR, GR → FT/GD; NC com devolucao_mercadoria), logistica/guias-saida (+contabilizar, descontabilizar, anular) | FT/FR/GR baixam e GD/NC de devolução repõem ao custo, CMV permanente no lançamento do documento, guias de consumo com numeração sem repetições (ADR-043) |
| **Orçamento (parte 1)** | ✅ | orcamento/rubricas (+base), orcamento/orcamentos (+valores, submeter, aprovar, devolver, nova-versao, repartir, contributos, consolidar, controlo) | Base histórica com crescimento/inflação, segregação submeter/aprovar, consolidação da última versão, realizado do Diário sem apuramentos (ADR-044) |
| **Orçamento (parte 2)** | ✅ | orcamento/verificar, pedidos-excesso (+decidir), alertas, monitor; controlo na adjudicação, factura directa, pagamento e lançamento manual | Compromissos pela parte por facturar, sem dotação não bloqueia, falha fechada, alerta de bloqueio registado fora da transacção desfeita (ADR-045) |
| **Orçamento (parte 3)** | ✅ | orcamento/previsoes (+revisao, publicar), orcamentos/{id}/cenarios (+padrao), cenarios (+gerar-versao), orcamentos/{id}/desvios/{rubrica} | Previsões a 12 meses com revisões e fecho estimado, cenários que geram versão, classificação dos desvios (ADR-046) |
| **POS (parte 1)** | ✅ | pos/terminais (+copiar-meios, ativo, sessoes), pos/definicoes, pos/sessoes (+relatorio-x, fechar, vendas, contabilizar, descontabilizar, deliberacao) | Terminais e meios, sessões com X/Z, vendas FR com preço com IVA, desconto e troco, integração com CMV, desvios automáticos e deliberados (ADR-047) |
| **POS (parte 2)** | ✅ | pos/prestacao, pos/sessoes/{id}/prestacao (+transferencias), pos/liquidacoes (+anular), pos/relatorios (+servicos) | Prestação de contas: numerário → folha de caixa, TPA com comissão e transferências → tesouraria; relatórios com filtros (ADR-048) |
| **POS (parte 3a)** | ✅ | pos/lavandaria (definições, peças, serviços, ordens, orçamentos, materiais, pagamentos, entrega, reclamações, relatório) | Lavandaria/alfaiataria com FR ou FT+recibo, Consumidor Final, armazenagem, integração na sessão, reclamações com segregação (ADR-049) |
| **POS (parte 3b)** | ✅ | pos/hotelaria (quartos, estadias, checkin, checkout), pos/armazem (stock, vendas, picking) | Hotelaria com regras do terminal e rateio dos pagamentos; venda ao balcão com CMV; picking NE → GR (ADR-050) |
| **Activos** | ✅ | ativos/categorias, bens (+importar, edicao-massa, transferencias), afetacoes, manutencoes, aquisicoes-pendentes, abates, amortizacoes (+calcular, quota, integrar, reabrir, verificacao), mapas, fluxo | Amortizações em rascunho → diário AM, reabertura por estorno, abates com mais/menos-valia; 1 435/1 448 quotas migradas reproduzidas (ADR-051) |
| **Projectos** | ✅ | projetos (carteira, ficha/estado, WBS, Kanban, equipa, organigrama, orçamento, aditamentos, horas, equipamentos, requisições, revisões/autos, facturação, extracto, resumo, fluxo, rentabilidade) | Razão analítico único sem duplicados, autos e facturação sem repetições, imputação salarial ligada ao processamento (ADR-052) |
| **Acréscimos e diferimentos** | ✅ | acrescimos/definicoes, itens (+regularizar, terminar), quotas, proposta (+contabilizar), lancamentos (+descontabilizar), reconciliacao, recolha | Repartição dias/meses, proposta mensal, um lançamento por linha, estorno sem buracos; 8 períodos migrados reproduzidos (ADR-053) |
| **CRM** | ✅ | crm/configuracao, funis (+quadro), modelos-email, sequencias, contas, oportunidades (+etapa, conversao, documentos), atividades, agenda, emails, campanhas, previsao, indicadores | Funis com tarefas e sequências, ficha 360º, prospect → cliente, ligação a Vendas, emails registados sem envio (ADR-054) |
| **Contabilidade (parte 2)** | ✅ | contabilidade/relatorios (balanco, demonstracao-resultados, fluxo-caixa, extrato, evolucao, iva, reconciliacao-agt, movimentos-sem-nota), compensacoes, relatorio-contas, tabelas, reciclagem, lancamentos/importar, saldos-historicos | Motor único das demonstrações, igual ao legado ao cêntimo; Relatório e Contas com fotografia (ADR-055) |
| **Encerramento e rotinas** | ✅ | contabilidade/encerramento (passos 1-5, validacoes, encerrar, reabrir, cancelar-apuramento), contabilidade/rotinas | Apuramento no período 13 por lançamentos/estornos, validações corrigidas, Imposto de Selo, capitalização (ADR-056) |
| **Consolidação** | ✅ | consolidacao/grupos (+executar, mapa), execucoes | Eliminações intragrupo e conversão cambial; reproduz a execução do legado (ADR-057) |
| **Sistema (administração)** | ✅ | sistema/utilizadores, perfis, gestao-empresas, moedas, cambios (+bai), unidades-negocio, plano-contas/substituir, manutencao, copias, migracao | Sem escalada de privilégios, substituir conta sem reescrever o Diário, manutenção com aprovação dupla, cópias por empresa (ADR-058) |
| **Painéis, Análise Dinâmica e BI** | ✅ | gestao/inicio, gestao/paineis (+{modulo}, comparacao), gestao/cubo (conjuntos, valores, consultar), gestao/bi | 13 painéis + holding, cubo seguro por lista branca, KPIs iguais ao balancete (ADR-059) |
| **Relatórios de gestão e Fluxo de Processos** | ✅ | gestao/relatorios (periodos, resumo, todos, {modulo}), gestao/fluxos ({fluxo}, processos, processos/{chave}) | 9 módulos A×B iguais ao legado, 14 fluxos; apuramento separado dos salários (ADR-060) |

Os testes são agora 67 (403 verificações). Com os dados reais, os endpoints da Contabilidade respondem em 0,2–0,7 s.

## Decisões do utilizador (2026-09-29, após a Fase 1)
1. **Ecrãs vazios do legado** (Encomendas Clientes, Activos "Cadastro"): **corrigir** no sistema novo — ADR-014.
2. **Rotinas destrutivas escondidas**: **não portar**; substituir por relatórios de validação — ADR-015.
3. **Descontabilizar**: **estorno com rasto** em vez de apagar linhas — ADR-016.
4. **Regras salariais implícitas**: corrigir só o que está errado, mantendo as isenções que existem. A isenção de 30 000 Kz passa a depender da marcação `irt = conditional_30k` do infotipo e não do nome — ADR-017.

### Fase 5 — Frontend React (em curso)

| Parte | Estado | Conteúdo |
| :--- | :---: | :--- |
| **Base + piloto** | ✅ | Login, escolha de empresa, menu por permissões (`GET /api/sistema/menu`), layout, tabela paginada genérica, erros; Vendas › Facturação (listagem, emissão, detalhe e acções) — ADR-061 |
| **Ronda 1** | ✅ | 65 ecrãs: Vendas, Compras, Armazém/Inventário, Contabilidade, Tesouraria, RH e Salários; 90 testes Vitest — ADR-062 |
| **Ronda 2** | ✅ | 54 ecrãs: POS, Activos, Projectos, Acréscimos, Orçamento, CRM, Estrutura, Configurações, painéis/relatórios/fluxos/BI; os 116 ecrãs do catálogo registados; 207 testes Vitest — ADR-063 |
| **Afinação** | ✅ | Paginação uniforme, nomes nas respostas, filtros e endpoints em falta, portal com avaliação do próprio, importação .xlsx no servidor, descargas seguras — ADR-064 |
| **Componentes comuns** | ✅ | `src/utilitarios/{decimal,csv}`, `src/componentes/Accoes` (useAccao, ModalMotivo), `src/componentes/graficos` (gráficos SVG) — usados por todos os módulos; os específicos de domínio (pagamentos POS, seletores, Gantt, grelha mensal) ficam nos módulos |
