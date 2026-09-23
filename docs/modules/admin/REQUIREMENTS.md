# Admin — Requirements

## Implemented

- Admin login/logout/check-token/me (Sanctum)
- Admin profile update (including avatar media)
- Admin password change
- Admin accounts CRUD with status + bulk delete
- Mount point for all admin-facing API routes (catalog, users, AI, providers)

## Business Rules (from code)

- Authenticated admin required for all protected admin routes
- Admin linked to `country_id`
- Admin has `status` field
- Avatar via Spatie media collection

## NEEDS-DECISION

- Admin roles and permissions (Spatie; Admin API controllers use `DefinesAdminCatalogPermissions` / `AdminPermissionMiddleware` + `{group}.{action}` on guard `admin_api`, aligned with `AdminPermissionSeeder`)
- Multi-admin permission levels
- Admin audit logging
