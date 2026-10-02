import { Alert, Button, Card, Col, DatePicker, Descriptions, Form, Input, Row, Select, Skeleton, Space, Table, Tag } from 'antd';
import { ArrowLeftOutlined, CheckCircleTwoTone, PlusOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import { pedidoDocumentoComercial } from '@/modulos/vendas/impressao/documentoComercial';
import { dadosGuiaSaida } from './comum/impressao';
import dayjs, { type Dayjs } from 'dayjs';
import { useState } from 'react';
import { Route, Routes, useNavigate, useParams } from 'react-router-dom';
import { obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { useSessao } from '@/sessao/SessaoContexto';
import { dataApi, formatarData, formatarKz, formatarNumero } from '@/utilitarios/formatacao';
import { ModalMotivo, useAccao } from '@/componentes/Accoes';
import { EstadoTag, opcoesEstado, rotuloEstado } from '@/modulos/compras/comum/estados';
import { LinhasProdutos, type LinhaProdutoForm } from '@/modulos/compras/comum/LinhasProdutos';
import { NomeArmazem, NomeProduto, NomeTerceiro, useArmazens } from '@/modulos/compras/comum/referencias';
import { SeletorArmazem } from '@/modulos/compras/comum/Seletores';
import { TabelaApi, type ColunaApi } from '@/componentes/TabelaApi';
import { accoesGuia, guiaGerida } from './comum/regras';
import { TIPOS_GUIA, type GuiaSaida } from './comum/tipos';
import { BarraFiltros, COLUNAS_DESCRICOES, scrollTabela, useEcraPequeno } from '@/componentes/responsivo';

/** Armazém › Guias de saída (ecrã armazem_guias): consumo interno, vendas ao balcão e guias de venda migradas. */
export default function Guias() {
  return (
    <Routes>
      <Route index element={<ListaGuias />} />
      <Route path="novo" element={<EmitirGuia />} />
      <Route path=":id" element={<DetalheGuia />} />
    </Routes>
  );
}

function rotuloTipo(g: Pick<GuiaSaida, 'tipo' | 'tipo_original'>): string {
  if (g.tipo === 'VENDA' && g.tipo_original === 'VENDA_BALCAO') return TIPOS_GUIA.VENDA_BALCAO;
  return TIPOS_GUIA[g.tipo] ?? g.tipo;
}

function ListaGuias() {
  const navegar = useNavigate();
  const { pode } = useSessao();
  const [tipo, setTipo] = useState<string>();
  const [armazem, setArmazem] = useState<number>();
  const [pesquisa, setPesquisa] = useState('');
  const [estado, setEstado] = useState<string>();
  const pequeno = useEcraPequeno();
  const armazens = useArmazens();
  const nomeArmazem = (id: number | null | undefined) => (id ? armazens.data?.find((a) => a.id === id)?.nome ?? `#${id}` : '');

  const colunas: ColunaApi<GuiaSaida>[] = [
    { title: 'Guia', dataIndex: 'numero_documento', fixed: 'left', render: (v: string) => <strong>{v}</strong> },
    { title: 'Data', dataIndex: 'data', render: formatarData },
    { title: 'Tipo', key: 'tipo', responsive: ['sm'], valorImpressao: (r) => rotuloTipo(r), render: (_, r) => <Tag>{rotuloTipo(r)}</Tag> },
    { title: 'Armazém', dataIndex: 'armazem_id', responsive: ['md'], valorImpressao: (r) => nomeArmazem(r.armazem_id), render: (v: number | null) => <NomeArmazem id={v} /> },
    { title: 'Destino', key: 'destino', responsive: ['md'], valorImpressao: (r) => r.area_rececao || r.terceiro?.nome?.trim() || (r.terceiro_id ? `#${r.terceiro_id}` : ''), render: (_, r) => r.area_rececao || (r.terceiro_id ? <NomeTerceiro id={r.terceiro_id} terceiro={r.terceiro} /> : '—') },
    { title: 'Estado', dataIndex: 'estado', render: (e: string | null) => <EstadoTag estado={e} /> },
    { title: 'Contab.', dataIndex: 'contabilizado', align: 'center', responsive: ['lg'], valorImpressao: (r) => (r.contabilizado ? 'Sim' : 'Não'), render: (c: boolean | null) => (c ? <CheckCircleTwoTone twoToneColor="#52c41a" /> : null) },
  ];

  return (
    <>
      <CabecalhoPagina
        titulo="Guias de saída"
        subtitulo="Saídas de stock: consumo interno, vendas ao balcão e guias de venda"
        accoes={pode('armazem_guias_emitir') && <Button type="primary" icon={<PlusOutlined />} onClick={() => navegar('novo')}>Guia de consumo</Button>}
      />
      <Card>
        <BarraFiltros>
          <Input.Search placeholder="N.º ou destino" allowClear style={{ width: 240, maxWidth: '100%' }} onSearch={setPesquisa} />
          <Select placeholder="Tipo" allowClear style={{ width: 200, maxWidth: '100%' }} value={tipo} onChange={setTipo} options={['CONSUMO', 'VENDA', 'BACK_TO_BACK'].map((t) => ({ value: t, label: TIPOS_GUIA[t] }))} />
          <SeletorArmazem allowClear placeholder="Todos os armazéns" style={{ width: 220, maxWidth: '100%' }} value={armazem} onChange={setArmazem} />
          <Select placeholder="Estado" allowClear style={{ width: 170 }} value={estado} onChange={setEstado} options={opcoesEstado(['CONCLUIDO', 'ANULADA'])} />
        </BarraFiltros>
        <TabelaApi<GuiaSaida>
          url="/logistica/guias-saida"
          filtros={{ tipo, armazem_id: armazem, estado, pesquisa: pesquisa.trim() || undefined }}
          chaveConsulta={['logistica', 'guias']}
          columns={colunas}
          size={pequeno ? 'small' : 'middle'}
          impressao={{
            titulo: 'Lista de guias de saída',
            filtros: [tipo && `Tipo: ${TIPOS_GUIA[tipo] ?? tipo}`, !!armazem && `Armazém: ${nomeArmazem(armazem)}`, estado && `Estado: ${rotuloEstado(estado)}`, pesquisa.trim() && `Pesquisa: ${pesquisa.trim()}`],
          }}
          onRow={(r) => ({ onClick: () => navegar(String(r.id)), style: { cursor: 'pointer' } })}
        />
      </Card>
    </>
  );
}

function EmitirGuia() {
  const navegar = useNavigate();
  const [form] = Form.useForm<{ armazem_id: number; data: Dayjs; area_rececao: string; observacoes?: string; linhas: LinhaProdutoForm[] }>();
  const emitir = useAccao<GuiaSaida>({ invalidar: [['logistica']], aoSucesso: (g) => navegar(`../${g.id}`), tituloErro: 'Não foi possível emitir a guia' });
  return (
    <>
      <CabecalhoPagina titulo="Guia de consumo interno" accoes={<Button icon={<ArrowLeftOutlined />} onClick={() => navegar('..')}>Voltar</Button>} />
      <Form
        form={form}
        layout="vertical"
        initialValues={{ data: dayjs(), linhas: [{ quantidade: 1 }] }}
        onFinish={(v) =>
          emitir.mutate({
            url: '/logistica/guias-saida',
            dados: { armazem_id: v.armazem_id, data: dataApi(v.data), area_rececao: v.area_rececao, observacoes: v.observacoes || undefined, linhas: v.linhas.map((l) => ({ produto_id: l.produto_id, quantidade: l.quantidade })) },
          })
        }
      >
        <Card style={{ marginBottom: 16 }}>
          <Row gutter={16}>
            <Col xs={24} md={8}>
              <Form.Item name="armazem_id" label="Armazém" rules={[{ required: true, message: 'Escolha o armazém.' }]}>
                <SeletorArmazem />
              </Form.Item>
            </Col>
            <Col xs={24} md={6}>
              <Form.Item name="data" label="Data" rules={[{ required: true }]}>
                <DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} />
              </Form.Item>
            </Col>
            <Col xs={24} md={10}>
              <Form.Item name="area_rececao" label="Área / sector que recebe" rules={[{ required: true, message: 'Indique quem recebe o material.' }, { max: 255 }]}>
                <Input placeholder="Ex.: Manutenção, Obra X" />
              </Form.Item>
            </Col>
          </Row>
        </Card>
        <Card title="Artigos" style={{ marginBottom: 16 }}>
          <LinhasProdutos form={form} apenasStock />
          <Form.Item name="observacoes" label="Observações" style={{ marginTop: 16 }} rules={[{ max: 2000 }]}>
            <Input.TextArea rows={2} />
          </Form.Item>
        </Card>
        <Space wrap>
          <Button type="primary" htmlType="submit" loading={emitir.isPending}>Emitir guia</Button>
          <Button onClick={() => navegar('..')}>Cancelar</Button>
        </Space>
      </Form>
    </>
  );
}

function DetalheGuia() {
  const { id } = useParams();
  const navegar = useNavigate();
  const { pode } = useSessao();
  const [modal, setModal] = useState<'anular' | 'descontabilizar' | null>(null);
  const consulta = useQuery({ queryKey: ['logistica', 'guia', id], queryFn: () => obter<GuiaSaida>(`/logistica/guias-saida/${id}`) });
  const accao = useAccao<GuiaSaida>({ invalidar: [['logistica']], aoSucesso: () => setModal(null) });
  const armazens = useArmazens();

  if (consulta.isLoading) return <Skeleton active />;
  const g = consulta.data;
  if (!g) return <Alert type="error" message="Guia não encontrada." />;
  const a = accoesGuia(g, pode);

  return (
    <>
      <CabecalhoPagina
        titulo={`Guia ${g.numero_documento}`}
        subtitulo={rotuloTipo(g)}
        impressao={() => pedidoDocumentoComercial(dadosGuiaSaida(g, g.armazem_id ? armazens.data?.find((x) => x.id === g.armazem_id)?.nome ?? `#${g.armazem_id}` : null))}
        accoes={
          <>
            <Button icon={<ArrowLeftOutlined />} onClick={() => navegar('..')}>Voltar</Button>
            {a.contabilizar && (
              <Button type="primary" loading={accao.isPending} onClick={() => accao.mutate({ url: `/logistica/guias-saida/${g.id}/contabilizar` })}>
                Contabilizar
              </Button>
            )}
            {a.descontabilizar && <Button danger onClick={() => setModal('descontabilizar')}>Descontabilizar</Button>}
            {a.anular && <Button danger onClick={() => setModal('anular')}>Anular</Button>}
          </>
        }
      />
      {g.estado === 'ANULADA' && <Alert type="error" showIcon style={{ marginBottom: 16 }} message={`Guia anulada${g.motivo_anulacao ? `: ${g.motivo_anulacao}` : '.'}`} />}
      {!guiaGerida(g) && <Alert type="info" showIcon style={{ marginBottom: 16 }} message="Guia de venda: contabiliza-se e anula-se pela guia de remessa nas Vendas." />}
      <Card style={{ marginBottom: 16 }}>
        <Descriptions column={COLUNAS_DESCRICOES} size="small">
          <Descriptions.Item label="Data">{formatarData(g.data)}</Descriptions.Item>
          <Descriptions.Item label="Armazém"><NomeArmazem id={g.armazem_id} /></Descriptions.Item>
          <Descriptions.Item label="Estado"><EstadoTag estado={g.estado} /></Descriptions.Item>
          <Descriptions.Item label="Destino">{g.area_rececao || (g.terceiro_id ? <NomeTerceiro id={g.terceiro_id} terceiro={g.terceiro} /> : '—')}</Descriptions.Item>
          <Descriptions.Item label="Contabilização">{g.contabilizado ? `Contabilizada${g.numero_lan_contabilizacao ? ` (${g.numero_lan_contabilizacao})` : ''}` : 'Por contabilizar'}</Descriptions.Item>
          {g.criado_por && <Descriptions.Item label="Emitida por">{g.criado_por}</Descriptions.Item>}
          {g.observacoes && <Descriptions.Item label="Observações" span="filled">{g.observacoes}</Descriptions.Item>}
        </Descriptions>
      </Card>
      <Card title="Artigos">
        <Table scroll={scrollTabela()}
          rowKey="id"
          size="small"
          pagination={false}
          dataSource={g.linhas ?? []}
          columns={[
            { title: 'Produto', dataIndex: 'produto_id', render: (v: number, l: NonNullable<GuiaSaida['linhas']>[number]) => <NomeProduto id={v} produto={l.produto} /> },
            { title: 'Quantidade', dataIndex: 'quantidade', align: 'right', render: formatarNumero },
            { title: 'Custo unit. (Kz)', dataIndex: 'custo_unitario_kz', align: 'right', render: (v: string | null) => formatarKz(v) },
            { title: 'Valor (Kz)', dataIndex: 'valor_kz', align: 'right', render: (v: string | null) => formatarKz(v) },
          ]}
        />
      </Card>
      <ModalMotivo
        aberto={modal === 'descontabilizar'}
        titulo={`Descontabilizar a guia ${g.numero_documento}`}
        textoOk="Descontabilizar"
        aviso="O lançamento do custo das mercadorias é estornado."
        carregando={accao.isPending}
        aoFechar={() => setModal(null)}
        aoConfirmar={(motivo) => accao.mutate({ url: `/logistica/guias-saida/${g.id}/descontabilizar`, dados: { motivo } })}
      />
      <ModalMotivo
        aberto={modal === 'anular'}
        titulo={`Anular a guia ${g.numero_documento}`}
        textoOk="Anular"
        aviso="O stock é reposto no armazém ao custo da saída."
        carregando={accao.isPending}
        aoFechar={() => setModal(null)}
        aoConfirmar={(motivo) => accao.mutate({ url: `/logistica/guias-saida/${g.id}/anular`, dados: { motivo } })}
      />
    </>
  );
}
