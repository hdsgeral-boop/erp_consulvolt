import { describe, expect, it } from 'vitest';
import { agrupar, avisoPrazo, chaveResp, onde, resumoCusto, tarefasDosRecursos, validar } from './regras';
import type { DadosAssunto, ResumoAssunto } from './tipos';

// dados fictícios
const base = (d: Partial<DadosAssunto> = {}): DadosAssunto => ({
  titulo: 'Substituir o quadro eléctrico', responsaveis: [{ tipo: 'COLABORADOR', ref_id: 7, nome: 'Ana Fictícia' }], responsavel_principal: 'COLABORADOR:7',
  data_inicio: '2026-11-01', data_fim: '2026-11-10', local_tipo: 'INTERNO', unidade_negocio_id: 1,
  fundamentacao: 'O quadro actual não cumpre as normas e já causou duas paragens de produção.', recursos: 'Quadro novo', custo_estimado: 1000,
  metodo_resolucao: 'ACCAO', gerar_pedido_compra: false, linhas_pedido_compra: [], anexos: [], ...d,
});

const linha = (d: Partial<ResumoAssunto>): ResumoAssunto => ({
  id: 1, numero: 'AS 2026/001', titulo: 'X', estado: 'RASCUNHO', responsavel_principal_nome: 'Ana', local_tipo: 'INTERNO', unidade_negocio_id: 1,
  unidade_negocio_nome: 'Oficina', cliente_id: null, cliente_nome: null, encomenda: null, data_inicio: '2026-11-01', data_fim: '2026-11-10',
  custo_estimado: '100.00', codigo_moeda: 'AOA', metodo_resolucao: 'ACCAO', projeto_id: null, projeto: null, pedido_compra_id: null, pedido_compra: null,
  deliberacao_estado: null, em_atraso: false, criado_em: null, criado_por: null, ...d,
});

describe('Regras do assunto 5W1H', () => {
  it('chave do responsável como no legado', () => {
    expect(chaveResp({ tipo: 'COLABORADOR', ref_id: 7, nome: 'Ana' })).toBe('COLABORADOR:7');
    expect(chaveResp({ tipo: 'EXTERNO', ref_id: null, nome: ' Electricista ' })).toBe('EXTERNO:Electricista');
  });

  it('em rascunho só o título conta; completo exige as seis perguntas', () => {
    expect(validar(base({ fundamentacao: 'curta' }), true).erros).toEqual([]);
    expect(validar(base({ titulo: '' }), true).erros).toEqual(['WHAT: indique o título do assunto.']);
    expect(validar(base(), false).erros).toEqual([]);
    const e = validar(base({ fundamentacao: 'curta', responsavel_principal: 'COLABORADOR:9', local_tipo: 'EXTERNO' }), false).erros;
    expect(e).toContain('WHY: a fundamentação tem de ter pelo menos 50 caracteres (tem 5).');
    expect(e).toContain('WHO: o responsável principal tem de estar na lista de responsáveis.');
    expect(e).toContain('WHERE: escolha o cliente.');
  });

  it('fornecedor ⇔ pedido de compra, artigos e dimensões do projecto', () => {
    expect(validar(base({ gerar_pedido_compra: true }), false).erros).toEqual([
      'HOW: para gerar pedido de compra é preciso um fornecedor em WHO.', 'HOW: indique pelo menos um artigo para o pedido de compra.']);
    const comForn = base({ responsaveis: [{ tipo: 'FORNECEDOR', ref_id: 3, nome: 'Fornecedor' }], responsavel_principal: 'FORNECEDOR:3' });
    expect(validar(comForn, false).avisos).toEqual(['WHO: há um fornecedor indicado — normalmente isso implica gerar pedido de compra.']);
    expect(validar(base({ metodo_resolucao: 'PROJECTO', projeto_modo: 'NOVO' }), false).erros).toEqual(['HOW: o projecto interno precisa de centro de custo.']);
    expect(validar(base({ metodo_resolucao: 'PROJECTO', projeto_modo: 'EXISTENTE' }), false).erros).toEqual(['HOW: escolha o projecto existente que vai resolver este assunto.']);
  });

  it('prazo: erro, aviso de 48 h e dias', () => {
    expect(avisoPrazo('2026-11-10', '2026-11-01')?.tipo).toBe('erro');
    expect(avisoPrazo('2026-11-01', '2026-11-02')?.tipo).toBe('aviso');
    expect(avisoPrazo('2026-11-01', '2026-11-11')).toEqual({ tipo: 'info', texto: 'Prazo de 10 dia(s).' });
    expect(avisoPrazo(null, '2026-11-11')).toBeNull();
  });

  it('cada linha dos recursos é uma tarefa; sem linhas, o título', () => {
    expect(tarefasDosRecursos('- Bomba nova\n\n• Montagem\r\n* Ensaio', 'T')).toEqual(['Bomba nova', 'Montagem', 'Ensaio']);
    expect(tarefasDosRecursos('', 'Título')).toEqual(['Título']);
  });

  it('vistas agrupadas, prazo por mês e painel de custo', () => {
    const lista = [
      linha({ id: 1, unidade_negocio_nome: 'Oficina', custo_estimado: '100.00', estado: 'EM_CURSO', data_fim: '2026-12-05' }),
      linha({ id: 2, local_tipo: 'EXTERNO', cliente_nome: 'Cliente Fictício', encomenda: 'NE 7', custo_estimado: '50.50', estado: 'CANCELADO', data_fim: '2026-11-20' }),
      linha({ id: 3, unidade_negocio_nome: 'Oficina', custo_estimado: '25.00', data_fim: null }),
    ];
    expect(onde(lista[1])).toBe('Cliente Fictício · NE 7');
    expect(agrupar(lista, 'departamento').map((g) => [g.chave, g.itens.length, g.custo])).toEqual([['— externos —', 1, 50.5], ['Oficina', 2, 125]]);
    expect(agrupar(lista, 'prazo').map((g) => g.chave)).toEqual(['2026-11', '2026-12']);
    expect(agrupar(lista, 'lista')[0].itens).toHaveLength(3);
    expect(resumoCusto(lista)).toEqual({ total: 175.5, comprometido: 100, rascunho: 25, cancelado: 50.5 });
  });
});
