/**
 * «Gerar PDF para Assinatura» — minuta do contrato de trabalho segundo a Lei n.º 12/23, de 27 de Dezembro (Lei Geral do
 * Trabalho), como no legado novo (`window.generateContractPDF`, Versões/v4/ERP/js/app_v2.js:4998-5180):
 *   - dados do art. 13.º/1: morada repartida, naturalidade, documento e validade, INSS, CRC da empresa;
 *   - «por tempo indeterminado» ou «a termo certo», com o AVISO quando falta o motivo do termo (art. 13.º/2-3);
 *   - período experimental do contrato, por omissão 60 dias sem termo e 30 a termo (art. 18.º);
 *   - subsídios de férias e de Natal ≥ 50 % do salário base (art. 238.º/1; percentagens da configuração de RH);
 *   - horas por semana = min(44, horas/dia × 5,5) (art. 148.º);
 *   - objectivo geral da função e responsabilidades numeradas; o título e as responsabilidades do cargo da estrutura
 *     orgânica prevalecem quando existem.
 * Para imprimir ou guardar em PDF pelo motor de impressão. TODO o texto vindo da base passa por `esc` (é texto, não HTML).
 */
import { esc } from '@/componentes/impressao';
import { formatarData, formatarKz } from '@/utilitarios/formatacao';
import type { ContratoTrabalho } from '../api';
import { normalizarRemuneracoes, semFim } from '../comum/regras';

export interface DadosContratoAssinatura {
  contrato: ContratoTrabalho;
  empresa: { nome: string; nif?: string | null; morada?: string | null; crc?: string | null; municipio?: string | null };
  colaborador: {
    nome: string;
    nif?: string | null;
    morada?: string | null;
    nacionalidade?: string | null;
    data_nascimento?: string | null;
    naturalidade?: string | null;
    estado_civil?: string | null;
    documento?: string | null;
    documento_validade?: string | null;
    inss?: string | null;
  };
  /** Função (RH › Funções): nome, objectivo geral e responsabilidades (uma por linha; `descricao` nos registos antigos). */
  funcao?: { nome: string; objetivo_geral?: string | null; responsabilidades?: string | null; descricao?: string | null } | null;
  /** Cargo da estrutura orgânica do colaborador: o título e as responsabilidades próprias prevalecem sobre os da função. */
  cargo?: { titulo?: string | null; responsabilidades?: string | null } | null;
  /** Percentagens dos subsídios (configuração de RH); nunca abaixo dos 50 % legais. */
  subsidios?: { ferias?: number | null; natal?: number | null } | null;
  /** Nome da rubrica pelo id. */
  rubrica: (id: number) => string;
  /** Data de assinatura (ISO); por omissão hoje. */
  hoje?: string;
}

const CSS = `
.rh-contrato { font-family: 'Century Gothic', Arial, sans-serif; font-size: 10pt; text-align: justify; color: #1e293b; line-height: 1.6; }
.rh-contrato h1 { margin: 0; text-transform: uppercase; font-size: 20pt; letter-spacing: 2px; text-align: center; }
.rh-contrato .rh-c-topo { margin-bottom: 6mm; border-bottom: 0.8mm solid #1e293b; padding-bottom: 3mm; text-align: center; }
.rh-contrato .rh-c-modalidade { font-size: 9pt; color: #475569; margin-top: 1.5mm; }
.rh-contrato h3 { border-bottom: 0.3mm solid #ccc; padding-bottom: 1mm; font-size: 11pt; margin: 5mm 0 2mm; break-after: avoid; }
.rh-contrato .rh-c-aviso { border: 0.6mm solid #b91c1c; background: #fef2f2; color: #7f1d1d; padding: 2.5mm 3.5mm; margin-bottom: 5mm; font-size: 9pt; }
.rh-contrato .rh-c-falta { color: #b91c1c; }
.rh-contrato .rh-c-nota { font-size: 9pt; color: #475569; }
.rh-contrato ol { margin: 0 0 2.5mm 6mm; padding: 0; }
.rh-contrato ol li { margin-bottom: 0.8mm; }
.rh-contrato .rh-c-assin { margin-top: 12mm; display: flex; justify-content: space-between; break-inside: avoid; }
.rh-contrato .rh-c-assin div { width: 45%; text-align: center; border-top: 0.3mm solid #000; padding-top: 2mm; }
.rh-contrato .rh-c-data { margin-top: 9mm; text-align: center; font-size: 8pt; color: #777; }
`;

const txt = (v: unknown): string => String(v ?? '').trim();
const POR_PREENCHER = '<span class="rh-c-falta">[por preencher na ficha]</span>';
/** Valor escapado, ou a marca «por preencher» (ou a alternativa dada). */
const ou = (v: unknown, alt?: string): string => (txt(v) ? esc(txt(v)) : alt ?? POR_PREENCHER);
const EXTENSO: Record<number, string> = { 30: 'trinta', 60: 'sessenta', 90: 'noventa', 120: 'cento e vinte', 180: 'cento e oitenta' };

/** Horas por semana do contrato: min(44, horas/dia × 5,5), a uma casa (app_v2.js:5076). */
export function horasSemana(horasDia: number): number {
  const h = Math.min(44, Math.round(horasDia * 5.5 * 10) / 10);
  return h > 0 ? h : 44;
}

/** Período experimental: o do contrato, senão 60 dias sem termo e 30 a termo; a termo nunca acima de 30 (art. 18.º). */
export function periodoExperimental(c: Pick<ContratoTrabalho, 'periodo_experimental_dias' | 'data_fim'>): number {
  const semTermo = semFim(c.data_fim);
  const definido = c.periodo_experimental_dias !== null && c.periodo_experimental_dias !== undefined && Number.isFinite(Number(c.periodo_experimental_dias));
  const base = definido ? Number(c.periodo_experimental_dias) : semTermo ? 60 : 30;
  return semTermo ? base : Math.min(30, base);
}

/** Percentagem do subsídio: a configurada, nunca abaixo dos 50 % legais (art. 238.º/1). */
export const pctSubsidio = (v: number | null | undefined): number => Math.max(50, Number(v) || 50);

/** Responsabilidades em lista (uma por linha; tira marcadores «-», «•», «*»). */
export function listaResponsabilidades(t: string | null | undefined): string[] {
  return String(t ?? '').split(/\r?\n/).map((s) => s.replace(/^[-•*]\s*/, '').trim()).filter(Boolean);
}

const numeroPt = (n: number) => String(n).replace('.', ',');

export function contratoAssinaturaHtml(d: DadosContratoAssinatura): string {
  const c = d.contrato;
  const dias = Number(c.dias_contrato_mes) || 22;
  const horasDia = Number(c.horas_por_dia) || 8;
  const remuneracoes = normalizarRemuneracoes(c.remuneracoes, dias);
  const remHtml = remuneracoes
    .map((r) => {
      const mensal = Number(r.valor_mes) || 0;
      return `<li><strong>${esc(d.rubrica(r.infotipo_salarial_id) || 'Remuneração')}:</strong> ${esc(formatarKz(mensal))} por mês (${esc(formatarKz(mensal / dias))} por dia de trabalho efectivo).</li>`;
    })
    .join('');
  const semTermo = semFim(c.data_fim);
  const motivo = txt(c.motivo_termo);
  const diasExp = periodoExperimental(c);
  const pctFerias = pctSubsidio(d.subsidios?.ferias);
  const pctNatal = pctSubsidio(d.subsidios?.natal);

  // função e cargo: o título e as responsabilidades próprias do cargo da estrutura prevalecem (app_v2.js:5020)
  const categoria = txt(d.cargo?.titulo) || txt(d.funcao?.nome) || 'Colaborador';
  const objectivo = txt(d.funcao?.objetivo_geral);
  const resp = listaResponsabilidades(txt(d.cargo?.responsabilidades) || txt(d.funcao?.responsabilidades) || txt(d.funcao?.descricao));

  const e = d.empresa;
  const t = d.colaborador;
  const validade = t.documento_validade ? formatarData(t.documento_validade) : '';

  return `<style>${CSS}</style>
<div class="rh-contrato">
  <div class="rh-c-topo">
    <h1>Contrato de Trabalho</h1>
    <div class="rh-c-modalidade">${semTermo ? 'Por tempo indeterminado' : 'A termo certo'}</div>
  </div>
  ${!semTermo && !motivo ? `<div class="rh-c-aviso"><strong>Atenção — contrato incompleto.</strong> Nos contratos a termo é obrigatório indicar as razões
    determinantes da contratação (artigo 13.º, n.º 2, da Lei n.º 12/23). Na falta dessa menção, o contrato considera-se celebrado por tempo
    indeterminado (artigo 13.º, n.º 3). Preencha o motivo no contrato antes de assinar.</div>` : ''}

  <p><strong>ENTRE:</strong></p>
  <p style="margin-left:5mm"><strong>${ou(e.nome)}</strong>, com sede em ${ou(e.morada)}, contribuinte fiscal n.º ${ou(e.nif)}${txt(e.crc) ? `, matriculada na Conservatória do Registo Comercial sob o n.º ${esc(txt(e.crc))}` : ''},
    neste acto representada por quem legalmente a obriga, adiante designada por <strong>PRIMEIRA OUTORGANTE</strong> ou Empregadora.</p>
  <p><strong>E:</strong></p>
  <p style="margin-left:5mm"><strong>${ou(t.nome)}</strong>, de nacionalidade ${ou(t.nacionalidade, 'angolana')}${t.data_nascimento ? `, nascido(a) em ${esc(formatarData(t.data_nascimento))}` : ''}${txt(t.naturalidade) ? `, natural de ${esc(txt(t.naturalidade))}` : ''}${txt(t.estado_civil) ? `, de estado civil ${esc(txt(t.estado_civil).toLowerCase())}` : ''},
    portador(a) do documento de identificação n.º ${ou(t.documento)}${validade ? `, válido até ${esc(validade)}` : ''},
    contribuinte fiscal n.º ${ou(t.nif)}${txt(t.inss) ? `, inscrito(a) na Segurança Social sob o n.º ${esc(txt(t.inss))}` : ''},
    residente em ${ou(t.morada)}, adiante designado(a) por <strong>SEGUNDO OUTORGANTE</strong> ou Trabalhador.</p>

  <p>É celebrado o presente contrato de trabalho, que se rege pela <strong>Lei n.º 12/23, de 27 de Dezembro (Lei Geral do Trabalho)</strong>, demais legislação aplicável e pelas cláusulas seguintes:</p>

  <h3>Cláusula 1.ª (Objecto, categoria profissional e local de trabalho)</h3>
  <p>1. O Segundo Outorgante obriga-se a prestar a sua actividade profissional à Primeira Outorgante, sob a autoridade e direcção desta, com a categoria profissional de <strong>${esc(categoria)}</strong>.</p>
  ${objectivo ? `<p>2. <strong>Objectivo geral do cargo:</strong> ${esc(objectivo)}</p>` : ''}
  ${resp.length ? `<p>${objectivo ? 3 : 2}. <strong>Principais responsabilidades:</strong></p>
  <ol>${resp.map((r) => `<li>${esc(r)}</li>`).join('')}</ol>
  <p class="rh-c-nota">A enumeração é exemplificativa e não exclui outras tarefas compreendidas no objecto do cargo.</p>` : ''}
  <p>O local de trabalho é ${ou(e.morada)}, podendo o Trabalhador ser temporariamente deslocado para outras instalações da Empregadora, nos termos da lei.</p>
  <p>A Empregadora pode encarregar o Trabalhador de funções afins ou funcionalmente ligadas à sua categoria, sem diminuição da retribuição, nos termos do artigo 39.º da Lei n.º 12/23.</p>

  <h3>Cláusula 2.ª (Modalidade e duração do contrato)</h3>
  ${semTermo
    ? `<p>O presente contrato é celebrado <strong>por tempo indeterminado</strong>, produzindo efeitos a partir de <strong>${esc(formatarData(c.data_inicio))}</strong>.</p>`
    : `<p>O presente contrato é celebrado <strong>a termo certo</strong>, com início em <strong>${esc(formatarData(c.data_inicio))}</strong> e termo em <strong>${esc(formatarData(c.data_fim))}</strong>.</p>
  <p><strong>Razões determinantes da contratação a termo</strong> (artigo 13.º, n.º 2, e artigo 15.º da Lei n.º 12/23): ${motivo ? esc(motivo) : '<span class="rh-c-falta">[indicar o fundamento do artigo 15.º antes de assinar]</span>'}.</p>
  <p class="rh-c-nota">A duração do contrato observa os limites do artigo 16.º da Lei n.º 12/23, consoante o fundamento invocado.</p>`}

  <h3>Cláusula 3.ª (Período experimental)</h3>
  ${semTermo
    ? `<p>As partes acordam um período experimental de <strong>${diasExp} (${EXTENSO[diasExp] ?? diasExp}) dias</strong>, nos termos do artigo 18.º da Lei n.º 12/23, durante o qual qualquer das partes pode denunciar o contrato sem aviso prévio nem direito a indemnização.</p>
  <p class="rh-c-nota">Nos contratos por tempo indeterminado o período experimental corresponde aos primeiros 60 dias, podendo ser suprimido ou reduzido e, por acordo escrito, alargado até 120 dias ou até 180 dias no caso de funções de direcção.</p>`
    : `<p>As partes acordam, por escrito, um período experimental de <strong>${diasExp} dias</strong>, nos termos do artigo 18.º, n.º 3, da Lei n.º 12/23, durante o qual qualquer das partes pode denunciar o contrato sem aviso prévio nem direito a indemnização.</p>
  <p class="rh-c-nota">Nos contratos a termo o período experimental só existe havendo acordo escrito e não pode exceder 30 dias.</p>`}

  <h3>Cláusula 4.ª (Período normal de trabalho)</h3>
  <p>O período normal de trabalho é de <strong>${numeroPt(horasDia)} horas diárias</strong> e <strong>${numeroPt(horasSemana(horasDia))} horas semanais</strong>, dentro dos limites máximos de 8 horas por dia e 44 horas por semana fixados no artigo 148.º da Lei n.º 12/23, de acordo com o horário de trabalho em vigor na Empregadora.</p>
  <p>Para efeitos de processamento salarial, considera-se o mês de <strong>${dias} dias de trabalho</strong>.</p>
  <p>O trabalho prestado fora do período normal é remunerado como trabalho extraordinário, nos termos da lei.</p>

  <h3>Cláusula 5.ª (Remuneração)</h3>
  <p>Como contrapartida do trabalho prestado, o Trabalhador aufere:</p>
  <ul>${remHtml || '<li>A remuneração a definir conforme a tabela salarial em vigor.</li>'}</ul>
  <p>O salário é pago mensalmente, por transferência bancária para conta indicada pelo Trabalhador, <strong>até ao último dia útil do mês a que respeita</strong>, nos termos do artigo 245.º, n.º 1, da Lei n.º 12/23.</p>
  <p>Sobre a remuneração incidem os descontos legais, designadamente a contribuição do trabalhador para a Segurança Social e o Imposto sobre o Rendimento do Trabalho (IRT), que a Empregadora retém e entrega às entidades competentes.</p>

  <h3>Cláusula 6.ª (Férias e subsídios)</h3>
  <p>O Trabalhador tem direito a <strong>22 dias úteis de férias</strong> em cada ano civil, nos termos do artigo 204.º, n.º 1, da Lei n.º 12/23, não contando como tal os dias de descanso semanal, de descanso complementar e os feriados.</p>
  <p>No ano de admissão, as férias correspondem a 2 dias úteis por cada mês completo de trabalho, com o mínimo de 6 dias úteis.</p>
  <p>O Trabalhador tem ainda direito, nos termos do artigo 238.º, n.º 1, da Lei n.º 12/23, a uma <strong>gratificação de férias</strong> correspondente a ${pctFerias}% do salário base, paga antes do início das férias, e a um <strong>subsídio de Natal</strong> correspondente a ${pctNatal}% do salário base${pctFerias > 50 || pctNatal > 50 ? ', valores superiores aos mínimos legais de 50%' : ' (mínimos legais)'}.</p>

  <h3>Cláusula 7.ª (Descanso semanal e feriados)</h3>
  <p>O Trabalhador tem direito a um dia completo de descanso semanal, em regra ao domingo, com duração não inferior a 24 horas consecutivas, nos termos dos artigos 191.º a 193.º da Lei n.º 12/23, bem como aos feriados nacionais fixados na lei.</p>

  <h3>Cláusula 8.ª (Segurança social e acidentes de trabalho)</h3>
  <p>A Empregadora obriga-se a inscrever o Trabalhador na Segurança Social e a entregar as contribuições devidas, bem como a manter em vigor seguro de acidentes de trabalho e doenças profissionais, nos termos da legislação aplicável.</p>

  <h3>Cláusula 9.ª (Deveres e confidencialidade)</h3>
  <p>O Trabalhador obriga-se a cumprir com zelo e diligência as funções contratadas, a observar as normas internas da Empregadora e a guardar sigilo sobre informações comerciais, financeiras, técnicas e pessoais a que tenha acesso, obrigação que se mantém após a cessação do contrato.</p>
  <p>São deveres da Empregadora e do Trabalhador os previstos nos artigos 81.º e 83.º da Lei n.º 12/23.</p>

  <h3>Cláusula 10.ª (Cessação do contrato)</h3>
  <p>O contrato cessa nos termos previstos na Lei n.º 12/23.</p>
  <p>Não havendo justa causa, o Trabalhador pode rescindir o contrato mediante <strong>aviso prévio escrito de 30 dias</strong>, nos termos do artigo 305.º, n.º 1, da Lei n.º 12/23.</p>
  ${semTermo ? '' : '<p>A não renovação do contrato a termo é comunicada por escrito com a antecedência mínima de 30 dias (artigo 17.º, n.º 3, da Lei n.º 12/23).</p>'}

  <h3>Cláusula 11.ª (Disposição final)</h3>
  <p>Em tudo o que não estiver expressamente previsto neste contrato aplica-se a Lei n.º 12/23, de 27 de Dezembro, e demais legislação laboral em vigor na República de Angola.</p>
  <p>O presente contrato é feito em dois exemplares, ficando um na posse de cada uma das partes.</p>

  <div class="rh-c-assin"><div>A PRIMEIRA OUTORGANTE<br><span class="rh-c-nota">${ou(e.nome, '')}</span></div><div>O SEGUNDO OUTORGANTE<br><span class="rh-c-nota">${ou(t.nome, '')}</span></div></div>
  <div class="rh-c-data">${ou(e.municipio, 'Luanda')}, ${esc(formatarData(d.hoje ?? new Date().toISOString().slice(0, 10)))}</div>
</div>`;
}
