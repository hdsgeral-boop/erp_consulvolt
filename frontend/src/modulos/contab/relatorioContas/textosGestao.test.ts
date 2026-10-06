import { describe, expect, it } from 'vitest';
import { gerarTexto, htmlParaTexto, tabelaSeccao, textoActual, textoParaHtml, type DadosGestao } from './textosGestao';

const dados: DadosGestao = {
  ano: 2026,
  ano_anterior: 2025,
  colaboradores: 12,
  n: { indicadores: { rl: '1500000.00', vendas_prestacoes: '10000000.00', ebitda: '2000000.00', passivo_corrente: '500000.00', liquidez_geral: 2.5, liquidez_reduzida: 1.2, liquidez_imediata: 0.4, capital_proprio: '3000000.00', activo: '6000000.00', roe: 0.5 } },
  n1: { indicadores: { rl: '-200000.00', vendas_prestacoes: '8000000.00', ebitda: '500000.00', roe: null } },
};
const config = { nome: 'Empresa Fictícia, Lda', nif: '5000000000', capital: 100000, pct_reservas: 10, pct_transitados: 60, pct_dividendos: 30 };

describe('Relatório de Gestão (M-10)', () => {
  it('gera os acontecimentos relevantes a partir dos indicadores', () => {
    const t = gerarTexto('acontecimentos', dados, config);
    expect(t).toContain('saiu de um prejuízo');
    expect(t).toContain('crescimento de 25,0 %');
  });

  it('liquidez: cobre ou não as dívidas de curto prazo', () => {
    const t = gerarTexto('liquidez', dados, config);
    expect(t).toContain('consegue cobrir tais dívidas');
    expect(t).toContain('não cobrem');
  });

  it('texto editado prevalece sobre o automático; HTML do legado convertido para texto', () => {
    expect(textoActual('finais', { html: '<p>Obrigado.</p><ul><li>Clientes</li></ul>', auto: false }, dados, config)).toEqual({ texto: 'Obrigado.\n• Clientes', auto: false, copiado: undefined });
    expect(textoActual('finais', undefined, dados, config).auto).toBe(true);
  });

  it('texto → HTML escapado (sem HTML livre do utilizador)', () => {
    expect(textoParaHtml('Linha <b>1</b>\n• item a\n• item b\nFim')).toBe('<p>Linha &lt;b&gt;1&lt;/b&gt;</p><ul><li>item a</li><li>item b</li></ul><p>Fim</p>');
    expect(htmlParaTexto('<p>A</p><p>B<script>x()</script></p>')).toContain('A\nB');
  });

  it('proposta de aplicação reparte o resultado positivo pelas percentagens', () => {
    const linhas = tabelaSeccao('aplicacao', dados, config);
    expect(linhas.map((l) => l[1].replace(/\s/g, ''))).toEqual(['150000,00Kz', '900000,00Kz', '450000,00Kz']);
  });
});
