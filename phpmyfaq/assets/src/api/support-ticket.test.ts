import { afterEach, describe, expect, test, vi } from 'vitest';
import { sendSupportTicket } from './support-ticket';

const mockFetch = (ok: boolean, status: number, body: unknown): void => {
  global.fetch = vi.fn(() =>
    Promise.resolve({
      ok,
      status,
      json: () => (body === undefined ? Promise.reject(new SyntaxError('no JSON')) : Promise.resolve(body)),
    } as Response)
  );
};

describe('sendSupportTicket', () => {
  afterEach(() => {
    vi.restoreAllMocks();
  });

  test('posts the form as JSON to the support ticket API', async () => {
    mockFetch(true, 200, { success: 'Sent' });
    const data = new FormData();
    data.append('subject', 'Help');

    const result = await sendSupportTicket(data);

    expect(result).toEqual({ success: 'Sent' });
    expect(global.fetch).toHaveBeenCalledWith(
      'api/support-ticket',
      expect.objectContaining({ method: 'POST', body: JSON.stringify({ subject: 'Help' }) })
    );
  });

  test('returns the error message of a rejected ticket', async () => {
    mockFetch(false, 400, { error: 'Please enter the captcha code' });

    await expect(sendSupportTicket(new FormData())).resolves.toEqual({ error: 'Please enter the captcha code' });
  });

  test('throws when a failed response carries no error message', async () => {
    mockFetch(false, 500, undefined);

    await expect(sendSupportTicket(new FormData())).rejects.toThrow('HTTP 500');
  });
});
