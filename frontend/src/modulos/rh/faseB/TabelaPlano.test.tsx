import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { TabelaPlano } from './TabelaPlano';
import type { PrestacaoPlano } from './tipos';

const p = (n: number, extra: Partial<PrestacaoPlano> = {}): PrestacaoPlano => ({ numero: n, mes_ano: `2026-0${n}`, capital: '1000', juro: '0', total: '1000', saldo: '0', estado: 'PENDENTE', ...extra });

describe('TabelaPlano — prestação descontada', () => {
  it('mostra a coluna «Descontada em» quando há prestações descontadas', () => {
    render(<TabelaPlano comJuro={false} plano={[p(1, { estado: 'DESCONTADO', descontado_em: '2026-02-28T10:00:00Z' }), p(2)]} />);
    expect(screen.getAllByText('Descontada em').length).toBeGreaterThan(0);
  });

  it('não mostra a coluna se nenhuma foi descontada', () => {
    render(<TabelaPlano comJuro={false} plano={[p(1), p(2)]} />);
    expect(screen.queryByText('Descontada em')).toBeNull();
  });
});
