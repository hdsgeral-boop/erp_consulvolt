import { beforeEach, describe, expect, it, vi } from 'vitest';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router';
import { Entrar } from './Entrar';
import { RegistarEmpresa } from './publico/RegistarEmpresa';
import { RecuperarPassword } from './RecuperarPassword';
import { ErroApi } from '@/api/tipos';
import { ErroMfaPendente } from '@/sessao/mfa';

vi.setConfig({ testTimeout: 120_000 });

const entrar = vi.fn();
const concluirMfa = vi.fn();
const autenticarAposRegisto = vi.fn();
const enviar = vi.fn();
vi.mock('@/portal/rota', () => ({ naRotaPortal: () => false }));
vi.mock('@/sessao/SessaoContexto', () => ({ useSessao: () => ({ entrar, concluirMfa, motivoSaida: null, autenticarAposRegisto }) }));
vi.mock('@/api/cliente', () => ({
  obter: vi.fn(() => Promise.resolve({ logotipo: null, dias_teste_padrao: 30 })),
  enviar: (...a: unknown[]) => enviar(...a),
}));

function montar(ui: React.ReactElement) {
  return render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter>{ui}</MemoryRouter>
    </QueryClientProvider>,
  );
}

describe('Entrar — campos, mensagens e MFA', () => {
  beforeEach(() => {
    entrar.mockReset();
    concluirMfa.mockReset();
  });

  it('campos vazios ou só espaços: mensagens em português e nenhum pedido', async () => {
    montar(<Entrar />);
    fireEvent.change(screen.getByLabelText('Utilizador'), { target: { value: '   ' } });
    fireEvent.click(screen.getByRole('button', { name: 'Entrar' }));
    expect(await screen.findByText('Indique o utilizador ou o e-mail.')).toBeInTheDocument();
    expect(await screen.findByText('Indique a palavra-passe.')).toBeInTheDocument();
    expect(entrar).not.toHaveBeenCalled();
  });

  it('atributos para gestores de palavras-passe e teclados de telemóvel', () => {
    montar(<Entrar />);
    const utilizador = screen.getByLabelText('Utilizador');
    expect(utilizador).toHaveAttribute('autocomplete', 'username');
    expect(utilizador).toHaveAttribute('autocapitalize', 'none');
    expect(utilizador).toHaveAttribute('autocorrect', 'off');
    expect(utilizador).toHaveAttribute('spellcheck', 'false');
    expect(utilizador).toHaveAttribute('maxlength', '150');
    expect(screen.getByLabelText('Palavra-passe')).toHaveAttribute('autocomplete', 'current-password');
  });

  it('envia o utilizador sem espaços nas pontas e a palavra-passe tal como escrita; mostra a mensagem do servidor', async () => {
    entrar.mockRejectedValue(new ErroApi('Nome de utilizador ou palavra-passe incorrectos.', 401, 'CREDENCIAIS_INVALIDAS'));
    montar(<Entrar />);
    fireEvent.change(screen.getByLabelText('Utilizador'), { target: { value: '  ana.silva  ' } });
    fireEvent.change(screen.getByLabelText('Palavra-passe'), { target: { value: ' Segredo#2026 ' } });
    fireEvent.click(screen.getByRole('button', { name: 'Entrar' }));
    expect(await screen.findByText('Nome de utilizador ou palavra-passe incorrectos.')).toBeInTheDocument();
    expect(entrar).toHaveBeenCalledWith('ana.silva', ' Segredo#2026 ');
  });

  it('erro que não vem do servidor: mensagem genérica, sem detalhes técnicos', async () => {
    entrar.mockRejectedValue(new TypeError('Cannot read properties of undefined (reading x)'));
    montar(<Entrar />);
    fireEvent.change(screen.getByLabelText('Utilizador'), { target: { value: 'ana' } });
    fireEvent.change(screen.getByLabelText('Palavra-passe'), { target: { value: 'x' } });
    fireEvent.click(screen.getByRole('button', { name: 'Entrar' }));
    expect(await screen.findByText('Não foi possível entrar. Tente novamente.')).toBeInTheDocument();
    expect(screen.queryByText(/Cannot read/)).toBeNull();
  });

  it('com MFA activo pede o código, confirma-o e só então entra', async () => {
    entrar.mockRejectedValue(new ErroMfaPendente('token-limitado'));
    concluirMfa.mockResolvedValue({ papel: 'UTILIZADOR' });
    montar(<Entrar />);
    fireEvent.change(screen.getByLabelText('Utilizador'), { target: { value: 'ana' } });
    fireEvent.change(screen.getByLabelText('Palavra-passe'), { target: { value: 'Segredo#2026' } });
    fireEvent.click(screen.getByRole('button', { name: 'Entrar' }));

    const codigo = await screen.findByLabelText('Código de autenticação');
    expect(codigo).toHaveAttribute('autocomplete', 'one-time-code');
    fireEvent.click(screen.getByRole('button', { name: 'Confirmar e entrar' }));
    expect(await screen.findByText('Indique o código.')).toBeInTheDocument();
    expect(concluirMfa).not.toHaveBeenCalled();

    fireEvent.change(codigo, { target: { value: ' 123456 ' } });
    fireEvent.click(screen.getByRole('button', { name: 'Confirmar e entrar' }));
    await waitFor(() => expect(concluirMfa).toHaveBeenCalledWith('token-limitado', '123456'));
  });

  it('código MFA errado: mensagem do servidor e continua no passo do código', async () => {
    entrar.mockRejectedValue(new ErroMfaPendente('token-limitado'));
    concluirMfa.mockRejectedValue(new ErroApi('Código inválido.', 422, 'CODIGO_INVALIDO'));
    montar(<Entrar />);
    fireEvent.change(screen.getByLabelText('Utilizador'), { target: { value: 'ana' } });
    fireEvent.change(screen.getByLabelText('Palavra-passe'), { target: { value: 'Segredo#2026' } });
    fireEvent.click(screen.getByRole('button', { name: 'Entrar' }));
    fireEvent.change(await screen.findByLabelText('Código de autenticação'), { target: { value: '000000' } });
    fireEvent.click(screen.getByRole('button', { name: 'Confirmar e entrar' }));
    expect(await screen.findByText('Código inválido.')).toBeInTheDocument();
    expect(screen.getByLabelText('Código de autenticação')).toBeInTheDocument();
  });
});

describe('RecuperarPassword', () => {
  beforeEach(() => enviar.mockReset());

  it('bloqueia o duplo envio e mostra a mensagem igual do servidor', async () => {
    let resolver: (v: unknown) => void = () => undefined;
    enviar.mockReturnValue(new Promise((r) => { resolver = r; }));
    montar(<RecuperarPassword />);
    fireEvent.change(screen.getByLabelText('Utilizador ou E-mail registado'), { target: { value: ' ana@empresa.ao ' } });
    const botao = screen.getByRole('button', { name: /Recuperar Palavra-passe/ });
    fireEvent.click(botao);
    await waitFor(() => expect(enviar).toHaveBeenCalledTimes(1));
    fireEvent.click(botao);
    fireEvent.click(botao);
    expect(enviar).toHaveBeenCalledTimes(1);
    expect(enviar).toHaveBeenCalledWith('post', '/autenticacao/recuperar-password', { identificador: 'ana@empresa.ao' });
    resolver({ dados: { processado: true, mensagem: 'Se os dados introduzidos corresponderem a uma conta ativa, foi enviada uma nova password para o e-mail associado.' } });
    expect(await screen.findByText(/Se os dados introduzidos corresponderem/)).toBeInTheDocument();
  });

  it('campo vazio: mensagem e nenhum pedido', async () => {
    montar(<RecuperarPassword />);
    fireEvent.click(screen.getByRole('button', { name: /Recuperar Palavra-passe/ }));
    expect(await screen.findByText('Indique o nome de utilizador ou e-mail.')).toBeInTheDocument();
    expect(enviar).not.toHaveBeenCalled();
  });
});

describe('RegistarEmpresa — campos e critérios', () => {
  beforeEach(() => {
    enviar.mockReset();
    autenticarAposRegisto.mockReset();
  });

  const preencher = (campos: Record<string, string>) => {
    for (const [rotulo, valor] of Object.entries(campos)) fireEvent.change(screen.getByLabelText(rotulo), { target: { value: valor } });
  };

  it('submeter vazio mostra a obrigatoriedade de todos os campos e não envia nada', async () => {
    montar(<RegistarEmpresa />);
    fireEvent.click(screen.getByRole('button', { name: /Começar/ }));
    for (const m of ['Indique o nome da empresa.', 'Indique o NIF.', 'Indique o contacto telefónico.', 'Seleccione a província.', 'Indique o seu nome.', 'Indique o nome de utilizador.', 'Indique o e-mail.', 'Indique a palavra-passe.']) {
      expect(await screen.findByText(m)).toBeInTheDocument();
    }
    expect(enviar).not.toHaveBeenCalled();
  });

  it('NIF: recusa formatos inválidos, aceita 10 dígitos e o formato do BI de pessoa singular', async () => {
    montar(<RegistarEmpresa />);
    const nif = screen.getByLabelText('NIF da empresa');
    for (const mau of ['12345678', '54170123456', '0000000000', '999999999', '54A7012345']) {
      fireEvent.change(nif, { target: { value: mau } });
      fireEvent.blur(nif);
      expect(await screen.findByText(/NIF inválido/)).toBeInTheDocument();
    }
    for (const bom of ['5417012345', '000755848LA037', '000755848la037', ' 5417 012 345 ']) {
      fireEvent.change(nif, { target: { value: bom } });
      fireEvent.blur(nif);
      await waitFor(() => expect(screen.queryByText(/NIF inválido/)).toBeNull());
    }
  });

  it('telefone, nome de utilizador, e-mail e palavra-passe: mensagens claras', async () => {
    montar(<RegistarEmpresa />);
    preencher({ 'Telefone principal': '12345', 'Nome de utilizador (login)': 'admin', 'E-mail profissional': 'sem-arroba', 'Nome completo': 'João 123' });
    fireEvent.change(screen.getByLabelText(/Palavra-passe \(mín/), { target: { value: 'password123' } });
    fireEvent.click(screen.getByRole('button', { name: /Começar/ }));
    expect(await screen.findByText(/Telefone inválido/)).toBeInTheDocument();
    expect(await screen.findByText('Este nome de utilizador está reservado. Escolha outro.')).toBeInTheDocument();
    expect(await screen.findByText('Indique um e-mail válido.')).toBeInTheDocument();
    expect(await screen.findByText(/O nome só pode ter letras/)).toBeInTheDocument();
    expect(await screen.findByText('Fraca: use maiúsculas, minúsculas e algarismos.')).toBeInTheDocument();
    expect(enviar).not.toHaveBeenCalled();
  });

  it('indicador de força e confirmação da palavra-passe', async () => {
    montar(<RegistarEmpresa />);
    const p = screen.getByLabelText(/Palavra-passe \(mín/);
    fireEvent.change(p, { target: { value: 'abc' } });
    expect(await screen.findByText('Força: Curta')).toBeInTheDocument();
    fireEvent.change(p, { target: { value: 'Barcaça#2026x' } });
    expect(await screen.findByText(/Força: (Forte|Muito forte)/)).toBeInTheDocument();
    fireEvent.change(screen.getByLabelText('Confirmar a palavra-passe'), { target: { value: 'Outra#2026xxx' } });
    fireEvent.blur(screen.getByLabelText('Confirmar a palavra-passe'));
    expect(await screen.findByText('Não coincide com a palavra-passe.')).toBeInTheDocument();
  });

  it('atributos de acessibilidade e teclados móveis', () => {
    montar(<RegistarEmpresa />);
    expect(screen.getByLabelText('Telefone principal')).toHaveAttribute('inputmode', 'tel');
    expect(screen.getByLabelText('Telefone principal')).toHaveAttribute('autocomplete', 'tel');
    expect(screen.getByLabelText('E-mail profissional')).toHaveAttribute('inputmode', 'email');
    expect(screen.getByLabelText('Nome de utilizador (login)')).toHaveAttribute('autocapitalize', 'none');
    expect(screen.getByLabelText('Nome de utilizador (login)')).toHaveAttribute('autocomplete', 'username');
    expect(screen.getByLabelText(/Palavra-passe \(mín/)).toHaveAttribute('autocomplete', 'new-password');
    expect(screen.getByLabelText('NIF da empresa')).toHaveAttribute('autocapitalize', 'characters');
  });

  it('dados válidos: envia normalizado (sem confirmação), uma só vez, e entra', async () => {
    let resolver: (v: unknown) => void = () => undefined;
    enviar.mockReturnValue(new Promise((r) => { resolver = r; }));
    montar(<RegistarEmpresa />);
    preencher({
      'Nome comercial / Denominação social': '  João  &   Filhos,  Lda ',
      'NIF da empresa': ' 5417 012 345 ',
      'Telefone principal': '+244 923-000-111',
      'Nome completo': 'Maria  da Conceição',
      'Nome de utilizador (login)': ' Maria.Conceicao ',
      'E-mail profissional': ' Maria@Empresa.CO.AO ',
    });
    fireEvent.change(screen.getByLabelText(/Palavra-passe \(mín/), { target: { value: 'Barcaça#2026x' } });
    fireEvent.change(screen.getByLabelText('Confirmar a palavra-passe'), { target: { value: 'Barcaça#2026x' } });
    fireEvent.mouseDown(screen.getByRole('combobox'));
    fireEvent.click(await screen.findByText('Luanda'));

    const botao = screen.getByRole('button', { name: /Começar/ });
    fireEvent.click(botao);
    await waitFor(() => expect(enviar).toHaveBeenCalledTimes(1));
    fireEvent.click(botao);
    expect(enviar).toHaveBeenCalledTimes(1);
    expect(enviar).toHaveBeenCalledWith('post', '/autenticacao/registar-empresa', {
      nome_comercial: 'João & Filhos, Lda',
      nif: '5417012345',
      telefone: '923000111',
      provincia: 'Luanda',
      nome_completo: 'Maria da Conceição',
      nome_utilizador: 'maria.conceicao',
      email: 'maria@empresa.co.ao',
      palavra_passe: 'Barcaça#2026x',
    });
    resolver({ dados: { empresa_id: 7 } });
    await waitFor(() => expect(autenticarAposRegisto).toHaveBeenCalledWith(7));
  });

  it('registo feito no endereço de outro cliente: não tenta abrir sessão e indica o endereço da nova empresa', async () => {
    enviar.mockResolvedValue({ dados: { sem_sessao: true, endereco_entrada: 'https://exemplo.test/app', endereco_empresa: 'https://nova.exemplo.test/app' } });
    montar(<RegistarEmpresa />);
    preencher({
      'Nome comercial / Denominação social': 'Caculo Tech e Associados',
      'NIF da empresa': '5417012345',
      'Telefone principal': '937256318',
      'Nome completo': 'Leonildo Mulanga Caculo',
      'Nome de utilizador (login)': 'Leonel_Caculo',
      'E-mail profissional': 'leonildo@consulvolt.pt',
    });
    fireEvent.change(screen.getByLabelText(/Palavra-passe \(mín/), { target: { value: 'Barcaça#2026x' } });
    fireEvent.change(screen.getByLabelText('Confirmar a palavra-passe'), { target: { value: 'Barcaça#2026x' } });
    fireEvent.mouseDown(screen.getByRole('combobox'));
    fireEvent.click(await screen.findByText('Luanda'));
    fireEvent.click(screen.getByRole('button', { name: /Começar/ }));
    expect((await screen.findAllByText('Empresa registada')).length).toBeGreaterThan(0);
    expect(screen.getByText(/login único: https:\/\/exemplo\.test\/app/)).toBeInTheDocument();
    expect(screen.getByText(/morada própria https:\/\/nova\.exemplo\.test\/app/)).toBeInTheDocument();
    expect(autenticarAposRegisto).not.toHaveBeenCalled();
  });

  it('erro do servidor por campo (NIF repetido): aparece no campo e no resumo, sem detalhes técnicos', async () => {
    enviar.mockRejectedValue(new ErroApi('Os dados enviados são inválidos.', 422, 'VALIDACAO', { nif: ['Este NIF já se encontra registado numa empresa existente.'] }));
    montar(<RegistarEmpresa />);
    preencher({
      'Nome comercial / Denominação social': 'Loja Central, Lda', 'NIF da empresa': '5417012345', 'Telefone principal': '923000111',
      'Nome completo': 'Maria Santos', 'Nome de utilizador (login)': 'maria.santos', 'E-mail profissional': 'maria@empresa.co.ao',
    });
    fireEvent.change(screen.getByLabelText(/Palavra-passe \(mín/), { target: { value: 'Barcaça#2026x' } });
    fireEvent.change(screen.getByLabelText('Confirmar a palavra-passe'), { target: { value: 'Barcaça#2026x' } });
    fireEvent.mouseDown(screen.getByRole('combobox'));
    fireEvent.click(await screen.findByText('Luanda'));
    fireEvent.click(screen.getByRole('button', { name: /Começar/ }));
    expect((await screen.findAllByText('Este NIF já se encontra registado numa empresa existente.')).length).toBeGreaterThan(0);
    expect(autenticarAposRegisto).not.toHaveBeenCalled();
  });
});
