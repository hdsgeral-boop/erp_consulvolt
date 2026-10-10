import { describe, expect, it, vi, beforeEach } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { DadosPessoais } from './DadosPessoais';

const actualizarUtilizador = vi.fn();
vi.mock('@/sessao/SessaoContexto', () => ({ useSessao: () => ({ actualizarUtilizador }) }));

const enviarMock = vi.fn();
vi.mock('@/api/cliente', () => ({
  obter: vi.fn(() =>
    Promise.resolve({ nome_utilizador: 'e2e.admin', nome_completo: 'Admin Teste', telefone: null, email: 'admin@exemplo.test', palavra_passe_alterada_em: null, minimo_palavra_passe: 8 }),
  ),
  enviar: (...a: unknown[]) => enviarMock(...a),
}));

function montar() {
  const cliente = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={cliente}>
      <DadosPessoais />
    </QueryClientProvider>,
  );
}

describe('Gerir Dados Pessoais', { timeout: 30_000 }, () => {
  beforeEach(() => {
    enviarMock.mockReset();
    actualizarUtilizador.mockReset();
  });

  it('mostra o nome de utilizador só de leitura e grava nome, telefone e e-mail', async () => {
    enviarMock.mockResolvedValue({ dados: { nome_utilizador: 'e2e.admin', nome_completo: 'Novo Nome', telefone: '+244 900 000 000', email: 'admin@exemplo.test', palavra_passe_alterada_em: null, minimo_palavra_passe: 8 }, mensagem: 'Dados pessoais gravados.' });
    montar();
    expect(await screen.findByDisplayValue('e2e.admin')).toBeDisabled();
    fireEvent.change(await screen.findByLabelText('Nome'), { target: { value: 'Novo Nome' } });
    fireEvent.change(screen.getByLabelText('Telefone'), { target: { value: '+244 900 000 000' } });
    fireEvent.click(screen.getByRole('button', { name: /Gravar dados/ }));
    await waitFor(() => expect(enviarMock).toHaveBeenCalledWith('put', '/autenticacao/dados-pessoais', { nome_completo: 'Novo Nome', telefone: '+244 900 000 000', email: 'admin@exemplo.test' }));
    await waitFor(() => expect(actualizarUtilizador).toHaveBeenCalledWith(expect.objectContaining({ nome_completo: 'Novo Nome' })));
  });

  it('não envia a palavra-passe quando a confirmação não coincide', async () => {
    montar();
    fireEvent.change(await screen.findByLabelText('Palavra-passe actual'), { target: { value: 'Antiga#2026' } });
    fireEvent.change(screen.getByLabelText('Nova palavra-passe'), { target: { value: 'NovaSenha#2026' } });
    fireEvent.change(screen.getByLabelText('Confirmar a nova palavra-passe'), { target: { value: 'Diferente#2026' } });
    fireEvent.click(screen.getByRole('button', { name: /Alterar palavra-passe/ }));
    expect(await screen.findByText('Não coincide com a nova palavra-passe.')).toBeInTheDocument();
    expect(enviarMock).not.toHaveBeenCalled();
  });
});
