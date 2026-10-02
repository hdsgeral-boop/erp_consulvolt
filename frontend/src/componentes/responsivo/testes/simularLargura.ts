/**
 * Apoio a testes (Vitest/jsdom): simula a largura do ecrã para o `window.matchMedia` que o Ant Design
 * (Grid.useBreakpoint) e o useEcra usam. Interpreta `(min-width: Npx)` e `(max-width: Npx)`, combinados com `and`.
 *
 * ```ts
 * simularLargura(375);   // telemóvel
 * afterEach(() => simularLargura(null));   // repõe o simulacro de configuracao-testes.ts (nada corresponde)
 * ```
 */
const original = typeof window !== 'undefined' ? window.matchMedia : undefined;

export function corresponde(query: string, largura: number): boolean {
  const condicoes = [...query.matchAll(/\((min|max)-width:\s*([\d.]+)px\)/g)];
  if (!condicoes.length) return false;
  return condicoes.every(([, tipo, valor]) => (tipo === 'min' ? largura >= Number(valor) : largura <= Number(valor)));
}

export function simularLargura(largura: number | null): void {
  if (largura === null) {
    Object.defineProperty(window, 'matchMedia', { writable: true, configurable: true, value: original });
    return;
  }
  Object.defineProperty(window, 'innerWidth', { writable: true, configurable: true, value: largura });
  Object.defineProperty(window, 'matchMedia', {
    writable: true,
    configurable: true,
    value: (query: string) => ({
      matches: corresponde(query, largura),
      media: query,
      onchange: null,
      addListener: () => undefined,
      removeListener: () => undefined,
      addEventListener: () => undefined,
      removeEventListener: () => undefined,
      dispatchEvent: () => false,
    }),
  });
}
