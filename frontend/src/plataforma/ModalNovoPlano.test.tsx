import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { ModalNovoPlano } from './ModalNovoPlano';

const enviar = vi.fn();
vi.setConfig({ testTimeout: 120_000 });
vi.mock('@/api/cliente', () => ({ enviar: (...a: unknown[]) => enviar(...a) }));
vi.mock('@/utilitarios/erros', () => ({ notificarErro: vi.fn() }));

function montar() {
  const aoCriar = vi.fn();
  render(
    <QueryClientProvider client={new QueryClient()}>
      <ModalNovoPlano aberto={true} modulos={{ vendas: 'Vendas', stock: 'Stock' }} onFechar={vi.fn()} onCriado={aoCriar} />
    </QueryClientProvider>,
  );
  return aoCriar;
}

describe('ModalNovoPlano', () => {
  beforeEach(() => { enviar.mockReset(); enviar.mockResolvedValue({ dados: {}, mensagem: 'Plano criado.' }); });

  it('mostra a nota de que o plano nasce inactivo e a de que os módulos são informativos', () => {
    montar();
    expect(screen.getByText(/nasce inactivo/)).toBeInTheDocument();
    expect(screen.getByText(/ainda não condicionam o acesso/)).toBeInTheDocument();
  });

  it('não envia com código inválido', async () => {
    montar();
    fireEvent.change(screen.getByPlaceholderText('ex.: profissional'), { target: { value: 'Com Espaço' } });
    fireEvent.click(screen.getByRole('button', { name: /Criar plano/ }));
    expect(await screen.findByText('Só minúsculas, números, - e _.', {}, { timeout: 30000 })).toBeInTheDocument();
    expect(enviar).not.toHaveBeenCalled();
  });

  it('com «Sem limite» envia limites nulos', async () => {
    const aoCriar = montar();
    fireEvent.change(screen.getByPlaceholderText('ex.: profissional'), { target: { value: 'pro' } });
    fireEvent.change(screen.getByLabelText('Nome'), { target: { value: 'Profissional' } });
    fireEvent.change(screen.getByLabelText('Preço mensal'), { target: { value: '25000' } });
    for (const interruptor of screen.getAllByRole('switch')) fireEvent.click(interruptor);
    fireEvent.click(screen.getByRole('button', { name: /Criar plano/ }));

    await waitFor(() => expect(enviar).toHaveBeenCalledTimes(1), { timeout: 30000 });
    const [metodo, url, corpo] = enviar.mock.calls[0];
    expect(metodo).toBe('post');
    expect(url).toBe('/plataforma/planos');
    expect(corpo).toMatchObject({ codigo: 'pro', nome: 'Profissional', preco_mensal: 25000, moeda: 'AOA', max_empresas: null, max_utilizadores: null, modulos: [] });
    await waitFor(() => expect(aoCriar).toHaveBeenCalled(), { timeout: 30000 });
  });
});
