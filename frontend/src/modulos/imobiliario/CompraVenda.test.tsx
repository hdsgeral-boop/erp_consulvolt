import { configure, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { ReactNode } from 'react';
import type { FichaNegocio } from './apiNegocios';
import { AVISOS_NEGOCIOS, fichaNegocio, LISTAS_NEGOCIOS, negocio, RESUMO_NEGOCIOS } from './fixturesNegocios';
import { FRACOES, IMOVEL } from './fixturesParque';

vi.setConfig({ testTimeout: 120_000 });
configure({ asyncUtilTimeout: 15_000 });

const obterMock = vi.fn();
const enviarMock = vi.fn();
let permissoes = new Set<string>();
vi.mock('@/api/cliente', () => ({ obter: (...a: unknown[]) => obterMock(...a), enviar: (...a: unknown[]) => enviarMock(...a) }));
vi.mock('@/sessao/SessaoContexto', () => ({ useSessao: () => ({ pode: (...p: string[]) => p.some((x) => permissoes.has(x)), menu: [] }) }));

import CompraVenda from './CompraVenda';

const ecra = (ui: ReactNode) =>
  render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><MemoryRouter>{ui}</MemoryRouter></QueryClientProvider>);

/** O antd usa o mesmo id para o título de todos os diálogos nos testes: com um diálogo sobre a gaveta acha-se o modal pelo título. */
const modal = async (titulo: string): Promise<HTMLElement> => (await screen.findByText(titulo, { selector: '.ant-modal-title' })).closest('.ant-modal') as HTMLElement;

const TODAS = ['imo_negocios_view', 'imo_neg_editar', 'imo_neg_aprovar', 'imo_neg_fase', 'imo_neg_concluir', 'imo_neg_abater', 'imo_neg_pagamento'];
let ficha: FichaNegocio = fichaNegocio();

beforeEach(() => {
  obterMock.mockReset(); enviarMock.mockReset();
  permissoes = new Set(TODAS);
  ficha = fichaNegocio();
  obterMock.mockImplementation((url: string) => {
    if (url === '/imobiliario/negocios/listas') return Promise.resolve(LISTAS_NEGOCIOS);
    if (url === '/imobiliario/negocios/resumo') return Promise.resolve(RESUMO_NEGOCIOS);
    if (url === '/imobiliario/negocios/avisos') return Promise.resolve(AVISOS_NEGOCIOS);
    if (url === '/imobiliario/negocios') return Promise.resolve([negocio(), negocio({ id: 22, numero: 'VD 2026/0001', tipo: 'VENDA', estado: 'CONCLUIDO', fracao_id: 3, fracao_designacao: '101', preco: 50000000, total_impostos: 1103000,
      aprovado_em: '2026-03-02T10:00:00+01:00', resultado_contabilistico: 14000000, mais_valia: 13700000, terceiro_nome: 'Comprador Fictício' })]);
    if (url === '/imobiliario/negocios/21') return Promise.resolve(ficha);
    if (url === '/imobiliario/negocios/categorias') return Promise.resolve([{ id: 4, nome: 'Edifícios', conta_ativo: '121' }]);
    if (url === '/imobiliario/imoveis') return Promise.resolve([IMOVEL]);
    if (url === '/imobiliario/imoveis/7/fracoes') return Promise.resolve(FRACOES);
    if (url === '/imobiliario/terceiros') return Promise.resolve([{ id: 5, nome: 'Vendedor Fictício', nif: '5000000555' }]);
    if (url === '/imobiliario/taxas') return Promise.resolve({ ano: 2026, taxas: { ip_transmissao: 2, selo_transmissao: 0.3, escritura_fixo: 2000 }, estado_validacao: 'POR_VALIDAR' });
    return Promise.resolve([]);
  });
  enviarMock.mockResolvedValue({ dados: { id: 21, numero: 'CP 2026/0002', avisos: [], terminados: 1, negocio: ficha }, mensagem: 'Feito.' });
});

const abrir = async () => fireEvent.click(await screen.findByRole('button', { name: 'Abrir o negócio CP 2026/0001' }));

describe('Gestão Imobiliária — Compra e Venda', () => {
  it('mostra os indicadores, os pontos a tratar e os negócios com a fase, o resultado e a aprovação sempre em texto', async () => {
    ecra(<CompraVenda />);
    const linha = (await screen.findByText('CP 2026/0001')).closest('tr') as HTMLElement;
    expect(within(linha).getByText('Compra')).toBeInTheDocument();
    expect(within(linha).getByText('Em negociação')).toBeInTheDocument();
    expect(within(linha).getByText('por aprovar')).toBeInTheDocument();
    const venda = screen.getByText('VD 2026/0001').closest('tr') as HTMLElement;
    expect(within(venda).getByText('Concluído')).toBeInTheDocument();
    expect(within(venda).getByText('Mais-valia')).toBeInTheDocument();
    expect(within(venda).getByText('14 000 000,00 Kz')).toBeInTheDocument();
    expect(within(venda).getByText('Comprador Fictício')).toBeInTheDocument();
    for (const t of ['Compras escrituradas em', 'Vendas em', 'Impostos da transmissão', 'Processos abertos']) expect(screen.getAllByText(new RegExp(t)).length).toBeGreaterThan(0);
    expect(screen.getByText('1 ponto(s) a tratar')).toBeInTheDocument();
    expect(screen.getByText(/venda concluída e ainda sem abate do bem/)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Novo negócio/ })).toBeInTheDocument();
  });

  it('a ficha mostra a barra de fases em texto, o que falta para avançar, os impostos e o custo de aquisição', async () => {
    ecra(<CompraVenda />);
    await abrir();
    expect(await screen.findByText('Negócio por aprovar: só depois de aprovado se avança de fase ou se registam pagamentos.')).toBeInTheDocument();
    expect(screen.getByText(/Em negociação \(agora\)/)).toBeInTheDocument();
    expect(screen.getByText('Para avançar para «Promessa assinada» falta:')).toBeInTheDocument();
    expect(screen.getByText('Indique a data da promessa.')).toBeInTheDocument();
    expect(screen.getAllByText('807 000,00 Kz').length).toBeGreaterThan(0);
    expect(screen.getByText('31 107 000,00 Kz')).toBeInTheDocument();
    expect(screen.getByText('50 000,00 Kz')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Avançar para «Promessa assinada»/ })).toBeDisabled();
  });

  it('aprovar envia o pedido e quem não pode aprovar não vê o botão nem o de pagamentos', async () => {
    ecra(<CompraVenda />);
    await abrir();
    fireEvent.click(await screen.findByRole('button', { name: 'Aprovar' }));
    await waitFor(() => expect(enviarMock).toHaveBeenCalledWith('post', '/imobiliario/negocios/21/aprovar', {}));
    expect(screen.queryByRole('button', { name: 'Registar pagamento' })).toBeNull();   // ainda por aprovar
  });

  it('quem aprova não regista pagamentos e quem paga não aprova (as tarefas são distintas)', async () => {
    permissoes = new Set(['imo_negocios_view', 'imo_neg_aprovar']);
    ecra(<CompraVenda />);
    await abrir();
    await screen.findByText(/Negócio por aprovar/);
    expect(screen.getByRole('button', { name: 'Aprovar' })).toBeInTheDocument();
    for (const nome of ['Editar', 'Registar pagamento', 'Concluir', 'Cancelar o negócio', /Avançar para/]) expect(screen.queryByRole('button', { name: nome })).toBeNull();
  });

  it('o negócio aprovado regista o pagamento com a chave e anula-o com motivo', async () => {
    ficha = fichaNegocio({ aprovado: true, aprovado_em: '2026-03-02T10:00:00+01:00', pago_total: 3000000, por_liquidar: 27000000, faltas: [],
      pagamentos: [{ id: 8, tipo: 'SINAL', valor: 3000000, data: '2026-03-05', meio: 'Transferência', referencia: 'TR-1', estado: 'VALIDO', anulado_motivo: null }] });
    ecra(<CompraVenda />);
    await abrir();
    expect(await screen.findByText('Transferência · TR-1')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Registar pagamento' }));
    const d = await modal('Registar pagamento ao vendedor');
    expect(within(d).getByText(/falta 27 000 000,00 Kz/)).toBeInTheDocument();
    fireEvent.change(within(d).getByLabelText('Valor (Kz)'), { target: { value: '5000000' } });
    fireEvent.click(within(d).getByRole('button', { name: 'Registar' }));
    await waitFor(() => expect(enviarMock).toHaveBeenCalledTimes(1));
    const [metodo, url, corpo] = enviarMock.mock.calls[0] as [string, string, { tipo: string; valor: number; chave: string }];
    expect([metodo, url, corpo.valor]).toEqual(['post', '/imobiliario/negocios/21/pagamentos', 5000000]);
    expect(corpo.chave).toMatch(/^pag-21-/);
    fireEvent.click(await screen.findByRole('button', { name: /Anular o pagamento de/ }));
    const m = await modal('Anular o pagamento');
    fireEvent.change(within(m).getByLabelText('Motivo'), { target: { value: 'Cheque devolvido' } });
    fireEvent.click(within(m).getByRole('button', { name: 'Anular o pagamento' }));
    await waitFor(() => expect(enviarMock).toHaveBeenCalledWith('post', '/imobiliario/negocios/21/pagamentos/8/anular', { motivo: 'Cheque devolvido' }));
  });

  it('concluir a compra pede a categoria do bem e envia-a; a venda mostra o resultado e que o abate é outro passo', async () => {
    ficha = fichaNegocio({ estado: 'ESCRITURA', aprovado: true, aprovado_em: '2026-03-02T10:00:00+01:00', faltas: [], fases_seguintes: ['REGISTADO'] });
    ecra(<CompraVenda />);
    await abrir();
    fireEvent.click(await screen.findByRole('button', { name: 'Concluir' }));
    const d = await modal('Concluir a compra');
    expect(within(d).getByText(/fica com o custo de aquisição de 31 107 000,00 Kz/)).toBeInTheDocument();
    fireEvent.mouseDown(within(d).getByLabelText('Categoria do bem no imobilizado'));
    fireEvent.click(await screen.findByText('Edifícios (121)', { selector: '.ant-select-item-option-content' }));
    fireEvent.click(within(d).getByRole('button', { name: 'Concluir' }));
    await waitFor(() => expect(enviarMock).toHaveBeenCalledWith('post', '/imobiliario/negocios/21/concluir', { categoria_ativo_id: 4 }));
  });

  it('a venda concluída com bem por abater mostra o aviso e o botão só a quem tem imo_neg_abater; abater envia o pedido', async () => {
    ficha = fichaNegocio({ tipo: 'VENDA', estado: 'CONCLUIDO', aprovado: true, ativo_id: 9, fases_seguintes: [], faltas: [], custo_aquisicao_calculado: null,
      resultado_venda: { conhecido: true, custo: '60000000.00', amortizacoes: '24000000.00', valor_liquido: '36000000.00', resultado_contabilistico: '14000000.00', mais_valia: '13700000.00', tipo: 'MAIS_VALIA', quota: '0.600000', criterio_quota: 'área útil', parcial: true } });
    ecra(<CompraVenda />);
    await abrir();
    expect(await screen.findByText(/falta o passo «Abater o bem»/)).toBeInTheDocument();
    expect(screen.getAllByText('Mais-valia').length).toBeGreaterThan(0);
    expect(screen.queryByRole('button', { name: 'Concluir' })).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: 'Abater o bem' }));
    const d = await modal('Abater o bem vendido');
    expect(within(d).getByText(/venda parcial: o bem continua no imobilizado/)).toBeInTheDocument();
    fireEvent.click(within(d).getByRole('button', { name: 'Abater e lançar' }));
    await waitFor(() => expect(enviarMock).toHaveBeenCalledWith('post', '/imobiliario/negocios/21/abater', {}));
  });

  it('quem conclui a venda não vê o botão de abater', async () => {
    permissoes = new Set(['imo_negocios_view', 'imo_neg_concluir']);
    ficha = fichaNegocio({ tipo: 'VENDA', estado: 'CONCLUIDO', aprovado: true, ativo_id: 9, fases_seguintes: [], faltas: [] });
    ecra(<CompraVenda />);
    await abrir();
    await screen.findByText(/falta o passo «Abater o bem»/);
    expect(screen.queryByRole('button', { name: 'Abater o bem' })).toBeNull();
  });

  it('a venda com contrato em vigor pede a decisão e envia o destino escolhido', async () => {
    ficha = fichaNegocio({ tipo: 'VENDA', fracao_id: 3, fracao_designacao: '101', aprovado: true, faltas: ['A venda tem 1 contrato(s) em vigor (AR 2026/0001). A venda não faz cessar o arrendamento: decida o que lhes acontece antes de escriturar.'],
      contratos_em_vigor: [{ id: 4, numero: 'AR 2026/0001', fim: null, renda: 100000, estado: 'ACTIVO' }], custo_aquisicao_calculado: null });
    ecra(<CompraVenda />);
    await abrir();
    fireEvent.click(await screen.findByRole('button', { name: 'Decidir o que fazer aos contratos' }));
    const d = await modal('Contratos sobre o imóvel vendido');
    expect(within(d).getByText(/AR 2026\/0001 — até sem termo, renda 100 000,00 Kz/)).toBeInTheDocument();
    fireEvent.click(within(d).getByRole('button', { name: 'Confirmar' }));
    await waitFor(() => expect(enviarMock).toHaveBeenCalledWith('post', '/imobiliario/negocios/21/contratos', { destino: 'TRANSMITE' }));
  });

  it('o novo negócio mostra a simulação dos impostos à mão e grava com os campos escritos', async () => {
    ecra(<CompraVenda />);
    fireEvent.click(await screen.findByRole('button', { name: /Novo negócio/ }));
    const d = await modal('Novo negócio');
    expect(within(d).getByText('Indique o preço para ver os impostos da transmissão.')).toBeInTheDocument();
    fireEvent.change(within(d).getByLabelText('Preço (Kz)'), { target: { value: '30000000' } });
    fireEvent.change(within(d).getByLabelText('Valor patrimonial (Kz)'), { target: { value: '35000000' } });
    expect(await within(d).findByText(/Base 35 000 000,00 Kz — o valor patrimonial é maior do que o preço/)).toBeInTheDocument();
    expect(within(d).getByText(/Imposto Predial 700 000,00 Kz · Imposto de Selo 105 000,00 Kz · Escritura 2000,00 Kz/)).toBeInTheDocument();
    expect(within(d).getByText(/Total, a cargo do adquirente — a empresa: 807 000,00 Kz/)).toBeInTheDocument();
    fireEvent.mouseDown(within(d).getByLabelText('Vendedor'));
    fireEvent.click(await screen.findByText('Vendedor Fictício · 5000000555', { selector: '.ant-select-item-option-content' }));
    fireEvent.click(within(d).getByRole('button', { name: 'Gravar' }));
    await waitFor(() => expect(enviarMock).toHaveBeenCalledTimes(1));
    const [metodo, url, corpo] = enviarMock.mock.calls[0] as [string, string, Record<string, unknown>];
    expect([metodo, url, corpo.tipo, corpo.terceiro_id, corpo.preco, corpo.vpt, corpo.isencao]).toEqual(['post', '/imobiliario/negocios', 'COMPRA', 5, 30000000, 35000000, 'NAO']);
  });

  it('os encargos gravam-se com a chave de cada linha (Form.List com campos nomeados)', async () => {
    ficha = fichaNegocio({ custos: [{ id: 1, tipo: 'MEDIACAO', designacao: 'Comissão da mediadora', valor: 300000, capitaliza: true, pago: false, data: '2026-03-10' }] });
    ecra(<CompraVenda />);
    await abrir();
    fireEvent.click(await screen.findByRole('button', { name: 'Editar encargos' }));
    const d = await modal('Encargos do negócio');
    expect(within(d).getByLabelText('Descrição do encargo')).toHaveValue('Comissão da mediadora');
    fireEvent.click(within(d).getByRole('button', { name: 'Gravar' }));
    await waitFor(() => expect(enviarMock).toHaveBeenCalledTimes(1));
    const [metodo, url, corpo] = enviarMock.mock.calls[0] as [string, string, { linhas: { tipo: string; designacao: string; valor: number; capitaliza: boolean }[] }];
    expect([metodo, url]).toEqual(['put', '/imobiliario/negocios/21/custos']);
    expect(corpo.linhas).toHaveLength(1);
    expect(corpo.linhas[0]).toMatchObject({ tipo: 'MEDIACAO', designacao: 'Comissão da mediadora', valor: 300000, capitaliza: true });
  });

  it('sem permissões de edição não há botão de criar', async () => {
    permissoes = new Set(['imo_negocios_view']);
    ecra(<CompraVenda />);
    await screen.findByText('CP 2026/0001');
    expect(screen.queryByRole('button', { name: /Novo negócio/ })).toBeNull();
  });
});
