import { Alert, Button, Card, Descriptions, Form, Input, Modal, Skeleton, Table, Tag, Typography, message } from 'antd';
import { ArrowLeftOutlined, CopyOutlined, EditOutlined } from '@ant-design/icons';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { enviar, obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { useSessao } from '@/sessao/SessaoContexto';
import { notificarErro } from '@/utilitarios/erros';
import { formatarData, formatarDataHora, formatarKz } from '@/utilitarios/formatacao';
import { EtiquetaEstado, ValorKz } from '../../contab/comum/Componentes';
import { ROTULO_TIPO, type DocumentoTesouraria, type LinhaDocumentoTesouraria } from '../api';
import { accoesDocumento } from '../regras';
import { COLUNAS_DESCRICOES, larguraModal, scrollTabela } from '@/componentes/responsivo';
import { pedidoDuasVias } from '@/modulos/vendas/impressao/documentoRecibo';
import { dadosDocumentoTesouraria } from '../impressao';

/** Detalhe de um documento de tesouraria, com editar, anular, integrar e desintegrar conforme o estado e as permissões. */
export function DetalheDocumento({ permitirEdicao = true }: { permitirEdicao?: boolean }) {
  const { id } = useParams();
  const navegar = useNavigate();
  const { pode } = useSessao();
  const cliente = useQueryClient();
  const [motivo, setMotivo] = useState<'anular' | 'desintegrar' | null>(null);
  const [form] = Form.useForm<{ motivo: string }>();
  const consulta = useQuery({ queryKey: ['teso', 'documento', id], queryFn: () => obter<DocumentoTesouraria>(`/tesouraria/documentos/${id}`) });
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

  if (consulta.isLoading) return <Skeleton active />;
  const d = consulta.data;
  if (!d) return <Alert type="error" message="Documento não encontrado." />;
  const a = accoesDocumento(d, pode);

  return (
    <>
      <CabecalhoPagina
        titulo={d.numero_documento ?? `Documento #${d.id}`}
        subtitulo={ROTULO_TIPO[d.tipo]}
        impressao={() => pedidoDuasVias(dadosDocumentoTesouraria(d))}
        accoes={
          <>
            <Button icon={<ArrowLeftOutlined />} onClick={() => navegar('..')}>Voltar</Button>
            {permitirEdicao && a.podeEditar && <Button icon={<EditOutlined />} onClick={() => navegar('editar')}>Editar</Button>}
            {permitirEdicao && pode('teso_doc_emitir') && <Button icon={<CopyOutlined />} onClick={() => navegar(`../novo?tipo=${d.tipo}&copiar=${d.id}`)}>Copiar</Button>}
            {a.podeIntegrar && (
              <Button type="primary" loading={accao.isPending} onClick={() => Modal.confirm({ title: `Integrar ${d.numero_documento ?? 'o documento'} na contabilidade?`, content: 'É gerado o lançamento no diário de bancos/caixa.', okText: 'Integrar', cancelText: 'Cancelar', onOk: () => accao.mutateAsync({ caminho: 'integrar' }) })}>
                Integrar
              </Button>
            )}
            {a.podeDesintegrar && <Button danger onClick={() => setMotivo('desintegrar')}>Anular integração</Button>}
            {a.podeAnular && <Button danger onClick={() => setMotivo('anular')}>Anular</Button>}
          </>
        }
      />
      {d.estado === 'ANULADO' && <Alert type="error" showIcon style={{ marginBottom: 16 }} message={`Documento anulado${d.anulado_em ? ` em ${formatarDataHora(d.anulado_em)}` : ''}.`} description={d.motivo_anulacao ?? undefined} />}
      {d.periodo_processamento_salarial_id && <Alert type="info" showIcon style={{ marginBottom: 16 }} message="Documento gerado pelo processamento salarial." />}
      <Card style={{ marginBottom: 16 }}>
        <Descriptions column={COLUNAS_DESCRICOES} size="small">
          <Descriptions.Item label="Data">{formatarData(d.data_documento)}</Descriptions.Item>
          <Descriptions.Item label="Conta de banco/caixa">{d.conta_financeira}</Descriptions.Item>
          <Descriptions.Item label="Estado"><EtiquetaEstado estado={d.estado} /></Descriptions.Item>
          <Descriptions.Item label="Valor">{formatarKz(d.valor_total, true)}</Descriptions.Item>
          {d.codigo_moeda && d.codigo_moeda !== 'AOA' && <Descriptions.Item label="Em moeda">{d.valor_total_moeda} {d.codigo_moeda} (câmbio {d.taxa_cambio})</Descriptions.Item>}
          <Descriptions.Item label="Referência">{d.referencia ?? '—'}</Descriptions.Item>
          <Descriptions.Item label="Integração">{d.numero_lan_contabilizacao ? `${d.numero_lan_contabilizacao} · ${formatarDataHora(d.integrado_em)} · ${d.integrado_por ?? ''}` : 'Por integrar'}</Descriptions.Item>
          {d.reconciliacao_codigo && <Descriptions.Item label="Reconciliação"><Tag color="blue">{d.reconciliacao_codigo}</Tag></Descriptions.Item>}
          <Descriptions.Item label="Descrição" span="filled">{d.descricao ?? '—'}</Descriptions.Item>
        </Descriptions>
      </Card>
      <Card title="Linhas">
        <Table<LinhaDocumentoTesouraria>
          rowKey="id"
          size="small"
          pagination={false}
          dataSource={d.linhas ?? []}
          scroll={scrollTabela()}
          columns={[
            { title: 'Conta', dataIndex: 'codigo_conta', render: (v: string) => <strong>{v}</strong> },
            { title: 'Terceiro', key: 'terceiro', render: (_, l) => (l.terceiro ? `${l.terceiro.nome.trim()}${l.terceiro.nif ? ` (NIF ${l.terceiro.nif})` : ''}` : l.terceiro_id ? `#${l.terceiro_id}` : '—') },
            { title: 'Documento liquidado', dataIndex: 'numero_documento', responsive: ['sm'], render: (v: string | null) => v ?? '—' },
            { title: 'Descrição', dataIndex: 'descricao', responsive: ['md'] },
            { title: 'Débito', align: 'right', render: (_, l) => (l.tipo_dc === 'D' ? <ValorKz valor={l.valor} /> : null) },
            { title: 'Crédito', align: 'right', render: (_, l) => (l.tipo_dc === 'C' ? <ValorKz valor={l.valor} /> : null) },
            { title: 'Moeda', render: (_, l) => (l.codigo_moeda ? `${l.valor_moeda} ${l.codigo_moeda}` : '') },
          ]}
        />
      </Card>
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
    </>
  );
}
