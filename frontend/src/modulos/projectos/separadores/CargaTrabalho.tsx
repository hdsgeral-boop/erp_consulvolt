import { Alert, Button, Collapse, Empty, Flex, Modal, Space, Spin, Table, Tag, Typography } from 'antd';
import { useQuery } from '@tanstack/react-query';
import { useRef, useState } from 'react';
import { obter } from '@/api/cliente';
import { BotaoImprimir, BotoesExportar, esc } from '@/componentes/impressao';
import { larguraModal, scrollTabela } from '@/componentes/responsivo';
import { formatarData } from '@/utilitarios/formatacao';
import { EtiquetaProjectos, rotuloProjectos } from '../comum/componentes';
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
              <BotaoImprimir texto="Ordens de trabalho" tamanho="small" chave="projectos.ordem-trabalho" obterPedido={() => pedidoOrdemTrabalho(c)} />
              <BotoesExportar tamanho="small" obterPedido={() => (ref.current ? { titulo: `Carga de trabalho — ${c.projeto.codigo ?? ''} ${c.projeto.nome}`, conteudo: ref.current, orientacao: 'paisagem' as const } : null)} />
            </Space>
          </Flex>
          {c.areas_sem_gente.length > 0 && <Alert type="warning" showIcon style={{ marginBottom: 12 }} message={`Áreas do organigrama com tarefas e sem ninguém lá colocado: ${c.areas_sem_gente.join(', ')}.`} />}
          <div ref={ref}>
            {!c.grupos.length ? <Empty description="A equipa do projecto está vazia." /> : (
              <Collapse activeKey={abertos ?? chaves} onChange={(k) => setAbertos(k as string[])}
                items={c.grupos.map((g) => ({
                  key: g.chave,
                  label: <CabecalhoGrupo g={g} />,
                  // Versão Actual (ui_projects.js:2888-2889): a ordem de trabalho só desta pessoa, a partir da sua linha
                  extra: g.tarefas.length > 0 && (
                    <span className="imp-nao-imprimir" onClick={(e) => e.stopPropagation()} onKeyDown={(e) => e.stopPropagation()} role="presentation">
                      <BotaoImprimir texto="Ordem de trabalho" tamanho="small" chave="projectos.ordem-trabalho" obterPedido={() => pedidoOrdemTrabalho(c, g.chave)} />
                    </span>
                  ),
                  children: <TabelaCarga tarefas={g.tarefas} />,
                }))} />
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

/**
 * Ordem de trabalho (cargaOrdemTrabalho, ui_projects.js:2935-3063): uma folha por pessoa, agrupada por fase, com a
 * identificação do executante, prazos, o que falta da meta, o ritmo exigido, observações e assinaturas. Com `chave`
 * imprime só a dessa pessoa (botão «Ordem de trabalho» de cada linha, :2888-2889); sem `chave`, todas (botão da barra).
 */
export function ordemDeTrabalhoHtml(c: CargaTrabalho, chave?: string): string {
  const grupos = c.grupos.filter((g) => g.tarefas.length && (!chave || g.chave === chave));
  const posicao = (g: GrupoCarga) => (!g.posicao ? '—' : typeof g.posicao === 'string' ? g.posicao : g.posicao.titulo);
  const th = 'text-align:left;background:#f1f1f1;padding:3px 6px';
  const folhas = grupos.map((g, n) => {
    const porFase = new Map<string, TarefaCarga[]>();
    g.tarefas.forEach((t) => { const k = t.fase ?? 'Sem fase'; porFase.set(k, [...(porFase.get(k) ?? []), t]); });
    const inicios = g.tarefas.map((t) => t.inicio).filter((x): x is string => !!x).sort();
    const fins = g.tarefas.map((t) => t.fim).filter((x): x is string => !!x).sort();
    const qtd = Object.entries(g.quantidades).map(([u, v]) => `${esc(formatarQtd(v))} ${esc(u)}`).join(' · ');
    const corpo = [...porFase.entries()].map(([fase, ts]) => `
      <tr><td colspan="6" style="background:#e8e8e8;font-weight:700;padding:4px 6px;font-size:10px;letter-spacing:.5px">FASE: ${esc(fase)}</td></tr>
      ${ts.map((t) => `<tr>
        <td style="padding:3px 6px;vertical-align:top">${esc(t.caminho.length ? `${t.caminho.join(' › ')} › ` : '')}<strong>${esc(t.codigo ? `${t.codigo} ` : '')}${esc(t.nome)}</strong>${t.da_area && t.quota < 1 ? ` <small>(parte de ${Math.round(t.quota * 100)}%)</small>` : ''}${t.da_area && t.areas.length ? `<div style="font-size:9px;color:#555">Atribuída à área ${esc(t.areas.join(', '))}</div>` : ''}</td>
        <td style="padding:3px 6px;white-space:nowrap;vertical-align:top">${t.inicio ? `${esc(formatarData(t.inicio))} a ${esc(formatarData(t.fim))}` : '—'}</td>
        <td style="padding:3px 6px;text-align:right;white-space:nowrap;vertical-align:top">${t.meta ? `${esc(formatarQtd(t.meta.quantidade - t.meta.realizado))} ${esc(t.meta.unidade)}<div style="font-size:9px;color:#555">meta ${esc(formatarQtd(t.meta.quantidade))} · feitos ${esc(formatarQtd(t.meta.realizado))}</div>` : '—'}</td>
        <td style="padding:3px 6px;text-align:right;white-space:nowrap;vertical-align:top">${t.ritmo ? `${esc(formatarQtd(t.ritmo.dia))} ${esc(t.ritmo.unidade)}/dia` : '—'}</td>
        <td style="padding:3px 6px;text-align:right;vertical-align:top">${esc(formatarQtd(t.horas_previstas))} h</td>
        <td style="padding:3px 6px;text-align:center;vertical-align:top">${esc(rotuloProjectos(t.estado))}<div style="font-size:9px;color:#555">${t.execucao}%</div></td></tr>`).join('')}`).join('');
    return `<section style="${n > 0 ? 'page-break-before:always;' : ''}">
      <h3 style="margin:0 0 4px">${esc(g.nome)}${g.papel ? ` — ${esc(g.papel)}` : ''}</h3>
      <table style="width:100%;border-collapse:collapse;font-size:11px;margin:4px 0 10px" border="1" cellspacing="0">
        <tr><th style="${th};width:14%">Executante</th><td style="padding:3px 6px"><strong>${esc(g.nome)}</strong>${g.papel ? ` — ${esc(g.papel)}` : ''}</td>
          <th style="${th};width:14%">Posição</th><td style="padding:3px 6px">${esc(posicao(g))}</td></tr>
        <tr><th style="${th}">Período</th><td style="padding:3px 6px">${inicios.length ? `${esc(formatarData(inicios[0]))} a ${esc(formatarData(fins[fins.length - 1] ?? null))}` : '—'}</td>
          <th style="${th}">Horário</th><td style="padding:3px 6px">${esc(descricaoCalendario(c.calendario))}</td></tr>
        <tr><th style="${th}">Trabalho</th><td style="padding:3px 6px">${g.n_tarefas} tarefa(s)</td>
          <th style="${th}">Horas previstas</th><td style="padding:3px 6px">${esc(formatarQtd(g.horas_previstas))} h${g.horas_lancadas ? ` (${esc(formatarQtd(g.horas_lancadas))} h já lançadas)` : ''}</td></tr>
        ${qtd ? `<tr><th style="${th}">Quantidades</th><td colspan="3" style="padding:3px 6px">${qtd}</td></tr>` : ''}
      </table>
      <table style="width:100%;border-collapse:collapse;font-size:11px" border="1" cellspacing="0">
        <thead><tr><th style="text-align:left;padding:4px 6px">O que há para fazer</th><th>Prazo</th><th>Falta fazer</th><th>Ritmo exigido</th><th>Horas</th><th>Estado</th></tr></thead>
        <tbody>${corpo}</tbody>
      </table>
      <div style="margin-top:10px;border:1px solid #bbb;padding:5px 6px;font-size:11px"><strong>Observações / ocorrências:</strong><div style="height:52px"></div></div>
      <table style="width:100%;margin-top:28px;font-size:11px"><tr>
        <td style="width:32%;padding-top:26px;border-top:1px solid #000;text-align:center">Entregue por (nome e assinatura)</td><td style="width:2%"></td>
        <td style="width:32%;padding-top:26px;border-top:1px solid #000;text-align:center">Recebido por — ${esc(g.nome)}</td><td style="width:2%"></td>
        <td style="width:32%;padding-top:26px;border-top:1px solid #000;text-align:center">Data ____ / ____ / ________</td></tr></table>
    </section>`;
  });
  return folhas.join('') || '<p>Sem tarefas atribuídas.</p>';
}

/** Pedido de impressão da ordem de trabalho (todas as pessoas, ou só a da `chave`). */
export function pedidoOrdemTrabalho(c: CargaTrabalho, chave?: string) {
  const g = chave ? c.grupos.find((x) => x.chave === chave) : undefined;
  return {
    titulo: `Ordem de trabalho${g ? ` — ${g.nome}` : ''} — ${c.projeto.codigo ?? ''} ${c.projeto.nome}`.replace(/\s+/g, ' ').trim(),
    conteudo: ordemDeTrabalhoHtml(c, chave),
    orientacao: 'retrato' as const,
  };
}
