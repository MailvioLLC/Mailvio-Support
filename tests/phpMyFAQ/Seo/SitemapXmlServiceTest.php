<?php

declare(strict_types=1);

namespace phpMyFAQ\Seo;

use phpMyFAQ\Category;
use phpMyFAQ\Configuration;
use phpMyFAQ\CustomPage;
use phpMyFAQ\Faq\Statistics as FaqStatistics;
use PHPUnit\Framework\TestCase;

class SitemapXmlServiceTest extends TestCase
{
    private Configuration $configuration;

    private FaqStatistics $faqStatistics;

    private CustomPage $customPage;

    private Category $category;

    private SitemapXmlService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configuration = $this->createStub(Configuration::class);
        $this->faqStatistics = $this->createStub(FaqStatistics::class);
        $this->customPage = $this->createStub(CustomPage::class);
        $this->category = $this->createStub(Category::class);
        $this->category->method('setUser')->willReturnSelf();
        $this->category->method('setGroups')->willReturnSelf();

        $this->service = new SitemapXmlService(
            $this->configuration,
            $this->faqStatistics,
            $this->customPage,
            $this->category,
        );
    }

    public function testIsEnabledReturnsTrueWhenConfigEnabled(): void
    {
        $this->configuration->method('get')->willReturn(true);

        $this->assertTrue($this->service->isEnabled());
    }

    public function testIsEnabledReturnsFalseWhenConfigDisabled(): void
    {
        $this->configuration->method('get')->willReturn(false);

        $this->assertFalse($this->service->isEnabled());
    }

    public function testGenerateXmlReturnsNullWhenDisabled(): void
    {
        $this->configuration->method('get')->willReturn(false);

        $this->assertNull($this->service->generateXml());
    }

    public function testCollectUrlsReturnsFaqUrlsWithCorrectPriority(): void
    {
        $this->faqStatistics
            ->method('getTopTenData')
            ->willReturn([
                1 => [
                    'url' => 'https://example.com/faq/1',
                    'date' => '2026-01-01T00:00:00+00:00',
                    'question' => 'Test FAQ',
                    'answer' => 'Test answer',
                    'visits' => 100,
                    'last_visit' => '2026-01-01T00:00:00+00:00',
                ],
            ]);

        $this->customPage->method('getAllPages')->willReturn([]);

        $urls = $this->service->collectUrls();

        $this->assertCount(1, $urls);
        $this->assertSame('https://example.com/faq/1', $urls[0]['loc']);
        $this->assertSame('1.00', $urls[0]['priority']);
        $this->assertSame('2026-01-01T00:00:00+00:00', $urls[0]['lastmod']);
    }

    public function testCollectUrlsListsTheCategoriesAGuestCanOpen(): void
    {
        $this->faqStatistics->method('getTopTenData')->willReturn([]);
        $this->customPage->method('getAllPages')->willReturn([]);
        $this->configuration->method('getDefaultUrl')->willReturn('https://example.com/');

        $category = $this->createMock(Category::class);
        $category->expects($this->once())->method('setUser')->with(-1)->willReturnSelf();
        $category->expects($this->once())->method('setGroups')->with([-1])->willReturnSelf();
        $category
            ->expects($this->once())
            ->method('getOrderedCategories')
            ->willReturn([
                3 => ['id' => 3, 'name' => 'Getting Started with Mailvio', 'parent_id' => 0],
                7 => ['id' => 7, 'name' => 'Email Templates', 'parent_id' => 0],
            ]);
        $service = new SitemapXmlService($this->configuration, $this->faqStatistics, $this->customPage, $category);

        $urls = $service->collectUrls();

        $this->assertSame(
            [
                ['loc' => 'https://example.com/category/3/getting-started-with-mailvio.html', 'priority' => '0.80'],
                ['loc' => 'https://example.com/category/7/email-templates.html', 'priority' => '0.80'],
            ],
            $urls,
        );
    }

    public function testCollectUrlsFiltersInactiveCustomPages(): void
    {
        $this->faqStatistics->method('getTopTenData')->willReturn([]);
        $this->configuration->method('getDefaultUrl')->willReturn('https://example.com/');

        $this->customPage
            ->method('getAllPages')
            ->willReturn([
                [
                    'id' => 1,
                    'slug' => 'active-page',
                    'active' => 'y',
                    'created' => '2026-01-01 00:00:00',
                    'updated' => '2026-01-15 00:00:00',
                ],
                [
                    'id' => 2,
                    'slug' => 'inactive-page',
                    'active' => 'n',
                    'created' => '2026-01-01 00:00:00',
                    'updated' => null,
                ],
            ]);

        $urls = $this->service->collectUrls();

        $this->assertCount(1, $urls);
        $this->assertSame('https://example.com/page/active-page.html', $urls[0]['loc']);
    }

    public function testCollectUrlsBuildsCorrectCustomPageUrl(): void
    {
        $this->faqStatistics->method('getTopTenData')->willReturn([]);
        $this->configuration->method('getDefaultUrl')->willReturn('https://example.com/');

        $this->customPage
            ->method('getAllPages')
            ->willReturn([
                [
                    'id' => 1,
                    'slug' => 'my-custom-page',
                    'active' => 'y',
                    'created' => '2026-01-01 00:00:00',
                    'updated' => '2026-02-01 00:00:00',
                ],
            ]);

        $urls = $this->service->collectUrls();

        $this->assertCount(1, $urls);
        $this->assertSame('https://example.com/page/my-custom-page.html', $urls[0]['loc']);
        $this->assertSame('0.80', $urls[0]['priority']);
        $this->assertSame('2026-02-01 00:00:00', $urls[0]['lastmod']);
    }

    public function testCollectUrlsUsesCreatedDateWhenUpdatedIsNull(): void
    {
        $this->faqStatistics->method('getTopTenData')->willReturn([]);
        $this->configuration->method('getDefaultUrl')->willReturn('https://example.com/');

        $this->customPage
            ->method('getAllPages')
            ->willReturn([
                [
                    'id' => 1,
                    'slug' => 'page-no-update',
                    'active' => 'y',
                    'created' => '2026-01-01 00:00:00',
                    'updated' => null,
                ],
            ]);

        $urls = $this->service->collectUrls();

        $this->assertSame('2026-01-01 00:00:00', $urls[0]['lastmod']);
    }

    public function testGeneratedSitemapUsesTheNamespaceSearchEnginesRequire(): void
    {
        $this->configuration->method('get')->willReturn(true);
        $this->configuration->method('getTemplateSet')->willReturn('default');
        $this->faqStatistics
            ->method('getTopTenData')
            ->willReturn([
                1 => ['url' => 'https://example.com/faq/1', 'date' => '2026-01-01T00:00:00+00:00'],
            ]);
        $this->customPage->method('getAllPages')->willReturn([]);

        $document = new \DOMDocument();
        $this->assertTrue($document->loadXML((string) $this->service->generateXml()));

        // The sitemap protocol only accepts the http:// namespace URI; with https:// Google can't read the file
        $namespace = 'http://www.sitemaps.org/schemas/sitemap/0.9';
        $this->assertSame($namespace, $document->documentElement?->namespaceURI);
        $this->assertSame(
            'https://example.com/faq/1',
            $document->getElementsByTagNameNS($namespace, 'loc')->item(0)?->textContent,
        );
    }
}
