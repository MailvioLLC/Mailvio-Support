<?php

declare(strict_types=1);

namespace phpMyFAQ\Seo;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(LegacyHelpdeskRedirect::class)]
final class LegacyHelpdeskRedirectTest extends TestCase
{
    /**
     * @return array<string, array{string, string|null}>
     */
    public static function legacyUrls(): array
    {
        return [
            'portal root' => ['', LegacyHelpdeskRedirect::HOME],
            'home page' => ['en/home', LegacyHelpdeskRedirect::HOME],
            'knowledge base root' => ['en/kb', LegacyHelpdeskRedirect::HOME],
            'submit ticket' => ['en/newticket', LegacyHelpdeskRedirect::SUPPORT_TICKET],
            'submit ticket with trailing slash' => ['en/newticket/', LegacyHelpdeskRedirect::SUPPORT_TICKET],
            'article with matching title' => [
                'en/kb/articles/how-to-add-your-subscribers-in-mailvio',
                'https://support.example.com/content/9/72/en/how-to-add-your-subscribers-in-mailvio.html',
            ],
            'article copied with a date suffix' => [
                'en/kb/articles/everything-about-merge-tags-31-3-2025',
                'https://support.example.com/content/7/35/en/everything-about-merge-tags.html',
            ],
            'article whose title has punctuation' => [
                'en/kb/articles/faq-on-how-to-remove-non-opener-contacts',
                'https://support.example.com/content/4/22/en/faq-on-how-to-remove-non_opener-contacts.html',
            ],
            'article that no longer exists' => [
                'en/kb/articles/mailvio-integration-with-cloudflare',
                'search.html?search=mailvio+integration+with+cloudflare',
            ],
            'category with matching name' => [
                'en/kb/mailvio-support/subscriber-management',
                'https://support.example.com/category/9/subscriber-management.html',
            ],
            'unknown category' => ['en/kb/mailvio-support/old-category', LegacyHelpdeskRedirect::ALL_CATEGORIES],
            'image of the old help center' => ['api/publicImages/1114772000000448689', null],
            'unknown page' => ['en/signin', LegacyHelpdeskRedirect::HOME],
        ];
    }

    #[DataProvider('legacyUrls')]
    public function testMapsTheOldUrlToItsPageOnThisSite(string $legacyPath, ?string $expected): void
    {
        $redirect = new LegacyHelpdeskRedirect([
            'How to add your subscribers in Mailvio' => 'https://support.example.com/content/9/72/en/how-to-add-your-subscribers-in-mailvio.html',
            'Everything about Merge Tags' => 'https://support.example.com/content/7/35/en/everything-about-merge-tags.html',
            'FAQ on how to remove Non-opener contacts' => 'https://support.example.com/content/4/22/en/faq-on-how-to-remove-non_opener-contacts.html',
        ], ['Subscriber Management' => 'https://support.example.com/category/9/subscriber-management.html']);

        self::assertSame($expected, $redirect->targetFor($legacyPath));
    }

    public function testMatchesTitlesStoredWithHtmlEntities(): void
    {
        $redirect = new LegacyHelpdeskRedirect([
            'Why emails get delivered to Gmail&#39;s promotions tab' => 'https://support.example.com/faq.html',
        ], []);

        self::assertSame(
            'https://support.example.com/faq.html',
            $redirect->targetFor('en/kb/articles/why-emails-get-delivered-to-gmail-s-promotions-tab'),
        );
    }
}
