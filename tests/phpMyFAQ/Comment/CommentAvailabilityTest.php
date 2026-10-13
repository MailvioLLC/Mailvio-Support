<?php

declare(strict_types=1);

namespace phpMyFAQ\Comment;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(CommentAvailability::class)]
final class CommentAvailabilityTest extends TestCase
{
    /**
     * @return array<string, array{string, string, bool, bool, bool, CommentAvailability}>
     */
    public static function visitors(): array
    {
        return [
            'registered user on an open FAQ' => ['published', 'y', false, false, false, CommentAvailability::Open],
            'guest when guests may comment' => ['published', 'y', false, true, true, CommentAvailability::Open],
            'guest when only registered users may comment' => [
                'published',
                'y',
                false,
                true,
                false,
                CommentAvailability::LogInRequired,
            ],
            'comments switched off on the FAQ' => ['published', 'n', false, false, true, CommentAvailability::Closed],
            'guest on a FAQ with comments switched off' => [
                'published',
                'n',
                false,
                true,
                false,
                CommentAvailability::Closed,
            ],
            'draft FAQ' => ['draft', 'y', false, false, true, CommentAvailability::Closed],
            'expired FAQ' => ['published', 'y', true, false, true, CommentAvailability::Closed],
        ];
    }

    #[DataProvider('visitors')]
    public function testDecidesWhetherTheVisitorCanComment(
        string $status,
        string $commentFlag,
        bool $isExpired,
        bool $visitorIsGuest,
        bool $guestsMayComment,
        CommentAvailability $expected,
    ): void {
        self::assertSame($expected, CommentAvailability::forFaq(
            $status,
            $commentFlag,
            $isExpired,
            $visitorIsGuest,
            $guestsMayComment,
        ));
    }
}
