/**
 * «Gerar texto a partir da configuração» do comunicado de abertura do ciclo 360º (legado aval360_dados.js
 * textoProcedimentos). Texto simples (o portal mostra-o como texto, sem HTML).
 */
export interface ConfigComunicado {
  nome: string;
  data_inicio?: string | null;
  data_fim?: string | null;
  pesos?: Partial<Record<'CHEFIA' | 'AUTO' | 'PARES' | 'SUBORDINADOS', number | null>> | null;
  minimo_anonimato?: number | null;
  prazos?: { respostas_ate?: string | null; chefia_ate?: string | null; dias_conhecimento?: number | null; dias_contestacao?: number | null } | null;
  feedback?: { periodicidade?: string | null } | null;
  bonificacao?: { metodo?: string | null } | null;
}

const GRUPOS = { CHEFIA: 'Chefia', AUTO: 'Autoavaliação', PARES: 'Pares', SUBORDINADOS: 'Subordinados' } as const;
const PERIODICIDADES: Record<string, string> = { MENSAL: 'Mensal', TRIMESTRAL: 'Trimestral', SEMESTRAL: 'Semestral' };
const METODOS: Record<string, string> = {
  PERCENTAGEM: '% da remuneração mensal por classificação', FIXO: 'Valor fixo por classificação', BOLSA: 'Bolsa distribuída por pontos',
};

const data = (x?: string | null) => {
  const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(x ?? ''));
  return m ? `${m[3]}/${m[2]}/${m[1]}` : '—';
};

export function textoProcedimentos(c: ConfigComunicado): string {
  const pesos = (Object.keys(GRUPOS) as (keyof typeof GRUPOS)[]).filter((k) => Number(c.pesos?.[k] ?? 0) > 0).map((k) => `${GRUPOS[k]} ${Number(c.pesos?.[k])}%`).join(', ');
  const per = c.feedback?.periodicidade ?? 'NENHUM';
  const metodo = c.bonificacao?.metodo ?? 'NENHUM';
  const comFeedback = per !== 'NENHUM' && PERIODICIDADES[per];
  const linhas = [
    `Está aberto o ciclo «${c.nome}», relativo ao período de ${data(c.data_inicio)} a ${data(c.data_fim)}.`,
    '',
    'Como funciona a avaliação 360º:',
    `1. Cada colaborador é avaliado pela chefia directa, por si próprio (autoavaliação), pelos colegas da unidade (pares) e, quando tem equipa, pelos subordinados. Pesos na nota final: ${pesos || '—'}.`,
    `2. As avaliações dos pares e dos subordinados são anónimas: só se mostram médias por grupo e apenas com pelo menos ${c.minimo_anonimato ?? 3} respostas.`,
    `3. Faça a autoavaliação e as avaliações que lhe forem atribuídas no Portal do Colaborador › Avaliação até ${data(c.prazos?.respostas_ate)}.`,
    `4. As chefias concluem a avaliação da sua equipa até ${data(c.prazos?.chefia_ate)}.`,
    `5. Depois de a chefia concluir, o colaborador toma conhecimento no portal (até ${c.prazos?.dias_conhecimento ?? 10} dias) e pode comentar.`,
    `6. Pode contestar a avaliação, fundamentadamente, até ${c.prazos?.dias_contestacao ?? 10} dias depois de tomar conhecimento. A contestação é decidida pelo superior da chefia, com parecer do RH.`,
    comFeedback ? `7. Ao longo do ciclo realizam-se reuniões de acompanhamento com periodicidade ${PERIODICIDADES[per].toLowerCase()}, registadas pela chefia e confirmadas pelo colaborador no portal.` : '',
    metodo !== 'NENHUM' && METODOS[metodo] ? `${comFeedback ? 8 : 7}. Bonificação: ${METODOS[metodo].toLowerCase()}, calculada sobre a classificação final (depois de decididas as contestações) e sujeita a aprovação.` : '',
    '',
    'Em caso de dúvida contacte o departamento de Recursos Humanos.',
  ];
  return linhas.filter((l, i, a) => l !== '' || a[i - 1] !== '').join('\n');
}

/** Ciclo que o ecrã de configuração abre por omissão: o aberto; senão o rascunho mais recente; senão o primeiro. */
export function cicloActivo<T extends { id: number; estado: string; ano: number }>(ciclos: T[]): T | undefined {
  return ciclos.find((c) => c.estado === 'ABERTO') ?? [...ciclos].filter((c) => c.estado === 'RASCUNHO').sort((a, b) => b.ano - a.ano || b.id - a.id)[0] ?? ciclos[0];
}
