import type { CalendarioPlano, Ficha, FichaOrdem, ListasObras, Necessidade, OrdemLinha, Plano, ResumoObras } from './apiObras';

/** Dados de teste das obras, fichas e planos (só para os testes dos ecrãs). */
const o = (pares: [string, string][]) => pares.map(([chave, rotulo]) => ({ chave, rotulo }));

export const LISTAS_OBRAS: ListasObras = {
  tipos: o([['APETRECHAMENTO', 'Apetrechamento'], ['PREVENTIVA', 'Manutenção preventiva'], ['CORRECTIVA', 'Reparação'], ['MELHORIA', 'Melhoria'], ['VISTORIA', 'Vistoria']]),
  estados: o([['ABERTA', 'Aberta'], ['AGUARDA_MATERIAL', 'Aguarda material'], ['EM_CURSO', 'Em curso'], ['CONCLUIDA', 'Concluída'], ['CANCELADA', 'Cancelada']]),
  prioridades: o([['BAIXA', 'Baixa'], ['NORMAL', 'Normal'], ['ALTA', 'Alta'], ['URGENTE', 'Urgente']]),
  quem_paga: o([['SENHORIO', 'Senhorio (a empresa)'], ['INQUILINO', 'Inquilino'], ['PARTILHADO', 'Partilhado']]),
  estados_item: o([['POR_PEDIR', 'Por pedir'], ['REQUISITADO', 'Requisitado ao armazém'], ['PEDIDO_COMPRA', 'Em pedido de compra'], ['RECEBIDO', 'Recebido'], ['CANCELADO', 'Cancelado']]),
  estados_bem: o([['NOVO', 'Novo'], ['BOM', 'Bom estado'], ['USADO', 'Usado'], ['DANIFICADO', 'Danificado'], ['EM_FALTA', 'Em falta']]),
  periodicidades: o([['MENSAL', 'Mensal'], ['TRIMESTRAL', 'Trimestral'], ['ANUAL', 'Anual'], ['MANUAL', 'Datas à escolha']]),
  tipos_planeaveis: ['PREVENTIVA', 'VISTORIA'],
};

export const RESUMO_OBRAS: ResumoObras = { total: 3, abertas: 1, aguarda_material: 1, em_curso: 0, concluidas: 1, urgentes: 1, por_debitar: 1, atrasadas: 1, por_aprovar: 2, planos_activos: 1, planos_em_falta: 1, planos_por_gerar: 1 };

export const ordem = (x: Partial<OrdemLinha> = {}): OrdemLinha => ({
  id: 31, numero: 'OB 2026/0001', tipo: 'CORRECTIVA', descricao: 'Reparar a canalização da cozinha', imovel_id: 7, fracao_id: 3, prioridade: 'NORMAL', quem_paga: 'INQUILINO', debitavel: true, data_pedido: '2026-03-01',
  data_prevista: '2026-03-10', data_conclusao: null, responsavel: 'Carlos', pedido_por: 'Inquilino', custo_estimado: 30000, ficha_id: null, plano_id: null, plano_data: null, vistoria_reserva_id: null, estado: 'ABERTA',
  motivo_cancelamento: null, aprovada_em: null, aprovada_por: null, debitado_em: null, debitado_renda_id: null, debitado_valor: null, notas: null, imovel_nome: 'Edifício Girassol', imovel_codigo: 'PR0001',
  fracao_designacao: '101', necessidades: 0, por_pedir: 0, em_atraso: true, aprovada: false, por_debitar: false, ...x,
});

export const necessidade = (x: Partial<Necessidade> = {}): Necessidade => ({
  id: 1, ordem_id: 31, produto_id: 11, descricao: null, quantidade: '5.000', estado: 'POR_PEDIR', quantidade_recebida: '0.000', quantidade_armazem: '0.000', quantidade_compra: '0.000', nome: 'CAMA — Cama de casal',
  de_armazem: true, requisicao_numero: null, requisicao_estado: null, pedido_numero: null, pedido_estado: null, falta_receber: '0.000', ...x,
});

export const fichaOrdem = (x: Partial<FichaOrdem> = {}): FichaOrdem => ({
  ...ordem(), necessidades_lista: [], por_pedir: 0, custo_real: { armazem: '0.00', compras: '0.00', total: '0.00' }, aprovada: false, por_debitar: false,
  historico: [{ evento: 'CRIADA', estado_de: null, estado_para: 'ABERTA', motivo: null, em: '2026-03-01T10:00:00+01:00' }], ...x,
});

export const FICHAS: Ficha[] = [
  { id: 2, nome: 'T2 mobilado', tipologia: 'T2', finalidade: 'HABITACAO', activo: true, notas: 'Kit base', n_itens: 2, itens: [
    { id: 1, produto_id: 11, descricao: null, quantidade: 2, obrigatorio: true, nome: 'CAMA — Cama de casal', de_armazem: true }, { id: 2, produto_id: null, descricao: 'Mesa de jantar', quantidade: 1, obrigatorio: false, nome: 'Mesa de jantar', de_armazem: false }] },
  { id: 3, nome: 'Loja', tipologia: null, finalidade: null, activo: false, notas: null, n_itens: 0, itens: [] },
];

export const PLANO: Plano = {
  id: 9, nome: 'Pintura', tipo: 'PREVENTIVA', descricao: 'Pintar a escada', imovel_id: 7, fracao_id: null, periodicidade: 'MENSAL', primeira_data: '2026-01-31', quantas: 4, datas: ['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30'],
  prioridade: 'NORMAL', quem_paga: 'SENHORIO', debitavel: false, responsavel: null, antecedencia_dias: 30, custo_estimado: null, notas: null, activo: true, proxima: '2026-03-31', ultima_geracao: null,
  imovel_nome: 'Edifício Girassol', imovel_codigo: 'PR0001', fracao_designacao: null, n_datas: 4, concluidas: 0, em_falta: 2, por_gerar: 2, proxima_data: '2026-03-31',
};

export const CALENDARIO: CalendarioPlano = {
  plano: PLANO, antecedencia_dias: 30, horizonte: '2026-04-19', geradas: 0, por_gerar: 2, em_falta: 2, concluidas: 0, proxima: '2026-03-31',
  linhas: [{ data: '2026-01-31', estado: 'EM_FALTA', passada: true, ordem_id: null, ordem_numero: null }, { data: '2026-02-28', estado: 'EM_FALTA', passada: true, ordem_id: null, ordem_numero: null },
    { data: '2026-03-31', estado: 'POR_GERAR', passada: false, ordem_id: null, ordem_numero: null }, { data: '2026-04-30', estado: 'POR_GERAR', passada: false, ordem_id: null, ordem_numero: null }],
};
