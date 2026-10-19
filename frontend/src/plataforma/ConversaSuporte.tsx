import { Button, Drawer, Empty, Flex, Input, List, Tag, message } from 'antd';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { enviar, obter } from '@/api/cliente';
import { notificarErro } from '@/utilitarios/erros';
import { formatarDataHora } from '@/utilitarios/formatacao';

interface Mensagem { id: number; autor_tipo: 'CLIENTE' | 'PLATAFORMA'; autor_nome: string | null; mensagem: string; criado_em: string }

/** Conversa de um ticket de suporte. `base` = `/plataforma/suporte` (plataforma) ou `/minha-conta/suporte` (cliente). */
export function ConversaSuporte({ base, ticketId, titulo, aoFechar }: { base: string; ticketId: number | null; titulo?: string; aoFechar: () => void }) {
  const qc = useQueryClient();
  const [texto, setTexto] = useState('');
  const chave = ['suporte', 'mensagens', base, ticketId];
  const lista = useQuery({ queryKey: chave, queryFn: () => obter<Mensagem[]>(`${base}/${ticketId}/mensagens`), enabled: ticketId !== null });
  const enviarMsg = useMutation({
    mutationFn: () => enviar('post', `${base}/${ticketId}/mensagens`, { mensagem: texto }),
    onSuccess: () => { setTexto(''); message.success('Mensagem enviada.'); qc.invalidateQueries({ queryKey: chave }); qc.invalidateQueries({ queryKey: [base] }); },
    onError: (e) => notificarErro(e),
  });
  return (
    <Drawer open={ticketId !== null} onClose={aoFechar} width={520} title={titulo ?? 'Conversa'}>
      {lista.data?.length ? (
        <List dataSource={lista.data} renderItem={(m) => (
          <List.Item>
            <List.Item.Meta title={<Flex gap={8}><Tag color={m.autor_tipo === 'PLATAFORMA' ? 'blue' : 'green'}>{m.autor_tipo === 'PLATAFORMA' ? 'Suporte' : 'Cliente'}</Tag>{m.autor_nome}<span style={{ opacity: 0.6 }}>{formatarDataHora(m.criado_em)}</span></Flex>} description={<span style={{ whiteSpace: 'pre-wrap' }}>{m.mensagem}</span>} />
          </List.Item>
        )} />
      ) : <Empty description="Sem mensagens" />}
      <Input.TextArea rows={3} maxLength={5000} value={texto} onChange={(e) => setTexto(e.target.value)} placeholder="Escreva a mensagem" style={{ marginTop: 12 }} />
      <Button type="primary" style={{ marginTop: 8 }} disabled={!texto.trim()} loading={enviarMsg.isPending} onClick={() => enviarMsg.mutate()}>Enviar</Button>
    </Drawer>
  );
}