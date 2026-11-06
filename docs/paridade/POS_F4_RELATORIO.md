# POS F4 — Hotelaria: relatório

Âmbito: paridade com `js/hotelaria.js` (check-in, consumos, anulação, check-out por quarto e em factura única, saída tardia, talão), mais a
verificação numérica das regras R-029…R-033. Sem reservas, housekeeping ou ocupação futura (o legado não os tem). Sem migrações nem novas
permissões (nada foi preciso: `catalogo.json` intacto).

## 1. Verificação numérica (`backend/tests/Feature/ParidadePosHotelariaTest.php`)

Todos os valores esperados foram calculados à mão a partir do código do legado (os cálculos estão nos comentários dos testes). O sistema novo
deu os mesmos números em todos os casos, excepto os dois assinalados como diferença deliberada.

| Regra | Casos verificados | Resultado |
|---|---|---|
| R-029 diária | 14:00 dia 5 → 12:00 dia 6; virar mês; madrugada; 3 diárias; 2,5 → 3 (`Math.round`); 0,4 → 1 (`max(1,…)`); hora de saída do terminal | igual |
| R-030 hora e bloqueio | +2,5 h (inclui passar à meia-noite); janela 21:00–08:00 (21:00, 20:59, 07:59, 08:00, 00:00, 12:00); janela no mesmo dia; `de = ate` e bloqueio desligado | igual |
| R-031 saída tardia | diária: tolerância (13:00 sem proposta, 13:01 +1), exactamente 24 h (+1), 24 h e 1 min (+2), 25 h com 2 diárias (+2); hora: 19:00 sem proposta, 19:01/19:30/20:00 → 5 h, 20:30 → 6 h, 2,5 h → 4 h (extra 1,5) | igual (BUG-15 replicado) |
| R-032 totais | 25 080 − 10 % = 22 572 (base 19 800, IVA 2 772); recálculo à hora 6 × 1 140 = 6 840; recálculo à diária 4 × 11 400 = 45 600 − 20 % = 36 480 (32 000 + 4 480) | igual |
| R-032 IVA misto | 11 400 (14 %) + 3 × 500 (7 %) = 12 900. Legado: base 11 401,87 / IVA 1 498,13. Novo: base 11 401,86 / IVA 1 498,14 | **DIFERENÇA DELIBERADA** (total igual) |
| R-032 desconto | 11 400 + 2 × 1,10 com 15 %. Legado: 9 691,87. Novo: 9 691,88 | **DIFERENÇA DELIBERADA** (0,01) |
| R-033 rateio | 2 facturas (22 572 / 5 130; TPA 20 000 + numerário 7 702 + troco 298): 16 296,30 / 6 275,70 e 3 703,70 / 1 724,30; 3 × 10,00 com 3 pagamentos iguais (cêntimo ao primeiro maior, última fecha); 100 / 100 / 100,01; troco numa só factura | igual |

### Diferenças deliberadas (a decisão do utilizador: as correcções do novo prevalecem)

1. **Base e IVA por excesso ao cêntimo** (R-025): o documento selado calcula o IVA de cada linha por excesso ao cêntimo e a base é a maior
   que não ultrapassa o cobrado; o legado recalculava a base à parte (`liquido = Σ g/(1+t)`) só para o rateio. O **total** (o que se cobra e
   se paga) não muda; a divisão base/IVA pode diferir 0,01 por linha com IVA ≠ 14 %.
2. **Desconto arredondado por linha**: o total do check-out é a soma das linhas do FR emitido (`facturacao_agt.js:240-266`), igual à venda POS
   e à lavandaria. O legado arredondava `bruto × (1 − d)` num só passo, o que podia diferir 0,01 do documento que ele próprio selava.
   O ecrã de check-out já avisa que o total pode diferir um cêntimo do contratado.

## 2. Stock dos consumos (BUG-20) — alinhado

- **Legado**: `addPOSProduct` recusa no carrinho a mercadoria com `stock_qty ≤ 0` («O artigo X encontra-se fora de stock.») ou acima do stock
  («Não é possível faturar mais unidades … (N).»), pelo stock **global**; a baixa só ocorre no check-out (`criarVendaPOS`), onde o stock
  pode entretanto já não chegar.
- **Antes (novo)**: os consumos aceitavam qualquer quantidade e só o check-out validava (erro genérico da venda).
- **Agora**: `PUT /pos/hotelaria/estadias/{id}/consumos` recusa com as mesmas mensagens e o código `STOCK_INSUFICIENTE`, mas pelo saldo do
  **armazém do terminal** (correcção já adoptada no POS: o legado usava o stock global) e na soma do pedido por produto; serviços sem limite.
  A baixa continua só no check-out (igual ao legado, BUG-20 replicado). O check-out revalida a soma de todas as estadias seleccionadas e, se
  faltar, devolve «Não é possível fechar o check-out: stock insuficiente de X para os consumos de Quarto N (pedido …, disponível …)…», sem
  facturar nada (transacção única). Não há reserva de stock entre estadias (o legado também não).

## 3. F-xxx do sub-módulo

| F | Estado inicial | Resultado F4 |
|---|---|---|
| F-161…F-169, F-171…F-180 (19 IGUAIS) | IGUAL | verificadas contra `hotelaria.js` linha a linha e pelos casos numéricos acima; sem alterações (os testes `POSHotelariaTest` já cobriam o fluxo ponta a ponta) |
| F-167 aviso de check-in antecipado | IGUAL (só na frente de caixa) | o `ModalCheckin` do separador Hotelaria passa a ter a mesma pré-visualização do legado (`hotelPrever`): saída prevista, avisos (antecipado, hora bloqueada, mínimo de horas) e bloqueio do botão; preço 0 pede confirmação |
| F-170 consumos | DIFERENTE | recusa de stock (secção 2); resto igual |
| F-181 talão único | DIFERENTE | opção do operador (Q10): por omissão um talão por factura; com várias facturas, caixa «único talão» antes de emitir e botões «um talão por factura» / «talão único» no fim. `hotelaria/taloes.ts::juntarVendas` soma linhas, totais e pagamentos e põe todos os documentos e quartos no título, como o legado (`docNumber` com vírgulas, `table` = quartos). O QR de cada documento depende do QR do talão (F-071, F1) e não foi tocado |

Criação de quartos: continua a fazer-se marcando o produto (`e_quarto`) e editando as tarifas (`PUT /pos/hotelaria/quartos/{produto}`),
como no legado (as funções de criar quarto estavam obsoletas).

## 4. Ficheiros

- Backend: `Services/POS/Hotelaria/ServicoEstadiasHotel.php` (stock dos consumos, `exigirStockCheckout`), `ServicoCheckoutHotel.php` (chamada),
  `tests/Feature/ParidadePosHotelariaTest.php` (11 testes, 96 asserções).
- Contrato JSON: `tests/Fixtures/contrato_pos.json` — duas linhas alargadas de forma deliberada (`PUT …/consumos` aceita preços decimais;
  `POST …/checkout/simular` passa a ver `opcao_atraso` como texto). Nenhum campo novo na API.
- Frontend: `pos/hotelaria/{Checkout,Estadia}.tsx`, `pos/hotelaria/taloes.ts` + `taloes.test.ts`.
- Docs: linhas F-170 e F-181 da matriz.

## 5. Verificação

`php artisan test --filter="Hotelaria|Estadia"` com `POS_CONTRATO=verificar`: 16 verdes (308 asserções); Pint OK; `tsc --noEmit` limpo;
vitest `pos/hotelaria` + `frente/quartos`: 8 verdes. Não se correu a suite completa (rotas novas: nenhuma).

## 6. Fora do âmbito / por fazer

- QR em cada documento do talão único (F-071, frente/talão POS comercial, F1).
- Reservas, housekeeping e ocupação futura: fora, o legado não os tem.
- `npm run build` do `dist` não foi feito (sem suite massiva); fazê-lo no fecho da F9.
