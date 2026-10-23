import { Button, Card, Select, Space, Table, Tag, Typography } from 'antd';
import { CommentOutlined } from '@ant-design/icons';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { enviar, obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { notificarErro } from '@/utilitarios/erros';
import { formatarDataHora } from '@/utilitarios/formatacao';
import { ConversaSuporte } from '@/modulos/config/comum/ConversaSuporte';

interface Ticket { id: number; tenant_id: number; cliente: string | null; assunto: string; descricao: string | null; estado: string; prioridade: string; categoria: string; criado_por: string | null; criado_em: string }

const ESTADOS = ['ABERTO', 'EM_CURSO', 'RESOLVIDO', 'FECHADO'];
const CHAVE = ['suporte_agente', 'tickets'];

/** Backoffice de suporte isolado (ADR-076): o que um agente de suporte vê — só tickets, nada da administração da plataforma. */
export default function SuportePainel() {
  const qc = useQueryClient();
  const [estado, setEstado] = useState<string>();
  const [conversa, setConversa] = useState<Ticket | null>(null);
  const lista = useQuery({ queryKey: [...CHAVE, estado], queryFn: () => obter<Ticket[]>(`/suporte/tickets${estado ? `?estado=${estado}` : ''}`) });
  const mudar = useMutation({
    mutationFn: (p: { id: number; estado: string }) => enviar('put', `/suporte/tickets/${p.id}`, { estado: p.estado }),
    onSuccess: () => qc.invalidateQueries({ queryKey: CHAVE }),
    onError: (e) => notificarErro(e),
  });
  const opcoes = (l: string[]) => l.map((v) => ({ value: v, label: v }));

  return (
    <>
      <CabecalhoPagina titulo="Tickets de suporte" subtitulo="Pedidos de suporte de todos os clientes Consulvolt" />
      <Card extra={<Select allowClear placeholder="Estado" style={{ width: 160 }} options={opcoes(ESTADOS)} value={estado} onChange={setEstado} />}>
        <Table<Ticket> rowKey="id" size="middle" loading={lista.isLoading} dataSource={lista.data ?? []} scroll={{ x: 'max-content' }}
          expandable={{ expandedRowRender: (t) => t.descricao ?? '—' }}
          columns={[
            { title: 'Cliente', dataIndex: 'cliente', render: (v: string | null) => v || <Tag>—</Tag> },
            { title: 'Assunto', dataIndex: 'assunto', render: (v: string) => <strong>{v}</strong> },
            { title: 'Prioridade', render: (_, t) => <Tag color={t.prioridade === 'URGENTE' ? 'red' : t.prioridade === 'ALTA' ? 'orange' : t.prioridade === 'BAIXA' ? 'default' : 'blue'}>{t.prioridade}</Tag> },
            { title: 'Estado', render: (_, t) => <Select size="small" style={{ width: 140 }} value={t.estado} options={opcoes(ESTADOS)} onChange={(e) => mudar.mutate({ id: t.id, estado: e })} /> },
            { title: 'Criado', render: (_, t) => formatarDataHora(t.criado_em) },
            {
              title: '',
              align: 'right',
              render: (_, t) => (
                <Space>
                  <Button size="small" icon={<CommentOutlined />} onClick={() => setConversa(t)}>Conversa</Button>
                </Space>
              ),
            },
          ]} />
      </Card>
      <ConversaSuporte base="/suporte/tickets" ticketId={conversa?.id ?? null} titulo={conversa ? `#${conversa.id} — ${conversa.assunto}` : undefined} aoFechar={() => setConversa(null)} />
      {lista.data?.length === 0 && !lista.isLoading && (
        <Typography.Text type="secondary" style={{ display: 'block', marginTop: 12 }}>Sem tickets para mostrar.</Typography.Text>
      )}
    </>
  );
}
