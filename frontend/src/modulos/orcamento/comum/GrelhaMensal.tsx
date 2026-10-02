import { Button, Dropdown, Input, InputNumber, Modal, Space, Table, Tooltip, Typography } from 'antd';
import { MoreOutlined } from '@ant-design/icons';
import type { ColumnsType } from 'antd/es/table';
import { useMemo, useState } from 'react';
import { useEcra } from '@/componentes/responsivo';
import { formatarKz } from '@/utilitarios/formatacao';
import { Kz } from './componentes';
import { MESES, repartirAnual, sinal, totalAnual, totaisMensais } from './regras';
import type { Rubrica, TipoOrcamento } from './tipos';

export interface ValoresRubrica {
  valores: number[];
  notas: string | null;
}

interface Linha {
  chave: string;
  rubrica?: Rubrica;
  grupo?: string;
  total?: 'entradas' | 'saidas' | 'saldo';
  valores: number[];
}

/**
 * Grelha de 12 meses por rubrica (orçamentos e previsões). Em modo de edição cada célula é um campo numérico;
 * o menu da linha permite repartir um total anual por 12 meses iguais ou copiar o 1.º mês para os restantes.
 * Mostra totais de entradas, saídas e resultado (exploração) ou saldo (tesouraria).
 */
export function GrelhaMensal({ tipo, rubricas, valores, editavel, aoMudar, rotulosMeses = MESES, comNotas }: {
  tipo: TipoOrcamento;
  rubricas: Rubrica[];
  valores: Record<number, ValoresRubrica>;
  editavel?: boolean;
  aoMudar?: (rubricaId: number, v: ValoresRubrica) => void;
  rotulosMeses?: string[];
  comNotas?: boolean;
}) {
  const [repartir, setRepartir] = useState<{ rubrica: Rubrica; total: number | null } | null>(null);
  // No telemóvel a coluna da rubrica não fica fixa (260 px fixos ocupariam quase todo o ecrã).
  const { telemovel } = useEcra();
  const entradas = tipo === 'EXPLORACAO' ? 'Proveitos' : 'Recebimentos';
  const saidas = tipo === 'EXPLORACAO' ? 'Custos' : 'Pagamentos';

  const linhas = useMemo<Linha[]>(() => {
    const res: Linha[] = [];
    let grupo: string | null | undefined;
    const doGrupo = rubricas.filter((r) => r.ativo !== false || valores[r.id]);
    for (const r of doGrupo) {
      if (r.grupo !== grupo) {
        grupo = r.grupo;
        if (grupo) res.push({ chave: `g-${grupo}`, grupo, valores: [] });
      }
      res.push({ chave: `r-${r.id}`, rubrica: r, valores: valores[r.id]?.valores ?? Array(12).fill(0) });
    }
    const comNatureza = doGrupo.map((r) => ({ valores: valores[r.id]?.valores ?? [], natureza: r.natureza }));
    const e = totaisMensais(comNatureza.filter((x) => sinal(x.natureza) > 0));
    const s = totaisMensais(comNatureza.filter((x) => sinal(x.natureza) < 0));
    const saldo = totaisMensais(comNatureza, true);
    res.push({ chave: 't-e', total: 'entradas', valores: e.slice(0, 12) }, { chave: 't-s', total: 'saidas', valores: s.slice(0, 12) }, { chave: 't-r', total: 'saldo', valores: saldo.slice(0, 12) });
    return res;
  }, [rubricas, valores]);

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
      render: (_, l) => {
        if (l.grupo) return <Typography.Text strong type="secondary">{l.grupo}</Typography.Text>;
        if (l.total) return <Typography.Text strong>{l.total === 'entradas' ? `Total ${entradas.toLowerCase()}` : l.total === 'saidas' ? `Total ${saidas.toLowerCase()}` : tipo === 'EXPLORACAO' ? 'Resultado' : 'Saldo do período'}</Typography.Text>;
        const r = l.rubrica!;
        return (
          <Space size={4}>
            <Typography.Text>{r.codigo} {r.nome}</Typography.Text>
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
                <Button size="small" type="text" icon={<MoreOutlined />} />
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
      render: (_: unknown, l: Linha) => {
        if (l.grupo) return null;
        if (editavel && l.rubrica) return <InputNumber size="small" value={l.valores[i] ?? 0} precision={2} style={{ width: 112 }} controls={false} onChange={(v) => mudarMes(l.rubrica!, i, v)} />;
        return <Kz valor={l.valores[i] ?? 0} forte={!!l.total} />;
      },
    })),
    { title: 'Total', key: 't', align: 'right', fixed: telemovel ? undefined : 'right', render: (_, l) => (l.grupo ? null : <Kz valor={totalAnual(l.valores)} forte />) },
  ];
  if (comNotas)
    colunas.push({
      title: 'Notas', key: 'n', width: 220,
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
        onRow={(l) => ({ style: l.total ? { background: 'rgba(0,0,0,0.03)' } : l.grupo ? { background: 'rgba(0,0,0,0.015)' } : undefined })}
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
