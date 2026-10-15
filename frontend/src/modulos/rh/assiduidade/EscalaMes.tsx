import { Alert, Button, Card, Checkbox, Col, DatePicker, Descriptions, Form, Input, InputNumber, Modal, Popconfirm, Radio, Row, Space, Statistic, Table, Tag, Tooltip, Typography, Upload, message } from 'antd';
import type { ColumnsType } from 'antd/es/table';
import type { UploadFile } from 'antd/es/upload/interface';
import { DeleteOutlined, DownloadOutlined, UploadOutlined } from '@ant-design/icons';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import dayjs, { type Dayjs } from 'dayjs';
import { useState } from 'react';
import { descarregar, http, obter } from '@/api/cliente';
import type { Envelope } from '@/api/tipos';
import { IconeFa } from '@/componentes/fluxos';
import { larguraModal } from '@/componentes/responsivo';
import { useSessao } from '@/sessao/SessaoContexto';
import { notificarErro } from '@/utilitarios/erros';
import { dataApi, formatarData } from '@/utilitarios/formatacao';
import { SeletorColaborador } from '../comum/componentes';
import { useAccaoRh, useAvisarErro } from '../comum/consultas';
import { mesPorExtenso } from '../comum/regras';
import { DIAS_CURTOS, horasPrevistas, textoHorario } from '../faseB/regras';
import type { CelulaEscala, GrelhaEscala, LinhaGrelhaEscala, ResultadoEscala } from '../faseB/tipos';

/**
 * RH › Efectividade › «Escala do mês» (Versão Actual: assiduidade_ui.js:82; escalas.js). Turno previsto por colaborador
 * e dia (ou folga); a escala PRIORITÁRIA sobrepõe-se à prevista sem a apagar. Criar: aplicar um turno a um intervalo
 * (dias da semana opcionais), migrar o horário do contrato, copiar um intervalo, importar Excel; limpar um intervalo.
 * As gerações em massa nunca substituem uma prioritária sem confirmação. Com a efectividade do mês fechada, só consulta.
 */
type Operacao = 'aplicar' | 'contrato' | 'copiar' | 'importar' | 'limpar';

const hora = [{ pattern: /^([01]\d|2[0-3]):[0-5]\d$/, message: 'HH:MM' }];
const h2 = (v: number) => String(Math.round(v * 100) / 100).replace('.', ',');

function textoCelula(e: CelulaEscala): string {
  if (e.folga) return 'F';
  return h2(e.horas);
}

export function EscalaMes({ mes }: { mes: string }) {
  const { pode } = useSessao();
  const q = useQuery({ queryKey: ['rh', 'assiduidade', 'escalas', mes], queryFn: () => obter<GrelhaEscala>(`/rh/assiduidade/escalas/${mes}`) });
  useAvisarErro(q.error);
  const [operacao, setOperacao] = useState<Operacao | null>(null);
  const [celula, setCelula] = useState<{ linha: LinhaGrelhaEscala; dia: string; escala: CelulaEscala } | null>(null);
  const accao = useAccaoRh(() => setCelula(null));
  const g = q.data;
  const editar = pode('rh_assid_registar') && !g?.fechado;

  const colunas: ColumnsType<LinhaGrelhaEscala> = [
    { title: 'Colaborador', dataIndex: 'nome', fixed: 'left', width: 200, render: (v: string, l) => (
      <Space direction="vertical" size={0}>
        <Typography.Text strong>{v}</Typography.Text>
        <Typography.Text type="secondary" style={{ fontSize: 12 }}>{l.horario_contrato ? `Contrato: ${textoHorario(l.horario_contrato)}` : 'Sem horário no contrato'}</Typography.Text>
      </Space>
    ) },
    ...(g?.dias ?? []).map((d) => ({
      title: <Tooltip title={formatarData(d)}><span>{Number(d.slice(8))}<br /><small>{DIAS_CURTOS[dayjs(d).day()].slice(0, 2)}</small></span></Tooltip>,
      key: d,
      width: 46,
      align: 'center' as const,
      render: (_: unknown, l: LinhaGrelhaEscala) => {
        const e = l.celulas.find((c) => c.dia === d)?.escala;
        if (!e) return <Typography.Text type="secondary">·</Typography.Text>;
        return (
          <Tooltip title={`${e.folga ? 'Folga' : textoHorario({ ...e, intervalo_min: e.intervalo_minutos })}${e.prioritaria ? ' · prioritária' : ''}${e.nota ? ` · ${e.nota}` : ''}`}>
            <Tag style={{ margin: 0, cursor: 'pointer', paddingInline: 4 }} color={e.prioritaria ? 'gold' : e.folga ? 'default' : 'blue'} onClick={() => setCelula({ linha: l, dia: d, escala: e })}>
              {textoCelula(e)}
            </Tag>
          </Tooltip>
        );
      },
    })),
    { title: 'Dias', dataIndex: 'dias_trabalho', align: 'right', width: 60, fixed: 'right' },
    { title: 'Folgas', dataIndex: 'folgas', align: 'right', width: 64, fixed: 'right' },
    { title: 'Horas', dataIndex: 'horas', align: 'right', width: 70, fixed: 'right', render: h2 },
  ];

  return (
    <Card title={<Space><IconeFa nome="calendar-alt" />Escala de {mesPorExtenso(mes)}</Space>} loading={q.isLoading}
      extra={editar && (
        <Space wrap>
          <Button type="primary" onClick={() => setOperacao('aplicar')}>Aplicar turno</Button>
          <Button onClick={() => setOperacao('contrato')}>Migrar do contrato</Button>
          <Button onClick={() => setOperacao('copiar')}>Copiar</Button>
          <Button icon={<UploadOutlined />} onClick={() => setOperacao('importar')}>Importar Excel</Button>
          <Button danger onClick={() => setOperacao('limpar')}>Limpar</Button>
        </Space>
      )}>
      {g?.fechado && <Alert type="info" showIcon style={{ marginBottom: 12 }} message="A efectividade deste mês está fechada: a escala só pode ser consultada." />}
      <Typography.Paragraph type="secondary">
        Prevalência em cada dia: escala prioritária › escala prevista › horário do contrato › configuração da empresa. As horas da escala passam a ser as horas previstas
        para as faltas e as horas extra desse dia.
      </Typography.Paragraph>
      {g && (
        <Space size="large" wrap style={{ marginBottom: 12 }}>
          <Statistic title="Dias escalados" value={g.total.linhas} />
          <Statistic title="Prioritárias" value={g.total.prioritarias} />
          <Space size={4}><Tag color="blue">8</Tag>horas do turno<Tag>F</Tag>folga<Tag color="gold">4</Tag>prioritária</Space>
        </Space>
      )}
      <Table<LinhaGrelhaEscala> rowKey="colaborador_id" size="small" bordered pagination={false} columns={colunas} dataSource={g?.linhas ?? []} scroll={{ x: 'max-content', y: 520 }} />

      {operacao && g && <OperacaoEscala operacao={operacao} mes={mes} aoFechar={() => setOperacao(null)} />}

      <Modal open={celula !== null} title={celula ? `${celula.linha.nome} — ${formatarData(celula.dia)}` : ''} onCancel={() => setCelula(null)}
        footer={celula && editar ? (
          <Popconfirm title="Eliminar este dia da escala?" description={celula.escala.prioritaria ? 'A escala prevista (se existir) volta a valer.' : undefined} okText="Eliminar" okButtonProps={{ danger: true }}
            cancelText="Cancelar" onConfirm={() => accao.mutateAsync({ metodo: 'post', url: '/rh/assiduidade/escalas/eliminar', dados: { ids: [celula.escala.id] } })}>
            <Button danger icon={<DeleteOutlined />}>Eliminar dia</Button>
          </Popconfirm>
        ) : null}>
        {celula && (
          <Descriptions size="small" column={1} bordered>
            <Descriptions.Item label="Turno">{celula.escala.folga ? 'Folga' : textoHorario({ ...celula.escala, intervalo_min: celula.escala.intervalo_minutos })}</Descriptions.Item>
            <Descriptions.Item label="Regime">{celula.escala.folga ? '—' : celula.escala.periodos === 2 ? 'Dois períodos (4 picagens)' : 'Período corrido (2 picagens)'}</Descriptions.Item>
            <Descriptions.Item label="Horas previstas">{h2(celula.escala.horas)}</Descriptions.Item>
            <Descriptions.Item label="Tolerância">{celula.escala.tolerancia_min} min</Descriptions.Item>
            <Descriptions.Item label="Prioritária">{celula.escala.prioritaria ? 'Sim' : 'Não'}</Descriptions.Item>
            <Descriptions.Item label="Origem">{celula.escala.origem ?? '—'}</Descriptions.Item>
            {celula.escala.nota && <Descriptions.Item label="Nota">{celula.escala.nota}</Descriptions.Item>}
          </Descriptions>
        )}
      </Modal>
    </Card>
  );
}

interface ValoresOperacao {
  colaboradores?: number[];
  intervalo?: [Dayjs, Dayjs];
  origem?: [Dayjs, Dayjs];
  para_inicio?: Dayjs;
  dias_semana?: number[];
  folga?: boolean;
  prioritaria?: boolean;
  periodos?: 1 | 2;
  entrada?: string;
  saida?: string;
  saida_intervalo?: string;
  entrada_intervalo?: string;
  intervalo_minutos?: number;
  tolerancia_min?: number;
  nota?: string;
  substituir_prioritarias?: boolean;
  marcar_folgas?: boolean;
  limpar?: 'normais' | 'prioritarias' | 'todas';
}

const TITULOS: Record<Operacao, string> = {
  aplicar: 'Aplicar turno', contrato: 'Migrar o horário do contrato', copiar: 'Copiar escala', importar: 'Importar escala (Excel)', limpar: 'Limpar escala',
};

function OperacaoEscala({ operacao, mes, aoFechar }: { operacao: Operacao; mes: string; aoFechar: () => void }) {
  const [form] = Form.useForm<ValoresOperacao>();
  const cliente = useQueryClient();
  const [ficheiros, setFicheiros] = useState<UploadFile[]>([]);
  const [resultado, setResultado] = useState<ResultadoEscala | null>(null);
  const folga = Form.useWatch('folga', form);
  const periodos = Form.useWatch('periodos', form);
  const valores = Form.useWatch([], form) as ValoresOperacao | undefined;
  const inicio = dayjs(`${mes}-01`);
  const previstas = horasPrevistas({ entrada: valores?.entrada, saida: valores?.saida, periodos: valores?.periodos, saida_intervalo: valores?.saida_intervalo,
    entrada_intervalo: valores?.entrada_intervalo, intervalo_min: valores?.intervalo_minutos });

  const envio = useMutation({
    mutationFn: async (v: ValoresOperacao) => {
      const ids = v.colaboradores?.length ? v.colaboradores : null;
      if (operacao === 'importar') {
        const fd = new FormData();
        fd.append('ficheiro', ficheiros[0].originFileObj as File);
        fd.append('substituir_prioritarias', v.substituir_prioritarias ? '1' : '0');
        return (await http.post<Envelope<ResultadoEscala>>('/rh/assiduidade/escalas/importar', fd)).data;
      }
      const [de, ate] = (v.intervalo ?? []).map((d) => dataApi(d));
      const corpo = operacao === 'aplicar'
        ? { colaboradores: v.colaboradores, de, ate, dias_semana: v.dias_semana ?? [], folga: Boolean(v.folga), prioritaria: Boolean(v.prioritaria), substituir_prioritarias: Boolean(v.substituir_prioritarias),
          ...(v.folga ? {} : { periodos: v.periodos ?? 1, entrada: v.entrada, saida: v.saida, saida_intervalo: v.periodos === 2 ? v.saida_intervalo : null,
            entrada_intervalo: v.periodos === 2 ? v.entrada_intervalo : null, intervalo_minutos: v.periodos === 2 ? 0 : v.intervalo_minutos ?? 0, tolerancia_min: v.tolerancia_min ?? 0 }),
          nota: v.nota ?? null }
        : operacao === 'contrato' ? { de, ate, colaboradores: ids, marcar_folgas: Boolean(v.marcar_folgas), substituir_prioritarias: Boolean(v.substituir_prioritarias) }
          : operacao === 'copiar' ? { de_origem: dataApi(v.origem?.[0] ?? null), ate_origem: dataApi(v.origem?.[1] ?? null), para_inicio: dataApi(v.para_inicio ?? null), colaboradores: ids,
            prioritaria: Boolean(v.prioritaria), substituir_prioritarias: Boolean(v.substituir_prioritarias) }
            : { de, ate, colaboradores: ids, apenas_prioritarias: v.limpar === 'prioritarias', manter_prioritarias: v.limpar !== 'todas' };
      const url = { aplicar: 'aplicar', contrato: 'do-contrato', copiar: 'copiar', limpar: 'limpar' }[operacao];
      return (await http.post<Envelope<ResultadoEscala>>(`/rh/assiduidade/escalas/${url}`, corpo)).data;
    },
    onSuccess: (r) => {
      void cliente.invalidateQueries({ queryKey: ['rh', 'assiduidade'] });
      if (r.dados?.erros?.length) {
        setResultado(r.dados);
        return;
      }
      message.success(r.mensagem);
      if (r.dados?.sem_horario?.length) message.info(`Sem horário no contrato: ${r.dados.sem_horario.join(', ')}.`);
      aoFechar();
    },
    onError: (e) => notificarErro(e),
  });

  return (
    <Modal open title={TITULOS[operacao]} width={larguraModal(720)} okText={operacao === 'limpar' ? 'Limpar' : 'Gravar'} okButtonProps={{ danger: operacao === 'limpar', disabled: operacao === 'importar' && !ficheiros.length }}
      cancelText="Cancelar" confirmLoading={envio.isPending} onCancel={aoFechar} onOk={() => form.submit()} destroyOnHidden>
      <Form form={form} layout="vertical" onFinish={(v) => envio.mutate(v)}
        initialValues={{ intervalo: [inicio, inicio.endOf('month')], origem: [inicio, inicio.add(6, 'day')], para_inicio: inicio.add(7, 'day'), periodos: 1, intervalo_minutos: 60, tolerancia_min: 10, limpar: 'normais' }}>
        {operacao === 'importar' && (
          <>
            <Typography.Paragraph type="secondary">
              Uma linha por colaborador e dia; a chave é o NIF. Com erros não se grava nada (é indicada a linha). As linhas em branco são ignoradas.
              <Button type="link" icon={<DownloadOutlined />} onClick={() => void descarregar('/rh/assiduidade/escalas/modelo', undefined, 'Modelo_Escala.xlsx').catch(notificarErro)}>Descarregar o modelo</Button>
            </Typography.Paragraph>
            <Upload accept=".xlsx,.xls,.csv" maxCount={1} fileList={ficheiros} beforeUpload={() => false} onChange={({ fileList }) => setFicheiros(fileList.slice(-1))}>
              <Button icon={<UploadOutlined />}>Escolher ficheiro</Button>
            </Upload>
          </>
        )}
        {operacao === 'aplicar' && (
          <Form.Item name="colaboradores" label="Colaboradores" rules={[{ required: true, message: 'Escolha pelo menos um colaborador.' }]}>
            <SeletorColaborador apenasActivos mode="multiple" style={{ width: '100%' }} />
          </Form.Item>
        )}
        {(operacao === 'contrato' || operacao === 'copiar' || operacao === 'limpar') && (
          <Form.Item name="colaboradores" label="Colaboradores" extra="Vazio = todos os colaboradores activos."><SeletorColaborador apenasActivos mode="multiple" style={{ width: '100%' }} /></Form.Item>
        )}
        {(operacao === 'aplicar' || operacao === 'contrato' || operacao === 'limpar') && (
          <Form.Item name="intervalo" label="Intervalo" rules={[{ required: true, message: 'Indique o intervalo.' }]}><DatePicker.RangePicker format="DD/MM/YYYY" style={{ width: '100%' }} /></Form.Item>
        )}
        {operacao === 'copiar' && (
          <Row gutter={12}>
            <Col xs={24} md={14}><Form.Item name="origem" label="Copiar de" rules={[{ required: true }]}><DatePicker.RangePicker format="DD/MM/YYYY" style={{ width: '100%' }} /></Form.Item></Col>
            <Col xs={24} md={10}><Form.Item name="para_inicio" label="Para o intervalo que começa em" rules={[{ required: true }]}><DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} /></Form.Item></Col>
          </Row>
        )}
        {operacao === 'aplicar' && (
          <>
            <Form.Item name="dias_semana" label="Só nestes dias da semana" extra="Vazio = todos os dias do intervalo."><Checkbox.Group options={DIAS_CURTOS.map((d, i) => ({ value: i, label: d }))} /></Form.Item>
            <Space wrap style={{ marginBottom: 8 }}>
              <Form.Item name="folga" valuePropName="checked" noStyle><Checkbox>Folga (dia de descanso)</Checkbox></Form.Item>
              <Form.Item name="prioritaria" valuePropName="checked" noStyle><Checkbox>Escala prioritária (sobrepõe-se à prevista)</Checkbox></Form.Item>
            </Space>
            {!folga && (
              <>
                <Form.Item name="periodos" label="Regime de picagem"><Radio.Group options={[{ value: 1, label: 'Período corrido (2 picagens)' }, { value: 2, label: 'Dois períodos (4 picagens)' }]} /></Form.Item>
                <Row gutter={12}>
                  <Col xs={12} md={6}><Form.Item name="entrada" label="Entrada" rules={[{ required: true, message: 'Entrada.' }, ...hora]}><Input type="time" /></Form.Item></Col>
                  {periodos === 2 && <Col xs={12} md={6}><Form.Item name="saida_intervalo" label="Saída p/ intervalo" rules={[{ required: true, message: 'Obrigatória.' }, ...hora]}><Input type="time" /></Form.Item></Col>}
                  {periodos === 2 && <Col xs={12} md={6}><Form.Item name="entrada_intervalo" label="Regresso" rules={[{ required: true, message: 'Obrigatória.' }, ...hora]}><Input type="time" /></Form.Item></Col>}
                  <Col xs={12} md={6}><Form.Item name="saida" label="Saída" rules={[{ required: true, message: 'Saída.' }, ...hora]}><Input type="time" /></Form.Item></Col>
                  {periodos !== 2 && <Col xs={12} md={6}><Form.Item name="intervalo_minutos" label="Intervalo (min)"><InputNumber min={0} max={480} style={{ width: '100%' }} /></Form.Item></Col>}
                  <Col xs={12} md={6}><Form.Item name="tolerancia_min" label="Tolerância (min)"><InputNumber min={0} max={120} style={{ width: '100%' }} /></Form.Item></Col>
                </Row>
                {previstas !== null && <Typography.Paragraph type="secondary">Horas previstas por dia: {h2(previstas)}</Typography.Paragraph>}
              </>
            )}
            <Form.Item name="nota" label="Nota"><Input maxLength={500} placeholder="Ex.: motivo da escala prioritária" /></Form.Item>
          </>
        )}
        {operacao === 'contrato' && <Form.Item name="marcar_folgas" valuePropName="checked"><Checkbox>Marcar os dias sem trabalho como folga (descanso semanal e feriados)</Checkbox></Form.Item>}
        {operacao === 'copiar' && <Form.Item name="prioritaria" valuePropName="checked"><Checkbox>Gravar a cópia como escala prioritária</Checkbox></Form.Item>}
        {operacao === 'limpar' && (
          <Form.Item name="limpar" label="O que limpar">
            <Radio.Group options={[{ value: 'normais', label: 'A escala prevista (mantém as prioritárias)' }, { value: 'prioritarias', label: 'Só as prioritárias' }, { value: 'todas', label: 'Tudo' }]} />
          </Form.Item>
        )}
        {operacao !== 'limpar' && (
          <Form.Item name="substituir_prioritarias" valuePropName="checked" extra="Por omissão os dias com escala prioritária ficam protegidos.">
            <Checkbox>Substituir também os dias com escala prioritária</Checkbox>
          </Form.Item>
        )}
      </Form>
      {resultado?.erros?.length ? (
        <Alert type="error" showIcon message={`${resultado.erros.length} erro(s): nada foi gravado.`} description={<ul style={{ maxHeight: 200, overflow: 'auto', paddingLeft: 18, margin: 0 }}>{resultado.erros.map((e, i) => <li key={i}>{e}</li>)}</ul>} />
      ) : null}
    </Modal>
  );
}
