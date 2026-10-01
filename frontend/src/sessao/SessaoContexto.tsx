import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { definirAoExpirar, enviar, obter } from '@/api/cliente';
import type { Empresa, ModuloMenu, Utilizador } from '@/api/tipos';
import { armazenamento } from './armazenamento';

interface DadosSessao {
  utilizador: Utilizador;
  empresas: Empresa[];
  permissoes: string[];
}

interface Sessao {
  estado: 'a_carregar' | 'anonimo' | 'autenticado';
  utilizador: Utilizador | null;
  empresas: Empresa[];
  empresa: Empresa | null;
  menu: ModuloMenu[];
  permissoes: string[];
  motivoSaida: string | null;
  entrar: (nomeUtilizador: string, palavraPasse: string) => Promise<void>;
  sair: (motivo?: string) => Promise<void>;
  escolherEmpresa: (id: number) => Promise<void>;
  /** Tem pelo menos uma das permissões (como $this->exigir no backend); '*' = acesso total. */
  pode: (...chaves: string[]) => boolean;
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

  // arranque: reaproveita o token guardado
  useEffect(() => {
    definirAoExpirar(() => limparSessao('A sessão expirou. Entre novamente.'));
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
    const { dados: r } = await enviar<DadosSessao & { token: string; inatividade_minutos?: number }>('post', '/autenticacao/entrar', {
      nome_utilizador: nomeUtilizador,
      palavra_passe: palavraPasse,
      dispositivo: 'web',
    });
    armazenamento.definirToken(r.token);
    armazenamento.definirEmpresa(null);
    inatividade.current = r.inatividade_minutos ?? INATIVIDADE_PADRAO_MIN;
    setDados({ utilizador: r.utilizador, empresas: r.empresas, permissoes: r.permissoes });
    setEmpresaId(null);
    setMotivoSaida(null);
    setEstado('autenticado');
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
      armazenamento.definirEmpresa(id);
      setEmpresaId(id);
      cliente.clear();   // os dados em cache são da empresa anterior
      await carregarMenu();
    },
    [carregarMenu, cliente],
  );

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
      motivoSaida,
      entrar,
      sair,
      escolherEmpresa,
      pode: (...chaves) => total || chaves.some((c) => permissoes.includes(c.toLowerCase())),
    };
  }, [estado, dados, empresaId, menu, permissoesEmpresa, motivoSaida, entrar, sair, escolherEmpresa]);

  return <Contexto.Provider value={valor}>{children}</Contexto.Provider>;
}

export function useSessao(): Sessao {
  const s = useContext(Contexto);
  if (!s) throw new Error('useSessao fora do SessaoProvider');
  return s;
}
