@extends('emails.layouts.ironcore', ['emailTitle' => $subject, 'preheader' => $preheader])

@section('content')
    <p class="email-copy" style="margin:0 0 12px;color:#6843c2;font-size:11px;font-weight:800;letter-spacing:1.2px;text-transform:uppercase;">{{ $billingLabel }}</p>
    <h1 class="email-heading" style="margin:0 0 20px;color:#191b24;font-size:32px;line-height:39px;letter-spacing:-.7px;overflow-wrap:anywhere;word-wrap:break-word;">{{ $heading }}</h1>
    @php($greetingName = trim((string) ($recipientName ?? '')))
    <p class="email-copy" style="margin:0 0 18px;color:#3f414d;font-size:16px;line-height:25px;">Hello{{ $greetingName !== '' ? ' '.$greetingName : '' }},</p>
    <p class="email-copy" style="margin:0;color:#3f414d;font-size:15px;line-height:24px;">A new invoice for {{ $gymName }} is ready to review.</p>
    @include('emails.partials.info-card', ['title' => 'Invoice summary', 'rows' => [
        ['label' => 'Invoice', 'value' => $invoiceNumber],
        ['label' => 'Amount', 'value' => $amount],
        ['label' => 'Due date', 'value' => $dueDate],
        ['label' => 'Status', 'value' => $status],
    ]])
    @include('emails.partials.button', ['url' => $actionUrl, 'label' => $actionLabel])
@endsection
