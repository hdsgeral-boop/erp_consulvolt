# Projectos — separador «Financeiro»

Endpoint: `GET /api/projetos/{id}/financeiro` (leitura: permissões de leitura de projectos). Plano de facturação: `POST/PUT /plano`, `POST /plano/{linha}/cancelar`, `POST|DELETE /plano/{linha}/factura` (permissões `proj_gerir` ou `proj_revisao`).
Código: `ServicoFinanceiroProjetos`, `FormulasFinanceirasProjeto` (funções puras, testadas em `FinanceiroProjetoTest`), `Financeiro.tsx`.

## Fontes de dados (nada é duplicado)

| Indicador | Origem |
| :--- | :--- |
| Orçamento (BAC) | linhas de orçamento do projecto |
| Custo real (AC), custos por rubrica | movimentos analíticos já existentes (compras, folhas de horas, autos, equipamentos) |
| Compromissos | requisições/encomendas ainda não facturadas (analítico) |
| Execução (%) | progresso ponderado das tarefas (`execucao_ponderada`, senão o global) |
| Planeado (%) | curva S prevista (orçamento repartido pelas datas das tarefas) |
| Venda global, facturado | encomenda + aditamentos aprovados; FT/FR menos NC ligadas ao projecto |
| Recebido | `total_bruto − valor_pendente` das FT/FR do projecto |

## Fórmulas (PMBOK — valor acrescentado)

- PV = BAC × % planeado até hoje · EV = BAC × % de execução · AC = custo real
- CV = EV − AC · SV = EV − PV · CPI = EV ÷ AC · SPI = EV ÷ PV
- EAC = BAC ÷ CPI · ETC = EAC − AC · VAC = BAC − EAC · TCPI = (BAC − EV) ÷ (BAC − AC)
- Receita reconhecida = venda global × % de execução; sobre-facturação = facturado − reconhecida
- Margem bruta = facturado − custo real · Saldo a facturar = venda global − facturado
- Retenção = valor facturado × % de retenção da prestação
- Fluxo previsto: entradas = prestações planeadas (líquidas de retenção) no mês previsto; saídas = custo orçado ainda por distribuir (curva S). Só existe com plano de facturação.

Aproximação conhecida: a % planeada usa o último ponto da curva S no mês corrente (mês completo), pelo que o SPI a meio do mês é ligeiramente optimista.

## Indicadores escondidos

Sem orçamento não há PV/EV/CPI/SPI/EAC; sem custo real não há CPI/EAC/ETC/VAC; sem planeado até hoje não há SPI; projectos internos não têm receita nem facturação. O ecrã lista a razão em «Indicadores não apresentados» em vez de inventar valores.

## Alertas

Orçamento excedido (consumo > 100 %), CPI < 0,9 (erro < 0,8), SPI < 0,9 (erro < 0,8), EAC acima do orçamento.

## Plano de facturação e emissão

`faturacao_projetos` guarda prestações (`descricao`, `montante` sem IVA, `data_prevista`, `retencao_pct`, `marco_projeto_id`, `venda_id`, estado PLANEADO/FACTURADO/CANCELADO). A emissão NÃO é duplicada: a factura emite-se na Faturação (série, hash, AGT) escolhendo o projecto; depois associa-se à prestação. Só se associam FT/FR válidas ligadas ao projecto e ainda livres; o documento fiscal nunca é alterado. Tudo é auditado.

## Migrações novas

- `2026_11_30_090000_documentos_projeto_ficheiros` (tipos de documento e metadados dos PDF)
- `2026_11_30_090100_faturacao_projetos_plano` (colunas do plano de facturação)

Aditivas e idempotentes; GRANT a `erp_app` e RLS (`IsolamentoRls`) como as vizinhas.
