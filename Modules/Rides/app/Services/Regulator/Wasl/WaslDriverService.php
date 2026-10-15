<?php

namespace Modules\Rides\Services\Regulator\Wasl;

use App\Services\Notifications\NotificationCenter;
use Carbon\Carbon;
use Modules\Driver\Models\Driver;
use Modules\Driver\Models\Vehicle;
use Modules\Rides\Enums\RegulatorStatus;
use Modules\Rides\Exceptions\RidesException;
use Modules\Rides\Models\DriverRegulatorStatus;
use Modules\Rides\Models\RideRegulatorCity;
use Modules\Rides\Services\Drivers\DriverEligibility;
use Modules\Rides\Support\Hijri;
use Throwable;

/**
 * A driver and their vehicle at Wasl (docs/rides-plan.md §11.3–11.4), as Jawad does it in
 * production: register (`/dispatching/v2/drivers`), then check the eligibility every day
 * (`/dispatching/v2/drivers/eligibility`). The answer is kept in `driver_regulator_status`, which
 * DriverEligibility reads before a driver may go online. Every driver of a country with a regulator
 * goes through here — no exception by nationality or by how they were registered (§11.4).
 */
class WaslDriverService
{
    public function __construct(
        private readonly WaslClient $client,
        private readonly DriverEligibility $eligibility,
        private readonly NotificationCenter $notifications,
    ) {}

    public function statusOf(Driver $driver): DriverRegulatorStatus
    {
        return DriverRegulatorStatus::query()->firstOrCreate(
            ['driver_id' => $driver->id, 'regulator' => RideRegulatorCity::WASL],
            ['country_id' => $driver->country_id, 'driver_status' => RegulatorStatus::Pending->value, 'vehicle_status' => RegulatorStatus::Pending->value],
        );
    }

    public function register(Driver $driver): DriverRegulatorStatus
    {
        $status = $this->statusOf($driver);
        if ($status->blocked_until !== null && $status->blocked_until->isFuture()) {
            throw new RidesException('regulator_blocked', 409, ['date' => $status->blocked_until->toDateString()], ['blocked_until' => $status->blocked_until->toDateString()]);
        }
        if (! $this->client->configured()) {
            throw new RidesException('regulator_not_configured', 503);
        }

        $vehicle = $this->vehicleOf($driver);
        $missing = $this->missing($driver, $vehicle);
        if ($missing !== []) {
            throw new RidesException('regulator_missing_data', 422, ['fields' => implode(', ', $missing)], ['fields' => $missing]);
        }
        $letters = $vehicle->plateLetters();

        $result = $this->client->post('/dispatching/v2/drivers', [
            'driver' => [
                'emailAddress' => '',
                'identityNumber' => $driver->national_id,
                'mobileNumber' => $driver->phone,
                'dateOfBirthHijri' => Hijri::fromGregorian($driver->date_of_birth),
                'dateOfBirthGregorian' => $driver->date_of_birth->toDateString(),
            ],
            'vehicle' => [
                'plateLetterLeft' => $letters[0] ?? '',
                'plateLetterMiddle' => $letters[1] ?? '',
                'plateLetterRight' => $letters[2] ?? '',
                'plateNumber' => $vehicle->plate_number,
                'plateType' => $vehicle->plate_type,
                'sequenceNumber' => $vehicle->sequence_number,
            ],
        ], 'registration', 'driver', $driver->id);

        $before = $this->isValid($status);
        $status->forceFill(['registration_attempts' => $status->registration_attempts + 1, 'last_registered_at' => now(), 'vehicle_id' => $vehicle->id, 'last_checked_at' => now()]);

        $answer = $result['body']['result'] ?? null;
        if (WaslClient::succeeded($result) && is_array($answer)) {
            $status->forceFill([
                'driver_status' => self::status($answer['eligibility'] ?? null),
                'driver_expires_at' => self::date($answer['eligibilityExpiryDate'] ?? null),
                'vehicle_status' => self::status($answer['vehicleEligibility'] ?? ($answer['eligibility'] ?? null)),
                'vehicle_expires_at' => self::date($answer['vehicleLicenseExpiryDate'] ?? null),
                'vehicle_expires_at_hijri' => self::raw($answer['vehicleLicenseExpiryDate'] ?? null),
                'rejection_codes' => self::codes($answer['rejectionReasons'] ?? null),
                'vehicle_rejection_codes' => self::codes($answer['vehicleRejectionReasons'] ?? null),
            ])->save();

            // Wasl's own spelling of the name and the gender, as Jawad keeps them.
            $driver->forceFill(array_filter([
                'name_ar' => $answer['driverFullNameArabic'] ?? null,
                'name_en' => $answer['driverFullNameEnglish'] ?? null,
                'gender' => match ($answer['driverGender'] ?? null) {
                    'MALE' => 'male', 'FEMALE' => 'female', default => null,
                },
            ]))->save();

            if ($status->driver_status === RegulatorStatus::Pending->value) {
                $this->notice($driver, 'absher_approve');
            }
        } else {
            $status->forceFill(['rejection_codes' => array_filter([WaslClient::resultCode($result) ?? 'UNKNOWN']), 'vehicle_rejection_codes' => []])->save();
        }

        $this->tell($driver, $status, $before);

        return $status;
    }

    /** The daily check (and on request): eligibility, the criminal record, the vehicle. */
    public function check(Driver $driver): DriverRegulatorStatus
    {
        $status = $this->statusOf($driver);
        if (! $this->client->configured() || blank($driver->national_id)) {
            return $status;
        }

        $result = $this->client->post('/dispatching/v2/drivers/eligibility', ['driverIds' => [['id' => $driver->national_id]]], 'eligibility', 'driver', $driver->id);
        $answer = $result['body'][0] ?? null;
        if ($result['status'] !== 200 || ! is_array($answer)) {
            return $status;
        }

        $before = $this->isValid($status);
        $vehicle = $answer['vehicles'][0] ?? [];
        $criminal = $answer['criminalRecordStatus'] ?? $status->criminal_record_status;
        $status->forceFill([
            'driver_status' => isset($answer['driverEligibility']) ? self::status($answer['driverEligibility']) : $status->driver_status,
            'driver_expires_at' => self::date($answer['eligibilityExpiryDate'] ?? null) ?? $status->driver_expires_at,
            'vehicle_status' => isset($vehicle['vehicleEligibility']) ? self::status($vehicle['vehicleEligibility']) : $status->vehicle_status,
            'vehicle_expires_at' => self::date($vehicle['vehicleLicenseExpiryDate'] ?? null) ?? $status->vehicle_expires_at,
            'vehicle_expires_at_hijri' => self::raw($vehicle['vehicleLicenseExpiryDate'] ?? null) ?? $status->vehicle_expires_at_hijri,
            'criminal_record_status' => $criminal,
            'rejection_codes' => self::codes($answer['driverRejectionReason'] ?? null),
            'vehicle_rejection_codes' => self::codes($vehicle['vehicleRejectionReason'] ?? null),
            'last_checked_at' => now(),
        ])->save();

        $this->criminalRecord($driver, $status, (string) $criminal);
        $this->tell($driver, $status, $before);

        return $status->refresh();
    }

    /** Jawad's handling of the Absher criminal-record answers. */
    private function criminalRecord(Driver $driver, DriverRegulatorStatus $status, string $criminal): void
    {
        switch ($criminal) {
            case 'PENDING':
            case 'PENDING_DRIVER_APPROVAL':
                $this->notice($driver, 'absher_approve');
                break;
            case 'DRIVER_REJECTED':
            case 'REQUEST_EXPIRED':
                $this->notice($driver, $criminal === 'DRIVER_REJECTED' ? 'criminal_rejected' : 'criminal_expired');
                try {
                    $this->register($driver);
                } catch (Throwable) {
                    // Missing data or no keys: the daily check tries again tomorrow.
                }
                break;
            case 'DONE_RESULT_NOT_OK':
                if ($status->blocked_until === null || $status->blocked_until->isPast()) {
                    $status->forceFill(['blocked_until' => now()->addMonths((int) config('rides.wasl.criminal_block_months', 11))->toDateString()])->save();
                    $this->notice($driver, 'criminal_blocked', ['date' => $status->blocked_until->toDateString()]);
                }
                break;
        }
    }

    /** Tell the driver what changed: approved, or why not (D-9: the reason in their language). */
    private function tell(Driver $driver, DriverRegulatorStatus $status, bool $wasValid): void
    {
        $valid = $this->isValid($status);
        if ($valid && ! $wasValid) {
            $this->notifications->send($driver, 'wasl.valid', 'ride_wasl_valid_title', 'ride_wasl_valid_body', [], ['type' => 'wasl']);

            return;
        }
        if (! $valid && ($code = [...($status->rejection_codes ?? []), ...($status->vehicle_rejection_codes ?? [])][0] ?? null) !== null) {
            $this->notifications->send($driver, 'wasl.rejected', 'ride_wasl_rejected_title', 'ride_wasl_rejected_body', [
                'reason' => self::reason((string) $code, $driver->locale),
            ], ['type' => 'wasl', 'code' => $code]);
        }
    }

    /** @param array<string, scalar> $variables */
    private function notice(Driver $driver, string $key, array $variables = []): void
    {
        $this->notifications->send($driver, 'wasl.notice', 'ride_wasl_notice_title', 'ride_wasl_notice_body', [
            'message' => __('wasl.messages.'.$key, $variables, $driver->locale ?: 'ar'),
        ], ['type' => 'wasl']);
    }

    public static function reason(string $code, ?string $locale = null): string
    {
        $key = 'wasl.codes.'.$code;
        $text = __($key, [], $locale);

        return $text === $key ? __('wasl.messages.unknown', ['code' => $code], $locale) : $text;
    }

    private function isValid(DriverRegulatorStatus $status): bool
    {
        return $status->driver_status === RegulatorStatus::Valid->value && $status->vehicle_status === RegulatorStatus::Valid->value;
    }

    private function vehicleOf(Driver $driver): ?Vehicle
    {
        return $this->eligibility->activeVehicle($driver)
            ?? $driver->vehicles()->whereNotIn('status', ['rejected'])->latest('id')->first();
    }

    /** @return list<string> what Wasl needs and the account doesn't have yet */
    private function missing(Driver $driver, ?Vehicle $vehicle): array
    {
        return array_keys(array_filter([
            'national_id' => blank($driver->national_id),
            'date_of_birth' => $driver->date_of_birth === null,
            'phone' => blank($driver->phone),
            'vehicle' => $vehicle === null,
            'plate_letters' => $vehicle !== null && count($vehicle->plateLetters()) !== 3,
            'plate_number' => $vehicle !== null && blank($vehicle->plate_number),
            'plate_type' => $vehicle !== null && blank($vehicle->plate_type),
            'sequence_number' => $vehicle !== null && blank($vehicle->sequence_number),
        ]));
    }

    /** Wasl's word as it comes, lower-cased (valid / invalid / pending / rejected / …); only "valid" lets a driver work. */
    private static function status(?string $value): string
    {
        $word = strtolower(trim((string) $value));

        return $word === '' ? RegulatorStatus::Pending->value : mb_substr($word, 0, 24);
    }

    private static function raw(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? mb_substr($value, 0, 16) : null;
    }

    /** A Gregorian date, or a Hijri one (Wasl sends the vehicle licence expiry in Hijri). */
    private static function date(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        $year = preg_match('#^(\d{4})#', $value, $m) || preg_match('#^\d{1,2}[/-]\d{1,2}[/-](\d{4})#', $value, $m) ? (int) $m[1] : null;
        if ($year !== null && $year < 1600) {
            return Hijri::toGregorian($value);
        }
        try {
            return Carbon::parse($value)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    /** @return list<string> */
    private static function codes(mixed $value): array
    {
        return array_values(array_filter(array_map('strval', is_array($value) ? $value : [$value]), fn (string $c) => $c !== ''));
    }
}
