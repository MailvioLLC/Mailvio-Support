import { beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('./api', () => ({
  sendSupportTicket: vi.fn(),
}));

vi.mock('./utils', () => ({
  resetTurnstile: vi.fn(),
  addElement: vi.fn((tag: string, props: Record<string, string>) => {
    const element = document.createElement(tag);
    element.className = props.classList ?? '';
    element.innerText = props.innerText ?? '';
    return element;
  }),
}));

import { handleAskTabs, handleSupportTicket } from './support-ticket';
import { sendSupportTicket } from './api';
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
        <form id="pmf-support-ticket-form" class="needs-validation">
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
      expect(
        (document.querySelector('#pmf-support-ticket-response .alert-danger') as HTMLElement | null)?.innerText
      ).toBe('Please enter the captcha code');
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

  it('shows an unexpected failure', async () => {
    vi.mocked(sendSupportTicket).mockRejectedValue(new Error('HTTP 500'));
    handleSupportTicket();

    document.getElementById('pmf-submit-support-ticket')?.click();

    await vi.waitFor(() => {
      expect((document.querySelector('.alert-danger') as HTMLElement | null)?.innerText).toBe('HTTP 500');
    });
  });
});
