<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CertificateTemplateVersion extends Model
{
    use HasFactory;

    public $timestamps = false;
    const CREATED_AT = 'created_at';

    protected $fillable = [
        'template_id',
        'html_body',
        'header_html',
        'footer_html',
        'version_no',
    ];

    public function getUpdatedAtAttribute(): null
    {
        return null;
    }

    public function template()
    {
        return $this->belongsTo(CertificateTemplate::class, 'template_id');
    }

    public function issuedCertificates()
    {
        return $this->hasMany(IssuedCertificate::class, 'template_version_id');
    }
}
