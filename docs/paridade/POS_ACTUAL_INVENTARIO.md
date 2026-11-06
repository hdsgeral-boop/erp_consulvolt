# Inventário do POS que JÁ EXISTE no sistema novo

> Âmbito: análise só de leitura, feita em 2026-11-06 sobre o ramo `paridade-bd-front` (commit `3d0006b`). Serve de lado «actual» da matriz de lacunas contra o legado (`v_boss`). Cada funcionalidade tem um identificador `N-xxx` (secção 7), a ligar a uma `F-xxx` do inventário do legado. Referências `ficheiro:linha` são relativas à raiz do repositório (`backend/…`, `frontend/…`, `docs/…`). Não corri a suite de testes: o que se diz dos testes vem da leitura do código deles. A parte funcional é a que está no código; os docs (ADR, ANALISE_PARIDADE) estão em vários pontos desactualizados e são apontados na secção 5.

## 0. Resumo executivo

| Indicador | Valor |
|---|---|
| Sub-módulos do POS | 11 (Terminais e definições · Frente de caixa · Restaurante/mesas · Integração e desvios · Prestação de contas · Relatórios · Configuração de impressão · Lavandaria e alfaiataria · Hotelaria · POS de armazém · Catálogo por serviço) |
| Rotas HTTP do POS (`php artisan route:list`, `Api\POS\*`) | **97**, em 12 controladores (1 391 linhas) |
| Serviços PHP do POS | 28 ficheiros em `backend/app/Services/POS` (5 520 linhas, com `Hotelaria/` e `Lavandaria/`) |
| Tabelas próprias do POS | **15** (10 vêm do legado, 5 são novas: `mesas_pos`, `contas_mesa_pos`, `senhas_pos_armazem` e os pivôs `vendas_estadias_hotel`, `pedidos_lavandaria_faturas`) + colunas POS em `vendas` (cerca de 14), `produtos` (12) e `lancamentos_contabeis` (1) |
| Ecrãs registados no frontend | **10** (`pos`, `pos_relatorios`, `pos_terminais`, `pos_config_print`, `pos_integracao`, `pos_desvios`, `pos_prestacao`, `pos_lavandaria`, `pos_hotelaria` + `pos_armazem` no módulo Stock) |
| Ficheiros do frontend POS | 76 em `frontend/src/modulos/pos` (13 764 linhas, 13 ficheiros de teste) + 7 em `frontend/src/modulos/stock/posArmazem`/`PosArmazem.tsx` (1 450 linhas) |
| Testes | PHPUnit: 39 métodos em 11 ficheiros POS + 3 em `R3VendasTest` (catálogo por serviço) + 3 em `CorrecoesPosMigracaoTest`; Vitest: 91 casos em 15 ficheiros; Playwright: 2 cenários (`frontend/e2e/pos.e2e.ts`) |
| Permissões (`backend/resources/permissoes/catalogo.json:1228-1405, 1746-1775`) | 10 ecrãs (cada um com `<ecrã>_view`) e 24 tarefas (`pos_venda`, `pos_desconto`, `pos_fecho`, `pos_terminais_gerir`, `pos_print_config`, `pos_integrar`, `pos_descontabilizar`, `pos_desvio_deliberar`, `pos_prestar`, `pos_prestacao_anular`, `lav_ordens`, `lav_receber`, `lav_anular`, `lav_tabelas`, `lav_dano_decidir`, `lav_dano_pagar`, `hotel_estadias`, `hotel_checkout`, `hotel_anular`, `hotel_quartos`, `pos_armazem_vender`, `pos_armazem_picking`, `pos_armazem_expedir`, `pos_armazem_acerto`) |
| Validações de dados com origem POS | 10 (`ServicoValidacoesDados`) |
| Funcionalidades listadas | **N-001 a N-153** (secção 7) |

Em síntese: o POS está funcionalmente **portado em largura** (todos os fluxos principais do legado têm equivalente no servidor e no ecrã), com várias correcções deliberadas face ao legado (numeração, stock por armazém, CMV, transacções, segregação de funções). As lacunas que se mantêm são sobretudo: ausência de devolução/anulação/«reimpressão» de venda a partir do POS, ausência de movimentos de caixa avulsos (suprimento/sangria), fiscalidade impressa no talão (hash/QR/ATCUD), operação offline, e a dívida técnica listada na secção 8.

## 1. Backend

### 1.1 Mapa de ficheiros

| Camada | Ficheiros | Notas |
|---|---|---|
| Controladores (`backend/app/Http/Controllers/Api/POS`) | `POSController` (terminais, definições, sessões, vendas, integração, desvios; 244 l.), `FrentePOSController` (stock, sessões antigas, séries; 41 l.), `MesasPOSController` (116), `CatalogoServicosPOSController` (53), `PrestacaoContasController` (125), `ParidadeContasPOSController` (89), `HotelariaController` (171), `LavandariaController` (312), `DocumentosLavandariaController` (34), `ImportacaoLavandariaController` (31), `POSArmazemController` (86), `ParidadePOSArmazemController` (89) | Sem FormRequests nem API Resources dedicados: a validação é `$r->validate([...])` no controlador e as respostas são modelos/arrays via `RespostaApi`. Permissões por `$this->exigir(...)` em cada acção |
| Serviços (`backend/app/Services/POS`) | `ServicoTerminaisPOS` (269), `ServicoSessoesPOS` (202), `ServicoVendasPOS` (166), `ServicoContabilizacaoPOS` (289), `ServicoPrestacaoContasPOS` (383), `ServicoRelatoriosPOS` (185), `ServicoConfigPOS` (62), `ServicoCatalogoPOS` (145), `ServicoFrentePOS` (94), `ServicoMesasPOS` (251), `ServicoPOSArmazem` (169), `ServicoAcertoStockPOSArmazem` (127), `ServicoSenhasPOSArmazem` (105), `ServicoFontesLavandariaPOS` (182) | O motor de vendas (`Services/Vendas`) e o de stock/CMV (`Services/Logistica`) são reutilizados, não duplicados |
| Hotelaria (`Services/POS/Hotelaria`) | `RegrasHotel` (108), `ServicoEstadiasHotel` (272), `ServicoCheckoutHotel` (202) | |
| Lavandaria (`Services/POS/Lavandaria`) | `RegrasLavandaria` (224), `ServicoOrdensLavandaria` (609), `ServicoCaixaLavandaria` (403), `ServicoTabelasLavandaria` (183), `ServicoConfigLavandaria` (105), `ServicoReclamacoesLavandaria` (112), `ServicoRelatorioLavandaria` (120), `ServicoImportacaoTabelasLavandaria` (287) | Regras puras em `RegrasLavandaria`/`RegrasHotel` (sem I/O) |
| Migração (ETL) | `ServicoMigracaoPOS` (81), `ServicoMigracaoLavandaria` (137), `ServicoMigracaoHotelaria` (48), (o comando `erp:migracao:pos-carga` é «pós-carga», não do POS: repete permissões novas e contas 6621/7621) | Traduzem chaves JSON inglesas do legado para as portuguesas |
| Modelos | `TerminalPOS`, `SessaoPOS`, `ConfiguracaoPOS`, `LiquidacaoPOS`, `MesaPOS`, `ContaMesaPOS`, `SenhaPOSArmazem`, `EstadiaHotel` (com regras), `PedidoLavandaria`, `PagamentoLavandaria`, `PecaLavandaria`, `ReclamacaoLavandaria`, `ConfiguracaoLavandaria` (todos `extends …Base` gerado, com `Auditavel`, `PertenceEmpresa`; `TerminalPOS` com `SoftDeletes`) | `ServicoMesasPOS` não usa os modelos `MesaPOS`/`ContaMesaPOS`: acede por `DB::table` (ver 8) |
| Jobs / eventos / notificações | **Nenhum específico do POS.** Nenhum job (as vendas POS não se contabilizam sozinhas: `ServicoContabilizacaoVendas.php:106,210` recusa-o e remete para a integração da sessão) | Sem notificações (`Notificacoes`) emitidas pelo POS; sem eventos de domínio |
| Agendamentos | Nenhum específico | O aviso de sessões antigas é pull (frente de caixa e validação de dados) |
| Impressões | **Só no frontend** (HTML/CSS gerado no navegador, `comum/impressao.ts`, `comum/documentos.ts`, `lavandaria/impressoes.ts`) | O backend não gera PDF do POS; Documentos A4 passam pelo «motor comum de impressão» do frontend |
| Configurações | `configuracoes_pos` e `configuracoes_lavandaria` (por empresa); sem `config/*.php` próprio | |
| Gestão | `Services/Gestao/Fluxos/Avaliadores/FluxoPOS.php` (149) e `FluxoLavandaria.php` (255), `Services/Gestao/Relatorios/Modulos/ModuloServicos.php` | Fluxo de processos e relatório de gestão «POS e Serviços» |

### 1.2 Rotas (97)

Todas dentro do grupo autenticado com empresa activa (prefixo `/api`), registadas em `backend/routes/api.php:512-538` (terminais, definições, sessões), `routes/api/pos_prestacao.php`, `pos_lavandaria.php`, `pos_hotelaria.php` (hotelaria **e** POS de armazém), `paridade_pos_stock/{pos_frente,pos_contas,pos_armazem}.php`, `ronda2_g1.php:21-29` (mesas), `r3_vendas.php` (catálogo por serviço e pesquisa de clientes). Lista extraída de `php artisan route:list --json` e das chamadas `$this->exigir(...)` de cada acção (a segunda coluna de permissões é «qualquer uma destas»).

#### CatalogoServicosPOSController (3 rotas)

| Método | URI (`/api/…`) | Acção | Permissões aceites (qualquer uma) |
|---|---|---|---|
| GET | `pos/produtos/servicos` | `marcacoes` | vendas_produtos_view, vendas_produtos_gerir, pos_terminais_gerir |
| PUT | `pos/produtos/{produto}/servicos` | `definir` | vendas_produtos_gerir |
| GET | `pos/terminais/{terminal}/catalogo` | `catalogo` | pos_view, pos_venda, hotel_estadias, hotel_checkout |

#### DocumentosLavandariaController (1 rotas)

| Método | URI (`/api/…`) | Acção | Permissões aceites (qualquer uma) |
|---|---|---|---|
| GET | `pos/lavandaria/ordens/{ordem}/documentos/{venda}` | `__invoke` | pos_lavandaria_view, lav_ordens, lav_receber, lav_anular, lav_tabelas, lav_dano_decidir, lav_dano_pagar |

#### FrentePOSController (3 rotas)

| Método | URI (`/api/…`) | Acção | Permissões aceites (qualquer uma) |
|---|---|---|---|
| GET | `pos/frente/series` | `series` | pos_view, pos_venda, pos_terminais_view, pos_terminais_gerir |
| GET | `pos/frente/sessoes-antigas` | `sessoesAntigas` | pos_view, pos_venda, pos_terminais_view, pos_terminais_gerir |
| GET | `pos/frente/terminais/{terminal}/stock` | `stock` | pos_view, pos_venda |

#### HotelariaController (10 rotas)

| Método | URI (`/api/…`) | Acção | Permissões aceites (qualquer uma) |
|---|---|---|---|
| GET | `pos/hotelaria/estadias` | `lista` | pos_hotelaria_view, hotel_estadias, hotel_checkout, hotel_anular, hotel_quartos |
| GET | `pos/hotelaria/estadias/{estadia}` | `estadia` | pos_hotelaria_view, hotel_estadias, hotel_checkout, hotel_anular, hotel_quartos |
| PUT | `pos/hotelaria/estadias/{estadia}` | `alterar` | hotel_estadias |
| POST | `pos/hotelaria/estadias/{estadia}/anular` | `anular` | hotel_anular |
| PUT | `pos/hotelaria/estadias/{estadia}/consumos` | `consumos` | hotel_estadias, pos_venda, pos_desconto |
| GET | `pos/hotelaria/quartos` | `quartos` | pos_hotelaria_view, hotel_estadias, hotel_checkout, hotel_anular, hotel_quartos |
| PUT | `pos/hotelaria/quartos/{produto}` | `guardarQuarto` | hotel_quartos |
| POST | `pos/hotelaria/sessoes/{sessao}/checkin` | `checkin` | hotel_estadias |
| POST | `pos/hotelaria/sessoes/{sessao}/checkout` | `fazerCheckout` | hotel_checkout, pos_desconto |
| POST | `pos/hotelaria/sessoes/{sessao}/checkout/simular` | `simular` | hotel_checkout |

#### ImportacaoLavandariaController (1 rotas)

| Método | URI (`/api/…`) | Acção | Permissões aceites (qualquer uma) |
|---|---|---|---|
| POST | `pos/lavandaria/importar` | `__invoke` | lav_tabelas |

#### LavandariaController (28 rotas)

| Método | URI (`/api/…`) | Acção | Permissões aceites (qualquer uma) |
|---|---|---|---|
| GET | `pos/lavandaria/colaboradores` | `colaboradores` | pos_lavandaria_view, lav_ordens, lav_receber, lav_anular, lav_tabelas, lav_dano_decidir, lav_dano_pagar |
| GET | `pos/lavandaria/definicoes` | `definicoes` | pos_lavandaria_view, lav_ordens, lav_receber, lav_anular, lav_tabelas, lav_dano_decidir, lav_dano_pagar |
| PUT | `pos/lavandaria/definicoes` | `guardarDefinicoes` | lav_tabelas |
| POST | `pos/lavandaria/orcamentos` | `orcamentos` | lav_ordens |
| GET | `pos/lavandaria/ordens` | `ordens` | pos_lavandaria_view, lav_ordens, lav_receber, lav_anular, lav_tabelas, lav_dano_decidir, lav_dano_pagar |
| POST | `pos/lavandaria/ordens/atribuir` | `atribuir` | lav_ordens |
| POST | `pos/lavandaria/ordens/estado` | `alterarEstado` | lav_anular, lav_ordens |
| GET | `pos/lavandaria/ordens/{ordem}` | `ordem` | pos_lavandaria_view, lav_ordens, lav_receber, lav_anular, lav_tabelas, lav_dano_decidir, lav_dano_pagar |
| POST | `pos/lavandaria/ordens/{ordem}/anular` | `anular` | lav_anular |
| POST | `pos/lavandaria/ordens/{ordem}/entrega/simulacao` | `simularEntrega` | pos_lavandaria_view, lav_ordens, lav_receber, lav_anular, lav_tabelas, lav_dano_decidir, lav_dano_pagar |
| POST | `pos/lavandaria/ordens/{ordem}/linhas` | `accaoLinhas` | lav_ordens |
| POST | `pos/lavandaria/ordens/{ordem}/linhas/{linha}/materiais` | `material` | lav_ordens |
| POST | `pos/lavandaria/ordens/{ordem}/linhas/{linha}/reclamacoes` | `registarReclamacao` | lav_ordens |
| POST | `pos/lavandaria/pagamentos/{pagamento}/anular` | `anularRecibo` | lav_anular |
| GET | `pos/lavandaria/pecas` | `pecas` | pos_lavandaria_view, lav_ordens, lav_receber, lav_anular, lav_tabelas, lav_dano_decidir, lav_dano_pagar |
| POST | `pos/lavandaria/pecas` | `guardarPeca` | lav_tabelas |
| PUT | `pos/lavandaria/pecas/{peca}` | `guardarPeca` | lav_tabelas |
| GET | `pos/lavandaria/reclamacoes` | `reclamacoesLista` | pos_lavandaria_view, lav_ordens, lav_receber, lav_anular, lav_tabelas, lav_dano_decidir, lav_dano_pagar |
| POST | `pos/lavandaria/reclamacoes/{reclamacao}/decidir` | `decidirReclamacao` | lav_dano_decidir |
| POST | `pos/lavandaria/reclamacoes/{reclamacao}/pagar` | `pagarReclamacao` | lav_dano_pagar |
| GET | `pos/lavandaria/relatorio` | `relatorioPeriodo` | pos_lavandaria_view, pos_relatorios_view, lav_ordens |
| GET | `pos/lavandaria/servicos` | `servicos` | pos_lavandaria_view, lav_ordens, lav_receber, lav_anular, lav_tabelas, lav_dano_decidir, lav_dano_pagar |
| POST | `pos/lavandaria/servicos` | `guardarServico` | lav_tabelas |
| PUT | `pos/lavandaria/servicos/{servico}` | `guardarServico` | lav_tabelas |
| POST | `pos/lavandaria/sessoes/{sessao}/ordens` | `registar` | lav_ordens |
| POST | `pos/lavandaria/sessoes/{sessao}/ordens/{ordem}/entregar` | `entregar` | lav_ordens |
| POST | `pos/lavandaria/sessoes/{sessao}/ordens/{ordem}/faturar` | `faturar` | lav_ordens |
| POST | `pos/lavandaria/sessoes/{sessao}/ordens/{ordem}/pagamentos` | `receber` | lav_receber |

#### MesasPOSController (7 rotas)

| Método | URI (`/api/…`) | Acção | Permissões aceites (qualquer uma) |
|---|---|---|---|
| DELETE | `pos/mesas/{mesa}` | `destroy` | pos_terminais_gerir |
| GET | `pos/mesas/{mesa}/conta` | `conta` | pos_venda |
| PUT | `pos/mesas/{mesa}/conta` | `gravarConta` | pos_venda |
| POST | `pos/sessoes/{sessao}/mesas/{mesa}/cobrar` | `cobrar` | pos_venda |
| GET | `pos/terminais/{terminal}/mesas` | `index` | pos_venda, pos_terminais_gerir, pos_terminais_view, pos_view |
| POST | `pos/terminais/{terminal}/mesas` | `store` | pos_terminais_gerir, pos_venda |
| PUT | `pos/terminais/{terminal}/mesas/{mesa}` | `update` | pos_terminais_gerir |

#### POSArmazemController (6 rotas)

| Método | URI (`/api/…`) | Acção | Permissões aceites (qualquer uma) |
|---|---|---|---|
| GET | `pos/armazem/picking` | `fila` | pos_armazem_view, pos_armazem_vender, pos_armazem_picking, pos_armazem_expedir |
| GET | `pos/armazem/picking/{encomenda}` | `lista` | pos_armazem_picking, pos_armazem_expedir |
| POST | `pos/armazem/picking/{encomenda}/expedir` | `expedir` | pos_armazem_expedir |
| GET | `pos/armazem/vendas` | `vendas` | pos_armazem_view, pos_armazem_vender, pos_armazem_picking, pos_armazem_expedir |
| POST | `pos/armazem/vendas` | `vender` | pos_armazem_vender |
| GET | `pos/armazem/vendas/{guia}` | `venda` | pos_armazem_view, pos_armazem_vender, pos_armazem_picking, pos_armazem_expedir |

#### POSController (21 rotas)

| Método | URI (`/api/…`) | Acção | Permissões aceites (qualquer uma) |
|---|---|---|---|
| GET | `pos/definicoes` | `definicoes` | pos_view, pos_terminais_view, pos_terminais_gerir, pos_desvios_view, pos_desvio_deliberar |
| PUT | `pos/definicoes` | `guardarDefinicoes` | pos_terminais_gerir |
| GET | `pos/sessoes` | `sessoes` | pos_view, pos_relatorios_view, pos_integracao_view, pos_desvios_view, pos_prestacao_view, pos_venda |
| GET | `pos/sessoes/{sessao}` | `sessao` | pos_view, pos_relatorios_view, pos_integracao_view, pos_desvios_view, pos_prestacao_view, pos_venda |
| POST | `pos/sessoes/{sessao}/contabilizar` | `contabilizar` | pos_integrar |
| POST | `pos/sessoes/{sessao}/deliberacao` | `deliberar` | pos_desvio_deliberar |
| POST | `pos/sessoes/{sessao}/deliberacao/anular` | `anularDeliberacao` | pos_desvio_deliberar |
| POST | `pos/sessoes/{sessao}/descontabilizar` | `descontabilizar` | pos_descontabilizar |
| POST | `pos/sessoes/{sessao}/fechar` | `fecharSessao` | pos_fecho |
| GET | `pos/sessoes/{sessao}/relatorio-x` | `relatorioX` | pos_venda, pos_fecho |
| POST | `pos/sessoes/{sessao}/vendas` | `vender` | pos_venda, pos_desconto |
| GET | `pos/terminais` | `terminais` | pos_view, pos_terminais_view, pos_terminais_gerir, pos_venda |
| POST | `pos/terminais` | `guardarTerminal` | pos_terminais_gerir |
| DELETE | `pos/terminais/{terminal}` | `eliminarTerminal` | pos_terminais_gerir |
| GET | `pos/terminais/{terminal}` | `terminal` | pos_view, pos_terminais_view, pos_terminais_gerir, pos_venda |
| PUT | `pos/terminais/{terminal}` | `guardarTerminal` | pos_terminais_gerir |
| POST | `pos/terminais/{terminal}/ativo` | `ativarTerminal` | pos_terminais_gerir |
| POST | `pos/terminais/{terminal}/copiar-meios` | `copiarMeios` | pos_terminais_gerir |
| GET | `pos/terminais/{terminal}/servicos-lav` | `servicosLavTerminal` | pos_terminais_view, pos_terminais_gerir, pos_venda, lav_ordens, lav_tabelas |
| PUT | `pos/terminais/{terminal}/servicos-lav` | `guardarServicosLavTerminal` | pos_terminais_gerir |
| POST | `pos/terminais/{terminal}/sessoes` | `abrirSessao` | pos_venda |

#### ParidadeContasPOSController (2 rotas)

| Método | URI (`/api/…`) | Acção | Permissões aceites (qualquer uma) |
|---|---|---|---|
| POST | `pos/integracao/contabilizar` | `contabilizar` | pos_integrar |
| POST | `pos/integracao/pre-visualizacao` | `previsualizar` | pos_integrar |

#### ParidadePOSArmazemController (7 rotas)

| Método | URI (`/api/…`) | Acção | Permissões aceites (qualquer uma) |
|---|---|---|---|
| GET | `pos/armazem/acerto-stock` | `diferencas` | pos_armazem_view, pos_armazem_vender, pos_armazem_picking, pos_armazem_expedir, pos_armazem_acerto |
| POST | `pos/armazem/acerto-stock` | `acertar` | pos_armazem_acerto |
| GET | `pos/armazem/artigos` | `artigos` | pos_armazem_view, pos_armazem_vender, pos_armazem_picking, pos_armazem_expedir, pos_armazem_acerto |
| GET | `pos/armazem/senhas` | `senhas` | pos_armazem_view, pos_armazem_vender, pos_armazem_picking, pos_armazem_expedir, pos_armazem_acerto |
| POST | `pos/armazem/senhas` | `gerarSenha` | pos_armazem_vender, pos_armazem_picking, pos_armazem_expedir |
| POST | `pos/armazem/senhas/chamar` | `chamarSenha` | pos_armazem_vender, pos_armazem_picking, pos_armazem_expedir |
| POST | `pos/armazem/senhas/{senha}/repetir` | `repetirSenha` | pos_armazem_vender, pos_armazem_picking, pos_armazem_expedir |

#### PrestacaoContasController (8 rotas)

| Método | URI (`/api/…`) | Acção | Permissões aceites (qualquer uma) |
|---|---|---|---|
| GET | `pos/liquidacoes` | `liquidacoes` | pos_prestacao_view, pos_prestacao, pos_prestar, pos_prestacao_anular |
| GET | `pos/liquidacoes/{liquidacao}` | `liquidacao` | pos_prestacao_view, pos_prestacao, pos_prestar, pos_prestacao_anular |
| POST | `pos/liquidacoes/{liquidacao}/anular` | `anular` | pos_prestacao_anular |
| GET | `pos/prestacao` | `pendentes` | pos_prestacao_view, pos_prestacao, pos_prestar, pos_prestacao_anular |
| GET | `pos/relatorios` | `relatorios` | pos_relatorios_view, pos_relatorios |
| GET | `pos/sessoes/{sessao}/prestacao` | `itens` | pos_prestacao_view, pos_prestacao, pos_prestar, pos_prestacao_anular |
| POST | `pos/sessoes/{sessao}/prestacao` | `registar` | pos_prestar |
| POST | `pos/sessoes/{sessao}/prestacao/transferencias` | `registarTransferencias` | pos_prestar |

### 1.3 Ligações com outros módulos

| Módulo | Como o POS se liga | Evidência |
|---|---|---|
| Vendas / Faturação / AGT | Cada venda POS é uma **FR** (factura-recibo) emitida por `ServicoDocumentosVenda::emitir` com o bloco `pos {origem_serie, percentagem_desconto, colunas}`: série própria por terminal (`FR T01AAAA`, criada automaticamente na 1.ª venda do ano), hash/selagem AGT, stock e CMV. O modo POS só aceita FR e FT em Kz (`POS_TIPO_INVALIDO`). A lavandaria emite também **FT** (conta corrente) na série do terminal | `ServicoVendasPOS.php:76-84`; `ServicoDocumentosVenda.php:95-135,565-572`; `ServicoFrentePOS.php:81-93` |
| Vendas (contabilização) | Uma venda POS não se (des)contabiliza individualmente: `VENDA_POS` | `ServicoContabilizacaoVendas.php:106,210` |
| Vendas (clientes, imagens) | Pesquisa de clientes e «tornar cliente» (`vendas/clientes/pesquisa`, `…/{terceiro}/papel-cliente`); imagens dos produtos (`GET /vendas/produtos/imagens`) | `routes/api/r3_vendas.php`; `frontend/src/modulos/pos/comum/frente.ts:36-50` |
| Stock / Logística | Stock validado e abatido no **armazém do terminal** (ou o predefinido); CMV; guias de saída `VENDA_BALCAO` e de consumo (materiais de alfaiataria); conversão NE→GR (picking); movimento `AJUSTE` (acerto) via `ServicoStock` | `ServicoVendasPOS.php:63,151-165`; `ServicoGuiasSaida.php:65-90`; `ServicoPOSArmazem.php:130-152`; `ServicoAcertoStockPOSArmazem.php:76-127` |
| Tesouraria / Folha de Caixa | A prestação de contas cria **movimentos REC/PAG na folha de caixa aberta** da conta de liquidação (45) e **documentos de tesouraria** RECEBIMENTO (TPA e transferências, conta 43) e PAGAMENTO (comissão não deduzida; indemnizações da lavandaria). A tesouraria recusa mexer em documentos/movimentos ligados a liquidações registadas (`MOVIMENTO_POS`/`DOCUMENTO_POS`). A Folha de Caixa em si **não** é do POS (módulo Tesouraria) | `ServicoPrestacaoContasPOS.php:249-331`; `ServicoReclamacoesLavandaria.php:87-112`; ADR-048 |
| Contabilidade | Lançamentos `tipo_origem = POS` / `POS_DESVIO` com `sessao_pos_id` no diário `GEPOS` (configurável); contas transitórias por meio; CMV D71/C26 | `ServicoContabilizacaoPOS.php:41-80,213-236,239-275`; `ServicoLancamentos.php:77` |
| Terceiros | Cliente padrão do terminal (cliente com conta); «Consumidor Final» criado automaticamente (NIF 999999999); hóspede = cliente; `exigirContaCliente` (classe 31) na integração da lavandaria; listas do POS por papel em modo `estrito` | `ServicoVendasPOS.php:137-148`; `ServicoFontesLavandariaPOS.php:168-181`; ADR-071 (decisão 5) |
| Produtos | `produtos.servicos_pos` (jsonb), `e_quarto`, `preco_por_hora`, `preco_por_dia`, `horas_minimas`, `lavandaria_*` (grupo, unidade, dias de entrega, requer orçamento, ativa, preços por peça/kg), `movimenta_stock`, `taxa_imposto`, `codigo_isencao_fe` | `ServicoCatalogoPOS.php:28-29`; migração `2026_10_17_104512_r3_vendas_servicos_pos_produtos.php` |
| Impostos | IVA por produto (`taxa_imposto`); `CalculadoraDocumento::calcularComIva` (regra AGT: base ajustada ao cêntimo, IVA por excesso). Não há IVA «em falta» editável no POS | `CalculadoraDocumento.php:44-70` |
| Moedas | POS só em Kz (AOA). Contas bancárias da prestação em outra moeda são recusadas (`MOEDA_NAO_SUPORTADA`) | `ServicoDocumentosVenda.php:96`; `ServicoPrestacaoContasPOS.php:372-374` |
| Auditoria | Trait `Auditavel` nos modelos base; `ServicoMesasPOS` regista explicitamente a libertação de mesa; `AuditarContaRoot` | `Models/Base/TerminalPOSBase.php:29`; `ServicoMesasPOS.php:122` |
| Notificações | Nenhuma | — |
| Gestão / BI | Fluxo de processos POS e Lavandaria; KPIs «POS e Serviços» (vendas, tickets, ticket médio, sessões, desvios, ordens, entregas no prazo, estadias, ocupação, ADR, RevPAR); OData/Cubo lê `vendas` | `FluxoPOS.php`, `FluxoLavandaria.php`, `ServicoRelatoriosPOS.php:94-110`, `ModuloServicos.php` |
| Validação de dados | 10 verificações: `pos_sessoes_abertas_antigas`, `pos_sessoes_por_integrar`, `pos_sessoes_por_prestar`, `pos_liquidacoes_orfas`, `pos_tpa_talao_difere`, `hotel_estadias_saida_atrasada`, `pos_armazem_vendas_por_contabilizar`, `lavandaria_servicos_sem_conta_iva`, `lavandaria_servicos_conta_nao_62`, `lavandaria_clientes_sem_conta` | `Services/Sistema/ServicoValidacoesDados.php:125-200` |
| Multi-empresa / SaaS | RLS por `empresa_id` em todas as tabelas (aplicada por `IsolamentoRls::aplicarATodas`), `ContextoEmpresa`; copiar meios de outra empresa só com acesso | `Support/Tenancy/IsolamentoRls.php`; `ServicoTerminaisPOS.php:169-180` |

### 1.4 Numeração e séries

| Documento | Formato | Fonte | Evidência |
|---|---|---|---|
| Sessão | `T01-AAAA-NNNN` (código do terminal, ano, 4 dígitos) | `ServicoNumeracao` chave `pos_sessao:{terminal}:{ano}`, continuando o maior contador do legado (`contadores_sessao`) ou o maior sufixo existente | `ServicoSessoesPOS.php:55-59,195-200` |
| Z | `Z-T01-AAAA-NNNN` | chave `pos_z:{terminal}:{ano}` (`contadores_z`) | `ServicoSessoesPOS.php:174-179` |
| FR do POS | `FR T01AAAA/n` (série do terminal) | `ServicoSeries::reservar(…, origem_serie = código do terminal)`; a série é criada automaticamente | `ServicoDocumentosVenda.php:149`; `ServicoFrentePOS.php:81-93` |
| Ordem de lavandaria | `OS/<terminal>/<ano>/NNNNN` + etiqueta `<terminal>NNNNN` e peças `…-01,-02…` | chave `lav_os:{terminal}:{ano}` (`lavandaria_contadores_os`) | `ServicoOrdensLavandaria.php:80-93` |
| Recibo da lavandaria | `RC-LAV/<terminal>/<ano>/NNNNN` | chave `lav_rc:{terminal}:{ano}` (`lavandaria_contadores_rc`) | `ServicoCaixaLavandaria.php:172-177` |
| Peças / serviços de lavandaria | `PC####` / `SV####` | `ServicoNumeracao` | `ServicoTabelasLavandaria.php:16-17` |
| Guia de venda ao balcão | `GE POS AAAA/NNNN` (continua a do legado) | `ServicoGuiasSaida::numero('GE POS', …)` | `ServicoGuiasSaida.php:65-90` |
| Senha do armazém | `P-001…` por armazém e por dia | chave `pos_armazem:senha:{armazem}:{dia}` | `ServicoSenhasPOSArmazem.php:47-61` |

A unicidade destes números **não** é garantida por índice único na base (só pelo serviço de numeração): ver secção 8.

### 1.5 Estados e transições

| Entidade | Estados | Transições (e onde) |
|---|---|---|
| `sessoes_pos.estado` | `ABERTA` → `FECHADA` | abrir (`ServicoSessoesPOS.php:36`), fechar Z (`:120`); sem reabertura |
| `estado_contabilizacao` | `PENDENTE`, `CONTABILIZADA`, `SEM_MOVIMENTO` | fecho define `PENDENTE`/`SEM_MOVIMENTO` (`:181`); `contabilizar` → `CONTABILIZADA`; `descontabilizar` → `PENDENTE` (`ServicoContabilizacaoPOS.php:70,144`) |
| `estado_desvio` | `NAO_APLICAVEL` (aberta) → `SEM_DESVIO` / `DELIBERADO` (automático, dentro da tolerância) / `PENDENTE` (acima) | `deliberar`: PENDENTE → DELIBERADO; `anularDeliberacao`: DELIBERADO manual → PENDENTE (`:152-210`) |
| `estado_liquidacao` | `PENDENTE`, `PARCIAL`, `LIQUIDADA`, `SEM_MOVIMENTO` | recalculado em prestar/anular/integrar/deliberar (`ServicoPrestacaoContasPOS.php:234-245`) |
| `liquidacoes_pos.estado` | `REGISTADO` → `ANULADO` | `anular` (`:189-231`) |
| `contas_mesa_pos.estado` | `ABERTA`, `FECHADA`, `ANULADA` (CHECK na base) | gravar conta / libertar / cobrar (`ServicoMesasPOS.php:110-172`) |
| `estadias_hotel.estado` | `ABERTA` → `FECHADA` (check-out) ou `ANULADA` | `EstadiaHotel.php:16-20`; check-in/check-out/anular |
| `pedidos_lavandaria.estado` (derivado das linhas) | `ORCAMENTO`, `RECEBIDA`, `EM_EXECUCAO`, `PRONTA`, `ENTREGA_PARCIAL`, `ENTREGUE`, `ANULADA` | `RegrasLavandaria::estadoOrdem` (`:56-71`); linhas: `ORCAMENTO→RECEBIDA` (aprovar) ou `→ANULADA` (recusar); `RECEBIDA→EM_EXECUCAO→PRONTA→ENTREGUE`; reversão `→RECEBIDA` |
| `reclamacoes_lavandaria.estado` | `AGUARDA_COMPROVATIVO` → `COMPROVADO` → `APROVADA`/`RECUSADA` → `PAGA` | `ServicoReclamacoesLavandaria.php:30-112` |
| `pagamentos_lavandaria.natureza_registo/estado` | `ADIANTAMENTO`, `PAGAMENTO`, `FR`; `REGISTADO` → `ANULADO` | `ServicoCaixaLavandaria.php:171-187,339-361` |
| `senhas_pos_armazem.estado` | `AGUARDA` → `CHAMADA` | `ServicoSenhasPOSArmazem.php:47-95` |
| Encomenda (picking) | `POR_EXPEDIR`, `EXPEDIDA_PARCIAL` (derivado das quantidades entregues; «EM PICKING» não é gravado) | `ServicoPOSArmazem.php:68-91` |

Só `estado_desvio` e `terminais_pos.tipo` e `contas_mesa_pos.estado` têm CHECK na base; os restantes estados são validados apenas no serviço.

## 2. Base de dados

Fonte: `ferramentas/paridade/esquema_bd.json` (extraído do esquema vivo), migração gerada `backend/database/migrations/2026_09_30_100900_criar_tabelas_pos.php` (não editar à mão; gerada por `ferramentas/gerador/gerar_esquema.mjs`), `2026_10_08_140000_criar_senhas_pos_armazem_stock.php`, `2026_10_17_104512_r3_vendas_servicos_pos_produtos.php`, `2026_10_21_100000_pos_terminal_servicos_lavandaria.php`, chaves estrangeiras em `2026_09_30_900000_criar_chaves_estrangeiras.php`. **RLS:** todas as tabelas abaixo têm `empresa_id` e recebem a política `isolamento_tenant` por `IsolamentoRls::aplicarATodas()` (`2026_10_17_160000_saas_rls_base.php`; sem FORCE para o dono). O dicionário (`docs/dicionario/DICIONARIO_DADOS.md:2181-2420`) descreve as 10 tabelas herdadas do legado, mas com **URLs de API desactualizadas** (ex.: `/api/pos/config`, `/api/pos/lavandaria/pedidos` não existem; as rotas reais estão em 1.2).

### 2.1 Tabelas do POS (15)

#### `terminais_pos` — legado pos_terminals — 32 colunas

Colunas: `id` bigint NN, `empresa_id` bigint NN, `codigo` varchar, `nome` varchar, `tipo` varchar, `tipo_original` varchar, `unidade_negocio_id` bigint, `centro_custo_id` bigint, `armazem_id` bigint, `cliente_padrao_id` bigint, `fundo_maneio_padrao` numeric, `meios_pagamento` jsonb, `contadores` jsonb, `contadores_sessao` jsonb, `contadores_z` jsonb, `ativo` bool, `id_legado` varchar, `criado_por` varchar, `atualizado_por` varchar, `lavandaria_contadores_os` jsonb, `lavandaria_contadores_rc` jsonb, `lavandaria_contadores_ft` jsonb, `hotel_hora_entrada` varchar, `hotel_hora_saida` varchar, `hotel_tolerancia_atraso_min` int, `hotel_bloco_horas` bool, `hotel_bloco_horas_de` varchar, `hotel_bloco_horas_ate` varchar, `criado_em` timestamptz, `atualizado_em` timestamptz, `eliminado_em` timestamptz, `lavandaria_servicos_ids` jsonb.

FKs: `empresa_id`→`empresas`, `unidade_negocio_id`→`unidades_negocio`, `centro_custo_id`→`centros_custo`, `armazem_id`→`armazens`, `cliente_padrao_id`→`terceiros`.

Índices/restrições: UNIQUE (empresa_id,codigo) WHERE eliminado_em IS NULL; ix unidade_negocio_id, centro_custo_id, armazem_id, cliente_padrao_id; CHECK tipo ∈ {LOJA,RESTAURANTE,LAVANDARIA,HOTELARIA}.


#### `sessoes_pos` — legado pos_sessions — 39 colunas

Colunas: `id` bigint NN, `empresa_id` bigint NN, `terminal_pos_id` bigint, `codigo_terminal` varchar, `nome_terminal` varchar, `codigo_sessao` varchar, `estado` varchar, `aberto_em` timestamptz, `fundo_maneio_abertura` numeric, `operador_id` bigint, `nome_operador` varchar, `estado_contabilizacao` varchar, `estado_liquidacao` varchar, `estado_desvio` varchar, `estado_desvio_original` varchar, `fechado_em` timestamptz, `fechado_por` varchar, `numero_z` varchar, `numero_vendas` int, `total_vendas` numeric, `totais_por_metodo` jsonb, `transferencias` jsonb, `vendas_numerario` numeric, `numerario_esperado` numeric, `numerario_contado` numeric, `contagens_numerario` jsonb, `desvio` numeric, `fechos_tpa` jsonb, `justificacao` text, `lans_contabilizacao` jsonb, `diario_contabilizacao_id` bigint, `contabilizado_em` timestamptz, `contabilizado_por` varchar, `deliberacao` jsonb, `descontabilizado_em` timestamptz, `descontabilizado_por` varchar, `deliberacao_cancelada` jsonb, `criado_em` timestamptz, `atualizado_em` timestamptz.

FKs: `empresa_id`→`empresas`, `terminal_pos_id`→`terminais_pos`, `diario_contabilizacao_id`→`diarios_contabeis`.

Índices/restrições: UNIQUE (terminal_pos_id) WHERE estado='ABERTA'; ix empresa_id, terminal_pos_id, diario_contabilizacao_id; CHECK estado_desvio ∈ {NAO_APLICAVEL,SEM_DESVIO,DELIBERADO,PENDENTE}.


#### `configuracoes_pos` — legado pos_settings — 10 colunas

Colunas: `id` bigint NN, `empresa_id` bigint NN, `conta_sobra` varchar, `conta_quebra` varchar, `conta_operador` varchar, `tolerancia_desvio` numeric, `codigo_diario` varchar, `atualizado_por` varchar, `atualizado_em` timestamptz, `criado_em` timestamptz.

FKs: `empresa_id`→`empresas`.

Índices/restrições: UNIQUE (empresa_id).


#### `liquidacoes_pos` — legado pos_settlements — 29 colunas

Colunas: `id` bigint NN, `empresa_id` bigint NN, `estado` varchar, `criado_por` varchar, `sessao_pos_id` bigint, `numero_z` varchar, `chave_item` varchar, `natureza_registo` varchar, `meio_pagamento_codigo` varchar, `data` date, `montante_bruto` numeric, `comissao` numeric, `montante_liquido` numeric, `alvo` varchar, `conta_destino` varchar, `conta_transitoria` varchar, `sessao_caixa_id` bigint, `movimento_caixa_id` bigint, `conta_comissao` varchar, `documento_tesouraria_id` bigint, `referencia_lote` varchar, `numero_documento` varchar, `referencia` varchar, `comissao_deduzida` bool, `documento_comissao_id` bigint, `cancelado_em` timestamptz, `cancelado_por` varchar, `criado_em` timestamptz, `atualizado_em` timestamptz.

FKs: `empresa_id`→`empresas`, `sessao_pos_id`→`sessoes_pos`, `sessao_caixa_id`→`sessoes_caixa`, `movimento_caixa_id`→`movimentos_caixa`, `documento_tesouraria_id`→`documentos_tesouraria`, `documento_comissao_id`→`documentos_tesouraria`.

Índices/restrições: UNIQUE (sessao_pos_id,chave_item) WHERE estado='REGISTADO'; ix empresa_id, sessao_pos_id, sessao_caixa_id, movimento_caixa_id, documento_tesouraria_id, documento_comissao_id.


#### `mesas_pos` — novo (M-15) — 8 colunas

Colunas: `id` bigint NN, `empresa_id` bigint NN, `terminal_pos_id` bigint NN, `nome` varchar NN, `ordem` int, `ativo` bool, `criado_em` timestamptz, `atualizado_em` timestamptz.

FKs: `terminal_pos_id`→`terminais_pos`, `empresa_id`→`empresas`.

Índices/restrições: UNIQUE (terminal_pos_id,nome); ix empresa_id, (empresa_id,terminal_pos_id).


#### `contas_mesa_pos` — novo (M-15) — 17 colunas

Colunas: `id` bigint NN, `empresa_id` bigint NN, `mesa_pos_id` bigint NN, `terminal_pos_id` bigint NN, `sessao_pos_id` bigint, `estado` varchar NN, `linhas` jsonb NN, `percentagem_desconto` numeric, `cliente_id` bigint, `observacoes` text, `operador` varchar, `versao` int, `venda_id` bigint, `aberta_em` timestamptz, `fechada_em` timestamptz, `criado_em` timestamptz, `atualizado_em` timestamptz.

FKs: `mesa_pos_id`→`mesas_pos`, `terminal_pos_id`→`terminais_pos`, `sessao_pos_id`→`sessoes_pos`, `cliente_id`→`terceiros`, `venda_id`→`vendas`, `empresa_id`→`empresas`.

Índices/restrições: UNIQUE (mesa_pos_id) WHERE estado='ABERTA'; CHECK estado ∈ {ABERTA,FECHADA,ANULADA}; ix empresa_id, mesa_pos_id, terminal_pos_id, sessao_pos_id, cliente_id, venda_id, (empresa_id,terminal_pos_id,estado).


#### `senhas_pos_armazem` — novo (POS armazém) — 13 colunas

Colunas: `id` bigint NN, `empresa_id` bigint NN, `armazem_id` bigint NN, `data` date NN, `numero` int NN, `codigo` varchar NN, `estado` varchar NN, `chamada_em` timestamptz, `chamadas` int NN, `criado_por` varchar, `chamado_por` varchar, `criado_em` timestamptz, `atualizado_em` timestamptz.

FKs: `empresa_id`→`empresas`, `armazem_id`→`armazens`.

Índices/restrições: UNIQUE (empresa_id,armazem_id,data,numero); ix (empresa_id,armazem_id,data,estado).


#### `estadias_hotel` — legado hotel_stays — 34 colunas

Colunas: `id` bigint NN, `empresa_id` bigint NN, `terminal_pos_id` bigint, `codigo_terminal` varchar, `sessao_pos_id` bigint, `produto_quarto_id` bigint, `nome_quarto` varchar, `estado` varchar, `cliente_hospede_id` bigint, `nome_hospede` varchar, `numero_hospedes` int, `modo` varchar, `entrada_em` timestamptz, `saida_prevista_em` timestamptz, `quantidade` numeric, `preco_unitario` numeric, `taxa_imposto` numeric, `observacoes` text, `itens` jsonb, `historico_alteracoes` jsonb, `criado_por` varchar, `atualizado_por` varchar, `saida_em` timestamptz, `quantidade_final` numeric, `opcao_atraso` text, `percentagem_desconto` numeric, `venda_id` bigint, `numero_venda` varchar, `sessao_fecho_id` bigint, `fechado_em` timestamptz, `fechado_por` varchar, `motivo_cancelamento` text, `criado_em` timestamptz, `atualizado_em` timestamptz.

FKs: `empresa_id`→`empresas`, `terminal_pos_id`→`terminais_pos`, `sessao_pos_id`→`sessoes_pos`, `produto_quarto_id`→`produtos`, `cliente_hospede_id`→`terceiros`, `venda_id`→`vendas`, `sessao_fecho_id`→`sessoes_pos`.

Índices/restrições: UNIQUE (produto_quarto_id) WHERE estado='ABERTA'; ix empresa_id, terminal_pos_id, sessao_pos_id, produto_quarto_id, cliente_hospede_id, venda_id, sessao_fecho_id.


#### `vendas_estadias_hotel` — pivô novo (venda ↔ estadia) — 4 colunas

Colunas: `empresa_id` bigint NN, `venda_id` bigint NN, `estadia_hotel_id` bigint NN, `criado_em` timestamptz.

FKs: `venda_id`→`vendas`, `estadia_hotel_id`→`estadias_hotel`, `empresa_id`→`empresas`.

Índices/restrições: ix empresa_id, estadia_hotel_id (sem PK própria).


#### `configuracoes_lavandaria` — legado lav_settings — 18 colunas

Colunas: `id` bigint NN, `empresa_id` bigint NN, `taxa_armazenagem_ativa` bool, `dias_armazenagem_gratis` int, `percentagem_armazenagem_dia` numeric, `percentagem_adiantamento` numeric, `percentagem_urgencia` numeric, `fator_prazo_urgencia` numeric, `valor_taxa_recolha` numeric, `valor_taxa_entrega` numeric, `dias_reclamacao` int, `conta_extras` varchar, `taxa_extras` numeric, `conta_compensacao` varchar, `faturar_no_adiantamento` bool, `atualizado_por` varchar, `atualizado_em` timestamptz, `criado_em` timestamptz.

FKs: `empresa_id`→`empresas`.

Índices/restrições: ix empresa_id (sem UNIQUE: uma linha por empresa só por convenção do serviço).


#### `pecas_lavandaria` — legado lav_pieces — 14 colunas

Colunas: `id` bigint NN, `empresa_id` bigint NN, `codigo` varchar, `criado_por` varchar, `nome` varchar, `tecido` varchar, `cor` varchar, `unidade` varchar, `ativo` bool, `atualizado_por` varchar, `preco` numeric, `precos_servico` jsonb, `criado_em` timestamptz, `atualizado_em` timestamptz.

FKs: `empresa_id`→`empresas`.

Índices/restrições: ix empresa_id (sem UNIQUE no código da peça).


#### `pedidos_lavandaria` — legado lav_orders — 30 colunas

Colunas: `id` bigint NN, `empresa_id` bigint NN, `numero_encomenda` varchar, `terminal_pos_id` bigint, `codigo_terminal` varchar, `cliente_id` bigint, `recebido_em` timestamptz, `recebido_por` varchar, `sessao_rececao_id` bigint, `modo_faturacao` varchar, `urgente` bool, `data_prometida` timestamptz, `observacoes` text, `recolha` jsonb, `entrega` jsonb, `extras` jsonb, `historico_alteracoes` jsonb, `itens` jsonb, `estado` varchar, `colaborador_atribuido_id` bigint, `nome_atribuido` varchar, `atribuido_em` timestamptz, `atribuido_por` varchar, `nota_atribuicao` text, `atribuicoes` jsonb, `entregue_em` timestamptz, `motivo_cancelamento` text, `faturas_ids_legado` text, `criado_em` timestamptz, `atualizado_em` timestamptz.

FKs: `empresa_id`→`empresas`, `terminal_pos_id`→`terminais_pos`, `cliente_id`→`terceiros`, `sessao_rececao_id`→`sessoes_pos`, `colaborador_atribuido_id`→`colaboradores`.

Índices/restrições: ix empresa_id, terminal_pos_id, cliente_id, sessao_rececao_id, colaborador_atribuido_id (SEM UNIQUE em numero_encomenda).


#### `pedidos_lavandaria_faturas` — pivô novo (ordem ↔ factura) — 4 colunas

Colunas: `empresa_id` bigint NN, `pedido_lavandaria_id` bigint NN, `venda_id` bigint NN, `criado_em` timestamptz.

FKs: `pedido_lavandaria_id`→`pedidos_lavandaria`, `venda_id`→`vendas`, `empresa_id`→`empresas`.

Índices/restrições: ix empresa_id, venda_id.


#### `pagamentos_lavandaria` — legado lav_payments — 19 colunas

Colunas: `id` bigint NN, `empresa_id` bigint NN, `pedido_lavandaria_id` bigint, `numero_encomenda` varchar, `sessao_pos_id` bigint, `terminal_pos_id` bigint, `cliente_id` bigint, `data` date, `montante` numeric, `troco` numeric, `pos_pagamentos` jsonb, `numero_recibo` varchar, `natureza_registo` varchar, `estado` varchar, `criado_por` varchar, `lans_contabilizacao` jsonb, `venda_id` bigint, `criado_em` timestamptz, `atualizado_em` timestamptz.

FKs: `empresa_id`→`empresas`, `pedido_lavandaria_id`→`pedidos_lavandaria`, `sessao_pos_id`→`sessoes_pos`, `terminal_pos_id`→`terminais_pos`, `cliente_id`→`terceiros`, `venda_id`→`vendas`.

Índices/restrições: ix empresa_id, pedido_lavandaria_id, sessao_pos_id, terminal_pos_id, cliente_id, venda_id (SEM UNIQUE em numero_recibo).


#### `reclamacoes_lavandaria` — legado lav_claims — 25 colunas

Colunas: `id` bigint NN, `empresa_id` bigint NN, `pedido_lavandaria_id` bigint, `numero_encomenda` varchar, `linha_pedido_id` varchar, `nome_item` varchar, `descricao_peca` text, `estado_entrada` text, `cliente_id` bigint, `descricao` text, `valor_declarado` numeric, `estado` varchar, `criado_por` varchar, `referencia_comprovativo` varchar, `data_comprovativo` date, `valor_comprovativo` numeric, `nota_decisao` text, `decidido_em` timestamptz, `decidido_por` varchar, `valor_compensacao` numeric, `documento_tesouraria_id` bigint, `pago_em` timestamptz, `pago_por` varchar, `criado_em` timestamptz, `atualizado_em` timestamptz.

FKs: `empresa_id`→`empresas`, `pedido_lavandaria_id`→`pedidos_lavandaria`, `cliente_id`→`terceiros`, `documento_tesouraria_id`→`documentos_tesouraria`.

Índices/restrições: ix empresa_id, pedido_lavandaria_id, cliente_id, documento_tesouraria_id.

#### Colunas POS noutras tabelas

- `vendas`: `sessao_pos_id` (FK `sessoes_pos`), `terminal_pos_id` (FK `terminais_pos`), `codigo_terminal_pos`, `pos_pagamentos` (jsonb: meio_id, tipo, nome, valor líquido do troco, referencia, conta_transitoria, conta_liquidacao, codigo_tpa), `pos_troco`, `pos_operador`, `pos_lans_contabilizacao` (jsonb), `nome_tabela` (mesa ou quartos), `arredondamento_agt`, `pedido_lavandaria_id`, `numero_pedido_lavandaria`, `sessao_pos_legado_codigo` (vendas do POS antigo, sem FK), `estadias_hotel_ids_legado`, `saida_armazem`, `desconto`.
- `lancamentos_contabeis`: `sessao_pos_id` (lançamentos `POS` e `POS_DESVIO`).
- `produtos`: `servicos_pos` (jsonb, CHECK `ck_produtos_servicos_pos`: nulo ou array), `e_quarto`, `preco_por_hora`, `preco_por_dia`, `horas_minimas`, `lavandaria_grupo`, `lavandaria_unidade`, `lavandaria_dias_entrega`, `lavandaria_requer_orcamento`, `lavandaria_ativa`, `lavandaria_preco_peca`, `lavandaria_preco_kg`, `movimenta_stock`, `taxa_imposto`.
- Guia de saída `VENDA_BALCAO` (`guias_saida`) e `movimentos_caixa`/`documentos_tesouraria` (origem `POS`, `origem_id` = liquidação) criados pelo POS mas pertencentes a Logística e Tesouraria.


## 3. Frontend

Stack: React 19 + antd 6 + TanStack Query. Registo dos ecrãs: `frontend/src/modulos/pos/ecras.ts:1-22` (cada ecrã carregado com `lazy` e envolvido por `BarraPos`, o cabeçalho «Ponto de Venda (POS)» com os separadores do legado) e `frontend/src/modulos/registo.tsx:7,25`. Rota: `/m/pos/<ecrã>`. Menu lateral (`frontend/src/layout/menuLegado.ts:67-72`): Vendas › Ponto de Venda (POS) com **Frente de Caixa, Relatórios POS, Terminais POS, Config. Impressão**; `pos_integracao`, `pos_desvios`, `pos_prestacao`, `pos_lavandaria`, `pos_hotelaria` **não têm entrada de menu** — chegam-se pelos separadores de `BarraPos` (`comum/BarraPos.tsx:8-18`, cada separador só com `<ecrã>_view`), pela pesquisa Ctrl+K (`layout/indicePesquisa.gerado.ts`) e pelos favoritos. `pos_armazem` está no menu de Stock/Logística (`menuLegado.ts:58`). Estado: dados do servidor em React Query com chave `['pos', …]` (`comum/dados.ts:7`); estado local em `useState`; `localStorage` só para conveniências do posto (terminal escolhido por empresa — `FrenteCaixa.tsx:17-30`; preferências da frente por utilizador — `comum/frente.ts:122-160`; preferências de impressão por empresa — `comum/impressao.ts:13-58`). Permissões no cliente por `pode(...)` (`comum/regras.ts`), sempre redundantes com o servidor.

### 3.1 Ecrãs

| Ecrã (`id`) | Ficheiros | O que tem | Linhas |
|---|---|---|---|
| `pos` — Frente de Caixa | `FrenteCaixa.tsx`, `frente/*`, `comum/PainelPagamentos.tsx`, `comum/calculos.ts`, `comum/frente.ts`, `comum/produtos.ts`, `comum/atalhos.ts` | Cartão «Frente de Caixa Imersiva» → modo imersivo em ecrã inteiro (PC/Tablet/Mobile, «Voltar ao Office ERP»); «Abertura de Caixa» (terminal, fundo de maneio, operador, meios, aviso fixo de sessões antigas); barra da sessão (distintivo «Modo: …», relatório X, trocar terminal, fechar caixa); venda (carrinho à esquerda: Limpar, Desconto %, Subtotal líquido, IVA, Total, Checkout; produtos à direita: pesquisa, Com/Sem stock, Ocultar imagens, grelha/lista, chips de categoria com contagem, cartões com stock e imagem); pagamento misto com troco; talão; mapa de mesas (restaurante); mapa de quartos + check-in/consumos/check-out (hotelaria); frente de lavandaria; fecho Z; «Criar novo cliente» | `FrenteCaixa.tsx` 189; `PainelVenda.tsx` 697; `GrelhaProdutos.tsx` 225; `FechoZ.tsx` 314; `PainelMesas.tsx` 199; `PainelQuartos.tsx` 118; `PainelEstadia.tsx` 276 |
| `pos_relatorios` | `Relatorios.tsx` | Filtros (período, terminal, operador), KPIs, divisão por meio, fechos Z, vendas por produto/terminal/operador, diferenças TPA, impressão/exportação | 366 |
| `pos_terminais` | `Terminais.tsx`, `comum/DefinicoesCaixa.tsx`, `lavandaria/ServicosTerminal.tsx` | Lista com série FR por terminal e sessão aberta; criar/editar (código, nome, tipo, fundo, armazém, cliente por omissão, UN, CC, parâmetros do hotel); editor de meios (tipo, nome, activo, contas transitória/liquidação, N.º TPA, comissão %, conta e tratamento da comissão); copiar meios (substituir/acrescentar, de outra empresa); activar/desactivar; eliminar; serviços da lavandaria; botão «Definições de caixa» (contas de desvio, tolerância, diário) | 698 |
| `pos_config_print` | `ConfigImpressao.tsx` | Formato (térmico 58/80 mm ou A4), impressão automática do talão, pré-visualizar/directo, rodapé («Obrigado pela preferência!») — **guardado no navegador**, não no servidor | 151 |
| `pos_integracao` | `Integracao.tsx`, `contas/PreVisualizacaoIntegracao.tsx` | Sessões por integrar (selecção múltipla) → «Pré-visualizar e integrar» (lançamentos D/C sem gravar, impedimentos por sessão) e integradas (descontabilizar com motivo) | 164 + 200 |
| `pos_desvios` | `Desvios.tsx` | Desvios por deliberar (com justificação do operador) e histórico de deliberações; decisões SOBRA_PROVEITO/FALTA_CUSTO/FALTA_OPERADOR/SEM_EFEITO; anular deliberação; ver sessão; imprimir Z | 265 |
| `pos_prestacao` | `Prestacao.tsx` | Sessões com itens por prestar (NUM/TPA/TRF), modal «Prestar» (folha de caixa, conta bancária, data, comissão, deduzida), «prestar todas as transferências», liquidações com detalhe e anulação | 461 |
| `pos_lavandaria` | `lavandaria/Lavandaria.tsx` (separadores Peças · Serviços · Ordens de serviço · Relatórios · Danos e reclamações · Definições), `Tabelas.tsx`, `ImportarTabela.tsx`, `Ordens.tsx`, `DetalheOrdem.tsx`, `Recepcao.tsx`, `Reclamacoes.tsx` | Backoffice completo da lavandaria; na frente de caixa de um terminal LAVANDARIA aparece `FrenteLavandaria.tsx` («Nova ordem de serviço» + «Ordens e levantamentos») | 451, 437, 177, 679, 590, 691, 267, 28 |
| `pos_hotelaria` | `hotelaria/Hotelaria.tsx`, `Estadia.tsx`, `Checkout.tsx` | Mapa de quartos, check-in, alteração, consumos, anulação, check-out (simulação + emissão), histórico de estadias, tarifas dos quartos | 294, 349, 226 |
| `pos_armazem` (módulo Stock) | `frontend/src/modulos/stock/PosArmazem.tsx`, `posArmazem/Balcao.tsx`, `Senhas.tsx`, `logica.ts`, `posArmazem.css` | Separadores «Atendimento ao Balcão» (cartões do armazém, carrinho de picking → guia de saída, acerto de stock), «Fila de Picking», «Monitor de Chamada de Senhas» (voz por `speechSynthesis`, actualização periódica), «Vendas ao balcão» | 360, 334, 163 |

### 3.2 Componentes e utilitários partilhados do POS

- `comum/calculos.ts` — port em cêntimos inteiros de `CalculadoraDocumento::calcularComIva` (linhas 45-70), carrinho (`adicionarProduto`, `alterarQuantidade`, `alterarPreco`, `removerLinha`, `totaisCarrinho`, `linhasParaApi`), pagamentos (`resumirPagamentos` 157-180, `liquidarTroco` 185-200, `sugestoesNumerario` 202), `DENOMINACOES` (217). Tem 21 testes.
- `comum/regras.ts` — visibilidade das acções por permissão + estado (frente, integração, desvio, prestação, terminal, ordem de lavandaria, reclamação, estadia).
- `comum/impressao.ts` — talão de venda (`htmlTalaoVenda` 144-179), talão genérico (consulta de mesa, lavandaria, hotel), etiquetas, relatório X/Z, reimpressão em janela de pré-visualização ou iframe invisível (sem janelas bloqueáveis; CSP sem `onclick` inline).
- `comum/documentos.ts` — documentos A4 (sessão/Z, relatório X, relatórios POS) para o motor comum de impressão.
- `lavandaria/impressoes.ts` — talão da OS (2 vias), etiquetas, recibo RC-LAV, factura.
- `comum/meios.ts` — validação local dos meios (espelho de `validarMeios`).
- `comum/atalhos.ts` — `useAtalhos`: teclas de função activas mesmo em campos; outras só fora de campos.
- `frente/ModoImersivo.tsx` — emulador de dispositivo (pc/tablet/mobile) e ecrã inteiro; `frente/quartos.ts`, `frente/mesas.ts` — regras puras (horas bloqueadas, mapa de quartos, resumo de conta).
- Atalhos da frente: F2 pesquisa · F4 cliente · F8 desconto · F9 checkout/emitir · Esc limpa a pesquisa (`PainelVenda.tsx` cabeçalho).
- Responsividade: `pos.css`, `useEcraPequeno`, tabelas com `scrollTabela`; Playwright/axe em 12 larguras (ADR-066 e seguintes).

### 3.3 O que o frontend NÃO tem (face ao backend e ao legado)

- Sem **reimpressão de vendas anteriores** (só o talão da venda acabada de emitir, `PainelVenda.tsx:125-130`) nem **consulta/anulação/devolução** de vendas POS (a correcção é por nota de crédito no módulo Vendas; o ecrã de Lavandaria avisa isso — `DetalheOrdem.tsx:345`).
- Sem atalho «IVA em falta» na integração (`ANALISE_PARIDADE:412`); `grep` por «iva em falta» em `frontend/src` e `backend/app` não devolve nada.
- Sem edição da configuração de impressão no servidor (é por posto).
- O talão de venda **não imprime hash/QR/ATCUD** nem a frase «Processado por programa validado» (`impressao.ts:144-179`).
- Sem modo offline nem fila de vendas pendentes.

## 4. Testes existentes

### 4.1 PHPUnit (`backend/tests/Feature`; correr só com `docker exec erp_app_e2e php artisan test`)

| Ficheiro | Métodos | O que cobre |
|---|---|---|
| `POSTest.php` | 5 | validação de contas dos meios e cópia de meios; operador pelo nome completo; venda → fecho Z → integração com CMV e desvio automático; pagamentos, stock e desvio deliberado com segregação; preço com mais de 2 casas |
| `POSPrestacaoTest.php` | 4 | prestação completa a saldar as transitórias com desvio automático; desvio pendente a bloquear numerário, comissão à parte, anulação; numerário negativo e sessão sem vendas com desvio; relatórios com filtros e bloco «POS e Serviços» |
| `POSMesasTest.php` | 3 | criar mesas, gravar conta no servidor, total; cobrar → FR com nome da mesa e fecho da conta; só terminais RESTAURANTE e isolamento entre empresas |
| `POSHotelariaTest.php` | 5 | check-in com regras do terminal e quarto ocupado; consumos e anulação só sem consumos; check-out por quarto com saída tardia, desconto e rateio; factura única e saída tardia à hora; normalização dos JSON migrados |
| `POSLavandariaTest.php` | 7 | tabelas e definições validadas no servidor; Consumidor Final: adiantamento, factura, entrega e integração da sessão; factura-recibo, orçamento, materiais e permissões; armazenagem, anulações, reclamações e segregação; normalização dos dados migrados; colaboradores para atribuir; serviços por terminal |
| `POSLavandariaImportacaoTest.php` | 2 | simulação e importação de peças e serviços com linhas inválidas; exige `lav_tabelas` |
| `POSArmazemTest.php` | 2 | venda ao balcão (guia, stock, CMV, anulação por estorno); picking e expedição por GR |
| `ParidadePosArmazemTest.php` | 3 | artigos com stock do armazém; acerto de stock com movimento; senhas (emitir, chamar por ordem, monitor) |
| `ParidadePosContasTest.php` | 4 | pré-visualização sem gravar; integração em lote com impedimentos; permissões e validação; lista de ordens com prazo de levantamento |
| `ParidadePosFrenteTest.php` | 3 | aviso de sessões antigas de qualquer operador; stock do armazém e série de cada terminal; permissão |
| `POSPermissoesConsultaTest.php` | 1 | operador só-POS consulta clientes, catálogo, categorias e stock |
| `R3VendasTest.php` (parte POS) | 3 | catálogo por serviço; marcação `servicos_pos`; venda ao balcão recusa produto fora do serviço |
| `CorrecoesPosMigracaoTest.php` | 3 | movimentos de stock de vendas POS em armazém de outra empresa; empresa inexistente; NC sobre a factura (migração, não funcionalidade) |

Total: 39 métodos nos 11 ficheiros POS + 3 + 3. Cada método é um «cenário» longo (várias asserções): a densidade de cobertura por regra é alta no caminho feliz e nas recusas principais, mas **sem testes dedicados** a: eliminar/activar/desactivar terminal isolados, `copiar meios` entre empresas (só dentro de `POSTest`), reabertura de estadia alterada (`alterar`), `orcamentos` em massa, `atribuir` com datas, danos pagos com falta de conta, `ServicoSenhasPOSArmazem::repetir`, `FluxoPOS`/`FluxoLavandaria` (cobertos genericamente por `GestaoFluxosTest`).

### 4.2 Vitest (`frontend/src/modulos/pos`, `frontend/src/modulos/stock/posArmazem`) — 91 casos, 15 ficheiros

`comum/calculos.test.ts` (21: cálculos, pagamentos, troco, contagem, rateio), `comum/regras.test.tsx` (20: visibilidade das acções), `comum/frente.test.ts` (8), `comum/documentos.test.ts` (5), `comum/impressao.test.ts` (3), `formularios.test.tsx` (5), `r3Vendas.test.tsx` (5: catálogo por serviço e marcação), `frente/mesas.test.ts` (3), `frente/quartos.test.ts` (5), `lavandaria/formularios.test.ts` (3), `indicadores.test.ts` (3), `Recepcao.test.tsx` (1), `relatorio.test.ts` (1), `stock/posArmazem/logica.test.ts` (4), `posArmazem.test.tsx` (4). Não há testes de componente para `PainelVenda`, `FechoZ`, `Terminais`, `Prestacao`, `Desvios`, `Integracao`, `Hotelaria`, `Ordens`, `DetalheOrdem`, `Reclamacoes`, `Tabelas`.

### 4.3 Playwright (`frontend/e2e/pos.e2e.ts`, 136 linhas, dados fictícios de `backend/database/seeders/E2E`)

2 cenários: (1) operador abre a sessão, vende com pagamento misto e troco, vê o X e faz o Z sem desvio; (2) administrador pré-visualiza e integra a sessão fechada. Não há e2e de restaurante, hotelaria, lavandaria, prestação, desvios nem POS de armazém.

## 5. Documentação existente sobre o POS e a paridade

| Documento | O que diz | Estado |
|---|---|---|
| `docs/arquitetura/DECISOES.md` ADR-047 (l.1100) | POS parte 1: terminais, sessões, vendas, integração, desvios; correcções face ao legado; dados reais verificados (empresa 18) | Actual |
| ADR-048 (l.1192) | Prestação de contas e relatórios; segregação; anular não apaga | Actual |
| ADR-049 (l.1227) | Lavandaria e alfaiataria; «Fica para depois: recibos RC-LAV no SAF-T, impressões, importação Excel» | Impressões e importação já foram feitas depois (M-16); **recibos RC-LAV no SAF-T continuam por fazer** |
| ADR-050 (l.1273) | Hotelaria e POS de armazém. «Não portados: o acerto de stock (total = soma dos armazéns, ADR-042) e as senhas de atendimento» | **Superado:** o acerto e as senhas foram portados depois (`paridade_pos_stock/pos_armazem.php`, `ServicoAcertoStockPOSArmazem`, `ServicoSenhasPOSArmazem`). O acerto não muda o total do produto |
| ADR-063 (l.1733) | Os 9 ecrãs do POS no frontend; operador só-POS pode consultar | Actual; «o operador não cria fichas» foi revertido por `NovoClienteModal` |
| ADR-066/067/068 | Arredondamento AGT do POS separado do desconto (decisão 10); SAF-T do POS com `SettlementAmount` (decisão 11); M-15 mesas, M-16 impressões e importação da lavandaria; erros E-VEN-1/E-VEN-2 corrigidos | Actual |
| ADR-070/071 | Paridade visual do POS; contas por papel (conta cliente classe 31), listas do POS em modo `estrito`, «Consumidor Final» sem contas escondidas; série automática fora do regime AGT; NC sobre FR permitida | Actual |
| `docs/paridade/ANALISE_PARIDADE_2026-10-02.md` §9-10 | Matriz de 2026-10-02 (POS: MIGRADO excepto mesas, impressões da lavandaria, importação, talão do check-out, criação de quartos, IVA em falta, talão e visto do picking, senhas/acerto «N/A») | **Desactualizada**: mesas, impressões, importação, talão do check-out, pré-visualização/lote da integração, senhas, acerto e talão do picking foram entregues depois. Continuam em aberto (por leitura do código): atalho «IVA em falta» e recibos RC-LAV no SAF-T. O «ecrã inteiro / dispositivo» foi entregue (`ModoImersivo.tsx`) |
| `docs/paridade/ANALISE_CALCULOS_FLUXOS_2026-10-02.md` §2 | Fórmulas POS/hotel/lavandaria legado × novo; E-VEN-1, E-VEN-2 | Actual |
| `docs/paridade/INVENTARIO_FUNCIONAL_LEGADO.md` §3.3 | Linhas sobre `pos`, hotelaria, lavandaria no legado (regras: diária 14:00→12:00, bloqueio 21:00-08:00, 11 regras da lavandaria) | Base para o inventário do outro agente |
| `docs/paridade/COBERTURA_CAMPOS_2026-11.md` | Triagem BD→modelo→API→frontend: POS 11 tabelas, 254 colunas, 54, 4, 165, 29, 2, 0 (por categoria da triagem) | Heurística; ver ficheiro |
| `docs/dicionario/DICIONARIO_DADOS.md:2181-2420` | Dicionário das 10 tabelas legadas | URLs de API desactualizadas |
| `frontend/src/modulos/pos/**` e serviços | Os docblocks citam `ficheiro.js:linha` do legado para cada regra | Úteis como rastro de origem |
| `RELATORIO.md` de `verificacao_visual` (citado em várias rotas/ficheiros: 2.6, 3.1.x, 3.3.x, 3.5) | Relatório da verificação visual contra o legado, base do ADR-070 | **Não existe no repositório** (a referência ficou pendurada) |

**Decidido e portado:** ver secção 7. **Decidido como «não portar»:** o POS antigo em localStorage (`POS_SESS_<epoch>` fica só como `sessao_pos_legado_codigo`), contas fixas do legado (31.1, 34.5.3, 62), «absorver até 1 Kz» na integração, marcador «EM PICKING», contador de senhas em memória. **Registado como lacuna:** recibos RC-LAV no SAF-T (ADR-049), IVA em falta (matriz), vistos por linha no picking (feito: `PICKING_INCOMPLETO` + talão), e a `ANALISE_PARIDADE` marca ainda o POS como «MIGRADO» sem rever.

## 6. Regras implementadas (fórmulas, contabilização, stock, validações)

### 6.1 Preços, descontos, IVA, arredondamento

- **Preço com IVA.** Em cada linha: `cobrado = arred2(qtd × preço_com_IVA × (1 − pct/100))`; `base = arred2(cobrado / (1 + taxa/100))` e ajuste de ±0,01 (até 4 passos) até `base + IVA = cobrado`, com IVA por excesso ao cêntimo; `preco_base = arred6(base / qtd)`; totais: `liquido = Σ base`, `imposto = Σ IVA`, `bruto = liquido + imposto` — `CalculadoraDocumento.php:44-70`. Espelho cliente em `calculos.ts:45-70` (testes: `calculos.test.ts`).
- **Desconto.** Só desconto global em % (0-100, 4 casas) sobre o total com IVA; sem desconto por linha. Alterar o preço de uma linha (diferente do preço do produto a 2 casas) ou ter desconto > 0 exige `pos_desconto` — `POSController.php:196-202`; hotel: `HotelariaController.php:85,116,144,168`; mesas: `MesasPOSController.php:107-113`.
- **Arredondamento AGT (decisão 10).** `bruto = Σ arred2(qtd × preço_com_IVA)`; `desconto = arred2(bruto × pct/100)`; `arredondamento_agt = bruto − desconto − total_documento`; gravado em `vendas.arredondamento_agt`; o campo `desconto` da venda guarda `bruto − total` (`ServicoVendasPOS.php:82`) — `ServicoDocumentosVenda.php:565-572`. Efeito aceite: 40 500 com desconto pode sair 40 499,99 (ADR-050).
- **Preço a 2 casas.** `preco_unitario` com `decimal:0,2` (`POSController.php:192`) e normalizado a 2 casas antes de validar pagamentos (`ServicoVendasPOS.php:58-61`).
- **Total a cobrar** calculado antes de numerar, para validar pagamentos contra o mesmo total do documento (`ServicoVendasPOS.php:66-71`).

### 6.2 Pagamentos, troco

`ServicoVendasPOS.php:94-134`: mínimo 1 pagamento; cada valor > 0; meio activo do terminal; transferência exige `referencia` («Indique o n.º do comprovativo da transferência (…)»); `não_numerário > total` → `PAGAMENTO_EXCEDE` («TPA e transferências não podem exceder o total: o troco só se dá em numerário»); `recebido < total` → `PAGAMENTO_INSUFICIENTE`; `troco = recebido − total`; o troco abate-se ao numerário **do último pagamento em numerário para trás**; o valor gravado em `pos_pagamentos` é líquido do troco, linhas a zero desaparecem; `meio_pagamento` do documento = tipo único ou `MISTO`.

### 6.3 Stock e CMV

- Verificação por produto que `movimenta_stock`, no armazém do terminal (`armazem_id` ou predefinido), com `lockForUpdate`, somando quantidades repetidas, 3 casas: «Stock insuficiente de {código} no armazém do terminal (disponível …)» — `ServicoVendasPOS.php:151-165`.
- Saída ao custo médio e CMV (D 71 / C 26) pelo motor de stock; o CMV é lançado na integração da sessão (`ServicoStockVendas::linhasCmv`, `ServicoContabilizacaoPOS.php:267-271`). Serviços (`movimenta_stock = false`) não bloqueiam.
- Hotelaria: o stock dos consumos sai no check-out (a FR). Lavandaria: materiais de alfaiataria por guia de consumo ao custo médio com CMV imediato (`ServicoOrdensLavandaria.php:401-433`). POS armazém: guia `VENDA_BALCAO` (stock + CMV, diário GS; sem contas → guia por contabilizar com aviso) e GR do picking.

### 6.4 Sessão, Z e desvio

- `numerario_esperado = fundo_maneio_abertura + Σ pagamentos em numerário` (vendas FR não anuladas + recibos da lavandaria) — `ServicoSessoesPOS.php:105`.
- `contado = Σ denominação × quantidade` (notas e moedas 5000, 2000, 1000, 500, 200, 100, 50, 20, 10, 5, 2, 1 Kz — `:25`) ou o total indicado; `desvio = contado − esperado` — `:147-153`.
- TPA: talão obrigatório por meio TPA com movimento; `diferenca = talão − sistema`.
- Tolerância (`configuracoes_pos.tolerancia_desvio`): `|desvio| > tolerância` ou diferença de TPA ⇒ **justificação obrigatória** (`JUSTIFICACAO_OBRIGATORIA`) — `:165-172`.
- `estado_desvio`: zero → `SEM_DESVIO`; dentro da tolerância → `DELIBERADO` automático (SOBRA_PROVEITO se > 0, FALTA_CUSTO se < 0, `automatica: true`); acima → `PENDENTE` — `:182-185`.
- Sem vendas nem lavandaria → `SEM_MOVIMENTO` (contabilização e, se desvio zero, liquidação); com desvio automático, o desvio é lançado logo (`:186-188`).

### 6.5 Contabilização

- **Vendas** (um lançamento por data de emissão, diário `GEPOS`, `numero_documento = Z`, `tipo_origem = POS`, descrição «Z — vendas POS T01 (n doc.)»): D conta transitória de cada meio (numerário/TPA agregados por conta; **transferência uma linha por documento** com `terceiro_id` e descrição «Transferência {ref} — {doc}»); C proveitos e IVA com os valores do próprio documento (`ServicoContabilizacaoVendas::linhasProveitoEIva`), agrupados por conta e dimensões (UN/CC); CMV D 71 / C 26. Recusa se um pagamento não tem conta transitória ou se Σ pagamentos ≠ total do documento (`PAGAMENTOS_INCONSISTENTES`) — `ServicoContabilizacaoPOS.php:239-275`.
- **Lavandaria** (lançamentos próprios por data na mesma sessão): FT — D conta de cliente (classe 31; sem ela pára) / C proveitos + IVA; recibos — D transitória do meio (transferência por recibo, com cliente) / C conta de cliente; desequilíbrio é erro, nunca absorvido — `ServicoFontesLavandariaPOS.php:88-181`.
- **Desvio deliberado** (`POS_DESVIO`): sobra D transitória do numerário / C `conta_sobra` (6); falta D `conta_quebra` (7) ou `conta_operador` (3) / C transitória; data = fecho (automático) ou hoje (manual); valor = |desvio| — `:213-236`.
- **Descontabilizar** = estorno de todos os lançamentos (incl. o do desvio automático); recusa com prestação registada (`SESSAO_COM_LIQUIDACOES`) ou com deliberação manual lançada (`DELIBERACAO_COM_LANCAMENTO`) — `:121-149`.
- **Prestação:** numerário REC (D caixa/C transitória) ou PAG se negativo, na folha ABERTA da conta de liquidação; TPA RECEBIMENTO (C transitória pelo valor do sistema; comissão deduzida = linha D dentro do recebimento, não deduzida = PAGAMENTO à parte); transferência RECEBIMENTO por comprovativo com cliente — `ServicoPrestacaoContasPOS.php:249-331`. Valor do numerário prestado = `sistema + desvio` quando a deliberação tem efeito (`:83-91`); comissão sugerida = `arred2(pct × (talão ?? valor) / 100)` (`:110`); líquido = `bruto − comissão` se deduzida.
- **Diários/contas:** diário `GEPOS` por omissão (`ServicoConfigPOS::DIARIO_PADRAO`), configurável; contas de desvio com classe validada: sobras 6, quebras 7, operador 3 (`ServicoConfigPOS.php:18-19`); liquidação numerário 45, TPA/transferência 43 (`ServicoTerminaisPOS.php:131-134`).

### 6.6 Hotelaria

`RegrasHotel.php`: entrada 14:00, saída 12:00, tolerância 60 min, venda à hora bloqueada 21:00-08:00 (janela que atravessa a meia-noite; `de = até` desliga). Saída prevista: à hora, `entrada + horas`; à diária, `dia da entrada + máx(1, n) dias` à hora de saída. Saída tardia (depois de `prevista + tolerância`): à hora, `ceil(horas decorridas)`; à diária, `+ceil(atraso/24h)` diárias; decisão `RECALCULAR`/`MANTER` obrigatória. `total_alojamento = arred2(qtd × preço_com_IVA)`; preço por omissão: hora → `preco_por_hora`; dia → `preco_por_dia` ou `preco_unitario`. Check-in: diárias inteiras; à hora ≥ `horas_minimas` e fora da janela; quarto ocupado → `QUARTO_OCUPADO`; hóspede tem de ser cliente. Check-out: uma FR por quarto (cliente da estadia) ou factura **única** (cliente indicado); pagamentos rateados proporcionalmente ao total, cêntimo no maior pagamento, resto e troco na última factura — `ServicoCheckoutHotel.php:170-201`.

### 6.7 Lavandaria (`RegrasLavandaria.php`, `ServicoOrdensLavandaria.php`, `ServicoCaixaLavandaria.php`)

- `valorItem` = valor facturável com IVA (AGT); anulada = 0. `totais = servicos + extras (não cancelados)`; `saldo = total − pago`; `por_facturar = total − facturado`; `saldo_facturado = facturado − pago`.
- Urgência = `arred2(serviços × %urgência / 100)`; prazo em dias = `urgente ? max(1, ceil(prazo × fator_prazo_urgencia)) : max(1, prazo)` (prazo = máximo dos `dias_entrega` das linhas); `data_prometida = hoje + dias`.
- Adiantamento mínimo do Consumidor Final = `arred2(total × %adiantamento / 100)`; valor > total recusado.
- Taxas (urgência, armazenagem, recolha, entrega) são produtos `LAV-URG/ARM/REC/ENT` com conta de proveitos das definições e IVA do produto (`RegrasLavandaria.php:30-31`).
- Taxa de armazenagem por linha PRONTA não levantada: `dias = diasDesde(pronta_em) − dias_grátis > 0`; valor = `arred2(valorItem × %/dia / 100 × dias)` (`:166-181`).
- Facturação: recebido = total das linhas a facturar **e** facturado = pago ⇒ FR; senão FT em conta corrente + recibo (`RC-LAV…`, ADIANTAMENTO se o pago passa a exceder o facturado, senão PAGAMENTO). Consumidor Final paga o saldo facturado antes da entrega.
- Linha facturável = sem `venda_id`, não anulada e sem orçamento por aprovar (`RegrasLavandaria.php:89-92`).
- Estado da ordem = função dos estados das linhas activas (`:56-71`). Etiquetas: por kg, nº de peças; por peça, a quantidade (`:149-152`).
- Indicadores: atraso em dias, concluída no prazo, execução e espera de levantamento (`:200-223`).
- Reclamações: segregação decidir × pagar por reclamação; fundamentação até 255 caracteres; pagamento = documento de tesouraria PAGAMENTO (D conta de indemnizações classe 7).

### 6.8 POS de armazém

Acerto: `diferença = stock_global − Σ stock dos armazéns`; `ajuste = max(diferença, −stock_local)` (o armazém não fica negativo); aplica movimento AJUSTE E/S ao custo médio, documento `ACERTO_ARMAZEM`, e **repõe** `quantidade_stock`/`custo_medio` do produto (`ServicoAcertoStockPOSArmazem.php:76-127`). Picking: `por_expedir = max(0, quantidade − quantidade_entregue)`, `disponivel` por produto somando pedidos repetidos, expedição só com stock de **todas** as linhas e todas as linhas marcadas como recolhidas (`PICKING_INCOMPLETO`), conversão NE→GR (`ServicoPOSArmazem.php:93-152`).

### 6.9 Mensagens e códigos de erro principais (`ErroNegocio`)

`SESSAO_NAO_ABERTA` («A sessão de caixa já foi fechada. Abra uma nova sessão.»), `SESSAO_JA_ABERTA`, `TERMINAL_INATIVO`, `TERMINAL_SEM_MEIOS`, `FUNDO_INVALIDO`, `DESCONTO_INVALIDO`, `STOCK_INSUFICIENTE`, `PAGAMENTO_INSUFICIENTE|EXCEDE|INVALIDO`, `COMPROVATIVO_EM_FALTA`, `PRODUTO_FORA_DO_SERVICO`, `CONTAGEM_INVALIDA|EM_FALTA`, `FECHO_TPA_EM_FALTA`, `JUSTIFICACAO_OBRIGATORIA`, `SESSAO_NAO_FECHADA`, `SESSAO_JA_INTEGRADA`, `SEM_VENDAS`, `PAGAMENTO_SEM_CONTA`, `PAGAMENTOS_INCONSISTENTES`, `SESSAO_COM_LIQUIDACOES`, `SEGREGACAO_FUNCOES` (403), `DECISAO_INVALIDA`, `SEM_DESVIO_PENDENTE`, `NUMERARIO_PRESTADO`, `CONFIG_POS_EM_FALTA`, `ITEM_JA_PRESTADO`, `DESVIO_PENDENTE`, `DESVIO_NAO_LANCADO`, `SEM_FOLHA_CAIXA`, `FOLHA_CAIXA_FECHADA`, `MOVIMENTO_CAIXA_CONTABILIZADO`, `DOCUMENTO_INTEGRADO`, `COMISSAO_INVALIDA|SEM_CONTA`, `CONTA_NAO_BANCO`, `MOEDA_NAO_SUPORTADA`, `CODIGO_INVALIDO|BLOQUEADO|DUPLICADO`, `MEIO_INVALIDO|SEM_CONTAS`, `TRANSITORIA_REPETIDA`, `CONTA_CLASSE_INVALIDA`, `TERMINAL_COM_MOVIMENTO`, `CONTA_MESA_ALTERADA` (409), `MESA_DUPLICADA|COM_CONTA`, `QUARTO_OCUPADO|INVALIDO`, `HORA_BLOQUEADA`, `MINIMO_HORAS`, `ATRASO_POR_DECIDIR`, `ESTADIA_NAO_ABERTA|COM_CONSUMOS`, `ADIANTAMENTO_MINIMO`, `ESTADO_ENTRADA_EM_FALTA`, `DANO_SEM_DESCRICAO`, `PRECO_EM_FALTA`, `PAGAMENTO_OBRIGATORIO`, `LINHA_NAO_PRONTA`, `RECIBO_SESSAO_FECHADA`, `RECIBO_DE_FR`, `PICKING_INCOMPLETO`, `SEM_SENHAS`, `SEM_DIFERENCAS`. Catálogo geral em `backend/config/codigos_erro.php`.

### 6.10 Segregação de funções e permissões

Pares do catálogo (`catalogo.json:2980-2987`): `pos_venda` × `pos_desvio_deliberar` e `pos_venda` × `pos_prestar`; aplicados no servidor por `operador_id` da sessão (não só aviso): deliberar (`ServicoContabilizacaoPOS.php:171-174`), prestar (`ServicoPrestacaoContasPOS.php:135-137`), indemnização decidir × pagar (`ServicoReclamacoesLavandaria.php:99`). Perfil pré-definido «Operador POS / Lavandaria» (`catalogo.json:3287-3305`: `pos_view`, `pos_relatorios_view`, `pos_lavandaria_view`, `pos_hotelaria_view`, `pos_venda`, `pos_fecho`, `lav_ordens`, `lav_receber`, `hotel_estadias`, `hotel_checkout`).


## 7. Funcionalidades implementadas (base da matriz de paridade)

Legenda dos testes: **T** `POSTest`, **TP** `POSPrestacaoTest`, **TM** `POSMesasTest`, **TH** `POSHotelariaTest`, **TL** `POSLavandariaTest`, **TLI** `POSLavandariaImportacaoTest`, **TA** `POSArmazemTest`, **PA** `ParidadePosArmazemTest`, **PC** `ParidadePosContasTest`, **PF** `ParidadePosFrenteTest`, **R3** `R3VendasTest`, **PP** `POSPermissoesConsultaTest`; **V:x** teste Vitest do ficheiro x; **E2E** `frontend/e2e/pos.e2e.ts`; «—» = sem teste automático dedicado. Colunas «F-xxx»: a preencher na matriz.

### 7.1 Terminais, meios de pagamento e definições

| ID | Funcionalidade | Prova (código) | Teste |
|---|---|---|---|
| N-001 | CRUD de terminais: código (≤ 10, `[A-Z0-9_-]`), nome, tipo LOJA/RESTAURANTE/LAVANDARIA/HOTELARIA | `ServicoTerminaisPOS.php:51-93`; `POSController.php:51-69`; `Terminais.tsx` | T; V:formularios |
| N-002 | Código único por empresa (índice parcial) e bloqueado depois da primeira sessão/venda (entra na numeração) | `ServicoTerminaisPOS.php:57-62,256-259`; índice `uq_terminais_pos_empresa_id_codigo` | T (parcial) |
| N-003 | Terminal com armazém de saída, cliente por omissão (cliente com conta), fundo de maneio habitual, unidade de negócio e centro de custo | `ServicoTerminaisPOS.php:67-81` | T |
| N-004 | Meios de pagamento por terminal (NUMERARIO/TPA/TRANSFERENCIA): activo, contas transitória e de liquidação (classes 45/43, diferentes, sem repetição entre meios), TPA com código, comissão %, conta e tratamento (deduzida ou não) | `ServicoTerminaisPOS.php:101-163`; `comum/meios.ts:17-45` | T; V:formularios |
| N-005 | Meios padrão de um terminal novo (Numerário, Multicaixa (TPA), Transferência bancária) | `ServicoTerminaisPOS.php:40-48`; `comum/meios.ts:3-10` | — |
| N-006 | Copiar meios de outro terminal (SUBSTITUIR reaproveita ids por tipo; ACRESCENTAR junta), inclusive de outra empresa com acesso | `ServicoTerminaisPOS.php:169-194`; `Terminais.tsx:631-659` | T |
| N-007 | Activar/desactivar terminal; recusado com sessão aberta | `ServicoTerminaisPOS.php:222-230`; `POSController.php:80-86` | — |
| N-008 | Eliminar terminal (eliminação lógica); recusado se tem sessões ou vendas | `ServicoTerminaisPOS.php:232-238` | — |
| N-009 | Parâmetros de hotelaria no terminal: entrada, saída, tolerância, bloqueio da venda à hora e janela | `POSController.php:59-60`; `ServicoTerminaisPOS.php:81-92`; `Terminais.tsx:419-434` | TH |
| N-010 | Serviços de lavandaria por terminal (`lavandaria_servicos_ids`; null = todos; obrigatório configurar após criar) | `ServicoTerminaisPOS.php:204-220`; `POSController.php:97-112`; `ServicosTerminal.tsx` | TL |
| N-011 | Definições do POS: conta de sobras (6), quebras (7), responsabilidade do operador (3), tolerância de desvio, diário (GEPOS) | `ServicoConfigPOS.php:24-61`; `DefinicoesCaixa.tsx` | T; V:formularios |
| N-012 | Série das FR de cada terminal no ano (existente ou prevista) mostrada em Terminais | `ServicoFrentePOS.php:81-93`; `frente.ts:161-170` | PF |
| N-013 | Catálogo do terminal filtrado pelo serviço (`produtos.servicos_pos`; quartos só na hotelaria; serviços de lavandaria sempre na lavandaria) | `ServicoCatalogoPOS.php:37-74`; `CatalogoServicosPOSController.php` | R3; V:r3Vendas |
| N-014 | Marcação dos serviços POS por produto (GET/PUT), sem repetidos, pela ordem dos serviços | `ServicoCatalogoPOS.php:110-144` | R3; V:r3Vendas |
| N-015 | Venda ao balcão recusa produtos fora do serviço do terminal (também para pedido feito à mão) | `POSController.php:204-206`; `ServicoCatalogoPOS.php:81-91` | R3 |

### 7.2 Sessão de caixa e fecho

| ID | Funcionalidade | Prova (código) | Teste |
|---|---|---|---|
| N-016 | Abrir sessão com fundo de maneio (omissão: o do terminal); uma sessão aberta por terminal (lock + índice único parcial) | `ServicoSessoesPOS.php:36-69`; `POSController.php:157-164` | T; E2E |
| N-017 | Abrir exige terminal activo e pelo menos um meio activo com conta transitória | `ServicoSessoesPOS.php:39-44` | — |
| N-018 | Código de sessão `T01-AAAA-NNNN` por serviço de numeração, continuando o contador do legado | `ServicoSessoesPOS.php:55-59,195-200` | T |
| N-019 | Aviso (na abertura e na frente) de sessões abertas de dias anteriores de todos os terminais e operadores | `ServicoFrentePOS.php:50-72`; `ServicoSessoesPOS.php:63-67`; `AvisoSessoesAntigas.tsx` | PF |
| N-020 | Totais da sessão a partir das vendas FR não anuladas (por meio, numerário, transferências) | `ServicoSessoesPOS.php:79-106` | T |
| N-021 | Relatório X (sessão aberta) com impressão térmica ou A4 | `ServicoSessoesPOS.php:109-113`; `RelatorioXModal.tsx`; `documentos.ts:129-160`; `impressao.ts:255-295` | E2E; V:documentos |
| N-022 | Fecho Z: contagem do numerário por notas e moedas (12 denominações) ou pelo total | `ServicoSessoesPOS.php:25,120-153`; `FechoZ.tsx` | T; E2E |
| N-023 | Fecho Z: talão de fecho de cada TPA com movimento (valor, operações, lote) e diferença face ao sistema | `ServicoSessoesPOS.php:155-164`; `FechoZ.tsx:279` | T (parcial) |
| N-024 | Justificação obrigatória se |desvio| > tolerância ou se o talão do TPA difere | `ServicoSessoesPOS.php:165-172` | T |
| N-025 | Estado do desvio no fecho: SEM_DESVIO / DELIBERADO automático (sobra ou falta dentro da tolerância) / PENDENTE | `ServicoSessoesPOS.php:176-185` | T |
| N-026 | Nº Z `Z-T01-AAAA-NNNN`; sessão sem movimento → SEM_MOVIMENTO; desvio automático lançado já se não há vendas | `ServicoSessoesPOS.php:174-188` | TP |
| N-027 | Listar e consultar sessões (filtros terminal, estado, contabilização, desvio, liquidação; paginação) com as vendas | `POSController.php:134-155` | — |
| N-028 | Reimpressão do Z / detalhe da sessão em A4 | `DetalheSessao.tsx`; `documentos.ts:36-110` | V:documentos |
| N-029 | Sair da frente com a caixa aberta pede confirmação; terminal escolhido memorizado por posto | `FrenteCaixa.tsx:119-131` e `:17-30` | — |
| N-030 | Trocar de terminal activo a meio (voltar à abertura de caixa) | `FrenteCaixa.tsx:100-107,137` | — |

### 7.3 Frente de caixa e venda

| ID | Funcionalidade | Prova (código) | Teste |
|---|---|---|---|
| N-031 | Cartão «Frente de Caixa Imersiva» e modo imersivo em ecrã inteiro (PC/Tablet/Mobile) | `FrenteCaixa.tsx:36-90`; `ModoImersivo.tsx` | — |
| N-032 | Cartão «Abertura de Caixa» (terminal, fundo, operador, meios, aviso das sessões antigas; continuar sessão já aberta) | `AberturaCaixa.tsx` | E2E |
| N-033 | Venda: FR numa transacção pelo motor de Vendas (série do terminal, AGT, hash, stock, CMV) com bloqueio da sessão | `ServicoVendasPOS.php:43-86` | T; E2E |
| N-034 | Preço com IVA, desconto global % e total pela regra AGT (`calcularComIva`), espelhados ao cêntimo no cliente | `CalculadoraDocumento.php:44-70`; `calculos.ts:45-70` | T; V:calculos |
| N-035 | Desconto e alteração de preço exigem `pos_desconto` | `POSController.php:196-202` | T |
| N-036 | Pagamentos mistos (até 50); troco só em numerário, valor gravado líquido; TPA+transferência ≤ total | `ServicoVendasPOS.php:94-134`; `calculos.ts:157-215`; `PainelPagamentos.tsx` | T; V:calculos; E2E |
| N-037 | Transferência exige n.º do comprovativo | `ServicoVendasPOS.php:108-110` | T |
| N-038 | Preço unitário > 2 casas recusado; normalizado a 2 casas | `POSController.php:192`; `ServicoVendasPOS.php:58-61` | T |
| N-039 | Stock verificado no armazém do terminal com lock, por produto que movimenta stock | `ServicoVendasPOS.php:151-165` | T |
| N-040 | Cliente: indicado → padrão do terminal → «Consumidor Final» (criado se faltar) | `ServicoVendasPOS.php:137-148` | — |
| N-041 | Operador gravado = quem regista a venda (nome completo) | `ServicoVendasPOS.php:82` | T |
| N-042 | Arredondamento AGT gravado à parte do desconto (`vendas.arredondamento_agt`); `pos_pagamentos`, `pos_troco`, `pos_operador` | `ServicoDocumentosVenda.php:110,175,565-572` | T |
| N-043 | Grelha de produtos: pesquisa (código/nome sem acentos), Todos/Com stock/Sem stock, ocultar imagens, grelha/lista, chips de categoria com contagem e ícone, etiqueta de stock (cores), «Serviço» | `GrelhaProdutos.tsx`; `frente.ts:61-110`; `produtos.ts` | V:frente |
| N-044 | Leitura de código de barras «3*COD»/«COD*3» e Enter na pesquisa | `produtos.ts:18-37` | V:frente (parcial) |
| N-045 | Imagens dos produtos nos cartões (URL autenticado ou R2 público validado) | `frente.ts:36-50,110-120` | — |
| N-046 | Stock em tempo útil do armazém do terminal na grelha | `ServicoFrentePOS.php:31-42`; `frente.ts:27-35` | PF |
| N-047 | Atalhos F2, F4, F8, F9, Esc | `atalhos.ts`; `PainelVenda.tsx` (cabeçalho) | — |
| N-048 | Preferências da frente por utilizador (dispositivo, disposição, imagens, filtro de stock) | `frente.ts:122-160` | V:frente |
| N-049 | Selector de cliente com pesquisa e «tornar cliente» | `routes/api/r3_vendas.php`; `PainelVenda.tsx` | R3 (parcial) |
| N-050 | «Criar novo cliente» na frente (ficha mínima, conta classe 31 obrigatória) | `NovoClienteModal.tsx` | — |
| N-051 | Talão de venda (térmico 58/80 mm ou A4) com base, IVA, desconto, arredondamento, pagamentos, troco, rodapé e identidade da empresa; impressão automática ou reimpressão | `impressao.ts:59-179` | V:impressao |
| N-052 | Configuração de impressão do posto (formato, largura, automático, pré-visualizar/directo, rodapé) no navegador | `ConfigImpressao.tsx`; `impressao.ts:13-58` | V:formularios |
| N-053 | Operador só-POS consulta clientes, catálogo, categorias e stock do armazém sem poder criar fichas | `POSPermissoesConsultaTest`; `CatalogoServicosPOSController.php:26` | PP |

### 7.4 Restaurante (mesas)

| ID | Funcionalidade | Prova (código) | Teste |
|---|---|---|---|
| N-054 | Mesas por terminal RESTAURANTE (nome único, ordem, activa) com CRUD | `ServicoMesasPOS.php:56-93`; `MesasPOSController.php` | TM |
| N-055 | Criar várias mesas de uma vez (Mesa 1, Mesa 2, Terraço 3…) | `PainelMesas.tsx` | — |
| N-056 | Conta aberta por mesa guardada no servidor («Suspender»): linhas, desconto, cliente, observações, operador | `ServicoMesasPOS.php:110-139` | TM |
| N-057 | Bloqueio optimista entre postos (`versao`; 409 `CONTA_MESA_ALTERADA`) | `ServicoMesasPOS.php:189-195` | TM (parcial) |
| N-058 | Libertar mesa (sem linhas → conta ANULADA, auditada) | `ServicoMesasPOS.php:119-126` | — |
| N-059 | Cobrar a mesa: FR com `nome_tabela` + fecho da conta na mesma transacção; cobrança directa sem conta suspensa também fica registada | `ServicoMesasPOS.php:147-172` | TM |
| N-060 | Mapa de mesas na frente, com total da conta e operador | `PainelMesas.tsx`; `mesas.ts` | V:mesas |
| N-061 | Talão de consulta da mesa (impresso, sem facturar) | `PainelVenda.tsx`; `impressao.ts:207-241` | V:impressao |
| N-062 | Eliminar mesa: bloqueado com conta aberta; com histórico fica inactiva | `ServicoMesasPOS.php:81-93` | TM |

### 7.5 Hotelaria

| ID | Funcionalidade | Prova (código) | Teste |
|---|---|---|---|
| N-063 | Mapa de quartos (livre/ocupado/atrasado) com hóspede e total em aberto | `ServicoEstadiasHotel.php:40-57`; `PainelQuartos.tsx`; `quartos.ts` | V:quartos |
| N-064 | Quarto = produto `e_quarto` com preços por hora/dia e horas mínimas; edição das tarifas (`hotel_quartos`) | `HotelariaController.php:42-52`; `ServicoEstadiasHotel.php:32-37` | — |
| N-065 | Check-in: quarto livre, hóspede-cliente, modo DIA (inteiro) ou HORA (≥ mínimo, fora da janela), nº de hóspedes, observações, preço | `ServicoEstadiasHotel.php:75-111,251-271` | TH |
| N-066 | Quarto ocupado: lock + índice único parcial (`QUARTO_OCUPADO`) | `ServicoEstadiasHotel.php:80-88`; índice `uq_estadias_hotel_produto_quarto_id` | TH |
| N-067 | Regras horárias por terminal (entrada 14:00, saída 12:00, tolerância 60, bloqueio 21:00-08:00) | `RegrasHotel.php:18-82` | TH; V:quartos |
| N-068 | Alterar estadia (hóspede, entrada, quantidade, preço, hóspedes, notas) com saída prevista recalculada e histórico | `ServicoEstadiasHotel.php:119-155` | — |
| N-069 | Consumos do quarto (substituem a lista; produto não bloqueado e não quarto; preço do produto por omissão) | `ServicoEstadiasHotel.php:162-183` | TH |
| N-070 | Anular check-in (só sem consumos e com motivo ≥ 3 caracteres) | `ServicoEstadiasHotel.php:186-205` | TH |
| N-071 | Simular check-out (facturas, totais, propostas de saída tardia) | `ServicoCheckoutHotel.php:46-52,105-160` | TH |
| N-072 | Saída tardia: proposta de recálculo (RECALCULAR) ou manter (MANTER), decisão obrigatória | `RegrasHotel.php:67-82`; `ServicoCheckoutHotel.php:123-140` | TH |
| N-073 | Check-out: uma FR por quarto ou factura única; desconto %; pagamentos rateados com acerto do cêntimo e troco na última; tudo numa transacção | `ServicoCheckoutHotel.php:59-98,170-201` | TH |
| N-074 | Ligação factura ↔ estadias em `vendas_estadias_hotel` | `ServicoCheckoutHotel.php:83` | TH |
| N-075 | Preço do alojamento ≠ tarifa, preço de consumo alterado e desconto exigem `pos_desconto` | `HotelariaController.php:85,116,144,168` | — |
| N-076 | Lista e detalhe de estadias com filtros e proposta de atraso | `HotelariaController.php:54-74`; `ServicoEstadiasHotel.php:60-67` | — |
| N-077 | Ecrã Hotelaria (mapa, estadias, histórico, tarifas) e hotelaria dentro da frente de caixa (painel da estadia no carrinho) | `Hotelaria.tsx`; `Estadia.tsx`; `PainelEstadia.tsx` | V:quartos |
| N-078 | Talão do check-out de cada factura-recibo (automático se o posto o tiver activo) | `Checkout.tsx` (docblock) | — |
| N-079 | KPIs de hotelaria no relatório de gestão (estadias, ocupação, ADR, RevPAR) | `ModuloServicos.php` | GestaoRelatorios (genérico) |

### 7.6 Lavandaria e alfaiataria

| ID | Funcionalidade | Prova (código) | Teste |
|---|---|---|---|
| N-080 | Tabela de peças (PC####): designação, tecido, cor, unidade (peça/kg), preço base, preços por serviço, activa; sem duplicados | `ServicoTabelasLavandaria.php:39-78` | TL |
| N-081 | Tabela de serviços (SV####) = produtos com grupo LAVANDARIA/ALFAIATARIA, conta de proveitos 62, IVA, prazo, exige orçamento | `ServicoTabelasLavandaria.php:80-118` | TL |
| N-082 | Definições da lavandaria (armazenagem activa/dias grátis/% dia, % adiantamento, % urgência, factor de prazo, taxas de recolha e entrega, dias de reclamação, contas das taxas e das indemnizações, facturar no adiantamento) | `ServicoConfigLavandaria.php:30-105` | TL |
| N-083 | Produtos das taxas LAV-URG/ARM/REC/ENT criados com a conta das definições | `ServicoTabelasLavandaria.php:139-155` | TL |
| N-084 | Registo da ordem na recepção (cliente, linhas com estado à entrada e dano, urgente, recolha/entrega ao domicílio, observações) | `ServicoOrdensLavandaria.php:50-138`; `Recepcao.tsx` | TL; V:Recepcao |
| N-085 | Numeração `OS/<terminal>/<ano>/n`, etiquetas por peça, histórico da ordem | `ServicoOrdensLavandaria.php:80-93,125-128` | TL |
| N-086 | Cálculo de urgência, prazo, taxas de domicílio (produto com IVA) e total | `ServicoOrdensLavandaria.php:70-108` | TL; V:formularios |
| N-087 | Adiantamento mínimo do Consumidor Final; valor ≤ total | `ServicoOrdensLavandaria.php:110-117` | TL |
| N-088 | Restrição aos serviços configurados no terminal | `ServicoOrdensLavandaria.php:203-221` | TL |
| N-089 | Estado da ordem derivado das linhas (7 estados) | `RegrasLavandaria.php:56-71` | TL; V:indicadores |
| N-090 | Execução por linha: iniciar (RECEBIDA→EM_EXECUCAO) e pronta (→PRONTA) | `ServicoOrdensLavandaria.php:141-176` | TL |
| N-091 | Orçamentos: aprovar (valor, canal, nota) ou recusar (motivo), individual ou em massa | `ServicoOrdensLavandaria.php:184-244` | TL |
| N-092 | Alterar estado em massa (EM_EXECUCAO, PRONTA, RECEBIDA, ANULADA) com executante e motivo | `ServicoOrdensLavandaria.php:252-324` | TL |
| N-093 | Atribuir responsável (em massa; data; aplicar a todas/em curso; nota) e retirar | `ServicoOrdensLavandaria.php:331-385` | TL |
| N-094 | Anular ordem (sem documentos nem recebimentos; `lav_anular`, também pela alteração de estado) | `ServicoOrdensLavandaria.php:387-399`; `LavandariaController.php:188-215` | TL |
| N-095 | Materiais de alfaiataria: guia de consumo ao custo médio + CMV imediato; sem stock recusa | `ServicoOrdensLavandaria.php:401-433` | TL |
| N-096 | Lista de ordens com filtros (activas, atrasadas, não levantadas, orçamentos, sem responsável), KPIs, carga por responsável, prazo de levantamento; detalhe com indicadores | `ServicoOrdensLavandaria.php:435-…`; `RegrasLavandaria.php:200-223`; `Ordens.tsx` | PC; V:indicadores |
| N-097 | Receber pagamento com recibo `RC-LAV/…` (ADIANTAMENTO/PAGAMENTO), pagamentos validados pelas regras do POS | `ServicoCaixaLavandaria.php:97-187,212-232` | TL |
| N-098 | Facturação: FR (total = recebido e sem saldos) ou FT em conta corrente na série do terminal; valor da linha = valor do documento | `ServicoCaixaLavandaria.php:118-170,378-387` | TL |
| N-099 | Facturar pendente (linhas confirmadas) | `ServicoCaixaLavandaria.php:235-251` | TL |
| N-100 | Distribuição dos recebimentos pelas facturas mais antigas primeiro | `ServicoCaixaLavandaria.php:192-210` | TL |
| N-101 | Simulação e entrega de peças prontas com taxa de armazenagem (IVA do produto); Consumidor Final paga o saldo antes de entregar | `ServicoCaixaLavandaria.php:257-334`; `RegrasLavandaria.php:166-181` | TL |
| N-102 | Anular recibo enquanto a sessão está aberta (FR corrige-se com NC) | `ServicoCaixaLavandaria.php:339-361` | TL |
| N-103 | Danos e reclamações: registar na linha, decidir (comprovativo/aprovar/recusar), pagar por documento de tesouraria; segregação decidir × pagar | `ServicoReclamacoesLavandaria.php:30-112`; `Reclamacoes.tsx` | TL |
| N-104 | Relatório da lavandaria por período e terminal (≤ 400 dias) | `ServicoRelatorioLavandaria.php:22-…`; separador «Relatórios» de `Lavandaria.tsx` | V:relatorio |
| N-105 | Importação de peças, serviços e preços (Excel/CSV) com simulação linha a linha e gravação com ponto de salvaguarda por linha | `ServicoImportacaoTabelasLavandaria.php`; `ImportacaoLavandariaController.php`; `ImportarTabela.tsx` | TLI |
| N-106 | Impressões: talão da OS (2 vias), etiquetas das peças, recibo RC-LAV, factura | `lavandaria/impressoes.ts`; `DetalheOrdem.tsx` | — |
| N-107 | Frente de caixa da lavandaria (Nova ordem + Ordens e levantamentos), recepção com cartões por peça, cliente com pesquisa e pagamento na confirmação | `FrenteLavandaria.tsx`; `Recepcao.tsx` | V:Recepcao |
| N-108 | Backoffice com separadores Peças · Serviços · Ordens · Relatórios · Danos · Definições | `Lavandaria.tsx:25-31`; `Tabelas.tsx` | — |
| N-109 | Lista de colaboradores activos para atribuição (por `lav_ordens`) | `LavandariaController.php:130-138` | TL |
| N-110 | Recibos e facturas da lavandaria entram nos totais da sessão (por meio, numerário, transferências) sem somar a `total_vendas` | `ServicoFontesLavandariaPOS.php:55-72` | TL |

### 7.7 Integração contabilística e desvios

| ID | Funcionalidade | Prova (código) | Teste |
|---|---|---|---|
| N-111 | Integrar sessão fechada: um lançamento por data (diário GEPOS), D transitórias / C proveitos+IVA do próprio documento / CMV | `ServicoContabilizacaoPOS.php:41-80,239-275` | T; E2E |
| N-112 | Pré-visualização da integração (executa e reverte; devolve lançamentos ou impedimentos) | `ServicoContabilizacaoPOS.php:91-119`; `ParidadeContasPOSController.php:29-60`; `PreVisualizacaoIntegracao.tsx` | PC; E2E |
| N-113 | Integração em lote (sessões seleccionadas, impedimentos por sessão) | `ParidadeContasPOSController.php:61-…`; `Integracao.tsx:94-127` | PC |
| N-114 | Integração da lavandaria (FT D cliente/C proveitos+IVA; recibos D transitória/C cliente) | `ServicoFontesLavandariaPOS.php:88-181` | TL |
| N-115 | Descontabilizar por estorno com motivo; bloqueado com prestação registada ou deliberação manual lançada | `ServicoContabilizacaoPOS.php:121-149` | TP |
| N-116 | Deliberar desvio acima da tolerância (SOBRA_PROVEITO, FALTA_CUSTO, FALTA_OPERADOR, SEM_EFEITO), coerente com o sinal | `ServicoContabilizacaoPOS.php:152-183` | T; TP |
| N-117 | Segregação: quem operou a sessão não delibera o próprio desvio | `ServicoContabilizacaoPOS.php:171-174` | T |
| N-118 | Anular deliberação manual por estorno (bloqueado se o numerário já foi prestado) | `ServicoContabilizacaoPOS.php:186-210` | TP |
| N-119 | Lançamento do desvio (sobra/falta) com contas das definições e transitória do numerário | `ServicoContabilizacaoPOS.php:213-236` | T |
| N-120 | Desvio automático dentro da tolerância lançado com a integração (ou no fecho se sem vendas) | `ServicoContabilizacaoPOS.php:72-74`; `ServicoSessoesPOS.php:186-188` | T |
| N-121 | Ecrã Desvios (por deliberar com justificação; histórico; desfazer; ver sessão; imprimir Z) | `Desvios.tsx` | V:regras |
| N-122 | Ecrã Integração (por integrar / integradas; descontabilizar) | `Integracao.tsx` | E2E |

### 7.8 Prestação de contas

| ID | Funcionalidade | Prova (código) | Teste |
|---|---|---|---|
| N-123 | Itens por sessão: `NUM:<meio>`, `TPA:<meio>`, `TRF:<venda>:<meio>`, com estado POR_PRESTAR/PRESTADO/BLOQUEADO e motivo do bloqueio | `ServicoPrestacaoContasPOS.php:57-122,335-352` | TP |
| N-124 | Numerário → movimento REC (ou PAG se negativo) na folha de caixa aberta da conta de liquidação; valor = sistema + desvio deliberado | `ServicoPrestacaoContasPOS.php:249-277` | TP |
| N-125 | TPA → RECEBIMENTO no banco (conta 43, Kz) pelo valor do sistema; comissão sugerida editável, deduzida ou paga à parte | `ServicoPrestacaoContasPOS.php:279-313` | TP |
| N-126 | Transferência → RECEBIMENTO por comprovativo com cliente e n.º do documento | `ServicoPrestacaoContasPOS.php:315-331` | TP |
| N-127 | Prestar todas as transferências de uma vez (erros por item) | `ServicoPrestacaoContasPOS.php:167-186`; `PrestacaoContasController.php` | — |
| N-128 | Anular liquidação (sem apagar): retira movimento de folha aberta, anula documentos pendentes; integrado → erro | `ServicoPrestacaoContasPOS.php:189-231` | TP |
| N-129 | Estado da liquidação da sessão (PENDENTE/PARCIAL/LIQUIDADA) recalculado | `ServicoPrestacaoContasPOS.php:234-245` | TP |
| N-130 | Segregação: quem operou a sessão não presta contas dela (servidor) | `ServicoPrestacaoContasPOS.php:135-137` | TP |
| N-131 | Tesouraria recusa alterar/anular movimentos e documentos ligados a liquidações registadas | `ADR-048`; `Services/Tesouraria` (`MOVIMENTO_POS`/`DOCUMENTO_POS`) | TP |
| N-132 | Ecrã Prestação (itens, modal de prestar com folha/conta/data/comissão, detalhe e anulação de liquidações) | `Prestacao.tsx` | — |

### 7.9 Relatórios

| ID | Funcionalidade | Prova (código) | Teste |
|---|---|---|---|
| N-133 | Painel de relatórios (período, terminal, operador): KPIs (facturação, documentos, ticket médio, desvios, sessões abertas/por integrar/por prestar, diferenças TPA) | `ServicoRelatoriosPOS.php:33-92` | TP |
| N-134 | Por meio de pagamento (inclui FR migradas sem detalhe), por produto, por terminal, por operador, lista de Z, diferenças TPA | `ServicoRelatoriosPOS.php:78-185` | TP |
| N-135 | Impressão A4 e exportação do painel | `documentos.ts:163-254`; `Relatorios.tsx` | V:documentos |
| N-136 | KPIs «POS e Serviços» no relatório de gestão e fluxos de processo POS/Lavandaria | `ServicoRelatoriosPOS.php:94-110`; `FluxoPOS.php`; `FluxoLavandaria.php` | GestaoFluxosTest (genérico) |

### 7.10 POS de armazém

| ID | Funcionalidade | Prova (código) | Teste |
|---|---|---|---|
| N-137 | Artigos de stock com a quantidade do armazém e o preço (cartões, filtros Todos/Com/Sem stock, pesquisa) | `ServicoAcertoStockPOSArmazem.php:33-48`; `Balcao.tsx` | PA |
| N-138 | Venda ao balcão = guia de saída `VENDA_BALCAO` (`GE POS AAAA/NNNN`), custo médio, CMV no diário GS (guia por contabilizar se faltarem contas) | `ServicoPOSArmazem.php:44-60`; `ServicoGuiasSaida.php:65-90` | TA |
| N-139 | Histórico de vendas ao balcão e detalhe da guia | `POSArmazemController.php:27-45` | TA |
| N-140 | Fila de picking (encomendas NE por entregar; POR_EXPEDIR / EXPEDIDA_PARCIAL) | `ServicoPOSArmazem.php:68-91` | TA |
| N-141 | Lista de recolha com stock por armazém e disponibilidade | `ServicoPOSArmazem.php:93-128` | TA |
| N-142 | Expedição = conversão NE→GR com lock, stock de todas as linhas, todas as linhas recolhidas (`PICKING_INCOMPLETO`) | `ServicoPOSArmazem.php:130-152` | TA; V:posArmazem |
| N-143 | Acerto do stock do armazém (diferença global × soma dos armazéns, limitado ao stock local, movimento AJUSTE, repõe o total) | `ServicoAcertoStockPOSArmazem.php:51-127` | PA |
| N-144 | Senhas: tirar (P-001… por dia e armazém), chamar a próxima (lock), repetir; monitor com voz e histórico de 8 | `ServicoSenhasPOSArmazem.php:27-105`; `Senhas.tsx` | PA |

### 7.11 Transversais

| ID | Funcionalidade | Prova (código) | Teste |
|---|---|---|---|
| N-145 | Permissões: ecrãs `pos*` com `_view`, 24 tarefas, perfil «Operador POS / Lavandaria», pares de segregação | `catalogo.json:1228-1405,1746-1775,2980-2987,3287-3305` | PP; V:regras |
| N-146 | Isolamento por empresa (RLS + `PertenceEmpresa`) e auditoria (`Auditavel`) em todos os modelos | `IsolamentoRls`; `Models/Base/TerminalPOSBase.php:29` | TM (outra empresa não vê) |
| N-147 | Vendas POS não se (des)contabilizam individualmente | `ServicoContabilizacaoVendas.php:106,210` | — |
| N-148 | 10 validações de dados do POS, hotelaria e lavandaria | `ServicoValidacoesDados.php:125-200` | — |
| N-149 | Migração ETL do POS, lavandaria e hotelaria (chaves JSON inglesas → portuguesas, valores de linha, pagos das facturas) | `ServicoMigracaoPOS.php`; `ServicoMigracaoLavandaria.php`; `ServicoMigracaoHotelaria.php` | TH; TL; `CorrecoesPosMigracaoTest` |
| N-150 | Fluxo de processos e relatório de gestão com origem POS | `FluxoPOS.php`; `FluxoLavandaria.php`; `ModuloServicos.php` | GestaoFluxosTest |
| N-151 | Navegação: menu Vendas › POS (4 entradas), separadores do módulo com `_view`, pesquisa Ctrl+K, favoritos | `menuLegado.ts:67-72`; `BarraPos.tsx`; `indicePesquisa.gerado.ts` | — |
| N-152 | Cabeçalho do módulo com título e subtítulo do legado | `BarraPos.tsx:21-39` | — |
| N-153 | Teste ponta-a-ponta: abrir, vender com pagamento misto e troco, X, Z, pré-visualizar e integrar | `frontend/e2e/pos.e2e.ts:17,110` | E2E |

## 8. Estado de qualidade, lacunas e dívida técnica

### 8.1 Lacunas funcionais evidentes (face ao que um POS completo tem; confirmar contra o legado na matriz)

1. **Devolução/anulação/consulta de vendas a partir do POS**: não há ecrã nem rota para devolver ou reimprimir vendas anteriores; só o talão da venda acabada de emitir se reimprime (acção manual no modal pós-venda, `PainelVenda.tsx:125-130`), e o ecrã de detalhe da sessão reimprime o Z. A correcção de uma FR faz-se por NC no módulo Vendas (permitida desde ADR-071, decisão 4). `GET /pos/sessoes/{id}` devolve as vendas da sessão, mas o ecrã (`DetalheSessao.tsx`) é de leitura.
2. **Movimentos de caixa avulsos** (suprimento, sangria, despesas de caixa durante a sessão): inexistentes; o numerário esperado = fundo + vendas em numerário (`ServicoSessoesPOS.php:105`).
3. **Fiscalidade no talão**: sem hash curto, ATCUD, QR nem «processado por programa validado» (`impressao.ts:144-179`); a FR está selada pelo motor AGT, mas o talão do POS não a mostra. Os recibos RC-LAV não vão no SAF-T (ADR-049, ainda aberto).
4. **Desconto por linha, promoções, cartões de fidelização, gorjetas, divisão de conta, transferência de mesa, impressão de pedidos à cozinha/bar**: não existem (só desconto global %).
5. **Sessão**: não há reabertura de uma sessão fechada, nem fecho forçado de sessões antigas por um gestor (o aviso só informa), nem Z parcial/de turno.
6. **Operação offline/fila**: nenhuma. Todas as acções exigem o servidor.
7. **Atalho «IVA em falta»** na integração (matriz de 2026-10-02); não existe.
8. **Configuração de impressão só por posto** (localStorage): perde-se ao trocar de computador/navegador; sem impressão directa por ESC/POS, só HTML via diálogo do navegador.
9. **Senhas «públicas»**: o monitor exige sessão autenticada (`Senhas.tsx` usa polling autenticado); não há URL de TV sem login.
10. **Moeda**: POS só em Kz (`POS_TIPO_INVALIDO`); sem pagamento em moeda estrangeira.
11. **Notificações**: nenhuma (p. ex. desvio por deliberar, sessão esquecida aberta); só as validações de dados.
12. **Hotelaria**: reservas, mapa de ocupação futura, housekeeping, pré-pagamentos e cobrança parcial de estadia não existem; consumos só descontam stock no check-out (a venda pode ser recusada nessa altura por falta de stock, `ServicoCheckoutHotel` → `ServicoVendasPOS::exigirStock`).
13. **Lavandaria**: sem SMS/WhatsApp ao cliente na ordem pronta; sem rotas/portaria de recolha ao domicílio (só taxa e morada).
14. **POS de armazém**: não emite factura/pagamento (só guia, como o legado); sem leitor de código de barras próprio (o inventário tem); sem reserva de stock no picking.

### 8.2 Dívida técnica e riscos para a reconstrução

| # | Item | Evidência | Risco |
|---|---|---|---|
| D-01 | **Sem unicidade na base** para `sessoes_pos.codigo_sessao`, `numero_z`, `pedidos_lavandaria.numero_encomenda`, `pagamentos_lavandaria.numero_recibo` (só `ServicoNumeracao`); `configuracoes_lavandaria` sem UNIQUE por empresa; `vendas_estadias_hotel` e `pedidos_lavandaria_faturas` sem chave primária | `2026_09_30_100900_criar_tabelas_pos.php:240-244,268-273,396-405` | Duplicados por falha de numeração ou ETL |
| D-02 | CHECK só em 3 colunas (`tipo`, `estado_desvio`, `contas_mesa_pos.estado`); estados de sessão, contabilização, liquidação, estadia, ordem, pagamento, reclamação e senha são texto livre | idem | Estados inválidos sem barreira da base |
| D-03 | Tabelas geradas por `ferramentas/gerador/esquema_extra.mjs` + migração «não editar à mão»: alterações exigem regenerar; `produtos.servicos_pos` e `terminais_pos.lavandaria_servicos_ids` são colunas fora do esquema gerado (migrações com guardas) | `DECISOES.md` (ADR-068 «Colunas fora do esquema gerado»); migrações 2026_10_17 e 2026_10_21 | Divergência esquema gerado × migrações |
| D-04 | `ServicoMesasPOS` usa `DB::table` em vez dos modelos `MesaPOS`/`ContaMesaPOS` (docblock diz «até os models serem gerados» e cita uma migração `2026_10_06_100100` que já não existe) | `ServicoMesasPOS.php:27,38-139` | Perde `Auditavel`/`PertenceEmpresa` e casts; auditoria só manual |
| D-05 | Colunas de tipo impróprio herdadas do legado: `fator_prazo_urgencia` inteiro guardado em % (0,5 → 50) e lido em % ou fracção; `pecas_lavandaria.tecido` (20) e `cor` (10) curtos (validados no serviço) | `ServicoConfigLavandaria.php:16-17`; `ServicoTabelasLavandaria.php:22` | Conversões frágeis |
| D-06 | Muitos JSON como modelo de dados (`itens`, `extras`, `recolha`, `entrega`, `atribuicoes`, `historico_alteracoes` em `pedidos_lavandaria`; `itens` em `estadias_hotel`; `meios_pagamento`, contadores, `totais_por_metodo`, `fechos_tpa`, `deliberacao` em terminais/sessões; `linhas` em `contas_mesa_pos`) | `esquema_bd.json` | Sem integridade referencial nem relatórios SQL simples; migrações de dados custosas |
| D-07 | Serviços grandes: `ServicoOrdensLavandaria` 609 l., `Prestacao.tsx`/`Terminais.tsx`/`PainelVenda.tsx`/`Ordens.tsx`/`Recepcao.tsx` 460-700 l. cada | contagens | Difícil de testar por partes |
| D-08 | Validação no controlador com `$r->validate` (sem FormRequests/Resources) e respostas devolvem modelos completos (`toArray()`); fora do padrão de outros módulos | `POSController.php:54-61,176-179,189-195` | Acoplamento API↔colunas |
| D-09 | Comentário desactualizado em `ServicoCaixaLavandaria.php:39-40,374-377` («Pendente de alteração nas Vendas: a Factura sai sem `pos`») — já resolvido (a FT sai em modo POS) | `ServicoCaixaLavandaria.php:39,374-387` | Confunde |
| D-10 | Cobertura de testes de componente do frontend baixa nos ecrãs grandes (4.2) e só 2 cenários e2e | secção 4 | Regressões de UI |
| D-11 | `ANALISE_PARIDADE_2026-10-02.md`, ADR-050 e dicionário de dados estão desactualizados em vários pontos do POS; `RELATORIO.md` de `verificacao_visual` referenciado em código e rotas não existe no repositório | secção 5 | Matriz de paridade enganadora |
| D-12 | O backend não gera PDF/talões: todas as impressões do POS são HTML no cliente (CSP sem `onclick` inline; reimpressão via iframe) | `impressao.ts:296-339` | Sem auditoria do que foi impresso; sem talão fiscal garantido |
| D-13 | Preferências de impressão e de frente em `localStorage` (convenientes, não fiáveis) | `impressao.ts:34-57`; `frente.ts:135-160` | Perda de configuração |
| D-14 | `ServicoSessoesPOS::totais` recalcula tudo a cada leitura (FR, recibos, lavandaria) com N+1 por pagamentos; aceitável hoje, a vigiar com volume | `ServicoSessoesPOS.php:79-106` | Desempenho em sessões longas |
| D-15 | Comando `erp:migracao:pos-carga` com nome enganador (pós-carga ≠ POS) | `PosCargaMigracao.php` | Confusão de nomenclatura |

### 8.3 Pontos fortes a preservar na reconstrução

Transacções com `lockForUpdate` em sessão, estadia, mesa e encomenda; segregação de funções no servidor; numeração por serviço central a continuar os contadores do legado; contabilização por estorno (nunca apagar linhas); pré-visualização por transacção revertida; motor único de preços/IVA com cópia testada no cliente; reutilização do motor de Vendas e de Stock (sem lógica duplicada); aviso de sessões antigas e validações de dados; RLS/auditoria por omissão em todas as tabelas.
