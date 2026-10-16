import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
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
const largarEmpresaMock = vi.fn();

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
    largarEmpresa: largarEmpresaMock,
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
          <Route path="portal/*" element={<p>Página do portal</p>} />
          <Route element={<LayoutPrincipal />}>
            <Route index element={<p>Página inicial</p>} />
            <Route path="m/:modulo/:ecra/*" element={<p>Página do ecrã</p>} />
            <Route path="dados-pessoais" element={<p>Página dos dados pessoais</p>} />
          </Route>
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

afterEach(() => {
  simularLargura(null);
  logotipo = null;
  // o aspecto do menu fica no navegador por utilizador: cada teste começa com o padrão
  localStorage.clear();
});

describe('LayoutPrincipal — portátil (1366 px)', () => {
  beforeEach(() => simularLargura(1366));

  it('mostra o menu lateral com o nome da empresa, iniciais, «Trocar empresa» e a estrutura do legado com ícones', () => {
    const { container } = montar();
    const lateral = container.querySelector('.ant-layout-sider') as HTMLElement;
    expect(lateral).not.toBeNull();
    expect(within(lateral).getByText(EMPRESA)).toBeInTheDocument();
    expect(within(lateral).getByText('DE')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Trocar empresa' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Empresa activa' })).toBeInTheDocument();
    expect(screen.queryByRole('combobox', { name: 'Empresa activa' })).toBeNull();
    expect(screen.getByRole('button', { name: 'Recolher menu' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Abrir menu' })).toBeNull();
    expect(screen.getByText('Utilizador de Teste')).toBeInTheDocument();
    // legado: sem avatar no utilizador (nome + papel) e ajuda azul cheia
    expect(container.querySelector('.erp-utilizador .ant-avatar')).toBeNull();
    expect(screen.getByRole('button', { name: 'Ajuda' })).toHaveClass('ant-btn-primary');
    // Início no topo; módulos dentro de «Menu Geral»; configurações dentro de «Sistema»
    expect(within(lateral).getByText('Início')).toBeInTheDocument();
    const geral = within(lateral).getByText('Menu Geral').closest('.ant-menu-submenu-title') as HTMLElement;
    expect(geral.querySelector('svg.fx-icone')).not.toBeNull();
    expect(within(lateral).getByText('Sistema')).toBeInTheDocument();
    expect(within(lateral).queryByText('Vendas e Facturação')).toBeNull();
    expect(screen.getByText('Página inicial')).toBeInTheDocument();
  });

  it('«Trocar empresa» volta à escolha de empresa', () => {
    largarEmpresaMock.mockClear();
    montar('/m/vendas/vendas_faturacao');
    fireEvent.click(screen.getByRole('button', { name: 'Trocar empresa' }));
    expect(largarEmpresaMock).toHaveBeenCalledTimes(1);
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
    expect(within(gaveta).getByText('Menu Geral')).toBeInTheDocument();
    // os favoritos (pedido ao servidor) entram no menu: esperar antes de navegar para o menu não se redesenhar a meio
    expect(await within(gaveta).findByText('Favoritos')).toBeInTheDocument();

    // navegar fecha a gaveta (Menu Geral › Vendas › Faturação, rótulos do legado)
    fireEvent.click(within(gaveta).getByText('Menu Geral'));
    fireEvent.click(await within(gaveta).findByText('Vendas'));
    fireEvent.click(await within(gaveta).findByText('Faturação'));
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

  it('a barra superior já não tem o botão «Modo responsivo» nem o botão sair ao lado do nome', () => {
    montar('/');
    expect(screen.queryByRole('button', { name: 'Modo responsivo' })).toBeNull();
    expect(screen.queryByRole('button', { name: 'Sair do sistema' })).toBeNull();
  });

  it('o botão junto ao nome mostra as opções: «Gerir Dados Pessoais» primeiro e «Terminar sessão»', async () => {
    montar('/');
    fireEvent.click(screen.getByRole('button', { name: 'Opções do utilizador' }));
    const itens = await screen.findAllByRole('menuitem');
    const textos = itens.map((i) => i.textContent?.trim());
    expect(textos.filter((t) => t === 'Gerir Dados Pessoais' || t === 'Terminar sessão')).toEqual(['Gerir Dados Pessoais', 'Terminar sessão']);
    fireEvent.click(screen.getByText('Gerir Dados Pessoais'));
    expect(await screen.findByText('Página dos dados pessoais')).toBeInTheDocument();
  });
});

describe('LayoutPrincipal — Versão Actual: busca no menu, aspecto e tela nocturna', () => {
  beforeEach(() => simularLargura(1366));
  afterEach(() => {
    localStorage.clear();
    document.documentElement.classList.remove('erp-tela-nocturna');
  });

  it('filtra a árvore em linha sem acentos, conta os resultados e abre o escolhido com setas e Enter', async () => {
    const { container } = montar('/');
    const lateral = container.querySelector('.ant-layout-sider') as HTMLElement;
    const campo = within(lateral).getByRole('textbox', { name: 'Procurar no menu' });
    fireEvent.change(campo, { target: { value: 'FATURACAO' } });
    expect(await within(lateral).findByText('Faturação')).toBeInTheDocument();
    expect(within(lateral).getByRole('status')).toHaveTextContent('1 operação encontrada');
    // o grupo «Sistema» (sem resultados) desaparece durante a busca
    expect(within(lateral).queryByText('Sistema')).toBeNull();
    fireEvent.keyDown(campo, { key: 'ArrowDown' });
    await waitFor(() => expect(lateral.querySelector('.erp-menu-focado')).not.toBeNull());
    fireEvent.keyDown(campo, { key: 'Enter' });
    expect(await screen.findByText('Página do ecrã')).toBeInTheDocument();
    expect(campo).toHaveValue('');
  });

  it('nunca revela ecrãs sem permissão e Esc limpa a busca e repõe a árvore', async () => {
    const { container } = montar('/');
    const lateral = container.querySelector('.ant-layout-sider') as HTMLElement;
    const campo = within(lateral).getByRole('textbox', { name: 'Procurar no menu' });
    fireEvent.change(campo, { target: { value: 'compras' } });
    expect(within(lateral).getByRole('status')).toHaveTextContent('Nada encontrado');
    fireEvent.keyDown(campo, { key: 'Escape' });
    expect(campo).toHaveValue('');
    expect(within(lateral).getByRole('status')).toHaveTextContent('');
    expect(within(lateral).getByText('Sistema')).toBeInTheDocument();
  });

  it('tela nocturna: botão na barra superior e Ctrl+Shift+N, guardada nas preferências do utilizador', async () => {
    enviarMock.mockClear();
    montar('/');
    const botao = screen.getByRole('button', { name: 'Tela nocturna' });
    expect(botao).toHaveAttribute('aria-pressed', 'false');
    fireEvent.click(botao);
    await waitFor(() => expect(document.documentElement).toHaveClass('erp-tela-nocturna'));
    expect(screen.getByRole('button', { name: 'Tela nocturna' })).toHaveAttribute('aria-pressed', 'true');
    await waitFor(() =>
      expect(enviarMock).toHaveBeenCalledWith('put', '/sistema/preferencias/interface/aspecto_nucleo', { valor: { tema: 'escuro', menu: 'completo' } }),
    );
    expect(JSON.parse(localStorage.getItem('erp.aspecto.1') ?? '{}')).toEqual({ tema: 'escuro', menu: 'completo' });
    fireEvent.keyDown(window, { key: 'N', ctrlKey: true, shiftKey: true });
    await waitFor(() => expect(document.documentElement).not.toHaveClass('erp-tela-nocturna'));
  });

  it('aspecto do menu do utilizador: «só nomes» retira os símbolos e «só símbolos» recolhe o menu', () => {
    localStorage.setItem('erp.aspecto.1', JSON.stringify({ tema: 'claro', menu: 'nomes' }));
    const { container, unmount } = montar('/');
    let lateral = container.querySelector('.ant-layout-sider') as HTMLElement;
    const geral = within(lateral).getByText('Menu Geral').closest('.ant-menu-submenu-title') as HTMLElement;
    expect(geral.querySelector('svg.fx-icone')).toBeNull();
    expect(lateral).not.toHaveClass('ant-layout-sider-collapsed');
    unmount();

    localStorage.setItem('erp.aspecto.1', JSON.stringify({ tema: 'claro', menu: 'icones' }));
    const segundo = montar('/');
    lateral = segundo.container.querySelector('.ant-layout-sider') as HTMLElement;
    expect(lateral).toHaveClass('ant-layout-sider-collapsed');
    // com o menu só em símbolos não há caixa de busca; «Recolher menu» volta ao aspecto anterior
    expect(within(lateral).queryByRole('textbox', { name: 'Procurar no menu' })).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: 'Recolher menu' }));
    expect(lateral).not.toHaveClass('ant-layout-sider-collapsed');
  });
});

describe('LayoutPrincipal — fecho Versão Actual: portal autónomo e renomear favorito', () => {
  beforeEach(() => simularLargura(1366));

  it('«Abrir o portal» no menu do utilizador leva a /portal (sem o menu do ERP)', async () => {
    const { container } = montar('/');
    fireEvent.click(screen.getByRole('button', { name: 'Opções do utilizador' }));
    fireEvent.click(await screen.findByText('Abrir o portal'));
    expect(await screen.findByText('Página do portal')).toBeInTheDocument();
    expect(container.querySelector('.ant-layout-sider')).toBeNull();
  });

  it('o lápis «Renomear favorito» no menu «⋮» grava o rótulo próprio com a preferência', async () => {
    enviarMock.mockClear();
    const { container } = montar('/m/config/config_geral');
    const lateral = container.querySelector('.ant-layout-sider') as HTMLElement;
    expect(await within(lateral).findByText('Favoritos')).toBeInTheDocument();
    const pontos = await waitFor(() => {
      const el = within(lateral).getAllByText('Geral / Backup').map((x) => x.closest('li')?.querySelector('.erp-menu-pontos')).find(Boolean);
      if (!el) throw new Error('sem «⋮»');
      return el as HTMLElement;
    });
    fireEvent.click(pontos);
    fireEvent.click(await screen.findByText('Renomear favorito'));
    const campo = await screen.findByRole('textbox', { name: 'Nome do favorito' });
    fireEvent.change(campo, { target: { value: '  Cópias   de segurança ' } });
    fireEvent.click(screen.getByRole('button', { name: 'Guardar' }));
    await waitFor(() =>
      expect(enviarMock).toHaveBeenCalledWith('put', '/sistema/preferencias/favoritos/lista', {
        valor: [{ modulo: 'config', ecra: 'config_geral', rotulo: 'Cópias de segurança' }],
      }),
    );
  });
});

describe('LayoutPrincipal — menu lateral igual ao legado: largura ajustável, aspecto e expandir/recolher', () => {
  beforeEach(() => simularLargura(1366));

  const lateralDe = (container: HTMLElement) => container.querySelector('.ant-layout-sider') as HTMLElement;

  it('arrastar o friso com o rato muda a largura entre 220 e 800 px e guarda-a por utilizador; duplo clique repõe', async () => {
    const { container } = montar('/');
    const lateral = lateralDe(container);
    const friso = within(lateral).getByRole('separator', { name: 'Largura do menu' });
    expect(friso).toHaveAttribute('aria-valuenow', '280');
    expect(friso).toHaveAttribute('aria-valuemin', '220');
    expect(friso).toHaveAttribute('aria-valuemax', '800');
    expect(lateral.style.width).toBe('280px');

    fireEvent.mouseDown(friso, { button: 0, clientX: 280 });
    expect(friso).toHaveClass('a-arrastar');
    expect(document.body.style.cursor).toBe('col-resize');
    fireEvent.mouseMove(document, { clientX: 420 });
    await waitFor(() => expect(friso).toHaveAttribute('aria-valuenow', '420'));
    expect(lateral.style.width).toBe('420px');
    // limite máximo e mínimo do legado
    fireEvent.mouseMove(document, { clientX: 5000 });
    await waitFor(() => expect(friso).toHaveAttribute('aria-valuenow', '800'));
    fireEvent.mouseMove(document, { clientX: 10 });
    await waitFor(() => expect(friso).toHaveAttribute('aria-valuenow', '220'));
    fireEvent.mouseMove(document, { clientX: 380 });
    fireEvent.mouseUp(document);
    await waitFor(() => expect(friso).toHaveAttribute('aria-valuenow', '380'));
    expect(friso).not.toHaveClass('a-arrastar');
    expect(document.body.style.cursor).toBe('');
    expect(localStorage.getItem('erp.menu.largura.1')).toBe('380');

    // depois de largar, o rato já não mexe na largura
    fireEvent.mouseMove(document, { clientX: 600 });
    expect(friso).toHaveAttribute('aria-valuenow', '380');

    fireEvent.doubleClick(friso);
    expect(friso).toHaveAttribute('aria-valuenow', '280');
    expect(localStorage.getItem('erp.menu.largura.1')).toBeNull();
  });

  it('o friso é acessível pelo teclado e a largura guardada é lida ao entrar', () => {
    localStorage.setItem('erp.menu.largura.1', '500');
    const { container } = montar('/');
    const friso = within(lateralDe(container)).getByRole('separator', { name: 'Largura do menu' });
    expect(friso).toHaveAttribute('aria-valuenow', '500');
    expect(friso).toHaveAttribute('tabindex', '0');
    fireEvent.keyDown(friso, { key: 'ArrowRight' });
    expect(friso).toHaveAttribute('aria-valuenow', '510');
    fireEvent.keyDown(friso, { key: 'ArrowLeft', shiftKey: true });
    expect(friso).toHaveAttribute('aria-valuenow', '460');
    fireEvent.keyDown(friso, { key: 'Home' });
    expect(friso).toHaveAttribute('aria-valuenow', '220');
    fireEvent.keyDown(friso, { key: 'End' });
    expect(friso).toHaveAttribute('aria-valuenow', '800');
    expect(localStorage.getItem('erp.menu.largura.1')).toBe('800');
    fireEvent.keyDown(friso, { key: 'Enter' });
    expect(friso).toHaveAttribute('aria-valuenow', '280');
  });

  it('recolher continua a funcionar: menu de 76 px sem friso, e ao expandir volta à largura escolhida', () => {
    localStorage.setItem('erp.menu.largura.1', '450');
    const { container } = montar('/');
    const lateral = lateralDe(container);
    fireEvent.click(screen.getByRole('button', { name: 'Recolher menu' }));
    expect(lateral).toHaveClass('ant-layout-sider-collapsed');
    expect(lateral.style.width).toBe('76px');
    expect(within(lateral).queryByRole('separator')).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: 'Recolher menu' }));
    expect(lateral.style.width).toBe('450px');
  });

  it('botões do aspecto no próprio menu (completo, só símbolos, só nomes) e «Expandir/Recolher tudo»', async () => {
    const { container } = montar('/');
    const lateral = lateralDe(container);
    const grupo = within(lateral).getByRole('radiogroup', { name: 'Aspecto do menu' });
    expect(within(grupo).getByRole('radio', { name: 'Completo (símbolos e nomes)' })).toHaveAttribute('aria-checked', 'true');
    // «Expandir tudo» abre os grupos e sub-grupos (Menu Geral › Vendas); «Recolher tudo» fecha-os
    expect(within(lateral).queryByText('Faturação')).toBeNull();
    fireEvent.click(within(lateral).getByRole('button', { name: 'Expandir tudo' }));
    expect(await within(lateral).findByText('Faturação')).toBeVisible();
    fireEvent.click(within(lateral).getByRole('button', { name: 'Recolher tudo' }));
    await waitFor(() => expect(within(lateral).getByText('Menu Geral').closest('.ant-menu-submenu')).not.toHaveClass('ant-menu-submenu-open'));
    // seta do grupo (fa-chevron-down) roda quando o grupo abre
    const titulo = within(lateral).getByText('Sistema').closest('.ant-menu-submenu-title') as HTMLElement;
    expect(titulo.querySelector('.erp-menu-seta')).not.toHaveClass('aberta');
    fireEvent.click(titulo);
    await waitFor(() => expect(titulo.querySelector('.erp-menu-seta')).toHaveClass('aberta'));

    fireEvent.click(within(grupo).getByRole('radio', { name: 'Só símbolos' }));
    expect(lateral).toHaveClass('ant-layout-sider-collapsed');
    expect(within(grupo).getByRole('radio', { name: 'Só símbolos' })).toHaveAttribute('aria-checked', 'true');
    fireEvent.click(within(grupo).getByRole('radio', { name: 'Só nomes' }));
    expect(lateral).not.toHaveClass('ant-layout-sider-collapsed');
    expect(JSON.parse(localStorage.getItem('erp.aspecto.1') ?? '{}')).toEqual({ tema: 'claro', menu: 'nomes' });
  });

  it('a gaveta dos ecrãs pequenos não tem friso nem botões do aspecto', async () => {
    simularLargura(375);
    montar('/');
    fireEvent.click(screen.getByRole('button', { name: 'Abrir menu' }));
    const gaveta = await waitFor(() => {
      const g = document.querySelector('.erp-gaveta-menu') as HTMLElement | null;
      expect(g).not.toBeNull();
      return g as HTMLElement;
    });
    expect(within(gaveta).getByText('Menu Geral')).toBeInTheDocument();
    expect(within(gaveta).queryByRole('separator')).toBeNull();
    expect(within(gaveta).queryByRole('radiogroup')).toBeNull();
  });
});
