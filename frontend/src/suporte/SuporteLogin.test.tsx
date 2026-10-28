import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { SuporteLogin } from './SuporteLogin';

const entrar = vi.fn();
const sair = vi.fn().mockResolvedValue(undefined);
let utilizador: { papel: string } | null = null;
let motivoSaida: string | null = null;
vi.mock('@/sessao/SessaoContexto', () => ({ useSessao: () => ({ entrar, sair, utilizador, motivoSaida }) }));

function montar(rota = '/suporte/login') {
  return render(
    <MemoryRouter initialEntries={[rota]}>
      <Routes>
        <Route path="/suporte/login" element={<SuporteLogin />} />
        <Route path="/suporte" element={<p>Backoffice de Suporte</p>} />
        <Route path="/" element={<p>Site público</p>} />
      </Routes>
    </MemoryRouter>,
  );
}

const submeter = () => {
  fireEvent.change(screen.getByLabelText('Utilizador'), { target: { value: 'agente' } });
  fireEvent.change(screen.getByLabelText('Palavra-passe'), { target: { value: 'SenhaForte123' } });
  fireEvent.click(screen.getByRole('button', { name: 'Entrar na Central de Suporte' }));
};

describe('SuporteLogin (ADR-076)', () => {
  beforeEach(() => {
    entrar.mockReset();
    sair.mockClear();
    utilizador = null;
    motivoSaida = null;
  });

  it('agente de suporte com credenciais válidas: entra no backoffice', async () => {
    entrar.mockResolvedValue({ papel: 'AGENTE_SUPORTE' });
    montar();
    submeter();
    expect(await screen.findByText('Backoffice de Suporte')).toBeInTheDocument();
    expect(sair).not.toHaveBeenCalled();
  });

  it('super-administrador com credenciais válidas: também entra no backoffice', async () => {
    entrar.mockResolvedValue({ papel: 'SUPER_ADMINISTRADOR' });
    montar();
    submeter();
    expect(await screen.findByText('Backoffice de Suporte')).toBeInTheDocument();
  });

  it('utilizador comum do ERP com credenciais válidas: autentica e é desligado de imediato, com motivo', async () => {
    entrar.mockResolvedValue({ papel: 'UTILIZADOR' });
    montar();
    submeter();
    await waitFor(() => expect(sair).toHaveBeenCalledWith('Este acesso é reservado à equipa de suporte da plataforma. Entre pela página normal do ERP.'));
    expect(screen.queryByText('Backoffice de Suporte')).toBeNull();
    expect(screen.getByText('Central de Suporte')).toBeInTheDocument();
  });

  it('credenciais inválidas: mostra o erro do servidor, sem tentar entrar', async () => {
    entrar.mockRejectedValue(new Error('Credenciais inválidas.'));
    montar();
    submeter();
    expect(await screen.findByText('Falha ao autenticar no backoffice de suporte.')).toBeInTheDocument();
    expect(sair).not.toHaveBeenCalled();
  });

  it('já autenticado como agente de suporte (ex.: recarregar a página): vai direto para /suporte', async () => {
    utilizador = { papel: 'AGENTE_SUPORTE' };
    montar();
    expect(await screen.findByText('Backoffice de Suporte')).toBeInTheDocument();
  });

  it('mostra o motivoSaida (ex.: vindo de um sair() anterior) como alerta', () => {
    motivoSaida = 'Sessão terminada após 15 minutos sem actividade.';
    montar();
    expect(screen.getByText('Sessão terminada após 15 minutos sem actividade.')).toBeInTheDocument();
  });
});
