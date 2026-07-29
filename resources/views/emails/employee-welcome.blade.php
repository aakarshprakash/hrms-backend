@component('mail::message')
# Welcome, {{ $employeeName }}! 👋

An account has been created for you on **{{ config('app.name') }}**. You can use it to view your attendance, apply for leave, download payslips and more.

@component('mail::panel')
**Login Email:** {{ $loginEmail }}<br>
**Temporary Password:** `{{ $password }}`
@endcomponent

For your security, please log in and change this password as soon as possible.

@component('mail::button', ['url' => $loginUrl])
Log In Now
@endcomponent

If you weren't expecting this email, please contact your HR administrator.

Thanks,<br>
{{ config('app.name') }}
@endcomponent
