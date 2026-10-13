import { describe, expect, test, vi } from 'vitest';
import { handleReloadCaptcha, resetTurnstile } from './captcha';

global.fetch = vi.fn(() =>
  Promise.resolve({
    status: 200,
    ok: true,
    json: () => Promise.resolve({}),
    headers: new Headers(),
    redirected: false,
    statusText: 'OK',
    type: 'basic',
    url: '',
    clone: () => ({}) as Response,
    body: null,
    bodyUsed: false,
    arrayBuffer: () => Promise.resolve(new ArrayBuffer(0)),
    blob: () => Promise.resolve(new Blob()),
    formData: () => Promise.resolve(new FormData()),
    text: () => Promise.resolve(''),
  } as Response)
);

document.body.innerHTML = `
  <button id="reloadButton" data-action="refresh">Reload</button>
  <input id="captcha" />
  <img id="captchaImage" src="" />
`;

describe('handleReloadCaptcha', () => {
  test('should reload captcha image and clear captcha input on button click', async () => {
    const reloadButton = document.getElementById('reloadButton') as HTMLButtonElement;
    const captcha = document.getElementById('captcha') as HTMLInputElement;
    const date: number = Math.floor(new Date().getTime() / 1000);

    handleReloadCaptcha(reloadButton);

    reloadButton.click();

    await Promise.resolve();

    expect(fetch).toHaveBeenCalledWith(`api/captcha?action=refresh&timestamp=${date}`, {
      cache: 'no-cache',
      method: 'GET',
      redirect: 'follow',
      referrerPolicy: 'no-referrer',
    });
    expect(captcha.value).toBe('');
  });
});

describe('resetTurnstile', () => {
  const turnstileWindow = window as Window & { turnstile?: { reset: (widget?: string | HTMLElement) => void } };

  test('resets the Turnstile widget of the form', () => {
    const reset = vi.fn();
    turnstileWindow.turnstile = { reset };
    const form = document.createElement('form');
    form.innerHTML = '<div class="cf-turnstile" data-sitekey="site-key"></div>';

    resetTurnstile(form);

    expect(reset).toHaveBeenCalledWith(form.querySelector('.cf-turnstile'));
    delete turnstileWindow.turnstile;
  });

  test('does nothing when the form has no Turnstile widget', () => {
    const reset = vi.fn();
    turnstileWindow.turnstile = { reset };

    resetTurnstile(document.createElement('form'));

    expect(reset).not.toHaveBeenCalled();
    delete turnstileWindow.turnstile;
  });

  test('does nothing while the Turnstile script has not loaded yet', () => {
    const form = document.createElement('form');
    form.innerHTML = '<div class="cf-turnstile" data-sitekey="site-key"></div>';

    expect(() => resetTurnstile(form)).not.toThrow();
  });
});
