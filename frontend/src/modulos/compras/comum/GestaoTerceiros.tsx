import { Button, Card, Col, Descriptions, Drawer, Form, Input, Modal, Row, Space, Table, Tag } from 'antd';
import { DeleteOutlined, EditOutlined, PlusOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';

import { useEffect, useState } from 'react';
import { obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { TabelaApi, type ColunaApi } from '@/componentes/TabelaApi';
import { BarraFiltros, larguraGaveta, larguraModal, scrollTabela, useEcraPequeno } from '@/componentes/responsivo';
import { useSessao } from '@/sessao/SessaoContexto';
import { useAccao } from '@/componentes/Accoes';
import type { FichaTerceiro } from './referencias';
import { SeletorConta } from './Seletores';

type Papel = 'CLIENTE' | 'FORNECEDOR';

interface Endereco {
  id: number;
  [chave: string]: unknown;
}

const TEXTOS: Record<Papel, { titulo: string; subtitulo: string; singular: string; gerir: string[]; prefixoConta: string }> = {
  CLIENTE: { titulo: 'Clientes', subtitulo: 'Fichas de clientes (terceiros com papel de cliente)', singular: 'cliente', gerir: ['vendas_clientes_gerir', 'aux_gerir'], prefixoConta: '31' },
  FORNECEDOR: { titulo: 'Fornecedores', subtitulo: 'Fichas de fornecedores (terceiros com papel de fornecedor)', singular: 'fornecedor', gerir: ['compras_forn_gerir', 'aux_gerir'], prefixoConta: '32' },
};

/** Valores do formulário → corpo do pedido (campos vazios vão como null para limpar o valor gravado). */
export function corpoTerceiro(papel: Papel, v: Partial<FichaTerceiro>): Record<string, unknown> {
  const texto = (x: string | null | undefined) => (x && x.trim() ? x.trim() : null);
  return {
    papel,
    nome: texto(v.nome),
    nif: texto(v.nif),
    endereco: texto(v.endereco),
    email: texto(v.email),
    telefone: texto(v.telefone),
    codigo_conta: texto(v.codigo_conta),
    conta_compra_transitoria: papel === 'FORNECEDOR' ? texto(v.conta_compra_transitoria) : undefined,
    codigo_moeda: texto(v.codigo_moeda)?.toUpperCase() ?? null,
    fe_pais: texto(v.fe_pais)?.toUpperCase() ?? null,
  };
}

/**
 * Gestão de clientes (vendas_clientes) ou fornecedores (compras_fornecedores) sobre /api/terceiros.
 * A mesma entidade pode ser cliente e fornecedor: gravar com outro papel acrescenta-o à ficha.
 */
export function GestaoTerceiros({ papel }: { papel: Papel }) {
  const t = TEXTOS[papel];
  const { pode } = useSessao();
  const podeGerir = pode(...t.gerir);
  const podeEliminar = pode('vendas_dados_del', 'aux_eliminar');
  const [pesquisa, setPesquisa] = useState('');
  const [edicao, setEdicao] = useState<FichaTerceiro | 'novo' | null>(null);
  const [vista, setVista] = useState<number | null>(null);
  const [form] = Form.useForm<FichaTerceiro>();
  const pequeno = useEcraPequeno();

  const ficha = useQuery({
    queryKey: ['terceiros', 'ficha-completa', vista],
    queryFn: () => obter<FichaTerceiro & { enderecos?: Endereco[] }>(`/terceiros/${vista}`),
    enabled: vista !== null,
  });

  const gravar = useAccao<FichaTerceiro>({
    invalidar: [['terceiros']],
    aoSucesso: () => setEdicao(null),
    tituloErro: `Não foi possível gravar o ${t.singular}`,
  });
  const eliminar = useAccao({ invalidar: [['terceiros']], aoSucesso: () => setVista(null) });

  useEffect(() => {
    if (edicao === 'novo') form.setFieldsValue({ codigo_moeda: 'AOA', fe_pais: 'AO' } as FichaTerceiro);
    else if (edicao) form.setFieldsValue(edicao);
  }, [edicao, form]);

  const confirmarEliminar = (r: FichaTerceiro) =>
    Modal.confirm({
      title: `Eliminar ${r.nome.trim()}?`,
      content: 'A eliminação é recusada se a entidade tiver documentos ou movimentos.',
      okText: 'Eliminar',
      okButtonProps: { danger: true },
      cancelText: 'Cancelar',
      onOk: () => eliminar.mutateAsync({ metodo: 'delete', url: `/terceiros/${r.id}` }),
    });

  const colunas: ColunaApi<FichaTerceiro>[] = [
    { title: 'Nome', dataIndex: 'nome', ellipsis: true, render: (v: string) => <strong>{v.trim()}</strong> },
    { title: 'NIF', dataIndex: 'nif', render: (v) => v || '—' },
    {
      title: 'Papéis',
      responsive: ['lg'],
      valorImpressao: (r) => [r.e_cliente && 'Cliente', r.e_fornecedor && 'Fornecedor'].filter(Boolean).join(', '),
      render: (_, r) => (
        <Space wrap size={4}>
          {r.e_cliente && <Tag color="blue">Cliente</Tag>}
          {r.e_fornecedor && <Tag color="purple">Fornecedor</Tag>}
        </Space>
      ),
    },
    { title: 'Telefone', dataIndex: 'telefone', responsive: ['md'], render: (v) => v || '—' },
    { title: 'Email', dataIndex: 'email', responsive: ['lg'], render: (v) => v || '—' },
    { title: 'Conta', dataIndex: 'codigo_conta', responsive: ['sm'], render: (v) => v || <Tag color="red">Sem conta</Tag> },
    { title: 'Moeda', dataIndex: 'codigo_moeda', responsive: ['lg'], render: (v) => v || 'AOA' },
    {
      title: '',
      key: 'accoes',
      align: 'right',
      render: (_, r) => (
        <Space wrap onClick={(e) => e.stopPropagation()}>
          {podeGerir && <Button size="small" icon={<EditOutlined />} onClick={() => setEdicao(r)} aria-label="Editar" />}
          {podeEliminar && <Button size="small" danger icon={<DeleteOutlined />} onClick={() => confirmarEliminar(r)} aria-label="Eliminar" />}
        </Space>
      ),
    },
  ];

  const f = ficha.data;
  return (
    <>
      <CabecalhoPagina
        titulo={t.titulo}
        subtitulo={t.subtitulo}
        accoes={
          podeGerir && (
            <Button type="primary" icon={<PlusOutlined />} onClick={() => setEdicao('novo')}>
              Novo {t.singular}
            </Button>
          )
        }
      />
      <Card>
        <BarraFiltros>
          <Input.Search placeholder="Nome ou NIF" allowClear style={{ width: 300, maxWidth: '100%' }} onSearch={setPesquisa} />
        </BarraFiltros>
        <TabelaApi<FichaTerceiro>
          url="/terceiros"
          chaveConsulta={['terceiros', 'lista', papel]}
          filtros={{ papel, pesquisa }}
          columns={colunas}
          size={pequeno ? 'small' : 'middle'}
          impressao={{ titulo: `Lista de ${t.titulo.toLowerCase()}`, filtros: [pesquisa && `Pesquisa: ${pesquisa}`] }}
          onRow={(r) => ({ onClick: () => setVista(r.id), style: { cursor: 'pointer' } })}
        />
      </Card>

      <Drawer
        title={f?.nome?.trim() ?? 'Ficha'}
        open={vista !== null}
        onClose={() => setVista(null)}
        width={larguraGaveta(560)}
        loading={ficha.isLoading}
        extra={
          f && (
            <Space wrap>
              {podeGerir && <Button icon={<EditOutlined />} onClick={() => setEdicao(f)}>Editar</Button>}
              {podeEliminar && <Button danger icon={<DeleteOutlined />} onClick={() => confirmarEliminar(f)}>Eliminar</Button>}
            </Space>
          )
        }
      >
        {f && (
          <>
            <Descriptions column={1} size="small" bordered>
              <Descriptions.Item label="NIF">{f.nif || '—'}</Descriptions.Item>
              <Descriptions.Item label="Tipo">{f.tipo ?? '—'}</Descriptions.Item>
              <Descriptions.Item label="Endereço">{f.endereco || '—'}</Descriptions.Item>
              <Descriptions.Item label="Telefone">{f.telefone || '—'}</Descriptions.Item>
              <Descriptions.Item label="Email">{f.email || '—'}</Descriptions.Item>
              <Descriptions.Item label="Conta contabilística">{f.codigo_conta || '—'}</Descriptions.Item>
              {f.e_fornecedor && <Descriptions.Item label="Conta transitória de compras">{f.conta_compra_transitoria || '—'}</Descriptions.Item>}
              <Descriptions.Item label="Moeda">{f.codigo_moeda || 'AOA'}</Descriptions.Item>
              <Descriptions.Item label="País (FE)">{f.fe_pais || '—'}</Descriptions.Item>
            </Descriptions>
            {(f.enderecos?.length ?? 0) > 0 && (
              <Card size="small" title="Endereços registados (mesmo NIF)" style={{ marginTop: 16 }}>
                <Table<Endereco>
                  size="small"
                  rowKey="id"
                  pagination={false}
                  dataSource={f.enderecos}
                  scroll={scrollTabela()}
                  columns={[
                    { title: 'Endereço', render: (_, e) => String(e.endereco ?? e.morada ?? e.descricao ?? '—') },
                    { title: 'Localidade', render: (_, e) => String(e.localidade ?? e.cidade ?? '—') },
                  ]}
                />
              </Card>
            )}
          </>
        )}
      </Drawer>

      <Modal
        title={edicao === 'novo' ? `Novo ${t.singular}` : `Editar ${t.singular}`}
        open={edicao !== null}
        onCancel={() => setEdicao(null)}
        okText="Gravar"
        cancelText="Cancelar"
        confirmLoading={gravar.isPending}
        onOk={() => form.submit()}
        width={larguraModal(720)}
        destroyOnHidden
      >
        <Form<FichaTerceiro>
          form={form}
          layout="vertical"
          preserve={false}
          onFinish={(v) =>
            gravar.mutate(
              edicao === 'novo' || edicao === null
                ? { metodo: 'post', url: '/terceiros', dados: corpoTerceiro(papel, v) }
                : { metodo: 'put', url: `/terceiros/${edicao.id}`, dados: corpoTerceiro(papel, v) },
            )
          }
        >
          <Row gutter={16}>
            <Col xs={24} md={16}>
              <Form.Item name="nome" label="Nome" rules={[{ required: true, message: 'Indique o nome.' }, { max: 255 }]}>
                <Input />
              </Form.Item>
            </Col>
            <Col xs={24} md={8}>
              <Form.Item name="nif" label="NIF" rules={[{ max: 30 }]}>
                <Input />
              </Form.Item>
            </Col>
            <Col span={24}>
              <Form.Item name="endereco" label="Endereço" rules={[{ max: 1000 }]}>
                <Input.TextArea rows={2} />
              </Form.Item>
            </Col>
            <Col xs={24} md={12}>
              <Form.Item name="email" label="Email" rules={[{ type: 'email', message: 'Email inválido.' }, { max: 150 }]}>
                <Input />
              </Form.Item>
            </Col>
            <Col xs={24} md={12}>
              <Form.Item name="telefone" label="Telefone" rules={[{ max: 50 }]}>
                <Input />
              </Form.Item>
            </Col>
            <Col xs={24} md={12}>
              <Form.Item name="codigo_conta" label="Conta contabilística" rules={[{ required: true, message: 'Associe a conta contabilística da entidade.' }]}>
                <SeletorConta prefixo={t.prefixoConta} placeholder={`Ex.: ${t.prefixoConta}121…`} />
              </Form.Item>
            </Col>
            {papel === 'FORNECEDOR' && (
              <Col xs={24} md={12}>
                <Form.Item name="conta_compra_transitoria" label="Conta transitória de compras" tooltip="Conta 3.2.8 usada na recepção de mercadoria antes da factura.">
                  <SeletorConta prefixo="328" placeholder="Ex.: 3281" />
                </Form.Item>
              </Col>
            )}
            <Col xs={12} md={6}>
              <Form.Item name="codigo_moeda" label="Moeda" rules={[{ pattern: /^[A-Za-z]{3}$/, message: 'Código ISO de 3 letras.' }]}>
                <Input maxLength={3} style={{ textTransform: 'uppercase' }} />
              </Form.Item>
            </Col>
            <Col xs={12} md={6}>
              <Form.Item name="fe_pais" label="País (FE)" rules={[{ pattern: /^[A-Za-z]{2}$/, message: 'Código ISO de 2 letras (ex.: AO).' }]}>
                <Input maxLength={2} placeholder="AO" style={{ textTransform: 'uppercase' }} />
              </Form.Item>
            </Col>
          </Row>
        </Form>
      </Modal>
    </>
  );
}
