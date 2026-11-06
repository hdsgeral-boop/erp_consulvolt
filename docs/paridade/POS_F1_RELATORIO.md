# POS — Relatório da F1 (frente de caixa e talão) e F1b (extras decididos)

Ramo `paridade-bd-front`, 2026-11-06. Âmbito: POS comercial (loja e restaurante). Não tocou POS de armazém, hotelaria, lavandaria, mesas nem a integração em massa (F6).

## 1. F-xxx cobertas

| F | Estado antes | O que mudou |
|---|---|---|
| F-009 | PARCIAL | `AberturaCaixa` mostra a série FR do terminal (`/pos/frente/series`, «criada na primeira venda do ano» quando prevista) e os meios com aviso vermelho (já existia) |
| F-017 | PARCIAL | `BarraSessaoFrente`: «Saldo:» com Fundo, uma pílula por meio activo (numerário = esperado em caixa, TPA = cobrado, transferência só com movimento), Vendas e Total; cores do legado (`COR_TIPO_MEIO`) |
| F-030, F-033 | DIFERENTE | O catálogo do terminal devolve também os produtos bloqueados (`bloqueado: true`); a grelha mostra-os a 65 % com o crachá «Bloqueado»; ao clicar aparece `O artigo "X" está bloqueado para venda.`; o servidor recusa a venda (`PRODUTO_BLOQUEADO`, mesmo texto). Os outros consumidores de `useCatalogoPOS` (consumos do quarto) continuam sem bloqueados (`incluirBloqueados`) |
| F-034, F-035 | DIFERENTE | Textos do legado, medidos no armazém do terminal: `O artigo X encontra-se fora de stock.`, `Não é possível faturar mais unidades deste produto do que o stock existente (N).` e, em −/+/campo, `Quantidade limite de stock disponível para X atingida (N).` (cliente: `motivoNaoAdicionar`/`motivoQuantidadeExcede`; servidor: `ServicoVendasPOS::exigirStock`). Quarto em caixa que não é de hotelaria: `O artigo de hospedagem/quarto "X" só está disponível no POS do tipo Hotelaria.` |
| F-068 | PARCIAL | O modal de sucesso avisa quando o documento fica `COM_ERROS` na AGT (`avisoErrosAgt`). O reenvio/revisão continua em Vendas › Facturação electrónica (F6 trata dos KPI/notificações) |
| F-070 | PARCIAL | `htmlTalaoVenda`: «Talão nº», data, operador real (nunca «CAIXA POS»), Cliente e «NIF Cliente» (999999999 por omissão), Base/IVA/Desconto/Arredondamento, «TOTAL A PAGAR», «Meios de Pagamento:», «Valor Entregue (Dinheiro)» (= líquido + troco) e «Troco», coordenadas bancárias, rodapé e «Processado por computador (Software ERP)» |
| F-071 | EM FALTA | `GET /api/pos/frente/talao/{venda}` (`ServicoTalaoPOS`, permissão POS: `pos_view`/`pos_venda`/…): QR de consulta da AGT (o mesmo URL e gerador `ServicoQrAgt` das facturas, em SVG), série electrónica, cliente e coordenadas dos meios activos com IBAN. Sem regime não há QR nem série. O SVG entra no talão como `<img src="data:…">` (nunca como marcação) |
| F-072 | PARCIAL | Modal de sucesso com «Imprimir talão» e «Imprimir A4 / PDF» (motor comum; «Guardar como PDF» na janela de impressão, como no resto da app). PDF no servidor fica para a F7 |

Não atribuídas à F1 pela matriz (ficaram fora, como pedido): zoom (F-027) e arrastar-e-largar (F-032) — F8.

## 2. F1b (extras)

- **Consulta e reimpressão das vendas da sessão**: botão «Vendas» na barra da sessão (`VendasSessaoModal`); `GET /api/pos/sessoes/{sessao}/vendas-consulta[?q=]` e `…/vendas-consulta/{venda}` (linhas para o talão). Reimpressão com QR e IBAN.
- **Atalho «Emitir NC»**: activo se `nc_permitida` (FR, com saldo por creditar, sessão não integrada) e `vendas_fat_emitir`; confirma e abre o documento em Vendas com a janela «Converter» (NC) aberta. **Q17 (parcial)**: NC sobre FR de sessão já integrada é recusada pelo servidor (`NC_SESSAO_INTEGRADA`, em `ServicoDocumentosVenda::validarNotaCredito` através de `ServicoConsultaVendasPOS::exigirNcPermitida`); para a emitir descontabiliza-se a sessão. **Falta (F6)**: permitir a NC com permissão contabilística acompanhada de estorno na transitória, e abater a NC aos totais da sessão ainda aberta.
- **Suprimento e sangria**: tabela `movimentos_caixa_pos`, permissão `pos_movimento_caixa` (sensível; **não** está no perfil-modelo «Operador POS»: é decisão de supervisão), `GET/POST /api/pos/sessoes/{sessao}/movimentos` e `POST …/movimentos/{id}/anular`. Só com a sessão aberta, motivo ≥ 3 caracteres, a sangria não excede o numerário esperado; anular não apaga.
  - **Cálculo**: `numerario_esperado = fundo + numerário de vendas + suprimentos − sangrias` (relatório X, fecho Z e desvio). X devolve `suprimentos` e `sangrias`; a sessão **não** ganhou colunas (calculam-se da tabela). O Z impresso não mostra as duas linhas (usa o registo da sessão); mostra-as o X.
  - **Contabilização** (na integração da sessão, um lançamento POS por data do movimento, estornado com a sessão): suprimento D transitória do numerário / C conta de liquidação do meio (caixa 45); sangria D conta de liquidação (caixa/cofre 45) / C transitória. Contas gravadas no movimento.
  - **Prestação de contas / folha de caixa**: o item de numerário passa a `vendas + suprimentos − sangrias (+ desvio)` (a sangria é uma entrega antecipada); a transitória do numerário fica a zero. Verificado em teste: vendas 1 140, suprimento 2 000, sangria 3 000 ⇒ prestar 140,00.

## 3. Migrações novas (ensaiar na cópia local antes da produção)

- `2026_12_09_090000_pos_movimentos_caixa.php`: cria `movimentos_caixa_pos` (idempotente, CHECK de tipo/estado/valor, FK para empresas e sessoes_pos, índice, GRANT e RLS por `IsolamentoRls::aplicarATodas`). Sem alterações a tabelas existentes. A tabela não está em `esquema.json`/`esquema_extra.mjs` (modelo escrito à mão em `App\Models\MovimentoCaixaPOS`); incluí-la no gerador fica para quando se regenerar o esquema.

## 4. Contrato JSON (`POS_CONTRATO=verificar`)

Actualizado de propósito: catálogo do terminal (`bloqueado`) e relatório X (`suprimentos`, `sangrias`). Nos varredores de rotas (ContaRoot, EntradasMaliciosas, ConsultasPorEndpoint) o modo `verificar` falha em `GET pos/frente/sessoes-antigas` com dados reais (já acontecia antes; sem a variável passam todos).

## 5. Decisões

- Texto dos erros de stock/bloqueio: o do legado com a acentuação corrigida (Q15); stock sempre do armazém do terminal (Q1).
- QR só no regime AGT; ATCUD não se aplica a Angola.
- Os talões de hotelaria (`Checkout.tsx`) e da lavandaria (`DetalheOrdem.tsx`) continuam a chamar `htmlTalaoVenda` sem o 5.º argumento (pertencem a outros agentes): para terem QR/IBAN basta passar `await obterTalaoFiscal(venda.id)`.
- NC em sessão aberta ou fechada não integrada fica permitida (credita a conta do cliente, ADR-071).

## 6. O que falta

- F6: estorno na transitória para NC de sessão integrada com permissão contabilística; NC a abater os totais da sessão aberta; KPI/notificações de FE com erros.
- Z impresso com as linhas de suprimentos/sangrias (precisa de campos persistidos ou do endpoint de movimentos no fecho).
- Passar `obterTalaoFiscal` aos talões de hotelaria e lavandaria; PDF no servidor (F7); zoom e arrastar (F8).
- Teste e2e (Playwright) de «vender e ver o QR» não foi escrito (o ambiente e2e não foi usado).
