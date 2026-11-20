<?php

declare(strict_types=1);

namespace phpMyFAQ\Mailvio;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(MailvioApiTransactionalMailer::class)]
#[CoversClass(RecipientUnsubscribed::class)]
#[UsesClass(MailvioApi::class)]
#[UsesClass(MailvioRequestFailed::class)]
#[UsesClass(TransactionalMail::class)]
final class MailvioApiTransactionalMailerTest extends TestCase
{
    public function testPostsTheMailWithItsReplyAddressAndWithoutTracking(): void
    {
        $request = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$request) {
            $request = ['method' => $method, 'url' => $url, 'body' => $options['body']];

            return new MockResponse('{"id":22314054,"SubscriberId":125647086}', ['http_code' => 200]);
        });

        new MailvioApiTransactionalMailer(new MailvioApi($client, 'key'))->send($this->mail());

        self::assertSame(['POST', 'https://apiv2.mailvio.com/transaction'], [$request['method'], $request['url']]);
        self::assertSame(
            [
                'fromAddress' => 'support@example.com',
                'fromName' => 'Jane Doe',
                'replyAddress' => 'jane@example.com',
                'toAddress' => 'support@example.com',
                'toName' => 'Mailvio Support',
                'subject' => 'Help',
                'html' => '<p>It broke</p>',
                'trackOpens' => false,
                'trackLinks' => false,
            ],
            json_decode($request['body'], true),
        );
    }

    public function testReportsAnUnsubscribedRecipient(): void
    {
        $mailer = $this->mailerAnswering(422, '{"error":"Email Address Unsubscribed Previously"}');

        $this->expectException(RecipientUnsubscribed::class);
        $this->expectExceptionMessage(
            'Mailvio refused the mail to "support@example.com": Email Address Unsubscribed Previously',
        );

        $mailer->send($this->mail());
    }

    public function testPassesOtherRefusalsOn(): void
    {
        $mailer = $this->mailerAnswering(422, '{"error":"Email Address on Blacklist"}');

        try {
            $mailer->send($this->mail());
            self::fail('Expected MailvioRequestFailed');
        } catch (MailvioRequestFailed $exception) {
            self::assertNotInstanceOf(RecipientUnsubscribed::class, $exception);
            self::assertSame('Email Address on Blacklist', $exception->apiError);
        }
    }

    private function mailerAnswering(int $status, string $body): MailvioApiTransactionalMailer
    {
        return new MailvioApiTransactionalMailer(new MailvioApi(new MockHttpClient([new MockResponse($body, [
            'http_code' => $status,
        ])]), 'key'));
    }

    private function mail(): TransactionalMail
    {
        return new TransactionalMail(
            fromAddress: 'support@example.com',
            fromName: 'Jane Doe',
            replyAddress: 'jane@example.com',
            toAddress: 'support@example.com',
            toName: 'Mailvio Support',
            subject: 'Help',
            html: '<p>It broke</p>',
        );
    }
}
