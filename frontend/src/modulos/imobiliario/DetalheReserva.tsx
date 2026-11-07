import { Alert, Button, Descriptions, Drawer, Popconfirm, Skeleton, Space, Tag, Timeline, message } from 'antd';
import { useMutation, useQuery } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { larguraGaveta } from '@/componentes/responsivo';
import { notificarErro } from '@/utilitarios/erros';
import { apagarReserva, CHAVES_PK, facturarReserva, mudarEstadoReserva, obterReserva, type EstadoReserva, type FichaReserva, type ListasParque } from './apiParque';
import { ModalMotivo } from './ModaisContrato';
import { ModalCancelarReserva, ModalCaucaoReserva, ModalReceberReserva, ModalVistoriaReserva } from './ModaisReserva';
import { useRefrescar } from './hooks';
import { formatarKz } from './logica';
import { accoesDaReserva, COR_RESERVA, ROTULO_RESERVA } from './logicaParque';

export interface PermissoesReserva { marcar: boolean; aprovar: boolean; facturar: boolean; receber: boolean; vistoria: boolean; caucao: boolean; cancelar: boolean }

const EVENTOS: Record<string, string> = {
  CRIADA: 'Marcada', EDITADA: 'Alterada', ESTADO: 'Estado', FACTURADA: 'Facturada', RECEBIDO: 'Recebimento', VISTORIA: 'Vistoria', CANCELADA_FINANCEIRO: 'Cancelamento com reembolso',
};

/** Ficha da reserva: dados, painel «Dinheiro» (factura, recebido, por pagar, caução, reembolso, prazo), vistorias, trilho e as acções que o estado e as permissões deixam fazer. */
export function DetalheReserva({ id, listas, pode, aoEditar, aoFechar }: { id: number | null; listas: ListasParque | undefined; pode: PermissoesReserva; aoEditar: (r: FichaReserva) => void; aoFechar: () => void }) {
  const refrescar = useRefrescar();
  const q = useQuery({ queryKey: CHAVES_PK.reserva(id ?? 0), queryFn: () => obterReserva(id as number), enabled: id !== null });
  const [motivo, setMotivo] = useState<{ estado: EstadoReserva; titulo: string; okText: string; texto: string } | null>(null);
  const [cancelar, setCancelar] = useState<FichaReserva | null>(null);
  const [receber, setReceber] = useState<FichaReserva | null>(null);
  const [vistoria, setVistoria] = useState<FichaReserva | null>(null);
  const [caucao, setCaucao] = useState<FichaReserva | null>(null);
  useEffect(() => { if (q.error) notificarErro(q.error, 'Não foi possível abrir a reserva'); }, [q.error]);
  const r = q.data;
  const estado = useMutation({
    mutationFn: (v: { estado: EstadoReserva; motivo?: string }) => mudarEstadoReserva(id as number, v.estado, v.motivo),
    onError: (e) => notificarErro(e, 'Não foi possível mudar o estado da reserva'),
    onSuccess: async (x) => { message.success(x.mensagem); setMotivo(null); await refrescar(); },
  });
  const facturar = useMutation({
    mutationFn: () => facturarReserva(id as number), onError: (e) => notificarErro(e, 'Não foi possível facturar a reserva'),
    onSuccess: async (x) => { message.success(x.mensagem); await refrescar(); },
  });
  const apagar = useMutation({
    mutationFn: () => apagarReserva(id as number), onError: (e) => notificarErro(e, 'Não foi possível apagar a reserva'),
    onSuccess: async () => { message.success('Reserva apagada.'); await refrescar(); aoFechar(); },
  });
  const s = r?.situacao;
  const a = r && s ? accoesDaReserva(r.estado, r.espaco.regime === 'RESERVA_PAGA', r.valor, !!r.venda_id, r.interna, r.caucao_recebida > 0) : null;
  const fechada = !!r && ['REALIZADA', 'CANCELADA', 'FALTOU'].includes(r.estado);
  const caucaoPorFechar = !!s && s.caucao.em_poder > 0;
  const prazo = s?.prazo ? new Date(s.prazo).toLocaleString('pt-PT', { dateStyle: 'short', timeStyle: 'short' }) : null;

  return (
    <Drawer open={id !== null} onClose={aoFechar} size={larguraGaveta(760)} destroyOnHidden title={r ? <span>{r.espaco.nome} <Tag color={COR_RESERVA[r.estado]}>{ROTULO_RESERVA[r.estado]}</Tag></span> : 'Reserva'}>
      {!r || !s || !a ? <Skeleton active /> : (
        <>
          <p>{r.data} · {r.hora_inicio}–{r.hora_fim} · {r.reservante}</p>
          {s.caducou && <Alert type="warning" showIcon style={{ marginBottom: 12 }} title="O prazo de pagamento passou: a reserva cai na próxima verificação e o horário fica livre." />}
          {r.estado === 'AGUARDA_PAGAMENTO' && !s.caducou && prazo && <Alert type="info" showIcon style={{ marginBottom: 12 }} title={`O horário está preso até ${prazo}. Sem pagamento até lá, a reserva cai sozinha.`} />}
          {r.nc_pendente && <Alert type="warning" showIcon style={{ marginBottom: 12 }} title="Reembolso por tratar: anule o recibo e emita a nota de crédito em Vendas." />}
          {fechada && caucaoPorFechar && <Alert type="warning" showIcon style={{ marginBottom: 12 }} title={`Há ${formatarKz(s.caucao.em_poder)} de caução em poder: devolva-a ou retenha-a (com a vistoria de saída que o prova).`} />}
          <Descriptions size="small" column={1} bordered items={[
            { key: 'fr', label: 'Fracção', children: r.fraccao?.designacao ?? 'reserva interna' },
            ...(r.motivo ? [{ key: 'mo', label: 'Motivo', children: r.motivo }] : []),
            ...(r.pessoas ? [{ key: 'pe', label: 'Pessoas', children: `${r.pessoas}${r.espaco.capacidade ? ` de ${r.espaco.capacidade}` : ''}` }] : []),
            ...(r.contacto ? [{ key: 'co', label: 'Contacto', children: r.contacto }] : []),
            ...(r.divida_na_marcacao > 0 ? [{ key: 'di', label: 'Dívida na marcação', children: `${formatarKz(r.divida_na_marcacao)} — autorizada por ${r.autorizacao_por ?? '—'}: ${r.autorizacao_motivo ?? ''}` }] : []),
            ...(r.motivo_estado ? [{ key: 'me', label: 'Motivo do estado', children: r.motivo_estado }] : []),
          ]} />
          {s.paga && (
            <>
              <div className="imo-subtitulo">Dinheiro</div>
              <Descriptions size="small" column={1} bordered items={[
                { key: 'ut', label: 'Utilização', children: `${formatarKz(s.valor)}${r.valor_detalhe ? ` — ${r.valor_detalhe}` : ''}` },
                { key: 'fa', label: 'Factura', children: s.documento ? `${s.documento} (${formatarKz(s.facturado)})` : 'por emitir' },
                { key: 're', label: 'Recebido', children: formatarKz(s.recebido) },
                { key: 'pp', label: 'Por pagar', children: <span style={s.por_pagar > 0.009 ? { color: '#b91c1c' } : undefined}>{formatarKz(s.por_pagar)}</span> },
                { key: 'ca', label: 'Caução', children: `${formatarKz(s.caucao.recebida)} de ${formatarKz(s.caucao.devida)} — ${listas?.estados_caucao.find((x) => x.valor === s.caucao.estado)?.rotulo ?? s.caucao.estado}` },
                ...(s.caucao.retida > 0 ? [{ key: 'rt', label: 'Retido por danos', children: `${formatarKz(s.caucao.retida)}${r.caucao_motivo ? ` — ${r.caucao_motivo}` : ''}` }] : []),
                ...(s.caucao.devolvida > 0 ? [{ key: 'dv', label: 'Devolvido', children: formatarKz(s.caucao.devolvida) }] : []),
                ...(s.reembolso ? [{ key: 'rb', label: 'Reembolso do cancelamento', children: `${formatarKz(s.reembolso.previsto)} de ${formatarKz(s.reembolso.previsto + s.reembolso.retido)} (${s.reembolso.pct ?? '—'} %)` }] : []),
                { key: 'vi', label: 'Vistoria de entrada', children: s.vistoria_antes ? `${listas?.estados_vistoria.find((x) => x.valor === s.vistoria_antes?.estado)?.rotulo ?? s.vistoria_antes.estado} (${s.vistoria_antes.data})` : 'por fazer' },
                { key: 'vs', label: 'Vistoria de saída', children: s.vistoria_depois ? `${listas?.estados_vistoria.find((x) => x.valor === s.vistoria_depois?.estado)?.rotulo ?? s.vistoria_depois.estado} (${s.vistoria_depois.data})${s.vistoria_depois.observacoes ? ` — ${s.vistoria_depois.observacoes}` : ''}` : 'por fazer' },
              ]} />
            </>
          )}
          {r.espaco.regulamento && <><div className="imo-subtitulo">Regulamento</div><p style={{ whiteSpace: 'pre-wrap' }}>{r.espaco.regulamento}</p></>}
          <div className="imo-subtitulo">Acções</div>
          <Space wrap>
            {a.aprovar && pode.aprovar && <Button type="primary" onClick={() => estado.mutate({ estado: 'APROVADA' })} loading={estado.isPending}>Aprovar</Button>}
            {a.recusar && pode.aprovar && <Button danger onClick={() => setMotivo({ estado: 'RECUSADA', titulo: 'Recusar a reserva', okText: 'Recusar', texto: 'Diga o motivo — quem marcou vai perguntar porquê.' })}>Recusar</Button>}
            {a.realizada && pode.marcar && <Button onClick={() => estado.mutate({ estado: 'REALIZADA' })}>Realizada</Button>}
            {a.faltou && pode.marcar && <Button onClick={() => setMotivo({ estado: 'FALTOU', titulo: 'Não compareceu', okText: 'Registar', texto: 'Registe que ninguém apareceu. Só depois da data.' })}>Não compareceu</Button>}
            {a.facturar && pode.facturar && <Button type="primary" onClick={() => facturar.mutate()} loading={facturar.isPending}>Facturar</Button>}
            {a.receber && pode.receber && (s.por_pagar > 0.009 || s.caucao.por_receber > 0.009) && <Button type="primary" onClick={() => setReceber(r)}>Receber</Button>}
            {s.paga && pode.vistoria && !['PEDIDA', 'RECUSADA'].includes(r.estado) && <Button onClick={() => setVistoria(r)}>Vistoria</Button>}
            {s.paga && pode.caucao && caucaoPorFechar && <Button onClick={() => setCaucao(r)}>Fechar a caução</Button>}
            {a.editar && pode.marcar && <Button onClick={() => aoEditar(r)}>Alterar</Button>}
            {a.cancelar && pode.cancelar && <Button danger onClick={() => setCancelar(r)}>Cancelar</Button>}
            {a.apagar && pode.marcar && <Popconfirm title="Apagar deixa o registo sem rasto. Para manter o histórico, cancele-a em vez de a apagar." okText="Apagar" cancelText="Manter" okButtonProps={{ danger: true }} onConfirm={() => apagar.mutate()}><Button danger>Apagar</Button></Popconfirm>}
          </Space>
          <div className="imo-subtitulo">Histórico</div>
          <Timeline items={r.historico.map((h) => ({ key: h.id, content: <span>{EVENTOS[h.evento] ?? h.evento}{h.estado_para ? `: ${ROTULO_RESERVA[h.estado_para as EstadoReserva] ?? h.estado_para}` : ''}{h.motivo ? ` — ${h.motivo}` : ''} <small>({new Date(h.em).toLocaleString('pt-PT', { dateStyle: 'short', timeStyle: 'short' })})</small></span> }))} />
        </>
      )}
      <ModalMotivo opcional={motivo?.estado === 'FALTOU'} titulo={motivo?.titulo ?? ''} aberto={!!motivo} texto={motivo?.texto} okText={motivo?.okText ?? 'Confirmar'} carregando={estado.isPending} aoFechar={() => setMotivo(null)}
        aoConfirmar={(m) => estado.mutate({ estado: (motivo as { estado: EstadoReserva }).estado, motivo: m })} />
      <ModalCancelarReserva reserva={cancelar} aoFechar={() => setCancelar(null)} />
      <ModalReceberReserva reserva={receber} aoFechar={() => setReceber(null)} />
      <ModalVistoriaReserva reserva={vistoria} listas={listas} aoFechar={() => setVistoria(null)} />
      <ModalCaucaoReserva reserva={caucao} aoFechar={() => setCaucao(null)} />
    </Drawer>
  );
}
