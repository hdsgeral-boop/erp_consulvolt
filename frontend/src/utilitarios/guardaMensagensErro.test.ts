import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';

/**
 * Guarda: nenhum ecrã pode mostrar o `message` cru de uma excepção (nem `String(e)`) num alerta. O texto de um erro passa
 * SEMPRE por `mensagemParaUtilizador` / `notificarErro` (utilitarios/mensagensErro, utilitarios/erros).
 * Ver docs/qualidade/MENSAGENS_ERRO.md.
 */
const RAIZ = join(__dirname, '..');

function ficheiros(pasta: string): string[] {
  return readdirSync(pasta).flatMap((n) => {
    const p = join(pasta, n);
    if (statSync(p).isDirectory()) return ficheiros(p);
    return /\.(tsx?|jsx?)$/.test(n) && !/\.test\./.test(n) ? [p] : [];
  });
}

/** Padrões proibidos: `.message` ou `String(erro)` directamente em alertas, e `instanceof Error ? x.message`. */
const PROIBIDOS: [string, RegExp][] = [
  ['message.error/warning(x.message)', /message\.(error|warning|info)\(\s*(?:\w+\.)*(?:e|err|erro|error|ex)\.message\b/],
  ['message.*(String(erro))', /message\.(error|warning|info)\(\s*String\(\s*(?:e|err|erro|error)\b/],
  ['notification.*(description: x.message)', /notification\.(error|warning|info)\([^)]*description:\s*(?:\w+\.)*(?:e|err|erro|error)\.message\b/s],
  ['notification.*(String(erro))', /notification\.(error|warning|info)\([^)]*description:\s*String\(/s],
  ['Modal.error(content: x.message)', /Modal\.error\([^)]*content:\s*(?:e|err|erro|error)\.message\b/s],
  ['instanceof Error ? x.message', /instanceof Error \? \w+(?:\.\w+)*\.message/],
  ['JSON.stringify(erro) no ecrã', /(?:message|notification)\.\w+\([^)]*JSON\.stringify\(\s*(?:e|err|erro|error)\b/s],
  ['{x.error.message} em JSX', /\{\s*\w+\.error\??\.message\s*\}/],
  ['description={x.error.message}', /description=\{\s*\w+\.error\??\.message\s*\}/],
];

describe('guarda das mensagens de erro no frontend', () => {
  const todos = ficheiros(RAIZ);

  it.each(PROIBIDOS)('nenhum ficheiro tem: %s', (_nome, padrao) => {
    const violacoes = todos.filter((f) => padrao.test(readFileSync(f, 'utf8'))).map((f) => relative(RAIZ, f).replace(/\\/g, '/'));
    expect(violacoes, `Use mensagemParaUtilizador(e) / notificarErro(e) em vez de mostrar o message cru:\n${violacoes.join('\n')}`).toEqual([]);
  });

  it('o cliente HTTP constrói os ErroApi pela camada central (nunca com o texto cru da resposta)', () => {
    const cliente = readFileSync(join(RAIZ, 'api/cliente.ts'), 'utf8');
    expect(cliente).toContain('normalizarRespostaDeErro');
    expect(cliente).toContain('normalizarFalhaSemResposta');
    expect(cliente).not.toMatch(/corpo\?\.mensagem\s*\?\?/);
  });
});
