# Anexo C — Compra e venda, reservas pagas (financeiro), planos de manutenção e rentabilidade

> Anexo de `docs/paridade/IMOBILIARIO_INVENTARIO.md`. Notas de leitura integral do legado `v_boss/New/v2/js/modules/imobiliario` (evidência `ficheiro:linha`; abreviaturas definidas no próprio texto). Produzidas em 2026-11-06 por leitura, sem alterar código. Texto original dos leitores, não reescrito; as dúvidas e lacunas declaradas aqui são as oficiais.

# Inventário IMO-C — Gestão Imobiliária (legado v_boss/New/v2) — transacções, reservas financeiras, planos, rentabilidade

Raiz: `C:\xampp\htdocs\ERP_CONSULVOLT_MEU\v_boss\New\v2\js\modules\imobiliario\`. Abreviaturas de ficheiros nas evidências:
TR = imobiliario_transaccoes.js (653 l.), TRU = imobiliario_transaccoes_ui.js (548 l.), RF = imobiliario_reservas_fin.js (576 l.), PL = imobiliario_planos.js (263 l.), PLU = imobiliario_planos_ui.js (281 l.), RE = imobiliario_rentabilidade.js (349 l.), REU = imobiliario_rentabilidade_ui.js (305 l.). Todos lidos inteiros. Fora destes sete li só excertos de: `imobiliario_areas.js` (enums), `imobiliario_dados.js` (enums), `imobiliario_obras.js` (enums), `permissoes.js`, `core/modulos_registo.js`, `db_v2.js` (índices), `imobiliario_contratos.js` (linha 97 `emDocumento`).

Convenções do legado: base local Dexie (`db.<tabela>`), multi-empresa por `imo_company_id` (módulo imobiliário) ou `company_id` (tabelas gerais); `is_master_data=0` em todas as escritas; helper `cent(v)=Math.round(num(v)*100)/100` (TR:30, RF:29, PL:22, RE:31). Moeda Kz, locale `pt-AO` na UI.

---------------------------------------------------------------------------
## 0. Visão geral e ligações entre os ficheiros
- Rotas/ecrãs: `#/imobiliario/negocios` -> `window.renderImoNegocios` (TRU:2-3; app_v2.js:1093 `'imo_negocios'`); `#/imobiliario/rentabilidade` -> `window.renderImoRentabilidade` (REU:3). Planos não têm rota: abre-se em modal por `window.imoPlanos()` a partir do ecrã Apetrechamento e Manutenção (PLU:3). Reservas financeiras (RF) não têm UI neste ficheiro: as funções `window.imoResFacturar/imoResReceber/imoResRecOk/imoResCaucao/imoResCauOk/imoResVistoria/imoResVisOk` (nomes em permissoes.js:58-59) vivem noutro ficheiro de UI de áreas (não lido) e chamam `ImobiliarioReservasFin`.
- Registo de ecrãs (core/modulos_registo.js:19): `imo_negocios:'negocios'`, `imo_rentabilidade:'rentabilidade'` (módulo `imobiliario`, rotaBase `imobiliario`, `pacotes: []`).
- Dependências globais usadas: `window.Imobiliario` (I): `PAPEIS`, `imoveis()`, `unidades()`, `terceiros()`, `normalizarUrl()`, `ESTADOS_UNIDADE`; `window.ImobiliarioContratos` (C): `taxas(ano)`, `contratos()`, `rendas()`, `terminarContrato()`, `diasEntre`, `periodoDe`, `somarMeses`, `limitesPeriodo`, `periodosDoContrato`, `calcular`, `rendaNoPeriodo`, `unidadesDo`, `emDocumento`; `window.ImobiliarioObras` (O): `obras()`, `gravarObra()`, `TIPOS_OBRA`, `ESTADOS_OBRA`, `PRIORIDADES`, `QUEM_PAGA`; `window.ImobiliarioAreas` (A): `reserva`, `reservas`, `espaco`, `mudarEstado`, `REGIMES`, `ESTADOS_RESERVA`; `window.FacturaAGT` (numerar/numerarLocal/libertar/selar); `window.carregarPacote('vendas')`; `window.Notificacoes.mostrar`; `window.Dialogo.pedir/confirmar`; `window.Seletores.varrer`; `window.imoEstilosBase`; `window.getLocalDate`.
- Registo de DB (db_v2.js:1292-1311): `imo_deals: '++id, imo_company_id, numero, tipo, estado, property_id, unit_id, terceiro_id, data_escritura, [imo_company_id+tipo], [imo_company_id+estado]'`; `imo_deal_costs: '++id, imo_company_id, deal_id, tipo, [imo_company_id+deal_id]'`; `imo_work_plans: '++id, imo_company_id, property_id, unit_id, tipo, activo, [imo_company_id+activo]'`.

---------------------------------------------------------------------------
## 1. TRANSACÇÕES (compra e venda de imóveis) — TR + TRU

### 1.1 Filosofia/documentação (TR:1-22)
Negócio = processo `NEGOCIACAO -> PROMESSA (sinal) -> ESCRITURA (transmite) -> REGISTADO -> CONCLUIDO`. Fiscalidade (cabeçalho TR:10-17, "confirmada em 2026-11-06"): SISA extinta; Imposto Predial 2% sobre valor da transmissão; Imposto de Selo 0,3%; escritura Kz 2.000; os três são encargo do ADQUIRENTE (ao contrário da renda, onde o Selo é do senhorio); isenções OGE (habitação até 40M; metade entre 40M e 100M) anuais em `imo_settings.taxas[ano]` e aplicadas por escolha explícita; base = MAIOR entre preço e valor patrimonial (VPT). Mais-valia = preço de venda menos valor contabilístico (custo aquisição - amortizações); encargos capitalizáveis da compra entram no custo.

### 1.2 Tabelas e esquema

**imo_deals** (negócios) — campos escritos em `gravarNegocio` (TR:158-232), `avancarFase` (TR:381-411), `concluirCompra/Venda` (TR:419-526), UI TRU:323:
| campo | tipo | obrig./regra | por omissão / origem |
|---|---|---|---|
| id | int auto | PK `++id` | |
| imo_company_id | int | sempre `cid()` (TR:216) | empresa activa |
| is_master_data | 0 | TR:217 | 0 |
| numero | string `"CP 2026/0001"` / `"VD 2026/0001"` | gerado só na criação (TR:226) | série por tipo+ano, 4 dígitos |
| tipo | enum COMPRA/VENDA | valor inválido -> COMPRA (TR:169) | COMPRA |
| property_id | int/null | obrigatório se não houver unit_id (TR:172); se VENDA obrigatório (TR:187-189) | derivado de unit_id se este existir (TR:179) |
| unit_id | int/null | opcional | null = imóvel inteiro |
| terceiro_id | int | obrigatório (TR:191) | Vendedor (COMPRA) / Comprador (VENDA) |
| preco | decimal 2 | > 0 (TR:195) | |
| vpt | decimal 2 | opcional (0) | valor patrimonial (matriz) |
| sinal | decimal 2 | `<= preco` (TR:198) | 0; "Entregue na promessa" (TRU:412) |
| finalidade | enum HABITACAO/COMERCIO/ESCRITORIO/ARMAZEM/TERRENO/OUTRO | inválido -> HABITACAO (TR:199) | HABITACAO |
| isencao | enum NAO/METADE/TOTAL | inválido -> NAO (TR:200) | NAO |
| data_promessa, data_escritura, data_registo | string ISO `AAAA-MM-DD` ('' se vazio) | ver 1.5 | |
| notario | string | exigido para ESCRITURA (bloqueio) | |
| notas | string | | |
| docs | array de URL normalizadas por `I().normalizarUrl` (TR:214) | a UI NÃO envia `docs` (TRU:478-484) | [] |
| estado | enum fase | `FASES[d.estado] ? d.estado : (antigo ? antigo.estado : 'NEGOCIACAO')` (TR:215) | NEGOCIACAO |
| criado_em | ISO date | só na criação (TR:226) | hoje |
| base | decimal | calculado (calcularImpostos) | max(preco,vpt) |
| base_e_vpt | bool | `cent(vpt) > cent(preco)` | |
| ip_taxa, ip_valor | %, Kz | calculado | |
| selo_pct, selo_valor | %, Kz | calculado | |
| escritura_valor | Kz | calculado | `escritura_fixo` |
| total_impostos | Kz | `ip + selo + escritura` | |
| isencao (devolvido por calcularImpostos como `isencao: isencao||'NAO'`) | | | |
| impostos_fixados_em | ISO date | ao avançar para ESCRITURA (TR:399) | |
| motivo_cancelamento, cancelado_em | string/ISO | ao cancelar (TR:406-407) | |
| contratos_tratados, contratos_tratados_em | 'TRANSMITE'/'MANTEM', ISO | TRU:323 | |
| concluido_em | ISO | TR:451/519 | |
| custo_aquisicao | Kz | só COMPRA, no concluir (TR:452) | |
| asset_id | int/null | activo ligado (COMPRA, TR:452) | |
| mais_valia | Kz/null | só VENDA concluída (TR:520) | null se desconhecida |
| valor_contabilistico | Kz/null | VENDA (TR:521) | |
| sale_id | int/null | factura da venda (TR:522) | |
| asset_disposal_id | int/null | abate (TR:522) | |

**imo_deal_costs** (encargos do negócio) — `gravarCustos` (TR:234-256): `id`, `imo_company_id`, `deal_id`, `tipo` (enum TIPOS_CUSTO, inválido -> OUTRO), `designacao` (string, obrigatória), `valor` (>0, 2 casas), `capitaliza` (bool; se não vier = `TIPOS_CUSTO[tipo].capitaliza`), `data` (por omissão hoje), `pago` (bool), `is_master_data=0`. Gravação = APAGA todos os antigos do negócio e re-insere (TR:239-241); linhas sem designação ou com `valor<=0` são ignoradas silenciosamente (TR:245) e não há erro.

**Outras tabelas tocadas pelas transacções**: `imo_units` (lê `area_util`, `area_bruta`, `property_id`, `designacao`, `codigo`, `estado`; escreve `estado:'VENDIDA'`), `imo_properties` (lê `papel`, `nome`, `codigo`, `building_id`, `valor_patrimonial`; escreve `papel:'PROPRIO'`, `proprietario_id:null`, `valor_aquisicao`, `data_aquisicao`, `valor_patrimonial`, `valor_patrimonial_data`, `estado:'ALIENADO'`, `data_alienacao`), `fixed_assets` (lê `code`, `acquisition_value`, `accumulated_depreciation`; escreve `acquisition_value`, `acquisition_date`, `status:'ABATIDO'`), `asset_depreciations` (índice `asset_id`; lê `value` ou `amount`), `asset_disposals` (ver 1.7), `sales` + `sale_items` (factura da venda), `imo_settings.taxas[ano]` via `ImobiliarioContratos.taxas` (não confirmado como é gravado; só o consumo em TR:79-82).

### 1.3 Enumerações (rótulos exactos)
- **TIPOS** (TR:35-38): `COMPRA` = «Compra (a empresa adquire)», prefixo `CP`, cor #1d4ed8; `VENDA` = «Venda (a empresa aliena)», prefixo `VD`, cor #15803d. Na tabela da lista mostra-se apenas «Compra»/«Venda» (TRU:132).
- **FASES** (TR:42-49), `ordem` / campo de data: `NEGOCIACAO` «Em negociação» 1 / —; `PROMESSA` «Promessa assinada» 2 / `data_promessa`; `ESCRITURA` «Escritura feita» 3 / `data_escritura`; `REGISTADO` «Registado na Conservatória» 4 / `data_registo`; `CONCLUIDO` «Concluído» 5 / —; `CANCELADO` «Cancelado» 0 / —. Cores do selo (TRU:29-33): NEGOCIACAO #475569/#f1f5f9; PROMESSA #b45309/#fffbeb; ESCRITURA #1d4ed8/#eff6ff; REGISTADO #7c3aed/#f5f3ff; CONCLUIDO #15803d/#f0fdf4; CANCELADO #b91c1c/#fef2f2.
- **TIPOS_CUSTO** (TR:51-60), `capitaliza` por omissão: `IMPOSTO` «Imposto Predial da transmissão (2%)» sim; `SELO` «Imposto de Selo (0,3%)» sim; `ESCRITURA` «Escritura e notariado» sim; `REGISTO` «Registo predial» sim; `MEDIACAO` «Comissão de mediação» sim; `AVALIACAO` «Avaliação e vistoria» sim; `ADVOGADO` «Honorários jurídicos» sim; `OUTRO` «Outro encargo» não.
- **TAXAS_TRANSMISSAO por omissão** (TR:63-69): `ip_transmissao:2` (%), `selo_transmissao:0.3` (%), `escritura_fixo:2000` (Kz), `isencao_ate:0`, `isencao_metade_ate:0` (0 = sem escalão). Fusão: `Object.assign({}, TAXAS_TRANSMISSAO, await C().taxas(ano))` (TR:79-82), ou seja o que vem de `imo_settings.taxas[ano]` sobrepõe-se.
- **ISENCOES** (TR:71-75): `NAO` «Sem isenção»; `METADE` «Redução de 50% (OGE)»; `TOTAL` «Isento (OGE)».
- **FINALIDADE** (aceites TR:199; rótulo na UI = primeira letra maiúscula + resto minúsculas, TRU:414): Habitacao, Comercio, Escritorio, Armazem, Terreno, Outro (sem acentos na UI — particularidade; no detalhe mostra-se o código cru `d.finalidade`, TRU:215).
- Estados de fracção usados: `VENDIDA` (valor de `ESTADOS_UNIDADE`, dados.js:145-152: DISPONIVEL «Disponível», RESERVADA «Reservada», ARRENDADA «Arrendada», VENDIDA «Vendida», EM_OBRA «Em obra», INDISPONIVEL «Indisponível»). Estados de imóvel (dados.js:154): `ACTIVO` «Activo», `INACTIVO` «Inactivo», `ALIENADO` «Alienado». PAPEIS (dados.js:36-40): `PROPRIO` «Próprio», `ARRENDADO` «Arrendado a terceiros», `MEDIACAO` «Em mediação». Estados de contrato relevantes: `ACTIVO`, `SUSPENSO` (TR:371).
- Tipos de aviso (TR:607-624): `PROMESSA_ANTIGA`, `POR_REGISTAR`, `POR_CONCLUIR`, `ARRENDADO`.
- Tipos de resultado da mais-valia: `MAIS_VALIA` / `MENOS_VALIA` (TR:349).
- Destinos dos contratos (TRU:295-298): `TRANSMITE` «Transmitem-se ao comprador (a empresa deixa de os gerir)»; `MANTEM` «Mantêm-se nesta empresa (a venda não abrange o arrendamento)».

### 1.4 Fórmulas exactas
- **calcularImpostos(preco, vpt, t, isencao)** (TR:88-102):
  - `base = max(cent(preco), cent(vpt))`
  - `ip = cent(base * num(t.ip_transmissao) / 100)`; se `isencao==='TOTAL'` -> `ip=0`; se `'METADE'` -> `ip = cent(ip/2)` (a isenção só afecta o IP; Selo e escritura nunca são reduzidos).
  - `selo = cent(base * num(t.selo_transmissao) / 100)`
  - `escritura = cent(t.escritura_fixo)`
  - `total_impostos = cent(ip + selo + escritura)`; devolve também `base_e_vpt = cent(vpt) > cent(preco)`, `ip_taxa`, `selo_pct`.
- **sugerirIsencao(valor, finalidade, t)** (TR:105-115): se `finalidade !== 'HABITACAO'` -> `{NAO, 'As isenções do OGE são para habitação.'}`; `v=cent(valor)`; se `isencao_ate>0 && v<=isencao_ate` -> `TOTAL`, texto «Habitação até {isencao_ate pt-AO} Kz está isenta no OGE do ano.»; senão se `isencao_metade_ate>0 && v<=isencao_metade_ate` -> `METADE`, texto «Habitação entre {isencao_ate} e {isencao_metade_ate} Kz tem redução de 50%.»; senão `NAO` com «Acima dos escalões de isenção do ano.» (se `isencao_ate>0`) ou «Não há escalões de isenção configurados para este ano.». A UI passa `r.base` (a base, não o preço) como `valor` (TRU:464). Só sugestão; não aplica sozinho.
- **Ano das taxas**: ao gravar (fase < ESCRITURA): `(data_escritura || data_promessa || hoje).slice(0,4)` (TR:222); ao avançar para ESCRITURA: `data_escritura.slice(0,4)` (TR:397); na simulação UI: `(in-desc || in-dprom || hoje).slice(0,4)` (TRU:461); no formulário carrega `taxas(anoActual)` (TRU:389, valor `t` não usado depois — morto).
- **Recálculo**: impostos recalculam-se em cada `gravarNegocio` enquanto `FASES[d.estado].ordem < ESCRITURA.ordem` (TR:221-224); a partir de ESCRITURA ficam fixos (não são recalculados nem sobrescritos porque `d` vem do formulário sem os campos de imposto e o `update` só mexe nos campos enviados).
- **custoAquisicao(dealId)** (TR:262-274): `impostos = cent(ip_valor + selo_valor + escritura_valor)`; `outros = cent(soma valor dos custos com capitaliza)`; `total = cent(preco + impostos + outros)`; `gasto_periodo = cent(soma valor dos custos sem capitaliza)`. Devolve `{preco, impostos, encargos:outros, total, gasto_periodo}`. NOTA: usa SEMPRE os impostos do negócio (mesmo numa compra em que o utilizador ainda nem avançou de fase, aí são os últimos simulados).
- **quotaFraccao(propertyId, unitId)** (TR:291-306): sem `unitId` -> `{quota:1, criterio:'imóvel inteiro'}`; fracção não encontrada -> `{1,'fracção não encontrada no imóvel'}`; só 1 fracção no imóvel -> `{1,'fracção única do imóvel'}`; senão para `area_util` ('área útil') e depois `area_bruta` ('área bruta'): `total = soma(campo) de TODAS as fracções do imóvel`; se `total>0 && campo da fracção>0` -> `quota = campo/total`; fallback `quota = 1/n` com critério `partes iguais entre {n} fracções`. (Quota sobre o total, não sobre as que faltam vender; justificação TR:283-290.)
- **valorContabilistico(propertyId, unitId)** (TR:308-332): imóvel inexistente -> `{conhecido:false, motivo:'Imóvel não encontrado.'}`; sem `building_id` -> `'O imóvel não está ligado ao imobilizado: sem custo de aquisição registado não há valor contabilístico.'`; activo inexistente -> `'O activo ligado a este imóvel já não existe no imobilizado.'`; `acum = soma(asset_depreciations[asset_id].value ?? .amount)` se houver linhas, senão `fixed_assets.accumulated_depreciation`; `custo = cent(acquisition_value * quota)`; `amort = cent(acum * quota)`; `valor = cent(custo - amort)`; devolve `{conhecido, asset_id, codigo, custo, amortizacoes, valor, quota, criterio_quota, parcial: quota<1, custo_total, amortizacoes_total}`.
- **maisValia(dealId)** (TR:335-354): só VENDA (senão «A mais-valia é da venda, não da compra.»). `encargosVenda = cent(soma de TODOS os custos do negócio)` (inclui não capitalizáveis — não filtra por `capitaliza`); `resultado = cent(preco - vc.valor - encargosVenda)`; `tipo = resultado>=0 ? 'MAIS_VALIA':'MENOS_VALIA'`. Se `!vc.conhecido` devolve `{conhecido:false, preco, encargos, ...vc}` (sem resultado).
- **Numeração** (proximoNumero TR:139-148): `ano = new Date().getFullYear()` (ano corrente, não o da escritura); regex `^{pref} {ano}/(\d+)$` sobre `numero` de TODOS os negócios da empresa; `n = max+1`, `padStart(4,'0')` -> `CP 2026/0001`. Sem reserva/lock -> corrida possível em concorrência (dúvida para o porte: a série deve ser atómica).
- **Resumo (resumo(ano), TR:626-643)**: `a = String(ano || anoCorrente)`; `doAno` = negócios com `data_escritura.slice(0,4)===a` (negócios sem escritura NÃO contam em nenhum KPI de ano); `investido = soma(compras,'custo_aquisicao') || soma(compras,'preco')` (custo_aquisicao só existe após concluir; fallback para preço); `encaixado = soma(vendas,'preco')`; `mais_valias = soma de mais_valia>0`; `menos_valias = soma de mais_valia<0` (negativo); `impostos = soma(doAno,'ip_valor') + soma(doAno,'selo_valor')` (NÃO inclui `escritura_valor`; inclui compras e vendas do ano, mistura); `sinais_em_curso = soma de sinal dos negócios em estado PROMESSA` (de todos os anos); `total` = nº de negócios; `abertos` = estado fora de CONCLUIDO/CANCELADO.
- **Ordenação lista** (TR:129): `data_escritura || data_promessa` descendente (string compare), depois `id` desc. Filtros do serviço: `tipo`, `estado`, `property_id`, `unit_id`, `abertos`.

### 1.5 Validações e mensagens (exactas)
gravarNegocio (TR:158-232): «Base de dados indisponível.»; «Negócio não encontrado.»; «O negócio está {rot em minúsculas}: não se altera.» (para CONCLUIDO/CANCELADO, p.ex. «O negócio está concluído: não se altera.»); «Escolha o imóvel ou a fracção do negócio.»; «Fracção não encontrada.» (inexistente ou de outra empresa); «Imóvel não encontrado.»; «Só se vende o que é da empresa: este imóvel tem o papel «{rot do papel}».» (VENDA com papel ≠ PROPRIO); «Indique o imóvel ou a fracção que está a ser vendida.» (VENDA sem imóvel — redundante com a regra anterior, inalcançável na prática quando `!property_id&&!unit_id` já deu erro; só alcançável se unit_id inválido não... dúvida: código morto parcial); «Indique o vendedor.» / «Indique o comprador.»; «O preço tem de ser maior do que zero.»; «O sinal não pode passar do preço.»; «A escritura não pode ser anterior à promessa.»; «O registo não pode ser anterior à escritura.» (só se ambas as datas existirem; comparação de strings ISO). Não valida: `vpt` negativo, `sinal` negativo, NIF/estado do terceiro, que o terceiro seja cliente/fornecedor, unicidade de negócio ativo para a mesma fracção (um mesmo imóvel pode ter vários negócios abertos), fracção já VENDIDA, nem que `unit_id` pertença ao `property_id` indicado se ambos vierem (a fracção prevalece, TR:179).
bloqueios(dealId, fase) (TR:359-379) — lista de faltas para a fase pedida (a fase "alvo" implica todas as anteriores):
- «Negócio não encontrado.»
- fase PROMESSA e sem `data_promessa`: «Indique a data da promessa.» (a expressão `ordem >= PROMESSA.ordem && ... && fase==='PROMESSA'` só dispara na própria fase PROMESSA; para ESCRITURA/REGISTADO/CONCLUIDO a promessa NÃO é exigida -> «declarando que não houve promessa» do comentário TR:40-41).
- ordem >= ESCRITURA: «Indique a data da escritura.»; «Indique o cartório ou notário.»; «Indique o valor patrimonial: é ele que faz a base do imposto quando é maior do que o preço.» (`vpt<=0`); se VENDA com `unit_id` e há contratos ACTIVO/SUSPENSO na fracção e `!d.contratos_tratados`: «A fracção tem {n} contrato(s) em vigor ({números}). A venda não faz cessar o arrendamento: decida o que lhes acontece antes de escriturar.» (a verificação só olha `unit_id`; venda de imóvel inteiro não verifica contratos).
- ordem >= REGISTADO: «Indique a data do registo na Conservatória.»
avancarFase (TR:381-411): «Negócio não encontrado.»; «Fase desconhecida.»; «O negócio está cancelado.»; recuo: «Não se recua de «{rot actual}» para «{rot pedida}»: o que já foi assinado não se desfaz por mudança de estado.»; faltas juntas por espaço (`faltas.join(' ')`); cancelar a partir de ESCRITURA ou depois: «Um negócio já escriturado não se cancela: a propriedade transmitiu-se. Faça o negócio inverso.»; sem motivo: «Indique o motivo do cancelamento.». Nota: a verificação de bloqueios corre também para `fase==='CANCELADO'` (ordem 0 -> sem faltas) e é permitido saltar fases (de NEGOCIACAO directo a ESCRITURA/REGISTADO pela API; a UI só oferece a fase seguinte imediata).
concluirCompra (TR:419-456): «Negócio não encontrado.»; «Este negócio é uma venda.»; «A compra só se conclui depois da escritura.»; «A compra já está concluída.»; «Ligue o negócio ao imóvel no cadastro antes de o concluir.» (sem `property_id`). Não bloqueia negócio CANCELADO (ordem 0 < 3 já cai em «só se conclui depois da escritura»).
concluirVenda (TR:462-526): «Negócio não encontrado.»; «Este negócio é uma compra.»; «A venda só se conclui depois da escritura.»; «A venda já está concluída.». Facturar: «Não foi possível gerar a factura da venda: {e}»; «Não foi possível gravar a linha da factura: {e}». gravarCustos: «Negócio não encontrado.»; «O negócio está fechado: os encargos não se alteram.». eliminarNegocio: «Negócio não encontrado.»; «Um negócio já escriturado não se apaga: a propriedade transmitiu-se e tem de deixar rasto.» (ordem>=3; CANCELADO ordem 0 pode apagar-se).
Mensagens de UI: sucesso «Passou a «{rot}».»; «Negócio cancelado.»; «Negócio gravado.»; «Negócio criado em negociação.»; «Negócio eliminado.»; «{n} encargo(s) gravado(s).»; «Já não há contratos em vigor nesta fracção.»; «{n} contrato(s) transmitido(s) e encerrado(s) nesta empresa.»; «Os contratos mantêm-se. A venda fica registada como não abrangendo o arrendamento.».

### 1.6 Ecrã «Compra e venda» (lista) — TRU:80-146
- Título «Compra e venda»; subtítulo: «Cada negócio é um processo: negociação, promessa, escritura, registo. É a escritura que transmite a propriedade e fixa os impostos.»
- **KPIs** (TRU:104-110): (1) `{compras}` «Compras escrituradas em {ano} — {investido, 0 casas} Kz» (cor #1d4ed8); (2) `{vendas}` «Vendas em {ano} — {encaixado} Kz» (#15803d); (3) `{mais_valias+menos_valias}` «Resultado das vendas (Kz)» (verde se >=0, senão vermelho); (4) `{impostos}` «Impostos da transmissão (Kz)» (#b45309); (5) `{abertos}` «Processos abertos» + « — {sinais_em_curso} Kz de sinais» se houver (cor #7c3aed se >0, senão #64748b).
- **Avisos**: caixa `imo-aviso` «{n} ponto(s) a tratar:» com no máximo 8 itens de `avisos()` (textos em 1.9).
- **Filtros** (barra): «Procurar» (texto, debounce 250 ms; placeholder «número, imóvel, fracção ou contraparte»; pesquisa por número, nome imóvel, nome fracção, nome terceiro, case-insensitive, `includes`, filtro em cliente); «Tipo» (Os dois / Compra (a empresa adquire) / Venda (a empresa aliena)); «Fase» (Todas + as 6 fases, incluindo «Cancelado»). Botão «Novo negócio» (`fa-plus`, `imoNegEditar(null)`).
- **Colunas**: Número | Tipo | Imóvel / fracção (nome imóvel + «imóvel inteiro» ou nome fracção) | Contraparte | Escritura (data escritura, senão «promessa {data}», senão «—») | Preço (n) | Impostos (n) (`ip+selo+escritura`) | Resultado (n) (`mais_valia`, «—» se null; vermelho se <0) | Fase (selo) | acções. Linha clicável abre o detalhe. Botões por linha: «Editar» (lápis) e «Eliminar» (lixo), `stopPropagation`. Vazio: «Ainda não há negócios registados.» (colspan 10). O botão Editar/Eliminar aparece em TODAS as linhas mesmo concluídas (as funções recusam depois com erro).
- Filtros por tipo/estado actuam no serviço; ordem e filtro de texto no cliente.

### 1.7 Ecrã detalhe do processo — `desenharNegocio` (TRU:157-262)
- Botão «Negócios» (voltar). Cabeçalho: `{numero}` + selo da fase + rótulo do tipo; subtítulo: «{imóvel} — {fracção}» ou «(imóvel inteiro)» · «Vendedor|Comprador: {nome}».
- **Botões** (acções): (a) «{rot da fase seguinte}» (`fa-forward`, `imoNegAvancar(id,fase)`) só se existir `seguinte` e `faltas.length==0`; `seguinte = ['PROMESSA','ESCRITURA','REGISTADO'].find(k => ordem > ordemActual)`; (b) «Concluir» (`fa-flag-checkered`) se `ordemActual >= ESCRITURA.ordem` e estado ∉ {CONCLUIDO, CANCELADO} (pelo que só se conclui depois de escriturado; REGISTADO não é pré-requisito); (c) «Cancelar» (`fa-ban`) se `ordemActual < ESCRITURA.ordem` e estado ≠ CANCELADO (CANCELADO tem ordem 0 -> a condição `ordemActual<3` é verdadeira, protegida pelo 2.º teste); (d) «Editar» se estado ∉ {CONCLUIDO, CANCELADO}.
- **Barra de fases**: 5 blocos NEGOCIACAO, PROMESSA, ESCRITURA, REGISTADO, CONCLUIDO; classe `agora` (estado actual), `feita` (ordem < ordem actual); abaixo do rótulo a data do campo (`data_promessa`, `data_escritura`, `data_registo`).
- **Faltas**: se há `seguinte` e faltas: caixa «Para avançar para «{rot seguinte}» falta:» + lista; se alguma falta contiver «contrato(s) em vigor» (regex `/contrato\(s\) em vigor/`) aparece o botão «Decidir o que fazer aos contratos» (`imoNegTratarContratos`). Se cancelado: caixa vermelha «Cancelado em {cancelado_em}: {motivo}».
- **Cartão «Negócio»**: Preço; Valor patrimonial («por indicar» se 0); Base do imposto (+ «(o VPT é maior)»); Sinal entregue (só se ≠0); Finalidade (código cru); Cartório.
- **Cartão «Impostos da transmissão»** (+ «(fixados na escritura)» quando `impostos_fixados_em`): «Imposto Predial ({ip_taxa 0 casas}%)» + nome da isenção se ≠ NAO; «Imposto de Selo ({selo_pct 1 casa}%)»; «Escritura»; «Total» (`ip+selo+escritura`). Nota fixa: «A SISA foi extinta. Estes três são encargo do adquirente — ou seja, do comprador, não da empresa.» (VENDA) / «— ou seja, da empresa.» (COMPRA).
- **Cartão COMPRA «Custo de aquisição»**: Preço; Impostos e escritura; Outros encargos capitalizáveis; «Vai para o imobilizado» (total); «Gasto do período (não capitaliza)» (se ≠0); nota «É este valor — e não o preço — que fica como custo do bem e que, anos depois, faz a mais-valia da venda.»
- **Cartão VENDA «Resultado da venda»**: se conhecido: Preço de venda; Custo de aquisição; Amortizações acumuladas; Valor contabilístico; Encargos da venda (se ≠0); «Mais-valia»/«Menos-valia» (verde/vermelho); se parcial: «Venda parcial: é a quota-parte de {quota*100, 1 casa}% do activo, repartida por {critério}. O prédio inteiro custou {custo_total} e tem {amortizacoes_total} de amortizações.»; se desconhecido: «{motivo} O preço de venda é {preco}, mas sem custo de aquisição não se apura mais-valia.»
- **Cartão «Encargos do negócio»** + botão «Editar encargos» (se não fechado): tabela Tipo | Designação | Valor | Data | Capitaliza (Sim/Não) | Pago (Sim / «Não» laranja); vazio: «Sem encargos registados além dos impostos.». Cartão «Notas» se existirem.

### 1.8 Formulários e modais
**Modal «Novo negócio» / «Editar {numero}»** (`imoNegEditar`, largura 880, TRU:384-436). Texto: «O número é atribuído na gravação. Os impostos recalculam-se enquanto não houver escritura; depois ficam fixos, porque foram esses que se pagaram.»
| rótulo | id | tipo | obrig. | opções/regras |
|---|---|---|---|---|
| Tipo * | in-tipo | select | sim | TIPOS; `onchange` muda o rótulo do terceiro (Vendedor se COMPRA, Comprador se VENDA) |
| Vendedor/Comprador * | in-terceiro | select `data-seletor="terceiro"` (select2) | sim | todos os terceiros `nome · NIF`; «— escolher —» |
| Imóvel | in-imovel | select | (serviço exige imóvel OU fracção; na VENDA exige) | opções «{codigo} — {nome} ({rot papel})»; ajuda «Numa compra pode ficar em branco até ter o imóvel no cadastro.»; `onchange` filtra fracções |
| Fracção | in-unidade | select | não | «— imóvel inteiro —»; opções «{nome imóvel} — {designação}»; ajuda «Em branco, o negócio é do imóvel todo.»; esconde/desactiva as fracções de outros imóveis |
| Preço * | in-preco | number step 0.01 | sim | `oninput` simula |
| Valor patrimonial | in-vpt | number step 0.01 | não (obrigatório para escriturar) | ajuda «Da matriz. É a base do imposto se for maior do que o preço.» |
| Sinal | in-sinal | number | não | ajuda «Entregue na promessa.» |
| Finalidade | in-fin | select | | Habitacao...Outro; `onchange` simula |
| Data da promessa / Data da escritura / Data do registo | in-dprom / in-desc / in-dreg | date | não | |
| Cartório / notário | in-notario | text | não (obrig. p/ escriturar) | |
| Isenção do Imposto Predial | in-isen | select | | ISENCOES; ajuda «As isenções do OGE são anuais — confirme se vigoram no ano da escritura.» |
| (simulação) | in-simulacao | caixa | | `imo-fiscal`: Base (+ «— o valor patrimonial é maior do que o preço»); Imposto Predial — {taxa}% (+ « · {isenção}»); Imposto de Selo — {pct}%; Escritura; «Total, a cargo do adquirente — a empresa» (COMPRA) / «adquirente — o comprador» (VENDA); frase da sugestão e «Sugestão: {isenção}.» se a sugerida ≠ actual e ≠ NAO; sem preço: «Indique o preço para ver os impostos da transmissão.» |
| Notas | in-notas | textarea | | |
Botões «Cancelar» e «Gravar». Efeito Gravar: `gravarNegocio`; fecha; «Negócio gravado.» (se tinha id) ou «Negócio criado em negociação.»; abre o detalhe do negócio (`st.dealId=id`). Nota: editar um negócio de fase REGISTADO deixa alterar preço/VPT/isenção mas os impostos já fixados não recalculam (as chaves de imposto não vêm do formulário). `unit_id` do formulário vazio -> imóvel inteiro; `property_id` vazio com `unit_id` preenchido -> deriva.
**Modal «Encargos do negócio»** (`imoNegCustos`, 820): texto «Os capitalizáveis entram no custo de aquisição do bem — e por isso mudam a mais-valia de uma venda futura. Os outros são gasto do período. Os impostos da transmissão já estão contados à parte: não os repita aqui.»; linhas dinâmicas (grid 170px/1fr/130px/110px/40px): Tipo (select TIPOS_CUSTO), «descrição», «valor», data (default hoje), botão remover, checkboxes «capitaliza (entra no custo do bem)» (marcada por omissão em linha nova) e «já pago»; botões «Acrescentar», «Cancelar», «Gravar» (`imoNegGravarCustos`).
**Modal «Contratos sobre a fracção vendida»** (`imoNegTratarContratos`, 600): ajuda «A venda não faz cessar o arrendamento: a posição de senhorio passa para o comprador. O que muda é que a empresa deixa de o gerir, e as rendas futuras deixam de ser dela.»; lista «{numero} — até {fim|'sem termo'}, renda {renda}»; select «O que lhes acontece *» (`in-destino`, ajuda «Transmitir termina-os aqui na data da escritura, com o motivo registado — não os apaga.»); «Cancelar»/«Confirmar». Efeito `imoNegConfirmarC`: se TRANSMITE: para cada contrato vivo chama `C().terminarContrato(c.id, {data: data_escritura||hoje, cascata:true, motivo:'Transmissão da propriedade pela venda {numero}: a posição de senhorio passou para o comprador.', caucao_devolvida:0, caucao_retida:0})`; grava no negócio `contratos_tratados` e `contratos_tratados_em` (escrita DIRECTA a `db.imo_deals`, TRU:322-323, fora da camada de serviço). Observação: com `MANTEM` o bloqueio é levantado só por `contratos_tratados` truthy; a caução (0/0) fica por tratar — dúvida de negócio.
**Diálogos**: «Cancelar o negócio» (Dialogo.pedir, mensagem «Porque está a cancelar? Fica registado.», obrigatório) -> `avancarFase(id,'CANCELADO',{motivo})`; «Eliminar negócio» («Eliminar o negócio {numero} e os seus encargos? Não se pode desfazer.», perigo); «Concluir a compra» (confirmação: «O imóvel passa a próprio e fica com o custo de aquisição de {total} (preço {preco} mais {impostos+encargos} de impostos e encargos). Confirma?», botão «Concluir»); modal «Concluir a venda {numero}» (560): texto «Apura-se uma mais-valia|uma menos-valia de {resultado} (preço {preco} menos o valor contabilístico {vc}[ e {encargos} de encargos]). A fracção passa a vendida e o activo é abatido.» ou aviso `{motivo} A venda conclui-se à mesma, mas sem resultado apurado.`; checkbox `ic-facturar` «Emitir factura ao comprador» (+ «A transmissão de imóveis é isenta de IVA; a factura sai sem imposto.»); textarea «Notas de fecho» (`ic-notas`); botões «Cancelar»/«Concluir a venda». Pós-venda: «Venda concluída. {n} fracção(ões) marcada(s) como vendida(s).» + « Resultado: {x}.» + « Já não resta nada por vender: o imóvel foi alienado e o activo abatido.» (se abate e não parcial) + « Restam {n} fracção(ões) por vender.» + « Factura {doc_number} emitida.»; se `abateParcial`: diálogo «Falta o abate parcial no imobilizado» (mensagem sobre a quota vendida e o módulo Activos; botão «Entendido»). Pós-compra: «Compra concluída. Custo de aquisição {total}.» + « O activo no imobilizado foi actualizado.» ou « O imóvel não está ligado ao imobilizado — ligue-o em Imóveis para ter amortizações.».

### 1.9 Regras de fase/efeitos (resumo operacional) e avisos
- **Transições permitidas** (código): qualquer fase -> fase de ordem >= actual (salta fases permitido pela API; UI só oferece a seguinte); nunca recuar; CANCELADO só de fases < ESCRITURA e com motivo; CONCLUIDO só pelas funções `concluirCompra/concluirVenda` (a partir de ESCRITURA ou REGISTADO); CONCLUIDO e CANCELADO são terminais (nenhuma edição, encargos, avanço).
- **ESCRITURA**: congela impostos (`calcularImpostos` com taxas do ano da escritura; `impostos_fixados_em=hoje`).
- **concluirCompra** (TR:419-456): (1) `custo = custoAquisicao`; (2) `imo_properties.update`: `papel:'PROPRIO'`, `proprietario_id:null`, `valor_aquisicao = custo.total`, `data_aquisicao = data_escritura`; (3) se `vpt>0` e a propriedade tem `valor_patrimonial == null` -> `valor_patrimonial=cent(vpt)`, `valor_patrimonial_data=data_escritura` (não sobrescreve VPT existente); (4) se `building_id`: `fixed_assets.update(acquisition_value=total, acquisition_date=data_escritura)` (erro engolido `.catch(()=>null)`); NÃO cria activo se não existir; (5) `imo_deals.update`: `estado:'CONCLUIDO'`, `concluido_em`, `custo_aquisicao`, `asset_id` (null se não ligado), `notas = opcoes.notas || d.notas`. Não gera documento de compra/conta a pagar, não regista pagamento de sinal/preço, não cria lançamento contabilístico, não recalcula amortizações (UI não passa `notas`).
- **concluirVenda** (TR:462-526): (1) `mv = maisValia`; (2) marca `imo_units.estado='VENDIDA'` na fracção do negócio, ou em TODAS as fracções do imóvel se `unit_id` nulo; (3) `restam` = fracções do imóvel com estado ≠ VENDIDA; se `restam==0` -> `imo_properties.update(estado:'ALIENADO', data_alienacao=data_escritura)`; (4) se `mv.conhecido && mv.asset_id`: `asset_disposals.add({company_id, asset_id, date:data_escritura, type:'VENDA', value:cent(preco), net_book_value:mv.valor_contabilistico, result:mv.resultado, notes:'Venda {numero}[ — {pct}% do activo, por {criterio}]', is_master_data:0})` (try/catch, falha só `console.warn`); se `restam==0` -> `fixed_assets.update(status:'ABATIDO')`; senão `abateParcial=true` (valor do activo NÃO é reduzido por decisão de design, TR:492-499); (5) `if opcoes.facturar` -> `facturarVenda`; (6) `imo_deals.update`: `estado:'CONCLUIDO'`, `concluido_em`, `mais_valia` (null se desconhecida), `valor_contabilistico`, `sale_id`, `asset_disposal_id`, `notas`. Retorno `{mais_valia, fraccoes, restam, abate, abateParcial, venda}`. Nota: se `d.property_id` nulo `restam` fica 0. Os passos NÃO são transaccionais (falha a meio deixa estado parcial: fracções VENDIDA sem negócio CONCLUIDO). Também quando o activo não está ligado ao imobilizado não há abate (só aviso de resultado desconhecido).
- **avisos()** (TR:607-624) sobre negócios abertos (não CONCLUIDO/CANCELADO): `PROMESSA_ANTIGA` se estado PROMESSA e `dias(data_promessa -> hoje) > 180`: «{numero}: promessa assinada há {dias} dias e ainda sem escritura.»; `POR_REGISTAR` se estado ESCRITURA: «{numero}: escriturado em {data_escritura} e ainda sem registo na Conservatória — sem registo não é oponível a terceiros.»; `POR_CONCLUIR` se REGISTADO: «{numero}: registado e por concluir — concluir é o que leva o valor ao imobilizado (compra) ou apura a mais-valia (venda).»; `ARRENDADO` se VENDA com `unit_id` e ordem >= PROMESSA e contratos ACTIVO/SUSPENSO: «{numero}: a fracção está arrendada ({contratos}). A venda não faz cessar o arrendamento — a posição de senhorio passa para o comprador.» (estes avisos só são visíveis no ecrã de negócios; se alimentam notificações/centro de alertas noutro sítio: não confirmado).

### 1.10 Factura da venda — `facturarVenda(d, opcoes)` (TR:529-591)
Documento `doc_type:'Factura'`; `dataDoc = data_escritura || hoje`. Carrega pacote `vendas` se `!window.FacturaAGT && window.carregarPacote`. Numeração: `FacturaAGT.numerar('Factura', dataDoc, {origem:'GERAL'})`, fallback `numerarLocal`, fallback manual `FT {anoCorrente}/{n+1}` onde `n = count(sales company_id=cid && doc_type='Factura')`. Cabeçalho `sales`: `company_id`, `customer_id = terceiro_id`, `doc_type`, `doc_number`, `date = dataDoc + 'T' + hh:mm:ss actual`, `due_date = opcoes.vencimento || dataDoc`, `related_doc_id = String(numero do negócio)`, `status:'PENDENTE'`, `notes:'Venda de imóvel — {numero} ({imóvel · fracção})'`, `subtotal=cent(preco)`, `total_tax = iva = cent(preco * taxaIva/100)` com `taxaIva = num(opcoes.iva_taxa)` (a UI não passa -> 0), `total_discount:0`, `total_gross = cent(preco+iva)`, `total_net = cent(preco)`, `total_amount = cent(preco+iva)`, `imo_deal_id`, `is_master_data:0` + `reserva.campos` (campos fiscais AGT: hash, ATCUD, etc., conteúdo não visto). NÃO define `paid_amount`/`pending_amount` (contraste com RF:181). `sale_items.add`: `sale_id`, `product_id:null`, `description:'Venda de imóvel — {ref}'`, `quantity:1`, `billed_qty:1`, `delivered_qty:1`, `unit_price=cent(preco)`, `tax_rate=taxaIva`, `discount_pct:0`, `total`, `line_total`. Falhas: se `sales.add` falha -> `FacturaAGT.libertar(reserva)` e erro; se `sale_items.add` falha -> `sales.delete(saleId)` + libertar + erro. Sucesso -> `FacturaAGT.selar(saleId, reserva)` (erro só `console.error`). Sem motivo de isenção de IVA (código M?? AGT) nem nome do comprador, sem guia/recibo. Retorna `{sale_id, doc_number}`.

### 1.11 Ligações a outros módulos (TR/TRU)
- **Vendas/facturação AGT**: `facturarVenda` (TR:529-591) cria `sales` + `sale_items` com `imo_deal_id`; usa `FacturaAGT` (numeração e selagem). Grava do lado das vendas: `imo_deal_id`; do lado do negócio: `sale_id`.
- **Imobilizado/Activos**: `fixed_assets` (acquisition_value/date/status `ABATIDO`), `asset_disposals` (registo do abate), `asset_depreciations` (leitura). Ligação via `imo_properties.building_id`. Nada de amortizações é escrito aqui.
- **Contratos (arrendamento)**: `C().contratos({unit_id})`, `C().terminarContrato(...)` (TRU:312); estados ACTIVO/SUSPENSO.
- **Terceiros**: `I().terceiros()` (campos `id`, `name`, `nif`).
- **Contabilidade/lançamentos/tesouraria**: NENHUMA escrita contabilística nem de tesouraria nas transacções (nem sinal, nem preço, nem impostos pagos, nem comissões). Os encargos têm a flag `pago` mas sem efeito noutros módulos. Mediação existe só como tipo de custo (`MEDIACAO`) e como papel de imóvel; não há cálculo de comissão de mediação nestes ficheiros.
- **Auditoria**: nenhuma chamada a `audit_logs` em TR/TRU (contraste com RF).
- **Notificações**: apenas `Notificacoes.mostrar` para toasts (`tipo: info/sucesso/erro/aviso`) com fallback `alert`.
- **Projectos**: nenhuma. **Impressão**: nenhuma impressão/exportação no ecrã de negócios.
- **Permissões** (permissoes.js:88-92; ecrã `imo_negocios`): `imo_neg_editar` («Criar e editar negócios e encargos»: `imoNegGravar`, `imoNegGravarCustos`, `imoNegEliminar`); `imo_neg_fase` («Avançar fases (promessa, escritura, registo)»: `imoNegAvancar`, `imoNegCancelar`, `imoNegConfirmarC`; flag sensível `1`); `imo_neg_concluir` («Concluir negócios e emitir a factura da venda»: `imoNegConcluir`, `imoNegConfirmarConcl`; sensível `1`). Nota do catálogo: «Concluir leva o custo ao imobilizado (compra) ou apura a mais-valia e abate o activo (venda): separe quem regista de quem conclui.» As funções guardadas são `window.*` (a permissão é aplicada por nome de função global, não nas funções de serviço — o serviço `ImobiliarioTransaccoes` não verifica permissões). `imoNegAbrir`, `imoNegEditar`, `imoNegCustos`, `imoNegTratarContratos` (abertura de modais) não estão guardadas; `imoNegEditar` não tem chave de permissão (só a gravação).

---------------------------------------------------------------------------
## 2. RESERVAS PAGAS: factura, recebimento, caução e danos — RF (sem UI neste ficheiro)

### 2.1 Princípios (RF:1-19): (1) paga antes de usar (AGUARDA_PAGAMENTO -> CONFIRMADA só com recebimento); (2) caduca sozinha (prazo do espaço); (3) caução nunca é proveito (entra na tesouraria como valor a devolver; só a parte retida por danos com vistoria vira receita e é facturada à parte); (4) cancelar tem escala (N dias antes devolve tudo, depois a % do regulamento).

### 2.2 Dados (tabelas/campos tocados)
- **imo_bookings** (reservas; leitura via `A().reserva`): campos lidos `amenity_id`, `property_id`, `unit_id`, `terceiro_id`, `interna`, `estado`, `valor`, `valor_detalhe`, `motivo`, `data`, `hora_inicio`, `hora_fim`, `sale_id`, `receipt_id`, `prazo_pagamento` (ISO UTC), `caucao`, `caucao_recebida`, `caucao_retida`, `caucao_devolvida`, `vistoria_depois`. Escritos por RF: `estado`, `estado_em`, `estado_por`, `motivo_estado`, `caducada` (1), `prazo_pagamento`, `sale_id`, `doc_number`, `data_factura`, `actualizado_em`, `actualizado_por`, `receipt_id`, `caucao_treasury_id`, `caucao_recebida`, `caucao_recebida_em`, `reembolso_previsto`, `reembolso_retido`, `reembolso_pct`, `vistoria_antes`, `vistoria_depois` (objeto `{em, por, estado, observacoes, url}`), `caucao_retida`, `caucao_devolvida`, `caucao_devolvida_em`, `caucao_motivo`, `caucao_treasury_devolucao_id`, `caucao_sale_id`, `caucao_doc_number`, `danos_work_id`.
- **Espaço (`imo_amenities`, via `A().espaco`)**: lidos `regime`, `nome`, `prazo_pagamento_horas` (por omissão 48), `cancel_dias`, `cancel_pct`.
- **sales** (factura da reserva): campos extra `imo_booking_id`, `imo_amenity_id`, `imo_caucao:1` (factura da retenção). **receipts**: `company_id`, `customer_id`, `receipt_number`, `date`, `total_amount`, `payment_method`, `bank_id:null`, `account_code`, `reference`, `is_posted:false`. **receipt_items**: `receipt_id`, `sale_id`, `amount_paid`. **treasury_documents**: `company_id`, `doc_type` (RECEBIMENTO/PAGAMENTO), `doc_number`, `date`, `third_party_id`, `description`, `total_value`, `status:'PENDENTE'`, `payment_method`, `imo_booking_id`, `is_master_data:0`. **treasury_items**: `doc_id`, `account_code`, `third_party_id`, `value`, `type_dc` ('D' recebimento da caução, 'C' devolução), `is_master_data`. **audit_logs**: `company_id`, `action`, `ref_id`, `detail`, `user`, `date` (ISO), `is_master_data:0` (falha engolida, RF:36-41; utilizador = `currentUser.name || .username || 'sistema'`).

### 2.3 Enumerações
- **ESTADOS_CAUCAO** (RF:43-50): `NAO_APLICA` «Sem caução»; `POR_RECEBER` «Por receber»; `EM_PODER` «Em poder da empresa»; `DEVOLVIDA` «Devolvida»; `RETIDA_PARCIAL` «Devolvida em parte»; `RETIDA_TOTAL` «Retida por danos». `estadoCaucao(r)` (RF:83-90): sem `caucao` -> NAO_APLICA; sem `caucao_recebida` -> POR_RECEBER; `retida>0 && devolvida>0` -> RETIDA_PARCIAL; `retida>0` -> RETIDA_TOTAL; `devolvida>0` -> DEVOLVIDA; senão EM_PODER.
- **REGIMES** (areas.js:59-63): `GRATUITO` «Uso livre»; `RESERVA_GRATUITA` «Reserva obrigatória, sem custo»; `RESERVA_PAGA` «Reserva paga» (`paga:true`). **ESTADOS_RESERVA** (areas.js:74-83; `viva`): PEDIDA «Pedida» (viva), APROVADA «Aprovada» (viva), AGUARDA_PAGAMENTO «A aguardar pagamento» (viva), CONFIRMADA «Confirmada» (viva), REALIZADA «Realizada», FALTOU «Não compareceu», CANCELADA «Cancelada», RECUSADA «Recusada» (as quatro últimas não vivas; só as quatro primeiras ocupam o espaço mais REALIZADA).
- Estados da vistoria: `estado` livre, por omissão `'BOM'` (sem enum aqui); `quando` obrigatoriamente `'ANTES'` ou `'DEPOIS'`. Meio de pagamento por omissão `'Numerário'`. Tipos de obra de danos: `CORRECTIVA`, prioridade `ALTA`, `quem_paga:'SENHORIO'`.

### 2.4 Fórmulas exactas
- **situacao(reservaId)** (RF:55-81): `paga = REGIMES[espaco.regime].paga`; `recebido = soma(receipt_items[sale_id].amount_paid)` (sempre dos recibos); `porPagar = cent(valor - recebido)`; devolve `valor=cent(r.valor)`, `facturado = sale_id ? cent(valor) : 0`, `recebido=cent`, `por_pagar = max(0, porPagar)`, `caucao_devida=cent(caucao)`, `caucao_recebida`, `caucao_retida`, `caucao_devolvida`, `caucao_estado`, `pago = paga ? porPagar <= 0.009 : true`, `prazo`, `caducou = prazo && estado==='AGUARDA_PAGAMENTO' && prazo < agoraISO`. Comparação de prazos por strings ISO UTC.
- **marcarPrazo** (RF:119-132): `horas = max(1, round(num(espaco.prazo_pagamento_horas) || 48))`; `prazo = agora + horas`; limitado ao início do evento (`data + 'T' + hora_inicio||'00:00' + ':00'`, hora local) — `prazo = (limiteEvento inválido || d < limiteEvento) ? d : limiteEvento`; gravado `toISOString()`.
- **previsaoCancelamento(reservaId, data?)** (RF:313-334): `quando = data || hoje`; `faltam = diasEntre(quando, r.data)` (dias de calendário, meio-dia local; pode ser negativo); `limite = max(0, round(espaco.cancel_dias))`; `pct = faltam >= limite ? 100 : min(100, max(0, round(espaco.cancel_pct)))` (cancel_pct = % devolvida dentro do prazo penalizador); `devolve = cent(recebido*pct/100)`; `retem = cent(recebido - devolve)`; `caucao_a_devolver = cent(caucao_recebida - caucao_retida - caucao_devolvida)`; `explicacao`: «Nada foi pago: não há nada a devolver.» / «Faltam {faltam} dia(s) e o espaço devolve tudo até {limite} dia(s) antes.» / «Faltam só {faltam} dia(s): dentro dos {limite} dia(s) do regulamento devolve-se {pct}%.».
- **receber** (RF:222-294): `valor` por omissão = `por_pagar`; `caucao` por omissão = `max(0, cent(caucao - caucao_recebida))`; guarda `valor + caucao > 0`; `valor <= por_pagar + 0.009`; `caucao_recebida + caucao <= caucao + 0.009`. Estado final: se `pago` e (sem caução ou `caucao_recebida >= caucao - 0.009`) e estado era AGUARDA_PAGAMENTO -> `A().mudarEstado(id,'CONFIRMADA')`.
- **contas(filtro)** (RF:518-546): reservas filtradas por `{property_id, de, ate}` excluindo RECUSADA; por reserva `situacao`; `caucao_em_poder = cent(recebida - retida - devolvida)`; agregados: `reservas = n`; `facturado = soma valor das com sale_id`; `recebido = soma recebido`; `por_cobrar = soma por_pagar das com sale_id e estado ≠ CANCELADA`; `caucoes_em_poder`; `caucoes_retidas`; `receita_total = cent(recebido + caucoes_retidas)`. Nota: `recebido` inclui o de reservas CANCELADAS (reembolso não é deduzido; o reembolso nem é movimento aqui) — a receita pode estar sobreavaliada (dúvida).

### 2.5 Validações / mensagens (exactas)
- Comuns: «Reserva não encontrada.»
- facturar (RF:140-213): «Espaço não encontrado.»; ««{nome}» não é de reserva paga: não há o que facturar.»; «Esta reserva já está facturada.»; «Uma reserva «{rot estado}» não se factura.» (não viva); «Aprove a reserva antes de a facturar — uma factura selada não se corrige.» (PEDIDA); «A reserva não tem valor. Reveja o preço do espaço.»; «Uma reserva interna não tem a quem facturar. Marque-a em nome de uma fracção com contrato.» (`interna` ou sem `terceiro_id`); «Não foi possível gerar a factura: {e}»; «Não foi possível gravar a linha da factura: {e}».
- receber: «Uma reserva «{rot}» não recebe pagamentos.»; «Facture a reserva antes de registar o recebimento.»; «Não há nada por receber nesta reserva.»; «Está a receber {valor} e só faltam {por_pagar}.»; «A caução recebida ficaria acima da caução devida.»; «A tesouraria não está disponível para registar a caução.»
- confirmarPorPagamento: «Ainda faltam {por_pagar} Kz. Registe o recebimento primeiro.» (devolve `false` se o estado não é AGUARDA_PAGAMENTO).
- cancelarComReembolso: «Diga o motivo do cancelamento.»; «Não se devolve mais do que o que foi recebido.»
- gravarVistoria: «A vistoria é «ANTES» ou «DEPOIS».»; URL validada por `I().normalizarUrl(url, 'Comprovativo da vistoria')` (erro propaga).
- fecharCaucao: «Esta reserva não tem caução.»; «A caução nunca chegou a ser recebida.»; «A caução desta reserva já foi fechada.»; «O valor retido não pode ser negativo.»; «A caução é de {disponivel} e está a reter {reter}.»; «Para reter caução é preciso dizer o que se encontrou.» (reter>0 sem `motivo`); «Reter caução sem vistoria de saída é ficar com dinheiro de outra pessoa sem prova. Registe a vistoria primeiro.» (reter>0 sem `vistoria_depois`).

### 2.6 Operações e efeitos (por função)
1. **caducarPendentes(propertyId?)** (RF:98-116): para cada reserva AGUARDA_PAGAMENTO com `prazo_pagamento < agora`: se `situacao.pago` -> `confirmarPorPagamento` (confirma em vez de cair); senão `imo_bookings.update(estado:'CANCELADA', estado_em, estado_por:'sistema', motivo_estado:'Prazo de pagamento esgotado ({AAAA-MM-DD HH:mm}). O horário foi libertado.', caducada:1)` e `audit_logs` «Reserva caducada» («{data} {hora_inicio} — sem pagamento dentro do prazo»). NÃO passa por `A().mudarEstado` (não valida transições nem dispara efeitos dessa função) e não anula a factura emitida (sem nota de crédito). «Corre ao abrir o ecrã» (quem o invoca não está nestes ficheiros). Devolve `{caducadas, reservas}`.
2. **facturar** (RF:140-213): igual padrão AGT que TR (numerar -> numerarLocal -> `FT {ano}/{n+1}`), `date = hoje + 'T' + hora`, `due_date = r.data`, `related_doc_id = 'Reserva {id}'`, `notes = '{espaço · data hora_inicio–hora_fim}[ — {fracção}][ ({motivo})]'`, `subtotal = total_gross = total_net = total_amount = cent(valor)`, `total_tax:0`, `paid_amount:0`, `pending_amount = cent(valor)`, `imo_booking_id`, `imo_amenity_id`; linha única `description:'Utilização de {ref}[ — {valor_detalhe}]'`, `tax_rate:0` (sem IVA). Rollbacks iguais a TR. Depois: `imo_bookings.update(sale_id, doc_number, data_factura, actualizado_*)`; se estado APROVADA -> AGUARDA_PAGAMENTO (escrita directa); `marcarPrazo`; auditoria «Facturar reserva» («{dNum} · {ref} · {valor}»). A caução não entra na factura.
3. **receber**: cria `receipts` (número `RE {ano}/{n+1}` com `n = count(receipts da empresa)`; `date = data + 'T' + hora`; `reference:'Reserva {nome} {data}'`; `account_code` = `opcoes.account_code`; `bank_id:null`; `is_posted:false`) + `receipt_items(amount_paid)`; actualiza a venda: `paid_amount = cent(paid_amount + valor)`, `pending_amount = cent(total_amount - jaPago)`; se caução>0 cria `treasury_documents` (`RECEBIMENTO`, `CAU {ano}/{reservaId}`, descrição «Caução da reserva de {nome} em {data} — a devolver», `status:'PENDENTE'`) e `treasury_items` (`type_dc:'D'`, `account_code = opcoes.conta_caucao || conta`); grava na reserva `receipt_id`, `caucao_treasury_id`, `caucao_recebida` acumulada, `caucao_recebida_em`; confirma a reserva se tudo pago; auditoria «Recebimento de reserva» (`utilização {valor} + caução {caucao}`). Retorna `{receipt_id, treasury_id, valor, caucao, estado}`. Opções: `valor`, `caucao`, `payment_method`, `account_code`, `data`, `conta_caucao`.
4. **cancelarComReembolso(reservaId, motivo, opcoes)**: `A().mudarEstado(id,'CANCELADA',{motivo})`; grava `reembolso_previsto = devolve` (ou `opcoes.devolver`), `reembolso_retido = cent(recebido - devolve)`, `reembolso_pct`; auditoria «Cancelar reserva paga» («devolve {x} de {recebido} ({pct}%) — {motivo}»). Não emite nota de crédito nem movimento de tesouraria (comentário: nota de crédito faz-se em Vendas, decisão de quem tem permissão); a caução recebida continua por devolver e é alertada.
5. **gravarVistoria(reservaId, quando, dados)**: `{em: data||hoje, por: operador, estado: estado||'BOM', observacoes, url}` em `vistoria_antes|depois`; auditoria «Vistoria da reserva» («entrada|saída: {estado}[ — {obs}]»).
6. **fecharCaucao(reservaId, opcoes)**: `reter = cent(opcoes.reter)` (0 se omitido), `disponivel = cent(caucao_recebida)`, `devolver = cent(disponivel - reter)`; se `devolver>0 && treasury_documents`: `treasury_documents` `PAGAMENTO` `DEV {ano}/{reservaId}` («Devolução da caução da reserva de {nome} em {data}», meio `opcoes.payment_method||'Numerário'`, `status:'PENDENTE'`) + `treasury_items` (`type_dc:'C'`, `account_code = opcoes.conta_caucao`, `value=devolver`); se `reter>0 && terceiro_id`: `facturarRetencao` (factura já paga: `paid_amount = valor`, `pending_amount = 0`, `imo_caucao:1`, notas «Caução retida — {espaço} em {data}: {motivo}», linha «Caução retida por danos — {espaço}, reserva de {data}», sem IVA; mesma mecânica AGT; sem rollback em caso de erro na linha — diferente de `facturar`); se `opcoes.abrir_obra && window.ImobiliarioObras`: `gravarObra({tipo:'CORRECTIVA', property_id, unit_id:null, descricao:'Danos na área comum «{espaço}» — reserva de {data}: {motivo}', prioridade:'ALTA', quem_paga:'SENHORIO', data_pedido:data, custo_estimado: num(opcoes.custo_estimado) || reter})` (erro engolido com `console.error`); grava na reserva `caucao_retida`, `caucao_devolvida`, `caucao_devolvida_em`, `caucao_motivo`, `caucao_treasury_devolucao_id`, `caucao_sale_id`, `caucao_doc_number`, `danos_work_id`; auditoria «Fechar caução» («devolveu {d}, reteve {r}[ — motivo]»). Casos limite: com `reter>0` mas reserva sem `terceiro_id` (interna) a retenção fica registada sem factura; a factura de retenção não cria recibo, pelo que `situacao.recebido` não a conta (só entra em `receita_total` via `caucao_retida`).
7. **avisos(propertyId?)** (RF:548-566), só se `contas.reservas>0`: (a) `por_cobrar>0.009`: «{x pt-AO 2 casas} Kz facturados de reservas e ainda por receber.»; (b) `caucoes_em_poder>0.009`: «{x} Kz de cauções em poder da empresa — dinheiro de terceiros que está por devolver.»; (c) reservas REALIZADA/CANCELADA/FALTOU com caução em poder>0: «{n} reserva(s) terminadas com a caução por devolver[, {c} delas canceladas sem sequer terem usado o espaço].»; (d) reservas vivas com valor>0 sem `sale_id`: «{n} reserva(s) com valor e ainda sem factura.».

### 2.7 Ligações / permissões
- Vendas (sales/sale_items/FacturaAGT), Tesouraria (`receipts`, `treasury_documents`, `treasury_items`; nada postado: `is_posted:false`, `status:'PENDENTE'`), Manutenção (`ImobiliarioObras.gravarObra`), Auditoria (`audit_logs`), Áreas/Reservas (`ImobiliarioAreas`). Sem lançamentos contabilísticos directos: a conta vem de campos do formulário (`account_code`, `conta_caucao`; contas do plano não identificadas aqui).
- Permissões (permissoes.js:55-59, ecrã `imo_areas`/reservas): `imo_reserva_marcar` (`imoReservaEditar`, `imoReservaGravar`, `imoResApagar`); `imo_reserva_aprovar` (`imoResMudar`; sensível); `imo_reserva_devedor` (`imoReservaAutorizarDevedor`; sensível); `imo_reserva_facturar` («Facturar e receber reservas pagas»: `imoResFacturar`, `imoResReceber`, `imoResRecOk`); `imo_reserva_caucao` («Fechar a caução: devolver ou reter por danos»: `imoResCaucao`, `imoResCauOk`, `imoResVistoria`, `imoResVisOk`; sensível). `cancelarComReembolso`/`previsaoCancelamento`/`caducarPendentes` não têm chave visível nestas linhas (a UI que as chama não foi lida: não confirmado).

---------------------------------------------------------------------------
## 3. PLANOS DE MANUTENÇÃO E VISTORIA — PL + PLU

### 3.1 Tabela imo_work_plans (PL:81-127)
| campo | tipo | regra |
|---|---|---|
| id | auto | |
| imo_company_id | int | `cid()` |
| is_master_data | 0 | |
| tipo | enum PREVENTIVA/VISTORIA | só estes (TIPOS_PLANEAVEIS) |
| nome | string | `txt(nome) || descricao.slice(0,60)` |
| descricao | string | obrigatória |
| property_id | int | obrigatório se não houver unit_id; derivado de unit_id |
| unit_id | int/null | opcional (imóvel inteiro se null) |
| periodicidade | enum | inválida -> ANUAL |
| datas | array ISO ordenado, sem duplicados | `/^\d{4}-\d{2}-\d{2}$/`, 1..60 |
| custo_estimado | decimal/null | `''/null` -> null; senão `cent` |
| prioridade | BAIXA/NORMAL/ALTA/URGENTE | inválida -> NORMAL |
| quem_paga | SENHORIO/INQUILINO/PARTILHADO | inválida -> SENHORIO |
| debitavel | 0/1 | `quem_paga==='SENHORIO' ? 0 : (debitavel?1:0)` |
| responsavel | string | |
| antecedencia | int dias >= 0 | `max(0, round(num||0))` |
| notas | string | |
| activo | 0/1 | `activo===false||0 ? 0 : 1` |
| proxima | ISO | `datas.find(x => x >= hoje) || última data` (recalculado ao gravar) |
| criado_em | ISO | só na criação |
| ultima_geracao | ISO | ao gerar ordens |
`imo_works` recebe `plan_id` e `plano_data` (PL:210). Índice `[imo_company_id+activo]`.

### 3.2 Enumerações
- **TIPOS_PLANEAVEIS**: `PREVENTIVA`, `VISTORIA` (rótulos via `O().TIPOS_OBRA`: «Manutenção preventiva» (`fa-calendar-check`), «Vistoria» (`fa-clipboard-check`); restantes tipos de obra: APETRECHAMENTO «Apetrechamento», CORRECTIVA «Reparação», MELHORIA «Melhoria»).
- **PERIODICIDADES** (PL:30-38, meses): `MENSAL` «Mensal» 1; `BIMESTRAL` «Bimestral» 2; `TRIMESTRAL` «Trimestral» 3; `SEMESTRAL` «Semestral» 6; `ANUAL` «Anual» 12; `BIANUAL` «De dois em dois anos» 24; `MANUAL` «Datas à escolha» 0.
- **PRIORIDADES** (obras.js:52): Baixa/Normal/Alta/Urgente. **QUEM_PAGA** (obras.js:53): `SENHORIO` «Senhorio (a empresa)», `INQUILINO` «Inquilino», `PARTILHADO` «Partilhado». **ESTADOS_OBRA**: ABERTA «Aberta», AGUARDA_MATERIAL «Aguarda material», EM_CURSO «Em curso», CONCLUIDA «Concluída», CANCELADA «Cancelada».
- **Estado da data no calendário** (PL:161 e PLU:43-52): `EM_FALTA` «Em falta» (data passada sem ordem), `POR_GERAR` «Por gerar» (data futura/hoje sem ordem), ou o estado da ordem gerada (`CONCLUIDA` «Concluída», `CANCELADA` «Cancelada», outros pelo rótulo de `ESTADOS_OBRA`).

### 3.3 Regras e fórmulas
- **proporDatas(primeira, periodicidade, quantas)** (PL:45-60): inválida/formato ≠ `AAAA-MM-DD` -> `[]`; `n = max(1, min(60, round(quantas||1)))`; periodicidade desconhecida ou MANUAL -> `[primeira]`; para `i=0..n-1`: alvo = mês `m-1 + i*meses` (UTC, dia 1); `dd = min(dia_original, último dia do mês alvo)` (31 Jan + 1 mês -> 28/29 Fev; sempre a partir do dia ORIGINAL, não encadeado).
- **gravarPlano** validações exactas (PL:81-127): «Base de dados indisponível.»; «Só a manutenção preventiva e a vistoria se planeiam: uma reparação nasce de uma avaria e um apetrechamento de um arrendamento.»; «Descreva o que se vai fazer em cada data.»; «Indique o imóvel ou a fracção do plano.»; «Fracção não encontrada.»; «O plano precisa de pelo menos uma data.»; «São mais de 60 datas. Faça um plano por ano ou por ciclo.» (o limite de 60 após dedupe; inalcançável pela UI além de manual). Não valida que `edit` existe (`update` sobre id inexistente), nem que `id` pertence à empresa.
- **estado(planId)** (PL:152-173): para cada data: ordem gerada = `imo_works` com `plan_id==plano` e `data_prevista==data` (pelo MAPA por data: se houver 2 ordens para a mesma data prevalece a última); `estado = ordem ? ordem.estado : (data < hoje ? 'EM_FALTA':'POR_GERAR')`; `passada = data < hoje`; agregados `geradas`, `por_gerar` (sem ordem e não passada), `em_falta` (sem ordem e passada), `concluidas`, `proxima` = primeira linha não passada (tenha ou não ordem).
- **gerarOrdens(planId, {todas?, ate?})** (PL:180-218): «Plano não encontrado.»; «O plano está inactivo: reactive-o antes de gerar ordens.»; horizonte = `todas ? '9999-12-31' : (ate || hoje + max(num(antecedencia)||30, 1) dias)` (atenção: antecedência 0 -> trata-se como 30 por causa de `||30`); para cada data do plano: salta (conta `saltadas`) se já há ordem; salta se `data > horizonte`; senão `O().gravarObra({tipo, prioridade, property_id, unit_id, descricao:'{descricao} ({nome})', quem_paga, debitavel, custo_estimado, data_pedido: data < hoje ? data : hoje, data_prevista:data, responsavel, pedido_por:'Plano {nome}', notas})` e a seguir `imo_works.update(id, {plan_id, plano_data:data})`; se criou: `imo_work_plans.update({ultima_geracao:hoje, proxima: primeira data >= hoje sem ordem (e não criada agora) || p.proxima})`. Idempotente. Retorno `{criadas:[{id,data}], saltadas:n, horizonte}`. Se `gravarObra` falhar a meio, as anteriores ficam criadas (sem rollback) e o erro propaga.
- **gerarTodos(opcoes)** (PL:221-230): percorre planos activos, apanha erros por plano em `falhas:[{plano, erro}]`; devolve `{criadas, planos, falhas}`.
- **eliminarPlano** (PL:129-140): «Plano não encontrado.»; bloqueia se há ordens geradas por fechar: «O plano já gerou {n} ordem(ns) por fechar ({números}). Feche-as ou cancele-as antes de apagar o plano.» (fechadas = CONCLUIDA/CANCELADA); apaga só o plano; devolve `{ordens_historicas: n}`. (A mensagem de confirmação da UI diz «As ordens já geradas ficam — o que se apaga é o calendário.», imprecisa face ao bloqueio.)
- **avisos()** (PL:232-245), planos activos: `PLANO_EM_FALTA`: «Plano «{nome}»: {n} data(s) já passada(s) sem ordem gerada.»; `PLANO_PROXIMO`: «Plano «{nome}»: a próxima data é {data} e ainda não tem ordem.» (se a próxima ≤ hoje + max(antecedencia||30,1) dias e sem ordem).
- **resumo()** (PL:247-256): `{total, activos, datas (soma datas dos activos), em_falta, por_gerar}`.
- **planos(filtro)** ordenação por `proxima` asc (vazio -> '9999') depois `id`; filtros `property_id`, `unit_id`, `tipo`, `activo`; exclui `is_master_data`.

### 3.4 UI de planos (PLU)
**Modal «Planos de manutenção e vistoria»** (`imoPlanos`, 880; PLU:56-95): título + texto «Uma vistoria trimestral ou uma pintura anual não é uma ordem, é um calendário. O plano guarda as datas e gera as ordens.»; botões «Novo plano», «Fechar». Tabela (filtros de tabela só se >=3 planos): Plano (nome + descrição 60 car.) | Tipo (ícone + rótulo) | Onde (imóvel + fracção/«imóvel inteiro») | Periodicidade | Datas (n + «{concluidas} feita(s)») | Próxima (data ou «terminado») | Em falta (n a vermelho, «—») | Activo («Activo»/«Inactivo») | botões «Calendário» (`fa-list-check`), «Editar», «Eliminar». Vazio: «Ainda não há planos. Crie um para a vistoria periódica ou para a manutenção que se repete.».
**Modal calendário** (`imoPlanoCalendario`, 620): cabeçalho nome, «{descrição} · {periodicidade}»; botões «Gerar as próximas» (`imoPlanoGerar(id,false)`) e «Gerar todas» (`imoPlanoGerar(id,true)`) só se `por_gerar || em_falta`; «Fechar»; aviso se `em_falta`: «{n} data(s) já passaram sem ordem gerada. Gerá-las agora cria as ordens com a data de pedido na própria data — fica o rasto de que foram recuperadas.»; tabela Data (+«já passou» a vermelho) | Estado (selo) | Ordem (número ou «—») | seta -> `imoPlanoIrOrdem(id)` (fecha modais e `imoAbrirObra(id)` ou `#/imobiliario/obras`); nota ««Gerar as próximas» cria só o que está dentro dos {antecedencia||30} dias de antecedência do plano, para a lista de ordens não encher de coisas que só acontecem daqui a muito tempo.». Toast após gerar: «{n} ordem(ns) criada(s)» + « até {horizonte}» (se não «todas») ou «Não havia nada por gerar dentro do horizonte.»; refresca calendário e `renderImoObras`.
**`imoPlanoGerarTodos`** (PLU:146-154): função global sem botão neste ficheiro (usada por outro ecrã — não confirmado); toast «{n} ordem(ns) criada(s) a partir de {p} plano(s).» / «Não havia nada por gerar.»; falhas só em `console.warn`.
**Modal «Novo plano»/«Editar plano»** (`imoPlanoEditar`, 760): texto «Só a manutenção preventiva e a vistoria se planeiam: uma reparação nasce de uma avaria e um apetrechamento de um arrendamento, e nenhum dos dois tem data marcada de antemão.» Campos: Tipo * (`ip-tipo`, PREVENTIVA/VISTORIA); Nome do plano (`ip-nome`, placeholder «Vistoria trimestral, Pintura anual...»); «O que se faz em cada data *» (`ip-desc`, ajuda «É esta a descrição que vai para cada ordem gerada.»); Imóvel * (`ip-imovel`; o asterisco existe mas a validação do serviço é imóvel OU fracção); Fracção (`ip-unidade`, ajuda «Em branco, o plano é do imóvel todo.», filtra pelo imóvel); secção «Datas»: Primeira data (default 1.ª data existente ou hoje), Periodicidade (default ANUAL), Quantas (default nº de datas ou 4; min 1 max 60), botão «Propor datas» (substitui TODAS as linhas de data; toast «{n} data(s) propostas. Corrija-as à vontade antes de gravar.» ou «Indique a primeira data.»); nota «A periodicidade só propõe as datas. Depois ficam escritas uma a uma e corrigem-se à mão — a vistoria de Dezembro que passa para Janeiro não obriga a refazer o plano.»; grelha de datas editáveis (cada uma com remover) + «Acrescentar data»; Prioridade (default NORMAL); «Custo estimado por ordem»; «Antecedência (dias)» (default 30; ajuda «Com quantos dias se gera a ordem antes da data.»); Quem paga (default SENHORIO; `imoPlanoPagaMudou`: se SENHORIO desmarca e desactiva «debitável ao inquilino»); Responsável; checkboxes «debitável ao inquilino», «plano activo» (marcada por omissão); Notas; «Cancelar»/«Gravar». Efeito gravar: `gravarPlano`, «Plano gravado.», reabre a lista. Eliminar: confirmação «Eliminar o plano «{nome}»? As ordens já geradas ficam — o que se apaga é o calendário.», toast «Plano eliminado.[ As {n} ordem(ns) que gerou ficam no histórico.]».
- Ligações: Obras/Manutenção (`ImobiliarioObras.gravarObra/obras`, `imo_works.plan_id/plano_data`, `renderImoObras`, `imoAbrirObra`); Rentabilidade (consome planos sem ordem, ver 4). Sem notificações automáticas, sem auditoria, sem impressão, sem tesouraria/contabilidade. O agendamento "automático" não existe nestes ficheiros: gerar é acto manual (o texto de `permissoes.js:72` fala em «Os planos geram ordens sozinhos a partir de datas fixas»: quem dispara `gerarTodos` automaticamente não está nestes ficheiros — não confirmado).
- Permissões (permissoes.js:70-71, ecrã `imo_obras`): `imo_plano_editar` («Criar e editar planos de manutenção e vistoria»: `imoPlanoGravar`, `imoPlanoEliminar`); `imo_plano_gerar` («Gerar as ordens dos planos»: `imoPlanoGerar`, `imoPlanoGerarTodos`).

---------------------------------------------------------------------------
## 4. RENTABILIDADE DA FRACÇÃO — RE + REU

### 4.1 Fontes de dados (RE:62-90): contratos (`C().contratos`), rendas (`C().rendas`, excluídas `ANULADA`, com `periodo` entre `de` e `ate`), obras (`O().obras`, excluídas `CANCELADA`, com `dataDaObra` dentro de `limDe..limAte`), `purchase_items` com `imo_work_id` (todos, sem filtro de empresa), `maint_requisitions` da empresa com `imo_work_id`, `imo_units`, `imo_properties` da empresa, planos activos (`ImobiliarioPlanos`), taxas do ano de `ate` (`C().taxas(ate.slice(0,4))`).
Período: `ate = opcoes.ate || C().periodoDe(hoje)`; `de = opcoes.de || C().somarMeses(ate, -11)` (12 meses); erro «O início do intervalo é posterior ao fim.» se `de > ate`; formato `AAAA-MM`; `limDe = limitesPeriodo(de,'MENSAL').de`, `limAte = limitesPeriodo(ate,'MENSAL').ate`.
`dataDaObra(o) = data_conclusao || data_prevista || data_pedido || criado_em.slice(0,10)`. `INVESTIMENTO = ['APETRECHAMENTO','MELHORIA']` (as restantes — PREVENTIVA, CORRECTIVA, VISTORIA — contam como `manutencao`).

### 4.2 Fórmulas exactas
- **custoDaObra(obra, itensCompra)**: `comprados = itens com imo_work_id == obra.id`; `real = cent(soma( (received_qty>0 ? received_qty : quantity) * unit_price ))`; se `real>0` -> `{valor:real, origem:'compras', linhas}`; senão `{valor: cent(custo_estimado), origem: custo_estimado ? 'estimativa':'sem valor'}`. (Material do armazém não entra; sinalizado.)
- **Dois conjuntos por fracção**: `prev` (previsto) e `exec` (executado) com parcelas `{renda_recebida, renda_paga, selo, ip_proprio, manutencao, investimento, recuperado}`; nunca se somam entre si.
- **Repartição de contratos multi-fracção** (RE:116-129): `pesosDo(c)`: `unidadesDo(c)`; 0/1 fracção -> peso 1; várias: por `area_util` (se todas > 0) -> peso `area/total`; senão `area_bruta` idem; senão `1/n`. `parte(v) = v * peso`; `repartido=true` se peso<1.
- **lancarRenda(c, valores, periodo, conjunto)** (RE:132-155): `souSenhorio = (c.posicao || 'SENHORIO') === 'SENHORIO'`. Senhorio: `renda_recebida += parte(total_facturar)`; se `valores.selo_encargo==='SENHORIO'`: `selo += parte(selo_valor)`; se `!valores.ip_retido`: `ip_proprio += parte(ip_valor)` (os dois somam também em `impostos` do período); regista nº do contrato em `contratos_recebe`. Inquilino (posição ≠ SENHORIO): `renda_paga += parte(total_facturar)`; `contratos_paga`.
- **Executado de rendas**: rendas com `C().emDocumento(r)` (estado `FACTURADA` ou `PAGA`, contratos.js:97) cujo contrato tem `unit_id`: `lancarRenda(contrato, renda, renda.periodo, 'exec')` usando os campos gravados na renda (`total_facturar`, `selo_encargo`, `selo_valor`, `ip_retido`, `ip_valor`).
- **Previsto de rendas**: para cada contrato com `estado ∉ {RASCUNHO, SUSPENSO}` e `unit_id`, para cada `periodo` de `periodosDoContrato(c, limDe, limAte)` dentro de `[de,ate]`: `v = C().calcular(c, taxasBase, {renda: C().rendaNoPeriodo(c, periodo)})`; `lancarRenda(c, {...v, selo_encargo: c.selo_encargo, ip_retido: !!c.retencao_ip}, periodo, 'prev')`. As taxas são as do ANO DE `ate` para todos os períodos (aproximação).
- **Obras** (RE:183-215): ignora obras sem `unit_id` (obra do imóvel inteiro não se imputa); `fracPaga = quem_paga==='INQUILINO' ? 0 : (quem_paga==='PARTILHADO' ? 0.5 : 1)`; `estimado = cent(custo_estimado*fracPaga)`; `real = cent(custo.valor*fracPaga)`; `concluida = estado==='CONCLUIDA'`; `debitado = cent(w.debitado_valor)`; `alvo = INVESTIMENTO.includes(tipo) ? 'investimento':'manutencao'`; `prev[alvo] += estimado || real`; se concluída: `exec[alvo] += real; exec.recuperado += debitado`; `prev.recuperado += debitado || (w.debitavel ? estimado || real : 0)`; contadores `sem_custo` (origem 'sem valor'), `custo_do_armazem` (tem requisição); no período `dataDaObra.slice(0,7)`: `prev_obras += estimado||real`, se concluída `obras += real`; débito vai para o período de `debitado_em.slice(0,7) || período da obra` (`debitado`).
- **Planos** (RE:217-237): para planos activos com `unit_id` e `valor = cent(custo_estimado * fracPaga) != 0`, para cada data SEM ordem dentro de `[limDe, limAte]`: `prev[alvo] += valor`; `prev_obras` do mês `+= valor`; `planos_sem_ordem++`. (Planos de imóvel inteiro ignorados.)
- **fechar(z)** (RE:240-249): `receita = cent(renda_recebida)`; `custos = cent(renda_paga + selo + ip_proprio + manutencao + investimento - recuperado)`; `resultado = cent(receita - custos)`; `margem_pct = receita>0 ? round((receita-custos)/receita*1000)/10 : null`.
- **execPct(e,p)** = `|p|>0.009 ? round(e/p*1000)/10 : null`.
- **Rendimento (yield) anual** (RE:263-277): só se `imovel.papel==='PROPRIO'`; `base = valor_aquisicao>0 ? valor_aquisicao : valor_patrimonial`; `quota = T().quotaFraccao(p.id, unit_id).quota` (1 se T indisponível); `valorFraccao = cent(base*quota)`; `yield_pct = round( (exec.resultado / max(1,nExecutados) * 12) / valorFraccao * 1000 ) / 10`; `yield_prev_pct` idem com `prev.resultado / max(1, nPeriodos)`. `nPeriodos` = nº de períodos com `prev_recebe>0`; `nExecutados` = nº com `recebe>0`. (Anualiza pela média dos meses COM renda, não pelos 12 meses do intervalo; o resultado inclui obras/investimento do período, ver 4.4.)
- **Por linha** devolvida: `desvio = cent(exec.resultado - prev.resultado)`; `execucao_receita_pct`, `execucao_custos_pct`; `meses`, `meses_executados`; `media_mensal = nExecutados ? cent(exec.resultado/nExecutados) : null`; `media_mensal_prev`; `periodos[]` com `resultado = cent(recebe - paga - impostos - obras + debitado)` e `resultado_prev = cent(prev_recebe - prev_paga - prev_impostos - prev_obras)` (NÃO inclui o recuperado previsto -> a soma mensal prevista pode divergir de `prev.resultado` quando há débitos previstos; dúvida/inconsistência); `valor_fraccao`; `yield_pct`, `yield_prev_pct`; `repartido`; `planos_sem_ordem`; `avisos`; o topo da linha espalha `exec` (receita, custos, resultado = executados).
- **Avisos por linha** (RE:302-307): «{n} ordem(ns) sem custo estimado nem compras: o custo está subavaliado.»; «{n} ordem(ns) levaram material do armazém, cujo custo não entra nesta conta.»; «A renda vem de um contrato que abrange várias fracções e foi repartida por área.»; «{n} data(s) de plano sem ordem gerada: estão no previsto mas ainda não existem como ordem.»
- **Filtro/ordem/totais** (RE:313-339): só linhas com movimento (`prev.receita || prev.custos || exec.receita || exec.custos`) salvo `so_com_movimento===false`; ordenadas por `exec.resultado` asc (piores primeiro); totais por soma das linhas e novo `fechar` (as margens do total recalculam-se); listas: `negativas` (`exec.resultado<0`), `paradas` (`!exec.receita && exec.custos>0`), `por_facturar` (`prev.receita - exec.receita > 0.009`), `derrapagens` (`exec.custos - prev.custos > 0.009`), `investimento_no_periodo = tExec.investimento`, `avisos` (únicos).
- `daFraccao(unitId, opcoes)`: chama `porFraccao({so_com_movimento:false,...})` e filtra a linha.
- NÃO existem nestes ficheiros: yield bruto vs líquido separados, taxa de ocupação/vacância, amortizações, juros/financiamento, IMT. «Líquida» ≈ resultado executado (receita menos todos os custos, selos, IP não retido, manutenção e investimento); «bruta» não está formalizada (a `receita` é a renda facturada ao senhorio incluindo encargos/débitos de obras). A única métrica anualizada é o `yield_pct`.

### 4.3 Ecrã «Rentabilidade da fracção» (REU:55-163)
Título «Rentabilidade da fracção»; subtítulo «O que cada fracção devia render pelo contrato, o que rendeu de facto, e a diferença entre as duas coisas.»
- Estado de UI (`st`): `de`, `ate` (AAAA-MM; por omissão 12 meses até ao mês actual), `incluirInvestimento` (true), `todas` (false).
- **Funções de vista**: `res(z) = incluirInvestimento ? z.resultado : z.resultado + z.investimento`; `cust(z) = incluirInvestimento ? z.custos : z.custos - z.investimento`; `desvio = round((resExec - resPrev)*100)/100`; linhas reordenadas por resultado executado (asc).
- **KPIs**: «Resultado previsto (Kz)»; «Resultado executado (Kz)»; «Desvio face ao previsto (Kz)» (com `+`); «Renda facturada do que era devido» (= `exec.receita/prev.receita*100`, 1 decimal, `pct`); «Fracções a dar prejuízo» (nº de linhas com resultado vista < 0).
- **Avisos** (caixas): «{n} fracção(ões) com renda devida e não facturada:» (até 6: «{imóvel} — {fracção} ({x} Kz)»; «Em Rendas do Período gera-se e factura-se o que falta.»); «{n} fracção(ões) gastaram mais do que o previsto:» (até 6, «(+{x} Kz)»); «{n} fracção(ões) sem renda nenhuma e a custar dinheiro:» (todas, «({custos} Kz)» — nota: usa `x.custos`, `fmt` por 0 casas); «O custo das obras pode estar subavaliado:» + lista de avisos únicos.
- **Filtros**: «De» (input month), «Até» (input month), checkbox «contar o investimento do período» (marcada), checkbox «mostrar as fracções sem movimento»; botão «Imprimir». Quando investimento desmarcado: nota «A mostrar o resultado sem o apetrechamento e as melhorias do período: equipar uma fracção é investimento que se recupera ao longo de vários arrendamentos, e no ano em que se faz afunda a conta de um arrendamento que pode estar perfeitamente são.»
- **Tabela** (cabeçalho agrupado): Imóvel | Fracção (+ «subarrendada» se `contratos_paga`; + «quota do contrato» se `repartido`) | **Renda recebida**: Previsto, Executado, % (verde se >=99,5, senão laranja) | **Custos**: Previsto, Executado, % (verde se <=100,5, senão vermelho) | **Resultado**: Previsto, Executado, Desvio | Rendimento anual (`yield_pct`, e «prev. x%» se difere >0,05) | botão «Ver em detalhe». Rodapé «Total de {de} a {ate}» com somas; vazio «Sem contratos, rendas nem obras no período escolhido.». Nota explicativa final sobre Previsto/Executado/Rendimento anual.
- **Modal detalhe** (`imoRentDetalhe`, 760): «{imóvel} — {fracção}»; sub «De {de} a {ate} · {papel} · {estado da fracção}[ · recebe por {contratos}][ · paga por {contratos}]»; cartão «Previsto contra executado» (linhas: «Renda recebida»; «Renda paga ao senhorio» (custo, invertido); «Imposto de Selo»; «Imposto Predial não retido»; «Manutenção e reparações»; «Apetrechamento e melhorias»; «Debitado ao inquilino» (receita)), rodapé «Resultado», indicadores «Meses no período: {executados} facturado(s) de {meses}», «Média por mês facturado», «Valor da fracção», «Rendimento anual (previsto x%)»; cartão «Mês a mês» (Período («por facturar» quando `prev_recebe && !recebe`), Recebe, Paga, Impostos, Obras, Deb., Previsto, Executado + linha «Soma»); cartão «Obras do período» (Ordem, Tipo (+«investimento»), Data, Estado, Previsto, Executado («por concluir» se não concluída), Origem (compras/estimativa/sem valor), Debitado) com nota «Uma ordem só entra no executado quando é concluída: antes disso é custo previsto. «Compras» é o custo real do material facturado; «estimativa» é o custo previsto na ordem, que pode não ser o que se gastou.»; avisos da linha; «Fechar». O modal ignora o toggle de investimento em `l.exec.resultado` do rodapé mas usa `resultadoDe(l)` (que respeita-o) nos totais da tabela — mistura de critérios.
- Mensagens: «Sem dados para esta fracção no período.» (aviso); erros de `porFraccao` mostram o texto de erro.

### 4.4 Impressão (`imoRentImprimir`, REU:266-304)
Abre janela nova (`window.open('', '_blank')`; se bloqueada «O navegador bloqueou a janela de impressão.»), HTML A4 paisagem (margem 12 mm, Arial 9pt): título «Rentabilidade por fracção — previsto e executado»; sub «De {de} a {ate} · emitida em {hoje}[ · sem o apetrechamento e as melhorias do período]»; colunas Imóvel, Fracção, Renda recebida (Previsto/Executado), Custos (Previsto/Executado), Resultado (Previsto/Executado/Desvio), «Rend. anual»; linha Total; parágrafos com «Renda devida e não facturada:», «Gastaram mais do que o previsto:», «Sem renda e a custar dinheiro:», «Nota:» avisos; nota final sobre Previsto/executado/Selo/IP; `window.print()` automático 350 ms após carregar. Sem Excel/PDF/CSV; sem cabeçalho de empresa/logotipo/NIF.
- Ligações/permissões: leitura de Contratos/Rendas (facturação de rendas), Obras, Compras (`purchase_items.imo_work_id`), Armazém (`maint_requisitions.imo_work_id`), Planos, Transacções (`quotaFraccao`), Activos (indirectamente via valor_aquisicao do imóvel). Ecrã `imo_rentabilidade` SEM chaves de acção (`E('imo_rentabilidade', [], {nota:'Só consulta, mas junta num ecrã as rendas dos dois lados, os impostos e os custos das obras: quem o vê fica a ver o negócio todo.'})`, permissoes.js:93-94). Imprimir não tem chave própria.

---------------------------------------------------------------------------
## 5. Código morto, incoerências e pontos NÃO confirmados
1. TRU:389 `const t = await T().taxas(...)` não é usado (variável morta). TRU:447 `visiveis` contador nunca lido.
2. TR:187-189 ramo «Indique o imóvel ou a fracção que está a ser vendida.» praticamente inalcançável (já coberto por TR:172).
3. TR:364 expressão `ordem >= PROMESSA.ordem && ... && fase==='PROMESSA'` — só exige data da promessa quando se pede PROMESSA; permitido escriturar sem promessa (intencional segundo o comentário).
4. `docs` no negócio (TR:214) sem campo na UI. `iva_taxa` e `vencimento` de `facturarVenda` sem campo na UI (IVA sempre 0). `ISENCOES`, `TAXAS_TRANSMISSAO` exportadas.
5. `imoNegTratarContratos` escreve `imo_deals` directamente (TRU:322-323) em vez de pelo serviço.
6. Fases saltáveis pela API mas não pela UI; CONCLUIDO só por funções de conclusão. `avancarFase` para REGISTADO não exige promessa nem sinal.
7. `resumo.impostos` ignora `escritura_valor` e mistura compras com vendas; `sinais_em_curso` conta todos os anos.
8. Sinal (`sinal`) é apenas um número informativo: sem movimento de tesouraria, sem abatimento ao preço, sem devolução em cancelamento, sem dobro em incumprimento (não confirmado em outro sítio).
9. Comissão de mediação, yield/ocupação formais, amortizações, juros: NÃO existem nestes ficheiros (só tipo de custo `MEDIACAO` e papel `MEDIACAO` do imóvel). Contabilização (lançamentos, contas do plano) não é feita nestes sete ficheiros; só `account_code` passados ao receber/devolver caução (valores vindos da UI não lida). Se existe contabilização automática de `sales`/`treasury_documents`/`asset_disposals`, está nos módulos respectivos (não confirmado).
10. RF: sem UI nestes ficheiros; quem chama `caducarPendentes` (diz «Corre ao abrir o ecrã»), `previsaoCancelamento`, `cancelarComReembolso`, `confirmarPorPagamento`, e a chave de permissão do cancelamento não foram confirmados. O cabeçalho promete «Faltar sem avisar não devolve nada» mas nenhuma função de RF trata o estado `FALTOU` (a escala depende só de `cancel_dias/cancel_pct`); quem marca FALTOU está em `A().mudarEstado` (não lido).
11. RF `caducarPendentes` actualiza a reserva directamente (não usa `mudarEstado`) e não anula a factura nem o recibo parcial.
12. RF `cid = window.activeCompanyId` sem `parseInt` (as comparações podem misturar string/número ao contrário dos outros ficheiros que usam `parseInt`). `receipt_number` `RE {ano}/{n+1}` por contagem da empresa e `FT {ano}/{n+1}` de fallback são não atómicos.
13. RF `facturarRetencao` não faz rollback se falhar `sale_items.add` (ao contrário de `facturar` e `facturarVenda`).
14. PL: `antecedencia = 0` é tratado como 30 em `gerarOrdens`/`avisos`/UI (`||30`) apesar de `gravarPlano` aceitar 0; `estado()` mapeia ordem por data (uma data = uma ordem). UI `imoPlanoGerarTodos` sem botão aqui.
15. RE: `resultado_prev` mensal não inclui `recuperado` previsto, enquanto `prev.resultado` anual o inclui; yield anualiza por média dos meses com renda; taxas do ano de `ate` aplicadas a todos os meses; `purchase_items` não filtrados por empresa (compras de outras empresas com `imo_work_id` igual poderiam colidir — improvável porque ids são locais, mas a validar no porte multi-tenant).
16. Não li: `imobiliario_contratos.js` (cálculo de rendas, `taxas`, `calcular`, `terminarContrato`), `imobiliario_areas.js` (resto), `imobiliario_obras.js` (resto), onde residem `imo_settings.taxas[ano]`, selo/IP de arrendamento e a numeração de obras/AGT. Valores de `FacturaAGT.campos` não vistos.
17. Os rótulos do menu do módulo, textos de ajuda `imo-ajuda`, `imoEstilosBase` e CSS (`imo-kpi`, `imo-aviso`, `imo-selo`, `imo-fiscal`, `imo-tab`) estão fora destes ficheiros; usei só o que aparece inline.

---------------------------------------------------------------------------
## 6. Funcionalidades atómicas testáveis (rascunho)

### 6A. Negócios — dados e numeração
- IMO-C-001 Criar negócio COMPRA com imóvel, vendedor e preço > 0 em estado NEGOCIACAO; recebe número `CP AAAA/0001`.
- IMO-C-002 Criar negócio VENDA numerado `VD AAAA/0001`; série independente da compra; incrementa por tipo e ano corrente; formato 4 dígitos.
- IMO-C-003 Recusar gravar sem imóvel nem fracção: «Escolha o imóvel ou a fracção do negócio.»
- IMO-C-004 Na VENDA, recusar imóvel cujo papel ≠ PROPRIO com a mensagem que cita o papel.
- IMO-C-005 Recusar sem contraparte («Indique o vendedor.» / «Indique o comprador.» conforme o tipo).
- IMO-C-006 Recusar preço <= 0: «O preço tem de ser maior do que zero.»
- IMO-C-007 Recusar sinal maior que o preço: «O sinal não pode passar do preço.»
- IMO-C-008 Recusar escritura anterior à promessa e registo anterior à escritura (mensagens exactas).
- IMO-C-009 Fracção escolhida deriva `property_id` da fracção e rejeita fracção de outra empresa.
- IMO-C-010 Finalidade inválida cai em HABITACAO; isenção inválida cai em NAO; tipo inválido em COMPRA.
- IMO-C-011 Editar negócio em CONCLUIDO/CANCELADO é recusado («O negócio está concluído: não se altera.»).
- IMO-C-012 Editar mantém `numero`, `criado_em` e estado actual.
- IMO-C-013 Eliminar negócio em fase < ESCRITURA apaga também os seus encargos; a partir de ESCRITURA é recusado.
- IMO-C-014 Eliminar negócio CANCELADO é permitido (ordem 0).
- IMO-C-015 Listar negócios filtrados por tipo, fase, imóvel, fracção e «abertos»; isolados por empresa.
- IMO-C-016 Ordenação da lista por data de escritura/promessa desc., depois id desc.
- IMO-C-017 Pesquisa de texto por número, imóvel, fracção e contraparte (case-insensitive).

### 6B. Fiscalidade da transmissão
- IMO-C-018 Base do imposto = max(preço, VPT); sinaliza `base_e_vpt` quando o VPT é maior.
- IMO-C-019 Imposto Predial = base × taxa(2% por omissão)/100, arredondado a 2 casas.
- IMO-C-020 Imposto de Selo = base × 0,3% por omissão.
- IMO-C-021 Escritura fixa 2.000 Kz por omissão; total_impostos = IP + selo + escritura.
- IMO-C-022 Isenção TOTAL zera o IP; isenção METADE reduz o IP a metade; selo e escritura não mudam.
- IMO-C-023 Taxas lidas do ano da escritura (ou promessa, ou hoje) a partir das taxas anuais da empresa; defaults quando em falta.
- IMO-C-024 Sugestão de isenção: só para HABITACAO; TOTAL até `isencao_ate`, METADE até `isencao_metade_ate`, NAO acima; textos exactos; sem escalões configurados diz-se.
- IMO-C-025 Simulação em tempo real no formulário (base, IP, selo, escritura, total, sugestão) e mensagem «Indique o preço para ver os impostos da transmissão.»
- IMO-C-026 Impostos recalculam em cada gravação antes da escritura e ficam fixos a partir da escritura.
- IMO-C-027 Rotulagem do encargo: «a cargo do adquirente — a empresa» (compra) / «— o comprador» (venda).

### 6C. Fases e bloqueios
- IMO-C-028 Avançar NEGOCIACAO->PROMESSA exige data da promessa («Indique a data da promessa.»).
- IMO-C-029 Avançar para ESCRITURA exige data da escritura, cartório/notário e valor patrimonial > 0 (3 mensagens exactas).
- IMO-C-030 Avançar para ESCRITURA numa VENDA de fracção com contratos ACTIVO/SUSPENSO exige tratar os contratos (mensagem exacta com números).
- IMO-C-031 Avançar para REGISTADO exige data do registo.
- IMO-C-032 Não recuar de fase (mensagem exacta com rótulos).
- IMO-C-033 Ao entrar em ESCRITURA grava `impostos_fixados_em` e fixa os impostos pelas taxas do ano da escritura.
- IMO-C-034 Cancelar exige motivo; grava `motivo_cancelamento` e `cancelado_em`.
- IMO-C-035 Não se cancela negócio já escriturado («Faça o negócio inverso»).
- IMO-C-036 Negócio cancelado não avança («O negócio está cancelado.»).
- IMO-C-037 A UI só oferece o botão da fase seguinte quando não há faltas; lista as faltas num aviso.
- IMO-C-038 Botão «Concluir» só visível a partir de ESCRITURA; «Cancelar» só antes de ESCRITURA; «Editar» só em negócios abertos.
- IMO-C-039 Barra de fases com estados feita/agora e datas por fase.

### 6D. Encargos e custo de aquisição
- IMO-C-040 Gravar encargos substitui a lista anterior; ignora linhas sem designação ou valor <= 0.
- IMO-C-041 Tipo de encargo inválido -> OUTRO; `capitaliza` herda do tipo se não indicado; OUTRO não capitaliza.
- IMO-C-042 Encargos fechados (CONCLUIDO/CANCELADO) não se alteram («O negócio está fechado: os encargos não se alteram.»).
- IMO-C-043 Custo de aquisição = preço + (IP + selo + escritura do negócio) + encargos capitalizáveis; gasto do período = não capitalizáveis.
- IMO-C-044 Editor de encargos: adicionar/remover linhas, data por omissão hoje, flags «capitaliza» (marcada por omissão) e «já pago».
- IMO-C-045 Detalhe lista encargos com Tipo, Designação, Valor, Data, Capitaliza, Pago.

### 6E. Contratos na venda
- IMO-C-046 Modal «Decidir o que fazer aos contratos»: lista contratos vivos (número, fim ou «sem termo», renda).
- IMO-C-047 Destino TRANSMITE termina cada contrato à data da escritura (cascata, caução 0/0) com o motivo exacto e regista `contratos_tratados`.
- IMO-C-048 Destino MANTEM regista `contratos_tratados` sem terminar e levanta o bloqueio.
- IMO-C-049 Aviso ARRENDADO em vendas de fracção arrendada a partir da promessa.

### 6F. Conclusão da compra
- IMO-C-050 Concluir compra só após escritura; recusa se já concluída ou se é venda ou sem imóvel no cadastro.
- IMO-C-051 Concluir compra marca o imóvel PROPRIO, limpa o proprietário, grava `valor_aquisicao = custo total` e `data_aquisicao = data_escritura`.
- IMO-C-052 Escreve VPT do imóvel apenas se o cadastro não tinha VPT.
- IMO-C-053 Actualiza `acquisition_value` e `acquisition_date` do activo ligado; indica na mensagem se não está ligado ao imobilizado.
- IMO-C-054 Grava `custo_aquisicao`, `asset_id`, `concluido_em`, notas no negócio e passa a CONCLUIDO.
- IMO-C-055 Confirmação prévia com o custo de aquisição (preço + impostos e encargos).

### 6G. Conclusão da venda e mais-valia
- IMO-C-056 Quota de fracção: imóvel inteiro 1; fracção única 1; por área útil; fallback área bruta; fallback partes iguais; sobre o total das fracções.
- IMO-C-057 Valor contabilístico = custo×quota − amortizações×quota (amortizações de `asset_depreciations`, senão acumulado do activo).
- IMO-C-058 Valor contabilístico desconhecido (imóvel sem activo / activo apagado) devolve motivo exacto e não calcula mais-valia.
- IMO-C-059 Mais-valia = preço − valor contabilístico − todos os encargos do negócio; classifica MAIS_VALIA / MENOS_VALIA.
- IMO-C-060 Mais-valia só para VENDA; compra recusa com mensagem.
- IMO-C-061 Concluir venda marca a(s) fracção(ões) como VENDIDA (todas se imóvel inteiro).
- IMO-C-062 Imóvel passa a ALIENADO com `data_alienacao` quando não resta fracção por vender.
- IMO-C-063 Regista `asset_disposals` (tipo VENDA, valor, valor contabilístico, resultado, notas com %/critério em venda parcial).
- IMO-C-064 Activo passa a ABATIDO só se não restam fracções; em venda parcial não altera o valor e avisa (diálogo «Falta o abate parcial no imobilizado»).
- IMO-C-065 Venda guarda `mais_valia`, `valor_contabilistico`, `sale_id`, `asset_disposal_id`, notas de fecho.
- IMO-C-066 Mensagens de resultado pós-venda (fracções marcadas, resultado, restam, factura).
- IMO-C-067 Concluir venda sem valor contabilístico conclui à mesma «sem resultado apurado».

### 6H. Factura da venda
- IMO-C-068 Opção «Emitir factura ao comprador» cria `Factura` pela numeração AGT (fallback local, fallback `FT ano/n`).
- IMO-C-069 Factura com cliente = terceiro, data = data da escritura, vencimento = data (ou indicado), `related_doc_id` = número do negócio, estado PENDENTE.
- IMO-C-070 Linha única «Venda de imóvel — {imóvel · fracção}», IVA 0% por omissão (cálculo suporta taxa).
- IMO-C-071 Falha ao gravar factura/linha liberta a numeração reservada e desfaz o cabeçalho.
- IMO-C-072 Selagem AGT após criar a factura; falha de selagem não interrompe.
- IMO-C-073 Factura liga-se ao negócio (`imo_deal_id` / `sale_id`).

### 6I. Painel e avisos de negócios
- IMO-C-074 KPI compras escrituradas no ano e valor investido (custo de aquisição ou preço).
- IMO-C-075 KPI vendas do ano e valor encaixado.
- IMO-C-076 KPI resultado das vendas (mais-valias + menos-valias) com cor.
- IMO-C-077 KPI impostos da transmissão (IP + selo dos negócios com escritura no ano).
- IMO-C-078 KPI processos abertos e total de sinais em curso (estado PROMESSA).
- IMO-C-079 Avisos: promessa > 180 dias sem escritura; por registar; por concluir; fracção arrendada; lista limitada a 8 no ecrã.
- IMO-C-080 Detalhe: cartões Negócio, Impostos (fixados), Custo de aquisição (compra) / Resultado da venda (venda), Encargos, Notas.
- IMO-C-081 Formulário: filtragem de fracções pelo imóvel escolhido e rótulo dinâmico Vendedor/Comprador.
- IMO-C-082 Permissões: editar (`imo_neg_editar`), fase (`imo_neg_fase`), concluir (`imo_neg_concluir`) aplicadas às acções correspondentes.

### 6J. Reservas pagas — financeiro
- IMO-C-083 `situacao`: recebido calculado dos itens de recibo; por pagar = valor − recebido (>=0); `pago` verdadeiro quando saldo <= 0,009 (ou espaço não pago).
- IMO-C-084 Estado da caução derivado (6 estados com rótulos exactos).
- IMO-C-085 Facturar reserva só para espaço de reserva paga, reserva viva, aprovada, com valor e com terceiro; recusas com mensagens exactas.
- IMO-C-086 Factura de reserva sem IVA, estado PENDENTE, vencimento no dia da reserva, `related_doc_id` `Reserva {id}`, notas com espaço/data/horas.
- IMO-C-087 Facturar passa APROVADA -> AGUARDA_PAGAMENTO e define o prazo de pagamento.
- IMO-C-088 Prazo = min(agora + horas do espaço (48 por omissão, mín. 1), início do evento).
- IMO-C-089 Falha ao gravar factura/linha reverte e liberta numeração.
- IMO-C-090 Receber utilização: recibo `RE ano/n`, item de recibo, venda actualizada (pago/pendente).
- IMO-C-091 Receber caução: documento de tesouraria RECEBIMENTO `CAU ano/idReserva` pendente + item `D`; caução nunca em `receipts`.
- IMO-C-092 Validações de recebimento (excesso do valor, caução acima da devida, nada a receber, reserva não facturada, reserva não viva).
- IMO-C-093 Reserva AGUARDA_PAGAMENTO passa a CONFIRMADA quando tudo pago e caução completa.
- IMO-C-094 Caducidade: reservas AGUARDA_PAGAMENTO com prazo ultrapassado e sem pagamento passam a CANCELADA (sistema, `caducada=1`, motivo com data/hora) e auditam; as já pagas confirmam-se.
- IMO-C-095 `confirmarPorPagamento` falha com «Ainda faltam X Kz…» se não pago; devolve false se estado diferente.
- IMO-C-096 Previsão de cancelamento: dias que faltam, limite, % devolvida (100% até N dias antes, depois `cancel_pct`), valor devolvido/retido, caução a devolver e explicação textual.
- IMO-C-097 Cancelar com reembolso exige motivo, limita ao recebido, grava reembolso previsto/retido/%, audita; não emite nota de crédito.
- IMO-C-098 Vistoria ANTES/DEPOIS grava objecto `{em, por, estado(BOM), observacoes, url}` e audita; URL validada.
- IMO-C-099 Fechar caução: devolver tudo / reter parte / reter tudo; valida negativos, excesso, motivo obrigatório ao reter, vistoria de saída obrigatória ao reter, caução inexistente/não recebida/já fechada.
- IMO-C-100 Devolução gera documento de tesouraria PAGAMENTO `DEV ano/idReserva` com item `C`.
- IMO-C-101 Parte retida gera factura própria (já paga, `imo_caucao=1`) ao terceiro.
- IMO-C-102 Opção «abrir obra»: cria ordem correctiva ALTA, a cargo do senhorio, custo estimado informado ou valor retido, ligada à reserva (`danos_work_id`).
- IMO-C-103 `contas` do período: reservas (sem recusadas), facturado, recebido, por cobrar (só facturado e não cancelado), cauções em poder, cauções retidas, receita total = recebido + retidas.
- IMO-C-104 Avisos financeiros de reservas (por cobrar, cauções em poder, reservas terminadas com caução, reservas sem factura).
- IMO-C-105 Auditoria de: caducar, facturar, receber, cancelar, vistoria, fechar caução.

### 6K. Planos de manutenção e vistoria
- IMO-C-106 Só PREVENTIVA e VISTORIA são planeáveis (mensagem exacta).
- IMO-C-107 Plano exige descrição, imóvel ou fracção e pelo menos uma data; máx. 60; datas deduplicadas e ordenadas; formato AAAA-MM-DD.
- IMO-C-108 Nome por omissão = 60 primeiros caracteres da descrição; periodicidade inválida -> ANUAL; prioridade -> NORMAL; quem_paga -> SENHORIO.
- IMO-C-109 `debitavel` forçado a 0 quando paga o senhorio; UI desactiva o checkbox.
- IMO-C-110 `proxima` = primeira data >= hoje (senão última).
- IMO-C-111 Propor datas: periodicidades mensal/bimestral/trimestral/semestral/anual/bianual; dia mantido ou fim do mês; MANUAL devolve só a primeira; quantas 1..60; primeira inválida devolve vazio.
- IMO-C-112 UI «Propor datas» substitui as datas, datas editáveis uma a uma, acrescentar/remover.
- IMO-C-113 Estado do plano: cada data com ordem gerada (estado da ordem), «Em falta» (passada sem ordem) ou «Por gerar»; contadores gerar/falta/concluídas; próxima.
- IMO-C-114 Gerar as próximas: só datas dentro de hoje + antecedência (30 por omissão); idempotente (não duplica).
- IMO-C-115 Gerar todas: cria todo o calendário em falta.
- IMO-C-116 Ordem gerada: descrição `{descricao} ({nome})`, pedido por `Plano {nome}`, data pedido = hoje (ou a própria data se já passou), data prevista, custo/prioridade/quem_paga/debitável/responsável/notas do plano; liga `plan_id` e `plano_data`.
- IMO-C-117 Plano inactivo não gera («reactive-o antes de gerar ordens»).
- IMO-C-118 Gerar em lote para todos os planos activos com relatório de falhas.
- IMO-C-119 Eliminar plano bloqueado se há ordens geradas por fechar; senão apaga e informa quantas ficam no histórico.
- IMO-C-120 Lista de planos: colunas, datas/feitas, próxima ou «terminado», em falta, activo; vazio com mensagem.
- IMO-C-121 Calendário do plano com ligação à ordem e aviso de datas passadas.
- IMO-C-122 Avisos: PLANO_EM_FALTA e PLANO_PROXIMO; resumo (total, activos, datas, em falta, por gerar).
- IMO-C-123 Permissões `imo_plano_editar` e `imo_plano_gerar`.

### 6L. Rentabilidade
- IMO-C-124 Intervalo por omissão: 12 meses até ao mês actual; erro se início > fim.
- IMO-C-125 Executado de renda só de rendas FACTURADA/PAGA; previsto a partir dos contratos (exceto RASCUNHO e SUSPENSO), mês a mês, incluindo meses por facturar.
- IMO-C-126 Contratos de várias fracções repartem renda por área útil -> bruta -> partes iguais; linha marcada «quota do contrato» e aviso.
- IMO-C-127 Posição do contrato: SENHORIO soma `renda_recebida`; outra posição soma `renda_paga` (fracção «subarrendada»).
- IMO-C-128 Selo conta como custo quando `selo_encargo = SENHORIO`; IP só quando o inquilino não retém.
- IMO-C-129 Obras só da fracção; quem paga INQUILINO 0, PARTILHADO 50%, SENHORIO 100%; investimento (APETRECHAMENTO, MELHORIA) separado de manutenção.
- IMO-C-130 Custo da obra: real das compras (recebido ou pedido × preço), senão estimado, senão sem valor (+ aviso).
- IMO-C-131 Previsto de obras = estimado (ou real); executado só obras CONCLUIDAS; recuperado (débito ao inquilino) previsto e executado.
- IMO-C-132 Datas de planos sem ordem entram no previsto de manutenção/investimento da fracção.
- IMO-C-133 Custos = renda paga + selo + IP próprio + manutenção + investimento − recuperado; resultado = receita − custos; margem %.
- IMO-C-134 Desvio = executado − previsto; taxas de execução de receita e de custos.
- IMO-C-135 Yield anual só em imóveis PROPRIO: base = valor de aquisição (senão VPT) × quota; (resultado médio por mês facturado × 12)/valor; versão prevista.
- IMO-C-136 Listas de alerta: negativas, paradas, por facturar, derrapagens; avisos de custo subavaliado/armazém/repartição/planos.
- IMO-C-137 Ordenação por pior resultado executado; filtro «mostrar as fracções sem movimento».
- IMO-C-138 Toggle «contar o investimento do período» recalcula KPIs, linhas, custos e totais.
- IMO-C-139 KPIs: resultado previsto, executado, desvio, % renda facturada do devido, fracções a dar prejuízo.
- IMO-C-140 Tabela agrupada com % coloridas (>=99,5 renda; <=100,5 custos), rodapé de totais, vazio com mensagem.
- IMO-C-141 Detalhe da fracção: previsto×executado por parcela, indicadores (meses, média, valor, yield), mês a mês com soma, obras do período com origem do custo.
- IMO-C-142 Impressão A4 paisagem com totais, avisos e notas; indica «sem o apetrechamento e as melhorias» quando aplicável.
- IMO-C-143 Ecrã de leitura sem chaves de acção (visibilidade pelo ecrã `imo_rentabilidade`).
