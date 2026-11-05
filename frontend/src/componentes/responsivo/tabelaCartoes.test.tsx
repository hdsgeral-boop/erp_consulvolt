import { render } from '@testing-library/react';
import { Table } from 'antd';
import { CLASSE_TABELA_CARTOES, comRotulosDeCartao } from './tabelaCartoes';

describe('tabela em cartões (telemóvel)', () => {
  it('põe o título da coluna em data-rotulo de cada célula e preserva o onCell original', () => {
    const colunas = comRotulosDeCartao<{ k: number; nome: string }>([
      { title: 'Nome', dataIndex: 'nome', onCell: () => ({ 'data-x': 'a' }) as never },
      { title: 'Valor', dataIndex: 'k' },
      { title: <b>Acções</b>, key: 'a', render: () => 'ver' },
    ]);
    const { container } = render(<Table className={CLASSE_TABELA_CARTOES} rowKey="k" pagination={false} columns={colunas} dataSource={[{ k: 1, nome: 'Ana' }]} />);
    const celulas = container.querySelectorAll('tbody td');
    expect(celulas[0].getAttribute('data-rotulo')).toBe('Nome');
    expect(celulas[0].getAttribute('data-x')).toBe('a');
    expect(celulas[1].getAttribute('data-rotulo')).toBe('Valor');
    expect(celulas[2].getAttribute('data-rotulo')).toBe('');
    expect(container.querySelector('.erp-tabela-cartoes')).not.toBeNull();
  });
});
