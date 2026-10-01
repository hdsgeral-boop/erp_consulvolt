import { Card, Flex, Input, InputNumber, Modal, Select, Table, Tabs, Tooltip, Typography } from 'antd';
import type { ColumnsType } from 'antd/es/table';
import { useQuery } from '@tanstack/react-query';
import dayjs from 'dayjs';
import { useMemo, useState } from 'react';
import { obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { useSessao } from '@/sessao/SessaoContexto';
import { BotaoCsv, ValorKz } from '@/modulos/contab/comum/Componentes';
import { somarColunas } from '@/utilitarios/decimal';
import { useAccao } from '@/componentes/Accoes';
import { contemTexto } from '@/modulos/compras/comum/lista';
import { formatarData, formatarKz } from '@/utilitarios/formatacao';
import { EtiquetaActivos } from './comum/componentes';
import { codigoPeriodo, MESES_CURTOS, rotuloPeriodo, totaisMapa } from './comum/regras';
import type { LinhaFiscal, LinhaMapa, MapaAmortizacoes, MapaFiscal } from './comum/tipos';

/** Activos › Mapa das amortizações (ecrã activos_mapa): mapa anual com as 12 quotas mensais e mapa fiscal. */
export default function Mapa() {
  const [ano, setAno] = useState(dayjs().year());
  return (
    <>
      <CabecalhoPagina
        titulo="Mapa das amortizações"
        accoes={<InputNumber addonBefore="Ano" min={1900} max={2100} value={ano} onChange={(v) => v && setAno(v)} style={{ width: 150 }} />}
      />
      <Tabs
        destroyInactiveTabPane
        items={[
          { key: 'anual', label: 'Mapa anual', children: <MapaAnual ano={ano} /> },
          { key: 'fiscal', label: 'Mapa fiscal', children: <MapaFiscalAno ano={ano} /> },
        ]}
      />
    </>
  );
}

function MapaAnual({ ano }: { ano: number }) {
  const { pode } = useSessao();
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

  const colunas: ColumnsType<LinhaMapa> = [
    { title: 'Código', dataIndex: 'codigo', fixed: 'left', width: 110 },
    { title: 'Descrição', dataIndex: 'descricao', ellipsis: true, width: 220 },
    { title: 'Categoria', dataIndex: 'categoria', width: 160, ellipsis: true },
    { title: 'Taxa', dataIndex: 'taxa', align: 'right', render: (v) => (v ? `${Number(v).toLocaleString('pt-PT')}%` : '—') },
    { title: 'Aquisição', dataIndex: 'data_aquisicao', render: formatarData },
    { title: 'Aquis. anos ant.', dataIndex: 'aquisicao_anos_anteriores', align: 'right', render: (v) => <ValorKz valor={v} discretoSeZero /> },
    { title: 'Aquis. no ano', dataIndex: 'aquisicao_ano', align: 'right', render: (v) => <ValorKz valor={v} discretoSeZero /> },
    { title: 'Acum. anterior', dataIndex: 'acumulado_anterior', align: 'right', render: (v) => <ValorKz valor={v} discretoSeZero /> },
    ...MESES_CURTOS.map((nome, i) => ({
      title: nome,
      key: `m${i + 1}`,
      align: 'right' as const,
      render: (_: unknown, l: LinhaMapa) => {
        const m = l.meses[String(i + 1)];
        const editavel = podeEditar && l.estado === 'ACTIVO' && !m?.contabilizado;
        const conteudo = m ? (
          <Typography.Text type={m.contabilizado ? undefined : 'warning'} style={{ whiteSpace: 'nowrap' }}>{formatarKz(m.valor)}</Typography.Text>
        ) : <Typography.Text type="secondary">·</Typography.Text>;
        return editavel ? (
          <Tooltip title={`Definir a quota de ${rotuloPeriodo(codigoPeriodo(ano, i + 1))}`}>
            <a onClick={() => setEditar({ linha: l, mes: i + 1, valor: m ? Number(m.valor) : null })}>{conteudo}</a>
          </Tooltip>
        ) : conteudo;
      },
    })),
    { title: 'Total do ano', dataIndex: 'ano', align: 'right', render: (v) => <ValorKz valor={v} forte /> },
    { title: 'Acumulado', dataIndex: 'acumulado', align: 'right', render: (v) => <ValorKz valor={v} /> },
    { title: 'Líquido', dataIndex: 'liquido', align: 'right', render: (v) => <ValorKz valor={v} forte /> },
    { title: 'Estado', dataIndex: 'estado', render: (v) => <EtiquetaActivos valor={v} /> },
  ];

  return (
    <Card>
      <Flex gap={8} wrap justify="space-between" style={{ marginBottom: 16 }}>
        <Flex gap={8} wrap>
          <Input.Search placeholder="Código ou descrição" allowClear onSearch={setTexto} style={{ width: 240 }} />
          <Select placeholder="Categoria" allowClear value={categoria} onChange={setCategoria} style={{ width: 220 }} options={categorias.map((c) => ({ value: c, label: c }))} />
          <Select placeholder="Estado" allowClear value={estado} onChange={setEstado} style={{ width: 140 }}
            options={[{ value: 'ACTIVO', label: 'Activo' }, { value: 'INACTIVO', label: 'Inactivo' }, { value: 'ABATIDO', label: 'Abatido' }]} />
        </Flex>
        <BotaoCsv nome={`mapa-amortizacoes-${ano}`} linhas={linhas} colunas={[
          { titulo: 'Código', valor: (l) => l.codigo }, { titulo: 'Descrição', valor: (l) => l.descricao }, { titulo: 'Categoria', valor: (l) => l.categoria },
          { titulo: 'Taxa', valor: (l) => l.taxa, numerico: true }, { titulo: 'Data aquisição', valor: (l) => l.data_aquisicao },
          { titulo: 'Aquisição anos anteriores', valor: (l) => l.aquisicao_anos_anteriores, numerico: true }, { titulo: 'Aquisição no ano', valor: (l) => l.aquisicao_ano, numerico: true },
          { titulo: 'Acumulado anterior', valor: (l) => l.acumulado_anterior, numerico: true },
          ...MESES_CURTOS.map((m, i) => ({ titulo: m, valor: (l: LinhaMapa) => l.meses[String(i + 1)]?.valor ?? '', numerico: true })),
          { titulo: 'Total do ano', valor: (l) => l.ano, numerico: true }, { titulo: 'Acumulado', valor: (l) => l.acumulado, numerico: true },
          { titulo: 'Líquido', valor: (l) => l.liquido, numerico: true }, { titulo: 'Estado', valor: (l) => l.estado },
        ]} />
      </Flex>
      <Typography.Text type="secondary" style={{ display: 'block', marginBottom: 8 }}>
        Quotas a laranja: calculadas e ainda não integradas.{podeEditar ? ' Clique numa quota não integrada para a definir manualmente.' : ''}
      </Typography.Text>
      <Table<LinhaMapa>
        rowKey="ativo_imobilizado_id"
        size="small"
        loading={q.isFetching}
        dataSource={linhas}
        columns={colunas}
        scroll={{ x: 'max-content', y: 560 }}
        pagination={false}
        summary={() => totais && (
          <Table.Summary fixed>
            <Table.Summary.Row>
              <Table.Summary.Cell index={0} colSpan={5}><Typography.Text strong>Total {filtrado ? '(filtrado)' : ''}</Typography.Text></Table.Summary.Cell>
              <Table.Summary.Cell index={5} align="right"><ValorKz valor={totais.aquisicao_anos_anteriores} forte /></Table.Summary.Cell>
              <Table.Summary.Cell index={6} align="right"><ValorKz valor={totais.aquisicao_ano} forte /></Table.Summary.Cell>
              <Table.Summary.Cell index={7} align="right"><ValorKz valor={totais.acumulado_anterior} forte /></Table.Summary.Cell>
              {MESES_CURTOS.map((_, i) => (
                <Table.Summary.Cell key={i} index={8 + i} align="right"><ValorKz valor={totais.meses[String(i + 1)]} forte discretoSeZero /></Table.Summary.Cell>
              ))}
              <Table.Summary.Cell index={20} align="right"><ValorKz valor={totais.ano} forte /></Table.Summary.Cell>
              <Table.Summary.Cell index={21} align="right"><ValorKz valor={totais.acumulado} forte /></Table.Summary.Cell>
              <Table.Summary.Cell index={22} align="right"><ValorKz valor={totais.liquido} forte /></Table.Summary.Cell>
              <Table.Summary.Cell index={23} />
            </Table.Summary.Row>
          </Table.Summary>
        )}
      />
      <Modal
        title={editar ? `Quota de ${editar.linha.codigo} em ${rotuloPeriodo(codigoPeriodo(ano, editar.mes))}` : ''}
        open={!!editar}
        onCancel={() => setEditar(null)}
        okText="Gravar em rascunho"
        cancelText="Cancelar"
        confirmLoading={quota.isPending}
        onOk={() => editar && quota.mutate({ metodo: 'put', url: '/ativos/amortizacoes/quota', dados: { ativo_imobilizado_id: editar.linha.ativo_imobilizado_id, periodo: codigoPeriodo(ano, editar.mes), valor: editar.valor ?? 0 } })}
      >
        <Typography.Paragraph type="secondary">A quota fica em rascunho até à integração do período. Valor 0 retira o rascunho.</Typography.Paragraph>
        <InputNumber min={0} precision={2} style={{ width: 200 }} value={editar?.valor} onChange={(v) => editar && setEditar({ ...editar, valor: v })} addonAfter="Kz" autoFocus />
      </Modal>
    </Card>
  );
}

function MapaFiscalAno({ ano }: { ano: number }) {
  const [texto, setTexto] = useState('');
  const q = useQuery({ queryKey: ['activos', 'mapa-fiscal', ano], queryFn: () => obter<MapaFiscal>('/ativos/mapas/fiscal', { ano }) });
  const linhas = (q.data?.linhas ?? []).filter((l) => contemTexto(texto, l.codigo, l.descricao, l.conta));
  const filtrado = linhas.length !== (q.data?.linhas.length ?? 0);
  const totais = filtrado ? somarColunas(linhas, ['valor_aquisicao', 'anteriores', 'exercicio', 'acumuladas', 'liquido']) : q.data?.totais;
  const mesAno = (m: number | null, a: number | null) => (m && a ? `${String(m).padStart(2, '0')}/${a}` : '—');

  return (
    <Card>
      <Flex gap={8} wrap justify="space-between" style={{ marginBottom: 16 }}>
        <Input.Search placeholder="Código, descrição ou conta" allowClear onSearch={setTexto} style={{ width: 280 }} />
        <BotaoCsv nome={`mapa-fiscal-${ano}`} linhas={linhas} colunas={[
          { titulo: 'Código', valor: (l) => l.codigo }, { titulo: 'Descrição', valor: (l) => l.descricao }, { titulo: 'Conta', valor: (l) => l.conta },
          { titulo: 'Aquisição (mês/ano)', valor: (l) => mesAno(l.mes_aquisicao, l.ano_aquisicao) }, { titulo: 'Início de utilização', valor: (l) => mesAno(l.mes_inicio_utilizacao, l.ano_inicio_utilizacao) },
          { titulo: 'Valor de aquisição', valor: (l) => l.valor_aquisicao, numerico: true }, { titulo: 'Anos de vida', valor: (l) => l.anos_vida, numerico: true },
          { titulo: 'Taxa da categoria', valor: (l) => l.taxa_categoria, numerico: true }, { titulo: 'Taxa efectiva', valor: (l) => l.taxa_efectiva, numerico: true },
          { titulo: 'Amortizações anteriores', valor: (l) => l.anteriores, numerico: true }, { titulo: 'Amortizações do exercício', valor: (l) => l.exercicio, numerico: true },
          { titulo: 'Amortizações acumuladas', valor: (l) => l.acumuladas, numerico: true }, { titulo: 'Valor líquido', valor: (l) => l.liquido, numerico: true },
        ]} />
      </Flex>
      <Table<LinhaFiscal>
        rowKey="ativo_imobilizado_id"
        size="small"
        loading={q.isFetching}
        dataSource={linhas}
        scroll={{ x: 'max-content', y: 560 }}
        pagination={false}
        columns={[
          { title: 'Código', dataIndex: 'codigo', fixed: 'left' },
          { title: 'Descrição', dataIndex: 'descricao', ellipsis: true, width: 240 },
          { title: 'Conta', dataIndex: 'conta' },
          { title: 'Aquisição', key: 'aq', render: (_, l) => mesAno(l.mes_aquisicao, l.ano_aquisicao) },
          { title: 'Início utiliz.', key: 'iu', render: (_, l) => mesAno(l.mes_inicio_utilizacao, l.ano_inicio_utilizacao) },
          { title: 'Valor de aquisição', dataIndex: 'valor_aquisicao', align: 'right', render: (v) => <ValorKz valor={v} /> },
          { title: 'Anos de vida', dataIndex: 'anos_vida', align: 'right' },
          { title: 'Taxa cat.', dataIndex: 'taxa_categoria', align: 'right', render: (v) => (v ? `${Number(v).toLocaleString('pt-PT')}%` : '—') },
          { title: 'Taxa efectiva', dataIndex: 'taxa_efectiva', align: 'right', render: (v) => (v ? `${Number(v).toLocaleString('pt-PT')}%` : '—') },
          { title: 'Anteriores', dataIndex: 'anteriores', align: 'right', render: (v) => <ValorKz valor={v} discretoSeZero /> },
          { title: 'Do exercício', dataIndex: 'exercicio', align: 'right', render: (v) => <ValorKz valor={v} forte /> },
          { title: 'Acumuladas', dataIndex: 'acumuladas', align: 'right', render: (v) => <ValorKz valor={v} /> },
          { title: 'Líquido', dataIndex: 'liquido', align: 'right', render: (v) => <ValorKz valor={v} forte /> },
        ]}
        summary={() => totais && (
          <Table.Summary fixed>
            <Table.Summary.Row>
              <Table.Summary.Cell index={0} colSpan={5}><Typography.Text strong>Total {filtrado ? '(filtrado)' : ''}</Typography.Text></Table.Summary.Cell>
              <Table.Summary.Cell index={5} align="right"><ValorKz valor={totais.valor_aquisicao} forte /></Table.Summary.Cell>
              <Table.Summary.Cell index={6} colSpan={3} />
              <Table.Summary.Cell index={9} align="right"><ValorKz valor={totais.anteriores} forte /></Table.Summary.Cell>
              <Table.Summary.Cell index={10} align="right"><ValorKz valor={totais.exercicio} forte /></Table.Summary.Cell>
              <Table.Summary.Cell index={11} align="right"><ValorKz valor={totais.acumuladas} forte /></Table.Summary.Cell>
              <Table.Summary.Cell index={12} align="right"><ValorKz valor={totais.liquido} forte /></Table.Summary.Cell>
            </Table.Summary.Row>
          </Table.Summary>
        )}
      />
    </Card>
  );
}
