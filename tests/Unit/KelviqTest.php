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
        self::assertNull(Kelviq::plan_for('starter_monthly'), 'Starter is not published in Kelviq.');
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
        self::assertNull(Kelviq::intent(['metadata' => ['wpes_user' => '7', 'wpes_plan' => 'starter_monthly']]), 'Plan not sold on Kelviq.');
        self::assertNull(Kelviq::intent([]));
    }
}
