import { Card, Col, DatePicker, Row, Skeleton, Statistic, Table, Tabs } from 'antd';
import { useQuery } from '@tanstack/react-query';
import dayjs, { type Dayjs } from 'dayjs';
import { useEffect, useState } from 'react';
import { obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { BotoesExportar, pares, tabelaHtml } from '@/componentes/impressao';
import { BarraFiltros, scrollTabela, useEcraPequeno } from '@/componentes/responsivo';
import { useSessao } from '@/sessao/SessaoContexto';
import { notificarErro } from '@/utilitarios/erros';
import { dataApi, formatarData, formatarKz, formatarNumero } from '@/utilitarios/formatacao';
import { EstadoPOS } from '../comum/estados';
import { rotuloEstadoPOS } from '../comum/estados';
import { SeletorTerminal, useFiltroTerminal } from '../comum/Filtros';
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
          destroyOnHidden
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
  const filtroTerminal = useFiltroTerminal(terminal);
  const tamanho = useEcraPequeno() ? 'small' : 'middle';
  const kpi = (titulo: string, valor: number | string | undefined, kz = false) => (
    <Col xs={12} md={6} xl={4}>
      <Card size="small">
        <Statistic title={titulo} value={kz ? formatarKz(valor) : valor ?? 0} suffix={kz ? 'Kz' : undefined} valueStyle={{ fontSize: 18 }} />
      </Card>
    </Col>
  );
  return (
    <>
      <BarraFiltros
        accoes={
          <BotoesExportar
            tamanho="small"
            desactivado={!r}
            obterPedido={() =>
              r && {
                titulo: 'Relatório da lavandaria',
                periodo: `${periodo[0].format('DD/MM/YYYY')} a ${periodo[1].format('DD/MM/YYYY')}`,
                filtros: [filtroTerminal],
                conteudo: documentoRelatorioLav(r),
              }
            }
          />
        }
      >
        <DatePicker.RangePicker format="DD/MM/YYYY" allowClear={false} value={periodo} onChange={(v) => v?.[0] && v[1] && setPeriodo([v[0], v[1]])} />
        <SeletorTerminal tipo="LAVANDARIA" value={terminal} onChange={setTerminal} />
      </BarraFiltros>
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
                    size={tamanho}
                    scroll={scrollTabela()}
                    rowKey="numero"
                    dataSource={r.documentos}
                    columns={[
                      { title: 'Data', dataIndex: 'data', render: (v) => formatarData(v) },
                      { title: 'Documento', dataIndex: 'numero' },
                      { title: 'Ordem', dataIndex: 'ordem', responsive: ['md'] },
                      { title: 'Cliente', dataIndex: 'cliente' },
                      { title: 'Base', dataIndex: 'base', align: 'right', render: (v) => formatarKz(v), responsive: ['md'] },
                      { title: 'IVA', dataIndex: 'iva', align: 'right', render: (v) => formatarKz(v), responsive: ['md'] },
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
                  <Row gutter={[16, 16]}>
                    <Col xs={24} lg={12}>
                      <Table size="small" scroll={scrollTabela()} pagination={false} rowKey="servico" dataSource={r.por_servico} columns={[{ title: 'Serviço', dataIndex: 'servico' }, { title: 'Qtd.', dataIndex: 'quantidade', align: 'right', render: (v) => formatarNumero(v) }, { title: 'Total', dataIndex: 'total', align: 'right', render: (v) => formatarKz(v) }]} />
                    </Col>
                    <Col xs={24} lg={12}>
                      <Table size="small" scroll={scrollTabela()} pagination={false} rowKey="peca" dataSource={r.por_peca} columns={[{ title: 'Peça', dataIndex: 'peca' }, { title: 'Qtd.', dataIndex: 'quantidade', align: 'right', render: (v) => formatarNumero(v) }, { title: 'Etiquetas', dataIndex: 'etiquetas', align: 'right' }, { title: 'Valor', dataIndex: 'valor', align: 'right', render: (v) => formatarKz(v) }]} />
                    </Col>
                  </Row>
                ),
              },
              {
                key: 'meios',
                label: 'Por meio e terminal',
                children: (
                  <Row gutter={[16, 16]}>
                    <Col xs={24} lg={12}>
                      <Table size="small" scroll={scrollTabela()} pagination={false} rowKey="meio" dataSource={r.por_meio} columns={[{ title: 'Meio', dataIndex: 'meio' }, { title: 'N.º', dataIndex: 'numero', align: 'right' }, { title: 'Total', dataIndex: 'total', align: 'right', render: (v) => formatarKz(v) }]} />
                    </Col>
                    <Col xs={24} lg={12}>
                      <Table size="small" scroll={scrollTabela()} pagination={false} rowKey="terminal" dataSource={r.por_terminal} columns={[{ title: 'Terminal', dataIndex: 'terminal' }, { title: 'Ordens', dataIndex: 'ordens', align: 'right' }, { title: 'Facturado', dataIndex: 'facturado', align: 'right', render: (v) => formatarKz(v) }, { title: 'Recebido', dataIndex: 'recebido', align: 'right', render: (v) => formatarKz(v) }]} />
                    </Col>
                  </Row>
                ),
              },
              {
                key: 'dias',
                label: 'Por dia',
                children: (
                  <Table
                    size={tamanho}
                    scroll={scrollTabela()}
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

/** Relatório da lavandaria (período) para impressão: resumo, documentos, serviços, peças, meios, terminais e dias. */
export function documentoRelatorioLav(r: RelatorioLav): string {
  const n = (k: string) => r.resumo[k] ?? 0;
  const resumo = pares(
    [
      ['Ordens recebidas', n('recebidas')],
      ['Etiquetas', n('etiquetas')],
      ['Entregues', n('entregues')],
      ['Facturado', `${formatarKz(n('facturado'))} Kz`],
      ['Recebido', `${formatarKz(n('recebido'))} Kz`],
      ['Anulados', n('anulados')],
    ],
    3,
  );
  const docs = tabelaHtml({
    legenda: 'Documentos',
    linhas: r.documentos,
    totais: true,
    colunas: [
      { titulo: 'Data', valor: (d) => d.data, formato: 'data' },
      { titulo: 'Documento', valor: (d) => d.numero },
      { titulo: 'Ordem', valor: (d) => d.ordem },
      { titulo: 'Cliente', valor: (d) => d.cliente, quebrar: true },
      { titulo: 'Base', valor: (d) => d.base, formato: 'moeda', somar: true },
      { titulo: 'IVA', valor: (d) => d.iva, formato: 'moeda', somar: true },
      { titulo: 'Total', valor: (d) => d.total, formato: 'moeda', somar: true },
      { titulo: 'Estado', valor: (d) => (d.estado ? rotuloEstadoPOS(d.estado) : '') },
    ],
  });
  const servicos = tabelaHtml({
    legenda: 'Por serviço',
    linhas: r.por_servico,
    totais: true,
    colunas: [
      { titulo: 'Serviço', valor: (s) => s.servico },
      { titulo: 'Qtd.', valor: (s) => s.quantidade, formato: 'numero' },
      { titulo: 'Total', valor: (s) => s.total, formato: 'moeda', somar: true },
    ],
  });
  const pecas = tabelaHtml({
    legenda: 'Por peça',
    linhas: r.por_peca,
    totais: true,
    colunas: [
      { titulo: 'Peça', valor: (p) => p.peca },
      { titulo: 'Qtd.', valor: (p) => p.quantidade, formato: 'numero' },
      { titulo: 'Etiquetas', valor: (p) => p.etiquetas, formato: 'inteiro', somar: true },
      { titulo: 'Valor', valor: (p) => p.valor, formato: 'moeda', somar: true },
    ],
  });
  const meios = tabelaHtml({
    legenda: 'Por meio de pagamento',
    linhas: r.por_meio,
    totais: true,
    colunas: [
      { titulo: 'Meio', valor: (m) => m.meio },
      { titulo: 'N.º', valor: (m) => m.numero, formato: 'inteiro', somar: true },
      { titulo: 'Total', valor: (m) => m.total, formato: 'moeda', somar: true },
    ],
  });
  const terminais = tabelaHtml({
    legenda: 'Por terminal',
    linhas: r.por_terminal,
    totais: true,
    colunas: [
      { titulo: 'Terminal', valor: (t) => t.terminal },
      { titulo: 'Ordens', valor: (t) => t.ordens, formato: 'inteiro', somar: true },
      { titulo: 'Facturado', valor: (t) => t.facturado, formato: 'moeda', somar: true },
      { titulo: 'Recebido', valor: (t) => t.recebido, formato: 'moeda', somar: true },
    ],
  });
  const dias = tabelaHtml({
    legenda: 'Por dia',
    linhas: r.por_dia.filter((d) => d.recebidas || d.entregues || d.documentos),
    totais: true,
    colunas: [
      { titulo: 'Dia', valor: (d) => d.dia, formato: 'data' },
      { titulo: 'Recebidas', valor: (d) => d.recebidas, formato: 'inteiro', somar: true },
      { titulo: 'Etiquetas', valor: (d) => d.etiquetas, formato: 'inteiro', somar: true },
      { titulo: 'Entregues', valor: (d) => d.entregues, formato: 'inteiro', somar: true },
      { titulo: 'Documentos', valor: (d) => d.documentos, formato: 'inteiro', somar: true },
      { titulo: 'Facturado', valor: (d) => d.facturado, formato: 'moeda', somar: true },
      { titulo: 'Recebido', valor: (d) => d.recebido, formato: 'moeda', somar: true },
    ],
  });
  return `${resumo}${docs}${servicos}${pecas}${meios}${terminais}${dias}`;
}
