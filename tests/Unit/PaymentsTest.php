<?php

declare(strict_types=1);

namespace WPEssential\Server\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WPEssential\Server\Fulfilment;
use WPEssential\Server\LemonSqueezy;
use WPEssential\Server\Payments;

final class PaymentsTest extends TestCase
{

    public function testFulfilmentDecidesCreateRenewOrUpgrade(): void
    {
        self::assertSame(['create', 0], Fulfilment::decide(0, false, 0, true, 0));
        self::assertSame(['renew', 5], Fulfilment::decide(5, false, 0, true, 0));
        self::assertSame(['upgrade', 5], Fulfilment::decide(5, true, 0, true, 0), 'Renewing a trial upgrades it.');
        self::assertSame(['upgrade', 7], Fulfilment::decide(0, false, 7, true, 0));
        self::assertSame(['upgrade', 9], Fulfilment::decide(0, false, 0, true, 9), 'Buying Pro during a trial upgrades the trial.');
        self::assertSame(['create', 0], Fulfilment::decide(0, false, 0, false, 9), 'A non-Pro plan never takes over a trial.');
    }

    public function testLemonSqueezySignatureIsTheHexHmacOfTheRawBody(): void
    {
        $body = '{"meta":{"event_name":"order_created"}}';
        $sig = hash_hmac('sha256', $body, 'shh');

        self::assertTrue(LemonSqueezy::valid_signature($body, $sig, 'shh'));
        self::assertTrue(LemonSqueezy::valid_signature($body, strtoupper($sig), 'shh'));
        self::assertFalse(LemonSqueezy::valid_signature($body . ' ', $sig, 'shh'), 'Body changed.');
        self::assertFalse(LemonSqueezy::valid_signature($body, $sig, 'other'), 'Wrong secret.');
        self::assertFalse(LemonSqueezy::valid_signature($body, '', 'shh'));
        self::assertFalse(LemonSqueezy::valid_signature($body, $sig, ''), 'No secret configured.');
    }

    public function testLemonSqueezyIntentFromCustomData(): void
    {
        self::assertSame(
            ['user' => 12, 'plan' => 'pro_yearly', 'renew' => 0, 'upgrade' => 5],
            LemonSqueezy::intent(['wpes_user' => '12', 'wpes_plan' => 'pro_yearly', 'wpes_license' => '5', 'wpes_mode' => 'upgrade'])
        );
        self::assertSame(
            ['user' => 12, 'plan' => 'pro_monthly', 'renew' => 5, 'upgrade' => 0],
            LemonSqueezy::intent(['wpes_user' => '12', 'wpes_plan' => 'pro_monthly', 'wpes_license' => '5', 'wpes_mode' => 'renew'])
        );
        self::assertSame(0, LemonSqueezy::intent(['wpes_user' => '12', 'wpes_plan' => 'pro_monthly', 'wpes_license' => '5'])['upgrade'], 'Plain buy.');
        self::assertNull(LemonSqueezy::intent(['wpes_plan' => 'pro_yearly']), 'No buyer.');
        self::assertNull(LemonSqueezy::intent('not-an-array'));
        self::assertSame('pro_yearly', LemonSqueezy::intent(['wpes_user' => '1', 'wpes_plan' => "Pro_Yearly';--"])['plan']);
    }

    public function testWebhookDeliveriesAreIdempotentPerEventAndObject(): void
    {
        $a = ['meta' => ['event_name' => 'order_created'], 'data' => ['id' => '1', 'attributes' => ['updated_at' => 't1']]];
        $b = ['meta' => ['event_name' => 'order_refunded'], 'data' => ['id' => '1', 'attributes' => ['updated_at' => 't1']]];

        self::assertSame(LemonSqueezy::event_key($a), LemonSqueezy::event_key($a));
        self::assertNotSame(LemonSqueezy::event_key($a), LemonSqueezy::event_key($b));
    }

    public function testBankTransferGraceCoversOnlyUntilItsDate(): void
    {
        self::assertTrue(Payments::pending_covers(1000, 999));
        self::assertTrue(Payments::pending_covers(1000, 1000));
        self::assertFalse(Payments::pending_covers(1000, 1001));
        self::assertFalse(Payments::pending_covers(0, 1));
    }

    public function testReceiptsMustBeSmallImagesOrPdfsWhoseContentMatches(): void
    {
        self::assertNull(Payments::receipt_refusal('slip.jpg', 1000, 'image/jpeg'));
        self::assertNull(Payments::receipt_refusal('slip.PNG', 1000, 'image/png'));
        self::assertNull(Payments::receipt_refusal('slip.pdf', 1000, 'application/pdf'));
        self::assertSame('receipt_type', Payments::receipt_refusal('slip.php', 1000, 'text/x-php'));
        self::assertSame('receipt_type', Payments::receipt_refusal('slip.jpg', 1000, 'text/html'), 'Extension and content must agree.');
        self::assertSame('receipt_size', Payments::receipt_refusal('slip.jpg', 6 * 1024 * 1024, 'image/jpeg'));
        self::assertSame('receipt_size', Payments::receipt_refusal('slip.jpg', 0, 'image/jpeg'));
    }

    public function testOnlyThePlansOwnVariantFulfilsIt(): void
    {
        self::assertTrue(LemonSqueezy::variant_matches('123', '123'));
        self::assertTrue(LemonSqueezy::variant_matches(123, '123'));
        self::assertFalse(LemonSqueezy::variant_matches('124', '123'), 'A cheaper variant cannot buy this plan.');
        self::assertFalse(LemonSqueezy::variant_matches('', ''), 'A plan without a variant is not sold there.');
        self::assertFalse(LemonSqueezy::variant_matches('0', '0'));
    }

    public function testBankGraceIsGrantedAtMostOncePerSixtyDays(): void
    {
        $day = 86400;
        self::assertTrue(Payments::grace_allowed(0, 1_000_000_000));
        self::assertFalse(Payments::grace_allowed(1_000_000_000, 1_000_000_000 + 59 * $day));
        self::assertTrue(Payments::grace_allowed(1_000_000_000, 1_000_000_000 + 60 * $day));
    }
}
