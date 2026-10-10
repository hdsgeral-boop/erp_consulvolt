import { Button, DatePicker, Input, InputNumber, Modal, Select, Skeleton, message } from 'antd';
import dayjs, { type Dayjs } from 'dayjs';
import { useState, type CSSProperties, type ReactNode } from 'react';
import { IconeFa } from '@/componentes/fluxos/IconeFa';
import { ModalMotivo, useAccao } from '@/componentes/Accoes';
import { useSessao } from '@/sessao/SessaoContexto';
import { formatarDataHora, formatarKz } from '@/utilitarios/formatacao';
import { SeletorTerceiro } from '@/modulos/compras/comum/Seletores';
import { formatarCentimos } from '../comum/calculos';
import { accoesEstadia } from '../comum/regras';
import type { Terminal } from '../comum/tipos';
import { ModalAlterar } from '../hotelaria/Estadia';
import { precoQuarto, quantidadeMinima, type Estadia, type Quarto } from '../hotelaria/tipos';
import { horaBloqueada, preverCheckin, regrasHotel, unidadeEstadia } from './quartos';

/**
 * Painel de hospedagem no topo do carrinho (renderPOSHotelStayPanel, js/hotelaria.js:259-411): no Balcão, a nota da venda
 * directa; no quarto livre, o formulário de check-in (painelCheckin); no quarto ocupado, a estadia (painelOcupado) com
 * Alterar, Anular e Check-out. Os consumos são o próprio carrinho.
 */

export function NotaBalcao() {
  return (
    <div style={{ background: '#f8fafc', borderBottom: '1px solid #e2e8f0', padding: '0.6rem 1rem', fontSize: '0.78rem', color: '#475569' }}>
      <IconeFa nome="cash-register" /> <strong>Balcão</strong> — venda directa. Para alojamento, seleccione um quarto no mapa.
    </div>
  );
}

const corTexto = '#78350f';
const caixa = { background: '#fef3c7', borderBottom: '1px solid #fcd34d', padding: '0.8rem 1rem', display: 'flex', flexDirection: 'column', gap: '0.5rem' } as const;
const campo = { width: '100%', fontSize: '0.78rem' } as const;
const Rotulo = ({ children }: { children: ReactNode }) => <label style={{ fontSize: '0.7rem', fontWeight: 800, color: corTexto, margin: 0, display: 'block' }}>{children}</label>;

/** Check-in no próprio carrinho (painelCheckin + hotelConfirmarCheckin, hotelaria.js:270-386); POST /pos/hotelaria/sessoes/{sessao}/checkin. */
export function CheckinQuarto({ quarto, terminal, clienteActual, aoConcluir }: { quarto: Quarto; terminal: Terminal; clienteActual?: number; aoConcluir: (hospede: number) => void }) {
  const { pode } = useSessao();
  const rg = regrasHotel(terminal);
  const [hospede, setHospede] = useState<number | undefined>(clienteActual);
  const [modo, setModo] = useState<'DIA' | 'HORA'>('DIA');
  const [pessoas, setPessoas] = useState<number>(1);
  const [entrada, setEntrada] = useState<Dayjs | null>(() => dayjs());
  const [quantidade, setQuantidade] = useState<number>(1);
  const [preco, setPreco] = useState<number>(precoQuarto('DIA', quarto));
  const [obs, setObs] = useState('');
  const checkin = useAccao({ invalidar: [['pos']], tituloErro: 'Não foi possível registar o check-in', aoSucesso: () => hospede && aoConcluir(hospede) });
  const sessaoId = terminal.sessao_aberta?.id;
  const previsao = preverCheckin({ modo, entrada: entrada ? entrada.toDate() : null, quantidade, preco }, quarto, rg);
  const temHora = Number(quarto.preco_por_hora) > 0;
  const podeCheckin = pode('hotel_estadias');

  const mudarModo = (m: 'DIA' | 'HORA') => {
    setModo(m);
    setQuantidade(quantidadeMinima(m, quarto));
    setPreco(precoQuarto(m, quarto));
  };

  const confirmar = async () => {
    if (!hospede) return void message.warning('Seleccione o hóspede.');
    if (previsao.bloqueia) return void message.warning(previsao.avisos[0] ?? 'Verifique os dados do check-in.');
    if (preco <= 0) {
      const continuar = await new Promise<boolean>((resolve) =>
        Modal.confirm({ title: 'O alojamento fica com preço 0. Continuar?', okText: 'Continuar', cancelText: 'Cancelar', onOk: () => resolve(true), onCancel: () => resolve(false) }),
      );
      if (!continuar) return;
    }
    const precoAlterado = Math.round(preco * 100) !== Math.round(precoQuarto(modo, quarto) * 100);
    checkin.mutate({
      url: `/pos/hotelaria/sessoes/${sessaoId}/checkin`,
      dados: {
        produto_quarto_id: quarto.produto_id,
        cliente_hospede_id: hospede,
        modo,
        quantidade,
        ...(precoAlterado ? { preco_unitario: preco } : {}),
        entrada_em: entrada ? entrada.format('YYYY-MM-DD HH:mm:ss') : undefined,
        numero_hospedes: pessoas,
        observacoes: obs.trim() || undefined,
      },
    });
  };

  return (
    <div style={caixa}>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
        <span style={{ fontSize: '0.85rem', fontWeight: 800, color: '#b45309' }}>
          <IconeFa nome="door-open" /> Check-in — {quarto.nome}
        </span>
        <span style={{ fontSize: '0.7rem', color: '#059669', fontWeight: 700 }}>Livre</span>
      </div>
      {!podeCheckin ? (
        <span style={{ fontSize: '0.75rem', color: corTexto }}>Não tem permissão para registar check-ins (Estadias da hotelaria).</span>
      ) : (
        <>
          <div>
            <Rotulo>Hóspede (cliente)</Rotulo>
            <SeletorTerceiro papel="CLIENTE" size="small" value={hospede} onChange={(v) => setHospede(v)} style={campo} aria-label="Hóspede" />
          </div>
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '0.45rem' }}>
            <div>
              <Rotulo>Modalidade</Rotulo>
              <Select<'DIA' | 'HORA'>
                size="small"
                aria-label="Modalidade"
                style={campo}
                value={modo}
                onChange={mudarModo}
                options={[
                  { value: 'DIA', label: 'Diária' },
                  { value: 'HORA', label: `À hora${horaBloqueada(new Date(), rg) ? ' (bloqueada agora)' : ''}`, disabled: !temHora },
                ]}
              />
            </div>
            <div>
              <Rotulo>N.º de hóspedes</Rotulo>
              <InputNumber<number> size="small" aria-label="N.º de hóspedes" min={1} precision={0} style={campo} value={pessoas} onChange={(v) => setPessoas(Math.max(1, v ?? 1))} />
            </div>
          </div>
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '0.45rem' }}>
            <div>
              <Rotulo>Entrada</Rotulo>
              <DatePicker size="small" aria-label="Entrada" showTime format="DD/MM/YYYY HH:mm" style={campo} value={entrada} onChange={(v) => setEntrada(v)} />
            </div>
            <div>
              <Rotulo>{modo === 'HORA' ? `N.º de horas (mín. ${quantidadeMinima('HORA', quarto)})` : 'N.º de diárias'}</Rotulo>
              <InputNumber<number>
                size="small"
                aria-label="Quantidade"
                min={modo === 'HORA' ? quantidadeMinima('HORA', quarto) : 1}
                step={modo === 'HORA' ? 0.5 : 1}
                precision={modo === 'HORA' ? 1 : 0}
                style={campo}
                value={quantidade}
                onChange={(v) => setQuantidade(v ?? 0)}
              />
            </div>
          </div>
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '0.45rem' }}>
            <div>
              <Rotulo>{modo === 'HORA' ? 'Preço por hora (IVA incl.)' : 'Preço por diária (IVA incl.)'}</Rotulo>
              <InputNumber<number>
                size="small"
                aria-label="Preço"
                min={0}
                precision={2}
                decimalSeparator=","
                style={campo}
                disabled={!pode('pos_desconto')}
                title={pode('pos_desconto') ? undefined : 'Alterar o preço exige a permissão de descontos.'}
                value={preco}
                onChange={(v) => setPreco(v ?? 0)}
              />
            </div>
            <div>
              <Rotulo>Observações</Rotulo>
              <Input size="small" aria-label="Observações" placeholder="Opcional" maxLength={2000} style={campo} value={obs} onChange={(e) => setObs(e.target.value)} />
            </div>
          </div>
          <div style={{ background: '#fde68a', borderRadius: 4, padding: '0.4rem 0.6rem', fontSize: '0.74rem', fontWeight: 700, color: corTexto }}>
            {previsao.saida &&
              `Saída prevista: ${formatarDataHora(previsao.saida.toISOString())} · Alojamento: ${unidadeEstadia(modo, quantidade)} × ${formatarKz(preco)} = ${formatarCentimos(previsao.alojamento)} Kz`}
            {previsao.avisos.length > 0 && (
              <div style={{ color: previsao.bloqueia ? '#b91c1c' : '#9a3412', marginTop: 2 }}>
                {previsao.avisos.map((a) => (
                  <div key={a}>{a}</div>
                ))}
              </div>
            )}
          </div>
          {!sessaoId && <span style={{ fontSize: '0.74rem', color: '#b91c1c' }}>O terminal não tem sessão aberta.</span>}
          <Button
            block
            loading={checkin.isPending}
            disabled={previsao.bloqueia || !hospede || !sessaoId}
            onClick={() => void confirmar()}
            icon={<IconeFa nome="sign-in-alt" />}
            style={{ fontSize: '0.8rem', fontWeight: 800, borderRadius: 4, height: 36, background: '#b45309', color: '#fff', border: 'none', opacity: previsao.bloqueia || !hospede ? 0.5 : 1 }}
          >
            Confirmar check-in
          </Button>
        </>
      )}
    </div>
  );
}

/** Estadia do quarto ocupado (painelOcupado, hotelaria.js:388-411); os consumos vêm do carrinho (em cêntimos). */
export function EstadiaQuarto({
  quarto,
  estadia,
  consumos,
  temConsumos,
  terminal,
  aoCheckout,
}: {
  quarto: Quarto;
  estadia: Estadia | undefined;
  consumos: number;
  temConsumos: boolean;
  terminal: Terminal;
  aoCheckout: () => void;
}) {
  const { pode } = useSessao();
  const [alterar, setAlterar] = useState(false);
  const [anular, setAnular] = useState(false);
  const accao = useAccao({
    invalidar: [['pos']],
    aoSucesso: () => {
      setAlterar(false);
      setAnular(false);
    },
  });
  if (!estadia) return <div style={caixa}><Skeleton active paragraph={{ rows: 3 }} title={false} /></div>;
  const atrasado = !!quarto.estadia?.atrasado;
  const alojamento = Math.round(Number(estadia.quantidade) * Number(estadia.preco_unitario) * 100);
  const a = accoesEstadia(pode, { estado: estadia.estado, itens: temConsumos ? [1] : [] }, !!terminal.sessao_aberta);
  const linha = (rotulo: ReactNode, valor: ReactNode, estilo: CSSProperties = {}) => (
    <div style={{ display: 'flex', justifyContent: 'space-between', gap: '0.5rem', ...estilo }}>
      <span>{rotulo}</span>
      <span style={{ textAlign: 'right' }}>{valor}</span>
    </div>
  );
  const pedirAnulacao = () => {
    // hotelAnularEstadia (hotelaria.js:484): com consumos lançados não se anula
    if (temConsumos) return void message.warning('A estadia tem consumos lançados. Remova-os do carrinho antes de anular, ou faça o check-out.');
    setAnular(true);
  };
  return (
    <div style={{ ...caixa, gap: '0.35rem', fontSize: '0.78rem', color: corTexto }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '0.2rem' }}>
        <span style={{ fontSize: '0.85rem', fontWeight: 800, color: '#b45309' }}>
          <IconeFa nome="bed" /> {quarto.nome}
        </span>
        <span style={{ fontSize: '0.7rem', fontWeight: 800, color: atrasado ? '#b91c1c' : '#b45309' }}>{atrasado ? 'SAÍDA ATRASADA' : 'OCUPADO'}</span>
      </div>
      {linha(
        'Hóspede',
        <>
          <strong>{estadia.nome_hospede}</strong> ({Number(estadia.numero_hospedes) || 1} pax)
        </>,
      )}
      {linha('Entrada', formatarDataHora(estadia.entrada_em))}
      {linha('Saída prevista', <strong>{formatarDataHora(estadia.saida_prevista_em)}</strong>, atrasado ? { color: '#b91c1c' } : {})}
      {linha(
        'Alojamento',
        <>
          {unidadeEstadia(estadia.modo, estadia.quantidade)} × {formatarKz(estadia.preco_unitario)} = <strong>{formatarCentimos(alojamento)} Kz</strong>
        </>,
      )}
      {linha('Consumos', `${formatarCentimos(consumos)} Kz`)}
      {linha(<strong>Total em aberto</strong>, <strong>{formatarCentimos(alojamento + consumos)} Kz</strong>, { borderTop: '1px dashed #d97706', paddingTop: '0.25rem', fontSize: '0.85rem' })}
      {estadia.observacoes && <div style={{ fontSize: '0.72rem' }}>Obs.: {estadia.observacoes}</div>}
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1.3fr', gap: '0.35rem', marginTop: '0.35rem' }}>
        <Button size="small" disabled={!a.alterar} onClick={() => setAlterar(true)} icon={<IconeFa nome="edit" />}>
          Alterar
        </Button>
        <Button size="small" disabled={!a.anular.visivel} onClick={pedirAnulacao} icon={<IconeFa nome="ban" />} style={{ color: '#b91c1c' }}>
          Anular
        </Button>
        <Button size="small" type="primary" disabled={!a.checkout} onClick={aoCheckout} icon={<IconeFa nome="sign-out-alt" />}>
          Check-out
        </Button>
      </div>
      <ModalAlterar estadia={alterar ? estadia : null} carregando={accao.isPending} aoFechar={() => setAlterar(false)} aoConfirmar={(dados) => accao.mutate({ metodo: 'put', url: `/pos/hotelaria/estadias/${estadia.id}`, dados })} />
      <ModalMotivo
        aberto={anular}
        titulo={`Anular o check-in do ${estadia.nome_quarto}`}
        textoOk="Anular"
        aviso="Só é possível sem consumos."
        carregando={accao.isPending}
        aoFechar={() => setAnular(false)}
        aoConfirmar={(motivo) => accao.mutate({ url: `/pos/hotelaria/estadias/${estadia.id}/anular`, dados: { motivo } })}
      />
    </div>
  );
}
