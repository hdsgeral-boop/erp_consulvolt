import { Card, Flex, Input, InputNumber, Modal, Select, Table, theme, Tooltip, Typography } from 'antd';
import type { ColumnsType } from 'antd/es/table';
import { useQuery } from '@tanstack/react-query';
import dayjs from 'dayjs';
import { useMemo, useState, type CSSProperties } from 'react';
import { obter } from '@/api/cliente';
import { NavActivos } from './comum/NavActivos';
import { tabelaHtml, useImpressao, type ColunaImpressao, type PedidoImpressao } from '@/componentes/impressao';
import { useSessao } from '@/sessao/SessaoContexto';
import { useAccao } from '@/componentes/Accoes';
import { contemTexto } from '@/modulos/compras/comum/lista';
import { formatarData, formatarKz } from '@/utilitarios/formatacao';
import { rotuloActivos } from './comum/componentes';
import { codigoPeriodo, MESES_CURTOS, rotuloPeriodo, totaisMapa } from './comum/regras';
import type { LinhaMapa, MapaAmortizacoes } from './comum/tipos';
import { BotaoCartao } from './inventario/BotaoCartao';
import { anosMapa, anosTexto, taxaMapa, valorOuHifen } from './inventario/regrasInventario';

const kz = (v: string | number | null | undefined) => formatarKz(v ?? '0');
const kzOuHifen = (v: string | number | null | undefined) => valorOuHifen(v, formatarKz);

/**
 * Activos › Mapa de Amortizações (ecrã activos_mapa; renderAmortizationMap do legado): cartão «Mapa de Amortizações - Ano N»
 * com «Filtrar por Ano», Extrair e Exportar; colunas e cabeçalho em duas linhas («Meses do Ano N» sobre Jan…Dez) do antigo.
 * O «Mapa Fiscal» é um separador próprio do agregador (/m/activos/activos_mapa/fiscal).
 */
export default function Mapa() {
  const { pode } = useSessao();
  // fundos do legado (#f0fdf4 nas aquisições, #f8fafc no líquido) pelos tokens do tema, para a tela nocturna
  const { token } = theme.useToken();
  const VERDE_AQUISICOES = token.colorSuccessBg;
  const { imprimir, exportarExcel, aImprimir } = useImpressao();
  const [ano, setAno] = useState(dayjs().year());
  const [texto, setTexto] = useState('');
  const [categoria, setCategoria] = useState<string>();
  const [estado, setEstado] = useState<string>();
  const [editar, setEditar] = useState<{ linha: LinhaMapa; mes: number; valor: number | null } | null>(null);
  const q = useQuery({ queryKey: ['activos', 'mapa', ano], queryFn: () => obter<MapaAmortizacoes>('/ativos/mapas/amortizacoes', { ano }) });
  const quota = useAccao({ invalidar: [['activos']], aoSucesso: () => setEditar(null) });
  const podeEditar = pode('activos_mapa_editar', 'activos_amort_calcular');

  const categorias = useMemo(() => [...new Set((q.data?.linhas ?? []).map((l) => l.categoria).filter(Boolean))] as string[], [q.data]);
  const linhas = useMemo(
    () => (q.data?.linhas ?? []).filter((l) => (!categoria || l.categoria === categoria) && (!estado || l.estado === estado) && contemTexto(texto, l.codigo, l.descricao)),
    [q.data, categoria, estado, texto],
  );
  const filtrado = linhas.length !== (q.data?.linhas.length ?? 0);
  const totais = filtrado ? totaisMapa(linhas) : q.data?.totais;
  const titulo = `Mapa de Amortizações - Ano ${ano}`;

  /** Documento do mapa (Extrair = imprimir/PDF; Exportar = Excel), com as colunas pela ordem do antigo. */
  const pedido = (): PedidoImpressao => {
    const tot = totais;
    const colunasImp: ColunaImpressao<LinhaMapa>[] = [
      { titulo: 'Nº Inv.', valor: (l) => l.codigo, total: 'TOTAIS (FILTRADOS):' },
      { titulo: 'Descrição', valor: (l) => l.descricao },
      { titulo: 'Data Aq.', valor: (l) => l.data_aquisicao, formato: 'data' },
      { titulo: 'V. Aquisição (Anterior)', valor: (l) => l.aquisicao_anos_anteriores, formato: 'moeda', total: tot ? kz(tot.aquisicao_anos_anteriores) : '' },
      { titulo: 'Aquisições Ano', valor: (l) => l.aquisicao_ano, formato: 'moeda', total: tot ? kz(tot.aquisicao_ano) : '' },
      { titulo: 'Taxa %', valor: (l) => taxaMapa(l.taxa), alinhamento: 'direita' },
      { titulo: 'Anos', valor: (l) => anosTexto(l.anos_vida), alinhamento: 'direita' },
      { titulo: 'Amort. Acum. (Anos Anteriores)', valor: (l) => l.acumulado_anterior, formato: 'moeda', total: tot ? kz(tot.acumulado_anterior) : '' },
      ...MESES_CURTOS.map((nome, i): ColunaImpressao<LinhaMapa> => ({
        titulo: nome, valor: (l) => l.meses[String(i + 1)]?.valor ?? '', formato: 'moeda', total: tot ? kz(tot.meses[String(i + 1)]) : '',
      })),
      { titulo: 'Total Ano', valor: (l) => l.ano, formato: 'moeda', total: tot ? kz(tot.ano) : '' },
      { titulo: 'Acumulado Final', valor: (l) => l.acumulado, formato: 'moeda', total: tot ? kz(tot.acumulado) : '' },
      { titulo: 'V.C. Líquido', valor: (l) => l.liquido, formato: 'moeda', total: tot ? kz(tot.liquido) : '' },
    ];
    return {
      titulo,
      periodo: String(ano),
      filtros: [texto && `Pesquisa: ${texto}`, categoria && `Categoria: ${categoria}`, estado && `Estado: ${rotuloActivos(estado)}`].filter((f): f is string => !!f),
      orientacao: 'paisagem',
      nomeFicheiro: `Mapa_Amortizacoes_${ano}`,
      conteudo: tabelaHtml({ colunas: colunasImp, linhas, totais: true }),
    } as PedidoImpressao;
  };

  const celulaMes = (l: LinhaMapa, i: number) => {
    const m = l.meses[String(i + 1)];
    const valor = Number(m?.valor ?? 0);
    const editavel = podeEditar && l.estado === 'ACTIVO' && !m?.contabilizado;
    // legado: azul e negrito com valor; cinzento «-» sem valor; quotas por integrar a laranja (sistema novo)
    const conteudo = valor > 0 ? (
      <span style={{ whiteSpace: 'nowrap', fontWeight: 600, color: m?.contabilizado ? 'var(--ant-color-primary, #2563eb)' : '#d97706' }}>{kz(m?.valor)}</span>
    ) : <span style={{ color: '#cbd5e1' }}>-</span>;
    return editavel ? (
      <Tooltip title={`Clique para editar este valor (${rotuloPeriodo(codigoPeriodo(ano, i + 1))})`}>
        <a onClick={() => setEditar({ linha: l, mes: i + 1, valor: m ? Number(m.valor) : null })}>{conteudo}</a>
      </Tooltip>
    ) : conteudo;
  };

  const colunas: ColumnsType<LinhaMapa> = [
    { title: 'Nº Inv.', dataIndex: 'codigo', fixed: 'left', width: 100, render: (v) => <strong>{v}</strong> },
    { title: 'Descrição', dataIndex: 'descricao', ellipsis: true, width: 200, render: (v) => <small>{v}</small> },
    { title: 'Data Aq.', dataIndex: 'data_aquisicao', align: 'center', render: formatarData },
    { title: 'V. Aquisição (Anterior)', dataIndex: 'aquisicao_anos_anteriores', align: 'right', render: kzOuHifen },
    { title: 'Aquisições Ano', dataIndex: 'aquisicao_ano', align: 'right', onHeaderCell: () => ({ style: { background: VERDE_AQUISICOES } }),
      onCell: () => ({ style: { background: VERDE_AQUISICOES, fontWeight: 700 } }), render: kzOuHifen },
    { title: 'Taxa %', dataIndex: 'taxa', align: 'center', render: (v) => <span style={v === 'FIXA' ? { fontWeight: 700, color: '#d97706' } : undefined}>{taxaMapa(v)}</span> },
    { title: 'Anos', dataIndex: 'anos_vida', align: 'center', render: (v) => anosTexto(v) },
    { title: <>Amort. Acum.<br />(Anos Anteriores)</>, dataIndex: 'acumulado_anterior', align: 'right', render: (v) => <span style={{ color: '#64748b' }}>{kz(v)}</span> },
    {
      title: `Meses do Ano ${ano}`, align: 'center', key: 'meses',
      children: MESES_CURTOS.map((nome, i) => ({ title: nome, key: `m${i + 1}`, align: 'right' as const, render: (_: unknown, l: LinhaMapa) => celulaMes(l, i) })),
    },
    { title: 'Total Ano', dataIndex: 'ano', align: 'right', render: (v) => <span style={{ fontWeight: 700 }}>{kz(v)}</span> },
    { title: 'Acumulado Final', dataIndex: 'acumulado', align: 'right', render: (v) => <span style={{ fontWeight: 700, color: '#dc2626' }}>{kz(v)}</span> },
    { title: 'V.C. Líquido', dataIndex: 'liquido', align: 'right', onHeaderCell: () => ({ style: { borderLeft: `2px solid ${token.colorBorderSecondary}` } }),
      onCell: () => ({ style: { background: token.colorFillAlter, borderLeft: `2px solid ${token.colorBorderSecondary}` } }), render: (v) => <span style={{ fontWeight: 700, color: '#059669' }}>{kz(v)}</span> },
  ];

  const celulaTotal = (indice: number, valor: string | undefined, estilo?: CSSProperties) => (
    <Table.Summary.Cell index={indice} align="right"><span style={{ fontWeight: 700, ...estilo }}>{kz(valor)}</span></Table.Summary.Cell>
  );

  return (
    <>
      <NavActivos actual="activos_mapa" />
      <Card
        title={titulo}
        extra={
          <Flex gap={10} align="center" wrap className="imp-nao-imprimir">
            <label htmlFor="map-year-select" style={{ fontSize: '0.85rem', fontWeight: 600 }}>Filtrar por Ano:</label>
            <Select id="map-year-select" size="small" value={ano} onChange={setAno} style={{ width: 100 }} options={anosMapa(dayjs().year(), ano).map((a) => ({ value: a, label: String(a) }))} />
            <BotaoCartao icone="file-invoice" variante="primario" disabled={!linhas.length} loading={aImprimir} onClick={() => void imprimir(pedido())}>Extrair</BotaoCartao>
            <BotaoCartao icone="file-excel" disabled={!linhas.length} onClick={() => void exportarExcel(pedido())}>Exportar</BotaoCartao>
          </Flex>
        }
      >
        <Flex gap={8} wrap style={{ marginBottom: 12 }}>
          <Input.Search placeholder="Código ou descrição" allowClear onSearch={setTexto} style={{ width: 240 }} />
          <Select placeholder="Categoria" allowClear value={categoria} onChange={setCategoria} style={{ width: 220 }} options={categorias.map((c) => ({ value: c, label: c }))} />
          <Select placeholder="Estado" allowClear value={estado} onChange={setEstado} style={{ width: 140 }}
            options={[{ value: 'ACTIVO', label: 'Activo' }, { value: 'INACTIVO', label: 'Inactivo' }, { value: 'ABATIDO', label: 'Abatido' }]} />
        </Flex>
        {podeEditar && (
          <Typography.Text type="secondary" style={{ display: 'block', marginBottom: 8, fontSize: 12 }}>
            Quotas a laranja: calculadas e ainda não integradas. Clique numa quota não integrada para a definir manualmente.
          </Typography.Text>
        )}
        <Table<LinhaMapa>
          rowKey="ativo_imobilizado_id"
          size="small"
          bordered
          style={{ fontSize: '0.75rem' }}
          loading={q.isFetching}
          dataSource={linhas}
          columns={colunas}
          scroll={{ x: 'max-content', y: '70vh' }}
          pagination={false}
          summary={() => totais && (
            <Table.Summary fixed>
              <Table.Summary.Row style={{ background: token.colorFillAlter }}>
                <Table.Summary.Cell index={0} colSpan={3}><strong>TOTAIS (FILTRADOS):</strong></Table.Summary.Cell>
                {celulaTotal(3, totais.aquisicao_anos_anteriores)}
                {celulaTotal(4, totais.aquisicao_ano, { background: VERDE_AQUISICOES })}
                <Table.Summary.Cell index={5} colSpan={2} />
                {celulaTotal(7, totais.acumulado_anterior)}
                {MESES_CURTOS.map((_, i) => celulaTotal(8 + i, totais.meses[String(i + 1)]))}
                {celulaTotal(20, totais.ano)}
                {celulaTotal(21, totais.acumulado, { color: '#dc2626' })}
                {celulaTotal(22, totais.liquido, { color: '#059669' })}
              </Table.Summary.Row>
            </Table.Summary>
          )}
        />
      </Card>
      <Modal
        title={editar ? `Editar cota de amortização para o período ${rotuloPeriodo(codigoPeriodo(ano, editar.mes))} (${editar.linha.codigo})` : ''}
        open={!!editar}
        onCancel={() => setEditar(null)}
        okText="Gravar em rascunho"
        cancelText="Cancelar"
        confirmLoading={quota.isPending}
        onOk={() => editar && quota.mutate({ metodo: 'put', url: '/ativos/amortizacoes/quota', dados: { ativo_imobilizado_id: editar.linha.ativo_imobilizado_id, periodo: codigoPeriodo(ano, editar.mes), valor: editar.valor ?? 0 } })}
      >
        <Typography.Paragraph type="secondary">A quota fica em rascunho até à integração do período. Valor 0 retira o rascunho.</Typography.Paragraph>
        <InputNumber min={0} precision={2} style={{ width: 200 }} value={editar?.valor} onChange={(v) => editar && setEditar({ ...editar, valor: v })} suffix="Kz" autoFocus />
      </Modal>
    </>
  );
}
