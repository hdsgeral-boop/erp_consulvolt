import { useEffect, useRef, useState } from 'react';
import { Alert, Button, Drawer, Empty, Flex, Input, Popconfirm, Progress, Space, Spin, Tabs, Tag, Typography, Upload, message, theme } from 'antd';
import type { UploadFile } from 'antd';
import { DeleteOutlined, HistoryOutlined, PaperClipOutlined, PlusOutlined, RobotOutlined, SendOutlined, SettingOutlined } from '@ant-design/icons';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useLocation } from 'react-router';
import { ErroApi } from '@/api/tipos';
import { larguraGaveta } from '@/componentes/responsivo';
import { useSessao } from '@/sessao/SessaoContexto';
import { notificarErro } from '@/utilitarios/erros';
import { Markdown } from './Markdown';
import { AccaoProposta } from './assistente/AccaoProposta';
import {
  chavesIA, eliminarConversa, enviarMensagem, guardarRegras, listarConversas, listarMensagens, obterEstado, obterRegras, renomearConversa,
} from './assistente/api';
import { disponibilidade, ecraDoCaminho, MAX_ANEXOS, MAX_REGRAS, percentagemQuota, validarAnexos } from './assistente/regras';
import { CODIGOS_IA, type MensagemIA } from './assistente/tipos';
import './assistente/assistente.css';

interface Props {
  /** Texto de contexto, p.ex. «VENDAS › faturacao» (mostrado no chat vazio). */
  contexto?: string;
}

/**
 * Assistente (chat de IA) flutuante: botão no canto, gaveta com três separadores — Conversa, Histórico e Definições
 * (consumo de tokens e regras da IA da empresa). Distinto do «Assistente IA para lançamentos» do menu do utilizador.
 * As acções propostas pela IA só se executam depois de o utilizador as confirmar (ver AccaoProposta).
 */
export function AssistenteChatFlutuante({ contexto }: Props) {
  const { token } = theme.useToken();
  const { empresa } = useSessao();
  const empresaId = empresa?.id ?? null;
  const local = useLocation();
  const qc = useQueryClient();
  const [aberto, setAberto] = useState(false);
  const [aba, setAba] = useState('chat');
  const [conversa, setConversa] = useState<number | null>(null);
  const [texto, setTexto] = useState('');
  const [anexos, setAnexos] = useState<UploadFile[]>([]);
  const [regras, setRegras] = useState('');
  const [enviado, setEnviado] = useState<string | null>(null);
  const fim = useRef<HTMLDivElement>(null);

  // Tudo o que o assistente guarda é por empresa activa: a chave inclui a empresa e a conversa reinicia ao mudar.
  const k = (chave: readonly unknown[]) => [...chave, empresaId];
  useEffect(() => {
    setConversa(null);
    setTexto('');
    setAnexos([]);
  }, [empresaId]);

  const estado = useQuery({ queryKey: k(chavesIA.estado), queryFn: obterEstado, staleTime: 60_000, retry: false });
  const disp = disponibilidade(estado.data, estado.isLoading);
  const conversas = useQuery({ queryKey: k(chavesIA.conversas), queryFn: listarConversas, enabled: aberto && disp !== 'indisponivel' && !estado.isLoading });
  const mensagens = useQuery({
    queryKey: k(chavesIA.mensagens(conversa)),
    queryFn: () => listarMensagens(conversa as number),
    enabled: aberto && conversa !== null,
  });
  const regrasQuery = useQuery({ queryKey: k(chavesIA.regras), queryFn: obterRegras, enabled: aberto && aba === 'definicoes' });
  useEffect(() => {
    if (regrasQuery.data) setRegras(regrasQuery.data.regras ?? '');
  }, [regrasQuery.data]);
  useEffect(() => {
    fim.current?.scrollIntoView?.({ behavior: 'smooth' });
  }, [mensagens.data, enviado]);

  const refrescarEstado = () => qc.invalidateQueries({ queryKey: k(chavesIA.estado) });
  const refrescarMensagens = () => qc.invalidateQueries({ queryKey: k(chavesIA.mensagens(conversa)) });

  const enviar = useMutation({
    mutationFn: () => {
      const ecra = ecraDoCaminho(local.pathname);
      return enviarMensagem({
        texto: texto.trim(), conversa_id: conversa, modulo: ecra?.modulo, ecra: ecra?.ecra,
        anexos: anexos.map((a) => a.originFileObj).filter((f): f is NonNullable<typeof f> => !!f) as File[],
      });
    },
    onMutate: () => setEnviado(texto.trim()),
    onSuccess: (r) => {
      setTexto('');
      setAnexos([]);
      setConversa(r.conversa.id);
      qc.setQueryData<MensagemIA[]>(k(chavesIA.mensagens(r.conversa.id)), (antigas) => [...(antigas ?? []), ...r.mensagens]);
      void qc.invalidateQueries({ queryKey: k(chavesIA.conversas) });
      void refrescarEstado();
    },
    onError: (e) => {
      const codigo = e instanceof ErroApi ? e.codigo : undefined;
      if (codigo === CODIGOS_IA.quota || codigo === CODIGOS_IA.inactiva) void refrescarEstado();
      if (codigo === CODIGOS_IA.fornecedorMudou) setConversa(null);
      notificarErro(e, 'O assistente não respondeu');
    },
    onSettled: () => setEnviado(null),
  });

  const gravarRegras = useMutation({
    mutationFn: () => guardarRegras(regras),
    onSuccess: () => {
      message.success('Regras guardadas.');
      void qc.invalidateQueries({ queryKey: k(chavesIA.regras) });
    },
    onError: (e) => notificarErro(e),
  });

  function mandar() {
    if (!texto.trim() || enviar.isPending) return;
    if (disp !== 'disponivel') {
      message.info(disp === 'quota_esgotada' ? 'O limite mensal de tokens foi atingido. Peça mais créditos ao administrador da plataforma.' : 'O assistente ainda não está activo para a sua conta. Peça a activação ao administrador da plataforma.');
      return;
    }
    enviar.mutate();
  }

  function aoEscolherAnexos(lista: UploadFile[]) {
    const problemas = validarAnexos(lista.map((f) => ({ name: f.name, size: f.size ?? f.originFileObj?.size ?? 0 })));
    if (problemas.length) {
      message.warning(problemas.map((p) => (p.nome ? `${p.nome}: ${p.motivo}` : p.motivo)).join(' '));
      return;
    }
    setAnexos(lista);
  }

  async function eliminar(id: number) {
    try {
      await eliminarConversa(id);
      if (conversa === id) setConversa(null);
      await qc.invalidateQueries({ queryKey: k(chavesIA.conversas) });
    } catch (e) {
      notificarErro(e);
    }
  }

  async function renomear(id: number, titulo: string) {
    const novo = titulo.trim();
    if (!novo) return;
    try {
      await renomearConversa(id, novo);
      await qc.invalidateQueries({ queryKey: k(chavesIA.conversas) });
    } catch (e) {
      notificarErro(e);
    }
  }

  // Sem backend de IA (404/403 no estado) não há nada para mostrar: o botão não aparece.
  if (estado.error instanceof ErroApi && (estado.error.estado === 404 || estado.error.estado === 403)) return null;

  const quota = estado.data?.quota_tokens_mes ?? null;
  const usados = estado.data?.tokens_usados_mes ?? 0;
  const pct = percentagemQuota(estado.data);
  const bloqueado = disp === 'indisponivel' || disp === 'quota_esgotada';
  const avisoIndisponivel = estado.isError
    ? <Alert type="warning" showIcon style={{ marginBottom: 12 }} title="Não foi possível verificar a disponibilidade do assistente" description="Pode tentar novamente dentro de instantes." action={<Button size="small" onClick={() => void estado.refetch()}>Tentar de novo</Button>} />
    : disp === 'indisponivel' && !estado.isLoading
      ? <Alert type="info" showIcon style={{ marginBottom: 12 }} title="O assistente ainda não está disponível" description="Peça a activação (ou a compra de créditos) ao administrador da plataforma para iniciar conversas." />
      : null;

  const painelChat = (
    <Flex vertical style={{ height: 'calc(100vh - 170px)', minHeight: 320 }}>
      {avisoIndisponivel}
      {disp === 'quota_esgotada' && (
        <Alert type="warning" showIcon style={{ marginBottom: 12 }} title="Limite mensal de tokens atingido" description="Não pode enviar novas mensagens até ao próximo mês. Peça mais créditos ao administrador da plataforma." />
      )}
      <Space style={{ marginBottom: 12 }} wrap>
        <Button icon={<PlusOutlined />} onClick={() => { setConversa(null); setTexto(''); setAnexos([]); }}>Nova conversa</Button>
        <Button icon={<HistoryOutlined />} onClick={() => setAba('historico')}>Histórico</Button>
      </Space>
      <div style={{ flex: 1, overflowY: 'auto', padding: 4 }}>
        {conversa === null && !enviado && (
          <Empty description={contexto ? `Está em ${contexto}. Em que posso ajudar?` : 'Em que posso ajudar?'} />
        )}
        {mensagens.isLoading && <Spin style={{ display: 'block', margin: '16px auto' }} />}
        {mensagens.isError && <Alert type="error" showIcon title="Não foi possível carregar a conversa" action={<Button size="small" onClick={() => void mensagens.refetch()}>Tentar de novo</Button>} />}
        {mensagens.data?.map((m) => (
          <div key={m.id} className={`erp-assistente-mensagem${m.papel === 'utilizador' ? ' erp-assistente-utilizador' : ''}`}>
            <Typography.Text strong>{m.papel === 'utilizador' ? 'Eu' : 'Assistente'}</Typography.Text>
            <Markdown texto={m.texto ?? ''} />
            {m.extra?.accoes?.map((a) => (
              <AccaoProposta key={a.id} accao={a} aoDecidir={refrescarMensagens} aoNavegar={() => setAberto(false)} />
            ))}
            {!!m.extra?.fontes?.length && (
              <div style={{ marginTop: 8 }}>
                <Typography.Text type="secondary" style={{ fontSize: 12 }}>Fontes</Typography.Text>
                {m.extra.fontes.map((f) => (
                  <div key={f.url}><a href={/^https?:\/\//i.test(f.url) ? f.url : undefined} target="_blank" rel="noopener noreferrer">{f.titulo || f.url}</a></div>
                ))}
              </div>
            )}
          </div>
        ))}
        {enviado && (
          <>
            <div className="erp-assistente-mensagem erp-assistente-utilizador">
              <Typography.Text strong>Eu</Typography.Text>
              <Markdown texto={enviado} />
            </div>
            <Flex align="center" gap={8} style={{ padding: 8 }}><Spin size="small" /><Typography.Text type="secondary">A preparar a resposta…</Typography.Text></Flex>
          </>
        )}
        <div ref={fim} />
      </div>
      {anexos.length > 0 && (
        <Flex wrap gap={4} style={{ marginTop: 6 }}>
          {anexos.map((a) => <Tag key={a.uid} closable onClose={() => setAnexos((l) => l.filter((x) => x.uid !== a.uid))}>{a.name}</Tag>)}
        </Flex>
      )}
      <Flex gap={8} align="flex-end" style={{ marginTop: 8 }}>
        <Upload
          multiple
          showUploadList={false}
          fileList={anexos}
          beforeUpload={() => false}
          onChange={(i) => aoEscolherAnexos(i.fileList)}
          disabled={bloqueado || anexos.length >= MAX_ANEXOS}
        >
          <Button icon={<PaperClipOutlined />} aria-label="Anexar ficheiros" title="Anexar PDF, imagem, Excel, CSV ou texto" disabled={bloqueado || anexos.length >= MAX_ANEXOS} />
        </Upload>
        <Input.TextArea
          value={texto}
          onChange={(e) => setTexto(e.target.value)}
          autoSize={{ minRows: 2, maxRows: 5 }}
          maxLength={20000}
          disabled={bloqueado}
          aria-label="Mensagem para o assistente"
          placeholder={bloqueado ? 'O assistente não está disponível.' : 'Escreva a sua mensagem… (Shift+Enter muda de linha)'}
          onPressEnter={(e) => { if (!e.shiftKey) { e.preventDefault(); mandar(); } }}
        />
        <Button type="primary" icon={<SendOutlined />} loading={enviar.isPending} disabled={bloqueado || !texto.trim()} onClick={mandar} aria-label="Enviar mensagem" />
      </Flex>
    </Flex>
  );

  const painelHistorico = (
    <div>
      <Button icon={<PlusOutlined />} onClick={() => { setConversa(null); setAba('chat'); }}>Nova conversa</Button>
      {conversas.isLoading && <Spin style={{ display: 'block', margin: '16px auto' }} />}
      {conversas.data?.map((c) => (
        <Flex key={c.id} align="center" gap={8} style={{ padding: '10px 0', borderBottom: `1px solid ${token.colorBorderSecondary}` }}>
          <Typography.Text ellipsis editable={{ onChange: (v) => void renomear(c.id, v), tooltip: 'Renomear' }} style={{ flex: 1, minWidth: 0 }}>{c.titulo}</Typography.Text>
          <Button size="small" onClick={() => { setConversa(c.id); setAba('chat'); }} aria-label={`Abrir a conversa ${c.titulo}`}>Abrir</Button>
          <Popconfirm title="Eliminar esta conversa?" okText="Eliminar" cancelText="Cancelar" onConfirm={() => eliminar(c.id)}>
            <Button type="text" danger icon={<DeleteOutlined />} aria-label={`Eliminar a conversa ${c.titulo}`} />
          </Popconfirm>
        </Flex>
      ))}
      {!conversas.isLoading && !conversas.data?.length && <Empty description="Sem conversas anteriores" style={{ marginTop: 24 }} />}
    </div>
  );

  const painelDefinicoes = (
    <div>
      <Typography.Title level={5}>Consumo de tokens</Typography.Title>
      <Typography.Paragraph>
        {usados.toLocaleString('pt-PT')} usados este mês{quota !== null ? ` de ${quota.toLocaleString('pt-PT')}` : ' (sem limite)'}
      </Typography.Paragraph>
      {pct !== null && <Progress percent={pct} status={pct >= 100 ? 'exception' : pct >= 80 ? 'normal' : 'active'} />}
      {disp === 'quota_esgotada' && <Alert type="warning" showIcon style={{ marginTop: 8 }} title="Limite mensal atingido" description="Peça mais créditos ao administrador da plataforma." />}
      <Typography.Title level={5} style={{ marginTop: 20 }}>Regras da IA para a sua empresa</Typography.Title>
      <Typography.Paragraph type="secondary">Estas regras orientam o assistente em todas as conversas da empresa.</Typography.Paragraph>
      <Input.TextArea
        rows={10}
        maxLength={MAX_REGRAS}
        showCount
        value={regras}
        aria-label="Regras da IA"
        onChange={(e) => setRegras(e.target.value)}
        placeholder="Ex.: Responda sempre em português de Angola. Antes de propor um lançamento, peça o centro de custo."
      />
      <Button type="primary" loading={gravarRegras.isPending} onClick={() => gravarRegras.mutate()} style={{ marginTop: 16 }}>Guardar regras</Button>
    </div>
  );

  return (
    <>
      <Button
        className="erp-assistente-botao no-print"
        type="primary"
        shape="circle"
        icon={<RobotOutlined style={{ fontSize: 24 }} />}
        onClick={() => setAberto(true)}
        aria-label="Abrir o Assistente (chat)"
        title="Assistente"
      />
      <Drawer
        title={<span><RobotOutlined /> Assistente</span>}
        open={aberto}
        onClose={() => setAberto(false)}
        size={larguraGaveta(520)}
        styles={{ body: { padding: 12 } }}
      >
        <Tabs
          activeKey={aba}
          onChange={setAba}
          items={[
            { key: 'chat', label: <><RobotOutlined /> Conversa</>, children: painelChat },
            { key: 'historico', label: <><HistoryOutlined /> Histórico</>, children: painelHistorico },
            { key: 'definicoes', label: <><SettingOutlined /> Definições</>, children: painelDefinicoes },
          ]}
        />
      </Drawer>
    </>
  );
}
