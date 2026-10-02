import { Card, Col, DatePicker, Flex, Input, Row, Select, Statistic, Table, Typography } from 'antd';
import { useQuery } from '@tanstack/react-query';
import type dayjs from 'dayjs';
import { useMemo, useState } from 'react';
import { obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { tabelaHtml } from '@/componentes/impressao';
import { BotaoCsv, ValorKz } from '@/modulos/contab/comum/Componentes';
import { somar } from '@/utilitarios/decimal';
import { contemTexto } from '@/modulos/compras/comum/lista';
import { SeletorProjecto } from '@/modulos/activos/comum/componentes';
import { dataApi, formatarData, formatarKz } from '@/utilitarios/formatacao';
import { EtiquetaProjectos, rotuloProjectos } from './comum/componentes';
import { rotuloRubrica } from './comum/regras';
import type { Extracto as DadosExtracto, MovimentoExtracto } from './comum/tipos';
import { scrollTabela } from '@/componentes/responsivo';

/** Projectos › Extracto analítico (ecrã projectos_extracto): custos, proveitos e compromissos por projecto, tarefa e rubrica. */
export default function Extracto() {
  const [projecto, setProjecto] = useState<number>();
  const [periodo, setPeriodo] = useState<[dayjs.Dayjs | null, dayjs.Dayjs | null] | null>(null);
  const [natureza, setNatureza] = useState<string>();
  const [texto, setTexto] = useState('');
  const inicio = dataApi(periodo?.[0]);
  const fim = dataApi(periodo?.[1]);
  const q = useQuery({ queryKey: ['projectos', 'extracto', projecto, inicio, fim], queryFn: () => obter<DadosExtracto>('/projetos/extracto', { projeto_id: projecto, inicio, fim }) });
  const linhas = useMemo(
    () => (q.data?.movimentos ?? []).filter((m) => (!natureza || m.natureza === natureza) && contemTexto(texto, m.documento, m.descricao, m.codigo_projeto, m.tarefa, m.rubrica)),
    [q.data, natureza, texto],
  );
  // chave estável por linha (o índice no rowKey está obsoleto no Ant Design 5)
  const linhasComChave = useMemo(() => linhas.map((m, i) => ({ ...m, _chave: `${m.fonte}-${m.fonte_id}-${i}` })), [linhas]);
  const t = q.data?.totais;
  const resultado = t ? (Number(t.proveitos) - Number(t.custos)).toFixed(2) : undefined;

  return (
    <>
      <CabecalhoPagina
        titulo="Extracto analítico"
        subtitulo="Razão analítico, vendas, facturas de fornecedor, autos e compromissos (encomendas por facturar)"
        impressaoDesactivada={!linhas.length}
        impressao={() => ({
          titulo: 'Extracto analítico de projectos',
          periodo: inicio || fim ? `${inicio ? formatarData(inicio) : '…'} a ${fim ? formatarData(fim) : '…'}` : undefined,
          filtros: [
            projecto ? `Projecto: ${linhas[0]?.codigo_projeto ?? `#${projecto}`}` : 'Todos os projectos',
            natureza && `Natureza: ${rotuloProjectos(natureza)}`,
            texto && `Pesquisa: ${texto}`,
            t && `Proveitos ${formatarKz(t.proveitos)} Kz · Custos ${formatarKz(t.custos)} Kz · Resultado ${formatarKz(resultado)} Kz · Compromissos ${formatarKz(t.compromissos)} Kz`,
          ],
          conteudo: tabelaHtml({
            colunas: [
              { titulo: 'Data', valor: (m: MovimentoExtracto) => m.data, formato: 'data' },
              { titulo: 'Projecto', valor: (m) => m.codigo_projeto },
              { titulo: 'Natureza', valor: (m) => rotuloProjectos(m.natureza) },
              { titulo: 'Rubrica', valor: (m) => rotuloRubrica(m.categoria ?? m.rubrica) },
              { titulo: 'Módulo', valor: (m) => m.modulo },
              { titulo: 'Documento', valor: (m) => [m.tipo_documento, m.documento].filter(Boolean).join(' · ') },
              { titulo: 'Descrição', valor: (m) => m.descricao, quebrar: true },
              { titulo: 'Tarefa', valor: (m) => m.tarefa ?? '' },
              { titulo: 'Valor (Kz)', valor: (m) => m.valor, formato: 'moeda', somar: !!natureza },
              { titulo: 'Pendente (Kz)', valor: (m) => m.valor_pendente, formato: 'moeda', somar: !!natureza },
            ],
            linhas,
            agrupar: natureza ? undefined : { chave: (m) => rotuloProjectos(m.natureza), subtotais: true },
            totais: natureza ? 'Total' : false,
          }),
        })}
      />
      <Row gutter={[16, 16]} style={{ marginBottom: 16 }}>
        <Col xs={12} md={6}><Card size="small"><Statistic title="Proveitos (Kz)" value={formatarKz(t?.proveitos)} valueStyle={{ color: '#389e0d' }} /></Card></Col>
        <Col xs={12} md={6}><Card size="small"><Statistic title="Custos (Kz)" value={formatarKz(t?.custos)} valueStyle={{ color: '#cf1322' }} /></Card></Col>
        <Col xs={12} md={6}><Card size="small"><Statistic title="Resultado (Kz)" value={formatarKz(resultado)} /></Card></Col>
        <Col xs={12} md={6}><Card size="small"><Statistic title="Compromissos (Kz)" value={formatarKz(t?.compromissos)} /></Card></Col>
      </Row>
      <Card>
        <Flex gap={8} wrap justify="space-between" style={{ marginBottom: 16 }}>
          <Flex gap={8} wrap>
            <SeletorProjecto allowClear value={projecto} onChange={setProjecto} style={{ width: 280, maxWidth: '100%' }} placeholder="Todos os projectos" />
            <DatePicker.RangePicker format="DD/MM/YYYY" value={periodo} onChange={(v) => setPeriodo(v)} />
            <Select placeholder="Natureza" allowClear value={natureza} onChange={setNatureza} style={{ width: 150 }}
              options={[{ value: 'CUSTO', label: 'Custos' }, { value: 'PROVEITO', label: 'Proveitos' }, { value: 'COMPROMISSO', label: 'Compromissos' }]} />
            <Input.Search placeholder="Documento, descrição, tarefa" allowClear onSearch={setTexto} style={{ width: 240, maxWidth: '100%' }} />
          </Flex>
          <BotaoCsv nome="extracto-projectos" linhas={linhas} colunas={[
            { titulo: 'Data', valor: (m) => formatarData(m.data) }, { titulo: 'Projecto', valor: (m) => m.codigo_projeto }, { titulo: 'Natureza', valor: (m) => m.natureza },
            { titulo: 'Rubrica', valor: (m) => rotuloRubrica(m.categoria ?? m.rubrica) }, { titulo: 'Módulo', valor: (m) => m.modulo }, { titulo: 'Tipo', valor: (m) => m.tipo_documento },
            { titulo: 'Documento', valor: (m) => m.documento }, { titulo: 'Descrição', valor: (m) => m.descricao }, { titulo: 'Tarefa', valor: (m) => m.tarefa }, { titulo: 'Milestone', valor: (m) => m.marco },
            { titulo: 'Valor', valor: (m) => m.valor, numerico: true }, { titulo: 'Pendente', valor: (m) => m.valor_pendente, numerico: true }, { titulo: 'Contabilizado', valor: (m) => m.contabilizado },
          ]} />
        </Flex>
        <Table<MovimentoExtracto & { _chave: string }>
          rowKey="_chave"
          size="small"
          loading={q.isFetching}
          dataSource={linhasComChave}
          scroll={scrollTabela()}
          pagination={{ defaultPageSize: 50, showSizeChanger: true, showTotal: (n) => `${n} movimento(s)` }}
          columns={[
            { title: 'Data', dataIndex: 'data', render: formatarData },
            { title: 'Projecto', dataIndex: 'codigo_projeto' },
            { title: 'Natureza', dataIndex: 'natureza', responsive: ['sm'], render: (v) => <EtiquetaProjectos valor={v} /> },
            { title: 'Rubrica', key: 'r', responsive: ['md'], render: (_, m) => rotuloRubrica(m.categoria ?? m.rubrica) },
            { title: 'Módulo', dataIndex: 'modulo', responsive: ['lg'] },
            { title: 'Documento', key: 'doc', render: (_, m) => [m.tipo_documento, m.documento].filter(Boolean).join(' · ') || '—' },
            { title: 'Descrição', dataIndex: 'descricao', ellipsis: true, width: 260 },
            { title: 'Tarefa', dataIndex: 'tarefa', render: (v) => v ?? '—' },
            { title: 'Valor (Kz)', dataIndex: 'valor', align: 'right', render: (v, m) => <Typography.Text type={m.natureza === 'CUSTO' ? 'danger' : m.natureza === 'PROVEITO' ? 'success' : undefined}>{formatarKz(v)}</Typography.Text> },
            { title: 'Pendente', dataIndex: 'valor_pendente', align: 'right', render: (v) => <ValorKz valor={v} discretoSeZero /> },
          ]}
          summary={() => linhas.length > 0 && (
            <Table.Summary.Row>
              <Table.Summary.Cell index={0} colSpan={8}><Typography.Text strong>Total mostrado {natureza ? '' : '(proveitos − custos não se somam aqui: filtre por natureza)'}</Typography.Text></Table.Summary.Cell>
              <Table.Summary.Cell index={8} align="right">{natureza ? <ValorKz valor={somar(linhas.map((m) => m.valor))} forte /> : null}</Table.Summary.Cell>
              <Table.Summary.Cell index={9} />
            </Table.Summary.Row>
          )}
        />
      </Card>
    </>
  );
}
