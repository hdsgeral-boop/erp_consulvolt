import type { RefObject } from 'react';
import { BotoesExportar, type Orientacao } from '@/componentes/impressao';
import type { FichaProjecto } from './tipos';

/**
 * «Imprimir» + «PDF» de um separador do projecto: imprime o conteúdo visível do separador (o elemento `alvo`) com o
 * motor comum — botões, menus e paginação saem; o cabeçalho da empresa e o título com o projecto são postos pelo motor.
 */
export function ImpressaoSeparador({ alvo, titulo, projecto, orientacao, desactivado }: { alvo: RefObject<HTMLElement | null>; titulo: string; projecto: Pick<FichaProjecto, 'codigo' | 'nome'>; orientacao?: Orientacao; desactivado?: boolean }) {
  return (
    <BotoesExportar
      tamanho="small"
      desactivado={desactivado}
      obterPedido={() =>
        alvo.current
          ? {
              titulo: `${titulo} — ${projecto.codigo ? `${projecto.codigo} ` : ''}${projecto.nome}`,
              conteudo: alvo.current,
              orientacao,
            }
          : null
      }
    />
  );
}
