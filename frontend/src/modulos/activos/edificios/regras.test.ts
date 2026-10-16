import { describe, expect, it } from 'vitest';
import { htmlFolha, htmlPlanta } from './impressao';
import { filtrarBens, marcarTodosEncontrados, nomeAndar, nomeAndarPorOmissao, previaPortas, textoLocal, textoLocalFicha } from './regras';
import type { FolhaInventario, OpcoesLocais, Planta } from './tipos';

// dados fictícios
const OPCOES: OpcoesLocais = {
  edificios: [{ id: 1, codigo: 'AST-100', descricao: 'Sede fictícia' }],
  andares: [{ id: 10, edificio_id: 1, numero: '0', nome: 'Rés-do-chão', ordem: 0 }],
  portas: [{ id: 100, edificio_id: 1, andar_id: 10, numero: 'A1', nome: null, tipo: 'Gabinete' }],
};

describe('Edifícios e Locais — regras (js/activos_edificios.js)', () => {
  it('nome do andar por omissão como no legado', () => {
    expect(nomeAndarPorOmissao('0')).toBe('Rés-do-chão');
    expect(nomeAndarPorOmissao(2)).toBe('2.º andar');
    expect(nomeAndarPorOmissao('C1')).toBe('Piso C1');
    expect(nomeAndar({ nome: null, numero: '3' })).toBe('Piso 3');
  });

  it('pré-visualização das portas em série: máximo 200 e intervalo válido', () => {
    expect(previaPortas('A', 1, 6)).toEqual({ valida: true, n: 6, texto: 'Vão ser criadas 6 porta(s): A1, A2, A3 … A6' });
    expect(previaPortas('', 4, 4).texto).toBe('Vão ser criadas 1 porta(s): 4');
    expect(previaPortas('A', 5, 2).valida).toBe(false);
    expect(previaPortas('A', 1, 201)).toMatchObject({ valida: false, n: 201 });
  });

  it('texto da localização, procura e «marcar todos encontrados»', () => {
    expect(textoLocal(OPCOES, 100)).toBe('AST-100 · Rés-do-chão · A1');
    expect(textoLocal(OPCOES, null)).toBe('');
    expect(textoLocal([] as unknown as OpcoesLocais, 100)).toBe('');
    // ADR-071 (A2): no edifício sem porta (andar opcional)
    expect(textoLocal(OPCOES, null, 1)).toBe('AST-100 · sem porta');
    expect(textoLocal(OPCOES, null, 1, 10)).toBe('AST-100 · Rés-do-chão · sem porta');
    expect(textoLocalFicha({ tipo_ativo: 'EDIFICIO' })).toBe('É um edifício / local');
    expect(textoLocalFicha({ tipo_ativo: 'BEM', edificio: null })).toBe('Sem localização');
    expect(textoLocalFicha({ edificio: { codigo: 'AST-100', descricao: 'Sede fictícia' }, andar: { numero: '0', nome: 'Rés-do-chão' }, porta: { numero: 'A1', nome: null } }))
      .toBe('AST-100 — Sede fictícia › Rés-do-chão › Porta A1');
    expect(textoLocalFicha({ edificio: { codigo: 'AST-100', descricao: 'Sede fictícia' } })).toBe('AST-100 — Sede fictícia › sem porta');
    expect(filtrarBens([{ codigo: 'AST-1', descricao: 'Mesa' }, { codigo: 'AST-2', descricao: 'Cadeira' }], 'cad')).toHaveLength(1);
    const linhas = marcarTodosEncontrados([{ id: 1, resultado: null }, { id: 2, resultado: 'EM_FALTA' as const }]);
    expect(linhas.map((l) => l.resultado)).toEqual(['ENCONTRADO', 'EM_FALTA']);
  });

  it('a impressão escapa o texto (sem HTML injectado)', () => {
    const planta: Planta = {
      edificio: { id: 1, codigo: 'AST-100', descricao: 'Sede', estado: 'ACTIVO' },
      andares: [{ id: 10, numero: '0', nome: '<b>Piso</b>', notas: null, ordem: 0, n_portas: 1, n_bens: 1, valor: '10.00',
        portas: [{ id: 100, numero: 'A1', nome: null, tipo: null, capacidade: null, ordem: 1, andar_id: 10, n_bens: 1, valor: '10.00',
          bens: [{ id: 5, codigo: 'AST-5', descricao: '<script>x</script>', valor_aquisicao: '10.00' }] }] }],
      sem_local: [], totais: { andares: 1, portas: 1, bens: 1, valor: '10.00' },
    };
    const html = htmlPlanta(planta);
    expect(html).not.toContain('<script>');
    expect(html).toContain('&lt;script&gt;');
    const folha: FolhaInventario = {
      inventario: { id: 7, numero: 'INV-7', edificio_id: 1, ambito: 'EDIFICIO', ambito_texto: 'Todo o edifício', andar_id: null, porta_id: null, data: '2026-10-14',
        responsavel: 'Fictício', notas: null, estado: 'ABERTO', esperados: 1, valor_esperado: '10.00', aplicado_em: null, fechado_em: null, fechado_por: null, motivo_anulacao: null,
        edificio: { id: 1, codigo: 'AST-100', descricao: 'Sede' } },
      linhas: [{ id: 1, ativo_imobilizado_id: 5, codigo: 'AST-5', descricao: 'Mesa', porta_id: 100, valor: '10.00', resultado: 'DESLOCADO', porta_encontrada_id: 101,
        notas: null, local_esperado: 'Rés-do-chão · Porta A1', local_encontrado: 'Rés-do-chão · Porta A2' }],
      portas: [],
    };
    expect(htmlFolha(folha, false)).toContain('☐ Sim ☐ Não');
    expect(htmlFolha(folha, true)).toContain('Noutro local');
    expect(htmlFolha(folha, true)).toContain('A contabilidade');
  });
});
