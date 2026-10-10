import { Button, Skeleton } from 'antd';
import { useQuery } from '@tanstack/react-query';
import { useCallback, useEffect, useRef, useState } from 'react';
import { obter } from '@/api/cliente';
import { useAccao } from '@/componentes/Accoes';
import { IconeFa } from '@/componentes/fluxos/IconeFa';
import { useSessao } from '@/sessao/SessaoContexto';
import { chamadaNova, chaveChamada, fraseChamada, type EstadoSenhas } from './logica';
import './posArmazem.css';

/** Intervalo de actualização do monitor (ms): o monitor público pode estar noutro computador/TV. */
export const INTERVALO_MONITOR = 3000;

const CHAVE_SOM = 'pos_wh_senhas_som';

/** Chamada acústica (legado speakSentence: síntese de voz em português). */
function anunciar(texto: string) {
  if (typeof window === 'undefined' || !('speechSynthesis' in window)) return;
  try {
    const frase = new SpeechSynthesisUtterance(texto);
    frase.lang = 'pt-PT';
    frase.rate = 1;
    window.speechSynthesis.speak(frase);
  } catch {
    /* sem voz disponível */
  }
}

function lerSom(): boolean {
  try {
    return localStorage.getItem(CHAVE_SOM) !== 'nao';
  } catch {
    return true;
  }
}

/**
 * «📢 Monitor de Chamada de Senhas» (renderPOSSenhasTab, js/ui_pos_armazem.js:1126-1254): painel do operador («Tirar Nova
 * Senha», «Chamar próxima», fila em espera) e ecrã de chamada para o público (senha actual em grande, últimas chamadas),
 * com voz e ecrã inteiro. As senhas ficam no servidor (por armazém e por dia) e o monitor actualiza-se sozinho.
 */
export function Senhas({ armazem }: { armazem: number }) {
  const { pode } = useSessao();
  const operador = pode('pos_armazem_vender', 'pos_armazem_picking', 'pos_armazem_expedir');
  const monitorRef = useRef<HTMLDivElement>(null);
  const [som, setSom] = useState(lerSom);
  const [destaque, setDestaque] = useState(false);
  const [cheio, setCheio] = useState(false);
  const ultima = useRef<string | null | undefined>(undefined);

  const estado = useQuery({
    queryKey: ['logistica', 'pos-armazem', 'senhas', armazem],
    queryFn: () => obter<EstadoSenhas>('/pos/armazem/senhas', { armazem_id: armazem }),
    refetchInterval: INTERVALO_MONITOR,
    refetchIntervalInBackground: true,
  });
  const invalidar = [['logistica', 'pos-armazem', 'senhas', armazem]];
  const gerar = useAccao({ invalidar, tituloErro: 'Não foi possível tirar a senha' });
  const chamar = useAccao({ invalidar, tituloErro: 'Não foi possível chamar a senha' });
  const repetir = useAccao({ invalidar, tituloErro: 'Não foi possível repetir a chamada' });

  // nova chamada (aqui ou noutro posto): destaque verde 1,2 s e anúncio por voz
  const actual = estado.data?.actual ?? null;
  useEffect(() => {
    if (!estado.data) return;
    const chave = chaveChamada(actual);
    if (chamadaNova(ultima.current, chave) && actual) {
      setDestaque(true);
      const t = window.setTimeout(() => setDestaque(false), 1200);
      if (som) anunciar(fraseChamada(actual.codigo));
      ultima.current = chave;
      return () => window.clearTimeout(t);
    }
    ultima.current = chave;
    return undefined;
  }, [estado.data, actual, som]);
  useEffect(() => {
    ultima.current = undefined;   // outro armazém: não anuncia o que já lá estava
  }, [armazem]);

  useEffect(() => {
    const mudar = () => setCheio(document.fullscreenElement === monitorRef.current && !!monitorRef.current);
    document.addEventListener('fullscreenchange', mudar);
    return () => document.removeEventListener('fullscreenchange', mudar);
  }, []);
  const alternarEcraCheio = useCallback(() => {
    if (document.fullscreenElement) {
      void document.exitFullscreen?.().catch(() => undefined);
      return;
    }
    void monitorRef.current?.requestFullscreen?.().catch(() => undefined);
  }, []);
  const alternarSom = () => {
    setSom((s) => {
      try {
        localStorage.setItem(CHAVE_SOM, s ? 'nao' : 'sim');
      } catch {
        /* sem armazenamento */
      }
      return !s;
    });
  };
  const ecraCheioSuportado = typeof document !== 'undefined' && !!document.fullscreenEnabled;

  if (estado.isLoading) return <Skeleton active />;
  const fila = estado.data?.fila ?? [];
  const historico = estado.data?.chamadas ?? [];

  return (
    <div className="pa-senhas">
      <section className="pa-painel" aria-label="Painel de Operação de Senhas">
        <h3 className="pa-titulo-painel"><IconeFa nome="cogs" /> Painel de Operação de Senhas</h3>
        {operador && (
          <div className="pa-senhas-botoes">
            <Button type="primary" loading={gerar.isPending} onClick={() => gerar.mutate({ url: '/pos/armazem/senhas', dados: { armazem_id: armazem } })}>
              <IconeFa nome="ticket-alt" /> Tirar Nova Senha
            </Button>
            <Button type="primary" className="pa-btn-chamar" loading={chamar.isPending} disabled={!fila.length}
              onClick={() => chamar.mutate({ url: '/pos/armazem/senhas/chamar', dados: { armazem_id: armazem } })}>
              <IconeFa nome="bullhorn" /> Chamar próxima
            </Button>
          </div>
        )}
        <h4 style={{ margin: '0 0 1rem', fontSize: '0.95rem', fontWeight: 700 }}>Senhas Aguardando na Fila:</h4>
        <div className="pa-fila" aria-live="polite" data-testid="fila-senhas">
          {fila.length === 0 ? (
            <span style={{ fontSize: '0.85rem', color: '#94a3b8' }}>Fila vazia...</span>
          ) : (
            fila.map((s) => <span key={s.id} className="pa-fila-senha">{s.codigo}</span>)
          )}
        </div>
        {operador && actual && (
          <Button style={{ marginTop: 16 }} loading={repetir.isPending} onClick={() => repetir.mutate({ url: `/pos/armazem/senhas/${actual.id}/repetir` })}>
            <IconeFa nome="redo" /> Repetir chamada ({actual.codigo})
          </Button>
        )}
      </section>

      <section className="pa-monitor" ref={monitorRef} aria-label="Monitor de Chamada de Senhas">
        <div className="pa-monitor-topo">
          <button type="button" onClick={alternarSom} aria-pressed={som} title={som ? 'Desligar a voz' : 'Ligar a voz'}>
            <IconeFa nome="volume-up" /> {som ? 'Voz ligada' : 'Voz desligada'}
          </button>
          {ecraCheioSuportado && (
            <button type="button" onClick={alternarEcraCheio} aria-pressed={cheio}>
              <IconeFa nome={cheio ? 'compress' : 'expand'} /> {cheio ? 'Sair do Ecrã Cheio' : 'Ecrã Cheio'}
            </button>
          )}
        </div>
        <h4>SENHA ATUAL EM CHAMADA</h4>
        <div className={destaque ? 'pa-senha-grande chamando' : 'pa-senha-grande'} role="status" aria-live="assertive" data-testid="senha-actual">
          {actual?.codigo ?? '---'}
        </div>
        <div className="pa-historico-bloco">
          <h5>Últimas Senhas Chamadas:</h5>
          <div className="pa-historico">
            {historico.length === 0 ? <span className="pa-sem-registos">Sem registos.</span> : historico.map((s) => <div key={s.id}>{s.codigo}</div>)}
          </div>
        </div>
      </section>
    </div>
  );
}
