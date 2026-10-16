import { Alert, Button, Form, InputNumber, Select } from 'antd';
import { useEffect } from 'react';
import { useAccao } from '@/componentes/Accoes';
import { IconeFa } from '@/componentes/fluxos/IconeFa';
import { useSessao } from '@/sessao/SessaoContexto';
import { formatarDataHora } from '@/utilitarios/formatacao';
import { TIPOS_TERMINAL } from '../comum/dados';
import { meiosActivos } from '../comum/PainelPagamentos';
import { accoesFrente } from '../comum/regras';
import type { Terminal } from '../comum/tipos';
import { useCoresPos } from '../comum/cores';
import { AvisoSessoesAntigas } from './AvisoSessoesAntigas';

const rotulo = { fontWeight: 700, color: '#334155', fontSize: '0.85rem' } as const;

/**
 * Cartão «Abertura de Caixa» do legado (renderPOSTab com a caixa fechada, js/ui_sales.js:6362-6400, e ajustarEcraPOS,
 * pos_gestao.js:154-176): «Terminal POS / Caixa», «Fundo de Maneio / Valor Inicial (Kz)», aviso fixo das sessões abertas
 * desde dias anteriores, operador, meios de pagamento e «Abrir Caixa & Iniciar Vendas». Se o terminal escolhido já tiver
 * a sessão aberta, oferece continuar nela.
 */
export function AberturaCaixa({ terminais, terminalId, aoEscolher, aoContinuar }: { terminais: Terminal[]; terminalId: number | null; aoEscolher: (id: number) => void; aoContinuar: () => void }) {
  const cores = useCoresPos();
  const { pode, utilizador } = useSessao();
  const [form] = Form.useForm<{ fundo_maneio: number | null }>();
  const terminal = terminais.find((t) => t.id === terminalId) ?? null;
  const accoes = accoesFrente(pode, terminal);
  const meios = meiosActivos(terminal?.meios_pagamento).filter((m) => !!m.conta_transitoria);
  const abrir = useAccao({ invalidar: [['pos']], tituloErro: 'Não foi possível abrir a sessão', aoSucesso: aoContinuar });
  const operador = utilizador ? utilizador.nome_completo || utilizador.nome_utilizador : '—';
  const sessao = terminal?.sessao_aberta;

  useEffect(() => {
    form.setFieldsValue({ fundo_maneio: Number(terminal?.fundo_maneio_padrao ?? 0) });
  }, [terminal?.id, terminal?.fundo_maneio_padrao, form]);

  return (
    <div style={{ display: 'flex', justifyContent: 'center', alignItems: 'center', minHeight: '60vh' }}>
      <div className="pos-abertura-caixa" style={{ maxWidth: 480, width: '95%', background: cores.fundo, borderTop: '4px solid #2563eb', borderRadius: 4, padding: '2.5rem', boxShadow: '0 15px 40px rgba(0,0,0,0.1)' }}>
        <div style={{ textAlign: 'center', marginBottom: '1.5rem' }}>
          <div style={{ background: 'rgba(59,130,246,0.1)', color: '#2563eb', width: 70, height: 70, borderRadius: '50%', display: 'flex', alignItems: 'center', justifyContent: 'center', margin: '0 auto 1rem', fontSize: '2.2rem' }}>
            <IconeFa nome="cash-register" />
          </div>
          <h2 style={{ margin: 0, fontWeight: 800, color: cores.texto }}>Abertura de Caixa</h2>
          <p style={{ margin: '0.25rem 0 0', fontSize: '0.85rem', color: '#64748b' }}>Selecione o terminal e o fundo de maneio inicial.</p>
        </div>

        <Form
          form={form}
          layout="vertical"
          requiredMark={false}
          onFinish={(v) => terminal && abrir.mutate({ url: `/pos/terminais/${terminal.id}/sessoes`, dados: { fundo_maneio: v.fundo_maneio ?? 0 } })}
        >
          <Form.Item label={<span style={rotulo}>Terminal POS / Caixa:</span>}>
            <Select<number>
              size="large"
              aria-label="Terminal POS / Caixa"
              placeholder="Seleccione o terminal"
              value={terminal?.id}
              onChange={aoEscolher}
              options={terminais.map((t) => ({
                value: t.id,
                label: `${t.nome} (${t.codigo} · ${TIPOS_TERMINAL[t.tipo] ?? t.tipo})${t.ativo ? '' : ' · inactivo'}${t.sessao_aberta ? ' · caixa aberta' : ''}`,
                disabled: !t.ativo,
              }))}
            />
          </Form.Item>
          {!sessao && (
            <Form.Item name="fundo_maneio" label={<span style={rotulo}>Fundo de Maneio / Valor Inicial (Kz):</span>}>
              <InputNumber<number> size="large" min={0} precision={2} decimalSeparator="," style={{ width: '100%', fontWeight: 800 }} aria-label="Fundo de Maneio / Valor Inicial (Kz)" />
            </Form.Item>
          )}

          <AvisoSessoesAntigas />

          {terminal && (
            <div style={{ fontSize: '0.8rem', color: '#475569', background: cores.fundoAlt, border: `1px solid ${cores.borda}`, borderRadius: 4, padding: '0.6rem 0.8rem', marginBottom: '1rem', lineHeight: 1.7 }}>
              <div>
                <IconeFa nome="user" /> Operador: <strong>{operador}</strong>
              </div>
              <div>
                <IconeFa nome="credit-card" /> Meios de pagamento:{' '}
                {meios.length ? meios.map((m) => m.nome).join(', ') : <strong style={{ color: '#b91c1c' }}>nenhum meio com conta transitória configurado</strong>}
              </div>
              {sessao && (
                <div>
                  <IconeFa nome="hashtag" /> Caixa aberta: <strong>{sessao.codigo_sessao}</strong> · {sessao.nome_operador ?? '—'} · desde {formatarDataHora(sessao.aberto_em)}
                </div>
              )}
            </div>
          )}

          {sessao ? (
            <Button type="primary" size="large" block onClick={aoContinuar} disabled={!pode('pos_venda')} icon={<IconeFa nome="cash-register" />} style={{ borderRadius: 4, fontWeight: 800, height: 52 }}>
              Continuar a vender nesta caixa
            </Button>
          ) : terminal && !accoes.abrir ? (
            <Alert type="info" showIcon message={terminal.ativo ? 'Não tem permissão para abrir sessões neste terminal.' : 'Terminal inactivo: active-o em Terminais POS.'} />
          ) : (
            <Button
              type="primary"
              size="large"
              block
              htmlType="submit"
              loading={abrir.isPending}
              disabled={!terminal || !meios.length}
              title={terminal && !meios.length ? 'Configure os meios de pagamento do terminal em Terminais POS' : undefined}
              icon={<IconeFa nome="key" />}
              style={{ borderRadius: 4, fontWeight: 800, height: 52 }}
            >
              Abrir Caixa &amp; Iniciar Vendas
            </Button>
          )}
        </Form>
      </div>
    </div>
  );
}
