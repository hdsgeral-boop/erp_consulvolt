/** Repartição por unidades de negócio (Fase C, ADR-071): critérios, alocação no objecto, UN/CC por linha, repartir linha e mapas por UN. */
export { AlocacaoUN, regraAlocacao, type PropsAlocacaoUN } from './AlocacaoUN';
export { CamposLinhaUNCC, unccDaLinha, useLinhaUNCCDisponivel } from './CamposLinhaUNCC';
export { CriteriosReparticao, corpoCriterio } from './CriteriosReparticao';
export {
  CartaoAlocacaoUN,
  ModalAlocacaoUN,
  ResumoAlocacaoUN,
  SEM_UNCC_LINHA,
  bloqueioAlocacao,
  textoCCLinha,
  textoUNLinha,
  useColunasUNCC,
  type LinhaComUNCC,
  type PropsModalAlocacaoUN,
  type ResumoAlocacao,
} from './DetalheReparticao';
export { ModalRepartirLinha, type PropsModalRepartirLinha } from './ModalRepartirLinha';
export { TabelaPorUnidade, juntarColunas, type LinhaDemonstracaoUN, type MapaPorUnidade } from './TabelaPorUnidade';
export { CHAVE_CRITERIOS, gravarAlocacao, obterAlocacao, simularReparticao, useCriterios, useFormas, type TipoObjectoAlocacao } from './api';
export {
  FORMAS,
  alocacaoDeApi,
  alocacaoParaApi,
  alocacaoVazia,
  linhasRepartidas,
  somaCentimos,
  validarAlocacao,
  type Alocacao,
  type CriterioReparticao,
  type Forma,
  type FormaReparticao,
  type LinhaReparticao,
  type LinhaRepartivel,
  type ResultadoSimulacao,
  type UnidadeAlocada,
} from './regras';
export { useMapaPorUnidade } from './useMapaPorUnidade';
