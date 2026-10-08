@extends('emails.layouts.ironcore', ['emailTitle' => $subject, 'preheader' => $preheader])

@section('content')
    <p class="email-copy" style="margin:0 0 12px;color:{{ $overdue ? '#a33a35' : '#805c08' }};font-size:11px;font-weight:800;letter-spacing:1.2px;text-transform:uppercase;">Subscription billing</p>
    <h1 class="email-heading" style="margin:0 0 20px;color:#191b24;font-size:32px;line-height:39px;letter-spacing:-.7px;overflow-wrap:anywhere;word-wrap:break-word;">{{ $overdue ? 'Your subscription payment is overdue' : 'Your subscription payment is due' }}</h1>
    @php($greetingName = trim((string) ($recipientName ?? '')))
    <p class="email-copy" style="margin:0 0 18px;color:#3f414d;font-size:16px;line-height:25px;">Hello{{ $greetingName !== '' ? ' '.$greetingName : '' }},</p>
    <p class="email-copy" style="margin:0;color:#3f414d;font-size:15px;line-height:24px;">Your IronCore subscription invoice for {{ $gymName }} {{ $overdue ? 'is overdue' : 'is due today' }}.</p>
    @include('emails.partials.info-card', ['title' => 'Payment summary', 'rows' => [
        ['label' => 'Invoice', 'value' => $invoiceNumber],
        ['label' => 'Amount due', 'value' => $amount],
        ['label' => 'Due date', 'value' => $dueDate],
        ['label' => 'Status', 'value' => $overdue ? 'Overdue' : 'Payment due'],
    ]])
    <p class="email-copy" style="margin:0;color:#5f6270;font-size:13px;line-height:21px;">{{ $warning }}</p>
    @include('emails.partials.button', ['url' => $billingUrl, 'label' => 'Review billing'])
@endsection
