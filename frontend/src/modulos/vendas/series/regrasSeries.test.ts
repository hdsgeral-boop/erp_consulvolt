import { describe, expect, it } from 'vitest';
import {
  codigoSugerido, erroCodigo, erroNumeroInicial, nomeTipo, outraActiva, podeAlterarNumeracao, podeEliminar, proximoDocumento, serieUsada, textoCartao,
} from './regrasSeries';

describe('Séries de numeração — regras puras (vendas_series.js / facturacao_agt.js do legado novo)', () => {
  it('código: só letras e números, até 30, sem espaços nem «/»', () => {
    expect(erroCodigo('A2026')).toBeNull();
    expect(erroCodigo('LIV500')).toBeNull();
    expect(erroCodigo('')).toMatch(/Indique/);
    expect(erroCodigo('A/2026')).toMatch(/letras e números/);
    expect(erroCodigo('A 2026')).toMatch(/letras e números/);
    expect(erroCodigo('X'.repeat(31))).toMatch(/30/);
    expect(erroCodigo('X'.repeat(30))).toBeNull();
  });

  it('número inicial entre 1 e 99 999 999 (vazio = 1)', () => {
    expect(erroNumeroInicial(1)).toBeNull();
    expect(erroNumeroInicial('501')).toBeNull();
    expect(erroNumeroInicial(99_999_999)).toBeNull();
    expect(erroNumeroInicial('')).toBeNull();
    expect(erroNumeroInicial(0)).toMatch(/entre 1 e 99 999 999/);
    expect(erroNumeroInicial(100_000_000)).toMatch(/entre/);
    expect(erroNumeroInicial(1.5)).toMatch(/inteiro/);
  });

  it('próximo documento no formato SAF-T', () => {
    expect(proximoDocumento('FT', 'A2026', 501)).toBe('FT A2026/501');
    expect(proximoDocumento('NC', 'B1', null)).toBe('NC B1/1');
    expect(proximoDocumento('FR', '', 3)).toBeNull();
  });

  it('«usada» conta os documentos emitidos e não o próximo número', () => {
    const virgem501 = { emitidos: 0, proximo_numero: 501 };
    expect(serieUsada(virgem501)).toBe(false);
    expect(podeAlterarNumeracao(virgem501)).toBe(true);
    expect(podeEliminar(virgem501)).toBe(true);
    const usada = { emitidos: 3, proximo_numero: 4 };
    expect(serieUsada(usada)).toBe(true);
    expect(podeAlterarNumeracao(usada)).toBe(false);
    expect(podeEliminar(usada)).toBe(false);
    expect(podeAlterarNumeracao(null)).toBe(true);
  });

  it('uma só série activa por tipo + ano + origem', () => {
    const series = [
      { id: 1, tipo: 'FT', ano: 2026, origem: 'GERAL', estado: 'ATIVA', codigo: 'A2026' },
      { id: 2, tipo: 'FT', ano: 2026, origem: 'CX1', estado: 'ATIVA', codigo: 'CX12026' },
      { id: 3, tipo: 'NC', ano: 2026, origem: 'GERAL', estado: 'FECHADA', codigo: 'A2026' },
    ];
    expect(outraActiva(series, { tipo: 'FT', ano: 2026, origem: 'GERAL', estado: 'ATIVA' })?.id).toBe(1);
    expect(outraActiva(series, { id: 1, tipo: 'FT', ano: 2026, origem: 'GERAL', estado: 'ATIVA' })).toBeNull();
    expect(outraActiva(series, { tipo: 'FT', ano: 2026, origem: 'GERAL', estado: 'FECHADA' })).toBeNull();
    expect(outraActiva(series, { tipo: 'NC', ano: 2026, origem: 'GERAL', estado: 'ATIVA' })).toBeNull();
    expect(outraActiva(series, { tipo: 'FT', ano: 2027, origem: 'GERAL', estado: 'ATIVA' })).toBeNull();
  });

  it('cartão de cada tipo: série atribuída, numeração automática ou série AGT em falta', () => {
    const regime = { ativo: false, data_inicio: null, exigir_series_agt: false };
    expect(textoCartao({ tipo: 'FT', nome: 'Factura', ano: 2026, serie_id: 7, codigo: 'LIV', proximo_documento: 'FT LIV/501' }, regime))
      .toEqual({ numero: 'FT LIV/501', nota: 'Série LIV de 2026', atribuida: true });
    const auto = textoCartao({ tipo: 'NC', nome: 'Nota de Crédito', ano: 2026, serie_id: null, codigo: null, proximo_documento: null }, regime);
    expect(auto.atribuida).toBe(false);
    expect(auto.nota).toMatch(/numera sozinho/);
    expect(textoCartao({ tipo: 'FR', nome: 'Factura-Recibo', ano: 2026, serie_id: null, codigo: null, proximo_documento: null }, { ...regime, exigir_series_agt: true }).nota)
      .toMatch(/AGT/);
  });

  it('código sugerido e nomes dos tipos', () => {
    expect(codigoSugerido('GERAL', 2026)).toBe('A2026');
    expect(codigoSugerido('cx-1', 2026)).toBe('CX12026');
    expect(nomeTipo('FR')).toBe('Factura-Recibo');
    expect(nomeTipo('OR')).toBe('OR');
  });
});
