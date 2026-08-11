"use client";

import { ArrowRight, Building2, LoaderCircle, LockKeyhole, ShieldCheck } from "lucide-react";
import { FormEvent, useCallback, useEffect, useMemo, useRef, useState } from "react";
import { IronCoreDashboard, type DashboardMember, type NewDashboardMember, type View } from "./ironcore-dashboard";
import { MemberPortal, type MemberPortalData } from "./member-portal";
import { MemberAccountActivation, type MemberActivationSecret } from "./member-account-activation";
import type { FinanceData, NewFinanceInvoice, NewFinancePayment } from "./financial-management";
import type { SaasBillingData } from "./saas-billing-management";
import type { EngagementData } from "./engagement-management";
import type { CoachingData } from "./coaching-management";
import type { ReportData } from "./report-management";
import type { NewOperationBranch, NewOperationMembership, NewOperationPlan, OperationData } from "./tenant-operations";
import type { NewStaffInvite, StaffData, StaffUpdate } from "./staff-management";
import {
  IronCoreApi,
  IronCoreApiError,
  type AuthenticatedUser,
  type GymSummary,
  type IronCoreRole,
  type MemberRecord,
  type BranchRecord,
  type MembershipPlanRecord,
  type MembershipRecord,
  type StaffRecord,
  type StaffInvitationRecord,
  type InvoiceRecord,
  type PaymentRecord,
  type PaymentSummaryRecord,
  type PaymentGatewayRecord,
  type GymSubscriptionRecord,
  type NewSaasPlan,
  type SaasBillingInvoiceRecord,
  type SaasPlanRecord,
  type AttendanceRecord,
  type ClassBookingRecord,
  type ClassSessionRecord,
  type NewClassSession,
  type TrainerAssignmentRecord,
  type WorkoutPlanRecord,
  type WorkoutSessionRecord,
  type ProgressMeasurementRecord,
  type NotificationPreferenceRecord,
  type NotificationDeliveryRecord,
  type NewTrainerAssignment,
  type NewWorkoutPlan,
  type NewWorkoutSession,
  type NewProgressMeasurement,
  type UpdateNotificationPreference,
  type ReportOverviewRecord,
  type MemberSelfRecord,
  type MemberSelfCredentialRecord,
  type UpdateMemberSelf,
  type MemberAccountActivationPreview,
} from "./lib/ironcore-api";

type GymAccess = GymSummary & { role: IronCoreRole };
type MemberState = { rows: DashboardMember[]; total: number; loading: boolean; error: string | null };
type OperationsState = { branches: BranchRecord[]; plans: MembershipPlanRecord[]; memberships: MembershipRecord[]; loading: boolean; error: string | null };
type StaffState = { rows: StaffRecord[]; invitations: StaffInvitationRecord[]; loading: boolean; error: string | null };
type FinanceState = { payments: PaymentRecord[]; invoices: InvoiceRecord[]; summary: PaymentSummaryRecord | null; gateway: PaymentGatewayRecord | null; loading: boolean; error: string | null };
type SaasState = { plans: SaasPlanRecord[]; subscription: GymSubscriptionRecord | null; invoices: SaasBillingInvoiceRecord[]; loading: boolean; error: string | null };
type EngagementState = { attendance: AttendanceRecord[]; sessions: ClassSessionRecord[]; bookings: ClassBookingRecord[]; loading: boolean; error: string | null };
type CoachingState = { assignments: TrainerAssignmentRecord[]; plans: WorkoutPlanRecord[]; sessions: WorkoutSessionRecord[]; measurements: ProgressMeasurementRecord[]; preference: NotificationPreferenceRecord | null; deliveries: NotificationDeliveryRecord[]; loading: boolean; error: string | null };
type ReportState = { report: ReportOverviewRecord | null; from: string; to: string; currency: GymSummary["base_currency"]; loading: boolean; error: string | null };
type MemberSelfState = {
  profile: MemberSelfRecord | null;
  membership: MembershipRecord | null;
  invoices: InvoiceRecord[];
  payments: PaymentRecord[];
  attendance: AttendanceRecord[];
  credential: MemberSelfCredentialRecord | null;
  loading: boolean;
  error: string | null;
};

const apiOrigin = process.env.NEXT_PUBLIC_IRONCORE_API_URL?.trim() ?? "";
const demoMode = process.env.NEXT_PUBLIC_IRONCORE_DEMO_MODE === "true" || !apiOrigin;
const demoOperations: OperationData = {
  // Representative preview records stay inside demo mode. Authenticated mode
  // always replaces them with collections fetched for the explicitly selected
  // gym, so preview content can never become tenant authority.
  branches: [
    { id: "demo-branch-1", name: "Manchester Central", code: "MCR-CENTRAL", email: "central@forge.example", phone: "+44 161 555 0142", status: "active", isPrimary: true },
    { id: "demo-branch-2", name: "Salford Quays", code: "SQ-01", email: "salford@forge.example", phone: "+44 161 555 0188", status: "active", isPrimary: false },
  ],
  plans: [
    { id: "demo-plan-1", branchId: null, name: "Unlimited", code: "UNLIMITED", interval: "monthly", intervalCount: 1, priceMinor: 8900, currency: "GBP", status: "active" },
    { id: "demo-plan-2", branchId: "demo-branch-1", name: "Off-Peak", code: "OFF-PEAK", interval: "monthly", intervalCount: 1, priceMinor: 5900, currency: "GBP", status: "active" },
    { id: "demo-plan-3", branchId: null, name: "Day Pass", code: "DAY-PASS", interval: "one_time", intervalCount: 1, priceMinor: 1500, currency: "GBP", status: "active" },
  ],
  memberships: [
    { id: "demo-membership-1", memberId: "demo-1", planId: "demo-plan-1", status: "active", startsAt: "2026-08-01", nextBillingAt: "2026-09-01", priceMinor: 8900, currency: "GBP" },
    { id: "demo-membership-2", memberId: "demo-2", planId: "demo-plan-2", status: "active", startsAt: "2026-07-12", nextBillingAt: "2026-08-12", priceMinor: 5900, currency: "GBP" },
    { id: "demo-membership-3", memberId: "demo-3", planId: "demo-plan-1", status: "paused", startsAt: "2026-06-20", nextBillingAt: null, priceMinor: 8900, currency: "GBP" },
  ],
  members: [
    { id: "demo-1", name: "Amelia Hart" },
    { id: "demo-2", name: "Hassan Malik" },
    { id: "demo-3", name: "Omar Al-Farsi" },
  ],
  loading: false,
  error: null,
  baseCurrency: "GBP",
  canManageSetup: true,
  canManageMemberships: true,
  preview: true,
  onReload: () => undefined,
  onCreateBranch: async () => undefined,
  onCreatePlan: async () => undefined,
  onCreateMembership: async () => undefined,
};
const demoMembers: DashboardMember[] = [
  { id: "demo-1", name: "Amelia Hart", gym: "Forge Fitness", membership: "MBR-1042", joined: "04 Aug 2026", status: "Active", email: "amelia@example.com", accountLinked: false },
  { id: "demo-2", name: "Hassan Malik", gym: "Forge Fitness", membership: "MBR-1187", joined: "03 Aug 2026", status: "Active", email: "hassan@example.com", accountLinked: true },
  { id: "demo-3", name: "Omar Al-Farsi", gym: "Forge Fitness", membership: "MBR-1216", joined: "02 Aug 2026", status: "Paused", email: null, accountLinked: false },
];
const demoStaff: StaffData = {
  rows: [
    { id: "demo-staff-1", name: "Aisha Khan", email: "aisha@forge.example", role: "gym_manager", branchId: "demo-branch-1", employeeNumber: "MGR-001", jobTitle: "General Manager", status: "active", hiredAt: "2025-04-12" },
    { id: "demo-staff-2", name: "Daniel Reed", email: "daniel@forge.example", role: "trainer", branchId: "demo-branch-1", employeeNumber: "TRN-014", jobTitle: "Strength Coach", status: "active", hiredAt: "2026-01-08" },
  ],
  invitations: [{ id: "demo-invite-1", email: "frontdesk@forge.example", role: "receptionist", branchId: "demo-branch-1", employeeNumber: "REC-009", jobTitle: "Front Desk", status: "pending", expiresAt: "2026-08-14T12:00:00Z" }],
  branches: [{ id: "demo-branch-1", name: "Manchester Central" }], loading: false, error: null, actorRole: "gym_owner", onReload: () => undefined,
  onInvite: async () => `${typeof window === "undefined" ? "https://ironcore.example" : window.location.origin}/#demo-invitation`,
  onUpdate: async () => undefined,
};
const demoFinance: FinanceData = {
  payments: [
    { id: "demo-payment-1", memberId: "demo-1", invoiceId: "demo-invoice-1", branchId: "demo-branch-1", receipt: "PAY-01JZ8K3", provider: "stripe", method: "online_card", status: "succeeded", amountMinor: 8900, refundedMinor: 0, currency: "GBP", paidAt: "2026-08-07T09:42:00Z", notes: null },
    { id: "demo-payment-2", memberId: "demo-2", invoiceId: null, branchId: "demo-branch-1", receipt: "PAY-01JZ8GW", provider: "manual", method: "cash", status: "partially_refunded", amountMinor: 4200, refundedMinor: 1000, currency: "GBP", paidAt: "2026-08-07T08:18:00Z", notes: "Front desk payment" },
    { id: "demo-payment-3", memberId: "demo-3", invoiceId: null, branchId: "demo-branch-1", receipt: "PAY-01JZ87Q", provider: "manual", method: "card", status: "succeeded", amountMinor: 12600, refundedMinor: 0, currency: "GBP", paidAt: "2026-08-06T15:05:00Z", notes: "Terminal receipt 8441" },
  ],
  invoices: [
    { id: "demo-invoice-1", memberId: "demo-1", membershipId: "demo-membership-1", branchId: "demo-branch-1", number: "INV-01JZ8JK", status: "paid", currency: "GBP", totalMinor: 8900, paidMinor: 8900, dueMinor: 0, issuedAt: "2026-08-01T09:00:00Z", dueAt: "2026-08-07T23:59:00Z", notes: null },
    { id: "demo-invoice-2", memberId: "demo-4", membershipId: null, branchId: "demo-branch-1", number: "INV-01JZ7FD", status: "open", currency: "GBP", totalMinor: 4900, paidMinor: 0, dueMinor: 4900, issuedAt: "2026-08-05T09:00:00Z", dueAt: "2026-08-12T23:59:00Z", notes: "Monthly membership" },
  ],
  members: [{ id: "demo-1", name: "Amelia Hart" }, { id: "demo-2", name: "Hassan Malik" }, { id: "demo-3", name: "Omar Al-Farsi" }, { id: "demo-4", name: "Sarah Collins" }],
  memberships: [{ id: "demo-membership-1", memberId: "demo-1", label: "Unlimited · active" }],
  branches: [{ id: "demo-branch-1", name: "Manchester Central" }],
  summary: { grossMinor: 25700, refundedMinor: 1000, netMinor: 24700, pendingMinor: 0, outstandingMinor: 4900, currency: "GBP" },
  gateway: { status: "active", chargesEnabled: true, payoutsEnabled: true, detailsSubmitted: true, accountId: "acct_demo••4812", requirements: [] },
  actorRole: "gym_owner", loading: false, error: null, onReload: () => undefined,
  onCreateInvoice: async () => undefined, onCreatePayment: async () => null, onRefund: async () => undefined,
  onConnectStripe: async () => "#demo-stripe", onRefreshStripe: async () => undefined,
};
const demoSaasBilling: SaasBillingData = {
  plans: [
    { id: "saas-starter", code: "starter", name: "Starter", description: "Simple operations for a new independent gym.", status: "active", sort_order: 10, feature_limits: { members: 500, branches: 1, staff: 8, advanced_reports: false, priority_support: false }, prices: [{ id: "starter-gbp-month", currency: "GBP", billing_interval: "monthly", amount_minor: 3900, trial_days: 14, active: true }, { id: "starter-gbp-year", currency: "GBP", billing_interval: "yearly", amount_minor: 39000, trial_days: 14, active: true }], created_at: "2026-08-07T10:00:00Z" },
    { id: "saas-growth", code: "growth", name: "Growth", description: "Automation, payments and reporting for growing gym teams.", status: "active", sort_order: 20, feature_limits: { members: 2500, branches: 3, staff: 30, advanced_reports: true, priority_support: false }, prices: [{ id: "growth-gbp-month", currency: "GBP", billing_interval: "monthly", amount_minor: 7900, trial_days: 14, active: true }, { id: "growth-gbp-year", currency: "GBP", billing_interval: "yearly", amount_minor: 79000, trial_days: 14, active: true }], created_at: "2026-08-07T10:00:00Z" },
    { id: "saas-scale", code: "scale", name: "Scale", description: "Multi-location controls and priority support for fitness groups.", status: "active", sort_order: 30, feature_limits: { members: 1000000, branches: 1000, staff: 10000, advanced_reports: true, priority_support: true }, prices: [{ id: "scale-gbp-month", currency: "GBP", billing_interval: "monthly", amount_minor: 14900, trial_days: 14, active: true }, { id: "scale-gbp-year", currency: "GBP", billing_interval: "yearly", amount_minor: 149000, trial_days: 14, active: true }], created_at: "2026-08-07T10:00:00Z" },
  ],
  subscription: { id: "demo-saas-subscription", gym_id: "demo-gym", status: "active", plan_code: "growth", plan_name: "Growth", feature_limits: { members: 2500, branches: 3, staff: 30, advanced_reports: true, priority_support: false }, currency: "GBP", amount_minor: 7900, billing_interval: "monthly", current_period_start: "2026-08-01T00:00:00Z", current_period_end: "2026-09-01T00:00:00Z", trial_ends_at: null, cancel_at_period_end: false, cancelled_at: null, ended_at: null, failure_code: null, failure_message: null, billing_contact: { email: "owner@forge.example", name: "Forge Fitness" }, created_at: "2026-05-01T00:00:00Z" },
  invoices: [
    { id: "saas-invoice-1", number: "IC-2026-0081", status: "paid", currency: "GBP", amount_due_minor: 7900, amount_paid_minor: 7900, amount_remaining_minor: 0, hosted_invoice_url: null, invoice_pdf_url: null, period_start: "2026-08-01T00:00:00Z", period_end: "2026-09-01T00:00:00Z", due_at: null, paid_at: "2026-08-01T00:02:00Z", created_at: "2026-08-01T00:00:00Z" },
    { id: "saas-invoice-2", number: "IC-2026-0074", status: "paid", currency: "GBP", amount_due_minor: 7900, amount_paid_minor: 7900, amount_remaining_minor: 0, hosted_invoice_url: null, invoice_pdf_url: null, period_start: "2026-07-01T00:00:00Z", period_end: "2026-08-01T00:00:00Z", due_at: null, paid_at: "2026-07-01T00:02:00Z", created_at: "2026-07-01T00:00:00Z" },
  ],
  baseCurrency: "GBP", actorRole: "super_admin", loading: false, error: null,
  onReload: () => undefined, onCheckout: async () => "", onPortal: async () => "", onCreatePlan: async () => undefined,
};
const demoEngagement: EngagementData = {
  attendance: [
    { id: "attendance-1", gym_id: "demo-gym", member_id: "demo-1", membership_id: "demo-membership-1", branch_id: "demo-branch-1", member: { id: "demo-1", member_number: "MBR-1042", name: "Amelia Hart" }, branch: { id: "demo-branch-1", name: "Manchester Central" }, method: "qr", status: "checked_in", checked_in_at: "2026-08-07T13:42:00Z", checked_out_at: null },
    { id: "attendance-2", gym_id: "demo-gym", member_id: "demo-2", membership_id: "demo-membership-2", branch_id: "demo-branch-1", member: { id: "demo-2", member_number: "MBR-1187", name: "Hassan Malik" }, branch: { id: "demo-branch-1", name: "Manchester Central" }, method: "member_code", status: "checked_out", checked_in_at: "2026-08-07T10:08:00Z", checked_out_at: "2026-08-07T11:34:00Z" },
  ],
  sessions: [
    { id: "class-1", gym_id: "demo-gym", branch_id: "demo-branch-1", trainer_staff_profile_id: "demo-staff-2", branch: { id: "demo-branch-1", name: "Manchester Central" }, trainer: { id: "demo-staff-2", name: "Daniel Reed" }, title: "Strength & conditioning", description: "Progressive full-body coaching for intermediate members.", starts_at: "2026-08-08T17:30:00Z", ends_at: "2026-08-08T18:30:00Z", capacity: 16, booked_count: 16, waitlist_count: 2, attended_count: 0, waitlist_enabled: true, booking_opens_at: null, booking_closes_at: null, status: "scheduled", cancellation_reason: null, created_at: "2026-08-01T10:00:00Z" },
    { id: "class-2", gym_id: "demo-gym", branch_id: "demo-branch-1", trainer_staff_profile_id: "demo-staff-2", branch: { id: "demo-branch-1", name: "Manchester Central" }, trainer: { id: "demo-staff-2", name: "Daniel Reed" }, title: "Mobility reset", description: "Guided recovery, flexibility and movement quality.", starts_at: "2026-08-09T09:00:00Z", ends_at: "2026-08-09T09:45:00Z", capacity: 14, booked_count: 8, waitlist_count: 0, attended_count: 0, waitlist_enabled: true, booking_opens_at: null, booking_closes_at: null, status: "scheduled", cancellation_reason: null, created_at: "2026-08-01T10:00:00Z" },
  ],
  bookings: [
    { id: "booking-1", gym_id: "demo-gym", class_session_id: "class-1", member_id: "demo-1", membership_id: "demo-membership-1", member: { id: "demo-1", member_number: "MBR-1042", name: "Amelia Hart" }, status: "booked", waitlist_sequence: null, booked_at: "2026-08-05T10:00:00Z", promoted_at: null, cancelled_at: null, checked_in_at: null, cancellation_reason: null },
    { id: "booking-2", gym_id: "demo-gym", class_session_id: "class-1", member_id: "demo-2", membership_id: "demo-membership-2", member: { id: "demo-2", member_number: "MBR-1187", name: "Hassan Malik" }, status: "waitlisted", waitlist_sequence: 7, booked_at: "2026-08-06T09:00:00Z", promoted_at: null, cancelled_at: null, checked_in_at: null, cancellation_reason: null },
  ],
  members: [{ id: "demo-1", name: "Amelia Hart", number: "MBR-1042" }, { id: "demo-2", name: "Hassan Malik", number: "MBR-1187" }, { id: "demo-3", name: "Omar Al-Farsi", number: "MBR-1216" }],
  branches: [{ id: "demo-branch-1", name: "Manchester Central" }], trainers: [{ id: "demo-staff-2", name: "Daniel Reed" }],
  actorRole: "gym_owner", loading: false, error: null, onReload: () => undefined,
  onCheckIn: async () => undefined, onCheckOut: async () => undefined, onCreateSession: async () => undefined,
  onBook: async (sessionId, memberId) => ({ id: "demo-booking-new", gym_id: "demo-gym", class_session_id: sessionId, member_id: memberId ?? "demo-1", membership_id: "demo-membership-1", status: sessionId === "class-1" ? "waitlisted" : "booked", waitlist_sequence: sessionId === "class-1" ? 9 : null, booked_at: new Date().toISOString(), promoted_at: null, cancelled_at: null, checked_in_at: null, cancellation_reason: null }),
  onCancel: async () => undefined, onAttend: async () => undefined,
  onIssueCredential: async () => "icqr_demo_7e11966a00e524d7921fe9c4a6572cd02d1ddf6ae72344967a0a522c4a72a103",
};
const demoCoaching: CoachingData = {
  assignments: [{ id: "assign-1", gym_id: "demo-gym", trainer_staff_profile_id: "demo-staff-2", member_id: "demo-1", trainer: { id: "demo-staff-2", name: "Daniel Reed" }, member: { id: "demo-1", member_number: "MBR-1042", name: "Amelia Hart" }, status: "active", starts_on: "2026-08-01", ends_on: null, notes: "Strength coaching", created_at: "2026-08-01T09:00:00Z" }],
  plans: [{ id: "workout-plan-1", gym_id: "demo-gym", member_id: "demo-1", trainer_staff_profile_id: "demo-staff-2", member: { id: "demo-1", member_number: "MBR-1042", name: "Amelia Hart" }, trainer: { id: "demo-staff-2", name: "Daniel Reed" }, title: "12-week strength foundation", goal: "Build confident compound movement and consistent weekly training.", notes: null, starts_on: "2026-08-01", ends_on: "2026-10-24", status: "active", exercises: [{ id: "exercise-1", gym_id: "demo-gym", workout_plan_id: "workout-plan-1", name: "Back squat", instructions: "Controlled three-second descent with a stable brace.", day_number: 1, sort_order: 1, target_sets: 4, target_reps_min: 6, target_reps_max: 8, target_load_grams: 55000, target_duration_seconds: null, rest_seconds: 120 }, { id: "exercise-2", gym_id: "demo-gym", workout_plan_id: "workout-plan-1", name: "Romanian deadlift", instructions: "Maintain a neutral spine and controlled hip hinge.", day_number: 1, sort_order: 2, target_sets: 3, target_reps_min: 8, target_reps_max: 10, target_load_grams: 45000, target_duration_seconds: null, rest_seconds: 90 }], created_at: "2026-08-01T10:00:00Z" }],
  sessions: [{ id: "workout-session-1", gym_id: "demo-gym", workout_plan_id: "workout-plan-1", member_id: "demo-1", plan: { id: "workout-plan-1", title: "12-week strength foundation" }, member: { id: "demo-1", member_number: "MBR-1042", name: "Amelia Hart" }, performed_at: "2026-08-06T17:30:00Z", duration_seconds: 3120, notes: "Strong technique throughout.", sets: [{ id: "set-1", gym_id: "demo-gym", workout_plan_exercise_id: "exercise-1", exercise_name: "Back squat", set_number: 1, reps: 8, load_grams: 52500, duration_seconds: null, distance_metres: null, rpe: 7 }], created_at: "2026-08-06T18:22:00Z" }],
  measurements: [
    { id: "measure-4", gym_id: "demo-gym", member_id: "demo-1", member: { id: "demo-1", member_number: "MBR-1042", name: "Amelia Hart" }, metric: "body_weight", value_milli: 71400, unit: "kg", measured_at: "2026-08-07T08:00:00Z", note: null, created_at: "2026-08-07T08:00:00Z" },
    { id: "measure-3", gym_id: "demo-gym", member_id: "demo-1", member: { id: "demo-1", member_number: "MBR-1042", name: "Amelia Hart" }, metric: "body_weight", value_milli: 71900, unit: "kg", measured_at: "2026-07-31T08:00:00Z", note: null, created_at: "2026-07-31T08:00:00Z" },
    { id: "measure-2", gym_id: "demo-gym", member_id: "demo-1", member: { id: "demo-1", member_number: "MBR-1042", name: "Amelia Hart" }, metric: "body_weight", value_milli: 72400, unit: "kg", measured_at: "2026-07-24T08:00:00Z", note: null, created_at: "2026-07-24T08:00:00Z" },
    { id: "measure-1", gym_id: "demo-gym", member_id: "demo-1", member: { id: "demo-1", member_number: "MBR-1042", name: "Amelia Hart" }, metric: "body_weight", value_milli: 73100, unit: "kg", measured_at: "2026-07-17T08:00:00Z", note: null, created_at: "2026-07-17T08:00:00Z" },
  ],
  preference: { id: "preference-1", gym_id: "demo-gym", member_id: "demo-1", email_enabled: true, sms_enabled: true, push_enabled: false, class_reminders_enabled: true, workout_reminders_enabled: true, payment_reminders_enabled: true, marketing_enabled: false, quiet_hours_start: "22:00", quiet_hours_end: "07:00", timezone: "Europe/London" },
  deliveries: [{ id: "delivery-1", gym_id: "demo-gym", member_id: "demo-1", channel: "email", template_key: "workout_plan_assigned", status: "sent", attempts: 1, scheduled_at: "2026-08-01T10:01:00Z", sent_at: "2026-08-01T10:01:02Z", failure_code: null, created_at: "2026-08-01T10:01:00Z" }, { id: "delivery-2", gym_id: "demo-gym", member_id: "demo-1", channel: "sms", template_key: "class_reminder", status: "queued", attempts: 0, scheduled_at: "2026-08-08T15:30:00Z", sent_at: null, failure_code: null, created_at: "2026-08-07T10:00:00Z" }],
  members: [{ id: "demo-1", name: "Amelia Hart", number: "MBR-1042" }, { id: "demo-2", name: "Hassan Malik", number: "MBR-1187" }, { id: "demo-3", name: "Omar Al-Farsi", number: "MBR-1216" }],
  trainers: [{ id: "demo-staff-2", name: "Daniel Reed" }], actorRole: "gym_owner", loading: false, error: null,
  onReload: () => undefined, onAssign: async () => undefined, onEndAssignment: async () => undefined, onCreatePlan: async () => undefined,
  onLogSession: async () => undefined, onRecordProgress: async () => undefined, onUpdatePreference: async () => undefined,
};

const demoMemberPortal: MemberPortalData = {
  gym: { id: "demo-gym", name: "Forge Fitness", base_currency: "GBP", timezone: "Europe/London" },
  profile: { member_number: "MBR-1042", first_name: "Amelia", last_name: "Hart", email: "amelia@example.com", phone: "+44 7700 900142", date_of_birth: "1996-04-18", status: "active", joined_at: "2026-08-04" },
  membership: { id: "demo-membership-1", gym_id: "demo-gym", member_id: "demo-1", plan_id: "demo-plan-1", branch_id: "demo-branch-1", status: "active", starts_at: "2026-08-01", ends_at: null, next_billing_at: "2026-09-01", price_amount_minor: 8900, currency: "GBP", joining_fee_minor: 0, billing_interval: "monthly", interval_count: 1, auto_renew: true, plan: { id: "demo-plan-1", name: "Unlimited", code: "UNLIMITED" }, branch: { id: "demo-branch-1", name: "Manchester Central" }, created_at: "2026-08-01T09:00:00Z" },
  invoices: [],
  payments: [],
  attendance: demoEngagement.attendance.slice(0, 1),
  classes: demoEngagement.sessions,
  bookings: demoEngagement.bookings.filter((booking) => booking.member_id === "demo-1"),
  workoutPlans: demoCoaching.plans,
  workoutSessions: demoCoaching.sessions,
  measurements: demoCoaching.measurements,
  preference: demoCoaching.preference,
  credential: { credential_hint: "6A10", status: "active", expires_at: null, last_used_at: null, created_at: "2026-08-07T12:00:00Z" },
  loading: false,
  error: null,
};

function demoCredential(): MemberSelfCredentialRecord {
  const bytes = new Uint8Array(32);
  globalThis.crypto?.getRandomValues(bytes);
  const value = Array.from(bytes, (byte) => byte.toString(16).padStart(2, "0")).join("");
  return { credential_hint: value.slice(-4).toUpperCase(), status: "active", expires_at: null, last_used_at: null, created_at: new Date().toISOString(), credential: `icqr_demo_${value}` };
}

function isoDate(date: Date): string { return date.toISOString().slice(0, 10); }

function initialReportRange(): { from: string; to: string } {
  const to = new Date();
  const from = new Date(to);
  from.setUTCDate(from.getUTCDate() - 29);
  return { from: isoDate(from), to: isoDate(to) };
}

function buildDemoReport(from: string, to: string, currency: GymSummary["base_currency"]): ReportOverviewRecord {
  const start = new Date(`${from}T00:00:00Z`);
  const end = new Date(`${to}T00:00:00Z`);
  const requestedDays = Number.isFinite(start.getTime()) && Number.isFinite(end.getTime())
    ? Math.floor((end.getTime() - start.getTime()) / 86400000) + 1
    : 30;
  const days = Math.min(366, Math.max(1, requestedDays));
  const currencyFactor = { GBP: 1, USD: 1.28, PKR: 356, AED: 4.7, SAR: 4.8 }[currency];
  const daily = Array.from({ length: days }, (_, index) => {
    const date = new Date(start);
    date.setUTCDate(date.getUTCDate() + index);
    const weekend = [0, 6].includes(date.getUTCDay());
    const gross = Math.round((weekend ? 25100 : 38200 + ((index * 4700) % 18300)) * currencyFactor);
    const refunded = index % 11 === 0 ? Math.round(1800 * currencyFactor) : 0;
    return { date: isoDate(date), new_members: 4 + (index % 7), attendance_visits: (weekend ? 94 : 156) + ((index * 13) % 42), gross_revenue_minor: gross, refunded_minor: refunded, net_revenue_minor: gross - refunded };
  });
  const net = daily.reduce((sum, row) => sum + row.net_revenue_minor, 0);
  const visits = daily.reduce((sum, row) => sum + row.attendance_visits, 0);
  const newMembers = daily.reduce((sum, row) => sum + row.new_members, 0);

  return {
    period: { from, to: isoDate(new Date(start.getTime() + (days - 1) * 86400000)), days, timezone: "Europe/London", currency },
    summary: { active_members: 1842, new_members: newMembers, new_members_change_bps: 1240, net_revenue_minor: net, net_revenue_change_bps: 1830, outstanding_minor: Math.round(184900 * currencyFactor), attendance_visits: visits, attendance_change_bps: 860, class_utilization_bps: 7840, class_utilization_change_bps: 430, membership_cancellations: Math.max(3, Math.round(days / 3)) },
    daily,
    member_status: [{ status: "active", count: 1842 }, { status: "lead", count: 238 }, { status: "paused", count: 64 }, { status: "cancelled", count: 37 }],
    payment_methods: [{ method: "online_card", count: 714, net_minor: Math.round(net * .61) }, { method: "card", count: 286, net_minor: Math.round(net * .24) }, { method: "cash", count: 173, net_minor: Math.round(net * .11) }, { method: "bank_transfer", count: 41, net_minor: Math.round(net * .04) }],
    class_performance: { sessions: Math.max(12, days * 4), capacity: Math.max(240, days * 62), booked: Math.max(190, days * 53), attended: Math.max(160, Math.round(days * 48.6)), waitlisted: Math.max(5, Math.round(days * .8)), utilization_bps: 7840 },
    meta: { generated_at: "2026-08-07T14:30:00Z", cache_ttl_seconds: 60, report_version: "v1" },
  };
}

function roleLabel(role: IronCoreRole): string {
  return role.split("_").map((word) => word[0].toUpperCase() + word.slice(1)).join(" ");
}

function apiMessage(error: unknown, fallback: string): string {
  if (error instanceof IronCoreApiError) return error.message;
  if (error instanceof TypeError) return "We couldn’t reach the IronCore API. Check that the backend is running.";
  return error instanceof Error ? error.message : fallback;
}

function dashboardMember(member: MemberRecord, gymName: string): DashboardMember {
  return {
    id: member.id,
    name: `${member.first_name} ${member.last_name}`,
    gym: gymName,
    membership: member.member_number,
    joined: member.joined_at
      ? new Intl.DateTimeFormat("en-GB", { day: "2-digit", month: "short", year: "numeric" }).format(new Date(member.joined_at))
      : "Not set",
    status: member.status[0].toUpperCase() + member.status.slice(1),
    email: member.email,
    accountLinked: member.user_id !== null,
  };
}

function pendingMemberActivation(): MemberActivationSecret | null {
  if (typeof window === "undefined" || !window.location.hash.startsWith("#activate_")) return null;
  const params = new URLSearchParams(window.location.hash.slice(1));
  const gymId = params.get("activate_gym"); const token = params.get("activate_token");
  return gymId && token ? { gymId, token } : null;
}

function pendingInvitation(): { gymId: string; token: string } | null {
  if (typeof window === "undefined" || !window.location.hash.startsWith("#invite_")) return null;
  const params = new URLSearchParams(window.location.hash.slice(1));
  const gymId = params.get("invite_gym"); const token = params.get("invite_token");
  return gymId && token ? { gymId, token } : null;
}

function Brand() {
  return <div className="auth-brand"><span><i /><strong>IC</strong></span><b>IRONCORE</b></div>;
}

function LoginScreen({ onLogin, busy, error }: { onLogin: (email: string, password: string) => Promise<void>; busy: boolean; error: string | null }) {
  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const data = new FormData(event.currentTarget);
    await onLogin(String(data.get("email")), String(data.get("password")));
  }

  return <main className="auth-page">
    <section className="auth-story">
      <Brand />
      <div><p className="eyebrow">Secure gym operations</p><h1>One core for your entire fitness business.</h1><p>Members, teams and revenue—separated by gym and protected at every layer.</p></div>
      <ul><li><ShieldCheck size={17} /> Tenant-isolated workspaces</li><li><LockKeyhole size={17} /> Encrypted session authentication</li><li><Building2 size={17} /> Built for multi-location scale</li></ul>
    </section>
    <section className="auth-form-side">
      <form className="auth-card" onSubmit={submit}>
        <div className="auth-mobile-brand"><Brand /></div>
        <p className="eyebrow">Welcome back</p><h2>Sign in to IronCore</h2><p>Use the account assigned to your platform or gym.</p>
        {error && <div className="form-error" role="alert">{error}</div>}
        <label>Email address<input name="email" type="email" autoComplete="email" required placeholder="you@yourgym.com" autoFocus /></label>
        <label>Password<input name="password" type="password" autoComplete="current-password" required minLength={8} placeholder="Enter your password" /></label>
        <button className="primary-button auth-submit" disabled={busy} type="submit">{busy ? <><LoaderCircle className="spin" size={17} /> Signing in</> : <>Sign in securely <ArrowRight size={17} /></>}</button>
        <small>IronCore uses an encrypted, HttpOnly session cookie. Your credentials are never stored in this browser.</small>
      </form>
    </section>
  </main>;
}

function TenantPicker({ user, gyms, notice, onSelect, onLogout }: { user: AuthenticatedUser; gyms: GymAccess[]; notice: string | null; onSelect: (gym: GymAccess) => void; onLogout: () => void }) {
  return <main className="tenant-page"><header><Brand /><button className="secondary-button" onClick={onLogout}>Sign out</button></header><section className="tenant-card"><p className="eyebrow">Authorised workspaces</p><h1>Choose a gym</h1><p>Hello {user.name}. Select the tenant you want to work in. IronCore applies this context to every operational request.</p>{notice && <div className="form-error" role="alert">{notice}</div>}<div className="tenant-list">{gyms.map((gym) => <button key={gym.id} onClick={() => onSelect(gym)}><span className="tenant-avatar">{gym.name.split(" ").map((word) => word[0]).join("").slice(0, 2)}</span><span><strong>{gym.name}</strong><small>{roleLabel(gym.role)} · {gym.status}</small></span><ArrowRight size={18} /></button>)}</div>{gyms.length === 0 && <div className="tenant-empty"><Building2 size={24} /><strong>No active gym access</strong><span>Ask a platform administrator or gym owner to assign your account.</span></div>}</section></main>;
}

export function IronCoreApp() {
  const api = useMemo(() => demoMode ? null : new IronCoreApi(apiOrigin), []);
  const defaultReportRange = useMemo(() => initialReportRange(), []);
  const [demoPortal, setDemoPortal] = useState<"platform" | "gym" | "member">("platform");
  const [phase, setPhase] = useState<"booting" | "anonymous" | "authenticated">(demoMode ? "authenticated" : "booting");
  const [user, setUser] = useState<AuthenticatedUser | null>(null);
  const [gyms, setGyms] = useState<GymAccess[]>([]);
  const [selectedGym, setSelectedGym] = useState<GymAccess | null>(null);
  const [authBusy, setAuthBusy] = useState(false);
  const [authError, setAuthError] = useState<string | null>(null);
  const [memberSearch, setMemberSearch] = useState("");
  const [memberRefresh, setMemberRefresh] = useState(0);
  const [members, setMembers] = useState<MemberState>({ rows: [], total: 0, loading: false, error: null });
  const [operations, setOperations] = useState<OperationsState>({ branches: [], plans: [], memberships: [], loading: false, error: null });
  const [operationsRefresh, setOperationsRefresh] = useState(0);
  const [staff, setStaff] = useState<StaffState>({ rows: [], invitations: [], loading: false, error: null });
  const [staffRefresh, setStaffRefresh] = useState(0);
  const [finance, setFinance] = useState<FinanceState>({ payments: [], invoices: [], summary: null, gateway: null, loading: false, error: null });
  const [financeRefresh, setFinanceRefresh] = useState(0);
  const [saas, setSaas] = useState<SaasState>({ plans: [], subscription: null, invoices: [], loading: false, error: null });
  const [saasRefresh, setSaasRefresh] = useState(0);
  const [engagement, setEngagement] = useState<EngagementState>({ attendance: [], sessions: [], bookings: [], loading: false, error: null });
  const [engagementRefresh, setEngagementRefresh] = useState(0);
  const [coaching, setCoaching] = useState<CoachingState>({ assignments: [], plans: [], sessions: [], measurements: [], preference: null, deliveries: [], loading: false, error: null });
  const [coachingRefresh, setCoachingRefresh] = useState(0);
  const [memberSelf, setMemberSelf] = useState<MemberSelfState>({ profile: null, membership: null, invoices: [], payments: [], attendance: [], credential: null, loading: false, error: null });
  const [memberSelfRefresh, setMemberSelfRefresh] = useState(0);
  const [reports, setReports] = useState<ReportState>(() => ({
    report: demoMode ? buildDemoReport(defaultReportRange.from, defaultReportRange.to, "GBP") : null,
    ...defaultReportRange,
    currency: "GBP",
    loading: false,
    error: null,
  }));
  const [reportRefresh, setReportRefresh] = useState(0);
  const [inviteNotice, setInviteNotice] = useState<string | null>(null);
  const [memberActivation, setMemberActivation] = useState<MemberActivationSecret | null>(null);
  const [activationChecked, setActivationChecked] = useState(false);
  const requestSequence = useRef(0);
  const operationsSequence = useRef(0);
  const staffSequence = useRef(0);
  const financeSequence = useRef(0);
  const saasSequence = useRef(0);
  const engagementSequence = useRef(0);
  const coachingSequence = useRef(0);
  const reportSequence = useRef(0);
  const memberSelfSequence = useRef(0);

  useEffect(() => {
    let timer: number | undefined;
    const receiveActivation = () => {
      const invitation = pendingMemberActivation();
      if (invitation) {
        // Copy the one-time value into volatile component state, then remove it
        // before preview requests, navigation, analytics or referrers can use it.
        window.history.replaceState(null, "", `${window.location.pathname}${window.location.search}`);
      }
      if (timer) window.clearTimeout(timer);
      timer = window.setTimeout(() => {
        setMemberActivation(invitation);
        setActivationChecked(true);
      }, 0);
    };
    receiveActivation();
    window.addEventListener("hashchange", receiveActivation);
    return () => {
      window.removeEventListener("hashchange", receiveActivation);
      if (timer) window.clearTimeout(timer);
    };
  }, []);

  const loadSession = useCallback(async () => {
    if (!api) return;
    try {
      let identity = await api.me();
      const invitation = pendingInvitation();
      if (invitation) {
        try {
          await api.acceptStaffInvitation(invitation.gymId, invitation.token);
          // Refresh identity because acceptance creates the tenant membership.
          identity = await api.me();
          setInviteNotice("Invitation accepted. Your gym access is now active.");
        } catch (error) {
          setInviteNotice(apiMessage(error, "The invitation is invalid or expired."));
        } finally {
          // Fragment tokens are never persisted or sent as referrers.
          window.history.replaceState(null, "", `${window.location.pathname}${window.location.search}`);
        }
      }
      const available = await api.gyms();
      const access = available.map((gym): GymAccess => ({
        ...gym,
        // Super admins still select one explicit tenant; selection never
        // bypasses the Laravel membership/policy/RLS enforcement chain.
        role: identity.platform_role === "super_admin"
          ? "super_admin"
          : identity.gyms.find((assigned) => assigned.id === gym.id)?.role ?? "member",
      }));
      setUser(identity);
      setGyms(access);
      setPhase("authenticated");
      if (identity.platform_role !== "super_admin" && access.length === 1) setSelectedGym(access[0]);
    } catch (error) {
      if (error instanceof IronCoreApiError && error.status === 401) {
        setPhase("anonymous");
        return;
      }
      setAuthError(apiMessage(error, "The IronCore API is unavailable."));
      setPhase("anonymous");
    }
  }, [api]);

  useEffect(() => {
    if (!activationChecked || memberActivation) return;
    const timer = window.setTimeout(() => void loadSession(), 0);
    return () => window.clearTimeout(timer);
  }, [activationChecked, loadSession, memberActivation]);

  const previewMemberActivation = useCallback(async (gymId: string, token: string): Promise<MemberAccountActivationPreview> => {
    if (!api) return { gym_name: "Forge Fitness", member_first_name: "Amelia", masked_email: "a*****@example.com", existing_account: false };
    return api.previewMemberAccountActivation(gymId, token);
  }, [api]);

  const acceptMemberActivation = useCallback(async (gymId: string, token: string, password?: string): Promise<void> => {
    if (!api) {
      setMemberActivation(null);
      setDemoPortal("member");
      return;
    }
    await api.acceptMemberAccountActivation(gymId, token, password);
    setMemberActivation(null);
  }, [api]);

  useEffect(() => {
    if (!api || !selectedGym) return;
    if (!["super_admin", "gym_owner", "gym_manager", "receptionist"].includes(selectedGym.role)) return;
    const sequence = ++requestSequence.current;
    const timer = window.setTimeout(() => {
      setMembers((current) => ({ ...current, loading: true, error: null }));
      void api.members(selectedGym.id, memberSearch).then((result) => {
        if (sequence !== requestSequence.current) return;
        setMembers({ rows: result.data.map((member) => dashboardMember(member, selectedGym.name)), total: result.meta.total, loading: false, error: null });
      }).catch((error) => {
        if (sequence !== requestSequence.current) return;
        if (error instanceof IronCoreApiError && error.status === 401) {
          setUser(null); setSelectedGym(null); setPhase("anonymous");
        }
        setMembers((current) => ({ ...current, loading: false, error: error instanceof Error ? error.message : "Members could not be loaded." }));
      });
    }, 250);
    return () => window.clearTimeout(timer);
  }, [api, memberRefresh, memberSearch, selectedGym]);

  useEffect(() => {
    if (!api || !selectedGym) return;
    const sequence = ++operationsSequence.current;
    const timer = window.setTimeout(() => {
      setOperations((current) => ({ ...current, loading: true, error: null }));
      // Bounded collections load concurrently; every request still carries the
      // verified route/header gym pair and remains protected by Laravel + RLS.
      const canReadMemberships = ["super_admin", "gym_owner", "gym_manager", "receptionist"].includes(selectedGym.role);
      const membershipsRequest = canReadMemberships ? api.memberships(selectedGym.id) : Promise.resolve({ data: [], meta: { current_page: 1, last_page: 1, per_page: 100, total: 0 } });
      void Promise.all([api.branches(selectedGym.id), api.membershipPlans(selectedGym.id), membershipsRequest]).then(([branches, plans, memberships]) => {
        if (sequence !== operationsSequence.current) return;
        setOperations({ branches: branches.data, plans: plans.data, memberships: memberships.data, loading: false, error: null });
      }).catch((error) => {
        if (sequence !== operationsSequence.current) return;
        setOperations((current) => ({ ...current, loading: false, error: apiMessage(error, "Tenant operations could not be loaded.") }));
      });
    }, 0);
    return () => window.clearTimeout(timer);
  }, [api, operationsRefresh, selectedGym]);

  useEffect(() => {
    if (!api || !selectedGym || !["super_admin", "gym_owner", "gym_manager"].includes(selectedGym.role)) return;
    const sequence = ++staffSequence.current;
    const timer = window.setTimeout(() => {
      setStaff((current) => ({ ...current, loading: true, error: null }));
      // Staff and invitations are bounded tenant collections and share the
      // selected route/header gym; neither response can cross tenant context.
      void Promise.all([api.staff(selectedGym.id), api.staffInvitations(selectedGym.id)]).then(([rows, invitations]) => {
        if (sequence !== staffSequence.current) return;
        setStaff({ rows: rows.data, invitations: invitations.data, loading: false, error: null });
      }).catch((error) => {
        if (sequence !== staffSequence.current) return;
        setStaff((current) => ({ ...current, loading: false, error: apiMessage(error, "Tenant team could not be loaded.") }));
      });
    }, 0);
    return () => window.clearTimeout(timer);
  }, [api, selectedGym, staffRefresh]);

  useEffect(() => {
    if (!api || !selectedGym || !["super_admin", "gym_owner", "gym_manager", "receptionist"].includes(selectedGym.role)) return;
    const sequence = ++financeSequence.current;
    const timer = window.setTimeout(() => {
      setFinance((current) => ({ ...current, loading: true, error: null }));
      const canReadGateway = ["super_admin", "gym_owner", "gym_manager"].includes(selectedGym.role);
      const gatewayRequest = canReadGateway ? api.paymentGateway(selectedGym.id) : Promise.resolve(null);
      // Finance collections and their summary share one selected tenant but use
      // independent server queries; stale responses cannot overwrite a gym switch.
      void Promise.all([
        api.payments(selectedGym.id),
        api.invoices(selectedGym.id),
        api.paymentSummary(selectedGym.id, selectedGym.base_currency),
        gatewayRequest,
      ]).then(([payments, invoices, summary, gateway]) => {
        if (sequence !== financeSequence.current) return;
        setFinance({ payments: payments.data, invoices: invoices.data, summary, gateway, loading: false, error: null });
      }).catch((error) => {
        if (sequence !== financeSequence.current) return;
        setFinance((current) => ({ ...current, loading: false, error: apiMessage(error, "Tenant finance could not be loaded.") }));
      });
    }, 0);
    return () => window.clearTimeout(timer);
  }, [api, financeRefresh, selectedGym]);

  useEffect(() => {
    if (!api || !selectedGym || !["super_admin", "gym_owner", "gym_manager"].includes(selectedGym.role)) return;
    const sequence = ++saasSequence.current;
    const timer = window.setTimeout(() => {
      setSaas((current) => ({ ...current, loading: true, error: null }));
      // Plan catalogue and tenant billing records load together, but only the
      // latter are protected by the selected route/header tenant + forced RLS.
      void Promise.all([
        api.saasPlans(selectedGym.id),
        api.saasSubscription(selectedGym.id),
        api.saasBillingInvoices(selectedGym.id),
      ]).then(([plans, subscription, invoices]) => {
        if (sequence !== saasSequence.current) return;
        setSaas({ plans, subscription, invoices: invoices.data, loading: false, error: null });
      }).catch((error) => {
        if (sequence !== saasSequence.current) return;
        setSaas((current) => ({ ...current, loading: false, error: apiMessage(error, "SaaS billing could not be loaded.") }));
      });
    }, 0);
    return () => window.clearTimeout(timer);
  }, [api, saasRefresh, selectedGym]);

  useEffect(() => {
    if (!api || !selectedGym) return;
    const sequence = ++engagementSequence.current;
    const timer = window.setTimeout(() => {
      setEngagement((current) => ({ ...current, loading: true, error: null }));
      const attendanceRequest = selectedGym.role === "member" ? Promise.resolve([]) : api.attendance(selectedGym.id);
      // Presence, schedule and booking responses are independently tenant-bound;
      // the sequence guard prevents a previous gym replacing the active context.
      void Promise.all([attendanceRequest, api.classSessions(selectedGym.id), api.classBookings(selectedGym.id)]).then(([attendance, sessions, bookings]) => {
        if (sequence !== engagementSequence.current) return;
        setEngagement({ attendance, sessions: sessions.data, bookings: bookings.data, loading: false, error: null });
      }).catch((error) => {
        if (sequence !== engagementSequence.current) return;
        setEngagement((current) => ({ ...current, loading: false, error: apiMessage(error, "Attendance and classes could not be loaded.") }));
      });
    }, 0);
    return () => window.clearTimeout(timer);
  }, [api, engagementRefresh, selectedGym]);

  useEffect(() => {
    if (!api || !selectedGym || selectedGym.role === "receptionist") return;
    const sequence = ++coachingSequence.current;
    const timer = window.setTimeout(() => {
      setCoaching((current) => ({ ...current, loading: true, error: null }));
      const canReadNotifications = ["super_admin", "gym_owner", "gym_manager", "member"].includes(selectedGym.role);
      const preferenceRequest = selectedGym.role === "member" ? api.notificationPreference(selectedGym.id) : Promise.resolve(null);
      const deliveryRequest = canReadNotifications ? api.notificationDeliveries(selectedGym.id) : Promise.resolve([]);
      // Every coaching collection is independently tenant-bound. The sequence
      // guard prevents a prior gym response replacing the selected context.
      void Promise.all([
        api.trainerAssignments(selectedGym.id), api.workoutPlans(selectedGym.id),
        api.workoutSessions(selectedGym.id), api.progressMeasurements(selectedGym.id),
        preferenceRequest, deliveryRequest,
      ]).then(([assignments, plans, sessions, measurements, preference, deliveries]) => {
        if (sequence !== coachingSequence.current) return;
        setCoaching({ assignments, plans, sessions, measurements, preference, deliveries, loading: false, error: null });
      }).catch((error) => {
        if (sequence !== coachingSequence.current) return;
        setCoaching((current) => ({ ...current, loading: false, error: apiMessage(error, "Coaching and progress could not be loaded.") }));
      });
    }, 0);
    return () => window.clearTimeout(timer);
  }, [api, coachingRefresh, selectedGym]);

  useEffect(() => {
    if (!api || !selectedGym || selectedGym.role !== "member") return;
    const sequence = ++memberSelfSequence.current;
    const timer = window.setTimeout(() => {
      setMemberSelf((current) => ({ ...current, loading: true, error: null }));
      // These endpoints resolve the authenticated user-to-member link on the
      // server. The browser never supplies a member identifier or tenant data.
      void Promise.all([
        api.memberSelfProfile(selectedGym.id),
        api.memberSelfMembership(selectedGym.id),
        api.memberSelfInvoices(selectedGym.id),
        api.memberSelfPayments(selectedGym.id),
        api.memberSelfAttendance(selectedGym.id),
        api.memberSelfCredential(selectedGym.id),
      ]).then(([profile, membership, invoices, payments, attendance, credential]) => {
        if (sequence !== memberSelfSequence.current) return;
        setMemberSelf({ profile, membership, invoices: invoices.data, payments: payments.data, attendance, credential, loading: false, error: null });
      }).catch((error) => {
        if (sequence !== memberSelfSequence.current) return;
        setMemberSelf((current) => ({ ...current, loading: false, error: apiMessage(error, "Your member space could not be loaded.") }));
      });
    }, 0);
    return () => window.clearTimeout(timer);
  }, [api, memberSelfRefresh, selectedGym]);

  useEffect(() => {
    if (!api || !selectedGym || !["super_admin", "gym_owner", "gym_manager"].includes(selectedGym.role)) return;
    const sequence = ++reportSequence.current;
    const timer = window.setTimeout(() => {
      setReports((current) => ({ ...current, loading: true, error: null }));
      // The response belongs to one verified route/header gym. A sequence guard
      // prevents a slower prior tenant or filter response replacing this state.
      void api.reportOverview(selectedGym.id, reports.from, reports.to, reports.currency).then((report) => {
        if (sequence !== reportSequence.current) return;
        setReports((current) => ({ ...current, report, loading: false, error: null }));
      }).catch((error) => {
        if (sequence !== reportSequence.current) return;
        setReports((current) => ({ ...current, report: null, loading: false, error: apiMessage(error, "The tenant report could not be loaded.") }));
      });
    }, 0);
    return () => window.clearTimeout(timer);
  }, [api, reportRefresh, reports.currency, reports.from, reports.to, selectedGym]);

  async function login(email: string, password: string) {
    if (!api) return;
    setAuthBusy(true); setAuthError(null);
    try { await api.login(email, password); await loadSession(); }
    catch (error) { setAuthError(apiMessage(error, "Sign-in failed.")); }
    finally { setAuthBusy(false); }
  }

  async function logout() {
    if (api) await api.logout().catch(() => undefined);
    reportSequence.current += 1;
    memberSelfSequence.current += 1;
    setUser(null); setGyms([]); setSelectedGym(null); setMembers({ rows: [], total: 0, loading: false, error: null }); setOperations({ branches: [], plans: [], memberships: [], loading: false, error: null }); setStaff({ rows: [], invitations: [], loading: false, error: null }); setFinance({ payments: [], invoices: [], summary: null, gateway: null, loading: false, error: null }); setSaas({ plans: [], subscription: null, invoices: [], loading: false, error: null }); setEngagement({ attendance: [], sessions: [], bookings: [], loading: false, error: null }); setCoaching({ assignments: [], plans: [], sessions: [], measurements: [], preference: null, deliveries: [], loading: false, error: null }); setMemberSelf({ profile: null, membership: null, invoices: [], payments: [], attendance: [], credential: null, loading: false, error: null }); setReports({ report: null, ...defaultReportRange, currency: "GBP", loading: false, error: null }); setPhase("anonymous");
  }

  function selectGym(gym: GymAccess | null) {
    // Clear prior tenant rows before switching context so stale UI data from a
    // previous gym is never rendered while new scoped requests are pending.
    setMembers({ rows: [], total: 0, loading: false, error: null });
    setOperations({ branches: [], plans: [], memberships: [], loading: false, error: null });
    setStaff({ rows: [], invitations: [], loading: false, error: null });
    setFinance({ payments: [], invoices: [], summary: null, gateway: null, loading: false, error: null });
    setSaas({ plans: [], subscription: null, invoices: [], loading: false, error: null });
    setEngagement({ attendance: [], sessions: [], bookings: [], loading: false, error: null });
    setCoaching({ assignments: [], plans: [], sessions: [], measurements: [], preference: null, deliveries: [], loading: false, error: null });
    memberSelfSequence.current += 1;
    setMemberSelf({ profile: null, membership: null, invoices: [], payments: [], attendance: [], credential: null, loading: false, error: null });
    reportSequence.current += 1;
    setReports((current) => ({ ...current, report: null, currency: gym?.base_currency ?? "GBP", loading: false, error: null }));
    setSelectedGym(gym);
  }

  async function createMember(input: NewDashboardMember) {
    if (!api || !selectedGym) throw new Error("Select a gym before creating a member.");
    await api.createMember(selectedGym.id, input);
    const refreshed = await api.members(selectedGym.id, memberSearch);
    setMembers({ rows: refreshed.data.map((member) => dashboardMember(member, selectedGym.name)), total: refreshed.meta.total, loading: false, error: null });
  }
  async function inviteMemberPortal(memberId: string): Promise<string> {
    if (!api || !selectedGym) throw new Error("Select a gym before creating a member invitation.");
    const created = await api.createMemberAccountInvitation(selectedGym.id, memberId);
    return `${window.location.origin}${window.location.pathname}#activate_gym=${encodeURIComponent(selectedGym.id)}&activate_token=${encodeURIComponent(created.activation_token)}`;
  }

  async function createBranch(input: NewOperationBranch) { if (!api || !selectedGym) throw new Error("Select a gym first."); await api.createBranch(selectedGym.id, input); setOperationsRefresh((v) => v + 1); }
  async function createPlan(input: NewOperationPlan) { if (!api || !selectedGym) throw new Error("Select a gym first."); await api.createMembershipPlan(selectedGym.id, input); setOperationsRefresh((v) => v + 1); }
  async function createMembership(input: NewOperationMembership) { if (!api || !selectedGym) throw new Error("Select a gym first."); await api.createMembership(selectedGym.id, input); setOperationsRefresh((v) => v + 1); }
  async function inviteStaff(input: NewStaffInvite): Promise<string> {
    if (!api || !selectedGym) throw new Error("Select a gym first.");
    const created = await api.createStaffInvitation(selectedGym.id, input);
    setStaffRefresh((value) => value + 1);
    // The secret remains in the URL fragment, which browsers do not send to
    // servers or referrers. Acceptance removes it immediately after use.
    return `${window.location.origin}${window.location.pathname}#invite_gym=${encodeURIComponent(selectedGym.id)}&invite_token=${encodeURIComponent(created.acceptance_token)}`;
  }
  async function updateStaff(staffId: string, input: StaffUpdate): Promise<void> {
    if (!api || !selectedGym) throw new Error("Select a gym first.");
    await api.updateStaff(selectedGym.id, staffId, input);
    setStaffRefresh((value) => value + 1);
  }

  async function createInvoice(input: NewFinanceInvoice): Promise<void> {
    if (!api || !selectedGym) throw new Error("Select a gym first.");
    await api.createInvoice(selectedGym.id, input);
    setFinanceRefresh((value) => value + 1);
  }

  async function createPayment(input: NewFinancePayment): Promise<string | null> {
    if (!api || !selectedGym) throw new Error("Select a gym first.");
    const result = await api.createPayment(selectedGym.id, input);
    setFinanceRefresh((value) => value + 1);
    return result.checkout_url;
  }

  async function refundPayment(paymentId: string, amountMinor: number, reason: string): Promise<void> {
    if (!api || !selectedGym) throw new Error("Select a gym first.");
    await api.createRefund(selectedGym.id, paymentId, amountMinor, reason);
    setFinanceRefresh((value) => value + 1);
  }

  async function connectStripe(): Promise<string> {
    if (!api || !selectedGym) throw new Error("Select a gym first.");
    const result = await api.startStripeOnboarding(selectedGym.id);
    setFinanceRefresh((value) => value + 1);
    return result.onboarding_url;
  }

  async function refreshStripe(): Promise<void> {
    if (!api || !selectedGym) throw new Error("Select a gym first.");
    await api.refreshStripeGateway(selectedGym.id);
    setFinanceRefresh((value) => value + 1);
  }

  async function startSaasCheckout(priceId: string, idempotencyKey: string): Promise<string> {
    if (!api || !selectedGym) throw new Error("Select a gym first.");
    const result = await api.startSaasCheckout(selectedGym.id, priceId, idempotencyKey);
    setSaasRefresh((value) => value + 1);
    return result.checkout_url;
  }

  async function openSaasPortal(): Promise<string> {
    if (!api || !selectedGym) throw new Error("Select a gym first.");
    const result = await api.openSaasPortal(selectedGym.id);
    return result.portal_url;
  }

  async function createSaasPlan(input: NewSaasPlan): Promise<void> {
    if (!api || user?.platform_role !== "super_admin") throw new Error("Only a super administrator can publish platform plans.");
    await api.createSaasPlan(input);
    setSaasRefresh((value) => value + 1);
  }

  async function checkInMember(input: { branchId: string; accessValue: string }): Promise<void> {
    if (!api || !selectedGym) throw new Error("Select a gym first.");
    await api.checkIn(selectedGym.id, input.accessValue.startsWith("icqr_")
      ? { branch_id: input.branchId, credential: input.accessValue }
      : { branch_id: input.branchId, member_number: input.accessValue });
    setEngagementRefresh((value) => value + 1);
  }
  async function checkOutMember(attendanceId: string): Promise<void> { if (!api || !selectedGym) throw new Error("Select a gym first."); await api.checkOut(selectedGym.id, attendanceId); setEngagementRefresh((value) => value + 1); }
  async function createClassSession(input: NewClassSession): Promise<void> { if (!api || !selectedGym) throw new Error("Select a gym first."); await api.createClassSession(selectedGym.id, input); setEngagementRefresh((value) => value + 1); }
  async function bookClass(sessionId: string, memberId?: string): Promise<ClassBookingRecord> { if (!api || !selectedGym) throw new Error("Select a gym first."); const booking = await api.bookClass(selectedGym.id, sessionId, memberId); setEngagementRefresh((value) => value + 1); return booking; }
  async function cancelBooking(bookingId: string, reason: string): Promise<void> { if (!api || !selectedGym) throw new Error("Select a gym first."); await api.cancelClassBooking(selectedGym.id, bookingId, reason); setEngagementRefresh((value) => value + 1); }
  async function attendBooking(bookingId: string): Promise<void> { if (!api || !selectedGym) throw new Error("Select a gym first."); await api.attendClassBooking(selectedGym.id, bookingId); setEngagementRefresh((value) => value + 1); }
  async function issueCredential(memberId: string): Promise<string> { if (!api || !selectedGym) throw new Error("Select a gym first."); const result = await api.issueMemberAccessCredential(selectedGym.id, memberId); if (!result.credential) throw new Error("The one-time QR credential was not returned."); return result.credential; }

  async function updateMemberSelfProfile(input: UpdateMemberSelf): Promise<void> {
    if (!api || !selectedGym || selectedGym.role !== "member") throw new Error("Select your member workspace first.");
    const profile = await api.updateMemberSelfProfile(selectedGym.id, input);
    setMemberSelf((current) => ({ ...current, profile }));
  }

  async function rotateMemberSelfCredential(): Promise<MemberSelfCredentialRecord> {
    if (!api || !selectedGym || selectedGym.role !== "member") throw new Error("Select your member workspace first.");
    const result = await api.rotateMemberSelfCredential(selectedGym.id);
    const safeMetadata: MemberSelfCredentialRecord = { credential_hint: result.credential_hint, status: result.status, expires_at: result.expires_at, last_used_at: result.last_used_at, created_at: result.created_at };
    setMemberSelf((current) => ({ ...current, credential: safeMetadata }));
    return result;
  }

  async function assignTrainer(input: NewTrainerAssignment): Promise<void> {
    if (!api || !selectedGym) throw new Error("Select a gym first.");
    await api.createTrainerAssignment(selectedGym.id, input);
    setCoachingRefresh((value) => value + 1);
  }

  async function endTrainerAssignment(assignmentId: string, reason: string): Promise<void> {
    if (!api || !selectedGym) throw new Error("Select a gym first.");
    await api.endTrainerAssignment(selectedGym.id, assignmentId, reason);
    setCoachingRefresh((value) => value + 1);
  }

  async function createWorkoutPlan(input: NewWorkoutPlan): Promise<void> {
    if (!api || !selectedGym) throw new Error("Select a gym first.");
    await api.createWorkoutPlan(selectedGym.id, input);
    setCoachingRefresh((value) => value + 1);
  }

  async function logWorkoutSession(input: NewWorkoutSession): Promise<void> {
    if (!api || !selectedGym) throw new Error("Select a gym first.");
    await api.logWorkoutSession(selectedGym.id, input);
    setCoachingRefresh((value) => value + 1);
  }

  async function recordProgress(input: NewProgressMeasurement): Promise<void> {
    if (!api || !selectedGym) throw new Error("Select a gym first.");
    await api.recordProgress(selectedGym.id, input);
    setCoachingRefresh((value) => value + 1);
  }

  async function updateNotificationPreference(input: UpdateNotificationPreference): Promise<void> {
    if (!api || !selectedGym) throw new Error("Select a gym first.");
    await api.updateNotificationPreference(selectedGym.id, input);
    setCoachingRefresh((value) => value + 1);
  }

  function applyReportFilters(from: string, to: string, currency: GymSummary["base_currency"]): void {
    // Filter changes replace, rather than merge, report payloads so no stale
    // currency or tenant aggregate remains visible during the next request.
    setReports({
      report: demoMode ? buildDemoReport(from, to, currency) : null,
      from,
      to,
      currency,
      loading: !demoMode,
      error: null,
    });
  }

  function reloadReport(): void {
    if (demoMode) {
      setReports((current) => ({ ...current, report: buildDemoReport(current.from, current.to, current.currency), error: null }));
      return;
    }
    setReportRefresh((value) => value + 1);
  }

  const reportData: ReportData = { ...reports, onApply: applyReportFilters, onReload: reloadReport };

  if (memberActivation) return <MemberAccountActivation invitation={memberActivation} onPreview={previewMemberActivation} onAccept={acceptMemberActivation} onCancel={() => { setMemberActivation(null); if (demoMode) setDemoPortal("platform"); }} />;
  if (demoMode) {
    const sharedPreview = { liveOperations: demoOperations, liveStaff: demoStaff, liveFinance: demoFinance, liveEngagement: demoEngagement, liveCoaching: demoCoaching, liveReports: reportData };
    if (demoPortal === "member") return <MemberPortal data={demoMemberPortal} actions={{
      onReload: () => undefined,
      onLogout: () => setDemoPortal("platform"),
      onPortalSwitch: () => setDemoPortal("platform"),
      portalSwitchLabel: "Back to Super Admin",
      onUpdateProfile: async () => undefined,
      onRotateCredential: async () => demoCredential(),
      onBookClass: async () => undefined,
      onCancelBooking: async () => undefined,
      onLogWorkout: async () => undefined,
      onRecordProgress: async () => undefined,
      onUpdatePreferences: async () => undefined,
    }} />;
    if (demoPortal === "gym") return <IronCoreDashboard key="gym-preview"
      {...sharedPreview}
      portalMode="gym"
      operator={{ name: "Aisha Khan", role: "Gym Owner" }}
      activeGym={{ id: "demo-gym", name: "Forge Fitness" }}
      gymOptions={[{ id: "demo-gym", name: "Forge Fitness" }]}
      liveMembers={{ rows: demoMembers, total: 2841, loading: false, error: null, onSearch: () => undefined, onReload: () => undefined, onInvitePortal: async () => `${window.location.origin}${window.location.pathname}#activate_gym=demo-gym&activate_token=${"demo".padEnd(64, "x")}` }}
      liveSaasBilling={{ ...demoSaasBilling, actorRole: "gym_owner" }}
      tenantViews={["gym-dashboard", "members", "branches", "plans", "memberships", "attendance", "coaching", "payments", "billing", "reports", "staff"]}
      onPortalSwitch={() => setDemoPortal("member")}
      portalSwitchLabel="Preview member portal"
    />;
    return <IronCoreDashboard key="platform-preview"
      {...sharedPreview}
      portalMode="platform"
      liveSaasBilling={demoSaasBilling}
      onPortalSwitch={() => setDemoPortal("gym")}
      portalSwitchLabel="Preview gym portal"
    />;
  }
  if (phase === "booting") return <main className="boot-page"><Brand /><LoaderCircle className="spin" size={24} /><span>Securing your workspace…</span></main>;
  if (phase === "anonymous" || !user) return <LoginScreen onLogin={login} busy={authBusy} error={authError} />;
  if (!selectedGym) return <TenantPicker user={user} gyms={gyms} notice={inviteNotice} onSelect={selectGym} onLogout={logout} />;
  if (selectedGym.role === "member") {
    const memberPortalData: MemberPortalData = {
      gym: selectedGym,
      ...memberSelf,
      classes: engagement.sessions,
      bookings: engagement.bookings,
      workoutPlans: coaching.plans,
      workoutSessions: coaching.sessions,
      measurements: coaching.measurements,
      preference: coaching.preference,
      loading: memberSelf.loading || engagement.loading || coaching.loading,
      error: memberSelf.error ?? engagement.error ?? coaching.error,
    };
    return <MemberPortal data={memberPortalData} actions={{
      onReload: () => { setMemberSelfRefresh((value) => value + 1); setEngagementRefresh((value) => value + 1); setCoachingRefresh((value) => value + 1); },
      onLogout: logout,
      onUpdateProfile: updateMemberSelfProfile,
      onRotateCredential: rotateMemberSelfCredential,
      onBookClass: async (sessionId) => { await bookClass(sessionId); },
      onCancelBooking: cancelBooking,
      onLogWorkout: logWorkoutSession,
      onRecordProgress: recordProgress,
      onUpdatePreferences: updateNotificationPreference,
    }} />;
  }

  const setupRoles: IronCoreRole[] = ["super_admin", "gym_owner", "gym_manager"];
  const membershipRoles: IronCoreRole[] = [...setupRoles, "receptionist"];
  const liveOperations: OperationData = {
    branches: operations.branches.map((v) => ({ id: v.id, name: v.name, code: v.code, email: v.email, phone: v.phone, status: v.status, isPrimary: v.is_primary })),
    plans: operations.plans.map((v) => ({ id: v.id, branchId: v.branch_id, name: v.name, code: v.code, interval: v.billing_interval, intervalCount: v.interval_count, priceMinor: v.price_amount_minor, currency: v.currency, status: v.status })),
    memberships: operations.memberships.map((v) => ({ id: v.id, memberId: v.member_id, planId: v.plan_id, status: v.status, startsAt: v.starts_at, nextBillingAt: v.next_billing_at, priceMinor: v.price_amount_minor, currency: v.currency })),
    members: members.rows.map((v) => ({ id: v.id, name: v.name })), loading: operations.loading, error: operations.error,
    baseCurrency: selectedGym.base_currency, canManageSetup: setupRoles.includes(selectedGym.role), canManageMemberships: membershipRoles.includes(selectedGym.role),
    onReload: () => setOperationsRefresh((v) => v + 1), onCreateBranch: createBranch, onCreatePlan: createPlan, onCreateMembership: createMembership,
  };
  const liveStaff: StaffData = {
    rows: staff.rows.map((row) => ({ id: row.id, name: row.user.name, email: row.user.email, role: row.role, branchId: row.home_branch_id, employeeNumber: row.employee_number, jobTitle: row.job_title, status: row.status, hiredAt: row.hired_at })),
    invitations: staff.invitations.map((row) => ({ id: row.id, email: row.email, role: row.role, branchId: row.home_branch_id, employeeNumber: row.employee_number, jobTitle: row.job_title, status: row.status, expiresAt: row.expires_at })),
    branches: operations.branches.map((branch) => ({ id: branch.id, name: branch.name })), loading: staff.loading, error: staff.error, actorRole: selectedGym.role,
    onReload: () => setStaffRefresh((value) => value + 1), onInvite: inviteStaff, onUpdate: updateStaff,
  };
  const financeSummary = finance.summary ?? { gross_minor: 0, refunded_minor: 0, net_minor: 0, pending_minor: 0, outstanding_minor: 0, currency: selectedGym.base_currency };
  const liveFinance: FinanceData = {
    payments: finance.payments.map((row) => ({ id: row.id, memberId: row.member_id, invoiceId: row.invoice_id, branchId: row.branch_id, receipt: row.receipt_number, provider: row.provider, method: row.method, status: row.status, amountMinor: row.amount_minor, refundedMinor: row.refunded_amount_minor, currency: row.currency, paidAt: row.paid_at, notes: row.notes })),
    invoices: finance.invoices.map((row) => ({ id: row.id, memberId: row.member_id, membershipId: row.membership_id, branchId: row.branch_id, number: row.number, status: row.status, currency: row.currency, totalMinor: row.total_amount_minor, paidMinor: row.paid_amount_minor, dueMinor: row.due_amount_minor, issuedAt: row.issued_at, dueAt: row.due_at, notes: row.notes })),
    members: members.rows.map((row) => ({ id: row.id, name: row.name })),
    memberships: operations.memberships.map((row) => ({ id: row.id, memberId: row.member_id, label: `${operations.plans.find((plan) => plan.id === row.plan_id)?.name ?? "Membership"} · ${row.status}` })),
    branches: operations.branches.map((row) => ({ id: row.id, name: row.name })),
    summary: { grossMinor: financeSummary.gross_minor, refundedMinor: financeSummary.refunded_minor, netMinor: financeSummary.net_minor, pendingMinor: financeSummary.pending_minor, outstandingMinor: financeSummary.outstanding_minor, currency: financeSummary.currency },
    gateway: finance.gateway ? { status: finance.gateway.status, chargesEnabled: finance.gateway.charges_enabled, payoutsEnabled: finance.gateway.payouts_enabled, detailsSubmitted: finance.gateway.details_submitted, accountId: finance.gateway.provider_account_id, requirements: finance.gateway.requirements?.currently_due ?? [] } : null,
    actorRole: selectedGym.role, loading: finance.loading, error: finance.error,
    onReload: () => setFinanceRefresh((value) => value + 1), onCreateInvoice: createInvoice, onCreatePayment: createPayment, onRefund: refundPayment, onConnectStripe: connectStripe, onRefreshStripe: refreshStripe,
  };
  const liveSaasBilling: SaasBillingData = {
    plans: saas.plans,
    subscription: saas.subscription,
    invoices: saas.invoices,
    baseCurrency: selectedGym.base_currency,
    actorRole: selectedGym.role,
    loading: saas.loading,
    error: saas.error,
    onReload: () => setSaasRefresh((value) => value + 1),
    onCheckout: startSaasCheckout,
    onPortal: openSaasPortal,
    onCreatePlan: user.platform_role === "super_admin" ? createSaasPlan : undefined,
  };
  const liveEngagement: EngagementData = {
    attendance: engagement.attendance,
    sessions: engagement.sessions,
    bookings: engagement.bookings,
    members: members.rows.map((row) => ({ id: row.id, name: row.name, number: row.membership })),
    branches: operations.branches.map((row) => ({ id: row.id, name: row.name })),
    trainers: staff.rows.filter((row) => row.role === "trainer" && row.status === "active").map((row) => ({ id: row.id, name: row.user.name })),
    actorRole: selectedGym.role,
    loading: engagement.loading,
    error: engagement.error,
    onReload: () => setEngagementRefresh((value) => value + 1),
    onCheckIn: checkInMember,
    onCheckOut: checkOutMember,
    onCreateSession: createClassSession,
    onBook: bookClass,
    onCancel: cancelBooking,
    onAttend: attendBooking,
    onIssueCredential: issueCredential,
  };
  // Lists are assembled from already tenant-filtered API responses. Assignment
  // references fill trainer/member self-service views without broad member reads.
  const coachingMembers = new Map<string, { id: string; name: string; number?: string }>();
  members.rows.forEach((row) => coachingMembers.set(row.id, { id: row.id, name: row.name, number: row.membership }));
  coaching.assignments.forEach((row) => {
    if (row.member) coachingMembers.set(row.member.id, { id: row.member.id, name: row.member.name, number: row.member.member_number ?? undefined });
  });
  coaching.plans.forEach((row) => {
    if (row.member) coachingMembers.set(row.member.id, { id: row.member.id, name: row.member.name, number: row.member.member_number ?? undefined });
  });
  const coachingTrainers = new Map<string, { id: string; name: string }>();
  staff.rows.filter((row) => row.role === "trainer" && row.status === "active").forEach((row) => coachingTrainers.set(row.id, { id: row.id, name: row.user.name }));
  coaching.assignments.forEach((row) => {
    if (row.trainer) coachingTrainers.set(row.trainer.id, { id: row.trainer.id, name: row.trainer.name ?? "Assigned trainer" });
  });
  coaching.plans.forEach((row) => {
    if (row.trainer) coachingTrainers.set(row.trainer.id, { id: row.trainer.id, name: row.trainer.name ?? "Assigned trainer" });
  });
  const liveCoaching: CoachingData = {
    assignments: coaching.assignments,
    plans: coaching.plans,
    sessions: coaching.sessions,
    measurements: coaching.measurements,
    preference: coaching.preference,
    deliveries: coaching.deliveries,
    members: [...coachingMembers.values()],
    trainers: [...coachingTrainers.values()],
    actorRole: selectedGym.role,
    loading: coaching.loading,
    error: coaching.error,
    onReload: () => setCoachingRefresh((value) => value + 1),
    onAssign: assignTrainer,
    onEndAssignment: endTrainerAssignment,
    onCreatePlan: createWorkoutPlan,
    onLogSession: logWorkoutSession,
    onRecordProgress: recordProgress,
    onUpdatePreference: updateNotificationPreference,
  };
  const canManageMembers = membershipRoles.includes(selectedGym.role);
  const canManageStaff = setupRoles.includes(selectedGym.role);
  const canReadSaasBilling = ["super_admin", "gym_owner", "gym_manager"].includes(selectedGym.role);
  const canReadReports = ["super_admin", "gym_owner", "gym_manager"].includes(selectedGym.role);
  const canUseCoaching = selectedGym.role !== "receptionist";
  const tenantViews: View[] = canManageMembers
    ? ["gym-dashboard", "members", "branches", "plans", "memberships", "attendance", ...(canUseCoaching ? ["coaching" as View] : []), "payments", ...(canReadSaasBilling ? ["billing" as View] : []), ...(canReadReports ? ["reports" as View] : []), ...(canManageStaff ? ["staff" as View] : [])]
    : ["attendance", "coaching", "branches", "plans"];

  return <IronCoreDashboard key={selectedGym.id}
    portalMode="gym"
    operator={{ name: user.name, role: roleLabel(selectedGym.role) }}
    activeGym={{ id: selectedGym.id, name: selectedGym.name }}
    gymOptions={gyms.map((gym) => ({ id: gym.id, name: gym.name }))}
    onGymChange={(gymId) => selectGym(gyms.find((gym) => gym.id === gymId) ?? null)}
    onLogout={logout}
    liveMembers={canManageMembers ? { ...members, onSearch: setMemberSearch, onReload: () => setMemberRefresh((value) => value + 1), onInvitePortal: inviteMemberPortal } : undefined}
    liveOperations={liveOperations}
    liveStaff={canManageStaff ? liveStaff : undefined}
    liveFinance={canManageMembers ? liveFinance : undefined}
    liveSaasBilling={canReadSaasBilling ? liveSaasBilling : undefined}
    liveEngagement={liveEngagement}
    liveCoaching={canUseCoaching ? liveCoaching : undefined}
    liveReports={canReadReports ? reportData : undefined}
    tenantViews={tenantViews}
    onCreateMember={createMember}
  />;
}
