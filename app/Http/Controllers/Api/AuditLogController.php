<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The organisation's audit trail (Activity is tenant-scoped, so only this
 * organisation's entries are ever visible).
 */
class AuditLogController extends Controller
{
    /** Friendly names for subject types, used by the UI filter too. */
    private const SUBJECTS = [
        'employee' => \App\Models\Employee::class,
        'salary_structure' => \App\Models\SalaryStructure::class,
        'salary_component' => \App\Models\SalaryComponent::class,
        'statutory_rule' => \App\Models\StatutoryRule::class,
        'payroll_run' => \App\Models\PayrollRun::class,
        'payroll_adjustment' => \App\Models\PayrollRunAdjustment::class,
        'leave' => \App\Models\Leave::class,
        'attendance' => \App\Models\Attendance::class,
        'user' => \App\Models\User::class,
        'role' => \App\Models\Role::class,
        'company' => \App\Models\Company::class,
        'branch' => \App\Models\Branch::class,
    ];

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'log' => ['nullable', 'string', 'max:40'],
            'subject' => ['nullable', 'string', 'in:' . implode(',', array_keys(self::SUBJECTS))],
            'subject_id' => ['nullable', 'integer'],
            'causer_id' => ['nullable', 'integer'],
            'event' => ['nullable', 'string', 'max:40'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Activity::with('causer:id,name,email')->latest('id');

        if (! empty($validated['log'])) {
            $query->where('log_name', $validated['log']);
        }
        if (! empty($validated['subject'])) {
            $query->where('subject_type', self::SUBJECTS[$validated['subject']]);
        }
        if (! empty($validated['subject_id'])) {
            $query->where('subject_id', $validated['subject_id']);
        }
        if (! empty($validated['causer_id'])) {
            $query->where('causer_id', $validated['causer_id']);
        }
        if (! empty($validated['event'])) {
            $query->where('event', $validated['event']);
        }
        if (! empty($validated['from'])) {
            $query->where('created_at', '>=', $validated['from']);
        }
        if (! empty($validated['to'])) {
            $query->where('created_at', '<=', $validated['to'] . ' 23:59:59');
        }

        $page = $query->paginate($validated['per_page'] ?? 30);

        return response()->json([
            'data' => collect($page->items())->map(fn (Activity $a) => $this->present($a)),
            'meta' => [
                'total' => $page->total(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
            ],
            'subjects' => array_keys(self::SUBJECTS),
        ]);
    }

    /** History of one employee's record (profile, salary, leave). */
    public function employee(Employee $employee): JsonResponse
    {
        $this->authorize('update', $employee);

        $structureIds = \App\Models\SalaryStructure::where('employee_id', $employee->id)->pluck('id');

        $entries = Activity::with('causer:id,name')
            ->where(function ($q) use ($employee, $structureIds) {
                $q->where(fn ($s) => $s->where('subject_type', Employee::class)->where('subject_id', $employee->id))
                    ->orWhere(fn ($s) => $s->where('subject_type', \App\Models\SalaryStructure::class)->whereIn('subject_id', $structureIds));
            })
            ->latest('id')
            ->limit(200)
            ->get();

        return response()->json(['data' => $entries->map(fn (Activity $a) => $this->present($a))]);
    }

    private function present(Activity $activity): array
    {
        $subjectKey = array_search($activity->subject_type, self::SUBJECTS, true);

        return [
            'id' => $activity->id,
            'log' => $activity->log_name,
            'event' => $activity->event,
            'description' => $activity->description,
            'subject' => $subjectKey ?: class_basename((string) $activity->subject_type),
            'subject_id' => $activity->subject_id,
            'causer' => $activity->causer ? ['id' => $activity->causer->id, 'name' => $activity->causer->name] : null,
            'changes' => [
                'old' => $activity->properties['old'] ?? null,
                'new' => $activity->properties['attributes'] ?? null,
            ],
            'sensitive_changed' => $activity->properties['sensitive_changed'] ?? [],
            'created_at' => $activity->created_at,
        ];
    }
}
