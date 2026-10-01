import { Alert, Button, Card, DatePicker, Descriptions, Flex, Form, Input, InputNumber, Modal, Select, Skeleton, Table } from 'antd';
import { ArrowLeftOutlined, CheckCircleTwoTone, PlusOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import type { ColumnsType } from 'antd/es/table';
import type { Dayjs } from 'dayjs';
import { useState } from 'react';
import { Route, Routes, useNavigate, useParams } from 'react-router-dom';
import { obter, obterPagina } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { useSessao } from '@/sessao/SessaoContexto';
import { dataApi, formatarData, formatarDataHora, formatarKz, formatarNumero } from '@/utilitarios/formatacao';
import { ModalMotivo, useAccao } from './accoes';
import { EstadoTag } from './estados';
import { ModalRececao } from './ModaisEncomenda';
import { NomeArmazem, NomeProduto, NomeTerceiro, useArmazens } from './referencias';
import { accoesRececao } from './regras';
import { SeletorArmazem } from './Seletores';
import { TabelaApi } from '@/componentes/TabelaApi';
import { numeroOuId, type EncomendaCompra, type LinhaRececao, type RececaoCompra } from './tipos';

export type ModoRececoes = 'compras' | 'armazem';

const ESTADOS_FILTRO = [
  { value: 'RECEBIDO', label: 'Por validar' },
  { value: 'VALIDADO', label: 'Validadas (em stock)' },
  { value: 'ANULADO', label: 'Anuladas' },
];

/**
 * Recepções de compras. Em Compras (compras_rececoes) regista-se a recepção a partir da encomenda; no Armazém
 * (armazem_rececoes) valida-se a entrada em stock (que também contabiliza), reverte-se a validação ou anula-se.
 */
export function EcraRececoes({ modo }: { modo: ModoRececoes }) {
  return (
    <Routes>
      <Route index element={<ListaRececoes modo={modo} />} />
      <Route path=":id" element={<DetalheRececao />} />
    </Routes>
  );
}

function ListaRececoes({ modo }: { modo: ModoRececoes }) {
  const navegar = useNavigate();
  const { pode } = useSessao();
  const [estado, setEstado] = useState<string | undefined>(modo === 'armazem' ? 'RECEBIDO' : undefined);
  const [encomenda, setEncomenda] = useState<number | null>(null);
  const [periodo, setPeriodo] = useState<[Dayjs | null, Dayjs | null] | null>(null);
  const [escolher, setEscolher] = useState(false);
  const [pesquisa, setPesquisa] = useState('');

  const colunas: ColumnsType<RececaoCompra> = [
    { title: 'Recepção', key: 'numero', fixed: 'left', render: (_, r) => <strong>{numeroOuId(r.numero_rececao, r.id)}</strong> },
    { title: 'Guia do fornecedor', dataIndex: 'numero_entrega', render: (v) => v || '—' },
    { title: 'Encomenda', dataIndex: 'encomenda_compra_id', render: (v: number) => `#${v}` },
    { title: 'Data', dataIndex: 'data', render: formatarData },
    { title: 'Armazém', dataIndex: 'armazem_id', render: (v: number | null) => <NomeArmazem id={v} /> },
    { title: 'Valor (Kz)', dataIndex: 'valor_total_kz', align: 'right', render: (v: string | null) => (v ? formatarKz(v) : '—') },
    { title: 'Estado', dataIndex: 'estado', render: (e: string, r) => <EstadoTag estado={e === 'RECEBIDO' && !r.validado ? 'PENDENTE' : e} /> },
    { title: 'Contab.', dataIndex: 'contabilizado', align: 'center', render: (c: boolean | null) => (c ? <CheckCircleTwoTone twoToneColor="#52c41a" /> : null) },
  ];

  return (
    <>
      <CabecalhoPagina
        titulo={modo === 'armazem' ? 'Validar entradas' : 'Recepções'}
        subtitulo={modo === 'armazem' ? 'Recepções de compras a validar: a validação dá entrada em stock e contabiliza' : 'Recepções de mercadoria das encomendas a fornecedores'}
        accoes={
          modo === 'compras' &&
          pode('compras_rec_registar') && (
            <Button type="primary" icon={<PlusOutlined />} onClick={() => setEscolher(true)}>
              Registar recepção
            </Button>
          )
        }
      />
      <Card>
        <Flex gap={8} wrap style={{ marginBottom: 16 }}>
          <Select placeholder="Estado" allowClear style={{ width: 220 }} value={estado} onChange={setEstado} options={ESTADOS_FILTRO} />
          <Input.Search placeholder="N.º da recepção ou guia" allowClear style={{ width: 230 }} onSearch={(v) => setPesquisa(v.trim())} />
          <InputNumber placeholder="N.º interno da encomenda" min={1} style={{ width: 220 }} value={encomenda} onChange={(v) => setEncomenda(v)} />
          <DatePicker.RangePicker format="DD/MM/YYYY" value={periodo} onChange={(v) => setPeriodo(v)} />
        </Flex>
        <TabelaApi<RececaoCompra>
          url="/compras/rececoes"
          chaveConsulta={['compras', 'rececoes']}
          filtros={{ estado, pesquisa: pesquisa || undefined, encomenda_compra_id: encomenda ?? undefined, data_inicio: dataApi(periodo?.[0]), data_fim: dataApi(periodo?.[1]) }}
          columns={colunas}
          onRow={(r) => ({ onClick: () => navegar(String(r.id)), style: { cursor: 'pointer' } })}
        />
      </Card>
      {escolher && <EscolherEncomenda aoFechar={() => setEscolher(false)} aoRegistar={(r) => navegar(String(r.id))} />}
    </>
  );
}

/** Escolha da encomenda a receber (em processamento ou parcial) e registo da recepção. */
function EscolherEncomenda({ aoFechar, aoRegistar }: { aoFechar: () => void; aoRegistar: (r: RececaoCompra) => void }) {
  const [id, setId] = useState<number>();
  const abertas = useQuery({
    queryKey: ['compras', 'encomendas', 'por-receber'],
    queryFn: async () => {
      const [a, b] = await Promise.all(['EM_PROCESSAMENTO', 'PARCIAL'].map((estado) => obterPagina<EncomendaCompra>('/compras/encomendas', { estado, por_pagina: 200 })));
      return [...a.itens, ...b.itens];
    },
  });
  const encomenda = useQuery({ queryKey: ['compras', 'encomenda', String(id)], queryFn: () => obter<EncomendaCompra>(`/compras/encomendas/${id}`), enabled: !!id });

  if (encomenda.data) return <ModalRececao encomenda={encomenda.data} aberto aoFechar={aoFechar} aoRegistar={aoRegistar} />;
  return (
    <Modal title="Registar recepção" open onCancel={aoFechar} footer={null}>
      <Select
        showSearch
        optionFilterProp="label"
        style={{ width: '100%' }}
        loading={abertas.isLoading || encomenda.isFetching}
        placeholder="Encomenda por receber"
        value={id}
        onChange={setId}
        options={(abertas.data ?? []).map((e) => ({ value: e.id, label: `${numeroOuId(e.numero_encomenda, e.id)} — ${e.fornecedor?.nome?.trim() ?? `fornecedor #${e.fornecedor_id}`} — ${formatarData(e.data)} — ${formatarKz(e.montante_total)} Kz` }))}
      />
    </Modal>
  );
}

export function DetalheRececao() {
  const { id } = useParams();
  const navegar = useNavigate();
  const { pode } = useSessao();
  const [modal, setModal] = useState<'validar' | 'reverter' | 'anular' | null>(null);
  const [form] = Form.useForm<{ armazem_id?: number }>();
  const armazens = useArmazens();
  const consulta = useQuery({ queryKey: ['compras', 'rececao', id], queryFn: () => obter<RececaoCompra>(`/compras/rececoes/${id}`) });
  const encomenda = useQuery({
    queryKey: ['compras', 'encomenda', String(consulta.data?.encomenda_compra_id)],
    queryFn: () => obter<EncomendaCompra>(`/compras/encomendas/${consulta.data?.encomenda_compra_id}`),
    enabled: !!consulta.data?.encomenda_compra_id,
  });
  const accao = useAccao<RececaoCompra>({ invalidar: [['compras'], ['logistica']], aoSucesso: () => setModal(null) });

  if (consulta.isLoading) return <Skeleton active />;
  const r = consulta.data;
  if (!r) return <Alert type="error" message="Recepção não encontrada." />;
  const a = accoesRececao(r, pode);
  const nome = numeroOuId(r.numero_rececao, r.id);
  const predefinido = armazens.data?.find((x) => x.predefinido)?.id;

  return (
    <>
      <CabecalhoPagina
        titulo={`Recepção ${nome}`}
        subtitulo={encomenda.data ? <>Encomenda {numeroOuId(encomenda.data.numero_encomenda, encomenda.data.id)} · <NomeTerceiro id={encomenda.data.fornecedor_id} terceiro={encomenda.data.fornecedor} /></> : `Encomenda #${r.encomenda_compra_id}`}
        accoes={
          <>
            <Button icon={<ArrowLeftOutlined />} onClick={() => navegar('..')}>Voltar</Button>
            {a.validar && (
              <Button type="primary" onClick={() => { form.setFieldsValue({ armazem_id: predefinido }); setModal('validar'); }}>
                Validar entrada em stock
              </Button>
            )}
            {a.reverter && <Button danger onClick={() => setModal('reverter')}>Reverter validação</Button>}
            {a.anular && <Button danger onClick={() => setModal('anular')}>Anular</Button>}
          </>
        }
      />
      {r.estado === 'ANULADO' && <Alert type="error" showIcon style={{ marginBottom: 16 }} message={`Recepção anulada${r.motivo_anulacao ? `: ${r.motivo_anulacao}` : '.'}`} />}
      <Card style={{ marginBottom: 16 }}>
        <Descriptions column={{ xs: 1, md: 3 }} size="small">
          <Descriptions.Item label="Guia do fornecedor">{r.numero_entrega || '—'}</Descriptions.Item>
          <Descriptions.Item label="Data">{formatarData(r.data)}</Descriptions.Item>
          <Descriptions.Item label="Estado"><EstadoTag estado={r.estado} /></Descriptions.Item>
          <Descriptions.Item label="Armazém"><NomeArmazem id={r.armazem_id} /></Descriptions.Item>
          <Descriptions.Item label="Validada">{r.validado ? `${r.validado_por ?? ''} ${formatarDataHora(r.validado_em)}`.trim() : 'Não'}</Descriptions.Item>
          <Descriptions.Item label="Contabilização">{r.contabilizado ? `Contabilizada${r.numero_lan_contabilizacao ? ` (${r.numero_lan_contabilizacao})` : ''}` : 'Por contabilizar'}</Descriptions.Item>
          <Descriptions.Item label="Valor (Kz)">{formatarKz(r.valor_total_kz)}</Descriptions.Item>
        </Descriptions>
      </Card>
      <Card title="Artigos recebidos">
        <Table<LinhaRececao>
          rowKey="id"
          size="small"
          pagination={false}
          dataSource={r.linhas ?? []}
          columns={[
            { title: 'Produto', render: (_, l) => <NomeProduto id={l.produto_id} produto={l.produto} /> },
            { title: 'Quantidade', dataIndex: 'quantidade', align: 'right', render: formatarNumero },
            { title: 'Custo unit. (Kz)', dataIndex: 'custo_unitario_kz', align: 'right', render: (v: string | null) => formatarKz(v) },
            { title: 'Valor (Kz)', dataIndex: 'valor_kz', align: 'right', render: (v: string | null) => formatarKz(v) },
          ]}
        />
        {r.linhas?.some((l) => !l.item_compra_id) && (
          <Alert style={{ marginTop: 12 }} type="warning" showIcon message="Recepção migrada do legado sem ligação às linhas da encomenda: a anulação e a reversão têm de ser feitas manualmente." />
        )}
      </Card>

      <Modal
        title={`Validar a recepção ${nome}`}
        open={modal === 'validar'}
        onCancel={() => setModal(null)}
        okText="Validar"
        cancelText="Cancelar"
        confirmLoading={accao.isPending}
        onOk={() => form.submit()}
      >
        <Form form={form} layout="vertical" onFinish={(v) => accao.mutate({ url: `/compras/rececoes/${r.id}/validar`, dados: { armazem_id: v.armazem_id } })}>
          <Alert type="info" showIcon style={{ marginBottom: 16 }} message="A validação dá entrada dos artigos de stock no armazém (custo médio) e regista o lançamento na contabilidade (compras/transitória e inventário)." />
          <Form.Item name="armazem_id" label="Armazém de entrada" tooltip="Vazio: armazém predefinido.">
            <SeletorArmazem allowClear />
          </Form.Item>
        </Form>
      </Modal>
      <ModalMotivo
        aberto={modal === 'reverter'}
        titulo={`Reverter a validação de ${nome}`}
        textoOk="Reverter"
        aviso="Dá saída do stock ao custo da entrada e estorna o lançamento. Recusado se a encomenda já tiver facturas."
        carregando={accao.isPending}
        aoFechar={() => setModal(null)}
        aoConfirmar={(motivo) => accao.mutate({ url: `/compras/rececoes/${r.id}/reverter-validacao`, dados: { motivo } })}
      />
      <ModalMotivo
        aberto={modal === 'anular'}
        titulo={`Anular a recepção ${nome}`}
        textoOk="Anular"
        aviso="As quantidades voltam a ficar por receber na encomenda."
        carregando={accao.isPending}
        aoFechar={() => setModal(null)}
        aoConfirmar={(motivo) => accao.mutate({ url: `/compras/rececoes/${r.id}/anular`, dados: { motivo } })}
      />
    </>
  );
}
