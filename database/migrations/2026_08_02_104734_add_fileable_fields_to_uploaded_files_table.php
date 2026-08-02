<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('uploaded_files', function (Blueprint $table) {
            // 1. نجعل user_id اختياري (nullable) لأن الملف ممكن يتبع منظمة وليس مستخدم
            $table->foreignId('user_id')->nullable()->change();

            // 2. نضيف عمودين للعلاقة المتعددة الأشكال (fileable_type و fileable_id)
            $table->nullableMorphs('fileable');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('uploaded_files', function (Blueprint $table) {
            // نرجع user_id للزامية (كما كانت)
            $table->foreignId('user_id')->nullable(false)->change();

            // نحذف العمودين
            $table->dropMorphs('fileable');
        });
    }
};