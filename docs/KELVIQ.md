# Kelviq billing

`src/Kelviq.php` talks to the Kelviq REST API over HTTP, because there is no official PHP SDK. The calls mirror `@kelviq/node-sdk`.

## Configuration

Put these in `wp-config.php` constants or in the server environment. They are never stored in the database.

| Name | Value |
|---|---|
| `KELVIQ_SERVER_API_KEY` | Server key, from the Kelviq setup page in live mode |
| `KELVIQ_ENV` | `production` (when unset, the code uses sandbox) |
| `KELVIQ_WEBHOOK_SECRET` | Endpoint signing secret, `kq_whsec_…` |
| `KELVIQ_SUCCESS_URL` | Optional absolute return URL. The default is the My Account page plus `?wpes_checkout=done`. |

To check the keys without printing them, put them in a project `.env.local` and run:

    npx -y @kelviq/cli env --check

## What is sold

The Kelviq plans `pro` and `agency` are offered for `monthly → MONTHLY`, `yearly → YEARLY` and `lifetime → ONE_TIME`. Starter stays on WooCommerce because it is not published in Kelviq.

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

- `checkout.completed`: create, renew or upgrade the license through Fulfilment, using the metadata `wpes_user`, `wpes_plan`, `wpes_license` and `wpes_mode`.
- `invoice.payment_failed`: the renewal failed. Notify the customer and start the grace period.
- `subscription.created`: store the Kelviq subscription ID on the license.
- `subscription.updated`: sync auto-renew and the period end.
- `subscription.plan_changed`: change the license tier.
- `subscription.cancelled`: the subscription has ended, so expire the license. Never delete data.
