/**
 * Impressão das simulações salariais (legado js/modules/rh/folha_salarios.js: `imprimirSimulacaoColaborador`,
 * `imprimirSimulacaoPeriodo`). Os valores vêm SEMPRE do servidor (cálculo ao vivo do período em aberto ou fotografia):
 * aqui só se monta o HTML para o motor comum de impressão. Nada é gravado.
 */
import { esc, tabelaHtml, type ColunaImpressao, type PedidoImpressao } from '@/componentes/impressao';
import { somar } from '@/utilitarios/decimal';
import { formatarKz, formatarNumero } from '@/utilitarios/formatacao';
import type { ResultadoSalarial, RubricaResultado } from '../api';

/** INSS do trabalhador + IRT + outros descontos (o que se tira ao ilíquido para dar o líquido). */
export function totalDescontos(r: Pick<ResultadoSalarial, 'inss_trabalhador' | 'irt' | 'descontos'>): string {
  return somar([r.inss_trabalhador, r.irt, r.descontos]);
}

/** Percentagem efectiva (ex.: INSS 3 % sobre a base), só para mostrar; vazio se não houver base. */
export function percentagemEfectiva(valor: string | number | null | undefined, base: string | number | null | undefined): string {
  const b = Number(base);
  const v = Number(valor);
  if (!(b > 0) || !(v > 0)) return '';
  return `${formatarNumero(Math.round((v / b) * 10000) / 100)} %`;
}

const rubricasVisiveis = (r: ResultadoSalarial): RubricaResultado[] =>
  (r.rubricas ?? []).filter((x) => !x.informativa && x.tipo !== 'OUTROS' && (Math.abs(Number(x.valor)) > 0.004 || Number(x.horas ?? 0) > 0));

const MARCA_SIMULACAO =
  '<div class="rh-sim-marca"><b>SIMULAÇÃO</b> — documento sem valor de processamento: calculado no servidor com os lançamentos gravados no momento da impressão; nada foi gravado.</div>';

export const CSS_SIMULACAO = `
.rh-sim-marca { border: 0.4mm dashed #b45309; color: #92400e; padding: 1.2mm 2.5mm; font-size: 8pt; margin: 0 0 3mm; break-inside: avoid; }
.rh-sim-aviso { font-size: 8pt; color: #92400e; margin-top: 2mm; }
.rh-sim-nota { font-size: 7.5pt; color: #555; margin-top: 3mm; }
.rh-sim-res td { font-size: 9pt; }
.imp-tabela tr.rh-sim-liquido td { font-weight: 700; font-size: 10pt; border-top: 0.5mm solid #1f1f1f; }
.imp-tabela td.rh-sim-desc { color: #b42318; }
.rh-sim-grupo { font-size: 10.5pt; margin: 4mm 0 1.5mm; padding: 1mm 2mm; border-left: 1mm solid #1f1f1f; background: #f3f4f6; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
.rh-sim-assin { display: flex; justify-content: space-around; gap: 10mm; margin-top: 12mm; break-inside: avoid; }
.rh-sim-assin div { flex: 0 1 28%; text-align: center; font-size: 8pt; border-top: 0.3mm solid #1f1f1f; padding-top: 1mm; }
`;

/** HTML da simulação de um colaborador: identificação, rubricas (vencimentos / descontos), INSS, IRT, totais, líquido e encargo patronal. */
export function simulacaoColaboradorHtml(r: ResultadoSalarial, o: { nome: string; funcao?: string | null; simulacao?: boolean }): string {
  const linhas = rubricasVisiveis(r);
  const td = (v: string, desc = false) => `<td class="imp-num${desc ? ' rh-sim-desc' : ''}">${esc(v)}</td>`;
  const corpo = linhas
    .map((x) => {
      const det = x.horas && Number(x.horas) ? `<div style="font-size:7.5pt;color:#555">${esc(formatarNumero(x.horas))} h</div>` : '';
      return `<tr><td>${esc(x.nome)}${det}</td>${x.tipo === 'DESCONTO' ? `${td('')}${td(formatarKz(x.valor), true)}` : `${td(formatarKz(x.valor))}${td('')}`}</tr>`;
    })
    .join('');
  const pctTrab = percentagemEfectiva(r.inss_trabalhador, r.base_inss);
  const inss = r.avencado ? '' : `<tr><td>INSS trabalhador${pctTrab ? ` (${esc(pctTrab)})` : ''}</td>${td('')}${td(formatarKz(r.inss_trabalhador), true)}</tr>`;
  const irt = `<tr><td>IRT${r.avencado ? ' Grupo B (6,5 %)' : ` (matéria colectável ${esc(formatarKz(r.base_irt))} Kz)`}</td>${td('')}${td(formatarKz(r.irt), true)}</tr>`;
  const regime = r.avencado ? 'Prestador avençado (IRT Grupo B)' : r.reformado ? 'Reformado' : 'Conta de outrem (IRT Grupo A)';
  const ident = `<table class="imp-tabela rh-sim-res"><tbody>
<tr><td style="width:22%;color:#555">${r.avencado ? 'Prestador' : 'Colaborador'}</td><td><b>${esc(o.nome)}</b></td><td style="width:18%;color:#555">NIF</td><td>${esc(r.nif || '—')}</td></tr>
<tr><td style="color:#555">Função</td><td>${esc(o.funcao || r.funcao || '—')}</td><td style="color:#555">Dias contr. / trab.</td><td>${esc(formatarNumero(r.dias_contrato))} / ${esc(formatarNumero(r.dias_trabalhados))}</td></tr>
<tr><td style="color:#555">Regime</td><td colspan="3">${esc(regime)}</td></tr>
</tbody></table>`;
  const pat = r.avencado
    ? ''
    : `<tr><td>INSS patronal (encargo da empresa${percentagemEfectiva(r.inss_patronal, r.base_inss) ? `, ${esc(percentagemEfectiva(r.inss_patronal, r.base_inss))}` : ''})</td><td class="imp-num" colspan="2">${esc(formatarKz(r.inss_patronal))}</td></tr>
<tr><td>Custo total para a empresa</td><td class="imp-num" colspan="2"><b>${esc(formatarKz(somar([r.bruto, r.inss_patronal])))}</b></td></tr>`;
  const tabela = `<table class="imp-tabela"><thead><tr><th>Rubrica</th><th class="imp-num">Vencimentos (Kz)</th><th class="imp-num">Descontos (Kz)</th></tr></thead>
<tbody>${corpo || '<tr><td class="imp-vazio" colspan="3">Sem rubricas lançadas.</td></tr>'}${inss}${irt}</tbody>
<tfoot><tr class="imp-total"><td>Totais</td>${td(formatarKz(r.bruto))}${td(formatarKz(totalDescontos(r)))}</tr>
<tr class="rh-sim-liquido"><td>Líquido a receber</td><td class="imp-num" colspan="2">${esc(formatarKz(r.liquido))} Kz</td></tr>${pat}</tfoot></table>`;
  const avisos = r.avisos?.length ? `<div class="rh-sim-aviso">⚠ ${esc(r.avisos.join(' '))}</div>` : '';
  const nota = o.simulacao === false ? '' : '<div class="rh-sim-nota">Simulação — os valores podem mudar se os lançamentos ou o contrato forem alterados antes do encerramento.</div>';
  return `${o.simulacao === false ? '' : MARCA_SIMULACAO}${ident}${tabela}${avisos}${nota}`;
}

/** Pedido de impressão da simulação de um colaborador (A4 vertical por omissão). */
export function pedidoSimulacaoColaborador(r: ResultadoSalarial, o: { nome: string; mesAno: string; funcao?: string | null; simulacao?: boolean }): PedidoImpressao {
  return {
    titulo: o.simulacao === false ? 'Detalhe salarial' : 'Simulação salarial',
    subtitulo: o.nome,
    periodo: o.mesAno,
    conteudo: simulacaoColaboradorHtml(r, o),
    cssExtra: CSS_SIMULACAO,
    orientacao: 'retrato',
  };
}

/** Rubricas mais comuns primeiro (como no legado), depois por ordem alfabética. */
const PRIORIDADE = ['salário base', 'sal. base', 'subsídio de alimentação', 's. alim', 'subsídio de transporte', 's. transp', 'subsídio de férias', 's. férias', 'horas extraordinárias', 'h. extras'];

function ordenar(nomes: Set<string>): string[] {
  const p = (n: string) => {
    const i = PRIORIDADE.indexOf(n.toLowerCase());
    return i === -1 ? 99 : i;
  };
  return [...nomes].sort((a, b) => p(a) - p(b) || a.localeCompare(b, 'pt', { numeric: true }));
}

/** Colunas de rubricas presentes nos resultados (vencimentos e descontos; faltas primeiro nos descontos). */
export function colunasRubricasFolha(dados: ResultadoSalarial[]): { vencimentos: string[]; descontos: string[] } {
  const v = new Set<string>();
  const d = new Set<string>();
  dados.forEach((r) => rubricasVisiveis(r).forEach((x) => (x.tipo === 'DESCONTO' ? d : v).add(x.nome)));
  const desc = ordenar(d);
  const faltas = new Set(dados.flatMap((r) => (r.rubricas ?? []).filter((x) => x.falta).map((x) => x.nome)));
  return { vencimentos: ordenar(v), descontos: [...desc.filter((n) => faltas.has(n)), ...desc.filter((n) => !faltas.has(n))] };
}

const somaRubrica = (r: ResultadoSalarial, nome: string, desconto: boolean) =>
  somar((r.rubricas ?? []).filter((x) => x.nome === nome && !x.informativa && (desconto ? x.tipo === 'DESCONTO' : x.tipo === 'VENCIMENTO')).map((x) => x.valor));

/**
 * Mapa detalhado da folha (todas as rubricas em colunas): N.º, nome, NIF, dias, vencimentos, ilíquido, descontos, INSS,
 * IRT, total de descontos, líquido e INSS patronal, com totais. Mapa longo (muitas colunas): o motor passa-o a horizontal
 * e ajusta a escala; o utilizador pode impor a orientação no botão.
 */
export function tabelaFolhaDetalhada(dados: ResultadoSalarial[], nome: (r: ResultadoSalarial) => string): string {
  const { vencimentos, descontos } = colunasRubricasFolha(dados);
  const avencados = dados.length > 0 && dados.every((r) => r.avencado);
  const moeda = (titulo: string, valor: (r: ResultadoSalarial) => string): ColunaImpressao<ResultadoSalarial> => ({
    titulo, valor, formato: 'moeda', somar: true, formatar: (v) => (v === null || v === undefined || v === '' || typeof v === 'boolean' || Number(v) === 0 ? '-' : formatarKz(v)),
  });
  const colunas: ColunaImpressao<ResultadoSalarial>[] = [
    { titulo: 'N.º', valor: (_, i) => i + 1, formato: 'inteiro', alinhamento: 'centro' },
    { titulo: avencados ? 'Prestador' : 'Colaborador', valor: (r) => nome(r) },
    { titulo: 'NIF', valor: (r) => r.nif ?? '' },
    { titulo: 'D.C.', valor: (r) => formatarNumero(r.dias_contrato), alinhamento: 'centro' },
    { titulo: 'D.T.', valor: (r) => formatarNumero(r.dias_trabalhados), alinhamento: 'centro' },
    ...vencimentos.map((n) => moeda(n, (r) => somaRubrica(r, n, false))),
    moeda('Total ilíquido', (r) => r.bruto),
    ...descontos.map((n) => moeda(n, (r) => somaRubrica(r, n, true))),
    ...(avencados ? [] : [moeda('INSS trab.', (r) => r.inss_trabalhador)]),
    moeda(avencados ? 'IRT Grupo B 6,5 %' : 'IRT', (r) => r.irt),
    moeda('Total descontos', (r) => totalDescontos(r)),
    moeda('Líquido a receber', (r) => r.liquido),
    ...(avencados ? [] : [moeda('INSS patronal', (r) => r.inss_patronal)]),
  ];
  return tabelaHtml({ colunas, linhas: dados, totais: `TOTAIS (${dados.length})`, vazio: 'Sem colaboradores neste período.' });
}

/**
 * Simulação da folha do período (zona de cálculo): folhas separadas Colaboradores / Avençados, assinaturas e a marca
 * «Simulação» enquanto o período não estiver validado.
 */
export function pedidoSimulacaoPeriodo(o: {
  resultados: ResultadoSalarial[];
  nome: (r: ResultadoSalarial) => string;
  mesAno: string;
  estado: string;
  grupo?: 'colaboradores' | 'avencados';
}): PedidoImpressao {
  const ordenados = [...o.resultados].sort((a, b) => o.nome(a).localeCompare(o.nome(b), 'pt', { numeric: true }));
  const grupos = [
    { chave: 'colaboradores', titulo: 'Folha de salários — Colaboradores', dados: ordenados.filter((r) => !r.avencado) },
    { chave: 'avencados', titulo: 'Folha de avenças — Prestadores de serviço (IRT Grupo B)', dados: ordenados.filter((r) => r.avencado) },
  ].filter((g) => g.dados.length && (!o.grupo || g.chave === o.grupo));
  const simulacao = o.estado !== 'VALIDADO';
  const fechado = o.estado === 'FECHADO' || o.estado === 'VALIDADO';
  const blocos = grupos.map((g) => `<section><h3 class="rh-sim-grupo">${esc(g.titulo)} · ${g.dados.length}</h3>${tabelaFolhaDetalhada(g.dados, o.nome)}</section>`).join('');
  return {
    titulo: fechado ? 'Mapa de processamento salarial' : 'Simulação da folha de salários',
    periodo: o.mesAno,
    conteudo: `${simulacao ? MARCA_SIMULACAO : ''}${blocos || '<p>Sem dados para imprimir.</p>'}<div class="rh-sim-assin"><div>Elaborado por</div><div>Verificado por</div><div>Aprovado por</div></div>`,
    cssExtra: CSS_SIMULACAO,
  };
}
