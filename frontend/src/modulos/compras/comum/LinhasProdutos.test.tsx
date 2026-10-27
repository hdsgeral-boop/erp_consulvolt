import { fireEvent, render, screen } from '@testing-library/react';
import { Form, type FormInstance } from 'antd';
import { describe, expect, it, vi } from 'vitest';
import { LinhasProdutos, type LinhaProdutoForm } from './LinhasProdutos';

const CATALOGO = [
  { id: 1, codigo: 'P001', nome: 'Cimento', preco_unitario: '100.00', taxa_imposto: '14', movimenta_stock: true },
  { id: 2, codigo: 'P002', nome: 'Areia', preco_unitario: '50.00', taxa_imposto: '14', movimenta_stock: true },
];

// mapa do catálogo já carregado (sem passar pelo react-query, que teria de resolver antes de cada teste)
vi.mock('./referencias', () => ({
  useMapaProdutos: () => new Map(CATALOGO.map((p) => [p.id, p])),
  rotuloProduto: (p: { codigo?: string | null; nome: string } | null | undefined) => (p ? (p.codigo ? `${p.codigo} — ${p.nome}` : p.nome) : ''),
}));

// o seletor real pede o catálogo ao servidor; aqui basta um campo simples
vi.mock('./Seletores', () => {
  const Falso = ({ placeholder, value, onChange }: { placeholder?: string; value?: number; onChange?: (v: number) => void }) => (
    <input aria-label={placeholder ?? 'Produto ou serviço'} value={value ?? ''} onChange={(e) => onChange?.(Number(e.target.value) || 0)} />
  );
  return { SeletorProduto: Falso };
});

// ecrã largo (no jsdom o Grid do AntD não tem largura e daria «telemóvel», com as colunas empilhadas)
vi.mock('@/componentes/responsivo', async (original) => ({
  ...(await original<typeof import('@/componentes/responsivo')>()),
  useEcra: () => ({ sm: true, md: true, lg: true, xl: true, xxl: false, actual: 'xl', telemovel: false, tablet: false, pequeno: false }),
}));

let formulario: FormInstance;
function Caixa() {
  const [form] = Form.useForm<{ linhas: LinhaProdutoForm[] }>();
  formulario = form;
  return (
    <Form form={form} initialValues={{ linhas: [{ quantidade: 1 }] }}>
      <LinhasProdutos form={form} preco iva descricao />
    </Form>
  );
}
const montar = () => render(<Caixa />);

describe('LinhasProdutos — colagem do Excel', () => {
  it('botão «Colar do Excel»: acrescenta linhas no fim, reconhecendo o produto pelo código do catálogo', () => {
    montar();
    fireEvent.click(screen.getByRole('button', { name: /Colar do Excel/ }));
    fireEvent.change(screen.getByLabelText('Linhas a colar'), { target: { value: 'P001\tCimento tipo 1\t10\t95\t14\nP002\t\t5\t48\t14' } });
    fireEvent.click(screen.getByRole('button', { name: /Acrescentar linhas/ }));
    expect(formulario.getFieldValue(['linhas', 1])).toMatchObject({ produto_id: 1, descricao: 'Cimento tipo 1', quantidade: 10, preco_unitario: 95, taxa_imposto: 14 });
    expect(formulario.getFieldValue(['linhas', 2])).toMatchObject({ produto_id: 2, quantidade: 5, preco_unitario: 48, taxa_imposto: 14 });
  }, 60_000);

  it('colagem directa numa célula: preenche a partir da coluna/linha clicada, criando linhas se faltarem', () => {
    const { container } = montar();
    const celulaProduto = container.querySelector('[data-colar-linha="0"] [data-colar-coluna="0"] input') as HTMLElement;
    fireEvent.paste(celulaProduto, { clipboardData: { getData: () => 'P001\tCimento\t10\t95\t14\nP002\t\t5\t48\t14' } });
    expect(formulario.getFieldValue(['linhas', 0])).toMatchObject({ produto_id: 1, descricao: 'Cimento', quantidade: 10, preco_unitario: 95, taxa_imposto: 14 });
    expect(formulario.getFieldValue(['linhas', 1])).toMatchObject({ produto_id: 2, quantidade: 5, preco_unitario: 48, taxa_imposto: 14 });
  }, 60_000);

  it('produto não reconhecido fica por preencher, sem bloquear as outras colunas da linha', async () => {
    montar();
    fireEvent.click(screen.getByRole('button', { name: /Colar do Excel/ }));
    fireEvent.change(screen.getByLabelText('Linhas a colar'), { target: { value: 'Inexistente\t\t3\t10' } });
    fireEvent.click(screen.getByRole('button', { name: /Acrescentar linhas/ }));
    expect(formulario.getFieldValue(['linhas', 1])).toMatchObject({ quantidade: 3, preco_unitario: 10 });
    expect(formulario.getFieldValue(['linhas', 1]).produto_id).toBeFalsy();
    expect(await screen.findByText(/não reconhecido/)).toBeInTheDocument();
  }, 60_000);
});
