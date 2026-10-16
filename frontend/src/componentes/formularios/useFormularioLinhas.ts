import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { converterTexto, errosLinha, linhaVazia, mapearColagem, mensagemErro, omissaoDe, partirTexto } from './conversoes';
import type { CampoFormulario, LinhaFormulario, ResumoLinhas } from './tipos';

export interface OpcoesFormularioLinhas {
  /** Campos do formulário; entram na tabela os que não têm `emLinhas: false`. */
  campos: CampoFormulario[];
  /**
   * Grava um registo (uma linha). Deve ser a mesma chamada da grade (mesmo URL e mesma transformação dos valores), para
   * que as regras sejam as mesmas. Rejeitar = a linha falhou (a mensagem do erro fica na linha).
   */
  gravar: (registo: Record<string, unknown>, contexto: { numero: number }) => Promise<unknown>;
  /** Linhas em branco ao abrir (legado: 5). */
  linhasIniciais?: number;
  /** Valores por omissão vindos do formulário da grade (ex.: milestone já escolhido); sobrepõem-se a `campo.omissao`. */
  omissoes?: Record<string, unknown>;
}

export interface ResultadoColagem {
  acrescentadas: number;
  naoReconhecidos: number;
  comCabecalho: boolean;
  /** Exemplos de valores não reconhecidos por coluna (rótulo → valores). */
  exemplos: Record<string, string[]>;
}

/** Avisos devolvidos pela API (ex.: `{ avisos: [...] }` dos projectos). */
function avisosDe(r: unknown): string[] {
  const a = (r as { dados?: { avisos?: unknown }; avisos?: unknown } | null)?.dados?.avisos ?? (r as { avisos?: unknown } | null)?.avisos;
  return Array.isArray(a) ? a.filter((x): x is string => typeof x === 'string') : [];
}

/**
 * Estado da vista «Linhas» (uma linha por registo): adicionar, duplicar, remover, limpar, colar do Excel, validar e
 * gravar linha a linha. As linhas gravadas saem da tabela; as que falham ficam com a mensagem do erro para corrigir
 * (legado: «As que falharem voltam à tabela para corrigir»).
 */
export function useFormularioLinhas({ campos: todos, gravar, linhasIniciais = 5, omissoes: omissoesForm }: OpcoesFormularioLinhas) {
  const campos = useMemo(() => todos.filter((c) => c.emLinhas !== false), [todos]);
  const omissoes = useMemo(() => {
    const o: Record<string, unknown> = {};
    for (const c of campos) o[c.nome] = omissoesForm && omissoesForm[c.nome] !== undefined ? omissoesForm[c.nome] : omissaoDe(c);
    return o;
  }, [campos, omissoesForm]);
  const contador = useRef(0);
  const nova = useCallback((valores?: Record<string, unknown>, invalidos?: Record<string, string>): LinhaFormulario => {
    contador.current += 1;
    return { id: contador.current, valores: { ...omissoes, ...(valores ?? {}) }, invalidos: invalidos && Object.keys(invalidos).length ? invalidos : undefined };
  }, [omissoes]);
  const [linhas, setLinhasEstado] = useState<LinhaFormulario[]>(() => Array.from({ length: linhasIniciais }, () => nova()));
  const ref = useRef(linhas);
  const setLinhas = useCallback((f: (l: LinhaFormulario[]) => LinhaFormulario[]) => {
    ref.current = f(ref.current);
    setLinhasEstado(ref.current);
  }, []);
  const [aGravar, setAGravar] = useState(false);
  const [progresso, setProgresso] = useState<{ feitas: number; total: number } | null>(null);
  const cancelar = useRef(false);

  // Omissões que chegam depois de abrir (o formulário da grade é preenchido num efeito): aplicam-se às linhas ainda em branco
  const omissoesAntes = useRef(omissoes);
  const chaveOmissoes = JSON.stringify(omissoes);
  useEffect(() => {
    const antes = omissoesAntes.current;
    omissoesAntes.current = omissoes;
    if (JSON.stringify(antes) === chaveOmissoes) return;
    setLinhas((ls) => ls.map((l) => (linhaVazia(campos, l.valores, antes) && !l.invalidos ? { ...l, valores: { ...omissoes } } : l)));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [chaveOmissoes]);

  const eVazia = useCallback((l: LinhaFormulario) => linhaVazia(campos, l.valores, omissoes) && !l.invalidos, [campos, omissoes]);
  const preenchidas = useMemo(() => linhas.filter((l) => !eVazia(l)), [linhas, eVazia]);

  /** Acrescenta uma linha (no fim ou depois de `depoisDe`) e devolve o id. */
  const adicionar = useCallback((valores?: Record<string, unknown>, depoisDe?: number): number => {
    const l = nova(valores);
    setLinhas((ls) => {
      const i = depoisDe === undefined ? -1 : ls.findIndex((x) => x.id === depoisDe);
      return i < 0 ? [...ls, l] : [...ls.slice(0, i + 1), l, ...ls.slice(i + 1)];
    });
    return l.id;
  }, [nova, setLinhas]);

  /** Nova linha igual à última linha preenchida (legado: «Duplicar última»). */
  const duplicarUltima = useCallback((): number => {
    const p = ref.current.filter((l) => !eVazia(l));
    return adicionar(p.length ? p[p.length - 1].valores : undefined);
  }, [adicionar, eVazia]);

  /** Cópia de uma linha logo a seguir a ela (Ctrl+D). */
  const duplicar = useCallback((id: number): number => {
    const l = ref.current.find((x) => x.id === id);
    return adicionar(l?.valores, id);
  }, [adicionar]);

  const remover = useCallback((id: number) => setLinhas((ls) => ls.filter((l) => l.id !== id)), [setLinhas]);

  /** Repõe os valores por omissão de uma linha (Ctrl+Del). */
  const limpar = useCallback((id: number) => setLinhas((ls) => ls.map((l) => (l.id === id ? { id: l.id, valores: { ...omissoes } } : l))), [setLinhas, omissoes]);

  const definir = useCallback((id: number, nome: string, valor: unknown) => setLinhas((ls) => ls.map((l) => {
    if (l.id !== id) return l;
    const invalidos = l.invalidos ? { ...l.invalidos } : undefined;
    if (invalidos) delete invalidos[nome];
    return { ...l, valores: { ...l.valores, [nome]: valor }, invalidos: invalidos && Object.keys(invalidos).length ? invalidos : undefined, erro: undefined };
  })), [setLinhas]);

  /**
   * Cola texto do Excel. Sem `inicio`: retira as linhas em branco e acrescenta as coladas no fim (legado). Com `inicio`
   * (colagem directa numa célula): preenche a partir dessa linha e coluna, criando as linhas que faltarem.
   * `colunas` = colunas visíveis, pela ordem da tabela (a ordem com que se lê o Excel sem cabeçalho).
   */
  const colar = useCallback((texto: string, opcoes: { colunas?: CampoFormulario[]; inicio?: { id: number; nome: string } } = {}): ResultadoColagem => {
    const colunas = opcoes.colunas ?? campos;
    const res: ResultadoColagem = { acrescentadas: 0, naoReconhecidos: 0, comCabecalho: false, exemplos: {} };
    const celulas = partirTexto(texto);
    if (!celulas.length) return res;
    let alvo = colunas;
    if (opcoes.inicio) {
      const i = colunas.findIndex((c) => c.nome === opcoes.inicio!.nome);
      alvo = colunas.slice(Math.max(0, i));
    }
    const { linhas: valoresTexto, comCabecalho } = opcoes.inicio ? { linhas: celulas, comCabecalho: false } : mapearColagem(celulas, alvo);
    res.comCabecalho = comCabecalho;
    const convertidas = valoresTexto.map((vs) => {
      const valores: Record<string, unknown> = {};
      const invalidos: Record<string, string> = {};
      alvo.forEach((c, i) => {
        const t = vs[i];
        // célula ausente: fica o valor por omissão; em branco numa linha nova também (ex.: data de hoje)
        if (t === undefined || (!opcoes.inicio && !t.trim())) return;
        const { ok, valor } = converterTexto(c, t);
        valores[c.nome] = valor;
        if (!ok) {
          invalidos[c.nome] = t;
          res.naoReconhecidos += 1;
          const ex = (res.exemplos[c.rotulo] ??= []);
          if (ex.length < 5 && !ex.includes(t)) ex.push(t);
        }
      });
      return { valores, invalidos };
    });
    setLinhas((ls) => {
      if (opcoes.inicio) {
        const copia = [...ls];
        let i = copia.findIndex((l) => l.id === opcoes.inicio!.id);
        if (i < 0) i = copia.length;
        convertidas.forEach((cv) => {
          if (i < copia.length) {
            const l = copia[i];
            const inv = { ...(l.invalidos ?? {}) };
            Object.keys(cv.valores).forEach((k) => delete inv[k]);
            Object.assign(inv, cv.invalidos);
            copia[i] = { ...l, valores: { ...l.valores, ...cv.valores }, invalidos: Object.keys(inv).length ? inv : undefined, erro: undefined };
          } else {
            copia.push(nova(cv.valores, cv.invalidos));
            res.acrescentadas += 1;
          }
          i += 1;
        });
        return copia;
      }
      const restantes = ls.filter((l) => !eVazia(l));
      res.acrescentadas = convertidas.length;
      return [...restantes, ...convertidas.map((cv) => nova(cv.valores, cv.invalidos))];
    });
    return res;
  }, [campos, eVazia, nova, setLinhas]);

  /** Valida as linhas preenchidas e marca as que têm erro; devolve quantas têm erro. */
  const validar = useCallback((): number => {
    let n = 0;
    setLinhas((ls) => ls.map((l) => {
      if (eVazia(l)) return l.erro ? { ...l, erro: undefined } : l;
      const e = errosLinha(campos, l.valores, l.invalidos);
      if (e) n += 1;
      return { ...l, erro: e ?? undefined };
    }));
    return n;
  }, [campos, eVazia, setLinhas]);

  /** Grava as linhas preenchidas uma a uma (pela ordem da tabela). Valida antes; com erros não grava nada. */
  const gravarTodas = useCallback(async (): Promise<ResumoLinhas & { invalidas: number }> => {
    const invalidas = validar();
    const fila = ref.current.filter((l) => !eVazia(l));
    const resumo = { total: fila.length, gravadas: 0, falhadas: 0, avisos: [] as string[], cancelado: false, invalidas };
    if (invalidas || !fila.length) return { ...resumo, falhadas: invalidas };
    cancelar.current = false;
    setAGravar(true);
    setProgresso({ feitas: 0, total: fila.length });
    try {
      let feitas = 0;
      for (const l of fila) {
        const numero = ref.current.findIndex((x) => x.id === l.id) + 1;
        if (cancelar.current) {
          resumo.cancelado = true;
          resumo.falhadas += 1;
          setLinhas((ls) => ls.map((x) => (x.id === l.id ? { ...x, erro: 'Não gravada (cancelado).' } : x)));
        } else {
          try {
            const r = await gravar({ ...omissoes, ...l.valores }, { numero });
            resumo.gravadas += 1;
            resumo.avisos.push(...avisosDe(r).map((a) => `Linha ${numero}: ${a}`));
            setLinhas((ls) => ls.filter((x) => x.id !== l.id));
          } catch (e) {
            resumo.falhadas += 1;
            const m = mensagemErro(e);
            setLinhas((ls) => ls.map((x) => (x.id === l.id ? { ...x, erro: m } : x)));
          }
        }
        feitas += 1;
        setProgresso({ feitas, total: fila.length });
      }
    } finally {
      setAGravar(false);
      setProgresso(null);
      // tabela vazia depois de gravar: volta a ter linhas em branco para continuar
      if (!ref.current.length) setLinhas(() => Array.from({ length: linhasIniciais }, () => nova()));
    }
    return resumo;
  }, [validar, eVazia, gravar, omissoes, setLinhas, linhasIniciais, nova]);

  /** Recomeça com linhas em branco (ex.: ao reabrir o modal). */
  const repor = useCallback(() => setLinhas(() => Array.from({ length: linhasIniciais }, () => nova())), [setLinhas, linhasIniciais, nova]);

  return {
    campos,
    linhas,
    omissoes,
    preenchidas,
    eVazia,
    adicionar,
    duplicarUltima,
    duplicar,
    remover,
    limpar,
    definir,
    colar,
    validar,
    gravarTodas,
    repor,
    aGravar,
    progresso,
    cancelar: () => {
      cancelar.current = true;
    },
  };
}

export type EstadoFormularioLinhas = ReturnType<typeof useFormularioLinhas>;
