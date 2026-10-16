import { render, screen } from '@testing-library/react';
import { avaliarSemaforo } from './regraSemaforo';
import { Semaforo } from './Semaforo';
import type { IndicadoresSemaforo } from './tipos';

const ok: IndicadoresSemaforo = { tarefas: 10, tarefas_atrasadas: 0, execucao: 50, desempenho: 1, restantes_dias: 30, orcamento: 1000, consumo_pct: 40, rubricas_excedidas: 0 };

describe('Semáforo da carteira (regra dos alertas de prazo e orçamento do Resumo)', () => {
  it('verde: dentro do prazo e do orçamento', () => {
    expect(avaliarSemaforo('ACTIVO', ok).cor).toBe('verde');
  });

  it('vermelho: tarefas em atraso ou orçamento ultrapassado', () => {
    expect(avaliarSemaforo('ACTIVO', { ...ok, tarefas_atrasadas: 2 })).toMatchObject({ cor: 'vermelho', motivos: ['2 tarefa(s) em atraso.'] });
    expect(avaliarSemaforo('EM_CURSO', { ...ok, consumo_pct: 100.5 }).cor).toBe('vermelho');
    // vermelho ganha ao âmbar e mostra os dois motivos
    expect(avaliarSemaforo('ACTIVO', { ...ok, tarefas_atrasadas: 1, rubricas_excedidas: 1 }).motivos).toHaveLength(2);
  });

  it('âmbar: ritmo < 90 %, orçamento quase esgotado (≥ 85 % com execução < 85 %) ou rubricas acima do orçado', () => {
    expect(avaliarSemaforo('ACTIVO', { ...ok, desempenho: 0.89 }).cor).toBe('ambar');
    expect(avaliarSemaforo('ACTIVO', { ...ok, consumo_pct: 85, execucao: 84 }).cor).toBe('ambar');
    expect(avaliarSemaforo('ACTIVO', { ...ok, rubricas_excedidas: 1 }).cor).toBe('ambar');
    // limites: 100 % exactos e 85 % com execução ≥ 85 % não são alerta (como no Resumo)
    expect(avaliarSemaforo('ACTIVO', { ...ok, consumo_pct: 100, execucao: 90 }).cor).toBe('verde');
    expect(avaliarSemaforo('ACTIVO', { ...ok, desempenho: 0.9 }).cor).toBe('verde');
    // sem tarefas o ritmo não conta (o alerta do Resumo exige tarefas)
    expect(avaliarSemaforo('ACTIVO', { ...ok, tarefas: 0, desempenho: 0.1 }).cor).toBe('verde');
  });

  it('cinzento: encerrado, cancelado, sem indicadores ou sem tarefas nem orçamento', () => {
    expect(avaliarSemaforo('ENCERRADO', { ...ok, tarefas_atrasadas: 3 }).cor).toBe('cinzento');
    expect(avaliarSemaforo('CANCELADO', ok).cor).toBe('cinzento');
    expect(avaliarSemaforo('ACTIVO', null).cor).toBe('cinzento');
    expect(avaliarSemaforo('PREPARACAO', { ...ok, tarefas: 0, orcamento: 0 }).cor).toBe('cinzento');
  });

  it('o componente anuncia a cor e o motivo (dica acessível)', () => {
    render(<Semaforo estado="ACTIVO" indicadores={{ ...ok, consumo_pct: 120 }} />);
    expect(screen.getByRole('img', { name: /Em atraso ou acima do orçamento\. Orçamento ultrapassado \(120% consumido\)\./ })).toHaveAttribute('data-cor', 'vermelho');
  });
});
