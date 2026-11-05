# Moving Module (`Modules/Moving`)

Furniture moving. Business decisions: [moving_business.md](../../moving_business.md). Tasks and status: [moving_tasks.md](../../moving_tasks.md). Requirements: `06-DORR-Furniture-Moving-Requirements-v1.3.docx` (REQ numbers below).

It stands on the provider platform in `Modules/Provider` (establishments, branches, members and permissions, vehicles, documents, work gate) — see [provider/README.md](../provider/README.md).

## Flow

1. **Draft** (`mobile/v1/moving/requests`): load type, description, photos/videos (`media`), pieces, vehicle type, workers count (one number), extras, pickup and drop-off (point, address, neighbourhood, floor, lift, entrance), time.
2. **Publish**: checks the required fields (DEC-037) and that moving is on in the country (`country_service_category`), keeps the country values with the request (`policy`, `policy_version`, REQ-003), and sends it to every active branch of an approved establishment offering moving whose area (`radius_km`, capped by `broadcast.max_radius_km`) holds the pickup point (`moving_request_branches`). Offer time: `offers.window_minutes`, never past the moving time.
3. **Offers** (`provider/v1/moving/requests/{uuid}/offers`): one per establishment; only with a vehicle passing `WorkGate::offerProblem()`; above the sender's `offer_limit_minor` it waits for `offers.approve`; a vehicle booked within `schedule.block_hours` is refused (REQ-027). Providers see the neighbourhood only until their offer is accepted (REQ-019).
4. **The customer compares** three at a time (`offers?page=`) with the reasons of the order (`cheapest`, `earliest`, `vehicle_fit`).
5. **Accept** opens the shared payment screen (`MovingCheckoutPurpose`, `ReservesFunds`): paying holds the total in the customer's wallet with no end date (`MovingPayments`, reference `moving_request`), keeps price, tax, commission and the establishment's net (`MovingPricing`), and closes the other offers.
6. **Assign** (`provider/v1/moving/jobs/{uuid}/assign`, `jobs.assign`): a driver of the establishment with an approved licence; another vehicle only with a written reason. The driver accepts or declines in «مهامي» (`my-jobs`). An individual's owner is assigned and accepted at once. A member who leaves hands back their jobs (`MembershipEnded`).
7. **No offer chosen in time**: `moving:expire-requests` (every minute) expires it (REQ-020).

8. **Moving day** (`provider/v1/moving/jobs/{uuid}/step`): `set_out` → `arrived` (place and time; a note when there is no GPS) → `loading` → `departed` (needs the numbered inventory, one photo per piece: `jobs/{uuid}/inventory`) → `unloading`. Positions: `jobs/{uuid}/locations` (batches) → `moving.driver_location` on the private channel `moving.{uuid}`.
9. **Delivery** (`jobs/{uuid}/deliver`): the customer's code (made at acceptance, shown only to the customer, also as the QR `dorr://moving/{uuid}/{code}`); without it `without_code` = `refused` / `absent` plus a note — never counted as consent (REQ-033). The objection window (`delivery.objection_hours`) starts.
10. **Money** (`MovingSettlement`, `MovingMoney`): when the customer confirms (`requests/{uuid}/confirm`) or the window ends (`moving:settle-due`, every 5 min), the establishment's wallet gets the net; commission and tax go to the ledger (`moving_commissions`, `moving_taxes`). No window set → it waits for the customer's confirmation.
11. **Report** (`requests/{uuid}/report`, photos + claimed amount, within the window): at settlement only the claim stays held (capped by `dispute.max_hold_bp` and the net), the rest is paid; a person decides (`admin/v1/moving-cases/{uuid}/decide`, `award_minor`).
12. **Cancelling** (`requests/{uuid}/cancel`, `jobs/{uuid}/cancel`, reasons in `config/config.php`): the customer pays the stage fee (`cancel.customer.*_bp`, shown in `show` as `cancel_fee_minor` and in `cancellation`) to the establishment; no cancelling after loading. A reason blaming the other side opens an exception (`moving_cases`, kind `exception`) decided by a person (`refund_all` / `fee` / `continue`). The establishment may cancel before its driver sets out (full refund).

13. **Changes** (`MovingChangeService`, `moving_change_orders` with before/after): the establishment asks a price difference (`jobs/{uuid}/changes`); the customer answers (`requests/{uuid}/changes/{uuid}/respond`) — more to pay opens the payment screen (`MovingChangeCheckoutPurpose`, held with the rest). The waiting fee (`waiting.*`) is worked out when loading starts and asked the same way. Either side asks a new time (`reschedule`), the other answers.
14. **After**: rating of the establishment and the driver (`requests/{uuid}/rating`; average and finished moves shown on offers), tickets (eight kinds, to the establishment first, to Dorr with a reason), the invoice (`requests/{uuid}/invoice`, `jobs/{uuid}/invoice`).

Every step goes through `MovingJournal` → `moving_request_events` + the `MovingRequestChanged` event. `MovingNotifier` (after commit) broadcasts `moving.changed` on `moving.{uuid}` and stores + pushes the important ones by permission (texts: `lang/*/notifications.php` → `moving_{customer|provider}_{action}`).

Admin pages (`views/moving/`): requests (live, with the month's money), establishments review, tickets, cases, vehicle types, documents asked, moving values. Permissions: `moving-requests`, `provider-establishments`, `moving-tickets`, `moving-cases`, `provider-vehicle-types`, `provider-document-types`, `moving-policies`.

## Country values (`moving_policies`, admin `moving-policies`)

All empty until the admin sets them; an empty value means the rule is not applied (DEC-020). Keys: `Modules/Moving/config/config.php` → `policy_keys`.

## Not built yet

The invoice as PDF and the facts report (REQ-102), evidence fingerprints (REQ-060), the behaviour engine (REQ-066 →), AI features, the establishment's fine on cancelling (NEEDS-DECISION), and the first build of the providers app (phase C, `providerApp/` + `providerAppIOS/`) and of the customer screens (phase D, `androidApp/` + `iosApp/`): all their code is written, none of it is built yet — see phase B in [moving_tasks.md](../../moving_tasks.md).
