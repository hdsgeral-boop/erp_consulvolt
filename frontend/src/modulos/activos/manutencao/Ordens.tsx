import { Alert, Button, Card, Checkbox, Col, DatePicker, Descriptions, Empty, Form, Input, InputNumber, Modal, Row, Select, Space, Spin, Table, Tag, Typography } from 'antd';
import { useQuery } from '@tanstack/react-query';
import dayjs, { type Dayjs } from 'dayjs';
import { useEffect, useState } from 'react';
import { obter } from '@/api/cliente';
import { ErroApi } from '@/api/tipos';
import { ModalMotivo, useAccao } from '@/componentes/Accoes';
import { IconeFa } from '@/componentes/fluxos';
import { useSessao } from '@/sessao/SessaoContexto';
import { dataApi, formatarData, formatarKz } from '@/utilitarios/formatacao';
import { SeletorActivo } from '../comum/componentes';
import { useListaPaginada } from '../comum/paginacao';
import { EtiquetaOrdem, SeletorArtigo, useArmazens } from './comum';
import { destinoTexto, ESTADOS_ITEM, ordemAberta, PRIORIDADES, quantidade, resumoRequisicao, TIPOS } from './regras';
import type { DetalheOrdem, ItemOrdem, LinhaPlanoRequisicao, Ordem, ResumoManutencao, TipoManutencao } from './tipos';

const INVALIDAR = [['activos']];

/** Ordens de manutenção (areaOrdens, js/activos_manutencao_ui.js:320-378): filtros, em atraso, abrir a ordem. */
export function Ordens({ aoIrPlanos }: { aoIrPlanos: () => void }) {
  const [filtro, setFiltro] = useState('ABERTAS');
  const [pesquisa, setPesquisa] = useState('');
  const [aberta, setAberta] = useState<number | null>(null);
  const resumo = useQuery({ queryKey: ['activos', 'manutencao', 'resumo'], queryFn: () => obter<ResumoManutencao>('/ativos/manutencao/resumo') });
  const q = useListaPaginada<Ordem>(['activos', 'manutencao', 'ordens'], '/ativos/manutencao/ordens', { filtro, pesquisa: pesquisa || undefined });
  const c = resumo.data?.ordens;
  return (
    <>
      <Card style={{ marginBottom: 16 }} title={<span><IconeFa nome="clipboard-check" /> Ordens de manutenção</span>}
        extra={<Button size="small" icon={<IconeFa nome="calendar-check" />} title="As ordens abrem-se a partir dos planos" onClick={aoIrPlanos}>Abrir ordens em Planos</Button>}>
        <p style={{ marginTop: 0, color: '#64748b' }}>
          A ordem é o trabalho concreto num activo. Dentro dela lançam-se as necessidades e, num clique, o material de armazém vira <strong>requisição</strong> e os
          serviços viram <strong>pedido de compra</strong>.
        </p>
        <Space wrap>
          {[['ABERTAS', `Em aberto (${c?.ABERTAS ?? 0})`], ['CONCLUIDA', `Concluídas (${c?.CONCLUIDA ?? 0})`], ['CANCELADA', 'Canceladas'], ['TODAS', `Todas (${c?.TODAS ?? 0})`]].map(([k, r]) => (
            <Button key={k} size="small" type={filtro === k ? 'primary' : 'default'} onClick={() => setFiltro(k)}>{r}</Button>
          ))}
          <Input.Search size="small" allowClear placeholder="Ordem, activo, responsável" onSearch={setPesquisa} style={{ width: 240 }} />
          {!!c?.EM_ATRASO && <Tag color="red" icon={<IconeFa nome="exclamation-triangle" />}>{c.EM_ATRASO} em atraso</Tag>}
        </Space>
      </Card>
      <Card>
        <Table<Ordem> rowKey="id" size="middle" loading={q.isFetching} dataSource={q.itens} pagination={q.paginacao} scroll={{ x: 'max-content' }}
          locale={{ emptyText: 'Sem ordens para este filtro. As ordens abrem-se no separador Planos.' }}
          onRow={(o) => ({ onDoubleClick: () => setAberta(o.id) })}
          columns={[
            { title: 'Ordem', dataIndex: 'numero', render: (v) => <strong>{v}</strong> },
            { title: 'Activo', key: 'a', render: (_, o) => `${o.ativo_imobilizado?.codigo ?? ''} — ${o.ativo_imobilizado?.descricao ?? ''}` },
            { title: 'Tipo', dataIndex: 'tipo', render: (v: TipoManutencao) => TIPOS[v] ?? v },
            { title: 'Prioridade', dataIndex: 'prioridade', render: (v: keyof typeof PRIORIDADES) => <Tag color={v === 'URGENTE' || v === 'ALTA' ? 'volcano' : 'default'}>{PRIORIDADES[v] ?? v}</Tag> },
            { title: 'Prevista', key: 'p', render: (_, o) => <>{formatarData(o.data_prevista)} {o.em_atraso && <Tag color="red">em atraso</Tag>}</> },
            { title: 'Responsável', dataIndex: 'responsavel', render: (v) => v || '—' },
            { title: 'Custo', dataIndex: 'custo_total', align: 'right', render: (v) => formatarKz(v) },
            { title: 'Estado', dataIndex: 'estado', render: (v) => <EtiquetaOrdem estado={v} /> },
            { title: 'Abrir', key: 'x', align: 'right', render: (_, o) => <Button size="small" title="Abrir a ordem" icon={<IconeFa nome="folder-open" />} onClick={() => setAberta(o.id)} /> },
          ]} />
      </Card>
      <DetalheOrdemModal id={aberta} aoFechar={() => setAberta(null)} />
    </>
  );
}

/** Nova ordem para uma avaria ou trabalho pontual (formularioOrdem, UI :380-422). */
export function ModalOrdemAvulsa({ aberto, aoFechar }: { aberto: boolean; aoFechar: () => void }) {
  const [form] = Form.useForm<{ ativo_imobilizado_id: number; tipo: TipoManutencao; prioridade: string; data_prevista: Dayjs; responsavel?: string; descricao: string }>();
  const accao = useAccao({ invalidar: INVALIDAR, aoSucesso: aoFechar });
  useEffect(() => {
    if (aberto) { form.resetFields(); form.setFieldsValue({ tipo: 'CORRECTIVA', prioridade: 'NORMAL', data_prevista: dayjs() }); }
  }, [aberto, form]);
  return (
    <Modal title="Nova ordem de manutenção" open={aberto} onCancel={aoFechar} onOk={() => form.submit()} okText="Abrir ordem" cancelText="Cancelar" confirmLoading={accao.isPending} destroyOnHidden width={680}>
      <p style={{ color: '#64748b' }}>Para um trabalho que não vem de um plano (avaria, pedido pontual).</p>
      <Form form={form} layout="vertical" onFinish={(v) => accao.mutate({ url: '/ativos/manutencao/ordens', dados: { ...v, data_prevista: dataApi(v.data_prevista) } })}>
        <Form.Item name="ativo_imobilizado_id" label="Activo" rules={[{ required: true, message: 'Escolha o activo da ordem de manutenção.' }]}><SeletorActivo style={{ width: '100%' }} /></Form.Item>
        <Row gutter={12}>
          <Col span={8}><Form.Item name="tipo" label="Tipo" rules={[{ required: true }]}><Select options={Object.entries(TIPOS).map(([value, label]) => ({ value, label }))} /></Form.Item></Col>
          <Col span={8}><Form.Item name="prioridade" label="Prioridade"><Select options={Object.entries(PRIORIDADES).map(([value, label]) => ({ value, label }))} /></Form.Item></Col>
          <Col span={8}><Form.Item name="data_prevista" label="Data prevista" rules={[{ required: true, message: 'Indique a data prevista.' }]}><DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} /></Form.Item></Col>
        </Row>
        <Form.Item name="responsavel" label="Responsável"><Input maxLength={150} placeholder="Quem executa" /></Form.Item>
        <Form.Item name="descricao" label="Trabalho a fazer" rules={[{ required: true, message: 'Descreva o trabalho a fazer.' }]}><Input.TextArea rows={3} maxLength={5000} placeholder="Descreva a avaria ou o trabalho pedido" /></Form.Item>
      </Form>
    </Modal>
  );
}

/** Detalhe da ordem (detalheOrdem, UI :425-528): tarefas, necessidades, requisitar, iniciar, cancelar e concluir. */
export function DetalheOrdemModal({ id, aoFechar }: { id: number | null; aoFechar: () => void }) {
  const { pode } = useSessao();
  const gerir = pode('activos_manut_gerir');
  const requisitar = pode('activos_manut_requisitar');
  const q = useQuery({ queryKey: ['activos', 'manutencao', 'ordem', id], queryFn: () => obter<DetalheOrdem>(`/ativos/manutencao/ordens/${id}`), enabled: !!id });
  const accao = useAccao({ invalidar: INVALIDAR });
  const [item, setItem] = useState(false);
  const [assistente, setAssistente] = useState(false);
  const [concluir, setConcluir] = useState(false);
  const [cancelar, setCancelar] = useState(false);
  const o = q.data;
  const aberta = !!o && ordemAberta(o.estado);
  return (
    <Modal open={!!id} onCancel={aoFechar} width={980} footer={null} destroyOnHidden
      title={o ? <Space wrap><IconeFa nome="clipboard-check" /> Ordem {o.numero} <EtiquetaOrdem estado={o.estado} /></Space> : 'Ordem'}>
      {q.isLoading && <Spin />}
      {o && (
        <>
          <Row gutter={16}>
            <Col xs={24} md={16}>
              <Typography.Text type="secondary">
                {o.ativo_imobilizado?.codigo} — {o.ativo_imobilizado?.descricao} · {TIPOS[o.tipo] ?? o.tipo} · prevista {formatarData(o.data_prevista)}{o.responsavel ? ` · ${o.responsavel}` : ''}
              </Typography.Text>
            </Col>
            <Col xs={24} md={8} style={{ textAlign: 'right' }}>
              <div style={{ fontSize: 12, color: '#64748b', textTransform: 'uppercase' }}>Custo</div>
              <div style={{ fontSize: 18, fontWeight: 700 }}>{formatarKz(o.custo_total)}</div>
              <div style={{ fontSize: 12, color: '#64748b' }}>Material {formatarKz(o.custo_materiais)} · Serviços {formatarKz(o.custo_servicos)} · Mão-de-obra {formatarKz(o.custo_mao_obra)}</div>
            </Col>
          </Row>
          {o.descricao && <Alert style={{ margin: '12px 0' }} type="info" message={o.descricao} />}
          {o.motivo_cancelamento && <Alert style={{ margin: '12px 0' }} type="error" message={<><strong>Cancelada:</strong> {o.motivo_cancelamento}</>} />}
          {o.resolucao && <Alert style={{ margin: '12px 0' }} type="success" message={<><strong>Trabalho efectuado:</strong> {o.resolucao}{Number(o.horas) ? ` · ${Number(o.horas)} h` : ''}{o.data_fim ? ` · concluída em ${formatarData(o.data_fim)}` : ''}</>} />}
          {!!o.tarefas?.length && (
            <Card size="small" title="Tarefas" style={{ marginBottom: 12 }}>
              <Space direction="vertical">
                {o.tarefas.map((t, k) => (
                  <Checkbox key={k} checked={t.feito} disabled={!aberta || !gerir}
                    onChange={(e) => accao.mutate({ metodo: 'put', url: `/ativos/manutencao/ordens/${o.id}/tarefas`, dados: { feitas: o.tarefas!.map((x, j) => (j === k ? e.target.checked : x.feito)) } })}>
                    {t.texto}
                  </Checkbox>
                ))}
              </Space>
            </Card>
          )}
          <Card size="small" title="Necessidades" extra={aberta && (
            <Space>
              {gerir && <Button size="small" icon={<IconeFa nome="plus" />} onClick={() => setItem(true)}>Acrescentar</Button>}
              {requisitar && <Button size="small" type="primary" icon={<IconeFa nome="paper-plane" />} disabled={!o.por_pedir} onClick={() => setAssistente(true)}>
                Requisitar / pedir compra{o.por_pedir ? ` (${o.por_pedir})` : ''}</Button>}
            </Space>
          )}>
            <Table<ItemOrdem> rowKey="id" size="small" pagination={false} dataSource={o.itens} scroll={{ x: 'max-content' }} locale={{ emptyText: 'Sem necessidades lançadas.' }}
              columns={[
                { title: 'Tipo', dataIndex: 'tipo', render: (v) => <Tag color={v === 'SERVICO' ? 'purple' : 'cyan'}>{v === 'SERVICO' ? 'Serviço' : 'Material'}</Tag> },
                { title: 'Artigo / serviço', key: 'n', render: (_, i) => (i.produto ? `${i.produto.codigo ?? ''} — ${i.produto.nome}` : i.descricao ?? '—') },
                { title: 'Pedido', dataIndex: 'quantidade', align: 'right', render: quantidade },
                { title: 'Recebido', dataIndex: 'quantidade_recebida', align: 'right', render: (v) => (Number(v) ? quantidade(v) : '—') },
                { title: 'Destino', dataIndex: 'destino', render: destinoTexto },
                { title: 'Estado', dataIndex: 'estado', render: (v: ItemOrdem['estado']) => ESTADOS_ITEM[v] ?? v },
                { title: 'Custo', dataIndex: 'custo', align: 'right', render: (v) => (Number(v) ? formatarKz(v) : '—') },
                { title: '', key: 'x', render: (_, i) => (aberta && gerir && i.estado === 'POR_PEDIR'
                  ? <Button size="small" danger title="Retirar a linha" icon={<IconeFa nome="times" />} onClick={() => accao.mutate({ metodo: 'delete', url: `/ativos/manutencao/itens/${i.id}` })} /> : null) },
              ]} />
            {(!!o.requisicoes.length || !!o.pedidos_compra.length) && (
              <Descriptions size="small" column={1} style={{ marginTop: 8 }}>
                {!!o.requisicoes.length && <Descriptions.Item label="Requisições">{o.requisicoes.map((r) => `${r.numero} (${r.estado})`).join(', ')}</Descriptions.Item>}
                {!!o.pedidos_compra.length && <Descriptions.Item label="Pedidos de compra">{o.pedidos_compra.map((p) => `${p.numero_pedido ?? `#${p.id}`} (${p.estado})`).join(', ')}</Descriptions.Item>}
              </Descriptions>
            )}
          </Card>
          <Space style={{ marginTop: 16, width: '100%', justifyContent: 'space-between' }} wrap>
            <Space wrap>
              {aberta && gerir && <Button danger icon={<IconeFa nome="ban" />} onClick={() => setCancelar(true)}>Cancelar ordem</Button>}
              {gerir && (o.estado === 'PLANEADA' || o.estado === 'AGUARDA_MATERIAL') && (
                <Button icon={<IconeFa nome="play" />} loading={accao.isPending} onClick={() => accao.mutate({ url: `/ativos/manutencao/ordens/${o.id}/iniciar` })}>Iniciar</Button>
              )}
            </Space>
            <Space>
              <Button onClick={aoFechar}>Fechar</Button>
              {aberta && gerir && <Button type="primary" style={{ background: '#10b981' }} icon={<IconeFa nome="check" />} onClick={() => setConcluir(true)}>Concluir</Button>}
            </Space>
          </Space>
          <ModalItem ordemId={o.id} aberto={item} aoFechar={() => setItem(false)} />
          <AssistenteRequisicao ordem={o} aberto={assistente} aoFechar={() => setAssistente(false)} />
          <ModalConclusao ordem={o} aberto={concluir} aoFechar={() => setConcluir(false)} />
          <ModalMotivo aberto={cancelar} titulo={`Cancelar a ordem ${o.numero}`} textoOk="Cancelar ordem" carregando={accao.isPending}
            aviso="Porque é que a ordem é cancelada? Fica registado. As requisições e os pedidos de compra já emitidos não são anulados."
            aoConfirmar={(motivo) => accao.mutate({ url: `/ativos/manutencao/ordens/${o.id}/cancelar`, dados: { motivo } }, { onSuccess: () => setCancelar(false) })}
            aoFechar={() => setCancelar(false)} />
        </>
      )}
      {!q.isLoading && !o && <Empty />}
    </Modal>
  );
}

/** Acrescentar necessidade (formularioItem, UI :530-566). */
function ModalItem({ ordemId, aberto, aoFechar }: { ordemId: number; aberto: boolean; aoFechar: () => void }) {
  const [form] = Form.useForm<{ tipo: 'MATERIAL' | 'SERVICO'; produto_id: number; quantidade: number }>();
  const accao = useAccao({ invalidar: INVALIDAR, aoSucesso: aoFechar });
  useEffect(() => { if (aberto) { form.resetFields(); form.setFieldsValue({ tipo: 'MATERIAL', quantidade: 1 }); } }, [aberto, form]);
  const tipo = Form.useWatch('tipo', form);
  return (
    <Modal title="Acrescentar necessidade" open={aberto} onCancel={aoFechar} onOk={() => form.submit()} okText="Acrescentar" cancelText="Cancelar" confirmLoading={accao.isPending} destroyOnHidden>
      <Form form={form} layout="vertical" onFinish={(v) => accao.mutate({ url: `/ativos/manutencao/ordens/${ordemId}/itens`, dados: v })}>
        <Form.Item name="tipo" label="Tipo"><Select options={[{ value: 'MATERIAL', label: 'Material (sai do armazém se houver)' }, { value: 'SERVICO', label: 'Serviço (vai sempre a compras)' }]} /></Form.Item>
        <Form.Item name="produto_id" label="Artigo / serviço" rules={[{ required: true, message: 'Escolha o artigo ou serviço.' }]}><SeletorArtigo tipo={tipo} style={{ width: '100%' }} /></Form.Item>
        <Form.Item name="quantidade" label="Quantidade" rules={[{ required: true, message: 'Indique a quantidade.' }]}><InputNumber min={0.001} style={{ width: '100%' }} /></Form.Item>
      </Form>
    </Modal>
  );
}

/** Assistente: mostra para onde vai cada linha ANTES de emitir (assistenteRequisicao, UI :569-639). */
function AssistenteRequisicao({ ordem, aberto, aoFechar }: { ordem: DetalheOrdem; aberto: boolean; aoFechar: () => void }) {
  const armazens = useArmazens();
  const [armazem, setArmazem] = useState<number>();
  const [requerente, setRequerente] = useState('');
  useEffect(() => {
    if (aberto) { setRequerente(ordem.responsavel ?? ''); setArmazem((a) => a ?? armazens.data?.find((w) => w.predefinido)?.id ?? armazens.data?.[0]?.id); }
  }, [aberto, ordem, armazens.data]);
  const q = useQuery({ queryKey: ['activos', 'manutencao', 'plano-requisicao', ordem.id, armazem], enabled: aberto,
    queryFn: () => obter<LinhaPlanoRequisicao[]>(`/ativos/manutencao/ordens/${ordem.id}/plano-requisicao`, { armazem_id: armazem }) });
  const accao = useAccao({ invalidar: INVALIDAR, aoSucesso: aoFechar });
  const linhas = q.data ?? [];
  const r = resumoRequisicao(linhas);
  return (
    <Modal title={`Requisitar o que falta — ordem ${ordem.numero}`} open={aberto} onCancel={aoFechar} width={900} destroyOnHidden okText="Emitir" cancelText="Cancelar"
      okButtonProps={{ disabled: !r.armazem && !r.compra }} confirmLoading={accao.isPending}
      onOk={() => accao.mutate({ url: `/ativos/manutencao/ordens/${ordem.id}/requisitar`, dados: { armazem_id: armazem ?? null, requerente: requerente || null } })}>
      <p style={{ color: '#64748b' }}>O material que o armazém tem vira <strong>requisição</strong>; os serviços e o material em falta viram <strong>pedido de compra</strong>. Confirme antes de emitir.</p>
      {armazens.data?.length
        ? <Space style={{ marginBottom: 8 }}><span>Armazém</span><Select<number> style={{ width: 280 }} value={armazem} onChange={setArmazem} options={armazens.data.map((w) => ({ value: w.id, label: w.nome }))} /></Space>
        : <Alert type="warning" showIcon style={{ marginBottom: 8 }} message="Não há armazéns registados: tudo segue para pedido de compra." />}
      <Table<LinhaPlanoRequisicao> rowKey="item_id" size="small" pagination={false} loading={q.isFetching} dataSource={linhas}
        columns={[
          { title: 'Artigo / serviço', dataIndex: 'nome' },
          { title: 'Precisa', dataIndex: 'quantidade', align: 'right', render: quantidade },
          { title: 'Em stock', dataIndex: 'stock', align: 'right', render: (v) => (v === null ? '—' : quantidade(v)) },
          { title: 'Ao armazém', dataIndex: 'para_armazem', align: 'right', render: (v) => <strong style={{ color: '#075985' }}>{Number(v) ? quantidade(v) : '—'}</strong> },
          { title: 'A compras', dataIndex: 'para_compra', align: 'right', render: (v) => <strong style={{ color: '#92400e' }}>{Number(v) ? quantidade(v) : '—'}</strong> },
          { title: 'Porquê', dataIndex: 'porque', render: (v, l) => <small style={{ color: l.bloqueada ? '#b91c1c' : '#64748b' }}>{v}</small> },
        ]} />
      <Space style={{ marginTop: 8 }} wrap>
        <Tag color="cyan">{r.armazem} linha(s) → requisição ao armazém</Tag>
        <Tag color="gold">{r.compra} linha(s) → pedido de compra</Tag>
        {!!r.bloqueadas && <Tag color="red">{r.bloqueadas} linha(s) sem artigo ficam por pedir</Tag>}
      </Space>
      <div style={{ marginTop: 12, maxWidth: 320 }}><div>Requerente</div><Input value={requerente} maxLength={150} onChange={(e) => setRequerente(e.target.value)} /></div>
    </Modal>
  );
}

/** Conclusão (formularioConclusao, UI :641-688): se houver necessidades por receber, pede confirmação e conclui forçando. */
function ModalConclusao({ ordem, aberto, aoFechar }: { ordem: DetalheOrdem; aberto: boolean; aoFechar: () => void }) {
  const [form] = Form.useForm<{ resolucao: string; data_fim: Dayjs; horas?: number; custo_mao_obra?: number; custo_servicos?: number; leitura_contador?: number }>();
  const [modal, contexto] = Modal.useModal();
  const enviarConclusao = (forcar: boolean) => {
    const v = form.getFieldsValue();
    accao.mutate({ url: `/ativos/manutencao/ordens/${ordem.id}/concluir`, dados: { ...v, data_fim: dataApi(v.data_fim), forcar: forcar || undefined, leitura_contador: v.leitura_contador ?? null } });
  };
  const accao = useAccao({
    invalidar: INVALIDAR,
    aoSucesso: aoFechar,
    aoErro: (e) => {
      if (e instanceof ErroApi && e.codigo === 'NECESSIDADES_PENDENTES') {
        void modal.confirm({ title: 'Necessidades por receber', content: e.message, okText: 'Concluir mesmo assim', cancelText: 'Voltar', onOk: () => enviarConclusao(true) });
        return true;
      }
      return false;
    },
  });
  useEffect(() => {
    if (aberto) {
      form.resetFields();
      form.setFieldsValue({ resolucao: ordem.resolucao ?? '', data_fim: dayjs(), horas: Number(ordem.horas ?? 0), custo_mao_obra: Number(ordem.custo_mao_obra ?? 0),
        custo_servicos: Number(ordem.custo_servicos_sugerido) || Number(ordem.custo_servicos ?? 0) });
    }
  }, [aberto, ordem, form]);
  const porContador = ordem.plano_manutencao?.periodicidade?.modo === 'CONTADOR';
  return (
    <Modal title={`Concluir a ordem ${ordem.numero}`} open={aberto} onCancel={aoFechar} onOk={() => form.submit()} okText="Concluir" cancelText="Cancelar"
      okButtonProps={{ style: { background: '#10b981' } }} confirmLoading={accao.isPending} destroyOnHidden width={640}>
      {contexto}
      <Form form={form} layout="vertical" onFinish={() => enviarConclusao(false)}>
        <Form.Item name="resolucao" label="Trabalho efectuado" rules={[{ required: true, message: 'Escreva o que foi feito na manutenção.' }]}>
          <Input.TextArea rows={4} maxLength={5000} placeholder="O que foi feito, peças trocadas, observações..." />
        </Form.Item>
        <Row gutter={12}>
          <Col span={12}><Form.Item name="data_fim" label="Data de conclusão" rules={[{ required: true }]}><DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} /></Form.Item></Col>
          <Col span={12}><Form.Item name="horas" label="Horas de trabalho"><InputNumber min={0} style={{ width: '100%' }} /></Form.Item></Col>
          <Col span={12}><Form.Item name="custo_mao_obra" label="Custo de mão-de-obra (Kz)"><InputNumber min={0} precision={2} style={{ width: '100%' }} /></Form.Item></Col>
          <Col span={12}><Form.Item name="custo_servicos" label="Custo de serviços (Kz)" extra="Sugerido pelas compras ligadas a esta ordem."><InputNumber min={0} precision={2} style={{ width: '100%' }} /></Form.Item></Col>
          <Col span={24}><Form.Item name="leitura_contador" label="Leitura do contador (horas/km), se o plano for por contador"
            extra={porContador ? 'Sem leitura, o plano por contador não avança.' : undefined}><InputNumber min={0} style={{ width: '100%' }} placeholder="Deixe vazio se não se aplica" /></Form.Item></Col>
        </Row>
        <Alert type="info" message={<>Material já entregue pelo armazém: <strong>{formatarKz(ordem.custo_materiais)}</strong></>} />
      </Form>
    </Modal>
  );
}
