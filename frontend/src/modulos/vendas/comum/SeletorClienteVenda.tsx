import { Alert, Form, Modal, Select, Space, Tag, Typography, message, theme, type SelectProps } from 'antd';
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect, useMemo, useState } from 'react';
import { enviar, obter } from '@/api/cliente';
import { useSessao } from '@/sessao/SessaoContexto';
import { notificarErro } from '@/utilitarios/erros';
import { SeletorConta } from '@/modulos/compras/comum/Seletores';
import {
  ATRASO_PESQUISA_MS, LIMITE_PESQUISA, contaClienteValida, juntarClientes, notaCliente, precisaPapelCliente, rotuloCliente,
  type ClienteResumo, type RespostaPesquisaClientes,
} from './clientes';

type Props = Omit<SelectProps<number>, 'options' | 'showSearch' | 'filterOption' | 'onSearch' | 'loading' | 'onChange'> & {
  onChange?: (v: number | undefined) => void;
  /** Cliente já escolhido que pode não vir na primeira página (cópia, conversão do CRM, guia). */
  inicial?: ClienteResumo | null;
  /** Ficha resumida do cliente escolhido (ex.: sugerir a moeda). */
  onEscolher?: (c: ClienteResumo | undefined) => void;
};

/** Texto da pesquisa só depois de o utilizador parar de escrever. */
function useAdiado<T>(valor: T, ms: number): T {
  const [adiado, setAdiado] = useState(valor);
  useEffect(() => {
    const t = window.setTimeout(() => setAdiado(valor), ms);
    return () => window.clearTimeout(t);
  }, [valor, ms]);
  return adiado;
}

/**
 * Cliente do documento de venda: pesquisa na tabela de terceiros enquanto se escreve (nome, NIF, conta ou n.º; no
 * servidor, com limite). Os fornecedores que ainda não são clientes aparecem também: ao escolher um, pede-se a conta de
 * cliente (classe 31) e o servidor acrescenta-lhe o papel («Cliente e Fornecedor», ADR-071). Colaboradores não aparecem.
 */
export function SeletorClienteVenda({ value, onChange, inicial, onEscolher, ...props }: Props) {
  const { token } = theme.useToken();
  const { pode } = useSessao();
  const consultas = useQueryClient();
  const [texto, setTexto] = useState('');
  const pesquisa = useAdiado(texto.trim(), ATRASO_PESQUISA_MS);
  const [conhecidos, setConhecidos] = useState<ClienteResumo[]>([]);
  const [aPromover, setAPromover] = useState<ClienteResumo | null>(null);
  const [form] = Form.useForm<{ conta_cliente?: string }>();
  const consulta = useQuery({
    queryKey: ['vendas', 'clientes', 'pesquisa', pesquisa],
    queryFn: () => obter<RespostaPesquisaClientes>('/vendas/clientes/pesquisa', { q: pesquisa || undefined, limite: LIMITE_PESQUISA }),
    placeholderData: keepPreviousData,
    staleTime: 30_000,
    retry: false,
  });
  const itens = useMemo(
    () => juntarClientes(consulta.data?.itens ?? [], inicial ? [inicial] : [], conhecidos),
    [consulta.data, inicial, conhecidos],
  );
  const escolher = (c: ClienteResumo | undefined) => {
    if (c) setConhecidos((k) => juntarClientes([c], k));
    onChange?.(c?.id);
    onEscolher?.(c);
  };

  const promover = useMutation({
    mutationFn: ({ id, conta }: { id: number; conta: string }) => enviar<ClienteResumo>('post', `/vendas/clientes/${id}/papel-cliente`, { conta_cliente: conta }),
    onSuccess: ({ dados, mensagem }) => {
      message.success(mensagem);
      void consultas.invalidateQueries({ queryKey: ['vendas', 'clientes'] });
      void consultas.invalidateQueries({ queryKey: ['terceiros'] });
      setAPromover(null);
      escolher(dados);
    },
    onError: (e) => notificarErro(e, 'Não foi possível tornar a entidade cliente'),
  });

  const podePromover = pode('vendas_clientes_gerir');
  const abrirPromocao = (c: ClienteResumo) => {
    setAPromover(c);
    form.setFieldsValue({ conta_cliente: consulta.data?.conta_cliente_sugerida ?? undefined });
  };

  return (
    <>
      <Select<number>
        showSearch
        allowClear
        filterOption={false}
        aria-label="Cliente"
        placeholder="Pesquisar por nome, NIF ou conta"
        notFoundContent={consulta.isFetching ? 'A pesquisar…' : pesquisa ? 'Nenhum cliente ou fornecedor encontrado.' : 'Escreva para pesquisar.'}
        {...props}
        value={value}
        onSearch={setTexto}
        loading={consulta.isFetching}
        onChange={(v) => {
          const c = itens.find((x) => x.id === v);
          if (precisaPapelCliente(c)) return void abrirPromocao(c as ClienteResumo);   // o valor só muda depois de passar a cliente
          escolher(c);
        }}
        options={itens.map((c) => ({ value: c.id, label: rotuloCliente(c), cliente: c }))}
        optionRender={(o) => {
          const c = (o.data as { cliente: ClienteResumo }).cliente;
          const nota = notaCliente(c);
          return (
            <Space size={6} wrap={false}>
              <span>{rotuloCliente(c)}</span>
              {nota && <Tag color="orange" style={{ marginInlineEnd: 0 }}>{nota}</Tag>}
            </Space>
          );
        }}
      />
      <Modal
        open={!!aPromover}
        title="Usar como cliente"
        okText="Tornar cliente e escolher"
        cancelText="Cancelar"
        onCancel={() => setAPromover(null)}
        okButtonProps={{ disabled: !podePromover, loading: promover.isPending }}
        onOk={() => form.submit()}
        destroyOnHidden
      >
        {aPromover && (
          <>
            <Typography.Paragraph>
              <strong>{rotuloCliente(aPromover)}</strong> está registado como fornecedor e ainda não é cliente. Ao continuar, passa a
              «Cliente e Fornecedor» com a conta de cliente indicada (a conta de fornecedor mantém-se).
            </Typography.Paragraph>
            {!podePromover && (
              <Alert type="warning" showIcon style={{ marginBottom: 12 }}
                message="Não tem a permissão «Gerir clientes»: peça a quem a tenha para acrescentar o papel de cliente em Vendas › Clientes." />
            )}
            <Form form={form} layout="vertical" disabled={!podePromover}
              onFinish={(v) => promover.mutate({ id: aPromover.id, conta: String(v.conta_cliente ?? '').trim() })}>
              <Form.Item
                name="conta_cliente"
                label="Conta de cliente (classe 31)"
                rules={[{ validator: async (_, v) => (contaClienteValida(v) ? undefined : Promise.reject(new Error('Indique uma conta de movimento da classe 31.'))) }]}
                extra={<span style={{ color: token.colorTextSecondary }}>Cada cliente usa a conta da sua ficha; sem ela a factura não pode ser contabilizada.</span>}
              >
                <SeletorConta prefixo="31" placeholder="Ex.: 3112" />
              </Form.Item>
            </Form>
          </>
        )}
      </Modal>
    </>
  );
}
