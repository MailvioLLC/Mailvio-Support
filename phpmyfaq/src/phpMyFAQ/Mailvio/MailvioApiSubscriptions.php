<?php

/**
 * Looks up and changes subscriptions through the Mailvio REST API (apiv2.mailvio.com).
 *
 * This Source Code Form is subject to the terms of the Mozilla Public License,
 * v. 2.0. If a copy of the MPL was not distributed with this file, You can
 * obtain one at https://mozilla.org/MPL/2.0/.
 *
 * @package   phpMyFAQ
 * @author    Steven Mey <darkretailer@users.noreply.github.com>
 * @copyright 2026 phpMyFAQ Team
 * @license   https://www.mozilla.org/MPL/2.0/ Mozilla Public License Version 2.0
 * @link      https://www.phpmyfaq.de
 * @since     2026-11-19
 */

declare(strict_types=1);

namespace phpMyFAQ\Mailvio;

use phpMyFAQ\Environment;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Mailvio refuses a transactional mail to an address that unsubscribed from a group, the "Transactional
 * Emails" group included. Re-subscribing sets the subscriber active again in every group they left.
 * The API key comes from phpmyfaq/.env (MAILVIO_API_KEY) and must belong to the Mailvio account whose
 * SMTP login the site sends with.
 *
 * @see https://github.com/MailvioLLC/mailvio-api/tree/master/docs/API
 */
final readonly class MailvioApiSubscriptions implements Subscriptions
{
    public const string DEFAULT_BASE_URL = 'https://apiv2.mailvio.com';

    /** Mailvio adds every recipient of a transactional mail to this group and checks it before sending. */
    public const string TRANSACTIONAL_GROUP_NAME = 'Transactional Emails';

    public function __construct(
        private HttpClientInterface $httpClient,
        #[\SensitiveParameter]
        private string $apiKey,
        private string $baseUrl = self::DEFAULT_BASE_URL,
    ) {
    }

    public static function fromEnvironment(HttpClientInterface $httpClient): self
    {
        return new self($httpClient, (string) Environment::get('MAILVIO_API_KEY', ''));
    }

    public function isUnsubscribedFromTransactionalEmails(string $emailAddress): bool
    {
        $this->assertApiKey();
        $transactionalGroupId = $this->transactionalGroupId();

        foreach ($this->findSubscribers($emailAddress) as $subscriber) {
            if (in_array(
                $transactionalGroupId,
                $this->unsubscribedGroupIds($this->intValue($subscriber, 'id')),
                strict: true,
            )) {
                return true;
            }
        }

        return false;
    }

    public function resubscribe(string $emailAddress): void
    {
        $this->assertApiKey();

        $subscribers = $this->findSubscribers($emailAddress);
        if ($subscribers === []) {
            throw new MailvioRequestFailed(sprintf(
                'Cannot re-subscribe: Mailvio has no subscriber "%s".',
                $emailAddress,
            ));
        }

        foreach ($subscribers as $subscriber) {
            $subscriberId = $this->intValue($subscriber, 'id');
            foreach ($this->unsubscribedGroupIds($subscriberId) as $groupId) {
                $this->request('POST', sprintf('/group/%d/subscriber/changeStatus', $groupId), [
                    'json' => ['subscriberIds' => [$subscriberId], 'newStatus' => 'active'],
                ]);
            }
        }
    }

    private function assertApiKey(): void
    {
        if ($this->apiKey === '') {
            throw new MailvioRequestFailed('Cannot reach the Mailvio API: set MAILVIO_API_KEY in phpmyfaq/.env.');
        }
    }

    private function transactionalGroupId(): int
    {
        foreach ($this->listValue($this->request('GET', '/group', ['query' => [
            'limit' => 1000,
        ]]), 'Groups') as $group) {
            if ($this->stringValue($group, 'groupName') === self::TRANSACTIONAL_GROUP_NAME) {
                return $this->intValue($group, 'id');
            }
        }

        throw new MailvioRequestFailed(sprintf('Mailvio has no group "%s".', self::TRANSACTIONAL_GROUP_NAME));
    }

    /**
     * The search does not find plus addresses (jane+tag@example.com) by the whole address, only by the
     * part before the @, so that is the fallback. Only exact matches count.
     *
     * @return list<array<array-key, mixed>>
     */
    private function findSubscribers(string $emailAddress): array
    {
        $localPart = strstr($emailAddress, needle: '@', before_needle: true);
        $searches = array_unique([$emailAddress, $localPart === false ? $emailAddress : $localPart]);

        foreach ($searches as $search) {
            $matches = array_values(array_filter($this->listValue($this->request('GET', '/subscriber', [
                'query' => ['search' => $search, 'limit' => 50],
            ]), 'Subscribers'), fn(array $subscriber): bool => strcasecmp($this->stringValue($subscriber, 'emailAddress'), $emailAddress) === 0 && $this->intValue($subscriber, 'id') > 0));

            if ($matches !== []) {
                return $matches;
            }
        }

        return [];
    }

    /**
     * Read from the subscriber itself: the group states in search results can lag behind.
     *
     * @return list<int>
     */
    private function unsubscribedGroupIds(int $subscriberId): array
    {
        $subscriber = $this->arrayValue($this->request('GET', '/subscriber/' . $subscriberId, []), 'Subscriber');

        $groupIds = [];
        foreach ($this->listValue($subscriber, 'GroupSubscribers') as $group) {
            if (($group['unsubscribe'] ?? false) !== true || $this->intValue($group, 'GroupId') <= 0) {
                continue;
            }

            $groupIds[] = $this->intValue($group, 'GroupId');
        }

        return $groupIds;
    }

    /**
     * @param array<array-key, mixed> $data
     * @return array<array-key, mixed>
     */
    private function arrayValue(array $data, string $key): array
    {
        return is_array($data[$key] ?? null) ? $data[$key] : [];
    }

    /**
     * @param array<array-key, mixed> $data
     * @return list<array<array-key, mixed>>
     */
    private function listValue(array $data, string $key): array
    {
        return array_values(array_filter($this->arrayValue($data, $key), is_array(...)));
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function stringValue(array $data, string $key): string
    {
        return is_string($data[$key] ?? null) ? $data[$key] : '';
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function intValue(array $data, string $key): int
    {
        return is_int($data[$key] ?? null) ? $data[$key] : 0;
    }

    /**
     * @param array<string, mixed> $options
     * @return array<array-key, mixed>
     * @throws MailvioRequestFailed
     */
    private function request(string $method, string $path, array $options): array
    {
        try {
            $response = $this->httpClient->request($method, rtrim($this->baseUrl, characters: '/') . $path, [
                ...$options,
                'headers' => ['x-access-token' => $this->apiKey],
                'timeout' => 10,
            ]);
            $status = $response->getStatusCode();
            $body = $response->getContent(throw: false);
        } catch (ExceptionInterface $exception) {
            throw new MailvioRequestFailed(
                sprintf('Mailvio API %s %s failed: %s', $method, $path, $exception->getMessage()),
                previous: $exception,
            );
        }

        if ($status < 200 || $status >= 300) {
            throw new MailvioRequestFailed(sprintf(
                'Mailvio API %s %s answered HTTP %d: %s',
                $method,
                $path,
                $status,
                mb_strimwidth($body, start: 0, width: 300, trim_marker: '…'),
            ));
        }

        return array_filter([json_decode($body, associative: true)], is_array(...))[0] ?? [];
    }
}
