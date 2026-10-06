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
