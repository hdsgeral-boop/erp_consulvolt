import { Button, Card, Col, Form, Input, Modal, Row, Space, Tag } from 'antd';
import { PlusOutlined } from '@ant-design/icons';
import { useState } from 'react';
import { useAccao } from '@/componentes/Accoes';
import { TabelaApi, type ColunaApi } from '@/componentes/TabelaApi';
import { BarraFiltros, useEcraPequeno } from '@/componentes/responsivo';
import { useSessao } from '@/sessao/SessaoContexto';
import { SeletorConta } from '../comum/Seletores';
import type { FichaTerceiro } from '@/modulos/compras/comum/referencias';
import { BotaoValidarNifAgt } from '@/modulos/compras/comum/ValidarNifAgt';
import { contaCredorDevedor, prefixosConta } from './contasTerceiro';

interface ValoresCredorDevedor {
  nome: string;
  nif?: string;
  conta_credor_devedor: string;
}

/** Célula de conta (ui_aux.js:184-186): código em etiqueta; sem conta, «por definir» a vermelho. */
function CelulaConta({ codigo }: { codigo: string | null | undefined }) {
  return codigo ? <Tag style={{ fontWeight: 600 }}>{codigo}</Tag> : <span style={{ color: '#b91c1c' }} title="Sem conta associada — a integração vai parar aqui">por definir</span>;
}

/** Corpo do pedido POST/PUT /terceiros para o papel autónomo Credor/Devedor (sem cliente/fornecedor). */
function corpoCredorDevedor(v: ValoresCredorDevedor): Record<string, unknown> {
  return { papel: 'CREDOR_DEVEDOR', nome: v.nome.trim(), nif: v.nif?.trim() || null, conta_credor_devedor: v.conta_credor_devedor.trim() };
}

/**
 * Contabilidade › Tabelas auxiliares › Credores e Devedores: terceira instância do padrão de Clientes/Fornecedores
 * (GestaoTerceiros), para o papel novo e autónomo «Credor/Devedor» (conta corrente genérica, classe 37 — não é
 * cliente nem fornecedor). Sobre /api/terceiros?papel=CREDOR_DEVEDOR.
 */
export function CredoresDevedores() {
  const { pode } = useSessao();
  const podeGerir = pode('credores_devedores_gerir', 'aux_gerir');
  const podeEliminar = pode('aux_eliminar', 'vendas_dados_del');
  const [pesquisa, setPesquisa] = useState('');
  const [edicao, setEdicao] = useState<FichaTerceiro | 'novo' | null>(null);
  const [form] = Form.useForm<ValoresCredorDevedor>();
  const nifActual = Form.useWatch('nif', form);
  const pequeno = useEcraPequeno();

  const gravar = useAccao<FichaTerceiro>({ invalidar: [['terceiros']], aoSucesso: () => setEdicao(null), tituloErro: 'Não foi possível gravar o credor/devedor' });
  const eliminar = useAccao({ invalidar: [['terceiros']] });

  const abrir = (t: FichaTerceiro | 'novo') => {
    setEdicao(t);
    if (t === 'novo') form.resetFields();
    else form.setFieldsValue({ nome: t.nome?.trim(), nif: t.nif ?? undefined, conta_credor_devedor: contaCredorDevedor(t) || undefined });
  };

  const confirmarEliminar = (t: FichaTerceiro) =>
    Modal.confirm({
      title: `Eliminar ${t.nome.trim()}?`,
      content: 'A eliminação é recusada se a entidade tiver documentos ou movimentos.',
      okText: 'Eliminar',
      okButtonProps: { danger: true },
      cancelText: 'Cancelar',
      onOk: () => eliminar.mutateAsync({ metodo: 'delete', url: `/terceiros/${t.id}` }),
    });

  const colunas: ColunaApi<FichaTerceiro>[] = [
    { title: 'Nome', dataIndex: 'nome', ellipsis: true, filtroServidor: 'pesquisa', render: (v: string) => v.trim() },
    { title: 'NIF', dataIndex: 'nif', render: (v) => v || '—' },
    { title: <span title="Conta de credor/devedor (classe 37)">Conta (classe 37)</span>, key: 'conta_credor_devedor', render: (_, t) => <CelulaConta codigo={contaCredorDevedor(t)} />, valorImpressao: (t) => contaCredorDevedor(t) || '-' },
  ];

  return (
    <>
      {edicao !== null && (
        <Card
          style={{ borderTop: '4px solid var(--erp-primaria, #2563eb)', marginBottom: '2rem' }}
          title={edicao === 'novo' ? 'Novo Credor/Devedor' : 'Editar Credor/Devedor'}
          extra={<Button size="small" onClick={() => setEdicao(null)} aria-label="Fechar">×</Button>}
        >
          <Form<ValoresCredorDevedor>
            form={form}
            layout="vertical"
            onFinish={(v) =>
              gravar.mutate(
                edicao === 'novo'
                  ? { metodo: 'post', url: '/terceiros', dados: corpoCredorDevedor(v) }
                  : { metodo: 'put', url: `/terceiros/${edicao.id}`, dados: corpoCredorDevedor(v) },
              )
            }
          >
            <Row gutter={16}>
              <Col xs={24} md={14}>
                <Form.Item name="nome" label="Nome / Entidade" rules={[{ required: true, message: 'Indique o nome.' }, { max: 255 }]}>
                  <Input />
                </Form.Item>
              </Col>
              <Col xs={24} md={10}>
                <Form.Item name="nif" label="NIF" rules={[{ max: 30 }]}>
                  <Input addonAfter={<BotaoValidarNifAgt nif={nifActual} tamanho="small" apenasIcone />} />
                </Form.Item>
              </Col>
              <Col xs={24} md={12}>
                <Form.Item
                  name="conta_credor_devedor"
                  label="Conta de credor/devedor (classe 37)"
                  rules={[{ required: true, message: 'Indique a conta de credor/devedor (classe 37).' }]}
                >
                  <SeletorConta prefixos={prefixosConta('CREDOR_DEVEDOR')} placeholder="Ex.: 37.1.1" allowClear style={{ width: '100%' }} />
                </Form.Item>
              </Col>
            </Row>
            <Space>
              <Button type="primary" htmlType="submit" loading={gravar.isPending}>Guardar</Button>
              <Button onClick={() => setEdicao(null)}>Cancelar</Button>
            </Space>
          </Form>
        </Card>
      )}
      <Card
        title="Credores e Devedores"
        extra={podeGerir && (
          <Button size="small" type="primary" icon={<PlusOutlined />} onClick={() => abrir('novo')}>
            Novo Credor/Devedor
          </Button>
        )}
      >
        <BarraFiltros>
          <Input.Search placeholder="Nome ou NIF" allowClear style={{ width: 300, maxWidth: '100%' }} onSearch={setPesquisa} />
        </BarraFiltros>
        <TabelaApi<FichaTerceiro>
          url="/terceiros"
          chaveConsulta={['terceiros', 'lista', 'CREDOR_DEVEDOR']}
          filtros={{ papel: 'CREDOR_DEVEDOR', pesquisa }}
          columns={colunas}
          size={pequeno ? 'small' : 'middle'}
          impressao={{ titulo: 'Credores e Devedores', filtros: [pesquisa && `Pesquisa: ${pesquisa}`] }}
          accoes={(t) => [
            { icone: 'edit', titulo: 'Editar', oculto: !podeGerir, onClick: () => abrir(t) },
            { icone: 'trash', titulo: 'Eliminar', variante: 'perigo', cheio: true, oculto: !podeEliminar, onClick: () => confirmarEliminar(t) },
          ]}
          vazio={{ icone: 'id-card', titulo: 'Sem credores/devedores', texto: 'Ainda não há nenhum registado.' }}
        />
      </Card>
    </>
  );
}
