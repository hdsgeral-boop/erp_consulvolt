# POS — Relatório da F6 (integrações e contabilização)

Ramo `paridade-bd-front`. Testes: `POSNcSessaoTest` (7 casos, números calculados à mão), `POSLavandariaTest::recibos_rc_lav_saem_no_saft…`, vitest `pos/contas/f6.test.tsx`; `POS_CONTRATO=verificar` verde nos testes POS; `contrato_pos.json` actualizado de propósito (ver 5).

## 1. F-xxx e I-xx fechadas

| Ref. | Estado | O que ficou |
|---|---|---|
| F-078 / Q3 | DOCUMENTADO | Mantém-se o do sistema novo: o desvio dentro da tolerância é DELIBERADO automaticamente (`automatica: true`) e lançado com a integração (D transitória / C sobra, ou D quebra / C transitória), por isso a transitória do numerário fecha a zero. O legado deixava o resíduo para sempre (BUG-21). O ecrã de Desvios já mostra «Automática» |
| Q4 | DOCUMENTADO | Troco abatido do último numerário para trás: sem efeito com um só meio de numerário. Mantém-se |
| Q5 | FEITO | Fecho Z sem o talão do TPA, como o legado: o servidor recusa por omissão (`FECHO_TPA_EM_FALTA`, com `erros.permite_sem_talao`); com `confirmar_sem_talao: true` fecha, mas a justificação é obrigatória (`JUSTIFICACAO_OBRIGATORIA`), o fecho fica com `sem_talao: true` (`valor_talao`/`diferenca` nulos) e aparece sempre no relatório «diferenças TPA». Frontend: caixa «Fechar sem o talão deste TPA» por TPA, aviso na confirmação e justificação obrigatória |
| F-112 / I-05 | FEITO | «IVA em falta»: a falta de conta de IVA por omissão passa a impedimento `IVA_EM_FALTA` com a lista de artigos (vendas e facturas da lavandaria da sessão) na pré-visualização e na integração. `POST /api/pos/integracao/iva-em-falta {conta_iva, produto_ids, definir_omissao}` (permissão `pos_integrar`) grava a conta (classe 34, de movimento) só nos artigos que ainda não a têm (categoria > artigo) e/ou como conta por omissão das contas de Vendas. Frontend: bloco amarelo na pré-visualização «Gravar X nesses artigos» |
| Q17 / I-06 | FEITO | NC sobre factura-recibo POS (ver 2) |
| F-068, Q18 / I-19 | FEITO | Pendentes e notificações do POS (ver 3) |
| F-106 / I-20 | FEITO | KPIs do POS no painel de Vendas (ver 3) |
| I-02 / Q16 | FEITO | Recibos RC-LAV no SAF-T (ver 4) |
| I-01, I-03, I-04, I-07…I-12, I-14…I-18, I-21…I-24 | SEM ALTERAÇÃO | Já estavam Completa/Completa+/Paridade (a F1 fechou o QR do talão de I-02; a F3 as impressões de I-04). I-13 (moedas) continua «Diferente» por decisão: o POS é só Kz e o numerário POS para folha em moeda é recusado (corrige BUG-12). I-24: a nota 10 já é imposta nas linhas 45 da Folha de Caixa |

## 2. NC sobre factura-recibo de sessão POS (Q17)

Nova tabela `ncs_sessao_pos` (uma linha por NC com o valor repartido pelos pagamentos da FR, em proporção, a última parcela com o arredondamento) e `ServicoNcSessaoPOS`, chamado pela emissão da NC (`ServicoDocumentosVenda::emitir`):

- **Sessão ABERTA**: `ServicoSessoesPOS::totais` abate a NC (total de vendas, por meio, numerário, transferências); relatório X e Z já saem líquidos; o numerário esperado desce (o dinheiro devolvido sai da gaveta). Na integração lança-se **D conta do cliente da NC / C transitória de cada meio**.
- **Sessão FECHADA por integrar**: o instantâneo do Z (`totais_por_metodo`, `transferencias`, `total_vendas`, `vendas_numerario`) é ajustado na hora (contagem e desvio do fecho não mudam); o estorno lança-se na integração.
- **Sessão INTEGRADA**: exige a nova permissão sensível `pos_nc_estornar` («Estornar notas de crédito de sessões integradas», grupo Integração; **não** está no perfil «Operador POS»; só no perfil contabilístico onde está `pos_integrar`). Sem ela: `NC_SESSAO_INTEGRADA`. Se a sessão já tem prestação de contas registada: `NC_SESSAO_COM_PRESTACAO` (anule a prestação primeiro, porque a transitória já foi regularizada). Com permissão: ajusta o instantâneo, lança já o estorno (guardado em `lans_contabilizacao`) e o item a prestar desce.
- **Descontabilizar** a sessão estorna também estes lançamentos e as NC voltam a «pendentes»; reintegrar não duplica (testado: saldos iguais).
- Prova (teste): FR 1 140 = 640 numerário + 500 TPA; segunda FR 1 140 numerário; NC da primeira ⇒ transitória numerário 1 780 − 640 = 1 140, TPA 500 − 500 = 0, lançamentos equilibrados, item de numerário a prestar 1 140, sem item TPA.
- A NC em si continua a ser contabilizada pela facturação (D proveitos e IVA / C cliente); o estorno (D cliente / C transitória) anula o saldo do cliente na conta 31. Se a NC nunca for contabilizada nas Vendas, o cliente fica com um débito: a integração de Vendas deve ser feita.
- A NC em sessão aberta ou fechada não integrada continua permitida (ADR-071).

## 3. Pendentes, notificações e KPIs (Q18)

- `ServicoInicio::pendentes` (que alimenta também o sino de notificações como alertas operacionais, `ServicoNotificacoes`) ganhou, por permissão do utilizador (nunca vê quem não tem o ecrã): `pos_sessoes_antigas` (aberta desde dias anteriores; ecrã `pos`), `pos_desvios` (desvio acima da tolerância por deliberar; `pos_desvios`), `pos_por_integrar` (`pos_integracao`), `pos_por_liquidar` (`pos_prestacao`) e `pos_fe_erros` (vendas POS com `fe_estado = COM_ERROS`; `vendas_faturacao`). Desvios, sessões antigas e FE com erros são «URGENTE»; ligam ao ecrã respectivo (`/m/pos/...`). As FE recusadas pela AGT e o stock crítico já tinham alerta (`vendas_agt_recusados`, `stock_critico`).
- `IndicadoresPOS` no painel de Vendas: vendas de hoje líquidas de devoluções (NC sobre FR POS), sessões abertas, desvios por deliberar, sessões por integrar e por liquidar, e a tabela «vendas de hoje por meio». Cada grupo só aparece com a vista correspondente. A chave de cache do painel passou a incluir as vistas POS do utilizador (senão os KPIs vazavam entre perfis durante 120 s).
- Não se criaram notificações persistentes por utilizador: os alertas são calculados e filtrados por permissão a cada pedido, o que dá o mesmo efeito sem tabela nova nem destinatários desactualizados.

## 4. SAF-T: recibos RC-LAV (Q16)

`ServicoSaftRecibosLavandaria` + `ServicoExportacaoSaft`: os `pagamentos_lavandaria` ADIANTAMENTO/PAGAMENTO (não anulados) saem em `Payments` como `RG`, com `PaymentRefNo` derivado e sem alterar o n.º gravado: `RC-LAV/T01/2026/00001` ⇒ `RG LAVT012026/1` (cumpre o padrão `^[^ ]+ [^/ ]+/[0-9]+$` de `RegrasNumeracaoAgt`; aviso se houver repetidos). Um `PaymentMethod` por meio usado (NU/CD/TB, valor líquido de troco). As linhas repartem o recibo pelas FT da ordem das mais antigas para as mais recentes (a mesma regra de `actualizarFaturas`; a FR já nasce paga e não é referenciada); o excedente é adiantamento: linha sem `SourceDocumentID`. `NumberOfEntries`/`TotalCredit` incluem os dois tipos de recibo; os clientes dos recibos entram em `MasterFiles`; `validar` conta-os. O repositório não tem o XSD nem um verificador local do ficheiro (a skill `verificar-saft-agt` só consulta a submissão no Portal do Contribuinte); a validação feita foi estrutural no teste (XML bem formado, padrão do n.º, totais, ligação à FT). **Falta** validar o ficheiro no validador da AGT antes da certificação, como já avisa a documentação do serviço.

## 5. Migrações novas (ensaiar na cópia local antes da produção)

- `2026_12_11_090000_pos_notas_credito_sessao.php`: cria `ncs_sessao_pos` (idempotente, CHECK de estado e valor, FKs, UNIQUE por NC, índice, GRANT e RLS por `IsolamentoRls::aplicarATodas`). Aditiva: não altera tabelas existentes; as sessões e NC antigas não mudam (não há retroactivos: as NC já emitidas sobre FR POS não entram nesta tabela).
- Sem migração para as restantes alterações (Q5, IVA em falta, notificações, KPIs, SAF-T).
- Permissão nova `pos_nc_estornar` em `catalogo.json` (à mão; também no perfil contabilístico ao lado de `pos_integrar`).
- `contrato_pos.json` actualizado de propósito (união das formas): `fechos_tpa[]` com `valor_talao/diferenca/operacoes_talao` nulos e `sem_talao`; `pre-visualizacao` com `iva_em_falta`; `POST pos/integracao/iva-em-falta`; formas já usadas pelos testes novos (`vendas-consulta`, `prestacao`).

## 6. Decisões

- NC com estorno só com permissão própria (`pos_nc_estornar`), não com `pos_integrar`, porque mexe numa sessão já contabilizada.
- Com prestação de contas registada a NC é recusada (não se estorna uma transitória já saldada): o utilizador anula a prestação, emite a NC e presta de novo.
- Fecho sem talão do TPA: continua a exigir a confirmação explícita no pedido (`confirmar_sem_talao`) para a API não fechar sem querer.
- «IVA em falta»: só se grava nos artigos sem conta de IVA em nenhuma origem; a conta por omissão grava-se nas contas de Vendas (partilhada com a facturação, como o `contaIvaLiquidadoPorOmissao` do legado era comum).

## 7. O que falta

- Validar o SAF-T com RC-LAV no validador da AGT (não há XSD no repositório).
- F9: percorrer a cadeia completa venda → NC → Z → integração → prestação com a transitória a zero, incluindo a prestação do item após NC e a anulação de prestação para emitir NC (aqui só se testou até ao item a prestar).
- NC já emitidas antes desta fase sobre FR de sessões integradas não têm registo em `ncs_sessao_pos`: se existirem, regularizam-se à mão (descontabilizar/integrar de novo não as inclui).
- Stock mínimo no POS: reutiliza-se o alerta global `stock_critico`; não há alerta por armazém do terminal.
- Frontend: o atalho «Emitir NC» continua a decidir pelo servidor (`nc_permitida`); não há vitest do bloco «Fechar sem o talão do TPA» (só a verificação de tipos e a suite existente do POS).
- Nota de processo: durante a verificação final lancei por engano um `artisan test` enquanto outro agente tinha uma suite em curso (o `ps` já o mostrava); as falhas `QueryException` resultantes são colisões do `RefreshDatabase`, não regressões; os testes foram repetidos sozinhos.
