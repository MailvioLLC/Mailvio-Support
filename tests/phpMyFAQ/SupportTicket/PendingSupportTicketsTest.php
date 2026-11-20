<?php

declare(strict_types=1);

namespace phpMyFAQ\SupportTicket;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

#[CoversClass(PendingSupportTickets::class)]
#[UsesClass(SupportTicket::class)]
final class PendingSupportTicketsTest extends TestCase
{
    private PendingSupportTickets $pendingTickets;

    protected function setUp(): void
    {
        $this->pendingTickets = new PendingSupportTickets(new Session(new MockArraySessionStorage()));
    }

    public function testFindsAHeldTicketByItsId(): void
    {
        $ticket = $this->ticket('jane@example.com');

        $id = $this->pendingTickets->hold($ticket);

        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $id);
        self::assertEquals($ticket, $this->pendingTickets->find($id));
    }

    public function testFindsNothingForAnUnknownId(): void
    {
        $this->pendingTickets->hold($this->ticket('jane@example.com'));

        self::assertNull($this->pendingTickets->find('unknown'));
    }

    public function testFindsNothingOnceReleased(): void
    {
        $id = $this->pendingTickets->hold($this->ticket('jane@example.com'));

        $this->pendingTickets->release($id);

        self::assertNull($this->pendingTickets->find($id));
    }

    public function testKeepsOnlyTheLatestFiveTickets(): void
    {
        $first = $this->pendingTickets->hold($this->ticket('first@example.com'));
        for ($ticket = 2; $ticket <= 6; ++$ticket) {
            $last = $this->pendingTickets->hold($this->ticket(sprintf('ticket%d@example.com', $ticket)));
        }

        self::assertNull($this->pendingTickets->find($first));
        self::assertSame('ticket6@example.com', $this->pendingTickets->find($last)?->email);
    }

    private function ticket(string $email): SupportTicket
    {
        return new SupportTicket('Jane Doe', $email, 'Help', 'It broke');
    }
}
