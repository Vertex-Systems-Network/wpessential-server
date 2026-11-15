<?php

declare(strict_types=1);

namespace WPEssential\Server\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WPEssential\Server\SiteProof;

final class SiteProofTest extends TestCase
{
    public function testHashFormatMatchesTheFreePlugin(): void
    {
        // The WPEssential Free plugin publishes exactly this hash (Platform\Admin\Connect\SiteProof::hash).
        $token = str_repeat('ab', 32);
        self::assertSame(hash('sha256', 'wpessential-site-proof|' . $token), SiteProof::hash($token));
    }

    public function testOnlyTheTokenWhoseHashTheSitePublishedMatches(): void
    {
        $token = bin2hex(random_bytes(32));
        $published = SiteProof::hash($token);

        self::assertTrue(SiteProof::matches($token, $published));
        self::assertFalse(SiteProof::matches(bin2hex(random_bytes(32)), $published), 'Another token (another account naming this domain).');
        self::assertFalse(SiteProof::matches($token, ''), 'Site publishes nothing.');
        self::assertFalse(SiteProof::matches('short', SiteProof::hash('short')), 'Tokens must be long.');
    }
}
