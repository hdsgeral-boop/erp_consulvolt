import { Button, Drawer, Empty, Flex, Input, List, Select, Space, Tabs, Tag, Typography, Upload, message } from 'antd';
import { PaperClipOutlined } from '@ant-design/icons';
import type { UploadFile } from 'antd/es/upload/interface';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { descarregar, enviar, obter } from '@/api/cliente';
import { notificarErro } from '@/utilitarios/erros';
import { formatarDataHora } from '@/utilitarios/formatacao';

interface Anexo { id: number; nome: string; tamanho_bytes: number; tipo_mime: string }
interface Mensagem { id: number; autor_tipo: 'CLIENTE' | 'PLATAFORMA'; autor_nome: string | null; mensagem: string; criado_em: string; anexos?: Anexo[] }
interface NotaInterna { id: number; autor_nome: string | null; nota: string; criado_em: string }
interface RespostaRapida { id: number; titulo: string; texto: string }

const ACEITA_ANEXOS = 'image/jpeg,image/png,image/webp,application/pdf';
const tamanhoLegivel = (b: number) => (b < 1024 ? `${b} B` : b < 1024 * 1024 ? `${Math.round(b / 1024)} KB` : `${(b / 1024 / 1024).toFixed(1)} MB`);

/**
 * Conversa de um ticket de suporte. `base` = `/plataforma/suporte` (plataforma), `/suporte/tickets` (agente) ou
 * `/minha-conta/suporte` (cliente); `anexosBase` é a rota de download dos anexos dessa mesma área. `agente`: mostra
 * notas internas (nunca visíveis ao cliente) e, com `respostasRapidasBase`, o selector de respostas pré-definidas.
 */
export function ConversaSuporte({
  base, anexosBase, respostasRapidasBase, agente, ticketId, titulo, aoFechar,
}: {
  base: string; anexosBase: string; respostasRapidasBase?: string; agente?: boolean; ticketId: number | null; titulo?: string; aoFechar: () => void;
}) {
  const qc = useQueryClient();
  const [texto, setTexto] = useState('');
  const [ficheiros, setFicheiros] = useState<UploadFile[]>([]);
  const [novaNota, setNovaNota] = useState('');
  const chave = ['suporte', 'mensagens', base, ticketId];
  const chaveNotas = ['suporte', 'notas', base, ticketId];
  const lista = useQuery({ queryKey: chave, queryFn: () => obter<Mensagem[]>(`${base}/${ticketId}/mensagens`), enabled: ticketId !== null });
  const notas = useQuery({ queryKey: chaveNotas, queryFn: () => obter<NotaInterna[]>(`${base}/${ticketId}/notas`), enabled: ticketId !== null && !!agente });
  const respostasRapidas = useQuery({
    queryKey: ['suporte', 'respostas-rapidas', respostasRapidasBase],
    queryFn: () => obter<RespostaRapida[]>(respostasRapidasBase ?? ''),
    enabled: !!agente && !!respostasRapidasBase,
  });
  const enviarMsg = useMutation({
    mutationFn: () => {
      if (ficheiros.length === 0) return enviar('post', `${base}/${ticketId}/mensagens`, { mensagem: texto });
      const fd = new FormData();
      fd.append('mensagem', texto);
      ficheiros.forEach((f) => f.originFileObj && fd.append('ficheiros[]', f.originFileObj));

      return enviar('post', `${base}/${ticketId}/mensagens`, fd);
    },
    onSuccess: () => {
      setTexto('');
      setFicheiros([]);
      message.success('Mensagem enviada.');
      qc.invalidateQueries({ queryKey: chave });
      qc.invalidateQueries({ queryKey: [base] });
    },
    onError: (e) => notificarErro(e),
  });
  const adicionarNota = useMutation({
    mutationFn: () => enviar('post', `${base}/${ticketId}/notas`, { nota: novaNota }),
    onSuccess: () => { setNovaNota(''); qc.invalidateQueries({ queryKey: chaveNotas }); },
    onError: (e) => notificarErro(e),
  });

  const conversa = (
    <>
      {lista.data?.length ? (
        <List
          dataSource={lista.data}
          renderItem={(m) => (
            <List.Item>
              <List.Item.Meta
                title={<Flex gap={8}><Tag color={m.autor_tipo === 'PLATAFORMA' ? 'blue' : 'green'}>{m.autor_tipo === 'PLATAFORMA' ? 'Suporte' : 'Cliente'}</Tag>{m.autor_nome}<span style={{ opacity: 0.6 }}>{formatarDataHora(m.criado_em)}</span></Flex>}
                description={
                  <>
                    <span style={{ whiteSpace: 'pre-wrap' }}>{m.mensagem}</span>
                    {(m.anexos?.length ?? 0) > 0 && (
                      <Space direction="vertical" size={2} style={{ marginTop: 6, display: 'flex' }}>
                        {m.anexos?.map((a) => (
                          <Button
                            key={a.id}
                            size="small"
                            type="link"
                            icon={<PaperClipOutlined />}
                            style={{ padding: 0, height: 'auto' }}
                            onClick={() => descarregar(`${anexosBase}/${a.id}`, undefined, a.nome).catch((e) => notificarErro(e))}
                          >
                            {a.nome} ({tamanhoLegivel(a.tamanho_bytes)})
                          </Button>
                        ))}
                      </Space>
                    )}
                  </>
                }
              />
            </List.Item>
          )}
        />
      ) : <Empty description="Sem mensagens" />}
      {agente && (respostasRapidas.data?.length ?? 0) > 0 && (
        <Select
          allowClear
          placeholder="Inserir resposta rápida…"
          style={{ width: '100%', marginTop: 12 }}
          value={undefined}
          options={(respostasRapidas.data ?? []).map((r) => ({ value: r.id, label: r.titulo }))}
          onChange={(id) => {
            const r = respostasRapidas.data?.find((x) => x.id === id);
            if (r) setTexto((t) => (t.trim() ? `${t}\n${r.texto}` : r.texto));
          }}
        />
      )}
      <Upload multiple accept={ACEITA_ANEXOS} fileList={ficheiros} beforeUpload={() => false} onChange={({ fileList }) => setFicheiros(fileList.slice(-3))} maxCount={3} style={{ marginTop: 8 }}>
        <Button size="small" icon={<PaperClipOutlined />}>Anexar (até 3, imagem ou PDF, 5 MB cada)</Button>
      </Upload>
      <Input.TextArea rows={3} maxLength={5000} value={texto} onChange={(e) => setTexto(e.target.value)} placeholder="Escreva a mensagem" style={{ marginTop: 8 }} />
      <Button type="primary" style={{ marginTop: 8 }} disabled={!texto.trim()} loading={enviarMsg.isPending} onClick={() => enviarMsg.mutate()}>Enviar</Button>
    </>
  );

  const notasInternas = (
    <>
      <Typography.Paragraph type="secondary" style={{ fontSize: 12 }}>Visíveis só à equipa de suporte — o cliente nunca as vê.</Typography.Paragraph>
      {notas.data?.length ? (
        <List dataSource={notas.data} renderItem={(n) => (
          <List.Item><List.Item.Meta title={<Flex gap={8}>{n.autor_nome}<span style={{ opacity: 0.6 }}>{formatarDataHora(n.criado_em)}</span></Flex>} description={<span style={{ whiteSpace: 'pre-wrap' }}>{n.nota}</span>} /></List.Item>
        )} />
      ) : <Empty description="Sem notas internas" />}
      <Input.TextArea rows={2} maxLength={2000} value={novaNota} onChange={(e) => setNovaNota(e.target.value)} placeholder="Nova nota interna" style={{ marginTop: 12 }} />
      <Button style={{ marginTop: 8 }} disabled={!novaNota.trim()} loading={adicionarNota.isPending} onClick={() => adicionarNota.mutate()}>Adicionar nota</Button>
    </>
  );

  return (
    <Drawer open={ticketId !== null} onClose={aoFechar} width={560} title={titulo ?? 'Conversa'}>
      {agente ? (
        <Tabs items={[{ key: 'conversa', label: 'Conversa', children: conversa }, { key: 'notas', label: 'Notas internas', children: notasInternas }]} />
      ) : conversa}
    </Drawer>
  );
}
