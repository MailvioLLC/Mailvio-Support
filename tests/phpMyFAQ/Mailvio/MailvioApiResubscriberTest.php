<?php

declare(strict_types=1);

namespace phpMyFAQ\Mailvio;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(MailvioApiResubscriber::class)]
final class MailvioApiResubscriberTest extends TestCase
{
    /** @var list<array{method: string, url: string, body: string, token: string}> */
    private array $requests = [];

    public function testReactivatesTheSubscriberInEveryGroupTheyUnsubscribedFrom(): void
    {
        $resubscriber = $this->resubscriber([
            $this->json(['count' => 1, 'Subscribers' => [$this->searchResult(42, 'jane@example.com')]]),
            $this->json([
                'Subscriber' => [
                    'id' => 42,
                    'GroupSubscribers' => [
                        ['unsubscribe' => true, 'active' => false, 'GroupId' => 116738],
                        ['unsubscribe' => false, 'active' => true, 'GroupId' => 201679],
                        ['unsubscribe' => true, 'active' => false, 'GroupId' => 130048],
                    ],
                ],
            ]),
            $this->json(['Success' => true]),
            $this->json(['Success' => true]),
        ]);

        $resubscriber->resubscribe('jane@example.com');

        self::assertCount(4, $this->requests);
        self::assertSame('GET', $this->requests[0]['method']);
        self::assertStringStartsWith('https://apiv2.mailvio.com/subscriber?', $this->requests[0]['url']);
        self::assertStringContainsString('search=jane@example.com&', $this->requests[0]['url']);
        self::assertSame('https://apiv2.mailvio.com/subscriber/42', $this->requests[1]['url']);
        self::assertSame(['POST', 'https://apiv2.mailvio.com/group/116738/subscriber/changeStatus'], [
            $this->requests[2]['method'],
            $this->requests[2]['url'],
        ]);
        self::assertSame('{"subscriberIds":[42],"newStatus":"active"}', $this->requests[2]['body']);
        self::assertSame('https://apiv2.mailvio.com/group/130048/subscriber/changeStatus', $this->requests[3]['url']);
        self::assertSame('x-access-token: secret-key', $this->requests[3]['token']);
    }

    public function testFindsAPlusAddressByThePartBeforeTheAt(): void
    {
        $resubscriber = $this->resubscriber([
            $this->json(['count' => 0, 'Subscribers' => []]),
            $this->json([
                'count' => 2,
                'Subscribers' => [
                    $this->searchResult(7, 'jane+other@example.com'),
                    $this->searchResult(8, 'Jane+Tag@example.com'),
                ],
            ]),
            $this->json([
                'Subscriber' => [
                    'id' => 8,
                    'GroupSubscribers' => [
                        ['unsubscribe' => true, 'active' => false, 'GroupId' => 116738],
                    ],
                ],
            ]),
            $this->json(['Success' => true]),
        ]);

        $resubscriber->resubscribe('jane+tag@example.com');

        self::assertStringContainsString('search=jane%2Btag', $this->requests[1]['url']);
        self::assertSame('https://apiv2.mailvio.com/subscriber/8', $this->requests[2]['url']);
        self::assertSame('{"subscriberIds":[8],"newStatus":"active"}', $this->requests[3]['body']);
    }

    public function testFailsWhenMailvioHasNoSuchSubscriber(): void
    {
        $resubscriber = $this->resubscriber([
            $this->json(['count' => 1, 'Subscribers' => [$this->searchResult(9, 'janet@example.com')]]),
            $this->json(['count' => 1, 'Subscribers' => [$this->searchResult(9, 'janet@example.com')]]),
        ]);

        $this->expectException(ResubscribeFailed::class);
        $this->expectExceptionMessage('Mailvio has no subscriber "jane@example.com"');

        $resubscriber->resubscribe('jane@example.com');
    }

    public function testFailsWithTheApiAnswerWhenARequestIsRefused(): void
    {
        $resubscriber = $this->resubscriber([
            new MockResponse('{"error":"Invalid token"}', ['http_code' => 401]),
        ]);

        $this->expectException(ResubscribeFailed::class);
        $this->expectExceptionMessage('Mailvio API GET /subscriber answered HTTP 401: {"error":"Invalid token"}');

        $resubscriber->resubscribe('jane@example.com');
    }

    public function testFailsWhenTheApiCannotBeReached(): void
    {
        $resubscriber = $this->resubscriber([new MockResponse('', ['error' => 'Connection timed out'])]);

        $this->expectException(ResubscribeFailed::class);
        $this->expectExceptionMessage('Mailvio API GET /subscriber failed: Connection timed out');

        $resubscriber->resubscribe('jane@example.com');
    }

    public function testFailsWithoutAnApiKeyBeforeCallingTheApi(): void
    {
        $resubscriber = new MailvioApiResubscriber(new MockHttpClient([]), apiKey: '');

        $this->expectException(ResubscribeFailed::class);
        $this->expectExceptionMessage('set MAILVIO_API_KEY in phpmyfaq/.env');

        $resubscriber->resubscribe('jane@example.com');
    }

    /**
     * @param list<MockResponse> $responses
     */
    private function resubscriber(array $responses): MailvioApiResubscriber
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$responses) {
            $this->requests[] = [
                'method' => $method,
                'url' => $url,
                'body' => (string) ($options['body'] ?? ''),
                'token' => (string) current(array_filter(
                    $options['headers'] ?? [],
                    static fn(string $header): bool => str_starts_with($header, 'x-access-token'),
                )),
            ];

            return array_shift($responses) ?? new MockResponse('unexpected request', ['http_code' => 500]);
        });

        return new MailvioApiResubscriber($client, 'secret-key');
    }

    /**
     * @return array<string, mixed>
     */
    private function searchResult(int $id, string $emailAddress): array
    {
        return ['id' => $id, 'emailAddress' => $emailAddress, 'blackListed' => 0, 'groups' => []];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function json(array $data): MockResponse
    {
        return new MockResponse(json_encode($data, JSON_THROW_ON_ERROR), ['http_code' => 200]);
    }
}
