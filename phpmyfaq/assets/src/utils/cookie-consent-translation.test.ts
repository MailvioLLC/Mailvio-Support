import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const readTranslation = (language: string): string =>
  readFileSync(resolve(process.cwd(), `phpmyfaq/translations/cookie-consent/${language}.json`), 'utf8');

describe('cookie consent English translation', () => {
  it('speaks for the site as "we", not as a single person', (): void => {
    const translation = readTranslation('en');

    expect(translation).not.toMatch(/\b(I|my|me)\b(?! choose)/);
    expect(translation).toContain('We use essential cookies');
  });

  it('labels the save button in the preferences dialog as saving, not rejecting', (): void => {
    const translation = JSON.parse(readTranslation('en'));

    expect(translation.preferencesModal.savePreferencesBtn).toBe('Save preferences');
  });
});
