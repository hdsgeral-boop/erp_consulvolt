import { enviar, obter } from '@/api/cliente';

export type EstadoPlano = 'RASCUNHO' | 'APROVADO' | 'EM_EXECUCAO' | 'ENCERRADO';
export type Semaforo = 'VERDE' | 'AMARELO' | 'VERMELHO' | 'SEM_META' | 'SEM_MEDIDA';

export interface Plano {
  id: number; nome: string; ano_inicio: number; ano_fim: number; missao: string | null; visao: string | null; valores: string | null;
  estado: EstadoPlano; estado_rotulo: string; versao: number; congelado: boolean; em_vigor: boolean;
  aprovado_em: string | null; aprovado_por: string | null; aprovacao_excepcao: string | null; motivo_reabertura: string | null;
}
export interface ResumoPlano { id: number; nome: string; ano_inicio: number; ano_fim: number; estado: EstadoPlano; estado_rotulo: string; versao: number; congelado: boolean; em_vigor: boolean }
export interface Perspectiva { id: number; plano_estrategico_id: number; ordem: number; nome: string; descricao: string | null; cor: string; icone: string }
export interface Objectivo {
  id: number; plano_estrategico_id: number; perspectiva_id: number; objectivo_pai_id: number | null; unidade_organica_id: number | null;
  responsavel_colaborador_id: number | null; responsavel_externo: string | null; nome: string; descricao: string | null;
  horizonte: 'PLANO' | 'TRIMESTRE'; periodo: string | null; periodo_rotulo: string | null; estado: string; estado_rotulo: string; ordem: number;
}
export interface DetalhePlano {
  plano: Plano; perspectivas: Perspectiva[]; objectivos: Objectivo[];
  indicadores_por_objectivo: Record<string, { total: number; activos: number }>;
}

export interface FiltroFonte { campo: string; operador: string; valor: string | null }
export interface FonteModulo { modulo?: string; operacao?: string; campo?: string | null; filtros?: FiltroFonte[] }
export interface Indicador {
  id: number; plano_estrategico_id: number; objectivo_id: number; objectivo: { id: number; nome: string; perspectiva_id: number; horizonte: string } | null;
  nome: string; descricao: string | null; origem: string; origem_rotulo: string; fonte: FonteModulo;
  polaridade: string; tipo: string; formato: string; frequencia: string; agregacao: string | null; unidade_medida: string | null;
  linha_base: string | null; linha_base_periodo: string | null; oculto: boolean;
  dono_colaborador_id: number | null; dono_externo: string | null; prazo_dias: number | null;
  tolerancia_verde: string; tolerancia_amarela: string; ativo: boolean; metas_count: number | null; medicoes_count: number | null;
}

export interface Opcao { id: string; nome: string; disponivel?: boolean }
export interface FonteCatalogo { id: string; nome: string; campos: Opcao[]; filtros: { id: string; nome: string; tipo: 'texto' | 'numero'; opcoes: string[] | null }[] }
export interface Catalogos {
  origens: Opcao[]; polaridades: Opcao[]; tipos: Opcao[]; frequencias: Opcao[]; formatos: Opcao[]; agregacoes: Opcao[]; operacoes: Opcao[]; operadores: Opcao[];
  semaforos: Opcao[]; estados_plano: Opcao[]; estados_objectivo: Opcao[]; horizontes: Opcao[]; fontes_modulo: FonteCatalogo[];
  unidades: { id: number; nome: string; codigo: string | null }[]; colaboradores: { id: number; nome: string }[];
}

export interface MetaPeriodo { periodo: string; valor: string | null; valor_minimo: string | null; nota: string | null }
export interface MetasIndicador {
  indicador_id: number; frequencia: string; polaridade: string; formato: string; oculto: boolean;
  anos: { ano: number; periodos: { chave: string; rotulo: string }[] }[]; metas: MetaPeriodo[];
}
export interface LinhaSerie {
  periodo: string; rotulo: string; meta: string | null; valor: string | null; cumprimento: string | null; semaforo: Semaforo; semaforo_rotulo: string;
  oculto: boolean; nota: string | null; medido_por: string | null; medido_em: string | null;
}
export interface Serie {
  indicador_id: number; ano: number; frequencia: string; formato: string; polaridade: string; oculto: boolean; periodos: LinhaSerie[];
  resumo: Record<string, number | string | null>;
}
export interface ResultadoTeste { periodo: string; rotulo: string; valor: string | null; detalhe: string | null; oculto: boolean }

export const CHAVE_PLANOS = ['estrategia', 'planos'] as const;
export const chavePlano = (id: number) => ['estrategia', 'plano', id] as const;
export const chaveIndicadores = (id: number) => ['estrategia', 'indicadores', id] as const;
export const CHAVE_CATALOGOS = ['estrategia', 'catalogos'] as const;

const base = '/estrategia';
export const listarPlanos = async () => (await obter<{ planos: ResumoPlano[] }>(`${base}/planos`)).planos;
export const obterPlano = (id: number) => obter<DetalhePlano>(`${base}/planos/${id}`);
export const obterCatalogos = () => obter<Catalogos>(`${base}/catalogos`);
export const gravarPlano = async (id: number | null, d: Record<string, unknown>) => (await enviar<Plano>(id ? 'put' : 'post', id ? `${base}/planos/${id}` : `${base}/planos`, d)).dados;
export const apagarPlano = (id: number) => enviar<null>('delete', `${base}/planos/${id}`);
export const transicaoPlano = async (id: number, accao: 'aprovar' | 'iniciar-execucao' | 'reabrir' | 'encerrar', corpo: { motivo?: string; excepcao?: string } = {}) =>
  (await enviar<Plano>('post', `${base}/planos/${id}/${accao}`, corpo)).dados;

export const gravarPerspectiva = async (plano: number, id: number | null, d: Record<string, unknown>) =>
  (await enviar<Perspectiva>(id ? 'put' : 'post', `${base}/planos/${plano}/perspectivas${id ? `/${id}` : ''}`, d)).dados;
export const apagarPerspectiva = (plano: number, id: number, motivo?: string) => enviar<null>('delete', `${base}/planos/${plano}/perspectivas/${id}`, { motivo_excepcao: motivo });
export const gravarObjectivo = async (plano: number, id: number | null, d: Record<string, unknown>) =>
  (await enviar<Objectivo>(id ? 'put' : 'post', `${base}/planos/${plano}/objectivos${id ? `/${id}` : ''}`, d)).dados;
export const apagarObjectivo = (plano: number, id: number, motivo?: string) => enviar<null>('delete', `${base}/planos/${plano}/objectivos/${id}`, { motivo_excepcao: motivo });

export const listarIndicadores = async (plano: number) => (await obter<{ indicadores: Indicador[] }>(`${base}/planos/${plano}/indicadores`)).indicadores;
export const gravarIndicador = async (plano: number, id: number | null, d: Record<string, unknown>) =>
  (await enviar<Indicador>(id ? 'put' : 'post', `${base}/planos/${plano}/indicadores${id ? `/${id}` : ''}`, d)).dados;
export const apagarIndicador = (plano: number, id: number, motivo?: string) => enviar<null>('delete', `${base}/planos/${plano}/indicadores/${id}`, { motivo_excepcao: motivo });
export const testarIndicador = async (id: number) => (await enviar<ResultadoTeste>('post', `${base}/indicadores/${id}/testar`, {})).dados;
export const obterMetas = (id: number) => obter<MetasIndicador>(`${base}/indicadores/${id}/metas`);
export const gravarMetas = async (id: number, metas: { periodo: string; valor: string | null }[], motivo?: string) =>
  (await enviar<MetasIndicador>('put', `${base}/indicadores/${id}/metas`, { metas, motivo_excepcao: motivo })).dados;
export const obterSerie = (id: number, ano: number) => obter<Serie>(`${base}/indicadores/${id}/serie`, { ano });
export const entregarValor = async (id: number, d: { periodo: string; valor: string; nota?: string }) => (await enviar<LinhaSerie>('post', `${base}/indicadores/${id}/medicoes`, d)).dados;
