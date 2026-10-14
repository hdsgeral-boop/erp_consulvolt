# Análise v3 — RH / Salários / Estrutura Orgânica / Portal do Colaborador

Comparação **LEGADO NOVO** (`Versões/Versão Actual/ERP`) × **LEGADO ANTIGO** (`payroll_system_web`) e verificação no **erp_laravel**.
Legendas: LN = legado novo · LA = legado antigo · EL = erp_laravel. Só de leitura: não se alterou nada.

## Conclusão principal

- **O motor de cálculo não mudou.** `js/engine_v2.js` é igual nos dois legados (0 linhas de diferença), e `regras_salariais.js` só mudou na escolha do contrato em vigor. O IRT, o INSS, o valor dia/hora, as horas extra (+50 % até 30 h no mês, +75 % acima) e o avençado (6,5 %) ficam como estavam. O `MotorSalarial.php` do EL já os aplica, e com as correcções do ADR-014/017/036.
- **O que muda no salário vem de fora do motor:** (1) que contratos entram no processamento (suspensos e cessados), (2) as prestações de empréstimos como desconto, (3) as horas de falta e extra apuradas pela escala ou pelo horário do contrato, (4) a produtividade de base «prémio» e (5) a repartição do custo por várias unidades de negócio na integração contabilística.
- **No EL faltam três módulos inteiros:** Escala do mês, Empréstimos e Suspensão/Cessação. Há ainda várias funcionalidades **PARCIAIS**: efectividade sem horário nem dois períodos, cartas de pagamento só de salários, funções sem objectivo geral, minuta do contrato antiga e portal sem os separadores novos.

## Tabela-resumo

| # | Funcionalidade | Ecrã (LN) | Estado no EL | Esforço |
|---|---|---|---|---|
| 1 | Escala do mês (turnos por colaborador e dia, escala prioritária, migrar do contrato, copiar, Excel) | RH › Efectividade › separador «Escala do mês» | **FALTA** | Alto |
| 2 | Horário do contrato + regime de 1 ou 2 períodos (2 ou 4 picagens) | RH › Contratos (formulário) e Efectividade | **FALTA** | Médio |
| 3 | Apuramento da efectividade pela escala ou horário (horas/dia por dia, dias úteis por colaborador, atrasos e saídas antecipadas, avisos) | RH › Efectividade › Resumo do mês | **PARCIAL** (só as regras antigas) | Médio |
| 4 | Importação da efectividade melhorada (modelo Excel já preenchido, 4 picagens, NIF sem zeros à esquerda, vírgula decimal, linhas em branco ignoradas) | RH › Efectividade › Importar | **PARCIAL** | Baixo–Médio |
| 5 | Empréstimos a colaboradores (pedido no portal → chefia → RH → plano de amortização → conta corrente → desconto no cálculo) | RH › Empréstimos; Portal › Empréstimos; Calcular › «Importar Empréstimos» | **FALTA** | Alto |
| 6 | Suspensão e cessação do contrato (Lei 12/23, fundamentos, compensação, reversão, histórico) | RH › Contratos (botões por contrato) | **FALTA** | Médio–Alto |
| 7 | Contrato em vigor mais rigoroso (exclui suspensos dentro do período e cessados) + contrato «vigente» só para consulta | Processamento, ficha, portal | **PARCIAL** | Baixo (depende do 6) |
| 8 | Minuta do contrato de trabalho conforme a Lei 12/23 (art. 13.º, motivo do termo, período experimental do art. 18.º, subsídios de 50 % do art. 238.º, objectivo e responsabilidades numeradas) | RH › Contratos › «Gerar PDF para Assinatura» | **PARCIAL** | Baixo–Médio |
| 9 | Remunerações contratualizáveis = todas as rubricas de vencimento | RH › Contratos | **EXISTE** | — |
| 10 | Descritivo das funções (objectivo geral + responsabilidades) e herança pelos cargos da estrutura (função obrigatória) | RH › Funções; Estrutura › Unidades e Cargos | **PARCIAL** | Baixo–Médio |
| 11 | Produtividade: rótulos e fórmula por métrica, base «PRÉMIO» para objectivo, explicação do cálculo, permissões por separador | RH › Produtividade | **PARCIAL** | Baixo |
| 12 | Produtividade a partir da execução de projectos (horas, quantidades medidas, tarefas concluídas) | RH › Produtividade › «Execução de projectos» | **FALTA** | Médio–Alto |
| 13 | Aplicar itens de produtividade em massa a contratos | RH › Contratos | **FALTA** | Baixo–Médio |
| 14 | Portal: separadores Escala, Contrato e Empréstimos | Portal do Colaborador | **FALTA** | Médio (depende de 1, 5 e 6) |
| 15 | Portal autónomo (`portal.html`: login próprio, escolha da empresa, sem o ERP à volta) | Página própria | **FALTA** | Médio |
| 16 | Cartas de ordem de pagamento (a partir de um movimento de tesouraria; terceiros e salários; modelo de texto; IBAN da conta 42/43) | Tesouraria › Cartas de Pagamento | **PARCIAL** (só salários, a partir do período) | Médio–Alto |
| 17 | Repartição do custo salarial por várias unidades de negócio (`unidades_alocadas`) | Processamentos › Integração contábil | **FALTA** | Médio |
| 18 | Motor IRT/INSS/faltas/horas extra/avençado | Calcular / Processamentos | **EXISTE** (sem alterações no LN) | — |

---

## 1. Escala do mês — **FALTA**

1. **O que faz para o utilizador:** define, por colaborador e por dia, o turno previsto (entrada, saída, intervalo, tolerância) ou a folga. Formas de criar: migrar o horário do contrato, inserir à mão (um dia, um intervalo, ou certos dias da semana), copiar um intervalo para outro, importar Excel e limpar um intervalo. A **escala prioritária** sobrepõe-se à prevista sem a apagar: retirada a prioritária, a prevista volta a valer. As gerações em massa nunca a substituem sem confirmação explícita. Grelha mensal com horas, dias de trabalho, folgas e prioritárias por colaborador.
   - Ecrã: RH › Efectividade › separador «Escala do mês» (`js/modules/rh/assiduidade_ui.js:82` `abaEscala`).
   - Lógica: `js/modules/rh/escalas.js` (`horasDe` :60, `gravarLinhas` :140, `aplicar` :169, `gerarDoContrato` :190, `copiar` :225, `lerExcel` :291, `grelhaMes` :344).
2. **Dados:** tabela Dexie nova `rh_schedules` (v1270), com índices `[company_id+dia]`, `[employee_id+dia]` e `[company_id+employee_id]`. Campos: `company_id, employee_id, dia 'AAAA-MM-DD', entrada, saida, periodos (1|2), saida_intervalo, entrada_intervalo, intervalo_min (0–480), tolerancia_min (0–120), folga, prioritaria, origem CONTRATO|MANUAL|EXCEL|COPIA, nota, criado_em/por, actualizado_em/por`.
   - **Regras:**
     - Prevalência no dia: prioritária › normal › horário do contrato › configuração da empresa.
     - Horas previstas com 1 período = saída − entrada − intervalo_min/60.
     - Horas previstas com 2 períodos = (saída do intervalo − entrada) + (saída − entrada depois do intervalo).
     - Turnos que passam da meia-noite: a diferença soma 24 h.
     - `gerarDoContrato` usa os dias da semana do horário (ou os dias úteis da empresa), salta os feriados e pode marcar as folgas.
     - Excel com a chave NIF e as colunas NIF, Colaborador, Data, Entrada, Saída, Períodos, Saída intervalo, Entrada intervalo, Intervalo (min), Tolerância (min), Folga, Prioritária e Nota.
     - Só entram colaboradores activos.
3. **No EL:** a pesquisa por `rh_schedules|escala|prioritari` em `backend/app`, `routes`, `migrations` e `frontend/src` não devolve nada. Não há tabela, serviço, ecrã nem permissão.
4. **Esforço: Alto.** Precisa de uma migração (`escalas_colaboradores`), de `ServicoEscalas` (gravar com protecção das prioritárias, gerar do contrato, copiar, importar Excel com PhpSpreadsheet, grelha), do separador em `Assiduidade.tsx` com grelha editável, e da integração em `ServicoAssiduidade::resumoMes`. **Depende do #2** (horário do contrato) e alimenta o #3 e o #14.

## 2. Horário do contrato e regime de picagem — **FALTA**

1. **O que faz:** no formulário do contrato define-se o horário diário (entrada, saída, intervalo, tolerância e dias da semana) e o regime: **1 período** (2 picagens, intervalo fixo descontado) ou **2 períodos** (4 picagens, intervalo picado). As «Horas por Dia» do contrato passam a acompanhar o horário. A configuração da efectividade ganha `periodos_padrao`, `intervalo_saida_padrao` (12:00) e `intervalo_entrada_padrao` (13:00).
   - Ficheiros: `js/app_v2.js:4751` (formulário) e `js/modules/rh/assiduidade.js:148` `horarioDe` e :204 `regimeDoDia`.
2. **Dados:** `contracts.horario = { entrada, saida, periodos, saida_intervalo, entrada_intervalo, intervalo_min, tolerancia_min, dias[0..6] }`. Os registos de efectividade ganham `saida_intervalo` e `entrada_intervalo`, e `rh_attendance_config` ganha os três campos acima.
3. **No EL:** `ContratoTrabalho` não tem coluna `horario`. A pesquisa por «horario» nas migrações de RH e nos modelos base não devolve nada. `ServicoAssiduidade::gravarRegisto` só aceita `entrada/saida/horas`.
4. **Esforço: Médio.** É preciso acrescentar uma coluna JSON ao contrato, os campos aos registos e à configuração, a validação (as duas picagens do intervalo vão juntas) e o formulário. É pré-requisito dos #1 e #3.

## 3. Apuramento da efectividade pela escala e pelo horário — **PARCIAL**

1. **O que faz:** o resumo do mês (`assiduidade.js:674` `resumoMes`) passa a usar, dia a dia:
   - as horas previstas desse dia: escala, senão o horário do contrato, senão `hours_per_day`, senão 8;
   - os dias úteis do colaborador: os dias do horário mandam sobre os da empresa, e a escala decide folga ou trabalho;
   - as horas efectivas pelo regime (4 picagens = soma dos dois períodos; 2 picagens = bruto menos o intervalo previsto);
   - o **atraso** (entrada − prevista − tolerância) e a **saída antecipada**, só informativos e sem desconto (`desvioDoDia` :226);
   - avisos novos: dias com atraso, dias com escala prioritária, e dias em regime de 2 períodos sem as picagens do intervalo.
   - Novos campos devolvidos: `horario`, `diasEscala`, `diasEscalaPrioritaria`, `semPicagemIntervalo`, `horasAtraso`, `diasAtraso` e `horasSaidaAntecipada`.
2. **Regras de cálculo (inalteradas na estrutura, mas agora com `horasDoDia` variável):**
   - **Falta parcial** = horasDoDia − horas arredondadas, quando passa a tolerância e não há férias nem ausência.
   - **Falta de dia inteiro** = horasDoDia.
   - **Extra** = h − horasDoDia, se ≥ `extras_min_minutos`.
   - **Dia não útil:** conta todo como extra, ou só com autorização se `extra_nao_util_exige_autorizacao`.
   - **Compensação DIA, MENSAL ou LIMITE:** min(extra, falta[, limite]).
   - Aviso de mais de 3 faltas (art. 230.º).
   - Valorização no processamento, sem alterações: falta = horas × valor hora; extra = horas × valor hora × (1 + p1) até 30 h e × (1 + p2) acima.
3. **No EL:** `backend/app/Services/RH/ServicoAssiduidade.php:218-330` já tem arredondamento, tolerância, mínimo de extra, não útil com autorização, compensação DIA/MENSAL/LIMITE, ausências não remuneradas e o aviso do art. 230.º, com correcções do ADR-038. **Mas** `$horasDia` é fixo por colaborador (`horas_por_dia` ou 8, linha 239) e os dias úteis são os da empresa (`calendario->diaUtil`). Não há escala, horário, regime de 2 períodos, atrasos nem os avisos novos.
4. **Esforço: Médio.** Pode adaptar-se o ciclo diário existente. **Depende dos #1 e #2.**

## 4. Importação da efectividade melhorada — **PARCIAL**

1. **O que faz:**
   - Modelo Excel **já preenchido** com uma linha por colaborador activo e dia de trabalho do mês, com o horário previsto e o NIF formatado como texto, mais uma folha de colaboradores e uma de instruções (`assiduidade.js:618` `modeloImportacao`).
   - Colunas «Saida intervalo» e «Entrada intervalo» com sinónimos.
   - Índice NIF/INSS **sem zeros à esquerda**, usado só quando não é ambíguo.
   - Horas com **vírgula decimal** («7,5»).
   - Linhas do modelo deixadas em branco não contam como erro.
   - Mensagem de erro com um NIF de exemplo.
2. **Dados:** não há tabelas novas.
3. **No EL:** `ServicoAssiduidade.php:78-130` importa CSV/XLSX com chaves NIF, INSS, número e nome, e já salta as linhas totalmente vazias. Não lê as picagens do intervalo, não tem modelo pré-preenchido, não tem índice sem zeros e não converte a vírgula no campo `horas`. Uma linha do modelo com NIF mas sem horas não é ignorada.
4. **Esforço: Baixo–Médio.** O modelo pré-preenchido depende do #1 e do #2 para trazer o horário previsto.

## 5. Empréstimos a colaboradores — **FALTA**

1. **O que faz:**
   - O colaborador pede no Portal o capital, o número de prestações, o mês de início e o motivo, e vê a simulação da prestação ao vivo (`portal_ui.js:398`).
   - O pedido segue a máquina de aprovação do portal (`rh_portal_requests`, novo tipo `EMPRESTIMO`): chefia (se `exige_chefia`) e depois RH (`portal_dados.js:106` e :263).
   - Aprovado, gera-se o **plano de amortização** e um documento de autorização de desconto para assinar (`empDocumento`).
   - O RH lança o capital na **conta corrente** do colaborador e importa as prestações do mês no cálculo com o botão «Importar Empréstimos» (`app_v2.js:5406`).
   - O empréstimo pode ser cancelado enquanto nenhuma prestação tiver sido descontada. Ao reabrir o processamento, o desconto anula-se.
   - Ecrãs: RH › Empréstimos (`data-target="rh_emprestimos"`, `index_arrumado.html:254`; `emprestimos_ui.js:25`, com os separadores Lista e Configurações).
   - Lógica: `js/modules/rh/emprestimos.js` (`plano` :75, `pedir` :99, `aoDecidir` :163, `lancarNoPeriodo` :229, `anularDoPeriodo` :264, `lancarContaCorrente` :281).
2. **Dados (Dexie v1273):**
   - `rh_loans` (`rh_company_id, employee_id, numero 'EMP AAAA/NNN', capital, prestacoes, taxa_juro, valor_prestacao, inicio_desconto, motivo, estado PENDENTE_CHEFIA|PENDENTE_RH|APROVADO|RECUSADO|CANCELADO|LIQUIDADO, portal_request_id, total_juros, total_a_pagar, lancamento_ref, aprovado/liquidado/cancelado_em/por`);
   - `rh_loan_instalments` (`loan_id, employee_id, numero, month_year, capital, juro, total, saldo, estado PENDENTE|DESCONTADO|ISENTO, payroll_entry_id, descontado_em`);
   - `rh_loan_settings` (`conta_emprestimo, conta_contrapartida, journal_id, infotype_desconto_id, prestacoes_max (12), multiplo_salario_max (3), taxa_juro_padrao (0), exige_chefia`);
   - `rh_portal_requests.loan_id`.
   - **Regras de cálculo:**
     - Plano de **prestação constante (sistema francês)**: i = taxa anual / 100 / 12; prestação = C·i / (1 − (1+i)^−n). Com i = 0, prestação = C/n.
     - Juro de cada mês = saldo × i; amortização = prestação − juro. A **última prestação fecha o saldo ao cêntimo**.
     - Limites: n ≤ `prestacoes_max`; capital ≤ `multiplo_salario_max` × salário base do contrato (soma mensal das remunerações). Sem salário apurável o pedido é **recusado**. Só um empréstimo aberto de cada vez.
     - Desconto: um `payroll_entry` na rubrica `infotype_desconto_id` com valor = total da prestação do mês (substitui o valor, se se pedir). A prestação passa a DESCONTADO e o empréstimo a LIQUIDADO quando o saldo chega a 0.
     - Conta corrente: D `conta_emprestimo` / C `conta_contrapartida` pelo capital, no diário configurado (obrigatório), com o terceiro escolhido pelo NIF.
   - Permissões novas: `rh_emp_gerir`, `rh_emp_lancar`, `rh_emp_config` (`permissoes.js`).
3. **No EL:** a pesquisa por `emprestim|rh_loan` só encontra `ServicoRelatorioContas.php:319` (rubrica de balanço, sem relação com isto). O catálogo de permissões não tem `rh_emprestimos`. `ServicoPortalColaborador::TIPOS = ['FERIAS','AUSENCIA','DOCUMENTO','AGREGADO']` (linha 37). O `MotorSalarial` já trata uma linha de DESCONTO como desconto ao líquido, por isso o cálculo em si não precisa de mudanças.
4. **Esforço: Alto.** Precisa de 3 tabelas, `ServicoEmprestimos` (plano em bcmath, pedido, aprovação no portal, lançamento no período com invalidação da cache depois do commit, anulação ao reabrir, lançamento contabilístico pelo serviço de diário), do ecrã RH › Empréstimos, do separador no portal, do botão em `Calcular.tsx` e do PDF de autorização. Depende do diário de contabilidade (já existe), dos terceiros e de `ServicoFolhaSalarial::reabrir`, que tem de anular as prestações.

## 6. Suspensão e cessação do contrato — **FALTA**

1. **O que faz:** os botões Suspender, Reactivar, Cessar, Anular cessação e Ver estado na lista de contratos (`contrato_estado_ui.js`; lógica em `contrato_estado.js`).
   - **Suspender** (:136): exige o início, aceita um fim opcional e pede o fundamento do art. 267.º (trabalhador: serviço militar, maternidade, doença, prisão preventiva, pena até 1 ano, funções sindicais, cargo público, força maior) ou do art. 270.º (empregador: conjuntural, calamidade, encerramento, outra). Fica guardado no histórico `suspensoes[]`.
   - **Cessar** (:191): data, modalidade (caducidade art. 277.º, revogação art. 280.º, resolução arts. 281.º–306.º), aviso prévio (30 dias na denúncia) e uma proposta de **compensação** editável. Mostra os processamentos posteriores com lançamentos e passa o colaborador a «Não ACTIVO», com `data_cessacao`.
   - **Anular cessação** (:224): recusa se já houver outro contrato por cessar e guarda `cessacoes_anuladas[]`.
   - Editar o contrato nunca o reactiva sozinho (`app_v2.js` ≈ 4451 e 4883). A regra «1 contrato em vigor por colaborador» passa a ignorar os cessados.
2. **Dados:** `contracts.status` ACTIVO|SUSPENSO|CESSADO; `contracts.suspensao` {de, ate, motivo_codigo, motivo, artigo, lado, observacoes, registado_em/por}; `suspensoes[]`; `cessacao` {data, modalidade, motivo_codigo, artigo, aviso_previo_dias, antiguidade_anos, salario_base, compensacao_valor, compensacao_proposta, compensacao_artigo, compensacao_formula, …}; `cessacoes_anuladas[]`; `employees.data_cessacao`. Permissões novas: `contratos_suspender`, e `contratos_terminate` passa a incluir cessar e anular.
   - **Fórmulas (Lei 12/23):**
     - Antiguidade = anos completos, mais 1 se a fracção for ≥ 3 meses (art. 311.º).
     - SB = soma mensal das remunerações do contrato.
     - **ART307** (caducidades) = 0,5 × SB × anos.
     - **ART308** (despedimento por causas objectivas ou colectivo) = SB × min(anos, 5) + 0,5 × SB × max(anos − 5, 0).
     - **ART310** (rescisão com justa causa pelo trabalhador) = max(SB × anos, 3 × SB).
     - Revogação, despedimento disciplinar, denúncia e abandono: sem compensação automática.
     - A compensação **não** gera lançamento salarial: fica registada como proposta e decisão.
3. **No EL:** os estados aceites são `ACTIVO, INACTIVO, SUSPENSO` (`ContratoTrabalhoController.php:20,71`). Não há CESSADO, nem campos de suspensão ou cessação, nem fundamentos, compensação ou histórico. Existe só `ServicoContratosTrabalho::terminar` (data de fim, linha 157). A pesquisa por `cessa(c|ç)ão|contratos_suspender` não devolve nada no RH.
4. **Esforço: Médio–Alto.** Precisa de colunas JSON e estado no contrato, de catálogos de fundamentos, de um serviço de actos com auditoria, do cálculo da compensação em bcmath, da UI e das permissões. É pré-requisito do #7 e do separador «Contrato» do portal.

## 7. Contrato em vigor mais rigoroso — **PARCIAL**

1. **O que faz:** `RegrasSalariais.contratoActivo` (`regras_salariais.js:95`) passa a devolver só o contrato ACTIVO dentro das datas, ou SUSPENSO **fora** do período da suspensão. Exclui os CESSADOS. A importação dos lançamentos dos contratos (`app_v2.js:5547`) usa-o e informa quantos contratos ficaram fora. O novo `contratoVigente` (:107) mostra o contrato mesmo suspenso ou cessado, só para consulta (ficha `ficha_colaborador.js:470`, declaração do portal `portal_dados.js:344`).
2. **Dados:** os do #6.
3. **No EL:** `ServicoFolhaSalarial::contratoDoMes` (linhas 704-716) já exige ACTIVO e as datas válidas no mês, o que é equivalente para ACTIVO/INACTIVO. Um contrato SUSPENSO fica sempre de fora, mesmo fora do período, porque não há período de suspensão. Também não há a variante «vigente» para a consulta, e o colaborador suspenso não consegue pedir a declaração.
4. **Esforço: Baixo**, depois do #6.

## 8. Minuta do contrato de trabalho (Lei 12/23) — **PARCIAL**

1. **O que faz:** `generateContractPDF` (`app_v2.js` ≈ 4999-5120) foi reescrita.
   - Cita a Lei 12/23 e inclui os dados do art. 13.º/1: morada repartida, naturalidade, documento e validade, INSS, CRC da empresa.
   - Indica «por tempo indeterminado» ou «a termo certo» e mostra um **aviso vermelho** se o contrato a termo não tiver `motivo_termo` (art. 13.º/2-3).
   - Período experimental `periodo_experimental_dias`, por omissão 60 dias sem termo e 30 a termo (art. 18.º).
   - Subsídios de férias e de Natal ≥ 50 %, ou o `subsidio_ferias_pct`/`subsidio_natal_pct` da empresa (art. 238.º).
   - Horas por semana = min(44, horas/dia × 5,5).
   - Objectivo geral da função e responsabilidades numeradas; prevalecem o título e as responsabilidades do cargo da estrutura orgânica, se existirem.
2. **Dados:** `contracts.motivo_termo`, `contracts.periodo_experimental_dias`, `companies.subsidio_ferias_pct/subsidio_natal_pct`, `roles.objectivo_geral/responsabilidades`, `org_positions.responsabilidades`.
3. **No EL:** existe `frontend/src/modulos/rh/contratos/contratoAssinatura.ts` com a minuta **antiga**: período experimental fixo de 60 dias, «termo incerto/certo», sem a lei citada, sem o aviso do motivo, sem os 50 %, só a `descricao` da função. Não foram encontrados os campos `motivo_termo` nem `periodo_experimental` no backend.
4. **Esforço: Baixo–Médio.** São campos novos no contrato e na empresa e a reescrita do template. Depende do #10.

## 9. Remunerações contratualizáveis — **EXISTE**

O LN (`app_v2.js:4370`) passa a aceitar todas as rubricas do tipo VENCIMENTO, em vez de uma lista fixa de nomes. O EL já faz isto: `ServicoContratosTrabalho::remuneracoes` (linhas 180-196) aceita qualquer `tipo === 'VENCIMENTO'`.

## 10. Descritivo das funções e herança pelos cargos — **PARCIAL**

1. **O que faz:**
   - A função (RH › Funções) ganha **objectivo geral** e **responsabilidades**; o antigo `description` é migrado para `responsabilidades` e mantido como espelho (`app_v2.js:4011`, `funcaoObjectivo` :4013).
   - Mostra os cargos que herdam a descrição e não deixa apagar uma função usada por cargos.
   - Na estrutura orgânica, um cargo **novo** tem de ter função (`estrutura_dados.js:168`); os cargos antigos sem função continuam editáveis.
   - O formulário do cargo mostra o descritivo herdado, e as responsabilidades próprias do cargo passam a opcionais (`estrutura_ui.js:440-466`).
   - Novo manual `docs/manual_estrutura_organica.html`.
2. **Dados:** Dexie v1272 `roles.objectivo_geral` e `roles.responsabilidades`, com upgrade a partir de `description`.
3. **No EL:** `Funcoes.tsx` só tem `descricao` («Descrição da Função (Obrigações)») e não aparece `objectivo_geral` em lado nenhum. `ServicoEstruturaOrg.php:177` aceita um cargo **sem** `cargo_funcao_id` se tiver título, ou seja, sem a nova obrigatoriedade. Não há painel do descritivo herdado nem bloqueio ao apagar uma função usada.
4. **Esforço: Baixo–Médio.**

## 11. Produtividade — base «prémio» e explicação do cálculo — **PARCIAL**

1. **O que faz:** cada métrica ganha rótulos próprios para quantidade, preço, mínimo e máximo, a fórmula e um exemplo (`produtividade.js`, METRICAS). Nova `base_calculo`:
   - UNITARIO: valor = quantidade considerada × preço;
   - **PREMIO**, só na métrica OBJECTIVO: valor = prémio a 100 % × (% considerada / 100) (`calcularValor` :149).
   - Os itens antigos valem como UNITARIO.
   - `explicarCalculo` (:155) mostra a conta por extenso e os cortes de mínimo e tecto.
   - Os registos ganham `origem_chave` e `fonte`, para a ponte com os projectos.
   - A UI aplica as tarefas `rh_prod_registar`, `rh_prod_periodo` e `rh_prod_config` por separador e por botão (`data-perm-req`).
2. **Dados:** `rh_productivity_items.base_calculo`; registos `.origem_chave`, `.fonte`.
3. **No EL:** `ServicoProdutividade.php` tem as 5 métricas e calcula sempre quantidade × preço (linha 223). Não há `base_calculo`, PREMIO nem `origem_chave`. As permissões `rh_prod_*` já existem no catálogo.
4. **Esforço: Baixo.**

## 12. Produtividade a partir da execução de projectos — **FALTA**

1. **O que faz:** `js/modules/rh/produtividade_projectos.js` (novo, 2026-10-11) recolhe os dados de três fontes:
   - **HORAS** ← `project_timesheets`;
   - **QUANTIDADE** ← `meta_execucoes` das tarefas, com normalização de unidades e sinónimos (m², m³ e outros);
   - **TAREFAS** ← tarefas com `concluida_em` dentro do período.
   - Sugere o item de produtividade, valida a elegibilidade (o item tem de estar no contrato activo) e o período aberto, e atribui a quantidade ao responsável da tarefa. Reenviar não duplica, por causa da `origem_chave`.
   - Aparece no separador «Execução de projectos» e no assistente a partir do projecto (`prodProjectosAssistente`).
2. **Dados:** não cria tabelas; escreve em `rh_productivity_records` com `origem_chave` e `fonte`.
3. **No EL:** não foi encontrada nenhuma ponte (a pesquisa por «produtividade» em `Services/Projetos` não devolve nada). Já existem as folhas de horas (`ServicoExecucaoProjetos`, `FolhaHorasProjeto`), por isso a fonte HORAS é viável.
4. **Esforço: Médio–Alto.** Depende do #11 (`origem_chave`) e das metas por quantidade dos projectos (área de Projectos do LN, também nova).

## 13. Aplicar itens de produtividade em massa a contratos — **FALTA**

1. **O que faz:** `js/modules/rh/contratos_produtividade.js` (novo) aplica um ou mais itens, com o preço do item ou um preço próprio, aos contratos escolhidos. Há três modos: acrescentar, substituir ou retirar. Ignora os inactivos e grava em `contracts.produtividade=[{item_id, preco_unitario|null}]`. Exige `contratos_new`.
2. **Dados:** não cria tabelas.
3. **No EL:** o contrato já aceita `produtividade` (`ServicoContratosTrabalho.php:57-64`), mas a massa (`routes/api/ronda2_g2.php:41` `contratos/massa`, `aplicarRubricasEmMassa`) só trata rubricas.
4. **Esforço: Baixo–Médio.**

## 14. Portal: separadores Escala, Contrato e Empréstimos — **FALTA**

1. **O que faz:** o portal ganha os separadores **Escala** (consulta da escala do mês, com o horário do contrato nos dias sem escala, `portal_ui.js:501`), **Contrato** (dados do contrato vigente, incluindo o estado, `:551`) e **Empréstimos** (pedir com simulação, acompanhar e ver o plano, `:398`). `PortalColaborador` passa a expor `etapasPara` e `criarPedido`.
2. **Dados:** os dos #1, #5 e #6.
3. **No EL:** `frontend/src/modulos/rh/Portal.tsx:43-51` tem Pedidos, Recibos, Faltas, Avaliação, Equipa, Aprovações e Os meus dados. Não há escala, contrato nem empréstimos («contrato» não aparece em `Portal.tsx` nem em `ServicoPortalColaborador.php`).
4. **Esforço: Médio.** Depende dos #1, #5 e #6.

## 15. Portal autónomo (`portal.html`) — **FALTA**

1. **O que faz:** uma página própria, acessível por um link no site da empresa.
   - Login com a mesma autenticação do ERP (`SessaoServico.autenticar`), seguido da escolha da empresa, que **não** volta a pedir as credenciais.
   - Recusa o acesso a quem não está ligado a uma ficha de colaborador nem tem aprovações pendentes.
   - Sem o menu do ERP (`window.MODO_PORTAL = true` impede o arranque do ERP completo).
   - Retoma a sessão guardada e tem um botão Sair.
   - Ficheiro: `portal.html:1-292`.
2. **Dados:** não cria tabelas.
3. **No EL:** `frontend/src/App.tsx:20-24` só tem o `LayoutPrincipal` (`/`, `m/:modulo/:ecra/*`, `dados-pessoais`). Não há rota ou layout de portal isolado.
4. **Esforço: Médio.** Uma rota `/portal` com layout mínimo e a mesma autenticação (Sanctum), escolha da empresa, guarda de acesso «tem colaborador ou aprovações», e reaproveitamento dos componentes de `Portal.tsx`. Pela OWASP: limitar as tentativas de login e não expor dados sem sessão.

## 16. Cartas de ordem de pagamento — **PARCIAL**

1. **O que faz:** em Tesouraria › Cartas de Pagamento (`teso_cartas`, `index_arrumado.html:295`; `cartas_pagamento.js`, `cartas_pagamento_ui.js`):
   - A carta **nasce sempre de um movimento de tesouraria** do tipo PAGAMENTO (`elegibilidade` :137), com a conta financeira 42/43 e o **IBAN no plano de contas** (banco, balcão, conta, IBAN, SWIFT e titular).
   - Tipo **TERCEIRO**: os beneficiários são as linhas do movimento, agregadas por terceiro, com a conta em `third_party_bank_details` (pode escolher-se outra).
   - Tipo **SALARIOS**: os colaboradores do período ligado ao movimento, com o líquido e o IBAN. Quem já entrou noutra carta do mesmo período aparece assinalado e desmarcado. O mesmo mês pode ter várias cartas.
   - Numeração `CP AAAA/NNN` por empresa e por ano.
   - Texto padrão por empresa e por tipo (`payment_letter_templates`), com variáveis e editável em cada carta.
   - Os dados do banco e do beneficiário ficam fotografados na carta. A carta pode ser anulada e impressa, e o valor sai por extenso.
2. **Dados (v1271):** `payment_letters` (+ `tipo, numero, sequencia, ano, estado, treasury_doc_id, bank_*, currency, assunto, corpo, signature_*, local`), `payment_letter_items` (+ `third_party_id, nome, nif, banco, numero_conta, titular, referencia`), `payment_letter_templates`, `third_party_bank_details`. A classe 4 do plano de contas ganha os dados bancários só nas contas 42/43. Permissões novas: `teso_carta_emitir` e `teso_carta_modelo`.
3. **No EL:** `backend/app/Services/RH/ServicoPagamentoSalarios.php` emite cartas de **salários** a partir do **período validado**: o IBAN é fixado, um colaborador só entra numa carta por período, sem IBAN a carta é recusada, e daí gera-se o pagamento na tesouraria. Tabelas `cartas_pagamento_bancario` e `itens_carta_pagamento` (migração `2026_09_30_100700_criar_tabelas_rh.php:239-271`). **Faltam:** o fluxo inverso (carta a partir de um movimento), as cartas a terceiros, `third_party_bank_details`, o modelo de texto, a numeração CP, a anulação com estado, a fotografia dos dados do banco emissor e os dados bancários nas contas 42/43 do plano. O IBAN só existe em `meios_pagamento` e nas coordenadas dos colaboradores. O catálogo não tem `teso_cartas`.
4. **Esforço: Médio–Alto.** O tipo salários pode reaproveitar `ServicoPagamentoSalarios`; é preciso alinhar a regra «a carta nasce do movimento» com a ordem actual do EL (carta → pagamento), o que pede uma decisão (ADR).

## 17. Repartição do custo salarial por unidades de negócio — **FALTA**

1. **O que faz:** na integração contabilística dos salários (`app_v2.js:6478-6554`), cada colaborador com `unidades_alocadas` tem o custo, os impostos e o líquido divididos pelas unidades conforme o critério (`ReparticaoUN.pesos`). A última parte absorve o arredondamento, para o lançamento ficar equilibrado. Sem alocação, vai tudo para a unidade da ficha.
2. **Dados:** `employees.unidades_alocadas` e `bu_allocation_keys` (v1275; critérios PERCENTAGEM, PARTES_IGUAIS, COLABORADORES, VOLUME_NEGOCIOS, AREA, PERSONALIZADO). Ficheiros novos `js/modules/configuracoes/alocacao_un.js` e `reparticao.js`.
3. **No EL:** `ServicoFolhaSalarial::contabilizar` agrega por `{conta}|{dc}|{unidade_negocio_id}|{centro_custo_id}` com **uma** unidade por colaborador (linhas 553-554). A pesquisa por `unidades_alocadas|bu_allocation|ReparticaoUN` não devolve nada.
4. **Esforço: Médio.** Depende das chaves de repartição, que são da área de Configurações e estão fora do âmbito deste relatório.

## 18. Motor salarial (IRT, INSS, faltas, horas extra) — **EXISTE, sem alterações no LN**

`js/engine_v2.js` não tem diferenças entre os legados. As fórmulas do manual novo (`docs/manual_rh_salarios.html`, «Valor dia e valor hora» … «Líquido») são as que já estavam:

- **Valor dia** = soma mensal das rubricas «base dia/hora» ÷ dias contratados; **valor hora** = valor dia ÷ horas por dia.
- **Faltas** = horas × valor hora (dia inteiro = horas/dia do contrato). Reduzem a base do INSS e a do IRT. «Dias Trab.» abaixo dos contratados gera «Desc. Falta» = valor ÷ dias contratados × dias em falta.
- **Horas extra** = h1 × valor hora × (1 + p1) + h2 × valor hora × (1 + p2), com p1 = 50 %, limite 30 h e p2 = 75 %, configuráveis (`regras_salariais.js:53-63`).
- **INSS:** 3 % do trabalhador e 8 % da empresa; 0 % para aposentados e avençados. A base são as rubricas sujeitas a INSS, menos as faltas.
- **IRT normal:** base = ilíquido − INSS − isenção do subsídio de alimentação (≤ 30 000) − isenção do subsídio de transporte (≤ 30 000) − faltas; aplica-se a tabela progressiva (fixo + (base − excesso) × taxa); uma base negativa conta como 0.
- **Avençado:** 6,5 % do ilíquido, sem INSS.
- **Líquido** = ilíquido − INSS − IRT − descontos.

**No EL:** `backend/app/Services/RH/MotorSalarial.php` implementa as mesmas fórmulas: a tabela de IRT das linhas 24-36, configurável por `ServicoTabelaIRT`; `he` com p1 50, limite 30 e p2 75 (linha 165); `taxa_avencado` 6.5 (linha 199); INSS 3 % e 8 % configuráveis; e a isenção de 30 000 Kz. Os modos LEGADO e ATUAL estão em ADR-014/017/036. O modo ATUAL difere de propósito do legado: a isenção é marcada por flag e conta sobre o valor pago, a base do INSS é ≥ 0, o avençado paga sobre o valor efectivamente pago, e há arredondamento por componente. **Não há nada a portar no motor.** As mudanças com efeito no salário são todas de dados de entrada (#3, #5, #7, #11, #17).

---

## Menus, ecrãs e permissões novos no LN (para o catálogo do EL)

| `data-target` / entidade | Origem no LN | No EL (`catalogo.json` / `ecras.ts`) |
|---|---|---|
| `rh_emprestimos` (RH › Empréstimos) | `index_arrumado.html:254`; `permissoes.js` E('rh_emprestimos') com `rh_emp_gerir`, `rh_emp_lancar`, `rh_emp_config` | Ausente |
| `teso_cartas` (Tesouraria › Cartas de Pagamento) | `index_arrumado.html:295`; `teso_carta_emitir`, `teso_carta_modelo` | Ausente |
| `contratos_suspender` (nova); `contratos_terminate` passa a incluir `contratoCessar`/`contratoAnularCessacao` | `permissoes.js` | `contratos_terminate` existe; `contratos_suspender` ausente |
| `rh_prod_registar` alargada a `prodProjectosAssistente`/`prodProjectosEnviar` | `permissoes.js` | `rh_prod_registar` existe (sem a ponte) |
| Separador «Escala do mês» dentro de `rh_assiduidade` | `assiduidade_ui.js` | `rh_assiduidade` existe, sem o separador |
| `portal.html` (fora do menu) | raiz do LN | Ausente |

## Ordem sugerida de implementação no EL

1. **#6 e #7**, Suspensão/Cessação e contrato em vigor: afectam directamente quem é processado.
2. **#2, #1, #3 e #4**, horário, escala e apuramento: é um bloco único, que muda as horas de falta e extra.
3. **#5 e #14**, Empréstimos e o portal: um desconto novo no salário.
4. **#10 e #8**, funções e minuta do contrato: são legais e documentais.
5. **#11, #13 e #12**, produtividade.
6. **#16, #17 e #15**, cartas, repartição por unidades e portal autónomo.

Cada um destes ecrãs novos tem de seguir as regras permanentes: invalidar a cache depois do commit, OWASP Top 10 (autorização por tarefa, validação no servidor, limite de tentativas no login do portal) e bcmath nos valores.
