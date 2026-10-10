import { Card, Space, Switch, Typography } from 'antd';
import { useState, type ReactNode } from 'react';
import { useNavigate } from 'react-router-dom';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { IconeFa } from '@/componentes/fluxos';
import { scrollTabela } from '@/componentes/responsivo';
import type { ColunaApi } from '@/componentes/TabelaApi';
import { TabelaComModos } from '@/componentes/vistas';
import type { PeriodoSalarial } from '../api';
import { useAvisarErro, usePeriodosSalariais } from '../comum/consultas';
import { pedidoTabela } from '../comum/impressao';

/** Períodos que o antigo mostra em Processamentos (renderProcessamento): os encerrados (FECHADO) e os validados. */
export function periodosProcessados(periodos: PeriodoSalarial[], todos: boolean): PeriodoSalarial[] {
  return todos ? periodos : periodos.filter((p) => p.estado === 'FECHADO' || p.estado === 'VALIDADO');
}

/** Estado actual como a pílula do antigo (verde «VALIDADO» com ✓✓, amarela com cadeado nos restantes). */
export function PilulaEstadoPeriodo({ estado }: { estado: string }) {
  const validado = estado === 'VALIDADO';
  const aberto = estado === 'ABERTO';
  return (
    <span style={{ background: validado ? '#dcfce7' : aberto ? '#e0f2fe' : '#fef9c3', color: validado ? '#166534' : aberto ? '#075985' : '#854d0e', padding: '2px 10px', borderRadius: 10, fontSize: '0.78rem', fontWeight: 600, whiteSpace: 'nowrap' }}>
      <IconeFa nome={validado ? 'check-double' : aberto ? 'unlock' : 'lock'} /> {estado}
    </span>
  );
}

/** «Processamentos (Validação e Consulta)» › «Histórico de Períodos Processados». */
export function ListaProcessamentos({ accoesExtra }: { accoesExtra?: ReactNode }) {
  const navegar = useNavigate();
  const periodos = usePeriodosSalariais();
  useAvisarErro(periodos.error);
  const [todos, setTodos] = useState(false);
  const linhas = periodosProcessados(periodos.data ?? [], todos);

  const colunas: ColunaApi<PeriodoSalarial>[] = [
    { title: 'Período de Processamento', dataIndex: 'mes_ano', render: (v: string) => <strong style={{ fontSize: '1.1rem' }}>{v}</strong> },
    { title: 'Estado Atual', dataIndex: 'estado', align: 'center', render: (e: string) => <PilulaEstadoPeriodo estado={e} />, valorImpressao: (p) => p.estado },
    {
      title: 'Contabilizado', dataIndex: 'contabilizado', align: 'center',
      valorImpressao: (p) => (p.contabilizado ? `Contabilizado${p.numero_lan_contabilizacao ? ` (${p.numero_lan_contabilizacao})` : ''}` : 'Pendente'),
      valorFiltro: (p) => (p.contabilizado ? 'Contabilizado' : 'Pendente'),
      render: (_: unknown, p) => (p.contabilizado
        ? <span style={{ color: '#2563eb', background: '#eff6ff', border: '1px solid #dbeafe', borderRadius: 6, padding: '2px 8px', whiteSpace: 'nowrap' }} title={p.numero_lan_contabilizacao ?? undefined}><IconeFa nome="book" /> Contabilizado</span>
        : <span style={{ color: '#94a3b8' }}><IconeFa nome="minus-circle" /> Pendente</span>),
    },
  ];

  return (
    <>
      <CabecalhoPagina
        titulo="Processamentos (Validação e Consulta)"
        subtitulo="Validação, contabilização e pagamento dos processamentos salariais"
        accoes={accoesExtra}
        impressaoDesactivada={!linhas.length}
        impressao={() => pedidoTabela({ titulo: 'Histórico de Períodos Processados', colunas, linhas })}
      />
      <Card title="Histórico de Períodos Processados"
        extra={<Space><Switch size="small" checked={todos} onChange={setTodos} aria-label="Mostrar também os períodos em aberto" /><Typography.Text type="secondary">Mostrar também os períodos em aberto</Typography.Text></Space>}>
        <TabelaComModos<PeriodoSalarial> rowKey="id" size="middle" scroll={scrollTabela()} loading={periodos.isFetching} columns={colunas} dataSource={linhas} modos={false}
          vazio={{ icone: 'file-invoice-dollar', titulo: 'Nenhum processamento encerrado ainda', texto: 'Use o menu «Calcular» para fechar um mês.' }}
          accoes={(p) => [{ icone: 'search-plus', titulo: 'Aceder Detalhes', texto: 'Aceder Detalhes', variante: 'primario', cheio: true, onClick: () => navegar(String(p.id)) }]}
          onRow={(r) => ({ onClick: () => navegar(String(r.id)), style: { cursor: 'pointer' } })} />
      </Card>
    </>
  );
}
