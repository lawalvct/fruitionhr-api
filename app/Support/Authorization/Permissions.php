<?php

namespace App\Support\Authorization;

/**
 * Canonical permission catalogue. Permissions are global rows; roles are
 * per-tenant (Spatie teams mode). Add new permissions here, then re-run
 * `php artisan db:seed --class=PermissionSeeder` (idempotent).
 *
 * Naming: <module>.<action>. Salary visibility is deliberately separate from
 * employee visibility (see architecture plan §7).
 */
class Permissions
{
    // Company setup
    public const COMPANY_VIEW = 'company.view';
    public const COMPANY_MANAGE = 'company.manage';

    // Users & roles
    public const USERS_VIEW = 'users.view';
    public const USERS_MANAGE = 'users.manage';
    public const ROLES_MANAGE = 'roles.manage';

    // Employees
    public const EMPLOYEES_VIEW = 'employees.view';
    public const EMPLOYEES_CREATE = 'employees.create';
    public const EMPLOYEES_UPDATE = 'employees.update';
    public const EMPLOYEES_DELETE = 'employees.delete';
    public const EMPLOYEES_VIEW_SALARY = 'employees.view_salary';
    public const EMPLOYEES_MANAGE_SALARY = 'employees.manage_salary';

    // Attendance
    public const ATTENDANCE_VIEW = 'attendance.view';
    public const ATTENDANCE_MANAGE = 'attendance.manage';
    public const ATTENDANCE_APPROVE = 'attendance.approve';

    // Leave
    public const LEAVE_VIEW = 'leave.view';
    public const LEAVE_MANAGE = 'leave.manage';
    public const LEAVE_APPROVE = 'leave.approve';

    // Payroll
    public const PAYROLL_VIEW = 'payroll.view';
    public const PAYROLL_PROCESS = 'payroll.process';
    public const PAYROLL_APPROVE = 'payroll.approve';
    public const PAYROLL_REVERSE = 'payroll.reverse';

    // Employee self-service (ESS)
    public const ESS_PROFILE_VIEW = 'ess.profile.view';
    public const ESS_PROFILE_UPDATE = 'ess.profile.update';
    public const ESS_LEAVE_VIEW = 'ess.leave.view';
    public const ESS_LEAVE_APPLY = 'ess.leave.apply';
    public const ESS_ATTENDANCE_VIEW = 'ess.attendance.view';
    public const ESS_PAYSLIPS_VIEW = 'ess.payslips.view';

    // Manager self-service (MSS)
    public const MSS_APPROVALS_VIEW = 'mss.approvals.view';
    public const MSS_TEAM_VIEW = 'mss.team.view';

    // Recruitment
    public const RECRUITMENT_VIEW = 'recruitment.view';
    public const RECRUITMENT_MANAGE = 'recruitment.manage';
    public const RECRUITMENT_APPROVE = 'recruitment.approve';

    // Reports
    public const REPORTS_VIEW = 'reports.view';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::COMPANY_VIEW,
            self::COMPANY_MANAGE,
            self::USERS_VIEW,
            self::USERS_MANAGE,
            self::ROLES_MANAGE,
            self::EMPLOYEES_VIEW,
            self::EMPLOYEES_CREATE,
            self::EMPLOYEES_UPDATE,
            self::EMPLOYEES_DELETE,
            self::EMPLOYEES_VIEW_SALARY,
            self::EMPLOYEES_MANAGE_SALARY,
            self::ATTENDANCE_VIEW,
            self::ATTENDANCE_MANAGE,
            self::ATTENDANCE_APPROVE,
            self::LEAVE_VIEW,
            self::LEAVE_MANAGE,
            self::LEAVE_APPROVE,
            self::PAYROLL_VIEW,
            self::PAYROLL_PROCESS,
            self::PAYROLL_APPROVE,
            self::PAYROLL_REVERSE,
            self::ESS_PROFILE_VIEW,
            self::ESS_PROFILE_UPDATE,
            self::ESS_LEAVE_VIEW,
            self::ESS_LEAVE_APPLY,
            self::ESS_ATTENDANCE_VIEW,
            self::ESS_PAYSLIPS_VIEW,
            self::MSS_APPROVALS_VIEW,
            self::MSS_TEAM_VIEW,
            self::RECRUITMENT_VIEW,
            self::RECRUITMENT_MANAGE,
            self::RECRUITMENT_APPROVE,
            self::REPORTS_VIEW,
        ];
    }

    /**
     * Default roles provisioned for every new tenant.
     *
     * @return array<string, list<string>>
     */
    public static function defaultRoles(): array
    {
        return [
            'owner' => self::all(),
            'hr_admin' => [
                self::COMPANY_VIEW,
                self::COMPANY_MANAGE,
                self::USERS_VIEW,
                self::USERS_MANAGE,
                self::EMPLOYEES_VIEW,
                self::EMPLOYEES_CREATE,
                self::EMPLOYEES_UPDATE,
                self::EMPLOYEES_DELETE,
                self::EMPLOYEES_VIEW_SALARY,
                self::EMPLOYEES_MANAGE_SALARY,
                self::ATTENDANCE_VIEW,
                self::ATTENDANCE_MANAGE,
                self::ATTENDANCE_APPROVE,
                self::LEAVE_VIEW,
                self::LEAVE_MANAGE,
                self::LEAVE_APPROVE,
                self::PAYROLL_VIEW,
                self::PAYROLL_PROCESS,
                self::ESS_PROFILE_VIEW,
                self::ESS_PROFILE_UPDATE,
                self::ESS_LEAVE_VIEW,
                self::ESS_LEAVE_APPLY,
                self::ESS_ATTENDANCE_VIEW,
                self::ESS_PAYSLIPS_VIEW,
                self::MSS_APPROVALS_VIEW,
                self::MSS_TEAM_VIEW,
                self::RECRUITMENT_VIEW,
                self::RECRUITMENT_MANAGE,
                self::RECRUITMENT_APPROVE,
                self::REPORTS_VIEW,
            ],
            'manager' => [
                self::EMPLOYEES_VIEW,
                self::ATTENDANCE_VIEW,
                self::ATTENDANCE_APPROVE,
                self::LEAVE_VIEW,
                self::LEAVE_APPROVE,
                self::ESS_PROFILE_VIEW,
                self::ESS_PROFILE_UPDATE,
                self::ESS_LEAVE_VIEW,
                self::ESS_LEAVE_APPLY,
                self::ESS_ATTENDANCE_VIEW,
                self::ESS_PAYSLIPS_VIEW,
                self::MSS_APPROVALS_VIEW,
                self::MSS_TEAM_VIEW,
                self::RECRUITMENT_VIEW,
                self::RECRUITMENT_APPROVE,
                self::REPORTS_VIEW,
            ],
            'employee' => [
                self::ESS_PROFILE_VIEW,
                self::ESS_PROFILE_UPDATE,
                self::ESS_LEAVE_VIEW,
                self::ESS_LEAVE_APPLY,
                self::ESS_ATTENDANCE_VIEW,
                self::ESS_PAYSLIPS_VIEW,
            ],
        ];
    }
}
