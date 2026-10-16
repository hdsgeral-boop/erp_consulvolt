import { Button, Card, InputNumber, Modal, Select, Space, Table, Tag, Typography } from 'antd';
import { useQuery } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { obter } from '@/api/cliente';
import { ModalMotivo, useAccao } from '@/componentes/Accoes';
import { IconeFa } from '@/componentes/fluxos';
import { useSessao } from '@/sessao/SessaoContexto';
import { formatarData, formatarKz } from '@/utilitarios/formatacao';
import { useListaPaginada } from '../comum/paginacao';
import { useArmazens } from './comum';
import { quantidade } from './regras';
import type { DetalheRequisicao, LinhaRequisicao, Requisicao } from './tipos';

const CORES: Record<string, string> = { PENDENTE: 'gold', PARCIAL: 'blue', SATISFEITA: 'green', CANCELADA: 'red' };

/** Requisições ao armazém (areaRequisicoes, js/activos_manutencao_ui.js:691-745): entregar ou cancelar. */
export function Requisicoes() {
  const { pode } = useSessao();
  const requisitar = pode('activos_manut_requisitar');
  const [estado, setEstado] = useState<string>();
  const [entregar, setEntregar] = useState<number | null>(null);
  const [cancelar, setCancelar] = useState<Requisicao | null>(null);
  const q = useListaPaginada<Requisicao>(['activos', 'manutencao', 'requisicoes'], '/ativos/manutencao/requisicoes', { estado });
  const accao = useAccao({ invalidar: [['activos']], aoSucesso: () => setCancelar(null) });
  return (
    <>
      <Card style={{ marginBottom: 16 }} title={<span><IconeFa nome="dolly" /> Requisições ao armazém</span>}
        extra={<Select allowClear placeholder="Estado" value={estado} onChange={setEstado} style={{ width: 160 }}
          options={['PENDENTE', 'PARCIAL', 'SATISFEITA', 'CANCELADA'].map((e) => ({ value: e, label: e }))} />}>
        <Typography.Paragraph type="secondary" style={{ margin: 0 }}>Material pedido pelas ordens de manutenção. A entrega dá saída do stock e soma o custo à ordem.</Typography.Paragraph>
      </Card>
      <Card>
        <Table<Requisicao> rowKey="id" size="middle" loading={q.isFetching} dataSource={q.itens} pagination={q.paginacao} scroll={{ x: 'max-content' }}
          locale={{ emptyText: 'Ainda não há requisições. Saem das ordens de manutenção.' }}
          columns={[
            { title: 'Requisição', dataIndex: 'numero', render: (v) => <strong>{v}</strong> },
            { title: 'Data', dataIndex: 'data', render: formatarData },
            { title: 'Ordem', key: 'o', render: (_, r) => r.ordem_manutencao?.numero ?? '—' },
            { title: 'Activo', key: 'a', render: (_, r) => `${r.ativo_imobilizado?.codigo ?? ''} — ${r.ativo_imobilizado?.descricao ?? ''}` },
            { title: 'Requerente', dataIndex: 'requerente', render: (v) => v || '—' },
            { title: 'Linhas', dataIndex: 'linhas_count', align: 'center' },
            { title: 'Estado', dataIndex: 'estado', render: (v) => <Tag color={CORES[v] ?? 'default'}>{v}</Tag> },
            { title: 'Acções', key: 'x', align: 'right', render: (_, r) => (r.estado === 'PENDENTE' || r.estado === 'PARCIAL') && requisitar ? (
              <Space size={4}>
                <Button size="small" type="primary" style={{ background: '#10b981' }} icon={<IconeFa nome="dolly" />} onClick={() => setEntregar(r.id)}>Entregar</Button>
                <Button size="small" danger title="Cancelar" icon={<IconeFa nome="ban" />} disabled={r.estado === 'PARCIAL'} onClick={() => setCancelar(r)} />
              </Space>
            ) : <IconeFa nome="check-double" style={{ color: '#10b981' }} /> },
          ]} />
      </Card>
      <ModalEntrega id={entregar} aoFechar={() => setEntregar(null)} />
      <ModalMotivo aberto={!!cancelar} titulo={`Cancelar a requisição ${cancelar?.numero ?? ''}`} textoOk="Cancelar requisição" carregando={accao.isPending}
        aviso="Fica registado e as necessidades voltam a «por pedir»."
        aoConfirmar={(motivo) => cancelar && accao.mutate({ url: `/ativos/manutencao/requisicoes/${cancelar.id}/cancelar`, dados: { motivo } })}
        aoFechar={() => setCancelar(null)} />
    </>
  );
}

/** Entrega do material (formularioEntrega, UI :747-803): armazém de saída, stock disponível e quantidade a entregar por linha. */
function ModalEntrega({ id, aoFechar }: { id: number | null; aoFechar: () => void }) {
  const armazens = useArmazens();
  const [armazem, setArmazem] = useState<number>();
  const [quantidades, setQuantidades] = useState<Record<number, number>>({});
  const q = useQuery({ queryKey: ['activos', 'manutencao', 'requisicao', id, armazem], enabled: !!id,
    queryFn: () => obter<DetalheRequisicao>(`/ativos/manutencao/requisicoes/${id}`, { armazem_id: armazem }) });
  const accao = useAccao({ invalidar: [['activos']], aoSucesso: aoFechar });
  const r = q.data;
  useEffect(() => { if (!id) { setArmazem(undefined); setQuantidades({}); } }, [id]);
  useEffect(() => { if (r && armazem === undefined && r.armazem_consulta_id) setArmazem(r.armazem_consulta_id); }, [r, armazem]);
  // por omissão entrega o que falta, limitado ao stock do armazém (UI :772)
  useEffect(() => {
    if (r) setQuantidades(Object.fromEntries(r.linhas.map((l) => [l.id, Math.max(0, Math.min(Number(l.falta), Number(l.stock)))])));
  }, [r]);
  return (
    <Modal title={`Entregar o material — ${r?.numero ?? ''}`} open={!!id} onCancel={aoFechar} width={800} destroyOnHidden okText="Entregar" cancelText="Cancelar"
      okButtonProps={{ style: { background: '#10b981' }, disabled: !armazem }} confirmLoading={accao.isPending}
      onOk={() => r && accao.mutate({ url: `/ativos/manutencao/requisicoes/${r.id}/entregar`, dados: { armazem_id: armazem, entregas: quantidades } })}>
      <Typography.Paragraph type="secondary">A entrega dá saída do stock, regista o movimento no armazém e soma o custo à ordem de manutenção.</Typography.Paragraph>
      <Space style={{ marginBottom: 8 }}><span>Armazém de saída</span>
        <Select<number> style={{ width: 280 }} value={armazem} onChange={setArmazem} options={(armazens.data ?? []).map((w) => ({ value: w.id, label: w.nome }))} /></Space>
      <Table<LinhaRequisicao> rowKey="id" size="small" pagination={false} loading={q.isFetching} dataSource={r?.linhas ?? []}
        columns={[
          { title: 'Artigo', dataIndex: 'descricao' },
          { title: 'Pedido', dataIndex: 'quantidade', align: 'right', render: quantidade },
          { title: 'Entregue', dataIndex: 'quantidade_entregue', align: 'right', render: (v) => (Number(v) ? quantidade(v) : '—') },
          { title: 'Em stock', dataIndex: 'stock', align: 'right', render: quantidade },
          { title: 'Custo', dataIndex: 'custo', align: 'right', render: (v) => (Number(v) ? formatarKz(v) : '—') },
          { title: 'A entregar', key: 'q', align: 'right', render: (_, l) => (
            <InputNumber aria-label={`A entregar ${l.descricao}`} size="small" min={0} max={Number(l.falta)} value={quantidades[l.id]} disabled={l.estado === 'ENTREGUE' || l.estado === 'CANCELADA'}
              onChange={(v) => setQuantidades((s) => ({ ...s, [l.id]: Number(v ?? 0) }))} />
          ) },
        ]} />
    </Modal>
  );
}
