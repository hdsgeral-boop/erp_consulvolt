/**
 * «Gerar PDF para Assinatura» (legado `window.generateContractPDF`, js/app_v2.js:4722): minuta do contrato de trabalho
 * com as cláusulas do sistema anterior, para imprimir ou guardar em PDF pelo motor de impressão (o logótipo e o nome
 * da empresa no cabeçalho vêm do motor). Todo o texto vindo da base passa por `esc`.
 */
import { esc } from '@/componentes/impressao';
import { formatarData, formatarKz } from '@/utilitarios/formatacao';
import type { ContratoTrabalho } from '../api';
import { normalizarRemuneracoes, semFim } from '../comum/regras';

export interface DadosContratoAssinatura {
  contrato: ContratoTrabalho;
  empresa: { nome: string; nif?: string | null; morada?: string | null };
  colaborador: { nome: string; nif?: string | null; morada?: string | null };
  funcao?: { nome: string; descricao?: string | null } | null;
  /** Nome da rubrica pelo id. */
  rubrica: (id: number) => string;
  /** Data de assinatura (ISO); por omissão hoje. */
  hoje?: string;
}

const CSS = `
.rh-contrato { font-family: 'Century Gothic', Arial, sans-serif; font-size: 10pt; text-align: justify; color: #1e293b; line-height: 1.6; }
.rh-contrato h1 { margin: 0 0 6mm; text-transform: uppercase; font-size: 20pt; letter-spacing: 2px; text-align: center; border-bottom: 0.8mm solid #1e293b; padding-bottom: 3mm; }
.rh-contrato h3 { border-bottom: 0.3mm solid #ccc; padding-bottom: 1mm; font-size: 11pt; margin: 5mm 0 2mm; break-after: avoid; }
.rh-contrato .rh-c-funcoes { margin: 2mm 0 3mm; padding: 3mm; background: #f8fafc; border: 0.3mm solid #e2e8f0; border-radius: 1.5mm; white-space: pre-line; font-style: italic; }
.rh-contrato .rh-c-assin { margin-top: 14mm; display: flex; justify-content: space-between; break-inside: avoid; }
.rh-contrato .rh-c-assin div { width: 45%; text-align: center; border-top: 0.3mm solid #000; padding-top: 2mm; }
.rh-contrato .rh-c-data { margin-top: 10mm; text-align: center; font-size: 8pt; color: #777; }
`;

export function contratoAssinaturaHtml(d: DadosContratoAssinatura): string {
  const c = d.contrato;
  const dias = Number(c.dias_contrato_mes) || 22;
  const horas = Number(c.horas_por_dia) || 8;
  const remuneracoes = normalizarRemuneracoes(c.remuneracoes, dias);
  const remHtml = remuneracoes
    .map((r) => {
      const mensal = Number(r.valor_mes) || 0;
      return `<li><strong>${esc(d.rubrica(r.infotipo_salarial_id) || 'Remuneração')}:</strong> ${esc(formatarKz(mensal))} por mês (${esc(formatarKz(mensal / dias))} por dia de trabalho efectivo).</li>`;
    })
    .join('');
  const semTermo = semFim(c.data_fim);
  const morada = d.empresa.morada || '---';
  const funcao = d.funcao?.nome || 'Colaborador';
  return `<style>${CSS}</style>
<div class="rh-contrato">
  <h1>Contrato de Trabalho</h1>
  <p><strong>ENTRE:</strong></p>
  <p style="margin-left:5mm"><strong>${esc(d.empresa.nome)}</strong>, com sede em ${esc(morada)}, NIF ${esc(d.empresa.nif || '---')}, adiante designada por <strong>PRIMEIRA OUTORGANTE</strong> ou Empregadora.</p>
  <p><strong>E:</strong></p>
  <p style="margin-left:5mm"><strong>${esc(d.colaborador.nome)}</strong>, residente em ${esc(d.colaborador.morada || '---')}, portador do NIF ${esc(d.colaborador.nif || '---')}, adiante designado por <strong>SEGUNDO OUTORGANTE</strong> ou Trabalhador.</p>
  <p>É celebrado o presente Contrato de Trabalho, que se rege pelas Cláusulas seguintes:</p>

  <h3>Cláusula 1ª (Objecto, Funções e Local de Trabalho)</h3>
  <p>O Segundo Outorgante obriga-se a prestar a sua actividade profissional à Primeira Outorgante sob a autoridade e direcção desta, exercendo a Função de <strong>${esc(funcao)}</strong>.</p>
  <p>O Trabalhador exercerá as suas funções na sede da Empregadora, sita em ${esc(morada)}, podendo ser deslocado temporariamente para outras unidades.</p>
  <p>A Empregadora reserva-se o direito de solicitar ao Trabalhador a acumulação de funções afins, sempre que a necessidade do serviço o exigir, sem prejuízo da sua remuneração.</p>
  ${d.funcao?.descricao ? `<div class="rh-c-funcoes"><strong style="display:block;font-style:normal;margin-bottom:1.5mm">Obrigações e Descrição de Funções:</strong>${esc(d.funcao.descricao)}</div>` : ''}

  <h3>Cláusula 2ª (Remuneração)</h3>
  <p>Em contrapartida do seu trabalho, o Trabalhador terá direito às seguintes remunerações contratuais:</p>
  <ul>${remHtml || '<li>A definir conforme tabela salarial em vigor.</li>'}</ul>
  <p>Adicionalmente, o Trabalhador tem direito a um Subsídio de Férias e Subsídio de Natal equivalentes a 100% do salário base.</p>
  <p>O pagamento da remuneração mensal será efectuado até ao 5º dia útil do mês seguinte, mediante transferência bancária, devendo o Trabalhador indicar o IBAN para o efeito.</p>

  <h3>Cláusula 3ª (Duração, Horário e Período Experimental)</h3>
  <p>O presente contrato é celebrado ${semTermo ? 'a termo incerto' : 'a termo certo'}, tendo início em <strong>${esc(formatarData(c.data_inicio))}</strong>${semTermo ? '' : ` e termo em <strong>${esc(formatarData(c.data_fim))}</strong>`}.</p>
  <p>Fica estabelecido um Período experimental de 60 (sessenta) dias, nos termos da Lei Geral do Trabalho, durante o qual qualquer das partes pode denunciá-lo sem aviso prévio ou indemnização.</p>
  <p>O Período normal de trabalho será de <strong>${dias} dias por mês</strong>, com um Período diário de <strong>${String(horas).replace('.', ',')} horas</strong>, de acordo com o horário em vigor na empresa.</p>

  <h3>Cláusula 4ª (Férias, Descanso e Faltas)</h3>
  <p>O Trabalhador tem direito a 30 (trinta) dias de férias por cada ano completo de trabalho, a gozar em data acordada com a Empregadora, bem como a 1 (um) dia de descanso semanal e a feriados nacionais, de acordo com o calendário oficial da República de Angola.</p>
  <p>As faltas serão justificadas nos termos da Lei Geral do Trabalho, mediante apresentação de atestado médico ou outro documento aceite pela Empregadora.</p>

  <h3>Cláusula 5ª (Deveres, Confidencialidade e Rescisão)</h3>
  <p>O Trabalhador compromete-se a cumprir com zelo e dedicação as suas funções. Obriga-se a não divulgar informações sobre clientes, dados financeiros, estratégias comerciais e demais informações internas, mesmo após a cessação do contrato.</p>
  <p>A rescisão do contrato será regida pela Lei Geral do Trabalho, com aviso prévio de 30 dias (em contratos sem termo), salvo em caso de justa causa devidamente comprovada.</p>

  <div class="rh-c-assin"><div>Pela PRIMEIRA OUTORGANTE</div><div>Pelo SEGUNDO OUTORGANTE</div></div>
  <div class="rh-c-data">Data de Assinatura: Luanda, ${esc(formatarData(d.hoje ?? new Date().toISOString().slice(0, 10)))}</div>
</div>`;
}
