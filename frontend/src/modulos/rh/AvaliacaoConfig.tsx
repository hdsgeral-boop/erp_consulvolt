import { Alert, Button, Card, Col, DatePicker, Divider, Form, Input, InputNumber, Modal, Row, Select, Space, Tabs, Typography } from 'antd';
import { LockOutlined, PlayCircleOutlined, PlusOutlined, SaveOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import dayjs, { type Dayjs } from 'dayjs';
import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { IconeFa } from '@/componentes/fluxos';
import type { ColunaApi } from '@/componentes/TabelaApi';
import { useSessao } from '@/sessao/SessaoContexto';
import { formatarData, formatarDataHora } from '@/utilitarios/formatacao';
import { CLASSES_AVALIACAO, METODOS_BONIFICACAO, PERIODOS_AVALIACAO, type Ciclo360, type ItemAvaliacao } from './api';
import { EstadoTag } from './comum/componentes';
import { useAccaoRh, useAvisarErro, useInfotipos } from './comum/consultas';
import { validarPesos360 } from './comum/regras';
import { scrollTabela } from '@/componentes/responsivo';
import { CartaoKpi, GrelhaKpi, TabelaComModos } from '@/componentes/vistas';
import { cicloActivo, textoProcedimentos } from './avaliacao360/textoComunicado';

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

const VALORES_NOVO = {
  ano: dayjs().year(), periodo: 'ANUAL', pesos: { CHEFIA: 50, AUTO: 10, PARES: 20, SUBORDINADOS: 20 }, minimo_anonimato: 3, max_pares: 5,
  prazos: { dias_conhecimento: 10, dias_contestacao: 10 }, feedback: { periodicidade: 'NENHUM' },
  bonificacao: { metodo: 'NENHUM', tabela: { Excelente: 15, 'Muito Bom': 10, Bom: 5, Suficiente: 0, Insuficiente: 0 }, meses_base: 1, bolsa: { montante: 0, nota_minima: 3.5 } },
};

/**
 * RH › Configuração da avaliação de desempenho (ecrã rh_avaliacao_config), como no antigo (aval360_ui.js): abre
 * directamente o formulário do ciclo activo com as secções Ciclo e período · Itens e objectivos · 360º e anonimato ·
 * Feedback periódico · Bonificação · Comunicado e abertura; «Novo ciclo», «Gravar configuração» e «Acompanhamento do
 * ciclo». A lista de ciclos fica por cima (escolher outro ciclo, abrir, fechar).
 */
export default function AvaliacaoConfig() {
  const { pode } = useSessao();
  const navegar = useNavigate();
  const infotipos = useInfotipos();
  const q = useQuery({ queryKey: ['rh', 'avaliacao', 'ciclos'], queryFn: () => obter<Ciclo360[]>('/rh/avaliacao/ciclos') });
  useAvisarErro(q.error);
  const itens = useQuery({ queryKey: ['rh', 'avaliacao', 'itens'], queryFn: () => obter<ItemAvaliacao[]>('/rh/avaliacao/itens') });
  const [escolhido, setEscolhido] = useState<number | 'novo' | null>(null);
  const [form] = Form.useForm();
  const accao = useAccaoRh<Ciclo360>((c, pedido) => { if (pedido.metodo === 'post' && pedido.url === '/rh/avaliacao/ciclos') setEscolhido(c.id); });
  const metodo = Form.useWatch(['bonificacao', 'metodo'], form);
  const pesos = Form.useWatch('pesos', form) as Record<string, number> | undefined;
  const avisoPesos = pesos ? validarPesos360(pesos) : null;

  const ciclos = q.data ?? [];
  const ciclo = escolhido === 'novo' ? null : ciclos.find((c) => c.id === escolhido) ?? null;
  const rascunho = escolhido === 'novo' || ciclo?.estado === 'RASCUNHO';
  const fechado = ciclo?.estado === 'FECHADO';
  const podeGravar = pode('rh_aval_config') && !fechado;

  useEffect(() => {
    if (escolhido === null && q.data) {
      const a = cicloActivo(q.data);
      setEscolhido(a ? a.id : pode('rh_aval_config') ? 'novo' : null);
    }
  }, [q.data, escolhido, pode]);

  const assinatura = ciclo ? JSON.stringify(ciclo) : '';
  useEffect(() => {
    form.resetFields();
    if (escolhido === 'novo') form.setFieldsValue(VALORES_NOVO);
    else if (ciclo) {
      const b = ciclo.bonificacao ?? {};
      form.setFieldsValue({
        ...ciclo,
        prazos: { ...ciclo.prazos, chefia_ate: ciclo.prazos?.chefia_ate ? dayjs(ciclo.prazos.chefia_ate) : null, respostas_ate: ciclo.prazos?.respostas_ate ? dayjs(ciclo.prazos.respostas_ate) : null },
        bonificacao: { ...b, infotipo_salarial_id: b.infotipo_salarial_id ?? b.infotype_id ?? null, mes_lancamento: b.mes_lancamento ? dayjs(b.mes_lancamento, 'MM/YYYY') : null },
      });
    }
    // Só se repõe ao mudar de ciclo ou quando os dados do ciclo mudam no servidor (não a cada nova leitura igual)
  }, [escolhido, assinatura, form]);

  const gravar = (v: Record<string, unknown> & { prazos?: Record<string, unknown>; bonificacao?: Record<string, unknown> }) => {
    const prazos = { ...v.prazos, chefia_ate: (v.prazos?.chefia_ate as Dayjs | null)?.format('YYYY-MM-DD') ?? null, respostas_ate: (v.prazos?.respostas_ate as Dayjs | null)?.format('YYYY-MM-DD') ?? null };
    const bonificacao = v.bonificacao ? { ...v.bonificacao, mes_lancamento: (v.bonificacao.mes_lancamento as Dayjs | null)?.format('MM/YYYY') ?? null } : undefined;
    const dados = { ...v, prazos, bonificacao };
    if (escolhido === 'novo') accao.mutate({ metodo: 'post', url: '/rh/avaliacao/ciclos', dados });
    else if (ciclo) accao.mutate({ metodo: 'put', url: `/rh/avaliacao/ciclos/${ciclo.id}`, dados });
  };

  const gerarTexto = () => {
    const v = form.getFieldsValue(true) as Record<string, never> & { prazos?: Record<string, unknown> };
    const periodoRot = PERIODOS_AVALIACAO.find((p) => p.value === v.periodo)?.label ?? v.periodo;
    form.setFieldValue(['comunicado', 'texto'], textoProcedimentos({
      nome: (v.nome as string) || ciclo?.nome || `Avaliação ${periodoRot} ${v.ano}`,
      data_inicio: ciclo?.data_inicio, data_fim: ciclo?.data_fim, pesos: v.pesos, minimo_anonimato: v.minimo_anonimato, feedback: v.feedback, bonificacao: v.bonificacao,
      prazos: { ...v.prazos, respostas_ate: (v.prazos?.respostas_ate as Dayjs | null)?.format('YYYY-MM-DD') ?? null, chefia_ate: (v.prazos?.chefia_ate as Dayjs | null)?.format('YYYY-MM-DD') ?? null } as never,
    }));
    if (!form.getFieldValue(['comunicado', 'titulo'])) form.setFieldValue(['comunicado', 'titulo'], `Abertura do ciclo de avaliação ${periodoRot} ${v.ano}`);
  };

  const abrirCiclo = (c: Ciclo360) => Modal.confirm({
    title: 'Abrir o ciclo?', content: 'Fotografa a composição (a partir da Estrutura Orgânica) e os critérios comuns. Só pode haver um ciclo aberto.',
    okText: 'Abrir', cancelText: 'Cancelar', onOk: () => accao.mutateAsync({ metodo: 'post', url: `/rh/avaliacao/ciclos/${c.id}/abrir` }),
  });
  const fecharCiclo = (c: Ciclo360) => Modal.confirm({
    title: 'Fechar o ciclo?', content: 'Os resultados anónimos passam a ser visíveis e a configuração da bonificação fica bloqueada.',
    okText: 'Fechar', okButtonProps: { danger: true }, cancelText: 'Cancelar', onOk: () => accao.mutateAsync({ metodo: 'post', url: `/rh/avaliacao/ciclos/${c.id}/fechar` }),
  });

  const colunas: ColunaApi<Ciclo360>[] = [
    { title: 'Ciclo', key: 'ciclo', render: (_, c) => <strong>{c.nome ?? `${c.periodo} ${c.ano}`}</strong>, valorFiltro: (c) => c.nome ?? `${c.periodo} ${c.ano}` },
    { title: 'Período', key: 'periodo', render: (_, c) => `${PERIODOS_AVALIACAO.find((p) => p.value === c.periodo)?.label ?? c.periodo} ${c.ano}` },
    { title: 'Datas', key: 'datas', render: (_, c) => `${formatarData(c.data_inicio)} a ${formatarData(c.data_fim)}` },
    { title: 'Estado', dataIndex: 'estado', render: (e: string) => <EstadoTag estado={e} /> },
    { title: 'Pesos (C/A/P/S)', key: 'pesos', render: (_, c) => GRUPOS.map((g) => c.pesos?.[g.chave] ?? 0).join(' / ') },
    { title: 'Participantes', key: 'participantes', align: 'right', render: (_, c) => c.participantes?.length ?? '—' },
    { title: 'Bonificação', key: 'bonificacao', ocultaPorOmissao: true, render: (_, c) => METODOS_BONIFICACAO.find((m) => m.value === c.bonificacao?.metodo)?.label ?? '—' },
    { title: 'Aberto', key: 'aberto', ocultaPorOmissao: true, render: (_, c) => (c.aberto_em ? `${formatarDataHora(c.aberto_em)} · ${c.aberto_por ?? ''}` : '—') },
  ];

  const lista = itens.data ?? [];
  const activos = lista.filter((i) => i.ativo);
  const comunsC = activos.filter((i) => i.ambito === 'COMUM' && i.tipo === 'CRITERIO').length;
  const objectivos = activos.filter((i) => i.tipo === 'OBJECTIVO');
  const nomeCiclo = escolhido === 'novo' ? 'Novo ciclo' : ciclo ? (ciclo.nome ?? `${ciclo.periodo} ${ciclo.ano}`) : '';

  return (
    <>
      <CabecalhoPagina titulo="Configuração da Avaliação de Desempenho" subtitulo="Ciclo 360º: período, itens e objectivos, anonimato, acompanhamento, bonificação e comunicado"
        accoes={<Space wrap>
          {pode('rh_aval_config') && <Button icon={<PlusOutlined />} onClick={() => setEscolhido('novo')}>Novo ciclo</Button>}
          {pode('rh_avaliacao_ciclo_view') && <Button icon={<IconeFa nome="sync-alt" />} onClick={() => navegar('/m/rh/rh_avaliacao_ciclo')}>Acompanhamento do ciclo</Button>}
        </Space>} />

      <Card size="small" title="Ciclos" style={{ marginBottom: 16 }}>
        <TabelaComModos<Ciclo360> idVista="ciclos-360" rowKey="id" size="small" loading={q.isFetching} columns={colunas} dataSource={ciclos} scroll={scrollTabela()} modos={false}
          impressao={{ titulo: 'Ciclos de avaliação 360º' }}
          rowClassName={(c) => (c.id === escolhido ? 'ant-table-row-selected' : '')}
          onRow={(c) => ({ onClick: () => setEscolhido(c.id), style: { cursor: 'pointer' } })}
          vazio={{ icone: 'sync-alt', titulo: 'Sem ciclos', texto: 'Crie o primeiro com «Novo ciclo».' }}
          accoes={(c) => [
            { icone: 'edit', titulo: 'Configurar este ciclo', onClick: () => setEscolhido(c.id) },
            pode('rh_aval_abrir') && c.estado === 'RASCUNHO' && { icone: 'play', titulo: 'Abrir o ciclo', variante: 'sucesso', onClick: () => abrirCiclo(c) },
            pode('rh_aval_abrir') && c.estado === 'ABERTO' && { icone: 'lock', titulo: 'Fechar o ciclo', variante: 'perigo', onClick: () => fecharCiclo(c) },
          ]} />
      </Card>

      {escolhido !== null && (escolhido === 'novo' || ciclo) && (
        <Card
          title={<Space wrap><IconeFa nome="sliders-h" />{nomeCiclo}{ciclo && <EstadoTag estado={ciclo.estado} />}</Space>}
          extra={<Space wrap>
            {ciclo && pode('rh_aval_abrir') && ciclo.estado === 'RASCUNHO' && <Button icon={<PlayCircleOutlined />} onClick={() => abrirCiclo(ciclo)}>Abrir ciclo</Button>}
            {ciclo && pode('rh_aval_abrir') && ciclo.estado === 'ABERTO' && <Button danger icon={<LockOutlined />} onClick={() => fecharCiclo(ciclo)}>Fechar ciclo</Button>}
            {podeGravar && <Button type="primary" icon={<SaveOutlined />} loading={accao.isPending} onClick={() => form.submit()}>Gravar configuração</Button>}
          </Space>}>
          {ciclo?.estado === 'ABERTO' && <Alert type="info" showIcon style={{ marginBottom: 12 }} message="Ciclo aberto: só se alteram o nome, os prazos, o comunicado, o acompanhamento e (sem bónus aprovados) a bonificação." />}
          {fechado && <Alert type="warning" showIcon style={{ marginBottom: 12 }} message="Ciclo fechado: a configuração já não se altera." />}
          <Form form={form} layout="vertical" onFinish={gravar} disabled={!podeGravar}>
            <Tabs type="card" items={[
              {
                key: 'ciclo', label: <span><IconeFa nome="calendar-alt" /> Ciclo e período</span>, forceRender: true,
                children: (
                  <Row gutter={12}>
                    <Col xs={24} md={12}><Form.Item name="nome" label="Nome do ciclo"><Input maxLength={255} placeholder="Por omissão: Avaliação <período> <ano>" /></Form.Item></Col>
                    <Col xs={24} sm={12} md={6}><Form.Item name="ano" label="Ano" rules={[{ required: escolhido === 'novo' }]}><InputNumber min={2000} max={2100} disabled={!rascunho} style={{ width: '100%' }} /></Form.Item></Col>
                    <Col xs={24} sm={12} md={6}><Form.Item name="periodo" label="Período avaliado" rules={[{ required: escolhido === 'novo' }]}><Select options={PERIODOS_AVALIACAO} disabled={!rascunho} /></Form.Item></Col>
                    <Col xs={24}><Divider plain orientation="left">Prazos</Divider></Col>
                    <Col xs={24} sm={12} md={6}><Form.Item name={['prazos', 'respostas_ate']} label="Autoavaliação e 360º até"><DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} /></Form.Item></Col>
                    <Col xs={24} sm={12} md={6}><Form.Item name={['prazos', 'chefia_ate']} label="Avaliação da chefia até"><DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} /></Form.Item></Col>
                    <Col xs={24} sm={12} md={6}><Form.Item name={['prazos', 'dias_conhecimento']} label="Tomada de conhecimento (dias)"><InputNumber min={1} style={{ width: '100%' }} /></Form.Item></Col>
                    <Col xs={24} sm={12} md={6}><Form.Item name={['prazos', 'dias_contestacao']} label="Prazo de contestação (dias)"><InputNumber min={1} style={{ width: '100%' }} /></Form.Item></Col>
                    <Col xs={24}><Alert type="info" showIcon message="A contestação conta a partir da tomada de conhecimento e é decidida pelo superior da chefia (na Estrutura Orgânica), com parecer do RH. Sem superior definido, decide o RH. Prazos vazios: respostas até 20 dias e chefia até 30 dias depois do fim do período." /></Col>
                  </Row>
                ),
              },
              {
                key: 'itens', label: <span><IconeFa nome="tasks" /> Itens e objectivos</span>, forceRender: true,
                children: (
                  <>
                    <GrelhaKpi larguraMinima={170}>
                      <CartaoKpi rotulo="Critérios comuns (nota 1–5)" valor={comunsC || '8 padrão'} icone="list" cor="azul" />
                      <CartaoKpi rotulo="Objectivos quantitativos" valor={objectivos.filter((i) => i.natureza !== 'QUALITATIVO').length} icone="bullseye" cor="verde" />
                      <CartaoKpi rotulo="Objectivos qualitativos" valor={objectivos.filter((i) => i.natureza === 'QUALITATIVO').length} icone="star" cor="laranja" />
                      <CartaoKpi rotulo="Itens específicos (por colaborador)" valor={activos.filter((i) => i.ambito === 'ESPECIFICO').length} icone="user-check" cor="roxo" />
                    </GrelhaKpi>
                    <ul style={{ fontSize: '0.84rem', color: '#334155', margin: '12px 0 0', paddingLeft: '1.1rem', lineHeight: 1.6 }}>
                      <li><b>Critérios comuns</b>: avaliados por todos os grupos do 360º (auto, chefia, pares e subordinados).</li>
                      <li><b>Itens específicos</b>: juntam-se aos comuns na avaliação de um colaborador (avaliados pela chefia e na autoavaliação).</li>
                      <li><b>Objectivos quantitativos</b>: meta, unidade e sentido; a chefia regista o valor atingido e o resultado em % é calculado.</li>
                      <li><b>Objectivos qualitativos</b>: nota de 1 a 5 (1 = 0% · 3 = 50% · 5 = 100%).</li>
                      <li>Na abertura do ciclo os critérios comuns ficam fixados para as avaliações 360º{ciclo?.criterios?.length ? ` (este ciclo: ${ciclo.criterios.map((c) => c.nome).join(', ')})` : ''}.</li>
                    </ul>
                    {pode('rh_avaliacao_itens') && <Button style={{ marginTop: 12 }} type="primary" icon={<IconeFa nome="tasks" />} onClick={() => navegar('/m/rh/rh_avaliacao')}>Gerir itens e objectivos</Button>}
                  </>
                ),
              },
              {
                key: 'r360', label: <span><IconeFa nome="sync-alt" /> 360º e anonimato</span>, forceRender: true,
                children: (
                  <Row gutter={12}>
                    <Col xs={24}><Typography.Text strong>Pesos dos grupos na nota final (somam 100)</Typography.Text></Col>
                    {GRUPOS.map((g) => <Col xs={24} sm={12} md={6} key={g.chave}><Form.Item name={['pesos', g.chave]} label={`${g.rotulo} (%)`}><InputNumber min={0} max={100} disabled={!rascunho} style={{ width: '100%' }} /></Form.Item></Col>)}
                    {avisoPesos && <Col xs={24}><Alert type="warning" showIcon message={avisoPesos} style={{ marginBottom: 12 }} /></Col>}
                    <Col xs={24} sm={12} md={8}><Form.Item name="minimo_anonimato" label="Mínimo de respostas (anonimato)"><InputNumber min={2} disabled={!rascunho} style={{ width: '100%' }} /></Form.Item></Col>
                    <Col xs={24} sm={12} md={8}><Form.Item name="max_pares" label="Máximo de pares por colaborador"><InputNumber min={0} max={50} disabled={!rascunho} style={{ width: '100%' }} /></Form.Item></Col>
                    <Col xs={24}><Alert type="info" showIcon message="Pares = colegas da mesma unidade (sem a chefia nem os próprios subordinados). Subordinados = equipa directa. As avaliações de pares e subordinados são anónimas: se um grupo não chegar ao mínimo, junta-se ao outro grupo anónimo; se mesmo assim não chegar, não conta e o peso é redistribuído." /></Col>
                  </Row>
                ),
              },
              {
                key: 'feedback', label: <span><IconeFa nome="comments" /> Feedback periódico</span>, forceRender: true,
                children: (
                  <Row gutter={12}>
                    <Col xs={24} md={12}><Form.Item name={['feedback', 'periodicidade']} label="Reuniões de acompanhamento"><Select options={PERIODICIDADES} /></Form.Item></Col>
                    <Col xs={24}><Typography.Text type="secondary">A chefia regista cada reunião no Portal (A minha equipa) e o colaborador confirma-a; as que faltam aparecem no Acompanhamento do ciclo.</Typography.Text></Col>
                  </Row>
                ),
              },
              {
                key: 'bonificacao', label: <span><IconeFa nome="gift" /> Bonificação</span>, forceRender: true,
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
                key: 'comunicado', label: <span><IconeFa nome="bullhorn" /> Comunicado e abertura</span>, forceRender: true,
                children: (
                  <>
                    <Space wrap style={{ marginBottom: 12 }}>
                      {podeGravar && <Button icon={<IconeFa nome="magic" />} onClick={gerarTexto}>Gerar texto a partir da configuração</Button>}
                      {ciclo && pode('rh_aval_abrir') && ciclo.estado === 'RASCUNHO' && <Button type="primary" icon={<PlayCircleOutlined />} onClick={() => abrirCiclo(ciclo)}>Abrir ciclo</Button>}
                    </Space>
                    <Form.Item name={['comunicado', 'titulo']} label="Título"><Input maxLength={255} /></Form.Item>
                    <Form.Item name={['comunicado', 'texto']} label="Texto (mostrado no portal, com confirmação de leitura)"><Input.TextArea rows={12} /></Form.Item>
                    <Typography.Text type="secondary">Grave a configuração antes de abrir o ciclo. Ao abrir, a composição (chefia, pares e subordinados) é fotografada a partir da Estrutura Orgânica.</Typography.Text>
                  </>
                ),
              },
            ]} />
          </Form>
        </Card>
      )}
    </>
  );
}
