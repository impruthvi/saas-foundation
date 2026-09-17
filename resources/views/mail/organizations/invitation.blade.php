<x-mail::message>
# {{ __('You have been invited to join :organization', ['organization' => $organizationName]) }}

{{ __('Accepting this invitation adds you to :organization. You will still keep your own workspace.', ['organization' => $organizationName]) }}

<x-mail::button :url="$acceptUrl">
{{ __('Accept invitation') }}
</x-mail::button>

{{ __('This invitation expires on :date. If it lapses, ask whoever invited you to send a new one.', ['date' => $invitation->expires_at->toFormattedDateString()]) }}

{{ __('If you were not expecting this, you can ignore this email — nothing happens until you accept.') }}

{{ __('Thanks') }},<br>
{{ config('app.name') }}
</x-mail::message>
