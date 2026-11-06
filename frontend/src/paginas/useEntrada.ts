import { useCallback, useRef, useState } from 'react';
import { ErroApi, type Utilizador } from '@/api/tipos';
import { ErroMfaPendente } from '@/sessao/mfa';
import { useSessao } from '@/sessao/SessaoContexto';

/**
 * Estado comum dos três ecrãs de entrada (ERP, consola /admin, suporte): erro a mostrar, envio em curso (com bloqueio de
 * duplo envio) e o 2.º passo do MFA. O identificador (utilizador ou e-mail) é enviado sem espaços nas pontas; a
 * palavra-passe segue exactamente como foi escrita. Nenhuma mensagem diz se a conta existe: vêm do servidor.
 */
export function useEntrada(mensagemPadrao = 'Não foi possível entrar. Tente novamente.') {
  const { entrar, concluirMfa } = useSessao();
  const [erro, setErro] = useState<string | null>(null);
  const [aEntrar, setAEntrar] = useState(false);
  const [tokenMfa, setTokenMfa] = useState<string | null>(null);
  const emCurso = useRef(false);

  const executar = useCallback(
    async (accao: () => Promise<Utilizador>): Promise<Utilizador | null> => {
      if (emCurso.current) return null;   // duplo clique / Enter repetido: só um pedido de cada vez
      emCurso.current = true;
      setErro(null);
      setAEntrar(true);
      try {
        return await accao();
      } catch (e) {
        if (e instanceof ErroMfaPendente) {
          setTokenMfa(e.tokenMfa);
        } else {
          setErro(e instanceof ErroApi ? e.message : mensagemPadrao);
          // código errado: o token limitado continua válido (5 min) e o utilizador volta a tentar; sessão expirada: volta ao início
          if (e instanceof ErroApi && (e.estado === 401 || e.estado === 403)) setTokenMfa(null);
        }
        return null;
      } finally {
        emCurso.current = false;
        setAEntrar(false);
      }
    },
    [mensagemPadrao],
  );

  /** 1.º passo: utilizador (ou e-mail) + palavra-passe. Devolve o utilizador se já entrou; null se falhou ou se falta o código MFA. */
  const submeter = useCallback(
    (nome: string, palavraPasse: string) => executar(() => entrar(nome.trim(), palavraPasse)),
    [executar, entrar],
  );

  /** 2.º passo do MFA. */
  const submeterCodigo = useCallback(
    (codigo: string) => (tokenMfa ? executar(() => concluirMfa(tokenMfa, codigo.trim())) : Promise.resolve(null)),
    [executar, concluirMfa, tokenMfa],
  );

  const cancelarMfa = useCallback(() => {
    setTokenMfa(null);
    setErro(null);
  }, []);

  return { erro, setErro, aEntrar, passoMfa: tokenMfa !== null, submeter, submeterCodigo, cancelarMfa };
}
