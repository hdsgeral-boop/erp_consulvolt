import { Card, Checkbox, Flex, Form, InputNumber, Skeleton, Table } from 'antd';
import type { ColumnsType } from 'antd/es/table';
import { useQuery } from '@tanstack/react-query';
import { useEffect, useMemo, useState } from 'react';
import { TituloMapa } from './TituloMapa';
import './mapas.css';
import { obter } from '@/api/cliente';
import { BotoesExportar, tabelaHtml } from '@/componentes/impressao';
import { scrollTabela, useEcraPequeno } from '@/componentes/responsivo';
import { formatarKz } from '@/utilitarios/formatacao';
import { NOMES_MESES, type Evolucao } from '../api';
import { BotaoCsv, ValorKz } from '../comum/Componentes';
import { FiltrosMapa } from '../comum/FiltrosMapa';
import { filtrosDosParametros, periodoDosParametros } from '../comum/impressao';
import { useMapa } from '../comum/useMapa';
import { agruparPorNivel } from './arvoreContas';
import { BotaoDetalheConta, BotoesArvore, DetalheConta, type PedidoDetalheConta } from './DetalheConta';

type LinhaEvolucao = Evolucao['contas'][number];
type TerceiroEvolucao = NonNullable<LinhaEvolucao['terceiros']>[number] & { nome?: string | null };

/** Nome do terceiro na evolução (o servidor devolve `nome`; versões anteriores `terceiro`). */
const nomeTerceiro = (t: TerceiroEvolucao) => (t.nome ?? t.terceiro)?.trim() || '—';

/**
 * Mapas › Evolução mensal (ecrã contab_mapa_evolucao): movimento líquido por mês e saldo, por conta (e terceiro).
 * Com «Nível» (contas agregadas, ex.: razão), cada linha abre as contas de movimento que a compõem (a mesma evolução sem
 * nível, pedida quando se abre a 1.ª); cada conta de movimento abre o extracto do ano (movimentos com ligação ao lançamento).
 */
export default function MapaEvolucao() {
  const mapa = useMapa<Evolucao>('evolucao', '/contabilidade/relatorios/evolucao');
  const d = mapa.data;
  const p = mapa.parametros;
  const meses = d?.meses_ativos?.length ? d.meses_ativos : Array.from({ length: 12 }, (_, i) => i + 1);
  const pequeno = useEcraPequeno();
  const nivel = p?.nivel ? Number(p.nivel) : null;
  const [abertas, setAbertas] = useState<string[]>([]);
  const [detalhe, setDetalhe] = useState<PedidoDetalheConta | null>(null);
  useEffect(() => setAbertas([]), [d]);

  const pMovimento = p && nivel ? { ...p, nivel: undefined, por_terceiro: undefined } : null;
  const movimento = useQuery({
    queryKey: ['contab', 'mapa', 'evolucao', 'movimento', pMovimento],
    queryFn: () => obter<Evolucao>('/contabilidade/relatorios/evolucao', pMovimento ?? undefined),
    enabled: !!pMovimento && abertas.length > 0,
    retry: false,
  });
  const porGrupo = useMemo(() => (nivel ? agruparPorNivel(movimento.data?.contas ?? [], nivel) : new Map<string, LinhaEvolucao[]>()), [movimento.data, nivel]);
  const expansiveis = (d?.contas ?? []).filter((c) => !!nivel || !!c.terceiros?.length).map((c) => c.codigo_conta);
  const abrirDetalhe = (c: { codigo_conta: string; descricao: string | null }) =>
    setDetalhe({ conta: c.codigo_conta, descricao: c.descricao, inicio: `${d?.ano}-01-01`, fim: `${d?.ano}-12-31`, filtros: p });

  const colunasMeses = <T extends { meses: Record<string, string> }>(prefixo: string) =>
    meses.map((m) => ({
      title: NOMES_MESES[m - 1],
      key: `${prefixo}${m}`,
      align: 'right' as const,
      render: (_: unknown, r: T) => <ValorKz valor={r.meses[String(m)] ?? '0.00'} discretoSeZero />,
    }));

  const colunas: ColumnsType<LinhaEvolucao> = [
    {
      title: 'Conta',
      dataIndex: 'codigo_conta',
      fixed: pequeno ? undefined : 'left',
      render: (v: string, r) => (
        <span style={{ whiteSpace: 'nowrap' }}>
          {v}
          {!nivel && <BotaoDetalheConta aoClicar={() => abrirDetalhe(r)} titulo="Ver os movimentos da conta no ano" />}
        </span>
      ),
    },
    { title: 'Descrição', dataIndex: 'descricao', ellipsis: true, width: 220 },
    ...colunasMeses<LinhaEvolucao>('m'),
    { title: 'Saldo', dataIndex: 'saldo', align: 'right', fixed: pequeno ? undefined : 'right', render: (v: string) => <ValorKz valor={v} forte /> },
  ];

  const tabelaTerceiros = (r: LinhaEvolucao) => (
    <Table<TerceiroEvolucao>
      scroll={scrollTabela()}
      rowKey="terceiro_id"
      size="small"
      pagination={false}
      dataSource={r.terceiros as TerceiroEvolucao[]}
      columns={[
        { title: 'Terceiro', key: 'terceiro', render: (_, t) => nomeTerceiro(t) },
        ...colunasMeses<TerceiroEvolucao>('t'),
        { title: 'Saldo', dataIndex: 'saldo', align: 'right', render: (v: string) => <ValorKz valor={v} /> },
      ]}
    />
  );

  const tabelaMovimento = (r: LinhaEvolucao) =>
    movimento.isLoading ? (
      <Skeleton active paragraph={{ rows: 2 }} />
    ) : (
      <Table<LinhaEvolucao>
        scroll={scrollTabela()}
        rowKey="codigo_conta"
        size="small"
        pagination={false}
        dataSource={porGrupo.get(r.codigo_conta) ?? []}
        locale={{ emptyText: 'Sem contas de movimento.' }}
        columns={[
          {
            title: 'Conta de movimento',
            dataIndex: 'codigo_conta',
            render: (v: string, c) => (
              <span style={{ whiteSpace: 'nowrap' }}>
                {v}
                <BotaoDetalheConta aoClicar={() => abrirDetalhe(c)} titulo="Ver os movimentos da conta no ano" />
              </span>
            ),
          },
          { title: 'Descrição', dataIndex: 'descricao', ellipsis: true, width: 200 },
          ...colunasMeses<LinhaEvolucao>('s'),
          { title: 'Saldo', dataIndex: 'saldo', align: 'right', render: (v: string) => <ValorKz valor={v} forte /> },
        ]}
      />
    );

  return (
    <>
      <TituloMapa titulo="Evolução Mensal" subtitulo="Balancete de evolução por mês" />
      <Card style={{ marginBottom: 16 }}>
        <FiltrosMapa
          modo="ano"
          aCalcular={mapa.isFetching}
          aoCalcular={mapa.calcular}
          valoresIniciais={{ nivel: 2 }}
          extra={
            <>
              <Form.Item name="nivel" label="Nível" style={{ marginBottom: 8 }}>
                <InputNumber min={1} max={20} style={{ width: 90 }} />
              </Form.Item>
              <Form.Item name="por_terceiro" valuePropName="checked" style={{ marginBottom: 8 }}>
                <Checkbox>Por terceiro</Checkbox>
              </Form.Item>
            </>
          }
        />
      </Card>
      {d && (
        <Card
          extra={
            <Flex gap={8} wrap>
              <BotoesArvore aoExpandir={() => setAbertas(expansiveis)} aoRecolher={() => setAbertas([])} desactivado={!expansiveis.length} />
              <BotoesExportar
                obterPedido={() => ({
                  titulo: `Evolução mensal ${d.ano}`,
                  periodo: periodoDosParametros(mapa.parametros),
                  filtros: filtrosDosParametros(mapa.parametros),
                  orientacao: 'paisagem',
                  conteudo: documentoEvolucao(d, meses, nivel ? porGrupo : undefined),
                })}
              />
              <BotaoCsv<LinhaEvolucao>
                nome={`evolucao_${d.ano}`}
                linhas={d.contas}
                colunas={[
                  { titulo: 'Conta', valor: (l) => l.codigo_conta },
                  { titulo: 'Descrição', valor: (l) => l.descricao },
                  ...meses.map((m) => ({ titulo: NOMES_MESES[m - 1], valor: (l: LinhaEvolucao) => l.meses[String(m)] ?? '0.00', numerico: true })),
                  { titulo: 'Saldo', valor: (l) => l.saldo, numerico: true },
                ]}
              />
            </Flex>
          }
        >
          <Table<LinhaEvolucao>
            // cabeçalho escuro (#0f172a) do legado (js/ui_reports.js:3137-3143)
            className="erp-mapa-contab"
            rowKey="codigo_conta"
            size="small"
            columns={colunas}
            dataSource={d.contas}
            pagination={false}
            scroll={scrollTabela()}
            expandable={{
              expandedRowKeys: abertas,
              onExpandedRowsChange: (k) => setAbertas(k.map(String)),
              rowExpandable: (r) => !!nivel || !!r.terceiros?.length,
              expandedRowRender: (r) => (nivel ? tabelaMovimento(r) : tabelaTerceiros(r)),
            }}
            summary={() => (
              <Table.Summary fixed>
                <Table.Summary.Row>
                  <Table.Summary.Cell index={0} colSpan={3}>
                    <strong>Totais</strong>
                  </Table.Summary.Cell>
                  {meses.map((m, i) => (
                    <Table.Summary.Cell key={m} index={i + 3} align="right">
                      <ValorKz valor={d.totais[String(m)] ?? '0.00'} forte />
                    </Table.Summary.Cell>
                  ))}
                  <Table.Summary.Cell index={meses.length + 3} align="right">
                    <ValorKz valor={d.saldo_total} forte />
                  </Table.Summary.Cell>
                </Table.Summary.Row>
              </Table.Summary>
            )}
          />
        </Card>
      )}
      <DetalheConta pedido={detalhe} aoFechar={() => setDetalhe(null)} />
    </>
  );
}

/**
 * Evolução mensal para impressão: as contas e, se pedido, os terceiros de cada conta — ou, com nível e as contas de
 * movimento já carregadas («Expandir tudo»), as contas de movimento debaixo de cada conta agregada — com os totais por mês.
 */
export function documentoEvolucao(d: Evolucao, meses: number[], movimento?: Map<string, Evolucao['contas']>): string {
  type Linha = { conta: string; descricao: string; meses: Record<string, string>; saldo: string; terceiro?: boolean };
  const linhas: Linha[] = d.contas.flatMap((c) => [
    { conta: c.codigo_conta, descricao: c.descricao ?? '', meses: c.meses, saldo: c.saldo },
    ...(movimento?.get(c.codigo_conta) ?? []).map((m) => ({ conta: `   ${m.codigo_conta}`, descricao: `   ${m.descricao ?? ''}`, meses: m.meses, saldo: m.saldo, terceiro: true })),
    ...((c.terceiros ?? []) as TerceiroEvolucao[]).map((t) => ({ conta: '', descricao: `   · ${nomeTerceiro(t)}`, meses: t.meses, saldo: t.saldo, terceiro: true })),
  ]);
  return tabelaHtml<Linha>({
    linhas,
    totais: 'Totais',
    colunas: [
      { titulo: 'Conta', valor: (l) => l.conta, total: 'Totais' },
      { titulo: 'Descrição', valor: (l) => l.descricao, total: '' },
      ...meses.map((m) => ({ titulo: NOMES_MESES[m - 1], valor: (l: Linha) => l.meses[String(m)] ?? '0.00', formato: 'moeda' as const, total: formatarKz(d.totais[String(m)] ?? '0.00') })),
      { titulo: 'Saldo', valor: (l) => l.saldo, formato: 'moeda', total: formatarKz(d.saldo_total) },
    ],
  });
}
