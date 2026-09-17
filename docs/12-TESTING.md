# Testing

---

## Testing Strategy

| Layer | Tool | Status |
|-------|------|--------|
| Backend unit | PHPUnit | Minimal (example test) |
| Backend feature | PHPUnit | Partial (API exception renderer) |
| Backend API integration | PHPUnit | **Missing** for most endpoints |
| Frontend unit | — | **Not configured** |
| Frontend E2E | — | **Not configured** |
| Static analysis (PHP) | Laravel Pint | Available, no custom `pint.json` |
| Lint (JS) | ESLint | **Not configured** |

**Principle:** Tests are part of feature implementation (see [07-IMPLEMENTATION-PLAN.md](./07-IMPLEMENTATION-PLAN.md)).

---

## Test Structure

```
tests/
├── TestCase.php
├── Unit/
│   └── ExampleTest.php
└── Feature/
    ├── ExampleTest.php
    └── ApiExceptionRendererTest.php
```

Module tests folders exist (`.gitkeep`) but no module tests implemented:
- `Modules/Admin/tests/`
- `Modules/User/tests/`
- `Modules/AI/tests/`
- `Modules/Provider/tests/`

---

## PHPUnit Configuration

**File:** `phpunit.xml`

- Environment: `APP_ENV=testing`
- Database: SQLite `:memory:`
- Cache/session/queue: array/sync drivers

---

## Running Tests

```bash
# Recommended
composer test

# Direct
php artisan test

# Specific file
php artisan test tests/Feature/ApiExceptionRendererTest.php

# With filter
php artisan test --filter=ApiExceptionRenderer
```

---

## Existing Test Coverage

### `Tests\Unit\ExampleTest`
- Placeholder: `assertTrue(true)`

### `Tests\Feature\ExampleTest`
- `GET /` returns 200

### `Tests\Feature\ApiExceptionRendererTest`
- Missing API route → JSON 404 (Arabic locale)
- Missing API route → JSON 404 (English locale)
- Missing API route → JSON 404 (X-Locale header)
- Unauthenticated admin route → JSON 401
- Validation error → JSON 422 with errors

---

## Important Scenarios to Test (Missing)

### Authentication
- [ ] Admin login success/failure
- [ ] User registration + OTP verification flow
- [ ] Token check endpoint
- [ ] Logout revokes token

### Catalog CRUD
- [ ] Create country with translations
- [ ] Validation fails when translations missing
- [ ] Delete blocked when relations exist
- [ ] Status change + bulk delete

### Authorization
- [ ] User cannot access admin routes
- [ ] Admin cannot access user-only routes

### AI
- [ ] Chat status when no provider configured
- [ ] Provider test connection (mocked HTTP)

### Frontend
- [ ] **TODO** — choose Vitest or Cypress when approved

---

## Writing Feature Tests

Follow Laravel conventions:

```php
namespace Tests\Feature;

use Tests\TestCase;

class ExampleApiTest extends TestCase
{
    public function test_example(): void
    {
        $response = $this->getJson('/api/admin/v1/languages/dropdown', [
            'X-Locale' => 'en',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['success', 'data']);
    }
}
```

Use Sanctum testing helpers for authenticated routes:

```php
use Laravel\Sanctum\Sanctum;
use Modules\Admin\Models\Admin;

Sanctum::actingAs($admin, ['*'], 'admin_api');
```

---

## Code Style Checks

```bash
# Laravel Pint (dry run)
./vendor/bin/pint --test

# Apply fixes
./vendor/bin/pint
```

---

## CI/CD

**TODO** — No CI configuration found in repository (`.github/workflows` not confirmed).

**NEEDS-DECISION:** CI pipeline setup.

---

## Frontend Build Verification

No automated frontend tests; verify manually:

```bash
npm run build
```

Ensures Vite production build succeeds for both SPAs.
