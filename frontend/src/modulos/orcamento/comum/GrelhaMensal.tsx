import { Button, Dropdown, Input, InputNumber, Modal, Space, Table, Tooltip, Typography, theme } from 'antd';
import { MoreOutlined } from '@ant-design/icons';
import type { ColumnsType } from 'antd/es/table';
import { useMemo, useState } from 'react';
import { useEcra } from '@/componentes/responsivo';
import { formatarKz } from '@/utilitarios/formatacao';
import { Kz } from './componentes';
import { MESES, repartirAnual, totalAnual } from './regras';
import type { Rubrica, TipoOrcamento } from './tipos';

export interface ValoresRubrica {
  valores: number[];
  notas: string | null;
}

type TipoLinha = 'natureza' | 'grupo' | 'rubrica' | 'total' | 'resultado' | 'saldo';

export interface LinhaGrelha {
  chave: string;
  tipo: TipoLinha;
  rotulo: string;
  rubrica?: Rubrica;
  /** 12 meses; `total` = total anual (nulo nas linhas sem total, como o saldo final). */
  valores: number[];
  total: number | null;
}

const NOME_NAT: Record<string, string> = { PROVEITO: 'Proveitos', CUSTO: 'Custos', RECEBIMENTO: 'Recebimentos', PAGAMENTO: 'Pagamentos' };
const vazio12 = () => Array(12).fill(0) as number[];
const arred = (x: number) => Math.round(x * 100) / 100;
const somar = (a: number[], b: number[]) => a.map((x, i) => arred(x + (b[i] ?? 0)));

/**
 * Linhas da grelha como no legado (orcamento_ui.js:401-427): por natureza (Proveitos/Custos ou Recebimentos/Pagamentos),
 * os grupos com o subtotal mensal, as rubricas e «Total <natureza>»; no fim «Resultado previsto» (exploração) ou
 * «Fluxo de caixa do mês» e «Saldo final previsto» a partir do saldo inicial (tesouraria).
 */
export function linhasGrelha(tipo: TipoOrcamento, rubricas: Rubrica[], valores: Record<number, ValoresRubrica>, saldoInicial = 0): LinhaGrelha[] {
  const res: LinhaGrelha[] = [];
  const tes = tipo === 'TESOURARIA';
  const ordem = tes ? ['RECEBIMENTO', 'PAGAMENTO'] : ['PROVEITO', 'CUSTO'];
  const visiveis = rubricas.filter((r) => r.ativo !== false || valores[r.id]);
  const vals = (r: Rubrica) => {
    const v = [...(valores[r.id]?.valores ?? [])].map(Number);
    while (v.length < 12) v.push(0);
    return v.slice(0, 12);
  };
  const totaisNat: Record<string, number[]> = {};
  for (const nat of ordem) {
    const daNat = visiveis.filter((r) => r.natureza === nat);
    res.push({ chave: `n-${nat}`, tipo: 'natureza', rotulo: NOME_NAT[nat], valores: [], total: null });
    const grupos = new Map<string, Rubrica[]>();
    for (const r of daNat) {
      const g = r.grupo || 'Sem grupo';
      grupos.set(g, [...(grupos.get(g) ?? []), r]);
    }
    let totNat = vazio12();
    for (const [g, rs] of grupos) {
      const sub = rs.reduce((a, r) => somar(a, vals(r)), vazio12());
      res.push({ chave: `g-${nat}-${g}`, tipo: 'grupo', rotulo: g, valores: sub, total: totalAnual(sub) });
      for (const r of rs) res.push({ chave: `r-${r.id}`, tipo: 'rubrica', rotulo: `${r.codigo} ${r.nome}`, rubrica: r, valores: vals(r), total: totalAnual(vals(r)) });
      totNat = somar(totNat, sub);
    }
    totaisNat[nat] = totNat;
    res.push({ chave: `t-${nat}`, tipo: 'total', rotulo: `Total ${NOME_NAT[nat].toLowerCase()}`, valores: totNat, total: totalAnual(totNat) });
  }
  const fluxo = totaisNat[ordem[0]].map((x, i) => arred(x - totaisNat[ordem[1]][i]));
  if (tes) {
    res.push({ chave: 't-fluxo', tipo: 'resultado', rotulo: 'Fluxo de caixa do mês', valores: fluxo, total: totalAnual(fluxo) });
    const saldo: number[] = [];
    fluxo.forEach((x, i) => saldo.push(arred((i ? saldo[i - 1] : Number(saldoInicial) || 0) + x)));
    res.push({ chave: 't-saldo', tipo: 'saldo', rotulo: 'Saldo final previsto', valores: saldo, total: null });
  } else {
    res.push({ chave: 't-res', tipo: 'resultado', rotulo: 'Resultado previsto', valores: fluxo, total: totalAnual(fluxo) });
  }
  return res;
}

type Linha = LinhaGrelha;

/**
 * Grelha de 12 meses por rubrica (orçamentos), com a disposição do legado: linhas por natureza, subtotais por grupo,
 * totais por natureza, resultado (ou fluxo e saldo final) e as colunas «Total {ano}» e «Real. {ano-1}». Em modo de
 * edição cada célula é um campo numérico; o menu da linha reparte um total anual ou copia o 1.º mês.
 */
export function GrelhaMensal({ tipo, rubricas, valores, editavel, aoMudar, rotulosMeses = MESES, comNotas, ano, realAnterior, saldoInicial }: {
  tipo: TipoOrcamento;
  rubricas: Rubrica[];
  valores: Record<number, ValoresRubrica>;
  editavel?: boolean;
  aoMudar?: (rubricaId: number, v: ValoresRubrica) => void;
  rotulosMeses?: string[];
  comNotas?: boolean;
  /** Ano do orçamento (cabeçalhos «Total {ano}» e «Real. {ano-1}»). */
  ano?: number;
  /** Realizado do ano anterior por rubrica (total anual); sem dados a coluna mostra «—». */
  realAnterior?: Record<number, number | string> | null;
  /** Saldo inicial de disponibilidades (tesouraria) para o «Saldo final previsto». */
  saldoInicial?: number;
}) {
  const [repartir, setRepartir] = useState<{ rubrica: Rubrica; total: number | null } | null>(null);
  // No telemóvel a coluna da rubrica não fica fixa (260 px fixos ocupariam quase todo o ecrã).
  const { telemovel } = useEcra();
  const { token } = theme.useToken();
  const linhas = useMemo(() => linhasGrelha(tipo, rubricas, valores, saldoInicial), [tipo, rubricas, valores, saldoInicial]);
  const nColunas = rotulosMeses.length + 3 + (comNotas ? 1 : 0);
  const juntar = (l: Linha) => (l.tipo === 'natureza' ? { colSpan: 0 } : {});

  const mudarMes = (r: Rubrica, mes: number, v: number | null) => {
    const actual = valores[r.id] ?? { valores: Array(12).fill(0), notas: null };
    const novos = [...actual.valores];
    while (novos.length < 12) novos.push(0);
    novos[mes] = v ?? 0;
    aoMudar?.(r.id, { ...actual, valores: novos });
  };

  const colunas: ColumnsType<Linha> = [
    {
      title: 'Rubrica', key: 'r', fixed: telemovel ? undefined : 'left', width: telemovel ? 180 : 260,
      onCell: (l) => (l.tipo === 'natureza' ? { colSpan: nColunas } : {}),
      render: (_, l) => {
        if (l.tipo === 'natureza') return <Typography.Text strong>{l.rotulo}</Typography.Text>;
        if (l.tipo === 'grupo') return <Typography.Text strong type="secondary" style={{ paddingLeft: 16 }}>{l.rotulo}</Typography.Text>;
        if (l.tipo !== 'rubrica') return <Typography.Text strong>{l.rotulo}</Typography.Text>;
        const r = l.rubrica!;
        return (
          <Space size={4}>
            <Typography.Text><strong>{r.codigo}</strong> {r.nome}</Typography.Text>
            {editavel && (
              <Dropdown trigger={['click']} menu={{
                items: [{ key: 'rep', label: 'Repartir um total anual' }, { key: 'cop', label: 'Copiar Jan. para todos os meses' }, { key: 'zero', label: 'Limpar a linha' }],
                onClick: ({ key }) => {
                  const actual = valores[r.id] ?? { valores: Array(12).fill(0), notas: null };
                  if (key === 'rep') setRepartir({ rubrica: r, total: totalAnual(actual.valores) || null });
                  if (key === 'cop') aoMudar?.(r.id, { ...actual, valores: Array(12).fill(actual.valores[0] ?? 0) });
                  if (key === 'zero') aoMudar?.(r.id, { ...actual, valores: Array(12).fill(0) });
                },
              }}>
                <Button size="small" type="text" icon={<MoreOutlined />} aria-label={`Opções da rubrica ${r.codigo}`} />
              </Dropdown>
            )}
          </Space>
        );
      },
    },
    ...rotulosMeses.map((m, i) => ({
      title: m,
      key: `m${i}`,
      align: 'right' as const,
      width: editavel ? 120 : undefined,
      onCell: juntar,
      render: (_: unknown, l: Linha) => {
        if (l.tipo === 'natureza') return null;
        if (editavel && l.rubrica) return <InputNumber size="small" value={l.valores[i] ?? 0} precision={2} style={{ width: 112 }} controls={false} onChange={(v) => mudarMes(l.rubrica!, i, v)} aria-label={`${l.rubrica.codigo} ${m}`} />;
        return <Kz valor={l.valores[i] ?? 0} forte={l.tipo !== 'rubrica'} />;
      },
    })),
    {
      title: ano ? `Total ${ano}` : 'Total', key: 't', align: 'right', fixed: telemovel ? undefined : 'right', onCell: juntar,
      render: (_, l) => (l.total === null ? null : <Kz valor={l.total} forte />),
    },
    {
      title: ano ? `Real. ${ano - 1}` : 'Real. ano anterior', key: 'ra', align: 'right', onCell: juntar,
      render: (_, l) => {
        if (l.tipo !== 'rubrica') return null;
        const v = realAnterior?.[l.rubrica!.id];
        return <Typography.Text type="secondary" style={{ whiteSpace: 'nowrap' }}>{v === undefined || v === null ? '—' : formatarKz(v)}</Typography.Text>;
      },
    },
  ];
  if (comNotas)
    colunas.push({
      title: 'Notas', key: 'n', width: 220, onCell: juntar,
      render: (_, l) => {
        if (!l.rubrica) return null;
        const v = valores[l.rubrica.id];
        return editavel
          ? <Input size="small" value={v?.notas ?? ''} maxLength={2000} placeholder="Justificação" onChange={(e) => aoMudar?.(l.rubrica!.id, { valores: v?.valores ?? Array(12).fill(0), notas: e.target.value || null })} />
          : <Tooltip title={v?.notas}><Typography.Text ellipsis style={{ maxWidth: 200 }}>{v?.notas ?? ''}</Typography.Text></Tooltip>;
      },
    });

  return (
    <>
      <Table<Linha>
        rowKey="chave"
        size="small"
        pagination={false}
        dataSource={linhas}
        columns={colunas}
        scroll={{ x: 'max-content', y: 620 }}
        rowClassName={(l) => `erp-grelha-${l.tipo}`}
        onRow={(l) => ({ style: l.tipo === 'natureza' || l.tipo === 'grupo' ? { background: token.colorFillAlter, fontWeight: 700 } : l.tipo !== 'rubrica' ? { background: token.colorFillSecondary, fontWeight: 800 } : undefined })}
      />
      <Modal
        title={`Repartir ${repartir?.rubrica.codigo ?? ''} por 12 meses`}
        open={!!repartir}
        onCancel={() => setRepartir(null)}
        okText="Repartir"
        cancelText="Cancelar"
        onOk={() => {
          if (repartir) aoMudar?.(repartir.rubrica.id, { valores: repartirAnual(repartir.total ?? 0), notas: valores[repartir.rubrica.id]?.notas ?? null });
          setRepartir(null);
        }}
      >
        <Typography.Paragraph type="secondary">Valor anual repartido em 12 partes iguais ao cêntimo (Dezembro absorve o arredondamento).</Typography.Paragraph>
        <InputNumber autoFocus precision={2} style={{ width: 240 }} value={repartir?.total} onChange={(v) => repartir && setRepartir({ ...repartir, total: v })} suffix="Kz" />
        {repartir?.total ? <Typography.Paragraph style={{ marginTop: 8 }}>≈ {formatarKz(repartirAnual(repartir.total)[0])} Kz por mês</Typography.Paragraph> : null}
      </Modal>
    </>
  );
}
