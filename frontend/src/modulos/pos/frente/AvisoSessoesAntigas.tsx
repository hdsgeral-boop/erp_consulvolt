import { Alert } from 'antd';
import type { CSSProperties } from 'react';
import { IconeFa } from '@/componentes/fluxos/IconeFa';
import { formatarData } from '@/utilitarios/formatacao';
import { useSessoesAntigas, type SessaoAntiga } from '../comum/frente';

/** Texto do aviso do legado (avisoSessoesAntigas, pos_gestao.js:147-152). */
export function textoSessoesAntigas(antigas: SessaoAntiga[]): string | null {
  if (!antigas.length) return null;
  const inicio = antigas.length === 1 ? 'Existe 1 sessão de caixa aberta' : `Existem ${antigas.length} sessões de caixa abertas`;
  const lista = antigas.map((s) => `${s.codigo_sessao} (${s.nome_terminal ?? s.codigo_terminal ?? '—'}, ${s.aberto_em ? formatarData(s.aberto_em) : '—'})`).join(', ');
  return `${inicio} desde dias anteriores: ${lista}. Feche-as para garantir a conferência diária.`;
}

/**
 * Aviso fixo com TODAS as sessões abertas desde dias anteriores (qualquer terminal e operador), na abertura de caixa e
 * nos Terminais POS (3.1.9, 3.3.5). Sem permissão ou sem sessões antigas não mostra nada.
 */
export function AvisoSessoesAntigas({ estilo }: { estilo?: CSSProperties }) {
  const q = useSessoesAntigas();
  const texto = textoSessoesAntigas(q.data?.sessoes ?? []);
  if (!texto) return null;
  return (
    <Alert
      className="pos-aviso-sessoes"
      type="warning"
      showIcon
      icon={<IconeFa nome="exclamation-triangle" />}
      style={{ background: '#fff7ed', borderColor: '#fed7aa', color: '#9a3412', marginBottom: 12, ...estilo }}
      message={texto}
    />
  );
}
