import { Alert, Button, Card, Descriptions, Flex, Form, Input, Modal, Skeleton, Space, Table, Tag, Typography, message } from 'antd';
import { ArrowLeftOutlined, CopyOutlined, EditOutlined } from '@ant-design/icons';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState, type ReactNode } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { enviar, obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { IconeFa } from '@/componentes/fluxos/IconeFa';
import { BotoesExportar } from '@/componentes/impressao/BotoesExportar';
import { useSessao } from '@/sessao/SessaoContexto';
import { notificarErro } from '@/utilitarios/erros';
import { formatarData, formatarDataHora, formatarKz } from '@/utilitarios/formatacao';
import { somar } from '@/utilitarios/decimal';
import { EtiquetaEstado, ValorKz } from '../../contab/comum/Componentes';
import { ROTULO_TIPO, type DocumentoTesouraria, type LinhaDocumentoTesouraria } from '../api';
import { accoesDocumento } from '../regras';
import { urlSegura } from '../colunasLegado';
import { COLUNAS_DESCRICOES, larguraModal, scrollTabela } from '@/componentes/responsivo';
import { pedidoDuasVias } from '@/modulos/vendas/impressao/documentoRecibo';
import { dadosDocumentoTesouraria } from '../impressao';

/** Texto do período salarial no detalhe (como a opção do formulário do legado: «05/2026 (integrado)»). */
export function rotuloPeriodoSalarial(p: DocumentoTesouraria['periodo_salarial']): string | null {
  if (!p) return null;
  return `${p.mes_ano}${p.contabilizado ? ' (integrado)' : p.estado ? ` (${p.estado.toLowerCase()})` : ''}`;
}

interface Props {
  permitirEdicao?: boolean;
  /** id do documento (no modal); na página vem da rota (:id) */
  id?: number | string;
  /**
   * Abre em modal (viewTesoMovementDetail do legado, ui_tesouraria.js:3693, abre numa janela sobre a lista). A rota de
   * página (…/:id) continua para as ligações directas.
   */
  emModal?: boolean;
  aoFechar?: () => void;
}

/** Detalhe de um documento de tesouraria, com editar, anular, integrar e desintegrar conforme o estado e as permissões. */
export function DetalheDocumento({ permitirEdicao = true, id: idProp, emModal = false, aoFechar }: Props) {
  const params = useParams();
  const id = idProp !== undefined ? String(idProp) : params.id;
  const navegar = useNavigate();
  const { pode } = useSessao();
  const cliente = useQueryClient();
  const [motivo, setMotivo] = useState<'anular' | 'desintegrar' | null>(null);
  const [form] = Form.useForm<{ motivo: string }>();
  const consulta = useQuery({ queryKey: ['teso', 'documento', id], queryFn: () => obter<DocumentoTesouraria>(`/tesouraria/documentos/${id}`), enabled: !!id });
  const accao = useMutation({
    mutationFn: ({ caminho, dados }: { caminho: string; dados?: unknown }) => enviar<DocumentoTesouraria>('post', `/tesouraria/documentos/${id}/${caminho}`, dados),
    onSuccess: ({ mensagem }) => {
      message.success(mensagem);
      setMotivo(null);
      form.resetFields();
      void cliente.invalidateQueries({ queryKey: ['teso'] });
      void cliente.invalidateQueries({ queryKey: ['contab'] });
    },
    onError: (e) => notificarErro(e),
  });

  const d = consulta.data;
  // caminhos relativos: na página estamos em …/:id; no modal, na lista (…/)
  const caminho = (resto: string) => (emModal ? resto : `../${resto}`);

  const accoes = (doc: DocumentoTesouraria): ReactNode => {
    const a = accoesDocumento(doc, pode);
    return (
      <>
        {permitirEdicao && a.podeEditar && <Button icon={<EditOutlined />} onClick={() => navegar(caminho(`${doc.id}/editar`))}>Editar</Button>}
        {permitirEdicao && pode('teso_doc_emitir') && <Button icon={<CopyOutlined />} onClick={() => navegar(caminho(`novo?tipo=${doc.tipo}&copiar=${doc.id}`))}>Copiar</Button>}
        {a.podeIntegrar && (
          <Button type="primary" loading={accao.isPending} onClick={() => Modal.confirm({ title: `Integrar ${doc.numero_documento ?? 'o documento'} na contabilidade?`, content: 'É gerado o lançamento no diário de bancos/caixa.', okText: 'Integrar', cancelText: 'Cancelar', onOk: () => accao.mutateAsync({ caminho: 'integrar' }) })}>
            Integrar
          </Button>
        )}
        {a.podeDesintegrar && <Button danger onClick={() => setMotivo('desintegrar')}>Anular integração</Button>}
        {a.podeAnular && <Button danger onClick={() => setMotivo('anular')}>Anular</Button>}
      </>
    );
  };

  const corpo = (doc: DocumentoTesouraria) => {
    const url = urlSegura(doc.url_documento);
    const periodo = rotuloPeriodoSalarial(doc.periodo_salarial);
    const notas = (doc.linhas ?? []).some((l) => l.nota_demonstracao_id || l.nota_fluxo_caixa_id);
    const nota = (mapa: Record<string, string> | undefined, v: number | null) => (v ? mapa?.[String(v)] ?? `#${v}` : '-');
    return (
      <>
        {doc.estado === 'ANULADO' && <Alert type="error" showIcon style={{ marginBottom: 16 }} message={`Documento anulado${doc.anulado_em ? ` em ${formatarDataHora(doc.anulado_em)}` : ''}.`} description={doc.motivo_anulacao ?? undefined} />}
        {doc.recibo_venda_id ? (
          <Alert type="info" showIcon style={{ marginBottom: 16 }} message={`Recebimento gerado pelo recibo de venda ${doc.numero_documento ?? ''}`.trim()}
            description="Acompanha o recibo: integrar contabiliza o recibo (um só lançamento) e anular a integração descontabiliza-o; altere ou anule o recibo em Vendas › Recibos." />
        ) : null}
        <Card style={{ marginBottom: 16 }}>
          <Descriptions column={COLUNAS_DESCRICOES} size="small">
            <Descriptions.Item label="Tipo / Natureza"><strong style={{ color: doc.tipo === 'PAGAMENTO' ? '#dc2626' : '#059669' }}>{doc.tipo}</strong></Descriptions.Item>
            <Descriptions.Item label="Data">{formatarData(doc.data_documento)}</Descriptions.Item>
            <Descriptions.Item label="Conta de banco/caixa">{doc.conta_financeira}</Descriptions.Item>
            <Descriptions.Item label="Estado"><EtiquetaEstado estado={doc.estado} /></Descriptions.Item>
            <Descriptions.Item label="Valor">{formatarKz(doc.valor_total, true)}</Descriptions.Item>
            {doc.codigo_moeda && doc.codigo_moeda !== 'AOA' && <Descriptions.Item label="Em moeda">{doc.valor_total_moeda} {doc.codigo_moeda} (câmbio {doc.taxa_cambio})</Descriptions.Item>}
            <Descriptions.Item label="Referência">{doc.referencia ?? '—'}</Descriptions.Item>
            <Descriptions.Item label="Projecto/Obra">{doc.projeto ? `${doc.projeto.codigo ?? ''} - ${doc.projeto.nome ?? ''}` : doc.codigo_projeto ?? '—'}</Descriptions.Item>
            {doc.tipo === 'PAGAMENTO' && <Descriptions.Item label="Período salarial">{periodo ?? '—'}</Descriptions.Item>}
            <Descriptions.Item label="Integração">{doc.numero_lan_contabilizacao ? `${doc.numero_lan_contabilizacao} · ${formatarDataHora(doc.integrado_em)} · ${doc.integrado_por ?? ''}` : 'Por integrar'}</Descriptions.Item>
            {doc.reconciliacao_codigo && <Descriptions.Item label="Reconciliação"><Tag color="blue">{doc.reconciliacao_codigo}</Tag></Descriptions.Item>}
            <Descriptions.Item label="Descrição" span="filled">{doc.descricao ?? '—'}</Descriptions.Item>
          </Descriptions>
        </Card>
        {/* «Documentos Externos» do legado (viewTesoMovementDetail): botão para o URL do documento (só http/https) */}
        {url && (
          <Flex wrap gap={8} align="center" style={{ marginBottom: 16, padding: '10px 16px', background: '#f0f9ff', border: '1px solid #bae6fd', borderRadius: 10 }}>
            <Typography.Text strong style={{ color: '#0369a1', fontSize: 12, textTransform: 'uppercase', letterSpacing: '0.05em' }}><IconeFa nome="link" /> Documentos Externos:</Typography.Text>
            <Button type="primary" size="small" href={url} target="_blank" rel="noopener noreferrer" icon={<IconeFa nome="external-link-alt" />} style={{ background: '#0284c7' }}>Documento de Tesouraria</Button>
          </Flex>
        )}
        <Card title="Linhas">
          <Table<LinhaDocumentoTesouraria>
            rowKey="id"
            size="small"
            pagination={false}
            dataSource={doc.linhas ?? []}
            scroll={scrollTabela()}
            columns={[
              { title: 'Conta', dataIndex: 'codigo_conta', render: (v: string) => <strong>{v}</strong> },
              { title: 'Terceiro', key: 'terceiro', render: (_, l) => (l.terceiro ? `${l.terceiro.nome.trim()}${l.terceiro.nif ? ` (NIF ${l.terceiro.nif})` : ''}` : l.terceiro_id ? `#${l.terceiro_id}` : '—') },
              { title: 'Documento liquidado', dataIndex: 'numero_documento', render: (v: string | null) => v ?? '—' },
              { title: 'Descrição', dataIndex: 'descricao' },
              { title: 'Débito', align: 'right', render: (_, l) => (l.tipo_dc === 'D' ? <ValorKz valor={l.valor} /> : null) },
              { title: 'Crédito', align: 'right', render: (_, l) => (l.tipo_dc === 'C' ? <ValorKz valor={l.valor} /> : null) },
              { title: 'Moeda', render: (_, l) => (l.codigo_moeda ? `${l.valor_moeda} ${l.codigo_moeda}` : '') },
              // «Notas (D/F)» do legado: Nota Demo / Nota Fluxo (só quando alguma linha as tem)
              ...(notas ? [{ title: 'Notas (D/F)', key: 'notas', render: (_: unknown, l: LinhaDocumentoTesouraria) => `${nota(doc.notas_demonstracao, l.nota_demonstracao_id)} / ${nota(doc.notas_fluxo_caixa, l.nota_fluxo_caixa_id)}` }] : []),
            ]}
            // linha «TOTAIS:» do detalhe do legado (viewTesoMovementDetail, ui_tesouraria.js:3809-3815)
            summary={(ls) => (
              <Table.Summary.Row>
                <Table.Summary.Cell index={0} colSpan={4} align="right">TOTAIS:</Table.Summary.Cell>
                <Table.Summary.Cell index={4} align="right">{formatarKz(somar(ls.filter((l) => l.tipo_dc === 'D').map((l) => l.valor)))}</Table.Summary.Cell>
                <Table.Summary.Cell index={5} align="right">{formatarKz(somar(ls.filter((l) => l.tipo_dc === 'C').map((l) => l.valor)))}</Table.Summary.Cell>
                <Table.Summary.Cell index={6} colSpan={notas ? 2 : 1} />
              </Table.Summary.Row>
            )}
          />
        </Card>
      </>
    );
  };

  const modalMotivo = (
    <Modal
      title={motivo === 'anular' ? 'Anular documento' : 'Anular integração (estorno)'}
      open={motivo !== null}
      width={larguraModal(520)}
      onCancel={() => setMotivo(null)}
      okText={motivo === 'anular' ? 'Anular' : 'Anular integração'}
      okButtonProps={{ danger: true }}
      confirmLoading={accao.isPending}
      onOk={() => form.submit()}
    >
      <Form form={form} layout="vertical" onFinish={(v) => motivo && accao.mutate({ caminho: motivo, dados: v })}>
        <Typography.Paragraph type="secondary">
          {motivo === 'anular' ? 'O documento fica anulado (não integrado).' : 'O lançamento da integração é estornado e o documento volta a «por integrar».'}
        </Typography.Paragraph>
        <Form.Item name="motivo" label="Motivo" rules={[{ required: true, min: 5, message: 'Indique o motivo (pelo menos 5 caracteres).' }]}>
          <Input.TextArea rows={3} maxLength={500} />
        </Form.Item>
      </Form>
    </Modal>
  );

  if (emModal) {
    return (
      <Modal
        open
        width={larguraModal(900)}
        onCancel={aoFechar}
        destroyOnHidden
        title={
          <Space wrap>
            <IconeFa nome="file-invoice" />
            {d ? `${d.numero_documento ?? `Documento #${d.id}`} — ${ROTULO_TIPO[d.tipo]}` : 'Documento de tesouraria'}
          </Space>
        }
        footer={
          <Space wrap style={{ justifyContent: 'flex-end' }}>
            {d && <BotoesExportar obterPedido={() => pedidoDuasVias(dadosDocumentoTesouraria(d))} />}
            {d && accoes(d)}
            <Button onClick={aoFechar}>Fechar</Button>
          </Space>
        }
      >
        {consulta.isLoading ? <Skeleton active /> : d ? corpo(d) : <Alert type="error" message="Documento não encontrado." />}
        {modalMotivo}
      </Modal>
    );
  }

  if (consulta.isLoading) return <Skeleton active />;
  if (!d) return <Alert type="error" message="Documento não encontrado." />;

  return (
    <>
      <CabecalhoPagina
        titulo={d.numero_documento ?? `Documento #${d.id}`}
        subtitulo={ROTULO_TIPO[d.tipo]}
        impressao={() => pedidoDuasVias(dadosDocumentoTesouraria(d))}
        accoes={
          <>
            <Button icon={<ArrowLeftOutlined />} onClick={() => navegar('..')}>Voltar</Button>
            {accoes(d)}
          </>
        }
      />
      {corpo(d)}
      {modalMotivo}
    </>
  );
}
