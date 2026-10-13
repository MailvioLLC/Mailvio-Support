<?php

declare(strict_types=1);

namespace phpMyFAQ\Controller\Frontend;

use phpMyFAQ\Controller\AbstractController;
use phpMyFAQ\Functional\ControllerWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesNamespace;

#[CoversClass(SitemapXmlController::class)]
#[UsesNamespace('phpMyFAQ')]
#[UsesClass(AbstractController::class)]
final class SitemapXmlControllerWebTest extends ControllerWebTestCase
{
    public function testXmlSitemapReturnsXml(): void
    {
        $response = $this->requestPublic('GET', '/sitemap.xml');

        self::assertResponseIsSuccessful($response);
        self::assertSame('text/xml', $response->headers->get('Content-Type'));
        self::assertResponseContains('<?xml', $response);
    }

    public function testGzipSitemapReturnsGzipPayload(): void
    {
        $response = $this->requestPublic('GET', '/sitemap.gz');

        self::assertResponseIsSuccessful($response);
        self::assertSame('application/x-gzip', $response->headers->get('Content-Type'));
        self::assertSame('gzip', $response->headers->get('Content-Encoding'));
    }

    public function testXmlGzipSitemapReturnsGzipPayload(): void
    {
        $response = $this->requestPublic('GET', '/sitemap.xml.gz');

        self::assertResponseIsSuccessful($response);
        self::assertSame('application/x-gzip', $response->headers->get('Content-Type'));
        self::assertSame('gzip', $response->headers->get('Content-Encoding'));
    }
}
