import { fireEvent, render, screen } from '@testing-library/react';
import { Tabs } from 'antd';
import { MemoryRouter, Route, Routes, useLocation, useNavigate } from 'react-router-dom';
import { describe, expect, it } from 'vitest';
import { consultaComSeparador, separadorDaConsulta, useSeparadorUrl } from './useSeparadorUrl';

const CHAVES = ['lista', 'recibos', 'config'] as const;

function Ecra({ chaves = CHAVES as readonly string[], padrao }: { chaves?: readonly string[]; padrao?: string }) {
  const [sep, setSep] = useSeparadorUrl(chaves, padrao);
  return (
    <>
      <span data-testid="activo">{sep}</span>
      <Tabs activeKey={sep} onChange={setSep} items={chaves.map((k) => ({ key: k, label: `Sep ${k}`, children: <p>conteúdo {k}</p> }))} />
    </>
  );
}

/** Mostra o endereço actual e um botão «Voltar» (history -1). */
function Endereco() {
  const l = useLocation();
  const navegar = useNavigate();
  return (
    <>
      <span data-testid="endereco">{l.pathname + l.search}</span>
      <button type="button" onClick={() => navegar(-1)}>Voltar</button>
    </>
  );
}

function montar(entradas: string[], el = <Ecra />) {
  render(
    <MemoryRouter initialEntries={entradas} initialIndex={entradas.length - 1}>
      <Routes>
        <Route path="/m/x/ecra" element={el} />
        <Route path="/inicio" element={<p>início</p>} />
      </Routes>
      <Endereco />
    </MemoryRouter>,
  );
}

describe('separadorDaConsulta / consultaComSeparador', () => {
  it('lê ?sep= e, por compatibilidade, ?separador=; chave inválida → padrão ou o primeiro', () => {
    expect(separadorDaConsulta('?sep=recibos', CHAVES)).toBe('recibos');
    expect(separadorDaConsulta('?separador=config', CHAVES)).toBe('config');
    expect(separadorDaConsulta('?sep=recibos&separador=config', CHAVES)).toBe('recibos');
    expect(separadorDaConsulta('?sep=xpto&separador=config', CHAVES)).toBe('config');
    expect(separadorDaConsulta('?sep=xpto', CHAVES)).toBe('lista');
    expect(separadorDaConsulta('', CHAVES)).toBe('lista');
    expect(separadorDaConsulta('', CHAVES, 'config')).toBe('config');
    expect(separadorDaConsulta('?sep=recibos', CHAVES, 'config')).toBe('recibos');
    // padrão que não existe (ex.: sem permissão) → o primeiro
    expect(separadorDaConsulta('', ['lista'], 'config')).toBe('lista');
    // sem separadores ainda (permissões a carregar)
    expect(separadorDaConsulta('?sep=recibos', [])).toBe('');
    // não aceita chaves «parecidas» nem injecção no valor
    expect(separadorDaConsulta('?sep=Recibos', CHAVES)).toBe('lista');
    expect(separadorDaConsulta('?sep=recibos%3Cscript%3E', CHAVES)).toBe('lista');
  });

  it('mantém os outros parâmetros e retira o ?separador= antigo', () => {
    expect(consultaComSeparador('?unidade=4&separador=lista', 'config').toString()).toBe('unidade=4&sep=config');
    expect(consultaComSeparador('', 'a b').toString()).toBe('sep=a+b');
  });
});

describe('useSeparadorUrl', () => {
  it('abre o separador pedido no endereço (?sep= e ?separador=)', () => {
    montar(['/m/x/ecra?sep=recibos']);
    expect(screen.getByTestId('activo')).toHaveTextContent('recibos');
    expect(screen.getByRole('tab', { name: 'Sep recibos' })).toHaveAttribute('aria-selected', 'true');
  });

  it('compatibilidade com ?separador=', () => {
    montar(['/m/x/ecra?separador=config']);
    expect(screen.getByTestId('activo')).toHaveTextContent('config');
  });

  it('chave inválida ou sem permissão → primeiro separador', () => {
    montar(['/m/x/ecra?sep=config'], <Ecra chaves={['lista', 'recibos']} />);
    expect(screen.getByTestId('activo')).toHaveTextContent('lista');
  });

  it('ao mudar de separador grava ?sep= com replace (Voltar sai do ecrã, não salta entre separadores)', () => {
    montar(['/inicio', '/m/x/ecra?unidade=7']);
    expect(screen.getByTestId('activo')).toHaveTextContent('lista');
    fireEvent.click(screen.getByRole('tab', { name: 'Sep recibos' }));
    expect(screen.getByTestId('activo')).toHaveTextContent('recibos');
    expect(screen.getByTestId('endereco')).toHaveTextContent('/m/x/ecra?unidade=7&sep=recibos');
    fireEvent.click(screen.getByRole('tab', { name: 'Sep config' }));
    expect(screen.getByTestId('endereco')).toHaveTextContent('/m/x/ecra?unidade=7&sep=config');
    fireEvent.click(screen.getByRole('button', { name: 'Voltar' }));
    expect(screen.getByTestId('endereco')).toHaveTextContent(/^\/inicio$/);
  });

  it('padrão próprio do ecrã quando o endereço não traz separador', () => {
    montar(['/m/x/ecra'], <Ecra padrao="config" />);
    expect(screen.getByTestId('activo')).toHaveTextContent('config');
  });

  it('fora de um Router funciona como estado local', () => {
    render(<Ecra />);
    expect(screen.getByTestId('activo')).toHaveTextContent('lista');
    fireEvent.click(screen.getByRole('tab', { name: 'Sep config' }));
    expect(screen.getByTestId('activo')).toHaveTextContent('config');
  });
});
