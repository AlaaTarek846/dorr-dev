<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Starting data for the provider platform (A-01), all editable by the admin afterwards: the moving trucks, and the
 * papers and photos asked from establishments, vehicles and drivers. The photo showing the vehicle with its plate is
 * the one the owner asked for first and is always required; the angles come with it (owner's call, 2026-11-05).
 */
return new class extends Migration
{
    private const VEHICLE_TYPES = [
        ['quarter_truck', 'ربع نقل', 'Quarter truck', 'لنقل القطع القليلة والأجهزة.', 'For a few pieces and appliances.'],
        ['half_truck', 'نصف نقل', 'Half truck', 'لنقل غرفة أو شقة صغيرة.', 'For a room or a small flat.'],
        ['jumbo_truck', 'شاحنة جامبو', 'Jumbo truck', 'لنقل الشقق الكبيرة والمنازل.', 'For large flats and houses.'],
        ['crane_truck', 'شاحنة بونش', 'Crane truck', 'لرفع القطع إلى الطوابق العالية.', 'For lifting pieces to high floors.'],
    ];

    /** code, subject, kind, has_number, has_expiry, needs_back, alert_days, establishment_type, ar, en, ar instructions, en instructions */
    private const DOCUMENT_TYPES = [
        ['vehicle_plate_photo', 'vehicle', 'photo', false, false, false, null, null, 'صورة المركبة تظهر فيها اللوحة', 'Vehicle photo showing the plate', 'التقط صورة أمامية واضحة للمركبة تظهر فيها اللوحة كاملة.', 'Take a clear front photo of the vehicle with the whole plate visible.'],
        ['vehicle_side_photo', 'vehicle', 'photo', false, false, false, null, null, 'صورة جانبية للمركبة', 'Side photo of the vehicle', 'صوّر المركبة من الجانب بالكامل.', 'Photograph the whole side of the vehicle.'],
        ['vehicle_rear_photo', 'vehicle', 'photo', false, false, false, null, null, 'صورة خلفية للمركبة', 'Rear photo of the vehicle', 'صوّر المركبة من الخلف.', 'Photograph the vehicle from behind.'],
        ['vehicle_cargo_photo', 'vehicle', 'photo', false, false, false, null, null, 'صورة صندوق الحمولة', 'Cargo area photo', 'صوّر صندوق الحمولة من الداخل.', 'Photograph the inside of the cargo area.'],
        ['vehicle_registration', 'vehicle', 'document', true, true, true, 30, null, 'رخصة المركبة', 'Vehicle registration', 'صوّر وجهي رخصة المركبة بوضوح.', 'Photograph both sides of the vehicle registration clearly.'],
        ['driving_licence', 'driver', 'document', true, true, true, 30, null, 'رخصة القيادة', 'Driving licence', 'صوّر وجهي رخصة القيادة بوضوح.', 'Photograph both sides of the driving licence clearly.'],
        ['national_id', 'establishment', 'document', true, false, true, null, 'individual', 'الهوية الشخصية', 'National ID', 'صوّر وجهي الهوية بوضوح.', 'Photograph both sides of the ID clearly.'],
        ['commercial_register', 'establishment', 'document', true, true, false, 30, 'company', 'السجل التجاري', 'Commercial register', 'صوّر السجل التجاري ساري المفعول.', 'Photograph the valid commercial register.'],
    ];

    public function up(): void
    {
        $now = now();
        $countryIds = DB::table('countries')->pluck('id');
        $movingId = DB::table('service_categories')->where('module_name', 'moving')->value('id');

        foreach (self::VEHICLE_TYPES as $i => [$code, $ar, $en, $arDesc, $enDesc]) {
            if (DB::table('provider_vehicle_types')->where('code', $code)->exists()) {
                continue;
            }
            $id = DB::table('provider_vehicle_types')->insertGetId(['code' => $code, 'status' => true, 'sort_order' => $i + 1, 'created_at' => $now, 'updated_at' => $now]);
            DB::table('provider_vehicle_type_translations')->insert([
                ['provider_vehicle_type_id' => $id, 'locale' => 'ar', 'name' => $ar, 'description' => $arDesc],
                ['provider_vehicle_type_id' => $id, 'locale' => 'en', 'name' => $en, 'description' => $enDesc],
            ]);
            DB::table('provider_vehicle_type_countries')->insert($countryIds->map(fn ($c) => ['vehicle_type_id' => $id, 'country_id' => $c])->all());
            if ($movingId) {
                DB::table('provider_vehicle_type_services')->insert(['vehicle_type_id' => $id, 'service_category_id' => $movingId]);
            }
        }

        foreach (self::DOCUMENT_TYPES as $i => [$code, $subject, $kind, $number, $expiry, $back, $alert, $estType, $ar, $en, $arHow, $enHow]) {
            if (DB::table('provider_document_types')->where('code', $code)->exists()) {
                continue;
            }
            $id = DB::table('provider_document_types')->insertGetId([
                'code' => $code, 'subject' => $subject, 'kind' => $kind, 'has_number' => $number, 'has_expiry' => $expiry,
                'needs_back_side' => $back, 'expiry_alert_days' => $alert, 'status' => true, 'sort_order' => $i + 1,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('provider_document_type_translations')->insert([
                ['provider_document_type_id' => $id, 'locale' => 'ar', 'name' => $ar, 'instructions' => $arHow],
                ['provider_document_type_id' => $id, 'locale' => 'en', 'name' => $en, 'instructions' => $enHow],
            ]);
            // Vehicle and driver papers are asked for moving; the establishment's own papers for every service.
            $serviceId = $subject === 'establishment' ? null : $movingId;
            if ($subject !== 'establishment' && ! $movingId) {
                continue;
            }
            DB::table('provider_document_requirements')->insert($countryIds->map(fn ($c) => [
                'document_type_id' => $id, 'country_id' => $c, 'service_category_id' => $serviceId, 'vehicle_type_id' => null,
                'establishment_type' => $estType, 'is_required' => true, 'created_at' => $now, 'updated_at' => $now,
            ])->all());
        }
    }

    public function down(): void
    {
        $docCodes = array_column(self::DOCUMENT_TYPES, 0);
        $docIds = DB::table('provider_document_types')->whereIn('code', $docCodes)->pluck('id');
        if (! DB::table('provider_documents')->whereIn('document_type_id', $docIds)->exists()) {
            DB::table('provider_document_types')->whereIn('id', $docIds)->delete();
        }
        $typeIds = DB::table('provider_vehicle_types')->whereIn('code', array_column(self::VEHICLE_TYPES, 0))->pluck('id');
        if (! DB::table('provider_vehicles')->whereIn('vehicle_type_id', $typeIds)->exists()) {
            DB::table('provider_vehicle_types')->whereIn('id', $typeIds)->delete();
        }
    }
};
