import { describe, expect, it } from 'vitest';
import { pedidoConferencia } from './Conferencia';
import { dadosMovimento } from './FolhaCaixa';
import { urlSegura } from './colunasLegado';
import type { ConferenciaCaixa } from './api';

describe('tesouraria — cobertura de campos', () => {
  it('o movimento de caixa leva o URL do documento aparado e só se aceita http(s)', () => {
    expect(dadosMovimento({ tipo: 'PAG', data_documento: '2026-10-16', valor: 10, descricao: 'Táxi', url_documento: ' https://exemplo.ao/doc.pdf ' }).url_documento).toBe('https://exemplo.ao/doc.pdf');
    expect('url_documento' in dadosMovimento({ tipo: 'PAG', data_documento: '2026-10-16', valor: 10, descricao: 'Táxi', url_documento: '   ' })).toBe(false);
    expect(urlSegura('javascript:alert(1)')).toBeNull();
    expect(urlSegura('https://exemplo.ao/doc.pdf')).toBe('https://exemplo.ao/doc.pdf');
  });

  it('o auto de conferência mostra a conta de regularização e quem registou', () => {
    const c = {
      id: 1, codigo_conta: '4511', nome_conta: 'Caixa sede', data_conferencia: '2026-10-16', nome_operador: 'Ana', registado_por: 'ana.f', denominacoes: { N1000: 2 },
      total_fisico: '2000.00', total_sistema: '1700.00', saldo_externo: null, diferenca: '300.00', justificacao: null, estado: 'FINALIZADO', referencia_lancamento: 'LAN-1',
      conta_regularizacao: '6881', nome_conta_regularizacao: 'Outros proveitos', nome_gerente: null, assinado_gerente_em: null,
    } as ConferenciaCaixa;
    const html = String(pedidoConferencia(c).conteudo);
    expect(html).toContain('6881 — Outros proveitos');
    expect(html).toContain('ana.f');
  });
});
