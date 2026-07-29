<?php

namespace App\Models;

use App\Traits\HasBranchScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CertificateTemplate extends Model
{
    use HasFactory, HasBranchScope;

    protected $fillable = [
        'branch_id',
        'name',
        'type',
        'html_body',
        'header_html',
        'footer_html',
        'logo_path',
        'signature_path',
        'status',
        'created_by',
    ];

    public function versions()
    {
        return $this->hasMany(CertificateTemplateVersion::class, 'template_id');
    }

    public function requests()
    {
        return $this->hasMany(CertificateRequest::class, 'template_id');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
