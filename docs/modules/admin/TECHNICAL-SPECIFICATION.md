# Admin — Technical Specification

## Model: `Modules\Admin\Models\Admin`

- Authenticatable + Sanctum `HasApiTokens`
- `belongsTo` Country
- Media: avatar
- Table: `admins`

## Auth Flow

1. `AdminAuthController@login` validates credentials → creates Sanctum token
2. Token sent as Bearer on subsequent requests
3. Guard: `admin_api`
4. Guest middleware prevents authenticated access to login routes

## CRUD Pattern

`AdminController` → `AdminService` → `AdminRepository`

Similar to User/Provider module CRUD (not using Catalog base).

## Cross-Module Dependencies

- `Modules\Admin\routes\admin.php` imports:
  - `App\Http\Controllers\General\*` (catalog)
  - `Modules\User\Http\Controllers\UserController` (user management)
- Branding uses General repositories in `routes/web.php`

## Frontend Routes

`resources/js/modules/admin/routes.js` — login, dashboard, catalog pages, users, providers, AI settings, platform settings, profile.

Middleware: `auth.js`, `guest.js`
