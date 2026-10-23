import { Button, Card, Popconfirm, Space, Table, Tag, Typography, message } from 'antd';
import { LaptopOutlined, StopOutlined } from '@ant-design/icons';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { enviar, obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { notificarErro } from '@/utilitarios/erros';
import { formatarDataHora } from '@/utilitarios/formatacao';

interface Sessao {
  id: number;
  dispositivo: string | null;
  endereco_ip: string | null;
  ultimo_uso_em: string | null;
  expira_em: string;
  nome_utilizador: string;
  cliente: string;
}

const CHAVE = ['plataforma', 'sessoes'];

/** Plataforma SaaS — sessões activas (plataforma_sessoes): tokens válidos de todos os clientes, com revogação. */
export default function PlataformaSessoes() {
  const qc = useQueryClient();
  const lista = useQuery({ queryKey: CHAVE, queryFn: () => obter<Sessao[]>('/plataforma/sessoes'), refetchInterval: 30_000 });
  const revogar = useMutation({
    mutationFn: (id: number) => enviar('delete', `/plataforma/sessoes/${id}`),
    onSuccess: () => {
      message.success('Sessão terminada.');
      qc.invalidateQueries({ queryKey: CHAVE });
    },
    onError: (e) => notificarErro(e),
  });

  return (
    <>
      <CabecalhoPagina titulo="Sessões activas" subtitulo="Tokens válidos de todos os clientes (últimas 300) — termine uma sessão suspeita a qualquer momento" />
      <Card>
        <Table<Sessao>
          rowKey="id"
          size="middle"
          loading={lista.isLoading}
          dataSource={lista.data ?? []}
          scroll={{ x: 'max-content' }}
          pagination={{ pageSize: 20 }}
          locale={{ emptyText: 'Sem sessões activas no momento.' }}
          columns={[
            { title: 'Cliente', dataIndex: 'cliente', render: (v: string) => <strong>{v}</strong> },
            { title: 'Utilizador', dataIndex: 'nome_utilizador' },
            {
              title: 'Dispositivo',
              dataIndex: 'dispositivo',
              render: (v: string | null) => (
                <Space size={6}>
                  <LaptopOutlined style={{ color: '#94a3b8' }} />
                  {v || 'Desconhecido'}
                </Space>
              ),
            },
            { title: 'Endereço IP', dataIndex: 'endereco_ip', render: (v: string | null) => v || <Typography.Text type="secondary">—</Typography.Text> },
            { title: 'Último uso', dataIndex: 'ultimo_uso_em', render: (v: string | null) => (v ? formatarDataHora(v) : '—') },
            {
              title: 'Expira em',
              dataIndex: 'expira_em',
              render: (v: string) => {
                const expiraEmBreve = new Date(v).getTime() - Date.now() < 24 * 60 * 60 * 1000;
                return <Tag color={expiraEmBreve ? 'gold' : 'default'}>{formatarDataHora(v)}</Tag>;
              },
            },
            {
              title: '',
              align: 'right',
              render: (_, s) => (
                <Popconfirm title="Terminar esta sessão?" description="O utilizador terá de iniciar sessão novamente." onConfirm={() => revogar.mutate(s.id)}>
                  <Button size="small" danger icon={<StopOutlined />}>
                    Terminar
                  </Button>
                </Popconfirm>
              ),
            },
          ]}
        />
      </Card>
    </>
  );
}
