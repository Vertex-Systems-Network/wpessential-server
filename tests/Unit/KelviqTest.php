<?php

declare(strict_types=1);

namespace WPEssential\Server\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WPEssential\Server\Kelviq;

final class KelviqTest extends TestCase
{

    public function testOnlyPublishedPlansAndPeriodsMap(): void
    {
        self::assertSame(['plan' => 'pro', 'period' => 'YEARLY'], Kelviq::plan_for('pro_yearly'));
        self::assertSame(['plan' => 'agency', 'period' => 'ONE_TIME'], Kelviq::plan_for('agency_lifetime'));
        self::assertSame(['plan' => 'starter', 'period' => 'MONTHLY'], Kelviq::plan_for('starter_monthly'));
        self::assertNull(Kelviq::plan_for('pro_weekly'));
        self::assertNull(Kelviq::plan_for('owner'));
    }

    public function testEnvironmentDefaultsToSandboxAndRejectsUnknown(): void
    {
        self::assertSame('sandbox', Kelviq::environment(''));
        self::assertSame('production', Kelviq::environment('production'));
        self::assertNull(Kelviq::environment('live'));
    }

    public function testWebhookSignatureCoversIdTimestampAndRawBody(): void
    {
        $body = '{"id":"evt_1","type":"checkout.completed"}';
        $sig = 'v1,' . hash_hmac('sha256', 'msg_1.1700000000.' . $body, 'kq_whsec_x');

        self::assertTrue(Kelviq::valid_signature('msg_1', '1700000000', $body, $sig, 'kq_whsec_x', 1700000100));
        self::assertTrue(Kelviq::valid_signature('msg_1', '1700000000', $body, 'v1,bad ' . $sig, 'kq_whsec_x', 1700000100), 'Any listed signature may match.');
        self::assertFalse(Kelviq::valid_signature('msg_1', '1700000000', $body . ' ', $sig, 'kq_whsec_x', 1700000100), 'Body changed.');
        self::assertFalse(Kelviq::valid_signature('msg_2', '1700000000', $body, $sig, 'kq_whsec_x', 1700000100), 'Id changed.');
        self::assertFalse(Kelviq::valid_signature('msg_1', '1700000000', $body, $sig, 'kq_whsec_x', 1700000400), 'Too old.');
        self::assertFalse(Kelviq::valid_signature('msg_1', '1700000000', $body, $sig, '', 1700000100), 'No secret.');
    }

    public function testEntitlementAccess(): void
    {
        $response = ['entitlements' => [['featureId' => 'sites', 'hasAccess' => false], ['featureId' => 'white_label', 'hasAccess' => true]]];

        self::assertTrue(Kelviq::access_in($response, 'white_label'));
        self::assertFalse(Kelviq::access_in($response, 'sites'));
        self::assertFalse(Kelviq::access_in([], 'sites'));
    }

    public function testCheckoutIntentComesFromServerMetadataAndMatchingCustomer(): void
    {
        $paid = ['id' => 'cs_1', 'customer_id' => '7', 'metadata' => ['wpes_user' => '7', 'wpes_plan' => 'pro_yearly', 'wpes_license' => '12', 'wpes_mode' => 'renew']];

        self::assertSame(['user' => 7, 'plan' => 'pro_yearly', 'renew' => 12, 'upgrade' => 0], Kelviq::intent($paid));
        self::assertNull(Kelviq::intent(['customer_id' => '8'] + $paid), 'Paid by another customer.');
        self::assertNull(Kelviq::intent(['metadata' => ['wpes_user' => '7', 'wpes_plan' => 'owner_lifetime']]), 'Plan not sold on Kelviq.');
        self::assertNull(Kelviq::intent([]));
    }

    public function testWooCouponBecomesAKelviqDiscount(): void
    {
        $percent = Kelviq::discount_body(['code' => 'launch-20', 'type' => 'percent', 'amount' => '20', 'active' => true, 'expires' => 1893456000, 'limit' => 50]);
        self::assertSame('LAUNCH20', $percent['code']);
        self::assertSame('PERCENTAGE', $percent['discountType']);
        self::assertSame(20, $percent['percentageOff']);
        self::assertSame('ONCE', $percent['duration']);
        self::assertSame('2030-01-01T00:00:00Z', $percent['redeemBy']);
        self::assertSame(50, $percent['maxRedemptions']);

        $fixed = Kelviq::discount_body(['code' => 'ten', 'type' => 'fixed_cart', 'amount' => '10', 'active' => true]);
        self::assertSame(['FIXED_AMOUNT', '10.00', 'USD'], [$fixed['discountType'], $fixed['amountOff'], $fixed['currency']]);
        self::assertArrayNotHasKey('redeemBy', $fixed);

        self::assertNull(Kelviq::discount_body(['code' => 'off', 'type' => 'percent', 'amount' => '20', 'active' => false]), 'Trashed or expired.');
        self::assertNull(Kelviq::discount_body(['code' => '---', 'type' => 'percent', 'amount' => '20', 'active' => true]), 'No usable code.');
        self::assertNull(Kelviq::discount_body(['code' => 'x', 'type' => 'sign_up_fee', 'amount' => '5', 'active' => true]));
    }
}
