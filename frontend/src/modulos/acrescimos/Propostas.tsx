import { Alert, Button, Card, DatePicker, Flex, List, Modal, Select, Space, Table, Tabs, Tag, Typography } from 'antd';
import { CheckCircleOutlined, RollbackOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import dayjs from 'dayjs';
import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { TabelaApi } from '@/componentes/TabelaApi';
import { useSessao } from '@/sessao/SessaoContexto';
import { BotaoCsv, ValorKz } from '@/modulos/contab/comum/Componentes';
import { ModalMotivo, useAccao } from '@/modulos/compras/comum/accoes';
import { dataApi, formatarData, formatarKz } from '@/utilitarios/formatacao';
import { EtiquetaAD } from './comum/componentes';
import { mesApi, totaisSeleccao } from './comum/regras';
import type { LancamentoAD, LinhaProposta, LinhaReconciliacao, Proposta, ResultadoContabilizacao } from './comum/tipos';

/** Acréscimos e diferimentos › Proposta mensal (ecrã ad_propostas): contabilizar as linhas escolhidas, descontabilizar e reconciliar. */
export default function Propostas() {
  return (
    <>
      <CabecalhoPagina titulo="Proposta mensal" subtitulo="Lançamentos por contabilizar até ao mês (incluindo atrasados): um lançamento por linha, no diário do módulo" />
      <Tabs
        destroyInactiveTabPane
        items={[
          { key: 'proposta', label: 'Proposta', children: <PropostaMes /> },
          { key: 'lancamentos', label: 'Lançamentos', children: <Lancamentos /> },
          { key: 'reconciliacao', label: 'Reconciliação', children: <Reconciliacao /> },
        ]}
      />
    </>
  );
}

function PropostaMes() {
  const { pode } = useSessao();
  const [mes, setMes] = useState(dayjs());
  const [chaves, setChaves] = useState<string[]>([]);
  const [resultado, setResultado] = useState<ResultadoContabilizacao | null>(null);
  const m = mesApi(mes);
  const q = useQuery({ queryKey: ['acrescimos', 'proposta', m], queryFn: () => obter<Proposta>('/acrescimos/proposta', { mes: m }) });
  const accao = useAccao<ResultadoContabilizacao>({ invalidar: [['acrescimos']], aoSucesso: (r) => { setChaves([]); if (r.erros.length) setResultado(r); } });
  useEffect(() => setChaves([]), [m]);
  const linhas = q.data?.linhas ?? [];
  const sel = totaisSeleccao(linhas, chaves);
  const podeContab = pode('ad_contabilizar');

  return (
    <Card>
      <Flex gap={8} wrap justify="space-between" style={{ marginBottom: 16 }}>
        <Space wrap>
          <DatePicker picker="month" format="MM/YYYY" value={mes} allowClear={false} onChange={(d) => d && setMes(d)} />
          <Typography.Text type="secondary">{linhas.length} linha(s) · total {formatarKz(q.data?.total, true)}</Typography.Text>
        </Space>
        <Space wrap>
          <BotaoCsv nome={`proposta-ad-${m}`} linhas={linhas} colunas={[
            { titulo: 'Registo', valor: (l) => l.item.id }, { titulo: 'Descrição', valor: (l) => l.item.descricao }, { titulo: 'Tipo', valor: (l) => l.tipo_rotulo },
            { titulo: 'Período', valor: (l) => l.periodo }, { titulo: 'Data', valor: (l) => l.data }, { titulo: 'Débito', valor: (l) => l.debito }, { titulo: 'Crédito', valor: (l) => l.credito },
            { titulo: 'Valor', valor: (l) => l.valor, numerico: true }, { titulo: 'Atrasada', valor: (l) => l.atrasada },
          ]} />
          {podeContab && (
            <Button type="primary" icon={<CheckCircleOutlined />} disabled={!sel.n} loading={accao.isPending}
              onClick={() => Modal.confirm({
                title: `Contabilizar ${sel.n} lançamento(s)?`, content: `Total ${formatarKz(sel.total, true)}. Cada linha gera um lançamento no Diário.`, okText: 'Contabilizar', cancelText: 'Cancelar',
                onOk: () => accao.mutateAsync({ url: '/acrescimos/proposta/contabilizar', dados: { mes: m, chaves } }),
              })}>
              Contabilizar {sel.n ? `${sel.n} (${formatarKz(sel.total)})` : 'seleccionadas'}
            </Button>
          )}
        </Space>
      </Flex>
      {(q.data?.alertas.length ?? 0) > 0 && (
        <Alert type="warning" showIcon style={{ marginBottom: 12 }} message={`${q.data?.alertas.length} acréscimo(s) sem documento real depois da data limite`}
          description={<ul style={{ margin: 0, paddingLeft: 18 }}>{q.data?.alertas.map((a) => <li key={a.id}><Link to={`/m/acrescimos/ad_registos/${a.id}`}>#{a.id} {a.descricao}</Link> — {formatarKz(a.valor, true)} (limite {formatarData(a.data_limite)})</li>)}</ul>} />
      )}
      <Table<LinhaProposta>
        rowKey="chave"
        size="small"
        loading={q.isFetching}
        dataSource={linhas}
        pagination={false}
        scroll={{ x: 'max-content' }}
        rowSelection={podeContab ? { selectedRowKeys: chaves, onChange: (k) => setChaves(k as string[]) } : undefined}
        columns={[
          { title: 'Registo', key: 'i', render: (_, l) => <><strong>#{l.item.id}</strong> {l.item.descricao}</> },
          { title: 'Tipo', dataIndex: 'tipo', render: (v) => <EtiquetaAD valor={v} /> },
          { title: 'Período', dataIndex: 'periodo', render: (p, l) => <>{p}{l.atrasada && <Tag color="orange" style={{ marginLeft: 6 }}>Atrasada</Tag>}</> },
          { title: 'Data', dataIndex: 'data', render: formatarData },
          { title: 'Débito', dataIndex: 'debito' },
          { title: 'Crédito', dataIndex: 'credito' },
          { title: 'Valor (Kz)', dataIndex: 'valor', align: 'right', render: (v) => <ValorKz valor={v} forte /> },
        ]}
      />
      <Modal title="Resultado da contabilização" open={!!resultado} onCancel={() => setResultado(null)} footer={<Button onClick={() => setResultado(null)}>Fechar</Button>}>
        <Typography.Paragraph>{resultado?.ok.length} contabilizado(s), {resultado?.erros.length} com erro:</Typography.Paragraph>
        <List size="small" dataSource={resultado?.erros ?? []} renderItem={(e) => <List.Item><Typography.Text type="danger">{e.mensagem}</Typography.Text></List.Item>} />
      </Modal>
    </Card>
  );
}

function Lancamentos() {
  const { pode } = useSessao();
  const [periodo, setPeriodo] = useState<dayjs.Dayjs | null>(null);
  const [estado, setEstado] = useState<string>();
  const [descontab, setDescontab] = useState<LancamentoAD | null>(null);
  const accao = useAccao({ invalidar: [['acrescimos']], aoSucesso: () => setDescontab(null) });
  return (
    <Card>
      <Flex gap={8} wrap style={{ marginBottom: 16 }}>
        <DatePicker picker="month" format="MM/YYYY" placeholder="Período" value={periodo} onChange={setPeriodo} />
        <Select placeholder="Estado" allowClear value={estado} onChange={setEstado} style={{ width: 170 }} options={[{ value: 'CONTABILIZADO', label: 'Contabilizado' }, { value: 'ANULADO', label: 'Anulado (estornado)' }]} />
      </Flex>
      <TabelaApi<LancamentoAD>
        url="/acrescimos/lancamentos"
        chaveConsulta={['acrescimos', 'lancamentos']}
        filtros={{ periodo: periodo ? mesApi(periodo) : undefined, estado }}
        columns={[
          { title: 'Registo', dataIndex: 'item_acrescimo_diferimento_id', render: (id) => <Link to={`/m/acrescimos/ad_registos/${id}`}>#{id}</Link> },
          { title: 'Período', dataIndex: 'periodo' },
          { title: 'Tipo', dataIndex: 'tipo', render: (v) => <EtiquetaAD valor={v} /> },
          { title: 'Documento', dataIndex: 'numero_documento' },
          { title: 'N.º lanç.', dataIndex: 'numero_lan' },
          { title: 'Data', dataIndex: 'data_documento', render: formatarData },
          { title: 'Valor (Kz)', dataIndex: 'valor', align: 'right', render: (v) => <ValorKz valor={v} /> },
          { title: 'Estado', dataIndex: 'estado', render: (v) => <EtiquetaAD valor={v} /> },
          {
            title: '', key: 'acc', align: 'right',
            render: (_, l) => pode('ad_contabilizar') && l.estado === 'CONTABILIZADO' && <Button size="small" danger icon={<RollbackOutlined />} onClick={() => setDescontab(l)}>Descontabilizar</Button>,
          },
        ]}
      />
      <ModalMotivo aberto={!!descontab} titulo={`Descontabilizar ${descontab?.numero_documento ?? ''}`} textoOk="Descontabilizar" aviso="O lançamento é estornado (sem buracos na numeração) e a linha volta à proposta."
        carregando={accao.isPending} aoFechar={() => setDescontab(null)} aoConfirmar={(motivo) => descontab && accao.mutate({ url: `/acrescimos/lancamentos/${descontab.id}/descontabilizar`, dados: { motivo } })} />
    </Card>
  );
}

function Reconciliacao() {
  const [data, setData] = useState<dayjs.Dayjs | null>(null);
  const d = dataApi(data);
  const q = useQuery({ queryKey: ['acrescimos', 'reconciliacao', d], queryFn: () => obter<LinhaReconciliacao[]>('/acrescimos/reconciliacao', { data: d }) });
  const divergentes = (q.data ?? []).filter((l) => Number(l.diferenca) !== 0).length;
  return (
    <Card>
      <Flex gap={8} wrap style={{ marginBottom: 16 }} align="center">
        <DatePicker format="DD/MM/YYYY" placeholder="À data (hoje)" value={data} onChange={setData} />
        <Typography.Text type="secondary">Saldo das contas 37 segundo o módulo e segundo o Diário {data ? `em ${data.format('DD/MM/YYYY')}` : 'à data de hoje'}.</Typography.Text>
      </Flex>
      {q.data && <Alert style={{ marginBottom: 12 }} type={divergentes ? 'warning' : 'success'} showIcon message={divergentes ? `${divergentes} conta(s) com diferença` : 'Módulo e Diário conferem'} />}
      <Table<LinhaReconciliacao>
        rowKey="conta"
        size="small"
        loading={q.isFetching}
        dataSource={q.data}
        pagination={false}
        columns={[
          { title: 'Conta', dataIndex: 'conta' },
          { title: 'Módulo (Kz)', dataIndex: 'modulo', align: 'right', render: (v) => <ValorKz valor={v} /> },
          { title: 'Diário (Kz)', dataIndex: 'diario', align: 'right', render: (v) => <ValorKz valor={v} /> },
          { title: 'Diferença', dataIndex: 'diferenca', align: 'right', render: (v) => <ValorKz valor={v} forte discretoSeZero /> },
        ]}
      />
    </Card>
  );
}
