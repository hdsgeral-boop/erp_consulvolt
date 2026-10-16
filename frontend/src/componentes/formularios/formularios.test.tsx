import { beforeEach, describe, expect, it, vi } from 'vitest';
import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { Form, Input } from 'antd';
import { useState } from 'react';
import { reporPreferenciasVistaLocal, CHAVE_LOCAL_VISTAS } from '@/componentes/vistas';

vi.mock('@/api/cliente', () => ({ obter: vi.fn(), enviar: vi.fn(), obterPagina: vi.fn(), descarregar: vi.fn() }));

import { ModalFormulario } from './ModalFormulario';
import { SeccaoFormulario } from './SeccaoFormulario';
import { dataTexto, mapearColagem, numeroTexto, opcaoPara, partirTexto } from './conversoes';
import type { CampoFormulario } from './tipos';

const CAMPOS: CampoFormulario[] = [
  { nome: 'nome', rotulo: 'Designação', obrigatorio: true },
  { nome: 'quantidade', rotulo: 'Quantidade', tipo: 'numero' },
  { nome: 'estado', rotulo: 'Estado', tipo: 'select', omissao: 'PENDENTE', opcoes: [{ value: 'PENDENTE', label: 'Pendente' }, { value: 'CONCLUIDA', label: 'Concluída' }] },
  { nome: 'notas', rotulo: 'Notas', emLinhas: 'opcional', seccao: 'Outros' },
];

function Ecra({ criacao = true, gravar = vi.fn().mockResolvedValue({}), aoConcluir = vi.fn() }: { criacao?: boolean; gravar?: (r: Record<string, unknown>) => Promise<unknown>; aoConcluir?: () => void }) {
  const [form] = Form.useForm();
  const [aberto, setAberto] = useState(true);
  return (
    <ModalFormulario chave="teste.fases" titulo={criacao ? 'Nova fase' : 'Editar fase'} aberto={aberto} aoFechar={() => setAberto(false)} criacao={criacao} campos={CAMPOS} form={form}
      gravarLinha={gravar} aoConcluirLinhas={aoConcluir} nomeRegistos="fases" onOk={() => form.submit()} okText="Gravar">
      <Form form={form} layout="vertical" initialValues={{ estado: 'PENDENTE' }}>
        <Form.Item name="nome" label="Designação"><Input /></Form.Item>
        <Form.Item name="quantidade" label="Quantidade"><Input /></Form.Item>
        <Form.Item name="estado" label="Estado"><Input /></Form.Item>
      </Form>
    </ModalFormulario>
  );
}

const linhas = () => screen.getAllByTestId('linha-formulario');
const celula = (rotulo: string, n: number) => screen.getByLabelText(`${rotulo}, linha ${n}`) as HTMLInputElement;
const escrever = (el: HTMLElement, v: string) => fireEvent.change(el, { target: { value: v } });
const irParaLinhas = () => fireEvent.click(screen.getByRole('button', { name: /Linhas/ }));
async function registarEConfirmar() {
  fireEvent.click(screen.getByRole('button', { name: /Registar linhas/ }));
  const ok = await screen.findByRole('button', { name: 'Registar' });
  fireEvent.click(ok);
}

// antd no jsdom é lento (e a máquina de testes partilhada): margem larga por teste
vi.setConfig({ testTimeout: 120_000 });

beforeEach(() => {
  reporPreferenciasVistaLocal();
  window.localStorage.clear();
});

describe('conversões (legado formulario_linhas.js)', () => {
  it('números e datas em formato português e do Excel', () => {
    expect(numeroTexto('70 004,54')).toBe(70004.54);
    expect(numeroTexto('1.234,56')).toBe(1234.56);
    expect(numeroTexto('1,234.56')).toBe(1234.56);
    expect(numeroTexto('abc')).toBeNull();
    expect(dataTexto('16/07/2026')).toBe('2026-07-16');
    expect(dataTexto('2026-7-6')).toBe('2026-07-06');
    expect(dataTexto('31/02/2026')).toBeNull();
    expect(dataTexto('46219')).toMatch(/^2026-/);
  });
  it('opções por valor, texto, código e início único', () => {
    const ops = [{ value: 1, label: 'D01 - Diário geral' }, { value: 2, label: 'Caixa (NIF: 500)' }];
    expect(opcaoPara(ops, '1')?.value).toBe(1);
    expect(opcaoPara(ops, 'd01')?.value).toBe(1);
    expect(opcaoPara(ops, 'diario geral')).toBeUndefined();
    expect(opcaoPara(ops, '500')?.value).toBe(2);
    expect(opcaoPara(ops, 'cai')?.value).toBe(2);
  });
  it('colagem com cabeçalho mapeia pelo nome; sem cabeçalho pela ordem', () => {
    const com = mapearColagem(partirTexto('Quantidade\tDesignação\n5\tFundações'), CAMPOS);
    expect(com.comCabecalho).toBe(true);
    expect(com.linhas).toEqual([['Fundações', '5', '', '']]);
    const sem = mapearColagem(partirTexto('Fundações;5\nEstrutura;7'), CAMPOS);
    expect(sem.comCabecalho).toBe(false);
    expect(sem.linhas[1]).toEqual(['Estrutura', '7', '', '']);
  });
});

describe('ModalFormulario — Grade | Linhas', () => {
  it('na criação mostra Grade | Linhas e guarda a vista escolhida; na edição só a grade', async () => {
    const { unmount } = render(<Ecra />);
    const grupo = screen.getByRole('group', { name: 'Vista do formulário' });
    expect(within(grupo).getByRole('button', { name: /Grade/ })).toHaveAttribute('aria-pressed', 'true');
    irParaLinhas();
    expect(await screen.findByTestId('painel-linhas')).toBeInTheDocument();
    expect(linhas()).toHaveLength(5);
    expect(screen.getByText(/Uma linha por registo\. Linhas deixadas em branco são ignoradas\. Cada linha é gravada com as mesmas regras do formulário\. Campos com \* são obrigatórios\./)).toBeInTheDocument();
    expect(JSON.parse(window.localStorage.getItem(CHAVE_LOCAL_VISTAS) ?? '{}').valor.ecras['formulario:teste.fases']).toBe('linhas');
    unmount();
    render(<Ecra criacao={false} />);
    expect(screen.queryByRole('group', { name: 'Vista do formulário' })).toBeNull();
    expect(screen.queryByTestId('painel-linhas')).toBeNull();
  });

  it('adicionar, duplicar a última, remover linhas e contador', async () => {
    render(<Ecra />);
    irParaLinhas();
    await screen.findByTestId('painel-linhas');
    expect(screen.getByTestId('contagem-linhas')).toHaveTextContent('0 linha(s) preenchida(s)');
    escrever(celula('Designação', 1), 'Fundações');
    expect(screen.getByTestId('contagem-linhas')).toHaveTextContent('1 linha(s) preenchida(s)');
    fireEvent.click(screen.getByRole('button', { name: /Adicionar linha/ }));
    expect(linhas()).toHaveLength(6);
    fireEvent.click(screen.getByRole('button', { name: /Duplicar última/ }));
    expect(linhas()).toHaveLength(7);
    expect(celula('Designação', 7).value).toBe('Fundações');
    fireEvent.click(screen.getByRole('button', { name: 'Remover linha 7' }));
    expect(linhas()).toHaveLength(6);
    expect(screen.getByTestId('contagem-linhas')).toHaveTextContent('1 linha(s) preenchida(s)');
  });

  it('atalhos: Enter passa à linha seguinte (cria na última), Ctrl+D duplica, Ctrl+Del limpa', async () => {
    render(<Ecra />);
    irParaLinhas();
    await screen.findByTestId('painel-linhas');
    escrever(celula('Designação', 1), 'A');
    fireEvent.keyDown(celula('Designação', 1), { key: 'd', ctrlKey: true });
    expect(linhas()).toHaveLength(6);
    expect(celula('Designação', 2).value).toBe('A');
    fireEvent.keyDown(celula('Designação', 2), { key: 'Delete', ctrlKey: true });
    expect(celula('Designação', 2).value).toBe('');
    fireEvent.keyDown(celula('Designação', 1), { key: 'Enter' });
    expect(document.activeElement).toBe(celula('Designação', 2));
    fireEvent.keyDown(celula('Designação', 6), { key: 'Enter' });
    expect(linhas()).toHaveLength(7);
    await waitFor(() => expect(document.activeElement).toBe(celula('Designação', 7)));
  });

  it('colar do Excel (TSV) substitui as linhas em branco e assinala valores não reconhecidos', async () => {
    render(<Ecra />);
    irParaLinhas();
    await screen.findByTestId('painel-linhas');
    fireEvent.click(screen.getByRole('button', { name: /Colar do Excel/ }));
    escrever(await screen.findByLabelText('Linhas a colar'), 'Fundações\t1.250,5\tConcluída\nEstrutura\t3\tXPTO');
    fireEvent.click(screen.getByRole('button', { name: 'Acrescentar linhas' }));
    await waitFor(() => expect(linhas()).toHaveLength(2));
    expect(celula('Designação', 1).value).toBe('Fundações');
    expect(celula('Quantidade', 1).value).toBe('1250,5');
    expect(screen.getAllByLabelText('Estado, linha 2').some((e) => e.closest('.ant-select-status-error'))).toBe(true);
    expect(screen.getByText(/1 valor\(es\) não reconhecido\(s\).*Estado: XPTO/)).toBeInTheDocument();
  });

  it('colagem directa numa célula preenche a partir dela', async () => {
    render(<Ecra />);
    irParaLinhas();
    await screen.findByTestId('painel-linhas');
    fireEvent.paste(celula('Designação', 4), { clipboardData: { getData: () => 'X\t2\nY\t3\nZ\t4\n' } });
    expect(linhas()).toHaveLength(6);
    expect(celula('Designação', 6).value).toBe('Z');
    expect(celula('Quantidade', 5).value).toBe('3');
  });

  it('valida obrigatórios por linha antes de gravar e não grava nada', async () => {
    const gravar = vi.fn().mockResolvedValue({});
    render(<Ecra gravar={gravar} />);
    irParaLinhas();
    await screen.findByTestId('painel-linhas');
    escrever(celula('Designação', 1), 'A');
    escrever(celula('Quantidade', 2), '4');
    fireEvent.click(screen.getByRole('button', { name: /Registar linhas/ }));
    expect(await screen.findByText(/1 linha\(s\) com campos obrigatórios em falta/)).toBeInTheDocument();
    expect(linhas()[1]).toHaveAttribute('aria-invalid', 'true');
    expect(screen.getByText('Falta: Designação.')).toBeInTheDocument();
    expect(gravar).not.toHaveBeenCalled();
  });

  it('grava linha a linha, ignora as em branco, e as que falham ficam na tabela com o erro', async () => {
    const gravar = vi.fn().mockImplementation((r: Record<string, unknown>) => (r.nome === 'B' ? Promise.reject(new Error('Já existe uma fase com esse nome.')) : Promise.resolve({ dados: { avisos: [] } })));
    const aoConcluir = vi.fn();
    render(<Ecra gravar={gravar} aoConcluir={aoConcluir} />);
    irParaLinhas();
    await screen.findByTestId('painel-linhas');
    escrever(celula('Designação', 1), 'A');
    escrever(celula('Designação', 3), 'B');
    escrever(celula('Designação', 5), 'C');
    await registarEConfirmar();
    await waitFor(() => expect(aoConcluir).toHaveBeenCalled());
    expect(gravar).toHaveBeenCalledTimes(3);
    expect(gravar.mock.calls.map((c) => c[0].nome)).toEqual(['A', 'B', 'C']);
    expect(gravar.mock.calls[0][0]).toMatchObject({ nome: 'A', estado: 'PENDENTE' });
    expect(aoConcluir.mock.calls[0][0]).toMatchObject({ total: 3, gravadas: 2, falhadas: 1 });
    // ficam a linha que falhou e as em branco; o modal continua aberto
    expect(screen.getByText('Já existe uma fase com esse nome.')).toBeInTheDocument();
    expect(screen.getAllByText(/2 de 3 linha\(s\) gravada\(s\); 1 linha\(s\) ficaram na tabela para corrigir\./).length).toBeGreaterThan(0);
    expect(screen.getAllByTestId('linha-formulario').map((tr) => (within(tr).queryByLabelText(/^Designação/) as HTMLInputElement)?.value)).toContain('B');
    expect(screen.queryByDisplayValue('A')).toBeNull();
  });

  it('tudo gravado: fecha o modal', async () => {
    const gravar = vi.fn().mockResolvedValue({});
    render(<Ecra gravar={gravar} />);
    irParaLinhas();
    await screen.findByTestId('painel-linhas');
    escrever(celula('Designação', 1), 'A');
    await registarEConfirmar();
    await waitFor(() => expect(gravar).toHaveBeenCalledTimes(1));
    await waitFor(() => expect(screen.queryByTestId('painel-linhas')).toBeNull());
  });

  it('colunas opcionais mostram-se e escondem-se', async () => {
    render(<Ecra />);
    irParaLinhas();
    await screen.findByTestId('painel-linhas');
    expect(screen.queryByLabelText('Notas, linha 1')).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: 'Mostrar ou esconder colunas' }));
    fireEvent.click(await screen.findByRole('checkbox', { name: /Notas/ }));
    expect(screen.getByLabelText('Notas, linha 1')).toBeInTheDocument();
  });

  it('ao voltar à grade a 1.ª linha preenchida passa para os campos', async () => {
    render(<Ecra />);
    irParaLinhas();
    await screen.findByTestId('painel-linhas');
    escrever(celula('Designação', 2), 'Cobertura');
    fireEvent.click(screen.getByRole('button', { name: /Grade/ }));
    await waitFor(() => expect(screen.queryByTestId('painel-linhas')).toBeNull());
    expect(screen.getByLabelText('Designação')).toHaveValue('Cobertura');
  });
});

describe('SeccaoFormulario', () => {
  it('recolhe e abre sem desmontar os campos', () => {
    render(<SeccaoFormulario titulo="Dependência" recolhida><input aria-label="Depende de" defaultValue="x" /></SeccaoFormulario>);
    const botao = screen.getByRole('button', { name: /Dependência/ });
    expect(botao).toHaveAttribute('aria-expanded', 'false');
    expect(screen.getByLabelText('Depende de', { selector: 'input' })).not.toBeVisible();
    act(() => fireEvent.click(botao));
    expect(botao).toHaveAttribute('aria-expanded', 'true');
    expect(screen.getByLabelText('Depende de')).toBeVisible();
  });
});
