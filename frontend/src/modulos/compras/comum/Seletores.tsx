import { AutoComplete, Checkbox, Form, Input, InputNumber, Select, Switch, message, type SelectProps } from 'antd';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { enviar, obter, obterPagina } from '@/api/cliente';
import { useSessao } from '@/sessao/SessaoContexto';
import { notificarErro } from '@/utilitarios/erros';
import { dropdownComCriar, ModalCriacaoRapida } from '@/componentes/formularios';
import { SeletorConta as SeletorContaPlano } from '@/modulos/contab/comum/Seletores';
import { prefixosConta } from '@/modulos/contab/tabelasAux/contasTerceiro';
import { useCategorias } from '@/modulos/vendas/produtos/Categorias';
import type { Categoria } from '@/modulos/vendas/produtos/formularioProduto';
import { rotuloProduto, useArmazens, useCatalogo, type Armazem, type FichaTerceiro, type ProdutoCatalogo } from './referencias';
import { BotaoValidarNifAgt } from './ValidarNifAgt';

type PropsBase<V> = Omit<SelectProps<V>, 'options' | 'showSearch' | 'filterOption' | 'onSearch' | 'loading'>;

/**
 * Criação mínima de um cliente/fornecedor (nome, NIF e a conta do papel — os mesmos campos obrigatórios do servidor,
 * `GuardarTerceiroRequest`/`ServicoTerceiros::exigirContasDoPapel`), a partir de um campo de busca que ainda não achou
 * a entidade (padrão de `SeletorClienteVenda`: modal próprio, sem saltar para o ecrã de Terceiros).
 */
function ModalCriarTerceiroRapido({ papel, aberto, podeCriar, aoFechar, aoCriar }: { papel: 'CLIENTE' | 'FORNECEDOR'; aberto: boolean; podeCriar: boolean; aoFechar: () => void; aoCriar: (t: FichaTerceiro) => void }) {
  const [form] = Form.useForm<{ nome: string; nif?: string; conta: string }>();
  const nifActual = Form.useWatch('nif', form);
  const criar = useMutation({
    mutationFn: (v: { nome: string; nif?: string; conta: string }) =>
      enviar<FichaTerceiro>('post', '/terceiros', { papel, nome: v.nome.trim(), nif: v.nif?.trim() || null, [papel === 'CLIENTE' ? 'conta_cliente' : 'conta_fornecedor']: v.conta.trim() }),
    onSuccess: ({ dados, mensagem }) => {
      message.success(mensagem);
      form.resetFields();
      aoCriar(dados);
    },
    onError: (e) => notificarErro(e, `Não foi possível criar o ${papel === 'CLIENTE' ? 'cliente' : 'fornecedor'}`),
  });
  return (
    <ModalCriacaoRapida<{ nome: string; nif?: string; conta: string }>
      aberto={aberto}
      titulo={papel === 'CLIENTE' ? 'Criar novo cliente' : 'Criar novo fornecedor'}
      podeCriar={podeCriar}
      aGravar={criar.isPending}
      aoFechar={aoFechar}
      form={form}
      onFinish={(v) => criar.mutate(v)}
    >
      <Form.Item name="nome" label="Nome" rules={[{ required: true, message: 'Indique o nome.' }, { max: 255 }]}>
        <Input autoFocus />
      </Form.Item>
      <Form.Item name="nif" label="NIF" rules={[{ required: papel === 'FORNECEDOR', message: 'O NIF do fornecedor é obrigatório.' }, { max: 30 }]}>
        <Input addonAfter={<BotaoValidarNifAgt nif={nifActual} tamanho="small" apenasIcone />} />
      </Form.Item>
      <Form.Item
        name="conta"
        label={papel === 'CLIENTE' ? 'Conta de cliente (classe 31)' : 'Conta de fornecedor (classe 32 ou 37.0)'}
        rules={[{ required: true, message: 'Indique a conta.' }]}
      >
        <SeletorContaPlano prefixos={prefixosConta(papel)} placeholder={papel === 'CLIENTE' ? 'Ex.: 3112' : 'Ex.: 3211'} allowClear style={{ width: '100%' }} />
      </Form.Item>
    </ModalCriacaoRapida>
  );
}

/** Pesquisa de clientes ou fornecedores (GET /terceiros?papel=…&pesquisa=…). */
/**
 * onEscolher: recebe a ficha do terceiro escolhido (ex.: sugerir a moeda do fornecedor — comprasFxSugerirMoedaFornecedor, M-17).
 * permissaoCriar: com a permissão indicada, mostra «+ Criar novo» no fundo da lista (modal de criação mínima); sem a prop, nada muda.
 */
export function SeletorTerceiro({ papel, onEscolher, permissaoCriar, ...props }: PropsBase<number> & { papel: 'CLIENTE' | 'FORNECEDOR'; onEscolher?: (t: FichaTerceiro | undefined) => void; permissaoCriar?: string }) {
  const { pode } = useSessao();
  const consultas = useQueryClient();
  const [pesquisa, setPesquisa] = useState('');
  const [aCriar, setACriar] = useState(false);
  const podeCriar = !!permissaoCriar && pode(permissaoCriar);
  const consulta = useQuery({
    queryKey: ['terceiros', 'pesquisa', papel, pesquisa],
    queryFn: () => obterPagina<FichaTerceiro>('/terceiros', { papel, pesquisa, por_pagina: 30, estrito: 1 }),   // só pelo papel (ehCliente/ehFornecedor do legado)
    retry: false,
  });
  const escolher = (t: FichaTerceiro) => { props.onChange?.(t.id, { value: t.id, label: t.nome.trim() }); onEscolher?.(t); };
  const opcoes = (consulta.data?.itens ?? []).map((t) => ({ value: t.id, label: `${t.nome.trim()}${t.nif ? ` (NIF ${t.nif})` : ''}` }));
  // valor pré-seleccionado (ex.: vindo de «Liquidar») que não está na lista carregada: resolve o rótulo pela ficha, para não mostrar o id
  const valorAtual = typeof props.value === 'number' ? props.value : undefined;
  const foraDaLista = valorAtual !== undefined && !opcoes.some((o) => o.value === valorAtual);
  const ficha = useQuery({
    queryKey: ['terceiros', 'ficha', valorAtual],
    queryFn: () => obter<FichaTerceiro>(`/terceiros/${valorAtual}`),
    enabled: foraDaLista,
    retry: false,
  });
  if (foraDaLista && ficha.data) opcoes.unshift({ value: ficha.data.id, label: `${ficha.data.nome.trim()}${ficha.data.nif ? ` (NIF ${ficha.data.nif})` : ''}` });
  return (
    <>
      <Select<number>
        showSearch
        allowClear
        filterOption={false}
        onSearch={setPesquisa}
        loading={consulta.isFetching}
        placeholder={papel === 'CLIENTE' ? 'Pesquisar cliente (nome ou NIF)' : 'Pesquisar fornecedor (nome ou NIF)'}
        options={opcoes}
        popupRender={(menu) => dropdownComCriar(menu, { mostrar: !!permissaoCriar, podeCriar, rotulo: papel === 'CLIENTE' ? 'cliente' : 'fornecedor', aoClicar: () => setACriar(true) })}
        {...props}
        onChange={(v, o) => { props.onChange?.(v, o); onEscolher?.((consulta.data?.itens ?? []).find((t) => t.id === v)); }}
      />
      {permissaoCriar && (
        <ModalCriarTerceiroRapido
          papel={papel}
          aberto={aCriar}
          podeCriar={podeCriar}
          aoFechar={() => setACriar(false)}
          aoCriar={(t) => {
            void consultas.invalidateQueries({ queryKey: ['terceiros'] });
            setACriar(false);
            escolher(t);
          }}
        />
      )}
    </>
  );
}

/** Criação mínima de uma categoria de produtos (só o nome; as contas ficam para depois, em Vendas › Categorias). */
function ModalCriarCategoriaRapida({ aberto, podeCriar, aoFechar, aoCriar }: { aberto: boolean; podeCriar: boolean; aoFechar: () => void; aoCriar: (c: Categoria) => void }) {
  const [form] = Form.useForm<{ nome: string }>();
  const criar = useMutation({
    mutationFn: (v: { nome: string }) => enviar<Categoria>('post', '/logistica/categorias-produtos', { nome: v.nome.trim() }),
    onSuccess: ({ dados, mensagem }) => {
      message.success(mensagem);
      form.resetFields();
      aoCriar(dados);
    },
    onError: (e) => notificarErro(e, 'Não foi possível criar a categoria'),
  });
  return (
    <ModalCriacaoRapida<{ nome: string }> aberto={aberto} titulo="Criar nova categoria" podeCriar={podeCriar} aGravar={criar.isPending} aoFechar={aoFechar} form={form} onFinish={(v) => criar.mutate(v)}>
      <Form.Item name="nome" label="Nome" rules={[{ required: true, message: 'Indique o nome.' }, { max: 255 }]}>
        <Input autoFocus />
      </Form.Item>
    </ModalCriacaoRapida>
  );
}

/** Categoria de produtos. permissaoCriar: mostra «+ Criar nova categoria» no fundo da lista. */
export function SeletorCategoria({ permissaoCriar, ...props }: PropsBase<number> & { permissaoCriar?: string }) {
  const { pode } = useSessao();
  const consultas = useQueryClient();
  const { data, isLoading } = useCategorias();
  const [aCriar, setACriar] = useState(false);
  const podeCriar = !!permissaoCriar && pode(permissaoCriar);
  return (
    <>
      <Select<number>
        showSearch
        optionFilterProp="label"
        loading={isLoading}
        placeholder="Categoria"
        options={(data ?? []).map((c) => ({ value: c.id, label: c.nome }))}
        popupRender={(menu) => dropdownComCriar(menu, { mostrar: !!permissaoCriar, podeCriar, rotulo: 'categoria', aoClicar: () => setACriar(true) })}
        {...props}
      />
      {permissaoCriar && (
        <ModalCriarCategoriaRapida
          aberto={aCriar}
          podeCriar={podeCriar}
          aoFechar={() => setACriar(false)}
          aoCriar={(c) => {
            void consultas.invalidateQueries({ queryKey: ['logistica', 'categorias'] });
            setACriar(false);
            props.onChange?.(c.id, { value: c.id, label: c.nome });
          }}
        />
      )}
    </>
  );
}

interface ValoresProdutoRapido { codigo: string; nome: string; categoria_produto_id: number; preco_unitario?: number; e_servico?: boolean }

/** Criação mínima de um produto (código, nome, categoria — obrigatória no servidor — e preço; o resto fica para a ficha completa). */
function ModalCriarProdutoRapido({ aberto, podeCriar, aoFechar, aoCriar }: { aberto: boolean; podeCriar: boolean; aoFechar: () => void; aoCriar: (p: ProdutoCatalogo) => void }) {
  const [form] = Form.useForm<ValoresProdutoRapido>();
  const criar = useMutation({
    mutationFn: (v: ValoresProdutoRapido) =>
      enviar<ProdutoCatalogo>('post', '/logistica/produtos', { codigo: v.codigo.trim(), nome: v.nome.trim(), categoria_produto_id: v.categoria_produto_id, preco_unitario: v.preco_unitario ?? null, e_servico: !!v.e_servico, movimenta_stock: !v.e_servico }),
    onSuccess: ({ dados, mensagem }) => {
      message.success(mensagem);
      form.resetFields();
      aoCriar(dados);
    },
    onError: (e) => notificarErro(e, 'Não foi possível criar o produto'),
  });
  return (
    <ModalCriacaoRapida<ValoresProdutoRapido> aberto={aberto} titulo="Criar novo produto" podeCriar={podeCriar} aGravar={criar.isPending} aoFechar={aoFechar} form={form} onFinish={(v) => criar.mutate(v)}>
      <Form.Item name="codigo" label="Código" rules={[{ required: true, message: 'Indique o código.' }, { max: 50 }]}>
        <Input autoFocus />
      </Form.Item>
      <Form.Item name="nome" label="Nome" rules={[{ required: true, message: 'Indique o nome.' }, { max: 255 }]}>
        <Input />
      </Form.Item>
      <Form.Item name="categoria_produto_id" label="Categoria" rules={[{ required: true, message: 'Indique a categoria.' }]}>
        <SeletorCategoria style={{ width: '100%' }} />
      </Form.Item>
      <Form.Item name="preco_unitario" label="Preço unitário">
        <InputNumber min={0} style={{ width: '100%' }} decimalSeparator="," />
      </Form.Item>
      <Form.Item name="e_servico" label="É um serviço (não movimenta stock)" valuePropName="checked">
        <Switch />
      </Form.Item>
    </ModalCriacaoRapida>
  );
}

/** Produto do catálogo (produtos activos), com pesquisa por código ou nome. permissaoCriar: mostra «+ Criar novo produto». */
export function SeletorProduto({ apenasStock, permissaoCriar, ...props }: PropsBase<number> & { apenasStock?: boolean; permissaoCriar?: string }) {
  const { pode } = useSessao();
  const consultas = useQueryClient();
  const { data, isLoading } = useCatalogo();
  const [aCriar, setACriar] = useState(false);
  const podeCriar = !!permissaoCriar && pode(permissaoCriar);
  return (
    <>
      <Select<number>
        showSearch
        optionFilterProp="label"
        loading={isLoading}
        placeholder="Produto ou serviço"
        options={(data ?? []).filter((p) => !apenasStock || p.movimenta_stock).map((p) => ({ value: p.id, label: rotuloProduto(p) }))}
        popupRender={(menu) => dropdownComCriar(menu, { mostrar: !!permissaoCriar, podeCriar, rotulo: 'produto', aoClicar: () => setACriar(true) })}
        {...props}
      />
      {permissaoCriar && (
        <ModalCriarProdutoRapido
          aberto={aCriar}
          podeCriar={podeCriar}
          aoFechar={() => setACriar(false)}
          aoCriar={(p) => {
            void consultas.invalidateQueries({ queryKey: ['logistica', 'catalogo'] });
            setACriar(false);
            props.onChange?.(p.id, { value: p.id, label: rotuloProduto(p) });
          }}
        />
      )}
    </>
  );
}

interface ValoresArmazem { nome: string; codigo?: string; localizacao?: string; predefinido?: boolean }

/** Criação mínima de um armazém (os mesmos campos de `Armazens.tsx` → `dadosArmazem`; só o nome é obrigatório). */
function ModalCriarArmazemRapido({ aberto, podeCriar, aoFechar, aoCriar }: { aberto: boolean; podeCriar: boolean; aoFechar: () => void; aoCriar: (a: Armazem) => void }) {
  const [form] = Form.useForm<ValoresArmazem>();
  const criar = useMutation({
    mutationFn: (v: ValoresArmazem) =>
      enviar<Armazem>('post', '/logistica/armazens', { nome: v.nome.trim(), codigo: v.codigo?.trim() || null, localizacao: v.localizacao?.trim() || null, predefinido: !!v.predefinido }),
    onSuccess: ({ dados, mensagem }) => {
      message.success(mensagem);
      form.resetFields();
      aoCriar(dados);
    },
    onError: (e) => notificarErro(e, 'Não foi possível criar o armazém'),
  });
  return (
    <ModalCriacaoRapida<ValoresArmazem> aberto={aberto} titulo="Criar novo armazém" podeCriar={podeCriar} aGravar={criar.isPending} aoFechar={aoFechar} form={form} onFinish={(v) => criar.mutate(v)}>
      <Form.Item name="nome" label="Nome / Identificação" rules={[{ required: true, message: 'Indique o nome.' }, { max: 255 }]}>
        <Input autoFocus />
      </Form.Item>
      <Form.Item name="codigo" label="Código" rules={[{ max: 20 }]}>
        <Input />
      </Form.Item>
      <Form.Item name="localizacao" label="Localização / Endereço" rules={[{ max: 150 }]}>
        <Input />
      </Form.Item>
      <Form.Item name="predefinido" valuePropName="checked">
        <Checkbox>Armazém predefinido</Checkbox>
      </Form.Item>
    </ModalCriacaoRapida>
  );
}

/** permissaoCriar: com a permissão indicada (ex.: «armazem_config»), mostra «+ Criar novo armazém» no fundo da lista. */
export function SeletorArmazem({ permissaoCriar, ...props }: PropsBase<number> & { permissaoCriar?: string }) {
  const { pode } = useSessao();
  const consultas = useQueryClient();
  const { data, isLoading } = useArmazens();
  const [aCriar, setACriar] = useState(false);
  const podeCriar = !!permissaoCriar && pode(permissaoCriar);
  return (
    <>
      <Select<number>
        showSearch
        optionFilterProp="label"
        loading={isLoading}
        placeholder="Armazém"
        options={(data ?? []).map((a) => ({ value: a.id, label: `${a.nome}${a.predefinido ? ' (predefinido)' : ''}` }))}
        popupRender={(menu) => dropdownComCriar(menu, { mostrar: !!permissaoCriar, podeCriar, rotulo: 'armazém', aoClicar: () => setACriar(true) })}
        {...props}
      />
      {permissaoCriar && (
        <ModalCriarArmazemRapido
          aberto={aCriar}
          podeCriar={podeCriar}
          aoFechar={() => setACriar(false)}
          aoCriar={(a) => {
            void consultas.invalidateQueries({ queryKey: ['logistica'] });
            setACriar(false);
            props.onChange?.(a.id, { value: a.id, label: a.nome });
          }}
        />
      )}
    </>
  );
}

interface PropsConta {
  value?: string;
  onChange?: (v: string) => void;
  /** Prefixo das contas sugeridas (ex.: '31' clientes, '32' fornecedores, '4' disponibilidades). */
  prefixo?: string;
  placeholder?: string;
  disabled?: boolean;
}

/**
 * Conta do plano (só se escolhem contas de movimento; as totalizadoras aparecem bloqueadas). Sugere as contas do plano quando o utilizador as pode consultar;
 * caso contrário, aceita o código escrito (o servidor valida a conta).
 */
export function SeletorConta({ value, onChange, prefixo, placeholder = 'Código da conta', disabled }: PropsConta) {
  const consulta = useQuery({
    queryKey: ['plano', 'contas', prefixo ?? ''],
    queryFn: () => obter<{ codigo: string; descricao: string; tipo?: string | null }[]>('/contabilidade/plano-contas', { prefixo }),
    staleTime: 600_000,
    retry: false,
  });
  return (
    <AutoComplete
      value={value}
      onChange={(v: string) => onChange?.(v)}
      disabled={disabled}
      placeholder={placeholder}
      allowClear
      options={(consulta.data ?? []).slice(0, 2000).map((c) => {
        const bloqueada = c.tipo != null && c.tipo !== 'M';
        return { value: c.codigo, label: `${c.codigo} - ${c.descricao}${bloqueada ? ' (totalizadora — não pode ser mapeada)' : ''}`, disabled: bloqueada };
      })}
      filterOption={(i, o) => String(o?.label ?? '').toLowerCase().includes(i.toLowerCase())}
    />
  );
}
