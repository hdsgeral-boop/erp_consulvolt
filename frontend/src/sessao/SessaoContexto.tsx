import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { definirAoExpirar, enviar, http, obter, possivelSessaoActiva } from '@/api/cliente';
import type { Empresa, ModuloMenu, Utilizador } from '@/api/tipos';
import { armazenamento } from './armazenamento';
import { ErroMfaPendente } from './mfa';

interface DadosSessao {
  utilizador: Utilizador;
  empresas: Empresa[];
  permissoes: string[];
  /** Empresa activa da SESSÃO, guardada no servidor (ADR-082): é daqui que a sessão se reconstrói, nunca do navegador. */
  empresa_ativa_id?: number | null;
  /** Última empresa escolhida (só para ordenar o ecrã de escolha). */
  ultima_empresa_id?: number | null;
}

interface Sessao {
  estado: 'a_carregar' | 'anonimo' | 'autenticado';
  utilizador: Utilizador | null;
  empresas: Empresa[];
  empresa: Empresa | null;
  ultimaEmpresaId: number | null;
  menu: ModuloMenu[];
  permissoes: string[];
  motivoSaida: string | null;
  entrar: (nomeUtilizador: string, palavraPasse: string) => Promise<Utilizador>;
  /** 2.º passo do login com MFA: confirma o código com o token limitado que `entrar` lançou (ErroMfaPendente) e abre a sessão. */
  concluirMfa: (tokenMfa: string, codigo: string) => Promise<Utilizador>;
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
  /** Sincroniza a sessão do utilizador e selecciona a empresa imediatamente após registo (o token já chegou num cookie). */
  autenticarAposRegisto: (empresaId: number) => Promise<void>;
}

const Contexto = createContext<Sessao | null>(null);
const INATIVIDADE_PADRAO_MIN = 15;

export function SessaoProvider({ children }: { children: ReactNode }) {
  const cliente = useQueryClient();
  const [estado, setEstado] = useState<Sessao['estado']>('a_carregar');
  const [dados, setDados] = useState<DadosSessao | null>(null);
  const [empresaId, setEmpresaId] = useState<number | null>(null);
  const [menu, setMenu] = useState<ModuloMenu[]>([]);
  const [permissoesEmpresa, setPermissoesEmpresa] = useState<string[] | null>(null);
  const [motivoSaida, setMotivoSaida] = useState<string | null>(null);
  const inatividade = useRef(INATIVIDADE_PADRAO_MIN);
  const dadosRef = useRef<DadosSessao | null>(null);
  dadosRef.current = dados;

  const limparSessao = useCallback(
    (motivo: string | null) => {
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

  // arranque: o token vem num cookie HttpOnly invisível a JavaScript (Fase D) — o cookie CSRF que o acompanha
  // (não-HttpOnly, mesma validade) serve de sinal de que vale a pena perguntar ao servidor; sem ele, nunca houve
  // sessão nenhuma e pedir /autenticacao/eu só mostraria, sem razão, "a sessão expirou" a quem nunca entrou.
  useEffect(() => {
    definirAoExpirar(() => limparSessao('A sessão expirou. Entre novamente.'));
    if (!possivelSessaoActiva()) {
      setEstado('anonimo');
      return;
    }
    obter<DadosSessao>('/autenticacao/eu')
      .then(async (d) => {
        setDados(d);
        // a empresa activa vem do servidor (estado da sessão), não do navegador
        const activa = d.empresa_ativa_id ?? null;
        if (activa && d.empresas.some((e) => e.id === activa)) {
          armazenamento.definirEmpresa(activa);
          setEmpresaId(activa);
          await carregarMenu().catch(() => undefined);
        } else {
          armazenamento.definirEmpresa(null);
          setEmpresaId(null);
        }
        setEstado('autenticado');
      })
      .catch(() => limparSessao(null));
  }, [carregarMenu, limparSessao]);

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

  const entrar = useCallback(async (nomeUtilizador: string, palavraPasse: string) => {
    const { dados: r } = await enviar<DadosSessao & { inatividade_minutos?: number }>('post', '/autenticacao/entrar', {
      nome_utilizador: nomeUtilizador,
      palavra_passe: palavraPasse,
    });
    // MFA activo: ainda não há sessão (só um token limitado para o código) — o ecrã pede o código e chama concluirMfa
    if ((r as { mfa_pendente?: boolean }).mfa_pendente === true) throw new ErroMfaPendente(String((r as { token_mfa?: string }).token_mfa ?? ''));
    armazenamento.definirEmpresa(null);
    inatividade.current = r.inatividade_minutos ?? INATIVIDADE_PADRAO_MIN;
    setDados({ utilizador: r.utilizador, empresas: r.empresas, permissoes: r.permissoes, ultima_empresa_id: r.ultima_empresa_id ?? null });
    setEmpresaId(null);
    setMotivoSaida(null);
    setEstado('autenticado');
    return r.utilizador;
  }, []);

  const concluirMfa = useCallback(async (tokenMfa: string, codigo: string) => {
    // o token limitado vai no cabeçalho só neste pedido; a sessão completa chega no cookie HttpOnly
    await http.post('/autenticacao/mfa/verificar', { codigo }, { headers: { Authorization: `Bearer ${tokenMfa}` } });
    const d = await obter<DadosSessao>('/autenticacao/eu');
    armazenamento.definirEmpresa(null);
    setDados({ utilizador: d.utilizador, empresas: d.empresas, permissoes: d.permissoes, ultima_empresa_id: d.ultima_empresa_id ?? null });
    setEmpresaId(null);
    setMotivoSaida(null);
    setEstado('autenticado');
    return d.utilizador;
  }, []);

  const sair = useCallback(
    async (motivo?: string) => {
      await enviar('post', '/autenticacao/sair').catch(() => undefined);
      limparSessao(motivo ?? null);
    },
    [limparSessao],
  );

  const escolherEmpresa = useCallback(
    async (id: number) => {
      await enviar('put', '/autenticacao/empresa-activa', { empresa_id: id });   // estado da sessão no servidor
      armazenamento.definirEmpresa(id);
      // conta root (ADR-084): a empresa pode ser de outro cliente e não vir na lista do início — o servidor devolve-a em `eu`
      if (dadosRef.current?.utilizador.conta_root === true && !dadosRef.current.empresas.some((e) => e.id === id)) {
        const d = await obter<DadosSessao>('/autenticacao/eu');
        setDados(d);
      }
      setEmpresaId(id);
      setDados((d) => (d ? { ...d, ultima_empresa_id: id } : d));
      cliente.clear();   // os dados em cache são da empresa anterior
      await carregarMenu();
    },
    [carregarMenu, cliente],
  );

  const largarEmpresa = useCallback(() => {
    armazenamento.definirEmpresa(null);
    setEmpresaId(null);
    setMenu([]);
    setPermissoesEmpresa(null);
    cliente.clear();   // os dados em cache são da empresa que se larga
    void enviar('put', '/autenticacao/empresa-activa', { empresa_id: null }).catch(() => undefined);
  }, [cliente]);

  const recarregarEmpresas = useCallback(async () => {
    const d = await obter<DadosSessao>('/autenticacao/eu');
    setDados((actual) => (actual ? { ...actual, empresas: d.empresas } : d));
  }, []);

  const actualizarUtilizador = useCallback((parcial: Partial<Utilizador>) => {
    setDados((d) => (d ? { ...d, utilizador: { ...d.utilizador, ...parcial } } : d));
  }, []);

  const autenticarAposRegisto = useCallback(
    async (novaEmpresaId: number) => {
      await enviar('put', '/autenticacao/empresa-activa', { empresa_id: novaEmpresaId });
      armazenamento.definirEmpresa(novaEmpresaId);
      const d = await obter<DadosSessao>('/autenticacao/eu');
      setDados(d);
      setEmpresaId(novaEmpresaId);
      setMotivoSaida(null);
      setEstado('autenticado');
      await carregarMenu().catch(() => undefined);
    },
    [carregarMenu],
  );

  const valor = useMemo<Sessao>(() => {
    const permissoes = permissoesEmpresa ?? dados?.permissoes ?? [];
    const total = permissoes.includes('*');
    return {
      estado,
      utilizador: dados?.utilizador ?? null,
      empresas: dados?.empresas ?? [],
      empresa: dados?.empresas.find((e) => e.id === empresaId) ?? null,
      ultimaEmpresaId: dados?.ultima_empresa_id ?? null,
      menu,
      permissoes,
      motivoSaida,
      entrar,
      concluirMfa,
      sair,
      escolherEmpresa,
      largarEmpresa,
      pode: (...chaves) => total || chaves.some((c) => permissoes.includes(c.toLowerCase())),
      recarregarEmpresas,
      actualizarUtilizador,
      autenticarAposRegisto,
    };
  }, [estado, dados, empresaId, menu, permissoesEmpresa, motivoSaida, entrar, concluirMfa, sair, escolherEmpresa, largarEmpresa, recarregarEmpresas, actualizarUtilizador, autenticarAposRegisto]);

  return <Contexto.Provider value={valor}>{children}</Contexto.Provider>;
}

export function useSessao(): Sessao {
  const s = useContext(Contexto);
  if (!s) throw new Error('useSessao fora do SessaoProvider');
  return s;
}
