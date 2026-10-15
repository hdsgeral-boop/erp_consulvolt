import { esc } from '@/componentes/impressao';
import { formatarKz } from '@/utilitarios/formatacao';
import { nomeAndar, RESULTADOS } from './regras';
import type { ComparacaoInventario, FolhaInventario, Planta } from './tipos';

/**
 * Conteúdo HTML (sempre escapado) da impressão da planta e das folhas de inventário (assetImprimirPlanta :503-531,
 * assetImprimirFolha :930-969). O cabeçalho com a empresa e o utilizador é posto pelo motor de impressão comum.
 */

const CSS = `
  .andar{border:1px solid #cbd5e1;border-radius:4px;margin-bottom:8px;break-inside:avoid}
  .andar h4{margin:0;padding:4px 8px;background:#e2e8f0;font-size:9.5pt}
  .portas{display:grid;grid-template-columns:repeat(auto-fill,minmax(42mm,1fr));gap:5px;padding:6px}
  .porta{border:1px solid #cbd5e1;border-top:3px solid #b45309;border-radius:4px;padding:4px 6px;font-size:8.5pt}
  .porta ul{margin:3px 0 2px;padding-left:14px}.tot{color:#475569;font-size:8pt}.vz{color:#94a3b8;font-style:italic}
  .assin{display:flex;gap:30px;margin-top:28px}.assin div{flex:1;border-top:1px solid #0f172a;padding-top:4px;text-align:center;font-size:8.5pt}
  table.folha{width:100%;border-collapse:collapse}table.folha th,table.folha td{border:1px solid #cbd5e1;padding:2px 4px;font-size:8.5pt;vertical-align:top}
  table.folha th{background:#e2e8f0;text-align:left}.r{text-align:right}.c{text-align:center}
`;

export const CSS_EDIFICIOS = CSS;

export function htmlPlanta(p: Planta): string {
  const corpo = p.andares
    .map((f) => {
      const portas = f.portas
        .map((porta) => {
          const bens = porta.bens.map((b) => `<li>${esc(b.codigo ?? '')} — ${esc(b.descricao)}</li>`).join('') || '<li class="vz">sem bens</li>';
          return `<div class="porta"><strong>${esc(porta.numero)}</strong> ${esc(porta.nome || porta.tipo || '')}<ul>${bens}</ul>`
            + `<div class="tot">${porta.n_bens} bem(ns) · ${esc(formatarKz(porta.valor))}</div></div>`;
        })
        .join('') || '<div class="vz">sem portas</div>';
      return `<div class="andar"><h4>${esc(nomeAndar(f))} <small>(${f.n_portas} porta(s))</small></h4><div class="portas">${portas}</div></div>`;
    })
    .join('');
  const sub = `${p.totais.andares} andar(es) · ${p.totais.portas} porta(s) · ${p.totais.bens} bem(ns) · ${formatarKz(p.totais.valor)}`;
  return `<p>${esc(sub)}</p>${corpo || '<p>Sem andares.</p>'}`;
}

/** Folha para contagem no terreno (em branco) ou comparação já preenchida, com as três assinaturas. */
export function htmlFolha(f: FolhaInventario | ComparacaoInventario, comparacao: boolean): string {
  const inv = f.inventario;
  const previstas = f.linhas.filter((l) => l.resultado !== 'EXTRA');
  const extras = f.linhas.filter((l) => l.resultado === 'EXTRA');
  const total = previstas.reduce((s, l) => s + Number(l.valor || 0), 0);
  const linhas = previstas
    .map((l, i) => {
      const fim = comparacao
        ? `<td class="c" style="color:${l.resultado ? RESULTADOS[l.resultado].cor : '#64748b'};font-weight:700">${esc(l.resultado ? RESULTADOS[l.resultado].rotulo : 'Por contar')}</td>`
          + `<td>${esc(l.resultado === 'DESLOCADO' ? l.local_encontrado ?? '' : '')}</td><td>${esc(l.notas ?? '')}</td>`
        : '<td class="c">☐ Sim ☐ Não</td><td></td><td></td>';
      return `<tr><td class="c">${i + 1}</td><td><strong>${esc(l.codigo ?? '')}</strong></td><td>${esc(l.descricao ?? '')}</td><td>${esc(l.local_esperado ?? '—')}</td>`
        + `<td class="r">${esc(formatarKz(l.valor))}</td>${fim}</tr>`;
    })
    .join('');
  const cab = `<p>${esc(`${inv.edificio?.codigo ?? ''} — ${inv.edificio?.descricao ?? ''} · Âmbito: ${inv.ambito_texto} · Data: ${inv.data ?? ''} · Responsável: ${inv.responsavel ?? '—'}${inv.notas ? ` · ${inv.notas}` : ''}`)}</p>`;
  const tabela = `<table class="folha"><thead><tr><th>#</th><th>Nº Inventário</th><th>Descrição</th><th>Local esperado</th><th class="r">Valor</th>`
    + `${comparacao ? '<th>Resultado</th>' : '<th>Encontrado</th>'}<th>Local encontrado</th><th>Observações</th></tr></thead>`
    + `<tbody>${linhas || '<tr><td colspan="8" class="c">Sem bens esperados.</td></tr>'}</tbody>`
    + `<tfoot><tr><td colspan="4" class="r"><strong>TOTAL (${previstas.length} bens)</strong></td><td class="r"><strong>${esc(formatarKz(String(total.toFixed(2))))}</strong></td><td colspan="3"></td></tr></tfoot></table>`;
  const tabelaExtras = comparacao && extras.length
    ? `<h4>Bens encontrados não previstos (${extras.length})</h4><table class="folha"><thead><tr><th>Nº Inventário</th><th>Descrição</th><th>Local encontrado</th><th class="r">Valor</th></tr></thead><tbody>`
      + extras.map((l) => `<tr><td>${esc(l.codigo ?? '')}</td><td>${esc(l.descricao ?? '')}</td><td>${esc(l.local_encontrado ?? '—')}</td><td class="r">${esc(formatarKz(l.valor))}</td></tr>`).join('')
      + '</tbody></table>'
    : '';
  return `${cab}${tabela}${tabelaExtras}<div class="assin"><div>O responsável pela contagem</div><div>O responsável do local</div><div>A contabilidade</div></div>`;
}
