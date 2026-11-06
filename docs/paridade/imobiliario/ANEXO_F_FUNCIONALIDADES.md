# Anexo F — Lista numerada de funcionalidades atómicas (IMO-F-001…)

> Anexo de `IMOBILIARIO_INVENTARIO.md`. Renumeração contínua das funcionalidades testáveis dos anexos A–D; a coluna «origem» mantém o código do leitor (IMO-A/B/C/D-nnn) para voltar à evidência `ficheiro:linha`. Cada linha é um caso de teste candidato (PHPUnit com números à mão, ou vitest). Fase = fase do plano (`IMOBILIARIO_PLANO.md`) em que passa a ser exigida.

| IMO-F | origem | sub-módulo | funcionalidade (e evidência) |
|---|---|---|---|
| IMO-F-001 | IMO-A-001 | Cadastro/parque/áreas/ficha | Criar imóvel só com nome: código automático PR0001 (D:185,229); papel PROPRIO, tipo PREDIO, estado ACTIVO por omissão. |
| IMO-F-002 | IMO-A-002 | Cadastro/parque/áreas/ficha | Código seguinte = máximo numérico+1 com 4 dígitos (PR0002…), ignorando códigos fora do padrão (D:180-183). |
| IMO-F-003 | IMO-A-003 | Cadastro/parque/áreas/ficha | Recusar imóvel sem nome: «Dê uma designação ao imóvel.» |
| IMO-F-004 | IMO-A-004 | Cadastro/parque/áreas/ficha | Recusar papel ARRENDADO/MEDIACAO sem proprietário com a mensagem parametrizada pelo rótulo em minúsculas. |
| IMO-F-005 | IMO-A-005 | Cadastro/parque/áreas/ficha | Papel PROPRIO permite ligar building_id; outros papéis forçam building_id=null. |
| IMO-F-006 | IMO-A-006 | Cadastro/parque/áreas/ficha | Recusar área útil > área bruta (ambas >0) no imóvel e na fracção. |
| IMO-F-007 | IMO-A-007 | Cadastro/parque/áreas/ficha | Recusar código de imóvel duplicado (case-insensitive) e matriz predial duplicada. |
| IMO-F-008 | IMO-A-008 | Cadastro/parque/áreas/ficha | Condomínio exige n_habitacoes>0; outros tipos zeram n_habitacoes; tipos sem pisos zeram n_pisos/piso_inicial. |
| IMO-F-009 | IMO-A-009 | Cadastro/parque/áreas/ficha | Valores monetários/áreas arredondados a 2 casas; valor_patrimonial/valor_aquisicao nulos quando vazios. |
| IMO-F-010 | IMO-A-010 | Cadastro/parque/áreas/ficha | isencao_detencao aceita NAO/METADE/TOTAL, outro valor → NAO. |
| IMO-F-011 | IMO-A-011 | Cadastro/parque/áreas/ficha | Documentos: URL normalizada (https:// por omissão), recusar não-http(s) e URL inválido com mensagens exactas; entradas sem URL descartadas; tipo desconhecido → OUTRO. |
| IMO-F-012 | IMO-A-012 | Cadastro/parque/áreas/ficha | Editar imóvel actualiza actualizado_em/por e regista «Editar imóvel». |
| IMO-F-013 | IMO-A-013 | Cadastro/parque/áreas/ficha | Eliminar imóvel recusado se tem fracções. |
| IMO-F-014 | IMO-A-014 | Cadastro/parque/áreas/ficha | Listar imóveis ordenados por código natural (pt, numeric); isolamento por empresa e exclusão de is_master_data. |
| IMO-F-015 | IMO-A-015 | Cadastro/parque/áreas/ficha | Criar fracção: designação obrigatória; código `${imóvel}-NN`; ordem automática; estado DISPONIVEL; estado_desde=hoje. |
| IMO-F-016 | IMO-A-016 | Cadastro/parque/áreas/ficha | Recusar código e designação duplicados no mesmo imóvel (case-insensitive). |
| IMO-F-017 | IMO-A-017 | Cadastro/parque/áreas/ficha | Natureza HABITACIONAL/ESTACIONAMENTO/ARRECADACAO; campos park_* só gravados se vaga. |
| IMO-F-018 | IMO-A-018 | Cadastro/parque/áreas/ficha | Vaga PERMANENTE exige fracção-mãe do mesmo imóvel, não acessória, não ela própria (mensagens exactas). |
| IMO-F-019 | IMO-A-019 | Cadastro/parque/áreas/ficha | Vaga com afectação desconhecida → AUTONOMA; tipo → DESCOBERTO; porte → LIGEIRO. |
| IMO-F-020 | IMO-A-020 | Cadastro/parque/áreas/ficha | Alterar estado ao editar actualiza estado_desde. |
| IMO-F-021 | IMO-A-021 | Cadastro/parque/áreas/ficha | Recusar mudar fracção com vagas afectas para acessório. |
| IMO-F-022 | IMO-A-022 | Cadastro/parque/áreas/ficha | Eliminar fracção recusado se ARRENDADA/VENDIDA ou com vagas afectas. |
| IMO-F-023 | IMO-A-023 | Cadastro/parque/áreas/ficha | Mudar estado da fracção (qualquer→qualquer) grava estado_desde, estado_motivo e regista log com rótulos «antes → depois — motivo». |
| IMO-F-024 | IMO-A-024 | Cadastro/parque/áreas/ficha | Criar fracção «Única» para imóvel sem fracções (TERRENO → tipologia TERRENO; áreas do imóvel); recusar se já tem. |
| IMO-F-025 | IMO-A-025 | Cadastro/parque/áreas/ficha | Importar portas do edifício do imobilizado (apenas novas; designação por nome/«andar · Porta n»; notas «Tipo na planta»); recusar se imóvel sem building_id. |
| IMO-F-026 | IMO-A-026 | Cadastro/parque/áreas/ficha | Listar edifícios disponíveis (EDIFICIO, não mestre) com indicação jaLigado. |
| IMO-F-027 | IMO-A-027 | Cadastro/parque/áreas/ficha | Gerar fracções por piso — esquema PORTA (101, 201, R/C 01, caves C101). |
| IMO-F-028 | IMO-A-028 | Cadastro/parque/áreas/ficha | Gerar — esquema LETRA, NUMERO, ESQ_DTO (Esq./Dto./Frt./Tras.). |
| IMO-F-029 | IMO-A-029 | Cadastro/parque/áreas/ficha | Gerar com blocos (prefixo «Bloco · »), piso inicial negativo, não duplicar designações existentes, devolver {criadas, repetidas}; limite 500. |
| IMO-F-030 | IMO-A-030 | Cadastro/parque/áreas/ficha | Código das fracções geradas sequencial a partir de jaTem.length+1. |
| IMO-F-031 | IMO-A-031 | Cadastro/parque/áreas/ficha | Contar fracções por piso (total, ocupadas, área) ordenado por piso. |
| IMO-F-032 | IMO-A-032 | Cadastro/parque/áreas/ficha | Aviso «Sem fracções». |
| IMO-F-033 | IMO-A-033 | Cadastro/parque/áreas/ficha | Aviso de condomínio: faltam/sobram fracções vs habitações declaradas. |
| IMO-F-034 | IMO-A-034 | Cadastro/parque/áreas/ficha | Avisos de pisos (mais pisos do que declarados; fracções sem piso; sem n_pisos). |
| IMO-F-035 | IMO-A-035 | Cadastro/parque/áreas/ficha | Aviso soma de áreas > área do imóvel (inclui vagas). |
| IMO-F-036 | IMO-A-036 | Cadastro/parque/áreas/ficha | Aviso fracções sem área. |
| IMO-F-037 | IMO-A-037 | Cadastro/parque/áreas/ficha | Avisos de vagas: órfãs, cave sem altura, autónomas sem renda. |
| IMO-F-038 | IMO-A-038 | Cadastro/parque/áreas/ficha | Avisos do imóvel: sem matriz, sem valor patrimonial (PROPRIO), sem edifício ligado (PROPRIO). |
| IMO-F-039 | IMO-A-039 | Cadastro/parque/áreas/ficha | Resumo por imóvel (total, ocupadas, livres, vagas, acessórios, área, renda de referência). |
| IMO-F-040 | IMO-A-040 | Cadastro/parque/áreas/ficha | Resumo global: taxa de ocupação só de fracções principais (ARRENDADA+VENDIDA)/total; vagas e arrecadações à parte; porEstado; porPapel. |
| IMO-F-041 | IMO-A-041 | Cadastro/parque/áreas/ficha | Terceiros elegíveis a proprietário: company_id numérico ou texto. |
| IMO-F-042 | IMO-A-042 | Cadastro/parque/áreas/ficha | Gerar vagas por piso(s): numeração G-101/G-201, R/C G-01, código `${imóvel}-G101`; tipo por omissão SUBTERRANEO se todos os pisos <0; recusar PERMANENTE; limite 500; saltar repetidas. |
| IMO-F-043 | IMO-A-043 | Cadastro/parque/áreas/ficha | Gerar vagas com altura/comprimento/largura/tomada/renda/área e natureza ESTACIONAMENTO, tipologia GARAGEM, finalidade ESTACIONAMENTO. |
| IMO-F-044 | IMO-A-044 | Cadastro/parque/áreas/ficha | Criar/editar vaga avulsa (modal) com os campos próprios. |
| IMO-F-045 | IMO-A-045 | Cadastro/parque/áreas/ficha | Afectar vagas a fracção: recusar vaga de outro imóvel, não-vaga, com contrato próprio activo, mãe acessória; guardar sugestão incluída/acrescida/aberta. |
| IMO-F-046 | IMO-A-046 | Cadastro/parque/áreas/ficha | Libertar vagas → AUTONOMA, avisar se estavam a ser usadas por contrato da fracção. |
| IMO-F-047 | IMO-A-047 | Cadastro/parque/áreas/ficha | Mudar afectação para VISITA/CARGA_DESCARGA: recusar se ocupada; estado passa a DISPONIVEL. |
| IMO-F-048 | IMO-A-048 | Cadastro/parque/áreas/ficha | Ocupação da vaga: via contrato próprio ACTIVO ou via fracção-mãe (PERMANENTE). |
| IMO-F-049 | IMO-A-049 | Cadastro/parque/áreas/ficha | Mapa do parque agrupado por piso (descendente), ordenação natural, filtro por texto (lugar, código, dístico, fracção, ocupante, contrato, matrícula). |
| IMO-F-050 | IMO-A-050 | Cadastro/parque/áreas/ficha | Conflito de porte (veículo maior que a vaga) assinalado. |
| IMO-F-051 | IMO-A-051 | Cadastro/parque/áreas/ficha | Resumo do parque: arrendáveis, ocupadas, livres, taxa, afectas, autónomas, visita, tomada, conflitos, por contrato próprio/fracção. |
| IMO-F-052 | IMO-A-052 | Cadastro/parque/áreas/ficha | Gravar veículos do contrato: matrícula obrigatória, normalizada (LD-12-34-AB com 8 caracteres), vaga pertence ao contrato (inclui vagas afectas), duplicados recusados, substitui lista. |
| IMO-F-053 | IMO-A-053 | Cadastro/parque/áreas/ficha | Matrícula repetida noutro contrato activo do imóvel → confirmação. |
| IMO-F-054 | IMO-A-054 | Cadastro/parque/áreas/ficha | Lugares livres (arrendáveis, sem ocupação, não em obra/indisponíveis). |
| IMO-F-055 | IMO-A-055 | Cadastro/parque/áreas/ficha | Encargos propostos ao contrato por vaga afecta (valor, incluído, detalhe). |
| IMO-F-056 | IMO-A-056 | Cadastro/parque/áreas/ficha | Avisos do parque (vaga prometida duas vezes, sem preço, porte, sem altura). |
| IMO-F-057 | IMO-A-057 | Cadastro/parque/áreas/ficha | Imprimir mapa de parqueamento A4. |
| IMO-F-058 | IMO-A-058 | Cadastro/parque/áreas/ficha | KPIs do ecrã Parqueamento (Lugares, Livres, Ocupados, Visitas e cargas, Taxa do parque). |
| IMO-F-059 | IMO-A-059 | Cadastro/parque/áreas/ficha | Cartão da vaga com cores por estado (EM_OBRA/INDISPONIVEL, uso comum, ocupada, livre) e matrículas. |
| IMO-F-060 | IMO-A-060 | Cadastro/parque/áreas/ficha | Criar espaço: nome e imóvel obrigatórios; regime por omissão RESERVA_GRATUITA; tipo desconhecido → OUTRO. |
| IMO-F-061 | IMO-A-061 | Cadastro/parque/áreas/ficha | Horário por omissão 08:00–22:00 e todos os dias; recusar fecho ≤ abertura (se reservável). |
| IMO-F-062 | IMO-A-062 | Cadastro/parque/áreas/ficha | Recusar duração min > max e antecedência min > max. |
| IMO-F-063 | IMO-A-063 | Cadastro/parque/áreas/ficha | Preço e caução só gravados em RESERVA_PAGA; recusar preço ≤0 (excepto FIXO). |
| IMO-F-064 | IMO-A-064 | Cadastro/parque/áreas/ficha | conta_proveito validada por Seletores.validarConta e nunca por omissão. |
| IMO-F-065 | IMO-A-065 | Cadastro/parque/áreas/ficha | prazo_pagamento_horas mínimo 1 (omissão 48); cancel_dias ≥0; cancel_pct 0–100. |
| IMO-F-066 | IMO-A-066 | Cadastro/parque/áreas/ficha | Activar/desactivar espaço; eliminar recusado com reservas (vivas ou históricas). |
| IMO-F-067 | IMO-A-067 | Cadastro/parque/áreas/ficha | Listagem de espaços (filtros imóvel/activo/reserváveis) e colunas do separador «Espaços». |
| IMO-F-068 | IMO-A-068 | Cadastro/parque/áreas/ficha | Cálculo do valor: preço × unidades (HORA, PERIODO 4h, DIA 24h, FIXO) com detalhe textual. |
| IMO-F-069 | IMO-A-069 | Cadastro/parque/áreas/ficha | Elegibilidade: espaço inactivo / uso livre / sem data. |
| IMO-F-070 | IMO-A-070 | Cadastro/parque/áreas/ficha | Elegibilidade: dia de funcionamento, horário, duração min/max. |
| IMO-F-071 | IMO-A-071 | Cadastro/parque/áreas/ficha | Elegibilidade: data passada, antecedência mínima e máxima. |
| IMO-F-072 | IMO-A-072 | Cadastro/parque/áreas/ficha | Elegibilidade: conflito de horário (sobreposição semi-aberta) com reservas que ocupam. |
| IMO-F-073 | IMO-A-073 | Cadastro/parque/áreas/ficha | Reserva interna (sem fracção): aviso e dispensa verificação de contrato/limite/dívida. |
| IMO-F-074 | IMO-A-074 | Cadastro/parque/áreas/ficha | Elegibilidade: fracção de outro imóvel; sem contrato SENHORIO activo cobrindo a data (mensagens distintas). |
| IMO-F-075 | IMO-A-075 | Cadastro/parque/áreas/ficha | Limite mensal por fracção (conta ocupa + FALTOU; mensagens para 1 e >1; aviso de esgotar). |
| IMO-F-076 | IMO-A-076 | Cadastro/parque/áreas/ficha | Dívida (>0.009) exige autorização com motivo; grava autorizacao_por/em e divida_na_marcacao. |
| IMO-F-077 | IMO-A-077 | Cadastro/parque/áreas/ficha | Capacidade: recusar pessoas > capacidade. |
| IMO-F-078 | IMO-A-078 | Cadastro/parque/áreas/ficha | Estado inicial da reserva: PEDIDA / AGUARDA_PAGAMENTO / APROVADA. |
| IMO-F-079 | IMO-A-079 | Cadastro/parque/áreas/ficha | Editar reserva só se viva. |
| IMO-F-080 | IMO-A-080 | Cadastro/parque/áreas/ficha | Máquina de estados (TRANSICOES) e mensagem de transição inválida. |
| IMO-F-081 | IMO-A-081 | Cadastro/parque/áreas/ficha | Recusar/cancelar exigem motivo; REALIZADA/FALTOU recusados para datas futuras. |
| IMO-F-082 | IMO-A-082 | Cadastro/parque/áreas/ficha | Aprovar/confirmar revalida conflito (ignora PEDIDAs). |
| IMO-F-083 | IMO-A-083 | Cadastro/parque/áreas/ficha | Eliminar reserva só se viva e sem sale_id/receipt_id. |
| IMO-F-084 | IMO-A-084 | Cadastro/parque/áreas/ficha | Disponibilidade do dia: ocupadas, livres, horas livres, vazio, abre. |
| IMO-F-085 | IMO-A-085 | Cadastro/parque/áreas/ficha | Calendário agrupado por dia com reservante (Administração / terceiro / fracção). |
| IMO-F-086 | IMO-A-086 | Cadastro/parque/áreas/ficha | Resumo e avisos de reservas (por aprovar, por fechar, a aguardar pagamento, com dívida, espaços de festa sem limite). |
| IMO-F-087 | IMO-A-087 | Cadastro/parque/áreas/ficha | KPIs do ecrã Áreas comuns e aviso de reservas caducadas ao abrir. |
| IMO-F-088 | IMO-A-088 | Cadastro/parque/áreas/ficha | Marcar espaço (modal) com painel do dia, links de horas livres, verificação em directo, caixa de impedimentos, valor e caução. |
| IMO-F-089 | IMO-A-089 | Cadastro/parque/áreas/ficha | Detalhe da reserva com botões por estado (Aprovar, Recusar, Realizada, Não compareceu, Cancelar, Alterar, Apagar). |
| IMO-F-090 | IMO-A-090 | Cadastro/parque/áreas/ficha | Facturar reserva paga (não interna, valor>0, estado≠PEDIDA). |
| IMO-F-091 | IMO-A-091 | Cadastro/parque/áreas/ficha | Receber (utilização + caução, meio, conta de tesouraria) → CONFIRMADA quando pago. |
| IMO-F-092 | IMO-A-092 | Cadastro/parque/áreas/ficha | Vistoria de entrada/saída (estado BOM/SUJO/DANIFICADO/EM_FALTA, observações, URL). |
| IMO-F-093 | IMO-A-093 | Cadastro/parque/áreas/ficha | Fechar caução: reter só com vistoria de saída, devolver o resto, facturar retido, abrir ordem de manutenção. |
| IMO-F-094 | IMO-A-094 | Cadastro/parque/áreas/ficha | Cancelar reserva paga com previsão de reembolso, motivo obrigatório e nota de crédito em Vendas. |
| IMO-F-095 | IMO-A-095 | Cadastro/parque/áreas/ficha | Imprimir reservas por período (A4). |
| IMO-F-096 | IMO-A-096 | Cadastro/parque/áreas/ficha | Painel «Dinheiro» da reserva (utilização, factura, recebido, por pagar, caução, retido, devolvido, reembolso, prazo). |
| IMO-F-097 | IMO-A-097 | Cadastro/parque/áreas/ficha | Abrir ficha por unitId (mensagem «Fracção não encontrada.»); cabeçalho com estado, imóvel, município, papel, «desde». |
| IMO-F-098 | IMO-A-098 | Cadastro/parque/áreas/ficha | Resumo: cartões «A fracção», «Situação», «De relance». |
| IMO-F-099 | IMO-A-099 | Cadastro/parque/áreas/ficha | Situação: contrato SENHORIO vivo (inquilino, renda, vigência, dívida, caução) e contrato INQUILINO (renda paga) com margem mensal. |
| IMO-F-100 | IMO-A-100 | Cadastro/parque/áreas/ficha | Resumo de vaga («O lugar») e vagas que vão junto, com sugestão de preço. |
| IMO-F-101 | IMO-A-101 | Cadastro/parque/áreas/ficha | Documentos da fracção listados com link. |
| IMO-F-102 | IMO-A-102 | Cadastro/parque/áreas/ficha | Separador Contratos (inclui contratos de âmbito alargado; caso vaga sem contrato próprio). |
| IMO-F-103 | IMO-A-103 | Cadastro/parque/áreas/ficha | Separador Rendas (últimas 12 linhas por contrato; senhorio vs inquilino). |
| IMO-F-104 | IMO-A-104 | Cadastro/parque/áreas/ficha | Separador Manutenção (ordens + planos; atraso; a debitar). |
| IMO-F-105 | IMO-A-105 | Cadastro/parque/áreas/ficha | Separador Inventário (bens + comparação contra ficha-tipo + abrir ordem de apetrechamento). |
| IMO-F-106 | IMO-A-106 | Cadastro/parque/áreas/ficha | Separador Rentabilidade (12 meses, previsto/executado/desvio, rendimento anual). |
| IMO-F-107 | IMO-A-107 | Cadastro/parque/áreas/ficha | Separador Negócios (negócios da fracção ou do imóvel inteiro). |
| IMO-F-108 | IMO-A-108 | Cadastro/parque/áreas/ficha | Mudar estado a partir da ficha (motivo pedido para INDISPONIVEL/EM_OBRA). |
| IMO-F-109 | IMO-A-109 | Cadastro/parque/áreas/ficha | Imprimir ficha da fracção A4. |
| IMO-F-110 | IMO-A-110 | Cadastro/parque/áreas/ficha | Navegação por hash entre ficha e contratos/obras/negócios/rentabilidade. |
| IMO-F-111 | IMO-A-111 | Cadastro/parque/áreas/ficha | Auditoria das operações do módulo (lista de acções registadas). |
| IMO-F-112 | IMO-A-112 | Cadastro/parque/áreas/ficha | Isolamento por empresa e exclusão de dados mestre em todas as leituras. |
| IMO-F-113 | IMO-B-001 | Contratos/rendas/modelos/subarrendamento | Criar contrato ARRENDAMENTO de uma fracção livre com terceiro, início, renda > 0: grava em RASCUNHO com `numero` `AR AAAA/0001` (CT:496, 257-269). |
| IMO-F-114 | IMO-B-002 | Contratos/rendas/modelos/subarrendamento | Numeração sequencial por prefixo e ano: segundo ARRENDAMENTO = `AR AAAA/0002`; SUBARRENDAMENTO `SB`, TOMADA `AT` com séries independentes. |
| IMO-F-115 | IMO-B-003 | Contratos/rendas/modelos/subarrendamento | `posicao` derivada do tipo (TOMADA=INQUILINO; restantes=SENHORIO). |
| IMO-F-116 | IMO-B-004 | Contratos/rendas/modelos/subarrendamento | Âmbito FRACCAO exige fracção («Escolha a fracção...»). |
| IMO-F-117 | IMO-B-005 | Contratos/rendas/modelos/subarrendamento | Âmbito IMOVEL abrange todas as fracções arrendáveis do imóvel; exclui vagas VISITA e CARGA_DESCARGA; fracção principal = primeira. |
| IMO-F-118 | IMO-B-006 | Contratos/rendas/modelos/subarrendamento | Âmbito PARCIAL exige >= 2 fracções do mesmo imóvel (mensagens distintas para outro imóvel e para <2). |
| IMO-F-119 | IMO-B-007 | Contratos/rendas/modelos/subarrendamento | Imóvel sem fracções recusa âmbito IMOVEL/PARCIAL. |
| IMO-F-120 | IMO-B-008 | Contratos/rendas/modelos/subarrendamento | Terceiro obrigatório com mensagem diferente por lado. |
| IMO-F-121 | IMO-B-009 | Contratos/rendas/modelos/subarrendamento | Inquilino não pode ser o proprietário do imóvel (lado senhorio). |
| IMO-F-122 | IMO-B-010 | Contratos/rendas/modelos/subarrendamento | TOMADA exige imóvel com papel ARRENDADO. |
| IMO-F-123 | IMO-B-011 | Contratos/rendas/modelos/subarrendamento | TOMADA exige conta de custo; conta de retenção se retém IP; validação das 3 contas contra o plano (inexistente, totalizadora, plano vazio). |
| IMO-F-124 | IMO-B-012 | Contratos/rendas/modelos/subarrendamento | TOMADA: `permite_sub` 0/1 e `parent_lease_id=null`; não-TOMADA: contas '' e `permite_sub=0`. |
| IMO-F-125 | IMO-B-013 | Contratos/rendas/modelos/subarrendamento | SUBARRENDAMENTO exige imóvel ARRENDADO. |
| IMO-F-126 | IMO-B-014 | Contratos/rendas/modelos/subarrendamento | SUBARRENDAMENTO exige tomada (ACTIVO/RASCUNHO/SUSPENSO) sobre a fracção; escolhe automaticamente se só há uma; exige escolha se várias. |
| IMO-F-127 | IMO-B-015 | Contratos/rendas/modelos/subarrendamento | SUBARRENDAMENTO recusa mãe sem `permite_sub`. |
| IMO-F-128 | IMO-B-016 | Contratos/rendas/modelos/subarrendamento | SUBARRENDAMENTO recusa fracções não cobertas pela mãe. |
| IMO-F-129 | IMO-B-017 | Contratos/rendas/modelos/subarrendamento | SUBARRENDAMENTO recusa início anterior ao da mãe. |
| IMO-F-130 | IMO-B-018 | Contratos/rendas/modelos/subarrendamento | SUBARRENDAMENTO recusa fim posterior ao da mãe (usa fim_real se existir) ou sem termo se a mãe tem fim. |
| IMO-F-131 | IMO-B-019 | Contratos/rendas/modelos/subarrendamento | ARRENDAMENTO recusa imóvel com papel MEDIACAO. |
| IMO-F-132 | IMO-B-020 | Contratos/rendas/modelos/subarrendamento | Data de início obrigatória; fim > início. |
| IMO-F-133 | IMO-B-021 | Contratos/rendas/modelos/subarrendamento | Renda > 0, arredondada a 2 casas. |
| IMO-F-134 | IMO-B-022 | Contratos/rendas/modelos/subarrendamento | Normalizações: periodicidade (default MENSAL), dia 1..28, antecipada (default true), finalidade (default HABITACAO), isenção (NAO), retenção (true), selo (SENHORIO). |
| IMO-F-135 | IMO-B-023 | Contratos/rendas/modelos/subarrendamento | Caução <= 6 x renda; recebida <= acordada. |
| IMO-F-136 | IMO-B-024 | Contratos/rendas/modelos/subarrendamento | Actualização PERC em (0,100], VALOR/PERC exigem valor>0; meses default 12. |
| IMO-F-137 | IMO-B-025 | Contratos/rendas/modelos/subarrendamento | Renovação (NAO/AUTO), renovacao_meses>=1 (12), aviso_dias>=0. |
| IMO-F-138 | IMO-B-026 | Contratos/rendas/modelos/subarrendamento | Encargos: filtragem designacao não vazia e (valor>0 ou incluído); guarda origem e unit_id. |
| IMO-F-139 | IMO-B-027 | Contratos/rendas/modelos/subarrendamento | `doc_type` Factura / Factura-Recibo. |
| IMO-F-140 | IMO-B-028 | Contratos/rendas/modelos/subarrendamento | Conflito de datas e fracção entre contratos do mesmo lado em RASCUNHO/ACTIVO/SUSPENSO (mensagem com nomes e datas); tomada e subarrendamento da mesma fracção não conflituam. |
| IMO-F-141 | IMO-B-029 | Contratos/rendas/modelos/subarrendamento | Edição conserva número e estado. |
| IMO-F-142 | IMO-B-030 | Contratos/rendas/modelos/subarrendamento | Gravar com estado ACTIVO marca fracções ARRENDADA (só lado senhorio). |
| IMO-F-143 | IMO-B-031 | Contratos/rendas/modelos/subarrendamento | Activar RASCUNHO/SUSPENSO -> ACTIVO; recusa TERMINADO/DENUNCIADO. |
| IMO-F-144 | IMO-B-032 | Contratos/rendas/modelos/subarrendamento | Activar subarrendamento exige mãe existente e ACTIVA. |
| IMO-F-145 | IMO-B-033 | Contratos/rendas/modelos/subarrendamento | Activar recusa fracção já em contrato ACTIVO do mesmo lado e vaga PERMANENTE afecta a fracção arrendada. |
| IMO-F-146 | IMO-B-034 | Contratos/rendas/modelos/subarrendamento | Activar contrato lado senhorio marca fracções ARRENDADA; lado inquilino não altera estado das fracções. |
| IMO-F-147 | IMO-B-035 | Contratos/rendas/modelos/subarrendamento | Terminar exige data >= início e motivo; estado TERMINADO (termo) ou DENUNCIADO (denúncia). |
| IMO-F-148 | IMO-B-036 | Contratos/rendas/modelos/subarrendamento | Terminar anula rendas não documento com `de > data`, com nota «· anulada pela saída em AAAA-MM-DD»; mantém FACTURADA/PAGA/ANULADA. |
| IMO-F-149 | IMO-B-037 | Contratos/rendas/modelos/subarrendamento | Terminar grava fim_real, motivo_saida, caucao_devolvida/retida/motivo e liberta fracções (DISPONIVEL, só lado senhorio). |
| IMO-F-150 | IMO-B-038 | Contratos/rendas/modelos/subarrendamento | Terminar mãe com filhos vivos exige cascata; sem confirmação rejeita com lista de números. |
| IMO-F-151 | IMO-B-039 | Contratos/rendas/modelos/subarrendamento | Cascata termina cada filho com a mesma data, motivo «Caducidade: ...», caução devolvida = recebida, retida = 0; devolve contagens. |
| IMO-F-152 | IMO-B-040 | Contratos/rendas/modelos/subarrendamento | Eliminar: recusa ACTIVO/SUSPENSO, com filhos e com rendas em documento; apaga rendas e contrato. |
| IMO-F-153 | IMO-B-041 | Contratos/rendas/modelos/subarrendamento | UI Terminar: caução devolvida default = em poder; motivo de retenção obrigatório se retida>0; aviso de dívida; checkbox cascata só quando há filhos. |
| IMO-F-154 | IMO-B-042 | Contratos/rendas/modelos/subarrendamento | Períodos por periodicidade (limitesPeriodo) e fim de período correcto (meses de 28/30/31 dias). |
| IMO-F-155 | IMO-B-043 | Contratos/rendas/modelos/subarrendamento | Vencimento: antecipada = dia do 1.º mês; no fim do período = dia do último mês; limitado ao último dia do mês. |
| IMO-F-156 | IMO-B-044 | Contratos/rendas/modelos/subarrendamento | Geração de rendas: só ACTIVO; não duplica períodos existentes; respeita fim/fim_real; estado PREVISTA; copia posicao; calcula via `calcular`. |
| IMO-F-157 | IMO-B-045 | Contratos/rendas/modelos/subarrendamento | Fórmula base = renda + encargos a acrescer - desconto. |
| IMO-F-158 | IMO-B-046 | Contratos/rendas/modelos/subarrendamento | Imposto Predial = base x 60% x 25% (taxas do ano); isenção TOTAL=0; METADE=ip/2. |
| IMO-F-159 | IMO-B-047 | Contratos/rendas/modelos/subarrendamento | Selo = base x 0,1% (habitação) / 0,4% (outros), 0 se ISENTO; não entra no total a facturar. |
| IMO-F-160 | IMO-B-048 | Contratos/rendas/modelos/subarrendamento | IVA 0% arrendamento, 7% alojamento hoteleiro. |
| IMO-F-161 | IMO-B-049 | Contratos/rendas/modelos/subarrendamento | total_facturar = base + IVA; total_receber = total_facturar - IP se `retencao_ip`. |
| IMO-F-162 | IMO-B-050 | Contratos/rendas/modelos/subarrendamento | custo_senhorio = selo (se encargo senhorio) + IP (se não retém). |
| IMO-F-163 | IMO-B-051 | Contratos/rendas/modelos/subarrendamento | Actualização PERC composta por N ciclos completos desde `actualizacao_base` ou início; VALOR linear. |
| IMO-F-164 | IMO-B-052 | Contratos/rendas/modelos/subarrendamento | Actualização manual: grava renda, ultima_actualizacao, renda_anterior, actualizacao_base; recalcula rendas não facturadas a partir da data de efeito; abre carta ACTUALIZACAO. |
| IMO-F-165 | IMO-B-053 | Contratos/rendas/modelos/subarrendamento | Taxas por ano: gravar/ler (`imo_settings.taxas[ano]`), valores base por omissão, validação IP <= 100%. |
| IMO-F-166 | IMO-B-054 | Contratos/rendas/modelos/subarrendamento | `proporPeriodo` gera rendas de contratos ACTIVO e lista as do período (sem anuladas), por lado. |
| IMO-F-167 | IMO-B-055 | Contratos/rendas/modelos/subarrendamento | Aprovar PREVISTA -> APROVADA em lote (ignora estados outros). |
| IMO-F-168 | IMO-B-056 | Contratos/rendas/modelos/subarrendamento | Anular renda PREVISTA/APROVADA exige motivo; recusa FACTURADA («nota de crédito») e PAGA («anule-o na Tesouraria»). |
| IMO-F-169 | IMO-B-057 | Contratos/rendas/modelos/subarrendamento | Recalcular renda não-documento (taxas/renda/desconto). |
| IMO-F-170 | IMO-B-058 | Contratos/rendas/modelos/subarrendamento | Facturar exige lado senhorio, renda APROVADA (mensagens por estado). |
| IMO-F-171 | IMO-B-059 | Contratos/rendas/modelos/subarrendamento | Numeração AGT/local/fallback `FT\|FR AAAA/N`; libertar reserva em falha; selar após gravar. |
| IMO-F-172 | IMO-B-060 | Contratos/rendas/modelos/subarrendamento | Cabeçalho da venda (customer, due_date = vencimento, related_doc_id = número do contrato, status PENDENTE, subtotal/total_tax/total_amount, imo_lease_id, imo_rent_id). |
| IMO-F-173 | IMO-B-061 | Contratos/rendas/modelos/subarrendamento | Linhas da factura: renda, encargos a acrescer, extras (obras) — com IVA da renda. |
| IMO-F-174 | IMO-B-062 | Contratos/rendas/modelos/subarrendamento | Renda passa a FACTURADA com sale_id, doc_number, data_factura. |
| IMO-F-175 | IMO-B-063 | Contratos/rendas/modelos/subarrendamento | Facturar em lote devolve feitas/falhas; UI com confirmação e lista de falhas. |
| IMO-F-176 | IMO-B-064 | Contratos/rendas/modelos/subarrendamento | Conta corrente: recebido = soma de recibos da venda; dívida = total_receber - recebido; atraso em dias (só documentos vencidos com dívida); juros simples = dívida x mora% x dias/30. |
| IMO-F-177 | IMO-B-065 | Contratos/rendas/modelos/subarrendamento | Totais da conta corrente (facturado, esperado, recebido, em dívida, juros, IP retido, selo suportado, caução em poder). |
| IMO-F-178 | IMO-B-066 | Contratos/rendas/modelos/subarrendamento | Impressão da conta corrente (A4 horizontal). |
| IMO-F-179 | IMO-B-067 | Contratos/rendas/modelos/subarrendamento | Pagar exige lado inquilino, renda APROVADA, conta de custo e de disponibilidades; retenção exige conta de retenção. |
| IMO-F-180 | IMO-B-068 | Contratos/rendas/modelos/subarrendamento | Documento de tesouraria PAGAMENTO PENDENTE: total_value = base - IP retido; linha D custo; linha C retenção; reference = doc_number ou nº do contrato. |
| IMO-F-181 | IMO-B-069 | Contratos/rendas/modelos/subarrendamento | Recusa pagar renda com IVA e com valor divergente de total_receber (>0,02). |
| IMO-F-182 | IMO-B-070 | Contratos/rendas/modelos/subarrendamento | Renda passa a PAGA (treasury_id, data_pagamento). |
| IMO-F-183 | IMO-B-071 | Contratos/rendas/modelos/subarrendamento | Pagar em lote devolve feitas/falhas. |
| IMO-F-184 | IMO-B-072 | Contratos/rendas/modelos/subarrendamento | Desligar pagamento: motivo; recusa documento INTEGRADO; renda volta a APROVADA e perde treasury_id; nota registada. |
| IMO-F-185 | IMO-B-073 | Contratos/rendas/modelos/subarrendamento | Conta corrente lado inquilino usa treasury_document (exclui ANULADO). |
| IMO-F-186 | IMO-B-074 | Contratos/rendas/modelos/subarrendamento | Margem por fracção no intervalo (recebe, paga, margem, taxa %). |
| IMO-F-187 | IMO-B-075 | Contratos/rendas/modelos/subarrendamento | Totais, fracções com margem negativa, tomadas sem subarrendamento; ordenação ASC por margem. |
| IMO-F-188 | IMO-B-076 | Contratos/rendas/modelos/subarrendamento | Cadeia: mãe + filhos, pago/recebido/margem, vivos. |
| IMO-F-189 | IMO-B-077 | Contratos/rendas/modelos/subarrendamento | Mês a mês por fracção. |
| IMO-F-190 | IMO-B-078 | Contratos/rendas/modelos/subarrendamento | Imprimir margem (A4 horizontal). |
| IMO-F-191 | IMO-B-079 | Contratos/rendas/modelos/subarrendamento | Obras a debitar: concluídas, debitáveis, não debitadas; contrato activo lado senhorio sobre a fracção. |
| IMO-F-192 | IMO-B-080 | Contratos/rendas/modelos/subarrendamento | Debitar obra: estados/mensagens; extras na renda; base/total_facturar/total_receber recalculados; IP e Selo inalterados; marca a obra como debitada. |
| IMO-F-193 | IMO-B-081 | Contratos/rendas/modelos/subarrendamento | Avisos: RASCUNHO, REGISTO (prazo_registo dias, só senhorio e ACTIVO), CAUCAO (falta receber/entregar), SEM_TITULO/TITULO_INACTIVO/ALEM_TITULO, FIM (<= max(60, aviso_dias)), EXPIRADO, ACTUALIZACAO, POR_FACTURAR, POR_PAGAR, MORA. |
| IMO-F-194 | IMO-B-082 | Contratos/rendas/modelos/subarrendamento | KPIs do ecrã de contratos (activos, renda mensal por lado normalizada pela periodicidade, margem mensal, cauções, por facturar/por pagar). |
| IMO-F-195 | IMO-B-083 | Contratos/rendas/modelos/subarrendamento | KPIs do ecrã de rendas (previstas, aprovadas, facturadas/pagas, a receber/pagar). |
| IMO-F-196 | IMO-B-084 | Contratos/rendas/modelos/subarrendamento | Listagem de contratos: filtros texto/estado/lado; coluna «sob NÚMERO» para subarrendamentos; fracções múltiplas. |
| IMO-F-197 | IMO-B-085 | Contratos/rendas/modelos/subarrendamento | CRUD de modelos (tipo, nome, texto obrigatórios; activo; rejeita variáveis desconhecidas). |
| IMO-F-198 | IMO-B-086 | Contratos/rendas/modelos/subarrendamento | Importar 8 modelos por omissão sem duplicar (tipo+nome). |
| IMO-F-199 | IMO-B-087 | Contratos/rendas/modelos/subarrendamento | Aplicar modelo: substituição de variáveis; variável vazia -> `[Rótulo]` + lista de faltas; formatos de data/Kz; renda por extenso. |
| IMO-F-200 | IMO-B-088 | Contratos/rendas/modelos/subarrendamento | Contexto invertido por lado (senhorio/inquilino) e morada do contrato/ficha por NIF. |
| IMO-F-201 | IMO-B-089 | Contratos/rendas/modelos/subarrendamento | Recibo com `recebido` vindo dos recibos de Vendas e dívida; Interpelação com dívida/dias/juros da conta corrente. |
| IMO-F-202 | IMO-B-090 | Contratos/rendas/modelos/subarrendamento | Ver/copiar/imprimir documento (A4 vertical, aviso de faltas escondido em impressão). |
| IMO-F-203 | IMO-B-091 | Contratos/rendas/modelos/subarrendamento | `extenso()`: casos 0, 1, 100, 1000, 1000000, 2 500 000, céntimos. |
| IMO-F-204 | IMO-B-092 | Contratos/rendas/modelos/subarrendamento | Vagas afectas: propor encargos PARQUE (valor de referência/incluído/ignorar) e perguntar ao gravar. |
| IMO-F-205 | IMO-B-093 | Contratos/rendas/modelos/subarrendamento | Permissões: 11 tarefas e 4 ecrãs (secção 9) aplicadas às acções correspondentes. |
| IMO-F-206 | IMO-B-094 | Contratos/rendas/modelos/subarrendamento | Multiempresa: todas as consultas filtradas por `imo_company_id`. |
| IMO-F-207 | IMO-C-001 | Negócios/reservas financeiras/planos/rentabilidade | Criar negócio COMPRA com imóvel, vendedor e preço > 0 em estado NEGOCIACAO; recebe número `CP AAAA/0001`. |
| IMO-F-208 | IMO-C-002 | Negócios/reservas financeiras/planos/rentabilidade | Criar negócio VENDA numerado `VD AAAA/0001`; série independente da compra; incrementa por tipo e ano corrente; formato 4 dígitos. |
| IMO-F-209 | IMO-C-003 | Negócios/reservas financeiras/planos/rentabilidade | Recusar gravar sem imóvel nem fracção: «Escolha o imóvel ou a fracção do negócio.» |
| IMO-F-210 | IMO-C-004 | Negócios/reservas financeiras/planos/rentabilidade | Na VENDA, recusar imóvel cujo papel ≠ PROPRIO com a mensagem que cita o papel. |
| IMO-F-211 | IMO-C-005 | Negócios/reservas financeiras/planos/rentabilidade | Recusar sem contraparte («Indique o vendedor.» / «Indique o comprador.» conforme o tipo). |
| IMO-F-212 | IMO-C-006 | Negócios/reservas financeiras/planos/rentabilidade | Recusar preço <= 0: «O preço tem de ser maior do que zero.» |
| IMO-F-213 | IMO-C-007 | Negócios/reservas financeiras/planos/rentabilidade | Recusar sinal maior que o preço: «O sinal não pode passar do preço.» |
| IMO-F-214 | IMO-C-008 | Negócios/reservas financeiras/planos/rentabilidade | Recusar escritura anterior à promessa e registo anterior à escritura (mensagens exactas). |
| IMO-F-215 | IMO-C-009 | Negócios/reservas financeiras/planos/rentabilidade | Fracção escolhida deriva `property_id` da fracção e rejeita fracção de outra empresa. |
| IMO-F-216 | IMO-C-010 | Negócios/reservas financeiras/planos/rentabilidade | Finalidade inválida cai em HABITACAO; isenção inválida cai em NAO; tipo inválido em COMPRA. |
| IMO-F-217 | IMO-C-011 | Negócios/reservas financeiras/planos/rentabilidade | Editar negócio em CONCLUIDO/CANCELADO é recusado («O negócio está concluído: não se altera.»). |
| IMO-F-218 | IMO-C-012 | Negócios/reservas financeiras/planos/rentabilidade | Editar mantém `numero`, `criado_em` e estado actual. |
| IMO-F-219 | IMO-C-013 | Negócios/reservas financeiras/planos/rentabilidade | Eliminar negócio em fase < ESCRITURA apaga também os seus encargos; a partir de ESCRITURA é recusado. |
| IMO-F-220 | IMO-C-014 | Negócios/reservas financeiras/planos/rentabilidade | Eliminar negócio CANCELADO é permitido (ordem 0). |
| IMO-F-221 | IMO-C-015 | Negócios/reservas financeiras/planos/rentabilidade | Listar negócios filtrados por tipo, fase, imóvel, fracção e «abertos»; isolados por empresa. |
| IMO-F-222 | IMO-C-016 | Negócios/reservas financeiras/planos/rentabilidade | Ordenação da lista por data de escritura/promessa desc., depois id desc. |
| IMO-F-223 | IMO-C-017 | Negócios/reservas financeiras/planos/rentabilidade | Pesquisa de texto por número, imóvel, fracção e contraparte (case-insensitive). |
| IMO-F-224 | IMO-C-018 | Negócios/reservas financeiras/planos/rentabilidade | Base do imposto = max(preço, VPT); sinaliza `base_e_vpt` quando o VPT é maior. |
| IMO-F-225 | IMO-C-019 | Negócios/reservas financeiras/planos/rentabilidade | Imposto Predial = base × taxa(2% por omissão)/100, arredondado a 2 casas. |
| IMO-F-226 | IMO-C-020 | Negócios/reservas financeiras/planos/rentabilidade | Imposto de Selo = base × 0,3% por omissão. |
| IMO-F-227 | IMO-C-021 | Negócios/reservas financeiras/planos/rentabilidade | Escritura fixa 2.000 Kz por omissão; total_impostos = IP + selo + escritura. |
| IMO-F-228 | IMO-C-022 | Negócios/reservas financeiras/planos/rentabilidade | Isenção TOTAL zera o IP; isenção METADE reduz o IP a metade; selo e escritura não mudam. |
| IMO-F-229 | IMO-C-023 | Negócios/reservas financeiras/planos/rentabilidade | Taxas lidas do ano da escritura (ou promessa, ou hoje) a partir das taxas anuais da empresa; defaults quando em falta. |
| IMO-F-230 | IMO-C-024 | Negócios/reservas financeiras/planos/rentabilidade | Sugestão de isenção: só para HABITACAO; TOTAL até `isencao_ate`, METADE até `isencao_metade_ate`, NAO acima; textos exactos; sem escalões configurados diz-se. |
| IMO-F-231 | IMO-C-025 | Negócios/reservas financeiras/planos/rentabilidade | Simulação em tempo real no formulário (base, IP, selo, escritura, total, sugestão) e mensagem «Indique o preço para ver os impostos da transmissão.» |
| IMO-F-232 | IMO-C-026 | Negócios/reservas financeiras/planos/rentabilidade | Impostos recalculam em cada gravação antes da escritura e ficam fixos a partir da escritura. |
| IMO-F-233 | IMO-C-027 | Negócios/reservas financeiras/planos/rentabilidade | Rotulagem do encargo: «a cargo do adquirente — a empresa» (compra) / «— o comprador» (venda). |
| IMO-F-234 | IMO-C-028 | Negócios/reservas financeiras/planos/rentabilidade | Avançar NEGOCIACAO->PROMESSA exige data da promessa («Indique a data da promessa.»). |
| IMO-F-235 | IMO-C-029 | Negócios/reservas financeiras/planos/rentabilidade | Avançar para ESCRITURA exige data da escritura, cartório/notário e valor patrimonial > 0 (3 mensagens exactas). |
| IMO-F-236 | IMO-C-030 | Negócios/reservas financeiras/planos/rentabilidade | Avançar para ESCRITURA numa VENDA de fracção com contratos ACTIVO/SUSPENSO exige tratar os contratos (mensagem exacta com números). |
| IMO-F-237 | IMO-C-031 | Negócios/reservas financeiras/planos/rentabilidade | Avançar para REGISTADO exige data do registo. |
| IMO-F-238 | IMO-C-032 | Negócios/reservas financeiras/planos/rentabilidade | Não recuar de fase (mensagem exacta com rótulos). |
| IMO-F-239 | IMO-C-033 | Negócios/reservas financeiras/planos/rentabilidade | Ao entrar em ESCRITURA grava `impostos_fixados_em` e fixa os impostos pelas taxas do ano da escritura. |
| IMO-F-240 | IMO-C-034 | Negócios/reservas financeiras/planos/rentabilidade | Cancelar exige motivo; grava `motivo_cancelamento` e `cancelado_em`. |
| IMO-F-241 | IMO-C-035 | Negócios/reservas financeiras/planos/rentabilidade | Não se cancela negócio já escriturado («Faça o negócio inverso»). |
| IMO-F-242 | IMO-C-036 | Negócios/reservas financeiras/planos/rentabilidade | Negócio cancelado não avança («O negócio está cancelado.»). |
| IMO-F-243 | IMO-C-037 | Negócios/reservas financeiras/planos/rentabilidade | A UI só oferece o botão da fase seguinte quando não há faltas; lista as faltas num aviso. |
| IMO-F-244 | IMO-C-038 | Negócios/reservas financeiras/planos/rentabilidade | Botão «Concluir» só visível a partir de ESCRITURA; «Cancelar» só antes de ESCRITURA; «Editar» só em negócios abertos. |
| IMO-F-245 | IMO-C-039 | Negócios/reservas financeiras/planos/rentabilidade | Barra de fases com estados feita/agora e datas por fase. |
| IMO-F-246 | IMO-C-040 | Negócios/reservas financeiras/planos/rentabilidade | Gravar encargos substitui a lista anterior; ignora linhas sem designação ou valor <= 0. |
| IMO-F-247 | IMO-C-041 | Negócios/reservas financeiras/planos/rentabilidade | Tipo de encargo inválido -> OUTRO; `capitaliza` herda do tipo se não indicado; OUTRO não capitaliza. |
| IMO-F-248 | IMO-C-042 | Negócios/reservas financeiras/planos/rentabilidade | Encargos fechados (CONCLUIDO/CANCELADO) não se alteram («O negócio está fechado: os encargos não se alteram.»). |
| IMO-F-249 | IMO-C-043 | Negócios/reservas financeiras/planos/rentabilidade | Custo de aquisição = preço + (IP + selo + escritura do negócio) + encargos capitalizáveis; gasto do período = não capitalizáveis. |
| IMO-F-250 | IMO-C-044 | Negócios/reservas financeiras/planos/rentabilidade | Editor de encargos: adicionar/remover linhas, data por omissão hoje, flags «capitaliza» (marcada por omissão) e «já pago». |
| IMO-F-251 | IMO-C-045 | Negócios/reservas financeiras/planos/rentabilidade | Detalhe lista encargos com Tipo, Designação, Valor, Data, Capitaliza, Pago. |
| IMO-F-252 | IMO-C-046 | Negócios/reservas financeiras/planos/rentabilidade | Modal «Decidir o que fazer aos contratos»: lista contratos vivos (número, fim ou «sem termo», renda). |
| IMO-F-253 | IMO-C-047 | Negócios/reservas financeiras/planos/rentabilidade | Destino TRANSMITE termina cada contrato à data da escritura (cascata, caução 0/0) com o motivo exacto e regista `contratos_tratados`. |
| IMO-F-254 | IMO-C-048 | Negócios/reservas financeiras/planos/rentabilidade | Destino MANTEM regista `contratos_tratados` sem terminar e levanta o bloqueio. |
| IMO-F-255 | IMO-C-049 | Negócios/reservas financeiras/planos/rentabilidade | Aviso ARRENDADO em vendas de fracção arrendada a partir da promessa. |
| IMO-F-256 | IMO-C-050 | Negócios/reservas financeiras/planos/rentabilidade | Concluir compra só após escritura; recusa se já concluída ou se é venda ou sem imóvel no cadastro. |
| IMO-F-257 | IMO-C-051 | Negócios/reservas financeiras/planos/rentabilidade | Concluir compra marca o imóvel PROPRIO, limpa o proprietário, grava `valor_aquisicao = custo total` e `data_aquisicao = data_escritura`. |
| IMO-F-258 | IMO-C-052 | Negócios/reservas financeiras/planos/rentabilidade | Escreve VPT do imóvel apenas se o cadastro não tinha VPT. |
| IMO-F-259 | IMO-C-053 | Negócios/reservas financeiras/planos/rentabilidade | Actualiza `acquisition_value` e `acquisition_date` do activo ligado; indica na mensagem se não está ligado ao imobilizado. |
| IMO-F-260 | IMO-C-054 | Negócios/reservas financeiras/planos/rentabilidade | Grava `custo_aquisicao`, `asset_id`, `concluido_em`, notas no negócio e passa a CONCLUIDO. |
| IMO-F-261 | IMO-C-055 | Negócios/reservas financeiras/planos/rentabilidade | Confirmação prévia com o custo de aquisição (preço + impostos e encargos). |
| IMO-F-262 | IMO-C-056 | Negócios/reservas financeiras/planos/rentabilidade | Quota de fracção: imóvel inteiro 1; fracção única 1; por área útil; fallback área bruta; fallback partes iguais; sobre o total das fracções. |
| IMO-F-263 | IMO-C-057 | Negócios/reservas financeiras/planos/rentabilidade | Valor contabilístico = custo×quota − amortizações×quota (amortizações de `asset_depreciations`, senão acumulado do activo). |
| IMO-F-264 | IMO-C-058 | Negócios/reservas financeiras/planos/rentabilidade | Valor contabilístico desconhecido (imóvel sem activo / activo apagado) devolve motivo exacto e não calcula mais-valia. |
| IMO-F-265 | IMO-C-059 | Negócios/reservas financeiras/planos/rentabilidade | Mais-valia = preço − valor contabilístico − todos os encargos do negócio; classifica MAIS_VALIA / MENOS_VALIA. |
| IMO-F-266 | IMO-C-060 | Negócios/reservas financeiras/planos/rentabilidade | Mais-valia só para VENDA; compra recusa com mensagem. |
| IMO-F-267 | IMO-C-061 | Negócios/reservas financeiras/planos/rentabilidade | Concluir venda marca a(s) fracção(ões) como VENDIDA (todas se imóvel inteiro). |
| IMO-F-268 | IMO-C-062 | Negócios/reservas financeiras/planos/rentabilidade | Imóvel passa a ALIENADO com `data_alienacao` quando não resta fracção por vender. |
| IMO-F-269 | IMO-C-063 | Negócios/reservas financeiras/planos/rentabilidade | Regista `asset_disposals` (tipo VENDA, valor, valor contabilístico, resultado, notas com %/critério em venda parcial). |
| IMO-F-270 | IMO-C-064 | Negócios/reservas financeiras/planos/rentabilidade | Activo passa a ABATIDO só se não restam fracções; em venda parcial não altera o valor e avisa (diálogo «Falta o abate parcial no imobilizado»). |
| IMO-F-271 | IMO-C-065 | Negócios/reservas financeiras/planos/rentabilidade | Venda guarda `mais_valia`, `valor_contabilistico`, `sale_id`, `asset_disposal_id`, notas de fecho. |
| IMO-F-272 | IMO-C-066 | Negócios/reservas financeiras/planos/rentabilidade | Mensagens de resultado pós-venda (fracções marcadas, resultado, restam, factura). |
| IMO-F-273 | IMO-C-067 | Negócios/reservas financeiras/planos/rentabilidade | Concluir venda sem valor contabilístico conclui à mesma «sem resultado apurado». |
| IMO-F-274 | IMO-C-068 | Negócios/reservas financeiras/planos/rentabilidade | Opção «Emitir factura ao comprador» cria `Factura` pela numeração AGT (fallback local, fallback `FT ano/n`). |
| IMO-F-275 | IMO-C-069 | Negócios/reservas financeiras/planos/rentabilidade | Factura com cliente = terceiro, data = data da escritura, vencimento = data (ou indicado), `related_doc_id` = número do negócio, estado PENDENTE. |
| IMO-F-276 | IMO-C-070 | Negócios/reservas financeiras/planos/rentabilidade | Linha única «Venda de imóvel — {imóvel · fracção}», IVA 0% por omissão (cálculo suporta taxa). |
| IMO-F-277 | IMO-C-071 | Negócios/reservas financeiras/planos/rentabilidade | Falha ao gravar factura/linha liberta a numeração reservada e desfaz o cabeçalho. |
| IMO-F-278 | IMO-C-072 | Negócios/reservas financeiras/planos/rentabilidade | Selagem AGT após criar a factura; falha de selagem não interrompe. |
| IMO-F-279 | IMO-C-073 | Negócios/reservas financeiras/planos/rentabilidade | Factura liga-se ao negócio (`imo_deal_id` / `sale_id`). |
| IMO-F-280 | IMO-C-074 | Negócios/reservas financeiras/planos/rentabilidade | KPI compras escrituradas no ano e valor investido (custo de aquisição ou preço). |
| IMO-F-281 | IMO-C-075 | Negócios/reservas financeiras/planos/rentabilidade | KPI vendas do ano e valor encaixado. |
| IMO-F-282 | IMO-C-076 | Negócios/reservas financeiras/planos/rentabilidade | KPI resultado das vendas (mais-valias + menos-valias) com cor. |
| IMO-F-283 | IMO-C-077 | Negócios/reservas financeiras/planos/rentabilidade | KPI impostos da transmissão (IP + selo dos negócios com escritura no ano). |
| IMO-F-284 | IMO-C-078 | Negócios/reservas financeiras/planos/rentabilidade | KPI processos abertos e total de sinais em curso (estado PROMESSA). |
| IMO-F-285 | IMO-C-079 | Negócios/reservas financeiras/planos/rentabilidade | Avisos: promessa > 180 dias sem escritura; por registar; por concluir; fracção arrendada; lista limitada a 8 no ecrã. |
| IMO-F-286 | IMO-C-080 | Negócios/reservas financeiras/planos/rentabilidade | Detalhe: cartões Negócio, Impostos (fixados), Custo de aquisição (compra) / Resultado da venda (venda), Encargos, Notas. |
| IMO-F-287 | IMO-C-081 | Negócios/reservas financeiras/planos/rentabilidade | Formulário: filtragem de fracções pelo imóvel escolhido e rótulo dinâmico Vendedor/Comprador. |
| IMO-F-288 | IMO-C-082 | Negócios/reservas financeiras/planos/rentabilidade | Permissões: editar (`imo_neg_editar`), fase (`imo_neg_fase`), concluir (`imo_neg_concluir`) aplicadas às acções correspondentes. |
| IMO-F-289 | IMO-C-083 | Negócios/reservas financeiras/planos/rentabilidade | `situacao`: recebido calculado dos itens de recibo; por pagar = valor − recebido (>=0); `pago` verdadeiro quando saldo <= 0,009 (ou espaço não pago). |
| IMO-F-290 | IMO-C-084 | Negócios/reservas financeiras/planos/rentabilidade | Estado da caução derivado (6 estados com rótulos exactos). |
| IMO-F-291 | IMO-C-085 | Negócios/reservas financeiras/planos/rentabilidade | Facturar reserva só para espaço de reserva paga, reserva viva, aprovada, com valor e com terceiro; recusas com mensagens exactas. |
| IMO-F-292 | IMO-C-086 | Negócios/reservas financeiras/planos/rentabilidade | Factura de reserva sem IVA, estado PENDENTE, vencimento no dia da reserva, `related_doc_id` `Reserva {id}`, notas com espaço/data/horas. |
| IMO-F-293 | IMO-C-087 | Negócios/reservas financeiras/planos/rentabilidade | Facturar passa APROVADA -> AGUARDA_PAGAMENTO e define o prazo de pagamento. |
| IMO-F-294 | IMO-C-088 | Negócios/reservas financeiras/planos/rentabilidade | Prazo = min(agora + horas do espaço (48 por omissão, mín. 1), início do evento). |
| IMO-F-295 | IMO-C-089 | Negócios/reservas financeiras/planos/rentabilidade | Falha ao gravar factura/linha reverte e liberta numeração. |
| IMO-F-296 | IMO-C-090 | Negócios/reservas financeiras/planos/rentabilidade | Receber utilização: recibo `RE ano/n`, item de recibo, venda actualizada (pago/pendente). |
| IMO-F-297 | IMO-C-091 | Negócios/reservas financeiras/planos/rentabilidade | Receber caução: documento de tesouraria RECEBIMENTO `CAU ano/idReserva` pendente + item `D`; caução nunca em `receipts`. |
| IMO-F-298 | IMO-C-092 | Negócios/reservas financeiras/planos/rentabilidade | Validações de recebimento (excesso do valor, caução acima da devida, nada a receber, reserva não facturada, reserva não viva). |
| IMO-F-299 | IMO-C-093 | Negócios/reservas financeiras/planos/rentabilidade | Reserva AGUARDA_PAGAMENTO passa a CONFIRMADA quando tudo pago e caução completa. |
| IMO-F-300 | IMO-C-094 | Negócios/reservas financeiras/planos/rentabilidade | Caducidade: reservas AGUARDA_PAGAMENTO com prazo ultrapassado e sem pagamento passam a CANCELADA (sistema, `caducada=1`, motivo com data/hora) e auditam; as já pagas confirmam-se. |
| IMO-F-301 | IMO-C-095 | Negócios/reservas financeiras/planos/rentabilidade | `confirmarPorPagamento` falha com «Ainda faltam X Kz…» se não pago; devolve false se estado diferente. |
| IMO-F-302 | IMO-C-096 | Negócios/reservas financeiras/planos/rentabilidade | Previsão de cancelamento: dias que faltam, limite, % devolvida (100% até N dias antes, depois `cancel_pct`), valor devolvido/retido, caução a devolver e explicação textual. |
| IMO-F-303 | IMO-C-097 | Negócios/reservas financeiras/planos/rentabilidade | Cancelar com reembolso exige motivo, limita ao recebido, grava reembolso previsto/retido/%, audita; não emite nota de crédito. |
| IMO-F-304 | IMO-C-098 | Negócios/reservas financeiras/planos/rentabilidade | Vistoria ANTES/DEPOIS grava objecto `{em, por, estado(BOM), observacoes, url}` e audita; URL validada. |
| IMO-F-305 | IMO-C-099 | Negócios/reservas financeiras/planos/rentabilidade | Fechar caução: devolver tudo / reter parte / reter tudo; valida negativos, excesso, motivo obrigatório ao reter, vistoria de saída obrigatória ao reter, caução inexistente/não recebida/já fechada. |
| IMO-F-306 | IMO-C-100 | Negócios/reservas financeiras/planos/rentabilidade | Devolução gera documento de tesouraria PAGAMENTO `DEV ano/idReserva` com item `C`. |
| IMO-F-307 | IMO-C-101 | Negócios/reservas financeiras/planos/rentabilidade | Parte retida gera factura própria (já paga, `imo_caucao=1`) ao terceiro. |
| IMO-F-308 | IMO-C-102 | Negócios/reservas financeiras/planos/rentabilidade | Opção «abrir obra»: cria ordem correctiva ALTA, a cargo do senhorio, custo estimado informado ou valor retido, ligada à reserva (`danos_work_id`). |
| IMO-F-309 | IMO-C-103 | Negócios/reservas financeiras/planos/rentabilidade | `contas` do período: reservas (sem recusadas), facturado, recebido, por cobrar (só facturado e não cancelado), cauções em poder, cauções retidas, receita total = recebido + retidas. |
| IMO-F-310 | IMO-C-104 | Negócios/reservas financeiras/planos/rentabilidade | Avisos financeiros de reservas (por cobrar, cauções em poder, reservas terminadas com caução, reservas sem factura). |
| IMO-F-311 | IMO-C-105 | Negócios/reservas financeiras/planos/rentabilidade | Auditoria de: caducar, facturar, receber, cancelar, vistoria, fechar caução. |
| IMO-F-312 | IMO-C-106 | Negócios/reservas financeiras/planos/rentabilidade | Só PREVENTIVA e VISTORIA são planeáveis (mensagem exacta). |
| IMO-F-313 | IMO-C-107 | Negócios/reservas financeiras/planos/rentabilidade | Plano exige descrição, imóvel ou fracção e pelo menos uma data; máx. 60; datas deduplicadas e ordenadas; formato AAAA-MM-DD. |
| IMO-F-314 | IMO-C-108 | Negócios/reservas financeiras/planos/rentabilidade | Nome por omissão = 60 primeiros caracteres da descrição; periodicidade inválida -> ANUAL; prioridade -> NORMAL; quem_paga -> SENHORIO. |
| IMO-F-315 | IMO-C-109 | Negócios/reservas financeiras/planos/rentabilidade | `debitavel` forçado a 0 quando paga o senhorio; UI desactiva o checkbox. |
| IMO-F-316 | IMO-C-110 | Negócios/reservas financeiras/planos/rentabilidade | `proxima` = primeira data >= hoje (senão última). |
| IMO-F-317 | IMO-C-111 | Negócios/reservas financeiras/planos/rentabilidade | Propor datas: periodicidades mensal/bimestral/trimestral/semestral/anual/bianual; dia mantido ou fim do mês; MANUAL devolve só a primeira; quantas 1..60; primeira inválida devolve vazio. |
| IMO-F-318 | IMO-C-112 | Negócios/reservas financeiras/planos/rentabilidade | UI «Propor datas» substitui as datas, datas editáveis uma a uma, acrescentar/remover. |
| IMO-F-319 | IMO-C-113 | Negócios/reservas financeiras/planos/rentabilidade | Estado do plano: cada data com ordem gerada (estado da ordem), «Em falta» (passada sem ordem) ou «Por gerar»; contadores gerar/falta/concluídas; próxima. |
| IMO-F-320 | IMO-C-114 | Negócios/reservas financeiras/planos/rentabilidade | Gerar as próximas: só datas dentro de hoje + antecedência (30 por omissão); idempotente (não duplica). |
| IMO-F-321 | IMO-C-115 | Negócios/reservas financeiras/planos/rentabilidade | Gerar todas: cria todo o calendário em falta. |
| IMO-F-322 | IMO-C-116 | Negócios/reservas financeiras/planos/rentabilidade | Ordem gerada: descrição `{descricao} ({nome})`, pedido por `Plano {nome}`, data pedido = hoje (ou a própria data se já passou), data prevista, custo/prioridade/quem_paga/debitável/responsável/notas do plano; liga `plan_id` e `plano_data`. |
| IMO-F-323 | IMO-C-117 | Negócios/reservas financeiras/planos/rentabilidade | Plano inactivo não gera («reactive-o antes de gerar ordens»). |
| IMO-F-324 | IMO-C-118 | Negócios/reservas financeiras/planos/rentabilidade | Gerar em lote para todos os planos activos com relatório de falhas. |
| IMO-F-325 | IMO-C-119 | Negócios/reservas financeiras/planos/rentabilidade | Eliminar plano bloqueado se há ordens geradas por fechar; senão apaga e informa quantas ficam no histórico. |
| IMO-F-326 | IMO-C-120 | Negócios/reservas financeiras/planos/rentabilidade | Lista de planos: colunas, datas/feitas, próxima ou «terminado», em falta, activo; vazio com mensagem. |
| IMO-F-327 | IMO-C-121 | Negócios/reservas financeiras/planos/rentabilidade | Calendário do plano com ligação à ordem e aviso de datas passadas. |
| IMO-F-328 | IMO-C-122 | Negócios/reservas financeiras/planos/rentabilidade | Avisos: PLANO_EM_FALTA e PLANO_PROXIMO; resumo (total, activos, datas, em falta, por gerar). |
| IMO-F-329 | IMO-C-123 | Negócios/reservas financeiras/planos/rentabilidade | Permissões `imo_plano_editar` e `imo_plano_gerar`. |
| IMO-F-330 | IMO-C-124 | Negócios/reservas financeiras/planos/rentabilidade | Intervalo por omissão: 12 meses até ao mês actual; erro se início > fim. |
| IMO-F-331 | IMO-C-125 | Negócios/reservas financeiras/planos/rentabilidade | Executado de renda só de rendas FACTURADA/PAGA; previsto a partir dos contratos (exceto RASCUNHO e SUSPENSO), mês a mês, incluindo meses por facturar. |
| IMO-F-332 | IMO-C-126 | Negócios/reservas financeiras/planos/rentabilidade | Contratos de várias fracções repartem renda por área útil -> bruta -> partes iguais; linha marcada «quota do contrato» e aviso. |
| IMO-F-333 | IMO-C-127 | Negócios/reservas financeiras/planos/rentabilidade | Posição do contrato: SENHORIO soma `renda_recebida`; outra posição soma `renda_paga` (fracção «subarrendada»). |
| IMO-F-334 | IMO-C-128 | Negócios/reservas financeiras/planos/rentabilidade | Selo conta como custo quando `selo_encargo = SENHORIO`; IP só quando o inquilino não retém. |
| IMO-F-335 | IMO-C-129 | Negócios/reservas financeiras/planos/rentabilidade | Obras só da fracção; quem paga INQUILINO 0, PARTILHADO 50%, SENHORIO 100%; investimento (APETRECHAMENTO, MELHORIA) separado de manutenção. |
| IMO-F-336 | IMO-C-130 | Negócios/reservas financeiras/planos/rentabilidade | Custo da obra: real das compras (recebido ou pedido × preço), senão estimado, senão sem valor (+ aviso). |
| IMO-F-337 | IMO-C-131 | Negócios/reservas financeiras/planos/rentabilidade | Previsto de obras = estimado (ou real); executado só obras CONCLUIDAS; recuperado (débito ao inquilino) previsto e executado. |
| IMO-F-338 | IMO-C-132 | Negócios/reservas financeiras/planos/rentabilidade | Datas de planos sem ordem entram no previsto de manutenção/investimento da fracção. |
| IMO-F-339 | IMO-C-133 | Negócios/reservas financeiras/planos/rentabilidade | Custos = renda paga + selo + IP próprio + manutenção + investimento − recuperado; resultado = receita − custos; margem %. |
| IMO-F-340 | IMO-C-134 | Negócios/reservas financeiras/planos/rentabilidade | Desvio = executado − previsto; taxas de execução de receita e de custos. |
| IMO-F-341 | IMO-C-135 | Negócios/reservas financeiras/planos/rentabilidade | Yield anual só em imóveis PROPRIO: base = valor de aquisição (senão VPT) × quota; (resultado médio por mês facturado × 12)/valor; versão prevista. |
| IMO-F-342 | IMO-C-136 | Negócios/reservas financeiras/planos/rentabilidade | Listas de alerta: negativas, paradas, por facturar, derrapagens; avisos de custo subavaliado/armazém/repartição/planos. |
| IMO-F-343 | IMO-C-137 | Negócios/reservas financeiras/planos/rentabilidade | Ordenação por pior resultado executado; filtro «mostrar as fracções sem movimento». |
| IMO-F-344 | IMO-C-138 | Negócios/reservas financeiras/planos/rentabilidade | Toggle «contar o investimento do período» recalcula KPIs, linhas, custos e totais. |
| IMO-F-345 | IMO-C-139 | Negócios/reservas financeiras/planos/rentabilidade | KPIs: resultado previsto, executado, desvio, % renda facturada do devido, fracções a dar prejuízo. |
| IMO-F-346 | IMO-C-140 | Negócios/reservas financeiras/planos/rentabilidade | Tabela agrupada com % coloridas (>=99,5 renda; <=100,5 custos), rodapé de totais, vazio com mensagem. |
| IMO-F-347 | IMO-C-141 | Negócios/reservas financeiras/planos/rentabilidade | Detalhe da fracção: previsto×executado por parcela, indicadores (meses, média, valor, yield), mês a mês com soma, obras do período com origem do custo. |
| IMO-F-348 | IMO-C-142 | Negócios/reservas financeiras/planos/rentabilidade | Impressão A4 paisagem com totais, avisos e notas; indica «sem o apetrechamento e as melhorias» quando aplicável. |
| IMO-F-349 | IMO-C-143 | Negócios/reservas financeiras/planos/rentabilidade | Ecrã de leitura sem chaves de acção (visibilidade pelo ecrã `imo_rentabilidade`). |
| IMO-F-350 | IMO-D-001 | Obras/fiscal/navegação | Menu "Gestão Imobiliária" com 13 entradas na ordem indicada e rotas `#/imobiliario/{ecrã}`. |
| IMO-F-351 | IMO-D-002 | Obras/fiscal/navegação | Fechar automaticamente modais `imo-*-modal` ao mudar de rota. |
| IMO-F-352 | IMO-D-003 | Obras/fiscal/navegação | Lista de imóveis com KPIs (Imóveis, Fracções, Ocupação %, m² área bruta). |
| IMO-F-353 | IMO-D-004 | Obras/fiscal/navegação | Pesquisa de imóveis por código/designação/morada/município/matriz (insensível a maiúsculas). |
| IMO-F-354 | IMO-D-005 | Obras/fiscal/navegação | Filtro por papel com contagem por papel. |
| IMO-F-355 | IMO-D-006 | Obras/fiscal/navegação | Aviso "n imóvel(eis) com dados por completar". |
| IMO-F-356 | IMO-D-007 | Obras/fiscal/navegação | Linhas de imóveis não-ACTIVO a opacidade reduzida; coluna Ocupadas com percentagem. |
| IMO-F-357 | IMO-D-008 | Obras/fiscal/navegação | Detalhe do imóvel: cartões (identificação, localização+mapa, áreas/valores, fracções) e tabela por piso (só >1 piso). |
| IMO-F-358 | IMO-D-009 | Obras/fiscal/navegação | Editor do imóvel: campos condicionais (pisos só em tipos com pisos; habitações só condomínio e obrigatório; edifício só para papel PROPRIO; proprietário obrigatório para ARRENDADO/MEDIACAO). |
| IMO-F-359 | IMO-D-010 | Obras/fiscal/navegação | Documentos do imóvel/fracção só como URL http(s); linhas sem URL descartadas. |
| IMO-F-360 | IMO-D-011 | Obras/fiscal/navegação | Editor de fracção; ao preencher Piso, Andar preenche-se com nomePiso. |
| IMO-F-361 | IMO-D-012 | Obras/fiscal/navegação | Gerar fracções por piso com pré-visualização, esquemas PORTA/LETRA/NUMERO/ESQ_DTO, blocos, limite 500, saltar existentes. |
| IMO-F-362 | IMO-D-013 | Obras/fiscal/navegação | "Trazer da planta" cria fracção por porta sem fracção; "Fracção única" quando não há fracções. |
| IMO-F-363 | IMO-D-014 | Obras/fiscal/navegação | Mapa de ocupação: KPI por estado + taxa de ocupação; grelha por imóvel; abrir ficha; mudar só o estado (motivo pedido em INDISPONIVEL/EM_OBRA). |
| IMO-F-364 | IMO-D-015 | Obras/fiscal/navegação | Impressão do mapa de ocupação A4. |
| IMO-F-365 | IMO-D-016 | Obras/fiscal/navegação | Criar ficha-tipo (nome obrigatório; tipologia/finalidade opcionais; activa/inactiva) com lista de artigos (artigo ou descrição; qtd inteira>=1; obrigatório/opcional). |
| IMO-F-366 | IMO-D-017 | Obras/fiscal/navegação | Gravar artigos da ficha em bloco substituindo os anteriores; linhas vazias ignoradas. |
| IMO-F-367 | IMO-D-018 | Obras/fiscal/navegação | Editar/apagar ficha (apaga itens); listagem com nº de artigos e estado. |
| IMO-F-368 | IMO-D-019 | Obras/fiscal/navegação | Registar bem da fracção (artigo ou descrição obrigatória; qtd; estado; data; notas). |
| IMO-F-369 | IMO-D-020 | Obras/fiscal/navegação | Editar/apagar bem. |
| IMO-F-370 | IMO-D-021 | Obras/fiscal/navegação | Importar bens do imobilizado pela porta (exclui EDIFICIO, evita duplicados por asset_id, origem INVENTARIO, estado BOM) e mensagens de erro. |
| IMO-F-371 | IMO-D-022 | Obras/fiscal/navegação | Comparar fracção com ficha: precisa/tem/falta; DANIFICADO e EM_FALTA não contam; sem artigo casa por descrição normalizada; mostra "n danificado(s)/em falta não contam" e nº de obrigatórios em falta. |
| IMO-F-372 | IMO-D-023 | Obras/fiscal/navegação | Abrir ordem de apetrechamento a partir das faltas (erro se nada falta); descrição automática; necessidades com a quantidade em falta. |
| IMO-F-373 | IMO-D-024 | Obras/fiscal/navegação | Criar ordem (tipo, prioridade, imóvel/fracção, descrição obrigatória, quem paga, debitável, custo estimado, datas, responsável, pedido por, notas) com numeração `AP`/`OB AAAA/NNNN` por prefixo e ano. |
| IMO-F-374 | IMO-D-025 | Obras/fiscal/navegação | Validar: precisa imóvel ou fracção; descrição; data prevista >= data do pedido. |
| IMO-F-375 | IMO-D-026 | Obras/fiscal/navegação | `debitavel` forçado a 0 quando quem paga = SENHORIO; campo escondido na UI. |
| IMO-F-376 | IMO-D-027 | Obras/fiscal/navegação | Editar ordem (não altera estado nem número); só se não fechada (UI). |
| IMO-F-377 | IMO-D-028 | Obras/fiscal/navegação | Eliminar ordem: bloqueada se itens REQUISITADO/PEDIDO_COMPRA; apaga itens. |
| IMO-F-378 | IMO-D-029 | Obras/fiscal/navegação | Lista de ordens com filtro por estado, ordenação data_pedido desc, coluna Prevista com "em atraso". |
| IMO-F-379 | IMO-D-030 | Obras/fiscal/navegação | KPIs das ordens: Abertas, Aguarda material, Em curso, Urgentes por fechar, Com prazo ultrapassado, Por debitar ao inquilino, Planos activos. |
| IMO-F-380 | IMO-D-031 | Obras/fiscal/navegação | Detalhe da ordem com cartões Trabalho/Quem paga/Datas, aviso de cancelamento e tabela de necessidades. |
| IMO-F-381 | IMO-D-032 | Obras/fiscal/navegação | Editar necessidades (só POR_PEDIR; os já pedidos ficam intocáveis; quantidade mínima 0,01). |
| IMO-F-382 | IMO-D-033 | Obras/fiscal/navegação | Plano de pedido: serviço/sem artigo/sem armazém => compras; stock global ou por armazém; paraArmazem = min(qtd, stock>=0); paraCompra = resto; textos "Porquê". |
| IMO-F-383 | IMO-D-034 | Obras/fiscal/navegação | Pedir material cria requisição `RQ AAAA/NNNN` (PENDENTE, imo_work_id) e/ou pedido de compra (PENDENTE, parent_type REQUEST), actualiza estado/ids/quantidades por item e põe a ordem em AGUARDA_MATERIAL; rejeita ordens fechadas ou sem necessidades. |
| IMO-F-384 | IMO-D-035 | Obras/fiscal/navegação | Selecção de armazém no pedido recalcula o plano. |
| IMO-F-385 | IMO-D-036 | Obras/fiscal/navegação | Registar entrega de item: valida quantidade>0 e <= pedida; estado RECEBIDO quando completo; cria bem no inventário (origem COMPRA, notas "Recebido na ordem X") quando a ordem tem fracção. |
| IMO-F-386 | IMO-D-037 | Obras/fiscal/navegação | Ordem AGUARDA_MATERIAL passa a EM_CURSO quando todos os itens estão RECEBIDO/CANCELADO. |
| IMO-F-387 | IMO-D-038 | Obras/fiscal/navegação | Concluir: bloqueia se há itens por receber (excepto POR_PEDIR); permite forçar cancelando esses itens; grava data_conclusao = hoje. |
| IMO-F-388 | IMO-D-039 | Obras/fiscal/navegação | Cancelar: exige motivo; grava motivo_cancelamento; não toca no resto. |
| IMO-F-389 | IMO-D-040 | Obras/fiscal/navegação | Auditoria das acções Criar/Editar/Pedir material/Mudar estado/Eliminar com texto de detalhe. |
| IMO-F-390 | IMO-D-041 | Obras/fiscal/navegação | `porDebitar`: obras CONCLUIDA, debitáveis, sem debitado_em (contador e ligação ao débito na renda). |
| IMO-F-391 | IMO-D-042 | Obras/fiscal/navegação | Taxas fiscais por ano em `imo_settings.taxas[ano]` com valores de arranque; DETENCAO_BASE mesclada. |
| IMO-F-392 | IMO-D-043 | Obras/fiscal/navegação | Cálculo da detenção por escalões (0,1% até 5M; 5.000 fixo até 6M; 0,5% sobre excesso de 5M acima; terreno 0,6%); VPT<=0 => "conhecido=false" com motivo. |
| IMO-F-393 | IMO-D-044 | Obras/fiscal/navegação | Agravamento 50% se desocupado >= 12 meses; isenção METADE/TOTAL sobre (imposto+agravamento); total. |
| IMO-F-394 | IMO-D-045 | Obras/fiscal/navegação | Texto do escalão aplicado (formato pt-AO) por imóvel. |
| IMO-F-395 | IMO-D-046 | Obras/fiscal/navegação | Cálculo da desocupação por fracção (sem contrato nunca; contrato vivo; sem data de saída; saída após fim do ano; meses = round(dias/30,4)). |
| IMO-F-396 | IMO-D-047 | Obras/fiscal/navegação | Desocupação do imóvel = nenhuma fracção com contrato; meses = mínimo dos conhecidos. |
| IMO-F-397 | IMO-D-048 | Obras/fiscal/navegação | Mapa da detenção: só imóveis PROPRIO não ALIENADO; totais (total, agravamentos, isenções, entregues, sem VPT); avisos por imóvel (sem VPT, sem matriz, desocupado). |
| IMO-F-398 | IMO-D-049 | Obras/fiscal/navegação | Mapa de retenções do mês: só rendas em documento com IP retido; separação "A entregar à AGT" (posicao INQUILINO) vs "Retido pelos inquilinos"; colunas e totais; documento = doc_number ou "Tesouraria #id". |
| IMO-F-399 | IMO-D-050 | Obras/fiscal/navegação | Mapa de selo do ano: rendas não anuladas, selo encargo SENHORIO, posição SENHORIO, agrupado por contrato (períodos, base, taxa, valor), ordenado por valor desc. |
| IMO-F-400 | IMO-D-051 | Obras/fiscal/navegação | Modelo 1 por imóvel: contratos SENHORIO nos estados activo/terminado/denunciado/suspenso com rendas em documento no ano; apuramento (rendimento, IP liquidado, retido, a pagar = liquidado − retido, selo, detenção); avisos. |
| IMO-F-401 | IMO-D-052 | Obras/fiscal/navegação | Registar entrega (modal) por tipo/ano/período/imóvel com estado, data (<= hoje, obrigatória se Entregue), valor (por omissão apurado), referência, notas; DISPENSADO exige notas; unicidade por tipo+ano+período+imóvel. |
| IMO-F-402 | IMO-D-053 | Obras/fiscal/navegação | Apagar registo de entrega só se não ENTREGUE. |
| IMO-F-403 | IMO-D-054 | Obras/fiscal/navegação | Resumo anual: KPIs, carga fiscal por devedor (5 linhas, sem total), retenções mês a mês, transmissões (compras/vendas, mais/menos-valias). |
| IMO-F-404 | IMO-D-055 | Obras/fiscal/navegação | Avisos fiscais: Modelo 1 do ano anterior em falta (antes/depois de 31 Jan), imóveis sem VPT, retenções por entregar com prazo fim do mês seguinte; máximo de 8 mostrados + "e mais n". |
| IMO-F-405 | IMO-D-056 | Obras/fiscal/navegação | Impressões fiscais: resumo (retrato), detenção (paisagem), retenções (paisagem), Modelo 1 (retrato com assinatura). |
| IMO-F-406 | IMO-D-057 | Obras/fiscal/navegação | Secção imobiliária no relatório anual de contas a partir de `resumoAnual` e `mapaDetencao`. |
| IMO-F-407 | IMO-D-058 | Obras/fiscal/navegação | Catálogo de permissões por ecrã/tarefa (imo_imoveis, imo_ocupacao, imo_fichas, imo_obras, imo_fiscal, e tarefas: imo_imovel_editar, imo_fraccao_editar, imo_estado_mudar, imo_ficha_editar, imo_obra_editar, imo_obra_pedir*, imo_obra_receber, imo_obra_fechar, imo_bens_editar, imo_plano_editar, imo_plano_gerar, imo_fiscal_entregar*, imo_taxas_editar*, imo_sub_debitar*) (* = sensível). |
