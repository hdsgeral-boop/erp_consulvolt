import { describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactNode } from 'react';

vi.setConfig({ testTimeout: 240_000 });

const enviar = vi.fn((..._a: unknown[]) => Promise.resolve({ dados: { pedido: { id: 9, numero_encomenda: 'OS-1', itens: [] } }, mensagem: 'ok' }));
vi.mock('@/api/cliente', async (original) => ({
  ...(await original<typeof import('@/api/cliente')>()),
  obter: vi.fn((url: string) => {
    if (url === '/pos/lavandaria/pecas') return Promise.resolve([{ id: 1, codigo: 'P01', nome: 'Camisa', tecido: 'Algodão', cor: null, unidade: 'PECA', ativo: true, preco: '1500.00', precos_servico: null }]);
    if (url === '/pos/lavandaria/servicos')
      return Promise.resolve([{ id: 5, codigo: 'S01', nome: 'Lavar e passar', lavandaria_grupo: 'LAVANDARIA', codigo_conta: null, taxa_imposto: '14', lavandaria_dias_entrega: 2, lavandaria_requer_orcamento: false, lavandaria_ativa: true }]);
    if (url === '/pos/lavandaria/definicoes')
      return Promise.resolve({ percentagem_adiantamento: '50', percentagem_urgencia: '50', fator_prazo_urgencia: 0.5, valor_taxa_recolha: '1000', valor_taxa_entrega: '1000', estados_entrada: ['Bom estado', 'Manchas'] });
    return Promise.resolve([]);
  }),
  obterPagina: vi.fn(() => Promise.resolve({ itens: [{ id: 3, nome: 'Consumidor Final', nif: '99999999' }], paginacao: { pagina_atual: 1, por_pagina: 30, total: 1, ultima_pagina: 1 } })),
  enviar: (...a: unknown[]) => enviar(...a),
}));
vi.mock('@/sessao/SessaoContexto', () => ({ useSessao: () => ({ pode: () => true, empresa: { id: 7, nome: 'Empresa Fictícia' } }) }));
vi.mock('../comum/impressao', () => ({ imprimirHtml: vi.fn(), reimprimir: vi.fn(), lerPreferencias: () => ({}), useCabecalhoTalao: () => () => ({}) }));

import { Recepcao } from './Recepcao';

const TERMINAL = { id: 2, codigo: 'T06', nome: 'Patriota_2', tipo: 'LAVANDARIA', sessao_aberta: { id: 11 }, meios_pagamento: [{ id: 'num', nome: 'Numerário', tipo: 'NUMERARIO', ativo: true, conta_transitoria: '489', conta_liquidacao: '4511' }] } as never;

function montar(ui: ReactNode) {
  const cliente = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(<QueryClientProvider client={cliente}>{ui}</QueryClientProvider>);
}

describe('Lavandaria — nova ordem de serviço como no protótipo', () => {
  it('5 secções, Consumidor Final por omissão, peça em cartão, resumo e envio com o estado à entrada', async () => {
    montar(<Recepcao terminal={TERMINAL} />);
    for (const s of ['Cliente e condições', 'Adicionar peça', 'Peças da ordem', 'Recolha e entrega ao domicílio', 'Observações', 'Resumo']) expect(screen.getByText(s)).toBeInTheDocument();
    expect(screen.getByText(/Ainda não há peças/)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Registar ordem/ })).toBeDisabled();
    await screen.findByText('Consumidor Final · 99999999');
    expect(await screen.findByText(/Consumidor Final: adiantamento mínimo de 50/)).toBeInTheDocument();

    // escolher a peça e o serviço e «Adicionar à ordem»
    fireEvent.mouseDown(screen.getByText('— seleccionar a peça —'));
    fireEvent.click(await screen.findByText(/P01 · Camisa \(Algodão\)/));
    fireEvent.mouseDown(screen.getByText('— seleccionar o serviço —'));
    fireEvent.click(await screen.findByText('S01 · Lavar e passar'));
    fireEvent.click(screen.getByRole('button', { name: /Adicionar à ordem/ }));

    const cartao = await screen.findByTestId('cartao-peca');
    expect(within(cartao).getByText(/1\. Camisa/)).toBeInTheDocument();
    expect(screen.getByTestId('total-ordem')).toHaveTextContent('1 500,00 Kz');
    expect(screen.getByText(/1 peça\(s\) sem estado à entrada/)).toBeInTheDocument();

    // sem estado à entrada não abre a confirmação
    fireEvent.click(screen.getByRole('button', { name: /Registar ordem/ }));
    expect(screen.queryByText('Registar ordem de serviço')).toBeNull();

    fireEvent.mouseDown(within(cartao).getByText('— indicar o estado —'));
    fireEvent.click(await screen.findByTitle('Bom estado'));
    fireEvent.click(screen.getByRole('button', { name: /Registar ordem/ }));
    const dialogo = await screen.findByRole('dialog');
    expect(within(dialogo).getByText(/adiantamento obrigatório de pelo menos/)).toBeInTheDocument();
    // o adiantamento (50% de 1 500) paga-se em numerário
    fireEvent.click(within(dialogo).getByRole('button', { name: /Numerário/ }));
    fireEvent.click(within(dialogo).getByRole('button', { name: /Registar e imprimir/ }));

    await waitFor(() => expect(enviar).toHaveBeenCalled());
    const [metodo, url, dados] = enviar.mock.calls[0] as [string, string, Record<string, unknown>];
    expect([metodo, url]).toEqual(['post', '/pos/lavandaria/sessoes/11/ordens']);
    expect(dados).toMatchObject({ cliente_id: 3, modo_faturacao: 'ENTREGA', urgente: false, itens: [{ peca_id: 1, produto_id: 5, quantidade: 1, estado_entrada: 'Bom estado' }], valor: '750.00' });
  });
});
