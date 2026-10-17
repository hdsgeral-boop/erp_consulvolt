import { fireEvent, render, screen, within } from '@testing-library/react';
import { Form, type FormInstance } from 'antd';
import { vi } from 'vitest';
import { EditorLinhasDC, colunasEditor, type CamposEditor, type DisposicaoEditor } from './EditorLinhasDC';

// os seletores reais pedem dados ao servidor; aqui basta um campo simples
vi.mock('./Seletores', () => {
  const Falso = ({ placeholder }: { placeholder?: string }) => <input aria-label={placeholder ?? 'seletor'} />;
  return { SeletorAux: Falso, SeletorConta: Falso, SeletorTerceiro: Falso, SeletorUnidade: Falso };
});

// ecrã largo (no jsdom o Grid do AntD não tem largura e daria «telemóvel», com as colunas empilhadas)
vi.mock('@/componentes/responsivo', async (original) => ({
  ...(await original<typeof import('@/componentes/responsivo')>()),
  useEcra: () => ({ sm: true, md: true, lg: true, xl: true, xxl: false, actual: 'xl', telemovel: false, tablet: false, pequeno: false }),
}));

const TODOS: CamposEditor = { terceiro: true, numeroDocumento: true, centroCusto: true, unidade: true, notas: true };

let formulario: FormInstance;
function Caixa({ disposicao, campos }: { disposicao?: DisposicaoEditor; campos: CamposEditor }) {
  const [form] = Form.useForm();
  formulario = form;
  return (
    <Form form={form} initialValues={{ linhas: [{ tipo_dc: 'D', terceiro_id: 7, _terceiro: 'Cliente A' }] }}>
      <EditorLinhasDC disposicao={disposicao} campos={campos} extraRodape={<button type="button">Equilibrar</button>} />
    </Form>
  );
}
const montar = (disposicao?: DisposicaoEditor, campos: CamposEditor = TODOS) => render(<Caixa disposicao={disposicao} campos={campos} />);

describe('EditorLinhasDC — colunas como cada legado', () => {
  it('Lançamentos (ui_lancamentos.js:650-658): Conta · D/C · Valor · Terceiro · Notas · UNº/CC · … · Acção', () => {
    expect(colunasEditor('lancamentos', { terceiro: true, centroCusto: true, unidade: true, notas: true })).toEqual(['conta', 'dc', 'valor', 'terceiro', 'notas', 'uncc', 'descricao', 'accoes']);
  });

  it('Tesouraria (ui_tesouraria.js:713-724): Conta · Terceiro · Doc · Nota Demo · Nota Fluxo · UN/CC · Descrição · D/C · Valor · Ações', () => {
    expect(colunasEditor('tesouraria', TODOS)).toEqual(['conta', 'terceiro', 'doc', 'nota_demo', 'nota_fluxo', 'uncc', 'descricao', 'dc', 'valor', 'accoes']);
    expect(colunasEditor('tesouraria', {})).toEqual(['conta', 'descricao', 'dc', 'valor', 'accoes']);
  });

  it('cabeçalhos pela ordem do legado e «Adicionar Linha» no fundo, ao lado do extra (Lançamentos)', () => {
    const { container } = montar('lancamentos');
    const cab = container.querySelector('.cabecalho-linhas') as HTMLElement;
    expect(within(cab).getAllByRole('columnheader').map((c) => c.textContent)).toEqual(['Conta', 'D/C', 'Valor (Kz)', 'Terceiro', 'Notas Dem / Fluxo', 'UNº / CC', 'Descrição', 'Acção']);
    const adicionar = screen.getByRole('button', { name: /Adicionar Linha/ });
    // o botão vem depois da grelha (rodapé)
    expect(cab.compareDocumentPosition(adicionar) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Equilibrar' })).toBeInTheDocument();
  }, 60_000);

  it('Tesouraria por omissão: «Adicionar Linha» antes da grelha e a linha nova herda o terceiro', () => {
    const { container } = montar();
    const cab = container.querySelector('.cabecalho-linhas') as HTMLElement;
    expect(within(cab).getAllByRole('columnheader').map((c) => c.textContent)).toEqual([
      'Conta Contrapartida', 'Terceiro', 'Doc', 'Nota Demo', 'Nota Fluxo', 'U. Negócio / C. Custo', 'Descrição', 'D/C', 'Valor (Kz)', 'Ações',
    ]);
    const adicionar = screen.getByRole('button', { name: /Adicionar Linha/ });
    expect(cab.compareDocumentPosition(adicionar) & Node.DOCUMENT_POSITION_PRECEDING).toBeTruthy();
    expect(container.querySelectorAll('[role="row"]').length).toBe(2);
    fireEvent.click(adicionar);
    expect(container.querySelectorAll('[role="row"]').length).toBe(3);
    expect(formulario.getFieldValue(['linhas', 1])).toMatchObject({ tipo_dc: 'D', terceiro_id: 7 });
  }, 60_000);
});
