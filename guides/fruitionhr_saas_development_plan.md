# FruitionHR SaaS Development Plan

## Project Name

**FruitionHR**

## Product Vision

FruitionHR is a complete HR, Payroll, Performance, Recruitment, Time, Learning, and Employee Finance SaaS platform designed for growing African businesses and enterprise organizations.

The goal is to build a robust HR system that can compete with platforms like SeamlessHR and NotchHR, while remaining configurable, scalable, and practical for Nigerian/African business operations.

---

## 1. Product Positioning

### Core Positioning

> FruitionHR is an all-in-one HR automation platform for managing employees from recruitment to exit, with payroll, performance, attendance, leave, learning, compliance, and employee self-service in one system.

### Target Customers

- SMEs with growing staff strength
- Manufacturing companies
- Service companies
- Schools and institutions
- Professional firms
- Multi-branch organizations
- Companies needing payroll compliance
- Companies migrating from manual HR/Excel processes

### Main Competitive Pillars

1. **Core HR Management**
2. **Time & Attendance**
3. **Payroll & Compliance**
4. **Recruitment & Onboarding**
5. **Performance & Goals**
6. **Learning & Talent Management**
7. **Employee Finance & Self-Service**
8. **Workflow, Audit, and Reporting**

---

## 2. Main Product Modules

```txt
Dashboard

Company Setup
Employees
Recruitment
Attendance
Leave
Payroll
Performance
Goals
Training
Succession
Disciplinary
Exit
Assets
Employee Self-Service
Manager Self-Service
Reports
Workflow
Notifications
Documents
Administration
```

---

## 3. Recommended Technology Stack

### Recommended Stack

```txt
Backend: Laravel 11/12
Frontend: Inertia.js + Vue 3
Styling: Tailwind CSS
Database: PostgreSQL or MySQL
Cache/Queue: Redis
Auth/API: Laravel Sanctum
Permissions: Spatie Laravel Permission
Queue Monitoring: Laravel Horizon
PDF/Reports: Laravel Excel + PDF Generator
Storage: Local first, S3-compatible later
Deployment: VPS + Nginx + PHP-FPM + Supervisor + GitHub Actions
Optional Later: NestJS for integration services
```

### Why Laravel + Inertia + Vue?

This is the best balance for FruitionHR because:

- Laravel keeps backend development fast.
- Inertia allows a modern SPA-like experience without building a separate frontend API for every screen.
- Vue gives clean interactivity for dashboards, forms, approvals, tables, and modals.
- Laravel still handles routing, authorization, policies, jobs, queues, notifications, and reports easily.
- It keeps the project easier to maintain than Laravel API + separate Next.js frontend at the beginning.

### Blade Alternative

If speed is more important than modern frontend structure:

```txt
Laravel + Blade + Livewire + Alpine.js + Tailwind CSS
```

This is also good, especially for admin systems with many forms and tables.

### NestJS Recommendation

Do **not** split the main HR/payroll logic between Laravel and NestJS at the beginning.

Use Laravel as the main backend.

Use NestJS later only for specialized services such as:

```txt
Biometric device integration
Real-time attendance gateway
AI service gateway
Webhook processing
Notification microservice
Mobile BFF
```

Avoid this mistake:

```txt
Employees in Laravel
Attendance in NestJS
Payroll in Laravel
Performance in NestJS
Workflow split between both
```

That will slow development and make debugging difficult.

---

## 4. System Architecture

### Recommended Architecture

Use a **modular monolith** architecture first.

```txt
FruitionHR Web App
        |
        v
Laravel Application
        |
        |-- Company Module
        |-- Employee Module
        |-- Recruitment Module
        |-- Attendance Module
        |-- Leave Module
        |-- Payroll Module
        |-- Performance Module
        |-- Goals Module
        |-- Training Module
        |-- Succession Module
        |-- Disciplinary Module
        |-- Exit Module
        |-- Assets Module
        |-- Workflow Engine
        |-- Rule Engine
        |-- Notification Engine
        |-- Audit Engine
        |-- Document Engine
        |-- Reports Module
        |
        v
Database Layer
        |
        |-- PostgreSQL/MySQL
        |-- Redis
        |-- File Storage
```

### Optional Future Architecture

```txt
Laravel Core App
        |
        |-- Handles HR, payroll, workflow, reports, permissions
        |
        v
NestJS Integration Service
        |
        |-- Biometric integrations
        |-- AI gateway
        |-- Webhooks
        |-- Real-time services
```

---

## 5. Recommended Folder Structure

```txt
app/
  Modules/
    Company/
      Controllers/
      Models/
      Services/
      Actions/
      Requests/
      Policies/
      Resources/
      Jobs/
      Events/
      Listeners/

    Employee/
      Controllers/
      Models/
      Services/
      Actions/
      Requests/
      Policies/
      Resources/

    Recruitment/
    Attendance/
    Leave/
    Payroll/
    Performance/
    Goals/
    Training/
    Succession/
    Discipline/
    Exit/
    Assets/
    Workflow/
    Rules/
    Documents/
    Notifications/
    Reports/
    Security/
```

Each module should be responsible for its own business logic.

Avoid putting all logic inside controllers.

Recommended structure inside each module:

```txt
Controllers = receive request and return response
Requests = validation
Actions = single-purpose business actions
Services = larger business processes
Models = database models
Policies = authorization
Jobs = background tasks
Events/Listeners = system events
Resources = API/Inertia response formatting
```

---

## 6. Multi-Tenancy Strategy

### First Version

Use **single database with tenant_id column**.

Most business tables should include:

```txt
tenant_id
created_by
updated_by
deleted_by
created_at
updated_at
deleted_at
```

### Why Tenant ID Strategy?

- Easier to build
- Easier to maintain
- Lower hosting cost
- Easier reporting
- Good enough for early SaaS
- Easier backup and deployment

### Important Rule

Every query must be scoped by `tenant_id`.

Example:

```php
Employee::where('tenant_id', currentTenantId())->get();
```

Use middleware to resolve the current tenant.

Later, for large enterprise clients, you can support separate database per tenant.

---

## 7. Core Engines

FruitionHR should be built around reusable engines, not isolated modules.

The four most important engines are:

```txt
1. Workflow Engine
2. Rule Engine
3. Audit Engine
4. Document Engine
```

---

## 8. Workflow Engine

The workflow engine should support approvals across all modules.

### Modules That Need Workflow

```txt
Leave
Payroll
Recruitment
Training
Appraisal
Loan
Exit
Discipline
Asset Request
Profile Update
Overtime
Attendance Adjustment
```

### Example Leave Workflow

```txt
Employee
  ↓
Supervisor
  ↓
HR
  ↓
Approved
```

### Example Payroll Workflow

```txt
Payroll Officer
  ↓
Finance Manager
  ↓
HR Manager
  ↓
Managing Director
  ↓
Posted/Locked
```

### Suggested Tables

```txt
workflow_definitions
workflow_steps
workflow_requests
workflow_actions
workflow_approvers
```

### workflow_definitions

```txt
id
tenant_id
module
name
description
is_active
created_by
created_at
updated_at
```

### workflow_steps

```txt
id
tenant_id
workflow_definition_id
step_order
step_name
approver_type
approver_role_id
approver_user_id
requires_all_approvers
can_reject
can_delegate
created_at
updated_at
```

### workflow_requests

```txt
id
tenant_id
workflow_definition_id
module
record_type
record_id
requested_by
current_step_id
status
submitted_at
approved_at
rejected_at
created_at
updated_at
```

### workflow_actions

```txt
id
tenant_id
workflow_request_id
workflow_step_id
action_by
action
comments
action_date
created_at
```

### Workflow Statuses

```txt
draft
submitted
pending
approved
rejected
cancelled
returned
```

---

## 9. Rule Engine

The rule engine should control payroll, leave, attendance, overtime, statutory deductions, and performance grading.

### Rule Areas

```txt
Payroll rules
Late deduction rules
Overtime rules
Leave accrual rules
PAYE rules
Pension rules
NHF rules
NSITF rules
Performance grade rules
Loan repayment rules
```

### Simple Rule Table Approach

```txt
rules
rule_conditions
rule_actions
```

### Alternative Practical Approach

Start with separate rule tables per domain:

```txt
payroll_rules
attendance_rules
leave_policies
overtime_rules
statutory_rules
performance_grade_rules
```

This is easier for the first version.

### Rule Examples

```txt
If employee is late beyond grace period, mark late.
If late minutes exceed configured limit, apply deduction.
If leave type is unpaid, deduct daily salary.
If overtime is approved, pay hourly rate x multiplier.
If appraisal score is 90-100, grade is Outstanding.
```

---

## 10. Audit Engine

Every sensitive action must be audited.

### Audit Events

```txt
Create record
Update record
Delete record
Approve request
Reject request
Cancel request
Reverse payroll
Lock payroll
Export payroll
Download payslip
Change salary
Change role/permission
Login
Failed login
Upload document
Delete document
```

### Suggested Table

```txt
audit_logs
```

### audit_logs Fields

```txt
id
tenant_id
user_id
module
action
auditable_type
auditable_id
old_values
new_values
ip_address
user_agent
created_at
```

### Important Advice

Do not rely only on simple activity logs. Payroll and HR changes need proper before/after records.

---

## 11. Document Engine

Documents should be reusable across the whole system.

### Document Uses

```txt
Employee documents
CVs
Certificates
Contracts
Offer letters
Warning letters
Training certificates
Exit documents
Policies
Medical records
Passport/Visa
Identity documents
```

### Suggested Table

```txt
documents
```

### documents Fields

```txt
id
tenant_id
owner_type
owner_id
document_type
title
file_path
file_size
mime_type
version
uploaded_by
expires_at
status
created_at
updated_at
```

---

## 12. Notification Engine

Notifications should be event-driven.

### Channels

```txt
In-app
Email
SMS later
Push notification later
```

### Notification Events

```txt
Leave submitted
Leave approved
Leave rejected
Payroll awaiting approval
Payslip generated
Appraisal opened
Training assigned
Loan approved
Offer sent
Exit clearance pending
Document expiring
Contract expiring
Employee birthday
Work anniversary
```

---

# 13. Module Details

---

## 13.1 Company Setup Module

### Purpose

This module manages company-level configurations and master data.

### Submodules

```txt
Company Information
Branches
Departments
Divisions
Business Units
Cost Centres
Job Grades
Salary Grades
Positions
Job Titles
Employment Types
Shift Definitions
Holiday Calendar
Leave Year
Tax Configuration
Pension Configuration
NHF Configuration
NSITF Configuration
Bank Setup
Approval Workflow Setup
```

### Suggested Tables

```txt
tenants
companies
branches
departments
divisions
business_units
cost_centres
job_grades
salary_grades
positions
job_titles
employment_types
holiday_calendars
holiday_calendar_dates
company_banks
statutory_settings
```

---

## 13.2 Employee Management Module

### Purpose

This is the core employee database.

### Submodules

```txt
Employee Biodata
Employment History
Emergency Contacts
Next of Kin
Family Information
Educational History
Professional Qualifications
Certifications
Skills
Languages
Medical Information
Passport & Visa
Identity Documents
Bank Details
Pension Details
Tax Details
Digital Signature
Employee Documents
Employee Photo
Employment Contracts
```

### Suggested Tables

```txt
employees
employee_profiles
employee_employment_records
employee_contacts
employee_family_members
employee_education
employee_qualifications
employee_certifications
employee_skills
employee_languages
employee_medical_records
employee_identity_documents
employee_bank_accounts
employee_statutory_details
employee_contracts
```

### Important Design Advice

Do not put everything inside one `employees` table.

Keep `employees` for main employee identity and status.

Use related tables for deep details.

### Example employees Table

```txt
id
tenant_id
employee_number
user_id
first_name
middle_name
last_name
official_email
personal_email
phone
gender
date_of_birth
employment_status
photo_path
created_at
updated_at
deleted_at
```

### Example employee_employment_records Table

```txt
id
tenant_id
employee_id
branch_id
department_id
division_id
business_unit_id
cost_centre_id
position_id
job_grade_id
salary_grade_id
employment_type_id
supervisor_id
effective_from
effective_to
status
created_at
updated_at
```

---

## 13.3 Recruitment Management Module

### Purpose

To manage the full recruitment lifecycle.

### Flow

```txt
Manpower Requisition
  ↓
Approval
  ↓
Vacancy Creation
  ↓
Application
  ↓
Shortlisting
  ↓
Interview
  ↓
Assessment
  ↓
Offer
  ↓
Acceptance
  ↓
Onboarding
  ↓
Employee Creation
```

### Submodules

```txt
Manpower Requisition
Vacancy Management
Applicant Portal
Applicant Tracking
Interview Management
Assessment
Offer Management
Onboarding Checklist
```

### Applicant Stages

```txt
Applied
Shortlisted
Interview Scheduled
Interviewed
Second Interview
Assessment
Offer
Accepted
Rejected
Hired
```

### Suggested Tables

```txt
manpower_requisitions
vacancies
applicants
applications
application_stage_history
interviews
interview_panels
interview_questions
interview_scores
assessments
offers
onboarding_tasks
```

### Important Rule

Recruitment cannot begin until manpower requisition is approved.

---

## 13.4 Attendance Management Module

### Purpose

To track time, shifts, attendance, lateness, absence, overtime, and timesheets.

### Features

```txt
Clock In
Clock Out
Biometric Import
GPS Attendance
Geo-fencing
Shift Roster
Late Arrival
Early Exit
Absent
Holiday
Weekend
Overtime
Timesheet
Attendance Approval
Attendance Finalization
```

### Attendance Flow

```txt
Raw Attendance
  ↓
Supervisor Review
  ↓
HR Adjustment
  ↓
Attendance Approval
  ↓
Attendance Finalization
  ↓
Payroll Processing
```

### Logic Examples

```txt
Clock-in time > shift start + grace period = Late
Clock-out time < shift end = Early exit
No clock-in on working day = Absent
Approved leave = Not absent
Holiday/weekend = No deduction unless configured
```

### Suggested Tables

```txt
shifts
shift_rosters
attendance_logs
attendance_summaries
attendance_imports
attendance_adjustments
attendance_approvals
attendance_finalizations
```

### Important Payroll Rule

Payroll should only use finalized attendance, not raw attendance logs.

---

## 13.5 Leave Management Module

### Purpose

To manage leave policies, leave applications, balances, approvals, and payroll impact.

### Leave Types

```txt
Annual
Casual
Sick
Study
Compassionate
Maternity
Paternity
Unpaid
Special Leave
```

### Leave Balance Formula

```txt
Opening Balance
+ Annual Allocation
+ Carry Forward
- Days Taken
= Current Balance
```

### Leave Flow

```txt
Employee applies
  ↓
Supervisor approval
  ↓
HR approval
  ↓
Approved
  ↓
Attendance updated
  ↓
Payroll updated if applicable
```

### Suggested Tables

```txt
leave_types
leave_policies
leave_balances
leave_requests
leave_request_days
leave_approvals
leave_adjustments
leave_years
```

---

## 13.6 Payroll Management Module

### Purpose

To process payroll accurately using salary structure, attendance, leave, overtime, loans, statutory deductions, and approvals.

### Submodules

```txt
Pay Periods
Salary Structure
Salary Components
Allowances
Deductions
Loans
Salary Advances
Overtime
Bonuses
Commissions
PAYE
Pension
NHF
NSITF
13th Month
Gratuity
Final Settlement
Payslip
Bank Schedule
Payroll Journal
Payroll Approval
Payroll Lock
Payroll Reversal
Payroll Audit
Payroll Variance Report
```

### Payroll Flow

```txt
1. Create Pay Period
2. Confirm Active Employees
3. Pull Salary Structures
4. Pull Finalized Attendance
5. Pull Approved Leave
6. Pull Approved Overtime
7. Pull Loan Repayments
8. Calculate Gross Pay
9. Calculate Statutory Deductions
10. Calculate Other Deductions
11. Calculate Net Pay
12. Generate Payroll Preview
13. Show Payroll Variance
14. Submit Payroll for Approval
15. Approve Payroll
16. Lock Payroll
17. Generate Payslips
18. Generate Bank Schedule
19. Generate Statutory Reports
20. Export Payroll Journal
```

### Payroll Should Not Run Unless

```txt
Attendance is finalized
Leave is processed
Loans are updated
Overtime is approved
Approvals are completed
```

### Payroll Formula

```txt
Gross Pay
- PAYE
- Pension
- NHF
- Loans
- Absence Deductions
- Late Deductions
- Other Deductions
= Net Pay
```

### Suggested Tables

```txt
pay_periods
salary_components
salary_structures
employee_salary_structures
employee_salary_components
payroll_runs
payroll_run_employees
payroll_earnings
payroll_deductions
payroll_statutories
payslips
payroll_approvals
payroll_reversals
payroll_bank_schedules
payroll_journals
payroll_variances
```

### Payroll Locking Rule

After payroll is approved and locked, do not allow silent editing.

Corrections should be done using:

```txt
Payroll reversal
Payroll adjustment
Next month adjustment
```

---

## 13.7 Payroll Journal

Even if FruitionHR is not a full accounting system, it should generate payroll journals.

### Example Payroll Journal

```txt
DR Salary Expense
DR Employer Pension Expense
DR NSITF Expense
CR Salary Payable
CR PAYE Payable
CR Pension Payable
CR NHF Payable
CR Loan Receivable
```

### Salary Payment Journal

```txt
DR Salary Payable
CR Bank
```

### Why This Matters

This makes FruitionHR useful to companies that want to export payroll data to accounting software.

---

## 13.8 Performance Management Module

### Purpose

To manage appraisal cycles, KPIs, rating scales, weighted reviews, and performance outcomes.

### Features

```txt
Appraisal Cycles
Performance Categories
KPI Library
Weighted KPIs
Rating Scales
Self Review
Manager Review
Peer Review
Subordinate Review
Customer Review
360-Degree Review
OKRs
Balanced Scorecard
Competency Assessment
Final Score
Final Grade
Performance Outcome
```

### Rating Scale Examples

```txt
1-5 Scale
1-10 Scale
Percentage
Letter Grade
Behaviour Scale
Custom Scale
```

### Reviewer Weight Example

```txt
Manager = 60%
Self = 10%
Peers = 15%
Subordinates = 10%
Customers = 5%
```

### Final Score Formula

```txt
Manager Score x 60%
+ Self Score x 10%
+ Peer Score x 15%
+ Subordinate Score x 10%
+ Customer Score x 5%
= Final Score
```

### Grade Example

```txt
90-100 = Outstanding
80-89 = Excellent
70-79 = Very Good
60-69 = Good
50-59 = Fair
Below 50 = Poor
```

### Suggested Tables

```txt
appraisal_cycles
performance_categories
performance_kpis
appraisal_templates
appraisal_template_items
rating_scales
rating_scale_options
appraisal_assignments
appraisal_reviewers
appraisal_reviews
appraisal_scores
appraisal_results
performance_outcomes
```

### Validation Rules

```txt
KPI/category weight must equal 100%
Reviewer source weight must equal 100%
```

---

## 13.9 Goal Management Module

### Purpose

To manage company, department, and individual goals.

### Goal Levels

```txt
Company Goals
Department Goals
Individual Goals
```

### Features

```txt
SMART Goals
Goal Weight
Due Date
Completion Percentage
Status
Check-ins
Comments
Manager Review
```

### Suggested Tables

```txt
goals
goal_updates
goal_checkins
goal_comments
```

---

## 13.10 Training Management Module

### Purpose

To manage employee learning, training plans, cost, attendance, assessment, and certification.

### Features

```txt
Training Needs Analysis
Training Calendar
Training Budget
Training Provider
Training Attendance
Assessment
Certification
Training Evaluation
Training Cost
```

### Later LMS Features

```txt
Course Library
Lessons
Videos
Quizzes
Assignments
Certificates
Progress Tracking
Mandatory Training
Compliance Training
```

### Suggested Tables

```txt
training_needs
training_programs
training_sessions
training_attendees
training_assessments
training_certificates
training_evaluations
training_costs
```

---

## 13.11 Succession Planning Module

### Purpose

To identify future leaders and replacements for critical positions.

### Features

```txt
Critical Positions
Potential Successors
Readiness Level
Talent Pool
Replacement Chart
Career Path
```

### Suggested Tables

```txt
critical_positions
successor_candidates
talent_pools
career_paths
succession_plans
```

---

## 13.12 Disciplinary Management Module

### Purpose

To manage employee disciplinary cases and actions.

### Flow

```txt
Query
  ↓
Investigation
  ↓
Hearing
  ↓
Decision
  ↓
Warning/Suspension/Termination Recommendation
  ↓
Appeal
  ↓
Closure
```

### Suggested Tables

```txt
disciplinary_cases
disciplinary_actions
disciplinary_hearings
disciplinary_evidence
disciplinary_decisions
disciplinary_appeals
```

---

## 13.13 Exit Management Module

### Purpose

To manage resignation, termination, retirement, clearance, final settlement, and employee closure.

### Exit Types

```txt
Resignation
Retirement
Dismissal
Termination
Death
Contract End
```

### Exit Flow

```txt
Resignation submitted
  ↓
Manager approval
  ↓
HR clearance
  ↓
Asset return
  ↓
Finance clearance
  ↓
IT access removal
  ↓
Final payroll
  ↓
Exit interview
  ↓
Employee closed
```

### Suggested Tables

```txt
exit_requests
exit_clearance_items
exit_clearance_actions
exit_interviews
final_settlements
```

---

## 13.14 Asset Management Module

### Purpose

To manage company assets assigned to employees.

### Asset Types

```txt
Laptop
Phone
SIM
Vehicle
ID Card
Uniform
Office Keys
Other Equipment
```

### Features

```txt
Asset Register
Asset Assignment
Asset Return
Asset Damage
Asset History
Asset Clearance
```

### Suggested Tables

```txt
asset_categories
assets
asset_assignments
asset_returns
asset_damages
asset_history
```

---

## 13.15 Employee Self-Service

### Employee Can

```txt
View Profile
Request Profile Update
Apply for Leave
View Leave Balance
Clock In/Out
View Attendance
View Payslip
Download Tax Certificate
Request Loan
Request Training
Complete Appraisal
Update Goals
View Announcements
Upload Documents
```

---

## 13.16 Manager Self-Service

### Manager Can

```txt
View Team Dashboard
Approve Leave
Approve Attendance
Approve Payroll if authorized
Approve Recruitment
Approve Training
Approve Appraisal
View Team Attendance
View Team Leave Calendar
View Team Performance
Recommend Promotion
Recommend Training
Initiate Disciplinary Query
```

---

## 13.17 Reports Module

### Employee Reports

```txt
Employee List
Headcount by Department
New Hires
Exits
Contract Expiry
Birthday Report
Work Anniversary
Employee Document Expiry
```

### Payroll Reports

```txt
Payroll Summary
Payslip
Bank Schedule
PAYE Report
Pension Report
NHF Report
NSITF Report
Loan Deduction Report
Payroll Variance Report
Department Payroll Cost
Payroll Journal
```

### Attendance Reports

```txt
Daily Attendance
Absenteeism
Late Arrival
Early Exit
Overtime
Timesheet
```

### Leave Reports

```txt
Leave Balance
Leave Taken
Leave Liability
Leave Calendar
Unpaid Leave
```

### Recruitment Reports

```txt
Vacancies
Applicants by Stage
Time-to-Hire
Offer Acceptance
Recruitment Cost
```

### Performance Reports

```txt
Appraisal Results
KPI Score
Department Performance
Low Performers
Promotion Recommendations
Training Recommendations
```

### Audit Reports

```txt
User Activity
Approval Trail
Payroll Changes
Salary Changes
Deleted Records
```

---

## 13.18 Security & Administration

### Features

```txt
Role-Based Access Control
Permissions
User Groups
Audit Trail
Two-Factor Authentication
Password Policies
Session Management
API Access
Data Backup
System Logs
Login History
Device/IP Log
```

### Important Permission Examples

```txt
view_employee_basic_info
view_employee_salary
edit_employee_salary
view_payroll
process_payroll
approve_payroll
reverse_payroll
view_statutory_reports
download_employee_documents
delete_employee_record
manage_roles
manage_permissions
```

### Important Security Advice

Do not give one general HR permission that can do everything.

Salary visibility must be separated from employee profile visibility.

---

# 14. Database Planning

## Common Columns

Most business tables should include:

```txt
id
tenant_id
created_by
updated_by
deleted_by
created_at
updated_at
deleted_at
```

## Approval-Based Tables

Approval records should include:

```txt
status
submitted_at
approved_at
rejected_at
current_workflow_step_id
```

## Historical Records

Important changes should not be overwritten.

Use effective dates.

### Salary History Example

```txt
employee_salary_structures
- employee_id
- effective_from
- effective_to
- gross_salary
- status
```

### Employment Transfer Example

```txt
employee_employment_records
- employee_id
- department_id
- position_id
- branch_id
- effective_from
- effective_to
```

This allows historical reporting.

Example question the system should answer:

```txt
Which department was this employee in as of January 2025?
```

---

# 15. Suggested Development Phases

---

## Phase 0: Discovery and System Design

### Goal

Understand client requirements and freeze the MVP scope.

### Deliverables

```txt
Requirement document
Module list
User roles
Approval workflows
Payroll rules
Leave policies
Attendance policies
Performance appraisal structure
Database ERD
UI wireframes
Technical architecture
Development roadmap
```

### Activities

```txt
Interview HR team
Interview payroll team
Interview finance team
Interview management
Collect existing HR forms
Collect payroll Excel templates
Collect payslip format
Collect attendance template/device format
Collect approval process
Collect statutory rules
```

---

## Phase 1: Foundation Setup

### Goal

Build the foundation of the SaaS.

### Features

```txt
Authentication
Tenant setup
Company profile
Branches
Departments
Positions
Job grades
Salary grades
Employment types
Users
Roles
Permissions
Audit log foundation
Basic dashboard
```

### Technical Work

```txt
Project setup
Database setup
Module structure
Tenant middleware
RBAC setup
Base layout
Navigation
Reusable components
Global settings
```

---

## Phase 2: Employee Core

### Goal

Build the employee master record.

### Features

```txt
Employee list
Add employee
Employee biodata
Employment details
Contacts
Next of kin
Bank details
Tax details
Pension details
Documents
Contracts
Employee photo
Employee status
Employment history
```

### Deliverables

```txt
Employee profile page
Employee import template
Employee document upload
Employee history tracking
Employee report
```

---

## Phase 3: Workflow, Documents, Notifications

### Goal

Build reusable engines before deeper modules.

### Features

```txt
Workflow definitions
Workflow steps
Approval requests
Approval actions
Document engine
Notification engine
Audit trail
```

### Deliverables

```txt
Reusable approval engine
Reusable document upload
In-app notifications
Email notification foundation
Approval trail UI
```

---

## Phase 4: Attendance and Leave

### Goal

Build time and absence management.

### Attendance Features

```txt
Shift setup
Shift roster
Clock in/out
Manual attendance entry
Attendance import
Late arrival
Early exit
Absence
Attendance adjustment
Attendance approval
Attendance finalization
```

### Leave Features

```txt
Leave types
Leave policies
Leave balances
Leave request
Leave approval
Leave calendar
Unpaid leave handling
Leave adjustment
```

### Deliverables

```txt
Attendance report
Leave balance report
Leave calendar
Attendance finalization screen
```

---

## Phase 5: Payroll Core

### Goal

Build accurate payroll processing.

### Features

```txt
Pay periods
Salary components
Salary structures
Employee salary setup
Allowances
Deductions
Loans
Salary advances
Overtime
Payroll preview
Gross pay calculation
Statutory deductions
Net pay calculation
Payroll approval
Payroll lock
Payslip generation
Bank schedule
Statutory reports
Payroll audit
```

### Deliverables

```txt
Payroll processing screen
Payroll preview
Payslip PDF
Bank schedule export
PAYE report
Pension report
NHF report
NSITF report
Payroll approval workflow
```

---

## Phase 6: Payroll Advanced

### Goal

Make payroll enterprise-ready.

### Features

```txt
Payroll reversal
Payroll adjustment
Payroll variance report
13th month
Gratuity
Final settlement
Payroll journal
Payroll export
Salary increment history
Department payroll cost
```

### Deliverables

```txt
Payroll variance report
Payroll reversal screen
Payroll journal export
Final settlement calculation
```

---

## Phase 7: Employee and Manager Self-Service

### Goal

Reduce HR workload and enable self-service.

### Employee Features

```txt
View profile
Request profile update
Apply leave
View attendance
View payslip
Download tax certificate
Request loan
Request training
Complete appraisal
View announcements
```

### Manager Features

```txt
Team dashboard
Approve leave
Approve attendance
View team attendance
View team leave
Approve training
Approve appraisal
Recommend employee action
```

### Deliverables

```txt
Employee portal
Manager portal
Self-service dashboard
Approval inbox
```

---

## Phase 8: Recruitment and Onboarding

### Goal

Manage hiring from request to employee creation.

### Features

```txt
Manpower requisition
Vacancy management
Applicant portal
Applicant tracking
Interview scheduling
Interview panels
Interview scorecards
Assessment
Offer letter
Offer acceptance
Onboarding checklist
Convert candidate to employee
```

### Deliverables

```txt
ATS dashboard
Applicant portal
Interview scorecard
Offer letter generator
Onboarding workflow
```

---

## Phase 9: Performance and Goals

### Goal

Build configurable performance management.

### Features

```txt
Appraisal cycles
Performance categories
KPI library
Rating scales
Weighted KPIs
Self review
Manager review
Peer review
Subordinate review
Customer review
360-degree review
OKRs
Balanced scorecard
Competency assessment
Final score
Final grade
Performance outcome
```

### Deliverables

```txt
Appraisal setup
Appraisal forms
Review assignment
Final score calculation
Performance report
Promotion/training recommendation
```

---

## Phase 10: Training and Learning

### Goal

Support employee development.

### Features

```txt
Training needs analysis
Training calendar
Training budget
Training provider
Training attendance
Assessment
Certification
Training evaluation
Training cost
Course library later
Lessons later
Quizzes later
```

### Deliverables

```txt
Training calendar
Training attendance
Training report
Certificate upload/download
```

---

## Phase 11: Enterprise HR Modules

### Goal

Complete advanced HR lifecycle management.

### Features

```txt
Succession planning
Disciplinary management
Exit management
Asset management
Final settlement
Clearance workflow
Exit interview
```

### Deliverables

```txt
Succession dashboard
Disciplinary case management
Exit workflow
Asset assignment/return
Final settlement workflow
```

---

## Phase 12: Advanced Reports and AI

### Goal

Add intelligence and decision support.

### Advanced Reports

```txt
Custom report builder
Dashboard analytics
Payroll trend
Attrition trend
Performance trend
Attendance trend
Department cost analysis
```

### AI Features

```txt
AI job description generator
AI interview question generator
AI CV match score
AI appraisal summary
AI payroll variance explanation
AI HR policy assistant
AI warning letter generator
AI promotion letter generator
AI training recommendation
```

---

# 16. MVP Scope Recommendation

Do not build everything at once.

## MVP 1: Core HR + Payroll

Build this first:

```txt
Company setup
Users/roles/permissions
Employee management
Departments/positions/grades
Salary components
Salary structure
Attendance
Leave
Payroll processing
Payslip
Bank schedule
Statutory reports
Workflow approval
Audit trail
Basic reports
```

This is the first sellable version.

## MVP 2: Self-Service + Manager Portal

```txt
Employee self-service
Manager self-service
Approval inbox
Profile update request
Leave request
Attendance view
Payslip view
Loan request
Notifications
```

## MVP 3: Recruitment + Onboarding

```txt
Manpower requisition
Vacancies
Applicant portal
Applicant tracking
Interviews
Offer letters
Onboarding checklist
Convert applicant to employee
```

## MVP 4: Performance + Goals

```txt
Appraisal cycles
KPIs
Rating scales
OKRs
360 review
Performance reports
Training recommendations
```

## MVP 5: Learning + Finance

```txt
Training needs
Training calendar
Training attendance
Certificates
Loans
Salary advances
Repayment schedules
```

## MVP 6: Enterprise

```txt
Succession planning
Disciplinary management
Exit management
Asset management
Custom reports
AI features
Mobile app
API integrations
```

---

# 17. What Not To Build First

Avoid these in the first version:

```txt
Mobile app
AI everywhere
Microservices
Complex formula builder
Payroll financing
Full LMS video platform
Custom report builder
Biometric hardware integration
Multi-country payroll
```

These are useful but can delay the product.

Build the core system first.

---

# 18. Development Team Roles

For a serious build, recommended roles are:

```txt
Product Owner
Project Manager
Backend Developer
Frontend Developer
UI/UX Designer
QA Tester
DevOps Support
HR/Payroll Domain Consultant
```

If the team is small, one person can cover multiple roles, but do not ignore QA and domain validation.

---

# 19. Testing Strategy

### Test Types

```txt
Unit tests
Feature tests
Payroll calculation tests
Approval workflow tests
Permission tests
Import/export tests
PDF generation tests
Security tests
UAT tests
```

### Critical Test Areas

```txt
Payroll calculations
PAYE/pension/NHF/NSITF deductions
Leave balance calculation
Attendance finalization
Late deduction
Overtime calculation
Approval workflow
Salary visibility permission
Tenant data isolation
Payroll reversal
Payslip generation
```

Payroll should be tested with real sample Excel data from the client.

---

# 20. Deployment Plan

### Recommended VPS Setup

```txt
Ubuntu Server
Nginx
PHP-FPM
PostgreSQL/MySQL
Redis
Supervisor
SSL Certificate
GitHub Actions
Daily database backup
Weekly full server backup
Object storage backup later
```

### Required Background Workers

```txt
Queue worker
Notification worker
Payroll report generation worker
Import processing worker
PDF generation worker
Scheduled jobs
```

### Laravel Scheduler Tasks

```txt
Document expiry reminders
Contract expiry reminders
Birthday notifications
Work anniversary notifications
Leave accrual updates
Attendance finalization reminders
Backup checks
Payroll period reminders
```

---

# 21. CI/CD Plan

### Recommended Flow

```txt
Developer pushes to GitHub
  ↓
GitHub Actions runs tests
  ↓
Deploy to staging
  ↓
QA/UAT testing
  ↓
Deploy to production
  ↓
Run migrations
  ↓
Restart queues
  ↓
Clear cache
```

### Deployment Commands

```bash
php artisan down
git pull origin main
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan queue:restart
php artisan up
```

---

# 22. Backup Plan

### Backup Types

```txt
Daily database backup
Weekly full server backup
Document storage backup
Monthly archive backup
Pre-deployment backup
```

### Backup Storage

```txt
Local server backup
Remote storage
Google Drive/S3-compatible storage
External drive/manual backup for enterprise clients
```

### Restore Testing

Backups are not complete until restore has been tested.

Perform test restore at least monthly.

---

# 23. Security Checklist

```txt
Use HTTPS everywhere
Enable 2FA
Use RBAC and permissions
Separate salary visibility permissions
Audit sensitive actions
Log login history
Protect document downloads
Use signed URLs for private files
Prevent tenant data leakage
Use CSRF protection
Use rate limiting
Use secure password policy
Use database backups
Use environment variables
Disable debug mode in production
Use proper server firewall
Restrict database remote access
Keep dependencies updated
```

---

# 24. AI Feature Roadmap

AI should come after the core system is stable.

### Early AI Features

```txt
AI job description generator
AI interview questions
AI offer letter draft
AI appraisal summary
AI payroll variance explanation
AI leave/attendance insight
```

### Advanced AI Features

```txt
AI CV screening
AI HR policy chatbot
AI attrition risk insight
AI training recommendation
AI salary benchmarking
AI compliance assistant
```

### Important AI Warning

AI should assist HR decisions, not replace approval, compliance, or payroll calculation logic.

Payroll and statutory calculations should remain deterministic and rule-based.

---

# 25. Product Success Advice

The biggest risk is not coding.

The biggest risk is **scope control**.

The client wants an enterprise HR system. If everything is attempted at once, the project may become too large and difficult to complete.

Recommended delivery strategy:

```txt
Start with Core HR + Payroll.
Then add Self-Service.
Then Recruitment.
Then Performance.
Then Training.
Then Enterprise modules.
Then AI and integrations.
```

This gives the client progress and gives the development team control.

---

# 26. Final Recommendation

Build FruitionHR as a Laravel modular SaaS platform with:

```txt
Employee Core
Workflow Engine
Rule Engine
Audit Engine
Document Engine
Notification Engine
Payroll Engine
Reporting Engine
```

Recommended stack:

```txt
Laravel + Inertia + Vue + Tailwind
PostgreSQL/MySQL
Redis
Laravel Horizon
Laravel Sanctum
Spatie Permission
VPS + GitHub Actions CI/CD
NestJS later only for integrations
```

The right first goal is not to build every module immediately.

The right first goal is to build a strong foundation that can grow into a serious SeamlessHR/NotchHR competitor.

---

# 27. Immediate Next Steps

```txt
1. Confirm MVP scope with the client.
2. Collect their payroll Excel templates.
3. Collect their employee biodata form.
4. Collect attendance format/device sample.
5. Collect payslip format.
6. Collect approval workflows.
7. Collect leave policies.
8. Collect salary structure rules.
9. Create database ERD.
10. Create UI wireframes.
11. Start Phase 1 development.
```
