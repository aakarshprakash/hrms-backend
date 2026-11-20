<?php

namespace App\Services\Users;

use App\Mail\NotificationMail;
use App\Models\Employee;
use App\Models\User;
use App\Support\Access\RoleGrants;
use App\Support\Access\Roles;
use App\Support\Mail\SmtpSettings;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Resets someone's password on behalf of an administrator, in one step: a new password (generated, or
 * typed by the admin), the person is forced to change it at their next sign-in, every session they have
 * is ended, it is recorded in the audit trail, and the new password is emailed (through the
 * organisation's own mail server when it has one).
 *
 * Who may be reset follows the same rule as managing users: a tenant admin can reset anyone in the
 * organisation; a branch-bound admin only people in their own branches, and never a tenant admin. You
 * cannot reset your own password here -- that is "Change password".
 */
class PasswordResetter
{
    /** Without look-alikes (0/O, 1/l/I) so a temporary password can be read out or typed from a printout. */
    private const LOWER = 'abcdefghijkmnpqrstuvwxyz';

    private const UPPER = 'ABCDEFGHJKLMNPQRSTUVWXYZ';

    private const DIGITS = '23456789';

    public function __construct(private readonly TenantContext $context)
    {
    }

    /**
     * @return array{email: string, password: ?string, email_sent: bool, created: bool}
     *         `password` is returned only when it was generated, so the admin can pass it on.
     */
    public function reset(User $actor, User $target, string $mode = 'auto', ?string $password = null, bool $sendEmail = true): array
    {
        $this->assertCanReset($actor, $target);
        [$plain, $generated] = $this->choose($mode, $password);

        $target->forceFill([
            'password' => Hash::make($plain),
            'password_changed_at' => now(),
            'must_change_password' => true,
        ])->save();
        $target->tokens()->delete();

        $emailed = $sendEmail && $this->email($target->email, $target->name, $plain, created: false);

        $this->audit($actor, $target, 'password_reset', 'Password reset by administrator', $generated, $emailed);

        return ['email' => $target->email, 'password' => $generated ? $plain : null, 'email_sent' => $emailed, 'created' => false];
    }

    /**
     * Quick reset from an employee's profile. Someone who has no login yet gets one (employee role,
     * email as the username) so the same button also covers "they can't sign in at all".
     *
     * @return array{email: string, password: ?string, email_sent: bool, created: bool}
     */
    public function resetForEmployee(User $actor, Employee $employee, string $mode = 'auto', ?string $password = null, bool $sendEmail = true): array
    {
        if ($employee->user) {
            return $this->reset($actor, $employee->user, $mode, $password, $sendEmail);
        }

        $email = strtolower((string) $employee->email);
        if ($email === '') {
            throw ValidationException::withMessages(['email' => 'This employee has no email address, so a login can’t be created. Add one on their profile first.']);
        }
        // Emails are unique across every organisation, so look beyond this tenant.
        if ($this->context->withoutScoping(fn () => User::where('email', $email)->exists())) {
            throw ValidationException::withMessages(['email' => 'That email is already used by another account.']);
        }

        $role = RoleGrants::assignable($actor, Roles::EMPLOYEE);
        [$plain, $generated] = $this->choose($mode, $password);

        $user = User::create([
            'name' => $employee->full_name,
            'email' => $email,
            'phone' => $employee->phone,
            'password' => Hash::make($plain),
            'user_type' => 'employee',
            'employee_id' => $employee->id,
            'branch_id' => $employee->branch_id,
            'must_change_password' => true,
            'password_changed_at' => now(),
        ]);
        $user->assignRole($role);
        $employee->update(['user_id' => $user->id]);

        $emailed = $sendEmail && $this->email($email, $employee->full_name, $plain, created: true);

        $this->audit($actor, $user, 'login_created', 'Login created by administrator', $generated, $emailed);

        return ['email' => $email, 'password' => $generated ? $plain : null, 'email_sent' => $emailed, 'created' => true];
    }

    /** Request rules shared by both reset endpoints. @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'mode' => ['sometimes', 'in:auto,manual'],
            'password' => ['required_if:mode,manual', 'nullable', 'string', Password::min(8)->letters()->numbers()],
            'send_email' => ['sometimes', 'boolean'],
        ];
    }

    /** A readable temporary password: 10 characters with upper case, lower case and digits. */
    public static function generate(int $length = 10): string
    {
        $pick = fn (string $set): string => $set[random_int(0, strlen($set) - 1)];
        $chars = [$pick(self::UPPER), $pick(self::LOWER), $pick(self::DIGITS)];
        $all = self::UPPER . self::LOWER . self::DIGITS;

        while (count($chars) < $length) {
            $chars[] = $pick($all);
        }
        shuffle($chars);

        return implode('', $chars);
    }

    // ── Internals ─────────────────────────────────────────────────────────

    /** @return array{0: string, 1: bool} [plain password, was it generated] */
    private function choose(string $mode, ?string $password): array
    {
        if ($mode === 'manual') {
            return [(string) $password, false];
        }

        return [self::generate(), true];
    }

    private function assertCanReset(User $actor, User $target): void
    {
        abort_if($actor->id === $target->id, 422, 'Use “Change password” for your own account.');

        if ($actor->isTenantAdmin() || $actor->isPlatformAdmin()) {
            return;
        }

        $branchIds = $actor->accessibleBranchIds();
        if ($branchIds !== null) {
            $inScope = User::whereKey($target->id)->where(function ($q) use ($branchIds) {
                $q->whereIn('branch_id', $branchIds)
                    ->orWhereHas('extraBranches', fn ($b) => $b->whereIn('branches.id', $branchIds));
            })->exists();
            abort_unless($inScope, 404, 'User not found.');
        }

        abort_if($target->isTenantAdmin(), 403, 'Only a tenant admin can reset another tenant admin’s password.');
    }

    private function email(string $to, string $name, string $plain, bool $created): bool
    {
        try {
            $company = $this->context->company();
            $sender = SmtpSettings::forCompany($this->context->id(), $company?->name);
            $login = rtrim((string) config('app.frontend_url'), '/') . '/login';

            $mail = new NotificationMail(
                mailSubject: $created ? 'Your ' . config('app.name') . ' login details' : 'Your password was reset',
                heading: $created ? 'Your login is ready' : 'Your password was reset',
                lines: [
                    "Hi {$name},",
                    $created
                        ? 'An administrator has created your login. Sign in with this email address and the temporary password below.'
                        : 'An administrator has reset your password. Sign in with the temporary password below.',
                    "Temporary password: {$plain}",
                    'You will be asked to choose a new password the first time you sign in. If you did not expect this email, tell your HR team.',
                ],
                link: $login,
                actionLabel: 'Sign in',
                companyName: $company?->name,
            );
            $mail->from($sender['from_address'], $sender['from_name']);
            ($sender['mailer'] ? Mail::mailer($sender['mailer']) : Mail::mailer())->to($to)->send($mail);

            return true;
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
    }

    private function audit(User $actor, User $target, string $event, string $message, bool $generated, bool $emailed): void
    {
        // The password itself is never written to the audit trail.
        activity('security')->causedBy($actor)->performedOn($target)->event($event)
            ->withProperties(['generated' => $generated, 'emailed' => $emailed])->log($message);
    }
}
