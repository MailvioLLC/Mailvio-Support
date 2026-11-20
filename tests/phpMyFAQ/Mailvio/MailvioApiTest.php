<?php

declare(strict_types=1);

namespace phpMyFAQ\Mailvio;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(MailvioApi::class)]
#[CoversClass(MailvioRequestFailed::class)]
final class MailvioApiTest extends TestCase
{
    public function testSendsTheApiKeyAndReturnsTheDecodedAnswer(): void
    {
        $headers = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$headers) {
            $headers = $options['headers'];

            return new MockResponse('{"id":7}', ['http_code' => 200]);
        });

        $answer = new MailvioApi($client, 'secret-key')->request('GET', '/subscriber/7');

        self::assertSame(['id' => 7], $answer);
        self::assertContains('x-access-token: secret-key', $headers);
    }

    public function testFailsWithTheApiAnswerWhenARequestIsRefused(): void
    {
        $api = new MailvioApi(new MockHttpClient([new MockResponse('{"error":"Email Address on Blacklist"}', [
            'http_code' => 422,
        ])]), 'secret-key');

        try {
            $api->request('POST', '/transaction');
            self::fail('Expected MailvioRequestFailed');
        } catch (MailvioRequestFailed $exception) {
            self::assertSame(
                'Mailvio API POST /transaction answered HTTP 422: {"error":"Email Address on Blacklist"}',
                $exception->getMessage(),
            );
            self::assertSame(422, $exception->httpStatus);
            self::assertSame('Email Address on Blacklist', $exception->apiError);
        }
    }

    public function testFailsWhenTheApiCannotBeReached(): void
    {
        $api = new MailvioApi(new MockHttpClient([new MockResponse('', ['error' => 'Connection timed out'])]), 'key');

        $this->expectException(MailvioRequestFailed::class);
        $this->expectExceptionMessage('Mailvio API GET /group failed: Connection timed out');

        $api->request('GET', '/group');
    }

    public function testFailsWithoutAnApiKeyBeforeCallingTheApi(): void
    {
        $api = new MailvioApi(new MockHttpClient([]), apiKey: '');

        $this->expectException(MailvioRequestFailed::class);
        $this->expectExceptionMessage('set MAILVIO_API_KEY in phpmyfaq/.env');

        $api->request('GET', '/group');
    }
}
