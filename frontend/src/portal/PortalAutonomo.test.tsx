import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { ErroApi, type ModuloMenu } from '@/api/tipos';
import { PortalAutonomo } from './PortalAutonomo';
import { naRotaPortal, temAcessoAoErp } from './rota';

vi.setConfig({ testTimeout: 30_000 });

const largarEmpresa = vi.fn();
const sair = vi.fn(() => Promise.resolve());
let resposta: () => Promise<unknown> = () => Promise.resolve(null);
const ecra = (id: string) => ({ id, nome: id, vistas: [], pai: null });
const SO_PORTAL: ModuloMenu[] = [{ id: 'rh', nome: 'RH', ecras: [ecra('rh_portal')] }];
const COM_ERP: ModuloMenu[] = [{ id: 'geral', nome: 'Geral', ecras: [ecra('dashboard')] }, ...SO_PORTAL];
let menu: ModuloMenu[] = SO_PORTAL;

vi.mock('@/sessao/SessaoContexto', () => ({
  useSessao: () => ({
    empresa: { id: 7, nome: 'Empresa Fictícia, Lda' },
    utilizador: { id: 3, nome_utilizador: 'joana', nome_completo: 'Joana Utilizadora' },
    largarEmpresa,
    sair,
    menu,
    estado: 'autenticado',
  }),
}));
vi.mock('@/sessao/identidade', () => ({ useIdentidade: () => ({ data: { id: 7, nome: 'Empresa Fictícia, Lda', logotipo: null }, isLoading: false }) }));
vi.mock('@/api/cliente', () => ({
  obter: vi.fn((url: string) => (url === '/portal/acesso' ? resposta() : Promise.resolve(null))),
  enviar: vi.fn(() => Promise.resolve({ dados: null, mensagem: 'ok' })),
}));
// os separadores são os do ecrã rh_portal (não se duplicam aqui)
vi.mock('@/modulos/rh/Portal', () => ({ default: () => <p>Separadores do portal</p>, Aprovacoes: () => <p>Lista de aprovações</p> }));

function montar() {
  const cliente = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={cliente}>
      <MemoryRouter initialEntries={['/portal']}>
        <Routes>
          <Route path="portal/*" element={<PortalAutonomo />} />
          <Route path="/" element={<p>Início do ERP</p>} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe('Portal autónomo do colaborador', () => {
  beforeEach(() => {
    largarEmpresa.mockClear();
    sair.mockClear();
    menu = SO_PORTAL;
  });
  afterEach(() => {
    localStorage.clear();
    document.documentElement.classList.remove('erp-tela-nocturna');
  });

  it('colaborador associado: cabeçalho mínimo (empresa, nome, trocar empresa, sair) e os separadores do portal, sem o menu do ERP', async () => {
    resposta = () => Promise.resolve({ colaborador: { id: 1, nome_completo: 'Joana Fictícia' }, aprovacoes_pendentes: 0, aprova_rh: false });
    const { container } = montar();
    expect(await screen.findByText('Separadores do portal')).toBeInTheDocument();
    expect(screen.getAllByText('Empresa Fictícia, Lda').length).toBeGreaterThan(0);
    expect(screen.getAllByText('Joana Fictícia').length).toBeGreaterThan(0);
    expect(container.querySelector('.ant-layout-sider')).toBeNull();
    expect(container.querySelector('.erp-menu-busca')).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: 'Trocar empresa' }));
    expect(largarEmpresa).toHaveBeenCalled();
    fireEvent.click(screen.getByRole('button', { name: 'Sair' }));
    expect(sair).toHaveBeenCalled();
  });

  it('mesmo aspecto do ERP: barra superior e área de trabalho do sistema, tela nocturna e sem «Voltar ao ERP» para quem só tem o portal', async () => {
    resposta = () => Promise.resolve({ colaborador: { id: 1, nome_completo: 'Joana Fictícia' }, aprovacoes_pendentes: 0, aprova_rh: false });
    const { container } = montar();
    expect(await screen.findByText('Separadores do portal')).toBeInTheDocument();
    expect(container.querySelector('.ant-layout-header.erp-cabecalho')).not.toBeNull();
    expect(container.querySelector('.ant-layout-content.erp-conteudo.erp-fundo-rh')).not.toBeNull();
    expect(screen.getByRole('navigation', { name: 'Navegação do portal' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Voltar ao ERP' })).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: 'Tela nocturna' }));
    await waitFor(() => expect(document.documentElement).toHaveClass('erp-tela-nocturna'));
    expect(screen.getByRole('button', { name: 'Tela nocturna' })).toHaveAttribute('aria-pressed', 'true');
  });

  it('quem também tem o ERP volta ao ERP pelo botão da barra', async () => {
    menu = COM_ERP;
    resposta = () => Promise.resolve({ colaborador: { id: 1, nome_completo: 'Joana Fictícia' }, aprovacoes_pendentes: 0, aprova_rh: false });
    montar();
    expect(await screen.findByText('Separadores do portal')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Voltar ao ERP' }));
    expect(await screen.findByText('Início do ERP')).toBeInTheDocument();
  });

  it('acesso ao ERP: qualquer ecrã do menu além do portal', () => {
    expect(temAcessoAoErp(SO_PORTAL)).toBe(false);
    expect(temAcessoAoErp([])).toBe(false);
    expect(temAcessoAoErp(undefined)).toBe(false);
    expect(temAcessoAoErp(COM_ERP)).toBe(true);
  });

  it('aprovador sem colaborador: só as aprovações', async () => {
    resposta = () => Promise.resolve({ colaborador: null, aprovacoes_pendentes: 2, aprova_rh: true });
    montar();
    expect(await screen.findByText('Lista de aprovações')).toBeInTheDocument();
    expect(screen.queryByText('Separadores do portal')).toBeNull();
  });

  it('sem acesso (403 do servidor): mensagem clara e escolher outra empresa', async () => {
    resposta = () => Promise.reject(new ErroApi('O portal do colaborador só está disponível para utilizadores associados a um colaborador desta empresa.', 403, 'SEM_ACESSO_PORTAL'));
    montar();
    expect(await screen.findByText(/só está disponível para utilizadores associados/)).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: /Escolher outra empresa/ }));
    expect(largarEmpresa).toHaveBeenCalled();
  });

  it('reconhece a rota do portal (o login volta para lá)', () => {
    expect(naRotaPortal('/portal')).toBe(true);
    expect(naRotaPortal('/portal/x')).toBe(true);
    expect(naRotaPortal('/portalx')).toBe(false);
    expect(naRotaPortal('/m/rh/rh_portal')).toBe(false);
  });
});
