@component('mail::message')
# {{ $heading }}

@foreach ($lines as $line)
{{ $line }}

@endforeach
@if ($url)
@component('mail::button', ['url' => $url])
{{ $actionLabel }}
@endcomponent
@endif

Thanks,<br>
{{ $companyName ?? config('app.name') }}

<small>You're receiving this because email notifications are on for your organisation. You can turn them off for yourself under Account → Notifications.</small>
@endcomponent
