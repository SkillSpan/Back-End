<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const BEFORE = ['draft', 'pending_review', 'open', 'closed', 'in_progress', 'completed', 'archived'];

    private const AFTER = [
        'draft', 'submitted', 'changes_requested', 'approved', 'rejected', 'open',
        'selection', 'active', 'under_review', 'completed', 'cancelled', 'archived',
        'closed',
    ];

    public function up(): void
    {
        $this->renameStatus('pending_review', 'submitted');
        $this->renameStatus('in_progress', 'active');

        Schema::table('projects', function (Blueprint $table) {
            $table->enum('status', self::AFTER)->default('draft')->change();
        });
    }

    public function down(): void
    {
        $this->renameStatus('submitted', 'pending_review');
        $this->renameStatus('active', 'in_progress');
        $this->renameStatus('changes_requested', 'draft');
        $this->renameStatus('approved', 'draft');
        $this->renameStatus('rejected', 'draft');
        $this->renameStatus('selection', 'open');
        $this->renameStatus('under_review', 'in_progress');
        $this->renameStatus('cancelled', 'archived');

        Schema::table('projects', function (Blueprint $table) {
            $table->enum('status', self::BEFORE)->default('draft')->change();
        });
    }

    private function renameStatus(string $from, string $to): void
    {
        DB::table('projects')->where('status', $from)->update(['status' => $to]);
    }
};
