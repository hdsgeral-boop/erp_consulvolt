import { Alert, Button, Input, Modal, Skeleton, Space, Typography, message } from 'antd';
import { useMutation, useQuery } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { larguraModal } from '@/componentes/responsivo';
import { notificarErro } from '@/utilitarios/erros';
import { gravarMetas, obterMetas, type Indicador } from './api';
import { escada, lerPasso } from './comum';

/**
 * Metas da empresa por período (est_metas_editar): uma caixa por período da frequência do indicador, para cada ano do ciclo.
 * Caixa vazia apaga a meta. «Preencher em escada» parte de um valor inicial e de um passo absoluto ou percentual («5%»), em
 * aritmética decimal igual à do servidor. Num plano aprovado o servidor pede o motivo (ver `pedirMotivo`).
 */
export function ModalMetas({ indicador, editavel, aoFechar, pedirMotivo }: { indicador: Indicador; editavel: boolean; aoFechar: () => void; pedirMotivo: (f: (m?: string) => void) => void }) {
  const { data, isLoading } = useQuery({ queryKey: ['estrategia', 'metas', indicador.id], queryFn: () => obterMetas(indicador.id) });
  const [valores, setValores] = useState<Record<string, string>>({});
  const [escadaAberta, setEscadaAberta] = useState(false);
  const [inicio, setInicio] = useState('');
  const [passo, setPasso] = useState('0');
  useEffect(() => { if (data) setValores(Object.fromEntries(data.metas.filter((m) => m.valor !== null).map((m) => [m.periodo, String(Number(m.valor))]))); }, [data]);
  const periodos = (data?.anos ?? []).flatMap((a) => a.periodos.map((p) => p.chave));
  const oculto = !!data?.oculto;

  const gravar = useMutation({
    mutationFn: (motivo?: string) => {
      const inicial = new Set((data?.metas ?? []).map((m) => m.periodo));
      const envio = periodos.filter((p) => (valores[p] ?? '').trim() !== '' || inicial.has(p)).map((p) => ({ periodo: p, valor: (valores[p] ?? '').trim() === '' ? null : valores[p].trim().replace(',', '.') }));
      return gravarMetas(indicador.id, envio, motivo);
    },
    onSuccess: () => { message.success('Metas gravadas.'); aoFechar(); },
    onError: (e) => notificarErro(e),
  });

  const preencher = () => {
    const { passo: p, percentual } = lerPasso(passo);
    const lista = escada(inicio, p, periodos.length, percentual);
    setValores(Object.fromEntries(periodos.map((per, k) => [per, lista[k]])));
    setEscadaAberta(false);
  };

  return (
    <Modal open title={`Metas · ${indicador.nome}`} width={larguraModal(720)} onCancel={aoFechar} destroyOnHidden okText="Gravar metas" cancelText="Cancelar" confirmLoading={gravar.isPending}
      okButtonProps={{ disabled: !editavel || oculto }} onOk={() => pedirMotivo((m) => gravar.mutate(m))}
      footer={(_, { OkBtn, CancelBtn }) => (
        <Space wrap>
          {editavel && !oculto && <Button onClick={() => setEscadaAberta(true)}>Preencher em escada</Button>}
          <CancelBtn />{editavel && <OkBtn />}
        </Space>
      )}>
      {isLoading || !data ? <Skeleton active /> : (
        <>
          <Typography.Paragraph type="secondary">
            {data.frequencia.charAt(0) + data.frequencia.slice(1).toLowerCase()} · {data.polaridade === 'SOBE' ? 'quanto maior, melhor' : data.polaridade === 'DESCE' ? 'quanto menor, melhor' : 'quanto mais perto da meta, melhor'}
          </Typography.Paragraph>
          {oculto && <Alert type="info" showIcon style={{ marginBottom: 12 }} title="Os valores deste indicador são financeiros e estão reservados a quem tem a permissão «Ver valores financeiros»." />}
          {data.anos.map((a) => (
            <section key={a.ano} aria-label={`Metas de ${a.ano}`}>
              <Typography.Title level={5} style={{ marginTop: 8 }}>{a.ano}</Typography.Title>
              <div className="est-metas-ano">
                {a.periodos.map((p) => (
                  <label key={p.chave}>
                    <span style={{ fontSize: 12 }}>{p.rotulo.replace(/ de \d{4}$/, '')}</span>
                    <Input inputMode="decimal" aria-label={`Meta de ${p.rotulo}`} disabled={!editavel || oculto} className="est-valor" style={{ textAlign: 'right' }} value={valores[p.chave] ?? ''}
                      onChange={(e) => setValores((v) => ({ ...v, [p.chave]: e.target.value }))} />
                  </label>
                ))}
              </div>
            </section>
          ))}
        </>
      )}
      <Modal open={escadaAberta} title="Preencher em escada" okText="Preencher" cancelText="Cancelar" onCancel={() => setEscadaAberta(false)} onOk={preencher} width={larguraModal(420)} destroyOnHidden>
        <label>Valor do primeiro período<Input inputMode="decimal" value={inicio} onChange={(e) => setInicio(e.target.value)} /></label>
        <label style={{ display: 'block', marginTop: 12 }}>Crescimento por período (use % no fim para percentagem, ex.: 5%)<Input value={passo} onChange={(e) => setPasso(e.target.value)} /></label>
      </Modal>
    </Modal>
  );
}
