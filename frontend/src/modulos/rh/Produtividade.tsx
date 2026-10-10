import { ImportarExcel } from './comum/ImportarExcel';
import { Alert, Button, Card, Checkbox, Col, DatePicker, Descriptions, Form, Input, InputNumber, Modal, Row, Select, Space, Tabs, Tag, Typography } from 'antd';
import { ImportOutlined, LockOutlined, PlusOutlined, UnlockOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import dayjs, { type Dayjs } from 'dayjs';
import { useState } from 'react';
import { Route, Routes, useNavigate, useParams } from 'react-router-dom';
import { obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
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
import { periodoActualProdutividade, resumoProdutividade, type LinhaResumoProdutividade } from './produtividade/regrasProdutividade';

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
  ['periodos', 'Períodos', 'list'],
] as const;
type Separador = (typeof SEPARADORES)[number][0];

function EcraProdutividade() {
  const { id } = useParams();
  const navegar = useNavigate();
  const { pode } = useSessao();
  const periodos = useQuery({ queryKey: ['rh', 'produtividade', 'periodos'], queryFn: () => obter<PeriodoProdutividade[]>('/rh/produtividade/periodos') });
  useAvisarErro(periodos.error);
  const [sep, setSep] = useState<Separador>('registos');
  const [novo, setNovo] = useState(false);
  const periodoId = id ? Number(id) : periodoActualProdutividade(periodos.data ?? [])?.id;
  const irPara = (pid: number) => navegar(id ? `../${pid}` : String(pid), { relative: 'path' });

  return (
    <>
      <CabecalhoPagina
        titulo={<Space><IconeFa nome="chart-line" />Subsídio de produtividade</Space>}
        subtitulo="Produtividade medida por quantidade, horas, objectivo, pontos ou tarefas; valor = quantidade × preço unitário. Só colaboradores com itens de produtividade no contrato. O período fechado é importado no cálculo salarial."
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
      {sep === 'periodos' && <ListaPeriodos aoAbrir={(pid) => { irPara(pid); setSep('registos'); }} />}
      {sep !== 'itens' && sep !== 'periodos' && (periodoId === undefined
        ? <Card loading={periodos.isLoading}>{!periodos.isLoading && <Alert type="info" showIcon message="Crie primeiro o período de cálculo da produtividade (botão «Novo período»)." />}</Card>
        : <PainelPeriodo id={periodoId} separador={sep} />)}
      <NovoPeriodo aberto={novo} aoFechar={() => setNovo(false)} aoCriar={(p) => { setNovo(false); irPara(p.id); setSep('registos'); }} />
    </>
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
  return (
    <CadastroSimples<ItemProdutividade>
      url="/rh/produtividade/itens"
      chave={['rh', 'produtividade', 'itens']}
      nomeItem="item de produtividade"
      tituloImpressao="Itens de produtividade"
      podeGerir={pode('rh_prod_config')}
      podeEliminar={pode('rh_prod_config')}
      pesquisa={(r) => `${r.codigo} ${r.descricao}`}
      valoresNovos={{ metrica: 'QUANTIDADE', ativo: true }}
      paraFormulario={(r) => ({ ...r, preco_unitario: Number(r.preco_unitario), minimo: r.minimo !== null ? Number(r.minimo) : null, maximo: r.maximo !== null ? Number(r.maximo) : null })}
      colunas={[
        { title: 'Código', dataIndex: 'codigo', render: (v: string) => <strong>{v}</strong> },
        { title: 'Descrição', dataIndex: 'descricao' },
        { title: 'Métrica', dataIndex: 'metrica' },
        { title: 'Unidade', dataIndex: 'unidade', render: (v: string | null) => v ?? '—' },
        { title: 'Preço unitário', dataIndex: 'preco_unitario', align: 'right', render: (v: string) => formatarKz(v) },
        { title: 'Mín./máx. (total)', render: (_, r) => `${r.minimo !== null ? formatarNumero(r.minimo) : '—'} / ${r.maximo !== null ? formatarNumero(r.maximo) : '—'}` },
        { title: 'Rubrica', dataIndex: 'infotipo_salarial_id', render: (v: number) => infotipos.nome(v) },
        { title: 'Activo', dataIndex: 'ativo', render: (v: boolean) => (v ? <Tag color="green">Sim</Tag> : <Tag>Não</Tag>) },
      ]}
      campos={
        <Row gutter={12}>
          <Col xs={24} sm={12} md={8}><Form.Item name="codigo" label="Código" rules={[{ required: true }, { max: 20 }]}><Input /></Form.Item></Col>
          <Col xs={24} md={16}><Form.Item name="descricao" label="Descrição" rules={[{ required: true }, { max: 500 }]}><Input /></Form.Item></Col>
          <Col xs={24} sm={12} md={8}><Form.Item name="metrica" label="Métrica"><Select options={METRICAS_PRODUTIVIDADE.map((m) => ({ value: m, label: m }))} /></Form.Item></Col>
          <Col xs={24} sm={12} md={8}><Form.Item name="unidade" label="Unidade"><Input maxLength={10} /></Form.Item></Col>
          <Col xs={24} sm={12} md={8}><Form.Item name="preco_unitario" label="Preço unitário" rules={[{ required: true }]}><InputNumber min={0.0001} precision={4} decimalSeparator="," style={{ width: '100%' }} /></Form.Item></Col>
          <Col xs={24} sm={12} md={8}><Form.Item name="minimo" label="Mínimo (total)"><InputNumber min={0} style={{ width: '100%' }} /></Form.Item></Col>
          <Col xs={24} sm={12} md={8}><Form.Item name="maximo" label="Máximo (total)"><InputNumber min={0} style={{ width: '100%' }} /></Form.Item></Col>
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
  const p = q.data;
  if (!p) return <Card loading={q.isLoading}>{!q.isLoading && <Alert type="error" message="Período não encontrado." />}</Card>;

  const aberto = p.estado !== 'FECHADO';
  const registar = aberto && pode('rh_prod_registar');
  const nomeColab = (cid: number) => p.elegiveis.find((e) => e.colaborador_id === cid)?.nome ?? `#${cid}`;
  const item = (iid: number) => itens.data?.find((i) => i.id === iid);
  const itensDoColab = p.elegiveis.find((e) => e.colaborador_id === colabForm)?.itens ?? {};
  const total = somar(p.registos.map((r) => r.valor));

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
          <GrelhaKpi larguraMinima={180}>
            <CartaoKpi rotulo="Colaboradores" valor={resumo.length} icone="users" cor="azul" />
            <CartaoKpi rotulo="Registos" valor={p.registos.length} icone="list" cor="cinzento" />
            <CartaoKpi rotulo="Total a pagar" valor={formatarKz(total)} unidade="Kz" icone="coins" cor="verde" />
            <CartaoKpi rotulo="Medição" valor={`${formatarData(p.data_inicio)} a ${formatarData(p.data_fim)}`} icone="calendar-alt" cor="roxo" />
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
      <Modal title={edicao === 'novo' ? 'Novo registo' : 'Editar registo'} open={edicao !== null} onCancel={() => setEdicao(null)} okText="Gravar" cancelText="Cancelar" confirmLoading={accao.isPending} onOk={() => form.submit()} destroyOnHidden>
        <Form form={form} layout="vertical" onFinish={(v) => {
          const dados = { ...v, data: dataApi(v.data ?? null) ?? null, observacoes: v.observacoes ?? null };
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
      </Modal>
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
