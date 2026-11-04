import { Alert, Button, Card, Checkbox, Form, Input, InputNumber, Modal, Popconfirm, Select, Space, Table, Tag } from 'antd';
import type { ColumnsType } from 'antd/es/table';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { IconeFa } from '@/componentes/fluxos';
import { scrollTabela } from '@/componentes/responsivo';
import { useSessao } from '@/sessao/SessaoContexto';
import { formatarKz } from '@/utilitarios/formatacao';
import { elegibilidade, eliminarElegibilidade, gravarElegibilidade } from '@/modulos/estrutura/tarefas/api';
import { useMutacao } from '@/modulos/estrutura/tarefas/comum';
import { useItensProdutividade } from '@/modulos/estrutura/tarefas/EditorEntregavel';
import type { DadosElegibilidade, LinhaElegibilidade, OrigemElegibilidade } from '@/modulos/estrutura/tarefas/tipos';
import { SeletorColaborador } from '../comum/componentes';
import { AtribuirEmMassa } from './ElegibilidadeMassa';
import { useAvisarErro } from '../comum/consultas';

const ORIGENS: Record<OrigemElegibilidade, { rotulo: string; cor: string; dica: string }> = {
  CONTRATO: { rotulo: 'contrato', cor: 'blue', dica: 'Veio do antigo contrato de trabalho (migrada quando os itens saíram do contrato)' },
  MANUAL: { rotulo: 'manual', cor: 'default', dica: 'Acrescentado aqui pelo RH' },
  ENTREGAVEL: { rotulo: 'entregável', cor: 'purple', dica: 'Ganhou a elegibilidade ao ter uma entrega validada / convertida de um entregável' },
};

const CHAVE = ['rh', 'produtividade', 'elegibilidade'];

/**
 * RH › Produtividade › «Elegibilidade» (db_v2.js v1284 rh_prod_eligibility; especificação §2 e §4.2): colaborador × item
 * com a origem — contrato (migrada), manual ou entregável — preço próprio e nota; adicionar / desactivar / retirar e atribuir
 * em massa (grelha, linhas, Excel) com `rh_prod_config`. A elegibilidade já não vive no contrato de trabalho.
 */
export function Elegibilidade() {
  const { pode } = useSessao();
  const gerir = pode('rh_prod_config');
  const [colaborador, setColaborador] = useState<number>();
  const [item, setItem] = useState<number>();
  const [novo, setNovo] = useState(false);
  const [massa, setMassa] = useState(false);
  const [form] = Form.useForm<DadosElegibilidade>();
  const itens = useItensProdutividade();
  const q = useQuery({ queryKey: [...CHAVE, colaborador, item], queryFn: () => elegibilidade({ colaborador_id: colaborador, item_id: item }), retry: false });
  useAvisarErro(q.error);
  const gravar = useMutacao((d: DadosElegibilidade) => gravarElegibilidade(d), { invalidar: [['rh', 'produtividade']], aoSucesso: () => setNovo(false) });
  const retirar = useMutacao((id: number) => eliminarElegibilidade(id), { invalidar: [['rh', 'produtividade']] });
  const lista: LinhaElegibilidade[] = Array.isArray(q.data?.linhas) ? q.data.linhas : [];

  const colunas: ColumnsType<LinhaElegibilidade> = [
    { title: 'Colaborador', dataIndex: 'colaborador_nome', render: (v: string | null, l) => <b>{v ?? `#${l.colaborador_id}`}</b>, sorter: (a, b) => (a.colaborador_nome ?? '').localeCompare(b.colaborador_nome ?? '', 'pt') },
    { title: 'Item', key: 'item', render: (_, l) => `${l.item_codigo} — ${l.item_descricao}` },
    { title: 'Origem', dataIndex: 'origem', render: (o: OrigemElegibilidade) => <Tag color={ORIGENS[o]?.cor} title={ORIGENS[o]?.dica}>{ORIGENS[o]?.rotulo ?? o}</Tag> },
    { title: 'Preço próprio', dataIndex: 'preco_unitario', align: 'right', render: (v) => (v != null && Number(v) > 0 ? formatarKz(v) : <span style={{ color: '#94a3b8' }}>o do item</span>) },
    { title: 'Preço aplicado', dataIndex: 'preco_aplicado', align: 'right', responsive: ['md'], render: (v) => (v != null ? formatarKz(v) : '—') },
    { title: 'Activo', dataIndex: 'ativo', render: (v: boolean) => (v ? <Tag color="green">Sim</Tag> : <Tag>Não</Tag>) },
    { title: 'Nota', dataIndex: 'nota', responsive: ['md'], render: (v: string | null) => v ?? '' },
    {
      title: '', key: 'a', align: 'right',
      render: (_, l) => gerir && (
        <Space size={4}>
          <Button size="small" onClick={() => gravar.mutate({ colaborador_id: l.colaborador_id, item_produtividade_id: l.item_produtividade_id, ativo: !l.ativo, preco_unitario: l.preco_unitario != null ? Number(l.preco_unitario) : null, nota: l.nota })}>
            {l.ativo ? 'Desactivar' : 'Reactivar'}
          </Button>
          <Popconfirm title="Retirar esta elegibilidade?" okText="Retirar" cancelText="Cancelar" okButtonProps={{ danger: true }} onConfirm={() => retirar.mutateAsync(l.id)}>
            <Button size="small" danger type="text" aria-label={`Retirar ${l.colaborador_nome ?? l.colaborador_id}`} icon={<IconeFa nome="trash" />} />
          </Popconfirm>
        </Space>
      ),
    },
  ];

  return (
    <Card size="small" title={<Space><IconeFa nome="user-check" />Elegibilidade para o subsídio de produtividade</Space>}
      extra={gerir && (
        <Space wrap>
          <Button icon={<IconeFa nome="users" />} onClick={() => setMassa(true)}>Atribuir em massa</Button>
          <Button type="primary" icon={<IconeFa nome="plus" />} onClick={() => { form.resetFields(); setNovo(true); }}>Adicionar</Button>
        </Space>
      )}>
      <Alert type="info" showIcon style={{ marginBottom: 12 }} title="Quem pode ter registos de produtividade = colaboradores ACTIVOS com linhas activas nesta tabela (origem manual, entregável ou migrada do contrato). O preço aplicado é o da linha, se tiver; senão o do item." />
      <Space wrap style={{ marginBottom: 12 }}>
        <SeletorColaborador value={colaborador} onChange={setColaborador} />
        <Select allowClear showSearch optionFilterProp="label" placeholder="Item de produtividade" aria-label="Item" style={{ minWidth: 260 }} value={item} onChange={setItem} loading={itens.isLoading}
          options={(itens.data ?? []).map((i) => ({ value: i.id, label: `${i.codigo} — ${i.descricao}` }))} />
      </Space>
      <Table<LinhaElegibilidade> rowKey={(l) => `t${l.id}`} size="small" loading={q.isFetching} dataSource={lista} columns={colunas}
        pagination={{ pageSize: 50, hideOnSinglePage: true }} scroll={scrollTabela()} locale={{ emptyText: 'Ninguém é elegível com estes filtros.' }} />
      <AtribuirEmMassa aberto={massa} aoFechar={() => setMassa(false)} />
      <Modal open={novo} title="Tornar elegível" onCancel={() => setNovo(false)} okText="Gravar" cancelText="Cancelar" confirmLoading={gravar.isPending} onOk={() => form.submit()} destroyOnHidden>
        <Form form={form} layout="vertical" initialValues={{ ativo: true }} onFinish={(v) => gravar.mutate({ ...v, preco_unitario: v.preco_unitario ?? null, nota: v.nota?.trim() || null })}>
          <Form.Item name="colaborador_id" label="Colaborador" rules={[{ required: true, message: 'Escolha o colaborador.' }]}><SeletorColaborador apenasActivos style={{ width: '100%' }} /></Form.Item>
          <Form.Item name="item_produtividade_id" label="Item de produtividade" rules={[{ required: true, message: 'Escolha o item.' }]}>
            <Select showSearch optionFilterProp="label" options={(itens.data ?? []).filter((i) => i.ativo).map((i) => ({ value: i.id, label: `${i.codigo} — ${i.descricao} (${formatarKz(i.preco_unitario)})` }))} />
          </Form.Item>
          <Form.Item name="preco_unitario" label="Preço próprio (vazio = o do item)"><InputNumber min={0} precision={4} style={{ width: '100%' }} /></Form.Item>
          <Form.Item name="nota" label="Nota"><Input maxLength={255} /></Form.Item>
          <Form.Item name="ativo" valuePropName="checked"><Checkbox>Activa</Checkbox></Form.Item>
        </Form>
      </Modal>
    </Card>
  );
}
