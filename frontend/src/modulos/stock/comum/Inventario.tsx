import { Alert, Button, Card, Col, DatePicker, Descriptions, Flex, Form, Input, InputNumber, Modal, Row, Select, Skeleton, Space, Statistic, Table, Tag, Typography } from 'antd';
import { ArrowLeftOutlined, PlusOutlined, SaveOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import type { ColumnsType } from 'antd/es/table';
import dayjs, { type Dayjs } from 'dayjs';
import { useMemo, useState } from 'react';
import { Route, Routes, useNavigate, useParams } from 'react-router-dom';
import { obter } from '@/api/cliente';
import { ErroApi } from '@/api/tipos';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { useSessao } from '@/sessao/SessaoContexto';
import { dataApi, formatarData, formatarDataHora, formatarKz, formatarNumero } from '@/utilitarios/formatacao';
import { notificarErro } from '@/utilitarios/erros';
import { ModalMotivo, useAccao } from '@/componentes/Accoes';
import { EstadoTag, opcoesEstado } from '@/modulos/compras/comum/estados';
import { contemTexto } from '@/modulos/compras/comum/lista';
import { NomeArmazem } from '@/modulos/compras/comum/referencias';
import { SeletorArmazem, SeletorProduto } from '@/modulos/compras/comum/Seletores';
import { TabelaApi } from '@/componentes/TabelaApi';
import { accoesInventario, previsualizarRegularizacao } from './regras';
import type { LinhaInventario, SessaoInventario } from './tipos';

/** Modo do ecrã: sessões (gestão), contagem (contagem cega) ou revisão (diferenças e regularização). */
export type ModoInventario = 'sessoes' | 'contagem' | 'revisao';

const TITULOS: Record<ModoInventario, { titulo: string; subtitulo: string; estado?: string }> = {
  sessoes: { titulo: 'Sessões de inventário', subtitulo: 'Inventários por armazém: abertura, aprovação, reabertura e anulação' },
  contagem: { titulo: 'Efectuar contagem', subtitulo: 'Contagem física (cega: a quantidade do sistema não é mostrada)', estado: 'EM_CONTAGEM' },
  revisao: { titulo: 'Revisão e regularização', subtitulo: 'Diferenças entre o contado e o sistema, custos e justificações', estado: 'REVISAO' },
};

export interface EdicaoContagem {
  quantidade_contada?: number | null;
  observacoes?: string | null;
}

/** Linhas a enviar na contagem: só as alteradas face ao que está gravado. */
export function linhasAlteradas(originais: Pick<LinhaInventario, 'produto_id' | 'quantidade_contada' | 'observacoes'>[], edicoes: Record<number, EdicaoContagem>) {
  const porProduto = new Map(originais.map((l) => [l.produto_id, l]));
  return Object.entries(edicoes)
    .map(([id, e]) => ({ produto_id: Number(id), ...e }))
    .filter((e) => {
      const o = porProduto.get(e.produto_id);
      if (!o) return true; // produto acrescentado na contagem
      const qOriginal = o.quantidade_contada === null ? null : Number(o.quantidade_contada);
      const qNova = e.quantidade_contada === undefined ? qOriginal : e.quantidade_contada;
      const obsNova = e.observacoes === undefined ? o.observacoes : e.observacoes;
      return qNova !== qOriginal || (obsNova || null) !== (o.observacoes || null);
    })
    .map((e) => {
      const o = porProduto.get(e.produto_id);
      return {
        produto_id: e.produto_id,
        quantidade_contada: e.quantidade_contada === undefined ? (o?.quantidade_contada == null ? null : Number(o.quantidade_contada)) : e.quantidade_contada,
        observacoes: (e.observacoes === undefined ? o?.observacoes : e.observacoes) || null,
      };
    });
}

export function EcraInventario({ modo }: { modo: ModoInventario }) {
  return (
    <Routes>
      <Route index element={<ListaSessoes modo={modo} />} />
      <Route path=":id" element={<DetalheSessao modo={modo} />} />
    </Routes>
  );
}

function ListaSessoes({ modo }: { modo: ModoInventario }) {
  const navegar = useNavigate();
  const { pode } = useSessao();
  const t = TITULOS[modo];
  const [armazem, setArmazem] = useState<number>();
  const [estado, setEstado] = useState<string | undefined>(t.estado);
  const [pesquisa, setPesquisa] = useState('');
  const [abrir, setAbrir] = useState(false);

  const colunas: ColumnsType<SessaoInventario> = [
    { title: 'Inventário', key: 'n', render: (_, s) => <strong>INV {dayjs(s.data).format('YYYY')}/{s.id}</strong> },
    { title: 'Data', dataIndex: 'data', render: formatarData },
    { title: 'Armazém', dataIndex: 'armazem_id', render: (v: number) => <NomeArmazem id={v} /> },
    { title: 'Descrição', dataIndex: 'descricao', render: (v) => v || '—' },
    { title: 'Iniciado por', dataIndex: 'iniciado_por', render: (v) => v || '—' },
    { title: 'Aprovado', key: 'ap', render: (_, s) => (s.aprovado_por ? `${s.aprovado_por} · ${formatarDataHora(s.aprovado_em)}` : '—') },
    { title: 'Estado', dataIndex: 'estado', render: (e: string) => <EstadoTag estado={e} /> },
  ];

  return (
    <>
      <CabecalhoPagina
        titulo={t.titulo}
        subtitulo={t.subtitulo}
        accoes={modo === 'sessoes' && pode('inventario_iniciar') && <Button type="primary" icon={<PlusOutlined />} onClick={() => setAbrir(true)}>Novo inventário</Button>}
      />
      <Card>
        <Flex gap={8} wrap style={{ marginBottom: 16 }}>
          <Input.Search placeholder="Descrição" allowClear style={{ width: 240 }} onSearch={setPesquisa} />
          <SeletorArmazem allowClear placeholder="Todos os armazéns" style={{ width: 220 }} value={armazem} onChange={setArmazem} />
          {modo === 'sessoes' && <Select placeholder="Estado" allowClear style={{ width: 180 }} value={estado} onChange={setEstado} options={opcoesEstado(['EM_CONTAGEM', 'REVISAO', 'CONCLUIDA', 'ANULADA'])} />}
        </Flex>
        <TabelaApi<SessaoInventario>
          url="/logistica/inventarios"
          filtros={{ armazem_id: armazem, estado, pesquisa: pesquisa.trim() || undefined }}
          chaveConsulta={['logistica', 'inventarios']}
          columns={colunas}
          locale={{ emptyText: modo === 'contagem' ? 'Não há inventários em contagem.' : modo === 'revisao' ? 'Não há inventários em revisão.' : undefined }}
          onRow={(r) => ({ onClick: () => navegar(String(r.id)), style: { cursor: 'pointer' } })}
        />
      </Card>
      <ModalAbrir aberto={abrir} aoFechar={() => setAbrir(false)} aoAbrir={(s) => navegar(String(s.id))} />
    </>
  );
}

function ModalAbrir({ aberto, aoFechar, aoAbrir }: { aberto: boolean; aoFechar: () => void; aoAbrir: (s: SessaoInventario) => void }) {
  const [form] = Form.useForm<{ armazem_id: number; data: Dayjs; descricao?: string }>();
  const accao = useAccao<SessaoInventario>({ invalidar: [['logistica']], aoSucesso: (s) => { aoFechar(); aoAbrir(s); }, tituloErro: 'Não foi possível abrir o inventário' });
  return (
    <Modal title="Novo inventário" open={aberto} onCancel={aoFechar} okText="Abrir inventário" cancelText="Cancelar" confirmLoading={accao.isPending} onOk={() => form.submit()} destroyOnClose>
      <Form form={form} layout="vertical" preserve={false} initialValues={{ data: dayjs() }} onFinish={(v) => accao.mutate({ url: '/logistica/inventarios', dados: { armazem_id: v.armazem_id, data: dataApi(v.data), descricao: v.descricao || undefined } })}>
        <Alert type="warning" showIcon style={{ marginBottom: 16 }} message="Enquanto o inventário estiver aberto, o armazém fica sem movimentos até à aprovação ou anulação." />
        <Form.Item name="armazem_id" label="Armazém" rules={[{ required: true, message: 'Escolha o armazém.' }]}>
          <SeletorArmazem />
        </Form.Item>
        <Form.Item name="data" label="Data do inventário" rules={[{ required: true }]}>
          <DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} />
        </Form.Item>
        <Form.Item name="descricao" label="Descrição" rules={[{ max: 500 }]}>
          <Input />
        </Form.Item>
      </Form>
    </Modal>
  );
}

function DetalheSessao({ modo }: { modo: ModoInventario }) {
  const { id } = useParams();
  const navegar = useNavigate();
  const { pode } = useSessao();
  const [modal, setModal] = useState<'reabrir' | 'anular' | null>(null);
  const [edicoes, setEdicoes] = useState<Record<number, EdicaoContagem>>({});
  const [revisao, setRevisao] = useState<Record<number, { custo_personalizado?: number | null; justificacao?: string | null }>>({});
  const [novoProduto, setNovoProduto] = useState<number>();
  const [pesquisa, setPesquisa] = useState('');
  const [soPorContar, setSoPorContar] = useState(false);

  const consulta = useQuery({ queryKey: ['logistica', 'inventario', id], queryFn: () => obter<SessaoInventario>(`/logistica/inventarios/${id}`) });
  const limpar = () => {
    setEdicoes({});
    setRevisao({});
    setModal(null);
  };
  const accao = useAccao({ invalidar: [['logistica']], aoSucesso: limpar });
  const concluir = useAccao({ invalidar: [['logistica']], aoSucesso: limpar });
  /** Acrescentar um produto não limpa as contagens ainda por gravar. */
  const acrescentar = useAccao({ invalidar: [['logistica', 'inventario', id]] });

  const s = consulta.data;
  const linhas = useMemo(() => s?.linhas ?? [], [s]);
  const linhasRevistas = useMemo(
    () => linhas.map((l) => ({ ...l, ...(revisao[l.produto_id] ? { custo_personalizado: revisao[l.produto_id].custo_personalizado === undefined ? l.custo_personalizado : revisao[l.produto_id].custo_personalizado == null ? null : String(revisao[l.produto_id].custo_personalizado), justificacao: revisao[l.produto_id].justificacao ?? l.justificacao } : {}) })),
    [linhas, revisao],
  );
  const previsao = useMemo(() => previsualizarRegularizacao(linhasRevistas), [linhasRevistas]);

  if (consulta.isLoading) return <Skeleton active />;
  if (!s) return <Alert type="error" message="Inventário não encontrado." />;
  const a = accoesInventario(s, pode);
  const nome = `INV ${dayjs(s.data).format('YYYY')}/${s.id}`;
  const alteradas = linhasAlteradas(linhas, edicoes);
  const porContar = linhas.filter((l) => (edicoes[l.produto_id]?.quantidade_contada !== undefined ? edicoes[l.produto_id].quantidade_contada : l.quantidade_contada) == null).length;
  const emContagem = s.estado === 'EM_CONTAGEM';
  const visiveis = linhasRevistas.filter((l) => contemTexto(pesquisa, l.codigo, l.nome) && (!soPorContar || (edicoes[l.produto_id]?.quantidade_contada ?? l.quantidade_contada) == null));
  const editarRevisao = a.rever && modo !== 'sessoes';
  const editarContagem = a.contar && modo !== 'revisao';

  const terminarContagem = () => {
    const enviar = (zero: boolean) => concluir.mutateAsync({ url: `/logistica/inventarios/${s.id}/concluir-contagem`, dados: { por_contar_como_zero: zero } });
    if (alteradas.length) {
      Modal.warning({ title: 'Há contagens por gravar', content: 'Grave a contagem antes de a terminar.' });
      return;
    }
    if (porContar > 0) {
      Modal.confirm({
        title: `Há ${porContar} produto(s) por contar`,
        content: 'Confirma que a quantidade destes produtos é zero? A contagem passa para revisão.',
        okText: 'Sim, contar como zero',
        cancelText: 'Voltar',
        onOk: () => enviar(true),
      });
    } else {
      Modal.confirm({ title: 'Terminar a contagem?', content: 'O inventário passa para revisão.', okText: 'Terminar', cancelText: 'Cancelar', onOk: () => enviar(false) });
    }
  };

  const aprovar = () =>
    Modal.confirm({
      title: `Aprovar o inventário ${nome}?`,
      content: `Regulariza o stock (sobras ${formatarKz(previsao.sobras.valor)} Kz, quebras ${formatarKz(previsao.quebras.valor)} Kz, estimativa) e lança no diário à data do inventário. Quem iniciou o inventário não o pode aprovar.`,
      okText: 'Aprovar e regularizar',
      cancelText: 'Cancelar',
      onOk: () => accao.mutateAsync({ url: `/logistica/inventarios/${s.id}/aprovar` }).catch(() => undefined),
    });

  const colunasContagem: ColumnsType<LinhaInventario> = [
    { title: 'Código', dataIndex: 'codigo', render: (v) => v || '—' },
    { title: 'Produto', dataIndex: 'nome' },
    {
      title: 'Quantidade contada',
      key: 'q',
      render: (_, l) => {
        const valor = edicoes[l.produto_id]?.quantidade_contada !== undefined ? edicoes[l.produto_id].quantidade_contada : l.quantidade_contada == null ? null : Number(l.quantidade_contada);
        return editarContagem ? (
          <InputNumber min={0} value={valor} style={{ width: 140 }} placeholder="por contar" onChange={(v) => setEdicoes((e) => ({ ...e, [l.produto_id]: { ...e[l.produto_id], quantidade_contada: v } }))} />
        ) : valor == null ? <Typography.Text type="secondary">por contar</Typography.Text> : formatarNumero(valor);
      },
    },
    {
      title: 'Observações',
      key: 'o',
      render: (_, l) =>
        editarContagem ? (
          <Input
            maxLength={500}
            style={{ width: 260 }}
            value={edicoes[l.produto_id]?.observacoes ?? l.observacoes ?? ''}
            onChange={(ev) => setEdicoes((e) => ({ ...e, [l.produto_id]: { ...e[l.produto_id], observacoes: ev.target.value } }))}
          />
        ) : (
          l.observacoes || '—'
        ),
    },
  ];

  const colunasRevisao: ColumnsType<LinhaInventario> = [
    { title: 'Código', dataIndex: 'codigo', render: (v) => v || '—' },
    { title: 'Produto', dataIndex: 'nome' },
    { title: 'Sistema', dataIndex: 'quantidade_sistema', align: 'right', render: formatarNumero },
    { title: 'Contado', dataIndex: 'quantidade_contada', align: 'right', render: formatarNumero },
    {
      title: 'Diferença',
      dataIndex: 'diferenca',
      align: 'right',
      render: (v: string | null) => {
        const n = Number(v ?? 0);
        return n ? <Tag color={n > 0 ? 'green' : 'red'}>{n > 0 ? '+' : ''}{formatarNumero(n)}</Tag> : '0';
      },
    },
    { title: 'Custo médio', dataIndex: 'custo_medio', align: 'right', render: (v: string | null) => formatarKz(v) },
    {
      title: 'Custo a usar',
      key: 'custo',
      render: (_, l) =>
        editarRevisao && Number(l.diferenca ?? 0) !== 0 ? (
          <InputNumber
            min={0}
            precision={2}
            style={{ width: 140 }}
            placeholder="custo médio"
            value={l.custo_personalizado == null || l.custo_personalizado === '' ? null : Number(l.custo_personalizado)}
            onChange={(v) => setRevisao((r) => ({ ...r, [l.produto_id]: { ...r[l.produto_id], custo_personalizado: v } }))}
          />
        ) : (
          formatarKz(l.custo_personalizado ?? l.custo_unitario ?? l.custo_medio)
        ),
    },
    {
      title: 'Justificação',
      key: 'just',
      render: (_, l) =>
        editarRevisao && Number(l.diferenca ?? 0) !== 0 ? (
          <Input maxLength={500} style={{ width: 240 }} value={l.justificacao ?? ''} onChange={(ev) => setRevisao((r) => ({ ...r, [l.produto_id]: { ...r[l.produto_id], justificacao: ev.target.value } }))} />
        ) : (
          l.justificacao || '—'
        ),
    },
    { title: 'Valor da diferença', key: 'valor', align: 'right', render: (_, l) => (l.valor_diferenca ? formatarKz(l.valor_diferenca) : formatarKz(Math.abs(Number(l.diferenca ?? 0)) * Number(l.custo_personalizado ?? l.custo_medio ?? 0) * Math.sign(Number(l.diferenca ?? 0)))) },
  ];

  return (
    <>
      <CabecalhoPagina
        titulo={`Inventário ${nome}`}
        subtitulo={<><NomeArmazem id={s.armazem_id} /> · {formatarData(s.data)}{s.descricao ? ` · ${s.descricao}` : ''}</>}
        accoes={
          <>
            <Button icon={<ArrowLeftOutlined />} onClick={() => navegar('..')}>Voltar</Button>
            {editarContagem && (
              <>
                <Button type="primary" icon={<SaveOutlined />} disabled={!alteradas.length} loading={accao.isPending} onClick={() => accao.mutate({ url: `/logistica/inventarios/${s.id}/contagem`, dados: { linhas: alteradas } })}>
                  Gravar contagem{alteradas.length ? ` (${alteradas.length})` : ''}
                </Button>
                <Button loading={concluir.isPending} onClick={terminarContagem}>Terminar contagem</Button>
              </>
            )}
            {editarRevisao && (
              <Button
                type="primary"
                icon={<SaveOutlined />}
                disabled={!Object.keys(revisao).length}
                loading={accao.isPending}
                onClick={() =>
                  accao.mutate({
                    url: `/logistica/inventarios/${s.id}/revisao`,
                    dados: {
                      linhas: linhasRevistas
                        .filter((l) => revisao[l.produto_id])
                        .map((l) => ({ produto_id: l.produto_id, custo_personalizado: l.custo_personalizado === null || l.custo_personalizado === '' ? null : Number(l.custo_personalizado), justificacao: l.justificacao || null })),
                    },
                  })
                }
              >
                Gravar revisão
              </Button>
            )}
            {a.voltarContagem && modo !== 'contagem' && (
              <Button onClick={() => Modal.confirm({ title: 'Voltar à contagem?', okText: 'Voltar à contagem', cancelText: 'Cancelar', onOk: () => accao.mutateAsync({ url: `/logistica/inventarios/${s.id}/voltar-contagem` }).catch(() => undefined) })}>
                Voltar à contagem
              </Button>
            )}
            {a.aprovar && modo !== 'contagem' && <Button type="primary" disabled={Object.keys(revisao).length > 0} onClick={aprovar}>Aprovar</Button>}
            {a.reabrir && modo === 'sessoes' && <Button danger onClick={() => setModal('reabrir')}>Reabrir</Button>}
            {a.anular && modo === 'sessoes' && <Button danger onClick={() => setModal('anular')}>Anular</Button>}
          </>
        }
      />
      {s.estado === 'ANULADA' && <Alert type="error" showIcon style={{ marginBottom: 16 }} message={`Inventário anulado${s.motivo_anulacao ? `: ${s.motivo_anulacao}` : '.'}`} />}
      {modo === 'contagem' && !emContagem && <Alert type="info" showIcon style={{ marginBottom: 16 }} message="Este inventário já não está em contagem." />}
      {modo === 'revisao' && s.estado !== 'REVISAO' && <Alert type="info" showIcon style={{ marginBottom: 16 }} message="Este inventário não está em revisão." />}

      <Card style={{ marginBottom: 16 }}>
        <Descriptions column={{ xs: 1, md: 4 }} size="small">
          <Descriptions.Item label="Estado"><EstadoTag estado={s.estado} /></Descriptions.Item>
          <Descriptions.Item label="Artigos">{linhas.length}</Descriptions.Item>
          {emContagem && <Descriptions.Item label="Por contar">{porContar}</Descriptions.Item>}
          {s.iniciado_por && <Descriptions.Item label="Iniciado por">{s.iniciado_por}</Descriptions.Item>}
          {s.aprovado_por && <Descriptions.Item label="Aprovado">{s.aprovado_por} · {formatarDataHora(s.aprovado_em)}</Descriptions.Item>}
          {s.numero_lan_contabilizacao && <Descriptions.Item label="Lançamento">{s.numero_lan_contabilizacao}</Descriptions.Item>}
        </Descriptions>
      </Card>

      {!emContagem && s.estado !== 'ANULADA' && (
        <Row gutter={16} style={{ marginBottom: 16 }}>
          <Col xs={12} md={6}><Card><Statistic title={`Sobras (${previsao.sobras.linhas})`} value={formatarKz(previsao.sobras.valor)} suffix="Kz" valueStyle={{ color: '#389e0d' }} /></Card></Col>
          <Col xs={12} md={6}><Card><Statistic title={`Quebras (${previsao.quebras.linhas})`} value={formatarKz(previsao.quebras.valor)} suffix="Kz" valueStyle={{ color: '#cf1322' }} /></Card></Col>
          <Col xs={12} md={6}><Card><Statistic title="Saldo da regularização" value={formatarKz(previsao.sobras.valor - previsao.quebras.valor)} suffix="Kz" /></Card></Col>
          <Col xs={12} md={6}><Card><Statistic title="Diferenças por justificar" value={previsao.porJustificar} valueStyle={previsao.porJustificar ? { color: '#d48806' } : undefined} /></Card></Col>
        </Row>
      )}

      <Card
        title={emContagem ? 'Contagem' : 'Linhas do inventário'}
        extra={
          editarContagem && (
            <Space>
              <SeletorProduto apenasStock allowClear placeholder="Acrescentar produto não listado" style={{ width: 300 }} value={novoProduto} onChange={setNovoProduto} />
              <Button
                icon={<PlusOutlined />}
                disabled={!novoProduto}
                loading={acrescentar.isPending}
                onClick={() => {
                  if (!novoProduto) return;
                  if (linhas.some((l) => l.produto_id === novoProduto)) {
                    notificarErro(new ErroApi('O produto já faz parte do inventário.', 422), 'Produto repetido');
                    return;
                  }
                  acrescentar.mutate({ url: `/logistica/inventarios/${s.id}/contagem`, dados: { linhas: [{ produto_id: novoProduto, quantidade_contada: null }] } });
                  setNovoProduto(undefined);
                }}
              >
                Acrescentar
              </Button>
            </Space>
          )
        }
      >
        <Flex gap={8} style={{ marginBottom: 12 }} align="center">
          <Input.Search placeholder="Código ou produto" allowClear style={{ width: 260 }} onSearch={setPesquisa} onChange={(e) => !e.target.value && setPesquisa('')} />
          {emContagem && (
            <Button size="small" type={soPorContar ? 'primary' : 'default'} onClick={() => setSoPorContar((x) => !x)}>
              Só por contar
            </Button>
          )}
        </Flex>
        <Table<LinhaInventario>
          rowKey="id"
          size="small"
          scroll={{ x: 'max-content' }}
          dataSource={visiveis}
          columns={emContagem ? colunasContagem : colunasRevisao}
          pagination={{ defaultPageSize: 50, showSizeChanger: true, showTotal: (t) => `${t} artigo(s)` }}
        />
      </Card>
      <ModalMotivo
        aberto={modal === 'reabrir'}
        titulo={`Reabrir o inventário ${nome}`}
        textoOk="Reabrir"
        aviso="Estorna o lançamento e anula os ajustes da regularização; o inventário volta à revisão."
        carregando={accao.isPending}
        aoFechar={() => setModal(null)}
        aoConfirmar={(motivo) => accao.mutate({ url: `/logistica/inventarios/${s.id}/reabrir`, dados: { motivo } })}
      />
      <ModalMotivo
        aberto={modal === 'anular'}
        titulo={`Anular o inventário ${nome}`}
        textoOk="Anular"
        aviso="O armazém volta a aceitar movimentos; nada é regularizado."
        carregando={accao.isPending}
        aoFechar={() => setModal(null)}
        aoConfirmar={(motivo) => accao.mutate({ url: `/logistica/inventarios/${s.id}/anular`, dados: { motivo } })}
      />
    </>
  );
}
