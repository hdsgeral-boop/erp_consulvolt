import { Alert, Button, Card, Empty, Modal, Select, Spin, Table, Typography, theme } from 'antd';
import { useQuery } from '@tanstack/react-query';
import dayjs from 'dayjs';
import { Fragment, useEffect, useMemo, useState, type CSSProperties, type ReactNode } from 'react';
import { useSearchParams } from 'react-router-dom';
import { obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { IconeFa } from '@/componentes/fluxos';
import { GraficoBarras } from '@/componentes/graficos/Graficos';
import type { ColunaApi } from '@/componentes/TabelaApi';
import { CartaoKpi, GrelhaKpi, TabelaComModos } from '@/componentes/vistas';
import { larguraModal, scrollTabela } from '@/componentes/responsivo';
import { formatarData, formatarKz } from '@/utilitarios/formatacao';
import { SeletorOrcamentoLegado, useOrcamentosLegado } from './comum/componentes';
import {
  COR_BARRA, COR_LEITURA, NOME_NATUREZA, TEXTO_CLASSIFICACAO, TEXTO_LEITURA, corDesvio, leitura, mesesDaVista, naturezas, pct, principaisDesvios, resultado,
  linhasTabelaControlo, rotuloPeriodo, sentidoDesvio, seriesMensais, totaisPorNatureza, type LinhaTabelaControlo, type VistaControlo,
} from './comum/controlo';
import { MESES, somaValores } from './comum/regras';
import type { CausasDesvio, Controlo as DadosControlo, LinhaControlo, NaturezaRubrica } from './comum/tipos';

/** Valor em Kz como no legado («1 234,50 Kz»). */
const kz = (v: number | string | null | undefined) => formatarKz(v ?? 0, true);

// faixas do legado (.orc-aviso / .orc-info) com fundos translúcidos: ficam legíveis também na tela nocturna
const ESTILO_AVISO: CSSProperties = { border: '1px solid rgba(245,158,11,0.45)', background: 'rgba(245,158,11,0.1)', color: '#b45309', borderRadius: 4, padding: '0.5rem 0.7rem', fontSize: 13, marginBottom: 10 };
const ESTILO_INFO: CSSProperties = { border: '1px solid rgba(37,99,235,0.35)', background: 'rgba(37,99,235,0.08)', color: '#1d4ed8', borderRadius: 4, padding: '0.5rem 0.7rem', fontSize: 13, marginBottom: 10 };
/** Tipo de desvio (.orc-classe, orcamento_planeamento_ui.js:53-54): barra esquerda de 4 px com a cor do tipo. */
const COR_CLASSE: Record<string, string> = { ESTRUTURAL: '#b91c1c', PONTUAL: '#d97706', TEMPORAL: '#0891b2' };
const estiloClasse = (c: string): CSSProperties => {
  const cor = COR_CLASSE[c] ?? '#1d4ed8';
  return { ...ESTILO_INFO, borderColor: `${cor}66`, borderLeft: `4px solid ${cor}`, background: `${cor}14`, color: cor };
};

/** Cartão de indicador do legado (.orc-kpi): rótulo pequeno, valor grande e linha cinzenta por baixo. */
function KpiOrc({ rotulo, valor, negativo, rodape }: { rotulo: ReactNode; valor: ReactNode; negativo?: boolean; rodape?: ReactNode }) {
  return <CartaoKpi barra="nenhuma" rotulo={rotulo} valor={valor} secundaria={rodape} corValor={negativo ? '#b91c1c' : undefined} style={{ height: '100%' }} />;
}

/** Barra de execução (0–150 %) com a cor da leitura. */
function BarraExecucao({ l }: { l: LinhaControlo }) {
  const p = l.execucao_pct === null ? 0 : Math.max(0, Math.min(150, l.execucao_pct));
  return (
    <div title={l.execucao_pct === null ? 'Sem orçamento' : `${pct(l.execucao_pct)} executado`} style={{ height: 8, background: 'rgba(148,163,184,0.3)', borderRadius: 4, overflow: 'hidden', minWidth: 80 }}>
      <div style={{ height: '100%', width: `${p / 1.5}%`, background: COR_BARRA[leitura(l)] }} />
    </div>
  );
}

/** Orçamento › Controlo Orçamental (ecrã orc_controlo): orçado vs realizado (Diário), desvios e grau de execução por rubrica. */
export default function Controlo() {
  const [params] = useSearchParams();
  const lista = useOrcamentosLegado();
  const [orcamento, setOrcamento] = useState<number>();
  const [mes, setMes] = useState(dayjs().month() + 1);
  const [vista, setVista] = useState<VistaControlo>('ACUMULADO');
  const [analise, setAnalise] = useState<LinhaControlo | null>(null);
  const { token } = theme.useToken();

  // por omissão como no legado: o orçamento pedido (?orcamento=, «Orçado vs realizado» da lista), senão o primeiro aprovado da ordem do legado
  useEffect(() => {
    const ls = lista.data?.orcamentos;
    if (orcamento || !ls?.length) return;
    const pedido = Number(params.get('orcamento'));
    setOrcamento(ls.some((o) => o.id === pedido) ? pedido : (lista.data?.por_omissao ?? ls[0].id));
  }, [lista.data, orcamento, params]);

  const q = useQuery({
    queryKey: ['orcamento', 'controlo', orcamento, mes, vista],
    queryFn: () => obter<DadosControlo>(`/orcamento/orcamentos/${orcamento}/controlo`, { mes, vista }),
    enabled: !!orcamento,
  });
  const c = q.data;
  const tes = c?.orcamento.tipo === 'TESOURARIA';
  const periodo = c ? rotuloPeriodo(vista, mes, c.orcamento.ano) : '';
  const [natA, natB] = naturezas(c?.orcamento.tipo ?? 'EXPLORACAO');
  const totais = useMemo(() => (c ? totaisPorNatureza(c) : null), [c]);
  const res = useMemo(() => (c ? resultado(c) : null), [c]);
  const piores = useMemo(() => (c ? principaisDesvios(c.linhas) : []), [c]);
  const corrigido = !!c && c.orcamento.versao > 1 && c.linhas.some((l) => l.orcado_inicial !== null && l.orcado_inicial !== l.orcado);
  // disposição do legado: natureza → rubricas → «Total <natureza>» e, no fim, o resultado (fluxo de caixa)
  const linhas = useMemo(() => (c ? linhasTabelaControlo(c) : []), [c]);
  const mensal = useMemo(() => (c ? seriesMensais(c, vista, mes) : null), [c, vista, mes]);
  const nomeResultado = tes ? 'Fluxo de caixa' : 'Resultado';

  const sintetica = (l: LinhaTabelaControlo) => !!l.tipo_linha;
  const nCols = 9 + (corrigido ? 1 : 0);
  const soNaRubrica = (l: LinhaTabelaControlo) => (l.tipo_linha === 'natureza' ? { colSpan: 0 } : {});
  const colunas: ColunaApi<LinhaTabelaControlo>[] = [
    {
      title: 'Rubrica', key: 'r', valorFiltro: (l) => `${l.codigo} ${l.nome}`, valorImpressao: (l) => (l.tipo_linha ? l.nome : `${l.codigo} ${l.nome}`),
      onCell: (l) => (l.tipo_linha === 'natureza' ? { colSpan: nCols } : {}),
      render: (_, l) => (l.tipo_linha ? <strong>{l.nome}</strong> : <><strong>{l.codigo}</strong> {l.nome}</>),
    },
    { title: 'Natureza', dataIndex: 'natureza', ocultaPorOmissao: true, onCell: soNaRubrica, valorFiltro: (l) => NOME_NATUREZA[l.natureza], render: (v: NaturezaRubrica) => NOME_NATUREZA[v] },
    ...(corrigido ? [{ title: 'Inicial', dataIndex: 'orcado_inicial', align: 'right', onCell: soNaRubrica, render: (v: string | null) => <Typography.Text type="secondary">{formatarKz(v)}</Typography.Text>, valorImpressao: (l: LinhaTabelaControlo) => formatarKz(l.orcado_inicial) } as ColunaApi<LinhaTabelaControlo>] : []),
    { title: 'Orçado', dataIndex: 'orcado', align: 'right', onCell: soNaRubrica, render: (v) => formatarKz(v), valorImpressao: (l) => formatarKz(l.orcado) },
    {
      title: 'Realizado', dataIndex: 'realizado', align: 'right', onCell: soNaRubrica,
      // como no legado, o realizado de uma rubrica abre a análise (movimentos e causas)
      render: (v, l) => (sintetica(l) ? <span style={{ color: l.tipo_linha === 'resultado' && Number(v) < 0 ? token.colorError : undefined }}>{formatarKz(v)}</span>
        : <Button type="link" size="small" style={{ padding: 0, height: 'auto' }} onClick={(e) => { e.stopPropagation(); setAnalise(l); }}>{formatarKz(v)}</Button>), valorImpressao: (l) => formatarKz(l.realizado) },
    { title: 'Desvio', dataIndex: 'desvio', align: 'right', onCell: soNaRubrica, render: (v) => formatarKz(v), valorImpressao: (l) => formatarKz(l.desvio) },
    { title: 'Desvio %', dataIndex: 'desvio_pct', align: 'right', onCell: soNaRubrica, render: (v: number | null) => pct(v, true), valorImpressao: (l) => pct(l.desvio_pct, true) },
    { title: 'Execução', dataIndex: 'execucao_pct', align: 'right', onCell: soNaRubrica, render: (v: number | null, l) => (l.tipo_linha === 'resultado' ? '' : pct(v)), valorImpressao: (l) => pct(l.execucao_pct) },
    { title: '', key: 'barra', filtravel: false, ocultavel: false, onCell: soNaRubrica, render: (_, l) => (sintetica(l) ? null : <BarraExecucao l={l} />), valorImpressao: () => '' },
    {
      title: 'Leitura', key: 'leitura', valorFiltro: (l) => TEXTO_LEITURA[leitura(l)], valorImpressao: (l) => (l.tipo_linha ? '' : TEXTO_LEITURA[leitura(l)]), onCell: soNaRubrica,
      render: (_, l) => (sintetica(l) ? null : 
        <span style={{ whiteSpace: 'nowrap', fontWeight: 700, color: COR_LEITURA[leitura(l)] }}>
          {TEXTO_LEITURA[leitura(l)]}{' '}
          <Button size="small" aria-label="Analisar desvio" title="Analisar as causas do desvio" icon={<IconeFa nome="search-plus" />} onClick={(e) => { e.stopPropagation(); setAnalise(l); }} />
        </span>
      ),
    },
  ];

  return (
    <>
      <CabecalhoPagina titulo="Controlo Orçamental" subtitulo="Orçado vs realizado (Diário), desvios e grau de execução por rubrica." />
      {!lista.isLoading && !lista.data?.orcamentos.length ? (
        <Card><Empty description="Ainda não há orçamentos. Crie-os em Gestão Orçamental › Orçamentos." /></Card>
      ) : (
        <div style={{ display: 'flex', flexWrap: 'wrap', gap: '0.6rem', alignItems: 'flex-end', background: token.colorFillAlter, border: `1px solid ${token.colorBorderSecondary}`, borderRadius: 4, padding: '0.6rem', marginBottom: 12 }}>
          <label style={{ minWidth: 280, flex: 1 }}>
            <span style={{ fontSize: 12, color: token.colorTextSecondary, fontWeight: 600, display: 'block', marginBottom: 2 }}>Orçamento</span>
            <SeletorOrcamentoLegado value={orcamento} onChange={setOrcamento} style={{ width: '100%' }} aria-label="Orçamento" />
          </label>
          <label>
            <span style={{ fontSize: 12, color: token.colorTextSecondary, fontWeight: 600, display: 'block', marginBottom: 2 }}>Vista</span>
            <Select<VistaControlo> value={vista} onChange={setVista} style={{ width: 200 }} aria-label="Vista"
              options={[{ value: 'MES', label: 'Só o mês' }, { value: 'ACUMULADO', label: 'Acumulado até ao mês' }, { value: 'ANO', label: 'Ano completo' }]} />
          </label>
          <label>
            <span style={{ fontSize: 12, color: token.colorTextSecondary, fontWeight: 600, display: 'block', marginBottom: 2 }}>Mês</span>
            <Select<number> value={mes} onChange={setMes} disabled={vista === 'ANO'} style={{ width: 100 }} aria-label="Mês" options={MESES.map((m, i) => ({ value: i + 1, label: m }))} />
          </label>
        </div>
      )}
      {q.isLoading && <div style={{ padding: '2rem', textAlign: 'center', color: token.colorTextSecondary }}><Spin /> A calcular o realizado...</div>}
      {q.error && <Alert type="error" showIcon message={`Não foi possível calcular o controlo: ${(q.error as Error).message}`} />}
      {c && totais && res && mensal && (
        <>
          <div style={{ marginBottom: 12 }}>
          <GrelhaKpi larguraMinima={200}>
            {totais.map((t) => (
              <Fragment key={t.natureza}>
                <KpiOrc rotulo={`${NOME_NATUREZA[t.natureza]} · ${periodo}`} valor={kz(t.realizado)} rodape={`orçado ${kz(t.orcado)} · ${pct(t.execucao)}`} />
              </Fragment>
            ))}
            <>
              <KpiOrc rotulo={`${nomeResultado} · ${periodo}`} valor={kz(res.realizado)} negativo={res.realizado < 0} rodape={`orçado ${kz(res.orcado)} · desvio ${kz(res.desvio)}`} />
            </>
            {tes && c.saldos_fim_mes && (
              <>
                <KpiOrc rotulo={`Saldo de disponibilidades · fim ${MESES[mesesDaVista(vista, mes)[1] - 1]}`} valor={kz(c.saldos_fim_mes[mesesDaVista(vista, mes)[1] - 1])}
                  negativo={Number(c.saldos_fim_mes[mesesDaVista(vista, mes)[1] - 1]) < 0} rodape={c.saldo_inicial !== undefined ? `saldo inicial ${kz(c.saldo_inicial)}` : undefined} />
              </>
            )}
          </GrelhaKpi>
          </div>
          {c.orcamento.estado !== 'APROVADO' && (
            <div style={ESTILO_AVISO} role="note"><IconeFa nome="exclamation-triangle" /> Este orçamento está «{({ RASCUNHO: 'Rascunho', SUBMETIDO: 'Submetido', SUBSTITUIDO: 'Substituído' } as Record<string, string>)[c.orcamento.estado] ?? c.orcamento.estado}». O controlo formal deve usar a versão aprovada.</div>
          )}
          {corrigido && <div style={ESTILO_INFO} role="note"><IconeFa nome="code-branch" /> Orçamento corrigido (v{c.orcamento.versao}); a coluna «Inicial» mostra a versão inicial aprovada.</div>}
          {piores.length > 0 && (
            <Card size="small" style={{ marginBottom: 12 }} styles={{ body: { padding: '0.6rem 0.75rem' } }}>
              <h4 style={{ margin: '0 0 0.4rem', fontSize: 14.4 }}><IconeFa nome="exclamation-circle" style={{ color: token.colorError }} /> Principais desvios desfavoráveis · {periodo}</h4>
              <div style={{ display: 'grid', gap: '0.3rem' }}>
                {piores.map((l) => (
                  <div key={l.rubrica_id} style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: '0.5rem', flexWrap: 'wrap', fontSize: 13.4 }}>
                    <span><b>{l.codigo}</b> {l.nome} — {sentidoDesvio(l.natureza)} do orçado em <b style={{ color: token.colorError }}>{kz(Math.abs(Number(l.desvio)))}</b>{l.desvio_pct === null ? '' : ` (${pct(Math.abs(l.desvio_pct))})`}</span>
                    <Button size="small" icon={<IconeFa nome="search-plus" />} onClick={() => setAnalise(l)}>Porquê?</Button>
                  </div>
                ))}
              </div>
            </Card>
          )}
          {c.sem_rubrica.length > 0 && (
            <div style={ESTILO_AVISO} role="note">
              <IconeFa nome="search-dollar" /> Movimentos sem rubrica (não controlados):{' '}
              {c.sem_rubrica.slice(0, 10).map((s) => <span key={s.conta} style={{ display: 'inline-block', fontSize: 11.5, background: '#e0f2fe', color: '#075985', borderRadius: 4, padding: '0 5px', margin: '1px 2px', fontFamily: 'ui-monospace, Consolas, monospace' }}>{s.conta}: {kz(somaValores(s.valores.slice(mesesDaVista(vista, mes)[0] - 1, mesesDaVista(vista, mes)[1])))}</span>)}
              {c.sem_rubrica.length > 10 ? '…' : ''} — associe estas contas a uma rubrica.
            </div>
          )}
          <Card size="small" style={{ marginBottom: 12 }}>
            <TabelaComModos<LinhaTabelaControlo>
              rowKey="rubrica_id"
              pagination={false}
              rowClassName={(l) => (l.tipo_linha ? `erp-controlo-${l.tipo_linha}` : '')}
              onRow={(l) => ({ style: l.tipo_linha === 'natureza' ? { background: token.colorFillAlter, fontWeight: 700 }
                : l.tipo_linha ? { background: token.colorFillSecondary, fontWeight: 800, borderTop: l.tipo_linha === 'resultado' ? `2px solid ${token.colorText}` : undefined } : undefined })}
              size="small"
              loading={q.isFetching}
              dataSource={linhas}
              scroll={scrollTabela()}
              columns={colunas}
              impressao={{
                titulo: `Controlo Orçamental — ${tes ? 'Tesouraria' : 'Exploração'}`,
                subtitulo: lista.data?.orcamentos.find((o) => o.id === c.orcamento.id)?.rotulo ?? c.orcamento.nome ?? undefined,
                periodo,
                filtros: [
                  `${NOME_NATUREZA[natA]}: ${kz(totais[0].realizado)} (orçado ${kz(totais[0].orcado)})`,
                  `${NOME_NATUREZA[natB]}: ${kz(totais[1].realizado)} (orçado ${kz(totais[1].orcado)})`,
                  `${nomeResultado}: ${kz(res.realizado)} (orçado ${kz(res.orcado)})`,
                ],
                nomeFicheiro: `Controlo_Orcamental_${c.orcamento.tipo}_${c.orcamento.ano}_v${c.orcamento.versao}`,
              }}
              vazio={{ icone: 'balance-scale', titulo: 'Sem rubricas', texto: 'Este orçamento não tem rubricas com valores nem realizado.' }}
            />
          </Card>
          <Card size="small" title={`${nomeResultado} mensal — orçado vs realizado ${c.orcamento.ano}`}>
            <GraficoBarras rotulos={MESES} monetario altura={260}
              series={[{ rotulo: 'Orçado', valores: mensal.orcado, cor: '#cbd5e1' }, { rotulo: 'Realizado', valores: mensal.realizado, cor: '#1d4ed8' }]} />
          </Card>
        </>
      )}
      <ModalAnaliseDesvio orcamentoId={orcamento} linha={analise} meses={mesesDaVista(vista, mes)} ano={c?.orcamento.ano} aoFechar={() => setAnalise(null)} />
    </>
  );
}

/** Análise do desvio («Porquê?», orcamento_planeamento_ui.js:228-257): mês a mês, tipo de desvio, contas, maiores movimentos e terceiros. */
function ModalAnaliseDesvio({ orcamentoId, linha, meses, ano, aoFechar }: { orcamentoId?: number; linha: LinhaControlo | null; meses: [number, number]; ano?: number; aoFechar: () => void }) {
  const q = useQuery({
    queryKey: ['orcamento', 'paridade', 'desvio', orcamentoId, linha?.rubrica_id, meses[0], meses[1]],
    queryFn: () => obter<CausasDesvio>(`/orcamento/paridade/orcamentos/${orcamentoId}/desvios/${linha?.rubrica_id}`, { de: meses[0], ate: meses[1] }),
    enabled: !!linha && !!orcamentoId,
    retry: false,
  });
  const d = q.data;
  const { token } = theme.useToken();
  const nat = linha?.natureza ?? 'CUSTO';
  const entrada = nat === 'PROVEITO' || nat === 'RECEBIMENTO';
  const periodo = ano ? (meses[0] === meses[1] ? `${MESES[meses[0] - 1]} ${ano}` : `${MESES[meses[0] - 1]}–${MESES[meses[1] - 1]} ${ano}`) : '';
  const cor = (v: string | number) => ({ color: corDesvio(nat, v) });
  const seccao = (titulo: string, corpo: ReactNode) => (
    <div style={{ padding: '0.5rem 0.6rem' }}>
      <h4 style={{ margin: '0 0 0.4rem', fontSize: 13.6 }}>{titulo}</h4>
      {corpo}
    </div>
  );
  const semMov = <Typography.Text type="secondary">Sem movimentos.</Typography.Text>;

  return (
    <Modal open={!!linha} onCancel={aoFechar} footer={null} width={larguraModal(980)} destroyOnHidden
      title={<><IconeFa nome="search-plus" /> {linha ? `Análise do desvio — ${linha.codigo} ${linha.nome} · ${periodo}` : 'Análise do desvio'}</>}>
      {q.isLoading && <div style={{ padding: '2rem', textAlign: 'center', color: token.colorTextSecondary }}><Spin /> A analisar movimentos...</div>}
      {q.error && <Alert type="error" showIcon message={`Não foi possível analisar: ${(q.error as Error).message}`} />}
      {d && (
        <div style={{ maxHeight: '70vh', overflow: 'auto' }}>
          <div style={{ marginBottom: 10 }}>
          <GrelhaKpi larguraMinima={200}>
            <><KpiOrc rotulo="Desvio no período" valor={<span style={cor(d.desvio_total)}>{kz(d.desvio_total)}</span>} rodape={entrada ? 'real acima do orçado é favorável' : 'real acima do orçado é desfavorável'} /></>
            <><KpiOrc rotulo="Meses desfavoráveis" valor={`${d.desfavoraveis} de ${d.mensal.length}`} /></>
            {d.fecho_estimado && (
              <>
                <KpiOrc rotulo={`Fecho estimado ${d.periodo.ano} (previsão rev. ${d.fecho_estimado.revisao})`} valor={kz(d.fecho_estimado.valor)}
                  rodape={<>orçado ano {kz(d.fecho_estimado.orcado_ano)} · desvio esperado <b style={cor(Number(d.fecho_estimado.valor) - Number(d.fecho_estimado.orcado_ano))}>{kz(Number(d.fecho_estimado.valor) - Number(d.fecho_estimado.orcado_ano))}</b></>} />
              </>
            )}
          </GrelhaKpi>
          </div>
          <div style={d.classificacao === 'SEM_DESVIO' ? ESTILO_INFO : estiloClasse(d.classificacao)}><b>Tipo de desvio:</b> {TEXTO_CLASSIFICACAO[d.classificacao] ?? d.classificacao}</div>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(min(100%, 420px), 1fr))', gap: 10 }}>
            {seccao('Mês a mês', (
              <Table size="small" rowKey="mes" pagination={false} scroll={scrollTabela()} dataSource={d.mensal} columns={[
                { title: 'Mês', dataIndex: 'mes', render: (m: number) => MESES[m - 1] },
                { title: 'Orçado', dataIndex: 'orcado', align: 'right', render: (v) => formatarKz(v) },
                { title: 'Real', dataIndex: 'real', align: 'right', render: (v) => formatarKz(v) },
                { title: 'Desvio', dataIndex: 'desvio', align: 'right', render: (v) => <span style={cor(v)}>{formatarKz(v)}</span> },
                { title: 'Acumulado', dataIndex: 'acumulado', align: 'right', render: (v) => <span style={cor(v)}>{formatarKz(v)}</span> },
              ]} />
            ))}
            {seccao(`Contas que explicam a variação (vs mesmo período de ${d.periodo.ano - 1})`, d.contas.length ? (
              <Table size="small" rowKey={(x) => String(x.conta)} pagination={false} scroll={scrollTabela()} dataSource={d.contas.slice(0, 12)} columns={[
                { title: 'Conta', dataIndex: 'conta', render: (v, x) => <><b>{v}</b> <span style={{ color: token.colorTextSecondary, fontSize: 12 }}>{x.descricao}</span></> },
                { title: String(d.periodo.ano), dataIndex: 'real', align: 'right', render: (v) => formatarKz(v) },
                { title: String(d.periodo.ano - 1), dataIndex: 'anterior', align: 'right', render: (v) => formatarKz(v) },
                { title: 'Variação', dataIndex: 'variacao', align: 'right', render: (v) => <span style={cor(v)}>{formatarKz(v)}</span> },
              ]} />
            ) : semMov)}
            {seccao('Maiores movimentos', d.maiores_movimentos.length ? (
              <Table size="small" rowKey={(_, i) => String(i)} pagination={false} scroll={scrollTabela()} dataSource={d.maiores_movimentos} columns={[
                { title: 'Data', dataIndex: 'data_documento', render: formatarData },
                { title: 'Documento', dataIndex: 'numero_documento', render: (v) => v ?? '—' },
                { title: 'Descrição', dataIndex: 'descricao', ellipsis: true },
                { title: 'Valor', key: 'v', align: 'right', render: (_, m) => formatarKz(m.valor_rubrica ?? m.valor) },
              ]} />
            ) : semMov)}
            {seccao('Por terceiro (clientes / fornecedores)', d.terceiros.length ? (
              <Table size="small" rowKey={(x) => String(x.terceiro_id ?? 'sem')} pagination={false} scroll={scrollTabela()} dataSource={d.terceiros} columns={[
                { title: 'Terceiro', dataIndex: 'nome' },
                { title: 'Valor', dataIndex: 'valor', align: 'right', render: (v) => formatarKz(v) },
                { title: 'Peso', dataIndex: 'peso_pct', align: 'right', render: (v: number) => pct(v) },
              ]} />
            ) : semMov)}
          </div>
        </div>
      )}
    </Modal>
  );
}
