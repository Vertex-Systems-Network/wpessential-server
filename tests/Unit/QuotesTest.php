<?php

declare(strict_types=1);

namespace WPEssential\Server\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WPEssential\Server\Quotes;

final class QuotesTest extends TestCase
{
    public function testScopeLinesAndTotal(): void
    {
        $lines = Quotes::lines("Design (5 pages) | 1 | 600\n\nDevelopment | 2 | 350\r\nSEO", ['label', 'qty', 'amount']);
        self::assertCount(3, $lines);
        self::assertSame(['label' => 'Development', 'qty' => '2', 'amount' => '350'], $lines[1]);
        self::assertSame(['label' => 'SEO', 'qty' => '', 'amount' => ''], $lines[2]);
        self::assertSame(1300.0, Quotes::total($lines));
    }
}
