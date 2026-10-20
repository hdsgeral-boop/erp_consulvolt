import { render, screen } from '@testing-library/react';
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom';
import { describe, expect, it } from 'vitest';
import { RedirecionarEnderecoAntigo } from './App';

function Destino() {
  const { pathname, search, hash } = useLocation();
  return <p data-testid="destino">{`${pathname}${search}${hash}`}</p>;
}

function montar(entrada: string) {
  return render(
    <MemoryRouter initialEntries={[entrada]}>
      <Routes>
        <Route path="m/:modulo/:ecra/*" element={<RedirecionarEnderecoAntigo />} />
        <Route path=":modulo/:ecra/*" element={<Destino />} />
      </Routes>
    </MemoryRouter>,
  );
}

describe('endereços antigos com /m/', () => {
  it('/m/config/config_licenca vai para /config/config_licenca', () => {
    montar('/m/config/config_licenca');
    expect(screen.getByTestId('destino').textContent).toBe('/config/config_licenca');
  });

  it('mantém a pesquisa, o # e os segmentos seguintes', () => {
    montar('/m/vendas/faturacao/55?tipo=RECEBIMENTO#linha-3');
    expect(screen.getByTestId('destino').textContent).toBe('/vendas/faturacao/55?tipo=RECEBIMENTO#linha-3');
  });

  it('o endereço novo abre directamente, sem passar pelo redireccionamento', () => {
    montar('/config/config_licenca');
    expect(screen.getByTestId('destino').textContent).toBe('/config/config_licenca');
  });
});
