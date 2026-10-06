import { Alert, Button, Checkbox, Col, DatePicker, Form, InputNumber, Modal, Radio, Row, Select, Space, Typography, message } from 'antd';
import { MinusCircleOutlined, PlusOutlined } from '@ant-design/icons';
import { useQueryClient } from '@tanstack/react-query';
import dayjs, { type Dayjs } from 'dayjs';
import { useState } from 'react';
import { enviar } from '@/api/cliente';
import { larguraModal } from '@/componentes/responsivo';
import { notificarErro } from '@/utilitarios/erros';
import { useColaboradores, useInfotipos } from './consultas';
import { SeletorColaborador } from './componentes';

interface Valores {
  colaboradores: number[];
  rubricas: { infotipo_salarial_id: number; valor_mes: number }[];
  modo: 'SUBSTITUIR' | 'MANTER';
  criar: boolean;
  data_inicio?: Dayjs;
  dias_contrato_mes?: number;
  horas_por_dia?: number;
}

interface Resultado { actualizados: string[]; criados: string[]; sem_alteracao: string[]; inactivos: string[]; sem_contrato: string[]; simulacao: boolean }

/**
 * Contratos › Aplicar rubricas em massa (contratosEmMassa, js/modules/rh/contratos_massa.js): uma ou mais rubricas com o
 * mesmo valor mensal para vários colaboradores — acrescenta ao contrato vigente (substituindo ou mantendo o valor) e,
 * opcionalmente, cria o contrato a quem não tem. Primeiro simula; depois aplica.
 */
export function ContratosMassa({ aberto, aoFechar }: { aberto: boolean; aoFechar: () => void }) {
  const cliente = useQueryClient();
  const colaboradores = useColaboradores();
  const infotipos = useInfotipos();
  const [form] = Form.useForm<Valores>();
  const criar = Form.useWatch('criar', form);
  const [plano, setPlano] = useState<Resultado | null>(null);
  const [aEnviar, setAEnviar] = useState(false);

  const executar = async (simular: boolean) => {
    const v = await form.validateFields();
    setAEnviar(true);
    try {
      const { dados, mensagem } = await enviar<Resultado>('post', '/rh/contratos/massa', {
        colaboradores: v.colaboradores, rubricas: v.rubricas, modo: v.modo, criar: v.criar, simular,
        novos: v.criar ? { data_inicio: v.data_inicio?.format('YYYY-MM-DD'), dias_contrato_mes: v.dias_contrato_mes, horas_por_dia: v.horas_por_dia } : undefined,
      });
      setPlano(dados);
      if (!simular) {
        message.success(mensagem);
        void cliente.invalidateQueries({ queryKey: ['rh'] });
      }
    } catch (e) {
      notificarErro(e);
    } finally {
      setAEnviar(false);
    }
  };
  const fechar = () => { setPlano(null); form.resetFields(); aoFechar(); };

  return (
    <Modal title="Aplicar rubricas em massa" open={aberto} width={larguraModal(760)} onCancel={fechar} destroyOnHidden
      footer={<Space wrap><Button onClick={fechar}>Fechar</Button><Button loading={aEnviar} onClick={() => void executar(true)}>Simular</Button>
        <Button type="primary" loading={aEnviar} disabled={!plano?.simulacao} onClick={() => void executar(false)}>Aplicar</Button></Space>}>
      <Typography.Paragraph type="secondary">Cada rubrica tem o mesmo valor mensal para todos os colaboradores escolhidos. Os contratos inactivos e os colaboradores não activos não são alterados.</Typography.Paragraph>
      <Form form={form} layout="vertical" initialValues={{ modo: 'SUBSTITUIR', criar: false, rubricas: [{}], dias_contrato_mes: 22, horas_por_dia: 8, data_inicio: dayjs().startOf('month') }}
        onValuesChange={() => setPlano(null)}>
        <Form.Item name="colaboradores" label="Colaboradores" rules={[{ required: true, message: 'Escolha os colaboradores.' }]}>
          <SeletorColaborador mode="multiple" apenasActivos maxTagCount="responsive" style={{ width: '100%' }} />
        </Form.Item>
        <Button size="small" style={{ marginTop: -12, marginBottom: 12 }} onClick={() => form.setFieldsValue({ colaboradores: colaboradores.lista.filter((c) => c.estado === 'ACTIVO').map((c) => c.id) as never })}>Todos os activos</Button>
        <Form.List name="rubricas">{(campos, { add, remove }) => (
          <>
            {campos.map(({ key, name }) => (
              <Row key={key} gutter={8} align="middle">
                <Col xs={24} sm={14}><Form.Item name={[name, 'infotipo_salarial_id']} rules={[{ required: true, message: 'Rubrica.' }]}>
                  <Select showSearch optionFilterProp="label" placeholder="Rubrica de vencimento" options={infotipos.lista.filter((i) => i.tipo === 'VENCIMENTO').map((i) => ({ value: i.id, label: i.nome }))} />
                </Form.Item></Col>
                <Col xs={20} sm={8}><Form.Item name={[name, 'valor_mes']} rules={[{ required: true, message: 'Valor.' }]}><InputNumber min={0} precision={2} decimalSeparator="," placeholder="Valor mensal (Kz)" style={{ width: '100%' }} /></Form.Item></Col>
                <Col xs={4} sm={2}>{campos.length > 1 && <Button type="text" danger icon={<MinusCircleOutlined />} aria-label="Retirar rubrica" onClick={() => remove(name)} />}</Col>
              </Row>
            ))}
            <Button size="small" icon={<PlusOutlined />} onClick={() => add({})} style={{ marginBottom: 12 }}>Acrescentar rubrica</Button>
          </>
        )}</Form.List>
        <Form.Item name="modo" label="Se o contrato já tiver a rubrica">
          <Radio.Group options={[{ value: 'SUBSTITUIR', label: 'Substituir o valor' }, { value: 'MANTER', label: 'Manter o valor actual' }]} />
        </Form.Item>
        <Form.Item name="criar" valuePropName="checked"><Checkbox>Criar o contrato a quem não tem, com estas rubricas</Checkbox></Form.Item>
        {criar && (
          <Row gutter={12}>
            <Col xs={24} sm={8}><Form.Item name="data_inicio" label="Início" rules={[{ required: true }]}><DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} /></Form.Item></Col>
            <Col xs={12} sm={8}><Form.Item name="dias_contrato_mes" label="Dias/mês"><InputNumber min={1} max={31} style={{ width: '100%' }} /></Form.Item></Col>
            <Col xs={12} sm={8}><Form.Item name="horas_por_dia" label="Horas/dia"><InputNumber min={1} max={24} style={{ width: '100%' }} /></Form.Item></Col>
          </Row>
        )}
      </Form>
      {plano && (
        <Alert type={plano.simulacao ? 'info' : 'success'} showIcon message={plano.simulacao ? 'Simulação (nada foi gravado)' : 'Aplicado'}
          description={`${plano.actualizados.length} contrato(s) a actualizar · ${plano.criados.length} a criar · ${plano.sem_alteracao.length} já com estes valores`
            + `${plano.inactivos.length ? ` · ${plano.inactivos.length} não activo(s)` : ''}${plano.sem_contrato.length ? ` · ${plano.sem_contrato.length} sem contrato ignorado(s)` : ''}.`} />
      )}
    </Modal>
  );
}
