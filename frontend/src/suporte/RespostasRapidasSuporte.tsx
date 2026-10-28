import { Button, Card, Form, Input, Modal, Popconfirm, Space, Table } from 'antd';
import { ArrowLeftOutlined, DeleteOutlined, EditOutlined, PlusOutlined } from '@ant-design/icons';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { enviar, obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { notificarErro } from '@/utilitarios/erros';

interface RespostaRapida { id: number; titulo: string; texto: string }

const CHAVE = ['suporte_agente', 'respostas-rapidas'];

/** Catálogo de respostas pré-definidas ("macros") para a equipa de suporte inserir rapidamente numa conversa. */
export default function RespostasRapidasSuporte() {
  const navegar = useNavigate();
  const qc = useQueryClient();
  const [editar, setEditar] = useState<RespostaRapida | null>(null);
  const [novo, setNovo] = useState(false);
  const [form] = Form.useForm<{ titulo: string; texto: string }>();
  const lista = useQuery({ queryKey: CHAVE, queryFn: () => obter<RespostaRapida[]>('/suporte/respostas-rapidas') });
  const refrescar = () => qc.invalidateQueries({ queryKey: CHAVE });
  const gravar = useMutation({
    mutationFn: (v: { titulo: string; texto: string }) => (editar ? enviar('put', `/suporte/respostas-rapidas/${editar.id}`, v) : enviar('post', '/suporte/respostas-rapidas', v)),
    onSuccess: () => { setNovo(false); setEditar(null); refrescar(); },
    onError: (e) => notificarErro(e),
  });
  const apagar = useMutation({ mutationFn: (id: number) => enviar('delete', `/suporte/respostas-rapidas/${id}`), onSuccess: refrescar, onError: (e) => notificarErro(e) });

  const abrirModal = (r?: RespostaRapida) => {
    setEditar(r ?? null);
    form.setFieldsValue(r ?? { titulo: '', texto: '' });
    setNovo(true);
  };

  return (
    <>
      <CabecalhoPagina
        titulo="Respostas rápidas"
        subtitulo="Catálogo de respostas pré-definidas para a conversa com o cliente"
        accoes={
          <Space>
            <Button icon={<ArrowLeftOutlined />} onClick={() => navegar('..')}>Voltar</Button>
            <Button type="primary" icon={<PlusOutlined />} onClick={() => abrirModal()}>Nova resposta</Button>
          </Space>
        }
      />
      <Card>
        <Table<RespostaRapida> rowKey="id" size="small" loading={lista.isLoading} dataSource={lista.data ?? []}
          columns={[
            { title: 'Título', dataIndex: 'titulo', width: 260 },
            { title: 'Texto', dataIndex: 'texto', ellipsis: true },
            {
              title: '', align: 'right', width: 110, render: (_, r) => (
                <Space>
                  <Button size="small" icon={<EditOutlined />} onClick={() => abrirModal(r)} />
                  <Popconfirm title="Eliminar esta resposta?" okText="Eliminar" cancelText="Cancelar" onConfirm={() => apagar.mutate(r.id)}>
                    <Button size="small" danger icon={<DeleteOutlined />} />
                  </Popconfirm>
                </Space>
              ),
            },
          ]} />
      </Card>
      <Modal
        open={novo}
        title={editar ? 'Editar resposta rápida' : 'Nova resposta rápida'}
        okText="Gravar"
        cancelText="Cancelar"
        confirmLoading={gravar.isPending}
        onCancel={() => setNovo(false)}
        destroyOnHidden
        onOk={() => form.validateFields().then((v) => gravar.mutate(v))}
      >
        <Form form={form} layout="vertical">
          <Form.Item name="titulo" label="Título" rules={[{ required: true, message: 'Indique o título.' }]}><Input maxLength={150} /></Form.Item>
          <Form.Item name="texto" label="Texto" rules={[{ required: true, message: 'Indique o texto.' }]}><Input.TextArea rows={5} maxLength={5000} /></Form.Item>
        </Form>
      </Modal>
    </>
  );
}
