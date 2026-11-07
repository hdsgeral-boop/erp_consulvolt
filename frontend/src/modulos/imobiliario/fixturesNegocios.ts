import type { AvisoNegocio, FichaNegocio, ListasNegocios, Negocio, ResumoNegocios } from './apiNegocios';

/** Dados de teste dos negócios de compra e venda (só para os testes dos ecrãs). */
const o = (pares: [string, string][]) => pares.map(([chave, rotulo]) => ({ chave, rotulo }));

export const LISTAS_NEGOCIOS: ListasNegocios = {
  tipos: o([['COMPRA', 'Compra (a empresa adquire)'], ['VENDA', 'Venda (a empresa aliena)']]),
  fases: o([['NEGOCIACAO', 'Em negociação'], ['PROMESSA', 'Promessa assinada'], ['ESCRITURA', 'Escritura feita'], ['REGISTADO', 'Registado na Conservatória'], ['CONCLUIDO', 'Concluído'], ['CANCELADO', 'Cancelado']]),
  tipos_custo: [{ chave: 'MEDIACAO', rotulo: 'Comissão de mediação', capitaliza: true }, { chave: 'OUTRO', rotulo: 'Outro encargo', capitaliza: false }],
  isencoes: o([['NAO', 'Sem isenção'], ['METADE', 'Redução de 50% (OGE)'], ['TOTAL', 'Isento (OGE)']]),
  finalidades: o([['HABITACAO', 'Habitação'], ['COMERCIO', 'Comércio']]),
  destinos_contratos: o([['TRANSMITE', 'Transmitem-se ao comprador (a empresa deixa de os gerir)'], ['MANTEM', 'Mantêm-se nesta empresa (a venda não abrange o arrendamento)']]),
  tipos_pagamento: o([['SINAL', 'Sinal'], ['PRESTACAO', 'Prestação'], ['SALDO', 'Saldo']]),
};

export const RESUMO_NEGOCIOS: ResumoNegocios = {
  ano: 2026, compras: 1, investido: 31107000, vendas: 2, encaixado: 70000000, mais_valias: 13700000, menos_valias: -4000000, impostos: 1000000, impostos_compras: 807000, impostos_vendas: 193000,
  sinais_em_curso: 3000000, total: 4, abertos: 2, por_aprovar: 1, abates_pendentes: 1,
};

export const AVISOS_NEGOCIOS: AvisoNegocio[] = [{ tipo: 'ABATE_PENDENTE', texto: 'VD 2026/0001: venda concluída e ainda sem abate do bem nem lançamento da alienação.', negocio_id: 21 }];

export const negocio = (x: Partial<Negocio> = {}): Negocio => ({
  id: 21, numero: 'CP 2026/0001', tipo: 'COMPRA', estado: 'NEGOCIACAO', imovel_id: 7, fracao_id: null, terceiro_id: 5, preco: 30000000, vpt: 35000000, sinal: 3000000, finalidade: 'HABITACAO', isencao: 'NAO',
  data_promessa: null, data_escritura: null, data_registo: null, notario: null, base: 35000000, base_e_vpt: true, ip_taxa: 2, ip_valor: 700000, selo_pct: 0.3, selo_valor: 105000, escritura_valor: 2000,
  total_impostos: 807000, impostos_ano: 2026, impostos_fixados_em: null, aprovado_em: null, aprovado_por: null, motivo_cancelamento: null, cancelado_em: null, contratos_destino: null, concluido_em: null,
  custo_aquisicao: null, ativo_id: null, valor_contabilistico: null, mais_valia: null, resultado_contabilistico: null, quota: null, abate_parcial: false, venda_id: null, lancamento_compra: null,
  lancamento_venda: null, abatido_em: null, notas: null, imovel_nome: 'Edifício Girassol', imovel_codigo: 'PR0001', imovel_papel: 'PROPRIO', fracao_designacao: null, terceiro_nome: 'Vendedor Fictício', terceiro_nif: '5000000555',
  pago_total: 0, ...x,
});

export const fichaNegocio = (x: Partial<FichaNegocio> = {}): FichaNegocio => ({
  ...negocio(), aprovado: false, custos: [], pagamentos: [], historico: [{ evento: 'CRIADO', estado_de: null, estado_para: 'NEGOCIACAO', motivo: null, em: '2026-03-20T10:00:00+01:00' }], pago_total: 0, por_liquidar: 30000000,
  fases_seguintes: ['PROMESSA', 'ESCRITURA', 'REGISTADO'], contratos_em_vigor: [],
  custo_aquisicao_calculado: { preco: '30000000.00', impostos: '807000.00', encargos: '300000.00', total: '31107000.00', gasto_periodo: '50000.00' }, resultado_venda: null, faltas: ['Aprove o negócio antes de avançar.', 'Indique a data da promessa.'], ...x,
});
