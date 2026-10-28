import { useCallback, useEffect, useLayoutEffect, useRef, useState } from 'react';
import { usePreferencias } from '@/componentes/preferencias/usePreferencias';

/**
 * Aspecto da interface por utilizador (Versão Actual: js/tela_noturna.js e js/aspecto_menu.js):
 *  - tema: «claro» ou «escuro» (tela nocturna, botão na barra superior e Ctrl+Shift+N);
 *  - menu: «completo» (símbolo + nome), «icones» (só símbolos, nome ao passar o rato) ou «nomes» (só nomes).
 *
 * Guardado no servidor (PUT /api/sistema/preferencias/interface/aspecto_nucleo — muda de posto com o utilizador) e com
 * cópia no navegador por utilizador, lida de forma síncrona no arranque do layout para aplicar o tema antes da primeira
 * pintura (sem clarão). No legado ficava só no localStorage (`tela-noturna_<user>`, `nav-aspecto_<user>`).
 */
export type TemaInterface = 'claro' | 'escuro';
export type AspectoMenu = 'completo' | 'icones' | 'nomes';

export interface AspectoInterface {
  tema: TemaInterface;
  menu: AspectoMenu;
}

export const NOME_PREFERENCIA_ASPECTO = 'aspecto_nucleo';
export const ASPECTO_PADRAO: AspectoInterface = { tema: 'claro', menu: 'completo' };
export const CLASSE_TELA_NOCTURNA = 'erp-tela-nocturna';

export const chaveLocalAspecto = (utilizador?: number | string | null) => `erp.aspecto.${utilizador ?? 'anonimo'}`;

/** Valida um valor lido (servidor ou navegador): o que não for conhecido fica com o valor por omissão. */
export function normalizarAspecto(v: unknown): AspectoInterface {
  const o = v && typeof v === 'object' ? (v as Record<string, unknown>) : {};
  return {
    tema: o.tema === 'escuro' ? 'escuro' : 'claro',
    menu: o.menu === 'icones' || o.menu === 'nomes' ? o.menu : 'completo',
  };
}

export function lerAspectoLocal(utilizador?: number | string | null): AspectoInterface {
  try {
    const t = window.localStorage.getItem(chaveLocalAspecto(utilizador));
    return t ? normalizarAspecto(JSON.parse(t)) : ASPECTO_PADRAO;
  } catch {
    return ASPECTO_PADRAO;
  }
}

function gravarLocal(utilizador: number | string | null | undefined, a: AspectoInterface): void {
  try {
    window.localStorage.setItem(chaveLocalAspecto(utilizador), JSON.stringify(a));
  } catch {
    /* armazenamento indisponível: fica só nesta sessão */
  }
}

/** Aplica (ou retira) a classe da tela nocturna no elemento raiz e o `color-scheme` (barras de deslocamento, campos nativos). */
export function aplicarTema(tema: TemaInterface): void {
  const raiz = document.documentElement;
  raiz.classList.toggle(CLASSE_TELA_NOCTURNA, tema === 'escuro');
  raiz.dataset.tema = tema;
  raiz.style.colorScheme = tema === 'escuro' ? 'dark' : '';
}

/** Aspecto do utilizador com gravação no servidor; o tema é aplicado ao documento enquanto o layout estiver montado. */
export function useAspecto(utilizador?: number | string | null): { aspecto: AspectoInterface; definir: (parcial: Partial<AspectoInterface>) => void } {
  const [aspecto, setAspecto] = useState<AspectoInterface>(() => lerAspectoLocal(utilizador));
  const alteradoNoPosto = useRef(false);
  const { lista, consulta, gravar } = usePreferencias<AspectoInterface>('interface');

  // o servidor manda (outro posto), salvo se entretanto o utilizador mudou aqui
  useEffect(() => {
    if (!consulta.isSuccess || alteradoNoPosto.current) return;
    const p = lista.find((x) => x?.nome === NOME_PREFERENCIA_ASPECTO);
    if (!p) return;
    const v = normalizarAspecto(p.valor);
    setAspecto((a) => (a.tema === v.tema && a.menu === v.menu ? a : v));
    gravarLocal(utilizador, v);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [consulta.isSuccess, consulta.dataUpdatedAt]);

  // antes da pintura: sem clarão ao entrar com a tela nocturna
  useLayoutEffect(() => {
    aplicarTema(aspecto.tema);
  }, [aspecto.tema]);
  useLayoutEffect(() => () => aplicarTema('claro'), []);

  const definir = useCallback(
    (parcial: Partial<AspectoInterface>) => {
      setAspecto((a) => {
        const novo = normalizarAspecto({ ...a, ...parcial });
        alteradoNoPosto.current = true;
        gravarLocal(utilizador, novo);
        gravar({ nome: NOME_PREFERENCIA_ASPECTO, valor: novo });
        return novo;
      });
    },
    [utilizador, gravar],
  );

  return { aspecto, definir };
}
