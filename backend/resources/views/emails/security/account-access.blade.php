@extends('emails.layouts.ironcore', ['emailTitle' => $subject, 'preheader' => $preheader])

@section('content')
    <p class="email-copy" style="margin:0 0 12px;color:#6843c2;font-size:11px;font-weight:800;letter-spacing:1.2px;text-transform:uppercase;">{{ $isOwnerInvitation ? 'Gym owner access' : 'Account security' }}</p>
    <h1 class="email-heading" style="margin:0 0 20px;color:#191b24;font-size:32px;line-height:39px;letter-spacing:-.7px;overflow-wrap:anywhere;word-wrap:break-word;">{{ $heading }}</h1>
    @php($greetingName = trim((string) ($recipientName ?? '')))
    <p class="email-copy" style="margin:0 0 18px;color:#3f414d;font-size:16px;line-height:25px;">Hello{{ $greetingName !== '' ? ' '.$greetingName : '' }},</p>
    @if ($isOwnerInvitation)
        <p class="email-copy" style="margin:0 0 18px;color:#3f414d;font-size:15px;line-height:24px;">Your IronCore account gives you secure management access{{ $gymName ? ' to '.$gymName : '' }}. Complete your account setup by creating your password.</p>
        @if ($gymName)
            @include('emails.partials.info-card', ['title' => 'Account access', 'rows' => [
                ['label' => 'Workspace', 'value' => $gymName],
                ['label' => 'Access level', 'value' => 'Gym Owner'],
            ]])
        @endif
    @else
        <p class="email-copy" style="margin:0 0 18px;color:#3f414d;font-size:15px;line-height:24px;">We received a request to reset the password for your IronCore account. Use the secure button below to choose a new password.</p>
    @endif

    @include('emails.partials.button', ['url' => $actionUrl, 'label' => $isOwnerInvitation ? 'Complete account setup' : 'Reset password'])

    <p class="email-copy" style="margin:22px 0 0;color:#5f6270;font-size:13px;line-height:21px;">This secure link expires in {{ $expiresInMinutes }} minutes. If you were not expecting this email, you can safely ignore it.</p>
    <p class="email-copy fallback-link" style="max-width:100%;margin:18px 0 0;color:#858794;font-size:12px;line-height:19px;overflow-wrap:anywhere;word-wrap:break-word;word-break:break-all;">Button not working? Open this link:<br><a href="{{ $actionUrl }}" style="color:#6843c2;text-decoration:underline;overflow-wrap:anywhere;word-wrap:break-word;word-break:break-all;">{{ $actionUrl }}</a></p>
@endsection
