import { Alert, Button, Card, Checkbox, DatePicker, Descriptions, Form, Input, InputNumber, Modal, Popconfirm, Radio, Result, Space, Spin, Table, Timeline, Typography } from 'antd';
import { ArrowLeftOutlined, BranchesOutlined, CheckOutlined, DeleteOutlined, MergeCellsOutlined, PlusOutlined, RollbackOutlined, SaveOutlined, SendOutlined, ShareAltOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import { useEffect, useMemo, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { useSessao } from '@/sessao/SessaoContexto';
import { SeletorAux, SeletorUnidade } from '@/modulos/contab/comum/Seletores';
import { ModalMotivo, useAccao } from '@/modulos/compras/comum/accoes';
import { SeletorProjecto } from '@/modulos/activos/comum/componentes';
import { dataApi, formatarData, formatarDataHora } from '@/utilitarios/formatacao';
import { EtiquetaOrc, Kz, rotuloOrc, useRubricas } from './comum/componentes';
import { GrelhaMensal, type ValoresRubrica } from './comum/GrelhaMensal';
import { accoesOrcamento, doze, percentagensValidas, repartirPorPesos, totaisMensais } from './comum/regras';
import type { FichaOrcamento } from './comum/tipos';

/** Ficha do orçamento: valores mensais editáveis, ciclo de aprovação, versões e hierarquia (top-down / contributos). */
export function DetalheOrcamento() {
  const { id } = useParams();
  const navegar = useNavigate();
  const { pode, utilizador } = useSessao();
  const q = useQuery({ queryKey: ['orcamento', 'ficha', id], queryFn: () => obter<FichaOrcamento>(`/orcamento/orcamentos/${id}`) });
  const rub = useRubricas(q.data?.tipo);
  const [valores, setValores] = useState<Record<number, ValoresRubrica>>({});
  const [alterado, setAlterado] = useState(false);
  const [devolver, setDevolver] = useState(false);
  const [repartir, setRepartir] = useState(false);
  const [contributos, setContributos] = useState(false);
  const accao = useAccao({ invalidar: [['orcamento']] });
  const gravar = useAccao({ invalidar: [['orcamento']], aoSucesso: () => setAlterado(false) });
  const versao = useAccao<{ id: number }>({ invalidar: [['orcamento']], aoSucesso: (o) => navegar(`../${o.id}`, { relative: 'path' }) });
  const eliminar = useAccao({ invalidar: [['orcamento']], aoSucesso: () => navegar('..') });

  useEffect(() => {
    if (!q.data) return;
    setValores(Object.fromEntries(q.data.linhas.map((l) => [l.rubrica_orcamental_id, { valores: doze(l.valores), notas: l.notas }])));
    setAlterado(false);
  }, [q.data]);

  const rubricas = useMemo(() => rub.data ?? [], [rub.data]);
  if (q.isLoading) return <Spin style={{ display: 'block', margin: 48 }} />;
  if (q.error || !q.data) return <Result status="404" title="Orçamento não encontrado" extra={<Button onClick={() => navegar('..')}>Voltar</Button>} />;
  const o = q.data;
  const acc = accoesOrcamento(o, pode, utilizador?.nome_utilizador);
  const naturezas = new Map(rubricas.map((r) => [r.id, r.natureza]));
  const resultado = totaisMensais(Object.entries(valores).map(([rid, v]) => ({ valores: v.valores, natureza: naturezas.get(Number(rid)) })), true)[12];

  const guardar = () =>
    gravar.mutate({ metodo: 'put', url: `/orcamento/orcamentos/${o.id}/valores`, dados: { linhas: Object.entries(valores).map(([rid, v]) => ({ rubrica_orcamental_id: Number(rid), valores: doze(v.valores), notas: v.notas })) } });

  return (
    <>
      <CabecalhoPagina
        titulo={<Space wrap><Button type="text" icon={<ArrowLeftOutlined />} onClick={() => navegar('..')} />{o.nome ?? rotuloOrc(o.tipo)} {o.ano} · v{o.versao}<EtiquetaOrc valor={o.tipo} /><EtiquetaOrc valor={o.estado} /></Space>}
        subtitulo={o.metodo === 'BASE_ZERO' ? 'Base zero: cada rubrica com valor exige justificação (10+ caracteres)' : undefined}
        accoes={
          <>
            {acc.editarValores && <Button type="primary" icon={<SaveOutlined />} disabled={!alterado} loading={gravar.isPending} onClick={guardar}>Gravar valores</Button>}
            {acc.submeter && (
              <Popconfirm title="Submeter para aprovação?" okText="Submeter" cancelText="Cancelar" disabled={alterado} onConfirm={() => accao.mutate({ url: `/orcamento/orcamentos/${o.id}/submeter` })}>
                <Button icon={<SendOutlined />} disabled={alterado} title={alterado ? 'Grave primeiro os valores' : undefined}>Submeter</Button>
              </Popconfirm>
            )}
            {acc.aprovar && (
              <Popconfirm title="Aprovar o orçamento?" description="O aprovado anterior da mesma chave fica substituído." okText="Aprovar" cancelText="Cancelar" onConfirm={() => accao.mutate({ url: `/orcamento/orcamentos/${o.id}/aprovar` })}>
                <Button type="primary" icon={<CheckOutlined />}>Aprovar</Button>
              </Popconfirm>
            )}
            {acc.devolver && <Button danger icon={<RollbackOutlined />} onClick={() => setDevolver(true)}>Devolver</Button>}
            {acc.novaVersao && <Button icon={<BranchesOutlined />} loading={versao.isPending} onClick={() => versao.mutate({ url: `/orcamento/orcamentos/${o.id}/nova-versao` })}>Nova versão</Button>}
            {acc.hierarquia && o.abordagem === 'TOP_DOWN' && <Button icon={<ShareAltOutlined />} onClick={() => setRepartir(true)}>Repartir (top-down)</Button>}
            {acc.hierarquia && <Button icon={<PlusOutlined />} onClick={() => setContributos(true)}>Pedir contributos</Button>}
            {acc.hierarquia && o.filhos.length > 0 && (
              <Popconfirm title="Consolidar os contributos neste orçamento?" description="Soma a última versão de cada contributo." okText="Consolidar" cancelText="Cancelar" onConfirm={() => accao.mutate({ url: `/orcamento/orcamentos/${o.id}/consolidar` })}>
                <Button icon={<MergeCellsOutlined />}>Consolidar</Button>
              </Popconfirm>
            )}
            {acc.eliminar && (
              <Popconfirm title="Eliminar este orçamento em rascunho?" okText="Eliminar" cancelText="Cancelar" okButtonProps={{ danger: true }} onConfirm={() => eliminar.mutate({ metodo: 'delete', url: `/orcamento/orcamentos/${o.id}` })}>
                <Button danger icon={<DeleteOutlined />} />
              </Popconfirm>
            )}
          </>
        }
      />
      {alterado && <Alert type="warning" showIcon style={{ marginBottom: 12 }} message="Há alterações por gravar." />}
      {(o.rejeicoes?.length ?? 0) > 0 && o.estado === 'RASCUNHO' && (
        <Alert type="error" showIcon style={{ marginBottom: 12 }} message="Devolvido para revisão"
          description={<Timeline style={{ marginTop: 8, marginBottom: -16 }} items={o.rejeicoes!.map((r) => ({ children: `${formatarDataHora(r.em)} · ${r.por ?? ''}: ${r.motivo}` }))} />} />
      )}
      <Card size="small" style={{ marginBottom: 16 }}>
        <Descriptions size="small" column={{ xs: 1, md: 4 }}>
          <Descriptions.Item label="Método">{o.metodo === 'BASE_ZERO' ? 'Base zero' : o.metodo === 'HISTORICO' ? 'Histórico' : '—'}</Descriptions.Item>
          <Descriptions.Item label="Abordagem">{o.abordagem === 'TOP_DOWN' ? 'Top-down' : o.abordagem === 'BOTTOM_UP' ? 'Bottom-up' : '—'}</Descriptions.Item>
          <Descriptions.Item label="Responsável">{o.responsavel ?? '—'}</Descriptions.Item>
          <Descriptions.Item label={o.tipo === 'EXPLORACAO' ? 'Resultado orçado' : 'Saldo orçado do ano'}><Kz valor={resultado} forte /></Descriptions.Item>
          <Descriptions.Item label="Criado por">{o.criado_por ?? '—'}</Descriptions.Item>
          <Descriptions.Item label="Submetido">{o.submetido_em ? `${o.submetido_por ?? ''} ${formatarDataHora(o.submetido_em)}` : '—'}</Descriptions.Item>
          <Descriptions.Item label="Aprovado">{o.aprovado_em ? `${o.aprovado_por ?? ''} ${formatarDataHora(o.aprovado_em)}` : '—'}</Descriptions.Item>
          {o.prazo_contributo && <Descriptions.Item label="Prazo dos contributos">{formatarData(o.prazo_contributo)}</Descriptions.Item>}
          {o.consolidado_em && <Descriptions.Item label="Consolidado">{formatarDataHora(o.consolidado_em)}</Descriptions.Item>}
          {o.orcamento_pai_id && <Descriptions.Item label="Orçamento pai"><a onClick={() => navegar(`../${o.orcamento_pai_id}`, { relative: 'path' })}>#{o.orcamento_pai_id}</a></Descriptions.Item>}
        </Descriptions>
      </Card>
      <Card size="small" title="Valores mensais" style={{ marginBottom: 16 }} loading={rub.isLoading}>
        <GrelhaMensal tipo={o.tipo} rubricas={rubricas} valores={valores} editavel={acc.editarValores} comNotas
          aoMudar={(rid, v) => { setValores((s) => ({ ...s, [rid]: v })); setAlterado(true); }} />
      </Card>
      {o.filhos.length > 0 && (
        <Card size="small" title={`Orçamentos filhos (${o.filhos.length})`}>
          <Table rowKey="id" size="small" pagination={false} dataSource={o.filhos} onRow={(f) => ({ onClick: () => navegar(`../${f.id}`, { relative: 'path' }), style: { cursor: 'pointer' } })}
            columns={[
              { title: 'Nome', dataIndex: 'nome' },
              { title: 'Versão', dataIndex: 'versao', render: (v) => `v${v}` },
              { title: 'Responsável', dataIndex: 'responsavel', render: (v) => v ?? '—' },
              { title: 'Estado', dataIndex: 'estado', render: (v) => <EtiquetaOrc valor={v} /> },
            ]} />
        </Card>
      )}
      <ModalMotivo aberto={devolver} titulo="Devolver o orçamento para revisão" textoOk="Devolver" carregando={accao.isPending} aoFechar={() => setDevolver(false)}
        aoConfirmar={(motivo) => accao.mutate({ url: `/orcamento/orcamentos/${o.id}/devolver`, dados: { motivo } }, { onSuccess: () => setDevolver(false) })} />
      <ModalRepartir orcamento={repartir ? o : null} aoFechar={() => setRepartir(false)} />
      <ModalContributos orcamento={contributos ? o : null} aoFechar={() => setContributos(false)} />
    </>
  );
}

/** Top-down: reparte o orçamento pelos filhos em rascunho (IGUAL, pelo REALIZADO anterior ou MANUAL a 100 %). */
function ModalRepartir({ orcamento, aoFechar }: { orcamento: FichaOrcamento | null; aoFechar: () => void }) {
  const [criterio, setCriterio] = useState<'IGUAL' | 'REALIZADO' | 'MANUAL'>('IGUAL');
  const [pcts, setPcts] = useState<Record<number, number>>({});
  const accao = useAccao({ invalidar: [['orcamento']], aoSucesso: () => aoFechar() });
  const filhos = (orcamento?.filhos ?? []).filter((f) => f.estado === 'RASCUNHO');
  useEffect(() => {
    if (!orcamento) return;
    const partes = repartirPorPesos(100, filhos.map(() => 1));
    setPcts(Object.fromEntries(filhos.map((f, i) => [f.id, partes[i]])));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [orcamento]);
  const valido = criterio !== 'MANUAL' || percentagensValidas(Object.values(pcts));
  return (
    <Modal title="Repartir o orçamento pelos filhos" open={!!orcamento} onCancel={aoFechar} okText="Repartir" cancelText="Cancelar" okButtonProps={{ disabled: !filhos.length || !valido }} confirmLoading={accao.isPending}
      onOk={() => accao.mutate({ url: `/orcamento/orcamentos/${orcamento?.id}/repartir`, dados: { criterio, percentagens: criterio === 'MANUAL' ? pcts : undefined } })}>
      {!filhos.length ? <Alert type="info" showIcon message="Não há orçamentos filhos em rascunho. Peça primeiro os contributos para criar os filhos." /> : (
        <>
          <Radio.Group value={criterio} onChange={(e) => setCriterio(e.target.value)} style={{ marginBottom: 12 }}
            options={[{ value: 'IGUAL', label: 'Partes iguais' }, { value: 'REALIZADO', label: 'Pelo realizado anterior' }, { value: 'MANUAL', label: 'Percentagens' }]} />
          {criterio === 'MANUAL' && (
            <>
              {filhos.map((f) => (
                <Space key={f.id} style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 6 }}>
                  <Typography.Text>{f.nome}</Typography.Text>
                  <InputNumber min={0} max={100} precision={2} value={pcts[f.id]} onChange={(v) => setPcts((s) => ({ ...s, [f.id]: v ?? 0 }))} addonAfter="%" style={{ width: 140 }} />
                </Space>
              ))}
              <Typography.Text type={valido ? 'success' : 'danger'}>Soma: {Object.values(pcts).reduce((a, b) => a + b, 0).toFixed(2)}% {valido ? '' : '(tem de ser 100%)'}</Typography.Text>
            </>
          )}
          <Typography.Paragraph type="secondary" style={{ marginTop: 8 }}>O último filho fica com o resto do arredondamento, para a soma igualar o pai.</Typography.Paragraph>
        </>
      )}
    </Modal>
  );
}

interface LinhaContributo {
  chave: number;
  unidade_negocio_id?: number;
  centro_custo_id?: number;
  projeto_id?: number;
  responsavel?: string;
}

/** Bottom-up: cria um orçamento filho (contributo) por dimensão, com o utilizador responsável e o prazo. */
function ModalContributos({ orcamento, aoFechar }: { orcamento: FichaOrcamento | null; aoFechar: () => void }) {
  const [linhas, setLinhas] = useState<LinhaContributo[]>([]);
  const [form] = Form.useForm();
  const accao = useAccao({ invalidar: [['orcamento']], aoSucesso: () => aoFechar() });
  const dim = orcamento?.dimensao_filhos ?? 'UN';
  useEffect(() => { if (orcamento) { setLinhas([{ chave: Date.now() }]); form.resetFields(); } }, [orcamento, form]);
  const mudar = (chave: number, campo: keyof LinhaContributo, v: unknown) => setLinhas((s) => s.map((l) => (l.chave === chave ? { ...l, [campo]: v } : l)));
  const valido = linhas.length > 0 && linhas.every((l) => l.responsavel?.trim() && (dim === 'UN' ? l.unidade_negocio_id : dim === 'CC' ? l.centro_custo_id : l.projeto_id));
  return (
    <Modal title="Pedir contributos (bottom-up)" open={!!orcamento} onCancel={aoFechar} width={760} okText="Pedir" cancelText="Cancelar" okButtonProps={{ disabled: !valido }} confirmLoading={accao.isPending}
      onOk={() => {
        const v = form.getFieldsValue();
        accao.mutate({ url: `/orcamento/orcamentos/${orcamento?.id}/contributos`, dados: { filhos: linhas.map(({ chave: _c, ...l }) => ({ ...l, responsavel: l.responsavel?.trim() })), prazo: dataApi(v.prazo) ?? null, preencher: !!v.preencher } });
      }}>
      <Table<LinhaContributo>
        size="small"
        rowKey="chave"
        pagination={false}
        dataSource={linhas}
        columns={[
          {
            title: dim === 'UN' ? 'Unidade de negócio' : dim === 'CC' ? 'Centro de custo' : 'Projecto', key: 'd',
            render: (_, l) => dim === 'UN' ? <SeletorUnidade value={l.unidade_negocio_id} onChange={(v) => mudar(l.chave, 'unidade_negocio_id', v)} style={{ width: 260 }} />
              : dim === 'CC' ? <SeletorAux tabela="centros-custo" value={l.centro_custo_id} onChange={(v) => mudar(l.chave, 'centro_custo_id', v)} style={{ width: 260 }} />
              : <SeletorProjecto value={l.projeto_id} onChange={(v) => mudar(l.chave, 'projeto_id', v)} style={{ width: 260 }} />,
          },
          { title: 'Responsável (utilizador)', key: 'r', render: (_, l) => <Input value={l.responsavel} onChange={(e) => mudar(l.chave, 'responsavel', e.target.value)} maxLength={100} style={{ width: 220 }} /> },
          { title: '', key: 'x', render: (_, l) => <Button danger size="small" icon={<DeleteOutlined />} disabled={linhas.length === 1} onClick={() => setLinhas((s) => s.filter((x) => x.chave !== l.chave))} /> },
        ]}
      />
      <Button size="small" icon={<PlusOutlined />} style={{ margin: '8px 0 16px' }} onClick={() => setLinhas((s) => [...s, { chave: Date.now() }])}>Contributo</Button>
      <Form form={form} layout="inline">
        <Form.Item name="prazo" label="Prazo"><DatePicker format="DD/MM/YYYY" /></Form.Item>
        <Form.Item name="preencher" valuePropName="checked"><Checkbox>Pré-preencher com a base do pai</Checkbox></Form.Item>
      </Form>
    </Modal>
  );
}
