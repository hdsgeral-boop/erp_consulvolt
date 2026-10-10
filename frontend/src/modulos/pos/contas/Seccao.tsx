import type { CSSProperties, ReactNode } from 'react';
import { IconeLegado } from '@/componentes/vistas';

/**
 * Cartão de secção do sistema anterior (`.card` com `h3`/`h4` e ícone colorido, texto explicativo cinzento e acções à
 * direita) — usado nas secções da Integração contabilística, dos Desvios de caixa e da Prestação de contas.
 */
export function Seccao({
  titulo,
  icone,
  cor,
  descricao,
  extra,
  nivel = 3,
  children,
  style,
}: {
  titulo: ReactNode;
  icone?: string;
  cor?: string;
  descricao?: ReactNode;
  extra?: ReactNode;
  /** 3 = título principal (1.1rem); 4 = secção secundária (0.95rem), como no antigo. */
  nivel?: 3 | 4;
  children?: ReactNode;
  style?: CSSProperties;
}) {
  const Titulo = nivel === 3 ? 'h3' : 'h4';
  return (
    <section
      style={{ background: 'var(--ant-color-bg-container, #fff)', border: '1px solid #e2e8f0', borderRadius: 4, padding: '1.25rem', marginBottom: 16, ...style }}
      aria-label={typeof titulo === 'string' ? titulo : undefined}
    >
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: '0.6rem', marginBottom: children ? '0.8rem' : 0 }}>
        <div style={{ minWidth: 0 }}>
          <Titulo style={{ margin: 0, fontSize: nivel === 3 ? '1.1rem' : '0.95rem', fontWeight: 700, display: 'flex', alignItems: 'center', gap: 8 }}>
            {icone && <IconeLegado icone={icone} style={{ color: cor ?? '#2563eb' }} />}
            {titulo}
          </Titulo>
          {descricao && <div style={{ margin: '0.2rem 0 0', fontSize: '0.8rem', color: '#64748b' }}>{descricao}</div>}
        </div>
        {extra && <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>{extra}</div>}
      </div>
      {children}
    </section>
  );
}
