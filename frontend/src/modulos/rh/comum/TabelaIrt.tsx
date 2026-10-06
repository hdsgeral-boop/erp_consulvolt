import { Alert, Button, InputNumber, Popconfirm, Space, Tag, Typography } from 'antd';
import { RollbackOutlined, SaveOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { obter } from '@/api/cliente';
import { scrollTabela } from '@/componentes/responsivo';
import { useSessao } from '@/sessao/SessaoContexto';
import { formatarKz, formatarNumero } from '@/utilitarios/formatacao';
import type { ConfiguracaoRH, EscalaoTabelaIrt } from '../api';
import { useAccaoRh, useAvisarErro } from './consultas';

import { TabelaComModos } from '@/componentes/vistas';
/** Validação local (o servidor valida de novo): limites crescentes e só o último escalão sem limite. */
export function validarTabelaIrt(t: EscalaoTabelaIrt[]): string | null {
  if (t.length < 2) return 'A tabela tem de ter pelo menos dois escalões.';
  for (let i = 0; i < t.length; i++) {
    const ultimo = i === t.length - 1;
    if (ultimo !== (t[i].max === null)) return ultimo ? 'O último escalão não tem limite superior.' : `Indique o limite superior do escalão ${i + 1}.`;
    if (i > 0 && t[i].max !== null && (t[i].max as number) <= (t[i - 1].max ?? 0)) return `Os limites têm de ser crescentes (escalão ${i + 1}).`;
    if (t[i].taxa < 0 || t[i].taxa > 100) return `Taxa inválida no escalão ${i + 1}.`;
  }
  return null;
}

/**
 * Tabela de IRT do Grupo A (decisão 1 do utilizador): IRT = parcela fixa + (matéria colectável − excesso) × taxa, no primeiro
 * escalão cujo limite é ≥ à matéria colectável. Comum a todas as empresas; aplica-se aos encerramentos seguintes (os
 * períodos já encerrados mantêm a fotografia).
 */
export function TabelaIrt() {
  const { pode } = useSessao();
  const editar = pode('rh_tabela_irt_gerir');
  const q = useQuery({ queryKey: ['rh', 'configuracao'], queryFn: () => obter<ConfiguracaoRH>('/rh/configuracao') });
  useAvisarErro(q.error);
  const [tabela, setTabela] = useState<EscalaoTabelaIrt[]>([]);
  useEffect(() => { if (q.data) setTabela(q.data.tabela_irt.map((e) => ({ ...e }))); }, [q.data]);
  const accao = useAccaoRh();
  const erro = validarTabelaIrt(tabela);
  const mudar = (i: number, campo: keyof EscalaoTabelaIrt, v: number | null) => setTabela(tabela.map((e, k) => (k === i ? { ...e, [campo]: v ?? (campo === 'max' ? null : 0) } : e)));
  const num = (i: number, campo: keyof EscalaoTabelaIrt, v: number | null, min?: number) => (editar
    ? <InputNumber size="small" value={v} min={min ?? 0} decimalSeparator="," style={{ width: 140 }} aria-label={`${campo} do escalão ${i + 1}`} onChange={(x) => mudar(i, campo, x)}
      disabled={campo === 'max' && i === tabela.length - 1} />
    : campo === 'taxa' ? `${formatarNumero(v)} %` : v === null ? '—' : formatarKz(v));

  return (
    <>
      <Alert type="warning" showIcon style={{ marginBottom: 12 }} message="Confirme a tabela com o Código do IRT em vigor"
        description="Por omissão é a tabela do sistema anterior (engine_v2.js): isenção até 150 000 Kz e parcela fixa de 12 500 Kz a partir de 150 000,01 Kz. Se a lei mudar, altere-a aqui; os períodos já encerrados não mudam." />
      <Space wrap style={{ marginBottom: 12 }}>
        {q.data?.tabela_irt_personalizada ? <Tag color="orange">Tabela alterada</Tag> : <Tag color="green">Tabela original (engine_v2.js)</Tag>}
        {editar && <Button type="primary" icon={<SaveOutlined />} disabled={Boolean(erro)} loading={accao.isPending} onClick={() => accao.mutate({ metodo: 'put', url: '/rh/tabela-irt', dados: { escaloes: tabela } })}>Gravar tabela</Button>}
        {editar && q.data?.tabela_irt_personalizada && (
          <Popconfirm title="Repor a tabela original?" okText="Repor" cancelText="Cancelar" onConfirm={() => accao.mutateAsync({ metodo: 'delete', url: '/rh/tabela-irt' })}>
            <Button icon={<RollbackOutlined />}>Repor original</Button>
          </Popconfirm>
        )}
        {editar && erro && <Typography.Text type="danger">{erro}</Typography.Text>}
      </Space>
      <TabelaComModos<EscalaoTabelaIrt> rowKey={(_, i) => String(i)} size="small" pagination={false} loading={q.isFetching} dataSource={tabela} scroll={scrollTabela()} columns={[
        { title: 'Escalão', render: (_, __, i) => i + 1, width: 80 },
        { title: 'Até (Kz)', render: (_, e, i) => num(i, 'max', e.max) },
        { title: 'Parcela fixa (Kz)', render: (_, e, i) => num(i, 'fixo', e.fixo) },
        { title: 'Taxa (%)', render: (_, e, i) => num(i, 'taxa', e.taxa) },
        { title: 'Excesso de (Kz)', render: (_, e, i) => num(i, 'excesso', e.excesso) },
      ]} />
    </>
  );
}
