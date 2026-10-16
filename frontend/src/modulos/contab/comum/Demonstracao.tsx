import { Modal, Skeleton, Space, Statistic, Table, Typography } from 'antd';
import type { ColumnsType } from 'antd/es/table';
import { useQuery } from '@tanstack/react-query';
import { useRef, useState } from 'react';
import { BotoesExportar } from '@/componentes/impressao';
import { larguraModal, scrollTabela } from '@/componentes/responsivo';
import { obter } from '@/api/cliente';
import { formatarData, formatarKz } from '@/utilitarios/formatacao';
import { negativo } from '@/utilitarios/decimal';
import { BotaoCsv, ValorKz } from './Componentes';
import { useTabelaAux } from './dados';
import '../mapas/mapas.css';
import type { LinhaPlana } from './demonstracoes';
import { useAbrirLancamento } from './useMapa';

interface LinhaMovimento {
  id: number;
  data_documento: string;
  diario: string | null;
  numero_lan: string;
  numero_documento: string | null;
  codigo_conta: string;
  descricao: string | null;
  tipo_dc: 'D' | 'C';
  valor: string;
}

/** Classe de cada linha (secção, subsecção, nota, subtotal, total, realces da DR) — mapas.css, como o legado. */
export function classeLinhaDemonstracao(l: LinhaPlana): string {
  if (l.estilo === 'proveitos' || l.estilo === 'custos' || l.estilo === 'resultado') return `erp-dem-${l.estilo}`;
  if (l.estilo === 'subseccao') return 'erp-dem-subseccao';
  return `erp-dem-${l.tipo}${l.estilo === 'final' ? ' erp-dem-final' : ''}`;
}

/** Análise vertical (AV %): valor sobre a base do agregado; sem base → «-» (js/ui_reports.js:1256-1260). */
export function analiseVertical(valor: string | undefined, base: string | undefined): string {
  const b = Number(base ?? 0);
  if (valor === undefined || !b) return '-';
  return `${((Number(valor) / b) * 100).toFixed(1)}%`;
}

/** Análise horizontal (AH %): variação sobre o ano anterior, com sinal; anterior zero e actual ≠ 0 → «+100.0%» (js/ui_reports.js:1262-1273). */
export function analiseHorizontal(atual: string | undefined, anterior: string | undefined): { texto: string; sentido: 'sobe' | 'desce' | 'igual' } {
  const c = Number(atual ?? 0);
  const p = Number(anterior ?? 0);
  const diff = c - p;
  const sentido = diff > 0.004 ? 'sobe' : diff < -0.004 ? 'desce' : 'igual';
  if (p !== 0) {
    const pct = (diff / Math.abs(p)) * 100;
    return { texto: pct > 0 ? `+${pct.toFixed(1)}%` : `${pct.toFixed(1)}%`, sentido };
  }
  return { texto: c !== 0 ? '+100.0%' : '-', sentido };
}

/**
 * Tabela de uma demonstração financeira (linhas planas) com coluna comparativa e drill-down pelas notas
 * (GET /contabilidade/relatorios/notas/{tipo}/{id}). As notas vêm pelo código; o id resolve-se pelas tabelas auxiliares.
 */
export function TabelaDemonstracao({
  linhas,
  comparativo,
  rotuloAtual,
  rotuloAnterior,
  tipoNota,
  parametros,
  nomeCsv,
  impressao,
}: {
  linhas: LinhaPlana[];
  comparativo: boolean;
  rotuloAtual: string;
  rotuloAnterior: string;
  tipoNota: 'demonstracao' | 'fluxo';
  parametros: Record<string, unknown>;
  nomeCsv: string;
  /** Título/período/filtros do documento impresso (Imprimir/PDF da demonstração tal como está no ecrã). */
  impressao?: { titulo: string; periodo?: string; filtros?: (string | null | undefined | false)[] };
}) {
  const refTabela = useRef<HTMLDivElement>(null);
  const notas = useTabelaAux(tipoNota === 'demonstracao' ? 'notas-demonstracao' : 'notas-fluxo-caixa');
  const [notaAberta, setNotaAberta] = useState<{ id: number; codigo: string } | null>(null);
  const idNota = (codigo: string | null | undefined) => (codigo ? notas.data?.find((n) => n.codigo === codigo)?.id : undefined);

  const comAV = linhas.some((l) => l.base !== undefined);
  const semValor = (l: LinhaPlana) => l.tipo === 'seccao';
  // colunas do legado (js/ui_reports.js:1155-1165 e 1322-1332): Designação · Notas · Ano N · AV % · Ano N-1 · AH %
  const colunas: ColumnsType<LinhaPlana> = [
    {
      title: 'Designação',
      dataIndex: 'descricao',
      render: (v: string) => <span className="erp-dem-designacao" style={{ whiteSpace: 'nowrap' }}>{v}</span>,
    },
    {
      title: 'Notas',
      dataIndex: 'nota',
      width: 70,
      align: 'center',
      render: (v: string | null, l) => {
        if (!v || l.tipo === 'seccao') return null;
        const id = idNota(v);
        return id ? (
          <Typography.Link strong onClick={() => setNotaAberta({ id, codigo: v })}>
            {v}
          </Typography.Link>
        ) : (
          v
        );
      },
    },
    { title: rotuloAtual, dataIndex: 'atual', align: 'right', render: (v: string | undefined, l) => (v === undefined || semValor(l) ? null : <ValorKz valor={v} forte={negativo(v)} />) },
    ...(comAV
      ? [
          {
            title: 'AV %',
            key: 'av',
            align: 'right' as const,
            onHeaderCell: () => ({ className: 'erp-mapa-av' }),
            onCell: (l: LinhaPlana) => ({ className: semValor(l) ? undefined : 'erp-mapa-av' }),
            render: (_: unknown, l: LinhaPlana) => (semValor(l) ? null : analiseVertical(l.atual, l.base)),
          },
        ]
      : []),
    ...(comparativo
      ? [
          {
            title: rotuloAnterior,
            dataIndex: 'anterior',
            align: 'right' as const,
            render: (v: string | undefined, l: LinhaPlana) =>
              v === undefined || semValor(l) ? null : (
                <span className={negativo(v) ? undefined : 'erp-mapa-anterior'}>
                  <ValorKz valor={v} forte={negativo(v)} />
                </span>
              ),
          },
          {
            title: 'AH %',
            key: 'ah',
            align: 'right' as const,
            render: (_: unknown, l: LinhaPlana) => {
              if (semValor(l) || l.atual === undefined) return null;
              const ah = analiseHorizontal(l.atual, l.anterior);
              return <span className={`erp-mapa-ah erp-mapa-ah-${ah.sentido}`}>{ah.texto}</span>;
            },
          },
        ]
      : []),
  ];

  return (
    <>
      <div style={{ marginBottom: 12, display: 'flex', flexWrap: 'wrap', gap: 8 }}>
        {impressao && <BotoesExportar obterPedido={() => refTabela.current && { ...impressao, conteudo: refTabela.current }} />}
        <BotaoCsv<LinhaPlana>
          nome={nomeCsv}
          linhas={linhas}
          colunas={[
            { titulo: 'Designação', valor: (l) => l.descricao },
            { titulo: 'Notas', valor: (l) => l.nota },
            { titulo: rotuloAtual, valor: (l) => l.atual, numerico: true },
            ...(comAV ? [{ titulo: 'AV %', valor: (l: LinhaPlana) => (l.tipo === 'seccao' ? null : analiseVertical(l.atual, l.base)) }] : []),
            ...(comparativo
              ? [
                  { titulo: rotuloAnterior, valor: (l: LinhaPlana) => l.anterior, numerico: true },
                  { titulo: 'AH %', valor: (l: LinhaPlana) => (l.tipo === 'seccao' || l.atual === undefined ? null : analiseHorizontal(l.atual, l.anterior).texto) },
                ]
              : []),
          ]}
        />
      </div>
      <div ref={refTabela}>
        <Table<LinhaPlana>
          className="erp-mapa-contab"
          rowKey="chave"
          size="small"
          pagination={false}
          columns={colunas}
          dataSource={linhas}
          rowClassName={classeLinhaDemonstracao}
          scroll={scrollTabela()}
        />
      </div>
      {notaAberta && <DetalheNota tipo={tipoNota} nota={notaAberta} parametros={parametros} aoFechar={() => setNotaAberta(null)} />}
    </>
  );
}

function DetalheNota({ tipo, nota, parametros, aoFechar }: { tipo: string; nota: { id: number; codigo: string }; parametros: Record<string, unknown>; aoFechar: () => void }) {
  const abrir = useAbrirLancamento();
  const { data_inicio, data_fim, ...resto } = parametros;
  void resto;
  const consulta = useQuery({
    queryKey: ['contab', 'nota', tipo, nota.id, data_inicio, data_fim],
    queryFn: () =>
      obter<{ nota: { codigo: string; descricao: string }; totais: { linhas: number; debito: string; credito: string; saldo_devedor: string }; linhas: LinhaMovimento[] }>(
        `/contabilidade/relatorios/notas/${tipo}/${nota.id}`,
        { data_inicio, data_fim },
      ),
  });
  const d = consulta.data;
  return (
    <Modal open title={d ? `Nota ${d.nota.codigo} — ${d.nota.descricao}` : `Nota ${nota.codigo}`} onCancel={aoFechar} footer={null} width={larguraModal(1000)}>
      {!d ? (
        <Skeleton active />
      ) : (
        <>
          <Space size={32} wrap style={{ marginBottom: 12 }}>
            <Statistic title="Linhas" value={d.totais.linhas} />
            <Statistic title="Débito" value={formatarKz(d.totais.debito)} />
            <Statistic title="Crédito" value={formatarKz(d.totais.credito)} />
            <Statistic title="Saldo devedor" value={formatarKz(d.totais.saldo_devedor)} />
          </Space>
          <TabelaMovimentos linhas={d.linhas} abrir={abrir} />
        </>
      )}
    </Modal>
  );
}

export function TabelaMovimentos({ linhas, abrir }: { linhas: LinhaMovimento[]; abrir?: (id: number) => void }) {
  return (
    <Table<LinhaMovimento>
      rowKey="id"
      size="small"
      dataSource={linhas}
      pagination={{ pageSize: 20, showTotal: (n) => `${n} movimento(s)` }}
      scroll={scrollTabela()}
      columns={[
        { title: 'Data', dataIndex: 'data_documento', render: formatarData },
        { title: 'Diário', dataIndex: 'diario', responsive: ['md'] },
        { title: 'N.º lançamento', dataIndex: 'numero_lan', render: (v: string, r) => (abrir ? <Typography.Link onClick={() => abrir(r.id)}>{v}</Typography.Link> : v) },
        { title: 'Documento', dataIndex: 'numero_documento', responsive: ['md'] },
        { title: 'Conta', dataIndex: 'codigo_conta' },
        { title: 'Descrição', dataIndex: 'descricao', ellipsis: true, width: 260 },
        { title: 'Débito', align: 'right', render: (_, r) => (r.tipo_dc === 'D' ? <ValorKz valor={r.valor} /> : null) },
        { title: 'Crédito', align: 'right', render: (_, r) => (r.tipo_dc === 'C' ? <ValorKz valor={r.valor} /> : null) },
      ]}
    />
  );
}

/** «Movimentos por mapear» (linhas sem nota DEMO) — alerta do Balanço/DR (GET /relatorios/movimentos-sem-nota). */
export function MovimentosSemNota({ parametros, aoFechar }: { parametros: Record<string, unknown>; aoFechar: () => void }) {
  const abrir = useAbrirLancamento();
  const consulta = useQuery({
    queryKey: ['contab', 'movimentos-sem-nota', parametros],
    queryFn: () =>
      obter<{ resumo: { linhas: number; debito: string; credito: string; diferenca: string }; por_conta: { codigo_conta: string; linhas: number; saldo: string }[]; linhas: LinhaMovimento[] }>(
        '/contabilidade/relatorios/movimentos-sem-nota',
        { ...parametros, limite: 2000 },
      ),
  });
  const d = consulta.data;
  return (
    <Modal open title="Movimentos por mapear (sem nota DEMO)" onCancel={aoFechar} footer={null} width={larguraModal(1100)}>
      {!d ? (
        <Skeleton active />
      ) : (
        <>
          <Space size={32} wrap style={{ marginBottom: 12 }}>
            <Statistic title="Linhas" value={d.resumo.linhas} />
            <Statistic title="Débito" value={formatarKz(d.resumo.debito)} />
            <Statistic title="Crédito" value={formatarKz(d.resumo.credito)} />
            <Statistic title="Diferença" value={formatarKz(d.resumo.diferenca)} />
          </Space>
          <Typography.Title level={5}>Por conta</Typography.Title>
          <Table
            rowKey="codigo_conta"
            size="small"
            dataSource={d.por_conta}
            pagination={{ pageSize: 10 }}
            scroll={scrollTabela()}
            columns={[
              { title: 'Conta', dataIndex: 'codigo_conta' },
              { title: 'Linhas', dataIndex: 'linhas', align: 'right' },
              { title: 'Saldo', dataIndex: 'saldo', align: 'right', render: (v: string) => <ValorKz valor={v} /> },
            ]}
          />
          <Typography.Title level={5}>Movimentos</Typography.Title>
          <TabelaMovimentos linhas={d.linhas} abrir={abrir} />
        </>
      )}
    </Modal>
  );
}
