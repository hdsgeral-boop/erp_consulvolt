import { Alert, Button, Card, DatePicker, Descriptions, Flex, Form, Input, InputNumber, Modal, Skeleton, Space, Table, Tag, Typography } from 'antd';
import { tabelaHtml } from '@/componentes/impressao';
import { CartaoKpi, CORES_KPI, EstadoVazio, GrelhaKpi, type PropsEstadoVazio } from '@/componentes/vistas';
import { ArrowLeftOutlined, PlusOutlined, SaveOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import type { ColumnsType } from 'antd/es/table';
import { useArmazens } from '@/modulos/compras/comum/referencias';
import dayjs, { type Dayjs } from 'dayjs';
import { useMemo, useState } from 'react';
import { Route, Routes, useNavigate, useParams } from 'react-router-dom';
import { obter, obterPagina } from '@/api/cliente';
import { ErroApi } from '@/api/tipos';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { useSessao } from '@/sessao/SessaoContexto';
import { dataApi, formatarData, formatarDataHora, formatarKz, formatarNumero } from '@/utilitarios/formatacao';
import { notificarErro } from '@/utilitarios/erros';
import { ModalMotivo, useAccao } from '@/componentes/Accoes';
import { EstadoTag, rotuloEstado } from '@/modulos/compras/comum/estados';
import { contemTexto } from '@/modulos/compras/comum/lista';
import { NomeArmazem } from '@/modulos/compras/comum/referencias';
import { SeletorArmazem, SeletorProduto } from '@/modulos/compras/comum/Seletores';
import { TabelaApi, type ColunaApi } from '@/componentes/TabelaApi';
import { accoesInventario, previsualizarRegularizacao } from './regras';
import type { LinhaInventario, SessaoInventario } from './tipos';
import { BarraFiltros, COLUNAS_DESCRICOES, larguraModal, scrollTabela, useEcraPequeno } from '@/componentes/responsivo';

/**
 * Inventário impresso: em contagem, a folha de contagem cega (sem a quantidade do sistema, com espaço para escrever);
 * depois, o mapa de diferenças com custos, valores e justificações.
 */
export function pedidoInventario(s: SessaoInventario, nome: string, armazem: string) {
  const linhas = s.linhas ?? [];
  const base = { periodo: formatarData(s.data), filtros: [`Armazém: ${armazem}`, `Estado: ${rotuloEstado(s.estado)}`, s.descricao ?? undefined, `${linhas.length} artigo(s)`] };
  if (s.estado === 'EM_CONTAGEM') {
    return {
      ...base,
      titulo: `Folha de contagem — inventário ${nome}`,
      conteudo:
        tabelaHtml({
          colunas: [
            { titulo: 'Código', valor: (l: LinhaInventario) => l.codigo ?? '' },
            { titulo: 'Produto', valor: (l) => l.nome, quebrar: true },
            { titulo: 'Quantidade contada', valor: (l) => (l.quantidade_contada == null ? '' : formatarNumero(l.quantidade_contada)), alinhamento: 'direita', largura: '32mm' },
            { titulo: 'Observações', valor: (l) => l.observacoes ?? '', largura: '55mm', quebrar: true },
          ],
          linhas,
        }) +
        '<div class="imp-sem-quebra" style="display:flex;justify-content:space-around;gap:10mm;margin-top:14mm"><div style="flex:0 1 38%;text-align:center;border-top:0.3mm solid #1f1f1f;padding-top:1mm;font-size:8pt">Contado por</div><div style="flex:0 1 38%;text-align:center;border-top:0.3mm solid #1f1f1f;padding-top:1mm;font-size:8pt">Conferido por</div></div>',
    };
  }
  return {
    ...base,
    titulo: `Mapa de diferenças — inventário ${nome}`,
    conteudo: tabelaHtml({
      colunas: [
        { titulo: 'Código', valor: (l: LinhaInventario) => l.codigo ?? '' },
        { titulo: 'Produto', valor: (l) => l.nome, quebrar: true },
        { titulo: 'Sistema', valor: (l) => l.quantidade_sistema, formato: 'numero' },
        { titulo: 'Contado', valor: (l) => l.quantidade_contada, formato: 'numero' },
        { titulo: 'Diferença', valor: (l) => l.diferenca, formato: 'numero' },
        { titulo: 'Custo (Kz)', valor: (l) => l.custo_personalizado ?? l.custo_unitario ?? l.custo_medio, formato: 'moeda' },
        { titulo: 'Valor da diferença (Kz)', valor: (l) => l.valor_diferenca, formato: 'moeda', somar: true },
        { titulo: 'Justificação', valor: (l) => l.justificacao ?? '', quebrar: true },
      ],
      linhas,
      totais: 'Saldo da regularização',
    }),
  };
}

/** Modo do ecrã: sessões (gestão), contagem (contagem cega) ou revisão (diferenças e regularização). */
export type ModoInventario = 'sessoes' | 'contagem' | 'revisao';

/** Títulos, textos e estados vazios do legado (js/ui_inventory.js:75-76, 329-330, 617-618). */
const TITULOS: Record<ModoInventario, { titulo: string; subtitulo: string; estado?: string; vazio: PropsEstadoVazio }> = {
  sessoes: {
    titulo: 'Sessões de Inventário',
    subtitulo: 'Crie ou consulte as folhas de contagem cega para os Armazens.',
    vazio: { icone: 'tasks', titulo: 'Sem sessões de Inventário registadas.' },
  },
  contagem: {
    titulo: 'Efetuar Contagem',
    subtitulo: 'Contagem física (cega: a quantidade do sistema não é mostrada)',
    estado: 'EM_CONTAGEM',
    vazio: { icone: 'clipboard-list', titulo: 'Nenhuma Folha de Contagem Ativa', texto: 'Não existem Inventários em fase de contagem no momento.' },
  },
  revisao: {
    titulo: 'Revisão e Regularização',
    subtitulo: 'Diferenças entre o contado e o sistema, custos e justificações',
    estado: 'REVISAO',
    vazio: { icone: 'check-circle', cor: '#059669', titulo: 'Nenhuma Revisão Pendente', texto: 'Não existem contagens a aguardar aprovação e regularização.' },
  },
};

/** «# Lote» e tipo como no legado (o tipo das sessões é «GERAL» quando não vem gravado). */
export const tipoInventario = (s: Pick<SessaoInventario, 'tipo'>) => (s.tipo?.trim() ? s.tipo.trim().toUpperCase() : 'GERAL');

export interface EdicaoContagem {
  quantidade_contada?: number | null;
  observacoes?: string | null;
}

/** Linhas a enviar na contagem: só as alteradas face ao que está gravado. */
export function linhasAlteradas(originais: Pick<LinhaInventario, 'produto_id' | 'quantidade_contada' | 'observacoes'>[], edicoes: Record<number, EdicaoContagem>) {
  const porProduto = new Map(originais.map((l) => [l.produto_id, l]));
  return Object.entries(edicoes)
    .map(([id, e]) => ({ produto_id: Number(id), ...e }))
    .filter((e) => {
      const o = porProduto.get(e.produto_id);
      if (!o) return true; // produto acrescentado na contagem
      const qOriginal = o.quantidade_contada === null ? null : Number(o.quantidade_contada);
      const qNova = e.quantidade_contada === undefined ? qOriginal : e.quantidade_contada;
      const obsNova = e.observacoes === undefined ? o.observacoes : e.observacoes;
      return qNova !== qOriginal || (obsNova || null) !== (o.observacoes || null);
    })
    .map((e) => {
      const o = porProduto.get(e.produto_id);
      return {
        produto_id: e.produto_id,
        quantidade_contada: e.quantidade_contada === undefined ? (o?.quantidade_contada == null ? null : Number(o.quantidade_contada)) : e.quantidade_contada,
        observacoes: (e.observacoes === undefined ? o?.observacoes : e.observacoes) || null,
      };
    });
}

export function EcraInventario({ modo }: { modo: ModoInventario }) {
  return (
    <Routes>
      <Route index element={<ListaSessoes modo={modo} />} />
      <Route path=":id" element={<DetalheSessao modo={modo} />} />
    </Routes>
  );
}

function ListaSessoes({ modo }: { modo: ModoInventario }) {
  const navegar = useNavigate();
  const { pode } = useSessao();
  const t = TITULOS[modo];
  const armazem: number | undefined = undefined;
  const estado: string | undefined = t.estado;
  const pesquisa = '';
  const [abrir, setAbrir] = useState(false);
  const [motivo, setMotivo] = useState<{ accao: 'reabrir' | 'anular'; sessao: SessaoInventario } | null>(null);
  const accao = useAccao({ invalidar: [['logistica']], aoSucesso: () => setMotivo(null) });
  const pequeno = useEcraPequeno();
  const armazens = useArmazens();
  const nomeArmazem = (id: number | null | undefined) => (id ? armazens.data?.find((a) => a.id === id)?.nome ?? '-' : '-');
  // legado (ui_inventory.js): sem folhas em contagem / revisões pendentes mostra só o cartão vazio, sem título nem tabela
  const total = useQuery({
    queryKey: ['logistica', 'inventarios', 'total', estado],
    queryFn: () => obterPagina<SessaoInventario>('/logistica/inventarios', { estado, por_pagina: 1 }),
    enabled: modo !== 'sessoes',
  });
  if (modo !== 'sessoes' && total.data && total.data.paginacao.total === 0) {
    return (
      <Card>
        <EstadoVazio {...t.vazio} />
      </Card>
    );
  }

  // Colunas do legado (renderInvsessoesTab, ui_inventory.js:86-94); «Iniciado por» e «Aprovado» ficam em «Colunas ▾».
  const colunas: ColunaApi<SessaoInventario>[] = [
    { title: '# Lote', dataIndex: 'id', align: 'center', valorImpressao: (s) => String(s.id), render: (v: number) => <strong>{v}</strong> },
    { title: 'Data', dataIndex: 'data', align: 'center', render: formatarData },
    { title: 'Armazém', dataIndex: 'armazem_id', valorImpressao: (s) => nomeArmazem(s.armazem_id), render: (v: number) => (armazens.data?.some((a) => a.id === v) ? <NomeArmazem id={v} /> : '-') },
    { title: 'Descrição', dataIndex: 'descricao', render: (v) => v || 'Inventário Geral' },
    { title: 'Tipo', dataIndex: 'tipo', align: 'center', valorImpressao: tipoInventario, render: (_, s) => tipoInventario(s) },
    { title: 'Estado', dataIndex: 'estado', align: 'center', render: (e: string) => rotuloEstado(e) }, // legado: texto simples
    { title: 'Iniciado por', dataIndex: 'iniciado_por', ocultaPorOmissao: true, render: (v) => v || '—' },
    { title: 'Aprovado', key: 'ap', ocultaPorOmissao: true, valorImpressao: (s) => (s.aprovado_por ? `${s.aprovado_por} · ${formatarDataHora(s.aprovado_em)}` : '—'), render: (_, s) => (s.aprovado_por ? `${s.aprovado_por} · ${formatarDataHora(s.aprovado_em)}` : '—') },
  ];

  return (
    <>
      <Flex justify="space-between" align="center" gap={12} wrap style={{ marginBottom: 16 }}>
        <div>
          <h3 style={{ margin: 0, fontSize: '1.2rem', color: 'var(--ant-color-primary, #2563eb)' }}>{t.titulo}</h3>
          <Typography.Text type="secondary" style={{ fontSize: '0.85rem' }}>{t.subtitulo}</Typography.Text>
        </div>
        {modo === 'sessoes' && pode('inventario_iniciar') && <Button type="primary" icon={<PlusOutlined />} onClick={() => setAbrir(true)}>Iniciar Novo Inventário</Button>}
      </Flex>
      <Card>
        {/* legado: sem barra de filtros (os filtros são os da linha por baixo dos cabeçalhos) */}
        <TabelaApi<SessaoInventario>
          url="/logistica/inventarios"
          filtros={{ armazem_id: armazem, estado, pesquisa: pesquisa.trim() || undefined }}
          chaveConsulta={['logistica', 'inventarios']}
          columns={colunas}
          size={pequeno ? 'small' : 'middle'}
          impressao={{
            titulo: t.titulo,
            filtros: [!!armazem && `Armazém: ${nomeArmazem(armazem)}`, estado && `Estado: ${rotuloEstado(estado)}`, pesquisa.trim() && `Pesquisa: ${pesquisa.trim()}`],
          }}
          vazio={t.vazio}
          accoes={(s) => {
            const a = accoesInventario(s, pode);
            return [
              { icone: 'eye', titulo: 'Detalhes', onClick: () => navegar(String(s.id)) },
              modo === 'sessoes' && s.estado === 'EM_CONTAGEM' && a.anular && { icone: 'trash', titulo: 'Anular Sessão', variante: 'perigo', onClick: () => setMotivo({ accao: 'anular', sessao: s }) },
              modo === 'sessoes' && a.reabrir && { icone: 'undo', titulo: 'Reabrir (Reverter Movimentos)', variante: 'aviso', onClick: () => setMotivo({ accao: 'reabrir', sessao: s }) },
            ];
          }}
          onRow={(r) => ({ onClick: () => navegar(String(r.id)), style: { cursor: 'pointer' } })}
        />
      </Card>
      <ModalAbrir aberto={abrir} aoFechar={() => setAbrir(false)} aoAbrir={(s) => navegar(String(s.id))} />
      <ModalMotivo
        aberto={motivo?.accao === 'reabrir'}
        titulo={`Reabrir o inventário n.º ${motivo?.sessao.id ?? ''}`}
        textoOk="Reabrir"
        aviso="Estorna o lançamento e anula os ajustes da regularização; o inventário volta à revisão."
        carregando={accao.isPending}
        aoFechar={() => setMotivo(null)}
        aoConfirmar={(m) => motivo && accao.mutate({ url: `/logistica/inventarios/${motivo.sessao.id}/reabrir`, dados: { motivo: m } })}
      />
      <ModalMotivo
        aberto={motivo?.accao === 'anular'}
        titulo={`Anular o inventário n.º ${motivo?.sessao.id ?? ''}`}
        textoOk="Anular"
        aviso="O armazém volta a aceitar movimentos; nada é regularizado."
        carregando={accao.isPending}
        aoFechar={() => setMotivo(null)}
        aoConfirmar={(m) => motivo && accao.mutate({ url: `/logistica/inventarios/${motivo.sessao.id}/anular`, dados: { motivo: m } })}
      />
    </>
  );
}

function ModalAbrir({ aberto, aoFechar, aoAbrir }: { aberto: boolean; aoFechar: () => void; aoAbrir: (s: SessaoInventario) => void }) {
  const [form] = Form.useForm<{ armazem_id: number; data: Dayjs; descricao?: string }>();
  const accao = useAccao<SessaoInventario>({ invalidar: [['logistica']], aoSucesso: (s) => { aoFechar(); aoAbrir(s); }, tituloErro: 'Não foi possível abrir o inventário' });
  return (
    <Modal width={larguraModal(520)} title="Nova Sessão de Inventário" open={aberto} onCancel={aoFechar} okText="Abrir inventário" cancelText="Cancelar" confirmLoading={accao.isPending} onOk={() => form.submit()} destroyOnHidden>
      <Form form={form} layout="vertical" preserve={false} initialValues={{ data: dayjs() }} onFinish={(v) => accao.mutate({ url: '/logistica/inventarios', dados: { armazem_id: v.armazem_id, data: dataApi(v.data), descricao: v.descricao || undefined } })}>
        <Alert type="warning" showIcon style={{ marginBottom: 16 }} message="Enquanto o inventário estiver aberto, o armazém fica sem movimentos até à aprovação ou anulação." />
        <Form.Item name="armazem_id" label="Armazém" rules={[{ required: true, message: 'Escolha o armazém.' }]}>
          <SeletorArmazem />
        </Form.Item>
        <Form.Item name="data" label="Data do inventário" rules={[{ required: true }]}>
          <DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} />
        </Form.Item>
        <Form.Item name="descricao" label="Descrição" rules={[{ max: 500 }]}>
          <Input />
        </Form.Item>
      </Form>
    </Modal>
  );
}

function DetalheSessao({ modo }: { modo: ModoInventario }) {
  const { id } = useParams();
  const navegar = useNavigate();
  const { pode } = useSessao();
  const [modal, setModal] = useState<'reabrir' | 'anular' | null>(null);
  const [edicoes, setEdicoes] = useState<Record<number, EdicaoContagem>>({});
  const [revisao, setRevisao] = useState<Record<number, { custo_personalizado?: number | null; justificacao?: string | null }>>({});
  const [novoProduto, setNovoProduto] = useState<number>();
  const [pesquisa, setPesquisa] = useState('');
  const [soPorContar, setSoPorContar] = useState(false);
  const armazens = useArmazens();

  const consulta = useQuery({ queryKey: ['logistica', 'inventario', id], queryFn: () => obter<SessaoInventario>(`/logistica/inventarios/${id}`) });
  const limpar = () => {
    setEdicoes({});
    setRevisao({});
    setModal(null);
  };
  const accao = useAccao({ invalidar: [['logistica']], aoSucesso: limpar });
  const concluir = useAccao({ invalidar: [['logistica']], aoSucesso: limpar });
  /** Acrescentar um produto não limpa as contagens ainda por gravar. */
  const acrescentar = useAccao({ invalidar: [['logistica', 'inventario', id]] });

  const s = consulta.data;
  const linhas = useMemo(() => s?.linhas ?? [], [s]);
  const linhasRevistas = useMemo(
    () => linhas.map((l) => ({ ...l, ...(revisao[l.produto_id] ? { custo_personalizado: revisao[l.produto_id].custo_personalizado === undefined ? l.custo_personalizado : revisao[l.produto_id].custo_personalizado == null ? null : String(revisao[l.produto_id].custo_personalizado), justificacao: revisao[l.produto_id].justificacao ?? l.justificacao } : {}) })),
    [linhas, revisao],
  );
  const previsao = useMemo(() => previsualizarRegularizacao(linhasRevistas), [linhasRevistas]);

  if (consulta.isLoading) return <Skeleton active />;
  if (!s) return <Alert type="error" message="Inventário não encontrado." />;
  const a = accoesInventario(s, pode);
  const nome = `INV ${dayjs(s.data).format('YYYY')}/${s.id}`;
  const alteradas = linhasAlteradas(linhas, edicoes);
  const porContar = linhas.filter((l) => (edicoes[l.produto_id]?.quantidade_contada !== undefined ? edicoes[l.produto_id].quantidade_contada : l.quantidade_contada) == null).length;
  const emContagem = s.estado === 'EM_CONTAGEM';
  const visiveis = linhasRevistas.filter((l) => contemTexto(pesquisa, l.codigo, l.nome) && (!soPorContar || (edicoes[l.produto_id]?.quantidade_contada ?? l.quantidade_contada) == null));
  const editarRevisao = a.rever && modo !== 'sessoes';
  const editarContagem = a.contar && modo !== 'revisao';

  const terminarContagem = () => {
    const enviar = (zero: boolean) => concluir.mutateAsync({ url: `/logistica/inventarios/${s.id}/concluir-contagem`, dados: { por_contar_como_zero: zero } });
    if (alteradas.length) {
      Modal.warning({ title: 'Há contagens por gravar', content: 'Grave a contagem antes de a terminar.' });
      return;
    }
    if (porContar > 0) {
      Modal.confirm({
        title: `Há ${porContar} produto(s) por contar`,
        content: 'Confirma que a quantidade destes produtos é zero? A contagem passa para revisão.',
        okText: 'Sim, contar como zero',
        cancelText: 'Voltar',
        onOk: () => enviar(true),
      });
    } else {
      Modal.confirm({ title: 'Terminar a contagem?', content: 'O inventário passa para revisão.', okText: 'Terminar', cancelText: 'Cancelar', onOk: () => enviar(false) });
    }
  };

  const aprovar = () =>
    Modal.confirm({
      title: `Aprovar o inventário ${nome}?`,
      content: `Regulariza o stock (sobras ${formatarKz(previsao.sobras.valor)} Kz, quebras ${formatarKz(previsao.quebras.valor)} Kz, estimativa) e lança no diário à data do inventário. Quem iniciou o inventário não o pode aprovar.`,
      okText: 'Aprovar e regularizar',
      cancelText: 'Cancelar',
      onOk: () => accao.mutateAsync({ url: `/logistica/inventarios/${s.id}/aprovar` }).catch(() => undefined),
    });

  const colunasContagem: ColumnsType<LinhaInventario> = [
    { title: 'Código', dataIndex: 'codigo', render: (v) => v || '—' },
    { title: 'Produto', dataIndex: 'nome' },
    {
      title: 'Quantidade contada',
      key: 'q',
      render: (_, l) => {
        const valor = edicoes[l.produto_id]?.quantidade_contada !== undefined ? edicoes[l.produto_id].quantidade_contada : l.quantidade_contada == null ? null : Number(l.quantidade_contada);
        return editarContagem ? (
          <InputNumber min={0} value={valor} style={{ width: 140 }} placeholder="por contar" onChange={(v) => setEdicoes((e) => ({ ...e, [l.produto_id]: { ...e[l.produto_id], quantidade_contada: v } }))} />
        ) : valor == null ? <Typography.Text type="secondary">por contar</Typography.Text> : formatarNumero(valor);
      },
    },
    {
      title: 'Observações',
      key: 'o',
      render: (_, l) =>
        editarContagem ? (
          <Input
            maxLength={500}
            style={{ width: 260, maxWidth: '100%' }}
            value={edicoes[l.produto_id]?.observacoes ?? l.observacoes ?? ''}
            onChange={(ev) => setEdicoes((e) => ({ ...e, [l.produto_id]: { ...e[l.produto_id], observacoes: ev.target.value } }))}
          />
        ) : (
          l.observacoes || '—'
        ),
    },
  ];

  const colunasRevisao: ColumnsType<LinhaInventario> = [
    { title: 'Código', dataIndex: 'codigo', render: (v) => v || '—' },
    { title: 'Produto', dataIndex: 'nome' },
    { title: 'Sistema', dataIndex: 'quantidade_sistema', align: 'right', render: formatarNumero },
    { title: 'Contado', dataIndex: 'quantidade_contada', align: 'right', render: formatarNumero },
    {
      title: 'Diferença',
      dataIndex: 'diferenca',
      align: 'right',
      render: (v: string | null) => {
        const n = Number(v ?? 0);
        return n ? <Tag color={n > 0 ? 'green' : 'red'}>{n > 0 ? '+' : ''}{formatarNumero(n)}</Tag> : '0';
      },
    },
    { title: 'Custo médio', dataIndex: 'custo_medio', align: 'right', render: (v: string | null) => formatarKz(v) },
    {
      title: 'Custo a usar',
      key: 'custo',
      render: (_, l) =>
        editarRevisao && Number(l.diferenca ?? 0) !== 0 ? (
          <InputNumber
            min={0}
            precision={2}
            style={{ width: 140 }}
            placeholder="custo médio"
            value={l.custo_personalizado == null || l.custo_personalizado === '' ? null : Number(l.custo_personalizado)}
            onChange={(v) => setRevisao((r) => ({ ...r, [l.produto_id]: { ...r[l.produto_id], custo_personalizado: v } }))}
          />
        ) : (
          formatarKz(l.custo_personalizado ?? l.custo_unitario ?? l.custo_medio)
        ),
    },
    {
      title: 'Justificação',
      key: 'just',
      render: (_, l) =>
        editarRevisao && Number(l.diferenca ?? 0) !== 0 ? (
          <Input maxLength={500} style={{ width: 240, maxWidth: '100%' }} value={l.justificacao ?? ''} onChange={(ev) => setRevisao((r) => ({ ...r, [l.produto_id]: { ...r[l.produto_id], justificacao: ev.target.value } }))} />
        ) : (
          l.justificacao || '—'
        ),
    },
    { title: 'Valor da diferença', key: 'valor', align: 'right', render: (_, l) => (l.valor_diferenca ? formatarKz(l.valor_diferenca) : formatarKz(Math.abs(Number(l.diferenca ?? 0)) * Number(l.custo_personalizado ?? l.custo_medio ?? 0) * Math.sign(Number(l.diferenca ?? 0)))) },
  ];

  return (
    <>
      <CabecalhoPagina
        titulo={`Inventário ${nome}`}
        subtitulo={<><NomeArmazem id={s.armazem_id} /> · {formatarData(s.data)}{s.descricao ? ` · ${s.descricao}` : ''}</>}
        impressao={() => pedidoInventario(s, nome, armazens.data?.find((x) => x.id === s.armazem_id)?.nome ?? `#${s.armazem_id}`)}
        accoes={
          <>
            <Button icon={<ArrowLeftOutlined />} onClick={() => navegar('..')}>Voltar</Button>
            {editarContagem && (
              <>
                <Button type="primary" icon={<SaveOutlined />} disabled={!alteradas.length} loading={accao.isPending} onClick={() => accao.mutate({ url: `/logistica/inventarios/${s.id}/contagem`, dados: { linhas: alteradas } })}>
                  Gravar contagem{alteradas.length ? ` (${alteradas.length})` : ''}
                </Button>
                <Button loading={concluir.isPending} onClick={terminarContagem}>Terminar contagem</Button>
              </>
            )}
            {editarRevisao && (
              <Button
                type="primary"
                icon={<SaveOutlined />}
                disabled={!Object.keys(revisao).length}
                loading={accao.isPending}
                onClick={() =>
                  accao.mutate({
                    url: `/logistica/inventarios/${s.id}/revisao`,
                    dados: {
                      linhas: linhasRevistas
                        .filter((l) => revisao[l.produto_id])
                        .map((l) => ({ produto_id: l.produto_id, custo_personalizado: l.custo_personalizado === null || l.custo_personalizado === '' ? null : Number(l.custo_personalizado), justificacao: l.justificacao || null })),
                    },
                  })
                }
              >
                Gravar revisão
              </Button>
            )}
            {a.voltarContagem && modo !== 'contagem' && (
              <Button onClick={() => Modal.confirm({ title: 'Voltar à contagem?', okText: 'Voltar à contagem', cancelText: 'Cancelar', onOk: () => accao.mutateAsync({ url: `/logistica/inventarios/${s.id}/voltar-contagem` }).catch(() => undefined) })}>
                Voltar à contagem
              </Button>
            )}
            {a.aprovar && modo !== 'contagem' && <Button type="primary" disabled={Object.keys(revisao).length > 0} onClick={aprovar}>Aprovar</Button>}
            {a.reabrir && modo === 'sessoes' && <Button style={{ color: '#f59e0b', borderColor: '#f59e0b' }} onClick={() => setModal('reabrir')}>Reabrir</Button>}
            {a.anular && modo === 'sessoes' && <Button danger onClick={() => setModal('anular')}>Anular</Button>}
          </>
        }
      />
      {s.estado === 'ANULADA' && <Alert type="error" showIcon style={{ marginBottom: 16 }} message={`Inventário anulado${s.motivo_anulacao ? `: ${s.motivo_anulacao}` : '.'}`} />}
      {modo === 'contagem' && !emContagem && <Alert type="info" showIcon style={{ marginBottom: 16 }} message="Este inventário já não está em contagem." />}
      {modo === 'revisao' && s.estado !== 'REVISAO' && <Alert type="info" showIcon style={{ marginBottom: 16 }} message="Este inventário não está em revisão." />}

      <Card style={{ marginBottom: 16 }}>
        <Descriptions column={COLUNAS_DESCRICOES} size="small">
          <Descriptions.Item label="Estado"><EstadoTag estado={s.estado} /></Descriptions.Item>
          <Descriptions.Item label="Artigos">{linhas.length}</Descriptions.Item>
          {emContagem && <Descriptions.Item label="Por contar">{porContar}</Descriptions.Item>}
          {s.iniciado_por && <Descriptions.Item label="Iniciado por">{s.iniciado_por}</Descriptions.Item>}
          {s.aprovado_por && <Descriptions.Item label="Aprovado">{s.aprovado_por} · {formatarDataHora(s.aprovado_em)}</Descriptions.Item>}
          {s.numero_lan_contabilizacao && <Descriptions.Item label="Lançamento">{s.numero_lan_contabilizacao}</Descriptions.Item>}
        </Descriptions>
      </Card>

      {!emContagem && s.estado !== 'ANULADA' && (
        // resumo financeiro do legado (ui_inventory.js:756-770): artigos com diferença, quebras e sobras, barra à esquerda; saldo e
        // diferenças por justificar são do sistema novo e ficam a seguir
        <GrelhaKpi larguraMinima={200} style={{ marginBottom: 16 }}>
          <CartaoKpi barra="esquerda" cor="laranja" rotulo="Artigos com Diferença" valor={previsao.sobras.linhas + previsao.quebras.linhas} />
          <CartaoKpi barra="esquerda" cor="vermelho" rotulo={`Custo Global Quebras (${previsao.quebras.linhas})`} valor={formatarKz(previsao.quebras.valor)} unidade="Kz" corValor={CORES_KPI.vermelho} />
          <CartaoKpi barra="esquerda" cor="verde" rotulo={`Custo Global Sobras (${previsao.sobras.linhas})`} valor={formatarKz(previsao.sobras.valor)} unidade="Kz" corValor={CORES_KPI.verde} />
          <CartaoKpi barra="esquerda" cor="azul" rotulo="Saldo da regularização" valor={formatarKz(previsao.sobras.valor - previsao.quebras.valor)} unidade="Kz" />
          <CartaoKpi barra="esquerda" cor={previsao.porJustificar ? 'laranja' : 'cinzento'} rotulo="Diferenças por justificar" valor={previsao.porJustificar} />
        </GrelhaKpi>
      )}

      <Card
        title={emContagem ? 'Contagem' : 'Linhas do inventário'}
        extra={
          editarContagem && (
            <Space wrap>
              <SeletorProduto apenasStock allowClear placeholder="Acrescentar produto não listado" style={{ width: 300, maxWidth: '100%' }} value={novoProduto} onChange={setNovoProduto} />
              <Button
                icon={<PlusOutlined />}
                disabled={!novoProduto}
                loading={acrescentar.isPending}
                onClick={() => {
                  if (!novoProduto) return;
                  if (linhas.some((l) => l.produto_id === novoProduto)) {
                    notificarErro(new ErroApi('O produto já faz parte do inventário.', 422), 'Produto repetido');
                    return;
                  }
                  acrescentar.mutate({ url: `/logistica/inventarios/${s.id}/contagem`, dados: { linhas: [{ produto_id: novoProduto, quantidade_contada: null }] } });
                  setNovoProduto(undefined);
                }}
              >
                Acrescentar
              </Button>
            </Space>
          )
        }
      >
        <BarraFiltros style={{ marginBottom: 12 }}>
          <Input.Search placeholder="Código ou produto" allowClear style={{ width: 260, maxWidth: '100%' }} onSearch={setPesquisa} onChange={(e) => !e.target.value && setPesquisa('')} />
          {emContagem && (
            <Button size="small" type={soPorContar ? 'primary' : 'default'} onClick={() => setSoPorContar((x) => !x)}>
              Só por contar
            </Button>
          )}
        </BarraFiltros>
        <Table<LinhaInventario>
          rowKey="id"
          size="small"
          scroll={scrollTabela()}
          dataSource={visiveis}
          columns={emContagem ? colunasContagem : colunasRevisao}
          pagination={{ defaultPageSize: 50, showSizeChanger: true, showTotal: (t) => `${t} artigo(s)` }}
        />
      </Card>
      <ModalMotivo
        aberto={modal === 'reabrir'}
        titulo={`Reabrir o inventário ${nome}`}
        textoOk="Reabrir"
        aviso="Estorna o lançamento e anula os ajustes da regularização; o inventário volta à revisão."
        carregando={accao.isPending}
        aoFechar={() => setModal(null)}
        aoConfirmar={(motivo) => accao.mutate({ url: `/logistica/inventarios/${s.id}/reabrir`, dados: { motivo } })}
      />
      <ModalMotivo
        aberto={modal === 'anular'}
        titulo={`Anular o inventário ${nome}`}
        textoOk="Anular"
        aviso="O armazém volta a aceitar movimentos; nada é regularizado."
        carregando={accao.isPending}
        aoFechar={() => setModal(null)}
        aoConfirmar={(motivo) => accao.mutate({ url: `/logistica/inventarios/${s.id}/anular`, dados: { motivo } })}
      />
    </>
  );
}
