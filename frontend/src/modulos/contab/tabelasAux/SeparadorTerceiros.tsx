import { Button, Card, Col, Form, Input, Modal, Row, Select, Space, Typography } from 'antd';
import { useState } from 'react';
import { useAccao } from '@/componentes/Accoes';
import { IconeFa } from '@/componentes/fluxos/IconeFa';
import { TabelaApi, type ColunaApi } from '@/componentes/TabelaApi';
import { corpoTerceiro } from '@/modulos/compras/comum/GestaoTerceiros';
import type { FichaTerceiro } from '@/modulos/compras/comum/referencias';
import { useSessao } from '@/sessao/SessaoContexto';
import { SeletorConta } from '../comum/Seletores';
import { papelDoTerceiro, rotuloTipoTerceiro } from './tabelasAux';

type Papel = 'CLIENTE' | 'FORNECEDOR';
type ValoresTerceiro = Partial<FichaTerceiro> & { papel: Papel };

/**
 * Tabelas auxiliares › Terceiros (tab 'terceiros', js/ui_aux.js:169-277): formulário «Novo Terceiro» à esquerda e
 * «Terceiros Registados» (ID, NIF, Nome, Tipo, Ações) à direita, sobre a API comum /api/terceiros (a mesma ficha dos
 * clientes e fornecedores; as permissões são validadas no servidor: aux_gerir para gravar, aux_eliminar para eliminar).
 */
export function SeparadorTerceiros() {
  const { pode } = useSessao();
  const gerir = pode('aux_gerir');
  const eliminarPerm = pode('aux_eliminar', 'vendas_dados_del');
  const [form] = Form.useForm<ValoresTerceiro>();
  const [edicao, setEdicao] = useState<FichaTerceiro | null>(null);
  const [pesquisa, setPesquisa] = useState('');

  const limpar = () => {
    setEdicao(null);
    form.resetFields();
    form.setFieldsValue({ papel: 'CLIENTE', codigo_moeda: 'AOA' });
  };
  const gravar = useAccao<FichaTerceiro>({ invalidar: [['terceiros']], aoSucesso: limpar, tituloErro: 'Não foi possível gravar o terceiro' });
  const eliminar = useAccao({ invalidar: [['terceiros']] });

  const editar = (t: FichaTerceiro) => {
    setEdicao(t);
    form.setFieldsValue({ ...t, nome: t.nome?.trim(), papel: papelDoTerceiro(t) });
  };

  const colunas: ColunaApi<FichaTerceiro>[] = [
    { title: 'ID', dataIndex: 'id', width: 70, render: (v: number) => <Typography.Text type="secondary" style={{ fontSize: 12 }}>{v}</Typography.Text>, valorImpressao: (t) => t.id },
    { title: 'NIF', dataIndex: 'nif', render: (v: string | null) => v || '—' },
    { title: 'Nome', dataIndex: 'nome', ellipsis: true, render: (v: string) => v.trim() },
    { title: 'Tipo', key: 'tipo', render: (_, t) => rotuloTipoTerceiro(t), valorImpressao: (t) => rotuloTipoTerceiro(t) },
  ];

  return (
    <Row gutter={[16, 16]}>
      {gerir && (
        <Col xs={24} lg={8}>
          <Card
            size="small"
            title={edicao ? `Editar Terceiro ${edicao.id}` : 'Novo Terceiro'}
          >
            <Form<ValoresTerceiro>
              form={form}
              layout="vertical"
              initialValues={{ papel: 'CLIENTE', codigo_moeda: 'AOA' }}
              onFinish={(v) => {
                const dados = corpoTerceiro(v.papel, v);
                gravar.mutate(edicao ? { metodo: 'put', url: `/terceiros/${edicao.id}`, dados } : { metodo: 'post', url: '/terceiros', dados });
              }}
            >
              <Form.Item name="nif" label="NIF / Identificação" rules={[{ max: 30 }]}>
                <Input placeholder="NIF" />
              </Form.Item>
              <Form.Item name="nome" label="Nome / Entidade" rules={[{ required: true, message: 'Indique o nome.' }, { max: 255 }]}>
                <Input placeholder="Nome completo" />
              </Form.Item>
              <Form.Item name="papel" label="Tipo" rules={[{ required: true }]}>
                <Select options={[{ value: 'CLIENTE', label: 'Cliente' }, { value: 'FORNECEDOR', label: 'Fornecedor' }]} />
              </Form.Item>
              <Form.Item name="endereco" label="Endereço" rules={[{ max: 1000 }]}>
                <Input.TextArea rows={2} placeholder="Rua, n.º porta, bairro, município, província" />
              </Form.Item>
              <Form.Item name="codigo_moeda" label="Moeda por omissão">
                <Select options={['AOA', 'USD', 'EUR', 'ZAR', 'CNY'].map((m) => ({ value: m, label: m }))} />
              </Form.Item>
              <Form.Item
                name="codigo_conta"
                label={
                  <span>
                    Conta contabilística <Typography.Text type="secondary" style={{ fontSize: 12 }}>(obrigatória para clientes e fornecedores)</Typography.Text>
                  </span>
                }
                rules={[{ required: true, message: 'Associe a conta contabilística da entidade.' }]}
              >
                <SeletorConta prefixos={['31', '32']} placeholder="Ex.: 31.1.1 / 32.1.1" style={{ width: '100%' }} />
              </Form.Item>
              <Button type="primary" htmlType="submit" block icon={<IconeFa nome={edicao ? 'save' : 'plus'} />} loading={gravar.isPending}>
                {edicao ? 'Gravar Terceiro' : 'Adicionar Terceiro'}
              </Button>
              {edicao && (
                <Button block style={{ marginTop: 8 }} onClick={limpar}>
                  Cancelar edição
                </Button>
              )}
            </Form>
          </Card>
        </Col>
      )}
      <Col xs={24} lg={gerir ? 16 : 24}>
        <Typography.Title level={5} style={{ marginTop: 0 }}>
          Terceiros Registados
        </Typography.Title>
        <Space wrap style={{ marginBottom: 8 }}>
          <Input.Search placeholder="Nome ou NIF" allowClear style={{ width: 280, maxWidth: '100%' }} onSearch={setPesquisa} />
        </Space>
        <TabelaApi<FichaTerceiro>
          url="/terceiros"
          chaveConsulta={['terceiros', 'lista', 'tabelas_aux']}
          filtros={{ pesquisa }}
          columns={colunas}
          size="small"
          impressao={{ titulo: 'Terceiros Registados', filtros: [pesquisa && `Pesquisa: ${pesquisa}`] }}
          accoes={(t) => [
            { icone: 'edit', titulo: 'Editar', variante: 'primario', oculto: !gerir, onClick: () => editar(t) },
            {
              icone: 'trash',
              titulo: 'Eliminar',
              variante: 'perigo',
              oculto: !eliminarPerm,
              onClick: () =>
                Modal.confirm({
                  title: `Eliminar ${t.nome.trim()}?`,
                  content: 'A eliminação é recusada se a entidade tiver documentos ou movimentos.',
                  okText: 'Eliminar',
                  okButtonProps: { danger: true },
                  cancelText: 'Cancelar',
                  onOk: () => eliminar.mutateAsync({ metodo: 'delete', url: `/terceiros/${t.id}` }),
                }),
            },
          ]}
        />
      </Col>
    </Row>
  );
}
