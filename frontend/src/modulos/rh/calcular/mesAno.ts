/**
 * Campo «Digitar Mês/Ano (ex: 01/2026)» do passo 1 do Calcular (legado `openPeriod`: /^\d{2}\/\d{4}$/).
 * Aceita também «1/2026», «01-2026» e «012026»; devolve «MM/AAAA» ou null se o mês ou o ano forem inválidos.
 */
export function normalizarMesAno(texto: string): string | null {
  const t = texto.trim();
  const m = /^(\d{1,2})\s*[/.-]?\s*(\d{4})$/.exec(t);
  if (!m) return null;
  const mes = Number(m[1]);
  const ano = Number(m[2]);
  if (mes < 1 || mes > 12 || ano < 2000 || ano > 2100) return null;
  return `${String(mes).padStart(2, '0')}/${ano}`;
}
