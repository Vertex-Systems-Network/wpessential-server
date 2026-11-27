# Kelviq billing

## Design: one core, many payment providers

- The source of truth is our own plans (`Plans`) and licenses (`Licenses`). Every paid purchase goes through `Fulfilment::apply()`, which creates, renews or upgrades a license. Kelviq license keys are not used.
- A provider only takes the payment. It has `enabled()` and `checkout_url( $user_id, $plan, $license_id, $mode )`, is listed in `Api::PROVIDERS`, and calls `Fulfilment::apply()` from its verified webhook.
- A WooCommerce gateway, such as a Pakistani bank's plugin, needs no code, because paid WooCommerce orders already fulfil through `Orders`.
- A bank with its own API needs one class like `Kelviq` and one line in `Api::PROVIDERS`.

`src/Kelviq.php` talks to the Kelviq REST API over HTTP, because there is no official PHP SDK. The calls mirror `@kelviq/node-sdk`.

## Configuration

Go to Settings → Payments → Kelviq. It has the same layout as Lemon Squeezy:
- Enable
- Live (production)
- Prices include tax
- Plan identifier
- Server API key and webhook signing secret. Once saved, these are never shown again.

The `wp-config.php` constants `KELVIQ_SERVER_API_KEY`, `KELVIQ_WEBHOOK_SECRET` and `KELVIQ_ENV` override the fields. `KELVIQ_SUCCESS_URL` is optional.

To check the keys without printing them, put them in a project `.env.local` and run:

    npx -y @kelviq/cli env --check

## What is sold: everything is managed in WooCommerce

Plans, prices and coupons are managed only in WooCommerce. Kelviq has one product (`wpessential`) and one shared one-time plan, `wpessential-order`. The plan identifier is set under Settings → Kelviq.

Each checkout sends:
- the plan's USD price (the product's `_wpes_usd_price`, otherwise its price), after any WooCommerce coupon, as `customAmount`
- `currencyCode` USD
- `chargePeriod` ONE_TIME

A coupon passes the same checks as in a cart: it must be published, not expired, have uses left and allow the product. Its use is counted once the payment is confirmed.

Renewals work like the WooCommerce flow: each renewal is a new payment. Kelviq does not run any subscriptions.

Unconfirmed by the docs: whether `customAmount` is in major units and whether it is accepted with `ONE_TIME`. Test both in sandbox first.

The `customerId` is the WordPress user ID, and it is always read on the server.

## Routes (namespace `wpessential-server/v1`)

- `POST /account/checkout` with `channel=kelviq` and `plan=<tier>_<period>` returns a hosted checkout URL.
- `POST /account/billing` returns the customer portal URL. It returns 404 when the user has no Kelviq billing yet.
- `POST /kelviq/webhooks` is the webhook endpoint. It verifies the signature (403 if invalid) and handles each event once.

`Kelviq::has_feature( $user, $feature )` checks entitlements. It fails closed, so any error returns `false`. It is not called anywhere yet, because Kelviq plans have no features. Add `sites` and `white_label` features in Kelviq first.

## Go-live steps (owner)

1. Paste the live keys into `wp-config.php` and set `KELVIQ_ENV=production`.
2. Register the webhook:

       npx -y @kelviq/cli webhook add https://<server-domain>/wp-json/wpessential-server/v1/kelviq/webhooks --events checkout.completed,invoice.payment_failed,subscription.created,subscription.updated,subscription.plan_changed,subscription.cancelled --write-secret .env.local --prod --yes

   Then copy `KELVIQ_WEBHOOK_SECRET` into `wp-config.php`.
3. Complete business verification and payouts in Kelviq.
4. Test: sign in, then start an upgrade with the Kelviq channel. Stop at the checkout page; a payment there is real.

## Webhook TODOs (in `Kelviq::dispatch`)

- `checkout.completed` is done: `Fulfilment::apply()` runs from the server-written metadata, and the paying customer must match.
- Recurring renewals are not handled yet. They need `invoice.paid` to be mapped to the license through the subscription id.
- `invoice.payment_failed`: the renewal failed. Notify the customer and start the grace period.
- `subscription.created`: store the Kelviq subscription ID on the license.
- `subscription.updated`: sync auto-renew and the period end.
- `subscription.plan_changed`: change the license tier.
- `subscription.cancelled`: the subscription has ended, so expire the license. Never delete data.

## Catalog (pricing as code)

`tools/kelviq/kelviq.config.ts` holds the one product and the one plan. Push it once, from `tools/kelviq/`, with the keys in `.env.local`:

    npm install
    npx kelviq env --check
    npx kelviq push --prod --dry-run
    npx kelviq push --prod --yes --publish

After that, price and plan changes are made in WooCommerce only.
