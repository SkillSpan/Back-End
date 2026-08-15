<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * يضيف تفاصيل إضافية عن المؤسسة: القطاع، حجم الشركة، وبيانات العنوان
     * (الدولة، المدينة، العنوان التفصيلي، الرمز البريدي).
     */
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('industry')->nullable()->after('description');
            $table->string('company_size')->nullable()->after('industry');
            $table->string('country')->nullable()->after('company_size');
            $table->string('city')->nullable()->after('country');
            $table->string('address')->nullable()->after('city');
            $table->string('postal_code')->nullable()->after('address');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn([
                'industry',
                'company_size',
                'country',
                'city',
                'address',
                'postal_code',
            ]);
        });
    }
};
