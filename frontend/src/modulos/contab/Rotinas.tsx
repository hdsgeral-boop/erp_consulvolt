import { Alert, Button, Card, DatePicker, Empty, Form, Input, Modal, Select, Space, Table, Tabs, Tag, Typography, message } from 'antd';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import dayjs, { type Dayjs } from 'dayjs';
import { useMemo, useState } from 'react';
import { enviar, obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { useSessao } from '@/sessao/SessaoContexto';
import { notificarErro } from '@/utilitarios/erros';
import { formatarData, formatarKz } from '@/utilitarios/formatacao';
import { EtiquetaEstado, ValorKz } from './comum/Componentes';
import { somar } from '@/utilitarios/decimal';
import { lerLinhasActualizacao } from './comum/rotinas';
import { SeletorAux, SeletorConta, SeletorDiario } from './comum/Seletores';

interface Obra {
  id: number;
  codigo: string;
  nome: string;
  estado: string;
}

interface MovPar {
  id: number;
  data_documento: string;
  numero_documento: string | null;
  codigo_conta: string;
  terceiro_id: number | null;
  valor: string;
}

interface Par {
  debito: MovPar;
  credito: MovPar;
  diferenca: string;
  exacto: boolean;
}

interface SaldoTransferir {
  conta_origem: string;
  saldo: string;
  nota_demonstracao_id: number | null;
  natureza: 'DEVEDOR' | 'CREDOR' | 'SEM_SALDO';
}

interface RotinaHistorico {
  codigo: string;
  tipo: string;
  data: string;
  valor_total: string | null;
  estado: string;
}

/** Contabilidade › Rotinas contabilísticas (ecrã contab_rotinas, ADR-056). O Imposto de Selo tem ecrã próprio. */
export default function Rotinas() {
  const { pode } = useSessao();
  const exec = pode('contab_rotinas_exec');
  return (
    <>
      <CabecalhoPagina titulo="Rotinas contabilísticas" subtitulo="Capitalização, compensação, transferência de saldos, actualização em massa e histórico" />
      <Tabs
        items={[
          { key: 'cap', label: 'Capitalização de obras', children: <Capitalizacao exec={exec} /> },
          { key: 'comp', label: 'Compensação automática', children: <Compensacao exec={exec} /> },
          { key: 'trf', label: 'Transferência de saldos', children: <Transferencia exec={exec} /> },
          { key: 'act', label: 'Actualização em massa', children: <Actualizacao exec={exec} /> },
          { key: 'hist', label: 'Histórico', children: <Historico /> },
          { key: 'limp', label: 'Limpeza de reconciliações', children: <Limpeza /> },
        ]}
      />
    </>
  );
}

function useAccao(sucesso?: () => void) {
  const cliente = useQueryClient();
  return useMutation({
    mutationFn: ({ caminho, dados }: { caminho: string; dados: unknown }) => enviar<Record<string, unknown>>('post', `/contabilidade/rotinas/${caminho}`, dados),
    onSuccess: ({ mensagem }) => {
      message.success(mensagem);
      void cliente.invalidateQueries({ queryKey: ['contab'] });
      sucesso?.();
    },
    onError: (e) => notificarErro(e),
  });
}

function Capitalizacao({ exec }: { exec: boolean }) {
  const obras = useQuery({ queryKey: ['contab', 'rotinas', 'obras'], queryFn: () => obter<Obra[]>('/contabilidade/rotinas/capitalizacao/obras') });
  const [form] = Form.useForm<{ mes: Dayjs; projetos: number[]; conta_debito: string; conta_credito: string }>();
  const accao = useAccao(() => form.resetFields(['projetos']));
  return (
    <Card>
      <Typography.Paragraph type="secondary">
        Capitaliza os custos do mês das obras internas: debita uma conta da classe 1 e credita uma conta 65 (documento CAP-AAAAMM, no último dia do mês).
      </Typography.Paragraph>
      <Form
        form={form}
        layout="vertical"
        disabled={!exec}
        initialValues={{ mes: dayjs().subtract(1, 'month') }}
        onFinish={(v) => accao.mutate({ caminho: 'capitalizacao', dados: { ...v, mes: v.mes.format('YYYY-MM') } })}
        style={{ maxWidth: 720 }}
      >
        <Form.Item name="mes" label="Mês" rules={[{ required: true }]}>
          <DatePicker picker="month" format="MM/YYYY" />
        </Form.Item>
        <Form.Item name="projetos" label="Obras internas" rules={[{ required: true, message: 'Escolha pelo menos uma obra.' }]}>
          <Select mode="multiple" loading={obras.isLoading} options={(obras.data ?? []).map((o) => ({ value: o.id, label: `${o.codigo} — ${o.nome}` }))} />
        </Form.Item>
        <Space wrap>
          <Form.Item name="conta_debito" label="Conta a débito (classe 1)" rules={[{ required: true }]}>
            <SeletorConta prefixos={['1']} style={{ width: 300 }} />
          </Form.Item>
          <Form.Item name="conta_credito" label="Conta a crédito (65)" rules={[{ required: true }]}>
            <SeletorConta prefixos={['65']} style={{ width: 300 }} />
          </Form.Item>
        </Space>
        {exec && (
          <Form.Item>
            <Button type="primary" htmlType="submit" loading={accao.isPending}>
              Executar capitalização
            </Button>
          </Form.Item>
        )}
      </Form>
    </Card>
  );
}

function Compensacao({ exec }: { exec: boolean }) {
  const pares = useQuery({ queryKey: ['contab', 'rotinas', 'pares'], queryFn: () => obter<Par[]>('/contabilidade/rotinas/compensacao/pares'), enabled: false });
  const [seleccao, setSeleccao] = useState<string[]>([]);
  const accao = useAccao(() => {
    setSeleccao([]);
    void pares.refetch();
  });
  const chave = (p: Par) => `${p.debito.id}-${p.credito.id}`;
  return (
    <Card
      extra={
        <Space>
          <Button loading={pares.isFetching} onClick={() => void pares.refetch()}>
            {pares.data ? 'Actualizar pares' : 'Identificar pares'}
          </Button>
          {exec && (
            <Button
              type="primary"
              disabled={!seleccao.length}
              loading={accao.isPending}
              onClick={() =>
                accao.mutate({
                  caminho: 'compensacao',
                  dados: { pares: (pares.data ?? []).filter((p) => seleccao.includes(chave(p))).map((p) => ({ debito_id: p.debito.id, credito_id: p.credito.id })) },
                })
              }
            >
              Compensar {seleccao.length} par(es)
            </Button>
          )}
        </Space>
      }
    >
      <Typography.Paragraph type="secondary">
        Pares débito/crédito do mesmo terceiro, conta e documento. Pares com diferença são regularizados na conta 3772.
      </Typography.Paragraph>
      {!pares.data ? (
        <Empty description="Carregue em «Identificar pares»." />
      ) : (
        <Table<Par>
          rowKey={chave}
          size="small"
          dataSource={pares.data}
          pagination={{ pageSize: 50, showTotal: (n) => `${n} par(es)` }}
          scroll={{ x: 'max-content' }}
          rowSelection={exec ? { selectedRowKeys: seleccao, onChange: (k) => setSeleccao(k as string[]) } : undefined}
          columns={[
            { title: 'Conta', render: (_, p) => p.debito.codigo_conta },
            { title: 'Terceiro', render: (_, p) => (p.debito.terceiro_id ? `#${p.debito.terceiro_id}` : '—') },
            { title: 'Documento', render: (_, p) => p.debito.numero_documento },
            { title: 'Data débito', render: (_, p) => formatarData(p.debito.data_documento) },
            { title: 'Débito', align: 'right', render: (_, p) => <ValorKz valor={p.debito.valor} /> },
            { title: 'Data crédito', render: (_, p) => formatarData(p.credito.data_documento) },
            { title: 'Crédito', align: 'right', render: (_, p) => <ValorKz valor={p.credito.valor} /> },
            { title: 'Diferença', align: 'right', render: (_, p) => (p.exacto ? <Tag color="green">Exacto</Tag> : <ValorKz valor={p.diferenca} />) },
          ]}
        />
      )}
    </Card>
  );
}

interface LinhaTransferencia extends SaldoTransferir {
  conta_destino?: string;
  nota_destino_id?: number;
}

function Transferencia({ exec }: { exec: boolean }) {
  const [mes, setMes] = useState<Dayjs>(dayjs().subtract(1, 'month'));
  const [contas, setContas] = useState<string[]>([]);
  const [diario, setDiario] = useState<number>();
  const [linhas, setLinhas] = useState<LinhaTransferencia[]>([]);
  const consulta = useMutation({
    mutationFn: () => enviar<SaldoTransferir[]>('post', '/contabilidade/rotinas/transferencia/saldos', { mes: mes.format('YYYY-MM'), contas }),
    onSuccess: ({ dados }) => setLinhas(dados.map((d) => ({ ...d }))),
    onError: (e) => notificarErro(e, 'Não foi possível obter os saldos'),
  });
  const accao = useAccao(() => setLinhas([]));
  const prontas = linhas.filter((l) => l.conta_destino && l.natureza !== 'SEM_SALDO');
  const alterar = (i: number, campo: Partial<LinhaTransferencia>) => setLinhas((ls) => ls.map((l, j) => (j === i ? { ...l, ...campo } : l)));

  return (
    <Card>
      <Typography.Paragraph type="secondary">Transfere o saldo de fim de mês das contas de origem para as contas de destino, com lançamentos equilibrados.</Typography.Paragraph>
      <Space wrap style={{ marginBottom: 16 }}>
        <DatePicker picker="month" format="MM/YYYY" value={mes} onChange={(v) => v && setMes(v)} allowClear={false} />
        <Select mode="tags" placeholder="Contas de origem (escreva e Enter)" value={contas} onChange={setContas} style={{ minWidth: 320 }} tokenSeparators={[',', ';', ' ']} />
        <Button disabled={!contas.length} loading={consulta.isPending} onClick={() => consulta.mutate()}>
          Ver saldos
        </Button>
      </Space>
      {linhas.length > 0 && (
        <>
          <Table<LinhaTransferencia>
            rowKey="conta_origem"
            size="small"
            pagination={false}
            dataSource={linhas}
            columns={[
              { title: 'Conta de origem', dataIndex: 'conta_origem' },
              { title: 'Saldo', dataIndex: 'saldo', align: 'right', render: (v: string) => <ValorKz valor={v} /> },
              { title: 'Natureza', dataIndex: 'natureza', render: (v: string) => <Tag>{v.replace('_', ' ').toLowerCase()}</Tag> },
              { title: 'Conta de destino', render: (_, l, i) => <SeletorConta value={l.conta_destino} onChange={(v) => alterar(i, { conta_destino: v })} disabled={!exec || l.natureza === 'SEM_SALDO'} style={{ width: 260 }} /> },
              { title: 'Nota DEMO de destino', render: (_, l, i) => <SeletorAux tabela="notas-demonstracao" value={l.nota_destino_id} onChange={(v) => alterar(i, { nota_destino_id: v })} disabled={!exec} /> },
            ]}
          />
          {exec && (
            <Space style={{ marginTop: 16 }}>
              <SeletorDiario value={diario} onChange={setDiario} />
              <Button
                type="primary"
                disabled={!diario || !prontas.length}
                loading={accao.isPending}
                onClick={() =>
                  accao.mutate({
                    caminho: 'transferencia',
                    dados: { diario_id: diario, mes: mes.format('YYYY-MM'), linhas: prontas.map((l) => ({ conta_origem: l.conta_origem, conta_destino: l.conta_destino, nota_destino_id: l.nota_destino_id })) },
                  })
                }
              >
                Transferir {prontas.length} saldo(s) · {formatarKz(somar(prontas.map((l) => l.saldo)))} Kz
              </Button>
            </Space>
          )}
        </>
      )}
    </Card>
  );
}

function Actualizacao({ exec }: { exec: boolean }) {
  const [texto, setTexto] = useState('');
  const [resultado, setResultado] = useState<{ codigo: string; actualizadas: number; erros: { linha: number; lancamento_id: number | null; motivo: string }[] } | null>(null);
  const linhas = useMemo(() => lerLinhasActualizacao(texto), [texto]);
  const cliente = useQueryClient();
  const accao = useMutation({
    mutationFn: () => enviar<{ codigo: string; actualizadas: number; erros: { linha: number; lancamento_id: number | null; motivo: string }[] }>('post', '/contabilidade/rotinas/actualizacao-massa', { linhas }),
    onSuccess: ({ dados, mensagem }) => {
      message.success(mensagem);
      setResultado(dados);
      void cliente.invalidateQueries({ queryKey: ['contab'] });
    },
    onError: (e) => notificarErro(e),
  });
  return (
    <Card>
      <Typography.Paragraph type="secondary">
        Cole linhas «id da linha de lançamento; campo; novo valor» (de uma folha de cálculo). Campos: descricao, codigo_conta, terceiro_id, diario_id,
        nota_demonstracao_id, nota_fluxo_caixa_id. As linhas com erro são recusadas; as válidas ficam no histórico e podem ser anuladas.
      </Typography.Paragraph>
      <Input.TextArea rows={8} value={texto} onChange={(e) => setTexto(e.target.value)} placeholder={'91489;nota_demonstracao_id;272\n91490;descricao;Nova descrição'} disabled={!exec} />
      <Space style={{ marginTop: 12 }}>
        <Typography.Text>{linhas.length} linha(s) reconhecida(s)</Typography.Text>
        {exec && (
          <Button type="primary" disabled={!linhas.length} loading={accao.isPending} onClick={() => accao.mutate()}>
            Processar
          </Button>
        )}
      </Space>
      {resultado && (
        <>
          <Alert style={{ marginTop: 12 }} type={resultado.erros.length ? 'warning' : 'success'} showIcon message={`${resultado.actualizadas} actualizada(s) · ${resultado.erros.length} com erro · rotina ${resultado.codigo}`} />
          {resultado.erros.length > 0 && (
            <Table
              rowKey={(r) => `${r.linha}`}
              size="small"
              style={{ marginTop: 12 }}
              pagination={false}
              dataSource={resultado.erros}
              columns={[
                { title: 'Linha', dataIndex: 'linha' },
                { title: 'Lançamento', dataIndex: 'lancamento_id' },
                { title: 'Motivo', dataIndex: 'motivo' },
              ]}
            />
          )}
        </>
      )}
    </Card>
  );
}

function Historico() {
  const { pode } = useSessao();
  const historico = useQuery({ queryKey: ['contab', 'rotinas', 'historico'], queryFn: () => obter<RotinaHistorico[]>('/contabilidade/rotinas/historico') });
  const [anular, setAnular] = useState<string | null>(null);
  const [form] = Form.useForm<{ motivo: string }>();
  const accao = useAccao(() => {
    setAnular(null);
    form.resetFields();
  });
  return (
    <Card>
      <Table<RotinaHistorico>
        rowKey="codigo"
        size="small"
        loading={historico.isLoading}
        dataSource={historico.data}
        pagination={{ pageSize: 25 }}
        columns={[
          { title: 'Código', dataIndex: 'codigo', render: (v: string) => <strong>{v}</strong> },
          { title: 'Tipo', dataIndex: 'tipo', render: (v: string) => <Tag>{v}</Tag> },
          { title: 'Data', dataIndex: 'data', render: formatarData },
          { title: 'Valor', dataIndex: 'valor_total', align: 'right', render: (v: string | null) => (v ? <ValorKz valor={v} /> : '—') },
          { title: 'Estado', dataIndex: 'estado', render: (v: string) => <EtiquetaEstado estado={v} /> },
          {
            title: '',
            render: (_, r) => (pode('contab_rotinas_anular') && r.estado === 'EXECUTADA' ? <Button size="small" danger onClick={() => setAnular(r.codigo)}>Anular</Button> : null),
          },
        ]}
      />
      <Modal title={`Anular a rotina ${anular ?? ''}`} open={!!anular} onCancel={() => setAnular(null)} okText="Anular" okButtonProps={{ danger: true }} confirmLoading={accao.isPending} onOk={() => form.submit()}>
        <Form form={form} layout="vertical" onFinish={(v) => accao.mutate({ caminho: 'anular', dados: { codigo: anular, motivo: v.motivo } })}>
          <Typography.Paragraph type="secondary">Os lançamentos da rotina são estornados (fica o rasto no Diário).</Typography.Paragraph>
          <Form.Item name="motivo" label="Motivo" rules={[{ required: true, message: 'Indique o motivo.' }]}>
            <Input.TextArea rows={3} maxLength={500} />
          </Form.Item>
        </Form>
      </Modal>
    </Card>
  );
}

function Limpeza() {
  const consulta = useQuery({
    queryKey: ['contab', 'rotinas', 'limpeza'],
    queryFn: () => obter<{ linhas: Record<string, unknown>[]; total: number; execucao: string }>('/contabilidade/rotinas/limpeza-reconciliacoes'),
  });
  const d = consulta.data;
  return (
    <Card loading={consulta.isLoading}>
      {d && (
        <>
          <Alert type="info" showIcon style={{ marginBottom: 12 }} message={`${d.total} linha(s) de tesouraria com falsas reconciliações.`} description={d.execucao} />
          {d.linhas.length > 0 && (
            <Table
              rowKey={(_, i) => String(i)}
              size="small"
              dataSource={d.linhas}
              pagination={{ pageSize: 25 }}
              scroll={{ x: 'max-content' }}
              columns={Object.keys(d.linhas[0]).map((k) => ({ title: k.replace(/_/g, ' '), dataIndex: k, render: (v: unknown) => String(v ?? '—') }))}
            />
          )}
        </>
      )}
    </Card>
  );
}
