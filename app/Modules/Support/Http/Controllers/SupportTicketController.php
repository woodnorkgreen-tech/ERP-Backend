<?php

namespace App\Modules\Support\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Support\Http\Requests\StoreSupportTicketRequest;
use App\Modules\Support\Http\Requests\UpdateSupportTicketRequest;
use App\Modules\Support\Http\Resources\SupportTicketResource;
use App\Modules\Support\Models\SupportTicket;
use App\Modules\Support\Models\SupportTicketAttachment;
use App\Modules\Support\Services\SupportMetricsService;
use App\Modules\Support\Services\SupportTicketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SupportTicketController extends Controller
{
    public function __construct(
        private readonly SupportTicketService $service,
        private readonly SupportMetricsService $metrics,
    ) {}

    public function metrics(Request $request): JsonResponse
    {
        $this->authorize('viewAny', SupportTicket::class);
        return response()->json(['data' => $this->metrics->forUser($request->user())]);
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', SupportTicket::class);
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(SupportTicket::STATUSES)],
            'priority' => ['nullable', Rule::in(SupportTicket::PRIORITIES)],
            'type' => ['nullable', Rule::in(SupportTicket::TYPES)],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
            'scope' => ['nullable', Rule::in(['mine', 'assigned_to_me', 'unassigned', 'all'])],
            'overdue' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $query = SupportTicket::query()
            ->visibleTo($request->user())
            ->with(['reporter:id,name,email', 'assignee:id,name,email'])
            ->withCount(['attachments', 'messages'])
            ->when($validated['search'] ?? null, function ($query, string $search) {
                $query->where(fn ($nested) => $nested
                    ->where('ticket_number', 'like', "%{$search}%")
                    ->orWhere('subject', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhereHas('reporter', fn ($reporter) => $reporter->where('name', 'like', "%{$search}%")));
            })
            ->when($validated['priority'] ?? null, fn ($query, $priority) => $query->where('priority', $priority))
            ->when($validated['type'] ?? null, fn ($query, $type) => $query->where('type', $type))
            ->when($validated['assigned_to'] ?? null, fn ($query, $assignedTo) => $query->where('assigned_to', $assignedTo));

        if ($request->boolean('overdue')) {
            $query->whereNotIn('status', ['waiting_on_user', 'resolved', 'closed'])
                ->where('resolution_due_at', '<', now());
        }

        $metricsQuery = clone $query;

        if (($validated['scope'] ?? null) === 'assigned_to_me') {
            $query->where('assigned_to', $request->user()->id);
        } elseif (($validated['scope'] ?? null) === 'unassigned') {
            $query->whereNull('assigned_to')->whereNotIn('status', ['resolved', 'closed']);
        } elseif (($validated['scope'] ?? null) === 'mine') {
            $query->where('reporter_id', $request->user()->id);
        }

        // Keep the overview meaningful while a status filter is active. These
        // totals respect visibility, search, scope, type, priority and assignee.
        $statusTotals = (clone $query)
            ->select('status')
            ->selectRaw('COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $query->when($validated['status'] ?? null, fn ($builder, $status) => $builder->where('status', $status));

        $tickets = $query
            ->orderByRaw("FIELD(priority, 'urgent', 'high', 'normal', 'low')")
            ->orderByDesc('last_activity_at')
            ->paginate($validated['per_page'] ?? 20);

        return response()->json([
            'data' => SupportTicketResource::collection($tickets->getCollection()),
            'meta' => [
                'current_page' => $tickets->currentPage(),
                'last_page' => $tickets->lastPage(),
                'per_page' => $tickets->perPage(),
                'total' => $tickets->total(),
            ],
            'summary' => [
                'total' => $statusTotals->sum(),
                'open' => collect(['open', 'assigned'])->sum(fn ($status) => (int) ($statusTotals[$status] ?? 0)),
                'active' => collect(['in_progress', 'waiting_on_user'])->sum(fn ($status) => (int) ($statusTotals[$status] ?? 0)),
                'done' => collect(['resolved', 'closed'])->sum(fn ($status) => (int) ($statusTotals[$status] ?? 0)),
            ],
            'metrics' => [
                'unassigned' => (clone $metricsQuery)->whereNull('assigned_to')->whereNotIn('status', ['resolved', 'closed'])->count(),
                'urgent' => (clone $metricsQuery)->where('priority', 'urgent')->whereNotIn('status', ['resolved', 'closed'])->count(),
                'waiting_on_user' => (clone $metricsQuery)->where('status', 'waiting_on_user')->count(),
                'assigned_to_me' => (clone $metricsQuery)->where('assigned_to', $request->user()->id)->whereNotIn('status', ['resolved', 'closed'])->count(),
                'overdue' => (clone $metricsQuery)->whereNotIn('status', ['waiting_on_user', 'resolved', 'closed'])->where('resolution_due_at', '<', now())->count(),
            ],
        ]);
    }

    public function store(StoreSupportTicketRequest $request): JsonResponse
    {
        $ticket = $this->service->create($request->user(), $request->validated(), $request->file('attachments', []));
        return response()->json([
            'message' => "Ticket {$ticket->ticket_number} submitted successfully.",
            'data' => new SupportTicketResource($this->loadTicket($ticket)),
        ], 201);
    }

    public function show(Request $request, SupportTicket $ticket): JsonResponse
    {
        $this->authorize('view', $ticket);
        return response()->json(['data' => new SupportTicketResource($this->loadTicket($ticket))]);
    }

    public function update(UpdateSupportTicketRequest $request, SupportTicket $ticket): JsonResponse
    {
        $ticket = $this->service->update($ticket, $request->user(), $request->validated());
        return response()->json(['message' => 'Ticket updated successfully.', 'data' => new SupportTicketResource($this->loadTicket($ticket))]);
    }

    public function reply(Request $request, SupportTicket $ticket): JsonResponse
    {
        $this->authorize('reply', $ticket);
        abort_if($ticket->status === 'closed', 422, 'Reopen this ticket before adding a reply.');
        $validated = $request->validate([
            'message' => ['required', 'string', 'min:2', 'max:5000'],
            'is_internal' => ['nullable', 'boolean'],
            'action' => ['nullable', Rule::in(['keep', 'waiting_on_user', 'resolved'])],
            'attachments' => ['nullable', 'array', 'max:3'],
            'attachments.*' => ['file', 'max:10240', 'mimes:jpg,jpeg,png,webp,pdf,doc,docx,xls,xlsx,txt,csv'],
        ]);
        $internal = (bool) ($validated['is_internal'] ?? false);
        if ($internal) $this->authorize('addInternalNote', $ticket);
        $action = $validated['action'] ?? 'keep';
        if ($internal || $action !== 'keep') $this->authorize('update', $ticket);
        $ticket = $this->service->reply($ticket, $request->user(), $validated['message'], $internal, $action, $request->file('attachments', []));
        return response()->json(['message' => $internal ? 'Internal note added.' : 'Reply sent.', 'data' => new SupportTicketResource($this->loadTicket($ticket))]);
    }

    public function uploadAttachment(Request $request, SupportTicket $ticket): JsonResponse
    {
        $this->authorize('reply', $ticket);
        abort_if($ticket->status === 'closed' && !$request->user()->can('support.manage') && !$request->user()->hasRole(['Super Admin', 'Admin']), 422, 'Closed tickets cannot receive new attachments.');
        $validated = $request->validate(['attachment' => ['required', 'file', 'max:10240', 'mimes:jpg,jpeg,png,webp,pdf,doc,docx,xls,xlsx,txt,csv']]);
        abort_if($ticket->attachments()->count() >= 10, 422, 'A ticket can contain at most 10 attachments.');
        $attachment = $this->service->addAttachment($ticket, $request->user(), $validated['attachment']);
        return response()->json(['message' => 'Attachment uploaded.', 'data' => ['id' => $attachment->id, 'name' => $attachment->original_name]], 201);
    }

    public function confirmResolution(Request $request, SupportTicket $ticket): JsonResponse
    {
        $this->authorize('view', $ticket);
        $ticket = $this->service->confirmResolution($ticket, $request->user());
        return response()->json(['message' => 'Resolution confirmed. Ticket closed.', 'data' => new SupportTicketResource($this->loadTicket($ticket))]);
    }

    public function downloadAttachment(Request $request, SupportTicket $ticket, SupportTicketAttachment $attachment): StreamedResponse
    {
        $this->authorize('downloadAttachment', $ticket);
        abort_unless($attachment->support_ticket_id === $ticket->id, 404);
        $internalAttachment = $attachment->message()->where('is_internal', true)->exists();
        $canManage = $request->user()->hasRole(['Super Admin', 'Admin']) || $request->user()->can('support.manage');
        abort_if($internalAttachment && !$canManage, 403, 'This attachment belongs to an internal note.');
        abort_unless(Storage::disk($attachment->disk)->exists($attachment->path), 404, 'Attachment file not found.');
        return Storage::disk($attachment->disk)->download($attachment->path, $attachment->original_name, ['Content-Type' => $attachment->mime_type]);
    }

    public function assignees(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasRole(['Super Admin', 'Admin']) || $request->user()->can('support.manage'), 403);
        $users = User::query()->active()
            ->where(function ($query) {
                $query->whereHas('roles', fn ($roles) => $roles->whereIn('name', ['Super Admin', 'Admin']))
                    ->orWhereHas('permissions', fn ($permissions) => $permissions->where('name', 'support.manage'))
                    ->orWhereHas('roles.permissions', fn ($permissions) => $permissions->where('name', 'support.manage'));
            })
            ->orderBy('name')->get(['id', 'name', 'email']);
        return response()->json(['data' => $users]);
    }

    private function loadTicket(SupportTicket $ticket): SupportTicket
    {
        return $ticket->fresh([
            'reporter:id,name,email', 'assignee:id,name,email', 'resolver:id,name',
            'attachments:id,support_ticket_id,message_id,uploaded_by,original_name,mime_type,size,created_at',
            'attachments.message:id,is_internal',
            'messages' => fn ($query) => $query->with(['author:id,name', 'attachments:id,support_ticket_id,message_id,original_name,mime_type,size,created_at'])->oldest(),
            'activities' => fn ($query) => $query->with('actor:id,name')->latest(),
        ]);
    }
}
