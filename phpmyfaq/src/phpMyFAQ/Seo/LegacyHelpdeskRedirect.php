<?php

/**
 * Maps URLs of the former Zoho Desk help center (/portal/...) to their pages on this site.
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
 * @since     2026-10-13
 */

declare(strict_types=1);

namespace phpMyFAQ\Seo;

/**
 * Old links keep working: the home page, the ticket form, and articles and categories whose
 * title still matches the old URL slug. An article without a match leads to a search for its
 * slug words, an unknown category to the category overview.
 */
final readonly class LegacyHelpdeskRedirect
{
    public const string HOME = '';

    public const string SUPPORT_TICKET = 'add-question.html#support-ticket';

    public const string ALL_CATEGORIES = 'show-categories.html';

    /** @var array<string, string> */
    private array $faqUrlsBySlug;

    /** @var array<string, string> */
    private array $categoryUrlsBySlug;

    /**
     * @param array<array-key, string> $faqUrlsByTitle      FAQ title => URL of the FAQ; PHP turns
     *                                                    numeric titles into integer keys
     * @param array<array-key, string> $categoryUrlsByName  category name => URL of the category
     */
    public function __construct(array $faqUrlsByTitle, array $categoryUrlsByName)
    {
        $this->faqUrlsBySlug = self::keyBySlug($faqUrlsByTitle);
        $this->categoryUrlsBySlug = self::keyBySlug($categoryUrlsByName);
    }

    /**
     * @param string $legacyPath the path after /portal/, e.g. "en/kb/articles/dedicated-ip-warmup"
     * @return string|null the target, relative to the site root unless it is a full URL; null when
     *                     the old URL has no counterpart (e.g. images of the old help center)
     */
    public function targetFor(string $legacyPath): ?string
    {
        $segments = array_values(array_filter(
            explode(separator: '/', string: strtolower(trim($legacyPath, characters: '/'))),
            static fn(string $segment): bool => $segment !== '',
        ));

        if (($segments[0] ?? '') === 'api') {
            return null;
        }

        if (preg_match(pattern: '/^[a-z]{2}(-[a-z]{2})?$/', subject: $segments[0] ?? '') === 1) {
            array_shift($segments);
        }

        return match (true) {
            ($segments[0] ?? '') === 'newticket' => self::SUPPORT_TICKET,
            ($segments[0] ?? '') === 'kb' && ($segments[1] ?? '') === 'articles' && array_key_exists(2, $segments)
                => $this->articleTarget($segments[2]),
            ($segments[0] ?? '') === 'kb' && array_key_exists(2, $segments) => $this->categoryTarget($segments[2]),
            default => self::HOME,
        };
    }

    private function articleTarget(string $slug): string
    {
        $slug = self::slug($slug);

        return (
            $this->faqUrlsBySlug[$slug]
            ?? 'search.html?search=' . urlencode(str_replace(search: '-', replace: ' ', subject: $slug))
        );
    }

    private function categoryTarget(string $slug): string
    {
        return $this->categoryUrlsBySlug[self::slug($slug)] ?? self::ALL_CATEGORIES;
    }

    /**
     * @param array<array-key, string> $urlsByTitle
     * @return array<string, string>
     */
    private static function keyBySlug(array $urlsByTitle): array
    {
        $urlsBySlug = [];
        foreach ($urlsByTitle as $title => $url) {
            $urlsBySlug[self::slug((string) $title)] ??= $url;
        }

        return $urlsBySlug;
    }

    /**
     * Lowercase words joined by dashes. Zoho Desk appended a date to copied articles
     * ("everything-about-merge-tags-31-3-2025"), which is dropped.
     */
    private static function slug(string $title): string
    {
        $slug = strtolower(html_entity_decode($title, ENT_QUOTES | ENT_HTML5, encoding: 'UTF-8'));
        $slug = trim((string) preg_replace(pattern: '/[^a-z0-9]+/', replacement: '-', subject: $slug), characters: '-');

        return (string) preg_replace(pattern: '/-\d{1,2}-\d{1,2}-\d{4}$/', replacement: '', subject: $slug);
    }
}
