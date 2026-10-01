import { Result } from 'antd';
import { useParams } from 'react-router-dom';
import { ECRAS } from '@/modulos/registo';
import { EmConstrucao } from '@/componentes/EmConstrucao';
import { useSessao } from '@/sessao/SessaoContexto';

/** /m/:modulo/:ecra/* — mostra o ecrã registado, se o utilizador o puder ver (o servidor volta a validar cada pedido). */
export function Ecra() {
  const { modulo, ecra } = useParams();
  const { menu } = useSessao();
  const definicao = menu.find((m) => m.id === modulo)?.ecras.find((e) => e.id === ecra);
  if (!definicao) return <Result status="403" title="Sem acesso" subTitle="Não tem permissão para este ecrã nesta empresa." />;
  const Componente = ecra ? ECRAS[ecra] : undefined;
  return Componente ? <Componente /> : <EmConstrucao nome={definicao.nome} />;
}
