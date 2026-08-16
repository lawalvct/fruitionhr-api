<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Platform-owned catalogue: FruitionHR's own price list, not tenant data.
        Schema::create('plans', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('description')->nullable();

            // Per employee, per billing interval. Integer kobo — never float.
            $table->bigInteger('price_per_employee')->default(0);
            $table->string('billing_interval', 20)->default('monthly'); // monthly | yearly

            // Seats are billed at no fewer than min_employees, so a two-person
            // company still covers the floor price.
            $table->unsignedInteger('min_employees')->default(1);
            $table->unsignedInteger('max_employees')->nullable(); // null = unlimited

            $table->unsignedInteger('trial_days')->default(14);
            $table->json('features')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'sort_order'], 'plans_active_order_idx');
        });

        Schema::create('subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained();

            // trialing | active | past_due | cancelled | expired
            $table->string('status', 20)->default('trialing');

            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            // When a cancelled subscription actually stops working.
            $table->timestamp('ends_at')->nullable();

            // Snapshot of what the last renewal was priced on, so history stays
            // reproducible even after the plan price or headcount changes.
            $table->unsignedInteger('employee_count')->default(0);
            $table->bigInteger('amount')->default(0); // kobo

            $table->timestamps();

            $table->index(['tenant_id', 'status'], 'subscriptions_tenant_status_idx');
            $table->index(['status', 'current_period_end'], 'subscriptions_renewal_idx');
        });

        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();

            $table->string('gateway', 20);          // paystack | nomba
            // The real idempotency guard — a webhook and a client verify racing
            // each other cannot create two payments for one charge.
            $table->string('reference')->unique();

            $table->bigInteger('amount');           // kobo
            $table->string('currency', 3)->default('NGN');
            $table->string('status', 20)->default('pending'); // pending|successful|failed|abandoned

            $table->unsignedInteger('employee_count')->default(0);
            $table->json('gateway_response')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status'], 'payments_tenant_status_idx');
            $table->index(['status', 'created_at'], 'payments_pending_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('plans');
    }
};
