import { Button, Card, Form, Modal } from 'antd';
import { PlusOutlined } from '@ant-design/icons';
import type { ColumnsType } from 'antd/es/table';
import { useQuery } from '@tanstack/react-query';
import { useEffect, useMemo, useState, type ReactNode } from 'react';
import { createPortal } from 'react-dom';
import { obter } from '@/api/cliente';
import { BotoesExportar } from '@/componentes/impressao';
import { BarraFiltros, larguraModal as limitarLargura, scrollTabela, useEcraPequeno } from '@/componentes/responsivo';
import { notificarErro } from '@/utilitarios/erros';
import { useAccaoRh } from './consultas';
import { contem, PesquisaLocal } from './componentes';
import { pedidoTabela } from './impressao';
import './cadastros.css';


import { TabelaComModos } from '@/componentes/vistas';
interface Props<T extends { id: number }> {
  /** Caminho da API (GET lista; POST cria; PUT/DELETE {url}/{id}). */
  url: string;
  chave: unknown[];
  colunas: ColumnsType<T>;
  /** Campos do formulário (Form.Item). */
  campos: ReactNode;
  podeGerir: boolean;
  podeEliminar: boolean;
  nomeItem: string;
  /** Valores do formulário a partir do registo (edição) e por omissão (novo). */
  paraFormulario?: (r: T) => Record<string, unknown>;
  valoresNovos?: Record<string, unknown>;
  /** Transformação dos valores do formulário antes do envio. */
  paraEnvio?: (v: Record<string, unknown>) => Record<string, unknown>;
  pesquisa?: (r: T) => string;
  accoesExtra?: ReactNode;
  larguraModal?: number;
  /** Título do documento impresso (mostra «Imprimir» e «PDF» com as linhas filtradas). */
  tituloImpressao?: string;
  /** Texto do botão de criar (antigo: «Nova Função», «Novo Infotipo», «Adicionar Banco»); por omissão «Novo». */
  rotuloNovo?: string;
  /** Título do modal de criação (antigo: «Adicionar Nova Função»…). */
  tituloNovo?: string;
  /** Título do cartão (ex.: «Bancos»). */
  titulo?: ReactNode;
  /**
   * Botões no cabeçalho da página, como no antigo (Template · Copiar de outra empresa · Importar Excel · + Nova Função
   * à direita do h2.view-title, js/app_v2.js renderFunçãoes/renderInfotipos/renderConfiguracoesBancarias): elemento de
   * destino dado por `useDestinoAccoes` (vai para as `accoes` do CabecalhoPagina), ou `null` quando este cadastro não
   * está visível (separador inactivo). Neste modo o cartão fica só com a lista do antigo (caixa «N registos
   * encontrados» com Excel/PDF/Imprimir/Colunas e filtros por coluna, sem a pesquisa geral — o DataTables do antigo
   * não a tinha). Sem esta prop (omissão), os botões ficam na barra do cartão.
   */
  accoesCabecalho?: HTMLElement | null;
  /** Com `accoesCabecalho`: o botão de criar fica no cabeçalho do cartão (antigo: «Adicionar Banco» dentro de «Gestão de Bancos»). */
  novoNoCartao?: boolean;
  /** Ordem inicial das linhas (antigo: DataTables sem `order` → 1.ª coluna ascendente). */
  ordemInicial?: (a: T, b: T) => number;
  /**
   * Formulário sempre visível dentro do cartão, por cima da lista (antigo: «Gestão de Bancos» com os campos em grelha e
   * «+ Adicionar Banco» por baixo). Editar preenche este mesmo formulário («Actualizar» / «Cancelar»), sem modal.
   */
  camposEmLinha?: ReactNode;
}

/** Destino dos botões no cabeçalho da página (ver `accoesCabecalho`): `[destino, elemento a pôr nas accoes do CabecalhoPagina]`. */
export function useDestinoAccoes(): [HTMLElement | null, ReactNode] {
  const [destino, setDestino] = useState<HTMLElement | null>(null);
  return [destino, <div key="destino-accoes" ref={setDestino} style={{ display: 'flex', flexWrap: 'wrap', gap: 8, justifyContent: 'flex-end' }} />];
}

/** Tabela de suporte com criar/editar num modal e eliminar (cargos, tipos de organização, bancos, rubricas, itens). */
export function CadastroSimples<T extends { id: number }>({
  url, chave, colunas, campos, podeGerir, podeEliminar, nomeItem, paraFormulario, valoresNovos, paraEnvio, pesquisa, accoesExtra, larguraModal = 560, tituloImpressao,
  rotuloNovo, tituloNovo, titulo, accoesCabecalho, novoNoCartao, ordemInicial, camposEmLinha,
}: Props<T>) {
  const pequeno = useEcraPequeno();
  const [form] = Form.useForm();
  const [aberto, setAberto] = useState<T | 'novo' | null>(null);
  const [termo, setTermo] = useState('');
  const consulta = useQuery({ queryKey: chave, queryFn: () => obter<T[]>(url) });
  useEffect(() => {
    if (consulta.error) notificarErro(consulta.error, 'Erro ao carregar a listagem');
  }, [consulta.error]);
  const [formLinha] = Form.useForm();
  const [emEdicao, setEmEdicao] = useState<T | null>(null);
  const limparLinha = () => {
    formLinha.resetFields();
    formLinha.setFieldsValue(valoresNovos ?? {});
    setEmEdicao(null);
  };
  const accao = useAccaoRh(() => {
    setAberto(null);
    if (camposEmLinha) limparLinha();
  });

  const abrir = (r: T | 'novo') => {
    if (camposEmLinha && r !== 'novo') {
      formLinha.resetFields();
      formLinha.setFieldsValue(paraFormulario ? paraFormulario(r) : r);
      setEmEdicao(r);
      return;
    }
    form.resetFields();
    form.setFieldsValue(r === 'novo' ? valoresNovos ?? {} : paraFormulario ? paraFormulario(r) : r);
    setAberto(r);
  };

  const accoesLinha = podeGerir || podeEliminar
    ? (r: T) => [
        podeGerir && { icone: 'edit', titulo: 'Editar', onClick: () => abrir(r) },
        podeEliminar && { icone: 'trash', titulo: 'Excluir', variante: 'perigo' as const, confirmar: { titulo: `Eliminar ${nomeItem}?`, textoOk: 'Eliminar', perigo: true },
          onClick: () => accao.mutateAsync({ metodo: 'delete', url: `${url}/${r.id}` }) },
      ]
    : undefined;

  const ordenados = useMemo(() => (ordemInicial ? [...(consulta.data ?? [])].sort(ordemInicial) : consulta.data ?? []), [consulta.data, ordemInicial]);
  const noCabecalho = accoesCabecalho !== undefined;
  const dados = noCabecalho ? ordenados : ordenados.filter((r) => !pesquisa || contem(pesquisa(r), termo));
  const botaoNovo = podeGerir && !camposEmLinha ? <Button type="primary" icon={<PlusOutlined />} onClick={() => abrir('novo')}>{rotuloNovo ?? 'Novo'}</Button> : null;

  return (
    <Card title={titulo} extra={noCabecalho && novoNoCartao ? botaoNovo : undefined}>
      {noCabecalho && accoesCabecalho && createPortal(<>{accoesExtra}{!novoNoCartao && botaoNovo}</>, accoesCabecalho)}
      {!noCabecalho && <BarraFiltros
        accoes={
          <>
            {accoesExtra}
            {tituloImpressao && (
              <BotoesExportar
                desactivado={!dados.length}
                obterPedido={() => pedidoTabela({ titulo: tituloImpressao, filtros: termo ? [`Pesquisa: ${termo}`] : undefined, colunas, linhas: dados })}
              />
            )}
            {botaoNovo}
          </>
        }
      >
        {pesquisa ? <PesquisaLocal aoMudar={setTermo} /> : null}
      </BarraFiltros>}
      {camposEmLinha && podeGerir && (
        <Form form={formLinha} layout="vertical" initialValues={valoresNovos} className="rh-form-em-linha" onFinish={(v: Record<string, unknown>) => {
          const dadosEnvio = paraEnvio ? paraEnvio(v) : v;
          if (emEdicao) accao.mutate({ metodo: 'put', url: `${url}/${emEdicao.id}`, dados: dadosEnvio });
          else accao.mutate({ metodo: 'post', url, dados: dadosEnvio });
        }}>
          {camposEmLinha}
          <div style={{ display: 'flex', gap: 8, marginBottom: 16 }}>
            <Button type="primary" htmlType="submit" icon={emEdicao ? undefined : <PlusOutlined />} loading={accao.isPending} style={{ minWidth: 'min(100%, 33%)' }}>
              {emEdicao ? `Actualizar ${nomeItem}` : rotuloNovo ?? 'Adicionar'}
            </Button>
            {emEdicao && <Button onClick={limparLinha}>Cancelar</Button>}
          </div>
        </Form>
      )}
      <TabelaComModos<T> rowKey="id" size={pequeno ? 'small' : 'middle'} loading={consulta.isFetching} columns={colunas} dataSource={dados} scroll={scrollTabela()}
        accoes={accoesLinha} impressao={noCabecalho && tituloImpressao ? { titulo: tituloImpressao } : undefined} />
      <Modal
        title={aberto === 'novo' ? tituloNovo ?? `Novo — ${nomeItem}` : `Editar — ${nomeItem}`}
        open={aberto !== null}
        width={limitarLargura(larguraModal)}
        onCancel={() => setAberto(null)}
        okText="Gravar"
        cancelText="Cancelar"
        confirmLoading={accao.isPending}
        onOk={() => form.submit()}
        destroyOnHidden
      >
        <Form form={form} layout="vertical" onFinish={(v: Record<string, unknown>) => {
          const dadosEnvio = paraEnvio ? paraEnvio(v) : v;
          if (aberto === 'novo') accao.mutate({ metodo: 'post', url, dados: dadosEnvio });
          else if (aberto) accao.mutate({ metodo: 'put', url: `${url}/${aberto.id}`, dados: dadosEnvio });
        }}>
          {campos}
        </Form>
      </Modal>
    </Card>
  );
}
