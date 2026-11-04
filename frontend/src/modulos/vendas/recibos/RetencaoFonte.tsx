import { Alert, Button, Card, Col, DatePicker, Flex, Form, Input, InputNumber, Modal, Row, Select, Space, Statistic, Switch, Table, Tag, Typography } from 'antd';
import { SettingOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import dayjs from 'dayjs';
import { useEffect, useState } from 'react';
import { obter } from '@/api/cliente';
import { useAccao } from '@/componentes/Accoes';
import { larguraModal } from '@/componentes/responsivo';
import { SeletorConta } from '@/modulos/compras/comum/Seletores';
import { formatarKz } from '@/utilitarios/formatacao';
import { resumirRecibo, type EstadoRetencao, type TipoRetencao } from './retencao';

interface RespostaTipos {
  tipos: TipoRetencao[];
  conta_imposto_padrao: string;
  conta_sugerida: string;
}

/** Tipos de retenção na fonte da empresa (GET /vendas/configuracao/retencoes). */
export function useTiposRetencao(soActivos = true, activo = true) {
  return useQuery({
    queryKey: ['vendas', 'retencoes', soActivos],
    queryFn: () => obter<RespostaTipos>('/vendas/configuracao/retencoes', { so_activos: soActivos ? 1 : undefined }),
    enabled: activo,
  });
}

/**
 * Linha «Retenção na fonte» do recibo: interruptor, tipo, percentagem, base (por omissão o total a liquidar), valor retido
 * (calculado e editável, com aviso) e comprovativo; e o resumo «total liquidado / retenção / valor a receber (líquido)».
 */
export function BlocoRetencao({ total, valor, aoMudar, podeConfigurar }: { total: number; valor: EstadoRetencao; aoMudar: (v: EstadoRetencao) => void; podeConfigurar: boolean }) {
  const tipos = useTiposRetencao(true);
  const [gerir, setGerir] = useState(false);
  const resumo = resumirRecibo(total, valor);
  const lista = tipos.data?.tipos ?? [];
  const mudar = (p: Partial<EstadoRetencao>) => aoMudar({ ...valor, ...p });

  const escolherTipo = (id: number | undefined) => {
    const t = lista.find((x) => x.id === id);
    mudar({ tipoId: id, percentagem: t ? Number(t.percentagem) : valor.percentagem, valor: null });
  };
  const ligar = (aplicar: boolean) => {
    if (aplicar && valor.tipoId === undefined && lista.length) {
      const t = lista[0];
      aoMudar({ ...valor, aplicar, tipoId: t.id, percentagem: Number(t.percentagem), valor: null });
    } else mudar({ aplicar });
  };

  return (
    <Card
      title="Retenção na fonte"
      size="small"
      style={{ marginTop: 16 }}
      extra={podeConfigurar && <Button type="link" size="small" icon={<SettingOutlined />} onClick={() => setGerir(true)}>Tipos de retenção</Button>}
      data-testid="bloco-retencao"
    >
      <Space style={{ marginBottom: 12 }}>
        <Switch checked={valor.aplicar} onChange={ligar} aria-label="Aplicar retenção na fonte" />
        <span>Aplicar retenção na fonte</span>
        <Typography.Text type="secondary">O cliente retém este imposto ao pagar e entrega-o ao Estado: o recibo liquida as facturas pelo valor bruto e só entra em caixa/banco o líquido.</Typography.Text>
      </Space>
      {valor.aplicar && (
        <>
          <Row gutter={16}>
            <Col xs={24} md={8}>
              <Form.Item label="Tipo de retenção" style={{ marginBottom: 12 }}>
                <Select<number> value={valor.tipoId} onChange={escolherTipo} loading={tipos.isFetching} aria-label="Tipo de retenção" placeholder="Tipo de retenção"
                  options={lista.map((t) => ({ value: t.id, label: `${t.descricao} (${Number(t.percentagem)} %)` }))} />
              </Form.Item>
            </Col>
            <Col xs={12} md={4}>
              <Form.Item label="Percentagem (%)" style={{ marginBottom: 12 }}>
                <InputNumber min={0} max={100} precision={2} style={{ width: '100%' }} value={valor.percentagem} aria-label="Percentagem da retenção" onChange={(v) => mudar({ percentagem: v, valor: null })} />
              </Form.Item>
            </Col>
            <Col xs={12} md={6}>
              <Form.Item label="Base de incidência (Kz)" tooltip="Valor sobre o qual incide a retenção (por omissão, o total a liquidar; por exemplo, só a parte de serviços). Não pode exceder o total." style={{ marginBottom: 12 }}>
                <InputNumber min={0} max={total} precision={2} style={{ width: '100%' }} value={valor.base ?? resumo.base} aria-label="Base de incidência" onChange={(v) => mudar({ base: v, valor: null })} />
              </Form.Item>
            </Col>
            <Col xs={24} md={6}>
              <Form.Item label="Valor retido (Kz)" style={{ marginBottom: 12 }}>
                <InputNumber min={0} precision={2} style={{ width: '100%' }} value={resumo.retido} aria-label="Valor retido" onChange={(v) => mudar({ valor: v })} />
              </Form.Item>
            </Col>
            <Col xs={24} md={8}>
              <Form.Item label="Comprovativo (n.º / guia de retenção)" style={{ marginBottom: 12 }}>
                <Input maxLength={100} value={valor.referencia} aria-label="Referência do comprovativo" onChange={(e) => mudar({ referencia: e.target.value })} />
              </Form.Item>
            </Col>
            <Col xs={12} md={6}>
              <Form.Item label="Data do comprovativo" style={{ marginBottom: 12 }}>
                <DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} value={valor.data ? dayjs(valor.data) : null} onChange={(d) => mudar({ data: d ? d.format('YYYY-MM-DD') : null })} />
              </Form.Item>
            </Col>
          </Row>
          {resumo.editado && !resumo.erro && (
            <Alert type="warning" showIcon style={{ marginBottom: 12 }}
              title={`O valor retido (${formatarKz(resumo.retido)} Kz) difere do calculado (${formatarKz(resumo.calculado)} Kz). Confirme pelo comprovativo do cliente: é o valor que fica registado.`}
              action={<Button size="small" onClick={() => mudar({ valor: null })}>Repor o calculado</Button>} />
          )}
          {resumo.erro && <Alert type="error" showIcon style={{ marginBottom: 12 }} title={resumo.erro} />}
        </>
      )}
      <Flex justify="end" gap={32} wrap data-testid="resumo-recibo">
        <Statistic title="Total das facturas liquidadas (Kz)" value={formatarKz(resumo.total)} />
        {valor.aplicar && <Statistic title={`Retenção na fonte${valor.percentagem !== null ? ` (${valor.percentagem} %)` : ''} (Kz)`} value={`− ${formatarKz(resumo.retido)}`} />}
        <Statistic title="Valor a receber (líquido) (Kz)" value={formatarKz(resumo.liquido)} />
      </Flex>
      {podeConfigurar && <ModalTiposRetencao aberto={gerir} aoFechar={() => setGerir(false)} />}
    </Card>
  );
}

interface ValoresTipo {
  codigo: string;
  descricao: string;
  percentagem: number;
  conta_imposto?: string | null;
  ativo: boolean;
}

/** Configuração dos tipos de retenção na fonte (permissão vendas_config): criar, editar e eliminar; inclui 0 %. */
export function ModalTiposRetencao({ aberto, aoFechar }: { aberto: boolean; aoFechar: () => void }) {
  const tipos = useTiposRetencao(false, aberto);
  const [form] = Form.useForm<ValoresTipo>();
  const [aEditar, setAEditar] = useState<TipoRetencao | 'novo' | null>(null);
  const accao = useAccao({ invalidar: [['vendas', 'retencoes']], aoSucesso: () => setAEditar(null) });
  useEffect(() => {
    if (aEditar === 'novo') form.setFieldsValue({ codigo: '', descricao: '', percentagem: 0, conta_imposto: null, ativo: true });
    else if (aEditar) form.setFieldsValue({ codigo: aEditar.codigo, descricao: aEditar.descricao, percentagem: Number(aEditar.percentagem), conta_imposto: aEditar.conta_imposto, ativo: aEditar.ativo });
  }, [aEditar, form]);

  return (
    <Modal title="Tipos de retenção na fonte" open={aberto} onCancel={aoFechar} footer={<Button onClick={aoFechar}>Fechar</Button>} width={larguraModal(820)}>
      <Alert type="info" showIcon style={{ marginBottom: 12 }}
        title={`Percentagens e conta do imposto retido são sugestões por omissão: confirme-as com o seu contabilista. Conta do imposto por omissão: ${tipos.data?.conta_imposto_padrao ?? '…'} (altere-a em Contas de vendas, «Imposto retido na fonte pelos clientes»).`} />
      <Table<TipoRetencao>
        rowKey="id" size="small" pagination={false} loading={tipos.isFetching} dataSource={tipos.data?.tipos ?? []}
        columns={[
          { title: 'Código', dataIndex: 'codigo' },
          { title: 'Descrição', dataIndex: 'descricao' },
          { title: '%', dataIndex: 'percentagem', align: 'right', render: (v: string) => Number(v) },
          { title: 'Conta', dataIndex: 'conta_imposto', render: (v: string | null) => v ?? 'por omissão' },
          { title: 'Estado', dataIndex: 'ativo', render: (v: boolean) => (v ? <Tag color="green">Activo</Tag> : <Tag>Inactivo</Tag>) },
          {
            title: '', key: 'acoes', render: (_, t) => (
              <Space>
                <Button size="small" onClick={() => setAEditar(t)}>Editar</Button>
                <Button size="small" danger onClick={() => Modal.confirm({ title: `Eliminar o tipo ${t.codigo}?`, content: 'Os recibos já emitidos mantêm os dados da retenção.', okText: 'Eliminar', okButtonProps: { danger: true }, cancelText: 'Cancelar',
                  onOk: () => accao.mutateAsync({ metodo: 'delete', url: `/vendas/configuracao/retencoes/${t.id}` }) })}>Eliminar</Button>
              </Space>
            ),
          },
        ]}
      />
      <Button style={{ marginTop: 12 }} onClick={() => setAEditar('novo')}>Novo tipo</Button>
      <Modal title={aEditar === 'novo' ? 'Novo tipo de retenção' : 'Editar tipo de retenção'} open={aEditar !== null} onCancel={() => setAEditar(null)} okText="Gravar" cancelText="Cancelar"
        confirmLoading={accao.isPending} onOk={() => form.submit()} destroyOnHidden>
        <Form<ValoresTipo> form={form} layout="vertical"
          onFinish={(v) => accao.mutate(aEditar === 'novo'
            ? { url: '/vendas/configuracao/retencoes', dados: { ...v, conta_imposto: v.conta_imposto || null } }
            : { metodo: 'put', url: `/vendas/configuracao/retencoes/${(aEditar as TipoRetencao).id}`, dados: { ...v, conta_imposto: v.conta_imposto || null } })}>
          <Form.Item name="codigo" label="Código" rules={[{ required: true, message: 'Indique o código.' }, { max: 30 }]}><Input /></Form.Item>
          <Form.Item name="descricao" label="Descrição" rules={[{ required: true, message: 'Indique a descrição.' }, { max: 200 }]}><Input /></Form.Item>
          <Form.Item name="percentagem" label="Percentagem por omissão (%)" rules={[{ required: true, message: 'Indique a percentagem (0 a 100).' }]}><InputNumber min={0} max={100} precision={2} style={{ width: '100%' }} /></Form.Item>
          <Form.Item name="conta_imposto" label="Conta do imposto retido (opcional)" tooltip="Se vazia, usa a conta das contas de vendas (por omissão 34.1)."><SeletorConta prefixo="34" /></Form.Item>
          <Form.Item name="ativo" label="Activo" valuePropName="checked"><Switch /></Form.Item>
        </Form>
      </Modal>
    </Modal>
  );
}

/** Texto curto da retenção de um recibo, para a lista e o detalhe. */
export function textoRetencao(percentagem: string | number, valor: string | number): string {
  return `${Number(percentagem)} % — ${formatarKz(valor)} Kz`;
}
