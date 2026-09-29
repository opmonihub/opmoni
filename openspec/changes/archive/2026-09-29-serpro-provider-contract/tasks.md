## 1. Fixtures reais

- [x] 1.1 Gravar como fixture, a partir de uma chamada real ao trial, a resposta do `SITFIS` com o envelope completo e o `dados` decodificado, no lugar de `sitfis-relatorio.json`; verificar que a suíte determinística continua passando só com fixtures (antiga 3.3 de `complete-serpro-integration`, depois 1.1 de `serpro-contract-and-mailbox`)

  > Feita (2026-09-29): `sitfis-solicitar-protocolo.json` e `sitfis-relatorio.json` vieram do trial, com os contribuintes fictícios do cenário publicado. O `pdf` foi cortado em 4096 caracteres (ver `_provenance`). O real diverge do exemplo documental: não há `responseId`, o `dados` sai em uma passagem e o relatório traz só `{pdf}`. O trial manda `versaoSistema: "1.0"` para os dois serviços do SITFIS, e o `config` usa `2.0`. Isso não foi trocado: o trial é mock e ecoa o que recebe, então a versão de produção segue sem conferência.

## 2. Caixa postal

- [x] 2.1 Conferir contra o gateway o `path` do `MSGDETALHAMENTO62`, hoje `Consultar` por dedução do serviço irmão; corrigir `config/integra-contador.php` se divergir. Não chamar o serviço contra contribuinte real para isso: a chamada registra a ciência da intimação

  > Feita (2026-09-29): o cenário publicado do trial usa `/Consultar`, e a chamada ao trial com o `isn` fictício respondeu `200` com `[Sucesso-CAIXAPOSTAL]`. Gravada em `caixapostal-detalhar-mensagem.json`; um teste passa a resposta real pelo `SerproMonitoringMapper::detalheMensagem`. O `config` não mudou.
