<?php

declare(strict_types=1);

namespace phpMyFAQ\Alert;

use phpMyFAQ\Configuration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(ZulipAlertNotifier::class)]
final class ZulipAlertNotifierTest extends TestCase
{
    public function testPostsTheAlertToTheStreamAsTheBot(): void
    {
        $requests = [];
        $httpClient = new MockHttpClient(static function (string $method, string $url, array $options) use (
            &$requests,
        ): MockResponse {
            $requests[] = [$method, $url, $options];
            return new MockResponse('{"result":"success"}', ['http_code' => 200]);
        });
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('error');

        $this->createNotifier($httpClient, $logger)->alert('Support site > Support tickets', 'Mail refused');

        self::assertCount(1, $requests);
        [$method, $url, $options] = $requests[0];
        self::assertSame('POST', $method);
        self::assertSame('https://mailvio.zulipchat.com/api/v1/messages', $url);
        self::assertContains('Authorization: Basic ' . base64_encode('bot@example.com:api-key'), $options['headers']);
        parse_str($options['body'], $body);
        self::assertSame(
            [
                'type' => 'stream',
                'to' => 'error_logs',
                'topic' => 'Support site > Support tickets',
                'content' => 'Mail refused',
            ],
            $body,
        );
    }

    public function testReadsTheBotFromTheEnvironment(): void
    {
        $requests = [];
        $httpClient = new MockHttpClient(static function (string $method, string $url, array $options) use (
            &$requests,
        ): MockResponse {
            $requests[] = [$url, $options];
            return new MockResponse('{}', ['http_code' => 200]);
        });
        $configuration = $this->createStub(Configuration::class);
        $configuration->method('getLogger')->willReturn(new \Monolog\Logger('test'));
        $backup = $_ENV;
        $_ENV['ZULIP_URL'] = 'https://chat.example.com';
        $_ENV['ZULIP_BOT_EMAIL'] = 'bot@example.com';
        $_ENV['ZULIP_BOT_API_KEY'] = 'api-key';
        unset($_ENV['ZULIP_STREAM_ERROR_LOGS']);

        try {
            ZulipAlertNotifier::fromEnvironment($httpClient, $configuration)->alert('Topic', 'Mail refused');
        } finally {
            $_ENV = $backup;
        }

        self::assertSame('https://chat.example.com/api/v1/messages', $requests[0][0]);
        parse_str($requests[0][1]['body'], $body);
        self::assertSame(ZulipAlertNotifier::DEFAULT_STREAM, $body['to']);
    }

    public function testTruncatesLongAlerts(): void
    {
        $content = '';
        $httpClient = new MockHttpClient(static function (string $method, string $url, array $options) use (
            &$content,
        ): MockResponse {
            parse_str($options['body'], $body);
            $content = $body['content'];
            return new MockResponse('{}', ['http_code' => 200]);
        });

        $this->createNotifier($httpClient, $this->createStub(LoggerInterface::class))->alert('Topic', str_repeat(
            'a',
            5000,
        ));

        self::assertSame(1500, strlen($content));
        self::assertStringEndsWith('[Message truncated. Original length: 5000 bytes.]', $content);
    }

    public function testOnlyLogsTheAlertWhenTheBotIsNotConfigured(): void
    {
        $httpClient = new MockHttpClient(static fn(): MockResponse => self::fail('No request expected'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->expects($this->once())
            ->method('warning')
            ->with(self::stringContains('set ZULIP_URL, ZULIP_BOT_EMAIL and ZULIP_BOT_API_KEY'));

        new ZulipAlertNotifier($httpClient, $logger, '', '', '')->alert('Topic', 'Mail refused');
    }

    public function testLogsARejectedAlertInsteadOfThrowing(): void
    {
        $httpClient = new MockHttpClient(new MockResponse('{"result":"error","msg":"Invalid API key"}', [
            'http_code' => 401,
        ]));
        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->expects($this->once())
            ->method('error')
            ->with(self::logicalAnd(self::stringContains('HTTP 401'), self::stringContains('Mail refused')));

        $this->createNotifier($httpClient, $logger)->alert('Topic', 'Mail refused');
    }

    public function testLogsAnUnreachableZulipInsteadOfThrowing(): void
    {
        $httpClient = new MockHttpClient(static fn(): never => throw new TransportException('Could not resolve host'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with(self::stringContains('Could not resolve host'));

        $this->createNotifier($httpClient, $logger)->alert('Topic', 'Mail refused');
    }

    private function createNotifier(MockHttpClient $httpClient, LoggerInterface $logger): ZulipAlertNotifier
    {
        return new ZulipAlertNotifier(
            $httpClient,
            $logger,
            'https://mailvio.zulipchat.com/',
            'bot@example.com',
            'api-key',
        );
    }
}
