<?php

namespace Tests\Feature;

use App\Mail\BrandedTransactionalMail;
use Tests\TestCase;

class BrandedEmailTemplatesTest extends TestCase
{
    public function test_shared_layout_is_responsive_and_email_safe(): void
    {
        $html = $this->render('emails.security.account-access', [
            'subject' => 'Reset your IronCore password',
            'preheader' => 'Use this secure link to reset your password.',
            'heading' => 'Reset your password',
            'recipientName' => 'Alex',
            'gymName' => null,
            'isOwnerInvitation' => false,
            'actionUrl' => 'https://app.ironcore.website/#reset_email=alex%40example.test&reset_token=secure-token',
            'expiresInMinutes' => 60,
        ]);

        $this->assertStringContainsString('IRONCORE', $html);
        $this->assertStringContainsString('max-width:640px', $html);
        $this->assertStringContainsString('@media only screen and (max-width: 480px)', $html);
        $this->assertStringContainsString('width="100%"', $html);
        $this->assertStringNotContainsString('width="640"', $html);
        $this->assertStringContainsString('box-sizing: border-box', $html);
        $this->assertStringContainsString('overflow-wrap: anywhere', $html);
        $this->assertStringContainsString('table-layout:fixed', $html);
        $this->assertStringContainsString('#6d42e5', strtolower($html));
        $this->assertStringContainsString('#191b24', strtolower($html));
        $this->assertStringNotContainsString('#176b52', strtolower($html));
        $this->assertStringContainsString('reset_email=alex%40example.test&amp;reset_token=secure-token', $html);
        $this->assertStringNotContainsString('<script', strtolower($html));
        $this->assertStringNotContainsString('rel="stylesheet"', strtolower($html));
    }

    public function test_long_dynamic_values_use_mobile_safe_wrapping(): void
    {
        $longGymName = 'Demo Forge Fitness & Performance Centre — PREVIEW ONLY — North Metropolitan Training Workspace';
        $longInvoiceNumber = 'IC-SAAS-2026-00124';

        $invitation = $this->render('emails.invitations.account', [
            'subject' => "You're invited to {$longGymName} on IronCore",
            'preheader' => 'Activate staff access.',
            'recipientName' => 'Preview Recipient',
            'gymName' => $longGymName,
            'portalLabel' => 'staff portal',
            'roleLabel' => 'Reception and Operational Support Coordinator',
            'expiresAt' => '14 Oct 2026, 17:00 PKT',
            'actionUrl' => 'https://app.ironcore.website/#invite_gym=preview-workspace-with-a-long-identifier&invite_token=preview-only-token-with-a-deliberately-long-value',
        ]);

        $invoice = $this->render('emails.saas.invoice-status', [
            'subject' => 'IronCore subscription payment due today',
            'preheader' => 'Invoice due.',
            'recipientName' => 'Preview Recipient',
            'gymName' => $longGymName,
            'invoiceNumber' => $longInvoiceNumber,
            'amount' => '49.00 GBP',
            'dueDate' => '8 Oct 2026',
            'warning' => 'Payment is required within the configured grace period.',
            'overdue' => false,
            'billingUrl' => 'https://app.ironcore.website/#email_destination=saas_billing',
        ]);

        foreach ([$invitation, $invoice] as $html) {
            $this->assertStringContainsString('overflow-wrap:anywhere', $html);
            $this->assertStringContainsString('word-wrap:break-word', $html);
            $this->assertStringNotContainsString('width="640"', $html);
        }

        $this->assertStringContainsString(e($longGymName), $invitation);
        $this->assertStringContainsString($longInvoiceNumber, $invoice);
    }

    public function test_supported_transactional_templates_render_their_available_dynamic_data(): void
    {
        $cases = [
            ['emails.security.account-access', [
                'subject' => "You're invited to manage Forge Fitness",
                'preheader' => 'Complete your account setup.',
                'heading' => "You're invited to manage Forge Fitness",
                'recipientName' => 'Fatima',
                'gymName' => 'Forge Fitness',
                'isOwnerInvitation' => true,
                'actionUrl' => 'https://app.ironcore.website/#reset_email=fatima%40example.test&reset_token=owner-token',
                'expiresInMinutes' => 60,
            ], ['Fatima', 'Forge Fitness', 'Gym Owner', 'owner-token']],
            ['emails.security.account-access', [
                'subject' => 'Your account setup link has been renewed',
                'preheader' => 'Complete your account setup.',
                'heading' => 'Your account setup link has been renewed',
                'recipientName' => 'Fatima',
                'gymName' => 'Forge Fitness',
                'isOwnerInvitation' => true,
                'actionUrl' => 'https://app.ironcore.website/#reset_email=fatima%40example.test&reset_token=renewed-token',
                'expiresInMinutes' => 60,
            ], ['renewed', 'Forge Fitness', 'renewed-token']],
            ['emails.invitations.account', [
                'subject' => "You're invited to Forge Fitness on IronCore",
                'preheader' => 'Activate staff access.',
                'recipientName' => null,
                'gymName' => 'Forge Fitness',
                'portalLabel' => 'staff portal',
                'roleLabel' => 'Receptionist',
                'expiresAt' => '14 Oct 2026, 17:00 PKT',
                'actionUrl' => 'https://app.ironcore.website/#invite_gym=gym-1&invite_token=staff-token',
            ], ['staff portal', 'Receptionist', '14 Oct 2026', 'staff-token']],
            ['emails.invitations.account', [
                'subject' => "You're invited to Forge Fitness on IronCore",
                'preheader' => 'Activate member access.',
                'recipientName' => 'Omar',
                'gymName' => 'Forge Fitness',
                'portalLabel' => 'member portal',
                'roleLabel' => null,
                'expiresAt' => '8 Oct 2026, 17:00 PKT',
                'actionUrl' => 'https://app.ironcore.website/#activate_gym=gym-1&activate_token=member-token',
            ], ['Omar', 'member portal', 'member-token']],
            ['emails.saas.trial-ending', [
                'subject' => 'Your IronCore trial ends tomorrow',
                'preheader' => 'Trial ending.',
                'recipientName' => 'Aisha',
                'gymName' => 'Forge Fitness',
                'trialEndsAt' => '8 Oct 2026',
                'billingUrl' => 'https://app.ironcore.website/#email_destination=saas_billing',
            ], ['Aisha', 'Forge Fitness', '8 Oct 2026', 'Trial ending']],
            ['emails.saas.trial-expired', [
                'subject' => 'Action required: your IronCore trial has ended',
                'preheader' => 'Payment required.',
                'recipientName' => 'Aisha',
                'gymName' => 'Forge Fitness',
                'billingUrl' => 'https://app.ironcore.website/#email_destination=saas_billing',
            ], ['Aisha', 'Forge Fitness', 'Restricted']],
            ['emails.saas.invoice-status', [
                'subject' => 'IronCore subscription payment due today',
                'preheader' => 'Invoice due.',
                'recipientName' => 'Aisha',
                'gymName' => 'Forge Fitness',
                'invoiceNumber' => 'IC-SAAS-2026-00124',
                'amount' => '49.00 GBP',
                'dueDate' => '7 Oct 2026',
                'warning' => 'Payment is required within 15 days.',
                'overdue' => false,
                'billingUrl' => 'https://app.ironcore.website/#email_destination=saas_billing',
            ], ['IC-SAAS-2026-00124', '49.00 GBP', '7 Oct 2026', 'Payment due', 'email_destination=saas_billing']],
            ['emails.saas.invoice-status', [
                'subject' => 'Action required: IronCore subscription payment overdue',
                'preheader' => 'Invoice overdue.',
                'recipientName' => 'Aisha',
                'gymName' => 'Forge Fitness',
                'invoiceNumber' => 'IC-SAAS-2026-00125',
                'amount' => '49.00 GBP',
                'dueDate' => '1 Oct 2026',
                'warning' => 'The grace period has ended.',
                'overdue' => true,
                'billingUrl' => 'https://app.ironcore.website/#email_destination=saas_billing',
            ], ['IC-SAAS-2026-00125', '49.00 GBP', '1 Oct 2026', 'Overdue', 'email_destination=saas_billing']],
            ['emails.billing.invoice-created', [
                'subject' => 'Your membership invoice is ready',
                'preheader' => 'A new invoice is ready.',
                'recipientName' => 'Zain',
                'billingLabel' => 'Membership billing',
                'heading' => 'Your membership invoice is ready',
                'gymName' => 'Forge Fitness & Performance Centre',
                'invoiceNumber' => 'INV-2026-00124',
                'amount' => '5,500.00 PKR',
                'dueDate' => '18 October 2026',
                'status' => 'Open',
                'actionUrl' => 'https://app.ironcore.website/#email_destination=member_account',
                'actionLabel' => 'View and pay invoice',
            ], ['Forge Fitness &amp; Performance Centre', 'INV-2026-00124', '5,500.00 PKR', 'View and pay invoice']],
            ['emails.billing.invoice-status', [
                'subject' => 'Your membership invoice is overdue',
                'preheader' => 'Payment overdue.',
                'recipientName' => 'Zain',
                'billingLabel' => 'Membership billing',
                'heading' => 'Your membership payment is overdue',
                'gymName' => 'Forge Fitness',
                'invoiceNumber' => 'INV-2026-00125',
                'amount' => '49.00 GBP',
                'dueDate' => '1 October 2026',
                'status' => 'Open',
                'overdue' => true,
                'actionUrl' => 'https://app.ironcore.website/#email_destination=member_account',
                'actionLabel' => 'View and pay invoice',
            ], ['INV-2026-00125', '49.00 GBP', 'Overdue', 'View and pay invoice']],
            ['emails.billing.payment-paid', [
                'subject' => 'Your subscription payment is confirmed',
                'preheader' => 'Payment confirmed.',
                'recipientName' => 'Aisha',
                'billingLabel' => 'IronCore subscription billing',
                'heading' => 'Your subscription payment is confirmed',
                'gymName' => 'Forge Fitness',
                'invoiceNumber' => 'IC-SAAS-2026-00124',
                'amountPaid' => '49.00 GBP',
                'paymentDate' => '8 October 2026',
                'paymentMethod' => 'Bank Transfer',
                'accessRestored' => true,
                'actionUrl' => 'https://app.ironcore.website/#email_destination=saas_billing',
                'actionLabel' => 'View billing',
            ], ['IC-SAAS-2026-00124', '49.00 GBP', 'Bank Transfer', 'access has been restored', 'View billing']],
            ['emails.billing.access-status', [
                'subject' => 'Your IronCore account is restricted',
                'preheader' => 'Payment required.',
                'recipientName' => 'Aisha',
                'billingLabel' => 'IronCore subscription billing',
                'heading' => 'Your IronCore account is restricted',
                'gymName' => 'Forge Fitness',
                'invoiceNumber' => 'IC-SAAS-2026-00124',
                'restricted' => true,
                'restored' => false,
                'accessNoun' => 'IronCore account access',
                'actionUrl' => 'https://app.ironcore.website/#email_destination=saas_billing',
                'actionLabel' => 'Restore access',
            ], ['IronCore account access is restricted', 'IC-SAAS-2026-00124', 'Restore access']],
            ['emails.membership.payment-due', [
                'subject' => 'Your membership payment is due',
                'preheader' => 'Membership payment due.',
                'body' => 'Please pay your open membership invoice before the grace period ends.',
                'recipientName' => 'Zain',
                'actionUrl' => 'https://app.ironcore.website/#email_destination=member_account',
            ], ['Zain', 'membership invoice', 'Payment due', 'email_destination=member_account']],
            ['emails.training.workout-plan-assigned', [
                'subject' => 'Your new workout plan is ready',
                'preheader' => 'New workout plan.',
                'body' => 'Strength Builder is now available in your IronCore coaching workspace.',
                'recipientName' => 'Zain',
                'actionUrl' => 'https://app.ironcore.website/#email_destination=member_training',
            ], ['Zain', 'Strength Builder', 'View your workout plan', 'email_destination=member_training']],
            ['emails.notifications.transactional', [
                'subject' => 'IronCore notification',
                'preheader' => 'New update.',
                'body' => 'A new account update is available.',
                'recipientName' => 'Zain',
                'actionUrl' => 'https://app.ironcore.website',
            ], ['Zain', 'A new account update is available.']],
        ];

        foreach ($cases as [$view, $data, $needles]) {
            $html = $this->render($view, $data);
            foreach ($needles as $needle) {
                $this->assertStringContainsString($needle, $html, "{$view} did not render {$needle}");
            }
        }
    }

    public function test_blank_recipient_name_uses_a_safe_greeting(): void
    {
        $html = $this->render('emails.notifications.transactional', [
            'subject' => 'IronCore notification',
            'preheader' => 'New update.',
            'body' => 'A new account update is available.',
            'recipientName' => '   ',
            'actionUrl' => 'https://app.ironcore.website',
        ]);

        $this->assertStringContainsString('Hello,', $html);
        $this->assertStringNotContainsString('Hello ,', $html);
    }

    public function test_branded_mailable_keeps_subject_view_and_dynamic_data_explicit(): void
    {
        $mail = new BrandedTransactionalMail('Subject', 'emails.notifications.transactional', [
            'subject' => 'Subject',
            'preheader' => 'Preview',
            'body' => 'Body',
            'recipientName' => 'Sam',
            'actionUrl' => 'https://app.ironcore.website',
        ]);

        $this->assertSame('Subject', $mail->envelope()->subject);
        $this->assertSame('emails.notifications.transactional', $mail->content()->view);
        $this->assertSame('Sam', $mail->content()->with['recipientName']);
    }

    /** @param array<string, mixed> $data */
    private function render(string $view, array $data): string
    {
        return view($view, $data)->render();
    }
}
