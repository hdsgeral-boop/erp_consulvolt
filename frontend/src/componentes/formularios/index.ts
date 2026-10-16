/**
 * Formulários em modal com as vistas «Grade» e «Linhas» (legado: js/shared/formulario_linhas.js). Inventário dos
 * modais e guia de aplicação: docs/paridade/versao_actual/07_modais_grade_linhas.md.
 */
export { ModalFormulario, type PropsModalFormulario } from './ModalFormulario';
export { PainelLinhas, NOTA_LINHAS, type PropsPainelLinhas } from './PainelLinhas';
export { AlternarFormulario } from './AlternarFormulario';
export { SeccaoFormulario } from './SeccaoFormulario';
export { useFormularioLinhas, type EstadoFormularioLinhas, type OpcoesFormularioLinhas, type ResultadoColagem } from './useFormularioLinhas';
export { useVistaFormulario, chaveVistaFormulario } from './preferenciaFormulario';
export {
  normTxt,
  numeroTexto,
  dataTexto,
  opcaoPara,
  converterTexto,
  mapearColagem,
  partirTexto,
  errosLinha,
  linhaVazia,
  mensagemErro,
  paraFormulario,
  deFormulario,
} from './conversoes';
export type { CampoFormulario, TipoCampo, OpcaoCampo, PropsCelula, LinhaFormulario, ResumoLinhas } from './tipos';
