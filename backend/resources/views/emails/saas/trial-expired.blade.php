@extends('emails.layouts.ironcore', ['emailTitle' => $subject, 'preheader' => $preheader])

@section('content')
    <p style="margin:0 0 12px;color:#a33a35;font-size:11px;font-weight:800;letter-spacing:1.2px;text-transform:uppercase;">Payment required</p>
    <h1 class="email-heading" style="margin:0 0 20px;color:#191b24;font-size:32px;line-height:39px;letter-spacing:-.7px;overflow-wrap:anywhere;word-wrap:break-word;">Your IronCore trial has ended</h1>
    @php($greetingName = trim((string) ($recipientName ?? '')))
    <p class="email-copy" style="margin:0 0 18px;color:#3f414d;font-size:16px;line-height:25px;">Hello{{ $greetingName !== '' ? ' '.$greetingName : '' }},</p>
    <p class="email-copy" style="margin:0;color:#3f414d;font-size:15px;line-height:24px;">Normal gym operations for {{ $gymName }} are now restricted. You can still sign in, select a plan and complete payment to restore access.</p>
    <div style="margin:24px 0 0;">@include('emails.partials.status-badge', ['status' => 'Restricted', 'tone' => 'danger'])</div>
    @include('emails.partials.button', ['url' => $billingUrl, 'label' => 'Restore access'])
@endsection
