import { Alert, Button, DatePicker, Form, Input, Modal, Popconfirm, Select, Skeleton, Space, Tag, Typography } from 'antd';
import { DeleteOutlined, DollarOutlined, EyeOutlined, PlusOutlined } from '@ant-design/icons';
import type { ColumnsType } from 'antd/es/table';
import { useQuery } from '@tanstack/react-query';
import dayjs, { type Dayjs } from 'dayjs';
import { useState } from 'react';
import { obter } from '@/api/cliente';
import { useSessao } from '@/sessao/SessaoContexto';
import { dataApi, formatarData, formatarKz } from '@/utilitarios/formatacao';
import { GRUPOS_PAGAMENTO, type CartaPagamento, type PeriodoSalarial } from '../api';
import { AreaImpressao, BotaoImprimir, CabecalhoMapa } from './componentes';
import { useAccaoRh, useAvisarErro } from './consultas';
import { formatarIban, mesPorExtenso } from './regras';
import { DeslocamentoHorizontal, larguraModal, scrollTabela } from '@/componentes/responsivo';

import { TabelaComModos } from '@/componentes/vistas';
interface ContaPlano {
  codigo: string;
  descricao: string | null;
}

/** Cartas de pagamento bancário de um período: emitir (conta 43), ver/imprimir, eliminar e gerar o pagamento na Tesouraria. */
export function CartasPeriodo({ periodo }: { periodo: PeriodoSalarial }) {
  const { pode, empresa } = useSessao();
  const [emitir, setEmitir] = useState(false);
  const [ver, setVer] = useState<number | null>(null);
  const [form] = Form.useForm<{ codigo_conta_bancaria: string; data: Dayjs; nome_assinatura?: string; grupo: string }>();
  const cartas = useQuery({ queryKey: ['rh', 'salarios', 'cartas', periodo.id], queryFn: () => obter<CartaPagamento[]>('/rh/salarios/cartas', { periodo_id: periodo.id }) });
  useAvisarErro(cartas.error, 'Erro ao carregar as cartas');
  const verPlano = pode('config_plano_view', 'lancamentos_view', 'relatorios_contabeis_view');
  const contas = useQuery({
    queryKey: ['rh', 'plano-contas', '43'],
    queryFn: () => obter<ContaPlano[]>('/contabilidade/plano-contas', { prefixo: '43', tipo: 'M' }),
    enabled: emitir && verPlano,
    staleTime: 600_000,
  });
  const detalhe = useQuery({ queryKey: ['rh', 'salarios', 'carta', ver], queryFn: () => obter<CartaPagamento>(`/rh/salarios/cartas/${ver}`), enabled: ver !== null });
  const accao = useAccaoRh(() => setEmitir(false));
  const podeEmitir = periodo.estado === 'VALIDADO' && pode('processamento_integrate');

  const colunas: ColumnsType<CartaPagamento> = [
    { title: 'N.º', dataIndex: 'id', render: (v: number) => <strong>#{v}</strong> },
    { title: 'Data', dataIndex: 'data', render: formatarData },
    { title: 'Grupo', dataIndex: 'grupo', render: (g: string) => GRUPOS_PAGAMENTO.find((x) => x.value === g)?.label ?? g },
    { title: 'Conta bancária', dataIndex: 'codigo_conta_bancaria', responsive: ['md'] },
    { title: 'Montante', dataIndex: 'montante_total', align: 'right', render: (v: string) => formatarKz(v, true) },
    { title: 'Pagamento', dataIndex: 'documento_tesouraria_id', render: (v: number | null) => (v ? <Tag color="green">Gerado (#{v})</Tag> : <Tag>Por pagar</Tag>) },
    {
      title: '',
      key: 'accoes',
      align: 'right',
      render: (_, c) => (
        <Space size={4}>
          <Button size="small" type="text" icon={<EyeOutlined />} aria-label="Ver" onClick={() => setVer(c.id)} />
          {!c.documento_tesouraria_id && pode('teso_doc_emitir') && (
            <Popconfirm title="Gerar o pagamento na Tesouraria?" description={periodo.contabilizado ? 'Fica PENDENTE; a integração no diário faz-se na Tesouraria.' : 'O período tem de estar contabilizado.'}
              okText="Gerar" cancelText="Cancelar" onConfirm={() => accao.mutateAsync({ metodo: 'post', url: `/rh/salarios/cartas/${c.id}/pagamento` })}>
              <Button size="small" type="text" icon={<DollarOutlined />} aria-label="Gerar pagamento" />
            </Popconfirm>
          )}
          {!c.documento_tesouraria_id && pode('processamento_integrate') && (
            <Popconfirm title="Eliminar a carta?" okText="Eliminar" okButtonProps={{ danger: true }} cancelText="Cancelar"
              onConfirm={() => accao.mutateAsync({ metodo: 'delete', url: `/rh/salarios/cartas/${c.id}` })}>
              <Button size="small" type="text" danger icon={<DeleteOutlined />} aria-label="Eliminar" />
            </Popconfirm>
          )}
        </Space>
      ),
    },
  ];

  const c = detalhe.data;
  return (
    <>
      <Space wrap style={{ marginBottom: 12 }}>
        {podeEmitir && <Button icon={<PlusOutlined />} onClick={() => { form.setFieldsValue({ data: dayjs(), grupo: 'TODOS' }); setEmitir(true); }}>Emitir carta</Button>}
        {periodo.estado !== 'VALIDADO' && <Typography.Text type="secondary">As cartas emitem-se de períodos validados.</Typography.Text>}
      </Space>
      <TabelaComModos<CartaPagamento> rowKey="id" size="small" loading={cartas.isFetching} columns={colunas} dataSource={cartas.data ?? []} pagination={false} scroll={scrollTabela()} />

      <Modal title="Emitir carta de pagamento" open={emitir} onCancel={() => setEmitir(false)} okText="Emitir" cancelText="Cancelar" confirmLoading={accao.isPending} onOk={() => form.submit()} destroyOnHidden>
        <Typography.Paragraph type="secondary">Inclui os salários líquidos ainda sem carta (do grupo escolhido). É recusada se algum colaborador não tiver IBAN.</Typography.Paragraph>
        <Form form={form} layout="vertical" onFinish={(v) => accao.mutate({ metodo: 'post', url: `/rh/salarios/periodos/${periodo.id}/cartas`, dados: { ...v, data: dataApi(v.data) } })}>
          <Form.Item name="codigo_conta_bancaria" label="Conta bancária (43…)" rules={[{ required: true, message: 'Indique a conta.' }, { pattern: /^43/, message: 'Tem de ser uma conta bancária (43).' }]}>
            {verPlano ? (
              <Select showSearch loading={contas.isLoading} optionFilterProp="label" options={(contas.data ?? []).map((x) => ({ value: x.codigo, label: `${x.codigo} — ${x.descricao ?? ''}` }))} />
            ) : (
              <Input maxLength={20} placeholder="43…" />
            )}
          </Form.Item>
          <Form.Item name="data" label="Data" rules={[{ required: true }]}><DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} /></Form.Item>
          <Form.Item name="grupo" label="Grupo"><Select options={GRUPOS_PAGAMENTO} /></Form.Item>
          <Form.Item name="nome_assinatura" label="Assinatura"><Input maxLength={255} /></Form.Item>
        </Form>
      </Modal>

      <Modal title={`Carta de pagamento #${ver ?? ''}`} open={ver !== null} width={larguraModal(860)} onCancel={() => setVer(null)}
        footer={<Space wrap><BotaoImprimir desactivado={!c} /><Button onClick={() => setVer(null)}>Fechar</Button></Space>}>
        {detalhe.isLoading || !c ? <Skeleton active /> : (
          <AreaImpressao>
            <CabecalhoMapa empresa={empresa?.nome} titulo="Carta de pagamento de salários" mesAno={c.mes_ano} extra={<div>Data: {formatarData(c.data)} · Conta a debitar: {c.codigo_conta_bancaria}</div>} />
            <Typography.Paragraph>
              Solicitamos a transferência dos salários de {mesPorExtenso(c.mes_ano)} para as contas abaixo indicadas, no montante total de <strong>{formatarKz(c.montante_total, true)}</strong>.
            </Typography.Paragraph>
            <DeslocamentoHorizontal>
            <table className="rh-tabela-mapa">
              <thead><tr><th>#</th><th style={{ textAlign: 'left' }}>Beneficiário</th><th>IBAN</th><th>Montante (Kz)</th></tr></thead>
              <tbody>
                {(c.itens ?? []).map((i, n) => (
                  <tr key={i.id}><td className="num">{n + 1}</td><td>{i.nome ?? `#${i.colaborador_id}`}</td><td><code>{formatarIban(i.iban)}</code></td><td className="num">{formatarKz(i.montante)}</td></tr>
                ))}
              </tbody>
              <tfoot><tr><td colSpan={3}>Total</td><td className="num">{formatarKz(c.montante_total)}</td></tr></tfoot>
            </table>
            </DeslocamentoHorizontal>
            {c.documento_tesouraria && <Alert className="rh-nao-imprimir" style={{ marginTop: 12 }} type="success" showIcon message={`Pagamento ${c.documento_tesouraria.numero_documento} (${c.documento_tesouraria.estado}).`} />}
            <div className="rh-recibo-assinatura"><div>{c.nome_assinatura ?? 'Assinatura autorizada'}</div></div>
          </AreaImpressao>
        )}
      </Modal>
    </>
  );
}
