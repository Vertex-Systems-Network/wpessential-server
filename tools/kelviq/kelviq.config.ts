// Kelviq catalog for WPEssential: one product and ONE shared one-time plan.
// Plans, prices and coupons live in WooCommerce; each checkout sends the plan's USD price as
// customAmount (src/Kelviq.php), so this file rarely changes. The plan price here is a placeholder.
import { plan, product } from '@kelviq/cli/config';

export const wpessential = product({
	identifier: 'wpessential',
	name: 'WPEssential',
	taxCode: 'software',
	description: 'Premium WordPress themes, demos and the Pro plugin.',
});

export const order = plan({
	identifier: 'wpessential-order',
	name: 'WPEssential order',
	product: 'wpessential',
	description: 'Charged at the WooCommerce plan price.',
	entitlements: [],
	prices: [
		{
			priceType: 'PAID',
			currency: 'USD',
			freeTrial: false,
			trialPeriod: 0,
			enabled: true,
			taxBehavior: 'INCLUSIVE',
			chargeCatalogPrice: [
				{
					feature: null,
					priceModel: 'FLAT',
					paymentType: 'ADVANCE_COMMITMENT',
					reset: 'NEVER',
					charges: [ { chargePeriod: 'ONE_TIME', priceData: { amount: 1 } } ],
				},
			],
		},
	],
});
