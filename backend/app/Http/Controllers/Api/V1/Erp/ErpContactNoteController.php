<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Erp\ErpCatalogWriteService;
use App\Erp\ErpContactNoteService;
use App\Http\Controllers\Controller;
use App\Models\Erp\ErpContact;
use App\Models\Erp\ErpContactAttachment;
use App\Models\Erp\ErpContactNote;
use App\Models\User;
use App\Support\CurrentCompany;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

class ErpContactNoteController extends Controller
{
    private const SERVER_MANAGED_FIELDS = [
        'created_by', 'user_id', 'author_id', 'erp_company_id', 'company_id', 'account_id',
        'notable_type', 'notable_id', 'model', 'disk', 'path', 'storage_key', 'original_name',
        'mime_type', 'byte_length', 'sha256',
    ];

    public function __construct(
        private ErpCatalogWriteService $writes,
        private ErpContactNoteService $notes,
    ) {}

    public function index(Request $request, int $contact): JsonResponse
    {
        $context = CurrentCompany::fromRequest($request);
        $this->findContact((int) $context->company->id, $contact);
        $filters = Validator::make($request->query(), [
            'state' => ['sometimes', 'string', Rule::in(['active', 'deletion_pending'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ])->validate();
        $state = $filters['state'] ?? 'active';
        $perPage = (int) ($filters['per_page'] ?? 25);
        $companyId = (int) $context->company->id;
        $actorId = (int) $request->user()->getAuthIdentifier();
        $attachmentStates = $state === 'active' ? ['ready', 'unavailable'] : ['deletion_pending'];

        $query = ErpContactNote::query()
            ->where('erp_company_id', $companyId)
            ->where('erp_contact_id', $contact)
            ->visibleTo($actorId);

        if ($state === 'active') {
            $query->where('state', 'active');
        } else {
            $query->where(function (Builder $pending) use ($companyId): void {
                $pending->where('state', 'deletion_pending')
                    ->orWhereHas('attachments', function (Builder $attachments) use ($companyId): void {
                        $attachments->where('erp_company_id', $companyId)
                            ->where('status', 'deletion_pending');
                    });
            });
        }

        $page = $query
            ->withCount(['attachments as attachments_count' => function (Builder $attachments) use ($companyId, $attachmentStates): void {
                $attachments->where('erp_company_id', $companyId)->whereIn('status', $attachmentStates);
            }])
            ->orderByDesc('id')
            ->paginate($perPage);

        return response()->json([
            'data' => $page->getCollection()->map(fn (ErpContactNote $note): array => $this->serializeNote($note, actorId: $actorId))->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'state' => $state,
            ],
        ]);
    }

    public function store(Request $request, int $contact): JsonResponse
    {
        $data = $this->validatedNotePayload($request);

        return $this->write($request, function (CurrentCompany $context) use ($request, $contact, $data): JsonResponse {
            $contactRow = $this->findActiveContact((int) $context->company->id, $contact);
            $note = $this->notes->createNote((int) $context->company->id, (int) $contactRow->getKey(), $this->actor($request), $data);

            return response()->json(['data' => $this->serializeNote($note, 0, (int) $request->user()->getAuthIdentifier())], 201);
        });
    }

    public function show(Request $request, int $contact, int $note): JsonResponse
    {
        $context = CurrentCompany::fromRequest($request);
        $noteRow = $this->findVisibleNote($request, (int) $context->company->id, $contact, $note);
        $filters = Validator::make($request->query(), [
            'attachments_per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ])->validate();
        $perPage = (int) ($filters['attachments_per_page'] ?? 25);
        $attachments = ErpContactAttachment::query()
            ->where('erp_company_id', $context->company->id)
            ->where('erp_contact_note_id', $noteRow->getKey())
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'attachments_page');

        $pendingAttachmentCount = ErpContactAttachment::query()
            ->where('erp_company_id', $context->company->id)
            ->where('erp_contact_note_id', $noteRow->getKey())
            ->where('status', 'deletion_pending')
            ->count();
        $activeAttachmentCount = ErpContactAttachment::query()
            ->where('erp_company_id', $context->company->id)
            ->where('erp_contact_note_id', $noteRow->getKey())
            ->whereIn('status', ['ready', 'unavailable'])
            ->count();
        $data = $this->serializeNote(
            $noteRow,
            $noteRow->state === 'deletion_pending' ? $pendingAttachmentCount : $activeAttachmentCount,
            (int) $request->user()->getAuthIdentifier(),
        );
        $data['deletion_pending_attachments_count'] = $pendingAttachmentCount;
        $data['attachments'] = $attachments->getCollection()
            ->map(fn (ErpContactAttachment $attachment): array => $this->serializeAttachment($attachment))
            ->all();

        return response()->json([
            'data' => $data,
            'meta' => [
                'attachments' => [
                    'current_page' => $attachments->currentPage(),
                    'last_page' => $attachments->lastPage(),
                    'per_page' => $attachments->perPage(),
                    'total' => $attachments->total(),
                ],
            ],
        ]);
    }

    public function update(Request $request, int $contact, int $note): JsonResponse
    {
        $data = $this->validatedNotePayload($request);

        return $this->write($request, function (CurrentCompany $context) use ($request, $contact, $note, $data): JsonResponse {
            $noteRow = $this->findVisibleNote($request, (int) $context->company->id, $contact, $note);
            $updated = $this->notes->updateNote($noteRow, $this->actor($request), $data);
            $activeAttachmentCount = ErpContactAttachment::query()
                ->where('erp_company_id', $context->company->id)
                ->where('erp_contact_note_id', $updated->getKey())
                ->whereIn('status', ['ready', 'unavailable'])
                ->count();

            return response()->json(['data' => $this->serializeNote(
                $updated,
                $activeAttachmentCount,
                (int) $request->user()->getAuthIdentifier(),
            )]);
        });
    }

    public function archive(Request $request, int $contact, int $note): JsonResponse
    {
        return $this->write($request, function (CurrentCompany $context) use ($request, $contact, $note): JsonResponse {
            $companyId = (int) $context->company->id;
            $noteRow = $this->findVisibleNote($request, $companyId, $contact, $note);
            $this->notes->archiveNote($companyId, $noteRow);

            return response()->json([
                'code' => 'deletion_requested',
                'message' => 'A remoção da anotação foi solicitada.',
                'data' => ['id' => $noteRow->getKey(), 'state' => 'deletion_pending'],
            ], 202);
        });
    }

    public function storeAttachment(Request $request, int $contact, int $note): JsonResponse
    {
        $rules = ['file' => ['required', 'file']];
        foreach (self::SERVER_MANAGED_FIELDS as $field) {
            $rules[$field] = ['missing'];
        }
        $data = Validator::make($request->all(), $rules, [
            'file.required' => 'Selecione um arquivo para anexar.',
            'file.file' => 'O arquivo enviado não é válido.',
        ])->validate();

        return $this->write($request, function (CurrentCompany $context) use ($request, $contact, $note, $data): JsonResponse {
            $companyId = (int) $context->company->id;
            $this->findActiveContact($companyId, $contact);
            $noteRow = $this->findVisibleNote($request, $companyId, $contact, $note);
            $result = $this->notes->storeAttachment($companyId, $noteRow, $data['file']);
            $responseData = $this->serializeAttachment($result['attachment']);

            if (! $result['available']) {
                return response()->json([
                    'code' => 'attachment_unavailable',
                    'message' => 'O arquivo não pôde ser verificado no armazenamento. Tente enviá-lo novamente.',
                    'data' => $responseData,
                ], 503);
            }

            return response()->json(['data' => $responseData], 201);
        });
    }

    public function downloadAttachment(Request $request, int $contact, int $note, int $attachment): Response
    {
        $context = CurrentCompany::fromRequest($request);
        $companyId = (int) $context->company->id;
        $noteRow = $this->findVisibleNote($request, $companyId, $contact, $note);
        $attachmentRow = $this->findAttachment($companyId, $noteRow, $attachment);

        if ($noteRow->state === 'deletion_pending') {
            return response()->json(['code' => 'note_deletion_pending', 'message' => 'A anotação está aguardando remoção.'], 409);
        }
        if ($attachmentRow->status === 'deletion_pending') {
            return response()->json(['code' => 'attachment_deletion_pending', 'message' => 'O arquivo está aguardando remoção.'], 409);
        }
        if ($attachmentRow->status !== 'ready') {
            return $this->unavailableResponse();
        }

        $bytes = $this->notes->verifiedAttachmentBytes($companyId, $noteRow, $attachmentRow);
        if ($bytes === null) {
            return $this->unavailableResponse();
        }

        $fallbackName = preg_replace('/[^A-Za-z0-9._-]/', '_', $attachmentRow->original_name) ?: 'document';
        $disposition = HeaderUtils::makeDisposition('attachment', $attachmentRow->original_name, $fallbackName);

        return response($bytes, 200, [
            'Content-Type' => $attachmentRow->mime_type,
            'Content-Disposition' => $disposition,
            'Content-Length' => (string) strlen($bytes),
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function archiveAttachment(Request $request, int $contact, int $note, int $attachment): JsonResponse
    {
        return $this->write($request, function (CurrentCompany $context) use ($request, $contact, $note, $attachment): JsonResponse {
            $companyId = (int) $context->company->id;
            $noteRow = $this->findVisibleNote($request, $companyId, $contact, $note);
            $attachmentRow = $this->findAttachment($companyId, $noteRow, $attachment);
            $this->notes->archiveAttachment($companyId, $noteRow, $attachmentRow);

            return response()->json([
                'code' => 'deletion_requested',
                'message' => 'A remoção do arquivo foi solicitada.',
                'data' => ['id' => $attachmentRow->getKey(), 'status' => 'deletion_pending'],
            ], 202);
        });
    }

    /** @return array<string, mixed> */
    private function validatedNotePayload(Request $request): array
    {
        $rules = [
            'heading' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'is_private' => ['sometimes', 'boolean'],
        ];
        foreach (self::SERVER_MANAGED_FIELDS as $field) {
            $rules[$field] = ['missing'];
        }

        $messages = [
            'heading.string' => 'O título deve ser um texto.',
            'heading.max' => 'O título deve ter no máximo 255 caracteres.',
            'description.string' => 'A anotação deve ser um texto.',
            'description.max' => 'A anotação deve ter no máximo 10.000 caracteres.',
            'is_private.boolean' => 'A opção de privacidade deve ser verdadeira ou falsa.',
        ];
        foreach (self::SERVER_MANAGED_FIELDS as $field) {
            $messages[$field.'.missing'] = 'Este campo é preenchido pelo sistema.';
        }

        $data = Validator::make($request->all(), $rules, $messages)->validate();
        if (array_key_exists('is_private', $data)) {
            $data['is_private'] = (bool) $data['is_private'];
        }

        return $data;
    }

    private function findContact(int $companyId, int $contactId): ErpContact
    {
        return ErpContact::query()
            ->where('erp_company_id', $companyId)
            ->whereKey($contactId)
            ->firstOrFail();
    }

    private function findActiveContact(int $companyId, int $contactId): ErpContact
    {
        return ErpContact::query()
            ->where('erp_company_id', $companyId)
            ->where('status', 'active')
            ->whereKey($contactId)
            ->firstOrFail();
    }

    private function findVisibleNote(Request $request, int $companyId, int $contactId, int $noteId): ErpContactNote
    {
        $this->findContact($companyId, $contactId);

        return ErpContactNote::query()
            ->where('erp_company_id', $companyId)
            ->where('erp_contact_id', $contactId)
            ->whereKey($noteId)
            ->visibleTo((int) $request->user()->getAuthIdentifier())
            ->firstOrFail();
    }

    private function findAttachment(int $companyId, ErpContactNote $note, int $attachmentId): ErpContactAttachment
    {
        return ErpContactAttachment::query()
            ->where('erp_company_id', $companyId)
            ->where('erp_contact_note_id', $note->getKey())
            ->whereKey($attachmentId)
            ->firstOrFail();
    }

    private function actor(Request $request): User
    {
        /** @var User $actor */
        $actor = $request->user();

        return $actor;
    }

    /** @return array<string, mixed> */
    private function serializeNote(ErpContactNote $note, ?int $attachmentCount = null, ?int $actorId = null): array
    {
        return [
            'id' => $note->getKey(),
            'heading' => $note->heading,
            'description' => $note->description,
            'is_private' => $note->is_private,
            'state' => $note->state,
            'can_change_privacy' => $note->state === 'active'
                && $actorId !== null
                && (int) $note->created_by === $actorId,
            'attachments_count' => $attachmentCount ?? (int) ($note->attachments_count ?? 0),
            'created_at' => $note->created_at?->toISOString(),
            'updated_at' => $note->updated_at?->toISOString(),
        ];
    }

    /** @return array{id: int, original_name: string, mime_type: string, byte_length: int, sha256: string, status: string} */
    private function serializeAttachment(ErpContactAttachment $attachment): array
    {
        return [
            'id' => (int) $attachment->getKey(),
            'original_name' => $attachment->original_name,
            'mime_type' => $attachment->mime_type,
            'byte_length' => (int) $attachment->byte_length,
            'sha256' => $attachment->sha256,
            'status' => $attachment->status,
        ];
    }

    private function unavailableResponse(): JsonResponse
    {
        return response()->json([
            'code' => 'attachment_unavailable',
            'message' => 'O arquivo não está disponível ou não passou na verificação de integridade. Tente novamente mais tarde.',
        ], 503);
    }

    /** @param Closure(CurrentCompany): JsonResponse $operation */
    private function write(Request $request, Closure $operation): JsonResponse
    {
        try {
            return $this->writes->run($request, $operation);
        } catch (QueryException) {
            return response()->json([
                'code' => 'contact_note_unavailable',
                'message' => 'Não foi possível salvar a anotação ou o arquivo. Tente novamente mais tarde.',
            ], 503);
        }
    }
}
