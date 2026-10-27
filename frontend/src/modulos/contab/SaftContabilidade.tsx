import { Alert, Button, Card, Flex, InputNumber, Typography, message, theme } from 'antd';
import { useState } from 'react';
import { descarregar, obter } from '@/api/cliente';
import { IconeFa } from '@/componentes/fluxos/IconeFa';
import { useSessao } from '@/sessao/SessaoContexto';
import { notificarErro } from '@/utilitarios/erros';

interface ValidacaoSaftContabilidade {
  nome: string;
  lancamentos: number;
  contas: number;
  avisos: string[];
}

const ROSA = '#1e3a8a';

/**
 * SAF-T(AO) de Contabilidade (Decreto Executivo n.º 317/20): gera e descarrega o ficheiro XML anual (GeneralLedgerEntries)
 * do exercício escolhido — prazo legal 10 de Abril do ano seguinte; obrigatório a partir do exercício de 2026.
 */
export default function SaftContabilidade() {
  const { pode } = useSessao();
  const { token } = theme.useToken();
  const [ano, setAno] = useState<number>(new Date().getFullYear() - 1);
  const [aValidar, setAValidar] = useState(false);
  const [aGerar, setAGerar] = useState(false);
  const [previa, setPrevia] = useState<ValidacaoSaftContabilidade | null>(null);
  const permitido = pode('relatorios_contabeis_view', 'contab_agt');

  const validar = async () => {
    setAValidar(true);
    setPrevia(null);
    try {
      const v = await obter<ValidacaoSaftContabilidade>('/contabilidade/saft/validar', { ano });
      setPrevia(v);
    } catch (e) {
      notificarErro(e, 'Não foi possível validar o exercício');
    } finally {
      setAValidar(false);
    }
  };

  const gerar = async () => {
    setAGerar(true);
    try {
      const v = await obter<ValidacaoSaftContabilidade>('/contabilidade/saft/validar', { ano });
      const d = await descarregar('/contabilidade/saft', { ano }, v.nome || `SAFT_AO_CONTAB_${ano}.xml`);
      message.success(`SAF-T de Contabilidade gerado: ${d.cabecalhos['x-saft-lancamentos'] ?? v.lancamentos} linha(s), ${d.cabecalhos['x-saft-contas'] ?? v.contas} conta(s).`);
      const avisos = d.cabecalhos['x-saft-avisos'] ? decodeURIComponent(d.cabecalhos['x-saft-avisos']) : v.avisos.join(' | ');
      if (avisos) message.warning(`Avisos: ${avisos}`, 10);
    } catch (e) {
      notificarErro(e, 'Não foi possível gerar o SAF-T de Contabilidade');
    } finally {
      setAGerar(false);
    }
  };

  if (!permitido) return <Alert type="info" showIcon message="Sem permissão para exportar o SAF-T (AO) de Contabilidade." />;

  return (
    <>
      <div style={{ background: `linear-gradient(135deg, ${ROSA} 0%, #1e293b 100%)`, color: '#fff', borderRadius: 4, padding: 24, marginBottom: 24, position: 'relative', overflow: 'hidden' }}>
        <IconeFa nome="book" style={{ position: 'absolute', right: -10, bottom: -20, fontSize: 120, opacity: 0.12, transform: 'rotate(-15deg)' }} />
        <h3 style={{ margin: '0 0 8px', fontSize: 22, fontWeight: 800, color: '#fff' }}><IconeFa nome="file-invoice-dollar" /> SAF-T (AO) de Contabilidade</h3>
        <p style={{ margin: 0, fontSize: 14, opacity: 0.95, maxWidth: 650 }}>
          Ficheiro anual do razão geral (plano de contas, saldos de abertura/fecho e lançamentos do exercício), conforme o Decreto Executivo n.º 317/20.
          Prazo legal: 10 de Abril do ano seguinte.
        </p>
      </div>

      <Card>
        <h4 style={{ margin: '0 0 16px', fontSize: 14, fontWeight: 800, textTransform: 'uppercase', letterSpacing: '0.05em' }}>
          <IconeFa nome="filter" style={{ color: ROSA }} /> Exercício
        </h4>
        <Flex gap={16} align="flex-end" wrap>
          <div>
            <Typography.Text style={{ display: 'block', fontSize: 12, fontWeight: 800, color: token.colorTextSecondary, textTransform: 'uppercase', marginBottom: 6 }}>
              Ano
            </Typography.Text>
            <InputNumber min={2000} max={new Date().getFullYear()} value={ano} onChange={(v) => setAno(v ?? ano)} aria-label="Exercício" style={{ width: 140 }} />
          </div>
          <Button icon={<IconeFa nome="search-dollar" />} loading={aValidar} onClick={() => void validar()}>Validar</Button>
          <Button type="primary" icon={<IconeFa nome="file-download" />} loading={aGerar} onClick={() => void gerar()}>Gerar e Descarregar XML</Button>
        </Flex>
        {previa && (
          <Alert
            style={{ marginTop: 16 }}
            type={previa.avisos.length ? 'warning' : 'success'}
            showIcon
            message={`${previa.lancamentos} linha(s) de lançamento, ${previa.contas} conta(s) do plano, no exercício de ${ano}.`}
            description={previa.avisos.length ? <ul style={{ margin: 0, paddingLeft: 18 }}>{previa.avisos.map((a) => <li key={a}>{a}</li>)}</ul> : undefined}
          />
        )}
      </Card>
    </>
  );
}
