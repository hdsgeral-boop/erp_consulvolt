import { describe, expect, it } from 'vitest';
import { estadoAgtDoDocumento, normalizarMensagensAgt, orientacaoAgt } from './estadoAgt';

describe('estado AGT do documento (A-04)', () => {
  it('segue a regra dos contadores da Facturação electrónica', () => {
    expect(estadoAgtDoDocumento(undefined)).toBeNull();
    expect(estadoAgtDoDocumento({ regime: false, estado: 'PRONTO', envio: 'VALIDO' })).toBeNull();
    expect(estadoAgtDoDocumento({ regime: true, estado: 'COM_ERROS', envio: null })).toBe('COM_ERROS_LOCAIS');
    expect(estadoAgtDoDocumento({ regime: true, estado: null, envio: null })).toBe('COM_ERROS_LOCAIS');
    expect(estadoAgtDoDocumento({ regime: true, estado: 'PRONTO', envio: null })).toBe('POR_ENVIAR');
    expect(estadoAgtDoDocumento({ regime: true, estado: 'PRONTO', envio: 'INVALIDO' })).toBe('INVALIDO');
    expect(estadoAgtDoDocumento({ regime: true, estado: 'PRONTO', envio: 'DESCONHECIDO' })).toBe('POR_ENVIAR');
  });

  it('torna legíveis os erros do validador local, do envio e do legado', () => {
    expect(
      normalizarMensagensAgt([
        'E02: número de documento com formato inválido (FT A/1).',
        'E01/E02: NIF da empresa em falta ou inválido.',
        'Linha isenta sem motivo',
        { codigo: 'E40', mensagem: 'NIF do cliente inválido', documentNo: 'FT A/7' },
        { idError: 'E97', descriptionError: 'Consulta prematura' },
        { mensagem: 'Erro do legado sem código' },
        null,
        '',
      ]),
    ).toEqual([
      { codigo: 'E02', mensagem: 'número de documento com formato inválido (FT A/1).' },
      { codigo: 'E01/E02', mensagem: 'NIF da empresa em falta ou inválido.' },
      { codigo: null, mensagem: 'Linha isenta sem motivo' },
      { codigo: 'E40', mensagem: 'NIF do cliente inválido', documento: 'FT A/7' },
      { codigo: 'E97', mensagem: 'Consulta prematura', documento: null },
      { codigo: null, mensagem: 'Erro do legado sem código', documento: null },
    ]);
    expect(normalizarMensagensAgt('não é lista')).toEqual([]);
  });

  it('dá orientação só nos estados que exigem acção ou espera', () => {
    expect(orientacaoAgt('INVALIDO')).toMatch(/Revalidar AGT/);
    expect(orientacaoAgt('COM_ERROS_LOCAIS')).toMatch(/nota de crédito/);
    expect(orientacaoAgt('VALIDO')).toBeNull();
    expect(orientacaoAgt(null)).toBeNull();
  });
});
