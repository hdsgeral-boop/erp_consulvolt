import { Button, Card, DatePicker, Modal, Table, Tag, Typography } from 'antd';
import { PlusOutlined } from '@ant-design/icons';
import type { ColumnsType } from 'antd/es/table';
import dayjs, { type Dayjs } from 'dayjs';
import { useState, type ReactNode } from 'react';
import { useNavigate } from 'react-router-dom';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { useSessao } from '@/sessao/SessaoContexto';
import { formatarDataHora } from '@/utilitarios/formatacao';
import type { PeriodoSalarial } from '../api';
import { EstadoTag } from './componentes';
import { useAccaoRh, useAvisarErro, usePeriodosSalariais } from './consultas';

/** Listagem dos períodos salariais (ecrãs Calcular e Processamentos); abrir um período só no Calcular. */
export function ListaPeriodos({ titulo, subtitulo, permitirAbrir, accoesExtra }: { titulo: string; subtitulo: string; permitirAbrir?: boolean; accoesExtra?: ReactNode }) {
  const navegar = useNavigate();
  const { pode } = useSessao();
  const periodos = usePeriodosSalariais();
  useAvisarErro(periodos.error);
  const [abrir, setAbrir] = useState(false);
  const [mes, setMes] = useState<Dayjs | null>(dayjs());
  const accao = useAccaoRh<PeriodoSalarial>((p) => {
    setAbrir(false);
    navegar(String(p.id));
  });

  const colunas: ColumnsType<PeriodoSalarial> = [
    { title: 'Mês', dataIndex: 'mes_ano', render: (v: string) => <strong>{v}</strong> },
    { title: 'Estado', dataIndex: 'estado', render: (e: string) => <EstadoTag estado={e} /> },
    { title: 'Contabilização', render: (_, p) => (p.contabilizado ? <Tag color="green">Contabilizado {p.numero_lan_contabilizacao ? `(${p.numero_lan_contabilizacao})` : ''}</Tag> : '—') },
    { title: 'Encerrado', render: (_, p) => (p.fechado_em ? `${formatarDataHora(p.fechado_em)} · ${p.fechado_por ?? ''}` : '—') },
    { title: 'Validado', render: (_, p) => (p.validado_em ? `${formatarDataHora(p.validado_em)} · ${p.validado_por ?? ''}` : '—') },
    { title: 'Cálculo', dataIndex: 'modo_calculo', render: (m: string | null) => (m === 'LEGADO' ? <Tag>Legado</Tag> : 'Actual') },
  ];

  return (
    <>
      <CabecalhoPagina
        titulo={titulo}
        subtitulo={subtitulo}
        accoes={<>{accoesExtra}{permitirAbrir && pode('calcular_folha') && <Button type="primary" icon={<PlusOutlined />} onClick={() => setAbrir(true)}>Abrir período</Button>}</>}
      />
      <Card>
        <Table<PeriodoSalarial> rowKey="id" size="middle" loading={periodos.isFetching} columns={colunas} dataSource={periodos.data ?? []}
          onRow={(r) => ({ onClick: () => navegar(String(r.id)), style: { cursor: 'pointer' } })} pagination={{ pageSize: 24, showTotal: (t) => `${t} período(s)` }} />
      </Card>
      <Modal title="Abrir período salarial" open={abrir} onCancel={() => setAbrir(false)} okText="Abrir" cancelText="Cancelar" confirmLoading={accao.isPending}
        okButtonProps={{ disabled: !mes }} onOk={() => mes && accao.mutate({ metodo: 'post', url: '/rh/salarios/periodos', dados: { mes_ano: mes.format('MM/YYYY') } })}>
        <Typography.Paragraph type="secondary">O exercício contabilístico do mês tem de estar aberto. Só pode existir um processamento por mês.</Typography.Paragraph>
        <DatePicker picker="month" format="MM/YYYY" value={mes} onChange={setMes} style={{ width: '100%' }} />
      </Modal>
    </>
  );
}

