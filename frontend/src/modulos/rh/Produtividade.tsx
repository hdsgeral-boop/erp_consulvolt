import { useSeparadorUrl } from '@/componentes/navegacao/useSeparadorUrl';
import { ImportarExcel } from './comum/ImportarExcel';
import { Alert, Button, Card, Checkbox, Col, DatePicker, Descriptions, Form, Input, InputNumber, Modal, Row, Select, Space, Tabs, Tag, Typography } from 'antd';
import { ImportOutlined, LockOutlined, PlusOutlined, UnlockOutlined } from '@ant-design/icons';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import dayjs, { type Dayjs } from 'dayjs';
import { useEffect, useState } from 'react';
import { Route, Routes, useNavigate, useParams } from 'react-router-dom';
import { enviar, obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { ModalFormulario, type CampoFormulario } from '@/componentes/formularios';
import { IconeFa } from '@/componentes/fluxos';
import { scrollTabela } from '@/componentes/responsivo';
import type { ColunaApi } from '@/componentes/TabelaApi';
import { useSessao } from '@/sessao/SessaoContexto';
import { dataApi, formatarData, formatarDataHora, formatarKz, formatarNumero } from '@/utilitarios/formatacao';
import { METRICAS_PRODUTIVIDADE, type DetalheProdutividade, type ItemProdutividade, type PeriodoProdutividade, type RegistoProdutividade } from './api';
import { CadastroSimples } from './comum/CadastroSimples';
import { EstadoTag } from './comum/componentes';
import { useAccaoRh, useAvisarErro, useInfotipos } from './comum/consultas';
import { mesPorExtenso, somar } from './comum/regras';
import { CartaoKpi, GrelhaKpi, TabelaComModos } from '@/componentes/vistas';
import {
  descricaoMetrica, nomeMetrica, parametrosSimulacao, periodoActualProdutividade, resumoProdutividade, textoExplicacao,
  type ExplicacaoCalculo, type LinhaResumoProdutividade, type MetricasProdutividade, type ValoresSimulacao,
} from './produtividade/regrasProdutividade';
import { PonteProdutividade } from '@/modulos/projectos/comum/PonteProdutividade';
import { campoSimNao, dataDe, numeroDe, textoDe } from './comum/linhasRh';

/** Métricas e bases de cálculo descritas pelo servidor (GET /rh/produtividade/metricas — a mesma fonte da explicação do cálculo). */
export function useMetricasProdutividade() {
  return useQuery({ queryKey: ['rh', 'produtividade', 'metricas'], queryFn: () => obter<MetricasProdutividade>('/rh/produtividade/metricas'), staleTime: Infinity });
}

/** Valor que só muda depois de `ms` sem alterações (evita um pedido por tecla na pré-visualização). */
function useAdiado<T>(valor: T, ms: number): T {
  const [v, setV] = useState(valor);
  useEffect(() => { const t = setTimeout(() => setV(valor), ms); return () => clearTimeout(t); }, [valor, ms]);
  return v;
}

/** Vista Linhas dos «Itens de produtividade» (um item por linha; o mesmo POST e o mesmo `paraEnvio` da grade). */
export function camposItemProdutividade(rubricas: CampoFormulario['opcoes'], metricas?: MetricasProdutividade): CampoFormulario[] {
  return [
    { nome: 'codigo', rotulo: 'Código', obrigatorio: true, largura: 110, maxLength: 20 },
    { nome: 'descricao', rotulo: 'Descrição', obrigatorio: true, largura: 240, maxLength: 500 },
    { nome: 'metrica', rotulo: 'Métrica', tipo: 'select', largura: 190, omissao: 'QUANTIDADE', opcoes: METRICAS_PRODUTIVIDADE.map((m) => ({ value: m, label: nomeMetrica(metricas, m) })) },
    { nome: 'unidade', rotulo: 'Unidade', largura: 90, maxLength: 10 },
    { nome: 'base_calculo', rotulo: 'Base de cálculo', tipo: 'select', largura: 240, omissao: 'UNITARIO', emLinhas: 'opcional',
      opcoes: (['UNITARIO', 'PREMIO'] as const).map((b) => ({ value: b, label: metricas?.bases[b]?.nome ?? b })),
      validar: (b, r) => (b === 'PREMIO' && r.metrica !== 'OBJECTIVO' ? 'O prémio só se aplica ao cumprimento de objectivo.' : null) },
    { nome: 'preco_unitario', rotulo: 'Preço / prémio', tipo: 'numero', obrigatorio: true, largura: 120, min: 0.0001, precisao: 4, aliases: ['Preço', 'Valor por unidade'] },
    { nome: 'minimo', rotulo: 'Mínimo (total)', tipo: 'numero', largura: 110, min: 0, emLinhas: 'opcional' },
    { nome: 'maximo', rotulo: 'Máximo (total)', tipo: 'numero', largura: 110, min: 0, emLinhas: 'opcional' },
    { nome: 'infotipo_salarial_id', rotulo: 'Rubrica (vencimento)', tipo: 'select', obrigatorio: true, largura: 220, opcoes: rubricas, aliases: ['Rubrica'] },
    campoSimNao('ativo', 'Activo', { omissao: true }),
  ];
}

/** Corpo do POST/PUT de um registo de produtividade (grade e linhas). */
export const dadosRegistoProdutividade = (v: Record<string, unknown>) => ({
  colaborador_id: v.colaborador_id,
  item_produtividade_id: v.item_produtividade_id,
  quantidade: numeroDe(v.quantidade),
  data: dataDe(v.data),
  observacoes: textoDe(v.observacoes),
});

/**
 * RH › Subsídio de produtividade (ecrã rh_produtividade), como no antigo (js/modules/rh/produtividade_ui.js): abre
 * directamente no período actual (o aberto mais recente, senão o último) com os separadores Registos do período ·
 * Resumo · Importar Excel · Itens de produtividade · Colaboradores elegíveis; a lista de períodos fica num separador.
 */
export default function Produtividade() {
  return (
    <Routes>
      <Route index element={<EcraProdutividade />} />
      <Route path=":id" element={<EcraProdutividade />} />
    </Routes>
  );
}

const SEPARADORES = [
  ['registos', 'Registos do período', 'edit'],
  ['resumo', 'Resumo', 'calculator'],
  ['importar', 'Importar Excel', 'file-excel'],
  ['itens', 'Itens de produtividade', 'tags'],
  ['elegiveis', 'Colaboradores elegíveis', 'user-check'],
  // Versão Actual (produtividade_projectos.js:251-386): horas, quantidades medidas e tarefas concluídas dos projectos
  ['projectos', 'Execução de projectos', 'hard-hat'],
  ['periodos', 'Períodos', 'list'],
] as const;
type Separador = (typeof SEPARADORES)[number][0];

function EcraProdutividade() {
  const { id } = useParams();
  const navegar = useNavigate();
  const { pode } = useSessao();
  const periodos = useQuery({ queryKey: ['rh', 'produtividade', 'periodos'], queryFn: () => obter<PeriodoProdutividade[]>('/rh/produtividade/periodos') });
  useAvisarErro(periodos.error);
  const [sep, setSep] = useSeparadorUrl<Separador>(SEPARADORES.map(([k]) => k), 'registos');   // ?sep= (pesquisa do cabeçalho)
  const [novo, setNovo] = useState(false);
  const periodoId = id ? Number(id) : periodoActualProdutividade(periodos.data ?? [])?.id;
  const irPara = (pid: number) => navegar(id ? `../${pid}` : String(pid), { relative: 'path' });

  return (
    <>
      <CabecalhoPagina
        titulo={<Space><IconeFa nome="chart-line" />Subsídio de produtividade</Space>}
        subtitulo="Produtividade medida por quantidade, horas, objectivo, pontos ou tarefas; valor = quantidade × preço unitário (no cumprimento de objectivo pode ser prémio a 100 % × % atingida). Só colaboradores com itens de produtividade no contrato. O período fechado é importado no cálculo salarial."
      />
      <Card size="small" style={{ marginBottom: 12 }}>
        <Space wrap align="end" style={{ width: '100%' }}>
          <div>
            <Typography.Text style={{ display: 'block', marginBottom: 4 }}>Período de cálculo</Typography.Text>
            <Select<number> style={{ minWidth: 320 }} loading={periodos.isLoading} value={periodoId} onChange={irPara} aria-label="Período de cálculo" placeholder="— sem períodos —"
              options={(periodos.data ?? []).map((p) => ({ value: p.id, label: `${mesPorExtenso(p.mes)} · medição ${formatarData(p.data_inicio)} a ${formatarData(p.data_fim)} · ${p.estado === 'FECHADO' ? 'fechado' : 'aberto'}` }))} />
          </div>
          {pode('rh_prod_periodo') && <Button type="primary" icon={<PlusOutlined />} onClick={() => setNovo(true)}>Novo período</Button>}
          {periodoId !== undefined && <AccoesPeriodo id={periodoId} />}
        </Space>
      </Card>
      <Tabs type="card" activeKey={sep} onChange={(k) => setSep(k as Separador)}
        items={SEPARADORES.map(([k, rotulo, icone]) => ({ key: k, label: <span><IconeFa nome={icone} /> {rotulo}</span> }))} />
      {sep === 'itens' && <ItensProdutividade />}
      {sep === 'projectos' && <ExecucaoProjectos />}
      {sep === 'periodos' && <ListaPeriodos aoAbrir={(pid) => { irPara(pid); setSep('registos'); }} />}
      {sep !== 'itens' && sep !== 'periodos' && sep !== 'projectos' && (periodoId === undefined
        ? <Card loading={periodos.isLoading}>{!periodos.isLoading && <Alert type="info" showIcon message="Crie primeiro o período de cálculo da produtividade (botão «Novo período»)." />}</Card>
        : <PainelPeriodo id={periodoId} separador={sep} />)}
      <NovoPeriodo aberto={novo} aoFechar={() => setNovo(false)} aoCriar={(p) => { setNovo(false); irPara(p.id); setSep('registos'); }} />
    </>
  );
}

/**
 * Separador «Execução de projectos» (Versão Actual): intervalo e projectos a recolher; a análise contra o período e os
 * contratos e o envio (sem duplicar) são os da ponte comum com o separador Execução do projecto.
 */
function ExecucaoProjectos() {
  const [intervalo, setIntervalo] = useState<[Dayjs, Dayjs]>([dayjs().subtract(1, 'month').startOf('month'), dayjs().subtract(1, 'month').endOf('month')]);
  const [projetos, setProjetos] = useState<number[]>([]);
  const lista = useQuery({ queryKey: ['projectos', 'carteira-ponte'], queryFn: () => obter<{ id: number; codigo: string | null; nome: string }[]>('/projetos/ativos'), retry: false });
  return (
    <Card size="small" title="Execução de projectos → produtividade">
      <Space wrap style={{ marginBottom: 12 }}>
        <DatePicker.RangePicker format="DD/MM/YYYY" value={intervalo} allowClear={false} onChange={(v) => v?.[0] && v[1] && setIntervalo([v[0], v[1]])} />
        <Select mode="multiple" allowClear style={{ minWidth: 320 }} placeholder="Todos os projectos" value={projetos} onChange={setProjetos} loading={lista.isLoading}
          optionFilterProp="label" options={(lista.data ?? []).map((p) => ({ value: p.id, label: `${p.codigo ? `${p.codigo} · ` : ''}${p.nome}` }))} />
      </Space>
      <PonteProdutividade de={intervalo[0].format('YYYY-MM-DD')} ate={intervalo[1].format('YYYY-MM-DD')} projetos={projetos.length ? projetos : undefined} />
    </Card>
  );
}

function useDetalhe(id: number) {
  const q = useQuery({ queryKey: ['rh', 'produtividade', 'periodo', String(id)], queryFn: () => obter<DetalheProdutividade>(`/rh/produtividade/periodos/${id}`) });
  useAvisarErro(q.error);
  return q;
}

/** Fechar / Reabrir o período escolhido (pílula do estado e lançamento no cálculo). */
function AccoesPeriodo({ id }: { id: number }) {
  const { pode } = useSessao();
  const q = useDetalhe(id);
  const [reabrir, setReabrir] = useState(false);
  const [formR] = Form.useForm<{ motivo: string }>();
  const accao = useAccaoRh(() => setReabrir(false));
  const p = q.data;
  if (!p) return null;
  const aberto = p.estado !== 'FECHADO';
  return (
    <>
      <Space wrap style={{ paddingBottom: 4 }}>
        <EstadoTag estado={p.estado} />
        {p.lancado_em && <Tag color="purple">Lançado no cálculo {formatarDataHora(p.lancado_em)}</Tag>}
        {aberto && pode('rh_prod_periodo') && (
          <Button icon={<LockOutlined />} onClick={() => Modal.confirm({ title: 'Fechar o período?', content: 'Depois de fechado pode ser importado no processamento salarial do mês.', okText: 'Fechar', cancelText: 'Cancelar', onOk: () => accao.mutateAsync({ metodo: 'post', url: `/rh/produtividade/periodos/${id}/fechar` }) })}>Fechar período</Button>
        )}
        {!aberto && pode('rh_prod_periodo') && <Button icon={<UnlockOutlined />} onClick={() => { formR.resetFields(); setReabrir(true); }}>Reabrir</Button>}
      </Space>
      <Modal title="Reabrir período de produtividade" open={reabrir} onCancel={() => setReabrir(false)} okText="Reabrir" okButtonProps={{ danger: true }} cancelText="Cancelar" confirmLoading={accao.isPending} onOk={() => formR.submit()} destroyOnHidden>
        <Form form={formR} layout="vertical" onFinish={(v) => accao.mutate({ metodo: 'post', url: `/rh/produtividade/periodos/${id}/reabrir`, dados: v })}>
          <Form.Item name="motivo" label="Motivo" rules={[{ required: true, min: 5, message: 'Pelo menos 5 caracteres.' }]}><Input.TextArea rows={3} maxLength={500} /></Form.Item>
          <Typography.Text type="secondary">Exige o processamento salarial do mês aberto. Se já tiver sido lançada no cálculo, volte a importar depois de fechar.</Typography.Text>
        </Form>
      </Modal>
    </>
  );
}

function NovoPeriodo({ aberto, aoFechar, aoCriar }: { aberto: boolean; aoFechar: () => void; aoCriar: (p: PeriodoProdutividade) => void }) {
  const [form] = Form.useForm<{ mes: Dayjs; janela: [Dayjs, Dayjs]; observacoes?: string }>();
  const accao = useAccaoRh<PeriodoProdutividade>((p) => aoCriar(p));
  return (
    <Modal title="Novo período de produtividade" open={aberto} onCancel={aoFechar} okText="Abrir" cancelText="Cancelar" confirmLoading={accao.isPending} onOk={() => form.submit()} destroyOnHidden
      afterOpenChange={(v) => { if (v) { const m = dayjs().startOf('month'); form.setFieldsValue({ mes: m, janela: [m, m.endOf('month')] }); } }}>
      <Form form={form} layout="vertical" onFinish={(v) => accao.mutate({ metodo: 'post', url: '/rh/produtividade/periodos', dados: { mes: v.mes.format('YYYY-MM'), data_inicio: dataApi(v.janela[0]), data_fim: dataApi(v.janela[1]), observacoes: v.observacoes ?? null } })}>
        <Form.Item name="mes" label="Mês do processamento" rules={[{ required: true }]}><DatePicker picker="month" format="MM/YYYY" style={{ width: '100%' }} /></Form.Item>
        <Form.Item name="janela" label="Janela de medição (até 93 dias)" rules={[{ required: true }]}><DatePicker.RangePicker format="DD/MM/YYYY" style={{ width: '100%' }} /></Form.Item>
        <Form.Item name="observacoes" label="Observações"><Input.TextArea rows={2} maxLength={1000} /></Form.Item>
      </Form>
    </Modal>
  );
}

function ListaPeriodos({ aoAbrir }: { aoAbrir: (id: number) => void }) {
  const q = useQuery({ queryKey: ['rh', 'produtividade', 'periodos'], queryFn: () => obter<PeriodoProdutividade[]>('/rh/produtividade/periodos') });
  const colunas: ColunaApi<PeriodoProdutividade>[] = [
    { title: 'Mês', dataIndex: 'mes', render: (m: string) => <strong>{mesPorExtenso(m)}</strong>, valorFiltro: (p) => mesPorExtenso(p.mes) },
    { title: 'Janela de medição', render: (_, p) => `${formatarData(p.data_inicio)} a ${formatarData(p.data_fim)}` },
    { title: 'Estado', dataIndex: 'estado', render: (e: string) => <EstadoTag estado={e} /> },
    { title: 'Total no fecho', dataIndex: 'total_fecho', align: 'right', render: (v: string | null) => (v ? formatarKz(v) : '—') },
    { title: 'Lançado', dataIndex: 'lancado_em', render: (v: string | null) => (v ? formatarDataHora(v) : '—') },
  ];
  return (
    <Card>
      <TabelaComModos<PeriodoProdutividade> idVista="periodos" rowKey="id" size="middle" loading={q.isFetching} dataSource={q.data ?? []} scroll={scrollTabela()} modos={false}
        impressao={{ titulo: 'Períodos de produtividade' }} columns={colunas}
        accoes={(p) => [{ icone: 'folder-open', titulo: 'Abrir os registos do período', onClick: () => aoAbrir(p.id) }]}
        vazio={{ icone: 'chart-line', titulo: 'Sem períodos', texto: 'Crie o primeiro com «Novo período».' }} />
    </Card>
  );
}

function ItensProdutividade() {
  const { pode } = useSessao();
  const infotipos = useInfotipos();
  const metricas = useMetricasProdutividade();
  useAvisarErro(metricas.error);
  const camposLinhas = camposItemProdutividade(infotipos.lista.filter((i) => i.tipo === 'VENCIMENTO').map((i) => ({ value: i.id, label: i.nome })), metricas.data);
  return (
    <CadastroSimples<ItemProdutividade>
      url="/rh/produtividade/itens"
      chave={['rh', 'produtividade', 'itens']}
      nomeItem="item de produtividade"
      tituloImpressao="Itens de produtividade"
      podeGerir={pode('rh_prod_config')}
      podeEliminar={pode('rh_prod_config')}
      pesquisa={(r) => `${r.codigo} ${r.descricao}`}
      valoresNovos={{ metrica: 'QUANTIDADE', base_calculo: 'UNITARIO', ativo: true }}
      paraFormulario={(r) => ({ ...r, base_calculo: r.base_calculo === 'PREMIO' ? 'PREMIO' : 'UNITARIO', preco_unitario: Number(r.preco_unitario), minimo: r.minimo !== null ? Number(r.minimo) : null, maximo: r.maximo !== null ? Number(r.maximo) : null })}
      // #11: a base «prémio» só se envia na métrica OBJECTIVO (o servidor recusa noutras)
      paraEnvio={(v) => ({ ...v, base_calculo: v.metrica === 'OBJECTIVO' ? v.base_calculo ?? 'UNITARIO' : 'UNITARIO' })}
      linhas={{ chave: 'rh.produtividade.itens', campos: camposLinhas, nomeRegistos: 'itens de produtividade' }}
      colunas={[
        { title: 'Código', dataIndex: 'codigo', render: (v: string) => <strong>{v}</strong> },
        { title: 'Descrição', dataIndex: 'descricao' },
        { title: 'Métrica', dataIndex: 'metrica', render: (v: string | null) => nomeMetrica(metricas.data, v) },
        { title: 'Base de cálculo', key: 'base', render: (_, r) => (r.base_calculo === 'PREMIO' ? <Tag color="purple">Prémio × % atingida</Tag> : 'Quantidade × preço') },
        { title: 'Unidade', dataIndex: 'unidade', render: (v: string | null) => v ?? '—' },
        { title: 'Preço / prémio', dataIndex: 'preco_unitario', align: 'right', render: (v: string) => formatarKz(v) },
        { title: 'Mín./máx. (total)', render: (_, r) => `${r.minimo !== null ? formatarNumero(r.minimo) : '—'} / ${r.maximo !== null ? formatarNumero(r.maximo) : '—'}` },
        { title: 'Rubrica', dataIndex: 'infotipo_salarial_id', render: (v: number) => infotipos.nome(v) },
        { title: 'Activo', dataIndex: 'ativo', render: (v: boolean) => (v ? <Tag color="green">Sim</Tag> : <Tag>Não</Tag>) },
      ]}
      campos={
        <Row gutter={12}>
          <Col xs={24} sm={12} md={8}><Form.Item name="codigo" label="Código" rules={[{ required: true }, { max: 20 }]}><Input /></Form.Item></Col>
          <Col xs={24} md={16}><Form.Item name="descricao" label="Descrição" rules={[{ required: true }, { max: 500 }]}><Input /></Form.Item></Col>
          <Col xs={24} sm={12} md={8}><Form.Item name="metrica" label="Métrica"><Select loading={metricas.isLoading} options={METRICAS_PRODUTIVIDADE.map((m) => ({ value: m, label: nomeMetrica(metricas.data, m) }))} /></Form.Item></Col>
          <Col xs={24} sm={12} md={8}><Form.Item name="unidade" label="Unidade"><Input maxLength={10} /></Form.Item></Col>
          {/* #11: rótulos, fórmula e exemplo da métrica; base «prémio» só no cumprimento de objectivo (produtividade.js:36-160) */}
          <Form.Item noStyle shouldUpdate={(a, b) => a.metrica !== b.metrica || a.base_calculo !== b.base_calculo || a.preco_unitario !== b.preco_unitario
            || a.minimo !== b.minimo || a.maximo !== b.maximo || a.unidade !== b.unidade}>
            {({ getFieldValue }) => (
              <CamposCalculoItem metricas={metricas.data} valores={{
                metrica: getFieldValue('metrica'), base_calculo: getFieldValue('base_calculo'), preco_unitario: getFieldValue('preco_unitario'),
                unidade: getFieldValue('unidade'), minimo: getFieldValue('minimo'), maximo: getFieldValue('maximo'),
              }} />
            )}
          </Form.Item>
          <Col xs={24} sm={12} md={8}><Form.Item name="ativo" label=" " valuePropName="checked"><Checkbox>Activo</Checkbox></Form.Item></Col>
          <Col xs={24}>
            <Form.Item name="infotipo_salarial_id" label="Rubrica (vencimento)" rules={[{ required: true }]}>
              <Select showSearch optionFilterProp="label" options={infotipos.lista.filter((i) => i.tipo === 'VENCIMENTO').map((i) => ({ value: i.id, label: i.nome }))} />
            </Form.Item>
          </Col>
        </Row>
      }
      larguraModal={720}
    />
  );
}

/**
 * #11 — campos do item que dependem da métrica (base, preço, mínimo, máximo) e a explicação do cálculo com os valores do
 * formulário. Rótulos, fórmula e conta vêm do servidor (GET /rh/produtividade/explicar simula o item por gravar com o mesmo
 * motor que calcula os registos); aqui só se formata.
 */
export function CamposCalculoItem({ metricas, valores }: { metricas?: MetricasProdutividade; valores: ValoresSimulacao }) {
  const m = descricaoMetrica(metricas, valores.metrica);
  const pedido = parametrosSimulacao(valores);
  const chave = useAdiado(JSON.stringify(pedido), 300);
  const exp = useQuery({
    queryKey: ['rh', 'produtividade', 'explicar', chave],
    queryFn: () => obter<ExplicacaoCalculo>('/rh/produtividade/explicar', JSON.parse(chave) as Record<string, string>),
    placeholderData: keepPreviousData,
    staleTime: 60_000,
  });
  const e = exp.data;
  const temPreco = Number(pedido.preco) > 0;
  const unidade = valores.metrica === 'OBJECTIVO' ? '%' : (valores.unidade ?? '');
  return (
    <>
      {m?.base_premio && (
        <Col xs={24} sm={12} md={8}>
          <Form.Item name="base_calculo" label="Base de cálculo">
            <Select options={(['UNITARIO', 'PREMIO'] as const).map((b) => ({ value: b, label: metricas?.bases[b]?.nome ?? b }))} />
          </Form.Item>
        </Col>
      )}
      <Col xs={24} sm={12} md={8}><Form.Item name="preco_unitario" label={e?.rotulo_preco ?? m?.rotulo_preco ?? 'Preço unitário'} rules={[{ required: true }]}><InputNumber min={0.0001} precision={4} decimalSeparator="," style={{ width: '100%' }} /></Form.Item></Col>
      <Col xs={24} sm={12} md={8}><Form.Item name="minimo" label={`${m?.rotulo_minimo ?? 'Mínimo'} (total)`}><InputNumber min={0} style={{ width: '100%' }} /></Form.Item></Col>
      <Col xs={24} sm={12} md={8}><Form.Item name="maximo" label={`${m?.rotulo_maximo ?? 'Máximo'} (total)`}><InputNumber min={0} style={{ width: '100%' }} /></Form.Item></Col>
      <Col xs={24}>
        <Alert type="info" showIcon style={{ marginBottom: 12 }} data-testid="explicacao-item" message={e?.formula ?? m?.formula ?? 'A carregar a fórmula…'}
          description={<>
            {m && <div>{m.ajuda}</div>}
            {m && <div style={{ marginTop: 4 }}><b>Exemplo:</b> {m.exemplo}</div>}
            {e && temPreco && <div style={{ marginTop: 4 }} aria-live="polite"><b>Com estes valores, para {formatarNumero(e.quantidade)} {unidade}:</b> {textoExplicacao(e)}</div>}
            {exp.isError && <Typography.Text type="warning">Não foi possível obter a explicação do cálculo.</Typography.Text>}
          </>} />
      </Col>
    </>
  );
}

/** GET /rh/produtividade/periodos/{id}/explicacao — a conta de cada colaborador e item. */
interface ExplicacaoProdutividade {
  colaborador_id: number;
  item_produtividade_id: number;
  codigo: string;
  formula: string;
  conta: string;
  cortes: string[];
  valor: string;
  valor_gravado: string;
  precos_diferentes: boolean;
}

interface ValoresRegisto {
  colaborador_id: number;
  item_produtividade_id: number;
  quantidade: number;
  data?: Dayjs | null;
  observacoes?: string;
}

/** Separadores que dependem do período: registos, resumo, importar e colaboradores elegíveis. */
function PainelPeriodo({ id, separador }: { id: number; separador: Exclude<Separador, 'itens' | 'periodos'> }) {
  const { pode } = useSessao();
  const q = useDetalhe(id);
  const itens = useQuery({ queryKey: ['rh', 'produtividade', 'itens'], queryFn: () => obter<ItemProdutividade[]>('/rh/produtividade/itens') });
  const infotipos = useInfotipos();
  const [edicao, setEdicao] = useState<RegistoProdutividade | 'novo' | null>(null);
  const [importar, setImportar] = useState(false);
  const [form] = Form.useForm<ValoresRegisto>();
  const accao = useAccaoRh(() => setEdicao(null));
  const colabForm = Form.useWatch('colaborador_id', form);
  // #11: a conta por extenso de cada colaborador e item (o mínimo e o tecto aplicam-se ao total do período)
  const explicacao = useQuery({
    queryKey: ['rh', 'produtividade', 'periodo', String(id), 'explicacao'],
    queryFn: () => obter<ExplicacaoProdutividade[]>(`/rh/produtividade/periodos/${id}/explicacao`),
    enabled: separador === 'resumo',
  });
  const p = q.data;
  if (!p) return <Card loading={q.isLoading}>{!q.isLoading && <Alert type="error" message="Período não encontrado." />}</Card>;

  const aberto = p.estado !== 'FECHADO';
  const registar = aberto && pode('rh_prod_registar');
  const nomeColab = (cid: number) => p.elegiveis.find((e) => e.colaborador_id === cid)?.nome ?? `#${cid}`;
  const item = (iid: number) => itens.data?.find((i) => i.id === iid);
  const itensDoColab = p.elegiveis.find((e) => e.colaborador_id === colabForm)?.itens ?? {};
  const total = somar(p.registos.map((r) => r.valor));
  // vista Linhas de «Novo registo»: o item tem de estar no contrato do colaborador da linha
  const camposLinhas: CampoFormulario[] = [
    { nome: 'colaborador_id', rotulo: 'Colaborador (elegível)', tipo: 'select', obrigatorio: true, largura: 240, opcoes: p.elegiveis.map((e) => ({ value: e.colaborador_id, label: e.nome })), aliases: ['Colaborador', 'Nome'] },
    { nome: 'item_produtividade_id', rotulo: 'Item do contrato', tipo: 'select', obrigatorio: true, largura: 220, aliases: ['Item', 'Código'],
      opcoes: (itens.data ?? []).map((i) => ({ value: i.id, label: `${i.codigo} - ${i.descricao}` })),
      validar: (v, r) => (v && r.colaborador_id && !Object.values(p.elegiveis.find((e) => e.colaborador_id === r.colaborador_id)?.itens ?? {}).some((i) => i.item_id === v)
        ? 'O item não está no contrato deste colaborador.' : null) },
    { nome: 'quantidade', rotulo: 'Quantidade', tipo: 'numero', obrigatorio: true, largura: 110, min: 0 },
    { nome: 'data', rotulo: 'Data', tipo: 'data', largura: 140,
      validar: (d) => (d && (String(d) < p.data_inicio.slice(0, 10) || String(d) > p.data_fim.slice(0, 10)) ? `A data tem de estar na janela de medição (${formatarData(p.data_inicio)} a ${formatarData(p.data_fim)}).` : null) },
    { nome: 'observacoes', rotulo: 'Observações', largura: 220, maxLength: 1000, emLinhas: 'opcional' },
  ];

  const colunas: ColunaApi<RegistoProdutividade>[] = [
    { title: 'Data', dataIndex: 'data', render: formatarData },
    { title: 'Colaborador', dataIndex: 'colaborador_id', render: nomeColab, valorFiltro: (r) => nomeColab(r.colaborador_id) },
    { title: 'Item', dataIndex: 'item_produtividade_id', render: (v: number) => (item(v) ? `${item(v)?.codigo} — ${item(v)?.descricao}` : `#${v}`) },
    { title: 'Quantidade', dataIndex: 'quantidade', align: 'right', render: formatarNumero },
    { title: 'Considerada', dataIndex: 'quantidade_considerada', align: 'right', render: (v: string | null, r) => (v !== null && Number(v) !== Number(r.quantidade) ? <Typography.Text type="warning">{formatarNumero(v)}</Typography.Text> : formatarNumero(v)) },
    { title: 'Preço unit.', dataIndex: 'preco_unitario', align: 'right', render: (v: string) => formatarKz(v) },
    { title: 'Valor', dataIndex: 'valor', align: 'right', render: (v: string) => formatarKz(v), total: true, totalImpressao: (ls) => formatarKz(somar(ls.map((r) => r.valor))) },
    { title: 'Origem', dataIndex: 'origem', render: (o: string | null) => (o ? <Tag>{o}</Tag> : '—') },
  ];

  const resumo = resumoProdutividade(p, (iid) => item(iid));
  const colResumo: ColunaApi<LinhaResumoProdutividade>[] = [
    { title: 'Colaborador', dataIndex: 'nome', render: (v: string) => <strong>{v}</strong> },
    { title: 'Itens (quantidade → valor)', key: 'itens', valorImpressao: (l) => l.itens.map((i) => `${i.rotulo}: ${formatarNumero(i.quantidade)} → ${formatarKz(i.valor)}`).join('; '),
      render: (_, l) => l.itens.map((i) => <div key={i.rotulo}>{i.rotulo}: {formatarNumero(i.quantidade)} → {formatarKz(i.valor)}</div>) },
    { title: 'Rubrica(s)', key: 'rubricas', valorImpressao: (l) => l.rubricas.map((r) => infotipos.nome(r)).join(', '), render: (_, l) => l.rubricas.map((r) => <div key={r}>{infotipos.nome(r)}</div>) },
    { title: 'Como foi calculado', key: 'explicacao',
      valorImpressao: (l) => (explicacao.data ?? []).filter((x) => x.colaborador_id === l.colaborador_id).map((x) => `${x.codigo}: ${x.conta}${x.cortes.length ? ` (${x.cortes.join('; ')})` : ''}`).join('; '),
      render: (_, l) => (explicacao.data ?? []).filter((x) => x.colaborador_id === l.colaborador_id).map((x) => (
        <div key={x.item_produtividade_id} style={{ fontSize: 12 }}>
          <Typography.Text type="secondary">{x.codigo} · {x.formula}</Typography.Text><br />{x.conta}
          {x.cortes.map((c) => <div key={c}><Typography.Text type="warning">{c}</Typography.Text></div>)}
          {x.precos_diferentes && <div><Typography.Text type="secondary">Preços diferentes entre registos: vale o valor gravado ({formatarKz(x.valor_gravado)}).</Typography.Text></div>}
        </div>
      )) },
    { title: 'Registos', dataIndex: 'registos', align: 'right' },
    { title: 'Total', dataIndex: 'total', align: 'right', render: (v: string) => <strong>{formatarKz(v)}</strong>, total: true },
  ];

  return (
    <>
      {separador === 'registos' && (
        <Card title={<Space><IconeFa nome="list" />Registos de {mesPorExtenso(p.mes)} ({p.registos.length})</Space>}
          extra={registar && (
            <Space wrap>
              <Button icon={<ImportOutlined />} onClick={() => setImportar(true)}>Importar Excel</Button>
              <Button type="primary" icon={<PlusOutlined />} onClick={() => { form.resetFields(); setEdicao('novo'); }}>Registo</Button>
            </Space>
          )}>
          {!p.elegiveis.length && <Alert type="warning" showIcon style={{ marginBottom: 12 }} message="Nenhum colaborador tem itens de produtividade no contrato activo. Configure os itens (separador «Itens de produtividade») e acrescente-os aos contratos em RH › Contratos." />}
          <Typography.Paragraph type="secondary">Medição de {formatarData(p.data_inicio)} a {formatarData(p.data_fim)}. O mínimo e o máximo do item aplicam-se ao total do colaborador no período; a quantidade considerada reparte-se pelos registos.</Typography.Paragraph>
          <TabelaComModos<RegistoProdutividade> idVista="registos" rowKey="id" size="small" columns={colunas} dataSource={p.registos} scroll={scrollTabela()} modos={false}
            impressao={{ titulo: 'Registos de produtividade', periodo: `${mesPorExtenso(p.mes)} (janela ${formatarData(p.data_inicio)} a ${formatarData(p.data_fim)})`, filtros: [`Estado: ${p.estado}`, `Elegíveis: ${p.elegiveis.length}`] }}
            vazio={{ icone: 'chart-line', titulo: 'Sem registos neste período' }}
            accoes={(r) => [
              registar && { icone: 'edit', titulo: 'Editar', onClick: () => { form.setFieldsValue({ colaborador_id: r.colaborador_id, item_produtividade_id: r.item_produtividade_id, quantidade: Number(r.quantidade), data: r.data ? dayjs(r.data) : null, observacoes: r.observacoes ?? undefined }); setEdicao(r); } },
              registar && { icone: 'trash', titulo: 'Eliminar', variante: 'perigo', confirmar: 'Eliminar o registo?', onClick: () => accao.mutateAsync({ metodo: 'delete', url: `/rh/produtividade/periodos/${id}/registos/${r.id}` }) },
            ]} />
        </Card>
      )}

      {separador === 'resumo' && (
        <>
          <GrelhaKpi larguraMinima={150}>
            <CartaoKpi barra="nenhuma" rotulo="Colaboradores" valor={resumo.length} />
            <CartaoKpi barra="nenhuma" rotulo="Registos" valor={p.registos.length} />
            <CartaoKpi barra="nenhuma" rotulo="Total a pagar" valor={formatarKz(total)} unidade="Kz" />
            <CartaoKpi barra="nenhuma" rotulo="Medição" valor={`${formatarData(p.data_inicio)} a ${formatarData(p.data_fim)}`} />
          </GrelhaKpi>
          <Card style={{ marginTop: 12 }} title={`Produtividade de ${mesPorExtenso(p.mes)}`}>
            <Descriptions size="small" column={{ xs: 1, md: 3 }} style={{ marginBottom: 8 }}>
              <Descriptions.Item label="Total no fecho">{p.total_fecho ? formatarKz(p.total_fecho, true) : '—'}</Descriptions.Item>
              {p.lancado_em && <Descriptions.Item label="Lançado no processamento">{formatarDataHora(p.lancado_em)}</Descriptions.Item>}
              {p.motivo_reabertura && <Descriptions.Item label="Motivo da reabertura">{p.motivo_reabertura}</Descriptions.Item>}
            </Descriptions>
            <TabelaComModos<LinhaResumoProdutividade> idVista="resumo-produtividade" rowKey="colaborador_id" size="small" columns={colResumo} dataSource={resumo} scroll={scrollTabela()} modos={false}
              impressao={{ titulo: `Subsídio de produtividade — ${mesPorExtenso(p.mes)}`, subtitulo: `Medição de ${formatarData(p.data_inicio)} a ${formatarData(p.data_fim)} · ${p.estado === 'FECHADO' ? 'Fechado' : 'Aberto'}`, orientacao: 'paisagem' }}
              vazio={{ icone: 'calculator', titulo: 'Sem registos neste período' }} />
          </Card>
        </>
      )}

      {separador === 'importar' && (
        <Card title={<Space><IconeFa nome="file-excel" />Importar Excel — {mesPorExtenso(p.mes)}</Space>}>
          <Typography.Paragraph type="secondary">
            Folha «Produtividade»: NIF, código do item, quantidade, data (opcional, dentro da janela {formatarData(p.data_inicio)} a {formatarData(p.data_fim)}) e observações.
            O colaborador tem de ter o item no contrato activo; o mesmo NIF + item + data nunca se duplica.
          </Typography.Paragraph>
          {registar
            ? <Button type="primary" icon={<ImportOutlined />} onClick={() => setImportar(true)}>Importar Excel</Button>
            : <Alert type="info" showIcon message={aberto ? 'Sem permissão para registar produtividade.' : 'Período fechado: reabra-o para importar.'} />}
        </Card>
      )}

      {separador === 'elegiveis' && <Elegiveis periodo={p} />}

      <ImportarExcel aberto={importar} titulo={`Importar produtividade — ${mesPorExtenso(p.mes)}`} url={`/rh/produtividade/periodos/${id}/importar`} modelo="produtividade" comDecisao="IGNORAR"
        ajuda="Folha «Produtividade»: NIF, código do item, quantidade, data (opcional, dentro da janela) e observações. O colaborador tem de ter o item no contrato activo; o mesmo NIF + item + data nunca se duplica."
        aoFechar={() => setImportar(false)} />
      <ModalFormulario chave="rh.produtividade.registos" titulo={edicao === 'novo' ? 'Novo registo' : 'Editar registo'} aberto={edicao !== null} aoFechar={() => setEdicao(null)}
        criacao={edicao === 'novo'} form={form} campos={camposLinhas} nomeRegistos="registos de produtividade" invalidar={[['rh', 'produtividade']]}
        gravarLinha={(r) => enviar('post', `/rh/produtividade/periodos/${id}/registos`, dadosRegistoProdutividade(r))}
        okText="Gravar" cancelText="Cancelar" confirmLoading={accao.isPending} onOk={() => form.submit()}>
        <Form form={form} layout="vertical" onFinish={(v) => {
          const dados = dadosRegistoProdutividade(v as unknown as Record<string, unknown>);
          if (edicao === 'novo') accao.mutate({ metodo: 'post', url: `/rh/produtividade/periodos/${id}/registos`, dados });
          else if (edicao) accao.mutate({ metodo: 'put', url: `/rh/produtividade/periodos/${id}/registos/${edicao.id}`, dados });
        }}>
          <Form.Item name="colaborador_id" label="Colaborador (elegível)" rules={[{ required: true }]}>
            <Select showSearch optionFilterProp="label" onChange={() => form.setFieldValue('item_produtividade_id', undefined)} options={p.elegiveis.map((e) => ({ value: e.colaborador_id, label: e.nome }))} />
          </Form.Item>
          <Form.Item name="item_produtividade_id" label="Item do contrato" rules={[{ required: true }]}>
            <Select options={Object.values(itensDoColab).map((i) => ({ value: i.item_id, label: `${i.codigo} (${formatarKz(i.preco)})` }))} />
          </Form.Item>
          <Row gutter={12}>
            <Col xs={24} md={12}><Form.Item name="quantidade" label="Quantidade" rules={[{ required: true }]}><InputNumber min={0} style={{ width: '100%' }} /></Form.Item></Col>
            <Col xs={24} md={12}><Form.Item name="data" label="Data"><DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} disabledDate={(d) => d.isBefore(dayjs(p.data_inicio)) || d.isAfter(dayjs(p.data_fim)) || d.isAfter(dayjs())} /></Form.Item></Col>
          </Row>
          <Form.Item name="observacoes" label="Observações"><Input.TextArea rows={2} maxLength={1000} /></Form.Item>
        </Form>
      </ModalFormulario>
    </>
  );
}

/** «Colaboradores elegíveis»: com itens de produtividade no contrato activo (só estes podem ter subsídio). */
function Elegiveis({ periodo }: { periodo: DetalheProdutividade }) {
  type Linha = DetalheProdutividade['elegiveis'][number];
  const colunas: ColunaApi<Linha>[] = [
    { title: 'Colaborador', dataIndex: 'nome', render: (v: string) => <strong>{v}</strong> },
    {
      title: 'Itens (preço aplicado)', key: 'itens',
      valorImpressao: (e) => Object.values(e.itens).map((i) => `${i.codigo} — ${formatarKz(i.preco)}`).join('; '),
      render: (_, e) => Object.values(e.itens).map((i) => <div key={i.item_id}>{i.codigo} — {formatarKz(i.preco)}</div>),
    },
  ];
  return (
    <Card title={<Space><IconeFa nome="user-check" />Com itens de produtividade no contrato ({periodo.elegiveis.length})</Space>}>
      <Alert type="info" showIcon style={{ marginBottom: 12 }} message="Só estes colaboradores podem ter registos de produtividade. Para incluir outros, edite o contrato em RH › Contratos e marque os itens." />
      <TabelaComModos<Linha> idVista="elegiveis" rowKey="colaborador_id" size="small" columns={colunas} dataSource={periodo.elegiveis} modos={false}
        impressao={{ titulo: 'Colaboradores elegíveis (produtividade)' }}
        vazio={{ icone: 'user-check', titulo: 'Nenhum colaborador tem itens de produtividade no contrato.' }} />
    </Card>
  );
}
