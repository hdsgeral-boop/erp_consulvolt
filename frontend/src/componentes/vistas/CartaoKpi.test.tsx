import { act, render } from '@testing-library/react';
import { CartaoKpi } from './CartaoKpi';
import { ajustarValor, type ElementoAjustavel, TAMANHO_MINIMO } from './CartaoKpiAjuste';

/** Elemento falso: o texto ocupa `larguraTexto` px com a letra base e encolhe na proporção do tamanho de letra. */
function elemento(largura: number, larguraTexto: number, base: number, larguraNumero = larguraTexto): ElementoAjustavel {
  const el = {
    clientWidth: largura,
    style: { fontSize: '' },
    dataset: {} as { quebra?: string },
    get scrollWidth() {
      const t = el.style.fontSize ? parseFloat(el.style.fontSize) : base;
      const escala = t / base;
      // com a unidade na linha seguinte só conta o número; com «tudo» quebra onde for preciso
      if (el.dataset.quebra === 'tudo') return Math.min(largura, larguraNumero * escala);
      return (el.dataset.quebra === 'unidade' ? larguraNumero : larguraTexto) * escala;
    },
  };
  return el;
}

describe('CartaoKpi — valor sempre completo (sem reticências)', () => {
  it('cabe: fica com o tamanho base, numa linha', () => {
    const el = elemento(200, 150, 20);
    expect(ajustarValor(el, 20)).toBe(20);
    expect(el.style.fontSize).toBe('');
    expect(el.dataset.quebra).toBeUndefined();
  });

  it('não cabe: a letra encolhe até caber (entre o mínimo e o tamanho base)', () => {
    const el = elemento(200, 260, 20);
    const t = ajustarValor(el, 20);
    expect(t).toBeLessThan(20);
    expect(t).toBeGreaterThanOrEqual(TAMANHO_MINIMO);
    expect(el.scrollWidth).toBeLessThanOrEqual(200);
    expect(el.dataset.quebra).toBeUndefined();
  });

  it('nem no mínimo cabe: a unidade passa para a linha seguinte; número enorme quebra por último', () => {
    const el = elemento(100, 400, 20, 150);
    expect(ajustarValor(el, 20)).toBe(TAMANHO_MINIMO);
    expect(el.dataset.quebra).toBe('unidade');

    const enorme = elemento(100, 900, 20, 800);
    ajustarValor(enorme, 20);
    expect(enorme.dataset.quebra).toBe('tudo');
  });

  it('volta ao tamanho base quando o cartão alarga', () => {
    const el = elemento(100, 150, 20);
    ajustarValor(el, 20);
    expect(el.style.fontSize).not.toBe('');
    (el as { clientWidth: number }).clientWidth = 300;
    expect(ajustarValor(el, 20)).toBe(20);
    expect(el.style.fontSize).toBe('');
  });

  it('no cartão: sem reticências, número e unidade separados e a letra ajustada à largura', () => {
    const larguras = vi.spyOn(HTMLElement.prototype, 'clientWidth', 'get').mockImplementation(function (this: HTMLElement) {
      return this.classList.contains('erp-kpi-valor') ? 120 : 0;
    });
    const conteudos = vi.spyOn(HTMLElement.prototype, 'scrollWidth', 'get').mockImplementation(function (this: HTMLElement) {
      if (!this.classList.contains('erp-kpi-valor')) return 0;
      const t = this.style.fontSize ? parseFloat(this.style.fontSize) : 20;
      return 180 * (t / 20);
    });
    const estilo = window.getComputedStyle;
    const computado = vi.spyOn(window, 'getComputedStyle').mockImplementation((el: Element, p?: string | null) =>
      (el as HTMLElement).classList?.contains('erp-kpi-valor') ? ({ fontSize: '20px' } as CSSStyleDeclaration) : estilo(el, p));
    try {
      const { container } = render(<CartaoKpi rotulo="Total facturado" valor="123 456 789,00" unidade="Kz" />);
      const valor = container.querySelector('.erp-kpi-valor') as HTMLElement;
      expect(valor.querySelector('.erp-kpi-numero')).toHaveTextContent('123 456 789,00');
      expect(valor.querySelector('.erp-kpi-unidade')).toHaveTextContent('Kz');
      expect(valor.querySelector('wbr')).not.toBeNull();
      expect(valor).toHaveAttribute('title', '123 456 789,00 Kz');
      expect(parseFloat(valor.style.fontSize)).toBeLessThan(20);
      expect(valor.textContent).not.toContain('…');
      act(() => undefined);
    } finally {
      larguras.mockRestore();
      conteudos.mockRestore();
      computado.mockRestore();
    }
  });
});
