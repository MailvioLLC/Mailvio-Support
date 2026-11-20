<?php

declare(strict_types=1);

namespace phpMyFAQ\Controller\Frontend\Api;

use phpMyFAQ\Alert\AlertNotifier;
use phpMyFAQ\Mailvio\MailvioRequestFailed;
use phpMyFAQ\Mailvio\RecipientUnsubscribed;
use phpMyFAQ\Mailvio\Subscriptions;
use phpMyFAQ\Mailvio\TransactionalMail;
use phpMyFAQ\Mailvio\TransactionalMailer;
use phpMyFAQ\Session\Token;
use phpMyFAQ\User\CurrentUser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesNamespace;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;

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

    private const string SEND_FAILED = "We couldn't send your message. Please email us at support@example.com instead while we work on fixing this.";

    protected function setUp(): void
    {
        parent::setUp();

        $this->configuration->getAll();
        $this->overrideConfigurationValues([
            'main.administrationMail' => 'support@example.com',
            'main.titleFAQ' => 'Mailvio Support',
        ]);
    }

    public function testSendsTheVisitorACopyAndTheSupportInboxAMailThatRepliesToTheVisitor(): void
    {
        $mailer = $this->recordingMailer();
        $alertNotifier = $this->createMock(AlertNotifier::class);
        $alertNotifier->expects($this->never())->method('alert');

        $response = $this->send($this->createController($mailer, $alertNotifier), self::VALID_TICKET);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame(
            'Thank you! Your message has been sent to our support team. We will reply to your email address.',
            $this->payload($response)['success'],
        );
        self::assertCount(2, $mailer->sent);
        [$visitorCopy, $supportCopy] = $mailer->sent;

        self::assertSame([
            'support@example.com',
            'Mailvio Support',
            'support@example.com',
            'jane@example.com',
            'Jane Doe',
        ], [
            $visitorCopy->fromAddress,
            $visitorCopy->fromName,
            $visitorCopy->replyAddress,
            $visitorCopy->toAddress,
            $visitorCopy->toName,
        ]);
        self::assertSame([
            'support@example.com',
            'Jane Doe',
            'jane@example.com',
            'support@example.com',
            'Mailvio Support',
        ], [
            $supportCopy->fromAddress,
            $supportCopy->fromName,
            $supportCopy->replyAddress,
            $supportCopy->toAddress,
            $supportCopy->toName,
        ]);
        self::assertSame('Campaign stuck in sending', $supportCopy->subject);
        self::assertStringContainsString('Mailvio account email: jane@example.com', $supportCopy->html);
        self::assertStringContainsString(
            "My campaign has been sending for two days.<br />\nCan you check it?",
            $supportCopy->html,
        );
        self::assertSame($supportCopy->html, $visitorCopy->html);
    }

    public function testEscapesHtmlInTheTicket(): void
    {
        $mailer = $this->recordingMailer();

        $this->send($this->createController($mailer), [
            ...self::VALID_TICKET,
            'name' => '<b>Jane</b>',
            'message' => '<script>alert(1)</script>',
        ]);

        $html = $mailer->sent[1]->html;
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringContainsString('&lt;b&gt;Jane&lt;/b&gt;', $html);
    }

    public function testLineBreaksAreRemovedFromTheSubject(): void
    {
        $mailer = $this->recordingMailer();

        $this->send($this->createController($mailer), [
            ...self::VALID_TICKET,
            'subject' => "Hello\r\nBcc: victim@example.com",
        ]);

        self::assertSame('Hello Bcc: victim@example.com', $mailer->sent[1]->subject);
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
        $mailer = $this->recordingMailer();

        $response = $this->send($this->createController($mailer), [...self::VALID_TICKET, $field => $value]);

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertSame('Please fill in all fields with a valid email address.', $this->payload($response)['error']);
        self::assertSame([], $mailer->sent);
    }

    public function testRefusesATicketWithoutAValidCsrfToken(): void
    {
        $mailer = $this->recordingMailer();
        $controller = $this->createController($mailer);
        $this->injectControllerState($controller, $this->createAuthenticatedUserMock(), $this->createSession());

        $request = Request::create('/api/support-ticket', 'POST', content: json_encode([
            ...self::VALID_TICKET,
            'pmf-csrf-token' => 'forged',
        ], JSON_THROW_ON_ERROR));

        self::assertSame(Response::HTTP_UNAUTHORIZED, $controller->create($request)->getStatusCode());
        self::assertSame([], $mailer->sent);
    }

    public function testRefusesABodyThatIsNotAJsonObject(): void
    {
        $controller = $this->createController($this->recordingMailer());
        $this->injectControllerState($controller, $this->createAuthenticatedUserMock(), $this->createSession());

        $response = $controller->create(Request::create('/api/support-ticket', 'POST', content: 'not json'));

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testRefusesAGuestTicketWithAWrongCaptcha(): void
    {
        $mailer = $this->recordingMailer();

        $guest = $this->createStub(CurrentUser::class);
        $guest->method('isLoggedIn')->willReturn(false);

        $response = $this->send(
            $this->createController($mailer),
            [...self::VALID_TICKET, 'captcha' => 'wrong'],
            $guest,
        );

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertSame('Please enter the captcha code', $this->payload($response)['error']);
        self::assertSame([], $mailer->sent);
    }

    public function testAsksForTheSecurityCheckInsteadOfACodeWhenTurnstileRefusesAGuest(): void
    {
        $this->overrideConfigurationValues(['security.enableTurnstile' => true]);
        $mailer = $this->recordingMailer();

        $guest = $this->createStub(CurrentUser::class);
        $guest->method('isLoggedIn')->willReturn(false);

        $response = $this->send(
            $this->createController($mailer),
            [...self::VALID_TICKET, 'cf-turnstile-response' => ''],
            $guest,
        );

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertSame(
            "We couldn't confirm you're human. Please complete the security check and try again.",
            $this->payload($response)['error'],
        );
        self::assertSame([], $mailer->sent);
    }

    public function testTellsTheVisitorWhereToWriteWhenSendingFails(): void
    {
        $mailer = $this->recordingMailer(new MailvioRequestFailed('Mailvio API POST /transaction failed: timeout'));

        $response = $this->send($this->createController($mailer), self::VALID_TICKET);

        self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $response->getStatusCode());
        self::assertSame(self::SEND_FAILED, $this->payload($response)['error']);
    }

    public function testAlertsTheTeamWhenMailvioRefusesTheTicket(): void
    {
        $mailer = $this->recordingMailer(
            null,
            new MailvioRequestFailed('Mailvio API POST /transaction answered HTTP 422: {"error":"Invalid sender"}'),
        );
        $alertNotifier = $this->createMock(AlertNotifier::class);
        $alertNotifier
            ->expects($this->once())
            ->method('alert')
            ->with(SupportTicketController::ALERT_TOPIC, self::logicalAnd(
                self::stringContains('A support ticket could not be sent to support@example.com'),
                self::stringContains('Invalid sender'),
                self::stringContains(
                    "```text\nName: Jane Doe\nEmail: jane@example.com\nSubject: Campaign stuck in sending",
                ),
                self::stringContains('My campaign has been sending for two days.'),
            ));

        $this->send($this->createController($mailer, $alertNotifier), self::VALID_TICKET);
    }

    public function testTheVisitorCannotBreakOutOfTheAlertCodeBlock(): void
    {
        $mailer = $this->recordingMailer(new MailvioRequestFailed('timeout'));
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
        $controller = $this->createController($this->recordingMailer());

        for ($ticket = 1; $ticket <= 5; ++$ticket) {
            self::assertSame(Response::HTTP_OK, $this->send($controller, self::VALID_TICKET)->getStatusCode());
        }

        self::assertSame(
            Response::HTTP_TOO_MANY_REQUESTS,
            $this->send($controller, self::VALID_TICKET)->getStatusCode(),
        );
    }

    public function testAsksToResubscribeAndSendsNothingToSupportWhenTheVisitorUnsubscribed(): void
    {
        $mailer = $this->recordingMailer($this->unsubscribed());
        $alertNotifier = $this->createMock(AlertNotifier::class);
        $alertNotifier->expects($this->never())->method('alert');

        $response = $this->send($this->createController($mailer, $alertNotifier), self::VALID_TICKET);

        self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
        $payload = $this->payload($response);
        self::assertSame(
            'You previously unsubscribed with your email jane@example.com, hence we can not reply to your emails. '
            . 'Please click here to re-subscribe to Mailvio emails or directly email us at support@example.com.',
            $payload['error'],
        );
        self::assertSame('jane@example.com', $payload['unsubscribed']['email']);
        self::assertSame('click here to re-subscribe', $payload['unsubscribed']['resubscribeLabel']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $payload['unsubscribed']['ticketId']);
        self::assertSame([], $mailer->sent, 'The support copy must not go out without the visitor');
    }

    public function testResubscribesTheHeldAddressAndSendsTheHeldTicket(): void
    {
        $session = $this->createSession();
        $ticketId = $this->holdUnsubscribedTicket($session);

        $subscriptions = $this->createMock(Subscriptions::class);
        $subscriptions->expects($this->once())->method('resubscribe')->with('jane@example.com');
        $mailer = $this->recordingMailer();

        $response = $this->resubscribe($this->createController($mailer, subscriptions: $subscriptions), $session, [
            'ticketId' => $ticketId,
            'email' => 'someone-else@example.com',
        ]);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame(
            'You are subscribed to Mailvio emails again, and your message has been sent to our support team. '
            . 'We will reply to your email address.',
            $this->payload($response)['success'],
        );
        self::assertCount(2, $mailer->sent);
        self::assertSame('jane@example.com', $mailer->sent[0]->toAddress);
        self::assertSame('jane@example.com', $mailer->sent[1]->replyAddress);
        self::assertSame('Campaign stuck in sending', $mailer->sent[1]->subject);
        self::assertStringContainsString('My campaign has been sending for two days.', $mailer->sent[1]->html);
    }

    public function testSendsAHeldTicketOnlyOnce(): void
    {
        $session = $this->createSession();
        $ticketId = $this->holdUnsubscribedTicket($session);
        $controller = $this->createController($this->recordingMailer());

        $this->resubscribe($controller, $session, ['ticketId' => $ticketId]);
        $response = $this->resubscribe($controller, $session, ['ticketId' => $ticketId]);

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        self::assertSame(
            'Your message is no longer waiting to be sent. Please send it again.',
            $this->payload($response)['error'],
        );
    }

    public function testRefusesToResubscribeWithoutAHeldTicket(): void
    {
        $subscriptions = $this->createMock(Subscriptions::class);
        $subscriptions->expects($this->never())->method('resubscribe');

        $response = $this->resubscribe(
            $this->createController($this->recordingMailer(), subscriptions: $subscriptions),
            $this->createSession(),
            ['ticketId' => 'unknown', 'email' => 'victim@example.com'],
        );

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    public function testRefusesToResubscribeWithoutAValidCsrfToken(): void
    {
        $session = $this->createSession();
        $ticketId = $this->holdUnsubscribedTicket($session);
        $subscriptions = $this->createMock(Subscriptions::class);
        $subscriptions->expects($this->never())->method('resubscribe');
        $controller = $this->createController($this->recordingMailer(), subscriptions: $subscriptions);
        $this->injectControllerState($controller, $this->createAuthenticatedUserMock(), $session);

        $response = $controller->resubscribe(Request::create(
            '/api/support-ticket/resubscribe',
            'POST',
            content: json_encode(['ticketId' => $ticketId, 'pmf-csrf-token' => 'forged'], JSON_THROW_ON_ERROR),
        ));

        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    public function testAlertsTheTeamAndSendsNothingWhenResubscribingFails(): void
    {
        $session = $this->createSession();
        $ticketId = $this->holdUnsubscribedTicket($session);
        $subscriptions = $this->createStub(Subscriptions::class);
        $subscriptions->method('resubscribe')->willThrowException(new MailvioRequestFailed('Mailvio API answered 401'));
        $mailer = $this->recordingMailer();
        $alertNotifier = $this->createMock(AlertNotifier::class);
        $alertNotifier
            ->expects($this->once())
            ->method('alert')
            ->with(SupportTicketController::ALERT_TOPIC, self::logicalAnd(
                self::stringContains('Mailvio API answered 401'),
                self::stringContains('Email: jane@example.com'),
            ));

        $response = $this->resubscribe($this->createController($mailer, $alertNotifier, $subscriptions), $session, [
            'ticketId' => $ticketId,
        ]);

        self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $response->getStatusCode());
        self::assertSame(self::SEND_FAILED, $this->payload($response)['error']);
        self::assertSame([], $mailer->sent);
    }

    public function testRefusesAResubscribeBodyThatIsNotAJsonObject(): void
    {
        $controller = $this->createController($this->recordingMailer());
        $this->injectControllerState($controller, $this->createAuthenticatedUserMock(), $this->createSession());

        $response = $controller->resubscribe(Request::create('/api/support-ticket/resubscribe', 'POST', content: 'x'));

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testLimitsTheNumberOfResubscribeAttemptsPerVisitor(): void
    {
        $controller = $this->createController($this->recordingMailer());
        $session = $this->createSession();

        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            $this->resubscribe($controller, $session, ['ticketId' => 'unknown']);
        }

        self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $this->resubscribe($controller, $session, [
            'ticketId' => 'unknown',
        ])->getStatusCode());
    }

    private function unsubscribed(): RecipientUnsubscribed
    {
        return new RecipientUnsubscribed(
            'Mailvio refused the mail to "jane@example.com": Email Address Unsubscribed Previously',
            422,
            'Email Address Unsubscribed Previously',
        );
    }

    /**
     * A mailer that records what it sends; each given exception is thrown by the matching send call
     * instead (null sends normally).
     */
    private function recordingMailer(?MailvioRequestFailed ...$failures): RecordingTransactionalMailer
    {
        return new RecordingTransactionalMailer($failures);
    }

    private function holdUnsubscribedTicket(Session $session): string
    {
        $response = $this->send(
            $this->createController($this->recordingMailer($this->unsubscribed())),
            self::VALID_TICKET,
            session: $session,
        );

        return $this->payload($response)['unsubscribed']['ticketId'];
    }

    private function createController(
        TransactionalMailer $mailer,
        ?AlertNotifier $alertNotifier = null,
        ?Subscriptions $subscriptions = null,
    ): SupportTicketController {
        return new SupportTicketController(
            $mailer,
            $alertNotifier ?? $this->createStub(AlertNotifier::class),
            $subscriptions ?? $this->createStub(Subscriptions::class),
        );
    }

    /**
     * @param array<string, string> $ticket
     */
    private function send(
        SupportTicketController $controller,
        array $ticket,
        ?CurrentUser $user = null,
        ?Session $session = null,
    ): Response {
        [$session, $csrfToken] = $this->createValidCsrfSession($session);
        $this->injectControllerState($controller, $user ?? $this->createAuthenticatedUserMock(), $session);

        return $controller->create(Request::create('/api/support-ticket', 'POST', content: json_encode([
            ...$ticket,
            'pmf-csrf-token' => $csrfToken,
        ], JSON_THROW_ON_ERROR)));
    }

    /**
     * @param array<string, string> $data
     */
    private function resubscribe(SupportTicketController $controller, Session $session, array $data): Response
    {
        [$session, $csrfToken] = $this->createValidCsrfSession($session);
        $this->injectControllerState($controller, $this->createAuthenticatedUserMock(), $session);

        return $controller->resubscribe(Request::create('/api/support-ticket/resubscribe', 'POST', content: json_encode(
            [...$data, 'pmf-csrf-token' => $csrfToken],
            JSON_THROW_ON_ERROR,
        )));
    }

    /**
     * @return array{0: Session, 1: string}
     */
    private function createValidCsrfSession(?Session $session = null): array
    {
        Token::resetInstanceForTests();
        $session ??= $this->createSession();
        $csrfToken = Token::getInstance($session)->getTokenString(SupportTicketController::CSRF_PAGE);
        $_COOKIE[sprintf('%s-%s', Token::PMF_SESSION_NAME, substr(md5(SupportTicketController::CSRF_PAGE), 0, 10))] =
            $csrfToken;

        return [$session, $csrfToken];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Response $response): array
    {
        return json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }
}

final class RecordingTransactionalMailer implements TransactionalMailer
{
    /** @var list<TransactionalMail> */
    public array $sent = [];

    private int $calls = 0;

    /**
     * @param array<int, MailvioRequestFailed|null> $failures
     */
    public function __construct(
        private readonly array $failures,
    ) {
    }

    public function send(TransactionalMail $mail): void
    {
        $failure = $this->failures[$this->calls++] ?? null;
        if ($failure !== null) {
            throw $failure;
        }

        $this->sent[] = $mail;
    }
}
