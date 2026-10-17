import { Button, Card, DatePicker, Modal, Tag, Typography } from 'antd';
import { PlusOutlined } from '@ant-design/icons';
import dayjs, { type Dayjs } from 'dayjs';
import { useState, type ReactNode } from 'react';
import { useNavigate } from 'react-router-dom';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { scrollTabela, useEcraPequeno } from '@/componentes/responsivo';
import type { ColunaApi } from '@/componentes/TabelaApi';
import { useSessao } from '@/sessao/SessaoContexto';
import { formatarDataHora } from '@/utilitarios/formatacao';
import type { PeriodoSalarial } from '../api';
import { EstadoTag } from './componentes';
import { useAccaoRh, useAvisarErro, usePeriodosSalariais } from './consultas';
import { pedidoTabela } from './impressao';

import { TabelaComModos } from '@/componentes/vistas';
/** Listagem dos períodos salariais (ecrãs Calcular e Processamentos); abrir um período só no Calcular. */
export function ListaPeriodos({ titulo, subtitulo, permitirAbrir, accoesExtra, antes }: {
  titulo: string;
  subtitulo: string;
  permitirAbrir?: boolean;
  accoesExtra?: ReactNode;
  /** Conteúdo antes da tabela; como função recebe `abrirPeriodo` (abre o diálogo «Abrir período salarial»). */
  antes?: ReactNode | ((abrirPeriodo: () => void) => ReactNode);
}) {
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

  const existentes = new Set((periodos.data ?? []).map((p) => p.mes_ano));
  /** Abre o diálogo no mês corrente ou, se já tiver período, no primeiro mês seguinte sem período. */
  const abrirDialogo = () => {
    let d = dayjs();
    for (let i = 0; i < 24 && existentes.has(d.format('MM/YYYY')); i++) d = d.add(1, 'month');
    setMes(d);
    setAbrir(true);
  };
  const pequeno = useEcraPequeno();
  const colunas: ColunaApi<PeriodoSalarial>[] = [
    { title: 'Mês', dataIndex: 'mes_ano', render: (v: string) => <strong>{v}</strong> },
    { title: 'Estado', dataIndex: 'estado', render: (e: string) => <EstadoTag estado={e} /> },
    { title: 'Contabilização', render: (_, p) => (p.contabilizado ? <Tag color="green">Contabilizado {p.numero_lan_contabilizacao ? `(${p.numero_lan_contabilizacao})` : ''}</Tag> : '—') },
    { title: 'Encerrado', responsive: ['lg'], render: (_, p) => (p.fechado_em ? `${formatarDataHora(p.fechado_em)} · ${p.fechado_por ?? ''}` : '—') },
    { title: 'Validado', responsive: ['md'], render: (_, p) => (p.validado_em ? `${formatarDataHora(p.validado_em)} · ${p.validado_por ?? ''}` : '—') },
    { title: 'Cálculo', dataIndex: 'modo_calculo', responsive: ['md'], render: (m: string | null) => (m === 'LEGADO' ? <Tag>Legado</Tag> : 'Actual') },
  ];

  return (
    <>
      <CabecalhoPagina
        titulo={titulo}
        subtitulo={subtitulo}
        accoes={<>{accoesExtra}{permitirAbrir && pode('calcular_folha') && <Button type="primary" icon={<PlusOutlined />} onClick={abrirDialogo}>Abrir período</Button>}</>}
        impressaoDesactivada={!periodos.data?.length}
        impressao={() => pedidoTabela({ titulo: `Períodos salariais — ${titulo}`, colunas, linhas: periodos.data ?? [] })}
      />
      {typeof antes === 'function' ? antes(abrirDialogo) : antes}
      <Card>
        <TabelaComModos<PeriodoSalarial> rowKey="id" size={pequeno ? 'small' : 'middle'} scroll={scrollTabela()} loading={periodos.isFetching} columns={colunas} dataSource={periodos.data ?? []}
          onRow={(r) => ({ onClick: () => navegar(String(r.id)), style: { cursor: 'pointer' } })} pagination={{ pageSize: 24, showTotal: (t) => `${t} período(s)` }} />
      </Card>
      <Modal title="Abrir período salarial" open={abrir} onCancel={() => setAbrir(false)} okText="Abrir" cancelText="Cancelar" confirmLoading={accao.isPending}
        okButtonProps={{ disabled: !mes || existentes.has(mes.format('MM/YYYY')) }} onOk={() => mes && accao.mutate({ metodo: 'post', url: '/rh/salarios/periodos', dados: { mes_ano: mes.format('MM/YYYY') } })}>
        <Typography.Paragraph type="secondary">O exercício contabilístico do mês tem de estar aberto. Só pode existir um processamento por mês (os meses que já têm período aparecem indisponíveis).</Typography.Paragraph>
        <DatePicker picker="month" format="MM/YYYY" value={mes} onChange={setMes} style={{ width: '100%' }} aria-label="Mês do novo período"
          disabledDate={(d) => existentes.has(d.format('MM/YYYY'))} />
        {mes && existentes.has(mes.format('MM/YYYY')) && <Typography.Text type="warning">Já existe o período {mes.format('MM/YYYY')}: escolha-o na lista.</Typography.Text>}
      </Modal>
    </>
  );
}

