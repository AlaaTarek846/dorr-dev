# User — Changelog

## [Unreleased]

### Changed
- `GET /api/mobile/v1/privacy-policy` replaced by `GET /api/mobile/v1/legal-pages?type=privacy|term&service_id=` (`Mobile/LegalPageController` / `MobileLegalPageService`); `Mobile/PrivacyPolicyController` and `MobilePrivacyPolicyService` removed

### Added
- Mobile-only auth APIs: `/api/mobile/v1/*` (`user_api` guard, `Routes/mobile.php`)
- Combined login/register by phone (request OTP creates the user if missing)
- Country-aware phone validation via `ValidatesCountryPhone` (uses `countries.phone_starts_with` / `phone_length`)
- `App\Traits\SendsPhoneOtp` — general fixed-demo-OTP sender (verification_codes / users)
- `EnsurePhoneVerified` middleware (blocks routes until `phone_verified_at` set)
- `MobileAuthController` + `MobileOtpRequest` / `MobileVerifyRequest`
- Public mobile catalog content: `GET /api/mobile/v1/faqs` (every active general FAQ,
  `service_id IS NULL`) and `GET /api/mobile/v1/privacy-policy` (the single active general
  policy), served by `Mobile\FaqController` / `Mobile\PrivacyPolicyController` through
  `MobileFaqService` / `MobilePrivacyPolicyService` and the `FaqRepository::generalActive()` /
  `PrivacyPolicyRepository::generalActive()` queries
- Feature tests: `tests/Feature/MobileAuthTest.php`, `tests/Feature/MobileContentTest.php`
- Module documentation

## Historical

See git log for `Modules/User/` changes.
