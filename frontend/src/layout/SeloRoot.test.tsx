import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { SeloRoot } from './SeloRoot';

describe('selo ROOT do cabeçalho', () => {
  it('aparece só para a conta root vinda do servidor', () => {
    render(<SeloRoot utilizador={{ conta_root: true }} />);
    expect(screen.getByTestId('selo-root').textContent).toBe('ROOT');
  });

  it.each([[{ conta_root: false }], [{}], [null], [undefined], [{ conta_root: 'true' }]])('não aparece para %j', (u) => {
    render(<SeloRoot utilizador={u as never} />);
    expect(screen.queryByTestId('selo-root')).toBeNull();
  });
});
