import { Button, type ButtonProps } from 'antd';
import type { ReactNode } from 'react';
import { IconeFa } from '@/componentes/fluxos';

/** Variantes dos botões do cabeçalho dos cartões do legado (.btn-outline, .btn-primary, .btn-danger, .btn-warning, .btn-success). */
export type VarianteBotaoCartao = 'contorno' | 'primario' | 'perigo' | 'aviso' | 'sucesso';

const ESTILOS: Record<VarianteBotaoCartao, ButtonProps> = {
  contorno: {},
  primario: { type: 'primary' },
  perigo: { type: 'primary', danger: true },
  aviso: { type: 'primary', style: { background: '#f59e0b', borderColor: '#f59e0b' } },
  sucesso: { type: 'primary', style: { background: '#10b981', borderColor: '#10b981' } },
};

/** Botão pequeno com ícone Font Awesome do legado, para o cabeçalho dos cartões («Template Excel», «Novo Activo»…). */
export function BotaoCartao({ icone, variante = 'contorno', children, style, ...props }: Omit<ButtonProps, 'icon' | 'type' | 'danger'> & { icone: string; variante?: VarianteBotaoCartao; children: ReactNode }) {
  const base = ESTILOS[variante];
  return (
    <Button size="small" {...base} {...props} style={{ ...base.style, ...style }} icon={<IconeFa nome={icone} />}>
      {children}
    </Button>
  );
}
