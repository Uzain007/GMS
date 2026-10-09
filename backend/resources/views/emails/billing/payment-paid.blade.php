@extends('emails.layouts.ironcore', ['emailTitle' => $subject, 'preheader' => $preheader])

@section('content')
    <p class="email-copy" style="margin:0 0 12px;color:#6843c2;font-size:11px;font-weight:800;letter-spacing:1.2px;text-transform:uppercase;">{{ $billingLabel }}</p>
    <h1 class="email-heading" style="margin:0 0 20px;color:#191b24;font-size:32px;line-height:39px;letter-spacing:-.7px;overflow-wrap:anywhere;word-wrap:break-word;">{{ $heading }}</h1>
    @php($greetingName = trim((string) ($recipientName ?? '')))
    <p class="email-copy" style="margin:0 0 18px;color:#3f414d;font-size:16px;line-height:25px;">Hello{{ $greetingName !== '' ? ' '.$greetingName : '' }},</p>
    <p class="email-copy" style="margin:0;color:#3f414d;font-size:15px;line-height:24px;">Your payment for {{ $gymName }} has been successfully recorded.{{ $accessRestored ? ' Your gym access has been restored.' : '' }}</p>
    @include('emails.partials.info-card', ['title' => 'Payment confirmation', 'rows' => [
        ['label' => 'Invoice', 'value' => $invoiceNumber],
        ['label' => 'Amount paid', 'value' => $amountPaid],
        ['label' => 'Payment date', 'value' => $paymentDate],
        ['label' => 'Payment method', 'value' => $paymentMethod],
        ['label' => 'Status', 'value' => 'Paid'],
    ]])
    @include('emails.partials.button', ['url' => $actionUrl, 'label' => $actionLabel])
@endsection
