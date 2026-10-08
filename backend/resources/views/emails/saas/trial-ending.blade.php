@extends('emails.layouts.ironcore', ['emailTitle' => $subject, 'preheader' => $preheader])

@section('content')
    <p class="email-copy" style="margin:0 0 12px;color:#6843c2;font-size:11px;font-weight:800;letter-spacing:1.2px;text-transform:uppercase;">Subscription update</p>
    <h1 class="email-heading" style="margin:0 0 20px;color:#191b24;font-size:32px;line-height:39px;letter-spacing:-.7px;overflow-wrap:anywhere;word-wrap:break-word;">Your IronCore trial ends tomorrow</h1>
    @php($greetingName = trim((string) ($recipientName ?? '')))
    <p class="email-copy" style="margin:0 0 18px;color:#3f414d;font-size:16px;line-height:25px;">Hello{{ $greetingName !== '' ? ' '.$greetingName : '' }},</p>
    <p class="email-copy" style="margin:0;color:#3f414d;font-size:15px;line-height:24px;">Select a plan and complete payment to keep normal gym operations available for {{ $gymName }}.</p>
    @include('emails.partials.info-card', ['title' => 'Trial summary', 'rows' => [
        ['label' => 'Workspace', 'value' => $gymName],
        ['label' => 'Trial ends', 'value' => $trialEndsAt],
        ['label' => 'Status', 'value' => 'Trial ending'],
    ]])
    @include('emails.partials.button', ['url' => $billingUrl, 'label' => 'Review plans and payment'])
@endsection
