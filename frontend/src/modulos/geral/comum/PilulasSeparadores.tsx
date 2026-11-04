import type { CSSProperties, KeyboardEvent } from 'react';
import { IconeFa } from '@/componentes/fluxos';
import { estiloPainel } from './estiloPainel';
import './painel.css';

export interface ItemPilula {
  id: string;
  nome: string;
  /** Ícone Font Awesome; sem ele usa-se o de `estiloPainel(id)`. */
  icone?: string | null;
}

/**
 * Separadores em pílula do Dashboard Global (js/ui_painel_modulos.js:1013-1046), partilhados com o Fluxo de Processos:
 * ícone e cor do módulo (`estiloPainel`), várias linhas, activo com a cor do módulo. `papel="botoes"` (Dashboard) usa
 * botões com `aria-pressed`; `papel="separadores"` (Fluxo de Processos) usa `tablist`/`tab` com setas, Home e End.
 */
export function PilulasSeparadores({
  itens,
  activo,
  aoEscolher,
  rotulo,
  papel = 'botoes',
}: {
  itens: ItemPilula[];
  activo: string;
  aoEscolher: (id: string) => void;
  rotulo: string;
  papel?: 'botoes' | 'separadores';
}) {
  const comoSeparadores = papel === 'separadores';
  const teclas = (ev: KeyboardEvent<HTMLElement>) => {
    if (!comoSeparadores) return;
    const i = itens.findIndex((f) => f.id === activo);
    const alvo = ev.key === 'ArrowRight' ? i + 1 : ev.key === 'ArrowLeft' ? i - 1 : ev.key === 'Home' ? 0 : ev.key === 'End' ? itens.length - 1 : null;
    if (alvo === null || alvo < 0 || alvo >= itens.length) return;
    ev.preventDefault();
    aoEscolher(itens[alvo].id);
    (ev.currentTarget.querySelectorAll('button')[alvo] as HTMLButtonElement | undefined)?.focus();
  };
  const botoes = itens.map((p) => {
    const e = estiloPainel(p.id);
    const eActivo = p.id === activo;
    return (
      <button
        key={p.id}
        type="button"
        className={`painel-modulo-btn${eActivo ? ' activo' : ''}`}
        style={{ '--painel-cor': e.cor } as CSSProperties}
        onClick={() => aoEscolher(p.id)}
        {...(comoSeparadores ? { role: 'tab', 'aria-selected': eActivo, tabIndex: eActivo ? 0 : -1 } : { 'aria-pressed': eActivo })}
      >
        <IconeFa nome={p.icone ?? e.icone} className="painel-modulo-icone" />
        <span>{p.nome}</span>
      </button>
    );
  });
  return comoSeparadores ? (
    <div className="painel-modulos" role="tablist" aria-label={rotulo} onKeyDown={teclas}>
      {botoes}
    </div>
  ) : (
    <nav className="painel-modulos" aria-label={rotulo}>
      {botoes}
    </nav>
  );
}
