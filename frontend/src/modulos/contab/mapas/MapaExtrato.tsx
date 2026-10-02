import { Alert, Button, Card, DatePicker, Form, Modal, Select, Space, Statistic, Table, Tag, Typography, message } from 'antd';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import dayjs, { type Dayjs } from 'dayjs';
import { useState } from 'react';
import { enviar, obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { useSessao } from '@/sessao/SessaoContexto';
import { notificarErro } from '@/utilitarios/erros';
import { dataApi, formatarData, formatarKz } from '@/utilitarios/formatacao';
import type { Extrato, MovimentoExtrato } from '../api';
import { BotoesExportar, pares } from '@/componentes/impressao';
import { larguraModal, scrollTabela } from '@/componentes/responsivo';
import type { ColunaApi } from '@/componentes/TabelaApi';
import { BotaoCsv, IndicadorEquilibrio, ValorKz } from '../comum/Componentes';
import { filtrosDosParametros, periodoDosParametros, tabelaDeColunas } from '../comum/impressao';
import { equilibrio } from '@/utilitarios/decimal';
import { FiltrosMapa } from '../comum/FiltrosMapa';
import { SeletorConta, SeletorDiario } from '../comum/Seletores';
import { useAbrirLancamento, useMapa } from '../comum/useMapa';

interface Compensacao {
  reconciliacao_codigo: string;
  data: string | null;
  estado: string | null;
  valor_total: string | null;
  linhas: { id: number; data_documento: string; codigo_conta: string; numero_lan: string; numero_documento: string | null; descricao: string | null; tipo_dc: 'D' | 'C'; valor: string }[];
}

/** Mapas › Extracto de conta corrente (ecrã contab_mapa_extrato), com compensação de movimentos e regularização de diferenças. */
export default function MapaExtrato() {
  const { pode } = useSessao();
  const cliente = useQueryClient();
  const abrir = useAbrirLancamento();
  const mapa = useMapa<Extrato>('extrato', '/contabilidade/relatorios/extrato');
  const [seleccao, setSeleccao] = useState<number[]>([]);
  const [regularizar, setRegularizar] = useState(false);
  const [codigo, setCodigo] = useState<string | null>(null);
  const [formReg] = Form.useForm<{ codigo_conta: string; data: Dayjs; diario_id?: number }>();
  const d = mapa.data;
  const podeCompensar = pode('contab_compensar');

  const compensacao = useQuery({
    queryKey: ['contab', 'compensacao', codigo],
    queryFn: () => obter<Compensacao>(`/contabilidade/compensacoes/${codigo}`),
    enabled: !!codigo,
  });

  const aposMutacao = (mensagem: string) => {
    message.success(mensagem);
    setSeleccao([]);
    setRegularizar(false);
    setCodigo(null);
    void cliente.invalidateQueries({ queryKey: ['contab'] });
  };
  const compensar = useMutation({
    mutationFn: () => enviar('post', '/contabilidade/compensacoes', { linhas: seleccao }),
    onSuccess: ({ mensagem }) => aposMutacao(mensagem),
    onError: (e) => notificarErro(e, 'Não foi possível compensar'),
  });
  const regularizacao = useMutation({
    mutationFn: (v: { codigo_conta: string; data: Dayjs; diario_id?: number }) =>
      enviar('post', '/contabilidade/compensacoes/regularizar', { linhas: seleccao, codigo_conta: v.codigo_conta, data: dataApi(v.data), diario_id: v.diario_id }),
    onSuccess: ({ mensagem }) => aposMutacao(mensagem),
    onError: (e) => notificarErro(e, 'Não foi possível regularizar'),
  });
  const reverter = useMutation({
    mutationFn: (c: string) => enviar('delete', `/contabilidade/compensacoes/${c}`),
    onSuccess: ({ mensagem }) => aposMutacao(mensagem),
    onError: (e) => notificarErro(e, 'Não foi possível reverter a compensação'),
  });

  const seleccionadas = (d?.movimentos ?? []).filter((m) => seleccao.includes(m.id));
  const eq = equilibrio(seleccionadas);

  const colunas: ColunaApi<MovimentoExtrato>[] = [
    { title: 'Data', dataIndex: 'data_documento', render: formatarData, width: 100 },
    { title: 'Conta', dataIndex: 'codigo_conta', render: (v: string, r) => <span title={r.descricao_conta ?? ''}>{v}</span> },
    { title: 'Terceiro', dataIndex: 'terceiro', ellipsis: true, width: 180, responsive: ['md'], render: (v: string | null) => v?.trim() ?? '—' },
    { title: 'Diário', dataIndex: 'diario', responsive: ['lg'] },
    { title: 'N.º lançamento', dataIndex: 'numero_lan', render: (v: string, r) => (abrir ? <Typography.Link onClick={() => abrir(r.id)}>{v}</Typography.Link> : v), valorImpressao: (r) => r.numero_lan },
    { title: 'Documento', dataIndex: 'numero_documento', responsive: ['md'] },
    { title: 'Descrição', dataIndex: 'descricao', ellipsis: true, width: 260 },
    { title: 'Contrapartidas', dataIndex: 'contrapartidas', responsive: ['lg'] },
    { title: 'Débito', align: 'right', render: (_, r) => (r.tipo_dc === 'D' ? <ValorKz valor={r.valor} /> : null), totalImpressao: () => formatarKz(d?.debito) },
    { title: 'Crédito', align: 'right', render: (_, r) => (r.tipo_dc === 'C' ? <ValorKz valor={r.valor} /> : null), totalImpressao: () => formatarKz(d?.credito) },
    { title: 'Saldo', dataIndex: 'saldo', align: 'right', render: (v: string) => <ValorKz valor={v} /> },
    {
      title: 'Compensação',
      dataIndex: 'reconciliacao_codigo',
      responsive: ['md'],
      valorImpressao: (r) => r.reconciliacao_codigo ?? '',
      render: (v: string | null) => (v ? <Tag color="blue" style={{ cursor: 'pointer' }} onClick={() => setCodigo(v)}>{v}</Tag> : null),
    },
  ];

  return (
    <>
      <CabecalhoPagina titulo="Extracto de conta corrente" subtitulo="Movimentos por conta e/ou terceiro, com compensação" />
      <Card style={{ marginBottom: 16 }}>
        <FiltrosMapa
          modo="periodo"
          periodoObrigatorio={false}
          aCalcular={mapa.isFetching}
          aoCalcular={(p) => {
            if (!p.filtro_contas && !p.terceiro_id) return void message.warning('Indique pelo menos uma conta ou um terceiro.');
            if (!p.data_inicio && !p.data_fim) return void message.warning('Indique o período.');
            setSeleccao([]);
            mapa.calcular(p);
          }}
          avancados={{ contas: true, terceiro: true, diario: true, centro: true, unidade: true, classe9: true }}
          extra={
            <Form.Item name="tipo" label="Movimentos" initialValue="todos" style={{ marginBottom: 8 }}>
              <Select style={{ width: 160 }} options={[{ value: 'todos', label: 'Todos' }, { value: 'aberto', label: 'Em aberto' }, { value: 'compensado', label: 'Compensados' }]} />
            </Form.Item>
          }
        />
      </Card>
      {d && (
        <Card>
          <Space size={32} wrap style={{ marginBottom: 12 }}>
            <Statistic title="Saldo inicial" value={formatarKz(d.saldo_inicial)} />
            <Statistic title="Débitos" value={formatarKz(d.debito)} />
            <Statistic title="Créditos" value={formatarKz(d.credito)} />
            <Statistic title="Saldo final" value={formatarKz(d.saldo_final)} />
            <BotoesExportar
              obterPedido={async () => ({
                titulo: 'Extracto de conta corrente',
                periodo: periodoDosParametros(mapa.parametros),
                filtros: [...filtrosDosParametros(mapa.parametros), mapa.parametros?.tipo && mapa.parametros.tipo !== 'todos' ? `Movimentos: ${mapa.parametros.tipo === 'aberto' ? 'em aberto' : 'compensados'}` : null],
                conteudo:
                  pares([
                    ['Saldo inicial', formatarKz(d.saldo_inicial)],
                    ['Débitos', formatarKz(d.debito)],
                    ['Créditos', formatarKz(d.credito)],
                    ['Saldo final', formatarKz(d.saldo_final)],
                  ], 4) + (await tabelaDeColunas(colunas, d.movimentos)),
              })}
            />
            <BotaoCsv<MovimentoExtrato>
              nome="extracto_conta_corrente"
              linhas={d.movimentos}
              colunas={[
                { titulo: 'Data', valor: (l) => l.data_documento },
                { titulo: 'Conta', valor: (l) => l.codigo_conta },
                { titulo: 'NIF', valor: (l) => l.nif_terceiro },
                { titulo: 'Terceiro', valor: (l) => l.terceiro?.trim() },
                { titulo: 'Diário', valor: (l) => l.diario },
                { titulo: 'N.º lançamento', valor: (l) => l.numero_lan },
                { titulo: 'Documento', valor: (l) => l.numero_documento },
                { titulo: 'Descrição', valor: (l) => l.descricao },
                { titulo: 'Débito', valor: (l) => (l.tipo_dc === 'D' ? l.valor : ''), numerico: true },
                { titulo: 'Crédito', valor: (l) => (l.tipo_dc === 'C' ? l.valor : ''), numerico: true },
                { titulo: 'Saldo', valor: (l) => l.saldo, numerico: true },
                { titulo: 'Compensação', valor: (l) => l.reconciliacao_codigo },
              ]}
            />
          </Space>
          {podeCompensar && seleccao.length > 0 && (
            <Alert
              style={{ marginBottom: 12 }}
              type={eq.equilibrado ? 'success' : 'warning'}
              message={
                <Space direction="vertical" style={{ width: '100%' }}>
                  <IndicadorEquilibrio linhas={seleccionadas} />
                  <Space wrap>
                    <Button type="primary" disabled={seleccao.length < 2 || !eq.equilibrado} loading={compensar.isPending} onClick={() => compensar.mutate()}>
                      Compensar {seleccao.length} movimento(s)
                    </Button>
                    <Button disabled={eq.equilibrado} onClick={() => { formReg.setFieldsValue({ data: dayjs() }); setRegularizar(true); }}>
                      Regularizar a diferença e compensar
                    </Button>
                    <Button type="link" onClick={() => setSeleccao([])}>Limpar selecção</Button>
                  </Space>
                </Space>
              }
            />
          )}
          <Table<MovimentoExtrato>
            rowKey="id"
            size="small"
            columns={colunas}
            dataSource={d.movimentos}
            pagination={{ pageSize: 100, showTotal: (n) => `${n} movimento(s)` }}
            scroll={scrollTabela()}
            rowSelection={
              podeCompensar
                ? {
                    selectedRowKeys: seleccao,
                    onChange: (k) => setSeleccao(k as number[]),
                    getCheckboxProps: (r) => ({ disabled: !!r.reconciliacao_codigo || !!r.estornado_por_id || !!r.estorno_de_id }),
                  }
                : undefined
            }
          />
        </Card>
      )}

      <Modal
        title={`Regularizar diferença de ${formatarKz(eq.diferenca)} Kz`}
        open={regularizar}
        onCancel={() => setRegularizar(false)}
        okText="Lançar e compensar"
        confirmLoading={regularizacao.isPending}
        onOk={() => formReg.submit()}
      >
        <Form form={formReg} layout="vertical" onFinish={(v) => regularizacao.mutate(v)}>
          <Typography.Paragraph type="secondary">É gravado um lançamento pela diferença contra a conta indicada e os movimentos ficam compensados.</Typography.Paragraph>
          <Form.Item name="codigo_conta" label="Conta de contrapartida" rules={[{ required: true, message: 'Indique a conta.' }]}>
            <SeletorConta style={{ width: '100%' }} />
          </Form.Item>
          <Form.Item name="data" label="Data do lançamento" rules={[{ required: true }]}>
            <DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} />
          </Form.Item>
          <Form.Item name="diario_id" label="Diário (opcional)">
            <SeletorDiario allowClear style={{ width: '100%' }} />
          </Form.Item>
        </Form>
      </Modal>

      <Modal
        title={`Compensação ${codigo ?? ''}`}
        open={!!codigo}
        onCancel={() => setCodigo(null)}
        width={larguraModal(820)}
        footer={
          <Space wrap>
            <Button onClick={() => setCodigo(null)}>Fechar</Button>
            {pode('contab_reconc_rev') && codigo && (
              <Button
                danger
                loading={reverter.isPending}
                onClick={() => Modal.confirm({ title: `Reverter a compensação ${codigo}?`, okText: 'Reverter', okButtonProps: { danger: true }, cancelText: 'Cancelar', onOk: () => reverter.mutateAsync(codigo) })}
              >
                Reverter compensação
              </Button>
            )}
          </Space>
        }
      >
        {compensacao.data && (
          <>
            <Typography.Paragraph>
              {compensacao.data.data ? `Data: ${formatarData(compensacao.data.data)} · ` : ''}
              {compensacao.data.valor_total ? `Valor: ${formatarKz(compensacao.data.valor_total, true)}` : ''}
            </Typography.Paragraph>
            <Table
              rowKey="id"
              size="small"
              scroll={scrollTabela()}
              pagination={false}
              dataSource={compensacao.data.linhas}
              columns={[
                { title: 'Data', dataIndex: 'data_documento', render: formatarData },
                { title: 'Conta', dataIndex: 'codigo_conta' },
                { title: 'N.º lançamento', dataIndex: 'numero_lan' },
                { title: 'Documento', dataIndex: 'numero_documento' },
                { title: 'Descrição', dataIndex: 'descricao' },
                { title: 'D/C', dataIndex: 'tipo_dc' },
                { title: 'Valor', dataIndex: 'valor', align: 'right', render: (v: string) => <ValorKz valor={v} /> },
              ]}
            />
          </>
        )}
      </Modal>
    </>
  );
}
