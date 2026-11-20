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

/**
 * Mailvio refuses a transactional mail to an address that unsubscribed from its "Transactional Emails"
 * group. Re-subscribing sets the subscriber active again in every group they left, that one included.
 *
 * @see https://github.com/MailvioLLC/mailvio-api/tree/master/docs/API
 */
final readonly class MailvioApiSubscriptions implements Subscriptions
{
    public function __construct(
        private MailvioApi $api,
    ) {
    }

    public function resubscribe(string $emailAddress): void
    {
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
                $this->api->request('POST', sprintf('/group/%d/subscriber/changeStatus', $groupId), [
                    'json' => ['subscriberIds' => [$subscriberId], 'newStatus' => 'active'],
                ]);
            }
        }
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
            $matches = array_values(array_filter($this->listValue($this->api->request('GET', '/subscriber', [
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
        $subscriber = $this->arrayValue($this->api->request('GET', '/subscriber/' . $subscriberId, []), 'Subscriber');

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
}
