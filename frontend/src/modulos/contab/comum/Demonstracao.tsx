import { Modal, Skeleton, Space, Statistic, Table, Typography } from 'antd';
import type { ColumnsType } from 'antd/es/table';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { obter } from '@/api/cliente';
import { formatarData, formatarKz } from '@/utilitarios/formatacao';
import { BotaoCsv, ValorKz } from './Componentes';
import { useTabelaAux } from './dados';
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

const ESTILO: Record<LinhaPlana['tipo'], React.CSSProperties> = {
  seccao: { fontWeight: 600, textTransform: 'none', background: '#fafafa' },
  linha: {},
  subtotal: { fontWeight: 600 },
  total: { fontWeight: 700, borderTop: '2px solid #d9d9d9' },
};

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
}: {
  linhas: LinhaPlana[];
  comparativo: boolean;
  rotuloAtual: string;
  rotuloAnterior: string;
  tipoNota: 'demonstracao' | 'fluxo';
  parametros: Record<string, unknown>;
  nomeCsv: string;
}) {
  const notas = useTabelaAux(tipoNota === 'demonstracao' ? 'notas-demonstracao' : 'notas-fluxo-caixa');
  const [notaAberta, setNotaAberta] = useState<{ id: number; codigo: string } | null>(null);
  const idNota = (codigo: string | null | undefined) => (codigo ? notas.data?.find((n) => n.codigo === codigo)?.id : undefined);

  const colunas: ColumnsType<LinhaPlana> = [
    {
      title: 'Rubrica',
      dataIndex: 'descricao',
      render: (v: string, l) => <span style={{ paddingLeft: l.tipo === 'linha' ? 16 : 0 }}>{v}</span>,
    },
    {
      title: 'Nota',
      dataIndex: 'nota',
      width: 80,
      render: (v: string | null, l) => {
        if (!v || l.tipo === 'seccao') return null;
        const id = idNota(v);
        return id ? <Typography.Link onClick={() => setNotaAberta({ id, codigo: v })}>{v}</Typography.Link> : v;
      },
    },
    { title: rotuloAtual, dataIndex: 'atual', align: 'right', render: (v: string | undefined) => (v === undefined ? null : <ValorKz valor={v} />) },
    ...(comparativo ? [{ title: rotuloAnterior, dataIndex: 'anterior', align: 'right' as const, render: (v: string | undefined) => (v === undefined ? null : <ValorKz valor={v} />) }] : []),
  ];

  return (
    <>
      <div style={{ marginBottom: 12 }}>
        <BotaoCsv<LinhaPlana>
          nome={nomeCsv}
          linhas={linhas}
          colunas={[
            { titulo: 'Rubrica', valor: (l) => l.descricao },
            { titulo: 'Nota', valor: (l) => l.nota },
            { titulo: rotuloAtual, valor: (l) => l.atual, numerico: true },
            ...(comparativo ? [{ titulo: rotuloAnterior, valor: (l: LinhaPlana) => l.anterior, numerico: true }] : []),
          ]}
        />
      </div>
      <Table<LinhaPlana>
        rowKey="chave"
        size="small"
        pagination={false}
        columns={colunas}
        dataSource={linhas}
        onRow={(l) => ({ style: ESTILO[l.tipo] })}
        scroll={{ x: 'max-content' }}
      />
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
    <Modal open title={d ? `Nota ${d.nota.codigo} — ${d.nota.descricao}` : `Nota ${nota.codigo}`} onCancel={aoFechar} footer={null} width={1000}>
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
      scroll={{ x: 'max-content' }}
      columns={[
        { title: 'Data', dataIndex: 'data_documento', render: formatarData },
        { title: 'Diário', dataIndex: 'diario' },
        { title: 'N.º lançamento', dataIndex: 'numero_lan', render: (v: string, r) => (abrir ? <Typography.Link onClick={() => abrir(r.id)}>{v}</Typography.Link> : v) },
        { title: 'Documento', dataIndex: 'numero_documento' },
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
    <Modal open title="Movimentos por mapear (sem nota DEMO)" onCancel={aoFechar} footer={null} width={1100}>
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
