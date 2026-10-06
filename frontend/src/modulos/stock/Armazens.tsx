import { Button, Card, Checkbox, Form, Input, Modal, Space, Tag } from 'antd';
import { DeleteOutlined, EditOutlined, PlusOutlined, SettingOutlined } from '@ant-design/icons';
import { useEffect, useState } from 'react';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { tabelaHtml } from '@/componentes/impressao';
import { useSessao } from '@/sessao/SessaoContexto';
import { useAccao } from '@/componentes/Accoes';
import { ModalContas } from '@/modulos/compras/comum/ModalContas';
import { useArmazens, type Armazem } from '@/modulos/compras/comum/referencias';
import { larguraModal, scrollTabela } from '@/componentes/responsivo';

import { TabelaComModos } from '@/componentes/vistas';
/** Armazém › Configuração de armazéns (ecrã armazem_armazens): armazéns da empresa e contas da logística. */
export default function Armazens() {
  const { pode } = useSessao();
  const podeGerir = pode('armazem_config');
  const consulta = useArmazens();
  const [edicao, setEdicao] = useState<Armazem | 'novo' | null>(null);
  const [contas, setContas] = useState(false);
  const [form] = Form.useForm<{ nome: string; codigo?: string; localizacao?: string; predefinido?: boolean }>();
  const gravar = useAccao({ invalidar: [['logistica']], aoSucesso: () => setEdicao(null), tituloErro: 'Não foi possível gravar o armazém' });
  const eliminar = useAccao({ invalidar: [['logistica']] });

  useEffect(() => {
    if (edicao === 'novo') form.setFieldsValue({ nome: '', codigo: undefined, localizacao: undefined, predefinido: false });
    else if (edicao) form.setFieldsValue({ nome: edicao.nome, codigo: edicao.codigo ?? undefined, localizacao: edicao.localizacao ?? undefined, predefinido: !!edicao.predefinido });
  }, [edicao, form]);

  return (
    <>
      <CabecalhoPagina
        titulo="Armazéns"
        subtitulo="Locais de stock da empresa; o predefinido recebe as entradas sem armazém indicado"
        impressaoDesactivada={!consulta.data?.length}
        impressao={() => ({
          titulo: 'Armazéns',
          conteudo: tabelaHtml({
            colunas: [
              { titulo: 'Nome', valor: (a: Armazem) => `${a.nome}${a.predefinido ? ' (predefinido)' : ''}` },
              { titulo: 'Código', valor: (a) => a.codigo ?? '' },
              { titulo: 'Localização', valor: (a) => a.localizacao ?? '', quebrar: true },
            ],
            linhas: consulta.data ?? [],
          }),
        })}
        accoes={
          <>
            {pode('armazem_config', 'armazem_stock_view') && <Button icon={<SettingOutlined />} onClick={() => setContas(true)}>Contas da logística</Button>}
            {podeGerir && <Button type="primary" icon={<PlusOutlined />} onClick={() => setEdicao('novo')}>Novo armazém</Button>}
          </>
        }
      />
      <Card>
        <TabelaComModos<Armazem> scroll={scrollTabela()}
          rowKey="id"
          loading={consulta.isFetching}
          dataSource={consulta.data ?? []}
          pagination={false}
          columns={[
            { title: 'Nome', dataIndex: 'nome', render: (v: string, r) => <><strong>{v}</strong>{r.predefinido && <Tag color="blue" style={{ marginLeft: 8 }}>Predefinido</Tag>}</> },
            { title: 'Código', dataIndex: 'codigo', render: (v) => v || '—' },
            { title: 'Localização', dataIndex: 'localizacao', render: (v) => v || '—' },
            {
              title: '',
              key: 'accoes',
              align: 'right',
              render: (_, r) =>
                podeGerir && (
                  <Space wrap>
                    <Button size="small" icon={<EditOutlined />} onClick={() => setEdicao(r)} aria-label="Editar" />
                    <Button
                      size="small"
                      danger
                      icon={<DeleteOutlined />}
                      aria-label="Eliminar"
                      onClick={() =>
                        Modal.confirm({
                          title: `Eliminar o armazém ${r.nome}?`,
                          content: 'É recusado se o armazém tiver stock ou movimentos.',
                          okText: 'Eliminar',
                          okButtonProps: { danger: true },
                          cancelText: 'Cancelar',
                          onOk: () => eliminar.mutateAsync({ metodo: 'delete', url: `/logistica/armazens/${r.id}` }),
                        })
                      }
                    />
                  </Space>
                ),
            },
          ]}
        />
      </Card>
      <Modal width={larguraModal(520)} title={edicao === 'novo' ? 'Novo armazém' : 'Editar armazém'} open={edicao !== null} onCancel={() => setEdicao(null)} okText="Gravar" cancelText="Cancelar" confirmLoading={gravar.isPending} onOk={() => form.submit()}>
        <Form
          form={form}
          layout="vertical"
          onFinish={(v) => {
            const dados = { nome: v.nome, codigo: v.codigo || null, localizacao: v.localizacao || null, predefinido: !!v.predefinido };
            gravar.mutate(edicao && edicao !== 'novo' ? { metodo: 'put', url: `/logistica/armazens/${edicao.id}`, dados } : { url: '/logistica/armazens', dados });
          }}
        >
          <Form.Item name="nome" label="Nome" rules={[{ required: true, message: 'Indique o nome.' }, { max: 255 }]}>
            <Input />
          </Form.Item>
          <Form.Item name="codigo" label="Código" rules={[{ max: 20 }]}>
            <Input />
          </Form.Item>
          <Form.Item name="localizacao" label="Localização" rules={[{ max: 150 }]}>
            <Input />
          </Form.Item>
          <Form.Item name="predefinido" valuePropName="checked">
            <Checkbox>Armazém predefinido</Checkbox>
          </Form.Item>
        </Form>
      </Modal>
      <ModalContas url="/logistica/configuracao/contas" titulo="Contas da logística" chaveConsulta={['logistica', 'contas']} aberto={contas} aoFechar={() => setContas(false)} podeEditar={podeGerir} />
    </>
  );
}
