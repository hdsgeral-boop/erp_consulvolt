import { Alert, DatePicker, Empty, Flex, Modal, Segmented, Select, Space, Spin, Switch, Typography, theme } from 'antd';
import { useQuery } from '@tanstack/react-query';
import dayjs from 'dayjs';
import { useMemo, useRef, useState } from 'react';
import { obter } from '@/api/cliente';
import { BotoesExportar } from '@/componentes/impressao';
import { larguraModal } from '@/componentes/responsivo';
import { formatarKz } from '@/utilitarios/formatacao';
import { acumular, descricaoCalendario, ESCALAS, formatarQtd, type Escala } from '../../comum/regrasMetas';
import type { LinhaMapa, MapaQuantidades as Mapa, TabelaMapa } from '../../comum/tipos';

/**
 * Mapa de quantidades (Versão Actual, ui_projects.js:925-1477): uma tabela por unidade com fase › tarefa › subtarefa nas
 * linhas e os períodos da escala nas colunas; previsto (meta repartida pelo calendário) e executado (medições), com as
 * vistas Horas (previstas × lançadas) e Valor (orçamento mapeado pelas quantidades), modo acumulado, níveis e impressão.
 * A matriz vem calculada do servidor (GET /projetos/{id}/mapa-quantidades).
 */
export function ModalMapaQuantidades({ projectoId, projectoNome, aberto, aoFechar }: { projectoId: number; projectoNome: string; aberto: boolean; aoFechar: () => void }) {
  const { token } = theme.useToken();
  const [escala, setEscala] = useState<Escala>('mes');
  const [periodo, setPeriodo] = useState<[dayjs.Dayjs | null, dayjs.Dayjs | null] | null>(null);
  const [vista, setVista] = useState<'quantidades' | 'horas' | 'valor'>('quantidades');
  const [acumulado, setAcumulado] = useState(false);
  const [nivel, setNivel] = useState(9);
  const ref = useRef<HTMLDivElement>(null);
  const q = useQuery({
    queryKey: ['projectos', 'mapa-quantidades', projectoId, escala, periodo?.[0]?.format('YYYY-MM-DD'), periodo?.[1]?.format('YYYY-MM-DD')],
    queryFn: () => obter<Mapa>(`/projetos/${projectoId}/mapa-quantidades`, { escala, de: periodo?.[0]?.format('YYYY-MM-DD'), ate: periodo?.[1]?.format('YYYY-MM-DD') }),
    enabled: aberto,
    retry: false,
  });
  const m = q.data;
  const tabelas: { titulo: string; tab: TabelaMapa; unidade: string; dinheiro?: boolean; rotulos: [string, string] }[] = useMemo(() => {
    if (!m) return [];
    if (vista === 'horas') return [{ titulo: `Horas — previstas pelo calendário × lançadas nas folhas${m.horas.totais.rendimento_pct != null ? ` · rendimento ${m.horas.totais.rendimento_pct}%` : ''}`, tab: m.horas, unidade: 'h', rotulos: ['Previstas', 'Lançadas'] }];
    if (vista === 'valor') return [{ titulo: `Valor — orçamento mapeado pelas quantidades (${formatarKz(m.valor.total_mapeado, true)})`, tab: m.valor, unidade: 'Kz', dinheiro: true, rotulos: ['Previsto', 'Executado'] }];
    return m.quantidades.map((t) => ({ titulo: `${t.unidade} — meta ${formatarQtd(t.meta)} ${t.unidade}`, tab: t, unidade: t.unidade, rotulos: ['Previsto', 'Executado'] as [string, string] }));
  }, [m, vista]);

  const fmt = (v: number, dinheiro?: boolean) => (v ? (dinheiro ? formatarKz(v) : formatarQtd(v)) : '');
  const serie = (vs: number[]) => (acumulado ? acumular(vs) : vs);
  const celula: React.CSSProperties = { border: `1px solid ${token.colorBorderSecondary}`, padding: '2px 6px', textAlign: 'right', whiteSpace: 'nowrap', fontSize: 12 };
  const linhaHtml = (nome: string, tab: TabelaMapa['totais'] | LinhaMapa, dinheiro: boolean | undefined, rotulos: [string, string], forte: boolean, nivelLinha: number) => (
    <>
      <tr style={{ background: forte ? token.colorFillAlter : undefined, fontWeight: forte ? 600 : undefined }}>
        <td rowSpan={2} style={{ ...celula, textAlign: 'left', paddingLeft: 6 + nivelLinha * 14, minWidth: 220, whiteSpace: 'normal' }}>{nome}</td>
        <td style={{ ...celula, textAlign: 'left', color: token.colorTextSecondary }}>{rotulos[0]}</td>
        {serie(tab.previsto).map((v, i) => <td key={i} style={celula}>{fmt(v, dinheiro)}</td>)}
        <td style={{ ...celula, fontWeight: 600 }}>{fmt(tab.total_previsto, dinheiro)}</td>
      </tr>
      <tr style={{ background: forte ? token.colorFillAlter : undefined }}>
        <td style={{ ...celula, textAlign: 'left', color: token.colorSuccess }}>{rotulos[1]}</td>
        {serie(tab.realizado).map((v, i) => <td key={i} style={{ ...celula, color: token.colorSuccess }}>{fmt(v, dinheiro)}</td>)}
        <td style={{ ...celula, fontWeight: 600, color: token.colorSuccess }}>{fmt(tab.total_realizado, dinheiro)}</td>
      </tr>
    </>
  );

  return (
    <Modal title="Mapa de quantidades" open={aberto} onCancel={aoFechar} footer={null} width={larguraModal(1280)} destroyOnHidden>
      <Flex gap={8} wrap align="center" style={{ marginBottom: 12 }} className="imp-nao-imprimir">
        <Segmented value={vista} onChange={(v) => setVista(v as typeof vista)} options={[{ value: 'quantidades', label: 'Quantidades' }, { value: 'horas', label: 'Horas' }, { value: 'valor', label: 'Valor' }]} />
        <Select value={escala} onChange={setEscala} options={ESCALAS} style={{ width: 130 }} aria-label="Escala" />
        <DatePicker.RangePicker format="DD/MM/YYYY" value={periodo} onChange={(v) => setPeriodo(v)} allowEmpty={[true, true]} placeholder={['Desde', 'Até']} />
        <Select value={nivel} onChange={setNivel} style={{ width: 170 }} aria-label="Níveis" options={[{ value: 0, label: 'Só fases' }, { value: 1, label: 'Fases e tarefas' }, { value: 9, label: 'Tudo aberto' }]} />
        <Space><Switch checked={acumulado} onChange={setAcumulado} size="small" /> Acumulado</Space>
        <BotoesExportar tamanho="small" desactivado={!m} obterPedido={() => (ref.current ? { titulo: `Mapa de quantidades — ${projectoNome}`, conteudo: ref.current, orientacao: 'paisagem' as const,
          filtros: [`Escala: ${ESCALAS.find((e) => e.value === escala)?.label}`, m ? `${m.de ?? '—'} a ${m.ate ?? '—'}` : '', acumulado ? 'Acumulado' : ''].filter(Boolean) } : null)} />
      </Flex>
      {m && <Typography.Paragraph type="secondary" style={{ fontSize: 12 }}>Repartição linear pelos dias de trabalho do calendário do projecto ({descricaoCalendario(m.calendario)}); não conhece feriados nem turnos.</Typography.Paragraph>}
      {q.isLoading ? <Spin style={{ display: 'block', margin: 32 }} /> : q.error ? <Alert type="error" showIcon message={(q.error as Error).message} /> : (
        <div ref={ref}>
          {!tabelas.length || !m?.colunas.length ? <Empty description={vista === 'valor' ? 'Nenhuma rubrica do orçamento está mapeada pelas quantidades.' : 'Sem tarefas com meta e prazo nesta janela.'} /> : tabelas.map((t) => (
            <div key={t.titulo} className="erp-deslocar-x" style={{ marginBottom: 16 }}>
              <Typography.Title level={5} style={{ margin: '4px 0 8px' }}>{t.titulo}</Typography.Title>
              <table style={{ borderCollapse: 'collapse', minWidth: '100%' }}>
                <thead className="imp-repetir">
                  <tr style={{ background: token.colorFillAlter }}>
                    <th style={{ ...celula, textAlign: 'left' }}>Fase › tarefa</th><th style={celula} />
                    {m.colunas.map((c) => <th key={c.de} title={c.titulo} style={{ ...celula, textAlign: 'center', color: c.fds ? token.colorTextTertiary : undefined }}>{c.rotulo}</th>)}
                    <th style={celula}>Total</th>
                  </tr>
                </thead>
                <tbody>
                  {t.tab.linhas.filter((l) => l.nivel <= nivel).map((l) => <FragmentoLinha key={l.chave}>{linhaHtml(l.nome, l, t.dinheiro, t.rotulos, l.tipo === 'fase', l.nivel)}</FragmentoLinha>)}
                  {linhaHtml('Total', t.tab.totais, t.dinheiro, t.rotulos, true, 0)}
                </tbody>
              </table>
            </div>
          ))}
        </div>
      )}
    </Modal>
  );
}

function FragmentoLinha({ children }: { children: React.ReactNode }) {
  return <>{children}</>;
}
