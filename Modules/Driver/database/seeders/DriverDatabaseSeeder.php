<?php

namespace Modules\Driver\Database\Seeders;

use App\Models\Country;
use App\Models\ServiceCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Modules\Driver\Enums\VehicleBodyType;
use Modules\Driver\Models\DriverDocumentRequirement;
use Modules\Driver\Models\DriverDocumentType;
use Modules\Driver\Models\VehicleColor;
use Modules\Driver\Models\VehicleMake;
use Modules\Driver\Models\VehicleModel;

/**
 * The document types (and which Saudi passenger rides need), and a starting vehicle catalog the
 * admin extends. Safe to run again.
 */
class DriverDatabaseSeeder extends Seeder
{
    /** code => [applies_to, has_number, has_expiry, needs_back, ar, en, required for SA rides] */
    private const DOCUMENTS = [
        'national_id' => ['driver', true, true, true, 'الهوية / الإقامة', 'National ID / Iqama', true],
        'driving_license' => ['driver', true, true, true, 'رخصة القيادة', 'Driving licence', true],
        'vehicle_registration' => ['vehicle', true, true, true, 'استمارة المركبة', 'Vehicle registration', true],
        'vehicle_insurance' => ['vehicle', true, true, false, 'تأمين المركبة', 'Vehicle insurance', true],
        'vehicle_inspection' => ['vehicle', false, true, false, 'الفحص الدوري', 'Periodic inspection', false],
        'driving_authorization' => ['vehicle', true, true, false, 'تفويض القيادة (لو المركبة مش باسمك)', 'Driving authorisation (vehicle not in your name)', false],
        'bus_license' => ['driver', true, true, true, 'رخصة قيادة حافلة', 'Bus driving licence', false],
        'motorcycle_license' => ['driver', true, true, true, 'رخصة دراجة نارية', 'Motorcycle licence', false],
        'marine_license' => ['driver', true, true, false, 'رخصة تشغيل مركبة بحرية', 'Marine operating licence', false],
    ];

    /** make en => [ar, [[model en, ar, body type, seats, luxury], …]] */
    private const VEHICLES = [
        // O-64: motorcycles, buses, boats and yachts — before these a captain could only register a car.
        'Honda Motorcycles' => ['هوندا دراجات', [['CG 125', 'سي جي 125', 'motorcycle', 1, false], ['CB 150', 'سي بي 150', 'motorcycle', 1, false], ['PCX 160', 'بي سي إكس 160', 'motorcycle', 1, false]]],
        'Yamaha Motorcycles' => ['ياماها دراجات', [['YBR 125', 'واي بي آر 125', 'motorcycle', 1, false], ['NMAX 155', 'إن ماكس 155', 'motorcycle', 1, false]]],
        'Suzuki Motorcycles' => ['سوزوكي دراجات', [['GN 125', 'جي إن 125', 'motorcycle', 1, false], ['Burgman 200', 'بيرجمان 200', 'motorcycle', 1, false]]],
        'Mercedes-Benz Buses' => ['مرسيدس باصات', [['Sprinter', 'سبرينتر', 'minibus', 16, false], ['Travego', 'ترافيجو', 'bus', 50, false]]],
        'Hyundai Buses' => ['هيونداي باصات', [['County', 'كاونتي', 'bus', 26, false], ['Universe', 'يونيفرس', 'bus', 45, false]]],
        'King Long' => ['كينج لونج', [['XMQ6127', 'إكس إم كيو 6127', 'bus', 50, false]]],
        'Yamaha Boats' => ['ياماها مراكب', [['242 Limited', '242 ليميتد', 'boat', 10, false], ['F150 Speedboat', 'لنش F150', 'boat', 8, false]]],
        'Bayliner' => ['بايلاينر', [['Element E18', 'إليمنت E18', 'boat', 8, false]]],
        'Azimut' => ['أزيموت', [['Azimut 50', 'أزيموت 50', 'yacht', 12, true]]],
        'Sunseeker' => ['سن سيكر', [['Manhattan 55', 'مانهاتن 55', 'yacht', 12, true]]],
        'Gulf Craft' => ['جلف كرافت', [['Majesty 62', 'ماجستي 62', 'yacht', 15, true]]],
        'Toyota' => ['تويوتا', [['Camry', 'كامري', 'sedan', 4, false], ['Corolla', 'كورولا', 'sedan', 4, false], ['Yaris', 'يارس', 'sedan', 4, false], ['Land Cruiser', 'لاند كروزر', 'suv', 6, true], ['Hiace', 'هايس', 'minibus', 12, false]]],
        'Hyundai' => ['هيونداي', [['Accent', 'أكسنت', 'sedan', 4, false], ['Elantra', 'إلنترا', 'sedan', 4, false], ['Sonata', 'سوناتا', 'sedan', 4, false], ['H1', 'H1', 'van', 8, false]]],
        'Kia' => ['كيا', [['Pegas', 'بيجاس', 'sedan', 4, false], ['K5', 'K5', 'sedan', 4, false], ['Carnival', 'كرنفال', 'van', 7, false]]],
        'Nissan' => ['نيسان', [['Sunny', 'صني', 'sedan', 4, false], ['Altima', 'ألتيما', 'sedan', 4, false]]],
        'Chevrolet' => ['شيفروليه', [['Tahoe', 'تاهو', 'suv', 7, true]]],
        'GMC' => ['جي إم سي', [['Yukon', 'يوكن', 'suv', 7, true]]],
        'Lexus' => ['لكزس', [['ES', 'ES', 'sedan', 4, true]]],
        'Mercedes-Benz' => ['مرسيدس بنز', [['E-Class', 'الفئة E', 'sedan', 4, true], ['S-Class', 'الفئة S', 'sedan', 4, true]]],
    ];

    /** hex => [ar, en] */
    private const COLORS = [
        '#FFFFFF' => ['أبيض', 'White'], '#000000' => ['أسود', 'Black'], '#C0C0C0' => ['فضي', 'Silver'],
        '#808080' => ['رمادي', 'Grey'], '#1E3A8A' => ['أزرق', 'Blue'], '#B91C1C' => ['أحمر', 'Red'],
        '#D6C6A5' => ['بيج', 'Beige'], '#B8860B' => ['ذهبي', 'Gold'], '#6B4226' => ['بني', 'Brown'], '#166534' => ['أخضر', 'Green'],
    ];

    public function run(): void
    {
        $this->documents();
        $this->vehicles();
    }

    private function documents(): void
    {
        $saudi = Country::query()->where('code', 'SA')->first();
        $rides = ServiceCategory::query()->where('module_name', config('rides.service_module_name', 'passenger_ride'))->first();
        $sort = 0;

        foreach (self::DOCUMENTS as $code => [$appliesTo, $hasNumber, $hasExpiry, $needsBack, $ar, $en, $requiredForRides]) {
            $type = DriverDocumentType::withTrashed()->firstOrCreate(['code' => $code], [
                'applies_to' => $appliesTo,
                'has_number' => $hasNumber,
                'has_expiry' => $hasExpiry,
                'needs_back_side' => $needsBack,
                'status' => true,
                'sort_order' => ++$sort,
            ]);
            $this->translate($type, ['ar' => ['name' => $ar], 'en' => ['name' => $en]]);

            if ($requiredForRides && $saudi !== null && $rides !== null) {
                DriverDocumentRequirement::query()->firstOrCreate(
                    ['document_type_id' => $type->id, 'country_id' => $saudi->id, 'service_category_id' => $rides->id],
                    ['is_required' => true],
                );
            }
        }
    }

    private function vehicles(): void
    {
        $sort = 0;
        foreach (self::VEHICLES as $makeEn => [$makeAr, $models]) {
            $make = VehicleMake::withTrashed()->whereHas('translations', fn ($q) => $q->where('locale', 'en')->where('name', $makeEn))->first()
                ?? VehicleMake::query()->create(['status' => true, 'sort_order' => ++$sort]);
            $this->translate($make, ['ar' => ['name' => $makeAr], 'en' => ['name' => $makeEn]]);

            foreach ($models as [$en, $ar, $body, $seats, $luxury]) {
                $exists = $make->models()->withTrashed()->whereHas('translations', fn ($q) => $q->where('locale', 'en')->where('name', $en))->exists();
                if ($exists) {
                    continue;
                }
                $model = VehicleModel::query()->create([
                    'make_id' => $make->id,
                    'body_type' => VehicleBodyType::from($body),
                    'seats' => $seats,
                    'is_luxury' => $luxury,
                    'status' => true,
                ]);
                $this->translate($model, ['ar' => ['name' => $ar], 'en' => ['name' => $en]]);
            }
        }

        foreach (self::COLORS as $hex => [$ar, $en]) {
            $color = VehicleColor::withTrashed()->firstOrCreate(['hex' => $hex], ['status' => true]);
            $this->translate($color, ['ar' => ['name' => $ar], 'en' => ['name' => $en]]);
        }
    }

    /**
     * @param  array<string, array<string, string>>  $byLocale
     */
    private function translate(Model $model, array $byLocale): void
    {
        foreach ($byLocale as $locale => $fields) {
            $model->translations()->updateOrCreate(['locale' => $locale], $fields);
        }
    }
}
