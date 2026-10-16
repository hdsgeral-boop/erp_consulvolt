import { Button, Card, Form, Input, InputNumber, Popconfirm, Select, Space, Table, Tag, Typography, theme } from 'antd';
import { CheckOutlined, DeleteOutlined, EditOutlined, PartitionOutlined, PlusOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import { useEffect, useMemo, useState, useRef } from 'react';
import { enviar, obter } from '@/api/cliente';
import { ValorKz } from '@/modulos/contab/comum/Componentes';
import { usePlanoContas } from '@/modulos/contab/comum/dados';
import { SeletorConta } from '@/modulos/compras/comum/Seletores';
import { useAccao } from '@/componentes/Accoes';
import { ModalFormulario, type CampoFormulario } from '@/componentes/formularios';
import { CartaoKpi, GrelhaKpi, TabelaComModos } from '@/componentes/vistas';
import { formatarData, formatarKz } from '@/utilitarios/formatacao';
import { ModalEliminar, SeletorTarefa, useWbs } from '../comum/componentes';
import { achatarWbs, consumo, FECHADOS, RUBRICAS, rotuloRubrica, totaisPorRubrica } from '../comum/regras';
import { dadosAditamento, dadosLinhaOrcamento, ESTADOS_ADITAMENTO } from '../comum/linhas';
import type { Aditamento, CustosTarefa, LinhaOrcamento } from '../comum/tipos';
import type { PropsSeparador } from '../DetalheProjecto';
import { ImpressaoSeparador } from '../comum/ImpressaoSeparador';
import { scrollTabela } from '@/componentes/responsivo';
import { useSessao } from '@/sessao/SessaoContexto';
import { ModalMapearQuantidade, QuadroOrcamentoQtd } from './OrcamentoQuantidades';

const FECHADO = (estado: string) => FECHADOS.includes(estado);

/** Estado do aditamento como no legado (renderProjectAditamentos, ui_projects.js:5575-5645). */
const ESTADO_ADIT: Record<string, { rotulo: string; cor: string }> = {
  APROVADO: { rotulo: 'Aprovado', cor: 'success' },
  PENDENTE: { rotulo: 'Pendente aprovação', cor: 'warning' },
  REJEITADO: { rotulo: 'Rejeitado', cor: 'error' },
};

/**
 * Orçamento base por tarefa e rubrica, orçado vs executado por tarefa, e aditamentos (trabalhos a mais/menos).
 * Disposição do legado (ui_projects.js:570-649 e 5575-5645): cartões «Orçamento total aprovado» (azul) e «Total de
 * aditamentos aprovados» (verde) com a barra à esquerda; tabela do orçamento (Tarefa / Fase, Rubrica, Conta, Responsável,
 * Valor orçamentado); o orçamento pelas quantidades; tabela dos aditamentos (Referência ADIT-n, Descrição, Valor extra,
 * Estado). O orçamento revisto, a repartição por rubrica e o «orçado vs executado» são do sistema novo e ficam.
 */
export function SeparadorOrcamento({ projecto, acc }: PropsSeparador) {
  const { token } = theme.useToken();
  const orc = useQuery({ queryKey: ['projectos', 'orcamento', projecto.id], queryFn: () => obter<{ total: string; linhas: LinhaOrcamento[] }>(`/projetos/${projecto.id}/orcamento`) });
  const adit = useQuery({ queryKey: ['projectos', 'aditamentos', projecto.id], queryFn: () => obter<{ total_aprovado: string; aditamentos: Aditamento[] }>(`/projetos/${projecto.id}/aditamentos`) });
  const custos = useQuery({ queryKey: ['projectos', 'custos-tarefas', projecto.id], queryFn: () => obter<Record<string, CustosTarefa>>(`/projetos/${projecto.id}/custos-tarefas`) });
  const w = useWbs(projecto.id);
  const [linha, setLinha] = useState<Partial<LinhaOrcamento> | null>(null);
  const [mapear, setMapear] = useState<LinhaOrcamento | null>(null);
  const { pode } = useSessao();
  const [aditamento, setAditamento] = useState<Partial<Aditamento> | null>(null);
  const [eliminarAdit, setEliminarAdit] = useState<Aditamento | null>(null);
  const accao = useAccao({ invalidar: [['projectos']], aoSucesso: () => setEliminarAdit(null) });
  const tarefas = useMemo(() => new Map(achatarWbs(w.data).map((t) => [t.id, t])), [w.data]);
  const porRubrica = totaisPorRubrica(orc.data?.linhas ?? []);
  const totalComAdit = (Number(orc.data?.total ?? 0) + Number(adit.data?.total_aprovado ?? 0)).toFixed(2);
  const linhasCustos = Object.entries(custos.data ?? {}).map(([id, c]) => ({ id: Number(id), ...c }));
  const refSeparador = useRef<HTMLDivElement>(null);

  return (
    <div ref={refSeparador}>
    <Space direction="vertical" size={16} style={{ width: '100%' }}>
      <div className="imp-nao-imprimir" style={{ display: 'flex', justifyContent: 'flex-end' }}>
        <ImpressaoSeparador alvo={refSeparador} titulo="Orçamento e aditamentos" projecto={projecto} />
      </div>
      <GrelhaKpi>
        <CartaoKpi barra="esquerda" cor="azul" icone="calculator" rotulo="Orçamento total aprovado" valor={formatarKz(orc.data?.total)} unidade="Kz" corValor={token.colorPrimary} />
        <CartaoKpi barra="esquerda" cor="verde" icone="file-signature" rotulo="Total de aditamentos aprovados" valor={formatarKz(adit.data?.total_aprovado)} unidade="Kz" corValor="#059669" />
        <CartaoKpi barra="esquerda" cor="roxo" icone="layer-group" rotulo="Orçamento revisto" valor={formatarKz(totalComAdit)} unidade="Kz" secundaria="Base + aditamentos aprovados" />
        <CartaoKpi barra="esquerda" cor="cinzento" icone="list" rotulo="Por rubrica" valor={Object.keys(porRubrica).length || '—'}
          secundaria={Object.keys(porRubrica).length
            ? Object.entries(porRubrica).map(([r, v]) => <span key={r} style={{ display: 'flex', justifyContent: 'space-between', gap: 8 }}><span>{rotuloRubrica(r)}</span><span>{formatarKz(v)}</span></span>)
            : 'Sem rubricas'} />
      </GrelhaKpi>
      <Card size="small" title="Orçamento base (custo previsto)" extra={acc.gerir && <Button type="primary" size="small" icon={<PlusOutlined />} onClick={() => setLinha({})}>Rubrica</Button>}>
        <TabelaComModos<LinhaOrcamento> idVista="orcamento-base" scroll={scrollTabela()}
          rowKey="id"
          size="small"
          loading={orc.isFetching}
          dataSource={orc.data?.linhas}
          pagination={false}
          columns={[
            { title: 'Tarefa / Fase (WBS)', dataIndex: 'tarefa_projeto_id', render: (id, l) => (id ? l.tarefa_nome ?? tarefas.get(id)?.nome ?? '#' + id : 'Projecto (geral)') },
            { title: 'Rubrica de custo', dataIndex: 'rubrica', render: (r: string) => <Tag>{rotuloRubrica(r)}</Tag> },
            { title: 'Conta', dataIndex: 'numero_conta', render: (v) => v ?? '—' },
            { title: 'Responsável', key: 'resp', render: (_, l) => l.membro_nome ?? l.posicao_titulo ?? '—' },
            { title: 'Valor orçamentado (Kz)', dataIndex: 'montante', align: 'right', render: (v) => <Typography.Text strong style={{ color: token.colorPrimary }}>{formatarKz(v)}</Typography.Text> },
            // Versão Actual: estado do mapeamento pelas quantidades (✓ = toda mapeada; senão a % mapeada)
            { title: 'Por quantidade', key: 'qtd', align: 'center', render: (_, l) => {
              const m = Number(l.mapeado_qtd ?? 0);
              const pct = Number(l.montante) > 0 ? Math.round((m / Number(l.montante)) * 100) : 0;
              const texto = !l.n_mapas ? '—' : Number(l.saldo_por_mapear ?? 0) < 0.01 ? <CheckOutlined style={{ color: token.colorSuccess }} /> : `${pct}%`;
              return pode('proj_orcamento_qtd') && !FECHADO(projecto.estado) ? <Button size="small" icon={<PartitionOutlined />} onClick={() => setMapear(l)}>{texto}</Button> : texto;
            } },
            {
              title: '', key: 'acc', align: 'right',
              render: (_, l) => acc.gerir && (
                <Space wrap>
                  <Button size="small" icon={<EditOutlined />} onClick={() => setLinha(l)} />
                  <Popconfirm title="Remover esta rubrica do orçamento?" okText="Remover" cancelText="Cancelar" okButtonProps={{ danger: true }}
                    onConfirm={() => accao.mutate({ metodo: 'delete', url: `/projetos/${projecto.id}/orcamento/${l.id}` })}>
                    <Button size="small" danger icon={<DeleteOutlined />} />
                  </Popconfirm>
                </Space>
              ),
            },
          ]}
        />
      </Card>
      <QuadroOrcamentoQtd projectoId={projecto.id} />
      <ModalMapearQuantidade projectoId={projecto.id} linha={mapear} aoFechar={() => setMapear(null)} />
      <Card size="small" title="Orçado vs executado por tarefa">
        <Table scroll={scrollTabela()}
          rowKey="id"
          size="small"
          loading={custos.isFetching}
          dataSource={linhasCustos}
          pagination={false}
          columns={[
            { title: 'Tarefa', dataIndex: 'id', render: (id) => tarefas.get(id)?.nome ?? `#${id}` },
            { title: 'Orçado', dataIndex: 'orcamento', align: 'right', render: (v) => <ValorKz valor={v} /> },
            { title: 'Executado', dataIndex: 'executado', align: 'right', render: (v, l) => <Typography.Text type={Number(v) > Number(l.orcamento) && Number(l.orcamento) > 0 ? 'danger' : undefined}>{formatarKz(v)}</Typography.Text> },
            { title: 'Consumo', key: 'c', align: 'right', render: (_, l) => { const c = consumo(l.executado, l.orcamento); return c === null ? '—' : `${c.toLocaleString('pt-PT')}%`; } },
            { title: 'Horas', dataIndex: 'horas', align: 'right' },
            { title: 'Requisições', dataIndex: 'requisicoes', align: 'right' },
          ]}
        />
      </Card>
      <Card size="small" title="Aditamentos e trabalhos a mais" extra={acc.gerir && <Button size="small" icon={<PlusOutlined />} onClick={() => setAditamento({ estado: 'PENDENTE' })}>Aditamento</Button>}>
        <TabelaComModos<Aditamento> idVista="aditamentos" scroll={scrollTabela()}
          rowKey="id"
          size="small"
          loading={adit.isFetching}
          dataSource={adit.data?.aditamentos}
          pagination={false}
          columns={[
            { title: 'Referência', dataIndex: 'id', render: (id: number) => <Typography.Text strong>ADIT-{id}</Typography.Text> },
            { title: 'Descrição do trabalho', dataIndex: 'descricao' },
            { title: 'Valor extra (Kz)', dataIndex: 'montante', align: 'right',
              render: (v: string | null) => <Typography.Text strong style={{ color: Number(v ?? 0) > 0 ? token.colorSuccess : token.colorError }}>{formatarKz(v)}</Typography.Text> },
            { title: 'Estado', dataIndex: 'estado', render: (v: string) => <Tag color={ESTADO_ADIT[v]?.cor ?? 'default'}>{ESTADO_ADIT[v]?.rotulo ?? v}</Tag> },
            { title: 'Data', key: 'd', render: (_, a) => formatarData(a.data ?? a.criado_em ?? null) },
            {
              title: '', key: 'acc', align: 'right',
              render: (_, a) => (
                <Space wrap>
                  {acc.gerir && <Button size="small" icon={<EditOutlined />} onClick={() => setAditamento(a)} />}
                  {acc.eliminar && <Button size="small" danger icon={<DeleteOutlined />} onClick={() => setEliminarAdit(a)} />}
                </Space>
              ),
            },
          ]}
        />
      </Card>
      <ModalLinha projectoId={projecto.id} linha={linha} aoFechar={() => setLinha(null)} />
      <ModalAditamento projectoId={projecto.id} aditamento={aditamento} aoFechar={() => setAditamento(null)} />
      <ModalEliminar aberto={!!eliminarAdit} titulo="Eliminar o aditamento" aviso={eliminarAdit?.descricao} carregando={accao.isPending} aoFechar={() => setEliminarAdit(null)}
        aoConfirmar={(confirmacao) => eliminarAdit && accao.mutate({ metodo: 'delete', url: `/projetos/${projecto.id}/aditamentos/${eliminarAdit.id}`, dados: { confirmacao } })} />
    </Space>
    </div>
  );
}

const OPCOES_RUBRICA = Object.entries(RUBRICAS).map(([value, label]) => ({ value, label }));

/** Rubrica do orçamento (saveBudgetLine). «Grade | Linhas» na criação (legado: projecto_orcamento). */
function ModalLinha({ projectoId, linha, aoFechar }: { projectoId: number; linha: Partial<LinhaOrcamento> | null; aoFechar: () => void }) {
  const [form] = Form.useForm();
  const rubrica = Form.useWatch('rubrica', form);
  const accao = useAccao({ invalidar: [['projectos']], aoSucesso: () => aoFechar() });
  const criacao = !!linha && !linha.id;
  const w = useWbs(projectoId);
  const plano = usePlanoContas(criacao);
  const campos = useMemo<CampoFormulario[]>(() => [
    { nome: 'tarefa_projeto_id', rotulo: 'Tarefa (WBS)', tipo: 'select', largura: 220, aliases: ['Tarefa'], placeholder: 'Projecto (geral)',
      opcoes: achatarWbs(w.data).map((t) => ({ value: t.id, label: `${'— '.repeat(t.nivel)}${t.codigo ? `${t.codigo} ` : ''}${t.nome}` })) },
    { nome: 'rubrica', rotulo: 'Rubrica', tipo: 'select', obrigatorio: true, opcoes: OPCOES_RUBRICA, largura: 150 },
    { nome: 'numero_conta', rotulo: 'Conta contabilística', tipo: 'select', largura: 220, aliases: ['Conta'],
      opcoes: (plano.data ?? []).filter((c) => c.tipo === 'M').map((c) => ({ value: c.codigo, label: `${c.codigo} - ${c.descricao ?? ''}` })),
      validar: (v, r) => (v && r.rubrica === 'MAO_DE_OBRA' ? 'A mão de obra não leva conta.' : null) },
    { nome: 'montante', rotulo: 'Valor previsto (AOA)', tipo: 'numero', obrigatorio: true, precisao: 2, largura: 140, aliases: ['Montante', 'Valor', 'Montante (Kz)'] },
  ], [w.data, plano.data]);
  useEffect(() => {
    if (linha) { form.resetFields(); form.setFieldsValue({ ...linha, montante: linha.montante ? Number(linha.montante) : undefined }); }
  }, [linha, form]);
  return (
    <ModalFormulario chave="projectos.orcamento" titulo={linha?.id ? 'Editar rubrica' : 'Nova rubrica do orçamento'} aberto={!!linha} aoFechar={aoFechar} criacao={criacao}
      form={form} campos={campos} nomeRegistos="linhas de orçamento" invalidar={[['projectos']]}
      gravarLinha={(r) => enviar('post', `/projetos/${projectoId}/orcamento`, dadosLinhaOrcamento(r))}
      nota="Uma linha por rubrica do orçamento (tarefa vazia = projecto em geral). A mão de obra não leva conta. Linhas deixadas em branco são ignoradas."
      onOk={() => form.submit()} okText="Gravar" cancelText="Cancelar" confirmLoading={accao.isPending}>
      <Form form={form} layout="vertical" onFinish={(v) => {
        const dados = dadosLinhaOrcamento(v);
        accao.mutate(linha?.id ? { metodo: 'put', url: `/projetos/${projectoId}/orcamento/${linha.id}`, dados } : { url: `/projetos/${projectoId}/orcamento`, dados });
      }}>
        <Form.Item name="tarefa_projeto_id" label="Tarefa"><SeletorTarefa projectoId={projectoId} allowClear placeholder="Projecto (geral)" /></Form.Item>
        <Form.Item name="rubrica" label="Rubrica" rules={[{ required: true, message: 'Escolha a rubrica.' }]}>
          <Select options={OPCOES_RUBRICA} />
        </Form.Item>
        {rubrica && rubrica !== 'MAO_DE_OBRA' && <Form.Item name="numero_conta" label="Conta (opcional)"><SeletorConta /></Form.Item>}
        <Form.Item name="montante" label="Montante (Kz)" rules={[{ required: true, message: 'Indique o montante.' }]}><InputNumber precision={2} style={{ width: 220, maxWidth: '100%' }} /></Form.Item>
      </Form>
    </ModalFormulario>
  );
}

/** Vista Linhas dos aditamentos (legado: projecto_aditamentos — descrição, valor e estado). */
const CAMPOS_ADITAMENTO: CampoFormulario[] = [
  { nome: 'descricao', rotulo: 'Descrição do trabalho', obrigatorio: true, largura: 320, maxLength: 5000, aliases: ['Descrição'] },
  { nome: 'montante', rotulo: 'Valor', tipo: 'numero', precisao: 2, largura: 130, aliases: ['Montante', 'Valor extra'] },
  { nome: 'estado', rotulo: 'Estado', tipo: 'select', opcoes: ESTADOS_ADITAMENTO, omissao: 'PENDENTE', largura: 130 },
];

/** Aditamento (saveChangeOrder). «Grade | Linhas» na criação; valor negativo = trabalhos a menos. */
function ModalAditamento({ projectoId, aditamento, aoFechar }: { projectoId: number; aditamento: Partial<Aditamento> | null; aoFechar: () => void }) {
  const [form] = Form.useForm();
  const accao = useAccao({ invalidar: [['projectos']], aoSucesso: () => aoFechar() });
  useEffect(() => {
    if (aditamento) { form.resetFields(); form.setFieldsValue({ ...aditamento, montante: aditamento.montante ? Number(aditamento.montante) : undefined }); }
  }, [aditamento, form]);
  return (
    <ModalFormulario chave="projectos.aditamentos" titulo={aditamento?.id ? 'Editar aditamento' : 'Novo aditamento'} aberto={!!aditamento} aoFechar={aoFechar}
      criacao={!!aditamento && !aditamento.id} form={form} campos={CAMPOS_ADITAMENTO} nomeRegistos="aditamentos / trabalhos a mais" invalidar={[['projectos']]}
      gravarLinha={(r) => enviar('post', `/projetos/${projectoId}/aditamentos`, dadosAditamento(r))}
      nota="Uma linha por aditamento ou trabalho a mais (valor negativo = trabalhos a menos). Linhas deixadas em branco são ignoradas."
      onOk={() => form.submit()} okText="Gravar" cancelText="Cancelar" confirmLoading={accao.isPending}>
      <Form form={form} layout="vertical" onFinish={(v) => {
        const dados = dadosAditamento(v);
        accao.mutate(aditamento?.id ? { metodo: 'put', url: `/projetos/${projectoId}/aditamentos/${aditamento.id}`, dados } : { url: `/projetos/${projectoId}/aditamentos`, dados });
      }}>
        <Form.Item name="descricao" label="Descrição" rules={[{ required: true, message: 'Descreva o aditamento.' }]}><Input.TextArea rows={3} maxLength={5000} /></Form.Item>
        <Space wrap>
          <Form.Item name="montante" label="Montante (Kz)" tooltip="Negativo = trabalhos a menos"><InputNumber precision={2} style={{ width: 200, maxWidth: '100%' }} /></Form.Item>
          <Form.Item name="estado" label="Estado">
            <Select style={{ width: 160 }} options={ESTADOS_ADITAMENTO} />
          </Form.Item>
        </Space>
      </Form>
    </ModalFormulario>
  );
}
