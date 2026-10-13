<?php

declare(strict_types=1);

namespace phpMyFAQ\Controller\Frontend;

use phpMyFAQ\Functional\ControllerWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use Symfony\Component\HttpFoundation\Response;

#[CoversClass(LegacyHelpdeskController::class)]
#[UsesNamespace('phpMyFAQ')]
#[UsesClass(AbstractFrontController::class)]
final class LegacyHelpdeskControllerWebTest extends ControllerWebTestCase
{
    public function testRedirectsTheOldHomePageToTheStartPage(): void
    {
        $response = $this->requestPublic('GET', '/portal/en/home');

        self::assertSame(Response::HTTP_MOVED_PERMANENTLY, $response->getStatusCode());
        self::assertStringEndsWith('/', (string) $response->headers->get('Location'));
        self::assertStringNotContainsString('portal', (string) $response->headers->get('Location'));
    }

    public function testRedirectsTheOldTicketFormToTheSupportTicketTab(): void
    {
        $response = $this->requestPublic('GET', '/portal/en/newticket');

        self::assertSame(Response::HTTP_MOVED_PERMANENTLY, $response->getStatusCode());
        self::assertStringEndsWith('/add-question.html#support-ticket', (string) $response->headers->get('Location'));
    }

    public function testRedirectsAnUnknownOldArticleToASearchForItsTitle(): void
    {
        $response = $this->requestPublic('GET', '/portal/en/kb/articles/an-article-that-never-existed');

        self::assertSame(Response::HTTP_MOVED_PERMANENTLY, $response->getStatusCode());
        self::assertStringEndsWith(
            '/search.html?search=an+article+that+never+existed',
            (string) $response->headers->get('Location'),
        );
    }

    public function testOldImagesStayNotFound(): void
    {
        $response = $this->requestPublic('GET', '/portal/api/publicImages/1114772000000448689');

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }
}
