import { Card, Col, DatePicker, Flex, Row, Skeleton, Statistic, Table, Tabs } from 'antd';
import { useQuery } from '@tanstack/react-query';
import dayjs, { type Dayjs } from 'dayjs';
import { useEffect, useState } from 'react';
import { obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { useSessao } from '@/sessao/SessaoContexto';
import { notificarErro } from '@/utilitarios/erros';
import { dataApi, formatarData, formatarKz, formatarNumero } from '@/utilitarios/formatacao';
import { EstadoPOS } from '../comum/estados';
import { SeletorTerminal } from '../comum/Filtros';
import { BarraSessao, useTerminalDeTrabalho } from '../comum/SessaoTerminal';
import { Ordens } from './Ordens';
import { Recepcao } from './Recepcao';
import { Reclamacoes } from './Reclamacoes';
import { Tabelas } from './Tabelas';
import type { RelatorioLav } from './tipos';

/**
 * POS › Lavandaria e alfaiataria (ecrã pos_lavandaria): ordens de serviço (execução, orçamentos, recebimentos, facturação,
 * entrega, recibos), recepção de peças, danos e reclamações, tabelas e relatório (ADR-049).
 */
export default function Lavandaria() {
  const { pode } = useSessao();
  const trabalho = useTerminalDeTrabalho('LAVANDARIA');
  const [separador, setSeparador] = useState('ordens');

  return (
    <>
      <CabecalhoPagina titulo="Lavandaria e alfaiataria" accoes={<BarraSessao tipo="LAVANDARIA" {...trabalho} />} />
      {trabalho.carregando ? (
        <Skeleton active />
      ) : (
        <Tabs
          activeKey={separador}
          onChange={setSeparador}
          destroyInactiveTabPane
          items={[
            { key: 'ordens', label: 'Ordens de serviço', children: <Ordens terminal={trabalho.terminal} /> },
            ...(pode('lav_ordens') ? [{ key: 'recepcao', label: 'Recepção', children: <Recepcao terminal={trabalho.terminal} /> }] : []),
            { key: 'reclamacoes', label: 'Danos e reclamações', children: <Reclamacoes /> },
            { key: 'tabelas', label: 'Tabelas e definições', children: <Tabelas /> },
            ...(pode('pos_lavandaria_view', 'pos_relatorios_view', 'lav_ordens') ? [{ key: 'relatorio', label: 'Relatório', children: <Relatorio /> }] : []),
          ]}
        />
      )}
    </>
  );
}

function Relatorio() {
  const [periodo, setPeriodo] = useState<[Dayjs, Dayjs]>([dayjs().startOf('month'), dayjs()]);
  const [terminal, setTerminal] = useState<number>();
  const filtros = { de: dataApi(periodo[0]), ate: dataApi(periodo[1]), terminal_pos_id: terminal };
  const consulta = useQuery({ queryKey: ['pos', 'lavandaria', 'relatorio', filtros], queryFn: () => obter<RelatorioLav>('/pos/lavandaria/relatorio', filtros) });
  useEffect(() => {
    if (consulta.error) notificarErro(consulta.error, 'Erro ao carregar o relatório');
  }, [consulta.error]);
  const r = consulta.data;
  const kpi = (titulo: string, valor: number | string | undefined, kz = false) => (
    <Col xs={12} md={6} xl={4}>
      <Card size="small">
        <Statistic title={titulo} value={kz ? formatarKz(valor) : valor ?? 0} suffix={kz ? 'Kz' : undefined} valueStyle={{ fontSize: 18 }} />
      </Card>
    </Col>
  );
  return (
    <>
      <Flex gap={8} wrap style={{ marginBottom: 12 }}>
        <DatePicker.RangePicker format="DD/MM/YYYY" allowClear={false} value={periodo} onChange={(v) => v?.[0] && v[1] && setPeriodo([v[0], v[1]])} />
        <SeletorTerminal tipo="LAVANDARIA" value={terminal} onChange={setTerminal} />
      </Flex>
      {!r ? (
        <Skeleton active />
      ) : (
        <>
          <Row gutter={[12, 12]} style={{ marginBottom: 12 }}>
            {kpi('Ordens recebidas', r.resumo.recebidas)}
            {kpi('Etiquetas', r.resumo.etiquetas)}
            {kpi('Entregues', r.resumo.entregues)}
            {kpi('Facturado', r.resumo.facturado, true)}
            {kpi('Recebido', r.resumo.recebido, true)}
            {kpi('Anulados', r.resumo.anulados)}
          </Row>
          <Tabs
            size="small"
            items={[
              {
                key: 'docs',
                label: 'Documentos',
                children: (
                  <Table
                    size="small"
                    rowKey="numero"
                    dataSource={r.documentos}
                    columns={[
                      { title: 'Data', dataIndex: 'data', render: (v) => formatarData(v) },
                      { title: 'Documento', dataIndex: 'numero' },
                      { title: 'Ordem', dataIndex: 'ordem' },
                      { title: 'Cliente', dataIndex: 'cliente' },
                      { title: 'Base', dataIndex: 'base', align: 'right', render: (v) => formatarKz(v) },
                      { title: 'IVA', dataIndex: 'iva', align: 'right', render: (v) => formatarKz(v) },
                      { title: 'Total', dataIndex: 'total', align: 'right', render: (v) => formatarKz(v) },
                      { title: 'Estado', dataIndex: 'estado', render: (v) => <EstadoPOS estado={v} /> },
                    ]}
                  />
                ),
              },
              {
                key: 'servicos',
                label: 'Por serviço e peça',
                children: (
                  <Row gutter={16}>
                    <Col xs={24} lg={12}>
                      <Table size="small" pagination={false} rowKey="servico" dataSource={r.por_servico} columns={[{ title: 'Serviço', dataIndex: 'servico' }, { title: 'Qtd.', dataIndex: 'quantidade', align: 'right', render: (v) => formatarNumero(v) }, { title: 'Total', dataIndex: 'total', align: 'right', render: (v) => formatarKz(v) }]} />
                    </Col>
                    <Col xs={24} lg={12}>
                      <Table size="small" pagination={false} rowKey="peca" dataSource={r.por_peca} columns={[{ title: 'Peça', dataIndex: 'peca' }, { title: 'Qtd.', dataIndex: 'quantidade', align: 'right', render: (v) => formatarNumero(v) }, { title: 'Etiquetas', dataIndex: 'etiquetas', align: 'right' }, { title: 'Valor', dataIndex: 'valor', align: 'right', render: (v) => formatarKz(v) }]} />
                    </Col>
                  </Row>
                ),
              },
              {
                key: 'meios',
                label: 'Por meio e terminal',
                children: (
                  <Row gutter={16}>
                    <Col xs={24} lg={12}>
                      <Table size="small" pagination={false} rowKey="meio" dataSource={r.por_meio} columns={[{ title: 'Meio', dataIndex: 'meio' }, { title: 'N.º', dataIndex: 'numero', align: 'right' }, { title: 'Total', dataIndex: 'total', align: 'right', render: (v) => formatarKz(v) }]} />
                    </Col>
                    <Col xs={24} lg={12}>
                      <Table size="small" pagination={false} rowKey="terminal" dataSource={r.por_terminal} columns={[{ title: 'Terminal', dataIndex: 'terminal' }, { title: 'Ordens', dataIndex: 'ordens', align: 'right' }, { title: 'Facturado', dataIndex: 'facturado', align: 'right', render: (v) => formatarKz(v) }, { title: 'Recebido', dataIndex: 'recebido', align: 'right', render: (v) => formatarKz(v) }]} />
                    </Col>
                  </Row>
                ),
              },
              {
                key: 'dias',
                label: 'Por dia',
                children: (
                  <Table
                    size="small"
                    rowKey="dia"
                    dataSource={r.por_dia.filter((d) => d.recebidas || d.entregues || d.documentos)}
                    columns={[
                      { title: 'Dia', dataIndex: 'dia', render: (v) => formatarData(v) },
                      { title: 'Recebidas', dataIndex: 'recebidas', align: 'right' },
                      { title: 'Etiquetas', dataIndex: 'etiquetas', align: 'right' },
                      { title: 'Entregues', dataIndex: 'entregues', align: 'right' },
                      { title: 'Documentos', dataIndex: 'documentos', align: 'right' },
                      { title: 'Facturado', dataIndex: 'facturado', align: 'right', render: (v) => formatarKz(v) },
                      { title: 'Recebido', dataIndex: 'recebido', align: 'right', render: (v) => formatarKz(v) },
                    ]}
                  />
                ),
              },
            ]}
          />
        </>
      )}
    </>
  );
}
