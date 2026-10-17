import { Alert, Card, Skeleton, Switch, Table, Tooltip, Typography } from 'antd';
import type { ColumnsType } from 'antd/es/table';
import { useQuery } from '@tanstack/react-query';
import { useEffect, useRef } from 'react';
import { obter } from '@/api/cliente';
import { IconeFa } from '@/componentes/fluxos/IconeFa';
import { BotoesExportar } from '@/componentes/impressao';
import { scrollTabela } from '@/componentes/responsivo';
import { useSessao } from '@/sessao/SessaoContexto';
import { eZero, somar } from '@/utilitarios/decimal';
import { notificarErro } from '@/utilitarios/erros';
import { ValorKz } from '../comum/Componentes';
import { classeLinhaDemonstracao } from '../comum/Demonstracao';
import type { LinhaPlana } from '../comum/demonstracoes';
import './mapas.css';

export type MapaPorEmpresa = 'balancete' | 'balanco' | 'dr' | 'fluxo';

export interface ColunaPorEmpresa {
  chave: string;
  nome: string;
  empresa_id?: number;
  tipo: 'empresa' | 'ajuste' | 'consolidado';
}

export interface DadosPorEmpresa {
  mapa: MapaPorEmpresa;
  execucao: { id: number; data_fim: string | null; moeda: string | null };
  colunas: ColunaPorEmpresa[];
  /** coluna → rubrica (demonstrações) ou conta (balancete) → valor */
  valores: Record<string, Record<string, string>>;
  linhas?: { codigo_conta: string; descricao: string | null; totalizadora: boolean }[];
}

/** A empresa activa é uma holding (empresa de consolidação)? */
export function useEHolding(): boolean {
  return !!useSessao().empresa?.e_consolidacao;
}

/** Interruptor «Por empresa» (legado: «Colunas por empresa», js/consolidacao.js:150-153) — só na holding. */
export function InterruptorPorEmpresa({ ligado, aoMudar }: { ligado: boolean; aoMudar: (v: boolean) => void }) {
  const holding = useEHolding();
  if (!holding) return null;
  return (
    <Tooltip title="Uma coluna por empresa do grupo, eliminações, conversão e consolidado (da última consolidação), sem comparativo">
      <span className="imp-nao-imprimir" style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}>
        <Switch size="small" checked={ligado} onChange={aoMudar} aria-label="Por empresa" />
        <span>
          <IconeFa nome="building" /> Por empresa
        </span>
      </span>
    </Tooltip>
  );
}

/** Parâmetros do mapa para a vista por empresa (sem comparativo nem opções que não se aplicam). */
export function parametrosPorEmpresa(p: Record<string, unknown>): Record<string, unknown> {
  const { comparativo: _c, modo_comparativo: _m, por_terceiro: _t, ...resto } = p;
  void _c;
  void _m;
  void _t;
  return resto;
}

export function usePorEmpresa(mapa: MapaPorEmpresa, parametros: Record<string, unknown> | null | undefined, ligado: boolean) {
  const holding = useEHolding();
  const p = parametros ? parametrosPorEmpresa(parametros) : null;
  const q = useQuery({
    queryKey: ['contab', 'mapa', 'por-empresa', mapa, p],
    queryFn: () => obter<DadosPorEmpresa>(`/contabilidade/relatorios/por-empresa/${mapa}`, p ?? undefined),
    enabled: holding && ligado && !!p,
    retry: false,
  });
  useEffect(() => {
    if (q.error) notificarErro(q.error, 'Não foi possível calcular o mapa por empresa');
  }, [q.error]);
  return q;
}

const cabecalho = (c: ColunaPorEmpresa) => (
  <span title={c.nome} style={{ whiteSpace: 'nowrap', fontWeight: c.tipo === 'consolidado' ? 700 : undefined }}>
    {c.nome.length > 18 ? `${c.nome.slice(0, 16)}…` : c.nome}
  </span>
);

/** Linha da demonstração mostrada? (como o legado: sem valores em nenhuma coluna só ficam os títulos e totais). */
export function linhaVisivelPorEmpresa(l: LinhaPlana, d: DadosPorEmpresa): boolean {
  if (l.tipo === 'seccao' || l.tipo === 'total' || l.tipo === 'subtotal') return true;
  return d.colunas.some((c) => !eZero(l.rubrica ? d.valores[c.chave]?.[l.rubrica] : undefined));
}

/** Demonstração (Balanço, DR, Fluxo) com uma coluna por empresa + consolidado; as linhas são as do mapa consolidado. */
export function DemonstracaoPorEmpresa({ linhas, dados, titulo, periodo, carregando }: { linhas: LinhaPlana[]; dados?: DadosPorEmpresa; titulo: string; periodo?: string; carregando: boolean }) {
  const ref = useRef<HTMLDivElement>(null);
  if (!dados) return <Card>{carregando ? <Skeleton active /> : null}</Card>;
  const visiveis = linhas.filter((l) => linhaVisivelPorEmpresa(l, dados));
  const colunas: ColumnsType<LinhaPlana> = [
    { title: 'Designação', dataIndex: 'descricao', fixed: 'left', render: (v: string) => <span className="erp-dem-designacao" style={{ whiteSpace: 'nowrap' }}>{v}</span> },
    { title: 'Notas', dataIndex: 'nota', width: 60, align: 'center', render: (v: string | null, l) => (l.tipo === 'seccao' ? null : v) },
    ...dados.colunas.map((c) => ({
      title: cabecalho(c),
      key: c.chave,
      align: 'right' as const,
      render: (_: unknown, l: LinhaPlana) => {
        if (l.tipo === 'seccao' || !l.rubrica) return null;
        const v = dados.valores[c.chave]?.[l.rubrica];
        return v === undefined ? (c.tipo === 'consolidado' ? <ValorKz valor={l.atual ?? '0.00'} forte /> : null) : <ValorKz valor={v} forte={c.tipo === 'consolidado'} />;
      },
    })),
  ];
  return (
    <Card>
      <div style={{ marginBottom: 12, display: 'flex', flexWrap: 'wrap', gap: 8, alignItems: 'center' }}>
        <BotoesExportar obterPedido={() => ref.current && { titulo: `${titulo} — por empresa`, periodo, filtros: [`Consolidação de ${dados.execucao.data_fim ?? ''}`], orientacao: 'paisagem', conteudo: ref.current }} />
        <Typography.Text type="secondary" style={{ fontSize: 12 }}>
          Valores da última consolidação ({dados.execucao.data_fim ?? '—'}{dados.execucao.moeda ? `, ${dados.execucao.moeda}` : ''}); sem comparativo.
        </Typography.Text>
      </div>
      <div ref={ref}>
        <Table<LinhaPlana> className="erp-mapa-contab" rowKey="chave" size="small" pagination={false} columns={colunas} dataSource={visiveis} rowClassName={classeLinhaDemonstracao} scroll={scrollTabela()} />
      </div>
    </Card>
  );
}

/** Balancete da holding por empresa: saldo final (D − C; credor negativo) de cada conta em cada coluna. */
export function BalancetePorEmpresa({ dados, periodo, carregando }: { dados?: DadosPorEmpresa; periodo?: string; carregando: boolean }) {
  const ref = useRef<HTMLDivElement>(null);
  if (!dados) return carregando ? <Skeleton active /> : null;
  const linhas = dados.linhas ?? [];
  const movimento = linhas.filter((l) => !l.totalizadora);
  const colunas: ColumnsType<(typeof linhas)[number]> = [
    { title: 'Conta', dataIndex: 'codigo_conta', fixed: 'left', render: (v: string) => <span className="erp-bal-codigo">{v}</span> },
    { title: 'Descrição', dataIndex: 'descricao', render: (v: string | null, l) => <span className="erp-bal-descricao">{`${l.totalizadora ? 'Σ ' : ''}${v ?? ''}`}</span> },
    ...dados.colunas.map((c) => ({
      title: cabecalho(c),
      key: c.chave,
      align: 'right' as const,
      render: (_: unknown, l: (typeof linhas)[number]) => {
        const v = dados.valores[c.chave]?.[l.codigo_conta];
        return eZero(v) ? <span className="erp-bal-vazio">-</span> : <ValorKz valor={v ?? '0.00'} forte={c.tipo === 'consolidado'} />;
      },
    })),
  ];
  return (
    <>
      {dados.colunas.length <= 1 && <Alert type="info" showIcon message="O grupo não tem empresas com valores neste período." style={{ marginBottom: 8 }} />}
      <div style={{ marginBottom: 8 }}>
        <BotoesExportar obterPedido={() => ref.current && { titulo: 'Balancete — por empresa', periodo, filtros: ['Saldo final (D − C): credor negativo'], orientacao: 'paisagem', conteudo: ref.current }} />
      </div>
      <div ref={ref}>
        <Table
          className="erp-mapa-contab"
          rowKey={(l) => `${l.codigo_conta}${l.totalizadora ? '|T' : ''}`}
          size="small"
          columns={colunas}
          dataSource={linhas}
          rowClassName={(l) => (l.totalizadora ? 'erp-bal-totalizadora' : 'erp-bal-movimento')}
          pagination={{ pageSize: 100, showSizeChanger: true }}
          scroll={scrollTabela()}
          summary={() => (
            <Table.Summary fixed>
              <Table.Summary.Row>
                <Table.Summary.Cell index={0} colSpan={2} align="right">
                  <strong>TOTAIS (contas de movimento):</strong>
                </Table.Summary.Cell>
                {dados.colunas.map((c, i) => (
                  <Table.Summary.Cell key={c.chave} index={i + 2} align="right">
                    <ValorKz valor={somar(movimento.map((l) => dados.valores[c.chave]?.[l.codigo_conta] ?? '0.00'))} forte />
                  </Table.Summary.Cell>
                ))}
              </Table.Summary.Row>
            </Table.Summary>
          )}
        />
      </div>
    </>
  );
}
