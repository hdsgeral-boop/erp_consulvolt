import { Alert, Button, Card, Descriptions, Empty, Flex, Form, Input, InputNumber, Modal, Select, Space, Table, Tabs, Tag, Typography } from 'antd';
import { useQuery } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { BotoesExportar } from '@/componentes/impressao';
import type { ColunaApi } from '@/componentes/TabelaApi';
import { useSessao } from '@/sessao/SessaoContexto';
import { formatarData, formatarKz, formatarNumero } from '@/utilitarios/formatacao';
import { PERIODOS_AVALIACAO, type Avaliacao, type Bonificacao, type Ciclo360 } from './api';
import { EstadoTag, FaseTag } from './comum/componentes';
import { useAccaoRh, useAvisarErro, useColaboradores } from './comum/consultas';
import { classificar, somar } from './comum/regras';
import { BarraFiltros, COLUNAS_DESCRICOES, larguraModal, scrollTabela } from '@/componentes/responsivo';
import { pedidoTabela } from './comum/impressao';

interface Resultado360 {
  componentes?: Record<string, { media: number | null; n: number; peso: number }>;
  nota?: number | null;
  classificacao?: string | null;
  avisos?: string[];
  comentarios?: string[];
  respostas: Record<string, number>;
  esperadas: Record<string, number>;
  liberado: boolean;
  aviso?: string;
}

const ROTULO_GRUPO: Record<string, string> = { CHEFIA: 'Chefia', AUTO: 'Autoavaliação', PARES: 'Pares', SUBORDINADOS: 'Subordinados', PARES_SUB: 'Pares e subordinados' };

/** RH › Acompanhamento do ciclo 360º (ecrã rh_avaliacao_ciclo): fases, contestações (parecer/decisão), resultados e bonificações. */
export default function AvaliacaoCiclo() {
  const ciclos = useQuery({ queryKey: ['rh', 'avaliacao', 'ciclos'], queryFn: () => obter<Ciclo360[]>('/rh/avaliacao/ciclos') });
  useAvisarErro(ciclos.error);
  const [id, setId] = useState<number>();
  useEffect(() => {
    if (id === undefined && ciclos.data?.length) setId((ciclos.data.find((c) => c.estado === 'ABERTO') ?? ciclos.data[0]).id);
  }, [ciclos.data, id]);
  const ciclo = ciclos.data?.find((c) => c.id === id);

  return (
    <>
      <CabecalhoPagina titulo="Acompanhamento do ciclo 360º" subtitulo="A chefia avalia no Portal; aqui o RH acompanha as fases, dá parecer nas contestações e trata as bonificações"
        accoes={<Select style={{ width: 300, maxWidth: '100%' }} placeholder="Ciclo" value={id} onChange={setId} loading={ciclos.isLoading}
          options={(ciclos.data ?? []).map((c) => ({ value: c.id, label: `${c.nome ?? `${c.periodo} ${c.ano}`} — ${c.estado}` }))} />} />
      {!ciclo ? <Empty description="Sem ciclos de avaliação." /> : (
        <>
          <Card style={{ marginBottom: 16 }}>
            <Descriptions size="small" column={{ xs: 1, md: 4 }}>
              <Descriptions.Item label="Estado"><EstadoTag estado={ciclo.estado} /></Descriptions.Item>
              <Descriptions.Item label="Período">{PERIODOS_AVALIACAO.find((p) => p.value === ciclo.periodo)?.label} {ciclo.ano} ({formatarData(ciclo.data_inicio)} a {formatarData(ciclo.data_fim)})</Descriptions.Item>
              <Descriptions.Item label="Respostas até">{formatarData(ciclo.prazos?.respostas_ate)}</Descriptions.Item>
              <Descriptions.Item label="Chefia até">{formatarData(ciclo.prazos?.chefia_ate)}</Descriptions.Item>
            </Descriptions>
          </Card>
          <Tabs items={[
            { key: 'avaliacoes', label: 'Avaliações e contestações', children: <Avaliacoes ciclo={ciclo} /> },
            { key: 'participantes', label: `Participantes (${ciclo.participantes?.length ?? 0})`, children: <Participantes ciclo={ciclo} /> },
            { key: 'bonificacoes', label: 'Bonificações', children: <Bonificacoes ciclo={ciclo} /> },
          ]} />
        </>
      )}
    </>
  );
}

function Avaliacoes({ ciclo }: { ciclo: Ciclo360 }) {
  const { pode } = useSessao();
  const colaboradores = useColaboradores();
  const q = useQuery({ queryKey: ['rh', 'avaliacao', 'avaliacoes', ciclo.ano, ciclo.periodo], queryFn: () => obter<Avaliacao[]>('/rh/avaliacao/avaliacoes', { ano: ciclo.ano, periodo: ciclo.periodo }) });
  useAvisarErro(q.error);
  const [fase, setFase] = useState<string>();
  const [parecer, setParecer] = useState<Avaliacao | null>(null);
  const [decidir, setDecidir] = useState<Avaliacao | null>(null);
  const [resultado, setResultado] = useState<Avaliacao | null>(null);
  const [formP] = Form.useForm<{ texto: string }>();
  const [formD] = Form.useForm<{ resultado: 'MANTIDA' | 'ALTERADA'; justificacao: string; nota?: number }>();
  const accao = useAccaoRh(() => { setParecer(null); setDecidir(null); });
  const resDecisao = Form.useWatch('resultado', formD);
  const r360 = useQuery({ queryKey: ['rh', 'avaliacao', 'resultado360', resultado?.id], queryFn: () => obter<Resultado360>(`/rh/avaliacao/avaliacoes/${resultado?.id}/resultado-360`), enabled: resultado !== null });

  const linhas = (q.data ?? []).filter((a) => !fase || a.fase === fase);
  const nomeCiclo = `${ciclo.nome ?? `${ciclo.periodo} ${ciclo.ano}`}`;
  const colunas: ColunaApi<Avaliacao>[] = [
    { title: 'Colaborador', dataIndex: 'colaborador_id', render: (v: number) => <strong>{colaboradores.nome(v)}</strong> },
    { title: 'Fase', dataIndex: 'fase', render: (f: string) => <FaseTag fase={f} /> },
    { title: 'Pontuação (chefia)', dataIndex: 'pontuacao', align: 'right', responsive: ['md'], render: (v: string | null) => (v ? formatarNumero(v) : '—') },
    { title: 'Nota 360º', dataIndex: 'nota_360', align: 'right', responsive: ['md'], render: (v: string | null) => (v ? formatarNumero(v) : '—') },
    { title: 'Nota final', dataIndex: 'nota_final', align: 'right', render: (v: number | null) => (v !== null ? <strong>{formatarNumero(v)}</strong> : '—') },
    { title: 'Classificação', render: (_, a) => a.classificacao_360 ?? a.classificacao ?? classificar(a.nota_final) ?? '—' },
    { title: 'Contestação', responsive: ['md'], render: (_, a) => (a.contestacao?.fundamentacao ? (a.contestacao.decisao ? <Tag color="green">Decidida ({a.contestacao.decisao.resultado === 'ALTERADA' ? 'alterada' : 'mantida'})</Tag> : a.contestacao.parecer_rh ? <Tag color="blue">Com parecer</Tag> : <Tag color="red">Aguarda parecer</Tag>) : '—') },
    {
      title: '',
      key: 'accoes',
      render: (_, a) => (
        <Space size={4} wrap>
          <Button size="small" onClick={() => setResultado(a)}>Resultado 360º</Button>
          {a.fase === 'CONTESTADA' && !a.contestacao?.parecer_rh && pode('rh_aval_parecer') && <Button size="small" onClick={() => { formP.resetFields(); setParecer(a); }}>Dar parecer</Button>}
          {a.fase === 'CONTESTADA' && a.contestacao?.parecer_rh && <Button size="small" type="primary" onClick={() => { formD.resetFields(); formD.setFieldsValue({ resultado: 'MANTIDA' }); setDecidir(a); }}>Decidir</Button>}
        </Space>
      ),
    },
  ];

  const res = r360.data;
  return (
    <Card>
      <BarraFiltros style={{ marginBottom: 12 }} accoes={
        <BotoesExportar desactivado={!linhas.length} obterPedido={() => pedidoTabela({ titulo: 'Avaliações do ciclo 360º', subtitulo: nomeCiclo, filtros: fase ? [`Fase: ${fase}`] : undefined, colunas, linhas })} />
      }>
        <Select placeholder="Fase" allowClear style={{ width: 220 }} value={fase} onChange={setFase} options={['EM_AVALIACAO', 'AGUARDA_CONHECIMENTO', 'PRAZO_CONTESTACAO', 'CONTESTADA', 'FINAL'].map((f) => ({ value: f, label: <FaseTag fase={f} /> }))} />
      </BarraFiltros>
      <Table<Avaliacao> rowKey="id" size="small" loading={q.isFetching} columns={colunas} dataSource={linhas} pagination={{ pageSize: 50 }} scroll={scrollTabela()}
        expandable={{ rowExpandable: (a) => Boolean(a.contestacao?.fundamentacao), expandedRowRender: (a) => (
          <Descriptions size="small" column={1} bordered>
            <Descriptions.Item label="Fundamentação">{a.contestacao?.fundamentacao}</Descriptions.Item>
            {a.contestacao?.parecer_rh && <Descriptions.Item label="Parecer do RH">{a.contestacao.parecer_rh.texto}</Descriptions.Item>}
            {a.contestacao?.decisao && <Descriptions.Item label="Decisão">{a.contestacao.decisao.justificacao}</Descriptions.Item>}
          </Descriptions>
        ) }} />

      <Modal title="Parecer do RH na contestação" open={parecer !== null} onCancel={() => setParecer(null)} okText="Registar" cancelText="Cancelar" confirmLoading={accao.isPending} onOk={() => formP.submit()} destroyOnHidden>
        <Typography.Paragraph type="secondary">{parecer?.contestacao?.fundamentacao}</Typography.Paragraph>
        <Form form={formP} layout="vertical" onFinish={(v) => parecer && accao.mutate({ metodo: 'post', url: `/rh/avaliacao/avaliacoes/${parecer.id}/parecer`, dados: v })}>
          <Form.Item name="texto" label="Parecer" rules={[{ required: true, message: 'Escreva o parecer.' }]}><Input.TextArea rows={5} maxLength={10000} /></Form.Item>
        </Form>
      </Modal>

      <Modal title="Decidir a contestação" open={decidir !== null} onCancel={() => setDecidir(null)} okText="Decidir" cancelText="Cancelar" confirmLoading={accao.isPending} onOk={() => formD.submit()} destroyOnHidden>
        <Alert type="info" showIcon style={{ marginBottom: 12 }} message="Decide a chefia da chefia avaliadora; o RH só decide quando ela não existe (o servidor verifica)." />
        <Form form={formD} layout="vertical" onFinish={(v) => decidir && accao.mutate({ metodo: 'post', url: `/rh/avaliacao/avaliacoes/${decidir.id}/decidir-contestacao`, dados: v })}>
          <Form.Item name="resultado" label="Resultado" rules={[{ required: true }]}><Select options={[{ value: 'MANTIDA', label: 'Mantida' }, { value: 'ALTERADA', label: 'Alterada' }]} /></Form.Item>
          {resDecisao === 'ALTERADA' && <Form.Item name="nota" label="Nova nota (1 a 5)" rules={[{ required: true }]}><InputNumber min={1} max={5} step={0.01} style={{ width: '100%' }} /></Form.Item>}
          <Form.Item name="justificacao" label="Justificação" rules={[{ required: true }]}><Input.TextArea rows={4} maxLength={10000} /></Form.Item>
        </Form>
      </Modal>

      <Modal title={`Resultado 360º — ${resultado ? colaboradores.nome(resultado.colaborador_id) : ''}`} open={resultado !== null} width={larguraModal(720)} onCancel={() => setResultado(null)} footer={<Button onClick={() => setResultado(null)}>Fechar</Button>}>
        {r360.isLoading || !res ? <Card loading variant="borderless" /> : (
          <>
            <Descriptions size="small" column={COLUNAS_DESCRICOES} bordered style={{ marginBottom: 12 }}>
              {Object.entries(res.respostas ?? {}).map(([g, n]) => <Descriptions.Item key={g} label={`Respostas — ${ROTULO_GRUPO[g] ?? g}`}>{n} / {res.esperadas?.[g] ?? 0}</Descriptions.Item>)}
            </Descriptions>
            {!res.liberado ? <Alert type="info" showIcon message={res.aviso ?? 'Resultados ainda não disponíveis.'} /> : (
              <>
                <Table size="small" pagination={false} scroll={scrollTabela()} rowKey="grupo" dataSource={Object.entries(res.componentes ?? {}).map(([grupo, c]) => ({ grupo, ...c }))} columns={[
                  { title: 'Grupo', dataIndex: 'grupo', render: (g: string) => ROTULO_GRUPO[g] ?? g },
                  { title: 'Respostas', dataIndex: 'n', align: 'right' },
                  { title: 'Peso', dataIndex: 'peso', align: 'right' },
                  { title: 'Média', dataIndex: 'media', align: 'right', render: (v: number | null) => (v !== null ? formatarNumero(v) : '—') },
                ]} />
                <Typography.Title level={5} style={{ marginTop: 12 }}>Nota 360º: {res.nota !== null && res.nota !== undefined ? formatarNumero(res.nota) : '—'} {res.classificacao ? `(${res.classificacao})` : ''}</Typography.Title>
                {(res.avisos ?? []).length > 0 && <Alert type="warning" showIcon message={res.avisos?.join(' ')} />}
                {(res.comentarios ?? []).length > 0 && <ul>{res.comentarios?.map((c, i) => <li key={i}>{c}</li>)}</ul>}
              </>
            )}
          </>
        )}
      </Modal>
    </Card>
  );
}

function Participantes({ ciclo }: { ciclo: Ciclo360 }) {
  const colaboradores = useColaboradores();
  if (!ciclo.participantes?.length) return <Card><Empty description="A composição é fotografada ao abrir o ciclo." /></Card>;
  type Participante = NonNullable<Ciclo360['participantes']>[number];
  const colunas: ColunaApi<Participante>[] = [
        { title: 'Colaborador', dataIndex: 'nome' },
        { title: 'Chefia', dataIndex: 'chefia_id', render: (v: number | null) => colaboradores.nome(v) },
        { title: 'Pares', dataIndex: 'pares', render: (v: number[]) => v?.map((x) => colaboradores.nome(x)).join(', ') || '—' },
        { title: 'Subordinados', dataIndex: 'subordinados', render: (v: number[]) => v?.length ?? 0 },
  ];
  return (
    <Card>
      <BarraFiltros style={{ marginBottom: 12 }} accoes={<BotoesExportar obterPedido={() => pedidoTabela({ titulo: 'Participantes do ciclo 360º', subtitulo: ciclo.nome ?? `${ciclo.periodo} ${ciclo.ano}`, colunas, linhas: ciclo.participantes ?? [] })} />}>{null}</BarraFiltros>
      <Table size="small" rowKey="employee_id" dataSource={ciclo.participantes} pagination={{ pageSize: 50 }} scroll={scrollTabela()} columns={colunas} />
    </Card>
  );
}

function Bonificacoes({ ciclo }: { ciclo: Ciclo360 }) {
  const { pode } = useSessao();
  const colaboradores = useColaboradores();
  const q = useQuery({ queryKey: ['rh', 'avaliacao', 'bonificacoes', ciclo.id], queryFn: () => obter<Bonificacao[]>(`/rh/avaliacao/ciclos/${ciclo.id}/bonificacoes`) });
  useAvisarErro(q.error);
  const accao = useAccaoRh();
  const lista = q.data ?? [];
  const metodo = ciclo.bonificacao?.metodo ?? 'NENHUM';
  const colunas: ColunaApi<Bonificacao>[] = [
        { title: 'Colaborador', dataIndex: 'colaborador_id', render: (v: number) => colaboradores.nome(v) },
        { title: 'Classificação', dataIndex: 'classificacao' },
        { title: 'Nota', dataIndex: 'nota', align: 'right', render: (v: string | null) => (v ? formatarNumero(v) : '—') },
        { title: 'Base', dataIndex: 'base', align: 'right', responsive: ['md'], render: (v: string | null) => (v ? formatarKz(v) : '—') },
        { title: 'Valor', dataIndex: 'valor', align: 'right', render: (v: string) => <strong>{formatarKz(v)}</strong>, totalImpressao: (ls) => formatarKz(somar(ls.map((b) => b.valor))) },
        { title: 'Estado', dataIndex: 'estado', render: (e: string) => <EstadoTag estado={e} /> },
        {
          title: '',
          key: 'accoes',
          render: (_, b) => b.estado !== 'PROPOSTA' && pode('rh_aval_bonus_aprovar') && (
            <Button size="small" danger onClick={() => post(`/rh/avaliacao/bonificacoes/${b.id}/anular`, 'Anular a bonificação?', 'Volta a proposta (e sai do processamento, se ainda aberto).')}>Anular</Button>
          ),
        },
  ];
  const post = (url: string, titulo: string, texto: string) => Modal.confirm({ title: titulo, content: texto, okText: 'Confirmar', cancelText: 'Cancelar', onOk: () => accao.mutateAsync({ metodo: 'post', url }) });

  return (
    <Card>
      {metodo === 'NENHUM' && <Alert type="info" showIcon style={{ marginBottom: 12 }} message="Este ciclo não tem bonificação configurada." />}
      <Flex gap={8} wrap justify="space-between" style={{ marginBottom: 12 }}>
        <Typography.Text>Total: <strong>{formatarKz(somar(lista.map((b) => b.valor)), true)}</strong> · {lista.length} proposta(s)</Typography.Text>
        <Space wrap>
          <BotoesExportar desactivado={!lista.length} obterPedido={() => pedidoTabela({ titulo: 'Bonificações da avaliação de desempenho', subtitulo: ciclo.nome ?? `${ciclo.periodo} ${ciclo.ano}`, colunas, linhas: lista, totais: 'Total' })} />
          {pode('rh_aval_bonus_calcular') && metodo !== 'NENHUM' && <Button onClick={() => post(`/rh/avaliacao/ciclos/${ciclo.id}/bonificacoes/calcular`, 'Calcular as propostas?', 'Só entram avaliações na fase Final e participantes do ciclo.')}>Calcular propostas</Button>}
          {pode('rh_aval_bonus_aprovar') && lista.some((b) => b.estado === 'PROPOSTA') && <Button type="primary" onClick={() => post(`/rh/avaliacao/ciclos/${ciclo.id}/bonificacoes/aprovar`, 'Aprovar as propostas?', 'Quem calculou não aprova; ninguém aprova o seu próprio bónus.')}>Aprovar</Button>}
          {pode('rh_aval_bonus_lancar') && lista.some((b) => b.estado === 'APROVADA') && <Button onClick={() => post(`/rh/avaliacao/ciclos/${ciclo.id}/bonificacoes/lancar`, 'Lançar no processamento?', `Lança no processamento de ${ciclo.bonificacao?.mes_lancamento ?? '—'} (tem de estar aberto).`)}>Lançar no processamento</Button>}
        </Space>
      </Flex>
      <Table<Bonificacao> rowKey="id" size="small" loading={q.isFetching} dataSource={lista} pagination={{ pageSize: 50 }} scroll={scrollTabela()} columns={colunas} />
    </Card>
  );
}
