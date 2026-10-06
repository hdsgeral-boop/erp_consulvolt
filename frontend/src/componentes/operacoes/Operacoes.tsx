import { CheckCircleFilled, CloseCircleFilled, CloseOutlined, DownOutlined, LoadingOutlined, StopOutlined, UpOutlined } from '@ant-design/icons';
import { Button, Progress, Tooltip, Typography } from 'antd';
import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { enviar, obter } from '@/api/cliente';

/**
 * Gestor de operações em segundo plano (M-05; legado js/tarefas.js + js/shared/janela_processo.js): painel no canto com
 * as operações longas em curso (progresso, minimizar, concluída/falhou), que continuam enquanto o utilizador navega.
 *
 * Dois tipos:
 *  - no cliente: `executar(titulo, async (progresso) => { …; progresso(40, 'A ler página 2 de 5'); … })` — ex. a recolha
 *    de todas as páginas para imprimir/exportar;
 *  - no servidor: `acompanharLote(id, titulo)` para um lote de trabalhos na fila (ServicoOperacoes::despachar, resposta
 *    com `id`), acompanhado por GET /api/sistema/operacoes/{id} de 2 em 2 s; pode ser cancelado.
 */
export type EstadoOperacao = 'EM_CURSO' | 'CONCLUIDA' | 'CONCLUIDA_COM_FALHAS' | 'FALHOU' | 'CANCELADA';

export interface Operacao {
  id: string;
  titulo: string;
  progresso: number;
  detalhe?: string;
  estado: EstadoOperacao;
  servidor?: boolean;
  iniciada: number;
}

interface ApiOperacao {
  id: string;
  titulo: string;
  progresso: number;
  total: number;
  pendentes: number;
  falhados: number;
  estado: 'EM_CURSO' | 'CONCLUIDA' | 'CONCLUIDA_COM_FALHAS' | 'CANCELADA';
}

interface ContextoOperacoes {
  operacoes: Operacao[];
  executar: <T>(titulo: string, fn: (progresso: (percentagem: number, detalhe?: string) => void) => Promise<T>) => Promise<T>;
  acompanharLote: (id: string, titulo: string) => void;
  remover: (id: string) => void;
}

const Contexto = createContext<ContextoOperacoes | null>(null);
const INTERVALO_MS = 2000;
let sequencia = 0;

export function OperacoesProvider({ children }: { children: ReactNode }) {
  const [operacoes, setOperacoes] = useState<Operacao[]>([]);
  const temporizadores = useRef(new Map<string, number>());

  const actualizar = useCallback((id: string, d: Partial<Operacao>) => setOperacoes((l) => l.map((o) => (o.id === id ? { ...o, ...d } : o))), []);
  const remover = useCallback((id: string) => {
    window.clearTimeout(temporizadores.current.get(id));
    temporizadores.current.delete(id);
    setOperacoes((l) => l.filter((o) => o.id !== id));
  }, []);

  const executar = useCallback<ContextoOperacoes['executar']>(
    async (titulo, fn) => {
      const id = `local-${++sequencia}`;
      setOperacoes((l) => [...l, { id, titulo, progresso: 0, estado: 'EM_CURSO', iniciada: Date.now() }]);
      try {
        const r = await fn((p, detalhe) => actualizar(id, { progresso: Math.max(0, Math.min(100, Math.round(p))), detalhe }));
        actualizar(id, { progresso: 100, estado: 'CONCLUIDA', detalhe: undefined });
        window.setTimeout(() => remover(id), 4000);
        return r;
      } catch (e) {
        actualizar(id, { estado: 'FALHOU', detalhe: e instanceof Error ? e.message : 'Falhou.' });
        throw e;
      }
    },
    [actualizar, remover],
  );

  const acompanharLote = useCallback(
    (id: string, titulo: string) => {
      setOperacoes((l) => (l.some((o) => o.id === id) ? l : [...l, { id, titulo, progresso: 0, estado: 'EM_CURSO', servidor: true, iniciada: Date.now() }]));
      const ciclo = async () => {
        try {
          const r = await obter<ApiOperacao>(`/sistema/operacoes/${id}`);
          actualizar(id, { progresso: r.progresso, estado: r.estado, detalhe: `${r.total - r.pendentes} de ${r.total}${r.falhados ? ` · ${r.falhados} com erro` : ''}` });
          if (r.estado === 'EM_CURSO') temporizadores.current.set(id, window.setTimeout(ciclo, INTERVALO_MS));
        } catch (e) {
          actualizar(id, { estado: 'FALHOU', detalhe: e instanceof Error ? e.message : 'Não foi possível obter o estado.' });
        }
      };
      void ciclo();
    },
    [actualizar],
  );

  useEffect(() => {
    const t = temporizadores.current;
    return () => t.forEach((h) => window.clearTimeout(h));
  }, []);

  const valor = useMemo(() => ({ operacoes, executar, acompanharLote, remover }), [operacoes, executar, acompanharLote, remover]);
  return (
    <Contexto.Provider value={valor}>
      {children}
      <PainelOperacoes />
    </Contexto.Provider>
  );
}

/** Fora do OperacoesProvider (ex.: testes de um ecrã isolado) as operações correm sem painel. */
const SEM_PAINEL: ContextoOperacoes = {
  operacoes: [],
  executar: (_t, fn) => fn(() => undefined),
  acompanharLote: () => undefined,
  remover: () => undefined,
};

export function useOperacoes(): ContextoOperacoes {
  return useContext(Contexto) ?? SEM_PAINEL;
}

const ICONE: Record<EstadoOperacao, ReactNode> = {
  EM_CURSO: <LoadingOutlined spin aria-hidden />,
  CONCLUIDA: <CheckCircleFilled style={{ color: '#047857' }} aria-hidden />,
  CONCLUIDA_COM_FALHAS: <CloseCircleFilled style={{ color: '#b45309' }} aria-hidden />,
  FALHOU: <CloseCircleFilled style={{ color: '#dc2626' }} aria-hidden />,
  CANCELADA: <StopOutlined style={{ color: '#64748b' }} aria-hidden />,
};

/** Painel no canto inferior: lista das operações, minimizável (o legado passava a segundo plano aos 20 s). */
export function PainelOperacoes() {
  const ctx = useContext(Contexto);
  const [minimizado, setMinimizado] = useState(false);
  if (!ctx || !ctx.operacoes.length) return null;
  const emCurso = ctx.operacoes.filter((o) => o.estado === 'EM_CURSO').length;
  return (
    <section className="erp-operacoes no-print" aria-label="Operações em segundo plano" aria-live="polite">
      <header className="erp-operacoes-cabeca">
        <Typography.Text strong>{emCurso ? `Operações em curso (${emCurso})` : 'Operações concluídas'}</Typography.Text>
        <Button size="small" type="text" icon={minimizado ? <UpOutlined /> : <DownOutlined />} aria-label={minimizado ? 'Mostrar operações' : 'Minimizar operações'} onClick={() => setMinimizado(!minimizado)} />
      </header>
      {!minimizado && (
        <ul className="erp-operacoes-lista">
          {ctx.operacoes.map((o) => (
            <li key={o.id} className="erp-operacao">
              <div className="erp-operacao-linha">
                <span className="erp-operacao-titulo">{ICONE[o.estado]} {o.titulo}</span>
                {o.estado === 'EM_CURSO' && o.servidor ? (
                  <Tooltip title="Cancelar">
                    <Button size="small" type="text" icon={<StopOutlined />} aria-label={`Cancelar ${o.titulo}`} onClick={() => void enviar('post', `/sistema/operacoes/${o.id}/cancelar`).then(() => ctx.acompanharLote(o.id, o.titulo)).catch(() => undefined)} />
                  </Tooltip>
                ) : o.estado !== 'EM_CURSO' ? (
                  <Button size="small" type="text" icon={<CloseOutlined />} aria-label={`Fechar ${o.titulo}`} onClick={() => ctx.remover(o.id)} />
                ) : null}
              </div>
              <Progress percent={o.progresso} size="small" status={o.estado === 'FALHOU' ? 'exception' : o.estado === 'EM_CURSO' ? 'active' : o.estado === 'CONCLUIDA' ? 'success' : 'normal'} />
              {o.detalhe && <Typography.Text type="secondary" style={{ fontSize: 12 }}>{o.detalhe}</Typography.Text>}
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}
