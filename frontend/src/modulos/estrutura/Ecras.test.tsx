import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';

vi.setConfig({ testTimeout: 180_000 });
import type { ReactNode } from 'react';
import type { Estrutura as DadosEstrutura } from './comum/arvore';

const ESTRUTURA: DadosEstrutura = {
  sem_unidade: 1,
  unidades: [
    { id: 1, codigo: 'DG', nome: 'Direcção-Geral', tipo: 'DIRECCAO_GERAL', unidade_organica_pai_id: null, colaborador_responsavel_id: 1, missao: null, atribuicoes: null, centro_custo_id: null, unidade_negocio_id: null, ordem: 1, ativo: true, apoio: false, cor: null, disposicao: null, membros: 1,
      postos: [{ id: 10, unidade_organica_id: 1, cargo_funcao_id: null, titulo: 'Director-Geral', vagas: 1, posto_superior_id: null, responsabilidades: null, chefia: true, ordem: 1, ocupados: 1, livres: 0 }] },
    { id: 2, codigo: null, nome: 'Finanças', tipo: 'DEPARTAMENTO', unidade_organica_pai_id: 1, colaborador_responsavel_id: null, missao: null, atribuicoes: null, centro_custo_id: null, unidade_negocio_id: null, ordem: 1, ativo: true, apoio: false, cor: null, disposicao: null, membros: 0,
      postos: [{ id: 20, unidade_organica_id: 2, cargo_funcao_id: null, titulo: 'Contabilista', vagas: 2, posto_superior_id: null, responsabilidades: null, chefia: false, ordem: 1, ocupados: 0, livres: 2 }] },
    { id: 3, codigo: null, nome: 'Vendas', tipo: 'DEPARTAMENTO', unidade_organica_pai_id: 1, colaborador_responsavel_id: null, missao: null, atribuicoes: null, centro_custo_id: null, unidade_negocio_id: null, ordem: 2, ativo: true, apoio: false, cor: null, disposicao: null, membros: 0, postos: [] },
  ],
};
const COLABS = [
  { id: 1, nome_completo: 'Ana Fictícia', estado: 'ACTIVO', unidade_organica_id: 1, posto_trabalho_id: 10, colaborador_gestor_id: null, cargo_funcao_id: null },
  { id: 2, nome_completo: 'Rui Fictício', estado: 'ACTIVO', unidade_organica_id: null, posto_trabalho_id: null, colaborador_gestor_id: null, cargo_funcao_id: null },
];

const obterMock = vi.fn();
const enviarMock = vi.fn();
const navegarMock = vi.fn();
let permissoes = new Set<string>();
vi.mock('@/api/cliente', () => ({ obter: (...a: unknown[]) => obterMock(...a), enviar: (...a: unknown[]) => enviarMock(...a) }));
vi.mock('@/sessao/SessaoContexto', () => ({ useSessao: () => ({ pode: (...p: string[]) => p.some((x) => permissoes.has(x)), menu: [] }) }));
vi.mock('@/modulos/rh/comum/consultas', () => {
  const mapa = new Map(COLABS.map((c) => [c.id, c]));
  return {
    useColaboradores: () => ({ lista: COLABS, mapa, isLoading: false, nome: (id: number | null) => (id ? mapa.get(id)?.nome_completo ?? `#${id}` : '—') }),
    useCargos: () => ({ lista: [], isLoading: false, nome: () => '—' }),
  };
});
vi.mock('@/modulos/rh/comum/componentes', () => ({ SeletorColaborador: () => null }));
vi.mock('@/modulos/contab/comum/dados', () => ({ useTabelaAux: () => ({ data: [] }), useUnidadesNegocio: () => ({ data: [] }) }));
vi.mock('react-router-dom', async (orig) => ({ ...(await orig<typeof import('react-router-dom')>()), useNavigate: () => navegarMock }));

const envolver = (ui: ReactNode) =>
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter>{ui}</MemoryRouter>
    </QueryClientProvider>,
  );

beforeEach(() => {
  obterMock.mockReset();
  enviarMock.mockReset();
  obterMock.mockImplementation((url: string) => {
    if (url === '/rh/estrutura') return Promise.resolve(ESTRUTURA);
    if (url === '/estrutura/salarios') return Promise.resolve({ periodo_salarial: '08/2026', por_colaborador: [{ colaborador_id: 1, valor: '250000.00' }] });
    return Promise.resolve([]);
  });
  enviarMock.mockResolvedValue({ dados: { unidades: 1 }, mensagem: 'ok' });
});

describe('Estrutura orgânica — ecrã do antigo', () => {
  it('árvore à esquerda, detalhe à direita, colaboradores afectos e botões do antigo', async () => {
    permissoes = new Set(['est_estrutura_view', 'est_editar', 'est_eliminar', 'est_ver_salarios']);
    const { default: Estrutura } = await import('./Estrutura');
    envolver(<Estrutura />);
    const arvore = await screen.findByRole('navigation', { name: 'Unidades' }, { timeout: 60_000 });
    // «Direcção-Geral · 1 · 2» (pessoas do ramo · vagas em aberto)
    expect(within(arvore).getByText('Direcção-Geral').parentElement!.textContent).toContain('1 · 2');
    for (const b of ['Organigrama', 'Mapa de pessoal', 'Afectar colaboradores', 'Várias unidades', 'Nova unidade', 'Subunidade', 'Várias subunidades', 'Editar']) {
      expect(screen.getAllByRole('button', { name: new RegExp(b) }).length).toBeGreaterThan(0);
    }
    expect(screen.getByText('Cargos e vagas')).toBeTruthy();
    expect(screen.getByText('Colaboradores afectos (1)')).toBeTruthy();
    for (const c of ['Colaborador', 'Função', 'Cargo', 'Chefia directa', 'Salário mensal']) expect(screen.getAllByText(c).length).toBeGreaterThan(0);
    expect(screen.getByRole('button', { name: /Alterar/ })).toBeTruthy();
    await waitFor(() => expect(screen.getAllByText(/250\s?000,00/).length).toBeGreaterThan(0), { timeout: 30_000 });
    expect(screen.getByText(/1 colaborador\(es\) activos sem unidade: Rui Fictício/)).toBeTruthy();
    // Subunidades → selecciona Finanças
    fireEvent.click(screen.getByRole('button', { name: /Finanças · 0/ }));
    expect(await screen.findByText('Colaboradores afectos (0)')).toBeTruthy();
  });

  it('sem permissão de edição nem de salários: sem botões de escrita e sem salários', async () => {
    permissoes = new Set(['est_estrutura_view']);
    const { default: Estrutura } = await import('./Estrutura');
    envolver(<Estrutura />);
    await screen.findByRole('navigation', { name: 'Unidades' }, { timeout: 60_000 });
    expect(screen.queryByRole('button', { name: /Afectar colaboradores/ })).toBeNull();
    expect(screen.queryByText('Salário mensal')).toBeNull();
    expect(obterMock).not.toHaveBeenCalledWith('/estrutura/salarios');
  });
});

describe('Organigrama — controlos do antigo', () => {
  it('Funcional/Nominal, Vertical/Horizontal, zoom, Expandir/Recolher, Empilhar, Imprimir e Ecrã inteiro', async () => {
    permissoes = new Set(['est_estrutura_view', 'est_editar']);
    const { default: Organigrama } = await import('./Organigrama');
    envolver(<Organigrama />);
    await screen.findByRole('button', { name: 'Finanças' }, { timeout: 60_000 });
    for (const b of ['Editar estrutura', 'Funcional', 'Nominal', 'Vertical', 'Horizontal', 'Diminuir', 'Aumentar', 'Expandir', 'Recolher', 'Empilhar últimos níveis', 'Tudo lado a lado', 'Imprimir', 'Ecrã inteiro']) {
      expect(screen.getAllByRole('button', { name: new RegExp(b) }).length).toBeGreaterThan(0);
    }
    fireEvent.click(screen.getByRole('button', { name: 'Aumentar' }));
    expect(screen.getByRole('button', { name: 'Ajustar' }).textContent).toBe('115%');
    fireEvent.click(screen.getByRole('button', { name: 'Ajustar' }));
    expect(screen.getByRole('button', { name: 'Ajustar' }).textContent).toBe('100%');
    // Nominal mostra o responsável
    fireEvent.click(screen.getByRole('button', { name: /Nominal/ }));
    expect(screen.getAllByText(/Ana Fictícia/).length).toBeGreaterThan(0);
    // Horizontal esconde a disposição (só vertical)
    fireEvent.click(screen.getByRole('button', { name: /Horizontal/ }));
    expect(screen.queryByRole('button', { name: /Empilhar últimos níveis/ })).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: /Vertical/ }));
    // Empilhar últimos níveis: Direcção-Geral tem 2 subunidades de último nível
    fireEvent.click(screen.getByRole('button', { name: /Empilhar últimos níveis/ }));
    await waitFor(() => expect(enviarMock).toHaveBeenCalledWith('post', '/estrutura/disposicao', { ids: [1], disposicao: 'COLUNA' }), { timeout: 30_000 });
    // Recolher não fecha a raiz; o ± da caixa recolhe o ramo
    fireEvent.click(screen.getByRole('button', { name: 'Recolher Direcção-Geral' }));
    expect(screen.queryByRole('button', { name: 'Finanças' })).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: 'Expandir' }));
    expect(screen.getByRole('button', { name: 'Finanças' })).toBeTruthy();
  });
});

describe('Mapa de pessoal — vista do antigo', () => {
  it('uma tabela UNIDADE, CARGO, VAGAS, OCUPADOS, EM ABERTO, ACIMA, PESSOAS (+ massa com permissão)', async () => {
    permissoes = new Set(['est_mapa_view', 'est_ver_salarios']);
    const { default: MapaPessoal } = await import('./MapaPessoal');
    envolver(<MapaPessoal />);
    const tabela = (await screen.findByText('Director-Geral', {}, { timeout: 60_000 })).closest('table')!;
    const cab = within(tabela).getAllByRole('columnheader').map((th) => th.textContent);
    expect(cab).toEqual(['Unidade', 'Cargo', 'Vagas', 'Ocupados', 'Em aberto', 'Acima', 'Pessoas', 'Massa salarial']);
    expect(within(tabela).getByText('Ana Fictícia')).toBeTruthy();
    expect(within(tabela).getByText('Total')).toBeTruthy();
    expect(screen.getByText('Vagas previstas')).toBeTruthy();
  });
});
