# User — Testing

## Current Coverage

None specific to User module.

## Recommended Tests

### Auth
- [ ] Registration sends verification code
- [ ] verify-email with valid/invalid OTP
- [ ] create-password completes registration
- [ ] Login after registration
- [ ] Forgot/reset password flow

### Profile
- [ ] Update profile fields
- [ ] Change password

### Admin User CRUD
- [ ] List/create/update/delete user
- [ ] Status change to Blocked

### Social Auth
- [ ] Mock Socialite redirect/callback (integration)

## Location

`Modules/User/tests/` or `tests/Feature/User/`
