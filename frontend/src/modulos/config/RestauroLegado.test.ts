import { describe, expect, it } from 'vitest';
import { ESTADOS_RESTAURO, podeAprovar } from './RestauroLegado';

describe('Restauro do sistema antigo — regras do ecrã', () => {
  const base = { estado: 'SIMULADO', pedido_por: { id: 1, nome: 'Quem pediu' }, simulacao: { sem_problemas: true } } as Parameters<typeof podeAprovar>[0];

  it('só outro super-administrador aprova, e só uma simulação sem problemas', () => {
    expect(podeAprovar(base, 2)).toBe(true);
    expect(podeAprovar(base, 1)).toBe(false);
    expect(podeAprovar({ ...base, simulacao: { ...base.simulacao!, sem_problemas: false } }, 2)).toBe(false);
    expect(podeAprovar({ ...base, estado: 'SIMULANDO' }, 2)).toBe(false);
  });

  it('todos os estados do servidor têm rótulo', () => {
    for (const e of ['SIMULANDO', 'SIMULADO', 'SIMULACAO_FALHADA', 'APROVADO', 'EM_EXECUCAO', 'CONCLUIDO', 'FALHADO', 'CANCELADO']) expect(ESTADOS_RESTAURO[e]?.rotulo).toBeTruthy();
  });
});
