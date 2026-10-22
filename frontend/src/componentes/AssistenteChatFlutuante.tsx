import { DeleteOutlined, HistoryOutlined, PlusOutlined, RobotOutlined, SendOutlined, SettingOutlined } from '@ant-design/icons';
import { Alert, Button, Drawer, Empty, Input, Popconfirm, Space, Spin, Tabs, Typography, message } from 'antd';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect, useRef, useState } from 'react';
import { useLocation, useNavigate } from 'react-router-dom';
import { enviar, obter } from '@/api/cliente';
import { Markdown } from '@/componentes/Markdown';
import { lerCaminhoEcra } from '@/utilitarios/caminhos';
import { notificarErro } from '@/utilitarios/erros';

type Estado = { ativo: boolean; quota_tokens_mes: number | null; tokens_usados_mes: number };
type Conversa = { id: number; titulo: string; atualizado_em: string };
type Accao = { id: number; tipo: string; resumo: string; metodo: string; url: string; dados: Record<string, unknown>; estado: string };
type Mensagem = { id: number; papel: 'utilizador' | 'assistente'; texto: string; extra: { accoes?: Accao[]; fontes?: { url: string; titulo?: string }[] } };

export function AssistenteChatFlutuante({ contexto }: { contexto?: string }) {
  const [aberto, setAberto] = useState(false);
  const [aba, setAba] = useState('chat');
  const [conversa, setConversa] = useState<number | null>(null);
  const [texto, setTexto] = useState('');
  const [regras, setRegras] = useState('');
  const [aEnviar, setAEnviar] = useState(false);
  const [aGuardar, setAGuardar] = useState(false);
  const fim = useRef<HTMLDivElement>(null);
  const local = useLocation();
  const navegar = useNavigate();
  const qc = useQueryClient();
  const estado = useQuery({ queryKey: ['ia', 'estado'], queryFn: () => obter<Estado>('/ia/estado'), staleTime: 60_000 });
  const conversas = useQuery({ queryKey: ['ia', 'conversas'], queryFn: () => obter<Conversa[]>('/ia/conversas'), enabled: aberto && estado.data?.ativo === true });
  const mensagens = useQuery({ queryKey: ['ia', 'mensagens', conversa], queryFn: () => obter<Mensagem[]>(`/ia/conversas/${conversa}/mensagens`), enabled: aberto && conversa !== null });
  const regrasQuery = useQuery({ queryKey: ['ia', 'regras'], queryFn: () => obter<{ regras: string }>('/ia/regras'), enabled: aberto && aba === 'definicoes' });
  useEffect(() => { if (regrasQuery.data) setRegras(regrasQuery.data.regras); }, [regrasQuery.data]);
  useEffect(() => { fim.current?.scrollIntoView({ behavior: 'smooth' }); }, [mensagens.data, aEnviar]);

  async function mandar() {
    if (!texto.trim() || aEnviar) return;
    setAEnviar(true);
    try {
      const ecra = lerCaminhoEcra(local.pathname);
      const r = await enviar<{ conversa: Conversa; mensagens: Mensagem[] }>('post', '/ia/mensagens', {
        texto: texto.trim(), conversa_id: conversa, modulo: ecra?.modulo, ecra: ecra?.ecra,
      });
      setTexto('');
      setConversa(r.dados.conversa.id);
      qc.setQueryData<Mensagem[]>(['ia', 'mensagens', r.dados.conversa.id], (antigas) => [...(antigas ?? []), ...r.dados.mensagens]);
      await Promise.all([qc.invalidateQueries({ queryKey: ['ia', 'conversas'] }), qc.invalidateQueries({ queryKey: ['ia', 'estado'] })]);
    } catch (e) { notificarErro(e); }
    finally { setAEnviar(false); }
  }

  async function decidir(accao: Accao, confirmar: boolean) {
    try {
      if (!confirmar) {
        await enviar('post', `/ia/accoes/${accao.id}/decisao`, { estado: 'REJEITADA' });
      } else if (accao.tipo === 'navegar' || accao.metodo === 'GET') {
        navegar(accao.url);
        await enviar('post', `/ia/accoes/${accao.id}/decisao`, { estado: 'EXECUTADA' });
        setAberto(false);
      } else {
        await enviar(accao.metodo.toLowerCase() as 'post' | 'put' | 'delete', accao.url, accao.dados);
        await enviar('post', `/ia/accoes/${accao.id}/decisao`, { estado: 'EXECUTADA' });
        message.success('Operação concluída.');
      }
      await qc.invalidateQueries({ queryKey: ['ia', 'mensagens', conversa] });
    } catch (e) { notificarErro(e); }
  }

  async function guardarRegras() {
    setAGuardar(true);
    try {
      await enviar('put', '/ia/regras', { regras });
      await qc.invalidateQueries({ queryKey: ['ia', 'regras'] });
      message.success('Regras guardadas.');
    } catch (e) { notificarErro(e); }
    finally { setAGuardar(false); }
  }

  if (estado.isLoading || !estado.data?.ativo) return null;
  const quota = estado.data.quota_tokens_mes;
  return <>
    <Button type="primary" shape="round" icon={<RobotOutlined />} onClick={() => setAberto(true)} aria-label="Abrir chat da IA"
      style={{ position: 'fixed', right: 22, bottom: 22, zIndex: 900, boxShadow: '0 6px 24px #0003' }}>IA</Button>
    <Drawer title="Assistente IA" open={aberto} onClose={() => setAberto(false)} width={Math.min(520, window.innerWidth)} styles={{ body: { padding: 12, display: 'flex', flexDirection: 'column' } }}>
      <Tabs activeKey={aba} onChange={setAba} items={[
        { key: 'chat', label: <><RobotOutlined /> Chat</>, children: <div style={{ display: 'flex', flexDirection: 'column', height: 'calc(100vh - 160px)' }}>
          <Space style={{ marginBottom: 12 }}><Button icon={<PlusOutlined />} onClick={() => { setConversa(null); setTexto(''); }}>Nova conversa</Button>
            <Button icon={<HistoryOutlined />} onClick={() => setAba('historico')}>Histórico</Button></Space>
          <div style={{ flex: 1, overflowY: 'auto', padding: 4 }}>
            {!conversa && <Empty description={contexto ? `Estás em ${contexto}. Em que vamos trabalhar?` : 'Em que posso ajudar?'} />}
            {mensagens.isLoading && <Spin />}
            {mensagens.data?.map((m) => <div key={m.id} style={{ marginBottom: 16, marginLeft: m.papel === 'utilizador' ? 32 : 0, padding: 12, borderRadius: 12, background: m.papel === 'utilizador' ? '#e6f4ff' : '#f5f5f5' }}>
              <Typography.Text strong>{m.papel === 'utilizador' ? 'Tu' : 'Assistente'}</Typography.Text>
              <Markdown texto={m.texto ?? ''} />
              {m.extra?.accoes?.map((a) => <div key={a.id} style={{ marginTop: 10 }}><Typography.Text>{a.resumo}</Typography.Text><br />
                {a.estado === 'PENDENTE' ? <Space><Popconfirm title="Confirmar esta operação?" onConfirm={() => void decidir(a, true)}><Button size="small" type="primary">Confirmar</Button></Popconfirm>
                  <Button size="small" onClick={() => void decidir(a, false)}>Rejeitar</Button></Space> : <Typography.Text type="secondary">{a.estado}</Typography.Text>}</div>)}
              {m.extra?.fontes?.map((f) => <div key={f.url}><a href={f.url} target="_blank" rel="noopener noreferrer">{f.titulo || f.url}</a></div>)}
            </div>)}
            {aEnviar && <Spin tip="A preparar resposta..." />}
            <div ref={fim} />
          </div>
          <Space.Compact style={{ width: '100%', marginTop: 8 }}><Input.TextArea value={texto} onChange={(e) => setTexto(e.target.value)} autoSize={{ minRows: 2, maxRows: 5 }} placeholder="Escreve a tua mensagem..." onPressEnter={(e) => { if (!e.shiftKey) { e.preventDefault(); void mandar(); } }} />
            <Button type="primary" icon={<SendOutlined />} loading={aEnviar} disabled={!texto.trim()} onClick={() => void mandar()} aria-label="Enviar mensagem" /></Space.Compact>
        </div> },
        { key: 'historico', label: <><HistoryOutlined /> Histórico</>, children: <div><Button icon={<PlusOutlined />} onClick={() => { setConversa(null); setAba('chat'); }}>Nova conversa</Button>
          {conversas.data?.map((c) => <div key={c.id} style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '10px 0', borderBottom: '1px solid #eee' }}>
            <Button type="text" style={{ flex: 1, textAlign: 'left', overflow: 'hidden', textOverflow: 'ellipsis' }} onClick={() => { setConversa(c.id); setAba('chat'); }}>{c.titulo}</Button>
            <Popconfirm title="Eliminar esta conversa?" onConfirm={async () => { try { await enviar('delete', `/ia/conversas/${c.id}`); if (conversa === c.id) setConversa(null); await qc.invalidateQueries({ queryKey: ['ia', 'conversas'] }); } catch (e) { notificarErro(e); } }}><Button type="text" icon={<DeleteOutlined />} aria-label="Eliminar conversa" /></Popconfirm>
          </div>)}
          {!conversas.isLoading && !conversas.data?.length && <Empty description="Sem conversas anteriores" />}
        </div> },
        { key: 'definicoes', label: <><SettingOutlined /> Definições</>, children: <div>
          <Typography.Title level={5}>Consumo de tokens</Typography.Title>
          <Typography.Paragraph>{estado.data.tokens_usados_mes.toLocaleString('pt-PT')} usados este mês{quota !== null ? ` de ${quota.toLocaleString('pt-PT')}` : ''}</Typography.Paragraph>
          {quota !== null && estado.data.tokens_usados_mes >= quota && <Alert type="warning" message="Limite mensal atingido" />}
          <Typography.Title level={5}>Regras da IA para a tua empresa</Typography.Title>
          <Typography.Paragraph type="secondary">Estas regras orientam o assistente em todas as conversas da empresa.</Typography.Paragraph>
          <Input.TextArea rows={10} maxLength={10000} showCount value={regras} onChange={(e) => setRegras(e.target.value)} placeholder="Ex.: Responde sempre em português de Angola. Antes de propor um lançamento, pede o centro de custo." />
          <Button type="primary" loading={aGuardar} onClick={() => void guardarRegras()} style={{ marginTop: 18 }}>Guardar regras</Button>
        </div> },
      ]} />
    </Drawer>
  </>;
}
