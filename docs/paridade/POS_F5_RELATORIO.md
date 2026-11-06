# POS — Fase F5: lavandaria e alfaiataria

Objectivo: a lavandaria cobra «tal como o legado» — por **opção de preço** (Peça, Quilograma, Tamanho) da peça e do serviço — sem apagar dados de produção e sem mudar o total de nenhuma ordem antiga. Depende da F0b (colunas `opcoes_preco`, `opcoes_preco_origem`). O RC-LAV no SAF-T fica na F6 (não tocado).

## 1. Regra de preço (backend)

- `RegrasLavandaria`: `MODOS`, `opcoesPeca()` (as opções guardadas, sem tamanhos sem nome; na falta, a opção única reconstruída de `unidade`+`preco`, como `opcoesPeca` do legado), `chaveOpcao()` (modo + tamanho sem distinguir maiúsculas), `rotuloOpcao()`, `unidadeOpcao()` (KG só no modo quilograma; o tamanho vende-se à peça).
- `ServicoTabelasLavandaria::precoPecaServico($peca, $servico, ?$opcao)`: entrada de `precos_servico` da **opção** (modo+tamanho) → na falta, a entrada antiga **sem modo** (valia para a opção única que a peça tinha; igual ao legado) → na falta, o preço da própria opção. Sem opção, a 1.ª (compatível com os clientes e ordens anteriores).
- `opcaoDaPeca($peca, modo, tamanho)`: devolve a opção pedida ou lança `OPCAO_PRECO_INVALIDA` (422).
- `ServicoOrdensLavandaria::prepararItens`: aceita `modo`/`tamanho` por linha; preço pela opção; a quantidade só pode ser fraccionada no modo kg; a linha guarda `modo`, `tamanho` e `unidade`; a mensagem «sem preço» passa a dizer a opção («não tem preço em «Tamanho M» para o serviço…»). `GuardarPecaRequest`/`RegistarRequest` aceitam `opcoes_preco[]`, `precos_servico[].modo/tamanho`, `itens[].modo/tamanho`.
- **Perda de dados corrigida**: `precosServico` já não regrava `{produto_id, preco}` — preserva `modo`/`tamanho`; só descarta, como o legado, as entradas de opções que a peça deixou de ter. Editar uma peça sem `opcoes_preco` (cliente antigo, importador) mantém as opções: `unidade`/`preco` só alteram a 1.ª opção **se mudaram** (reenviar o espelho de uma peça cuja 1.ª opção é um tamanho não a destrói). Toda a gravação marca `opcoes_preco_origem = NOVO`.
- Validações do legado, no servidor: «Dê nome ao tamanho…», «Há duas opções de preço iguais…», preço inválido, peça duplicada por **designação + tecido + 1.ª opção** (antes era unidade).
- Importador (`ServicoImportacaoTabelasLavandaria`): preços por peça × serviço × **opção** (4.ª coluna «Opção»: Peça, Kg, «Tamanho M» ou só o nome do tamanho; vazia = a 1.ª), erro «a peça não tem a opção "X"», e a gravação substitui só a entrada daquela opção (as outras ficam intactas, com modo e tamanho). Reconhece os cabeçalhos dos modelos .xlsx (com «*», «(vazio = novo)», «Venda por (Peça/Kg)»…) por prefixo.

## 2. Ordens antigas inalteradas

- Nenhuma migração de dados nesta fase (F5 não precisou de colunas novas): `itens` e `precos_servico` são jsonb e a F0b já traduziu `modo`/`tamanho`.
- Os totais gravados (`valor` por linha) nunca são recalculados; só linhas **novas** usam a regra por opção.
- Peça `DERIVADA` com uma única opção: o preço é o mesmo de antes (entrada do serviço sem modo ou com o modo da opção única → preço específico; senão preço base). Teste `pecas_derivadas_com_uma_so_opcao_dao_o_preco_de_sempre` (3 × 900 da matriz antiga, 1,5 kg × 800 de peça em kg, 2 × 1 000 do preço base; depois de editar só o nome: origem `NOVO`, opção e matriz intactas, mesma ordem = 2 700).
- Diferença deliberada face ao pré-F5: uma peça com **várias** opções e matriz com modo deixa de usar «a primeira entrada do serviço» (era o risco de preço trocado da F0b); a entrada antiga sem modo continua a valer para qualquer opção, como no legado.

## 3. Ecrãs (frontend, `pos/lavandaria`)

- **Recepção**: depois de escolher peça e serviço aparece «Cobrar por» (só quando a peça tem mais de uma opção) com o preço de cada opção **para o serviço escolhido**; a linha mostra «(Tamanho M)», cobra o preço certo e envia `modo`/`tamanho`; a lista de peças indica «N opções». Unidade e quantidade (inteira ou em kg, com n.º de etiquetas) seguem a opção.
- **Editor de peças**: lista de opções (modo, nome do tamanho com sugestões, preço), botões «+ Opção» e «+ Tamanho», matriz **serviço × opção** («Preços específicos por serviço e opção»); a gravação lê `form.getFieldValue('opcoes_preco'/'matriz')` (armadilha do `Form.List` do antd 6) e valida com as mensagens do legado. Entradas antigas sem modo: passam para a opção única; havendo várias opções seguem como estão. A tabela mostra uma linha por opção.
- Tamanho cobrado visível em talão da OS, etiquetas, detalhe, lista de linhas por facturar/ordens e relatório (peças recebidas por tamanho).
- **Importação**: modelos **.xlsx** descarregados do servidor (`GET /api/pos/lavandaria/modelos/{pecas|servicos|precos}`, colunas nomeadas do legado e folha «Como_Preencher»; o modelo de preços inclui a coluna Opção); o próprio modelo importa-se.
- **Exportação**: «Excel (7 folhas)» no relatório (`GET /api/pos/lavandaria/relatorio/excel`: Resumo, Documentos, Meios de pagamento, Serviços, Peças, Terminais, Diário; números como números). A impressão A4/PDF do relatório continua pelo motor comum (`BotoesExportar`, com `documentoRelatorioLav`).

## 4. Matriz de lacunas — estado das linhas LAV

| F-xxx | Antes | Agora |
|---|---|---|
| F-183 opções de preço da peça | PARCIAL | **IGUAL** (opções Peça/Kg/Tamanho, «+ Opção», validações, 1.ª manda, origem NOVO) |
| F-184 preços serviço × opção | PARCIAL | **IGUAL** (matriz com modo/tamanho; sem perda na edição/importação) |
| F-187 importação Excel com Opção/Tamanho | PARCIAL | **IGUAL** (coluna Opção; simulação mostra a opção) |
| F-188 modelos .xlsx | DIFERENTE | **IGUAL** (xlsx nomeado, com guia de preenchimento) |
| F-229 Excel de 7 folhas | PARCIAL | **IGUAL** quanto ao Excel; a impressão A4 usa o motor comum (equivalente ao legado) |
| F-192 zoom/detalhes do catálogo | por confirmar | **não feito** (prioridade B, atribuída à F8; a recepção é por selecção, não por grelha) |
| F-190…F-228 (recepção, estados, orçamentos, RC-LAV, FR/FT, armazenagem, danos) | IGUAL | sem alteração; verificadas pela suite existente (`POSLavandariaTest`, 4 cenários completos) |

Não tocados: RC-LAV no SAF-T (F6), frente de caixa/talão comercial (F1), POS de armazém (F3), hotelaria (F4), mesas.

## 5. Testes

- Backend: `POSLavandariaOpcoesPrecoTest` (6 testes, valores calculados à mão: 2 × 1 140 = 2 280; 2,5 kg × 900 = 2 250; 2 × 1 710 (matriz M) = 3 420; Casal sem entrada = 2 280; seco sem entrada = 1 140; total 11 370; XL → `OPCAO_PRECO_INVALIDA`; 1,5 peças de tamanho → `QUANTIDADE_INVALIDA`; opção sem preço → `PRECO_EM_FALTA`; edição não apaga modo/tamanho; importação por opção; relatório por tamanho) e `POSLavandariaExcelTest` (modelo .xlsx e 7 folhas, permissões, o modelo volta a importar-se). Com `POS_CONTRATO=verificar`: `POSLavandaria|MigracaoLavandaria` 28 passam (inclui a F0b). Os testes que varrem todas as rotas GET (`ConsultasPorEndpointTest`, `OwaspControloAcessoTest`, `AcessoCruzadoGeradoTest`, `EntradasMaliciosasListagensTest`, `ContaRootTest`) passam com as 2 rotas novas (classificadas, 403 sem permissão, sem 5xx).
- Contrato JSON: `contrato_pos.json` actualizado de forma deliberada (peça com `opcoes_preco`/`opcoes_preco_origem`; linhas das ordens com `modo`/`tamanho`), só nas chaves de rotas de lavandaria.
- Frontend: `formularios.test.ts` (corpo com opções, validações, matriz ida-e-volta, regra de preço), `Recepcao.test.tsx` («Cobrar por»), `TabelasOpcoes.test.tsx` (editar sem tocar reenvia opções e matriz completas), `regras.test.tsx` (inalterado, passa). `tsc` limpo; `npm run build` feito (dist actualizado).

## 6. Para o coordenador

- Sem migrações novas. Sem permissões novas (`lav_tabelas` para modelos, as do relatório para o Excel).
- Antes de pôr em produção: correr `erp:lavandaria:auditar-migracao` (F0b) e, havendo o backup Dexie, `erp:lavandaria:completar-migracao` — as peças `DERIVADA` com várias opções reais só ficam correctas depois de reler `lav_prices`; uma peça editada no ecrã novo antes disso fica `NOVO` e já não é reconstruída.
- Pendente (fora do âmbito): F-192 (zoom/detalhes do catálogo) na F8.
