export type IronCoreRole =
  | "super_admin"
  | "gym_owner"
  | "gym_manager"
  | "receptionist"
  | "trainer"
  | "member";

export type AuthenticatedUser = {
  id: string;
  name: string;
  email: string;
  must_change_password: boolean;
  platform_role: IronCoreRole | null;
  gyms: Array<{ id: string; name: string; role: IronCoreRole }>;
};

export type MfaChallenge = {
  authentication: "mfa_challenge";
  mfa_required: true;
  challenge_token: string;
  expires_in: number;
};

export type SessionAuthentication = {
  authentication: "session";
  user: AuthenticatedUser;
};

export type AuthenticationResult = SessionAuthentication | MfaChallenge;
export type InitialSetupStatus = { setup_required: boolean; setup_available: boolean };
export type InitialSuperAdmin = { setup_key: string; name: string; email: string; password: string; password_confirmation: string };
export type MfaStatus = { enabled: boolean; setup_pending: boolean; confirmed_at: string | null; recovery_codes_remaining: number };
export type MfaSetup = { secret: string; otpauth_uri: string; issuer: string; account: string };
export type MfaRecoveryCodes = { recovery_codes: string[]; recovery_codes_remaining: number };

export type GymSummary = {
  id: string;
  name: string;
  slug: string;
  legal_name?: string | null;
  base_currency: "GBP" | "USD" | "PKR" | "AED" | "SAR";
  country_code: string;
  timezone: string;
  status: "trial" | "active" | "past_due" | "suspended" | "cancelled";
  trial_ends_at?: string | null;
  created_at?: string | null;
};

export type UpdateGym = {
  name?: string;
  legal_name?: string | null;
  base_currency?: GymSummary["base_currency"];
  country_code?: string;
  timezone?: string;
  status?: GymSummary["status"];
  reason: string;
};

export type NewGym = {
  name: string;
  legal_name?: string;
  slug?: string;
  base_currency: GymSummary["base_currency"];
  country_code: string;
  timezone: string;
  owner: {
    create_login_account: boolean;
    name?: string;
    email?: string;
    phone?: string;
    setup_method?: "invite" | "temporary_password";
    temporary_password?: string;
    temporary_password_confirmation?: string;
    require_password_change?: boolean;
  };
};

export type GymOwnerAccount = {
  user_id: string;
  name: string;
  email: string;
  phone: string | null;
  account_status: "active" | "suspended";
  setup_status: "invite_pending" | "password_change_required" | "complete";
  setup_method: "invite" | "temporary_password" | "existing_account" | null;
  invite_sent_at: string | null;
  setup_completed_at: string | null;
  last_login_at: string | null;
  must_change_password: boolean;
  role: "gym_owner";
};

export type NewGymOwnerAccount = {
  name: string;
  email: string;
  phone: string;
  setup_method: "invite" | "temporary_password";
  temporary_password?: string;
  temporary_password_confirmation?: string;
  require_password_change?: boolean;
  reason?: string;
};

export type UpdateGymOwnerAccount = {
  name: string;
  email: string;
  phone: string;
  status: "active" | "suspended";
  reason: string;
};

export type CreatedGym = { gym: GymSummary; owner_account: GymOwnerAccount | null };
export type AuditLogRecord = { id: string; created_at: string; user: { id: string; name: string; email: string } | null; role: string | null; gym: { id: string; name: string } | null; action: string; resource: string | null; resource_id: string | null; old_value: Record<string, unknown> | null; new_value: Record<string, unknown> | null; reason: string | null; ip_address: string | null; device: string | null };
export type AuditLogFilters = { from?: string; to?: string; gym_id?: string; actor_id?: string; role?: string; action?: string; resource?: string; search?: string; page?: number; per_page?: number };

export type MemberRecord = {
  id: string;
  gym_id: string;
  home_branch_id: string | null;
  user_id: string | null;
  member_number: string;
  member_code: string;
  first_name: string;
  last_name: string;
  email: string | null;
  phone: string | null;
  date_of_birth: string | null;
  status: "lead" | "active" | "paused" | "cancelled" | "archived";
  joined_at: string | null;
  current_membership?: MembershipRecord | null;
  created_at: string | null;
};

export type MemberSelfRecord = Pick<MemberRecord,
  "member_code" | "first_name" | "last_name" | "email" | "phone" |
  "date_of_birth" | "status" | "joined_at"
>;

export type NewMember = {
  first_name: string;
  last_name: string;
  email: string;
  phone: string;
  status?: MemberRecord["status"];
};
export type UpdateMember = { home_branch_id?: string | null; first_name?: string; last_name?: string; email?: string; phone?: string; status?: MemberRecord["status"]; reason: string };
export type MemberImportSummary = { total_rows: number; valid_rows: number; invalid_rows: number; duplicate_rows: number; missing_required_fields: number; invalid_email: number; invalid_phone: number; invalid_branch_references: number; invalid_membership_references: number };
export type MemberImportRecord = { id: string; gym_id: string; original_name: string; status: "previewed" | "queued" | "processing" | "completed" | "failed"; total_rows: number; processed_rows: number; success_rows: number; failure_rows: number; preview_summary: MemberImportSummary | null; errors: Array<{ line: number | null; category: string; message: string }> | null; previewed_at: string | null; confirmed_at: string | null; completed_at: string | null };

export type BranchRecord = { id: string; gym_id: string; name: string; code: string; email: string | null; phone: string | null; timezone: string | null; status: "active" | "inactive"; is_primary: boolean; created_at: string | null };
export type NewBranch = { name: string; code: string; email?: string; phone?: string; timezone?: string; is_primary?: boolean };
export type UpdateBranch = { name: string; code: string; email?: string | null; phone?: string | null; timezone?: string | null; status: BranchRecord["status"]; is_primary: boolean; reason: string };
export type MembershipPlanRecord = { id: string; gym_id: string; branch_id: string | null; name: string; code: string; description: string | null; billing_interval: "one_time" | "weekly" | "monthly" | "quarterly" | "yearly"; interval_count: number; price_amount_minor: number; currency: GymSummary["base_currency"]; joining_fee_minor: number; duration_days: number | null; trial_days: number; status: "active" | "inactive"; created_at: string | null };
export type NewMembershipPlan = { name: string; code: string; branch_id?: string; description?: string; billing_interval: MembershipPlanRecord["billing_interval"]; interval_count: number; price_amount_minor: number; currency: GymSummary["base_currency"]; joining_fee_minor?: number; duration_days?: number; trial_days?: number; status?: "active" };
export type UpdateMembershipPlan = { name: string; code: string; branch_id?: string | null; description?: string | null; price_amount_minor: number; currency: GymSummary["base_currency"]; duration_days?: number | null; trial_days?: number; status: MembershipPlanRecord["status"]; reason: string };
export type MembershipRecord = { id: string; gym_id: string; member_id: string; plan_id: string; branch_id: string | null; status: "pending" | "active" | "paused" | "cancelled" | "expired"; starts_at: string; ends_at?: string | null; is_in_date?: boolean; next_billing_at: string | null; price_amount_minor: number; currency: GymSummary["base_currency"]; joining_fee_minor?: number; billing_interval?: "one_time" | "weekly" | "monthly" | "quarterly" | "yearly"; interval_count?: number; auto_renew: boolean; plan?: { id: string; name: string; code: string }; branch?: { id: string; name: string } | null; created_at: string | null };
export type NewMembership = { member_id: string; plan_id: string; branch_id?: string; starts_at: string; ends_at?: string; status?: "pending" | "active"; auto_renew?: boolean };
export type UpdateMembership = { status: MembershipRecord["status"]; ends_at?: string | null; next_billing_at?: string | null; auto_renew: boolean; cancellation_reason?: string | null; reason: string };
export type UpdateMemberSelf = { first_name?: string; last_name?: string; email?: string; phone?: string; date_of_birth?: string | null };
export type StaffRole = "gym_owner" | "gym_manager" | "receptionist" | "trainer";
export type StaffRecord = { id: string; gym_id: string; user: { id: string; name: string; email: string }; role: StaffRole; home_branch_id: string | null; phone: string | null; employee_number: string; job_title: string | null; status: "active" | "suspended" | "inactive"; hired_at: string | null; terminated_at: string | null; has_profile_image: boolean; created_at: string | null };
export type StaffInvitationRecord = { id: string; gym_id: string; home_branch_id: string | null; email: string; role: StaffRole; employee_number: string; job_title: string | null; status: "pending" | "accepted" | "revoked" | "expired"; expires_at: string; accepted_at: string | null; created_at: string | null };
export type NewStaffInvitation = { email: string; role: StaffRole; employee_number: string; job_title?: string; home_branch_id?: string; expires_in_days?: number };
export type NewTrainer = { name: string; email: string; phone: string; home_branch_id: string; status: "active" | "inactive"; profile_image?: File };
export type CreatedTrainer = { trainer: StaffRecord; account_setup_token: string | null; existing_account: boolean };
export type UpdateStaff = { display_name?: string; contact_email?: string; phone?: string; role?: StaffRole; employee_number?: string; job_title?: string | null; home_branch_id?: string | null; status?: StaffRecord["status"]; hired_at?: string | null; terminated_at?: string | null; reason: string };
export type UpdateOwnStaffProfile = { display_name: string; contact_email: string; phone: string; reason: string };
export type CreatedStaffInvitation = { invitation: StaffInvitationRecord; acceptance_token: string };
export type MemberAccountInvitationRecord = { id: string; gym_id: string; member_id: string; email: string; status: "pending" | "accepted" | "revoked" | "expired"; expires_at: string; accepted_at: string | null; revoked_at: string | null; created_at: string | null };
export type CreatedMemberAccountInvitation = { invitation: MemberAccountInvitationRecord; activation_token: string };
export type MemberAccountActivationPreview = { gym_name: string; member_first_name: string; masked_email: string; existing_account: boolean };
export type InvoiceItemRecord = { id: string; invoice_id: string; description: string; quantity: number; unit_amount_minor: number; subtotal_amount_minor: number; tax_amount_minor: number; total_amount_minor: number };
export type InvoiceRecord = { id: string; gym_id: string; member_id: string; membership_id: string | null; branch_id: string | null; number: string; status: "draft" | "open" | "paid" | "void" | "uncollectible"; currency: GymSummary["base_currency"]; subtotal_amount_minor: number; tax_amount_minor: number; total_amount_minor: number; paid_amount_minor: number; due_amount_minor: number; issued_at: string; due_at: string | null; paid_at: string | null; notes: string | null; items: InvoiceItemRecord[]; created_at: string | null };
export type PaymentRefundRecord = { id: string; payment_id: string; status: "pending" | "succeeded" | "failed"; amount_minor: number; currency: GymSummary["base_currency"]; reason: string; refunded_at: string | null; created_at: string | null };
export type BankTransferReceiptRecord = { id: string; payment_id: string; member_id: string; membership_id: string; invoice_id: string | null; bank_reference: string | null; transferred_on: string | null; original_name: string; mime_type: string; size_bytes: number; submitted_at: string | null; reviewed_at: string | null; review_reason: string | null };
export type PaymentRecord = { id: string; gym_id: string; member_id: string; membership_id: string | null; invoice_id: string | null; branch_id: string | null; receipt_number: string; provider: "manual" | "stripe"; method: "cash" | "card" | "bank_transfer" | "online_card" | "other"; status: "pending" | "paid" | "rejected" | "partially_refunded" | "refunded" | "voided"; amount_minor: number; refunded_amount_minor: number; currency: GymSummary["base_currency"]; paid_at: string | null; failure_message: string | null; notes: string | null; provider_checkout_id: string | null; refunds: PaymentRefundRecord[]; bank_transfer_receipt: BankTransferReceiptRecord | null; created_at: string | null };
export type PaymentSummaryRecord = { gross_minor: number; refunded_minor: number; net_minor: number; pending_minor: number; outstanding_minor: number; currency: GymSummary["base_currency"] };
export type PaymentGatewayRecord = { id: string; provider: "stripe"; status: "pending" | "restricted" | "active" | "disabled"; charges_enabled: boolean; payouts_enabled: boolean; details_submitted: boolean; country_code: string; default_currency: GymSummary["base_currency"]; requirements: { currently_due?: string[]; eventually_due?: string[]; disabled_reason?: string | null } | null; connected_at: string | null; provider_account_id: string | null };
export type NewInvoice = { member_id: string; membership_id?: string; branch_id?: string; currency: GymSummary["base_currency"]; issued_at?: string; due_at?: string; notes?: string; items: Array<{ description: string; quantity: number; unit_amount_minor: number; tax_amount_minor?: number }> };
export type NewPayment = { member_id: string; membership_id?: string; invoice_id?: string; branch_id?: string; method: PaymentRecord["method"]; amount_minor: number; currency: GymSummary["base_currency"]; idempotency_key: string; paid_at?: string; notes?: string; bank_reference?: string; transferred_on?: string; receipt?: File };
export type CreatedPayment = { payment: PaymentRecord; checkout_url: string | null; idempotency_reused: boolean };
export type PaymentGatewayState = { gateway: PaymentGatewayRecord | null; provider_configured: boolean; checkout_available: boolean };
export type BankTransferInvoiceDetails = { invoice_id: string; payment_reference: string; amount_minor: number; currency: GymSummary["base_currency"] };
export type BankTransferDetails = { account_name: string; bank_name: string; account_number_or_iban: string; routing_details: string | null; payment_instructions: string | null; invoices: BankTransferInvoiceDetails[] };
export type GymBankTransferSetting = { id: string; gym_id: string; enabled: boolean; account_name: string | null; bank_name: string | null; account_number_or_iban: string | null; routing_details: string | null; payment_instructions: string | null; updated_at: string | null };
export type UpdateGymBankTransferSetting = { enabled: boolean; account_name?: string; bank_name?: string; account_number_or_iban?: string; routing_details?: string; payment_instructions?: string; reason: string };
export type MemberPaymentOptions = { stripe_configured: boolean; stripe_available: boolean; bank_transfer_available: boolean; bank_transfer_details: BankTransferDetails | null; cash_available_at_gym: boolean };
export type NewMemberPayment = { invoice_id: string; method: "bank_transfer" | "online_card"; idempotency_key: string; bank_reference?: string; transferred_on?: string; receipt?: File };
export type SaasFeatureLimits = { members: number; branches: number; staff: number; advanced_reports: boolean; priority_support: boolean };
export type SaasPaymentMethod = "cash" | "bank_transfer" | "stripe";
export type SaasPlanPriceRecord = { id: string; currency: GymSummary["base_currency"]; billing_interval: "monthly" | "yearly"; amount_minor: number; trial_days: number; active: boolean };
export type SaasPlanRecord = { id: string; code: string; name: string; description: string | null; status: "draft" | "active" | "archived"; feature_limits: SaasFeatureLimits; payment_methods: SaasPaymentMethod[]; sort_order: number; prices: SaasPlanPriceRecord[]; created_at: string | null };
export type GymSubscriptionRecord = { id: string; gym_id: string; provider: "manual" | "stripe"; status: "incomplete" | "trialing" | "active" | "past_due" | "unpaid" | "paused" | "cancelled" | "incomplete_expired"; plan_code: string; plan_name: string; feature_limits: SaasFeatureLimits; currency: GymSummary["base_currency"]; amount_minor: number; billing_interval: "monthly" | "yearly"; current_period_start: string | null; current_period_end: string | null; next_billing_at?: string | null; grace_period_days?: number; billing_restricted_at?: string | null; billing_override_until?: string | null; billing_override_reason?: string | null; trial_ends_at: string | null; cancel_at_period_end: boolean; cancelled_at: string | null; ended_at: string | null; failure_code: string | null; failure_message: string | null; billing_contact?: { email: string; name: string | null }; created_at: string | null };
export type SaasBillingInvoiceRecord = { id: string; number: string | null; status: "draft" | "upcoming" | "due" | "open" | "past_due" | "paid" | "void" | "cancelled" | "uncollectible"; currency: GymSummary["base_currency"]; amount_due_minor: number; amount_paid_minor: number; amount_remaining_minor: number; hosted_invoice_url: string | null; invoice_pdf_url: string | null; available_at?: string | null; period_start: string | null; period_end: string | null; due_at: string | null; grace_ends_at?: string | null; paid_at: string | null; voided_at?: string | null; void_reason?: string | null; created_at: string | null };
export type SaasPaymentOptions = { cash_available: boolean; bank_transfer_available: boolean; stripe_configured: boolean };
export type SaasPaymentCorrectionRecord = { id: string; reference: string | null; method: "cash" | "bank_transfer" | null; payment_date: string | null; amount_minor: number | null; internal_notes: string | null; metadata: Record<string, string> | null; reason: string; corrected_by: { id: string; name: string } | null; created_at: string | null };
export type SaasPaymentRefundRecord = { id: string; status: string; amount_minor: number; currency: GymSummary["base_currency"]; reason: string; recorded_by: { id: string; name: string } | null; refunded_at: string | null };
export type SaasSubscriptionPaymentRecord = { id: string; gym_id: string; saas_plan_price_id: string; gym_subscription_id: string | null; method: "cash" | "bank_transfer"; effective_method?: "cash" | "bank_transfer"; status: "pending" | "paid" | "rejected" | "partially_refunded" | "refunded" | "voided"; amount_minor: number; refunded_amount_minor?: number; effective_amount_minor?: number; currency: GymSummary["base_currency"]; reference: string | null; effective_reference?: string | null; payment_date?: string | null; effective_payment_date?: string | null; corrections?: SaasPaymentCorrectionRecord[]; refunds?: SaasPaymentRefundRecord[]; has_receipt: boolean; receipt_original_name: string | null; reviewed_at: string | null; review_reason: string | null; paid_at: string | null; plan?: { id: string; name: string; code: string; billing_interval: "monthly" | "yearly" }; created_at: string | null };
export type NewSaasPaymentCorrection = { reference?: string | null; method?: "cash" | "bank_transfer" | null; payment_date?: string | null; amount_minor?: number | null; internal_notes?: string | null; metadata?: Record<string, string> | null; reason: string };
export type NewSaasSubscriptionPayment = { saas_plan_price_id?: string; saas_billing_invoice_id?: string; method: "cash" | "bank_transfer"; idempotency_key: string; reference: string; payment_date?: string; receipt?: File };
export type PlatformMemberRecord = { id: string; gym_id: string; gym_name: string; name: string; email: string | null; phone: string | null; member_code: string; status: string; branch: { id: string; name: string } | null; membership_status: string | null; plan: { id: string; name: string } | null; joined_at: string | null };
export type PlatformMemberPage = Paginated<PlatformMemberRecord> & { meta: Paginated<PlatformMemberRecord>["meta"] & { facets: { plans: Array<{ id: string; name: string; gym_id: string; gym_name: string }>; branches: Array<{ id: string; name: string; gym_id: string; gym_name: string }> } } };
export type PlatformBillingRecord = { metrics: { total_gyms: number; active_gyms: number; trial_gyms: number; paid_gyms: number; unpaid_gyms: number; past_due_gyms: number; billing_suspended_gyms: number; cancelled_archived_gyms: number; mrr_by_currency: Record<string, number>; revenue_this_month_by_currency: Record<string, number>; outstanding_by_currency: Record<string, number>; overdue_by_currency: Record<string, number>; upcoming_renewals: number; trial_conversions: number }; subscriptions: Array<{ id: string; gym_id: string; gym_name: string; plan_id: string; plan_name: string; status: string; currency: GymSummary["base_currency"]; amount_minor: number; billing_interval: string; next_billing_at: string | null; grace_period_days: number; billing_restricted_at: string | null }>; invoices: Paginated<{ id: string; gym_id: string; gym_name: string; subscription_id: string; plan_name: string | null; number: string | null; status: string; currency: GymSummary["base_currency"]; amount_due_minor: number; amount_paid_minor: number; amount_remaining_minor: number; period_start: string | null; period_end: string | null; due_at: string | null; grace_ends_at: string | null; paid_at: string | null }> };
export type PlatformAnalyticsPoint = { month: string; new_gyms: number; total_gyms: number; active_gyms: number; members_added: number; revenue_by_currency: Record<string, number> };
export type PlatformAnalyticsRecord = { from: string; to: string; timeline: PlatformAnalyticsPoint[]; plan_distribution: Array<{ plan: string; gyms: number }>; revenue_by_plan: Array<{ plan: string; revenue_by_currency: Record<string, number> }>; invoice_statuses: Array<{ status: string; count: number }>; comparison: { current_month: PlatformAnalyticsPoint; previous_month: PlatformAnalyticsPoint }; billing_metrics: PlatformBillingRecord["metrics"] };
export type NewSaasPlan = { code: string; name: string; description?: string; sort_order?: number; feature_limits: SaasFeatureLimits; payment_methods: SaasPaymentMethod[]; currency: GymSummary["base_currency"]; billing_interval: "monthly" | "yearly"; amount_minor: number; trial_days?: number };
export type UpdateSaasPlan = { name?: string; description?: string | null; status?: SaasPlanRecord["status"]; sort_order?: number; feature_limits?: SaasFeatureLimits; payment_methods?: SaasPaymentMethod[]; price?: Omit<NewSaasPlanPrice, "reason">; reason: string };
export type NewSaasPlanPrice = { currency: GymSummary["base_currency"]; billing_interval: "monthly" | "yearly"; amount_minor: number; trial_days?: number; reason: string };
export type MemberAccessCredentialRecord = { id: string; gym_id: string; member_id: string; member_code: string; credential_hint: string; status: "active" | "revoked" | "expired"; expires_at: string | null; last_used_at: string | null; created_at: string | null; credential?: string };
export type MemberSelfCredentialRecord = Pick<MemberAccessCredentialRecord,
  "credential_hint" | "status" | "expires_at" | "last_used_at" | "created_at"
> & { credential?: string };
export type MemberSummaryRecord = { id: string; member_number: string; member_code: string; name: string };
export type AttendanceRecord = { id: string; gym_id: string; member_id: string; membership_id: string; branch_id: string; member?: MemberSummaryRecord; branch?: { id: string; name: string }; method: "qr" | "member_code" | "manual"; status: "checked_in" | "checked_out"; checked_in_at: string; checked_out_at: string | null };
export type ClassSessionRecord = { id: string; gym_id: string; branch_id: string; trainer_staff_profile_id: string | null; branch?: { id: string; name: string }; trainer?: { id: string; name: string | null } | null; title: string; description: string | null; starts_at: string; ends_at: string; capacity: number; booked_count: number; waitlist_count: number; attended_count: number; waitlist_enabled: boolean; booking_opens_at: string | null; booking_closes_at: string | null; status: "scheduled" | "cancelled" | "completed"; cancellation_reason: string | null; created_at: string | null };
export type ClassBookingRecord = { id: string; gym_id: string; class_session_id: string; member_id: string; membership_id: string; member?: MemberSummaryRecord; session?: { id: string; title: string; starts_at: string }; status: "booked" | "waitlisted" | "cancelled" | "attended" | "no_show"; waitlist_sequence: number | null; booked_at: string; promoted_at: string | null; cancelled_at: string | null; checked_in_at: string | null; cancellation_reason: string | null };
export type NewClassSession = { branch_id: string; trainer_staff_profile_id?: string; title: string; description?: string; starts_at: string; ends_at: string; capacity: number; waitlist_enabled?: boolean; booking_opens_at?: string; booking_closes_at?: string };
export type UpdateClassSession = Partial<NewClassSession> & { status?: ClassSessionRecord["status"]; reason: string };
export type AttendanceCheckIn = { branch_id?: string; credential?: string; member_code?: string; member_id?: string };
export type TrainerAssignmentRecord = { id: string; gym_id: string; trainer_staff_profile_id: string; member_id: string; trainer?: { id: string; name: string | null }; member?: MemberSummaryRecord; status: "active" | "inactive"; starts_on: string; ends_on: string | null; notes: string | null; created_at: string | null };
export type WorkoutExerciseRecord = { id: string; gym_id: string; workout_plan_id: string; name: string; instructions: string | null; day_number: number; sort_order: number; target_sets: number | null; target_reps_min: number | null; target_reps_max: number | null; target_load_grams: number | null; target_duration_seconds: number | null; rest_seconds: number | null };
export type WorkoutPlanRecord = { id: string; gym_id: string; member_id: string; trainer_staff_profile_id: string; member?: MemberSummaryRecord; trainer?: { id: string; name: string | null }; title: string; goal: string | null; notes: string | null; starts_on: string; ends_on: string | null; status: "draft" | "active" | "completed" | "cancelled"; exercises: WorkoutExerciseRecord[]; created_at: string | null };
export type WorkoutSetRecord = { id: string; gym_id: string; workout_plan_exercise_id: string; exercise_name?: string; set_number: number; reps: number | null; load_grams: number | null; duration_seconds: number | null; distance_metres: number | null; rpe: number | null };
export type WorkoutSessionRecord = { id: string; gym_id: string; workout_plan_id: string; member_id: string; plan?: { id: string; title: string }; member?: MemberSummaryRecord; performed_at: string; duration_seconds: number | null; notes: string | null; sets: WorkoutSetRecord[]; created_at: string | null };
export type ProgressMeasurementRecord = { id: string; gym_id: string; member_id: string; member?: MemberSummaryRecord; metric: "body_weight" | "body_fat" | "waist" | "chest" | "hips" | "biceps" | "thigh" | "custom"; value_milli: number; unit: "kg" | "percent" | "cm" | "count" | "seconds" | "metres" | "custom"; measured_at: string; note: string | null; status: "active" | "corrected" | "voided"; replaces_measurement_id: string | null; voided_at: string | null; created_at: string | null };
export type NotificationPreferenceRecord = { id: string | null; gym_id: string; member_id: string; email_enabled: boolean; sms_enabled: boolean; push_enabled: boolean; class_reminders_enabled: boolean; workout_reminders_enabled: boolean; payment_reminders_enabled: boolean; marketing_enabled: boolean; quiet_hours_start: string | null; quiet_hours_end: string | null; timezone: string };
export type NotificationDeliveryRecord = { id: string; gym_id: string; member_id: string; channel: "email" | "sms" | "push"; template_key: string; status: "queued" | "sending" | "sent" | "failed" | "suppressed"; attempts: number; scheduled_at: string; sent_at: string | null; failure_code: string | null; created_at: string | null };
export type NewTrainerAssignment = { trainer_staff_profile_id: string; member_id: string; starts_on: string; ends_on?: string; notes?: string };
export type NewWorkoutPlan = { member_id: string; trainer_staff_profile_id: string; title: string; goal?: string; notes?: string; starts_on: string; ends_on?: string; status?: WorkoutPlanRecord["status"]; exercises: Array<{ name: string; instructions?: string; day_number: number; sort_order: number; target_sets?: number; target_reps_min?: number; target_reps_max?: number; target_load_grams?: number; target_duration_seconds?: number; rest_seconds?: number }> };
export type UpdateWorkoutPlan = Partial<Omit<NewWorkoutPlan, "exercises">> & { exercises?: NewWorkoutPlan["exercises"]; reason: string };
export type NewWorkoutSession = { workout_plan_id: string; member_id?: string; performed_at: string; duration_seconds?: number; notes?: string; sets: Array<{ workout_plan_exercise_id: string; set_number: number; reps?: number; load_grams?: number; duration_seconds?: number; distance_metres?: number; rpe?: number }> };
export type NewProgressMeasurement = { member_id?: string; metric: ProgressMeasurementRecord["metric"]; value_milli: number; unit: ProgressMeasurementRecord["unit"]; measured_at: string; note?: string };
export type UpdateProgressMeasurement = Omit<NewProgressMeasurement, "member_id"> & { reason: string };
export type UpdateNotificationPreference = Partial<Omit<NotificationPreferenceRecord, "id" | "gym_id" | "member_id">>;
export type ReportOverviewRecord = {
  period: { from: string; to: string; days: number; timezone: string; currency: GymSummary["base_currency"] };
  summary: {
    active_members: number; new_members: number; new_members_change_bps: number | null;
    net_revenue_minor: number; net_revenue_change_bps: number | null; outstanding_minor: number;
    attendance_visits: number; attendance_change_bps: number | null;
    class_utilization_bps: number; class_utilization_change_bps: number | null;
    membership_cancellations: number;
  };
  daily: Array<{ date: string; new_members: number; attendance_visits: number; gross_revenue_minor: number; refunded_minor: number; net_revenue_minor: number }>;
  member_status: Array<{ status: string; count: number }>;
  payment_methods: Array<{ method: string; count: number; net_minor: number }>;
  class_performance: { sessions: number; capacity: number; booked: number; attended: number; waitlisted: number; utilization_bps: number };
  meta: { generated_at: string; cache_ttl_seconds: number; report_version: string };
};

export type Paginated<T> = {
  data: T[];
  meta: { current_page: number; last_page: number; per_page: number; total: number };
};

export type CursorPage<T> = { data: T[]; meta?: { per_page?: number; next_cursor?: string | null; prev_cursor?: string | null } };

type ApiEnvelope<T> = { data: T };
type ValidationPayload = { message?: string; errors?: Record<string, string[]> };

export class IronCoreApiError extends Error {
  constructor(
    message: string,
    public readonly status: number,
    public readonly errors: Record<string, string[]> = {},
  ) {
    super(message);
    this.name = "IronCoreApiError";
  }
}

function normalizeOrigin(origin: string): string {
  return origin.trim().replace(/\/+$/, "");
}

function readXsrfToken(): string | null {
  if (typeof document === "undefined") return null;
  const cookie = document.cookie
    .split("; ")
    .find((entry) => entry.startsWith("XSRF-TOKEN="));
  return cookie ? decodeURIComponent(cookie.slice("XSRF-TOKEN=".length)) : null;
}

export class IronCoreApi {
  private readonly origin: string;

  constructor(origin: string) {
    this.origin = normalizeOrigin(origin);
  }

  private async request<T>(
    path: string,
    options: RequestInit = {},
    gymId?: string,
  ): Promise<T> {
    const response = await this.performRequest(path, options, gymId);

    if (response.status === 204) return undefined as T;
    return response.json() as Promise<T>;
  }

  private async performRequest(path: string, options: RequestInit = {}, gymId?: string): Promise<Response> {
    const method = (options.method ?? "GET").toUpperCase();
    const headers = new Headers(options.headers);
    if (!headers.has("Accept")) headers.set("Accept", "application/json");
    headers.set("X-Requested-With", "XMLHttpRequest");

    if (options.body && !(options.body instanceof FormData)) headers.set("Content-Type", "application/json");
    if (gymId) {
      // Laravel validates this header against both the route gym and the
      // authenticated membership; the client value alone never grants access.
      headers.set("X-Gym-ID", gymId);
    }
    if (!["GET", "HEAD", "OPTIONS"].includes(method)) {
      const xsrf = readXsrfToken();
      if (xsrf) headers.set("X-XSRF-TOKEN", xsrf);
    }

    const response = await fetch(`${this.origin}${path}`, {
      ...options,
      method,
      headers,
      // Web authentication remains in an encrypted, HttpOnly Laravel session
      // cookie. IronCore never persists bearer credentials in browser storage.
      credentials: "include",
    });

    if (!response.ok) {
      const payload = await response.json().catch(() => ({})) as ValidationPayload;
      const firstError = Object.values(payload.errors ?? {})[0]?.[0];
      throw new IronCoreApiError(
        firstError ?? payload.message ?? "IronCore could not complete that request.",
        response.status,
        payload.errors,
      );
    }

    return response;
  }

  async csrf(): Promise<void> {
    await this.request<void>("/sanctum/csrf-cookie");
  }

  async initialSetupStatus(): Promise<InitialSetupStatus> {
    return (await this.request<ApiEnvelope<InitialSetupStatus>>("/api/v1/setup/super-admin")).data;
  }

  async createInitialSuperAdmin(input: InitialSuperAdmin): Promise<SessionAuthentication> {
    await this.csrf();
    return (await this.request<ApiEnvelope<SessionAuthentication>>("/api/v1/setup/super-admin", {
      method: "POST",
      body: JSON.stringify(input),
    })).data;
  }

  async login(email: string, password: string): Promise<AuthenticationResult> {
    await this.csrf();
    const response = await this.request<ApiEnvelope<AuthenticationResult>>(
      "/api/v1/auth/login",
      {
        method: "POST",
        body: JSON.stringify({ email, password, use_bearer_token: false }),
      },
    );
    return response.data;
  }

  async verifyMfaChallenge(challengeToken: string, value: string, recovery = false): Promise<SessionAuthentication> {
    await this.csrf();
    const response = await this.request<ApiEnvelope<SessionAuthentication>>("/api/v1/auth/mfa/challenge", {
      method: "POST",
      body: JSON.stringify(recovery
        ? { challenge_token: challengeToken, recovery_code: value }
        : { challenge_token: challengeToken, code: value }),
    });
    return response.data;
  }

  async requestPasswordReset(email: string): Promise<void> {
    await this.csrf();
    await this.request<{ message: string }>("/api/v1/auth/forgot-password", {
      method: "POST",
      body: JSON.stringify({ email }),
    });
  }

  async resetPassword(email: string, token: string, password: string): Promise<AuthenticationResult> {
    await this.csrf();
    return (await this.request<ApiEnvelope<AuthenticationResult>>("/api/v1/auth/reset-password", {
      method: "POST",
      body: JSON.stringify({ email, token, password, password_confirmation: password }),
    })).data;
  }

  async changePassword(currentPassword: string, password: string): Promise<void> {
    await this.csrf();
    await this.request<{ message: string }>("/api/v1/auth/password", {
      method: "PATCH",
      body: JSON.stringify({ current_password: currentPassword, password, password_confirmation: password }),
    });
  }

  async mfaStatus(): Promise<MfaStatus> {
    return (await this.request<ApiEnvelope<MfaStatus>>("/api/v1/auth/mfa")).data;
  }

  async beginMfaSetup(currentPassword: string): Promise<MfaSetup> {
    await this.csrf();
    return (await this.request<ApiEnvelope<MfaSetup>>("/api/v1/auth/mfa/setup", {
      method: "POST",
      body: JSON.stringify({ current_password: currentPassword }),
    })).data;
  }

  async confirmMfaSetup(code: string): Promise<MfaRecoveryCodes & { enabled: true }> {
    await this.csrf();
    return (await this.request<ApiEnvelope<MfaRecoveryCodes & { enabled: true }>>("/api/v1/auth/mfa/confirm", {
      method: "POST",
      body: JSON.stringify({ code }),
    })).data;
  }

  async regenerateMfaRecoveryCodes(currentPassword: string, code: string): Promise<MfaRecoveryCodes> {
    await this.csrf();
    return (await this.request<ApiEnvelope<MfaRecoveryCodes>>("/api/v1/auth/mfa/recovery-codes", {
      method: "POST",
      body: JSON.stringify({ current_password: currentPassword, code }),
    })).data;
  }

  async disableMfa(currentPassword: string, value: string, recovery = false): Promise<void> {
    await this.csrf();
    await this.request<{ message: string }>("/api/v1/auth/mfa", {
      method: "DELETE",
      body: JSON.stringify(recovery
        ? { current_password: currentPassword, recovery_code: value }
        : { current_password: currentPassword, code: value }),
    });
  }

  async me(): Promise<AuthenticatedUser> {
    return (await this.request<ApiEnvelope<AuthenticatedUser>>("/api/v1/auth/me")).data;
  }

  async logout(): Promise<void> {
    await this.csrf();
    await this.request<void>("/api/v1/auth/logout", { method: "POST" });
  }

  async gyms(): Promise<GymSummary[]> {
    return (await this.request<Paginated<GymSummary>>("/api/v1/gyms?per_page=100")).data;
  }

  async createGym(input: NewGym): Promise<CreatedGym> {
    await this.csrf();
    const response = await this.request<{ data: GymSummary; meta: { owner_account: GymOwnerAccount | null } }>("/api/v1/gyms", {
      method: "POST",
      body: JSON.stringify(input),
    });
    return { gym: response.data, owner_account: response.meta.owner_account };
  }

  async gym(gymId: string): Promise<GymSummary> {
    return (await this.request<ApiEnvelope<GymSummary>>(
      `/api/v1/gyms/${encodeURIComponent(gymId)}`,
      {},
      gymId,
    )).data;
  }

  async updateGym(gymId: string, input: UpdateGym): Promise<GymSummary> {
    await this.csrf();
    return (await this.request<ApiEnvelope<GymSummary>>(
      `/api/v1/gyms/${encodeURIComponent(gymId)}`,
      { method: "PATCH", body: JSON.stringify(input) },
      gymId,
    )).data;
  }

  async deleteGym(gymId: string, confirmation: string, reason: string): Promise<void> {
    await this.csrf();
    await this.request<void>(`/api/v1/gyms/${encodeURIComponent(gymId)}`, { method: "DELETE", body: JSON.stringify({ confirmation, reason }) }, gymId);
  }

  platformAuditLog(filters: AuditLogFilters = {}): Promise<Paginated<AuditLogRecord>> {
    const query = new URLSearchParams(Object.entries(filters).filter(([, value]) => value !== undefined).map(([key, value]) => [key, String(value)]));
    return this.request<Paginated<AuditLogRecord>>(`/api/v1/platform/audit-log?${query}`);
  }

  async platformAuditExport(format: "csv" | "xlsx" | "pdf", filters: AuditLogFilters = {}): Promise<Blob> {
    const query = new URLSearchParams({ ...Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== undefined).map(([key, value]) => [key, String(value)])), format });
    return (await this.performRequest(`/api/v1/platform/audit-log?${query}`, { headers: { Accept: "application/octet-stream" } })).blob();
  }

  async gymOwnerAccount(gymId: string): Promise<GymOwnerAccount | null> {
    return (await this.request<ApiEnvelope<GymOwnerAccount | null>>(
      `/api/v1/gyms/${encodeURIComponent(gymId)}/owner-account`, {}, gymId,
    )).data;
  }

  async createGymOwnerAccount(gymId: string, input: NewGymOwnerAccount): Promise<GymOwnerAccount> {
    await this.csrf();
    return (await this.request<ApiEnvelope<GymOwnerAccount>>(
      `/api/v1/gyms/${encodeURIComponent(gymId)}/owner-account`,
      { method: "POST", body: JSON.stringify(input) }, gymId,
    )).data;
  }

  async updateGymOwnerAccount(gymId: string, input: UpdateGymOwnerAccount): Promise<GymOwnerAccount> {
    await this.csrf();
    return (await this.request<ApiEnvelope<GymOwnerAccount>>(
      `/api/v1/gyms/${encodeURIComponent(gymId)}/owner-account`,
      { method: "PATCH", body: JSON.stringify(input) }, gymId,
    )).data;
  }

  async sendGymOwnerReset(gymId: string, reason: string): Promise<GymOwnerAccount> {
    await this.csrf();
    return (await this.request<ApiEnvelope<GymOwnerAccount>>(
      `/api/v1/gyms/${encodeURIComponent(gymId)}/owner-account/password-reset`,
      { method: "POST", body: JSON.stringify({ reason }) }, gymId,
    )).data;
  }

  async generateGymOwnerTemporaryPassword(gymId: string, reason: string): Promise<{ account: GymOwnerAccount; temporary_password: string }> {
    await this.csrf();
    const response = await this.request<{ data: GymOwnerAccount; meta: { temporary_password: string } }>(
      `/api/v1/gyms/${encodeURIComponent(gymId)}/owner-account/temporary-password`,
      { method: "POST", body: JSON.stringify({ reason }) }, gymId,
    );
    return { account: response.data, temporary_password: response.meta.temporary_password };
  }

  async gymBankTransferSetting(gymId: string): Promise<GymBankTransferSetting | null> {
    return (await this.request<ApiEnvelope<GymBankTransferSetting | null>>(
      `/api/v1/gyms/${encodeURIComponent(gymId)}/bank-transfer-settings`, {}, gymId,
    )).data;
  }

  async updateGymBankTransferSetting(gymId: string, input: UpdateGymBankTransferSetting): Promise<GymBankTransferSetting> {
    await this.csrf();
    return (await this.request<ApiEnvelope<GymBankTransferSetting>>(
      `/api/v1/gyms/${encodeURIComponent(gymId)}/bank-transfer-settings`,
      { method: "PATCH", body: JSON.stringify(input) }, gymId,
    )).data;
  }

  async platformSaasPlans(): Promise<SaasPlanRecord[]> {
    return (await this.request<Paginated<SaasPlanRecord>>("/api/v1/platform/saas-plans?per_page=100")).data;
  }

  async members(gymId: string, search = ""): Promise<Paginated<MemberRecord>> {
    const params = new URLSearchParams({ per_page: "25" });
    if (search.trim()) params.set("search", search.trim());
    return this.request<Paginated<MemberRecord>>(
      `/api/v1/gyms/${encodeURIComponent(gymId)}/members?${params}`,
      {},
      gymId,
    );
  }

  async createMember(gymId: string, member: NewMember): Promise<MemberRecord> {
    await this.csrf();
    return (await this.request<ApiEnvelope<MemberRecord>>(
      `/api/v1/gyms/${encodeURIComponent(gymId)}/members`,
      { method: "POST", body: JSON.stringify(member) },
      gymId,
    )).data;
  }

  async previewMemberImport(gymId: string, file: File): Promise<MemberImportRecord> {
    await this.csrf();
    const form = new FormData(); form.set("file", file);
    return (await this.request<ApiEnvelope<MemberImportRecord>>(
      `/api/v1/gyms/${encodeURIComponent(gymId)}/member-imports`,
      { method: "POST", body: form }, gymId,
    )).data;
  }

  async confirmMemberImport(gymId: string, importId: string): Promise<MemberImportRecord> {
    await this.csrf();
    return (await this.request<ApiEnvelope<MemberImportRecord>>(
      `/api/v1/gyms/${encodeURIComponent(gymId)}/member-imports/${encodeURIComponent(importId)}/confirm`,
      { method: "POST" }, gymId,
    )).data;
  }

  async memberImport(gymId: string, importId: string): Promise<MemberImportRecord> {
    return (await this.request<ApiEnvelope<MemberImportRecord>>(
      `/api/v1/gyms/${encodeURIComponent(gymId)}/member-imports/${encodeURIComponent(importId)}`, {}, gymId,
    )).data;
  }

  async memberImportTemplate(gymId: string, format: "csv" | "xlsx"): Promise<Blob> {
    const response = await this.performRequest(
      `/api/v1/gyms/${encodeURIComponent(gymId)}/members-import-template?format=${format}`,
      { headers: { Accept: format === "csv" ? "text/csv" : "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" } }, gymId,
    );
    return response.blob();
  }

  async memberRosterExport(gymId: string): Promise<Blob> {
    const response = await this.performRequest(`/api/v1/gyms/${encodeURIComponent(gymId)}/members-export`, { headers: { Accept: "text/csv" } }, gymId);
    return response.blob();
  }

  async platformMemberRosterExport(): Promise<Blob> {
    const response = await this.performRequest("/api/v1/platform/members/export", { headers: { Accept: "text/csv" } });
    return response.blob();
  }

  async platformMembers(params: URLSearchParams): Promise<PlatformMemberPage> {
    return this.request<PlatformMemberPage>(`/api/v1/platform/member-directory?${params.toString()}`);
  }

  async platformMembersExport(params: URLSearchParams): Promise<Blob> {
    return (await this.performRequest(`/api/v1/platform/member-directory/export?${params.toString()}`, { headers: { Accept: "application/octet-stream" } })).blob();
  }

  async platformBilling(params = new URLSearchParams()): Promise<PlatformBillingRecord> {
    return (await this.request<ApiEnvelope<PlatformBillingRecord>>(`/api/v1/platform/billing?${params.toString()}`)).data;
  }

  async platformAnalytics(params = new URLSearchParams()): Promise<PlatformAnalyticsRecord> {
    return (await this.request<ApiEnvelope<PlatformAnalyticsRecord>>(`/api/v1/platform/analytics?${params.toString()}`)).data;
  }

  async member(gymId: string, memberId: string): Promise<MemberRecord> {
    return (await this.request<ApiEnvelope<MemberRecord>>(
      `/api/v1/gyms/${encodeURIComponent(gymId)}/members/${encodeURIComponent(memberId)}`,
      {},
      gymId,
    )).data;
  }

  updateMember(gymId: string, memberId: string, input: UpdateMember): Promise<MemberRecord> {
    return this.updateTenantRecord<MemberRecord>(gymId, "members", memberId, input);
  }

  async createMemberAccountInvitation(gymId: string, memberId: string): Promise<CreatedMemberAccountInvitation> {
    await this.csrf();
    const response = await this.request<{ data: MemberAccountInvitationRecord; meta: { activation_token: string } }>(
      `/api/v1/gyms/${encodeURIComponent(gymId)}/members/${encodeURIComponent(memberId)}/account-invitations`,
      { method: "POST", body: JSON.stringify({ expires_in_hours: 48 }) },
      gymId,
    );
    return { invitation: response.data, activation_token: response.meta.activation_token };
  }

  async previewMemberAccountActivation(gymId: string, token: string): Promise<MemberAccountActivationPreview> {
    await this.csrf();
    return (await this.request<ApiEnvelope<MemberAccountActivationPreview>>(
      `/api/v1/gyms/${encodeURIComponent(gymId)}/member-account-invitations/preview`,
      { method: "POST", body: JSON.stringify({ token }) },
    )).data;
  }

  async acceptMemberAccountActivation(gymId: string, token: string, password?: string): Promise<AuthenticationResult> {
    await this.csrf();
    const payload = password ? { token, password, password_confirmation: password } : { token };
    return (await this.request<ApiEnvelope<AuthenticationResult>>(
      `/api/v1/gyms/${encodeURIComponent(gymId)}/member-account-invitations/accept`,
      { method: "POST", body: JSON.stringify(payload) },
    )).data;
  }

  private tenantCollection<T>(gymId: string, resource: string): Promise<Paginated<T>> {
    // Route and header tenant identifiers must agree; Laravel authorises them
    // independently and PostgreSQL RLS remains the final fail-closed boundary.
    return this.request<Paginated<T>>(`/api/v1/gyms/${encodeURIComponent(gymId)}/${resource}?per_page=100`, {}, gymId);
  }

  private async createTenantRecord<T>(gymId: string, resource: string, payload: unknown): Promise<T> {
    await this.csrf();
    return (await this.request<ApiEnvelope<T>>(`/api/v1/gyms/${encodeURIComponent(gymId)}/${resource}`, { method: "POST", body: JSON.stringify(payload) }, gymId)).data;
  }

  private async updateTenantRecord<T>(gymId: string, resource: string, id: string, payload: unknown): Promise<T> {
    await this.csrf();
    return (await this.request<ApiEnvelope<T>>(`/api/v1/gyms/${encodeURIComponent(gymId)}/${resource}/${encodeURIComponent(id)}`, { method: "PATCH", body: JSON.stringify(payload) }, gymId)).data;
  }

  branches(gymId: string) { return this.tenantCollection<BranchRecord>(gymId, "branches"); }
  createBranch(gymId: string, input: NewBranch) { return this.createTenantRecord<BranchRecord>(gymId, "branches", input); }
  updateBranch(gymId: string, branchId: string, input: UpdateBranch) { return this.updateTenantRecord<BranchRecord>(gymId, "branches", branchId, input); }
  async deleteBranch(gymId: string, branchId: string, reason: string): Promise<void> {
    await this.csrf();
    await this.request<void>(`/api/v1/gyms/${encodeURIComponent(gymId)}/branches/${encodeURIComponent(branchId)}`, { method: "DELETE", body: JSON.stringify({ reason }) }, gymId);
  }
  membershipPlans(gymId: string) { return this.tenantCollection<MembershipPlanRecord>(gymId, "membership-plans"); }
  createMembershipPlan(gymId: string, input: NewMembershipPlan) { return this.createTenantRecord<MembershipPlanRecord>(gymId, "membership-plans", input); }
  updateMembershipPlan(gymId: string, planId: string, input: UpdateMembershipPlan) { return this.updateTenantRecord<MembershipPlanRecord>(gymId, "membership-plans", planId, input); }
  memberships(gymId: string) { return this.tenantCollection<MembershipRecord>(gymId, "memberships"); }
  createMembership(gymId: string, input: NewMembership) { return this.createTenantRecord<MembershipRecord>(gymId, "memberships", input); }
  updateMembership(gymId: string, membershipId: string, input: UpdateMembership) { return this.updateTenantRecord<MembershipRecord>(gymId, "memberships", membershipId, input); }
  staff(gymId: string) { return this.tenantCollection<StaffRecord>(gymId, "staff"); }
  async ownStaffProfile(gymId: string): Promise<StaffRecord> {
    return (await this.request<ApiEnvelope<StaffRecord>>(`/api/v1/gyms/${encodeURIComponent(gymId)}/staff/me`, {}, gymId)).data;
  }
  async updateOwnStaffProfile(gymId: string, input: UpdateOwnStaffProfile): Promise<StaffRecord> {
    await this.csrf();
    return (await this.request<ApiEnvelope<StaffRecord>>(
      `/api/v1/gyms/${encodeURIComponent(gymId)}/staff/me`,
      { method: "PATCH", body: JSON.stringify(input) },
      gymId,
    )).data;
  }
  staffInvitations(gymId: string) { return this.tenantCollection<StaffInvitationRecord>(gymId, "staff-invitations"); }
  updateStaff(gymId: string, staffId: string, input: UpdateStaff) { return this.updateTenantRecord<StaffRecord>(gymId, "staff", staffId, input); }
  async createTrainer(gymId: string, input: NewTrainer): Promise<CreatedTrainer> {
    await this.csrf();
    const form = new FormData();
    form.set("name", input.name);
    form.set("email", input.email);
    form.set("phone", input.phone);
    form.set("home_branch_id", input.home_branch_id);
    form.set("status", input.status);
    if (input.profile_image) form.set("profile_image", input.profile_image);
    const response = await this.request<{ data: StaffRecord; meta: { account_setup_token: string | null; existing_account: boolean } }>(
      `/api/v1/gyms/${encodeURIComponent(gymId)}/staff`,
      { method: "POST", body: form },
      gymId,
    );
    return { trainer: response.data, ...response.meta };
  }
  async deleteStaff(gymId: string, staffId: string, reason: string): Promise<void> {
    await this.csrf();
    await this.request<void>(`/api/v1/gyms/${encodeURIComponent(gymId)}/staff/${encodeURIComponent(staffId)}`, { method: "DELETE", body: JSON.stringify({ reason }) }, gymId);
  }
  async replaceStaffProfileImage(gymId: string, staffId: string, image: File, reason: string): Promise<StaffRecord> {
    await this.csrf();
    const form = new FormData();
    form.set("profile_image", image);
    form.set("reason", reason);
    return (await this.request<ApiEnvelope<StaffRecord>>(`/api/v1/gyms/${encodeURIComponent(gymId)}/staff/${encodeURIComponent(staffId)}/profile-image`, { method: "POST", body: form }, gymId)).data;
  }
  async removeStaffProfileImage(gymId: string, staffId: string, reason: string): Promise<StaffRecord> {
    await this.csrf();
    return (await this.request<ApiEnvelope<StaffRecord>>(`/api/v1/gyms/${encodeURIComponent(gymId)}/staff/${encodeURIComponent(staffId)}/profile-image`, { method: "DELETE", body: JSON.stringify({ reason }) }, gymId)).data;
  }
  async staffProfileImage(gymId: string, staffId: string): Promise<Blob | null> {
    try {
      const response = await this.performRequest(`/api/v1/gyms/${encodeURIComponent(gymId)}/staff/${encodeURIComponent(staffId)}/profile-image`, { headers: { Accept: "image/*" } }, gymId);
      return response.blob();
    } catch (error) {
      if (error instanceof IronCoreApiError && error.status === 404) return null;
      throw error;
    }
  }
  invoices(gymId: string) { return this.tenantCollection<InvoiceRecord>(gymId, "invoices"); }
  createInvoice(gymId: string, input: NewInvoice) { return this.createTenantRecord<InvoiceRecord>(gymId, "invoices", input); }
  payments(gymId: string) { return this.tenantCollection<PaymentRecord>(gymId, "payments"); }

  async paymentSummary(gymId: string, currency: GymSummary["base_currency"]): Promise<PaymentSummaryRecord> {
    const params = new URLSearchParams({ currency });
    return (await this.request<ApiEnvelope<PaymentSummaryRecord>>(`/api/v1/gyms/${encodeURIComponent(gymId)}/payments/summary?${params}`, {}, gymId)).data;
  }

  async createPayment(gymId: string, input: NewPayment): Promise<CreatedPayment> {
    await this.csrf();
    let body: BodyInit;
    if (input.receipt) {
      const form = new FormData();
      Object.entries(input).forEach(([key, value]) => {
        if (value !== undefined) form.set(key, value instanceof File ? value : String(value));
      });
      body = form;
    } else {
      body = JSON.stringify(input);
    }
    const response = await this.request<{ data: PaymentRecord; meta: { checkout_url: string | null; idempotency_reused: boolean } }>(`/api/v1/gyms/${encodeURIComponent(gymId)}/payments`, { method: "POST", body }, gymId);
    return { payment: response.data, checkout_url: response.meta.checkout_url, idempotency_reused: response.meta.idempotency_reused };
  }

  async createRefund(gymId: string, paymentId: string, amountMinor: number, reason: string): Promise<PaymentRefundRecord> {
    await this.csrf();
    return (await this.request<ApiEnvelope<PaymentRefundRecord>>(`/api/v1/gyms/${encodeURIComponent(gymId)}/payments/${encodeURIComponent(paymentId)}/refunds`, { method: "POST", body: JSON.stringify({ amount_minor: amountMinor, reason }) }, gymId)).data;
  }

  async reviewBankTransfer(gymId: string, paymentId: string, decision: "approve" | "reject", reason: string): Promise<PaymentRecord> {
    await this.csrf();
    return (await this.request<ApiEnvelope<PaymentRecord>>(`/api/v1/gyms/${encodeURIComponent(gymId)}/payments/${encodeURIComponent(paymentId)}/bank-transfer-review`, { method: "PATCH", body: JSON.stringify({ decision, reason }) }, gymId)).data;
  }

  async paymentReceipt(gymId: string, paymentId: string): Promise<Blob> {
    const response = await this.performRequest(`/api/v1/gyms/${encodeURIComponent(gymId)}/payments/${encodeURIComponent(paymentId)}/receipt`, {}, gymId);
    return response.blob();
  }

  async paymentGateway(gymId: string): Promise<PaymentGatewayState> {
    const response = await this.request<{ data: PaymentGatewayRecord | null; meta: { provider_configured: boolean; checkout_available: boolean } }>(`/api/v1/gyms/${encodeURIComponent(gymId)}/payment-gateways/stripe`, {}, gymId);
    return { gateway: response.data, ...response.meta };
  }

  async startStripeOnboarding(gymId: string): Promise<{ gateway: PaymentGatewayRecord; onboarding_url: string }> {
    await this.csrf();
    const response = await this.request<{ data: PaymentGatewayRecord; meta: { onboarding_url: string } }>(`/api/v1/gyms/${encodeURIComponent(gymId)}/payment-gateways/stripe/onboard`, { method: "POST" }, gymId);
    return { gateway: response.data, onboarding_url: response.meta.onboarding_url };
  }

  async refreshStripeGateway(gymId: string): Promise<PaymentGatewayRecord> {
    await this.csrf();
    return (await this.request<ApiEnvelope<PaymentGatewayRecord>>(`/api/v1/gyms/${encodeURIComponent(gymId)}/payment-gateways/stripe/refresh`, { method: "POST" }, gymId)).data;
  }

  async saasPlans(gymId: string): Promise<SaasPlanRecord[]> {
    return (await this.request<ApiEnvelope<SaasPlanRecord[]>>(`/api/v1/gyms/${encodeURIComponent(gymId)}/saas-plans`, {}, gymId)).data;
  }

  async saasSubscription(gymId: string): Promise<GymSubscriptionRecord | null> {
    return (await this.request<ApiEnvelope<GymSubscriptionRecord | null>>(`/api/v1/gyms/${encodeURIComponent(gymId)}/saas-subscription`, {}, gymId)).data;
  }

  saasBillingInvoices(gymId: string): Promise<Paginated<SaasBillingInvoiceRecord>> {
    return this.tenantCollection<SaasBillingInvoiceRecord>(gymId, "saas-billing-invoices");
  }

  async saasPaymentOptions(gymId: string): Promise<SaasPaymentOptions> {
    return (await this.request<ApiEnvelope<SaasPaymentOptions>>(`/api/v1/gyms/${encodeURIComponent(gymId)}/saas-subscription/payment-options`, {}, gymId)).data;
  }

  saasSubscriptionPayments(gymId: string): Promise<Paginated<SaasSubscriptionPaymentRecord>> {
    return this.tenantCollection<SaasSubscriptionPaymentRecord>(gymId, "saas-subscription/manual-payments");
  }

  async createSaasSubscriptionPayment(gymId: string, input: NewSaasSubscriptionPayment): Promise<SaasSubscriptionPaymentRecord> {
    await this.csrf();
    let body: BodyInit;
    if (input.receipt) {
      const form = new FormData();
      Object.entries(input).forEach(([key, value]) => {
        if (value !== undefined) form.set(key, value instanceof File ? value : String(value));
      });
      body = form;
    } else {
      body = JSON.stringify(input);
    }
    return (await this.request<ApiEnvelope<SaasSubscriptionPaymentRecord>>(`/api/v1/gyms/${encodeURIComponent(gymId)}/saas-subscription/manual-payments`, { method: "POST", body }, gymId)).data;
  }

  async reviewSaasSubscriptionPayment(gymId: string, paymentId: string, decision: "approve" | "reject", reason: string): Promise<SaasSubscriptionPaymentRecord> {
    await this.csrf();
    return (await this.request<ApiEnvelope<SaasSubscriptionPaymentRecord>>(`/api/v1/gyms/${encodeURIComponent(gymId)}/saas-subscription/manual-payments/${encodeURIComponent(paymentId)}/review`, { method: "PATCH", body: JSON.stringify({ decision, reason }) }, gymId)).data;
  }

  async saasSubscriptionPaymentReceipt(gymId: string, paymentId: string): Promise<Blob> {
    const response = await this.performRequest(`/api/v1/gyms/${encodeURIComponent(gymId)}/saas-subscription/manual-payments/${encodeURIComponent(paymentId)}/receipt`, {}, gymId);
    return response.blob();
  }

  async correctSaasSubscriptionPayment(gymId: string, paymentId: string, input: NewSaasPaymentCorrection): Promise<SaasSubscriptionPaymentRecord> {
    await this.csrf();
    return (await this.request<ApiEnvelope<SaasSubscriptionPaymentRecord>>(`/api/v1/gyms/${encodeURIComponent(gymId)}/saas-subscription/manual-payments/${encodeURIComponent(paymentId)}/corrections`, { method: "POST", body: JSON.stringify(input) }, gymId)).data;
  }

  async refundSaasSubscriptionPayment(gymId: string, paymentId: string, amountMinor: number, reason: string): Promise<SaasSubscriptionPaymentRecord> {
    await this.csrf();
    return (await this.request<ApiEnvelope<SaasSubscriptionPaymentRecord>>(`/api/v1/gyms/${encodeURIComponent(gymId)}/saas-subscription/manual-payments/${encodeURIComponent(paymentId)}/refunds`, { method: "POST", body: JSON.stringify({ amount_minor: amountMinor, reason }) }, gymId)).data;
  }

  async voidSaasInvoice(gymId: string, invoiceId: string, reason: string): Promise<SaasBillingInvoiceRecord> {
    await this.csrf();
    return (await this.request<ApiEnvelope<SaasBillingInvoiceRecord>>(`/api/v1/gyms/${encodeURIComponent(gymId)}/saas-billing-invoices/${encodeURIComponent(invoiceId)}/void`, { method: "POST", body: JSON.stringify({ reason }) }, gymId)).data;
  }

  async overrideSaasBilling(gymId: string, days: number, reason: string): Promise<GymSubscriptionRecord> {
    await this.csrf();
    return (await this.request<ApiEnvelope<GymSubscriptionRecord>>(`/api/v1/gyms/${encodeURIComponent(gymId)}/saas-subscription/billing-override`, { method: "POST", body: JSON.stringify({ days, reason }) }, gymId)).data;
  }

  async saasIronCoreReceipt(gymId: string, paymentId: string): Promise<Blob> {
    return (await this.performRequest(`/api/v1/gyms/${encodeURIComponent(gymId)}/saas-subscription/manual-payments/${encodeURIComponent(paymentId)}/ironcore-receipt`, { headers: { Accept: "application/pdf" } }, gymId)).blob();
  }

  async saasPaymentReport(gymId: string, format: "csv" | "xlsx" | "pdf"): Promise<Blob> {
    return (await this.performRequest(`/api/v1/gyms/${encodeURIComponent(gymId)}/saas-subscription/payment-report?format=${format}`, { headers: { Accept: "application/octet-stream" } }, gymId)).blob();
  }

  async startSaasCheckout(gymId: string, priceId: string, idempotencyKey: string): Promise<{ checkout_url: string; idempotency_reused: boolean }> {
    await this.csrf();
    return (await this.request<ApiEnvelope<{ checkout_url: string; idempotency_reused: boolean }>>(`/api/v1/gyms/${encodeURIComponent(gymId)}/saas-subscription/checkout`, { method: "POST", body: JSON.stringify({ saas_plan_price_id: priceId, idempotency_key: idempotencyKey }) }, gymId)).data;
  }

  async openSaasPortal(gymId: string): Promise<{ portal_url: string }> {
    await this.csrf();
    return (await this.request<ApiEnvelope<{ portal_url: string }>>(`/api/v1/gyms/${encodeURIComponent(gymId)}/saas-subscription/portal`, { method: "POST" }, gymId)).data;
  }

  async createSaasPlan(input: NewSaasPlan): Promise<SaasPlanRecord> {
    await this.csrf();
    return (await this.request<ApiEnvelope<SaasPlanRecord>>(`/api/v1/platform/saas-plans`, { method: "POST", body: JSON.stringify(input) })).data;
  }

  async updateSaasPlan(planId: string, input: UpdateSaasPlan): Promise<SaasPlanRecord> {
    await this.csrf();
    return (await this.request<ApiEnvelope<SaasPlanRecord>>(
      `/api/v1/platform/saas-plans/${encodeURIComponent(planId)}`,
      { method: "PATCH", body: JSON.stringify(input) },
    )).data;
  }

  async addSaasPlanPrice(planId: string, input: NewSaasPlanPrice): Promise<SaasPlanPriceRecord> {
    await this.csrf();
    return (await this.request<ApiEnvelope<SaasPlanPriceRecord>>(
      `/api/v1/platform/saas-plans/${encodeURIComponent(planId)}/prices`,
      { method: "POST", body: JSON.stringify(input) },
    )).data;
  }

  async attendance(gymId: string): Promise<AttendanceRecord[]> {
    const params = new URLSearchParams({ per_page: "100" });
    return (await this.request<CursorPage<AttendanceRecord>>(`/api/v1/gyms/${encodeURIComponent(gymId)}/attendance?${params}`, {}, gymId)).data;
  }

  async checkIn(gymId: string, input: AttendanceCheckIn): Promise<AttendanceRecord> {
    await this.csrf();
    return (await this.request<ApiEnvelope<AttendanceRecord>>(`/api/v1/gyms/${encodeURIComponent(gymId)}/attendance/check-ins`, { method: "POST", body: JSON.stringify(input) }, gymId)).data;
  }

  async checkOut(gymId: string, attendanceId: string): Promise<AttendanceRecord> {
    await this.csrf();
    return (await this.request<ApiEnvelope<AttendanceRecord>>(`/api/v1/gyms/${encodeURIComponent(gymId)}/attendance/${encodeURIComponent(attendanceId)}/check-out`, { method: "POST" }, gymId)).data;
  }

  async issueMemberAccessCredential(gymId: string, memberId: string): Promise<MemberAccessCredentialRecord> {
    await this.csrf();
    return (await this.request<ApiEnvelope<MemberAccessCredentialRecord>>(`/api/v1/gyms/${encodeURIComponent(gymId)}/members/${encodeURIComponent(memberId)}/access-credential`, { method: "POST" }, gymId)).data;
  }

  async memberAccessCredential(gymId: string, memberId: string): Promise<MemberAccessCredentialRecord | null> {
    return (await this.request<ApiEnvelope<MemberAccessCredentialRecord | null>>(
      `/api/v1/gyms/${encodeURIComponent(gymId)}/members/${encodeURIComponent(memberId)}/access-credential`,
      {},
      gymId,
    )).data;
  }

  async rotateMemberAccessCredential(gymId: string, memberId: string): Promise<MemberAccessCredentialRecord> {
    await this.csrf();
    return (await this.request<ApiEnvelope<MemberAccessCredentialRecord>>(
      `/api/v1/gyms/${encodeURIComponent(gymId)}/members/${encodeURIComponent(memberId)}/access-credential/rotate`,
      { method: "POST" },
      gymId,
    )).data;
  }

  async memberSelfProfile(gymId: string): Promise<MemberSelfRecord> {
    return (await this.request<ApiEnvelope<MemberSelfRecord>>(`/api/v1/gyms/${encodeURIComponent(gymId)}/member/me`, {}, gymId)).data;
  }

  async updateMemberSelfProfile(gymId: string, input: UpdateMemberSelf): Promise<MemberSelfRecord> {
    await this.csrf();
    return (await this.request<ApiEnvelope<MemberSelfRecord>>(`/api/v1/gyms/${encodeURIComponent(gymId)}/member/me`, { method: "PATCH", body: JSON.stringify(input) }, gymId)).data;
  }

  async memberSelfMembership(gymId: string): Promise<MembershipRecord | null> {
    return (await this.request<ApiEnvelope<MembershipRecord | null>>(`/api/v1/gyms/${encodeURIComponent(gymId)}/member/membership`, {}, gymId)).data;
  }

  memberSelfInvoices(gymId: string): Promise<Paginated<InvoiceRecord>> {
    return this.request<Paginated<InvoiceRecord>>(`/api/v1/gyms/${encodeURIComponent(gymId)}/member/invoices?per_page=25`, {}, gymId);
  }

  memberSelfPayments(gymId: string): Promise<Paginated<PaymentRecord>> {
    return this.request<Paginated<PaymentRecord>>(`/api/v1/gyms/${encodeURIComponent(gymId)}/member/payments?per_page=25`, {}, gymId);
  }

  async memberPaymentOptions(gymId: string): Promise<MemberPaymentOptions> {
    return (await this.request<ApiEnvelope<MemberPaymentOptions>>(`/api/v1/gyms/${encodeURIComponent(gymId)}/member/payment-options`, {}, gymId)).data;
  }

  async createMemberPayment(gymId: string, input: NewMemberPayment): Promise<CreatedPayment> {
    await this.csrf();
    let body: BodyInit;
    if (input.receipt) {
      const form = new FormData();
      Object.entries(input).forEach(([key, value]) => {
        if (value !== undefined) form.set(key, value instanceof File ? value : String(value));
      });
      body = form;
    } else {
      body = JSON.stringify(input);
    }
    const response = await this.request<{ data: PaymentRecord; meta: { checkout_url: string | null; idempotency_reused: boolean } }>(`/api/v1/gyms/${encodeURIComponent(gymId)}/member/payments`, { method: "POST", body }, gymId);
    return { payment: response.data, ...response.meta };
  }

  async memberPaymentReceipt(gymId: string, paymentId: string): Promise<Blob> {
    const response = await this.performRequest(`/api/v1/gyms/${encodeURIComponent(gymId)}/member/payments/${encodeURIComponent(paymentId)}/receipt`, {}, gymId);
    return response.blob();
  }

  async memberSelfAttendance(gymId: string): Promise<AttendanceRecord[]> {
    return (await this.request<CursorPage<AttendanceRecord>>(`/api/v1/gyms/${encodeURIComponent(gymId)}/member/attendance?per_page=50`, {}, gymId)).data;
  }

  async memberSelfCredential(gymId: string): Promise<MemberSelfCredentialRecord | null> {
    return (await this.request<ApiEnvelope<MemberSelfCredentialRecord | null>>(`/api/v1/gyms/${encodeURIComponent(gymId)}/member/access-credential`, {}, gymId)).data;
  }

  async rotateMemberSelfCredential(gymId: string): Promise<MemberSelfCredentialRecord> {
    await this.csrf();
    return (await this.request<ApiEnvelope<MemberSelfCredentialRecord>>(`/api/v1/gyms/${encodeURIComponent(gymId)}/member/access-credential/rotate`, { method: "POST" }, gymId)).data;
  }

  async ensureMemberSelfCredential(gymId: string): Promise<MemberSelfCredentialRecord> {
    await this.csrf();
    return (await this.request<ApiEnvelope<MemberSelfCredentialRecord>>(`/api/v1/gyms/${encodeURIComponent(gymId)}/member/access-credential`, { method: "POST" }, gymId)).data;
  }

  classSessions(gymId: string): Promise<Paginated<ClassSessionRecord>> {
    return this.tenantCollection<ClassSessionRecord>(gymId, "class-sessions");
  }

  classBookings(gymId: string): Promise<Paginated<ClassBookingRecord>> {
    return this.tenantCollection<ClassBookingRecord>(gymId, "class-bookings");
  }

  createClassSession(gymId: string, input: NewClassSession): Promise<ClassSessionRecord> {
    return this.createTenantRecord<ClassSessionRecord>(gymId, "class-sessions", input);
  }

  updateClassSession(gymId: string, sessionId: string, input: UpdateClassSession): Promise<ClassSessionRecord> {
    return this.updateTenantRecord<ClassSessionRecord>(gymId, "class-sessions", sessionId, input);
  }

  async bookClass(gymId: string, sessionId: string, memberId?: string): Promise<ClassBookingRecord> {
    await this.csrf();
    return (await this.request<ApiEnvelope<ClassBookingRecord>>(`/api/v1/gyms/${encodeURIComponent(gymId)}/class-sessions/${encodeURIComponent(sessionId)}/bookings`, { method: "POST", body: JSON.stringify(memberId ? { member_id: memberId } : {}) }, gymId)).data;
  }

  async cancelClassBooking(gymId: string, bookingId: string, reason: string): Promise<ClassBookingRecord> {
    await this.csrf();
    return (await this.request<ApiEnvelope<ClassBookingRecord>>(`/api/v1/gyms/${encodeURIComponent(gymId)}/class-bookings/${encodeURIComponent(bookingId)}/cancel`, { method: "POST", body: JSON.stringify({ reason }) }, gymId)).data;
  }

  async attendClassBooking(gymId: string, bookingId: string): Promise<ClassBookingRecord> {
    await this.csrf();
    return (await this.request<ApiEnvelope<ClassBookingRecord>>(`/api/v1/gyms/${encodeURIComponent(gymId)}/class-bookings/${encodeURIComponent(bookingId)}/attend`, { method: "POST" }, gymId)).data;
  }

  async trainerAssignments(gymId: string): Promise<TrainerAssignmentRecord[]> {
    return (await this.request<CursorPage<TrainerAssignmentRecord>>(`/api/v1/gyms/${encodeURIComponent(gymId)}/trainer-assignments?per_page=100`, {}, gymId)).data;
  }

  async workoutPlans(gymId: string): Promise<WorkoutPlanRecord[]> {
    return (await this.request<CursorPage<WorkoutPlanRecord>>(`/api/v1/gyms/${encodeURIComponent(gymId)}/workout-plans?per_page=100`, {}, gymId)).data;
  }

  async workoutSessions(gymId: string): Promise<WorkoutSessionRecord[]> {
    return (await this.request<CursorPage<WorkoutSessionRecord>>(`/api/v1/gyms/${encodeURIComponent(gymId)}/workout-sessions?per_page=100`, {}, gymId)).data;
  }

  async progressMeasurements(gymId: string, includeHistory = false): Promise<ProgressMeasurementRecord[]> {
    const params = new URLSearchParams({ per_page: "100" });
    if (includeHistory) params.set("include_history", "1");
    return (await this.request<CursorPage<ProgressMeasurementRecord>>(`/api/v1/gyms/${encodeURIComponent(gymId)}/progress-measurements?${params}`, {}, gymId)).data;
  }

  async notificationPreference(gymId: string, memberId?: string): Promise<NotificationPreferenceRecord> {
    const params = new URLSearchParams();
    if (memberId) params.set("member_id", memberId);
    const suffix = params.size ? `?${params}` : "";
    return (await this.request<ApiEnvelope<NotificationPreferenceRecord>>(`/api/v1/gyms/${encodeURIComponent(gymId)}/notification-preferences${suffix}`, {}, gymId)).data;
  }

  async notificationDeliveries(gymId: string): Promise<NotificationDeliveryRecord[]> {
    return (await this.request<CursorPage<NotificationDeliveryRecord>>(`/api/v1/gyms/${encodeURIComponent(gymId)}/notification-deliveries?per_page=100`, {}, gymId)).data;
  }

  async reportOverview(gymId: string, from: string, to: string, currency: GymSummary["base_currency"]): Promise<ReportOverviewRecord> {
    const params = new URLSearchParams({ from, to, currency });
    // Laravel calculates every aggregate after validating the selected route +
    // header tenant; the browser never receives or combines another gym's rows.
    return (await this.request<ApiEnvelope<ReportOverviewRecord>>(
      `/api/v1/gyms/${encodeURIComponent(gymId)}/reports/overview?${params}`,
      {},
      gymId,
    )).data;
  }

  createTrainerAssignment(gymId: string, input: NewTrainerAssignment): Promise<TrainerAssignmentRecord> {
    return this.createTenantRecord<TrainerAssignmentRecord>(gymId, "trainer-assignments", input);
  }

  async endTrainerAssignment(gymId: string, assignmentId: string, reason: string): Promise<TrainerAssignmentRecord> {
    await this.csrf();
    // The selected tenant is repeated in route and verified header; Laravel
    // records the reason before closing the trainer's assignment boundary.
    return (await this.request<ApiEnvelope<TrainerAssignmentRecord>>(
      `/api/v1/gyms/${encodeURIComponent(gymId)}/trainer-assignments/${encodeURIComponent(assignmentId)}/end`,
      { method: "PATCH", body: JSON.stringify({ reason }) },
      gymId,
    )).data;
  }

  createWorkoutPlan(gymId: string, input: NewWorkoutPlan): Promise<WorkoutPlanRecord> {
    return this.createTenantRecord<WorkoutPlanRecord>(gymId, "workout-plans", input);
  }

  updateWorkoutPlan(gymId: string, planId: string, input: UpdateWorkoutPlan): Promise<WorkoutPlanRecord> {
    return this.updateTenantRecord<WorkoutPlanRecord>(gymId, "workout-plans", planId, input);
  }

  logWorkoutSession(gymId: string, input: NewWorkoutSession): Promise<WorkoutSessionRecord> {
    return this.createTenantRecord<WorkoutSessionRecord>(gymId, "workout-sessions", input);
  }

  recordProgress(gymId: string, input: NewProgressMeasurement): Promise<ProgressMeasurementRecord> {
    return this.createTenantRecord<ProgressMeasurementRecord>(gymId, "progress-measurements", input);
  }

  updateProgress(gymId: string, measurementId: string, input: UpdateProgressMeasurement): Promise<ProgressMeasurementRecord> {
    return this.updateTenantRecord<ProgressMeasurementRecord>(gymId, "progress-measurements", measurementId, input);
  }

  async deleteProgress(gymId: string, measurementId: string, reason: string): Promise<ProgressMeasurementRecord> {
    await this.csrf();
    return (await this.request<ApiEnvelope<ProgressMeasurementRecord>>(
      `/api/v1/gyms/${encodeURIComponent(gymId)}/progress-measurements/${encodeURIComponent(measurementId)}`,
      { method: "DELETE", body: JSON.stringify({ reason }) },
      gymId,
    )).data;
  }

  async updateNotificationPreference(gymId: string, input: UpdateNotificationPreference): Promise<NotificationPreferenceRecord> {
    await this.csrf();
    return (await this.request<ApiEnvelope<NotificationPreferenceRecord>>(`/api/v1/gyms/${encodeURIComponent(gymId)}/notification-preferences`, { method: "PATCH", body: JSON.stringify(input) }, gymId)).data;
  }

  async createStaffInvitation(gymId: string, input: NewStaffInvitation): Promise<CreatedStaffInvitation> {
    await this.csrf();
    const response = await this.request<{ data: StaffInvitationRecord; meta: { acceptance_token: string } }>(`/api/v1/gyms/${encodeURIComponent(gymId)}/staff-invitations`, { method: "POST", body: JSON.stringify(input) }, gymId);
    return { invitation: response.data, acceptance_token: response.meta.acceptance_token };
  }

  async acceptStaffInvitation(gymId: string, token: string): Promise<StaffRecord> {
    await this.csrf();
    // Acceptance intentionally runs before tenant membership exists. Laravel
    // binds RLS from the route gym and validates the hashed token + user email.
    return (await this.request<ApiEnvelope<StaffRecord>>(`/api/v1/gyms/${encodeURIComponent(gymId)}/staff-invitations/accept`, { method: "POST", body: JSON.stringify({ token }) })).data;
  }
}
