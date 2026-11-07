<?php

namespace Modules\Freelance\Services\Team;

/**
 * FS-104: what a member of the client's team reads of an answer meant for the owner. Without `money` every amount (`*_minor`) is blank
 * and the money lines are empty; the owner's wallet balance is never shown; every `can` button the role may not press is off.
 * Works on decoded JSON objects (so an empty map stays `{}`) as well as on PHP arrays.
 */
final class ClientTeamView
{
    /** The ability a member needs to be told about each "next step"; steps not listed belong to the owner. */
    private const NEXT_NEEDS = [
        'fund_milestone' => ClientTeamAbilities::FUND,
        'review_delivery' => ClientTeamAbilities::REVIEW,
        'review_offers' => ClientTeamAbilities::OFFERS,
    ];

    /** @param  list<string>  $abilities */
    public static function mask(mixed $value, bool $money, array $abilities, ?string $key = null): mixed
    {
        if (is_object($value)) {
            if ($key === 'can') {
                foreach (get_object_vars($value) as $flag => $on) {
                    $value->{$flag} = self::allowed($flag, $on, $abilities);
                }

                return $value;
            }
            foreach (get_object_vars($value) as $k => $v) {
                $value->{$k} = self::child((string) $k, $v, $money, $abilities);
            }
            // A part's new price is told as `payload.from` / `payload.to` (amounts without the `_minor` name).
            if (! $money && ($value->type ?? null) === 'milestone_price' && isset($value->payload) && is_object($value->payload)) {
                $value->payload->from = null;
                $value->payload->to = null;
            }

            return $value;
        }
        if (! is_array($value)) {
            return self::scalar($value, $money, $key);
        }
        if ($key === 'can' && ! array_is_list($value)) {
            foreach ($value as $flag => $on) {
                $value[$flag] = self::allowed((string) $flag, $on, $abilities);
            }

            return $value;
        }
        if (! $money && $key === 'lines') {
            return [];
        }
        foreach ($value as $k => $v) {
            $value[$k] = is_string($k) ? self::child($k, $v, $money, $abilities) : self::mask($v, $money, $abilities, $key);
        }

        return $value;
    }

    /** @param  list<string>  $abilities */
    private static function child(string $k, mixed $v, bool $money, array $abilities): mixed
    {
        if (str_starts_with($k, 'can_') && is_bool($v)) {
            return false;
        }
        if (! $money && $k === 'lines' && (is_array($v) || is_object($v))) {
            return [];
        }
        // "What is due from you next": a member is only told about a step his role can take (the rest is the owner's).
        if ($k === 'next_action' && is_string($v)) {
            $need = self::NEXT_NEEDS[$v] ?? null;

            return $need !== null && in_array($need, $abilities, true) ? $v : null;
        }

        return self::mask($v, $money, $abilities, $k);
    }

    private static function scalar(mixed $value, bool $money, ?string $key): mixed
    {
        if ($key === 'wallet_available_minor' || $key === 'wallet_minor' || (! $money && $key !== null && str_ends_with($key, '_minor'))) {
            return null;
        }

        return $value;
    }

    /** @param  list<string>  $abilities */
    private static function allowed(string $flag, mixed $on, array $abilities): bool
    {
        $need = ClientTeamAbilities::CAN[$flag] ?? null;

        return $on === true && $need !== null && in_array($need, $abilities, true);
    }
}
