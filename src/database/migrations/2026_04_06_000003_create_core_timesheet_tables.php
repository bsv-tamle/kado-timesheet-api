<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('positions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('work_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        // Add foreign keys for users after departments/positions are available.
        Schema::table('users', function (Blueprint $table) {
            $table->foreign('department_id')->references('id')->on('departments')->nullOnDelete();
            $table->foreign('position_id')->references('id')->on('positions')->nullOnDelete();
        });

        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('project_code', 50)->unique();
            $table->string('project_name', 255);
            $table->string('status', 20)->default('active');
            $table->boolean('billable_flag')->default(false);
            $table->text('description')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index(['billable_flag', 'status']);
        });

        Schema::create('employee_projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->timestampTz('assigned_at')->useCurrent();
            $table->timestampTz('unassigned_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['employee_id', 'is_active']);
            $table->index(['project_id', 'is_active']);
        });

        Schema::create('timesheet_periods', function (Blueprint $table) {
            $table->id();
            $table->date('period_month')->unique(); // first day of month, e.g. 2026-04-01
            $table->string('status', 20)->default('open');
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('timesheet_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('users')->cascadeOnDelete();
            $table->date('work_date');
            $table->foreignId('period_id')->nullable()->constrained('timesheet_periods')->nullOnDelete();
            $table->decimal('total_hours', 5, 2)->default(0);
            $table->string('status', 20)->default('draft');
            $table->timestampTz('submitted_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['employee_id', 'work_date']);
            $table->index('work_date');
            $table->index('period_id');
        });

        Schema::create('timesheet_entry_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('timesheet_entry_id')->constrained('timesheet_entries')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $table->foreignId('work_type_id')->nullable()->constrained('work_types')->nullOnDelete();
            $table->decimal('hours_worked', 5, 2);
            $table->text('note')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('timesheet_entry_id');
            $table->index('project_id');
            $table->index('work_type_id');
        });

        Schema::create('notification_runs', function (Blueprint $table) {
            $table->id();
            $table->date('target_date');
            $table->string('timezone', 50)->default('Asia/Tokyo');
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at')->nullable();
            $table->string('status', 30); // processing|success|failed|skipped_weekend|skipped_duplicate
            $table->integer('total_targets')->default(0);
            $table->integer('sent_count')->default(0);
            $table->integer('skipped_count')->default(0);
            $table->integer('failed_count')->default(0);
            $table->text('error_message')->nullable();
            $table->string('slack_channel', 100)->nullable();
            $table->text('message_preview')->nullable();
            $table->timestamps();
        });

        // Partial unique and strict check constraints for PostgreSQL.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("CREATE UNIQUE INDEX uq_employee_projects_active ON employee_projects(employee_id, project_id) WHERE is_active = true");
            DB::statement("CREATE UNIQUE INDEX uq_timesheet_entries_employee_work_date ON timesheet_entries(employee_id, work_date) WHERE deleted_at IS NULL");
            DB::statement("CREATE UNIQUE INDEX uq_ts_details_entry_project ON timesheet_entry_details(timesheet_entry_id, project_id) WHERE deleted_at IS NULL");
            DB::statement('CREATE UNIQUE INDEX uq_notification_runs_target_date_timezone ON notification_runs(target_date, timezone)');

            DB::statement("ALTER TABLE users ADD CONSTRAINT chk_users_role CHECK (role IN ('admin', 'employee'))");
            DB::statement("ALTER TABLE users ADD CONSTRAINT chk_users_status CHECK (status IN ('active', 'inactive', 'locked'))");
            DB::statement("ALTER TABLE projects ADD CONSTRAINT chk_projects_status CHECK (status IN ('active', 'inactive', 'archived'))");
            DB::statement("ALTER TABLE timesheet_periods ADD CONSTRAINT chk_timesheet_periods_status CHECK (status IN ('open', 'closed', 'locked'))");
            DB::statement("ALTER TABLE timesheet_entries ADD CONSTRAINT chk_timesheet_entries_status CHECK (status IN ('draft', 'submitted'))");
            DB::statement('ALTER TABLE timesheet_entries ADD CONSTRAINT chk_timesheet_entries_total_hours CHECK (total_hours >= 0 AND total_hours <= 24)');
            DB::statement('ALTER TABLE timesheet_entry_details ADD CONSTRAINT chk_timesheet_entry_details_hours CHECK (hours_worked >= 0 AND hours_worked <= 24)');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS uq_notification_runs_target_date_timezone');
            DB::statement('DROP INDEX IF EXISTS uq_ts_details_entry_project');
            DB::statement('DROP INDEX IF EXISTS uq_timesheet_entries_employee_work_date');
            DB::statement('DROP INDEX IF EXISTS uq_employee_projects_active');
        }

        Schema::dropIfExists('notification_runs');
        Schema::dropIfExists('timesheet_entry_details');
        Schema::dropIfExists('timesheet_entries');
        Schema::dropIfExists('timesheet_periods');
        Schema::dropIfExists('employee_projects');
        Schema::dropIfExists('projects');

        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['department_id']);
            $table->dropForeign(['position_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS chk_users_role');
            DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS chk_users_status');
        }

        Schema::dropIfExists('work_types');
        Schema::dropIfExists('positions');
        Schema::dropIfExists('departments');
    }
};
