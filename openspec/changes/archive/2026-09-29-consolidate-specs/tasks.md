## 1. Baseline

- [x] 1.1 Conferir que as mudanças em `openspec/specs/` são só desta change; verificar com `git diff --stat openspec/specs`
- [x] 1.2 Validar a change e as specs vigentes; verificar com `openspec validate consolidate-specs --strict` e `openspec validate --specs`

## 2. Specs vigentes (edição direta)

- [x] 2.1 Centralizar isolamento, auditoria de suporte e matriz de papéis em `isolation`, `support-access` e `accounts`, tirando as cópias de Work e Equipe; verificar com `rg -n "Auditoria de suporte" openspec/specs` vazio
- [x] 2.2 Corrigir `auth`, `subscriptions`, `serpro-connection`, `serpro-sync`, `monitoring`, `work-templates` e `work-processes` contra o código; verificar com `rg -n "before this change|supersedes|or no accounts|Pentência" openspec/specs` vazio
- [x] 2.3 Fundir as duplicatas de `member-directory`, `client-fiscal-access`, `serpro-sync`, `serpro-connection` e `admin-panel`; verificar com `openspec validate --specs`

## 3. Texto fora das specs

- [x] 3.1 Corrigir no `openspec/config.yaml` a frase "o user só lê Work"; verificar com `rg -n "só lê Work" openspec/config.yaml` vazio
- [x] 3.2 Tirar do `CONTEXT.md` a Procuração e-CAC como cadastro do escritório; verificar com `rg -n "cadastrada" CONTEXT.md openspec/config.yaml` vazio

## 4. Verificação

- [x] 4.1 Conferir que nenhum arquivo de código mudou por esta change; verificar com `git status --short backend frontend` igual ao de antes
- [x] 4.2 Rodar a validação final; verificar com `openspec validate --all`
- [x] 4.3 Arquivar antes de `serpro-contract-and-mailbox`, `client-onboarding-modules` e `departments-fk`; verificar com `openspec archive consolidate-specs`
