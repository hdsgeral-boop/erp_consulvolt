# Análise — regras de negócio no backend e Redis a 100 % (2026-10-02)

**Agente:** REG (regras de negócio e Redis) · **Tipo:** análise, sem alterações de código
**Pedido do utilizador:** «Que todas as regras de negócio que estavam no JavaScript passem para o backend, em controllers e noutros tipos, e que o Redis continue a funcionar 100 %.»
**Âmbito:**
- `erp_laravel/frontend/src` (333 ficheiros, excluindo testes);
- `erp_laravel/backend/app` (Services, Controllers/Api, Requests, Models, Jobs, Providers);
- legado `payroll_system_web/js/**`;
- ambiente Docker de desenvolvimento a correr.

**Método:**
- Três pesquisas de leitura em paralelo: frontend, confiança do backend em valores do cliente e inventário do legado.
- Cada achado ALTA e MÉDIA foi confirmado por leitura directa do código (marcado ✔ nas tabelas).
- Verificações vivas no Redis e no worker com scripts temporários, já apagados.
- Não houve nenhuma escrita na base `erp_consulvolt`. Os pedidos autenticados correram dentro de uma transacção desfeita.

---

## Sumário executivo

1. **A arquitectura já cumpre o essencial do pedido.** O frontend não envia para a API, em nenhum ecrã:
   - totais nem IVA de documentos;
   - números de documento nem séries;
   - troco, desvio de fecho Z nem rateios;
   - débito/crédito de lançamentos automáticos;
   - IRT, INSS nem salário líquido;
   - quotas de amortização, imposto de selo nem diferenças de câmbio.

   O backend recalcula tudo isto em `CalculadoraDocumento`, `CalculadoraCompra`, `MotorSalarial`, `CalculadoraAmortizacoes`, `CalculadoraAcrescimos`, `ServicoVendasPOS`, `ServicoSessoesPOS`, `ServicoSeries` e `ServicoNumeracao`. No frontend, as cópias destas regras servem só para pré-visualização, botões e impressão.
2. **Ficam lacunas reais.** Há **4 achados ALTA** e **16 MÉDIA** na Parte 1. São campos que o servidor aceita sem confrontar com a fonte da verdade, ou regras do legado que não passaram para o backend. O caso mais grave é a Tesouraria, onde há forma de contornar o limite do saldo em aberto.
3. **O Redis está operacional a 100 % no ambiente de desenvolvimento.** Funcionam:
   - cache, locks, rate limiting e filas;
   - o worker (processou um job de teste em 0,5 s);
   - o scheduler (ciclo AGT de 2 em 2 min, `withoutOverlapping`).

   Há, no entanto, **1 risco ALTA**: a cache sobrevive à migração `--substituir`, que faz `TRUNCATE … RESTART IDENTITY` e por isso reutiliza ids, o que pode expor empresas de outro utilizador. Há ainda **4 riscos MÉDIA** de invalidação e de comportamento em falha. Este ponto é relevante para a **migração definitiva**, o próximo passo.

---

# PARTE 1 — Regras de negócio

## 1.1 Legenda

- **OK** — o backend é a fonte da verdade; o frontend só antecipa ou mostra.
- **RISCO** — o frontend calcula ou pré-preenche, e o backend aceita sem recalcular nem validar contra a fonte.
- **EM FALTA NO BACKEND** — a regra existia no legado ou no frontend e não está no servidor.
- ✔ — confirmado por leitura directa nesta análise.

Caminhos abreviados:
- **F** = `frontend/src/`;
- **B** = `backend/app/`;
- **L** = `payroll_system_web/js/`.

## 1.2 Achados ALTA

| # | Regra | Frontend | Backend | Estado | Correcção proposta |
|---|---|---|---|---|---|
| A1 ✔ | **Não liquidar mais do que o saldo em aberto** (pagamentos/recebimentos e folha de caixa) | F `modulos/teso/pagamentos/FormularioDocumento.tsx` (`acrescentarPendentes` pré-preenche `linhas[].valor` com o saldo) → `POST/PUT /tesouraria/documentos` | B `Services/Tesouraria/ServicoDocumentosTesouraria.php:271-301, 339-342`; `ServicoLiquidacoes.php:22-69`; `ServicoCaixa.php:85-93` | **RISCO** | Ver «Detalhe A1» abaixo da tabela. |
| A2 ✔ | **NC proibida sobre factura PAGA ou com pagamentos** | — | B `Services/Vendas/ServicoDocumentosVenda.php::validarNotaCredito` (≈ l.346-365): só limita ao saldo creditável | **EM FALTA NO BACKEND** (legado L `ui_sales.js:1725-1728` e `2750-2753`) | Em `validarNotaCredito`, se `bccomp($origem->valor_pago, '0.01', 2) >= 0` ou o estado for `PAGO`, lançar `ErroNegocio('Não é possível emitir nota de crédito para uma factura liquidada…', 'NC_FATURA_PAGA', 422)`. Aplicar também em `converter()` quando o destino é NC. Se o utilizador quiser manter o comportamento novo, registar a decisão num ADR. |
| A3 ✔ | **As linhas da NC têm de corresponder às da factura de origem** (produto, preço, taxa, quantidade) | F `modulos/vendas/EmitirDocumento.tsx:118-125` (envia `preco_unitario` livre também na NC) | B `ServicoDocumentosVenda.php:332` (preço do cliente) e `:336` (taxa **actual** do produto, não a da factura); `validarNotaCredito` só compara o **total** | **RISCO** | Ver «Detalhe A3» abaixo da tabela. |
| A4 ✔ | **Pré-validação AGT bloqueia a emissão** («Nada foi gravado») | F `modulos/vendas/agt/accoesDocumento.ts` (só acções posteriores) | B `Services/Vendas/ServicoSelagemAgt.php:32-34`: grava `fe_estado = COM_ERROS` com número de série já consumido | **EM FALTA NO BACKEND** (legado L `ui_sales.js:1798-1803`, `2943-2948`). Sem ADR. | Ver «Detalhe A4» abaixo da tabela. |

**Detalhe A1.** Há duas formas de contornar o limite, e mais duas falhas na liquidação:
- **(a) Número de documento inventado.** Em `validarLigacao`, `$l['numero_documento'] ??= $numero` deixa prevalecer o número enviado pelo cliente. Com `venda_id` e um `numero_documento` inventado, `pendentes->saldo()` não encontra pendente, o limite é saltado («adiantamento ou movimento avulso», l.294), e `ServicoLiquidacoes::aplicar` actualiza na mesma o `valor_pago` da venda.
- **(b) Conta diferente da do terceiro.** O mesmo acontece se `codigo_conta` ou `conta_contrapartida` não for a conta do terceiro.
- **(c) Venda anulada aceite.** `validarLigacao` não rejeita uma venda `ANULADO`; nas facturas de compra, rejeita `ANULADA`.
- **(d) Sem tecto no valor pago.** `aplicar` não limita `valor_pago` a `total_bruto`.

Correcção:
1. Em `ServicoDocumentosTesouraria::validarLigacao`, quando há `venda_id` ou `fatura_compra_id`, **impor** `numero_documento = número do documento ligado`. Rejeitar se o cliente enviou um diferente (`LIGACAO_INVALIDA`).
2. Impor que `codigo_conta` é a conta do terceiro (`Terceiro::codigo_conta`).
3. Se a linha está ligada a um documento e não há pendente, rejeitar (`SEM_PENDENTE`) em vez de tratar como adiantamento.
4. Em `ServicoLiquidacoes::validarLigacao`, rejeitar `estado = 'ANULADO'`.
5. Em `aplicar`, impor `valor_pago ≤ total_bruto`; o excesso gera erro.
6. Aplicar o mesmo em `ServicoCaixa` (movimentos).

**Detalhe A3.** Uma NC pode creditar com preços ou IVA diferentes dos da FT, desde que o total caiba no saldo creditável.

Correcção:
1. Em `ServicoDocumentosVenda::emitir`, para tipo `NC`, obter as linhas de `venda_origem_id`. Exigir que cada `produto_id` exista na origem.
2. Usar o `preco_unitario` e a `taxa_imposto` **da linha de origem**, ignorando os do pedido.
3. Limitar a quantidade a `quantidade_origem − quantidade_já_creditada` por linha, com `lockForUpdate` já existente.
4. No frontend, não enviar preço na NC.

**Detalhe A4.** Hoje um documento fiscal com erros AGT fica numerado e, sendo fiscal, não pode ser anulado: só se corrige com uma NC.

Correcção:
1. Em `ServicoDocumentosVenda::emitir`, chamar a validação de `ServicoSelagemAgt` **antes** de `ServicoSeries::reservar`.
2. Se houver erros e o documento estiver dentro do regime, lançar `ErroNegocio('O documento não cumpre as regras da AGT…', 'AGT_PRE_VALIDACAO', 422, ['erros' => …])`. A transacção é desfeita e o número não é consumido.
3. Acrescentar as regras em falta: E29 (data anterior à adesão), país ISO-2 do cliente, unidade e código do produto, documento de valor zero e NIF com mais de 50 caracteres (L `facturacao_agt.js:336-391`).
4. Se a gravação com `COM_ERROS` foi intencional, registar ADR.

## 1.3 Achados MÉDIA

| # | Regra | Frontend | Backend | Estado | Correcção proposta |
|---|---|---|---|---|---|
| M1 ✔ | Preço de venda = ficha do produto, salvo autorização | F `vendas/EmitirDocumento.tsx:134-139` pré-preenche com o catálogo e **envia sempre** `preco_unitario`; o POS só envia quando altera (`pos/comum/calculos.ts:124-130`) | B `ServicoDocumentosVenda::prepararLinhas:332` aceita o preço do cliente; `DocumentoVendaController` só exige `vendas_fat_emitir` (o POS exige `pos_desconto`) | **RISCO** | Em `prepararLinhas`, comparar com o preço da ficha convertido ao câmbio. Se for diferente, exigir uma nova tarefa `vendas_alterar_preco` (em `CatalogoPermissoes`) e registar o preço original na auditoria. No frontend, enviar `preco_unitario` só quando é alterado. |
| M2 ✔ | Câmbio = tabela de câmbios, salvo câmbio manual autorizado | F câmbio introduzido em Vendas, Compras e Tesouraria | B `ServicoDocumentosVenda.php:302-303` (`manual => true`); `Compras/CalculadoraCompra.php:261-266`; `ServicoDocumentosTesouraria.php:≈364` | **RISCO** | Criar `ServicoCambios::validarManual($moeda, $data, $taxa)`. Exigir a permissão `cambio_manual` e uma tolerância configurável (ex.: ±5 % face a `obter()`). Acima dela, exigir justificação na auditoria. |
| M3 | Taxa de IVA da factura de compra = taxa da linha da encomenda; taxas só de uma tabela de códigos de imposto | F `compras/comum/ModaisEncomenda.tsx:129-133`, `LinhasProdutos.tsx:47-48` (taxa do catálogo enviada) | B `Compras/ServicoFaturasCompra.php:62` (`$l['taxa_imposto'] ?? $item->taxa_imposto`); `GuardarProdutoRequest.php:27-28` (0-100 livre) | **RISCO** | Em `registarDaEncomenda`, usar sempre `$item->taxa_imposto` e rejeitar uma divergência sem `compras_alterar_iva`. Em produtos e facturas directas, validar a taxa contra uma lista de taxas legais em `config/erp.php` (`erp.fiscal.taxas_iva` = 0, 2, 5, 7, 14) ou contra uma tabela de códigos de imposto. |
| M4 ✔ | Ajuste de stock: custo, exercício, contabilização e segregação | F `stock/NiveisStock.tsx:133` (envia `custo_unitario` nas entradas) | B `Logistica/ServicoStock::ajustar` (l.55-59) + `StockController.php:107-114`: custo do cliente altera o custo médio; **0** chamadas a `exigirAberto` em `ServicoStock`; sem lançamento; só `armazem_ajuste` | **RISCO** | Ver «Detalhe M4» abaixo da tabela. |
| M5 ✔ | Exercício encerrado bloqueia qualquer escrita datada | — | B `ServicoLancamentos::criar:46` chama `exigirAberto` **fora** da transacção e sem bloquear a empresa (o encerramento faz `lockForUpdate` à empresa, `ServicoEncerramento.php:512`). Só 16 serviços verificam: stock, POS e activos não verificam. | **RISCO** e **EM FALTA** (legado tinha um hook global, L `db_v2.js:463-518`) | Ver «Detalhe M5» abaixo da tabela. |
| M6 | Lançamentos CX/BD: contrapartida com nota de fluxo de caixa; conta 4 sem nota | — | B `ServicoLancamentos::criar` e `CriarLancamentoRequest`: nota opcional, sem regra | **EM FALTA NO BACKEND** (L `ui_lancamentos.js:1707-1731`) ✔ | Em `ServicoLancamentos::criar`, se o código do diário for `CX` ou `BD` e houver linhas da classe 4: exigir `nota_fluxo_caixa_id` nas linhas que não são da classe 4 (`NOTA_FLUXO_OBRIGATORIA`) e proibi-la nas da classe 4 (`NOTA_FLUXO_NA_DISPONIBILIDADE`). |
| M7 | Lançamento manual em moeda estrangeira | — | `CriarLancamentoRequest` não aceita moeda nem câmbio | **EM FALTA NO BACKEND** (L `moedas_lancamentos.js:157-342`) | Acrescentar `linhas.*.codigo_moeda`, `valor_moeda` e `taxa_cambio` (câmbio de `ServicoCambios`). Validar o equilíbrio na moeda e em Kz, com acerto de arredondamento numa conta configurada, e a moeda da conta (só classe 4, regra já em `ServicoPlanoContas`). |
| M8 ✔ | Salários: horas extra só por assiduidade aprovada; valor de rubrica não substitui o contrato sem controlo; segregação encerrar ≠ validar ≠ contabilizar | F `rh/.../Calcular.tsx:89-104` (um POST por colaborador) | B `RH/MotorSalarial.php:64-66, 91-93` (dias trabalhados acima dos dias do contrato geram «H. Extras»); `ServicoFolhaSalarial::gravarLancamento` (`updateOrCreate`, l.≈125); `validar` não compara utilizadores | **RISCO**. É paridade com `engine_v2.js` (ADR-013): mudar exige decisão do utilizador. | (1) Limitar `dias_trabalhados` a `diasContrato` excepto com a permissão `rh_horas_extra_dias`, ou exigir que o excesso venha da assiduidade (`ServicoAssiduidade`). (2) Registar na auditoria a substituição de um valor importado do contrato. (3) Em `ServicoFolhaSalarial::validar`, impor `validado_por ≠ fechado_por` (como já é feito em orçamento e inventário). |
| M9 | Valor a facturar num auto de projecto ≤ valor por facturar | F `projectos/separadores/Revisoes.tsx:247-266` (pré-preenche com `sugerido`) | B `Projetos/ServicoRevisoesProjetos::faturar` (l.267-270): só exige > 0 | **RISCO** | Impor `valor ≤ sugerido` (ou ≤ valor por facturar da encomenda). Acima disso, exigir a permissão `projetos_faturar_excesso` e justificação. |
| M10 ✔ | Aprovação de aditamentos de projecto é um acto próprio, com segregação | F `projectos/separadores/Orcamento.tsx:156-162` (estado escolhido num campo do formulário) | B `Projetos/ServicoOrcamentoProjetos::guardarAditamento` (l.108-134): aceita `estado` APROVADO na criação, altera `montante` depois de aprovado e elimina aprovados | **RISCO** | Retirar `estado` do CRUD: criar sempre `PENDENTE`. Criar os endpoints `aprovar` e `rejeitar` com tarefa própria, `aprovado_por ≠ criado_por` e transições PENDENTE → APROVADO/REJEITADO. Bloquear edição e eliminação de aprovados. |
| M11 | Saldo de abertura da caixa = saldo do último fecho | F `teso/FolhaCaixa.tsx` | B `Tesouraria/ServicoCaixa.php:52-56`: diferença só gera aviso | **RISCO** | Impor `saldo_abertura = último saldo_fecho`. Uma diferença exige a permissão `caixa_abertura_divergente`, justificação e lançamento de sobra/quebra. |
| M12 | Venda POS só na sessão do próprio operador | F `pos/frente/PainelVenda.tsx:105-116` | B `POS/ServicoVendasPOS.php:45-48`: verifica só que a sessão está ABERTA | **RISCO** | Em `vender`, exigir `sessao.operador_id === Auth::id()`, excepto com a permissão `pos_vender_sessao_alheia`. Hoje a venda conta para o fecho Z de outra pessoa. |
| M13 ✔ | Mapa de IRT oficial: parcela fixa, taxa, excesso e imposto devido calculados no servidor | F `rh/relatorios/Mapas.tsx:112-151` + `rh/comum/regras.ts:134-162` (cópia de `TABELA_IRT`), **exportado em CSV oficial** | B `MotorSalarial::irt()` não devolve a decomposição | **RISCO** (tabela fiscal duplicada) | Fazer o endpoint do detalhe do período devolver `irt_escalao: {fixo, taxa, excesso, devido}` calculado por `MotorSalarial`. Retirar `TABELA_IRT` e `escalaoIrt` do frontend. |
| M14 | Notas DEMO e de fluxo automáticas nos lançamentos gerados (salários 72→28 e 3→19, vendas 27/8, tesouraria 10, entradas de armazém 11) | — | Só `ServicoAmortizacoes` (l.447-449) aplica | **EM FALTA NO BACKEND** (L `app_v2.js:6073-6074`, `ui_sales.js:3143-3146`, `ui_tesouraria.js:1412-1440`, `ui_warehouse.js:516`) | Centralizar num `ServicoNotasAutomaticas::aplicar(array $linhas, string $origem)`, chamado por `ServicoFolhaSalarial::contabilizar`, `ServicoContabilizacaoVendas`, `ServicoDocumentosTesouraria::integrar` e pela contabilização de entradas de stock. Sem isto, as demonstrações de resultados e de fluxos de caixa ficam incompletas. |
| M15 | Unicidade do número de documento na base de dados (defesa em profundidade) | — | Índice único só para FT/FR/NC/ND e guias de saída. OR/PF/NE/GR/RE/PAG/REC/PC/PP/EC dependem só de locks. | **RISCO** (baixo enquanto os locks funcionarem) | Migração com `unique(empresa_id, numero_documento)` (ou com tipo e série) nas restantes tabelas numeradas. |
| M16 | Mesas de restaurante no POS (nome único, consulta de mesa) | — | Inexistente | **EM FALTA NO BACKEND** (funcionalidade; L `ui_sales.js:8063-8477`) | Decidir com o utilizador se entra no âmbito: tabela `mesas_pos`, nome único por terminal e estado da conta por mesa. |

**Detalhe M4.** Correcção:
1. Em `StockController::ajustar`, validar `produto_id` e `armazem_id` com `exists` por empresa.
2. Em `ServicoStock::ajustar`, chamar `ServicoExercicios::exigirAberto`.
3. Numa entrada, aceitar `custo` só com a permissão `armazem_ajuste_custo`; por omissão usar o custo médio.
4. Gerar lançamento de regularização: conta de quebra ou sobra do produto (`conta_quebra` / `conta_sobra`) contra a conta de inventário.
5. Opcionalmente, exigir aprovação por outra pessoa acima de um valor (como já é feito em `ServicoInventario:126`).

**Detalhe M5.** Correcção:
1. Mover `exigirAberto` para **dentro** de `DB::transaction` e fazer antes `DB::table('empresas')->where('id', $empresa)->sharedLock()->first()`. Assim o encerramento, que usa `lockForUpdate`, serializa com as escritas.
2. Chamar `exigirAberto` em `ServicoStock::movimentar`, `ServicoInventario`, `ServicoGuiasSaida`, nas vendas, estadias e ordens do POS e nas alterações de activos.
3. Alternativa: criar um *trait* `ProtegidoPorExercicio` com `saving`/`deleting` nos models datados, à semelhança do hook global do legado.

## 1.4 Achados BAIXA

| # | Regra | Onde | Estado | Correcção proposta |
|---|---|---|---|---|
| B1 | Custo/hora do equipamento derivado do activo ou da tabela | B `ServicoExecucaoProjetos.php:129-134` (aceita ≥ 0) | RISCO | Por omissão, usar o custo do activo; um valor manual só com permissão. |
| B2 | Comissão TPA na prestação de contas | F `pos/Prestacao.tsx:222-245`; B `ServicoPrestacaoContasPOS.php:283-285` (0 ≤ comissão < bruto) | RISCO | Tolerância face a `comissao_sugerida`; acima dela, justificação. |
| B3 | Taxas de recolha e entrega da lavandaria | B `POS/Lavandaria/ServicoOrdensLavandaria.php:66` ✔ (`$x['taxa'] ?? $cfg[...]`) | RISCO | Usar a taxa da configuração; uma diferente exige `pos_desconto`. |
| B4 | Valor do «documento real» na regularização de acréscimos | B `ServicoItensAcrescimos.php:140-148` | RISCO | Quando vêm `fonte` e `id`, ler o valor do documento e ignorar o do cliente. |
| B5 | Desconto POS até 100 % sem limite por perfil | B `POSController.php:177-182` | RISCO | `desconto_maximo` por perfil ou terminal. |
| B6 | Artigo-quarto só vendável em POS de hotelaria | B `ServicoVendasPOS::vender` não verifica `e_quarto` | EM FALTA (L `ui_sales.js:7056-7059`) | Rejeitar produtos com `e_quarto = true` em `vender`. |
| B7 | Transferir lançamento para outra empresa (exercício aberto no destino) | — | EM FALTA (L `ui_lancamentos.js:1023-1122`) | Decidir com o utilizador. Se entrar, fazer estorno na origem e criação no destino numa transacção, com `exigirAberto` em ambas. |
| B8 | Recibo de cliente sem facturas (adiantamento com distribuição automática) | B `ServicoRecibosVenda::criar` exige alocações | EM FALTA, eventualmente coberto por documento de tesouraria avulso | Confirmar com o utilizador; se for necessário, criar `ServicoRecibosVenda::adiantamento`. |
| B9 | Lançamentos manuais em contas de controlo (31, 32, 34 IVA, 43) | B `ServicoLancamentos::criar` | RISCO (provavelmente igual ao legado) | Aviso ou permissão `contab_lancar_contas_controlo` para contas marcadas como de subdiário. |
| B10 | Segregação «registar ≠ validar» na recepção de compras e «gravar ≠ integrar» na tesouraria | B `ServicoRececoesCompra::validar` (l.115-130), `ServicoDocumentosTesouraria::integrar` | RISCO | Comparar `criado_por` com o utilizador, como já é feito nos outros pares de `ServicoPerfis`. |
| B11 | Arredondamento: seis implementações de cêntimos no frontend (`rh/comum/regras.ts:80-88` em vírgula flutuante, `crm/comum/tipos.ts`, `orcamento/comum/regras.ts`, `vendas/relatorios/kpis.ts`, `vendas/recibos/alocacao.ts`, `compras/comum/calculos.ts`, `pos/comum/calculos.ts`) | F | OK (só apresentação), mas a pré-visualização pode divergir do servidor | Consolidar em `utilitarios/decimal.ts`. Para o POS, que mostra o total a cobrar, preferir uma simulação do servidor (`POST /pos/sessoes/{id}/vendas/simulacao`). |
| B12 | Regras de conversão e de estado duplicadas no frontend (`vendas/api.ts:81-87` `CONVERSOES`/`FISCAIS`; `*/comum/regras.ts` `accoes*`) | F | OK (o servidor valida tudo), com risco de divergência | Fazer os endpoints de detalhe devolver `accoes_permitidas: [...]`, calculadas pelo serviço; o frontend só as lê. |
| B13 | `numeroRecibo` AAAAMM-NNNN como fallback no recibo de salário | F `rh/comum/regras.ts:73`, `ReciboSalario.tsx:27` | OK (fallback visual) | Garantir que o backend devolve sempre o número; retirar o fallback. |

## 1.5 Regras verificadas e correctas (OK — o frontend só antecipa)

| Regra | Frontend (pré-visualização) | Backend (fonte da verdade) |
|---|---|---|
| Totais e IVA de documentos de venda, arredondamento AGT | `vendas/EmitirDocumento.tsx:104-116` | `Vendas/CalculadoraDocumento::calcular`, `ServicoDocumentosVenda:89, 112-113` |
| Taxa de IVA na venda | (só leitura) | da ficha do produto, `ServicoDocumentosVenda:336` |
| Numeração e séries | — | `ServicoSeries::reservar` (lock Redis + `FOR UPDATE`) e `ServicoNumeracao` |
| Conversões de documentos | `vendas/api.ts:81-87` | `ServicoDocumentosVenda::CONVERSOES`, `converter` (preços da origem) |
| Recibos: alocação FIFO | `vendas/recibos/alocacao.ts:15-40` | `ServicoRecibosVenda::criar` (l.169-189): ≤ pendente, mesmo cliente, FT contabilizada, `lockForUpdate` |
| POS: total, desconto e troco | `pos/comum/calculos.ts:49-68, 161-189` | `ServicoVendasPOS.php:63-66, 89-128` (troco só em numerário) |
| POS: fecho Z e desvio | `pos/comum/calculos.ts:248-262` | `ServicoSessoesPOS.php:147-172` |
| Hotelaria: rateio no check-out | `pos/comum/calculos.ts:278-301` | `ServicoCheckoutHotel::ratear` / `calcularComIva` |
| Compras: totais, quantidades pendentes e custo de entrada | `compras/comum/calculos.ts:24-39` | `CalculadoraCompra::calcular`; `ServicoRececoesCompra` (≤ pendente); `ServicoFaturasCompra:57-63` |
| Partida dobrada | `utilitarios/decimal.ts:42` (`equilibrio`) | `ServicoLancamentos::criar:48-52` (bcmath) e contas de movimento |
| IRT, INSS e líquido | (só mapas) | `RH/MotorSalarial` (ADR-013) |
| Amortizações e quota manual | `activos/comum/regras.ts` | `CalculadoraAmortizacoes`; quota manual ≤ base − acumulado (`ServicoAmortizacoes:253-292`) |
| Inventariação de aquisições | `activos/comum/regras.ts:162-170` | `ServicoAquisicoesAtivos` (Σ ≤ por inventariar) |
| Orçamento: total e segregação | `orcamento/comum/regras.ts` | `ServicoOrcamentos::gravarValores`; submeter ≠ aprovar (l.160) |
| CRM: valor da oportunidade | `crm/comum/tipos.ts:211-219` | recalculado a partir dos itens (`ServicoOportunidadesCRM:66-67`) |
| Reconciliação bancária | `teso/regras.ts:76-105` | `ServicoReconciliacaoBancaria::confirmar` (Σ extracto = Σ diário) |
| Stock negativo em guias de saída e POS | `stock/comum/carrinho.ts:13-25` | `ServicoStock::saida`, `ServicoVendasPOS::exigirStock` (`lockForUpdate`) |
| Segregação já imposta no servidor | `disabled={proprio}` em vários ecrãs | deliberação de compras, inventário, orçamento, prestação POS, desvio POS, lavandaria, Avaliação 360, portal RH, manutenção de dados |

## 1.6 Caminho inverso (legado → backend)

A pesquisa ao legado confirmou que estão no backend:
- o cálculo salarial completo (INSS, IRT por escalões, avençados 6,5 %, isenção de 30 000 Kz, pro-rata, horas extra 50/75 %);
- os estados do período salarial;
- a contabilização de salários;
- o encerramento e o apuramento;
- as regras de vendas: séries, isenção M, NC até ao saldo creditável, conversões, descontabilização;
- POS, terminais, hotelaria e lavandaria;
- tesouraria, meios de pagamento (IBAN mod 97) e reconciliação;
- compras: escalões e segregação;
- stock: custo médio ponderado real;
- inventário, amortizações e acréscimos;
- terceiros: NIF único, conta obrigatória.

Há também diferenças intencionais, documentadas:
- ROUNDING_DIFF substituído por cálculo exacto;
- estorno em vez de apagar;
- fotografia dos resultados salariais;
- numeração por série com lock.

**Regras do legado não encontradas no backend:** A2, A4, M5 (parcial), M6, M7, M14, M16, B6, B7, B8 e a segregação de salários (M8.3, que no legado era só aviso).

**Pedidas mas inexistentes também no legado** (não são lacunas de migração):
- retenção na fonte de 6,5 % em documentos comerciais;
- limite de crédito de cliente;
- descontos por linha em documentos.

---

# PARTE 2 — Redis a 100 %

## 2.1 Configuração

| Item | Desenvolvimento | Produção (`docker-compose.prod.yml`, `.env.prod.example`) |
|---|---|---|
| Servidor | `redis:7-alpine` 7.4.11, AOF ligado, `requirepass`, `maxmemory 512mb`, `volatile-lru`, porta 127.0.0.1:6380 | Igual, sem porta publicada, `protected-mode yes` |
| Cliente | `phpredis` | `phpredis` |
| Bases lógicas | 0 = default (filas, locks, rate limiting, sessões); 1 = cache. E2E: 2 e 3, prefixos `erp_e2e:` e `erp_e2e_cache:` | 0 e 1 |
| Cache | `CACHE_STORE=redis`, `CACHE_PREFIX=erp:`; locks em `lock_connection=default` | Igual |
| Filas | `QUEUE_CONNECTION=redis`; **`retry_after` = 90 s** (não definido no `.env`) | `REDIS_QUEUE_RETRY_AFTER=660` |
| Worker | `queue:work redis --queue=alta,agt,default,pdfs,baixa --timeout=600 --tries=3 --max-time=3600` | Igual, com `--memory=512` e healthcheck |
| Scheduler | `schedule:work` | Igual, com healthcheck |
| Sessões | `SESSION_DRIVER=redis` (a API usa tokens Sanctum na BD; sem `statefulApi`) | Igual |
| Modo de manutenção | `APP_MAINTENANCE_DRIVER=file` | `cache` / store `redis` (correcto para vários contentores) |

## 2.2 Inventário de utilizações

| Tipo | Chave ou nome | Onde | TTL | Invalidação |
|---|---|---|---|---|
| Cache | `erp:utilizador:{id}:empresas:v{versão}` | `Services/Sistema/ServicoEmpresas::idsAcessiveis` (l.20-34), usado por `ResolverEmpresaAtiva` em **todos** os pedidos com empresa | 3600 s | `Utilizador::saved` (papel, acesso a todas, activo), `UtilizadorEmpresa::saved/deleted`, `ServicoUtilizadores:437`, `ServicoGestaoEmpresas:123`, `ServicoCopiaEmpresa:521`, `ServicoConsolidacaoGrupos:104`; global com `empresas:versao` (`Empresa::saved` em estado ou eliminação, `deleted`, `restored`; `ServicoConsolidacaoGrupos:166`) |
| Cache | `erp:empresas:versao` | `ServicoEmpresas` (l.54-62) | sem TTL | incremento não atómico (ver R7) |
| Cache | `erp:{empresa}:contabilidade:plano_contas` | `Contabilidade/ServicoPlanoContas::todas` → `contaDeMovimento` (valida **todas** as linhas de lançamento) | 86 400 s | `PlanoConta::saved/deleted/restored`, `ServicoConsolidacao:337`, `ServicoMigracaoDados:177` |
| Cache | `erp:{empresa}:logistica:catalogo_produtos` | `Logistica/ServicoProdutos::catalogo` → `GET /api/logistica/produtos/catalogo` (pré-preenche preços no frontend) | 21 600 s | `Produto::saved/deleted/restored`, `ServicoMigracaoDados:178`, `ServicoSubstituicaoConta:169` |
| Cache | `erp:gestao:painel:{md5(empresa, painel, mês, filtros, permissões, acessíveis)}` | `Gestao/Paineis/ServicoPaineis::painel` (l.138-145) | 120 s | só TTL e `actualizar=1` (aceitável e documentado) |
| Cache | `erp:gestao:comparacao:{md5(utilizador, ids, mês)}` | `PaineisController::comparacao` (l.80-84) | 120 s | só TTL e `actualizar=1` |
| Cache | `erp:fk_referencias:{tabela}` | `Support/Dados/VerificadorReferencias::referenciasPara` (l.19) | 3600 s | nenhuma (ver R8) |
| Cache | `erp:copia_empresa:tabelas:{versão do esquema}` | `ServicoCopiaEmpresa::tabelasEmpresa` (l.238) | 3600 s | versionada pela última migração (correcto) |
| Lock | `erp:lock:numeracao:{empresa}:{chave}` | `ServicoNumeracao::proximo` | 10 s, espera 5 s | libertado no fim; a serialização final é garantida pelo `FOR UPDATE` |
| Lock | `erp:lock:serie:{empresa}:{tipo}:{ano}:{origem}` | `ServicoSeries::reservar` | 10 s, espera 5 s | idem |
| Lock | `erp:lock:agt:{envio\|consulta}:{empresa}` | `ServicoEnvioAgt::comLock` (l.322-329) | 300 s, espera 1 s | idem |
| Lock (único) | `CicloAgt` com `ShouldBeUnique`, `uniqueId = empresa` | `Jobs/Vendas/CicloAgt` | `uniqueFor` 300 s | libertado no fim do job |
| Mutex | `withoutOverlapping` em `erp:agt:ciclo` e `compras:contratos-expirados` | `routes/console.php` | 24 h (por omissão) | libertado no fim |
| Rate limiting | `entrar` (utilizador + IP; IP) e `api` (utilizador ou IP; 300/min) | `AppServiceProvider::configurarLimites`; `bootstrap/app.php` `throttleApi()` | 60 s | — |
| Fila | `queues:{alta,agt,default,pdfs,baixa}` | Só **um** job: `CicloAgt` (`CicloAgtCommand`, `ServicoDocumentosVenda:165`) | — | — |
| Sessões | `SESSION_DRIVER=redis` | Sem uso efectivo na API (tokens na BD) | — | — |
| Saúde | `Redis::connection()->info()` e `Queue::size` | `SaudeController` | — | — |

Notas:
- **As permissões não estão em cache.** `ServicoPermissoes` está registado como `scoped` (por pedido ou job) e memoriza só em memória. Não há, por isso, risco de permissões desactualizadas. As chaves de configuração `erp.cache.ttl.permissoes_utilizador` e `taxas_cambio` existem mas **não são usadas**. A frase de `docs/PRODUCAO.md:185` («permissões… em cache») está imprecisa.
- **O worker processa só o ciclo AGT.** Conciliação, folhas em massa, PDFs e ETL correm de forma síncrona no pedido HTTP, apesar do comentário no `docker-compose.yml`. Não é um defeito do Redis, mas a documentação deve reflectir a realidade.

## 2.3 Resultados das verificações no ambiente a funcionar

`docker compose ps`:
- `erp_app`, `erp_postgres` e `erp_redis` com estado *healthy*;
- `erp_scheduler` e `erp_web` em execução;
- `erp_worker` em execução há 30 min (reinício normal por `--max-time=3600`).

| Verificação | Resultado |
|---|---|
| `PING` | `PONG` |
| `INFO` | v7.4.11; 2,05 MB / 512 MB; `volatile-lru`; AOF `ok`; RDB `ok`; `evicted_keys=0`; `rejected_connections=0`; 3 clientes |
| `DBSIZE` / keyspace | db0: 0 chaves (filas vazias, sem locks pendentes); db1: 6 a 14 chaves (`plano_contas` de várias empresas, `catalogo_produtos` da empresa 6, `utilizador:1:empresas:v8`, `empresas:versao`); db3: 3 chaves E2E. As chaves seguem o formato `erp:{empresa}:{módulo}:{chave}`. Não foram lidos valores. |
| `Redis::connection('default')` e `('cache')` ping | OK / OK |
| `Cache::put/get/forget/increment` | OK (≈ 4,9 ms o conjunto) |
| `Cache::remember` (valor estruturado) | OK. Nota: com phpredis, valores numéricos voltam como *string*. O código actual faz *cast* (`(int) Cache::get('empresas:versao')`), por isso não há defeito. |
| `Cache::lock` | exclusivo (2.º `get` falha); reutilizável depois de `release`; `block(1)` lança `LockTimeoutException` |
| `RateLimiter` | `hit` ×3 → `attempts=3`; `tooManyAttempts(…,3)=true`; `clear` → 0 |
| `Queue::size` (5 filas) | 0 em todas |
| Worker | job inócuo de teste (fila `baixa`) processado em **0,5 s** pelo contentor do worker |
| Scheduler | `schedule:list`: 3 tarefas. Logs: `erp:agt:ciclo` executado a cada 2 min (08:08, 08:10, 08:12) em cerca de 1 s, `DONE` |
| Pedidos reais autenticados (utilizador temporário criado dentro de uma transacção **desfeita**) | `GET /api/logistica/produtos/catalogo` (empresa 6): 1.º pedido 193,6 ms (inclui o arranque do kernel), 2.º **13,9 ms** (cache); consulta directa à BD 32,2 ms. Cabeçalho `X-RateLimit-Remaining` 299 → 298: o limitador está activo. A chave `utilizador:{id}:empresas` do utilizador temporário foi criada pelo middleware e **apagada no fim**. O utilizador não existe após o *rollback*. |
| Limpeza | Não ficou nenhuma chave `teste_reg:*` nem do utilizador temporário nas db0 e db1. Os scripts em `/tmp` do contentor e no scratchpad foram apagados. |

## 2.4 Riscos de invalidação e de isolamento entre empresas

As chaves por empresa incluem sempre `{empresa}`. As dos painéis incluem a empresa, as permissões e as empresas acessíveis; a da comparação inclui o utilizador. **Não há fuga entre empresas em funcionamento normal.** Os riscos são estes:

| # | Gravidade | Risco | Evidência | Correcção |
|---|---|---|---|---|
| R1 ✔ | **ALTA** | **A cache sobrevive à migração do legado com `--substituir`.** `TRUNCATE … RESTART IDENTITY CASCADE` não dispara eventos de model e reutiliza ids. Durante 1 h, o utilizador novo com o id X herda a lista de empresas acessíveis do antigo X: é uma **fuga entre empresas**. Durante 24 h, a empresa com o id N valida lançamentos contra o plano de contas antigo; durante 6 h, o catálogo fica antigo. Com `--simular`, qualquer `remember` feito durante a migração guarda dados que o *rollback* apaga. O E2E já trata este caso (`PrepararE2E.php:47-53`), mas a migração não. | `Services/Migracao/ServicoMigracaoLegado.php:175-187` (sem `Cache::`); `Console/Commands/MigrarBackupLegado.php` | Ver «Detalhe R1» abaixo da tabela. |
| R2 ✔ | MÉDIA | **A invalidação corre dentro da transacção, antes do COMMIT.** Os `static::saved(fn () => Cache::forget(...))` de `PlanoConta.php:22`, `Produto.php:24`, `Empresa.php:50`, `Utilizador.php:66-71` e `UtilizadorEmpresa.php:22` disparam dentro de `DB::transaction`. Um pedido concorrente nessa janela volta a guardar os dados **antigos**, que ainda são os visíveis. Consequências: conta nova → `CONTA_INEXISTENTE` durante 24 h; conta passada a totalizadora → aceita lançamentos durante 24 h; **acesso a uma empresa retirado → mantém-se até 1 h**. | ficheiros citados | Ver «Detalhe R2» abaixo da tabela. |
| R3 ✔ | MÉDIA | **A importação de cópia de empresa não invalida a cache.** `ServicoCopiaEmpresa::importar` (para uma empresa existente vazia) e `clonar` inserem `plano_contas` e `produtos` por `DB::table()->insert` (l.273), sem eventos. Se alguém abriu a empresa antes, ficam em cache um plano vazio (24 h) e um catálogo vazio (6 h). | `Services/Sistema/ServicoCopiaEmpresa.php:149-199, 273` | No fim de `importar` e `clonar`, fora de `simular`, executar `DB::afterCommit(fn () => [Cache::forget(ChaveCache::empresa($id,'contabilidade','plano_contas')), Cache::forget(ChaveCache::empresa($id,'logistica','catalogo_produtos'))])`. |
| R7 | BAIXA | `invalidarTodos()` faz `get` e depois `forever(v+1)`, o que não é atómico: duas alterações simultâneas podem produzir a mesma versão. | `ServicoEmpresas.php:54-57` | `Cache::add('empresas:versao', 1); Cache::increment('empresas:versao');` |
| R8 | BAIXA | `fk_referencias:{tabela}` não tem versão. Até 1 h depois de uma migração de esquema com uma FK nova, `VerificadorReferencias` não vê a nova referência e deixa eliminar logicamente um registo em uso; a FK real não impede eliminações lógicas. | `Support/Dados/VerificadorReferencias.php:19` | Incluir `versaoEsquema()` na chave, como em `ServicoCopiaEmpresa`, ou fazer `cache:forget` destas chaves no arranque do contentor. |
| R9 | BAIXA | Painéis e comparação: até 120 s de dados desactualizados depois de uma escrita. | `ServicoPaineis.php:38, 138-145` | Aceitável e documentado (há o botão «Actualizar»). Nada a fazer. |

**Detalhe R1.** Correcção:
1. Em `ServicoMigracaoLegado`, depois do COMMIT e também no fim de `--simular`, limpar a base lógica da cache. Usar a mesma guarda do `PrepararE2E`: `Cache::flush()` só se `database.redis.cache.database` for dedicada; caso contrário, apagar por padrão `erp:*` com `SCAN` na ligação `cache`.
2. Incluir no procedimento da migração definitiva, em `docs/PRODUCAO.md`, o passo obrigatório `php artisan cache:clear` depois da migração.
3. Defesa adicional: incluir na chave das empresas acessíveis um elemento que mude quando o utilizador é recriado, por exemplo `utilizador:{id}:{criado_em timestamp}:empresas`.

**Detalhe R2.** Correcção: invalidar **depois** do commit e manter a invalidação imediata (duplo *delete*). Em cada `booted()`:

```php
$invalidar = function (PlanoConta $c) {
    $f = fn () => Cache::forget(ChaveCache::empresa((int) $c->empresa_id, 'contabilidade', 'plano_contas'));
    $f();
    DB::afterCommit($f);
};
```

O mesmo padrão aplica-se a `Produto`, `Empresa` (`invalidarTodos`), `Utilizador` e `UtilizadorEmpresa`. A alternativa é usar Observers com `ShouldHandleEventsAfterCommit`. Aplicar também às chamadas explícitas em `ServicoUtilizadores:437`, `ServicoConsolidacao:337` e `ServicoConsolidacaoGrupos:104/166`, que estão dentro de transacções.

## 2.5 Comportamento se o Redis cair

| Componente | O que acontece | Fallback |
|---|---|---|
| **Toda a API** | `throttleApi()` (`bootstrap/app.php:22`) consulta o `RateLimiter` em **todos** os pedidos `/api/*` → `RedisException` → `ManipuladorExcecoesApi` devolve **HTTP 500** `ERRO_INTERNO` | Nenhum |
| Pedidos com empresa | `ResolverEmpresaAtiva` → `ServicoEmpresas::idsAcessiveis` (`Cache::remember`) → 500 | Nenhum |
| Login (`/api/entrar`) | `throttle:entrar` → 500 | Nenhum |
| Numeração (vendas, lançamentos, POS, Z) | `Cache::lock` lança `RedisException`, não `LockTimeoutException` → 500. A transacção exterior é desfeita: **não há números perdidos nem duplicados** | Não é necessário: a integridade está garantida pela BD |
| Emissão de factura com envio automático à AGT | `CicloAgt::dispatch(...)->afterCommit()` (`ServicoDocumentosVenda.php:165`). O `ShouldBeUnique` e o `push` usam o Redis. A excepção ocorre **depois do COMMIT**: a factura fica gravada mas o utilizador recebe 500 e pode **voltar a emitir** (risco de factura duplicada) | Nenhum. Ver **R4** |
| Worker e scheduler | `queue:work` falha e reinicia (`restart: unless-stopped`); `withoutOverlapping` falha e a tarefa não corre. O ciclo AGT fica parado | Recuperam sozinhos quando o Redis volta |
| `/api/saude` | Está no grupo `api` e passa pelo throttle. Com o Redis em baixo devolve **500 genérico**, não o **503 com o detalhe por componente** que o controlador foi desenhado para dar. O `/up` não verifica o Redis | Ver **R5** |
| Persistência | AOF ligado: um reinício do Redis não perde filas, locks *unique* nem `empresas:versao` | — |

**Riscos e correcções:**

| # | Gravidade | Correcção |
|---|---|---|
| R4 ✔ | MÉDIA | Em `ServicoDocumentosVenda::emitir`, envolver o envio do job: `try { CicloAgt::dispatch($empresa)->afterCommit()->delay(...); } catch (Throwable $e) { Log::warning('Envio AGT não agendado; o agendador recolhe o documento no próximo ciclo', [...]); }`. O `erp:agt:ciclo` de 2 em 2 min já apanha os documentos `PRONTO`. Em alternativa, usar `DB::afterCommit(fn () => rescue(fn () => CicloAgt::dispatch(...)))`. |
| R5 ✔ | MÉDIA | Retirar o throttle da rota de saúde: em `routes/api.php:57`, `Route::get('saude', SaudeController::class)->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class.':api')`, ou com um limitador próprio que não use o Redis. Assim `/api/saude` devolve 503 com `redis: FALHA`. Usar `/api/saude` no healthcheck do contentor `app` ou do *reverse proxy*. |
| R6 | MÉDIA (operacional) | O Redis é um ponto único de falha da API inteira; é uma decisão de arquitectura aceitável (ADR da directiva). Mitigações: (a) monitorizar `/api/saude`; (b) opcionalmente, um limitador `api` resiliente que, perante `RedisException`, deixa passar e regista um aviso em vez de devolver 500 (manter o `entrar` estrito); (c) documentar em `docs/PRODUCAO.md` o comportamento e a recuperação. |
| R10 | BAIXA | No desenvolvimento, `REDIS_QUEUE_RETRY_AFTER` não está definido (90 s), abaixo do `--timeout=600` do worker e do `$timeout=300` do `CicloAgt`. Com mais de um worker, um ciclo com mais de 90 s seria entregue segunda vez; o lock `agt:envio` mitiga. Acrescentar `REDIS_QUEUE_RETRY_AFTER=660` ao `backend/.env` e ao `.env.example`, como já existe em produção. |
| R11 | BAIXA | O `/api/saude` não sabe se o worker e o scheduler estão vivos: só vê o tamanho das filas. Acrescentar um batimento: `Schedule::call(fn () => Cache::put('batimento:scheduler', now()->timestamp, 300))->everyMinute()` e um job periódico que grava `batimento:worker`. O `SaudeController` marca FALHA se algum tiver mais de 5 min. |
| R12 | BAIXA | `volatile-lru`: chaves sem TTL (filas, `empresas:versao`, locks *unique*) nunca são despejadas. Se a memória encher, as escritas falham (OOM). Ocupação actual: 2 MB em 512 MB, risco baixo. Vigiar `used_memory` na monitorização. |

---

## Ordem de execução recomendada

1. **Antes da migração definitiva:**
   - R1 (limpeza de cache na migração);
   - R2 (invalidação depois do commit);
   - R3 (importação de cópias);
   - A1 (contornos na Tesouraria);
   - A2 e A3 (NC);
   - A4 (pré-validação AGT bloqueante; decidir com o utilizador).
2. **A seguir:**
   - R4 e R5 (comportamento com o Redis em falha);
   - M1 a M5 (preço, câmbio, IVA, ajustes de stock, exercício encerrado);
   - M6 (nota de fluxo de caixa);
   - M10 (aditamentos de projecto);
   - M13 (Mapa de IRT no servidor).
3. **Com decisão do utilizador:**
   - M7 (lançamentos em moeda estrangeira);
   - M8 (paridade salarial, ADR-013);
   - M14 (notas DEMO automáticas);
   - M16 (mesas de restaurante no POS);
   - B6 a B8.
4. **Limpeza:**
   - B11 a B13 (duplicações no frontend; endpoints devolvem `accoes_permitidas`);
   - R7, R8, R10 a R12;
   - corrigir `docs/PRODUCAO.md:185` e o comentário do worker no `docker-compose.yml`.

Cada correcção deve ter um teste de *feature*. Para as regras de Tesouraria e NC, incluir os casos de contorno descritos (número de documento falso, conta trocada, venda anulada, NC sobre factura paga, NC com preço ou taxa diferentes da origem).
