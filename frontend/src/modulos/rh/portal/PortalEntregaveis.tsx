import { Alert, Button, Card, Col, DatePicker, Empty, Form, Input, InputNumber, Modal, Row, Select, Space, Table, Tag, Typography } from 'antd';
import { useQuery } from '@tanstack/react-query';
import dayjs, { type Dayjs } from 'dayjs';
import { useState } from 'react';
import { IconeFa } from '@/componentes/fluxos';
import { larguraModal, scrollTabela } from '@/componentes/responsivo';
import { dataApi, formatarData } from '@/utilitarios/formatacao';
import { CHAVE_PORTAL_ENTREGAVEIS, meusEntregaveis, reenviarEntregaPortal, registarEntregaPortal, registarImpedimentoPortal } from '@/modulos/estrutura/tarefas/api';
import { LigacaoAnexo, TagImpedimento, TagValidacao, useMutacao } from '@/modulos/estrutura/tarefas/comum';
import { FormularioImpedimento } from '@/modulos/estrutura/tarefas/Impedimentos';
import { ESTADO_PERIODO, fq, nomeMetrica, normalizarUrl, pctMeta, prazoTexto } from '@/modulos/estrutura/tarefas/regras';
import type { DadosExecucao, DadosImpedimento, Execucao, MeuEntregavel, ReferenciaNome } from '@/modulos/estrutura/tarefas/tipos';
import { useAvisarErro } from '../comum/consultas';

interface CamposEntrega {
  data: Dayjs;
  quantidade: number;
  url_evidencia?: string;
  observacoes?: string;
  colaborador_id?: number;
}

/**
 * Portal › Entregáveis (portal_ui.js desenharEntregaveis, 1000-1090): os entregáveis que me estão atribuídos e os das
 * unidades por que respondo; registar a entrega (o próprio, ou o responsável em nome de quem executa), reenviar uma
 * entrega devolvida e reportar que não há condições de executar. A validação é sempre de outra pessoa.
 */
export function PortalEntregaveis() {
  const q = useQuery({ queryKey: CHAVE_PORTAL_ENTREGAVEIS, queryFn: meusEntregaveis, retry: false });
  useAvisarErro(q.error, 'Não foi possível carregar os entregáveis');
  const [entregar, setEntregar] = useState<MeuEntregavel | null>(null);
  const [impedir, setImpedir] = useState<MeuEntregavel | null>(null);
  const [formE] = Form.useForm<CamposEntrega>();
  const [formI] = Form.useForm();
  const entrega = useMutacao((p: { id: number; d: DadosExecucao }) => registarEntregaPortal(p.id, p.d), { aoSucesso: () => setEntregar(null) });
  const reenviar = useMutacao((id: number) => reenviarEntregaPortal(id));
  const impedimento = useMutacao((p: { id: number; d: DadosImpedimento }) => registarImpedimentoPortal(p.id, p.d), { aoSucesso: () => setImpedir(null) });
  const lista = Array.isArray(q.data?.entregaveis) ? q.data.entregaveis : [];
  const c = q.data?.contadores;

  return (
    <Card title={<Space><IconeFa nome="cube" />Os meus entregáveis</Space>} loading={q.isLoading}>
      <Typography.Paragraph type="secondary">
        Entregáveis que lhe estão atribuídos e os das unidades por que responde. Registe aqui o que foi entregue — pelo próprio ou, sendo responsável, por quem executa. <b>A validação é sempre de outra pessoa</b>: quem lança não valida o que lançou. Só depois de validado conta para o subsídio de produtividade.
      </Typography.Paragraph>
      {c && (c.devolvidas > 0 || c.recusadas > 0) && (
        <Alert type="warning" showIcon style={{ marginBottom: 12 }} title={`${c.devolvidas ? `${c.devolvidas} entrega(s) devolvida(s) para corrigir` : ''}${c.devolvidas && c.recusadas ? ' · ' : ''}${c.recusadas ? `${c.recusadas} recusada(s)` : ''}`} />
      )}
      {!lista.length && !q.isLoading && <Empty description="Não tem entregáveis atribuídos nem unidades por que responda." />}
      {lista.map((e) => {
        const r = e.resumo;
        const pct = r ? pctMeta(r.realizado, r.meta ?? e.meta) : null;
        const texto = prazoTexto(e, e.tarefa.periodicidade);
        // o servidor pode enviar a unidade orgânica em `unidade_organica`, `unidade_nome` ou (por colisão com a unidade de medida) em `unidade`
        const unidadeOrg = e.unidade_organica?.nome ?? e.unidade_nome ?? (typeof e.unidade === 'object' && e.unidade ? (e.unidade as ReferenciaNome).nome : null);
        const medida = typeof e.unidade === 'string' ? e.unidade : '';
        return (
          <Card key={e.id} size="small" style={{ marginBottom: 12 }} title={<span><b>{e.nome}</b> <Typography.Text type="secondary" style={{ fontWeight: 400, fontSize: '0.8rem' }}>{[unidadeOrg, e.tarefa.codigo ? `${e.tarefa.codigo} · ${e.tarefa.nome}` : e.tarefa.nome].filter(Boolean).join(' › ')}</Typography.Text></span>}
            extra={<Space size={4} wrap>
              {e.papel === 'RESPONSAVEL' && <Tag color="geekblue" title="Responde por este entregável (responsável da unidade ou de uma acima)"><IconeFa nome="user-shield" /> responde</Tag>}
              {r && <Tag color={ESTADO_PERIODO[r.estado]?.cor}>{ESTADO_PERIODO[r.estado]?.rotulo ?? r.estado}</Tag>}
            </Space>}>
            <Row gutter={16}>
              <Col xs={24} md={14}>
                <div style={{ fontSize: '0.82rem', color: '#475569' }}>{nomeMetrica(e.metrica)} em <b>{medida}</b>{e.item_produtividade_id ? ' · remunerado' : ''}</div>
                {r?.limite && texto && (
                  <div style={{ fontSize: '0.82rem', marginTop: 4, color: r.estado === 'EM_ATRASO' ? '#b91c1c' : '#b45309' }}>
                    <IconeFa nome="calendar-day" /> <b>Prazo: {formatarData(r.limite)}</b> ({texto}){r.estado === 'EM_ATRASO' ? <> — <b>ultrapassado</b></> : null}{e.paga_fora_prazo ? '' : <> · <b>fora do prazo não é pago</b></>}
                  </div>
                )}
                {e.tarefa.url_instrucoes && <div style={{ fontSize: '0.82rem', marginTop: 4 }}><LigacaoAnexo url={e.tarefa.url_instrucoes} rotulo="Instruções da tarefa" icone="book" semAnexo={null} /></div>}
                {e.descricao && <div style={{ fontSize: '0.8rem', color: '#64748b', marginTop: 4 }}>{e.descricao}</div>}
              </Col>
              <Col xs={24} md={10} style={{ textAlign: 'right' }}>
                <div style={{ fontSize: '1.2rem', fontWeight: 700 }}>{fq(r?.realizado ?? 0)} <small style={{ fontWeight: 400, color: '#64748b' }}>{medida}</small></div>
                {r && <div style={{ fontSize: '0.78rem', color: '#64748b' }}>período {formatarData(r.periodo_de)} a {formatarData(r.periodo_ate)}</div>}
                {e.meta != null && pct != null && <div style={{ fontSize: '0.78rem', color: pct >= 100 ? '#15803d' : '#b45309' }}>{pct}% da meta de {fq(e.meta)}</div>}
                {r?.pendentes ? <div style={{ fontSize: '0.75rem', color: '#b45309' }}>{r.pendentes} entrega(s) por validar</div> : null}
                {r?.devolvidas ? <div style={{ fontSize: '0.75rem', color: '#9a3412' }}><b>{r.devolvidas} devolvida(s) para corrigir</b></div> : null}
                {r?.recusadas ? <div style={{ fontSize: '0.75rem', color: '#b91c1c' }}>{r.recusadas} recusada(s)</div> : null}
              </Col>
            </Row>
            {(e.impedimentos ?? []).length > 0 && (
              <div style={{ marginTop: 8, fontSize: '0.8rem' }}>
                {e.impedimentos!.map((i) => (
                  <div key={i.id} style={{ borderLeft: `3px solid ${i.estado === 'ACEITE' ? '#15803d' : i.estado === 'RECUSADO' ? '#b91c1c' : '#b45309'}`, padding: '2px 0 2px 8px', marginBottom: 4 }}>
                    <TagImpedimento estado={i.estado} /> período de {dayjs(i.periodo_de).format('MM/YYYY')} — {i.motivo} <LigacaoAnexo url={i.url_documento} rotulo="documento" semAnexo={null} />
                    {i.decisao_nota && <div style={{ color: '#64748b' }}>{i.decisao_nota}</div>}
                  </div>
                ))}
              </div>
            )}
            <Space wrap style={{ marginTop: 10 }}>
              <Button type="primary" icon={<IconeFa nome="paper-plane" />} onClick={() => { formE.resetFields(); formE.setFieldsValue({ data: dayjs(), colaborador_id: e.papel === 'RESPONSAVEL' ? e.colaborador_id ?? undefined : undefined }); setEntregar(e); }}>Entregar</Button>
              <Button icon={<IconeFa nome="exclamation-triangle" />} style={{ color: '#b45309' }} title="Dizer porque é que não há condições de executar neste período" onClick={() => { formI.resetFields(); setImpedir(e); }}>Não há condições de executar</Button>
              {e.papel === 'RESPONSAVEL' && e.dono && <Typography.Text type="secondary" style={{ fontSize: '0.8rem' }}>Pode registar em nome de <b>{e.dono.nome}</b>.</Typography.Text>}
            </Space>
            {e.execucoes.length > 0 && (
              <Table<Execucao> rowKey="id" size="small" dataSource={e.execucoes} pagination={false} scroll={scrollTabela()} style={{ marginTop: 10 }}
                columns={[
                  { title: 'Data', dataIndex: 'data', render: (v: string) => <span style={{ whiteSpace: 'nowrap' }}>{formatarData(v)}</span> },
                  ...(e.papel === 'RESPONSAVEL' ? [{ title: 'Quem', key: 'quem', render: (_: unknown, x: Execucao) => x.colaborador_nome ?? `#${x.colaborador_id}` }] : []),
                  { title: 'Quantidade', dataIndex: 'quantidade', align: 'right', render: (v) => <b>{fq(v)}</b> },
                  {
                    title: 'Validação', key: 'v',
                    render: (_, x) => <><TagValidacao estado={x.validacao} />{x.validacao_motivo && (x.validacao === 'DEVOLVIDA' || x.validacao === 'RECUSADA') && <div style={{ fontSize: '0.74rem', color: x.validacao === 'DEVOLVIDA' ? '#9a3412' : '#b91c1c' }}><b>{x.validacao === 'DEVOLVIDA' ? 'A corrigir:' : 'Motivo:'}</b> {x.validacao_motivo}</div>}</>,
                  },
                  { title: 'Evidência', key: 'ev', render: (_, x) => <LigacaoAnexo url={x.url_evidencia} /> },
                  { title: 'Observações', dataIndex: 'observacoes', responsive: ['md'], render: (v: string | null) => <span style={{ fontSize: '0.8rem', color: '#64748b' }}>{v ?? ''}</span> },
                  { title: '', key: 'a', render: (_, x) => x.validacao === 'DEVOLVIDA' && x.pode_reenviar !== false && <Button size="small" icon={<IconeFa nome="paper-plane" />} title="Já corrigi: voltar a submeter ao responsável" loading={reenviar.isPending} onClick={() => reenviar.mutate(x.id)}>Reenviar</Button> },
                ]} />
            )}
          </Card>
        );
      })}

      <Modal open={entregar !== null} title={`Entregar — ${entregar?.nome ?? ''}`} onCancel={() => setEntregar(null)} okText="Entregar" cancelText="Cancelar" confirmLoading={entrega.isPending} onOk={() => formE.submit()} width={larguraModal(600)} destroyOnHidden>
        <Form form={formE} layout="vertical" onFinish={(v) => entregar && entrega.mutate({ id: entregar.id, d: { quantidade: v.quantidade, data: dataApi(v.data)!, url_evidencia: normalizarUrl(v.url_evidencia), observacoes: v.observacoes?.trim() || null, colaborador_id: v.colaborador_id } })}>
          {entregar?.papel === 'RESPONSAVEL' && (
            <Form.Item name="colaborador_id" label="Quem executou" extra="Vazio: o próprio. Sendo responsável pode indicar um membro da unidade.">
              <Select allowClear placeholder="— eu próprio —" showSearch optionFilterProp="label" options={(entregar.executores ?? (entregar.dono ? [entregar.dono] : [])).map((x) => ({ value: x.id, label: x.nome }))} />
            </Form.Item>
          )}
          <Row gutter={12}>
            <Col xs={12}><Form.Item name="data" label="Data" rules={[{ required: true, message: 'Indique a data.' }]}><DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} disabledDate={(d) => d.isAfter(dayjs(), 'day')} /></Form.Item></Col>
            <Col xs={12}><Form.Item name="quantidade" label={`Quantidade (${typeof entregar?.unidade === 'string' ? entregar.unidade : ''})`} rules={[{ required: true, message: 'Indique a quantidade.' }]}><InputNumber min={0.0001} style={{ width: '100%' }} /></Form.Item></Col>
          </Row>
          <Form.Item name="url_evidencia" label="Evidência (endereço)" extra="É o que o responsável vê antes de validar."><Input maxLength={500} placeholder="https://…" /></Form.Item>
          <Form.Item name="observacoes" label="Observações"><Input.TextArea rows={2} maxLength={2000} /></Form.Item>
        </Form>
      </Modal>

      <Modal open={impedir !== null} title={`Não há condições de executar — ${impedir?.nome ?? ''}`} onCancel={() => setImpedir(null)} footer={null} width={larguraModal(600)} destroyOnHidden>
        <Typography.Paragraph type="secondary">O responsável da unidade decide. Um impedimento aceite justifica o período e retira-o dos atrasos.</Typography.Paragraph>
        <FormularioImpedimento form={formI} aGravar={impedimento.isPending} gravar={(d) => impedir && impedimento.mutate({ id: impedir.id, d })} />
      </Modal>
    </Card>
  );
}
