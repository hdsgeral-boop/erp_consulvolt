import type { DiaCalendario, DiaEspaco, Espaco, FichaReserva, ListasParque, MapaParque, ReservaLinha, VagaMapa, Verificacao } from './apiParque';

/** Dados de teste do parqueamento e das áreas comuns (só para os testes dos ecrãs). */
const opc = (pares: [string, string][]) => pares.map(([valor, rotulo]) => ({ valor, rotulo }));

export const LISTAS_PARQUE: ListasParque = {
  park_tipos: opc([['DESCOBERTO', 'Descoberto'], ['COBERTO', 'Coberto'], ['SUBTERRANEO', 'Subterrâneo'], ['BOX', 'Box fechada']]),
  park_portes: [{ valor: 'MOTA', rotulo: 'Mota', ordem: 1 }, { valor: 'LIGEIRO', rotulo: 'Ligeiro', ordem: 2 }, { valor: 'SUV', rotulo: 'SUV / 4×4', ordem: 3 }, { valor: 'PESADO', rotulo: 'Pesado / carrinha', ordem: 4 }],
  park_afectacoes: [{ valor: 'PERMANENTE', rotulo: 'Afecta a uma fracção', ajuda: 'Acessório da fracção.', pede_fraccao: true, arrendavel: true },
    { valor: 'AUTONOMA', rotulo: 'Autónoma', ajuda: 'Arrenda-se sozinha.', pede_fraccao: false, arrendavel: true }, { valor: 'VISITA', rotulo: 'Visitas', ajuda: 'Uso comum.', pede_fraccao: false, arrendavel: false },
    { valor: 'CARGA_DESCARGA', rotulo: 'Cargas e descargas', ajuda: 'Lugar de serviço.', pede_fraccao: false, arrendavel: false }],
  tipos_area: [{ valor: 'SALAO', rotulo: 'Salão de festas', capacidade: true }, { valor: 'GINASIO', rotulo: 'Ginásio', capacidade: true }],
  regimes: [{ valor: 'GRATUITO', rotulo: 'Uso livre', ajuda: 'Entra quem quer.', reserva: false, paga: false }, { valor: 'RESERVA_GRATUITA', rotulo: 'Reserva obrigatória, sem custo', ajuda: 'Marca-se, não se paga.', reserva: true, paga: false },
    { valor: 'RESERVA_PAGA', rotulo: 'Reserva paga', ajuda: 'Marca-se e paga-se.', reserva: true, paga: true }],
  unidades_preco: [{ valor: 'HORA', rotulo: 'por hora', horas: 1 }, { valor: 'FIXO', rotulo: 'valor fixo por reserva', horas: 0 }],
  estados_reserva: opc([['PEDIDA', 'Pedida'], ['APROVADA', 'Aprovada'], ['AGUARDA_PAGAMENTO', 'A aguardar pagamento']]).map((o) => ({ ...o, ocupa: true, viva: true })), transicoes: {},
  estados_caucao: opc([['NAO_APLICA', 'Sem caução'], ['POR_RECEBER', 'Por receber'], ['EM_PODER', 'Em poder da empresa']]), estados_vistoria: opc([['BOM', 'Bom — sem danos'], ['DANIFICADO', 'Com danos']]),
  dias: ['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado'],
};

export const vaga = (o: Partial<VagaMapa>): VagaMapa => ({
  id: 1, codigo: 'PR0001-G101', designacao: 'G-101', piso: -1, andar: 'Cave', afectacao: 'AUTONOMA', tipo: 'SUBTERRANEO', porte: 'LIGEIRO', altura_max: 2.1, tomada: false, distico: null, comprimento: null, largura: null,
  area: 12.5, notas: null, estado: 'DISPONIVEL', renda_referencia: 25000, fracao_id: null, afecta_a: null, incluida_na_renda: null, arrendavel: true, ocupada: false, via: null, contrato_id: null, contrato: null,
  ocupante: null, veiculos: [], conflito_porte: [], ...o,
});

const VAGAS: VagaMapa[] = [
  vaga({ id: 1, designacao: 'G-101', afectacao: 'PERMANENTE', fracao_id: 3, afecta_a: '101', ocupada: true, via: 'FRACCAO', contrato_id: 9, contrato: 'AR 2026/0001', ocupante: 'Inquilino Fictício', tomada: true,
    veiculos: [{ matricula: 'LD-12-34-AB', marca: 'Toyota', modelo: 'Hilux', porte: 'SUV' }], conflito_porte: ['LD-12-34-AB'] }),
  vaga({ id: 2, designacao: 'G-102', codigo: 'PR0001-G102' }),
  vaga({ id: 3, designacao: 'Visitas 1', codigo: 'PR0001-V001', afectacao: 'VISITA', arrendavel: false, altura_max: null }),
];

export const MAPA: MapaParque = {
  pisos: [{ andar: 'Cave', piso: -1, vagas: VAGAS, ocupadas: 1 }], total: 3,
  resumo: { total: 3, arrendaveis: 2, ocupadas: 1, livres: 1, afectas: 1, autonomas: 1, visita: 1, taxa: 50, por_contrato_proprio: 0, por_fraccao: 1, com_tomada: 1, conflitos: 1 },
  avisos: ['1 vaga(s) com veículo maior do que o porte do lugar.'],
};

export const IMOVEL = { id: 7, codigo: 'PR0001', nome: 'Edifício Girassol', papel: 'PROPRIO' };
export const FRACOES = [{ id: 3, imovel_id: 7, codigo: 'PR0001-01', designacao: '101', natureza: 'HABITACIONAL', estado: 'ARRENDADA', ordem: 1, piso: 1, andar: '1.º andar' },
  { id: 4, imovel_id: 7, codigo: 'PR0001-02', designacao: '102', natureza: 'HABITACIONAL', estado: 'DISPONIVEL', ordem: 2, piso: 1, andar: '1.º andar' }];

export const espaco = (o: Partial<Espaco> = {}): Espaco => ({
  id: 5, imovel_id: 7, tipo: 'SALAO', nome: 'Salão de festas', localizacao: 'Cobertura', regime: 'RESERVA_PAGA', capacidade: 50, hora_abre: '08:00', hora_fecha: '22:00', dias_semana: [0, 1, 2, 3, 4, 5, 6], duracao_min: 2, duracao_max: 8,
  antecedencia_min_dias: 3, antecedencia_max_dias: 0, limite_mes_fraccao: 1, exige_aprovacao: false, preco: 4000, preco_unidade: 'HORA', caucao: 20000, prazo_pagamento_horas: 48, cancel_dias: 7, cancel_pct: 50,
  regulamento: 'Devolver limpo, sem música depois das 22h.', regulamento_url: null, activo: true, notas: null, imovel_nome: 'Edifício Girassol', imovel_codigo: 'PR0001', reservas: 2, ...o,
});

export const reserva = (o: Partial<ReservaLinha> = {}): ReservaLinha => ({
  id: 21, espaco_id: 5, imovel_id: 7, fracao_id: 3, contrato_id: 9, terceiro_id: 11, interna: false, data: '2026-03-27', hora_inicio: '14:00', hora_fim: '16:30', pessoas: 20, motivo: 'Aniversário', contacto: null, estado: 'AGUARDA_PAGAMENTO',
  motivo_estado: null, valor: 12000, valor_detalhe: '3 × hora (2.5 h)', caucao: 20000, caucao_recebida: 0, caucao_devolvida: 0, caucao_retida: 0, caucao_motivo: null, divida_na_marcacao: 0, autorizacao_motivo: null, autorizacao_por: null,
  prazo_pagamento: '2026-03-22T10:00:00+01:00', venda_id: null, nota_credito_id: null, nc_pendente: false, espaco_nome: 'Salão de festas', espaco_tipo: 'SALAO', fraccao: '101', reservante: 'Inquilino Fictício', ...o,
});

export const CALENDARIO: DiaCalendario[] = [{ data: '2026-03-27', dia: 'Sexta', reservas: [reserva({}), reserva({ id: 22, hora_inicio: '18:00', hora_fim: '19:00', interna: true, fracao_id: null, fraccao: null, reservante: 'Administração', valor: 0, caucao: 0, estado: 'APROVADA', motivo: 'Assembleia' })] }];

export const ficha = (o: Partial<FichaReserva> = {}): FichaReserva => {
  const { fraccao, ...base } = reserva({});
  void fraccao;   // na ficha a fracção é um objecto

  return {
    ...base,
    espaco: { id: 5, nome: 'Salão de festas', tipo: 'SALAO', regime: 'RESERVA_PAGA', regulamento: 'Devolver limpo, sem música depois das 22h.', regulamento_url: null, cancel_dias: 7, cancel_pct: 50, capacidade: 50 },
    fraccao: { id: 3, designacao: '101', codigo: 'PR0001-01' }, reservante: 'Inquilino Fictício',
    situacao: { valor: 12000, facturado: 0, recebido: 0, por_pagar: 12000, paga: true, pago: false, venda_id: null, documento: null, venda_estado: null, prazo: '2026-03-22T10:00:00+01:00', caducou: false,
      caucao: { devida: 20000, recebida: 0, devolvida: 0, retida: 0, em_poder: 0, por_receber: 20000, estado: 'POR_RECEBER' }, vistoria_antes: null, vistoria_depois: null, reembolso: null },
    historico: [{ id: 1, evento: 'CRIADA', estado_de: null, estado_para: 'AGUARDA_PAGAMENTO', motivo: null, dados: null, em: '2026-03-20T10:00:00+01:00' }], vistorias: [], ...o,
  };
};

export const DIA: DiaEspaco = { espaco_id: 5, data: '2026-03-27', dia: 'Sexta', abre: true, hora_abre: '08:00', hora_fecha: '22:00', ocupadas: [{ id: 22, inicio: '18:00', fim: '19:00', estado: 'APROVADA' }],
  livres: [{ inicio: '08:00', fim: '18:00', horas: 10 }, { inicio: '19:00', fim: '22:00', horas: 3 }], horas_livres: 13, vazio: false };

export const VERIFICACAO_OK: Verificacao = { espaco: { id: 5, nome: 'Salão de festas', tipo: 'SALAO', regime: 'RESERVA_PAGA', imovel_id: 7 }, impedimentos: [], avisos: [], exige_autorizacao: false, divida: 0, contrato_id: 9, terceiro_id: 11,
  valor: '12000.00', caucao: '20000.00', detalhe: '3 × hora (2.5 h)', horas: '2.5' };
