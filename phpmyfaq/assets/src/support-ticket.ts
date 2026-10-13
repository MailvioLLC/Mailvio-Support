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
import { sendSupportTicket } from './api';
import { addElement, resetTurnstile } from './utils';

const SUPPORT_TICKET_HASH = '#support-ticket';

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
        message.append(addElement('div', { classList: 'alert alert-success', innerText: response.success }));
        form.reset();
        form.classList.add('d-none');
      }

      if (response.error) {
        message.append(addElement('div', { classList: 'alert alert-danger', innerText: response.error }));
      }
    } catch (error) {
      // A failure without a message from our server (network error, proxy error page) is never shown as is
      const failure = form.dataset.sendFailed || (error as Error).message;
      message.append(addElement('div', { classList: 'alert alert-danger', innerText: failure }));
    } finally {
      submit.disabled = false;
      resetTurnstile(form);
      message.scrollIntoView?.({ behavior: 'smooth', block: 'center' });
    }
  });
};
