import { Alert, Button, Card, Checkbox, Col, Empty, Form, InputNumber, message, Modal, Popconfirm, Row, Select, Space, Table, Tabs, Typography } from 'antd';
import { CheckCircleOutlined, DeleteOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import { useEffect, useState, type ClipboardEvent } from 'react';
import { enviar, obter } from '@/api/cliente';
import { useAccao } from '@/componentes/Accoes';
import { ColarExcelBotao } from '@/componentes/formularios/ColarExcelBotao';
import { colagemParaLinhas, type ResultadoColagemGrade } from '@/componentes/formularios/colagemGrade';
import type { CampoFormulario } from '@/componentes/formularios/tipos';
import { larguraModal, scrollTabela } from '@/componentes/responsivo';
import { formatarKz } from '@/utilitarios/formatacao';
import { useWbs } from '../comum/componentes';
import { achatarWbs, rotuloRubrica } from '../comum/regras';
import { formatarQtd } from '../comum/regrasMetas';
import type { DistribuicaoQtd, LinhaOrcamento, ResumoOrcamentoQtd } from '../comum/tipos';

/**
 * Orçamento mapeado pelas quantidades (Versão Actual, js/projectos_orcamento_qtd.js): cada rubrica pode ser mapeada,
 * numa ou em várias partes, sobre o trabalho medido (unidade, âmbito, base META/EXECUTADO, valor até ao saldo). Daí sai um
 * preço por unidade e o valor reparte-se pelas tarefas, fases, áreas e pessoas. O valor da rubrica não muda.
 */
export const useOrcamentoQtd = (projectoId: number) =>
  useQuery({ queryKey: ['projectos', 'orcamento-qtd', projectoId], queryFn: () => obter<ResumoOrcamentoQtd>(`/projetos/${projectoId}/orcamento/quantidades`) });

const BASES = [{ value: 'META', label: 'Quantidade prevista (meta)' }, { value: 'EXECUTADO', label: 'Quantidade já executada' }];

export function ModalMapearQuantidade({ projectoId, linha, aoFechar }: { projectoId: number; linha: LinhaOrcamento | null; aoFechar: () => void }) {
  const r = useOrcamentoQtd(projectoId);
  const w = useWbs(projectoId);
  const [form] = Form.useForm<{ unidade: string; base: string; escopo: string; fases?: number[]; tarefas?: number[]; valor: number }>();
  const [previsao, setPrevisao] = useState<DistribuicaoQtd | null>(null);
  const accao = useAccao({ invalidar: [['projectos']], aoSucesso: () => aoFechar() });
  const retirar = useAccao({ invalidar: [['projectos']] });
  const rub = r.data?.rubricas.find((x) => x.linha_id === linha?.id);
  const v = Form.useWatch([], form);
  useEffect(() => {
    if (!linha || !r.data) return;
    form.setFieldsValue({ unidade: r.data.unidades[0], base: 'META', escopo: 'UNIDADE', valor: rub && rub.saldo > 0 ? rub.saldo : undefined });
    setPrevisao(null);
  }, [linha, r.data, rub, form]);
  const pedido = () => v && ({ unidade: v.unidade, base: v.base, valor: v.valor,
    escopo: { tipo: v.escopo, ids: v.escopo === 'FASE' ? v.fases ?? [] : v.escopo === 'TAREFAS' ? v.tarefas ?? [] : [] } });
  // previsão no servidor (as mesmas regras da gravação) sempre que o pedido muda
  useEffect(() => {
    const p = pedido();
    if (!linha || !p?.unidade || !(Number(p.valor) > 0) || (p.escopo.tipo !== 'UNIDADE' && !p.escopo.ids.length)) { setPrevisao(null); return; }
    const t = window.setTimeout(() => {
      enviar<DistribuicaoQtd>('post', `/projetos/${projectoId}/orcamento/${linha.id}/quantidades/prever`, p).then((x) => setPrevisao(x.dados)).catch(() => setPrevisao(null));
    }, 350);
    return () => window.clearTimeout(t);
  }, [JSON.stringify(v), linha?.id]); // eslint-disable-line react-hooks/exhaustive-deps
  const tarefasDaUnidade = achatarWbs(w.data).filter((t) => (t.meta_unidade || '') === v?.unidade && Number(t.meta_quantidade ?? 0) > 0);
  const fechada = !!rub?.totalmente_mapeada;

  // Colagem do Excel (só quando «escopo = tarefas que eu escolher»): uma coluna, «Tarefa» (código ou nome) — há aqui
  // um mapa local fiável (tarefasDaUnidade) para reconhecer o texto colado; acrescenta à selecção, não a substitui
  // (não há «linhas» na grelha para sobrepor — é uma lista de marcação, não uma grelha de valores).
  const camposColagemTarefas: CampoFormulario[] = [
    { nome: 'tarefa', rotulo: 'Tarefa (código ou nome)', tipo: 'select', opcoes: tarefasDaUnidade.map((t) => ({ value: t.id, label: `${t.codigo ? `${t.codigo} ` : ''}${t.nome}` })) },
  ];
  const avisoColagemTarefas = (r: ResultadoColagemGrade, n: number) => {
    if (r.naoReconhecidos) {
      const det = Object.entries(r.exemplos).map(([k, s]) => `${k}: ${s.join(', ')}`).join('; ');
      void message.warning(`${n} tarefa(s) acrescentada(s) à selecção. ${r.naoReconhecidos} valor(es) não reconhecido(s): ${det}`);
    } else if (n) {
      void message.success(`${n} tarefa(s) acrescentada(s) à selecção.`);
    }
  };
  const colarTarefas = (texto: string) => {
    const res = colagemParaLinhas(texto, camposColagemTarefas);
    const novos = res.linhas.map((l) => l.tarefa).filter((x): x is number => typeof x === 'number');
    const actuais = (form.getFieldValue('tarefas') as number[] | undefined) ?? [];
    if (novos.length) form.setFieldValue('tarefas', Array.from(new Set([...actuais, ...novos])));
    avisoColagemTarefas(res, novos.length);
  };
  const aoColarTarefas = (e: ClipboardEvent<HTMLDivElement>) => {
    const texto = e.clipboardData?.getData('text') ?? '';
    if (!texto.trim()) return;
    e.preventDefault();
    colarTarefas(texto);
  };

  return (
    <Modal title="Mapear o orçamento pelas quantidades" open={!!linha} onCancel={aoFechar} width={larguraModal(860)} destroyOnHidden
      okText="Mapear o saldo" cancelText={fechada ? 'Fechar' : 'Cancelar'} onOk={() => form.submit()} okButtonProps={{ disabled: fechada || !previsao?.linhas.length }} confirmLoading={accao.isPending}>
      <Typography.Paragraph type="secondary">
        <strong>{rotuloRubrica(linha?.rubrica)}</strong> · rubrica de {formatarKz(linha?.montante, true)} ·{' '}
        <Typography.Text type={fechada ? 'success' : 'warning'} strong>{fechada ? 'totalmente mapeada' : `saldo por mapear ${formatarKz(rub?.saldo ?? 0, true)}`}</Typography.Text>.
        Diga a que trabalho medido corresponde este dinheiro; o valor é repartido pelas tarefas com essa quantidade.
      </Typography.Paragraph>
      {(rub?.mapas.length ?? 0) > 0 && (
        <Table size="small" rowKey="indice" pagination={false} dataSource={rub?.mapas} style={{ marginBottom: 12 }}
          columns={[
            { title: 'Já mapeado', key: 'u', render: (_, m) => `${m.unidade} · ${m.base === 'EXECUTADO' ? 'executado' : 'meta'}` },
            { title: 'Âmbito', key: 'a', render: (_, m) => (m.escopo.tipo === 'FASE' ? `${m.escopo.ids.length} fase(s)` : m.escopo.tipo === 'TAREFAS' ? `${m.escopo.ids.length} tarefa(s)` : 'Todas as tarefas da unidade') },
            { title: 'Quantidade', dataIndex: 'quantidade_mapeada', align: 'right', render: (x) => formatarQtd(x) },
            { title: 'Preço', dataIndex: 'preco_unitario', align: 'right', render: (x) => formatarKz(x) },
            { title: 'Valor', dataIndex: 'valor', align: 'right', render: (x) => <strong>{formatarKz(x)}</strong> },
            { title: '', key: 'x', render: (_, m) => (
              <Popconfirm title={`Retirar o mapeamento de ${formatarKz(m.valor, true)}? O valor volta ao saldo por mapear.`} okText="Retirar" cancelText="Cancelar" okButtonProps={{ danger: true }}
                onConfirm={() => linha && retirar.mutate({ metodo: 'delete', url: `/projetos/${projectoId}/orcamento/${linha.id}/quantidades/${m.indice}` })}>
                <Button size="small" danger icon={<DeleteOutlined />} aria-label="Retirar mapeamento" />
              </Popconfirm>
            ) },
          ]} />
      )}
      {fechada ? <Alert type="info" showIcon icon={<CheckCircleOutlined />} message="Esta rubrica está inteiramente mapeada: não há saldo. Para refazer, retire um dos mapeamentos acima." />
        : !r.data?.unidades.length ? <Empty description="Não há tarefas com quantidade atribuída. Defina metas ou use «Tarefas por quantidade»." /> : (
          <Form form={form} layout="vertical" onFinish={() => linha && accao.mutate({ url: `/projetos/${projectoId}/orcamento/${linha.id}/quantidades`, dados: pedido() })}>
            <Row gutter={12}>
              <Col xs={24} md={8}><Form.Item name="unidade" label="Unidade de medida" rules={[{ required: true }]}><Select options={r.data.unidades.map((u) => ({ value: u, label: u }))} /></Form.Item></Col>
              <Col xs={24} md={8}><Form.Item name="base" label="Repartir pela"><Select options={BASES} /></Form.Item></Col>
              <Col xs={24} md={8}><Form.Item name="valor" label="Valor a mapear (Kz)" extra={`Até ${formatarKz(rub?.saldo ?? 0, true)}.`} rules={[{ required: true, message: 'Indique o valor.' }]}>
                <InputNumber min={0} max={rub?.saldo} precision={2} style={{ width: '100%' }} /></Form.Item></Col>
            </Row>
            <Form.Item name="escopo" label="Que trabalho entra">
              <Select options={[{ value: 'UNIDADE', label: 'Todas as tarefas desta unidade' }, { value: 'FASE', label: 'Só as tarefas de certas fases' }, { value: 'TAREFAS', label: 'Só as tarefas que eu escolher' }]} />
            </Form.Item>
            {v?.escopo === 'FASE' && <Form.Item name="fases"><Checkbox.Group options={(w.data?.grupos ?? []).filter((g) => g.marco).map((g) => ({ value: g.marco!.id, label: g.marco!.nome }))} /></Form.Item>}
            {v?.escopo === 'TAREFAS' && (
              <div onPaste={aoColarTarefas}>
                <Space style={{ display: 'flex', justifyContent: 'flex-end', marginBottom: 4 }}>
                  <ColarExcelBotao colunas={camposColagemTarefas.map((c) => c.rotulo)} aoColar={colarTarefas} tamanho="small" />
                </Space>
                <Form.Item name="tarefas"><Checkbox.Group style={{ display: 'flex', flexDirection: 'column' }}
                  options={tarefasDaUnidade.map((t) => ({ value: t.id, label: `${t.codigo ? `${t.codigo} ` : ''}${t.nome} — ${formatarQtd(t.meta_quantidade)} ${t.meta_unidade} (feitos ${formatarQtd(t.meta_realizado)})` }))} /></Form.Item>
              </div>
            )}
          </Form>
        )}
      {!fechada && previsao && (
        <>
          <Alert type="info" showIcon style={{ marginBottom: 8 }} message={<><strong>{formatarQtd(previsao.quantidade)} {previsao.unidade}</strong> em {previsao.linhas.length} tarefa(s) · preço unitário <strong>{formatarKz(previsao.preco_unitario, true)}</strong>
            {(previsao.sobra ?? 0) >= 0.01 ? ` · ficam ${formatarKz(previsao.sobra, true)} por mapear` : (previsao.sobra ?? 0) < -0.009 ? ' · acima do saldo!' : ' · a rubrica fica inteiramente mapeada'}</>} />
          <Table size="small" rowKey="tarefa_id" pagination={false} dataSource={previsao.linhas} scroll={{ y: 200 }}
            columns={[{ title: 'Tarefa', key: 't', render: (_, x) => `${x.codigo ? `${x.codigo} ` : ''}${x.nome}` }, { title: 'Quantidade', dataIndex: 'quantidade', align: 'right', render: (q) => `${formatarQtd(q)} ${previsao.unidade}` },
              { title: 'Valor', dataIndex: 'valor', align: 'right', render: (x) => <strong>{formatarKz(x)}</strong> }]} />
        </>
      )}
    </Modal>
  );
}

/** «Repartição do orçamento mapeado» por tarefa, fase, área do organigrama e pessoa (orcDistribuicaoQtd, :372-480). */
export function QuadroOrcamentoQtd({ projectoId }: { projectoId: number }) {
  const r = useOrcamentoQtd(projectoId);
  if (!r.data || r.data.total_mapeado <= 0) return null;
  const tab = (dados: { nome: string; valor: number; quantidade: number }[]) => (
    <Table size="small" rowKey="nome" pagination={false} dataSource={dados} scroll={scrollTabela()}
      columns={[{ title: 'Nome', dataIndex: 'nome' }, { title: 'Quantidade', dataIndex: 'quantidade', align: 'right', render: (q) => formatarQtd(q) },
        { title: 'Valor (Kz)', dataIndex: 'valor', align: 'right', render: (x) => formatarKz(x) }]} />
  );
  return (
    <Card size="small" title={`Repartição do orçamento mapeado (${formatarKz(r.data.total_mapeado, true)})`}>
      <Tabs items={[
        { key: 't', label: 'Por tarefa', children: tab(r.data.por_tarefa.map((x) => ({ nome: `${x.codigo ? `${x.codigo} ` : ''}${x.nome} (${x.unidade})`, valor: x.valor, quantidade: x.quantidade }))) },
        { key: 'f', label: 'Por fase', children: tab(r.data.por_fase) },
        { key: 'a', label: 'Por área', children: tab(r.data.por_area) },
        { key: 'p', label: 'Por pessoa', children: tab(r.data.por_pessoa) },
      ]} />
      <Space><Typography.Text type="secondary" style={{ fontSize: 12 }}>Uma tarefa de uma área sem responsável directo reparte-se pelos membros da área (horas alocadas ou partes iguais).</Typography.Text></Space>
    </Card>
  );
}
