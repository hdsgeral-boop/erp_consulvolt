import { describe, expect, it } from 'vitest';
import { seccoesRotinas, SECCOES_ROTINAS } from '../comum/rotinas';
import { papelDoTerceiro, rotuloTipoTerceiro, SEPARADORES_AUX, separadorAuxActivo } from '../tabelasAux/tabelasAux';
import { contasParaColar, descreverUltimo, destinoSeparador, normalizarContasColadas, separadorActivo, SEPARADORES_RELATORIOS, separadoresPermitidos } from './separadoresRelatorios';

const todos = () => true;

describe('Relatórios e mapas contabilísticos (8.2.1)', () => {
  it('tem os 8 separadores pela ordem do legado', () => {
    expect(SEPARADORES_RELATORIOS.map((s) => s.rotulo)).toEqual([
      'Extrato', 'Balancete', 'Balancete do Razão', 'Balanço', 'Demonstração de resultados', 'Fluxo de Caixa', 'Evolução Mensal', 'Mapa IVA',
    ]);
  });

  it('cada separador navega para a rota contab_mapa_* (razão e IVA no ecrã do balancete)', () => {
    const destinos = Object.fromEntries(SEPARADORES_RELATORIOS.map((s) => [s.chave, destinoSeparador(s)]));
    expect(destinos.extrato).toBe('/m/contab/contab_mapa_extrato');
    expect(destinos.razao).toBe('/m/contab/contab_mapa_balancete?separador=razao');
    expect(destinos.iva).toBe('/m/contab/contab_mapa_balancete?separador=iva');
  });

  it('escolhe o separador da rota, com ?separador= no balancete', () => {
    expect(separadorActivo('balanco', '', todos)?.chave).toBe('balanco');
    expect(separadorActivo('balancete', '?separador=razao', todos)?.chave).toBe('razao');
    expect(separadorActivo('balancete', '?separador=iva', todos)?.chave).toBe('iva');
    expect(separadorActivo('balancete', '?separador=xpto', todos)?.chave).toBe('balancete');
  });

  it('a entrada «Mapas e relatórios» abre o primeiro permitido; sem permissões não há separador', () => {
    expect(separadorActivo(null, '', todos)?.chave).toBe('extrato');
    const soBalanco = (p: string) => p === 'contab_mapa_balanco_view';
    expect(separadorActivo(null, '', soBalanco)?.chave).toBe('balanco');
    expect(separadorActivo('extrato', '', soBalanco)?.chave).toBe('balanco');
    expect(separadoresPermitidos(() => false)).toEqual([]);
    expect(separadorActivo('extrato', '', () => false)).toBeNull();
  });

  it('Balancete do Razão e Mapa IVA dependem da permissão do balancete (como no legado)', () => {
    const soBalancete = (p: string) => p === 'contab_mapa_balancete_view';
    expect(separadoresPermitidos(soBalancete).map((s) => s.chave)).toEqual(['balancete', 'razao', 'iva']);
  });

  it('«Colar múltiplas contas»: uma por linha ou separadas por vírgula, sem repetições', () => {
    expect(normalizarContasColadas('21\r\n24\n\n26 \n24')).toBe('21, 24, 26');
    expect(normalizarContasColadas('31*, 4311-4319;62')).toBe('31*, 4311-4319, 62');
    expect(contasParaColar('21, 24')).toBe('21\n24');
    expect(contasParaColar(undefined)).toBe('');
  });

  it('descreve o último lançamento mostrado na abertura', () => {
    expect(descreverUltimo({ diario: 'VD', numero_lan: '12', numero_documento: 'FT 1', data_documento: '2026-01-31' })).toContain('31/01/2026');
    expect(descreverUltimo(null)).toBe('Sem lançamentos nesta empresa.');
  });
});

describe('Rotinas (8.3.1)', () => {
  it('Imposto de Selo é um separador, pela ordem do legado', () => {
    expect(SECCOES_ROTINAS.map((s) => s.nome)).toEqual([
      'Capitalização de Obras', 'Compensação Automática', 'Transferência de Saldos', 'Imposto de Selo', 'Actualização em Massa', 'Limpeza de Reconciliações', 'Histórico',
    ]);
  });

  it('a rota imposto_selo abre o separador «Imposto de Selo»; as permissões filtram os separadores', () => {
    expect(seccoesRotinas(true, true, 'selo').activa).toBe('selo');
    expect(seccoesRotinas(true, true, undefined, 'historico').activa).toBe('historico');
    expect(seccoesRotinas(true, true).activa).toBe('capitalizacao');
    const soSelo = seccoesRotinas(false, true, undefined, 'historico');
    expect(soSelo.seccoes.map((s) => s.chave)).toEqual(['selo']);
    expect(soSelo.activa).toBe('selo');
    expect(seccoesRotinas(true, false, 'selo').seccoes.some((s) => s.chave === 'selo')).toBe(false);
    expect(seccoesRotinas(false, false).activa).toBeNull();
  });
});

describe('Tabelas auxiliares (8.6.1)', () => {
  it('tem o separador Terceiros na posição do legado', () => {
    expect(SEPARADORES_AUX.map((s) => s.rotulo)).toEqual([
      'Diários', 'Terceiros', 'Notas de Demonstrações', 'Notas de Fluxo de Caixa', 'Centros de Custo', 'Unidades de Negócio', 'Plano de contas', 'Reciclagem',
    ]);
  });

  it('o separador activo vem de ?separador= (desconhecido → o primeiro)', () => {
    const chaves = SEPARADORES_AUX.map((s) => s.chave);
    expect(separadorAuxActivo('?separador=terceiros', chaves)).toBe('terceiros');
    expect(separadorAuxActivo('?separador=nada', chaves)).toBe('diarios');
    expect(separadorAuxActivo('', chaves)).toBe('diarios');
    expect(separadorAuxActivo('?separador=reciclagem', chaves.filter((c) => c !== 'reciclagem'))).toBe('diarios');
  });

  it('tipo e papel dos terceiros', () => {
    expect(rotuloTipoTerceiro({ e_cliente: true, e_fornecedor: true })).toBe('CLIENTE / FORNECEDOR');
    expect(rotuloTipoTerceiro({ tipo: 'colaborador' })).toBe('COLABORADOR');
    expect(rotuloTipoTerceiro({})).toBe('—');
    expect(papelDoTerceiro({ e_fornecedor: true })).toBe('FORNECEDOR');
    expect(papelDoTerceiro({ tipo: 'FORNECEDOR' })).toBe('FORNECEDOR');
    expect(papelDoTerceiro({ e_cliente: true, e_fornecedor: true })).toBe('CLIENTE');
  });
});
