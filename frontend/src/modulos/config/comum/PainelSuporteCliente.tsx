import { Button, Card, Form, Input, Modal, Rate, Select, Space, Table, Tag, Typography, message } from 'antd';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { enviar, obter } from '@/api/cliente';
import { notificarErro } from '@/utilitarios/erros';
import { formatarDataHora } from '@/utilitarios/formatacao';
import { ConversaSuporte } from './ConversaSuporte';

interface TicketCliente {
  id: number;
  assunto: string;
  estado: string;
  categoria: string;
  criado_em: string;
  csat_nota: number | null;
  pode_avaliar: boolean;
}

/**
 * Separador "Suporte" do lado do cliente — pedidos de ticket com conversa, anexos e avaliação (CSAT). Partilhado
 * por "Minha conta" (`/minha-conta/suporte`, só o administrador) e "Licença e plano" (`/licenca/suporte`, qualquer
 * utilizador da empresa): mesmos tickets (por tenant_id), só com porta de entrada diferente.
 */
export function PainelSuporteCliente({ base, anexosBase }: { base: string; anexosBase: string }) {
  const qc = useQueryClient();
  const [novo, setNovo] = useState(false);
  const [conversa, setConversa] = useState<TicketCliente | null>(null);
  const [avaliar, setAvaliar] = useState<TicketCliente | null>(null);
  const [nota, setNota] = useState(5);
  const [comentario, setComentario] = useState('');
  const [form] = Form.useForm();
  const tickets = useQuery({ queryKey: [base], queryFn: () => obter<TicketCliente[]>(base) });
  const abrir = useMutation({
    mutationFn: (v: Record<string, unknown>) => enviar('post', base, v),
    onSuccess: () => {
      message.success('Pedido aberto.');
      setNovo(false);
      qc.invalidateQueries({ queryKey: [base] });
    },
    onError: (e) => notificarErro(e),
  });
  const avaliarAccao = useMutation({
    mutationFn: () => enviar('post', `${base}/${avaliar?.id}/avaliacao`, { nota, comentario: comentario.trim() || undefined }),
    onSuccess: () => {
      message.success('Obrigado pela sua avaliação.');
      setAvaliar(null);
      qc.invalidateQueries({ queryKey: [base] });
    },
    onError: (e) => notificarErro(e),
  });

  return (
    <>
      <Card extra={<Button type="primary" onClick={() => { form.resetFields(); setNovo(true); }}>Novo pedido</Button>}>
        <Table<TicketCliente>
          rowKey="id"
          size="small"
          loading={tickets.isLoading}
          dataSource={tickets.data ?? []}
          columns={[
            { title: 'Assunto', dataIndex: 'assunto' },
            { title: 'Estado', render: (_, t) => <Tag>{t.estado}</Tag> },
            { title: 'Aberto em', render: (_, t) => formatarDataHora(t.criado_em) },
            {
              title: 'Avaliação',
              render: (_, t) =>
                t.csat_nota ? (
                  <Rate disabled value={t.csat_nota} style={{ fontSize: 14 }} />
                ) : t.pode_avaliar ? (
                  <Button size="small" onClick={() => { setNota(5); setComentario(''); setAvaliar(t); }}>
                    Avaliar
                  </Button>
                ) : (
                  '—'
                ),
            },
            { title: '', render: (_, t) => <Button size="small" onClick={() => setConversa(t)}>Conversa</Button> },
          ]}
        />
      </Card>

      <Modal
        open={novo}
        title="Novo pedido de suporte"
        okText="Enviar"
        confirmLoading={abrir.isPending}
        onCancel={() => setNovo(false)}
        destroyOnClose
        onOk={() => form.validateFields().then((v) => abrir.mutate(v))}
      >
        <Form form={form} layout="vertical" initialValues={{ categoria: 'GERAL' }}>
          <Form.Item name="assunto" label="Assunto" rules={[{ required: true }]}>
            <Input maxLength={200} />
          </Form.Item>
          <Form.Item name="categoria" label="Categoria">
            <Select
              options={[
                { value: 'GERAL', label: 'Geral' },
                { value: 'PLANO', label: 'Plano / comercial' },
                { value: 'FACTURACAO', label: 'Facturação' },
                { value: 'MARKETING', label: 'Marketing e comunicação' },
              ]}
            />
          </Form.Item>
          <Form.Item name="descricao" label="Descrição">
            <Input.TextArea rows={4} maxLength={5000} />
          </Form.Item>
        </Form>
      </Modal>

      <ConversaSuporte base={base} anexosBase={anexosBase} ticketId={conversa?.id ?? null} titulo={conversa?.assunto} aoFechar={() => setConversa(null)} />

      <Modal
        open={!!avaliar}
        title={`Avaliar «${avaliar?.assunto ?? ''}»`}
        okText="Enviar avaliação"
        cancelText="Cancelar"
        confirmLoading={avaliarAccao.isPending}
        onCancel={() => setAvaliar(null)}
        onOk={() => avaliarAccao.mutate()}
      >
        <Space direction="vertical" size={12} style={{ width: '100%' }}>
          <div>
            <Typography.Text>Como avalia o apoio que recebeu?</Typography.Text>
            <div>
              <Rate value={nota} onChange={setNota} />
            </div>
          </div>
          <Input.TextArea rows={3} maxLength={1000} placeholder="Comentário (opcional)" value={comentario} onChange={(e) => setComentario(e.target.value)} />
        </Space>
      </Modal>
    </>
  );
}
