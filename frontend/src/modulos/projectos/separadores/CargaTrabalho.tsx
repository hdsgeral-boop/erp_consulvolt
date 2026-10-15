import { Alert, Button, Collapse, Empty, Flex, Modal, Space, Spin, Table, Tag, Typography } from 'antd';
import { useQuery } from '@tanstack/react-query';
import { useRef, useState } from 'react';
import { obter } from '@/api/cliente';
import { BotaoImprimir, BotoesExportar, esc } from '@/componentes/impressao';
import { larguraModal, scrollTabela } from '@/componentes/responsivo';
import { formatarData } from '@/utilitarios/formatacao';
import { EtiquetaProjectos } from '../comum/componentes';
import { descricaoCalendario, formatarQtd } from '../comum/regrasMetas';
import type { CargaTrabalho, GrupoCarga, TarefaCarga } from '../comum/tipos';

/**
 * Carga de trabalho da equipa (Versão Actual, renderCargaTrabalho, ui_projects.js:2683-2934): por colaborador ou
 * entidade, as tarefas que lhe cabem com a quantidade atribuída, as horas previstas pelo calendário e as lançadas; uma
 * tarefa de uma área do organigrama sem responsável directo reparte-se pelos membros da área. A ordem de trabalho
 * (cargaOrdemTrabalho, :2935-3063) imprime uma folha por pessoa com o que há para fazer, prazos, quantidade e ritmo.
 */
export function ModalCargaTrabalho({ projectoId, aberto, aoFechar }: { projectoId: number; aberto: boolean; aoFechar: () => void }) {
  const q = useQuery({ queryKey: ['projectos', 'carga-trabalho', projectoId], queryFn: () => obter<CargaTrabalho>(`/projetos/${projectoId}/carga-trabalho`), enabled: aberto });
  const [abertos, setAbertos] = useState<string[] | null>(null);
  const ref = useRef<HTMLDivElement>(null);
  const c = q.data;
  const chaves = (c?.grupos ?? []).filter((g) => g.tarefas.length).map((g) => g.chave);

  return (
    <Modal title="Carga de trabalho da equipa" open={aberto} onCancel={aoFechar} footer={null} width={larguraModal(1100)} destroyOnHidden>
      {q.isLoading || !c ? <Spin style={{ display: 'block', margin: 32 }} /> : (
        <>
          <Flex gap={8} wrap justify="space-between" style={{ marginBottom: 12 }} className="imp-nao-imprimir">
            <Typography.Text type="secondary">Calendário: {descricaoCalendario(c.calendario)}. Uma tarefa conta para um só responsável; a de uma área reparte-se pelas horas/dia alocadas.</Typography.Text>
            <Space wrap>
              <Button size="small" onClick={() => setAbertos(chaves)}>Abrir tudo</Button>
              <Button size="small" onClick={() => setAbertos([])}>Fechar tudo</Button>
              <BotaoImprimir texto="Ordem de trabalho" tamanho="small" obterPedido={() => ({ titulo: `Ordem de trabalho — ${c.projeto.codigo ?? ''} ${c.projeto.nome}`, conteudo: ordemDeTrabalhoHtml(c), orientacao: 'retrato' as const })} />
              <BotoesExportar tamanho="small" obterPedido={() => (ref.current ? { titulo: `Carga de trabalho — ${c.projeto.codigo ?? ''} ${c.projeto.nome}`, conteudo: ref.current, orientacao: 'paisagem' as const } : null)} />
            </Space>
          </Flex>
          {c.areas_sem_gente.length > 0 && <Alert type="warning" showIcon style={{ marginBottom: 12 }} message={`Áreas do organigrama com tarefas e sem ninguém lá colocado: ${c.areas_sem_gente.join(', ')}.`} />}
          <div ref={ref}>
            {!c.grupos.length ? <Empty description="A equipa do projecto está vazia." /> : (
              <Collapse activeKey={abertos ?? chaves} onChange={(k) => setAbertos(k as string[])}
                items={c.grupos.map((g) => ({ key: g.chave, label: <CabecalhoGrupo g={g} />, children: <TabelaCarga tarefas={g.tarefas} /> }))} />
            )}
          </div>
        </>
      )}
    </Modal>
  );
}

function CabecalhoGrupo({ g }: { g: GrupoCarga }) {
  return (
    <Flex gap={8} wrap align="center">
      <strong>{g.nome}</strong>
      {g.tipo && <EtiquetaProjectos valor={g.tipo} />}
      {g.papel && <Typography.Text type="secondary">{g.papel}</Typography.Text>}
      <Tag>{g.n_tarefas} tarefa(s)</Tag>
      <Tag color="blue">{formatarQtd(g.horas_previstas)} h previstas</Tag>
      <Tag color={g.horas_lancadas > g.horas_previstas && g.horas_previstas > 0 ? 'red' : 'green'}>{formatarQtd(g.horas_lancadas)} h lançadas</Tag>
      {Object.entries(g.quantidades).map(([u, v]) => <Tag key={u} color="purple">{formatarQtd(v)} {u}</Tag>)}
    </Flex>
  );
}

function TabelaCarga({ tarefas }: { tarefas: TarefaCarga[] }) {
  return (
    <Table size="small" rowKey="tarefa_id" pagination={false} dataSource={tarefas} scroll={scrollTabela()} locale={{ emptyText: 'Sem tarefas atribuídas.' }}
      columns={[
        { title: 'Tarefa', key: 't', render: (_, t) => (
          <div style={{ paddingLeft: t.nivel * 12 }}>
            {t.caminho.length > 0 && <Typography.Text type="secondary" style={{ display: 'block', fontSize: 11 }}>{t.caminho.join(' › ')}</Typography.Text>}
            {t.codigo && <Typography.Text type="secondary">{t.codigo} </Typography.Text>}{t.nome}
            {t.da_area && <Tag style={{ marginLeft: 4 }} color="cyan">da área{t.quota < 1 ? ` · ${Math.round(t.quota * 100)}%` : ''}</Tag>}
          </div>
        ) },
        { title: 'Fase', dataIndex: 'fase', render: (v) => v ?? '—' },
        { title: 'Prazo', key: 'p', render: (_, t) => (t.inicio ? `${formatarData(t.inicio)} a ${formatarData(t.fim)}` : '—') },
        { title: 'Quantidade', key: 'q', align: 'right', render: (_, t) => (t.meta ? `${formatarQtd(t.meta.realizado)} / ${formatarQtd(t.meta.quantidade)} ${t.meta.unidade}` : '—') },
        { title: 'Ritmo', key: 'r', align: 'right', render: (_, t) => (t.ritmo ? `${formatarQtd(t.ritmo.dia)} ${t.ritmo.unidade}/dia` : '—') },
        { title: 'Horas prev.', dataIndex: 'horas_previstas', align: 'right', render: (v) => formatarQtd(v) },
        { title: 'Horas lanç.', dataIndex: 'horas_lancadas', align: 'right', render: (v) => formatarQtd(v) },
        { title: 'Estado', dataIndex: 'estado', render: (v) => <EtiquetaProjectos valor={v} /> },
      ]} />
  );
}

/** Ordem de trabalho: uma folha por pessoa, agrupada por fase, com prazos, quantidade, ritmo e assinaturas (cargaOrdemTrabalho, :2935-3063). */
export function ordemDeTrabalhoHtml(c: CargaTrabalho): string {
  const folhas = c.grupos.filter((g) => g.tarefas.length).map((g, n) => {
    const porFase = new Map<string, TarefaCarga[]>();
    g.tarefas.forEach((t) => { const k = t.fase ?? 'Sem fase'; porFase.set(k, [...(porFase.get(k) ?? []), t]); });
    const corpo = [...porFase.entries()].map(([fase, ts]) => `
      <tr><td colspan="5" style="background:#f1f5f9;font-weight:600;padding:4px 6px">${esc(fase)}</td></tr>
      ${ts.map((t) => `<tr>
        <td style="padding:3px 6px">${esc(t.caminho.length ? `${t.caminho.join(' › ')} › ` : '')}<strong>${esc(t.codigo ? `${t.codigo} ` : '')}${esc(t.nome)}</strong>${t.da_area && t.quota < 1 ? ` <small>(parte de ${Math.round(t.quota * 100)}%)</small>` : ''}</td>
        <td style="padding:3px 6px;white-space:nowrap">${t.inicio ? `${esc(formatarData(t.inicio))} a ${esc(formatarData(t.fim))}` : '—'}</td>
        <td style="padding:3px 6px;text-align:right;white-space:nowrap">${t.meta ? `${esc(formatarQtd(t.meta.quantidade - t.meta.realizado))} ${esc(t.meta.unidade)}` : '—'}</td>
        <td style="padding:3px 6px;text-align:right;white-space:nowrap">${t.ritmo ? `${esc(formatarQtd(t.ritmo.dia))} ${esc(t.ritmo.unidade)}/dia` : '—'}</td>
        <td style="padding:3px 6px;text-align:right">${esc(formatarQtd(t.horas_previstas))} h</td></tr>`).join('')}`).join('');
    return `<section style="${n > 0 ? 'page-break-before:always;' : ''}">
      <h3 style="margin:0 0 4px">${esc(g.nome)}${g.papel ? ` — ${esc(g.papel)}` : ''}</h3>
      <p style="margin:0 0 8px;color:#475569">${g.n_tarefas} tarefa(s) · ${esc(formatarQtd(g.horas_previstas))} h previstas · calendário: ${esc(descricaoCalendario(c.calendario))}</p>
      <table style="width:100%;border-collapse:collapse;font-size:12px" border="1" cellspacing="0">
        <thead><tr><th style="text-align:left;padding:4px 6px">Tarefa</th><th>Prazo</th><th>Falta fazer</th><th>Ritmo</th><th>Horas</th></tr></thead>
        <tbody>${corpo}</tbody>
      </table>
      <table style="width:100%;margin-top:32px;font-size:12px"><tr>
        <td style="width:50%;padding-top:28px;border-top:1px solid #000;text-align:center">O responsável pela obra</td><td style="width:8%"></td>
        <td style="width:42%;padding-top:28px;border-top:1px solid #000;text-align:center">Recebi — ${esc(g.nome)}</td></tr></table>
    </section>`;
  });
  return folhas.join('') || '<p>Sem tarefas atribuídas.</p>';
}
