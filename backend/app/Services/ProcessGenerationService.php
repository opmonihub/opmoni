<?php

namespace App\Services;

use App\Enums\TaskStatus;
use App\Models\Client;
use App\Models\Process;
use App\Models\ProcessTemplate;
use App\Models\ProcessTemplateTask;
use App\Models\Task;
use App\Tenant\CurrentTenant;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessGenerationService
{
    /**
     * @return Collection<int, Process>
     */
    public function generate(ProcessTemplate $template, Carbon $month): Collection
    {
        $reference = $month->copy()->startOfMonth()->startOfDay();
        $referenceDate = $reference->toDateString();

        // Congelamento: se o mês já foi gerado, a elegibilidade atual não
        // reavalia nem recria nada (regime/tag/blueprint mudam só os
        // próximos meses); retorna os processos congelados do mês.
        $frozen = Process::withoutGlobalScope('account')->where([
            'account_id' => $template->account_id,
            'template_id' => $template->getKey(),
        ])->whereDate('reference_month', $referenceDate)->orderBy('id')->get();

        if ($frozen->isNotEmpty()) {
            return $frozen;
        }

        $clients = $this->eligibleClients($template);
        $steps = $template->steps()->orderBy('order')->get();

        return $clients->map(fn (Client $client): Process => DB::transaction(function () use ($template, $client, $reference, $referenceDate, $steps): Process {
            $process = Process::withoutGlobalScope('account')->where([
                'account_id' => $template->account_id,
                'template_id' => $template->getKey(),
                'client_id' => $client->getKey(),
            ])->whereDate('reference_month', $referenceDate)->lockForUpdate()->first();

            if ($process instanceof Process) {
                return $process;
            }

            try {
                $process = Process::query()->create([
                    'name' => $template->name.' '.$reference->format('m/Y'),
                    'template_id' => $template->getKey(),
                    'client_id' => $client->getKey(),
                    'reference_month' => $reference->toDateString(),
                    'status' => 'open',
                    'due_on' => $this->resolveDueDate($reference, (int) $template->due_day),
                ]);
            } catch (QueryException $e) {
                if (! $this->isUniqueViolation($e)) {
                    throw $e;
                }

                $existing = Process::withoutGlobalScope('account')->where([
                    'account_id' => $template->account_id,
                    'template_id' => $template->getKey(),
                    'client_id' => $client->getKey(),
                ])->whereDate('reference_month', $referenceDate)->lockForUpdate()->first();

                if ($existing instanceof Process) {
                    return $existing;
                }

                throw $e;
            }

            $now = now()->toDateTimeString();
            // Task::insert bypassa o cast `date` do Eloquent, que serializa como
            // `Y-m-d H:i:s`; formatar igual preserva o estado observável no banco.
            $rows = $steps->map(fn (ProcessTemplateTask $step): array => [
                'account_id' => $template->account_id,
                'process_id' => $process->getKey(),
                'title' => $step->title,
                'department_id' => $step->department_id,
                'description' => $step->description,
                'status' => TaskStatus::Todo->value,
                'due_on' => Carbon::parse($this->resolveDueDate($reference, (int) $step->due_day))->toDateTimeString(),
                'priority' => $step->priority,
                'assignee_member_id' => $this->resolveAssignee($template->account_id, $step),
                'order' => $step->order,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all();

            if ($rows !== []) {
                Task::insert($rows);
            }

            return $process;
        }));
    }

    /**
     * @return Collection<int, array{client: Client, reason: string}>
     */
    public function preview(ProcessTemplate $template): Collection
    {
        return $this->eligibleClients($template)->map(fn (Client $client): array => [
            'client' => $client,
            'reason' => $this->matchReason($template, $client),
        ]);
    }

    /** @return Collection<int, Client> */
    public function eligibleClients(ProcessTemplate $template): Collection
    {
        resolve(CurrentTenant::class)->accountId = $template->account_id;

        $regimes = $template->regimes ?? [];
        $tagIds = $template->tags()->pluck('tags.id')->all();
        $removed = $template->exceptions()->where('kind', 'removed')->pluck('client_id')->all();
        $added = $template->exceptions()->where('kind', 'added')->pluck('client_id')->all();

        $base = Client::query()
            ->where('status', 'active')
            ->when($regimes !== [], fn ($query) => $query->whereIn('tax_regime', $regimes))
            ->when($tagIds !== [], fn ($query) => $query->whereHas('tags', fn ($tags) => $tags->whereKey($tagIds)))
            ->when($removed !== [], fn ($query) => $query->whereNotIn('clients.id', $removed))
            ->orderBy('name')->get();

        if ($added === []) {
            return $base;
        }

        $extra = Client::query()->whereKey($added)->where('status', 'active')->orderBy('name')->get();

        return $base->merge($extra)->unique('id')->values();
    }

    private function matchReason(ProcessTemplate $template, Client $client): string
    {
        $added = $template->exceptions()->where('kind', 'added')->pluck('client_id')->all();

        return in_array($client->getKey(), $added, true) ? 'added' : 'rule';
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        $code = (string) $e->getCode();
        $previous = $e->getPrevious();

        if ($previous instanceof \PDOException && is_string($previous->getCode())) {
            $code = $previous->getCode();
        }

        if (in_array($code, ['23505', '23000', '19'], true)) {
            return true;
        }

        return str_contains(strtolower($e->getMessage()), 'unique constraint');
    }

    private function resolveDueDate(Carbon $reference, int $day): string
    {
        $capped = min(max($day, 1), $reference->daysInMonth);

        return $reference->copy()->day($capped)->toDateString();
    }

    /**
     * A geração agendada não pode falhar o mês inteiro por um responsável
     * fora do departamento (ex.: saiu do time após a escrita do modelo):
     * gera a task com responsável nulo e registra auditoria em log.
     */
    private function resolveAssignee(int $accountId, ProcessTemplateTask $step): ?int
    {
        $assignee = $step->default_assignee_member_id;

        if ($assignee === null) {
            return null;
        }

        if ($step->department_id !== null && ! DepartmentMembership::memberBelongs($accountId, (int) $step->department_id, (int) $assignee)) {
            Log::warning('work.generation.assignee_outside_department', [
                'account_id' => $accountId,
                'template_id' => $step->template_id,
                'step_id' => $step->getKey(),
                'department_id' => $step->department_id,
                'assignee_member_id' => (int) $assignee,
            ]);

            return null;
        }

        return (int) $assignee;
    }
}
