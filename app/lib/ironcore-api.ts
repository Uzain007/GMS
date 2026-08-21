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
  status: string;
  trial_ends_at?: string | null;
  created_at?: string | null;
};

export type NewGym = {
  name: string;
  legal_name?: string;
  slug?: string;
  base_currency: GymSummary["base_currency"];
  country_code: string;
  timezone: string;
  owner: { name: string; email: string };
};

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
  created_at: string | null;
};

export type MemberSelfRecord = Pick<MemberRecord,
  "member_number" | "member_code" | "first_name" | "last_name" | "email" | "phone" |
  "date_of_birth" | "status" | "joined_at"
>;

export type NewMember = {
  first_name: string;
  last_name: string;
  email?: string;
  phone?: string;
  status?: MemberRecord["status"];
};
export type UpdateMember = { first_name: string; last_name: string; email?: string | null; phone?: string | null; status: MemberRecord["status"]; reason: string };

export type BranchRecord = { id: string; gym_id: string; name: string; code: string; email: string | null; phone: string | null; timezone: string; status: "active" | "inactive"; is_primary: boolean; created_at: string | null };
export type NewBranch = { name: string; code: string; email?: string; phone?: string; is_primary?: boolean };
export type UpdateBranch = { name: string; code: string; email?: string | null; phone?: string | null; status: BranchRecord["status"]; is_primary: boolean; reason: string };
export type MembershipPlanRecord = { id: string; gym_id: string; branch_id: string | null; name: string; code: string; billing_interval: "one_time" | "weekly" | "monthly" | "quarterly" | "yearly"; interval_count: number; price_amount_minor: number; currency: GymSummary["base_currency"]; joining_fee_minor: number; status: "active" | "inactive"; created_at: string | null };
export type NewMembershipPlan = { name: string; code: string; branch_id?: string; billing_interval: MembershipPlanRecord["billing_interval"]; interval_count: number; price_amount_minor: number; currency: GymSummary["base_currency"]; joining_fee_minor?: number; status?: "active" };
export type UpdateMembershipPlan = { name: string; code: string; branch_id?: string | null; price_amount_minor: number; currency: GymSummary["base_currency"]; status: MembershipPlanRecord["status"]; reason: string };
export type MembershipRecord = { id: string; gym_id: string; member_id: string; plan_id: string; branch_id: string | null; status: "pending" | "active" | "paused" | "cancelled" | "expired"; starts_at: string; ends_at?: string | null; next_billing_at: string | null; price_amount_minor: number; currency: GymSummary["base_currency"]; joining_fee_minor?: number; billing_interval?: "one_time" | "weekly" | "monthly" | "quarterly" | "yearly"; interval_count?: number; auto_renew: boolean; plan?: { id: string; name: string; code: string }; branch?: { id: string; name: string } | null; created_at: string | null };
export type NewMembership = { member_id: string; plan_id: string; branch_id?: string; starts_at: string; status?: "pending" | "active"; auto_renew?: boolean };
export type UpdateMembership = { status: MembershipRecord["status"]; ends_at?: string | null; next_billing_at?: string | null; auto_renew: boolean; cancellation_reason?: string | null; reason: string };
export type UpdateMemberSelf = { first_name?: string; last_name?: string; email?: string | null; phone?: string | null; date_of_birth?: string | null };
export type StaffRole = "gym_owner" | "gym_manager" | "receptionist" | "trainer";
export type StaffRecord = { id: string; gym_id: string; user: { id: string; name: string; email: string }; role: StaffRole; home_branch_id: string | null; employee_number: string; job_title: string | null; status: "active" | "suspended" | "inactive"; hired_at: string | null; terminated_at: string | null; created_at: string | null };
export type StaffInvitationRecord = { id: string; gym_id: string; home_branch_id: string | null; email: string; role: StaffRole; employee_number: string; job_title: string | null; status: "pending" | "accepted" | "revoked" | "expired"; expires_at: string; accepted_at: string | null; created_at: string | null };
export type NewStaffInvitation = { email: string; role: StaffRole; employee_number: string; job_title?: string; home_branch_id?: string; expires_in_days?: number };
export type UpdateStaff = { role?: StaffRole; employee_number?: string; job_title?: string | null; home_branch_id?: string | null; status?: StaffRecord["status"]; hired_at?: string | null; terminated_at?: string | null; reason: string };
export type CreatedStaffInvitation = { invitation: StaffInvitationRecord; acceptance_token: string };
export type MemberAccountInvitationRecord = { id: string; gym_id: string; member_id: string; email: string; status: "pending" | "accepted" | "revoked" | "expired"; expires_at: string; accepted_at: string | null; revoked_at: string | null; created_at: string | null };
export type CreatedMemberAccountInvitation = { invitation: MemberAccountInvitationRecord; activation_token: string };
export type MemberAccountActivationPreview = { gym_name: string; member_first_name: string; masked_email: string; existing_account: boolean };
export type InvoiceItemRecord = { id: string; invoice_id: string; description: string; quantity: number; unit_amount_minor: number; subtotal_amount_minor: number; tax_amount_minor: number; total_amount_minor: number };
export type InvoiceRecord = { id: string; gym_id: string; member_id: string; membership_id: string | null; branch_id: string | null; number: string; status: "draft" | "open" | "paid" | "void" | "uncollectible"; currency: GymSummary["base_currency"]; subtotal_amount_minor: number; tax_amount_minor: number; total_amount_minor: number; paid_amount_minor: number; due_amount_minor: number; issued_at: string; due_at: string | null; paid_at: string | null; notes: string | null; items: InvoiceItemRecord[]; created_at: string | null };
export type PaymentRefundRecord = { id: string; payment_id: string; status: "pending" | "succeeded" | "failed"; amount_minor: number; currency: GymSummary["base_currency"]; reason: string; refunded_at: string | null; created_at: string | null };
export type PaymentRecord = { id: string; gym_id: string; member_id: string; membership_id: string | null; invoice_id: string | null; branch_id: string | null; receipt_number: string; provider: "manual" | "stripe"; method: "cash" | "card" | "bank_transfer" | "online_card" | "other"; status: "pending" | "succeeded" | "failed" | "partially_refunded" | "refunded" | "voided"; amount_minor: number; refunded_amount_minor: number; currency: GymSummary["base_currency"]; paid_at: string | null; failure_message: string | null; notes: string | null; provider_checkout_id: string | null; refunds: PaymentRefundRecord[]; created_at: string | null };
export type PaymentSummaryRecord = { gross_minor: number; refunded_minor: number; net_minor: number; pending_minor: number; outstanding_minor: number; currency: GymSummary["base_currency"] };
export type PaymentGatewayRecord = { id: string; provider: "stripe"; status: "pending" | "restricted" | "active" | "disabled"; charges_enabled: boolean; payouts_enabled: boolean; details_submitted: boolean; country_code: string; default_currency: GymSummary["base_currency"]; requirements: { currently_due?: string[]; eventually_due?: string[]; disabled_reason?: string | null } | null; connected_at: string | null; provider_account_id: string | null };
export type NewInvoice = { member_id: string; membership_id?: string; branch_id?: string; currency: GymSummary["base_currency"]; issued_at?: string; due_at?: string; notes?: string; items: Array<{ description: string; quantity: number; unit_amount_minor: number; tax_amount_minor?: number }> };
export type NewPayment = { member_id: string; membership_id?: string; invoice_id?: string; branch_id?: string; method: PaymentRecord["method"]; amount_minor: number; currency: GymSummary["base_currency"]; idempotency_key: string; paid_at?: string; notes?: string };
export type CreatedPayment = { payment: PaymentRecord; checkout_url: string | null; idempotency_reused: boolean };
export type SaasFeatureLimits = { members: number; branches: number; staff: number; advanced_reports: boolean; priority_support: boolean };
export type SaasPlanPriceRecord = { id: string; currency: GymSummary["base_currency"]; billing_interval: "monthly" | "yearly"; amount_minor: number; trial_days: number; active: boolean };
export type SaasPlanRecord = { id: string; code: string; name: string; description: string | null; status: "draft" | "active" | "archived"; feature_limits: SaasFeatureLimits; sort_order: number; prices: SaasPlanPriceRecord[]; created_at: string | null };
export type GymSubscriptionRecord = { id: string; gym_id: string; status: "incomplete" | "trialing" | "active" | "past_due" | "unpaid" | "paused" | "cancelled" | "incomplete_expired"; plan_code: string; plan_name: string; feature_limits: SaasFeatureLimits; currency: GymSummary["base_currency"]; amount_minor: number; billing_interval: "monthly" | "yearly"; current_period_start: string | null; current_period_end: string | null; trial_ends_at: string | null; cancel_at_period_end: boolean; cancelled_at: string | null; ended_at: string | null; failure_code: string | null; failure_message: string | null; billing_contact?: { email: string; name: string | null }; created_at: string | null };
export type SaasBillingInvoiceRecord = { id: string; number: string | null; status: "draft" | "open" | "paid" | "void" | "uncollectible"; currency: GymSummary["base_currency"]; amount_due_minor: number; amount_paid_minor: number; amount_remaining_minor: number; hosted_invoice_url: string | null; invoice_pdf_url: string | null; period_start: string | null; period_end: string | null; due_at: string | null; paid_at: string | null; created_at: string | null };
export type NewSaasPlan = { code: string; name: string; description?: string; sort_order?: number; feature_limits: SaasFeatureLimits; currency: GymSummary["base_currency"]; billing_interval: "monthly" | "yearly"; amount_minor: number; trial_days?: number };
export type MemberAccessCredentialRecord = { id: string; gym_id: string; member_id: string; credential_hint: string; status: "active" | "revoked" | "expired"; expires_at: string | null; last_used_at: string | null; created_at: string | null; credential?: string };
export type MemberSelfCredentialRecord = Pick<MemberAccessCredentialRecord,
  "credential_hint" | "status" | "expires_at" | "last_used_at" | "created_at"
> & { credential?: string };
export type AttendanceRecord = { id: string; gym_id: string; member_id: string; membership_id: string; branch_id: string; member?: { id: string; member_number: string; member_code?: string; name: string }; branch?: { id: string; name: string }; method: "qr" | "member_code" | "manual"; status: "checked_in" | "checked_out"; checked_in_at: string; checked_out_at: string | null };
export type ClassSessionRecord = { id: string; gym_id: string; branch_id: string; trainer_staff_profile_id: string | null; branch?: { id: string; name: string }; trainer?: { id: string; name: string | null } | null; title: string; description: string | null; starts_at: string; ends_at: string; capacity: number; booked_count: number; waitlist_count: number; attended_count: number; waitlist_enabled: boolean; booking_opens_at: string | null; booking_closes_at: string | null; status: "scheduled" | "cancelled" | "completed"; cancellation_reason: string | null; created_at: string | null };
export type ClassBookingRecord = { id: string; gym_id: string; class_session_id: string; member_id: string; membership_id: string; member?: { id: string; member_number: string; name: string }; session?: { id: string; title: string; starts_at: string }; status: "booked" | "waitlisted" | "cancelled" | "attended" | "no_show"; waitlist_sequence: number | null; booked_at: string; promoted_at: string | null; cancelled_at: string | null; checked_in_at: string | null; cancellation_reason: string | null };
export type NewClassSession = { branch_id: string; trainer_staff_profile_id?: string; title: string; description?: string; starts_at: string; ends_at: string; capacity: number; waitlist_enabled?: boolean; booking_opens_at?: string; booking_closes_at?: string };
export type AttendanceCheckIn = { branch_id: string; credential?: string; member_code?: string; member_id?: string };
export type TrainerAssignmentRecord = { id: string; gym_id: string; trainer_staff_profile_id: string; member_id: string; trainer?: { id: string; name: string | null }; member?: { id: string; member_number: string; name: string }; status: "active" | "inactive"; starts_on: string; ends_on: string | null; notes: string | null; created_at: string | null };
export type WorkoutExerciseRecord = { id: string; gym_id: string; workout_plan_id: string; name: string; instructions: string | null; day_number: number; sort_order: number; target_sets: number | null; target_reps_min: number | null; target_reps_max: number | null; target_load_grams: number | null; target_duration_seconds: number | null; rest_seconds: number | null };
export type WorkoutPlanRecord = { id: string; gym_id: string; member_id: string; trainer_staff_profile_id: string; member?: { id: string; member_number: string; name: string }; trainer?: { id: string; name: string | null }; title: string; goal: string | null; notes: string | null; starts_on: string; ends_on: string | null; status: "draft" | "active" | "completed" | "cancelled"; exercises: WorkoutExerciseRecord[]; created_at: string | null };
export type WorkoutSetRecord = { id: string; gym_id: string; workout_plan_exercise_id: string; exercise_name?: string; set_number: number; reps: number | null; load_grams: number | null; duration_seconds: number | null; distance_metres: number | null; rpe: number | null };
export type WorkoutSessionRecord = { id: string; gym_id: string; workout_plan_id: string; member_id: string; plan?: { id: string; title: string }; member?: { id: string; member_number: string; name: string }; performed_at: string; duration_seconds: number | null; notes: string | null; sets: WorkoutSetRecord[]; created_at: string | null };
export type ProgressMeasurementRecord = { id: string; gym_id: string; member_id: string; member?: { id: string; member_number: string; name: string }; metric: "body_weight" | "body_fat" | "waist" | "chest" | "hips" | "biceps" | "thigh" | "custom"; value_milli: number; unit: "kg" | "percent" | "cm" | "count" | "seconds" | "metres" | "custom"; measured_at: string; note: string | null; created_at: string | null };
export type NotificationPreferenceRecord = { id: string | null; gym_id: string; member_id: string; email_enabled: boolean; sms_enabled: boolean; push_enabled: boolean; class_reminders_enabled: boolean; workout_reminders_enabled: boolean; payment_reminders_enabled: boolean; marketing_enabled: boolean; quiet_hours_start: string | null; quiet_hours_end: string | null; timezone: string };
export type NotificationDeliveryRecord = { id: string; gym_id: string; member_id: string; channel: "email" | "sms" | "push"; template_key: string; status: "queued" | "sending" | "sent" | "failed" | "suppressed"; attempts: number; scheduled_at: string; sent_at: string | null; failure_code: string | null; created_at: string | null };
export type NewTrainerAssignment = { trainer_staff_profile_id: string; member_id: string; starts_on: string; ends_on?: string; notes?: string };
export type NewWorkoutPlan = { member_id: string; trainer_staff_profile_id: string; title: string; goal?: string; notes?: string; starts_on: string; ends_on?: string; status?: WorkoutPlanRecord["status"]; exercises: Array<{ name: string; instructions?: string; day_number: number; sort_order: number; target_sets?: number; target_reps_min?: number; target_reps_max?: number; target_load_grams?: number; target_duration_seconds?: number; rest_seconds?: number }> };
export type NewWorkoutSession = { workout_plan_id: string; member_id?: string; performed_at: string; duration_seconds?: number; notes?: string; sets: Array<{ workout_plan_exercise_id: string; set_number: number; reps?: number; load_grams?: number; duration_seconds?: number; distance_metres?: number; rpe?: number }> };
export type NewProgressMeasurement = { member_id?: string; metric: ProgressMeasurementRecord["metric"]; value_milli: number; unit: ProgressMeasurementRecord["unit"]; measured_at: string; note?: string };
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
    const method = (options.method ?? "GET").toUpperCase();
    const headers = new Headers(options.headers);
    headers.set("Accept", "application/json");
    headers.set("X-Requested-With", "XMLHttpRequest");

    if (options.body) headers.set("Content-Type", "application/json");
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

    if (response.status === 204) return undefined as T;
    return response.json() as Promise<T>;
  }

  async csrf(): Promise<void> {
    await this.request<void>("/sanctum/csrf-cookie");
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

  async createGym(input: NewGym): Promise<GymSummary> {
    await this.csrf();
    return (await this.request<ApiEnvelope<GymSummary>>("/api/v1/gyms", {
      method: "POST",
      body: JSON.stringify(input),
    })).data;
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
  membershipPlans(gymId: string) { return this.tenantCollection<MembershipPlanRecord>(gymId, "membership-plans"); }
  createMembershipPlan(gymId: string, input: NewMembershipPlan) { return this.createTenantRecord<MembershipPlanRecord>(gymId, "membership-plans", input); }
  updateMembershipPlan(gymId: string, planId: string, input: UpdateMembershipPlan) { return this.updateTenantRecord<MembershipPlanRecord>(gymId, "membership-plans", planId, input); }
  memberships(gymId: string) { return this.tenantCollection<MembershipRecord>(gymId, "memberships"); }
  createMembership(gymId: string, input: NewMembership) { return this.createTenantRecord<MembershipRecord>(gymId, "memberships", input); }
  updateMembership(gymId: string, membershipId: string, input: UpdateMembership) { return this.updateTenantRecord<MembershipRecord>(gymId, "memberships", membershipId, input); }
  staff(gymId: string) { return this.tenantCollection<StaffRecord>(gymId, "staff"); }
  staffInvitations(gymId: string) { return this.tenantCollection<StaffInvitationRecord>(gymId, "staff-invitations"); }
  updateStaff(gymId: string, staffId: string, input: UpdateStaff) { return this.updateTenantRecord<StaffRecord>(gymId, "staff", staffId, input); }
  invoices(gymId: string) { return this.tenantCollection<InvoiceRecord>(gymId, "invoices"); }
  createInvoice(gymId: string, input: NewInvoice) { return this.createTenantRecord<InvoiceRecord>(gymId, "invoices", input); }
  payments(gymId: string) { return this.tenantCollection<PaymentRecord>(gymId, "payments"); }

  async paymentSummary(gymId: string, currency: GymSummary["base_currency"]): Promise<PaymentSummaryRecord> {
    const params = new URLSearchParams({ currency });
    return (await this.request<ApiEnvelope<PaymentSummaryRecord>>(`/api/v1/gyms/${encodeURIComponent(gymId)}/payments/summary?${params}`, {}, gymId)).data;
  }

  async createPayment(gymId: string, input: NewPayment): Promise<CreatedPayment> {
    await this.csrf();
    const response = await this.request<{ data: PaymentRecord; meta: { checkout_url: string | null; idempotency_reused: boolean } }>(`/api/v1/gyms/${encodeURIComponent(gymId)}/payments`, { method: "POST", body: JSON.stringify(input) }, gymId);
    return { payment: response.data, checkout_url: response.meta.checkout_url, idempotency_reused: response.meta.idempotency_reused };
  }

  async createRefund(gymId: string, paymentId: string, amountMinor: number, reason: string): Promise<PaymentRefundRecord> {
    await this.csrf();
    return (await this.request<ApiEnvelope<PaymentRefundRecord>>(`/api/v1/gyms/${encodeURIComponent(gymId)}/payments/${encodeURIComponent(paymentId)}/refunds`, { method: "POST", body: JSON.stringify({ amount_minor: amountMinor, reason }) }, gymId)).data;
  }

  async paymentGateway(gymId: string): Promise<PaymentGatewayRecord | null> {
    return (await this.request<ApiEnvelope<PaymentGatewayRecord | null>>(`/api/v1/gyms/${encodeURIComponent(gymId)}/payment-gateways/stripe`, {}, gymId)).data;
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

  async memberSelfAttendance(gymId: string): Promise<AttendanceRecord[]> {
    return (await this.request<CursorPage<AttendanceRecord>>(`/api/v1/gyms/${encodeURIComponent(gymId)}/member/attendance?per_page=50`, {}, gymId)).data;
  }

  async memberSelfCredential(gymId: string): Promise<MemberSelfCredentialRecord | null> {
    return (await this.request<ApiEnvelope<MemberSelfCredentialRecord | null>>(`/api/v1/gyms/${encodeURIComponent(gymId)}/member/access-credential`, {}, gymId)).data;
  }

  async rotateMemberSelfCredential(gymId: string): Promise<MemberSelfCredentialRecord> {
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

  async progressMeasurements(gymId: string): Promise<ProgressMeasurementRecord[]> {
    return (await this.request<CursorPage<ProgressMeasurementRecord>>(`/api/v1/gyms/${encodeURIComponent(gymId)}/progress-measurements?per_page=100`, {}, gymId)).data;
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

  updateWorkoutPlan(gymId: string, planId: string, input: Partial<Pick<WorkoutPlanRecord, "title" | "goal" | "notes" | "ends_on" | "status">> & { reason?: string }): Promise<WorkoutPlanRecord> {
    return this.updateTenantRecord<WorkoutPlanRecord>(gymId, "workout-plans", planId, input);
  }

  logWorkoutSession(gymId: string, input: NewWorkoutSession): Promise<WorkoutSessionRecord> {
    return this.createTenantRecord<WorkoutSessionRecord>(gymId, "workout-sessions", input);
  }

  recordProgress(gymId: string, input: NewProgressMeasurement): Promise<ProgressMeasurementRecord> {
    return this.createTenantRecord<ProgressMeasurementRecord>(gymId, "progress-measurements", input);
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
