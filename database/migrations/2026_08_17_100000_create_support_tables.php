<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_tickets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // Human-quotable on a phone call: TKT-000123.
            $table->string('reference', 20)->unique();

            $table->string('subject');
            $table->string('category', 30)->default('other');
            $table->string('priority', 20)->default('normal');
            // open | in_progress | waiting_on_customer | resolved | closed
            $table->string('status', 30)->default('open');

            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();

            // Drives "who is waiting on whom" without counting messages.
            $table->timestamp('last_customer_reply_at')->nullable();
            $table->timestamp('last_agent_reply_at')->nullable();
            $table->timestamp('first_responded_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'status'], 'support_tickets_tenant_status_idx');
            // The platform queue: open work, oldest first.
            $table->index(['status', 'priority', 'created_at'], 'support_tickets_queue_idx');
        });

        Schema::create('support_ticket_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('support_ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // Who is speaking, kept explicit rather than inferred from the
            // author's role — an admin's role could change later, and the
            // thread must still read correctly.
            $table->string('author_type', 20); // customer | agent

            $table->text('body');

            // Internal notes are agent-only and must never reach the customer.
            $table->boolean('is_internal')->default(false);

            $table->timestamps();

            $table->index(['support_ticket_id', 'created_at'], 'support_messages_thread_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_ticket_messages');
        Schema::dropIfExists('support_tickets');
    }
};
