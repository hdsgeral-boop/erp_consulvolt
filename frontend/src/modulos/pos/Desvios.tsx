import { Alert, Form, Input, Modal, Radio, Space, Typography } from 'antd';
import { useEffect, useState } from 'react';
import { TabelaApi } from '@/componentes/TabelaApi';
import { larguraModal } from '@/componentes/responsivo';
import { IconeLegado, type AccaoLinha } from '@/componentes/vistas';
import { useSessao } from '@/sessao/SessaoContexto';
import { formatarDataHora, formatarKz } from '@/utilitarios/formatacao';
import { ModalMotivo, useAccao } from '@/componentes/Accoes';
import { useDefinicoesPOS } from './comum/dados';
import { DetalheSessao } from './comum/DetalheSessao';
import { accoesDesvio, DECISOES, decisoesPermitidas } from './comum/regras';
import type { SessaoPOS } from './comum/tipos';
import { corDesvio, rotuloDecisao, textoDesvio } from './contas/formatos';
import { Seccao } from './contas/Seccao';
import { useCoresPos } from './comum/cores';

/**
 * POS › Desvios de caixa (ecrã pos_desvios) como no sistema anterior (renderDesvios, pos_prestacao.js:363-394): «Desvios de
 * caixa por deliberar» (com a justificação do operador) e o histórico «Deliberações» (decisão, fundamentação, lançamento,
 * deliberado por, desfazer = anular a deliberação por estorno). Quem operou a sessão não delibera (ADR-047).
 */
export default function Desvios() {
  const { pode, utilizador } = useSessao();
  const definicoes = useDefinicoesPOS();
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
  const d = definicoes.data;
  const conta = (c: string | null | undefined) => <strong>{c || 'conta por definir'}</strong>;

  const accoesPendente = (s: SessaoPOS): AccaoLinha[] => {
    const a = accoesDesvio(pode, s, utilizador?.id);
    return [
      { icone: 'eye', titulo: 'Ver sessão', onClick: () => setDetalhe(s.id) },
      {
        icone: 'gavel',
        titulo: 'Deliberar',
        texto: 'Deliberar',
        variante: 'primario',
        cheio: true,
        oculto: !a.deliberar.visivel,
        desactivado: a.deliberar.bloqueio ?? false,
        onClick: () => setDeliberar(s),
      },
    ];
  };
  const accoesDeliberada = (s: SessaoPOS): AccaoLinha[] => [
    { icone: 'eye', titulo: 'Ver sessão', onClick: () => setDetalhe(s.id) },
    { icone: 'undo', titulo: 'Anular deliberação', variante: 'perigo', oculto: !accoesDesvio(pode, s, utilizador?.id).anular.visivel, onClick: () => setAnular(s) },
  ];

  return (
    <>
      <Seccao
        titulo="Desvios de caixa por deliberar"
        icone="balance-scale"
        cor="#b45309"
        descricao={
          <>
            O desvio só é lançado depois de deliberado: sobras para {conta(d?.conta_sobra)}, faltas para {conta(d?.conta_quebra)} ou imputadas ao operador em{' '}
            {conta(d?.conta_operador)} (Terminais POS → Definições de caixa).
            {d && ` Tolerância: ${formatarKz(d.tolerancia_desvio)} Kz (abaixo dela a regularização é automática).`}
          </>
        }
      >
        <TabelaApi<SessaoPOS>
          idVista="desvios-pendentes"
          modos={false}
          url="/pos/sessoes"
          chaveConsulta={['pos', 'sessoes', 'desvios', 'pendentes']}
          filtros={{ estado: 'FECHADA', estado_desvio: 'PENDENTE' }}
          size="small"
          impressao={{ titulo: 'Desvios de caixa por deliberar' }}
          columns={[
            { title: 'Z', dataIndex: 'numero_z', render: (v) => <b>{v}</b> },
            { title: 'Terminal · operador', key: 'terminal', render: (_, s) => `${s.codigo_terminal} · ${s.nome_operador ?? '—'}` },
            { title: 'Fecho', dataIndex: 'fechado_em', render: (v) => formatarDataHora(v) },
            { title: 'Esperado', dataIndex: 'numerario_esperado', align: 'right', render: (v) => formatarKz(v) },
            { title: 'Contado', dataIndex: 'numerario_contado', align: 'right', render: (v) => formatarKz(v) },
            {
              title: 'Desvio',
              dataIndex: 'desvio',
              align: 'right',
              render: (v) => <span style={{ fontWeight: 800, color: corDesvio(v) }}>{textoDesvio(v)}</span>,
              valorImpressao: (s) => textoDesvio(s.desvio),
              total: true,
            },
            { title: 'Justificação do operador', dataIndex: 'justificacao', render: (v) => <span style={{ display: 'inline-block', maxWidth: 260 }}>{v ?? ''}</span>, valorImpressao: (s) => s.justificacao ?? '' },
            {
              title: 'Contabilização',
              dataIndex: 'estado_contabilizacao',
              render: (v) => (v === 'CONTABILIZADA' ? <span style={{ color: '#059669' }}>Contabilizada</span> : <span style={{ color: '#b45309' }}>Por contabilizar</span>),
              valorImpressao: (s) => (s.estado_contabilizacao === 'CONTABILIZADA' ? 'Contabilizada' : 'Por contabilizar'),
            },
          ]}
          accoes={accoesPendente}
          vazio={{ icone: 'check-circle', cor: '#059669', titulo: 'Sem desvios por deliberar.' }}
        />
      </Seccao>
      <Seccao titulo="Deliberações" icone="history" cor="#334155" nivel={4}>
        <TabelaApi<SessaoPOS>
          idVista="desvios-deliberacoes"
          modos={false}
          url="/pos/sessoes"
          chaveConsulta={['pos', 'sessoes', 'desvios', 'deliberacoes']}
          filtros={{ estado: 'FECHADA', estado_desvio: 'DELIBERADO' }}
          size="small"
          impressao={{ titulo: 'Deliberações dos desvios de caixa' }}
          columns={[
            { title: 'Z', dataIndex: 'numero_z', render: (v) => <b>{v}</b> },
            { title: 'Operador', dataIndex: 'nome_operador' },
            { title: 'Desvio', dataIndex: 'desvio', align: 'right', render: (v) => formatarKz(v), total: true },
            {
              title: 'Decisão',
              key: 'decisao',
              render: (_, s) => (
                <>
                  {rotuloDecisao(s.deliberacao)}
                  {s.deliberacao?.conta && (
                    <div>
                      <small>{s.deliberacao.conta}</small>
                    </div>
                  )}
                </>
              ),
              valorFiltro: (s) => rotuloDecisao(s.deliberacao),
              valorImpressao: (s) => `${rotuloDecisao(s.deliberacao)}${s.deliberacao?.conta ? ` (${s.deliberacao.conta})` : ''}`,
            },
            { title: 'Fundamentação', key: 'nota', render: (_, s) => <span style={{ display: 'inline-block', maxWidth: 260 }}>{s.deliberacao?.nota ?? ''}</span>, valorFiltro: (s) => s.deliberacao?.nota ?? '', valorImpressao: (s) => s.deliberacao?.nota ?? '' },
            { title: 'Lançamento', key: 'lan', render: (_, s) => s.deliberacao?.numero_lan || '—', valorFiltro: (s) => s.deliberacao?.numero_lan ?? '', valorImpressao: (s) => s.deliberacao?.numero_lan || '—' },
            {
              title: 'Deliberado por',
              key: 'por',
              render: (_, s) => (
                <>
                  {s.deliberacao?.por ?? (s.deliberacao?.automatica ? 'Automática' : '')}
                  <div>
                    <small>{formatarDataHora(s.deliberacao?.em ?? s.deliberacao?.data ?? null)}</small>
                  </div>
                </>
              ),
              valorFiltro: (s) => s.deliberacao?.por ?? '',
              valorImpressao: (s) => `${s.deliberacao?.por ?? ''} ${formatarDataHora(s.deliberacao?.em ?? s.deliberacao?.data ?? null)}`.trim(),
            },
          ]}
          accoes={accoesDeliberada}
          vazio={{ icone: 'history', titulo: 'Sem deliberações.' }}
        />
      </Seccao>
      <DetalheSessao id={detalhe} aoFechar={() => setDetalhe(null)} />
      <ModalDeliberar
        sessao={deliberar}
        carregando={accao.isPending}
        aoFechar={() => setDeliberar(null)}
        aoConfirmar={(dados) => deliberar && accao.mutate({ url: `/pos/sessoes/${deliberar.id}/deliberacao`, dados })}
      />
      <ModalMotivo
        aberto={!!anular}
        titulo={`Anular a deliberação do desvio de ${anular?.numero_z ?? ''}`}
        textoOk="Anular deliberação"
        aviso={`${anular?.deliberacao?.numero_lan ? `O lançamento ${anular.deliberacao.numero_lan} é estornado e o` : 'O'} desvio volta a ficar por deliberar. Fica bloqueado se o numerário já tiver sido transferido para a Folha de Caixa.`}
        carregando={accao.isPending}
        aoFechar={() => setAnular(null)}
        aoConfirmar={(motivo) => anular && accao.mutate({ url: `/pos/sessoes/${anular.id}/deliberacao/anular`, dados: { motivo } })}
      />
    </>
  );
}

/** Deliberar desvio (posDeliberarDesvio, pos_prestacao.js:396-424): esperado, contado, sobra/falta, justificação e decisão. */
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
  const opcoes = sessao ? decisoesPermitidas(sessao) : [];
  useEffect(() => {
    if (sessao) {
      form.resetFields();
      form.setFieldsValue({ decisao: opcoes.find((o) => !o.desactivada)?.valor });
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [sessao, form]);
  const desvio = Number(sessao?.desvio ?? 0);
  const sobra = desvio > 0;
  const cores = useCoresPos();
  const caixa = (rotulo: string, valor: string, fundo = cores.fundoAlt) => (
    <div style={{ background: fundo, border: `1px solid ${cores.borda}`, borderRadius: 4, padding: '0.5rem' }}>
      {rotulo}
      <br />
      <strong>{valor}</strong>
    </div>
  );
  return (
    <Modal
      open={!!sessao}
      title={
        <Space>
          <IconeLegado icone="gavel" /> Deliberar desvio · {sessao?.numero_z ?? ''}
        </Space>
      }
      okText="Registar deliberação"
      cancelText="Cancelar"
      confirmLoading={carregando}
      onCancel={aoFechar}
      onOk={() => form.submit()}
      width={larguraModal(620)}
      destroyOnHidden
    >
      {sessao && (
        <>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(140px, 1fr))', gap: '0.5rem', fontSize: '0.85rem', marginBottom: '0.8rem' }}>
            {caixa('Esperado', formatarKz(sessao.numerario_esperado))}
            {caixa('Contado', formatarKz(sessao.numerario_contado))}
            {caixa(sobra ? 'Sobra' : 'Falta', formatarKz(Math.abs(desvio)), sobra ? '#ecfdf5' : '#fef2f2')}
          </div>
          <div style={{ fontSize: '0.82rem', color: '#475569', marginBottom: '0.6rem' }}>
            <strong>Justificação do operador:</strong> {sessao.justificacao || '—'}
          </div>
          <Form form={form} layout="vertical" onFinish={(v) => aoConfirmar({ decisao: v.decisao, nota: v.nota?.trim() || undefined })}>
            <Form.Item name="decisao" rules={[{ required: true, message: 'Escolha a decisão.' }]}>
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
            <Form.Item
              name="nota"
              label="Fundamentação da deliberação"
              rules={[{ required: true, whitespace: true, min: 5, message: 'Escreva a fundamentação da deliberação.' }]}
            >
              <Input.TextArea rows={2} maxLength={1000} showCount placeholder="Decisão tomada e por quem foi autorizada" />
            </Form.Item>
          </Form>
          {sessao.estado_contabilizacao !== 'CONTABILIZADA' && sessao.estado_contabilizacao !== 'SEM_MOVIMENTO' && (
            <Alert
              type="warning"
              showIcon
              message="A sessão ainda não está contabilizada: só é possível deliberar «sem efeito». Para lançar a sobra/falta, contabilize primeiro a sessão."
            />
          )}
        </>
      )}
    </Modal>
  );
}
