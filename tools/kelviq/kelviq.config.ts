// Kelviq catalog for WPEssential Pro (prices mirror src/Plans.php, USD).
// Push from this folder: npx kelviq push --prod --dry-run, then --prod --yes --publish after approval.
// License keys stay in our own system (Licenses), so plans carry no `license` block.
import { feature, plan, product } from '@kelviq/cli/config';

export const wpessential = product({
	identifier: 'wpessential-pro',
	name: 'WPEssential Pro',
	taxCode: 'software',
	description: 'Premium WordPress themes, demos and the Pro plugin.',
});

export const allThemes = feature({ identifier: 'all-themes', name: 'All themes and demos', type: 'BOOLEAN' });
export const earlyAccess = feature({ identifier: 'early-access', name: 'Early access to new demos', type: 'BOOLEAN' });
export const prioritySupport = feature({ identifier: 'priority-support', name: 'Priority support', type: 'BOOLEAN' });
export const whiteLabel = feature({ identifier: 'white-label', name: 'White-label dashboard', type: 'BOOLEAN' });

// Monthly, yearly (12 months − 20%) and lifetime (one major version) for one tier.
const prices = (monthly: number, lifetime: number) => [
	{
		priceType: 'PAID' as const,
		currency: 'USD',
		freeTrial: false,
		trialPeriod: 0,
		enabled: true,
		taxBehavior: 'EXCLUSIVE' as const,
		chargeCatalogPrice: [
			{
				feature: null,
				priceModel: 'FLAT',
				paymentType: 'ADVANCE_COMMITMENT',
				reset: 'NEVER',
				charges: [
					{ chargePeriod: 'MONTHLY', priceData: { amount: monthly } },
					{ chargePeriod: 'YEARLY', priceData: { amount: Math.round( monthly * 12 * 0.8 ) } },
					{ chargePeriod: 'ONE_TIME', priceData: { amount: lifetime } },
				],
			},
		],
	},
];

const on = ( ids: string[] ) => ids.map( ( id ) => ( { feature: id, value: true } ) );

export const starter = plan({
	identifier: 'starter',
	name: 'Starter',
	product: 'wpessential-pro',
	description: '1 theme and its demos, Gutenberg and Elementor, 1 website.',
	entitlements: [],
	prices: prices( 10, 239 ),
});

export const pro = plan({
	identifier: 'pro',
	name: 'Pro',
	product: 'wpessential-pro',
	description: 'All themes and demos, early access, 5 websites, priority support.',
	entitlements: on( [ 'all-themes', 'early-access', 'priority-support' ] ),
	prices: prices( 30, 719 ),
});

export const agency = plan({
	identifier: 'agency',
	name: 'Agency',
	product: 'wpessential-pro',
	description: 'Everything in Pro, unlimited websites, white-label dashboard.',
	entitlements: on( [ 'all-themes', 'early-access', 'priority-support', 'white-label' ] ),
	prices: prices( 100, 2399 ),
});
