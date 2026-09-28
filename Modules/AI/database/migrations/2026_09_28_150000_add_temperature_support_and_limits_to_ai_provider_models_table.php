<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Business gap fix: two separate real problems reported directly by
     * the admin.
     *
     * 1. The admin screen showed a Temperature input for EVERY registered
     *    model, including ones whose real API rejects that parameter
     *    outright (OpenAI's o-series reasoning models - o1/o3/o4 - return
     *    an error if `temperature` is sent at all; image/video/audio/
     *    embedding/moderation endpoints have no such concept either).
     *    `temperature_supported` records that fact so the UI can hide the
     *    input instead of offering a setting that would break the request.
     *    Nullable, not defaulted to true/false: NULL means "not computed
     *    yet" (every existing row, until the admin clicks "Recalculate
     *    classification"), and AiProviderModelResource falls back to
     *    treating a NULL as supported (true) so nothing already working
     *    silently loses its temperature control the moment this column
     *    exists.
     *
     * 2. Two genuinely different numbers were being conflated under one
     *    name: the admin's OWN override of how many tokens to request per
     *    reply (the pre-existing `max_tokens` column, a REQUEST
     *    parameter this platform sends to the API) versus the MODEL's own
     *    documented ceilings (a maximum output length, and a total
     *    context window covering input+output together) - fixed facts
     *    about the model, not something this platform asks for.
     *    `max_output_tokens` and `context_window` are added as clearly
     *    separate, purely informational metadata columns; `max_tokens`
     *    is entirely untouched and keeps meaning exactly what it always
     *    has.
     *
     * Both purely additive and nullable, so every existing row and every
     * existing query keeps working completely unchanged.
     */
    public function up(): void
    {
        Schema::table('ai_provider_models', function (Blueprint $table) {
            $table->boolean('temperature_supported')->nullable()->after('temperature')
                ->comment('هل الموديل ده بيقبل باراميتر temperature فعلياً؟ NULL = لسه متحسبتش (قبل أول "إعادة احتساب التصنيف") - المفروض تتعامل معاها كـ true لحد ما تتحسب');

            $table->unsignedBigInteger('max_output_tokens')->nullable()->after('max_tokens')
                ->comment('أقصى عدد توكنز فى الرد - قيمة موثقة من المزوّد نفسه عن الموديل، مختلفة تماماً عن max_tokens (اللي هو طلب الأدمن الفعلي للـ API) - NULL لو مش معروفة، ماتتخمنش');

            $table->unsignedBigInteger('context_window')->nullable()->after('max_output_tokens')
                ->comment('إجمالي نافذة السياق (input + output) للموديل - قيمة موثقة من المزوّد، NULL لو مش معروفة، ماتتخمنش');
        });
    }

    public function down(): void
    {
        Schema::table('ai_provider_models', function (Blueprint $table) {
            $table->dropColumn(['temperature_supported', 'max_output_tokens', 'context_window']);
        });
    }
};
