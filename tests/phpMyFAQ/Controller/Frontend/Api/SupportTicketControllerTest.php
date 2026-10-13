<?php

declare(strict_types=1);

namespace phpMyFAQ\Controller\Frontend\Api;

use phpMyFAQ\Alert\AlertNotifier;
use phpMyFAQ\Mail;
use phpMyFAQ\Session\Token;
use phpMyFAQ\User\CurrentUser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesNamespace;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\Mailer\Exception\TransportException;

#[CoversClass(SupportTicketController::class)]
#[UsesNamespace('phpMyFAQ')]
final class SupportTicketControllerTest extends ApiControllerTestCase
{
    private const array VALID_TICKET = [
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'subject' => 'Campaign stuck in sending',
        'message' => "My campaign has been sending for two days.\nCan you check it?",
        'captcha' => 'ignored-for-logged-in-user',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->configuration->getAll();
        $this->overrideConfigurationValues(['main.administrationMail' => 'support@example.com']);
    }

    public function testSendsTheTicketToTheSupportInboxWithTheVisitorAsReplyToAndInCopy(): void
    {
        $mailer = $this->createMock(Mail::class);
        $mailer->expects($this->once())->method('setReplyTo')->with('jane@example.com', 'Jane Doe');
        $mailer->expects($this->once())->method('addTo')->with('support@example.com');
        $mailer->expects($this->once())->method('addCc')->with('jane@example.com', 'Jane Doe');
        $alertNotifier = $this->createMock(AlertNotifier::class);
        $alertNotifier->expects($this->never())->method('alert');
        $mailer->expects($this->once())->method('send')->with(true);

        $response = $this->send($this->createController($mailer, $alertNotifier), self::VALID_TICKET);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame(
            'Thank you! Your message has been sent to our support team. We will reply to your email address.',
            $this->payload($response)['success'],
        );
        self::assertSame('Campaign stuck in sending', $mailer->subject);
        self::assertStringContainsString('Mailvio account email: jane@example.com', $mailer->message);
        self::assertStringContainsString(
            "My campaign has been sending for two days.<br />\nCan you check it?",
            $mailer->message,
        );
    }

    public function testEscapesHtmlInTheTicket(): void
    {
        $mailer = $this->createMock(Mail::class);
        $mailer->expects($this->once())->method('send');

        $this->send($this->createController($mailer), [
            ...self::VALID_TICKET,
            'name' => '<b>Jane</b>',
            'message' => '<script>alert(1)</script>',
        ]);

        self::assertStringNotContainsString('<script>', $mailer->message);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $mailer->message);
        self::assertStringContainsString('&lt;b&gt;Jane&lt;/b&gt;', $mailer->message);
    }

    public function testLineBreaksCannotInjectMailHeadersThroughTheSubject(): void
    {
        $mailer = $this->createMock(Mail::class);
        $mailer->expects($this->once())->method('send');

        $this->send($this->createController($mailer), [
            ...self::VALID_TICKET,
            'subject' => "Hello\r\nBcc: victim@example.com",
        ]);

        self::assertSame('Hello Bcc: victim@example.com', $mailer->subject);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function incompleteTickets(): array
    {
        return [
            'missing name' => ['name', ''],
            'invalid email' => ['email', 'not-an-email'],
            'missing subject' => ['subject', '   '],
            'missing message' => ['message', ''],
        ];
    }

    #[DataProvider('incompleteTickets')]
    public function testRefusesAnIncompleteTicket(string $field, string $value): void
    {
        $mailer = $this->createMock(Mail::class);
        $mailer->expects($this->never())->method('send');

        $response = $this->send($this->createController($mailer), [...self::VALID_TICKET, $field => $value]);

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertSame('Please fill in all fields with a valid email address.', $this->payload($response)['error']);
    }

    public function testRefusesATicketWithoutAValidCsrfToken(): void
    {
        $mailer = $this->createMock(Mail::class);
        $mailer->expects($this->never())->method('send');
        $controller = $this->createController($mailer);
        $this->injectControllerState($controller, $this->createAuthenticatedUserMock(), $this->createSession());

        $request = Request::create('/api/support-ticket', 'POST', content: json_encode([
            ...self::VALID_TICKET,
            'pmf-csrf-token' => 'forged',
        ], JSON_THROW_ON_ERROR));

        self::assertSame(Response::HTTP_UNAUTHORIZED, $controller->create($request)->getStatusCode());
    }

    public function testRefusesABodyThatIsNotAJsonObject(): void
    {
        $mailer = $this->createMock(Mail::class);
        $mailer->expects($this->never())->method('send');
        $controller = $this->createController($mailer);
        $this->injectControllerState($controller, $this->createAuthenticatedUserMock(), $this->createSession());

        $response = $controller->create(Request::create('/api/support-ticket', 'POST', content: 'not json'));

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testRefusesAGuestTicketWithAWrongCaptcha(): void
    {
        $mailer = $this->createMock(Mail::class);
        $mailer->expects($this->never())->method('send');

        $guest = $this->createStub(CurrentUser::class);
        $guest->method('isLoggedIn')->willReturn(false);

        $response = $this->send(
            $this->createController($mailer),
            [...self::VALID_TICKET, 'captcha' => 'wrong'],
            $guest,
        );

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertSame('Please enter the captcha code', $this->payload($response)['error']);
    }

    public function testTellsTheVisitorWhereToWriteWhenSendingFails(): void
    {
        $mailer = $this->createStub(Mail::class);
        $mailer->method('send')->willThrowException(new TransportException('Connection refused'));

        $response = $this->send($this->createController($mailer), self::VALID_TICKET);

        self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $response->getStatusCode());
        self::assertSame(
            "We couldn't send your message. Please email us at support@example.com instead while we work on fixing this.",
            $this->payload($response)['error'],
        );
    }

    public function testAlertsTheTeamWhenTheMailServerRefusesTheTicket(): void
    {
        $mailer = $this->createStub(Mail::class);
        $mailer
            ->method('send')
            ->willThrowException(
                new TransportException(
                    'Expected response code "250" but got code "500", with message "500 No Valid Mailserver Found".',
                ),
            );
        $alertNotifier = $this->createMock(AlertNotifier::class);
        $alertNotifier
            ->expects($this->once())
            ->method('alert')
            ->with(SupportTicketController::ALERT_TOPIC, self::logicalAnd(
                self::stringContains('A support ticket could not be sent to support@example.com'),
                self::stringContains('500 No Valid Mailserver Found'),
                self::stringContains(
                    "```text\nName: Jane Doe\nEmail: jane@example.com\nSubject: Campaign stuck in sending",
                ),
                self::stringContains('My campaign has been sending for two days.'),
            ));

        $this->send($this->createController($mailer, $alertNotifier), self::VALID_TICKET);
    }

    public function testTheVisitorCannotBreakOutOfTheAlertCodeBlock(): void
    {
        $mailer = $this->createStub(Mail::class);
        $mailer->method('send')->willThrowException(new TransportException('Connection refused'));
        $alertNotifier = $this->createMock(AlertNotifier::class);
        $alertNotifier
            ->expects($this->once())
            ->method('alert')
            ->with(
                self::anything(),
                self::callback(
                    static fn(string $alert): bool => (
                        substr_count($alert, '```') === 2
                        && str_contains($alert, "'''\n@**all**")
                    ),
                ),
            );

        $this->send($this->createController($mailer, $alertNotifier), [
            ...self::VALID_TICKET,
            'message' => "```\n@**all**",
        ]);
    }

    public function testLimitsTheNumberOfTicketsPerVisitor(): void
    {
        $mailer = $this->createStub(Mail::class);
        $controller = $this->createController($mailer);

        for ($ticket = 1; $ticket <= 5; ++$ticket) {
            self::assertSame(Response::HTTP_OK, $this->send($controller, self::VALID_TICKET)->getStatusCode());
        }

        self::assertSame(
            Response::HTTP_TOO_MANY_REQUESTS,
            $this->send($controller, self::VALID_TICKET)->getStatusCode(),
        );
    }

    private function createController(Mail $mailer, ?AlertNotifier $alertNotifier = null): SupportTicketController
    {
        return new SupportTicketController($mailer, $alertNotifier ?? $this->createStub(AlertNotifier::class));
    }

    /**
     * @param array<string, string> $ticket
     */
    private function send(SupportTicketController $controller, array $ticket, ?CurrentUser $user = null): Response
    {
        [$session, $csrfToken] = $this->createValidCsrfSession();
        $this->injectControllerState($controller, $user ?? $this->createAuthenticatedUserMock(), $session);

        return $controller->create(Request::create('/api/support-ticket', 'POST', content: json_encode([
            ...$ticket,
            'pmf-csrf-token' => $csrfToken,
        ], JSON_THROW_ON_ERROR)));
    }

    /**
     * @return array{0: Session, 1: string}
     */
    private function createValidCsrfSession(): array
    {
        Token::resetInstanceForTests();
        $session = $this->createSession();
        $csrfToken = Token::getInstance($session)->getTokenString(SupportTicketController::CSRF_PAGE);
        $_COOKIE[sprintf('%s-%s', Token::PMF_SESSION_NAME, substr(md5(SupportTicketController::CSRF_PAGE), 0, 10))] =
            $csrfToken;

        return [$session, $csrfToken];
    }

    /**
     * @return array<string, string>
     */
    private function payload(Response $response): array
    {
        return json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }
}
