import { Alert, AutoComplete, Button, Checkbox, Col, DatePicker, Flex, Form, Input, InputNumber, Modal, Row, Select, Space, Table, Tag, Typography } from 'antd';
import { ClearOutlined, SnippetsOutlined, ThunderboltOutlined } from '@ant-design/icons';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import dayjs from 'dayjs';
import { useEffect, useMemo, useState } from 'react';
import { enviar, obter } from '@/api/cliente';
import { useAccao } from '@/componentes/Accoes';
import { larguraModal, scrollTabela } from '@/componentes/responsivo';
import { notificarErro } from '@/utilitarios/erros';
import { dataApi, formatarData } from '@/utilitarios/formatacao';
import { useEquipa, useOrganigrama, useWbs } from '../../comum/componentes';
import { achatarWbs } from '../../comum/regras';
import { formatarQtd, interpretarColagem, partesIguais, prazoRepartido, UNIDADES } from '../../comum/regrasMetas';
import type { LinhaFolhaMedicao, ResultadoLote, TarefaWbs } from '../../comum/tipos';

/**
 * Execução medida (Versão Actual, js/projectos_metas.js): registar a quantidade executada de uma tarefa (grade), a folha
 * de medição com várias tarefas de uma vez (linhas, com «colar do Excel») e o assistente «Tarefas por quantidade».
 * As regras e a gravação são do servidor; aqui só se recolhe e se mostra o resultado.
 */

const useFolha = (projectoId: number, activo: boolean) =>
  useQuery({ queryKey: ['projectos', 'folha-medicao', projectoId], queryFn: () => obter<LinhaFolhaMedicao[]>(`/projetos/${projectoId}/medicoes/folha`), enabled: activo });

/** Registar execução de uma tarefa (projMetaRegistar, :405-459). */
export function ModalMedicao({ projectoId, tarefa, aoFechar, aoLinhas }: { projectoId: number; tarefa: TarefaWbs | null; aoFechar: () => void; aoLinhas: () => void }) {
  const [form] = Form.useForm<{ quantidade: number; data: dayjs.Dayjs; nota?: string }>();
  const folha = useFolha(projectoId, !!tarefa);
  const linha = folha.data?.find((l) => l.id === tarefa?.id);
  const accao = useAccao({ invalidar: [['projectos']], aoSucesso: () => aoFechar() });
  useEffect(() => {
    if (!tarefa) return;
    form.resetFields();
    const falta = Number(tarefa.meta_quantidade ?? 0) - Number(tarefa.meta_realizado ?? 0);
    form.setFieldsValue({ data: dayjs(), quantidade: falta > 0 ? Math.round(falta * 1000) / 1000 : undefined });
  }, [tarefa, form]);
  const meta = Number(tarefa?.meta_quantidade ?? 0);
  const feito = Number(tarefa?.meta_realizado ?? 0);
  const un = tarefa?.meta_unidade || 'un';
  return (
    <Modal
      title={`Registar execução — ${tarefa?.codigo ? `${tarefa.codigo} ` : ''}${tarefa?.nome ?? ''}`}
      open={!!tarefa} onCancel={aoFechar} onOk={() => form.submit()} okText="Registar" cancelText="Cancelar" confirmLoading={accao.isPending} destroyOnHidden width={larguraModal(560)}
      footer={(orig) => <Flex justify="space-between" wrap gap={8}><Button onClick={() => { aoFechar(); aoLinhas(); }}>Folha de medição (várias tarefas)</Button><Space>{orig}</Space></Flex>}
    >
      <Alert type="info" showIcon style={{ marginBottom: 12 }}
        message={<>Meta: <strong>{formatarQtd(meta)} {un}</strong> · realizado <strong>{formatarQtd(feito)}</strong> · falta <strong>{formatarQtd(Math.max(0, meta - feito))}</strong></>} />
      <Form form={form} layout="vertical" onFinish={(v) => accao.mutate({ url: `/projetos/${projectoId}/tarefas/${tarefa?.id}/medicoes`, dados: { quantidade: v.quantidade, data: dataApi(v.data), nota: v.nota || null } })}>
        <Row gutter={12}>
          <Col xs={24} md={12}>
            <Form.Item name="quantidade" label={`Quantidade executada (${un})`} extra="Negativa para corrigir um registo a mais." rules={[{ required: true, message: 'Indique a quantidade.' }]}>
              <InputNumber style={{ width: '100%' }} decimalSeparator="," />
            </Form.Item>
          </Col>
          <Col xs={24} md={12}>
            <Form.Item name="data" label="Data" rules={[{ required: true }]}><DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} disabledDate={(d) => d.isAfter(dayjs(), 'day')} /></Form.Item>
          </Col>
        </Row>
        <Form.Item name="nota" label="Nota"><Input maxLength={500} placeholder="Frente, turno, medição…" /></Form.Item>
      </Form>
      {(linha?.medicoes.length ?? 0) > 0 && (
        <Table size="small" rowKey={(m) => `${m.em}-${m.q}`} pagination={false} scroll={{ y: 180 }} dataSource={linha?.medicoes}
          columns={[
            { title: 'Data', dataIndex: 'data', render: formatarData },
            { title: 'Qtd.', dataIndex: 'q', align: 'right', render: (v) => <strong>{formatarQtd(v)}</strong> },
            { title: 'Nota', dataIndex: 'nota' },
            { title: 'Por', dataIndex: 'por' },
          ]} />
      )}
    </Modal>
  );
}

/** Folha de medição: uma linha por tarefa com meta (projMetasRegistarEmMassa, :468-628). */
export function ModalFolhaMedicao({ projectoId, aberto, aoFechar, destacar }: { projectoId: number; aberto: boolean; aoFechar: () => void; destacar?: number | null }) {
  const folha = useFolha(projectoId, aberto);
  const equipa = useEquipa(projectoId);
  const cliente = useQueryClient();
  const [data, setData] = useState(dayjs());
  const [nota, setNota] = useState('');
  const [qtd, setQtd] = useState<Record<number, number | null>>({});
  const [notas, setNotas] = useState<Record<number, string>>({});
  const [falhas, setFalhas] = useState<Record<number, string>>({});
  const [colar, setColar] = useState(false);
  const [aGravar, setAGravar] = useState(false);
  useEffect(() => { if (aberto) { setQtd({}); setNotas({}); setFalhas({}); setData(dayjs()); setNota(''); } }, [aberto]);
  const linhas = folha.data ?? [];
  const nomeMembro = useMemo(() => new Map((equipa.data?.membros ?? []).map((m) => [m.id, m.nome])), [equipa.data]);
  const preenchidas = linhas.filter((l) => Number(qtd[l.id] ?? 0) !== 0);
  const porUnidade = preenchidas.reduce<Record<string, number>>((a, l) => ({ ...a, [l.unidade]: (a[l.unidade] ?? 0) + Number(qtd[l.id]) }), {});
  const acima = preenchidas.filter((l) => Number(qtd[l.id]) > l.falta && l.falta >= 0).length;

  const gravar = () => Modal.confirm({
    title: 'Registar medições',
    content: `${preenchidas.length} linha(s) em ${data.format('DD/MM/YYYY')}. Cada uma é gravada com as mesmas regras do registo individual; as que falharem ficam assinaladas para corrigir.`,
    okText: 'Registar', cancelText: 'Cancelar',
    onOk: async () => {
      setAGravar(true);
      try {
        const r = await enviar<ResultadoLote>('post', `/projetos/${projectoId}/medicoes/lote`, {
          data: dataApi(data), nota: nota || null, linhas: preenchidas.map((l) => ({ tarefa_id: l.id, quantidade: qtd[l.id], nota: notas[l.id] || null })),
        });
        const novas: Record<number, string> = {};
        r.dados.falhadas.forEach((f) => { novas[f.tarefa_id] = f.erro; });
        setFalhas(novas);
        setQtd((q) => Object.fromEntries(Object.entries(q).filter(([id]) => novas[Number(id)])));
        void cliente.invalidateQueries({ queryKey: ['projectos'] });
        if (r.dados.falhadas.length) Modal.warning({ title: 'Folha de medição', content: r.mensagem });
        else { Modal.success({ title: 'Folha de medição', content: r.mensagem }); aoFechar(); }
      } catch (e) {
        notificarErro(e, 'Não foi possível registar as medições');
      } finally {
        setAGravar(false);
      }
    },
  });

  return (
    <Modal title="Folha de medição" open={aberto} onCancel={aoFechar} width={larguraModal(1080)} destroyOnHidden
      footer={[<Button key="c" onClick={aoFechar}>Fechar</Button>, <Button key="g" type="primary" disabled={!preenchidas.length} loading={aGravar} onClick={gravar}>Registar medições</Button>]}>
      <Typography.Paragraph type="secondary">Uma linha por tarefa com meta. Escreva só as que mediu — as linhas em branco são ignoradas.</Typography.Paragraph>
      <Flex gap={8} wrap align="end" style={{ marginBottom: 12 }}>
        <div><Typography.Text strong style={{ display: 'block', fontSize: 12 }}>Data da medição</Typography.Text>
          <DatePicker format="DD/MM/YYYY" value={data} allowClear={false} onChange={(d) => d && setData(d)} disabledDate={(d) => d.isAfter(dayjs(), 'day')} /></div>
        <div style={{ flex: '1 1 14rem' }}><Typography.Text strong style={{ display: 'block', fontSize: 12 }}>Nota comum</Typography.Text>
          <Input value={nota} onChange={(e) => setNota(e.target.value)} maxLength={500} placeholder="Frente, turno, auto de medição…" /></div>
        <Button icon={<ThunderboltOutlined />} onClick={() => setQtd(Object.fromEntries(linhas.filter((l) => l.falta > 0).map((l) => [l.id, l.falta])))}>Preencher o que falta</Button>
        <Button icon={<SnippetsOutlined />} onClick={() => setColar(true)}>Colar do Excel</Button>
        <Button icon={<ClearOutlined />} onClick={() => { setQtd({}); setNotas({}); }}>Limpar</Button>
      </Flex>
      <Table<LinhaFolhaMedicao> size="small" rowKey="id" loading={folha.isFetching} dataSource={linhas} pagination={false} scroll={scrollTabela('52vh')}
        rowClassName={(l) => (falhas[l.id] ? 'erp-linha-erro' : l.id === destacar ? 'ant-table-row-selected' : '')}
        locale={{ emptyText: 'Este projecto não tem tarefas com meta por quantidade. Defina metas ou use «Tarefas por quantidade».' }}
        columns={[
          { title: 'Tarefa', key: 't', render: (_, l) => <>{l.fase && <Typography.Text type="secondary" style={{ fontSize: 11, display: 'block' }}>{l.fase}</Typography.Text>}<strong>{l.codigo}</strong> {l.nome}{falhas[l.id] && <Typography.Text type="danger" style={{ display: 'block', fontSize: 12 }}>{falhas[l.id]}</Typography.Text>}</> },
          { title: 'Responsável', key: 'r', render: (_, l) => (l.atribuido_a_id ? nomeMembro.get(l.atribuido_a_id) ?? `#${l.atribuido_a_id}` : '—') },
          { title: 'Meta', key: 'm', align: 'right', render: (_, l) => `${formatarQtd(l.meta)} ${l.unidade}` },
          { title: 'Realizado', dataIndex: 'realizado', align: 'right', render: (v) => formatarQtd(v) },
          { title: 'Falta', dataIndex: 'falta', align: 'right', render: (v: number) => <Typography.Text type={v > 0 ? 'warning' : 'success'}>{formatarQtd(Math.max(0, v))}</Typography.Text> },
          { title: 'A registar', key: 'q', width: 130, render: (_, l) => <InputNumber size="small" style={{ width: '100%' }} value={qtd[l.id] ?? null} onChange={(v) => setQtd((q) => ({ ...q, [l.id]: v }))} decimalSeparator="," aria-label={`Quantidade de ${l.nome}`} /> },
          { title: 'Nota da linha', key: 'n', width: 170, render: (_, l) => <Input size="small" value={notas[l.id] ?? ''} onChange={(e) => setNotas((n) => ({ ...n, [l.id]: e.target.value }))} maxLength={500} /> },
        ]} />
      <Typography.Paragraph type="secondary" style={{ marginTop: 8 }}>
        {preenchidas.length
          ? <>{preenchidas.length} linha(s) a registar: {Object.entries(porUnidade).map(([u, v]) => <Tag key={u}>{formatarQtd(v)} {u}</Tag>)}{acima > 0 && <Typography.Text type="warning"> · {acima} linha(s) acima do que falta para a meta</Typography.Text>}</>
          : 'Nenhuma linha preenchida.'}
      </Typography.Paragraph>
      <ModalColar aberto={colar} aoFechar={() => setColar(false)} aoAplicar={(texto) => {
        const r = interpretarColagem(texto, linhas);
        setQtd((q) => ({ ...q, ...Object.fromEntries(r.quantidades) }));
        setColar(false);
        if (r.naoReconhecidas.length) Modal.warning({ title: 'Colagem parcial', content: `${r.quantidades.size} linha(s) aplicada(s). Não reconhecidas: ${r.naoReconhecidas.slice(0, 8).join(', ')}.` });
      }} />
    </Modal>
  );
}

function ModalColar({ aberto, aoFechar, aoAplicar }: { aberto: boolean; aoFechar: () => void; aoAplicar: (texto: string) => void }) {
  const [texto, setTexto] = useState('');
  useEffect(() => { if (aberto) setTexto(''); }, [aberto]);
  return (
    <Modal title="Colar medições" open={aberto} onCancel={aoFechar} onOk={() => aoAplicar(texto)} okText="Aplicar" cancelText="Cancelar" destroyOnHidden>
      <Typography.Paragraph type="secondary">Duas colunas: <strong>código ou nome da tarefa</strong> e <strong>quantidade</strong> (separadas por tabulação, «|» ou «;»). Aceita 1 234,56 e 1.234,56.</Typography.Paragraph>
      <Input.TextArea rows={8} value={texto} onChange={(e) => setTexto(e.target.value)} style={{ fontFamily: 'monospace' }} placeholder={'1.1\t120\n1.2\t85,5'} />
    </Modal>
  );
}

interface Parte { nome: string; quantidade: number; responsavel?: string; nomeTocado?: boolean; qtdTocada?: boolean }

/** Assistente «Tarefas por quantidade» (projMetasEmMassa, :683-838). */
export function ModalTarefasQuantidade({ projectoId, aberto, aoFechar, inicial }: { projectoId: number; aberto: boolean; aoFechar: () => void; inicial?: { marco?: number | null; pai?: number | null } }) {
  const w = useWbs(projectoId);
  const equipa = useEquipa(projectoId);
  const org = useOrganigrama(projectoId);
  const [form] = Form.useForm();
  const [partes, setPartes] = useState<Parte[]>([]);
  const nome = Form.useWatch('nome', form) as string | undefined;
  const total = Number(Form.useWatch('total', form) ?? 0);
  const n = Number(Form.useWatch('n', form) ?? 1);
  const unidade = (Form.useWatch('unidade', form) as string | undefined) || 'un';
  const accao = useAccao({ invalidar: [['projectos']], aoSucesso: () => aoFechar() });
  useEffect(() => {
    if (!aberto) return;
    form.resetFields();
    form.setFieldsValue({ destino: inicial?.pai ? `sub:${inicial.pai}` : `raiz:${inicial?.marco ?? ''}`, n: 3, inicio: dayjs(), repartir: true });
    setPartes([]);
  }, [aberto, form, inicial]);
  // refaz as partes quando muda o total, o número ou o nome; o que o utilizador escreveu à mão mantém-se (refazer, :749-771)
  useEffect(() => {
    const qs = partesIguais(total, n);
    setPartes((ant) => qs.map((q, i) => ({
      nome: ant[i]?.nomeTocado ? ant[i].nome : `${nome || 'Parte'} ${i + 1}/${qs.length}`, nomeTocado: ant[i]?.nomeTocado,
      quantidade: ant[i]?.qtdTocada ? ant[i].quantidade : q, qtdTocada: ant[i]?.qtdTocada, responsavel: ant[i]?.responsavel,
    })));
  }, [total, n, nome]);
  const soma = Math.round(partes.reduce((s, p) => s + Number(p.quantidade || 0), 0) * 100) / 100;
  const opcoesResp = [
    ...(equipa.data?.membros?.length ? [{ label: 'Equipa', options: equipa.data.membros.map((m) => ({ value: `m:${m.id}`, label: `${m.nome}${m.papel ? ` (${m.papel})` : ''}` })) }] : []),
    ...(org.data?.posicoes?.length ? [{ label: 'Organigrama do projecto', options: org.data.posicoes.map((p) => ({ value: `n:${p.id}`, label: p.titulo })) }] : []),
  ];
  const fases = (w.data?.grupos ?? []).filter((g) => g.marco).map((g) => ({ value: `raiz:${g.marco!.id}`, label: g.marco!.nome }));
  const tarefas = achatarWbs(w.data).map((t) => ({ value: `sub:${t.id}`, label: `${'— '.repeat(t.nivel)}${t.codigo ? `${t.codigo} ` : ''}${t.nome}` }));
  const v = Form.useWatch([], form) as { inicio?: dayjs.Dayjs; fim?: dayjs.Dayjs; repartir?: boolean } | undefined;
  const prazos = v?.repartir && v.inicio && v.fim ? prazoRepartido(v.inicio.format('YYYY-MM-DD'), v.fim.format('YYYY-MM-DD'), partes.length || 1) : null;

  return (
    <Modal title="Criar tarefas por quantidade" open={aberto} onCancel={aoFechar} onOk={() => form.submit()} okText="Criar tarefas" cancelText="Cancelar" confirmLoading={accao.isPending}
      width={larguraModal(820)} destroyOnHidden>
      <Typography.Paragraph type="secondary">Indique a meta total e em quantas partes se divide. Cada parte nasce como tarefa com a sua meta, o seu prazo e o seu responsável — pessoa ou posição do organigrama.</Typography.Paragraph>
      <Form form={form} layout="vertical" onFinish={(d) => {
        const [modo, ref] = String(d.destino || 'raiz:').split(':');
        accao.mutate({ url: `/projetos/${projectoId}/tarefas/por-quantidade`, dados: {
          nome: d.nome, unidade: d.unidade, total: d.total, marco_projeto_id: modo === 'raiz' && ref ? Number(ref) : null, tarefa_pai_id: modo === 'sub' ? Number(ref) : null,
          data_inicio: dataApi(d.inicio) ?? null, data_fim: dataApi(d.fim) ?? null, repartir_prazo: !!d.repartir,
          partes: partes.map((p) => ({ nome: p.nome, quantidade: p.quantidade, responsavel: p.responsavel || null })),
        } });
      }}>
        <Row gutter={12}>
          <Col xs={24} md={12}><Form.Item name="destino" label="Onde entra"><Select options={[{ label: 'Tarefa principal da fase', options: [{ value: 'raiz:', label: 'Sem fase' }, ...fases] }, { label: 'Subtarefa de', options: tarefas }]} /></Form.Item></Col>
          <Col xs={24} md={12}><Form.Item name="nome" label="Designação base" rules={[{ required: true, message: 'Indique a designação base.' }]}><Input maxLength={150} placeholder="Ex.: Pavimentação" /></Form.Item></Col>
          <Col xs={8}><Form.Item name="total" label="Meta total" rules={[{ required: true, message: 'Indique a meta total.' }]}><InputNumber min={0} style={{ width: '100%' }} decimalSeparator="," /></Form.Item></Col>
          <Col xs={8}><Form.Item name="unidade" label="Unidade" rules={[{ required: true, message: 'Indique a unidade.' }]}><AutoComplete options={UNIDADES.map((u) => ({ value: u }))} placeholder="m²" /></Form.Item></Col>
          <Col xs={8}><Form.Item name="n" label="Em quantas partes"><InputNumber min={1} max={60} precision={0} style={{ width: '100%' }} /></Form.Item></Col>
          <Col xs={12}><Form.Item name="inicio" label="Início"><DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} /></Form.Item></Col>
          <Col xs={12}><Form.Item name="fim" label="Fim"><DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} /></Form.Item></Col>
        </Row>
        <Form.Item name="repartir" valuePropName="checked" style={{ marginTop: -8 }}><Checkbox>Repartir o prazo pelas partes, em sequência</Checkbox></Form.Item>
      </Form>
      <Table<Parte> size="small" rowKey={(_, i) => String(i)} pagination={false} dataSource={partes} scroll={{ y: 260 }}
        columns={[
          { title: 'Parte', key: 'n', render: (_, p, i) => <Input size="small" value={p.nome} maxLength={160} onChange={(e) => setPartes((ps) => ps.map((x, k) => (k === i ? { ...x, nome: e.target.value, nomeTocado: true } : x)))} /> },
          { title: `Quantidade (${unidade})`, key: 'q', width: 150, render: (_, p, i) => <InputNumber size="small" min={0} style={{ width: '100%' }} value={p.quantidade} decimalSeparator="," onChange={(q) => setPartes((ps) => ps.map((x, k) => (k === i ? { ...x, quantidade: Number(q ?? 0), qtdTocada: true } : x)))} /> },
          { title: 'Responsável', key: 'r', width: 230, render: (_, p, i) => <Select size="small" allowClear style={{ width: '100%' }} placeholder="— sem responsável —" value={p.responsavel} options={opcoesResp} onChange={(r) => setPartes((ps) => ps.map((x, k) => (k === i ? { ...x, responsavel: r } : x)))} /> },
          { title: 'Prazo', key: 'p', width: 170, render: (_, _p, i) => (prazos?.[i] ? `${formatarData(prazos[i].inicio)} a ${formatarData(prazos[i].fim)}` : '—') },
        ]} />
      <Typography.Text type={Math.abs(soma - total) < 0.005 ? 'success' : 'warning'} style={{ display: 'block', marginTop: 8 }}>
        Soma das partes: <strong>{formatarQtd(soma)} {unidade}</strong>{Math.abs(soma - total) < 0.005 ? ' — bate certo com a meta.' : ` — ${soma > total ? 'a mais' : 'a menos'} ${formatarQtd(Math.abs(soma - total))} face à meta de ${formatarQtd(total)}.`}
      </Typography.Text>
    </Modal>
  );
}
