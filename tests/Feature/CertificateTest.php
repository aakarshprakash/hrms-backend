<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CertificateRequest;
use App\Models\CertificateTemplate;
use App\Models\Company;
use App\Models\Department;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\IssuedCertificate;
use App\Models\User;
use App\Services\CertificateResolverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CertificateTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;
    protected User $hrUser;
    protected Company $company;
    protected Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        Role::firstOrCreate(['name' => 'hr', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'employee', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);

        $this->company = Company::factory()->create();
        $this->branch  = Branch::factory()->create(['company_id' => $this->company->id]);

        $this->hrUser = User::factory()->create([
            'branch_id'    => $this->branch->id,
            'is_super_admin' => false,
        ]);
        $this->hrUser->assignRole('hr');

        $department  = Department::create(['branch_id' => $this->branch->id, 'name' => 'Engineering']);
        $designation = Designation::create([
            'branch_id'     => $this->branch->id,
            'department_id' => $department->id,
            'title'         => 'Software Engineer',
        ]);

        $this->employee = Employee::factory()->create([
            'branch_id'      => $this->branch->id,
            'department_id'  => $department->id,
            'designation_id' => $designation->id,
            'first_name'     => 'John',
            'last_name'      => 'Doe',
        ]);
    }

    public function test_html_sanitization_strips_script_tags(): void
    {
        $this->actingAs($this->hrUser);

        $response = $this->postJson('/api/certificate-templates', [
            'branch_id' => $this->branch->id,
            'name'      => 'Test Template',
            'type'      => 'experience',
            'html_body' => '<script>alert(\'xss\')</script><p>Hello</p>',
            'status'    => 'draft',
        ]);

        $response->assertStatus(201);

        $template = CertificateTemplate::first();

        $this->assertStringNotContainsString('<script>', $template->html_body);
        $this->assertStringContainsString('<p>Hello</p>', $template->html_body);
    }

    public function test_placeholder_resolution_substitutes_employee_data(): void
    {
        $resolver = app(CertificateResolverService::class);

        $html   = '<p>Dear {{employee_name}}, your designation is {{designation}}.</p>';
        $result = $resolver->resolve($html, $this->employee);

        $this->assertStringContainsString('John Doe', $result);
        $this->assertStringContainsString('Software Engineer', $result);
        $this->assertEquals(
            '<p>Dear John Doe, your designation is Software Engineer.</p>',
            $result
        );
    }

    public function test_issued_certificate_html_unchanged_after_template_edit(): void
    {
        $this->actingAs($this->hrUser);

        // Create and publish template
        $template = CertificateTemplate::create([
            'branch_id' => $this->branch->id,
            'name'      => 'Experience Letter',
            'type'      => 'experience',
            'html_body' => '<p>Hello {{employee_name}}</p>',
            'status'    => 'published',
            'created_by' => $this->hrUser->id,
        ]);

        // Submit certificate request
        $request = CertificateRequest::create([
            'employee_id'  => $this->employee->id,
            'template_id'  => $template->id,
            'status'       => 'pending',
            'requested_by' => $this->hrUser->id,
        ]);

        // Approve it
        $this->postJson("/api/certificate-requests/{$request->id}/approve")
            ->assertStatus(200);

        // Update the template
        $this->putJson("/api/certificate-templates/{$template->id}", [
            'html_body' => '<p>Goodbye {{employee_name}}</p>',
        ])->assertStatus(200);

        // Assert issued certificate still has old content
        $issued = IssuedCertificate::first();
        $this->assertNotNull($issued);
        $this->assertStringContainsString('Hello', $issued->resolved_html);
        $this->assertStringNotContainsString('Goodbye', $issued->resolved_html);
    }

    public function test_public_verify_returns_only_safe_fields(): void
    {
        $this->actingAs($this->hrUser);

        $template = CertificateTemplate::create([
            'branch_id'  => $this->branch->id,
            'name'       => 'Joining Letter',
            'type'       => 'joining',
            'html_body'  => '<p>Welcome {{employee_name}}</p>',
            'status'     => 'published',
            'created_by' => $this->hrUser->id,
        ]);

        $request = CertificateRequest::create([
            'employee_id'  => $this->employee->id,
            'template_id'  => $template->id,
            'status'       => 'pending',
            'requested_by' => $this->hrUser->id,
        ]);

        $this->postJson("/api/certificate-requests/{$request->id}/approve")
            ->assertStatus(200);

        $issued = IssuedCertificate::first();
        $this->assertNotNull($issued);

        // Call public verify without auth
        $this->app['auth']->forgetGuards();

        $response = $this->getJson("/api/verify/{$issued->certificate_number}");

        $response->assertStatus(200);
        $response->assertJsonStructure(['valid', 'type', 'issued_at', 'employee_name', 'branch']);
        $response->assertJsonMissing(['resolved_html']);
        $response->assertJsonMissing(['pdf_path']);
        $response->assertJsonMissing(['email']);
        $response->assertJson(['valid' => true]);
    }

    public function test_branch_isolation_on_templates(): void
    {
        // Create a second branch and user
        $company2 = Company::factory()->create();
        $branch2  = Branch::factory()->create(['company_id' => $company2->id]);
        $user2    = User::factory()->create([
            'branch_id'      => $branch2->id,
            'is_super_admin' => false,
        ]);
        $user2->assignRole('hr');

        // Create template in branch1
        CertificateTemplate::create([
            'branch_id'  => $this->branch->id,
            'name'       => 'Branch1 Template',
            'type'       => 'experience',
            'html_body'  => '<p>Hello</p>',
            'status'     => 'published',
            'created_by' => $this->hrUser->id,
        ]);

        // User2 from branch2 should NOT see branch1 templates
        $this->actingAs($user2);

        $response = $this->getJson('/api/certificate-templates');
        $response->assertStatus(200);

        $templates = $response->json();
        $this->assertEmpty($templates, 'Branch 2 user should not see Branch 1 templates');
    }
}
