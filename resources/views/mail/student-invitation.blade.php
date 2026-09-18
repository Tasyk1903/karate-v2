<p>{{ __('mobile_students.invitation_body', ['coach' => $coachName], $language) }}</p>
<p><strong>{{ $coachCode }}</strong></p>
<p>{{ __('mobile_registration.student', [], $language) }}</p>
<p>{{ __('mobile_registration.existing', [], $language) }}</p>
@include('mail.partials.mobile-install')
