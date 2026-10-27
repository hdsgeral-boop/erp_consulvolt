import { fireEvent, render, screen } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { ModalEditarMassaProdutos } from './ModalEditarMassaProdutos';
import type { ProdutoFicha } from './formularioProduto';

const PRODUTOS = [{ id: 1, nome: 'Produto A' }, { id: 2, nome: 'Produto B' }] as ProdutoFicha[];
const CATEGORIAS = [{ id: 10, nome: 'Bebidas' }, { id: 20, nome: 'Limpeza' }];

const montar = () =>
  render(
    <QueryClientProvider client={new QueryClient()}>
      <ModalEditarMassaProdutos linhas={PRODUTOS} categorias={CATEGORIAS} aoFechar={() => undefined} />
    </QueryClientProvider>,
  );

// Não há uma linha por produto (o mesmo valor aplica-se a todos os seleccionados): a colagem aqui preenche os
// campos do próprio formulário — preço (modo + valor), IVA, categoria, controla stock, é serviço, bloqueado.
describe('ModalEditarMassaProdutos — colagem do Excel', () => {
  // o <Modal> do AntD desenha-se num portal em document.body, fora do `container` do render — as consultas usam
  // `document.body` (como o `screen`), não `container`.
  it('botão «Colar do Excel»: preenche os campos do formulário pela ordem das colunas', () => {
    montar();
    fireEvent.click(screen.getByRole('button', { name: /Colar do Excel/ }));
    fireEvent.change(
      screen.getByLabelText('Linhas a colar'),
      { target: { value: 'Definir o preço (Kz)\t1500\t14\tBebidas\tSim\tNão\tNão' } },
    );
    fireEvent.click(screen.getByRole('button', { name: 'Acrescentar linhas' }));

    expect(document.body.querySelector('[data-colar-coluna="0"] .ant-select-selection-item')?.textContent).toBe('Definir o preço (Kz)');
    expect((document.body.querySelector('[data-colar-coluna="1"] input') as HTMLInputElement).value).toBe('1500');
    expect(document.body.querySelector('[data-colar-coluna="2"] .ant-select-selection-item')?.textContent).toBe('14% (geral)');
    expect(document.body.querySelector('[data-colar-coluna="3"] .ant-select-selection-item')?.textContent).toBe('Bebidas');
    expect(document.body.querySelector('[data-colar-coluna="4"] .ant-select-selection-item')?.textContent).toBe('Sim');
    expect(document.body.querySelector('[data-colar-coluna="5"] .ant-select-selection-item')?.textContent).toBe('Não');
    expect(document.body.querySelector('[data-colar-coluna="6"] .ant-select-selection-item')?.textContent).toBe('Não');
  }, 60_000);

  it('colagem directa numa célula: preenche a partir da coluna clicada, sem tocar nos campos anteriores', () => {
    montar();
    const celulaTaxa = document.body.querySelector('[data-colar-linha="0"] [data-colar-coluna="2"] input') as HTMLElement;
    fireEvent.paste(celulaTaxa, { clipboardData: { getData: () => '14\tBebidas\tSim' } });

    expect(document.body.querySelector('[data-colar-coluna="2"] .ant-select-selection-item')?.textContent).toBe('14% (geral)');
    expect(document.body.querySelector('[data-colar-coluna="3"] .ant-select-selection-item')?.textContent).toBe('Bebidas');
    expect(document.body.querySelector('[data-colar-coluna="4"] .ant-select-selection-item')?.textContent).toBe('Sim');
    // coluna anterior (preço) não foi tocada — continua sem selecção
    expect(document.body.querySelector('[data-colar-coluna="0"] .ant-select-selection-item')).toBeNull();
  });

  it('valor não reconhecido fica por preencher (não limpa a selecção) e avisa', () => {
    montar();
    fireEvent.click(screen.getByRole('button', { name: /Colar do Excel/ }));
    fireEvent.change(
      screen.getByLabelText('Linhas a colar'),
      { target: { value: 'Definir o preço (Kz)\t1500\t99' } },
    );
    fireEvent.click(screen.getByRole('button', { name: 'Acrescentar linhas' }));

    expect(document.body.querySelector('[data-colar-coluna="0"] .ant-select-selection-item')?.textContent).toBe('Definir o preço (Kz)');
    expect(document.body.querySelector('[data-colar-coluna="2"] .ant-select-selection-item')).toBeNull();
    expect(screen.getByText(/valor\(es\) não reconhecido\(s\)/)).toBeInTheDocument();
  }, 60_000);
});
