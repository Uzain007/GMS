@extends('emails.layouts.ironcore', ['emailTitle' => $subject, 'preheader' => $preheader])

@section('content')
    <p class="email-copy" style="margin:0 0 12px;color:{{ $restricted ? '#a33a35' : '#6843c2' }};font-size:11px;font-weight:800;letter-spacing:1.2px;text-transform:uppercase;">{{ $billingLabel }}</p>
    <h1 class="email-heading" style="margin:0 0 20px;color:#191b24;font-size:32px;line-height:39px;letter-spacing:-.7px;overflow-wrap:anywhere;word-wrap:break-word;">{{ $heading }}</h1>
    @php($greetingName = trim((string) ($recipientName ?? '')))
    <p class="email-copy" style="margin:0 0 18px;color:#3f414d;font-size:16px;line-height:25px;">Hello{{ $greetingName !== '' ? ' '.$greetingName : '' }},</p>
    @php($accessLabel = $accessNoun ?? 'gym access')
    <p class="email-copy" style="margin:0;color:#3f414d;font-size:15px;line-height:24px;">{{ $restricted ? 'Your '.$accessLabel.' is restricted because this invoice remains outstanding.' : 'Your payment has been confirmed and your '.$accessLabel.' is restored.' }}</p>
    @include('emails.partials.info-card', ['title' => 'Account status', 'rows' => [
        ['label' => 'Gym', 'value' => $gymName],
        ['label' => 'Invoice', 'value' => $invoiceNumber],
        ['label' => 'Status', 'value' => $restricted ? 'Payment required' : 'Access restored'],
    ]])
    @include('emails.partials.button', ['url' => $actionUrl, 'label' => $actionLabel])
@endsection
