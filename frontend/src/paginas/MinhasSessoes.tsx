import { Alert, Button, Card, Popconfirm, Table, Tag, Typography, message } from 'antd';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect } from 'react';
import { enviar, obter } from '@/api/cliente';
import { IconeFa } from '@/componentes/fluxos';
import { formatarDataHora } from '@/utilitarios/formatacao';
import { notificarErro } from '@/utilitarios/erros';

export interface SessaoActiva {
  id: number;
  dispositivo: string;
  ip_aproximado: string | null;
  criada_em: string | null;
  ultima_actividade_em: string | null;
  esta_sessao: boolean;
}

export const CHAVE_SESSOES = ['autenticacao', 'sessoes'];

/**
 * «As minhas sessões» (ADR-082): os dispositivos com sessão aberta na conta do próprio utilizador — rótulo do navegador
 * e sistema, IP aproximado, quando começou e a última actividade. Permite terminar uma sessão ou todas as outras (a
 * actual nunca é terminada daqui: para isso há «Sair»). Tudo é decidido no servidor; este ecrã só mostra e pede.
 */
export function MinhasSessoes() {
  const cliente = useQueryClient();
  const sessoes = useQuery({ queryKey: CHAVE_SESSOES, queryFn: () => obter<SessaoActiva[]>('/autenticacao/sessoes') });
  const lista = Array.isArray(sessoes.data) ? sessoes.data : [];

  useEffect(() => {
    if (sessoes.error) notificarErro(sessoes.error, 'Não foi possível obter as sessões');
  }, [sessoes.error]);

  const terminar = useMutation({
    mutationFn: (id: number) => enviar('delete', `/autenticacao/sessoes/${id}`),
    onSuccess: (r) => {
      message.success(r.mensagem);
      void cliente.invalidateQueries({ queryKey: CHAVE_SESSOES });
    },
    onError: (e) => notificarErro(e, 'Não foi possível terminar a sessão'),
  });
  const terminarOutras = useMutation({
    mutationFn: () => enviar('post', '/autenticacao/sessoes/terminar-outras'),
    onSuccess: (r) => {
      message.success(r.mensagem);
      void cliente.invalidateQueries({ queryKey: CHAVE_SESSOES });
    },
    onError: (e) => notificarErro(e, 'Não foi possível terminar as outras sessões'),
  });

  const outras = lista.filter((s) => !s.esta_sessao).length;

  return (
    <Card
      title={<span><IconeFa nome="desktop" /> As minhas sessões</span>}
      extra={
        <Popconfirm title="Terminar todas as outras sessões?" description="Os outros dispositivos terão de entrar de novo." okText="Terminar" cancelText="Cancelar" onConfirm={() => terminarOutras.mutate()}>
          <Button danger disabled={outras === 0} loading={terminarOutras.isPending}>
            Terminar todas as outras
          </Button>
        </Popconfirm>
      }
    >
      <Typography.Paragraph type="secondary" style={{ marginTop: 0 }}>
        Cada dispositivo tem a sua própria sessão. Se não reconhece algum, termine-o e altere a palavra-passe.
      </Typography.Paragraph>
      {sessoes.isError && <Alert type="error" showIcon title="Não foi possível obter as sessões." style={{ marginBottom: 12 }} />}
      <Table<SessaoActiva>
        rowKey="id"
        size="small"
        loading={sessoes.isLoading}
        pagination={false}
        dataSource={lista}
        scroll={{ x: 'max-content' }}
        columns={[
          {
            title: 'Dispositivo',
            dataIndex: 'dispositivo',
            render: (v: string, s) => (
              <span>
                {v} {s.esta_sessao && <Tag color="green">Esta sessão</Tag>}
              </span>
            ),
          },
          { title: 'IP aproximado', dataIndex: 'ip_aproximado', render: (v: string | null) => v ?? '—' },
          { title: 'Iniciada', dataIndex: 'criada_em', render: (v: string | null) => (v ? formatarDataHora(v) : '—') },
          { title: 'Última actividade', dataIndex: 'ultima_actividade_em', render: (v: string | null) => (v ? formatarDataHora(v) : '—') },
          {
            title: '',
            key: 'acoes',
            align: 'right',
            render: (_: unknown, s) =>
              s.esta_sessao ? null : (
                <Popconfirm title="Terminar esta sessão?" okText="Terminar" cancelText="Cancelar" onConfirm={() => terminar.mutate(s.id)}>
                  <Button size="small" danger loading={terminar.isPending && terminar.variables === s.id}>
                    Terminar
                  </Button>
                </Popconfirm>
              ),
          },
        ]}
      />
    </Card>
  );
}
