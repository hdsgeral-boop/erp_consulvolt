import { lerTabela, tabelasDoConteudo, type CelulaExcel } from './excel';
import type { OpcoesDocumento } from './tipos';

/**
 * Copiar a área de transferência (texto separado por tabulações, formato que o Excel reconhece ao colar) — o
 * contrário do «Colar do Excel» (colagemGrade.ts). Reaproveita o mesmo leitor de tabelas da exportação Excel
 * (excel.ts: `tabelasDoConteudo`/`lerTabela`), por isso funciona em qualquer ecrã que já tenha «Imprimir/Excel»
 * (BotoesExportar), sem nada a configurar módulo a módulo.
 */

const DADOS = ({ tabela }: { tabela: HTMLTableElement }) => !tabela.classList.contains('imp-pares');

/** Títulos das colunas da 1.ª tabela de dados do conteúdo (para o selector «copiar só estas colunas»). */
export function colunasDoConteudo(conteudo: OpcoesDocumento['conteudo']): string[] {
  const tabela = tabelasDoConteudo(conteudo).filter(DADOS)[0]?.tabela;
  if (!tabela) return [];
  const { linhas, cabecalhos } = lerTabela(tabela);
  if (!cabecalhos) return [];
  return (linhas[cabecalhos - 1] ?? []).map((c) => c?.texto ?? '');
}

// o texto tal como mostrado (não o valor bruto de `lerTabela`, pensado para o Excel tipar a célula — aqui o
// utilizador quer copiar o que vê, incluindo o «Kz»/«%» e os separadores em pt-PT).
const textoCelula = (c: CelulaExcel | null): string => (c?.texto ?? '').replace(/\t/g, ' ').replace(/\r?\n/g, ' ');

/**
 * Tabela(s) do conteúdo → texto TSV. `colunas` (índices, 0-based, na ordem do cabeçalho) restringe a cópia a essas
 * colunas; omitido copia a tabela inteira. Várias tabelas no mesmo documento (ex.: um relatório com secções) saem
 * separadas por uma linha em branco.
 */
export function tabelaParaTexto(conteudo: OpcoesDocumento['conteudo'], colunas?: number[]): string {
  const tabelas = tabelasDoConteudo(conteudo).filter(DADOS);
  return tabelas
    .map(({ tabela }) => lerTabela(tabela).linhas.map((linha) => (colunas ?? linha.map((_, i) => i)).map((i) => textoCelula(linha[i])).join('\t')).join('\n'))
    .filter(Boolean)
    .join('\n\n');
}

/** Copia texto para a área de transferência; `execCommand` como recurso em navegadores/contextos sem a API moderna. */
export async function copiarTexto(texto: string): Promise<boolean> {
  try {
    if (navigator.clipboard?.writeText) {
      await navigator.clipboard.writeText(texto);
      return true;
    }
  } catch {
    // alguns navegadores recusam sem interacção direta do utilizador ou fora de um contexto seguro — recurso abaixo
  }
  try {
    const area = document.createElement('textarea');
    area.value = texto;
    area.style.position = 'fixed';
    area.style.opacity = '0';
    document.body.appendChild(area);
    area.focus();
    area.select();
    const ok = document.execCommand('copy');
    document.body.removeChild(area);
    return ok;
  } catch {
    return false;
  }
}
