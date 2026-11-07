import { enviar, obter } from '@/api/cliente';
import type { Opcao } from './api';

export type Afectacao = 'PERMANENTE' | 'AUTONOMA' | 'VISITA' | 'CARGA_DESCARGA';
export type EstadoReserva = 'PEDIDA' | 'APROVADA' | 'AGUARDA_PAGAMENTO' | 'CONFIRMADA' | 'REALIZADA' | 'FALTOU' | 'CANCELADA' | 'RECUSADA';
export type Regime = 'GRATUITO' | 'RESERVA_GRATUITA' | 'RESERVA_PAGA';

export interface ListasParque {
  park_tipos: (Opcao & { ajuda?: string })[]; park_portes: (Opcao & { ordem: number })[]; park_afectacoes: (Opcao & { ajuda: string; pede_fraccao: boolean; arrendavel: boolean })[];
  tipos_area: (Opcao & { capacidade: boolean })[]; regimes: (Opcao & { ajuda: string; reserva: boolean; paga: boolean })[]; unidades_preco: (Opcao & { horas: number })[];
  estados_reserva: (Opcao & { ocupa: boolean; viva: boolean })[]; transicoes: Record<string, string[]>; estados_caucao: Opcao[]; estados_vistoria: Opcao[]; dias: string[];
}

export interface VeiculoMapa { matricula: string; marca: string | null; modelo: string | null; porte: string | null }
export interface VagaMapa {
  id: number; codigo: string; designacao: string; piso: number | null; andar: string; afectacao: Afectacao; tipo: string | null; porte: string | null; altura_max: number | null; tomada: boolean;
  distico: string | null; comprimento: number | null; largura: number | null; area: number | null; notas: string | null; estado: string; renda_referencia: number | null; fracao_id: number | null; afecta_a: string | null; incluida_na_renda: boolean | null; arrendavel: boolean;
  ocupada: boolean; via: 'PROPRIO' | 'FRACCAO' | null; contrato_id: number | null; contrato: string | null; ocupante: string | null; veiculos: VeiculoMapa[]; conflito_porte: string[];
}
export interface PisoMapa { andar: string; piso: number | null; vagas: VagaMapa[]; ocupadas: number }
export interface ResumoParque {
  total: number; arrendaveis: number; ocupadas: number; livres: number; afectas: number; autonomas: number; visita: number; taxa: number | null; por_contrato_proprio: number;
  por_fraccao: number; com_tomada: number; conflitos: number;
}
export interface MapaParque { pisos: PisoMapa[]; total: number; resumo: ResumoParque | null; avisos: string[] }

export interface VeiculoContrato { id?: number; matricula: string; marca?: string | null; modelo?: string | null; cor?: string | null; porte?: string | null; condutor?: string | null; distico?: string | null; vaga_id?: number | null }
export interface VeiculosDoContrato { veiculos: VeiculoContrato[]; vagas: { id: number; designacao: string; afecta: boolean; porte: string | null }[]; lugares: number }
export interface EncargoProposto { fracao_id: number; vaga_id: number; origem: 'PARQUE'; designacao: string; valor: number; incluido_na_renda: boolean | null; detalhe: string }

export interface Espaco {
  id: number; imovel_id: number; tipo: string; nome: string; localizacao: string | null; regime: Regime; capacidade: number | null; hora_abre: string; hora_fecha: string; dias_semana: number[];
  duracao_min: number | null; duracao_max: number | null; antecedencia_min_dias: number; antecedencia_max_dias: number; limite_mes_fraccao: number | null; exige_aprovacao: boolean; preco: number;
  preco_unidade: string; caucao: number; prazo_pagamento_horas: number; cancel_dias: number; cancel_pct: number; regulamento: string | null; regulamento_url: string | null; activo: boolean;
  notas: string | null; imovel_nome: string | null; imovel_codigo: string | null; reservas: number;
}

export interface ReservaLinha {
  id: number; espaco_id: number; imovel_id: number; fracao_id: number | null; contrato_id: number | null; terceiro_id: number | null; interna: boolean; data: string; hora_inicio: string; hora_fim: string;
  pessoas: number | null; motivo: string | null; contacto: string | null; estado: EstadoReserva; motivo_estado: string | null; valor: number; valor_detalhe: string | null; caucao: number; caucao_recebida: number;
  caucao_devolvida: number; caucao_retida: number; caucao_motivo: string | null; divida_na_marcacao: number; autorizacao_motivo: string | null; autorizacao_por: string | null; prazo_pagamento: string | null; venda_id: number | null;
  nota_credito_id: number | null; nc_pendente: boolean; espaco_nome?: string | null; espaco_tipo?: string | null; fraccao?: string | null; reservante?: string;
}
export interface DiaCalendario { data: string; dia: string; reservas: ReservaLinha[] }

export interface SituacaoReserva {
  valor: number; facturado: number; recebido: number; por_pagar: number; paga: boolean; pago: boolean; venda_id: number | null; documento: string | null; venda_estado: string | null; prazo: string | null; caducou: boolean;
  caucao: { devida: number; recebida: number; devolvida: number; retida: number; em_poder: number; por_receber: number; estado: string };
  vistoria_antes: Vistoria | null; vistoria_depois: Vistoria | null;
  reembolso: { previsto: number; retido: number; pct: number | null; nc_pendente: boolean; nota_credito_id: number | null } | null;
}
export interface Vistoria { id: number; estado: string; observacoes: string | null; url: string | null; data: string; custo_danos: number | null; quando?: string }
export interface EventoReserva { id: number; evento: string; estado_de: string | null; estado_para: string | null; motivo: string | null; dados: Record<string, unknown> | null; em: string }
export interface FichaReserva extends Omit<ReservaLinha, 'fraccao'> {
  espaco: { id: number; nome: string; tipo: string; regime: Regime; regulamento: string | null; regulamento_url: string | null; cancel_dias: number; cancel_pct: number; capacidade: number | null };
  fraccao: { id: number; designacao: string; codigo: string } | null; reservante: string; situacao: SituacaoReserva; historico: EventoReserva[]; vistorias: Vistoria[];
}

export interface Verificacao {
  espaco: { id: number; nome: string; tipo: string; regime: Regime; imovel_id: number } | null; impedimentos: string[]; avisos: string[]; exige_autorizacao: boolean; divida: number;
  contrato_id: number | null; terceiro_id: number | null; valor: string; caucao: string; detalhe: string; horas: string | null;
}
export interface Previsao {
  faltam_dias: number; recebido: number; pct: number; devolve: number; retem: number; caucao_a_devolver: number; explicacao: string; facturada: boolean; devolve_aplicado?: number; retem_aplicado?: number;
}
export interface DiaEspaco {
  espaco_id: number; data: string; dia: string; abre: boolean; hora_abre: string; hora_fecha: string; ocupadas: { id: number; inicio: string; fim: string; estado: string }[];
  livres: { inicio: string; fim: string; horas: number }[]; horas_livres: number; vazio: boolean;
}
export interface ResumoAreas { espacos: number; activos: number; reservaveis: number; pagos: number; por_aprovar: number; aguarda_pagamento: number; futuras: number; hoje: number; por_fechar: number; com_divida: number }
export interface MovimentoCaucaoReserva { id: number; tipo: 'RECEBIDA' | 'DEVOLVIDA' | 'RETIDA'; valor: number; data: string; conta_financeira: string | null; motivo: string | null; estado: 'VALIDO' | 'ANULADO'; venda_id: number | null }

export const CHAVES_PK = {
  listas: ['imobiliario', 'parque', 'listas'] as const,
  mapa: (imovel: number, texto: string) => ['imobiliario', 'parque', 'mapa', imovel, texto] as const,
  veiculos: (contrato: number) => ['imobiliario', 'parque', 'veiculos', contrato] as const,
  espacos: ['imobiliario', 'areas', 'espacos'] as const,
  resumo: (imovel: number | null) => ['imobiliario', 'areas', 'resumo', imovel] as const,
  avisos: (imovel: number | null) => ['imobiliario', 'areas', 'avisos', imovel] as const,
  calendario: (imovel: number | null, espaco: number | null, de: string, ate: string) => ['imobiliario', 'areas', 'calendario', imovel, espaco, de, ate] as const,
  dia: (espaco: number, data: string) => ['imobiliario', 'areas', 'dia', espaco, data] as const,
  reserva: (id: number) => ['imobiliario', 'areas', 'reserva', id] as const,
  fracoesDoImovel: (imovel: number) => ['imobiliario', 'fracoes', imovel] as const,
};

export const obterListasParque = () => obter<ListasParque>('/imobiliario/parque/listas');
export const obterMapaParque = (imovel: number, texto: string) => obter<MapaParque>(`/imobiliario/imoveis/${imovel}/parque/mapa`, texto ? { texto } : undefined);
export const gerarVagas = (imovel: number, dados: Record<string, unknown>) => enviar<{ criadas: number; repetidas: number }>('post', `/imobiliario/imoveis/${imovel}/parque/gerar`, dados);
export const criarVaga = (imovel: number, dados: Record<string, unknown>) => enviar<{ id: number }>('post', `/imobiliario/imoveis/${imovel}/parque/vagas`, dados);
export const actualizarVaga = (id: number, dados: Record<string, unknown>) => enviar<{ id: number }>('put', `/imobiliario/parque/vagas/${id}`, dados);
export const eliminarVaga = (id: number) => enviar<null>('delete', `/imobiliario/parque/vagas/${id}`);
export const afectarVagas = (dados: { fracao_id: number; vaga_ids: number[]; incluida_na_renda?: boolean | null; reatribuir?: boolean }) => enviar<{ afectadas: number; avisos: string[] }>('post', '/imobiliario/parque/afectar', dados);
export const libertarVagas = (vaga_ids: number[]) => enviar<{ libertadas: number; avisos: string[] }>('post', '/imobiliario/parque/libertar', { vaga_ids });
export const obterVeiculos = (contrato: number) => obter<VeiculosDoContrato>(`/imobiliario/contratos/${contrato}/veiculos`);
export const gravarVeiculos = (contrato: number, veiculos: VeiculoContrato[], confirmar_repetidas = false) =>
  enviar<{ gravados: number; repetidas: unknown[] }>('put', `/imobiliario/contratos/${contrato}/veiculos`, { veiculos, confirmar_repetidas });
export const obterEncargosPropostos = (fracaoIds: number[]) => obter<EncargoProposto[]>('/imobiliario/parque/encargos-propostos', { fracao_ids: fracaoIds.join(',') });

export const listarEspacos = () => obter<Espaco[]>('/imobiliario/areas/espacos');
export const gravarEspaco = (dados: Record<string, unknown>, id?: number) => enviar<Espaco>(id ? 'put' : 'post', id ? `/imobiliario/areas/espacos/${id}` : '/imobiliario/areas/espacos', dados);
export const activarEspaco = (id: number, activo: boolean) => enviar<Espaco>('post', `/imobiliario/areas/espacos/${id}/activo`, { activo });
export const eliminarEspaco = (id: number) => enviar<null>('delete', `/imobiliario/areas/espacos/${id}`);
export const obterResumoAreas = (imovel: number | null) => obter<ResumoAreas>('/imobiliario/areas/resumo', imovel ? { imovel_id: imovel } : undefined);
export const obterAvisosAreas = (imovel: number | null) => obter<string[]>('/imobiliario/areas/avisos', imovel ? { imovel_id: imovel } : undefined);
export const obterAvisosFinanceiros = (imovel: number | null) => obter<string[]>('/imobiliario/reservas/avisos', imovel ? { imovel_id: imovel } : undefined);
export const obterCalendario = (imovel: number | null, espaco: number | null, de: string, ate: string) =>
  obter<DiaCalendario[]>('/imobiliario/areas/calendario', { ...(imovel ? { imovel_id: imovel } : {}), ...(espaco ? { espaco_id: espaco } : {}), de, ate });
export const obterDia = (espaco: number, data: string) => obter<DiaEspaco>(`/imobiliario/areas/espacos/${espaco}/dia`, { data });

export const verificarReserva = (q: { espaco_id: number; fracao_id?: number | null; data?: string; hora_inicio?: string; hora_fim?: string; excepto?: number | null; pessoas?: number | null }) =>
  obter<Verificacao>('/imobiliario/reservas/verificar', Object.fromEntries(Object.entries(q).filter(([, v]) => v !== null && v !== undefined && v !== '')));
export const obterReserva = (id: number) => obter<FichaReserva>(`/imobiliario/reservas/${id}`);
export const gravarReserva = (dados: Record<string, unknown>, id?: number) => enviar<FichaReserva>(id ? 'put' : 'post', id ? `/imobiliario/reservas/${id}` : '/imobiliario/reservas', dados);
export const apagarReserva = (id: number) => enviar<null>('delete', `/imobiliario/reservas/${id}`);
export const mudarEstadoReserva = (id: number, estado: EstadoReserva, motivo?: string) => enviar<FichaReserva>('post', `/imobiliario/reservas/${id}/estado`, { estado, motivo });
export const obterPrevisao = (id: number) => obter<Previsao>(`/imobiliario/reservas/${id}/previsao-cancelamento`);
export const cancelarReserva = (id: number, motivo: string, devolver?: number) => enviar<{ previsao: Previsao; reserva: FichaReserva }>('post', `/imobiliario/reservas/${id}/cancelar`, { motivo, ...(devolver !== undefined ? { devolver } : {}) });
export const facturarReserva = (id: number) => enviar<{ numero_documento: string; repetida: boolean; reserva: FichaReserva }>('post', `/imobiliario/reservas/${id}/facturar`, {});
export const receberReserva = (id: number, dados: { valor?: number; caucao?: number; data?: string; meio_pagamento?: string; conta_financeira: string; chave?: string }) =>
  enviar<{ recibo_id: number | null; reserva: FichaReserva }>('post', `/imobiliario/reservas/${id}/receber`, dados);
export const registarVistoria = (id: number, dados: { quando: string; estado: string; observacoes?: string; url?: string; data?: string; custo_danos?: number }) => enviar<FichaReserva>('post', `/imobiliario/reservas/${id}/vistoria`, dados);
export const movimentarCaucaoReserva = (id: number, dados: { tipo: string; valor: number; data?: string; conta_financeira?: string; motivo?: string; chave: string }) =>
  enviar<{ movimento: MovimentoCaucaoReserva; reserva: FichaReserva }>('post', `/imobiliario/reservas/${id}/caucao`, dados);
export const caducarReservas = (imovel: number | null) => enviar<{ caducadas: number; confirmadas: number; parciais: number; falhas: number }>('post', '/imobiliario/reservas/caducar', imovel ? { imovel_id: imovel } : {});
