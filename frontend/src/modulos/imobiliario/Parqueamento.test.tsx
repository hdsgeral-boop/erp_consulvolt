import { configure, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { ReactNode } from 'react';
import { ErroApi } from '@/api/tipos';
import { FRACOES, IMOVEL, LISTAS_PARQUE, MAPA } from './fixturesParque';

vi.setConfig({ testTimeout: 120_000 });
configure({ asyncUtilTimeout: 15_000 });

const obterMock = vi.fn();
const enviarMock = vi.fn();
let permissoes = new Set<string>();
vi.mock('@/api/cliente', () => ({ obter: (...a: unknown[]) => obterMock(...a), enviar: (...a: unknown[]) => enviarMock(...a) }));
vi.mock('@/sessao/SessaoContexto', () => ({ useSessao: () => ({ pode: (...p: string[]) => p.some((x) => permissoes.has(x)), menu: [] }) }));

import Parqueamento from './Parqueamento';

const ecra = (ui: ReactNode) =>
  render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><MemoryRouter>{ui}</MemoryRouter></QueryClientProvider>);

const TODAS = ['imo_parque_view', 'imo_vaga_editar', 'imo_vaga_afectar', 'imo_vaga_veiculos'];

beforeEach(() => {
  obterMock.mockReset(); enviarMock.mockReset();
  permissoes = new Set(TODAS);
  obterMock.mockImplementation((url: string, params?: Record<string, string>) => {
    if (url === '/imobiliario/imoveis') return Promise.resolve([IMOVEL]);
    if (url === '/imobiliario/parque/listas') return Promise.resolve(LISTAS_PARQUE);
    if (url === '/imobiliario/imoveis/7/parque/mapa') {
      const q = (params?.texto ?? '').toLowerCase();
      return Promise.resolve(q === '' ? MAPA : { ...MAPA, pisos: [{ ...MAPA.pisos[0], vagas: MAPA.pisos[0].vagas.filter((v) => `${v.designacao} ${v.ocupante ?? ''}`.toLowerCase().includes(q)) }] });
    }
    if (url === '/imobiliario/imoveis/7/fracoes') return Promise.resolve(FRACOES);
    if (url === '/imobiliario/contratos/9/veiculos') return Promise.resolve({ veiculos: [{ id: 1, matricula: 'LD-12-34-AB', marca: 'Toyota', modelo: 'Hilux', cor: 'Branco', porte: 'SUV', condutor: 'Ana', distico: 'D-9', vaga_id: 1 }],
      vagas: [{ id: 1, designacao: 'G-101', afecta: true, porte: 'LIGEIRO' }], lugares: 1 });
    return Promise.resolve([]);
  });
  enviarMock.mockResolvedValue({ dados: { criadas: 20, repetidas: 0, afectadas: 1, libertadas: 1, avisos: [], gravados: 1, repetidas_: [] }, mensagem: 'ok' });
});

describe('Gestão Imobiliária — Parqueamento', () => {
  it('mostra os indicadores, os avisos e o mapa por piso com o estado sempre em texto', async () => {
    ecra(<Parqueamento />);
    const g101 = await screen.findByRole('button', { name: /Lugar G-101, Ocupada, ocupado por Inquilino Fictício, afecto a 101, veículo maior do que o lugar/ });
    expect(g101).toBeInTheDocument();
    expect(screen.getByText('Lugares')).toBeInTheDocument();
    expect(screen.getByText('Taxa do parque')).toBeInTheDocument();
    expect(screen.getByText('50 %')).toBeInTheDocument();
    expect(screen.getByText('1 ponto(s) a tratar')).toBeInTheDocument();
    expect(screen.getByText(/1 vaga\(s\) com veículo maior do que o porte do lugar/)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Lugar G-102, Livre/ })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Lugar Visitas 1, Visitas/ })).toBeInTheDocument();   // uso comum: roxo e escrito
    expect(within(g101).getByText('LD-12-34-AB')).toBeInTheDocument();
    expect(screen.getByRole('region', { name: 'Piso Cave' })).toBeInTheDocument();
    expect(screen.getByText(/Verde é livre, azul é ocupado, roxo é de uso comum/)).toBeInTheDocument();
  });

  it('a pesquisa de texto vai ao servidor e mostra só os lugares que casam', async () => {
    ecra(<Parqueamento />);
    await screen.findByRole('button', { name: /Lugar G-102/ });
    fireEvent.change(screen.getByLabelText('Procurar lugares'), { target: { value: 'Inquilino' } });
    await waitFor(() => expect(screen.queryByRole('button', { name: /Lugar G-102/ })).toBeNull());
    expect(obterMock).toHaveBeenCalledWith('/imobiliario/imoveis/7/parque/mapa', { texto: 'Inquilino' });
    expect(await screen.findByRole('button', { name: /Lugar G-101/ })).toBeInTheDocument();
  });

  it('só quem tem as permissões vê as acções de escrita', async () => {
    permissoes = new Set(['imo_parque_view']);
    ecra(<Parqueamento />);
    await screen.findByRole('button', { name: /Lugar G-102/ });
    for (const nome of [/Gerar vagas/, /Vaga$/, /Afectar a fracção/]) expect(screen.queryByRole('button', { name: nome })).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: /Lugar G-102/ }));   // sem edição o diálogo é só de consulta
    expect(await screen.findByRole('button', { name: 'Fechar' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Gravar' })).toBeNull();
    expect(screen.queryByRole('button', { name: 'Apagar' })).toBeNull();
  });

  it('sem vagas explica o que é uma vaga e oferece gerar', async () => {
    obterMock.mockImplementation((url: string) => {
      if (url === '/imobiliario/imoveis') return Promise.resolve([IMOVEL]);
      if (url === '/imobiliario/parque/listas') return Promise.resolve(LISTAS_PARQUE);
      if (url === '/imobiliario/imoveis/7/parque/mapa') return Promise.resolve({ pisos: [], total: 0, resumo: null, avisos: [] });
      return Promise.resolve([]);
    });
    ecra(<Parqueamento />);
    expect(await screen.findByText(/ainda não tem lugares de estacionamento/)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Gerar vagas' })).toBeEnabled();
    expect(screen.getByRole('button', { name: /Afectar a fracção/ })).toBeDisabled();
  });

  it('gerar vagas envia os pisos, o prefixo e os valores do formulário', async () => {
    ecra(<Parqueamento />);
    await screen.findByRole('button', { name: /Lugar G-102/ });   // o imóvel já carregou: os botões do cabeçalho activam-se
    fireEvent.click(screen.getByRole('button', { name: 'Gerar vagas' }));
    expect(await screen.findByText(/Gerar duas vezes não duplica/)).toBeInTheDocument();
    fireEvent.change(screen.getByLabelText('Piso(s)'), { target: { value: '-1, -2' } });
    fireEvent.change(screen.getByLabelText('Lugares por piso'), { target: { value: '12' } });
    fireEvent.click(screen.getByRole('button', { name: 'Gerar' }));
    await waitFor(() => expect(enviarMock).toHaveBeenCalledTimes(1));
    const [metodo, url, corpo] = enviarMock.mock.calls[0] as [string, string, Record<string, unknown>];
    expect([metodo, url]).toEqual(['post', '/imobiliario/imoveis/7/parque/gerar']);
    expect(corpo).toMatchObject({ pisos: '-1, -2', por_piso: 12, prefixo: 'G', park_tipo: 'SUBTERRANEO', park_porte: 'LIGEIRO', area_bruta: 12.5, park_tomada: false });
  });

  it('o piso tem de ser números separados por vírgulas', async () => {
    ecra(<Parqueamento />);
    await screen.findByRole('button', { name: /Lugar G-102/ });
    fireEvent.click(screen.getByRole('button', { name: 'Gerar vagas' }));
    fireEvent.change(await screen.findByLabelText('Piso(s)'), { target: { value: 'cave' } });
    fireEvent.click(screen.getByRole('button', { name: 'Gerar' }));
    expect(await screen.findByText('Só números separados por vírgulas.')).toBeInTheDocument();
    expect(enviarMock).not.toHaveBeenCalled();
  });

  it('editar uma vaga grava só o que o formulário tem e apagar pede confirmação', async () => {
    ecra(<Parqueamento />);
    fireEvent.click(await screen.findByRole('button', { name: /Lugar G-102/ }));
    expect(await screen.findByDisplayValue('G-102')).toBeInTheDocument();
    fireEvent.change(screen.getByLabelText('Dístico / cartão'), { target: { value: 'D-77' } });
    fireEvent.click(screen.getByRole('button', { name: 'Gravar' }));
    await waitFor(() => expect(enviarMock).toHaveBeenCalledTimes(1));
    const [metodo, url, corpo] = enviarMock.mock.calls[0] as [string, string, Record<string, unknown>];
    expect([metodo, url]).toEqual(['put', '/imobiliario/parque/vagas/2']);
    expect(corpo).toMatchObject({ designacao: 'G-102', park_distico: 'D-77', afectacao: 'AUTONOMA' });
    expect(corpo).not.toHaveProperty('fracao_id');   // só se envia para vagas afectas
    expect(corpo).not.toHaveProperty('estado');      // o estado muda pelo trilho próprio
    enviarMock.mockClear();
    await waitFor(() => expect(screen.queryByRole('button', { name: 'Gravar' })).toBeNull());   // gravou: o diálogo fechou
    fireEvent.click(await screen.findByRole('button', { name: /Lugar G-102/ }));
    fireEvent.click(await screen.findByRole('button', { name: 'Apagar' }));
    expect(await screen.findByText(/Apagar «G-102»\? Não há volta a dar\./)).toBeInTheDocument();
    expect(enviarMock).not.toHaveBeenCalled();
  });

  it('uma vaga ocupada abre os veículos do contrato e grava cor e dístico que já tinham', async () => {
    ecra(<Parqueamento />);
    fireEvent.click(await screen.findByRole('button', { name: /Lugar G-101/ }));
    expect(await screen.findByText(/Ocupada pelo contrato AR 2026\/0001 — por arrastamento da fracção/)).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Veículos' }));
    expect(await screen.findByText(/Veículos do contrato AR 2026\/0001/)).toBeInTheDocument();
    expect(await screen.findByDisplayValue('Branco')).toBeInTheDocument();
    expect(screen.getByText(/Este contrato dá direito a 1 lugar\(es\)/)).toBeInTheDocument();
    const matricula = screen.getByLabelText('Matrícula do veículo 1');
    fireEvent.change(matricula, { target: { value: 'ld 12 34 cd' } });
    fireEvent.blur(matricula);
    expect(matricula).toHaveValue('LD-12-34-CD');
    fireEvent.click(screen.getByRole('button', { name: 'Gravar' }));
    await waitFor(() => expect(enviarMock).toHaveBeenCalledTimes(1));
    const [metodo, url, corpo] = enviarMock.mock.calls[0] as [string, string, { veiculos: Record<string, unknown>[]; confirmar_repetidas: boolean }];
    expect([metodo, url]).toEqual(['put', '/imobiliario/contratos/9/veiculos']);
    expect(corpo.veiculos[0]).toMatchObject({ matricula: 'LD-12-34-CD', marca: 'Toyota', cor: 'Branco', distico: 'D-9', condutor: 'Ana', porte: 'SUV', vaga_id: 1 });
    expect(corpo.confirmar_repetidas).toBe(false);
  });

  it('a matrícula repetida noutro contrato pede confirmação e só então volta a gravar', async () => {
    enviarMock.mockRejectedValueOnce(new ErroApi('Há matrículas que já estão noutros contratos em vigor deste imóvel (LD-12-34-AB já consta de AR 2026/0002).', 409, 'VEICULOS_MATRICULA_REPETIDA'));
    ecra(<Parqueamento />);
    fireEvent.click(await screen.findByRole('button', { name: /Lugar G-101/ }));
    fireEvent.click(await screen.findByRole('button', { name: 'Veículos' }));
    await screen.findByDisplayValue('Branco');
    fireEvent.click(screen.getByRole('button', { name: 'Gravar' }));
    expect((await screen.findAllByText('Matrícula repetida')).length).toBeGreaterThan(0);
    expect(screen.getByText(/já consta de AR 2026\/0002/)).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Continuar mesmo assim' }));
    await waitFor(() => expect(enviarMock).toHaveBeenCalledTimes(2));
    expect((enviarMock.mock.calls[1] as [string, string, { confirmar_repetidas: boolean }])[2].confirmar_repetidas).toBe(true);
  });

  it('afectar a uma fracção que já tem esse lugar pede confirmação antes de o mudar', async () => {
    enviarMock.mockRejectedValueOnce(new ErroApi('«G-102» já está afecta a 101. Liberte-a primeiro ou confirme a mudança para esta fracção.', 409, 'PARQUE_JA_AFECTA'));
    ecra(<Parqueamento />);
    await screen.findByRole('button', { name: /Lugar G-102/ });
    fireEvent.click(screen.getByRole('button', { name: /Afectar a fracção/ }));
    const dialogo = await screen.findByRole('dialog', { name: 'Afectar vagas a uma fracção' });
    expect(within(dialogo).getByRole('button', { name: 'Afectar' })).toBeDisabled();   // falta a fracção e os lugares
    fireEvent.mouseDown(within(dialogo).getByLabelText('Fracção'));
    fireEvent.click(await screen.findByText('102 (PR0001-02)'));
    fireEvent.click(within(dialogo).getByRole('checkbox', { name: /G-102/ }));
    expect(within(dialogo).getByText('já de 101', { exact: false })).toBeTruthy();   // o lugar G-101 já tem dono (rótulo da lista)
    fireEvent.click(within(dialogo).getByRole('button', { name: 'Afectar' }));
    expect((await screen.findAllByText('Mudar um lugar de fracção?')).length).toBeGreaterThan(0);
    expect(enviarMock).toHaveBeenCalledTimes(1);
    expect((enviarMock.mock.calls[0] as [string, string, Record<string, unknown>])[2]).toMatchObject({ fracao_id: 4, vaga_ids: [2], reatribuir: false, incluida_na_renda: null });
    fireEvent.click(screen.getByRole('button', { name: 'Mudar para esta fracção' }));
    await waitFor(() => expect(enviarMock).toHaveBeenCalledTimes(2));
    expect((enviarMock.mock.calls[1] as [string, string, Record<string, unknown>])[2]).toMatchObject({ fracao_id: 4, vaga_ids: [2], reatribuir: true });
  });
});
