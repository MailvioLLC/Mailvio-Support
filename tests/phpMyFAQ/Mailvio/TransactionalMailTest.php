<?php

declare(strict_types=1);

namespace phpMyFAQ\Mailvio;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(TransactionalMail::class)]
final class TransactionalMailTest extends TestCase
{
    private const array MAIL = [
        'fromAddress' => 'support@example.com',
        'fromName' => 'Mailvio Support',
        'replyAddress' => 'support@example.com',
        'toAddress' => 'jane@example.com',
        'toName' => 'Jane Doe',
        'subject' => 'Help',
        'html' => '<p>It broke</p>',
    ];

    public function testKeepsItsFields(): void
    {
        $mail = new TransactionalMail(...self::MAIL);

        self::assertSame('jane@example.com', $mail->toAddress);
        self::assertSame('<p>It broke</p>', $mail->html);
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function invalidFields(): array
    {
        return [
            'invalid sender' => ['fromAddress', 'support', 'fromAddress "support" is not a valid email address.'],
            'invalid reply address' => ['replyAddress', '', 'replyAddress "" is not a valid email address.'],
            'invalid recipient' => ['toAddress', 'jane@', 'toAddress "jane@" is not a valid email address.'],
            'empty subject' => ['subject', '', 'A transactional mail needs a subject and a body.'],
            'empty body' => ['html', '', 'A transactional mail needs a subject and a body.'],
        ];
    }

    #[DataProvider('invalidFields')]
    public function testCannotBeCreatedWithAnInvalidField(string $field, string $value, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new TransactionalMail(...[...self::MAIL, $field => $value]);
    }
}
