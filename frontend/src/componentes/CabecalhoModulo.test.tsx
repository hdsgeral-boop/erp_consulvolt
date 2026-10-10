import { fireEvent, render, screen, within } from '@testing-library/react';
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom';
import { CabecalhoModulo, separadorActivo } from './CabecalhoModulo';

let permissoes: string[] = ['*'];
vi.mock('@/sessao/SessaoContexto', () => ({
  useSessao: () => ({ pode: (...c: string[]) => permissoes.includes('*') || c.some((x) => permissoes.includes(x)) }),
}));

function Onde() {
  const l = useLocation();
  return <p data-testid="onde">{l.pathname + l.search}</p>;
}

const SEPARADORES = [
  { chave: 'a', rotulo: 'Pedidos', icone: 'shopping-cart', destino: '/m/compras/compras_encomendas', permissao: 'compras_encomendas_view', contador: 3 },
  { chave: 'b', rotulo: 'Encomendas Clientes', icone: 'file-export', destino: '/m/compras/compras_encomendas_clientes', permissao: 'compras_encomendas_clientes_view' },
  { chave: 'c', rotulo: 'Recebimentos', destino: '/m/teso/teso_gestao_pagamentos?tipo=RECEBIMENTO' },
];

function montar(caminho: string, props: Partial<Parameters<typeof CabecalhoModulo>[0]> = {}) {
  return render(
    <MemoryRouter initialEntries={[caminho]}>
      <Routes>
        <Route
          path="*"
          element={
            <>
              <CabecalhoModulo titulo="Módulo de Compras e Aprovisionamento" icone="shopping-cart" subtitulo="Sub" separadores={SEPARADORES} {...props} />
              <Onde />
            </>
          }
        />
      </Routes>
    </MemoryRouter>,
  );
}

describe('CabecalhoModulo (G.5)', () => {
  afterEach(() => {
    permissoes = ['*'];
  });

  it('título com ícone, subtítulo, indicadores e separadores-botão com o activo pelo caminho', () => {
    const { container } = montar('/m/compras/compras_encomendas_clientes/12', {
      indicadores: [
        { chave: 'p', rotulo: 'Pendentes', valor: 8 },
        { chave: 'i', rotulo: 'Integrados', valor: 286, icone: 'check-circle', cor: '#10b981' },
      ],
    });
    expect(screen.getByRole('heading', { level: 2, name: 'Módulo de Compras e Aprovisionamento' })).toBeInTheDocument();
    expect(container.querySelector('.erp-cab-modulo-titulo svg.fx-icone')).not.toBeNull();
    expect(screen.getByText('Sub')).toBeInTheDocument();
    const estado = screen.getByRole('status');
    expect(within(estado).getByText('8')).toBeInTheDocument();
    expect(within(estado).getByText('286')).toBeInTheDocument();
    const nav = screen.getByRole('navigation', { name: 'Separadores do módulo' });
    const activo = within(nav).getByRole('link', { name: 'Encomendas Clientes' });
    expect(activo).toHaveAttribute('aria-current', 'page');
    expect(activo).toHaveClass('activo');
    // «compras_encomendas» não fica activo dentro de «compras_encomendas_clientes»
    expect(within(nav).getByRole('link', { name: /Pedidos/ })).not.toHaveAttribute('aria-current');
    expect(within(nav).getByText('3')).toHaveClass('erp-separador-contador');
  });

  it('os separadores navegam entre rotas e respeitam a query string', () => {
    montar('/m/compras/compras_encomendas');
    fireEvent.click(screen.getByRole('link', { name: 'Recebimentos' }));
    expect(screen.getByTestId('onde')).toHaveTextContent('/m/teso/teso_gestao_pagamentos?tipo=RECEBIMENTO');
    expect(screen.getByRole('link', { name: 'Recebimentos' })).toHaveAttribute('aria-current', 'page');
  });

  it('esconde os separadores sem permissão e a barra quando fica só um', () => {
    permissoes = ['compras_encomendas_view'];
    montar('/m/compras/compras_encomendas');
    // fica «Pedidos» (com permissão) e «Recebimentos» (sem permissão exigida)
    expect(screen.queryByRole('link', { name: 'Encomendas Clientes' })).toBeNull();
    expect(screen.getAllByRole('link')).toHaveLength(2);
  });

  it('barra de acções rápidas e botão «Ecrã Cheio» (Fullscreen API)', () => {
    const pedir = vi.fn(() => Promise.resolve());
    Object.defineProperty(document, 'fullscreenEnabled', { configurable: true, value: true });
    const alvo = document.createElement('div');
    alvo.requestFullscreen = pedir;
    montar('/m/compras/compras_encomendas', { accoesRapidas: <button type="button">Pedido</button>, ecraCheio: () => alvo });
    expect(screen.getByRole('button', { name: 'Pedido' }).closest('.erp-cab-modulo-rapidas')).not.toBeNull();
    fireEvent.click(screen.getByRole('button', { name: /Ecrã Cheio/ }));
    expect(pedir).toHaveBeenCalledTimes(1);
  });

  it('separadorActivo: prefixo do caminho e parâmetros da query', () => {
    expect(separadorActivo('/m/a/b', '/m/a/b')).toBe(true);
    expect(separadorActivo('/m/a/b', '/m/a/b/novo')).toBe(true);
    expect(separadorActivo('/m/a/b', '/m/a/bc')).toBe(false);
    expect(separadorActivo('/m/a/b?tipo=X', '/m/a/b', '?tipo=X&p=1')).toBe(true);
    expect(separadorActivo('/m/a/b?tipo=X', '/m/a/b', '?tipo=Y')).toBe(false);
  });
});
