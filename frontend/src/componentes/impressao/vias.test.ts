import { afterEach, describe, expect, it } from 'vitest';
import { construirDocumento, opcoesVias } from './documento';
import { decidirFormato, FORMATO_PADRAO, mmParaPx } from './formato';
import { aplicarPreferencia, chaveDocumento, chavePorOmissao, descreverPreferencia, gravarPreferencia, lerPreferencia, PREFERENCIA_AUTOMATICA } from './preferencias';

const identidade = { nome: 'Demo E2E Comércio, Lda', nif: '5000000000', morada: 'Rua A, Luanda' };

describe('vias na mesma folha (original e cópia)', () => {
  it('cada via leva o cabeçalho da empresa, o título e o rótulo; linha de corte entre elas', () => {
    const html = construirDocumento({ titulo: 'Recibo RC 2026/1', identidade, conteudo: '<p>Corpo do recibo</p>', vias: true });
    expect(html.match(/class="imp-via"/g)).toHaveLength(2);
    expect(html.match(/class="imp-cabecalho"/g)).toHaveLength(2);
    expect(html.match(/Corpo do recibo/g)).toHaveLength(2);
    expect(html).toContain('<span class="imp-via-rotulo">Original</span>');
    expect(html).toContain('<span class="imp-via-rotulo">Duplicado</span>');
    expect(html.match(/class="imp-corte"/g)).toHaveLength(1);
    expect(html).toContain('class="imp-vias imp-uma-folha"');
    expect(html).toContain('imp-com-vias');
  });

  it('rótulos próprios e vários documentos (um por folha)', () => {
    const html = construirDocumento({
      titulo: 'Recibo de vencimento',
      identidade,
      conteudo: '',
      vias: { rotulos: ['Original — Colaborador', 'Duplicado — Entidade Patronal'], partes: [{ conteudo: '<p>A</p>', subtitulo: 'Ana' }, { conteudo: '<p>B</p>', subtitulo: 'Bruno' }] },
    });
    expect(html.match(/class="imp-vias imp-uma-folha imp-quebra-pagina"/g)).toHaveLength(1);
    expect(html.match(/class="imp-via"/g)).toHaveLength(4);
    expect(html).toContain('Duplicado — Entidade Patronal');
    expect(html).toContain('<div class="imp-subtitulo">Bruno</div>');
  });

  it('CSS: retrato uma por cima da outra (meia folha cada), paisagem lado a lado com corte vertical', () => {
    const html = construirDocumento({ titulo: 'T', conteudo: '', vias: true });
    expect(html).toMatch(/body\.imp-paginado\[data-orientacao="retrato"\] \.imp-vias \{ min-height: calc\(/);
    expect(html).toMatch(/body\[data-orientacao="paisagem"\] \.imp-vias \{ flex-direction: row;/);
    expect(html).toMatch(/\.imp-corte \{ flex: 0 0 auto; width: 0;[^}]*border-left/);
  });

  it('sem vias: documento normal (cabeçalho fora do conteúdo); blocoTitulo=false esconde o título', () => {
    const html = construirDocumento({ titulo: 'Mapa', identidade, conteudo: '<p>x</p>', blocoTitulo: false });
    expect(html).not.toContain('class="imp-via"');
    expect(html).not.toContain('<h1 class="imp-titulo">');
    expect(opcoesVias(undefined)).toBeNull();
    expect(opcoesVias(['Original', 'Cópia'])?.rotulos).toEqual(['Original', 'Cópia']);
  });

  it('a medição recebe a orientação do candidato (as vias mudam de disposição em paisagem)', () => {
    const vistos: string[] = [];
    const f = decidirFormato({ medir: (px, _q, o) => { vistos.push(String(o)); return px > mmParaPx(200) ? px : px + 200; }, orientacao: 'auto' });
    expect(vistos[0]).toBe('retrato');
    expect(vistos).toContain('paisagem');
    expect(f.orientacao).toBe('paisagem');
    expect(FORMATO_PADRAO.orientacao).toBe('retrato');
  });
});

describe('preferência de página (escolha do utilizador)', () => {
  afterEach(() => window.localStorage.clear());

  it('a chave ignora números e identificadores (todos os recibos partilham a escolha)', () => {
    expect(chaveDocumento('Recibo RC 2026/15')).toBe(chaveDocumento('Recibo RC 2026/16'));
    expect(chavePorOmissao('/vendas/recibos/123', 'Imprimir')).toBe(chavePorOmissao('/vendas/recibos/9', 'Imprimir'));
    expect(chavePorOmissao('/rh/mapas', 'Imprimir')).not.toBe(chavePorOmissao('/rh/calcular', 'Imprimir'));
  });

  it('lembra a última escolha por documento; «Automática» apaga a preferência', () => {
    expect(lerPreferencia('mapa')).toEqual(PREFERENCIA_AUTOMATICA);
    gravarPreferencia('mapa', { orientacao: 'paisagem', papel: 'A3' });
    expect(lerPreferencia('mapa')).toEqual({ orientacao: 'paisagem', papel: 'A3' });
    expect(lerPreferencia('outro')).toEqual(PREFERENCIA_AUTOMATICA);
    gravarPreferencia('mapa', PREFERENCIA_AUTOMATICA);
    expect(window.localStorage.getItem('erp.impressao.pagina:mapa')).toBeNull();
  });

  it('valores inválidos no armazenamento voltam a «Automática»', () => {
    window.localStorage.setItem('erp.impressao.pagina:x', '{"orientacao":"diagonal","papel":"A5"}');
    expect(lerPreferencia('x')).toEqual(PREFERENCIA_AUTOMATICA);
    window.localStorage.setItem('erp.impressao.pagina:y', 'não é json');
    expect(lerPreferencia('y')).toEqual(PREFERENCIA_AUTOMATICA);
  });

  it('«Automática» mantém o pedido do ecrã; «Vertical»/«Horizontal» impõem a orientação', () => {
    const pedido = { titulo: 'Recibo', conteudo: '', orientacao: 'retrato' as const, papel: 'A4' as const };
    expect(aplicarPreferencia(pedido, PREFERENCIA_AUTOMATICA)).toMatchObject({ orientacao: 'retrato', papel: 'A4' });
    expect(aplicarPreferencia(pedido, { orientacao: 'paisagem', papel: 'auto' })).toMatchObject({ orientacao: 'paisagem', papel: 'A4' });
    const mapa: { titulo: string; conteudo: string; orientacao?: 'auto' | 'retrato' | 'paisagem'; papel?: 'auto' | 'A4' | 'A3' } = { titulo: 'Mapa', conteudo: '' };
    expect(aplicarPreferencia(mapa, { orientacao: 'paisagem', papel: 'A3' })).toMatchObject({ orientacao: 'paisagem', papel: 'A3' });
    expect(descreverPreferencia({ orientacao: 'paisagem', papel: 'A3' })).toBe('Horizontal · A3');
    expect(descreverPreferencia(PREFERENCIA_AUTOMATICA)).toBe('Automática');
  });
});
