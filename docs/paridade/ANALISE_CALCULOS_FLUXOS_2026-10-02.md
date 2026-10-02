# Análise de cálculos, processos e fluxos — legado × novo (2026-10-02)

Agente CAL. Análise e verificação sem alterações ao código; nenhuma escrita na base `erp_consulvolt` (tudo em transacções com ROLLBACK). Sem dados pessoais: só ids internos e valores agregados.

**Estado:** concluída (2026-10-02). Verificação feita por mim nos salários e nos acréscimos e por quatro verificações de domínio (vendas/POS, stock/compras/activos, contabilidade/câmbios/consolidação, orçamento/RH), cujos achados de «erro no novo» confirmei por leitura do código.

Classificações: **ERRO NO NOVO**, **ERRO NO LEGADO corrigido de propósito** (ADR), **DIFERENÇA ACEITE** (ADR), **A DECIDIR PELO UTILIZADOR**.

## Resumo

**Conclusão.** Nos sete domínios, as fórmulas do novo reproduzem as do legado. Todas as diferenças numéricas encontradas nos dados reais são:
- arredondamentos já decididos (ADR-022/029/030/066);
- erros do legado corrigidos de propósito, com ADR;
- problemas de dados, como alterações posteriores à contabilização ou caracteres invisíveis.

Encontrei **10 erros no novo**. O D-SAL-1 já está corrigido. Nenhum altera as fórmulas principais. São lacunas de fluxo ou de bordo: notas às demonstrações, preços POS, notas de crédito sobre documentos POS, custo médio em saídas a custo explícito, transitória das compras migradas, compromissos orçamentais, resíduo na produtividade e limpeza de códigos no ETL.

**Amostras verificadas (resumo):**
- **Salários:** 413 resultados (todos os 63 períodos) e 44 contabilizações simuladas.
- **Vendas:** 102 documentos e 28 vendas POS, mais 8 000 casos sintéticos.
- **Stock e compras:** população inteira (33 produtos de stock, 81 movimentos, 25 facturas).
- **Amortizações:** 1 448 quotas e 102 documentos AM.
- **Contabilidade:** balancete, DR e Balanço em 13 pares empresa × ano; apuramento em 11 pares; selo em 48 meses; grupo de consolidação real.
- **Orçamento:** 24 casos.
- **Acréscimos:** cerca de 5 200 casos.
- **RH:** férias (510 intervalos), assiduidade (3 meses), produtividade, e 4 000 casos de avaliação.

### Erros no novo (corrigir)

| N.º | Domínio | Ficheiro / função | Problema | Correcção |
|---|---|---|---|---|
| D-SAL-1 | Salários | `ServicoFolhaSalarial::contabilizar` | Fotografias LEGADO com 1-2 cêntimos de desequilíbrio → re-contabilização recusada | **CORRIGIDO** (`acertarArredondamento`, ROUNDING_DIFF nos períodos LEGADO) |
| E-VEN-1 | POS / hotel | `POSController.php:172`, `HotelariaController.php:111`, `ServicoVendasPOS::vender` | Preço com > 2 casas: pagamento validado por valor diferente do documento | `decimal:0,2` na validação; normalizar o preço a 2 casas antes de `calcularComIva` e de `$bruto` |
| E-VEN-2 | Vendas | `ServicoDocumentosVenda::converter` / `emitir` (NC) | NC de FR/FT POS recalculada sobre o preço base arredondado (novas) ou com IVA (migradas): total ≠ factura, muitas vezes recusada | Usar `total_linha`/`total` da linha de origem (proporcional em crédito parcial) |
| E-STK-1 | Stock | `ServicoStock::movimentar` (l. 137-145), `ServicoRecalculoStock::recalcularProduto` (l. 138-146) | Saída a custo explícito ≠ custo médio não recalcula o custo médio (stock ≠ conta 26) | Recalcular `cm = (q×cm − q_saída×custo) / (q − q_saída)` quando sobra stock |
| E-STK-2 | Compras | `ServicoContabilizacaoCompras.php:60` | `valor_transitoria_kz` NULL (linhas migradas) → 0 → tudo para diferenças de câmbio | Usar `$liquido` quando `valor_transitoria_kz` é NULL |
| E-CON-1 | Demonstrações | `ServicoDocumentosTesouraria::integrar` (l. 171-174), `ServicoCaixa::contabilizar` (l. 152-155), `ServicoFolhaSalarial::contabilizar`, `ServicoContabilizacaoCompras`; `recoverDataMapping` não portado | Linhas novas sem `nota_demonstracao_id` → saem do Balanço e da DR («por mapear») | Notas como no legado (10 em 43/45; 28/19 salários; 11/9/4 compras; notas do movimento na caixa) e portar a atribuição de notas por prefixo da conta |
| E-CON-2 | ETL | `Migracao/ConversorTipos.php:218` | U+00A0 nas pontas dos códigos não é limpo (conta «75216 » na empresa 1) | Remover espaços, incluindo U+00A0, nas pontas das colunas de código; validação de dados |
| E-ORC-1 | Orçamento | `ServicoControloOrcamental::verificar` (l. 180-181) e `monitor` (l. 333) | Compromissos do ano inteiro em vez de até ao mês do documento → bloqueios indevidos | Filtrar `substr($x['data'], 5, 2) <= $meses` |
| E-ORC-2 | Orçamento | `ServicoControloOrcamental::compromissos` (l. 86) | Factura de encomenda por contabilizar não conta como compromisso nem como realizado | Retirar `->whereNull('i.item_encomenda_id')` |
| E-RH-1 | Produtividade | `ServicoProdutividade::recalcular` (l. 219-221) | Arredondamento intermédio a 3 casas → resíduo (ex.: 50 Kz) | Calcular sem arredondamento intermédio e pôr o resto no último registo |

### Questões a decidir pelo utilizador

1. **D-SAL-2:** degrau de 12 500 Kz na tabela de IRT a 150 000 Kz. Confirmar a tabela contra a lei em vigor.
2. **D-SAL-3:** precisão de `dias_trabalhados` (3 casas; impacto de 10 Kz). Proposta: aceitar.
3. **D-SAL-5:** encerrar a folha com avisos de horas extra/faltas não valorizadas (sem contrato válido). Proposta: bloquear ou pedir confirmação.
4. **D-SAL-4:** desconto com `calculo_horas = FALTA` tratado como falta. Proposta: aceitar e registar no ADR-036.
5. **POS:** o campo `desconto` inclui o arredondamento AGT.
6. **POS:** documento AGT/SAF-T com preço a 2 casas e sem `settlementAmount`.
7. **FT id 122 sem linhas:** não contabilizável no novo.
8. **Recibo de venda:** ficou sem nota de fluxo de caixa.
9. **Stock negativo seguido de entrada:** a diferença fica no stock (alternativa: lançá-la no CMV).
10. **Adjudicação:** câmbio da proposta ou da data da adjudicação.
11. **Facturas de compra com projecto:** conta da rubrica do projecto ou conta do produto.
12. **Venda de activos:** IVA liquidado (nenhum dos dois sistemas o lança).
13. **Diferenças de câmbio:** a justificação do ADR-033/034 está errada (6621 = favorável e 7621 = desfavorável estavam correctas). A configuração está vazia em todas as empresas.
14. **Arredondamento do banco** em documentos em moeda com várias linhas (menor).
15. **Totais do balancete** com «sem saldo zero» (menor).
16. **Monitor orçamental, modo NENHUM:** «AVISO» ≥ 90 % e nunca «EXCEDIDO».
17. **Gasto sem orçamento:** não fica assinalado como desvio desfavorável (menor).
18. **Feriados da empresa:** a lista está vazia.
19. **Férias:** proporcionais do 1.º ano e transporte de saldo (não existem em nenhum dos dois).
20. **Antes do primeiro encerramento:** corrigir o plano de contas (769 e outras totalizadoras com movimento; contas 8xx em falta).

## 1. Salários (motor, fotografia, contabilização, pagamento)

**Ficheiros comparados.** Legado: `js/engine_v2.js` (tabela IRT, INSS, avençado), `js/modules/rh/regras_salariais.js` (valor dia/hora, horas extra, faltas por hora, contrato activo), `js/app_v2.js:5787-6016` (`calculatePeriodData`) e `:6026-6330` (`integratePayrollToJournal`). Novo: `app/Services/RH/MotorSalarial.php`, `ServicoFolhaSalarial.php`, `ServicoPagamentoSalarios.php`, `Projetos/ServicoExecucaoProjetos.php::imputarPeriodo`.

### 1.1 Fórmulas — comparação linha a linha

| Elemento | Legado | Novo (modo LEGADO / modo ATUAL) | Resultado |
|---|---|---|---|
| Tabela IRT | 11 escalões, `fixo + (base − excesso) × taxa`, isento ≤ 150 000 | Constantes idênticas (`MotorSalarial::TABELA_IRT`), mesma fórmula, mesmo critério `base ≤ máx` | Igual |
| INSS | 3 % / 8 % sobre a base INSS; reformado 0 / 0 | LEGADO 3/8; ATUAL taxas da empresa (omissão 3/8); reformado 0/0 | Igual (ATUAL: ADR-036) |
| Base INSS negativa | INSS calculado antes de limitar a base a ≥ 0 | LEGADO igual; ATUAL limita antes | ERRO NO LEGADO corrigido (ADR-036 n.º 3) |
| Pro-rata por dias | `valor ÷ dias_contrato × (trab − contrato)` → H. Extras ou Desc. Falta | Igual (bcmath, escala 10) | Igual |
| Divisor dos dias | `contract_days_month` do contrato, senão `work_days` do colaborador | Igual (`calcular`, l. 219-220); variante COLABORADOR para períodos antigos | Igual |
| Isenção 30 000 Kz | Pelo NOME (alimentação/transporte), sobre o valor CHEIO mesmo com faltas | LEGADO igual; ATUAL pela marcação `conditional_30k` e sobre o valor pago | ERRO NO LEGADO corrigido (ADR-017, ADR-036 n.º 1) |
| Vencimento com `irt=false` | Ignorado (tributado) | ATUAL fora da base IRT | ERRO NO LEGADO corrigido (ADR-036 n.º 2) |
| Avençado | 6,5 % sobre o bruto, sem INSS | LEGADO igual; ATUAL 6,5 % sobre bruto − faltas | ERRO NO LEGADO corrigido (ADR-036 n.º 4) |
| Horas extra por hora | `valor hora = Σ rubricas base_horaria ÷ dias ÷ horas`; +50 % até 30 h, +75 % acima (config. empresa); arred. 2 casas | Igual (`referencia`, l. 153-169) | Igual |
| Faltas por hora | `horas × valor hora`, arred. 2 casas, base INSS ≥ 0 | Igual | Igual |
| Falta manual (desconto) | nome contém «falta» | nome contém «falta» **ou** `calculo_horas = FALTA` | Diferença mínima não documentada (ver 1.4, D-SAL-4) |
| Rubricas OUTROS | Lançadas a crédito no diário | Informativas, fora do bruto e do diário | ERRO NO LEGADO corrigido (ADR-036 n.º 5) |
| Arredondamento | Float; só no diário (`toFixed(2)` por conta) | LEGADO: só no fim; ATUAL: cada componente a 2 casas half-up; líquido = bruto − INSS − IRT − descontos exacto | DIFERENÇA ACEITE (ADR-022/036 n.º 6) |
| Líquido | bruto − INSS trab. − IRT − descontos | Igual | Igual |
| Contrato do mês | `contratoActivo` compara `MM/AAAA-01` com datas ISO (filtro inoperante → 1.º ACTIVO) | LEGADO: 1.º ACTIVO; ATUAL: ACTIVO válido no mês | ERRO NO LEGADO corrigido (comentário l. 415) |

### 1.2 Verificação numérica (dados reais, sem escrita)

Amostra: **todos** os 63 períodos do backup → 413 resultados colaborador × mês, recalculados (a) em Node com o código do legado copiado verbatim e (b) com `ServicoFolhaSalarial::calcular` sobre a base migrada (dentro de `BEGIN … ROLLBACK`), comparando bruto, INSS trabalhador, INSS patronal, base IRT, IRT, descontos e líquido ao cêntimo.

| Comparação | Iguais | Diferentes | Causa das diferenças |
|---|---|---|---|
| Motor modo LEGADO vs legado (divisor contrato) | 410 | 3 | 2 × período 80 (empresa 8, 05/2026): +6,67 e +3,33 Kz no bruto, porque `dias_trabalhados` 27,56756757 foi migrado como 27,568 (coluna `numeric(12,3)`); 1 × período 89: 0,01 Kz (meio-cêntimo exacto vs float) |
| Motor modo LEGADO vs legado (divisor colaborador) | 410 | 3 | As mesmas |
| Fotografia gravada vs recálculo LEGADO (melhor variante) | 407 | 0 | — |
| Modo ATUAL vs legado | 380 | 33 | Todas explicadas pelas correcções do ADR-036/017 (pormenor abaixo) |

Efeito do modo ATUAL nos períodos reais (se fossem recalculados hoje): empresa 8 04/2026 (isenção sobre valor pago: IRT +1 850 Kz no total), empresa 3 05/2026 (IRT +12 522,61 Kz num colaborador — ver D-SAL-2), empresa 8 05/2026 (+1,85 Kz, precisão dos dias), empresa 18 01/2026 (avençado sobre valor pago, `irt=false` e um contrato que só começa em 07/2026 — sem contrato válido as horas extra e faltas por hora não são valorizadas, só fica um aviso). **Nota:** o ADR-036 diz que «em modo ATUAL só a empresa 18 em 01/2026 difere»; com os dados actuais diferem 4 períodos (todos explicados). Convém actualizar a frase.

### 1.3 Contabilização (folha → diário SAL) — simulação ponta-a-ponta

Para os 44 períodos contabilizados: dentro de uma transacção com `ROLLBACK`, `descontabilizar` (estorno) seguido de `contabilizar` a partir da fotografia, comparando conta × D/C × valor com as linhas originais do legado (`SALMMAAAA`).

- **24 períodos idênticos ao cêntimo** (contas, D/C, valores, diário SAL, data = último dia do mês).
- **6 períodos com 0,01 Kz** em INSS (34922/72522) ou sem a linha ROUNDING_DIFF de 0,01 (3772): o legado arredondava por conta agregada, o novo por colaborador — DIFERENÇA ACEITE (ADR-022).
- **4 períodos da empresa 8** com a conta do líquido 3612 → 36121: o mapeamento NET_PAY_CREDIT foi alterado no legado depois da contabilização — dados, não cálculo.
- **4 períodos da empresa 1 (04-07/2026)** com diferenças grandes: são os períodos já conhecidos cujos dados foram alterados depois de contabilizados (ADR-036).
- **1 período (empresa 3, 03/2026)** recusado com `MAPEAMENTO_EM_FALTA` (rubrica «Descontos de Adiantamento», tipo de organização 6): o mapeamento não existe nos dados migrados — dados.
- **5 períodos (28, 87, 97, 99, 117) recusados com «Lançamento desequilibrado»** — ver D-SAL-1.

Mapeamento das contas: rubricas por (infotipo, tipo de organização) e coluna avençado; NET_PAY / IRT / IRT_AVENCADO / INSS_FUNC / INSS_EMP_DEBIT / INSS_EMP_CREDIT — igual ao legado; melhoria: IRT_AVENCADO_CREDIT cai para o primeiro mapeamento quando não há um para o tipo de organização.

Pagamento (`ServicoPagamentoSalarios`): documento de tesouraria PAGAMENTO que debita NET_PAY_CREDIT de cada colaborador e credita a conta 43; impede pagar mais do que o líquido do período — correcto.

Mão de obra por folha de horas (`imputarPeriodo`): `custo hora = (bruto + INSS patronal) ÷ (dias_contrato × 8)` — igual ao legado; o novo trunca `dias_contrato` a inteiro (`(int)`), o legado não (só difere com dias fraccionários; nenhum caso nos dados).

Testes existentes: `MotorSalarialTest`, `SalariosTest`, `SalariosPagamentoTest` — 14 passaram (117 asserções).

### 1.4 Diferenças e classificação

- **D-SAL-1 — ERRO NO NOVO — CORRIGIDO** (pelo coordenador, em `ServicoFolhaSalarial::acertarArredondamento`, chamado em `contabilizar`: linha ROUNDING_DIFF nos períodos LEGADO).  Descrição original: As fotografias em modo LEGADO não fecham ao cêntimo (componentes não arredondados um a um): Σ vencimentos − Σ descontos − líquido − IRT − INSS trab. = +0,02 / −0,01 / +0,02 / −0,01 / −0,02 Kz nos períodos 28, 87, 97, 99 e 117. Se um destes períodos for descontabilizado (estorno), **nunca mais pode ser contabilizado** (`ServicoLancamentos` recusa). O legado absorvia até 10 Kz na conta ROUNDING_DIFF (existe em 3 empresas). Correcção: em `ServicoFolhaSalarial::contabilizar` (antes de `$this->lancamentos->criar`, l. 329-332), calcular `dif = ΣD − ΣC`; se `0 < |dif| ≤ 10` **e** o período estiver em `modo_calculo = 'LEGADO'`, acrescentar uma linha na conta `contaSistema($sistema, 'ROUNDING_DIFF', null, false)` (a crédito se ΣD > ΣC, a débito no caso contrário); sem esse mapeamento, recusar com `MAPEAMENTO_EM_FALTA` (ROUNDING_DIFF). No modo ATUAL a soma fecha sempre por construção e a linha nunca é necessária.
- **D-SAL-2 — A DECIDIR PELO UTILIZADOR (legislação).** A tabela do `engine_v2.js` isenta até 150 000 Kz e aplica 12 500 Kz fixos a partir de 150 000,01 Kz: há um «degrau» de 12 500 Kz. Caso real: empresa 3, 05/2026, um colaborador com base 149 058 Kz no legado (IRT 0) passa a 150 141,33 Kz em modo ATUAL (isenção sobre o valor pago) e o IRT salta para 12 522,61 Kz — o líquido desce 12 522 Kz por 1 083 Kz de base. A tabela é a decidida (ADR-013); pede-se apenas que confirme que reflecte a lei em vigor (escalões até 150 000 Kz e parcela fixa de 12 500 Kz).
- **D-SAL-3 — DIFERENÇA DE MIGRAÇÃO (A DECIDIR, impacto 10 Kz).** `linhas_folha_salarial.dias_trabalhados` é `numeric(12,3)`; o legado guardava dias com 8 casas (ex.: 27,56756757, vindos da efectividade). Efeito: +6,67 e +3,33 Kz no período 80 (que já não confere com o diário por outras razões). O motor também subtrai dias com escala 4 (`bcsub($wd, $dc, 4)`). Opções: aceitar (recomendado) ou alargar a coluna para 6-8 casas e usar `self::E` nessas subtracções.
- **D-SAL-4 — DIFERENÇA não documentada (mínima).** Um DESCONTO com `calculo_horas = 'FALTA'` lançado em valor (sem horas) e cujo nome não contém «falta» é tratado como falta pelo novo (reduz as bases INSS e IRT) e não pelo legado. É mais coerente; registar no ADR-036.
- **D-SAL-5 — A DECIDIR.** Em modo ATUAL, um colaborador com horas extra/faltas por hora mas sem contrato válido no mês fica sem essas rubricas valorizadas (só um aviso). Sugere-se que `encerrar` bloqueie (ou peça confirmação) quando houver avisos deste tipo, para não fechar um mês com faltas e horas extra a zero.


## 2. Vendas, IVA, AGT e POS (incl. lavandaria e hotelaria)

**Ficheiros comparados.** Legado: `js/ui_sales.js` (`saveSale` 1753-1847, `_generateSalePostingLines` 3071-3272, recibos 4828-4891, POS 6747-6788), `js/facturacao_agt.js` (`construir` 234-310, `selar` 436-457), `js/moedas_vendas.js`, `js/moedas_documentos.js`, `js/pos_gestao.js`, `js/pos_prestacao.js`, `js/lavandaria.js`. Novo: `Services/Vendas/CalculadoraDocumento.php`, `ServicoDocumentosVenda.php`, `ServicoContabilizacaoVendas.php`, `ServicoHashSaft.php`, `ServicoSelagemAgt.php`, `ServicoExportacaoSaft.php`, `Services/POS/*`.

**Amostra.** Toda a população: 102 documentos migrados (73 com linhas; FT, FR, NC, OR, PF, NE, GR, GD; 6 empresas; taxas 0 % e 14 %; 7 em USD/EUR), 28 vendas POS e 4 sessões. Sem documentos no regime AGT nem descontos POS nos dados reais, portanto também 3 000 documentos sintéticos (1-4 linhas, quantidades decimais, taxas 0/5/7/14, desconto POS 0-30 %) e 5 000 casos de NC sobre FR POS, com as funções do legado copiadas para Node e a `CalculadoraDocumento` PHP real.

### 2.1 Fórmulas e resultado

| Elemento | Legado | Novo | Verificação | Classificação |
|---|---|---|---|---|
| Linha | Regime AGT: `r2(qtd × r6(preço))`; fora do regime: `qtd × preço` em float, sem arredondar | `arred(qtd × preço, 2)` half-up, bcmath, para todos os documentos | 102/102 totais migrados iguais; 66/66 iguais à fórmula AGT do legado; sintéticos 2 999/3 000 (o caso restante: float do JS num …,485) | Igual / ERRO NO LEGADO corrigido (ADR-022/029) |
| IVA | Regime AGT: por linha, por excesso ao cêntimo; fora do regime: `net × taxa` em float | Por linha, por excesso ao cêntimo (`excessoCentimo`) | 1 FT (id 133): IVA +0,01 | ERRO NO LEGADO corrigido (ADR-029) |
| Desconto de linha / global / financeiro, retenção | Não existem nos documentos normais | Não existem | — | Igual |
| Multi-moeda | Kz = arred(valores na moeda × câmbio) | Preço convertido a 6 casas e regra AGT em Kz | 7/7 totais na moeda iguais; em Kz 5 com IVA +0,01 | DIFERENÇA ACEITE (ADR-030) |
| NC | Tolerância +0,01 acima do saldo; NC só sobre facturas não pagas | Sem tolerância; NC sobre FR permitida | — | Regra decidida (ADR-029); ver E-VEN-2 |
| Hash / SAF-T | Sem assinatura real | RSA-SHA1 `data;entrada;n.º;bruto 2 casas;hash anterior`, por série; `GrossTotal` no mesmo formato | Sem documentos em regime para comparar | ERRO NO LEGADO corrigido (ADR-030) |
| POS: preço com IVA | Fora do regime: cabeçalho `Σ bruto/(1+t)`; em regime: `construir` (maior base com base + IVA por excesso ≤ valor cobrado) | Mesmo algoritmo do `construir` (`calcularComIva`), desconto em % | Sintéticos 2 995/3 000 (5 × 1 cêntimo de ruído float); nas 28 vendas reais, 20 dariam bruto 0,01-0,02 abaixo do preço (ex.: 20 000 → 19 999,99) | DIFERENÇA ACEITE (ADR-050/066); documentos migrados mantêm os valores do legado |
| Troco e pagamentos | Troco só em numerário; TPA + transferências ≤ total | Igual (troco tirado do último numerário; o legado tirava do primeiro) | — | Igual |
| Fecho Z | Esperado = fundo + numerário; pendente se \|desvio\| > tolerância | Igual; desvio dentro da tolerância também lançado | — | ERRO NO LEGADO corrigido (ADR-047) |
| Comissão TPA | pct × talão (ou valor) | Igual | — | Igual |
| Lavandaria | Urgência e adiantamento mínimo; armazenagem a 14 % fixo | Mesma fórmula; armazenagem com o IVA do produto; linhas pela regra AGT (−0,01 ocasional) | Estadia/ordem migrada recalcula igual | ERRO NO LEGADO corrigido / DIFERENÇA ACEITE (ADR-049) |
| Hotel (check-out) | — | `calcularComIva` com rateio dos pagamentos e cêntimo no maior | Factura migrada de 65 000 recalcula igual | DIFERENÇA ACEITE (ADR-050/066) |

Recálculo independente feito por mim (73 documentos com linhas, `CalculadoraDocumento` sobre a base migrada): 47 iguais; 20 POS com bruto 0,01-0,02 abaixo do gravado (ADR-066); 1 FT e 4 OR/NE em USD com IVA +0,01 (ADR-029/030); 2 OR/NE em USD com −31,87 Kz quando se recalcula a partir do preço Kz gravado a 2 casas — o total certo sai de `preço na moeda × câmbio` com 6 casas, como fazem o legado e o novo (não é diferença).

### 2.2 Contabilização (FT/FR/NC/GR/GD + CMV, recibo, POS)

- **FT/NC:** legado D cliente pelo bruto / C proveitos e IVA recalculados por `total/(1+t)` com contas fixas (61/62/72, 34.5.3); novo D cliente / C proveitos (`total_linha`) + C IVA (`total − total_linha`), contas do produto ou da configuração, diário FC. Em 45 documentos com linhas, 44 dão a mesma repartição; 1 (id 157) troca 0,01 entre proveito e IVA — ERRO NO LEGADO corrigido (ADR-029). Sem desequilíbrios.
- **FR:** legado D cliente / C proveitos e depois recibo D caixa / C cliente; novo D disponibilidade directamente — mesmo efeito líquido (ADR-029).
- **CMV:** novo D custo / C inventário ao custo médio no próprio documento; GR/GD só CMV no diário GR. O legado não tinha CMV na factura e valorizava a GR por custo médio → preço de custo → preço de venda — ERRO NO LEGADO corrigido (ADR-043).
- **Recibo:** D disponibilidade / C cliente, mesmos valores.
- **POS (integração da sessão):** sessão 1 — 11 linhas iguais em valor e conta (só a conta do IVA mudou na ficha do produto depois); sessão 3 — proveito/IVA 236 842,09/35 614,05 no novo contra 236 842,10/35 614,04 no legado (o legado repartia o total da sessão em float) — ERRO NO LEGADO corrigido (ADR-047). CMV D71/C26 novo (ADR-043/047). Prestação de contas reproduz as liquidações migradas (ADR-048).
- Testes `Vendas|POS|Hotel|Lavandaria`: 58 passaram (1 264 asserções).

### 2.3 Diferenças e classificação

- **E-VEN-1 — ERRO NO NOVO.** Preço POS com mais de 2 casas: `POSController.php:172` (e `HotelariaController.php:111`) aceita `linhas.*.preco_unitario` sem `decimal:0,2`; `ServicoVendasPOS::vender` (l. 63-68) valida os pagamentos com o preço tal como veio e a emissão arredonda-o a 2 casas (`ServicoDocumentosVenda.php:340`). Ex.: 3 × 10,005 → 30,02 nos pagamentos e 30,03 no documento → pagamento aceite por menos, `PAGAMENTOS_INCONSISTENTES` na integração da sessão, campo `desconto` errado. **Correcção:** acrescentar `'decimal:0,2'` às duas regras de validação; em `ServicoVendasPOS::vender`, normalizar o preço com `number_format((float) $preco, 2, '.', '')` antes de `calcularComIva` e do cálculo de `$bruto`.
- **E-VEN-2 — ERRO NO NOVO.** NC de uma FR/FT emitida pelo POS: na emissão POS o preço guardado é a base com desconto a 2 casas (`ServicoDocumentosVenda.php:90-93`, `139`), e `converter()` (l. 186-215) reconstrói a NC com `calcular()` sobre esse preço. Em 5 000 casos (qtd ≥ 2), 60 % dão total de NC diferente da FR: 1 688 acima (recusados com `NC_EXCEDE_SALDO`, ex.: FR 2 469,84 → NC 2 469,86) e 1 312 abaixo (saldo por creditar). Nas FR POS migradas o preço da linha continua com IVA (ex.: 35 000 com `total_linha` 30 701,75): a NC daria 39 900 e seria sempre recusada. **Correcção:** em `converter()` para NC, passar `total_linha` e `total` da linha de origem; em `emitir()`, quando a NC credita a quantidade toda, usar esses valores em vez de `CalculadoraDocumento::calcular`; em crédito parcial, valor = arred(`total_linha` × q / q_origem) e IVA por excesso.
- **A DECIDIR — campo `desconto` do POS/hotel.** O novo grava `desconto = bruto − total` (`ServicoVendasPOS.php:77`; `ServicoCheckoutHotel.php:152`), pelo que uma venda sem desconto fica com 0,01-0,02 de «desconto» (é o arredondamento AGT). Sugestão: `desconto = arred(bruto × pct / 100)` e o arredondamento à parte.
- **A DECIDIR — documento AGT/SAF-T do POS.** O legado enviava `unitPriceBase` (antes do desconto), `unitPrice` a 6 casas e `settlementAmount`; o novo (`ServicoSelagemAgt.php:86-100`, `ServicoExportacaoSaft.php:295`) envia o preço com desconto a 2 casas nos dois campos e sem `settlementAmount`. Totais e IVA certos, mas qtd × preço pode diferir do valor da linha em cêntimos e o desconto deixa de aparecer.
- **A DECIDIR — FT id 122 sem linhas.** Não contabilizada; o novo recusa (o legado usava contas fixas 72/IVA). Dados (ADR-005/029).
- **A DECIDIR — nota de fluxo de caixa no recibo.** O legado atribuía a primeira nota de fluxo começada por «1»; o novo não atribui, o que afecta a demonstração de fluxos de caixa por notas.

## 3. Custo médio e stock; compras

**Ficheiros comparados.** Legado: `js/ui_warehouse.js` (`calculateAverageCosts` 1-97, recepção 514-520, saídas 1105/1408), `js/ui_inventory.js` (1107, 1134-1170), `js/ui_compras_v2.js` (pontuação 2612-2635, adjudicação 2097, factura 3341-3370), `js/compras_deliberacao.js`. Novo: `Services/Logistica/ServicoStock.php`, `ServicoRecalculoStock.php`, `ServicoInventario.php`, `ServicoMigracaoStock.php`, `Services/Compras/*`.

**Amostra.** População inteira (o backup tem 124 produtos, 33 de stock, 16 saldos por armazém, 7 com quantidade): todos verificados. 81 movimentos reais de 3 produtos repetidos no `ServicoStock` do ambiente E2E (transacção revertida). 23 propostas, 25 facturas de compra, 21 lançamentos FF do legado. Testes `LogisticaStockTest`, `ComprasTest`, `AfinacaoComprasTest`, `AfinacaoArmazemTest` passam.

### 3.1 Custo médio e valorização

| Elemento | Legado | Novo | Resultado / classificação |
|---|---|---|---|
| Custo unitário | Não há custo médio perpétuo: média qtd × preço das linhas de **encomenda** (quantidades encomendadas); na falta, `cost_price` ou o preço de venda | Custo médio ponderado perpétuo `(q⁺ × cm + q × c) / (q⁺ + q)`, global por produto, 6 casas; q⁺ = quantidade anterior se positiva | ERRO NO LEGADO corrigido (ADR-042) |
| Saídas / transferências | Ao custo do legado acima | Ao custo médio; transferência sai e entra ao mesmo custo (cm inalterado) | Verificado (cm 150 mantém-se) |
| Custo inicial migrado | — | Último custo de recepção (`ServicoMigracaoStock.php:28-34`) | DIFERENÇA ACEITE (ADR-042) |
| Saldos | — | 16/16 saldos por armazém iguais ao legado e à soma dos movimentos; 33/33 totais = soma dos armazéns | Igual |
| Movimentos repetidos | — | 81/81 valores iguais ao cêntimo à fórmula de referência; cm difere ≤ 3×10⁻⁶ (o `bcdiv` trunca às 6 casas) | Igual (truncar vs arredondar: irrelevante) |
| Valor do stock (7 linhas com quantidade) | 177 741 157,65 | 102 823 387,40 | Produto 24: legado usava o preço de venda (ADR-042); produtos 102 e 103 (empresa 18): média das encomendas vs último custo de recepção — DIFERENÇA ACEITE (ADR-042/031). Nota: com custo médio sobre todo o histórico, o produto 102 ficaria em ≈ 2,4 M |
| Inventário | Sobras D26/C sobras; quebras D quebras/C26 | Mesmas contas; data da sessão e custo médio | Igual / ADR-042 |

### 3.2 Compras

- **Pontuação das propostas:** preço = mín/total × 70; prazo = 30 − 3 × dias de atraso — 23/23 propostas reais iguais.
- **Escalões de deliberação:** equivalentes; o valor estimado usa o custo médio em vez do preço de venda (ADR-031).
- **Linhas, IVA, câmbio:** cada linha arredondada ao cêntimo (o legado somava floats) — ADR-022. Sem descontos em nenhum dos dois. Linhas = total da factura em 21/25; as 4 restantes (41, 42, 43, 46) têm linhas a zero: o legado creditava o total mesmo assim, o novo recusa (ADR-031).
- **Recepção (diário GL):** D21/C328 e D26/C21, mesmas contas; valor por linha da encomenda/câmbio (ADR-031).
- **Factura (diário FF):** das 21 facturas com lançamento do legado só 1 (51) fica idêntica; as outras explicam-se por E-STK-2 (13 facturas), por o legado debitar 72 ou reescrever prefixos onde o novo usa a conta de custo do produto (ADR-031), por linhas de stock sem ligação à encomenda nas facturas 23-25 e 27 (o novo recusa recontabilizar — ADR-031) e por as contas 7621/6621 passarem a vir da configuração (ADR-031).
- **Fluxo:** proposta → adjudicação (as outras recusadas) → encomenda → recepção em dois passos → validação no armazém (stock e GL) → factura FF (transitória, IVA dedutível, fornecedor pelo total) → pagamento na Tesouraria (ADR-032). Verificado por leitura de código e pelos testes.

### 3.3 Diferenças e classificação

- **E-STK-1 — ERRO NO NOVO.** Uma saída valorizada a custo explícito diferente do custo médio não recalcula o custo médio: `ServicoStock::movimentar`, ramo das saídas (l. 137-145), e `ServicoRecalculoStock::recalcularProduto` (l. 138-146). Acontece ao reverter a validação de uma recepção (`ServicoRececoesCompra.php:221`, sai ao custo da entrada) e nas quebras de inventário com custo personalizado (`ServicoInventario.php:141-143`). Simulação (E2E, revertida): entradas 10 × 100 e 10 × 200 → cm 150; reverter a segunda deixa 10 unidades a cm 150 = 1 500,00, mas os movimentos e a conta 26 dão 1 000,00. **Correcção:** no ramo `else`, quando `$m['custo']` vem indicado e `bcsub($total, $quantidade, 3) > 0`, fazer `$custoMedio = bcdiv(bcsub(bcmul($total, $custoMedio, 8), bcmul($quantidade, $custo, 8), 8), bcsub($total, $quantidade, 3), 6)`; aplicar a mesma regra ao `$cm` de `recalcularProduto` quando a saída não é ao custo médio.
- **E-STK-2 — ERRO NO NOVO.** `ServicoContabilizacaoCompras.php:60`: `$valorTrans = number_format((float) $l->valor_transitoria_kz, 2, '.', '')` transforma NULL em 0,00. Nas linhas migradas (13 facturas da empresa 18, ids 29-39, 22 linhas de stock com `valor_transitoria_kz` NULL, contabilizadas e estornáveis), descontabilizar e voltar a contabilizar põe o líquido inteiro em diferenças de câmbio desfavoráveis (ex.: 25 879 980,00 da factura 29) em vez da 328. O legado debitava a 328 pelo valor da linha quando faltava `valor_328_kz` (`ui_compras_v2.js:3364-3370`). **Correcção:** `$valorTrans = $l->valor_transitoria_kz !== null ? number_format((float) $l->valor_transitoria_kz, 2, '.', '') : $liquido;`
- **A DECIDIR — stock negativo seguido de entrada.** O novo põe a base a 0 e a diferença de custo das unidades vendidas a descoberto fica no stock (ex.: 5 × 100, venda de 8, entrada 10 × 130 → 7 × 130 = 910 contra 1 000 na conta 26). Alternativa: lançar a diferença no CMV.
- **A DECIDIR — câmbio do valor da proposta na adjudicação.** O novo usa `montante_total` ao câmbio da proposta (`ServicoProcessoCompras.php:219`); o legado reavaliava ao câmbio da data da adjudicação. Só afecta propostas em moeda estrangeira.
- **A DECIDIR — facturas com projecto.** O legado debitava a conta da rubrica de orçamento do projecto; o novo usa a conta do produto.
- **Menor:** custo médio e valor estimado da deliberação truncados em vez de arredondados (`ServicoStock.php:135`, `ServicoDeliberacaoCompras.php:91`), efeito de milionésimos.

## 4. Amortizações e abates

**Ficheiros comparados.** Legado: `js/ui_assets.js` (quota 1314-1342 e 2019-2038, diário 1500-1520, abate 2524-2526), `js/fluxo_imobilizado.js`. Novo: `Services/Ativos/CalculadoraAmortizacoes.php` (37-71), `ServicoAmortizacoes.php`, `ServicoAbatesAtivos.php` (140-178).

**Amostra.** Todas as 1 448 quotas migradas, 174 activos, 102 documentos AM (empresas 3, 6 e 18). Testes `AtivosAmortizacoesTest`, `AtivosTest` passam.

| Elemento | Legado | Novo | Resultado |
|---|---|---|---|
| Base | aquisição − residual | Igual | Igual |
| Quota | quota fixa ou base ÷ vida útil (meses); sem pro-rata (o mês de aquisição conta inteiro) | Igual, half-up ao cêntimo; o último mês absorve o resto; sem vida útil, usa a taxa da categoria | 1 435/1 448 iguais (confirma ADR-051) |
| Diferenças | — | 9 × −0,01 (meio cêntimo exacto arredondado para baixo pelo float: activo 177 da empresa 3, activo 281 da empresa 6); 4 no último mês com quota fixa (activos 30, 31, 32, 44 da empresa 6, 12/2025: o legado deixou por amortizar 51,42 / 53,92 / 47,12 / 163,00 Kz) | ERRO NO LEGADO corrigido (ADR-022/051) |
| Acumulado e VLC | — | 174/174 activos iguais; `verificar()` sem diferenças | Igual |
| Diário AM | Agrupado por conta, UN, CC; documento AM-MM-AAAA no último dia do mês | Igual; reabrir = estorno | Totais por documento e D/C: 102/102 iguais. Desdobramento: na empresa 6 o legado debitou 999999 (a conta 73.1 não existe — ADR-051); na empresa 3 o legado não gravou CC. A empresa 22 tem 84 linhas AM sem activos nem quotas (dados) |
| Abate | Só mudava o estado | D18 pela acumulada / C11-12 pela aquisição / D terceiro pelo valor; diferença a C6 (mais-valia) ou D7 (menos-valia) sobre VLC; diário AM, documento ABT-id | ERRO NO LEGADO corrigido (ADR-051); sem abates reais no backup (os 16 registos são dados mestre de exemplo) |

Diferenças:
- Nenhum ERRO NO NOVO.
- **A DECIDIR — IVA na venda de activos.** Nenhum dos sistemas lança IVA liquidado na venda de um activo abatido.
- **Nota de dados:** nenhuma categoria tem conta do activo; a conta é pedida ao utilizador ou vem do lançamento de compra.

## 5. Câmbios, tesouraria, balancete/DR/Balanço, apuramento e encerramento, selo, consolidação

**Ficheiros comparados.** Legado: `js/relatorio_contas.js:88-209`, `js/ui_reports.js:381-560`, `js/ui_closing.js:145-1250`, `js/ui_rotinas.js:711-829`, `js/moedas.js:61-77`, `js/moedas_tesouraria.js:231-316`, `js/ui_tesouraria.js:1391-1480`, `js/ui_folha_caixa.js:1309-1319`, `js/consolidacao.js:463-705`, `js/app_v2.js:9208-9250` (`recoverDataMapping`). Novo: `Services/Contabilidade/ServicoDemonstracoesFinanceiras.php`, `ServicoRelatoriosContabeis.php`, `FiltroMapas.php`, `ServicoEncerramento.php`, `ServicoRotinasContabeisSelo.php`, `Services/Sistema/ServicoCambios.php`, `Services/Tesouraria/ServicoDocumentosTesouraria.php`, `ServicoCaixa.php`, `Services/Consolidacao/ServicoConsolidacao.php`.

**Amostra.** Balancete/DR/Balanço em 13 pares empresa × ano (1/26, 3/26, 6/25, 6/26, 8/25, 8/26, 9/26, 10/26, 18/26, 19/26, 20/25, 22/25, 22/26), por conta, por classe e por nota; apuramento pré-visualizado nos 5 passos em 11 pares e executado (transacção revertida) em 6/2025 e 1/2026 contra as 172 linhas reais de apuramento do backup; selo em 48 meses; consolidação do grupo real 2 (holding 22; empresas 6, 8, 10) em AOA e em USD (câmbio fictício igual nos dois motores); câmbios: o backup só tem 1 documento em moeda (sem diferença), por isso os 3 cenários do `TesourariaMultimoedaTest` e 2 fictícios. A base ficou intacta (44 400 linhas, sem lançamentos nem contas novas). Testes de encerramento, rotinas, multi-moeda e consolidação: 23 passaram (393 asserções).

### 5.1 Resultados

| Domínio | Fórmula (legado = novo) | Resultado | Classificação |
|---|---|---|---|
| Balancete / DR / Balanço | Activo D−C; capital e passivo C−D; anos anteriores na nota 14; RL = (Σ22..26 − Σ27..30) + 31 + 32 + 33 − 35 + 34; notas 8-10 credoras → 21; sem classe 9 nem períodos 13/14 do próprio ano | 6 pares iguais ao cêntimo; os outros só 0,01-0,07 (ex.: empresa 1/2026, RL −0,03); diferenças maiores só da limpeza de caracteres invisíveis no ETL (empresas 8 e 22/2026: 411 640,00 saem da conta fantasma «​4311» para a 4311; empresa 3/2026: 32121 e 3432 fundidas com as gémeas) | DIFERENÇA ACEITE (ADR-022) / ERRO NO LEGADO corrigido (ADR-032, 055, 060) |
| Apuramento (5 passos) | conta → agrupadora .9 («19» no passo 4) → 82x…86x → agregadora → 881…886 → 889, período 13, 31-12; mesma tabela de prefixos e D/C (verificado linha a linha: `ui_closing.js:252-1250` = `ServicoEncerramento.php:66-83, 360-416`) | 6/2025: passos 2 e 4 idênticos às linhas reais, passo 1 com 7 movimentos de 0,01-0,02 (como diz o ADR-056); 8/2025 igual; restantes 0,01-0,02; 1/2026 +404,79 por uma conta 7x com espaço invisível (ver E-CON-3) | DIFERENÇA ACEITE (ADR-022/056) |
| Encerramento / abertura | Nenhum dos dois tem IRC/derrama (87), transporte para a 59 no período 14 nem lançamento de abertura | O Balanço de 2027 leva o resultado de 2026 para a nota 14 (4 904 337,94 + 25 462 484,13 = 30 366 822,07); classes 6/7 a zero | Igual (ADR-056); estorno em vez de apagar, recusa de totalizadoras e inventário só como aviso: decisões registadas |
| Imposto de Selo 1 % | Base = débitos 43/45/48 nos diários VD e CB + débitos 45 no CX; D 75311 / C 3471 | 43/48 meses iguais; 5 com base a diferir 0,01-0,08 e imposto igual; os 6 IS reais do legado são reconhecidos como já lançados | ERRO NO LEGADO corrigido (arredondamento, não duplicar o mês — ADR-056/022) |
| Taxa de câmbio | Última taxa até à data; a da empresa prevalece se igual ou mais recente; Kz por unidade; BAI = (compra + venda)/2 a 6 casas | Igual | Igual |
| Diferenças de câmbio | Banco ao câmbio da data; valor histórico = saldo Kz (liquidação total) ou moeda × saldo Kz ÷ saldo moeda; diferença = Kz ao câmbio do documento − histórico; perda D, ganho C | 3 cenários do teste e 1 parcial fictício dão as mesmas linhas (+995,00; +2 407,90; −5 700,00). Novo: uma linha de diferença por item, com terceiro (legado: uma agregada) — totais iguais | DIFERENÇA ACEITE (ADR-034) |
| Consolidação | Membros a 100 % (INTEGRAL), eliminações por NIF com a chave do legado, excluindo prefixos «4, 34»; diferença na 5.9.8; conversão ao câmbio de cada linha e acerto das classes 1-4 ao câmbio de fecho; resto na 5.9.9 | AOA: 4 eliminações iguais (2 × 4 283 958,00 e 2 × 1 500 000,00), diferença 0,00, reservas 521 899,99 = execução 5 do legado. USD: 162/164 acertos iguais; 4511 0,01 (metades negativas, ADR-022); 4311 da empresa 8 −39,69 nas reservas porque no legado a conta «​4311» não passava o teste `/^[1-4]/` | Igual (ADR-057) / ERRO NO LEGADO corrigido (ADR-032) |

### 5.2 Diferenças e classificação

- **E-CON-1 — ERRO NO NOVO (impacto alto nas demonstrações).** As demonstrações do novo são construídas **pelas notas das linhas** (`ServicoDemonstracoesFinanceiras`, linhas sem nota vão para «Movimentos por mapear»). No legado as integrações punham nota nas linhas, e os dados migrados têm nota em quase tudo (ex.: SAL 1 205/1 209, BD 13 534/13 600, CX 2 670/3 028). No novo só as amortizações (`ServicoAmortizacoes.php:447-449`) e as rotinas põem nota. Ficam sem nota:
  - **Tesouraria** — `ServicoDocumentosTesouraria::integrar` (l. 171-174): a linha da conta financeira não leva `nota_demonstracao_id`. O legado punha a nota 10 (Disponibilidades). Confirmado: a integração simulada do documento pendente 8818 deixou a linha 43 de 1 879 411,33 sem nota.
  - **Caixa** — `ServicoCaixa::contabilizar` (l. 152-155): nenhuma linha leva nota. O legado punha nota 10 na 45 e as notas do movimento na contrapartida. É provável que o mesmo valha para as regularizações (`ServicoCaixa.php:167`, `ServicoConferenciaCaixa.php:85`).
  - **Salários** — `ServicoFolhaSalarial::contabilizar`: o legado punha nota 28 nas contas 72* e nota 19 nas 3* (`app_v2.js:6316-6338`).
  - **Compras** — `ServicoContabilizacaoCompras`: o legado punha nota 11 em 321/322/328, nota 9 em 34* e nota 4 em 11/12/14 (`ui_compras_v2.js:3452-3460`).
  - Para as vendas, POS e guias o legado também não punha nota ao contabilizar, mas tinha a ferramenta `recoverDataMapping` (`app_v2.js:9208-9250`), que atribui a nota pelo prefixo da conta (11/12/14/18→4, 13→5, 15→7, 2→8, 31/33/35/37→9, 43/45→10, 32→19, 34/36/38→21, 51→12, 54-57→13, 59→14, 61/62→22, 63→23, 71→27, 72→28, 75→29, 78→35). Essa ferramenta não foi portada.
  Efeito: cada movimento novo de banco, caixa, salários ou compras sai do Balanço e da DR e o Balanço desequilibra. **Correcção:** (a) nos quatro serviços acima, atribuir as mesmas notas que o legado: nota 10 às linhas 43/45 (sem nota de fluxo), notas do movimento nas contrapartidas da caixa, 28/19 nos salários, 11/9/4 nas compras, procurando o id pela coluna `codigo` de `notas_demonstracao_resultados` da empresa; (b) portar `recoverDataMapping` como rotina (ou como nota por omissão em `ServicoLancamentos::criar` quando `nota_demonstracao_id` vem vazio), com a tabela de prefixos acima, para cobrir vendas, POS, guias e stock.
- **E-CON-2 — ERRO NO NOVO (ETL).** `Migracao/ConversorTipos.php:218` só remove U+200B-200D, U+2060 e U+FEFF. A empresa 1 tem 3 linhas (404,76) na conta «75216» seguida de espaço não separável (U+00A0), que não existe no plano: o passo 1 do apuramento com `criarContas` criaria uma conta «75216 ». **Correcção:** nas colunas de código, remover também espaços (incluindo U+00A0) nas pontas, ex.: `preg_replace('/^[\s\x{00A0}]+|[\s\x{00A0}]+$/u', '', $s)`, e acrescentar uma validação de dados que detecte códigos com espaços.
- **A DECIDIR — contas de diferenças de câmbio.** O ADR-033/034 justifica a troca dizendo que «6621/7621 estavam invertidas face ao PGC angolano». No PGC angolano a classe 6 é de proveitos e a 7 de custos, e os dados confirmam-no (classe 6 com saldo credor; salários em 72). Logo, 6621 = favorável e 7621 = desfavorável estavam correctas no legado. Além disso, `configuracoes_contabeis_tesouraria` está vazia em todas as empresas: a primeira liquidação com diferença de câmbio será recusada até se configurar (sugere-se 6621 / 7621). A linha de diferença de câmbio também não leva nota (nem no legado), pelo que não aparece na DR — ver E-CON-1.
- **A DECIDIR (menor) — arredondamento do banco em documentos em moeda com várias linhas sem documento ligado.** O novo lança no banco a soma dos arredondamentos das linhas; o legado arredondava o total e acertava a maior linha (ex.: 3 × USD 10,01 a 900,555 → 27 043,68 no novo, 27 043,67 no legado).
- **A DECIDIR (menor) — totais do balancete com «sem saldo zero».** O novo soma só as linhas visíveis (`ServicoRelatoriosContabeis.php:75-77`); o legado somava todos os movimentos.
- **Bloqueios práticos ao encerramento (dados, não cálculo):** a conta 769 é totalizadora em 8 dos 11 pares (bloqueia o passo 2); há movimentos em contas totalizadoras de origem (6211 na empresa 6; 617, 62, 712, 72 e 75234 na empresa 18; 75219 e 75220 na 22), que bloqueiam o passo 1; faltam contas 8xx em várias empresas (criam-se a pedido). Corrigir o plano antes do primeiro encerramento.
- O fluxo de caixa não foi verificado numericamente.

## 6. Orçamento, acréscimos e diferimentos (consolidação: ver secção 5)

### 6.1 Orçamento

**Ficheiros comparados.** Legado: `js/modules/orcamento/orcamento_dados.js:315-378`, `orcamento_planeamento.js:192-310, 437-598`, `orcamento_ui.js`. Novo: `Services/Orcamento/ServicoExecucaoOrcamental.php`, `ServicoControloOrcamental.php`, `ServicoPlaneamentoOrcamental.php`.

| Elemento | Fórmula (legado = novo) | Amostra / resultado | Classificação |
|---|---|---|---|
| Realizado | Exploração: classes 6/7 e contas da rubrica, PROVEITO = C−D, CUSTO = D−C; tesouraria: movimento líquido de 43/45 repartido pelas contrapartidas; saldos inicial e de fim de mês | 8 orçamentos migrados × 2024-2026 = 24 casos: 20 iguais; 4 com 0,01-0,02 (linhas arredondadas ao cêntimo na migração) | DIFERENÇA ACEITE (ADR-022) |
| Exclusão do apuramento | Legado por período 13/14; novo pelos diários AP-* | 133 linhas do apuramento de 2025 da empresa 6 excluídas nos dois | ERRO NO LEGADO corrigido (ADR-044) |
| Controlo e compromissos | Consumido = realizado + compromissos (+ documento); estados por % de aviso/limite e modo | Sem dados reais (nenhum orçamento aprovado com controlo): simulado no ambiente E2E (transacção revertida) | Ver E-ORC-1 e E-ORC-2 |
| Sem dotação, encomendas anuladas, falha fechada, pedido UTILIZADO só se o documento gravar, modo AVISAR sem «EXCEDIDO» | — | — | ERRO NO LEGADO corrigido (ADR-045) |
| Planeamento (semeadura ORCAMENTO/TENDÊNCIA/ANO_ANTERIOR/BRANCO; estimativa de fecho; limiares 0,35/0,7) | Idênticas por leitura | Sem previsões no backup; testes de planeamento passam | Igual |

Por leitura: o controlo corre na adjudicação da encomenda, na factura directa, no pagamento da tesouraria e no lançamento manual, dentro da transacção do documento; o realizado nasce quando a factura é contabilizada.

- **E-ORC-1 — ERRO NO NOVO.** Compromissos não filtrados pelo mês: o legado (`orcamento_planeamento.js:475`, `consumo`) só soma compromissos com data ≤ mês do documento; o novo soma os do ano inteiro em `ServicoControloOrcamental::verificar` (l. 180-181) e `monitor` (l. 333). Caso testado: orçamento 100/mês, base ACUMULADO, encomenda de 1 000 datada de Dezembro e documento de 50 em Março → novo 350 % e BLOQUEIO (monitor «EXCEDIDO»); legado 16,7 % e OK. **Correcção:** acrescentar ao `array_filter` dos compromissos, nas linhas 180 e 333, a condição `&& (int) substr($x['data'], 5, 2) <= $meses` (com `$meses = 12` na base ANO, o filtro deixa entrar o ano todo, como no legado).
- **E-ORC-2 — ERRO NO NOVO.** O compromisso desaparece entre o registo e a contabilização de uma factura feita a partir de encomenda: `ServicoFaturasCompra::registarDaEncomenda` (l. 81) aumenta logo `quantidade_faturada` (a parte facturada sai da encomenda), mas `ServicoControloOrcamental::compromissos` exclui as linhas de factura com encomenda (l. 86, `->whereNull('i.item_encomenda_id')`). Até à contabilização, o valor não está nem em compromissos nem no realizado. **Correcção:** retirar `->whereNull('i.item_encomenda_id')` da linha 86 (não há dupla contagem: a encomenda já só conta a parte por facturar).
- **A DECIDIR — modo NENHUM no monitor.** O legado mostrava «EXCEDIDO» acima de 100 % e «OK» abaixo; o novo mostra «AVISO» a partir de 90 % e nunca «EXCEDIDO» (l. 337-342). O ADR-045 só trata o modo AVISAR.
- **A DECIDIR (menor) — gasto sem orçamento.** No mapa, uma rubrica de custo sem orçado mas com realizado não fica com `desvio_significativo` (`ServicoExecucaoOrcamental.php:159` exige `pct !== null`); o legado marcava-a «Desfavorável». Sugestão: `! $favoravel && ($pct === null || abs($pct) > 10)`.

### 6.2 Acréscimos e diferimentos

**Ficheiros comparados.** Legado: `js/modules/acrescimos/ad_dados.js:62-244`. Novo: `Services/Acrescimos/CalculadoraAcrescimos.php`, `ServicoPropostaAcrescimos.php`, `ServicoItensAcrescimos.php`.

- **Fórmula (igual):** plano por MESES (peso 1 por mês civil tocado) ou por DIAS (dias do período em cada mês); quota = arred(total × pesos acumulados ÷ soma) − quotas anteriores; a última absorve a diferença. Contas: reconhecimento/término CUSTO D resultado (7) / C balanço (37); PROVEITO o inverso (6); inicial, regularização e anulação o inverso do reconhecimento. Validação da conta de resultado: 7 para gasto, 6 para rendimento (igual).
- **Verificação independente (feita por mim):** 2 002 casos (os 2 registos reais + 2 000 sintéticos: 2023-2026, inícios a meio do mês, 1 a 800 dias, por DIAS e por MESES), função do legado copiada para Node × `CalculadoraAcrescimos::quotas` em PHP: 1 837 iguais em todas as quotas; 165 com ±0,01 em algumas quotas (504 quotas no total, máximo 0,01) — sempre meios cêntimos que o float do legado arredonda para baixo; **em todos os casos os mesmos meses e a soma das quotas = total**.
- **Verificação do agente de domínio:** 2 registos reais com quotas e pendentes iguais (proposta de 2026-12: 3 linhas, 779 769,23, D 752331 / C 3743; reconciliação da 3743 da empresa 10: módulo = diário = 2 859 153,85); 3 208 casos fictícios (bissextos, meses de 28/31 dias) com 254 diferenças de ±0,01 do mesmo tipo; 144 ciclos completos (inicial, reconhecimento, regularização com diferença, anulação, término, `documento_em_balanco`) sem diferenças.
- **Fluxo:** proposta → contabilização (um lançamento por linha, documento `AD<id>-AAAAMM-TIP`, lock e controlo de duplicados) → regularização (a 37 salda pelo valor reconhecido, a diferença fica registada); descontabilizar = estorno.
- **Classificação:** ±0,01 — ERRO NO LEGADO corrigido (ADR-022); estorno — ADR-053/016. Nenhum ERRO NO NOVO.

## 7. Férias, assiduidade, produtividade, avaliação e bónus

**Ficheiros comparados.** Legado: `js/modules/rh/ferias.js:40-130`, `assiduidade.js:454-548`, `produtividade.js:90-96`, `avaliacao.js:52-111`, `aval360_dados.js:241-282, 438-470`. Novo: `Services/RH/ServicoFerias.php`, `ServicoCalendarioRH.php`, `ServicoAssiduidade.php`, `ServicoProdutividade.php`, `ServicoAvaliacao.php`, `ServicoAvaliacao360.php`. Testes `FeriasProdutividadeTest`, `AssiduidadeTest`, `AvaliacaoTest` passam.

| Domínio | Fórmula | Amostra / resultado | Classificação |
|---|---|---|---|
| Férias | Direito 22 dias úteis por omissão; saldo = direito − dias marcados não cancelados | 4 períodos reais e 506 intervalos fictícios: mesmos dias úteis (a configuração não tem feriados). Com um feriado configurado, o novo desconta-o (ex.: 11-26/11/2026 → 11 dias em vez de 12) | Igual / ERRO NO LEGADO corrigido (ADR-038/039) |
| Assiduidade (resumo do mês) | Horas extra, horas e dias de falta, dias com registo, férias/ausências, horas trabalhadas | Empresa 18, 01/2026, 02/2026 e 09/2026: os 6 colaboradores iguais; coincidem com os fechos gravados no legado (01/2026: 180,25 h extra e 532 h de falta; 09/2026: 85 dias de falta) | Igual; só ACTIVOS, sem faltas antes da admissão ou fora do contrato, recálculo ao lançar — ERRO NO LEGADO corrigido (ADR-038) |
| Produtividade | Valor = quantidade considerada × preço; mínimo/máximo sobre o total do colaborador no item | Período 09/2026: 6 registos, total 489 250,00 igual | Igual (mín./máx. no total: ADR-039); ver E-RH-1 |
| Avaliação | Média ponderada dos critérios; objectivos quantitativos com limite de 200 % e qualitativos (nota − 1) × 25; pontuação = critérios × (1 − P) + objectivos × P; escalas | 4 000 casos fictícios: classificação e médias 360 sem diferenças; pontuação final com 0,01 em 17 casos (meio cêntimo do float), sem efeito na classificação | Igual / ERRO NO LEGADO corrigido (ADR-022) |
| 360º e bonificação | Junção PARES_SUB; bónus PERCENTAGEM / FIXO / BOLSA com o arredondamento no maior valor | Iguais por leitura e nos casos fictícios; sem avaliações reais no backup | Igual (correcções do ADR-041: peso vazio = 30, só participantes do ciclo, sem duplicados) |

- **E-RH-1 — ERRO NO NOVO.** `ServicoProdutividade::recalcular` (l. 219-221) arredonda a quantidade considerada de cada registo a 3 casas antes de multiplicar pelo preço, e nenhum registo absorve a diferença. Caso: máximo 10, três registos de 5 a 50 000 → 3 × 3,333 × 50 000 = 499 950 em vez de 500 000 (50 Kz a menos). **Correcção:** `valor_i = q_i × considerada ÷ total × preço_i` sem arredondamento intermédio (bcmath), arredondado ao cêntimo, e a diferença até `arred(Σ q_i × considerada ÷ total × preço_i)` posta no último registo; `quantidade_considerada` pode continuar a 3 casas só para apresentação.
- **A DECIDIR — feriados.** A lista de feriados da empresa está vazia, por isso, por exemplo, 11/11 conta como dia útil nas férias e na assiduidade. É configuração.
- **A DECIDIR — proporcionais do 1.º ano e transporte de saldo de férias.** Não existem em nenhum dos dois (ficaram para depois no ADR-039).
- **Nota menor (assiduidade):** o legado escolhia o contrato recorrendo também a contratos não ACTIVO válidos no mês; o novo só usa ACTIVO. Só afecta as horas por dia quando são diferentes de 8.
