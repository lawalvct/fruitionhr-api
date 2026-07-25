<?php

namespace App\Modules\Performance\Services;

use App\Models\User;
use App\Modules\Performance\Models\AppraisalTemplate;
use App\Modules\Performance\Models\PerformanceCategory;
use App\Modules\Performance\Models\PerformanceKpi;
use App\Modules\Performance\Models\RatingScale;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the client-spec KPI library (build spec §8), default rating scale, and
 * the three sample templates (§7) for a tenant that opts into default
 * performance data — at onboarding or later from the performance setup screen.
 *
 * Idempotent: rerunning never duplicates rows, so it is safe to call from both
 * onboarding completion and the manual "load sample library" action.
 */
class PerformanceDefaultsProvisioner
{
    public function provision(User $owner): array
    {
        return DB::transaction(function () use ($owner): array {
            $categories = $this->provisionCategories($owner);
            $kpis = $this->provisionKpis($owner, $categories);
            $scale = $this->provisionScale($owner);
            $templates = $this->provisionTemplates($owner, $scale, $kpis);

            return [
                'categories' => count($categories),
                'kpis' => count($kpis),
                'templates' => count($templates),
            ];
        });
    }

    /** @return array<string, PerformanceCategory> */
    private function provisionCategories(User $owner): array
    {
        $categories = [];
        foreach ([
            'Output' => 'Work results: volume, quality, and goal delivery.',
            'Conduct' => 'Attendance, integrity, and adherence to company standards.',
            'Behavioural' => 'How work gets done: communication, teamwork, initiative.',
            'Growth' => 'Learning agility, improvement, and readiness for bigger roles.',
            'Compliance' => 'Adherence to policy, statutory, and process requirements.',
            'Technical' => 'Depth of role-specific and product knowledge.',
            'Leadership' => 'People management: coaching, delegation, and team results.',
        ] as $name => $description) {
            $categories[$name] = PerformanceCategory::query()->firstOrCreate(
                ['name' => $name],
                ['description' => $description, 'created_by' => $owner->id],
            );
        }

        return $categories;
    }

    /**
     * Representative KPI set per department from build spec §8. Core KPIs ship
     * with low/mid/high descriptors; HR completes the rest in-app via the KPI
     * library screen (the spec's intended split of structure vs content).
     *
     * @param  array<string, PerformanceCategory>  $categories
     * @return array<string, PerformanceKpi>
     */
    private function provisionKpis(User $owner, array $categories): array
    {
        $kpis = [];
        foreach ($this->kpiLibrary() as [$name, $category, $department, $type, $definition, $descriptors]) {
            $kpis[$name] = PerformanceKpi::query()->firstOrCreate(
                ['performance_category_id' => $categories[$category]->id, 'name' => $name],
                [
                    'department' => $department,
                    'type' => $type,
                    'description' => $definition,
                    'descriptor_low' => $descriptors[0] ?? null,
                    'descriptor_mid' => $descriptors[1] ?? null,
                    'descriptor_high' => $descriptors[2] ?? null,
                    'created_by' => $owner->id,
                ],
            );
        }

        return $kpis;
    }

    private function provisionScale(User $owner): RatingScale
    {
        $scale = RatingScale::query()->firstOrCreate(
            ['name' => 'FruitionHR 5-band scale'],
            ['description' => 'Default appraisal grading bands (percent of maximum score).', 'created_by' => $owner->id],
        );

        if ($scale->options()->count() === 0) {
            foreach ([
                ['Unsatisfactory', 0, 3999],
                ['Needs Improvement', 4000, 5999],
                ['Meets Expectations', 6000, 7499],
                ['Exceeds Expectations', 7500, 8999],
                ['Outstanding', 9000, 10000],
            ] as $index => [$label, $min, $max]) {
                $scale->options()->create([
                    'label' => $label,
                    'min_score_basis_points' => $min,
                    'max_score_basis_points' => $max,
                    'sort_order' => $index + 1,
                ]);
            }
        }

        return $scale;
    }

    /**
     * Sample templates from build spec §7 (weights per role, min passing 50%).
     *
     * @param  array<string, PerformanceKpi>  $kpis
     * @return list<AppraisalTemplate>
     */
    private function provisionTemplates(User $owner, RatingScale $scale, array $kpis): array
    {
        $definitions = [
            ['General Staff Appraisal', null, 'General Staff', [
                ['Productivity', 35], ['Quality of Work', 20], ['Attendance & Punctuality', 10],
                ['Communication', 10], ['Teamwork', 10], ['Initiative', 15],
            ]],
            ['People Manager Appraisal', null, 'People Manager', [
                ['Coaching & Development', 15], ['Delegation', 15], ['Team Goal Achievement', 30],
                ['Team Engagement/Retention', 8], ['Succession Readiness', 7],
                ['Budget Ownership', 10], ['Continuous Improvement', 15],
            ]],
            ['Sales Executive Appraisal', 'Sales', 'Sales Executive', [
                ['Revenue Attainment', 40], ['Pipeline Generation', 10], ['Conversion Rate', 10],
                ['Customer Retention', 15], ['Sales Process Adherence', 10],
                ['Negotiation Skill', 8], ['Product Knowledge', 7],
            ]],
        ];

        $templates = [];
        foreach ($definitions as [$name, $department, $role, $items]) {
            $template = AppraisalTemplate::query()->firstOrCreate(
                ['name' => $name],
                [
                    'rating_scale_id' => $scale->id,
                    'department' => $department,
                    'target_role' => $role,
                    'min_passing_basis_points' => 5000,
                    'description' => 'Sample template provided by FruitionHR — adjust weights and KPIs to fit your organisation.',
                    'created_by' => $owner->id,
                ],
            );

            if ($template->items()->count() === 0) {
                foreach ($items as [$kpiName, $weight]) {
                    $template->items()->create([
                        'performance_kpi_id' => $kpis[$kpiName]->id,
                        'weight' => $weight,
                        'is_mandatory' => true,
                    ]);
                }
            }

            $templates[] = $template;
        }

        return $templates;
    }

    /**
     * [name, category, department, type, definition, [low, mid, high]]
     *
     * @return list<array{0: string, 1: string, 2: ?string, 3: string, 4: string, 5: list<string>}>
     */
    private function kpiLibrary(): array
    {
        return [
            // Core / General — descriptors included (spec §8.x pattern)
            ['Quality of Work', 'Output', null, 'qualitative', 'Accuracy, completeness and polish of work produced relative to role expectations.', [
                'Work regularly contains errors or omissions and needs rework before it can be used.',
                'Work is usually accurate and complete, with occasional corrections needed.',
                'Work is consistently accurate, thorough, and ready to use without correction.',
            ]],
            ['Productivity', 'Output', null, 'quantitative', 'Volume of work completed within a given period relative to target.', [
                'Delivers well below the expected output for the role (< 70% of target).',
                'Delivers expected output most periods (80–110% of target).',
                'Consistently delivers well above target (> 120%).',
            ]],
            ['Attendance & Punctuality', 'Conduct', null, 'quantitative', 'Adherence to agreed working hours and attendance policy.', [
                'Frequently late or absent without approval.',
                'Generally punctual with rare unapproved absences.',
                'Consistently punctual and reliable; absences always planned and approved.',
            ]],
            ['Communication', 'Behavioural', null, 'qualitative', 'Clarity, timeliness and appropriateness of written and verbal communication.', [
                'Messages are frequently unclear, late, or require follow-up to understand.',
                'Communicates clearly and on time in most situations; occasional lapses in complex topics.',
                'Consistently clear, timely, and tailored to the audience, including in difficult or high-stakes situations.',
            ]],
            ['Teamwork', 'Behavioural', null, 'qualitative', 'Collaboration with colleagues and contribution to shared outcomes.', [
                'Works in isolation; contributions to shared goals are minimal or reluctant.',
                'Cooperates willingly and contributes fairly to team outcomes.',
                'Actively lifts team performance; sought out by colleagues for support and collaboration.',
            ]],
            ['Initiative', 'Behavioural', null, 'qualitative', 'Proactively identifying and acting on opportunities or problems without being asked.', [
                'Waits to be told; problems are left for others to notice.',
                'Acts on obvious opportunities and flags problems promptly.',
                'Consistently anticipates needs and drives improvements without prompting.',
            ]],
            ['Problem Solving', 'Behavioural', null, 'qualitative', 'Ability to diagnose issues and arrive at workable solutions.', [
                'Struggles to move past obstacles without detailed direction.',
                'Resolves routine problems independently with sound judgement.',
                'Untangles complex problems and produces workable solutions others rely on.',
            ]],
            ['Integrity & Compliance', 'Conduct', null, 'qualitative', 'Adherence to company policy, code of conduct and ethical standards.', [
                'Repeated policy breaches or conduct concerns raised.',
                'Complies with policy and acts ethically in day-to-day work.',
                'Models exemplary conduct and holds others to the standard.',
            ]],
            ['Learning Agility', 'Growth', null, 'qualitative', 'Speed and willingness to acquire new skills or adapt to new tools/processes.', [
                'Resists new tools or methods; skills are stagnant.',
                'Picks up new skills and processes at the expected pace.',
                'Rapidly masters new skills and helps others adopt them.',
            ]],
            ['Goal Achievement', 'Output', null, 'quantitative', 'Percentage of assigned goals/OKRs met for the cycle.', [
                'Fewer than 60% of assigned goals met.',
                '70–90% of assigned goals met.',
                'All goals met, several exceeded.',
            ]],

            // Sales
            ['Revenue Attainment', 'Output', 'Sales', 'quantitative', 'Actual revenue closed against assigned target for the period.', [
                'Below 80% of assigned target.', '90–110% of assigned target.', 'Above 125% of assigned target.',
            ]],
            ['Pipeline Generation', 'Output', 'Sales', 'quantitative', 'Value/volume of new qualified opportunities created.', []],
            ['Conversion Rate', 'Output', 'Sales', 'quantitative', 'Percentage of opportunities converted to closed-won deals.', []],
            ['Customer Retention', 'Output', 'Sales', 'quantitative', 'Percentage of managed accounts retained/renewed in the period.', []],
            ['Average Deal Size', 'Output', 'Sales', 'quantitative', 'Average value of closed-won deals relative to target.', []],
            ['Sales Process Adherence', 'Compliance', 'Sales', 'qualitative', 'Consistent use of CRM, pricing rules and approval workflows.', []],
            ['Product Knowledge', 'Technical', 'Sales', 'qualitative', 'Depth of knowledge of products/services being sold.', []],
            ['Negotiation Skill', 'Behavioural', 'Sales', 'qualitative', 'Effectiveness in structuring win-win deals within approved terms.', []],

            // HR
            ['Time-to-Fill', 'Output', 'HR', 'quantitative', 'Average days to fill open requisitions against target.', []],
            ['Employee Relations Handling', 'Behavioural', 'HR', 'qualitative', 'Quality and timeliness of resolving employee grievances/queries.', []],
            ['Payroll Accuracy', 'Output', 'HR', 'quantitative', 'Percentage of payroll cycles processed without correction.', []],
            ['Policy Compliance', 'Compliance', 'HR', 'qualitative', 'Adherence to statutory and internal HR policy in casework.', []],
            ['Onboarding Quality', 'Output', 'HR', 'quantitative', 'New-hire feedback score and completion rate of onboarding checklist.', []],
            ['Training Coordination', 'Output', 'HR', 'quantitative', 'Percentage of planned training programs delivered on schedule.', []],
            ['Confidentiality', 'Conduct', 'HR', 'qualitative', 'Discretion in handling sensitive employee and company data.', []],
            ['HR Analytics & Reporting', 'Technical', 'HR', 'qualitative', 'Accuracy and timeliness of HR reports/dashboards delivered to leadership.', []],

            // Finance
            ['Reporting Accuracy', 'Output', 'Finance', 'quantitative', 'Error rate in financial statements and management reports.', []],
            ['Reporting Timeliness', 'Output', 'Finance', 'quantitative', 'Percentage of reports/closes delivered by deadline.', []],
            ['Budget Variance Control', 'Output', 'Finance', 'quantitative', 'Deviation of actuals from budget for owned cost centres.', []],
            ['Reconciliation Accuracy', 'Output', 'Finance', 'quantitative', 'Percentage of accounts reconciled without unexplained variances.', []],
            ['Regulatory Compliance', 'Compliance', 'Finance', 'qualitative', 'Adherence to tax, statutory and audit requirements.', []],
            ['Cost Control', 'Output', 'Finance', 'qualitative', 'Effectiveness in identifying and executing cost-saving initiatives.', []],
            ['Financial Analysis Quality', 'Technical', 'Finance', 'qualitative', 'Depth and usefulness of analysis supporting business decisions.', []],
            ['Audit Readiness', 'Compliance', 'Finance', 'qualitative', 'State of documentation and controls at time of internal/external audit.', []],

            // IT / Engineering
            ['Code Quality', 'Output', 'IT/Engineering', 'qualitative', 'Adherence to coding standards, review feedback volume, defect rate.', []],
            ['Delivery Timeliness', 'Output', 'IT/Engineering', 'quantitative', 'Percentage of sprint/project commitments delivered on schedule.', []],
            ['System Uptime / Reliability', 'Output', 'IT/Engineering', 'quantitative', 'Uptime of owned systems against SLA.', []],
            ['Bug Resolution Time', 'Output', 'IT/Engineering', 'quantitative', 'Average time to resolve assigned defects against SLA.', []],
            ['Technical Documentation', 'Output', 'IT/Engineering', 'qualitative', 'Completeness and clarity of documentation for owned systems.', []],
            ['Security Practice', 'Compliance', 'IT/Engineering', 'qualitative', 'Adherence to secure coding and data-handling practices.', []],
            ['Innovation / Technical Initiative', 'Growth', 'IT/Engineering', 'qualitative', 'Proactive improvements proposed or implemented beyond assigned tasks.', []],
            ['Incident Response', 'Behavioural', 'IT/Engineering', 'qualitative', 'Effectiveness and calm under pressure during production incidents.', []],

            // Operations
            ['Process Efficiency', 'Output', 'Operations', 'quantitative', 'Cycle time for owned processes against target.', []],
            ['Error/Defect Rate', 'Output', 'Operations', 'quantitative', 'Rate of process errors or rework required.', []],
            ['Cost per Unit/Transaction', 'Output', 'Operations', 'quantitative', 'Operating cost per unit of output against budget.', []],
            ['Safety Compliance', 'Compliance', 'Operations', 'qualitative', 'Adherence to workplace safety rules and incident-free operation.', []],
            ['Vendor/Supplier Coordination', 'Behavioural', 'Operations', 'qualitative', 'Effectiveness in managing external vendor relationships and SLAs.', []],
            ['Inventory Accuracy', 'Output', 'Operations', 'quantitative', 'Variance between recorded and physical inventory.', []],
            ['Documentation & SOP Adherence', 'Compliance', 'Operations', 'qualitative', 'Consistency in following and updating standard operating procedures.', []],
            ['Continuous Improvement', 'Growth', 'Operations', 'qualitative', 'Number/impact of process improvements proposed or implemented.', []],

            // Customer Service
            ['Customer Satisfaction (CSAT)', 'Output', 'Customer Service', 'quantitative', 'Average CSAT score on handled interactions.', []],
            ['First Contact Resolution', 'Output', 'Customer Service', 'quantitative', 'Percentage of queries resolved without escalation or follow-up.', []],
            ['Average Handling Time', 'Output', 'Customer Service', 'quantitative', 'Average time to resolve a customer query against target.', []],
            ['Response Time / SLA Adherence', 'Output', 'Customer Service', 'quantitative', 'Percentage of interactions responded to within SLA.', []],
            ['Complaint Escalation Rate', 'Output', 'Customer Service', 'quantitative', 'Proportion of interactions escalated due to unresolved dissatisfaction.', []],
            ['Product/Service Knowledge', 'Technical', 'Customer Service', 'qualitative', 'Accuracy of information provided to customers.', []],
            ['Empathy & Tone', 'Behavioural', 'Customer Service', 'qualitative', 'Quality of interpersonal handling in difficult interactions.', []],
            ['Upsell/Cross-sell Contribution', 'Output', 'Customer Service', 'quantitative', 'Value generated from upsell/cross-sell during service interactions.', []],

            // Manufacturing & Logistics
            ['Production Output', 'Output', 'Manufacturing/Logistics', 'quantitative', 'Units produced against planned output.', []],
            ['Quality/Defect Rate', 'Output', 'Manufacturing/Logistics', 'quantitative', 'Percentage of output failing quality inspection.', []],
            ['Machine/Equipment Uptime', 'Output', 'Manufacturing/Logistics', 'quantitative', 'Uptime of owned equipment against target.', []],
            ['On-Time Delivery', 'Output', 'Manufacturing/Logistics', 'quantitative', 'Percentage of shipments/orders delivered within promised window.', []],
            ['Safety Incidents', 'Compliance', 'Manufacturing/Logistics', 'quantitative', 'Number of safety incidents/near-misses on owned line or route.', []],
            ['Waste/Loss Control', 'Output', 'Manufacturing/Logistics', 'quantitative', 'Material or inventory loss against acceptable threshold.', []],
            ['Route/Warehouse Efficiency', 'Output', 'Manufacturing/Logistics', 'quantitative', 'Cost or time efficiency of owned logistics operation against target.', []],
            ['Regulatory & Safety Compliance', 'Compliance', 'Manufacturing/Logistics', 'qualitative', 'Adherence to statutory transport/manufacturing safety requirements.', []],

            // Leadership / People Management
            ['Team Goal Achievement', 'Leadership', null, 'quantitative', 'Percentage of team-level goals/OKRs achieved for the cycle.', []],
            ['Coaching & Development', 'Leadership', null, 'qualitative', "Evidence of investing in direct reports' growth (1:1s, feedback, development plans).", []],
            ['Delegation', 'Leadership', null, 'qualitative', 'Effectiveness in distributing work appropriately across the team.', []],
            ['Team Engagement/Retention', 'Leadership', null, 'quantitative', 'Team engagement score and/or voluntary attrition rate.', []],
            ['Decision Making', 'Leadership', null, 'qualitative', "Quality and timeliness of decisions under the manager's authority.", []],
            ['Budget Ownership', 'Leadership', null, 'quantitative', 'Management of department budget against plan.', []],
            ['Change Management', 'Leadership', null, 'qualitative', 'Effectiveness in leading the team through organisational or process change.', []],
            ['Succession Readiness', 'Leadership', null, 'qualitative', 'Evidence of building bench strength / successors within the team.', []],
        ];
    }
}
