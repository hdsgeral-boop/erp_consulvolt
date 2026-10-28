import { Button, Card, Input, Select, Space, Table, Tag, Tooltip, Typography } from 'antd';
import { ClockCircleOutlined, CommentOutlined, DashboardOutlined } from '@ant-design/icons';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { Link } from 'react-router-dom';
import { enviar, obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { notificarErro } from '@/utilitarios/erros';
import { formatarDataHora } from '@/utilitarios/formatacao';
import { ConversaSuporte } from '@/modulos/config/comum/ConversaSuporte';

interface Ticket {
  id: number; tenant_id: number; cliente: string | null; assunto: string; descricao: string | null; estado: string; prioridade: string; categoria: string;
  criado_por: string | null; criado_em: string; agente_id: number | null; agente_nome: string | null; prazo_sla: string | null; em_atraso: boolean;
}
interface Agente { id: number; nome: string }

const ESTADOS = ['ABERTO', 'EM_CURSO', 'RESOLVIDO', 'FECHADO'];
const CATEGORIAS = ['GERAL', 'PLANO', 'FACTURACAO', 'MARKETING'];
const CHAVE = ['suporte_agente', 'tickets'];

/**
 * Backoffice de suporte isolado (ADR-076): o que um agente de suporte vê — só tickets, nada da administração da
 * plataforma. Desde o ADR-077, a lista já vem do servidor restrita às categorias da especialização do agente
 * (super-administrador continua a ver tudo); o filtro de categoria aqui é só de conforto sobre o que já chegou.
 */
export default function SuportePainel() {
  const qc = useQueryClient();
  const [estado, setEstado] = useState<string>();
  const [categoria, setCategoria] = useState<string>();
  const [pesquisa, setPesquisa] = useState('');
  const [conversa, setConversa] = useState<Ticket | null>(null);
  const lista = useQuery({
    queryKey: [...CHAVE, estado, pesquisa],
    queryFn: () => obter<Ticket[]>('/suporte/tickets', { estado, pesquisa: pesquisa || undefined }),
  });
  const agentes = useQuery({ queryKey: ['suporte_agente', 'agentes'], queryFn: () => obter<Agente[]>('/suporte/agentes') });
  const mudar = useMutation({
    mutationFn: (p: { id: number; dados: Record<string, unknown> }) => enviar('put', `/suporte/tickets/${p.id}`, p.dados),
    onSuccess: () => qc.invalidateQueries({ queryKey: CHAVE }),
    onError: (e) => notificarErro(e),
  });
  const opcoes = (l: string[]) => l.map((v) => ({ value: v, label: v }));
  const linhas = (lista.data ?? []).filter((t) => !categoria || t.categoria === categoria);

  return (
    <>
      <CabecalhoPagina
        titulo="Tickets de suporte"
        subtitulo="Pedidos de suporte de todos os clientes Consulvolt"
        accoes={<Link to="dashboard"><Button icon={<DashboardOutlined />}>Dashboard</Button></Link>}
      />
      <Card extra={
        <Space wrap>
          <Input.Search allowClear placeholder="Pesquisar" style={{ width: 200 }} onSearch={setPesquisa} />
          <Select allowClear placeholder="Categoria" style={{ width: 160 }} options={opcoes(CATEGORIAS)} value={categoria} onChange={setCategoria} />
          <Select allowClear placeholder="Estado" style={{ width: 160 }} options={opcoes(ESTADOS)} value={estado} onChange={setEstado} />
        </Space>
      }>
        <Table<Ticket> rowKey="id" size="middle" loading={lista.isLoading} dataSource={linhas} scroll={{ x: 'max-content' }}
          expandable={{ expandedRowRender: (t) => t.descricao ?? '—' }}
          columns={[
            { title: 'Cliente', dataIndex: 'cliente', render: (v: string | null) => v || <Tag>—</Tag> },
            {
              title: 'Assunto', dataIndex: 'assunto', render: (v: string, t) => (
                <Space size={6}>
                  {t.em_atraso && <Tooltip title={`Prazo do SLA vencido${t.prazo_sla ? ` (${formatarDataHora(t.prazo_sla)})` : ''}`}><ClockCircleOutlined style={{ color: '#dc2626' }} /></Tooltip>}
                  <strong>{v}</strong>
                </Space>
              ),
            },
            { title: 'Categoria', dataIndex: 'categoria', render: (v: string) => <Tag>{v}</Tag> },
            { title: 'Prioridade', render: (_, t) => <Tag color={t.prioridade === 'URGENTE' ? 'red' : t.prioridade === 'ALTA' ? 'orange' : t.prioridade === 'BAIXA' ? 'default' : 'blue'}>{t.prioridade}</Tag> },
            { title: 'Agente', render: (_, t) => (
              <Select allowClear size="small" style={{ width: 150 }} placeholder="Por atribuir" value={t.agente_id ?? undefined}
                options={(agentes.data ?? []).map((a) => ({ value: a.id, label: a.nome }))}
                onChange={(v) => mudar.mutate({ id: t.id, dados: { agente_id: v ?? null } })} />
            ) },
            { title: 'Estado', render: (_, t) => <Select size="small" style={{ width: 140 }} value={t.estado} options={opcoes(ESTADOS)} onChange={(e) => mudar.mutate({ id: t.id, dados: { estado: e } })} /> },
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
      <ConversaSuporte
        base="/suporte/tickets"
        anexosBase="/suporte/anexos"
        respostasRapidasBase="/suporte/respostas-rapidas"
        agente
        ticketId={conversa?.id ?? null}
        titulo={conversa ? `#${conversa.id} — ${conversa.assunto}` : undefined}
        aoFechar={() => setConversa(null)}
      />
      {linhas.length === 0 && !lista.isLoading && (
        <Typography.Text type="secondary" style={{ display: 'block', marginTop: 12 }}>Sem tickets para mostrar.</Typography.Text>
      )}
    </>
  );
}
