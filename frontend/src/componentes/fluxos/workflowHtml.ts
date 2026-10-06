import { ESTADOS_FLUXO, estadoDe } from './estados';
import type { EtapaDiagrama, NarrativaDaEtapa } from './ComponentesFluxo';

/**
 * «Imprimir workflow» (FluxoNarrativa.imprimir, js/fluxo_narrativa.js:278-340) para o motor comum de impressão: objectivo,
 * intervenientes, diagrama em caixas numeradas com setas «▶» (traço azul no topo; a etapa seleccionada com contorno âmbar),
 * estado e resumo de cada etapa do processo escolhido, indicadores actuais, tabela da narrativa por etapa e, se houver, os
 * factos e pendências da etapa seleccionada. O cabeçalho da empresa, a data e as páginas são postos pelo motor.
 */

export interface DadosWorkflow {
  narrativa: { titulo: string; objectivo: string; intervenientes: string[]; etapas: NarrativaDaEtapa[] } | null;
  etapas: EtapaDiagrama[];
  /** estado e resumo por etapa do processo escolhido (sem processo: só o workflow) */
  estados?: Record<string, { estado: string; resumo?: string | null } | undefined>;
  processo?: string | null;
  etapaSeleccionada?: string | null;
  kpis?: { rotulo: string; texto: string }[];
  factos?: { rotulo: string; texto: string }[];
  pendencias?: { nivel: string; texto: string }[];
}

const esc = (s: unknown) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]!);

export const CSS_WORKFLOW = `
.wf{font-size:9.5pt;color:#0f172a;-webkit-print-color-adjust:exact;print-color-adjust:exact}
.wf h2{font-size:11pt;margin:12px 0 6px;padding-bottom:3px;border-bottom:1px solid #94a3b8}
.wf .obj{font-size:10pt;line-height:1.5;margin:0 0 4px}
.wf .meta{font-size:8.5pt;color:#475569}
.wf .diag{display:flex;align-items:stretch;gap:4px;margin:8px 0 4px;break-inside:avoid}
.wf .no{flex:1;border:1px solid #94a3b8;border-top:4px solid #1d4ed8;border-radius:4px;padding:6px 7px;min-width:0}
.wf .no.sel{box-shadow:0 0 0 2px #f59e0b}
.wf .num{font-size:8pt;font-weight:700;color:#1d4ed8}
.wf .nome{font-weight:700;font-size:9.5pt;line-height:1.2;margin:1px 0 2px}
.wf .quem{font-size:7.5pt;color:#475569}
.wf .est{display:inline-block;margin-top:4px;font-size:7.5pt;font-weight:700;border-radius:3px;padding:1px 5px}
.wf .res{font-size:7.5pt;color:#334155;margin-top:2px}
.wf .seta{align-self:center;color:#94a3b8;font-size:10pt}
.wf .kpis{display:flex;flex-wrap:wrap;gap:6px}
.wf .kpi{border:1px solid #cbd5e1;border-radius:4px;padding:4px 8px;min-width:120px}
.wf .kpi span{display:block;font-size:7.5pt;color:#64748b;text-transform:uppercase}
.wf .kpi b{font-size:11pt}
.wf table{width:100%;border-collapse:collapse}
.wf th{background:#f1f5f9;text-align:left;padding:5px 6px;border:1px solid #cbd5e1;font-size:8.5pt}
.wf td{padding:5px 6px;border:1px solid #e2e8f0;vertical-align:top;font-size:8.8pt;line-height:1.4}
.wf tr{break-inside:avoid}
.wf tr.sel td{background:#fffbeb}
.wf .pend li{margin:2px 0}.wf .pend li.erro{color:#991b1b}.wf .pend li.aviso{color:#92400e}
`;

export function workflowHtml(d: DadosWorkflow): string {
  const N = d.narrativa;
  const nomes = d.etapas.map((e, i) => N?.etapas[i]?.nome || e.nome);
  const sel = d.etapaSeleccionada ? d.etapas.findIndex((e) => e.id === d.etapaSeleccionada) : -1;
  const comEstados = !!d.estados;
  const diagrama = d.etapas
    .map((e, i) => {
      const s = d.estados?.[e.id];
      const def = s ? ESTADOS_FLUXO[estadoDe(s.estado)] : null;
      const caixa = `<div class="no${i === sel ? ' sel' : ''}"><div class="num">${i + 1}</div><div class="nome">${esc(nomes[i])}</div>${N?.etapas[i]?.quem ? `<div class="quem">${esc(N.etapas[i].quem)}</div>` : ''}${
        def ? `<div class="est" style="background:${def.fundo};color:${def.texto}">${def.rotulo}</div>${s?.resumo ? `<div class="res">${esc(s.resumo)}</div>` : ''}` : ''
      }</div>`;
      return caixa + (i < d.etapas.length - 1 ? '<div class="seta">&#9654;</div>' : '');
    })
    .join('');
  const partes: string[] = ['<div class="wf">'];
  if (N) {
    partes.push(`<p class="obj"><b>Objectivo.</b> ${esc(N.objectivo)}</p>`);
    if (N.intervenientes.length) partes.push(`<div class="meta"><b>Intervenientes:</b> ${N.intervenientes.map(esc).join(' · ')}</div>`);
  }
  partes.push(`<h2>Diagrama do processo${d.processo ? ` — situação de ${esc(d.processo)}` : ''}</h2><div class="diag">${diagrama}</div>`);
  if (comEstados) partes.push('<div class="meta">Os estados e resumos são os do processo seleccionado no ecrã no momento da impressão; a etapa em destaque é a seleccionada.</div>');
  if (d.kpis?.length) partes.push(`<h2>Indicadores actuais</h2><div class="kpis">${d.kpis.map((k) => `<div class="kpi"><span>${esc(k.rotulo)}</span><b>${esc(k.texto)}</b></div>`).join('')}</div>`);
  if (N) {
    partes.push(
      `<h2>Narrativa por etapa</h2><table><thead><tr><th style="width:4%">N.º</th><th style="width:15%">Etapa</th><th style="width:13%">Quem executa</th><th>Descrição</th><th style="width:20%">Controlos</th><th style="width:14%">Resultado</th>${
        comEstados ? '<th style="width:10%">Estado actual</th>' : ''
      }</tr></thead><tbody>${N.etapas
        .map((e, i) => {
          const s = d.etapas[i] ? d.estados?.[d.etapas[i].id] : undefined;
          const est = comEstados ? `<td>${s ? `${ESTADOS_FLUXO[estadoDe(s.estado)].rotulo}${s.resumo ? `<br><small>${esc(s.resumo)}</small>` : ''}` : '—'}</td>` : '';
          return `<tr class="${i === sel ? 'sel' : ''}"><td>${i + 1}</td><td><b>${esc(e.nome)}</b></td><td>${esc(e.quem)}</td><td>${esc(e.descricao)}</td><td>${esc(e.controlos)}</td><td>${esc(e.resultado)}</td>${est}</tr>`;
        })
        .join('')}</tbody></table>`,
    );
  }
  if (sel >= 0 && (d.factos?.length || d.pendencias?.length)) {
    partes.push(`<h2>Etapa seleccionada: ${esc(nomes[sel])}</h2>`);
    if (d.factos?.length) partes.push(`<div class="kpis">${d.factos.map((f) => `<div class="kpi"><span>${esc(f.rotulo)}</span><b style="font-size:9.5pt">${esc(f.texto)}</b></div>`).join('')}</div>`);
    partes.push(d.pendencias?.length ? `<ul class="pend">${d.pendencias.map((p) => `<li class="${p.nivel === 'erro' ? 'erro' : 'aviso'}">${esc(p.texto)}</li>`).join('')}</ul>` : '<p class="meta">Sem pendências nesta etapa.</p>');
  }
  partes.push('</div>');
  return partes.join('');
}
