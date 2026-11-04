/**
 * Estado da sessão no navegador — ADR-082: NADA de autenticação em armazenamento do navegador.
 *
 *  - o token vai num cookie HttpOnly emitido pelo servidor (invisível a JavaScript);
 *  - o identificador do dispositivo é outro cookie HttpOnly, também emitido e assinado pelo servidor;
 *  - a empresa activa é estado da SESSÃO no servidor (PUT /autenticacao/empresa-activa; GET /autenticacao/eu devolve-a):
 *    aqui só existe em memória (por separador), para ser enviada em X-Empresa-Id e não depender de um pedido por consulta;
 *  - a ordem «última empresa usada» vem do servidor (`ultima_empresa_id` em /eu).
 *
 * Por isso este módulo NÃO escreve em localStorage/sessionStorage/IndexedDB. Só apaga chaves que versões anteriores
 * deixaram no navegador (`erp.dispositivo`, `erp.empresa`, `erp.empresas_recentes.*`), e converte as chaves de interface
 * antigas (com o id do utilizador legível) para chaves opacas (ver chaveOpaca.ts).
 */
let empresaActiva: number | null = null;

const CHAVES_SESSAO_ANTIGAS = [/^erp\.dispositivo$/, /^erp\.empresa$/, /^erp\.empresas_recentes\./];

/** Prefixos de preferências de interface cuja chave antiga terminava no id numérico do utilizador/empresa. */
const PADROES_A_CONVERTER: RegExp[] = [
  /^(erp\.aspecto\.)(\d+)$/,
  /^(erp\.menu\.largura\.)(\d+)$/,
  /^(erp\.menu\.modo\.)(\d+)$/,
  /^(erp\.plataforma\.menu\.modo\.)(\d+)$/,
  /^(erp\.licenca\.avisoVisto\.)(\d+)$/,
  /^(erp\.[A-Za-z_]+\.posicao\.)(\d+)$/,
  /^(erp\.pos\.frente\.)(\d+)$/,
  /^(erp\.pos\.impressao\.)(\d+)$/,
  /^(erp\.pos\.terminal\.(?:[a-z_]+\.)?)(\d+)$/,
  /^(erp\.tour_boas_vindas_v1:)(\d+)$/,
];

export function limparChavesAntigas(converter: (id: string) => string): void {
  try {
    const chaves: string[] = [];
    for (let i = 0; i < window.localStorage.length; i++) {
      const c = window.localStorage.key(i);
      if (c) chaves.push(c);
    }
    for (const chave of chaves) {
      if (CHAVES_SESSAO_ANTIGAS.some((r) => r.test(chave))) {
        window.localStorage.removeItem(chave);
        continue;
      }
      for (const padrao of PADROES_A_CONVERTER) {
        const m = padrao.exec(chave);
        if (!m) continue;
        const nova = `${m[1]}${converter(m[2])}`;
        const valor = window.localStorage.getItem(chave);
        if (valor !== null && window.localStorage.getItem(nova) === null) window.localStorage.setItem(nova, valor);
        window.localStorage.removeItem(chave);
        break;
      }
    }
  } catch {
    /* armazenamento bloqueado: nada a limpar */
  }
}

export const armazenamento = {
  empresaId: (): number | null => empresaActiva,
  definirEmpresa: (id: number | null): void => {
    empresaActiva = id;
  },
  limpar: (): void => {
    empresaActiva = null;
  },
};
