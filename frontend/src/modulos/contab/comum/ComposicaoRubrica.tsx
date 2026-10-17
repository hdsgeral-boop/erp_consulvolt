import { Alert, Segmented, Skeleton, Space, Table, Tag, Typography, theme } from 'antd';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { obter } from '@/api/cliente';
import { scrollTabela } from '@/componentes/responsivo';
import { eZero, somar } from '@/utilitarios/decimal';
import { formatarData, formatarKz } from '@/utilitarios/formatacao';
import { ValorKz } from './Componentes';
import { useAbrirLancamento } from './useMapa';

export type TipoDemonstracao = 'balanco' | 'dr' | 'fluxo';

export interface ContaComposicao {
  codigo_conta: string;
  descricao: string | null;
  nota: string | null;
  linhas: number;
  debito: string;
  credito: string;
  valor: string;
}

export interface ComponenteComposicao {
  rubrica: string;
  descricao: string;
  sinal: 1 | -1;
  valor: string;
}

export interface Composicao {
  tipo: TipoDemonstracao;
  rubrica: string;
  descricao: string;
  periodo: { inicio: string; fim: string; ambito: 'atual' | 'anterior' };
  valor: string;
  formula: string | null;
  criterio: string | null;
  historico: boolean;
  componentes: ComponenteComposicao[];
  contas: ContaComposicao[];
  ajustes: { descricao: string; valor: string }[];
  total_contas: string;
  movimentos?: { id: number; data_documento: string; diario: string | null; numero_lan: string | null; numero_documento: string | null; descricao: string | null; tipo_dc: 'D' | 'C'; valor: string; nota: string | null }[];
}

const URL = (tipo: TipoDemonstracao) => `/contabilidade/relatorios/composicao/${tipo}`;

/** Parâmetros do mapa que a composição usa (as datas, o modo do comparativo e os filtros; nunca a opção «comparativo»). */
export function parametrosComposicao(parametros: Record<string, unknown>, rubrica: string, periodo: 'atual' | 'anterior', conta?: string): Record<string, unknown> {
  const { comparativo: _c, ...resto } = parametros;
  void _c;
  return { ...resto, rubrica, periodo, codigo_conta: conta };
}

/** Soma das parcelas (contas + ajustes, ou rubricas com sinal) — tem de dar o valor do mapa. */
export function somaParcelas(c: Pick<Composicao, 'componentes' | 'contas' | 'ajustes'>): string {
  if (c.componentes.length) return somar(c.componentes.map((x) => (x.sinal < 0 ? `-${x.valor}`.replace('--', '') : x.valor)));
  return somar([...c.contas.map((x) => x.valor), ...c.ajustes.map((x) => x.valor)]);
}

/**
 * Cálculo de uma rubrica das demonstrações financeiras (Balanço, DR, Fluxos de caixa), mostrado ao abrir a linha: o critério,
 * a fórmula (totais) com as rubricas somadas/subtraídas, ou as contas de movimento que compõem a nota (débito, crédito e o
 * valor com o sinal da rubrica), os ajustes de apresentação e a verificação «soma = valor do mapa». Cada conta abre os seus
 * movimentos, com ligação ao lançamento.
 */
export function ComposicaoRubrica({ tipo, rubrica, parametros, comparativo, rotulos }: { tipo: TipoDemonstracao; rubrica: string; parametros: Record<string, unknown>; comparativo: boolean; rotulos: [string, string] }) {
  const { token } = theme.useToken();
  const [periodo, setPeriodo] = useState<'atual' | 'anterior'>('atual');
  const consulta = useQuery({
    queryKey: ['contab', 'mapa', 'composicao', tipo, rubrica, periodo, parametros],
    queryFn: () => obter<Composicao>(URL(tipo), parametrosComposicao(parametros, rubrica, periodo)),
    retry: false,
  });
  const c = consulta.data;
  const soma = c ? somaParcelas(c) : null;
  const confere = c && soma !== null && eZero(somar([soma, `-${c.valor}`.replace('--', '')]));

  return (
    <div style={{ padding: '8px 12px', background: token.colorFillQuaternary, borderLeft: `3px solid ${token.colorPrimary}` }}>
      <Space wrap style={{ marginBottom: 8 }} className="imp-nao-imprimir">
        <Typography.Text strong>Cálculo da rubrica</Typography.Text>
        {comparativo && (
          <Segmented<'atual' | 'anterior'>
            size="small"
            value={periodo}
            onChange={setPeriodo}
            options={[
              { value: 'atual', label: rotulos[0] },
              { value: 'anterior', label: rotulos[1] },
            ]}
          />
        )}
      </Space>
      {consulta.error ? (
        <Alert type="error" showIcon message="Não foi possível obter o cálculo desta rubrica." />
      ) : !c ? (
        <Skeleton active paragraph={{ rows: 2 }} />
      ) : (
        <>
          <Typography.Paragraph type="secondary" style={{ marginBottom: 6, fontSize: 12 }}>
            {formatarData(c.periodo.inicio)} a {formatarData(c.periodo.fim)}
            {c.criterio ? ` · ${c.criterio}` : ''}
          </Typography.Paragraph>
          {c.formula && (
            <Typography.Paragraph style={{ marginBottom: 6, fontSize: 12 }}>
              <strong>Fórmula:</strong> {c.formula}
            </Typography.Paragraph>
          )}
          {c.historico ? (
            <Alert type="info" showIcon message={`Valor dos saldos históricos importados: ${formatarKz(c.valor)}`} />
          ) : c.componentes.length > 0 ? (
            <Table<ComponenteComposicao>
              rowKey="rubrica"
              size="small"
              pagination={false}
              scroll={scrollTabela()}
              dataSource={c.componentes}
              columns={[
                { title: 'Operação', dataIndex: 'sinal', width: 80, align: 'center', render: (s: number) => <Tag color={s < 0 ? 'red' : 'green'}>{s < 0 ? '−' : '+'}</Tag> },
                { title: 'Rubrica', dataIndex: 'rubrica', width: 110 },
                { title: 'Descrição', dataIndex: 'descricao' },
                { title: 'Valor', dataIndex: 'valor', align: 'right', render: (v: string) => <ValorKz valor={v} /> },
              ]}
              summary={() => (
                <Table.Summary.Row>
                  <Table.Summary.Cell index={0} colSpan={3} align="right">
                    <strong>= {c.descricao}</strong>
                  </Table.Summary.Cell>
                  <Table.Summary.Cell index={3} align="right">
                    <ValorKz valor={c.valor} forte />
                  </Table.Summary.Cell>
                </Table.Summary.Row>
              )}
            />
          ) : (
            <TabelaContas tipo={tipo} rubrica={rubrica} periodo={periodo} parametros={parametros} c={c} />
          )}
          {!c.historico && (
            <Typography.Text type={confere ? 'success' : 'danger'} style={{ fontSize: 12 }}>
              {confere ? '✓ A soma das parcelas é igual ao valor do mapa.' : `A soma das parcelas (${formatarKz(soma)}) difere do valor do mapa (${formatarKz(c.valor)}).`}
            </Typography.Text>
          )}
        </>
      )}
    </div>
  );
}

type LinhaConta = ContaComposicao & { chave: string; ajuste?: boolean };

function TabelaContas({ tipo, rubrica, periodo, parametros, c }: { tipo: TipoDemonstracao; rubrica: string; periodo: 'atual' | 'anterior'; parametros: Record<string, unknown>; c: Composicao }) {
  const [abertas, setAbertas] = useState<string[]>([]);
  const linhas: LinhaConta[] = [
    ...c.contas.map((x) => ({ ...x, chave: `${x.codigo_conta}|${x.nota ?? ''}` })),
    ...c.ajustes.map((a, i) => ({ chave: `ajuste-${i}`, ajuste: true, codigo_conta: '', descricao: a.descricao, nota: null, linhas: 0, debito: '0.00', credito: '0.00', valor: a.valor })),
  ];
  const comNota = c.contas.some((x) => x.nota && x.nota !== rubrica);
  return (
    <Table<LinhaConta>
      rowKey="chave"
      size="small"
      pagination={linhas.length > 50 ? { pageSize: 50 } : false}
      scroll={scrollTabela()}
      dataSource={linhas}
      locale={{ emptyText: 'Nenhum lançamento compõe esta rubrica no período.' }}
      expandable={{
        expandedRowKeys: abertas,
        onExpandedRowsChange: (k) => setAbertas(k.map(String)),
        rowExpandable: (l) => !l.ajuste,
        expandedRowRender: (l) => <MovimentosConta tipo={tipo} rubrica={rubrica} periodo={periodo} parametros={parametros} conta={l.codigo_conta} />,
      }}
      columns={[
        { title: 'Conta', dataIndex: 'codigo_conta', render: (v: string, l) => (l.ajuste ? <Tag>Ajuste</Tag> : <strong>{v}</strong>) },
        { title: 'Descrição', dataIndex: 'descricao', ellipsis: true, render: (v: string | null, l) => (l.ajuste ? <em>{v}</em> : v ?? 'Conta desconhecida') },
        ...(comNota ? [{ title: 'Nota', dataIndex: 'nota', width: 70 }] : []),
        { title: 'Linhas', dataIndex: 'linhas', align: 'right', responsive: ['md'], render: (v: number, l) => (l.ajuste ? '' : v) },
        { title: 'Débito', dataIndex: 'debito', align: 'right', render: (v: string, l) => (l.ajuste ? '' : <ValorKz valor={v} discretoSeZero />) },
        { title: 'Crédito', dataIndex: 'credito', align: 'right', render: (v: string, l) => (l.ajuste ? '' : <ValorKz valor={v} discretoSeZero />) },
        { title: 'Valor na rubrica', dataIndex: 'valor', align: 'right', render: (v: string) => <ValorKz valor={v} forte /> },
      ]}
      summary={() => (
        <Table.Summary.Row>
          <Table.Summary.Cell index={0} colSpan={comNota ? 7 : 6} align="right">
            <strong>= {c.descricao}</strong>
          </Table.Summary.Cell>
          <Table.Summary.Cell index={comNota ? 7 : 6} align="right">
            <ValorKz valor={c.valor} forte />
          </Table.Summary.Cell>
        </Table.Summary.Row>
      )}
    />
  );
}

function MovimentosConta({ tipo, rubrica, periodo, parametros, conta }: { tipo: TipoDemonstracao; rubrica: string; periodo: 'atual' | 'anterior'; parametros: Record<string, unknown>; conta: string }) {
  const abrir = useAbrirLancamento();
  const consulta = useQuery({
    queryKey: ['contab', 'mapa', 'composicao', tipo, rubrica, periodo, parametros, conta],
    queryFn: () => obter<Composicao>(URL(tipo), parametrosComposicao(parametros, rubrica, periodo, conta)),
    retry: false,
  });
  const m = consulta.data?.movimentos;
  if (consulta.error) return <Alert type="error" showIcon message="Não foi possível obter os movimentos da conta." />;
  if (!m) return <Skeleton active paragraph={{ rows: 1 }} />;
  return (
    <Table
      rowKey="id"
      size="small"
      dataSource={m}
      scroll={scrollTabela()}
      pagination={m.length > 20 ? { pageSize: 20, showTotal: (n) => `${n} movimento(s)` } : false}
      columns={[
        { title: 'Data', dataIndex: 'data_documento', render: formatarData, width: 100 },
        { title: 'Diário', dataIndex: 'diario', responsive: ['md'] },
        { title: 'Nº Lanç.', dataIndex: 'numero_lan', render: (v: string | null, r) => (abrir ? <Typography.Link title="Abrir o lançamento" onClick={() => abrir(r.id)}>{v || r.numero_documento || r.id}</Typography.Link> : v) },
        { title: 'Nº Doc', dataIndex: 'numero_documento', responsive: ['lg'] },
        { title: 'Descrição', dataIndex: 'descricao', ellipsis: true, width: 260 },
        { title: 'Débito', key: 'd', align: 'right', render: (_, r) => (r.tipo_dc === 'D' ? <ValorKz valor={r.valor} /> : null) },
        { title: 'Crédito', key: 'c', align: 'right', render: (_, r) => (r.tipo_dc === 'C' ? <ValorKz valor={r.valor} /> : null) },
      ]}
    />
  );
}
