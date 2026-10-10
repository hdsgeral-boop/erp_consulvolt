import { Alert, Modal, Result, Skeleton } from 'antd';
import { useEffect, useState } from 'react';
import { IconeFa } from '@/componentes/fluxos/IconeFa';
import { useSessao } from '@/sessao/SessaoContexto';
import { TIPOS_TERMINAL, useTerminais } from './comum/dados';
import { gravarPreferenciasFrente, lerPreferenciasFrente, type PreferenciasFrente } from './comum/frente';
import { accoesFrente } from './comum/regras';
import { AberturaCaixa } from './frente/AberturaCaixa';
import { AvisoSessoesAntigas } from './frente/AvisoSessoesAntigas';
import { BarraSessaoFrente } from './frente/BarraSessaoFrente';
import { FechoZ } from './frente/FechoZ';
import { ModoImersivo, pedirEcraInteiro } from './frente/ModoImersivo';
import { PainelVenda } from './frente/PainelVenda';
import { RelatorioXModal } from './frente/RelatorioXModal';

const chaveTerminal = (empresa: number | undefined) => `erp.pos.terminal.${empresa ?? 0}`;

function lerTerminalGuardado(empresa: number | undefined): number | null {
  try {
    const v = Number(window.localStorage.getItem(chaveTerminal(empresa)));
    return v > 0 ? v : null;
  } catch {
    return null;
  }
}

/**
 * POS › Frente de Caixa (ecrã pos) como no sistema anterior (3.1.1–3.1.9): no ERP, o cartão «Frente de Caixa Imersiva» com
 * «Iniciar Ponto de Venda (POS)»; este abre o ambiente imersivo em ecrã inteiro (PC / Tablet / Mobile, «Voltar ao Office
 * ERP») com a «Abertura de Caixa» (terminal, fundo de maneio, operador, aviso fixo das sessões abertas de dias anteriores)
 * ou, com a caixa aberta, a venda (barra da sessão, carrinho à esquerda, produtos à direita), o relatório X e o fecho Z.
 * O terminal escolhido fica memorizado neste posto; as preferências da grelha e o dispositivo, por utilizador.
 */
export default function FrenteCaixa() {
  const [imersivo, setImersivo] = useState(false);
  if (!imersivo)
    return (
      <EntradaPOS
        aoIniciar={() => {
          pedirEcraInteiro();
          setImersivo(true);
        }}
      />
    );
  return <FrenteImersiva aoSair={() => setImersivo(false)} />;
}

/** Cartão de entrada do legado (renderPOS separador «Frente de Caixa», js/ui_sales.js:158-172). */
function EntradaPOS({ aoIniciar }: { aoIniciar: () => void }) {
  return (
    <div style={{ padding: '3rem 1.5rem', textAlign: 'center', background: 'var(--ant-color-bg-container, #fff)', borderRadius: 20, boxShadow: '0 10px 30px rgba(0,0,0,0.03)', maxWidth: 600, margin: '2rem auto' }}>
      <div style={{ width: 80, height: 80, background: 'rgba(16,185,129,0.1)', color: '#10b981', borderRadius: '50%', display: 'flex', alignItems: 'center', justifyContent: 'center', margin: '0 auto 1.5rem', fontSize: '2.5rem' }}>
        <IconeFa nome="cash-register" />
      </div>
      <h3 style={{ fontSize: '1.5rem', fontWeight: 800, color: '#1e293b', marginBottom: '0.75rem' }}>Frente de Caixa Imersiva</h3>
      <p style={{ color: '#64748b', marginBottom: '2rem', fontSize: '0.95rem', lineHeight: 1.6, maxWidth: 480, marginLeft: 'auto', marginRight: 'auto' }}>
        Inicie o ponto de venda dedicado para o registo rápido de vendas. Optimizado para ecrãs tácteis, computadores, tablets ou dispositivos móveis.
      </p>
      <button
        type="button"
        onClick={aoIniciar}
        autoFocus
        style={{ background: '#10b981', color: '#fff', border: 'none', fontWeight: 700, fontSize: '1rem', padding: '0.85rem 2.5rem', borderRadius: 12, display: 'inline-flex', alignItems: 'center', gap: 10, cursor: 'pointer', boxShadow: '0 10px 20px -3px rgba(16,185,129,0.3)' }}
      >
        <IconeFa nome="external-link-alt" /> Iniciar Ponto de Venda (POS)
      </button>
    </div>
  );
}

function FrenteImersiva({ aoSair }: { aoSair: () => void }) {
  const { pode, empresa, utilizador } = useSessao();
  const terminais = useTerminais();
  const [terminalId, setTerminalId] = useState<number | null>(() => lerTerminalGuardado(empresa?.id));
  // ⇄ «Trocar de Terminal Ativo»: volta ao cartão de abertura mesmo com a caixa aberta
  const [aTrocar, setATrocar] = useState(false);
  const [verX, setVerX] = useState(false);
  // sessão do fecho Z em curso: guardada à parte para o resultado do fecho (n.º Z, imprimir) não desaparecer quando a
  // lista de terminais é recarregada e a sessão deixa de estar aberta
  const [sessaoZ, setSessaoZ] = useState<number | null>(null);
  const [prefs, setPrefs] = useState<PreferenciasFrente>(() => lerPreferenciasFrente(utilizador?.id));
  const mudarPrefs = (p: Partial<PreferenciasFrente>) =>
    setPrefs((a) => {
      const n = { ...a, ...p };
      gravarPreferenciasFrente(utilizador?.id, n);
      return n;
    });

  const escolher = (id: number | null) => {
    setTerminalId(id);
    try {
      if (id) window.localStorage.setItem(chaveTerminal(empresa?.id), String(id));
      else window.localStorage.removeItem(chaveTerminal(empresa?.id));
    } catch {
      /* armazenamento indisponível: a escolha vale só nesta página */
    }
  };

  const lista = terminais.data ?? [];
  const terminal = lista.find((t) => t.id === terminalId) ?? null;
  useEffect(() => {
    if (!terminais.data) return;
    if (terminalId && !terminal) escolher(null);
    // um único terminal activo: fica escolhido, como o selector do legado
    else if (!terminalId) {
      const activos = terminais.data.filter((t) => t.ativo);
      if (activos.length === 1) escolher(activos[0].id);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [terminais.data]);

  const accoes = accoesFrente(pode, terminal);
  const sessao = terminal?.sessao_aberta ?? null;
  // como o legado (pos_gestao.js:225): sair com a caixa aberta pede confirmação
  const sair = () =>
    sessao
      ? Modal.confirm({
          title: 'Confirmar',
          content: `A caixa ${sessao.codigo_sessao} continua aberta. Pode sair e voltar mais tarde, mas feche a caixa no fim do turno para conferir os valores. Sair do POS?`,
          okText: 'Confirmar',
          cancelText: 'Cancelar',
          onOk: aoSair,
        })
      : aoSair();

  let conteudo;
  if (terminais.isLoading) conteudo = <Skeleton active />;
  else if (terminais.isError) conteudo = <Result status="error" title="Não foi possível carregar os terminais POS." />;
  else if (!lista.length) conteudo = <Result status="info" title="Não há terminais POS" subTitle="Crie-os em Terminais POS." />;
  else if (!terminal || !sessao || aTrocar)
    conteudo = <AberturaCaixa terminais={lista} terminalId={terminalId} aoEscolher={(id) => escolher(id)} aoContinuar={() => setATrocar(false)} />;
  else
    conteudo = (
      <>
        <AvisoSessoesAntigas />
        {/* hotelaria: o mapa de quartos, o check-in e o check-out estão na própria venda (js/hotelaria.js), como no legado */}
        {terminal.tipo === 'LAVANDARIA' && (
          <Alert
            type="info"
            showIcon
            style={{ marginBottom: 12 }}
            message={`Terminal de ${TIPOS_TERMINAL[terminal.tipo].toLowerCase()}: as ordens, recebimentos e entregas fazem-se no ecrã próprio, nesta mesma sessão.`}
          />
        )}
        {accoes.vender ? (
          <PainelVenda
            key={sessao.id}
            terminal={terminal}
            sessaoId={sessao.id}
            colunas={prefs.dispositivo === 'mobile' ? 1 : 2}
            preferencias={prefs}
            aoMudarPreferencias={mudarPrefs}
            barraSessao={
              <BarraSessaoFrente
                terminal={terminal}
                podeRelatorioX={accoes.relatorioX}
                podeFechar={accoes.fecharZ}
                aoTrocarTerminal={() => setATrocar(true)}
                aoRelatorioX={() => setVerX(true)}
                aoFecharCaixa={() => setSessaoZ(sessao.id)}
              />
            }
          />
        ) : (
          <Result status="info" title="Sessão aberta" subTitle="Não tem permissão para registar vendas neste terminal." />
        )}
      </>
    );

  return (
    <ModoImersivo dispositivo={prefs.dispositivo} aoMudarDispositivo={(d) => mudarPrefs({ dispositivo: d })} aoSair={sair}>
      {conteudo}
      {sessao && <RelatorioXModal sessaoId={sessao.id} aberto={verX} aoFechar={() => setVerX(false)} />}
      {sessaoZ !== null && <FechoZ sessaoId={sessaoZ} aberto aoFechar={() => setSessaoZ(null)} />}
    </ModoImersivo>
  );
}
