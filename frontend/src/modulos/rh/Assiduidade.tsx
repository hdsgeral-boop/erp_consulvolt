import {
  Alert, Button, Card, Checkbox, Col, DatePicker, Descriptions, Flex, Form, Input, InputNumber, Modal, Popconfirm, Row, Select, Space, Tabs, Tag, TimePicker, Tooltip, Typography, Upload, message,
} from 'antd';
import { CalendarOutlined, ClockCircleOutlined, DeleteOutlined, LockOutlined, PlusOutlined, SearchOutlined, UnlockOutlined, UploadOutlined, WarningOutlined } from '@ant-design/icons';
import type { UploadFile } from 'antd/es/upload/interface';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import dayjs, { type Dayjs } from 'dayjs';
import { useEffect, useMemo, useState, type ReactNode } from 'react';
import { descarregar, enviar, http, obter } from '@/api/cliente';
import type { Envelope } from '@/api/tipos';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { ModalFormulario, type CampoFormulario } from '@/componentes/formularios';
import { IconeFa } from '@/componentes/fluxos';
import { BotoesExportar } from '@/componentes/impressao';
import type { ColunaApi } from '@/componentes/TabelaApi';
import { useSessao } from '@/sessao/SessaoContexto';
import { notificarErro } from '@/utilitarios/erros';
import { formatarData, formatarDataHora } from '@/utilitarios/formatacao';
import {
  ARREDONDAMENTOS, DIAS_SEMANA, type ApuramentoMes, type Ausencia, type ConfigAssiduidade, type FechoMensal, type FeriadoNacional, type LinhaApuramento, type RegistoEfectividade, type TipoAusencia,
} from './api';
import { EstadoTag, SeletorColaborador } from './comum/componentes';
import { useAccaoRh, useAvisarErro, useColaboradores } from './comum/consultas';
import { formatarHoras, horasEntre, mesPorExtenso } from './comum/regras';
import { EscalaMes } from './assiduidade/EscalaMes';
import { normalizarLinhaApuramento, normalizarTotais } from './faseB/regras';
import { BarraFiltros, larguraModal, scrollTabela } from '@/componentes/responsivo';
import { pedidoTabela } from './comum/impressao';

import { CartaoKpi, GrelhaKpi, TabelaComModos } from '@/componentes/vistas';
import { campoHora, campoSimNao, dataDe, horaDe, numeroDe, opcoesColaboradores, propsHora, textoDe } from './comum/linhasRh';
import { useSeparadorUrl } from '@/componentes/navegacao/useSeparadorUrl';

/** Cores dos valores dos indicadores do antigo (assiduidade_ui.js, .ef-kpi strong). */
const COR_EXTRA = '#166534';
const COR_ALERTA = '#b45309';
const COR_FALTA = '#b91c1c';

/** RH › Efectividade (ecrã rh_assiduidade): registos, importação, apuramento e fecho do mês, ausências e configuração. */
export default function Assiduidade() {
  const [mes, setMes] = useState<Dayjs>(dayjs().startOf('month'));
  const m = mes.format('YYYY-MM');
  // antigo (assiduidade_ui.js:100, :485, :566, :659): o mês escolhe-se dentro de cada separador; o estado é partilhado
  const seletor = (rotulo = 'Mês') => <SeletorMes rotulo={rotulo} valor={mes} aoMudar={setMes} />;
  const [sep, setSep] = useSeparadorUrl(['apuramento', 'escala', 'registos', 'importar', 'ausencias', 'fechos', 'config']);
  // Ordem e nomes dos separadores do antigo (abre no «Resumo do mês»); «Fechos mensais» é um extra do sistema novo.
  return (
    <>
      <CabecalhoPagina
        titulo="Efectividade (biometria)"
        subtitulo="Dias e horas trabalhados: registo manual, importação de ficheiro do relógio biométrico ou ligação directa. Base do apuramento de faltas e horas extra no processamento salarial."
      />
      <Tabs
        type="card"
        activeKey={sep}
        onChange={setSep}
        items={[
          { key: 'apuramento', label: <span><IconeFa nome="calculator" /> Resumo do mês</span>, children: <Apuramento mes={m} seletor={seletor()} /> },
          // Versão Actual (assiduidade_ui.js:58 e :82): turnos por colaborador e dia, escala prioritária, migrar do contrato, copiar, Excel
          { key: 'escala', label: <span><IconeFa nome="calendar-alt" /> Escala do mês</span>, children: <EscalaMes mes={m} seletor={seletor('Mês da escala')} /> },
          { key: 'registos', label: <span><IconeFa nome="clock" /> Registos</span>, children: <Registos mes={m} seletor={seletor()} /> },
          { key: 'importar', label: <span><IconeFa nome="file-import" /> Importar</span>, children: <Registos mes={m} soImportar seletor={seletor('Mês do modelo')} /> },
          { key: 'ausencias', label: <span><IconeFa nome="user-clock" /> Ausências justificadas</span>, children: <Ausencias mes={m} seletor={seletor()} /> },
          { key: 'fechos', label: 'Fechos mensais', children: <Fechos /> },
          { key: 'config', label: <span><IconeFa nome="sliders-h" /> Configuração</span>, children: <Configuracao /> },
        ]}
      />
    </>
  );
}

// ───────────── Registos ─────────────

interface ValoresRegisto {
  colaborador_id: number;
  data: Dayjs;
  /** 'HH:mm' (o TimePicker guarda texto: o mesmo valor da vista Linhas). */
  entrada?: string | null;
  saida?: string | null;
  saida_intervalo?: string | null;
  entrada_intervalo?: string | null;
  horas?: number | null;
  observacoes?: string;
  autorizado_extra?: boolean;
}

/** Corpo do POST /rh/assiduidade/registos — o mesmo na grade e em cada linha. */
export function dadosRegistoEfectividade(v: Record<string, unknown>) {
  return {
    colaborador_id: v.colaborador_id,
    data: dataDe(v.data),
    entrada: horaDe(v.entrada),
    saida: horaDe(v.saida),
    saida_intervalo: horaDe(v.saida_intervalo),
    entrada_intervalo: horaDe(v.entrada_intervalo),
    horas: numeroDe(v.horas),
    observacoes: textoDe(v.observacoes),
    autorizado_extra: v.autorizado_extra ? true : null,
  };
}

/** As duas picagens do intervalo vão juntas (regime de dois períodos). */
const validarIntervalo = (_: unknown, r: Record<string, unknown>) => (!r.saida_intervalo === !r.entrada_intervalo ? null : 'Indique as duas picagens do intervalo.');

/** Colunas da vista Linhas do «Registo de efectividade» (um registo por colaborador e dia). */
export function camposRegistoEfectividade(colaboradores: CampoFormulario['opcoes']): CampoFormulario[] {
  return [
    { nome: 'colaborador_id', rotulo: 'Colaborador', tipo: 'select', obrigatorio: true, largura: 240, opcoes: colaboradores, aliases: ['Nome', 'NIF', 'Funcionário'] },
    { nome: 'data', rotulo: 'Data', tipo: 'data', obrigatorio: true, largura: 140 },
    campoHora('entrada', 'Entrada'),
    campoHora('saida', 'Saída', { aliases: ['Saida'] }),
    campoHora('saida_intervalo', 'Saída para o intervalo', { emLinhas: 'opcional', seccao: 'Intervalo', aliases: ['Saída intervalo', 'Saída almoço'], validar: validarIntervalo }),
    campoHora('entrada_intervalo', 'Regresso do intervalo', { emLinhas: 'opcional', seccao: 'Intervalo', aliases: ['Entrada intervalo', 'Regresso almoço'] }),
    { nome: 'horas', rotulo: 'Horas', tipo: 'numero', largura: 90, min: 0, max: 24, precisao: 2,
      validar: (h, r) => (!r.entrada && !r.saida && (h === null || h === undefined || h === '') ? 'Indique a entrada/saída ou as horas.' : null) },
    campoSimNao('autorizado_extra', 'Extra autorizado', { emLinhas: 'opcional', aliases: ['Autorizado'] }),
    { nome: 'observacoes', rotulo: 'Observações', largura: 200, maxLength: 1000, emLinhas: 'opcional', aliases: ['Observacoes'] },
  ];
}

/** Mês da efectividade dentro de cada separador (antigo: `<label>Mês</label><input type="month">`). */
export function SeletorMes({ rotulo, valor, aoMudar }: { rotulo: string; valor: Dayjs; aoMudar: (v: Dayjs) => void }) {
  return (
    <Space size={6} style={{ fontWeight: 400 }}>
      <Typography.Text strong style={{ fontSize: 13 }}>{rotulo}</Typography.Text>
      <DatePicker picker="month" format="MM/YYYY" allowClear={false} value={valor} onChange={(v) => v && aoMudar(v.startOf('month'))} aria-label={rotulo} style={{ width: 130 }} />
    </Space>
  );
}

function Registos({ mes, soImportar, seletor }: { mes: string; soImportar?: boolean; seletor?: ReactNode }) {
  const { pode } = useSessao();
  const cliente = useQueryClient();
  const colaboradores = useColaboradores();
  const [colaborador, setColaborador] = useState<number>();
  const [novo, setNovo] = useState(false);
  const [importar, setImportar] = useState(false);
  const [ficheiros, setFicheiros] = useState<UploadFile[]>([]);
  const [substituir, setSubstituir] = useState(true);
  const [resultado, setResultado] = useState<ResultadoImportacaoEfectividade | null>(null);
  const [aDescarregar, setADescarregar] = useState(false);
  /** Modelo Excel pré-preenchido do mês (uma linha por colaborador activo e dia de trabalho, NIF em texto). */
  const descarregarModelo = async () => {
    setADescarregar(true);
    try {
      await descarregar('/rh/assiduidade/modelo', { mes }, `Modelo_Efectividade_${mes}.xlsx`);
    } catch (e) {
      notificarErro(e, 'Não foi possível descarregar o modelo');
    } finally {
      setADescarregar(false);
    }
  };
  const [form] = Form.useForm<ValoresRegisto>();
  const registos = useQuery({ queryKey: ['rh', 'assiduidade', 'registos', mes, colaborador], queryFn: () => obter<RegistoEfectividade[]>('/rh/assiduidade/registos', { mes, colaborador_id: colaborador }) });
  useAvisarErro(registos.error);
  const accao = useAccaoRh(() => setNovo(false));
  const entrada = Form.useWatch('entrada', form);
  const saida = Form.useWatch('saida', form);
  const calculadas = horasEntre(entrada, saida);
  const camposLinhas = useMemo(() => camposRegistoEfectividade(opcoesColaboradores(colaboradores.lista, true)), [colaboradores.lista]);

  /** M-12: o servidor lê o relógio biométrico configurado (CSV ou JSON) com o mesmo leitor da importação de ficheiro. */
  const relogio = useMutation({
    mutationFn: async () => (await http.post<Envelope<{ gravados: number; ignorados: number; erros: string[] }>>('/rh/assiduidade/registos/importar-relogio', { substituir })).data,
    onSuccess: (r) => {
      message.success(r.mensagem);
      setResultado(r.dados);
      void cliente.invalidateQueries({ queryKey: ['rh', 'assiduidade'] });
    },
    onError: (e) => notificarErro(e, 'Não foi possível ler o relógio biométrico'),
  });

  const envioFicheiro = useMutation({
    mutationFn: async () => {
      const fd = new FormData();
      fd.append('ficheiro', ficheiros[0].originFileObj as File);
      fd.append('substituir', substituir ? '1' : '0');
      const r = await http.post<Envelope<ResultadoImportacaoEfectividade>>('/rh/assiduidade/registos/importar', fd);
      return r.data;
    },
    onSuccess: (r) => {
      message.success(r.mensagem);
      setResultado(r.dados);
      setImportar(false);
      setFicheiros([]);
      void cliente.invalidateQueries({ queryKey: ['rh', 'assiduidade'] });
    },
    onError: (e) => notificarErro(e, 'Não foi possível importar o ficheiro'),
  });

  const colunas: ColunaApi<RegistoEfectividade>[] = [
    { title: 'Data', dataIndex: 'data', render: (v: string) => `${formatarData(v)} (${DIAS_SEMANA[dayjs(v).day()].slice(0, 3)})` },
    { title: 'Colaborador', dataIndex: 'colaborador_id', render: (v: number) => colaboradores.nome(v) },
    { title: 'Entrada', dataIndex: 'entrada', render: (v: string | null) => v?.slice(0, 5) ?? '—' },
    { title: 'Saída', dataIndex: 'saida', render: (v: string | null) => v?.slice(0, 5) ?? '—' },
    { title: 'Intervalo', key: 'intervalo', responsive: ['md'], render: (_, r) => (r.saida_intervalo ? `${r.saida_intervalo.slice(0, 5)}–${r.entrada_intervalo?.slice(0, 5) ?? ''}` : '—') },
    { title: 'Horas', dataIndex: 'horas', align: 'right', render: formatarHoras },
    { title: 'Origem', dataIndex: 'origem', responsive: ['md'], render: (o: string | null, r) => (o ? <Tooltip title={r.fonte}><Tag>{o}</Tag></Tooltip> : '—') },
    { title: 'Extra autorizado', dataIndex: 'autorizado_extra', align: 'center', responsive: ['md'], render: (v: boolean | null) => (v ? 'Sim' : '') },
    { title: 'Observações', dataIndex: 'observacoes', responsive: ['lg'], ellipsis: true, render: (v: string | null) => v ?? '' },
    {
      title: '',
      key: 'accoes',
      render: (_, r) => pode('rh_assid_registar') && (
        <Popconfirm title="Eliminar o registo?" okText="Eliminar" okButtonProps={{ danger: true }} cancelText="Cancelar"
          onConfirm={() => accao.mutateAsync({ metodo: 'delete', url: `/rh/assiduidade/registos/${r.id}` })}>
          <Button size="small" type="text" danger icon={<DeleteOutlined />} aria-label="Eliminar" />
        </Popconfirm>
      ),
    },
  ];

  const modais = (
    <>
        <Modal title="Importar efectividade (CSV / XLSX)" open={importar} onCancel={() => setImportar(false)} okText="Importar" cancelText="Cancelar"
          okButtonProps={{ disabled: ficheiros.length === 0 }} confirmLoading={envioFicheiro.isPending} onOk={() => envioFicheiro.mutate()}>
          <Typography.Paragraph type="secondary">
            Colunas reconhecidas pelo cabeçalho: <code>data</code> e uma identificação (<code>nif</code>, <code>inss</code>, <code>numero</code> ou <code>nome</code>); opcionais <code>entrada</code>, <code>saida</code>,
            <code>saida intervalo</code> e <code>entrada intervalo</code> (dois períodos; também «saída almoço» / «regresso almoço»), <code>horas</code> (aceita «7,5»), <code>autorizado</code> e <code>observacoes</code>.
            As linhas sem entrada, saída nem horas (do modelo, deixadas em branco) não contam como erro. Máximo 10 MB.
          </Typography.Paragraph>
          <Upload accept=".csv,.txt,.xlsx,.xls" maxCount={1} fileList={ficheiros} beforeUpload={() => false} onChange={({ fileList }) => setFicheiros(fileList.slice(-1))}>
            <Button icon={<UploadOutlined />}>Escolher ficheiro</Button>
          </Upload>
          <Checkbox style={{ marginTop: 12 }} checked={substituir} onChange={(e) => setSubstituir(e.target.checked)}>Substituir os registos existentes do mesmo dia</Checkbox>
        </Modal>
  
        <Modal title="Resultado da importação" open={resultado !== null} onCancel={() => setResultado(null)} footer={<Button type="primary" onClick={() => setResultado(null)}>Fechar</Button>}>
          {resultado && (
            <>
              {/* antigo (assiduidade_ui.js, resultado da importação): .ef-kpis — rótulo em maiúsculas e valor grande, sem ícone */}
              <GrelhaKpi larguraMinima={110} style={{ marginBottom: 12 }}>
                <CartaoKpi barra="nenhuma" rotulo="Gravados" valor={resultado.gravados} corValor={COR_EXTRA} />
                <CartaoKpi barra="nenhuma" rotulo="Ignorados" valor={resultado.ignorados} />
                {resultado.em_branco !== undefined && <CartaoKpi barra="nenhuma" rotulo="Linhas em branco" valor={resultado.em_branco} />}
                <CartaoKpi barra="nenhuma" rotulo="Erros" valor={resultado.erros.length} corValor={resultado.erros.length ? COR_FALTA : COR_EXTRA} />
              </GrelhaKpi>
              {resultado.erros.length > 0 && <ul style={{ maxHeight: 260, overflow: 'auto', paddingLeft: 18 }}>{resultado.erros.map((e, i) => <li key={i}>{e}</li>)}</ul>}
            </>
          )}
        </Modal>
    </>
  );

  if (soImportar) {
    // Separador «Importar» do antigo: ficheiro do relógio (CSV/XLSX) ou leitura directa do relógio configurado.
    return (
      <Card title={<Space wrap><Space><IconeFa nome="file-import" />Importar registos de {mesPorExtenso(mes)}</Space>{seletor}</Space>}>
        <Typography.Paragraph type="secondary">
          Colunas reconhecidas pelo cabeçalho: <code>data</code> e uma identificação (<code>nif</code>, <code>inss</code>, <code>numero</code> ou <code>nome</code>); opcionais <code>entrada</code>, <code>saida</code>,
            <code>saida intervalo</code> e <code>entrada intervalo</code> (dois períodos; também «saída almoço» / «regresso almoço»), <code>horas</code> (aceita «7,5»), <code>autorizado</code> e <code>observacoes</code>.
            As linhas sem entrada, saída nem horas (do modelo, deixadas em branco) não contam como erro. Máximo 10 MB.
          Comece por «Descarregar o modelo»: traz uma linha por colaborador activo e dia de trabalho, com o NIF e o horário previsto. Depois de importar, confira o «Resumo do mês» e feche a efectividade.
        </Typography.Paragraph>
        {pode('rh_assid_registar') ? (
          <Space wrap>
            <Button type="primary" icon={<UploadOutlined />} onClick={() => setImportar(true)}>Importar ficheiro</Button>
            {/* #4 (Versão Actual): modelo Excel já preenchido com os colaboradores activos e os dias de trabalho do mês */}
            <Button icon={<IconeFa nome="file-excel" />} loading={aDescarregar} onClick={() => void descarregarModelo()}>Descarregar o modelo</Button>
            <Popconfirm title="Ler os registos do relógio biométrico?" description="O servidor lê o endereço configurado; os registos do mesmo dia são substituídos." okText="Ler relógio" cancelText="Cancelar"
              onConfirm={() => relogio.mutateAsync()}>
              <Button icon={<ClockCircleOutlined />} loading={relogio.isPending}>Ler relógio</Button>
            </Popconfirm>
          </Space>
        ) : <Alert type="info" showIcon message="Sem permissão para registar efectividade." />}
        {modais}
      </Card>
    );
  }

  return (
    <Card>
      <BarraFiltros accoes={
        <>
          <BotoesExportar desactivado={!registos.data?.length} obterPedido={() => pedidoTabela({
            titulo: 'Registos de efectividade', periodo: mesPorExtenso(mes), filtros: colaborador ? [`Colaborador: ${colaboradores.nome(colaborador)}`] : undefined, colunas, linhas: registos.data ?? [],
          })} />
          {pode('rh_assid_registar') && (
          <Space wrap>
            <Popconfirm title="Ler os registos do relógio biométrico?" description="O servidor lê o endereço configurado; os registos do mesmo dia são substituídos." okText="Ler relógio" cancelText="Cancelar"
              onConfirm={() => relogio.mutateAsync()}>
              <Button icon={<ClockCircleOutlined />} loading={relogio.isPending}>Ler relógio</Button>
            </Popconfirm>
            <Button icon={<UploadOutlined />} onClick={() => setImportar(true)}>Importar ficheiro</Button>
            <Button type="primary" icon={<PlusOutlined />} onClick={() => { form.resetFields(); form.setFieldsValue({ colaborador_id: colaborador, data: dayjs(`${mes}-01`).isSame(dayjs(), 'month') ? dayjs() : dayjs(`${mes}-01`) }); setNovo(true); }}>Novo registo</Button>
          </Space>
          )}
        </>
      }>
        {seletor}
        <SeletorColaborador value={colaborador} onChange={setColaborador} />
      </BarraFiltros>
      <TabelaComModos<RegistoEfectividade> idVista="registos" rowKey="id" size="small" loading={registos.isFetching} columns={colunas} dataSource={registos.data ?? []} scroll={scrollTabela()}
        pagination={{ pageSize: 50, showSizeChanger: true, showTotal: (t) => `${t} registo(s) em ${mesPorExtenso(mes)}` }} />

      <ModalFormulario chave="rh.efectividade.registos" titulo="Registo de efectividade" aberto={novo} aoFechar={() => setNovo(false)} criacao form={form} campos={camposLinhas}
        nomeRegistos="registos de efectividade" invalidar={[['rh', 'assiduidade']]} gravarLinha={(r) => enviar('post', '/rh/assiduidade/registos', dadosRegistoEfectividade(r))}
        nota="Uma linha por colaborador e dia (gravar substitui o registo existente). Indique entrada/saída ou as horas. Linhas deixadas em branco são ignoradas."
        okText="Gravar" cancelText="Cancelar" confirmLoading={accao.isPending} onOk={() => form.submit()}>
        <Typography.Paragraph type="secondary">Um registo por colaborador e dia: gravar substitui o existente. Indique entrada/saída ou as horas.</Typography.Paragraph>
        <Form form={form} layout="vertical" onFinish={(v) => accao.mutate({ metodo: 'post', url: '/rh/assiduidade/registos', dados: dadosRegistoEfectividade(v as unknown as Record<string, unknown>) })}>
          <Form.Item name="colaborador_id" label="Colaborador" rules={[{ required: true, message: 'Escolha o colaborador.' }]}><SeletorColaborador apenasActivos style={{ width: '100%' }} /></Form.Item>
          <Row gutter={12}>
            <Col xs={24} sm={12} md={8}><Form.Item name="data" label="Data" rules={[{ required: true }]}><DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} /></Form.Item></Col>
            <Col xs={24} sm={12} md={8}><Form.Item name="entrada" label="Entrada" {...propsHora}><TimePicker format="HH:mm" minuteStep={5} style={{ width: '100%' }} /></Form.Item></Col>
            <Col xs={24} sm={12} md={8}><Form.Item name="saida" label="Saída" {...propsHora}><TimePicker format="HH:mm" minuteStep={5} style={{ width: '100%' }} /></Form.Item></Col>
          </Row>
          {/* Versão Actual: regime de dois períodos (4 picagens) — as duas picagens do intervalo vão juntas */}
          <Row gutter={12}>
            <Col xs={24} sm={12}><Form.Item name="saida_intervalo" label="Saída para o intervalo" {...propsHora} dependencies={['entrada_intervalo']} rules={[({ getFieldValue }) => ({ validator: (_, x) => (!x === !getFieldValue('entrada_intervalo') ? Promise.resolve() : Promise.reject(new Error('Indique as duas picagens do intervalo.'))) })]}><TimePicker format="HH:mm" minuteStep={5} style={{ width: '100%' }} /></Form.Item></Col>
            <Col xs={24} sm={12}><Form.Item name="entrada_intervalo" label="Regresso do intervalo" {...propsHora}><TimePicker format="HH:mm" minuteStep={5} style={{ width: '100%' }} /></Form.Item></Col>
          </Row>
          <Form.Item name="horas" label="Horas (sem entrada/saída)" extra={calculadas !== null ? `Entrada → saída: ${formatarHoras(calculadas)}` : undefined}>
            <InputNumber min={0} max={24} step={0.25} style={{ width: '100%' }} />
          </Form.Item>
          <Form.Item name="autorizado_extra" valuePropName="checked"><Checkbox>Trabalho extra autorizado (dia não útil)</Checkbox></Form.Item>
          <Form.Item name="observacoes" label="Observações"><Input.TextArea rows={2} maxLength={1000} /></Form.Item>
        </Form>
      </ModalFormulario>

      {modais}
    </Card>
  );
}

/** Resultado da importação do ficheiro da efectividade (as linhas do modelo deixadas em branco não são erro). */
interface ResultadoImportacaoEfectividade { gravados: number; ignorados: number; em_branco?: number; erros: string[] }

// ───────────── Apuramento ─────────────

/** Horário previsto por baixo do nome: «08:00–17:00 · 8h/dia» (com o intervalo nos dois períodos). */
function textoHorario(l: Pick<LinhaApuramento, 'horario' | 'horasDia'>): string {
  const h = l.horario;
  const hd = l.horasDia ? `${formatarHoras(l.horasDia)}/dia` : '';
  if (!h?.entrada || !h.saida) return hd;
  const dois = h.periodos === 2 && h.saida_intervalo && h.entrada_intervalo;
  return `${h.entrada.slice(0, 5)}–${dois ? `${h.saida_intervalo!.slice(0, 5)} · ${h.entrada_intervalo!.slice(0, 5)}–` : ''}${h.saida.slice(0, 5)}${hd ? ` · ${hd}` : ''}`;
}

function Apuramento({ mes, seletor }: { mes: string; seletor?: ReactNode }) {
  const { pode } = useSessao();
  const q = useQuery({ queryKey: ['rh', 'assiduidade', 'mes', mes], queryFn: () => obter<ApuramentoMes>(`/rh/assiduidade/meses/${mes}`) });
  useAvisarErro(q.error);
  const [reabrir, setReabrir] = useState(false);
  const [form] = Form.useForm<{ motivo: string }>();
  const accao = useAccaoRh(() => setReabrir(false));
  // o servidor devolve snake_case; os fechos migrados do legado guardam as linhas em camelCase
  const a = q.data ? { ...q.data, linhas: (q.data.linhas ?? []).map((l) => normalizarLinhaApuramento(l as unknown as Record<string, unknown>)), totais: normalizarTotais(q.data.totais as unknown as Record<string, unknown>) } : undefined;
  const fechado = a?.estado === 'FECHADO';

  const horas = (v: number) => formatarHoras(v);
  const colunas: ColunaApi<LinhaApuramento>[] = [
    {
      title: 'Colaborador', dataIndex: 'nome', fixed: 'left', valorImpressao: (l) => `${l.nome}${textoHorario(l) ? ` (${textoHorario(l)})` : ''}${l.avisos?.length ? ` — ${l.avisos.join(' ')}` : ''}`,
      // Versão Actual: horário previsto por baixo do nome e os avisos escritos em linha (assiduidade_ui.js, resumo do mês)
      render: (v: string, l) => (
        <div style={{ minWidth: 220 }}>
          <strong>{v}</strong>
          {textoHorario(l) && <div style={{ fontSize: 11, color: '#64748b' }}>{textoHorario(l)}</div>}
          {(l.avisos ?? []).map((x) => <div key={x} style={{ fontSize: 11, color: '#b45309', whiteSpace: 'normal' }}><WarningOutlined /> {x}</div>)}
        </div>
      ),
    },
    { title: 'Dias úteis', dataIndex: 'diasUteis', align: 'right', total: true, formatoTotal: (x) => x },
    { title: 'Dias c/ registo', dataIndex: 'diasComRegisto', align: 'right', total: true, formatoTotal: (x) => x },
    { title: 'Férias/ausências', dataIndex: 'diasFeriasAusencia', align: 'right', render: (v?: number) => v ?? 0, total: true, formatoTotal: (x) => x },
    { title: 'Dias de falta', dataIndex: 'diasFalta', align: 'right', valorImpressao: (l) => l.diasFalta ?? 0, render: (v: number) => (v > 0 ? <Typography.Text type={v > 3 ? 'danger' : undefined}>{v}</Typography.Text> : 0), total: true, formatoTotal: (x) => x },
    // art. 230.º da Lei 12/23: mais de 3 faltas injustificadas no mês ou 12 no ano
    { title: 'Faltas no ano', dataIndex: 'diasFaltaAno', align: 'right', valorImpressao: (l) => l.diasFaltaAno ?? '',
      render: (v?: number) => (v === undefined ? '—' : v > 12 ? <Tooltip title="Acima de 12 faltas injustificadas no ano há infracção disciplinar (art.º 230.º da Lei 12/23)."><Typography.Text type="danger">{v}</Typography.Text></Tooltip> : v) },
    { title: 'Horas trabalhadas', dataIndex: 'horasTrabalhadas', align: 'right', render: formatarHoras, total: true, formatoTotal: horas },
    { title: 'Horas extra', dataIndex: 'horasExtra', align: 'right', render: formatarHoras, total: true, formatoTotal: horas },
    { title: 'Horas de falta', dataIndex: 'horasFalta', align: 'right', render: formatarHoras, total: true, formatoTotal: horas },
    { title: 'Compensadas', dataIndex: 'horasCompensadas', align: 'right', render: formatarHoras, total: true, formatoTotal: horas },
    { title: 'Não autorizadas', dataIndex: 'horasNaoAutorizadas', align: 'right', render: formatarHoras, total: true, formatoTotal: horas },
    // Versão Actual: horas previstas do dia (escala › horário › contrato), atrasos e saídas antecipadas (informativos) e dias com escala
    { title: 'Horas/dia', dataIndex: 'horasDia', align: 'right', responsive: ['lg'], render: formatarHoras },
    { title: 'Atrasos', key: 'atrasos', align: 'right', responsive: ['lg'], valorImpressao: (l) => `${l.diasAtraso ?? 0} · ${formatarHoras(l.horasAtraso ?? 0)}`,
      render: (_, l) => (l.diasAtraso ? <Tooltip title="Informativo: os atrasos não descontam no salário.">{l.diasAtraso} · {formatarHoras(l.horasAtraso ?? 0)}</Tooltip> : 0) },
    { title: 'Saída antec.', dataIndex: 'horasSaidaAntecipada', align: 'right', responsive: ['lg'], render: (v?: number) => (v ? <Tooltip title="Informativo: horas de saída antes da hora prevista.">{formatarHoras(v)}</Tooltip> : 0),
      total: true, formatoTotal: horas },
    { title: 'Dias c/ escala', dataIndex: 'diasEscala', align: 'right', responsive: ['lg'], render: (v?: number, l?: LinhaApuramento) => (v ? `${v}${l?.diasEscalaPrioritaria ? ` (${l.diasEscalaPrioritaria} prior.)` : ''}` : 0) },
  ];
  const comFaltas = a ? (a as { colaboradores_com_faltas?: number }).colaboradores_com_faltas ?? a.linhas.filter((l) => (l.diasFalta ?? 0) > 0).length : 0;

  return (
    <Card loading={q.isLoading} title={<Space wrap>{seletor}<Space><IconeFa nome="calendar-check" />Apuramento de {mes}</Space></Space>}>
      {a && (
        <>
          {/* antigo (assiduidade_ui.js:494, resumo do mês): .ef-kpis com a mesma ordem e as mesmas cores; «Dias de falta» e «Estado» são do sistema novo */}
          <GrelhaKpi larguraMinima={150} style={{ marginBottom: 12 }}>
            <CartaoKpi barra="nenhuma" rotulo="Dias úteis do mês" valor={a.dias_uteis} />
            <CartaoKpi barra="nenhuma" rotulo="Colaboradores" valor={a.linhas.length} />
            <CartaoKpi barra="nenhuma" rotulo="Horas extra" valor={formatarHoras(a.totais.horasExtra)} corValor={COR_EXTRA} />
            <CartaoKpi barra="nenhuma" rotulo="Horas de falta" valor={formatarHoras(a.totais.horasFalta)} corValor={COR_ALERTA} />
            <CartaoKpi barra="nenhuma" rotulo="Com faltas por justificar" valor={comFaltas} corValor={comFaltas ? COR_FALTA : COR_EXTRA} />
            <CartaoKpi barra="nenhuma" rotulo="Dias de falta" valor={a.totais.diasFalta} />
            <CartaoKpi barra="nenhuma" rotulo="Estado" valor={<EstadoTag estado={a.estado} />} />
          </GrelhaKpi>
          <Flex justify="flex-end" wrap gap={12} style={{ marginBottom: 16 }}>
            <Space wrap>
              <BotoesExportar desactivado={!a.linhas.length} obterPedido={() => pedidoTabela({
                titulo: 'Mapa de efectividade (apuramento mensal)',
                periodo: mesPorExtenso(mes),
                filtros: [`Estado: ${fechado ? 'Fechado' : `provisório até ${formatarData(a.apurado_ate)}`}`, `Dias úteis: ${a.dias_uteis}`],
                colunas,
                linhas: a.linhas,
              })} />
              {!fechado && pode('rh_assid_registar') && (
                <Button icon={<SearchOutlined />} loading={accao.isPending} onClick={() => accao.mutate({ metodo: 'post', url: `/rh/assiduidade/meses/${mes}/detectar-faltas` })}>Detectar faltas</Button>
              )}
              {!fechado && pode('rh_assid_fechar') && (
                <Button type="primary" icon={<LockOutlined />} onClick={() => Modal.confirm({
                  title: `Fechar a efectividade de ${mesPorExtenso(mes)}?`,
                  content: 'O apuramento fica guardado e passa a poder ser lançado no processamento salarial. As faltas por justificar são geradas.',
                  okText: 'Fechar', cancelText: 'Cancelar', onOk: () => accao.mutateAsync({ metodo: 'post', url: `/rh/assiduidade/meses/${mes}/fechar` }),
                })}>Fechar efectividade</Button>
              )}
              {fechado && pode('rh_assid_fechar') && <Button icon={<UnlockOutlined />} onClick={() => { form.resetFields(); setReabrir(true); }}>Reabrir mês</Button>}
            </Space>
          </Flex>
          {a.fecho && (
            <Descriptions size="small" column={{ xs: 1, md: 2, xl: 3 }} style={{ marginBottom: 12 }}>
              <Descriptions.Item label="Fechado">{a.fecho.fechado_em ? `${formatarDataHora(a.fecho.fechado_em)} · ${a.fecho.fechado_por ?? ''}` : '—'}</Descriptions.Item>
              <Descriptions.Item label="Lançado no processamento">{a.fecho.lancado_em ? formatarDataHora(a.fecho.lancado_em) : 'Não'}</Descriptions.Item>
              <Descriptions.Item label="Faltas geradas">{a.fecho.ausencias_geradas ?? 0}</Descriptions.Item>
              {a.fecho.motivo_reabertura && <Descriptions.Item label="Última reabertura" span="filled">{formatarDataHora(a.fecho.reaberto_em)} — {a.fecho.motivo_reabertura}</Descriptions.Item>}
            </Descriptions>
          )}
          {!fechado && <Alert type="info" showIcon style={{ marginBottom: 12 }} message={`Apuramento provisório até ${formatarData(a.apurado_ate)} (o mês ainda não está fechado).`} />}
          <Typography.Paragraph type="secondary" style={{ marginBottom: 8 }}>
            As faltas descontam férias aprovadas e ausências justificadas aprovadas. Acima de 3 faltas injustificadas num mês (ou 12 no ano) há infracção disciplinar,
            além da perda de remuneração (art.º 230.º da Lei 12/23). A coluna «Faltas no ano» acumula as faltas de Janeiro até este mês.
          </Typography.Paragraph>
          <TabelaComModos<LinhaApuramento> idVista="apuramento" rowKey={(l) => String(l.employeeId ?? l.employee_id ?? l.nome)} size="small" columns={colunas} dataSource={a.linhas} scroll={scrollTabela()} modos={false}
            vazio={{ icone: "calendar-check", titulo: "Sem colaboradores no apuramento", texto: "Não há colaboradores activos neste mês." }} />
        </>
      )}
      <Modal title="Reabrir a efectividade do mês" open={reabrir} onCancel={() => setReabrir(false)} okText="Reabrir" okButtonProps={{ danger: true }} cancelText="Cancelar"
        confirmLoading={accao.isPending} onOk={() => form.submit()} destroyOnHidden>
        <Form form={form} layout="vertical" onFinish={(v) => accao.mutate({ metodo: 'post', url: `/rh/assiduidade/meses/${mes}/reabrir`, dados: v })}>
          <Form.Item name="motivo" label="Motivo" rules={[{ required: true, min: 5, message: 'Indique o motivo (pelo menos 5 caracteres).' }]}><Input.TextArea rows={3} maxLength={500} /></Form.Item>
          <Typography.Text type="secondary">Exige o processamento salarial do mês aberto.</Typography.Text>
        </Form>
      </Modal>
    </Card>
  );
}

// ───────────── Ausências ─────────────

const ESTADOS_AUSENCIA = ['POR_JUSTIFICAR', 'PENDENTE_CHEFIA', 'PENDENTE_RH', 'APROVADO', 'RECUSADO', 'CANCELADO'];

/**
 * Corpo do POST /rh/assiduidade/ausencias (registo novo) — o mesmo na grade (período em `periodo`, intervalo de datas)
 * e em cada linha (`data_inicio` / `data_fim`).
 */
export function dadosAusenciaNova(v: Record<string, unknown>) {
  const periodo = Array.isArray(v.periodo) ? v.periodo : null;
  return {
    tipo: v.tipo,
    motivo: textoDe(v.motivo),
    documento_url: textoDe(v.documento_url),
    colaborador_id: v.colaborador_id,
    data_inicio: dataDe(periodo ? periodo[0] : v.data_inicio),
    data_fim: dataDe(periodo ? periodo[1] : v.data_fim ?? v.data_inicio),
    horas: numeroDe(v.horas),
  };
}

/** Colunas da vista Linhas de «Registar ausência» (uma ausência por linha). */
export function camposAusencia(colaboradores: CampoFormulario['opcoes'], cat: Record<string, TipoAusencia>): CampoFormulario[] {
  return [
    { nome: 'colaborador_id', rotulo: 'Colaborador', tipo: 'select', obrigatorio: true, largura: 240, opcoes: colaboradores, aliases: ['Nome', 'NIF', 'Funcionário'] },
    { nome: 'data_inicio', rotulo: 'Início', tipo: 'data', obrigatorio: true, largura: 140, aliases: ['Data início', 'De'] },
    { nome: 'data_fim', rotulo: 'Fim', tipo: 'data', obrigatorio: true, largura: 140, aliases: ['Data fim', 'Até'],
      validar: (f, r) => (f && r.data_inicio && String(f) < String(r.data_inicio) ? 'O fim é anterior ao início.' : null) },
    { nome: 'tipo', rotulo: 'Tipo (Lei 12/23)', tipo: 'select', obrigatorio: true, largura: 220, aliases: ['Tipo'],
      opcoes: Object.entries(cat).map(([k, t]) => ({ value: k, label: t.nome })) },
    { nome: 'horas', rotulo: 'Horas', tipo: 'numero', largura: 90, min: 0.5, max: 744, precisao: 2,
      validar: (h, r) => (cat[String(r.tipo)]?.unidade === 'HORAS' && (h === null || h === undefined || h === '') ? 'Indique as horas (tipo em horas).' : null) },
    { nome: 'motivo', rotulo: 'Motivo', tipo: 'textoLongo', obrigatorio: true, largura: 260, maxLength: 1000 },
    { nome: 'documento_url', rotulo: 'Documento de prova (ligação)', largura: 220, maxLength: 1000, emLinhas: 'opcional', aliases: ['Documento', 'Prova'] },
  ];
}

function Ausencias({ mes, seletor }: { mes: string; seletor?: ReactNode }) {
  const { pode } = useSessao();
  const colaboradores = useColaboradores();
  const [estado, setEstado] = useState<string>();
  const [colaborador, setColaborador] = useState<number>();
  const [doMes, setDoMes] = useState(true);
  const [edicao, setEdicao] = useState<'nova' | Ausencia | null>(null);
  const [decidir, setDecidir] = useState<Ausencia | null>(null);
  const [form] = Form.useForm<{ colaborador_id: number; tipo: string; periodo: [Dayjs, Dayjs]; horas?: number; motivo: string; documento_url?: string }>();
  const [formD] = Form.useForm<{ decisao: 'APROVADO' | 'RECUSADO'; remunerada?: 'SIM' | 'NAO'; nota?: string }>();
  const catalogo = useQuery({ queryKey: ['rh', 'assiduidade', 'tipos-ausencia'], queryFn: () => obter<Record<string, TipoAusencia>>('/rh/assiduidade/tipos-ausencia'), staleTime: Infinity });
  const lista = useQuery({
    queryKey: ['rh', 'assiduidade', 'ausencias', { estado, colaborador, mes: doMes ? mes : null }],
    queryFn: () => obter<Ausencia[]>('/rh/assiduidade/ausencias', { estado, colaborador_id: colaborador, mes: doMes ? mes : undefined }),
  });
  useAvisarErro(lista.error);
  const accao = useAccaoRh(() => { setEdicao(null); setDecidir(null); });
  const tipoForm = Form.useWatch('tipo', form);
  const decisao = Form.useWatch('decisao', formD);
  const cat = catalogo.data ?? {};

  const colunas: ColunaApi<Ausencia>[] = [
    { title: 'Colaborador', dataIndex: 'colaborador_id', render: (v: number) => colaboradores.nome(v) },
    { title: 'Período', render: (_, a) => (a.data_inicio === a.data_fim ? formatarData(a.data_inicio) : `${formatarData(a.data_inicio)} a ${formatarData(a.data_fim)}`) },
    { title: 'Tipo', dataIndex: 'tipo', render: (t: string | null, a) => (t ? <Tooltip title={cat[t]?.artigo ? `Lei 12/23, art.º ${cat[t].artigo}` : undefined}>{cat[t]?.nome ?? t}</Tooltip> : a.ocorrencia ?? '—') },
    { title: 'Dias / horas', align: 'right', render: (_, a) => (a.horas ? formatarHoras(a.horas) : a.dias_uteis ?? a.dias ?? '—') },
    { title: 'Remunerada', dataIndex: 'remunerada', responsive: ['md'], render: (v: string | null) => (v === 'SIM' ? 'Sim' : v === 'NAO' ? 'Não' : v ?? '—') },
    { title: 'Estado', dataIndex: 'estado', render: (e: string) => <EstadoTag estado={e} /> },
    { title: 'Origem', responsive: ['lg'], render: (_, a) => (a.pedido_portal_colaborador_id ? <Tag>Portal</Tag> : a.detectada ? <Tag>Detectada</Tag> : <Tag>RH</Tag>) },
    { title: 'Motivo / decisão', responsive: ['md'], ellipsis: true, render: (_, a) => [a.motivo, a.nota_decisao].filter(Boolean).join(' — ') || '' },
    {
      title: '',
      key: 'accoes',
      render: (_, a) => (
        <Space size={4}>
          {a.estado === 'POR_JUSTIFICAR' && pode('rh_assid_registar') && (
            <Button size="small" onClick={() => { form.resetFields(); form.setFieldsValue({ colaborador_id: a.colaborador_id, periodo: [dayjs(a.data_inicio), dayjs(a.data_fim)] }); setEdicao(a); }}>Justificar</Button>
          )}
          {a.estado.startsWith('PENDENTE') && pode('rh_portal_aprovar') && (
            <Button size="small" type="primary" onClick={() => { formD.resetFields(); formD.setFieldsValue({ decisao: 'APROVADO' }); setDecidir(a); }}>Decidir</Button>
          )}
          {a.estado.startsWith('PENDENTE') && pode('rh_assid_registar', 'rh_portal_aprovar') && (
            <Popconfirm title="Cancelar a ausência?" description={a.detectada ? 'A falta volta a «por justificar».' : undefined} okText="Cancelar ausência" cancelText="Voltar"
              onConfirm={() => accao.mutateAsync({ metodo: 'post', url: `/rh/assiduidade/ausencias/${a.id}/cancelar` })}>
              <Button size="small" danger>Cancelar</Button>
            </Popconfirm>
          )}
        </Space>
      ),
    },
  ];

  const tipoSel = tipoForm ? cat[tipoForm] : undefined;
  const camposLinhas = useMemo(() => camposAusencia(opcoesColaboradores(colaboradores.lista), cat), [colaboradores.lista, cat]);
  const enviarAusencia = (v: { colaborador_id: number; tipo: string; periodo: [Dayjs, Dayjs]; horas?: number; motivo: string; documento_url?: string }) => {
    const base = { tipo: v.tipo, motivo: v.motivo, documento_url: v.documento_url || null };
    if (edicao === 'nova') accao.mutate({ metodo: 'post', url: '/rh/assiduidade/ausencias', dados: dadosAusenciaNova(v as unknown as Record<string, unknown>) });
    else if (edicao) accao.mutate({ metodo: 'post', url: `/rh/assiduidade/ausencias/${edicao.id}/justificar`, dados: base });
  };

  return (
    <Card>
      <BarraFiltros accoes={
        <>
          <BotoesExportar desactivado={!lista.data?.length} obterPedido={() => pedidoTabela({
            titulo: 'Ausências e faltas',
            periodo: doMes ? mesPorExtenso(mes) : undefined,
            filtros: [colaborador ? `Colaborador: ${colaboradores.nome(colaborador)}` : null, estado ? `Estado: ${estado}` : null],
            colunas,
            linhas: lista.data ?? [],
          })} />
          {pode('rh_assid_registar') && <Button type="primary" icon={<PlusOutlined />} onClick={() => { form.resetFields(); setEdicao('nova'); }}>Registar ausência</Button>}
        </>
      }>
          {seletor}
          <SeletorColaborador value={colaborador} onChange={setColaborador} />
          <Select placeholder="Estado" allowClear style={{ width: 200 }} value={estado} onChange={setEstado} options={ESTADOS_AUSENCIA.map((e) => ({ value: e, label: <EstadoTag estado={e} /> }))} />
          <Checkbox checked={doMes} onChange={(e) => setDoMes(e.target.checked)}>Só {mesPorExtenso(mes)}</Checkbox>
      </BarraFiltros>
      <TabelaComModos<Ausencia> idVista="ausencias" rowKey="id" size="small" loading={lista.isFetching} columns={colunas} dataSource={lista.data ?? []} scroll={scrollTabela()} pagination={{ pageSize: 50, showTotal: (t) => `${t} ausência(s)` }} />

      <ModalFormulario chave="rh.efectividade.ausencias" titulo={edicao === 'nova' ? 'Registar ausência' : 'Justificar falta'} aberto={edicao !== null} width={larguraModal(640)} aoFechar={() => setEdicao(null)}
        criacao={edicao === 'nova'} form={form} campos={camposLinhas} nomeRegistos="ausências" invalidar={[['rh', 'assiduidade']]}
        gravarLinha={(r) => enviar('post', '/rh/assiduidade/ausencias', dadosAusenciaNova(r))}
        okText="Gravar" cancelText="Cancelar" confirmLoading={accao.isPending} onOk={() => form.submit()}>
        <Form form={form} layout="vertical" onFinish={enviarAusencia}>
          <Form.Item name="colaborador_id" label="Colaborador" rules={[{ required: true }]}><SeletorColaborador style={{ width: '100%' }} disabled={edicao !== 'nova'} /></Form.Item>
          <Form.Item name="periodo" label="Período" rules={[{ required: true, message: 'Indique as datas.' }]}><DatePicker.RangePicker format="DD/MM/YYYY" disabled={edicao !== 'nova'} style={{ width: '100%' }} /></Form.Item>
          <Form.Item name="tipo" label="Tipo (Lei 12/23)" rules={[{ required: true, message: 'Escolha o tipo.' }]}>
            <Select showSearch optionFilterProp="label" options={Object.entries(cat).map(([k, t]) => ({ value: k, label: t.nome }))} />
          </Form.Item>
          {tipoSel && (
            <Alert type="info" showIcon style={{ marginBottom: 12 }} message={`Art.º ${tipoSel.artigo} · ${tipoSel.unidade === 'HORAS' ? 'em horas' : tipoSel.unidade === 'DIAS_UTEIS' ? 'dias úteis' : 'dias de calendário'} · remunerada: ${tipoSel.remunerada === 'SIM' ? 'sim' : tipoSel.remunerada === 'NAO' ? 'não' : 'a critério do empregador'}${tipoSel.max_seguidos ? ` · máx. ${tipoSel.max_seguidos} seguidos` : ''}${tipoSel.prova_opcional ? '' : ' · exige prova'}`} />
          )}
          {tipoSel?.unidade === 'HORAS' && edicao === 'nova' && <Form.Item name="horas" label="Horas" rules={[{ required: true }]}><InputNumber min={0.5} max={744} step={0.5} style={{ width: '100%' }} /></Form.Item>}
          <Form.Item name="motivo" label="Motivo" rules={[{ required: true, message: 'Indique o motivo.' }]}><Input.TextArea rows={3} maxLength={1000} /></Form.Item>
          <Form.Item name="documento_url" label="Documento de prova (ligação)" extra="Endereço do documento digitalizado."><Input maxLength={1000} /></Form.Item>
        </Form>
      </ModalFormulario>

      <Modal title="Decidir ausência" open={decidir !== null} onCancel={() => setDecidir(null)} okText="Confirmar" cancelText="Cancelar" confirmLoading={accao.isPending} onOk={() => formD.submit()} destroyOnHidden>
        {decidir && <Typography.Paragraph>{colaboradores.nome(decidir.colaborador_id)} — {decidir.tipo ? cat[decidir.tipo]?.nome ?? decidir.tipo : ''} ({formatarData(decidir.data_inicio)} a {formatarData(decidir.data_fim)})</Typography.Paragraph>}
        <Form form={formD} layout="vertical" onFinish={(v) => decidir && accao.mutate({ metodo: 'post', url: `/rh/assiduidade/ausencias/${decidir.id}/decidir`, dados: v })}>
          <Form.Item name="decisao" label="Decisão" rules={[{ required: true }]}>
            <Select options={[{ value: 'APROVADO', label: 'Aprovar' }, { value: 'RECUSADO', label: 'Recusar' }]} />
          </Form.Item>
          {decisao === 'APROVADO' && decidir?.tipo && cat[decidir.tipo]?.remunerada === 'EMPREGADOR' && (
            <Form.Item name="remunerada" label="Remunerada?" rules={[{ required: true, message: 'Decida sobre a remuneração.' }]}>
              <Select options={[{ value: 'SIM', label: 'Sim' }, { value: 'NAO', label: 'Não (descontada)' }]} />
            </Form.Item>
          )}
          <Form.Item name="nota" label="Nota" rules={[{ required: decisao === 'RECUSADO', message: 'Indique o motivo da recusa.' }]}><Input.TextArea rows={2} maxLength={500} /></Form.Item>
        </Form>
      </Modal>
    </Card>
  );
}

// ───────────── Fechos ─────────────

function Fechos() {
  const q = useQuery({ queryKey: ['rh', 'assiduidade', 'fechos'], queryFn: () => obter<FechoMensal[]>('/rh/assiduidade/fechos') });
  useAvisarErro(q.error);
  const colunas: ColunaApi<FechoMensal>[] = [
        { title: 'Mês', dataIndex: 'mes', render: (m: string) => mesPorExtenso(m) },
        { title: 'Estado', dataIndex: 'estado', render: (e: string) => <EstadoTag estado={e} /> },
        { title: 'Dias úteis', dataIndex: 'dias_uteis', align: 'right' },
        { title: 'Dias de falta', align: 'right', render: (_, f) => f.totais?.diasFalta ?? 0 },
        { title: 'Horas extra', align: 'right', render: (_, f) => formatarHoras(f.totais?.horasExtra) },
        { title: 'Horas de falta', align: 'right', render: (_, f) => formatarHoras(f.totais?.horasFalta) },
        { title: 'Fechado', render: (_, f) => (f.fechado_em ? `${formatarDataHora(f.fechado_em)} · ${f.fechado_por ?? ''}` : '—') },
        { title: 'Lançado', render: (_, f) => (f.lancado_em ? formatarDataHora(f.lancado_em) : '—') },
        { title: 'Faltas geradas', dataIndex: 'ausencias_geradas', align: 'right' },
  ];
  return (
    <Card>
      <BarraFiltros accoes={<BotoesExportar desactivado={!q.data?.length} obterPedido={() => pedidoTabela({ titulo: 'Fechos mensais da efectividade', colunas, linhas: q.data ?? [] })} />}>{null}</BarraFiltros>
      <TabelaComModos<FechoMensal> idVista="fechos" rowKey="id" size="small" loading={q.isFetching} dataSource={q.data ?? []} pagination={{ pageSize: 24 }} scroll={scrollTabela()} columns={colunas} />
    </Card>
  );
}

// ───────────── Configuração ─────────────

function Configuracao() {
  const { pode } = useSessao();
  const editar = pode('rh_assid_config');
  const q = useQuery({ queryKey: ['rh', 'assiduidade', 'config'], queryFn: () => obter<ConfigAssiduidade>('/rh/assiduidade/configuracao') });
  useAvisarErro(q.error);
  const [form] = Form.useForm();
  const accao = useAccaoRh();
  const modo = Form.useWatch('modo_compensacao', form);
  const [anoFeriados, setAnoFeriados] = useState(dayjs().year());
  /** Decisão 6: pré-carrega os feriados nacionais de Angola no campo (o utilizador revê e grava). */
  const preCarregar = async () => {
    try {
      const lista = await obter<FeriadoNacional[]>('/rh/assiduidade/feriados-nacionais', { ano: anoFeriados });
      const actuais: Dayjs[] = form.getFieldValue('feriados') ?? [];
      const chaves = new Set(actuais.map((d) => d.format('YYYY-MM-DD')));
      const novos = lista.filter((f) => !chaves.has(f.data));
      form.setFieldsValue({ feriados: [...actuais, ...novos.map((f) => dayjs(f.data))].sort((a, b) => a.valueOf() - b.valueOf()) });
      message.info(`${novos.length} feriado(s) nacional(is) de ${anoFeriados} acrescentado(s): confirme a lista e grave.`);
    } catch (e) {
      notificarErro(e);
    }
  };
  useEffect(() => {
    if (q.data) form.setFieldsValue({ ...q.data, feriados: (q.data.feriados ?? []).map((d) => dayjs(d)), relogio: q.data.relogio ?? {} });
  }, [q.data, form]);

  return (
    <Card loading={q.isLoading}>
      <Form form={form} layout="vertical" disabled={!editar} style={{ maxWidth: 820 }}
        onFinish={(v: Omit<ConfigAssiduidade, 'feriados'> & { feriados: Dayjs[] }) => accao.mutate({ metodo: 'put', url: '/rh/assiduidade/configuracao', dados: { ...v, feriados: (v.feriados ?? []).map((d) => d.format('YYYY-MM-DD')) } })}>
        <Form.Item name="dias_uteis" label="Dias úteis da semana" rules={[{ required: true, message: 'Escolha pelo menos um dia.' }]}>
          <Checkbox.Group options={DIAS_SEMANA.map((d, i) => ({ value: i, label: d }))} />
        </Form.Item>
        <Row gutter={16}>
          <Col xs={24} md={8}><Form.Item name="tolerancia_min" label="Tolerância (minutos)" rules={[{ required: true }]}><InputNumber min={0} max={120} style={{ width: '100%' }} /></Form.Item></Col>
          <Col xs={24} md={8}><Form.Item name="arredondamento_min" label="Arredondamento (minutos)" rules={[{ required: true }]}><Select options={ARREDONDAMENTOS.map((a) => ({ value: a, label: a === 0 ? 'Sem arredondamento' : `${a} min` }))} /></Form.Item></Col>
          <Col xs={24} md={8}><Form.Item name="extras_min_minutos" label="Mínimo para horas extra (min)" rules={[{ required: true }]}><InputNumber min={0} max={240} style={{ width: '100%' }} /></Form.Item></Col>
          <Col xs={24} md={8}>
            <Form.Item name="modo_compensacao" label="Compensação de horas" rules={[{ required: true }]}>
              <Select options={[{ value: 'DIA', label: 'No próprio dia' }, { value: 'MENSAL', label: 'No mês' }, { value: 'LIMITE', label: 'No mês, até um limite' }]} />
            </Form.Item>
          </Col>
          {modo === 'LIMITE' && <Col xs={24} md={8}><Form.Item name="limite_compensacao_h" label="Limite (horas)"><InputNumber min={0} max={200} style={{ width: '100%' }} /></Form.Item></Col>}
          <Col xs={24} md={8}><Form.Item name="extra_nao_util_exige_autorizacao" label="Extra em dia não útil" valuePropName="checked"><Checkbox>Exige autorização</Checkbox></Form.Item></Col>
          {/* Versão Actual (assiduidade.js:41): regime de picagem de quem não tem horário nem escala */}
          <Col xs={24} md={8}><Form.Item name="periodos_padrao" label="Regime por omissão"><Select options={[{ value: 1, label: 'Período corrido (2 picagens)' }, { value: 2, label: 'Dois períodos (4 picagens)' }]} /></Form.Item></Col>
          <Col xs={12} md={8}><Form.Item name="intervalo_saida_padrao" label="Saída p/ intervalo (omissão)" rules={[{ pattern: /^([01]d|2[0-3]):[0-5]d$/, message: 'HH:MM' }]}><Input type="time" /></Form.Item></Col>
          <Col xs={12} md={8}><Form.Item name="intervalo_entrada_padrao" label="Regresso do intervalo (omissão)" rules={[{ pattern: /^([01]d|2[0-3]):[0-5]d$/, message: 'HH:MM' }]}><Input type="time" /></Form.Item></Col>
        </Row>
        <Form.Item name="feriados" label="Feriados" extra="Lista oficial de Angola (Lei dos Feriados Nacionais): confirme antes de gravar; as pontes e tolerâncias de ponto decretadas acrescentam-se à mão.">
          <DatePicker multiple format="DD/MM/YYYY" style={{ width: '100%' }} />
        </Form.Item>
        {editar && (
          <Space wrap style={{ marginTop: -8, marginBottom: 16 }}>
            <InputNumber value={anoFeriados} min={2000} max={2100} onChange={(v) => v && setAnoFeriados(v)} aria-label="Ano dos feriados" />
            <Button icon={<CalendarOutlined />} onClick={() => void preCarregar()}>Pré-carregar feriados nacionais de Angola</Button>
          </Space>
        )}
        <Row gutter={16}>
          <Col xs={24} md={16}><Form.Item name={['relogio', 'url']} label="Relógio biométrico — endereço" extra="Lido pelo servidor do ERP (botão «Ler relógio» nos registos): http(s), sem credenciais no endereço."><Input maxLength={500} /></Form.Item></Col>
          <Col xs={24} md={8}><Form.Item name={['relogio', 'formato']} label="Formato"><Select allowClear options={[{ value: 'CSV', label: 'CSV' }, { value: 'JSON', label: 'JSON' }]} /></Form.Item></Col>
        </Row>
        {editar && <Button type="primary" htmlType="submit" loading={accao.isPending}>Gravar configuração</Button>}
      </Form>
    </Card>
  );
}
