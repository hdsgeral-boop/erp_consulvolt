import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { definirAoExpirar, enviar, obter } from '@/api/cliente';
import type { Empresa, ModuloMenu, Utilizador } from '@/api/tipos';
import { naRotaPortal, ROTA_PORTAL } from '@/portal/rota';
import { armazenamento } from './armazenamento';

/**
 * Ao terminar sessão ou largar a empresa, o endereço volta ao início (ou ao portal, se se estava no portal). O ERP é
 * desmontado nesses momentos e, sem isto, a próxima entrada (ou a outra empresa) reabria o ecrã onde se estava.
 */
function voltarAoInicio() {
  if (window.location.pathname === '/redefinir-senha') return;   // link do e-mail de recuperação: não se toca
  const destino = naRotaPortal() ? ROTA_PORTAL : '/';
  if (window.location.pathname !== destino || window.location.search) window.history.replaceState(null, '', destino);
}

interface DadosSessao {
  utilizador: Utilizador;
  empresas: Empresa[];
  permissoes: string[];
  /** Super-administrador do tenant da plataforma SaaS: não precisa de empresa. */
  e_plataforma?: boolean;
  /** Agente de suporte da plataforma: só o backoffice de tickets (consola da plataforma, «Suporte»). */
  e_suporte?: boolean;
}

interface Sessao {
  estado: 'a_carregar' | 'anonimo' | 'autenticado';
  utilizador: Utilizador | null;
  empresas: Empresa[];
  empresa: Empresa | null;
  menu: ModuloMenu[];
  permissoes: string[];
  /** Administrador da plataforma SaaS (opera clientes, planos, licenças…), sem empresa. */
  ePlataforma: boolean;
  /** Agente de suporte da plataforma (só tickets). */
  eSuporte: boolean;
  motivoSaida: string | null;
  entrar: (nomeUtilizador: string, palavraPasse: string) => Promise<void>;
  sair: (motivo?: string) => Promise<void>;
  escolherEmpresa: (id: number) => Promise<void>;
  /** Larga a empresa activa e volta ao ecrã de escolha (legado: «Trocar empresa» → #/selecionar-empresa). */
  largarEmpresa: () => void;
  /** Tem pelo menos uma das permissões (como $this->exigir no backend); '*' = acesso total. */
  pode: (...chaves: string[]) => boolean;
  /**
   * Volta a ler as empresas a que o utilizador tem acesso (ex.: depois de criar ou eliminar uma holding na Consolidação,
   * para a holding aparecer — ou desaparecer — na troca de empresa sem novo login).
   */
  recarregarEmpresas: () => Promise<void>;
  /** Actualiza os dados do utilizador na sessão (ex.: depois de «Gerir Dados Pessoais»), sem novo pedido ao servidor. */
  actualizarUtilizador: (parcial: Partial<Utilizador>) => void;
}

type RespostaSessao = DadosSessao & { token: string; inatividade_minutos?: number };

/** Lê e retira do endereço o código de entrada vindo do domínio base (#codigo=…), para não ficar no histórico. */
function retirarCodigoEntrada(): string | null {
  const m = /^#codigo=([A-Za-z0-9]{48})$/.exec(window.location.hash);
  if (!m) return null;
  window.history.replaceState(null, '', window.location.pathname + window.location.search);
  return m[1];
}

const Contexto = createContext<Sessao | null>(null);
const INATIVIDADE_PADRAO_MIN = 15;

export function SessaoProvider({ children }: { children: ReactNode }) {
  const cliente = useQueryClient();
  const [estado, setEstado] = useState<Sessao['estado']>('a_carregar');
  const [dados, setDados] = useState<DadosSessao | null>(null);
  const [empresaId, setEmpresaId] = useState<number | null>(armazenamento.empresaId());
  const [menu, setMenu] = useState<ModuloMenu[]>([]);
  const [permissoesEmpresa, setPermissoesEmpresa] = useState<string[] | null>(null);
  const [motivoSaida, setMotivoSaida] = useState<string | null>(null);
  const inatividade = useRef(INATIVIDADE_PADRAO_MIN);

  const limparSessao = useCallback(
    (motivo: string | null) => {
      voltarAoInicio();
      armazenamento.limpar();
      setDados(null);
      setMenu([]);
      setPermissoesEmpresa(null);
      setEmpresaId(null);
      setMotivoSaida(motivo);
      setEstado('anonimo');
      cliente.clear();
    },
    [cliente],
  );

  const carregarMenu = useCallback(async () => {
    const r = await obter<{ menu: ModuloMenu[]; permissoes: string[] }>('/sistema/menu');
    setMenu(r.menu);
    setPermissoesEmpresa(r.permissoes);
  }, []);

  const aplicarSessao = useCallback((r: RespostaSessao) => {
    armazenamento.definirToken(r.token);
    armazenamento.definirEmpresa(null);
    inatividade.current = r.inatividade_minutos ?? INATIVIDADE_PADRAO_MIN;
    setDados({ utilizador: r.utilizador, empresas: r.empresas, permissoes: r.permissoes, e_plataforma: r.e_plataforma, e_suporte: r.e_suporte });
    setEmpresaId(null);
    setMotivoSaida(null);
    setEstado('autenticado');
  }, []);

  // arranque: conclui a entrada feita no domínio base (#codigo=…) ou reaproveita o token guardado
  useEffect(() => {
    definirAoExpirar(() => limparSessao('A sessão expirou. Entre novamente.'));
    const codigo = retirarCodigoEntrada();
    if (codigo) {
      enviar<RespostaSessao>('post', '/autenticacao/trocar-codigo', { codigo, dispositivo: 'web' })
        .then(({ dados }) => aplicarSessao(dados))
        .catch(() => limparSessao('O código de entrada expirou. Entre novamente.'));
      return;
    }
    if (!armazenamento.token()) {
      setEstado('anonimo');
      return;
    }
    obter<DadosSessao>('/autenticacao/eu')
      .then(async (d) => {
        setDados(d);
        const guardada = armazenamento.empresaId();
        if (guardada && d.empresas.some((e) => e.id === guardada)) await carregarMenu().catch(() => undefined);
        else {
          armazenamento.definirEmpresa(null);
          setEmpresaId(null);
        }
        setEstado('autenticado');
      })
      .catch(() => limparSessao(null));
  }, [aplicarSessao, carregarMenu, limparSessao]);

  // inactividade (o servidor também termina a sessão ao fim de N minutos sem pedidos)
  useEffect(() => {
    if (estado !== 'autenticado') return;
    let temporizador = 0;
    const reiniciar = () => {
      window.clearTimeout(temporizador);
      temporizador = window.setTimeout(() => {
        void enviar('post', '/autenticacao/sair').catch(() => undefined);
        limparSessao(`Sessão terminada após ${inatividade.current} minutos sem actividade.`);
      }, inatividade.current * 60_000);
    };
    const eventos = ['mousemove', 'keydown', 'click', 'scroll', 'touchstart'];
    eventos.forEach((e) => window.addEventListener(e, reiniciar, { passive: true }));
    reiniciar();
    return () => {
      window.clearTimeout(temporizador);
      eventos.forEach((e) => window.removeEventListener(e, reiniciar));
    };
  }, [estado, limparSessao]);

  const entrar = useCallback(
    async (nomeUtilizador: string, palavraPasse: string) => {
      const { dados: r } = await enviar<RespostaSessao | { redirecionar_para: string }>('post', '/autenticacao/entrar', {
        nome_utilizador: nomeUtilizador,
        palavra_passe: palavraPasse,
        dispositivo: 'web',
      });
      // domínio base (meuerp.ao): o servidor validou e manda abrir o endereço do cliente, com um código de uso único
      if ('redirecionar_para' in r) {
        window.location.assign(r.redirecionar_para);
        return;
      }
      aplicarSessao(r);
    },
    [aplicarSessao],
  );

  const sair = useCallback(
    async (motivo?: string) => {
      await enviar('post', '/autenticacao/sair').catch(() => undefined);
      limparSessao(motivo ?? null);
    },
    [limparSessao],
  );

  const escolherEmpresa = useCallback(
    async (id: number) => {
      armazenamento.definirEmpresa(id);
      setEmpresaId(id);
      cliente.clear();   // os dados em cache são da empresa anterior
      await carregarMenu();
    },
    [carregarMenu, cliente],
  );

  const largarEmpresa = useCallback(() => {
    voltarAoInicio();
    armazenamento.definirEmpresa(null);
    setEmpresaId(null);
    setMenu([]);
    setPermissoesEmpresa(null);
    cliente.clear();   // os dados em cache são da empresa que se larga
  }, [cliente]);

  const recarregarEmpresas = useCallback(async () => {
    const d = await obter<DadosSessao>('/autenticacao/eu');
    setDados((actual) => (actual ? { ...actual, empresas: d.empresas } : d));
  }, []);

  const actualizarUtilizador = useCallback((parcial: Partial<Utilizador>) => {
    setDados((d) => (d ? { ...d, utilizador: { ...d.utilizador, ...parcial } } : d));
  }, []);

  const valor = useMemo<Sessao>(() => {
    const permissoes = permissoesEmpresa ?? dados?.permissoes ?? [];
    const total = permissoes.includes('*');
    return {
      estado,
      utilizador: dados?.utilizador ?? null,
      empresas: dados?.empresas ?? [],
      empresa: dados?.empresas.find((e) => e.id === empresaId) ?? null,
      menu,
      permissoes,
      ePlataforma: dados?.e_plataforma === true,
      eSuporte: dados?.e_suporte === true,
      motivoSaida,
      entrar,
      sair,
      escolherEmpresa,
      largarEmpresa,
      pode: (...chaves) => total || chaves.some((c) => permissoes.includes(c.toLowerCase())),
      recarregarEmpresas,
      actualizarUtilizador,
    };
  }, [estado, dados, empresaId, menu, permissoesEmpresa, motivoSaida, entrar, sair, escolherEmpresa, largarEmpresa, recarregarEmpresas, actualizarUtilizador]);

  return <Contexto.Provider value={valor}>{children}</Contexto.Provider>;
}

export function useSessao(): Sessao {
  const s = useContext(Contexto);
  if (!s) throw new Error('useSessao fora do SessaoProvider');
  return s;
}
