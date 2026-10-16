import type { CampoFormulario } from '@/componentes/formularios';

/**
 * Propriedades de um `ModalFormulario` que só tem a vista Grade (edição, ou criação em que não faz sentido registar
 * vários de uma vez — ex.: terminal com meios de pagamento próprios): sem colunas nem gravação em linhas.
 */
export const SO_GRADE: { criacao: false; campos: CampoFormulario[]; gravarLinha: () => Promise<unknown> } = {
  criacao: false,
  campos: [],
  gravarLinha: () => Promise.resolve(),
};
