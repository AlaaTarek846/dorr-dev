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
- `service_categories`: parent_id, module_name (unique, nullable), is_login_dashboard, is_auto_assign, requires_provider, status, sort_order
- `service_category_translations`: service_category_id, locale, name

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
