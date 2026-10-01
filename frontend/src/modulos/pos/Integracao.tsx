import { Button, Flex, Modal, Select, Space, Tooltip } from 'antd';
import { EyeOutlined, RollbackOutlined, SendOutlined } from '@ant-design/icons';
import { useState } from 'react';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { TabelaApi } from '@/componentes/TabelaApi';
import { useSessao } from '@/sessao/SessaoContexto';
import { formatarDataHora, formatarKz } from '@/utilitarios/formatacao';
import { ModalMotivo, useAccao } from '@/componentes/Accoes';
import { DetalheSessao, ValorDesvio } from './comum/DetalheSessao';
import { EstadoPOS, opcoesEstadoPOS } from './comum/estados';
import { SeletorTerminal } from './comum/Filtros';
import { accoesIntegracao } from './comum/regras';
import type { SessaoPOS } from './comum/tipos';

/**
 * POS › Integração das sessões (ecrã pos_integracao): sessões fechadas por integrar na contabilidade (um lançamento por
 * data no diário do POS, com CMV) e descontabilização por estorno (ADR-047).
 */
export default function Integracao() {
  const { pode } = useSessao();
  const [terminal, setTerminal] = useState<number>();
  const [estado, setEstado] = useState<string | undefined>('PENDENTE');
  const [detalhe, setDetalhe] = useState<number | null>(null);
  const [estornar, setEstornar] = useState<SessaoPOS | null>(null);
  const accao = useAccao({ invalidar: [['pos']], aoSucesso: () => setEstornar(null) });

  const integrar = (s: SessaoPOS) =>
    Modal.confirm({
      title: `Integrar a sessão ${s.numero_z ?? s.codigo_sessao}?`,
      content: 'Gera o lançamento das vendas (transitórias por meio, proveitos, IVA e CMV) e, se houver, o do desvio deliberado.',
      okText: 'Integrar',
      cancelText: 'Cancelar',
      onOk: () => accao.mutateAsync({ url: `/pos/sessoes/${s.id}/contabilizar` }).catch(() => undefined),
    });

  const botoes = (s: SessaoPOS) => {
    const a = accoesIntegracao(pode, s);
    return (
      <Space size={4}>
        <Tooltip title="Ver sessão">
          <Button size="small" icon={<EyeOutlined />} onClick={() => setDetalhe(s.id)} />
        </Tooltip>
        {a.contabilizar && (
          <Button size="small" type="primary" icon={<SendOutlined />} onClick={() => integrar(s)}>
            Integrar
          </Button>
        )}
        {a.descontabilizar && (
          <Button size="small" danger icon={<RollbackOutlined />} onClick={() => setEstornar(s)}>
            Descontabilizar
          </Button>
        )}
      </Space>
    );
  };

  return (
    <>
      <CabecalhoPagina titulo="Integração das sessões POS" subtitulo="Contabilização das sessões fechadas (fecho Z)" />
      <Flex gap={8} wrap style={{ marginBottom: 12 }}>
        <SeletorTerminal value={terminal} onChange={setTerminal} />
        <Select
          allowClear
          placeholder="Integração"
          style={{ width: 200 }}
          value={estado}
          onChange={setEstado}
          options={opcoesEstadoPOS(['PENDENTE', 'CONTABILIZADA', 'SEM_MOVIMENTO'])}
        />
      </Flex>
      <TabelaApi<SessaoPOS>
        url="/pos/sessoes"
        chaveConsulta={['pos', 'sessoes', 'integracao']}
        filtros={{ estado: 'FECHADA', terminal_pos_id: terminal, estado_contabilizacao: estado }}
        columns={[
          { title: 'Z', dataIndex: 'numero_z', render: (v, s) => v ?? s.codigo_sessao },
          { title: 'Terminal', render: (_, s) => `${s.codigo_terminal} — ${s.nome_terminal}` },
          { title: 'Operador', dataIndex: 'nome_operador' },
          { title: 'Fecho', dataIndex: 'fechado_em', render: (v) => formatarDataHora(v) },
          { title: 'Vendas', dataIndex: 'numero_vendas', align: 'right' },
          { title: 'Total', dataIndex: 'total_vendas', align: 'right', render: (v) => formatarKz(v) },
          { title: 'Desvio', dataIndex: 'desvio', align: 'right', render: (v) => <ValorDesvio valor={v} /> },
          { title: 'Integração', dataIndex: 'estado_contabilizacao', render: (v) => <EstadoPOS estado={v} /> },
          { title: 'Lançamentos', dataIndex: 'lans_contabilizacao', render: (v: string[] | null) => v?.join(', ') || '—' },
          { title: 'Prestação', dataIndex: 'estado_liquidacao', render: (v) => <EstadoPOS estado={v} /> },
          { title: '', key: 'accoes', fixed: 'right', render: (_, s) => botoes(s) },
        ]}
      />
      <DetalheSessao id={detalhe} aoFechar={() => setDetalhe(null)} />
      <ModalMotivo
        aberto={!!estornar}
        titulo={`Descontabilizar ${estornar?.numero_z ?? ''}`}
        textoOk="Estornar integração"
        aviso="A integração é estornada (o lançamento original mantém-se no diário). Fica bloqueado se houver prestação de contas registada."
        carregando={accao.isPending}
        aoFechar={() => setEstornar(null)}
        aoConfirmar={(motivo) => estornar && accao.mutate({ url: `/pos/sessoes/${estornar.id}/descontabilizar`, dados: { motivo } })}
      />
    </>
  );
}
