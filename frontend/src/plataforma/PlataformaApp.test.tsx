import { render, screen } from '@testing-library/react';
import { PlataformaApp } from './PlataformaApp';

let sessao: Record<string, unknown> = {};

vi.setConfig({ testTimeout: 120_000 });
vi.mock('@/sessao/SessaoContexto', () => ({ useSessao: () => sessao }));
vi.mock('./PlataformaEntrar', () => ({ PlataformaEntrar: () => <div>ENTRAR_PLATAFORMA</div> }));
vi.mock('./PlataformaSuporte', () => ({ PainelTickets: ({ agente }: { agente: boolean }) => <div>{agente ? 'TICKETS_AGENTE' : 'TICKETS'}</div> }));
vi.mock('@/modulos/config/ecras', () => ({ ecras: { plataforma_painel: () => <div>ECRA_PAINEL</div> } }));

describe('PlataformaApp: decisão de ecrã', () => {
  it('sem sessão mostra a entrada própria da plataforma', () => {
    sessao = { estado: 'anonimo', ePlataforma: false };
    render(<PlataformaApp />);
    expect(screen.getByText('ENTRAR_PLATAFORMA')).toBeInTheDocument();
  });

  it('utilizador que não é da plataforma é recusado (sem pedir empresa)', () => {
    sessao = { estado: 'autenticado', ePlataforma: false, sair: vi.fn() };
    render(<PlataformaApp />);
    expect(screen.getByText('Esta área é reservada à administração da plataforma.')).toBeInTheDocument();
  });

  it('administrador da plataforma, sem empresa, vê o cabeçalho, o menu e o Painel', async () => {
    window.history.pushState({}, '', '/qualquer');
    sessao = { estado: 'autenticado', ePlataforma: true, empresa: null, utilizador: { id: 1, nome_utilizador: 'adm', nome_completo: 'Admin SaaS' }, sair: vi.fn() };
    render(<PlataformaApp />);
    expect(screen.getByText('Central Admin')).toBeInTheDocument();
    expect(await screen.findByText('ECRA_PAINEL')).toBeInTheDocument();
    expect(window.location.pathname).toBe('/plataforma/painel');
  });

  it('agente de suporte entra na consola só com o «Suporte» (tickets, sem administração)', async () => {
    window.history.pushState({}, '', '/plataforma/clientes');
    sessao = { estado: 'autenticado', ePlataforma: false, eSuporte: true, empresa: null, utilizador: { id: 2, nome_utilizador: 'agente' }, sair: vi.fn() };
    render(<PlataformaApp />);
    expect(await screen.findByText('TICKETS_AGENTE')).toBeInTheDocument();
    expect(window.location.pathname).toBe('/plataforma/tickets');
    expect(screen.queryByText('Clientes')).toBeNull();
    expect(screen.queryByText('Administradores')).toBeNull();
  });
});
