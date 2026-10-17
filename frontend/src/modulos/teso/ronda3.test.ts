import { describe, expect, it } from 'vitest';
import { bloqueioEdicao, bloqueioExclusao, TEXTO_RECIBO_VENDA } from './colunasLegado';
import { podeImportarCaixa, type ResultadoImportacaoCaixa } from './caixa/ModalImportacaoCaixa';
import { accoesDocumento } from './regras';
import { eContaCaixa, emModoRecente, filtroAplicado, prefixosDaChave, proximoLimite, textoRecentes } from './ronda3';
import type { DocumentoTesouraria } from './api';

const todas = () => true;

describe('tesouraria — ronda 3', () => {
  it('Operações abre só com o registo mais recente enquanto não há filtro (legado: docs = [realDocs[0]])', () => {
    expect(filtroAplicado({})).toBe(false);
    expect(filtroAplicado({ pesquisa: '   ' })).toBe(false);
    expect(filtroAplicado({ pesquisa: 'TRF' })).toBe(true);
    expect(filtroAplicado({ estado: 'PENDENTE' })).toBe(true);
    expect(filtroAplicado({ conta: '4311' })).toBe(true);
    expect(filtroAplicado({ inicio: '2026-01-01' })).toBe(true);
    expect(emModoRecente({}, false)).toBe(true);
    expect(emModoRecente({}, true)).toBe(false);
    expect(emModoRecente({ pesquisa: 'x' }, false)).toBe(false);
  });

  it('«Ver mais» carrega mais registos sem passar do total', () => {
    expect(proximoLimite(1)).toBe(10);
    expect(proximoLimite(10)).toBe(20);
    expect(proximoLimite(10, 14)).toBe(14);
    expect(proximoLimite(1, 0)).toBe(10);
    expect(textoRecentes(1, 37)).toBe('A mostrar o registo mais recente (1 de 37).');
    expect(textoRecentes(10, 37)).toBe('A mostrar os 10 registos mais recentes (10 de 37).');
    expect(textoRecentes(10, 3)).toBe('A mostrar os 3 registos mais recentes (3 de 3).');
    expect(textoRecentes(1, undefined)).toBe('A mostrar o registo mais recente.');
  });

  it('sobras só 68 e quebras só 78; abrir sessão só em contas 45', () => {
    expect(prefixosDaChave('caixa_sobras')).toEqual(['68']);
    expect(prefixosDaChave('caixa_quebras')).toEqual(['78']);
    expect(prefixosDaChave('caixa_quebras', ['78'])).toEqual(['78']);
    expect(prefixosDaChave('diferencas_cambio_favoraveis')).toBeUndefined();
    expect(eContaCaixa('4511')).toBe(true);
    expect(eContaCaixa(' 45.1')).toBe(true);
    expect(eContaCaixa('4311')).toBe(false);
    expect(eContaCaixa(undefined)).toBe(false);
  });

  it('o recebimento de um recibo de venda não se edita nem anula na Tesouraria, mas integra-se', () => {
    const doc = { id: 1, estado: 'PENDENTE', recibo_venda_id: 9, periodo_processamento_salarial_id: null } as unknown as DocumentoTesouraria;
    expect(bloqueioEdicao(doc)).toBe(TEXTO_RECIBO_VENDA);
    expect(bloqueioExclusao(doc)).toBe(TEXTO_RECIBO_VENDA);
    expect(accoesDocumento(doc, todas)).toEqual({ podeEditar: false, podeAnular: false, podeIntegrar: true, podeDesintegrar: false });
    const normal = { ...doc, recibo_venda_id: null } as DocumentoTesouraria;
    expect(bloqueioEdicao(normal)).toBe(false);
    expect(accoesDocumento(normal, todas).podeEditar).toBe(true);
    expect(bloqueioEdicao({ ...doc, estado: 'ANULADO' } as DocumentoTesouraria)).toBe('Documento anulado');
  });

  it('a importação da Folha de Caixa só se confirma depois de uma pré-visualização sem erros', () => {
    const base: ResultadoImportacaoCaixa = { simulacao: true, importado: false, sessao_id: 1, linhas_lidas: 2, total_entradas: '0', total_saidas: '0', erros: [],
      validas: [{ linha: 2, id: null, data: '2026-10-16', tipo: 'REC', numero_documento: null, descricao: 'x', conta_contrapartida: '3111', valor: '1.00' }] };
    expect(podeImportarCaixa(null)).toBe(false);
    expect(podeImportarCaixa(base)).toBe(true);
    expect(podeImportarCaixa({ ...base, erros: [{ linha: 3, mensagem: 'Linha 3: erro' }] })).toBe(false);
    expect(podeImportarCaixa({ ...base, validas: [] })).toBe(false);
    expect(podeImportarCaixa({ ...base, simulacao: false })).toBe(false);
  });
});
