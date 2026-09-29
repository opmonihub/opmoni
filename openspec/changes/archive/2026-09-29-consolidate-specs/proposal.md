## Why

As 21 specs vigentes cresceram change a change e acumularam três problemas: regras transversais copiadas em várias specs (isolamento, auditoria de suporte, CRUD por papel), contradições com o código (condição de registro, bloqueio por assinatura, credencial de plataforma versus e-CNPJ do escritório) e justificativa de design escrita dentro de requisito normativo. Um implementador que lê duas specs hoje pode chegar a duas regras diferentes.

## What Changes

- Centralizar as regras transversais: isolamento em `isolation`, auditoria de suporte em `support-access` e a matriz de papéis em `accounts`. As cópias saem das specs de Work e Equipe.
- Declarar que o acesso de suporte tem os mesmos poderes do `admin`, sem exceção, e que toda escrita e todo ato com efeito no provedor (sync, habilitação, ciência da intimação) em modo suporte vai para o log de auditoria.
- Declarar que o `user` lê todos os recursos do tenant e escreve só os próprios filtros salvos.
- Corrigir contra o código: o registro abre só com a base sem users **e** sem accounts; Account suspensa bloqueia leitura e escrita; assinatura que não está ativa, ou ausente, bloqueia só a escrita.
- Separar a Credencial de plataforma do e-CNPJ do escritório e tratar o termo de autorização como do escritório, não do cliente.
- Tirar das specs do termo de autorização o raciocínio sobre a vigência e a canonicalização, mantendo só a regra; o raciocínio passa para o `design.md` desta change.
- Fundir duplicatas em `member-directory`, `client-fiscal-access`, `serpro-sync`, `serpro-connection` e `admin-panel`.
- Explicar os dois `due_day` do modelo e corrigir títulos e erros de digitação em `monitoring` e `work-processes`.

## Capabilities

### New Capabilities

Nenhuma.

### Modified Capabilities

Esta change não tem spec delta (`skip_specs: true`). O OpenSpec 1.11 recusa um bloco MODIFIED que perde cenário, e a limpeza é justamente fundir e tirar cenários. Por isso o texto foi editado direto em `openspec/specs/`, e o diff do git é o registro. Specs editadas:

- `accounts`: matriz de papéis completa.
- `isolation`: a regra vale para todo recurso do tenant.
- `support-access`: poderes de admin sem exceção e auditoria de todo ato.
- `auth`: condição de registro aberto.
- `subscriptions`: vocabulário e bloqueio só de escrita.
- `admin-panel`: telas do Painel Global sem repetir regra comercial.
- `member-directory`: um requisito só.
- `client-fiscal-access`: substituição de certificado sem duplicata.
- `serpro-connection`: credenciais separadas, termo do escritório, regra sem justificativa, segredos em um requisito.
- `serpro-sync`: duplicatas fundidas e vocabulário de estados e contagens documentado.
- `monitoring`: títulos e erros corrigidos.
- `work-templates`: os dois `due_day`; auditoria centralizada.
- `work-processes`: processo manual sem referência a change; CRUD e auditoria centralizados.
- `work-tasks`: auditoria centralizada.
- `team-departments`: CRUD e auditoria centralizados.

## Impact

- Backend: nenhum código muda. Os comportamentos descritos foram conferidos em `app/Http/Controllers/AuthController.php`, `app/Http/Middleware/ResolveTenant.php`, `app/Policies/` e `app/Services/ProcessGenerationService.php`. As lacunas de auditoria encontradas viram tasks de código na `serpro-contract-and-mailbox`.
- Frontend: nenhum código muda.
- Provedor: nenhuma chamada.
- Fora das specs: `openspec/config.yaml` (o `user` não lê só Work) e `CONTEXT.md` (procuração vem do SERPRO).
