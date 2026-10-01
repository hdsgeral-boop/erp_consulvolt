import { Alert, Badge, Button, Card, Col, Empty, Flex, Form, InputNumber, Result, Row, Skeleton, Space, Tag, Typography } from 'antd';
import { DesktopOutlined, FileTextOutlined, LockOutlined, SwapOutlined, UnlockOutlined } from '@ant-design/icons';
import { useEffect, useState } from 'react';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { useSessao } from '@/sessao/SessaoContexto';
import { formatarDataHora, formatarKz } from '@/utilitarios/formatacao';
import { useAccao } from '@/componentes/Accoes';
import { TIPOS_TERMINAL, useTerminais } from './comum/dados';
import { accoesFrente } from './comum/regras';
import type { SessaoResumo, Terminal } from './comum/tipos';
import { FechoZ } from './frente/FechoZ';
import { PainelVenda } from './frente/PainelVenda';
import { RelatorioXModal } from './frente/RelatorioXModal';

const chaveTerminal = (empresa: number | undefined) => `erp.pos.terminal.${empresa ?? 0}`;

function lerTerminalGuardado(empresa: number | undefined): number | null {
  try {
    const v = Number(window.localStorage.getItem(chaveTerminal(empresa)));
    return v > 0 ? v : null;
  } catch {
    return null;
  }
}

/**
 * POS › Frente de caixa (ecrã pos): escolher o terminal, abrir a sessão com fundo de maneio, vender, relatório X e fecho Z.
 * O terminal escolhido fica memorizado neste posto de trabalho.
 */
export default function FrenteCaixa() {
  const { pode, empresa } = useSessao();
  const terminais = useTerminais();
  const [terminalId, setTerminalId] = useState<number | null>(() => lerTerminalGuardado(empresa?.id));
  const [verX, setVerX] = useState(false);
  // sessão do fecho Z em curso: guardada à parte para o resultado do fecho (n.º Z, imprimir) não desaparecer quando a
  // lista de terminais é recarregada e a sessão deixa de estar aberta
  const [sessaoZ, setSessaoZ] = useState<number | null>(null);

  const escolher = (id: number | null) => {
    setTerminalId(id);
    try {
      if (id) window.localStorage.setItem(chaveTerminal(empresa?.id), String(id));
      else window.localStorage.removeItem(chaveTerminal(empresa?.id));
    } catch {
      /* armazenamento indisponível: a escolha vale só nesta página */
    }
  };

  const terminal = terminais.data?.find((t) => t.id === terminalId) ?? null;
  useEffect(() => {
    if (terminais.data && terminalId && !terminal) escolher(null);
  }, [terminais.data]);

  if (terminais.isLoading) return <Skeleton active />;
  if (terminais.isError) return <Result status="error" title="Não foi possível carregar os terminais POS." />;

  if (!terminal) return <EscolherTerminal terminais={terminais.data ?? []} aoEscolher={escolher} />;

  const accoes = accoesFrente(pode, terminal);
  const sessao = terminal.sessao_aberta;

  return (
    <>
      <CabecalhoPagina
        titulo={
          <Space>
            <DesktopOutlined /> {terminal.codigo} — {terminal.nome}
          </Space>
        }
        subtitulo={
          sessao ? (
            <Space size={4} wrap>
              <Badge status="processing" /> Sessão {sessao.codigo_sessao} · aberta por {sessao.nome_operador ?? '—'} em {formatarDataHora(sessao.aberto_em)}
            </Space>
          ) : (
            'Sem sessão aberta'
          )
        }
        accoes={
          <>
            <Button icon={<SwapOutlined />} onClick={() => escolher(null)}>
              Mudar terminal
            </Button>
            {accoes.relatorioX && (
              <Button icon={<FileTextOutlined />} onClick={() => setVerX(true)}>
                Relatório X
              </Button>
            )}
            {accoes.fecharZ && (
              <Button danger icon={<LockOutlined />} onClick={() => sessao && setSessaoZ(sessao.id)}>
                Fecho Z
              </Button>
            )}
          </>
        }
      />

      {(terminal.tipo === 'LAVANDARIA' || terminal.tipo === 'HOTELARIA') && sessao && (
        <Alert
          type="info"
          showIcon
          style={{ marginBottom: 12 }}
          message={`Terminal de ${TIPOS_TERMINAL[terminal.tipo].toLowerCase()}: as ordens, recebimentos, check-ins e check-outs fazem-se no ecrã próprio, nesta mesma sessão.`}
        />
      )}

      {!terminal.ativo ? (
        <Result status="warning" title="Terminal inactivo" subTitle="Active o terminal em Terminais POS antes de abrir uma sessão." />
      ) : sessao ? (
        accoes.vender ? (
          <PainelVenda key={sessao.id} terminal={terminal} sessaoId={sessao.id} />
        ) : (
          <Result status="info" title="Sessão aberta" subTitle="Não tem permissão para registar vendas neste terminal." />
        )
      ) : (
        <AbrirSessao terminal={terminal} podeAbrir={accoes.abrir} />
      )}

      {sessao && <RelatorioXModal sessaoId={sessao.id} aberto={verX} aoFechar={() => setVerX(false)} />}
      {sessaoZ !== null && <FechoZ sessaoId={sessaoZ} aberto aoFechar={() => setSessaoZ(null)} />}
    </>
  );
}

function EscolherTerminal({ terminais, aoEscolher }: { terminais: Terminal[]; aoEscolher: (id: number) => void }) {
  return (
    <>
      <CabecalhoPagina titulo="Frente de caixa" subtitulo="Escolha o terminal deste posto de trabalho" />
      {terminais.length === 0 ? (
        <Empty description="Não há terminais POS. Crie-os em Terminais POS." />
      ) : (
        <Row gutter={[16, 16]}>
          {terminais.map((t) => (
            <Col key={t.id} xs={24} sm={12} md={8} xl={6}>
              <Card hoverable={t.ativo} onClick={() => aoEscolher(t.id)} style={{ opacity: t.ativo ? 1 : 0.6, height: '100%' }}>
                <Flex vertical gap={6}>
                  <Flex justify="space-between" align="center">
                    <Typography.Title level={4} style={{ margin: 0 }}>
                      {t.codigo}
                    </Typography.Title>
                    <Tag>{TIPOS_TERMINAL[t.tipo] ?? t.tipo}</Tag>
                  </Flex>
                  <Typography.Text>{t.nome}</Typography.Text>
                  <EstadoTerminal sessao={t.sessao_aberta} ativo={t.ativo} />
                </Flex>
              </Card>
            </Col>
          ))}
        </Row>
      )}
    </>
  );
}

function EstadoTerminal({ sessao, ativo }: { sessao: SessaoResumo | null; ativo: boolean }) {
  if (!ativo) return <Tag color="default">Inactivo</Tag>;
  if (!sessao) return <Badge status="default" text="Sem sessão aberta" />;
  return <Badge status="processing" text={`Sessão aberta · ${sessao.nome_operador ?? '—'} · ${formatarDataHora(sessao.aberto_em)}`} />;
}

function AbrirSessao({ terminal, podeAbrir }: { terminal: Terminal; podeAbrir: boolean }) {
  const [form] = Form.useForm<{ fundo_maneio: number | null }>();
  const abrir = useAccao({ invalidar: [['pos']], tituloErro: 'Não foi possível abrir a sessão' });
  return (
    <Flex justify="center" style={{ marginTop: 24 }}>
      <Card style={{ width: 420 }} title={<Space><UnlockOutlined /> Abrir sessão de caixa</Space>}>
        {!podeAbrir ? (
          <Alert type="info" showIcon message="Não tem permissão para abrir sessões neste terminal." />
        ) : (
          <Form
            form={form}
            layout="vertical"
            initialValues={{ fundo_maneio: Number(terminal.fundo_maneio_padrao ?? 0) }}
            onFinish={(v) => abrir.mutate({ url: `/pos/terminais/${terminal.id}/sessoes`, dados: { fundo_maneio: v.fundo_maneio ?? 0 } })}
          >
            <Form.Item name="fundo_maneio" label="Fundo de maneio (numerário inicial na gaveta)" extra={`Padrão do terminal: ${formatarKz(terminal.fundo_maneio_padrao)} Kz`}>
              <InputNumber<number> size="large" min={0} precision={2} decimalSeparator="," style={{ width: '100%' }} addonAfter="Kz" autoFocus />
            </Form.Item>
            <Button type="primary" size="large" block htmlType="submit" loading={abrir.isPending}>
              Abrir sessão
            </Button>
          </Form>
        )}
      </Card>
    </Flex>
  );
}
