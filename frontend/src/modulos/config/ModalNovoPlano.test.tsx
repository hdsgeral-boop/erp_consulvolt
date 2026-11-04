import { beforeEach, describe, expect, it, vi } from 'vitest';
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

function preencherBase() {
  fireEvent.change(screen.getByPlaceholderText('ex.: profissional'), { target: { value: 'pro' } });
  fireEvent.change(screen.getByLabelText('Nome'), { target: { value: 'Profissional' } });
  fireEvent.change(screen.getByLabelText('Preço mensal'), { target: { value: '25000' } });
  for (const interruptor of screen.getAllByRole('switch')) fireEvent.click(interruptor);
}

describe('ModalNovoPlano', () => {
  beforeEach(() => { enviar.mockReset(); enviar.mockResolvedValue({ dados: {}, mensagem: 'Plano criado.' }); });

  it('mostra a nota de que o plano nasce inactivo e a de que os módulos condicionam o acesso', () => {
    montar();
    expect(screen.getByText(/nasce inactivo/)).toBeInTheDocument();
    expect(screen.getByText(/só dá acesso a estes módulos/)).toBeInTheDocument();
  });

  it('não envia com código inválido', async () => {
    montar();
    fireEvent.change(screen.getByPlaceholderText('ex.: profissional'), { target: { value: 'Com Espaço' } });
    fireEvent.click(screen.getByRole('button', { name: /Criar plano/ }));
    expect(await screen.findByText('Só minúsculas, números, - e _.', {}, { timeout: 30000 })).toBeInTheDocument();
    expect(enviar).not.toHaveBeenCalled();
  });

  it('exige pelo menos um módulo (um plano sem módulos bloquearia tudo)', async () => {
    montar();
    preencherBase();
    fireEvent.click(screen.getByRole('button', { name: /Criar plano/ }));
    expect(await screen.findByText('Escolha pelo menos um módulo.', {}, { timeout: 30000 })).toBeInTheDocument();
    expect(enviar).not.toHaveBeenCalled();
  });

  it('com «Sem limite» envia limites nulos e os módulos escolhidos', async () => {
    const aoCriar = montar();
    preencherBase();
    fireEvent.mouseDown(screen.getByRole('combobox', { name: /Módulos incluídos/ }));
    fireEvent.click(await screen.findByText('Vendas', { selector: '.ant-select-item-option-content' }));
    fireEvent.click(screen.getByRole('button', { name: /Criar plano/ }));

    await waitFor(() => expect(enviar).toHaveBeenCalledTimes(1), { timeout: 30000 });
    const [metodo, url, corpo] = enviar.mock.calls[0];
    expect(metodo).toBe('post');
    expect(url).toBe('/plataforma/planos');
    expect(corpo).toMatchObject({ codigo: 'pro', nome: 'Profissional', preco_mensal: 25000, moeda: 'AOA', max_empresas: null, max_utilizadores: null, modulos: ['vendas'] });
    await waitFor(() => expect(aoCriar).toHaveBeenCalled(), { timeout: 30000 });
  });
});
