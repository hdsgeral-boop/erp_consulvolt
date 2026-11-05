import { Button, Card, Input, InputNumber, Select, Space, Table, Tag, Tooltip, Typography } from 'antd';
import { ClockCircleOutlined, CommentOutlined, DashboardOutlined } from '@ant-design/icons';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { Link } from 'react-router';
import { enviar, obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { notificarErro } from '@/utilitarios/erros';
import { formatarDataHora } from '@/utilitarios/formatacao';
import { ConversaSuporte } from '@/modulos/config/comum/ConversaSuporte';

interface Ticket {
  id: number; tenant_id: number; cliente: string | null; assunto: string; descricao: string | null; estado: string; prioridade: string; categoria: string;
  criado_por: string | null; criado_em: string; agente_id: number | null; agente_nome: string | null; prazo_sla: string | null; em_atraso: boolean;
  tempo_atendimento_minutos: number | null;
}
interface Agente { id: number; nome: string }
interface MinhasCategorias { todas: boolean; categorias: string[]; grupos: { id: string; rotulo: string; categorias: string[] }[]; especializacao: string | null }

// Fase 6 da extensão SaaS (segurança.txt, secção 13.3): os 11 estados — ver TicketSuporte::ESTADOS.
const ESTADOS = [
  'ABERTO', 'EM_TRIAGEM', 'EM_ANALISE', 'ATRIBUIDO', 'EM_CURSO', 'A_AGUARDAR_CLIENTE', 'A_AGUARDAR_TERCEIRO',
  'RESOLVIDO', 'FECHADO', 'REABERTO', 'CANCELADO',
];
// Categorias (ADR-077, alargadas na Fase 6) — ver TicketSuporte::CATEGORIAS. O servidor já restringe o que cada
// agente vê pela sua especialização; este filtro é só de conforto sobre o que chegou.
const CATEGORIAS = [
  'GERAL', 'MARKETING', 'PLANO', 'FACTURACAO', 'PAGAMENTO', 'SUBSCRICAO',
  'INCIDENTE_TECNICO', 'ERRO_FUNCIONAL', 'AUTENTICACAO', 'PERMISSOES', 'CONFIGURACAO', 'DESEMPENHO', 'INTEGRACAO', 'SEGURANCA',
  'MELHORIA', 'DUVIDA', 'ADMINISTRATIVA', 'OUTROS',
];
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
  // As categorias do próprio utilizador (especialização, ADR-077) — a mesma fonte que o servidor usa para filtrar os tickets.
  const eu = useQuery({ queryKey: ['suporte_agente', 'eu'], queryFn: () => obter<MinhasCategorias>('/suporte/eu'), staleTime: 300_000 });
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
      {eu.data && (
        <Typography.Paragraph data-testid="minhas-categorias" style={{ marginBottom: 12 }}>
          As suas categorias:{' '}
          {eu.data.todas
            ? <Tag color="purple">Todas</Tag>
            : eu.data.grupos.length === 0
              ? <Typography.Text type="danger">nenhuma — peça a atribuição de uma especialização</Typography.Text>
              : eu.data.grupos.map((g) => <Tooltip key={g.id} title={g.categorias.join(', ')}><Tag color="purple">{g.rotulo.split(' (')[0]}</Tag></Tooltip>)}
          {eu.data.especializacao && <Typography.Text type="secondary"> · especialização «{eu.data.especializacao}»</Typography.Text>}
        </Typography.Paragraph>
      )}
      <Card extra={
        <Space wrap>
          <Input.Search allowClear placeholder="Pesquisar" style={{ width: 200 }} onSearch={setPesquisa} />
          <Select allowClear placeholder="Categoria" style={{ width: 160 }} options={opcoes(eu.data?.categorias ?? CATEGORIAS)} value={categoria} onChange={setCategoria} />
          <Select allowClear placeholder="Estado" style={{ width: 160 }} options={opcoes(ESTADOS)} value={estado} onChange={setEstado} />
        </Space>
      }>
        <Table<Ticket> rowKey="id" size="middle" loading={lista.isLoading} dataSource={linhas} scroll={{ x: 'max-content' }}
          expandable={{
            expandedRowRender: (t) => (
              <Space orientation="vertical" size={8}>
                <span>{t.descricao ?? '—'}</span>
                <Space>
                  <span>Tempo de atendimento (min):</span>
                  <InputNumber size="small" min={0} value={t.tempo_atendimento_minutos ?? undefined}
                    onBlur={(e) => { const v = e.target.value ? Number(e.target.value) : null; if (v !== t.tempo_atendimento_minutos) mudar.mutate({ id: t.id, dados: { tempo_atendimento_minutos: v } }); }} />
                </Space>
              </Space>
            ),
          }}
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
