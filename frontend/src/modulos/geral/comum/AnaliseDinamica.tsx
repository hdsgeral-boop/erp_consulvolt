import { Alert, Button, Card, Checkbox, Col, DatePicker, Empty, Flex, Form, Row, Segmented, Select, Space, Table, Tag, Typography } from 'antd';
import { DeleteOutlined, DownloadOutlined, PlayCircleOutlined, PlusOutlined } from '@ant-design/icons';
import { useMutation, useQuery } from '@tanstack/react-query';
import dayjs, { type Dayjs } from 'dayjs';
import { useEffect, useMemo, useState } from 'react';
import { enviar, obter } from '@/api/cliente';
import { notificarErro } from '@/utilitarios/erros';
import { dataApi } from '@/utilitarios/formatacao';
import { descarregarCsv } from '@/modulos/contab/comum/csv';
import { GraficoBarras } from './Graficos';
import { eNumerico, formatarPorFormato } from './componentes';
import { AGREGACOES, LIMITES_CUBO, construirPivot, pivotParaCsv, seriesPivot, type ConjuntoCubo, type PedidoMedida, type ResultadoCubo } from './pivot';

interface Filtro {
  dimensao: string;
  modo: 'filtros' | 'exclusoes';
  valores: string[];
}

interface Props {
  conjunto: ConjuntoCubo;
  /** POST que executa a análise (ex.: /gestao/cubo/consultar ou /gestao/bi/consultar). */
  urlConsultar: string;
  /** Envia `conjunto` no pedido (cubo). */
  enviarConjunto?: boolean;
  /** Valores das dimensões para os filtros (só o cubo tem GET /gestao/cubo/valores). */
  comValores?: boolean;
  /** Datas obrigatórias (cubo) ou opcionais com período pré-definido (BI). */
  periodos?: { id: string; rotulo: string }[];
}

/**
 * Análise dinâmica (tabela dinâmica agregada no servidor): dimensões nas linhas e colunas, medidas com agregação, filtros de
 * inclusão/exclusão, período, gráfico da 1.ª medida e exportação CSV. Usada pelo cubo do Dashboard e pelo BI contabilístico.
 */
export function AnaliseDinamica({ conjunto, urlConsultar, enviarConjunto, comValores, periodos }: Props) {
  const [datas, setDatas] = useState<[Dayjs, Dayjs] | null>([dayjs().startOf('year'), dayjs().endOf('month')]);
  const [periodo, setPeriodo] = useState<string | undefined>(periodos ? 'ano_atual' : undefined);
  const [linhas, setLinhas] = useState<string[]>(conjunto.padrao.linhas);
  const [colunas, setColunas] = useState<string[]>(conjunto.padrao.colunas);
  const [medidas, setMedidas] = useState<PedidoMedida[]>(conjunto.padrao.medidas);
  const [filtros, setFiltros] = useState<Filtro[]>([]);
  const [apuramento, setApuramento] = useState(false);
  const [vista, setVista] = useState<'tabela' | 'grafico'>('tabela');

  useEffect(() => {
    setLinhas(conjunto.padrao.linhas);
    setColunas(conjunto.padrao.colunas);
    setMedidas(conjunto.padrao.medidas);
    setFiltros([]);
  }, [conjunto]);

  const rotuloDim = (id: string) => conjunto.dimensoes.find((d) => d.id === id)?.rotulo ?? id;
  const opcoesDim = conjunto.dimensoes.map((d) => ({ value: d.id, label: d.rotulo }));
  const usarDatas = !periodos || periodo === 'livre';

  const consulta = useMutation({
    mutationFn: () => {
      const agrupar = (modo: Filtro['modo']) =>
        Object.fromEntries(filtros.filter((f) => f.modo === modo && f.valores.length).map((f) => [f.dimensao, f.valores]));
      return enviar<ResultadoCubo>('post', urlConsultar, {
        ...(enviarConjunto ? { conjunto: conjunto.id } : {}),
        ...(periodos && periodo !== 'livre' ? { periodo } : {}),
        ...(usarDatas && datas ? { data_inicio: dataApi(datas[0]), data_fim: dataApi(datas[1]) } : {}),
        linhas,
        colunas,
        medidas: medidas.map((m) => (m.agregacao === 'contagem' ? { agregacao: 'contagem' } : m)),
        filtros: agrupar('filtros'),
        exclusoes: agrupar('exclusoes'),
        incluir_apuramento: apuramento,
      });
    },
    onError: (e) => notificarErro(e, 'Não foi possível executar a análise'),
  });
  const r = consulta.data?.dados;
  const pivot = useMemo(() => (r ? construirPivot(r) : null), [r]);

  const colunasTabela = useMemo(() => {
    if (!r || !pivot) return [];
    const dims = r.linhas.map((d, i) => ({
      title: d.rotulo,
      key: `d${i}`,
      fixed: i === 0 ? ('left' as const) : undefined,
      render: (_: unknown, l: { dimensoes: string[]; chave: string }) => (l.chave === 'total' ? (i === 0 ? <strong>{l.dimensoes[0]}</strong> : null) : l.dimensoes[i]),
    }));
    if (!r.linhas.length) dims.push({ title: '', key: 'd0', fixed: 'left', render: (_: unknown, l: { dimensoes: string[]; chave: string }) => <strong>{l.dimensoes[0]}</strong> });
    const valor = (chave: string, formato: string, total: boolean) => (v: unknown, l: Record<string, unknown>) => {
      const negativo = eNumerico(formato) && Number(l[chave]) < 0;
      return (
        <span style={{ whiteSpace: 'nowrap', fontWeight: total || l.chave === 'total' ? 600 : undefined, color: negativo ? '#cf1322' : undefined }}>
          {formatarPorFormato(v, formato)}
        </span>
      );
    };
    // agrupa as colunas por chave de coluna (cabeçalho em dois níveis quando há várias medidas)
    const grupos = new Map<string, typeof pivot.colunas>();
    pivot.colunas.forEach((c) => grupos.set(c.grupo, [...(grupos.get(c.grupo) ?? []), c]));
    const valores = [...grupos.entries()].map(([grupo, cs]) =>
      cs.length === 1 && (r.medidas.length === 1 || !grupo)
        ? { title: grupo || cs[0].medida, dataIndex: cs[0].chave, key: cs[0].chave, align: 'right' as const, render: valor(cs[0].chave, cs[0].formato, cs[0].total) }
        : {
            title: grupo,
            key: `g-${grupo}`,
            children: cs.map((c) => ({ title: c.medida, dataIndex: c.chave, key: c.chave, align: 'right' as const, render: valor(c.chave, c.formato, c.total) })),
          },
    );
    return [...dims, ...valores];
  }, [r, pivot]);

  const grafico = r ? seriesPivot(r) : null;
  const podeAnalisar = (medidas.length > 0 || linhas.length > 0) && (!usarDatas || !!datas);

  return (
    <Space direction="vertical" size={16} style={{ width: '100%' }}>
      <Card size="small">
        <Form layout="vertical">
          <Row gutter={16}>
            {periodos && (
              <Col xs={24} md={6}>
                <Form.Item label="Período">
                  <Select value={periodo} onChange={setPeriodo} options={[...periodos.map((p) => ({ value: p.id, label: p.rotulo })), { value: 'livre', label: 'Intervalo de datas' }]} />
                </Form.Item>
              </Col>
            )}
            {usarDatas && (
              <Col xs={24} md={8}>
                <Form.Item label="Datas" required>
                  <DatePicker.RangePicker format="DD/MM/YYYY" value={datas} onChange={(v) => setDatas(v && v[0] && v[1] ? [v[0], v[1]] : null)} style={{ width: '100%' }} allowClear={false} />
                </Form.Item>
              </Col>
            )}
            <Col xs={24} md={periodos ? 10 : 16}>
              <Form.Item label={`Linhas (até ${LIMITES_CUBO.linhas})`}>
                <Select mode="multiple" value={linhas} onChange={(v: string[]) => setLinhas(v.slice(0, LIMITES_CUBO.linhas))} options={opcoesDim.filter((o) => !colunas.includes(o.value))} placeholder="Dimensões nas linhas" />
              </Form.Item>
            </Col>
            <Col xs={24} md={12}>
              <Form.Item label={`Colunas (até ${LIMITES_CUBO.colunas})`}>
                <Select mode="multiple" value={colunas} onChange={(v: string[]) => setColunas(v.slice(0, LIMITES_CUBO.colunas))} options={opcoesDim.filter((o) => !linhas.includes(o.value))} placeholder="Dimensões nas colunas (opcional)" />
              </Form.Item>
            </Col>
            <Col xs={24} md={12}>
              <Form.Item label={`Medidas (até ${LIMITES_CUBO.medidas})`}>
                <Space direction="vertical" style={{ width: '100%' }} size={4}>
                  {medidas.map((m, i) => (
                    <Flex key={i} gap={8}>
                      <Select
                        style={{ flex: 1 }}
                        value={m.agregacao === 'contagem' ? '__contagem' : (m.medida ?? undefined)}
                        disabled={m.agregacao === 'contagem'}
                        options={[...conjunto.medidas.map((x) => ({ value: x.id, label: x.rotulo })), ...(m.agregacao === 'contagem' ? [{ value: '__contagem', label: 'N.º de registos' }] : [])]}
                        onChange={(v: string) => setMedidas(medidas.map((x, j) => (j === i ? { ...x, medida: v } : x)))}
                        aria-label="Medida"
                      />
                      <Select
                        style={{ width: 130 }}
                        value={m.agregacao}
                        options={conjunto.agregacoes.map((a) => ({ value: a, label: AGREGACOES[a] ?? a }))}
                        onChange={(a: string) => setMedidas(medidas.map((x, j) => (j === i ? { medida: a === 'contagem' ? null : (x.medida ?? conjunto.medidas[0]?.id ?? null), agregacao: a } : x)))}
                        aria-label="Agregação"
                      />
                      <Button type="text" icon={<DeleteOutlined />} aria-label="Remover medida" onClick={() => setMedidas(medidas.filter((_, j) => j !== i))} />
                    </Flex>
                  ))}
                  {medidas.length < LIMITES_CUBO.medidas && (
                    <Button size="small" type="dashed" icon={<PlusOutlined />} onClick={() => setMedidas([...medidas, { medida: conjunto.medidas[0]?.id ?? null, agregacao: 'soma' }])}>
                      Medida
                    </Button>
                  )}
                </Space>
              </Form.Item>
            </Col>
          </Row>
          <Typography.Text strong>Filtros</Typography.Text>
          <Space direction="vertical" style={{ width: '100%', marginTop: 8 }} size={6}>
            {filtros.map((f, i) => (
              <LinhaFiltro
                key={i}
                filtro={f}
                opcoesDim={opcoesDim}
                conjunto={conjunto.id}
                datas={datas}
                comValores={comValores}
                aoMudar={(nf) => setFiltros(filtros.map((x, j) => (j === i ? nf : x)))}
                aoRemover={() => setFiltros(filtros.filter((_, j) => j !== i))}
              />
            ))}
            <Flex gap={16} wrap align="center">
              <Button size="small" type="dashed" icon={<PlusOutlined />} onClick={() => setFiltros([...filtros, { dimensao: conjunto.dimensoes[0]?.id ?? '', modo: 'filtros', valores: [] }])}>
                Filtro
              </Button>
              {conjunto.opcoes.includes('incluir_apuramento') && (
                <Checkbox checked={apuramento} onChange={(e) => setApuramento(e.target.checked)}>
                  Incluir lançamentos de apuramento (períodos 13 e 14)
                </Checkbox>
              )}
            </Flex>
          </Space>
          <Flex justify="end" style={{ marginTop: 12 }}>
            <Button type="primary" icon={<PlayCircleOutlined />} loading={consulta.isPending} disabled={!podeAnalisar} onClick={() => consulta.mutate()}>
              Analisar
            </Button>
          </Flex>
        </Form>
      </Card>

      {r && pivot && (
        <Card
          size="small"
          title={
            <Space>
              {r.conjunto.nome}
              <Typography.Text type="secondary" style={{ fontWeight: 400, fontSize: 12 }}>
                {r.resultado.length} linha(s){r.duracao_ms !== undefined ? ` · ${r.duracao_ms} ms` : ''}
              </Typography.Text>
            </Space>
          }
          extra={
            <Space>
              <Segmented size="small" value={vista} onChange={(v) => setVista(v as 'tabela' | 'grafico')} options={[{ value: 'tabela', label: 'Tabela' }, { value: 'grafico', label: 'Gráfico' }]} />
              <Button
                size="small"
                icon={<DownloadOutlined />}
                onClick={() =>
                  descarregarCsv(
                    `analise_${r.conjunto.id}`,
                    pivotParaCsv(r)
                      .map((l) => l.map((c) => (/[";\n]/.test(c) ? `"${c.replace(/"/g, '""')}"` : /^-?\d+(\.\d+)?$/.test(c) ? c.replace('.', ',') : c)).join(';'))
                      .join('\r\n'),
                  )
                }
              >
                CSV
              </Button>
            </Space>
          }
        >
          {r.medidas.length === 0 && <Alert type="info" message="Escolha pelo menos uma medida." />}
          {vista === 'tabela' ? (
            <Table
              size="small"
              bordered
              rowKey="chave"
              dataSource={[...pivot.linhas, ...(pivot.totais ? [pivot.totais] : [])]}
              columns={colunasTabela}
              scroll={{ x: 'max-content', y: 520 }}
              pagination={pivot.linhas.length > 200 ? { pageSize: 200, showSizeChanger: false } : false}
              locale={{ emptyText: <Empty description="Sem dados para os critérios escolhidos." /> }}
            />
          ) : grafico && grafico.rotulos.length ? (
            <GraficoBarras
              titulo={`${r.medidas[0]?.rotulo ?? ''} — ${r.linhas.map((d) => d.rotulo).join(' · ') || 'Total'} (15 maiores)`}
              rotulos={grafico.rotulos}
              series={grafico.series}
              monetario={r.medidas[0]?.formato === 'kz'}
              horizontal
            />
          ) : (
            <Empty description="Sem dados para o gráfico." />
          )}
          {(r.linhas.length > 0 || r.colunas.length > 0) && (
            <Typography.Paragraph type="secondary" style={{ fontSize: 12, marginTop: 8, marginBottom: 0 }}>
              Linhas: {r.linhas.map((d) => d.rotulo).join(', ') || '—'} · Colunas: {r.colunas.map((d) => d.rotulo).join(', ') || '—'}
              {filtros.filter((f) => f.valores.length).map((f, i) => (
                <Tag key={i} style={{ marginLeft: 6 }} color={f.modo === 'filtros' ? 'blue' : 'red'}>
                  {rotuloDim(f.dimensao)} {f.modo === 'filtros' ? '∈' : '∉'} {f.valores.length}
                </Tag>
              ))}
            </Typography.Paragraph>
          )}
        </Card>
      )}
    </Space>
  );
}

function LinhaFiltro({
  filtro,
  opcoesDim,
  conjunto,
  datas,
  comValores,
  aoMudar,
  aoRemover,
}: {
  filtro: Filtro;
  opcoesDim: { value: string; label: string }[];
  conjunto: string;
  datas: [Dayjs, Dayjs] | null;
  comValores?: boolean;
  aoMudar: (f: Filtro) => void;
  aoRemover: () => void;
}) {
  const [pesquisa, setPesquisa] = useState('');
  const valores = useQuery({
    queryKey: ['gestao', 'cubo', 'valores', conjunto, filtro.dimensao, datas?.[0]?.valueOf(), datas?.[1]?.valueOf(), pesquisa],
    queryFn: () =>
      obter<{ valor: string | null; linhas: number }[]>('/gestao/cubo/valores', {
        conjunto,
        dimensao: filtro.dimensao,
        data_inicio: dataApi(datas?.[0]),
        data_fim: dataApi(datas?.[1]),
        pesquisa,
      }),
    enabled: !!comValores && !!filtro.dimensao && !!datas,
  });
  return (
    <Flex gap={8} wrap>
      <Select style={{ width: 200 }} value={filtro.dimensao} options={opcoesDim} onChange={(d: string) => aoMudar({ ...filtro, dimensao: d, valores: [] })} aria-label="Dimensão do filtro" />
      <Select
        style={{ width: 130 }}
        value={filtro.modo}
        options={[{ value: 'filtros', label: 'é um de' }, { value: 'exclusoes', label: 'não é' }]}
        onChange={(m: Filtro['modo']) => aoMudar({ ...filtro, modo: m })}
        aria-label="Tipo de filtro"
      />
      <Select
        mode={comValores ? 'multiple' : 'tags'}
        style={{ flex: 1, minWidth: 240 }}
        value={filtro.valores}
        onChange={(v: string[]) => aoMudar({ ...filtro, valores: v })}
        onSearch={comValores ? setPesquisa : undefined}
        filterOption={!comValores}
        loading={valores.isFetching}
        placeholder={comValores ? 'Escolha os valores' : 'Escreva os valores e prima Enter'}
        options={(valores.data ?? []).map((v) => ({ value: v.valor ?? '', label: `${v.valor ?? '(vazio)'} (${v.linhas})` }))}
        aria-label="Valores do filtro"
      />
      <Button type="text" icon={<DeleteOutlined />} aria-label="Remover filtro" onClick={aoRemover} />
    </Flex>
  );
}
