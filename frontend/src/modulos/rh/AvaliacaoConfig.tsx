import { Alert, Button, Card, Col, DatePicker, Divider, Form, Input, InputNumber, Modal, Row, Select, Space, Tabs, Typography } from 'antd';
import { EditOutlined, LockOutlined, PlayCircleOutlined, PlusOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import dayjs, { type Dayjs } from 'dayjs';
import { useState } from 'react';
import { obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import type { ColunaApi } from '@/componentes/TabelaApi';
import { useSessao } from '@/sessao/SessaoContexto';
import { formatarData, formatarDataHora } from '@/utilitarios/formatacao';
import { CLASSES_AVALIACAO, METODOS_BONIFICACAO, PERIODOS_AVALIACAO, type Ciclo360 } from './api';
import { EstadoTag } from './comum/componentes';
import { useAccaoRh, useAvisarErro, useInfotipos } from './comum/consultas';
import { validarPesos360 } from './comum/regras';
import { larguraModal, scrollTabela } from '@/componentes/responsivo';
import { pedidoTabela } from './comum/impressao';

import { TabelaComModos } from '@/componentes/vistas';
const GRUPOS = [
  { chave: 'CHEFIA', rotulo: 'Chefia' },
  { chave: 'AUTO', rotulo: 'Autoavaliação' },
  { chave: 'PARES', rotulo: 'Pares' },
  { chave: 'SUBORDINADOS', rotulo: 'Subordinados' },
] as const;

const PERIODICIDADES = [
  { value: 'NENHUM', label: 'Sem reuniões de acompanhamento' },
  { value: 'MENSAL', label: 'Mensal' },
  { value: 'TRIMESTRAL', label: 'Trimestral' },
  { value: 'SEMESTRAL', label: 'Semestral' },
];

/** RH › Configuração da avaliação de desempenho (ecrã rh_avaliacao_config): ciclos 360º. */
export default function AvaliacaoConfig() {
  const { pode } = useSessao();
  const infotipos = useInfotipos();
  const q = useQuery({ queryKey: ['rh', 'avaliacao', 'ciclos'], queryFn: () => obter<Ciclo360[]>('/rh/avaliacao/ciclos') });
  useAvisarErro(q.error);
  const [edicao, setEdicao] = useState<Ciclo360 | 'novo' | null>(null);
  const [form] = Form.useForm();
  const accao = useAccaoRh(() => setEdicao(null));
  const metodo = Form.useWatch(['bonificacao', 'metodo'], form);
  const pesos = Form.useWatch('pesos', form) as Record<string, number> | undefined;
  const avisoPesos = pesos ? validarPesos360(pesos) : null;
  const rascunho = edicao === 'novo' || edicao?.estado === 'RASCUNHO';

  const abrir = (c: Ciclo360 | 'novo') => {
    form.resetFields();
    if (c === 'novo') {
      form.setFieldsValue({ ano: dayjs().year(), periodo: 'ANUAL', pesos: { CHEFIA: 50, AUTO: 10, PARES: 20, SUBORDINADOS: 20 }, minimo_anonimato: 3, max_pares: 5,
        prazos: { dias_conhecimento: 10, dias_contestacao: 10 }, feedback: { periodicidade: 'NENHUM' },
        bonificacao: { metodo: 'NENHUM', tabela: { Excelente: 15, 'Muito Bom': 10, Bom: 5, Suficiente: 0, Insuficiente: 0 }, meses_base: 1, bolsa: { montante: 0, nota_minima: 3.5 } } });
    } else {
      const b = c.bonificacao ?? {};
      form.setFieldsValue({
        ...c,
        prazos: { ...c.prazos, chefia_ate: c.prazos?.chefia_ate ? dayjs(c.prazos.chefia_ate) : null, respostas_ate: c.prazos?.respostas_ate ? dayjs(c.prazos.respostas_ate) : null },
        bonificacao: { ...b, infotipo_salarial_id: b.infotipo_salarial_id ?? b.infotype_id ?? null, mes_lancamento: b.mes_lancamento ? dayjs(b.mes_lancamento, 'MM/YYYY') : null },
      });
    }
    setEdicao(c);
  };

  const gravar = (v: Record<string, unknown> & { prazos?: Record<string, unknown>; bonificacao?: Record<string, unknown> }) => {
    const prazos = { ...v.prazos, chefia_ate: (v.prazos?.chefia_ate as Dayjs | null)?.format('YYYY-MM-DD') ?? null, respostas_ate: (v.prazos?.respostas_ate as Dayjs | null)?.format('YYYY-MM-DD') ?? null };
    const bonificacao = v.bonificacao ? { ...v.bonificacao, mes_lancamento: (v.bonificacao.mes_lancamento as Dayjs | null)?.format('MM/YYYY') ?? null } : undefined;
    const dados = { ...v, prazos, bonificacao };
    if (edicao === 'novo') accao.mutate({ metodo: 'post', url: '/rh/avaliacao/ciclos', dados });
    else if (edicao) accao.mutate({ metodo: 'put', url: `/rh/avaliacao/ciclos/${edicao.id}`, dados });
  };

  const colunas: ColunaApi<Ciclo360>[] = [
    { title: 'Ciclo', render: (_, c) => <strong>{c.nome ?? `${c.periodo} ${c.ano}`}</strong> },
    { title: 'Período', render: (_, c) => `${PERIODOS_AVALIACAO.find((p) => p.value === c.periodo)?.label ?? c.periodo} ${c.ano}` },
    { title: 'Datas', responsive: ['md'], render: (_, c) => `${formatarData(c.data_inicio)} a ${formatarData(c.data_fim)}` },
    { title: 'Estado', dataIndex: 'estado', render: (e: string) => <EstadoTag estado={e} /> },
    { title: 'Pesos (C/A/P/S)', responsive: ['md'], render: (_, c) => GRUPOS.map((g) => c.pesos?.[g.chave] ?? 0).join(' / ') },
    { title: 'Participantes', align: 'right', render: (_, c) => c.participantes?.length ?? '—' },
    { title: 'Bonificação', responsive: ['lg'], render: (_, c) => METODOS_BONIFICACAO.find((m) => m.value === c.bonificacao?.metodo)?.label ?? '—' },
    { title: 'Aberto', responsive: ['lg'], render: (_, c) => (c.aberto_em ? `${formatarDataHora(c.aberto_em)} · ${c.aberto_por ?? ''}` : '—') },
    {
      title: '',
      key: 'accoes',
      render: (_, c) => (
        <Space size={4} wrap>
          {pode('rh_aval_config') && c.estado !== 'FECHADO' && <Button size="small" icon={<EditOutlined />} onClick={() => abrir(c)}>Editar</Button>}
          {pode('rh_aval_abrir') && c.estado === 'RASCUNHO' && (
            <Button size="small" type="primary" icon={<PlayCircleOutlined />} onClick={() => Modal.confirm({ title: 'Abrir o ciclo?', content: 'Fotografa a composição (a partir da Estrutura Orgânica) e os critérios comuns. Só pode haver um ciclo aberto.', okText: 'Abrir', cancelText: 'Cancelar', onOk: () => accao.mutateAsync({ metodo: 'post', url: `/rh/avaliacao/ciclos/${c.id}/abrir` }) })}>Abrir</Button>
          )}
          {pode('rh_aval_abrir') && c.estado === 'ABERTO' && (
            <Button size="small" danger icon={<LockOutlined />} onClick={() => Modal.confirm({ title: 'Fechar o ciclo?', content: 'Os resultados anónimos passam a ser visíveis e a configuração da bonificação fica bloqueada.', okText: 'Fechar', okButtonProps: { danger: true }, cancelText: 'Cancelar', onOk: () => accao.mutateAsync({ metodo: 'post', url: `/rh/avaliacao/ciclos/${c.id}/fechar` }) })}>Fechar</Button>
          )}
        </Space>
      ),
    },
  ];

  return (
    <>
      <CabecalhoPagina titulo="Configuração da avaliação (ciclos 360º)" subtitulo="Período, prazos, pesos dos grupos, acompanhamento, bonificação e comunicado"
        accoes={pode('rh_aval_config') && <Button type="primary" icon={<PlusOutlined />} onClick={() => abrir('novo')}>Novo ciclo</Button>}
        impressaoDesactivada={!q.data?.length}
        impressao={() => pedidoTabela({ titulo: 'Ciclos de avaliação 360º', colunas, linhas: q.data ?? [] })} />
      <Card>
        <TabelaComModos<Ciclo360> rowKey="id" size="middle" loading={q.isFetching} columns={colunas} dataSource={q.data ?? []} pagination={false} scroll={scrollTabela()} />
      </Card>
      <Modal title={edicao === 'novo' ? 'Novo ciclo de avaliação' : 'Editar ciclo'} open={edicao !== null} width={larguraModal(860)} onCancel={() => setEdicao(null)} okText="Gravar" cancelText="Cancelar"
        confirmLoading={accao.isPending} onOk={() => form.submit()} destroyOnHidden>
        {!rascunho && <Alert type="info" showIcon style={{ marginBottom: 12 }} message="Ciclo aberto: só se alteram o nome, os prazos, o comunicado, o acompanhamento e (sem bónus aprovados) a bonificação." />}
        <Form form={form} layout="vertical" onFinish={gravar}>
          <Tabs items={[
            {
              key: 'geral',
              label: 'Geral',
              children: (
                <Row gutter={12}>
                  <Col xs={24} md={12}><Form.Item name="nome" label="Nome"><Input maxLength={255} placeholder="Por omissão: Avaliação <período> <ano>" /></Form.Item></Col>
                  <Col xs={24} sm={12} md={6}><Form.Item name="ano" label="Ano" rules={[{ required: edicao === 'novo' }]}><InputNumber min={2000} max={2100} disabled={!rascunho} style={{ width: '100%' }} /></Form.Item></Col>
                  <Col xs={24} sm={12} md={6}><Form.Item name="periodo" label="Período" rules={[{ required: edicao === 'novo' }]}><Select options={PERIODOS_AVALIACAO} disabled={!rascunho} /></Form.Item></Col>
                  <Col xs={24}><Typography.Text strong>Pesos dos grupos (somam 100)</Typography.Text></Col>
                  {GRUPOS.map((g) => <Col xs={24} sm={12} md={6} key={g.chave}><Form.Item name={['pesos', g.chave]} label={g.rotulo}><InputNumber min={0} max={100} disabled={!rascunho} style={{ width: '100%' }} /></Form.Item></Col>)}
                  {avisoPesos && <Col xs={24}><Alert type="warning" showIcon message={avisoPesos} style={{ marginBottom: 12 }} /></Col>}
                  <Col xs={24} sm={12} md={8}><Form.Item name="minimo_anonimato" label="Mínimo de respostas (anonimato)"><InputNumber min={2} disabled={!rascunho} style={{ width: '100%' }} /></Form.Item></Col>
                  <Col xs={24} sm={12} md={8}><Form.Item name="max_pares" label="Máximo de pares"><InputNumber min={0} max={50} disabled={!rascunho} style={{ width: '100%' }} /></Form.Item></Col>
                  <Col xs={24} sm={12} md={8}><Form.Item name={['feedback', 'periodicidade']} label="Acompanhamento"><Select options={PERIODICIDADES} /></Form.Item></Col>
                </Row>
              ),
            },
            {
              key: 'prazos',
              label: 'Prazos',
              children: (
                <Row gutter={12}>
                  <Col xs={24} md={12}><Form.Item name={['prazos', 'respostas_ate']} label="Respostas 360º até"><DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} /></Form.Item></Col>
                  <Col xs={24} md={12}><Form.Item name={['prazos', 'chefia_ate']} label="Avaliação da chefia até"><DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} /></Form.Item></Col>
                  <Col xs={24} md={12}><Form.Item name={['prazos', 'dias_conhecimento']} label="Dias para tomar conhecimento"><InputNumber min={1} style={{ width: '100%' }} /></Form.Item></Col>
                  <Col xs={24} md={12}><Form.Item name={['prazos', 'dias_contestacao']} label="Dias para contestar"><InputNumber min={1} style={{ width: '100%' }} /></Form.Item></Col>
                  <Col xs={24}><Typography.Text type="secondary">Vazios: respostas até 20 dias e chefia até 30 dias depois do fim do período.</Typography.Text></Col>
                </Row>
              ),
            },
            {
              key: 'bonificacao',
              label: 'Bonificação',
              children: (
                <Row gutter={12}>
                  <Col xs={24} md={12}><Form.Item name={['bonificacao', 'metodo']} label="Método"><Select options={METODOS_BONIFICACAO} /></Form.Item></Col>
                  <Col xs={24} md={12}>
                    <Form.Item name={['bonificacao', 'infotipo_salarial_id']} label="Rubrica (vencimento)">
                      <Select allowClear showSearch optionFilterProp="label" options={infotipos.lista.filter((i) => i.tipo === 'VENCIMENTO').map((i) => ({ value: i.id, label: i.nome }))} />
                    </Form.Item>
                  </Col>
                  <Col xs={24} md={12}><Form.Item name={['bonificacao', 'mes_lancamento']} label="Mês de lançamento no processamento"><DatePicker picker="month" format="MM/YYYY" style={{ width: '100%' }} /></Form.Item></Col>
                  {metodo === 'PERCENTAGEM' && <Col xs={24} md={12}><Form.Item name={['bonificacao', 'meses_base']} label="Meses de salário-base"><InputNumber min={0.01} style={{ width: '100%' }} /></Form.Item></Col>}
                  {(metodo === 'PERCENTAGEM' || metodo === 'FIXO') && (
                    <>
                      <Col xs={24}><Divider plain>{metodo === 'PERCENTAGEM' ? 'Percentagem por classificação' : 'Valor fixo por classificação (Kz)'}</Divider></Col>
                      {CLASSES_AVALIACAO.map((cl) => <Col xs={24} sm={12} md={8} key={cl}><Form.Item name={['bonificacao', 'tabela', cl]} label={cl}><InputNumber min={0} style={{ width: '100%' }} /></Form.Item></Col>)}
                    </>
                  )}
                  {metodo === 'BOLSA' && (
                    <>
                      <Col xs={24} md={12}><Form.Item name={['bonificacao', 'bolsa', 'montante']} label="Montante da bolsa (Kz)" rules={[{ required: true }]}><InputNumber min={0} style={{ width: '100%' }} /></Form.Item></Col>
                      <Col xs={24} md={12}><Form.Item name={['bonificacao', 'bolsa', 'nota_minima']} label="Nota mínima"><InputNumber min={1} max={5} step={0.1} style={{ width: '100%' }} /></Form.Item></Col>
                    </>
                  )}
                </Row>
              ),
            },
            {
              key: 'comunicado',
              label: 'Comunicado',
              children: (
                <>
                  <Form.Item name={['comunicado', 'titulo']} label="Título"><Input maxLength={255} /></Form.Item>
                  <Form.Item name={['comunicado', 'texto']} label="Texto (mostrado no portal, com confirmação de leitura)"><Input.TextArea rows={8} /></Form.Item>
                </>
              ),
            },
          ]} />
        </Form>
      </Modal>
    </>
  );
}
