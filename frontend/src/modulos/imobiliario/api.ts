import { enviar, obter } from '@/api/cliente';

export type PapelImovel = 'PROPRIO' | 'ARRENDADO' | 'MEDIACAO';
export type EstadoFracao = 'DISPONIVEL' | 'RESERVADA' | 'ARRENDADA' | 'VENDIDA' | 'EM_OBRA' | 'INDISPONIVEL';

export interface Opcao { valor: string; rotulo: string; ocupa?: boolean; conta?: boolean; pisos?: boolean; habitacoes?: boolean; exige_proprietario?: boolean }

export interface Listas {
  papeis: Opcao[]; tipos_imovel: Opcao[]; naturezas: Opcao[]; estados_fracao: Opcao[]; tipologias: Opcao[]; finalidades: Opcao[]; mobilia: Opcao[];
  estados_imovel: Opcao[]; isencoes_detencao: Opcao[]; esquemas: Opcao[]; tipos_documento: Opcao[]; provincias: string[];
  transicoes: Record<string, string[]>; naturezas_activas: string[];
}

export interface DocumentoLigado { tipo: string; nome: string; url: string; data: string }

export interface Imovel {
  id: number; codigo: string; nome: string; tipo: string; papel: PapelImovel; n_pisos: number; piso_inicial: number; n_habitacoes: number;
  proprietario_terceiro_id: number | null; ativo_edificio_id: number | null; morada: string | null; municipio: string | null; provincia: string | null;
  coordenadas: string | null; matriz_predial: string | null; registo_predial: string | null; licenca_utilizacao: string | null;
  area_terreno: number; area_bruta: number; area_util: number; valor_patrimonial: number | null; valor_patrimonial_data: string | null;
  isencao_detencao: string; valor_aquisicao: number | null; data_aquisicao: string | null; estado: string; documentos: DocumentoLigado[]; notas: string | null;
  proprietario_nome: string | null; total_fracoes: number; ocupadas: number; livres: number; taxa_ocupacao: number | null; area_fracoes: number;
  renda_referencia: number; acessorios: number;
  avisos?: string[]; edificio?: { id: number; codigo: string; descricao: string } | null;
}

export interface Fracao {
  id: number; imovel_id: number; codigo: string; designacao: string; natureza: string; porta_id: number | null; bloco: string | null; andar: string | null;
  numero: string | null; piso: number | null; tipologia: string; finalidade: string; area_bruta: number; area_util: number; quartos: number | null;
  casas_banho: number | null; mobilada: string; estado: EstadoFracao; estado_desde: string | null; estado_motivo: string | null; matriz_predial: string | null;
  valor_patrimonial: number | null; renda_referencia: number | null; venda_referencia: number | null; documentos: DocumentoLigado[]; ordem: number; notas: string | null;
  park_afectacao?: string | null;
}

export interface HistoricoEstado { id: number; estado_de: EstadoFracao | null; estado_para: EstadoFracao; motivo: string | null; utilizador_id: number | null; em: string }
export interface FichaFracao extends Fracao {
  imovel: { id: number; codigo: string; nome: string; papel: PapelImovel; matriz_predial: string | null } | null;
  porta: { id: number; nome: string | null; numero: string | null } | null;
  historico: HistoricoEstado[];
}

export interface Resumo {
  imoveis: number; unidades: number; ocupadas: number; taxa_ocupacao: number | null; area_bruta: number; vagas: number; arrecadacoes: number;
  por_estado: Record<string, number>; por_papel: Record<string, number>;
}

export interface EdificioLigavel { id: number; codigo: string | null; descricao: string | null; propriedade: string; regime_alheio: string | null; ligado_ao_imovel: string | null }
export interface TerceiroSimples { id: number; nome: string; nif: string | null }

export interface ModeloDocumento { id: number; tipo: string; nome: string; assunto: string | null; corpo: string; activo: boolean; sistema: boolean; versao: number }
export interface CatalogoModelos { tipos: Record<string, string>; variaveis: { chave: string; rotulo: string }[] }
export interface PreVisualizacao { corpo: string; assunto: string; faltas: string[]; invalidas: string[] }

export interface Taxas {
  ano: number; origem: 'GRAVADA' | 'HERDADA' | 'BASE'; herdada_de: number | null; taxas: Record<string, number>; ip_efectivo: number;
  estado_validacao: 'POR_VALIDAR' | 'VALIDADA'; validada_por: string | null; validada_em: string | null; validacao_nota: string | null; aviso: string; anos_gravados: number[];
}

export const CHAVES = {
  listas: ['imobiliario', 'listas'] as const,
  resumo: ['imobiliario', 'resumo'] as const,
  imoveis: ['imobiliario', 'imoveis'] as const,
  fracoes: (imovel: number) => ['imobiliario', 'fracoes', imovel] as const,
  fichaFracao: (id: number) => ['imobiliario', 'ficha-fracao', id] as const,
  modelos: ['imobiliario', 'modelos'] as const,
  catalogoModelos: ['imobiliario', 'catalogo-modelos'] as const,
  taxas: (ano: number) => ['imobiliario', 'taxas', ano] as const,
};

export const obterListas = () => obter<Listas>('/imobiliario/listas');
export const obterResumo = () => obter<Resumo>('/imobiliario/resumo');
export const listarImoveis = () => obter<Imovel[]>('/imobiliario/imoveis');
export const obterImovel = (id: number) => obter<Imovel>(`/imobiliario/imoveis/${id}`);
export const listarEdificios = () => obter<EdificioLigavel[]>('/imobiliario/edificios');
export const listarTerceiros = (texto?: string) => obter<TerceiroSimples[]>('/imobiliario/terceiros', texto ? { texto } : undefined);
export const gravarImovel = async (dados: Partial<Imovel>, id?: number) =>
  (await enviar<Imovel>(id ? 'put' : 'post', id ? `/imobiliario/imoveis/${id}` : '/imobiliario/imoveis', dados)).dados;
export const eliminarImovel = (id: number) => enviar<null>('delete', `/imobiliario/imoveis/${id}`);

export const listarFracoes = (imovel: number) => obter<Fracao[]>(`/imobiliario/imoveis/${imovel}/fracoes`);
export const obterFichaFracao = (id: number) => obter<FichaFracao>(`/imobiliario/fracoes/${id}`);
export const gravarFracao = async (dados: Partial<Fracao>, imovel: number, id?: number) =>
  (await enviar<FichaFracao>(id ? 'put' : 'post', id ? `/imobiliario/fracoes/${id}` : `/imobiliario/imoveis/${imovel}/fracoes`, dados)).dados;
export const eliminarFracao = (id: number) => enviar<null>('delete', `/imobiliario/fracoes/${id}`);
export const mudarEstadoFracao = async (id: number, estado: string, motivo: string) => (await enviar<FichaFracao>('post', `/imobiliario/fracoes/${id}/estado`, { estado, motivo })).dados;
export const gerarFracoes = async (imovel: number, dados: Record<string, unknown>) => (await enviar<{ criadas: number; repetidas: number }>('post', `/imobiliario/imoveis/${imovel}/fracoes/gerar`, dados));
export const criarFracaoUnica = (imovel: number) => enviar<FichaFracao>('post', `/imobiliario/imoveis/${imovel}/fracoes/unica`);
export const importarPortas = (imovel: number) => enviar<{ criadas: number; total: number }>('post', `/imobiliario/imoveis/${imovel}/fracoes/importar-portas`);

export const listarModelos = () => obter<ModeloDocumento[]>('/imobiliario/modelos');
export const obterCatalogoModelos = () => obter<CatalogoModelos>('/imobiliario/modelos/catalogo');
export const gravarModelo = async (dados: Partial<ModeloDocumento>, id?: number) =>
  (await enviar<ModeloDocumento>(id ? 'put' : 'post', id ? `/imobiliario/modelos/${id}` : '/imobiliario/modelos', dados)).dados;
export const eliminarModelo = (id: number) => enviar<null>('delete', `/imobiliario/modelos/${id}`);
export const importarModelosPadrao = () => enviar<{ importados: number }>('post', '/imobiliario/modelos/importar-padrao');
export const preVisualizarModelo = async (dados: { assunto?: string; corpo: string; valores?: Record<string, string> }) =>
  (await enviar<PreVisualizacao>('post', '/imobiliario/modelos/pre-visualizar', dados)).dados;

export const obterTaxas = (ano: number) => obter<Taxas>('/imobiliario/taxas', { ano });
export const gravarTaxas = async (ano: number, dados: Record<string, number | null>) => (await enviar<Taxas>('put', `/imobiliario/taxas/${ano}`, dados)).dados;
export const validarTaxas = async (ano: number, nota: string) => (await enviar<Taxas>('post', `/imobiliario/taxas/${ano}/validar`, { nota })).dados;
