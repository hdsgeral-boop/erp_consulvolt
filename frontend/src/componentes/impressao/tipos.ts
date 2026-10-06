/**
 * Tipos do motor de impressão/PDF.
 *
 * O PDF é gerado pelo motor de impressão do navegador: o documento HTML é montado numa iframe oculta, com um
 * `@page` de tamanho/orientação calculados, e o utilizador escolhe «Guardar como PDF» no diálogo de impressão.
 */

export type Orientacao = 'auto' | 'retrato' | 'paisagem';
export type Papel = 'auto' | 'A4' | 'A3';

/** Formato final da página, decidido pela medição do conteúdo (ou imposto pelo ecrã). */
export interface FormatoPagina {
  papel: 'A4' | 'A3';
  orientacao: 'retrato' | 'paisagem';
  /** Largura útil (sem margens) em mm. */
  larguraUtilMm: number;
  /** Escala aplicada ao conteúdo (1 = 100 %). */
  escala: number;
  /** Permite quebrar o texto das células (só quando nem a escala mínima chega). */
  quebrarTexto: boolean;
}

/** Dados da empresa no cabeçalho/rodapé (compatível com IdentidadeEmpresa de `@/sessao/identidade`). */
export interface IdentidadeImpressao {
  nome: string;
  nif?: string | null;
  morada?: string | null;
  telefone?: string | null;
  email?: string | null;
  website?: string | null;
  registo_comercial?: string | null;
  rodape?: string | null;
  /** data URI do logótipo, ou null */
  logotipo?: string | null;
}

export interface OpcoesDocumento {
  /** Título do documento (cabeçalho e, por omissão, nome do ficheiro). */
  titulo: string;
  subtitulo?: string | null;
  /** Período do mapa (ex.: «Janeiro de 2026», «01/01/2026 a 31/01/2026»). */
  periodo?: string | null;
  /** Filtros aplicados (texto livre ou lista «Rótulo: valor»). */
  filtros?: string | (string | null | undefined | false)[] | null;
  identidade?: IdentidadeImpressao | null;
  /** Utilizador que emite (nome a mostrar). */
  utilizador?: string | null;
  /** Data/hora de emissão (por omissão: agora). */
  emitidoEm?: Date;
  /** HTML do conteúdo, ou um elemento do ecrã (é clonado e limpo; o original não é alterado). */
  conteudo: string | Element;
  orientacao?: Orientacao;
  papel?: Papel;
  /** Nome sugerido para o PDF (sem extensão). Por omissão: «Título - Empresa - AAAA-MM-DD». */
  nomeFicheiro?: string | null;
  /** Rodapé de cada página; por omissão o rodapé da empresa (identidade.rodape). */
  rodape?: string | null;
  /**
   * Copia as folhas de estilo do ecrã (Ant Design, CSS dos módulos) para o documento.
   * Por omissão: sim quando `conteudo` é um elemento; não quando é HTML gerado (que usa só o CSS do motor).
   */
  estilosDaPagina?: boolean;
  /** CSS adicional (ex.: estilos próprios de um mapa). */
  cssExtra?: string;
  /** Mostrar o cabeçalho da empresa (por omissão: sim). */
  cabecalho?: boolean;
  /**
   * Partir o documento em folhas pelo próprio motor, com «Página X de Y» e o rodapé da empresa em cada folha
   * (funciona no Chromium/Edge e no Firefox). Por omissão: sim. Com `false` usa as caixas de margem do @page
   * (só Chromium/Edge mostram a numeração).
   */
  paginar?: boolean;
  /**
   * Documento em várias vias na mesma folha (recibos): cada via leva o cabeçalho da empresa, o título e o rótulo da via
   * («Original», «Duplicado»…), separadas por uma linha de corte. Em retrato as vias ficam uma por cima da outra (cada uma
   * em meia folha); em paisagem ficam lado a lado. As vias nunca se partem entre folhas: se não couberem, são reduzidas.
   * `true` → «Original» e «Duplicado». Ver `OpcoesVias` para vários documentos (um por folha).
   */
  vias?: boolean | string[] | OpcoesVias;
  /** Mostra o bloco do título (título, subtítulo, período, filtros). Por omissão: sim. */
  blocoTitulo?: boolean;
}

/** Vias na mesma folha. */
export interface OpcoesVias {
  /** Rótulos das vias (por omissão «Original» e «Duplicado»). */
  rotulos?: string[];
  /**
   * Vários documentos (ex.: recibos de vários colaboradores), cada um com as suas vias numa folha própria. Sem `partes`
   * usa-se o `conteudo` do documento.
   */
  partes?: { conteudo: string; subtitulo?: string | null }[];
}

/** Preferência do utilizador para a página (escolhida no botão de impressão; «auto» = decisão do motor/do ecrã). */
export interface PreferenciaPagina {
  orientacao: Orientacao;
  papel: Papel;
}

export interface OpcoesImpressao extends OpcoesDocumento {
  /** 'pdf' mostra a dica «Guardar como PDF»; o documento é o mesmo. */
  modo?: 'imprimir' | 'pdf';
}

export interface DocumentoPreparado {
  /** HTML final, autónomo (o mesmo que é impresso). */
  html: string;
  formato: FormatoPagina;
  nomeFicheiro: string;
  /** Número de folhas (0 quando o documento não foi paginado pelo motor). */
  paginas: number;
}
