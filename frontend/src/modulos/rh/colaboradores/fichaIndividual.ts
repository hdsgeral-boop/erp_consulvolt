/**
 * «Ficha Individual do Colaborador» — conteúdo do documento impresso a partir da lista de colaboradores
 * (paridade com `window.imprimirFichaColaborador` do sistema anterior, js/modules/rh/ficha_colaborador.js).
 * O cabeçalho da empresa (logótipo, nome, NIF) é posto pelo motor de impressão; aqui só se monta o corpo.
 * Todo o texto vindo da base passa por `esc` (sem HTML injectado).
 */
import { esc } from '@/componentes/impressao';
import { formatarData } from '@/utilitarios/formatacao';
import { ESTADOS_CIVIS, PARENTESCOS, type ContratoTrabalho, type FichaColaborador, type ReferenciaNome } from '../api';
import { semFim } from '../comum/regras';

export interface ContextoFicha {
  funcao: string;
  orgao: string;
  gestor: string;
  /** Data de emissão (ISO); por omissão hoje. */
  hoje?: string;
}

const vazio = '<span style="color:#94a3b8">—</span>';
const v = (x: unknown): string => (x === null || x === undefined || x === '' ? vazio : esc(String(x)));
const dataPt = (x: string | null | undefined): string => (x ? formatarData(x) : '');

/** Idade em anos completos na data de referência (null se a data for inválida ou futura). */
export function calcularIdade(iso: string | null | undefined, referencia: string = new Date().toISOString().slice(0, 10)): number | null {
  if (!iso) return null;
  const [a, m, d] = iso.slice(0, 10).split('-').map(Number);
  const [ra, rm, rd] = referencia.slice(0, 10).split('-').map(Number);
  if (!a || !m || !d || !ra) return null;
  let idade = ra - a;
  if (rm < m || (rm === m && rd < d)) idade -= 1;
  return idade >= 0 && idade < 150 ? idade : null;
}

function refNome(r: ReferenciaNome | null | undefined): string {
  return r ? [r.codigo, r.nome].filter(Boolean).join(' — ') : '';
}

/**
 * Contrato «vigente» (#7, Versão Actual; RegrasContrato::vigente no servidor): o que abrange a data e não está INACTIVO
 * (ACTIVO, SUSPENSO ou CESSADO — a ficha de quem tem o contrato suspenso ou cessado continua a mostrá-lo); senão o
 * ACTIVO mais recente; senão o mais recente de todos.
 */
export function contratoEmVigor(contratos: ContratoTrabalho[], hoje: string = new Date().toISOString().slice(0, 10)): ContratoTrabalho | null {
  const recentes = [...contratos].sort((x, y) => String(y.data_inicio ?? '').localeCompare(String(x.data_inicio ?? '')) || y.id - x.id);
  const estado = (c: ContratoTrabalho) => String(c.estado ?? 'ACTIVO').toUpperCase();
  const abrange = (c: ContratoTrabalho) => (!c.data_inicio || c.data_inicio.slice(0, 10) <= hoje) && (!c.data_fim || c.data_fim.slice(0, 10) >= hoje);
  const naData = recentes.filter((c) => abrange(c) && estado(c) !== 'INACTIVO');
  return naData.find((c) => estado(c) === 'ACTIVO') ?? naData[0] ?? recentes.find((c) => estado(c) === 'ACTIVO') ?? recentes[0] ?? null;
}

export function fichaIndividualHtml(c: FichaColaborador, contratos: ContratoTrabalho[], ctx: ContextoFicha): string {
  const linha = (pares: [string, string][]) => `<tr>${pares.map(([r, x]) => `<th>${esc(r)}</th><td>${x}</td>`).join('')}</tr>`;
  const titulo = (t: string) => `<h3 style="font-size:12pt;margin:14px 0 6px;padding-bottom:3px;border-bottom:1.5px solid #0f172a">${esc(t)}</h3>`;
  const sexo = c.sexo === 'M' ? 'Masculino' : c.sexo === 'F' ? 'Feminino' : '';
  const idade = calcularIdade(c.data_nascimento, ctx.hoje);
  const tipo = [c.avencado ? 'Avençado' : '', c.reformado ? 'Aposentado' : ''].filter(Boolean).join(', ') || 'Normal';
  const contrato = contratoEmVigor(contratos, ctx.hoje ?? undefined);
  const estadoCivil = ESTADOS_CIVIS.find((e) => e.value === c.estado_civil)?.label ?? c.estado_civil;
  const parentesco = (p: string | null | undefined) => PARENTESCOS.find((x) => x.value === p)?.label ?? p ?? '';

  return `
  <div class="rh-ficha-individual" style="font-family:Arial, Helvetica, sans-serif; color:#0f172a; font-size:9.5pt;">
    <style>
      .fci-t { width:100%; border-collapse:collapse; }
      .fci-t th { text-align:left; font-weight:600; color:#475569; width:18%; padding:4px 6px; border:1px solid #e2e8f0; background:#f8fafc; vertical-align:top; }
      .fci-t td { padding:4px 6px; border:1px solid #e2e8f0; width:32%; vertical-align:top; }
      .fci-l { width:100%; border-collapse:collapse; }
      .fci-l th { background:#f1f5f9; text-align:left; padding:4px 6px; border:1px solid #cbd5e1; font-weight:600; }
      .fci-l td { padding:4px 6px; border:1px solid #e2e8f0; }
    </style>
    <div style="text-align:right;color:#475569;margin-bottom:4px">Emitida em ${esc(dataPt(ctx.hoje ?? new Date().toISOString().slice(0, 10)))}</div>

    ${titulo('Identificação')}
    <table class="fci-t">
      ${linha([['Nome', `<strong>${v(c.nome_completo)}</strong>`], ['Nº interno', v(c.id)]])}
      ${linha([['NIF', v(c.nif)], ['Nº INSS', v(c.numero_inss)]])}
      ${linha([['Função', v(ctx.funcao)], ['Estado', v(c.estado)]])}
      ${linha([['Órgão', v(ctx.orgao)], ['Data de admissão', v(dataPt(c.data_admissao))]])}
      ${linha([['Unidade orgânica', v(refNome(c.unidade_organica))], ['UN / CC', v([refNome(c.unidade_negocio), refNome(c.centro_custo)].filter(Boolean).join(' / '))]])}
      ${linha([['Chefia directa', v(ctx.gestor === '—' ? '' : ctx.gestor)], ['', '']])}
      ${linha([['Tipo', v(tipo)], ['Dias de trabalho', v(c.dias_uteis_mes)]])}
    </table>

    ${titulo('Dados pessoais')}
    <table class="fci-t">
      ${linha([['Sexo', v(sexo)], ['Data de nascimento', `${v(dataPt(c.data_nascimento))}${idade !== null ? ` (${idade} anos)` : ''}`]])}
      ${linha([['Estado civil', v(estadoCivil)], ['Nacionalidade', v(c.nacionalidade)]])}
      ${linha([['Naturalidade', v(c.naturalidade)], ['Província', v(c.provincia_naturalidade)]])}
      ${linha([['BI / Passaporte', v(c.documento_identificacao)], ['Validade', v(dataPt(c.documento_validade))]])}
      ${linha([['Habilitação máxima', v(c.habilitacao_maxima)], ['', '']])}
    </table>

    ${titulo('Endereço e contactos')}
    <table class="fci-t">
      ${linha([['Endereço', v(c.endereco)], ['Bairro', v(c.bairro)]])}
      ${linha([['Município', v(c.municipio)], ['Província', v(c.provincia)]])}
      ${linha([['Telefone', v(c.telefone)], ['Telefone alternativo', v(c.telefone_alternativo)]])}
      ${linha([['E-mail', v(c.email)], ['', '']])}
      ${linha([['Emergência', v(c.emergencia_nome)], ['Telefone / parentesco', v([c.emergencia_telefone, c.emergencia_parentesco].filter(Boolean).join(' · '))]])}
    </table>

    ${titulo('Agregado familiar')}
    ${c.dependentes.length ? `<table class="fci-l"><thead><tr><th>Nome</th><th>Parentesco</th><th>Data de nascimento</th><th>Idade</th><th>Sexo</th><th>Dependente fiscal</th></tr></thead><tbody>
      ${c.dependentes.map((d) => { const i = calcularIdade(d.data_nascimento, ctx.hoje); return `<tr><td>${esc(d.nome ?? '')}</td><td>${esc(parentesco(d.parentesco))}</td><td>${esc(dataPt(d.data_nascimento))}</td><td>${i === null ? '' : i}</td><td>${esc(d.sexo ?? '')}</td><td>${d.dependente_fiscal ? 'Sim' : 'Não'}</td></tr>`; }).join('')}
    </tbody></table>` : '<p style="color:#64748b;margin:4px 0">Sem membros registados.</p>'}

    ${titulo('Habilitações literárias e profissionais')}
    ${c.habilitacoes.length ? `<table class="fci-l"><thead><tr><th>Nível</th><th>Curso / área</th><th>Instituição</th><th>Ano</th><th>Estado</th></tr></thead><tbody>
      ${c.habilitacoes.map((h) => `<tr><td>${esc(h.nivel ?? '')}</td><td>${esc(h.curso ?? '')}</td><td>${esc(h.instituicao ?? '')}</td><td>${esc(String(h.ano_conclusao ?? ''))}</td><td>${esc(h.estado ?? '')}</td></tr>`).join('')}
    </tbody></table>` : '<p style="color:#64748b;margin:4px 0">Sem habilitações registadas.</p>'}

    ${titulo('Contrato em vigor')}
    ${contrato ? `<table class="fci-t">
      ${linha([['Início', v(dataPt(contrato.data_inicio))], ['Fim', v(semFim(contrato.data_fim) ? 'Sem termo' : dataPt(contrato.data_fim))]])}
      ${linha([['Estado', v(contrato.estado)], ['Dias / mês', v(contrato.dias_contrato_mes)]])}
      ${linha([['Horas / dia', v(contrato.horas_por_dia)], ['', '']])}
    </table>` : '<p style="color:#64748b;margin:4px 0">Sem contrato activo.</p>'}

    <div style="display:flex;gap:40px;margin-top:40px">
      <div style="flex:1;text-align:center"><div style="border-top:1px solid #0f172a;padding-top:4px">O Colaborador</div></div>
      <div style="flex:1;text-align:center"><div style="border-top:1px solid #0f172a;padding-top:4px">Recursos Humanos</div></div>
    </div>
  </div>`;
}
