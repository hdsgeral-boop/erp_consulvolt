import { useCallback } from 'react';
import { usePreferenciasVista, type ModoVista } from '@/componentes/vistas/preferenciaVista';

/**
 * Última vista («grade» ou «linhas») de cada formulário de criação, por utilizador.
 *
 * Legado: `localStorage['formLinhas.<chave>']` (por posto). Aqui fica na mesma preferência de servidor das vistas das
 * listas (`interface/vistas_listas`, com cópia no navegador — ver `componentes/vistas/preferenciaVista.ts`), na entrada
 * `ecras['formulario:<chave>']`: acompanha o utilizador entre postos e não gasta mais entradas de preferências.
 */
export const chaveVistaFormulario = (chave: string) => `formulario:${chave}`;

export function useVistaFormulario(chave: string): [ModoVista, (v: ModoVista) => void] {
  const { valor, gravar } = usePreferenciasVista();
  const k = chaveVistaFormulario(chave);
  const vista: ModoVista = valor.ecras?.[k] === 'linhas' ? 'linhas' : 'grade';
  const definir = useCallback(
    (v: ModoVista) => {
      if ((valor.ecras?.[k] ?? 'grade') === v) return;
      gravar({ ...valor, ecras: { ...(valor.ecras ?? {}), [k]: v } });
    },
    [gravar, valor, k],
  );
  return [vista, definir];
}
