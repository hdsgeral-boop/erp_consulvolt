import { Alert, Button, Card, Select, Space, Table, Tag, Typography } from 'antd';
import { useQuery } from '@tanstack/react-query';
import dayjs from 'dayjs';
import { useState } from 'react';
import { IconeFa } from '@/componentes/fluxos';
import { scrollTabela } from '@/componentes/responsivo';
import { CartaoKpi, GrelhaKpi } from '@/componentes/vistas';
import { formatarData } from '@/utilitarios/formatacao';
import type { ResumoPortal } from '../api';
import { EstadoTag } from '../comum/componentes';
import { equipaDirecta, feriasDaEquipa, type PeriodoFeriasEquipa } from './api';

/**
 * Portal › Férias (portal_ui.js desenharFerias, 235-271): KPIs direito, marcados, aprovados, gozados e disponíveis do
 * ano, o período em que se pode pedir e o atalho para o pedido; para a chefia, a sub-secção «A minha equipa» com as
 * férias do ano da equipa directa (GET equipa/ferias?ano).
 */
export function FeriasPortal({ resumo: r, aoPedir }: { resumo: ResumoPortal; aoPedir: () => void }) {
  const f = r.ferias;
  return (
    <>
      <Card title={<Space><IconeFa nome="umbrella-beach" />As minhas férias de {r.ano}</Space>} extra={<Button type="primary" icon={<IconeFa nome="plus" />} disabled={!f.periodo} onClick={aoPedir}>Pedir férias</Button>}>
        <GrelhaKpi larguraMinima={140} style={{ marginBottom: 12 }}>
          <CartaoKpi barra="nenhuma" rotulo="Direito" valor={f.direito} unidade="dias" />
          <CartaoKpi barra="nenhuma" rotulo="Marcados" valor={f.marcados} unidade="dias" secundaria="inclui pedidos" />
          {f.aprovados !== undefined && <CartaoKpi barra="nenhuma" rotulo="Aprovados" valor={f.aprovados} unidade="dias" />}
          {f.gozados !== undefined && <CartaoKpi barra="nenhuma" rotulo="Gozados" valor={f.gozados} unidade="dias" />}
          <CartaoKpi barra="nenhuma" rotulo="Disponíveis" valor={f.saldo} unidade="dias" corValor={f.saldo < 0 ? '#b91c1c' : undefined} />
        </GrelhaKpi>
        {f.periodo
          ? <Alert type="info" showIcon title={`Período de férias de ${r.ano}: ${formatarData(f.periodo.data_inicio)} a ${formatarData(f.periodo.data_fim)}. O pedido segue para a chefia e depois para o RH.`} />
          : <Alert type="warning" showIcon title={`Ainda não foi definido o período de férias de ${r.ano}. Peça ao administrador para o configurar antes de pedir férias.`} />}
      </Card>
      <FeriasDaEquipa anoInicial={r.ano} />
    </>
  );
}

interface LinhaMembro {
  colaborador_id: number;
  nome: string;
  unidade: string | null;
  direito: number | null;
  marcados: number;
  saldo: number | null;
  periodos: PeriodoFeriasEquipa[];
}

/** «A minha equipa» — férias do ano da equipa directa; só aparece a quem tem equipa (GET equipa devolve a lista vazia aos outros). */
export function FeriasDaEquipa({ anoInicial }: { anoInicial: number }) {
  const [ano, setAno] = useState(anoInicial);
  const equipa = useQuery({ queryKey: ['rh', 'portal', 'equipa'], queryFn: equipaDirecta, retry: false });
  const q = useQuery({ queryKey: ['rh', 'portal', 'equipa', 'ferias', ano], queryFn: () => feriasDaEquipa(ano), enabled: Array.isArray(equipa.data) && equipa.data.length > 0, retry: false });
  const membros = Array.isArray(equipa.data) ? equipa.data : [];
  if (equipa.isError || !membros.length) return null;
  const ferias = Array.isArray(q.data?.ferias) ? q.data.ferias : [];
  const linhas: LinhaMembro[] = membros.map((m) => {
    const doAno = ano === m.ferias?.ano;
    return {
      colaborador_id: m.id, nome: m.nome_completo, unidade: m.unidade,
      direito: doAno ? m.ferias.direito : null, marcados: doAno ? m.ferias.marcados : ferias.filter((p) => p.colaborador_id === m.id).reduce((s, p) => s + Number(p.dias || 0), 0), saldo: doAno ? m.ferias.saldo : null,
      periodos: ferias.filter((p) => p.colaborador_id === m.id),
    };
  });
  const anos = [anoInicial + 1, anoInicial, anoInicial - 1, anoInicial - 2].map((a) => ({ value: a, label: String(a) }));
  return (
    <Card title={<Space><IconeFa nome="users" />A minha equipa — férias de {ano}</Space>} style={{ marginTop: 16 }}
      extra={<Select<number> value={ano} onChange={setAno} options={anos} style={{ width: 100 }} aria-label="Ano" />}>
      <Typography.Paragraph type="secondary">Férias planeadas, pedidas, aprovadas e gozadas da sua equipa directa (as canceladas não aparecem). Os pedidos decidem-se em «Aprovações».</Typography.Paragraph>
      <Table<LinhaMembro> rowKey="colaborador_id" size="small" loading={q.isFetching} dataSource={linhas} pagination={false} scroll={scrollTabela()}
        columns={[
          { title: 'Colaborador', dataIndex: 'nome', render: (v: string, m) => <><b>{v}</b>{m.unidade && <div style={{ fontSize: '0.74rem', color: '#64748b' }}>{m.unidade}</div>}</> },
          { title: 'Direito', dataIndex: 'direito', align: 'right', render: (v: number | null) => v ?? '—' },
          { title: 'Marcados', dataIndex: 'marcados', align: 'right' },
          { title: 'Saldo', dataIndex: 'saldo', align: 'right', render: (s: number | null) => (s == null ? '—' : <span style={s < 0 ? { color: '#dc2626', fontWeight: 600 } : undefined}>{s}</span>) },
          {
            title: `Períodos ${ano}`, key: 'p',
            render: (_, m) => m.periodos.length ? (
              <Space size={4} wrap>
                {m.periodos.map((p) => <Tag key={p.id} title={p.estado}>{dayjs(p.data_inicio).format('DD/MM')} – {dayjs(p.data_fim).format('DD/MM')} · {p.dias} d <EstadoTag estado={p.estado} /></Tag>)}
              </Space>
            ) : <small style={{ color: '#94a3b8' }}>Sem períodos</small>,
          },
        ]} />
    </Card>
  );
}
