import { Alert, Button, Card, Col, DatePicker, Descriptions, Form, Input, Row, Skeleton, Space, Table, Typography } from 'antd';
import { ArrowLeftOutlined } from '@ant-design/icons';
import { useImpressao } from '@/componentes/impressao/useImpressao';
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
import { EstadoTag, rotuloEstado } from '@/modulos/compras/comum/estados';
import { LinhasProdutos, type LinhaProdutoForm } from '@/modulos/compras/comum/LinhasProdutos';
import { NomeArmazem, NomeProduto, NomeTerceiro, useArmazens } from '@/modulos/compras/comum/referencias';
import { SeletorArmazem } from '@/modulos/compras/comum/Seletores';
import { TabelaApi, type ColunaApi } from '@/componentes/TabelaApi';
import { accoesGuia, guiaGerida } from './comum/regras';
import { TIPOS_GUIA, type GuiaSaida } from './comum/tipos';
import { COLUNAS_DESCRICOES, scrollTabela, useEcraPequeno } from '@/componentes/responsivo';

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

/** «Destinatário / Entidade» como no legado: «CONSUMO: área» nas guias de consumo; senão o terceiro ou «N/D». */
function destinatario(g: GuiaSaida): string {
  if (g.tipo === 'CONSUMO') return `CONSUMO: ${g.area_rececao || '-'}`;
  return g.terceiro?.nome?.trim() || g.area_rececao || 'N/D';
}

function ListaGuias() {
  const navegar = useNavigate();
  const { pode } = useSessao();
  const tipo: string | undefined = undefined;
  const armazem: number | undefined = undefined;
  const pesquisa = '';
  const estado: string | undefined = undefined;
  const [modal, setModal] = useState<{ accao: 'anular' | 'descontabilizar'; guia: GuiaSaida } | null>(null);
  const pequeno = useEcraPequeno();
  const armazens = useArmazens();
  const nomeArmazem = (id: number | null | undefined) => (id ? armazens.data?.find((a) => a.id === id)?.nome ?? `#${id}` : '');
  const accao = useAccao<GuiaSaida>({ invalidar: [['logistica']], aoSucesso: () => setModal(null) });
  const { imprimir } = useImpressao();
  const imprimirGuia = async (id: number) => {
    const g = await obter<GuiaSaida>(`/logistica/guias-saida/${id}`);
    await imprimir(pedidoDocumentoComercial(dadosGuiaSaida(g, g.armazem_id ? nomeArmazem(g.armazem_id) : null)));
  };

  // Colunas e ordem do legado (renderGuiasSaida, ui_warehouse.js:566-571); o armazém fica disponível em «Colunas ▾».
  const colunas: ColunaApi<GuiaSaida>[] = [
    { title: 'Data', dataIndex: 'data', render: formatarData },
    { title: 'Nº Guia', dataIndex: 'numero_documento', render: (v: string) => <strong>{v}</strong> },
    { title: 'Tipo', key: 'tipo', valorImpressao: (r) => rotuloTipo(r), render: (_, r) => r.tipo }, // legado: o código do tipo em texto (VENDA, CONSUMO, BACK_TO_BACK)
    { title: 'Destinatário / Entidade', key: 'destino', valorImpressao: destinatario, render: (_, r) => (r.tipo !== 'CONSUMO' && !r.terceiro && r.terceiro_id ? <NomeTerceiro id={r.terceiro_id} terceiro={r.terceiro} /> : destinatario(r)) },
    { title: 'Armazém', dataIndex: 'armazem_id', ocultaPorOmissao: true, valorImpressao: (r) => nomeArmazem(r.armazem_id), render: (v: number | null) => <NomeArmazem id={v} /> },
    {
      title: 'Status',
      dataIndex: 'estado',
      valorImpressao: (r) => `${rotuloEstado(r.estado ?? 'CONCLUIDO')}${r.contabilizado ? ' · Contabilizada' : ''}`,
      render: (e: string | null, r) => (
        <>
          <Space size={8} wrap>
            {/* legado (ui_warehouse.js:582-583): «CONCLUIDO» e «CONTABILIZADA» em texto, em maiúsculas */}
            <span style={(e ?? 'CONCLUIDO') === 'ANULADA' ? { color: '#dc2626' } : undefined}>{(e ?? 'CONCLUIDO').toUpperCase()}</span>
            {r.contabilizado && <span>CONTABILIZADA</span>}
          </Space>
          {/* legado (ui_warehouse.js:584): o motivo da anulação por baixo, em letra pequena (30 caracteres; completo na dica) */}
          {r.motivo_anulacao && (
            <Typography.Text type="secondary" style={{ display: 'block', fontSize: '0.7rem' }} title={r.motivo_anulacao}>
              {r.motivo_anulacao.slice(0, 30)}
            </Typography.Text>
          )}
        </>
      ),
    },
  ];

  return (
    <>
      <Card
        title="Guias de Saída e Entrega"
      >
        {/* legado: sem barra de filtros (só os filtros por coluna); a emissão faz-se pelo «Emitir Guia de Saída» do cabeçalho */}
        <TabelaApi<GuiaSaida>
          url="/logistica/guias-saida"
          filtros={{ tipo, armazem_id: armazem, estado, pesquisa: pesquisa.trim() || undefined }}
          chaveConsulta={['logistica', 'guias']}
          columns={colunas}
          size={pequeno ? 'small' : 'middle'}
          accoes={(g) => {
            const a = accoesGuia(g, pode);
            const gerida = guiaGerida(g);
            const anulada = g.estado === 'ANULADA';
            return [
              { icone: 'print', titulo: 'Imprimir', onClick: () => imprimirGuia(g.id) },
              g.contabilizado
                ? { icone: 'undo', titulo: 'Descontabilizar', variante: 'perigo', oculto: !pode('armazem_guias_contab'), desactivado: !a.descontabilizar, onClick: () => setModal({ accao: 'descontabilizar', guia: g }) }
                : {
                    icone: 'book',
                    titulo: 'Contabilizar',
                    variante: 'primario',
                    oculto: !pode('armazem_guias_contab') || anulada,
                    desactivado: !a.contabilizar && (gerida ? true : 'Guia de venda: contabiliza-se pela guia de remessa nas Vendas'),
                    confirmar: `Contabilizar a guia ${g.numero_documento}?`,
                    onClick: () => accao.mutateAsync({ url: `/logistica/guias-saida/${g.id}/contabilizar` }),
                  },
              {
                icone: 'trash',
                titulo: 'Anular Guia',
                variante: 'perigo',
                cheio: true,
                oculto: !pode('armazem_guias_anular') || anulada,
                desactivado: !a.anular && (!gerida ? 'Guia de venda: anula-se pela guia de remessa nas Vendas' : g.contabilizado ? 'Descontabilize a guia antes de a anular' : true),
                onClick: () => setModal({ accao: 'anular', guia: g }),
              },
            ];
          }}
          // legado: tabela simples, sem a barra DataTables nem filtros por coluna
          contador={false}
          menuColunas={false}
          filtrosColuna={false}
          filtroPersonalizado={false}
          onRow={(r) => ({ onClick: () => navegar(String(r.id)), style: { cursor: 'pointer' } })}
        />
      </Card>
      <ModalMotivo
        aberto={modal?.accao === 'descontabilizar'}
        titulo={`Descontabilizar a guia ${modal?.guia.numero_documento ?? ''}`}
        textoOk="Descontabilizar"
        aviso="O lançamento do custo das mercadorias é estornado."
        carregando={accao.isPending}
        aoFechar={() => setModal(null)}
        aoConfirmar={(motivo) => modal && accao.mutate({ url: `/logistica/guias-saida/${modal.guia.id}/descontabilizar`, dados: { motivo } })}
      />
      <ModalMotivo
        aberto={modal?.accao === 'anular'}
        titulo={`Anular a guia ${modal?.guia.numero_documento ?? ''}`}
        textoOk="Anular"
        aviso="O stock é reposto no armazém ao custo da saída."
        carregando={accao.isPending}
        aoFechar={() => setModal(null)}
        aoConfirmar={(motivo) => modal && accao.mutate({ url: `/logistica/guias-saida/${modal.guia.id}/anular`, dados: { motivo } })}
      />
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
