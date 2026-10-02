/** Componentes comuns do controlo orçamental nos documentos (A-02: pedido/aprovação de excesso no próprio documento). */
export { DialogoExcesso, type PropsDialogoExcesso } from './DialogoExcesso';
export { useExcessoOrcamental, type OpcoesTratamentoExcesso } from './useExcessoOrcamental';
export {
  CODIGO_EXCESSO,
  comOpcoesOrcamento,
  comOpcoesOrcamentoPedido,
  corpoPedido,
  eExcessoOrcamental,
  extrairExcesso,
  linhasDeMovimentos,
  opcoesAprovacaoNoActo,
  partirChave,
  podePrepararPedido,
  validarMotivo,
  type AlertaOrcamental,
  type ContextoExcesso,
  type DadosExcesso,
  type LinhaControlo,
  type OpcoesOrcamento,
  type TipoControlo,
} from './excesso';
