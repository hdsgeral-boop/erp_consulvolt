import { Alert, Button, Card, Checkbox, Form, Input, InputNumber, Modal, Popconfirm, Select, Space, Table, Typography, theme } from 'antd';
import { BranchesOutlined, DeleteOutlined, PlusOutlined, SaveOutlined, ThunderboltOutlined } from '@ant-design/icons';
import { useQueries, useQuery } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { enviar, obter } from '@/api/cliente';
import { ModalFormulario, type CampoFormulario } from '@/componentes/formularios';
import { IconeFa } from '@/componentes/fluxos';
import { BarraFiltros } from '@/componentes/responsivo';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { BotoesExportar, pares } from '@/componentes/impressao';
import { larguraModal, scrollTabela } from '@/componentes/responsivo';
import type { ColunaApi } from '@/componentes/TabelaApi';
import { pedidoTabela } from './comum/impressao';
import { useSessao } from '@/sessao/SessaoContexto';
import { useAccao } from '@/componentes/Accoes';
import { formatarKz } from '@/utilitarios/formatacao';
import { GraficoBarras } from '@/componentes/graficos/Graficos';
import { EtiquetaOrc, Kz, SeletorOrcamentoLegado, useOrcamentosLegado } from './comum/componentes';
import { resultadoPorCenario, variacaoCenario, type LinhaResultadoCenario } from './comum/cenarios';
import { pct } from './comum/controlo';
import { diferenca } from './comum/regras';
import type { CalculoCenario, Cenario } from './comum/tipos';

const VARIAVEIS: { chave: string; rotulo: string }[] = [
  { chave: 'vendas_volume_pct', rotulo: 'Volume de vendas' },
  { chave: 'vendas_preco_pct', rotulo: 'Preço de venda' },
  { chave: 'materias_pct', rotulo: 'Matérias e mercadorias' },
  { chave: 'pessoal_pct', rotulo: 'Pessoal' },
  { chave: 'outros_pct', rotulo: 'Outros custos' },
  { chave: 'cambio_pct', rotulo: 'Câmbio' },
];

/** Orçamento › Cenários what-if (ecrã orc_cenarios): variáveis e ajustes por rubrica sobre um orçamento; gerar nova versão. */
export default function Cenarios() {
  const { pode } = useSessao();
  const navegar = useNavigate();
  const todos = useOrcamentosLegado();
  const [orcamento, setOrcamento] = useState<number>();
  const [novo, setNovo] = useState(false);
  const editar = pode('orc_cenarios_edit');
  // bases do legado: orçamentos sem pai e não substituídos; por omissão o primeiro aprovado (senão o primeiro)
  const bases = (todos.data?.orcamentos ?? []).filter(eBase);
  const base = bases.find((o) => o.id === orcamento);
  useEffect(() => {
    if (orcamento || !bases.length) return;
    setOrcamento((bases.find((o) => o.estado === 'APROVADO') ?? bases[0]).id);
  }, [bases, orcamento]);
  const lista = useQuery({ queryKey: ['orcamento', 'cenarios', orcamento], queryFn: () => obter<Cenario[]>(`/orcamento/orcamentos/${orcamento}/cenarios`), enabled: !!orcamento });
  const padrao = useAccao({ invalidar: [['orcamento', 'cenarios']] });

  // disposição do legado (orcamento_planeamento_ui.js:450-549): acções no cabeçalho, filtro do orçamento base, avisos,
  // cenários e, no fim, a tabela comparativa e o gráfico
  return (
    <>
      <CabecalhoPagina
        titulo="Cenários (what-if)"
        subtitulo="Volume, preço, matérias, pessoal, outros custos e câmbio aplicados ao orçamento base"
        accoes={editar && orcamento ? (
          <>
            <Button icon={<ThunderboltOutlined />} loading={padrao.isPending} onClick={() => padrao.mutate({ url: `/orcamento/orcamentos/${orcamento}/cenarios/padrao` })}>Criar Otimista / Realista / Pessimista</Button>
            <Button type="primary" icon={<PlusOutlined />} onClick={() => setNovo(true)}>Novo cenário</Button>
          </>
        ) : undefined}
      />
      <Card style={{ marginBottom: 16 }}>
        <BarraFiltros>
          <SeletorOrcamentoLegado value={orcamento} onChange={setOrcamento} filtrar={eBase} style={{ width: 420, maxWidth: '100%' }} aria-label="Orçamento base" />
        </BarraFiltros>
      </Card>
      {base && base.estado !== 'APROVADO' && (
        <Alert style={{ marginBottom: 16 }} type="warning" showIcon
          message={`O orçamento base está «${base.estado === 'RASCUNHO' ? 'Rascunho' : base.estado === 'SUBMETIDO' ? 'Submetido' : base.estado}». Pode simular, mas só um orçamento aprovado gera uma nova versão a partir de um cenário.`} />
      )}
      {orcamento && lista.data && !lista.data.length && (
        <Alert style={{ marginBottom: 16 }} type="info" showIcon message="Comece por «Criar otimista / realista / pessimista» e ajuste as percentagens de cada cenário." />
      )}
      {/* grelha de cartões, um por cenário (.orc-cen-grid, auto-fill minmax(290px)) */}
      {lista.isLoading && <Card loading style={{ marginBottom: 16 }} />}
      {!!lista.data?.length && (
        <div data-testid="grelha-cenarios" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(min(100%, 290px), 1fr))', gap: 12, marginBottom: 16 }}>
          {lista.data.map((c) => (
            <CartaoCenario key={c.id} id={c.id} editar={editar} podeVersao={editar && pode('orc_editar')} aoGerar={(oid) => navegar(`/m/orcamento/orc_orcamentos/${oid}`)} />
          ))}
        </div>
      )}
      {orcamento && lista.data && <ResultadoAnual cenarios={lista.data} ano={base?.ano} tipo={base?.tipo ?? 'EXPLORACAO'} />}
      <ModalNovo orcamento={novo ? orcamento ?? null : null} aoFechar={() => setNovo(false)} aoGravar={() => undefined} />
    </>
  );
}

/**
 * Cartão de um cenário como no legado (orcamento_planeamento_ui.js:500-541): barra de cor do tipo, as 6 variáveis em
 * linhas «rótulo (%)» com o campo à direita, a linha «Resultado» com a diferença face à base e os botões Gravar,
 * Ajustes (n), Nova versão e eliminar. Os ajustes por rubrica abrem num modal.
 */
function CartaoCenario({ id, editar, podeVersao, aoGerar }: { id: number; editar: boolean; podeVersao: boolean; aoGerar: (orcamentoId: number) => void }) {
  const q = useQuery({ queryKey: ['orcamento', 'cenario', id], queryFn: () => obter<CalculoCenario>(`/orcamento/cenarios/${id}`) });
  const [variaveis, setVariaveis] = useState<Record<string, number>>({});
  const [ajustes, setAjustes] = useState<Record<string, number>>({});
  const [alterado, setAlterado] = useState(false);
  const [verAjustes, setVerAjustes] = useState(false);
  const gravar = useAccao({ invalidar: [['orcamento']], aoSucesso: () => setAlterado(false) });
  const eliminar = useAccao({ invalidar: [['orcamento', 'cenarios']] });
  const gerar = useAccao<{ id: number }>({ invalidar: [['orcamento']], aoSucesso: (o) => aoGerar(o.id) });
  const { token } = theme.useToken();
  useEffect(() => {
    if (!q.data) return;
    setVariaveis({ ...(q.data.cenario.variaveis ?? {}) });
    setAjustes({ ...(q.data.cenario.ajustes ?? {}) });
    setAlterado(false);
  }, [q.data]);
  if (!q.data) return <Card size="small" loading />;
  const r = q.data;
  const c = r.cenario;
  const variacao = diferenca(r.resultado_cenario, r.resultado_base);
  const cor = COR_CENARIO[c.tipo ?? ''] ?? COR_CENARIO.PERSONALIZADO;
  const nAjustes = Object.keys(ajustes).length;

  const colunas: ColunaApi<CalculoCenario['linhas'][number]>[] = [
    { title: 'Rubrica', key: 'r', render: (_, l) => <><strong>{l.codigo}</strong> {l.nome}</>, valorImpressao: (l) => `${l.codigo} ${l.nome}` },
    { title: 'Variável', dataIndex: 'indutor', render: (v) => VARIAVEIS.find((x) => x.chave === v)?.rotulo ?? v ?? '—' },
    { title: 'Base', dataIndex: 'base', align: 'right', render: (v) => <Kz valor={v} /> },
    { title: 'Cenário', dataIndex: 'cenario', align: 'right', render: (v) => <Kz valor={v} forte /> },
    {
      title: 'Ajuste (%)', key: 'aj', align: 'right',
      valorImpressao: (l) => (ajustes[String(l.rubrica_id)] ? `${ajustes[String(l.rubrica_id)].toLocaleString('pt-PT')}%` : ''),
      render: (_, l) => <InputNumber size="small" min={-100} max={500} precision={2} style={{ width: 100 }} disabled={!editar} value={ajustes[String(l.rubrica_id)] ?? undefined} placeholder="0"
        aria-label={`Ajuste ${l.codigo}`}
        onChange={(x) => { setAjustes((s) => { const n = { ...s }; if (x === null || x === 0) delete n[String(l.rubrica_id)]; else n[String(l.rubrica_id)] = x; return n; }); setAlterado(true); }} />,
    },
  ];

  return (
    <Card size="small" data-testid="cartao-cenario" style={{ borderTop: `3px solid ${cor}` }}
      title={<Space wrap size={4}><span>{c.nome}</span>{c.tipo && <EtiquetaOrc valor={c.tipo} />}</Space>}>
      {VARIAVEIS.map((v) => (
        <div key={v.chave} style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 8, padding: '2px 0' }}>
          <Typography.Text style={{ fontSize: 13 }}>{v.rotulo} (%)</Typography.Text>
          <InputNumber size="small" style={{ width: 84 }} precision={2} disabled={!editar} value={variaveis[v.chave] ?? 0} aria-label={`${v.rotulo} (%) — ${c.nome}`}
            onChange={(x) => { setVariaveis((s) => ({ ...s, [v.chave]: x ?? 0 })); setAlterado(true); }} />
        </div>
      ))}
      <div style={{ borderTop: `1px dashed ${token.colorBorder}`, marginTop: 8, paddingTop: 8, display: 'flex', justifyContent: 'space-between', flexWrap: 'wrap', gap: 4 }}>
        <span>Resultado: <strong style={{ color: Number(r.resultado_cenario) < 0 ? token.colorError : undefined }}>{formatarKz(r.resultado_cenario)}</strong></span>
        <span style={{ color: variacao < 0 ? token.colorError : token.colorSuccess, fontSize: 12.8 }}>{variacao > 0 ? '+' : ''}{formatarKz(variacao)} vs base</span>
      </div>
      {alterado && <Typography.Text type="warning" style={{ display: 'block', fontSize: 12, marginTop: 4 }}>Grave para recalcular o cenário no servidor.</Typography.Text>}
      <Space wrap size={4} style={{ marginTop: 8 }}>
        {editar && <Button type="primary" size="small" icon={<SaveOutlined />} disabled={!alterado} loading={gravar.isPending}
          onClick={() => gravar.mutate({ metodo: 'put', url: `/orcamento/cenarios/${c.id}`, dados: { nome: c.nome, tipo: c.tipo, variaveis, ajustes, notas: c.notas } })}>Gravar</Button>}
        <Button size="small" icon={<IconeFa nome="sliders-h" />} onClick={() => setVerAjustes(true)}>Ajustes ({nAjustes})</Button>
        {podeVersao && (
          <Popconfirm title="Gerar uma nova versão do orçamento a partir deste cenário?" description="Fica em rascunho; o aprovado mantém-se até à aprovação." okText="Gerar" cancelText="Cancelar" disabled={alterado}
            onConfirm={() => gerar.mutate({ url: `/orcamento/cenarios/${c.id}/gerar-versao` })}>
            <Button size="small" icon={<BranchesOutlined />} disabled={alterado} loading={gerar.isPending}>Nova versão</Button>
          </Popconfirm>
        )}
        {editar && (
          <Popconfirm title="Eliminar o cenário?" okText="Eliminar" cancelText="Cancelar" okButtonProps={{ danger: true }} onConfirm={() => eliminar.mutate({ metodo: 'delete', url: `/orcamento/cenarios/${c.id}` })}>
            <Button size="small" danger icon={<DeleteOutlined />} aria-label={`Eliminar ${c.nome}`} />
          </Popconfirm>
        )}
      </Space>
      <Modal open={verAjustes} onCancel={() => setVerAjustes(false)} width={larguraModal(820)} title={`Ajustes por rubrica — ${c.nome}`}
        footer={<Space wrap>
          <BotoesExportar tamanho="small" obterPedido={() => pedidoTabela({
            titulo: 'Cenário what-if', subtitulo: `${c.nome}${alterado ? ' (com alterações por gravar)' : ''}`,
            antes: pares([
              ...VARIAVEIS.map((v): [string, string] => [v.rotulo, `${(variaveis[v.chave] ?? 0).toLocaleString('pt-PT')}%`]),
              ['Resultado base (Kz)', formatarKz(r.resultado_base)], ['Resultado do cenário (Kz)', formatarKz(r.resultado_cenario)], ['Variação (Kz)', formatarKz(variacao)],
            ]),
            colunas, linhas: r.linhas,
          })} />
          <Button onClick={() => setVerAjustes(false)}>Fechar</Button>
        </Space>}>
        <Typography.Paragraph type="secondary">Ajuste em % sobre a rubrica, além da variável; grave o cenário para recalcular.</Typography.Paragraph>
        <Table rowKey="rubrica_id" size="small" pagination={false} dataSource={r.linhas} scroll={scrollTabela(420)} columns={colunas} />
      </Modal>
    </Card>
  );
}

/** Orçamento que pode servir de base aos cenários (legado: sem pai e não substituído). */
function eBase(o: { orcamento_pai_id: number | null; estado: string }): boolean {
  return !o.orcamento_pai_id && o.estado !== 'SUBSTITUIDO';
}

/** «Resultado anual por cenário» (tabela RUBRICA / TOTAL ANUAL <ano>, BASE, cenários… e gráfico), como no legado. */
function ResultadoAnual({ cenarios, ano, tipo }: { cenarios: Cenario[]; ano?: number; tipo: string }) {
  const [detalhe, setDetalhe] = useState(false);
  const { token } = theme.useToken();
  const calculos = useQueries({
    queries: cenarios.map((c) => ({ queryKey: ['orcamento', 'cenario', c.id], queryFn: () => obter<CalculoCenario>(`/orcamento/cenarios/${c.id}`) })),
  });
  // sem cenários: o aviso «Comece por…» já aparece por cima, como no legado
  if (!cenarios.length) return null;
  const prontos = calculos.map((x) => x.data).filter((x): x is CalculoCenario => !!x);
  const carregando = prontos.length < cenarios.length;
  const linhas = carregando ? [] : resultadoPorCenario(prontos, tipo, detalhe);
  const tes = tipo === 'TESOURARIA';
  const nomes = ['Base', ...prontos.map((c) => c.cenario.nome)];
  const res = linhas.find((l) => l.tipo === 'resultado');
  const celula = (l: LinhaResultadoCenario, i: number) => {
    const v = l.valores[i];
    if (v === null || v === undefined) return l.tipo === 'margem' ? '—' : null;
    if (l.tipo === 'margem') return pct(v);
    const base = l.valores[0] ?? 0;
    const d = i > 0 ? variacaoCenario(v, base, l.natureza === 'PROVEITO' || l.natureza === 'RECEBIMENTO' || l.tipo === 'resultado') : null;
    return (
      <>
        <span style={{ color: l.tipo === 'resultado' && v < 0 ? token.colorError : undefined }}>{formatarKz(v)}</span>
        {d && <div style={{ fontSize: 11.2, color: d.favoravel ? token.colorSuccess : token.colorError }}>{d.d > 0 ? '+' : ''}{d.d.toLocaleString('pt-PT', { maximumFractionDigits: 0 })}{d.pct === null ? '' : ` (${pct(d.pct, true)})`}</div>}
      </>
    );
  };
  const colunas = [
    { title: `Rubrica / total anual ${ano ?? ''}`.trim(), key: 'r', render: (_: unknown, l: LinhaResultadoCenario) => (l.tipo === 'rubrica' ? <><strong>{l.codigo}</strong> {l.rotulo}</> : l.rotulo) },
    ...nomes.map((n, i) => ({ title: n, key: `c${i}`, align: 'right' as const, render: (_: unknown, l: LinhaResultadoCenario) => celula(l, i) })),
  ];
  return (
    <>
      <Card size="small" style={{ marginTop: 16 }} loading={carregando}
        title={<Checkbox checked={detalhe} onChange={(e) => setDetalhe(e.target.checked)}>Mostrar todas as rubricas</Checkbox>}>
        <Table<LinhaResultadoCenario>
          rowKey="chave"
          size="small"
          pagination={false}
          dataSource={linhas}
          scroll={scrollTabela()}
          columns={colunas}
          onRow={(l) => ({ style: l.tipo === 'grupo' ? { background: token.colorFillAlter, fontWeight: 700 } : l.tipo === 'total' || l.tipo === 'resultado' ? { background: token.colorFillSecondary, fontWeight: 800, borderTop: l.tipo === 'resultado' ? `2px solid ${token.colorText}` : undefined } : undefined })}
        />
      </Card>
      {res && (
        <Card size="small" style={{ marginTop: 16 }} title={`${tes ? 'Fluxo de caixa' : 'Resultado'} anual por cenário`}>
          {/* legado: uma barra por coluna com a cor do tipo de cenário (base a cinzento) — uma série por coluna, empilhadas */}
          <GraficoBarras rotulos={nomes} monetario altura={240} empilhado
            series={nomes.map((n, i) => ({
              rotulo: n,
              cor: i === 0 ? COR_CENARIO.BASE : COR_CENARIO[prontos[i - 1]?.cenario.tipo ?? ''] ?? COR_CENARIO.PERSONALIZADO,
              valores: res.valores.map((v, j) => (j === i ? v : null)),
            }))} />
        </Card>
      )}
    </>
  );
}

/** Cores dos tipos de cenário do legado (orcamento_planeamento_ui.js:44; base a cinzento). */
const COR_CENARIO: Record<string, string> = { BASE: '#64748b', OTIMISTA: '#15803d', REALISTA: '#1d4ed8', PESSIMISTA: '#b91c1c', PERSONALIZADO: '#7c3aed' };
const TIPOS_CENARIO = [{ value: 'OTIMISTA', label: 'Otimista' }, { value: 'REALISTA', label: 'Realista' }, { value: 'PESSIMISTA', label: 'Pessimista' }, { value: 'PERSONALIZADO', label: 'Personalizado' }];

/** Regras de gravação do cenário novo, comuns à grade e à vista Linhas (nas linhas podem vir já as variáveis em %). */
export function dadosCenario(v: Record<string, unknown>, orcamento: number | null): Record<string, unknown> {
  const variaveis: Record<string, number> = {};
  for (const x of VARIAVEIS) {
    const n = v[x.chave];
    if (n !== undefined && n !== null && n !== '' && Number(n) !== 0) variaveis[x.chave] = Number(n);
  }
  return { orcamento_anual_id: orcamento, nome: typeof v.nome === 'string' ? v.nome.trim() : v.nome, tipo: v.tipo || 'PERSONALIZADO', variaveis, ajustes: {}, notas: v.notas || null };
}

/** Vista Linhas do «Novo cenário»: um cenário por linha; as variáveis (%) são colunas opcionais. */
export const CAMPOS_CENARIO: CampoFormulario[] = [
  { nome: 'nome', rotulo: 'Nome', obrigatorio: true, largura: 220, maxLength: 255, aliases: ['Cenário'] },
  { nome: 'tipo', rotulo: 'Tipo', tipo: 'select', opcoes: TIPOS_CENARIO, omissao: 'PERSONALIZADO', largura: 140 },
  ...VARIAVEIS.map((x): CampoFormulario => ({ nome: x.chave, rotulo: `${x.rotulo} (%)`, tipo: 'numero', precisao: 2, min: -100, max: 500, largura: 110, emLinhas: 'opcional', seccao: 'Variáveis', aliases: [x.rotulo] })),
  { nome: 'notas', rotulo: 'Notas', largura: 240, maxLength: 5000, emLinhas: 'opcional', seccao: 'Outros' },
];

function ModalNovo({ orcamento, aoFechar, aoGravar }: { orcamento: number | null; aoFechar: () => void; aoGravar: (c: Cenario) => void }) {
  const [form] = Form.useForm();
  const accao = useAccao<Cenario>({ invalidar: [['orcamento', 'cenarios']], aoSucesso: (c) => { aoGravar(c); aoFechar(); } });
  useEffect(() => { if (orcamento) form.setFieldsValue({ nome: '', tipo: 'PERSONALIZADO', notas: '' }); }, [orcamento, form]);
  return (
    <ModalFormulario chave="orcamento.cenarios" titulo="Novo cenário" aberto={!!orcamento} aoFechar={aoFechar} criacao form={form} campos={CAMPOS_CENARIO}
      nomeRegistos="cenários" invalidar={[['orcamento', 'cenarios']]} gravarLinha={(r) => enviar('post', '/orcamento/cenarios', dadosCenario(r, orcamento))}
      nota="Um cenário por linha, sobre o orçamento base escolhido. As variáveis (%) podem ficar em branco e ajustar-se depois no cenário. Linhas deixadas em branco são ignoradas. Campos com * são obrigatórios."
      onOk={() => form.submit()} okText="Criar" cancelText="Cancelar" confirmLoading={accao.isPending}>
      <Form form={form} layout="vertical" onFinish={(v) => accao.mutate({ url: '/orcamento/cenarios', dados: dadosCenario(v, orcamento) })}>
        <Form.Item name="nome" label="Nome" rules={[{ required: true, message: 'Indique o nome.' }]}><Input maxLength={255} /></Form.Item>
        <Form.Item name="tipo" label="Tipo"><Select options={TIPOS_CENARIO} /></Form.Item>
        <Form.Item name="notas" label="Notas"><Input.TextArea rows={2} maxLength={5000} /></Form.Item>
      </Form>
    </ModalFormulario>
  );
}
