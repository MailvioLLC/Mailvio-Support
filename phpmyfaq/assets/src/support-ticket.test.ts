import { beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('./api', () => ({
  sendSupportTicket: vi.fn(),
  resubscribeSupportTicket: vi.fn(),
}));

vi.mock('./utils', async () => ({
  resetTurnstile: vi.fn(),
  addElement: (await vi.importActual<typeof import('./utils/helper')>('./utils/helper')).addElement,
}));

import { handleAskTabs, handleSupportTicket } from './support-ticket';
import { resubscribeSupportTicket, sendSupportTicket } from './api';
import { resetTurnstile } from './utils';

const renderPage = (): void => {
  document.body.innerHTML = `
    <ul class="nav nav-tabs" id="pmf-ask-tabs" role="tablist">
      <li><button class="nav-link active" id="pmf-tab-public-question" data-bs-toggle="tab"
                  data-bs-target="#public-question">Public</button></li>
      <li><button class="nav-link" id="pmf-tab-support-ticket" data-bs-toggle="tab"
                  data-bs-target="#support-ticket">Ticket</button></li>
    </ul>
    <div class="tab-content">
      <div class="tab-pane active show" id="public-question">
        <a href="#support-ticket" class="pmf-open-support-ticket">Open a support ticket instead</a>
      </div>
      <div class="tab-pane" id="support-ticket">
        <div id="pmf-support-ticket-response"></div>
        <form id="pmf-support-ticket-form" class="needs-validation" data-send-failed="Please email us at support@example.com instead.">
          <input type="hidden" name="pmf-csrf-token" value="csrf-123">
          <input name="name" value="Jane" required>
          <input name="email" value="jane@example.com" required>
          <input name="subject" value="Help" required>
          <textarea name="message" required>It broke</textarea>
          <div class="cf-turnstile"></div>
        </form>
        <button id="pmf-submit-support-ticket">Send</button>
      </div>
    </div>
  `;
};

describe('handleAskTabs', () => {
  beforeEach(() => {
    renderPage();
    window.history.replaceState(null, '', '/add-question.html');
  });

  it('opens the support ticket tab when the page is linked with #support-ticket', () => {
    window.history.replaceState(null, '', '/add-question.html#support-ticket');

    handleAskTabs();

    expect(document.getElementById('pmf-tab-support-ticket')?.classList.contains('active')).toBe(true);
    expect(document.getElementById('support-ticket')?.classList.contains('active')).toBe(true);
  });

  it('opens the support ticket tab from the hint in the public question tab', () => {
    handleAskTabs();

    (document.querySelector('.pmf-open-support-ticket') as HTMLAnchorElement).click();

    expect(document.getElementById('pmf-tab-support-ticket')?.classList.contains('active')).toBe(true);
    expect(window.location.hash).toBe('#support-ticket');
  });

  it('keeps the public question tab open by default', () => {
    handleAskTabs();

    expect(document.getElementById('pmf-tab-public-question')?.classList.contains('active')).toBe(true);
  });
});

describe('handleSupportTicket', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    renderPage();
  });

  it('replaces the form with the confirmation once the ticket is sent', async () => {
    vi.mocked(sendSupportTicket).mockResolvedValue({ success: 'Your message has been sent' });
    handleSupportTicket();

    document.getElementById('pmf-submit-support-ticket')?.click();

    await vi.waitFor(() => {
      expect(document.querySelector('#pmf-support-ticket-response .alert-success')).not.toBeNull();
    });
    expect(document.getElementById('pmf-support-ticket-form')?.classList.contains('d-none')).toBe(true);
    expect(resetTurnstile).toHaveBeenCalledWith(document.getElementById('pmf-support-ticket-form'));
  });

  it('shows the error and keeps the form when the ticket is rejected', async () => {
    vi.mocked(sendSupportTicket).mockResolvedValue({ error: 'Please enter the captcha code' });
    handleSupportTicket();

    document.getElementById('pmf-submit-support-ticket')?.click();

    await vi.waitFor(() => {
      expect(document.querySelector('#pmf-support-ticket-response .alert-danger')?.textContent).toBe(
        'Please enter the captcha code'
      );
    });
    expect(document.getElementById('pmf-support-ticket-form')?.classList.contains('d-none')).toBe(false);
    expect((document.getElementById('pmf-submit-support-ticket') as HTMLButtonElement).disabled).toBe(false);
    expect(resetTurnstile).toHaveBeenCalled();
  });

  it('shows only the latest message after a retry', async () => {
    vi.mocked(sendSupportTicket)
      .mockResolvedValueOnce({ error: 'Please enter the captcha code' })
      .mockResolvedValueOnce({ success: 'Your message has been sent' });
    handleSupportTicket();
    const submit = document.getElementById('pmf-submit-support-ticket') as HTMLButtonElement;

    submit.click();
    await vi.waitFor(() => expect(document.querySelector('.alert-danger')).not.toBeNull());
    submit.click();
    await vi.waitFor(() => expect(document.querySelector('.alert-success')).not.toBeNull());

    expect(document.querySelector('.alert-danger')).toBeNull();
  });

  it('does not send an invalid form', () => {
    handleSupportTicket();
    (document.querySelector('[name="subject"]') as HTMLInputElement).value = '';

    document.getElementById('pmf-submit-support-ticket')?.click();

    expect(sendSupportTicket).not.toHaveBeenCalled();
    expect(document.getElementById('pmf-support-ticket-form')?.classList.contains('was-validated')).toBe(true);
  });

  it('shows the send-failed message instead of a technical error', async () => {
    vi.mocked(sendSupportTicket).mockRejectedValue(new Error('HTTP 502'));
    handleSupportTicket();

    document.getElementById('pmf-submit-support-ticket')?.click();

    await vi.waitFor(() => {
      expect(document.querySelector('.alert-danger')?.textContent).toBe(
        'Please email us at support@example.com instead.'
      );
    });
  });

  it('links the support email address in an error', async () => {
    vi.mocked(sendSupportTicket).mockResolvedValue({
      error: 'Please email us at support@example.com instead. <b>not html</b>',
    });
    handleSupportTicket();

    document.getElementById('pmf-submit-support-ticket')?.click();

    await vi.waitFor(() => expect(document.querySelector('.alert-danger a')).not.toBeNull());
    const link = document.querySelector('.alert-danger a') as HTMLAnchorElement;
    expect(link.getAttribute('href')).toBe('mailto:support@example.com');
    expect(link.classList.contains('alert-link')).toBe(true);
    expect(document.querySelector('.alert-danger b')).toBeNull();
    expect(document.querySelector('.alert-danger')?.textContent).toBe(
      'Please email us at support@example.com instead. <b>not html</b>'
    );
  });

  describe('when Mailvio refused the address as unsubscribed', () => {
    const unsubscribedResponse = {
      error:
        'You previously unsubscribed with your email jane@example.com, hence we can not reply to your emails. ' +
        'Please click here to re-subscribe to Mailvio emails or directly email us at support@example.com.',
      unsubscribed: { email: 'jane@example.com', ticketId: 'ticket-1', resubscribeLabel: 'click here to re-subscribe' },
    };

    const submitUnsubscribed = async (): Promise<HTMLButtonElement> => {
      vi.mocked(sendSupportTicket).mockResolvedValue(unsubscribedResponse);
      handleSupportTicket();
      document.getElementById('pmf-submit-support-ticket')?.click();
      await vi.waitFor(() => expect(document.getElementById('pmf-support-ticket-resubscribe')).not.toBeNull());

      return document.getElementById('pmf-support-ticket-resubscribe') as HTMLButtonElement;
    };

    it('explains the unsubscribe and offers a re-subscribe link instead of blaming the site', async () => {
      const button = await submitUnsubscribed();

      const alert = document.querySelector('#pmf-support-ticket-response .alert-warning') as HTMLElement;
      expect(alert.textContent).toBe(unsubscribedResponse.error);
      expect(button.textContent).toBe('click here to re-subscribe');
      expect(alert.querySelector('strong')?.textContent).toBe('jane@example.com');
      expect(alert.querySelector('a')?.getAttribute('href')).toBe('mailto:support@example.com');
      expect(document.querySelector('.alert-danger')).toBeNull();
    });

    it('re-subscribes, sends the waiting ticket and shows the confirmation', async () => {
      vi.mocked(resubscribeSupportTicket).mockResolvedValue({ success: 'Subscribed again and sent' });
      const button = await submitUnsubscribed();

      button.click();

      await vi.waitFor(() => {
        expect(document.querySelector('#pmf-support-ticket-response .alert-success')?.textContent).toBe(
          'Subscribed again and sent'
        );
      });
      expect(resubscribeSupportTicket).toHaveBeenCalledWith('csrf-123', 'ticket-1');
      expect(document.querySelector('.alert-warning')).toBeNull();
      expect(document.getElementById('pmf-support-ticket-form')?.classList.contains('d-none')).toBe(true);
    });

    it('shows the error when re-subscribing fails', async () => {
      vi.mocked(resubscribeSupportTicket).mockResolvedValue({ error: 'Please email us at support@example.com.' });
      const button = await submitUnsubscribed();

      button.click();

      await vi.waitFor(() => {
        expect(document.querySelector('.alert-danger')?.textContent).toBe('Please email us at support@example.com.');
      });
      expect(document.getElementById('pmf-support-ticket-form')?.classList.contains('d-none')).toBe(false);
    });

    it('shows the send-failed message when the re-subscribe request breaks', async () => {
      vi.mocked(resubscribeSupportTicket).mockRejectedValue(new Error('HTTP 502'));
      const button = await submitUnsubscribed();

      button.click();

      await vi.waitFor(() => {
        expect(document.querySelector('.alert-danger')?.textContent).toBe(
          'Please email us at support@example.com instead.'
        );
      });
    });
  });
});
