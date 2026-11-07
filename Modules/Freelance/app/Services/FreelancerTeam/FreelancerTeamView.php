<?php

namespace Modules\Freelance\Services\FreelancerTeam;

/**
 * FS-206a: what somebody of a freelancers' team reads of an answer meant for the signing Owner. Without `money` every amount (`*_minor`)
 * is blank and the money lines are empty; without `net` (everybody but an Owner) the commission, the net and the money lines (they carry
 * both) are blank; every `can` button his place may not press is off. Works on decoded JSON objects (an empty map stays `{}`) and arrays.
 */
final class FreelancerTeamView
{
    /** What only an Owner reads even when the amounts are shown. */
    private const NET_KEYS = ['net_minor', 'commission_minor', 'commission', 'fees'];

    /** FS-206b: the parts (uuids) the viewer is put on - he may start those though his place may not start a part. @var list<string> */
    private static array $own = [];

    /**
     * @param  list<string>  $abilities
     * @param  list<string>  $ownParts
     */
    public static function maskFor(mixed $value, array $abilities, array $ownParts = []): mixed
    {
        self::$own = $ownParts;
        try {
            return self::mask($value, $abilities);
        } finally {
            self::$own = [];
        }
    }

    /** @param  list<string>  $abilities */
    public static function mask(mixed $value, array $abilities, ?string $key = null): mixed
    {
        $money = in_array(TeamProjects::MONEY, $abilities, true);
        $net = in_array(TeamProjects::NET, $abilities, true);

        if (is_object($value)) {
            foreach (get_object_vars($value) as $k => $v) {
                $value->{$k} = $k === 'can' && (is_object($v) || (is_array($v) && ! array_is_list($v)))
                    ? self::buttons($v, $abilities, isset($value->uuid) && in_array($value->uuid, self::$own, true))
                    : self::child((string) $k, $v, $money, $net, $abilities);
            }

            return $value;
        }
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $value[$k] = is_string($k)
                    ? ($k === 'can' && (is_object($v) || (is_array($v) && ! array_is_list($v))) ? self::buttons($v, $abilities, isset($value['uuid']) && in_array($value['uuid'], self::$own, true)) : self::child($k, $v, $money, $net, $abilities))
                    : self::mask($v, $abilities, $key);
            }

            return $value;
        }

        return $value;
    }

    /** @param  list<string>  $abilities */
    private static function child(string $k, mixed $v, bool $money, bool $net, array $abilities): mixed
    {
        if (str_starts_with($k, 'can_') && is_bool($v)) {
            return false;
        }
        if (! $net && in_array($k, self::NET_KEYS, true)) {
            return null;
        }
        if ((! $money || ! $net) && $k === 'lines' && (is_array($v) || is_object($v))) {
            return [];
        }
        if (! $money && str_ends_with($k, '_minor') && (is_int($v) || is_float($v) || is_string($v))) {
            return null;
        }

        return self::mask($v, $abilities, $k);
    }

    /**
     * @param  object|array<string, mixed>  $can
     * @param  list<string>  $abilities
     */
    private static function buttons(object|array $can, array $abilities, bool $ownPart = false): object|array
    {
        foreach ((is_object($can) ? get_object_vars($can) : $can) as $flag => $on) {
            $need = TeamProjects::CAN[$flag] ?? null;
            $allowed = $on === true && $need !== null && (in_array($need, $abilities, true) || ($ownPart && $flag === 'start'));
            is_object($can) ? $can->{$flag} = $allowed : $can[$flag] = $allowed;
        }

        return $can;
    }
}
