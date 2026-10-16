import { Alert, Checkbox, Col, Form, Input, InputNumber, Radio, Row, Switch, Typography, type FormInstance } from 'antd';
import { DIAS_CURTOS, horasPrevistas, textoHorario } from '../faseB/regras';
import type { HorarioTrabalho } from '../faseB/tipos';

/**
 * Horário do contrato (Versão Actual: app_v2.js:4751; assiduidade.js:135): 1 período (2 picagens, intervalo fixo
 * descontado) ou 2 períodos (4 picagens, intervalo picado), tolerância e dias da semana. As «Horas por dia» do contrato
 * passam a acompanhar o horário. O servidor valida a coerência (RegrasContrato::validarHorario).
 */
export interface ValoresHorario {
  usar_horario?: boolean;
  horario?: Partial<Omit<HorarioTrabalho, 'dias'>> & { dias?: number[] };
}

export function horarioParaForm(h: HorarioTrabalho | null | undefined): ValoresHorario {
  return h?.entrada ? { usar_horario: true, horario: { ...h, dias: h.dias ?? [] } }
    : { usar_horario: false, horario: { periodos: 1, intervalo_min: 60, tolerancia_min: 10, dias: [1, 2, 3, 4, 5] } };
}

export function horarioParaApi(v: ValoresHorario): HorarioTrabalho | null {
  if (!v.usar_horario || !v.horario?.entrada || !v.horario?.saida) return null;
  const h = v.horario;
  const dois = Number(h.periodos) === 2;
  return {
    entrada: h.entrada as string, saida: h.saida as string, periodos: dois ? 2 : 1,
    saida_intervalo: dois ? h.saida_intervalo ?? null : null, entrada_intervalo: dois ? h.entrada_intervalo ?? null : null,
    intervalo_min: dois ? 0 : Number(h.intervalo_min ?? 0), tolerancia_min: Number(h.tolerancia_min ?? 0), dias: h.dias ?? [],
  };
}

const hora = [{ pattern: /^([01]\d|2[0-3]):[0-5]\d$/, message: 'HH:MM' }];

export function CamposHorario({ form, semTitulo }: { form: FormInstance; /** Dentro de uma secção recolhível com o seu próprio título. */ semTitulo?: boolean }) {
  const usar = Form.useWatch('usar_horario', form) as boolean | undefined;
  const h = Form.useWatch('horario', form) as Partial<HorarioTrabalho> | undefined;
  const dois = Number(h?.periodos) === 2;
  const previstas = horasPrevistas(h);
  return (
    <>
      {!semTitulo && <Typography.Title level={5} style={{ marginTop: 8 }}>Horário de trabalho</Typography.Title>}
      <Form.Item name="usar_horario" valuePropName="checked" extra="Com horário, as horas por dia, as faltas e as horas extra passam a ser apuradas por ele (a escala do mês, quando existe, sobrepõe-se).">
        <Switch checkedChildren="Com horário" unCheckedChildren="Sem horário" />
      </Form.Item>
      {usar && (
        <>
          <Form.Item name={['horario', 'periodos']} label="Regime de picagem">
            <Radio.Group options={[{ value: 1, label: 'Período corrido (2 picagens)' }, { value: 2, label: 'Dois períodos (4 picagens)' }]} />
          </Form.Item>
          <Row gutter={12}>
            <Col xs={12} md={6}><Form.Item name={['horario', 'entrada']} label="Entrada" rules={[{ required: true, message: 'Entrada.' }, ...hora]}><Input type="time" /></Form.Item></Col>
            {dois && <Col xs={12} md={6}><Form.Item name={['horario', 'saida_intervalo']} label="Saída p/ intervalo" rules={[{ required: true, message: 'Obrigatória.' }, ...hora]}><Input type="time" /></Form.Item></Col>}
            {dois && <Col xs={12} md={6}><Form.Item name={['horario', 'entrada_intervalo']} label="Regresso do intervalo" rules={[{ required: true, message: 'Obrigatória.' }, ...hora]}><Input type="time" /></Form.Item></Col>}
            <Col xs={12} md={6}><Form.Item name={['horario', 'saida']} label="Saída" rules={[{ required: true, message: 'Saída.' }, ...hora]}><Input type="time" /></Form.Item></Col>
            {!dois && <Col xs={12} md={6}><Form.Item name={['horario', 'intervalo_min']} label="Intervalo (min)" extra="Não picado; descontado."><InputNumber min={0} max={480} style={{ width: '100%' }} /></Form.Item></Col>}
            <Col xs={12} md={6}><Form.Item name={['horario', 'tolerancia_min']} label="Tolerância (min)"><InputNumber min={0} max={120} style={{ width: '100%' }} /></Form.Item></Col>
          </Row>
          <Form.Item name={['horario', 'dias']} label="Dias da semana" extra="Sem nenhum escolhido valem os dias úteis da empresa.">
            <Checkbox.Group options={DIAS_CURTOS.map((d, i) => ({ value: i, label: d }))} />
          </Form.Item>
          <Alert type={previstas ? 'info' : 'warning'} showIcon style={{ marginBottom: 12 }}
            message={previstas ? `${textoHorario(h)}: ${String(previstas).replace('.', ',')} h por dia (passam a ser as horas por dia do contrato).` : 'Indique um horário com horas pagas (o intervalo não pode consumir o turno).'} />
        </>
      )}
    </>
  );
}
