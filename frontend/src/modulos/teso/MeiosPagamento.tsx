import { Button, Card, Checkbox, Form, Input, Modal, Popconfirm, Space, Table, Tag, message } from 'antd';
import { DeleteOutlined, EditOutlined, PlusOutlined } from '@ant-design/icons';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { enviar } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { tabelaHtml } from '@/componentes/impressao';
import { useSessao } from '@/sessao/SessaoContexto';
import { notificarErro } from '@/utilitarios/erros';
import { SeletorConta } from '../contab/comum/Seletores';
import type { MeioPagamento } from './api';
import { useMeiosPagamento } from './comum';
import { larguraModal, scrollTabela } from '@/componentes/responsivo';

interface ValoresMeio {
  nome: string;
  codigo_conta: string;
  iban?: string;
  swift?: string;
  ativo: boolean;
  predefinido: boolean;
}

/** Tesouraria › Meios de pagamento (ecrã teso_meios_pagamento): contas de banco/caixa disponíveis, com o meio predefinido. */
export default function MeiosPagamento() {
  const { pode } = useSessao();
  const cliente = useQueryClient();
  const meios = useMeiosPagamento();
  const [edicao, setEdicao] = useState<MeioPagamento | 'novo' | null>(null);
  const [form] = Form.useForm<ValoresMeio>();
  const gerir = pode('teso_meios_gerir');

  const mutacao = useMutation({
    mutationFn: ({ metodo, url, corpo }: { metodo: 'post' | 'put' | 'delete'; url: string; corpo?: unknown }) => enviar<MeioPagamento | null>(metodo, url, corpo),
    onSuccess: ({ mensagem }) => {
      message.success(mensagem);
      setEdicao(null);
      void cliente.invalidateQueries({ queryKey: ['teso', 'meios'] });
    },
    onError: (e) => notificarErro(e),
  });

  const abrir = (m: MeioPagamento | 'novo') => {
    form.resetFields();
    form.setFieldsValue(m === 'novo' ? { ativo: true, predefinido: false } : { nome: m.nome, codigo_conta: m.codigo_conta, iban: m.iban ?? undefined, swift: m.swift ?? undefined, ativo: m.ativo, predefinido: m.predefinido });
    setEdicao(m);
  };

  return (
    <>
      <CabecalhoPagina
        titulo="Meios de pagamento"
        subtitulo="Contas de bancos (43) e caixa (45) usadas em pagamentos e recebimentos"
        accoes={gerir && <Button type="primary" icon={<PlusOutlined />} onClick={() => abrir('novo')}>Novo meio</Button>}
        impressaoDesactivada={!meios.data?.length}
        impressao={() => ({
          titulo: 'Meios de pagamento',
          conteudo: tabelaHtml({
            colunas: [
              { titulo: 'Nome', valor: (m: MeioPagamento) => `${m.nome}${m.predefinido ? ' (predefinido)' : ''}` },
              { titulo: 'Conta', valor: (m) => m.codigo_conta },
              { titulo: 'Moeda', valor: (m) => m.codigo_moeda ?? 'AOA' },
              { titulo: 'IBAN', valor: (m) => m.iban ?? '' },
              { titulo: 'SWIFT', valor: (m) => m.swift ?? '' },
              { titulo: 'Estado', valor: (m) => (m.ativo ? 'Activo' : 'Inactivo') },
            ],
            linhas: meios.data ?? [],
          }),
        })}
      />
      <Card>
        <Table<MeioPagamento>
          rowKey="id"
          loading={meios.isLoading}
          dataSource={meios.data}
          pagination={false}
          scroll={scrollTabela()}
          columns={[
            { title: 'Nome', dataIndex: 'nome', render: (v: string, m) => <Space wrap><strong>{v}</strong>{m.predefinido && <Tag color="blue">Predefinido</Tag>}</Space> },
            { title: 'Conta', dataIndex: 'codigo_conta' },
            { title: 'Moeda', dataIndex: 'codigo_moeda', responsive: ['md'], render: (v: string | null) => v ?? '—' },
            { title: 'IBAN', dataIndex: 'iban', responsive: ['md'], render: (v: string | null) => v ?? '—' },
            { title: 'SWIFT', dataIndex: 'swift', responsive: ['lg'], render: (v: string | null) => v ?? '—' },
            { title: 'Estado', dataIndex: 'ativo', render: (v: boolean) => (v ? <Tag color="green">Activo</Tag> : <Tag>Inactivo</Tag>) },
            {
              title: '',
              render: (_, m) =>
                gerir && (
                  <Space wrap>
                    <Button size="small" type="text" icon={<EditOutlined />} aria-label="Editar" title="Editar" onClick={() => abrir(m)} />
                    <Popconfirm title={`Eliminar ${m.nome}?`} okText="Eliminar" cancelText="Cancelar" okButtonProps={{ danger: true }} onConfirm={() => mutacao.mutateAsync({ metodo: 'delete', url: `/tesouraria/meios-pagamento/${m.id}` })}>
                      <Button size="small" type="text" danger icon={<DeleteOutlined />} aria-label="Eliminar" title="Eliminar" />
                    </Popconfirm>
                  </Space>
                ),
            },
          ]}
        />
      </Card>
      <Modal width={larguraModal(560)} title={edicao === 'novo' ? 'Novo meio de pagamento' : 'Editar meio de pagamento'} open={edicao !== null} onCancel={() => setEdicao(null)} okText="Gravar" confirmLoading={mutacao.isPending} onOk={() => form.submit()}>
        <Form
          form={form}
          layout="vertical"
          onFinish={(v) =>
            mutacao.mutate(edicao && edicao !== 'novo' ? { metodo: 'put', url: `/tesouraria/meios-pagamento/${edicao.id}`, corpo: v } : { metodo: 'post', url: '/tesouraria/meios-pagamento', corpo: v })
          }
        >
          <Form.Item name="nome" label="Nome" rules={[{ required: true }]}>
            <Input maxLength={255} />
          </Form.Item>
          <Form.Item name="codigo_conta" label="Conta (43 bancos, 45 caixa)" rules={[{ required: true }]}>
            <SeletorConta prefixos={['43', '45']} style={{ width: '100%' }} />
          </Form.Item>
          <Space wrap>
            <Form.Item name="iban" label="IBAN">
              <Input maxLength={40} style={{ width: 280, maxWidth: '100%' }} />
            </Form.Item>
            <Form.Item name="swift" label="SWIFT">
              <Input maxLength={11} style={{ width: 140 }} />
            </Form.Item>
          </Space>
          <Space wrap>
            <Form.Item name="ativo" valuePropName="checked" noStyle>
              <Checkbox>Activo</Checkbox>
            </Form.Item>
            <Form.Item name="predefinido" valuePropName="checked" noStyle>
              <Checkbox>Predefinido</Checkbox>
            </Form.Item>
          </Space>
        </Form>
      </Modal>
    </>
  );
}
