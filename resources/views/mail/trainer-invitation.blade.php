<h1>{{ __('team.invitation_title', [], $language) }}</h1>
<p>{{ $organization->name ?: $organization->full_name }}</p>
<p>{{ __('team.organization_code', [], $language) }}: <strong>{{ $organizationCode }}</strong></p>
<p>{{ __('mobile_registration.trainer', [], $language) }}</p>
<p>{{ __('mobile_registration.existing', [], $language) }}</p>
@include('mail.partials.mobile-install')
