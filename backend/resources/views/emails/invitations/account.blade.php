@extends('emails.layouts.ironcore', ['emailTitle' => $subject, 'preheader' => $preheader])

@section('content')
    <p class="email-copy" style="margin:0 0 12px;color:#6843c2;font-size:11px;font-weight:800;letter-spacing:1.2px;text-transform:uppercase;">Account invitation</p>
    <h1 class="email-heading" style="margin:0 0 20px;color:#191b24;font-size:32px;line-height:39px;letter-spacing:-.7px;overflow-wrap:anywhere;word-wrap:break-word;">{{ ($isResend ?? false) ? 'Your invitation link has been renewed' : "You're invited to {$gymName}" }}</h1>
    @php($greetingName = trim((string) ($recipientName ?? '')))
    <p class="email-copy" style="margin:0 0 18px;color:#3f414d;font-size:16px;line-height:25px;">Hello{{ $greetingName !== '' ? ' '.$greetingName : '' }},</p>
    <p class="email-copy" style="margin:0 0 18px;color:#3f414d;font-size:15px;line-height:24px;">{{ ($isResend ?? false) ? 'A new secure activation link is ready' : 'You have been invited' }} to the {{ $portalLabel }} for {{ $gymName }}. Activate your IronCore access using the button below.</p>

    @include('emails.partials.info-card', ['title' => 'Invitation details', 'rows' => array_values(array_filter([
        ['label' => 'Workspace', 'value' => $gymName],
        ['label' => 'Portal', 'value' => $portalLabel],
        $roleLabel ? ['label' => 'Role', 'value' => $roleLabel] : null,
        $expiresAt ? ['label' => 'Expires', 'value' => $expiresAt] : null,
    ]))])

    @include('emails.partials.button', ['url' => $actionUrl, 'label' => 'Activate your account'])
    <p class="email-copy" style="margin:22px 0 0;color:#5f6270;font-size:13px;line-height:21px;">This link is personal and expires automatically. If you were not expecting this invitation, you can safely ignore it.</p>
    <p class="email-copy fallback-link" style="max-width:100%;margin:18px 0 0;color:#858794;font-size:12px;line-height:19px;overflow-wrap:anywhere;word-wrap:break-word;word-break:break-all;">Button not working? Open this link:<br><a href="{{ $actionUrl }}" style="color:#6843c2;text-decoration:underline;overflow-wrap:anywhere;word-wrap:break-word;word-break:break-all;">{{ $actionUrl }}</a></p>
@endsection
