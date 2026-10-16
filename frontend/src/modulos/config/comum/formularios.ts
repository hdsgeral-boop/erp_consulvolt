import dayjs from 'dayjs';
import { dataApi } from '@/utilitarios/formatacao';

/**
 * Apoio aos formulários «Grade | Linhas» das Configurações (componentes/formularios): a grade dá datas em `dayjs` e a
 * vista Linhas em 'AAAA-MM-DD' — as funções `dados*` dos ecrãs aceitam as duas formas e dão o mesmo corpo do pedido.
 */
export const dataDe = (d: unknown): string | null => (dayjs.isDayjs(d) ? dataApi(d) ?? null : typeof d === 'string' && d ? d : null);

/** Opções «Sim / Não» das colunas booleanas da vista Linhas. */
export const OPCOES_SIM_NAO = [{ value: 1, label: 'Sim' }, { value: 0, label: 'Não' }];

/** Valor booleano vindo da grade (checkbox/switch) ou das linhas (1/0). */
export const simNao = (v: unknown): boolean => v === true || v === 1 || v === '1';

/**
 * Caixas/interruptores da grade guardados como 1/0 (como as colunas «Sim/Não» da vista Linhas): assim os valores da
 * grade passam sem conversão para as omissões das linhas. A gravação converte com `simNao`.
 */
export const PROPS_SIM_NAO = {
  valuePropName: 'checked',
  getValueProps: (v: unknown) => ({ checked: v === true || v === 1 || v === '1' }),
  normalize: (v: unknown) => (v ? 1 : 0),
} as const;
