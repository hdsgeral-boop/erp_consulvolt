import { Alert, Button, Descriptions, Drawer, Popconfirm, Skeleton, Space, Table, Tag, Timeline, message, Modal } from 'antd';
import { useMutation, useQuery } from '@tanstack/react-query';
import { useEffect, useRef, useState } from 'react';
import { larguraGaveta } from '@/componentes/responsivo';
import { notificarErro } from '@/utilitarios/erros';
import {
  apagarOrdem, aprovarOrdem, CHAVES_OB, concluirOrdem, cancelarOrdem, debitarOrdem, desfazerDebito, iniciarOrdem, obterOrdem, sincronizarOrdem, type FichaOrdem, type ListasObras, type Necessidade,
} from './apiObras';
import { useRefrescar } from './hooks';
import { formatarKz } from './logica';
import { accoesDaOrdem, COR_ITEM, COR_OBRA, custoDaOrdem, formatarQuantidade, ROTULO_ITEM, ROTULO_OBRA, ROTULO_TIPO_OBRA } from './logicaObras';
import { ModalMotivo } from './ModaisContrato';
import { ModalNecessidades, ModalOrdem, ModalPedirMaterial } from './ModaisObra';

const EVENTOS: Record<string, string> = {
  CRIADA: 'Aberta', EDITADA: 'Alterada', APROVADA: 'Aprovada', NECESSIDADES: 'Necessidades gravadas', MATERIAL_PEDIDO: 'Material pedido', RECEBIDO: 'Material recebido', EM_CURSO: 'Em curso',
  INICIADA: 'Começada', CONCLUIDA: 'Concluída', CANCELADA: 'Cancelada', DEBITADA: 'Debitada ao inquilino', DEBITO_DESFEITO: 'Débito desfeito', DE_VISTORIA: 'Aberta pelos danos de uma reserva',
};

export interface PermissoesOrdem { pode: (...p: string[]) => boolean }

const codigoDe = (e: unknown): string | undefined => (e as { codigo?: string } | null)?.codigo;

/**
 * Ficha da ordem de trabalho: dados, custo (real do material ou estimado), necessidades com a requisição e o pedido de compra, trilho e as acções que o estado e as permissões deixam fazer.
 * Quem aprova não regista entregas e quem debita ao inquilino não aprova rendas (pares de segregação do catálogo). Ao abrir, actualiza as entregas já feitas no armazém e nas Compras.
 */
export function DetalheOrdem({ id, listas, pode, aoFechar }: { id: number | null; listas: ListasObras | undefined; pode: PermissoesOrdem['pode']; aoFechar: () => void }) {
  const refrescar = useRefrescar();
  const q = useQuery({ queryKey: CHAVES_OB.ordem(id ?? 0), queryFn: () => obterOrdem(id as number), enabled: id !== null });
  const [editar, setEditar] = useState<FichaOrdem | null | undefined>(undefined);
  const [necessidades, setNecessidades] = useState<FichaOrdem | null>(null);
  const [pedir, setPedir] = useState<FichaOrdem | null>(null);
  const [cancelar, setCancelar] = useState(false);
  const o = q.data;
  useEffect(() => { if (q.error) notificarErro(q.error, 'Não foi possível abrir a ordem'); }, [q.error]);

  const sincronizar = useMutation({
    mutationFn: (_quieto: boolean) => sincronizarOrdem(id as number), onError: (e) => notificarErro(e, 'Não foi possível actualizar as entregas'),
    onSuccess: async (r, quieto) => { if (!quieto) message.success(r.mensagem); await refrescar(); },
  });
  const comPendentes = !!o && (o.necessidades_lista ?? []).some((n) => n.estado === 'REQUISITADO' || n.estado === 'PEDIDO_COMPRA') && pode('imo_obra_receber') && o.estado !== 'CONCLUIDA' && o.estado !== 'CANCELADA';
  const jaActualizada = useRef<number | null>(null);
  useEffect(() => {
    if (comPendentes && id !== null && jaActualizada.current !== id) { jaActualizada.current = id; sincronizar.mutate(true); }
  }, [comPendentes, id]); // eslint-disable-line react-hooks/exhaustive-deps

  const accao = <T,>(fn: () => Promise<{ mensagem: string } & T>, erro: string) => ({
    mutationFn: fn, onError: (e: unknown) => notificarErro(e, erro), onSuccess: async (r: { mensagem: string }) => { message.success(r.mensagem); await refrescar(); },
  });
  const aprovar = useMutation(accao(() => aprovarOrdem(id as number), 'Não foi possível aprovar a ordem'));
  const iniciar = useMutation(accao(() => iniciarOrdem(id as number), 'Não foi possível começar a ordem'));
  const desfazer = useMutation(accao(() => desfazerDebito(id as number), 'Não foi possível desfazer o débito'));
  const debitar = useMutation({
    mutationFn: () => debitarOrdem(id as number), onError: (e) => notificarErro(e, 'Não foi possível debitar a obra ao inquilino'),
    onSuccess: async (r) => { message.success(`${r.mensagem} (${formatarKz(r.dados.valor)}${r.dados.renda ? `, renda ${r.dados.renda.periodo}` : ''})`, 8); await refrescar(); },
  });
  const apagar = useMutation({
    mutationFn: () => apagarOrdem(id as number), onError: (e) => notificarErro(e, 'Não foi possível apagar a ordem'),
    onSuccess: async () => { message.success('Ordem apagada.'); await refrescar(); aoFechar(); },
  });
  const concluir = useMutation({
    mutationFn: (forcar: boolean) => concluirOrdem(id as number, forcar),
    onError: (e) => {
      if (codigoDe(e) === 'OBRA_PENDENTES') {
        Modal.confirm({ title: 'Concluir mesmo assim?', content: `${(e as Error).message} Concluir cancela as necessidades que faltam receber.`, okText: 'Concluir na mesma', cancelText: 'Voltar', onOk: () => concluir.mutate(true) });
        return;
      }
      notificarErro(e, 'Não foi possível concluir a ordem');
    },
    onSuccess: async (r) => { message.success(r.mensagem); await refrescar(); },
  });
  const cancelamento = useMutation({
    mutationFn: (motivo: string) => cancelarOrdem(id as number, motivo), onError: (e) => notificarErro(e, 'Não foi possível cancelar a ordem'),
    onSuccess: async (r) => { message.success(r.mensagem); setCancelar(false); await refrescar(); },
  });

  const a = o ? accoesDaOrdem(o, pode) : null;
  const custo = o ? custoDaOrdem(o) : null;
  const colunas = [
    { title: 'Artigo', dataIndex: 'nome', key: 'nome' },
    { title: 'Pedida', dataIndex: 'quantidade', key: 'q', align: 'right' as const, render: (v: string) => formatarQuantidade(v) },
    { title: 'Recebida', dataIndex: 'quantidade_recebida', key: 'r', align: 'right' as const, render: (v: string) => formatarQuantidade(v) },
    { title: 'Estado', dataIndex: 'estado', key: 'e', render: (v: Necessidade['estado']) => <Tag color={COR_ITEM[v]}>{ROTULO_ITEM[v]}</Tag> },
    { title: 'Onde foi', key: 'o', render: (_: unknown, n: Necessidade) => [
      Number(n.quantidade_armazem) > 0 ? `armazém ${formatarQuantidade(n.quantidade_armazem)}${n.requisicao_numero ? ` (${n.requisicao_numero})` : ''}` : null,
      Number(n.quantidade_compra) > 0 ? `compras ${formatarQuantidade(n.quantidade_compra)}${n.pedido_numero ? ` (${n.pedido_numero})` : ''}` : null].filter(Boolean).join(' · ') || '—' },
  ];

  return (
    <Drawer open={id !== null} onClose={aoFechar} size={larguraGaveta(860)} destroyOnHidden title={o ? <span>{o.numero} · {ROTULO_TIPO_OBRA[o.tipo]} <Tag color={COR_OBRA[o.estado]}>{ROTULO_OBRA[o.estado]}</Tag></span> : 'Ordem de trabalho'}>
      {!o || !a ? <Skeleton active /> : (
        <>
          <p>{o.imovel_codigo ? `${o.imovel_codigo} · ${o.imovel_nome}` : o.imovel_nome}{o.fracao_designacao ? ` — ${o.fracao_designacao}` : ' — o imóvel todo'}</p>
          {o.estado === 'CANCELADA' && <Alert type="error" showIcon style={{ marginBottom: 12 }} title={`Cancelada: ${o.motivo_cancelamento ?? ''}`} />}
          {!o.aprovada && o.estado !== 'CANCELADA' && o.estado !== 'CONCLUIDA' && <Alert type="warning" showIcon style={{ marginBottom: 12 }} title="Ordem por aprovar: só depois de aprovada se pede material ou se debita ao inquilino." />}
          {o.por_debitar && <Alert type="info" showIcon style={{ marginBottom: 12 }} title="Concluída e por debitar ao inquilino: o custo entra como encargo na próxima renda." />}
          {o.debitado_em && <Alert type="success" showIcon style={{ marginBottom: 12 }} title={`Debitada ao inquilino: ${formatarKz(o.debitado_valor)}.`} />}
          <Descriptions size="small" column={1} bordered items={[
            { key: 'd', label: 'Trabalho', children: o.descricao }, { key: 'p', label: 'Prioridade', children: listas?.prioridades.find((x) => x.chave === o.prioridade)?.rotulo ?? o.prioridade },
            { key: 'q', label: 'Quem paga', children: <span>{listas?.quem_paga.find((x) => x.chave === o.quem_paga)?.rotulo ?? o.quem_paga}{o.debitavel ? <Tag style={{ marginLeft: 8 }} color="cyan">a debitar</Tag> : null}</span> },
            { key: 'c', label: custo?.real ? 'Custo do material' : 'Custo estimado', children: <span>{formatarKz(custo?.valor ?? null)}{custo?.real && o.custo_estimado ? <small> (estimado {formatarKz(o.custo_estimado)})</small> : null}</span> },
            { key: 'da', label: 'Datas', children: `pedido ${o.data_pedido}${o.data_prevista ? ` · previsto ${o.data_prevista}` : ''}${o.data_conclusao ? ` · concluído ${o.data_conclusao}` : ''}` },
            ...(o.responsavel ? [{ key: 'r', label: 'Responsável', children: o.responsavel }] : []), ...(o.pedido_por ? [{ key: 'pp', label: 'Pedido por', children: o.pedido_por }] : []),
            { key: 'ap', label: 'Aprovação', children: o.aprovada ? `Aprovada por ${o.aprovada_por ?? '—'}` : 'Por aprovar' },
            ...(o.notas ? [{ key: 'n', label: 'Notas', children: o.notas }] : []),
          ]} />
          <div className="imo-subtitulo">Necessidades</div>
          <Table<Necessidade> size="small" rowKey="id" dataSource={o.necessidades_lista} columns={colunas} pagination={false} scroll={{ x: 'max-content' }} locale={{ emptyText: 'Sem necessidades. Use «Necessidades» para as listar.' }} />
          <div className="imo-subtitulo">Acções</div>
          <Space wrap>
            {a.editar && <Button onClick={() => setEditar(o)}>Editar</Button>}
            {a.editar && <Button onClick={() => setNecessidades(o)}>Necessidades</Button>}
            {a.aprovar && <Button type="primary" loading={aprovar.isPending} onClick={() => aprovar.mutate()}>Aprovar</Button>}
            {a.pedir && <Button type="primary" onClick={() => setPedir(o)}>{`Pedir material (${o.por_pedir} por pedir)`}</Button>}
            {a.receber && <Button loading={sincronizar.isPending} onClick={() => sincronizar.mutate(false)}>Registar entregas</Button>}
            {a.iniciar && <Button loading={iniciar.isPending} onClick={() => iniciar.mutate()}>Começar</Button>}
            {a.concluir && <Button type="primary" loading={concluir.isPending} onClick={() => concluir.mutate(false)}>Concluir</Button>}
            {a.debitar && <Button type="primary" loading={debitar.isPending} onClick={() => debitar.mutate()}>Debitar ao inquilino</Button>}
            {a.desfazer && <Popconfirm title="Desfazer o débito retira o encargo da renda (só enquanto a renda não for facturada)." okText="Desfazer" cancelText="Manter" onConfirm={() => desfazer.mutate()}><Button>Desfazer o débito</Button></Popconfirm>}
            {a.cancelar && <Button danger onClick={() => setCancelar(true)}>Cancelar a ordem</Button>}
            {a.apagar && <Popconfirm title="Apagar a ordem? Se já tem trabalho feito, cancele-a em vez de a apagar." okText="Apagar" cancelText="Manter" okButtonProps={{ danger: true }} onConfirm={() => apagar.mutate()}><Button danger>Apagar</Button></Popconfirm>}
          </Space>
          <div className="imo-subtitulo">Histórico</div>
          <Timeline items={o.historico.map((h, i) => ({ key: i, content: <span>{EVENTOS[h.evento] ?? h.evento}{h.motivo ? ` — ${h.motivo}` : ''} <small>({new Date(h.em).toLocaleString('pt-PT', { dateStyle: 'short', timeStyle: 'short' })})</small></span> }))} />
        </>
      )}
      <ModalOrdem ordem={editar} listas={listas} aoFechar={() => setEditar(undefined)} />
      <ModalNecessidades ordem={necessidades} aoFechar={() => setNecessidades(null)} />
      <ModalPedirMaterial ordem={pedir} aoFechar={() => setPedir(null)} />
      <ModalMotivo titulo="Cancelar a ordem" aberto={cancelar} texto="Diga porque cancela a ordem. Fica no registo." okText="Cancelar a ordem" carregando={cancelamento.isPending} aoFechar={() => setCancelar(false)} aoConfirmar={(m) => cancelamento.mutate(m)} />
    </Drawer>
  );
}
