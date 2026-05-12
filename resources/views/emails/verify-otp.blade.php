@component('mail::message')
# Email Verification

Hello,

Your email verification code is:

@component('mail::panel')
{{ $otp }}
@endcomponent

This code will expire in 10 minutes. Do not share this code with anyone.

Thanks,<br>
{{ config('app.name') }}
@endcomponent
