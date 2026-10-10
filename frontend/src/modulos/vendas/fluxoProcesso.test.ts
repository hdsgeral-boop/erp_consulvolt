import { describe, expect, it } from 'vitest';
import { botoesFluxo, corEstadoFluxo, rotuloDocumentoFluxo } from './fluxoProcesso';
import { accoesLinhaDocumento } from './accoesLinhaDocumento';
import type { DocumentoVenda } from './api';

const tudo = () => true;
const nada = () => false;

describe('janela «Fluxo Completo do Processo» (showDocumentWorkflow)', () => {
  it('rótulo e cor do estado como no legado', () => {
    expect(rotuloDocumentoFluxo({ tipo_documento: 'FT', numero_documento: 'FT 2026/1' })).toBe('Factura FT 2026/1');
    expect(rotuloDocumentoFluxo({ tipo_documento: 'ND', numero_documento: 'ND 1' })).toBe('ND ND 1');
    expect(corEstadoFluxo('PAGO')).toBe('success');
    expect(corEstadoFluxo('concluido')).toBe('success');
    expect(corEstadoFluxo('ANULADO')).toBe('error');
    expect(corEstadoFluxo(null)).toBe('warning');
  });

  it('Contabilizar ou Descontabilizar conforme o estado e as permissões', () => {
    expect(botoesFluxo({ tipo_documento: 'FT', estado: 'PENDENTE', contabilizado: false }, tudo)).toEqual({ contabilizar: true, descontabilizar: false, converter: false, emitirRecibo: true });
    expect(botoesFluxo({ tipo_documento: 'FT', estado: 'PAGO', contabilizado: true }, tudo)).toEqual({ contabilizar: false, descontabilizar: true, converter: false, emitirRecibo: false });
    expect(botoesFluxo({ tipo_documento: 'FT', estado: 'PENDENTE', contabilizado: true }, nada)).toEqual({ contabilizar: false, descontabilizar: false, converter: false, emitirRecibo: false });
    // encomendas e orçamentos não se contabilizam; convertem-se enquanto não concluídos
    expect(botoesFluxo({ tipo_documento: 'NE', estado: 'PENDENTE', contabilizado: false }, tudo)).toMatchObject({ contabilizar: false, converter: true });
    expect(botoesFluxo({ tipo_documento: 'OR', estado: 'CONCLUIDO', contabilizado: false }, tudo).converter).toBe(false);
    expect(botoesFluxo({ tipo_documento: 'GR', estado: 'ANULADO', contabilizado: false }, tudo)).toMatchObject({ contabilizar: false, converter: false });
    expect(botoesFluxo(undefined, tudo).descontabilizar).toBe(false);
  });

  it('«Documento AGT» continua a abrir o detalhe e «Ver Processo» a janela', () => {
    const chamadas: string[] = [];
    const fazer = {
      verProcesso: () => { chamadas.push('janela'); }, verDocumentoAgt: () => { chamadas.push('detalhe'); },
      imprimir: () => {}, copiar: () => {}, contabilizar: () => {}, descontabilizar: () => {}, converter: () => {}, pedidoCompra: () => {}, liquidar: () => {}, limparEstado: () => {}, eliminar: () => {},
    };
    const d = { id: 1, tipo_documento: 'FT', numero_documento: 'FT 1', estado: 'PENDENTE', contabilizado: false, valor_pago: '0', faturacao_eletronica: { regime: true, estado: 'PRONTO' } } as unknown as DocumentoVenda;
    const accoes = accoesLinhaDocumento(d, 'faturas', tudo, fazer);
    accoes.find((a) => a.chave === 'processo')?.onClick?.();
    accoes.find((a) => a.chave === 'agt')?.onClick?.();
    expect(chamadas).toEqual(['janela', 'detalhe']);
  });
});
