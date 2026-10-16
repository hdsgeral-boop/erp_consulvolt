import { Alert, Button, Card, Checkbox, Col, DatePicker, Descriptions, Flex, Form, Input, InputNumber, Modal, Row, Select, Skeleton, Space, Table, Tag, theme, Typography } from 'antd';
import { BotoesExportar, tabelaHtml } from '@/componentes/impressao';
import { IconeFa } from '@/componentes/fluxos/IconeFa';
import { CartaoKpi, CORES_KPI, EstadoVazio, GrelhaKpi, type PropsEstadoVazio } from '@/componentes/vistas';
import { ArrowLeftOutlined, PlusOutlined, SaveOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import type { ColumnsType } from 'antd/es/table';
import { useArmazens } from '@/modulos/compras/comum/referencias';
import dayjs, { type Dayjs } from 'dayjs';
import { useMemo, useState } from 'react';
import { Navigate, Route, Routes, useNavigate, useParams, useSearchParams } from 'react-router-dom';
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

/** Títulos, textos e estados vazios do legado (js/ui_inventory.js:75-76, 337-353, 645-656). */
const TITULOS: Record<ModoInventario, { titulo: string; subtitulo: string; estado?: string; vazio: PropsEstadoVazio; seleccionar?: string; escolha?: string; semEscolha?: string }> = {
  sessoes: {
    titulo: 'Sessões de Inventário',
    subtitulo: 'Crie ou consulte as folhas de contagem cega para os Armazens.',
    vazio: { icone: 'tasks', titulo: 'Sem sessões de Inventário registadas.' },
  },
  contagem: {
    titulo: 'Folhas de Contagem Ativas',
    subtitulo: 'Registe as quantidades físicas reais que encontrar nas prateleiras.',
    estado: 'EM_CONTAGEM',
    seleccionar: 'Selecionar Folha:',
    escolha: '-- Escolha um Armazém --',
    semEscolha: 'Por favor, selecione uma folha de contagem acima para iniciar.',
    vazio: { icone: 'clipboard-list', titulo: 'Nenhuma Folha de Contagem Ativa', texto: 'Não existem Inventários em fase de contagem no momento.' },
  },
  revisao: {
    titulo: 'Revisão de Inventário e Regularização',
    subtitulo: 'Analise as Diferenças encontradas e gere os movimentos de quebra/sobra.',
    estado: 'REVISAO',
    seleccionar: 'Selecionar Lote:',
    escolha: '-- Escolha um Lote --',
    semEscolha: 'Por favor, selecione um lote pendente de revisão acima.',
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
  // legado: «Efetuar Contagem» e «Revisão e Regularização» são uma folha de trabalho com selector de lote (não uma lista)
  if (modo !== 'sessoes') {
    return (
      <Routes>
        <Route index element={<FolhasActivas modo={modo} />} />
        <Route path=":id" element={<IrParaLote />} />
      </Routes>
    );
  }
  return (
    <Routes>
      <Route index element={<ListaSessoes modo={modo} />} />
      <Route path=":id" element={<DetalheSessao modo={modo} />} />
    </Routes>
  );
}

/** Endereços antigos …/inventario_contagem/<id> passam a …/inventario_contagem?lote=<id>. */
function IrParaLote() {
  const { id } = useParams();
  return <Navigate to={`..?lote=${encodeURIComponent(id ?? '')}`} replace />;
}

/**
 * Cabeçalho do legado com «Selecionar Folha:» / «Selecionar Lote:» (renderInvContagemTab / renderInvRevisaoTab,
 * js/ui_inventory.js:327-380, 616-668) e a folha de trabalho do lote escolhido por baixo; com um só lote abre-o logo.
 */
function FolhasActivas({ modo }: { modo: Exclude<ModoInventario, 'sessoes'> }) {
  const t = TITULOS[modo];
  const [params, setParams] = useSearchParams();
  const armazens = useArmazens();
  const sessoes = useQuery({
    queryKey: ['logistica', 'inventarios', 'activos', t.estado],
    queryFn: () => obterPagina<SessaoInventario>('/logistica/inventarios', { estado: t.estado, por_pagina: 200 }),
  });
  const lista = sessoes.data?.itens ?? [];
  const pedido = params.get('lote');
  const lote = pedido ?? (lista.length === 1 ? String(lista[0].id) : null);

  if (sessoes.isLoading) return <Skeleton active />;
  if (!lista.length && !pedido) {
    return (
      <Card>
        <EstadoVazio {...t.vazio} />
      </Card>
    );
  }
  const nomeArmazem = (id: number) => armazens.data?.find((a) => a.id === id)?.nome ?? '-';
  return (
    <>
      <Flex justify="space-between" align="center" gap={12} wrap style={{ marginBottom: 24 }}>
        <div>
          <h3 style={{ margin: 0, fontSize: '1.2rem', color: 'var(--ant-color-primary, #2563eb)' }}>{t.titulo}</h3>
          <Typography.Text type="secondary" style={{ fontSize: '0.85rem' }}>{t.subtitulo}</Typography.Text>
        </div>
        <Flex align="center" gap={10} wrap>
          <label htmlFor={`inv-${modo}-lote`} style={{ fontWeight: 600, margin: 0 }}>{t.seleccionar}</label>
          <Select
            id={`inv-${modo}-lote`}
            style={{ minWidth: 250, maxWidth: '100%' }}
            placeholder={t.escolha}
            allowClear
            value={lote ?? undefined}
            onChange={(v?: string) => setParams(v ? { lote: v } : {}, { replace: true })}
            options={lista.map((s) => ({ value: String(s.id), label: `Lote #${s.id} - ${nomeArmazem(s.armazem_id)} (${formatarData(s.data)})` }))}
          />
        </Flex>
      </Flex>
      {lote ? (
        <DetalheSessao key={lote} modo={modo} idSessao={lote} />
      ) : (
        <Alert type="info" style={{ textAlign: 'center', padding: '2rem' }} message={t.semEscolha} />
      )}
    </>
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
  // campos, textos e botões do formulário «Nova Sessão de Inventário» do legado (js/ui_inventory.js:107-136)
  return (
    <Modal width={larguraModal(620)} title="Nova Sessão de Inventário" open={aberto} onCancel={aoFechar} okText="Gerar Folha de Contagem" cancelText="Cancelar" confirmLoading={accao.isPending} onOk={() => form.submit()} destroyOnHidden>
      <Form form={form} layout="vertical" preserve={false} initialValues={{ data: dayjs() }} onFinish={(v) => accao.mutate({ url: '/logistica/inventarios', dados: { armazem_id: v.armazem_id, data: dataApi(v.data), descricao: v.descricao || undefined } })}>
        <Row gutter={16}>
          <Col xs={24} sm={12}>
            <Form.Item name="armazem_id" label="Armazém Alvo" rules={[{ required: true, message: 'Escolha o armazém.' }]}>
              <SeletorArmazem placeholder="-- Selecione o Armazém --" />
            </Form.Item>
          </Col>
          <Col xs={24} sm={12}>
            <Form.Item name="data" label="Data Prevista" rules={[{ required: true }]}>
              <DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} />
            </Form.Item>
          </Col>
        </Row>
        <Form.Item name="descricao" label="Descrição / Referência" rules={[{ max: 500 }]}>
          <Input placeholder="Ex: Inventário Anual 2026, ou Contagem de Fim de Mês" />
        </Form.Item>
        <Alert
          type="info"
          showIcon
          style={{ fontSize: '0.85rem' }}
          message="Ao iniciar, o sistema criará uma «Folha de Contagem Cega» para este Armazém, registando o stock atual (esperado) em background. Os operadores não verão estas quantidades durante a contagem."
        />
        <Alert type="warning" showIcon style={{ marginTop: 8, fontSize: '0.85rem' }} message="Enquanto o inventário estiver aberto, o armazém fica sem movimentos até à aprovação ou anulação." />
      </Form>
    </Modal>
  );
}

function DetalheSessao({ modo, idSessao }: { modo: ModoInventario; idSessao?: string }) {
  const params = useParams();
  const id = idSessao ?? params.id;
  const navegar = useNavigate();
  const { pode } = useSessao();
  const { token } = theme.useToken();
  const [busca, setBusca] = useState('');
  const [mostrarSemDiferenca, setMostrarSemDiferenca] = useState(false);
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
  const concluir = useAccao({
    invalidar: [['logistica']],
    aoSucesso: () => {
      limpar();
      // legado (finishInvCount): concluída a contagem, segue para a «Revisão e Regularização»
      if (modo === 'contagem' && pode('inventario_revisao_view')) navegar(`/m/stock/inventario_revisao?lote=${id}`);
    },
  });
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
  const filtroTexto = modo === 'contagem' ? busca : pesquisa;
  const visiveis = linhasRevistas.filter(
    (l) =>
      contemTexto(filtroTexto, l.codigo, l.nome) &&
      (!soPorContar || (edicoes[l.produto_id]?.quantidade_contada ?? l.quantidade_contada) == null) &&
      // legado (revisão): por omissão só os artigos com diferença; «Mostrar artigos sem Diferença» mostra os restantes
      (modo !== 'revisao' || mostrarSemDiferenca || Number(l.diferenca ?? 0) !== 0),
  );
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

  const colunaQuantidade: ColumnsType<LinhaInventario>[number] = {
      title: 'Quantidade contada',
      key: 'q',
      render: (_, l) => {
        const valor = edicoes[l.produto_id]?.quantidade_contada !== undefined ? edicoes[l.produto_id].quantidade_contada : l.quantidade_contada == null ? null : Number(l.quantidade_contada);
        return editarContagem ? (
          <InputNumber min={0} value={valor} style={{ width: 140 }} placeholder="por contar" onChange={(v) => setEdicoes((e) => ({ ...e, [l.produto_id]: { ...e[l.produto_id], quantidade_contada: v } }))} />
        ) : valor == null ? <Typography.Text type="secondary">por contar</Typography.Text> : formatarNumero(valor);
      },
    };
  const colunaObservacoes: ColumnsType<LinhaInventario>[number] = {
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
    };
  const colunasContagem: ColumnsType<LinhaInventario> = [
    { title: 'Código', dataIndex: 'codigo', render: (v) => v || '—' },
    { title: 'Produto', dataIndex: 'nome' },
    colunaQuantidade,
    colunaObservacoes,
  ];

  const colunaCusto: ColumnsType<LinhaInventario>[number] = {
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
    };
  const colunaJustificacao: ColumnsType<LinhaInventario>[number] = {
      title: 'Justificação',
      key: 'just',
      render: (_, l) =>
        editarRevisao && Number(l.diferenca ?? 0) !== 0 ? (
          <Input maxLength={500} style={{ width: 240, maxWidth: '100%' }} value={l.justificacao ?? ''} onChange={(ev) => setRevisao((r) => ({ ...r, [l.produto_id]: { ...r[l.produto_id], justificacao: ev.target.value } }))} />
        ) : (
          l.justificacao || '—'
        ),
    };
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
    colunaCusto,
    colunaJustificacao,
    { title: 'Valor da diferença', key: 'valor', align: 'right', render: (_, l) => (l.valor_diferenca ? formatarKz(l.valor_diferenca) : formatarKz(Math.abs(Number(l.diferenca ?? 0)) * Number(l.custo_personalizado ?? l.custo_medio ?? 0) * Math.sign(Number(l.diferenca ?? 0)))) },
  ];

  const acrescentarProduto = () => {
    if (!novoProduto) return;
    if (linhas.some((l) => l.produto_id === novoProduto)) {
      notificarErro(new ErroApi('O produto já faz parte do inventário.', 422), 'Produto repetido');
      return;
    }
    acrescentar.mutate({ url: `/logistica/inventarios/${s.id}/contagem`, dados: { linhas: [{ produto_id: novoProduto, quantidade_contada: null }] } });
    setNovoProduto(undefined);
  };
  const gravarContagem = () => accao.mutate({ url: `/logistica/inventarios/${s.id}/contagem`, dados: { linhas: alteradas } });
  const gravarRevisao = () =>
    accao.mutate({
      url: `/logistica/inventarios/${s.id}/revisao`,
      dados: {
        linhas: linhasRevistas
          .filter((l) => revisao[l.produto_id])
          .map((l) => ({ produto_id: l.produto_id, custo_personalizado: l.custo_personalizado === null || l.custo_personalizado === '' ? null : Number(l.custo_personalizado), justificacao: l.justificacao || null })),
      },
    });
  const voltarContagem = () =>
    Modal.confirm({
      title: 'Voltar à contagem?',
      content: modo === 'revisao' ? 'Tem a certeza que deseja devolver este lote para a fase de contagem? Os operadores poderão alterar as quantidades novamente.' : undefined,
      okText: 'Voltar à contagem',
      cancelText: 'Cancelar',
      onOk: () =>
        accao
          .mutateAsync({ url: `/logistica/inventarios/${s.id}/voltar-contagem` })
          .then(() => {
            if (modo === 'revisao' && pode('inventario_contagem_view')) navegar(`/m/stock/inventario_contagem?lote=${s.id}`);
          })
          .catch(() => undefined),
    });

  /** Leitor de código de barras do legado (handleInvBarcodeScan): Enter com o código exacto soma 1 à quantidade contada. */
  const lerCodigo = () => {
    const q = busca.trim().toLowerCase();
    if (!q || !editarContagem) return;
    const l = linhas.find((x) => (x.codigo ?? '').trim().toLowerCase() === q);
    if (!l) {
      notificarErro(new ErroApi('Código ou Artigo não encontrado na folha de contagem!', 422), 'Código não encontrado');
      return;
    }
    const actual = edicoes[l.produto_id]?.quantidade_contada !== undefined ? edicoes[l.produto_id].quantidade_contada : l.quantidade_contada == null ? null : Number(l.quantidade_contada);
    setEdicoes((e) => ({ ...e, [l.produto_id]: { ...e[l.produto_id], quantidade_contada: Number(actual ?? 0) + 1 } }));
    setBusca('');
  };

  if (modo !== 'sessoes') {
    // Folha de trabalho do legado (loadFolhaContagem / loadFolhaRevisação, js/ui_inventory.js:384-473, 671-812)
    const revisaoFolha = modo === 'revisao';
    const codigo = (v: string | null) => <span style={{ fontFamily: 'monospace', fontSize: '0.85rem', color: token.colorTextSecondary }}>{v || '-'}</span>;
    const artigo = (v: string) => <span style={{ fontWeight: 600 }}>{v}</span>;
    const colunasFolha: ColumnsType<LinhaInventario> = revisaoFolha
      ? [
          { title: 'Código', dataIndex: 'codigo', width: '10%', render: codigo },
          { title: 'Artigo', dataIndex: 'nome', render: artigo },
          { title: 'Qtd. Sistema', dataIndex: 'quantidade_sistema', align: 'center', render: formatarNumero },
          { title: 'Qtd. Contada', dataIndex: 'quantidade_contada', align: 'center', render: (v: string | null) => <span style={{ fontWeight: 700, color: token.colorPrimary }}>{formatarNumero(v)}</span> },
          {
            title: 'Diferença',
            dataIndex: 'diferenca',
            align: 'center',
            render: (v: string | null) => {
              const n = Number(v ?? 0);
              if (!n) return <Tag>OK (0)</Tag>;
              return n > 0 ? <Tag color="green">+{formatarNumero(n)} (Sobra)</Tag> : <Tag color="red">{formatarNumero(n)} (Quebra)</Tag>;
            },
          },
          { ...colunaCusto, title: 'Custo Unitario', align: 'right' },
          {
            title: 'Impacto Financeiro',
            key: 'impacto',
            align: 'right',
            render: (_, l) => {
              const dif = Number(l.diferenca ?? 0);
              if (!dif) return <Typography.Text type="secondary">-</Typography.Text>;
              const custo = l.custo_personalizado != null && l.custo_personalizado !== '' ? Number(l.custo_personalizado) : Number(l.custo_unitario ?? l.custo_medio ?? 0);
              return <Typography.Text type="secondary" style={{ fontSize: '0.8rem' }}>{formatarKz(Math.abs(dif) * custo)}</Typography.Text>;
            },
          },
          { ...colunaJustificacao, title: 'Justificação / Nota', width: '25%' },
          { title: 'Custo médio', dataIndex: 'custo_medio', align: 'right', render: (v: string | null) => formatarKz(v) },
        ]
      : [
          { title: 'Código', dataIndex: 'codigo', width: '20%', render: codigo },
          { title: 'Artigo', dataIndex: 'nome', render: artigo },
          { ...colunaQuantidade, title: 'Quantidade Contada', align: 'center' },
          colunaObservacoes,
        ];
    const temRevisaoPorGravar = Object.keys(revisao).length > 0;
    return (
      <Card
        style={{ borderTop: `4px solid ${token.colorPrimary}` }}
        styles={{ body: { padding: 0 } }}
        title={
          <div style={{ whiteSpace: 'normal', padding: '8px 0' }}>
            <span>
              <IconeFa nome={revisaoFolha ? 'balance-scale' : 'boxes'} /> {revisaoFolha ? 'Regularização' : 'Contagem'}: <NomeArmazem id={s.armazem_id} />
            </span>
            <div style={{ fontSize: '0.8rem', fontWeight: 400, marginTop: 4, color: token.colorTextSecondary }}>
              Lote: #{s.id} | Ref: {s.descricao ?? ''}
            </div>
          </div>
        }
        extra={
          revisaoFolha ? (
            <Checkbox checked={mostrarSemDiferenca} onChange={(e) => setMostrarSemDiferenca(e.target.checked)} style={{ fontSize: '0.8rem' }}>
              Mostrar artigos sem Diferença
            </Checkbox>
          ) : (
            <Input
              prefix={<IconeFa nome="barcode" style={{ color: '#94a3b8' }} />}
              placeholder="Scan Código Barras / Pesquisa..."
              aria-label="Código de barras ou pesquisa"
              allowClear
              style={{ width: 300, maxWidth: '100%', height: 40 }}
              value={busca}
              onChange={(e) => setBusca(e.target.value)}
              onPressEnter={lerCodigo}
            />
          )
        }
      >
        {s.estado === 'ANULADA' && <Alert type="error" showIcon banner message={`Inventário anulado${s.motivo_anulacao ? `: ${s.motivo_anulacao}` : '.'}`} />}
        {!revisaoFolha && !emContagem && <Alert type="info" showIcon banner message="Este inventário já não está em contagem." />}
        {revisaoFolha && s.estado !== 'REVISAO' && <Alert type="info" showIcon banner message="Este inventário não está em revisão." />}
        {revisaoFolha && s.estado !== 'ANULADA' && (
          // resumo financeiro do legado (ui_inventory.js:756-770); saldo e diferenças por justificar são do sistema novo e ficam a seguir
          <div style={{ padding: '1.5rem', background: token.colorFillAlter, borderBottom: `1px solid ${token.colorBorderSecondary}` }}>
            <GrelhaKpi larguraMinima={200}>
              <CartaoKpi barra="esquerda" cor="laranja" rotulo="Artigos com Diferença" valor={previsao.sobras.linhas + previsao.quebras.linhas} />
              <CartaoKpi barra="esquerda" cor="vermelho" rotulo="Custo Global Quebras" valor={formatarKz(previsao.quebras.valor)} unidade="Kz" corValor={CORES_KPI.vermelho} />
              <CartaoKpi barra="esquerda" cor="verde" rotulo="Custo Global Sobras" valor={formatarKz(previsao.sobras.valor)} unidade="Kz" corValor={CORES_KPI.verde} />
              <CartaoKpi barra="esquerda" cor="azul" rotulo="Saldo da regularização" valor={formatarKz(previsao.sobras.valor - previsao.quebras.valor)} unidade="Kz" />
              <CartaoKpi barra="esquerda" cor={previsao.porJustificar ? 'laranja' : 'cinzento'} rotulo="Diferenças por justificar" valor={previsao.porJustificar} />
            </GrelhaKpi>
          </div>
        )}
        <div style={{ padding: '1.5rem' }}>
          <BarraFiltros style={{ marginBottom: 12 }}>
            {revisaoFolha && <Input.Search placeholder="Código ou produto" allowClear style={{ width: 260, maxWidth: '100%' }} onSearch={setPesquisa} onChange={(e) => !e.target.value && setPesquisa('')} />}
            {editarContagem && (
              <>
                <Button size="small" type={soPorContar ? 'primary' : 'default'} onClick={() => setSoPorContar((x) => !x)}>Só por contar</Button>
                <SeletorProduto apenasStock allowClear placeholder="Acrescentar produto não listado" style={{ width: 300, maxWidth: '100%' }} value={novoProduto} onChange={setNovoProduto} />
                <Button size="small" icon={<PlusOutlined />} disabled={!novoProduto} loading={acrescentar.isPending} onClick={acrescentarProduto}>Acrescentar</Button>
              </>
            )}
            <BotoesExportar tamanho="small" obterPedido={() => pedidoInventario(s, nome, armazens.data?.find((x) => x.id === s.armazem_id)?.nome ?? `#${s.armazem_id}`)} />
          </BarraFiltros>
          <Table<LinhaInventario>
            rowKey="id"
            size="small"
            scroll={scrollTabela()}
            dataSource={visiveis}
            columns={colunasFolha}
            pagination={{ defaultPageSize: 50, showSizeChanger: true, hideOnSinglePage: true, showTotal: (t) => `${t} artigo(s)` }}
          />
        </div>
        <Flex justify="space-between" align="center" gap={12} wrap style={{ padding: '1.5rem', background: token.colorFillAlter, borderTop: `1px solid ${token.colorBorderSecondary}` }}>
          {revisaoFolha ? (
            <Typography.Text type="secondary" style={{ fontSize: '0.85rem' }}>
              <IconeFa nome="exclamation-triangle" style={{ color: '#f59e0b' }} /> A Regularização irá criar os movimentos de acerto e Lançamentos contabilísticos automaticamente.
            </Typography.Text>
          ) : (
            <Typography.Text type="secondary" style={{ fontSize: '0.85rem' }}>
              <IconeFa nome="info-circle" /> Os valores em branco serão assumidos como pendentes.{emContagem ? ` (${porContar} por contar)` : ''}
            </Typography.Text>
          )}
          <Space wrap>
            {editarContagem && (
              <>
                <Button icon={<IconeFa nome="save" />} title="Guardar para continuar mais tarde" disabled={!alteradas.length} loading={accao.isPending} onClick={gravarContagem}>
                  Guardar Rascunho{alteradas.length ? ` (${alteradas.length})` : ''}
                </Button>
                <Button type="primary" icon={<IconeFa nome="check-double" />} loading={concluir.isPending} onClick={terminarContagem}>
                  Concluir Contagem
                </Button>
              </>
            )}
            {editarRevisao && (
              <Button icon={<IconeFa nome="save" />} title="Guardar justificações e custos para continuar depois" disabled={!temRevisaoPorGravar} loading={accao.isPending} onClick={gravarRevisao}>
                Guardar Rascunho
              </Button>
            )}
            {a.voltarContagem && revisaoFolha && (
              <Button icon={<IconeFa nome="undo" />} title="Reabrir Contagem" onClick={voltarContagem}>
                Reabrir Contagem
              </Button>
            )}
            {a.aprovar && revisaoFolha && (
              <Button type="primary" icon={<IconeFa nome="check-circle" />} disabled={temRevisaoPorGravar} title={temRevisaoPorGravar ? 'Guarde o rascunho antes de aprovar' : undefined} onClick={aprovar}>
                Aprovar e Regularizar Stock
              </Button>
            )}
          </Space>
        </Flex>
      </Card>
    );
  }

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
                <Button type="primary" icon={<SaveOutlined />} disabled={!alteradas.length} loading={accao.isPending} onClick={gravarContagem}>
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
                onClick={gravarRevisao}
              >
                Gravar revisão
              </Button>
            )}
            {a.voltarContagem && (
              <Button onClick={voltarContagem}>
                Voltar à contagem
              </Button>
            )}
            {a.aprovar && <Button type="primary" disabled={Object.keys(revisao).length > 0} onClick={aprovar}>Aprovar</Button>}
            {a.reabrir && <Button style={{ color: '#f59e0b', borderColor: '#f59e0b' }} onClick={() => setModal('reabrir')}>Reabrir</Button>}
            {a.anular && <Button danger onClick={() => setModal('anular')}>Anular</Button>}
          </>
        }
      />
      {s.estado === 'ANULADA' && <Alert type="error" showIcon style={{ marginBottom: 16 }} message={`Inventário anulado${s.motivo_anulacao ? `: ${s.motivo_anulacao}` : '.'}`} />}

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
                onClick={acrescentarProduto}
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
