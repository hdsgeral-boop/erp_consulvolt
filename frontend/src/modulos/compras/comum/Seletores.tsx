import { AutoComplete, Button, Divider, Form, Input, Modal, Select, Space, Typography, message, type SelectProps } from 'antd';
import { PlusOutlined } from '@ant-design/icons';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { enviar, obter, obterPagina } from '@/api/cliente';
import { useSessao } from '@/sessao/SessaoContexto';
import { notificarErro } from '@/utilitarios/erros';
import { SeletorConta as SeletorContaPlano } from '@/modulos/contab/comum/Seletores';
import { prefixosConta } from '@/modulos/contab/tabelasAux/contasTerceiro';
import { rotuloProduto, useArmazens, useCatalogo, type FichaTerceiro } from './referencias';
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
    <Modal
      open={aberto}
      title={papel === 'CLIENTE' ? 'Criar novo cliente' : 'Criar novo fornecedor'}
      okText="Criar"
      cancelText="Cancelar"
      onCancel={() => { form.resetFields(); aoFechar(); }}
      okButtonProps={{ disabled: !podeCriar, loading: criar.isPending }}
      onOk={() => form.submit()}
      destroyOnHidden
    >
      {!podeCriar && (
        <Typography.Paragraph type="warning">
          Não tem a permissão para criar {papel === 'CLIENTE' ? 'clientes' : 'fornecedores'}.
        </Typography.Paragraph>
      )}
      <Form form={form} layout="vertical" disabled={!podeCriar} onFinish={(v) => criar.mutate(v)}>
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
      </Form>
    </Modal>
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
  return (
    <>
      <Select<number>
        showSearch
        allowClear
        filterOption={false}
        onSearch={setPesquisa}
        loading={consulta.isFetching}
        placeholder={papel === 'CLIENTE' ? 'Pesquisar cliente (nome ou NIF)' : 'Pesquisar fornecedor (nome ou NIF)'}
        options={(consulta.data?.itens ?? []).map((t) => ({ value: t.id, label: `${t.nome.trim()}${t.nif ? ` (NIF ${t.nif})` : ''}` }))}
        dropdownRender={permissaoCriar ? (menu) => (
          <>
            {menu}
            <Divider style={{ margin: '4px 0' }} />
            {podeCriar ? (
              <Button type="text" block icon={<PlusOutlined />} onMouseDown={(e) => e.preventDefault()} onClick={() => setACriar(true)}>
                Criar novo {papel === 'CLIENTE' ? 'cliente' : 'fornecedor'}
              </Button>
            ) : (
              <Space style={{ padding: '4px 11px' }}><Typography.Text type="secondary" style={{ fontSize: 12 }}>Sem permissão para criar.</Typography.Text></Space>
            )}
          </>
        ) : undefined}
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

/** Produto do catálogo (produtos activos), com pesquisa por código ou nome. */
export function SeletorProduto({ apenasStock, ...props }: PropsBase<number> & { apenasStock?: boolean }) {
  const { data, isLoading } = useCatalogo();
  return (
    <Select<number>
      showSearch
      optionFilterProp="label"
      loading={isLoading}
      placeholder="Produto ou serviço"
      options={(data ?? []).filter((p) => !apenasStock || p.movimenta_stock).map((p) => ({ value: p.id, label: rotuloProduto(p) }))}
      {...props}
    />
  );
}

export function SeletorArmazem(props: PropsBase<number>) {
  const { data, isLoading } = useArmazens();
  return (
    <Select<number>
      showSearch
      optionFilterProp="label"
      loading={isLoading}
      placeholder="Armazém"
      options={(data ?? []).map((a) => ({ value: a.id, label: `${a.nome}${a.predefinido ? ' (predefinido)' : ''}` }))}
      {...props}
    />
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
