/**
 * Support ticket form and the tabs of the ask-question page
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

import { Tab } from 'bootstrap';
import { resubscribeSupportTicket, sendSupportTicket, UnsubscribedTicket } from './api';
import { addElement, resetTurnstile } from './utils';

const SUPPORT_TICKET_HASH = '#support-ticket';
const EMAIL_ADDRESS = /([^\s@]+@[^\s@]+\.[a-z]{2,})/i;

/**
 * Text whose email addresses are mailto links, so visitors can write to support in one click; the
 * visitor's own address is only emphasised. Built from text nodes: never parsed as HTML.
 */
const linkedText = (text: string, ownAddress = ''): Node[] =>
  text.split(EMAIL_ADDRESS).map((part: string, index: number): Node => {
    if (index % 2 === 0) {
      return document.createTextNode(part);
    }

    return part.toLowerCase() === ownAddress.toLowerCase()
      ? addElement('strong', { textContent: part })
      : addElement('a', { href: `mailto:${part}`, classList: 'alert-link', textContent: part });
  });

const errorAlert = (text: string): HTMLElement =>
  addElement('div', { classList: 'alert alert-danger' }, linkedText(text));

const successAlert = (text: string): HTMLElement =>
  addElement('div', { classList: 'alert alert-success', textContent: text });

/**
 * Alert for an address that unsubscribed from Mailvio emails: its re-subscribe link sends the
 * waiting ticket.
 */
const unsubscribedAlert = (text: string, ticket: UnsubscribedTicket, onResubscribe: () => void): HTMLElement => {
  const labelStart = text.indexOf(ticket.resubscribeLabel);
  if (labelStart === -1) {
    return errorAlert(text);
  }

  const button = addElement('button', {
    type: 'button',
    id: 'pmf-support-ticket-resubscribe',
    classList: 'btn btn-link alert-link p-0 align-baseline text-decoration-underline',
    textContent: ticket.resubscribeLabel,
  });
  button.addEventListener('click', onResubscribe, { once: true });

  return addElement('div', { classList: 'alert alert-warning' }, [
    ...linkedText(text.slice(0, labelStart), ticket.email),
    button,
    ...linkedText(text.slice(labelStart + ticket.resubscribeLabel.length), ticket.email),
  ]);
};

/**
 * Opens the support ticket tab for links to …/add-question.html#support-ticket and for the
 * "open a support ticket instead" hint, and keeps the address in sync with the open tab.
 */
export const handleAskTabs = (): void => {
  const ticketTab = document.getElementById('pmf-tab-support-ticket');
  if (!ticketTab) {
    return;
  }

  const openTicketTab = (): void => Tab.getOrCreateInstance(ticketTab).show();

  if (window.location.hash === SUPPORT_TICKET_HASH) {
    openTicketTab();
  }

  document.querySelectorAll<HTMLAnchorElement>('.pmf-open-support-ticket').forEach((link) => {
    link.addEventListener('click', (event: MouseEvent) => {
      event.preventDefault();
      openTicketTab();
    });
  });

  document.querySelectorAll<HTMLElement>('#pmf-ask-tabs [data-bs-toggle="tab"]').forEach((tab) => {
    tab.addEventListener('shown.bs.tab', () => {
      const hash = tab === ticketTab ? SUPPORT_TICKET_HASH : '';
      window.history.replaceState(null, '', `${window.location.pathname}${window.location.search}${hash}`);
    });
  });
};

export const handleSupportTicket = (): void => {
  const form = document.getElementById('pmf-support-ticket-form') as HTMLFormElement | null;
  const submit = document.getElementById('pmf-submit-support-ticket') as HTMLButtonElement | null;
  const message = document.getElementById('pmf-support-ticket-response') as HTMLElement | null;

  if (!form || !submit || !message) {
    return;
  }

  const failure = (error: unknown): string => form.dataset.sendFailed || (error as Error).message;

  const showSent = (text: string): void => {
    message.replaceChildren(successAlert(text));
    form.reset();
    form.classList.add('d-none');
  };

  const resubscribe = async (ticket: UnsubscribedTicket): Promise<void> => {
    const button = document.getElementById('pmf-support-ticket-resubscribe') as HTMLButtonElement | null;
    if (button) {
      button.disabled = true;
    }
    submit.disabled = true;

    const csrfToken = (form.elements.namedItem('pmf-csrf-token') as HTMLInputElement | null)?.value ?? '';

    try {
      const response = await resubscribeSupportTicket(csrfToken, ticket.ticketId);

      if (response.success) {
        showSent(response.success);
      } else {
        message.replaceChildren(errorAlert(response.error ?? failure(new Error('No response'))));
      }
    } catch (error) {
      message.replaceChildren(errorAlert(failure(error)));
    } finally {
      submit.disabled = false;
      message.scrollIntoView?.({ behavior: 'smooth', block: 'center' });
    }
  };

  submit.addEventListener('click', async (event: MouseEvent) => {
    event.preventDefault();
    event.stopPropagation();

    if (!form.checkValidity()) {
      form.classList.add('was-validated');
      return;
    }

    submit.disabled = true;
    message.replaceChildren();

    try {
      const response = await sendSupportTicket(new FormData(form));

      if (response.success) {
        showSent(response.success);
      }

      if (response.error && response.unsubscribed) {
        const ticket = response.unsubscribed;
        message.append(unsubscribedAlert(response.error, ticket, () => resubscribe(ticket)));
      } else if (response.error) {
        message.append(errorAlert(response.error));
      }
    } catch (error) {
      // A failure without a message from our server (network error, proxy error page) is never shown as is
      message.append(errorAlert(failure(error)));
    } finally {
      submit.disabled = false;
      resetTurnstile(form);
      message.scrollIntoView?.({ behavior: 'smooth', block: 'center' });
    }
  });
};
