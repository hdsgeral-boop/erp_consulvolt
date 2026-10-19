import { useCallback, useState } from 'react';
import { useInRouterContext, useSearchParams } from 'react-router-dom';

/**
 * Separador activo de um ecrã guardado no endereço (`?sep=<chave>`), para que a pesquisa do cabeçalho
 * («Separador › Ecrã › Módulo»), os favoritos e as ligações abram directamente o separador pedido.
 *
 *  - lê `?sep=` e, por compatibilidade, `?separador=` (ligações antigas) — o primeiro que for uma chave válida;
 *  - chave desconhecida, sem permissão (não está em `chaves`) ou ausente → `padrao` (se válido) ou o primeiro separador;
 *  - ao mudar de separador substitui a entrada do histórico (`replace`): o botão «Voltar» sai do ecrã em vez de saltar
 *    entre separadores; os outros parâmetros do endereço mantêm-se e o `?separador=` antigo é retirado;
 *  - fora de um Router (testes isolados) funciona como um estado local.
 *
 * Uso: `const [sep, setSep] = useSeparadorUrl(itens.map((i) => i.key));  <Tabs activeKey={sep} onChange={setSep} items={itens} />`
 */
export const PARAMETRO_SEPARADOR = 'sep';
const PARAMETROS_LEITURA = [PARAMETRO_SEPARADOR, 'separador'] as const;

/** Separador a mostrar dada a consulta do endereço (função pura, também usada nos testes). */
export function separadorDaConsulta<K extends string>(consulta: URLSearchParams | string, chaves: readonly K[], padrao?: K): K {
  const p = typeof consulta === 'string' ? new URLSearchParams(consulta) : consulta;
  for (const nome of PARAMETROS_LEITURA) {
    const v = p.get(nome);
    if (v && (chaves as readonly string[]).includes(v)) return v as K;
  }
  if (padrao !== undefined && chaves.includes(padrao)) return padrao;
  return (chaves[0] ?? padrao ?? '') as K;
}

/** Nova consulta com o separador `chave` (mantém os outros parâmetros; retira o `separador=` antigo). */
export function consultaComSeparador(consulta: URLSearchParams | string, chave: string): URLSearchParams {
  const n = new URLSearchParams(consulta);
  n.delete('separador');
  n.set(PARAMETRO_SEPARADOR, chave);
  return n;
}

function useSeparadorEndereco<K extends string>(chaves: readonly K[], padrao?: K): [K, (chave: string) => void] {
  const [parametros, setParametros] = useSearchParams();
  const activo = separadorDaConsulta(parametros, chaves, padrao);
  const mudar = useCallback(
    (chave: string) => setParametros((antes) => consultaComSeparador(antes, chave), { replace: true }),
    [setParametros],
  );
  return [activo, mudar];
}

function useSeparadorLocal<K extends string>(chaves: readonly K[], padrao?: K): [K, (chave: string) => void] {
  const [escolhido, setEscolhido] = useState<string>('');
  const activo = separadorDaConsulta(escolhido ? `${PARAMETRO_SEPARADOR}=${encodeURIComponent(escolhido)}` : '', chaves, padrao);
  return [activo, setEscolhido];
}

export function useSeparadorUrl<K extends string>(chaves: readonly K[], padrao?: K): [K, (chave: string) => void] {
  // estar ou não dentro de um Router não muda durante a vida do componente: a ordem dos hooks é estável
  const comRouter = useInRouterContext();
  // eslint-disable-next-line react-hooks/rules-of-hooks
  return comRouter ? useSeparadorEndereco(chaves, padrao) : useSeparadorLocal(chaves, padrao);
}
