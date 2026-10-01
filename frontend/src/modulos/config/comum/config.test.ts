import {
  alternarEcra,
  alternarModulo,
  alternarTarefa,
  chavesDoCatalogo,
  chavesDoModelo,
  construirMatriz,
  contar,
  estadoModulo,
  filtrarCatalogo,
  type CatalogoPermissoes,
} from './permissoes';
import { accoesPedido, diferencas, lerTabelaColada, textoConfirmacao, variacaoTaxa } from './regras';
import { nomeDoCabecalho } from './ficheiros';

const CATALOGO: CatalogoPermissoes = {
  modulos: [
    {
      id: 'teso',
      nome: 'Tesouraria',
      ecras: [
        {
          id: 'teso_docs',
          nome: 'Pagamentos',
          vistas: [],
          pai: null,
          nota: null,
          tarefas: [
            { chave: 'teso_doc_emitir', rotulo: 'Emitir', sensivel: false },
            { chave: 'teso_integrar', rotulo: 'Integrar', sensivel: true },
          ],
        },
        { id: 'teso_mapas', nome: 'Mapas', vistas: [], pai: null, nota: null, tarefas: [] },
      ],
    },
    { id: 'geral', nome: 'Geral', ecras: [{ id: 'dashboard', nome: 'Dashboard', vistas: [], pai: null, nota: null, tarefas: [] }] },
  ],
  segregacao: [{ a: 'teso_doc_emitir', b: 'teso_integrar', motivo: 'Emite e integra.' }],
  modelos: [{ nome: 'Tesoureiro', permissoes: { _v2: true, teso_docs_view: true, teso_doc_emitir: true, all: false } }],
};
const [TESO, GERAL] = CATALOGO.modulos;
const DOCS = TESO.ecras[0];

describe('editor de perfis (permissões v2)', () => {
  it('lista as chaves do catálogo', () => {
    expect([...chavesDoCatalogo(CATALOGO)].sort()).toEqual(['dashboard_view', 'teso_doc_emitir', 'teso_docs_view', 'teso_integrar', 'teso_mapas_view']);
  });

  it('marcar uma tarefa marca a consulta do ecrã; desmarcar a consulta retira as tarefas', () => {
    let s = alternarTarefa(new Set(), DOCS, 'teso_integrar', true);
    expect([...s].sort()).toEqual(['teso_docs_view', 'teso_integrar']);
    s = alternarEcra(s, DOCS, false);
    expect(s.size).toBe(0);
  });

  it('marca módulos inteiros sem as sensíveis e calcula o estado', () => {
    const s = alternarModulo(new Set(), TESO, true, false);
    expect(s.has('teso_integrar')).toBe(false);
    expect(estadoModulo(s, TESO)).toBe('parcial');
    expect(estadoModulo(alternarModulo(s, TESO, true, true), TESO)).toBe('total');
    expect(estadoModulo(alternarModulo(s, TESO, false), TESO)).toBe('nenhum');
    expect(estadoModulo(s, GERAL)).toBe('nenhum');
  });

  it('conta ecrãs, tarefas, sensíveis e conflitos de segregação', () => {
    const s = new Set(['teso_docs_view', 'teso_doc_emitir', 'teso_integrar', 'dashboard_view']);
    const c = contar(s, CATALOGO);
    expect(c).toMatchObject({ ecras: 2, tarefas: 2, sensiveis: 1 });
    expect(c.conflitos).toHaveLength(1);
    expect(contar(new Set(['teso_doc_emitir']), CATALOGO).conflitos).toHaveLength(0);
  });

  it('aplica perfis-modelo sem _v2 nem all', () => {
    expect([...chavesDoModelo(CATALOGO.modelos[0])].sort()).toEqual(['teso_doc_emitir', 'teso_docs_view']);
  });

  it('filtra o catálogo sem acentos', () => {
    expect(filtrarCatalogo(CATALOGO.modulos, 'integrar').map((m) => m.ecras.map((e) => e.id))).toEqual([['teso_docs']]);
    expect(filtrarCatalogo(CATALOGO.modulos, 'TESOURARIA')[0].ecras).toHaveLength(2);
    expect(filtrarCatalogo(CATALOGO.modulos, '')).toHaveLength(2);
  });

  it('constrói a matriz perfis × permissões (acesso total marca tudo)', () => {
    const m = construirMatriz(CATALOGO, { perfis: [{ id: 1, nome: 'Tes', acesso_total: false }, { id: 2, nome: 'Admin', acesso_total: true }], chaves: { teso_docs_view: [1] } });
    expect(m).toHaveLength(5);
    expect([...m.find((l) => l.chave === 'teso_docs_view')!.perfis].sort()).toEqual([1, 2]);
    expect([...m.find((l) => l.chave === 'dashboard_view')!.perfis]).toEqual([2]);
    const so = construirMatriz(CATALOGO, { perfis: [{ id: 1, nome: 'Tes', acesso_total: false }, { id: 2, nome: 'Admin', acesso_total: true }], chaves: { teso_docs_view: [1] } }, true);
    expect(so.map((l) => l.chave)).toEqual(['teso_docs_view']);
  });
});

describe('regras de configurações', () => {
  it('compara antes e depois na auditoria', () => {
    const d = diferencas({ a: 1, b: 'x', c: null }, { a: 2, b: 'x', d: { y: 1 } });
    expect(d[0]).toMatchObject({ campo: 'a', antes: '1', depois: '2', alterado: true });
    expect(d.find((x) => x.campo === 'b')!.alterado).toBe(false);
    expect(d.find((x) => x.campo === 'd')!.depois).toBe('{"y":1}');
    expect(diferencas(null, { a: 1 })[0]).toMatchObject({ antes: '', alterado: false });
  });

  it('lê tabelas coladas do Excel e CSV', () => {
    expect(lerTabelaColada('Data\tMoeda\tTaxa\n2026-09-30\tUSD\t912,5\n\n')).toEqual([{ Data: '2026-09-30', Moeda: 'USD', Taxa: '912,5' }]);
    expect(lerTabelaColada('Conta;Descrição\n"3111";"Clientes; nacionais"')).toEqual([{ Conta: '3111', Descrição: 'Clientes; nacionais' }]);
    expect(lerTabelaColada('a,b\n1')).toEqual([{ a: '1', b: '' }]);
    expect(lerTabelaColada('só cabeçalho')).toEqual([]);
  });

  it('mostra as acções certas na aprovação dupla', () => {
    const p = { estado: 'PENDENTE', pedido_por: { id: 1, nome_utilizador: 'a' }, aprovado_por: null };
    expect(accoesPedido(p, { id: 1, superAdmin: false })).toEqual({ aprovar: false, rejeitar: false, executar: false, cancelar: true });
    expect(accoesPedido(p, { id: 2, superAdmin: false })).toEqual({ aprovar: true, rejeitar: true, executar: false, cancelar: false });
    const ap = { ...p, estado: 'APROVADO', aprovado_por: { id: 2, nome_utilizador: 'b' } };
    expect(accoesPedido(ap, { id: 2, superAdmin: false })).toMatchObject({ executar: true, cancelar: false, aprovar: false });
    expect(accoesPedido(ap, { id: 3, superAdmin: false }).executar).toBe(false);
    expect(accoesPedido(ap, { id: 3, superAdmin: true })).toMatchObject({ executar: true, cancelar: true });
    expect(accoesPedido({ ...p, estado: 'EXECUTADO' }, { id: 1, superAdmin: true })).toEqual({ aprovar: false, rejeitar: false, executar: false, cancelar: false });
    expect(textoConfirmacao(12)).toBe('CONFIRMO #12');
  });

  it('calcula a variação de câmbios e lê o nome do ficheiro', () => {
    expect(variacaoTaxa(110, 100)).toBe(10);
    expect(variacaoTaxa(110, null)).toBeNull();
    expect(nomeDoCabecalho("attachment; filename*=UTF-8''c%C3%B3pia.json", 'x')).toBe('cópia.json');
    expect(nomeDoCabecalho('attachment; filename="Template_diarios.xlsx"', 'x')).toBe('Template_diarios.xlsx');
    expect(nomeDoCabecalho(null, 'x.json')).toBe('x.json');
  });
});
