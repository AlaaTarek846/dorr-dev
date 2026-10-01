# General — Data Model

Tables managed by General module logic:

## flags / flag_translations
- `flags`: code, status
- `flag_translations`: flag_id, locale, name

## languages / language_translations
- `languages`: code, direction, status, stores_translation, is_default_dashboard, is_default_website, flag_id
- `language_translations`: language_id, locale, name

## currencies / currency_translations
- `currencies`: code, symbol, exchange_rate, is_default, status
- `currency_translations`: currency_id, locale, name

## countries / country_translations
- `countries`: code, code_alpha3, dial_code, phone_starts_with, phone_length, is_default, status, flag_id, currency_id
- `country_translations`: country_id, locale, name

## service_categories / service_category_translations
- `service_categories`: parent_id, module_name (unique, nullable), audiences (JSON array of `ServiceAudience`: admin, user, provider, driver), is_login_dashboard, is_auto_assign, requires_provider, status, sort_order
- `service_category_translations`: service_category_id, locale, name, description (nullable longText)
- Legacy booleans `is_login_dashboard` / `requires_provider` stay in sync when `audiences` is saved (user / provider flags).

## faqs / faq_translations
- `faqs`: service_id (nullable FK → service_categories.id, nullOnDelete), status, sort_order
- `faq_translations`: faq_id, locale, question, answer (unique faq_id + locale)

## legal_pages / legal_page_translations
- `legal_pages`: type (`LegalPageType`: privacy | term), service_id (nullable FK → service_categories.id, nullOnDelete), status, soft deletes; unique (type, service_id, deleted_at) — at most one live page per type per service, null/general repeats allowed
- `legal_page_translations`: legal_page_id, locale, content (longText; unique legal_page_id + locale)
- Replaces `privacy_policies` / `privacy_policy_translations` (migration `2026_09_29_090100_create_legal_pages_table`); there is no `sort_order`

## platform_settings
- `app_name` + media via Spatie (not column-based files)

## Relationships

```
Flag ──< Language
Flag ──< Country >── Currency
ServiceCategory ──< ServiceCategory (parent/children)
Country ──< Admin, User, Provider (via country_id)
```

See [../../05-DATA-MODEL.md](../../05-DATA-MODEL.md) for full ER overview.
