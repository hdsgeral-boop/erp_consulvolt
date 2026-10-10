import { Button, Typography } from 'antd';
import { useQuery } from '@tanstack/react-query';
import { useEffect, useMemo, useRef, useState, type Key } from 'react';
import { obterPagina } from '@/api/cliente';
import { TabelaApi, type ColunaApi } from '@/componentes/TabelaApi';
import { IconeLegado, TabelaComModos, type AccaoLinha } from '@/componentes/vistas';
import { useSessao } from '@/sessao/SessaoContexto';
import { notificarErro } from '@/utilitarios/erros';
import { formatarDataHora, formatarKz } from '@/utilitarios/formatacao';
import { ModalMotivo, useAccao } from '@/componentes/Accoes';
import { DetalheSessao } from './comum/DetalheSessao';
import { accoesIntegracao } from './comum/regras';
import type { SessaoPOS } from './comum/tipos';
import { linhasMeios } from './contas/formatos';
import { ModalPreVisualizacaoIntegracao } from './contas/PreVisualizacaoIntegracao';
import { Seccao } from './contas/Seccao';

/** Colunas comuns às duas listas (Z, Terminal · operador, Fecho, Doc., Total, Meios — pos_prestacao.js:233-240). */
function colunasSessao(): ColunaApi<SessaoPOS>[] {
  return [
    { title: 'Z', dataIndex: 'numero_z', render: (v, s) => <b>{v ?? s.codigo_sessao}</b>, valorImpressao: (s) => s.numero_z ?? s.codigo_sessao },
    {
      title: 'Terminal · operador',
      key: 'terminal',
      render: (_, s) => `${s.codigo_terminal} · ${s.nome_operador ?? '—'}`,
      valorFiltro: (s) => `${s.codigo_terminal} ${s.nome_terminal} ${s.nome_operador ?? ''}`,
    },
    { title: 'Fecho', dataIndex: 'fechado_em', render: (v) => formatarDataHora(v) },
    { title: 'Doc.', dataIndex: 'numero_vendas', align: 'right', render: (v) => v ?? 0 },
    { title: 'Total', dataIndex: 'total_vendas', align: 'right', render: (v) => <b>{formatarKz(v)}</b>, total: true },
    {
      title: 'Meios',
      key: 'meios',
      render: (_, s) => (
        <span style={{ fontSize: '0.75rem' }}>
          {linhasMeios(s.totais_por_metodo).map((m) => (
            <div key={m}>{m}</div>
          ))}
        </span>
      ),
      valorFiltro: (s) => linhasMeios(s.totais_por_metodo).join(' '),
      valorImpressao: (s) => linhasMeios(s.totais_por_metodo).join('; '),
    },
  ];
}

/**
 * POS › Integração contabilística (ecrã pos_integracao) como no sistema anterior (renderIntegracao, pos_prestacao.js:225-260):
 * «Integração contabilística das sessões do POS» — sessões fechadas por integrar, com caixa de selecção e «Pré-visualizar e
 * contabilizar» (mostra os lançamentos antes de gravar) — e «Sessões contabilizadas» (meios, lançamentos, desfazer = estorno).
 */
export default function Integracao() {
  const { pode } = useSessao();
  const [chaves, setChaves] = useState<Key[]>([]);
  const [previsualizar, setPrevisualizar] = useState<number[]>([]);
  const [detalhe, setDetalhe] = useState<number | null>(null);
  const [estornar, setEstornar] = useState<SessaoPOS | null>(null);
  const accao = useAccao({ invalidar: [['pos']], aoSucesso: () => setEstornar(null) });
  const podeIntegrar = pode('pos_integrar');

  const pendentes = useQuery({
    queryKey: ['pos', 'sessoes', 'integracao', 'pendentes'],
    queryFn: () => obterPagina<SessaoPOS>('/pos/sessoes', { estado: 'FECHADA', estado_contabilizacao: 'PENDENTE', por_pagina: 500 }),
  });
  useEffect(() => {
    if (pendentes.error) notificarErro(pendentes.error, 'Erro ao carregar as sessões por integrar');
  }, [pendentes.error]);
  const linhas = useMemo(() => pendentes.data?.itens ?? [], [pendentes.data]);

  // como no antigo, as sessões por integrar vêm todas marcadas; ao recarregar mantém-se a escolha das que continuam
  const conhecidas = useRef<Set<number>>(new Set());
  useEffect(() => {
    setChaves((actuais) => {
      const ids = linhas.map((s) => s.id);
      const novas = ids.filter((id) => !conhecidas.current.has(id));
      conhecidas.current = new Set(ids);
      return [...actuais.filter((k) => ids.includes(Number(k))), ...novas];
    });
  }, [linhas]);

  const accoesFeitas = (s: SessaoPOS): AccaoLinha[] => [
    { icone: 'eye', titulo: 'Ver sessão', onClick: () => setDetalhe(s.id) },
    { icone: 'undo', titulo: 'Descontabilizar', variante: 'perigo', oculto: !accoesIntegracao(pode, s).descontabilizar, onClick: () => setEstornar(s) },
  ];

  return (
    <>
      <Seccao
        titulo="Integração contabilística das sessões do POS"
        icone="book"
        cor="#2563eb"
        descricao="Débito das contas transitórias (numerário e TPA em bolo; transferências por documento) e crédito dos proveitos e do IVA. Um lançamento por sessão e por dia."
        extra={
          podeIntegrar && (
            <Button type="primary" icon={<IconeLegado icone="eye" />} disabled={!chaves.length} onClick={() => setPrevisualizar(chaves.map(Number))}>
              Pré-visualizar e contabilizar
            </Button>
          )
        }
      >
        <TabelaComModos<SessaoPOS>
          idVista="integracao-pendentes"
          modos={false}
          rowKey="id"
          size="small"
          loading={pendentes.isFetching}
          dataSource={linhas}
          columns={colunasSessao()}
          impressao={{ titulo: 'Integração contabilística · sessões por contabilizar' }}
          accoes={(s) => [{ icone: 'eye', titulo: 'Ver sessão', onClick: () => setDetalhe(s.id) }]}
          seleccao={
            podeIntegrar
              ? { chaves, aoMudar: (k) => setChaves(k), ajuda: 'Marque as sessões a contabilizar e clique em «Pré-visualizar e contabilizar».' }
              : undefined
          }
          vazio={{ icone: 'check-circle', cor: '#059669', titulo: 'Sem sessões fechadas por contabilizar.' }}
        />
      </Seccao>
      <Seccao titulo="Sessões contabilizadas" icone="check-circle" cor="#059669" nivel={4}>
        <TabelaApi<SessaoPOS>
          idVista="integracao-feitas"
          modos={false}
          url="/pos/sessoes"
          chaveConsulta={['pos', 'sessoes', 'integracao', 'contabilizadas']}
          filtros={{ estado: 'FECHADA', estado_contabilizacao: 'CONTABILIZADA' }}
          size="small"
          impressao={{ titulo: 'Integração contabilística · sessões contabilizadas' }}
          columns={[
            ...colunasSessao(),
            {
              title: 'Lançamentos',
              dataIndex: 'lans_contabilizacao',
              render: (v: string[] | null, s) => (
                <span style={{ fontSize: '0.75rem' }}>
                  {(v ?? []).map((l) => (
                    <div key={l}>{l}</div>
                  ))}
                  <Typography.Text type="secondary" style={{ fontSize: '0.72rem' }}>
                    {formatarDataHora(s.contabilizado_em)} · {s.contabilizado_por ?? ''}
                  </Typography.Text>
                </span>
              ),
              valorFiltro: (s) => (s.lans_contabilizacao ?? []).join(' '),
              valorImpressao: (s) => `${(s.lans_contabilizacao ?? []).join(', ')} · ${formatarDataHora(s.contabilizado_em)} · ${s.contabilizado_por ?? ''}`,
            },
          ]}
          accoes={accoesFeitas}
          vazio={{ icone: 'book', titulo: 'Ainda sem sessões contabilizadas.' }}
        />
      </Seccao>
      <ModalPreVisualizacaoIntegracao ids={previsualizar} aoFechar={() => setPrevisualizar([])} aoConcluir={() => setPrevisualizar([])} />
      <DetalheSessao id={detalhe} aoFechar={() => setDetalhe(null)} />
      <ModalMotivo
        aberto={!!estornar}
        titulo={`Descontabilizar ${estornar?.numero_z ?? ''}`}
        textoOk="Descontabilizar"
        aviso={`Os lançamentos ${(estornar?.lans_contabilizacao ?? []).join(', ')} são estornados (o original mantém-se no diário). Fica bloqueado se houver prestação de contas registada ou deliberação com lançamento.`}
        carregando={accao.isPending}
        aoFechar={() => setEstornar(null)}
        aoConfirmar={(motivo) => estornar && accao.mutate({ url: `/pos/sessoes/${estornar.id}/descontabilizar`, dados: { motivo } })}
      />
    </>
  );
}
