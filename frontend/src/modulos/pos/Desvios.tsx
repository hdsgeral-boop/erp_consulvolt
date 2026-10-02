import { Alert, Button, Descriptions, Form, Input, Modal, Radio, Select, Space, Tooltip, Typography } from 'antd';
import { AuditOutlined, EyeOutlined, StopOutlined } from '@ant-design/icons';
import { useEffect, useState } from 'react';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { TabelaApi } from '@/componentes/TabelaApi';
import { BarraFiltros, larguraModal } from '@/componentes/responsivo';
import { useSessao } from '@/sessao/SessaoContexto';
import { formatarDataHora, formatarKz } from '@/utilitarios/formatacao';
import { ModalMotivo, useAccao } from '@/componentes/Accoes';
import { useDefinicoesPOS } from './comum/dados';
import { DetalheSessao, ValorDesvio } from './comum/DetalheSessao';
import { EstadoPOS, opcoesEstadoPOS, rotuloEstadoPOS } from './comum/estados';
import { SeletorTerminal, useFiltroTerminal } from './comum/Filtros';
import { accoesDesvio, DECISOES, decisoesPermitidas } from './comum/regras';
import type { SessaoPOS } from './comum/tipos';

/**
 * POS › Desvios de caixa (ecrã pos_desvios): desvios acima da tolerância por deliberar (sobra, falta como custo, falta do
 * operador ou sem efeito) e anulação de deliberações manuais por estorno. Quem operou a sessão não delibera (ADR-047).
 */
export default function Desvios() {
  const { pode, utilizador } = useSessao();
  const definicoes = useDefinicoesPOS();
  const [terminal, setTerminal] = useState<number>();
  const [estado, setEstado] = useState<string | undefined>('PENDENTE');
  const filtroTerminal = useFiltroTerminal(terminal);
  const [detalhe, setDetalhe] = useState<number | null>(null);
  const [deliberar, setDeliberar] = useState<SessaoPOS | null>(null);
  const [anular, setAnular] = useState<SessaoPOS | null>(null);
  const accao = useAccao({
    invalidar: [['pos']],
    aoSucesso: () => {
      setDeliberar(null);
      setAnular(null);
    },
  });

  const botoes = (s: SessaoPOS) => {
    const a = accoesDesvio(pode, s, utilizador?.id);
    return (
      <Space size={4}>
        <Tooltip title="Ver sessão">
          <Button size="small" icon={<EyeOutlined />} onClick={() => setDetalhe(s.id)} />
        </Tooltip>
        {a.deliberar.visivel && (
          <Tooltip title={a.deliberar.bloqueio}>
            <Button size="small" type="primary" icon={<AuditOutlined />} disabled={!!a.deliberar.bloqueio} onClick={() => setDeliberar(s)}>
              Deliberar
            </Button>
          </Tooltip>
        )}
        {a.anular.visivel && (
          <Button size="small" danger icon={<StopOutlined />} onClick={() => setAnular(s)}>
            Anular deliberação
          </Button>
        )}
      </Space>
    );
  };

  return (
    <>
      <CabecalhoPagina titulo="Desvios de caixa" subtitulo="Diferenças entre o numerário contado e o esperado no fecho Z" />
      {definicoes.data && (
        <Descriptions size="small" column={{ xs: 1, md: 3, xl: 5 }} style={{ marginBottom: 12 }}>
          <Descriptions.Item label="Tolerância">{formatarKz(definicoes.data.tolerancia_desvio)} Kz</Descriptions.Item>
          <Descriptions.Item label="Sobras">{definicoes.data.conta_sobra ?? '—'}</Descriptions.Item>
          <Descriptions.Item label="Quebras">{definicoes.data.conta_quebra ?? '—'}</Descriptions.Item>
          <Descriptions.Item label="Operador">{definicoes.data.conta_operador ?? '—'}</Descriptions.Item>
          <Descriptions.Item label="Diário">{definicoes.data.codigo_diario}</Descriptions.Item>
        </Descriptions>
      )}
      <BarraFiltros>
        <SeletorTerminal value={terminal} onChange={setTerminal} />
        <Select allowClear placeholder="Desvio" style={{ width: 200 }} value={estado} onChange={setEstado} options={opcoesEstadoPOS(['PENDENTE', 'DELIBERADO', 'SEM_DESVIO'])} />
      </BarraFiltros>
      <TabelaApi<SessaoPOS>
        url="/pos/sessoes"
        chaveConsulta={['pos', 'sessoes', 'desvios']}
        filtros={{ estado: 'FECHADA', terminal_pos_id: terminal, estado_desvio: estado }}
        impressao={{
          titulo: 'Desvios de caixa',
          filtros: [filtroTerminal, `Desvio: ${estado ? rotuloEstadoPOS(estado) : 'Todos'}`, definicoes.data ? `Tolerância: ${formatarKz(definicoes.data.tolerancia_desvio)} Kz` : null],
        }}
        columns={[
          { title: 'Z', dataIndex: 'numero_z' },
          { title: 'Terminal', render: (_, s) => `${s.codigo_terminal} — ${s.nome_terminal}` },
          { title: 'Operador', dataIndex: 'nome_operador', responsive: ['md'] },
          { title: 'Fecho', dataIndex: 'fechado_em', render: (v) => formatarDataHora(v) },
          { title: 'Esperado', dataIndex: 'numerario_esperado', align: 'right', render: (v) => formatarKz(v), responsive: ['md'] },
          { title: 'Contado', dataIndex: 'numerario_contado', align: 'right', render: (v) => formatarKz(v), responsive: ['md'] },
          { title: 'Desvio', dataIndex: 'desvio', align: 'right', render: (v) => <ValorDesvio valor={v} /> },
          { title: 'Estado', dataIndex: 'estado_desvio', render: (v) => <EstadoPOS estado={v} /> },
          { title: 'Decisão', render: (_, s) => (s.deliberacao ? `${DECISOES[s.deliberacao.decisao]?.rotulo ?? s.deliberacao.decisao}${s.deliberacao.automatica ? ' (auto.)' : ''}` : '—') },
          { title: 'Integração', dataIndex: 'estado_contabilizacao', render: (v) => <EstadoPOS estado={v} />, responsive: ['lg'] },
          { title: '', key: 'accoes', fixed: 'right', exportar: false, render: (_, s) => botoes(s) },
        ]}
      />
      <DetalheSessao id={detalhe} aoFechar={() => setDetalhe(null)} />
      <ModalDeliberar sessao={deliberar} carregando={accao.isPending} aoFechar={() => setDeliberar(null)} aoConfirmar={(dados) => deliberar && accao.mutate({ url: `/pos/sessoes/${deliberar.id}/deliberacao`, dados })} />
      <ModalMotivo
        aberto={!!anular}
        titulo={`Anular a deliberação de ${anular?.numero_z ?? ''}`}
        textoOk="Anular deliberação"
        aviso="O lançamento do desvio é estornado e o desvio volta a ficar por deliberar. Fica bloqueado se o numerário já tiver sido prestado."
        carregando={accao.isPending}
        aoFechar={() => setAnular(null)}
        aoConfirmar={(motivo) => anular && accao.mutate({ url: `/pos/sessoes/${anular.id}/deliberacao/anular`, dados: { motivo } })}
      />
    </>
  );
}

function ModalDeliberar({
  sessao,
  carregando,
  aoFechar,
  aoConfirmar,
}: {
  sessao: SessaoPOS | null;
  carregando: boolean;
  aoFechar: () => void;
  aoConfirmar: (d: { decisao: string; nota?: string }) => void;
}) {
  const [form] = Form.useForm<{ decisao: string; nota?: string }>();
  useEffect(() => {
    if (sessao) form.resetFields();
  }, [sessao, form]);
  const opcoes = sessao ? decisoesPermitidas(sessao) : [];
  const sobra = Number(sessao?.desvio ?? 0) > 0;
  return (
    <Modal open={!!sessao} title={`Deliberar o desvio de ${sessao?.numero_z ?? ''}`} okText="Deliberar" cancelText="Cancelar" confirmLoading={carregando} onCancel={aoFechar} onOk={() => form.submit()} width={larguraModal(560)} destroyOnHidden>
      {sessao && (
        <>
          <Alert
            type={sobra ? 'success' : 'warning'}
            showIcon
            style={{ marginBottom: 12 }}
            message={`${sobra ? 'Sobra' : 'Falta'} de ${formatarKz(Math.abs(Number(sessao.desvio ?? 0)))} Kz`}
            description={sessao.justificacao ? `Justificação do operador: ${sessao.justificacao}` : undefined}
          />
          <Form form={form} layout="vertical" onFinish={(v) => aoConfirmar({ decisao: v.decisao, nota: v.nota?.trim() || undefined })}>
            <Form.Item name="decisao" label="Decisão" rules={[{ required: true, message: 'Escolha a decisão.' }]}>
              <Radio.Group>
                <Space direction="vertical">
                  {opcoes.map((o) => (
                    <Radio key={o.valor} value={o.valor} disabled={!!o.desactivada}>
                      <b>{DECISOES[o.valor].rotulo}</b> <Typography.Text type="secondary">— {o.desactivada ?? DECISOES[o.valor].descricao}</Typography.Text>
                    </Radio>
                  ))}
                </Space>
              </Radio.Group>
            </Form.Item>
            <Form.Item name="nota" label="Nota">
              <Input.TextArea rows={3} maxLength={1000} showCount />
            </Form.Item>
          </Form>
        </>
      )}
    </Modal>
  );
}
