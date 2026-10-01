import { Alert, Button, Card, Form, Input, Modal, Skeleton, Space, Table, Tabs, Tag, Typography } from 'antd';
import { ArrowLeftOutlined, AuditOutlined, CheckOutlined, RollbackOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { Route, Routes, useNavigate, useParams } from 'react-router-dom';
import { obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { useSessao } from '@/sessao/SessaoContexto';
import { formatarDataHora, formatarKz } from '@/utilitarios/formatacao';
import type { DetalhePeriodo } from './api';
import { CartasPeriodo } from './comum/Cartas';
import { EstadoTag } from './comum/componentes';
import { useAccaoRh, useAvisarErro } from './comum/consultas';
import { ListaPeriodos } from './comum/ListaPeriodos';
import { ResumoTotais, TabelaResultados } from './comum/TabelaResultados';
import { accoesPeriodo } from './comum/regras';

interface Verificacao {
  periodo_id: number;
  mes_ano: string;
  colaboradores: number;
  debitos_calculados: string;
  debitos_diario: string;
  diferenca: string;
  confere: boolean;
  sem_lancamento: boolean;
  modo_calculo: string;
}

/** RH › Processamentos (ecrã processamento): validar, reabrir, contabilizar/estornar e cartas de pagamento. */
export default function Processamento() {
  const [verificar, setVerificar] = useState(false);
  return (
    <>
      <Routes>
        <Route index element={
          <ListaPeriodos titulo="Processamentos" subtitulo="Validação, contabilização e pagamento dos processamentos salariais"
            accoesExtra={<Button icon={<AuditOutlined />} onClick={() => setVerificar(true)}>Verificar contra o diário</Button>} />
        } />
        <Route path=":id" element={<DetalheProcessamento />} />
      </Routes>
      {verificar && <VerificacaoDiario aoFechar={() => setVerificar(false)} />}
    </>
  );
}

function DetalheProcessamento() {
  const { id } = useParams();
  const navegar = useNavigate();
  const { pode } = useSessao();
  const periodo = useQuery({ queryKey: ['rh', 'salarios', 'periodo', id], queryFn: () => obter<DetalhePeriodo>(`/rh/salarios/periodos/${id}`) });
  useAvisarErro(periodo.error, 'Erro ao carregar o processamento');
  const [estorno, setEstorno] = useState(false);
  const [form] = Form.useForm<{ motivo: string }>();
  const accao = useAccaoRh(() => setEstorno(false));

  if (periodo.isLoading) return <Skeleton active />;
  const p = periodo.data;
  if (!p) return <Alert type="error" message="Processamento não encontrado." />;
  const ac = accoesPeriodo(p, pode);
  const post = (caminho: string) => accao.mutateAsync({ metodo: 'post', url: `/rh/salarios/periodos/${id}/${caminho}` });

  return (
    <>
      <CabecalhoPagina
        titulo={`Processamento ${p.mes_ano}`}
        subtitulo={<Space><EstadoTag estado={p.estado} />{p.contabilizado ? <Tag color="green">Contabilizado ({p.numero_lan_contabilizacao ?? '—'})</Tag> : <Tag>Por contabilizar</Tag>}</Space>}
        accoes={
          <>
            <Button icon={<ArrowLeftOutlined />} onClick={() => navegar('..')}>Voltar</Button>
            {ac.validar && (
              <Button type="primary" icon={<CheckOutlined />} onClick={() => Modal.confirm({ title: `Validar o processamento de ${p.mes_ano}?`, content: 'Depois de validado emitem-se os recibos, a ordem de pagamento e a contabilização.', okText: 'Validar', cancelText: 'Cancelar', onOk: () => post('validar') })}>Validar</Button>
            )}
            {ac.contabilizar && (
              <Button type="primary" onClick={() => Modal.confirm({ title: `Contabilizar ${p.mes_ano}?`, content: 'Cria o lançamento no diário SAL a partir da fotografia, com os mapeamentos contabilísticos. Se faltar algum mapeamento a operação é recusada.', okText: 'Contabilizar', cancelText: 'Cancelar', onOk: () => post('contabilizar') })}>Contabilizar</Button>
            )}
            {ac.descontabilizar && <Button danger onClick={() => { form.resetFields(); setEstorno(true); }}>Descontabilizar</Button>}
            {ac.reabrir && (
              <Button icon={<RollbackOutlined />} onClick={() => Modal.confirm({ title: `Reabrir ${p.mes_ano}?`, content: 'A fotografia dos resultados é apagada e o período volta a Aberto (lançamentos editáveis no ecrã Calcular).', okText: 'Reabrir', okButtonProps: { danger: true }, cancelText: 'Cancelar', onOk: () => post('reabrir') })}>Reabrir</Button>
            )}
          </>
        }
      />
      {p.estado === 'ABERTO' && <Alert type="info" showIcon style={{ marginBottom: 16 }} message="Período em cálculo: os resultados são ao vivo. Encerre o cálculo no ecrã Calcular para o validar aqui." />}
      {(p.estado === 'FECHADO' || p.estado === 'VALIDADO') && p.contabilizado && <Alert type="warning" showIcon style={{ marginBottom: 16 }} message="Contabilizado: para reabrir, descontabilize primeiro (estorno)." />}
      <ResumoTotais periodo={p} />
      <Card>
        <Tabs
          items={[
            { key: 'resultados', label: 'Resultados', children: <TabelaResultados periodo={p} carregando={periodo.isFetching} /> },
            { key: 'cartas', label: 'Cartas e pagamento', children: <CartasPeriodo periodo={p} /> },
            {
              key: 'historico',
              label: 'Histórico',
              children: (
                <Space direction="vertical">
                  <span>Encerrado: {p.fechado_em ? `${formatarDataHora(p.fechado_em)} por ${p.fechado_por ?? '—'}` : '—'}</span>
                  <span>Validado: {p.validado_em ? `${formatarDataHora(p.validado_em)} por ${p.validado_por ?? '—'}` : '—'}</span>
                  <span>Modo de cálculo: {p.modo_calculo === 'LEGADO' ? 'Legado (período migrado e fotografado)' : 'Actual'}</span>
                </Space>
              ),
            },
          ]}
        />
      </Card>
      <Modal title="Descontabilizar (estorno)" open={estorno} onCancel={() => setEstorno(false)} okText="Descontabilizar" okButtonProps={{ danger: true }} cancelText="Cancelar"
        confirmLoading={accao.isPending} onOk={() => form.submit()} destroyOnClose>
        <Form form={form} layout="vertical" onFinish={(v) => accao.mutate({ metodo: 'post', url: `/rh/salarios/periodos/${id}/descontabilizar`, dados: v })}>
          <Form.Item name="motivo" label="Motivo" rules={[{ required: true, min: 5, message: 'Indique o motivo (pelo menos 5 caracteres).' }]}>
            <Input.TextArea rows={3} maxLength={500} />
          </Form.Item>
          <Typography.Text type="secondary">O lançamento é estornado (fica o rasto no Diário).</Typography.Text>
        </Form>
      </Modal>
    </>
  );
}

function VerificacaoDiario({ aoFechar }: { aoFechar: () => void }) {
  const q = useQuery({ queryKey: ['rh', 'salarios', 'verificacao'], queryFn: () => obter<Verificacao[]>('/rh/salarios/verificacao-legado') });
  useAvisarErro(q.error);
  return (
    <Modal title="Verificação das folhas contra o diário (SAL)" open width={900} onCancel={aoFechar} footer={<Button onClick={aoFechar}>Fechar</Button>}>
      <Typography.Paragraph type="secondary">Compara os débitos calculados pela fotografia (vencimentos + INSS da empresa) com os débitos lançados no diário SAL (tolerância de 10 Kz).</Typography.Paragraph>
      <Table<Verificacao> rowKey="periodo_id" size="small" loading={q.isFetching} dataSource={q.data ?? []} pagination={false} scroll={{ x: 'max-content' }} columns={[
        { title: 'Mês', dataIndex: 'mes_ano' },
        { title: 'Colab.', dataIndex: 'colaboradores', align: 'right' },
        { title: 'Calculado', dataIndex: 'debitos_calculados', align: 'right', render: (v: string) => formatarKz(v) },
        { title: 'Diário', dataIndex: 'debitos_diario', align: 'right', render: (v: string) => formatarKz(v) },
        { title: 'Diferença', dataIndex: 'diferenca', align: 'right', render: (v: string) => formatarKz(v) },
        { title: 'Resultado', render: (_, r) => (r.sem_lancamento ? <Tag color="orange">Sem lançamento</Tag> : r.confere ? <Tag color="green">Confere</Tag> : <Tag color="red">Difere</Tag>) },
        { title: 'Cálculo', dataIndex: 'modo_calculo' },
      ]} />
    </Modal>
  );
}
