# POS — Matriz de lacunas, interligações, bugs e decisões

Análise só de leitura, feita em 2026-11-06 sobre o ramo `paridade-bd-front` (HEAD `5903db6`). Compara o POS do legado (`v_boss/payroll_system_web/js`) com o POS do sistema novo (`backend/app`, `frontend/src/modulos/pos`, `frontend/src/modulos/stock/posArmazem`). Base: `POS_LEGADO_INVENTARIO.md` (F-001…F-247, R-001…R-050, I-01…I-24) e `POS_ACTUAL_INVENTARIO.md` (N-001…N-153, D-01…D-15), com confirmação no código nos pontos duvidosos (secção 2). O plano de fases e as perguntas ao utilizador estão em `POS_PLANO_RECONSTRUCAO.md`.

**Legenda do estado**: **IGUAL** (mesmo comportamento, regras e mensagens, salvo nota) · **PARCIAL** (existe mas falta parte) · **DIFERENTE** (existe, comportamento diferente, quase sempre por decisão ou correcção; a coluna «diferença» diz qual) · **EM FALTA** (não existe) · **NÃO PORTAR** (decisão registada de não portar). **Esforço** S (≤ 1 dia de agente), M (2–4 dias), L (≥ 1 semana). **Risco** B/M/A (baixo/médio/alto: fiscal, dados de produção ou regressão). **Dependências**: fase do plano (F0…F9), `Q<n>` = pergunta ao utilizador, «Tesouraria» = módulo fora do POS.

**Sub-módulos**: ACE acesso e abertura · FRE frente de venda · RES restaurante · CHK checkout e emissão · FEC fecho de caixa · TER terminais e definições · REL relatórios, impressão e fluxos · INT integração contabilística · DES desvios · PRE prestação de contas · ARM POS de armazém · HOT hotelaria · LAV lavandaria · FCX Folha de Caixa (módulo Tesouraria, listada porque o legado a inventariou como parte do POS).

## Contagens por estado

| Estado | N.º | % |
|---|---:|---:|
| IGUAL | 177 | 71.7% |
| PARCIAL | 13 | 5.3% |
| DIFERENTE | 46 | 18.6% |
| EM FALTA | 7 | 2.8% |
| NÃO PORTAR | 4 | 1.6% |
| **Total** | **247** | 100% |

| Sub-módulo | IGUAL | PARCIAL | DIFERENTE | EM FALTA | NÃO PORTAR | Total |
|---|---:|---:|---:|---:|---:|---:|
| ACE | 11 | 1 | 3 | 0 | 0 | 15 |
| FRE | 18 | 1 | 6 | 2 | 0 | 27 |
| RES | 3 | 0 | 5 | 0 | 0 | 8 |
| CHK | 15 | 2 | 6 | 1 | 0 | 24 |
| FEC | 11 | 0 | 3 | 0 | 0 | 14 |
| TER | 12 | 0 | 0 | 0 | 0 | 12 |
| REL | 4 | 0 | 2 | 1 | 0 | 7 |
| INT | 5 | 0 | 4 | 1 | 1 | 11 |
| DES | 5 | 0 | 1 | 0 | 0 | 6 |
| PRE | 10 | 0 | 2 | 0 | 0 | 12 |
| ARM | 9 | 4 | 7 | 2 | 2 | 24 |
| HOT | 19 | 0 | 2 | 0 | 0 | 21 |
| LAV | 46 | 4 | 1 | 0 | 0 | 51 |
| FCX | 9 | 1 | 4 | 0 | 1 | 15 |


## 1. Conclusão em poucas linhas

1. O POS novo cobre em largura quase todo o legado: **176 (71%) das 247 funcionalidades estão IGUAIS** e a maior parte das restantes são DIFERENTES por decisão consciente (correcções), não por falta.
2. As faltas reais concentram-se em: (a) **lavandaria — opções de preço por peça/kg/tamanho** (e a migração que perde esses dados); (b) **talão sem QR AGT, sem IBAN e sem «Processado por computador»**; (c) **POS de armazém sem impressão da guia nem talão de levantamento**; (d) pequenas faltas de UX da frente de caixa (zoom, arrastar, pílulas por meio, produtos bloqueados visíveis); (e) painel de módulos sem KPIs POS e atalho «IVA em falta».
3. O legado **não tem** devolução, anulação, sangria, suprimento, gorjeta, divisão de conta, transferência de mesa nem reabertura de caixa (confirmado por pesquisa no código: nenhuma ocorrência em `ui_sales.js`, `pos_gestao.js`, `pos_prestacao.js`, `ui_pos_armazem.js`, `hotelaria.js`; a «devolução» do legado é a NC no módulo Vendas). O pedido «F1: devolução/consulta, suprimento/sangria» do plano é, portanto, **evolução e não paridade**: fica como decisão (Q6).
4. A dívida técnica relevante para a reconstrução é de integridade da BD (UNIQUE/CHECK/PK em pivôs) e de padrões (FormRequests/Resources) — F0.

## 2. Verificações complementares (buracos do inventário do legado, completados com leitura do código)

| Tema | Resultado da leitura | Consequência para a reconstrução |
|---|---|---|
| `generateGlobalLanNumber` (`app_v2.js:3-22`) | Nº do lançamento = `<código do diário><ano da data do documento><sequencial de 6 dígitos>` (ex.: `GEPOS2026000001`); procura o máximo nas linhas do diário e soma 1 — não atómico. Mesma regra em `ui_tesouraria.js` | O novo (`ServicoLancamentos::proximoNumeroLan`, `str_pad 6`) já tem o mesmo formato. Garantir lock por diário/ano em integrações concorrentes (teste F9) |
| `nucleo_partilhado.js` | Só 97 linhas: `ProjectAPI`, `renderProjectSelector`, `showErroModal`, `showSucessoModal`. **`Dialogo`, `ContaTerceiro` e `ImportMestre` NÃO estão aqui**: vivem em `shared/ui.js:219` (`confirmar`, `pedir`, `escolher`), `shared/contas_terceiro.js:139` e `shared/import_mestre.js:49` | Corrige o inventário do legado (§10, ponto 2). `Dialogo.pedir` = prompt com validação (motivos ≥ 3 caracteres); o novo usa modais antd |
| Integração da Tesouraria (`ui_tesouraria.js:1494-1587`, `integratetreasuryDoc`) | Documento `PENDENTE` → valida equilíbrio (Σ itens com sinal = total do documento), diário `BD` para contas 42/43 e `CX` para 45 (`tesoDiarioDaConta`), nota 10 na linha do banco, **um** `lan_number` por documento, linha do banco + uma linha por item, `status INTEGRADO`; desintegrar volta a `PENDENTE` | O recebimento do POS (TPA/transferência: C transitória, D banco) é contabilizado aqui, no diário de bancos, depois da prestação. O novo faz o mesmo na Tesouraria (`ServicoDocumentosTesouraria`); a ligação POS→Tesouraria está IGUAL |
| Geração do JSON AGT / selagem (`facturacao_agt.js:497-535`) | `selar` gera `documents[]` de `registarFactura`, valida (E03 imposto, L01…), guarda `fe_documento/fe_erros/fe_estado`, **alinha** `total_net/tax/gross` da venda aos da AGT; imposto por excesso ao cêntimo (`excessoCentimo`). **Não há assinatura RSA no legado** | O novo já tem selagem + hash + QR no servidor (`ServicoQrAgt`, `FaturacaoEletronicaController`); é mais completo. Equivalente da regra de arredondamento: `arredondamento_agt` |
| QR (`facturacao_agt_qr.js`) | QR = URL de consulta `https://quiosqueagt.minfin.gov.ao/facturacao-eletronica/consultar-fe?emissor=<NIF>&document=<documentNo>`, modelo 2, correcção M, 350×350, logótipo AGT. **Não há ATCUD** (conceito português; Angola não o usa) | O pedido «talão com hash/QR/ATCUD» reduz-se a **QR AGT + número do documento** (F-071). O servidor já devolve a imagem em `GET /api/vendas/documentos/{venda}/qr` |
| SAF-T do legado (`ui_sales.js:10038+`, `:10600-10661`) | Hash por `sha1(data;entrada;nº;total;hash anterior)` (não conforme), `SettlementAmount 0.00` fixo, pagamentos da FR repartidos por `getSalePaymentSplits` a partir dos documentos de tesouraria | O novo é mais correcto (assinatura, `SettlementAmount` real — ADR-067). Recibos RC-LAV continuam fora do SAF-T (ADR-049): lacuna real |
| `printInvoice` / A4 (`print_docs.js`, `shared/documento_comercial.js`) | `print_docs.js` só imprime documentos de compras/logística; o A4 de vendas usa `window.DocumentoComercial` (modelo comum). Há 73 linhas, sem lógica própria | O novo usa o motor comum (`vendas/impressao/documentoComercial.ts`), incluindo QR nos A4 de Vendas. Para o POS só falta o talão e o A4 a partir do modal de sucesso (F-070…F-072) |
| `ui_folha_caixa.js` 1030-1240 e 1610-2020 | 1027-1297: `showPagarFaturas/showReceberFaturas` listam documentos em aberto derivados do **razão** (saldo por terceiro+conta+documento, `_getOpenInvoicesFromAccounting`, aberto se saldo > 0,01), filtro, valor editável, geram PAG/REC com `source_type 'contabilidade'`. 1602-1740: `imprimirFolhaCaixa` (A4). 1808-2020: importação Excel (modelo, validação por linha) | O novo cobre-os na Tesouraria (`ServicoPendentes::abertos`, `ModalLiquidarFacturas`, `ModalImportacaoCaixa`). Diferenças documentadas em F-237, F-245…F-247 |
| Sem sangria/suprimento/gorjeta/devolução | Pesquisa por `sangria|suprimento|gorjeta|devolu|estorn` nos ficheiros do POS: só aparecem «Guia de Devolução» e NC em `ui_sales.js` (Vendas) | Confirmado: não são paridade (Q6) |
| Lavandaria: opções de preço | Legado: `lav_pieces.lav_prices[] {mode PECA/KG/TAMANHO, size, price}` e `service_prices[] {product_id, mode, size, price}`, linha com `size`. Novo: `pecas_lavandaria` com `unidade` e `preco` únicos e `precos_servico {produto_id, preco}`; `ServicoMigracaoLavandaria` não traduz `lav_prices`, `mode` nem `size` | **Lacuna e risco de perda de dados** (F-183, F-184): corrigir o ETL antes de qualquer nova carga |

## 3. Matriz de paridade (uma linha por F-xxx)

| F | Sub | Funcionalidade | Estado | N-xxx | Diferença concreta | Esf. | Risco | Dep. |
|---|---|---|---|---|---|---|---|---|
| F-001 | ACE | Vista POS com 7 separadores + Lavandaria | IGUAL | N-151, N-152 | Hotelaria tem separador próprio (no legado é só tipo de terminal); sem alteração necessária | — | — | — |
| F-002 | ACE | Cartão «Frente de Caixa Imersiva» / «Iniciar Ponto de Venda» | IGUAL | N-031 | — | — | — | — |
| F-003 | ACE | Modo imersivo e «Voltar ao Office ERP» | IGUAL | N-031 | — | — | — | — |
| F-004 | ACE | Aviso de saída com caixa aberta | IGUAL | N-029 | — | — | — | — |
| F-005 | ACE | Selector PC/Tablet/Mobile persistido | IGUAL | N-031, N-048 | Persistência por utilizador (legado: por posto) | — | — | — |
| F-006 | ACE | Selecção do terminal na abertura | IGUAL | N-032 | — | — | — | — |
| F-007 | ACE | Trocar terminal activo | IGUAL | N-030 | — | — | — | — |
| F-008 | ACE | Fundo de maneio pré-preenchido com o do terminal | IGUAL | N-016, N-032 | — | — | — | — |
| F-009 | ACE | Bloco informativo na abertura (operador, série, meios) | PARCIAL | N-032 | Falta mostrar a série FR do terminal e a lista de meios activos com aviso vermelho «nenhum meio com conta transitória» (a série só aparece em Terminais) | S | B | F1 |
| F-010 | ACE | Bloqueio da abertura sem terminal/meios com transitória | IGUAL | N-017 | — | — | — | — |
| F-011 | ACE | Abrir: fundo ≥ 0 e sessão já aberta no terminal | DIFERENTE | N-016 | Sessão já aberta no terminal: o novo continua-a (legado: erro + sincroniza); índice único parcial na BD garante 1 sessão aberta | — | B | — |
| F-012 | ACE | Sessão `<TERM>-<ANO>-NNNN` com contador atómico | DIFERENTE | N-018 | Contador no ServicoNumeracao (continua o do legado) mas SEM UNIQUE na BD (D-01) | S | M | F0 |
| F-013 | ACE | Aviso de sessões abertas de dias anteriores | DIFERENTE | N-019 | Novo cobre todos os terminais e operadores (legado igual); mensagem textual a conferir | — | B | — |
| F-014 | ACE | Terminal activo guardado por empresa/posto | IGUAL | N-029 | — | — | — | — |
| F-015 | ACE | Migração de terminais antigos do localStorage (T01…) | IGUAL | N-149 | Feito por ETL (fora de runtime) | — | — | — |
| F-016 | FRE | Crachá de modo e do terminal | IGUAL | N-032 | — | — | — | — |
| F-017 | FRE | Widget de saldo (Fundo, cada meio, Vendas) | PARCIAL | N-021 | Só pílulas Fundo e Vendas; faltam as pílulas por meio de pagamento com cores por tipo (numerário #059669, TPA #2563eb, transferência #7c3aed) | S | B | F1 |
| F-018 | FRE | Botão «Relatório X» + #sessão/operador | IGUAL | N-021 | — | — | — | — |
| F-019 | FRE | Selector de cliente com «Nome (NIF)» | IGUAL | N-049 | — | — | — | — |
| F-020 | FRE | Cliente por omissão «Consumidor Final» | DIFERENTE | N-040 | NIF 999999999 (legado 99999999/999999999) e criado com conta classe 31 (ADR-071); aceitável | — | B | — |
| F-021 | FRE | Criar cliente rápido no POS | IGUAL | N-050 | — | — | — | — |
| F-022 | FRE | Validação da conta do cliente (classe 31) | IGUAL | N-050 | Novo valida sempre (o checkout do legado novo deixou de validar: BUG-09) | — | — | — |
| F-023 | FRE | Pesquisa por nome ou código | IGUAL | N-043 | Novo ignora acentos e lê código de barras (extra) | — | — | — |
| F-024 | FRE | Categorias com contagem e ícone por nome | IGUAL | N-043 | — | — | — | — |
| F-025 | FRE | Filtro Todos/Com stock/Sem stock persistente | IGUAL | N-043, N-048 | — | — | — | — |
| F-026 | FRE | Vista grelha/lista persistente | IGUAL | N-043, N-048 | — | — | — | — |
| F-027 | FRE | Zoom dos cartões da grelha (pos_vendas 110–300) | EM FALTA | — | Não existe: grelha fixa `minmax(170px,1fr)`; sem botões −/+ nem persistência | S | B | F8 |
| F-028 | FRE | Ocultar imagens | IGUAL | N-043, N-048 | — | — | — | — |
| F-029 | FRE | Cartão de produto (imagem/ícone, nome, código, preço, crachá) | IGUAL | N-043, N-045 | Imagens por URL autenticado/R2 (legado base64) | — | — | — |
| F-030 | FRE | Bloqueados/sem stock a 65% e não clicáveis | DIFERENTE | N-013, N-043 | Produtos bloqueados nem aparecem no catálogo (legado mostra com crachá «Bloqueado»); sem stock fica acessível mas o servidor recusa | S | B | F1 |
| F-031 | FRE | Adicionar ao carrinho por clique | IGUAL | N-043 | — | — | — | — |
| F-032 | FRE | Adicionar ao carrinho por arrastar e largar | EM FALTA | — | Não existe (nenhum `draggable`/`onDrop` em modulos/pos); zona de largada tracejada ausente | S | B | F8 |
| F-033 | FRE | Mensagem «artigo bloqueado para venda» | DIFERENTE | N-013 | Sem mensagem: o produto não é listado; pedido manual é recusado pelo catálogo do terminal | S | B | F1 |
| F-034 | FRE | Mensagem «fora de stock» | DIFERENTE | N-039 | Validação no servidor com «Stock insuficiente de X no armazém do terminal (disponível …)»; textos diferentes do legado | S | B | F1 |
| F-035 | FRE | Limite de quantidade pelo stock (adicionar/+/campo) | DIFERENTE | N-039, N-046 | Limite pelo stock do ARMAZÉM do terminal (legado: stock global, BUG-10); confirmar se o cliente limita +/campo antes do servidor | S | B | F1 |
| F-036 | FRE | Quantidade ≤ 0 remove a linha | IGUAL | N-034 | — | — | — | — |
| F-037 | FRE | Preço unitário editável por linha | IGUAL | N-035, N-038 | Exige pos_desconto (legado lista a tarefa mas o código não a aplica a updatePOSCartPrice); preço > 2 casas recusado | — | — | — |
| F-038 | FRE | Remover linha / Limpar carrinho | IGUAL | N-034 | — | — | — | — |
| F-039 | FRE | Desconto global % (0–100) | IGUAL | N-034, N-035 | 4 casas decimais; exige pos_desconto | — | — | — |
| F-040 | FRE | Resumo Subtotal líquido/IVA/Desconto/Total | DIFERENTE | N-034, N-042 | Base e IVA já reduzidos pelo desconto (regra AGT + arredondamento_agt); no legado net/tax ficam antes do desconto (BUG-02, R-002) | — | M | DECISÃO Q2 |
| F-041 | FRE | Quartos escondidos fora de hotelaria | IGUAL | N-013 | — | — | — | — |
| F-042 | FRE | Carrinho vazio com mensagem | IGUAL | N-043 | — | — | — | — |
| F-043 | RES | Mapa de mesas Livre/Ocupado com total | IGUAL | N-060 | — | — | — | — |
| F-044 | RES | Mesas por omissão (Balcao, Mesa 1–8, Terrasso 1–2, Take-Away) | DIFERENTE | N-054, N-055 | Sem mesas de origem: criam-se por terminal (criação em lote existe); «Balcão» = venda sem mesa | S | B | F2 / Q9 |
| F-045 | RES | Criar mesa (nome único) | DIFERENTE | N-054, N-062 | Mesa na BD por terminal, nome único por terminal (legado: localStorage global); sem artefacto «???» | — | — | — |
| F-046 | RES | Carrinho por mesa persistido | DIFERENTE | N-056 | Conta da mesa no servidor, partilhada entre postos (melhoria sobre localStorage) | — | — | — |
| F-047 | RES | Trocar de mesa guarda e restaura | DIFERENTE | N-056, N-057 | Bloqueio optimista por versão (409 CONTA_MESA_ALTERADA) — extra | — | — | — |
| F-048 | RES | Suspender mesa («Conta Suspensa») | DIFERENTE | N-056 | Grava a conta no servidor; confirmar texto/modal «Conta Suspensa / Os consumos da mesa foram registados e guardados com sucesso.» | S | B | F2 |
| F-049 | RES | Consulta de mesa (conta provisória, preview/directo) | IGUAL | N-061, N-052 | Talão mostra o operador real (legado: «CAIXA POS», BUG-01); botões Pagar/Checkout da pré-visualização por confirmar | S | B | F2 |
| F-050 | RES | Mesa gravada em sales.table_name | IGUAL | N-059 | — | — | — | — |
| F-051 | CHK | Modal «Finalizar venda» (total, terminal, sessão) | IGUAL | N-036 | — | — | — | — |
| F-052 | CHK | Um campo por meio activo do terminal | IGUAL | N-036 | — | — | — | — |
| F-053 | CHK | Botão «Tudo» por meio | IGUAL | N-036 | — | — | — | — |
| F-054 | CHK | Referência TPA (opcional) / transferência (obrigatória) | IGUAL | N-037 | — | — | — | — |
| F-055 | CHK | Pagamentos mistos | IGUAL | N-036 | Máximo 50 pagamentos | — | — | — |
| F-056 | CHK | Troco só em numerário | DIFERENTE | N-036 | Troco abatido do último numerário para trás (legado: por ordem das linhas); iguais com um só meio numerário (BUG-16) | — | B | Q4 |
| F-057 | CHK | «Falta cobrar X» | IGUAL | N-036 | — | — | — | — |
| F-058 | CHK | TPA/transferências não excedem o total | IGUAL | N-036 | — | — | — | — |
| F-059 | CHK | Valores negativos recusados | IGUAL | N-036 | — | — | — | — |
| F-060 | CHK | Checkout bloqueado (vazio/caixa fechada/sem meios) | IGUAL | N-033 | — | — | — | — |
| F-061 | CHK | Revalidar sessão ABERTA antes de emitir | IGUAL | N-033 | Lock da sessão na transacção | — | — | — |
| F-062 | CHK | FR com série do terminal (AGT/local/FR-POS) | DIFERENTE | N-033, N-012 | Só série AGT por terminal (`FR T01AAAA/n`), criada na 1.ª venda; sem fallback `FR-POS/<cód>/<ano>/<n>` (decisão de não portar) | — | M | — |
| F-063 | CHK | Libertar o número se a gravação falhar | DIFERENTE | N-033 | Transacção (rollback) em vez de reserva+libertar | — | — | — |
| F-064 | CHK | Gravação da venda (pagamentos, troco, operador, terminal, sessão, UN, CC) | DIFERENTE | N-033, N-041, N-042 | Colunas portuguesas; meio do documento = tipo único ou `MISTO` (legado «Misto (A + B)»); operador = nome completo; `desconto` = bruto − total + `arredondamento_agt` | — | B | — |
| F-065 | CHK | Linhas da venda (qtd, preço c/IVA, IVA, total) | IGUAL | N-033 | Preço base a 6 casas | — | — | — |
| F-066 | CHK | Baixa de stock global e do armazém do terminal | DIFERENTE | N-039 | Stock por armazém com lock; global derivado pelo motor de stock (legado: max(0) global + armazém negativo) | — | M | — |
| F-067 | CHK | Movimento SAÍDA «Venda POS: <doc>» | DIFERENTE | N-039 | Movimento ao custo médio + CMV D71/C26 na integração (legado: preço de VENDA e sem CMV, BUG-10) | — | M | — |
| F-068 | CHK | Selagem FE e aviso de erros AGT | PARCIAL | N-033 | Selagem no motor de Vendas; falta confirmar o aviso ao operador quando o documento fica com erros AGT | S | M | F1 / F6 |
| F-069 | CHK | Impressão automática (térmico ou A4) | IGUAL | N-051, N-052 | Configuração no navegador (ver F-104) | — | — | — |
| F-070 | CHK | Talão térmico completo (QR AGT, coordenadas bancárias, NIF cliente, «Processado por computador») | PARCIAL | N-051 | Falta: QR AGT, IBAN/SWIFT dos meios, «Talão nº», NIF cliente 999999999 por omissão, linha «Processado por computador»; tem base, IVA, desconto, arredondamento, troco | M | M | F1 |
| F-071 | CHK | QR code AGT no talão de documentos selados | EM FALTA | — | Backend tem `GET /api/vendas/documentos/{venda}/qr` (ServicoQrAgt) mas o talão do POS não o usa (impressao.ts:144-179). ATCUD não se aplica a Angola | M | M | F1 |
| F-072 | CHK | Modal de sucesso com «Imprimir A4» e «Descarregar PDF» | IGUAL | N-051 | **F7**: «Imprimir A4 / PDF» (navegador) e «Descarregar PDF» gerado no servidor (dompdf, A4, com QR e IBAN); o talão térmico não tem PDF (ESC/POS fora de âmbito) | — | — | F7 |
| F-073 | CHK | Limpeza do carrinho/mesa após venda | IGUAL | N-059 | — | — | — | — |
| F-074 | CHK | Contabilização individual de documentos POS bloqueada | IGUAL | N-147 | Código VENDA_POS | — | — | — |
| F-075 | FEC | Relatório X (parcial) | IGUAL | N-021 | Térmico ou A4 | — | — | — |
| F-076 | FEC | Modal de fecho com vendas por meio | IGUAL | N-022 | — | — | — | — |
| F-077 | FEC | Contagem de numerário por denominações | DIFERENTE | N-022 | 12 denominações (inclui 2 Kz); legado 11; aceita total indicado | — | B | Q (trivial) |
| F-078 | FEC | Esperado/contado/desvio com tolerância | DIFERENTE | N-024, N-025, N-120 | Desvio dentro da tolerância é DELIBERADO automaticamente e lançado (legado: SEM_DESVIO e ignorado: a transitória nunca zera, BUG-21) | — | A | DECISÃO Q3 |
| F-079 | FEC | Fecho do TPA (talão, operações, lote, diferença) | IGUAL | N-023 | — | — | — | — |
| F-080 | FEC | Lista de transferências recebidas no fecho | IGUAL | N-022 | Por confirmar no ecrã FechoZ | — | B | F9 |
| F-081 | FEC | Justificação obrigatória | IGUAL | N-024 | — | — | — | — |
| F-082 | FEC | Talão de TPA em falta | DIFERENTE | N-023 | Servidor recusa (FECHO_TPA_EM_FALTA); legado só pedia confirmação «Fechar mesmo assim?» | — | M | DECISÃO Q5 |
| F-083 | FEC | Confirmação final do fecho | IGUAL | N-022 | — | — | — | — |
| F-084 | FEC | Z `Z-<TERM>-<ANO>-NNNN` | IGUAL | N-026 | Sem UNIQUE na BD (D-01) | S | M | F0 |
| F-085 | FEC | Estados de contabilização/prestação PENDENTE/SEM_MOVIMENTO | IGUAL | N-026 | — | — | — | — |
| F-086 | FEC | Impressão do Z 78 mm | IGUAL | N-021, N-028 | — | — | — | — |
| F-087 | FEC | Reimpressão do Z | IGUAL | N-028 | — | — | — | — |
| F-088 | FEC | Sessão fechada não aceita vendas | IGUAL | N-033 | — | — | — | — |
| F-089 | TER | Lista de terminais | IGUAL | N-012 | — | — | — | — |
| F-090 | TER | Criar/editar terminal | IGUAL | N-001, N-003 | — | — | — | — |
| F-091 | TER | Código bloqueado após emitir documentos | IGUAL | N-002 | — | — | — | — |
| F-092 | TER | Regras de hotelaria no terminal | IGUAL | N-009 | — | — | — | — |
| F-093 | TER | Meios por terminal (Numerário/TPA/Transferência) | IGUAL | N-004, N-005 | — | — | — | — |
| F-094 | TER | Transitória e liquidação com filtros 45/43 | IGUAL | N-004 | — | — | — | — |
| F-095 | TER | TPA: n.º, conta/comissão/tratamento | IGUAL | N-004 | — | — | — | — |
| F-096 | TER | Adicionar/remover/activar meio | IGUAL | N-004 | — | — | — | — |
| F-097 | TER | Copiar meios de outro terminal | IGUAL | N-006 | — | — | — | — |
| F-098 | TER | Validação das contas dos meios | IGUAL | N-004 | — | — | — | — |
| F-099 | TER | Activar/desactivar terminal | IGUAL | N-007 | Eliminar terminal (extra, N-008) | — | — | — |
| F-100 | TER | Definições de caixa | IGUAL | N-011 | — | — | — | — |
| F-101 | REL | Relatórios POS: KPIs | DIFERENTE | N-133 | KPIs mais amplos (por integrar, por prestar, diferenças TPA) e filtro por operador | — | B | — |
| F-102 | REL | Relatórios POS: por meio | IGUAL | N-134 | — | — | — | — |
| F-103 | REL | Relatórios POS: lista de Z com estado e reimpressão | IGUAL | N-134, N-028 | — | — | — | — |
| F-104 | REL | Config. Impressão (formato, consulta, auto, rodapé) | DIFERENTE | N-052 | **F7**: guardada no servidor por empresa, com sobreposição por terminal (legado: só `localStorage` por empresa); o navegador fica com a cópia e oferece «Guardar no servidor» a quem só tinha a local; acrescenta largura 58/80 mm | — | — | F7 |
| F-105 | REL | Fluxo de Processos POS | IGUAL | N-150 | — | — | — | — |
| F-106 | REL | Painel de módulos: pendências e vendas do dia | EM FALTA | N-148 | `Gestao/Paineis/Indicadores.php` não tem KPIs POS (só validações de dados); falta «POS — Sessões abertas / Vendas de hoje» e pendências | M | B | F6 |
| F-107 | REL | Relatórios de gestão «POS e Serviços» | IGUAL | N-136 | — | — | — | — |
| F-108 | INT | Lista de sessões por contabilizar/contabilizadas | IGUAL | N-122 | — | — | — | — |
| F-109 | INT | Pré-visualização por sessão/dia | IGUAL | N-112 | — | — | — | — |
| F-110 | INT | Lançamentos D transitórias / C proveitos e IVA por dia | DIFERENTE | N-111 | Proveitos/IVA do próprio documento (linhasProveitoEIva), sem recalcular por factor; CMV D71/C26 incluído no mesmo lançamento (legado: nenhum CMV no POS comercial) | — | M | — |
| F-111 | INT | Conta de proveito (61/62) e de IVA por produto | DIFERENTE | N-111 | Contas por produto/categoria (ContasProduto) sem fallback 62/61/31.1/34.5.3 (decisão não portar) | — | M | — |
| F-112 | INT | Conta de IVA por omissão + «Gravar X nesses serviços» | EM FALTA | — | Não existe (ANALISE_PARIDADE:412); sem aviso de IVA em falta nem atalho | S | B | F6 |
| F-113 | INT | Impedimentos da integração | DIFERENTE | N-111, N-112 | Equivalentes (sem transitória, Σ ≠ total, conta inexistente, exercício) + PAGAMENTOS_INCONSISTENTES; não absorve ≤ 1 Kz | — | — | — |
| F-114 | INT | Ajuste de arredondamento ≤ 1 Kz | NÃO PORTAR | — | Decidido não portar: desequilíbrio é erro (ADR-071) | — | B | — |
| F-115 | INT | Diário GEPOS criado automaticamente | IGUAL | N-011 | Configurável | — | — | — |
| F-116 | INT | Contabilizar em massa com resultado por sessão | IGUAL | N-113 | — | — | — | — |
| F-117 | INT | Marcar vendas contabilizadas | IGUAL | N-111 | `pos_lans_contabilizacao` | — | — | — |
| F-118 | INT | Descontabilizar sessão (bloqueios) | DIFERENTE | N-115 | Estorna (não apaga linhas); mesmos bloqueios; motivo obrigatório | — | — | — |
| F-119 | DES | Lista de desvios por deliberar e deliberados | IGUAL | N-121 | — | — | — | — |
| F-120 | DES | Deliberar sobra | IGUAL | N-116 | — | — | — | — |
| F-121 | DES | Deliberar falta | IGUAL | N-116 | — | — | — | — |
| F-122 | DES | Lançamento do desvio D/C | IGUAL | N-119 | — | — | — | — |
| F-123 | DES | Fundamentação ≥ 5 caracteres e data | IGUAL | N-116 | Por confirmar a data editável no modal | S | B | F9 |
| F-124 | DES | Anular deliberação | DIFERENTE | N-118 | Por estorno (legado apaga linhas) | — | — | — |
| F-125 | PRE | Itens por sessão (numerário, TPA, transferências) | IGUAL | N-123 | — | — | — | — |
| F-126 | PRE | Estado da Folha de Caixa aberta | IGUAL | N-132 | — | — | — | — |
| F-127 | PRE | Transferir numerário para a Folha de Caixa | DIFERENTE | N-124 | Movimento REC (PAG se negativo) na folha aberta da conta de liquidação; só Kz (folha em moeda é recusada: corrige BUG-12); segregação operador × prestador | — | M | — |
| F-128 | PRE | Ajuste do numerário com o desvio deliberado | IGUAL | N-124 | — | — | — | — |
| F-129 | PRE | Liquidar TPA (data-valor, banco, comissão) | IGUAL | N-125 | — | — | — | — |
| F-130 | PRE | Recebimento de Tesouraria por TPA/transferência | IGUAL | N-125, N-126 | — | — | — | — |
| F-131 | PRE | Pagamento separado da comissão | IGUAL | N-125 | — | — | — | — |
| F-132 | PRE | Registar transferência por comprovativo | IGUAL | N-126 | — | — | — | — |
| F-133 | PRE | Registar todas as transferências | IGUAL | N-127 | — | — | — | — |
| F-134 | PRE | Anular liquidação | DIFERENTE | N-128, N-131 | Não apaga (estado ANULADO); Tesouraria recusa mexer em documentos ligados | — | — | — |
| F-135 | PRE | Histórico de movimentos de prestação | IGUAL | N-132 | — | — | — | — |
| F-136 | PRE | Estado LIQUIDADA/PARCIAL/PENDENTE | IGUAL | N-129 | — | — | — | — |
| F-137 | ARM | Separadores Balcão / Picking / Senhas | IGUAL | N-137, N-140, N-144 | Acrescenta «Vendas ao balcão» | — | — | — |
| F-138 | ARM | Aviso «Nenhum Armazém Configurado» | PARCIAL | — | Por confirmar mensagem e botão «Configurar Armazéns» | S | B | F3 |
| F-139 | ARM | Selector de armazém | IGUAL | N-137 | — | — | — | — |
| F-140 | ARM | Catálogo inventariável com stock do armazém | IGUAL | N-137 | — | — | — | — |
| F-141 | ARM | Filtro, pesquisa, grelha/lista, zoom | PARCIAL | N-137 | Filtros e pesquisa existem; grelha/lista e zoom (`pos_armazem` 120–320) por confirmar | S | B | F3 / F8 |
| F-142 | ARM | Etiquetas Esgotado/Baixo/disponível | IGUAL | N-137 | — | — | — | — |
| F-143 | ARM | Carrinho de picking com limite pelo stock do armazém | IGUAL | N-137, N-138 | — | — | — | — |
| F-144 | ARM | Cliente de balcão opcional | PARCIAL | N-138 | Confirmar se `POST pos/armazem/vendas` e o ecrã aceitam cliente | S | B | F3 |
| F-145 | ARM | Aviso de diferenças stock global × armazéns | IGUAL | N-143 | — | — | — | — |
| F-146 | ARM | Acertar stock do armazém | DIFERENTE | N-143 | Cria movimento AJUSTE ao custo médio e repõe o total do produto (legado: sem movimentos nem alterar global; ADR-042). Corrige o ADR-050 | — | M | DECISÃO (manter novo) |
| F-147 | ARM | Confirmar Saída e Expedir (Guia de Saída GE POS) | DIFERENTE | N-138 | Guia `VENDA_BALCAO` numerada `GE POS AAAA/NNNN` com numeração central (legado: contagem total, BUG-04) | — | B | — |
| F-148 | ARM | Baixa de stock + movimento SAÍDA a custo médio | DIFERENTE | N-138 | Motor de stock; armazém não fica negativo | — | B | — |
| F-149 | ARM | Lançamento D71/C26 do custo | DIFERENTE | N-138 | Diário GS; sem contas fica «por contabilizar» com aviso (legado: diário LO‖GS‖1.º) | — | B | — |
| F-150 | ARM | Impressão da Guia de Saída A4 | PARCIAL | N-139 | Impressão só em Logística › Guias de Saída (`dadosGuiaSaida`); o POS de armazém não imprime ao expedir; faltam telefone/email/morada da empresa reais (legado tinha valores de exemplo) | S | B | F3 |
| F-151 | ARM | Talão térmico de levantamento/picking | EM FALTA | — | Não existe talão de picking no frontend (grep): corrige POS_ACTUAL §5 e ADR | S | B | F3 |
| F-152 | ARM | Síntese de voz da conclusão | EM FALTA | — | Voz só nas senhas (N-144), não na conclusão da saída/picking | S | B | F3 / F8 |
| F-153 | ARM | Fila de picking de encomendas | DIFERENTE | N-140 | Encomenda NE por entregar (POR_EXPEDIR/EXPEDIDA_PARCIAL); sem estado «EM PICKING» gravado (decisão) | — | B | Q8 |
| F-154 | ARM | Iniciar picking (marca EM PICKING) | NÃO PORTAR | N-141 | Marcador não gravado por decisão; a lista de recolha abre sem alterar a encomenda | — | B | Q8 |
| F-155 | ARM | Checklist de recolha com stock local e armazém | IGUAL | N-141 | — | — | — | — |
| F-156 | ARM | Bloqueio de verificação sem stock | IGUAL | N-142 | — | — | — | — |
| F-157 | ARM | Finalizar Picking & Expedir | DIFERENTE | N-142 | Conversão NE→GR com lock (`GE`/GR do motor); exige todas as linhas recolhidas; custo pelo motor de stock | — | B | — |
| F-158 | ARM | Pausar picking | NÃO PORTAR | — | Sem estado EM PICKING não há «pausa»; fechar a lista equivale | — | B | Q8 |
| F-159 | ARM | Tirar nova senha P-001 | DIFERENTE | N-144 | Senha por armazém e por dia, na BD (legado: memória, sem reinício diário, BUG-18) | — | — | — |
| F-160 | ARM | Chamar próxima senha (voz, ecrã grande, histórico 8) | IGUAL | N-144 | Mais: «repetir» chamada | — | — | — |
| F-161 | HOT | Mapa de quartos livre/ocupado/atrasado | IGUAL | N-063 | — | — | — | — |
| F-162 | HOT | Barra livres/ocupados/atrasados + «Balcão» | IGUAL | N-063, N-077 | — | — | — | — |
| F-163 | HOT | Check-in completo | IGUAL | N-065 | Hóspede tem de ser cliente | — | — | — |
| F-164 | HOT | Diária e venda à hora (mínimo de horas) | IGUAL | N-065 | — | — | — | — |
| F-165 | HOT | Bloqueio horário da venda à hora | IGUAL | N-067 | — | — | — | — |
| F-166 | HOT | Saída prevista (diária 12:00 / horas) | IGUAL | N-067 | — | — | — | — |
| F-167 | HOT | Aviso de check-in antecipado | IGUAL | N-067 | Aviso em quartos.ts | — | — | — |
| F-168 | HOT | Impedir check-in duplicado | IGUAL | N-066 | Índice único parcial | — | — | — |
| F-169 | HOT | Estadia na BD com histórico | IGUAL | N-068 | — | — | — | — |
| F-170 | HOT | Consumos do quarto (persistência com debounce) | DIFERENTE | N-069 | Substituição da lista por PUT no servidor; exige pos_desconto para preço alterado. **F4**: recusa mercadoria sem stock/acima do saldo do armazém do terminal com as mensagens do legado (antes só o check-out validava o stock) | — | — | — |
| F-171 | HOT | Bloqueio de consumos sem check-in | IGUAL | N-069 | — | — | — | — |
| F-172 | HOT | Alterar estadia com histórico | IGUAL | N-068 | — | — | — | — |
| F-173 | HOT | Anular check-in (sem consumos, com motivo) | IGUAL | N-070 | — | — | — | — |
| F-174 | HOT | Check-out de um ou vários quartos | IGUAL | N-071, N-073 | — | — | — | — |
| F-175 | HOT | Saída tardia (recalcular/manter) | IGUAL | N-072 | — | — | — | — |
| F-176 | HOT | Desconto % no check-out | IGUAL | N-073, N-075 | Exige pos_desconto | — | — | — |
| F-177 | HOT | Factura por quarto ou única | IGUAL | N-073 | — | — | — | — |
| F-178 | HOT | Repartição proporcional dos pagamentos | IGUAL | N-073 | — | — | — | — |
| F-179 | HOT | FR com alojamento + consumos | IGUAL | N-073, N-074 | Pivô venda↔estadia sem PK (D-01) | S | B | F0 |
| F-180 | HOT | Fecho da estadia com dados da factura | IGUAL | N-073 | — | — | — | — |
| F-181 | HOT | Talão único do check-out | IGUAL (opção) | N-078 | **F4**: por omissão um talão por factura-recibo; com várias facturas o operador escolhe o talão único (todas as linhas, todos os documentos, pagamentos somados), como o legado (Q10) | — | — | F4 |
| F-182 | LAV | Terminal lavandaria com ecrã próprio | IGUAL | N-107 | — | — | — | — |
| F-183 | LAV | Peças com opções de preço (peça/kg/tamanho) | IGUAL | N-080 | Opções Peça/Kg/Tamanho, «+ Opção», validações do legado e origem NOVO (F5) | — | — | — |
| F-184 | LAV | Preços por serviço × opção | IGUAL | N-080 | Matriz serviço × opção com modo/tamanho; edição e importação já não os apagam (F5) | — | — | — |
| F-185 | LAV | Tabela de serviços | IGUAL | N-081 | — | — | — | — |
| F-186 | LAV | Códigos automáticos PC/SV | IGUAL | N-080, N-081 | — | — | — | — |
| F-187 | LAV | Importação Excel de peças/serviços/preços | IGUAL | N-105 | Coluna Opção na importação de preços; simulação mostra a opção (F5) | — | — | — |
| F-188 | LAV | Modelos Excel de importação | IGUAL | N-105 | Modelos .xlsx com colunas nomeadas e folha «Como_Preencher» (F5) | — | — | — |
| F-189 | LAV | Definições da lavandaria | IGUAL | N-082 | `fator_prazo_urgencia` guardado em % (D-05) | — | — | — |
| F-190 | LAV | Nova ordem: cliente, facturação, urgência | IGUAL | N-084 | — | — | — | — |
| F-191 | LAV | Recolha e entrega ao domicílio | IGUAL | N-084, N-086 | — | — | — | — |
| F-192 | LAV | Catálogo em grelha/lista de peças e serviços | IGUAL | N-107 | Zoom/detalhes do catálogo da lavandaria por confirmar | S | B | F8 |
| F-193 | LAV | Escolher peça→serviço ou serviço→peça | IGUAL | N-107 | — | — | — | — |
| F-194 | LAV | Estado à entrada obrigatório | IGUAL | N-084 | — | — | — | — |
| F-195 | LAV | Descrição do dano obrigatória | IGUAL | N-084 | — | — | — | — |
| F-196 | LAV | Cobrança por kg com n.º de etiquetas | IGUAL | N-085, N-086 | — | — | — | — |
| F-197 | LAV | Linhas com orçamento | IGUAL | N-091 | — | — | — | — |
| F-198 | LAV | Cálculo da ordem | IGUAL | N-086 | — | — | — | — |
| F-199 | LAV | Prazo prometido / urgente | IGUAL | N-086 | — | — | — | — |
| F-200 | LAV | Adiantamento obrigatório do Consumidor Final | IGUAL | N-087 | — | — | — | — |
| F-201 | LAV | Pagamento na recepção com pagamentos mistos | IGUAL | N-084, N-097 | — | — | — | — |
| F-202 | LAV | Registo `OS/<TERM>/<ANO>/<n>` | IGUAL | N-085 | Sem UNIQUE na BD (D-01) | S | M | F0 |
| F-203 | LAV | Etiquetas por peça | IGUAL | N-085, N-106 | — | — | — | — |
| F-204 | LAV | Impressão OS 2 vias com condições | IGUAL | N-106 | — | — | — | — |
| F-205 | LAV | Etiquetas 56×30 mm | IGUAL | N-106 | — | — | — | — |
| F-206 | LAV | Lista de ordens com KPIs/filtros/pesquisa | IGUAL | N-096 | — | — | — | — |
| F-207 | LAV | Selecção em massa | IGUAL | N-091, N-092, N-093 | — | — | — | — |
| F-208 | LAV | Detalhe da ordem | IGUAL | N-096 | — | — | — | — |
| F-209 | LAV | Iniciar execução | IGUAL | N-090 | — | — | — | — |
| F-210 | LAV | Marcar pronta | IGUAL | N-090 | — | — | — | — |
| F-211 | LAV | Iniciar/Pronto em todas | IGUAL | N-090 | — | — | — | — |
| F-212 | LAV | Aprovar orçamento | IGUAL | N-091 | — | — | — | — |
| F-213 | LAV | Recusar orçamento | IGUAL | N-091 | — | — | — | — |
| F-214 | LAV | Aprovação/recusa em massa | IGUAL | N-091 | — | — | — | — |
| F-215 | LAV | Materiais da alfaiataria | IGUAL | N-095 | Guia de consumo ao custo médio | — | — | — |
| F-216 | LAV | Registar dano/reclamação | IGUAL | N-103 | — | — | — | — |
| F-217 | LAV | Alterar estado | IGUAL | N-092 | — | — | — | — |
| F-218 | LAV | Atribuir responsável (RH) | IGUAL | N-093, N-109 | — | — | — | — |
| F-219 | LAV | Anular ordem | IGUAL | N-094 | — | — | — | — |
| F-220 | LAV | Mensagem ao cliente (WhatsApp/copiar) | IGUAL | N-096 | — | — | — | — |
| F-221 | LAV | Emitir factura de linhas por facturar | IGUAL | N-099 | — | — | — | — |
| F-222 | LAV | Receber pagamento com recibo RC | IGUAL | N-097 | — | — | — | — |
| F-223 | LAV | Entrega com taxa de armazenagem | IGUAL | N-101 | — | — | — | — |
| F-224 | LAV | FR vs FT automático | IGUAL | N-098 | — | — | — | — |
| F-225 | LAV | Distribuição de recebimentos pelas facturas | IGUAL | N-100 | — | — | — | — |
| F-226 | LAV | Indicadores de prazo | IGUAL | N-096 | — | — | — | — |
| F-227 | LAV | Carga e desempenho por responsável | IGUAL | N-096 | — | — | — | — |
| F-228 | LAV | Danos e reclamações (comprovativo, decisão, pagamento) | IGUAL | N-103 | Segregação decidir × pagar (extra) | — | — | — |
| F-229 | LAV | Relatórios da lavandaria (Excel 7 folhas, imprimir) | IGUAL | N-104 | Excel de 7 folhas no servidor; impressão A4 pelo motor comum (F5) | — | — | — |
| F-230 | LAV | Integração contabilística da lavandaria | IGUAL | N-114 | Sem fallbacks 31.1/34.5.3 | — | — | — |
| F-231 | LAV | Fonte da sessão para o Z da lavandaria | IGUAL | N-110 | — | — | — | — |
| F-232 | LAV | Fluxo de Processos Lavandaria | IGUAL | N-150 | — | — | — | — |
| F-233 | FCX | Histórico de sessões da Folha de Caixa | IGUAL | — | Módulo Tesouraria (ServicoCaixa); fora do âmbito POS | — | — | — |
| F-234 | FCX | Abrir sessão (conta 45, saldo inicial da última) | IGUAL | — | Módulo Tesouraria | — | — | — |
| F-235 | FCX | Uma só sessão aberta por empresa | PARCIAL | — | Tesouraria: por confirmar a regra «uma por empresa» vs por conta | S | B | F9 |
| F-236 | FCX | Registar entrada/saída (grade e linhas) | IGUAL | — | Módulo Tesouraria | — | — | — |
| F-237 | FCX | Contrapartida pelo produto (custo nas saídas) | DIFERENTE | — | Servidor recusa produto sem conta de custo (PRODUTO_SEM_CONTA_CUSTO); legado só avisava | — | B | Tesouraria |
| F-238 | FCX | Colar do Excel / Importar Excel | IGUAL | — | ModalImportacaoCaixa; «colar» por confirmar | S | B | Tesouraria |
| F-239 | FCX | Pagar facturas de fornecedor | IGUAL | — | ModalLiquidarFacturas | — | — | — |
| F-240 | FCX | Receber facturas de cliente | IGUAL | — | ModalLiquidarFacturas | — | — | — |
| F-241 | FCX | Notas às demonstrações/fluxo por linha e em massa | IGUAL | — | ModalClassificarMovimentos | — | — | — |
| F-242 | FCX | Câmbio da conta de caixa e diferenças cambiais | IGUAL | — | ServicoCaixaMoedas | — | — | — |
| F-243 | FCX | Conferir contrapartidas das saídas | NÃO PORTAR | — | Remédio para um bug antigo; o servidor bloqueia na origem | — | B | — |
| F-244 | FCX | Remover linha não integrada | IGUAL | — | — | — | — | — |
| F-245 | FCX | Fechar sessão com contagem física e diferença | DIFERENTE | — | Sobra/quebra lançadas na contabilização (contas caixa_sobras/quebras); legado só mostrava a diferença | — | M | Tesouraria |
| F-246 | FCX | Contabilizar sessão (diário CX, nota 10, câmbio) | DIFERENTE | — | Um lançamento por dia (legado: um por linha); nota 10 e câmbio iguais | — | M | Tesouraria |
| F-247 | FCX | Eliminar sessão, detalhes, imprimir | DIFERENTE | — | Acrescenta reabrir e descontabilizar | — | B | Tesouraria |

Nota: as linhas com «por confirmar» não têm prova directa nem no inventário actual nem na leitura que fiz; ficam como casos de verificação manual/automática na F9 (não são lacunas assumidas).

## 4. Funcionalidades NOVAS do sistema (o legado não tem) — para registar, NÃO remover

| N | Funcionalidade nova | Nota |
|---|---|---|
| N-008 | Eliminar terminal (lógico), recusado com sessões/vendas | Legado nunca apaga terminais |
| N-010, N-013…N-015 | Catálogo por serviço (`produtos.servicos_pos`), serviços de lavandaria por terminal, venda recusa produto fora do serviço | |
| N-027 | Listagem/consulta de sessões com filtros e paginação | |
| N-044 | Leitor de código de barras «3*COD»/«COD*3» + Enter | Legado não tem (§7 ponto 3) |
| N-047 | Atalhos F2, F4, F8, F9, Esc | Legado sem teclado |
| N-045, N-046 | Imagens por URL seguro; stock em tempo útil do armazém do terminal | |
| N-053 | Operador só-POS consulta clientes/catálogo/stock sem criar fichas | |
| N-054…N-062 | Mesas no servidor: CRUD, conta partilhada entre postos, versão optimista, libertar, eliminar | |
| N-057 | Bloqueio optimista `CONTA_MESA_ALTERADA` | |
| N-066 | Índice único parcial «um quarto = uma estadia aberta» e uma sessão aberta por terminal | |
| N-076 | Lista/detalhe de estadias com histórico e tarifas | |
| N-079 | KPIs ocupação, ADR, RevPAR | Já nos relatórios de gestão do legado (I-20); mantém |
| N-117, N-130, N-103 | Segregação de funções aplicada no servidor (deliberar/prestar/indemnizar) | Legado só avisava ao gravar o perfil |
| N-120 | Desvio dentro da tolerância deliberado e lançado automaticamente | Ver BUG-21 / Q3 |
| N-127 | «Prestar todas as transferências» com erros por item | |
| N-131 | Tesouraria recusa alterar documentos ligados a liquidações POS | |
| N-133 | Filtro por operador, KPI de diferenças TPA, por integrar, por prestar | |
| N-143, N-144 | Acerto de stock com movimento AJUSTE; senhas na BD por armazém/dia com «repetir» | |
| N-146 | RLS por empresa e auditoria (`Auditavel`) em todas as tabelas POS | O legado não audita as tabelas POS (I-18) |
| N-148 | 10 validações de dados do POS/hotel/lavandaria | |
| — | `arredondamento_agt` à parte do desconto (ADR-066) | |
| — | Pré-visualização da integração por transacção revertida | O legado também tem pré-visualização (F-109), mas não revertida |
| — | Reabrir e descontabilizar sessão da Folha de Caixa; conferência de caixa | Tesouraria |
| — | Impressão 58 mm além de 80 mm; reimpressão por iframe sem janelas bloqueáveis | |
| — | Terminal eliminado/inactivo não aparece; `Ctrl+K` e favoritos para os ecrãs POS | |

## 5. Erros e desactualizações da documentação existente

| Documento | O que está desactualizado ou errado | Correcção |
|---|---|---|
| `ANALISE_PARIDADE_2026-10-02.md` §9-10 | Dá o POS como «MIGRADO» excepto mesas, impressões da lavandaria, importação, talão do check-out, criação de quartos, IVA em falta, talão e visto do picking, senhas/acerto «N/A» | Entregues depois: mesas, impressões e importação da lavandaria, talão do check-out, pré-visualização/lote da integração, senhas, acerto, ecrã inteiro/dispositivo. **Continuam por fazer**: IVA em falta (F-112), talão de picking (F-151), opções de preço da lavandaria (F-183/F-184, que a análise não detectou), QR no talão (F-071). «Criação de quartos» faz-se marcando o produto (`e_quarto`) e editando tarifas (`PUT pos/hotelaria/quartos/{produto}`) |
| ADR-050 | «Não portados: o acerto de stock e as senhas de atendimento» | Superado: portados (`ServicoAcertoStockPOSArmazem`, `ServicoSenhasPOSArmazem`, `paridade_pos_stock/pos_armazem.php`). Acrescentar que **não** existe talão nem impressão da guia no POS de armazém |
| ADR-049 | «Fica para depois: recibos RC-LAV no SAF-T, impressões, importação Excel» | Impressões e importação feitas (M-16); modelos de importação são CSV; **RC-LAV no SAF-T continua aberto** |
| `docs/dicionario/DICIONARIO_DADOS.md:2181-2420` | URLs de API inexistentes (`/api/pos/config`, `/api/pos/lavandaria/pedidos`…) e só descreve as 10 tabelas legadas | Reescrever URLs a partir de `route:list` (97 rotas) e acrescentar `mesas_pos`, `contas_mesa_pos`, `senhas_pos_armazem`, `vendas_estadias_hotel`, `pedidos_lavandaria_faturas` |
| `POS_ACTUAL_INVENTARIO.md` §5 e §8 | Diz «vistos por linha no picking (feito: `PICKING_INCOMPLETO` + talão)» | O visto/`PICKING_INCOMPLETO` existe; **o talão não existe** (nenhuma geração de talão em `frontend/src/modulos/stock`). Também: o inventário lista o POS de armazém como «sem leitor de código de barras» mas a frente comercial tem |
| `POS_ACTUAL_INVENTARIO.md` §1.3 | «Impostos: não há IVA em falta editável» | Correcto, mas é a lacuna F-112, não decisão |
| `POS_LEGADO_INVENTARIO.md` §10 (ponto 2) | Dá `Dialogo/ContaTerceiro/ImportMestre` como de `nucleo_partilhado.js` | Estão em `shared/ui.js`, `shared/contas_terceiro.js`, `shared/import_mestre.js` |
| `POS_LEGADO_INVENTARIO.md` §3.5 / F-071 | Fala de «hash/QR/ATCUD» no plano pedido | Angola: QR de consulta AGT + assinatura; ATCUD não se aplica |
| `RELATORIO.md` de `verificacao_visual` | Citado em comentários e rotas do código | Não existe no repositório: remover as citações ou repor o ficheiro |
| Comentários `ServicoCaixaLavandaria.php:39-40,374-377`, `ServicoMesasPOS.php:27` | «FT sai sem pos», «até os models serem gerados» | Já resolvidos (D-09, D-04) |

## 6. Interligações I-01…I-24 × estado no sistema novo

Legenda: **Completa** (existe e cobre o legado), **Parcial**, **Diferente** (existe mas por outro desenho), **Não existe** (também não existe no legado: paridade).

| I | Ligação | Existe? | Estado | Detalhe / o que falta |
|---|---|---|---|---|
| I-01 | POS → Vendas/Facturação (FR) | Sim | Diferente | FR emitida pelo motor `ServicoDocumentosVenda::emitir` com bloco `pos`; `is_posted` substituído por `pos_lans_contabilizacao`. A4 e PDF no modal de sucesso (F-072, F7) |
| I-02 | POS → Séries/AGT/QR/SAF-T | Sim | Parcial | Série por terminal, selagem, hash, QR (endpoint) e SAF-T com `SettlementAmount` existem; **falta QR no talão (F-071)** e **RC-LAV no SAF-T** |
| I-03 | POS → Stock/Armazém | Sim | Diferente | Armazém do terminal com lock, custo médio, CMV na integração (corrige o legado: preço de venda, sem CMV, stock global) |
| I-04 | POS Armazém → Stock/Guias/Contab. | Sim | Diferente | Guia `VENDA_BALCAO`/GR, diário GS, «por contabilizar» se faltarem contas; **sem impressão no POS (F-150/F-151)** |
| I-05 | POS → Contabilidade (integração em massa) | Sim | Diferente | Um lançamento por dia, GEPOS, valores do próprio documento, CMV D71/C26, estorno ao descontabilizar; **falta «IVA em falta» (F-112)**; sem fallbacks 62/31.1/34.5.3 |
| I-06 | POS → Contabilidade (desvios) | Sim | Diferente | `POS_DESVIO`; desvio automático dentro da tolerância (BUG-21/Q3); segregação no servidor |
| I-07 | POS → Folha de Caixa | Sim | Diferente | REC (ou PAG) na folha aberta da conta de liquidação, só Kz; operador ≠ prestador |
| I-08 | POS → Tesouraria | Sim | Completa | RECEBIMENTO/PAGAMENTO pendentes e integração normal; Tesouraria recusa mexer (N-131) |
| I-09 | POS → Contas/plano | Sim | Completa | Validações de classe (45/43/7/6/3) e totalizadoras nos serviços |
| I-10 | POS → Terceiros/Clientes | Sim | Completa+ | Cliente com conta classe 31, Consumidor Final com conta, hóspede = cliente; papéis em modo estrito (ADR-071) |
| I-11 | POS → Produtos/Preços | Sim | Completa+ | Catálogo por serviço (novo); sem tabelas de preço/promoções (igual ao legado) |
| I-12 | POS → Impostos | Sim | Completa | IVA por produto, `codigo_isencao_fe`; sem motivo de isenção editável no POS (igual) |
| I-13 | POS → Moedas | Parcial | Diferente | POS só Kz; Folha de Caixa tem moedas (`ServicoCaixaMoedas`); numerário POS para folha em moeda recusado (corrige BUG-12) |
| I-14 | POS → RH | Sim | Completa | Atribuir ordens a colaboradores; sem comissões/turnos (igual) |
| I-15 | POS → Projectos | Não existe | Paridade | Só UN/CC |
| I-16 | POS → Activos | Não existe | Paridade | Quartos são produtos |
| I-17 | POS → Utilizadores/Permissões/Empresas | Sim | Completa+ | 24 tarefas, `_view`, pares de segregação aplicados no servidor, RLS |
| I-18 | POS → Auditoria | Sim | Completa+ | `Auditavel` nos modelos (legado: nenhuma auditoria nas tabelas POS); excepção: `ServicoMesasPOS` usa `DB::table` (D-04) |
| I-19 | POS → Notificações/Fluxo | Parcial | Parcial | Fluxos POS/Lavandaria existem (N-150); **nenhuma notificação** (FE com erros, desvio por deliberar, sessão esquecida) |
| I-20 | POS → Dashboard/BI/Relatórios | Parcial | Parcial | Relatórios de gestão e OData existem; **painel de módulos sem KPIs POS (F-106)** |
| I-21 | Lavandaria → Contab./Tesouraria | Sim | Completa | FT D cliente/C proveitos+IVA, recibos D transitória/C cliente, indemnização por PAGAMENTO |
| I-22 | Hotelaria → Vendas | Sim | Completa | FR por quarto ou única; pivô venda↔estadia (sem PK: D-01) |
| I-23 | Folha de Caixa → Contabilidade | Sim | Diferente | Um lançamento por dia, sobra/quebra lançadas |
| I-24 | Notas às demonstrações/fluxo | Sim | Completa | Nota 10 imposta nas linhas 45; linhas POS→Folha chegam sem nota |

**Cadeia contas transitórias → integração em massa → regularização (a validar ponta a ponta na F9):** venda (FR, sem contabilidade) → fecho Z (`PENDENTE`) → [deliberar desvio, se `PENDENTE`] → integração (D transitória / C proveitos+IVA+CMV, estado `CONTABILIZADA`) → prestação (numerário→Folha; TPA/transferência→Tesouraria; transitória fica a zero) → liquidada. A ordem inversa de anulação (liquidação → deliberação → integração) está implementada com os mesmos bloqueios. **Riscos da cadeia no novo** que o legado também tem: (1) uma **NC emitida sobre uma FR do POS** depois do Z não altera a sessão nem a transitória (totais usam só FR e `estado ≠ ANULADO`) — o saldo da transitória deixa de bater certo; (2) tolerância de desvio: no legado ficava na transitória para sempre (BUG-21); (3) vendas de lavandaria (FT) entram na integração mas não nos totais de caixa.

## 7. Bugs aparentes («BUG?») do legado e recomendação

Convenção: **Corrigir** = não replicar (já está corrigido no novo salvo indicação); **Replicar** = reproduzir «igualzinho»; **Decidir** = depende de Q.

| ID | Comportamento do legado | No novo | Recomendação |
|---|---|---|---|
| BUG-01 | Talão e consulta imprimem sempre «Operador: CAIXA POS» | Imprime o operador real | **Corrigir** (manter). Decisão a confirmar (Q1) |
| BUG-02 | Desconto não reduz `net`/`tax` (R-002): talão e `sales` ficam incoerentes | Base e IVA reduzidos; `arredondamento_agt` à parte (40 500 com desconto pode sair 40 499,99) | **Corrigir** (manter; fiscalmente obrigatório). Q2 |
| BUG-03 | Fila de picking mostra `total_value` inexistente (0/NaN) | Fila baseada em NE com `total_bruto` | **Corrigir** (feito) |
| BUG-04 | `GE POS`/`GE PICK` por contagem total (colisões) | Numeração central, continua a do legado | **Corrigir** (feito); falta UNIQUE (F0) |
| BUG-05 | `inventory_movements.date` ISO completo no armazém e AAAA-MM-DD nos outros | Data uniforme | **Corrigir** (feito) |
| BUG-06 | «Guardar» da Config. Impressão chama `renderVendas('pos_config_print')` | Mensagem de sucesso, fica no ecrã | **Não replicar** |
| BUG-07 | `pos_anular` sem implementação; sem anulação/devolução | Sem anulação; NC no módulo Vendas | **Decidir** (Q6/Q7): NÃO PORTAR a permissão; opcional atalho «Emitir NC» na sessão |
| BUG-08 | Acentos corrompidos e typos (`Serviao`, `Endereao`, `Logastico`, `Terrasso`, «???» nas mesas, `border-collapose`) | Textos limpos | **Corrigir** (não replicar). Q15 |
| BUG-09 | O checkout actual não valida a conta de cliente classe 31; Consumidor Final sem conta | Valida (ADR-071); CF com conta | **Corrigir** (manter) |
| BUG-10 | Stock validado contra o stock global; armazém pode ficar negativo; movimento ao preço de venda; sem CMV | Armazém do terminal, lock, custo médio, CMV | **Corrigir** (manter) |
| BUG-11 | Documentos de lavandaria contam no Z como «documentos» com total 0 | Recibos/FR entram por meio sem somar a `total_vendas` | **Corrigir** (feito; confirmar apresentação do Z) |
| BUG-12 | Numerário em Kz entra na folha em moeda estrangeira como se fosse da moeda | Recusa (`MOEDA_NAO_SUPORTADA`) | **Corrigir** (feito) |
| BUG-13 | Guia de saída com telefone/email/morada de exemplo, «programa validado n? 0000/AGT/2026» e IVA «Isento» fixos | Guia A4 do motor comum com dados da empresa (só em Logística) | **Corrigir**: usar dados da empresa e nº de certificação real quando a guia for impressa no POS (F3) |
| BUG-14 | Talão do check-out multi-factura ignora QR (nº com vírgulas) | Um talão por FR | **Decidir** (Q10): talão único com QR de cada documento |
| BUG-15 | Saída tardia à diária: extra conta desde a hora prevista, não desde o fim da tolerância | Replicado (`RegrasHotel`) | **Replicar** (já igual; regra de negócio) |
| BUG-16 | Troco abatido ao numerário «por ordem das linhas» | Do último numerário para trás | **Decidir** (Q4): sem efeito com um só meio numerário; manter |
| BUG-17 | Carrinho do POS de armazém só em memória | Idem (cliente) | **Replicar** (ou persistir por posto, opcional) |
| BUG-18 | Senhas em memória, sem reinício diário, não partilhadas | BD, por armazém e dia | **Corrigir** (feito) |
| BUG-19 | Hotel: bloqueio da venda à hora só impede a entrada, não a saída | Idem | **Replicar** |
| BUG-20 | Consumos do quarto não abatem stock até ao check-out | Idem (o check-out pode falhar por falta de stock) | **Replicar**; mensagem clara no check-out |
| BUG-21 | Desvio dentro da tolerância = `SEM_DESVIO`, sem lançamento nem prestação: a transitória do numerário fica com resíduo para sempre | Deliberado automaticamente e lançado | **Decidir** (Q3): recomendado manter o novo |
| BUG-22 | Aviso de sessões antigas só pela data de abertura; o POS não fecha sozinho | Idem (aviso em todos os terminais) | **Replicar**; opcional fecho forçado por gestor (novo) |
| BUG-23 | Preço editável sem verificação efectiva de `pos_desconto` (R-003) | Exige `pos_desconto` | **Corrigir** (manter) |

Funcionalidades que o legado NÃO tem e o novo tem: ver secção 4 (nenhuma a retirar).
