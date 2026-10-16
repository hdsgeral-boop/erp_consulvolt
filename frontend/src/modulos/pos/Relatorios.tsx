import { Button, Card, Col, DatePicker, Empty, Flex, Row, Skeleton, Table, Tabs } from 'antd';
import { DownloadOutlined, PrinterOutlined } from '@ant-design/icons';
import { useSessao } from '@/sessao/SessaoContexto';
import { useQuery } from '@tanstack/react-query';
import type { Dayjs } from 'dayjs';
import { useEffect, useState } from 'react';
import { obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { BarraFiltros, scrollTabela, useEcraPequeno } from '@/componentes/responsivo';
import { notificarErro } from '@/utilitarios/erros';
import { dataApi, formatarDataHora, formatarKz, formatarNumero } from '@/utilitarios/formatacao';
import { descarregarCsv, gerarCsv } from '@/utilitarios/csv';
import { useTerminais } from './comum/dados';
import { CartaoKpi, GrelhaKpi } from '@/componentes/vistas';
import { GraficoDonut } from '@/componentes/graficos/Graficos';
import { DetalheSessao, ValorDesvio } from './comum/DetalheSessao';
import { Seccao } from './contas/Seccao';
import { textoDesvio } from './contas/formatos';
import { documentoRelatorios } from './comum/documentos';
import { EstadoPOS } from './comum/estados';
import { SeletorTerminal } from './comum/Filtros';
import type { PainelRelatorios, SessaoPOS } from './comum/tipos';
import { htmlRelatorioSessao, lerPreferencias, reimprimir, useCabecalhoTalao } from './comum/impressao';

type Z = PainelRelatorios['zs'][number];

/** «Terminal · operador» como no legado (pos_gestao.js:1123). */
export const terminalOperador = (z: Pick<Z, 'codigo_terminal' | 'nome_operador'>) => `${z.codigo_terminal ?? '—'} · ${z.nome_operador ?? '—'}`;

/** Estado do desvio no resumo (estadoDesvio, pos_gestao.js:1112). */
function EstadoDesvioResumo({ estado }: { estado: string | null | undefined }) {
  if (estado === 'PENDENTE') return <span style={{ color: '#b45309', fontWeight: 700 }}>Por deliberar</span>;
  return <span style={{ color: '#059669' }}>{estado === 'DELIBERADO' ? 'Deliberado' : 'OK'}</span>;
}

/**
 * POS › Relatórios (ecrã pos_relatorios) como no sistema anterior (renderRelatoriosPOS, pos_gestao.js:1094-1127): abre SEM
 * limite de período (todos os dados, como o antigo), cartões KPI com a barra de cor por baixo e, lado a lado, «Por meio de
 * pagamento» e «Fechos de caixa (relatórios Z)»; o detalhe (Z completos, por produto, por terminal e operador, diferenças
 * de TPA) fica nos separadores. Os valores das vendas vêm de total_bruto das FR (nunca de valor_pago, vazio nas FR do
 * POS migradas). GET /pos/relatorios.
 */
/** Cores dos meios de pagamento no donut do legado (ui_sales.js:4572). */
const CORES_MEIO: Record<string, string> = { NUMERARIO: '#f59e0b', TPA: '#10b981', TRANSFERENCIA: '#3b82f6' };

export default function Relatorios() {
  // sem período = todos os dados (3.2.1)
  const [periodo, setPeriodo] = useState<[Dayjs, Dayjs] | null>(null);
  const [terminal, setTerminal] = useState<number>();
  const [detalhe, setDetalhe] = useState<number | null>(null);
  const filtros = { data_inicio: periodo ? dataApi(periodo[0]) : undefined, data_fim: periodo ? dataApi(periodo[1]) : undefined, terminal_pos_id: terminal };
  const sufixo = periodo ? `${filtros.data_inicio}_${filtros.data_fim}` : 'todos';
  const consulta = useQuery({ queryKey: ['pos', 'relatorios', filtros], queryFn: () => obter<PainelRelatorios>('/pos/relatorios', filtros) });
  useEffect(() => {
    if (consulta.error) notificarErro(consulta.error, 'Erro ao carregar os relatórios do POS');
  }, [consulta.error]);
  const r = consulta.data;
  const tamanho = useEcraPequeno() ? 'small' : 'middle';
  const terminais = useTerminais();
  const escolhido = terminal ? terminais.data?.find((t) => t.id === terminal) : undefined;

  const { empresa } = useSessao();
  const cabecalho = useCabecalhoTalao();
  // «Reemitir Z» por linha, como o legado (window.reprintZReport, pos_gestao.js:677-682)
  const reemitirZ = (id: number) =>
    obter<SessaoPOS>(`/pos/sessoes/${id}`)
      .then((s) => reimprimir(htmlRelatorioSessao(s, cabecalho(), lerPreferencias(empresa?.id)), lerPreferencias(empresa?.id)))
      .catch((e) => notificarErro(e, 'Não foi possível reemitir o Z'));

  const exportarZ = () =>
    r &&
    descarregarCsv(
      `pos_fechos_z_${sufixo}`,
      gerarCsv<Z>(
        [
          { titulo: 'Z', valor: (z) => z.numero_z },
          { titulo: 'Terminal', valor: (z) => z.codigo_terminal },
          { titulo: 'Operador', valor: (z) => z.nome_operador },
          { titulo: 'Abertura', valor: (z) => formatarDataHora(z.aberto_em) },
          { titulo: 'Fecho', valor: (z) => formatarDataHora(z.fechado_em) },
          { titulo: 'Vendas', valor: (z) => z.numero_vendas, numerico: true },
          { titulo: 'Total', valor: (z) => z.total_vendas, numerico: true },
          { titulo: 'Esperado', valor: (z) => z.numerario_esperado, numerico: true },
          { titulo: 'Contado', valor: (z) => z.numerario_contado, numerico: true },
          { titulo: 'Desvio', valor: (z) => z.desvio, numerico: true },
          { titulo: 'Estado do desvio', valor: (z) => z.estado_desvio },
          { titulo: 'Integração', valor: (z) => z.estado_contabilizacao },
          { titulo: 'Prestação', valor: (z) => z.estado_liquidacao },
        ],
        r.zs,
      ),
    );

  const exportarProdutos = () =>
    r &&
    descarregarCsv(
      `pos_vendas_produto_${sufixo}`,
      gerarCsv(
        [
          { titulo: 'Código', valor: (p: PainelRelatorios['por_produto'][number]) => p.codigo },
          { titulo: 'Produto', valor: (p) => p.nome },
          { titulo: 'Quantidade', valor: (p) => p.quantidade, numerico: true },
          { titulo: 'Base', valor: (p) => p.total_liquido, numerico: true },
          { titulo: 'Total', valor: (p) => p.total_bruto, numerico: true },
        ],
        r.por_produto,
      ),
    );

  return (
    <>
      <CabecalhoPagina
        titulo="Relatórios POS"
        impressaoDesactivada={!r}
        impressao={() =>
          r && {
            titulo: 'Relatórios POS',
            periodo: periodo ? `${periodo[0].format('DD/MM/YYYY')} a ${periodo[1].format('DD/MM/YYYY')}` : 'Todos os dados',
            filtros: [`Terminal: ${escolhido ? `${escolhido.codigo} — ${escolhido.nome}` : 'Todos'}`],
            conteudo: documentoRelatorios(r),
          }
        }
      />
      <BarraFiltros>
        <DatePicker.RangePicker
          format="DD/MM/YYYY"
          allowClear
          placeholder={['Desde o início', 'Até hoje']}
          aria-label="Período"
          value={periodo}
          onChange={(v) => setPeriodo(v?.[0] && v[1] ? [v[0], v[1]] : null)}
        />
        <SeletorTerminal value={terminal} onChange={setTerminal} />
      </BarraFiltros>
      {!r ? (
        <Skeleton active />
      ) : (
        <>
          <GrelhaKpi larguraMinima={200} style={{ marginBottom: 16 }}>
            <CartaoKpi rotulo="Facturação POS" valor={formatarKz(r.kpis.facturacao)} unidade="Kz" cor="#7c3aed" />
            <CartaoKpi rotulo="Documentos" valor={r.kpis.documentos} cor="#3b82f6" />
            <CartaoKpi rotulo="Ticket médio" valor={formatarKz(r.kpis.ticket_medio)} unidade="Kz" cor="#10b981" />
            <CartaoKpi rotulo="Desvios de caixa" valor={textoDesvio(r.kpis.desvios)} unidade="Kz" cor={Number(r.kpis.desvios) < 0 ? '#ef4444' : '#10b981'} corValor={Number(r.kpis.desvios) < 0 ? '#b91c1c' : undefined} />
            <CartaoKpi rotulo="Sessões abertas" valor={r.kpis.sessoes_abertas} cor="#f59e0b" />
            <CartaoKpi rotulo="Desvios por deliberar" valor={r.kpis.desvios_por_deliberar} cor={r.kpis.desvios_por_deliberar ? '#ef4444' : '#10b981'} />
          </GrelhaKpi>
          <Row gutter={[16, 16]} style={{ marginBottom: 16 }}>
            <Col xs={24} lg={8}>
              <Seccao titulo="Divisão por métodos de pagamento" icone="credit-card" nivel={4} style={{ height: '100%', marginBottom: 0 }}>
                {r.por_meio.some((m) => Number(m.valor) > 0) && (
                  // como o legado (ui_sales.js:4566-4575): donut Numerário âmbar, Multicaixa verde, Transferência azul
                  <GraficoDonut monetario altura={240} rotulos={r.por_meio.map((m) => m.nome)}
                    series={[{ rotulo: 'Valor', valores: r.por_meio.map((m) => Number(m.valor)) }]}
                    coresCategorias={r.por_meio.map((m) => CORES_MEIO[m.tipo ?? ''] ?? '#94a3b8')} />
                )}
                {r.por_meio.length ? (
                  <table className="pos-resumo-meios" style={{ width: '100%', fontSize: '0.85rem', borderCollapse: 'collapse' }}>
                    <tbody>
                      {r.por_meio.map((m) => (
                        <tr key={`${m.tipo}-${m.nome}`} style={{ borderBottom: '1px solid #f1f5f9' }}>
                          <td style={{ padding: '4px 0' }}>{m.nome}</td>
                          <td style={{ textAlign: 'right', fontWeight: 700 }}>{formatarKz(m.valor)}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                ) : (
                  <span style={{ color: '#94a3b8' }}>Sem vendas.</span>
                )}
              </Seccao>
            </Col>
            <Col xs={24} lg={16}>
              <Seccao titulo="Fechos de caixa (relatórios Z)" icone="history" nivel={4} style={{ height: '100%', marginBottom: 0 }}>
                <Table<Z>
                  size="small"
                  rowKey="id"
                  dataSource={r.zs}
                  scroll={scrollTabela()}
                  pagination={{ pageSize: 10, hideOnSinglePage: true }}
                  locale={{ emptyText: 'Sem fechos registados.' }}
                  onRow={(z) => ({ onClick: () => setDetalhe(z.id), style: { cursor: 'pointer' } })}
                  columns={[
                    { title: 'Z', dataIndex: 'numero_z', render: (v) => <b>{v}</b> },
                    { title: 'Terminal · operador', key: 'terminal', render: (_, z) => terminalOperador(z) },
                    { title: 'Fecho', dataIndex: 'fechado_em', render: (v) => formatarDataHora(v) },
                    { title: 'Vendas', dataIndex: 'total_vendas', align: 'right', render: (v) => formatarKz(v) },
                    { title: 'Esperado', dataIndex: 'numerario_esperado', align: 'right', render: (v) => formatarKz(v), responsive: ['md'] },
                    { title: 'Contado', dataIndex: 'numerario_contado', align: 'right', render: (v) => formatarKz(v), responsive: ['md'] },
                    { title: 'Desvio', dataIndex: 'desvio', align: 'right', render: (v) => <ValorDesvio valor={v} /> },
                    { title: 'Estado', dataIndex: 'estado_desvio', render: (v) => <EstadoDesvioResumo estado={v} /> },
                    {
                      key: 'imprimir',
                      width: 44,
                      render: (_, z) => (
                        <Button
                          size="small"
                          title="Reemitir Z"
                          aria-label={`Reemitir ${z.numero_z}`}
                          icon={<PrinterOutlined />}
                          onClick={(e) => {
                            e.stopPropagation();
                            reemitirZ(z.id);
                          }}
                        />
                      ),
                    },
                  ]}
                />
              </Seccao>
            </Col>
          </Row>
          <Tabs
            items={[
              {
                key: 'z',
                label: `Fechos Z (${r.zs.length})`,
                children: (
                  <>
                    <Flex justify="end" style={{ marginBottom: 8 }}>
                      <Button icon={<DownloadOutlined />} disabled={!r.zs.length} onClick={exportarZ}>
                        CSV
                      </Button>
                    </Flex>
                    <Table<Z>
                      size={tamanho}
                      rowKey="id"
                      dataSource={r.zs}
                      scroll={scrollTabela()}
                      onRow={(z) => ({ onClick: () => setDetalhe(z.id), style: { cursor: 'pointer' } })}
                      columns={[
                        { title: 'Z', dataIndex: 'numero_z' },
                        { title: 'Terminal · operador', key: 'terminal', render: (_, z) => terminalOperador(z) },
                        { title: 'Fecho', dataIndex: 'fechado_em', render: (v) => formatarDataHora(v) },
                        { title: 'Vendas', dataIndex: 'numero_vendas', align: 'right' },
                        { title: 'Total', dataIndex: 'total_vendas', align: 'right', render: (v) => formatarKz(v) },
                        { title: 'Esperado', dataIndex: 'numerario_esperado', align: 'right', render: (v) => formatarKz(v), responsive: ['md'] },
                        { title: 'Contado', dataIndex: 'numerario_contado', align: 'right', render: (v) => formatarKz(v), responsive: ['md'] },
                        { title: 'Desvio', dataIndex: 'desvio', align: 'right', render: (v) => <ValorDesvio valor={v} /> },
                        { title: 'Desvio', dataIndex: 'estado_desvio', render: (v) => <EstadoPOS estado={v} /> },
                        { title: 'Integração', dataIndex: 'estado_contabilizacao', render: (v) => <EstadoPOS estado={v} /> },
                        { title: 'Prestação', dataIndex: 'estado_liquidacao', render: (v) => <EstadoPOS estado={v} /> },
                      ]}
                    />
                  </>
                ),
              },
              {
                key: 'meios',
                label: 'Por meio de pagamento',
                children: (
                  <Table
                    size={tamanho}
                    scroll={scrollTabela()}
                    pagination={false}
                    rowKey={(m) => `${m.tipo}-${m.nome}`}
                    dataSource={r.por_meio}
                    columns={[
                      { title: 'Meio', dataIndex: 'nome' },
                      { title: 'Natureza', dataIndex: 'tipo', render: (v) => (v ? <EstadoPOS estado={v} /> : 'Sem detalhe') },
                      { title: 'Operações', dataIndex: 'quantidade', align: 'right' },
                      { title: 'Valor', dataIndex: 'valor', align: 'right', render: (v) => formatarKz(v) },
                    ]}
                  />
                ),
              },
              {
                key: 'produtos',
                label: 'Por produto',
                children: (
                  <>
                    <Flex justify="end" style={{ marginBottom: 8 }}>
                      <Button icon={<DownloadOutlined />} disabled={!r.por_produto.length} onClick={exportarProdutos}>
                        CSV
                      </Button>
                    </Flex>
                    <Table
                      size={tamanho}
                      scroll={scrollTabela()}
                      rowKey="produto_id"
                      dataSource={r.por_produto}
                      columns={[
                        { title: 'Código', dataIndex: 'codigo', responsive: ['md'] },
                        { title: 'Produto', dataIndex: 'nome' },
                        { title: 'Quantidade', dataIndex: 'quantidade', align: 'right', render: (v) => formatarNumero(v) },
                        { title: 'Base', dataIndex: 'total_liquido', align: 'right', render: (v) => formatarKz(v), responsive: ['md'] },
                        { title: 'Total', dataIndex: 'total_bruto', align: 'right', render: (v) => formatarKz(v) },
                      ]}
                    />
                  </>
                ),
              },
              {
                key: 'terminais',
                label: 'Por terminal e operador',
                children: (
                  <Row gutter={[16, 16]}>
                    <Col xs={24} lg={12}>
                      <Card size="small" title="Por terminal">
                        <Table
                          size={tamanho}
                          scroll={scrollTabela()}
                          pagination={false}
                          rowKey="terminal_pos_id"
                          dataSource={r.por_terminal}
                          columns={[
                            { title: 'Terminal', render: (_, t) => `${t.codigo_terminal} — ${t.nome_terminal}` },
                            { title: 'Documentos', dataIndex: 'documentos', align: 'right' },
                            { title: 'Total', dataIndex: 'total', align: 'right', render: (v) => formatarKz(v) },
                          ]}
                        />
                      </Card>
                    </Col>
                    <Col xs={24} lg={12}>
                      <Card size="small" title="Por operador">
                        <Table
                          size={tamanho}
                          scroll={scrollTabela()}
                          pagination={false}
                          rowKey={(o) => o.operador ?? '—'}
                          dataSource={r.por_operador}
                          columns={[
                            { title: 'Operador', dataIndex: 'operador', render: (v) => v ?? '—' },
                            { title: 'Documentos', dataIndex: 'documentos', align: 'right' },
                            { title: 'Total', dataIndex: 'total', align: 'right', render: (v) => formatarKz(v) },
                          ]}
                        />
                      </Card>
                    </Col>
                  </Row>
                ),
              },
              {
                key: 'tpa',
                label: `Diferenças TPA (${r.diferencas_tpa.length})`,
                children: r.diferencas_tpa.length ? (
                  <Table
                    size={tamanho}
                    scroll={scrollTabela()}
                    pagination={false}
                    rowKey={(d) => `${d.sessao_pos_id}-${d.meio_id}`}
                    dataSource={r.diferencas_tpa}
                    columns={[
                      { title: 'Z', dataIndex: 'numero_z' },
                      { title: 'TPA', render: (_, d) => `${d.nome ?? '—'}${d.codigo_tpa ? ` (${d.codigo_tpa})` : ''}` },
                      { title: 'Sistema', dataIndex: 'valor_sistema', align: 'right', render: (v) => formatarKz(v) },
                      { title: 'Talão', dataIndex: 'valor_talao', align: 'right', render: (v) => formatarKz(v) },
                      { title: 'Diferença', dataIndex: 'diferenca', align: 'right', render: (v) => <ValorDesvio valor={v} /> },
                      { title: 'Lote', dataIndex: 'referencia_lote', render: (v) => v ?? '—' },
                    ]}
                  />
                ) : (
                  <Empty description="Sem diferenças entre os talões TPA e o sistema." />
                ),
              },
            ]}
          />
        </>
      )}
      <DetalheSessao id={detalhe} aoFechar={() => setDetalhe(null)} />
    </>
  );
}
