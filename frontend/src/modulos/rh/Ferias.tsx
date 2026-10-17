import { Alert, Button, Card, Col, DatePicker, Form, Input, InputNumber, Modal, Popconfirm, Progress, Row, Select, Space, Switch, Tabs, Tag, Tooltip, message, theme } from 'antd';
import { DeleteOutlined, EditOutlined, PlusOutlined } from '@ant-design/icons';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import dayjs, { type Dayjs } from 'dayjs';
import { useMemo, useState } from 'react';
import { enviar, obter } from '@/api/cliente';
import { ErroApi } from '@/api/tipos';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { ModalFormulario, type CampoFormulario } from '@/componentes/formularios';
import { BarraFiltros, scrollTabela } from '@/componentes/responsivo';
import type { ColunaApi } from '@/componentes/TabelaApi';
import { useSessao } from '@/sessao/SessaoContexto';
import { notificarErro } from '@/utilitarios/erros';
import { formatarData } from '@/utilitarios/formatacao';
import { ESTADOS_FERIAS_RH, ROTULOS_ESTADO, type ConfiguracaoRH, type PeriodoFerias, type ResumoFerias } from './api';
import { contem, EstadoTag, PesquisaLocal, SeletorColaborador } from './comum/componentes';
import { useAccaoRh, useAvisarErro, useColaboradores } from './comum/consultas';
import { htmlTabela, seccaoHtml } from './comum/impressao';

import { CartaoKpi, GrelhaKpi, TabelaComModos } from '@/componentes/vistas';
import { IconeFa } from '@/componentes/fluxos';
import { anosDisponiveis, conflitos, contaSaldo, corEstadoFerias, ESTADOS_FERIAS, filtrarPorEstado, periodosPorColaborador, rotuloChip, rotuloEstadoFerias, totaisFerias } from './ferias/regrasFerias';
import { MapaAnualFerias } from './ferias/MapaAnualFerias';
import { dataDe, numeroDe, opcoesColaboradores, textoDe } from './comum/linhasRh';

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

/** Corpo do POST/PUT /rh/ferias (grade: `periodo` em intervalo de datas; linhas: `data_inicio` / `data_fim`). */
export function dadosFerias(v: Record<string, unknown>) {
  const periodo = Array.isArray(v.periodo) ? v.periodo : null;
  return {
    colaborador_id: v.colaborador_id,
    data_inicio: dataDe(periodo ? periodo[0] : v.data_inicio),
    data_fim: dataDe(periodo ? periodo[1] : v.data_fim),
    estado: v.estado ?? 'PLANEADO',
    direito: numeroDe(v.direito),
    observacoes: textoDe(v.observacoes),
  };
}

/** Vista Linhas de «Marcar férias» (um período por linha). */
export function camposFerias(colaboradores: CampoFormulario['opcoes']): CampoFormulario[] {
  return [
    { nome: 'colaborador_id', rotulo: 'Colaborador', tipo: 'select', obrigatorio: true, largura: 260, opcoes: colaboradores, aliases: ['Nome', 'NIF', 'Funcionário'] },
    { nome: 'data_inicio', rotulo: 'Início', tipo: 'data', obrigatorio: true, largura: 140, aliases: ['Data início', 'De'] },
    { nome: 'data_fim', rotulo: 'Fim', tipo: 'data', obrigatorio: true, largura: 140, aliases: ['Data fim', 'Até'],
      validar: (f, r) => (f && r.data_inicio && String(f) < String(r.data_inicio) ? 'O fim é anterior ao início.' : null) },
    { nome: 'estado', rotulo: 'Estado', tipo: 'select', largura: 150, omissao: 'PLANEADO', opcoes: ESTADOS_FERIAS_RH.map((x) => ({ value: x, label: ROTULOS_ESTADO[x] ?? x })) },
    { nome: 'direito', rotulo: 'Direito anual (dias úteis)', tipo: 'numero', largura: 120, min: 0, max: 60, emLinhas: 'opcional', aliases: ['Direito'] },
    { nome: 'observacoes', rotulo: 'Observações', largura: 220, maxLength: 1000, emLinhas: 'opcional' },
  ];
}

/** RH › Programa de férias (ecrã rh_ferias): direito, marcação, aprovação e gozo das férias. */
export default function Ferias() {
  const { pode } = useSessao();
  const editar = pode('rh_ferias_edit');
  const { token } = theme.useToken();
  const cliente = useQueryClient();
  const colaboradores = useColaboradores();
  const [ano, setAno] = useState(dayjs().year());
  const [colaborador, setColaborador] = useState<number>();
  const [termo, setTermo] = useState('');
  const [estadoFiltro, setEstadoFiltro] = useState('');
  const [edicao, setEdicao] = useState<PeriodoFerias | 'novo' | null>(null);
  const [form] = Form.useForm<ValoresFerias>();
  const plano = useQuery({ queryKey: ['rh', 'ferias', ano, colaborador], queryFn: () => obter<Plano>('/rh/ferias', { ano, colaborador_id: colaborador }) });
  useAvisarErro(plano.error);
  const accao = useAccaoRh();
  const camposLinhas = useMemo(() => camposFerias(opcoesColaboradores(colaboradores.lista, true)), [colaboradores.lista]);

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

  const abrir = (p: PeriodoFerias | 'novo', colaboradorId?: number) => {
    form.resetFields();
    if (p === 'novo') form.setFieldsValue({ colaborador_id: colaboradorId ?? colaborador, estado: 'PLANEADO' });
    else form.setFieldsValue({ colaborador_id: p.colaborador_id, periodo: [dayjs(p.data_inicio), dayjs(p.data_fim)], estado: p.estado, direito: p.direito, observacoes: p.observacoes ?? undefined });
    setEdicao(p);
  };

  const todosPeriodos = plano.data?.periodos ?? [];
  const porColab = periodosPorColaborador(todosPeriodos);
  const emConflito = conflitos(todosPeriodos);
  const totais = totaisFerias(plano.data?.resumo ?? [], todosPeriodos);
  const resumo = filtrarPorEstado((plano.data?.resumo ?? []).filter((r) => contem(r.nome, termo)), porColab, estadoFiltro);
  const visiveis = new Set(resumo.map((r) => r.colaborador_id));
  const periodos = todosPeriodos.filter((p) => visiveis.has(p.colaborador_id));
  /** Clicar num chip (ou numa barra do mapa) abre o período, como no antigo; os pedidos do portal decidem-se em «Pedidos do Portal». */
  const abrirPeriodo = editar
    ? (p: PeriodoFerias) => (p.estado !== 'PEDIDO' ? abrir(p) : void message.info('Pedido do portal: decida-o em «Pedidos do Portal».'))
    : undefined;

  const colResumo: ColunaApi<ResumoFerias>[] = [
    { title: 'Colaborador', dataIndex: 'nome', render: (v: string) => <strong>{v}</strong>, sorter: (a, b) => a.nome.localeCompare(b.nome, 'pt') },
    { title: 'Direito', dataIndex: 'direito', align: 'right', render: (d: number, r) => (
      <Space size={4}>{d}{r.ano_admissao && <Tooltip title="Ano de admissão: 2 dias úteis por mês completo (LGT)"><Tag color="blue">1.º ano</Tag></Tooltip>}
        {(r.transporte ?? 0) > 0 && <Tooltip title="Saldo transportado do ano anterior"><Tag color="gold">+{r.transporte}</Tag></Tooltip>}</Space>
    ), valorImpressao: (r) => `${r.direito}${r.transporte ? ` (incl. ${r.transporte} transportados)` : ''}` },
    { title: 'Planeados', dataIndex: 'marcados', align: 'right' },
    { title: 'Aprovados', dataIndex: 'aprovados', align: 'right', ocultaPorOmissao: true },
    { title: 'Gozados', dataIndex: 'gozados', align: 'right' },
    { title: 'Pedidos (portal)', dataIndex: 'pedidos', align: 'right', ocultaPorOmissao: true },
    { title: 'Saldo', dataIndex: 'saldo', align: 'right', // antigo: número simples, a vermelho quando negativo (.rhf-saldo-neg)
      render: (s: number) => <span style={s < 0 ? { color: '#dc2626', fontWeight: 600 } : undefined}>{s}</span>, sorter: (a, b) => a.saldo - b.saldo },
    {
      title: `Períodos ${ano}`, key: 'periodos',
      valorFiltro: (r) => (porColab.get(r.colaborador_id) ?? []).map(rotuloChip).join(' '),
      valorImpressao: (r) => (porColab.get(r.colaborador_id) ?? []).map((p) => `${rotuloChip(p)} (${rotuloEstadoFerias(p.estado)})`).join('; ') || 'Sem períodos',
      render: (_, r) => {
        const ps = porColab.get(r.colaborador_id) ?? [];
        if (!ps.length) return <small style={{ color: '#94a3b8' }}>Sem períodos</small>;
        return (
          <div style={{ display: 'flex', flexWrap: 'wrap', gap: 4 }}>
            {ps.map((p) => {
              const conflito = emConflito.has(p.id);
              return (
                <button key={p.id} type="button" onClick={() => abrirPeriodo?.(p)}
                  title={`${rotuloEstadoFerias(p.estado)}${conflito ? ' — sobrepõe outro período' : ''}${p.observacoes ? ` — ${p.observacoes}` : ''}`}
                  style={{ display: 'inline-flex', alignItems: 'center', gap: 4, border: `1px solid ${token.colorBorder}`, borderLeft: `4px solid ${corEstadoFerias(p.estado)}`, borderRadius: 4,
                    padding: '1px 7px', fontSize: '0.78rem', background: token.colorBgContainer, color: token.colorText, cursor: abrirPeriodo ? 'pointer' : 'default', boxShadow: conflito ? 'inset 0 0 0 1px #f59e0b' : undefined,
                    textDecoration: contaSaldo(p) ? undefined : 'line-through' }}>
                  {rotuloChip(p)}{conflito && <span style={{ color: '#f59e0b' }}><IconeFa nome="exclamation-triangle" /></span>}
                </button>
              );
            })}
          </div>
        );
      },
    },
    { title: 'Utilização', width: 160, ocultaPorOmissao: true, valorImpressao: (r) => `${r.direito ? Math.round((r.marcados / r.direito) * 100) : 0} %`, render: (_, r) => <Progress size="small" percent={r.direito ? Math.round((r.marcados / r.direito) * 100) : 0} status={r.saldo < 0 ? 'exception' : 'normal'} /> },
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
        subtitulo="Plano anual de férias por colaborador: direito, períodos, saldo e mapa anual."
        accoes={editar && <Button type="primary" icon={<PlusOutlined />} onClick={() => abrir('novo')}>Novo período</Button>}
        impressaoDesactivada={!resumo.length && !periodos.length}
        impressao={async () => ({
          titulo: 'Programa de férias',
          periodo: String(ano),
          filtros: [colaborador ? `Colaborador: ${colaboradores.nome(colaborador)}` : null, termo ? `Pesquisa: ${termo}` : null],
          conteudo: seccaoHtml('Resumo por colaborador', await htmlTabela(colResumo, resumo)) + seccaoHtml(`Períodos (${periodos.length})`, await htmlTabela(colPeriodos, periodos)),
        })}
      />
      {/* antigo (ferias.js:231, .rhf-kpis): seis caixas sem ícone, «Com sobreposições» em âmbar quando há conflitos */}
      <GrelhaKpi larguraMinima={150}>
        <CartaoKpi barra="nenhuma" rotulo="Colaboradores" valor={totais.colaboradores} />
        <CartaoKpi barra="nenhuma" rotulo="Dias de direito" valor={totais.direito} />
        <CartaoKpi barra="nenhuma" rotulo="Dias planeados" valor={totais.planeados} />
        <CartaoKpi barra="nenhuma" rotulo="Dias gozados" valor={totais.gozados} />
        <CartaoKpi barra="nenhuma" rotulo="Sem férias planeadas" valor={totais.sem} cor="laranja"
          aoClicar={() => setEstadoFiltro(estadoFiltro === 'SEM' ? '' : 'SEM')} activo={estadoFiltro === 'SEM'} />
        <CartaoKpi barra="nenhuma" rotulo="Com sobreposições" valor={totais.sobreposicoes} cor="laranja" corValor={totais.sobreposicoes ? '#b45309' : undefined} />
      </GrelhaKpi>
      <Card style={{ marginTop: 16 }}>
        <BarraFiltros>
          {/* antigo (ferias.js:163): o ano escolhe-se numa lista */}
          <Select<number> aria-label="Ano" prefix="Ano" value={ano} onChange={setAno} style={{ width: 130 }}
            options={anosDisponiveis(dayjs().year(), ano).map((a) => ({ value: a, label: String(a) }))} />
          <SeletorColaborador value={colaborador} onChange={setColaborador} />
          <PesquisaLocal aoMudar={setTermo} placeholder="Pesquisar colaborador" />
          <Select<string> value={estadoFiltro} onChange={setEstadoFiltro} style={{ width: 240 }} aria-label="Estado"
            options={[{ value: '', label: 'Estado: todos' }, ...ESTADOS_FERIAS.map((e) => ({ value: e.k, label: `Com períodos: ${e.r.toLowerCase()}` })), { value: 'SEM', label: 'Sem férias planeadas' }]} />
        </BarraFiltros>
        <Tabs items={[
          {
            key: 'resumo', label: 'Resumo por colaborador', children: (
              <>
                <TabelaComModos<ResumoFerias> idVista="resumo-ferias" rowKey="colaborador_id" size="small" loading={plano.isFetching} columns={colResumo} dataSource={resumo} scroll={scrollTabela()} modos={false}
                  vazio={{ icone: 'umbrella-beach', titulo: 'Sem colaboradores', texto: 'Nenhum colaborador corresponde aos filtros.' }}
                  accoes={(r) => [editar && { icone: 'plus', titulo: `Adicionar período a ${r.nome}`, texto: 'Período', onClick: () => abrir('novo', r.colaborador_id) }]} />
                {resumo.length > 0 && <MapaAnualFerias ano={ano} resumo={resumo} porColab={porColab} aoAbrir={abrirPeriodo} />}
              </>
            ),
          },
          { key: 'periodos', label: `Períodos (${periodos.length})`, children: <TabelaComModos<PeriodoFerias> idVista="periodos" rowKey="id" size="small" loading={plano.isFetching} columns={colPeriodos} dataSource={periodos} pagination={{ pageSize: 50 }} scroll={scrollTabela()} /> },
          { key: 'regras', label: 'Regras (LGT)', children: <RegrasFerias editar={editar} /> },
        ]} />
      </Card>
      <ModalFormulario chave="rh.ferias" titulo={edicao === 'novo' ? 'Marcar férias' : 'Editar férias'} aberto={edicao !== null} aoFechar={() => setEdicao(null)}
        criacao={edicao === 'novo'} form={form} campos={camposLinhas} nomeRegistos="períodos de férias" invalidar={[['rh']]}
        nota="Um período por linha. As linhas que excedam o direito ou caiam antes dos 6 meses de serviço ficam por gravar com a mensagem do servidor: marque-as na grade para confirmar. Linhas deixadas em branco são ignoradas."
        gravarLinha={(r) => enviar('post', '/rh/ferias', dadosFerias(r))}
        okText="Gravar" cancelText="Cancelar" confirmLoading={gravar.isPending} onOk={() => form.submit()}>
        <Form form={form} layout="vertical" onFinish={(v) => gravar.mutate({
          id: edicao && edicao !== 'novo' ? edicao.id : undefined,
          dados: dadosFerias(v as unknown as Record<string, unknown>),
        })}>
          <Form.Item name="colaborador_id" label="Colaborador" rules={[{ required: true }]}><SeletorColaborador apenasActivos style={{ width: '100%' }} disabled={edicao !== 'novo'} /></Form.Item>
          <Form.Item name="periodo" label="Período" rules={[{ required: true, message: 'Indique as datas.' }]}><DatePicker.RangePicker format="DD/MM/YYYY" style={{ width: '100%' }} /></Form.Item>
          <Form.Item name="estado" label="Estado"><Select options={ESTADOS_FERIAS_RH.map((x) => ({ value: x, label: ROTULOS_ESTADO[x] }))} /></Form.Item>
          <Form.Item name="direito" label="Direito anual (dias úteis)" extra="Vazio = direito calculado (22; no ano de admissão 2 por mês completo; mais o saldo transportado). Gravar aplica-o a todos os períodos do ano."><InputNumber min={0} max={60} style={{ width: '100%' }} /></Form.Item>
          <Form.Item name="observacoes" label="Observações"><Input.TextArea rows={2} maxLength={1000} /></Form.Item>
        </Form>
      </ModalFormulario>
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
