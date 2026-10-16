import dayjs from 'dayjs';
import { describe, expect, it } from 'vitest';
import { dadosAditamento, dadosEquipamento, dadosHoras, dadosLinhaOrcamento, dadosMembro, dadosPosicao, dadosProjecto, simNao, validarLinhaMembro, validarLinhaProjecto } from './linhas';

describe('projectos — regras comuns à Grade e às Linhas', () => {
  it('projecto: calendário por omissão, externos sem UN/CC forçados e estado retirado na edição', () => {
    const d = dadosProjecto({ tipo: 'EXTERNO', nome: 'Obra', codigo: '  ', cliente_id: 3, encomenda_venda_id: 9, estado: 'ACTIVO' });
    expect(d).toEqual(expect.objectContaining({ codigo: null, cliente_id: 3, encomenda_venda_id: 9, unidade_negocio_id: null, centro_custo_id: null, descricao: null, estado: 'ACTIVO' }));
    expect(d.calendario).toEqual({ modelo: 'SEG_SEX', horas_dia: 8, dias: expect.any(Array) });
    expect(dadosProjecto({ tipo: 'INTERNO', nome: 'X', cliente_id: 3, estado: 'ACTIVO' }, true)).not.toHaveProperty('estado');
    expect(dadosProjecto({ tipo: 'INTERNO', nome: 'X', cliente_id: 3 }).cliente_id).toBeNull();
    expect(validarLinhaProjecto('unidade_negocio_id', null, { tipo: 'INTERNO' })).toMatch(/obrigatória/);
    expect(validarLinhaProjecto('unidade_negocio_id', null, { tipo: 'EXTERNO' })).toBeNull();
    expect(validarLinhaProjecto('cliente_id', null, { tipo: 'EXTERNO' })).toBe('Indique o cliente.');
  });

  it('membro: só a coluna do tipo segue para o servidor', () => {
    expect(dadosMembro({ tipo: 'INTERNO', colaborador_id: 5, terceiro_id: 7, nome_externo: 'X', papel: '', horas_alocadas: 8 }))
      .toEqual({ tipo: 'INTERNO', colaborador_id: 5, terceiro_id: null, nome_externo: null, papel: null, horas_alocadas: 8 });
    expect(validarLinhaMembro('terceiro_id', null, { tipo: 'TERCEIRO' })).toBe('Escolha o terceiro.');
    expect(validarLinhaMembro('colaborador_id', null, { tipo: 'LIVRE' })).toBeNull();
  });

  it('horas e equipamento: data em dayjs (grade) ou texto (linhas)', () => {
    expect(dadosHoras({ membro_equipa_projeto_id: 1, tarefa_projeto_id: 2, data: dayjs('2026-10-03'), horas: 4 })).toEqual({ membro_equipa_projeto_id: 1, tarefa_projeto_id: 2, data: '2026-10-03', horas: 4 });
    expect(dadosEquipamento({ ativo_imobilizado_id: 3, tarefa_projeto_id: 2, data: '2026-10-04', horas: '2', custo_hora: 1500 })).toEqual({ ativo_imobilizado_id: 3, tarefa_projeto_id: 2, data: '2026-10-04', horas: 2, custo_hora: 1500 });
  });

  it('orçamento, aditamento e posição', () => {
    expect(dadosLinhaOrcamento({ rubrica: 'MAO_DE_OBRA', numero_conta: '62', montante: 100 })).toEqual({ tarefa_projeto_id: null, rubrica: 'MAO_DE_OBRA', numero_conta: null, montante: 100 });
    expect(dadosAditamento({ descricao: 'X', montante: null })).toEqual({ descricao: 'X', montante: null, estado: 'PENDENTE' });
    expect(dadosPosicao({ titulo: 'Chefe', vagas: null, apoio: true })).toEqual({ titulo: 'Chefe', area: null, descricao: null, vagas: 0, no_pai_id: null, cor: 'azul', apoio: true });
    expect([simNao('Sim'), simNao('não'), simNao('x'), simNao('talvez')]).toEqual([true, false, true, undefined]);
  });
});
