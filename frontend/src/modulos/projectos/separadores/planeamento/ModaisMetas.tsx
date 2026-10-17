import { Alert, AutoComplete, Button, Checkbox, Col, DatePicker, Flex, Form, Input, InputNumber, Modal, Row, Select, Space, Table, Tag, Typography } from 'antd';
import type { ColumnsType } from 'antd/es/table';
import { ClearOutlined, DownloadOutlined, SnippetsOutlined, ThunderboltOutlined, UploadOutlined } from '@ant-design/icons';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import dayjs from 'dayjs';
import { useEffect, useMemo, useState } from 'react';
import { descarregar, enviar, obter } from '@/api/cliente';
import { ImportarExcel } from '@/modulos/rh/comum/ImportarExcel';
import { useAccao } from '@/componentes/Accoes';
import { larguraModal, scrollTabela } from '@/componentes/responsivo';
import { notificarErro } from '@/utilitarios/erros';
import { dataApi, formatarData } from '@/utilitarios/formatacao';
import { useEquipa, useOrganigrama, useWbs } from '../../comum/componentes';
import { achatarWbs } from '../../comum/regras';
import { formatarQtd, interpretarColagem, partesIguais, prazoRepartido, UNIDADES } from '../../comum/regrasMetas';
import type { LinhaFolhaMedicao, ResultadoLote, TarefaWbs } from '../../comum/tipos';
import { agruparPorPessoa, chaveLinha, linhasALancar, linhasPorPessoa, medidoPorPessoa, type LinhaPessoa } from '../../comum/folhaMedicao';

/**
 * Execução medida (Versão Actual, js/projectos_metas.js): registar a quantidade executada de uma tarefa (grade), a folha
 * de medição com várias tarefas de uma vez (linhas, com «colar do Excel») e o assistente «Tarefas por quantidade».
 * As regras e a gravação são do servidor; aqui só se recolhe e se mostra o resultado.
 */

const useFolha = (projectoId: number, activo: boolean) =>
  useQuery({ queryKey: ['projectos', 'folha-medicao', projectoId], queryFn: () => obter<LinhaFolhaMedicao[]>(`/projetos/${projectoId}/medicoes/folha`), enabled: activo });

/** Registar execução de uma tarefa (projMetaRegistar, :405-459). */
export function ModalMedicao({ projectoId, tarefa, aoFechar, aoLinhas }: { projectoId: number; tarefa: TarefaWbs | null; aoFechar: () => void; aoLinhas: () => void }) {
  const [form] = Form.useForm<{ quantidade: number; data: dayjs.Dayjs; nota?: string; membro_id?: number }>();
  const folha = useFolha(projectoId, !!tarefa);
  const equipa = useEquipa(projectoId);
  const nomeMembro = new Map((equipa.data?.membros ?? []).map((m) => [m.id, m.nome]));
  const linha = folha.data?.find((l) => l.id === tarefa?.id);
  const accao = useAccao({ invalidar: [['projectos']], aoSucesso: () => aoFechar() });
  useEffect(() => {
    if (!tarefa) return;
    form.resetFields();
    const falta = Number(tarefa.meta_quantidade ?? 0) - Number(tarefa.meta_realizado ?? 0);
    form.setFieldsValue({ data: dayjs(), quantidade: falta > 0 ? Math.round(falta * 1000) / 1000 : undefined, membro_id: tarefa.atribuido_a_id ?? undefined });
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
      <Form form={form} layout="vertical" onFinish={(v) => accao.mutate({ url: `/projetos/${projectoId}/tarefas/${tarefa?.id}/medicoes`, dados: { quantidade: v.quantidade, data: dataApi(v.data), nota: v.nota || null, membro_id: v.membro_id ?? null } })}>
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
        <Form.Item name="membro_id" label="Executante" extra="Quem executou (membro da equipa); conta para a produtividade dessa pessoa.">
          <Select allowClear showSearch optionFilterProp="label" placeholder="—" options={(equipa.data?.membros ?? []).map((m) => ({ value: m.id, label: `${m.nome}${m.papel ? ` (${m.papel})` : ''}` }))} />
        </Form.Item>
        <Form.Item name="nota" label="Nota"><Input maxLength={500} placeholder="Frente, turno, medição…" /></Form.Item>
      </Form>
      {(linha?.medicoes.length ?? 0) > 0 && (
        <Table size="small" rowKey={(m) => `${m.em}-${m.q}`} pagination={false} scroll={{ y: 180 }} dataSource={linha?.medicoes}
          columns={[
            { title: 'Data', dataIndex: 'data', render: formatarData },
            { title: 'Qtd.', dataIndex: 'q', align: 'right', render: (v) => <strong>{formatarQtd(v)}</strong> },
            { title: 'Executante', dataIndex: 'membro_id', render: (v?: number) => (v ? nomeMembro.get(v) ?? `#${v}` : '—') },
            { title: 'Nota', dataIndex: 'nota' },
            { title: 'Por', dataIndex: 'por' },
          ]} />
      )}
    </Modal>
  );
}

/**
 * Folha de medição por pessoa (projMetasRegistarEmMassa, :468-628, com o pedido de 2026-10-16): uma linha por tarefa
 * medível e pessoa que a executa (responsável directo, ou cada membro da área do organigrama da tarefa); filtro e
 * agrupamento por pessoa; cada medição grava-se com o executante. «Baixar modelo» (Excel com as tarefas da pessoa e as
 * listas de referência) e «Importar» (simulação, erros por linha, tudo ou nada, sem duplicados) — regras no servidor.
 */
export function ModalFolhaMedicao({ projectoId, aberto, aoFechar, destacar }: { projectoId: number; aberto: boolean; aoFechar: () => void; destacar?: number | null }) {
  const folha = useFolha(projectoId, aberto);
  const equipa = useEquipa(projectoId);
  const cliente = useQueryClient();
  const [data, setData] = useState(dayjs());
  const [nota, setNota] = useState('');
  const [pessoa, setPessoa] = useState<number | null>(null);
  const [agrupar, setAgrupar] = useState(true);
  const [qtd, setQtd] = useState<Record<string, number | null>>({});
  const [notas, setNotas] = useState<Record<string, string>>({});
  const [exec, setExec] = useState<Record<string, number | null>>({});
  const [falhas, setFalhas] = useState<Record<number, string>>({});
  const [colar, setColar] = useState(false);
  const [importar, setImportar] = useState(false);
  const [aGravar, setAGravar] = useState(false);
  useEffect(() => { if (aberto) { setQtd({}); setNotas({}); setExec({}); setFalhas({}); setData(dayjs()); setNota(''); } }, [aberto]);
  const membros = useMemo(() => equipa.data?.membros ?? [], [equipa.data]);
  const nomeMembro = useMemo(() => new Map(membros.map((m) => [m.id, m.nome])), [membros]);
  const nome = (id: number) => nomeMembro.get(id) ?? `#${id}`;
  const linhas = useMemo(() => linhasPorPessoa(folha.data ?? [], pessoa), [folha.data, pessoa]);
  const grupos = useMemo(() => agruparPorPessoa(linhas, (id) => nomeMembro.get(id) ?? `#${id}`), [linhas, nomeMembro]);
  const aLancar = linhasALancar(linhas, qtd, notas, exec);
  const porUnidade = linhas.filter((l) => Number(qtd[l.chave] ?? 0) !== 0)
    .reduce<Record<string, number>>((a, l) => ({ ...a, [l.linha.unidade]: (a[l.linha.unidade] ?? 0) + Number(qtd[l.chave]) }), {});
  const acima = linhas.filter((l) => Number(qtd[l.chave] ?? 0) > l.linha.falta && l.linha.falta >= 0).length;
  const opcoesMembros = membros.map((m) => ({ value: m.id, label: `${m.nome}${m.papel ? ` (${m.papel})` : ''}` }));
  const medidoPessoa = useMemo(() => medidoPorPessoa(folha.data ?? []), [folha.data]);

  const gravar = () => Modal.confirm({
    title: 'Registar medições',
    content: `${aLancar.length} linha(s) em ${data.format('DD/MM/YYYY')}. Cada uma é gravada com as mesmas regras do registo individual e com o executante indicado; as que falharem ficam assinaladas para corrigir.`,
    okText: 'Registar', cancelText: 'Cancelar',
    onOk: async () => {
      setAGravar(true);
      try {
        const r = await enviar<ResultadoLote>('post', `/projetos/${projectoId}/medicoes/lote`, { data: dataApi(data), nota: nota || null, linhas: aLancar });
        const novas: Record<number, string> = {};
        r.dados.falhadas.forEach((f) => { novas[f.tarefa_id] = f.erro; });
        setFalhas(novas);
        setQtd((q) => Object.fromEntries(Object.entries(q).filter(([k]) => novas[Number(k.split(':')[0])])));
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

  const colunas: ColumnsType<LinhaPessoa> = [
    { title: 'Tarefa', key: 't', render: (_, { linha: l }) => <>{l.fase && <Typography.Text type="secondary" style={{ fontSize: 11, display: 'block' }}>{l.fase}</Typography.Text>}<strong>{l.codigo}</strong> {l.nome}{falhas[l.id] && <Typography.Text type="danger" style={{ display: 'block', fontSize: 12 }}>{falhas[l.id]}</Typography.Text>}</> },
    { title: 'Executante', key: 'e', width: 200, render: (_, l) => (
      <Select<number> size="small" style={{ width: '100%' }} allowClear placeholder="—" options={opcoesMembros} showSearch optionFilterProp="label"
        value={(exec[l.chave] !== undefined ? exec[l.chave] : l.membro_id) ?? undefined} onChange={(v) => setExec((x) => ({ ...x, [l.chave]: v ?? null }))}
        aria-label={`Executante de ${l.linha.nome}`} />
    ) },
    { title: 'Meta', key: 'm', align: 'right', render: (_, { linha: l }) => `${formatarQtd(l.meta)} ${l.unidade}` },
    { title: 'Realizado', key: 'rz', align: 'right', render: (_, { linha: l }) => formatarQtd(l.realizado) },
    { title: 'Falta', key: 'f', align: 'right', render: (_, { linha: l }) => <Typography.Text type={l.falta > 0 ? 'warning' : 'success'}>{formatarQtd(Math.max(0, l.falta))}</Typography.Text> },
    { title: 'A registar', key: 'q', width: 130, render: (_, l) => <InputNumber size="small" style={{ width: '100%' }} value={qtd[l.chave] ?? null} onChange={(v) => setQtd((q) => ({ ...q, [l.chave]: v }))} decimalSeparator="," aria-label={`Quantidade de ${l.linha.nome}${l.membro_id ? ` (${nome(l.membro_id)})` : ''}`} /> },
    { title: 'Nota da linha', key: 'n', width: 170, render: (_, l) => <Input size="small" value={notas[l.chave] ?? ''} onChange={(e) => setNotas((n) => ({ ...n, [l.chave]: e.target.value }))} maxLength={500} aria-label={`Nota de ${l.linha.nome}`} /> },
  ];
  const tabela = (dados: LinhaPessoa[], comCarga: boolean) => (
    <Table<LinhaPessoa> size="small" rowKey="chave" loading={comCarga && folha.isFetching} dataSource={dados} pagination={false} scroll={scrollTabela()} columns={colunas}
      rowClassName={(l) => (falhas[l.linha.id] ? 'erp-linha-erro' : l.linha.id === destacar ? 'ant-table-row-selected' : '')}
      locale={{ emptyText: pessoa ? 'Esta pessoa não tem tarefas com meta por quantidade (directas ou da sua área do organigrama).' : 'Este projecto não tem tarefas com meta por quantidade. Defina metas ou use «Tarefas por quantidade».' }} />
  );

  return (
    <Modal title="Folha de medição" open={aberto} onCancel={aoFechar} width={larguraModal(1180)} destroyOnHidden
      footer={[<Button key="c" onClick={aoFechar}>Fechar</Button>, <Button key="g" type="primary" disabled={!aLancar.length} loading={aGravar} onClick={gravar}>Registar medições</Button>]}>
      <Typography.Paragraph type="secondary">Uma linha por tarefa com meta e por pessoa que a executa. Escreva só as que mediu — as linhas em branco são ignoradas. Cada medição fica registada com o executante.</Typography.Paragraph>
      <Flex gap={8} wrap align="end" style={{ marginBottom: 8 }}>
        <div style={{ flex: '0 1 260px', minWidth: 200 }}><Typography.Text strong style={{ display: 'block', fontSize: 12 }} id="folha-pessoa">Pessoa</Typography.Text>
          <Select<number> allowClear showSearch optionFilterProp="label" style={{ width: '100%' }} placeholder="Todas as pessoas" aria-labelledby="folha-pessoa"
            value={pessoa ?? undefined} onChange={(v) => setPessoa(v ?? null)} options={opcoesMembros} /></div>
        <div><Typography.Text strong style={{ display: 'block', fontSize: 12 }}>Data da medição</Typography.Text>
          <DatePicker format="DD/MM/YYYY" value={data} allowClear={false} onChange={(d) => d && setData(d)} disabledDate={(d) => d.isAfter(dayjs(), 'day')} aria-label="Data da medição" /></div>
        <div style={{ flex: '1 1 12rem' }}><Typography.Text strong style={{ display: 'block', fontSize: 12 }}>Nota comum</Typography.Text>
          <Input value={nota} onChange={(e) => setNota(e.target.value)} maxLength={500} placeholder="Frente, turno, auto de medição…" aria-label="Nota comum" /></div>
        {!pessoa && <Checkbox checked={agrupar} onChange={(e) => setAgrupar(e.target.checked)}>Agrupar por pessoa</Checkbox>}
      </Flex>
      <Flex gap={8} wrap style={{ marginBottom: 12 }}>
        <Button icon={<ThunderboltOutlined />} onClick={() => setQtd(Object.fromEntries(linhas.filter((l) => l.linha.falta > 0 && (l.linha.pessoas.length <= 1 || pessoa)).map((l) => [l.chave, l.linha.falta])))}>Preencher o que falta</Button>
        <Button icon={<SnippetsOutlined />} onClick={() => setColar(true)}>Colar do Excel</Button>
        <Button icon={<ClearOutlined />} onClick={() => { setQtd({}); setNotas({}); setExec({}); }}>Limpar</Button>
        <Button icon={<DownloadOutlined />} onClick={() => void descarregar(`/projetos/${projectoId}/medicoes/modelo`, pessoa ? { membro_id: pessoa } : undefined,
          `Folha_Medicao${pessoa ? `_${nome(pessoa).replace(/[^\p{L}\p{N}]+/gu, '_')}` : ''}.xlsx`).catch(notificarErro)}>Baixar modelo</Button>
        <Button icon={<UploadOutlined />} onClick={() => setImportar(true)}>Importar</Button>
      </Flex>
      <div style={{ maxHeight: '52vh', overflow: 'auto' }}>
        {!pessoa && agrupar && grupos.length > 0
          ? grupos.map((g) => {
            const medido = medidoPessoa.get(g.membro_id);
            return (
              <section key={g.membro_id ?? 0} style={{ marginBottom: 12 }} aria-label={`Tarefas de ${g.nome}`}>
                <Flex gap={8} align="center" wrap style={{ margin: '4px 0' }}>
                  <Typography.Text strong>{g.nome}</Typography.Text>
                  <Tag>{g.linhas.length} tarefa(s)</Tag>
                  {medido && Object.entries(medido).map(([u, v]) => <Tag key={u} color="blue">{formatarQtd(v)} {u} medidos</Tag>)}
                  {g.membro_id !== null && <Button size="small" type="link" onClick={() => setPessoa(g.membro_id)}>Só esta pessoa</Button>}
                </Flex>
                {tabela(g.linhas, false)}
              </section>
            );
          })
          : tabela(linhas, true)}
      </div>
      <Typography.Paragraph type="secondary" style={{ marginTop: 8 }}>
        {aLancar.length
          ? <>{aLancar.length} linha(s) a registar: {Object.entries(porUnidade).map(([u, v]) => <Tag key={u}>{formatarQtd(v)} {u}</Tag>)}{acima > 0 && <Typography.Text type="warning"> · {acima} linha(s) acima do que falta para a meta</Typography.Text>}</>
          : 'Nenhuma linha preenchida.'}
      </Typography.Paragraph>
      <ModalColar aberto={colar} aoFechar={() => setColar(false)} aoAplicar={(texto) => {
        const r = interpretarColagem(texto, (folha.data ?? []).filter((l) => linhas.some((x) => x.linha.id === l.id)));
        // a quantidade vai para a primeira linha visível da tarefa (com uma pessoa escolhida, a dessa pessoa)
        const porTarefa = new Map<number, string>();
        linhas.forEach((l) => { if (!porTarefa.has(l.linha.id)) porTarefa.set(l.linha.id, l.chave); });
        setQtd((q) => ({ ...q, ...Object.fromEntries([...r.quantidades].map(([id, v]) => [porTarefa.get(id) ?? chaveLinha(id, null), v])) }));
        setColar(false);
        if (r.naoReconhecidas.length) Modal.warning({ title: 'Colagem parcial', content: `${r.quantidades.size} linha(s) aplicada(s). Não reconhecidas: ${r.naoReconhecidas.slice(0, 8).join(', ')}.` });
      }} />
      <ImportarExcel aberto={importar} titulo={`Importar folha de medição${pessoa ? ` — ${nome(pessoa)}` : ''}`} url={`/projetos/${projectoId}/medicoes/importar`}
        modelo="folha_medicao" urlModelo={`/projetos/${projectoId}/medicoes/modelo`} paramsModelo={pessoa ? { membro_id: pessoa } : undefined} ficheiroModelo="Folha_Medicao.xlsx"
        invalidar={[['projectos']]}
        ajuda="Preencha a coluna Quantidade do modelo (as linhas sem quantidade são ignoradas). Executante: nome ou NIF de um membro da equipa (vazio = responsável da tarefa); Data DD/MM/AAAA (vazia = hoje). Use primeiro «Simular»: uma linha com erro e nada é gravado; importar o mesmo ficheiro duas vezes não duplica as medições."
        aoFechar={() => setImportar(false)} />
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
