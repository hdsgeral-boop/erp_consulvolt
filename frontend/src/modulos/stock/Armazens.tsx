import { Button, Card, Checkbox, Flex, Form, Input, Modal, Tag } from 'antd';
import { useQuery } from '@tanstack/react-query';
import { useEffect, useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { obter } from '@/api/cliente';
import { IconeFa } from '@/componentes/fluxos/IconeFa';
import { useSessao } from '@/sessao/SessaoContexto';
import { useAccao } from '@/componentes/Accoes';
import { ModalContas } from '@/modulos/compras/comum/ModalContas';
import { useArmazens, type Armazem } from '@/modulos/compras/comum/referencias';
import { larguraModal, scrollTabela } from '@/componentes/responsivo';
import { TabelaComModos, type ColunaLegado } from '@/componentes/vistas';
import { formatarKz } from '@/utilitarios/formatacao';

type LinhaArmazem = Armazem & { valor_stock: string };

/**
 * Armazém › Config. Armazens (ecrã armazem_armazens), como os «Armazens Registados» do legado (renderArmazensTab,
 * js/ui_warehouse.js:1378): nome, localização, valor em stock (custo médio) com o «VALOR TOTAL GLOBAL», «Ver Stock»
 * (níveis filtrados pelo armazém), editar e eliminar; as contas da logística continuam aqui.
 */
export default function Armazens() {
  const { pode } = useSessao();
  const navegar = useNavigate();
  const podeGerir = pode('armazem_config');
  const consulta = useArmazens();
  const valores = useQuery({
    queryKey: ['logistica', 'paridade', 'armazens-valor'],
    queryFn: () => obter<{ total: string; por_armazem: { armazem_id: number; valor: string }[] }>('/logistica/paridade/armazens/valor'),
    enabled: pode('armazem_armazens_view', 'armazem_stock_view'),
  });
  const [edicao, setEdicao] = useState<Armazem | 'novo' | null>(null);
  const [contas, setContas] = useState(false);
  const [form] = Form.useForm<{ nome: string; codigo?: string; localizacao?: string; predefinido?: boolean }>();
  const gravar = useAccao({ invalidar: [['logistica']], aoSucesso: () => setEdicao(null), tituloErro: 'Não foi possível gravar o armazém' });
  const eliminar = useAccao({ invalidar: [['logistica']] });

  useEffect(() => {
    if (edicao === 'novo') form.setFieldsValue({ nome: '', codigo: undefined, localizacao: undefined, predefinido: false });
    else if (edicao) form.setFieldsValue({ nome: edicao.nome, codigo: edicao.codigo ?? undefined, localizacao: edicao.localizacao ?? undefined, predefinido: !!edicao.predefinido });
  }, [edicao, form]);

  const linhas = useMemo<LinhaArmazem[]>(() => {
    const porArmazem = new Map((valores.data?.por_armazem ?? []).map((v) => [v.armazem_id, v.valor]));
    return (consulta.data ?? []).map((a) => ({ ...a, valor_stock: porArmazem.get(a.id) ?? '0.00' }));
  }, [consulta.data, valores.data]);
  // 2.5.5: o legado não tem «Código»; só aparece quando algum armazém o tiver preenchido
  const temCodigo = linhas.some((a) => a.codigo?.trim());

  const colunas: ColunaLegado<LinhaArmazem>[] = [
    { title: 'Nome', dataIndex: 'nome', valorImpressao: (a) => `${a.nome}${a.predefinido ? ' (predefinido)' : ''}`, render: (v: string, r) => <><strong>{v}</strong>{r.predefinido && <Tag color="blue" style={{ marginLeft: 8 }}>Predefinido</Tag>}</> },
    ...(temCodigo ? [{ title: 'Código', dataIndex: 'codigo', render: (v: string | null) => v || '—' } as ColunaLegado<LinhaArmazem>] : []),
    { title: 'Localização', dataIndex: 'localizacao', render: (v: string | null) => <small style={{ color: '#64748b' }}>{v || '-'}</small>, valorImpressao: (a) => a.localizacao || '-' },
    {
      title: 'Valor em Stock',
      dataIndex: 'valor_stock',
      align: 'right',
      total: true,
      formatoTotal: (v) => <span style={{ color: 'var(--ant-color-primary, #2563eb)', fontSize: '1.05rem' }}>{formatarKz(v)}</span>,
      render: (v: string) => <strong style={{ color: 'var(--ant-color-primary, #2563eb)' }}>{formatarKz(v)}</strong>,
    },
  ];

  return (
    <>
      <Card
        title="Armazens Registados"
        extra={
          <Flex gap={8} wrap>
            {pode('armazem_config', 'armazem_stock_view') && <Button size="small" icon={<IconeFa nome="cog" />} onClick={() => setContas(true)}>Contas da logística</Button>}
            {podeGerir && <Button size="small" type="primary" icon={<IconeFa nome="plus" />} onClick={() => setEdicao('novo')}>Novo Armazém</Button>}
          </Flex>
        }
      >
        <TabelaComModos<LinhaArmazem>
          scroll={scrollTabela()}
          rowKey="id"
          loading={consulta.isFetching || valores.isFetching}
          dataSource={linhas}
          pagination={false}
          columns={colunas}
          rotuloTotal={linhas.length ? 'VALOR TOTAL GLOBAL (TODOS Armazens):' : false}
          vazio={{ icone: 'warehouse', titulo: 'Nenhum Armazém registado.' }}
          // legado (renderArmazensTab): tabela simples, sem a barra DataTables nem filtros por coluna
          contador={false}
          menuColunas={false}
          filtrosColuna={false}
          filtroPersonalizado={false}
          accoes={(r) => [
            { icone: 'boxes', titulo: 'Ver Stock Detalhado', texto: 'Ver Stock', oculto: !pode('armazem_stock_view'), onClick: () => navegar(`/m/stock/armazem_stock?armazem=${r.id}`) },
            { icone: 'edit', titulo: 'Editar Armazém', oculto: !podeGerir, onClick: () => setEdicao(r) },
            {
              icone: 'trash',
              titulo: 'Apagar Armazém',
              variante: 'perigo',
              cheio: true,
              oculto: !podeGerir,
              confirmar: { titulo: `Eliminar o armazém ${r.nome}?`, texto: 'É recusado se o armazém tiver stock ou movimentos.', textoOk: 'Eliminar', perigo: true },
              onClick: () => eliminar.mutateAsync({ metodo: 'delete', url: `/logistica/armazens/${r.id}` }),
            },
          ]}
        />
      </Card>
      <Modal width={larguraModal(520)} title={edicao === 'novo' ? 'Registar Armazém' : 'Editar Armazém'} open={edicao !== null} onCancel={() => setEdicao(null)} okText="Gravar" cancelText="Cancelar" confirmLoading={gravar.isPending} onOk={() => form.submit()}>
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
