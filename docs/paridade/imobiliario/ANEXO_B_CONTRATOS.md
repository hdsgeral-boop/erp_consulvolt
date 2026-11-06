# Anexo B — Contratos, rendas, modelos de documentos e subarrendamento

> Anexo de `docs/paridade/IMOBILIARIO_INVENTARIO.md`. Notas de leitura integral do legado `v_boss/New/v2/js/modules/imobiliario` (evidência `ficheiro:linha`; abreviaturas definidas no próprio texto). Produzidas em 2026-11-06 por leitura, sem alterar código. Texto original dos leitores, não reescrito; as dúvidas e lacunas declaradas aqui são as oficiais.

# IMO-B — Inventário: contratos, rendas, modelos, subarrendamento (legado v_boss/New/v2)

Base: C:\xampp\htdocs\ERP_CONSULVOLT_MEU\v_boss\New\v2\js\modules\imobiliario\
Abreviaturas: CT = imobiliario_contratos.js (1157 l.), CU = imobiliario_contratos_ui.js (1371 l.), TP = imobiliario_templates.js (640 l.), SB = imobiliario_subarrendamento.js (308 l.), SU = imobiliario_subarrendamento_ui.js (236 l.). Todos os 5 ficheiros lidos na íntegra. Extra consultados (só para esquema/permissões/rotas): js/db_v2.js:1255-1290, js/permissoes.js:73-103, js/core/modulos_registo.js:19, js/app_v2.js:1089-1092.

Armazenamento legado = IndexedDB (Dexie) por empresa (`imo_company_id`). Tudo é feito no cliente; não há servidor. Ids numéricos auto-incremento (`++id`), mas "gravados ora como número ora como texto" (CT:27-28) -> comparar sempre com `String(a)===String(b)` / `parseInt`.

---------------------------------------------------------------------
## 1. COLECÇÕES / TABELAS E ESQUEMA

Índices Dexie (db_v2.js:1264-1267 e 1280-1281):
- imo_leases: `++id, imo_company_id, numero, unit_id, property_id, terceiro_id, estado, tipo, posicao, parent_lease_id, [imo_company_id+estado], [imo_company_id+unit_id], [imo_company_id+posicao]`
- imo_rents: `++id, imo_company_id, lease_id, periodo, estado, vencimento, sale_id, treasury_id, posicao, [imo_company_id+periodo], [imo_company_id+estado], [lease_id+periodo], [imo_company_id+posicao]`
- imo_templates: `++id, imo_company_id, tipo, activo, [imo_company_id+tipo]`
- imo_settings: `++id, imo_company_id`
- Migração v1292 (db_v2.js:1282-1285): todo contrato/renda sem `posicao` passa a 'SENHORIO'.
Os restantes campos não são indexados (JSON livre).

### 1.1 imo_leases (contrato) — campos efectivamente gravados por `gravarContrato` (CT:305-505)
| campo | tipo | obrig./regra | omissão / normalização | evidência |
|---|---|---|---|---|
| id | int auto | — | — | CT:500-501 |
| imo_company_id | int | — | `cid()` = parseInt(window.activeCompanyId)||0 | CT:485 |
| numero | string | gerado na criação, imutável na edição | `PREF AAAA/NNNN` | CT:496, 257-269 |
| tipo | enum | — | se inválido -> 'ARRENDAMENTO' | CT:357 |
| posicao | enum SENHORIO/INQUILINO | derivado do tipo | `posicaoDoTipo(tipo)`: TOMADA->INQUILINO, resto->SENHORIO | CT:44, 358 |
| ambito | enum FRACCAO/IMOVEL/PARCIAL | — | inválido -> 'FRACCAO' | CT:312 |
| unit_id | int | fracção principal (=primeira de `unidades`) | parseInt | CT:324, 344 |
| unidades | int[] | lista de fracções abrangidas (sempre lista) | FRACCAO: [unit_id]; IMOVEL: todas as arrendáveis; PARCIAL: escolhidas | CT:325, 331, 341 |
| property_id | int | forçado ao do imóvel da fracção principal | `u.property_id` | CT:484 |
| terceiro_id | int | OBRIGATÓRIO (inquilino se SENHORIO; senhorio a quem paga se INQUILINO) | parseInt | CT:363-364 |
| inquilino_morada / inquilino_doc / inquilino_contacto | string | opcionais; "retrato" da outra parte na assinatura (a ficha do terceiro não os guarda) | txt | CT:367-369 |
| parent_lease_id | int/null | obrigatório se SUBARRENDAMENTO; null nos outros | CT:411, 392, 431 |
| permite_sub | 0/1 | só TOMADA; senão 0 | CT:391, 395 |
| conta_custo / conta_retencao / conta_financeira | string (código plano) | TOMADA: conta_custo obrigatória; conta_retencao obrigatória se retencao_ip !== false; conta_financeira opcional (pedida no pagamento). Não-TOMADA: '' | txt | CT:380-390, 394 |
| inicio | date ISO | obrigatório | — | CT:437-438 |
| fim | date ISO ou '' | opcional (''=sem termo); se existir tem de ser > inicio | — | CT:439-440 |
| fim_real | date | preenchido ao terminar | — | CT:610-611 |
| motivo_saida | string | preenchido ao terminar (obrigatório nesse acto) | — | CT:611 |
| renda | decimal(2) | obrigatório > 0 | `cent()` | CT:442-443 |
| periodicidade | enum | — | inválido -> 'MENSAL' | CT:445 |
| dia_vencimento | int 1..28 | — | `min(max(1, round(num||1)),28)` | CT:446 |
| antecipada | bool | — | default true (só `=== false` é false) | CT:447 |
| finalidade_fiscal | enum | — | inválido -> 'HABITACAO' | CT:448 |
| isencao_ip | enum NAO/METADE/TOTAL | — | inválido -> 'NAO' | CT:449 |
| retencao_ip | bool | — | default true | CT:450 |
| selo_encargo | enum SENHORIO/INQUILINO/ISENTO | — | default 'SENHORIO' | CT:451 |
| caucao | decimal | <= 6 x renda | `cent` | CT:453-454 |
| caucao_recebida | decimal | <= caucao | `cent` | CT:455-456 |
| caucao_devolvida / caucao_retida / caucao_motivo | decimal/decimal/string | preenchidos ao terminar | — | CT:612-613 |
| actualizacao | enum NAO/PERC/VALOR | — | default 'NAO' | CT:458 |
| actualizacao_valor | decimal | PERC: (0,100]; != NAO: > 0 | num | CT:459, 461-465 |
| actualizacao_meses | int>=1 | — | default 12 | CT:460 |
| actualizacao_base | date | data da última actualização manual (arranque da contagem) | — | CU:569; CT:695 |
| ultima_actualizacao / renda_anterior | date / decimal | gravados pela acção "Actualizar renda" | — | CU:566 |
| renovacao | enum NAO/AUTO | — | default 'NAO' no servidor (form default AUTO para novo, CU:714) | CT:466 |
| renovacao_meses | int>=1 | — | default 12 | CT:467 |
| aviso_dias | int>=0 | — | default 0 (form default 60, CU:729) | CT:468 |
| encargos | array de {designacao, valor, incluido, origem, unit_id} | filtra: designacao não vazia E (valor>0 OU incluido) | origem default 'MANUAL' (outra: 'PARQUE'); unit_id int/null | CT:474-480 |
| estado | enum | — | inválido -> 'RASCUNHO'; na edição a UI repõe o estado antigo | CT:482; CU:1023 |
| doc_type | 'Factura' / 'Factura-Recibo' | — | default 'Factura' | CT:483 |
| docs | array url | normalizado por `window.Imobiliario.normalizarUrl` | [] | CT:486 |
| notas | string | — | — | CT:487 |
| registo_agt_data | date | data de entrega da cópia na Repartição Fiscal | '' | CT:488 |
| criado_em | date | só na criação | `hojeISO()` | CT:497 |

Chaves/relações: unit_id -> imo_units; property_id -> imo_properties; terceiro_id -> third_parties (via `window.Imobiliario.terceiros()`); parent_lease_id -> imo_leases (auto-relação mãe/filhos); empresa -> `companies`.
Numeração: prefixos `PREFIXOS={ARRENDAMENTO:'AR', SUBARRENDAMENTO:'SB', TOMADA:'AT'}` (CT:257). Formato `"${pref} ${ano}/${n+1 com 4 dígitos}"`, p.ex. `AR 2026/0001`. Série por prefixo+ano civil corrente (`new Date().getFullYear()`, NÃO ano do início do contrato); n = max das ocorrências existentes do mesmo prefixo/ano + 1 (regex `^PREF ANO/(\d+)$`, CT:259-269). Não há reserva atómica (corrida possível; só cliente).

### 1.2 imo_rents (renda do período) — criada por `gerarRendas` (CT:760-767) + campos de `calcular`
| campo | descrição | evidência |
|---|---|---|
| id, imo_company_id, lease_id, periodo ('AAAA-MM' do início do período) | | CT:761 |
| posicao | cópia do contrato (default SENHORIO) | CT:762 |
| de, ate | limites do período (ver 3.1) | CT:763 |
| vencimento | data (ver 3.2) | CT:764 |
| estado | PREVISTA/APROVADA/FACTURADA/PAGA/ANULADA | CT:89-95, 765 |
| sale_id (venda), treasury_id (doc tesouraria), doc_number, notas, is_master_data=0 | | CT:765 |
| data_factura (ao facturar), data_pagamento (ao pagar) | | CT:969; SB:128 |
| extras[] {work_id, designacao, valor, data}, extras_total | encargos extraordinários (obras debitadas) | SB:287-298 |
| saída de `calcular` (CT:676-684): renda, encargos, desconto, base, ip_base, ip_taxa, ip_valor, ip_retido (bool), ip_a_reter, selo_pct, selo_valor, selo_encargo, iva_taxa, iva_valor, total_facturar, total_receber, custo_senhorio | | CT:655-685 |
Nota: `desconto` só existe via `extras.desconto` do recalcular; não há UI para o introduzir (ver 10).

### 1.3 imo_templates (modelo de documento) — `gravarModelo` (TP:377-381)
`{imo_company_id, tipo (enum TIPOS), nome, assunto, corpo, activo (0/1; default 1), is_master_data: 1}`. Importação: chave de duplicado = `tipo|nome` (TP:397).

### 1.4 imo_settings (taxas por ano)
Um registo por empresa: `{id, imo_company_id, taxas: { "<ano>": {chave: número,...} }}` (CT:178-204). `taxas(ano)` = `Object.assign({}, TAXAS_BASE, taxas[ano])` (CT:186-190, só o ano pedido; não herda de anos anteriores). `gravarTaxas` só grava chaves conhecidas de TAXAS_BASE e só as não vazias; substitui integralmente o objecto do ano (`t[a]=limpo`, CT:201). Erro: «As percentagens do Imposto Predial não podem passar de 100%.» se ip_base>100 ou ip_taxa>100 (CT:200).

TAXAS_BASE (CT:113-139): ip_taxa 25; ip_base 60 (-> 15% efectivo); selo_habitacao 0,1; selo_outro 0,4; iva_arrendamento 0; iva_alojamento 7; mora_mensal 0; prazo_registo 10 (dias); ip_transmissao 2; selo_transmissao 0,3; escritura_fixo 2000; isencao_ate 0; isencao_metade_ate 0; det_escalao1_ate 5000000; det_escalao1_pct 0,1; det_escalao2_ate 6000000; det_escalao2_fixo 5000; det_escalao3_pct 0,5; det_terreno_pct 0,6; det_agravamento_pct 50; det_desocupado_meses 12. (As de transmissão/detenção pertencem a I5/I6 mas editam-se aqui, CU:1254-1274.)

### 1.5 Tabelas de outros módulos tocadas
- sales / sale_items (factura da renda) — CT:900-959. Campos extra na venda: `imo_lease_id`, `imo_rent_id`, `related_doc_id = String(c.numero)`.
- treasury_documents / treasury_items — SB:106-122. Campos extra: `imo_lease_id`, `imo_rent_id`.
- receipt_items (`sale_id`, `amount_paid`) — leitura CT:1004, 1106; TP:501.
- chart_of_accounts (`company_id` número OU texto, `code`, `type` 'T' = totalizadora, `is_master_data`, `description`) — CT:213-228; CU:97-108.
- imo_units (`natureza`, `park_afectacao` VISITA/CARGA_DESCARGA/PERMANENTE, `park_unit_id`, `estado`, `designacao`, `codigo`, `renda_referencia`, `area_util`, `piso`, `tipologia`, `quartos`, `mobilada` SIM/PARCIAL, `property_id`, `imo_company_id`), imo_properties (`papel` ARRENDADO/MEDIACAO/..., `proprietario_id`, `nome`, `codigo`, `morada`, `municipio`, `provincia`, `matriz_predial`), imo_works (SB:273-299), companies (name/nome, tax_id/nif, address/morada), third_party_addresses (company_id, nif; street, door_number, neighborhood, municipality, province) — TP:491-494.

---------------------------------------------------------------------
## 2. ENUMERAÇÕES (rótulos exactos)

- TIPOS (CT:31-35): ARRENDAMENTO = «Arrendamento (a empresa é senhoria)»; SUBARRENDAMENTO = «Subarrendamento (a empresa subarrenda o que arrendou)»; TOMADA = «Arrendamento tomado (a empresa é inquilina)». Na listagem usa-se `.replace(/ \(.*/, '')` -> «Arrendamento», «Subarrendamento», «Arrendamento tomado» (CU:185).
- POSICOES (CT:39-42): SENHORIO {rot «A empresa recebe», doc «Factura de venda», cor #15803d}; INQUILINO {rot «A empresa paga», doc «Pagamento na Tesouraria», cor #b45309}.
- AMBITOS (CT:52-56): FRACCAO «Uma fracção» (ajuda «O caso normal: um contrato, uma fracção.»); IMOVEL «Imóvel inteiro» («Todas as fracções do prédio ou condomínio num só contrato.»); PARCIAL «Várias fracções» («Um conjunto escolhido de fracções do mesmo imóvel.»).
- FINALIDADES_FISCAIS (CT:64-71): HABITACAO «Habitação»; COMERCIO «Comércio»; ESCRITORIO «Escritório»; ARMAZEM «Armazém / industrial»; ALOJAMENTO «Alojamento hoteleiro»; OUTRO «Outro».
- PERIODICIDADES (CT:73-79): MENSAL «Mensal»/1; BIMESTRAL «Bimestral»/2; TRIMESTRAL «Trimestral»/3; SEMESTRAL «Semestral»/6; ANUAL «Anual»/12 (meses).
- ESTADOS do contrato (CT:81-87): RASCUNHO «Rascunho»; ACTIVO «Activo»; SUSPENSO «Suspenso»; TERMINADO «Terminado»; DENUNCIADO «Denunciado». Cores UI (CU:31-35): RASCUNHO #92400e/#fffbeb; ACTIVO #15803d/#f0fdf4; SUSPENSO #b45309/#fef3c7; TERMINADO #475569/#f1f5f9; DENUNCIADO #b91c1c/#fef2f2.
- ESTADOS_RENDA (CT:89-95): PREVISTA «Prevista»; APROVADA «Aprovada»; FACTURADA «Facturada» (lado senhorio); PAGA «Paga» (lado inquilino); ANULADA «Anulada». Cores (CU:36-39): PREVISTA #475569; APROVADA #1d4ed8; FACTURADA #15803d; ANULADA #b91c1c (PAGA não tem cor definida -> cai no default PREVISTA, CU:42; dúvida/bug cosmético).
- ACTUALIZACOES (CT:99-103): NAO «Não actualiza»; PERC «Percentagem fixa»; VALOR «Valor fixo».
- ISENCOES (CT:105-109): NAO «Sem isenção»; METADE «Redução de 50% (OGE)»; TOTAL «Isento (OGE)».
- selo_encargo: SENHORIO «Encargo do senhorio»; INQUILINO «Encargo do inquilino»; ISENTO «Isento» (CU:744-747).
- renovacao: NAO «Não renova»; AUTO «Automática» (CU:713-714).
- Momento (antecipada): 1 «Adiantada (início do período)»; 0 «No fim do período» (CU:722-723).
- doc_type: «Factura (recebe-se depois)» = Factura; «Factura-Recibo (pago no acto)» = Factura-Recibo (CU:735-736).
- origem do encargo: 'MANUAL' | 'PARQUE'.
- Tipos de modelo TP:24-33: CONTRATO «Contrato de arrendamento»; CONTRATO_SUB «Contrato de subarrendamento»; RECIBO «Recibo de renda»; AVISO «Aviso de vencimento»; INTERPELACAO «Interpelação por mora»; ACTUALIZACAO «Carta de actualização da renda»; DENUNCIA «Carta de denúncia»; VISTORIA «Auto de entrega / vistoria».
- Tipos de aviso (avisos(), CT:1042-1112): RASCUNHO, REGISTO, CAUCAO, SEM_TITULO, TITULO_INACTIVO, ALEM_TITULO, FIM, EXPIRADO, ACTUALIZACAO, POR_FACTURAR, POR_PAGAR, MORA.
- Enums de outros módulos usados: ESTADOS_UNIDADE (DISPONIVEL, RESERVADA, ARRENDADA, ...; `window.Imobiliario.ESTADOS_UNIDADE[..].rot`), PAPEIS de imóvel (ARRENDADO, MEDIACAO, ...), TIPOLOGIAS. Treasury status: PENDENTE, INTEGRADO, ANULADO; sales status: 'PENDENTE'.

---------------------------------------------------------------------
## 3. REGRAS DE NEGÓCIO E FÓRMULAS

Convenções: `cent(v) = Math.round(num(v)*100)/100` (CT:26) — arredondamento ao cêntimo, half-away-from-zero do JS (sobre float). `num` = parseFloat ou 0. Todas as datas ISO 'AAAA-MM-DD'; períodos 'AAAA-MM'.

### 3.1 Períodos
- `limitesPeriodo(periodo, periodicidade)` (CT:147-153): n=meses da periodicidade; `de = UTC(a, m-1, 1)`; `ate = UTC(a, m-1+n, 0)` (último dia do último mês do período). Ex.: 2026-11 TRIMESTRAL -> 2026-11-01 a 2027-01-31.
- `somarMeses(periodo, n)` (CT:155-159). `periodoDe(iso) = iso.slice(0,7)`.
- `periodosDoContrato(c, de, ate)` (CT:722-735): n=meses; `lim = fim_real || fim || ate`; p parte de `periodoDe(c.inicio)` e avança n meses; fim = `periodoDe(min(lim, ate))`; só emite p >= `periodoDe(de)`; guarda 600 iterações. Nota: NUNCA há pro-rata do primeiro/último período — o primeiro período é sempre inteiro a partir do mês do início (ver 10).
- Vencimento (CT:164-171): `base = antecipada===false ? lim.ate : lim.de`; dia = `min(max(1, round(dia_vencimento||1)), últimoDiaDoMêsDeBase)`. Ou seja, se "no fim do período" o dia_vencimento aplica-se ao MÊS DO ÚLTIMO DIA do período.
- `diasEntre(a,b) = round((b-a)/86400000)` (UTC) (CT:173).

### 3.2 Cálculo da renda `calcular(c, t, extras)` (CT:655-685) — fórmula exacta
```
renda     = cent(extras.renda ?? c.renda)
enc       = sum(c.encargos.filter(!incluido), cent(valor))
desconto  = cent(extras.desconto)
base      = cent(renda + enc - desconto)
ip_base   = cent(base * t.ip_base / 100)
ip        = cent(ip_base * t.ip_taxa / 100)         # 25% x 60% = 15% efectivo
  isencao_ip TOTAL -> ip = 0 ; METADE -> ip = cent(ip/2)
selo_pct  = (finalidade_fiscal==='HABITACAO') ? t.selo_habitacao : t.selo_outro     # 0,1 / 0,4
selo      = (selo_encargo==='ISENTO') ? 0 : cent(base * selo_pct / 100)
iva_taxa  = (finalidade_fiscal==='ALOJAMENTO') ? t.iva_alojamento : t.iva_arrendamento   # 7 / 0
iva       = cent(base * iva_taxa / 100)
total_facturar = cent(base + iva)
retido    = c.retencao_ip ? ip : 0
total_receber  = cent(total_facturar - retido)
custo_senhorio = cent((selo_encargo==='SENHORIO' ? selo : 0) + (retencao_ip ? 0 : ip))
ip_retido = !!retencao_ip ; ip_a_reter = retido ; ip_valor = ip
```
Notas: o Selo NÃO entra na factura nem no total; o IP sobre a base inclui encargos a acrescer (não só a renda). `selo_encargo==='INQUILINO'` calcula o selo mas não conta como custo do senhorio. Se retencao_ip = false o IP é custo do senhorio.

### 3.3 Actualização / indexação `rendaNoPeriodo(c, periodo)` (CT:693-703)
```
if actualizacao==='NAO' or actualizacao_valor<=0: return cent(c.renda)
ini = periodoDe(actualizacao_base || inicio)
decorridos = (a2-a1)*12 + (m2-m1)       # meses entre ini e periodo
n = floor(decorridos / max(1, actualizacao_meses))
n<=0 -> cent(c.renda)
PERC : cent(renda * (1 + valor/100)^n)    # composto
VALOR: cent(renda + valor * n)            # linear
```
Aplica-se a gerar rendas (CT:759) e recalcular (CT:850). Acção manual "Actualizar renda" (CU:533-586): grava `renda=nova`, `ultima_actualizacao=data`, `renda_anterior=antiga`, `actualizacao_base=data` (para a regra contar a partir daí); opcionalmente recalcula rendas com `estado` não FACTURADA/ANULADA e `de >= data de efeito` com `renda: nova` (nota: rendas PAGA também são puladas? NÃO — o filtro só exclui FACTURADA e ANULADA, CU:574, mas `recalcularRenda` lança «Uma renda que já é documento não se recalcula.» para PAGA e como não há try por renda, a actualização rebenta a meio (ver 10)). Valor proposto = `rendaNoPeriodo(c, mês de hoje)`.
Aviso ACTUALIZACAO quando `rendaNoPeriodo(hoje) > cent(c.renda)` (CT:1090-1093).

### 3.4 Caução
- Máx. 6 x renda (CT:454); recebida <= acordada (CT:456).
- Em poder: `caucao_recebida - caucao_devolvida - caucao_retida` (CT:1036, 1134 para o resumo).
- Ao terminar: guarda devolvida/retida/motivo; UI default devolvida = caução em poder, retida = 0, e exige motivo se retida>0 (CU:504-516). O servidor NÃO valida devolvida+retida <= em poder nem exige o motivo (só a UI). Não há lançamento contabilístico nem movimento de tesouraria da caução em lado nenhum.
- Aviso CAUCAO se `caucao > caucao_recebida`: texto «falta receber/entregar X de caução» (CT:1061-1066).

### 3.5 Mora e juros (conta corrente, CT:1010-1018)
```
esperado   = cent(r.total_receber)
movimentado= senhorio: soma receipt_items.amount_paid onde sale_id=r.sale_id
             inquilino: treasury_documents.total_value se status != 'ANULADO' (senão 0)
divida     = cent(esperado - movimentado)
atraso     = (emDocumento(r) && divida>0.009 && vencimento < hoje) ? diasEntre(vencimento, hoje) : 0
jurosPct   = num(c.mora_mensal != null ? c.mora_mensal : t.mora_mensal)      # t = taxas do ano corrente
juros      = (atraso>0 && jurosPct>0) ? cent(divida * jurosPct/100 * (atraso/30)) : 0
```
Juros simples, mês comercial de 30 dias, só sobre renda JÁ DOCUMENTO. Observação: `c.mora_mensal` NÃO é gravado por `gravarContrato` nem existe no formulário -> na prática usa-se sempre `t.mora_mensal` (campo por contrato inexistente, ver 10). Usa as taxas do ano corrente, não do ano da renda.

### 3.6 Conta corrente `contaCorrente(leaseId)` (CT:988-1038)
Linhas = rendas não ANULADAS, com `pago`, `divida`, `atraso`, `juros`. Totais:
- facturado = sum(total_facturar das emDocumento)
- esperado = sum(total_receber das emDocumento)
- recebido = sum(pago de todas as linhas)   (inclui não-documento, mas pago só é >0 se há sale/treasury)
- em_divida = sum(max(0, divida) das emDocumento)
- juros = sum(juros)
- ip_retido = sum(ip_valor das emDocumento com ip_retido)
- selo_senhorio = sum(selo_valor das linhas (não anuladas) com selo_encargo==='SENHORIO')  # nasce com a renda devida
- caucao_em_poder (3.4)
Do lado inquilino a UI troca rótulos (facturado->«Em documento», esperado->«A sair de caixa», recebido->«Pago», em_divida->«Por pagar»).
Atenção: do lado inquilino `divida` = esperado - valor do doc tesouraria (PENDENTE já conta como movimentado), pelo que "Por pagar" é praticamente sempre 0 para PAGA; e a UI só mostra "Dívida" para estado FACTURADA (CU:349).

### 3.7 Resumo/KPI `resumo(posicao)` (CT:1119-1143)
contratos=nº; activos=nº ACTIVO; `mensal(l) = sum(renda/meses(periodicidade))` dos activos do lado l (arredondado 2 casas) -> renda_mensal (SENHORIO), renda_mensal_paga (INQUILINO), margem_mensal = SENHORIO - INQUILINO; caucoes = sum(recebida - devolvida - retida) sobre TODOS os contratos (inclui terminados/ambos os lados); mes_periodo = mês de hoje; mes_previsto = sum(total_facturar) rendas SENHORIO do mês; mes_facturado = estado FACTURADA do mês; mes_a_pagar = sum(total_receber) lado INQUILINO; mes_pago = estado PAGA; por_facturar = nº rendas SENHORIO com estado != FACTURADA e vencimento <= hoje (inclui PAGA? só senhorio, ok; inclui APROVADA/PREVISTA); por_pagar = nº rendas INQUILINO estado != PAGA e vencimento <= hoje. (Atenção: rendas ANULADAS já excluídas por filtro prévio.)

### 3.8 Fiscalidade (comentário de topo, CT:3-17) — regras-guia
- Imposto Predial: 25% sobre 60% da renda (15% efectivo); retém o INQUILINO se tem contabilidade organizada (senhorio factura renda inteira e recebe menos).
- Imposto de Selo: 0,1% habitação / 0,4% restante; encargo do SENHORIO; não entra na factura.
- IVA: arrendamento isento (Lei 14/23); só alojamento hoteleiro a 7%.
- Taxas e isenções do OGE em `imo_settings.taxas[ano]` (anuais). Comentário TAXAS_BASE: «Confirmados em 2026-11-06 (Lei 20/20 e Lei 14/23)». Modelos: Lei 8/83 (arrendamento urbano) + Lei 20/20 (TP:10-11).
- Prazo de registo: `t.prazo_registo` (10 dias) para entregar cópia na Repartição Fiscal; ver aviso REGISTO.

### 3.9 Renovação
Só armazenada (`renovacao`, `renovacao_meses`) e usada em texto de aviso FIM (CT:1084) e no modelo. NÃO existe função que renove (prorrogue `fim`) automaticamente (ver 10). Aviso EXPIRADO: «passou o termo (...) e continua activo — renove ou termine».

### 3.10 Margem do subarrendamento `margem()` (SB:167-224)
- Intervalo por omissão: `de = hoje-11 meses` (somarMeses(periodo de hoje, -11)), `ate = período de hoje`. Filtra rendas não ANULADAS com `periodo >= de && <= ate` (comparação de strings).
- Agrupa por `c.unit_id` (fracção principal). Para cada renda: `v = num(r.total_facturar)`; lado SENHORIO -> `recebe += v` e regista `c.numero` em `subs`; lado INQUILINO -> `paga += v` e regista em `tomadas`. (Mesmo para contratos RASCUNHO/TERMINADOS: depende só de existirem rendas não anuladas.) Nota: usa `total_facturar` (renda inteira, ANTES da retenção) nos dois lados, não `total_receber`.
- Por fracção: `margem = cent(recebe - paga)`; `taxa = paga>0 ? round((recebe-paga)/paga*1000)/10 : null` (% com 1 casa, sobre o pago). Períodos: lista ordenada por período com recebe/paga/margem.
- Linhas ordenadas por margem ASC (piores primeiro). Totais: recebe, paga, margem. `negativas = linhas.paga>0 && margem<0`; `vazias = linhas.paga>0 && sem subs` (tomada sem subarrendamento).
- Não compara periodicidades distintas (o comentário SB:162-165 diz comparar "período a período", mas a soma é por `periodo` de início de renda, sem mensalizar; uma tomada anual contra subs mensais dá margem distorcida — dúvida, ver 10).
- `cadeia(leaseId)` (SB:227-240): mãe + filhos; `paga = ccMae.facturado`; `recebe = sum(conta.facturado dos filhos)`; `margem = recebe - paga`; `vivos` = filhos ACTIVO/SUSPENSO. (Acumula TODO o histórico, não filtrado por período.)

### 3.11 Obras debitáveis ao inquilino (SB:249-301)
- `obrasADebitar()`: `ImobiliarioObras.porDebitar()`; só obras com `unit_id`; contrato = o ACTIVO do lado SENHORIO com `x.unit_id === parseInt(o.unit_id)` (só a fracção principal, não considera `unidades`); valor = `cent(o.custo_estimado)`; se sem contrato: motivo «A fracção não tem contrato activo — não há a quem debitar.».
- `debitarObra(workId, rentId, valor)`: obra tem de estar `CONCLUIDA`, `debitavel` e sem `debitado_em`; renda não pode ser documento e tem de ser lado SENHORIO; valor>0 (default custo_estimado). `extras.push({work_id, designacao: 'Reparação: ' + descricao[0..80], valor, data: hoje})`; `somaExtras = sum(extras.valor)`; `base = cent(renda + encargos - desconto + somaExtras)`; `total_facturar = cent(base + iva_valor)`; `total_receber = cent(base + iva_valor - (ip_retido ? ip_valor : 0))` (IP e selo NÃO recalculados; IVA também não — usa o iva_valor antigo). Marca obra: `debitado_em, debitado_rent_id, debitado_valor`. Mensagens: «Ordem não encontrada.», «Só se debita uma obra concluída.», «Esta ordem não é debitável ao inquilino.», «Esta ordem já foi debitada.», «A renda já é documento: escolha uma renda ainda por facturar.», «Só se debita ao inquilino numa renda a receber.», «Indique o valor a debitar.». Se a renda for depois recalculada (`recalcularRenda`) `calcular` NÃO inclui extras -> o débito perde-se do total mas `extras` continua na renda e vai na factura (inconsistência, ver 10).

### 3.12 Facturação da renda `facturarRenda(id)` (CT:864-971)
Pré-condições por ordem (mensagens exactas em 4). Numeração: `FacturaAGT.numerar(docType, hoje, {origem:'GERAL'})`, fallback `numerarLocal`; se nada: `"FR|FT ANO/(count de sales da empresa do mesmo doc_type + 1)"`. Carrega pacote 'vendas' se `window.FacturaAGT` ausente (`window.carregarPacote`). Venda gravada com: company_id, customer_id=c.terceiro_id, doc_type, doc_number, date=hoje+'T'+hora, due_date=r.vencimento||hoje, related_doc_id=String(c.numero), status='PENDENTE', notes=`Renda de ${periodo} — contrato ${numero} (${imóvel} · ${fracção})`, subtotal=cent(base), total_tax=cent(iva_valor), total_discount=cent(desconto), total_gross=cent(base+iva_valor), total_net=cent(base), total_amount=cent(total_facturar), imo_lease_id, imo_rent_id, is_master_data=0, + `reserva.campos` (campos fiscais AGT). Linhas (sale_items; product_id null; quantity/billed_qty/delivered_qty=1; discount_pct=0; tax_rate=r.iva_taxa): 1) `Renda de ${periodo} — ${ref}` valor cent(renda - desconto); 2) uma por encargo não incluído: `${designacao} — ${periodo}` valor cent(valor); 3) uma por extra: `${designacao} — ${periodo}`. Em falha: apaga a venda e `FacturaAGT.libertar(reserva)`; erros: «Não foi possível gerar a factura: …», «Não foi possível gravar as linhas da factura: …». Depois `FacturaAGT.selar(saleId, reserva)` (erro só vai à consola). Por fim renda -> FACTURADA com `sale_id, doc_number, data_factura`. Notas: o contrato NÃO envia IVA/retenção/selo para a factura além do `total_tax`; a retenção de IP não existe no documento (a diferença só aparece no recebimento); a renda NÃO usa `product_id`/conta de rendimento: a contabilização ocorre no módulo Vendas.
`facturarVarias(ids)` itera e devolve `{feitas:[{id,sale_id,doc_number}], falhas:[{id,erro}]}`.

### 3.13 Pagamento da renda (lado inquilino) `pagarRenda` (SB:36-130)
- Contas: `contaCusto = o.conta_custo||c.conta_custo` (obrig.), `contaFin = o.conta_financeira||c.conta_financeira` (obrig.), `retem = c.retencao_ip && r.ip_valor>0`; se retem, `contaRet` obrigatória. Todas validadas contra o plano (`validarContaDoPlano`).
- `data = o.data || r.vencimento || hoje`; `desc = "Renda de ${periodo} — contrato ${numero} (${imóvel} · ${fracção})"`.
- Linhas de tesouraria: (1) D conta_custo valor cent(r.base), third_party_id=c.terceiro_id, doc_number, description=desc, business_unit_id/cost_center_id (opcionais em `o`), orig_doc_date=data; (2) se retem: C conta_retencao valor cent(r.ip_valor), description «Imposto Predial retido ao senhorio — ${periodo} (contrato ${numero})». demo_note_id/cashflow_note_id null; is_master_data 0.
- Recusa se `r.iva_valor>0` («Esta renda tem IVA. O IVA dedutível precisa de conta própria: lance este pagamento directamente na Tesouraria.»).
- `saida = cent(base - (retem ? ip_valor : 0))`; tem de coincidir com `total_receber` a ±0,02 («O valor a pagar não bate certo (${saida} contra ${r.total_receber}). Recalcule a renda antes de a pagar.»).
- `treasury_documents`: company_id, type 'PAGAMENTO', doc_date=data, account_fin=contaFin, description=desc, total_value=saida, currency/exchange_rate/exchange_rate_id/total_value_currency null, exchange_rate_manual false, status 'PENDENTE', reference = o.doc_number || c.numero, project_id/null, project_code '', doc_url = o.doc_url||null, payroll_period_id null, imo_lease_id, imo_rent_id, is_master_data 0. Em falha das linhas: apaga o documento. Renda -> PAGA com `treasury_id, doc_number, data_pagamento`. Retorna `{treasury_id, valor, retido}`.
- Observação: o encargo/IP/selo de um subarrendamento do lado senhorio segue o fluxo de factura; o "selo" do lado inquilino não gera qualquer lançamento.
- `desligarPagamento(rentId, motivo)`: só PAGA; motivo obrigatório; recusa se treasury_document.status==='INTEGRADO'; renda volta a APROVADA, `treasury_id=null`, `data_pagamento=null`, nota ` · pagamento desligado em ${hoje}: ${motivo}`. NÃO apaga o documento de tesouraria.

### 3.14 Regras do âmbito / disponibilidade / conflitos
- Vagas de estacionamento VISITA e CARGA_DESCARGA nunca entram em contrato (`arrendavel`, CT:318).
- `conflito(d)` (CT:276-294): mesmo LADO (`posicao`), estados ACTIVO/SUSPENSO/RASCUNHO, intervalo cruza (`c.inicio <= fim && (c.fim||9999-12-31) >= d.inicio`), e uma fracção em comum. Tomada e subarrendamento da mesma fracção cruzam-se de propósito (lados diferentes).
- `verificarLugaresLivres(c)` (CT:552-580): na ACTIVAÇÃO compara com outros ACTIVO do mesmo lado: fracção já noutro contrato, ou vaga PERMANENTE (`park_unit_id`) cuja fracção-mãe esteja arrendada noutro contrato activo.
- `marcarFraccoes(c, estado)` (CT:513-524): só lado SENHORIO altera `imo_units.estado` ('ARRENDADA' ao activar/gravar activo; 'DISPONIVEL' ao terminar/eliminar activo). Lado inquilino não altera.
- Cessação em cascata (3.15).

### 3.15 Máquina de estados do contrato
Estados: RASCUNHO, ACTIVO, SUSPENSO, TERMINADO, DENUNCIADO.
Transições implementadas:
- (novo) -> RASCUNHO (gravarContrato sem estado; msg UI «Contrato criado em rascunho — active-o para gerar rendas.», CU:1027). Na edição o estado nunca muda (UI repõe `antigo.estado`, CU:1023).
- RASCUNHO -> ACTIVO e SUSPENSO -> ACTIVO via `activarContrato` (botão «Activar» visível em RASCUNHO e SUSPENSO, CU:249). Recusa TERMINADO/DENUNCIADO («Um contrato terminado não se reactiva — faça um novo.»); se tem mãe, mãe tem de existir e estar ACTIVO; chama `verificarLugaresLivres`; marca fracções ARRENDADA (só lado senhorio).
- ACTIVO|SUSPENSO -> TERMINADO (termo) ou DENUNCIADO (`opcoes.denuncia` truthy) via `terminarContrato` (botão «Terminar / denunciar» visível em ACTIVO e SUSPENSO). Note-se: `terminarContrato` não valida o estado de origem (aceita RASCUNHO e até TERMINADO se chamado por código; a UI só oferece para ACTIVO/SUSPENSO). Efeitos: anula rendas futuras (ver abaixo), grava fim_real/motivo/caução, fracções -> DISPONIVEL (lado senhorio), cascata aos filhos.
- ACTIVO -> SUSPENSO: NÃO existe nenhuma função nem botão que suspenda (SUSPENSO só é lido: conflitos, avisos, UI, outras partes). Estado "órfão" (ver 10).
- Eliminação: só RASCUNHO/TERMINADO/DENUNCIADO (ACTIVO/SUSPENSO recusados), sem filhos, sem rendas FACTURADA/PAGA; apaga todas as rendas e o contrato.
Terminar em detalhe (CT:588-627): `data = o.data || hoje`; erro se `data < inicio`; motivo obrigatório; filhos dependentes (ACTIVO/SUSPENSO/RASCUNHO) exigem `o.cascata`; rendas com estado não (FACTURADA|PAGA|ANULADA) e `de > data` passam a ANULADA com nota ` · anulada pela saída em ${data}`; contrato atualizado; fracções libertadas; para cada filho recursivamente `terminarContrato(f.id, {data, denuncia:false, cascata:true, motivo:'Caducidade: o contrato ${c.numero}, de que depende, cessou em ${data} (${motivo}).', caucao_devolvida: f.caucao_recebida, caucao_retida:0})`. Retorna `{rendas_anuladas, filhos_terminados, rendas_filhos}`. Nota: renda cujo período contém a data (de <= data) NÃO é anulada nem reduzida (sem pro-rata).

### 3.16 Estado das rendas (fluxo)
PREVISTA -> APROVADA (`aprovarRendas`, só PREVISTA, ignora silenciosamente as outras) -> FACTURADA (lado senhorio) ou PAGA (lado inquilino). PREVISTA|APROVADA -> ANULADA (`anularRenda`; FACTURADA/PAGA recusadas). PAGA -> APROVADA (`desligarPagamento`). Geração só cria períodos que ainda não têm renda (qualquer estado, incluindo ANULADA — uma renda anulada não regenera). Recalcular só para não-documento (inclui ANULADA e PREVISTA/APROVADA; sem restrição de ANULADA).

---------------------------------------------------------------------
## 4. VALIDAÇÕES E MENSAGENS (exactas)

gravarContrato (CT:305-505), por ordem:
1. «Base de dados indisponível.»
2. FRACCAO sem fracção: «Escolha a fracção. O contrato prende-se sempre a uma fracção, nunca ao imóvel.»
3. Âmbito != FRACCAO: sem imóvel «Escolha o imóvel do contrato.»; imóvel sem fracções «O imóvel não tem fracções registadas. Crie-as (ou a fracção «Única») antes de o arrendar por inteiro.»; PARCIAL com fracções de outro imóvel «Há fracções escolhidas que não pertencem a este imóvel: um contrato não atravessa prédios.»; < 2 válidas «Escolha pelo menos duas fracções, ou mude o âmbito para uma fracção só.»
4. «Fracção não encontrada.» (também se imo_company_id != cid); «A fracção não tem imóvel associado.»; «Todas as fracções do contrato têm de ser do mesmo imóvel.»
5. Terceiro: «Indique o inquilino.» / «Indique o senhorio a quem a empresa paga.»; SENHORIO e terceiro == proprietário do imóvel: «O inquilino não pode ser o proprietário do imóvel.»
6. TOMADA: imóvel.papel != ARRENDADO: «Um contrato tomado é o que torna o imóvel arrendado: ponha o papel do imóvel em «Arrendado».»; sem conta de custo: «Indique a conta de custo da renda (o ERP não pré-preenche contas).»; retencao_ip!==false sem conta: «A empresa retém o Imposto Predial ao senhorio: indique a conta onde a retenção fica a aguardar entrega à AGT.»; validação das 3 contas (rótulos 'conta de custo', 'conta da retenção', 'conta de disponibilidades') com `validarContaDoPlano`: «A conta ${c} (${rotulo}) não existe no plano de contas desta empresa.» | «Esta empresa ainda não tem plano de contas: carregue-o antes de mapear a ${rotulo}.» | «A conta ${c} (${rotulo}) é totalizadora e não pode ser mapeada. Escolha uma conta de movimento.» (type==='T'; ignora master data).
7. SUBARRENDAMENTO: papel != ARRENDADO «Só se subarrenda o que a empresa arrendou: o imóvel tem de ter o papel «Arrendado».»; sem tomada «A fracção não tem contrato tomado: registe primeiro o arrendamento em que a empresa é inquilina.» (tomadas ACTIVO/RASCUNHO/SUSPENSO sobre `unit_id`); mãe ambígua/inexistente «Escolha o contrato tomado de que este subarrendamento depende.» (se só 1 título e parent não indicado, usa-o); mãe sem autorização «O contrato ${numero} não autoriza subarrendar. Sem autorização escrita do senhorio, o subarrendamento é causa de resolução.»; fracções não cobertas «O contrato ${numero} não abrange ${nomes}: a empresa não pode subarrendar o que não tomou.»; início antes da mãe «O subarrendamento não pode começar antes do contrato ${numero}, que só produz efeitos a partir de ${inicio}.»; fim além da mãe (usa fim_real||fim) «O subarrendamento caduca com o contrato ${numero}, que termina em ${fimMae}: não pode ir além dessa data nem ficar sem termo.»
8. ARRENDAMENTO com imóvel.papel==='MEDIACAO': «Um imóvel em mediação não se arrenda em nome da empresa — registe-o como próprio ou arrendado.» (Nota: o ARRENDAMENTO não exige papel PROPRIO; só exclui MEDIACAO. SUBARRENDAMENTO e TOMADA exigem ARRENDADO.)
9. «Indique a data de início.»; «A data de fim tem de ser posterior ao início.»; «A renda tem de ser maior do que zero.»; «A caução não pode passar de seis rendas.»; «A caução recebida não pode passar da caução acordada.»; «A percentagem de actualização tem de estar entre 0 e 100.»; «Indique o valor da actualização.»
10. Conflito: «${A fracção|As fracções} ${nomes} já ${está|estão} no contrato ${numero} (${Estado}) de ${inicio} a ${fim|sem termo}.»
activarContrato: «Contrato não encontrado.», «Um contrato terminado não se reactiva — faça um novo.», «O contrato tomado de que este depende já não existe.», «O contrato ${numero}, de que este subarrendamento depende, está em «${Estado}». Active-o primeiro.», `verificarLugaresLivres`: «Não se activa: ${choques}. Termine o outro contrato, ou liberte a vaga da fracção.» com choques `${designacao} já está no contrato ${n}` / `${vaga} está afecta a «${mae}», arrendada pelo contrato ${n}`.
terminarContrato: «A data de saída não pode ser anterior ao início do contrato.», «Indique o motivo da saída.», «O contrato ${n} dá título a ${k} subarrendamento(s) (${lista}), que caducam com ele. Confirme a cessação em cascata.»
eliminarContrato: «O contrato está em vigor: termine-o ou denuncie-o (com data e motivo) em vez de o apagar.», «O contrato dá título a ${k} subarrendamento(s) (${lista}) — apague-os primeiro.», «O contrato tem ${k} renda(s) já em documento — não se apaga.»
gerarRendas: «Só se geram rendas de contratos activos.», «O fim do intervalo é anterior ao início.»
anularRenda: «Renda não encontrada.», «A renda já é documento fiscal: corrija-a com nota de crédito em Vendas.» (FACTURADA), «A renda já tem documento de tesouraria: anule-o na Tesouraria e volte aqui.» (PAGA), «Indique o motivo da anulação.»; nota: ` · ${motivo}`.
recalcularRenda: «Uma renda que já é documento não se recalcula.»
facturarRenda ordem: renda existe; contrato existe; lado: «Neste contrato a empresa é a inquilina: a renda paga-se na Tesouraria, não se factura.»; «Esta renda já está facturada.»; «Não se factura uma renda anulada.»; «Aprove a renda antes de a facturar — uma factura selada não se corrige.»
pagarRenda: «Neste contrato a empresa é a senhoria: a renda factura-se, não se paga.»; «Esta renda já tem documento de tesouraria.»; «Não se paga uma renda anulada.»; «Aprove a renda antes de a pagar.»; «O contrato não tem conta de custo da renda.»; «Indique a conta de disponibilidades por onde sai o pagamento.»; «O contrato diz que a empresa retém o Imposto Predial, mas não tem conta de retenção. Indique-a no contrato.» + IVA + valor não bate (3.13); «Não foi possível criar o documento de tesouraria: …»; «Não foi possível gravar as linhas do pagamento: …».
desligarPagamento: «Esta renda não está marcada como paga.», «Indique o motivo.», «O documento já foi integrado na contabilidade: descontabilize-o na Tesouraria antes de o desligar daqui.»
Modelos: «Escolha o tipo de documento.», «Dê um nome ao modelo.», «O modelo não pode ficar sem texto.», «Variáveis que não existem: ${lista}.» (regex `\{\{\s*[a-z_]+\s*\}\}` sobre corpo+assunto; só minúsculas e underscore), «Modelo não encontrado.», «Contrato não encontrado.».
UI (avisos): «Não há contratos activos.»; «Marque as rendas a aprovar.» / «a pagar» / «a facturar»; «Escolha a conta de disponibilidades.»; «Indique o motivo da retenção da caução.»; «A renda nova tem de ser maior do que zero.»; «Indique a data de efeito.»; «O módulo de subarrendamento não está carregado.»; «Renda não encontrada.»; «O navegador bloqueou a janela de impressão.»; «O navegador não permitiu copiar — seleccione o texto à mão.»; «Escolha primeiro a fracção (ou as fracções) do contrato.»; «Estas fracções não têm vagas afectas.»; «O módulo de parqueamento não está carregado.»; «Não há modelo do tipo «${Tipo}». Importe os modelos por omissão no separador Documentos.»; SU: «O início do intervalo é posterior ao fim.», «Esta fracção não tem contrato tomado.», «Sem dados para esta fracção.», «O contrato não tem nenhuma renda por facturar. Gere as rendas do período e volte aqui.».
Cada validação é só de cliente; não há validação de unicidade do nº de contrato.

---------------------------------------------------------------------
## 5. UI — ECRÃS, SEPARADORES, MODAIS, ACÇÕES

Rotas (modulos_registo.js:19; app_v2.js:1089-1092): `#/imobiliario/contratos` -> renderImoContratos; `#/imobiliario/rendas` -> renderImoRendas; `#/imobiliario/modelos` -> renderImoModelos; `#/imobiliario/subarrendamento` -> renderImoSub. Chaves de ecrã: imo_contratos, imo_rendas, imo_modelos, imo_sub.
Estado de UI `st` (CU:29): leaseId, aba, filtro, filtroEstado, filtroLado, periodo, ladoRendas ('SENHORIO'), sel, falhas, modeloId.
Notificações: `window.Notificacoes.mostrar({tipo, texto})` (tipos 'info'|'aviso'|'sucesso'|'erro'), fallback `alert`. Diálogos: `window.Dialogo.pedir/confirmar/escolher/alerta`. Tarefas de fundo: `window.tarefaIniciar(titulo,{total})` -> `.concluir/.falhar`. Selects de conta: atributo `data-seletor="conta"` + `window.Seletores.varrer(modal)` (select2 com `role="dialog"` para dropdownParent).

### 5.1 Ecrã Contratos (lista) — CU:124-201
Título «Contratos de arrendamento»; sub «O contrato prende-se sempre a uma fracção. Daqui saem as rendas, os documentos e a conta corrente.»
KPIs (de `resumo()`): «Contratos activos (de N)» [#15803d]; «Renda mensal a receber (Kz)» [#1d4ed8]; (só se renda_mensal_paga>0) «Renda mensal a pagar (Kz)» [#b45309] e «Margem mensal (Kz)» [verde/vermelho]; «Cauções em poder da empresa (Kz)» [#7c3aed]; «Rendas vencidas por facturar» [#b45309 se >0]; (só se por_pagar>0) «Rendas vencidas por pagar» [#b91c1c]. Valores com 0 casas.
Caixa de avisos: «N ponto(s) a tratar:» lista os 8 primeiros textos de `avisos()` + «e mais N.».
Barra de filtros: «Procurar» (placeholder «número, inquilino, fracção ou imóvel»; debounce 250 ms; procura em numero, outra parte, fracção, imóvel — case-insens. includes); «Estado» (Todos + 5 estados); «Lado» (Os dois / A empresa recebe / A empresa paga). Botões: «Gerar rendas em falta» (`imoArrGerarTudo`), «Novo contrato» (`imoArrEditar(null)`).
Colunas: Número (+ «sob ${número da mãe}»), Tipo, Lado (selo), Outra parte (+ papel Inquilino/Senhorio), Imóvel / fracção (ou «N fracções» / «(imóvel inteiro)»), Vigência (inicio / fim_real||fim||«sem termo»), Renda (n), Periodicidade, Caução (recebida; «de X» se falta), Estado, acções [Editar (lápis), Eliminar (lixo)]. Linha clicável abre detalhe. Vazio: «Ainda não há contratos. O primeiro passo é ter a fracção registada em Imóveis.»
`imoArrGerarTudo`: para cada ACTIVO `gerarRendas(c.id,{ate: hoje})`; msg «N renda(s) prevista(s) criada(s).» + « M contrato(s) com problema.».
(Nota: a pesquisa filtra `lista` (já filtrada por estado/lado) mas usa `nomes` de todos.)

### 5.2 Detalhe do contrato — CU:226-372
Cabeçalho: botão «Contratos» (voltar); `numero` + selo estado + selo lado; sub «${Inquilino|Senhorio}: nome · imóvel — fracção(s) · início a fim»; se tem filhos: «Dá título a N subarrendamento(s): ...». Botões: «Activar» (RASCUNHO/SUSPENSO) ; «Actualizar renda» (ACTIVO); «Terminar / denunciar» (ACTIVO/SUSPENSO); «Editar».
Separadores: «Contrato», «Rendas (N)» (N = nº linhas da conta corrente), «Documentos».
**Separador Contrato**: 3 cartões + fiscalidade + notas + saída.
- Cartão «Condições»: Tipo; Depende de (link); Autoriza subarrendar (Sim / Não laranja) [só inquilino]; Conta de custo, Conta da retenção [só inquilino, se preenchidas]; Finalidade fiscal; Renda; Periodicidade («(adiantada)» / «(no fim do período)»); Vencimento «dia N»; Actualização («Não actualiza» | «X% a cada N meses» | «Kz a cada N meses»); Renovação («automática por N meses» | «não renova»); Pré-aviso «N dias»; Documento emitido (senhorio) / Pagamento «Documento de tesouraria pela conta X» (inquilino); Cópia na Repartição (data ou «em falta»; só senhorio).
- Cartão «Caução e encargos»: Caução acordada; «Caução recebida»/«Caução entregue»; «Em poder da empresa»/«Em poder do senhorio»; Devolvida; Retida (+ motivo); lista Encargos com «(incluído)» / «(a acrescer)» ou «Sem encargos autónomos: a renda inclui tudo.».
- Cartão «Conta corrente»: Facturado/«Em documento»; «Esperado em caixa»/«A sair de caixa»; Recebido/Pago; «Em dívida»/«Por pagar»; Juros de mora (se >0); «Imposto Predial retido pelo inquilino»/«Imposto Predial retido ao senhorio»; «Selo suportado pelo senhorio».
- «Fiscalidade de um período, à taxa de AAAA»: Base (renda + encargos a acrescer); Imposto Predial — X% efectivo (+ isenção); IVA (isento (Lei 14/23) / à taxa de N%); Imposto de Selo — N%, do senhorio/do inquilino/isento; «Total a facturar»; «Total a receber» (+ «(após retenção do inquilino)»). Calculado com `calcular(c, taxas(ano corrente), {renda: rendaNoPeriodo(hoje)})`. Texto explicativo condicional (retém / não retém; selo não entra na factura).
- Notas; aviso «Saída em ${fim_real}: ${motivo_saida}».
**Separador Rendas** (CU:332-372): botões «Gerar rendas em falta» (só ACTIVO; `imoArrGerar`) e «Imprimir conta corrente». Tabela colunas: Período (+de a ate), Vencimento (+ «N dia(s) de atraso»), Renda, Encargos, Base, IVA, «A facturar»/«Valor da renda», IP retido, «A receber»/«A pagar», «Recebido»/«Pago», «Dívida»/«Por pagar» (só se FACTURADA), Estado, Documento (doc_number | «Tesouraria #id» | —), acções: PREVISTA «Aprovar» (check); APROVADA lado senhorio «Facturar», lado inquilino «Pagar»; PREVISTA/APROVADA «Anular»; PAGA «Desligar o pagamento»; FACTURADA «Recibo» (imprime modelo RECIBO); FACTURADA com dívida>0.009 e atraso>0 «Interpelação» (modelo INTERPELACAO com divida/dias/juros). Rodapé «Totais facturados»/«Totais em documento». Ajuda condicional: «O recebimento vem dos recibos de Vendas — é lá que se recebe a renda...» / «O pagamento cria um documento na Tesouraria, em pendente. Conferir e integrar faz-se lá; alterar o valor também, e depois é preciso desligar e voltar a ligar aqui.»
**Separador Documentos** (CU:374-397): se não há modelos activos: «Ainda não há modelos de documentos nesta empresa.» + botão «Importar os modelos por omissão» (`imoArrImportarModelos`) e ajuda «Traz o contrato de arrendamento, o de subarrendamento, o recibo, o aviso, a interpelação, a actualização, a denúncia e o auto de entrega...». Caso contrário: grelha por tipo com um botão por modelo activo (`imoArrVerDoc(modeloId, leaseId)`). (O modelo de denúncia e o auto de vistoria só existem aqui; ao terminar contrato NÃO se abre automaticamente a carta de denúncia.)

### 5.3 Modais
- Modal «Documento» (`imo-doc-modal`, 820 px, CU:602-619): título = nome do modelo; sub = assunto; botões «Copiar» (clipboard; «Texto copiado.»), «Imprimir», «Fechar»; aviso «Faltam N dado(s): ... Aparecem entre parênteses rectos e não saem na impressão como texto normal — preencha-os no contrato antes de assinar.»; pré-visualização com `white-space: pre-wrap`.
- Modal «Terminar o contrato ${numero}» (`imo-sai-modal`, 580 px, CU:482-512): aviso se dívida (senhorio: «Há X em dívida. A saída não apaga a dívida: continua a cobrar-se.»; inquilino: «por pagar ao senhorio. A saída não apaga a obrigação.»); se filhos: caixa vermelha com checkbox «Compreendo e confirmo a cessação em cascata»; campos: «Data de saída *» (date, default hoje), «Natureza» (select: «Termo do contrato» (vazio) / «Denúncia» (valor 1)), «Motivo *» (textarea; placeholder «fim do prazo, denúncia pelo inquilino, incumprimento...»), cabeçalho «Caução — em poder da empresa: X», «Devolvida» (number, default = em poder), «Retida» (default 0), «Motivo da retenção» (ajuda «Retida sem motivo escrito é uma retenção indefensável.»). Botões «Cancelar», «Terminar». Resultado: «Contrato terminado. N renda(s) futura(s) anulada(s). [M subarrendamento(s) caducaram, com mais K renda(s) anulada(s).] A fracção voltou a disponível.»
- Modal «Actualizar a renda de ${numero}» (`imo-act-modal`, 520 px, CU:533-554): texto (sem cláusula: «Este contrato não tem cláusula de actualização: o novo valor tem de ser acordado por escrito com o inquilino.»; com: «A regra do contrato (X% a cada N meses | Kz a cada N meses) dá Y.»; «A actualização só afecta rendas ainda não facturadas.»); campos «Renda actual» (disabled), «Renda nova *» (default regra), «Data de efeito *»; checkbox «Recalcular as rendas ainda não facturadas a partir da data de efeito» (marcado). Após gravar: «Renda actualizada para X. N renda(s) recalculada(s).» e, se existe modelo ACTUALIZACAO, abre-o com `{renda_nova, renda_antiga, data_efeito}`.
- Modal «Pagar a renda de ${periodo}» (`imo-pagar-modal`, 520 px, CU:427-450): campos «Data do pagamento» (default vencimento), «Referência» (placeholder «n.º da transferência»), «Conta de disponibilidades *» (select; default `c.conta_financeira`); texto explica 2 linhas (custo X + retenção Y -> sai Z) ou «Sai Z de caixa.». Botões «Cancelar», «Criar pagamento». Mensagem: «Pagamento de X criado na Tesouraria (pendente)[, com Y de Imposto Predial retido].»
- Modal «Pagar N renda(s)» (lote, 560 px, CU:1170-1183): «Data do pagamento» (default hoje; ajuda «Em branco usa-se a data de vencimento de cada renda.»), «Referência» (placeholder «n.º da transferência ou do recibo do senhorio»), «Conta de disponibilidades *» (default a primeira conta_financeira encontrada), cartão «Total a sair de caixa: X». Botões «Cancelar», «Criar pagamentos». Mensagens: «N pagamento(s) criado(s) na Tesouraria, por conferir e integrar.» / «N criado(s); M falhou(aram).».
- Diálogos: «Anular renda» (pedir, obrigatório: «Porque está a anular esta renda?»), «Desligar o pagamento» (pedir: «A renda volta a «aprovada» e deixa de apontar para o documento de tesouraria. O documento não é apagado: trate dele na Tesouraria. Porquê?»), «Eliminar contrato» (confirmar perigo: «Eliminar o contrato ${numero} e as suas rendas não facturadas? Não se pode desfazer.» botão «Eliminar»), «Facturar rendas» (confirmar: «Vão ser emitidos N documento(s) fiscal(is). Depois de emitidos não se alteram nem se apagam: corrigem-se com nota de crédito. Confirma?» botão «Facturar»), «Vagas de estacionamento» (escolher: «Esta(s) fracção(ões) têm N vaga(s) afecta(s) que vão junto no contrato: ... O que se cobra por elas?» opções ACRESCER «Acrescer à renda pelo valor de referência», INCLUIR «Já estão incluídas na renda (sem valor acrescido)», IGNORAR «Não registar nos encargos»).
- Ao activar: «Contrato activo. A fracção fica disponível para subarrendar.» (inquilino) / «Contrato activo. A fracção passou a arrendada.».
- Impressão da conta corrente (CU:628-647): janela nova, A4 horizontal (`@page size:A4 landscape; margin:12mm`), título «Conta corrente — contrato ${numero}», sub «${inquilino} · ${imóvel} — ${fracção} · início a fim · emitida em ${hoje}»; colunas Período, Vencimento, Base, IVA, Facturado, IP retido, A receber, Recebido, Dívida, Atraso, Estado, Documento; tfoot «Totais facturados» (+ IP retido, esperado, recebido, em_divida); rodapé «Caução em poder da empresa: X Kz · Imposto de Selo suportado pelo senhorio: Y Kz»; `window.print()` após 350 ms. (Rótulos não adaptados ao lado inquilino.)

### 5.4 Formulário do contrato (`imo-lease-modal`, 900 px, CU:651-788)
Título «Novo contrato» / «Editar contrato ${numero}»; texto «O número é atribuído na gravação. O contrato prende-se a uma fracção: se o imóvel não tem divisões, crie-lhe a fracção «Única» em Imóveis.» Campos (todas as grelhas):
1. «Tipo *» (select 3 TIPOS; ajuda «O tipo decide de que lado a empresa está: nos dois primeiros recebe, no «tomado» paga.») | «Âmbito *» (3 AMBITOS; ajuda «Um prédio ou condomínio inteiro arrendase num só contrato, com uma só renda.»). 
2. «Fracção *» (modo FRACCAO): opções = fracções com `estado` DISPONIVEL ou RESERVADA (na edição todas) rotuladas `${imóvel} — ${designação}[ (ref. N Kz)]`; ajuda «Só aparecem as fracções livres.». Em PARCIAL/IMOVEL: «Imóvel *» (opções `${codigo} — ${nome} (${papel})`) + lista de caixas de fracções (`.il-un`) com área útil e estado; IMOVEL marca todas e desactiva; PARCIAL: botões «Marcar livres» / «Desmarcar»; ocupadas desactivadas e a opacidade 0,5; aviso para IMOVEL com ocupadas; textos «Escolha o imóvel para ver as fracções.», «Este imóvel não tem fracções registadas...», «A renda é uma só para todo o contrato. Na rentabilidade é repartida pelas fracções por área útil.»
3. SUBARRENDAMENTO: «Contrato tomado de que depende *» (opções `${numero} · fracção(ões) · início a fim · renda[ · NÃO autoriza subarrendar]`, filtradas por `data-unidades` à fracção escolhida; ajuda «O subarrendamento caduca com ele e não pode começar antes nem durar mais.» / «Esta fracção não tem contrato tomado. Registe primeiro o arrendamento em que a empresa é inquilina.»).
4. «Inquilino *» (rótulo muda para «Senhorio» em TOMADA; opções terceiros `nome · NIF`; ajuda «Vem dos terceiros. É a outra parte do contrato.») | «Documento de identificação» (placeholder «BI n.º 000000000LA000»; ajuda «O contrato precisa dele; a ficha do terceiro não o guarda.»); «Morada do inquilino» (ajuda «Em branco, usa-se a morada da ficha do terceiro.») | «Contacto» (placeholder «telefone ou email»).
5. «Início *» (default hoje) | «Fim» (ajuda «Em branco = sem termo.») | «Renovação» (Não renova / Automática; novo = Automática) | «Renovação (meses)» (default 12).
6. «Renda *» (default = `renda_referencia` da fracção) | «Periodicidade» | «Dia de vencimento» (1-28; ajuda «Até 28, para não falhar em Fevereiro.») | «Momento».
7. «Actualização» (3 opções; desactiva Valor e A cada se «NAO») | «Valor» (ajuda «% ou Kz, conforme a regra.») | «A cada (meses)» (12) | «Pré-aviso (dias)» (default 60 em novo).
8. «Caução acordada» (ajuda «No máximo seis rendas.») | «Caução recebida» | «Documento a emitir».
9. «Fiscalidade»: «Finalidade fiscal *» (ajuda «Decide o Selo (0,1% habitação, 0,4% o resto) e o IVA (só o alojamento hoteleiro é tributado).») | «Isenção do Imposto Predial» (ajuda «As isenções do OGE são anuais — confirme se ainda vigoram no ano em curso.») | «Imposto de Selo». Checkbox de retenção (rótulo muda: «O inquilino retém o Imposto Predial» / «A empresa retém o Imposto Predial ao senhorio», com subtexto de 15% menos) | «Cópia entregue na Repartição» (data; oculto em TOMADA; ajuda «A lei dá 10 dias a contar do início.»).
10. Caixa laranja TOMADA «A empresa é a inquilina — contabilização do pagamento» (texto «A renda sai pela Tesouraria, não por uma factura de venda. Estas contas são as que o documento de pagamento vai usar: o ERP nunca as pré-preenche.»; se sem plano de contas: aviso «Esta empresa ainda não tem plano de contas. Carregue-o em Configurações › Plano de Contas e volte aqui — sem contas de movimento não se pode registar um contrato tomado.»): «Conta de custo da renda *», «Conta da retenção» (ajuda «Só se a empresa retiver o Imposto Predial ao senhorio.»), «Conta de disponibilidades» (ajuda «Por onde sai o dinheiro. Pode escolher-se também no momento do pagamento.»); checkbox «O senhorio autoriza subarrendar» (subtexto «Sem autorização escrita não se registam subarrendamentos sobre este contrato — subarrendar sem ela é causa de resolução.»). Opções de conta: plano da empresa (`chart_of_accounts` where company_id anyOf [int, string], sem master data, ordenado lexicograficamente por `code`), formato `código — descrição`; uma conta gravada fora do plano mantém-se como «(conta fora do plano actual)».
11. «Encargos» (botão + e, se existe ImobiliarioParque, «Vagas afectas»): cabeçalho «Designação | Valor por período | Incluído na renda»; linhas com placeholder «condomínio, água, limpeza...» e checkbox «incluído»; ajuda «O que não está incluído na renda vai em linha própria da factura: o inquilino tem de ver o que é renda e o que é água, luz ou condomínio.»
12. «Notas» (textarea). Botões «Cancelar», «Gravar». Na gravação: se há vagas afectas por incluir pergunta (diálogo acima) e depois `gravarContrato`; mensagem «Contrato gravado.» (edição) ou «Contrato criado em rascunho — active-o para gerar rendas.»; abre o detalhe do contrato.
`imoArrTipoMudou` mostra/oculta caixas e filtra mães; `imoArrActMudou` desactiva campos; `imoArrAmbitoMudou`/`imoArrImovelMudou` gerem listas.
Integração com Parqueamento: `window.ImobiliarioParque.encargosPropostos(ids)` devolve `[{unit_id, designacao, detalhe, valor, incluido}]`; vira encargos `origem:'PARQUE'`.

### 5.5 Ecrã Rendas do período — CU:1050-1228
Título «Rendas do período»; sub dependente do lado. Separadores «A receber» (SENHORIO) | «A pagar» (INQUILINO). KPIs: «Previstas — X Kz», «Aprovadas — X Kz», «Facturadas»/«Pagas — X Kz», «A receber»/«A pagar» no período (Kz) (soma de `total_receber` de todas as linhas do lado). Barra: «Período» (input month; default mês de hoje), «Marcar todas», «Desmarcar», «Aprovar marcadas», «Facturar marcadas» (senhorio) / «Pagar marcadas» (inquilino). Ao abrir chama `proporPeriodo(periodo)` que, para TODOS os contratos ACTIVO, corre `gerarRendas(c.id,{de: inicio, ate: último dia do mês (periodicidade MENSAL forçada em limitesPeriodo)})` e devolve as rendas do período exacto `r.periodo === p` não anuladas (enriquecidas). Nota: só mostra contratos cuja renda COMEÇA nesse mês (para trimestral só aparecem nos meses 1,4,7,10 do ciclo a contar do início).
Tabela: checkbox (só PREVISTA/APROVADA), Contrato (link para o detalhe, aba rendas), «Inquilino»/«Senhorio», Imóvel/fracção, Vencimento, Renda, Encargos, Base, IVA, «A facturar»/«Valor da renda», IP retido, «A receber»/«A pagar», «Selo (senhorio)», Estado, Documento. Rodapé «Total do período». Vazio: «Nada a receber|a pagar neste período. [Há N renda(s) do outro lado — veja o separador ao lado.| Verifique se há contratos activos cuja vigência o cubra.]». Falhas de lote listadas na caixa «N renda(s) ficaram por tratar:». Textos de ajuda no rodapé (IP já entregue à AGT; selo é custo do senhorio / retenção e duas linhas na tesouraria).
Acções: Aprovar marcadas -> `aprovarRendas(ids)` («N renda(s) aprovada(s).»); Facturar -> confirmação + `facturarVarias` com tarefa «Facturação de rendas» (resultado «N factura(s) emitida(s).» / «N emitida(s); M falhou(aram).»); Pagar -> modal lote + `pagarVarias` com tarefa «Pagamento de rendas».

### 5.6 Ecrã Modelos e taxas — CU:1233-1370
Título «Modelos e taxas»; sub «Os modelos de documentos e as taxas fiscais do ano. As taxas guardam-se por ano porque mudam com o OGE.»
Cartão «Taxas de AAAA» (ano corrente): «Imposto Predial (%)», «Incide sobre (% da renda)» (ajuda «Efectivo: X%»), «Selo — habitação (%)», «Selo — outros fins (%)», «IVA arrendamento (%)» (ajuda «Isento desde a Lei 14/23.»), «IVA alojamento (%)», «Juro de mora mensal (%)» (ajuda «Só se o contrato o previr.»), «Prazo de registo (dias)» (ajuda «Cópia do contrato na Repartição Fiscal.»). Secção «Transmissão (compra e venda)» (texto «A SISA foi extinta: na transmissão há Imposto Predial e Selo, ambos a cargo do adquirente...»): «Imposto Predial da transmissão (%)», «Selo da transmissão (%)», «Escritura (Kz)», «Habitação isenta até (Kz)» (ajuda «0 = sem isenção este ano.»), «Redução de 50% até (Kz)». Secção «Detenção (pagar por ter o imóvel)»: «1.º escalão até (Kz)», «1.º escalão (%)», «2.º escalão até (Kz)», «2.º escalão — valor fixo (Kz)», «3.º escalão (%) sobre o excesso», «Terrenos (%)», «Agravamento por desocupação (%)», «Desocupado a partir de (meses)». Botão «Gravar taxas de AAAA» -> «Taxas de AAAA gravadas. As rendas já facturadas não mudam.» Só edita o ano corrente (não há selector de ano).
Secção «Modelos de documentos»: «N modelo(s). Uma variável sem valor fica entre parênteses rectos e é listada antes de imprimir.»; botões «Importar modelos por omissão» (`importarPadrao`: «N modelo(s) importado(s).»), «Novo modelo». Tabela: Tipo, Nome, Assunto, Linhas (nº de `\n`+1), Activo (selo Activo/Inactivo), [Editar, Eliminar]. Vazio «Sem modelos. Comece por importar os que vêm por omissão.»
Modal «Novo modelo»/«Editar modelo» (880 px): «Tipo *», «Nome *», «Assunto», «Texto *» (textarea 16 linhas monoespaçada), painel «Variáveis disponíveis — clique para inserir no texto» (insere `{{chave}}` na posição do cursor), checkbox «Activo». Eliminar: confirmar «Eliminar este modelo de documento?». Os modelos são listados ordenados por tipo e nome.

### 5.7 Ecrã Subarrendamento — SU:48-118
Título «Subarrendamento»; sub «O que entra e o que sai pela mesma fracção. A margem é o negócio; as fracções em baixo da lista são as que estão a custar dinheiro.» KPIs: «Recebido dos subarrendatários (Kz)», «Pago aos senhorios (Kz)», «Margem do período (Kz)», «Fracções com margem negativa», «Tomadas sem subarrendamento». Avisos: sem contratos tomados («Ainda não há contratos tomados. O subarrendamento começa por registar o arrendamento em que a empresa é a inquilina: ponha o imóvel com o papel «Arrendado» e crie um contrato do tipo «Arrendamento tomado». Só depois se podem subarrendar as suas fracções.»); fracções vazias («N fracção(ões) arrendada(s) pela empresa e ainda sem subarrendamento: imóvel — fracção (X Kz pagos); ...»). Filtros «De»/«Até» (month; default últimos 12 meses; erro «O início do intervalo é posterior ao fim.»), botão «Imprimir». Tabela: Imóvel, Fracção, Contratos tomados, Subarrendamentos («nenhum»), Pago, Recebido, Margem (cor), Taxa (% 1 casa), acções «Ver a cadeia» (só se há tomadas) e «Mês a mês». Rodapé «Total de de a ate». Ajuda: «A margem compara o que foi facturado ao subarrendatário com o que a empresa devia pagar no mesmo período, não o que já entrou ou saiu de caixa — para a margem não oscilar ao sabor dos atrasos de pagamento.»
Secção «Obras a debitar ao inquilino» («Ordens concluídas que o contrato manda debitar. Entram como encargo extraordinário numa renda ainda por facturar — não se emite documento só para elas.»): tabela Ordem, Fracção, Descrição (90 chars), Valor, Contrato activo, botão «Debitar» (só com contrato). Vazio «Nada a debitar.».
Modal «Cadeia da fracção» (760 px): por tomada: `numero — a empresa paga`, datas, estado, «NÃO autoriza subarrendar»; «Pago X · Recebido Y», «Margem Z»; tabela Subarrendamento (link)/Vigência/Estado/Facturado/Em dívida; vazio «Sem subarrendamentos[ — e o contrato não os autoriza].».
Modal «Mês a mês» (560 px): Período, Pago, Recebido, Margem + total.
Modal «Debitar a ordem ${numero}» (520 px): «Renda onde entra *» (rendas não-documento e não anuladas do contrato: `periodo · Estado · vence data`), «Valor a debitar *» (default custo_estimado; ajuda «Por omissão o custo estimado da ordem. Ajuste-o para o custo real, se o conhecer.»); texto «Entra como encargo extraordinário na renda escolhida. Não aumenta o Imposto Predial nem o Selo, que incidem sobre a renda — é um débito de despesa.»; mensagem «X debitados na renda de AAAA-MM.».
Impressão «Margem do subarrendamento» (A4 horizontal): colunas Imóvel, Fracção, Tomados, Subarrendamentos, Pago, Recebido, Margem, Taxa; rodapé de vazias e nota «A margem compara o facturado com o devido no mesmo período, não o movimento de caixa.».

---------------------------------------------------------------------
## 6. LIGAÇÕES A OUTROS MÓDULOS

| Módulo | Função / linha | Dados que passam | O que se grava de cada lado |
|---|---|---|---|
| Vendas/facturação | `facturarRenda` CT:864-971 (usa `window.FacturaAGT.numerar/numerarLocal/libertar/selar`, `window.carregarPacote('vendas')`) | ver 3.12 | `sales` + `sale_items` com `imo_lease_id/imo_rent_id`; renda -> FACTURADA (`sale_id`, `doc_number`, `data_factura`) |
| Recibos (recebimento) | `contaCorrente` CT:1003-1005; `avisos` CT:1105-1109; `TP.contexto` TP:500-503 | lê `receipt_items.amount_paid` por `sale_id` | só leitura: o recebimento é feito em Vendas; o contrato não lança recibos |
| Tesouraria | `pagarRenda` SB:36-130; `desligarPagamento` SB:142-157; `contaCorrente` CT:1006-1009 | doc PAGAMENTO PENDENTE + 1/2 linhas | `treasury_documents`+`treasury_items`; renda -> PAGA; integra-se/contabiliza-se na Tesouraria (aqui nunca se lança) |
| Contabilidade (plano de contas) | `validarContaDoPlano` CT:213-228; UI `contasDoPlano` CU:97-119 | códigos de conta | só validação; os lançamentos são criados quando a Tesouraria integra o documento (contas custo D, retenção C, disponibilidades em `account_fin`). Facturas: contabilizadas pelo módulo Vendas |
| Terceiros | `window.Imobiliario.terceiros()` (CU:653; CT:796; TP:484); `third_party_addresses` TP:491-494 | `terceiro_id`; morada por NIF | cliente da factura (`customer_id`) e `third_party_id` das linhas de tesouraria |
| Imóveis/fracções (imo_units, imo_properties) | CT:313-355, 513-524, 563-575 | estado da fracção (DISPONIVEL/RESERVADA/ARRENDADA) | `imo_units.estado` actualizado só no lado senhorio |
| Parqueamento | `ImobiliarioParque.encargosPropostos` CU:872,892; `park_afectacao/park_unit_id` CT:318, 568-575 | vagas -> encargos | encargos com `origem:'PARQUE'`, `unit_id` |
| Obras | `ImobiliarioObras.porDebitar/obras` SB:249-301; `imo_works.update` SB:299 | obras concluídas debitáveis | extras da renda; `debitado_em/debitado_rent_id/debitado_valor` na obra |
| Rentabilidade / Ficha / Fiscal | outros ficheiros lêem `imo_leases` (rentabilidade.js:172, fiscal.js:125, ficha_ui.js:249-274) — fora do âmbito, mas consomem `estado`, `posicao`, `unidades` | — | — |
| Notificações | `window.Notificacoes.mostrar` (todas as UIs) ; diálogos `window.Dialogo` | mensagens | só UI. NÃO há criação de alertas persistentes/agendados: `avisos()` é só calculada ao abrir o ecrã de contratos |
| Auditoria | nenhuma chamada nestes 5 ficheiros | — | sem log de auditoria próprio (só o que o ERP faça no hook de BD; não confirmado) |
| Permissões | js/permissoes.js:73-103 | ver 9 | — |
| Anexos | campo `docs` (URLs normalizadas com `Imobiliario.normalizarUrl`) CT:486; NÃO há UI para editar `docs` nos ecrãs lidos (campo gravado mas sem input no formulário) | — | — |
| Impressão/PDF | `TP.imprimir` TP:615-633 (A4 vertical, margens 22/20 mm, Georgia 11.5pt, `white-space:pre-wrap`, caixa «faltas» oculta em @media print); `imoArrImprimirCC`; `imoSubImprimir` (A4 horizontal) | — | janela `window.open` + `window.print()` após 350 ms; sem PDF servidor |
| Companhia | `companies.get(cid())` TP:483 | nome, NIF, morada | — |

---------------------------------------------------------------------
## 7. TEMPLATES DE DOCUMENTOS (imobiliario_templates.js)

Motor: `{{variavel}}` com regex `/\{\{\s*([a-z_]+)\s*\}\}/g` (TP:606). Valor ausente/vazio -> `[Rótulo]` e entra em `faltas` (lista de rótulos únicos). `aplicar(modeloId, leaseId, opcoes)` devolve `{modelo, assunto, corpo, faltas, contexto}`. `opcoes` aceites: `rent_id, data, divida, dias_atraso, juros, renda_nova, renda_antiga, data_efeito, motivo`.

VARIAVEIS (chave -> rótulo, TP:36-67): data_hoje «Data de hoje»; empresa «Empresa»; empresa_nif «NIF da empresa»; empresa_morada «Morada da empresa»; senhorio «Senhorio»; senhorio_nif «NIF do senhorio»; senhorio_morada «Morada do senhorio»; senhorio_doc «Documento de identificação do senhorio»; inquilino «Inquilino»; inquilino_nif «NIF do inquilino»; inquilino_morada «Morada do inquilino»; inquilino_doc «Documento de identificação do inquilino»; contrato «Número do contrato»; tipo_contrato «Tipo de contrato»; imovel «Imóvel»; imovel_morada «Morada do imóvel»; imovel_municipio «Município»; imovel_provincia «Província»; imovel_matriz «Artigo matricial»; fraccao «Fracção»; fraccao_piso «Piso»; fraccao_tipologia «Tipologia»; fraccao_area «Área útil»; fraccao_quartos «Número de quartos»; finalidade «Finalidade»; mobilada «Mobilada»; inicio «Data de início»; fim «Data de fim»; duracao «Duração»; renovacao «Renovação»; aviso_dias «Pré-aviso em dias»; renda «Renda»; renda_letras «Renda por extenso»; periodicidade «Periodicidade»; dia_vencimento «Dia de vencimento»; momento_pagamento «Momento do pagamento»; caucao «Caução»; caucao_meses «Caução em meses de renda»; encargos «Encargos»; actualizacao «Regra de actualização»; ip_taxa «Taxa efectiva do Imposto Predial»; ip_valor «Imposto Predial da renda»; retencao «Quem retém o Imposto Predial»; selo «Imposto de Selo»; selo_encargo «Quem suporta o Imposto de Selo»; iva «IVA»; periodo «Período da renda»; vencimento «Data de vencimento»; total_facturar «Total a facturar»; total_receber «Total a receber»; recebido «Valor efectivamente recebido»; divida «Valor em dívida»; dias_atraso «Dias de atraso»; juros «Juros de mora»; factura «Número da factura»; renda_nova «Renda actualizada»; renda_antiga «Renda anterior»; data_efeito «Data de efeito»; motivo «Motivo». (61 variáveis.)

Cálculo de cada variável (`contexto`, TP:475-591):
- `data_hoje`/`inicio`/`fim`/`vencimento`/`data_efeito`: `dataPT(iso)` = «D de Mês de AAAA» (meses por extenso em português com maiúscula: Janeiro...Dezembro), vazio se formato inválido.
- Importes: `fmt(v)` = `Intl.NumberFormat('pt-PT', 2 casas) + ' Kz'` (separador pt-PT: milhares com espaço fino, vírgula decimal).
- `renda_letras = extenso(v.renda)`: kwanzas por extenso (algoritmo em TP:418-468: escalas mil milhões/milhão/mil; «cem»/«cento»; «e» entre parcelas; «de» antes de kwanzas quando termina em milhão/milhões/mil milhões; céntimos «e X cêntimo(s)»; 0 -> «zero kwanzas»; singular «kwanza» para 1).
- `renda` = renda do período (`rendaNoPeriodo(c, período da renda ou do mês de `data`/hoje)`), não `c.renda`.
- `senhorio`/`senhorio_nif`/`senhorio_morada`: se lado SENHORIO = empresa (nome `emp.name||nome`, NIF `emp.tax_id||nif`, morada `emp.address||morada`); se INQUILINO = o terceiro (`inq`) e morada do contrato/ficha. `senhorio_doc` = `c.inquilino_doc` só se a empresa é inquilina; `inquilino_*` troca em simetria (doc do contrato só se empresa é senhoria).
- Morada da outra parte: `c.inquilino_morada` || `third_party_addresses{company_id,nif}` -> `street, door_number, neighborhood, municipality, province` unidos por ', '.
- `tipo_contrato = TIPOS[c.tipo]` (rótulo completo com parênteses).
- Imóvel: `nome||codigo`; `morada`, `municipio`, `provincia`, `matriz_predial`.
- Fracção: `designacao||codigo`; `piso` via `Imobiliario.nomePiso(u.piso)`; `tipologia` via `Imobiliario.TIPOLOGIAS[u.tipologia]||u.tipologia`; `area_util`, `quartos` (só se >0).
- `finalidade` = rótulo fiscal em minúsculas; `mobilada` = «mobilado» (SIM) | «parcialmente mobilado» (PARCIAL) | «sem mobília».
- `duracao` = «N meses» com `N = max(1, round(diasEntre(inicio, fim)/30.4))` (só se há fim).
- `renovacao` = texto: AUTO «Findo o prazo, o contrato renova-se automaticamente por períodos sucessivos de N meses, salvo denúncia de qualquer das partes.»; NAO «Findo o prazo, o contrato cessa sem necessidade de qualquer comunicação, não havendo renovação automática.»
- `aviso_dias` (só >0), `periodicidade` (rótulo em minúsculas), `dia_vencimento`, `momento_pagamento` («no final de cada período» | «adiantadamente, no início de cada período»).
- `caucao` fmt; `caucao_meses = round(caucao/renda*10)/10 + ' mês(es)'` (usa `c.renda`).
- `encargos` = lista `designacao: fmt(valor) (incluído na renda|a acrescer à renda)` separada por '; ' + '.', ou «Não há encargos autónomos: a renda inclui tudo o que não seja consumo próprio do inquilino.»
- `actualizacao` = «A renda não é objecto de actualização automática durante a vigência do contrato.» | «A renda é actualizada em X% a cada N meses de vigência.» | «A renda é actualizada em <fmt> a cada N meses de vigência.»
- `ip_taxa = round(ip_taxa*ip_base)/100 + '%'` (taxa efectiva); `ip_valor`, `selo`, `iva` («isento» ou «sujeito a IVA à taxa de N%»), `retencao` (2 textos: retém inquilino «A retenção na fonte é feita pelo inquilino, que entrega o imposto à Administração Geral Tributária e deduz o respectivo valor da renda a pagar.» / «O imposto é liquidado e pago pelo senhorio, por o inquilino não estar obrigado a retenção na fonte.»), `selo_encargo` («por nenhuma das partes, por isenção» | «pelo inquilino» | «pelo senhorio»). Calculados a partir de `calcular(c, taxas(ano corrente), {renda})`.
- `periodo, vencimento, factura (doc_number)`: só com renda. `total_facturar/total_receber` da renda ou do cálculo. `recebido` = soma receipt_items (só com renda). `divida` = `o.divida` (interpelação) ou `total_receber - recebido`. `dias_atraso`, `juros` só vêm de `opcoes`. `renda_antiga` default `fmt(c.renda)`.

Modelos por omissão (MODELOS_PADRAO, TP:69-355) — 8, um por tipo; nomes/assuntos:
1. CONTRATO «Contrato de arrendamento urbano», assunto «Contrato de arrendamento — {{fraccao}}». Secções: título CONTRATO DE ARRENDAMENTO URBANO; partes (PRIMEIRO OUTORGANTE (SENHORIO), SEGUNDO OUTORGANTE (INQUILINO)); CLÁUSULA 1.ª Objecto; 2.ª Finalidade; 3.ª Prazo; 4.ª Renda; 5.ª Actualização; 6.ª Caução (devolução 30 dias após entrega das chaves, deduzidas dívidas e danos); 7.ª Obrigações fiscais (IP, Selo, IVA, entrega de cópia na Repartição Fiscal); 8.ª Conservação e obras; 9.ª Cessão e subarrendamento (proibidos sem autorização escrita); 10.ª Resolução (renda em atraso por período superior a três meses, uso diverso, violação da 9.ª); 11.ª Foro; fecho «Feito em duplicado...», `{{imovel_municipio}}, {{data_hoje}}`, assinaturas.
2. CONTRATO_SUB «Contrato de subarrendamento», assunto «Contrato de subarrendamento — {{fraccao}}». Partes: PRIMEIRO OUTORGANTE (SUBLOCADOR) = {{empresa}} (NIF, sede) «devidamente autorizado pelo respectivo senhorio a subarrendá-lo»; SEGUNDO OUTORGANTE (SUBARRENDATÁRIO). Cláusulas: 1.ª Objecto e título; 2.ª Dependência do contrato principal (CADUCA automaticamente, sem indemnização); 3.ª Finalidade; 4.ª Prazo; 5.ª Renda; 6.ª Actualização; 7.ª Caução; 8.ª Obrigações fiscais; 9.ª Proibição de novo subarrendamento; 10.ª Foro.
3. RECIBO «Recibo de renda», assunto «Recibo de renda — {{periodo}}»: declaração de recebimento (recebido, período, fracção, imóvel, contrato), linhas Renda e encargos do período / Imposto Predial / Valor devido após retenção / Valor recebido / Valor ainda em dívida / Documento de suporte {{factura}}.
4. AVISO «Aviso de vencimento da renda», assunto «Renda de {{periodo}} — vencimento a {{vencimento}}».
5. INTERPELACAO «Interpelação por mora», assunto «Renda em atraso — contrato {{contrato}}»: prazo 8 (oito) dias para liquidação; advertência de resolução se atraso > 3 meses.
6. ACTUALIZACAO «Carta de actualização da renda», assunto «Actualização da renda — contrato {{contrato}}» (renda_antiga, renda_nova, actualizacao, data_efeito).
7. DENUNCIA «Carta de denúncia do contrato», assunto «Denúncia do contrato {{contrato}}» (usa data_efeito, aviso_dias, motivo, caucao).
8. VISTORIA «Auto de entrega e vistoria», assunto «Auto de entrega — {{fraccao}}» (campos em branco `____` para estado do arrendado, contadores, chaves, inventário, observações; assinaturas).
(Texto integral dos corpos em TP:73-353; deve ser copiado literalmente para os seeds.)
Observação: «Foro» e prazos legais são texto fixo dos modelos; não há dependência de configuração.
Persistência: `is_master_data: 1` em todos os modelos gravados (incluindo os criados pelo utilizador — dúvida, ver 10). `importarPadrao` não repõe modelos apagados se mudar o nome (dedupe por tipo|nome).
Impressão: ver tabela de ligações; título da janela = nome do modelo.

---------------------------------------------------------------------
## 8. RELATÓRIOS / EXPORTAÇÕES / IMPRESSÕES

- Documento do modelo: copiar texto; imprimir (A4 vertical).
- Conta corrente do contrato: imprimir (A4 horizontal), cols em 5.3.
- Margem do subarrendamento: imprimir (A4 horizontal) + ecrã com «Mês a mês» e «Cadeia».
- Interpelação/recibo/actualização: via modelos.
- Não há exportação Excel/CSV/PDF nestes ficheiros. Nem listagem de rendas imprimível. Nem alertas por email.

---------------------------------------------------------------------
## 9. PERMISSÕES (js/permissoes.js:73-103; chaves reais, não aplicadas dentro dos 5 ficheiros — o controlo é feito por nome das funções globais `window.xxx` listadas em cada tarefa)

Ecrãs (E): `imo_contratos` «Gestão imobiliária — contratos de arrendamento»; `imo_rendas` «... rendas do período»; `imo_sub` «... subarrendamento e margem»; `imo_modelos` «... modelos de documentos e taxas».
Tarefas (T(chave, rótulo, [funções], flag)):
- imo_contrato_editar «Criar e editar contratos» [imoArrGravar, imoArrEditar]
- imo_contrato_activar «Activar contratos e actualizar rendas» [imoArrActivar, imoArrConfirmarAct] (flag 1 = sensível/segregação)
- imo_contrato_terminar «Terminar, denunciar e eliminar contratos» [imoArrConfirmarSai, imoArrEliminar] (1)
- imo_contrato_docs «Emitir contratos, recibos e cartas» [imoArrVerDoc, imoArrDoc, imoArrImprimirCC]
- imo_renda_gerar «Gerar e recalcular rendas previstas» [imoArrGerar, imoArrGerarTudo]
- imo_renda_aprovar «Aprovar e anular rendas» [imoRendasAprovar, imoArrAprovar, imoArrAnular] (1)
- imo_renda_facturar «Facturar rendas (a receber)» [imoRendasFacturar, imoArrFacturar] (1)
- imo_renda_pagar «Pagar rendas (a pagar)» [imoConfirmarPagar, imoConfirmarPagarUma, imoArrDesligar] (1)
- imo_sub_debitar «Debitar obras ao inquilino» [imoConfirmarDeb] (1)
- imo_modelo_editar «Criar e editar modelos» [imoModeloGravar, imoModeloEliminar, imoArrImportarModelos, imoArrImportarModelos2]
- imo_taxas_editar «Alterar as taxas fiscais do ano» [imoGravarTaxas] (1)
Notas do catálogo: contratos «Terminar um contrato anula as rendas futuras ainda não facturadas e liberta a fracção.»; rendas «Facturar emite documento fiscal selado e pagar cria movimento de tesouraria: separe quem aprova de quem executa.»; sub «Mostra o que entra e o que sai por cada fracção; quem o vê fica a ver as rendas dos dois lados.»; modelos «As taxas mudam o cálculo de todas as rendas ainda não facturadas.»
Lacunas de mapeamento (funções sem permissão associada no catálogo): `imoArrActualizar` (abre o modal), `imoArrTerminar` (abre o modal), `imoRendasPagar`, `imoRendasLado`, `imoRendasPeriodo`, `imoSubCadeia`, `imoSubDebitar` (só imoConfirmarDeb está), `imoArrImprimirDoc`, `imoArrImportar...` (ok). O legado mantém também `permissoes` nos formulários (js/shared/formularios_linhas_config.js:676 `imo('imo_contratos',...)` — não lido; fora do âmbito).

---------------------------------------------------------------------
## 10. CÓDIGO MORTO / DÚVIDAS / O QUE NÃO CONFIRMEI

1. SUSPENSO nunca é atribuído (não há suspender). Está nos enums, conflitos, activação (SUSPENSO->ACTIVO), botão Terminar. Decidir se se implementa a transição ACTIVO->SUSPENSO (no legado é um estado órfão).
2. `renovacao AUTO` nunca renova: apenas aviso/texto. `EXPIRADO` pede acção manual.
3. `c.mora_mensal` (contrato) é lido em `contaCorrente` (CT:1015) mas nunca gravado nem editável; só vale a taxa do ano (`t.mora_mensal`). O texto da UI «Só se o contrato o previr.» sugere campo por contrato inexistente.
4. Campo `desconto` nas rendas: usado no cálculo e na factura, mas sem UI para o definir (só via `recalcularRenda(extras.desconto)` por código). `recalcularRenda` preserva `r.desconto`.
5. `docs` (anexos) sem UI nos ecrãs lidos.
6. `imoArrActualizar` + `recalcularRenda`: o filtro da UI (CU:574) só exclui FACTURADA/ANULADA; uma renda PAGA lançaria «Uma renda que já é documento não se recalcula.» e interromperia o ciclo a meio (a renda do contrato já fora gravada). Em contratos tomados com rendas PAGA, a actualização pode falhar parcialmente.
7. `recalcularRenda` não inclui `extras` (obras debitadas) no cálculo -> perde o débito no total mas mantém-no nas linhas da factura (inconsistência SB:287-298 vs CT:655-685, CT:951-959).
8. `debitarObra` não recalcula IVA; usa `iva_valor` antigo (aceitável porque isento).
9. `proporPeriodo` força periodicidade MENSAL para calcular o fim de intervalo (CT:775) mas filtra por `r.periodo === p`; contratos trimestrais/semestrais/anuais só aparecem no mês em que o seu período começa.
10. Sem pro-rata (primeiro/último período ou saída a meio do período) nem arredondamento de dias. A saída só anula rendas futuras (`de > data`).
11. `terminarContrato` não valida estado de origem (aceita RASCUNHO/TERMINADO se chamado por código) nem coerência de `caucao_devolvida + caucao_retida` com a caução em poder; a exigência do motivo de retenção é só da UI.
12. Caução: nenhum lançamento contabilístico/tesouraria da recepção/devolução; só campos informativos.
13. A numeração é por (prefixo, ano corrente do sistema), não ano do contrato; sem atomicidade.
14. `margem` não mensaliza periodicidades diferentes (o comentário diz que sim); usa `total_facturar` (antes de retenção) nos dois lados e inclui rendas de contratos em qualquer estado; agrupa pela fracção principal (`unit_id`) – contratos multi-fracção só contam na principal.
15. `resumo.caucoes` soma todos os contratos (incluindo os do lado inquilino e os terminados); caução entregue pela empresa (tomada) conta como "em poder da empresa" no KPI (mistura os lados). Confirmado no código: sem filtro por posição (CT:1134).
16. `aplicar`: a variável `senhorio_doc` fica vazia (e portanto «[Documento de identificação do senhorio]») quando a empresa é senhoria (nem a empresa tem campo de BI); `empresa_morada` depende de `companies.address`. Variáveis `fraccao_*` usam apenas a fracção principal.
17. Modelos gravados têm `is_master_data: 1` também quando criados pelo utilizador (TP:380); possível significado: dados de sistema não eliminados em reset — não confirmado.
18. Cor `PAGA` ausente em CORES_RENDA (renderiza como PREVISTA, cinzento).
19. Impressão da conta corrente não troca rótulos para o lado inquilino (Facturado/A receber/Recebido/Dívida).
20. IP/Selo/IVA ao nível da tabela: o cálculo usa `t = taxas(ano de ate)` na geração (CT:753: ano da data limite do intervalo, `new Date(ate).getFullYear()`), `taxas(ano de r.de)` no recálculo e `taxas(ano corrente)` no detalhe/conta corrente — três critérios diferentes.
21. Não confirmei: comportamento de `window.Imobiliario` (terceiros(), imoveis(), unidades(), normalizarUrl, nomePiso, TIPOLOGIAS, PAPEIS, ESTADOS_UNIDADE) — está em imobiliario_dados.js (fora do pedido); `FacturaAGT` (módulo de vendas); `ImobiliarioObras.porDebitar/obras`; `ImobiliarioParque.encargosPropostos`; `Dialogo`, `Seletores`, `tarefaIniciar`. Também não confirmei o que o hook de BD faz em auditoria/RLS nas tabelas imo_*.
22. Não há listener/rota de jobs: nada gera rendas ou avisos automaticamente (só botões e abertura de ecrã).
23. O `ambito` na edição: ao editar, a lista de fracções disponíveis no select de FRACCAO inclui todas (CU:656 `c ? true`).
24. `dia_vencimento` limitado a 28 no servidor, mas `vencimentoDe` ainda faz `min(dia, ultimoDiaDoMês)` (redundante).
25. Mensagem da UI de «Encargos»: o encargo incluído com valor 0 permanece (prova de decisão); filtro `valor>0 || incluido`.

---------------------------------------------------------------------
## 11. FUNCIONALIDADES ATÓMICAS TESTÁVEIS (rascunho)

Contratos — modelo e numeração
- IMO-B-001 Criar contrato ARRENDAMENTO de uma fracção livre com terceiro, início, renda > 0: grava em RASCUNHO com `numero` `AR AAAA/0001` (CT:496, 257-269).
- IMO-B-002 Numeração sequencial por prefixo e ano: segundo ARRENDAMENTO = `AR AAAA/0002`; SUBARRENDAMENTO `SB`, TOMADA `AT` com séries independentes.
- IMO-B-003 `posicao` derivada do tipo (TOMADA=INQUILINO; restantes=SENHORIO).
- IMO-B-004 Âmbito FRACCAO exige fracção («Escolha a fracção...»).
- IMO-B-005 Âmbito IMOVEL abrange todas as fracções arrendáveis do imóvel; exclui vagas VISITA e CARGA_DESCARGA; fracção principal = primeira.
- IMO-B-006 Âmbito PARCIAL exige >= 2 fracções do mesmo imóvel (mensagens distintas para outro imóvel e para <2).
- IMO-B-007 Imóvel sem fracções recusa âmbito IMOVEL/PARCIAL.
- IMO-B-008 Terceiro obrigatório com mensagem diferente por lado.
- IMO-B-009 Inquilino não pode ser o proprietário do imóvel (lado senhorio).
- IMO-B-010 TOMADA exige imóvel com papel ARRENDADO.
- IMO-B-011 TOMADA exige conta de custo; conta de retenção se retém IP; validação das 3 contas contra o plano (inexistente, totalizadora, plano vazio).
- IMO-B-012 TOMADA: `permite_sub` 0/1 e `parent_lease_id=null`; não-TOMADA: contas '' e `permite_sub=0`.
- IMO-B-013 SUBARRENDAMENTO exige imóvel ARRENDADO.
- IMO-B-014 SUBARRENDAMENTO exige tomada (ACTIVO/RASCUNHO/SUSPENSO) sobre a fracção; escolhe automaticamente se só há uma; exige escolha se várias.
- IMO-B-015 SUBARRENDAMENTO recusa mãe sem `permite_sub`.
- IMO-B-016 SUBARRENDAMENTO recusa fracções não cobertas pela mãe.
- IMO-B-017 SUBARRENDAMENTO recusa início anterior ao da mãe.
- IMO-B-018 SUBARRENDAMENTO recusa fim posterior ao da mãe (usa fim_real se existir) ou sem termo se a mãe tem fim.
- IMO-B-019 ARRENDAMENTO recusa imóvel com papel MEDIACAO.
- IMO-B-020 Data de início obrigatória; fim > início.
- IMO-B-021 Renda > 0, arredondada a 2 casas.
- IMO-B-022 Normalizações: periodicidade (default MENSAL), dia 1..28, antecipada (default true), finalidade (default HABITACAO), isenção (NAO), retenção (true), selo (SENHORIO).
- IMO-B-023 Caução <= 6 x renda; recebida <= acordada.
- IMO-B-024 Actualização PERC em (0,100], VALOR/PERC exigem valor>0; meses default 12.
- IMO-B-025 Renovação (NAO/AUTO), renovacao_meses>=1 (12), aviso_dias>=0.
- IMO-B-026 Encargos: filtragem designacao não vazia e (valor>0 ou incluído); guarda origem e unit_id.
- IMO-B-027 `doc_type` Factura / Factura-Recibo.
- IMO-B-028 Conflito de datas e fracção entre contratos do mesmo lado em RASCUNHO/ACTIVO/SUSPENSO (mensagem com nomes e datas); tomada e subarrendamento da mesma fracção não conflituam.
- IMO-B-029 Edição conserva número e estado.
- IMO-B-030 Gravar com estado ACTIVO marca fracções ARRENDADA (só lado senhorio).

Activação / terminação / eliminação
- IMO-B-031 Activar RASCUNHO/SUSPENSO -> ACTIVO; recusa TERMINADO/DENUNCIADO.
- IMO-B-032 Activar subarrendamento exige mãe existente e ACTIVA.
- IMO-B-033 Activar recusa fracção já em contrato ACTIVO do mesmo lado e vaga PERMANENTE afecta a fracção arrendada.
- IMO-B-034 Activar contrato lado senhorio marca fracções ARRENDADA; lado inquilino não altera estado das fracções.
- IMO-B-035 Terminar exige data >= início e motivo; estado TERMINADO (termo) ou DENUNCIADO (denúncia).
- IMO-B-036 Terminar anula rendas não documento com `de > data`, com nota «· anulada pela saída em AAAA-MM-DD»; mantém FACTURADA/PAGA/ANULADA.
- IMO-B-037 Terminar grava fim_real, motivo_saida, caucao_devolvida/retida/motivo e liberta fracções (DISPONIVEL, só lado senhorio).
- IMO-B-038 Terminar mãe com filhos vivos exige cascata; sem confirmação rejeita com lista de números.
- IMO-B-039 Cascata termina cada filho com a mesma data, motivo «Caducidade: ...», caução devolvida = recebida, retida = 0; devolve contagens.
- IMO-B-040 Eliminar: recusa ACTIVO/SUSPENSO, com filhos e com rendas em documento; apaga rendas e contrato.
- IMO-B-041 UI Terminar: caução devolvida default = em poder; motivo de retenção obrigatório se retida>0; aviso de dívida; checkbox cascata só quando há filhos.

Rendas — cálculo
- IMO-B-042 Períodos por periodicidade (limitesPeriodo) e fim de período correcto (meses de 28/30/31 dias).
- IMO-B-043 Vencimento: antecipada = dia do 1.º mês; no fim do período = dia do último mês; limitado ao último dia do mês.
- IMO-B-044 Geração de rendas: só ACTIVO; não duplica períodos existentes; respeita fim/fim_real; estado PREVISTA; copia posicao; calcula via `calcular`.
- IMO-B-045 Fórmula base = renda + encargos a acrescer - desconto.
- IMO-B-046 Imposto Predial = base x 60% x 25% (taxas do ano); isenção TOTAL=0; METADE=ip/2.
- IMO-B-047 Selo = base x 0,1% (habitação) / 0,4% (outros), 0 se ISENTO; não entra no total a facturar.
- IMO-B-048 IVA 0% arrendamento, 7% alojamento hoteleiro.
- IMO-B-049 total_facturar = base + IVA; total_receber = total_facturar - IP se `retencao_ip`.
- IMO-B-050 custo_senhorio = selo (se encargo senhorio) + IP (se não retém).
- IMO-B-051 Actualização PERC composta por N ciclos completos desde `actualizacao_base` ou início; VALOR linear.
- IMO-B-052 Actualização manual: grava renda, ultima_actualizacao, renda_anterior, actualizacao_base; recalcula rendas não facturadas a partir da data de efeito; abre carta ACTUALIZACAO.
- IMO-B-053 Taxas por ano: gravar/ler (`imo_settings.taxas[ano]`), valores base por omissão, validação IP <= 100%.
- IMO-B-054 `proporPeriodo` gera rendas de contratos ACTIVO e lista as do período (sem anuladas), por lado.
- IMO-B-055 Aprovar PREVISTA -> APROVADA em lote (ignora estados outros).
- IMO-B-056 Anular renda PREVISTA/APROVADA exige motivo; recusa FACTURADA («nota de crédito») e PAGA («anule-o na Tesouraria»).
- IMO-B-057 Recalcular renda não-documento (taxas/renda/desconto).

Facturação / recebimento
- IMO-B-058 Facturar exige lado senhorio, renda APROVADA (mensagens por estado).
- IMO-B-059 Numeração AGT/local/fallback `FT|FR AAAA/N`; libertar reserva em falha; selar após gravar.
- IMO-B-060 Cabeçalho da venda (customer, due_date = vencimento, related_doc_id = número do contrato, status PENDENTE, subtotal/total_tax/total_amount, imo_lease_id, imo_rent_id).
- IMO-B-061 Linhas da factura: renda, encargos a acrescer, extras (obras) — com IVA da renda.
- IMO-B-062 Renda passa a FACTURADA com sale_id, doc_number, data_factura.
- IMO-B-063 Facturar em lote devolve feitas/falhas; UI com confirmação e lista de falhas.
- IMO-B-064 Conta corrente: recebido = soma de recibos da venda; dívida = total_receber - recebido; atraso em dias (só documentos vencidos com dívida); juros simples = dívida x mora% x dias/30.
- IMO-B-065 Totais da conta corrente (facturado, esperado, recebido, em dívida, juros, IP retido, selo suportado, caução em poder).
- IMO-B-066 Impressão da conta corrente (A4 horizontal).

Lado inquilino / tesouraria
- IMO-B-067 Pagar exige lado inquilino, renda APROVADA, conta de custo e de disponibilidades; retenção exige conta de retenção.
- IMO-B-068 Documento de tesouraria PAGAMENTO PENDENTE: total_value = base - IP retido; linha D custo; linha C retenção; reference = doc_number ou nº do contrato.
- IMO-B-069 Recusa pagar renda com IVA e com valor divergente de total_receber (>0,02).
- IMO-B-070 Renda passa a PAGA (treasury_id, data_pagamento).
- IMO-B-071 Pagar em lote devolve feitas/falhas.
- IMO-B-072 Desligar pagamento: motivo; recusa documento INTEGRADO; renda volta a APROVADA e perde treasury_id; nota registada.
- IMO-B-073 Conta corrente lado inquilino usa treasury_document (exclui ANULADO).

Subarrendamento / margem / obras
- IMO-B-074 Margem por fracção no intervalo (recebe, paga, margem, taxa %).
- IMO-B-075 Totais, fracções com margem negativa, tomadas sem subarrendamento; ordenação ASC por margem.
- IMO-B-076 Cadeia: mãe + filhos, pago/recebido/margem, vivos.
- IMO-B-077 Mês a mês por fracção.
- IMO-B-078 Imprimir margem (A4 horizontal).
- IMO-B-079 Obras a debitar: concluídas, debitáveis, não debitadas; contrato activo lado senhorio sobre a fracção.
- IMO-B-080 Debitar obra: estados/mensagens; extras na renda; base/total_facturar/total_receber recalculados; IP e Selo inalterados; marca a obra como debitada.

Avisos / KPIs
- IMO-B-081 Avisos: RASCUNHO, REGISTO (prazo_registo dias, só senhorio e ACTIVO), CAUCAO (falta receber/entregar), SEM_TITULO/TITULO_INACTIVO/ALEM_TITULO, FIM (<= max(60, aviso_dias)), EXPIRADO, ACTUALIZACAO, POR_FACTURAR, POR_PAGAR, MORA.
- IMO-B-082 KPIs do ecrã de contratos (activos, renda mensal por lado normalizada pela periodicidade, margem mensal, cauções, por facturar/por pagar).
- IMO-B-083 KPIs do ecrã de rendas (previstas, aprovadas, facturadas/pagas, a receber/pagar).
- IMO-B-084 Listagem de contratos: filtros texto/estado/lado; coluna «sob NÚMERO» para subarrendamentos; fracções múltiplas.

Modelos / documentos
- IMO-B-085 CRUD de modelos (tipo, nome, texto obrigatórios; activo; rejeita variáveis desconhecidas).
- IMO-B-086 Importar 8 modelos por omissão sem duplicar (tipo+nome).
- IMO-B-087 Aplicar modelo: substituição de variáveis; variável vazia -> `[Rótulo]` + lista de faltas; formatos de data/Kz; renda por extenso.
- IMO-B-088 Contexto invertido por lado (senhorio/inquilino) e morada do contrato/ficha por NIF.
- IMO-B-089 Recibo com `recebido` vindo dos recibos de Vendas e dívida; Interpelação com dívida/dias/juros da conta corrente.
- IMO-B-090 Ver/copiar/imprimir documento (A4 vertical, aviso de faltas escondido em impressão).
- IMO-B-091 `extenso()`: casos 0, 1, 100, 1000, 1000000, 2 500 000, céntimos.
- IMO-B-092 Vagas afectas: propor encargos PARQUE (valor de referência/incluído/ignorar) e perguntar ao gravar.
- IMO-B-093 Permissões: 11 tarefas e 4 ecrãs (secção 9) aplicadas às acções correspondentes.
- IMO-B-094 Multiempresa: todas as consultas filtradas por `imo_company_id`.
