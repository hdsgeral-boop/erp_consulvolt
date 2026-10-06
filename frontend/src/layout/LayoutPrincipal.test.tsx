import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { definirModoResponsivo } from '@/componentes/responsivo/modoResponsivo';
import type { ModuloMenu } from '@/api/tipos';
import { simularLargura } from '@/componentes/responsivo/testes/simularLargura';
import { LayoutPrincipal } from './LayoutPrincipal';

// o Ant Design (Menu, Drawer) é lento em jsdom quando a suíte inteira corre em paralelo
vi.setConfig({ testTimeout: 30_000 });

const EMPRESA = 'Demo E2E Comércio, Lda';
const MENU: ModuloMenu[] = [
  { id: 'vendas', nome: 'Vendas e Facturação', ecras: [{ id: 'vendas_faturacao', nome: 'Facturação', vistas: [], pai: null }] },
  { id: 'config', nome: 'Configurações', ecras: [{ id: 'config_geral', nome: 'Geral', vistas: [], pai: null }] },
];

let logotipo: string | null = null;

vi.mock('@/sessao/SessaoContexto', () => ({
  useSessao: () => ({
    menu: MENU,
    empresa: { id: 1, nome: EMPRESA },
    empresas: [
      { id: 1, nome: EMPRESA },
      { id: 2, nome: 'Demo E2E Serviços, Lda' },
    ],
    utilizador: { id: 1, nome_utilizador: 'e2e.admin', nome_completo: 'Utilizador de Teste' },
    escolherEmpresa: vi.fn(() => Promise.resolve()),
    sair: vi.fn(() => Promise.resolve()),
    pode: () => true,
    estado: 'autenticado',
  }),
}));

vi.mock('@/sessao/identidade', () => ({
  useIdentidade: () => ({ data: { id: 1, nome: EMPRESA, logotipo }, isLoading: false }),
}));

// preferências do utilizador (favoritos): um favorito guardado no servidor
const enviarMock = vi.fn(() => Promise.resolve({ dados: null, mensagem: 'ok' }));
vi.mock('@/api/cliente', () => ({
  obter: vi.fn((url: string) => Promise.resolve(url.includes('/preferencias/favoritos') ? [{ nome: 'lista', valor: [{ modulo: 'config', ecra: 'config_geral' }] }] : [])),
  enviar: (...a: unknown[]) => (enviarMock as unknown as (...x: unknown[]) => Promise<unknown>)(...a),
  obterPagina: vi.fn(),
  http: { post: vi.fn() },
}));

function montar(caminho = '/') {
  const cliente = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={cliente}>
      <MemoryRouter initialEntries={[caminho]}>
        <Routes>
          <Route element={<LayoutPrincipal />}>
            <Route index element={<p>Página inicial</p>} />
            <Route path="m/:modulo/:ecra/*" element={<p>Página do ecrã</p>} />
          </Route>
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

afterEach(() => {
  simularLargura(null);
  logotipo = null;
});

describe('LayoutPrincipal — portátil (1366 px)', () => {
  beforeEach(() => simularLargura(1366));

  it('mostra o menu lateral com o nome da empresa, iniciais, selector de empresa e módulos com ícone', () => {
    const { container } = montar();
    const lateral = container.querySelector('.ant-layout-sider') as HTMLElement;
    expect(lateral).not.toBeNull();
    expect(within(lateral).getByText(EMPRESA)).toBeInTheDocument();
    expect(within(lateral).getByText('DE')).toBeInTheDocument();
    expect(screen.getByRole('combobox', { name: 'Empresa activa' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Recolher menu' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Abrir menu' })).toBeNull();
    expect(screen.getByText('Utilizador de Teste')).toBeInTheDocument();
    const modulo = within(lateral).getByText('Vendas e Facturação').closest('.ant-menu-submenu-title') as HTMLElement;
    expect(modulo.querySelector('.anticon svg')).not.toBeNull();
    expect(screen.getByText('Página inicial')).toBeInTheDocument();
  });

  it('usa o logótipo quando a empresa o tem', () => {
    logotipo = 'data:image/png;base64,iVBORw0KGgo=';
    montar();
    expect(screen.getByRole('img', { name: `Logótipo de ${EMPRESA}` })).toHaveAttribute('src', logotipo);
  });

  it('recolher o menu deixa só o logótipo/iniciais', () => {
    const { container } = montar();
    fireEvent.click(screen.getByRole('button', { name: 'Recolher menu' }));
    const lateral = container.querySelector('.ant-layout-sider') as HTMLElement;
    expect(lateral).toHaveClass('ant-layout-sider-collapsed');
    expect(within(lateral).queryByText(EMPRESA)).toBeNull();
    expect(within(lateral).getByText('DE')).toBeInTheDocument();
  });
});

describe('LayoutPrincipal — telemóvel (375 px)', () => {
  beforeEach(() => simularLargura(375));

  it('troca o menu lateral por uma gaveta e mostra a empresa na barra superior', async () => {
    const { container } = montar();
    expect(container.querySelector('.ant-layout-sider')).toBeNull();
    expect(screen.queryByRole('combobox', { name: 'Empresa activa' })).toBeNull();
    // barra superior: marca da empresa; nome do utilizador escondido (só o avatar)
    const cabecalho = container.querySelector('.ant-layout-header') as HTMLElement;
    expect(within(cabecalho).getByText(EMPRESA)).toBeInTheDocument();
    expect(screen.queryByText('Utilizador de Teste')).toBeNull();

    fireEvent.click(screen.getByRole('button', { name: 'Abrir menu' }));
    const gaveta = await waitFor(() => {
      const g = document.querySelector('.erp-gaveta-menu') as HTMLElement | null;
      expect(g).not.toBeNull();
      return g as HTMLElement;
    });
    expect(within(gaveta).getByRole('combobox', { name: 'Empresa activa' })).toBeInTheDocument();
    expect(within(gaveta).getByText('Vendas e Facturação')).toBeInTheDocument();
    // os favoritos (pedido ao servidor) entram no menu: esperar antes de navegar para o menu não se redesenhar a meio
    expect(await within(gaveta).findByText('Favoritos')).toBeInTheDocument();

    // navegar fecha a gaveta
    fireEvent.click(within(gaveta).getByText('Vendas e Facturação'));
    fireEvent.click(await within(gaveta).findByText('Facturação'));
    expect(await screen.findByText('Página do ecrã')).toBeInTheDocument();
    await waitFor(() => expect(screen.getByRole('button', { name: 'Abrir menu' })).toHaveAttribute('aria-expanded', 'false'));
  });
});

describe('LayoutPrincipal — tablet (800 px)', () => {
  beforeEach(() => simularLargura(800));

  it('usa a gaveta mas mostra o nome do utilizador', async () => {
    const { container } = montar();
    expect(container.querySelector('.ant-layout-sider')).toBeNull();
    expect(screen.getByRole('button', { name: 'Abrir menu' })).toBeInTheDocument();
    await act(async () => undefined);
    expect(screen.getByText('Utilizador de Teste')).toBeInTheDocument();
  });
});

describe('LayoutPrincipal — funcionalidades transversais (ronda 2)', () => {
  beforeEach(() => simularLargura(1366));
  afterEach(() => definirModoResponsivo(false));

  it('favoritos do servidor no menu e estrela do ecrã actual grava a preferência', async () => {
    const { container } = montar('/m/vendas/vendas_faturacao');
    const lateral = container.querySelector('.ant-layout-sider') as HTMLElement;
    expect(await within(lateral).findByText('Favoritos')).toBeInTheDocument();
    const estrela = await screen.findByRole('button', { name: 'Adicionar aos favoritos' });
    fireEvent.click(estrela);
    await waitFor(() =>
      expect(enviarMock).toHaveBeenCalledWith('put', '/sistema/preferencias/favoritos/lista', {
        valor: [
          { modulo: 'config', ecra: 'config_geral' },
          { modulo: 'vendas', ecra: 'vendas_faturacao' },
        ],
      }),
    );
    expect(await screen.findByRole('button', { name: 'Retirar dos favoritos' })).toBeInTheDocument();
  });

  it('F1 e o botão «Ajuda» abrem o painel de ajuda do ecrã', async () => {
    montar('/m/vendas/vendas_faturacao');
    fireEvent.keyDown(window, { key: 'F1' });
    expect(await screen.findByText('Para que serve')).toBeInTheDocument();
    expect(screen.getByText(/Emitir facturas, facturas-recibo/)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Ajuda' })).toBeInTheDocument();
  });

  it('Ctrl+K abre a pesquisa de ecrãs e Enter navega', async () => {
    montar('/');
    fireEvent.keyDown(window, { key: 'k', ctrlKey: true });
    const entrada = await screen.findByRole('textbox', { name: 'Procurar ecrã' });
    fireEvent.change(entrada, { target: { value: 'factur' } });
    fireEvent.keyDown(entrada, { key: 'Enter' });
    expect(await screen.findByText('Página do ecrã')).toBeInTheDocument();
    // depois de navegar aparece o botão flutuante «Voltar»
    expect(screen.getByRole('button', { name: /Voltar/ })).toBeInTheDocument();
  });

  it('«Modo responsivo» força a gaveta do menu num ecrã largo e pode ser desligado', async () => {
    const { container } = montar('/');
    fireEvent.click(screen.getByRole('button', { name: 'Modo responsivo' }));
    await waitFor(() => expect(container.querySelector('.ant-layout-sider')).toBeNull());
    expect(screen.getByRole('button', { name: 'Abrir menu' })).toBeInTheDocument();
    expect(document.body).toHaveClass('erp-modo-responsivo');
    fireEvent.click(screen.getByRole('button', { name: 'Sair do modo responsivo' }));
    await waitFor(() => expect(container.querySelector('.ant-layout-sider')).not.toBeNull());
  });
});
