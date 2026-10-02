import { Button, DatePicker, Flex, Form, Input, Modal, Radio, Select, Space, Table, Tag, Typography } from 'antd';
import { useQuery } from '@tanstack/react-query';
import type { Dayjs } from 'dayjs';
import { useEffect, useState } from 'react';
import { obter } from '@/api/cliente';
import { useSessao } from '@/sessao/SessaoContexto';
import { notificarErro } from '@/utilitarios/erros';
import { dataApi, formatarDataHora, formatarKz } from '@/utilitarios/formatacao';
import { useAccao } from '@/componentes/Accoes';
import { colunasParaImpressao, type ColunaApi } from '@/componentes/TabelaApi';
import { BotoesExportar, prepararTexto, tabelaHtml } from '@/componentes/impressao';
import { BarraFiltros, larguraModal, scrollTabela, useEcraPequeno } from '@/componentes/responsivo';
import { EstadoPOS } from '../comum/estados';
import { SeletorTerminal, useFiltroTerminal } from '../comum/Filtros';
import type { Terminal } from '../comum/tipos';
import { useColaboradoresLav } from './dados';
import { DetalheOrdem } from './DetalheOrdem';
import type { ListaOrdens, OrdemResumo } from './tipos';

/** Filtros especiais do servidor (ServicoOrdensLavandaria::listar) e os contadores correspondentes. */
const FILTROS: { valor: string; rotulo: string; contador?: string }[] = [
  { valor: 'ACTIVAS', rotulo: 'Activas' },
  { valor: 'ORCAMENTO', rotulo: 'Orçamentos', contador: 'orcamentos' },
  { valor: 'SEM_RESPONSAVEL', rotulo: 'Sem responsável', contador: 'sem_responsavel' },
  { valor: 'EM_EXECUCAO', rotulo: 'Em execução', contador: 'em_execucao' },
  { valor: 'PRONTA', rotulo: 'Prontas', contador: 'prontas' },
  { valor: 'ATRASADAS', rotulo: 'Atrasadas', contador: 'atrasadas' },
  { valor: 'NAO_LEVANTADAS', rotulo: 'Não levantadas', contador: 'nao_levantadas' },
  { valor: 'ENTREGUE', rotulo: 'Entregues' },
  { valor: 'ANULADA', rotulo: 'Anuladas' },
  { valor: 'TODAS', rotulo: 'Todas' },
];

/** Ordens de serviço da lavandaria: contadores, filtros, alteração de estado em lote e detalhe da ordem. */
export function Ordens({ terminal }: { terminal: Terminal | undefined }) {
  const { pode } = useSessao();
  const [estado, setEstado] = useState('ACTIVAS');
  const [texto, setTexto] = useState('');
  const [terminalFiltro, setTerminalFiltro] = useState<number>();
  const [seleccao, setSeleccao] = useState<number[]>([]);
  const [aberta, setAberta] = useState<number | null>(null);
  const [responsavel, setResponsavel] = useState<string>();
  const [atribuir, setAtribuir] = useState(false);
  const colaboradores = useColaboradoresLav(true);
  const filtros = { estado, texto: texto.trim() || undefined, terminal_pos_id: terminalFiltro, responsavel };
  const consulta = useQuery({ queryKey: ['pos', 'lavandaria', 'ordens', filtros], queryFn: () => obter<ListaOrdens>('/pos/lavandaria/ordens', filtros) });
  useEffect(() => {
    if (consulta.error) notificarErro(consulta.error, 'Erro ao carregar as ordens');
  }, [consulta.error]);
  const lote = useAccao({ invalidar: [['pos']], aoSucesso: () => setSeleccao([]) });
  const contadores = consulta.data?.contadores ?? {};
  const pequeno = useEcraPequeno();
  const filtroTerminal = useFiltroTerminal(terminalFiltro);
  const colunas: ColunaApi<OrdemResumo>[] = [
    {
      title: 'Ordem',
      dataIndex: 'numero_encomenda',
      render: (v, o) => (
        <Button type="link" size="small" style={{ padding: 0 }} onClick={() => setAberta(o.id)}>
          {v}
        </Button>
      ),
      valorImpressao: (o) => o.numero_encomenda,
    },
    { title: 'Cliente', render: (_, o) => o.cliente?.nome ?? `#${o.cliente_id}` },
    { title: 'Recebida', dataIndex: 'recebido_em', render: (v) => formatarDataHora(v), responsive: ['md'] },
    { title: 'Prometida', dataIndex: 'data_prometida', render: (v, o) => <>{formatarDataHora(v)} {o.urgente && <Tag color="red">Urgente</Tag>}</> },
    { title: 'Estado', dataIndex: 'estado', render: (v) => <EstadoPOS estado={v} /> },
    { title: 'Responsável', dataIndex: 'nome_atribuido', render: (v) => v ?? '—', responsive: ['lg'] },
    { title: 'Situação', render: (_, o) => <Typography.Text type={(o.indicadores.atraso ?? 0) > 0 ? 'danger' : undefined}>{o.indicadores.situacao ?? '—'}</Typography.Text>, responsive: ['md'] },
    { title: 'Total', dataIndex: 'total', align: 'right', render: (v) => formatarKz(v), responsive: ['md'] },
    { title: 'Saldo', dataIndex: 'saldo', align: 'right', render: (v) => <Typography.Text strong={Number(v) > 0}>{formatarKz(v)}</Typography.Text> },
  ];
  const pedidoImpressao = async () => {
    await prepararTexto();
    const linhas = consulta.data?.ordens ?? [];
    return {
      titulo: 'Ordens de serviço da lavandaria',
      filtros: [`Filtro: ${FILTROS.find((f) => f.valor === estado)?.rotulo ?? estado}`, filtroTerminal, texto.trim() && `Pesquisa: ${texto.trim()}`, `${linhas.length} ordem(ns)`],
      conteudo: tabelaHtml({ colunas: colunasParaImpressao(colunas, linhas), linhas }),
    };
  };

  return (
    <>
      <Flex gap={6} wrap style={{ marginBottom: 12 }}>
        {FILTROS.map((f) => (
          <Tag.CheckableTag key={f.valor} checked={estado === f.valor} onChange={() => setEstado(f.valor)} style={{ padding: '4px 10px', fontSize: 13 }}>
            {f.rotulo}
            {f.contador && contadores[f.contador] !== undefined ? ` (${contadores[f.contador]})` : ''}
          </Tag.CheckableTag>
        ))}
        {contadores.recebidas_hoje !== undefined && <Typography.Text type="secondary">· {contadores.recebidas_hoje} recebida(s) hoje</Typography.Text>}
      </Flex>
      <BarraFiltros accoes={<BotoesExportar tamanho="small" desactivado={!consulta.data?.ordens.length} obterPedido={pedidoImpressao} />}>
        <Input.Search allowClear placeholder="N.º da ordem, etiqueta, cliente ou telefone" style={{ width: 320 }} onSearch={setTexto} />
        <SeletorTerminal tipo="LAVANDARIA" value={terminalFiltro} onChange={setTerminalFiltro} />
        <Select placeholder="Responsável" allowClear showSearch optionFilterProp="label" value={responsavel} onChange={setResponsavel} style={{ width: 220 }} loading={colaboradores.isLoading}
          options={[{ value: 'SEM', label: 'Sem responsável' }, ...(colaboradores.data ?? []).map((c) => ({ value: String(c.id), label: c.activo ? c.nome : `${c.nome} (inactivo)` }))]} />
        {pode('lav_ordens') && seleccao.length > 0 && (
          <Space wrap>
            <Button loading={lote.isPending} onClick={() => lote.mutate({ url: '/pos/lavandaria/ordens/estado', dados: { ids: seleccao, estado: 'EM_EXECUCAO' } })}>
              Iniciar execução ({seleccao.length})
            </Button>
            <Button loading={lote.isPending} onClick={() => lote.mutate({ url: '/pos/lavandaria/ordens/estado', dados: { ids: seleccao, estado: 'PRONTA' } })}>
              Marcar prontas ({seleccao.length})
            </Button>
            <Button onClick={() => setAtribuir(true)}>Atribuir responsável ({seleccao.length})</Button>
          </Space>
        )}
      </BarraFiltros>
      <Table<OrdemResumo>
        rowKey="id"
        size={pequeno ? 'small' : 'middle'}
        loading={consulta.isFetching}
        dataSource={consulta.data?.ordens}
        scroll={scrollTabela()}
        pagination={{ defaultPageSize: 25, showSizeChanger: true, showTotal: (t) => `${t} ordem(ns)` }}
        rowSelection={pode('lav_ordens') ? { selectedRowKeys: seleccao, onChange: (k) => setSeleccao(k as number[]) } : undefined}
        onRow={(o) => ({ onDoubleClick: () => setAberta(o.id) })}
        columns={colunas}
      />
      <DetalheOrdem id={aberta} terminal={terminal} aoFechar={() => setAberta(null)} />
      <ModalAtribuir ids={atribuir ? seleccao : []} aoFechar={() => setAtribuir(false)} aoConcluir={() => { setAtribuir(false); setSeleccao([]); }} />
    </>
  );
}

/** Atribuir (ou retirar) o responsável de várias ordens (POST /pos/lavandaria/ordens/atribuir, lav_ordens). */
function ModalAtribuir({ ids, aoFechar, aoConcluir }: { ids: number[]; aoFechar: () => void; aoConcluir: () => void }) {
  const colaboradores = useColaboradoresLav();
  const [form] = Form.useForm<{ modo: 'ATRIBUIR' | 'RETIRAR'; colaborador_id?: number; data?: Dayjs | null; aplicar: 'PENDENTES' | 'NENHUM'; nota?: string }>();
  const modo = Form.useWatch('modo', form);
  const accao = useAccao({ invalidar: [['pos']], aoSucesso: aoConcluir });
  useEffect(() => { if (ids.length) form.setFieldsValue({ modo: 'ATRIBUIR', aplicar: 'PENDENTES', colaborador_id: undefined, data: null, nota: undefined }); }, [ids.length, form]);
  return (
    <Modal title={`Responsável de ${ids.length} ordem(ns)`} open={ids.length > 0} onCancel={aoFechar} okText="Gravar" cancelText="Cancelar" confirmLoading={accao.isPending} onOk={() => form.submit()} width={larguraModal(520)} destroyOnHidden>
      <Form form={form} layout="vertical" onFinish={(v) => accao.mutate({ url: '/pos/lavandaria/ordens/atribuir', dados: {
        ids, retirar: v.modo === 'RETIRAR', colaborador_id: v.modo === 'RETIRAR' ? null : v.colaborador_id, data: dataApi(v.data ?? null) ?? null, aplicar: v.aplicar, nota: v.nota?.trim() || null,
      } })}>
        <Form.Item name="modo"><Radio.Group options={[{ value: 'ATRIBUIR', label: 'Atribuir' }, { value: 'RETIRAR', label: 'Retirar o responsável' }]} /></Form.Item>
        {modo !== 'RETIRAR' && (
          <>
            <Form.Item name="colaborador_id" label="Colaborador" rules={[{ required: true, message: 'Escolha o colaborador.' }]}>
              <Select showSearch optionFilterProp="label" loading={colaboradores.isLoading} options={(colaboradores.data ?? []).map((c) => ({ value: c.id, label: c.nome }))} />
            </Form.Item>
            <Form.Item name="data" label="Data da atribuição" extra="Vazio = hoje; não pode ser futura."><DatePicker format="DD/MM/YYYY" /></Form.Item>
            <Form.Item name="aplicar" label="Linhas da ordem">
              <Radio.Group options={[{ value: 'PENDENTES', label: 'Atribuir também as linhas pendentes' }, { value: 'NENHUM', label: 'Só a ordem' }]} />
            </Form.Item>
          </>
        )}
        <Form.Item name="nota" label="Nota"><Input maxLength={500} /></Form.Item>
      </Form>
    </Modal>
  );
}
