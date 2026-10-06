import { Alert, Button, Card, Col, Statistic, DatePicker, Form, Input, InputNumber, Modal, Popconfirm, Progress, Row, Select, Space, Switch, Table, Tabs, Tag, Tooltip, message } from 'antd';
import { DeleteOutlined, EditOutlined, PlusOutlined } from '@ant-design/icons';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import dayjs, { type Dayjs } from 'dayjs';
import { useState } from 'react';
import { enviar, obter } from '@/api/cliente';
import { ErroApi } from '@/api/tipos';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { BarraFiltros, scrollTabela } from '@/componentes/responsivo';
import type { ColunaApi } from '@/componentes/TabelaApi';
import { useSessao } from '@/sessao/SessaoContexto';
import { notificarErro } from '@/utilitarios/erros';
import { dataApi, formatarData } from '@/utilitarios/formatacao';
import { ESTADOS_FERIAS_RH, ROTULOS_ESTADO, type ConfiguracaoRH, type PeriodoFerias, type ResumoFerias } from './api';
import { contem, EstadoTag, PesquisaLocal, SeletorColaborador } from './comum/componentes';
import { useAccaoRh, useAvisarErro, useColaboradores } from './comum/consultas';
import { htmlTabela, seccaoHtml } from './comum/impressao';

import { TabelaComModos } from '@/componentes/vistas';
interface Plano {
  resumo: ResumoFerias[];
  periodos: PeriodoFerias[];
}

interface ValoresFerias {
  colaborador_id: number;
  periodo: [Dayjs, Dayjs];
  estado: string;
  direito?: number | null;
  observacoes?: string;
}

/** RH › Programa de férias (ecrã rh_ferias): direito, marcação, aprovação e gozo das férias. */
export default function Ferias() {
  const { pode } = useSessao();
  const editar = pode('rh_ferias_edit');
  const cliente = useQueryClient();
  const colaboradores = useColaboradores();
  const [ano, setAno] = useState(dayjs().year());
  const [colaborador, setColaborador] = useState<number>();
  const [termo, setTermo] = useState('');
  const [edicao, setEdicao] = useState<PeriodoFerias | 'novo' | null>(null);
  const [form] = Form.useForm<ValoresFerias>();
  const plano = useQuery({ queryKey: ['rh', 'ferias', ano, colaborador], queryFn: () => obter<Plano>('/rh/ferias', { ano, colaborador_id: colaborador }) });
  useAvisarErro(plano.error);
  const accao = useAccaoRh();

  const gravar = useMutation({
    mutationFn: ({ dados, id }: { dados: Record<string, unknown>; id?: number }) => enviar(id ? 'put' : 'post', id ? `/rh/ferias/${id}` : '/rh/ferias', dados),
    onSuccess: ({ mensagem }) => {
      message.success(mensagem);
      setEdicao(null);
      void cliente.invalidateQueries({ queryKey: ['rh'] });
    },
    onError: (e, vars) => {
      if (e instanceof ErroApi && e.codigo === 'SALDO_EXCEDIDO' && !vars.dados.confirmar_excesso) {
        Modal.confirm({ title: 'Exceder o direito a férias?', content: e.message, okText: 'Gravar mesmo assim', cancelText: 'Cancelar', onOk: () => gravar.mutateAsync({ ...vars, dados: { ...vars.dados, confirmar_excesso: true } }) });
      } else if (e instanceof ErroApi && e.codigo === 'ANTIGUIDADE_INSUFICIENTE' && !vars.dados.confirmar_antiguidade) {
        Modal.confirm({ title: 'Gozo antes de 6 meses de serviço?', content: e.message, okText: 'Gravar mesmo assim', cancelText: 'Cancelar', onOk: () => gravar.mutateAsync({ ...vars, dados: { ...vars.dados, confirmar_antiguidade: true } }) });
      } else notificarErro(e);
    },
  });

  const abrir = (p: PeriodoFerias | 'novo') => {
    form.resetFields();
    if (p === 'novo') form.setFieldsValue({ colaborador_id: colaborador, estado: 'PLANEADO' });
    else form.setFieldsValue({ colaborador_id: p.colaborador_id, periodo: [dayjs(p.data_inicio), dayjs(p.data_fim)], estado: p.estado, direito: p.direito, observacoes: p.observacoes ?? undefined });
    setEdicao(p);
  };

  const resumo = (plano.data?.resumo ?? []).filter((r) => contem(r.nome, termo));
  const periodos = (plano.data?.periodos ?? []).filter((p) => contem(colaboradores.nome(p.colaborador_id), termo));

  const colResumo: ColunaApi<ResumoFerias>[] = [
    { title: 'Colaborador', dataIndex: 'nome', render: (v: string) => <strong>{v}</strong>, sorter: (a, b) => a.nome.localeCompare(b.nome, 'pt') },
    { title: 'Direito', dataIndex: 'direito', align: 'right', render: (d: number, r) => (
      <Space size={4}>{d}{r.ano_admissao && <Tooltip title="Ano de admissão: 2 dias úteis por mês completo (LGT)"><Tag color="blue">1.º ano</Tag></Tooltip>}
        {(r.transporte ?? 0) > 0 && <Tooltip title="Saldo transportado do ano anterior"><Tag color="gold">+{r.transporte}</Tag></Tooltip>}</Space>
    ), valorImpressao: (r) => `${r.direito}${r.transporte ? ` (incl. ${r.transporte} transportados)` : ''}` },
    { title: 'Marcados', dataIndex: 'marcados', align: 'right' },
    { title: 'Aprovados', dataIndex: 'aprovados', align: 'right' },
    { title: 'Gozados', dataIndex: 'gozados', align: 'right' },
    { title: 'Pedidos (portal)', dataIndex: 'pedidos', align: 'right', responsive: ['md'] },
    { title: 'Saldo', dataIndex: 'saldo', align: 'right', render: (s: number) => <Tag color={s < 0 ? 'red' : s === 0 ? 'default' : 'green'}>{s}</Tag>, sorter: (a, b) => a.saldo - b.saldo },
    { title: 'Utilização', width: 160, responsive: ['md'], valorImpressao: (r) => `${r.direito ? Math.round((r.marcados / r.direito) * 100) : 0} %`, render: (_, r) => <Progress size="small" percent={r.direito ? Math.round((r.marcados / r.direito) * 100) : 0} status={r.saldo < 0 ? 'exception' : 'normal'} /> },
  ];

  const colPeriodos: ColunaApi<PeriodoFerias>[] = [
    { title: 'Colaborador', dataIndex: 'colaborador_id', render: (v: number) => colaboradores.nome(v) },
    { title: 'Início', dataIndex: 'data_inicio', render: formatarData },
    { title: 'Fim', dataIndex: 'data_fim', render: formatarData },
    { title: 'Dias úteis', dataIndex: 'dias', align: 'right' },
    {
      title: 'Estado',
      dataIndex: 'estado',
      valorImpressao: (p) => ROTULOS_ESTADO[p.estado] ?? p.estado,
      render: (e: string, p) => editar && e !== 'PEDIDO' ? (
        <Select size="small" value={e} style={{ width: 140 }} onChange={(estado) => accao.mutate({ metodo: 'post', url: `/rh/ferias/${p.id}/estado`, dados: { estado } })}
          options={ESTADOS_FERIAS_RH.map((x) => ({ value: x, label: ROTULOS_ESTADO[x] }))} />
      ) : <EstadoTag estado={e} />,
    },
    { title: 'Origem', responsive: ['md'], render: (_, p) => (p.pedido_portal_colaborador_id ? <Tag>Portal #{p.pedido_portal_colaborador_id}</Tag> : 'RH') },
    { title: 'Observações', dataIndex: 'observacoes', responsive: ['lg'], ellipsis: true, render: (v: string | null) => v ?? '' },
    {
      title: '',
      key: 'accoes',
      render: (_, p) => editar && (
        <Space size={4}>
          {p.estado !== 'PEDIDO' && <Button size="small" type="text" icon={<EditOutlined />} aria-label="Editar" onClick={() => abrir(p)} />}
          {!p.pedido_portal_colaborador_id && (
            <Popconfirm title="Eliminar o período de férias?" okText="Eliminar" okButtonProps={{ danger: true }} cancelText="Cancelar"
              onConfirm={() => accao.mutateAsync({ metodo: 'delete', url: `/rh/ferias/${p.id}` })}>
              <Button size="small" type="text" danger icon={<DeleteOutlined />} aria-label="Eliminar" />
            </Popconfirm>
          )}
        </Space>
      ),
    },
  ];

  return (
    <>
      <CabecalhoPagina
        titulo="Programa de Férias"
        subtitulo="Dias úteis pelo calendário da empresa (feriados da configuração da Efectividade); os pedidos do portal decidem-se em «Pedidos do Portal»"
        accoes={editar && <Button type="primary" icon={<PlusOutlined />} onClick={() => abrir('novo')}>Marcar férias</Button>}
        impressaoDesactivada={!resumo.length && !periodos.length}
        impressao={async () => ({
          titulo: 'Programa de férias',
          periodo: String(ano),
          filtros: [colaborador ? `Colaborador: ${colaboradores.nome(colaborador)}` : null, termo ? `Pesquisa: ${termo}` : null],
          conteudo: seccaoHtml('Resumo por colaborador', await htmlTabela(colResumo, resumo)) + seccaoHtml(`Períodos (${periodos.length})`, await htmlTabela(colPeriodos, periodos)),
        })}
      />
      <div className="erp-grelha-auto" style={{ marginBottom: 16 }}>
        {[
          ['Colaboradores', resumo.length],
          ['Dias de direito', resumo.reduce((t, r) => t + r.direito, 0)],
          ['Dias planeados', resumo.reduce((t, r) => t + r.marcados, 0)],
          ['Dias gozados', resumo.reduce((t, r) => t + r.gozados, 0)],
          ['Sem férias planeadas', resumo.filter((r) => r.marcados === 0).length],
          ['Saldo negativo', resumo.filter((r) => r.saldo < 0).length],
        ].map(([t, v]) => <Card key={t} size="small"><Statistic title={t} value={v} /></Card>)}
      </div>
      <Card>
        <BarraFiltros>
          <InputNumber value={ano} min={2000} max={2100} onChange={(v) => v && setAno(v)} prefix="Ano" style={{ width: 150 }} />
          <SeletorColaborador value={colaborador} onChange={setColaborador} />
          <PesquisaLocal aoMudar={setTermo} placeholder="Nome" />
        </BarraFiltros>
        <Tabs items={[
          { key: 'resumo', label: 'Resumo por colaborador', children: <Table<ResumoFerias> rowKey="colaborador_id" size="small" loading={plano.isFetching} columns={colResumo} dataSource={resumo} pagination={{ pageSize: 50 }} scroll={scrollTabela()} /> },
          { key: 'periodos', label: `Períodos (${periodos.length})`, children: <TabelaComModos<PeriodoFerias> idVista="periodos" rowKey="id" size="small" loading={plano.isFetching} columns={colPeriodos} dataSource={periodos} pagination={{ pageSize: 50 }} scroll={scrollTabela()} /> },
          { key: 'regras', label: 'Regras (LGT)', children: <RegrasFerias editar={editar} /> },
        ]} />
      </Card>
      <Modal title={edicao === 'novo' ? 'Marcar férias' : 'Editar férias'} open={edicao !== null} onCancel={() => setEdicao(null)} okText="Gravar" cancelText="Cancelar"
        confirmLoading={gravar.isPending} onOk={() => form.submit()} destroyOnHidden>
        <Form form={form} layout="vertical" onFinish={(v) => gravar.mutate({
          id: edicao && edicao !== 'novo' ? edicao.id : undefined,
          dados: { colaborador_id: v.colaborador_id, data_inicio: dataApi(v.periodo[0]), data_fim: dataApi(v.periodo[1]), estado: v.estado, direito: v.direito ?? null, observacoes: v.observacoes ?? null },
        })}>
          <Form.Item name="colaborador_id" label="Colaborador" rules={[{ required: true }]}><SeletorColaborador apenasActivos style={{ width: '100%' }} disabled={edicao !== 'novo'} /></Form.Item>
          <Form.Item name="periodo" label="Período" rules={[{ required: true, message: 'Indique as datas.' }]}><DatePicker.RangePicker format="DD/MM/YYYY" style={{ width: '100%' }} /></Form.Item>
          <Form.Item name="estado" label="Estado"><Select options={ESTADOS_FERIAS_RH.map((x) => ({ value: x, label: ROTULOS_ESTADO[x] }))} /></Form.Item>
          <Form.Item name="direito" label="Direito anual (dias úteis)" extra="Vazio = direito calculado (22; no ano de admissão 2 por mês completo; mais o saldo transportado). Gravar aplica-o a todos os períodos do ano."><InputNumber min={0} max={60} style={{ width: '100%' }} /></Form.Item>
          <Form.Item name="observacoes" label="Observações"><Input.TextArea rows={2} maxLength={1000} /></Form.Item>
        </Form>
      </Modal>
    </>
  );
}

/**
 * Regras das férias (decisão 7 do utilizador, Lei Geral do Trabalho — Lei n.º 12/23): direito anual de 22 dias úteis;
 * no ano de admissão 2 dias úteis por mês completo de serviço (máx. 22), com gozo só depois de 6 meses; saldo não gozado
 * transportado para o ano seguinte até ao limite. O direito gravado no plano prevalece sempre.
 */
function RegrasFerias({ editar }: { editar: boolean }) {
  const q = useQuery({ queryKey: ['rh', 'configuracao'], queryFn: () => obter<ConfiguracaoRH>('/rh/configuracao') });
  useAvisarErro(q.error);
  const [form] = Form.useForm<Pick<ConfiguracaoRH, 'ferias_dias_mes_admissao' | 'ferias_meses_minimos_gozo' | 'ferias_transporte_saldo' | 'ferias_transporte_max_dias'>>();
  const accao = useAccaoRh();
  return (
    <Card loading={q.isLoading} variant="borderless">
      <Alert type="info" showIcon style={{ marginBottom: 16 }} message="Regra da Lei Geral do Trabalho (Lei n.º 12/23) — confirme com o jurista ou o acordo colectivo"
        description="Direito anual de 22 dias úteis. No ano de admissão: 2 dias úteis por cada mês completo de serviço até 31/12 (máximo 22) e gozo só depois de 6 meses completos. O saldo não gozado de um ano gerido no plano transita para o seguinte, até ao limite indicado. O direito gravado à mão no plano prevalece." />
      {q.data && (
        <Form form={form} layout="vertical" disabled={!editar} initialValues={q.data} style={{ maxWidth: 720 }}
          onFinish={(v) => accao.mutate({ metodo: 'put', url: '/rh/configuracao', dados: v })}>
          <Row gutter={16}>
            <Col xs={24} sm={12}><Form.Item name="ferias_dias_mes_admissao" label="Dias por mês completo (ano de admissão)"><InputNumber min={0} max={5} style={{ width: '100%' }} /></Form.Item></Col>
            <Col xs={24} sm={12}><Form.Item name="ferias_meses_minimos_gozo" label="Meses de serviço antes do gozo"><InputNumber min={0} max={12} style={{ width: '100%' }} /></Form.Item></Col>
            <Col xs={24} sm={12}><Form.Item name="ferias_transporte_saldo" label="Transportar o saldo para o ano seguinte" valuePropName="checked"><Switch /></Form.Item></Col>
            <Col xs={24} sm={12}><Form.Item name="ferias_transporte_max_dias" label="Máximo de dias transportados"><InputNumber min={0} max={66} style={{ width: '100%' }} /></Form.Item></Col>
          </Row>
          {editar && <Button type="primary" htmlType="submit" loading={accao.isPending}>Gravar regras</Button>}
        </Form>
      )}
    </Card>
  );
}
