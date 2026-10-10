import type { ReactNode } from 'react';
import { IconeLegado } from './AccoesLinha';
import './padroesLegado.css';

/**
 * Estado vazio do sistema anterior (G.7): cartão centrado com ícone grande (3rem), título a negrito e frase
 * explicativa — ex.: «Nenhuma Folha de Contagem Ativa» (clipboard-list cinzento) ou «Nenhuma Revisão Pendente»
 * (check-circle verde).
 */
export interface PropsEstadoVazio {
  /** Ícone Font Awesome (por omissão «folder-open»). */
  icone?: string;
  /** Cor do ícone (por omissão cinzento; ex.: '#059669' para «tudo em dia»). */
  cor?: string;
  titulo?: ReactNode;
  texto?: ReactNode;
  /** Botões (ex.: «+ Novo registo»). */
  accoes?: ReactNode;
}

export function EstadoVazio({ icone = 'folder-open', cor, titulo = 'Nenhum registo encontrado', texto, accoes }: PropsEstadoVazio) {
  return (
    <div className="erp-estado-vazio" role="status">
      <div className="erp-estado-vazio-icone" style={cor ? { color: cor } : undefined} aria-hidden>
        <IconeLegado icone={icone} />
      </div>
      {titulo && <h3 className="erp-estado-vazio-titulo">{titulo}</h3>}
      {texto && <p className="erp-estado-vazio-texto">{texto}</p>}
      {accoes && <div className="erp-estado-vazio-accoes">{accoes}</div>}
    </div>
  );
}
