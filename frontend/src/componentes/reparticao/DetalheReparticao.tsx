import { Alert, Button, Card, Modal, Space, Spin, Table, Tag, Typography } from 'antd';
import { ApartmentOutlined, EditOutlined } from '@ant-design/icons';
import { useMutation, useQuery, useQueryClient, type QueryKey } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { formatarKz } from '@/utilitarios/formatacao';
import { AlocacaoUN } from './AlocacaoUN';
import { gravarAlocacao, obterAlocacao, useCriterios, type TipoObjectoAlocacao } from './api';
import { alocacaoDeApi, alocacaoParaApi, validarAlocacao, type Alocacao, type LinhaReparticao } from './regras';

/**
 * Repartição por unidades de negócio nos ecrãs de DETALHE (ADR-071, R2/R5): colunas «Un. negócio»/«C. custo» por linha,
 * bloco só de leitura da alocação do documento e modal para a alterar (PUT /reparticao/alocacoes/{tipo}/{id}).
 * O servidor recusa alterar documentos contabilizados ou anulados (ReparticaoController::exigirAlteravel); aqui só se
 * esconde o botão e se explica porquê.
 */

/** Bloco `alocacao_un` devolvido pelas APIs de detalhe (App\Services\Gestao\ResumoAlocacaoUN). */
export interface ResumoAlocacao {
  criterio: { id: number; codigo: string | null; nome: string | null; criterio: string | null } | null;
  unidades: { unidade_negocio_id: number; peso: string | null }[];
  linhas: LinhaReparticao[];
  total: string | null;
  erro: string | null;
}

/** Linha com UN/CC próprias (id + nome). Sem nada = «— cabeçalho —». */
export interface LinhaComUNCC {
  unidade_negocio_id?: number | null;
  centro_custo_id?: number | null;
  unidade_negocio?: { id: number; codigo: string | null; nome: string | null } | null;
  centro_custo?: { id: number; codigo: string | null; descricao: string | null } | null;
}

export const SEM_UNCC_LINHA = '— cabeçalho —';

export function textoUNLinha(l: LinhaComUNCC): string {
  if (!l.unidade_negocio_id) return SEM_UNCC_LINHA;
  const u = l.unidade_negocio;
  return u ? [u.codigo, u.nome].filter(Boolean).join(' — ') : `#${l.unidade_negocio_id}`;
}

export function textoCCLinha(l: LinhaComUNCC): string {
  if (!l.centro_custo_id) return SEM_UNCC_LINHA;
  const c = l.centro_custo;
  return c ? [c.codigo, c.descricao].filter(Boolean).join(' — ') : `#${l.centro_custo_id}`;
}

/**
 * Colunas «Un. negócio» e «C. custo» para a tabela de linhas do detalhe (linha_un_cc.js). Só aparecem quando alguma linha
 * tem UN/CC própria: os nomes vêm na própria resposta do detalhe, por isso não se pedem as listas de UN/CC (quem só consulta
 * ou aprova não tem acesso a essas tabelas).
 */
export function useColunasUNCC<T extends LinhaComUNCC>(linhas: T[] | null | undefined) {
  const alguma = (linhas ?? []).some((l) => l.unidade_negocio_id || l.centro_custo_id);
  if (!alguma) return [];
  const celula = (t: string) => (t === SEM_UNCC_LINHA ? <Typography.Text type="secondary" style={{ fontSize: 12 }}>{t}</Typography.Text> : <small>{t}</small>);
  return [
    { title: 'Un. negócio', key: 'un_linha', render: (_: unknown, l: T) => celula(textoUNLinha(l)), valorImpressao: (l: T) => textoUNLinha(l) },
    { title: 'C. custo', key: 'cc_linha', render: (_: unknown, l: T) => celula(textoCCLinha(l)), valorImpressao: (l: T) => textoCCLinha(l) },
  ];
}

/** Tabela só de leitura com as percentagens (e parcelas) de cada unidade. */
export function ResumoAlocacaoUN({ resumo }: { resumo: ResumoAlocacao }) {
  return (
    <>
      <div style={{ marginBottom: 6 }}>
        Critério: <strong>{resumo.criterio ? `${resumo.criterio.codigo ?? ''} ${resumo.criterio.nome ?? ''}`.trim() : 'critério já não existe'}</strong>
        {' '}<Tag>{resumo.unidades.length} unidade(s)</Tag>
      </div>
      {resumo.erro && <Alert type="warning" showIcon message={resumo.erro} style={{ marginBottom: 6 }} />}
      {!!resumo.linhas.length && (
        <Table<LinhaReparticao>
          size="small"
          pagination={false}
          rowKey="unidade_negocio_id"
          dataSource={resumo.linhas}
          columns={[
            { title: 'Unidade', dataIndex: 'nome' },
            { title: '%', dataIndex: 'percentagem', align: 'right', render: (p: string) => `${Number(p).toFixed(2).replace('.', ',')} %` },
            ...(resumo.total ? [{ title: 'Valor', dataIndex: 'valor', align: 'right' as const, render: (v: string) => formatarKz(v) }] : []),
          ]}
        />
      )}
    </>
  );
}

export interface PropsModalAlocacaoUN {
  tipo: TipoObjectoAlocacao;
  /** id do objecto; null fecha o modal. */
  id: number | null;
  /** Valor de referência da pré-visualização (ex.: total líquido). */
  valor?: number | null;
  aoFechar: () => void;
  /** Consultas a refrescar depois de gravar. */
  invalidar?: QueryKey[];
  titulo?: string;
}

/** Modal reutilizável para alterar a alocação por unidades de negócio de um objecto gravado (R2). */
export function ModalAlocacaoUN({ tipo, id, valor, aoFechar, invalidar = [], titulo = 'Repartição por unidades de negócio' }: PropsModalAlocacaoUN) {
  const aberto = id !== null;
  const qc = useQueryClient();
  const criterios = useCriterios(true);
  const gravada = useQuery({ queryKey: ['reparticao', 'alocacao', tipo, id], queryFn: () => obterAlocacao(tipo, id!), enabled: aberto, retry: false });
  const [valorForm, setValorForm] = useState<Alocacao | null>(null);
  useEffect(() => {
    if (aberto && gravada.data) setValorForm(alocacaoDeApi(gravada.data.unidades_alocadas));
  }, [aberto, gravada.data]);
  const forma = (criterios.data ?? []).find((c) => c.id === valorForm?.criterio_id)?.criterio;
  const erro = valorForm?.criterio_id ? validarAlocacao(valorForm, forma) : null;
  const gravar = useMutation({
    mutationFn: () => gravarAlocacao(tipo, id!, alocacaoParaApi(valorForm, forma)),
    onSuccess: async () => {
      await Promise.all([...invalidar, ['reparticao', 'alocacao', tipo, id]].map((k) => qc.invalidateQueries({ queryKey: k })));
      aoFechar();
    },
  });

  return (
    <Modal open={aberto} onCancel={aoFechar} title={<span><ApartmentOutlined /> {titulo}</span>} okText="Gravar" cancelText="Cancelar" destroyOnHidden
      okButtonProps={{ disabled: !!erro || gravada.isLoading }} confirmLoading={gravar.isPending} onOk={() => gravar.mutate()} width={720}>
      {gravada.isLoading ? <Spin /> : (
        <>
          <Typography.Paragraph type="secondary" style={{ fontSize: 12 }}>
            Sem critério, o documento usa só a unidade de negócio do cabeçalho. A repartição conta na contabilização (linha › repartição do documento › cabeçalho);
            um documento contabilizado ou anulado já não pode ser alterado.
          </Typography.Paragraph>
          <AlocacaoUN value={valorForm} onChange={setValorForm} valor={valor ?? null} titulo="Repartir por várias unidades de negócio" />
        </>
      )}
      {(gravada.error || gravar.error) && <Alert style={{ marginTop: 8 }} type="error" showIcon message={((gravar.error ?? gravada.error) as Error).message} />}
    </Modal>
  );
}

/** Porque é que a alocação não se pode alterar (espelho de exigirAlteravel); null = pode. */
export function bloqueioAlocacao(doc: { contabilizado?: boolean | null; estado?: string | null }): string | null {
  if (doc.contabilizado) return 'Documento contabilizado: descontabilize-o para alterar a repartição.';
  if (doc.estado === 'ANULADO' || doc.estado === 'ANULADA') return 'Documento anulado: a repartição não pode ser alterada.';
  return null;
}

/**
 * Cartão «Unidades de negócio» do detalhe: bloco só de leitura (ou «uma só unidade, a do cabeçalho») e, com permissão e
 * documento alterável, o botão «Alterar repartição» que abre o ModalAlocacaoUN.
 */
export function CartaoAlocacaoUN({ tipo, id, resumo, valor, podeAlterar, bloqueio, invalidar, unidadeCabecalho, nota }: {
  tipo: TipoObjectoAlocacao;
  id: number;
  resumo: ResumoAlocacao | null | undefined;
  valor?: number | null;
  podeAlterar: boolean;
  bloqueio?: string | null;
  invalidar?: QueryKey[];
  unidadeCabecalho?: string | null;
  /** Ex.: «herdada da encomenda» — a repartição mostrada não é do próprio documento. */
  nota?: string | null;
}) {
  const [aberto, setAberto] = useState(false);
  if (!resumo && !podeAlterar) return null;
  return (
    <Card size="small" style={{ marginTop: 12 }} title={<span><ApartmentOutlined /> Unidades de negócio</span>}
      extra={podeAlterar && (
        <Space>
          {bloqueio && <Typography.Text type="secondary" style={{ fontSize: 12 }}>{bloqueio}</Typography.Text>}
          <Button size="small" icon={<EditOutlined />} disabled={!!bloqueio} onClick={() => setAberto(true)}>Alterar repartição</Button>
        </Space>
      )}>
      {nota && <Tag color="blue" style={{ marginBottom: 6 }}>{nota}</Tag>}
      {resumo ? <ResumoAlocacaoUN resumo={resumo} /> : (
        <Typography.Text type="secondary">Sem repartição: usa só a unidade do cabeçalho{unidadeCabecalho ? ` (${unidadeCabecalho})` : ''}.</Typography.Text>
      )}
      <ModalAlocacaoUN tipo={tipo} id={aberto ? id : null} valor={valor} invalidar={invalidar} aoFechar={() => setAberto(false)} />
    </Card>
  );
}
