# POS — Plano de reconstrução por fases

Complemento de `POS_MATRIZ_LACUNAS.md` (matriz F-xxx × N-xxx, interligações, bugs). Data: 2026-11-06, ramo `paridade-bd-front`. Este documento é **só planeamento**: nenhum código foi alterado.

## 0. Ponto de partida e princípios

- O POS novo já cobre **176 das 247 funcionalidades do legado como IGUAIS** e 45 como DIFERENTES (quase todas por correcção deliberada). Reconstruir «tal como está» significa, por isso, **fechar 15 PARCIAIS + 7 EM FALTA**, tratar as DIFERENÇAS que o utilizador não aceite (Q1–Q5) e blindar o resto com testes de paridade — não reescrever o módulo.
- O legado **não** tem devolução, anulação, sangria, suprimento, gorjeta, divisão de conta nem transferência de mesa (confirmado). Estes itens ficam numa fase opcional (F1b), só com decisão (Q6).
- **Migrações**: aditivas e idempotentes (`IF NOT EXISTS`/`Schema::hasColumn`), colunas novas nuláveis, CHECK/UNIQUE criados primeiro como `NOT VALID`/depois de limpar duplicados e validados depois; migrações com `$withinTransaction = false` quando usarem `CREATE INDEX CONCURRENTLY`. A produção tem dados reais (memória do projecto): **cada migração é ensaiada na cópia local (`erp_consulvolt` no PostgreSQL 18, 5432)** e seguida da suite `docker exec erp_app_e2e php artisan test` (nunca duas em paralelo). Tabelas novas entram em `IsolamentoRls::aplicarATodas`. As tabelas POS vêm de `ferramentas/gerador` («não editar à mão», D-03): alterações de esquema vão em migrações novas com guardas (padrão ADR-068) e actualizam `esquema_extra.mjs`/`esquema_bd.json` no mesmo commit.
- **Sem push**; cada fase fecha com commit próprio (`git add` só dos ficheiros da fase) e, após a suite massiva backend+frontend, `npm run build` (o frontend de teste serve de `frontend/dist`).
- Estimativas: S ≤ 1 dia de agente, M 2–4 dias, L ≥ 1 semana (um agente a tempo inteiro, com testes).

## 1. As 10 maiores lacunas (ordem de valor/risco)

| # | Lacuna | F-xxx | Fase |
|---|---|---|---|
| 1 | **Lavandaria sem opções de preço por peça/kg/tamanho** e ETL que perde `lav_prices`, `mode` e `size` (risco de dados em produção) | F-183, F-184, F-187, F-188 | F0b + F5 |
| 2 | **Talão do POS sem QR AGT, IBAN/SWIFT, «Talão nº» e «Processado por computador»** (o servidor já gera o QR) | F-070, F-071, F-072 | F1 |
| 3 | **POS de armazém não imprime a guia de saída nem o talão de levantamento e não fala a conclusão** | F-150, F-151, F-152 | F3 |
| 4 | **Sem UNIQUE/CHECK/PK na BD** em sessão, Z, OS, RC, config. da lavandaria e pivôs (D-01, D-02) | F-012, F-084, F-179, F-202 | F0 |
| 5 | **Cadeia transitória/NC e tolerância de desvio**: NC sobre FR POS não mexe na sessão; desvio tolerado difere do legado (BUG-21) | F-078, I-05…I-07 | F6 (+Q3, Q17) |
| 6 | **Painel de módulos sem KPIs POS e zero notificações** (FE com erros, desvio por deliberar, sessão esquecida) | F-106, F-068, I-19 | F6 |
| 7 | **Configuração de impressão só no navegador e impressão só no cliente** (sem PDF no servidor, sem trilho do que se imprimiu) | F-104, F-229 | F7 |
| 8 | **Atalho «IVA em falta»** na integração e **recibos RC-LAV fora do SAF-T** | F-112, ADR-049 | F6 |
| 9 | **UX da frente**: zoom, arrastar-e-largar, pílulas por meio, produtos bloqueados visíveis, série na abertura | F-009, F-017, F-027, F-030, F-032, F-033 | F1 + F8 |
| 10 | **Testes**: componentes grandes sem teste (`PainelVenda`, `FechoZ`, `Prestacao`…), 2 e2e, nenhum teste com os números documentados do legado | D-10, R-001…R-050 | F9 |

## 2. Fases

### F0 — Alicerces e dívida técnica (backend, esquema)
- **Objectivo**: integridade na BD e padrões, antes de mexer em funcionalidade.
- **F-xxx**: F-012, F-084, F-179, F-202 (unicidade/PK); sem alteração funcional.
- **Backend/BD**: `UNIQUE (empresa_id, codigo_sessao)`, `(empresa_id, numero_z)`, `(empresa_id, numero_encomenda)`, `(empresa_id, numero_recibo)` (parciais onde há nulos), `UNIQUE (empresa_id)` em `configuracoes_lavandaria`, PK composta em `vendas_estadias_hotel` e `pedidos_lavandaria_faturas`; CHECK dos estados (sessão, contabilização, liquidação, estadia, ordem, pagamento, reclamação, senha) criados `NOT VALID`+`VALIDATE`; `ServicoMesasPOS` a usar `MesaPOS`/`ContaMesaPOS` (auditoria, casts); FormRequests e API Resources para `POSController`, `LavandariaController`, `HotelariaController`, `PrestacaoContasController`, `MesasPOSController` (D-08); remover comentários falsos (D-09, D-04); renomear/documentar `erp:migracao:pos-carga` (D-15); passar `fator_prazo_urgencia` a fracção ou documentar (D-05).
- **Frontend**: nenhum (os Resources mantêm o contrato; testes de contrato).
- **Testes**: PHPUnit de unicidade e dos estados inválidos; teste de que as respostas não mudam (snapshot dos JSON actuais).
- **Migrações**: ensaiar na cópia: `SELECT empresa_id, numero_z, count(*) … HAVING count(*)>1` antes de criar cada UNIQUE; se houver duplicados do ETL, corrigir por script versionado e registado (nunca apagar vendas).
- **Riscos**: duplicados históricos bloqueiam o UNIQUE; Resources partem o frontend. **Aceitação**: suite verde; as consultas de duplicados devolvem 0 na cópia da produção; os JSON de rota não mudam.
- **Estimativa**: M. **Paralelismo**: arranca primeiro, mas só toca em `backend/app/Http`, `database/migrations` e `ServicoMesasPOS`; não colide com F1/F2/F3 se estes ficarem no frontend + serviços (coordenar a ordem dos nomes das migrações).

### F0b — Preservar os dados da lavandaria (pré-requisito de F5) 
- **Objectivo**: nenhuma carga ETL voltar a perder preços/tamanhos.
- **F-xxx**: F-183, F-184 (parte de dados).
- **Backend/BD**: colunas aditivas `pecas_lavandaria.opcoes_preco jsonb` (`[{modo,tamanho,preco}]`) e, em `precos_servico`, campos `modo`/`tamanho`; `itens` das ordens passam a aceitar `tamanho`/`modo`; `ServicoMigracaoLavandaria` traduz `lav_prices`, `service_prices.mode/size` e `items.size`; script de **auditoria** que conta, na cópia de produção, quantas peças têm mais de uma opção ou modo TAMANHO (decide a urgência real).
- **Testes**: fixture ETL com 1 peça com 3 opções (Peça, Kg, Tamanho M/Casal) e 1 matriz serviço×opção.
- **Riscos**: dados já migrados em produção sem `lav_prices` (reler do backup legado, não reconstituir). **Aceitação**: re-migração idempotente numa cópia devolve as opções e os totais das ordens antigas não mudam. **Estimativa**: S–M. **Paralelismo**: independente de F1–F4; precede F5.

### F1 — Frente de caixa, sessão e talão
- **Objectivo**: talão fiscalmente completo e frente igual ao legado.
- **F-xxx**: F-009, F-017, F-030, F-033, F-034, F-035, F-068, F-070, F-071, F-072 (e a decisão F-040/F-082 conforme Q2/Q5).
- **Backend**: o pedido de venda já devolve o necessário; acrescentar à resposta de venda o URL do QR (ou `fe_regime`/`id`) e as coordenadas bancárias activas (ou endpoint `pos/frente/talao-config`); aviso de erros AGT no resultado da venda (campo `fe_estado`/`fe_erros`).
- **Frontend**: `comum/impressao.ts` (`htmlTalaoVenda`): QR (imagem do endpoint), «Talão nº», NIF cliente 999999999 por omissão, IBAN/SWIFT, «Processado por computador (Software ERP)»; `AberturaCaixa` mostra série FR e meios com aviso vermelho; `BarraSessaoFrente` com pílulas por meio (cores do legado); `GrelhaProdutos`: produtos bloqueados visíveis com crachá «Bloqueado» e mensagem «O artigo "X" está bloqueado para venda.»; mensagens de stock com os textos do legado (`ServicoVendasPOS::exigirStock`); modal de sucesso com «Imprimir A4» (motor comum) e «Descarregar PDF».
- **Testes**: Vitest do talão (golden HTML com QR e IBAN), Vitest das mensagens, PHPUnit da resposta da venda; e2e: vender e ver o QR.
- **Riscos**: dependência do endpoint de QR (GD no servidor); CSP sem inline. **Aceitação**: talão de uma FR selada contém URL de consulta AGT correcta; documento sem regime não mostra QR. **Estimativa**: M.
- **F1b (opcional, só com Q6)**: consulta/reimpressão de vendas da sessão, atalho «Emitir NC» a partir da venda, suprimento/sangria. **Estimativa**: M–L; **Risco**: fiscal (NC) e contabilístico (transitórias): exige F6/Q17.
- **Paralelismo**: ficheiros próprios (`frente/*`, `comum/impressao.ts`, `comum/documentos.ts`); colide com F7/F8 em `impressao.ts` e `PainelVenda.tsx` → fazer F1 antes, ou serializar.

### F2 — Restaurante (mesas)
- **F-xxx**: F-044, F-048, F-049 (e Q9).
- **Trabalho**: mesas por omissão ao criar terminal RESTAURANTE (Balcão fica como «venda sem mesa»; Mesa 1–8, Esplanada 1–2, Take-Away com nomes limpos, sem «???»), texto/modal «Conta Suspensa», botões Pagar/Checkout na pré-visualização da consulta, operador real no talão. Opcional (Q6): transferência de mesa e divisão de conta.
- **Backend**: `ServicoMesasPOS` (já em modelos após F0); semente idempotente (só cria se o terminal não tiver mesas). **Testes**: `POSMesasTest` + semente; Vitest `mesas.ts`.
- **Riscos**: baixo. **Aceitação**: terminal novo RESTAURANTE abre com as mesas; consulta imprime operador real. **Estimativa**: S. **Paralelismo**: `frente/PainelMesas.tsx` + `ServicoMesasPOS` — sem colisão com F3/F4/F5.

### F3 — POS de armazém
- **F-xxx**: F-138, F-141, F-144, F-150, F-151, F-152 (+ Q8 sobre F-153/F-154/F-158).
- **Trabalho**: ao expedir imprimir a **Guia de Saída A4** (reutilizar `dadosGuiaSaida`, com dados reais da empresa — corrige BUG-13) e o **talão de levantamento** 78/80 mm; voz «Atendimento de X concluído. Guia N gerada» (já existe `speechSynthesis` nas senhas); cliente de balcão opcional; aviso «Nenhum armazém configurado»; grelha/lista e zoom `pos_armazem`; (Q8) estado «EM PICKING» leve (utilizador+hora, sem bloquear).
- **Backend**: pequeno (cliente na venda de balcão, se faltar; campo `em_picking_*` aditivo se Q8 = sim). **Testes**: `POSArmazemTest`, Vitest do talão e `posArmazem.test.tsx`.
- **Riscos**: baixo. **Aceitação**: expedir imprime guia A4 e talão com número da guia e artigos. **Estimativa**: S–M. **Paralelismo**: `frontend/src/modulos/stock/posArmazem*` + `ServicoPOSArmazem` — livre de colisões.

### F4 — Hotelaria
- **F-xxx**: F-181 (Q10) e verificação das restantes linhas de hotelaria (19 IGUAIS).
- **Trabalho**: talão único do check-out (todas as linhas, QR de cada documento) como opção; verificação manual do mapa de quartos/saída tardia contra os casos R-029…R-033; criação de quartos (documentar: produto `e_quarto` + tarifas). Sem reservas/housekeeping (fora do legado).
- **Backend/BD**: nenhum obrigatório. **Testes**: `POSHotelariaTest` com os casos numéricos da F9. **Riscos**: baixo. **Estimativa**: S. **Paralelismo**: `frontend/src/modulos/pos/hotelaria` — livre.

### F5 — Lavandaria e alfaiataria
- **F-xxx**: F-183, F-184, F-187, F-188, F-192, F-229 (e F-215…F-228 a verificar).
- **Trabalho**: **opções de preço** por peça (Peça, Quilograma, Tamanho + nome do tamanho; «+ Opção»; validações do legado: «Dê nome ao tamanho…», «Há duas opções de preço iguais…», duplicado por designação+tecido+1.ª opção), matriz serviço×opção, linha da ordem com tamanho e modo, escolha «Cobrar por» ao picar a peça, mensagem «sem preço para esta peça nesta opção…», editor de peças com a tabela de opções; importação com colunas Opção/Tamanho; modelos `.xlsx` (hoje CSV); exportação Excel de 7 folhas do relatório e impressão A4; zoom/detalhes do catálogo.
- **Backend/BD**: usa as colunas da F0b; `ServicoTabelasLavandaria`, `ServicoOrdensLavandaria::registar` (preço por opção), `ServicoImportacaoTabelasLavandaria`, `ServicoRelatorioLavandaria`.
- **Testes**: `POSLavandariaTest` com os casos R-034…R-043; Vitest `Recepcao.test.tsx`, `formularios.test.ts`; migração de uma peça de 3 opções.
- **Riscos**: **alto** (regra de preço central; ordens antigas com preço único têm de continuar iguais). **Aceitação**: peça com opções Peça/Kg/Tamanho cria ordem com o preço certo por opção; ordens migradas não mudam de total. **Estimativa**: L. **Paralelismo**: `Services/POS/Lavandaria` + `frontend/.../pos/lavandaria` — livre de F1–F4 (depende de F0b).

### F6 — Integrações, contabilização e prestação de contas
- **F-xxx**: F-078, F-082, F-106, F-112, F-068 (aviso), I-02 (RC-LAV no SAF-T), I-05…I-07, I-19.
- **Trabalho**: (1) atalho «IVA em falta» na pré-visualização (listar serviços sem conta de IVA e gravar a conta indicada); (2) **decisão e implementação do tratamento de NC sobre FR POS** (Q17): marcar a venda, ajustar os totais da sessão aberta ou estornar na integração, e validação que impede NC depois da prestação sem aviso; (3) tolerância de desvio conforme Q3; (4) talão TPA em falta conforme Q5; (5) KPIs POS no painel de módulos («POS — Sessões abertas / Vendas de hoje», pendências por contabilizar/desvios/por liquidar, lavandaria prontas/atrasadas); (6) notificações (`Notificacoes`) para FE com erros, desvio por deliberar, sessão aberta há dias; (7) RC-LAV no SAF-T (consultar o contabilista).
- **Backend**: `ServicoContabilizacaoPOS`, `ServicoSessoesPOS`, `Gestao/Paineis/Indicadores`, serviço de notificações, `Services/Vendas` SAF-T. **Testes**: `ParidadePosContasTest`, `POSPrestacaoTest`, novo teste da cadeia venda→NC→Z→integração→prestação com a transitória a zero.
- **Riscos**: **alto** (contabilidade e SAF-T). **Aceitação**: a conta transitória de cada meio fica a zero após prestação, mesmo com NC e desvio tolerado; SAF-T com RC-LAV valida no verificador AGT (skill `verificar-saft-agt`). **Estimativa**: L. **Paralelismo**: toca `Services/POS` contabilização/prestação — não colide com F1–F5, colide com F0 (estados/CHECK): fazer depois de F0.

### F7 — Relatórios, impressão no servidor e configuração persistida
- **F-xxx**: F-104, F-229, F-072 (PDF), D-12, D-13.
- **Trabalho**: configuração de impressão por empresa (e por posto como sobreposição) na BD (`configuracoes_pos`, colunas aditivas: formato, largura, consulta, automática, rodapé); PDF no servidor para A4 do POS (Z, X, relatórios, talão) via serviço único (ou manter o motor do cliente e guardar só o registo do que foi impresso); exportação Excel dos relatórios POS e da lavandaria. ESC/POS directo fica fora (Q18).
- **Riscos**: médio (PDF no servidor, fontes, CSP). **Aceitação**: a preferência segue o utilizador entre postos; PDF do Z igual ao A4 do cliente. **Estimativa**: M. **Paralelismo**: colide com F1/F8 em `comum/impressao.ts` — depois de F1.

### F8 — UX, atalhos e teclado
- **F-xxx**: F-027, F-032, F-141, F-192 (zoom), foco e teclado.
- **Trabalho**: `GrelhaZoom` (botões −/+ e persistência por posto, chaves `pos_vendas` 170 [110–300], `pos_mesas`/`pos_quartos` 140 [90–260], `pos_armazem` 200 [120–320]); arrastar-e-largar para o carrinho (zona tracejada azul, micro-animação); autofocus da pesquisa, atalhos F2/F4/F8/F9/Esc já existentes + Enter a adicionar o 1.º resultado; revisão de acessibilidade (axe, 12 larguras já existentes). **Testes**: Vitest + Playwright/axe. **Riscos**: baixo (touch/ecrã pequeno). **Estimativa**: S–M. **Paralelismo**: colide com F1 em `PainelVenda.tsx`/`GrelhaProdutos.tsx`: depois de F1.

### F9 — Testes de paridade ponta a ponta
- **Objectivo**: provar «igualzinho» com os números do legado.
- **Trabalho**: suíte `ParidadePosLegadoTest` (PHPUnit) + Vitest `calculos.paridade.test.ts` + 3 e2e novos (restaurante, hotelaria, lavandaria; prestação ponta a ponta) com fixtures abaixo; casos «por confirmar» da matriz (F-080, F-123, F-138, F-144, F-235…); teste de carga leve do `ServicoSessoesPOS::totais` (D-14); re-correr a cadeia contabilística. No fim: `npm run build`.
- **Estimativa**: M. **Depende** de F1–F6.

**Fixtures de paridade (R-xxx, valores AOA; calculados à mão, a confirmar por execução no legado):**

| Regra | Entrada | Esperado legado | Esperado novo (se difere) |
|---|---|---|---|
| R-001 | 3 × 1 140,00, IVA 14 % | bruto 3 420,00; base 3 000,00; IVA 420,00 | igual |
| R-002 | idem, desconto 10 % | `gross` 3 078,00 mas `net` 3 000 e `tax` 420 (incoerente) | base 2 700,00; IVA 378,00; total 3 078,00 |
| R-005 | total 1 000; numerário 600 + TPA 500 | pago 1 100; outros 500 ≤ 1 000; troco 100; numerário líquido 500 | igual |
| R-005b | total 1 000; TPA 1 100 | erro «O TPA e as transferências não podem exceder o total a pagar…» | igual |
| R-010 | fundo 50 000 + numerário 120 000; contado 169 500; tolerância 100 | esperado 170 000; desvio −500 → PENDENTE, exige justificação | igual |
| R-010b | contado 169 950 (desvio −50, tolerância 100) | SEM_DESVIO, sem lançamento | DELIBERADO automático FALTA_CUSTO 50 e lançado (BUG-21) |
| R-014 | FR 1 140, uma linha 1 140 IVA 14 % | C proveitos 1 000; C IVA 140; D transitória 1 140 | igual (+ CMV se inventariável) |
| R-021 | TPA 200 000, comissão 1,5 % | comissão 3 000; recebimento 197 000 (deduzida) ou 200 000 + pagamento 3 000 | igual |
| R-029 | entrada dia 5 às 14:00, 1 diária | saída prevista dia 6 às 12:00 | igual |
| R-031 | prevista 12:00, tolerância 60, agora 13:30 (diária) | extra = ceil(1,5 h / 24 h) = +1 diária | igual |
| R-032/33 | 2 estadias A 18 600 e B 9 300 (após desc. 10 %); pagamentos 20 000 num. + 7 900 TPA; factura por quarto | A: 13 333,33 + 5 266,67; B (resto): 6 666,67 + 2 633,33 | igual |
| R-034/35/37 | serviços 10 000; urgente 50 %; recolha 1 000; entrega 500; prazo 3 d, factor 0,5 | total 16 500; prazo ceil(1,5) = 2 d; adiantamento CF 50 % = 8 250 | igual |
| R-039 | linha 4 000 pronta há 35 dias; 30 grátis; 2 %/dia | taxa 400 | igual |
| R-046 | folha: abertura 100 000; REC 60 000; PAG 25 000 | saldo 135 000 | igual |

## 3. Quadro de paralelismo (agentes sem colisão de ficheiros)

| Onda | Fases em paralelo | Ficheiros próprios | Cuidados |
|---|---|---|---|
| 1 | **F0** + **F0b** (podem partilhar um agente de BD) | `database/migrations`, `ServicoMesasPOS`, controladores/Requests/Resources, `ServicoMigracaoLavandaria` | Um só agente numera as migrações; suite PHPUnit sem concorrência |
| 2 | **F1**, **F3**, **F4**, **F5** (e **F2** logo a seguir a F1) | F1: `frente/*`, `comum/impressao.ts`; F3: `stock/posArmazem*`, `ServicoPOSArmazem`; F4: `pos/hotelaria`; F5: `Services/POS/Lavandaria`, `pos/lavandaria` | F2 mexe em `PainelMesas.tsx` e partilha `PainelVenda.tsx` com F1: serializar |
| 3 | **F6** (contabilização) em paralelo com **F7** (impressão/config) | F6: `Services/POS` contabilização, painéis; F7: `comum/impressao.ts`, `configuracoes_pos` | F7 depois de F1; F6 depois de F0 |
| 4 | **F8** | `GrelhaProdutos`, `PainelVenda` | Depois de F1 e F7 |
| 5 | **F9** | testes | Depois de todas; `npm run build` no fim |

Estimativa global grosseira: F0 M, F0b S–M, F1 M, F2 S, F3 S–M, F4 S, F5 L, F6 L, F7 M, F8 S–M, F9 M ⇒ cerca de 5–7 semanas-agente em série, 3–4 semanas com 3–4 agentes em paralelo conforme o quadro. F1b (opcional) soma M–L.

## 4. Perguntas ao utilizador (decisões que só ele pode tomar)

Cada pergunta traz a minha recomendação.

1. **Q1 — Correcções já feitas.** O novo corrige comportamentos do legado (operador real no talão, stock do armazém do terminal, estorno em vez de apagar, segregação no servidor, numeração central). «Igualzinho» deve ceder a estas correcções? **Recomendo**: sim, manter todas; replicar fluxos, ecrãs, textos e regras de cálculo, não os defeitos.
2. **Q2 — Desconto.** O legado deixa base e IVA antes do desconto (talão incoerente); o novo reduz-os (regra AGT). **Recomendo**: manter o novo; aceitar o arredondamento AGT (ex.: 40 500 com desconto pode sair 40 499,99).
3. **Q3 — Desvio dentro da tolerância.** Legado: ignora (a transitória fica com resíduo para sempre). Novo: delibera e lança automaticamente. **Recomendo**: manter o novo (a transitória fecha a zero); mostrar o lançamento automático no ecrã de Desvios.
4. **Q4 — Troco.** Ordem de abatimento no numerário (legado: por linhas; novo: do último para trás). **Recomendo**: manter o novo (sem efeito com um só meio numerário).
5. **Q5 — Fecho sem talão do TPA.** Legado: pede confirmação e deixa fechar; novo: recusa. **Recomendo**: voltar ao legado (aviso + confirmação + justificação obrigatória), para não bloquear o fim do turno; a diferença fica registada.
6. **Q6 — Funcionalidades que o legado não tem** (devolução/consulta de vendas, atalho NC, suprimento/sangria, gorjeta, divisão de conta, transferência de mesa, reabrir sessão). **Recomendo**: não fazem parte da paridade; incluir só (a) consulta/reimpressão das vendas da sessão e (b) atalho «Emitir NC» (F1b), depois de F6; suprimento/sangria numa fase posterior; o resto não.
7. **Q7 — Permissão `pos_anular`** (existe no perfil antigo sem função). **Recomendo**: NÃO PORTAR; a correcção de uma venda é NC com a permissão de Vendas.
8. **Q8 — «EM PICKING» e «Pausar».** O novo não grava este estado. **Recomendo**: não portar agora; reavaliar se houver dois operadores a recolher a mesma encomenda (então campo leve «em picking por/às»).
9. **Q9 — Mesas por omissão.** **Recomendo**: criar ao abrir um terminal RESTAURANTE «Mesa 1–8, Esplanada 1–2, Take-Away» (nomes limpos) e manter «Balcão» como venda sem mesa.
10. **Q10 — Check-out de vários quartos.** Legado: um único talão; novo: um por factura. **Recomendo**: oferecer o talão único (com QR de cada documento) como opção.
11. **Q11 — Configuração de impressão.** **Recomendo**: guardar por empresa na BD, com sobreposição por posto; impressão directa ESC/POS fora de âmbito; PDF no servidor só para A4.
12. **Q12 — Lavandaria: opções de preço (peça/kg/tamanho).** **Recomendo**: portar completo (L) e, antes, fazer a auditoria da F0b à cópia da produção para saber quantas peças dependem disso; é a única lacuna com risco de dados.
13. **Q13 — Âmbito «NÃO PORTAR».** Confirmar as quatro decisões já tomadas: `FR-POS/…` como série de recurso (F-062), absorção de ≤ 1 Kz (F-114), marcador EM PICKING (F-154/F-158), «Conferir contrapartidas» (F-243). **Recomendo**: confirmar as quatro.
14. **Q14 — Folha de Caixa.** Tratar as 5 diferenças (F-237, F-245, F-246, F-247, F-235) no plano da Tesouraria e não no POS. **Recomendo**: sim.
15. **Q15 — Texto e UX.** Não reproduzir acentos corrompidos nem typos do legado; zoom e arrastar-e-largar entram em F8 (prioridade baixa). **Recomendo**: sim.
16. **Q16 — RC-LAV no SAF-T.** **Recomendo**: sim, prioridade média, a validar com o contabilista e o verificador AGT.
17. **Q17 — NC sobre FR do POS.** O que fazer à sessão/transitória quando a NC chega depois do Z ou da prestação? **Recomendo**: bloquear a NC de FR POS com sessão já integrada, salvo se emitida com a permissão contabilística e acompanhada de lançamento de estorno na transitória; NC em sessão aberta abate os totais da sessão.
18. **Q18 — Notificações e painel.** Activar notificações (FE com erros, desvio por deliberar, sessão aberta há dias) e KPIs POS no painel de módulos. **Recomendo**: sim (F6).
