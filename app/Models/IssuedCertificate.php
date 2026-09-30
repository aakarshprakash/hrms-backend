<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\VisibleThroughEmployee;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class IssuedCertificate extends Model
{
    use BelongsToCompany, VisibleThroughEmployee, HasFactory;

    protected $fillable = [
        'request_id',
        'template_version_id',
        'employee_id',
        'resolved_html',
        'pdf_path',
        'certificate_number',
        'issued_at',
    ];

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
        ];
    }

    public function request()
    {
        return $this->belongsTo(CertificateRequest::class, 'request_id');
    }

    public function templateVersion()
    {
        return $this->belongsTo(CertificateTemplateVersion::class, 'template_version_id');
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}
