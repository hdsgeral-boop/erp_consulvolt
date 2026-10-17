import type { ReactNode } from 'react';
import { formatarData } from '@/utilitarios/formatacao';
import type { ContratoTrabalho } from '../api';

/**
 * Texto por baixo do estado na lista de contratos, como no antigo (app_v2.js renderContratos, «detalheEstado»):
 * suspenso → «motivo · desde dd/mm/aaaa»; cessado → «motivo · dd/mm/aaaa»; restantes → null.
 */
export function detalheEstadoContrato(c: Pick<ContratoTrabalho, 'estado' | 'suspensao' | 'cessacao'>): { texto: string; tipo: 'suspensao' | 'cessacao' } | null {
  if (c.estado === 'SUSPENSO' && c.suspensao) {
    const texto = `${c.suspensao.motivo || ''}${c.suspensao.de ? ` · desde ${formatarData(c.suspensao.de)}` : ''}`.replace(/^ · /, '');
    return texto ? { texto, tipo: 'suspensao' } : null;
  }
  if (c.estado === 'CESSADO' && c.cessacao) {
    const texto = `${c.cessacao.motivo || ''}${c.cessacao.data ? ` · ${formatarData(c.cessacao.data)}` : ''}`.replace(/^ · /, '');
    return texto ? { texto, tipo: 'cessacao' } : null;
  }
  return null;
}

/** Uma linha por rubrica numa célula da lista (legado: um `<div>` por remuneração com traço inferior). */
export function LinhasRubricas({ valores, alinhar = 'left', negrito, vazio = '-' }: { valores: ReactNode[]; alinhar?: 'left' | 'right'; negrito?: boolean; vazio?: ReactNode }) {
  if (!valores.length) return <>{vazio}</>;
  return (
    <div className="rh-linhas-rubricas">
      {valores.map((v, i) => (
        <div key={i} style={{ borderBottom: '1px solid #f1f5f9', padding: '2px 0', textAlign: alinhar, fontWeight: negrito ? 600 : undefined }}>{v}</div>
      ))}
    </div>
  );
}
