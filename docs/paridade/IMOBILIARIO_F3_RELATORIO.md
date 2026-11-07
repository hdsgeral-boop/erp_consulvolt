# Gestão Imobiliária — Onda 2 (F3 Contratos e rendas): relatório

Data: 2026-11-07 · ramo `paridade-bd-front` · commit local único, sem push e sem deploy. Base: `IMOBILIARIO_PLANO.md` (§2, §3, §7 F3, §8: usadas as recomendações P3, P4, P5, P7 e P9), `IMOBILIARIO_INVENTARIO.md`, `imobiliario/ANEXO_B_CONTRATOS.md` e o código legado de contratos, rendas e subarrendamento (só leitura).

## 1. O que ficou funcional

| Área | Entrega |
|---|---|
| **Contratos** | Formulário completo (tipo arrendamento, subarrendamento e tomada; âmbito uma fracção, várias ou o imóvel inteiro; encargos; actualização; fiscalidade; contas do contrato tomado), numeração atómica `AR/SB/AT AAAA/NNNN`, **conflitos de datas por fracção e lado**, activar (fracção → arrendada, com histórico), **suspender e reactivar** (novos), **renovar** (novo), **terminar/denunciar com cascata** aos subarrendamentos, actualizar renda, eliminar, trilho de eventos (`historico_contrato_imo`), ligação do contrato tomado ao bem alheio do Activos (só escreve no bem, nunca ao contrário). |
| **Rendas** | Gerar (idempotente, nunca regenera uma renda anulada), **pro-rata corrigido** no primeiro e no último período, aprovar, anular, recalcular (mantém desconto e extras), débito de encargo extraordinário ao inquilino, listagem do período só de leitura. |
| **Facturar** | Sobre `ServicoDocumentosVenda::emitir` (série, selagem AGT, hash e stock são de Vendas). **Idempotente por renda**: linha bloqueada + UNIQUE em `venda_id`; repetir devolve a factura existente. |
| **Pagar** | Sobre `ServicoDocumentosTesouraria::gravar`: pagamento pendente com D custo e C retenção; idempotente por renda; desligar o pagamento anula o documento pendente. |
| **Conta corrente e mora** | Pago, dívida, atraso e juros por renda e por contrato; totais; KPIs (renda mensal, margem, caução, dívida, a expirar); 12 tipos de avisos. |
| **Caução** | Ledger imutável (`movimentos_caucao_imo`), lançamentos equilibrados no diário IMO, repetível por `chave`, anulação com estorno, limites (seis rendas, em poder). Lado senhorio passivo; lado inquilino activo. |
| **Imposto Predial retido (P5)** | Registado uma só vez como crédito de imposto (D conta do crédito / C conta do cliente). |
| **Subarrendamento** | Margem por fracção com **mensalização**, mês a mês, cadeia mãe/filhos, regras de título (autorização, fracções cobertas, datas). |
| **Configuração** | Artigo de serviço «Renda» e contas da caução e do imposto retido por empresa; nada vem pré-preenchido. |
| **Ecrãs** | `imo_contratos` (lista, KPIs, avisos, ficha em gaveta com condições, fiscalidade, conta corrente, caução e histórico, formulário, terminar, actualizar renda, movimento da caução), `imo_rendas` (período, a receber/a pagar, marcar, aprovar, facturar, pagar, anular, recalcular, debitar, desligar, registar retenção) e `imo_sub` (margem, cadeia, mês a mês). Separador novo «Facturação e contas» em Modelos e Taxas. Responsivos (modais e gavetas adaptativos), acessíveis (botões e campos com nome, estados sempre em texto) e na pesquisa global. |

## 2. Migração nova (a ensaiar na cópia da produção)

`backend/database/migrations/2026_12_17_090000_imobiliario_onda2_contratos_rendas.php`: **só tabelas novas**, aditiva e idempotente (`hasTable`, `IF NOT EXISTS`, CHECK por `pg_constraint`), RLS (`IsolamentoRls`), GRANT a `erp_app`, `down()` sem efeito. Não exige `btree_gist` (os conflitos de datas verificam-se no serviço com as fracções bloqueadas).

| Tabela | Notas |
|---|---|
| `contratos_arrendamento` | UNIQUE `(empresa_id, lower(numero))`; CHECK de tipo/posição coerentes (TOMADA ⇒ INQUILINO), pai só no subarrendamento, `renda>0`, datas, caução (`recebida ≤ acordada`, `devolvida + retida ≤ recebida`), actualização, dia 1–28. |
| `contrato_fracoes` | UNIQUE `(contrato, fracção)`; cópia do lado e do intervalo para os conflitos. |
| `contrato_encargos` | encargos incluídos ou a acrescer. |
| `rendas_arrendamento` | **UNIQUE `(contrato, período)`**, UNIQUE parcial de `venda_id` e de `documento_tesouraria_id`, CHECK `total_facturar = base + IVA + extras` e «facturada ⇒ tem venda». |
| `historico_contrato_imo` | trilho de eventos. |
| `movimentos_caucao_imo` | UNIQUE `(empresa, chave)` (idempotência); `estado` VALIDO/ANULADO. |
| `configuracao_imobiliario` | uma linha por empresa. |

Nenhuma tabela existente é alterada (a ligação a Vendas e à Tesouraria é por colunas nas rendas, sem colunas novas em `vendas` nem `documentos_tesouraria`). Rotas em `routes/api/imobiliario_contratos.php`, incluído em `routes/api.php` a seguir ao de F0–F2.

## 3. Decisões e correcções do legado (P3: corrigir, com o legado como fixture)

- **Pro-rata**: primeiro e último período proporcionais aos dias (16 de 31 dias de 100 000 Kz = 51 612,90). O legado cobrava o período inteiro e reproduz-se com o contrato `pro_rata = false` (testado). A renda do período em que cai a saída é acertada ao terminar; a renovação acerta a do período do termo antigo.
- **Extras (obras debitadas)**: entram no total e no IVA mas não no Imposto Predial nem no Selo; um recalcular já não os perde.
- **SUSPENSO** deixa de ser estado órfão (suspender/reactivar); **renovar** existe; terminar só parte de activo ou suspenso; contrato em vigor não muda tipo, âmbito, fracções, outra parte nem início.
- **Actualização manual** da renda salta as rendas já documentadas em vez de rebentar a meio.
- **Mora** à taxa do contrato (`mora_mensal`, que o legado lia e nunca gravava) ou à do ano do vencimento (o legado usava a do ano corrente); só se calcula do lado em que a empresa cobra. O pago do inquilino só conta o documento integrado.
- **Mora e dívida** descontam a retenção do inquilino (dívida = total a receber − recebido).
- **Margem do subarrendamento** mensalizada (renda anual tomada vs subarrendamentos mensais); o cálculo do legado fica como fixture.
- **Recalcular uma renda aprovada** devolve-a a «prevista» (volta a ser aprovada: o valor aprovado já não é o que se facturaria).
- **Desligar o pagamento** anula o documento pendente da Tesouraria (o legado deixava-o órfão e pagável duas vezes); um integrado recusa-se.
- **Listagem do período** é só leitura (gerar é acção própria) e mostra as rendas cujo período cobre o mês, não só as que começam nele (o legado escondia as trimestrais fora do seu mês).
- **Facturação**: se o IVA por linha dá até 5 cêntimos de diferença para a renda, manda o documento fiscal; acima disso recusa e desfaz tudo.
- **Caução** (P4): contas configuráveis por empresa, sem códigos PGC no código. Quem movimenta a caução não termina o contrato (par do catálogo); terminar só avisa que há caução em poder. O movimento e o seu lançamento nascem na mesma transacção (em vez de job posterior), com `contabilizar` repetível e a `chave` do pedido como idempotência.
- **IP retido** (P5): crédito de imposto, a confirmar com o contabilista. **Só Kz** (P7). **Taxas** editáveis e «por validar» (P9): o ERP não afirma conformidade legal.
- **Segregação**: acrescentado o 6.º par `imo_renda_gerar × imo_renda_aprovar`. O perfil «Técnico de Rendas» passa a só gerar e o «Gestor Imobiliário» a só aprovar (os dois acumulavam o par); `ImobiliarioCadastroTest` passou de 5 para 6 pares. Catálogo editado à mão (sem regenerar do v_boss).

## 4. Fora de âmbito (documentado, não começado)

Emissão dos modelos de documentos sobre um contrato real (contrato, recibo, interpelação, carta de actualização) e PDFs; obras a debitar ao inquilino a partir de ordens de trabalho (o débito manual existe; a ligação às ordens é da F5); vagas e encargos do parqueamento (F4: os encargos com `origem = PARQUE` já têm coluna); anexos reais do contrato (`documentos` guarda-se vazio); alertas agendados e notificações (F8; os avisos são calculados à vista); exportações Excel; ETL; ensaio do selo AGT para a isenção M14 (o artigo da renda leva o motivo; a validade fiscal é do contabilista). O `valor_pendente` da factura em Vendas não desce com a retenção do inquilino: o registo da retenção fecha o saldo na conta do cliente (contabilidade), não o estado da venda. As tabelas novas não entraram no `DICIONARIO_DADOS.md` (como as da Onda 1).

## 5. Testes e verificações (resultado real, lido do output)

- **PHPUnit** (`docker exec erp_app_e2e php artisan test --filter=…`, um de cada vez): `ImobiliarioContratosRendasTest` **30 testes / 791 asserções**; `ImobiliarioCalculadorasTest` (unidade, fixtures à mão) **15 / 90**; `ImobiliarioCadastroTest` **21 / 852** e `ImobiliarioModelosTaxasTest` **9 / 148** (Onda 1, verdes).
- Números à mão: IP 25 % × 60 % de 100 000 = 15 000, selo 100, a receber 85 000; METADE 7 500; alojamento IVA 7 000; extras 30 000 com IVA 9 100; pro-rata 16/31 = 51 612,90 (IP 7 741,94) e 14/30 = 46 666,67; actualização composta 5 % a 24 meses = 110 250 e linear 120 000; mora 85 000 × 1 % × 45/30 = 1 275 (35 000 em dívida: 525 e, a 2 %, 1 050); margem 40 000/60 000/20 000 a 50,0 % (e a do legado, distorcida, para contraste); caução 200 000 = 150 000 devolvidos + 50 000 retidos.
- **Idempotência e isolamento**: gerar, facturar (1 venda), pagar (1 documento), caução (mesma chave, 2 linhas de lançamento), retenção (1 lançamento), migração duas vezes; acesso cruzado por empresa a contratos, rendas, facturação, caução e eliminação (404); 403 antes de 422 em todas as acções; restrições da base de dados (UNIQUE da renda, CHECK da caução e da renda).
- **Varredores, todos verdes**: `OwaspControloAcessoTest` (sem entradas novas em `SEM_PERMISSAO_POR_DESENHO`), `AcessoCruzadoGeradoTest`, `ConsultasPorEndpointTest`, `EntradasMaliciosasListagensTest`, `ContaRootTest`; também `FkPerfisModelosTest`, `MenuConfigContaSubscricaoTest`, `CatalogoModulosPlanoTest` e `IsolamentoRlsContratoTest`. `vendor/bin/pint --test` limpo nos ficheiros da fase.
- **Vitest** (`--maxWorkers=3`): `src/modulos/imobiliario` **40 testes** (os 19 da Onda 1 + `logicaContratos.test.ts` 10 e `Contratos.test.tsx` 11); `src/layout` (incluindo `indicePesquisa`, regenerado), `src/componentes/icones` e `semAutenticacaoNoNavegador` verdes; `npx tsc --noEmit` limpo; `npm run build` feito.

## 6. Notas para o coordenador

1. Ensaiar a migração `2026_12_17_090000` na cópia da produção (só tabelas novas).
2. A empresa tem de configurar o artigo «Renda» (IVA 0 %, com motivo de isenção) e as contas da caução e do imposto retido em Modelos e Taxas › Facturação e contas, antes de facturar ou movimentar a caução; sem isso o ERP recusa e diz o que falta.
3. Correr `PerfisActualizarModelosCommand` para propagar os perfis «Técnico de Rendas» e «Gestor Imobiliário» alterados a perfis já criados.
4. As taxas, o IP retido, a isenção M14 e a contabilização da caução ficam por validar pelo contabilista; nada disto foi verificado contra a lei.
5. Os testes backend dependem do cabeçalho de data fixa (`travelTo 2026-03-20`); a base `erp_app_e2e` foi migrada.
