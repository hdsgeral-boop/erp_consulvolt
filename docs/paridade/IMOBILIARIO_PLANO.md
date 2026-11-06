# Gestão Imobiliária — plano de importação para o ERP novo

Data: 2026-11-06 · ramo `paridade-bd-front` · **só planeamento**: nenhum código foi alterado. Base: `IMOBILIARIO_INVENTARIO.md` e anexos A–F em `docs/paridade/imobiliario/`.

## 0. Princípios e regras do sistema novo que se mantêm

1. **Método** (ADR «Método de Trabalho», POS): inventário → matriz → plano → ondas; cada fase fecha com commit próprio (`git add` só dos ficheiros da fase), suite backend (`docker exec erp_app_e2e php artisan test`, **nunca duas em paralelo**) + frontend (vitest) e, no fim, `npm run build` (o frontend de teste serve de `frontend/dist`). Sem push. Migrações aditivas e idempotentes (`Schema::hasTable/hasColumn`, `CREATE INDEX IF NOT EXISTS`, CHECK/UNIQUE como `NOT VALID` e validados depois), ensaiadas na cópia da produção.
2. **Isolamento por empresa**: toda a tabela tem `empresa_id` + RLS via `IsolamentoRls` (padrão das migrações do POS) + `GRANT … TO erp_app`; modelos com `PertenceEmpresa` e `Auditavel`; `ContextoEmpresa` nos serviços. Não usar `imo_company_id`.
3. **Modelos `*Base`**: as tabelas novas **não** vêm do dicionário legado (não existem no `esquema.json`); seguem a via das tabelas pós-paridade do POS (migração manual + modelo escrito à mão que estende `ModeloBase`, com `$moduloAuditoria='Imobiliário'`), documentadas em `docs/dicionario/DICIONARIO_DADOS.md`. O gerador (`ferramentas/gerador/gerar_esquema.mjs`) só passa a gerar `*Base` se P2 decidir acrescentá-las ao esquema.
4. **Controller fino → FormRequest → Service → Resource**; `ErroNegocio(mensagem, código, http, contexto)` com mensagens seguras (`docs/qualidade/MENSAGENS_ERRO.md`); nada de SQL/stack ao cliente.
5. **Decimais**: dinheiro em `numeric(18,2)` e percentagens em `numeric(9,4)`; cálculo com bcmath (como `CalculadoraDocumento`/`CalculadoraAmortizacoes`), nunca float. Arredondamento «meio para cima» ao cêntimo, que reproduz o do legado nos casos testáveis; os casos de meio-cêntimo em float do JS são um risco documentado (fixtures à mão, ver §10).
6. **Efeitos laterais** (emitir factura, enviar AGT, lançar contabilidade) **depois do commit** (`after_commit`/outbox, ADR-080), repetíveis e idempotentes por chave natural (`renda_id`, `negocio_id`, `reserva_id`).
7. **Corrigir, não copiar, os defeitos** do legado listados em Inventário §4.1, com decisão explícita (P3).

## 1. Arquitectura alvo (visão)

```
frontend/src/modulos/imobiliario/      (ecras.ts, 13 ecrãs lazy)
backend/app/Http/Controllers/Api/Imobiliario/*Controller.php
backend/app/Http/Requests/Imobiliario/*Request.php   backend/app/Http/Resources/Imobiliario/*Resource.php
backend/app/Services/Imobiliario/Servico*.php  (+ Calculadora* puras e testáveis)
backend/app/Models/Imobiliario…  (modelos)       backend/routes/api/imobiliario.php
backend/database/migrations/2026_12_xx_*_imobiliario_*.php
backend/resources/permissoes/catalogo.json  (módulo «imobiliario», editado à mão)
```

Calculadoras puras (sem BD, 100% testáveis com números à mão): `CalculadoraRenda`, `CalculadoraActualizacao`, `CalculadoraMora`, `CalculadoraImpostosTransmissao`, `CalculadoraDetencao`, `CalculadoraReserva` (preço, prazo, escala de cancelamento), `CalculadoraRentabilidade`, `GeradorFraccoes`/`GeradorVagas` (designações). São o equivalente direto de `CalculadoraAmortizacoes` (Activos).

## 2. Tabelas novas propostas (21; português, plural, sem prefixo `imo_`; `empresa_id` em todas)

Convenção: `id` bigserial, `empresa_id` FK `empresas` restrict, `criado_em/atualizado_em timestamptz`, `criado_por/atualizado_por` quando o legado os tem; CHECK de enum com nome `ck_<tabela>_<campo>`; índices `ix_<tabela>_<cols>` com `empresa_id` à cabeça; UNIQUE parciais onde há nulos.

| # | Tabela (legado) | Colunas principais | UNIQUE / CHECK / FK / índices |
|---|---|---|---|
| 1 | `imoveis` (`imo_properties`) | `codigo` varchar(20), `nome`, `tipo`, `papel`, `n_pisos`, `piso_inicial`, `n_habitacoes`, `proprietario_terceiro_id`, `ativo_edificio_id`, `morada`, `municipio`, `provincia`, `coordenadas`, `matriz_predial`, `registo_predial`, `licenca_utilizacao`, `area_terreno/area_bruta/area_util` num(14,2), `valor_patrimonial` num(18,2) null, `valor_patrimonial_data`, `isencao_detencao`, `valor_aquisicao`, `data_aquisicao`, `estado`, `notas` | UNIQUE(`empresa_id`, lower(`codigo`)), UNIQUE parcial (`empresa_id`,`matriz_predial`) onde não nulo; CHECK papel/tipo/estado/isenção; CHECK `area_util<=area_bruta`; FK `ativo_edificio_id`→`ativos_imobilizados`(tipo EDIFICIO validado no serviço); CHECK `papel<>'PROPRIO' OR proprietario IS NULL` e `papel IN ('ARRENDADO','MEDIACAO') → proprietario NOT NULL`; ix (`empresa_id`,`papel`) |
| 2 | `fracoes` (`imo_units`) | `imovel_id`, `codigo`, `designacao`, `natureza`, `park_afectacao`, `park_fracao_id`, `park_tipo`, `park_porte`, `park_altura_max/comprimento/largura`, `park_tomada`, `park_distico`, `park_incluido_na_renda`, `porta_id`, `bloco`, `andar`, `numero`, `piso`, `tipologia`, `finalidade`, áreas, `quartos`, `casas_banho`, `mobilada`, `estado`, `estado_desde`, `estado_motivo`, `matriz_predial`, `valor_patrimonial`, `renda_referencia`, `venda_referencia`, `ordem`, `notas` | UNIQUE(`imovel_id`, lower(`codigo`)), UNIQUE(`imovel_id`, lower(`designacao`)); FK `imovel_id` cascade-restrict, `porta_id`→`portas_edificios`, `park_fracao_id`→`fracoes`; CHECK natureza/estado/tipologia/finalidade; ix (`empresa_id`,`imovel_id`), (`empresa_id`,`estado`), (`empresa_id`,`natureza`) |
| 3 | `historico_estado_fracao` (novo) | `fracao_id`, `estado_de`, `estado_para`, `motivo`, `utilizador_id`, `em` | substitui `estado_desde/estado_motivo` por trilho; ix (`fracao_id`,`em`) |
| 4 | `veiculos_contrato` (campo `park_veiculos`) | `contrato_id`, `matricula` (normalizada), `marca`, `modelo`, `cor`, `distico`, `fracao_vaga_id`, `ativo` | UNIQUE parcial (`empresa_id`, `matricula`) onde `ativo`; FK contrato |
| 5 | `espacos_comuns` (`imo_amenities`) | `imovel_id`, `tipo`, `nome`, `regime`, `preco`, `unidade_preco`, `horas_por_unidade`, `hora_abre/fecha`, `capacidade`, `limite_mensal_fracao`, `exige_aprovacao`, `caucao`, `prazo_pagamento_horas` (def. 48), `cancel_dias` (7), `cancel_pct` (50), `ativo` | CHECK regime; CHECK `cancel_pct BETWEEN 0 AND 100`; ix (`empresa_id`,`imovel_id`) |
| 6 | `reservas_espaco` (`imo_bookings`) | `espaco_id`, `imovel_id`, `fracao_id`, `contrato_id`, `terceiro_id`, `interna`, `data`, `hora_inicio/fim`, `estado`, `valor`, `valor_detalhe jsonb`, `motivo`, `venda_id`, `recibo_id`, `prazo_pagamento timestamptz`, `caucao`, `caucao_recebida/retida/devolvida`, `devedor_autorizado_por`, `devedor_autorizado_em`, `estado_em`, `notas` | CHECK estado; CHECK valores ≥ 0; **exclusão de sobreposição** `EXCLUDE USING gist (espaco_id WITH =, tstzrange(inicio,fim) WITH &&) WHERE estado vivo` (requer `btree_gist`; se indisponível, trava no serviço com lock); ix (`espaco_id`,`data`) |
| 7 | `vistorias_reserva` | `reserva_id`, `quando` (ANTES/DEPOIS), `estado`, `notas`, `custo_danos`, `ordem_trabalho_id` | CHECK quando |
| 8 | `contratos_arrendamento` (`imo_leases`) | `numero`, `tipo`, `posicao`, `estado`, `ambito`, `fracao_id` (principal), `imovel_id`, `terceiro_id`, `contrato_pai_id`, `finalidade_fiscal`, `inicio`, `fim`, `fim_real`, `periodicidade`, `antecipada`, `dia_vencimento`, `renda` num(18,2), `actualizacao`, `actualizacao_valor`, `actualizacao_meses`, `actualizacao_base`, `ultima_actualizacao`, `renda_anterior`, `caucao`, `caucao_recebida/devolvida/retida`, `caucao_motivo`, `retencao_ip`, `isencao_ip`, `selo_encargo`, `doc_tipo` (FT/FR), `renovacao`, `renovacao_meses`, `motivo_fim`, `conta_custo`, `conta_financeira`, `conta_retencao`, `mora_mensal` null, `notas` | UNIQUE(`empresa_id`,`numero`); CHECK `renda>0`; CHECK `caucao<=6*renda`; CHECK `caucao_recebida<=caucao`; CHECK `fim IS NULL OR fim>=inicio`; CHECK posição/tipo/estado/periodicidade; **sem sobreposição** (por fracção e lado) `EXCLUDE USING gist` via tabela `contrato_fracoes` (abaixo); ix (`empresa_id`,`estado`), (`empresa_id`,`posicao`), (`contrato_pai_id`) |
| 9 | `contrato_fracoes` (`unidades[]`) | `contrato_id`, `fracao_id`, `posicao` (cópia do lado), `inicio`, `fim` | UNIQUE(`contrato_id`,`fracao_id`); EXCLUDE (`fracao_id`, `posicao`, `daterange(inicio, coalesce(fim,'infinity'))`) onde contrato RASCUNHO/ACTIVO/SUSPENSO (o estado replica-se por trigger ou é mantido pelo serviço) |
| 10 | `contrato_encargos` (`encargos[]`) | `contrato_id`, `descricao`, `valor`, `incluido_na_renda`, `origem` (MANUAL/PARQUE), `fracao_id` | CHECK valor ≥ 0 |
| 11 | `rendas_arrendamento` (`imo_rents`) | `contrato_id`, `periodo` char(7), `de`, `ate`, `vencimento`, `estado`, `renda`, `encargos`, `desconto`, `extras jsonb`, `base`, `ip_base`, `ip_valor`, `ip_retido`, `selo_encargo`, `selo_valor`, `iva_taxa`, `iva_valor`, `total_facturar`, `total_receber`, `custo_senhorio`, `venda_id`, `documento_tesouraria_id`, `numero_documento`, `data_facturada`, `data_pagamento`, `notas`, `posicao` | **UNIQUE(`contrato_id`,`periodo`)**; CHECK estado; CHECK `total_facturar=base+iva_valor`; FK `venda_id`, `documento_tesouraria_id`; ix (`empresa_id`,`periodo`), (`empresa_id`,`estado`), (`empresa_id`,`vencimento`) |
| 12 | `modelos_documento_imo` (`imo_templates`) | `tipo`, `nome`, `corpo text`, `activo`, `sistema` bool, `versao` | CHECK tipo (8); UNIQUE(`empresa_id`,`tipo`,`nome`); `sistema` corrige o `is_master_data:1` indevido |
| 13 | `taxas_imobiliario` (`imo_settings.taxas[ano]`) | `ano` smallint, `ip_taxa`, `ip_base`, `selo_habitacao`, `selo_outro`, `iva_arrendamento`, `iva_alojamento`, `mora_mensal`, `prazo_registo`, `ip_transmissao`, `selo_transmissao`, `escritura_fixo`, `isencao_ate`, `isencao_metade_ate`, `det_escalao1_ate/pct`, `det_escalao2_ate/fixo`, `det_escalao3_pct`, `det_terreno_pct`, `det_agravamento_pct`, `det_agravamento_meses` | UNIQUE(`empresa_id`,`ano`); linha semente por ano com `TAXAS_BASE` marcada «a confirmar»; só `imo_taxas_editar` (sensível) altera |
| 14 | `negocios_imobiliarios` (`imo_deals`) | `numero`, `tipo`, `estado`, `imovel_id`, `fracao_id`, `terceiro_id`, `preco`, `vpt`, `sinal`, `finalidade`, `isencao`, `data_promessa/escritura/registo`, `notario`, `base`, `ip_taxa/valor`, `selo_pct/valor`, `escritura_valor`, `venda_id`, `custo_aquisicao`, `valor_contabilistico`, `mais_valia`, `destino_contratos`, `notas` | UNIQUE(`empresa_id`,`numero`); CHECK `sinal<=preco`, `preco>0`; ix (`empresa_id`,`tipo`,`estado`) |
| 15 | `custos_negocio_imo` (`imo_deal_costs`) | `negocio_id`, `tipo`, `descricao`, `valor`, `capitaliza`, `pago`, `data` | CHECK tipo (8) |
| 16 | `fichas_apetrechamento` + `itens_ficha_apetrechamento` (2 tabelas; `imo_fitout(_items)`) | ficha: `nome`, `tipologia`, `finalidade`, `activo`; item: `ficha_id`, `produto_id`, `descricao`, `quantidade int≥1`, `obrigatorio`, `ordem` | CHECK qtd ≥1; UNIQUE(`empresa_id`,lower(`nome`)) (corrige a falta de unicidade) |
| 17 | `bens_fracao` (`imo_unit_assets`) | `fracao_id`, `produto_id`, `descricao`, `quantidade` **num(12,3)** (o legado arredonda 0,5 para 1), `ativo_id`, `estado`, `origem`, `ordem_trabalho_id` | CHECK estado (NOVO/BOM/USADO/DANIFICADO/EM_FALTA) |
| 18 | `ordens_trabalho_imo` + `necessidades_ordem_imo` (2 tabelas; `imo_works`, `imo_work_items`) | ordem: `numero`, `tipo`, `descricao`, `imovel_id`, `fracao_id`, `prioridade`, `quem_paga`, `debitavel`, datas, `responsavel`, `custo_estimado`, `ficha_id`, `plano_id`, `plano_data`, `estado`, `motivo_cancelamento`, `debitado_em`, `debitado_renda_id`, `debitado_valor`, `projeto_id` null; necessidade: `ordem_id`, `produto_id`, `descricao`, `quantidade` num(12,3), `estado`, `quantidade_recebida`, `requisicao_manutencao_id`, `pedido_compra_id`, `linha_compra_id` | UNIQUE(`empresa_id`,`numero`); CHECK `imovel_id IS NOT NULL OR fracao_id IS NOT NULL`; CHECK datas; UNIQUE(`plano_id`,`plano_data`) (impede duplicar ordem por data do plano, defeito do legado) |
| 19 | `planos_manutencao_imo` (`imo_work_plans`) | `nome`, `tipo` (PREVENTIVA/VISTORIA), `imovel_id`, `fracao_id`, `periodicidade`, `primeira_data`, `quantas`, `datas jsonb`, `antecedencia_dias`, `custo_estimado`, `quem_paga`, `activo` | CHECK tipo; `antecedencia_dias` default 30 (0 válido, corrige o `||30`) |
| 20 | `entregas_fiscais_imo` (`imo_tax_filings`) | `tipo` (MODELO1/DETENCAO/RETENCAO/SELO), `ano`, `periodo`, `imovel_id`, `valor`, `estado`, `data_entrega`, `referencia`, `notas`, `entregue_por` | UNIQUE(`empresa_id`,`tipo`,`ano`,`periodo`,`imovel_id`) |
| 21 | `movimentos_caucao_imo` (novo) | `contrato_id` ou `reserva_id`, `tipo` (RECEBIDA/DEVOLVIDA/RETIDA), `valor`, `data`, `documento_tesouraria_id`, `lancamento_id`, `motivo`, `anulado_*` | CHECK tipo, `valor>0`; ledger imutável (estado ANULADO em vez de delete), base da contabilização e da conciliação da caução |

Totais: **21 tabelas novas** (cinco delas pares cabeçalho/linhas contadas como 1 + 1 nos nº 16 e 18 — ou seja 23 tabelas físicas se se contar os pares separadamente). **Colunas novas em tabelas existentes (aditivas, nuláveis):** `vendas.origem_tipo/origem_id` (se ainda não houver coluna genérica: verificar antes; senão só FK nas rendas), `documentos_tesouraria.origem_tipo/origem_id`, `ativos_imobilizados.contrato_arrendamento_id` (referência do bem alheio), `requisicoes_manutencao.necessidade_ordem_imo_id`. Nenhuma coluna existente é alterada ou removida.

**RLS/GRANT** de cada tabela: `(new IsolamentoRls(DB::connection()))->aplicarATodas()` + `GRANT SELECT, INSERT, UPDATE, DELETE … TO erp_app` e `GRANT USAGE, SELECT ON SEQUENCE` (modelo `2026_12_09_090000_pos_movimentos_caixa.php`). Teste `IsolamentoEmpresaTest` estendido às tabelas novas (acesso cruzado devolve 404/0 linhas).

## 3. Serviços, controladores e regras

### 3.1 Serviços (`app/Services/Imobiliario`)

| Serviço | Responsabilidade (funcionalidades IMO-F principais) |
|---|---|
| `ServicoImoveis` | CRUD imóvel, validações (código/matriz únicos, proprietário obrigatório por papel, `area_util≤area_bruta`), ligação a Activos; avisos do imóvel (A §1.7); taxa de ocupação |
| `ServicoFracoes` | CRUD, fracção única, gerar por piso (limite 500 **por bloco**, corrige o legado), importar portas do edifício (idempotente por `porta_id`), mudar estado com `TRANSICOES` e histórico |
| `ServicoParqueamento` | gerar vagas, afectar/libertar (recusa reatribuição silenciosa, corrige P:216-228), veículos (normalizar matrícula, preservar `cor`/`distico`), mapa |
| `ServicoAreasComuns` / `ServicoReservasEspaco` | espaços, conflitos, preço (`preço × max(1, ceil(horas/horasPorUnidade))`), limite mensal, devedor, transições (corrige salto AGUARDA_PAGAMENTO e auto-conflito) |
| `ServicoReservasFinanceiro` | factura (`ServicoDocumentosVenda`), recibo (`ServicoRecibosVenda`), caução (ledger), vistoria, previsão e cancelamento com escala, caducidade (job) |
| `ServicoContratosArrendamento` | gravar (conflitos por fracção/lado/intervalo), activar, terminar/denunciar com cascata, eliminar, actualizar renda, suspender/reactivar (novo, P3), renovar (novo, P3), avisos, resumo/KPIs |
| `CalculadoraRenda`, `CalculadoraActualizacao`, `CalculadoraMora` | fórmulas §4 do inventário |
| `ServicoRendas` | gerar (idempotente por UNIQUE), recalcular, aprovar, anular |
| `ServicoFacturacaoRendas` | `facturarRenda(s)` via `ServicoDocumentosVenda::emitir` em transacção + outbox; idempotente por `renda_id` |
| `ServicoPagamentoRendas` | `pagarRenda`, `desligarPagamento` via `ServicoDocumentosTesouraria::gravar` |
| `ServicoSubarrendamento` | margem (com mensalização, P3), cadeia, obras a debitar |
| `ServicoModelosDocumento` | 8 modelos, 61 variáveis, `contexto()`, geração de PDF no servidor |
| `ServicoTaxasImobiliario` | taxas por ano com herança do ano anterior e `TAXAS_BASE` |
| `ServicoNegociosImobiliarios` | fases, bloqueios, impostos, custos, conclusão de compra (→ Activos) e venda (→ factura + abate + mais-valia) |
| `ServicoObrasImoveis`, `ServicoApetrechamento`, `ServicoPlanosManutencao` | ordens, necessidades, pedido de material (→ requisição/compra), recepção, fecho/cancelamento, fichas, bens, planos e geração |
| `ServicoRentabilidade` | previsto × executado, rendimento anual |
| `ServicoFiscalImobiliario` | detenção, desocupação, retenções, selo, Modelo 1, resumo anual, calendário, entregas |
| `ServicoCaucao` | ledger e contabilização da caução |
| `ServicoContabilizacaoImobiliario` | caução e mais-valia → `ServicoLancamentos::criar` (job repetível) |
| `ServicoAlertasImobiliario` | alertas diários → `ServicoNotificacoes::criar` com `chave_origem` |
| `ServicoNumeracaoImobiliario` | séries `AR/SB/AT/CP/VD/AP/OB` e códigos `PR`, sobre `ServicoNumeracao::proximo` (corrige a corrida) |

### 3.2 Fronteira com Activos (decisão de desenho)

- `imoveis.ativo_edificio_id` só aceita `AtivoImobilizado` com `tipo_ativo='EDIFICIO'` e `propriedade='PROPRIO'`; imóvel `ARRENDADO` (tomado) pode ligar a um bem `ALHEIO` com `regime_alheio='ARRENDADO'`. Validado no serviço e com teste.
- `fracoes.porta_id` aponta para `portas_edificios` (já existe); a importação de portas é idempotente.
- Ao activar um contrato TOMADA, o serviço **escreve** no bem alheio `contrato_referencia`, `contrato_inicio`, `contrato_fim`, `renda_mensal` (via `ServicoAtivos`, só se o bem existe); nunca o contrário. O ecrã de Activos mostra a ligação.
- Compra concluída: cria a aquisição pelo fluxo de `ServicoAquisicoesAtivos` (ou liga a um bem existente); venda concluída: `ServicoAbatesAtivos` (simulação + abate) e a mais-valia usa `AmortizacaoAtivo` acumulada.

### 3.3 Rotas (`routes/api/imobiliario.php`, incluído como `fj_*`/`ativos.php`; prefixo `/api/imobiliario`, nomes `imobiliario.*`)

Grupos: `imoveis`, `fracoes`, `parque`, `espacos`, `reservas`, `contratos`, `rendas`, `subarrendamento`, `modelos`, `taxas`, `negocios`, `obras`, `apetrechamento`, `planos`, `rentabilidade`, `fiscal`, `alertas`. Convenção do projecto: GET lista/ficha, POST cria/acções, PUT actualiza, DELETE elimina; acções de estado como `POST …/{id}/activar`, `/terminar`, `/aprovar`, `/facturar`, `/pagar`, `/avancar`, `/concluir`; sempre `->whereNumber(...)`. Cada acção do controlador começa com `$this->exigir('<chave do catálogo>')` (padrão `AUTORIZACAO` do `OwaspControloAcessoTest`): o varrimento sem permissões tem de dar 403 em todas, **sem** entradas novas na lista `SEM_PERMISSAO_POR_DESENHO`. Exportações (Excel/PDF) em `GET …/exportar` com a mesma permissão de consulta + `licenca_exportacao` se aplicável.

### 3.4 Permissões (catálogo editado **à mão**, módulo novo `imobiliario`)

Migrar as 13 consultas e 35 tarefas do legado com as mesmas chaves (`imo_*`) para o módulo `imobiliario` do `catalogo.json` (ecrãs com `vistas`, `pai: null`, tarefas `{chave, rotulo, sensivel}`); **sensíveis** mantêm-se (14) e acrescentam-se: `imo_reserva_cancelar` (lacuna do legado), `imo_contrato_suspender`, `imo_contrato_renovar`, `imo_caucao_movimentar`, `imo_alertas_gerir`, `imo_exportar`. Pares de segregação novos (tabela `segregacao`): `imo_renda_aprovar`×`imo_renda_facturar`, `imo_neg_fase`×`imo_neg_concluir`, `imo_renda_facturar`×`imo_renda_pagar`, `imo_reserva_devedor`×`imo_reserva_facturar`, `imo_caucao_movimentar`×`imo_contrato_terminar`.
Perfis-modelo novos (fora do operador): **Gestor Imobiliário** (todas as tarefas excepto sensíveis de fecho), **Técnico de Rendas** (gerar/aprovar rendas, sem facturar), **Responsável de Cobrança** (facturar/pagar/mora), **Gestor de Obras do Imóvel**, **Fiscalista Predial** (`imo_fiscal_entregar`, `imo_taxas_editar`); Auditor/Consulta e Direcção recebem as 13 consultas; nenhuma tarefa sensível no perfil de operador. Executar `PerfisActualizarModelosCommand` e o teste de catálogo (`CatalogoPermissoes`). Todo o catálogo editado à mão, **sem regenerar do v_boss** (regra do projecto).

### 3.5 Contabilização (ADR-080; lançamentos equilibrados e idempotentes)

- **Renda do senhorio**: Vendas contabiliza a factura (receita, IVA); Tesouraria/recibo contabiliza o recebimento. O imobiliário não lança.
- **IP retido pelo inquilino** (lado senhorio): em Vendas, na liquidação (a decidir na P5: crédito de imposto a receber vs. custo).
- **Renda do inquilino** (lado tomado): documento de tesouraria PAGAMENTO com D custo (conta do contrato) e C retenção (IP a entregar à AGT).
- **Caução** (novo): `RECEBIDA`: D disponibilidades / C passivo «cauções de terceiros»; `DEVOLVIDA`: inverso; `RETIDA`: D passivo / C receita (ou compensação de dano), facturada à parte como no legado; contas **parametrizadas por empresa** em `ConfiguracaoImobiliario` (sem contas hard-coded; sem inventar códigos PGC aqui: P4).
- **Compra/venda de imóvel**: compra → aquisição de activo com custo capitalizável (Activos); venda → factura (Vendas) + abate (Activos) + resultado (mais/menos-valia) como lançamento complementar se o abate não o fizer sozinho (verificar `ServicoAbatesAtivos`).
- Job `ContabilizarImobiliarioJob` com `chave_idempotencia = tabela:id:evento`, `after_commit`, sem duplicar se repetido; teste de equilíbrio (ΣD = ΣC) e de repetição.

### 3.6 Notificações e alertas (`erp:imobiliario:alertas`, diário 07:45, `onOneServer()->withoutOverlapping()`)

Tipos: contrato a expirar (N dias, `fim` próximo), contrato expirado e activo, actualização de renda devida, renda por facturar/por pagar, renda em atraso (por escalão de dias), caução por receber, vistoria/plano sem ordem, obra parada, reserva com pagamento a caducar, prazos fiscais (Modelo 1, retenções do mês anterior, com a **data correcta** e não `…-30`), imóvel sem VPT. `chave_origem = imo:<tipo>:<id>:<data>`; visibilidade por `imo_*_view`; link para o ecrã.

### 3.7 Documentos, modelos e impressão

Modelos: manter os 8 modelos por omissão e as 61 variáveis (Anexo B §7), versionados; renderização no servidor para PDF A4 (dompdf, HTML limpo, sem rede nem ficheiros, como `ServicoPdfA4POS`), com cabeçalho da empresa (`/api/sistema/identidade`). Anexos reais em `documentos_anexos` (validador central ADR-083), não URLs soltas. No frontend `componentes/impressao` para o que é só ecrã (mapas), PDF para o que é documento (contrato, recibo, avisos, conta corrente). Excel: relatórios de rendas, conta corrente, mapas fiscais, rentabilidade, ocupação (padrão do Excel dos relatórios do POS).

## 4. Frontend

- `frontend/src/modulos/imobiliario/ecras.ts` (13 ecrãs lazy com os ids do catálogo) + registo em `modulos/registo.tsx` (`...imobiliario`).
- Menu: grupo `g('imobiliario','Gestão Imobiliária','city', [...13 itens com a ordem do legado...])` em `layout/menuLegado.ts` (entre Estrutura Orgânica e Planeamento Estratégico), `itensMenu.tsx`, e regeneração de `indicePesquisa.gerado.ts` (`ferramentas/pesquisa`).
- Componentes: reutilizar `componentes/vistas/TabelaLegado`, `GradeCartoes`, formulários e modais do projecto; ficha da fracção com `Tabs`; mapa de ocupação e calendário como componentes próprios (acessíveis: foco, `aria-*`, contraste de estado, texto além da cor); formulários responsivos (`FRONTEND_RESPONSIVIDADE.md`: 900 px do legado passa a modal adaptativo/ecrã inteiro em telemóvel); design system `UI_DESIGN_SYSTEM.md`; `ACESSIBILIDADE.md`.
- Erros: mensagens do `ErroNegocio` mostradas por `message`, nunca o texto técnico (`MENSAGENS_ERRO.md`).
- Painel de módulos: cartão «Gestão Imobiliária» com KPIs (§0 do Inventário §8 linha 15).
- Estrutura por ecrã: `Imoveis.tsx`, `MapaOcupacao.tsx`, `Parqueamento.tsx`, `AreasComuns.tsx`, `Contratos.tsx`, `Rendas.tsx`, `Subarrendamento.tsx`, `CompraVenda.tsx`, `Rentabilidade.tsx`, `Fiscalidade.tsx`, `ModelosTaxas.tsx`, `Obras.tsx`, `FichasApetrechamento.tsx`, mais `comum/` (tipos, formatação Kz, estados, cores com tokens).

## 5. Testes

- **PHPUnit** (`backend/tests/Feature/Imobiliario*Test.php`, `Unit/Imobiliario*`): uma classe por serviço; fixtures com números **calculados à mão** a partir das fórmulas do inventário, por exemplo: renda 100 000 Kz, HABITACAO, retenção de IP → `ip_base=60 000`, `ip=15 000`, `selo=100`, `iva=0`, `total_facturar=100 000`, `total_receber=85 000`; actualização composta 5% após 24 meses = `100 000×1,05²=110 250`; detenção VPT 4 000 000=4 000 (0,1%), 5 000 000=5 000, 6 000 000=5 000 fixos, 8 000 000=`(8 000 000−5 000 000)×0,5%=15 000`, terreno de 10 000 000=60 000 (0,6%), com agravamento de 50% se desocupado ≥ 12 meses; transmissão preço 30 000 000, VPT 35 000 000 → base 35 000 000, IP 700 000, selo 105 000, escritura 2 000; mora 85 000 com 45 dias a 1%/mês = `85 000×0,01×1,5=1 275`; reserva: 2,5 h a 4 000/h com `horasPorUnidade=1` → 3 unidades = 12 000; cancelamento 3 dias antes com `cancel_dias=7`, `cancel_pct=50` e recebido 12 000 → devolve 6 000. Cada IMO-F é um caso com o seu ID no nome do teste. Testes de contrato de rotas (`OwaspControloAcessoTest` sem exceções novas), `AcessoCruzadoGerado`/`IsolamentoEmpresaTest` para as 21 tabelas, concorrência da numeração, idempotência de gerar rendas/facturar/contabilizar, transacção parcial (falha a meio), RLS, auditoria (`Auditavel` regista), outbox.
- **Vitest**: calculadoras de pré-visualização (as mesmas fixtures), estados/cores, mapas, formulários (validações), `ecras.test.tsx` por ecrã (padrão de `activos/edificios/ecras.test.tsx`), acessibilidade (`AjudasAcessibilidade`).
- **E2E** (Playwright, skill `webapp-testing`): contrato→activar→gerar rendas→aprovar→facturar→recebimento→conta corrente; venda de imóvel.
- **CI**: os mesmos jobs; build do frontend no fim.

## 6. Migração de dados do legado (ETL)

Situação: as 17 tabelas `imo_*` **não** entram no backup de migração do legado (`company_backup.js:18-33`) nem existem na produção. Se houver dados reais num utilizador do legado (P1), é preciso um **exportador novo no legado** (só leitura, fora do âmbito desta tarefa) que escreva JSON por tabela, ou um pedido manual. ETL proposto (`app/Services/Migracao/ServicoMigracaoImobiliario`, comando `erp:imobiliario:migrar`): ordem terceiros→imóveis→fracções→contratos(+fracções, encargos)→rendas→modelos→taxas→negócios(+custos)→fichas→bens→planos→ordens(+necessidades)→entregas→espaços→reservas; chaves de correspondência `(empresa_id, origem_legado_id)` guardadas em colunas `legado_id` nuláveis (ou tabela de mapa) para idempotência; modo `--simular`; relatório de rejeitados; migração tudo-ou-nada por empresa; validações de `UNIQUE` antes; `sale_id`/`treasury_id` das rendas só se o documento migrado existir. Teste com um JSON fixture mínimo.

## 7. FASES (esforço S ≤ 1 dia de agente, M 2-4, L ≥ 1 semana)

| Fase | Conteúdo | IMO-F (aprox.) | Esforço | Depende de |
|---|---|---|---|---|
| **F0 Alicerces** | catálogo de permissões (módulo, 13 ecrãs, tarefas, perfis, segregação); `ecras.ts`/menu/pesquisa vazios com `EmConstrucao`; `ConfiguracaoImobiliario` e `taxas_imobiliario` (+semente); `ServicoNumeracaoImobiliario`; esqueleto de rotas e testes de acesso; migração base das 21 tabelas (vazias, RLS, GRANT) ou por fase | — | M | decisões P1-P4 |
| **F1 Cadastro** | imóveis, fracções, gerar por piso, importar portas, estados com histórico, mapa de ocupação, documentos/anexos, ficha da fracção (parte cadastro), avisos do imóvel | A (D, F): ~45 | L | F0 |
| **F2 Modelos e taxas** | 8 modelos + 61 variáveis + PDF servidor; ecrã Taxas; `CalculadoraRenda`/`Actualizacao`/`Mora` (só calculadoras e simulador) | B(TP): ~25 | M | F0 |
| **F3 Contratos e rendas** | contratos (formulário, conflitos, activar/terminar/cascata, actualizar renda), rendas (gerar/recalcular/aprovar/anular), facturar (Vendas), pagar (Tesouraria), conta corrente e mora, caução + ledger, avisos, KPIs, subarrendamento (margem, cadeia) | B: ~70 | L | F1, F2 |
| **F4 Parqueamento e áreas comuns** | vagas, afectação, veículos, mapa; espaços, calendário, reservas, devedor, financeiro (factura/recibo/caução/vistoria/cancelamento/caducidade) | A (P, AU) + C (RF): ~90 | L | F1 (F3 só para encargos e factura) |
| **F5 Obras e apetrechamento** | fichas, bens da fracção, ordens, necessidades, pedido de material (→ Manutenção de Activos/Compras), recepção ligada, planos e geração, débito ao inquilino | D (OB) + C (PL): ~65 | L | F1; F3 para débito |
| **F6 Compra e venda** | negócios, fases, impostos, custos, compra→Activos, venda→factura+abate+mais-valia, contabilização | C (TR): ~60 | L | F1, F3, Activos |
| **F7 Rentabilidade e Fiscal** | rentabilidade previsto × executado, rendimento; detenção, retenções, selo, Modelo 1, resumo anual, entregas, calendário; integração no Relatório e Contas | C (RE) + D (FI): ~55 | L | F3, F5, F6 |
| **F8 Transversais** | alertas agendados, painel/KPIs, pesquisa, exportações Excel, PDFs restantes, vista em linhas, Fluxo (narrativa) | ~25 | M | F3-F7 |
| **F9 ETL e fecho** | ETL (se P1 o exigir), testes e2e, desempenho (índices), segurança (varrimento OWASP), documentação, ensaio de migração na cópia da produção | — | M | tudo |

**Paralelismo sem colisão de ficheiros** (cada agente trabalha só na sua pasta e nas suas migrações; o catálogo, `registo.tsx` e `menuLegado.ts` são tocados **só na F0**, depois cada fase edita a sua linha de ecrãs): F2 ‖ F1 (depois de F0); F4 ‖ F5 ‖ F3 (serviços e controladores distintos; F4 e F5 só partilham `fracoes`, que F1 já fechou; atenção ao ficheiro de rotas: um ficheiro `routes/api/imobiliario_<parte>.php` por fase, incluídos em `api.php`); F6 só após F3 (usa `terminarContrato`) e coordenação com Activos; F7 serializada no fim. O `catalogo.json` e `esquema`/dicionário são pontos de colisão: uma única pessoa/agente por vez.

**Riscos principais**: (1) taxas fiscais sem verificação legal; (2) regras de contabilização da caução/IP retido por decidir; (3) integração AGT (isenção M14, série de documentos) e selagem; (4) sobreposição com Activos (bem alheio); (5) exclusões `gist` exigem `btree_gist` em produção; (6) ETL sem fonte exportável; (7) paridade «bug a bug» versus correcção deliberada (P3); (8) volume: ~407 funcionalidades, 13 ecrãs, 21 tabelas: planear como o POS (10 fases).

**Critérios de aceitação por fase**: todas as IMO-F da fase com teste verde e identificado; fórmulas batem com as fixtures à mão; `OwaspControloAcessoTest`, isolamento por empresa e auditoria verdes; migrações ensaiadas na cópia da produção sem duplicados; lançamentos equilibrados e repetíveis; ecrã responsivo (telemóvel) e acessível; mensagens de erro seguras; `npm run build` feito; commit da fase com `git add` só dos ficheiros da fase.

## 8. Perguntas ao utilizador (decisões que só ele pode tomar) e recomendação

| # | Pergunta | Recomendação |
|---|---|---|
| P1 | Há **dados reais** no módulo imobiliário do legado (numa empresa em uso) que tenham de ser migrados? Hoje não há exportador nem backup para as tabelas `imo_*`. | Se não, saltar o ETL (só um comando de importação por CSV/JSON opcional); se sim, construir primeiro um exportador de leitura no legado |
| P2 | As tabelas novas entram no dicionário/`esquema.json` (e geram `*Base`) ou ficam como as do POS (migração manual + modelo manual)? | Como as do POS; acrescentar ao `DICIONARIO_DADOS.md` à mão |
| P3 | **Paridade ou correcção** dos defeitos do legado (Inventário §4.1): suspender/renovar contratos, pro-rata, `mora_mensal` por contrato, mensalização da margem, recalcular com extras, cancelamento de reservas com permissão própria? | Corrigir todos, mantendo o comportamento do legado como fixture quando for regra de negócio (e registar a diferença como no POS) |
| P4 | **Contabilização**: queres lançamentos para caução (passivo), mais-valia/menos-valia e juros de mora? Que contas (do teu plano) usar? Hoje o legado não lança nada disto. | Sim para caução e mais-valia; juros de mora só quando recebidos; contas configuráveis por empresa, que me indicas ou que eu proponho para validares |
| P5 | O **IP retido** pelo inquilino reduz o recebimento do senhorio: tratar como crédito de imposto a receber (activo) ou como custo? | Crédito de imposto (valida com o contabilista) |
| P6 | Liga-se Imobiliário a **Projectos** e **Orçamento** (obras grandes, rubricas)? O legado não liga. | Não na primeira entrega; campo `projeto_id` já previsto, ligação na F8 se pedires |
| P7 | **Moeda estrangeira** em rendas (USD)? O legado é só Kz. | Só Kz agora; campos de moeda adiados |
| P8 | Fronteira com **Activos**: aceitas que o contrato tomado seja a fonte e os campos `contrato_*`/`renda_mensal` do bem alheio passem a ser sincronizados (só leitura no ecrã de Activos)? | Sim |
| P9 | **Validação fiscal**: quem confirma as taxas (IP 25%×60%, selo 0,1%/0,4%, IVA, detenção, transmissão, agravamento) e a isenção M14? | Contabilista/jurista antes da F3; ficam em `taxas_imobiliario` como dados editáveis por ano, marcadas «por confirmar» |
| P10 | **Perfis**: queres os 5 perfis-modelo propostos e os 5 pares de segregação? | Sim |
| P11 | Âmbito da primeira entrega em produção: tudo (F0-F9) ou por ondas (recomendo F0-F3 primeiro: cadastro + contratos + rendas, que dão valor imediato)? | Ondas: 1 = F0-F3; 2 = F4-F5; 3 = F6-F7; 4 = F8-F9 |
| P12 | **Alertas**: por notificação interna apenas, ou também e-mail (`Mail`, `EmailsTest`)? | Só notificação interna na primeira entrega |
