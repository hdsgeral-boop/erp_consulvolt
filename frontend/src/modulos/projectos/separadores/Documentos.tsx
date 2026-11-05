import { Alert, Button, Card, DatePicker, Flex, Form, Input, Modal, Popconfirm, Select, Space, Table, Tag, Typography, Upload } from 'antd';
import { DeleteOutlined, DownloadOutlined, HistoryOutlined, InboxOutlined, PlusOutlined, UploadOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import type { UploadFile } from 'antd/es/upload/interface';
import dayjs, { type Dayjs } from 'dayjs';
import { useState } from 'react';
import { descarregar, obter } from '@/api/cliente';
import { useAccao } from '@/componentes/Accoes';
import { scrollTabela } from '@/componentes/responsivo';
import { useSessao } from '@/sessao/SessaoContexto';
import { dataApi, formatarData } from '@/utilitarios/formatacao';
import type { PropsSeparador } from '../DetalheProjecto';

export interface DocumentoProjecto {
  id: number;
  titulo: string | null;
  nome_ficheiro: string | null;
  tipo_projeto_id: number | null;
  versao: number;
  n_versoes?: number;
  data_documento: string | null;
  autor: string | null;
  observacoes: string | null;
  tamanho_bytes: number | null;
}

export interface TipoDocumentoProjecto { id: number; nome: string; ativo: boolean }

interface Resposta { documentos: DocumentoProjecto[]; tipos: TipoDocumentoProjecto[] }

interface ValoresEnvio { titulo?: string; tipo_projeto_id?: number; data_documento?: Dayjs; observacoes?: string }

/** Tamanho legível (B, KB, MB). */
export function tamanhoLegivel(bytes: number | null | undefined): string {
  if (!bytes) return '—';
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(0)} KB`;
  return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
}

/** O PDF é validado no servidor (conteúdo real); aqui só se evita o envio óbvio de outro tipo ou acima de 10 MB. */
export function erroDoFicheiro(f: { name: string; size?: number }): string | null {
  if (!/\.pdf$/i.test(f.name)) return 'Só são aceites ficheiros PDF.';
  if ((f.size ?? 0) > 10 * 1024 * 1024) return 'O ficheiro excede o máximo de 10 MB.';
  return null;
}

/**
 * Documentos do projecto: PDF com tipo, título, versão, data, autor e observações. Descarga pelo endpoint autenticado;
 * «nova versão» mantém o histórico; eliminar é lógico (fica na auditoria).
 */
export function SeparadorDocumentos({ projecto }: PropsSeparador) {
  const { pode } = useSessao();
  const podeEscrever = pode('proj_gerir', 'proj_execucao');
  const podeEliminar = pode('proj_eliminar', 'proj_gerir');
  const [tipo, setTipo] = useState<number>();
  const [pesquisa, setPesquisa] = useState('');
  const [envio, setEnvio] = useState<{ versaoDe?: DocumentoProjecto } | null>(null);
  const [versoes, setVersoes] = useState<DocumentoProjecto | null>(null);
  const [ficheiros, setFicheiros] = useState<UploadFile[]>([]);
  const [form] = Form.useForm<ValoresEnvio>();
  const base = `/projetos/${projecto.id}/documentos`;

  const q = useQuery({ queryKey: ['projectos', 'documentos', projecto.id, tipo, pesquisa], queryFn: () => obter<Resposta>(base, { tipo, pesquisa: pesquisa || undefined }) });
  const hist = useQuery({ queryKey: ['projectos', 'documentos', projecto.id, 'versoes', versoes?.id], enabled: versoes !== null, queryFn: () => obter<DocumentoProjecto[]>(`${base}/${versoes?.id}/versoes`) });
  const accao = useAccao({ invalidar: [['projectos', 'documentos']] });
  const tipos = q.data?.tipos ?? [];
  const nomeTipo = (id: number | null) => tipos.find((t) => t.id === id)?.nome ?? '—';

  const fechar = () => { setEnvio(null); setFicheiros([]); form.resetFields(); };
  const enviar = async (v: ValoresEnvio) => {
    const f = ficheiros[0]?.originFileObj;
    if (!f) return;
    const fd = new FormData();
    fd.append('ficheiro', f);
    if (v.titulo) fd.append('titulo', v.titulo);
    if (v.tipo_projeto_id) fd.append('tipo_projeto_id', String(v.tipo_projeto_id));
    if (v.data_documento) fd.append('data_documento', dataApi(v.data_documento) ?? '');
    if (v.observacoes) fd.append('observacoes', v.observacoes);
    await accao.mutateAsync({ url: envio?.versaoDe ? `${base}/${envio.versaoDe.id}/versoes` : base, dados: fd });
    fechar();
  };
  const baixar = (d: DocumentoProjecto) => descarregar(`${base}/${d.id}/ficheiro`, undefined, d.nome_ficheiro ?? 'documento.pdf');

  const colunas = [
    { title: 'Título', dataIndex: 'titulo', render: (v: string | null, d: DocumentoProjecto) => <Space orientation="vertical" size={0}><strong>{v ?? d.nome_ficheiro}</strong><Typography.Text type="secondary" style={{ fontSize: 12 }}>{d.nome_ficheiro}</Typography.Text></Space> },
    { title: 'Tipo', dataIndex: 'tipo_projeto_id', render: (v: number | null) => <Tag>{nomeTipo(v)}</Tag> },
    { title: 'Versão', dataIndex: 'versao', align: 'center' as const, render: (v: number) => `v${v}` },
    { title: 'Data', dataIndex: 'data_documento', responsive: ['md' as const], render: (v: string | null) => (v ? formatarData(v) : '—') },
    { title: 'Autor', dataIndex: 'autor', responsive: ['lg' as const], render: (v: string | null) => v ?? '—' },
    { title: 'Tamanho', dataIndex: 'tamanho_bytes', align: 'right' as const, responsive: ['lg' as const], render: (v: number | null) => tamanhoLegivel(v) },
    {
      title: '',
      key: 'accoes',
      render: (_: unknown, d: DocumentoProjecto) => (
        <Space size={4} wrap>
          <Button size="small" icon={<DownloadOutlined />} onClick={() => void baixar(d)}>Descarregar</Button>
          {(d.n_versoes ?? 1) > 1 && <Button size="small" icon={<HistoryOutlined />} onClick={() => setVersoes(d)}>{d.n_versoes} versões</Button>}
          {podeEscrever && <Button size="small" icon={<UploadOutlined />} onClick={() => setEnvio({ versaoDe: d })}>Nova versão</Button>}
          {podeEliminar && (
            <Popconfirm title="Eliminar o documento?" description="Eliminam-se todas as versões (fica registado na auditoria)." okText="Eliminar" cancelText="Cancelar" okButtonProps={{ danger: true }}
              onConfirm={() => accao.mutateAsync({ metodo: 'delete', url: `${base}/${d.id}?todas=1` })}>
              <Button size="small" danger icon={<DeleteOutlined />} aria-label="Eliminar" />
            </Popconfirm>
          )}
        </Space>
      ),
    },
  ];

  return (
    <Card>
      <Flex gap={8} wrap justify="space-between" style={{ marginBottom: 12 }}>
        <Space wrap>
          <Input.Search allowClear placeholder="Pesquisar título, ficheiro ou observações" style={{ width: 280, maxWidth: '100%' }} onSearch={setPesquisa} />
          <Select allowClear placeholder="Todos os tipos" style={{ minWidth: 180 }} value={tipo} onChange={setTipo} options={tipos.filter((t) => t.ativo).map((t) => ({ value: t.id, label: t.nome }))} />
        </Space>
        {podeEscrever && <Button type="primary" icon={<PlusOutlined />} onClick={() => setEnvio({})}>Carregar documento</Button>}
      </Flex>
      <Table<DocumentoProjecto> rowKey="id" size="small" loading={q.isFetching} dataSource={q.data?.documentos ?? []} columns={colunas} scroll={scrollTabela()} pagination={{ pageSize: 20, hideOnSinglePage: true }}
        locale={{ emptyText: 'Sem documentos neste projecto.' }} expandable={{ rowExpandable: (d) => Boolean(d.observacoes), expandedRowRender: (d) => <Typography.Text type="secondary">{d.observacoes}</Typography.Text> }} />

      <Modal open={envio !== null} title={envio?.versaoDe ? `Nova versão de «${envio.versaoDe.titulo ?? envio.versaoDe.nome_ficheiro}»` : 'Carregar documento (PDF)'} okText="Carregar" cancelText="Cancelar"
        okButtonProps={{ disabled: ficheiros.length === 0 }} confirmLoading={accao.isPending} onCancel={fechar} onOk={() => form.submit()} destroyOnHidden>
        <Alert type="info" showIcon style={{ marginBottom: 12 }} title="Só PDF, até 10 MB. O conteúdo é verificado (sem JavaScript nem acções automáticas)." />
        <Form form={form} layout="vertical" onFinish={(v) => void enviar(v)} initialValues={{ data_documento: dayjs() }}>
          <Form.Item label="Ficheiro" required>
            <Upload.Dragger accept=".pdf,application/pdf" maxCount={1} fileList={ficheiros} beforeUpload={(f) => { const e = erroDoFicheiro(f); if (e) { Modal.error({ title: e }); return Upload.LIST_IGNORE; } return false; }}
              onChange={({ fileList }) => setFicheiros(fileList.slice(-1))}>
              <p className="ant-upload-drag-icon"><InboxOutlined /></p>
              <p className="ant-upload-text">Arraste o PDF ou clique para escolher</p>
            </Upload.Dragger>
          </Form.Item>
          <Form.Item name="titulo" label="Título"><Input maxLength={200} placeholder={envio?.versaoDe ? 'Mantém o título actual se vazio' : 'Se vazio, usa o nome do ficheiro'} /></Form.Item>
          {!envio?.versaoDe && <Form.Item name="tipo_projeto_id" label="Tipo de documento"><Select allowClear options={tipos.filter((t) => t.ativo).map((t) => ({ value: t.id, label: t.nome }))} /></Form.Item>}
          <Form.Item name="data_documento" label="Data do documento"><DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} /></Form.Item>
          <Form.Item name="observacoes" label="Observações"><Input.TextArea rows={2} maxLength={2000} /></Form.Item>
        </Form>
      </Modal>

      <Modal open={versoes !== null} title={`Versões de «${versoes?.titulo ?? versoes?.nome_ficheiro ?? ''}»`} footer={null} onCancel={() => setVersoes(null)} destroyOnHidden>
        <Table<DocumentoProjecto> rowKey="id" size="small" loading={hist.isFetching} pagination={false} dataSource={hist.data ?? []} scroll={scrollTabela()} columns={[
          { title: 'Versão', dataIndex: 'versao', render: (v: number) => `v${v}` },
          { title: 'Data', dataIndex: 'data_documento', render: (v: string | null) => (v ? formatarData(v) : '—') },
          { title: 'Autor', dataIndex: 'autor', render: (v: string | null) => v ?? '—' },
          { title: 'Tamanho', dataIndex: 'tamanho_bytes', align: 'right', render: (v: number | null) => tamanhoLegivel(v) },
          { title: '', key: 'd', render: (_, d) => <Button size="small" icon={<DownloadOutlined />} onClick={() => void baixar(d)} aria-label="Descarregar" /> },
        ]} />
      </Modal>
    </Card>
  );
}
