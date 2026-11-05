# Cobertura de campos BD → modelo → API → frontend (2026-11)

> Gerado por `ferramentas/paridade/auditar_campos.mjs` (determinístico; entradas: `esquema_bd.json`, `rotas.json`, código em `backend/app` e `frontend/src`). É uma **triagem heurística** por âmbito de tabela (ficheiros que mencionam o modelo/tabela; no frontend, ficheiros que chamam os endpoints dessa tabela e os seus imports locais). As lacunas listadas são confirmadas à mão antes de qualquer implementação.

## Classes

- **INFRA**: id, empresa_id, carimbos, autoria, hashes, chaves de idempotência — normal não ter UI.
- **LEGADO_TECNICO**: `*_original`, `*_legado`, colunas de normalização/paridade.
- **FUNCIONAL_COBERTO**: backend + API + ecrã.
- **FUNCIONAL_SO_BACKEND**: a API devolve/aceita, nenhum ecrã a mostra ou edita.
- **FUNCIONAL_SEM_API**: existe na BD/modelo, nenhum endpoint a lê ou escreve (ou só uso interno).
- **ORFA**: nenhum código a usa e o legado não tinha dados.

## Precisão da triagem e verificação manual

A triagem é estática: nomes de colunas montados por constantes (`EnderecoTerceiro::CAMPOS`), regras de validação com chaves pontuadas (`habilitacoes.*.curso`), URLs montadas com constantes (`${EST}/delegacoes`) e acesso via Resource/Service sem referência ao modelo geram **falsos positivos**. Por isso as classes `FUNCIONAL_SO_BACKEND`/`FUNCIONAL_SEM_API` são um limite superior das lacunas reais. Amostra verificada à mão (Vendas, Terceiros, RH, Compras, Estrutura, CRM):

- **Falsos positivos confirmados** (já cobertos): `enderecos_terceiros.*` (morada estruturada no Resource/Request/Serviço de terceiros), `habilitacoes_colaboradores.curso`, `delegacoes_chefia.*` e restantes tabelas de Estrutura (ecrãs em `modulos/estrutura/faseK`), `itens_venda.centro_custo_id/unidade_negocio_id` (por linha na emissão), `itens_venda.total_linha` (devolvido como `valor` da linha), `oportunidades_venda_crm.etapa_desde/ultima_atividade_em` (usados para os dias na etapa / sem actividade que o ecrã mostra), `pedidos_compra.colaborador_requerente_id` (deliberação de compras).
- **Espelhos internos sem UI por desenho**: `catalogo_fornecedores` (sincronizado automaticamente a partir do produto, `ServicoProdutos::sincronizarCatalogoCompras`), tabelas de maquinaria (`outbox_eventos`, `execucoes_*`, `quarentena_migracao`, `logs_auditoria_*`, …) tratadas como INFRA.
- **Lacunas reais corrigidas neste trabalho**: `vendas.data_entrega`/`vendas.local_entrega` (emissão, detalhe, impressão, herança na conversão; commit «data e local de entrega») e `colaboradores.data_cessacao` (listagem, ficha e impressão da ficha; só leitura, preenchida pelo estado do contrato).
- **Mantidas sem uso (legado), sem UI inventada**: `configuracoes_assiduidade.minimo_dia_horas` (o legado só a normalizava, `assiduidade.js:63`; a semântica de cálculo não está documentada — decisão de negócio pendente antes de a ligar à apuração), `documentos_projeto.*` e `faturacao_projetos.projeto_id` (tabelas sem funcionalidade; o legado tinha 0 linhas), `vendas.total_desconto`/`recibos_venda.montante_total_moeda`/`vendas.fe_estabelecimento`/`itens_venda.codigo_projeto`/`lancamentos_estornados.{reconciliacao_codigo,codigo_projeto,sistema_origem}`/`reconciliacoes_bancarias.importacao_codigo`/`tipos_documento.descricao`/`documentos_anexos.entidade_id` (restos de paridade do legado sem consumidor).
- **Restante**: a maioria dos campos técnicos listados abaixo são carimbos de estado (`*_em`/`*_por`), câmbio (`taxa_cambio_*`, `*_moeda`), campos da facturação electrónica AGT (`fe_*`, `saft_*`) e chaves de ligação — não têm valor como campo editável; os que ficam por rever à mão estão na lista priorizada.

## Totais (255 tabelas, 3990 colunas)

| Classe | Colunas | % |
| :--- | :--- | :--- |
| INFRA | 1297 | 32.5% |
| LEGADO_TECNICO | 78 | 2.0% |
| FUNCIONAL_COBERTO | 1928 | 48.3% |
| FUNCIONAL_SO_BACKEND | 316 | 7.9% |
| FUNCIONAL_SEM_API | 356 | 8.9% |
| ORFA | 15 | 0.4% |

## Por módulo

| Módulo | Tabelas | Colunas | INFRA | LEGADO_TECNICO | F_COBERTO | F_SO_BACKEND | F_SEM_API | ORFA |
| :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- |
| Vendas | 9 | 225 | 38 | 7 | 122 | 31 | 23 | 4 |
| Compras | 11 | 212 | 45 | 8 | 119 | 36 | 4 | 0 |
| Logística | 9 | 182 | 41 | 4 | 116 | 18 | 3 | 0 |
| Tesouraria | 10 | 197 | 41 | 10 | 102 | 31 | 12 | 1 |
| Contabilidade | 11 | 181 | 48 | 5 | 72 | 3 | 50 | 3 |
| RH | 43 | 711 | 201 | 20 | 398 | 28 | 63 | 1 |
| Activos | 16 | 285 | 70 | 3 | 156 | 33 | 23 | 0 |
| CRM | 8 | 109 | 37 | 2 | 64 | 6 | 0 | 0 |
| Projectos | 21 | 272 | 87 | 4 | 151 | 17 | 9 | 4 |
| Orçamento | 8 | 145 | 40 | 4 | 98 | 1 | 2 | 0 |
| Estrutura | 7 | 142 | 41 | 0 | 61 | 40 | 0 | 0 |
| Terceiros | 3 | 42 | 13 | 1 | 23 | 1 | 4 | 0 |
| POS | 11 | 254 | 54 | 4 | 165 | 29 | 2 | 0 |
| Acréscimos | 3 | 53 | 15 | 0 | 36 | 0 | 2 | 0 |
| Sistema | 15 | 224 | 96 | 4 | 93 | 11 | 18 | 2 |
| Autenticacao | 3 | 42 | 24 | 0 | 15 | 3 | 0 | 0 |
| Outros | 51 | 547 | 355 | 0 | 48 | 3 | 141 | 0 |
| Plataforma | 14 | 155 | 43 | 2 | 85 | 25 | 0 | 0 |
| Projetos | 2 | 12 | 8 | 0 | 4 | 0 | 0 | 0 |

## Tabelas com mais lacunas funcionais (SO_BACKEND + SEM_API + ORFA)

| Tabela | Módulo | Lacunas | Colunas |
| :--- | :--- | :--- | :--- |
| vendas | Vendas | 30 | 92 |
| lancamentos_estornados | Contabilidade | 25 | 33 |
| itens_documento_tesouraria | Tesouraria | 24 | 31 |
| agt_ligacoes_empresa | Outros | 18 | 31 |
| lancamentos_contabeis | Contabilidade | 18 | 53 |
| itens_venda | Vendas | 16 | 30 |
| pedidos_manutencao_equipamentos | Activos | 16 | 29 |
| execucoes_entregavel | Estrutura | 15 | 29 |
| itens_compra | Compras | 14 | 36 |
| impedimentos_entregavel | Estrutura | 12 | 20 |
| contas_mesa_pos | Outros | 11 | 17 |
| utilizacoes_assistente_ia | Outros | 11 | 18 |
| itens_carta_pagamento | RH | 10 | 15 |
| prestacoes_emprestimos | RH | 10 | 17 |
| ia_accoes | Outros | 9 | 13 |
| ordens_manutencao_ativos | Activos | 9 | 32 |
| resultados_folha_salarial | Outros | 9 | 25 |
| notificacoes_sistema | Outros | 8 | 13 |
| avaliacoes_desempenho_rh | RH | 7 | 34 |
| documentos_anexos | Sistema | 7 | 11 |
| ia_consumo | Outros | 7 | 12 |
| ia_mensagens | Outros | 7 | 10 |
| itens_guia_saida | Logística | 7 | 17 |
| linhas_requisicao_manutencao | Activos | 7 | 12 |
| reconciliacoes_bancarias | Tesouraria | 7 | 12 |
| registos_conversao_contas_produtos | Outros | 7 | 11 |
| senhas_pos_armazem | Outros | 7 | 13 |
| assuntos_metodo_trabalho | Projectos | 6 | 41 |
| correspondencias_reconciliacao | Tesouraria | 6 | 11 |
| delegacoes_chefia | Estrutura | 6 | 15 |
| faturas_compra | Compras | 6 | 29 |
| inventarios_fisicos_ativos | Activos | 6 | 22 |
| itens_ordem_manutencao | Activos | 6 | 19 |
| linhas_revisao_projeto | Projectos | 6 | 14 |
| modelos_documentos_rh | RH | 6 | 14 |
| movimentos_caixa | Tesouraria | 6 | 34 |
| multas_entregavel | Estrutura | 6 | 23 |
| produtos | Logística | 6 | 59 |
| subscricoes_empresa | Plataforma | 6 | 28 |
| utilizadores | Sistema | 6 | 28 |

## Lista priorizada de lacunas (687)

Ordenada por módulo central → tabela → coluna. «Onde ligar» = controller(s) e ecrã(s) do âmbito da tabela.


### Vendas

**configuracoes_contabeis_vendas** — controllers: —; ecrãs: —
- `chave` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `codigo_conta` (character varying) SEM_API — usada só internamente (serviço/modelo)
**itens_recibo_venda** — controllers: —; ecrãs: —
- `montante_pago` (numeric) SO_BACKEND
- `numero_lan_contabilizacao` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `recibo_venda_id` (bigint) SEM_API — usada só internamente (serviço/modelo)
- `venda_id` (bigint) SEM_API — usada só internamente (serviço/modelo)
**itens_venda** — controllers: —; ecrãs: frontend/src/modulos/estrutura/tarefas/EditorEntregavel.tsx, frontend/src/paginas/Inicio.tsx
- `acerto_cmv_kz` (numeric) SEM_API — usada só internamente (serviço/modelo)
- `centro_custo_id` (bigint) SO_BACKEND — só nos tipos TypeScript
- `codigo_conta` (character varying) SO_BACKEND
- `codigo_projeto` (text) ORFA
- `fe_selado` (boolean) SEM_API — usada só internamente (serviço/modelo)
- `imposto_moeda` (numeric) SEM_API — usada só internamente (serviço/modelo)
- `pedido_compra_id` (bigint) SEM_API — usada só internamente (serviço/modelo)
- `percentagem_desconto` (numeric) SEM_API — usada só internamente (serviço/modelo)
- `produto_id` (bigint) SO_BACKEND
- `projeto_id` (bigint) SO_BACKEND
- `quantidade_devolvida` (numeric) SO_BACKEND — só nos tipos TypeScript
- `quantidade_stock` (numeric) SEM_API — usada só internamente (serviço/modelo)
- `taxa_imposto` (numeric) SO_BACKEND
- `total_linha` (numeric) SO_BACKEND
- `unidade_negocio_id` (bigint) SO_BACKEND — só nos tipos TypeScript
- `venda_id` (bigint) SEM_API — usada só internamente (serviço/modelo)
**recibos_venda** — controllers: ReparticaoController, ReciboVendaController; ecrãs: frontend/src/modulos/vendas/Faturacao.tsx, frontend/src/modulos/vendas/ListaDocumentos.tsx, frontend/src/modulos/vendas/comum/BarraVendas.tsx
- `codigo_projeto` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `montante_total_moeda` (numeric) ORFA
- `serie_faturacao_eletronica_id` (bigint) SO_BACKEND
**series_faturacao_eletronica** — controllers: ConfigVendasController, FaturacaoEletronicaController, SeriesVendasController; ecrãs: frontend/src/modulos/pos/comum/frente.ts, frontend/src/modulos/vendas/Faturacao.tsx, frontend/src/modulos/vendas/agt/FaturacaoEletronica.tsx
- `agt_pedido_em` (timestamp with time zone) SO_BACKEND
- `agt_primeiro_numero` (integer) SO_BACKEND — só nos tipos TypeScript
- `agt_quantidade` (integer) SO_BACKEND
**vendas** — controllers: AtivosController, ConfiguracaoCRMController, OportunidadesCRMController, GuiasRemessaArmazemController, StockController, AssuntosMetodoController, DocumentosLavandariaController, HotelariaController, MesasPOSController, POSArmazemController, POSController, ReparticaoController, PreVisualizacaoContabilizacaoController, OperacoesTesourariaController, TesourariaController, DocumentoVendaController, FaturacaoEletronicaController, LigacaoAgtController, ParidadeVendasController, RecebimentosFaturaController, SeriesVendasController; ecrãs: frontend/src/modulos/activos/Abates.tsx, frontend/src/modulos/activos/ModalAbate.tsx, frontend/src/modulos/config/PlataformaSessoes.tsx
- `codigo_terminal_pos` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `fe_avisos` (jsonb) SO_BACKEND
- `fe_data_entrada_sistema` (timestamp with time zone) SEM_API — usada só internamente (serviço/modelo)
- `fe_documento` (jsonb) SEM_API — usada só internamente (serviço/modelo)
- `fe_envio` (jsonb) SO_BACKEND
- `fe_erros` (jsonb) SO_BACKEND
- `fe_estabelecimento` (character varying) ORFA
- `fe_estado` (character varying) SO_BACKEND
- `fe_numero` (integer) SO_BACKEND
- `fe_regime` (boolean) SO_BACKEND
- `fe_selado_em` (timestamp with time zone) SO_BACKEND
- `fe_serie` (character varying) SO_BACKEND
- `fe_tipo` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `fe_validado_em` (timestamp with time zone) SEM_API — usada só internamente (serviço/modelo)
- `montante_pago` (numeric) SO_BACKEND
- `numero_documento_saft` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `numero_pedido_lavandaria` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `pedido_lavandaria_id` (bigint) SO_BACKEND — só nos tipos TypeScript
- `pos_lans_contabilizacao` (jsonb) SEM_API — usada só internamente (serviço/modelo)
- `saft_hash_controlo` (character varying) SO_BACKEND
- `saft_source_billing` (character varying) SO_BACKEND
- `saida_armazem` (character varying) SO_BACKEND
- `serie_faturacao_eletronica_id` (bigint) SO_BACKEND
- `sessao_pos_legado_codigo` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `sistema_origem` (character varying) SO_BACKEND
- `taxa_cambio_id` (bigint) SEM_API — usada só internamente (serviço/modelo)
- `total_bruto_moeda` (numeric) SO_BACKEND
- `total_desconto` (numeric) ORFA
- `total_imposto_moeda` (numeric) SO_BACKEND — só nos tipos TypeScript
- `total_liquido_moeda` (numeric) SO_BACKEND

### Compras

**catalogo_fornecedores** — controllers: —; ecrãs: —
- `codigo` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `nome` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `preco_unitario` (numeric) SEM_API — usada só internamente (serviço/modelo)
- `produto_id` (bigint) SEM_API — usada só internamente (serviço/modelo)
**contratos_fornecedores** — controllers: ContratosComprasController, ParidadeComprasController; ecrãs: frontend/src/componentes/icones/iconesModulos.tsx, frontend/src/layout/fundoModulo.ts, frontend/src/modulos/compras/comum/ModaisEncomenda.tsx
- `cancelado_em` (timestamp with time zone) SO_BACKEND — só nos tipos TypeScript
**cotacoes_compra** — controllers: ComprasController; ecrãs: frontend/src/modulos/compras/comum/accoesLista.tsx, frontend/src/modulos/compras/pedidos/DetalhePedido.tsx, frontend/src/modulos/compras/prospeccao/DetalheProposta.tsx
- `taxa_cambio_id` (bigint) SO_BACKEND
- `taxa_cambio_manual` (boolean) SO_BACKEND
**encomendas_compra** — controllers: ComprasController, ContratosComprasController, ParidadeComprasController, ReparticaoController; ecrãs: frontend/src/componentes/icones/iconesModulos.tsx, frontend/src/layout/fundoModulo.ts, frontend/src/modulos/compras/comum/ModaisEncomenda.tsx
- `anulado_em` (timestamp with time zone) SO_BACKEND — só nos tipos TypeScript
- `codigo_projeto` (text) SO_BACKEND
- `taxa_cambio_id` (bigint) SO_BACKEND
- `taxa_cambio_manual` (boolean) SO_BACKEND
- `venda_origem_id` (bigint) SO_BACKEND — só nos tipos TypeScript
**faturas_compra** — controllers: ComprasController, ContratosComprasController, ParidadeComprasController, ReparticaoController, PreVisualizacaoContabilizacaoController, OperacoesTesourariaController, TesourariaController; ecrãs: frontend/src/componentes/icones/iconesModulos.tsx, frontend/src/layout/fundoModulo.ts, frontend/src/modulos/compras/comum/ModaisEncomenda.tsx
- `anulado_em` (timestamp with time zone) SO_BACKEND — só nos tipos TypeScript
- `codigo_projeto` (text) SO_BACKEND
- `projeto_id` (bigint) SO_BACKEND — só nos tipos TypeScript
- `taxa_cambio_id` (bigint) SO_BACKEND
- `taxa_cambio_manual` (boolean) SO_BACKEND
- `total_imposto_moeda` (numeric) SO_BACKEND — só nos tipos TypeScript
**itens_compra** — controllers: ComprasController; ecrãs: frontend/src/modulos/acrescimos/ModalItem.tsx, frontend/src/modulos/acrescimos/Registos.tsx, frontend/src/modulos/activos/manutencao/Ordens.tsx
- `cambial_faturado_por_receber_kz` (numeric) SO_BACKEND
- `cambial_faturado_por_receber_qtd` (numeric) SO_BACKEND
- `cambial_recebido_por_faturar_kz` (numeric) SO_BACKEND
- `cambial_recebido_por_faturar_qtd` (numeric) SO_BACKEND
- `codigo_projeto` (text) SO_BACKEND
- `encomenda_compra_id` (bigint) SO_BACKEND
- `fatura_compra_id` (bigint) SO_BACKEND
- `imposto_moeda` (numeric) SO_BACKEND
- `pedido_compra_id` (bigint) SO_BACKEND — só nos tipos TypeScript
- `tarefa_projeto_id` (bigint) SO_BACKEND
- `taxa_imposto` (numeric) SO_BACKEND
- `tipo_documento_origem` (character varying) SO_BACKEND
- `valor_recebido_kz` (numeric) SO_BACKEND
- `valor_transitoria_kz` (numeric) SO_BACKEND
**pedidos_compra** — controllers: ComprasController, PortalEntregaveisController, ReparticaoController; ecrãs: frontend/src/layout/menuLegado.ts, frontend/src/modulos/compras/comum/ModaisEncomenda.tsx, frontend/src/modulos/compras/comum/accoesLista.tsx
- `anulado_em` (timestamp with time zone) SO_BACKEND — só nos tipos TypeScript
- `anulado_por` (character varying) SO_BACKEND
- `codigo_projeto` (text) SO_BACKEND
- `colaborador_requerente_id` (bigint) SO_BACKEND
- `venda_origem_id` (bigint) SO_BACKEND — só nos tipos TypeScript
**rececoes_compra** — controllers: ComprasController, ParidadeStockController; ecrãs: frontend/src/modulos/compras/comum/ModaisEncomenda.tsx, frontend/src/modulos/compras/comum/Rececoes.tsx, frontend/src/modulos/compras/comum/accoesLista.tsx
- `anulado_em` (timestamp with time zone) SO_BACKEND — só nos tipos TypeScript
- `taxa_cambio_id` (bigint) SO_BACKEND
- `taxa_cambio_manual` (boolean) SO_BACKEND

### Logística

**categorias_produtos** — controllers: CategoriaProdutoController, MigracaoDadosController, ParidadeVendasController; ecrãs: frontend/src/modulos/compras/comum/Seletores.tsx, frontend/src/modulos/config/Migracao.tsx, frontend/src/modulos/pos/comum/dados.ts
- `chave_conversao` (character varying) SO_BACKEND
- `conta_compra` (character varying) SO_BACKEND — só nos tipos TypeScript
- `conta_inventario` (character varying) SO_BACKEND — só nos tipos TypeScript
**guias_saida** — controllers: StockController, POSArmazemController; ecrãs: frontend/src/layout/fundoModulo.ts, frontend/src/modulos/compras/comum/Rececoes.tsx, frontend/src/modulos/compras/comum/accoesLista.tsx
- `codigo_projeto` (text) SO_BACKEND
- `venda_relacionada_id` (bigint) SO_BACKEND — só nos tipos TypeScript
**itens_guia_saida** — controllers: StockController, POSArmazemController; ecrãs: frontend/src/layout/fundoModulo.ts, frontend/src/modulos/compras/comum/Rececoes.tsx, frontend/src/modulos/compras/comum/accoesLista.tsx
- `cambial_q1` (numeric) SO_BACKEND
- `cambial_q2` (numeric) SO_BACKEND
- `cambial_v1` (numeric) SO_BACKEND
- `cambial_v2` (numeric) SO_BACKEND
- `codigo_projeto` (text) SO_BACKEND
- `guia_saida_id` (bigint) SO_BACKEND
- `rececao_compra_id` (bigint) SO_BACKEND
**linhas_sessao_inventario** — controllers: StockController; ecrãs: frontend/src/modulos/activos/edificios/Folha.tsx, frontend/src/modulos/stock/comum/Inventario.tsx
- `sessao_inventario_id` (bigint) SO_BACKEND
**movimentos_inventario** — controllers: ParidadeStockController; ecrãs: frontend/src/modulos/stock/Movimentos.tsx
- `codigo_projeto` (text) SO_BACKEND
- `projeto_id` (bigint) SO_BACKEND — só nos tipos TypeScript
**produtos** — controllers: ManutencaoPlaneadaController, ComprasController, CategoriaProdutoController, ExportacaoInventarioController, GuiasRemessaArmazemController, NiveisStockController, ProdutoController, AssuntosMetodoController, CatalogoServicosPOSController, HotelariaController, LavandariaController, MesasPOSController, POSController, ParidadePOSArmazemController, ProjetoController, MigracaoDadosController, OperacoesTesourariaController, ImagemProdutoController, ParidadeVendasController; ecrãs: frontend/src/modulos/activos/manutencao/comum.tsx, frontend/src/modulos/compras/comum/ModaisEncomenda.tsx, frontend/src/modulos/compras/comum/Seletores.tsx
- `conta_compra` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `conta_inventario` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `imagem_atualizada_em` (timestamp with time zone) SO_BACKEND
- `imagem_base64` (text) SO_BACKEND — só nos tipos TypeScript
- `imagem_caminho` (character varying) SO_BACKEND
- `imagem_mime` (character varying) SEM_API — usada só internamente (serviço/modelo)

### Tesouraria

**correspondencias_reconciliacao** — controllers: —; ecrãs: —
- `data` (timestamp with time zone) SEM_API — usada só internamente (serviço/modelo)
- `lancamento_contabil_id` (bigint) SEM_API — usada só internamente (serviço/modelo)
- `linha_extrato_bancario_id` (bigint) SEM_API — usada só internamente (serviço/modelo)
- `reconciliacao_codigo` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `tipo_correspondencia` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `valor` (numeric) SEM_API — usada só internamente (serviço/modelo)
**documentos_tesouraria** — controllers: CartasPagamentoController, ImportacaoTesourariaController, TesourariaController; ecrãs: frontend/src/modulos/pos/lavandaria/DetalheOrdem.tsx, frontend/src/modulos/teso/Historico.tsx, frontend/src/modulos/teso/Integracao.tsx
- `taxa_cambio_id` (bigint) SO_BACKEND
**itens_documento_tesouraria** — controllers: TesourariaController; ecrãs: —
- `cambial_moeda_documento` (text) SO_BACKEND
- `cambial_saldo_kz` (numeric) SO_BACKEND
- `cambial_saldo_moeda` (numeric) SO_BACKEND
- `centro_custo_id` (bigint) SO_BACKEND
- `codigo_conta` (character varying) SO_BACKEND
- `codigo_moeda` (character varying) SO_BACKEND
- `codigo_projeto` (text) SO_BACKEND
- `descricao` (text) SO_BACKEND
- `documento_tesouraria_id` (bigint) SO_BACKEND
- `fatura_compra_id` (bigint) SO_BACKEND
- `nif_importado` (character varying) SO_BACKEND
- `nota_demonstracao_id` (bigint) SO_BACKEND
- `nota_fluxo_caixa_id` (bigint) SO_BACKEND
- `numero_documento` (character varying) SO_BACKEND
- `projeto_id` (bigint) SO_BACKEND
- `taxa_cambio` (numeric) SO_BACKEND
- `taxa_cambio_id` (bigint) SO_BACKEND
- `taxa_cambio_manual` (boolean) SO_BACKEND
- `terceiro_id` (bigint) SO_BACKEND
- `tipo_dc` (character varying) SO_BACKEND
- `unidade_negocio_id` (bigint) SO_BACKEND
- `valor` (numeric) SO_BACKEND
- `valor_kz_documento` (numeric) SO_BACKEND
- `venda_id` (bigint) SO_BACKEND
**movimentos_caixa** — controllers: OperacoesTesourariaController; ecrãs: frontend/src/modulos/pos/Desvios.tsx, frontend/src/modulos/pos/Prestacao.tsx, frontend/src/modulos/pos/Relatorios.tsx
- `contra_moeda` (character varying) SO_BACKEND
- `contravalor_kz` (numeric) SO_BACKEND
- `contravalor_moeda` (character varying) SO_BACKEND
- `fatura_compra_id` (bigint) SO_BACKEND — só nos tipos TypeScript
- `taxa_cambio` (numeric) SO_BACKEND — só nos tipos TypeScript
- `taxa_cambio_id` (bigint) SO_BACKEND
**reconciliacoes_bancarias** — controllers: —; ecrãs: —
- `data` (timestamp with time zone) SEM_API — usada só internamente (serviço/modelo)
- `detalhes` (text) SEM_API — usada só internamente (serviço/modelo)
- `estado` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `importacao_codigo` (character varying) ORFA
- `reconciliacao_codigo` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `tipo` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `valor_total` (numeric) SEM_API — usada só internamente (serviço/modelo)

### Contabilidade

**execucoes_consolidacao** — controllers: —; ecrãs: frontend/src/modulos/contab/Consolidacao.tsx
- `codigo_moeda` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `empresa_holding_id` (bigint) SEM_API — usada só internamente (serviço/modelo)
- `grupo_consolidacao_id` (bigint) SEM_API — usada só internamente (serviço/modelo)
- `nome_utilizador` (character varying) SEM_API — usada só internamente (serviço/modelo)
**grupos_consolidacao** — controllers: —; ecrãs: frontend/src/modulos/contab/Consolidacao.tsx
- `empresa_holding_id` (bigint) SEM_API — usada só internamente (serviço/modelo)
**lancamentos_contabeis** — controllers: LancamentoController; ecrãs: frontend/src/layout/fundoModulo.ts, frontend/src/modulos/contab/lancamentos/DetalheLancamento.tsx, frontend/src/modulos/contab/lancamentos/ListaLancamentos.tsx
- `arredondamento_cambial` (integer) SEM_API — usada só internamente (serviço/modelo)
- `documento_origem_id` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `empresa_intragrupo_id` (bigint) SEM_API — usada só internamente (serviço/modelo)
- `estornado_em` (timestamp with time zone) SO_BACKEND — só nos tipos TypeScript
- `execucao_consolidacao_id` (bigint) SEM_API — usada só internamente (serviço/modelo)
- `linha_origem_id` (bigint) SEM_API — usada só internamente (serviço/modelo)
- `periodo_contabil` (integer) SEM_API — usada só internamente (serviço/modelo)
- `projeto_id` (bigint) SO_BACKEND
- `reconciliacao_codigo` (character varying) SO_BACKEND — só nos tipos TypeScript
- `referencia_documento` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `sessao_pos_id` (bigint) SEM_API — usada só internamente (serviço/modelo)
- `sistema_origem` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `tarefa_projeto_id` (bigint) SEM_API — usada só internamente (serviço/modelo)
- `taxa_cambio_id` (bigint) SEM_API — usada só internamente (serviço/modelo)
- `tipo_consolidacao` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `tipo_documento_origem` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `valor_imposto` (numeric) SEM_API — usada só internamente (serviço/modelo)
- `valor_kz_origem` (numeric) SEM_API — usada só internamente (serviço/modelo)
**lancamentos_estornados** — controllers: —; ecrãs: —
- `centro_custo_id` (bigint) SEM_API — usada só internamente (serviço/modelo)
- `codigo_conta` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `codigo_projeto` (text) ORFA
- `data_documento` (date) SEM_API — usada só internamente (serviço/modelo)
- `descricao` (text) SEM_API — usada só internamente (serviço/modelo)
- `diario_id` (bigint) SEM_API — usada só internamente (serviço/modelo)
- `documento_origem_id` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `lancamento_original_id` (bigint) SEM_API — usada só internamente (serviço/modelo)
- `nome_utilizador` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `nota_demonstracao_id` (bigint) SEM_API — usada só internamente (serviço/modelo)
- `nota_fluxo_caixa_id` (bigint) SEM_API — usada só internamente (serviço/modelo)
- `numero_documento` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `numero_lan` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `periodo_contabil` (integer) SEM_API — usada só internamente (serviço/modelo)
- `projeto_id` (bigint) SEM_API — usada só internamente (serviço/modelo)
- `reconciliacao_codigo` (text) ORFA
- `referencia` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `sistema_origem` (character varying) ORFA
- `terceiro_id` (bigint) SEM_API — usada só internamente (serviço/modelo)
- `tipo_dc` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `tipo_documento_origem` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `unidade_negocio_id` (bigint) SEM_API — usada só internamente (serviço/modelo)
- `url_documento` (character varying) SEM_API — sem uso em código; o legado tinha dados
- `valor` (numeric) SEM_API — usada só internamente (serviço/modelo)
- `valor_imposto` (numeric) SEM_API — usada só internamente (serviço/modelo)
**membros_consolidacao** — controllers: —; ecrãs: —
- `empresa_membro_id` (bigint) SEM_API — usada só internamente (serviço/modelo)
- `grupo_consolidacao_id` (bigint) SEM_API — usada só internamente (serviço/modelo)
- `metodo` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `percentagem` (numeric) SEM_API — usada só internamente (serviço/modelo)
**relatorios_anuais_contas** — controllers: —; ecrãs: —
- `ano_relatorio` (integer) SEM_API — usada só internamente (serviço/modelo)
- `concluido_em` (timestamp with time zone) SEM_API — usada só internamente (serviço/modelo)
- `concluido_por` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `estado` (character varying) SEM_API — usada só internamente (serviço/modelo)

### RH

**ausencias_faltas_colaboradores** — controllers: AssiduidadeController; ecrãs: frontend/src/modulos/rh/Assiduidade.tsx, frontend/src/modulos/rh/Portal.tsx
- `decidido_em` (timestamp with time zone) SO_BACKEND
- `decidido_por` (character varying) SO_BACKEND
- `fecho_mensal_assiduidade_id` (bigint) SO_BACKEND
- `pendente_no_fecho` (boolean) SO_BACKEND
**avaliacoes_desempenho_rh** — controllers: AvaliacaoController, AvaliacaoEquipaController; ecrãs: frontend/src/modulos/rh/Avaliacao.tsx, frontend/src/modulos/rh/AvaliacaoCiclo.tsx, frontend/src/modulos/rh/comum/AMinhaEquipa.tsx
- `atualizado_360_em` (timestamp with time zone) SO_BACKEND
- `avisos_360` (jsonb) SO_BACKEND
- `componentes_360` (jsonb) SO_BACKEND
- `concluida_em` (timestamp with time zone) SO_BACKEND
- `concluida_por` (character varying) SO_BACKEND
- `reaberta_em` (timestamp with time zone) SO_BACKEND — só nos tipos TypeScript
- `reaberta_por` (character varying) SO_BACKEND — só nos tipos TypeScript
**bonificacoes_avaliacao_rh** — controllers: AvaliacaoController; ecrãs: frontend/src/modulos/rh/AvaliacaoCiclo.tsx, frontend/src/modulos/rh/AvaliacaoConfig.tsx, frontend/src/modulos/rh/comum/PortalAvaliacao.tsx
- `avaliacao_desempenho_id` (bigint) SO_BACKEND — só nos tipos TypeScript
- `lancado_em` (timestamp with time zone) SO_BACKEND
- `lancado_por` (character varying) SO_BACKEND
- `linha_folha_salarial_id` (bigint) SO_BACKEND
**confirmacoes_avaliacao_rh** — controllers: —; ecrãs: —
- `ciclo_avaliacao_id` (bigint) SEM_API — usada só internamente (serviço/modelo)
- `colaborador_id` (bigint) SEM_API — usada só internamente (serviço/modelo)
- `em` (timestamp with time zone) SEM_API — usada só internamente (serviço/modelo)
**dependentes_colaboradores** — controllers: —; ecrãs: —
- `colaborador_id` (bigint) SEM_API — usada só internamente (serviço/modelo)
- `nome` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `ordem` (integer) SEM_API — usada só internamente (serviço/modelo)
- `origem` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `sexo` (character varying) SEM_API — usada só internamente (serviço/modelo)
**emprestimos_colaboradores** — controllers: EmprestimosController; ecrãs: frontend/src/modulos/rh/Emprestimos.tsx, frontend/src/modulos/rh/calcular/ImportarEmprestimos.tsx, frontend/src/modulos/rh/portal/PortalFaseB.tsx
- `decidido_em` (timestamp with time zone) SO_BACKEND — só nos tipos TypeScript
- `pedido_portal_colaborador_id` (bigint) SO_BACKEND
**fechos_mensais_assiduidade** — controllers: AssiduidadeController; ecrãs: frontend/src/modulos/rh/Assiduidade.tsx
- `periodo_processamento_salarial_id` (bigint) SO_BACKEND
**feedbacks_avaliacao_360** — controllers: AvaliacaoController, AvaliacaoEquipaController; ecrãs: frontend/src/modulos/rh/comum/AMinhaEquipa.tsx, frontend/src/modulos/rh/comum/PortalAvaliacao.tsx
- `colaborador_chefia_id` (bigint) SO_BACKEND
**habilitacoes_colaboradores** — controllers: —; ecrãs: —
- `colaborador_id` (bigint) SEM_API — usada só internamente (serviço/modelo)
- `curso` (character varying) ORFA
- `estado` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `nivel` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `ordem` (character varying) SEM_API — usada só internamente (serviço/modelo)
**itens_carta_pagamento** — controllers: —; ecrãs: —
- `banco` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `carta_pagamento_bancario_id` (bigint) SEM_API — usada só internamente (serviço/modelo)
- `colaborador_id` (bigint) SEM_API — usada só internamente (serviço/modelo)
- `iban` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `montante` (numeric) SEM_API — usada só internamente (serviço/modelo)
- `nif` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `nome` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `numero_conta` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `referencia` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `terceiro_id` (bigint) SEM_API — usada só internamente (serviço/modelo)
**linhas_folha_salarial** — controllers: FolhaSalarialController; ecrãs: frontend/src/modulos/estrutura/faseK/api.ts, frontend/src/modulos/rh/Calcular.tsx, frontend/src/modulos/rh/Processamento.tsx
- `origem_efetividade` (boolean) SO_BACKEND
- `origem_produtividade` (boolean) SO_BACKEND
**mapeamentos_contabeis_rh** — controllers: —; ecrãs: —
- `avencado` (boolean) SEM_API — usada só internamente (serviço/modelo)
- `infotipo_salarial_id` (bigint) SEM_API — usada só internamente (serviço/modelo)
- `numero_conta` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `tipo_organizacao_id` (bigint) SEM_API — usada só internamente (serviço/modelo)
**mapeamentos_contabeis_sistema_rh** — controllers: —; ecrãs: —
- `avencado` (boolean) SEM_API — usada só internamente (serviço/modelo)
- `codigo` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `numero_conta` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `tipo_organizacao_id` (bigint) SEM_API — usada só internamente (serviço/modelo)
**modelos_documentos_rh** — controllers: —; ecrãs: —
- `ativo` (boolean) SEM_API — usada só internamente (serviço/modelo)
- `codigo` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `local` (text) SEM_API — usada só internamente (serviço/modelo)
- `nome` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `texto` (text) SEM_API — usada só internamente (serviço/modelo)
- `titulo` (character varying) SEM_API — usada só internamente (serviço/modelo)
**participacoes_ascendentes_rh** — controllers: —; ecrãs: —
- `ano` (integer) SEM_API — usada só internamente (serviço/modelo)
- `colaborador_alvo_id` (bigint) SEM_API — usada só internamente (serviço/modelo)
- `colaborador_id` (bigint) SEM_API — usada só internamente (serviço/modelo)
- `periodo` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `uid` (character varying) SEM_API — usada só internamente (serviço/modelo)
**participantes_avaliacao_360** — controllers: —; ecrãs: —
- `ciclo_avaliacao_id` (bigint) SEM_API — usada só internamente (serviço/modelo)
- `colaborador_avaliador_id` (bigint) SEM_API — usada só internamente (serviço/modelo)
- `grupo` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `uid` (character varying) SEM_API — usada só internamente (serviço/modelo)
**pedidos_portal_colaborador** — controllers: PortalColaboradorController, PortalEntregaveisController; ecrãs: frontend/src/modulos/rh/Portal.tsx, frontend/src/modulos/rh/PortalGestao.tsx, frontend/src/modulos/rh/portal/Aprovacoes.tsx
- `ausencia_falta_id` (bigint) SO_BACKEND
- `cancelado_em` (timestamp with time zone) SO_BACKEND — só nos tipos TypeScript
- `plano_ferias_colaborador_id` (bigint) SO_BACKEND
**periodos_produtividade_rh** — controllers: EntregaveisController, FeriasProdutividadeController, FiRhController, ImportacaoRHController, ProdutividadeProjetosController; ecrãs: frontend/src/modulos/estrutura/tarefas/api.ts, frontend/src/modulos/projectos/comum/PonteProdutividade.tsx, frontend/src/modulos/rh/Calcular.tsx
- `periodo_processamento_salarial_id` (bigint) SO_BACKEND — só nos tipos TypeScript
- `registos_fecho` (integer) SO_BACKEND — só nos tipos TypeScript
**prestacoes_emprestimos** — controllers: —; ecrãs: —
- `colaborador_id` (bigint) SEM_API — usada só internamente (serviço/modelo)
- `descontado_por` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `estado` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `juro` (numeric) SEM_API — usada só internamente (serviço/modelo)
- `linha_folha_salarial_id` (bigint) SEM_API — usada só internamente (serviço/modelo)
- `mes_ano` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `numero` (integer) SEM_API — usada só internamente (serviço/modelo)
- `periodo_processamento_salarial_id` (bigint) SEM_API — usada só internamente (serviço/modelo)
- `saldo` (numeric) SEM_API — usada só internamente (serviço/modelo)
- `total` (numeric) SEM_API — usada só internamente (serviço/modelo)
**registos_produtividade_rh** — controllers: FeriasProdutividadeController, FiRhController; ecrãs: frontend/src/modulos/estrutura/tarefas/api.ts, frontend/src/modulos/rh/Calcular.tsx, frontend/src/modulos/rh/Produtividade.tsx
- `fonte` (jsonb) SO_BACKEND
- `periodo_produtividade_id` (bigint) SO_BACKEND — só nos tipos TypeScript
**respostas_ascendentes_rh** — controllers: —; ecrãs: —
- `ano` (integer) SEM_API — usada só internamente (serviço/modelo)
- `colaborador_alvo_id` (bigint) SEM_API — usada só internamente (serviço/modelo)
- `periodo` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `uid` (character varying) SEM_API — usada só internamente (serviço/modelo)
**respostas_avaliacao_360** — controllers: —; ecrãs: —
- `ciclo_avaliacao_id` (bigint) SEM_API — usada só internamente (serviço/modelo)
- `grupo` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `notas` (jsonb) SEM_API — usada só internamente (serviço/modelo)
- `uid` (character varying) SEM_API — usada só internamente (serviço/modelo)

### Activos

**andares_edificios** — controllers: LocaisAtivosController; ecrãs: frontend/src/modulos/activos/edificios/Modais.tsx, frontend/src/modulos/activos/edificios/Planta.tsx
- `ordem` (integer) SO_BACKEND — só nos tipos TypeScript
**ativos_imobilizados** — controllers: AmortizacoesController, AtivosController, LocaisAtivosController; ecrãs: frontend/src/modulos/activos/Activos.tsx, frontend/src/modulos/activos/Amortizacoes.tsx, frontend/src/modulos/activos/Categorias.tsx
- `lancamento_contabil_id` (bigint) SO_BACKEND — só nos tipos TypeScript
**inventarios_fisicos_ativos** — controllers: LocaisAtivosController; ecrãs: frontend/src/modulos/activos/Activos.tsx, frontend/src/modulos/activos/DetalheActivo.tsx, frontend/src/modulos/activos/ModalActivo.tsx
- `anulado_em` (timestamp with time zone) SO_BACKEND
- `anulado_por` (character varying) SO_BACKEND
- `contado_em` (timestamp with time zone) SO_BACKEND
- `fechado_em` (timestamp with time zone) SO_BACKEND — só nos tipos TypeScript
- `fechado_por` (character varying) SO_BACKEND — só nos tipos TypeScript
- `valor_esperado` (numeric) SO_BACKEND — só nos tipos TypeScript
**itens_ordem_manutencao** — controllers: ManutencaoPlaneadaController; ecrãs: frontend/src/modulos/activos/manutencao/Ordens.tsx
- `ordem_manutencao_id` (bigint) SO_BACKEND — só nos tipos TypeScript
- `origem_tipo` (text) SO_BACKEND
- `pedido_compra_id` (bigint) SO_BACKEND — só nos tipos TypeScript
- `quantidade_armazem` (numeric) SO_BACKEND
- `quantidade_compra` (numeric) SO_BACKEND
- `requisicao_manutencao_id` (bigint) SO_BACKEND — só nos tipos TypeScript
**linhas_inventario_fisico_ativos** — controllers: LocaisAtivosController; ecrãs: frontend/src/modulos/activos/edificios/Folha.tsx
- `inventario_fisico_id` (bigint) SO_BACKEND
**linhas_requisicao_manutencao** — controllers: —; ecrãs: —
- `custo` (numeric) SEM_API — usada só internamente (serviço/modelo)
- `descricao` (text) SEM_API — usada só internamente (serviço/modelo)
- `estado` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `item_ordem_manutencao_id` (bigint) SEM_API — usada só internamente (serviço/modelo)
- `produto_id` (bigint) SEM_API — usada só internamente (serviço/modelo)
- `quantidade` (numeric) SEM_API — usada só internamente (serviço/modelo)
- `requisicao_manutencao_id` (bigint) SEM_API — usada só internamente (serviço/modelo)
**ordens_manutencao_ativos** — controllers: ManutencaoPlaneadaController, TarefasOrdemManutencaoController; ecrãs: frontend/src/modulos/activos/manutencao/Ordens.tsx, frontend/src/modulos/activos/manutencao/Planos.tsx, frontend/src/modulos/activos/manutencao/Tarefas.tsx
- `cancelado_em` (timestamp with time zone) SO_BACKEND
- `cancelado_por` (character varying) SO_BACKEND
- `concluido_em` (timestamp with time zone) SO_BACKEND
- `concluido_por` (character varying) SO_BACKEND — só nos tipos TypeScript
- `data_inicio` (date) SO_BACKEND — só nos tipos TypeScript
- `plano_antes` (jsonb) SO_BACKEND
- `plano_manutencao_id` (bigint) SO_BACKEND — só nos tipos TypeScript
- `reaberta_em` (timestamp with time zone) SO_BACKEND — só nos tipos TypeScript
- `reaberta_por` (character varying) SO_BACKEND — só nos tipos TypeScript
**pedidos_manutencao_equipamentos** — controllers: —; ecrãs: —
- `acao` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `ambito` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `aprovado_em` (timestamp with time zone) SEM_API — usada só internamente (serviço/modelo)
- `aprovado_por` (jsonb) SEM_API — usada só internamente (serviço/modelo)
- `cancelado_em` (timestamp with time zone) SEM_API — usada só internamente (serviço/modelo)
- `cancelado_por` (jsonb) SEM_API — usada só internamente (serviço/modelo)
- `estado` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `executado_por` (jsonb) SEM_API — usada só internamente (serviço/modelo)
- `expira_em` (timestamp with time zone) SEM_API — usada só internamente (serviço/modelo)
- `historico_alteracoes` (jsonb) SEM_API — usada só internamente (serviço/modelo)
- `impacto_na_aprovacao` (jsonb) SEM_API — usada só internamente (serviço/modelo)
- `impacto_na_execucao` (jsonb) SEM_API — usada só internamente (serviço/modelo)
- `justificacao` (text) SEM_API — usada só internamente (serviço/modelo)
- `pedido_por` (jsonb) SEM_API — usada só internamente (serviço/modelo)
- `rejeitado_em` (timestamp with time zone) SEM_API — usada só internamente (serviço/modelo)
- `resultado` (character varying) SEM_API — usada só internamente (serviço/modelo)
**planos_manutencao_ativos** — controllers: ManutencaoPlaneadaController; ecrãs: frontend/src/modulos/activos/manutencao/Ordens.tsx, frontend/src/modulos/activos/manutencao/Planos.tsx, frontend/src/modulos/activos/manutencao/Tarefas.tsx
- `ultima_data` (date) SO_BACKEND — só nos tipos TypeScript
**portas_edificios** — controllers: LocaisAtivosController; ecrãs: frontend/src/modulos/activos/edificios/Modais.tsx
- `ordem` (integer) SO_BACKEND — só nos tipos TypeScript
**registos_manutencao_ativos** — controllers: AtivosController; ecrãs: frontend/src/modulos/activos/Manutencao.tsx
- `ordem_manutencao_id` (bigint) SO_BACKEND — só nos tipos TypeScript
**requisicoes_manutencao** — controllers: ManutencaoPlaneadaController; ecrãs: frontend/src/modulos/activos/manutencao/Requisicoes.tsx
- `cancelado_em` (timestamp with time zone) SO_BACKEND
- `cancelado_por` (character varying) SO_BACKEND
- `ordem_manutencao_id` (bigint) SO_BACKEND — só nos tipos TypeScript
- `satisfeita_em` (timestamp with time zone) SO_BACKEND
**transferencias_centros_custo_ativos** — controllers: AtivosController; ecrãs: frontend/src/modulos/activos/Activos.tsx, frontend/src/modulos/activos/ModaisActivos.tsx
- `centro_custo_origem_id` (bigint) SO_BACKEND — só nos tipos TypeScript
- `codigo_projeto` (text) SO_BACKEND

### CRM

**atividades_comerciais_crm** — controllers: AtividadesCRMController, ContasCRMController, OportunidadesCRMController; ecrãs: frontend/src/modulos/crm/Agenda.tsx, frontend/src/modulos/crm/Contas.tsx, frontend/src/modulos/crm/Pipeline.tsx
- `concluida_em` (timestamp with time zone) SO_BACKEND — só nos tipos TypeScript
- `concluida_por` (character varying) SO_BACKEND
- `sequencia_campanha_id` (bigint) SO_BACKEND
**oportunidades_venda_crm** — controllers: ContasCRMController, OportunidadesCRMController; ecrãs: frontend/src/modulos/crm/Contas.tsx, frontend/src/modulos/crm/Pipeline.tsx, frontend/src/modulos/crm/comum/Oportunidade.tsx
- `etapa_desde` (timestamp with time zone) SO_BACKEND — só nos tipos TypeScript
- `fechado_em` (timestamp with time zone) SO_BACKEND — só nos tipos TypeScript
- `ultima_atividade_em` (timestamp with time zone) SO_BACKEND — só nos tipos TypeScript

### Projectos

**assuntos_metodo_trabalho** — controllers: AssuntosMetodoController, ProjetoController; ecrãs: frontend/src/modulos/metodo/Assuntos.tsx, frontend/src/modulos/metodo/FormularioAssunto.tsx
- `cancelado_em` (timestamp with time zone) SO_BACKEND
- `cancelado_por` (character varying) SO_BACKEND
- `concluido_em` (timestamp with time zone) SO_BACKEND
- `concluido_por` (character varying) SO_BACKEND
- `pedido_compra_id` (bigint) SO_BACKEND — só nos tipos TypeScript
- `responsavel_principal_ref` (integer) SO_BACKEND
**configuracoes_projetos** — controllers: —; ecrãs: frontend/src/componentes/icones/iconesModulos.tsx, frontend/src/modulos/projectos/separadores/Configuracao.tsx, frontend/src/modulos/registo.tsx
- `projeto_id` (bigint) SEM_API — usada só internamente (serviço/modelo)
**documentos_projeto** — controllers: —; ecrãs: frontend/src/modulos/projectos/ModalProjecto.tsx
- `data_carregamento` (date) ORFA
- `nome_ficheiro` (character varying) ORFA
- `tipo_documento_id` (bigint) ORFA
**faturacao_projetos** — controllers: —; ecrãs: frontend/src/componentes/icones/iconesModulos.tsx, frontend/src/modulos/projectos/separadores/Revisoes.tsx
- `projeto_id` (bigint) ORFA
**linhas_orcamento_projeto** — controllers: ExecucaoController, OrcamentoQuantidadesController; ecrãs: frontend/src/modulos/activos/ModaisActivos.tsx, frontend/src/modulos/activos/comum/componentes.tsx, frontend/src/modulos/projectos/Carteira.tsx
- `mapa_quantidades` (jsonb) SO_BACKEND
- `mapas_quantidades` (jsonb) SO_BACKEND
**linhas_requisicao_projeto** — controllers: —; ecrãs: frontend/src/modulos/projectos/separadores/Orcamento.tsx
- `requisicao_material_projeto_id` (bigint) SEM_API — usada só internamente (serviço/modelo)
**linhas_revisao_projeto** — controllers: —; ecrãs: —
- `colaborador_id` (bigint) SEM_API — usada só internamente (serviço/modelo)
- `documento_gerado_id` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `revisao_mensal_projeto_id` (bigint) SEM_API — usada só internamente (serviço/modelo)
- `tarefa_projeto_id` (bigint) SEM_API — usada só internamente (serviço/modelo)
- `terceiro_id` (bigint) SEM_API — usada só internamente (serviço/modelo)
- `tipo` (character varying) SEM_API — usada só internamente (serviço/modelo)
**membros_equipa_projeto** — controllers: EquipaController; ecrãs: —
- `colaborador_id` (bigint) SO_BACKEND
- `equipa_projeto_id` (bigint) SO_BACKEND — só nos tipos TypeScript
- `papel` (character varying) SO_BACKEND
- `terceiro_id` (bigint) SO_BACKEND
**razao_analitico_projetos** — controllers: ExecucaoController; ecrãs: frontend/src/modulos/activos/ModaisActivos.tsx, frontend/src/modulos/activos/comum/componentes.tsx, frontend/src/modulos/projectos/Carteira.tsx
- `documento_origem_id` (character varying) SO_BACKEND
- `lancamento_contabil_id` (bigint) SO_BACKEND — só nos tipos TypeScript
- `modulo_origem` (character varying) SO_BACKEND
- `tipo_documento_origem` (character varying) SO_BACKEND
**requisicoes_material_projeto** — controllers: —; ecrãs: frontend/src/modulos/projectos/separadores/Requisicoes.tsx
- `projeto_id` (bigint) SEM_API — usada só internamente (serviço/modelo)
**tarefas_projeto** — controllers: ExecucaoMedidaController, PlaneamentoController, ProjetoController; ecrãs: frontend/src/modulos/activos/ModaisActivos.tsx, frontend/src/modulos/activos/comum/componentes.tsx, frontend/src/modulos/projectos/Carteira.tsx
- `meta_execucoes` (jsonb) SO_BACKEND

### Orçamento

**linhas_previsao_orcamental** — controllers: —; ecrãs: —
- `previsao_orcamental_id` (bigint) SEM_API — usada só internamente (serviço/modelo)
- `rubrica_orcamental_id` (bigint) SEM_API — usada só internamente (serviço/modelo)
**previsoes_orcamentais** — controllers: PlaneamentoOrcamentalController; ecrãs: frontend/src/modulos/orcamento/Previsoes.tsx
- `revisao_origem_id` (bigint) SO_BACKEND

### Estrutura

**delegacoes_chefia** — controllers: DelegacoesChefiaController; ecrãs: frontend/src/modulos/estrutura/faseK/api.ts
- `ambito` (jsonb) SO_BACKEND — só nos tipos TypeScript
- `data_fim` (date) SO_BACKEND — só nos tipos TypeScript
- `data_inicio` (date) SO_BACKEND — só nos tipos TypeScript
- `estado` (character varying) SO_BACKEND — só nos tipos TypeScript
- `revogada_em` (timestamp with time zone) SO_BACKEND — só nos tipos TypeScript
- `revogada_por` (character varying) SO_BACKEND — só nos tipos TypeScript
**entregaveis_tarefa_unidade** — controllers: EntregaveisController, PortalEntregaveisController; ecrãs: frontend/src/modulos/estrutura/Estrutura.tsx, frontend/src/modulos/estrutura/faseK/api.ts, frontend/src/modulos/estrutura/multas/Multas.tsx
- `copiado_de_id` (bigint) SO_BACKEND — só nos tipos TypeScript
**execucoes_entregavel** — controllers: EntregaveisController, PortalEntregaveisController; ecrãs: frontend/src/modulos/estrutura/faseK/api.ts, frontend/src/modulos/estrutura/tarefas/api.ts
- `colaborador_id` (bigint) SO_BACKEND — só nos tipos TypeScript
- `convertida_em` (timestamp with time zone) SO_BACKEND — só nos tipos TypeScript
- `convertida_por` (character varying) SO_BACKEND — só nos tipos TypeScript
- `data` (date) SO_BACKEND — só nos tipos TypeScript
- `devolucao_anterior` (jsonb) SO_BACKEND
- `entregavel_id` (bigint) SO_BACKEND — só nos tipos TypeScript
- `observacoes` (text) SO_BACKEND — só nos tipos TypeScript
- `origem` (character varying) SO_BACKEND — só nos tipos TypeScript
- `periodo_produtividade_id` (bigint) SO_BACKEND — só nos tipos TypeScript
- `quantidade` (numeric) SO_BACKEND — só nos tipos TypeScript
- `registado_por` (character varying) SO_BACKEND — só nos tipos TypeScript
- `registado_por_colaborador_id` (bigint) SO_BACKEND — só nos tipos TypeScript
- `tarefa_unidade_id` (bigint) SO_BACKEND — só nos tipos TypeScript
- `unidade_organica_id` (bigint) SO_BACKEND — só nos tipos TypeScript
- `validada_por_colaborador_id` (bigint) SO_BACKEND — só nos tipos TypeScript
**impedimentos_entregavel** — controllers: EntregaveisController, PortalEntregaveisController; ecrãs: frontend/src/modulos/estrutura/tarefas/api.ts
- `colaborador_id` (bigint) SO_BACKEND — só nos tipos TypeScript
- `decidido_em` (timestamp with time zone) SO_BACKEND — só nos tipos TypeScript
- `decidido_por` (character varying) SO_BACKEND — só nos tipos TypeScript
- `decidido_por_colaborador_id` (bigint) SO_BACKEND
- `entregavel_id` (bigint) SO_BACKEND — só nos tipos TypeScript
- `estado` (character varying) SO_BACKEND — só nos tipos TypeScript
- `motivo` (text) SO_BACKEND — só nos tipos TypeScript
- `periodo_de` (date) SO_BACKEND — só nos tipos TypeScript
- `prazo` (date) SO_BACKEND — só nos tipos TypeScript
- `tarefa_unidade_id` (bigint) SO_BACKEND — só nos tipos TypeScript
- `unidade_organica_id` (bigint) SO_BACKEND — só nos tipos TypeScript
- `url_documento` (character varying) SO_BACKEND — só nos tipos TypeScript
**multas_entregavel** — controllers: MultasEntregaveisController; ecrãs: frontend/src/modulos/estrutura/Estrutura.tsx, frontend/src/modulos/estrutura/faseK/api.ts
- `decidido_por_colaborador_id` (bigint) SO_BACKEND
- `descontada_em` (timestamp with time zone) SO_BACKEND — só nos tipos TypeScript
- `entregavel_id` (bigint) SO_BACKEND — só nos tipos TypeScript
- `linha_folha_salarial_id` (bigint) SO_BACKEND
- `periodo_processamento_salarial_id` (bigint) SO_BACKEND — só nos tipos TypeScript
- `tarefa_unidade_id` (bigint) SO_BACKEND — só nos tipos TypeScript

### Terceiros

**enderecos_terceiros** — controllers: —; ecrãs: —
- `municipio` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `nif` (character varying) SO_BACKEND
- `pais` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `provincia` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `rua` (character varying) SEM_API — usada só internamente (serviço/modelo)

### POS

**configuracoes_pos** — controllers: —; ecrãs: frontend/src/App.tsx, frontend/src/api/cliente.ts, frontend/src/componentes/icones/iconesModulos.tsx
- `conta_quebra` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `conta_sobra` (character varying) SEM_API — usada só internamente (serviço/modelo)
**estadias_hotel** — controllers: HotelariaController; ecrãs: frontend/src/modulos/pos/frente/PainelEstadia.tsx, frontend/src/modulos/pos/frente/PainelVenda.tsx, frontend/src/modulos/pos/hotelaria/Checkout.tsx
- `fechado_em` (timestamp with time zone) SO_BACKEND — só nos tipos TypeScript
- `fechado_por` (character varying) SO_BACKEND — só nos tipos TypeScript
- `sessao_fecho_id` (bigint) SO_BACKEND
- `sessao_pos_id` (bigint) SO_BACKEND — só nos tipos TypeScript
**liquidacoes_pos** — controllers: PrestacaoContasController; ecrãs: frontend/src/modulos/config/PlataformaSessoes.tsx, frontend/src/modulos/pos/Desvios.tsx, frontend/src/modulos/pos/Integracao.tsx
- `documento_comissao_id` (bigint) SO_BACKEND — só nos tipos TypeScript
- `movimento_caixa_id` (bigint) SO_BACKEND — só nos tipos TypeScript
**pagamentos_lavandaria** — controllers: DocumentosLavandariaController; ecrãs: frontend/src/modulos/pos/lavandaria/DetalheOrdem.tsx
- `pedido_lavandaria_id` (bigint) SO_BACKEND — só nos tipos TypeScript
- `sessao_pos_id` (bigint) SO_BACKEND — só nos tipos TypeScript
**pedidos_lavandaria** — controllers: LavandariaController; ecrãs: frontend/src/modulos/pos/lavandaria/DetalheOrdem.tsx, frontend/src/modulos/pos/lavandaria/Ordens.tsx, frontend/src/modulos/pos/lavandaria/Recepcao.tsx
- `atribuido_por` (character varying) SO_BACKEND
- `colaborador_atribuido_id` (bigint) SO_BACKEND
- `motivo_cancelamento` (text) SO_BACKEND — só nos tipos TypeScript
- `nota_atribuicao` (text) SO_BACKEND
- `sessao_rececao_id` (bigint) SO_BACKEND
**pedidos_lavandaria_faturas** — controllers: DocumentosLavandariaController; ecrãs: —
- `pedido_lavandaria_id` (bigint) SO_BACKEND
- `venda_id` (bigint) SO_BACKEND
**reclamacoes_lavandaria** — controllers: LavandariaController; ecrãs: frontend/src/modulos/pos/lavandaria/DetalheOrdem.tsx, frontend/src/modulos/pos/lavandaria/Reclamacoes.tsx
- `decidido_em` (timestamp with time zone) SO_BACKEND — só nos tipos TypeScript
- `decidido_por` (character varying) SO_BACKEND — só nos tipos TypeScript
- `linha_pedido_id` (character varying) SO_BACKEND — só nos tipos TypeScript
- `pago_por` (character varying) SO_BACKEND
- `pedido_lavandaria_id` (bigint) SO_BACKEND — só nos tipos TypeScript
**sessoes_pos** — controllers: HotelariaController, POSController, ParidadeContasPOSController, PrestacaoContasController; ecrãs: frontend/src/modulos/activos/Amortizacoes.tsx, frontend/src/modulos/config/PlataformaSessoes.tsx, frontend/src/modulos/pos/Desvios.tsx
- `deliberacao_cancelada` (jsonb) SO_BACKEND
- `descontabilizado_em` (timestamp with time zone) SO_BACKEND — só nos tipos TypeScript
- `descontabilizado_por` (character varying) SO_BACKEND
- `diario_contabilizacao_id` (bigint) SO_BACKEND
**terminais_pos** — controllers: CatalogoServicosPOSController, FrentePOSController, HotelariaController, LavandariaController, POSController, SeriesVendasController; ecrãs: frontend/src/modulos/config/PlataformaSessoes.tsx, frontend/src/modulos/pos/Desvios.tsx, frontend/src/modulos/pos/Integracao.tsx
- `contadores_sessao` (jsonb) SO_BACKEND
- `contadores_z` (jsonb) SO_BACKEND
- `lavandaria_contadores_ft` (jsonb) SO_BACKEND
- `lavandaria_contadores_os` (jsonb) SO_BACKEND
- `lavandaria_contadores_rc` (jsonb) SO_BACKEND

### Acréscimos

**configuracoes_acrescimos_diferimentos** — controllers: —; ecrãs: —
- `contas` (jsonb) SEM_API — usada só internamente (serviço/modelo)
- `diario_id` (bigint) SEM_API — usada só internamente (serviço/modelo)

### Sistema

**criterios_reparticao_un** — controllers: ReparticaoController; ecrãs: frontend/src/componentes/reparticao/CriteriosReparticao.tsx, frontend/src/componentes/reparticao/api.ts
- `linhas` (jsonb) SO_BACKEND
**documentos_anexos** — controllers: —; ecrãs: frontend/src/modulos/pos/Relatorios.tsx, frontend/src/modulos/pos/comum/DetalheSessao.tsx, frontend/src/modulos/pos/frente/FechoZ.tsx
- `conteudo_ficheiro` (text) SEM_API — sem uso em código; o legado tinha dados
- `data_carregamento` (timestamp with time zone) SEM_API — sem uso em código; o legado tinha dados
- `entidade_id` (text) ORFA
- `nome_ficheiro` (character varying) SEM_API — sem uso em código; o legado tinha dados
- `tipo_documento_id` (bigint) SEM_API — sem uso em código; o legado tinha dados
- `tipo_entidade` (character varying) SEM_API — sem uso em código; o legado tinha dados
- `tipo_mime` (character varying) SEM_API — sem uso em código; o legado tinha dados
**empresas** — controllers: AutenticacaoController, RegistoEmpresaController, PaineisController, ClientesController, MinhaContaController, PainelController, PlataformaSaasController, FolhaSalarialController, ParidadeCadastrosRHController, CopiaEmpresaController, EmpresaController, IdentidadeEmpresaController, SubstituicaoContaController, UtilizadorController, LigacaoAgtController; ecrãs: frontend/src/modulos/config/ConfigGeral.tsx, frontend/src/modulos/config/Empresas.tsx, frontend/src/modulos/config/MinhaConta.tsx
- `estado_licenca` (character varying) SO_BACKEND
- `estado_licenca_desde` (timestamp with time zone) SEM_API — usada só internamente (serviço/modelo)
- `execucao_consolidacao_id` (bigint) SEM_API — usada só internamente (serviço/modelo)
- `licenca_inicio` (date) SO_BACKEND
- `motivo_suspensao` (character varying) SO_BACKEND
**ficheiros_regras_ia** — controllers: —; ecrãs: —
- `ativo` (boolean) SEM_API — usada só internamente (serviço/modelo)
- `motor` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `nome` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `nome_regra` (character varying) SEM_API — usada só internamente (serviço/modelo)
**perfis_utilizador** — controllers: AgentesSuporteController, OperadoresPlataformaController, PerfilController; ecrãs: frontend/src/modulos/config/Perfis.tsx, frontend/src/modulos/config/PlataformaAgentesSuporte.tsx, frontend/src/modulos/config/PlataformaOperadores.tsx
- `permissoes_originais` (jsonb) SO_BACKEND
- `restrito_plataforma` (boolean) SO_BACKEND
**regras_internas_ia** — controllers: —; ecrãs: —
- `ativo` (boolean) SEM_API — usada só internamente (serviço/modelo)
- `modelo` (text) SEM_API — usada só internamente (serviço/modelo)
- `nome` (character varying) SEM_API — usada só internamente (serviço/modelo)
**tipos_documento** — controllers: —; ecrãs: —
- `contexto_modulo` (character varying) SEM_API — sem uso em código; o legado tinha dados
- `descricao` (text) ORFA
- `nome` (character varying) SEM_API — usada só internamente (serviço/modelo)
**utilizadores** — controllers: AutenticacaoController, AutenticacaoGoogleController, RegistoEmpresaController, LancamentoController, PaineisController, AdministradoresController, AgentesSuporteController, ClientesController, ConversaSuportePlataformaController, DashboardSuporteController, OperadoresPlataformaController, PainelController, SuporteController, ProjetoController, EstruturaController, PortalColaboradorController, SaudeController, UtilizadorController; ecrãs: frontend/src/App.tsx, frontend/src/modulos/activos/ModaisActivos.tsx, frontend/src/modulos/activos/comum/componentes.tsx
- `google_id` (character varying) SO_BACKEND
- `mfa_ativado_em` (timestamp with time zone) SO_BACKEND
- `mfa_ativo` (boolean) SO_BACKEND
- `mfa_codigos_recuperacao` (jsonb) SO_BACKEND
- `mfa_segredo` (text) SO_BACKEND
- `modulos_permitidos` (jsonb) SEM_API — usada só internamente (serviço/modelo)

### Outros

**agt_ligacoes_empresa** — controllers: —; ecrãs: —
- `ativo` (boolean) SEM_API — usada só internamente (serviço/modelo)
- `certificado_valido_de` (timestamp with time zone) SEM_API — usada só internamente (serviço/modelo)
- `chave_anterior_impressao_digital` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `chave_atualizada_em` (timestamp with time zone) SEM_API — usada só internamente (serviço/modelo)
- `chave_atualizada_por` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `chave_bits` (integer) SEM_API — usada só internamente (serviço/modelo)
- `chave_impressao_digital` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `chave_publica` (text) SEM_API — usada só internamente (serviço/modelo)
- `chave_versao` (integer) SEM_API — usada só internamente (serviço/modelo)
- `credenciais_atualizadas_em` (timestamp with time zone) SEM_API — usada só internamente (serviço/modelo)
- `credenciais_atualizadas_por` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `ultimo_teste_em` (timestamp with time zone) SEM_API — usada só internamente (serviço/modelo)
- `ultimo_teste_mensagem` (text) SEM_API — usada só internamente (serviço/modelo)
- `ultimo_teste_ok` (boolean) SEM_API — usada só internamente (serviço/modelo)
- `webhook_ativo` (boolean) SEM_API — usada só internamente (serviço/modelo)
- `webhook_atualizado_em` (timestamp with time zone) SEM_API — usada só internamente (serviço/modelo)
- `webhook_atualizado_por` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `webhook_segredo` (text) SO_BACKEND
**agt_submissoes** — controllers: —; ecrãs: —
- `estado` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `tentativas` (integer) SEM_API — usada só internamente (serviço/modelo)
- `ultimo_erro` (text) SEM_API — usada só internamente (serviço/modelo)
- `venda_ids` (jsonb) SEM_API — usada só internamente (serviço/modelo)
**alocacoes_aquisicao_ativos** — controllers: —; ecrãs: —
- `ativo_imobilizado_id` (bigint) SEM_API — usada só internamente (serviço/modelo)
- `lancamento_contabil_id` (bigint) SEM_API — usada só internamente (serviço/modelo)
- `valor` (numeric) SEM_API — usada só internamente (serviço/modelo)

### Plataforma

**anexos_comprovativo_pagamento** — controllers: TransferenciasBancariasController, LicencaController; ecrãs: frontend/src/modulos/config/comum/TransferenciasBancariasPendentes.tsx
- `caminho` (character varying) SO_BACKEND
- `enviado_por` (bigint) SO_BACKEND
- `ip` (character varying) SO_BACKEND
- `tamanho_bytes` (bigint) SO_BACKEND
- `tipo_mime` (character varying) SO_BACKEND
**anexos_suporte** — controllers: ConversaSuportePlataformaController, MinhaContaController, LicencaController; ecrãs: frontend/src/modulos/config/Licenca.tsx, frontend/src/modulos/config/MinhaConta.tsx, frontend/src/modulos/config/PlataformaSuporte.tsx
- `caminho` (character varying) SO_BACKEND
- `mensagem_id` (bigint) SO_BACKEND

### Outros

**configuracoes_contabeis_compras** — controllers: —; ecrãs: —
- `chave` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `codigo_conta` (character varying) SEM_API — usada só internamente (serviço/modelo)
**configuracoes_contabeis_logistica** — controllers: —; ecrãs: —
- `chave` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `codigo_conta` (character varying) SEM_API — usada só internamente (serviço/modelo)
**configuracoes_contabeis_tesouraria** — controllers: —; ecrãs: —
- `chave` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `codigo_conta` (character varying) SEM_API — usada só internamente (serviço/modelo)
**configuracoes_gateway** — controllers: —; ecrãs: —
- `chave` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `valor` (text) SEM_API — usada só internamente (serviço/modelo)
**configuracoes_rh** — controllers: —; ecrãs: —
- `chave` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `valor` (jsonb) SEM_API — usada só internamente (serviço/modelo)
**contas_mesa_pos** — controllers: —; ecrãs: —
- `aberta_em` (timestamp with time zone) SEM_API — usada só internamente (serviço/modelo)
- `cliente_id` (bigint) SEM_API — usada só internamente (serviço/modelo)
- `estado` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `fechada_em` (timestamp with time zone) SEM_API — usada só internamente (serviço/modelo)
- `linhas` (jsonb) SEM_API — usada só internamente (serviço/modelo)
- `mesa_pos_id` (bigint) SEM_API — usada só internamente (serviço/modelo)
- `observacoes` (text) SEM_API — usada só internamente (serviço/modelo)
- `percentagem_desconto` (numeric) SEM_API — usada só internamente (serviço/modelo)
- `sessao_pos_id` (bigint) SEM_API — usada só internamente (serviço/modelo)
- `terminal_pos_id` (bigint) SEM_API — usada só internamente (serviço/modelo)
- `venda_id` (bigint) SEM_API — usada só internamente (serviço/modelo)
**ia_accoes** — controllers: —; ecrãs: —
- `conversa_id` (bigint) SEM_API — usada só internamente (serviço/modelo)
- `dados` (jsonb) SEM_API — usada só internamente (serviço/modelo)
- `decidida_em` (timestamp with time zone) SEM_API — usada só internamente (serviço/modelo)
- `estado` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `metodo` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `resultado` (jsonb) SEM_API — usada só internamente (serviço/modelo)
- `tipo` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `url` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `utilizador_id` (bigint) SEM_API — usada só internamente (serviço/modelo)
**ia_anexos** — controllers: —; ecrãs: —
- `caminho` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `conversa_id` (bigint) SEM_API — usada só internamente (serviço/modelo)
- `mime` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `nome` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `utilizador_id` (bigint) SEM_API — usada só internamente (serviço/modelo)

### Plataforma

**ia_atribuicoes** — controllers: IAPlataformaController; ecrãs: frontend/src/modulos/config/PlataformaIA.tsx
- `fornecedor_id` (bigint) SO_BACKEND

### Outros

**ia_consumo** — controllers: —; ecrãs: —
- `conversa_id` (bigint) SEM_API — usada só internamente (serviço/modelo)
- `fornecedor` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `modelo` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `pesquisas_web` (smallint) SEM_API — usada só internamente (serviço/modelo)
- `tokens_entrada` (integer) SEM_API — usada só internamente (serviço/modelo)
- `tokens_saida` (integer) SEM_API — usada só internamente (serviço/modelo)
- `utilizador_id` (bigint) SEM_API — usada só internamente (serviço/modelo)
**ia_conversas** — controllers: —; ecrãs: —
- `fornecedor` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `modelo` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `titulo` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `utilizador_id` (bigint) SEM_API — usada só internamente (serviço/modelo)
**ia_mensagens** — controllers: —; ecrãs: —
- `conversa_id` (bigint) SEM_API — usada só internamente (serviço/modelo)
- `extra` (jsonb) SEM_API — usada só internamente (serviço/modelo)
- `historico` (jsonb) SEM_API — usada só internamente (serviço/modelo)
- `papel` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `texto` (text) SEM_API — usada só internamente (serviço/modelo)
- `tokens_entrada` (integer) SEM_API — usada só internamente (serviço/modelo)
- `tokens_saida` (integer) SEM_API — usada só internamente (serviço/modelo)

### Plataforma

**mensagens_suporte** — controllers: ConversaSuportePlataformaController, MinhaContaController, LicencaController; ecrãs: frontend/src/App.tsx, frontend/src/modulos/config/Licenca.tsx, frontend/src/modulos/config/MinhaConta.tsx
- `ticket_id` (bigint) SO_BACKEND

### Outros

**mesas_pos** — controllers: —; ecrãs: —
- `ativo` (boolean) SEM_API — usada só internamente (serviço/modelo)
- `nome` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `ordem` (integer) SEM_API — usada só internamente (serviço/modelo)
- `terminal_pos_id` (bigint) SEM_API — usada só internamente (serviço/modelo)

### Plataforma

**notas_internas_suporte** — controllers: NotaInternaSuporteController; ecrãs: frontend/src/App.tsx, frontend/src/modulos/config/Licenca.tsx, frontend/src/modulos/config/PlataformaAgentesSuporte.tsx
- `ticket_id` (bigint) SO_BACKEND

### Outros

**notificacoes_sistema** — controllers: —; ecrãs: —
- `autor_nome` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `chave_origem` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `lida` (boolean) SEM_API — usada só internamente (serviço/modelo)
- `link` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `mensagem` (text) SEM_API — usada só internamente (serviço/modelo)
- `tipo` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `titulo` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `utilizador_id` (bigint) SEM_API — usada só internamente (serviço/modelo)
**preferencias_utilizador** — controllers: —; ecrãs: —
- `nome` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `tipo` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `utilizador_id` (bigint) SEM_API — usada só internamente (serviço/modelo)
- `valor` (jsonb) SEM_API — usada só internamente (serviço/modelo)
**rascunhos_reconciliacao** — controllers: —; ecrãs: —
- `codigo_conta` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `observacoes` (text) SEM_API — usada só internamente (serviço/modelo)
**recibos_venda_retencoes** — controllers: —; ecrãs: —
- `codigo_tipo` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `percentagem` (numeric) SEM_API — usada só internamente (serviço/modelo)
- `recibo_venda_id` (bigint) SEM_API — usada só internamente (serviço/modelo)
- `tipo_retencao_fonte_id` (bigint) SO_BACKEND — só nos tipos TypeScript
**registos_conversao_contas_produtos** — controllers: —; ecrãs: —
- `avisos` (jsonb) SEM_API — usada só internamente (serviço/modelo)
- `categoria_anterior_id` (bigint) SEM_API — usada só internamente (serviço/modelo)
- `categoria_criada` (boolean) SEM_API — usada só internamente (serviço/modelo)
- `categoria_nova_id` (bigint) SEM_API — usada só internamente (serviço/modelo)
- `contas` (jsonb) SEM_API — usada só internamente (serviço/modelo)
- `executado_por` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `produto_id` (bigint) SEM_API — usada só internamente (serviço/modelo)
**resultados_folha_salarial** — controllers: —; ecrãs: —
- `avencado` (boolean) SEM_API — usada só internamente (serviço/modelo)
- `avisos` (jsonb) SEM_API — usada só internamente (serviço/modelo)
- `bruto` (numeric) SEM_API — usada só internamente (serviço/modelo)
- `centro_custo_id` (bigint) SEM_API — usada só internamente (serviço/modelo)
- `colaborador_id` (bigint) SEM_API — usada só internamente (serviço/modelo)
- `irt` (numeric) SEM_API — usada só internamente (serviço/modelo)
- `periodo_processamento_salarial_id` (bigint) SEM_API — usada só internamente (serviço/modelo)
- `tipo_organizacao_id` (bigint) SEM_API — usada só internamente (serviço/modelo)
- `unidade_negocio_id` (bigint) SEM_API — usada só internamente (serviço/modelo)
**senhas_pos_armazem** — controllers: —; ecrãs: —
- `armazem_id` (bigint) SEM_API — usada só internamente (serviço/modelo)
- `chamada_em` (timestamp with time zone) SEM_API — usada só internamente (serviço/modelo)
- `chamado_por` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `codigo` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `data` (date) SEM_API — usada só internamente (serviço/modelo)
- `estado` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `numero` (integer) SEM_API — usada só internamente (serviço/modelo)

### Plataforma

**subscricoes_empresa** — controllers: TransferenciasBancariasController, GatewayPagamentoController, LicencaController; ecrãs: frontend/src/modulos/config/Licenca.tsx, frontend/src/modulos/config/comum/TransferenciasBancariasPendentes.tsx
- `cupom_id` (bigint) SO_BACKEND
- `desconto_cupom_tipo` (character varying) SO_BACKEND
- `desconto_cupom_valor` (numeric) SO_BACKEND
- `notas` (text) SO_BACKEND
- `pago_por` (bigint) SO_BACKEND
- `venda_id` (bigint) SO_BACKEND

### Autenticacao

**tenants** — controllers: PlataformaInicialController, RegistoEmpresaController, AuditoriaPlataformaController, ClientesController, MinhaContaController, PainelController, PlanosPlataformaController, PlataformaSaasController, SuporteController, ConfiguracaoSistemaController, DominioPermitidoController, IdentidadeEmpresaController, LicencaController, UtilizadorController; ecrãs: frontend/src/App.tsx, frontend/src/modulos/config/ConfigGeral.tsx, frontend/src/modulos/config/Licenca.tsx
- `logotipo_sistema` (text) SO_BACKEND
- `subscricao_fim` (date) SO_BACKEND
- `subscricao_inicio` (date) SO_BACKEND

### Plataforma

**tickets_suporte** — controllers: ConversaSuportePlataformaController, DashboardSuporteController, MinhaContaController, NotaInternaSuporteController, RespostaRapidaSuporteController, SuporteController, LicencaController; ecrãs: frontend/src/App.tsx, frontend/src/modulos/config/Licenca.tsx, frontend/src/modulos/config/MinhaConta.tsx
- `csat_comentario` (text) SO_BACKEND
- `primeira_resposta_em` (timestamp with time zone) SO_BACKEND
- `sla_violado_avisado_em` (timestamp with time zone) SO_BACKEND

### Outros

**tokens_bi** — controllers: —; ecrãs: —
- `expira_em` (timestamp with time zone) SEM_API — usada só internamente (serviço/modelo)
- `nome` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `revogado_em` (timestamp with time zone) SEM_API — usada só internamente (serviço/modelo)
- `revogado_por` (character varying) SEM_API — usada só internamente (serviço/modelo)

### Plataforma

**transacoes_gateway** — controllers: TransferenciasBancariasController; ecrãs: frontend/src/modulos/config/comum/TransferenciasBancariasPendentes.tsx
- `dados_pedido` (jsonb) SO_BACKEND
- `dados_resposta` (jsonb) SO_BACKEND
- `referencia_externa` (character varying) SO_BACKEND
**usos_cupom** — controllers: CupoesController; ecrãs: —
- `cupom_id` (bigint) SO_BACKEND
- `subscricao_id` (bigint) SO_BACKEND
- `valor_desconto_aplicado` (numeric) SO_BACKEND

### Outros

**utilizacoes_assistente_ia** — controllers: —; ecrãs: —
- `caracteres_texto` (integer) SEM_API — usada só internamente (serviço/modelo)
- `codigo_erro` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `estado` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `modelo` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `motor` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `nome_utilizador` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `tamanho_ficheiro_kb` (integer) SEM_API — usada só internamente (serviço/modelo)
- `tipo_ficheiro` (character varying) SEM_API — usada só internamente (serviço/modelo)
- `tokens_entrada` (integer) SEM_API — usada só internamente (serviço/modelo)
- `tokens_saida` (integer) SEM_API — usada só internamente (serviço/modelo)
- `utilizador_id` (bigint) SEM_API — usada só internamente (serviço/modelo)
**utilizador_empresa** — controllers: —; ecrãs: —
- `colaborador_id` (bigint) SEM_API — usada só internamente (serviço/modelo)
- `utilizador_id` (bigint) SEM_API — usada só internamente (serviço/modelo)
**vendas_documentos_relacionados** — controllers: —; ecrãs: —
- `venda_id` (bigint) SEM_API — usada só internamente (serviço/modelo)
- `venda_relacionada_id` (bigint) SO_BACKEND — só nos tipos TypeScript
**vendas_estadias_hotel** — controllers: —; ecrãs: —
- `estadia_hotel_id` (bigint) SEM_API — usada só internamente (serviço/modelo)
- `venda_id` (bigint) SEM_API — usada só internamente (serviço/modelo)

## Inverso: frontend → backend

### Campos de formulário sem coluna correspondente (16)

Campos `nome:`/`<Form.Item name>` que não são coluna de nenhuma tabela e que nenhum controller/request/serviço referencia (candidatos a código morto ou desalinhamento de nomes; muitos são auxiliares de UI legítimos).

| Campo | Ficheiro |
| :--- | :--- |
| `conta_37` | frontend/src/modulos/acrescimos/comum/formularioItem.ts |
| `principal_sn` | frontend/src/modulos/crm/comum/formularios.ts |
| `concluida_sn` | frontend/src/modulos/crm/comum/formularios.ts |
| `contas_texto` | frontend/src/modulos/orcamento/Rubricas.tsx |
| `contas_prefixo` | frontend/src/modulos/orcamento/Rubricas.tsx |
| `controlo_modo` | frontend/src/modulos/orcamento/Rubricas.tsx |
| `controlo_base` | frontend/src/modulos/orcamento/Rubricas.tsx |
| `activo_sn` | frontend/src/modulos/teso/MeiosPagamento.tsx |
| `padrao_sn` | frontend/src/modulos/teso/MeiosPagamento.tsx |
| `contingencia_sn` | frontend/src/modulos/vendas/agt/FaturacaoEletronica.tsx |
| `preco_modo` | frontend/src/modulos/vendas/produtos/ModalEditarMassaProdutos.tsx |
| `preco_valor` | frontend/src/modulos/vendas/produtos/ModalEditarMassaProdutos.tsx |
| `movimenta_stock_sn` | frontend/src/modulos/vendas/produtos/formularioProduto.ts |
| `e_servico_sn` | frontend/src/modulos/vendas/produtos/formularioProduto.ts |
| `codigo_serie` | frontend/src/modulos/vendas/series/SeriesNumeracao.tsx |
| `contingencia_sn` | frontend/src/modulos/vendas/series/SeriesNumeracao.tsx |

### Chamadas do frontend a rotas inexistentes — 404 latente (2)

- `/plataforma/root/empresas` — frontend/src/paginas/SeletorEmpresasRoot.tsx
- `POST /autenticacao/reautenticar` — frontend/src/sessao/PedidoReautenticacao.tsx

### Rotas da API sem referência no frontend (38)

Procura o prefixo literal da URL em `frontend/src`; rotas só de integração externa (webhooks, AGT, públicas) são esperadas aqui.

- POST `/agt/webhook/{token}` — Vendas\WebhookAgtController
- GET `/ativos/amortizacoes/por-integrar-no-ano` — Ativos\AmortizacoesController@porIntegrarNoAno
- POST `/autenticacao/mfa/confirmar` — Autenticacao\MfaController@confirmarSetup
- POST `/autenticacao/mfa/desactivar` — Autenticacao\MfaController@desactivar
- POST `/autenticacao/mfa/iniciar` — Autenticacao\MfaController@iniciarSetup
- POST `/autenticacao/mfa/verificar` — Autenticacao\MfaController@verificar
- GET `/bi/odata/{conjunto?}` — Integracoes\FeedODataController
- POST `/config/plano-contas/pgca` — Sistema\ParidadeConfigController@sincronizarPgca
- POST `/config/plano-contas/pgca/simular` — Sistema\ParidadeConfigController@simularPgca
- GET `/contabilidade/assistente/ficheiros-regras/estado` — Integracoes\FicheirosRegrasIAController@estado
- GET `/contabilidade/assistente/ficheiros-regras/modelo` — Integracoes\FicheirosRegrasIAController@modelo
- POST `/contabilidade/encerramento/{ano}/cancelar-apuramento` — Contabilidade\EncerramentoController@cancelarApuramento
- GET `/contabilidade/relatorios/balanco/por-unidade` — Reparticao\MapasPorUnidadeController@balanco
- GET `/contabilidade/relatorios/demonstracao-resultados/por-unidade` — Reparticao\MapasPorUnidadeController@demonstracaoResultados
- POST `/contabilidade/rotinas/anular` — Contabilidade\RotinasController@anular
- GET `/licenca/comprovativos/{anexo}` — Sistema\LicencaController@baixarComprovativo
- GET `/licenca/dados-bancarios` — Sistema\LicencaController@dadosBancarios
- POST `/licenca/subscricoes/{subscricao}/simular-confirmacao` — Sistema\GatewayPagamentoController@simularConfirmacao
- POST `/orcamento/verificar` — Orcamento\OrcamentoController@verificar
- POST `/plataforma/saas/empresas/{empresa}/estado-licenca` — Plataforma\PlataformaSaasController@definirEstadoLicenca
- POST `/projetos/folhas-horas/periodos/{periodo}/imputar` — Projetos\ExecucaoController@imputarPeriodo
- POST `/projetos/folhas-horas/periodos/{periodo}/reverter` — Projetos\ExecucaoController@reverterPeriodo
- POST `/rh/assiduidade/escalas/aplicar` — RH\EscalasController@aplicar
- POST `/rh/assiduidade/escalas/copiar` — RH\EscalasController@copiar
- POST `/rh/assiduidade/escalas/do-contrato` — RH\EscalasController@gerarDoContrato
- POST `/rh/assiduidade/escalas/limpar` — RH\EscalasController@limpar
- GET `/rh/avaliacao/360/tarefas` — RH\AvaliacaoController@tarefas360
- POST `/rh/contratos/{contrato}/anular-cessacao` — RH\ContratoEstadoController@anularCessacao
- POST `/rh/contratos/{contrato}/reactivar` — RH\ContratoEstadoController@reactivar
- GET `/rh/estrutura/chefia/{colaborador}` — RH\EstruturaController@chefia
- GET `/rh/estrutura/entregaveis/sugerir-codigo` — Estrutura\EntregaveisController@sugerirCodigo
- GET `/rh/portal/emprestimos/simular` — RH\PortalColaboradorController@simularEmprestimo
- GET `/saude` — SaudeController
- GET `/sistema/dominio-permitido` — Sistema\DominioPermitidoController
- POST `/sistema/notificacoes/teste` — Sistema\NotificacoesController@criarTeste
- GET `/sistema/operacoes/{id}/resultados` — Integracoes\PreferenciasOperacoesController@resultadosOperacao
- POST `/vendas/documentos/{venda}/revalidar` — Vendas\FaturacaoEletronicaController@revalidar
- GET `/vendas/saft/documentos-importados` — Vendas\LigacaoAgtController@documentosImportados
