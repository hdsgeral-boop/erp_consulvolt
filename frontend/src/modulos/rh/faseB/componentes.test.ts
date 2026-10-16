import { describe, expect, it } from 'vitest';
import type { ContratoTrabalho } from '../api';
import { accoesEstadoContrato } from '../contratos/EstadoContrato';
import { horarioParaApi, horarioParaForm } from '../contratos/HorarioContrato';
import { autorizacaoDescontoHtml } from '../Emprestimos';
import type { Emprestimo } from './tipos';

const contrato = (estado: ContratoTrabalho['estado'], extra: Partial<ContratoTrabalho> = {}): ContratoTrabalho => ({
  id: 1, colaborador_id: 9, remuneracoes: [], dias_contrato_mes: 22, data_inicio: '2020-01-01', data_fim: null, estado, horas_por_dia: '8', codigo_moeda: 'AOA', produtividade: [], ...extra,
});

const titulos = (c: ContratoTrabalho, perms: string[]) => accoesEstadoContrato(c, (p) => perms.includes(p), () => undefined).filter(Boolean).map((a) => (a as { titulo: string }).titulo);

describe('Fase B — acções de estado do contrato', () => {
  it('suspender exige contratos_suspender; cessar e anular exigem contratos_terminate', () => {
    expect(titulos(contrato('ACTIVO'), ['contratos_suspender'])).toEqual(['Suspender contrato']);
    expect(titulos(contrato('ACTIVO'), ['contratos_terminate'])).toEqual(['Cessar contrato (Lei 12/23)']);
    expect(titulos(contrato('SUSPENSO'), ['contratos_suspender', 'contratos_terminate'])).toEqual(['Ver estado e histórico', 'Reactivar contrato', 'Cessar contrato (Lei 12/23)']);
    expect(titulos(contrato('CESSADO'), ['contratos_suspender', 'contratos_terminate'])).toEqual(['Ver estado e histórico', 'Anular cessação']);
    expect(titulos(contrato('CESSADO'), [])).toEqual(['Ver estado e histórico']);
  });
});

describe('Fase B — horário no formulário do contrato', () => {
  it('ida e volta entre o formulário e a API', () => {
    expect(horarioParaForm(null)).toMatchObject({ usar_horario: false });
    const f = horarioParaForm({ entrada: '08:00', saida: '17:30', periodos: 2, saida_intervalo: '12:00', entrada_intervalo: '13:00', tolerancia_min: 10, dias: [1, 2, 3, 4, 5] });
    expect(f.usar_horario).toBe(true);
    expect(horarioParaApi(f)).toEqual({ entrada: '08:00', saida: '17:30', periodos: 2, saida_intervalo: '12:00', entrada_intervalo: '13:00', intervalo_min: 0, tolerancia_min: 10, dias: [1, 2, 3, 4, 5] });
    expect(horarioParaApi({ ...f, usar_horario: false })).toBeNull();
    // 1 período: as picagens do intervalo não seguem para o servidor
    expect(horarioParaApi({ usar_horario: true, horario: { entrada: '08:00', saida: '17:00', periodos: 1, intervalo_min: 60, saida_intervalo: '12:00' } }))
      .toMatchObject({ periodos: 1, intervalo_min: 60, saida_intervalo: null });
  });
});

describe('Fase B — autorização de desconto', () => {
  it('escapa o texto e lista o plano', () => {
    const e = { id: 1, colaborador_id: 9, numero: 'EMP 2026/001', capital: '120000.00', prestacoes: 3, taxa_juro: '0.0000', valor_prestacao: '40000.00', inicio_desconto: '2026-11',
      motivo: null, estado: 'APROVADO', total_juros: '0.00', total_a_pagar: '120000.00',
      prestacoes_plano: [{ numero: 1, mes_ano: '2026-11', capital: '40000.00', juro: '0.00', total: '40000.00', saldo: '80000.00' }] } as Emprestimo;
    const html = autorizacaoDescontoHtml(e, 'Ana <Fictícia>', 'Empresa & Filhos');
    expect(html).toContain('Ana &lt;Fictícia&gt;');
    expect(html).toContain('Empresa &amp; Filhos');
    expect(html).toContain('EMP 2026/001');
    expect(html).toContain('sem juros');
  });
});
