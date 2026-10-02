import { describe, expect, it } from 'vitest';
import { colunasDetalhe, filtrarValidacoes, formatarCelula, ligacaoDaValidacao, ordenarValidacoes, resumirGravidades, rotaDaLinha, rotuloColuna, type ResumoValidacao } from './regras';

const v = (codigo: string, gravidade: string, ocorrencias: number, modulo = 'Contabilidade'): ResumoValidacao => ({
  codigo, gravidade, ocorrencias, modulo, titulo: `Título ${codigo}`, descricao: `Descrição de ${codigo} (não é acção)`, legado: 'ui_reports.js:381',
});

const lista = [v('a_info', 'INFO', 38), v('b_aviso', 'AVISO', 2, 'Logística'), v('c_erro', 'ERRO', 0), v('d_erro', 'ERRO', 5), v('e_aviso', 'AVISO', 9, 'RH')];

describe('validações de dados (A-01)', () => {
  it('ordena erros, avisos e informações, e dentro de cada pelas ocorrências', () => {
    expect(ordenarValidacoes(lista).map((x) => x.codigo)).toEqual(['d_erro', 'c_erro', 'e_aviso', 'b_aviso', 'a_info']);
  });

  it('resume só as validações com ocorrências', () => {
    expect(resumirGravidades(lista)).toEqual({ porGravidade: { ERRO: 1, AVISO: 2, INFO: 1 }, ocorrencias: 54, comOcorrencias: 4, total: 5 });
  });

  it('filtra por gravidade, módulo, ocorrências e pesquisa sem acentos', () => {
    expect(filtrarValidacoes(lista, { soComOcorrencias: true }).map((x) => x.codigo)).not.toContain('c_erro');
    expect(filtrarValidacoes(lista, { gravidade: 'AVISO', modulo: 'RH' }).map((x) => x.codigo)).toEqual(['e_aviso']);
    expect(filtrarValidacoes(lista, { pesquisa: 'DESCRICAO DE D_ERRO' }).map((x) => x.codigo)).toEqual(['d_erro']);
  });

  it('junta as colunas das linhas pela ordem em que aparecem', () => {
    expect(colunasDetalhe([{ a: 1, b: 2 }, { b: 3, c: 4 }])).toEqual(['a', 'b', 'c']);
    expect(rotuloColuna('codigo_conta')).toBe('Conta');
    expect(rotuloColuna('estado_liquidacao')).toBe('Estado liquidacao');
    expect(rotuloColuna('produto_id')).toBe('Produto');
  });

  it('formata as células (Kz, datas, booleanos, ids e códigos sem separadores)', () => {
    expect(formatarCelula('diferenca', '-1250.5')).toMatch(/1\s?250,50/);
    expect(formatarCelula('quantidade_stock', '-3')).toBe('-3');
    expect(formatarCelula('linha_id', 123456)).toBe('123456');
    expect(formatarCelula('codigo_conta', '6211')).toBe('6211');
    expect(formatarCelula('data_documento', '2026-03-31')).toBe('31/03/2026');
    expect(formatarCelula('aberto_em', '2026-03-31 08:15:00')).toBe('31/03/2026 08:15');
    expect(formatarCelula('contabilizado', true)).toBe('Sim');
    expect(formatarCelula('x', null)).toBe('—');
  });

  it('liga ao ecrã certo só quando a coluna identifica esse registo', () => {
    expect(ligacaoDaValidacao('lancamentos_desequilibrados', ['diario', 'linha_id'])).toMatchObject({ modulo: 'contab', ecra: 'lancamentos' });
    // em projectos e reconciliações a «linha_id» é de outra tabela
    expect(ligacaoDaValidacao('projetos_autos_sem_factura', ['linha_id'])).toBeNull();
    expect(ligacaoDaValidacao('reconciliacoes_tesouraria_orfas', ['linha_id'])).toBeNull();
    expect(ligacaoDaValidacao('crm_vendas_cliente_divergente', ['oportunidade_id', 'venda_id'])).toMatchObject({ ecra: 'vendas_faturacao', coluna: 'venda_id' });
    expect(ligacaoDaValidacao('folhas_salariais_vs_diario', ['periodo_id'])).toMatchObject({ modulo: 'rh', ecra: 'processamento' });
    expect(ligacaoDaValidacao('ad_periodos_sem_lancamento', ['periodo_id', 'item_id'])).toMatchObject({ ecra: 'ad_registos', coluna: 'item_id' });
    const l = ligacaoDaValidacao('ativos_sem_categoria_ou_contas', ['ativo_id']);
    expect(rotaDaLinha(l, { ativo_id: 42 })).toBe('/m/activos/activos/42');
    expect(rotaDaLinha(l, { ativo_id: null })).toBeNull();
    expect(rotaDaLinha(null, { ativo_id: 42 })).toBeNull();
  });
});
