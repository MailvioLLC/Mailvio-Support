<?php

declare(strict_types=1);

namespace phpMyFAQ\SupportTicket;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(SupportTicket::class)]
final class SupportTicketTest extends TestCase
{
    private const array TICKET = [
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'subject' => 'Help',
        'message' => 'It broke',
    ];

    public function testRoundTripsThroughAnArray(): void
    {
        $ticket = SupportTicket::fromArray(self::TICKET);

        self::assertNotNull($ticket);
        self::assertSame(self::TICKET, $ticket->toArray());
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function invalidData(): array
    {
        return [
            'not an array' => ['ticket'],
            'missing name' => [[...self::TICKET, 'name' => '']],
            'invalid email' => [[...self::TICKET, 'email' => 'not-an-email']],
            'missing subject' => [[...self::TICKET, 'subject' => '']],
            'message is not a string' => [[...self::TICKET, 'message' => ['It broke']]],
        ];
    }

    #[DataProvider('invalidData')]
    public function testRestoresNothingFromInvalidData(mixed $data): void
    {
        self::assertNull(SupportTicket::fromArray($data));
    }

    public function testCannotBeCreatedWithAnInvalidEmailAddress(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"jane" is not a valid email address.');

        new SupportTicket('Jane Doe', 'jane', 'Help', 'It broke');
    }
}
