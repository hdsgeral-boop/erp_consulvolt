/** Modos de vista das listas («Linhas» / «Grade»): TabelaComModos (listas locais), GradeCartoes, AlternarVista e useModoVista. */
export { TabelaComModos, type PropsTabelaComModos, type PropsVistaLista } from './TabelaComModos';
export { GradeCartoes, chaveLinha, type OpcoesCartao, type PropsGradeCartoes } from './GradeCartoes';
export { AlternarVista } from './AlternarVista';
export {
  useModoVista,
  usePreferenciasVista,
  chaveEcraVista,
  normalizar as normalizarPreferenciasVista,
  reporPreferenciasVistaLocal,
  NOME_PREFERENCIA_VISTAS,
  CHAVE_LOCAL_VISTAS,
  type ModoVista,
  type OmissaoVista,
  type PreferenciasVista,
  type EstadoModoVista,
  type OpcoesModoVista,
} from './preferenciaVista';
export { derivarEstrutura, conteudoCelula, textoSimples, type ColunaVista, type ExtrasColunaVista, type EstruturaCartao } from './derivarCartao';

/** Padrões das listas do sistema anterior (ver README_PADROES_LEGADO.md). */
export {
  TabelaLegado,
  paginacaoLegado,
  OPCOES_TAMANHO_PAGINA,
  TAMANHO_PAGINA_LEGADO,
  type PropsTabelaLegado,
  type PropsPadroesLista,
  type ImpressaoLista,
  type OpcoesSeleccao,
  type ServidorLista,
} from './TabelaLegado';
export { AccoesLinha, BotaoAccao, IconeLegado, nomeIconeFa, type AccaoLinha, type ConfirmacaoAccao, type VarianteAccao } from './AccoesLinha';
export { BarraSeleccao, BotaoEditarEmMassa, type AccaoMassa, type OpcoesEditarEmMassa, type PropsBarraSeleccao } from './BarraSeleccao';
export { CartaoKpi, GrelhaKpi, CORES_KPI, fundoTranslucido, type PropsCartaoKpi, type PosicaoBarraKpi } from './CartaoKpi';
export { EstadoVazio, type PropsEstadoVazio } from './EstadoVazio';
export { FiltroColuna, BotaoFiltroPersonalizado, ModalFiltroPersonalizado, type ColunaDisponivel } from './FiltrosColuna';
export {
  aplicarFiltros,
  passaCondicao,
  normalizarTexto,
  normalizarFiltroPersonalizado,
  OPERADORES_FILTRO,
  type OperadorFiltro,
  type CondicaoFiltro,
  type CondicaoPersonalizada,
  type FiltroPersonalizado,
  type EstadoFiltros,
} from './filtrosLista';
export {
  colunasParaImpressao,
  brutoConsistente,
  calcularTotal,
  conteudoTotal,
  chaveColuna,
  type ColunaLegado,
  type ExtrasColunaLegado,
  type ExtrasColunaImpressao,
  type TotalColuna,
} from './colunasTabela';
export { useColunasEscolhidas, reporPreferenciasColunasLocal, NOME_PREFERENCIA_COLUNAS, CHAVE_LOCAL_COLUNAS } from './preferenciaColunas';
