## 0. Baseline

- [x] 0.1 Confirmar working tree limpo e rodar `cd backend && composer test` verificando suíte verde antes de alterar código
- [ ] 0.2 Garantir `.env` local com `FISCAL_ENVIRONMENT=homologacao`, credencial de plataforma, certificados do escritório G A CONT e termo válido — sem commitar segredos — verificando `php artisan config:show fiscal.environment` retorna `homologacao`

## 1. Documentação SERPRO (spike)

- [x] 1.1 Levantar no catálogo Integra Contador / documentação PGDASD quais `idServico` retornam extrato e declaração detalhada além de `CONSDECLARACAO13` e registrar conclusão em comentário no `design.md` (Open Questions) verificando que a seção cita serviço ou lacuna explícita
- [x] 1.2 Comparar campos esperados (DAS emitido, extrato, declaração) com `SerproMonitoringMapper::pgdas()` e listar gaps em nota no PR futuro verificando diff mental contra `openspec/changes/add-real-pgdas-das-fetch/specs/monitoring/spec.md`

## 2. Configuração e gates

- [x] 2.1 Adicionar config de probe (`homologation_canary_cnpj` default `30288513000100`, flag `SERPRO_PROBE_ENABLED`) e helper que recusa execução fora de homologação verificando teste unitário ou feature que asserta recusa quando `fiscal.environment` é produção
- [x] 2.2 Escrever teste vermelho que probe sem credencial/termo resulta em skip ou erro legível verificando `php artisan test --compact --filter=probe` falha antes da implementação

## 3. Comando de loop (`serpro:probe-pgdas`)

- [x] 3.1 Implementar `php artisan serpro:probe-pgdas` resolvendo Account pelo CNPJ `48123272000105` (ou `--account=`), cliente AUTO CENTER, chamando gateway homologação com envelope PGDASD/`CONSDECLARACAO13` verificando saída JSON/texto com status por etapa (auth, elegibilidade, consulta, projeção)
- [x] 3.2 Integrar projeção via `SerproMonitoringMapper` e validar períodos, DAS emitido e causa `sem_declaracao` quando aplicável verificando comando retorna PASS quando homologação responde 200
- [x] 3.3 Tratar 429/900807 e procuração ausente como skip legível verificando execução simulada com Http::fake ou resposta gravada

## 4. Testes Feature (grupo `serpro-trial`)

- [x] 4.1 Criar `SerproPgdasHomologationProbeTest` (ou equivalente) com `#[Group('serpro-trial')]` espelhando o comando para AUTO CENTER verificando `cd backend && php artisan test --compact --group=serpro-trial --filter=Pgdas` passa em homologação com pré-requisitos
- [x] 4.2 Adicionar teste que falha se ambiente não for homologação e flag desligada verificando skip/recusa sem HTTP outbound (`Http::preventStrayRequests()`)
- [x] 4.3 Se resposta real divergir da fixture `pgdasd-consultar-declaracao.json`, gravar fixture sanitizada nova e registrar em `SerproContractFixtureTest::payloads()` verificando `php artisan test --compact --filter=SerproContractFixtureTest` verde

## 5. Sincronização e monitoramento (ajustes mínimos)

- [x] 5.1 Corrigir mapper/catálogo apenas se probe expuser bug real (campos DAS, ano-calendário, elegibilidade `00146`) verificando `php artisan test --compact --filter=SerproSyncProjectionTest` e probe verdes
- [x] 5.2 Opcional: rodar um sync de uma execução só para o canário via job existente e confirmar linha `declaracoes/pgdas` persistida verificando assert em teste ou inspeção API autenticada documentada no PR

## 6. Runbook operacional

- [x] 6.1 Documentar loop de manutenção: variáveis de ambiente, intervalo entre execuções, CNPJs canário, comando e grupo PHPUnit verificando que `backend/README` ou bloco em `design.md` contém copy-paste funcional
- [x] 6.2 Documentar dependência de docs/SDK SERPRO para extrato/declaração quando spike (task 1.1) não fechar serviço verificando link ou referência ao portal/catálogo no runbook

## 7. Verificação final

- [x] 7.1 Rodar `cd backend && composer test` (sem `--group=serpro-trial`) verificando zero regressões
- [x] 7.2 Rodar `cd backend && vendor/bin/pint --dirty --format agent` verificando estilo PHP
- [x] 7.3 Rodar loop completo em homologação: `FISCAL_ENVIRONMENT=homologacao SERPRO_PROBE_ENABLED=1 php artisan serpro:probe-pgdas` e `--group=serpro-trial` verificando PASS ou skip justificado (cota/procuração)
- [x] 7.4 Rodar `openspec validate add-real-pgdas-das-fetch --strict` verificando change válido
- [x] 7.5 Revisar que logs e saída do probe não contêm consumer secret, senha de certificado, token ou XML bruto verificando grep manual em saída de uma execução de teste
