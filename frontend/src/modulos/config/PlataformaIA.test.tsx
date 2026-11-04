import { beforeEach, describe, expect, it, vi } from 'vitest';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import PlataformaIA from './PlataformaIA';

vi.setConfig({ testTimeout: 120_000 });

const obter = vi.fn();
const enviar = vi.fn();
vi.mock('@/api/cliente', async (original) => ({
  ...(await original<typeof import('@/api/cliente')>()),
  obter: (...a: unknown[]) => obter(...a),
  enviar: (...a: unknown[]) => enviar(...a),
}));
vi.mock('@/utilitarios/erros', () => ({ notificarErro: vi.fn() }));

const FORNECEDOR = (o: Record<string, unknown>) => ({
  id: 1, fornecedor: 'anthropic', nome: 'Anthropic', configurado: true, chave_final: 'ZZ99', modelos: ['claude-a', 'claude-b'], modelo_padrao: 'claude-a',
  preco_entrada_mtok: 3, preco_saida_mtok: 15, ativo: true, testado_em: null, ultimo_erro: null, ...o,
});
const FORNECEDORES = [
  FORNECEDOR({}),
  FORNECEDOR({ id: null, fornecedor: 'openai', nome: 'OpenAI', configurado: false, chave_final: null, modelos: [], modelo_padrao: null, ativo: false }),
  FORNECEDOR({ id: 3, fornecedor: 'gemini', nome: 'Google Gemini', configurado: false, chave_final: null, modelos: [], modelo_padrao: null, ativo: false }),
];
const ATRIBUICOES = [
  { tenant_id: 5, cliente: 'Alfa Lda', slug: 'alfa', estado: 'ATIVO', fornecedor: null, modelo: null, ativo: false, quota_tokens_mes: null, pesquisa_web: false, accoes_erp: false, tokens_mes: 0, custo_mes_usd: 0 },
  { tenant_id: 6, cliente: 'Beta SA', slug: 'beta', estado: 'ATIVO', fornecedor: 'anthropic', modelo: 'claude-a', ativo: true, quota_tokens_mes: 1000, pesquisa_web: true, accoes_erp: true, tokens_mes: 950, custo_mes_usd: 1.234 },
];
const CONSUMO = {
  meses: ['2026-10', '2026-11'], tokens_entrada: [10, 20], tokens_saida: [5, 6], custo_usd: [0.1, 0.2], pedidos: [1, 2],
  clientes_mes: [{ cliente: 'Beta SA', tokens: 950, custo_usd: 1.23, pedidos: 4, pesquisas: 2 }],
};

function montar() {
  const cliente = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(<QueryClientProvider client={cliente}><PlataformaIA /></QueryClientProvider>);
}

beforeEach(() => {
  obter.mockReset().mockImplementation((url: string) => {
    if (url === '/plataforma/ia/fornecedores') return Promise.resolve(FORNECEDORES);
    if (url === '/plataforma/ia/atribuicoes') return Promise.resolve(ATRIBUICOES);
    if (url === '/plataforma/ia/consumo') return Promise.resolve(CONSUMO);
    return Promise.resolve([]);
  });
  enviar.mockReset().mockResolvedValue({ dados: [], mensagem: 'ok' });
});

describe('Plataforma › Inteligência artificial', () => {
  it('mostra os três fornecedores e só diz se a chave está configurada (nunca a chave nem parte dela)', async () => {
    const { container } = montar();
    expect(await screen.findByText('Anthropic')).toBeInTheDocument();
    expect(screen.getByText('OpenAI')).toBeInTheDocument();
    expect(screen.getByText('Google Gemini')).toBeInTheDocument();
    expect(screen.getByText('Chave configurada · activo')).toBeInTheDocument();
    expect(screen.getAllByText('Sem chave')).toHaveLength(2);
    expect(container.textContent).not.toContain('ZZ99');
    const campo = screen.getByLabelText('Chave da API de Anthropic') as HTMLInputElement;
    expect(campo.type).toBe('password');
    expect(campo.value).toBe('');
    expect(campo.placeholder).toBe('');
  });

  it('gravar com a chave em branco não envia a chave; com chave nova envia-a e limpa o campo', async () => {
    montar();
    await screen.findByText('Anthropic');
    const gravar = screen.getAllByRole('button', { name: /Gravar/ })[0];
    fireEvent.click(gravar);
    await waitFor(() => expect(enviar).toHaveBeenCalledWith('put', '/plataforma/ia/fornecedores/anthropic', expect.not.objectContaining({ chave: expect.anything() })));

    const campo = screen.getByLabelText('Chave da API de Anthropic') as HTMLInputElement;
    fireEvent.change(campo, { target: { value: '  sk-nova-chave  ' } });
    fireEvent.click(gravar);
    await waitFor(() => expect(enviar).toHaveBeenCalledWith('put', '/plataforma/ia/fornecedores/anthropic', expect.objectContaining({ chave: 'sk-nova-chave' })));
    await waitFor(() => expect(campo.value).toBe(''));
  });

  it('«Testar ligação» só está disponível com chave configurada', async () => {
    montar();
    await screen.findByText('Anthropic');
    const botoes = screen.getAllByRole('button', { name: /Testar ligação/ });
    expect(botoes[0]).toBeEnabled();
    expect(botoes[1]).toBeDisabled();
    expect(botoes[2]).toBeDisabled();
    fireEvent.click(botoes[0]);
    await waitFor(() => expect(enviar).toHaveBeenCalledWith('post', '/plataforma/ia/fornecedores/anthropic/testar', { modelo: 'claude-a' }));
  });

  it('atribuição por cliente: grava o fornecedor/modelo/quota', async () => {
    montar();
    await screen.findByText('Anthropic');
    fireEvent.click(screen.getByRole('tab', { name: 'Atribuição aos clientes' }));
    await screen.findByText('Beta SA');
    const linha = () => screen.getByText('Beta SA').closest('tr') as HTMLElement;
    expect(within(linha()).getByText('Perto da quota')).toBeInTheDocument();
    fireEvent.change(within(linha()).getByLabelText('Quota mensal de Beta SA'), { target: { value: '2000000' } });
    fireEvent.click(within(linha()).getByRole('button', { name: 'Gravar' }));
    await waitFor(() => expect(enviar).toHaveBeenCalledWith('put', '/plataforma/ia/atribuicoes/6', expect.objectContaining({
      fornecedor: 'anthropic', modelo: 'claude-a', quota_tokens_mes: 2000000, pesquisa_web: true, accoes_erp: true, ativo: true,
    })));
  });

  it('retirar a IA a um cliente pede confirmação', async () => {
    montar();
    await screen.findByText('Anthropic');
    fireEvent.click(screen.getByRole('tab', { name: 'Atribuição aos clientes' }));
    const linha = (await screen.findByText('Beta SA')).closest('tr') as HTMLElement;
    fireEvent.click(within(linha).getByRole('button', { name: 'Retirar' }));
    expect(enviar).not.toHaveBeenCalled();
    fireEvent.click(await screen.findByText('Retirar', { selector: '.ant-popover button span, .ant-popconfirm button span' }));
    await waitFor(() => expect(enviar).toHaveBeenCalledWith('delete', '/plataforma/ia/atribuicoes/6'));
  });

  it('consumo: totais e consumo por cliente', async () => {
    montar();
    await screen.findByText('Anthropic');
    fireEvent.click(screen.getByRole('tab', { name: 'Consumo' }));
    expect(await screen.findByText('Consumo por cliente (este mês)')).toBeInTheDocument();
    expect(await screen.findByText('Tokens este mês')).toBeInTheDocument();
    expect(await screen.findByText('Pesquisas na internet')).toBeInTheDocument();
    expect(await screen.findByText('Beta SA')).toBeInTheDocument();
  });
});
