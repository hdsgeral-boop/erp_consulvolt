import { ApiOutlined, CopyOutlined, PlusOutlined, StopOutlined } from '@ant-design/icons';
import { Alert, Button, DatePicker, Drawer, Form, Input, Modal, Popconfirm, Select, Space, Table, Tag, Typography, message } from 'antd';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type { Dayjs } from 'dayjs';
import { useState } from 'react';
import { enviar, obter } from '@/api/cliente';
import { larguraGaveta, scrollTabela } from '@/componentes/responsivo';
import { notificarErro } from '@/utilitarios/erros';
import { formatarDataHora } from '@/utilitarios/formatacao';

/**
 * Power BI (decisão 25, M-02): tokens de leitura do feed OData da empresa activa (config_backup) e instruções de ligação.
 * O valor do token só é mostrado uma vez, ao criar; revogar é imediato.
 */
interface TokenBI {
  id: number;
  nome: string;
  prefixo: string;
  conjuntos: string[] | null;
  estado: 'ATIVO' | 'REVOGADO' | 'EXPIRADO';
  criado_por: string | null;
  criado_em: string | null;
  expira_em: string | null;
  ultimo_uso_em: string | null;
  utilizacoes: number;
}

interface DadosBI {
  tokens: TokenBI[];
  conjuntos: { id: string; nome: string; propriedades: number }[];
  endereco: string;
  tamanho_pagina: number;
}

const CHAVE = ['sistema', 'bi', 'tokens'];

export function PainelPowerBI({ aberto, aoFechar }: { aberto: boolean; aoFechar: () => void }) {
  const cliente = useQueryClient();
  const dados = useQuery({ queryKey: CHAVE, queryFn: () => obter<DadosBI>('/sistema/bi/tokens'), enabled: aberto });
  const [criar, setCriar] = useState(false);
  const [novo, setNovo] = useState<string | null>(null);
  const [form] = Form.useForm<{ nome: string; conjuntos?: string[]; expira_em?: Dayjs }>();
  const mutCriar = useMutation({
    mutationFn: (v: { nome: string; conjuntos?: string[]; expira_em?: Dayjs }) =>
      enviar<{ token: string }>('post', '/sistema/bi/tokens', { nome: v.nome, conjuntos: v.conjuntos?.length ? v.conjuntos : null, expira_em: v.expira_em?.format('YYYY-MM-DD') }),
    onSuccess: ({ dados: d }) => {
      setCriar(false);
      setNovo(d.token);
      form.resetFields();
      void cliente.invalidateQueries({ queryKey: CHAVE });
    },
    onError: (e) => notificarErro(e),
  });
  const revogar = useMutation({
    mutationFn: (id: number) => enviar('post', `/sistema/bi/tokens/${id}/revogar`),
    onSuccess: ({ mensagem }) => {
      message.success(mensagem);
      void cliente.invalidateQueries({ queryKey: CHAVE });
    },
    onError: (e) => notificarErro(e),
  });
  const copiar = (t: string) => void navigator.clipboard?.writeText(t).then(() => message.success('Copiado.'), () => undefined);
  const d = dados.data;
  return (
    <Drawer title={<span><ApiOutlined /> Power BI — feed OData de leitura</span>} open={aberto} onClose={aoFechar} width={larguraGaveta(760)} destroyOnHidden>
      <Typography.Paragraph>
        O Power BI lê os dados desta empresa, linha a linha, dos mesmos conjuntos da Análise Dinâmica (lançamentos, vendas, compras, tesouraria, armazém, salários,
        projectos e activos). Cada token só lê a empresa em que foi criado e pode ser revogado a qualquer momento.
      </Typography.Paragraph>
      {d && (
        <Alert
          type="info"
          showIcon
          style={{ marginBottom: 16 }}
          message="Como ligar no Power BI Desktop"
          description={
            <ol style={{ margin: 0, paddingInlineStart: 18 }}>
              <li>Obter dados › <strong>Feed OData</strong> › URL: <Typography.Text code copyable>{d.endereco}</Typography.Text></li>
              <li>Autenticação <strong>Básica</strong>: utilizador <Typography.Text code>bi</Typography.Text> (qualquer) e palavra-passe = o token.</li>
              <li>Escolha os conjuntos. Para limitar o período, use o URL do conjunto com <Typography.Text code>?data_inicio=AAAA-MM-DD&amp;data_fim=AAAA-MM-DD</Typography.Text>.</li>
              <li>Os filtros ($filter) e ordenações fazem-se no Power Query; o servidor envia {d.tamanho_pagina.toLocaleString('pt-PT')} linhas por página.</li>
            </ol>
          }
        />
      )}
      <Space style={{ marginBottom: 12 }}>
        <Button type="primary" icon={<PlusOutlined />} onClick={() => setCriar(true)}>Novo token</Button>
      </Space>
      <Table<TokenBI>
        size="small"
        rowKey="id"
        loading={dados.isLoading}
        dataSource={d?.tokens}
        pagination={false}
        scroll={scrollTabela()}
        columns={[
          { title: 'Nome', dataIndex: 'nome' },
          { title: 'Token', dataIndex: 'prefixo', render: (p: string) => <Typography.Text code>{p}…</Typography.Text> },
          { title: 'Conjuntos', dataIndex: 'conjuntos', responsive: ['md'], render: (c: string[] | null) => (c ? c.join(', ') : 'Todos') },
          { title: 'Estado', dataIndex: 'estado', render: (e: string) => <Tag color={e === 'ATIVO' ? 'green' : e === 'EXPIRADO' ? 'orange' : 'default'}>{e === 'ATIVO' ? 'Activo' : e === 'EXPIRADO' ? 'Expirado' : 'Revogado'}</Tag> },
          { title: 'Último uso', dataIndex: 'ultimo_uso_em', responsive: ['sm'], render: (v: string | null, t) => (v ? `${formatarDataHora(v)} (${t.utilizacoes})` : '—') },
          { title: 'Expira', dataIndex: 'expira_em', responsive: ['lg'], render: (v: string | null) => (v ? formatarDataHora(v) : '—') },
          {
            title: '',
            key: 'a',
            render: (_: unknown, t) =>
              t.estado === 'ATIVO' ? (
                <Popconfirm title="Revogar este token?" description="O Power BI deixa de conseguir actualizar os dados com ele." okText="Revogar" cancelText="Cancelar" onConfirm={() => revogar.mutateAsync(t.id)}>
                  <Button size="small" danger icon={<StopOutlined />}>Revogar</Button>
                </Popconfirm>
              ) : null,
          },
        ]}
      />
      <Modal title="Novo token de leitura" open={criar} onCancel={() => setCriar(false)} okText="Criar" cancelText="Cancelar" confirmLoading={mutCriar.isPending} onOk={() => form.submit()} destroyOnHidden>
        <Form form={form} layout="vertical" onFinish={(v) => mutCriar.mutate(v)}>
          <Form.Item name="nome" label="Nome" rules={[{ required: true, min: 3, message: 'Indique um nome (ex.: Power BI da Direcção).' }]}>
            <Input maxLength={255} />
          </Form.Item>
          <Form.Item name="conjuntos" label="Conjuntos (vazio = todos)">
            <Select mode="multiple" allowClear options={d?.conjuntos.map((c) => ({ value: c.id, label: c.nome }))} />
          </Form.Item>
          <Form.Item name="expira_em" label="Expira em (opcional)">
            <DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} />
          </Form.Item>
        </Form>
      </Modal>
      <Modal title="Token criado" open={!!novo} onCancel={() => setNovo(null)} footer={<Button type="primary" onClick={() => setNovo(null)}>Já copiei</Button>}>
        <Alert type="warning" showIcon style={{ marginBottom: 12 }} message="Copie o token agora: por segurança não volta a ser mostrado." />
        <Space.Compact style={{ width: '100%' }}>
          <Input readOnly value={novo ?? ''} aria-label="Token" />
          <Button icon={<CopyOutlined />} onClick={() => novo && copiar(novo)}>Copiar</Button>
        </Space.Compact>
      </Modal>
    </Drawer>
  );
}
