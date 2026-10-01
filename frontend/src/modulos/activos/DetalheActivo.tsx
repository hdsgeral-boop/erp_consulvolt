import { Alert, Button, Card, DatePicker, Descriptions, Modal, Popconfirm, Result, Space, Spin, Table, Tabs, Tag, Typography } from 'antd';
import { ArrowLeftOutlined, CloudUploadOutlined, DeleteOutlined, EditOutlined, StopOutlined, SwapOutlined, ToolOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import dayjs from 'dayjs';
import { useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { useSessao } from '@/sessao/SessaoContexto';
import { ValorKz } from '@/modulos/contab/comum/Componentes';
import { somar } from '@/modulos/contab/comum/decimal';
import { useAccao } from '@/modulos/compras/comum/accoes';
import { formatarData, formatarKz } from '@/utilitarios/formatacao';
import { EtiquetaActivos } from './comum/componentes';
import { accoesActivo, ordemPeriodo, rotuloPeriodo } from './comum/regras';
import type { AmortizacaoRegisto, FichaActivo } from './comum/tipos';
import { ModalActivo } from './ModalActivo';
import { ModalAfectacao, ModalTransferir } from './ModaisActivos';
import { ModalAbate } from './ModalAbate';
import { ModalManutencao } from './Manutencao';

/** Ficha do activo com o histórico: amortizações, transferências, manutenções, abates, afectações e lançamento de origem. */
export function DetalheActivo() {
  const { id } = useParams();
  const navegar = useNavigate();
  const { pode } = useSessao();
  const [editar, setEditar] = useState(false);
  const [transferir, setTransferir] = useState(false);
  const [afectar, setAfectar] = useState(false);
  const [abater, setAbater] = useState(false);
  const [manutencao, setManutencao] = useState(false);
  const [integrar, setIntegrar] = useState(false);
  const [ate, setAte] = useState(dayjs().subtract(1, 'month'));
  const q = useQuery({ queryKey: ['activos', 'ficha', id], queryFn: () => obter<FichaActivo>(`/ativos/bens/${id}`) });
  const eliminar = useAccao({ invalidar: [['activos']], aoSucesso: () => navegar('..') });
  const integrarAccao = useAccao({ invalidar: [['activos']], aoSucesso: () => setIntegrar(false) });

  if (q.isLoading) return <Spin style={{ display: 'block', margin: 48 }} />;
  if (q.error || !q.data) return <Result status="404" title="Activo não encontrado" extra={<Button onClick={() => navegar('..')}>Voltar</Button>} />;
  const a = q.data;
  const acc = accoesActivo(a, pode);
  const amortizacoes = [...a.amortizacoes].sort((x, y) => ordemPeriodo(y.periodo_codigo) - ordemPeriodo(x.periodo_codigo));

  return (
    <>
      <CabecalhoPagina
        titulo={
          <Space>
            <Button type="text" icon={<ArrowLeftOutlined />} onClick={() => navegar('..')} />
            {a.codigo} — {a.descricao}
            <EtiquetaActivos valor={a.estado} />
            {a.bloqueado && <Tag>Valores bloqueados</Tag>}
          </Space>
        }
        accoes={
          <>
            {acc.editar && <Button icon={<EditOutlined />} onClick={() => setEditar(true)}>Editar</Button>}
            {acc.transferir && <Button icon={<SwapOutlined />} onClick={() => setTransferir(true)}>Transferir</Button>}
            {acc.afectar && <Button onClick={() => setAfectar(true)}>Afectar a projecto</Button>}
            {pode('activos_manut') && a.estado !== 'ABATIDO' && <Button icon={<ToolOutlined />} onClick={() => setManutencao(true)}>Manutenção</Button>}
            {acc.integrar && <Button icon={<CloudUploadOutlined />} onClick={() => setIntegrar(true)}>Integrar amortizações</Button>}
            {acc.abater && <Button danger icon={<StopOutlined />} onClick={() => setAbater(true)}>Abater / vender</Button>}
            {acc.eliminar && (
              <Popconfirm title="Eliminar este activo?" description="Só é possível sem amortizações, abates nem lançamentos associados." okText="Eliminar" cancelText="Cancelar" okButtonProps={{ danger: true }}
                onConfirm={() => eliminar.mutate({ metodo: 'delete', url: `/ativos/bens/${a.id}` })}>
                <Button danger icon={<DeleteOutlined />} loading={eliminar.isPending} />
              </Popconfirm>
            )}
          </>
        }
      />
      <Card style={{ marginBottom: 16 }}>
        <Descriptions size="small" column={{ xs: 1, md: 2, xl: 4 }}>
          <Descriptions.Item label="Categoria">{a.categoria_ativo?.nome ?? '—'}</Descriptions.Item>
          <Descriptions.Item label="Taxa anual">{a.categoria_ativo?.taxa_anual ? `${Number(a.categoria_ativo.taxa_anual).toLocaleString('pt-PT')}%` : '—'}</Descriptions.Item>
          <Descriptions.Item label="Centro de custo">{a.centro_custo ? `${a.centro_custo.codigo} — ${a.centro_custo.descricao ?? ''}` : '—'}</Descriptions.Item>
          <Descriptions.Item label="Unidade de negócio">{a.unidade_negocio?.codigo ?? '—'}</Descriptions.Item>
          <Descriptions.Item label="Fornecedor">{a.fornecedor?.nome ?? '—'}</Descriptions.Item>
          <Descriptions.Item label="Data de aquisição">{formatarData(a.data_aquisicao)}</Descriptions.Item>
          <Descriptions.Item label="Vida útil">{a.vida_util ? `${a.vida_util} meses` : 'Pela taxa da categoria'}</Descriptions.Item>
          <Descriptions.Item label="Quota fixa">{a.quota_fixa ? formatarKz(a.quota_fixa, true) : '—'}</Descriptions.Item>
          <Descriptions.Item label="Valor de aquisição"><ValorKz valor={a.valor_aquisicao} forte /></Descriptions.Item>
          <Descriptions.Item label="Valor residual"><ValorKz valor={a.valor_residual} /></Descriptions.Item>
          <Descriptions.Item label="Amortização acumulada"><ValorKz valor={a.amortizacao_acumulada} /></Descriptions.Item>
          <Descriptions.Item label="Valor líquido"><ValorKz valor={a.valor_liquido} forte /></Descriptions.Item>
          {a.amortizacao_acumulada_inicial && (
            <Descriptions.Item label="Acumulada inicial">{formatarKz(a.amortizacao_acumulada_inicial)} {a.acumulado_fim_ano ? `(até ${a.acumulado_fim_ano})` : ''}</Descriptions.Item>
          )}
        </Descriptions>
      </Card>
      <Card>
        <Tabs
          items={[
            {
              key: 'amort',
              label: `Amortizações (${a.amortizacoes.length})`,
              children: (
                <Table<AmortizacaoRegisto>
                  rowKey="id"
                  size="small"
                  dataSource={amortizacoes}
                  pagination={{ pageSize: 12 }}
                  columns={[
                    { title: 'Período', dataIndex: 'periodo_codigo', render: (p) => rotuloPeriodo(p) },
                    { title: 'Data', dataIndex: 'data', render: formatarData },
                    { title: 'Quota (Kz)', dataIndex: 'valor', align: 'right', render: (v) => <ValorKz valor={v} /> },
                    { title: 'Estado', dataIndex: 'contabilizado', render: (c) => <EtiquetaActivos valor={c ? 'INTEGRADO' : 'RASCUNHO'} /> },
                  ]}
                  summary={() => (
                    <Table.Summary.Row>
                      <Table.Summary.Cell index={0} colSpan={2}><Typography.Text strong>Total integrado</Typography.Text></Table.Summary.Cell>
                      <Table.Summary.Cell index={2} align="right"><ValorKz valor={somar(a.amortizacoes.filter((x) => x.contabilizado).map((x) => x.valor))} forte /></Table.Summary.Cell>
                      <Table.Summary.Cell index={3} />
                    </Table.Summary.Row>
                  )}
                />
              ),
            },
            {
              key: 'origem',
              label: 'Lançamento de origem',
              children: a.origem ? (
                <Descriptions bordered size="small" column={2}>
                  <Descriptions.Item label="Documento">{a.origem.numero_documento ?? '—'}</Descriptions.Item>
                  <Descriptions.Item label="N.º lançamento">{a.origem.numero_lan ?? '—'}</Descriptions.Item>
                  <Descriptions.Item label="Diário">{a.origem.diario?.codigo ?? '—'}</Descriptions.Item>
                  <Descriptions.Item label="Data">{formatarData(a.origem.data_documento)}</Descriptions.Item>
                  <Descriptions.Item label="Conta">{a.origem.codigo_conta}</Descriptions.Item>
                  <Descriptions.Item label="Valor"><ValorKz valor={a.origem.valor} /></Descriptions.Item>
                  <Descriptions.Item label="Descrição" span={2}>{a.origem.descricao ?? '—'}</Descriptions.Item>
                </Descriptions>
              ) : (
                <Alert type="info" showIcon message="Activo sem lançamento de compra associado" description="Pode ligá-lo a uma linha 11/12 no ecrã Aquisições pendentes." />
              ),
            },
            {
              key: 'transf',
              label: `Transferências (${a.transferencias.length})`,
              children: (
                <Table rowKey="id" size="small" dataSource={a.transferencias} pagination={false}
                  columns={[
                    { title: 'Data', dataIndex: 'data', render: formatarData },
                    { title: 'Origem', key: 'o', render: (_, r) => r.centro_custo_origem?.codigo ?? '—' },
                    { title: 'Destino', key: 'd', render: (_, r) => r.centro_custo_destino?.codigo ?? '—' },
                  ]} />
              ),
            },
            {
              key: 'manut',
              label: `Manutenções (${a.manutencoes.length})`,
              children: (
                <Table rowKey="id" size="small" dataSource={a.manutencoes} pagination={false}
                  columns={[
                    { title: 'Data', dataIndex: 'data', render: formatarData },
                    { title: 'Tipo', dataIndex: 'tipo', render: (v) => <EtiquetaActivos valor={v} /> },
                    { title: 'Descrição', dataIndex: 'descricao', ellipsis: true },
                    { title: 'Custo', dataIndex: 'custo', align: 'right', render: (v) => <ValorKz valor={v} /> },
                    { title: 'Estado', dataIndex: 'estado', render: (v) => <EtiquetaActivos valor={v} /> },
                    { title: 'Resolução', dataIndex: 'resolucao', ellipsis: true, render: (v) => v ?? '—' },
                  ]} />
              ),
            },
            {
              key: 'abates',
              label: `Abates (${a.abates.length})`,
              children: (
                <Table rowKey="id" size="small" dataSource={a.abates} pagination={false}
                  columns={[
                    { title: 'Data', dataIndex: 'data', render: formatarData },
                    { title: 'Tipo', dataIndex: 'tipo', render: (v) => <EtiquetaActivos valor={v} /> },
                    { title: 'Valor', dataIndex: 'valor', align: 'right', render: (v) => <ValorKz valor={v} /> },
                    { title: 'Descrição', dataIndex: 'descricao', ellipsis: true },
                  ]} />
              ),
            },
            {
              key: 'afect',
              label: `Afectações (${a.afetacoes.length})`,
              children: (
                <Table rowKey="id" size="small" dataSource={a.afetacoes} pagination={false}
                  columns={[
                    { title: 'Projecto', key: 'p', render: (_, r) => (r.projeto ? `${r.projeto.codigo ?? ''} — ${r.projeto.nome}` : r.projeto_id) },
                    { title: 'Início', dataIndex: 'data_inicio', render: formatarData },
                    { title: 'Fim', dataIndex: 'data_fim', render: formatarData },
                  ]} />
              ),
            },
          ]}
        />
      </Card>
      <ModalActivo aberto={editar} activo={a} aoFechar={() => setEditar(false)} />
      <ModalTransferir aberto={transferir} activoId={a.id} aoFechar={() => setTransferir(false)} />
      <ModalAfectacao aberto={afectar} activoId={a.id} aoFechar={() => setAfectar(false)} />
      <ModalAbate aberto={abater} activo={a} aoFechar={() => setAbater(false)} />
      <ModalManutencao aberto={manutencao} activoId={a.id} aoFechar={() => setManutencao(false)} />
      <Modal
        title="Integrar amortizações do activo"
        open={integrar}
        onCancel={() => setIntegrar(false)}
        okText="Integrar"
        cancelText="Cancelar"
        confirmLoading={integrarAccao.isPending}
        onOk={() => integrarAccao.mutate({ url: `/ativos/bens/${a.id}/amortizacoes/integrar`, dados: { ate: ate.format('MM-YYYY') } })}
      >
        <Typography.Paragraph>Calcula e integra na contabilidade as quotas deste activo em falta até ao período indicado (inclusive).</Typography.Paragraph>
        <DatePicker picker="month" format="MM/YYYY" value={ate} onChange={(d) => d && setAte(d)} allowClear={false} />
      </Modal>
    </>
  );
}
