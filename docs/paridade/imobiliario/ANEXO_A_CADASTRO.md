# Anexo A — Cadastro: imóveis, fracções, parqueamento, áreas comuns e reservas, ficha da fracção

> Anexo de `docs/paridade/IMOBILIARIO_INVENTARIO.md`. Notas de leitura integral do legado `v_boss/New/v2/js/modules/imobiliario` (evidência `ficheiro:linha`; abreviaturas definidas no próprio texto). Produzidas em 2026-11-06 por leitura, sem alterar código. Texto original dos leitores, não reescrito; as dúvidas e lacunas declaradas aqui são as oficiais.

# Inventário IMO-A — Gestão Imobiliária (legado v_boss/New/v2/js/modules/imobiliario)
Ficheiros lidos INTEIROS: imobiliario_dados.js (640 l.), imobiliario_parque.js (412), imobiliario_parque_ui.js (486), imobiliario_areas.js (594), imobiliario_areas_ui.js (799), imobiliario_ficha_ui.js (579).
Convenção: `D:`=imobiliario_dados.js, `P:`=imobiliario_parque.js, `PU:`=imobiliario_parque_ui.js, `A:`=imobiliario_areas.js, `AU:`=imobiliario_areas_ui.js, `F:`=imobiliario_ficha_ui.js. Namespaces globais: `window.Imobiliario` (D:631), `window.ImobiliarioParque` (P:404), `window.ImobiliarioContratos` (outro ficheiro, não lido).

=====================================================================
# PARTE 1 — imobiliario_dados.js (D)
=====================================================================

## 1.1 Helpers comuns (D:22-32)
- BD() = `db` ou `window.db` (D:22). cid() = `window.activeCompanyId` (D:23). num(v)=Number finito senão 0 (D:24). txt(v)=String trim (D:25). r2(v)=`Math.round((num(v)+Number.EPSILON)*100)/100` (D:26). agora()=ISO (D:27). hojeISO()= `window.getLocalDate()` senão `toISOString().slice(0,10)` (D:30; comentário: Angola UTC+1). operador()= `currentUser.name||username||'sistema'` (D:31). mesmo(a,b)=String(a)===String(b) (D:32).

## 1.2 Coleções IndexedDB (Dexie) usadas em D
Todas filtradas por `!x.is_master_data && mesmo(x.imo_company_id, c)` (D:166-170): função `porEmpresa(tabela)`; devolve [] se a tabela não existir.
### imo_properties (imóveis) — campos gravados por gravarImovel (D:243-266, 268)
Chave: `id` (auto, Dexie add devolve id; D:268). Campos:
- imo_company_id (=activeCompanyId, só na criação, D:268); is_master_data=0 (D:268); criado_em/criado_por (só criação, D:268); actualizado_em/actualizado_por (sempre, D:265).
- codigo (texto; `txt(dados.codigo)` ou sugerido `PR0001`, D:229; único por empresa, case-insensitive, D:230).
- nome (obrigatório, D:217-218).
- tipo: enum TIPOS_IMOVEL, omissão 'PREDIO' (D:236).
- papel: enum PAPEIS, omissão 'PROPRIO' (D:219).
- n_pisos: inteiro ≥0, só se tipo.pisos senão 0 (D:245); piso_inicial: inteiro (pode ser negativo), só se tipo.pisos senão 0 (D:246); n_habitacoes: inteiro ≥0, só se tipo.habitacoes senão 0 (D:247).
- proprietario_id: parseInt ou null (D:248) — ref. a third_parties.id; obrigatório se papel ARRENDADO ou MEDIACAO (D:220).
- building_id: parseInt só se papel==='PROPRIO' e fornecido, senão null (D:224) — ref. fixed_assets.id (asset_type 'EDIFICIO').
- morada, municipio, provincia, coordenadas (texto, D:250-251).
- matriz_predial (artigo matricial, único por empresa se preenchido, D:231-234), registo_predial, licenca_utilizacao (texto, D:252).
- area_terreno, area_bruta, area_util: r2 (D:253). area_util<=area_bruta (D:226).
- valor_patrimonial: null se ''/null, senão r2 (D:255) — «nunca é calculado aqui» (D:254); valor_patrimonial_data: texto (D:256).
- isencao_detencao: enum ['NAO','METADE','TOTAL'], omissão 'NAO' (D:259). (Sem rótulos em D; ver F/AU.)
- valor_aquisicao: null ou r2 (D:260); data_aquisicao texto (D:261).
- estado: enum ESTADOS_IMOVEL, omissão 'ACTIVO' (D:262).
- documentos: array normalizado por limparDocumentos (D:263); notas (D:264).
### imo_units (fracções, incluindo vagas e arrecadações) — gravarUnidade (D:314-344) e criadores em massa
Chave id auto. Campos: imo_company_id, is_master_data=0, criado_em, criado_por, estado_desde (na criação = hojeISO(), D:359), actualizado_em/por.
- property_id: parseInt (D:315) FK imo_properties.
- codigo: `txt(dados.codigo)` ou `${p.codigo}-${String(irmas.length+1).padStart(2,'0')}` (D:294); único dentro do imóvel (case-insens., D:295). NOTA: usa irmas.length+1 — pode colidir após eliminações (a colisão é recusada pela validação, não se auto-incrementa).
- designacao (obrigatória; única no imóvel, case-insens., D:288-289, 296).
- natureza: enum NATUREZAS, omissão 'HABITACIONAL' (D:299).
- park_afectacao: se vaga: enum PARK_AFECTACOES omissão 'AUTONOMA'; senão null (D:301,317).
- park_unit_id: só se vaga PERMANENTE (D:302-304,318); referência a fracção-mãe do mesmo imóvel.
- park_tipo: se vaga enum PARK_TIPOS omissão 'DESCOBERTO' senão null (D:319). park_porte: se vaga enum PARK_PORTES omissão 'LIGEIRO' senão null (D:320).
- park_altura_max, park_comprimento, park_largura: se vaga e >0 → r2 senão null (D:322-324).
- park_tomada: boolean (vaga ? !!dados : false, D:325). park_distico: texto (vaga ? txt : '', D:326).
- park_incluido_na_renda: NÃO gravado por gravarUnidade; gravado por P:afectar (P:226), P:libertar (P:247), P:mudarAfectacao (P:265) e directamente por PU:278. Valores true/false/null.
- room_id: parseInt ou null (D:327) FK asset_rooms.id.
- bloco, andar, numero (texto, D:328); piso: null se ''/null senão Math.round(num) (D:329).
- tipologia: enum TIPOLOGIAS omissão 'OUTRA' (D:330). finalidade: enum FINALIDADES omissão 'HABITACAO' (D:331).
- area_bruta, area_util: r2 (D:332). quartos, casas_banho: `num(x)||null` (D:333). mobilada: enum MOBILIA omissão 'NAO' (D:334).
- estado: enum ESTADOS_UNIDADE omissão 'DISPONIVEL' (D:335). estado_motivo: gravado só por mudarEstadoUnidade (D:384).
- matriz_predial (texto, «a fracção autónoma tem artigo matricial próprio; sem ele vale o do imóvel», D:336-337); valor_patrimonial, renda_referencia, venda_referencia: null ou r2 (D:338-340).
- documentos (array), notas, ordem: `num(dados.ordem)||irmas.length+1` (D:342).
- Índices/tabelas externas lidas: `fixed_assets` (índice company_id), `asset_rooms` (índice building_id), `asset_floors` (índice building_id), `third_parties` (índice company_id), `imo_leases` (P). Não há declaração de esquema Dexie em D (esquema/stores definidos noutro ficheiro, NÃO LIDO — dúvida).
### Documentos embebidos (array em imo_properties.documentos e imo_units.documentos) — limparDocumentos (D:205-211)
Cada: {tipo (chave TIPOS_DOC, senão 'OUTRO'), nome (txt), url (normalizarUrl), data (txt)}; entradas sem url são descartadas (`.filter(d=>d.url)`). Não há upload de ficheiros: só URL (D:187-188).
### Outras
- Auditoria via `window.logMovement('Gestão Imobiliária', accao, id, detalhe)` (D:626-629) — opcional, erro engolido.

## 1.3 Enumerações (D) — rótulos exactos
**PAPEIS** (D:36-40) [chave: rot | ajuda | exigeProprietario | icone]
- PROPRIO: «Próprio» | «A empresa é dona. Pode ligar-se ao activo imobilizado e arrendar ou vender.» | false | fa-key
- ARRENDADO: «Arrendado a terceiros» | «A empresa é inquilina. É daqui que nasce o subarrendamento.» | true | fa-file-signature
- MEDIACAO: «Em mediação» | «Imóvel de terceiros que a empresa intermedeia por comissão.» | true | fa-handshake
**TIPOS_IMOVEL** (D:44-54) [chave: rot | pisos | habitacoes]
- PREDIO «Prédio / edifício» pisos=true hab=false; CONDOMINIO «Condomínio» true/true; MORADIA «Moradia» true/false; APARTAMENTO «Apartamento isolado» false/false; LOJA «Loja» f/f; ESCRITORIO «Escritório» f/f; ARMAZEM «Armazém / galpão» f/f; TERRENO «Terreno» f/f; OUTRO «Outro» f/f.
**ESQUEMAS** (numeração de fracções geradas, D:69-74)
- PORTA «Número de porta (101, 102, 201…)» ajuda «Piso × 100 + ordem. É o mais comum nos prédios.»
- LETRA «Letra por piso (A, B, C…)» ««1.º andar · A», «1.º andar · B».»
- NUMERO «Número por piso (1, 2, 3…)» ««1.º andar · 1», «1.º andar · 2».»
- ESQ_DTO «Esquerdo / Direito» ««1.º Esq.», «1.º Dto.» — só faz sentido com 2 ou 4 por piso.»
**LETRAS** = 'A..Z' (D:76).
**TIPOLOGIAS** (D:94-98): T0 «T0 (estúdio)», T1 «T1», T2 «T2», T3 «T3», T4 «T4», T5 «T5 ou maior», LOJA «Loja», ESCRITORIO «Escritório», ARMAZEM «Armazém», GARAGEM «Garagem / estacionamento», ARRECADACAO «Arrecadação», TERRENO «Terreno», OUTRA «Outra».
**FINALIDADES** (D:100-103): HABITACAO «Habitação», COMERCIO «Comércio», SERVICOS «Serviços / escritórios», INDUSTRIA «Indústria», ARMAZENAGEM «Armazenagem», ESTACIONAMENTO «Estacionamento», OUTRA «Outra».
**NATUREZAS** (D:109-113) [rot | ajuda | icone | conta]
- HABITACIONAL «Fracção» «Habitação, loja, escritório, armazém — o que se arrenda ou vende como espaço principal.» fa-door-open conta=true
- ESTACIONAMENTO «Vaga de estacionamento» «Lugar de parqueamento. Não entra na área habitacional nem na taxa de ocupação.» fa-square-parking conta=false
- ARRECADACAO «Arrecadação» «Arrumos ou cave de arrumação, normalmente acessório de uma fracção.» fa-box-archive conta=false
**PARK_TIPOS** (D:117-122): DESCOBERTO «Descoberto» («Ao ar livre, sem cobertura.»); COBERTO «Coberto» («Com cobertura mas sem fecho.»); SUBTERRANEO «Subterrâneo» («Em cave. É aqui que a altura máxima importa.»); BOX «Box fechada» («Compartimento próprio com porta. Vale mais e pode guardar outras coisas.»).
**PARK_PORTES** (D:126-131) [rot, ordem]: MOTA «Mota» 1; LIGEIRO «Ligeiro» 2; SUV «SUV / 4×4» 3; PESADO «Pesado / carrinha» 4.
**PARK_AFECTACOES** (D:135-140) [rot | ajuda | pedeUnidade | arrendavel]
- PERMANENTE «Afecta a uma fracção» «Acessório da fracção: arrendar a fracção leva a vaga junto.» pedeUnidade=true arrendavel=true
- AUTONOMA «Autónoma» «Arrenda-se sozinha, mesmo a quem não mora no prédio.» false/true
- VISITA «Visitas» «Uso comum e rotativo. Nunca entra em contratos nem conta para a ocupação.» false/false
- CARGA_DESCARGA «Cargas e descargas» «Lugar de serviço. Nunca entra em contratos.» false/false
**ESTADOS_UNIDADE** (D:145-152) [rot | cor | fundo | ocupa]
- DISPONIVEL «Disponível» #15803d #dcfce7 ocupa=false; RESERVADA «Reservada» #a16207 #fef9c3 false; ARRENDADA «Arrendada» #1d4ed8 #dbeafe true; VENDIDA «Vendida» #475569 #f1f5f9 true; EM_OBRA «Em obra» #b45309 #ffedd5 false; INDISPONIVEL «Indisponível» #b91c1c #fee2e2 false.
(comentário D:143-144: ARRENDADA e VENDIDA «passarão a ser escritos pelos contratos»; aqui ainda se escolhem à mão.)
**ESTADOS_IMOVEL** (D:154): ACTIVO «Activo», INACTIVO «Inactivo», ALIENADO «Alienado».
**MOBILIA** (D:156): NAO «Não mobilada», PARCIAL «Parcialmente mobilada», SIM «Mobilada».
**PROVINCIAS** (sugestão datalist, não fechada, D:160-162): Bengo, Benguela, Bié, Cabinda, Cuando, Cubango, Cuanza Norte, Cuanza Sul, Cunene, Huambo, Huíla, Icolo e Bengo, Luanda, Lunda Norte, Lunda Sul, Malanje, Moxico, Moxico Leste, Namibe, Uíge, Zaire (21 valores).
**TIPOS_DOC** (D:199-203): MATRIZ «Caderneta predial / matriz», REGISTO «Certidão do registo predial», LICENCA «Licença de utilização», PLANTA «Planta», ESCRITURA «Escritura», CONTRATO «Contrato», AVALIACAO «Avaliação», FOTO «Fotografias», OUTRO «Outro».
**isencao_detencao** valores NAO/METADE/TOTAL (D:259) — rótulos NÃO definidos em D.

## 1.4 Fórmulas / regras (D)
- nomePiso(n): p=Math.round(num(n)); p<-1 → `${abs(p)}.ª cave`; p===-1 → 'Cave'; p===0 → 'R/C'; senão `${p}.º andar` (D:60-66).
- designacaoGerada(esquema, piso, ordem, bloco) (D:78-91): pref = bloco ? `${bloco} · ` : ''.
  - PORTA: piso<0 → `${pref}C${abs(piso)}${ordem 2 dígitos}`; piso 0 → `${pref}${ordem 2 dígitos}` (01,02); senão `${pref}${piso*100+ordem}`.
  - LETRA: `${pref}${nomePiso(piso)} · ${LETRAS[(ordem-1)%26]}`.
  - ESQ_DTO: rot=['Esq.','Dto.','Frt.','Tras.'][(ordem-1)%4]; `${pref}${piso===0?'R/C':`${piso}.º`} ${rot}` (nota: piso negativo dá «-1.º Esq.», não tratado).
  - NUMERO (omissão): `${pref}${nomePiso(piso)} · ${ordem}`.
- porteCabe(vaga, veiculo) = ordem(veiculo, omissão 0) <= ordem(vaga, omissão 9) (D:132). Vaga desconhecida aceita tudo; veículo desconhecido cabe sempre.
- ehVaga(u)= natureza==='ESTACIONAMENTO' (D:114); ehAcessorio(u)= u && natureza && natureza!=='HABITACIONAL' (D:115) (natureza em falta = principal); vagaArrendavel(u)= ehVaga && PARK_AFECTACOES[u.park_afectacao].arrendavel (D:141) — vaga com park_afectacao null/indefinida NÃO é arrendável.
- Código de imóvel: proximoCodigo(usados,'PR') = 'PR' + padStart(4,'0') de (max dos numéricos que casam `^PR(\d+)$` +1); códigos fora do padrão contam 0 (D:180-185).
- Código de fracção = `${p.codigo}-NN` (NN=irmas.length+1, 2 dígitos) (D:294); em massa: `${p.codigo}-${String(jaTem.length+novas.length+1).padStart(2,'0')}` (D:423, 469).
- normalizarUrl(valor,campo) (D:189-197): vazio→''; se não tem esquema `^[a-zA-Z][a-zA-Z0-9+.-]*:` prefixa 'https://'; `new URL`; erro → «${campo}: «${v}» não é um endereço válido. Exemplo: https://…»; protocolo não http(s) → «${campo}: só se aceitam endereços http:// ou https://.»; devolve u.href.
- Taxa de ocupação do resumo (D:601): `totalU ? Math.round(ocupadas/totalU*100) : null`, totalU = nº de fracções principais (não acessórias); ocupadas = principais com estado.ocupa (ARRENDADA ou VENDIDA). `disponiveisParaOcupar` (D:600) = principais com estado ≠ EM_OBRA e ≠ INDISPONIVEL, calculado mas a taxa NÃO o usa como denominador (comentário D:599 contradiz).
- Por imóvel (D:573-590): total=minhas.length; ocupadas; livres=total-ocupadas; vagasTotal; vagasOcupadas (estado.ocupa); vagasAfectas (PERMANENTE); vagasVisita (VISITA ou CARGA_DESCARGA); acessorios=todas-minhas; areaBruta=r2(Σ area_bruta das principais); rendaReferencia=r2(Σ renda_referencia das principais) (não inclui vagas); avisos=avisosDoImovel(p,todas).
- Resumo global (D:591-609): imoveis, unidades(=principais), ocupadas, disponiveisParaOcupar, taxaOcupacao, areaBruta(princ), vagas (nº), vagasOcupadas (estado.ocupa), vagasArrendaveis, arrecadacoes (natureza ARRECADACAO), porEstado (contagem de principais por cada chave ESTADOS_UNIDADE), porPapel (contagem de imóveis por papel).
- contarPorPiso(us) (D:494-505): agrupa por `txt(u.andar)||'—'`; {andar, piso(num ou null), total, ocupadas(estado.ocupa), area=r2(Σ area_bruta)}; ordena por piso (null→999) depois andar localeCompare 'pt'.
- ordenação: imoveis por codigo localeCompare 'pt' numeric (D:172-173); unidades por ordem depois codigo (D:175-177).
- estado_desde: ao editar, se `antes.estado !== reg.estado` → reg.estado_desde=hojeISO() (D:347); na criação =hojeISO() (D:359).

## 1.5 Validações e mensagens exactas (D)
gravarImovel:
- «Dê uma designação ao imóvel.» (D:218)
- «Um imóvel «${PAPEIS[papel].rot.toLowerCase()}» tem de dizer de quem é: escolha o proprietário.» (D:221) (ex. «arrendado a terceiros», «em mediação»)
- «A área útil não pode ser maior do que a área bruta.» (só quando ambas >0, D:226)
- «Já existe um imóvel com o código ${codigo}.» (D:230)
- «Já existe um imóvel com o artigo matricial ${matriz}. Verifique: a matriz identifica o prédio.» (D:233)
- «Indique o número de habitações do condomínio — é contra esse número que se conferem as fracções registadas.» (tipo com habitacoes e n_habitacoes=0, D:241)
eliminarImovel: «O imóvel tem ${n} fracção(ões). Apague-as primeiro, ou ponha o imóvel como inactivo.» (D:276). Não verifica contratos/obras — dúvida.
gravarUnidade:
- «Imóvel não encontrado.» (D:287); «Dê uma designação à fracção (ex.: «1.º Esq.», «Loja A», «Única»).» (D:289); área útil>bruta idem (D:291); «Já existe uma fracção com o código ${codigo} neste imóvel.» (D:295); «Já existe a fracção «${designacao}» neste imóvel.» (D:296);
- «Uma vaga afecta a uma fracção precisa de saber a qual. Escolha a fracção, ou mude a afectação para «Autónoma».» (D:305)
- «A fracção escolhida não é deste imóvel. Uma vaga só se afecta a uma fracção do mesmo imóvel.» (D:309)
- ««${mae.designacao}» é ${natureza.rot em minúsculas}, não uma fracção. Uma vaga afecta-se a uma habitação, loja ou escritório.» (D:310)
- «Uma vaga não se afecta a si própria.» (D:311) (só dispara em edição)
- Edição: se antes era principal e passa a acessório e há vagas penduradas (park_unit_id==id): «Esta fracção tem ${n} vaga(s) afecta(s) (${designações}). Liberte-as antes de mudar a natureza.» (D:352)
eliminarUnidade: estado ARRENDADA/VENDIDA: «A fracção está ${rot minúsculas}. Mude o estado antes de a apagar.» (D:369); vagas penduradas: «Há ${n} vaga(s) afecta(s) a esta fracção (${designações}). Liberte-as primeiro.» (D:373). Não verifica contratos — dúvida.
mudarEstadoUnidade: «Estado desconhecido.» (D:381); «Fracção não encontrada.» (D:383).
importarDoEdificio: «Imóvel não encontrado.» (D:405); «Este imóvel não está ligado a nenhum edifício do imobilizado.» (D:406).
gerarFraccoesPorPiso: «Imóvel não encontrado.»; «São mais de 500 fracções de uma vez. Gere por blocos ou por partes.» (pisos*porPiso>500, D:452; não multiplica pelo nº de blocos).
criarFraccaoUnica: «Este imóvel já tem fracções.» (D:510).

## 1.6 Operações (D) — comportamento
- gravarImovel(dados,id) (D:215-271): criar/editar; logs «Editar imóvel»/«Criar imóvel» detalhe `${codigo} · ${nome}` (D:267,269). Editar = `update(id, reg)` (não toca criado_*).
- eliminarImovel (D:273-280): log «Eliminar imóvel».
- gravarUnidade (D:284-362): logs «Editar fracção»/«Criar fracção» `${p.codigo} · ${designacao}`.
- eliminarUnidade (D:364-377): log «Eliminar fracção».
- mudarEstadoUnidade(id,estado,motivo) (D:379-386): update {estado, estado_desde=hoje, estado_motivo, actualizado_*}; log «Mudar estado da fracção» `${designacao}: ${rotAntes} → ${rotNovo}${motivo?' — '+motivo:''}`. Sem matriz de transições: qualquer estado→qualquer estado.
- edificiosDisponiveis(c) (D:391-398): de `fixed_assets.where('company_id').equals(parseInt(c))`, filtra `is_master_data!==1 && asset_type==='EDIFICIO'`; devolve {id, rot: `${code? code+' · ':''}${description||name||'Edifício'}`, jaLigado: id já usado por algum imóvel}.
- importarDoEdificio(propertyId,{apenasNovas=true}) (D:402-439): lê asset_rooms e asset_floors por building_id; salta portas is_master_data===1 e (se apenasNovas) as cuja room_id já existe numa fracção; ordena por `ordem`; designacao = `txt(x.nome) || (andar? andar+' · ':'')+'Porta '+txt(x.numero)` (nome do andar = a.nome||a.numero); campos: room_id=x.id, codigo `${p.codigo}-NN`, andar, numero, natureza HABITACIONAL, tipologia 'OUTRA', finalidade 'HABITACAO', áreas 0, estado DISPONIVEL, notas `Tipo na planta: ${x.tipo}` se existir, ordem sequencial. bulkAdd; log «Importar fracções do edifício» `${n} fracção(ões) de ${p.codigo}`; devolve {criadas, total: nº de portas}.
- gerarFraccoesPorPiso(propertyId, opcoes) (D:443-491): opcoes {pisos, por_piso, piso_inicial, blocos(array ou CSV), esquema, tipologia, finalidade, area_bruta, area_util, quartos, casas_banho, mobilada, renda_referencia}. pisos=max(1, round(opcoes.pisos||p.n_pisos)); porPiso=max(1,round(por_piso)); inicial = opcoes.piso_inicial se não ''/null senão p.piso_inicial; blocos vazios → ['']; para cada bloco, piso=inicial+i (i<pisos), k=1..porPiso; designacao=designacaoGerada; ignora e conta `repetidas` se a designação (minúsculas) já existir (inclui as geradas nesta chamada); campos: bloco, andar=nomePiso(piso), piso, numero = esquema==='PORTA' ? String(piso>=0 ? piso*100+k : k) : String(k); estado DISPONIVEL; log «Gerar fracções por piso» `${n} fracção(ões) em ${p.codigo} (${pisos} piso(s) × ${porPiso}${blocos>1 ? ` × ${n} bloco(s)`:''})`; devolve {criadas, repetidas}.
- criarFraccaoUnica(propertyId) (D:508-517): via gravarUnidade com designacao 'Única', tipologia TERRENO se p.tipo==='TERRENO' senão 'OUTRA', finalidade 'HABITACAO', áreas = as do imóvel, estado DISPONIVEL.
- terceiros(c) (D:613-624): `third_parties.where('company_id').anyOf([parseInt(c), String(c)])`, sem is_master_data, ordenados por name 'pt'; company_id gravado ora número ora texto (D:616-619). Qualquer terceiro pode ser proprietário (D:612).

## 1.7 avisosDoImovel(p, us) — mensagens exactas (D:522-560)
Não bloqueiam. principais=us não acessórias; vagas=us vaga.
1. sem principais: «Sem fracções — os contratos prendem-se a fracções, crie pelo menos uma.»
2. tipo.habitacoes e n_habitacoes: d=principais-n_habitacoes; d<0 → «Faltam ${abs(d)} fracção(ões): o condomínio declara ${n} habitações e só estão registadas ${principais.length}.»; d>0 → «Há ${d} fracção(ões) a mais do que as ${n} habitações declaradas no condomínio.»
3. tipo.pisos e n_pisos: pisosComFraccoes = nº de `andar` distintos não vazios das principais; > n_pisos → «As fracções estão em ${x} pisos diferentes, mais do que os ${n} declarados.»; principais sem andar → «${k} fracção(ões) sem piso indicado — sem isso não se conta por andar.»
4. tipo.pisos e !n_pisos: «Sem número de pisos — é o que permite gerar e contar as fracções por andar.»
5. somaBruta=r2(Σ area_bruta de TODAS as us, incl. vagas) > area_bruta do imóvel (>0): «As fracções somam ${somaBruta} m² de área bruta, mais do que os ${area} m² do imóvel.»
6. principais sem área: «${k} fracção(ões) sem área — a área é a base da renda por m² e da repartição de encargos.»
7. vagas: órfãs (PERMANENTE cujo park_unit_id não existe em us): «${k} vaga(s) afecta(s) a uma fracção que já não existe — reveja a afectação.»; cave sem altura: «${k} vaga(s) em cave sem altura máxima registada — é o que evita prometer um lugar onde o carro não entra.»; autónomas arrendáveis sem renda_referencia: «${k} vaga(s) autónoma(s) sem renda de referência — sem preço não há o que propor no contrato.»
8. sem matriz_predial: «Sem artigo matricial — é o que identifica o prédio perante a AGT.»
9. papel PROPRIO e valor_patrimonial==null: «Sem valor patrimonial — é a base do Imposto Predial da detenção.»
10. papel PROPRIO e !building_id: «Não está ligado a nenhum edifício do imobilizado — sem isso não há planta, inventário nem manutenção.»

## 1.8 Ligações a outros módulos (D)
- Activos fixos: `fixed_assets` (asset_type 'EDIFICIO'; code, description, name, is_master_data, company_id) D:391-398; `asset_rooms` (building_id, floor_id, nome, numero, tipo, ordem, is_master_data) e `asset_floors` (building_id, nome, numero) em importarDoEdificio D:407-433. Liga imo_properties.building_id e imo_units.room_id. Só papel PROPRIO (D:224).
- Terceiros: third_parties (D:613-624) → imo_properties.proprietario_id.
- Auditoria: window.logMovement (D:626-629). Datas: window.getLocalDate (D:30). Utilizador: window.currentUser (D:31). Empresa: window.activeCompanyId (D:23).
- Contratos: só comentário (D:143-144).
- Permissões / projectos / contas / notificações / impressão: nenhuma em D.

## 1.9 Código morto / dúvidas (D)
- `disponiveisParaOcupar` inconsistente com a taxa (D:600-601).
- ESQ_DTO piso negativo dá «-1.º Esq.» (D:88).
- Parâmetro `i` não usado em importarDoEdificio (D:417). `NATUREZAS[x].conta` não é lido em D.
- Limite de 500 fracções ignora nº de blocos (D:452).
- Nenhuma validação de `estado` ALIENADO do imóvel contra fracções/contratos.
- Esquema Dexie das tabelas imo_* não está em D (NÃO CONFIRMADO onde é declarado).

=====================================================================
# PARTE 2 — imobiliario_parque.js (P) + imobiliario_parque_ui.js (PU)
=====================================================================

## 2.1 Coleções usadas (P)
- imo_units (vagas): campos park_* conforme 1.2. Leitura via I().unidades filtrada por ehVaga (P:51).
- imo_leases (contratos): lido via `ImobiliarioContratos.contratos({})` (P:72, 92, 196, 214, 365, 382), `C().unidadesDo(c)` (devolve ids das fracções/vagas do contrato), `C().contrato(id)` (PU:374); campos de contrato usados: id, numero, estado ('ACTIVO'), property_id, terceiro_id, park_veiculos (array) — NOVO CAMPO gravado em P:187 (`park_veiculos`, actualizado_em/por).
- audit_logs: `b.audit_logs.add({company_id, action, ref_id, detail, user, date, is_master_data:0})` (P:29-34) — log próprio de P (diferente do logMovement de D). Erro engolido.
- third_parties via I().terceiros (P:95) para o nome do ocupante (`terceiro_id` do contrato).
### park_veiculos (array embebido em imo_leases) — esquema (P:178-183)
{matricula (obrigatória, normalizada), unit_id (parseInt ou null; tem de pertencer ao contrato), marca, modelo, cor, porte (chave PARK_PORTES ou null), condutor, distico} — todos txt. UI só edita matricula, marca, modelo, porte, unit_id, condutor (PU:426-433): cor e distico são preservados? NÃO — gravarVeiculos substitui a lista e a UI não os envia, logo `cor` e `distico` ficam '' ao gravar pelo ecrã (perda silenciosa de dados; dúvida de código).

## 2.2 Fórmulas e regras (P)
- normalizarMatricula(v) (P:40-46): `txt(v).toUpperCase().replace(/[^A-Z0-9]/g,'')`; vazio→''; se length===8 → `${s.slice(0,2)}-${s.slice(2,4)}-${s.slice(4,6)}-${s.slice(6)}` (LD-12-34-AB); senão devolve a string sem hífenes. Não recusa matrículas estrangeiras.
- ocupacaoDaVaga(vagaOuId, contratos) (P:68-83): procura contrato ACTIVO que abranja a vaga (unidadesDo contém v.id) → {via:'PROPRIO', contrato, unit_id:v.id}; senão, se v.park_afectacao==='PERMANENTE' e v.park_unit_id, contrato ACTIVO que abranja a fracção-mãe → {via:'FRACCAO', contrato, unit_id: park_unit_id}; senão null. (Só estado 'ACTIVO' conta; outros estados de contrato ignorados.)
- mapa(propertyId,{q}) (P:89-134): se sem vagas → {pisos:[], total:0, resumo:null}. Por vaga: {vaga, piso (num|null), andar: txt(v.andar) || (piso==null ? '—' : nomePiso(piso)), afectacao: park_afectacao||'AUTONOMA', afecta_a (designacao||codigo da fracção-mãe), ocupacao, via, ocupante (nome do terceiro do contrato.terceiro_id), contrato (numero), veiculos = veiculosDoContrato(contrato, v.id), conflito_porte = matrículas dos veículos com porte && !porteCabe(v.park_porte, x.porte)}. Filtro `q` (minúsculas, includes) sobre [designacao, codigo, park_distico, afecta_a, ocupante, contrato] + matrículas. Agrupa por andar; pisos ordenados DESC por piso (null → −999) «de cima para baixo»; vagas dentro do piso ordenadas por designacao 'pt' numeric. `resumo` calculado sobre TODAS as linhas (antes do filtro) (P:133).
- resumoDe(linhas) (P:136-153): total; arrendaveis=linhas com vagaArrendavel; ocupadas=arrendaveis com ocupacao; livres=arrendaveis-ocupadas; afectas (afectacao PERMANENTE); autonomas (AUTONOMA); visita (VISITA ou CARGA_DESCARGA); **taxa = arrendaveis.length ? Math.round(ocupadas/arrendaveis*100) : null**; porContratoProprio (via PROPRIO); porFraccao (via FRACCAO); comTomada (park_tomada); conflitos (linhas com conflito_porte.length).
- veiculosDoContrato(c, unitId) (P:158-161): `c.park_veiculos` (array); se unitId dado, filtra `x.unit_id==null || mesmo(x.unit_id, unitId)` (veículo sem vaga aplica-se a todas as vagas do contrato).
- gravarVeiculos(leaseId, lista) (P:164-190): abrangidas = unidadesDo(c) + vagas afectas (vagasDaFraccao) a cada fracção do contrato; para cada item: matricula normalizada obrigatória; unit_id deve pertencer a abrangidas; duplicados (mesma matrícula normalizada) recusados; substitui a lista; update imo_leases {park_veiculos, actualizado_em, actualizado_por}; log «Veículos do contrato» `${c.numero}: ${n} veículo(s)`; devolve n.
- matriculaRepetida(matricula, propertyId, excepto) (P:193-198): contratos ACTIVOS do mesmo imóvel (exceto `excepto`) cujos veículos tenham a mesma matrícula normalizada → lista de numero dos contratos.
- afectar(unitId, vagaIds, incluidoNaRenda) (P:206-233): valida mãe; cada vaga: é vaga, mesmo imóvel, sem contrato próprio ACTIVO; update {park_afectacao:'PERMANENTE', park_unit_id, park_incluido_na_renda: (incluidoNaRenda==null ? (v.park_incluido_na_renda||null) : !!incluidoNaRenda)}; log «Afectar vagas» `${mae.designacao} ← ${lista}`; devolve nº. Atenção: NÃO valida que a vaga já afecta a outra fracção (reatribui silenciosamente — a UI avisa «Escolher um lugar que já é de outra fracção muda-o para esta.»).
- libertar(vagaIds) (P:236-251): para cada vaga: se ocupada via FRACCAO acrescenta aviso ««${designacao}» estava a ser usada pelo contrato ${numero} da fracção. Reveja os encargos desse contrato.»; update {park_afectacao:'AUTONOMA', park_unit_id:null, park_incluido_na_renda:null}; log «Libertar vagas» `${n} vaga(s)`; devolve {libertadas: ids.length, avisos} (conta ids recebidos, mesmo ignorados).
- mudarAfectacao(vagaId, afectacao, unitId) (P:254-270): afectacao inválida → «Afectação desconhecida.»; PERMANENTE → afectar(unitId,[vagaId]); vaga não existente → «Vaga não encontrada.»; se há ocupação e a nova afectação não é arrendável: «A vaga está ocupada pelo contrato ${numero}. Uma vaga de ${rot minúsculas} não se arrenda — termine o contrato primeiro.»; update {park_afectacao, park_unit_id:null, park_incluido_na_renda:null, estado: (nova arrendável ? v.estado : 'DISPONIVEL')}; log «Afectação da vaga» `${designacao} → ${rot}`. (Função exportada mas a UI não a chama — a UI usa gravarUnidade.)
- gerarVagas(propertyId, opcoes) (P:278-331): pisos = lista (array ou CSV) de inteiros (Math.round); porPiso=max(1,round(por_piso)); limite `pisos.length*porPiso>500`; prefixo omissão 'G'; tipo: opcoes.park_tipo válido, senão (todos os pisos <0 ? 'SUBTERRANEO' : 'DESCOBERTO'); porte omissão 'LIGEIRO'; afectacao omissão 'AUTONOMA' (PERMANENTE recusada). Numeração: seq = abs(piso)*100 + k; **designacao = `${prefixo}-${piso===0 ? String(k).padStart(2,'0') : seq}`** (G-101, G-201, R/C: G-01); **codigo = `${p.codigo}-${prefixo}${String(seq).padStart(3,'0')}`**; ignora existentes (designação minúsculas) e conta repetidas. Campos criados: bloco, andar=nomePiso(piso), piso, numero=String(seq), natureza ESTACIONAMENTO, park_afectacao, park_tipo, park_porte, park_altura_max/comprimento/largura (>0 → r2), park_tomada, park_distico '', tipologia 'GARAGEM', finalidade 'ESTACIONAMENTO', area_bruta=r2(area_bruta) e area_util=r2(area_bruta) (a mesma), estado DISPONIVEL, renda_referencia r2 ou null, ordem sequencial. Nota: piso positivo e negativo com mesmo abs geram mesma designação (piso 1 e −1 → G-101): o 2.º é saltado como «repetido». Log «Gerar vagas» `${n} vaga(s) em ${p.codigo} (piso(s) ${pisos} × ${porPiso})`. Devolve {criadas, repetidas}.
- encargosPropostos(unitIds) (P:340-361): para cada fracção, cada vaga afecta → {unit_id: vaga.id, origem:'PARQUE', designacao:`Lugar de estacionamento ${v.designacao}`, valor: num(renda_referencia)||0, incluido: park_incluido_na_renda==null ? null : !!x, detalhe: [rot tipo, rot porte, 'com tomada', `altura máx. ${v} m`].join(' · '), fraccao_id}. Consumido pelo módulo de contratos (não lido).
- livres(propertyId) (P:364-374): vagas arrendáveis, sem ocupação e estado ≠ EM_OBRA/INDISPONIVEL.
- avisos(propertyId) (P:378-402) mensagens exactas: «${n} vaga(s) estão num contrato próprio E afectas a uma fracção arrendada: o mesmo lugar está prometido a duas pessoas.»; «${n} vaga(s) arrendáveis sem renda de referência.» (conta TODAS arrendáveis incl. PERMANENTE; ≠ D que só conta AUTONOMA); «${n} vaga(s) com veículo maior do que o porte do lugar.»; «${n} vaga(s) em cave sem altura máxima — é o que evita prometer um lugar onde o carro não entra.»
- vagas(propertyId)=unidades.filter(ehVaga) (P:51); vagasDaFraccao(unitId)= vagas do imóvel da fracção com PERMANENTE e park_unit_id==unitId (P:54-58); fraccoesAfectaveis(propertyId)= unidades não acessórias (P:61).
- exportados (P:404-411): normalizarMatricula, vagas, vagasDaFraccao, fraccoesAfectaveis, livres, ocupacaoDaVaga, mapa, resumoDe, veiculosDoContrato, gravarVeiculos, matriculaRepetida, afectar, libertar, mudarAfectacao, gerarVagas, encargosPropostos, avisos.

## 2.3 Ecrã «Parqueamento» (PU) — `window.renderImoParque` (PU:82-143)
Estado local st={propertyId, q, soLivres(não usado), veiculosLease}. Rota/entrada: renderImoParque no #view-container. Se K() ausente: «O módulo de parqueamento não está carregado.»
- Sem imóveis: cabeçalho «Parqueamento» / «Os lugares de estacionamento do imóvel.» e cartão «Ainda não há imóveis. Comece pelo cadastro.»
- Barra: select de imóvel (`${codigo} · ${nome}`; mudar limpa q), campo de pesquisa placeholder «lugar, dístico, matrícula, fracção ou ocupante» (re-renderiza a cada tecla), botões «Gerar vagas» (fa-wand-magic-sparkles), «Vaga» (fa-plus), «Afectar a fracção» (fa-link), imprimir (ícone fa-print, sem texto).
- Estado vazio (sem pisos e sem pesquisa): cabeçalho «Parqueamento» / «Os lugares de estacionamento do imóvel, por piso.»; botões «Gerar vagas» (primário) e «Vaga avulsa»; texto «${nome} ainda não tem lugares de estacionamento.» + ajuda «Uma vaga é uma fracção com natureza «estacionamento»: entra nos contratos, no mapa de ocupação e na rentabilidade como qualquer outra. Para uma cave inteira use Gerar vagas — numera os lugares por piso de uma só vez.»
- Cabeçalho normal: «Parqueamento» / «Os lugares do imóvel, por piso: quem os ocupa, com que matrícula e por que contrato.»
- KPIs (PU:117-123): «Lugares» (r.total), «Livres» (r.livres), «Ocupados» (r.ocupadas), «Visitas e cargas» (r.visita), «Taxa do parque» (`${taxa}%` ou «—»). Atenção: «Lugares» = TODAS as vagas (incl. visita/carga) enquanto Livres/Ocupados são só arrendáveis.
- Lista de avisos de K().avisos (bloco imo-aviso).
- Por piso: cartão com «${andar}» e «${n} lugar(es) · ${m} ocupado(s)» (ocupado = com ocupacao); grelha de cartões de vaga. Sem resultados da pesquisa: «Nenhum lugar corresponde a «${q}».»
- Cartão da vaga (PU:145-159): designação; «${tipo rot} · ${porte rot}»; ícone tomada (title «Com tomada eléctrica»); «↕${altura}m» (title «Altura máxima»); «ligação + afecta_a»; ocupante (ou rótulo de estado); até 2 matrículas + «+N»; se conflito: «porte» (title «O veículo é maior do que o porte do lugar»); clique abre modal da vaga; title = estado + « — ocupante».
- Cores do lugar (corDa, PU:72-78): EM_OBRA/INDISPONIVEL → #b45309 fundo #fff7ed rótulo do estado; não arrendável (visita/carga) → #7c3aed #f5f3ff rótulo da afectação; ocupada → #1d4ed8 #eff6ff «Ocupada»; senão → #15803d #f0fdf4 «Livre».
- Legenda: «Pique o lugar para o editar, afectar a uma fracção ou registar os veículos. Verde é livre, azul é ocupado, roxo é de uso comum (visitas e cargas) — e esses nunca entram em contratos.»

### Modal «Gerar vagas» (PU:166-200)
Título «Gerar vagas»; ajuda «Em ${nome}. Os lugares ficam numerados por piso — piso −1 dá G-101, G-102…, piso −2 dá G-201. Gerar duas vezes não duplica: o que já existe é saltado.» Campos (grelha 2 col.): «Piso(s)» texto, omissão «-1», ajuda «Separe por vírgulas. Use números negativos para as caves.»; «Lugares por piso» number min 1, omissão 20; «Prefixo» texto maxlength 4 omissão G (ajuda «G-101, P-101, E-101…»); «Bloco (opcional)» placeholder A; «Tipo» select PARK_TIPOS (omissão SUBTERRANEO); «Porte» select PARK_PORTES (omissão LIGEIRO); «Altura máxima (m)» number step .01 placeholder 2.10 (ajuda «Em cave é o que evita prometer um lugar onde o jipe não entra.»); «Área por lugar (m²)» omissão 12.5; «Renda de referência» placeholder 25000 (ajuda «O preço que o contrato vai propor por lugar.»); checkbox «Com tomada para carregamento eléctrico». Botões «Cancelar» / «Gerar». Efeito: gerarVagas; sucesso `${criadas} lugar(es) criado(s)${repetidas? `; ${n} já existiam e foram saltados`:''}.`; erro → avisar(e.message,'erro'). (Nota: o campo Bloco é enviado mas gerarVagas só o grava em `bloco`; comprimento/largura não existem no modal.)

### Modal da vaga (PU:204-284)
Título: designação ou «Nova vaga». Se ocupada: «Ocupada pelo contrato ${numero}[ — por arrastamento da fracção a que está afecta]. Ver ou registar os veículos.» (link → imoParqueVeiculos); senão ajuda genérica. Campos: «Designação *» (placeholder G-101); «Dístico / cartão» (ajuda «O número que a portaria confere.»); «Piso» number omissão −1; «Tipo» select (omissão SUBTERRANEO); «Porte» (omissão LIGEIRO; ajuda «O maior veículo que o lugar aceita.»); «Altura máxima (m)»; «Comprimento × largura (m)» dois inputs (placeholders 5.00 / 2.50); «Área (m²)»; «Renda de referência» (ajuda «O que o contrato vai propor por este lugar.»); «Estado» select ESTADOS_UNIDADE; «Afectação» select PARK_AFECTACOES (mostra `ajuda` da opção); se PERMANENTE (caixa mostra/oculta por onchange): «Fracção a que está afecta *» select de fracções afectáveis (`designacao (codigo)`; ajuda «Arrendar essa fracção leva este lugar junto. O preço decide-se no contrato.») e checkbox «Sugerir como incluída na renda (sem valor acrescido)» (ajuda «É só uma sugestão: o contrato pergunta sempre.»); «Com tomada eléctrica» checkbox «Permite carregar um veículo eléctrico»; «Notas». Botões: «Apagar» (só edição, vermelho), «Veículos» (só se ocupada), «Cancelar», «Gravar».
Gravar (PU:260-284): monta dados {natureza:'ESTACIONAMENTO', designacao, park_distico, piso, andar=nomePiso(piso) ou '', park_tipo, park_porte, altura, comprimento, largura, tomada, afectacao, park_unit_id, area_bruta=area_util=área, renda_referencia, estado, tipologia 'GARAGEM', finalidade 'ESTACIONAMENTO', notas}; chama I().gravarUnidade(propertyId, dados, id); se PERMANENTE grava `park_incluido_na_renda` por update directo (PU:278); mensagens «Vaga actualizada.» / «Vaga criada.». Efeito colateral: gravar pelo modal de edição sobrescreve `bloco`, `numero`, `quartos`, etc. com vazio/ausente? — gravarUnidade usa dados.bloco etc. ausentes → '' (perde bloco/numero de vagas geradas ao editar; dúvida confirmada pelo código: reg.bloco=txt(dados.bloco) PU não envia bloco/numero/codigo; codigo mantém-se porque `txt(dados.codigo)||...` — **recalcula código novo se vazio!** em edição `dados.codigo` é undefined → codigo = `${p.codigo}-${irmas.length+1}` → o código da vaga muda ao editar). Registo como ACHADO (D:294 + PU:262-273).
Apagar: confirmação «Apagar a vaga» / «Apagar «${designacao}»? Não há volta a dar.» (Apagar/Manter, perigo); eliminarUnidade; «Vaga apagada.»

### Modal «Afectar vagas a uma fracção» (PU:303-369)
Pré-condições: sem fracções → «Este imóvel não tem fracções a que afectar vagas.» (aviso); sem vagas → «Este imóvel não tem vagas.» Texto: «Uma vaga afecta é acessório da fracção: arrendar a fracção leva o lugar junto, e o contrato pergunta se vai incluído na renda ou acrescido.» Campos: «Fracção *» select; «Lugares» lista de checkboxes (designação, `tipo · porte[ · valor Kz]`, selo «já de ${fracção}» se PERMANENTE, selo com rótulo da afectação se não arrendável; ajuda «Escolher um lugar que já é de outra fracção muda-o para esta.»); «Sugestão de preço» select: «Deixar em aberto — o contrato pergunta» (''), «Sugerir como incluída na renda» (1), «Sugerir como acrescida à renda» (0). Botões: «Libertar as escolhidas» (confirm «Libertar lugares» / «${n} lugar(es) voltam a autónomos e deixam de ir junto no contrato da fracção.» Libertar/Cancelar), «Cancelar», «Afectar». Mensagens: «Escolha a fracção.», «Escolha pelo menos um lugar.», sucesso `${n} lugar(es) afecto(s).`, `${n} lugar(es) libertado(s).` + avisos (tipo 'aviso' se houver).

### Modal «Veículos do contrato» (PU:373-453)
Título `Veículos do contrato ${numero}`; texto «Os veículos ficam no contrato, não na vaga: mudam com o inquilino e o lugar é o mesmo. É esta lista que a portaria consulta. Este contrato dá direito a ${n} lugar(es).» / «Este contrato não tem lugares de estacionamento.» Tabela (`#pk-veic-tab`): colunas «Matrícula *», «Marca e modelo», «Porte», «Lugar», «Condutor», (acção apagar). Matrícula: placeholder LD-12-34-AB, maiúsculas; Marca/Modelo: placeholders Toyota / Hilux; Porte: «—» + PARK_PORTES; Lugar: «qualquer» + vagas do contrato; botão «Outro veículo»; «Cancelar» / «Gravar». Vagas do contrato = vagas no âmbito + vagas afectas às fracções do âmbito (PU:380-386). Ao gravar: ignora linhas sem matrícula; por linha chama matriculaRepetida e se houver pede confirmação «Matrícula repetida» / «${matriculaNormalizada} já está no contrato ${lista} deste imóvel. Continuar mesmo assim?» (Continuar/Rever); depois gravarVeiculos; «${n} veículo(s) gravado(s).»

### Impressão «Mapa de parqueamento» (PU:457-485)
Janela nova A4 portrait (@page margin 16mm 14mm), título «Mapa de parqueamento — ${nome}»; sub: `${empresa.name} · NIF ${empresa.nif} · emitido em ${hoje} · ${total} lugar(es), ${ocupadas} ocupado(s), ${livres} livre(s), taxa ${taxa}%` (empresa = window.activeCompany); por piso: tabela com colunas «Lugar», «Dístico», «Tipo / porte», «Afectação», «Ocupante», «Contrato», «Matrículas» (separadas por « / »). window.print() automático após 350 ms. Erro de pop-up: «O navegador bloqueou a janela de impressão.» Usa mapa(propertyId,{}) sem filtro.

## 2.4 Ligações (P/PU)
- Contratos (ImobiliarioContratos.contratos/contrato/unidadesDo) — P:72,92,168,196,214,365,382; PU:374,381.
- Terceiros (nome do ocupante) — P:95-98.
- Auditoria audit_logs — P:29-34.
- Notificações: `window.Notificacoes.mostrar({tipo,texto})` com fallback `alert` (PU:19); Diálogo: `window.Dialogo.confirmar({titulo,mensagem,confirmar,cancelar,perigo})` (PU:288,358,440).
- select2 (`js/shared/seletores.js`) depende de `role="dialog"` no modal (PU:36-37).
- Estilos base `window.imoEstilosBase` (PU:49), classes imo-*, ficheiro não lido.
- Empresa: window.activeCompany (PU:460). Permissões: NENHUMA chave em P/PU (confirmado por leitura integral).
- Rentabilidade/contrato: «entra nos contratos, no mapa de ocupação e na rentabilidade» (texto) — integração está noutros ficheiros.

## 2.5 Código morto / dúvidas (P/PU)
- st.soLivres nunca usado (PU:25). P.mudarAfectacao e P.livres sem uso na UI (livres pode ser usada por contratos). `fmt` etc. OK.
- Perda de `cor`/`distico` dos veículos ao gravar pelo ecrã (PU:426-433 vs P:180,182).
- Edição de vaga recalcula `codigo` e zera `bloco`/`numero` (achado acima).
- Placeholder de `park_incluido_na_renda` só gravado se PERMANENTE.
- A taxa do parque exclui visitas/cargas mas o KPI «Lugares» inclui-as.

=====================================================================
# PARTE 3 — imobiliario_areas.js (A) — áreas comuns e reservas (fase 8)
=====================================================================
Namespace `window.ImobiliarioAreas` (A:585-593). Helpers: cent(v)=`Math.round(num(v)*100)/100` (A:30; sem EPSILON, ≠ r2 de D); log próprio em `audit_logs` (A:36-41), igual ao de P.

## 3.1 Coleções (A)
Filtro `!is_master_data && mesmo(imo_company_id, c)` (A:106-110).
### imo_amenities (espaços/áreas comuns) — gravarEspaco (A:170-200, 210)
Chave id auto. Campos: imo_company_id, is_master_data=0, criado_em/criado_por (criação), actualizado_em/por.
- property_id (parseInt, obrigatório; FK imo_properties).
- nome (obrigatório).
- tipo: enum TIPOS_AREA, omissão 'OUTRO'.
- regime: enum REGIMES, omissão 'RESERVA_GRATUITA'.
- capacidade: `num>0 ? round : null`.
- localizacao (txt).
- hora_abre, hora_fecha: «HH:MM» (hhmm); omissão abre 480 (08:00), fecha 1320 (22:00) se vazio/inválido (A:150-151).
- dias_semana: array de inteiros 0..6 (0=Domingo) filtrados; omissão [0,1,2,3,4,5,6] (A:178).
- duracao_min, duracao_max: horas (número) ou null (`dur.min || null`) (A:179).
- antecedencia_min_dias, antecedencia_max_dias: inteiros ≥0, omissão 0 (A:180).
- limite_mes_fraccao: `>0 ? round : null` (A:182).
- exige_aprovacao: boolean (A:183).
- preco: `REGIMES[regime].paga ? cent(preco) : 0`; preco_unidade: enum UNIDADES_PRECO omissão 'HORA'; caucao: `paga ? cent : 0` (A:185-187).
- conta_proveito: txt (código de conta contabilística; validada com `window.Seletores.validarConta` se existir) (A:164-168, 188). «A conta de proveito só se exige quando se cobra — e nunca se põe nenhuma por omissão» — na verdade NUNCA é obrigatória no código (só validada se preenchida).
- prazo_pagamento_horas: `Math.max(1, Math.round(num(x)||48))` (omissão 48 h) (A:191).
- cancel_dias: `max(0, round)`; cancel_pct: `min(100, max(0, round))` (A:193-194). Semântica comentada (A:192): até `cancel_dias` antes devolve tudo; depois, `cancel_pct` (NÃO verificado se cancel_pct é % devolvida ou retida — a fórmula de devolução não está em A).
- regulamento (txt), regulamento_url (txt, sem normalizarUrl), activo (1/0; omissão 1), notas.
### imo_bookings (reservas) — gravarReserva (A:392-410, 426-427)
Chave id auto. Campos: imo_company_id, is_master_data=0, criado_*, actualizado_*; amenity_id; property_id (do espaço); unit_id (parseInt ou null); lease_id (`el.lease_id||null`); terceiro_id (`el.terceiro_id||null`, do contrato vivo); interna (bool = unit_id==null); data (ISO yyyy-mm-dd); hora_inicio, hora_fim (hhmm); pessoas (>0 round ou null); motivo; contacto; valor, caucao, valor_detalhe (calcularValor); sale_id, receipt_id, treasury_id = null; caucao_devolvida_em = null; caucao_retida = 0 (ligações ao pagamento da «fase seguinte» — A:401-403); autorizacao_motivo, autorizacao_por (operador, se motivo), autorizacao_em (agora, se motivo); divida_na_marcacao (`el.divida||0`); notas; estado (só na criação); estado_em, estado_por, motivo_estado, realizada_em (mudarEstado, A:466-471).
  - ao EDITAR (id) o reg inclui todos os campos acima excepto `estado`; sobrescreve criado_*? não (update). Sobrescreve valor/caucao/lease etc. recalculados.
- Índices Dexie / esquema declarado: NÃO está em A (não confirmado).

## 3.2 Enumerações (A) — rótulos exactos
**TIPOS_AREA** (A:45-57) [rot | icone | pedeCapacidade]: SALAO «Salão de festas» fa-champagne-glasses true; GINASIO «Ginásio» fa-dumbbell true; PISCINA «Piscina» fa-person-swimming true; CHURRASQUEIRA «Churrasqueira» fa-fire-burner true; REUNIOES «Sala de reuniões» fa-users-rectangle true; COWORKING «Espaço de trabalho» fa-laptop true; TERRACO «Terraço» fa-umbrella-beach true; CAMPO «Campo de jogos» fa-futbol false; LAVANDARIA «Lavandaria comum» fa-soap false; PARQUE_INFANTIL «Parque infantil» fa-child-reaching false; OUTRO «Outro espaço» fa-door-closed false. (`pedeCapacidade` é para a UI; não é validado em A.)
**REGIMES** (A:59-63) [rot | ajuda | reserva | paga | cor]
- GRATUITO «Uso livre» «Entra quem quer, quando quer. Não se marca nem se cobra — fica no cadastro só para constar e para a manutenção.» reserva=false paga=false #15803d
- RESERVA_GRATUITA «Reserva obrigatória, sem custo» «Marca-se para não haver duas festas no mesmo sábado, mas não se paga nada.» true/false #1d4ed8
- RESERVA_PAGA «Reserva paga» «Marca-se e paga-se. O espaço só fica garantido depois do recebimento.» true/true #b45309
**UNIDADES_PRECO** (A:65-70) [rot | horas]: HORA «por hora» 1; PERIODO «por período de 4 horas» 4; DIA «por dia» 24; FIXO «valor fixo por reserva» 0.
**ESTADOS_RESERVA** (A:74-83) [rot | cor | fundo | ocupa | viva]: PEDIDA «Pedida» #a16207 #fef9c3 ocupa viva; APROVADA «Aprovada» #1d4ed8 #dbeafe ocupa viva; AGUARDA_PAGAMENTO «A aguardar pagamento» #b45309 #ffedd5 ocupa viva; CONFIRMADA «Confirmada» #15803d #dcfce7 ocupa viva; REALIZADA «Realizada» #475569 #f1f5f9 ocupa=true viva=false; FALTOU «Não compareceu» #b91c1c #fee2e2 ocupa=false viva=false; CANCELADA «Cancelada» #64748b #f8fafc false/false; RECUSADA «Recusada» #b91c1c #fef2f2 false/false.
**DIAS** (A:87): ['Domingo','Segunda','Terça','Quarta','Quinta','Sexta','Sábado'] (índice = Date.getDay()).
**TRANSICOES** (máquina de estados da reserva, A:434-440): PEDIDA → [APROVADA, AGUARDA_PAGAMENTO, RECUSADA, CANCELADA]; APROVADA → [REALIZADA, FALTOU, CANCELADA]; AGUARDA_PAGAMENTO → [CONFIRMADA, CANCELADA]; CONFIRMADA → [REALIZADA, FALTOU, CANCELADA]; REALIZADA/FALTOU/CANCELADA/RECUSADA → [] (terminais). Nota: a transição PEDIDA→AGUARDA_PAGAMENTO existe mas aprovar() só vai a APROVADA; AGUARDA_PAGAMENTO→CONFIRMADA depende da fase de pagamento (não implementada aqui: nada em A chama mudarEstado(...,'CONFIRMADA')).
**Estado inicial na criação** (A:426): `e.exige_aprovacao ? 'PEDIDA' : (paga ? 'AGUARDA_PAGAMENTO' : 'APROVADA')`. Logo uma reserva paga COM aprovação fica PEDIDA e ao aprovar vai a APROVADA (salta o pagamento — incoerência/dúvida; deveria ir a AGUARDA_PAGAMENTO).

## 3.3 Fórmulas / regras (A)
- hm(v): regex `^(\d{1,2}):(\d{2})$`; h>24 ou min>59 → null; devolve h*60+min (A:91-97) (aceita 24:00 e 24:59 → 1499; h==24 com min>0 não é rejeitado).
- hhmm(min) = `${floor(min/60) 2 dígitos}:${(min%60) 2 dígitos}` (A:98).
- horasEntre(ini,fim) = `Math.round((hm(fim)-hm(ini))/6)/10` (horas com 1 casa decimal, arredondada aos 6 min) (A:99).
- diaSemana(d) = `new Date(d+'T12:00:00').getDay()` (null se inválida) (A:100); diasEntre(a,b) = `Math.round((Date(b 12:00)-Date(a 12:00))/86400000)` (A:101); mesDe(d)=`d.slice(0,7)` (A:102).
- calcularValor(e, ini, fim) (A:230-242): se espaço sem regime pago → {valor:0, caucao:0, unidades:0, detalhe:'Sem custo'}; senão horas=horasEntre; u=UNIDADES_PRECO[e.preco_unidade]||HORA; unidades = u.horas>0 ? `Math.max(1, Math.ceil(horas/u.horas))` : 1; **valor = cent(preco × unidades)**; caucao=cent(e.caucao); detalhe = u.horas>0 ? `${unidades} × ${u.rot.replace('por ','')} (${horas} h)` : 'valor fixo' (ex. «3 × hora (3 h)», «1 × período de 4 horas (3 h)», «1 × dia (...)»).
- elegibilidade(amenityId, unitId, data, hIni, hFim, exceptoReservaId) (A:254-356) → {espaco, impedimentos[], avisos[], exigeAutorizacao, divida, lease_id, terceiro_id, conflitos[]}. Mensagens exactas, por ordem:
  1. espaço inexistente: «Espaço não encontrado.» (retorna);
  2. inactivo: «O espaço está inactivo.»;
  3. regime sem reserva: ««${nome}» é de uso livre: não se marca.»;
  4. sem data: «Indique a data.» (retorna);
  5. dia não funciona: `${DIAS[ds]} não é dia de funcionamento (abre ${dias.map(DIAS).join(', ')}).`;
  6. horas inválidas: «Indique a hora de início e de fim.»; fim<=ini: «A hora de fim tem de ser depois da de início.»; fora do horário (ini < hm(hora_abre) || fim > hm(hora_fecha)): `Fora do horário: o espaço funciona das ${hora_abre} às ${hora_fecha}.`; duração < min: `A reserva mínima é de ${duracao_min} h e esta tem ${horas} h.`; > max: `A reserva máxima é de ${duracao_max} h e esta tem ${horas} h.`;
  7. antecedência faltam=diasEntre(hoje,d): <0 «A data já passou.»; < antecedencia_min: `Marca-se com pelo menos ${min} dia(s) de antecedência e faltam ${faltam}.`; > antecedencia_max: `Não se marca com mais de ${max} dia(s) de antecedência.`;
  8. conflitos: `Já há reserva das ${hi} às ${hf} (${rot estado})${reservante? ' — '+reservante :''}.` (c.reservante nunca é preenchido por `conflitos()` — só por calendario(); portanto o sufixo nunca aparece);
  9. unitId vazio → aviso «Reserva interna, sem fracção: fica em nome da administração.» e RETORNA (sem validar contrato, limite, dívida);
  10. fracção inexistente «Fracção não encontrada.»; de outro imóvel: «A fracção é de outro imóvel: só os moradores do prédio usam as suas áreas comuns.»;
  11. contratos = `C().contratos({unit_id})` com estado 'ACTIVO' e `(c.posicao||'SENHORIO')==='SENHORIO'`; vivo = contrato com `inicio <= d && (!fim || fim >= d)`; sem vivo: com contratos → «O contrato da fracção não cobre esta data.»; sem contratos → «A fracção não tem contrato de arrendamento em vigor. Se é o proprietário a usar, marque como reserva interna.»; guarda lease_id e terceiro_id;
  12. limite mensal (limite_mes_fraccao>0): jaTem = reservas do espaço+fracção no mesmo mês, excluindo a própria, com `ocupaEspaco(r) || estado==='FALTOU'`; se jaTem.length>=lim: lim===1 → `A fracção já marcou este mês (${datas}) e só pode uma vez por mês.` senão `A fracção já usou as ${lim} reservas deste mês (${datas}).`; senão se jaTem.length && jaTem.length===lim-1 → aviso `Com esta, a fracção esgota as ${lim} reservas que tem direito este mês.`;
  13. dívida: `C().contaCorrente(vivo.id)`; `em_divida > 0.009` → divida=cent(em_divida), exigeAutorizacao=true, aviso `A fracção tem ${divida.toLocaleString('pt-AO',{minimumFractionDigits:2})} Kz de rendas em dívida.`; erro engolido (sem conta corrente não se inventa dívida).
- conflitos(amenityId,data,hIni,hFim,exceptoId) (A:359-366): reservas do espaço nesse dia com ocupaEspaco e id≠excepto; **sobrepõe-se se `hm(r.hora_inicio) < fim && hm(r.hora_fim) > ini`** (intervalos semi-abertos; encostar hora fim=início não conflita).
- gravarReserva (A:370-430) validações exactas: «Escolha o espaço.», «Espaço não encontrado.»; impedimentos juntos com espaço `el.impedimentos.join(' ')`; sem motivo de autorização com dívida: `A fracção tem ${divida} Kz em dívida. Para marcar assim mesmo é preciso o motivo da autorização.`; `O espaço comporta ${capacidade} pessoas e a reserva indica ${pessoas}.` (só se capacidade>0 e pessoas>capacidade; se pessoas null → num=0, passa); em edição de reserva não viva: «Uma reserva «${rot}» não se altera. Faça uma nova.» (verificado DEPOIS de elegibilidade). Nota: a `pessoas` é verificada depois de `elegibilidade` mas antes de gravar.
  - Nota: a `autorizacao_por` é o operador; o texto do comentário diz «exige-se o nome de quem autoriza e o motivo» mas só o motivo é exigido; o nome é o operador.
  - Log «Criar reserva» detalhe `${e.nome} · ${data} ${hi}-${hf}${autorizacao_motivo ? ' (autorizada com dívida)' : ''}`; «Editar reserva» `${e.nome} · ${data} ${hi}-${hf}`.
- mudarEstado(id, estado, {motivo}) (A:442-475): «Reserva não encontrada.», «Estado desconhecido.», transição não permitida: `Uma reserva «${rotActual}» não passa a «${rotNovo}».`; RECUSADA/CANCELADA exigem motivo: «Diga o motivo: quem marcou vai perguntar porquê.»; REALIZADA com data futura (`r.data > hoje`): «A reserva ainda não aconteceu.»; FALTOU com data futura: «Ainda não se pode dizer que não compareceu: a data não chegou.»; ao passar a APROVADA ou CONFIRMADA volta a verificar conflitos (excluindo a própria) e recusa se há reserva NÃO-PEDIDA a ocupar: `O horário já está ocupado por outra reserva das ${hi} às ${hf}. Recuse esta, ou mude-lhe a hora.`; grava {estado, estado_em, estado_por, motivo_estado, realizada_em (só REALIZADA)}; log «Estado da reserva» `${e.nome} ${r.data}: ${rotAntes} → ${rotDepois}[ — motivo]`. Atalhos: aprovar, recusar(id,motivo), cancelar(id,motivo), realizar, naoCompareceu(id,motivo) (A:477-481). Nota: cancelar NÃO calcula/regista devolução de caução nem reembolso (cancel_dias/cancel_pct não são lidos em A fora de gravarEspaco).
- eliminarEspaco (A:215-225): vivas → `O espaço tem ${n} reserva(s) por realizar. Cancele-as, ou ponha o espaço inactivo em vez de o apagar.`; qualquer histórico → `O espaço tem ${n} reserva(s) no histórico. Ponha-o inactivo: apagá-lo levava o registo do que já se passou.`; log «Eliminar área comum».
- eliminarReserva (A:483-492): não viva → `Uma reserva «${rot}» não se apaga: faz parte do histórico do espaço.`; com sale_id/receipt_id → «A reserva já tem documento emitido. Cancele-a em vez de a apagar.»; log «Eliminar reserva».
- gravarEspaco validações exactas (A:142-202): «Dê um nome ao espaço (ex.: «Salão de festas», «Ginásio do bloco A»).»; «Escolha o imóvel a que o espaço pertence.»; «Imóvel não encontrado.»; se reservável e fecha<=abre: «A hora de fecho tem de ser depois da de abertura.»; «A duração mínima não pode ser maior do que a máxima.»; «A antecedência mínima não pode ser maior do que a máxima.»; erro de `Seletores.validarConta(conta)` (texto do próprio Seletores); regime pago sem preço (preco<=0 e unidade≠FIXO): «Um espaço de reserva paga precisa de preço. Ponha o valor, ou mude o regime.» (nota: FIXO com preco 0 passa — dúvida). Logs «Editar área comum»/«Criar área comum» `${p.codigo} · ${nome}`.
- diaDe(amenityId, data) (A:500-525): {espaco, data, dia (nome), abre (dia de funcionamento), ocupadas (reservas que ocupam, ordenadas por hora), livres[{inicio,fim,horas}] calculadas por cursor a partir de hora_abre até hora_fecha (horas = round(min/6)/10), horasLivres=Σ, vazio = abre && sem ocupadas}.
- calendario({amenity_id, property_id, de, ate}) (A:528-548): reservas agrupadas por dia ordenado; cada uma com espaco_nome, fraccao (designacao||codigo), reservante = interna ? 'Administração' : (nome do terceiro || designação da fracção || '—'). Usa I().unidades(null) e I().terceiros().
- resumo(propertyId) (A:552-569): espacos, activos, reservaveis, pagos, porAprovar (PEDIDA), aguardaPagamento, futuras (data>=hoje e viva), hoje (data===hoje e viva), porFechar (data<hoje e viva), comDivida (divida_na_marcacao>0).
- avisos(propertyId) (A:571-583) exactos: `${n} reserva(s) à espera de aprovação.`; `${n} reserva(s) com data passada ainda por fechar — diga se foram realizadas ou se ninguém apareceu.`; `${n} reserva(s) à espera de pagamento: o espaço está preso mas ainda não está garantido.`; `${n} reserva(s) foram autorizadas com rendas em dívida.`; `${n} espaço(s) de festa sem limite de reservas por fracção — uma só família pode ficar com todos os fins-de-semana.` (espaços reserváveis activos de tipo SALAO ou CHURRASQUEIRA sem limite). Se não há espaços: sem avisos.
- Listagens: espacos(filtro {property_id, activo, reservaveis}) ordenado por nome 'pt' (A:112-119); reservas(filtro {amenity_id, property_id, unit_id, de, ate, estado, vivas}) ordenadas por data e hora_inicio (A:122-133).
- NOTA importante: o prazo_pagamento_horas, caução devolvida, sale_id/receipt_id/treasury_id e conta_proveito são só campos preparados; nenhum fluxo em A os usa (comentário A:18 «O PAGAMENTO é da fase seguinte»; ver reservas_fin — fora do âmbito, NÃO lido).

## 3.4 Ligações (A)
- Contratos: C().contratos({unit_id}) (campos estado, posicao ('SENHORIO' omissão), inicio, fim, id, terceiro_id), C().contaCorrente(leaseId) → {em_divida} (A:315,347).
- Terceiros: I().terceiros() (A:532). Fracções: I().unidades(null).
- Contas: `window.Seletores.validarConta(conta)` (A:165-168) — conta_proveito (módulo contabilístico/plano de contas).
- Auditoria: audit_logs (A:36-41).
- Pagamento/facturação: sale_id, receipt_id, treasury_id (A:402; fase seguinte, NÃO implementados em A).
- Permissões: nenhuma em A.

## 3.5 Dúvidas / código morto (A)
- `conflitos()` devolve reservas sem `reservante` → sufixo da mensagem nunca aparece.
- Reserva paga com exige_aprovacao: PEDIDA→APROVADA salta AGUARDA_PAGAMENTO (aprovar só usa 'APROVADA').
- cancel_dias/cancel_pct/prazo_pagamento_horas/caucao_*/conta_proveito sem lógica consumidora em A.
- `pedeCapacidade` não aplicado em A.
- hm aceita «24:30».
- FIXO com preço 0 passa a validação de regime pago.

=====================================================================
# PARTE 4 — imobiliario_areas_ui.js (AU) — ecrã «Áreas comuns»
=====================================================================
Globais: `window.renderImoAreas` (AU:81). Estado local st={aba:'calendario', tocou:false, propertyId:'', amenityId:'', de:'', ate:'', espacoId(não usado)} (AU:30). Dependências: I()=Imobiliario, A()=ImobiliarioAreas, F()=`window.ImobiliarioReservasFin` (ficheiro imobiliario_reservas_fin.js, NÃO LIDO — só as chamadas são inventariadas), C()=ImobiliarioContratos (declarado, não usado em AU).

## 4.1 Ecrã principal (AU:81-133)
- Erro: «O módulo de áreas comuns não está carregado.»
- Período por omissão: de=hoje, até=hoje+30 dias (somarDias, AU:85).
- Ao abrir: `F().caducarPendentes(propertyId||null)` → {caducadas}; aviso inserido no topo: `${n} reserva(s) caíram por não terem sido pagas dentro do prazo — o horário ficou livre.` (AU:87-99). (Caducidade das reservas pagas fora do prazo acontece AQUI, ao abrir o ecrã — não há job.)
- Dados em paralelo: imoveis, espacos(filtro imóvel), resumo, avisos (A) e F().avisos (concatenados) (AU:92-100).
- Título «Áreas comuns» / «Os espaços que o prédio partilha e quem os tem marcados. Uma área comum não é uma fracção: não se arrenda nem conta para a ocupação.»
- KPIs (AU:120-124): «Espaços» (r.espacos), «Reservas por vir» (r.futuras), «Por aprovar» (r.porAprovar), «Por fechar» (r.porFechar), «Hoje» (r.hoje).
- Barra: select imóvel («Todos os imóveis» + `${codigo} · ${nome}`; mudar limpa o filtro de espaço), botões separador «Calendário» (fa-calendar-days), «Espaços» (fa-building-columns), «Novo espaço» (fa-plus). Separador activo = btn-primary.
- Regra: se não há espaços e o utilizador ainda não tocou nos separadores → abre em «espacos» (AU:104).
- Bloco de avisos (lista).

## 4.2 Separador «Espaços» (AU:142-179)
Cartão «Espaços comuns» + botão «Novo espaço». Tabela (classe tabela-padrao se ≥3 linhas, senão no-filters): colunas «Espaço», «Imóvel», «Regime», «Horário», «Regras», «Preço» (alinhada à direita), «Estado», (acções).
- Espaço: ícone do tipo + nome + sub-linha `${tipo rot}${localizacao? ' · '+localizacao}`.
- Imóvel: nome (ou código ou «—»).
- Regime: rótulo do regime com a cor do regime.
- Horário: se regime reserva: `${hora_abre}–${hora_fecha}` + dias (diasRot: «todos os dias» se 7, senão abreviaturas de 3 letras separadas por vírgula, AU:181-185); senão «livre».
- Regras (juntas por « · »): `${duracao_min||'—'}–${duracao_max||'—'} h` (se algum); `${limite}×/mês por fracção`; `min. ${antecedencia_min_dias} d`; «com aprovação»; `${capacidade} pessoas`; senão «—».
- Preço: se regime pago: `${kz(preco)}` + rótulo da unidade + `caução ${fmt(caucao,0)}`; senão «—».
- Estado: selo «Activo» (verde #dcfce7/#15803d) / «Inactivo» (#f1f5f9/#475569).
- Acções: botão «Marcar» (fa-calendar-plus, só se regime reserva e activo) → imoReservaEditar(null,id); botão editar (fa-pen) → imoAreaEditar(id).
- Vazio: «Ainda não há espaços comuns registados.» + «Salão de festas, ginásio, piscina, churrasqueira, sala de reuniões — o que o prédio partilha. Cada espaço traz o seu regulamento: horário, duração, quantas vezes por mês cada fracção pode usar, e se é preciso aprovação.»

## 4.3 Modal «Novo espaço comum»/«Editar ${nome}» (AU:187-260) — `imoAreaEditar(id)`
Texto: «O regulamento do espaço é o que evita a discussão de sábado de manhã: horário, duração, quantas vezes por mês cada fracção pode usar e quem aprova.»
Campos (id → rótulo, tipo, obrigatório, omissão/opções):
- ae-nome «Nome *» texto, placeholder «Salão de festas».
- ae-imovel «Imóvel *» select («— escolher —» + `${codigo} · ${nome}`).
- ae-tipo «Tipo» select TIPOS_AREA (sem omissão explícita: primeira opção = SALAO ao criar).
- ae-local «Localização» placeholder «Cobertura, bloco B».
- ae-cap «Capacidade (pessoas)» number min 0.
- ae-regime «Regime *» select REGIMES, omissão RESERVA_GRATUITA; ajuda = `ajuda` do regime; onchange mostra/oculta blocos.
- Bloco «Quando se pode usar» (visível se regime.reserva): ae-abre «Abre às» time omissão 08:00; ae-fecha «Fecha às» time omissão 22:00; «Dias» checkboxes `.ae-dia` (Dom..Sáb abreviados a 3 letras; todos marcados por omissão); ae-dmin «Duração mínima (h)» number step 0.5 min 0; ae-dmax «Duração máxima (h)» idem; ae-amin «Antecedência mínima (dias)» (ajuda «Para dar tempo de preparar o espaço.»); ae-amax «Antecedência máxima (dias)» (ajuda «Impede marcar o salão para daqui a dois anos.»); ae-lim «Limite por fracção/mês» (ajuda «Sem limite, uma família fica com todos os sábados.»); ae-aprov «Aprovação» checkbox «Exige aprovação».
- Bloco «Quanto custa» (visível se regime.paga): ae-preco «Preço» number step 0.01 min 0; ae-unid «Cobrado» select UNIDADES_PRECO; ae-cauc «Caução» (ajuda «Devolve-se se não houver danos. Nunca é proveito.»); ae-prazo «Prazo para pagar (horas)» min 1 omissão 48 (ajuda «Passado o prazo sem pagar, a reserva cai e liberta o horário.»); ae-cdias «Devolve tudo até (dias antes)» omissão 7 (criação); ae-cpct «Depois devolve (%)» min 0 max 100 omissão 50 (ajuda «Quem desiste em cima da hora deixa o espaço por ocupar.») — logo cancel_pct = % DEVOLVIDA depois do prazo; ae-conta «Conta de proveito» select (data-seletor="conta"; «— sem conta —» + `${code} — ${name}` do plano da empresa, `chart_of_accounts` por company_id anyOf [parseInt, String, original], sem is_master_data, ordenadas por code; ajuda «Só se preenche aqui; o ERP nunca põe conta nenhuma por omissão.»).
- ae-reg «Regulamento» textarea (placeholder «Devolver limpo, sem música depois das 22h...»); ae-regurl «Regulamento (endereço)» placeholder https://...; ae-activo «Activo» checkbox «O espaço está em uso» (marcado por omissão ao criar).
Botões: «Apagar» (só edição) → imoAreaApagar; «Cancelar»; «Gravar». Mensagens: «Espaço actualizado.» / «Espaço criado.»; erros via avisar(e.message,'erro'). Apagar: confirm «Apagar o espaço» / «Apagar «${nome}»? Se já teve reservas, ponha-o inactivo em vez de o apagar.» (Apagar/Manter, perigo) → «Espaço apagado.»
- `Seletores.varrer(modal)` activa os selects de conta (AU:259).
- Nota: o select de conta usa TODAS as contas do plano (não filtra por classe de proveitos, 6/7) — sem restrição aplicada em AU.

## 4.4 Separador «Calendário» (AU:314-358)
- Barra: «De» date, «Até» date, «Espaço» select («Todos» + espaços), botão «Marcar» (se existem reserváveis activos) e imprimir (fa-print).
- Sem espaços reserváveis activos: cartão com `Nenhum dos espaços registados aceita reservas — são todos de uso livre, ou estão inactivos.` (ou «Ainda não há espaços comuns registados.») + «Salão de festas, ginásio, piscina, churrasqueira, sala de reuniões — o que o prédio partilha. Para aparecer no calendário, o espaço tem de ter regime de reserva.» + botões «Criar o primeiro espaço» e «Ver os espaços».
- Lista por dia: cabeçalho `${dia}, ${data}` + `${n} reserva(s)`; por reserva: horas (início/fim empilhadas, cor do estado), `${espaco_nome} — ${reservante}` + `(${fraccao})`, motivo, `autorizada com ${kz(dívida)} em dívida — ${autorizacao_por}`, valor (`${kz(valor)} + caução ${kz(caucao)}`), selo de estado e botão → imoReservaAbrir(id).
- Vazio: `Nada marcado entre ${de} e ${ate}.`
- Consulta: A.calendario({property_id, amenity_id, de, ate}) — lista TODOS os estados (incl. cancelada/recusada).

## 4.5 Modal «Marcar espaço»/«Editar reserva» (AU:362-456)
Texto: «Só marca quem tem contrato em vigor numa fracção deste imóvel. Para um evento da administração, deixe a fracção em branco.» Pré-condição: «Não há espaços com reserva activos.» (aviso).
Campos: rv-esp «Espaço *» (reserváveis+activos, filtrados pelo imóvel seleccionado; mudar redesenha o dia e recarrega fracções do imóvel do espaço); rv-un «Fracção» («— reserva interna (administração) —» + fracções principais do imóvel por `designacao||codigo`); rv-data «Data *» date omissão hoje; rv-pes «Pessoas» number min 1 (+ «Cabem ${capacidade}.»); rv-ini «Das *» time omissão hora_abre do espaço; rv-fim «Às *» time omissão hora_fecha; rv-mot «Motivo» (placeholder «aniversário, assembleia de condóminos...»); rv-cont «Contacto» (placeholder «telefone de quem marca»); rv-aut «Autorização *» (só visível se exigeAutorizacao; placeholder «porque se autoriza apesar da dívida»; ajuda «A fracção deve rendas. Para marcar assim mesmo é preciso que alguém assuma a decisão — fica gravada com o seu nome.»).
- Painel do dia (rv-dia): `${dia}, ${data} — ` + «o espaço não abre neste dia» | «livre o dia todo (${abre}–${fecha})» | `${n} reserva(s)`; selos com intervalos ocupados (cores do estado) e «Livre: ini–fim · …» com links que preenchem hora início/fim (imoReservaUsarHora).
- Verificação em directo (imoReservaVerifica): chama elegibilidade (com excepto=null mesmo ao editar — BUG: em edição a própria reserva aparece como conflito consigo, o botão fica desactivado; a gravação em A usa o `id` correcto), mostra caixa vermelha «Não se pode marcar assim:» + lista de impedimentos; caixa de avisos; se valor>0: `${kz(valor)} — ${detalhe}[, mais ${kz(caucao)} de caução]. O espaço só fica garantido depois do pagamento.`; botão «Marcar» desactivado se há impedimentos.
- Gravar: «Reserva marcada.» / «Reserva actualizada.»; erros por avisar.
- Observação: o modal envia `autorizacao_motivo` mas `elegibilidade` só exige se exigeAutorizacao.

## 4.6 Modal de detalhe da reserva (AU:474-520) — `imoReservaAbrir(id)`
Cabeçalho: ícone do tipo + nome do espaço + selo do estado; sub-linha `${data} · ${hi}–${hf} · ${quem}` (quem= 'Administração' | nome do terceiro | designação da fracção | '—'). Botão «Fechar». Cartão com linhas: «Fracção» (designação ou «reserva interna»), «Motivo», «Pessoas» (`${n} de ${cap}`), «Contacto», «Valor» (+detalhe), «Caução», «Dívida na marcação», «Autorizada por» (+motivo), «Motivo do estado», «Marcada em» (`${criado_em[:10]} por ${criado_por}`). Painel «Dinheiro» (ver 4.7). Regulamento do espaço (texto). Botões conforme TRANSICOES[estado] e viva: «Aprovar» (fa-check, se APROVADA permitida), «Recusar» (fa-ban), «Realizada» (fa-flag-checkered), «Não compareceu» (fa-user-slash), «Cancelar» (fa-xmark), e se viva «Alterar» (fa-pen → abre editar) e botão apagar (fa-trash). + botões financeiros.
- Observação: a transição PEDIDA→AGUARDA_PAGAMENTO existe em TRANSICOES mas NÃO há botão na UI para ela (só Aprovar).

## 4.7 Painel «Dinheiro» e acções financeiras (AU:528-571; só se F() existe e regime paga)
Chamadas ao módulo `ImobiliarioReservasFin` (a conferir noutro inventário): situacao(id) → {valor, recebido, por_pagar, caucao_devida, caucao_recebida, caucao_estado, prazo, caducou, reserva, espaco}; ESTADOS_CAUCAO; facturar(id)→{doc_number}; receber(id,{valor, caucao, data, payment_method, account_code, conta_caucao})→{estado}; gravarVistoria(id, quando, {estado, observacoes, url, data}); fecharCaucao(id,{reter, motivo, conta_caucao, abrir_obra, custo_estimado})→{devolvida, retida, doc_number, work_id}; previsaoCancelamento(id)→{explicacao, recebido, devolve, pct, retem, caucao_a_devolver}; cancelarComReembolso(id, motivo)→{devolve, recebido}; caducarPendentes; avisos.
Campos da reserva que o painel lê (gravados por ReservasFin, não por A): doc_number, caucao_doc_number, caucao_devolvida, caucao_devolvida_em, caucao_retida, caucao_motivo, reembolso_previsto, reembolso_retido, reembolso_pct, danos_work_id, vistoria_antes/vistoria_depois {estado, observacoes, url, em, por}, sale_id.
Linhas do painel: «Utilização», «Factura» («por emitir» se não há doc_number), «Recebido» (verde se >0), «Por pagar» (>0.009, vermelho), «Caução» (`${recebida} de ${devida} — ${ESTADOS_CAUCAO[estado]}`), «Retido por danos», «Devolvido» (`em ${data}`), «Porquê», «Reembolso do cancelamento» (`${previsto} de ${previsto+retido} (${pct}%)`), «Ordem dos danos» (#work_id), mensagem do prazo se estado AGUARDA_PAGAMENTO: caducou → «O prazo de pagamento passou: a reserva cai na próxima abertura do ecrã e o horário fica livre.» senão `O horário está preso até ${prazo[:16] com 'T'→' às '}. Sem pagamento até lá, a reserva cai sozinha.`; vistorias «Vistoria de entrada»/«Vistoria de saída» (só se caução recebida; «por fazer» se vazia; link «prova»).
Botões (AU:560-569): «Facturar» (viva, sem sale_id, valor>0, estado≠PEDIDA, não interna); «Receber» (viva, com sale_id, e por_pagar>0.009 ou caução recebida < devida−0.009); «Vistoria» e «Fechar caução» (caução>0, recebida>0, sem devolvida nem retida).
- Facturar: «Factura ${doc_number} emitida. A reserva fica garantida quando o pagamento entrar.»
- Modal «Receber da reserva»: texto «A utilização é receita; a caução é dinheiro à guarda da empresa, a devolver — por isso vão por caminhos diferentes.»; campos rr-val «Utilização» (omissão = por_pagar; ajuda `Faltam ${n} Kz.`/«Já está pago.»), rr-cauc «Caução» (omissão por caucionar; desactivado se nada; ajuda `Faltam ${n} Kz de caução.`/«Sem caução por receber.»), rr-data «Data» (hoje), rr-meio «Meio» (Numerário, Transferência, Multicaixa, Cheque), rr-conta «Conta de caixa ou banco» (contas do plano com `is_treasury` OU código a casar `/^4[23]|^45/`; ajuda «Onde o dinheiro entrou. O ERP não põe conta nenhuma por omissão.»). Botão «Registar». Mensagens: «Recebido. A reserva está confirmada e o espaço garantido.» (se estado resultante CONFIRMADA) / «Recebimento registado.» — é aqui, em ReservasFin.receber, que AGUARDA_PAGAMENTO→CONFIRMADA acontece.
- Modal «Vistoria»: «A de entrada é a fotografia do estado em que se entregou o espaço; a de saída é o que justifica reter caução — e sem ela não se retém nada.»; «Momento» (DEPOIS «Saída — depois de usar», ANTES «Entrada — antes de entregar»); «Como ficou» (BOM «Bom — sem danos», SUJO «Entregue sujo», DANIFICADO «Com danos», EM_FALTA «Com coisas em falta»); «Data»; «O que se encontrou» textarea; «Fotografias ou relatório (endereço)» (ajuda «O ERP guarda o endereço do documento, não o ficheiro.»). «Vistoria registada.»
- Modal «Fechar a caução»: «Há ${kz(caucao_recebida)} à guarda da empresa. O que não se retiver volta ao morador; o que se retiver passa a ser receita e é facturado.»; sem vistoria de saída: «Sem vistoria de saída só se pode devolver tudo: reter dinheiro de outra pessoa sem prova não se faz.» + link «Registar a vistoria agora»; campos «Reter por danos» (max = caução recebida; desactivado sem vistoria), «Devolve-se» (readonly = max(0, recebida − reter) formatado), «O que se encontrou» (obrigatório para reter — ajuda «Obrigatório para reter.»), «Conta de caixa ou banco», e se reter>0: «Reparação» checkbox «Abrir ordem de manutenção para reparar» (marcado) + «Custo estimado da reparação» (placeholder «deixe em branco para usar o valor retido»). Resultado: `Caução fechada: devolvidos ${fmt(devolvida)} Kz[, retidos ${fmt(retida)} Kz (${doc_number||'facturado'})][. Ordem de reparação aberta.|.]` — liga a ordens de manutenção (work_id) e a facturação.
- Mudar estado (`imoResMudar`, AU:723-762): cancelar reserva já paga (recebido>0.009): mostra confirmação «Cancelar uma reserva paga» com `explicacao`, «Recebido: …», «Devolve-se: … Kz (pct%)», «Fica retido: …», «Caução a devolver» e «A nota de crédito da devolução faz-se em Vendas, sobre a factura ${doc}.»; pede motivo obrigatório («Motivo do cancelamento» / «Fica no registo da reserva.») → cancelarComReembolso; `Reserva cancelada. A devolver ${devolve} Kz de ${recebido}.`. Para RECUSADA/CANCELADA/FALTOU pede motivo (títulos «Recusar a reserva», «Cancelar a reserva», «Não compareceu»; texto «Diga o motivo — quem marcou vai perguntar porquê.»; obrigatório excepto FALTOU) — NOTA: A.mudarEstado exige motivo só para RECUSADA/CANCELADA (FALTOU opcional, consistente). Sucesso: `Reserva ${rot em minúsculas}.` (ex. «Reserva aprovada.»).
- Apagar reserva: confirm «Apagar a reserva» / «Apagar deixa o registo sem rasto. Para manter o histórico, cancele-a em vez de a apagar.» → «Reserva apagada.»

## 4.8 Impressão «Reservas de áreas comuns» (AU:776-798)
Janela A4 portrait. Subtítulo `${empresa.name} · NIF ${nif} · de ${de} a ${ate} · emitido em ${hoje}`. Por dia: tabela «Horas», «Espaço», «Quem», «Fracção», «Motivo», «Valor» (direita), «Estado». «Nada marcado neste período.» se vazio. Erro: «O navegador bloqueou a janela de impressão.» Print automático em 350 ms.

## 4.9 Ligações e permissões (AU)
- Plano de contas: tabela `chart_of_accounts` (code, name, is_treasury, is_master_data, company_id) (AU:304-310); `Seletores.varrer` (select2).
- ReservasFin (facturação/vendas, tesouraria, manutenção/ordens de trabalho `work_id`, nota de crédito em Vendas): AU:90, 97, 531, 557, 575, 617, 659, 712, 728-741.
- Notificações/Diálogo: Notificacoes.mostrar, Dialogo.confirmar, Dialogo.pedir.
- Permissões: NENHUMA chave em AU. Impressão: janela com window.print().

## 4.10 Dúvidas (AU)
- imoReservaVerifica passa `id=null` (AU:441): ao editar, a verificação em directo conta a própria reserva como conflito.
- espacoId e C() declarados e não usados. Nota: reservas CANCELADAS aparecem no calendário.
- O estado «CONFIRMADA» só se atinge via ReservasFin.receber (não lido).

=====================================================================
# PARTE 5 — imobiliario_ficha_ui.js (F)
=====================================================================
Modal «Ficha da fracção», `window.imoFichaFraccao(unitId, aba)` (F:58), «abre de qualquer ecrã do módulo» (F:3). Não tem coleções próprias; lê e apresenta dados de fracção + módulos vizinhos. Namespaces usados: I=Imobiliario, C=ImobiliarioContratos, O=ImobiliarioObras, P=ImobiliarioPlanos, R=ImobiliarioRentabilidade, T=ImobiliarioTransaccoes, K=ImobiliarioParque, S=ImobiliarioSub (declarado, NÃO usado) (F:16-23).

## 5.1 Estrutura (F:58-118)
- Modal id `imo-fraccao-modal` (z-index 9100, max-width 900px) — id diferente de `imo-ficha-modal` (usado pelas fichas de apetrechamento / FormularioLinhas) (F:71-73).
- Erro: «Fracção não encontrada.» (F:63).
- Cabeçalho: ícone + `designacao||codigo` + selo do estado (cor/fundo de ESTADOS_UNIDADE); sub-linha `${imovel.codigo} · ${imovel.nome}[ · ${municipio}] · ${PAPEIS[papel].rot}[ · neste estado desde ${estado_desde}]`.
- Botões: «Mudar estado» (fa-arrows-rotate), «Abrir o imóvel» (fa-folder-open; chama `window.imoAbrirDaOcupacao(property_id)` se existir), imprimir (fa-print, sem texto), «Fechar».
- 7 separadores (F:67-69) [chave, rótulo, ícone]: resumo «Resumo» fa-circle-info; contratos «Contratos» fa-file-signature; rendas «Rendas» fa-money-bill-wave; obras «Manutenção» fa-screwdriver-wrench; bens «Inventário» fa-couch; rentab «Rentabilidade» fa-chart-line; negocios «Negócios» fa-handshake. Trocar de separador re-abre o modal inteiro (imoFichaAba → imoFichaFraccao) (F:103). Erros do painel mostrados em `imo-aviso`.
- «Carregamento à medida» (F:10-11): cada separador carrega quando pedido.

## 5.2 Separador «Resumo» (F:122-223)
Três cartões:
1. «A fracção»: Código; Designação; Natureza (só se acessório; rótulo NATUREZAS); Bloco (se existe); Piso (nomePiso, se existe); Tipologia (TIPOLOGIAS); Finalidade (FINALIDADES); Mobília (MOBILIA); «Área bruta / útil» (`m2(bruta) / m2(útil)`; m2 = `fmt(v,2)+' m²'` ou «—» se 0); «Quartos / casas de banho» (se quartos); «Artigo matricial» (se existe); «Porta no imobilizado» (`#${room_id}` se existe).
2. «Situação»: contrato vivo = estado ACTIVO e `(posicao||'SENHORIO')==='SENHORIO'`; tomada = ACTIVO e posicao 'INQUILINO' (a empresa é inquilina). Se vivo: «Arrendada por» (link para contrato → imoFichaIrContrato), «Inquilino», «Renda» (`kz(renda)` + periodicidade em minúsculas via C().rotPeriodicidade), «Vigência» (`${inicio} a ${fim||'sem termo'}`), «Em dívida» (cc.em_divida; vermelho se >0, verde senão), «Caução em poder da empresa» (cc.caucao_em_poder se >0); senão «Sem contrato de arrendamento em vigor.» Se tomada (bloco «A empresa paga por»): «A empresa paga por» (link), «Ao senhorio», «Renda paga», e se também vivo: **«Margem mensal» = renda(vivo) − renda(tomada)** (verde se ≥0, vermelho <0) (F:170) — subarrendamento. Mais «Renda de referência» (se >0), «Valor de venda» (venda_referencia, se >0).
3. «De relance»: «Contratos registados» (nº), «Ordens abertas» (`${abertas} de ${total}`; abertas = obras da fracção com estado fora de CONCLUIDA/CANCELADA; sublinhado laranja se >0), «Bens no inventário» (O().bensDaFraccao), «Vagas afectas» (se existem; K().vagasDaFraccao, só para não-acessórios), «Documentos» (nº), notas (texto pré-formatado).
- Cartão «O lugar» (só se a fracção é vaga) com: Tipo, Porte máximo, Altura máxima (`${fmt(altura,2)} m`), Comprimento × largura (se algum), Afectação, «Acessório de» (link para a ficha da fracção-mãe), Dístico, «Tomada eléctrica» (Sim/Não); frase `Ocupado pelo contrato ${numero}[ — por arrastamento da fracção a que está afecto].` + `Veículos: ${matrículas}.` ou «Sem veículos registados.»
- Cartão «Estacionamento que vai junto» (só se a fracção tem vagas afectas): tabela «Lugar», «Tipo / porte», «Dístico», «Referência» (renda_referencia), «Sugestão» (park_incluido_na_renda: null → «por decidir no contrato»; true → «incluída na renda»; false → «acrescida à renda»); texto «Arrendar esta fracção leva estes lugares junto. O contrato pergunta sempre o que se cobra por eles.»
- Cartão «Documentos»: lista `${TIPOS_DOC[tipo]||tipo||'Documento'}[ n.º ${x.numero}] — abrir` (link target _blank). NOTA: lê `x.numero` e não mostra `x.nome`/`x.data`; o esquema gravado por D.limparDocumentos é {tipo,nome,url,data} — `numero` nunca existe (inconsistência).

## 5.3 Separador «Contratos» (F:233-269)
- Fonte: C().contratos({unit_id}) (inclui contratos que abrangem a fracção dentro de prédio/condomínio inteiro — texto final «Aparecem também os contratos que abrangem esta fracção dentro de um prédio ou condomínio inteiro.»).
- Sem contratos: se a fracção é vaga ocupada via FRACCAO: «Este lugar não tem contrato próprio: vai junto com a fracção a que está afecto, pelo contrato ${numero}. Para o arrendar sozinho, liberte-o da fracção no ecrã do parqueamento.»; senão «Esta fracção nunca teve contrato.»
- Tabela: «Número» (+ «com mais ${n-1} fracção(ões)» se o contrato abrange várias), «Tipo» (C().TIPOS[tipo] sem o texto entre parênteses), «Lado» (C().POSICOES[posicao||'SENHORIO'] rot/cor), «Outra parte» (terceiro), «Vigência» (`inicio` / `fim_real||fim||'sem termo'`), «Renda» (direita), «Caução» (`caucao_recebida` se caução>0 senão «—»), «Estado» (cores: RASCUNHO #92400e, ACTIVO #15803d, SUSPENSO #b45309, TERMINADO #475569, DENUNCIADO #b91c1c; rótulo C().ESTADOS; `motivo_saida` cortado a 28 + «…»), botão → imoFichaIrContrato.
- Navegação: `location.hash='#/imobiliario/contratos'` depois `window.imoArrAbrir(id)` após 300 ms (F:225-229). Rota hash nova a replicar: #/imobiliario/contratos.

## 5.4 Separador «Rendas» (F:273-304)
- Contratos da fracção com estado em [ACTIVO, TERMINADO, DENUNCIADO, SUSPENSO]; para cada: C().contaCorrente(id) → {linhas, posicao, facturado, recebido, em_divida}; salta se erro ou sem linhas. Por contrato cartão: número + lado (POSICOES rot); resumo à direita: se SENHORIO «Facturado X · Recebido Y [· Em dívida Z]»; se não «Em documento X · Pago Y [· Por pagar Z]».
- Tabela (últimas 12 linhas): «Período», «Vencimento» (+ `${atraso} dia(s)` a vermelho), «Base», «A facturar»/«Valor» (total_facturar), «A receber»/«A pagar» (total_receber), «Recebido»/«Pago» (pago), «Estado» (C().ESTADOS_RENDA), «Documento» (`doc_number` ou `Tesouraria #${treasury_id}` ou «—»). Se >12 linhas: `A mostrar os últimos 12 de ${n} períodos.`
- Vazios: «Sem contratos com rendas.» / «Ainda não há rendas geradas nesta fracção.»

## 5.5 Separador «Manutenção» (F:308-366)
- Obras = O().obras({}) filtradas por unit_id, ordenadas por data_pedido desc. Cartão «Ordens de apetrechamento e manutenção» + botão «Nova ordem» (→ `#/imobiliario/obras` + `window.imoEditarObra(null)` após 350 ms). Tabela: «N.º» (+ «de plano» se plan_id), «Tipo» (O().TIPOS_OBRA icone/rot), «Descrição» (60 car.), «Pedido» (data_pedido), «Prevista» (data_prevista; «em atraso» se `data_prevista < hoje` e estado fora CONCLUIDA/CANCELADA), «Quem paga» (O().QUEM_PAGA; «a debitar» se debitavel; «debitado ${debitado_em}»), «Custo real / previsto» (custo_real em negrito + «prev. custo_estimado», ou só estimado), «Estado» (O().ESTADOS_OBRA selo), botão → `imoAbrirObra(id)` via `#/imobiliario/obras`. Vazio «Nenhuma ordem nesta fracção.»
- Cartão «Planos de manutenção e vistoria»: P().planos({unit_id}) + P().estado(id): colunas «Plano» (+«inactivo»), «Tipo», «Periodicidade» (P().PERIODICIDADES), «Próxima» (e.proxima.data | «datas em atraso» | «calendário cumprido»), «Datas» (nº de pl.datas), «Em falta» (e.em_falta).

## 5.6 Separador «Inventário» (F:370-428)
- Bens: O().bensDaFraccao(u.id). Ficha-tipo escolhida: `O().fichas()` com activo≠0; a primeira com mesma tipologia E mesma finalidade, senão só mesma tipologia, senão nenhuma (F:372-375). Faltas: O().necessidadesDeApetrechamento(unitId, fichaId) → linhas {descricao, product_id, quantidade, tem, falta}. Produtos O().produtos() (code — name).
- Cartão «O que está na fracção» + botão «Gerir o inventário» (→ `#/imobiliario/obras` + `imoBensFraccao(unitId)` após 350 ms). Tabela: «Bem», «Artigo», «Qtd.» (`quantidade||1`), «Estado» (O().ESTADOS_BEM; vermelho/negrito se DANIFICADO ou EM_FALTA), «Desde» (data), «Origem» (origem||'manual'). Vazio «Inventário vazio.»
- Cartão `Contra a ficha «${ficha.nome}»`: `${n} artigo(s) em falta. Um bem danificado ou em falta não conta como tendo.`; tabela «Artigo», «Deve ter», «Tem», «Falta» (linhas em falta a amarelo); botão «Abrir ordem de apetrechamento» (se há falta) → O().abrirApetrechamento(unitId, fichaId) → {itens, workId}: «Ordem aberta com ${itens} linha(s) em falta.» e abre a obra; sem faltas «A fracção tem tudo o que a ficha manda.»; sem ficha: «Não há ficha de apetrechamento para esta tipologia — sem ela não se sabe o que a fracção devia ter.»

## 5.7 Separador «Rentabilidade» (F:432-472)
- Se R() ausente: «O módulo de rentabilidade não está carregado.» Período: `ate = C().periodoDe(hoje)`; `de = C().somarMeses(ate, -11)` (12 meses civis fechando no mês corrente). `R().daFraccao(unitId,{de,ate})`; vazio: `Sem rendas nem obras entre ${de} e ${ate}.`
- Tabela «Previsto | Executado | Desvio» com `cel(prev, exec, inverter)`: desvio = `round((exec−prev)*100)/100`; bom = inverter ? d<=0 : d>=0; |d|<0.01 → «—» cinzento, senão verde/vermelho com sinal «+». Linhas: «Renda recebida» (prev.receita/exec.receita); «Renda paga» (se algum, inverter); «Selo e Imposto Predial» (`selo + ip_proprio`, inverter); «Manutenção» (inverter); «Apetrechamento e melhorias» (investimento, inverter); «Debitado ao inquilino» (recuperado); rodapé «Resultado» (prev.resultado / exec.resultado / desvio com «+» se ≥0).
- Linhas extra: «Meses» (`${meses_executados} facturado(s) de ${meses}`), «Valor da fracção» (valor_fraccao), «Rendimento anual» (`pct(yield_pct)` com 1 casa + `(previsto ${yield_prev_pct}%)`), avisos (lista). Botão «Ver no ecrã» → `#/imobiliario/rentabilidade`. Os cálculos (yield, resultado, etc.) vivem em ImobiliarioRentabilidade (NÃO lido; só consumo aqui).

## 5.8 Separador «Negócios» (F:476-503)
- T() ausente: «O módulo de compra e venda não está carregado.» Negócios: T().negocios({}) filtrados por `unit_id == fracção` OU (negócio sem unit_id e mesmo property_id) (imóvel inteiro). Vazio: «Esta fracção nunca esteve num negócio de compra ou venda.»
- Tabela: «Número», «Tipo» («Compra» / «Venda», cor T().TIPOS), «Âmbito» («esta fracção»/«imóvel inteiro»), «Contraparte», «Escritura» (`data_escritura` ou `promessa ${data_promessa}` ou «—»), «Preço», «Resultado» (`mais_valia`, verde ≥0, vermelho <0, «—» se null), «Fase» (T().FASES[estado].rot), botão → `#/imobiliario/negocios` + `imoNegAbrir(id)`.

## 5.9 «Mudar estado» (F:507-526) — transições do estado da fracção
- Dialogo.escolher({titulo:'Estado da fracção', mensagem:'Para que estado?', opcoes: todos os 6 ESTADOS_UNIDADE}) → se INDISPONIVEL ou EM_OBRA pede motivo (Dialogo.pedir titulo «Porquê?», mensagem «Diga o motivo (fica no registo)», NÃO obrigatório: cancelar aborta, vazio passa); chama I().mudarEstadoUnidade(unitId, estado, motivo); «Estado actualizado.»; reabre a ficha; se hash contém «ocupacao» chama `window.renderImoOcupacao()` (ecrã do mapa de ocupação, NÃO lido). Não há matriz de transições: qualquer estado → qualquer estado (inclui ARRENDADA/VENDIDA à mão apesar do comentário de D).

## 5.10 Impressão «Ficha da fracção» (F:528-578)
A4 portrait (margens 18/15 mm). Título `Ficha da fracção — ${designacao||codigo}`; sub `${p.codigo} · ${p.nome}[ · morada][, município] · emitida em ${hoje}`. Caixa: Estado (+ «desde»), Tipologia/Finalidade/Mobília, Áreas (bruta/útil m², quartos), Piso, Artigo matricial. Tabelas: «Contratos» (Número, Tipo, Lado, Outra parte, Vigência `inicio a fim_real||fim||'—'`, Renda, Estado; «Sem contratos.»), «Manutenção e apetrechamento» (N.º, Tipo, Descrição(50), Prevista, Quem paga, Custo (real senão estimado), Estado; «Sem ordens.»), «Inventário» (Bem, Qtd., Estado, Desde; «Inventário vazio.»), «Rentabilidade dos últimos 12 meses» (Rubrica Previsto/Executado: «Renda recebida», «Custos» (prev.custos/exec.custos), rodapé «Resultado»). Erro de pop-up: «O navegador bloqueou a janela de impressão.» print automático após 350 ms. Nota: esta ficha impressa não inclui o cartão do lugar (vagas) nem documentos.

## 5.11 Ligações (F)
- Contratos (C): contratos, unidadesDo, contaCorrente (em_divida, caucao_em_poder, facturado, recebido, posicao, linhas[periodo, vencimento, atraso, base, total_facturar, total_receber, pago, estado, doc_number, treasury_id]), TIPOS, POSICOES, ESTADOS, ESTADOS_RENDA, rotPeriodicidade, periodoDe, somarMeses.
- Obras/manutenção (O): obras, bensDaFraccao, fichas, necessidadesDeApetrechamento, produtos (artigos do stock — products), abrirApetrechamento, TIPOS_OBRA, QUEM_PAGA, ESTADOS_OBRA, ESTADOS_BEM. Planos (P): planos, estado, PERIODICIDADES.
- Rentabilidade (R.daFraccao). Compra/venda (T.negocios, TIPOS, FASES). Parque (K). Terceiros (I.terceiros).
- Navegação por hash: `#/imobiliario/contratos|obras|rentabilidade|negocios` + funções globais `imoArrAbrir`, `imoAbrirObra`, `imoEditarObra`, `imoBensFraccao`, `imoNegAbrir`, `imoAbrirDaOcupacao`, `renderImoOcupacao`.
- Permissões: NENHUMA chave de permissão em F (confirmado por leitura integral). Auditoria: nenhuma directa (mudarEstadoUnidade regista via D).

## 5.12 Dúvidas (F)
- `S()` (ImobiliarioSub — subarrendamento) declarado mas não usado; a margem do subarrendamento é calculada inline (F:170).
- docs com `x.numero` inexistente no esquema.
- Ficha impressa omite vagas/documentos.
- Separador «Resumo» chama `await I().unidades(...)` dentro do template (para «Acessório de»).

=====================================================================
# PARTE 6 — SÍNTESE
=====================================================================

## 6.1 Coleções / tabelas (resumo)
Próprias (4): imo_properties, imo_units, imo_amenities, imo_bookings.
Estendida (campo novo): imo_leases.park_veiculos (array) — P:187.
Documentos embebidos (arrays em imo_properties/imo_units): documentos[{tipo,nome,url,data}].
Lidas de outros módulos (6): fixed_assets (EDIFICIO), asset_rooms, asset_floors, third_parties, chart_of_accounts (AU:304-310), audit_logs (escrita P/A: {company_id, action, ref_id, detail, user, date, is_master_data}).
Funções de outros ficheiros do módulo consumidas (não lidas): ImobiliarioContratos, ImobiliarioReservasFin, ImobiliarioObras, ImobiliarioPlanos, ImobiliarioRentabilidade, ImobiliarioTransaccoes.
Numeração/códigos: imóvel «PR0001» (D:185); fracção `${codImóvel}-NN`; vaga gerada `${codImóvel}-${prefixo}${seq 3 díg}` e designação `${prefixo}-${seq}`; fracção por porta (101…); reserva: SEM numeração própria (apenas id) — nota: reservas não têm série; factura da reserva nasce em ReservasFin (doc_number). Não há séries/sequências persistidas: o próximo código deriva do máximo existente.

## 6.2 Fórmulas exactas (lista)
1. r2 / cent arredondamento a 2 casas (D:26; A:30).
2. nomePiso (D:60).
3. designacaoGerada 4 esquemas (D:78).
4. numero da fracção gerada (D:470).
5. codigo imóvel PR+4 dígitos (D:180).
6. codigo fracção `${cod}-NN` (D:294,423,469).
7. porteCabe (D:132).
8. taxaOcupacao imóveis = round(ocupadas/principais*100) (D:601).
9. areaBruta/rendaReferencia por imóvel (Σ das principais, D:586-587).
10. somaBruta (todas) vs area_bruta do imóvel (D:541).
11. Condomínio: d = principais − n_habitacoes (D:531).
12. pisosComFraccoes distintos (D:536).
13. contarPorPiso (D:494).
14. Taxa do parque = round(ocupadas/arrendaveis*100) (P:147).
15. numeração de vagas seq=abs(piso)*100+k; designação/código (P:300-306).
16. normalizarMatricula (P:40).
17. ocupacaoDaVaga PROPRIO/FRACCAO (P:68).
18. hm/hhmm/horasEntre=round(min/6)/10 (A:91-99).
19. diasEntre (A:101).
20. calcularValor = preco × max(1, ceil(horas/horasPorUnidade)); FIXO → 1 unidade (A:230).
21. conflito de horário (A:365).
22. Limite mensal por fracção (A:326-342).
23. Dívida > 0.009 exige autorização (A:348).
24. Livre/ocupado do dia (diaDe, cursor) (A:500).
25. Estado inicial da reserva (A:426); TRANSICOES (A:434).
26. Margem mensal do subarrendamento = renda cobrada − renda paga (F:170).
27. Desvio da rentabilidade = round((exec−prev)*100)/100 (F:439).
28. Janela 12 meses (F:434-435).
29. Devolve-se na caução = max(0, recebida − reter) (AU:706).
30. Cancelamento de reserva paga: explicação/devolve/retem em ReservasFin.previsaoCancelamento (NÃO lido).

## 6.3 Ecrãs / separadores / modais (contagem e lista)
Ecrãs principais (3): «Parqueamento» (renderImoParque), «Áreas comuns» (renderImoAreas), «Ficha da fracção» (modal imoFichaFraccao).
Separadores (9): Áreas — «Calendário», «Espaços»; Ficha — «Resumo», «Contratos», «Rendas», «Manutenção», «Inventário», «Rentabilidade», «Negócios».
Modais (11): Gerar vagas; Vaga; Afectar vagas a uma fracção; Veículos do contrato; Novo/Editar espaço comum; Marcar/Editar reserva; Detalhe da reserva; Receber da reserva; Vistoria; Fechar a caução; Ficha da fracção.
Impressões (3): Mapa de parqueamento; Reservas de áreas comuns; Ficha da fracção.
(Os ecrãs de CADASTRO de imóveis/fracções e mapa de ocupação NÃO estão nestes 6 ficheiros: D só tem regras; a UI correspondente — imobiliario_ui.js — NÃO foi lida; registar como lacuna.)
Rotas/ids de modais: imo-pkgerar-modal, imo-pkvaga-modal, imo-pkafect-modal, imo-pkveic-modal, imo-area-modal, imo-reserva-modal, imo-resdet-modal, imo-resrec-modal, imo-resvis-modal, imo-rescau-modal, imo-fraccao-modal.

## 6.4 Permissões
Nenhuma chave de permissão referenciada em NENHUM dos 6 ficheiros (D, P, PU, A, AU, F) — verificado por leitura integral. As permissões (se existirem) estão no router/menu (NÃO lido).

## 6.5 Notificações / auditoria / impressão
- Notificações: `window.Notificacoes.mostrar({tipo:'sucesso'|'erro'|'aviso'|'info', texto})` com fallback alert.
- Diálogos: `Dialogo.confirmar`, `Dialogo.pedir`, `Dialogo.escolher`.
- Auditoria: D usa `window.logMovement('Gestão Imobiliária', accao, id, detalhe)`; P e A gravam directamente em `audit_logs` (action/detail/user/date) — dois mecanismos distintos. Acções registadas: Criar/Editar/Eliminar imóvel; Criar/Editar/Eliminar fracção; Mudar estado da fracção; Importar fracções do edifício; Gerar fracções por piso; Veículos do contrato; Afectar vagas; Libertar vagas; Afectação da vaga; Gerar vagas; Criar/Editar/Eliminar área comum; Criar/Editar/Eliminar reserva; Estado da reserva.
- Impressão: janelas HTML com window.print() após 350 ms; PDF/Excel/exportações: NENHUM nestes ficheiros.
- Sem alertas por email/notificações push: apenas avisos no ecrã.

## 6.6 Lacunas de leitura declaradas
- Não lidos (fora do âmbito): imobiliario_ui.js (cadastro/ocupação), contratos, reservas_fin, obras, planos, rentabilidade, transaccoes, subarrendamento, fiscal, templates; logo: esquema Dexie das tabelas imo_*, UI de cadastro de imóvel/fracção (campos do formulário do imóvel, rótulos de isencao_detencao), mapa de ocupação, permissões, rota/menu.
- `isencao_detencao`: valores NAO/METADE/TOTAL sem rótulo nos ficheiros lidos.

## 6.7 Achados de código (a decidir no porte)
1. Editar vaga pela UI recalcula `codigo` (D:294 com dados.codigo ausente), repõe `ordem`, apaga `bloco`/`numero`/`documentos`/`quartos` etc. (PU:262-273).
2. Veículo perde `cor` e `distico` ao gravar pelo ecrã (PU:426-433 → P:180-182).
3. Reserva paga com exige_aprovacao: aprovar → APROVADA em vez de AGUARDA_PAGAMENTO (A:426,477).
4. Verificação em directo da reserva ignora o id em edição (AU:441).
5. Taxa de ocupação: denominador = todas as principais (não só «em condições»), apesar do comentário (D:599-601).
6. Mensagem «arrendáveis sem renda de referência» difere entre D (só autónomas) e P (todas arrendáveis).
7. Fichas: `documentos[].numero` inexistente (F:220).
8. `gerarFraccoesPorPiso` limite 500 ignora blocos (D:452).
9. A.conflitos nunca traz `reservante` (A:298).
10. `afectar` reatribui silenciosamente vaga já afecta a outra fracção (P:216-228).

## 6.8 Funcionalidades atómicas testáveis (rascunho IMO-A-001...)
### Cadastro de imóvel (dados)
- IMO-A-001 Criar imóvel só com nome: código automático PR0001 (D:185,229); papel PROPRIO, tipo PREDIO, estado ACTIVO por omissão.
- IMO-A-002 Código seguinte = máximo numérico+1 com 4 dígitos (PR0002…), ignorando códigos fora do padrão (D:180-183).
- IMO-A-003 Recusar imóvel sem nome: «Dê uma designação ao imóvel.»
- IMO-A-004 Recusar papel ARRENDADO/MEDIACAO sem proprietário com a mensagem parametrizada pelo rótulo em minúsculas.
- IMO-A-005 Papel PROPRIO permite ligar building_id; outros papéis forçam building_id=null.
- IMO-A-006 Recusar área útil > área bruta (ambas >0) no imóvel e na fracção.
- IMO-A-007 Recusar código de imóvel duplicado (case-insensitive) e matriz predial duplicada.
- IMO-A-008 Condomínio exige n_habitacoes>0; outros tipos zeram n_habitacoes; tipos sem pisos zeram n_pisos/piso_inicial.
- IMO-A-009 Valores monetários/áreas arredondados a 2 casas; valor_patrimonial/valor_aquisicao nulos quando vazios.
- IMO-A-010 isencao_detencao aceita NAO/METADE/TOTAL, outro valor → NAO.
- IMO-A-011 Documentos: URL normalizada (https:// por omissão), recusar não-http(s) e URL inválido com mensagens exactas; entradas sem URL descartadas; tipo desconhecido → OUTRO.
- IMO-A-012 Editar imóvel actualiza actualizado_em/por e regista «Editar imóvel».
- IMO-A-013 Eliminar imóvel recusado se tem fracções.
- IMO-A-014 Listar imóveis ordenados por código natural (pt, numeric); isolamento por empresa e exclusão de is_master_data.
### Fracções
- IMO-A-015 Criar fracção: designação obrigatória; código `${imóvel}-NN`; ordem automática; estado DISPONIVEL; estado_desde=hoje.
- IMO-A-016 Recusar código e designação duplicados no mesmo imóvel (case-insensitive).
- IMO-A-017 Natureza HABITACIONAL/ESTACIONAMENTO/ARRECADACAO; campos park_* só gravados se vaga.
- IMO-A-018 Vaga PERMANENTE exige fracção-mãe do mesmo imóvel, não acessória, não ela própria (mensagens exactas).
- IMO-A-019 Vaga com afectação desconhecida → AUTONOMA; tipo → DESCOBERTO; porte → LIGEIRO.
- IMO-A-020 Alterar estado ao editar actualiza estado_desde.
- IMO-A-021 Recusar mudar fracção com vagas afectas para acessório.
- IMO-A-022 Eliminar fracção recusado se ARRENDADA/VENDIDA ou com vagas afectas.
- IMO-A-023 Mudar estado da fracção (qualquer→qualquer) grava estado_desde, estado_motivo e regista log com rótulos «antes → depois — motivo».
- IMO-A-024 Criar fracção «Única» para imóvel sem fracções (TERRENO → tipologia TERRENO; áreas do imóvel); recusar se já tem.
- IMO-A-025 Importar portas do edifício do imobilizado (apenas novas; designação por nome/«andar · Porta n»; notas «Tipo na planta»); recusar se imóvel sem building_id.
- IMO-A-026 Listar edifícios disponíveis (EDIFICIO, não mestre) com indicação jaLigado.
- IMO-A-027 Gerar fracções por piso — esquema PORTA (101, 201, R/C 01, caves C101).
- IMO-A-028 Gerar — esquema LETRA, NUMERO, ESQ_DTO (Esq./Dto./Frt./Tras.).
- IMO-A-029 Gerar com blocos (prefixo «Bloco · »), piso inicial negativo, não duplicar designações existentes, devolver {criadas, repetidas}; limite 500.
- IMO-A-030 Código das fracções geradas sequencial a partir de jaTem.length+1.
- IMO-A-031 Contar fracções por piso (total, ocupadas, área) ordenado por piso.
### Avisos e resumos
- IMO-A-032 Aviso «Sem fracções».
- IMO-A-033 Aviso de condomínio: faltam/sobram fracções vs habitações declaradas.
- IMO-A-034 Avisos de pisos (mais pisos do que declarados; fracções sem piso; sem n_pisos).
- IMO-A-035 Aviso soma de áreas > área do imóvel (inclui vagas).
- IMO-A-036 Aviso fracções sem área.
- IMO-A-037 Avisos de vagas: órfãs, cave sem altura, autónomas sem renda.
- IMO-A-038 Avisos do imóvel: sem matriz, sem valor patrimonial (PROPRIO), sem edifício ligado (PROPRIO).
- IMO-A-039 Resumo por imóvel (total, ocupadas, livres, vagas, acessórios, área, renda de referência).
- IMO-A-040 Resumo global: taxa de ocupação só de fracções principais (ARRENDADA+VENDIDA)/total; vagas e arrecadações à parte; porEstado; porPapel.
- IMO-A-041 Terceiros elegíveis a proprietário: company_id numérico ou texto.
### Parqueamento
- IMO-A-042 Gerar vagas por piso(s): numeração G-101/G-201, R/C G-01, código `${imóvel}-G101`; tipo por omissão SUBTERRANEO se todos os pisos <0; recusar PERMANENTE; limite 500; saltar repetidas.
- IMO-A-043 Gerar vagas com altura/comprimento/largura/tomada/renda/área e natureza ESTACIONAMENTO, tipologia GARAGEM, finalidade ESTACIONAMENTO.
- IMO-A-044 Criar/editar vaga avulsa (modal) com os campos próprios.
- IMO-A-045 Afectar vagas a fracção: recusar vaga de outro imóvel, não-vaga, com contrato próprio activo, mãe acessória; guardar sugestão incluída/acrescida/aberta.
- IMO-A-046 Libertar vagas → AUTONOMA, avisar se estavam a ser usadas por contrato da fracção.
- IMO-A-047 Mudar afectação para VISITA/CARGA_DESCARGA: recusar se ocupada; estado passa a DISPONIVEL.
- IMO-A-048 Ocupação da vaga: via contrato próprio ACTIVO ou via fracção-mãe (PERMANENTE).
- IMO-A-049 Mapa do parque agrupado por piso (descendente), ordenação natural, filtro por texto (lugar, código, dístico, fracção, ocupante, contrato, matrícula).
- IMO-A-050 Conflito de porte (veículo maior que a vaga) assinalado.
- IMO-A-051 Resumo do parque: arrendáveis, ocupadas, livres, taxa, afectas, autónomas, visita, tomada, conflitos, por contrato próprio/fracção.
- IMO-A-052 Gravar veículos do contrato: matrícula obrigatória, normalizada (LD-12-34-AB com 8 caracteres), vaga pertence ao contrato (inclui vagas afectas), duplicados recusados, substitui lista.
- IMO-A-053 Matrícula repetida noutro contrato activo do imóvel → confirmação.
- IMO-A-054 Lugares livres (arrendáveis, sem ocupação, não em obra/indisponíveis).
- IMO-A-055 Encargos propostos ao contrato por vaga afecta (valor, incluído, detalhe).
- IMO-A-056 Avisos do parque (vaga prometida duas vezes, sem preço, porte, sem altura).
- IMO-A-057 Imprimir mapa de parqueamento A4.
- IMO-A-058 KPIs do ecrã Parqueamento (Lugares, Livres, Ocupados, Visitas e cargas, Taxa do parque).
- IMO-A-059 Cartão da vaga com cores por estado (EM_OBRA/INDISPONIVEL, uso comum, ocupada, livre) e matrículas.
### Áreas comuns
- IMO-A-060 Criar espaço: nome e imóvel obrigatórios; regime por omissão RESERVA_GRATUITA; tipo desconhecido → OUTRO.
- IMO-A-061 Horário por omissão 08:00–22:00 e todos os dias; recusar fecho ≤ abertura (se reservável).
- IMO-A-062 Recusar duração min > max e antecedência min > max.
- IMO-A-063 Preço e caução só gravados em RESERVA_PAGA; recusar preço ≤0 (excepto FIXO).
- IMO-A-064 conta_proveito validada por Seletores.validarConta e nunca por omissão.
- IMO-A-065 prazo_pagamento_horas mínimo 1 (omissão 48); cancel_dias ≥0; cancel_pct 0–100.
- IMO-A-066 Activar/desactivar espaço; eliminar recusado com reservas (vivas ou históricas).
- IMO-A-067 Listagem de espaços (filtros imóvel/activo/reserváveis) e colunas do separador «Espaços».
- IMO-A-068 Cálculo do valor: preço × unidades (HORA, PERIODO 4h, DIA 24h, FIXO) com detalhe textual.
- IMO-A-069 Elegibilidade: espaço inactivo / uso livre / sem data.
- IMO-A-070 Elegibilidade: dia de funcionamento, horário, duração min/max.
- IMO-A-071 Elegibilidade: data passada, antecedência mínima e máxima.
- IMO-A-072 Elegibilidade: conflito de horário (sobreposição semi-aberta) com reservas que ocupam.
- IMO-A-073 Reserva interna (sem fracção): aviso e dispensa verificação de contrato/limite/dívida.
- IMO-A-074 Elegibilidade: fracção de outro imóvel; sem contrato SENHORIO activo cobrindo a data (mensagens distintas).
- IMO-A-075 Limite mensal por fracção (conta ocupa + FALTOU; mensagens para 1 e >1; aviso de esgotar).
- IMO-A-076 Dívida (>0.009) exige autorização com motivo; grava autorizacao_por/em e divida_na_marcacao.
- IMO-A-077 Capacidade: recusar pessoas > capacidade.
- IMO-A-078 Estado inicial da reserva: PEDIDA / AGUARDA_PAGAMENTO / APROVADA.
- IMO-A-079 Editar reserva só se viva.
- IMO-A-080 Máquina de estados (TRANSICOES) e mensagem de transição inválida.
- IMO-A-081 Recusar/cancelar exigem motivo; REALIZADA/FALTOU recusados para datas futuras.
- IMO-A-082 Aprovar/confirmar revalida conflito (ignora PEDIDAs).
- IMO-A-083 Eliminar reserva só se viva e sem sale_id/receipt_id.
- IMO-A-084 Disponibilidade do dia: ocupadas, livres, horas livres, vazio, abre.
- IMO-A-085 Calendário agrupado por dia com reservante (Administração / terceiro / fracção).
- IMO-A-086 Resumo e avisos de reservas (por aprovar, por fechar, a aguardar pagamento, com dívida, espaços de festa sem limite).
- IMO-A-087 KPIs do ecrã Áreas comuns e aviso de reservas caducadas ao abrir.
- IMO-A-088 Marcar espaço (modal) com painel do dia, links de horas livres, verificação em directo, caixa de impedimentos, valor e caução.
- IMO-A-089 Detalhe da reserva com botões por estado (Aprovar, Recusar, Realizada, Não compareceu, Cancelar, Alterar, Apagar).
- IMO-A-090 Facturar reserva paga (não interna, valor>0, estado≠PEDIDA).
- IMO-A-091 Receber (utilização + caução, meio, conta de tesouraria) → CONFIRMADA quando pago.
- IMO-A-092 Vistoria de entrada/saída (estado BOM/SUJO/DANIFICADO/EM_FALTA, observações, URL).
- IMO-A-093 Fechar caução: reter só com vistoria de saída, devolver o resto, facturar retido, abrir ordem de manutenção.
- IMO-A-094 Cancelar reserva paga com previsão de reembolso, motivo obrigatório e nota de crédito em Vendas.
- IMO-A-095 Imprimir reservas por período (A4).
- IMO-A-096 Painel «Dinheiro» da reserva (utilização, factura, recebido, por pagar, caução, retido, devolvido, reembolso, prazo).
### Ficha da fracção
- IMO-A-097 Abrir ficha por unitId (mensagem «Fracção não encontrada.»); cabeçalho com estado, imóvel, município, papel, «desde».
- IMO-A-098 Resumo: cartões «A fracção», «Situação», «De relance».
- IMO-A-099 Situação: contrato SENHORIO vivo (inquilino, renda, vigência, dívida, caução) e contrato INQUILINO (renda paga) com margem mensal.
- IMO-A-100 Resumo de vaga («O lugar») e vagas que vão junto, com sugestão de preço.
- IMO-A-101 Documentos da fracção listados com link.
- IMO-A-102 Separador Contratos (inclui contratos de âmbito alargado; caso vaga sem contrato próprio).
- IMO-A-103 Separador Rendas (últimas 12 linhas por contrato; senhorio vs inquilino).
- IMO-A-104 Separador Manutenção (ordens + planos; atraso; a debitar).
- IMO-A-105 Separador Inventário (bens + comparação contra ficha-tipo + abrir ordem de apetrechamento).
- IMO-A-106 Separador Rentabilidade (12 meses, previsto/executado/desvio, rendimento anual).
- IMO-A-107 Separador Negócios (negócios da fracção ou do imóvel inteiro).
- IMO-A-108 Mudar estado a partir da ficha (motivo pedido para INDISPONIVEL/EM_OBRA).
- IMO-A-109 Imprimir ficha da fracção A4.
- IMO-A-110 Navegação por hash entre ficha e contratos/obras/negócios/rentabilidade.
- IMO-A-111 Auditoria das operações do módulo (lista de acções registadas).
- IMO-A-112 Isolamento por empresa e exclusão de dados mestre em todas as leituras.

(fim do inventário)
