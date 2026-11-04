import { render, screen } from '@testing-library/react';
import { App } from './App';

/**
 * Isolamento do backoffice de suporte (ADR-076): um agente de suporte autenticado nunca alcança o resto da aplicação
 * — nem a Plataforma, nem o ERP de uma empresa, nem o Portal — mesmo indo direto ao endereço. Cada rota fora do
 * backoffice reencaminha sempre para /suporte. A barreira real (dados) é do servidor; isto é só a SPA nunca a mostrar.
 */

let sessao: { estado: string; empresa: { id: number; nome: string } | null; utilizador: { papel: string } | null };
vi.mock('@/sessao/SessaoContexto', () => ({ useSessao: () => sessao }));
vi.mock('@/portal/rota', () => ({ ehHostPortal: () => false }));

// Todos os ecrãs são pesados (dados, sessão, etc.): substituídos por marcadores — só o encaminhamento está em teste.
vi.mock('@/layout/LayoutPrincipal', () => ({ LayoutPrincipal: () => <p>Layout ERP</p> }));
vi.mock('@/paginas/Entrar', () => ({ Entrar: () => <p>Login ERP</p> }));
vi.mock('@/paginas/RecuperarPassword', () => ({ RecuperarPassword: () => <p>Recuperar password</p> }));
vi.mock('@/paginas/EscolherEmpresa', () => ({ EscolherEmpresa: () => <p>Escolher empresa</p> }));
vi.mock('@/paginas/Inicio', () => ({ Inicio: () => <p>Início</p> }));
vi.mock('@/paginas/Ecra', () => ({ Ecra: () => <p>Ecrã do módulo</p> }));
vi.mock('@/paginas/DadosPessoais', () => ({ DadosPessoais: () => <p>Dados pessoais</p> }));
vi.mock('@/portal/PortalAutonomo', () => ({ PortalAutonomo: () => <p>Portal do colaborador</p> }));
vi.mock('@/paginas/publico/LandingPage', () => ({ LandingPage: () => <p>Landing page</p> }));
vi.mock('@/paginas/publico/RegistarEmpresa', () => ({ RegistarEmpresa: () => <p>Registar empresa</p> }));
vi.mock('@/paginas/publico/legal/TermosUtilizacao', () => ({ TermosUtilizacao: () => <p>Termos</p> }));
vi.mock('@/paginas/publico/legal/PoliticaPrivacidade', () => ({ PoliticaPrivacidade: () => <p>Privacidade</p> }));
vi.mock('@/paginas/publico/legal/ConformidadeFiscal', () => ({ ConformidadeFiscal: () => <p>Conformidade</p> }));
vi.mock('@/plataforma/LayoutPlataforma', () => ({ LayoutPlataforma: () => <p>Layout Plataforma</p> }));
vi.mock('@/plataforma/PlataformaDashboardExecutivo', () => ({ PlataformaDashboardExecutivo: () => <p>Dashboard Plataforma</p> }));
vi.mock('@/plataforma/PlataformaLogin', () => ({ PlataformaLogin: () => <p>Login Plataforma</p> }));
vi.mock('@/modulos/config/PlataformaClientes', () => ({ default: () => <p>Clientes</p> }));
vi.mock('@/modulos/config/PlataformaPlanos', () => ({ default: () => <p>Planos</p> }));
vi.mock('@/modulos/config/PlataformaSaasGestao', () => ({ default: () => <p>SaaS</p> }));
vi.mock('@/modulos/config/PlataformaSuporte', () => ({ default: () => <p>Suporte Plataforma</p> }));
vi.mock('@/modulos/config/PlataformaAuditoria', () => ({ default: () => <p>Auditoria</p> }));
vi.mock('@/modulos/config/PlataformaDefinicoes', () => ({ default: () => <p>Definições</p> }));
vi.mock('@/modulos/config/PlataformaSessoes', () => ({ default: () => <p>Sessões</p> }));
vi.mock('@/modulos/config/PlataformaAdministradores', () => ({ default: () => <p>Administradores</p> }));
vi.mock('@/modulos/config/PlataformaAgentesSuporte', () => ({ default: () => <p>Agentes de Suporte</p> }));
vi.mock('@/suporte/LayoutSuporte', () => ({ LayoutSuporte: () => <p>Layout Backoffice de Suporte</p> }));
vi.mock('@/suporte/SuporteLogin', () => ({ SuporteLogin: () => <p>Login Suporte</p> }));
vi.mock('@/suporte/SuportePainel', () => ({ default: () => <p>Tickets de suporte</p> }));

function irPara(caminho: string) {
  window.history.pushState({}, '', caminho);
}

describe('App — isolamento do backoffice de suporte (ADR-076)', () => {
  beforeEach(() => {
    sessao = { estado: 'autenticado', empresa: null, utilizador: { papel: 'AGENTE_SUPORTE' } };
  });

  it('/suporte: é o único destino alcançável — mostra o backoffice', async () => {
    irPara('/suporte');
    render(<App />);
    expect(await screen.findByText('Layout Backoffice de Suporte')).toBeInTheDocument();
  });

  it.each(['/app', '/inicio', '/dados-pessoais', '/m/vendas/faturacao', '/portal', '/'])(
    '%s: reencaminha sempre para /suporte, nunca mostra o resto do ERP',
    async (caminho) => {
      irPara(caminho);
      render(<App />);
      expect(await screen.findByText('Layout Backoffice de Suporte')).toBeInTheDocument();
      expect(screen.queryByText('Layout ERP')).toBeNull();
      expect(screen.queryByText('Escolher empresa')).toBeNull();
      expect(screen.queryByText('Portal do colaborador')).toBeNull();
    },
  );

  it('/admin: também reencaminha para /suporte (já coberto pela guarda da Plataforma)', async () => {
    irPara('/admin');
    render(<App />);
    expect(await screen.findByText('Layout Backoffice de Suporte')).toBeInTheDocument();
    expect(screen.queryByText('Layout Plataforma')).toBeNull();
  });

  it('um super-administrador continua a poder usar /suporte e /admin', async () => {
    sessao = { estado: 'autenticado', empresa: null, utilizador: { papel: 'SUPER_ADMINISTRADOR' } };
    irPara('/admin');
    render(<App />);
    expect(await screen.findByText('Layout Plataforma')).toBeInTheDocument();
  });

  it('um utilizador comum de empresa não é afectado pela guarda do backoffice', async () => {
    sessao = { estado: 'autenticado', empresa: { id: 1, nome: 'Empresa Teste' }, utilizador: { papel: 'UTILIZADOR' } };
    irPara('/inicio');
    render(<App />);
    expect(await screen.findByText('Layout ERP')).toBeInTheDocument();
  });
});

/**
 * Terminar sessão ou largar a empresa não reabre o ecrã onde se estava (a produção resolvia-o com history.replaceState
 * fora do router): aqui o próprio encaminhamento trata disso — sem sessão cai em /entrar e, com sessão, /entrar vai a /inicio.
 */
describe('App — voltar ao início ao terminar sessão', () => {
  it('sem sessão, um ecrã de módulo reencaminha para /entrar (o endereço do ecrã anterior desaparece)', async () => {
    sessao = { estado: 'anonimo', empresa: null, utilizador: null };
    irPara('/m/rh/colaboradores');
    render(<App />);
    expect(await screen.findByText('Login ERP')).toBeInTheDocument();
    expect(window.location.pathname).toBe('/entrar');
  });

  it('depois de entrar, /entrar leva a /inicio e não ao ecrã onde se estava', async () => {
    sessao = { estado: 'autenticado', empresa: { id: 1, nome: 'Empresa Teste' }, utilizador: { papel: 'UTILIZADOR' } };
    irPara('/entrar');
    render(<App />);
    expect(await screen.findByText('Layout ERP')).toBeInTheDocument();
    expect(window.location.pathname).toBe('/inicio');
  });
});
