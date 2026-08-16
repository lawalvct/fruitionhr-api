# How FruitionHR Billing Works

A plain-language guide to the subscription model — what we charge, what happens
when a customer stops paying, and what you control. Written for the business
side; no code required to follow it.

---

## The model in one line

**We charge per employee, per month.** A company with 14 staff on a ₦1,000 plan
pays ₦14,000 a month. Their bill follows their headcount.

---

## 1. What counts as an employee

The headcount is the meter, so it matters exactly who is on it.

| Status on the system | Billed? | Why |
| --- | --- | --- |
| Active | Yes | Obviously. |
| On leave | Yes | Still employed, still holds a seat, still uses the system. |
| Suspended | Yes | Same — they are still on the books. |
| **Exited** | **No** | They have left. Their records stay archived, but the company stops paying for them. |

Deleted records drop off the count automatically.

**The promise to the customer:** you pay for the people you employ. When someone
leaves, you stop paying for them — but you never lose their history.

---

## 2. Plans

Each plan is defined by four things, all editable from the admin console with no
developer involvement:

- **Price per employee, per month**
- **Minimum seats** — the floor. A 3-person company on a 5-seat plan pays for 5.
- **Employee guideline** — the point at which they have outgrown the plan.
- **Trial length** — free days when they first sign up. Currently 30 on every
  plan, so a company can complete one full payroll cycle before paying.

### Current plans

| Plan | Per employee | Seats | Trial |
| --- | --- | --- | --- |
| Starter | ₦1,000 | 5 – 25 | 30 days |
| Growth | ₦1,500 | 10 – 200 | 30 days |
| Enterprise | ₦2,500 | 50+ (no limit) | 30 days |

These figures are a starting point, not a decision — change them whenever you
like. Existing customers keep the price they are on until you move them.

---

## 3. What a bill actually looks like

| Company size | Plan | Calculation | Monthly bill |
| --- | --- | --- | --- |
| 3 employees | Starter | Below the 5-seat floor → billed for 5 | ₦5,000 |
| 14 employees | Starter | 14 × ₦1,000 | ₦14,000 |
| 40 employees | Starter | 40 × ₦1,000 — *and prompted to upgrade* | ₦40,000 |
| 40 employees | Growth | 40 × ₦1,500 | ₦60,000 |

Note the third row. **Passing the guideline does not cap the bill.** They are
charged for all 40 people and shown that Growth suits them better. The guideline
is advice, not a discount — otherwise the cheapest plan would quietly become
unlimited at the lowest rate.

---

## 4. The customer's journey

1. **They sign up.** A 30-day free trial starts automatically on the entry plan
   — no card needed, nothing to choose.
2. **They use the product.** Full access for the whole trial.
3. **The trial ends.** They pick a plan and pay by card or transfer, through
   Paystack or Nomba.
4. **It renews monthly.** Each renewal is priced on their headcount that day.
5. **Their team changes.** They add or lose people freely; the next bill follows.

---

## 5. If a customer does not pay

This is the part worth being deliberate about, because HR software holds payroll
records a company legally needs.

**Their workspace becomes read-only.** Immediately — there is no grace period.

**What they keep, always:**

- Signing in
- Viewing every record — employees, payslips, leave, attendance
- Exporting and downloading their data
- Reaching the billing page to pay

**What stops:** creating or changing anything. Adding staff, running payroll,
approving leave.

**Their data is never deleted.**

The reasoning: cutting a company off from its own payroll records would be
indefensible, and a business that cannot reach the payment page cannot pay us.
Read-only applies enough pressure to get the bill settled without holding their
records hostage.

The moment they pay, everything unlocks.

---

## 6. When a company grows mid-month

They add six people on the 10th. Those six can use the system that day.

**The extra six are charged on the next renewal, not immediately.** No mid-month
top-up charge, no part-month arithmetic on the invoice, no second card payment
that might decline. The bill simply reflects reality at each renewal.

The trade-off is a short revenue lag on growth, in exchange for a system with far
fewer ways to go wrong and a bill customers can predict.

---

## 7. When a company outgrows its plan

Nothing breaks. HR staff onboarding a new hire is urgent work, and blocking it to
force an upgrade would be the wrong moment to pick a fight.

Instead they are charged for everyone at their current rate, and shown a prompt
naming the plan that fits them better. It is framed honestly — *you are billed
for every employee either way, this plan is simply a better fit* — because the
upgrade genuinely is advice, not a penalty.

---

## 8. How the money is protected

Payments run through Paystack and Nomba using FruitionHR's own accounts.
Customers never supply payment credentials — this is subscription billing, not a
marketplace.

Four safeguards, in plain terms:

- **Every payment is confirmed with the provider directly.** We never take the
  browser's word that a payment succeeded.
- **A customer can never be charged twice for one payment**, even if their
  browser and the provider both report it at the same moment.
- **An underpayment does not activate a subscription.** If the amount received
  does not match the amount charged, it is flagged rather than accepted.
- **Payments are reconciled automatically.** If someone pays and closes their
  browser before the confirmation lands, a scheduled check catches it and
  activates their subscription anyway.

---

## 9. What you control

From the super-admin console:

**Billing → Plans** — create plans, change prices, adjust seat ranges and trial
lengths, retire a plan. Retiring hides it from new customers without disturbing
anyone already on it.

**Billing → Subscriptions** — every company, what they pay, their headcount, and
totals for revenue collected, active subscriptions, trials, and overdue accounts.

**Activity log** — every price change and plan edit is recorded with who did it.

---

## 10. Decisions made, and why

| Decision | Reasoning |
| --- | --- |
| Staff on leave still count | They are employed and still use the system. |
| Exited staff stop counting | Nobody should pay for people who have left. |
| Plans have a seat floor | Makes very small accounts viable to serve. |
| Guideline prompts an upgrade, does not cap the bill | Otherwise the cheapest plan becomes unlimited at the lowest rate. |
| Lapsed accounts go read-only, not locked | A company must always reach its own payroll records — and the payment page. |
| Growth billed at next renewal | Simpler and more predictable than part-month charges. |
| Trials start automatically at sign-up | Removes a decision from the first minute of using the product. |
| **Trials are 30 days on every plan** | Payroll runs monthly. A 14-day trial ends before a company ever completes a cycle, so they never see the product do the job they are hiring it for. Thirty days lets them run a full payroll — real payslips, real PAYE, pension and NHF on their own staff — which is what actually builds confidence to pay. |
| One trial per company | Switching plans keeps the original trial end date, so it cannot be restarted by hopping plans. |

---

## 11. Not built yet

Worth knowing before launch. None of these block taking money — they are the next
sensible additions.

| Gap | What it means today |
| --- | --- |
| **Invoices and receipts** | Payments are recorded and visible, but no PDF is issued. |
| **Dunning emails** | A customer whose payment fails is not emailed about it. They see it in the app; nothing chases them. |
| **Annual billing** | The system supports it; no annual plan has been priced yet. |
| **Discounts and coupons** | No mechanism for promotional pricing. |
| **Part-month refunds** | Downgrades take effect at renewal; nothing is refunded mid-period. |

### Before going live

Two operational steps, both one-off:

1. **Register the payment webhook addresses** with Paystack and Nomba, so
   payments confirm even when a customer closes their browser.
2. **Make sure the scheduled tasks are running** on the server. They handle
   reconciling payments and expiring lapsed subscriptions. Without them, a
   subscription that has ended will keep working indefinitely.

---

## Questions worth deciding

Not urgent, but they will come up:

- Should a customer be able to **downgrade** mid-period, and if so what happens
  to the difference?
- How long should an unpaid account stay recoverable before it is written off?
  Currently 30 days.
- Should very large customers get **negotiated pricing** outside the plan list?
- Is **annual billing at a discount** worth offering to improve cash flow?
