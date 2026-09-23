# User — Changelog

## [Unreleased]

### Added
- Mobile-only auth APIs: `/api/mobile/v1/*` (`user_api` guard, `Routes/mobile.php`)
- Combined login/register by phone (request OTP creates the user if missing)
- Country-aware phone validation via `ValidatesCountryPhone` (uses `countries.phone_starts_with` / `phone_length`)
- `App\Traits\SendsPhoneOtp` — general fixed-demo-OTP sender (verification_codes / users)
- `EnsurePhoneVerified` middleware (blocks routes until `phone_verified_at` set)
- `MobileAuthController` + `MobileOtpRequest` / `MobileVerifyRequest`
- Feature tests: `tests/Feature/MobileAuthTest.php`
- Module documentation

## Historical

See git log for `Modules/User/` changes.
