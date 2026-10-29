<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CareTicket;
use App\Models\CareTicketMessage;
use App\Models\Employee;
use App\Models\User;
use App\Notifications\InAppNotification;
use App\Support\Notifications\NotificationMessage;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The HR helpdesk. Employees raise and follow their own tickets; people with
 * helpdesk.manage handle every ticket in their data scope -- reply, add
 * internal notes, assign, prioritise and resolve. Anonymous grievances hide
 * who raised them from everyone but the employee.
 */
class HelpdeskController extends Controller
{
    public function meta(Request $request): JsonResponse
    {
        $handler = $request->user()->can('helpdesk.manage');
        $data = [
            'categories' => collect(CareTicket::CATEGORIES)->map(fn ($l, $k) => ['key' => $k, 'label' => $l])->values(),
            'sla_hours' => CareTicket::SLA,
            'can_handle' => $handler,
        ];

        if ($handler) {
            $tickets = $this->scoped($request->user())->get(['id', 'status', 'assigned_to', 'response_due_at', 'resolution_due_at', 'first_response_at', 'rating', 'type']);
            $open = $tickets->filter(fn ($t) => $t->isOpen());
            $data['handlers'] = $this->handlers()->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->values();
            $data['stats'] = [
                'open' => $open->count(),
                'unassigned' => $open->whereNull('assigned_to')->count(),
                'mine' => $open->where('assigned_to', $request->user()->id)->count(),
                'overdue' => $open->filter(fn ($t) => $t->isOverdue())->count(),
                'grievances' => $open->where('type', 'grievance')->count(),
                'avg_rating' => ($r = $tickets->whereNotNull('rating')->avg('rating')) ? round($r, 1) : null,
            ];
        }

        return response()->json(['data' => $data]);
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $handling = $request->input('scope') === 'all' && $user->can('helpdesk.manage');
        $query = $handling ? $this->scoped($user) : CareTicket::where('employee_id', $this->actorEmployee()?->id ?? 0);

        $query->with(['employee:id,employee_code,first_name,last_name', 'assignee:id,name'])
            ->when($request->filled('status'), fn ($q) => $request->input('status') === 'open'
                ? $q->whereIn('status', CareTicket::OPEN) : $q->where('status', $request->string('status')))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->string('category')))
            ->when($request->input('assigned') === 'me', fn ($q) => $q->where('assigned_to', $user->id))
            ->when($request->input('assigned') === 'none', fn ($q) => $q->whereNull('assigned_to'))
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($w) => $w->where('subject', 'like', '%' . $request->string('search') . '%')
                ->orWhere('number', 'like', '%' . $request->string('search') . '%')))
            ->orderByRaw("FIELD(status, 'open', 'in_progress', 'waiting', 'resolved', 'closed')")
            ->orderByRaw("FIELD(priority, 'urgent', 'high', 'normal', 'low')")
            ->latest('id');

        $page = $query->paginate(min(max($request->integer('per_page', 25), 5), 100));

        return response()->json([
            'data' => collect($page->items())->map(fn ($t) => $this->present($t, $user)),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $employee = $this->actorEmployee();
        abort_unless($employee, 422, 'Only employees can raise helpdesk tickets.');

        $v = $request->validate([
            'type' => ['required', Rule::in(['request', 'grievance'])],
            'category' => ['required', Rule::in(array_keys(CareTicket::CATEGORIES))],
            'subject' => 'required|string|min:4|max:191',
            'description' => 'required|string|min:10|max:5000',
            'priority' => ['nullable', Rule::in(array_keys(CareTicket::SLA))],
            'anonymous' => 'sometimes|boolean',
        ]);

        $priority = $v['priority'] ?? ($v['type'] === 'grievance' ? 'high' : 'normal');
        [$respond, $resolve] = CareTicket::SLA[$priority];

        $ticket = DB::transaction(function () use ($v, $employee, $priority, $respond, $resolve) {
            $companyId = app(TenantContext::class)->id();
            $next = (int) CareTicket::where('company_id', $companyId)->lockForUpdate()->count() + 1;

            return CareTicket::create([
                'branch_id' => $employee->branch_id,
                'employee_id' => $employee->id,
                'number' => sprintf('HD-%05d', $next),
                'type' => $v['type'],
                'category' => $v['category'],
                'subject' => $v['subject'],
                'description' => $v['description'],
                'priority' => $priority,
                'status' => 'open',
                'anonymous' => $v['type'] === 'grievance' && ($v['anonymous'] ?? false),
                'response_due_at' => now()->addHours($respond),
                'resolution_due_at' => now()->addHours($resolve),
            ]);
        });

        $this->notifyUsers($this->handlers($ticket->branch_id), 'helpdesk.created',
            ($ticket->type === 'grievance' ? 'New grievance' : 'New helpdesk ticket') . " {$ticket->number}",
            $ticket->subject, "/helpdesk/{$ticket->id}");

        return response()->json(['data' => $this->present($ticket->fresh(['employee', 'assignee']), $request->user()),
            'message' => "Ticket {$ticket->number} raised — HR will respond within {$respond} hours."], 201);
    }

    public function show(Request $request, CareTicket $ticket): JsonResponse
    {
        $user = $request->user();
        $handler = $this->authorizeTicket($ticket, $user);

        $messages = $ticket->messages()->with('user:id,name,employee_id')->orderBy('id')->get()
            ->reject(fn ($m) => $m->internal && ! $handler)
            ->map(fn ($m) => [
                'id' => $m->id,
                'body' => $m->body,
                'internal' => $m->internal,
                'event' => $m->event,
                'created_at' => $m->created_at?->toIso8601String(),
                // The raiser of an anonymous ticket shows as "Employee" to handlers.
                'author' => $m->user && $ticket->anonymous && $handler && $m->user->employee_id === $ticket->employee_id && $m->user_id !== $user->id
                    ? 'Employee (anonymous)' : ($m->user?->name ?? 'System'),
                'mine' => $m->user_id === $user->id,
                'from_hr' => $m->user && $m->user->employee_id !== $ticket->employee_id,
            ])->values();

        return response()->json(['data' => $this->present($ticket->load(['employee', 'assignee']), $user) + ['messages' => $messages, 'can_handle' => $handler]]);
    }

    public function reply(Request $request, CareTicket $ticket): JsonResponse
    {
        $user = $request->user();
        $handler = $this->authorizeTicket($ticket, $user);
        $v = $request->validate(['body' => 'required|string|min:1|max:5000', 'internal' => 'sometimes|boolean']);
        $internal = $handler && ($v['internal'] ?? false);
        $isRaiser = $user->employee_id === $ticket->employee_id;
        abort_if($ticket->status === 'closed', 422, 'This ticket is closed. Raise a new one if you still need help.');

        CareTicketMessage::create(['care_ticket_id' => $ticket->id, 'user_id' => $user->id, 'body' => $v['body'], 'internal' => $internal]);

        if (! $internal) {
            if ($isRaiser) {
                // The employee answered: back in HR's court.
                $ticket->update(['status' => in_array($ticket->status, ['waiting', 'resolved'], true) ? 'open' : $ticket->status, 'resolved_at' => null]);
                $this->notifyUsers($ticket->assigned_to ? User::whereKey($ticket->assigned_to)->get() : $this->handlers($ticket->branch_id),
                    'helpdesk.reply', "Reply on {$ticket->number}", $ticket->subject, "/helpdesk/{$ticket->id}");
            } else {
                $ticket->update([
                    'first_response_at' => $ticket->first_response_at ?? now(),
                    'status' => $ticket->status === 'open' ? 'in_progress' : $ticket->status,
                    'assigned_to' => $ticket->assigned_to ?? $user->id,
                ]);
                $this->notifyRaiser($ticket, "HR replied on {$ticket->number}", $ticket->subject);
            }
        }

        return $this->show($request, $ticket->fresh());
    }

    /** Handler: status, priority, assignee. */
    public function update(Request $request, CareTicket $ticket): JsonResponse
    {
        $user = $request->user();
        abort_unless($this->authorizeTicket($ticket, $user), 403, 'Only HR can change a ticket.');
        $v = $request->validate([
            'status' => ['sometimes', Rule::in(['open', 'in_progress', 'waiting', 'resolved', 'closed'])],
            'priority' => ['sometimes', Rule::in(array_keys(CareTicket::SLA))],
            'assigned_to' => ['sometimes', 'nullable', 'integer'],
            'note' => 'nullable|string|max:2000',
        ]);
        if (array_key_exists('assigned_to', $v) && $v['assigned_to'] !== null) {
            abort_unless($this->handlers()->contains('id', $v['assigned_to']), 422, 'Assign to someone who handles the helpdesk.');
        }

        $changes = [];
        $lines = [];
        if (isset($v['status']) && $v['status'] !== $ticket->status) {
            $changes['status'] = $v['status'];
            $changes['resolved_at'] = $v['status'] === 'resolved' ? now() : ($v['status'] === 'closed' ? ($ticket->resolved_at ?? now()) : null);
            $changes['closed_at'] = $v['status'] === 'closed' ? now() : null;
            $lines[] = 'Status: ' . str_replace('_', ' ', $v['status']);
        }
        if (isset($v['priority']) && $v['priority'] !== $ticket->priority) {
            $changes['priority'] = $v['priority'];
            $changes['resolution_due_at'] = $ticket->created_at->copy()->addHours(CareTicket::SLA[$v['priority']][1]);
            $lines[] = "Priority: {$v['priority']}";
        }
        if (array_key_exists('assigned_to', $v) && $v['assigned_to'] !== $ticket->assigned_to) {
            $changes['assigned_to'] = $v['assigned_to'];
            $lines[] = 'Assigned to ' . ($v['assigned_to'] ? User::find($v['assigned_to'])?->name : 'nobody');
        }

        DB::transaction(function () use ($ticket, $changes, $lines, $user, $v) {
            $ticket->update($changes);
            if ($lines) {
                CareTicketMessage::create(['care_ticket_id' => $ticket->id, 'user_id' => $user->id, 'body' => implode(' · ', $lines), 'event' => 'updated']);
            }
            if (! empty($v['note'])) {
                CareTicketMessage::create(['care_ticket_id' => $ticket->id, 'user_id' => $user->id, 'body' => $v['note'], 'internal' => false]);
                $ticket->update(['first_response_at' => $ticket->first_response_at ?? now()]);
            }
        });

        if (($changes['status'] ?? null) === 'resolved') {
            $this->notifyRaiser($ticket, "{$ticket->number} resolved", 'Tell us how we did, or reply if it isn’t sorted.');
        }
        if (! empty($changes['assigned_to']) && $changes['assigned_to'] !== $user->id) {
            $this->notifyUsers(User::whereKey($changes['assigned_to'])->get(), 'helpdesk.assigned', "{$ticket->number} assigned to you", $ticket->subject, "/helpdesk/{$ticket->id}");
        }

        return $this->show($request, $ticket->fresh());
    }

    /** The employee rates a resolved ticket, which closes it. */
    public function rate(Request $request, CareTicket $ticket): JsonResponse
    {
        abort_unless($request->user()->employee_id === $ticket->employee_id, 403);
        abort_unless($ticket->status === 'resolved', 422, 'You can rate a ticket once it’s resolved.');
        $v = $request->validate(['rating' => 'required|integer|min:1|max:5', 'comment' => 'nullable|string|max:500']);

        $ticket->update(['rating' => $v['rating'], 'rating_comment' => $v['comment'] ?? null, 'status' => 'closed', 'closed_at' => now()]);
        CareTicketMessage::create(['care_ticket_id' => $ticket->id, 'user_id' => $request->user()->id, 'body' => "Rated {$v['rating']}/5 and closed", 'event' => 'rated']);

        return $this->show($request, $ticket->fresh());
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /** Tickets of employees in the handler's data scope. */
    private function scoped(User $user)
    {
        return CareTicket::query()->whereIn('employee_id', Employee::visibleTo($user)->select('employees.id'));
    }

    /** True when $user handles this ticket, false when they raised it; 404 otherwise. */
    private function authorizeTicket(CareTicket $ticket, User $user): bool
    {
        if ($user->can('helpdesk.manage') && $this->scoped($user)->whereKey($ticket->id)->exists()) {
            return true;
        }
        abort_unless($user->employee_id && $user->employee_id === $ticket->employee_id, 404, 'Ticket not found.');

        return false;
    }

    private function present(CareTicket $t, User $viewer): array
    {
        $hidden = $t->anonymous && $viewer->employee_id !== $t->employee_id;

        return $t->only(['id', 'number', 'type', 'category', 'subject', 'description', 'priority', 'status', 'anonymous', 'rating', 'rating_comment']) + [
            'category_label' => CareTicket::CATEGORIES[$t->category] ?? $t->category,
            'employee' => $hidden ? ['name' => 'Anonymous', 'employee_code' => null] : ['name' => $t->employee?->full_name, 'employee_code' => $t->employee?->employee_code, 'id' => $t->employee_id],
            'assignee' => $t->assignee?->only(['id', 'name']),
            'created_at' => $t->created_at?->toIso8601String(),
            'response_due_at' => $t->response_due_at?->toIso8601String(),
            'resolution_due_at' => $t->resolution_due_at?->toIso8601String(),
            'first_response_at' => $t->first_response_at?->toIso8601String(),
            'resolved_at' => $t->resolved_at?->toIso8601String(),
            'overdue' => $t->isOverdue(),
        ];
    }

    /** People who handle the helpdesk (optionally those who can act in a branch). */
    private function handlers(?int $branchId = null)
    {
        return User::where('company_id', app(TenantContext::class)->id())->where('is_active', true)->get()
            ->filter(fn (User $u) => ($u->isTenantAdmin() || $u->hasPermissionTo('helpdesk.manage')) && (! $branchId || $u->canAccessBranch($branchId)))
            ->values();
    }

    private function notifyRaiser(CareTicket $ticket, string $title, string $body): void
    {
        $this->notifyUsers(User::where('employee_id', $ticket->employee_id)->get(), 'helpdesk.update', $title, $body, "/helpdesk/{$ticket->id}");
    }

    private function notifyUsers($users, string $event, string $title, string $body, string $link): void
    {
        $actor = request()->user()?->id;
        foreach ($users as $u) {
            if ($u->id !== $actor) {
                $u->notify(new InAppNotification(new NotificationMessage($event, $title, $body, $link)));
            }
        }
    }
}
