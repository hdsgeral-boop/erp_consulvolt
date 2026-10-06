import { describe, expect, it } from 'vitest';
import { brutoConsistente } from '@/componentes/TabelaApi';
import { crc32, gerarXlsx, interpretarCelula, lerDataPt, lerNumeroPt, letraColuna, montarFolha, nomeFolha, xmlFolha } from './excel';
import { tabelaHtml } from './tabela';

/** Lê um ZIP sem compressão (método 0) → { nome: texto } e verifica o CRC de cada entrada. */
function lerZip(bytes: Uint8Array): Record<string, string> {
  const v = new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength);
  const dec = new TextDecoder();
  const saida: Record<string, string> = {};
  let p = 0;
  while (v.getUint32(p, true) === 0x04034b50) {
    expect(v.getUint16(p + 8, true)).toBe(0);
    const crc = v.getUint32(p + 14, true);
    const tamanho = v.getUint32(p + 18, true);
    const nomeT = v.getUint16(p + 26, true);
    const nome = dec.decode(bytes.subarray(p + 30, p + 30 + nomeT));
    const dados = bytes.subarray(p + 30 + nomeT, p + 30 + nomeT + tamanho);
    expect(crc32(dados)).toBe(crc);
    saida[nome] = dec.decode(dados);
    p += 30 + nomeT + tamanho;
  }
  expect(v.getUint32(p, true)).toBe(0x02014b50);
  return saida;
}

describe('leitura de valores pt-PT', () => {
  it('números formatados, moeda e percentagens', () => {
    expect(lerNumeroPt('1 234 567,89')).toEqual({ valor: 1234567.89, casas: 2, percentagem: false });
    expect(lerNumeroPt('1 234,5 Kz')).toEqual({ valor: 1234.5, casas: 1, percentagem: false });
    expect(lerNumeroPt('1.050,25')).toEqual({ valor: 1050.25, casas: 2, percentagem: false });
    expect(lerNumeroPt('-12,5 %')).toEqual({ valor: -12.5, casas: 1, percentagem: true });
    expect(lerNumeroPt('1234,00')?.valor).toBe(1234);
    expect(lerNumeroPt('42')?.valor).toBe(42);
  });

  it('códigos e textos não são números', () => {
    expect(lerNumeroPt('0012')).toBeNull();
    expect(lerNumeroPt('FT 2026/12')).toBeNull();
    expect(lerNumeroPt('12345678901234567')).toBeNull();
    expect(lerNumeroPt('6621', false)).toBeNull();
    expect(lerNumeroPt('1.2.3')).toBeNull();
  });

  it('datas dd/mm/aaaa (com e sem hora) em série do Excel', () => {
    expect(lerDataPt('01/01/2026')).toEqual({ serie: 46023, comHora: false });
    expect(lerDataPt('31/12/2026 12:00')?.serie).toBeCloseTo(46387.5, 5);
    expect(lerDataPt('32/01/2026')).toBeNull();
  });

  it('célula: valor bruto prevalece; inteiros só à direita', () => {
    expect(interpretarCelula('912,1235', { bruto: '912.123456', tipoBruto: 'n' })).toEqual({ valor: 912.123456, tipo: 'numero', casas: 6 });
    expect(interpretarCelula('06/10/2026', { bruto: '2026-10-06', tipoBruto: 'd' }).tipo).toBe('data');
    expect(interpretarCelula('6621').tipo).toBe('texto');
    expect(interpretarCelula('15', { alinhadaDireita: true })).toEqual({ valor: 15, tipo: 'numero', casas: 0 });
    expect(interpretarCelula('—')).toEqual({ valor: null, tipo: 'texto' });
  });

  it('TabelaApi: valor bruto só quando coerente com o texto mostrado', () => {
    expect(brutoConsistente('1234.50', '1234,50')).toBe('1234.50');
    expect(brutoConsistente(912.123456, '912,1235')).toBe(912.123456);
    expect(brutoConsistente(12, 'Cliente Alfa')).toBeUndefined(); // id por trás de um nome
    expect(brutoConsistente('5999000001', '5999000001')).toBeUndefined(); // NIF: fica texto
    expect(brutoConsistente('2026-10-06', '06/10/2026')).toBe('2026-10-06');
    expect(brutoConsistente('2026-10-06', '07/10/2026')).toBeUndefined();
  });
});

describe('folha e ficheiro .xlsx', () => {
  const linhas = [
    { conta: '6621', data: '2026-10-06', valor: '1000.50', qtd: 3 },
    { conta: '7621', data: '2026-10-07', valor: '210.25', qtd: 2 },
  ];
  const conteudo = tabelaHtml({
    colunas: [
      { titulo: 'Conta', valor: (l: (typeof linhas)[number]) => l.conta },
      { titulo: 'Data', valor: (l) => l.data, formato: 'data' },
      { titulo: 'Valor', valor: (l) => l.valor, formato: 'moeda', somar: true },
      { titulo: 'Qtd.', valor: (l) => l.qtd, formato: 'inteiro' },
    ],
    linhas,
    totais: true,
  });
  const pedido = {
    titulo: 'Extracto de contas',
    periodo: 'Outubro de 2026',
    filtros: ['Diário: Geral'],
    identidade: { nome: 'Empresa Demo, Lda', nif: '5999000001' },
    utilizador: 'Admin',
    emitidoEm: new Date(2026, 9, 6, 10, 30),
    conteudo,
  };

  it('cabeçalho do documento, tipos das células e totais', () => {
    const f = montarFolha(pedido);
    expect(f.linhas[0][0]).toMatchObject({ valor: 'Extracto de contas', papel: 'titulo' });
    expect(f.linhas[1][0]?.valor).toBe('Empresa Demo, Lda · NIF 5999000001');
    expect(f.linhas.some((l) => l[0]?.valor === 'Emitido em 06/10/2026 10:30 por Admin')).toBe(true);
    const cab = f.cabecalho!;
    expect(f.linhas[cab.linha].map((c) => c?.valor)).toEqual(['Conta', 'Data', 'Valor', 'Qtd.']);
    const primeira = f.linhas[cab.linha + 1];
    expect(primeira[0]).toMatchObject({ valor: '6621', tipo: 'texto' });
    expect(primeira[1]).toMatchObject({ valor: 46301, tipo: 'data' });
    expect(primeira[2]).toMatchObject({ valor: 1000.5, tipo: 'numero', casas: 2 });
    expect(primeira[3]).toMatchObject({ valor: 3, tipo: 'numero' });
    const total = f.linhas[cab.ultimaLinha];
    expect(total[0]).toMatchObject({ valor: 'Total', papel: 'total' });
    expect(total[2]).toMatchObject({ valor: 1210.75, papel: 'total' });
  });

  it('pacote válido: tipos de conteúdo, folha, filtro, painel fixo e números/datas', () => {
    const { bytes } = gerarXlsx(pedido);
    const z = lerZip(bytes);
    expect(Object.keys(z)).toEqual(['[Content_Types].xml', '_rels/.rels', 'xl/workbook.xml', 'xl/_rels/workbook.xml.rels', 'xl/styles.xml', 'xl/worksheets/sheet1.xml']);
    expect(z['xl/workbook.xml']).toContain('<sheet name="Extracto de contas" sheetId="1" r:id="rId1"/>');
    expect(z['xl/workbook.xml']).toContain('_xlnm._FilterDatabase');
    const folha = z['xl/worksheets/sheet1.xml'];
    expect(folha).toContain('<v>1000.5</v>');
    expect(folha).toContain('<v>46301</v>');
    expect(folha).toContain('<autoFilter ref="A');
    expect(folha).toContain('state="frozen"');
    expect(folha).toContain('<t xml:space="preserve">6621</t>');
    for (const xml of Object.values(z)) expect(() => new DOMParser().parseFromString(xml, 'application/xml')).not.toThrow();
    for (const xml of Object.values(z)) expect(new DOMParser().parseFromString(xml, 'application/xml').querySelector('parsererror')).toBeNull();
  });

  it('células fundidas (grupos), escape de XML e nomes de folha', () => {
    const html = '<table><caption>Mapa</caption><thead><tr><th>A</th><th>B</th></tr></thead><tbody><tr class="imp-grupo"><td colspan="2">Grupo &lt;1&gt; &amp; Cia</td></tr><tr><td>x</td><td class="imp-num">1 000,00</td></tr></tbody></table>';
    const xml = xmlFolha(montarFolha({ titulo: 'T', conteudo: html }));
    expect(xml).toMatch(/<mergeCell ref="A\d+:B\d+"\/>/);
    expect(xml).toContain('Grupo &lt;1&gt; &amp; Cia');
    expect(letraColuna(0)).toBe('A');
    expect(letraColuna(27)).toBe('AB');
    expect(nomeFolha('Mapa [IVA] 1/2: teste muito longo para uma folha')).toBe('Mapa IVA 1 2 teste muito longo');
  });
});
