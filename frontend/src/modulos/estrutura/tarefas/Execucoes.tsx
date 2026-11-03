import { Alert, Button, Col, DatePicker, Drawer, Form, Input, InputNumber, Modal, Popconfirm, Row, Space, Table, Tag, Typography } from 'antd';
import type { ColumnsType } from 'antd/es/table';
import { useQuery } from '@tanstack/react-query';
import dayjs, { type Dayjs } from 'dayjs';
import { useEffect, useState } from 'react';
import { IconeFa } from '@/componentes/fluxos/IconeFa';
import { larguraGaveta, larguraModal, scrollTabela } from '@/componentes/responsivo';
import { useSessao } from '@/sessao/SessaoContexto';
import { SeletorColaborador } from '@/modulos/rh/comum/componentes';
import { useColaboradores } from '@/modulos/rh/comum/consultas';
import { dataApi, formatarData } from '@/utilitarios/formatacao';
import { actualizarExecucao, CHAVE_EXECUCOES, eliminarExecucao, execucoesDoEntregavel, registarExecucao, reenviarExecucao, reporValidacao, validarExecucao } from './api';
import { LigacaoAnexo, ModalMotivoCurto, TagValidacao, useMutacao } from './comum';
import { accoesValidacao, fq, normalizarUrl, totalValidado } from './regras';
import type { DadosExecucao, DecisaoValidacao, Entregavel, Execucao } from './tipos';

interface CamposEntrega {
  colaborador_id: number;
  data: Dayjs;
  quantidade: number;
  url_evidencia?: string;
  observacoes?: string;
}

/**
 * Entregas de um entregável (estrutura_tarefas_ui.js verExecucoes, 559-645): lista com validar / devolver / recusar / repor
 * / reenviar / editar / eliminar, e o registo de uma entrega. Quem pode validar é decidido entrega a entrega pelo servidor
 * (`pode_validar`): o responsável não valida o que ele próprio entregou.
 */
export function Execucoes({ entregavel, aoFechar }: { entregavel: Entregavel | null; aoFechar: () => void }) {
  const { pode } = useSessao();
  const editar = pode('est_editar');
  const colaboradores = useColaboradores();
  const q = useQuery({ queryKey: [...CHAVE_EXECUCOES, entregavel?.id], queryFn: () => execucoesDoEntregavel(entregavel!.id), enabled: !!entregavel, retry: false });
  const [edicao, setEdicao] = useState<Execucao | 'nova' | null>(null);
  const [motivo, setMotivo] = useState<{ execucao: Execucao; decisao: Exclude<DecisaoValidacao, 'VALIDADA'> } | null>(null);
  const [form] = Form.useForm<CamposEntrega>();
  const gravar = useMutacao((p: { id?: number; d: DadosExecucao }) => (p.id ? actualizarExecucao(p.id, p.d) : registarExecucao(entregavel!.id, p.d)), { aoSucesso: () => setEdicao(null) });
  const validar = useMutacao((p: { id: number; decisao: DecisaoValidacao; motivo?: string }) => validarExecucao(p.id, { decisao: p.decisao, motivo: p.motivo ?? null }), { aoSucesso: () => setMotivo(null) });
  const repor = useMutacao((id: number) => reporValidacao(id));
  const reenviar = useMutacao((id: number) => reenviarExecucao(id));
  const eliminar = useMutacao((id: number) => eliminarExecucao(id));
  const lista = Array.isArray(q.data) ? q.data : [];
  const nome = (x: Execucao) => x.colaborador_nome ?? colaboradores.nome(x.colaborador_id);

  useEffect(() => {
    if (!edicao) return;
    form.resetFields();
    form.setFieldsValue(edicao === 'nova'
      ? { data: dayjs(), colaborador_id: entregavel?.colaborador_id ?? undefined }
      : { colaborador_id: edicao.colaborador_id, data: dayjs(edicao.data), quantidade: Number(edicao.quantidade), url_evidencia: edicao.url_evidencia ?? undefined, observacoes: edicao.observacoes ?? undefined });
  }, [edicao, form, entregavel]);

  if (!entregavel) return null;
  const e = entregavel;
  const porValidar = lista.filter((x) => x.validacao === 'PENDENTE' && !x.convertida && x.periodo_produtividade_id == null).length;

  const colunas: ColumnsType<Execucao> = [
    { title: 'Data', dataIndex: 'data', render: (v: string) => <span style={{ whiteSpace: 'nowrap' }}>{formatarData(v)}</span> },
    { title: 'Quem', key: 'quem', render: (_, x) => nome(x) },
    { title: 'Quantidade', dataIndex: 'quantidade', align: 'right', render: (v) => <><b>{fq(v)}</b> {e.unidade}</> },
    {
      title: 'Validação', key: 'val',
      render: (_, x) => (
        <>
          <TagValidacao estado={x.validacao} />
          {x.validada_por && <div style={{ fontSize: '0.74rem', color: '#64748b' }}>{x.validada_por} · {formatarData(x.validada_em)}{x.validada_como === 'PERMISSAO' ? ' · por permissão' : ''}</div>}
          {x.validacao_motivo && (x.validacao === 'DEVOLVIDA' || x.validacao === 'RECUSADA') && <div style={{ fontSize: '0.74rem', color: x.validacao === 'DEVOLVIDA' ? '#9a3412' : '#b91c1c' }}>{x.validacao_motivo}</div>}
        </>
      ),
    },
    {
      title: 'Produtividade', key: 'prod',
      render: (_, x) => (
        <Space size={6}>
          {x.convertida || x.periodo_produtividade_id != null ? <Tag color="blue">convertida</Tag> : x.validacao === 'VALIDADA' ? <Tag color="gold">por converter</Tag> : <span style={{ color: '#94a3b8' }}>—</span>}
          <LigacaoAnexo url={x.url_evidencia} rotulo="evidência" semAnexo={null} />
          {x.origem !== 'MANUAL' && <Tag>{x.origem}</Tag>}
        </Space>
      ),
    },
    { title: 'Observações', dataIndex: 'observacoes', responsive: ['md'], render: (v: string | null) => <span style={{ fontSize: '0.8rem', color: '#64748b' }}>{v ?? ''}</span> },
    {
      title: '', key: 'a', align: 'right',
      render: (_, x) => {
        const convertida = !!x.convertida || x.periodo_produtividade_id != null;
        if (convertida) return null;
        const a = accoesValidacao(x);
        return (
          <Space size={2} wrap>
            {a.validar && <Button size="small" type="text" style={{ color: '#15803d' }} title="Validar esta entrega" aria-label={`Validar entrega ${x.id}`} icon={<IconeFa nome="check" />} onClick={() => validar.mutate({ id: x.id, decisao: 'VALIDADA' })} />}
            {a.devolver && <Button size="small" type="text" style={{ color: '#9a3412' }} title="Devolver para correcção: volta a quem entregou, com o que tem de melhorar" aria-label={`Devolver entrega ${x.id}`} icon={<IconeFa nome="undo" />} onClick={() => setMotivo({ execucao: x, decisao: 'DEVOLVIDA' })} />}
            {a.recusar && <Button size="small" type="text" danger title="Recusar esta entrega" aria-label={`Recusar entrega ${x.id}`} icon={<IconeFa nome="ban" />} onClick={() => setMotivo({ execucao: x, decisao: 'RECUSADA' })} />}
            {a.repor && <Button size="small" type="text" title="Repor por validar, sem nota" aria-label={`Repor entrega ${x.id}`} icon={<IconeFa nome="eraser" />} onClick={() => repor.mutate(x.id)} />}
            {x.pode_reenviar && x.validacao === 'DEVOLVIDA' && <Button size="small" title="Já corrigi: voltar a submeter ao responsável" icon={<IconeFa nome="paper-plane" />} onClick={() => reenviar.mutate(x.id)}>Reenviar</Button>}
            {!x.pode_validar && x.validacao === 'PENDENTE' && <span title={x.motivo_nao_valida || 'Não pode validar esta entrega (quem entrega ou regista não valida)'} style={{ color: '#cbd5e1' }}><IconeFa nome="lock" /></span>}
            {editar && <Button size="small" type="text" title="Editar (volta a ficar por validar)" aria-label={`Editar entrega ${x.id}`} icon={<IconeFa nome="edit" />} onClick={() => setEdicao(x)} />}
            {editar && (
              <Popconfirm title="Eliminar esta entrega?" okText="Eliminar" cancelText="Cancelar" okButtonProps={{ danger: true }} onConfirm={() => eliminar.mutateAsync(x.id)}>
                <Button size="small" type="text" danger aria-label={`Eliminar entrega ${x.id}`} icon={<IconeFa nome="trash" />} />
              </Popconfirm>
            )}
          </Space>
        );
      },
    },
  ];

  return (
    <Drawer open title={`Entregas — ${e.nome}`} onClose={aoFechar} width={larguraGaveta(980)} destroyOnHidden
      extra={editar && <Button type="primary" icon={<IconeFa nome="plus-circle" />} onClick={() => setEdicao('nova')}>Registar entrega</Button>}>
      <Alert type="warning" showIcon style={{ marginBottom: 12 }}
        title={<><b>Só entregas validadas vão para a produtividade.</b>{porValidar ? <> <b style={{ color: '#b45309' }}>{porValidar} por validar.</b></> : null}</>}
        description="Quem valida é o responsável da unidade ou de uma unidade acima (ou quem tenha a tarefa «validar entregas»). Quem entregou ou registou a entrega nunca a valida." />
      <Table<Execucao> rowKey="id" size="small" loading={q.isFetching} dataSource={lista} columns={colunas} pagination={lista.length > 50 ? { pageSize: 50 } : false} scroll={scrollTabela()}
        locale={{ emptyText: 'Ainda não há entregas registadas.' }}
        summary={() => lista.length ? (
          <Table.Summary.Row>
            <Table.Summary.Cell index={0} colSpan={2}><b>Total validado</b></Table.Summary.Cell>
            <Table.Summary.Cell index={2} align="right"><b>{fq(totalValidado(lista))}</b> {e.unidade}</Table.Summary.Cell>
            <Table.Summary.Cell index={3} colSpan={4} />
          </Table.Summary.Row>
        ) : null} />

      <Modal open={edicao !== null} title={edicao === 'nova' ? `Registar entrega — ${e.nome}` : 'Editar entrega'} onCancel={() => setEdicao(null)} okText="Gravar" cancelText="Cancelar"
        confirmLoading={gravar.isPending} onOk={() => form.submit()} width={larguraModal(640)} destroyOnHidden>
        {edicao !== 'nova' && <Alert type="info" showIcon style={{ marginBottom: 12 }} title="Editar uma entrega repõe-na por validar." />}
        <Form form={form} layout="vertical" onFinish={(v) => gravar.mutate({
          id: edicao && edicao !== 'nova' ? edicao.id : undefined,
          d: { colaborador_id: v.colaborador_id, quantidade: v.quantidade, data: dataApi(v.data)!, url_evidencia: normalizarUrl(v.url_evidencia), observacoes: v.observacoes?.trim() || null },
        })}>
          <Form.Item name="colaborador_id" label="Quem executou" rules={[{ required: true, message: 'Escolha quem executou.' }]}><SeletorColaborador apenasActivos style={{ width: '100%' }} /></Form.Item>
          <Row gutter={[16, 0]}>
            <Col xs={12}><Form.Item name="data" label="Data" rules={[{ required: true, message: 'Indique a data.' }]}><DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} disabledDate={(d) => d.isAfter(dayjs(), 'day')} /></Form.Item></Col>
            <Col xs={12}><Form.Item name="quantidade" label={`Quantidade entregue (${e.unidade})`} rules={[{ required: true, message: 'Indique a quantidade.' }]}><InputNumber min={0.0001} style={{ width: '100%' }} /></Form.Item></Col>
          </Row>
          <Form.Item name="url_evidencia" label="Evidência da entrega (endereço)" extra="Relatório, folha de obra, fotografia, mapa assinado — é o que o responsável vê antes de validar."><Input maxLength={500} placeholder="https://…" /></Form.Item>
          <Form.Item name="observacoes" label="Observações"><Input.TextArea rows={2} maxLength={2000} /></Form.Item>
        </Form>
      </Modal>

      <ModalMotivoCurto aberto={motivo !== null} perigo={motivo?.decisao === 'RECUSADA'} carregando={validar.isPending}
        titulo={motivo?.decisao === 'DEVOLVIDA' ? 'Devolver para correcção' : 'Recusar a entrega'}
        mensagem={motivo?.decisao === 'DEVOLVIDA' ? 'O que tem de ser melhorado ou corrigido? É isto que quem entregou vai ler no portal.' : 'Porque é que esta entrega não é aceite? Ao contrário da devolução, uma recusa não volta a contar.'}
        rotulo={motivo?.decisao === 'DEVOLVIDA' ? 'O que corrigir' : 'Motivo'} textoOk={motivo?.decisao === 'DEVOLVIDA' ? 'Devolver' : 'Recusar'}
        aoFechar={() => setMotivo(null)} aoConfirmar={(m) => motivo && validar.mutate({ id: motivo.execucao.id, decisao: motivo.decisao, motivo: m })} />
      {q.isError && <Typography.Text type="danger">Não foi possível carregar as entregas.</Typography.Text>}
    </Drawer>
  );
}
