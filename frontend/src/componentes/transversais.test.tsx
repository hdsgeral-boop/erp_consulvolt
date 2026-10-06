import { act, render, screen, waitFor } from '@testing-library/react';
import type { ModuloMenu } from '@/api/tipos';
import { procurarEcras } from '@/layout/PesquisaEcras';
import { moverModulo, ordenarModulos } from '@/paginas/Inicio';
import { propostaParaFormulario, type Proposta } from '@/modulos/geral/integracoes/AssistenteIA';
import { AJUDA, localizarAjuda, pesquisarAjuda } from './ajuda/ajuda';
import { OperacoesProvider, useOperacoes } from './operacoes/Operacoes';
import { resolverFavoritos } from './preferencias/favoritos';

vi.mock('@/api/cliente', () => ({ obter: vi.fn(), enviar: vi.fn(), obterPagina: vi.fn(), http: { post: vi.fn() } }));

const MENU: ModuloMenu[] = [
  {
    id: 'contab',
    nome: 'Contabilidade',
    ecras: [
      { id: 'lancamentos', nome: 'Lançamentos', vistas: ['lancamentos'], pai: null },
      { id: 'relatorios_contabeis', nome: 'Mapas e Relatórios', vistas: [], pai: null },
      { id: 'contab_mapa_balancete', nome: 'Balancete', vistas: [], pai: 'relatorios_contabeis' },
    ],
  },
  { id: 'crm', nome: 'CRM', ecras: [{ id: 'crm_pipeline', nome: 'Pipeline comercial', vistas: [], pai: null }] },
  { id: 'vendas', nome: 'Vendas e Facturação', ecras: [{ id: 'vendas_faturacao', nome: 'Facturação', vistas: [], pai: null }] },
];

describe('ajuda contextual (conteúdo de ajuda.js)', () => {
  it('ecrã com texto próprio, regras do módulo e outros ecrãs', () => {
    const a = localizarAjuda('/m/contab/lancamentos', MENU);
    expect(a.titulo).toBe('Lançamentos');
    expect(a.ecra?.serve).toMatch(/Consultar, criar, importar/);
    expect(a.modulo.nome).toBe('Contabilidade');
    expect(a.modulo.regras.some((r) => r.includes('Débito = Crédito'))).toBe(true);
    expect(a.outros.map((o) => o.id)).toContain('relatorios_contabeis');
  });

  it('ecrã-filho sem texto usa o do pai; módulos sem texto no legado usam o resumo', () => {
    expect(localizarAjuda('/m/contab/contab_mapa_balancete', MENU).ecra?.titulo).toBe('Mapas e Relatórios');
    const crm = localizarAjuda('/m/crm/crm_pipeline', MENU);
    expect(crm.ecra).toBeNull();
    expect(crm.modulo.resumo).toMatch(/Pipeline comercial/);
    expect(localizarAjuda('/', MENU).titulo).toBe('Início');
  });

  it('pesquisa sem acentos só nos ecrãs visíveis', () => {
    const r = pesquisarAjuda('facturas-recibo', MENU);
    expect(r.map((x) => x.id)).toEqual(['vendas_faturacao']);
    expect(pesquisarAjuda('salarios', MENU).every((x) => ['inicio', 'lancamentos', 'relatorios_contabeis', 'vendas_faturacao', 'contab_mapa_balancete', 'crm_pipeline'].includes(x.id))).toBe(true);
    expect(Object.keys(AJUDA.ecras).length).toBeGreaterThan(60);
  });
});

describe('pesquisa de ecrãs, favoritos e ordem dos módulos', () => {
  it('procura por palavras sem acentos, favoritos primeiro', () => {
    const fav = (m: string, e: string) => m === 'vendas' && e === 'vendas_faturacao';
    expect(procurarEcras(MENU, 'lancamentos', fav).map((r) => r.ecra)).toEqual(['lancamentos']);
    expect(procurarEcras(MENU, 'contabilidade balancete', fav).map((r) => r.ecra)).toEqual(['contab_mapa_balancete']);
    expect(procurarEcras(MENU, '', fav)[0].ecra).toBe('vendas_faturacao');
  });

  it('favoritos que já não estão no menu são ignorados', () => {
    const r = resolverFavoritos([{ modulo: 'contab', ecra: 'lancamentos' }, { modulo: 'rh', ecra: 'colaboradores' }], MENU);
    expect(r).toEqual([{ modulo: 'contab', ecra: 'lancamentos', nome: 'Lançamentos', nomeModulo: 'Contabilidade', rota: '/m/contab/lancamentos' }]);
  });

  it('arrastar e largar e ordem guardada (módulos novos no fim)', () => {
    expect(moverModulo(['a', 'b', 'c', 'd'], 'a', 'c')).toEqual(['b', 'c', 'a', 'd']);
    expect(moverModulo(['a', 'b', 'c', 'd'], 'd', 'b')).toEqual(['a', 'd', 'b', 'c']);
    expect(ordenarModulos(MENU, ['vendas', 'contab']).map((m) => m.id)).toEqual(['vendas', 'contab', 'crm']);
  });
});

describe('operações em segundo plano', () => {
  function Disparar({ aoTerminar }: { aoTerminar: (r: number) => void }) {
    const { executar } = useOperacoes();
    return (
      <button
        type="button"
        onClick={() =>
          void executar('Recolher dados', async (progresso) => {
            progresso(50, 'Página 1 de 2');
            await new Promise((r) => setTimeout(r, 20));
            return 42;
          }).then(aoTerminar)
        }
      >
        disparar
      </button>
    );
  }

  it('mostra o progresso no painel e conclui', async () => {
    const fim = vi.fn();
    render(
      <OperacoesProvider>
        <Disparar aoTerminar={fim} />
      </OperacoesProvider>,
    );
    await act(async () => screen.getByText('disparar').click());
    expect(await screen.findByText('Recolher dados', { exact: false })).toBeInTheDocument();
    await waitFor(() => expect(fim).toHaveBeenCalledWith(42));
    expect(await screen.findByText('Operações concluídas')).toBeInTheDocument();
  });
});

describe('assistente IA', () => {
  it('a proposta abre no formulário de lançamento (estado «copia» do NovoLancamento)', () => {
    const p: Proposta = {
      origem: 'IA', diario_id: 3, diario: 'CX Caixa', data_documento: '2026-10-05', numero_documento: 'FT 12', descricao: 'Combustível',
      linhas: [
        { codigo_conta: '6243', descricao_conta: 'Combustíveis', tipo_dc: 'D', valor: 100, descricao: '' },
        { codigo_conta: '4511', descricao_conta: 'Caixa', tipo_dc: 'C', valor: 100, descricao: 'Pagamento' },
      ],
      debito: '100.00', credito: '100.00', equilibrado: true, justificacao: null, avisos: [],
    };
    const e = propostaParaFormulario(p);
    expect(e.origem).toBe('assistente_ia');
    expect(e.copia.diario_id).toBe(3);
    expect(e.copia.linhas).toEqual([
      { codigo_conta: '6243', tipo_dc: 'D', valor: '100.00', descricao: 'Combustível' },
      { codigo_conta: '4511', tipo_dc: 'C', valor: '100.00', descricao: 'Pagamento' },
    ]);
  });
});
