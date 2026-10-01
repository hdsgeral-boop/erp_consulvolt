import { Alert, Button, Card, Descriptions, Empty, Flex, Form, Input, InputNumber, List, Modal, Rate, Space, Table, Tag, Typography } from 'antd';
import { useQuery } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { obter } from '@/api/cliente';
import { formatarData, formatarNumero } from '@/utilitarios/formatacao';
import { FASES_AVALIACAO, PERIODOS_AVALIACAO, type Autoavaliacao, type MinhaAvaliacao, type ResultadoAscendente } from '../api';
import { useAccaoRh, useAvisarErro } from './consultas';

type AvaliacaoPortal = MinhaAvaliacao['avaliacoes'][number];
type Tarefa360 = MinhaAvaliacao['tarefas_360'][number];
type Feedback = MinhaAvaliacao['feedbacks'][number];

interface Resultado360 {
  componentes?: Record<string, { media: number | null; n: number; peso: number }>;
  nota?: number | null;
  classificacao?: string | null;
  comentarios?: string[];
  respostas: Record<string, number>;
  esperadas: Record<string, number>;
  liberado: boolean;
  aviso?: string;
}

const ROTULO_GRUPO: Record<string, string> = { CHEFIA: 'Chefia', AUTO: 'Autoavaliação', PARES: 'Pares', SUBORDINADOS: 'Subordinados', PARES_SUB: 'Pares e subordinados' };
const rotuloPeriodo = (ano: number, periodo: string) => `${PERIODOS_AVALIACAO.find((p) => p.value === periodo)?.label ?? periodo} ${ano}`;

/**
 * Portal › A minha avaliação (ADR-064): as minhas avaliações (tomar conhecimento, contestar, resultado 360º), o ciclo
 * aberto (comunicado, autoavaliação, avaliações 360º a fazer e avaliação ascendente da chefia) e as reuniões de
 * acompanhamento. Lê GET /rh/portal/avaliacoes; as acções usam os endpoints de /rh/avaliacao (o servidor valida).
 */
export function PortalAvaliacao() {
  const q = useQuery({ queryKey: ['rh', 'portal', 'avaliacoes'], queryFn: () => obter<MinhaAvaliacao>('/rh/portal/avaliacoes') });
  useAvisarErro(q.error);
  if (q.isLoading) return <Card loading />;
  if (!q.data) return <Card><Empty description="Não foi possível obter a sua avaliação." /></Card>;
  const d = q.data;
  return (
    <Space direction="vertical" size={16} style={{ width: '100%' }}>
      {d.ciclo_aberto && <CicloAberto dados={d} />}
      <MinhasAvaliacoes avaliacoes={d.avaliacoes} />
      {d.feedbacks.length > 0 && <Acompanhamento feedbacks={d.feedbacks} />}
    </Space>
  );
}

function CicloAberto({ dados }: { dados: MinhaAvaliacao }) {
  const c = dados.ciclo_aberto!;
  const accao = useAccaoRh();
  const [responder, setResponder] = useState<Tarefa360 | null>(null);
  const [verEquipa, setVerEquipa] = useState(false);
  return (
    <Card title={`Ciclo de avaliação em curso — ${c.nome ?? rotuloPeriodo(c.ano, c.periodo)}`} size="small"
      extra={c.prazos?.respostas_ate ? <Typography.Text type="secondary">Respostas até {formatarData(c.prazos.respostas_ate)}</Typography.Text> : null}>
      {c.comunicado?.texto && (
        <Alert type={c.comunicado_confirmado ? 'success' : 'info'} showIcon style={{ marginBottom: 16 }} message={c.comunicado.titulo ?? 'Comunicado do ciclo'}
          description={<><Typography.Paragraph style={{ whiteSpace: 'pre-wrap', marginBottom: 8 }}>{c.comunicado.texto}</Typography.Paragraph>
            {c.comunicado_confirmado ? <Tag color="green">Leitura confirmada</Tag>
              : <Button size="small" type="primary" loading={accao.isPending} onClick={() => accao.mutate({ metodo: 'post', url: `/rh/avaliacao/ciclos/${c.id}/confirmar-comunicado` })}>Confirmar a leitura</Button>}</>} />
      )}

      <Typography.Title level={5}>Autoavaliação</Typography.Title>
      <FormAutoavaliacao ano={c.ano} periodo={c.periodo} />

      <Typography.Title level={5} style={{ marginTop: 16 }}>Avaliações 360º a fazer</Typography.Title>
      {dados.tarefas_360.length === 0 ? <Typography.Text type="secondary">Não tem avaliações de colegas por fazer neste ciclo.</Typography.Text> : (
        <List size="small" bordered dataSource={dados.tarefas_360} renderItem={(t) => (
          <List.Item actions={[<Button key="r" size="small" type="primary" onClick={() => setResponder(t)}>Responder</Button>]}>
            <Space>{t.nome ?? `#${t.colaborador_avaliado_id}`}<Tag>{t.grupo === 'PARES' ? 'Par' : 'Chefia (avaliação pelo subordinado)'}</Tag></Space>
          </List.Item>
        )} />
      )}
      <Typography.Paragraph type="secondary" style={{ marginTop: 4 }}>As respostas são anónimas: quem respondeu e o que respondeu ficam guardados separadamente.</Typography.Paragraph>

      <Typography.Title level={5} style={{ marginTop: 16 }}>Avaliação da minha chefia (ascendente)</Typography.Title>
      {!dados.ascendente?.chefia_colaborador_id ? <Typography.Text type="secondary">Não tem chefia directa definida.</Typography.Text>
        : dados.ascendente.respondida ? <Tag color="green">Já avaliou a sua chefia ({dados.ascendente.chefia_nome}) neste ciclo.</Tag>
          : <FormAscendente ano={c.ano} periodo={c.periodo} chefia={dados.ascendente.chefia_nome} questoes={dados.ascendente.questoes} />}

      <div style={{ marginTop: 16 }}>
        <Button size="small" onClick={() => setVerEquipa((v) => !v)}>{verEquipa ? 'Esconder' : 'Ver'} a avaliação que a minha equipa fez de mim</Button>
        {verEquipa && <ResultadoEquipa colaborador={dados.colaborador_id} ano={c.ano} periodo={c.periodo} />}
      </div>

      <Modal360 tarefa={responder} criterios={c.criterios} aoFechar={() => setResponder(null)} />
    </Card>
  );
}

function FormAutoavaliacao({ ano, periodo }: { ano: number; periodo: string }) {
  const q = useQuery({ queryKey: ['rh', 'portal', 'autoavaliacao', ano, periodo], queryFn: () => obter<Autoavaliacao>('/rh/avaliacao/autoavaliacao', { ano, periodo }) });
  useAvisarErro(q.error);
  const [form] = Form.useForm();
  const accao = useAccaoRh();
  const a = q.data?.autoavaliacao ?? null;
  const submetida = a?.estado === 'SUBMETIDA';
  useEffect(() => {
    if (!q.data) return;
    const notas = new Map((a?.criterios ?? []).map((x) => [x.chave, x]));
    const res = new Map((a?.objetivos ?? []).map((x) => [x.chave, x]));
    form.setFieldsValue({
      criterios: q.data.criterios.map((c) => ({ chave: c.chave, nota: notas.get(c.chave)?.nota ?? 0, comentario: notas.get(c.chave)?.comentario ?? '' })),
      objetivos: q.data.objetivos.map((o) => ({ chave: o.chave, resultado: res.get(o.chave)?.resultado ?? null })),
      realizacoes: a?.realizacoes ?? '', dificuldades: a?.dificuldades ?? '', formacao: a?.formacao ?? '',
    });
  }, [q.data, a, form]);
  if (q.isLoading) return <Card loading size="small" />;
  if (!q.data) return null;
  const enviar = (submeter: boolean) => {
    const v = form.getFieldsValue(true) as { criterios: { chave: string; nota: number; comentario: string }[]; objetivos: { chave: string; resultado: number | null }[]; realizacoes: string; dificuldades: string; formacao: string };
    accao.mutate({ metodo: 'put', url: '/rh/avaliacao/autoavaliacao', dados: {
      ano, periodo, submeter,
      criterios: v.criterios.map((c) => ({ chave: c.chave, nota: c.nota || null, comentario: c.comentario || null })),
      objetivos: v.objetivos.map((o) => ({ chave: o.chave, resultado: o.resultado })),
      realizacoes: v.realizacoes || null, dificuldades: v.dificuldades || null, formacao: v.formacao || null,
    } });
  };
  return (
    <Form form={form} layout="vertical" disabled={submetida}>
      {submetida && <Alert type="success" showIcon style={{ marginBottom: 12 }} message={`Autoavaliação submetida em ${formatarData(a?.submetida_em)}.`} />}
      {q.data.criterios.length === 0 && <Typography.Text type="secondary">Sem critérios definidos para si.</Typography.Text>}
      <Form.List name="criterios">
        {(campos) => campos.map(({ key, name }) => {
          const c = q.data.criterios[name];
          return (
            <Flex key={key} gap={12} wrap align="center" style={{ marginBottom: 4 }}>
              <span style={{ minWidth: 220 }}>{c?.nome}</span>
              <Form.Item name={[name, 'nota']} style={{ marginBottom: 0 }}><Rate count={5} /></Form.Item>
              <Form.Item name={[name, 'comentario']} style={{ marginBottom: 0, flex: 1, minWidth: 200 }}><Input placeholder="Comentário (opcional)" maxLength={2000} /></Form.Item>
            </Flex>
          );
        })}
      </Form.List>
      {q.data.objetivos.length > 0 && <Typography.Text strong>Objectivos (resultado atingido, %)</Typography.Text>}
      <Form.List name="objetivos">
        {(campos) => campos.map(({ key, name }) => (
          <Flex key={key} gap={12} align="center" style={{ marginBottom: 4 }}>
            <span style={{ minWidth: 220 }}>{q.data.objetivos[name]?.nome}</span>
            <Form.Item name={[name, 'resultado']} style={{ marginBottom: 0 }}><InputNumber min={0} max={200} suffix="%" style={{ width: 120 }} /></Form.Item>
          </Flex>
        ))}
      </Form.List>
      <Form.Item name="realizacoes" label="Principais realizações (obrigatório para submeter)" style={{ marginTop: 8 }}><Input.TextArea rows={3} maxLength={10000} /></Form.Item>
      <Form.Item name="dificuldades" label="Dificuldades"><Input.TextArea rows={2} maxLength={10000} /></Form.Item>
      <Form.Item name="formacao" label="Necessidades de formação"><Input.TextArea rows={3} maxLength={2000} showCount /></Form.Item>
      {!submetida && (
        <Space>
          <Button onClick={() => enviar(false)} loading={accao.isPending} disabled={false}>Gravar rascunho</Button>
          <Button type="primary" loading={accao.isPending} disabled={false}
            onClick={() => Modal.confirm({ title: 'Submeter a autoavaliação?', content: 'Depois de submetida já não pode ser alterada.', okText: 'Submeter', cancelText: 'Cancelar', onOk: () => enviar(true) })}>Submeter</Button>
        </Space>
      )}
    </Form>
  );
}

function Modal360({ tarefa, criterios, aoFechar }: { tarefa: Tarefa360 | null; criterios: { chave: string; nome: string }[]; aoFechar: () => void }) {
  const [notas, setNotas] = useState<Record<string, number>>({});
  const [comentario, setComentario] = useState('');
  const accao = useAccaoRh(() => aoFechar());
  useEffect(() => { if (tarefa) { setNotas({}); setComentario(''); } }, [tarefa]);
  const completo = criterios.every((c) => (notas[c.chave] ?? 0) >= 1);
  return (
    <Modal title={`Avaliação 360º — ${tarefa?.nome ?? ''}`} open={tarefa !== null} onCancel={aoFechar} okText="Enviar (anónimo)" cancelText="Cancelar" okButtonProps={{ disabled: !completo }}
      confirmLoading={accao.isPending} destroyOnClose
      onOk={() => tarefa && accao.mutate({ metodo: 'post', url: '/rh/avaliacao/360/respostas', dados: {
        colaborador_avaliado_id: tarefa.colaborador_avaliado_id, notas: criterios.map((c) => ({ chave: c.chave, nota: notas[c.chave] })), comentario: comentario.trim() || null,
      } })}>
      {criterios.map((c) => (
        <Flex key={c.chave} justify="space-between" align="center" style={{ marginBottom: 8 }}>
          <span>{c.nome}</span><Rate count={5} value={notas[c.chave] ?? 0} onChange={(n) => setNotas((x) => ({ ...x, [c.chave]: n }))} />
        </Flex>
      ))}
      <Input.TextArea rows={2} maxLength={2000} placeholder="Comentário (opcional, anónimo)" value={comentario} onChange={(e) => setComentario(e.target.value)} />
      {!completo && <Typography.Text type="secondary">Dê uma nota de 1 a 5 a todos os critérios.</Typography.Text>}
    </Modal>
  );
}

function FormAscendente({ ano, periodo, chefia, questoes }: { ano: number; periodo: string; chefia: string | null; questoes: { chave: string; nome: string }[] }) {
  const [notas, setNotas] = useState<Record<string, number>>({});
  const [comentario, setComentario] = useState('');
  const accao = useAccaoRh();
  const completo = questoes.every((q) => (notas[q.chave] ?? 0) >= 1);
  return (
    <div>
      <Typography.Paragraph type="secondary">Avalie {chefia ?? 'a sua chefia directa'} (1 = discordo totalmente, 5 = concordo totalmente). A resposta é anónima e só é mostrada em conjunto, com o mínimo de respostas do ciclo.</Typography.Paragraph>
      {questoes.map((q) => (
        <Flex key={q.chave} justify="space-between" align="center" style={{ marginBottom: 6, maxWidth: 720 }}>
          <span>{q.nome}</span><Rate count={5} value={notas[q.chave] ?? 0} onChange={(n) => setNotas((x) => ({ ...x, [q.chave]: n }))} />
        </Flex>
      ))}
      <Input.TextArea rows={2} maxLength={2000} placeholder="Comentário (opcional, anónimo)" value={comentario} onChange={(e) => setComentario(e.target.value)} style={{ maxWidth: 720, marginBottom: 8 }} />
      <div><Button type="primary" disabled={!completo} loading={accao.isPending}
        onClick={() => accao.mutate({ metodo: 'post', url: '/rh/avaliacao/ascendente', dados: { ano, periodo, respostas: questoes.map((q) => ({ chave: q.chave, nota: notas[q.chave] })), comentario: comentario.trim() || null } })}>
        Enviar avaliação da chefia</Button></div>
    </div>
  );
}

function ResultadoEquipa({ colaborador, ano, periodo }: { colaborador: number; ano: number; periodo: string }) {
  const q = useQuery({ queryKey: ['rh', 'portal', 'ascendente', colaborador, ano, periodo], queryFn: () => obter<ResultadoAscendente>(`/rh/avaliacao/ascendente/${colaborador}`, { ano, periodo }), retry: false });
  useAvisarErro(q.error);
  if (q.isLoading) return <Card loading size="small" style={{ marginTop: 8 }} />;
  const r = q.data;
  if (!r) return null;
  if (!r.liberado) return <Alert style={{ marginTop: 8 }} type="info" showIcon message={`${r.respostas} resposta(s) recebida(s). Os resultados só são mostrados com pelo menos ${r.minimo} respostas e depois do prazo do ciclo.`} />;
  return (
    <Card size="small" style={{ marginTop: 8 }} title={`Média ${r.media !== null && r.media !== undefined ? formatarNumero(r.media) : '—'} (${r.respostas} respostas)`}>
      <Table size="small" pagination={false} rowKey="chave" dataSource={Object.entries(r.questoes ?? {}).map(([chave, x]) => ({ chave, ...x }))} columns={[
        { title: 'Questão', dataIndex: 'nome' },
        { title: 'Média', dataIndex: 'media', align: 'right', render: (v: number | null) => (v !== null ? formatarNumero(v) : '—') },
      ]} />
      {(r.comentarios ?? []).length > 0 && <ul style={{ marginTop: 8 }}>{r.comentarios?.map((c, i) => <li key={i}>{c}</li>)}</ul>}
    </Card>
  );
}

function MinhasAvaliacoes({ avaliacoes }: { avaliacoes: AvaliacaoPortal[] }) {
  const [conhecer, setConhecer] = useState<AvaliacaoPortal | null>(null);
  const [contestar, setContestar] = useState<AvaliacaoPortal | null>(null);
  const [ver360, setVer360] = useState<AvaliacaoPortal | null>(null);
  const [texto, setTexto] = useState('');
  const accao = useAccaoRh(() => { setConhecer(null); setContestar(null); setTexto(''); });
  return (
    <Card title="As minhas avaliações" size="small">
      <Table<AvaliacaoPortal> rowKey="id" size="small" dataSource={avaliacoes} pagination={{ pageSize: 10 }} scroll={{ x: 'max-content' }}
        locale={{ emptyText: 'Ainda não tem avaliações concluídas.' }}
        columns={[
          { title: 'Período', render: (_, a) => <strong>{rotuloPeriodo(a.ano, a.periodo)}</strong> },
          { title: 'Avaliador', dataIndex: 'avaliador', render: (v: string | null) => v ?? '—' },
          { title: 'Nota', dataIndex: 'nota_final', align: 'right', render: (v: number | null) => (v !== null ? formatarNumero(v) : '—') },
          { title: 'Classificação', dataIndex: 'classificacao_final', render: (v: string | null) => v || '—' },
          { title: 'Fase', dataIndex: 'fase', render: (f: string) => <Tag color={FASES_AVALIACAO[f]?.cor}>{FASES_AVALIACAO[f]?.rotulo ?? f}</Tag> },
          {
            title: '', key: 'acc', align: 'right',
            render: (_, a) => (
              <Space size={4}>
                {a.tem_resultado_360 && <Button size="small" onClick={() => setVer360(a)}>Resultado 360º</Button>}
                {a.pode_tomar_conhecimento && <Button size="small" type="primary" onClick={() => { setTexto(''); setConhecer(a); }}>Tomar conhecimento</Button>}
                {a.pode_contestar && <Button size="small" danger onClick={() => { setTexto(''); setContestar(a); }}>Contestar</Button>}
              </Space>
            ),
          },
        ]}
        expandable={{ expandedRowRender: (a) => <DetalheAvaliacao a={a} /> }} />

      <Modal title="Tomar conhecimento da avaliação" open={conhecer !== null} onCancel={() => setConhecer(null)} okText="Confirmar" cancelText="Cancelar" confirmLoading={accao.isPending} destroyOnClose
        onOk={() => conhecer && accao.mutate({ metodo: 'post', url: `/rh/avaliacao/avaliacoes/${conhecer.id}/conhecimento`, dados: { comentario: texto.trim() || null } })}>
        <Typography.Paragraph>Tomar conhecimento não significa concordar. A partir desta data corre o prazo de contestação.</Typography.Paragraph>
        <Input.TextArea rows={3} maxLength={2000} placeholder="Comentário (opcional)" value={texto} onChange={(e) => setTexto(e.target.value)} />
      </Modal>

      <Modal title="Contestar a avaliação" open={contestar !== null} onCancel={() => setContestar(null)} okText="Enviar contestação" cancelText="Cancelar" okButtonProps={{ danger: true, disabled: texto.trim().length < 30 }}
        confirmLoading={accao.isPending} destroyOnClose
        onOk={() => contestar && accao.mutate({ metodo: 'post', url: `/rh/avaliacao/avaliacoes/${contestar.id}/contestar`, dados: { fundamentacao: texto.trim() } })}>
        {contestar?.prazo_contestacao && <Alert type="info" showIcon style={{ marginBottom: 12 }} message={`Prazo de contestação até ${formatarData(contestar.prazo_contestacao)}.`} />}
        <Input.TextArea rows={6} maxLength={10000} showCount placeholder="Fundamente a contestação (mínimo 30 caracteres)" value={texto} onChange={(e) => setTexto(e.target.value)} />
      </Modal>

      <ModalResultado360 avaliacao={ver360} aoFechar={() => setVer360(null)} />
    </Card>
  );
}

function DetalheAvaliacao({ a }: { a: AvaliacaoPortal }) {
  return (
    <Space direction="vertical" style={{ width: '100%' }}>
      {(a.criterios ?? []).length > 0 && (
        <Table size="small" pagination={false} rowKey="chave" dataSource={a.criterios ?? []} columns={[
          { title: 'Critério', dataIndex: 'nome' },
          { title: 'Nota', dataIndex: 'nota', render: (v: number | null) => (v ? <Rate disabled count={5} value={v} /> : '—') },
          { title: 'Comentário', dataIndex: 'comentario', render: (v: string | null) => v ?? '' },
        ]} />
      )}
      {(a.objetivos ?? []).length > 0 && (
        <Table size="small" pagination={false} rowKey="chave" dataSource={a.objetivos ?? []} columns={[
          { title: 'Objectivo', dataIndex: 'descricao' },
          { title: 'Meta', render: (_, o) => (o.meta !== null ? `${formatarNumero(o.meta)} ${o.unidade ?? ''}` : '—') },
          { title: 'Atingido', dataIndex: 'atingido', render: (v: number | null) => (v !== null ? formatarNumero(v) : '—') },
          { title: 'Resultado', dataIndex: 'resultado', render: (v: number | null) => (v !== null ? `${formatarNumero(v)} %` : '—') },
        ]} />
      )}
      <Descriptions size="small" column={1} bordered>
        {a.pontos_fortes && <Descriptions.Item label="Pontos fortes">{a.pontos_fortes}</Descriptions.Item>}
        {a.pontos_melhorar && <Descriptions.Item label="A melhorar">{a.pontos_melhorar}</Descriptions.Item>}
        {a.plano_desenvolvimento && <Descriptions.Item label="Plano de desenvolvimento">{a.plano_desenvolvimento}</Descriptions.Item>}
        {a.conhecimento && <Descriptions.Item label="Conhecimento">{formatarData(a.conhecimento.em)}{a.conhecimento.comentario ? ` — ${a.conhecimento.comentario}` : ''}</Descriptions.Item>}
        {a.contestacao?.fundamentacao && <Descriptions.Item label="Contestação">{formatarData(a.contestacao.em)} — {a.contestacao.fundamentacao}</Descriptions.Item>}
        {a.contestacao?.decisao && <Descriptions.Item label="Decisão">{a.contestacao.decisao.resultado === 'ALTERADA' ? 'Alterada' : 'Mantida'} — {a.contestacao.decisao.justificacao}</Descriptions.Item>}
      </Descriptions>
    </Space>
  );
}

function ModalResultado360({ avaliacao, aoFechar }: { avaliacao: AvaliacaoPortal | null; aoFechar: () => void }) {
  const q = useQuery({ queryKey: ['rh', 'portal', 'resultado360', avaliacao?.id], queryFn: () => obter<Resultado360>(`/rh/avaliacao/avaliacoes/${avaliacao?.id}/resultado-360`), enabled: avaliacao !== null, retry: false });
  useAvisarErro(q.error);
  const r = q.data;
  return (
    <Modal title="Resultado 360º" open={avaliacao !== null} width={680} onCancel={aoFechar} footer={<Button onClick={aoFechar}>Fechar</Button>}>
      {q.isLoading || !r ? <Card loading bordered={false} /> : !r.liberado ? <Alert type="info" showIcon message={r.aviso ?? 'Resultados ainda não disponíveis.'} /> : (
        <>
          <Table size="small" pagination={false} rowKey="grupo" dataSource={Object.entries(r.componentes ?? {}).map(([grupo, c]) => ({ grupo, ...c }))} columns={[
            { title: 'Grupo', dataIndex: 'grupo', render: (g: string) => ROTULO_GRUPO[g] ?? g },
            { title: 'Respostas', dataIndex: 'n', align: 'right' },
            { title: 'Média', dataIndex: 'media', align: 'right', render: (v: number | null) => (v !== null ? formatarNumero(v) : '—') },
          ]} />
          <Typography.Title level={5} style={{ marginTop: 12 }}>Nota 360º: {r.nota !== null && r.nota !== undefined ? formatarNumero(r.nota) : '—'} {r.classificacao ? `(${r.classificacao})` : ''}</Typography.Title>
          {(r.comentarios ?? []).length > 0 && <ul>{r.comentarios?.map((c, i) => <li key={i}>{c}</li>)}</ul>}
        </>
      )}
    </Modal>
  );
}

function Acompanhamento({ feedbacks }: { feedbacks: Feedback[] }) {
  const [confirmar, setConfirmar] = useState<Feedback | null>(null);
  const [comentario, setComentario] = useState('');
  const accao = useAccaoRh(() => setConfirmar(null));
  return (
    <Card title="Reuniões de acompanhamento" size="small">
      <Table<Feedback> rowKey="id" size="small" dataSource={feedbacks} pagination={{ pageSize: 10 }} scroll={{ x: 'max-content' }}
        columns={[
          { title: 'Data', dataIndex: 'data', render: formatarData },
          { title: 'Referência', dataIndex: 'periodo_referencia' },
          { title: 'Registada por', dataIndex: 'registado_por', render: (v: string | null) => v ?? '—' },
          { title: 'Acordos', dataIndex: 'acordos', ellipsis: true, render: (v: string | null) => v ?? '—' },
          { title: '', key: 'c', align: 'right', render: (_, f) => (f.confirmacao ? <Tag color="green">Confirmada {formatarData(f.confirmacao.em)}</Tag>
            : <Button size="small" type="primary" onClick={() => { setComentario(''); setConfirmar(f); }}>Confirmar</Button>) },
        ]}
        expandable={{ expandedRowRender: (f) => (
          <Descriptions size="small" column={1}>
            <Descriptions.Item label="Pontos positivos">{f.positivos ?? '—'}</Descriptions.Item>
            <Descriptions.Item label="A melhorar">{f.melhorar ?? '—'}</Descriptions.Item>
            <Descriptions.Item label="Acordos">{f.acordos ?? '—'}</Descriptions.Item>
          </Descriptions>
        ) }} />
      <Modal title="Confirmar a reunião de acompanhamento" open={confirmar !== null} onCancel={() => setConfirmar(null)} okText="Confirmar" cancelText="Cancelar" confirmLoading={accao.isPending} destroyOnClose
        onOk={() => confirmar && accao.mutate({ metodo: 'post', url: `/rh/avaliacao/feedbacks/${confirmar.id}/confirmar`, dados: { comentario: comentario.trim() || null } })}>
        <Input.TextArea rows={3} maxLength={2000} placeholder="Comentário (opcional)" value={comentario} onChange={(e) => setComentario(e.target.value)} />
      </Modal>
    </Card>
  );
}
