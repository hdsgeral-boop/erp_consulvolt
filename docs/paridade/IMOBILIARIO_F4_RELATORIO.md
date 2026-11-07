# Gestão Imobiliária — Onda 3 (F4 Parqueamento e áreas comuns): relatório

Data: 2026-11-07 · ramo `paridade-bd-front` · commit local único, sem push e sem deploy. Base: `IMOBILIARIO_PLANO.md` (§2, §3, §7 F4, §8: usadas as recomendações P3, P4 e P7), `IMOBILIARIO_INVENTARIO.md` (A: parqueamento e áreas comuns; C: reservas pagas) e o código legado (só leitura) através dos anexos A e C.

## 1. O que ficou funcional

| Área | Entrega |
|---|---|
| **Vagas** | São fracções com natureza `ESTACIONAMENTO` (a mesma tabela `fracoes`; entram nos contratos, na rentabilidade e no mapa como qualquer outra). Gerar por piso (`G-101`, `G-201`, ao R/C `G-01`; repetir não duplica e conta as repetidas; limite de 500), criar, editar e apagar. |
| **Afectação** | Afectar vagas a uma fracção (vão junto no contrato dela) e libertar; mapa por piso do mais alto para o mais baixo com a ocupação (contrato próprio ou por arrastamento da fracção-mãe), pesquisa, KPIs (lugares, livres, ocupados, visitas e cargas, taxa do parque), lugares livres, avisos e impressão/PDF/Excel pelo motor comum. |
| **Veículos** | Ficam no contrato (não na vaga): matrícula normalizada (`LD-12-34-AB`), marca, modelo, cor, dístico, porte, condutor e lugar; conflito de porte no mapa; matrícula repetida noutro contrato em vigor do imóvel pede confirmação. |
| **Encargos de vagas (ligação à F3)** | `GET parque/encargos-propostos` devolve, por vaga afecta, o valor da renda de referência e se vai incluída ou acrescida; o formulário do contrato ganhou o botão «Propor as vagas afectas», que acrescenta os encargos com `origem = PARQUE` (a F3 já gravava a coluna). O contrato também aceita agora, no ecrã, a vaga autónoma. |
| **Espaços comuns** | Tipo, regime (uso livre, reserva gratuita, reserva paga), horário, dias, duração mínima e máxima, antecedência, limite por fracção e mês, aprovação, preço por hora, período, dia ou fixo, caução, prazo de pagamento, escala de cancelamento, regulamento. Activar e inactivar; apagar só sem reservas. |
| **Reservas** | Verificação em directo (dia de funcionamento, horário, duração, antecedência, conflito semiaberto, contrato em vigor, limite mensal, capacidade), reserva interna ou por fracção, dívida de rendas com autorização gravada com o nome, estados com transições e permissões por estado, calendário por dia, disponibilidade do dia, resumo e avisos. |
| **Parte financeira** | Factura (`ServicoDocumentosVenda::emitir`, idempotente por reserva), recibo (`ServicoRecibosVenda::criar`) parcial e total, confirmação automática quando tudo está pago, vistoria de entrada e de saída, caução com ledger e lançamentos equilibrados, retenção por danos facturada à parte e compensada com a caução, previsão e cancelamento com escala, nota de crédito, caducidade (tarefa agendada e ao abrir o ecrã). |
| **Ecrãs** | `imo_parque` (Parqueamento) e `imo_areas` (Áreas Comuns) substituem as duas linhas «em construção» de `ecras.ts`. Responsivos (modais e gavetas adaptativos), acessíveis (botões com nome, estado sempre em texto além da cor, `aria-live` no painel do dia), na pesquisa global (regenerada). O separador «Facturação e contas» ganhou o artigo das reservas. |

## 2. Migração nova (a ensaiar na cópia da produção)

`backend/database/migrations/2026_12_18_090000_imobiliario_onda3_parqueamento_reservas.php`: tabelas novas, aditiva e idempotente (`hasTable`, `IF NOT EXISTS`, CHECK por `pg_constraint`), RLS (`IsolamentoRls`), GRANT a `erp_app`, `down()` sem efeito. Não exige `btree_gist`: os conflitos de horário verificam-se no serviço com o espaço bloqueado (`FOR UPDATE`).

| Tabela | Notas |
|---|---|
| `veiculos_contrato` | UNIQUE `(contrato, matrícula)`; CHECK de porte; vaga `ON DELETE SET NULL`. |
| `espacos_comuns` | UNIQUE `(empresa, imóvel, lower(nome))`; CHECK de tipo, regime, unidade, valores (`cancel_pct` 0–100, prazo ≥ 1) e horas `HH:MM`. |
| `reservas_espaco` | UNIQUE parcial de `venda_id`; CHECK de estado, horas (`fim > início`), valores e caução (`recebida ≤ devida`, `devolvida + retida ≤ recebida`), `interna = (fracao_id IS NULL)`. |
| `vistorias_reserva`, `historico_reserva_imo` | vistorias (a última de cada momento vale) e trilho imutável dos eventos. |
| `movimentos_caucao_reserva` | ledger imutável, UNIQUE `(empresa, chave)` (idempotência) e UNIQUE parcial da factura da retenção. |

**Única alteração a tabela existente (aditiva e idempotente):** `configuracao_imobiliario.produto_reserva_id` (`ADD COLUMN IF NOT EXISTS`, FK a `produtos` restrict). Nenhuma outra tabela existente foi alterada; as vagas usam as colunas `park_*` que a Onda 1 já criara.

## 3. Decisões e correcções do legado (P3: corrigir, com o legado como fixture)

- **Reatribuição silenciosa**: afectar uma vaga que já é de outra fracção recusa (409 `PARQUE_JA_AFECTA`, com o nome da fracção); só com confirmação (`reatribuir`) passa, com aviso para rever os contratos. A vaga de uso comum, a de outro imóvel e a que tem contrato próprio vivo recusam-se; libertar conta só as vagas que existem (o legado contava os ids recebidos).
- **Editar uma vaga** já não recalcula o código nem perde bloco, número, andar e ordem; só se toca no que o pedido traz (limpar a altura grava vazio, omitir não mexe). Uma vaga em contrato vivo não muda para uso comum nem se apaga.
- **Veículos** gravam cor e dístico (o legado perdia-os ao gravar pelo ecrã).
- **Reserva paga com aprovação** vai a «a aguardar pagamento» ao aprovar (o legado saltava-o e a reserva ficava «aprovada» sem nunca ser facturada nem confirmada).
- **Prazo de pagamento** conta desde que a reserva fica a aguardar pagamento (o legado só o marcava ao facturar e o horário ficava preso para sempre); `min(agora + horas do espaço, início do evento)`.
- **Verificação em edição** já não conta a própria reserva como conflito (legado: `imoReservaVerifica` passava `id = null`).
- **Espaço pago de preço fixo** também exige valor (o legado deixava gravar 0 Kz); a «conta de proveito», que nunca teve consumidor, desaparece: a receita vai pelo artigo de serviço, que traz a sua conta. O espaço não muda de imóvel depois de ter reservas.
- **Reserva facturada** ou com caução recebida não se edita nem apaga (cancela-se).
- **Caducidade**: só cancela o que não recebeu nada e emite a **nota de crédito** da factura que ficou por pagar (o legado deixava-a emitida); com pagamento parcial ou caução recebida não cancela em silêncio (fica sinalizada, com aviso); se afinal está tudo pago, confirma. Repetível e por empresa (comando `erp:imobiliario:caducar-reservas`, de 15 em 15 minutos, `onOneServer`).
- **Cancelamento** é acção própria (`imo_reserva_cancelar`, lacuna do legado, sensível): previsão pela escala (7 dias antes devolve 100 %, depois `cancel_pct`: 3 dias antes, recebido 12 000 → devolve 6 000). Factura por pagar: nota de crédito total, uma só vez. Com dinheiro recebido regista o reembolso previsto e deixa a nota de crédito **pendente** (o próprio Vendas exige anular primeiro o recibo de uma factura paga); a caução recebida fica por devolver, sinalizada.
- **Caução** (P4): ledger próprio da reserva, contas da configuração do módulo (mesmo passivo da caução dos contratos; nada de códigos PGC no código). Recebida D disponibilidades / C passivo; devolvida o inverso. **Retida** só com vistoria de saída que a prove: factura ao reservante o valor retido (artigo das reservas), contabiliza-a e compensa-a (D passivo / C conta do cliente), ficando a factura regularizada; só essa parte é receita. A retenção é definitiva (corrige-se com nota de crédito em Vendas, não por anulação); a devolução e o recebimento anulam-se com estorno.
- **Recibo**: a factura é contabilizada ao receber (o recibo exige-o); contabilizar o recibo fica na rotina normal de Vendas, como na F3.
- **Permissões e segregação** (catálogo editado à mão): duas tarefas novas, `imo_reserva_receber` (sensível) e `imo_reserva_vistoria` (o legado juntava-os a facturar e à caução), e três pares novos: `aprovar × facturar`, `facturar × receber` e `vistoria × caução` (agora 9 pares do módulo). Perfis: o Gestor Imobiliário recebe `receber` e `vistoria`; o Responsável de Cobrança recebe `caução`; nenhum perfil acumula um par.
- **Só Kz**, artigo das reservas **com IVA 0 %** (decisão P7/P9: o tratamento do IVA destes serviços fica por validar pelo contabilista; o ERP recusa um artigo com IVA).

## 4. Fora de âmbito (documentado, não começado)

Ordem de trabalho para reparar os danos da reserva (é da F5, Obras: o custo dos danos fica na vistoria); modelos de documentos sobre a reserva; ligação do mapa de parqueamento à rentabilidade da fracção (F7); alertas agendados e notificações das reservas (F8; os avisos são calculados à vista); exportações Excel dedicadas além das do motor comum; IVA nas reservas; nota de crédito parcial automática (o próprio Vendas exige anular primeiro o recibo); as tabelas novas não entraram no `DICIONARIO_DADOS.md` (como as das Ondas 1 e 2). Veículos só nos contratos em que a empresa é senhoria; reserva por fracção só com contrato de arrendamento activo (lado senhorio).

## 5. Testes e verificações (resultado real, lido do output)

- **PHPUnit** (`docker exec erp_app_e2e php artisan test --filter=…`, um de cada vez): `ImobiliarioParqueamentoTest` **12 testes / 286 asserções**; `ImobiliarioReservasTest` **23 / 926**; `ImobiliarioParqueamentoCalculadorasTest` (unidade, fixtures à mão) **8 / 74**; Ondas anteriores verdes: `ImobiliarioCadastroTest` **21 / 947** (contagens do catálogo 13/43/21/9/5), `ImobiliarioModelosTaxasTest` **9 / 148**, `ImobiliarioCalculadorasTest` **15 / 90**, `ImobiliarioContratosRendasTest` **30 / 884**.
- **Números à mão**: 2,5 h a 4 000/h = 3 unidades = 12 000 (`3 × hora (2.5 h)`); período de 4 h com 3 h = 1 período, com 4,5 h = 2; dia e preço fixo uma só vez; meia hora cobra a unidade; cancelar a 3 dias de 7 com 50 % devolve 6 000 de 12 000, a 7 dias devolve tudo, depois da data 30 % de 12 000 = 3 600, meio cêntimo 333,33 × 50 % = 166,67; prazo 48 h (20/03 10:00 → 22/03 10:00) e limitado ao início do evento; limite mensal por fracção; dívida 85 000 (100 000 − 15 000 de Imposto Predial retido); caução 20 000 = 15 000 devolvidos + 5 000 retidos; 10 h livres no dia (08–10, 12–14, 16–22); taxa do parque 2/5 = 40 %.
- **Idempotência e isolamento**: facturar (1 venda), recebimento (nunca passa do que falta), caução (mesma chave, 1 movimento e 1 factura da retenção), caducidade repetida (1 nota de crédito), migração duas vezes; acesso cruzado por empresa a vagas, mapa, veículos, espaços, reservas e todas as acções financeiras (404); 403 antes de 422 em todas as acções; restrições da base de dados (UNIQUE da matrícula, CHECK do porte) e RLS nas 6 tabelas.
- **Varredores, todos verdes**: `OwaspControloAcessoTest` (sem entradas novas em `SEM_PERMISSAO_POR_DESENHO`), `AcessoCruzadoGeradoTest`, `ConsultasPorEndpointTest`, `EntradasMaliciosasListagensTest`, `ContaRootTest`; também `FkPerfisModelosTest`, `CatalogoModulosPlanoTest`, `MenuConfigContaSubscricaoTest` e `IsolamentoRlsContratoTest`. `ConsultasPorEndpointTest`: o tecto próprio de `GET api/sistema/copias/exportar` passou de 480 para 500 (uma consulta por tabela; mede 487 com as seis tabelas novas). `vendor/bin/pint --test` limpo nos ficheiros da fase.
- **Vitest** (`--maxWorkers=3`): `logicaParque.test.ts` 11, `Parqueamento.test.tsx` 10, `AreasComuns.test.tsx` 12 (33 novos); `src/modulos/imobiliario` + `src/layout` + `src/componentes/icones` + `semAutenticacaoNoNavegador`: 22 ficheiros / 225 testes verdes; `npx tsc --noEmit` limpo; `npm run build` feito; índice de pesquisa regenerado.

## 6. Notas para o coordenador

1. Ensaiar a migração `2026_12_18_090000` na cópia da produção (tabelas novas e uma coluna anulável).
2. A empresa tem de indicar o artigo das reservas (IVA 0 %) e a conta do passivo das cauções em Modelos e Taxas › Facturação e contas; sem isso o ERP recusa e diz o que falta. As contas e o tratamento do IVA devem ser validados pelo contabilista; nada foi verificado contra a lei.
3. Correr `PerfisActualizarModelosCommand` para propagar as tarefas novas aos perfis já criados (Gestor Imobiliário e Responsável de Cobrança).
4. O comando `erp:imobiliario:caducar-reservas` está no agendador (15 em 15 minutos); em produção o `erp_scheduler` tem de estar a correr.
5. Os testes backend dependem do cabeçalho de data fixa (`travelTo 2026-03-20`); a base `erp_app_e2e` foi migrada. Num novo ambiente de teste de interface, o antd usa o mesmo id para o título de todos os diálogos: com um diálogo sobre outro (gaveta e modal), acha-se o modal pelo título (ver `AreasComuns.test.tsx`).
