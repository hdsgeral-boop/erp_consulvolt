import { Button, Card, Popconfirm, Table, message } from 'antd';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { enviar, obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { notificarErro } from '@/utilitarios/erros';
import { formatarDataHora } from '@/utilitarios/formatacao';

interface Sessao { id: number; dispositivo: string | null; endereco_ip: string | null; ultimo_uso_em: string | null; expira_em: string; nome_utilizador: string; cliente: string }
const CHAVE = ['plataforma', 'sessoes'];

/** Plataforma SaaS — sessões activas (plataforma_sessoes) de todos os clientes, com revogação. */
export default function PlataformaSessoes() {
  const qc = useQueryClient();
  const { data, isLoading } = useQuery({ queryKey: CHAVE, queryFn: () => obter<Sessao[]>('/plataforma/sessoes') });
  const revogar = useMutation({ mutationFn: (id: number) => enviar('delete', `/plataforma/sessoes/${id}`), onSuccess: () => { message.success('Sessão terminada.'); qc.invalidateQueries({ queryKey: CHAVE }); }, onError: (e) => notificarErro(e) });
  return (
    <>
      <CabecalhoPagina titulo="Sessões activas" subtitulo="Sessões de todos os clientes ainda válidas" />
      <Card>
        <Table<Sessao> rowKey="id" size="small" loading={isLoading} dataSource={data ?? []} scroll={{ x: 'max-content' }}
          columns={[
            { title: 'Cliente', dataIndex: 'cliente' },
            { title: 'Utilizador', dataIndex: 'nome_utilizador' },
            { title: 'Dispositivo', dataIndex: 'dispositivo' },
            { title: 'IP', dataIndex: 'endereco_ip' },
            { title: 'Último uso', render: (_, s) => (s.ultimo_uso_em ? formatarDataHora(s.ultimo_uso_em) : '—') },
            { title: '', render: (_, s) => <Popconfirm title="Terminar esta sessão?" onConfirm={() => revogar.mutate(s.id)}><Button size="small" danger>Terminar</Button></Popconfirm> },
          ]} />
      </Card>
    </>
  );
}