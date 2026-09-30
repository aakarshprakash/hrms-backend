<?php

namespace App\Services\Tenancy;

use App\Models\ApprovalFlow;
use App\Services\ApprovalWorkflowService;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Designation;
use App\Models\LeaveType;
use App\Models\OvertimeRule;
use App\Models\SalaryComponent;
use App\Models\Scopes\BranchScope;
use App\Models\Shift;
use App\Models\StatutoryRule;
use App\Support\Payroll\StatutoryDefaults;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Applies an industry starter template (config/industry_templates.php) to a
 * branch. Idempotent: anything that already exists by name is left alone,
 * so it doubles as "fill in the gaps" for a branch set up by hand.
 */
class IndustryTemplateService
{
    public const SECTIONS = ['departments', 'designations', 'shifts', 'leave_types', 'salary_components', 'statutory_rules'];

    public static function templateKeyFor(?string $industry): string
    {
        return array_key_exists((string) $industry, config('industry_templates')) ? $industry : 'general';
    }

    public static function available(): array
    {
        return collect(config('industry_templates'))
            ->map(fn ($t, $key) => ['key' => $key, 'label' => $t['label']])
            ->values()
            ->all();
    }

    /**
     * @return array{created: array<string,int>, skipped: array<string,int>}
     */
    public function apply(Branch $branch, ?string $industry = null): array
    {
        $key = self::templateKeyFor($industry ?? $branch->company?->industry);
        $template = config("industry_templates.{$key}");

        $created = array_fill_keys(self::SECTIONS, 0);
        $skipped = array_fill_keys(self::SECTIONS, 0);
        $tally = function (string $section, bool $wasCreated) use (&$created, &$skipped) {
            $wasCreated ? $created[$section]++ : $skipped[$section]++;
        };

        DB::transaction(function () use ($branch, $template, $tally) {
            foreach ($template['departments'] as $deptName => $designations) {
                [$department, $new] = $this->upsert(Department::class, ['branch_id' => $branch->id, 'name' => $deptName]);
                $tally('departments', $new);

                foreach ($designations as [$title, $level]) {
                    [, $new] = $this->upsert(Designation::class,
                        ['branch_id' => $branch->id, 'department_id' => $department->id, 'title' => $title],
                        ['level' => $level]);
                    $tally('designations', $new);
                }
            }

            foreach ($template['shifts'] as $shift) {
                [, $new] = $this->upsert(Shift::class, ['branch_id' => $branch->id, 'name' => $shift['name']], $shift);
                $tally('shifts', $new);
            }

            foreach ($template['leave_types'] as $type) {
                [, $new] = $this->upsert(LeaveType::class, ['branch_id' => $branch->id, 'name' => $type['name']], $type);
                $tally('leave_types', $new);
            }

            foreach ($template['salary_components'] as $component) {
                [, $new] = $this->upsert(SalaryComponent::class, ['branch_id' => $branch->id, 'name' => $component['name']], $component);
                $tally('salary_components', $new);
            }

            foreach ($template['statutory'] as $rule) {
                [, $new] = $this->upsert(StatutoryRule::class,
                    ['branch_id' => $branch->id, 'rule_type' => $rule['rule_type']],
                    ['country' => 'IN', 'config_json' => $rule['config_json'], 'is_active' => true]);
                $tally('statutory_rules', $new);
            }

            // Professional tax where the state levies it, and income tax (TDS).
            $state = $branch->state ?: $branch->company?->state;
            if (StatutoryDefaults::ptFor($state)) {
                [, $new] = $this->upsert(StatutoryRule::class, ['branch_id' => $branch->id, 'rule_type' => 'PT'],
                    ['country' => 'IN', 'config_json' => StatutoryDefaults::config('PT', $state), 'is_active' => true]);
                $tally('statutory_rules', $new);
            }
            [, $new] = $this->upsert(StatutoryRule::class, ['branch_id' => $branch->id, 'rule_type' => 'TAX'],
                ['country' => 'IN', 'config_json' => StatutoryDefaults::config('TAX'), 'is_active' => true]);
            $tally('statutory_rules', $new);

            $this->upsert(OvertimeRule::class, ['branch_id' => $branch->id],
                ['daily_threshold_hours' => 9, 'weekly_threshold_hours' => 54, 'rate_multiplier' => 2]);

            // Leave/OT/regularization go to the reporting manager (HR and
            // admins can act on their behalf); a branch can add steps later.
            foreach (['leave', 'overtime', 'regularization'] as $module) {
                $this->upsert(ApprovalFlow::class, ['branch_id' => $branch->id, 'module' => $module],
                    ['steps_json' => ApprovalWorkflowService::DEFAULT_STEPS]);
            }

            if ($branch->week_off_days === null) {
                $branch->update(['week_off_days' => $template['week_off_days']]);
            }
        });

        return compact('created', 'skipped');
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @return array{0: Model, 1: bool}
     */
    private function upsert(string $modelClass, array $search, array $attributes = []): array
    {
        $existing = $modelClass::withoutGlobalScope(BranchScope::class)->where($search)->first();

        if ($existing) {
            return [$existing, false];
        }

        $model = new $modelClass;
        $fillable = array_flip($model->getFillable());
        $model->fill(array_intersect_key(array_merge($attributes, $search), $fillable));
        $model->save();

        return [$model, true];
    }
}
