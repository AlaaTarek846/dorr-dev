<?php

namespace Modules\AI\Safety;

/**
 * What the RiskClassifier decided about one request, before any answer is written (spec 351–360).
 *
 *  - `domain`: religion · law · medicine (HIGH STAKES) · engineering (design that touches the
 *    structure, wiring or plumbing) · code — or null for everything else.
 *  - `specific`: the "(b)" case of each rule — a personal case / contract / dispute / symptoms /
 *    treatment / own religious situation; a design touching a structural, electrical or plumbing
 *    element; code for production, money, security or real user data.
 */
final class RiskAssessment
{
    public const RELIGION = 'religion';

    public const LAW = 'law';

    public const MEDICINE = 'medicine';

    public const ENGINEERING = 'engineering';

    public const CODE = 'code';

    public const DOMAINS = [self::RELIGION, self::LAW, self::MEDICINE, self::ENGINEERING, self::CODE];

    /** Religion, law and medicine (spec 350–355). */
    public const HIGH_STAKES = [self::RELIGION, self::LAW, self::MEDICINE];

    public function __construct(
        public readonly ?string $domain,
        public readonly bool $specific,
        /** model · rules — who decided (the rules decide when the model can't). */
        public readonly string $source = 'rules',
    ) {}

    public static function none(string $source = 'rules'): self
    {
        return new self(null, false, $source);
    }

    public function isHighStakes(): bool
    {
        return in_array($this->domain, self::HIGH_STAKES, true);
    }

    public function needsReferral(): bool
    {
        return $this->domain !== null && $this->specific;
    }
}
