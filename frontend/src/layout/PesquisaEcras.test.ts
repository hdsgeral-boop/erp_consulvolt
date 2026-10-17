import { describe, expect, it } from 'vitest';
import type { ModuloMenu } from '@/api/tipos';
import { funcoesDoMenu, pesquisarSistema, prepararIndiceAccoes, procurarAccoes, procurarFuncoes, trechosRealce, type FuncaoSistema } from './PesquisaEcras';
import type { EntradaIndicePesquisa } from './indicePesquisa.gerado';

const ecra = (id: string, nome = id) => ({ id, nome, vistas: [], pai: null });
const MENU: ModuloMenu[] = [
  { id: 'vendas', nome: 'Vendas e Facturação', ecras: [ecra('vendas_faturacao', 'Facturação'), ecra('vendas_clientes', 'Clientes')] },
  { id: 'teso', nome: 'Tesouraria', ecras: [ecra('teso_reconciliacao', 'Reconciliação bancária')] },
];
const FUNCOES: FuncaoSistema[] = [
  { modulo: 'vendas', ecra: 'vendas_faturacao', chave: 'vendas_recibos', rotulo: 'Emitir e anular recibos (liquidar facturas)' },
  { modulo: 'vendas', ecra: 'vendas_faturacao', chave: 'vendas_fat_del', rotulo: 'Anular documentos de venda' },
  { modulo: 'vendas', ecra: 'vendas_clientes', chave: 'vendas_clientes_del', rotulo: 'Eliminar clientes' },
  { modulo: 'teso', ecra: 'teso_reconciliacao', chave: 'teso_rec_validar', rotulo: 'Validar a reconciliação' },
  // ecrã que não está no menu do utilizador: nunca aparece, mesmo com a permissão
  { modulo: 'rh', ecra: 'colaboradores', chave: 'rh_colab_del', rotulo: 'Eliminar colaboradores' },
];
const todas = { eFavorito: () => false, pode: () => true, funcoes: FUNCOES };

describe('pesquisa da barra superior: funções dentro dos ecrãs', () => {
  it('encontra pela função, sem acentos e com várias palavras, e indica «Módulo › Ecrã» e a rota do ecrã', () => {
    const r = procurarFuncoes(MENU, 'ANULAR recibos', todas);
    expect(r).toEqual([
      { chave: 'vendas_recibos', nome: 'Emitir e anular recibos (liquidar facturas)', modulo: 'vendas', ecra: 'vendas_faturacao', nomeModulo: 'Vendas', nomeEcra: 'Faturação', rota: '/m/vendas/vendas_faturacao', favorito: false },
    ]);
    expect(procurarFuncoes(MENU, 'reconciliacao', todas).map((f) => f.chave)).toEqual(['teso_rec_validar']);
    // a palavra pode estar no ecrã ou no módulo; as que a têm no nome da função vêm primeiro
    expect(procurarFuncoes(MENU, 'tesouraria validar', todas).map((f) => f.chave)).toEqual(['teso_rec_validar']);
    expect(procurarFuncoes(MENU, '', todas)).toEqual([]);
  });

  it('só mostra as funções que o utilizador pode fazer e cujo ecrã está no seu menu', () => {
    const pode = (c: string) => c === 'vendas_fat_del' || c === 'rh_colab_del';
    expect(procurarFuncoes(MENU, 'anular', { ...todas, pode }).map((f) => f.chave)).toEqual(['vendas_fat_del']);
    expect(procurarFuncoes(MENU, 'eliminar', { ...todas, pode }).map((f) => f.chave)).toEqual([]);
    expect(procurarFuncoes(MENU, 'colaboradores', todas)).toEqual([]);
    expect(procurarFuncoes([], 'anular', todas)).toEqual([]);
  });

  it('ecrãs favoritos primeiro e limite de resultados', () => {
    const eFavorito = (m: string, e: string) => m === 'vendas' && e === 'vendas_clientes';
    expect(procurarFuncoes(MENU, 'vendas', { ...todas, eFavorito }).map((f) => f.chave)[0]).toBe('vendas_clientes_del');
    expect(procurarFuncoes(MENU, 'vendas', { ...todas, limiteFuncoes: 2 })).toHaveLength(2);
  });

  it('pesquisarSistema agrupa ecrãs e funções; sem termo mostra só os ecrãs favoritos', () => {
    const r = pesquisarSistema(MENU, 'faturacao', todas);
    expect(r.ecras.map((e) => e.rota)).toEqual(['/m/vendas/vendas_faturacao']);
    expect(r.funcoes.map((f) => f.chave)).toEqual(expect.arrayContaining(['vendas_recibos', 'vendas_fat_del']));
    const vazio = pesquisarSistema(MENU, '  ', { ...todas, eFavorito: (_m, e) => e === 'teso_reconciliacao' });
    expect(vazio).toEqual({ ecras: [expect.objectContaining({ ecra: 'teso_reconciliacao', favorito: true })], funcoes: [], accoes: [] });
  });

  it('por omissão o índice vem do menu do servidor (tarefas permitidas de cada ecrã) e respeita pode()', () => {
    const menu: ModuloMenu[] = [{ id: 'vendas', nome: 'Vendas e Facturação', ecras: [{ ...ecra('vendas_faturacao', 'Facturação'),
      tarefas: [{ chave: 'vendas_recibos', rotulo: 'Emitir e anular recibos (liquidar facturas)' }] }] }];
    expect(funcoesDoMenu(menu)).toEqual([{ modulo: 'vendas', ecra: 'vendas_faturacao', chave: 'vendas_recibos', rotulo: 'Emitir e anular recibos (liquidar facturas)' }]);
    expect(procurarFuncoes(menu, 'recibos', { eFavorito: () => false, pode: () => true }).map((f) => f.chave)).toEqual(['vendas_recibos']);
    expect(procurarFuncoes(menu, 'recibos', { eFavorito: () => false, pode: () => false })).toEqual([]);
    expect(procurarFuncoes(MENU, 'recibos', { eFavorito: () => false, pode: () => true })).toEqual([]);   // ecrãs sem tarefas no menu
  });

  it('realce: todos os pedaços encontrados, sem acentos, juntos quando se sobrepõem', () => {
    expect(trechosRealce('Emitir e anular recibos', 'recibo ANULAR')).toEqual([
      { inicio: 9, fim: 15 },
      { inicio: 16, fim: 22 },
    ]);
    expect(trechosRealce('Reconciliação', 'conciliacao')).toEqual([{ inicio: 2, fim: 13 }]);
    expect(trechosRealce('abc', '')).toEqual([]);
  });

  describe('botões e separadores (índice gerado)', () => {
    const INDICE: EntradaIndicePesquisa[] = [
      ['vendas_faturacao', 'b', 'Contabilizar seleccionados', 'vendas_fat_contabilizar'],
      ['vendas_faturacao', 'b', 'Emitir factura', 'vendas_fat_emitir|vendas_fat_post'],
      ['vendas_faturacao', 'b', 'Exportar SAF-T', ''],
      ['vendas_clientes', 's', 'Contas correntes', '', 'separador=contas'],
      ['teso_reconciliacao', 'b', 'Validar extracto', 'teso_rec_validar;teso_ver'],
      ['colaboradores', 'b', 'Exportar colaboradores', ''],   // ecrã fora do menu
    ];
    const accoes = prepararIndiceAccoes(INDICE, MENU);

    it('só ecrãs do menu do utilizador; «Botão/Separador › Ecrã › Módulo» com a rota do ecrã e do separador', () => {
      expect(accoes.map((a) => a.ecra)).not.toContain('colaboradores');
      const r = procurarAccoes('contas correntes', { ...todas, accoes });
      expect(r).toEqual([{ tipo: 'separador', nome: 'Contas correntes', modulo: 'vendas', ecra: 'vendas_clientes', nomeModulo: 'Vendas', nomeEcra: 'Clientes', rota: '/m/vendas/vendas_clientes?separador=contas', favorito: false }]);
      expect(procurarAccoes('exportar', { ...todas, accoes }).map((a) => a.nome)).toEqual(['Exportar SAF-T']);
    });

    it('respeita a permissão conhecida: «|» basta uma, «;» todas', () => {
      const pode = (c: string) => ['vendas_fat_post', 'teso_rec_validar'].includes(c);
      expect(procurarAccoes('emitir', { ...todas, accoes, pode }).map((a) => a.nome)).toEqual(['Emitir factura']);
      expect(procurarAccoes('contabilizar', { ...todas, accoes, pode })).toEqual([]);
      expect(procurarAccoes('validar', { ...todas, accoes, pode })).toEqual([]);
      expect(procurarAccoes('validar', { ...todas, accoes, pode: () => true }).map((a) => a.nome)).toEqual(['Validar extracto']);
    });

    it('pelo menos uma palavra no rótulo (o resto pode estar no ecrã/módulo); sem acentos', () => {
      expect(procurarAccoes('contabilizar faturacao', { ...todas, accoes }).map((a) => a.nome)).toEqual(['Contabilizar seleccionados']);
      expect(procurarAccoes('faturacao', { ...todas, accoes })).toEqual([]);
      expect(procurarAccoes('EXTRACTO', { ...todas, accoes }).map((a) => a.nome)).toEqual(['Validar extracto']);
      expect(pesquisarSistema(MENU, 'saf-t', { ...todas, accoes }).accoes.map((a) => a.nome)).toEqual(['Exportar SAF-T']);
    });
  });
});
