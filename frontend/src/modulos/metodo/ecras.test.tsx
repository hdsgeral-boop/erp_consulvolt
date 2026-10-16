import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { ReactNode } from 'react';
import { ecras } from './ecras';

vi.setConfig({ testTimeout: 180_000 });

// dados fictícios
const RESUMO = {
  id: 5, numero: 'AS 2026/001', titulo: 'Bomba de água avariada', estado: 'RASCUNHO', responsavel_principal_nome: 'Técnico Fictício', local_tipo: 'INTERNO',
  unidade_negocio_id: 1, unidade_negocio_nome: 'Produção fictícia', cliente_id: null, cliente_nome: null, encomenda: null, data_inicio: '2026-11-01',
  data_fim: '2026-11-20', custo_estimado: '480000.00', codigo_moeda: 'AOA', metodo_resolucao: 'PROJECTO', projeto_id: null, projeto: null,
  pedido_compra_id: null, pedido_compra: null, deliberacao_estado: 'PENDENTE', em_atraso: false, pode_decidir: true, criado_em: null, criado_por: 'tecnico',
};
const LISTA = { assuntos: [RESUMO, { ...RESUMO, id: 6, numero: 'AS 2026/002', titulo: 'Rever turnos', estado: 'EM_CURSO', deliberacao_estado: 'APROVADO', pode_decidir: false,
  custo_estimado: '20000.00', em_atraso: true, responsavel_principal_nome: 'Ana Fictícia' }],
alertas: { por_vencer: [], atrasados: ['AS 2026/002'], bloqueados: [], aguardam_minha_decisao: ['AS 2026/001'], horas_aviso: 48 } };
const DETALHE = {
  ...RESUMO, descricao: null, responsaveis: [{ tipo: 'COLABORADOR', ref_id: 2, nome: 'Técnico Fictício' }], responsavel_principal: 'COLABORADOR:2', morada: null,
  fundamentacao: 'A linha 2 está parada há dois dias por falta de pressão de água no circuito.', causa_raiz: null, recursos: 'Bomba nova', projeto_modo: 'NOVO',
  centro_custo_id: 1, encomenda_venda_id: null, gerar_pedido_compra: false, linhas_pedido_compra: [], anexos: [{ nome: 'Orçamento', url: 'https://exemplo.invalid/o.pdf' }],
  deliberacao: { estado: 'PENDENTE', aprovador_nome: 'Directora Fictícia', aprovador_utilizador: 'directora', motivo_aprovador: 'Responsável do nível acima no organigrama.' },
  motivo_cancelamento: null,
  permissoes: { ver: true, editar: false, submeter: false, decidir: true, concluir: false, cancelar: false, reabrir: false, gestor: false },
};
const OPCOES = { colaboradores: [{ id: 2, nome_completo: 'Técnico Fictício' }], fornecedores: [], clientes: [], unidades_negocio: [{ id: 1, codigo: 'UN1', nome: 'Produção fictícia' }],
  centros_custo: [{ id: 1, codigo: 'CC1', descricao: 'Fábrica' }], pode_criar: true, gestor: false };

const obterMock = vi.fn();
const enviarMock = vi.fn(() => Promise.resolve({ dados: null, mensagem: 'ok' }));
let permissoes = new Set<string>();
vi.mock('@/api/cliente', () => ({
  obter: (...a: unknown[]) => obterMock(...a),
  enviar: (...a: unknown[]) => enviarMock(...(a as [])),
}));
vi.mock('@/sessao/SessaoContexto', () => ({
  useSessao: () => ({ pode: (...p: string[]) => p.some((x) => permissoes.has(x)), menu: [], empresa: { id: 1, nome: 'Empresa Fictícia' }, utilizador: null, preferencias: {} }),
}));

const envolver = (ui: ReactNode) =>
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter>{ui}</MemoryRouter>
    </QueryClientProvider>,
  );

beforeEach(() => {
  obterMock.mockReset();
  enviarMock.mockClear();
  obterMock.mockImplementation((url: string) => Promise.resolve(
    url === '/metodo/assuntos' ? LISTA : url === '/metodo/assuntos/opcoes' ? OPCOES : url === '/metodo/assuntos/5' ? DETALHE
      : url === '/metodo/assuntos/aprovador' ? { colaborador_id: 9, nome: 'Directora Fictícia', utilizador: 'directora', proprio: false, motivo: 'Responsável do nível acima no organigrama.' }
        : url === '/metodo/assuntos/5/historico' ? { numero: 'AS 2026/001', registos: [{ id: 1, acao: 'Submetido a aprovação', detalhe: 'Aguarda Directora Fictícia.', por: 'tecnico', em: '2026-10-14T10:00:00Z' }] }
          : [],
  ));
});

describe('Método de Trabalho — Registo de Assuntos (Fase G)', () => {
  it('o ecrã mt_assuntos está registado', () => {
    expect(Object.keys(ecras)).toEqual(['mt_assuntos']);
  });

  it('lista com alertas, decisão pendente e vistas', async () => {
    permissoes = new Set(['mt_assuntos_view', 'mt_criar']);
    const { default: Assuntos } = await import('./Assuntos');
    envolver(<Assuntos />);
    await screen.findByText('Bomba de água avariada', undefined, { timeout: 60_000 });
    expect(screen.getByText(/1 assunto\(s\) à espera da sua decisão: AS 2026\/001/)).toBeTruthy();
    expect(screen.getByText(/1 assunto\(s\) com o prazo ultrapassado: AS 2026\/002/)).toBeTruthy();
    expect(screen.getByText('Decisão sua')).toBeTruthy();
    expect(screen.getByRole('button', { name: /Novo assunto/ })).toBeTruthy();
    // vista por responsável: um grupo por nome
    fireEvent.click(screen.getByRole('tab', { name: /Por responsável/ }));
    expect((await screen.findAllByText('Ana Fictícia')).length).toBe(2);   // título do grupo e célula
    // vista por custo: painel
    fireEvent.click(screen.getByRole('tab', { name: /Por custo/ }));
    expect(await screen.findByText('Custo estimado total')).toBeTruthy();
  });

  it('sem mt_criar não há «Novo assunto»; histórico abre', async () => {
    permissoes = new Set(['mt_assuntos_view']);
    const { default: Assuntos } = await import('./Assuntos');
    envolver(<Assuntos />);
    await screen.findByText('Bomba de água avariada', undefined, { timeout: 60_000 });
    expect(screen.queryByRole('button', { name: /Novo assunto/ })).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: 'Histórico AS 2026/001' }));
    expect(await screen.findByText('Submetido a aprovação')).toBeTruthy();
  });

  it('a chefia aprova pelo painel da deliberação', async () => {
    permissoes = new Set(['mt_assuntos_view']);
    const { default: Assuntos } = await import('./Assuntos');
    envolver(<Assuntos />);
    await screen.findByText('Bomba de água avariada', undefined, { timeout: 60_000 });
    fireEvent.click(screen.getByRole('button', { name: 'Abrir AS 2026/001' }));
    const dialogo = await screen.findByRole('dialog', {}, { timeout: 60_000 });
    await within(dialogo).findByText(/Só de leitura: o assunto aguarda aprovação/, undefined, { timeout: 60_000 });
    expect(within(dialogo).getByText('Directora Fictícia')).toBeTruthy();
    fireEvent.click(within(dialogo).getByRole('button', { name: /Aprovar e pôr em curso/ }));
    await waitFor(() => expect(enviarMock).toHaveBeenCalledWith('post', '/metodo/assuntos/5/decidir', { accao: 'APROVAR', nota: undefined }));
  });

  it('«Novo assunto»: os blocos 5W1H abrem e fecham (Grade com secções), com os campos montados', async () => {
    permissoes = new Set(['mt_assuntos_view', 'mt_criar']);
    const { default: Assuntos } = await import('./Assuntos');
    envolver(<Assuntos />);
    await screen.findByText('Bomba de água avariada', undefined, { timeout: 60_000 });
    fireEvent.click(screen.getByRole('button', { name: /Novo assunto/ }));
    const dialogo = await screen.findByRole('dialog', {}, { timeout: 60_000 });
    const why = within(dialogo).getByRole('button', { name: /WHY/ });
    expect(why).toHaveAttribute('aria-expanded', 'true');
    fireEvent.click(why);
    expect(why).toHaveAttribute('aria-expanded', 'false');
    expect(dialogo.querySelector('#' + CSS.escape(why.getAttribute('aria-controls') ?? ''))).toHaveAttribute('hidden');
    expect(within(dialogo).getAllByRole('button', { name: /WHAT|WHO|WHEN|WHERE|WHY|HOW/ })).toHaveLength(6);
  });
});
