import { configure, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { ReactNode } from 'react';
import type { FichaReserva, Verificacao } from './apiParque';
import { CALENDARIO, DIA, espaco, FRACOES, ficha, IMOVEL, LISTAS_PARQUE, VERIFICACAO_OK } from './fixturesParque';

vi.setConfig({ testTimeout: 120_000 });
configure({ asyncUtilTimeout: 15_000 });

const obterMock = vi.fn();
const enviarMock = vi.fn();
let permissoes = new Set<string>();
vi.mock('@/api/cliente', () => ({ obter: (...a: unknown[]) => obterMock(...a), enviar: (...a: unknown[]) => enviarMock(...a) }));
vi.mock('@/sessao/SessaoContexto', () => ({ useSessao: () => ({ pode: (...p: string[]) => p.some((x) => permissoes.has(x)), menu: [] }) }));

import AreasComuns from './AreasComuns';

const ecra = (ui: ReactNode) =>
  render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><MemoryRouter>{ui}</MemoryRouter></QueryClientProvider>);

/** O antd usa o mesmo id para o título de todos os diálogos nos testes: com um diálogo por cima de outro (gaveta + modal) acha-se o modal pelo título. */
const modal = async (titulo: string): Promise<HTMLElement> => (await screen.findByText(titulo, { selector: '.ant-modal-title' })).closest('.ant-modal') as HTMLElement;

const TODAS = ['imo_areas_view', 'imo_area_editar', 'imo_reserva_marcar', 'imo_reserva_aprovar', 'imo_reserva_devedor', 'imo_reserva_facturar', 'imo_reserva_receber', 'imo_reserva_vistoria',
  'imo_reserva_caucao', 'imo_reserva_cancelar'];

let verificacao: Verificacao = VERIFICACAO_OK;
let fichaActual: FichaReserva = ficha();

beforeEach(() => {
  obterMock.mockReset(); enviarMock.mockReset();
  permissoes = new Set(TODAS);
  verificacao = VERIFICACAO_OK;
  fichaActual = ficha();
  obterMock.mockImplementation((url: string) => {
    if (url === '/imobiliario/imoveis') return Promise.resolve([IMOVEL]);
    if (url === '/imobiliario/parque/listas') return Promise.resolve(LISTAS_PARQUE);
    if (url === '/imobiliario/areas/espacos') return Promise.resolve([espaco()]);
    if (url === '/imobiliario/areas/resumo') return Promise.resolve({ espacos: 1, activos: 1, reservaveis: 1, pagos: 1, por_aprovar: 1, aguarda_pagamento: 1, futuras: 2, hoje: 0, por_fechar: 3, com_divida: 0 });
    if (url === '/imobiliario/areas/avisos') return Promise.resolve(['1 reserva(s) à espera de aprovação.']);
    if (url === '/imobiliario/reservas/avisos') return Promise.resolve(['12 000,00 Kz facturados de reservas e ainda por receber.']);
    if (url === '/imobiliario/areas/calendario') return Promise.resolve(CALENDARIO);
    if (url === '/imobiliario/areas/espacos/5/dia') return Promise.resolve(DIA);
    if (url === '/imobiliario/reservas/verificar') return Promise.resolve(verificacao);
    if (url === '/imobiliario/reservas/21') return Promise.resolve(fichaActual);
    if (url === '/imobiliario/reservas/21/previsao-cancelamento') return Promise.resolve({ faltam_dias: 3, recebido: 12000, pct: 50, devolve: 6000, retem: 6000, caucao_a_devolver: 20000,
      explicacao: 'Faltam só 3 dia(s): dentro dos 7 dia(s) do regulamento devolve-se 50%.', facturada: true });
    if (url === '/imobiliario/imoveis/7/fracoes') return Promise.resolve(FRACOES);
    if (url === '/imobiliario/contratos/contas') return Promise.resolve([{ codigo: '4311', descricao: 'Banco' }]);
    return Promise.resolve([]);
  });
  enviarMock.mockResolvedValue({ dados: { caducadas: 0, confirmadas: 0, parciais: 0, falhas: 0, numero_documento: 'FT 2026/0009', repetida: false, reserva: ficha(), previsao: { devolve: 6000, recebido: 12000 }, id: 21 }, mensagem: 'ok' });
});

describe('Gestão Imobiliária — Áreas Comuns e Reservas', () => {
  it('mostra os indicadores, os avisos e o calendário por dia com o estado sempre em texto', async () => {
    ecra(<AreasComuns />);
    expect(await screen.findByText('Sexta, 2026-03-27', { exact: false })).toBeInTheDocument();
    expect(screen.getByText('Reservas por vir')).toBeInTheDocument();
    expect(screen.getByText('Por fechar')).toBeInTheDocument();
    expect(screen.getByText('2 ponto(s) a tratar')).toBeInTheDocument();
    expect(screen.getByText(/12 000,00 Kz facturados de reservas e ainda por receber/)).toBeInTheDocument();
    expect(screen.getByText('A aguardar pagamento')).toBeInTheDocument();
    expect(screen.getByText('Aprovada')).toBeInTheDocument();
    expect(screen.getByText(/Salão de festas — Inquilino Fictício/)).toBeInTheDocument();
    expect(screen.getByText(/Salão de festas — Administração/)).toBeInTheDocument();
    expect(screen.getByText(/12 000,00 Kz \+ caução 20 000,00 Kz/)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Abrir a reserva de Salão de festas em 2026-03-27 às 14:00' })).toBeInTheDocument();
    expect(screen.getByRole('region', { name: 'Sexta, 2026-03-27' })).toBeInTheDocument();
  });

  it('o separador Espaços mostra o regulamento resumido de cada espaço', async () => {
    ecra(<AreasComuns />);
    await screen.findByText('Sexta, 2026-03-27', { exact: false });
    fireEvent.click(screen.getByRole('button', { name: 'Espaços' }));
    const linha = (await screen.findByText('Salão de festas')).closest('tr') as HTMLElement;
    expect(within(linha).getByText('Reserva paga')).toBeInTheDocument();
    expect(within(linha).getByText('08:00–22:00 · todos os dias')).toBeInTheDocument();
    expect(within(linha).getByText(/2–8 h · 1×\/mês por fracção · min\. 3 d · 50 pessoas/)).toBeInTheDocument();
    expect(within(linha).getByText(/4000,00 Kz por hora · caução 20 000,00 Kz/)).toBeInTheDocument();
    expect(within(linha).getByText('Activo')).toBeInTheDocument();
    expect(within(linha).getByRole('button', { name: 'Editar Salão de festas' })).toBeInTheDocument();
  });

  it('só quem tem as permissões vê as acções e a verificação dos prazos só corre para quem aprova ou cancela', async () => {
    permissoes = new Set(['imo_areas_view']);
    ecra(<AreasComuns />);
    await screen.findByText('Sexta, 2026-03-27', { exact: false });
    expect(screen.queryByRole('button', { name: /Novo espaço/ })).toBeNull();
    expect(screen.queryByRole('button', { name: /Marcar/ })).toBeNull();
    expect(enviarMock).not.toHaveBeenCalled();
    fireEvent.click(screen.getByRole('button', { name: /Abrir a reserva de Salão de festas em 2026-03-27 às 14:00/ }));
    expect(await screen.findByText('Dinheiro')).toBeInTheDocument();
    for (const nome of ['Facturar', 'Receber', 'Vistoria', 'Cancelar', 'Alterar', 'Apagar']) expect(screen.queryByRole('button', { name: nome })).toBeNull();
  });

  it('ao abrir, quem aprova ou cancela dispara a caducidade dos prazos e é avisado do que caiu', async () => {
    enviarMock.mockResolvedValue({ dados: { caducadas: 2, confirmadas: 0, parciais: 1, falhas: 0 }, mensagem: 'ok' });
    ecra(<AreasComuns />);
    await waitFor(() => expect(enviarMock).toHaveBeenCalledWith('post', '/imobiliario/reservas/caducar', {}));
    expect(await screen.findByText(/2 reserva\(s\) caíram por não terem sido pagas dentro do prazo/)).toBeInTheDocument();
  });

  it('a ficha mostra o painel do dinheiro e factura a reserva; o botão Receber só aparece com algo por receber', async () => {
    ecra(<AreasComuns />);
    fireEvent.click(await screen.findByRole('button', { name: /Abrir a reserva de Salão de festas em 2026-03-27 às 14:00/ }));
    expect(await screen.findByText('Dinheiro')).toBeInTheDocument();
    expect(screen.getByText('por emitir')).toBeInTheDocument();
    expect(screen.getByText(/0,00 Kz de 20 000,00 Kz — Por receber/)).toBeInTheDocument();
    expect(screen.getByText(/O horário está preso até/)).toBeInTheDocument();
    expect(screen.getByText('Devolver limpo, sem música depois das 22h.')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Receber' })).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Facturar' }));
    await waitFor(() => expect(enviarMock).toHaveBeenCalledWith('post', '/imobiliario/reservas/21/facturar', {}));
  });

  it('uma reserva já facturada não se altera nem se factura outra vez', async () => {
    fichaActual = ficha({ venda_id: 8, situacao: { ...ficha().situacao, venda_id: 8, documento: 'FT 2026/0009', facturado: 12000 } });
    ecra(<AreasComuns />);
    fireEvent.click(await screen.findByRole('button', { name: /Abrir a reserva de Salão de festas em 2026-03-27 às 14:00/ }));
    expect(await screen.findByText('FT 2026/0009 (12 000,00 Kz)')).toBeInTheDocument();
    for (const nome of ['Facturar', 'Alterar', 'Apagar']) expect(screen.queryByRole('button', { name: nome })).toBeNull();
    expect(screen.getByRole('button', { name: 'Cancelar' })).toBeInTheDocument();
  });

  it('receber exige a conta de caixa ou banco e envia a utilização, a caução e uma chave do pedido', async () => {
    fichaActual = ficha({ venda_id: 8, situacao: { ...ficha().situacao, venda_id: 8, documento: 'FT 2026/0009', facturado: 12000 } });
    ecra(<AreasComuns />);
    fireEvent.click(await screen.findByRole('button', { name: /Abrir a reserva de Salão de festas em 2026-03-27 às 14:00/ }));
    fireEvent.click(await screen.findByRole('button', { name: 'Receber' }));
    const d = await modal('Receber da reserva');
    fireEvent.click(within(d).getByRole('button', { name: 'Registar' }));
    expect(await within(d).findByText('Indique a conta de caixa ou banco.')).toBeInTheDocument();
    expect(enviarMock).not.toHaveBeenCalledWith('post', '/imobiliario/reservas/21/receber', expect.anything());
    fireEvent.mouseDown(within(d).getByLabelText('Conta de caixa ou banco'));
    fireEvent.click(await screen.findByText('4311 — Banco'));
    fireEvent.click(within(d).getByRole('button', { name: 'Registar' }));
    await waitFor(() => expect(enviarMock).toHaveBeenCalledWith('post', '/imobiliario/reservas/21/receber', expect.objectContaining({ valor: 12000, caucao: 20000, conta_financeira: '4311', meio_pagamento: 'NUMERARIO' })));
    const corpo = enviarMock.mock.calls.find((c) => c[1] === '/imobiliario/reservas/21/receber')?.[2] as { chave: string };
    expect(corpo.chave).toMatch(/\w+-\w+/);
  });

  it('cancelar mostra o que se devolve pela escala do espaço e exige o motivo', async () => {
    fichaActual = ficha({ venda_id: 8, situacao: { ...ficha().situacao, venda_id: 8, documento: 'FT 2026/0009', facturado: 12000, recebido: 12000, por_pagar: 0, pago: true } });
    ecra(<AreasComuns />);
    fireEvent.click(await screen.findByRole('button', { name: /Abrir a reserva de Salão de festas em 2026-03-27 às 14:00/ }));
    fireEvent.click(await screen.findByRole('button', { name: 'Cancelar' }));
    const d = await modal('Cancelar a reserva');
    expect(await within(d).findByText(/Faltam só 3 dia\(s\): dentro dos 7 dia\(s\) do regulamento devolve-se 50%/)).toBeInTheDocument();
    expect(within(d).getByText(/devolve-se 6000,00 Kz \(50 %\) · fica retido 6000,00 Kz/)).toBeInTheDocument();
    fireEvent.click(within(d).getByRole('button', { name: 'Cancelar a reserva' }));
    expect(await within(d).findByText('Diga o motivo do cancelamento.')).toBeInTheDocument();
    expect(enviarMock).not.toHaveBeenCalledWith('post', '/imobiliario/reservas/21/cancelar', expect.anything());
    fireEvent.change(within(d).getByLabelText('Motivo do cancelamento'), { target: { value: 'Desistiu' } });
    fireEvent.click(within(d).getByRole('button', { name: 'Cancelar a reserva' }));
    await waitFor(() => expect(enviarMock).toHaveBeenCalledWith('post', '/imobiliario/reservas/21/cancelar', { motivo: 'Desistiu', devolver: 6000 }));
  });

  it('marcar: o que impede a marcação desactiva o botão e mostra a lista; com tudo certo mostra o preço e marca', async () => {
    verificacao = { ...VERIFICACAO_OK, impedimentos: ['Já há reserva das 18:00 às 19:00 (Aprovada).'], valor: '0.00' };
    ecra(<AreasComuns />);
    fireEvent.click(await screen.findByRole('button', { name: /Marcar/ }));
    const d = await screen.findByRole('dialog', { name: 'Marcar espaço' });
    expect(await within(d).findByText('Não se pode marcar assim:')).toBeInTheDocument();
    expect(within(d).getByText('Já há reserva das 18:00 às 19:00 (Aprovada).')).toBeInTheDocument();
    expect(within(d).getByRole('button', { name: 'Marcar' })).toBeDisabled();
    expect(within(d).getByText(/Livre:/)).toBeInTheDocument();                       // o painel do dia dá as horas livres
    expect(within(d).getByRole('button', { name: '19:00–22:00' })).toBeInTheDocument();
    // com tudo certo: preço, caução e botão activo
    verificacao = VERIFICACAO_OK;
    fireEvent.click(within(d).getByRole('button', { name: '19:00–22:00' }));         // preenche as horas
    expect(await within(d).findByText(/12 000,00 Kz — 3 × hora \(2\.5 h\), mais 20 000,00 Kz de caução\. O espaço só fica garantido depois do pagamento\./)).toBeInTheDocument();
    await waitFor(() => expect(within(d).getByRole('button', { name: 'Marcar' })).toBeEnabled());
    fireEvent.click(within(d).getByRole('button', { name: 'Marcar' }));
    await waitFor(() => expect(enviarMock).toHaveBeenCalledWith('post', '/imobiliario/reservas', expect.objectContaining({ espaco_id: 5, fracao_id: null, hora_inicio: '19:00', hora_fim: '22:00' })));
  });

  it('a fracção em dívida pede a autorização com motivo, e sem permissão de a dar não se marca', async () => {
    verificacao = { ...VERIFICACAO_OK, exige_autorizacao: true, divida: 85000, avisos: ['A fracção tem 85 000,00 Kz de rendas em dívida.'] };
    ecra(<AreasComuns />);
    fireEvent.click(await screen.findByRole('button', { name: /Marcar/ }));
    const d = await screen.findByRole('dialog', { name: 'Marcar espaço' });
    expect(await within(d).findByLabelText('Autorização')).toBeInTheDocument();
    expect(within(d).getByText(/A fracção tem 85 000,00 Kz de rendas em dívida/)).toBeInTheDocument();
    expect(within(d).getByRole('button', { name: 'Marcar' })).toBeDisabled();     // falta o motivo
    fireEvent.change(within(d).getByLabelText('Autorização'), { target: { value: 'Cliente antigo, paga na sexta' } });
    await waitFor(() => expect(within(d).getByRole('button', { name: 'Marcar' })).toBeEnabled());
    fireEvent.click(within(d).getByRole('button', { name: 'Marcar' }));
    await waitFor(() => expect(enviarMock).toHaveBeenCalledWith('post', '/imobiliario/reservas', expect.objectContaining({ autorizacao_motivo: 'Cliente antigo, paga na sexta' })));
  });

  it('sem a permissão de autorizar devedores a marcação fica bloqueada com a explicação', async () => {
    permissoes = new Set(TODAS.filter((p) => p !== 'imo_reserva_devedor'));
    verificacao = { ...VERIFICACAO_OK, exige_autorizacao: true, divida: 85000 };
    ecra(<AreasComuns />);
    fireEvent.click(await screen.findByRole('button', { name: /Marcar/ }));
    const d = await screen.findByRole('dialog', { name: 'Marcar espaço' });
    expect(await within(d).findByText('Só quem tem a permissão de autorizar reservas de devedores pode marcar esta reserva.')).toBeInTheDocument();
    expect(within(d).getByRole('button', { name: 'Marcar' })).toBeDisabled();
  });

  it('o novo espaço mostra o bloco do preço só no regime pago e valida o preço', async () => {
    ecra(<AreasComuns />);
    fireEvent.click(await screen.findByRole('button', { name: /Novo espaço/ }));
    const d = await screen.findByRole('dialog', { name: 'Novo espaço comum' });
    expect(within(d).getByText('Quando se pode usar')).toBeInTheDocument();
    expect(within(d).queryByText('Quanto custa')).toBeNull();
    fireEvent.change(within(d).getByLabelText('Nome'), { target: { value: 'Salão nobre' } });
    fireEvent.mouseDown(within(d).getByLabelText('Regime'));
    fireEvent.click(await screen.findByText('Reserva paga', { selector: '.ant-select-item-option-content' }));
    expect(await within(d).findByText('Quanto custa')).toBeInTheDocument();
    expect(within(d).getByText(/A receita das reservas vai pelo artigo de serviço configurado/)).toBeInTheDocument();
  });
});
