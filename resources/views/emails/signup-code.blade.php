@component('mail::message')
# Verify your email

Use this code to finish creating your {{ config('app.name') }} workspace:

@component('mail::panel')
<p style="font-size: 28px; font-weight: 700; letter-spacing: 8px; text-align: center; margin: 0;">{{ $code }}</p>
@endcomponent

The code expires in {{ $minutes }} minutes. If you didn't try to sign up, you can ignore this email.

Thanks,<br>
{{ config('app.name') }}
@endcomponent
