import { describe, expect, test, vi } from 'vitest';
import { createQuestion } from './question';
import { serialize } from '../utils';

describe('createQuestion function', (): void => {
  test('creates a question successfully', async (): Promise<void> => {
    // Mocking fetch function
    global.fetch = vi.fn().mockResolvedValue({
      ok: true,
      status: 200,
      json: () => Promise.resolve({ success: 'Question created successfully' }),
    });

    // Test data
    const data = Object.keys({ question: 'What is this all about?' }) as unknown as FormData;

    // Call the function
    const result = await createQuestion(data);

    // Assertions
    expect(result).toEqual({ success: 'Question created successfully' });
    expect(fetch).toHaveBeenCalledWith('api/question/create', {
      method: 'POST',
      cache: 'no-cache',
      headers: {
        'Content-Type': 'application/json',
      },
      body: JSON.stringify(serialize(data)),
      redirect: 'follow',
      referrerPolicy: 'no-referrer',
    });
  });

  test('returns the error message of a rejected question', async () => {
    global.fetch = vi.fn().mockResolvedValue({
      ok: false,
      status: 400,
      json: () => Promise.resolve({ error: 'Please enter the captcha code' }),
    });

    const data = Object.keys({ comment: 'Test comment' }) as unknown as FormData;

    await expect(createQuestion(data)).resolves.toEqual({ error: 'Please enter the captcha code' });
  });

  test('throws an error if a failed response carries no error message', async () => {
    global.fetch = vi.fn().mockResolvedValue({
      ok: false,
      status: 500,
      json: () => Promise.reject(new SyntaxError('Unexpected token <')),
    });

    const data = Object.keys({ comment: 'Test comment' }) as unknown as FormData;

    await expect(createQuestion(data)).rejects.toThrow('HTTP 500');
  });
});
