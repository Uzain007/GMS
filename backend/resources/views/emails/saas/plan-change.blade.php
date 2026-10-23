@extends('emails.layouts.ironcore')

@section('content')
    <p class="eyebrow">IronCore subscription</p>
    <h1>{{ $heading }}</h1>
    <p>Hello{{ filled($recipientName) ? ' '.$recipientName : '' }},</p>
    <p>This update concerns the IronCore subscription for <strong>{{ $gymName }}</strong>.</p>
    <table class="info-card" role="presentation" width="100%">
        <tr><td><span class="info-label">Request status</span><br><strong>{{ $status }}</strong></td></tr>
    </table>
    <p class="button-row"><a class="button" href="{{ $billingUrl }}">Review billing</a></p>
@endsection
