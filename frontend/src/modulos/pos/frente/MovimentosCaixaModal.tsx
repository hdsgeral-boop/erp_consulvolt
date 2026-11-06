import { Alert, Button, Form, Input, InputNumber, Modal, Select, Table, Tag, Typography } from 'antd';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { obter } from '@/api/cliente';
import { ModalMotivo, useAccao } from '@/componentes/Accoes';
import { larguraModal, scrollTabela } from '@/componentes/responsivo';
import { useSessao } from '@/sessao/SessaoContexto';
import { formatarDataHora, formatarKz } from '@/utilitarios/formatacao';

export interface MovimentoCaixa {
  id: number;
  tipo: 'SUPRIMENTO' | 'SANGRIA';
  valor: string;
  motivo: string;
  estado: 'ATIVO' | 'ANULADO';
  nome_operador: string | null;
  criado_em: string | null;
  motivo_anulacao: string | null;
}

interface Resposta {
  movimentos: MovimentoCaixa[];
  suprimentos: string;
  sangrias: string;
}

/**
 * Suprimentos e sangrias da caixa (F1b; o legado não tinha): dinheiro que entra na gaveta ou que dela sai para o cofre/banco, com
 * motivo. Só com a sessão aberta e com a permissão `pos_movimento_caixa`. Entram no numerário esperado, no fecho Z e na prestação de contas.
 */
export function MovimentosCaixaModal({ sessaoId, aberto, aoFechar }: { sessaoId: number; aberto: boolean; aoFechar: () => void }) {
  const { pode } = useSessao();
  const [form] = Form.useForm<{ tipo: 'SUPRIMENTO' | 'SANGRIA'; valor: number; motivo: string }>();
  const [anular, setAnular] = useState<MovimentoCaixa | null>(null);
  const podeMovimentar = pode('pos_movimento_caixa');
  const lista = useQuery({ queryKey: ['pos', 'movimentos-caixa', sessaoId], queryFn: () => obter<Resposta>(`/pos/sessoes/${sessaoId}/movimentos`), enabled: aberto, retry: false });
  const invalidar = [['pos']];
  const registar = useAccao({ invalidar, tituloErro: 'Não foi possível registar o movimento', aoSucesso: () => form.resetFields() });
  const anularAccao = useAccao({ invalidar, tituloErro: 'Não foi possível anular o movimento', aoSucesso: () => setAnular(null) });

  return (
    <Modal open={aberto} onCancel={aoFechar} width={larguraModal(760)} title="Suprimentos e sangrias" footer={<Button onClick={aoFechar}>Fechar</Button>} destroyOnHidden>
      {podeMovimentar ? (
        <Form form={form} layout="inline" initialValues={{ tipo: 'SUPRIMENTO' }} onFinish={(v) => registar.mutate({ url: `/pos/sessoes/${sessaoId}/movimentos`, dados: v })} style={{ rowGap: 8, marginBottom: 12 }}>
          <Form.Item name="tipo" rules={[{ required: true }]}>
            <Select
              aria-label="Tipo de movimento"
              style={{ width: 200 }}
              options={[
                { value: 'SUPRIMENTO', label: 'Suprimento (entra dinheiro)' },
                { value: 'SANGRIA', label: 'Sangria (sai dinheiro)' },
              ]}
            />
          </Form.Item>
          <Form.Item name="valor" rules={[{ required: true, message: 'Indique o valor' }]}>
            <InputNumber<number> aria-label="Valor (Kz)" placeholder="Valor (Kz)" min={0.01} precision={2} decimalSeparator="," style={{ width: 150 }} />
          </Form.Item>
          <Form.Item name="motivo" rules={[{ required: true, min: 3, message: 'Indique o motivo (3 caracteres, no mínimo)' }]}>
            <Input aria-label="Motivo" placeholder="Motivo" maxLength={300} style={{ width: 220 }} />
          </Form.Item>
          <Button type="primary" htmlType="submit" loading={registar.isPending}>
            Registar
          </Button>
        </Form>
      ) : (
        <Alert type="info" showIcon style={{ marginBottom: 12 }} title="Só consulta: o registo de suprimentos e sangrias exige a permissão «Registar suprimentos e sangrias da caixa»." />
      )}
      <Typography.Paragraph type="secondary" style={{ marginBottom: 8 }}>
        Suprimentos: <b>{formatarKz(lista.data?.suprimentos ?? '0')} Kz</b> · Sangrias: <b>{formatarKz(lista.data?.sangrias ?? '0')} Kz</b>
      </Typography.Paragraph>
      <Table<MovimentoCaixa>
        size="small"
        rowKey="id"
        loading={lista.isLoading}
        scroll={scrollTabela()}
        pagination={false}
        dataSource={lista.data?.movimentos ?? []}
        locale={{ emptyText: 'Sem suprimentos nem sangrias nesta sessão.' }}
        columns={[
          { title: 'Tipo', dataIndex: 'tipo', render: (t: string, m) => (<Tag color={t === 'SANGRIA' ? 'volcano' : 'green'}>{t === 'SANGRIA' ? 'Sangria' : 'Suprimento'}{m.estado === 'ANULADO' ? ' (anulado)' : ''}</Tag>) },
          { title: 'Hora', dataIndex: 'criado_em', render: (d: string | null) => (d ? formatarDataHora(d) : '—') },
          { title: 'Motivo', dataIndex: 'motivo' },
          { title: 'Operador', dataIndex: 'nome_operador', render: (o: string | null) => o ?? '—' },
          { title: 'Valor', dataIndex: 'valor', align: 'right', render: (v: string) => `${formatarKz(v)} Kz` },
          {
            title: '',
            render: (_: unknown, m) => podeMovimentar && m.estado === 'ATIVO' && <Button size="small" danger onClick={() => setAnular(m)}>Anular</Button>,
          },
        ]}
      />
      <ModalMotivo
        aberto={!!anular}
        titulo="Anular movimento de caixa"
        textoOk="Anular"
        carregando={anularAccao.isPending}
        aoFechar={() => setAnular(null)}
        aoConfirmar={(motivo) => anular && anularAccao.mutate({ url: `/pos/sessoes/${sessaoId}/movimentos/${anular.id}/anular`, dados: { motivo } })}
      />
    </Modal>
  );
}
