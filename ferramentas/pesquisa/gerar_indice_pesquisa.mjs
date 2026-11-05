// Índice da pesquisa «Procurar» da barra superior: BOTÕES/acções e SEPARADORES de cada ecrã, extraídos do código do
// frontend (frontend/src/modulos/**) com o compilador de TypeScript. Gera frontend/src/layout/indicePesquisa.gerado.ts
// (comitável); o teste src/layout/indicePesquisa.test.ts falha se o ficheiro estiver desactualizado face ao código.
//
// Uso (a partir de erp_laravel/):
//   node ferramentas/pesquisa/gerar_indice_pesquisa.mjs              escreve o índice
//   node ferramentas/pesquisa/gerar_indice_pesquisa.mjs --verificar  sai com código 1 se o índice estiver desactualizado
//   … --stdout [--raiz <pasta do frontend>]                         escreve o índice na saída (usado pelo teste)
//
// Como funciona
//  1. ecrãs: cada src/modulos/<módulo>/ecras.ts liga o id do ecrã do catálogo ao componente (import('./X')); a partir daí
//     seguem-se os imports locais (estáticos e dinâmicos) dentro de src/modulos/** — os componentes partilhados de
//     src/componentes/** (Imprimir, CSV, …) ficam de fora por serem iguais em todos os ecrãs;
//  2. separadores: objectos { key, label } dentro do `items` de um <Tabs> (directo ou numa variável/useMemo do ficheiro);
//     se o <Tabs> está no ficheiro de entrada do ecrã e o seu `activeKey` vem do endereço — o hook partilhado
//     useSeparadorUrl (src/componentes/navegacao, `?sep=`) ou `.get('sep')` — guarda-se a CHAVE do separador (o `key` do
//     item) e a pesquisa abre `rota?sep=<chave>`; ecrãs antigos que só lêem `.get('separador')` guardam `separador=<chave>`;
//  3. botões: texto de <Button>…</Button> (ou o `title`/`aria-label` quando só tem ícone) e descritores de acções
//     ({ titulo, onClick } das AccoesLinha e { label, onClick } dos menus de acções);
//  4. permissão: a chamada `pode('…')` mais próxima que condiciona o elemento (`pode(x) && <Button>`, `pode(x) ? … : …`,
//     `if (pode(x))`, `disabled={!pode(x)}`, ou uma constante `const podeX = pode('x')` usada na condição). Grupos separados
//     por «;» têm todos de se verificar; dentro de um grupo, «|» é «basta uma» (pode('a', 'b')).
// Rótulos genéricos (Cancelar, Guardar, Fechar, …) não entram: apareceriam em todos os ecrãs e não ajudam a procurar.
import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';
import { fileURLToPath, pathToFileURL } from 'node:url';

const AQUI = path.dirname(fileURLToPath(import.meta.url));
export const RAIZ_FRONTEND = path.resolve(AQUI, '../../frontend');
export const DESTINO = 'src/layout/indicePesquisa.gerado.ts';
export const FICHEIRO_CONFIG = path.join(AQUI, 'indice.config.json');
const CATALOGO = (raiz) => path.resolve(raiz, '../backend/resources/permissoes/catalogo.json');
export const lerConfig = () => JSON.parse(fs.readFileSync(FICHEIRO_CONFIG, 'utf8'));

const GENERICOS = new Set([
  'ok', 'sim', 'nao', 'cancelar', 'fechar', 'voltar', 'limpar', 'guardar', 'gravar', 'confirmar', 'aplicar', 'filtrar',
  'pesquisar', 'procurar', 'anterior', 'proximo', 'seguinte', 'continuar', 'editar', 'eliminar', 'apagar', 'remover',
  'adicionar', 'novo', 'nova', 'ver', 'abrir', 'actualizar', 'atualizar', 'recarregar', 'tentar de novo', 'repor', 'x',
  'mais', 'menos', 'todos', 'escolher ficheiro', 'limpar filtros', 'expandir tudo', 'recolher tudo', 'expandir todos', 'recolher todos', 'mais linhas', 'remover linha', 'remover linhas', 'todas', 'nenhum', 'detalhe', 'detalhes', 'opcoes', 'accoes', 'acoes', 'acções', 'ações',
]);

const JANELAS = new Set(['Modal', 'Drawer', 'ModalFormulario', 'DrawerFormulario']);
const normalizar = (s) => s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();

function carregarTs(raiz) {
  // o compilador vem sempre do frontend real (a raiz pode ser uma pasta de teste)
  return createRequire(path.join(fs.existsSync(path.join(raiz, 'node_modules', 'typescript')) ? raiz : RAIZ_FRONTEND, 'package.json'))('typescript');
}

/** Gera o conteúdo do índice (texto do ficheiro .ts). */
export function gerarIndice(raiz = RAIZ_FRONTEND) {
  const ts = carregarTs(raiz);
  const src = path.join(raiz, 'src');
  const modulos = path.join(src, 'modulos');
  const cache = new Map();

  const ler = (f) => {
    if (!cache.has(f)) cache.set(f, ts.createSourceFile(f, fs.readFileSync(f, 'utf8'), ts.ScriptTarget.Latest, true, f.endsWith('x') ? ts.ScriptKind.TSX : ts.ScriptKind.TS));
    return cache.get(f);
  };
  const resolver = (de, especificador) => {
    let base;
    if (especificador.startsWith('@/')) base = path.join(src, especificador.slice(2));
    else if (especificador.startsWith('.')) base = path.resolve(path.dirname(de), especificador);
    else return null;
    for (const c of [base, `${base}.tsx`, `${base}.ts`, path.join(base, 'index.tsx'), path.join(base, 'index.ts')]) {
      if (fs.existsSync(c) && fs.statSync(c).isFile()) return c;
    }
    return null;
  };
  const dentroDosModulos = (f) => f.startsWith(modulos + path.sep) && !/\.test\.tsx?$/.test(f) && path.basename(f) !== 'ecras.ts' && path.basename(f) !== 'registo.tsx';
  const importsDe = (f) => {
    const sf = ler(f);
    const r = [];
    const visitar = (n) => {
      if ((ts.isImportDeclaration(n) || ts.isExportDeclaration(n)) && n.moduleSpecifier && ts.isStringLiteral(n.moduleSpecifier) && !(n.importClause?.isTypeOnly)) r.push(n.moduleSpecifier.text);
      if (ts.isCallExpression(n) && n.expression.kind === ts.SyntaxKind.ImportKeyword && n.arguments[0] && ts.isStringLiteralLike(n.arguments[0])) r.push(n.arguments[0].text);
      ts.forEachChild(n, visitar);
    };
    visitar(sf);
    return r.map((e) => resolver(f, e)).filter((x) => x && dentroDosModulos(x));
  };

  // ── 1. ecrãs → ficheiros de entrada ─────────────────────────────────────────────────────────────
  const entradas = new Map();   // ecrã → Set(ficheiros)
  for (const m of fs.readdirSync(modulos).sort()) {
    const f = path.join(modulos, m, 'ecras.ts');
    if (!fs.existsSync(f)) continue;
    const sf = ler(f);
    const locais = new Map();
    sf.statements.forEach((s) => {
      if (ts.isVariableStatement(s)) s.declarationList.declarations.forEach((d) => ts.isIdentifier(d.name) && d.initializer && locais.set(d.name.text, d.initializer));
    });
    const importsNo = (no) => {
      const r = [];
      const visitar = (n) => {
        if (ts.isCallExpression(n) && n.expression.kind === ts.SyntaxKind.ImportKeyword && n.arguments[0] && ts.isStringLiteralLike(n.arguments[0])) r.push(n.arguments[0].text);
        ts.forEachChild(n, visitar);
      };
      visitar(no);
      return r;
    };
    const visitar = (n) => {
      if (ts.isVariableDeclaration(n) && ts.isIdentifier(n.name) && n.name.text === 'ecras' && n.initializer && ts.isObjectLiteralExpression(n.initializer)) {
        for (const p of n.initializer.properties) {
          if (!ts.isPropertyAssignment(p)) continue;
          const id = ts.isIdentifier(p.name) || ts.isStringLiteral(p.name) ? p.name.text : null;
          if (!id) continue;
          let alvos = importsNo(p.initializer);
          if (!alvos.length && ts.isCallExpression(p.initializer) && ts.isIdentifier(p.initializer.expression) && locais.has(p.initializer.expression.text)) alvos = importsNo(locais.get(p.initializer.expression.text));
          const ficheiros = alvos.map((a) => resolver(f, a)).filter(Boolean);
          if (!entradas.has(id)) entradas.set(id, new Set());
          ficheiros.forEach((x) => entradas.get(id).add(x));
        }
      }
      ts.forEachChild(n, visitar);
    };
    visitar(sf);
  }

  // ── 2./3. extracção por ficheiro (memorizada) ───────────────────────────────────────────────────
  const porFicheiro = new Map();
  const extrair = (f) => {
    if (porFicheiro.has(f)) return porFicheiro.get(f);
    const sf = ler(f);
    const texto = sf.getFullText();
    const lerEndereco = /\.get\(\s*'(separador|sep)'\s*\)|(useSeparadorUrl)\s*[<(]/;
    const constantes = new Map();
    // todas as declarações com o mesmo nome (o mesmo nome pode existir em várias funções do ficheiro)
    const declaracoes = new Map();
    const declarar = (nome, init) => {
      constantes.set(nome, init);
      if (!declaracoes.has(nome)) declaracoes.set(nome, []);
      declaracoes.get(nome).push(init);
    };
    const recolherConstantes = (n) => {
      if (ts.isVariableDeclaration(n) && n.initializer) {
        if (ts.isIdentifier(n.name)) declarar(n.name.text, n.initializer);
        // const [aba, setAba] = useState(pedido ?? …): o valor vem do inicializador
        else if (ts.isArrayBindingPattern(n.name) && n.name.elements[0] && ts.isBindingElement(n.name.elements[0]) && ts.isIdentifier(n.name.elements[0].name))
          declarar(n.name.elements[0].name.text, n.initializer);
      }
      ts.forEachChild(n, recolherConstantes);
    };
    recolherConstantes(sf);
    /** O `activeKey` do <Tabs> vem do endereço (useSeparadorUrl / ?sep= / ?separador=)? Devolve o nome do parâmetro. */
    const parametroDe = (expr, prof = 0) => {
      if (!expr || prof > 3) return null;
      const m = lerEndereco.exec(expr.getText(sf));
      if (m) return m[2] ? 'sep' : m[1];
      let achado = null;
      const ver = (n) => {
        if (achado) return;
        if (ts.isIdentifier(n) && declaracoes.has(n.text)) {
          for (const d of declaracoes.get(n.text)) if (!achado && d !== expr) achado = parametroDe(d, prof + 1);
        } else ts.forEachChild(n, ver);
      };
      ver(expr);
      return achado;
    };

    /** Grupos de permissões (AND de ORs) que tornam `expr` verdadeira (positivo) ou falsa (negativo). */
    const permissoes = (expr, positivo = true, prof = 0) => {
      if (!expr || prof > 4) return [];
      if (ts.isParenthesizedExpression(expr) || ts.isNonNullExpression(expr)) return permissoes(expr.expression, positivo, prof);
      if (ts.isPrefixUnaryExpression(expr) && expr.operator === ts.SyntaxKind.ExclamationToken) return permissoes(expr.operand, !positivo, prof);
      if (ts.isCallExpression(expr)) {
        const c = expr.expression;
        const nome = ts.isIdentifier(c) ? c.text : ts.isPropertyAccessExpression(c) ? c.name.text : null;
        if (nome === 'pode' && positivo) {
          const chaves = expr.arguments.filter(ts.isStringLiteralLike).map((a) => a.text);
          return chaves.length ? [chaves] : [];
        }
        if (nome === 'Boolean' && expr.arguments[0]) return permissoes(expr.arguments[0], positivo, prof);
        return [];
      }
      if (ts.isBinaryExpression(expr)) {
        const op = expr.operatorToken.kind;
        if ((op === ts.SyntaxKind.AmpersandAmpersandToken && positivo) || (op === ts.SyntaxKind.BarBarToken && !positivo))
          return [...permissoes(expr.left, positivo, prof), ...permissoes(expr.right, positivo, prof)];
        return [];
      }
      if (ts.isIdentifier(expr) && constantes.has(expr.text)) return permissoes(constantes.get(expr.text), positivo, prof + 1);
      return [];
    };
    /** Permissão mais próxima que condiciona o nó (sobe pela árvore até encontrar uma). */
    const permissaoDe = (no, atributos) => {
      if (atributos) {
        for (const a of atributos.properties) {
          if (ts.isJsxAttribute(a) && a.name.getText(sf) === 'disabled' && a.initializer && ts.isJsxExpression(a.initializer)) {
            const g = permissoes(a.initializer.expression, false);
            if (g.length) return g;
          }
        }
      }
      let filho = no;
      for (let p = no.parent; p; filho = p, p = p.parent) {
        let g = [];
        if (ts.isBinaryExpression(p) && p.operatorToken.kind === ts.SyntaxKind.AmpersandAmpersandToken && p.right === filho) g = permissoes(p.left, true);
        else if (ts.isConditionalExpression(p) && p.whenTrue === filho) g = permissoes(p.condition, true);
        else if (ts.isConditionalExpression(p) && p.whenFalse === filho) g = permissoes(p.condition, false);
        else if (ts.isIfStatement(p) && p.thenStatement === filho) g = permissoes(p.expression, true);
        else if (ts.isIfStatement(p) && p.elseStatement === filho) g = permissoes(p.expression, false);
        if (g.length) return g;
      }
      return [];
    };

    /** Texto visível de um rótulo (string, modelo, JSX com texto ou componente com `texto`/`rotulo`/`titulo`). */
    const textoDe = (n, prof = 0) => {
      if (!n || prof > 6) return '';
      if (ts.isStringLiteralLike(n)) return n.text;
      if (ts.isTemplateExpression(n)) return [n.head.text, ...n.templateSpans.map((s) => s.literal.text)].join(' ');
      if (ts.isParenthesizedExpression(n) || ts.isJsxExpression(n)) return textoDe(n.expression, prof + 1);
      if (ts.isConditionalExpression(n)) {
        const a = limpar(textoDe(n.whenTrue, prof + 1));
        const b = limpar(textoDe(n.whenFalse, prof + 1));
        return !a ? b : !b ? a : a.length <= b.length ? a : b;
      }
      if (ts.isBinaryExpression(n) && n.operatorToken.kind === ts.SyntaxKind.PlusToken) return `${textoDe(n.left, prof + 1)}${textoDe(n.right, prof + 1)}`;
      if (ts.isJsxText(n)) return n.text;
      if (ts.isJsxElement(n) || ts.isJsxSelfClosingElement(n)) {
        const atributos = ts.isJsxElement(n) ? n.openingElement.attributes : n.attributes;
        for (const a of atributos.properties) {
          if (ts.isJsxAttribute(a) && ['texto', 'rotulo', 'titulo', 'label'].includes(a.name.getText(sf)) && a.initializer) {
            const t = textoDe(a.initializer, prof + 1);
            if (limpar(t)) return t;
          }
        }
        return ts.isJsxElement(n) ? n.children.map((c) => textoDe(c, prof + 1)).join(' ') : '';
      }
      if (ts.isJsxFragment(n)) return n.children.map((c) => textoDe(c, prof + 1)).join(' ');
      return '';
    };
    const atributoTexto = (atributos, nomes) => {
      for (const nome of nomes)
        for (const a of atributos.properties)
          if (ts.isJsxAttribute(a) && a.name.getText(sf) === nome && a.initializer) {
            const t = limpar(textoDe(a.initializer));
            if (t) return t;
          }
      return '';
    };
    const propriedade = (obj, nome) => obj.properties.find((p) => ts.isPropertyAssignment(p) && (ts.isIdentifier(p.name) || ts.isStringLiteral(p.name)) && p.name.text === nome);
    const tem = (obj, nome) => obj.properties.some((p) => (ts.isPropertyAssignment(p) || ts.isShorthandPropertyAssignment(p) || ts.isMethodDeclaration(p)) && p.name && (ts.isIdentifier(p.name) || ts.isStringLiteral(p.name)) && p.name.text === nome);
    /** Expressão que alimenta `items` de um <Tabs>: a própria ou a constante do ficheiro com esse nome. */
    const raizItens = (e) => (e && ts.isIdentifier(e) && constantes.has(e.text) ? constantes.get(e.text) : e);

    /** Títulos estáticos de uma janela: as duas alternativas de um condicional; modelos só quando não terminam numa expressão. */
    const titulosDe = (n, prof = 0) => {
      if (!n || prof > 5) return [];
      if (ts.isJsxExpression(n) || ts.isParenthesizedExpression(n)) return titulosDe(n.expression, prof + 1);
      if (ts.isConditionalExpression(n)) return [...titulosDe(n.whenTrue, prof + 1), ...titulosDe(n.whenFalse, prof + 1)];
      if (ts.isTemplateExpression(n) && !n.templateSpans[n.templateSpans.length - 1].literal.text.trim()) return [];
      const t = limpar(textoDe(n, prof));
      return t && !/[#{}«»]/.test(t) ? [t] : [];
    };

    const itens = [];
    const objectosDeSeparador = new Set();
    const visitar = (n) => {
      // separadores: <Tabs items={…}>
      if ((ts.isJsxSelfClosingElement(n) || ts.isJsxOpeningElement(n)) && /(^|\.)Tabs$/.test(n.tagName.getText(sf))) {
        const at = n.attributes.properties.find((a) => ts.isJsxAttribute(a) && a.name.getText(sf) === 'items');
        const activo = n.attributes.properties.find((a) => ts.isJsxAttribute(a) && a.name.getText(sf) === 'activeKey');
        const parametro = lerEndereco.test(texto) && activo?.initializer && ts.isJsxExpression(activo.initializer) ? parametroDe(activo.initializer.expression) : null;
        const raizExpr = at?.initializer && ts.isJsxExpression(at.initializer) ? raizItens(at.initializer.expression) : null;
        if (raizExpr) {
          const seguidos = new Set();
          const procurar = (x) => {
            // `items={ABAS.map(...)}`: segue a constante do ficheiro que alimenta o `.map`
            if (ts.isIdentifier(x) && constantes.has(x.text) && !seguidos.has(x.text)) {
              seguidos.add(x.text);
              procurar(constantes.get(x.text));
              return;
            }
            if (ts.isObjectLiteralExpression(x) && tem(x, 'key') && tem(x, 'label')) {
              objectosDeSeparador.add(x);
              const k = propriedade(x, 'key');
              const chave = k && ts.isStringLiteralLike(k.initializer) ? k.initializer.text : null;
              const rotulo = limpar(textoDe(propriedade(x, 'label')?.initializer));
              if (rotulo) itens.push({ tipo: 's', rotulo, permissoes: permissaoDe(x), consulta: !chave || !parametro ? '' : parametro === 'sep' ? chave : `${parametro}=${chave}` });
              return;
            }
            ts.forEachChild(x, procurar);
          };
          procurar(raizExpr);
        }
      }
      // botões: <Button>texto</Button> ou o title/aria-label do botão só com ícone
      if (ts.isJsxElement(n) && n.openingElement.tagName.getText(sf) === 'Button') {
        const rotulo = limpar(n.children.map((c) => textoDe(c)).join(' ')) || atributoTexto(n.openingElement.attributes, ['title', 'aria-label']);
        if (rotulo) itens.push({ tipo: 'b', rotulo, permissoes: permissaoDe(n, n.openingElement.attributes), consulta: '' });
      }
      if (ts.isJsxSelfClosingElement(n) && n.tagName.getText(sf) === 'Button') {
        const rotulo = atributoTexto(n.attributes, ['title', 'aria-label']);
        if (rotulo) itens.push({ tipo: 'b', rotulo, permissoes: permissaoDe(n, n.attributes), consulta: '' });
      }
      // menus «Mais»/Importar (<Dropdown menu={{ items: [{ key, label }] }}>): cada item é uma acção, mesmo sem onClick próprio
      if ((ts.isJsxElement(n) || ts.isJsxSelfClosingElement(n)) && (ts.isJsxElement(n) ? n.openingElement.tagName.getText(sf) : n.tagName.getText(sf)) === 'Dropdown') {
        const atributos = ts.isJsxElement(n) ? n.openingElement.attributes : n.attributes;
        const m = atributos.properties.find((x) => ts.isJsxAttribute(x) && x.name.getText(sf) === 'menu');
        if (m && m.initializer && ts.isJsxExpression(m.initializer) && m.initializer.expression) {
          const seguidos = new Set();
          const procurar = (x) => {
            if (ts.isIdentifier(x) && constantes.has(x.text) && !seguidos.has(x.text)) {
              seguidos.add(x.text);
              procurar(constantes.get(x.text));
              return;
            }
            if (ts.isObjectLiteralExpression(x) && tem(x, 'key') && tem(x, 'label') && !objectosDeSeparador.has(x)) {
              const rotulo = limpar(textoDe(propriedade(x, 'label')?.initializer));
              if (rotulo) itens.push({ tipo: 'b', rotulo, permissoes: permissaoDe(x), consulta: '' });
              objectosDeSeparador.add(x);
              return;
            }
            ts.forEachChild(x, procurar);
          };
          procurar(m.initializer.expression);
        }
      }
      // janelas (Modal/Drawer/ModalFormulario): o título é uma acção que só existe numa janela, sem endereço
      if ((ts.isJsxElement(n) || ts.isJsxSelfClosingElement(n)) && JANELAS.has(ts.isJsxElement(n) ? n.openingElement.tagName.getText(sf) : n.tagName.getText(sf))) {
        const atributos = ts.isJsxElement(n) ? n.openingElement.attributes : n.attributes;
        const a = atributos.properties.find((x) => ts.isJsxAttribute(x) && ['title', 'titulo'].includes(x.name.getText(sf)));
        if (a && a.initializer) for (const rotulo of titulosDe(a.initializer)) itens.push({ tipo: 'd', rotulo, permissoes: permissaoDe(n, atributos), consulta: '' });
      }
      // vistas em «pílulas» (Segmented options): comportam-se como separadores sem endereço
      if ((ts.isJsxSelfClosingElement(n) || ts.isJsxOpeningElement(n)) && /(^|\.)Segmented$/.test(n.tagName.getText(sf))) {
        const at = n.attributes.properties.find((x) => ts.isJsxAttribute(x) && x.name.getText(sf) === 'options');
        const raizExpr = at?.initializer && ts.isJsxExpression(at.initializer) ? raizItens(at.initializer.expression) : null;
        const procurar = (x) => {
          if (ts.isObjectLiteralExpression(x) && tem(x, 'label')) {
            const rotulo = limpar(textoDe(propriedade(x, 'label')?.initializer));
            if (rotulo) itens.push({ tipo: 's', rotulo, permissoes: permissaoDe(x), consulta: '' });
            return;
          }
          ts.forEachChild(x, procurar);
        };
        if (raizExpr) procurar(raizExpr);
      }
      // acções descritas em objectos: { titulo, onClick } (AccoesLinha) e { label, onClick } (menus de acções)
      if (ts.isObjectLiteralExpression(n) && !objectosDeSeparador.has(n) && tem(n, 'onClick') && !tem(n, 'children')) {
        const p = propriedade(n, 'titulo') ?? propriedade(n, 'label');
        const rotulo = p ? limpar(textoDe(p.initializer)) : '';
        if (rotulo) itens.push({ tipo: 'b', rotulo, permissoes: permissaoDe(n), consulta: '' });
      }
      ts.forEachChild(n, visitar);
    };
    visitar(sf);
    porFicheiro.set(f, itens);
    return itens;
  };

  // ── 4. por ecrã: ficheiros alcançáveis (com a distância ao ficheiro de entrada), sem repetidos ───
  const config = lerConfig();
  const reRelatorio = new RegExp(config.classificacao.relatorio);
  const reConfig = new RegExp(config.classificacao.configuracao);
  const moduloDe = new Map([...entradas.keys()].map((ecra) => [ecra, [...entradas.get(ecra)][0] ? path.relative(modulos, [...entradas.get(ecra)][0]).split(path.sep)[0] : '']));
  const porEcra = new Map();   // ecrã → Map(chave → { ...item, prof })
  for (const ecra of [...entradas.keys()].sort()) {
    const vistos = new Map();   // ficheiro → distância
    const fila = [...entradas.get(ecra)].map((f) => [f, 0]);
    while (fila.length) {
      const [f, d] = fila.shift();
      if (vistos.has(f) || !fs.existsSync(f)) continue;
      vistos.set(f, d);
      fila.push(...importsDe(f).map((x) => [x, d + 1]));
    }
    const unicos = new Map();
    for (const f of [...vistos.keys()].sort()) {
      // o separador só abre pelo endereço quando está no próprio ecrã (não num detalhe/modal aberto a partir dele)
      const doEcra = entradas.get(ecra).has(f);
      const prof = vistos.get(f);
      for (const original of extrair(f)) {
        const it = doEcra ? original : { ...original, consulta: '' };
        const chave = `${it.tipo}|${normalizar(it.rotulo)}`;
        const perms = it.permissoes.map((g) => [...new Set(g)].sort().join('|')).sort().join(';');
        const antes = unicos.get(chave);
        // o mesmo rótulo em vários sítios: fica a condição menos exigente (sem permissão ganha) e a consulta conhecida
        if (!antes) unicos.set(chave, { ...it, perms, prof });
        else unicos.set(chave, { ...antes, perms: !antes.perms || !perms ? '' : antes.perms.length <= perms.length ? antes.perms : perms, consulta: antes.consulta || it.consulta, prof: Math.min(antes.prof, prof) });
      }
    }
    // o mesmo rótulo com outro tipo e protegido («Nova empresa» janela e botão; botão dentro de um separador protegido): o que
    // não tem permissão herda a do irmão protegido — nunca se mostra a quem não pode usar o controlo
    const protegidos = new Map();
    for (const it of unicos.values()) if (it.perms && !protegidos.has(normalizar(it.rotulo))) protegidos.set(normalizar(it.rotulo), it.perms);
    for (const [chave, it] of unicos) if (!it.perms && protegidos.has(normalizar(it.rotulo))) unicos.set(chave, { ...it, perms: protegidos.get(normalizar(it.rotulo)) });
    porEcra.set(ecra, unicos);
  }

  // componentes partilhados por vários ecrãs do mesmo módulo (ex.: Estrutura/Mapa/Organigrama): o botão fica só no(s) ecrã(s)
  // onde está mais perto do ficheiro de entrada; em empate ficam todos (não se adivinha), mas sem separadores sem endereço
  const melhor = new Map();   // módulo|chave|permissões → menor distância
  for (const [ecra, m] of porEcra) for (const [chave, it] of m) {
    const k = `${moduloDe.get(ecra)}|${chave}|${it.perms}`;
    melhor.set(k, Math.min(melhor.get(k) ?? 99, it.prof));
  }
  const reparticao = { removidas: 0 };
  const linhas = [];
  for (const ecra of [...porEcra.keys()].sort()) {
    const lista = [...porEcra.get(ecra).entries()]
      .filter(([chave, it]) => {
        const ok = it.prof <= melhor.get(`${moduloDe.get(ecra)}|${chave}|${it.perms}`);
        if (!ok) reparticao.removidas++;
        return ok;
      })
      .map(([, it]) => {
        // tipo de apresentação: configuração e relatório/exportação são separados dos botões «normais»
        let tipo = it.tipo;
        const n = normalizar(it.rotulo);
        if (tipo === 'b' && reRelatorio.test(n) && !/(importar|escolher|carregar|baixar modelo|descarregar modelo)/.test(n)) tipo = 'r';
        else if ((tipo === 'b' || tipo === 's') && reConfig.test(n)) tipo = 'c';
        else if (tipo === 's' && reRelatorio.test(n)) tipo = 'r';
        return { ...it, tipo };
      })
      .sort((a, b) => a.tipo.localeCompare(b.tipo) || normalizar(a.rotulo).localeCompare(normalizar(b.rotulo)) || a.rotulo.localeCompare(b.rotulo));
    for (const it of lista) linhas.push([ecra, it.tipo, it.rotulo, it.perms, ...(it.consulta ? [it.consulta] : [])]);
  }

  // ── 5. ecrãs do catálogo (cada um com as suas palavras-chave), ajuda e páginas fora do registo ─────────────────
  const extras = [];
  const palavrasEcras = {};
  const catalogo = fs.existsSync(CATALOGO(raiz)) ? JSON.parse(fs.readFileSync(CATALOGO(raiz), 'utf8')) : null;
  const semPesquisa = (id) => Object.keys(config.ecrasSemPesquisa).some((p) => (p.endsWith('*') ? id.startsWith(p.slice(0, -1)) : id === p));
  if (catalogo) {
    for (const m of catalogo.modulos) for (const e of m.ecras) {
      if (semPesquisa(e.id) || !entradas.has(e.id)) continue;
      extras.push([e.id, 'e', e.nome, '']);
      if (config.palavrasEcras[e.id]) palavrasEcras[e.id] = config.palavrasEcras[e.id];
    }
    const ajuda = path.join(raiz, 'src/componentes/ajuda/conteudo.json');
    if (fs.existsSync(ajuda)) {
      const conteudo = JSON.parse(fs.readFileSync(ajuda, 'utf8'));
      const doCatalogo = new Set(catalogo.modulos.flatMap((m) => m.ecras.map((e) => e.id)));
      for (const [id, t] of Object.entries(conteudo.ecras)) {
        if (!doCatalogo.has(id) || semPesquisa(id) || !entradas.has(id) || !t.titulo) continue;
        const resumo = [t.serve, ...(t.passos ?? [])].join(' ').replace(/\s+/g, ' ').trim().slice(0, 240);
        extras.push([id, 'a', t.titulo, '', '', resumo]);
      }
    }
  }
  if (catalogo) for (const p of config.paginas) extras.push([p.rota, 'p', p.rotulo, '', '', p.palavras ?? '']);

  const todas = [...linhas, ...extras];
  const corpo = todas.map((l) => { while (l.length > 4 && l[l.length - 1] === '') l.pop(); return `  ${JSON.stringify(l)},`; }).join('\n');
  const conf = `export const SINONIMOS_PESQUISA: readonly (readonly string[])[] = ${JSON.stringify(config.sinonimos)};\n\n` +
    `/** Palavras-chave extra por ecrã (id do ecrã) e por tarefa de permissão (chave) — ferramentas/pesquisa/indice.config.json. */\n` +
    `export const PALAVRAS_PESQUISA: Readonly<Record<string, string>> = ${JSON.stringify({ ...palavrasEcras, ...config.palavrasTarefas })};\n`;
  return `/* eslint-disable */
// GERADO por ferramentas/pesquisa/gerar_indice_pesquisa.mjs a partir de src/modulos/** (+ catálogo de permissões, ajuda e
// ferramentas/pesquisa/indice.config.json) — NÃO EDITAR À MÃO.
// Para actualizar (a partir de erp_laravel/): node ferramentas/pesquisa/gerar_indice_pesquisa.mjs
// Cada entrada: [ecrã do catálogo (ou rota '/…' nas páginas fora de /m), tipo, rótulo, permissões ('a|b' = basta uma; ';' = todas),
//   separador: a chave que o abre (a pesquisa navega para rota?sep=<chave>) ou, em ecrãs antigos, a consulta 'separador=<chave>',
//   palavras-chave extra (só ajuda e páginas)].
// Tipos: 'b' botão/acção · 'd' janela (diálogo ou formulário sem endereço, abre no ecrã) · 's' separador/vista · 'c' configuração ·
//   'r' relatório/mapa/exportação · 'e' ecrã (catálogo) · 'a' ajuda do ecrã · 'p' página fora de /m.

export type TipoEntradaPesquisa = 'b' | 'd' | 's' | 'c' | 'r' | 'e' | 'a' | 'p';
export type EntradaIndicePesquisa = readonly [ecra: string, tipo: TipoEntradaPesquisa, rotulo: string, permissoes: string, separador?: string, palavras?: string];

export const INDICE_PESQUISA: readonly EntradaIndicePesquisa[] = [
${corpo}
];

${conf}`;
}

/** Rótulo limpo: espaços colapsados, sem parênteses vazios de contadores, sem genéricos nem sinais soltos. */
function limpar(t) {
  const s = String(t ?? '').replace(/\s+/g, ' ').replace(/\(\s*\)/g, '').replace(/\s+([:,.;)])/g, '$1').replace(/\(\s+/g, '(').trim().replace(/[:\s]+$/, '');
  if (s.length < 2 || !/\p{L}/u.test(s)) return '';
  if (GENERICOS.has(normalizar(s).replace(/[.…!?]+$/, ''))) return '';
  return s.length > 90 ? '' : s;
}

// CLI
if (process.argv[1] && import.meta.url === pathToFileURL(path.resolve(process.argv[1])).href) {
  const i = process.argv.indexOf('--raiz');
  const raiz = i > 0 && process.argv[i + 1] ? path.resolve(process.argv[i + 1]) : RAIZ_FRONTEND;
  const destino = path.join(raiz, DESTINO);
  const novo = gerarIndice(raiz);
  const actual = fs.existsSync(destino) ? fs.readFileSync(destino, 'utf8') : '';
  if (process.argv.includes('--stdout')) {
    process.stdout.write(novo);
  } else if (process.argv.includes('--verificar')) {
    if (novo !== actual) {
      console.error(`O índice da pesquisa está desactualizado: corra node ferramentas/pesquisa/gerar_indice_pesquisa.mjs`);
      process.exit(1);
    }
    console.log('Índice da pesquisa em dia.');
  } else {
    fs.writeFileSync(destino, novo);
    console.log(`Escrito ${path.relative(process.cwd(), destino)} (${novo.split('\n').filter((l) => l.startsWith('  [')).length} entradas).`);
  }
}
