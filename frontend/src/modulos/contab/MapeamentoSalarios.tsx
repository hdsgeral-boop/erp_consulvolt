import { Alert, Button, Card, Space, Table, Tabs, Tag, Typography, message } from 'antd';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect, useMemo, useState } from 'react';
import { enviar, obter } from '@/api/cliente';
import { scrollTabela, useEcra } from '@/componentes/responsivo';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { tabelaHtml } from '@/componentes/impressao';
import { useSessao } from '@/sessao/SessaoContexto';
import { notificarErro } from '@/utilitarios/erros';
import { alteracoes, chaveCelula, corpoGravacao, grelhaRubricas, grelhaSistema, type Coluna, type MapeamentoRubrica, type MapeamentoSistema } from './comum/mapeamento';
import { SeletorConta } from './comum/Seletores';

interface DadosMapeamento {
  tipos_organizacao: { id: number; nome: string }[];
  infotipos: { id: number; nome: string; tipo: string | null }[];
  codigos_sistema: string[];
  rubricas: MapeamentoRubrica[];
  sistema: MapeamentoSistema[];
}

const NOMES_SISTEMA: Record<string, string> = {
  NET_PAY_CREDIT: 'Líquido a pagar (crédito)',
  IRT_CREDIT: 'IRT retido (crédito)',
  IRT_AVENCADO_CREDIT: 'IRT de avençados (crédito)',
  INSS_FUNC_CREDIT: 'INSS do trabalhador (crédito)',
  INSS_EMP_DEBIT: 'INSS da entidade patronal (débito)',
  INSS_EMP_CREDIT: 'INSS da entidade patronal (crédito)',
  ROUNDING_DIFF: 'Diferenças de arredondamento',
};

/**
 * Contabilidade › Mapeamento contabilístico de salários (ecrã contabilidade). Os endpoints são do módulo RH
 * (GET/PUT /api/rh/mapeamentos-contabeis), protegidos pelas tarefas deste ecrã (contabilidade_view / contab_mapeamento).
 */
export default function MapeamentoSalarios() {
  const { pode } = useSessao();
  const cliente = useQueryClient();
  const consulta = useQuery({ queryKey: ['rh', 'mapeamentos-contabeis'], queryFn: () => obter<DadosMapeamento>('/rh/mapeamentos-contabeis') });
  const d = consulta.data;
  const originalR = useMemo(() => grelhaRubricas(d?.rubricas ?? []), [d]);
  const originalS = useMemo(() => grelhaSistema(d?.sistema ?? []), [d]);
  const [editR, setEditR] = useState<Record<string, string>>({});
  const [editS, setEditS] = useState<Record<string, string>>({});
  useEffect(() => {
    setEditR(originalR);
    setEditS(originalS);
  }, [originalR, originalS]);

  const mudR = alteracoes(originalR, editR);
  const mudS = alteracoes(originalS, editS);
  const editar = pode('contab_mapeamento');

  const gravar = useMutation({
    mutationFn: () => enviar('put', '/rh/mapeamentos-contabeis', corpoGravacao(mudR, mudS)),
    onSuccess: ({ mensagem }) => {
      message.success(mensagem);
      void cliente.invalidateQueries({ queryKey: ['rh', 'mapeamentos-contabeis'] });
    },
    onError: (e) => notificarErro(e, 'Não foi possível gravar os mapeamentos'),
  });

  const { telemovel } = useEcra();
  const colunas: { chave: Coluna; titulo: string }[] = [...(d?.tipos_organizacao ?? []).map((t) => ({ chave: t.id as Coluna, titulo: t.nome })), { chave: 'AV', titulo: 'Avençados' }];

  const grelha = <L extends { chave: string; nome: string; tipo?: string | null }>(linhas: L[], valores: Record<string, string>, definir: (k: string, v: string) => void) => (
    <Table<L>
      rowKey="chave"
      size="small"
      pagination={false}
      dataSource={linhas}
      scroll={scrollTabela()}
      columns={[
        { title: 'Rubrica', dataIndex: 'nome', fixed: telemovel ? undefined : 'left', render: (v: string, l) => <Space wrap>{v}{l.tipo && <Tag>{l.tipo.toLowerCase()}</Tag>}</Space> },
        ...colunas.map((c) => ({
          title: c.titulo,
          key: String(c.chave),
          render: (_: unknown, l: L) => {
            const k = chaveCelula(l.chave, c.chave);
            return <SeletorConta value={valores[k] || undefined} onChange={(v) => definir(k, v ?? '')} allowClear disabled={!editar} style={{ width: 220, maxWidth: '100%' }} placeholder="—" />;
          },
        })),
      ]}
    />
  );

  return (
    <>
      <CabecalhoPagina
        titulo="Mapeamento contabilístico de salários"
        subtitulo="Contas usadas na contabilização do processamento salarial, por tipo de organização"
        impressaoDesactivada={!d}
        impressao={() => {
          if (!d) return null;
          const tabela = (legenda: string, linhas: { chave: string; nome: string }[], valores: Record<string, string>) =>
            tabelaHtml({
              legenda,
              linhas,
              colunas: [{ titulo: 'Rubrica', valor: (l) => l.nome, quebrar: true }, ...colunas.map((c) => ({ titulo: c.titulo, valor: (l: { chave: string }) => valores[chaveCelula(l.chave, c.chave)] || '—' }))],
            });
          return {
            titulo: 'Mapeamento contabilístico de salários',
            conteudo:
              tabela('Rubricas salariais', d.infotipos.map((i) => ({ chave: String(i.id), nome: `${i.nome}${i.tipo ? ` (${i.tipo.toLowerCase()})` : ''}` })), editR) +
              tabela('Contas de sistema', d.codigos_sistema.map((c) => ({ chave: c, nome: NOMES_SISTEMA[c] ?? c })), editS),
          };
        }}
        accoes={
          editar && (
            <Space wrap>
              <Button disabled={!mudR.length && !mudS.length} onClick={() => { setEditR(originalR); setEditS(originalS); }}>
                Repor
              </Button>
              <Button type="primary" disabled={!mudR.length && !mudS.length} loading={gravar.isPending} onClick={() => gravar.mutate()}>
                Gravar {mudR.length + mudS.length > 0 ? `(${mudR.length + mudS.length} alteração(ões))` : ''}
              </Button>
            </Space>
          )
        }
      />
      {!d ? (
        <Card loading={consulta.isLoading}>{consulta.isError && <Alert type="error" message="Não foi possível obter os mapeamentos." />}</Card>
      ) : (
        <>
          <Typography.Paragraph type="secondary">Só são aceites contas de movimento. Uma célula vazia remove o mapeamento.</Typography.Paragraph>
          <Tabs
            items={[
              {
                key: 'rubricas',
                label: 'Rubricas salariais',
                children: grelha(d.infotipos.map((i) => ({ chave: String(i.id), nome: i.nome, tipo: i.tipo })), editR, (k, v) => setEditR((e) => ({ ...e, [k]: v }))),
              },
              {
                key: 'sistema',
                label: 'Contas de sistema',
                children: grelha(d.codigos_sistema.map((c) => ({ chave: c, nome: NOMES_SISTEMA[c] ?? c })), editS, (k, v) => setEditS((e) => ({ ...e, [k]: v }))),
              },
            ]}
          />
        </>
      )}
    </>
  );
}
