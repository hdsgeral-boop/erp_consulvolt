import { describe, expect, it } from 'vitest';
import { estadoAgtDoDocumento, INFO_ESTADO_AGT, normalizarMensagensAgt, orientacaoAgt } from './estadoAgt';
import { textoConfigurado, ROTULO_AMBIENTE } from './LigacaoAgtEmpresa';
import { atrasados, textoErros, type LinhaMonitor } from './MonitorAgt';

const linha = (o: Partial<LinhaMonitor>): LinhaMonitor => ({
  id: 1, tipo_documento: 'FT', numero_documento: 'FT A2026/1', data_emissao: '2026-10-17', total_bruto: '100.00', estado: 'POR_ENVIAR', horas: 0,
  tentativas: 0, proxima_tentativa: null, contingencia_desde: null, request_id: null, erros: [], substituido_por: null, ...o,
});

describe('facturação electrónica AGT por empresa (r4_agt)', () => {
  it('lê os erros do obterEstado (errorCode/errorDescription) e do registarFactura (idError/descriptionError)', () => {
    expect(normalizarMensagensAgt([{ errorCode: 'E11', errorDescription: 'NIF desconhecido' }, { idError: 'E22', descriptionError: 'Imposto', documentNo: 'FT A/1' }])).toEqual([
      { codigo: 'E11', mensagem: 'NIF desconhecido', documento: null },
      { codigo: 'E22', mensagem: 'Imposto', documento: 'FT A/1' },
    ]);
    expect(textoErros([{ codigo: 'E09', mensagem: 'Já existe' }, 'E02: formato'])).toBe('E09 Já existe | E02 formato');
  });

  it('estados de contingência e de substituição e orientação de correcção com novo número (E46)', () => {
    expect(estadoAgtDoDocumento({ regime: true, estado: 'PRONTO', envio: 'CONTINGENCIA' })).toBe('CONTINGENCIA');
    expect(estadoAgtDoDocumento({ regime: true, estado: 'PRONTO', envio: 'SUBSTITUIDO' })).toBe('SUBSTITUIDO');
    expect(INFO_ESTADO_AGT.CONTINGENCIA.rotulo).toBe('Em contingência');
    expect(orientacaoAgt('INVALIDO')).toContain('NOVO número');
    expect(orientacaoAgt('CONTINGENCIA')).toContain('24 h');
  });

  it('conta os documentos por comunicar acima do limite de horas, sem contar os já enviados', () => {
    const l = [linha({ horas: 25 }), linha({ id: 2, horas: 30, estado: 'ENVIADO' }), linha({ id: 3, horas: 2, estado: 'CONTINGENCIA' }), linha({ id: 4, horas: 21, estado: 'CONTINGENCIA' })];
    expect(atrasados(l, 20)).toBe(2);
  });

  it('mostra só «configurado em … por …», nunca os segredos', () => {
    expect(textoConfigurado(null, null)).toBe('Por configurar');
    expect(textoConfigurado('2026-10-17T10:00:00+01:00', 'ana')).toMatch(/^Configurado em .+ por ana$/);
    expect(ROTULO_AMBIENTE.producao).toBe('Produção');
  });
});
