import {
  Alert, Button, Card, Checkbox, Descriptions, Dropdown, Flex, Form, InputNumber, Modal, Popconfirm, Progress, Select, Skeleton, Space, Table, Tabs, Tag, Typography, message,
} from 'antd';
import { ArrowLeftOutlined, DeleteOutlined, DownOutlined, EditOutlined, ImportOutlined, LockOutlined, PlusOutlined, UsergroupAddOutlined } from '@ant-design/icons';
import type { ColumnsType } from 'antd/es/table';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { Route, Routes, useNavigate, useParams } from 'react-router-dom';
import { enviar, obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { useSessao } from '@/sessao/SessaoContexto';
import { formatarKz, formatarNumero } from '@/utilitarios/formatacao';
import type { DetalhePeriodo, Lancamento } from './api';
import { EstadoTag, SeletorColaborador } from './comum/componentes';
import { useAccaoRh, useAvisarErro, useColaboradores, useInfotipos } from './comum/consultas';
import { ListaPeriodos } from './comum/ListaPeriodos';
import { ResumoTotais, TabelaResultados } from './comum/TabelaResultados';
import { accoesPeriodo } from './comum/regras';

/** RH › Calcular (ecrã calcular): abrir o período salarial, lançar rubricas, importar e encerrar o cálculo. */
export default function Calcular() {
  return (
    <Routes>
      <Route index element={<ListaPeriodos titulo="Calcular" subtitulo="Períodos de processamento salarial: lançamentos e cálculo" permitirAbrir />} />
      <Route path=":id" element={<PeriodoCalculo />} />
    </Routes>
  );
}

interface ValoresLancamento {
  colaborador_id: number;
  infotipo_salarial_id: number;
  valor?: number | null;
  dias_trabalhados?: number | null;
  horas?: number | null;
}

function PeriodoCalculo() {
  const { id } = useParams();
  const navegar = useNavigate();
  const { pode } = useSessao();
  const cliente = useQueryClient();
  const colaboradores = useColaboradores();
  const infotipos = useInfotipos();
  const periodo = useQuery({ queryKey: ['rh', 'salarios', 'periodo', id], queryFn: () => obter<DetalhePeriodo>(`/rh/salarios/periodos/${id}`) });
  const lancamentos = useQuery({ queryKey: ['rh', 'salarios', 'lancamentos', id], queryFn: () => obter<Lancamento[]>(`/rh/salarios/periodos/${id}/lancamentos`) });
  useAvisarErro(periodo.error, 'Erro ao carregar o período');
  const [filtroColab, setFiltroColab] = useState<number>();
  const [lancar, setLancar] = useState<'novo' | Lancamento | null>(null);
  const [lote, setLote] = useState(false);
  const [progressoLote, setProgressoLote] = useState<number | null>(null);
  const [efectividade, setEfectividade] = useState(false);
  const [produtividade, setProdutividade] = useState(false);
  const [resultadoImport, setResultadoImport] = useState<{ titulo: string; linhas: string[] } | null>(null);
  const [formL] = Form.useForm<ValoresLancamento>();
  const [formLote] = Form.useForm<{ infotipo_salarial_id: number; valor: number; dias_trabalhados?: number | null; colaboradores: number[] }>();
  const [formEf] = Form.useForm<{ infotipo_extra_id: number; infotipo_falta_id: number }>();
  const [substituir, setSubstituir] = useState(true);
  const rubricaL = Form.useWatch('infotipo_salarial_id', formL);

  const accao = useAccaoRh<Record<string, unknown>>((dados, pedido) => {
    setLancar(null);
    setEfectividade(false);
    setProdutividade(false);
    if (pedido.url.endsWith('importar-contratos') && dados) {
      const ign = (dados.ignorados as string[] | undefined) ?? [];
      setResultadoImport({ titulo: `Importação dos contratos: ${dados.criados ?? 0} lançamento(s) criado(s), ${dados.ja_existentes ?? 0} já existente(s).`, linhas: ign });
    }
  });

  if (periodo.isLoading) return <Skeleton active />;
  const p = periodo.data;
  if (!p) return <Alert type="error" message="Período não encontrado." />;
  const ac = accoesPeriodo(p, pode);
  const porHoras = (idRubrica?: number) => {
    const i = idRubrica ? infotipos.mapa.get(idRubrica) : undefined;
    return i?.calculo_horas === 'EXTRA' || i?.calculo_horas === 'FALTA';
  };

  const abrirLancamento = (l: Lancamento | 'novo') => {
    formL.resetFields();
    if (l === 'novo') formL.setFieldsValue({ colaborador_id: filtroColab });
    else formL.setFieldsValue({ colaborador_id: l.colaborador_id, infotipo_salarial_id: l.infotipo_salarial_id, valor: Number(l.valor), dias_trabalhados: l.dias_trabalhados ? Number(l.dias_trabalhados) : null, horas: l.horas ? Number(l.horas) : null });
    setLancar(l);
  };

  /** Lote: um pedido por colaborador (o servidor grava ou substitui o lançamento colaborador × rubrica). */
  const gravarLote = async (v: { infotipo_salarial_id: number; valor: number; dias_trabalhados?: number | null; colaboradores: number[] }) => {
    let ok = 0;
    const falhas: string[] = [];
    setProgressoLote(0);
    for (const [i, c] of v.colaboradores.entries()) {
      try {
        await enviar('post', `/rh/salarios/periodos/${id}/lancamentos`, { colaborador_id: c, infotipo_salarial_id: v.infotipo_salarial_id, valor: v.valor, dias_trabalhados: v.dias_trabalhados ?? null });
        ok += 1;
      } catch (e) {
        falhas.push(`${colaboradores.nome(c)}: ${e instanceof Error ? e.message : String(e)}`);
      }
      setProgressoLote(Math.round(((i + 1) / v.colaboradores.length) * 100));
    }
    setProgressoLote(null);
    setLote(false);
    void cliente.invalidateQueries({ queryKey: ['rh'] });
    if (falhas.length) setResultadoImport({ titulo: `Lançamento em lote: ${ok} gravado(s), ${falhas.length} com erro.`, linhas: falhas });
    else message.success(`${ok} lançamento(s) gravado(s).`);
  };

  const linhas = (lancamentos.data ?? [])
    .filter((l) => !filtroColab || l.colaborador_id === filtroColab)
    .sort((a, b) => colaboradores.nome(a.colaborador_id).localeCompare(colaboradores.nome(b.colaborador_id), 'pt') || a.id - b.id);

  const colunas: ColumnsType<Lancamento> = [
    { title: 'Colaborador', dataIndex: 'colaborador_id', render: (v: number) => colaboradores.nome(v) },
    { title: 'Rubrica', dataIndex: 'infotipo_salarial_id', render: (v: number) => infotipos.nome(v) },
    { title: 'Tipo', render: (_, l) => { const t = infotipos.mapa.get(l.infotipo_salarial_id)?.tipo; return t ? <Tag color={t === 'VENCIMENTO' ? 'green' : t === 'DESCONTO' ? 'red' : 'default'}>{t}</Tag> : '—'; } },
    { title: 'Valor', dataIndex: 'valor', align: 'right', render: (v: string) => formatarKz(v) },
    { title: 'Dias trab.', dataIndex: 'dias_trabalhados', align: 'right', render: (v: string | null) => (v ? formatarNumero(v) : '—') },
    { title: 'Horas', dataIndex: 'horas', align: 'right', render: (v: string | null) => (v ? formatarNumero(v) : '—') },
    { title: 'Origem', dataIndex: 'origem', render: (o: string | null, l) => (l.bonificacao_avaliacao_id ? <Tag color="purple">Bonificação</Tag> : o ? <Tag>{o}</Tag> : 'Manual') },
    {
      title: '',
      key: 'accoes',
      align: 'right',
      render: (_, l) => (
        <Space size={4}>
          {ac.lancar && <Button size="small" type="text" icon={<EditOutlined />} aria-label="Editar" onClick={() => abrirLancamento(l)} />}
          {ac.removerLancamento && !l.bonificacao_avaliacao_id && (
            <Popconfirm title="Remover o lançamento?" okText="Remover" okButtonProps={{ danger: true }} cancelText="Cancelar"
              onConfirm={() => accao.mutateAsync({ metodo: 'delete', url: `/rh/salarios/periodos/${id}/lancamentos/${l.id}` })}>
              <Button size="small" type="text" danger icon={<DeleteOutlined />} aria-label="Remover" />
            </Popconfirm>
          )}
        </Space>
      ),
    },
  ];

  const rubricasHoras = (tipo: 'EXTRA' | 'FALTA') => infotipos.lista.filter((i) => i.calculo_horas === tipo).map((i) => ({ value: i.id, label: i.nome }));

  return (
    <>
      <CabecalhoPagina
        titulo={`Processamento ${p.mes_ano}`}
        subtitulo={<Space><EstadoTag estado={p.estado} />{p.contabilizado && <Tag color="green">Contabilizado</Tag>}{p.fotografia ? 'Resultados fotografados' : 'Cálculo ao vivo'}</Space>}
        accoes={
          <>
            <Button icon={<ArrowLeftOutlined />} onClick={() => navegar('..')}>Voltar</Button>
            {ac.importar && (
              <Dropdown menu={{
                items: [
                  { key: 'contratos', label: 'Importar dos contratos' },
                  { key: 'efectividade', label: 'Importar efectividade (horas extra e faltas)' },
                  { key: 'produtividade', label: 'Importar produtividade' },
                ],
                onClick: ({ key }) => {
                  if (key === 'contratos') Modal.confirm({ title: 'Importar lançamentos dos contratos?', content: 'Cria os lançamentos em falta a partir do contrato activo no mês (colaboradores activos, em Kz). Os existentes mantêm-se.', okText: 'Importar', cancelText: 'Cancelar', onOk: () => accao.mutateAsync({ metodo: 'post', url: `/rh/salarios/periodos/${id}/importar-contratos` }) });
                  if (key === 'efectividade') { formEf.resetFields(); setEfectividade(true); }
                  if (key === 'produtividade') { setSubstituir(true); setProdutividade(true); }
                },
              }}>
                <Button icon={<ImportOutlined />}>Importar <DownOutlined /></Button>
              </Dropdown>
            )}
            {ac.lancar && pode('calcular_bulk') && <Button icon={<UsergroupAddOutlined />} onClick={() => { formLote.resetFields(); setLote(true); }}>Lançar em lote</Button>}
            {ac.lancar && <Button icon={<PlusOutlined />} onClick={() => abrirLancamento('novo')}>Lançamento</Button>}
            {ac.encerrar && (
              <Button type="primary" icon={<LockOutlined />} loading={accao.isPending} onClick={() => Modal.confirm({
                title: `Encerrar o cálculo de ${p.mes_ano}?`,
                content: 'Os resultados ficam fotografados (imutáveis) e o período passa a Fechado, para validação no ecrã Processamentos.',
                okText: 'Encerrar', cancelText: 'Cancelar',
                onOk: () => accao.mutateAsync({ metodo: 'post', url: `/rh/salarios/periodos/${id}/encerrar` }),
              })}>Encerrar cálculo</Button>
            )}
          </>
        }
      />
      {p.estado !== 'ABERTO' && <Alert type="info" showIcon style={{ marginBottom: 16 }} message="O período não está aberto: os lançamentos não se alteram. Para corrigir, reabra-o no ecrã Processamentos." />}
      <ResumoTotais periodo={p} />
      <Card>
        <Tabs
          items={[
            {
              key: 'lancamentos',
              label: `Lançamentos (${lancamentos.data?.length ?? 0})`,
              children: (
                <>
                  <Flex gap={8} style={{ marginBottom: 12 }}><SeletorColaborador value={filtroColab} onChange={setFiltroColab} /></Flex>
                  <Table<Lancamento> rowKey="id" size="small" loading={lancamentos.isFetching} columns={colunas} dataSource={linhas} scroll={{ x: 'max-content' }}
                    pagination={{ pageSize: 50, showSizeChanger: true, showTotal: (t) => `${t} lançamento(s)` }} />
                </>
              ),
            },
            { key: 'resultados', label: `Resultados (${p.resultados.length})`, children: <TabelaResultados periodo={p} carregando={periodo.isFetching} /> },
          ]}
        />
      </Card>

      <Modal title={lancar === 'novo' ? 'Novo lançamento' : 'Editar lançamento'} open={lancar !== null} onCancel={() => setLancar(null)} okText="Gravar" cancelText="Cancelar"
        confirmLoading={accao.isPending} onOk={() => formL.submit()} destroyOnClose>
        <Typography.Paragraph type="secondary">Há um lançamento por colaborador e rubrica: gravar substitui o existente.</Typography.Paragraph>
        <Form form={formL} layout="vertical" onFinish={(v) => accao.mutate({ metodo: 'post', url: `/rh/salarios/periodos/${id}/lancamentos`, dados: { ...v, horas: porHoras(v.infotipo_salarial_id) ? v.horas ?? null : null } })}>
          <Form.Item name="colaborador_id" label="Colaborador" rules={[{ required: true, message: 'Escolha o colaborador.' }]}>
            <SeletorColaborador style={{ width: '100%' }} disabled={lancar !== 'novo'} />
          </Form.Item>
          <Form.Item name="infotipo_salarial_id" label="Rubrica" rules={[{ required: true, message: 'Escolha a rubrica.' }]}>
            <Select showSearch optionFilterProp="label" disabled={lancar !== 'novo'} options={infotipos.lista.map((i) => ({ value: i.id, label: `${i.nome} (${i.tipo})` }))} />
          </Form.Item>
          {porHoras(rubricaL) ? (
            <Form.Item name="horas" label="Horas" rules={[{ required: true, message: 'Indique as horas.' }]} extra="Valorizadas pelo valor hora do contrato no cálculo.">
              <InputNumber min={0} max={744} step={0.5} style={{ width: '100%' }} />
            </Form.Item>
          ) : (
            <Form.Item name="valor" label="Valor (Kz)" rules={[{ required: true, message: 'Indique o valor.' }]}>
              <InputNumber min={0} precision={2} decimalSeparator="," style={{ width: '100%' }} />
            </Form.Item>
          )}
          <Form.Item name="dias_trabalhados" label="Dias trabalhados" extra="Pro rata face aos dias do contrato.">
            <InputNumber min={0} max={31} step={0.5} style={{ width: '100%' }} />
          </Form.Item>
        </Form>
      </Modal>

      <Modal title="Lançar em lote" open={lote} width={640} onCancel={() => progressoLote === null && setLote(false)} okText="Lançar" cancelText="Cancelar"
        confirmLoading={progressoLote !== null} onOk={() => formLote.submit()} destroyOnClose>
        <Form form={formLote} layout="vertical" onFinish={(v) => void gravarLote(v)}>
          <Form.Item name="infotipo_salarial_id" label="Rubrica" rules={[{ required: true, message: 'Escolha a rubrica.' }]}>
            <Select showSearch optionFilterProp="label" options={infotipos.lista.filter((i) => !i.calculo_horas || i.calculo_horas === 'NAO').map((i) => ({ value: i.id, label: `${i.nome} (${i.tipo})` }))} />
          </Form.Item>
          <Form.Item name="valor" label="Valor por colaborador (Kz)" rules={[{ required: true, message: 'Indique o valor.' }]}>
            <InputNumber min={0} precision={2} decimalSeparator="," style={{ width: '100%' }} />
          </Form.Item>
          <Form.Item name="dias_trabalhados" label="Dias trabalhados (opcional)"><InputNumber min={0} max={31} style={{ width: '100%' }} /></Form.Item>
          <Form.Item name="colaboradores" label="Colaboradores" rules={[{ required: true, message: 'Escolha pelo menos um colaborador.' }]}>
            <SeletorColaborador mode="multiple" apenasActivos style={{ width: '100%' }} maxTagCount="responsive" />
          </Form.Item>
          <Button size="small" onClick={() => formLote.setFieldsValue({ colaboradores: colaboradores.lista.filter((c) => c.estado === 'ACTIVO').map((c) => c.id) })}>Todos os activos</Button>
          {progressoLote !== null && <Progress percent={progressoLote} style={{ marginTop: 12 }} />}
        </Form>
      </Modal>

      <Modal title="Importar efectividade" open={efectividade} onCancel={() => setEfectividade(false)} okText="Importar" cancelText="Cancelar" confirmLoading={accao.isPending} onOk={() => formEf.submit()} destroyOnClose>
        <Typography.Paragraph type="secondary">Lança as horas extra e as faltas do mês fechado na Efectividade (recalculadas com as ausências aprovadas). Os lançamentos que deixaram de ter horas são retirados.</Typography.Paragraph>
        <Form form={formEf} layout="vertical" onFinish={(v) => accao.mutate({ metodo: 'post', url: `/rh/salarios/periodos/${id}/importar-efectividade`, dados: v })}>
          <Form.Item name="infotipo_extra_id" label="Rubrica das horas extra" rules={[{ required: true, message: 'Escolha a rubrica.' }]}><Select options={rubricasHoras('EXTRA')} /></Form.Item>
          <Form.Item name="infotipo_falta_id" label="Rubrica das faltas" rules={[{ required: true, message: 'Escolha a rubrica.' }]}><Select options={rubricasHoras('FALTA')} /></Form.Item>
        </Form>
      </Modal>

      <Modal title="Importar produtividade" open={produtividade} onCancel={() => setProdutividade(false)} okText="Importar" cancelText="Cancelar" confirmLoading={accao.isPending}
        onOk={() => accao.mutate({ metodo: 'post', url: `/rh/salarios/periodos/${id}/importar-produtividade`, dados: { substituir } })}>
        <Typography.Paragraph type="secondary">Lança o subsídio do período de produtividade fechado deste mês.</Typography.Paragraph>
        <Checkbox checked={substituir} onChange={(e) => setSubstituir(e.target.checked)}>Substituir os lançamentos de produtividade existentes</Checkbox>
      </Modal>

      <Modal title="Resultado" open={resultadoImport !== null} onCancel={() => setResultadoImport(null)} footer={<Button type="primary" onClick={() => setResultadoImport(null)}>Fechar</Button>}>
        <Typography.Paragraph strong>{resultadoImport?.titulo}</Typography.Paragraph>
        {resultadoImport && resultadoImport.linhas.length > 0 && (
          <Descriptions size="small" column={1} bordered>
            {resultadoImport.linhas.slice(0, 50).map((l, i) => <Descriptions.Item key={i} label={i + 1}>{l}</Descriptions.Item>)}
          </Descriptions>
        )}
      </Modal>
    </>
  );
}

