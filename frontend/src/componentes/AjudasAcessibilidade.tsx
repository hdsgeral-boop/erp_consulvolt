import { useEffect } from 'react';

/**
 * Ajudas de acessibilidade globais, sem tocar em cada ecrã (WCAG 1.3.1, 2.4.1, 4.1.2). Observa o DOM e, para cada elemento novo:
 *  - dá `id="conteudo-principal"` ao `<main>` (alvo do «Saltar para o conteúdo»);
 *  - dá nome acessível às caixas de selecção do AntD (`.ant-select` → `input[role=combobox]`) que não o têm: o rótulo do campo do
 *    formulário, ou o texto de substituição (placeholder), ou o valor escolhido, ou «Seleccionar»;
 *  - dá nome às células de cabeçalho vazias (`th`): «Acções» ou, na linha de filtros, «Filtro da coluna».
 * Só acrescenta atributos em falta; nunca substitui os que o ecrã já define.
 */
export function aplicarAjudas(raiz: ParentNode = document): void {
  const main = raiz.querySelector?.('main:not([id])');
  if (main && !document.getElementById('conteudo-principal')) main.id = 'conteudo-principal';

  raiz.querySelectorAll?.<HTMLInputElement>('.ant-select input[role="combobox"]:not([aria-label]):not([aria-labelledby])').forEach((entrada) => {
    if (entrada.labels && entrada.labels.length > 0) return;
    const selector = entrada.closest('.ant-select');
    const rotuloForm = entrada.closest('.ant-form-item')?.querySelector('label')?.textContent?.trim();
    const escolha = selector?.querySelector('.ant-select-content-value, .ant-select-selection-item')?.textContent?.trim();
    const sugestao = selector?.querySelector('.ant-select-placeholder, .ant-select-selection-placeholder')?.textContent?.trim();
    entrada.setAttribute('aria-label', rotuloForm || sugestao || escolha || 'Seleccionar');
  });

  raiz.querySelectorAll?.<HTMLElement>('th:empty:not([aria-label])').forEach((th) => {
    th.setAttribute('aria-label', th.closest('.erp-linha-filtros') ? 'Filtro da coluna' : 'Acções');
  });
}

export function AjudasAcessibilidade() {
  useEffect(() => {
    aplicarAjudas();
    let agendado = false;
    const observador = new MutationObserver(() => {
      if (agendado) return;
      agendado = true;
      requestAnimationFrame(() => {
        agendado = false;
        aplicarAjudas();
      });
    });
    observador.observe(document.body, { childList: true, subtree: true });
    return () => observador.disconnect();
  }, []);
  return null;
}
