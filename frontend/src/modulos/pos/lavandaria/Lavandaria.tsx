import { Alert, Button, DatePicker, Select, Skeleton } from 'antd';
import { useQuery } from '@tanstack/react-query';
import dayjs, { type Dayjs } from 'dayjs';
import { useEffect, useState, type CSSProperties, type ReactNode } from 'react';
import { obter } from '@/api/cliente';
import '@/componentes/CabecalhoModulo.css';
import { IconeLegado } from '@/componentes/vistas';
import { BotoesExportar, pares, tabelaHtml } from '@/componentes/impressao';
import { useSessao } from '@/sessao/SessaoContexto';
import { notificarErro } from '@/utilitarios/erros';
import { dataApi, formatarData, formatarKz, formatarNumero } from '@/utilitarios/formatacao';
import { rotuloEstadoPOS } from '../comum/estados';
import { useCoresPos } from '../comum/cores';
import { SeletorTerminal, useFiltroTerminal } from '../comum/Filtros';
import { BarraSessao, useTerminalDeTrabalho } from '../comum/SessaoTerminal';
import { Ordens } from './Ordens';
import { Recepcao } from './Recepcao';
import { Reclamacoes } from './Reclamacoes';
import { Definicoes, TabelaPecas, TabelaServicos } from './Tabelas';
import type { RelatorioLav } from './tipos';
import { GRUPOS_LAV, UNIDADES_LAV } from './dados';
import { useTerminais } from '../comum/dados';

/** Separadores do antigo, pela mesma ordem e com os mesmos ícones (renderBackoffice, lavandaria.js:1276); abre em «Peças». */
export const SEPARADORES_LAV: { chave: string; icone: string; rotulo: string }[] = [
  { chave: 'pecas', icone: 'tshirt', rotulo: 'Peças' },
  { chave: 'servicos', icone: 'list', rotulo: 'Serviços' },
  { chave: 'ordens', icone: 'clipboard-list', rotulo: 'Ordens de serviço' },
  { chave: 'relatorios', icone: 'chart-bar', rotulo: 'Relatórios' },
  { chave: 'danos', icone: 'exclamation-triangle', rotulo: 'Danos e reclamações' },
  { chave: 'definicoes', icone: 'sliders-h', rotulo: 'Definições' },
];

/**
 * POS › Lavandaria (ecrã pos_lavandaria) como no sistema anterior: separadores-botão Peças, Serviços, Ordens de serviço,
 * Relatórios, Danos e reclamações e Definições (abre em «Peças»). A recepção (nova ordem de serviço) abre-se em «Ordens de
 * serviço» → «Nova ordem de serviço». O terminal de trabalho e a sessão ficam no topo (ADR-049).
 */
export default function Lavandaria() {
  const { pode } = useSessao();
  const trabalho = useTerminalDeTrabalho('LAVANDARIA');
  const [separador, setSeparador] = useState('pecas');
  const [recepcao, setRecepcao] = useState(false);
  const mudar = (chave: string) => {
    setSeparador(chave);
    setRecepcao(false);
  };

  return (
    <>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: '0.6rem', flexWrap: 'wrap', marginBottom: '0.8rem' }}>
        <nav className="erp-cab-modulo-separadores" aria-label="Separadores da lavandaria">
          {SEPARADORES_LAV.map((s) => (
            <button key={s.chave} type="button" className={separador === s.chave ? 'erp-separador-botao activo' : 'erp-separador-botao'} aria-pressed={separador === s.chave} onClick={() => mudar(s.chave)}>
              <span className="erp-separador-icone">
                <IconeLegado icone={s.icone} />
              </span>
              {s.rotulo}
            </button>
          ))}
        </nav>
        <BarraSessao tipo="LAVANDARIA" {...trabalho} />
      </div>
      {trabalho.carregando ? (
        <Skeleton active />
      ) : separador === 'pecas' ? (
        <TabelaPecas />
      ) : separador === 'servicos' ? (
        <TabelaServicos />
      ) : separador === 'ordens' ? (
        recepcao && pode('lav_ordens') ? (
          <>
            <Button icon={<IconeLegado icone="arrow-left" />} style={{ marginBottom: 12 }} onClick={() => setRecepcao(false)}>
              Ordens de serviço
            </Button>
            <Recepcao terminal={trabalho.terminal} />
          </>
        ) : (
          <Ordens terminal={trabalho.terminal} aoNovaOrdem={() => setRecepcao(true)} />
        )
      ) : separador === 'relatorios' ? (
        pode('pos_lavandaria_view', 'pos_relatorios_view', 'lav_ordens') ? <Relatorio /> : <Alert type="info" showIcon message="Sem permissão para os relatórios da lavandaria." />
      ) : separador === 'danos' ? (
        <Reclamacoes />
      ) : (
        <Definicoes />
      )}
    </>
  );
}

type ModoPeriodo = 'DIA' | 'MES' | 'PERIODO';

/** Tipo do documento com o rótulo do antigo (doc_type / recibos, lavandaria.js:1947-1948). */
const TIPOS_DOC_LAV: Record<string, string> = { FT: 'Factura', FR: 'Factura-Recibo', RECIBO: 'Recibo', RECIBO_ADIANTAMENTO: 'Recibo (adiantamento)' };
const rotuloTipoDoc = (t: string) => TIPOS_DOC_LAV[t] ?? t;
const kzM = (v: number | string | null | undefined) => `${formatarKz(v ?? 0)} Kz`;

/** Período do relatório (periodoRelatorio, lavandaria.js:1885-1894): dia, mês ou intervalo. */
export function periodoRelatorioLav(modo: ModoPeriodo, dia: Dayjs, mes: Dayjs, de: Dayjs, ate: Dayjs): { de: Dayjs; ate: Dayjs; rotulo: string } {
  if (modo === 'DIA') return { de: dia, ate: dia, rotulo: `Dia ${dia.format('DD/MM/YYYY')}` };
  if (modo === 'MES') return { de: mes.startOf('month'), ate: mes.endOf('month'), rotulo: `Mês ${mes.format('MM/YYYY')}` };
  const [a, b] = de.isAfter(ate) ? [ate, de] : [de, ate];
  return { de: a, ate: b, rotulo: `De ${a.format('DD/MM/YYYY')} a ${b.format('DD/MM/YYYY')}` };
}

function KpiRel({ rotulo, valor, sub, cor }: { rotulo: string; valor: ReactNode; sub?: ReactNode; cor: string }) {
  const c = useCoresPos();
  return (
    <div style={{ background: c.fundo, border: `1px solid ${c.borda}`, borderLeft: `3px solid ${cor}`, borderRadius: 4, padding: '0.55rem 0.8rem' }}>
      <div style={{ fontSize: '0.68rem', fontWeight: 700, color: '#64748b', textTransform: 'uppercase' }}>{rotulo}</div>
      <div style={{ fontSize: '1.15rem', fontWeight: 800 }}>{valor}</div>
      {sub ? <div style={{ fontSize: '0.72rem', color: '#64748b' }}>{sub}</div> : null}
    </div>
  );
}

function SeccaoRel({ titulo, icone, children }: { titulo: string; icone: string; children: ReactNode }) {
  const c = useCoresPos();
  return (
    <div style={{ background: c.fundo, border: `1px solid ${c.borda}`, borderRadius: 4, padding: '0.9rem', margin: '0.7rem 0 0' }}>
      <h4 style={{ margin: '0 0 0.5rem', fontSize: '0.92rem' }}>
        <IconeLegado icone={icone} /> {titulo}
      </h4>
      {children}
    </div>
  );
}

/** Tabela simples do antigo (tabelaRel, lavandaria.js:1988-1994): 0.8rem, «Sem dados no período.» e linha de totais. */
function TabelaRel({ colunas, linhas, rodape }: { colunas: { rotulo: string; dir?: boolean }[]; linhas: ReactNode[][]; rodape?: ReactNode[] }) {
  const alinhar = (k: number): CSSProperties => (colunas[k]?.dir ? { textAlign: 'right', whiteSpace: 'nowrap' } : {});
  const cores = useCoresPos();
  return (
    <div style={{ overflowX: 'auto' }}>
      <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: '0.8rem' }}>
        <thead>
          <tr style={{ background: cores.fundoAlt, textAlign: 'left' }}>
            {colunas.map((c, k) => (
              <th key={k} style={{ padding: 6, textTransform: 'uppercase', fontSize: '0.72rem', fontWeight: 700, color: cores.texto, letterSpacing: '0.03em', ...(c.dir ? { textAlign: 'right' } : {}) }}>
                {c.rotulo}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {linhas.length ? (
            linhas.map((l, n) => (
              <tr key={n} style={{ borderTop: `1px solid ${cores.fundoSuave}` }}>
                {l.map((v, k) => (
                  <td key={k} style={{ padding: '5px 6px', ...alinhar(k) }}>
                    {v}
                  </td>
                ))}
              </tr>
            ))
          ) : (
            <tr>
              <td colSpan={colunas.length} style={{ padding: '0.8rem', textAlign: 'center', color: '#94a3b8' }}>
                Sem dados no período.
              </td>
            </tr>
          )}
        </tbody>
        {rodape && linhas.length > 0 && (
          <tfoot>
            <tr style={{ borderTop: `2px solid ${cores.texto}`, fontWeight: 800 }}>
              {rodape.map((v, k) => (
                <td key={k} style={{ padding: 6, ...alinhar(k) }}>
                  {v}
                </td>
              ))}
            </tr>
          </tfoot>
        )}
      </table>
    </div>
  );
}

const COR_ESTADO_DOC: Record<string, string> = { ANULADO: '#b91c1c', PAGO: '#059669', PENDENTE: '#b45309' };

function Relatorio() {
  const hoje = dayjs();
  const [modo, setModo] = useState<ModoPeriodo>('DIA');
  const [dia, setDia] = useState<Dayjs>(hoje);
  const [mes, setMes] = useState<Dayjs>(hoje);
  const [de, setDe] = useState<Dayjs>(hoje.startOf('month'));
  const [ate, setAte] = useState<Dayjs>(hoje);
  const [terminal, setTerminal] = useState<number>();
  const periodo = periodoRelatorioLav(modo, dia, mes, de, ate);
  const filtros = { de: dataApi(periodo.de), ate: dataApi(periodo.ate), terminal_pos_id: terminal };
  const consulta = useQuery({ queryKey: ['pos', 'lavandaria', 'relatorio', filtros], queryFn: () => obter<RelatorioLav>('/pos/lavandaria/relatorio', filtros) });
  useEffect(() => {
    if (consulta.error) notificarErro(consulta.error, 'Erro ao carregar o relatório');
  }, [consulta.error]);
  const r = consulta.data;
  const cores = useCoresPos();
  const filtroTerminal = useFiltroTerminal(terminal);
  const terminais = useTerminais();
  const escolhido = terminal ? terminais.data?.find((t) => t.id === terminal) : undefined;
  const rotuloTerminal = escolhido ? `${escolhido.codigo} · ${escolhido.nome}` : 'Todos os terminais';
  const s = (r?.resumo ?? {}) as Record<string, number | string | null>;
  const n = (k: string) => Number(s[k] ?? 0);
  const validos = (r?.documentos ?? []).filter((d) => d.estado !== 'ANULADO');
  const porTipo = validos.reduce<Record<string, { n: number; total: number }>>((acc, d) => {
    const g = (acc[rotuloTipoDoc(d.tipo)] ??= { n: 0, total: 0 });
    g.n += 1;
    g.total += Number(d.total ?? 0);
    return acc;
  }, {});
  const campo = (rotulo: string, filho: ReactNode) => (
    <label style={{ display: 'flex', flexDirection: 'column', gap: 2, margin: 0 }}>
      <span style={{ fontSize: '0.78rem', fontWeight: 700 }}>{rotulo}</span>
      {filho}
    </label>
  );

  return (
    <>
      {/* barra do antigo (lavandaria.js:2009-2018): Período (Dia/Mês/Intervalo), data(s), Terminal e exportação à direita */}
      <div style={{ background: cores.fundo, border: `1px solid ${cores.borda}`, borderRadius: 4, padding: '0.8rem', display: 'flex', gap: '0.5rem', flexWrap: 'wrap', alignItems: 'flex-end' }}>
        {campo(
          'Período',
          <Select<ModoPeriodo>
            value={modo}
            onChange={setModo}
            style={{ width: 130 }}
            aria-label="Período"
            options={[
              { value: 'DIA', label: 'Dia' },
              { value: 'MES', label: 'Mês' },
              { value: 'PERIODO', label: 'Intervalo' },
            ]}
          />,
        )}
        {modo === 'DIA' && campo('Dia', <DatePicker format="DD/MM/YYYY" allowClear={false} value={dia} onChange={(v) => v && setDia(v)} aria-label="Dia" />)}
        {modo === 'MES' && campo('Mês', <DatePicker picker="month" format="MM/YYYY" allowClear={false} value={mes} onChange={(v) => v && setMes(v)} aria-label="Mês" />)}
        {modo === 'PERIODO' && (
          <>
            {campo('De', <DatePicker format="DD/MM/YYYY" allowClear={false} value={de} onChange={(v) => v && setDe(v)} aria-label="De" />)}
            {campo('Até', <DatePicker format="DD/MM/YYYY" allowClear={false} value={ate} onChange={(v) => v && setAte(v)} aria-label="Até" />)}
          </>
        )}
        {campo('Terminal', <SeletorTerminal tipo="LAVANDARIA" allowClear placeholder="Todos os terminais" style={{ width: 220 }} value={terminal} onChange={setTerminal} />)}
        <div style={{ flex: 1 }} />
        <BotoesExportar
          tamanho="small"
          desactivado={!r}
          obterPedido={() =>
            r && {
              titulo: 'Relatório da lavandaria',
              periodo: periodo.rotulo,
              filtros: [filtroTerminal],
              conteudo: documentoRelatorioLav(r),
            }
          }
        />
      </div>
      {!r ? (
        <Skeleton active style={{ marginTop: 12 }} />
      ) : (
        <div>
          <div style={{ margin: '0.7rem 0 0.5rem', fontSize: '0.9rem' }}>
            <strong>{periodo.rotulo}</strong> · {rotuloTerminal}
          </div>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(165px, 1fr))', gap: '0.5rem' }}>
            <KpiRel rotulo="Ordens recebidas" valor={n('recebidas')} sub={`${n('etiquetas')} peça(s) · ${kzM(s.valor_recebidas)}`} cor="#2563eb" />
            <KpiRel rotulo="Ordens entregues" valor={n('entregues')} sub={`${n('pecas_entregues')} peça(s) entregue(s)`} cor="#475569" />
            <KpiRel rotulo="Total facturado" valor={kzM(s.facturado)} sub={`base ${kzM(s.base)} · IVA ${kzM(s.iva)}`} cor="#0f172a" />
            <KpiRel rotulo="Facturas (conta corrente)" valor={n('faturas_numero')} sub={kzM(s.faturas_valor)} cor="#7c3aed" />
            <KpiRel rotulo="Facturas-Recibo" valor={n('faturas_recibo_numero')} sub={kzM(s.faturas_recibo_valor)} cor="#0891b2" />
            <KpiRel rotulo="Recibos" valor={n('recibos_numero')} sub={kzM(s.recibos_valor)} cor="#059669" />
            <KpiRel rotulo="Total recebido" valor={kzM(s.recebido)} sub={n('anulados') ? `${n('anulados')} documento(s) anulado(s)` : undefined} cor="#059669" />
            <KpiRel
              rotulo="Concluídas no prazo"
              valor={s.percentagem_no_prazo === null || s.percentagem_no_prazo === undefined ? '—' : `${s.percentagem_no_prazo}%`}
              sub={`${n('concluidas')} concluída(s) no período`}
              cor="#b45309"
            />
            <KpiRel rotulo="Danos registados" valor={n('danos')} cor="#b91c1c" />
            <KpiRel rotulo="Saldo em aberto (actual)" valor={kzM(s.saldo_aberto)} sub={`por facturar ${kzM(s.por_facturar)}`} cor="#b91c1c" />
          </div>
          <SeccaoRel titulo="Documentos emitidos" icone="file-invoice">
            {Object.keys(porTipo).length > 0 && (
              <div style={{ display: 'flex', gap: '0.5rem', flexWrap: 'wrap', marginBottom: '0.5rem', fontSize: '0.8rem' }}>
                {Object.entries(porTipo).map(([t, g]) => (
                  <span key={t} style={{ background: cores.fundoSuave, borderRadius: 4, padding: '2px 8px' }}>
                    <strong>{t}</strong>: {g.n} · {kzM(g.total)}
                  </span>
                ))}
              </div>
            )}
            <TabelaRel
              colunas={[{ rotulo: 'Data' }, { rotulo: 'Documento' }, { rotulo: 'Tipo' }, { rotulo: 'Ordem' }, { rotulo: 'Cliente' }, { rotulo: 'Terminal' }, { rotulo: 'Base', dir: true }, { rotulo: 'IVA', dir: true }, { rotulo: 'Total', dir: true }, { rotulo: 'Estado' }]}
              linhas={r.documentos.map((d) => [
                formatarData(d.data),
                <strong key="n">{d.numero}</strong>,
                rotuloTipoDoc(d.tipo),
                d.ordem ?? '',
                d.cliente ?? '',
                d.terminal ?? '',
                d.base === null || d.base === undefined ? '—' : kzM(d.base),
                d.iva === null || d.iva === undefined ? '—' : kzM(d.iva),
                kzM(d.total),
                <span key="e" style={{ fontSize: '0.72rem', fontWeight: 700, color: COR_ESTADO_DOC[d.estado ?? ''] ?? '#475569' }}>
                  {d.estado ?? ''}
                </span>,
              ])}
              rodape={['', `${validos.length} documento(s) válido(s)`, '', '', '', '', kzM(s.base), kzM(s.iva), '', '']}
            />
          </SeccaoRel>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(360px, 1fr))', gap: '0 0.7rem' }}>
            <SeccaoRel titulo="Recebimentos por meio de pagamento" icone="wallet">
              <TabelaRel
                colunas={[{ rotulo: 'Meio de pagamento' }, { rotulo: 'N.º', dir: true }, { rotulo: 'Valor', dir: true }]}
                linhas={[...r.por_meio].sort((a, b) => Number(b.total) - Number(a.total)).map((m) => [m.meio, m.numero, kzM(m.total)])}
                rodape={['Total', '', kzM(s.recebido)]}
              />
            </SeccaoRel>
            <SeccaoRel titulo="Por terminal" icone="cash-register">
              <TabelaRel
                colunas={[{ rotulo: 'Terminal' }, { rotulo: 'OS recebidas', dir: true }, { rotulo: 'Documentos', dir: true }, { rotulo: 'Facturado', dir: true }, { rotulo: 'Recebido', dir: true }]}
                linhas={r.por_terminal.map((t) => [t.terminal, t.ordens, t.documentos, kzM(t.facturado), kzM(t.recebido)])}
                rodape={['Total', n('recebidas'), validos.length, kzM(s.facturado), kzM(s.recebido)]}
              />
            </SeccaoRel>
          </div>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(360px, 1fr))', gap: '0 0.7rem' }}>
            <SeccaoRel titulo="Facturado por serviço" icone="list">
              <TabelaRel
                colunas={[{ rotulo: 'Serviço' }, { rotulo: 'Grupo' }, { rotulo: 'Quantidade', dir: true }, { rotulo: 'Total (IVA incl.)', dir: true }]}
                linhas={r.por_servico.map((x) => [x.servico, x.grupo ? (GRUPOS_LAV[x.grupo] ?? x.grupo) : 'Taxas e extras', formatarNumero(x.quantidade), kzM(x.total)])}
                rodape={['Total', '', '', kzM(s.facturado)]}
              />
            </SeccaoRel>
            <SeccaoRel titulo="Peças recebidas" icone="tshirt">
              <TabelaRel
                colunas={[{ rotulo: 'Peça' }, { rotulo: 'Quantidade', dir: true }, { rotulo: 'Etiquetas', dir: true }, { rotulo: 'Valor dos serviços', dir: true }]}
                linhas={r.por_peca.map((p) => [p.peca, `${formatarNumero(p.quantidade)} ${(UNIDADES_LAV[p.unidade] ?? '').toLowerCase()}`, p.etiquetas, kzM(p.valor)])}
                rodape={['Total', '', n('etiquetas'), kzM(r.por_peca.reduce((t, p) => t + Number(p.valor), 0))]}
              />
            </SeccaoRel>
          </div>
          {r.por_dia.length > 1 && (
            <SeccaoRel titulo="Resumo diário" icone="calendar-alt">
              <TabelaRel
                colunas={[{ rotulo: 'Dia' }, { rotulo: 'OS recebidas', dir: true }, { rotulo: 'Peças recebidas', dir: true }, { rotulo: 'Peças entregues', dir: true }, { rotulo: 'Documentos', dir: true }, { rotulo: 'Facturado', dir: true }, { rotulo: 'Recebido', dir: true }]}
                linhas={r.por_dia.map((d) => [formatarData(d.dia), d.recebidas, d.etiquetas, d.entregues, d.documentos, kzM(d.facturado), kzM(d.recebido)])}
                rodape={['Total', n('recebidas'), n('etiquetas'), n('pecas_entregues'), validos.length, kzM(s.facturado), kzM(s.recebido)]}
              />
            </SeccaoRel>
          )}
        </div>
      )}
    </>
  );
}

/** Relatório da lavandaria (período) para impressão: resumo, documentos, serviços, peças, meios, terminais e dias. */
export function documentoRelatorioLav(r: RelatorioLav): string {
  const n = (k: string) => r.resumo[k] ?? 0;
  const kz = (k: string) => `${formatarKz(n(k))} Kz`;
  const resumo = pares(
    [
      ['Ordens recebidas', `${n('recebidas')} (${n('etiquetas')} peça(s) · ${kz('valor_recebidas')})`],
      ['Ordens entregues', `${n('entregues')} (${n('pecas_entregues')} peça(s))`],
      ['Total facturado', `${kz('facturado')} (base ${kz('base')} · IVA ${kz('iva')})`],
      ['Facturas (conta corrente)', `${n('faturas_numero')} · ${kz('faturas_valor')}`],
      ['Facturas-Recibo', `${n('faturas_recibo_numero')} · ${kz('faturas_recibo_valor')}`],
      ['Recibos', `${n('recibos_numero')} · ${kz('recibos_valor')}`],
      ['Total recebido', kz('recebido')],
      ['Concluídas no prazo', r.resumo.percentagem_no_prazo === null || r.resumo.percentagem_no_prazo === undefined ? '—' : `${r.resumo.percentagem_no_prazo}%`],
      ['Danos registados', n('danos')],
      ['Saldo em aberto (actual)', `${kz('saldo_aberto')} (por facturar ${kz('por_facturar')})`],
    ],
    2,
  );
  const docs = tabelaHtml({
    legenda: 'Documentos',
    linhas: r.documentos,
    totais: true,
    colunas: [
      { titulo: 'Data', valor: (d) => d.data, formato: 'data' },
      { titulo: 'Documento', valor: (d) => d.numero },
      { titulo: 'Ordem', valor: (d) => d.ordem },
      { titulo: 'Cliente', valor: (d) => d.cliente, quebrar: true },
      { titulo: 'Base', valor: (d) => d.base, formato: 'moeda', somar: true },
      { titulo: 'IVA', valor: (d) => d.iva, formato: 'moeda', somar: true },
      { titulo: 'Total', valor: (d) => d.total, formato: 'moeda', somar: true },
      { titulo: 'Estado', valor: (d) => (d.estado ? rotuloEstadoPOS(d.estado) : '') },
    ],
  });
  const servicos = tabelaHtml({
    legenda: 'Por serviço',
    linhas: r.por_servico,
    totais: true,
    colunas: [
      { titulo: 'Serviço', valor: (s) => s.servico },
      { titulo: 'Qtd.', valor: (s) => s.quantidade, formato: 'numero' },
      { titulo: 'Total', valor: (s) => s.total, formato: 'moeda', somar: true },
    ],
  });
  const pecas = tabelaHtml({
    legenda: 'Por peça',
    linhas: r.por_peca,
    totais: true,
    colunas: [
      { titulo: 'Peça', valor: (p) => p.peca },
      { titulo: 'Qtd.', valor: (p) => p.quantidade, formato: 'numero' },
      { titulo: 'Etiquetas', valor: (p) => p.etiquetas, formato: 'inteiro', somar: true },
      { titulo: 'Valor', valor: (p) => p.valor, formato: 'moeda', somar: true },
    ],
  });
  const meios = tabelaHtml({
    legenda: 'Por meio de pagamento',
    linhas: r.por_meio,
    totais: true,
    colunas: [
      { titulo: 'Meio', valor: (m) => m.meio },
      { titulo: 'N.º', valor: (m) => m.numero, formato: 'inteiro', somar: true },
      { titulo: 'Total', valor: (m) => m.total, formato: 'moeda', somar: true },
    ],
  });
  const terminais = tabelaHtml({
    legenda: 'Por terminal',
    linhas: r.por_terminal,
    totais: true,
    colunas: [
      { titulo: 'Terminal', valor: (t) => t.terminal },
      { titulo: 'Ordens', valor: (t) => t.ordens, formato: 'inteiro', somar: true },
      { titulo: 'Facturado', valor: (t) => t.facturado, formato: 'moeda', somar: true },
      { titulo: 'Recebido', valor: (t) => t.recebido, formato: 'moeda', somar: true },
    ],
  });
  const dias = tabelaHtml({
    legenda: 'Por dia',
    linhas: r.por_dia.filter((d) => d.recebidas || d.entregues || d.documentos),
    totais: true,
    colunas: [
      { titulo: 'Dia', valor: (d) => d.dia, formato: 'data' },
      { titulo: 'Recebidas', valor: (d) => d.recebidas, formato: 'inteiro', somar: true },
      { titulo: 'Etiquetas', valor: (d) => d.etiquetas, formato: 'inteiro', somar: true },
      { titulo: 'Entregues', valor: (d) => d.entregues, formato: 'inteiro', somar: true },
      { titulo: 'Documentos', valor: (d) => d.documentos, formato: 'inteiro', somar: true },
      { titulo: 'Facturado', valor: (d) => d.facturado, formato: 'moeda', somar: true },
      { titulo: 'Recebido', valor: (d) => d.recebido, formato: 'moeda', somar: true },
    ],
  });
  return `${resumo}${docs}${servicos}${pecas}${meios}${terminais}${dias}`;
}
