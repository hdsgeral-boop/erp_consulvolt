import { describe, expect, it, vi, beforeEach } from 'vitest';
import { colunasDoConteudo, copiarTexto, tabelaParaTexto } from './copiar';
import { tabelaHtml } from './tabela';

interface Linha { codigo: string; nome: string; total: number }

const LINHAS: Linha[] = [
  { codigo: 'C1', nome: 'Alfa', total: 1234.5 },
  { codigo: 'C2', nome: 'Beta', total: 2000 },
];

const HTML = tabelaHtml<Linha>({
  colunas: [
    { titulo: 'Código', valor: (l) => l.codigo },
    { titulo: 'Nome', valor: (l) => l.nome },
    { titulo: 'Total', valor: (l) => l.total, formato: 'moeda', somar: true },
  ],
  linhas: LINHAS,
  totais: 'Total geral',
});

describe('colunasDoConteudo', () => {
  it('lê os títulos do cabeçalho da 1.ª tabela de dados', () => {
    expect(colunasDoConteudo(HTML)).toEqual(['Código', 'Nome', 'Total']);
  });

  it('sem nenhuma tabela, devolve uma lista vazia', () => {
    expect(colunasDoConteudo('<p>Sem tabelas aqui.</p>')).toEqual([]);
  });

  it('ignora tabelas de pares (`.imp-pares`), só usa tabelas de dados', () => {
    expect(colunasDoConteudo('<table class="imp-pares"><tr><td>Rótulo: valor</td></tr></table>')).toEqual([]);
  });
});

describe('tabelaParaTexto', () => {
  it('tabela inteira: cabeçalho, linhas e total, separados por tabulação', () => {
    const texto = tabelaParaTexto(HTML);
    expect(texto.split('\n')).toEqual([
      'Código\tNome\tTotal',
      'C1\tAlfa\t1 234,50',
      'C2\tBeta\t2 000,00',
      'Total geral\t\t3 234,50',
    ]);
  });

  it('só as colunas escolhidas (por índice), pela mesma ordem do cabeçalho', () => {
    const texto = tabelaParaTexto(HTML, [0, 1]);
    expect(texto.split('\n')).toEqual(['Código\tNome', 'C1\tAlfa', 'C2\tBeta', 'Total geral\t']);
  });

  it('uma só coluna', () => {
    expect(tabelaParaTexto(HTML, [1])).toBe('Nome\nAlfa\nBeta\n');
  });

  it('sem nenhuma tabela no conteúdo, devolve texto vazio', () => {
    expect(tabelaParaTexto('<p>Sem tabelas aqui.</p>')).toBe('');
  });
});

describe('copiarTexto', () => {
  const originalClipboard = navigator.clipboard;
  beforeEach(() => {
    Object.defineProperty(navigator, 'clipboard', { value: originalClipboard, configurable: true });
  });

  it('usa navigator.clipboard.writeText quando disponível', async () => {
    const writeText = vi.fn().mockResolvedValue(undefined);
    Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true });
    expect(await copiarTexto('Código\tNome')).toBe(true);
    expect(writeText).toHaveBeenCalledWith('Código\tNome');
  });

  it('recorre a execCommand quando a API moderna falha ou não existe', async () => {
    Object.defineProperty(navigator, 'clipboard', { value: { writeText: vi.fn().mockRejectedValue(new Error('negado')) }, configurable: true });
    const execCommand = vi.fn().mockReturnValue(true);
    Object.defineProperty(document, 'execCommand', { value: execCommand, configurable: true });
    expect(await copiarTexto('x')).toBe(true);
    expect(execCommand).toHaveBeenCalledWith('copy');
  });
});
