import { configure, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { ReactNode } from 'react';
import type { FichaOrdem } from './apiObras';
import { CALENDARIO, fichaOrdem, FICHAS, LISTAS_OBRAS, necessidade, ordem, PLANO, RESUMO_OBRAS } from './fixturesObras';
import { FRACOES, IMOVEL } from './fixturesParque';

vi.setConfig({ testTimeout: 120_000 });
configure({ asyncUtilTimeout: 15_000 });

const obterMock = vi.fn();
const enviarMock = vi.fn();
let permissoes = new Set<string>();
vi.mock('@/api/cliente', () => ({ obter: (...a: unknown[]) => obterMock(...a), enviar: (...a: unknown[]) => enviarMock(...a) }));
vi.mock('@/sessao/SessaoContexto', () => ({ useSessao: () => ({ pode: (...p: string[]) => p.some((x) => permissoes.has(x)), menu: [] }) }));

import Obras from './Obras';

const ecra = (ui: ReactNode) =>
  render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><MemoryRouter>{ui}</MemoryRouter></QueryClientProvider>);

/** O antd usa o mesmo id para o título de todos os diálogos nos testes: com um diálogo sobre a gaveta acha-se o modal pelo título. */
const modal = async (titulo: string): Promise<HTMLElement> => (await screen.findByText(titulo, { selector: '.ant-modal-title' })).closest('.ant-modal') as HTMLElement;

const TODAS = ['imo_obras_view', 'imo_fichas_view', 'imo_obra_editar', 'imo_obra_aprovar', 'imo_obra_pedir', 'imo_obra_receber', 'imo_obra_fechar', 'imo_bens_editar', 'imo_plano_editar', 'imo_plano_gerar', 'imo_sub_debitar'];
let ficha: FichaOrdem = fichaOrdem();

beforeEach(() => {
  obterMock.mockReset(); enviarMock.mockReset();
  permissoes = new Set(TODAS);
  ficha = fichaOrdem();
  obterMock.mockImplementation((url: string) => {
    if (url === '/imobiliario/obras/listas') return Promise.resolve(LISTAS_OBRAS);
    if (url === '/imobiliario/obras/resumo') return Promise.resolve(RESUMO_OBRAS);
    if (url === '/imobiliario/obras/avisos') return Promise.resolve([{ tipo: 'ORDEM_ATRASADA', texto: 'A ordem OB 2026/0001 devia estar feita em 10/03/2026.', ordem_id: 31 }]);
    if (url === '/imobiliario/obras/ordens') return Promise.resolve([ordem(), ordem({ id: 32, numero: 'AP 2026/0001', tipo: 'APETRECHAMENTO', estado: 'AGUARDA_MATERIAL', aprovada: true, aprovada_em: '2026-03-02T10:00:00+01:00', em_atraso: false, data_prevista: null, debitavel: false, quem_paga: 'SENHORIO' })]);
    if (url === '/imobiliario/obras/ordens/31') return Promise.resolve(ficha);
    if (url === '/imobiliario/obras/ordens/31/plano-pedido') return Promise.resolve([
      { necessidade_id: 1, nome: 'CAMA — Cama de casal', produto_id: 11, quantidade: '5.000', stock: '3.000', para_armazem: '3.000', para_compra: '2.000', porque: 'O stock só dá para 3 — a falta vai a compras', bloqueada: false },
      { necessidade_id: 2, nome: 'Cortinas', produto_id: null, quantidade: '4.000', stock: null, para_armazem: '0.000', para_compra: '0.000', porque: 'Linha sem artigo', bloqueada: true }]);
    if (url === '/imobiliario/obras/armazens') return Promise.resolve([{ id: 2, nome: 'Armazém central' }]);
    if (url === '/imobiliario/planos') return Promise.resolve([PLANO]);
    if (url === '/imobiliario/planos/9/calendario') return Promise.resolve(CALENDARIO);
    if (url === '/imobiliario/imoveis') return Promise.resolve([IMOVEL]);
    if (url === '/imobiliario/imoveis/7/fracoes') return Promise.resolve(FRACOES);
    if (url === '/imobiliario/fichas') return Promise.resolve(FICHAS);
    if (url === '/imobiliario/fracoes/3/bens') return Promise.resolve({ fracao: { id: 3, designacao: '101', imovel_id: 7, imovel_nome: 'Edifício Girassol', imovel_codigo: 'PR0001', ligada_a_porta: true },
      bens: [{ id: 5, fracao_id: 3, produto_id: 11, descricao: null, quantidade: 1, ativo_id: null, estado: 'BOM', origem: 'COMPRA', data: '2026-03-02', notas: null, nome: 'CAMA — Cama de casal' }] });
    if (url === '/imobiliario/fracoes/3/comparar-ficha') return Promise.resolve({ ficha: { id: 2, nome: 'T2 mobilado' }, faltam: 1, faltam_obrigatorios: 1, completa: false,
      linhas: [{ nome: 'CAMA — Cama de casal', produto_id: 11, descricao: null, obrigatorio: true, precisa: '2.000', tem: '1.000', falta: '1.000', danificados: 0 }, { nome: 'Mesa de jantar', produto_id: null, descricao: 'Mesa de jantar', obrigatorio: false, precisa: '1.000', tem: '1.000', falta: '0.000', danificados: 0 }] });
    return Promise.resolve([]);
  });
  enviarMock.mockResolvedValue({ dados: { id: 31, numero: 'OB 2026/0001', criadas: [], falhas: [], valor: 30000, renda: { id: 4, periodo: '2026-03', total_facturar: 130000 }, repetido: false, ordem: ordem(), necessidades: 1 }, mensagem: 'Feito.' });
});

const abrir = async () => fireEvent.click(await screen.findByRole('button', { name: 'Abrir a ordem OB 2026/0001' }));

describe('Gestão Imobiliária — Apetrechamento e Manutenção', () => {
  it('mostra os indicadores, os avisos e as ordens com o estado, a aprovação e o atraso sempre em texto', async () => {
    ecra(<Obras />);
    const linha = (await screen.findByText('OB 2026/0001')).closest('tr') as HTMLElement;
    expect(within(linha).getByText('Reparação')).toBeInTheDocument();
    expect(within(linha).getByText('Aberta')).toBeInTheDocument();
    expect(within(linha).getByText('Por aprovar')).toBeInTheDocument();
    expect(within(linha).getByText('em atraso')).toBeInTheDocument();
    expect(within(linha).getByText('a debitar')).toBeInTheDocument();
    const outra = screen.getByText('AP 2026/0001').closest('tr') as HTMLElement;
    expect(within(outra).getByText('Aguarda material')).toBeInTheDocument();
    expect(within(outra).getByText('Aprovada')).toBeInTheDocument();
    for (const t of ['Abertas', 'Aguarda material', 'Em curso', 'Urgentes por fechar', 'Com prazo ultrapassado', 'Por debitar ao inquilino']) expect(screen.getAllByText(t).length).toBeGreaterThan(0);
    expect(screen.getByText('1 ponto(s) a tratar')).toBeInTheDocument();
    expect(screen.getByText(/A ordem OB 2026\/0001 devia estar feita em 10\/03\/2026/)).toBeInTheDocument();
    expect(screen.getByRole('tab', { name: 'Planos (1)' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Gerar as ordens em dívida/ })).toBeInTheDocument();
  });

  it('a ordem por aprovar mostra o aviso, quem aprova vê Aprovar e não vê Pedir material; aprovar envia o pedido', async () => {
    ficha = fichaOrdem({ por_pedir: 2, necessidades_lista: [necessidade()] });
    ecra(<Obras />);
    await abrir();
    expect(await screen.findByText(/Ordem por aprovar: só depois de aprovada/)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Pedir material/ })).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: 'Aprovar' }));
    await waitFor(() => expect(enviarMock).toHaveBeenCalledWith('post', '/imobiliario/obras/ordens/31/aprovar', {}));
  });

  it('quem não aprova só vê Pedir material depois da aprovação e quem aprova não regista entregas', async () => {
    permissoes = new Set(['imo_obras_view', 'imo_obra_pedir', 'imo_obra_receber']);
    ficha = fichaOrdem({ por_pedir: 1, necessidades_lista: [necessidade()] });
    ecra(<Obras />);
    await abrir();
    await screen.findByText(/Ordem por aprovar/);
    expect(screen.queryByRole('button', { name: 'Aprovar' })).toBeNull();
    expect(screen.queryByRole('button', { name: /Pedir material/ })).toBeNull();
    expect(screen.queryByRole('button', { name: 'Editar' })).toBeNull();
    expect(screen.queryByRole('button', { name: 'Concluir' })).toBeNull();
  });

  it('pedir material mostra o plano (armazém, compras, porquê) e envia o armazém escolhido', async () => {
    ficha = fichaOrdem({ aprovada: true, aprovada_em: '2026-03-02T10:00:00+01:00', por_pedir: 2, necessidades_lista: [necessidade(), necessidade({ id: 2, produto_id: null, descricao: 'Cortinas', nome: 'Cortinas', quantidade: '4.000' })] });
    ecra(<Obras />);
    await abrir();
    fireEvent.click(await screen.findByRole('button', { name: 'Pedir material (2 por pedir)' }));
    const d = await modal('Pedir material');
    expect(await within(d).findByText('O stock só dá para 3 — a falta vai a compras')).toBeInTheDocument();
    expect(within(d).getByText(/Há linhas sem artigo/)).toBeInTheDocument();
    expect(within(d).getByText('1 linha(s) ao armazém · 1 linha(s) a compras.')).toBeInTheDocument();
    fireEvent.click(within(d).getByRole('button', { name: 'Pedir' }));
    await waitFor(() => expect(enviarMock).toHaveBeenCalledWith('post', '/imobiliario/obras/ordens/31/pedir', { armazem_id: 2, requerente: undefined }));
  });

  it('concluir com material por receber pede confirmação e só então cancela o que falta', async () => {
    ficha = fichaOrdem({ estado: 'AGUARDA_MATERIAL', aprovada: true, necessidades_lista: [necessidade({ estado: 'PEDIDO_COMPRA', quantidade_compra: '5.000' })] });
    enviarMock.mockImplementation((_m: string, url: string, corpo: Record<string, unknown>) => {
      if (url.endsWith('/concluir') && corpo.cancelar_pendentes === false) return Promise.reject(Object.assign(new Error('A ordem tem 1 necessidade(s) por receber (requisição ou pedido de compra).'), { codigo: 'OBRA_PENDENTES' }));
      return Promise.resolve({ dados: {}, mensagem: 'Ordem concluída.' });
    });
    ecra(<Obras />);
    await abrir();
    await waitFor(() => expect(enviarMock).toHaveBeenCalledWith('post', '/imobiliario/obras/ordens/31/sincronizar', {}));   // ao abrir actualiza as entregas já feitas
    fireEvent.click(await screen.findByRole('button', { name: 'Concluir' }));
    expect((await screen.findAllByText('Concluir mesmo assim?')).length).toBeGreaterThan(0);
    expect(enviarMock).toHaveBeenCalledWith('post', '/imobiliario/obras/ordens/31/concluir', { cancelar_pendentes: false });
    fireEvent.click(screen.getByRole('button', { name: 'Concluir na mesma' }));
    await waitFor(() => expect(enviarMock).toHaveBeenCalledWith('post', '/imobiliario/obras/ordens/31/concluir', { cancelar_pendentes: true }));
  });

  it('a obra concluída, aprovada e por debitar debita-se ao inquilino; a debitada só deixa desfazer', async () => {
    ficha = fichaOrdem({ estado: 'CONCLUIDA', aprovada: true, por_debitar: true });
    ecra(<Obras />);
    await abrir();
    expect(await screen.findByText(/Concluída e por debitar ao inquilino/)).toBeInTheDocument();
    for (const nome of ['Editar', 'Aprovar', 'Concluir', 'Cancelar a ordem']) expect(screen.queryByRole('button', { name: nome })).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: 'Debitar ao inquilino' }));
    await waitFor(() => expect(enviarMock).toHaveBeenCalledWith('post', '/imobiliario/obras/ordens/31/debitar', {}));
  });

  it('a ordem já debitada mostra o valor e só oferece desfazer o débito; sem a permissão nem isso', async () => {
    ficha = fichaOrdem({ estado: 'CONCLUIDA', aprovada: true, por_debitar: false, debitado_em: '2026-03-20T10:00:00+01:00', debitado_valor: 30000 });
    ecra(<Obras />);
    await abrir();
    expect(await screen.findByText('Debitada ao inquilino: 30 000,00 Kz.')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Debitar ao inquilino' })).toBeNull();
    expect(screen.getByRole('button', { name: 'Desfazer o débito' })).toBeInTheDocument();
  });

  it('sem imo_sub_debitar não há botão de débito (quem aprova rendas não debita)', async () => {
    permissoes = new Set(['imo_obras_view', 'imo_renda_aprovar']);
    ficha = fichaOrdem({ estado: 'CONCLUIDA', aprovada: true, por_debitar: true });
    ecra(<Obras />);
    await abrir();
    await screen.findByText(/Concluída e por debitar ao inquilino/);
    expect(screen.queryByRole('button', { name: 'Debitar ao inquilino' })).toBeNull();
  });

  it('o separador Planos lista o plano e o calendário marca em falta e por gerar; gerar as próximas envia o pedido', async () => {
    ecra(<Obras />);
    fireEvent.click(await screen.findByRole('tab', { name: 'Planos (1)' }));
    const linha = (await screen.findByText('Pintura')).closest('tr') as HTMLElement;
    expect(within(linha).getByText('Manutenção preventiva')).toBeInTheDocument();
    expect(within(linha).getByText('4 (0 feita(s))')).toBeInTheDocument();
    expect(within(linha).getByText('2026-03-31')).toBeInTheDocument();
    expect(within(linha).getByText('Activo')).toBeInTheDocument();
    fireEvent.click(within(linha).getByRole('button', { name: 'Calendário de Pintura' }));
    const d = await modal('Calendário — Pintura');
    expect((await within(d).findAllByText('Em falta')).length).toBe(2);
    expect(within(d).getAllByText('Por gerar').length).toBe(2);
    expect(within(d).getByText(/2 data\(s\) já passaram sem ordem gerada/)).toBeInTheDocument();
    expect(within(d).getByText(/dentro dos 30 dias de antecedência do plano \(até 2026-04-19\)/)).toBeInTheDocument();
    fireEvent.click(within(d).getByRole('button', { name: 'Gerar as próximas' }));
    await waitFor(() => expect(enviarMock).toHaveBeenCalledWith('post', '/imobiliario/planos/9/gerar', { todas: false }));
    fireEvent.click(within(d).getByRole('button', { name: 'Gerar todas' }));
    await waitFor(() => expect(enviarMock).toHaveBeenCalledWith('post', '/imobiliario/planos/9/gerar', { todas: true }));
  });

  it('editar o plano mostra as datas escritas e grava-as uma a uma', async () => {
    ecra(<Obras />);
    fireEvent.click(await screen.findByRole('tab', { name: 'Planos (1)' }));
    fireEvent.click(await screen.findByRole('button', { name: 'Editar o plano Pintura' }));
    const d = await modal('Editar o plano «Pintura»');
    expect(within(d).getByLabelText('Data 3 do plano')).toHaveValue('31/03/2026');
    fireEvent.click(within(d).getByRole('button', { name: 'Retirar a data 4' }));
    fireEvent.click(within(d).getByRole('button', { name: 'Gravar' }));
    await waitFor(() => expect(enviarMock).toHaveBeenCalledTimes(1));
    const [metodo, url, corpo] = enviarMock.mock.calls[0] as [string, string, { datas: string[]; antecedencia_dias: number; tipo: string }];
    expect([metodo, url, corpo.tipo, corpo.antecedencia_dias]).toEqual(['put', '/imobiliario/planos/9', 'PREVENTIVA', 30]);
    expect(corpo.datas).toEqual(['2026-01-31', '2026-02-28', '2026-03-31']);
  });

  it('os bens da fracção: escolhe o imóvel e a fracção, compara com a ficha e abre a ordem de apetrechamento', async () => {
    ecra(<Obras />);
    fireEvent.click(await screen.findByRole('tab', { name: 'Bens das fracções' }));
    expect(await screen.findByText('Escolha o imóvel e a fracção para ver o que está lá dentro e o que falta.')).toBeInTheDocument();
    fireEvent.mouseDown(screen.getByLabelText('Imóvel', { selector: '#imo-bens-imovel' }));
    fireEvent.click(await screen.findByText('PR0001 · Edifício Girassol', { selector: '.ant-select-item-option-content' }));
    fireEvent.mouseDown(screen.getByLabelText('Fracção'));
    fireEvent.click(await screen.findByText('101', { selector: '.ant-select-item-option-content' }));
    const linha = (await screen.findByText('CAMA — Cama de casal')).closest('tr') as HTMLElement;
    expect(within(linha).getByText('Bom estado')).toBeInTheDocument();
    expect(within(linha).getByText('Comprado')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Trazer do imobilizado' })).toBeInTheDocument();
    fireEvent.mouseDown(screen.getByLabelText('Comparar com uma ficha'));
    fireEvent.click(await screen.findByText('T2 mobilado', { selector: '.ant-select-item-option-content' }));
    expect(await screen.findByText('Faltam 1 artigo(s) — 1 obrigatório(s)')).toBeInTheDocument();
    expect(screen.getByText(/Mesa de jantar \(opcional\)/)).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Abrir ordem de apetrechamento' }));
    await waitFor(() => expect(enviarMock).toHaveBeenCalledWith('post', '/imobiliario/obras/fracoes/3/abrir-apetrechamento', { ficha_id: 2, prioridade: undefined }));
  });

  it('sem permissões de edição não há botões de criar nem de gerar', async () => {
    permissoes = new Set(['imo_obras_view']);
    ecra(<Obras />);
    await screen.findByText('OB 2026/0001');
    expect(screen.queryByRole('button', { name: /Nova ordem/ })).toBeNull();
    expect(screen.queryByRole('button', { name: /Gerar as ordens em dívida/ })).toBeNull();
    fireEvent.click(screen.getByRole('tab', { name: 'Planos (1)' }));
    await screen.findByText('Pintura');
    expect(screen.queryByRole('button', { name: /Novo plano/ })).toBeNull();
    expect(screen.queryByRole('button', { name: 'Editar o plano Pintura' })).toBeNull();
  });
});
