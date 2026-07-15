import { describe, expect, it } from 'vitest';
import { compareVersions, isNewerVersion, shouldRollback } from './updater.js';

describe('compareVersions', () => {
  it('orders versions numerically', () => {
    expect(compareVersions('1.0.0', '1.0.0')).toBe(0);
    expect(compareVersions('1.2.0', '1.1.9')).toBe(1);
    expect(compareVersions('0.9.0', '0.10.0')).toBe(-1);
    expect(compareVersions('2.0.0', '1.9.9')).toBe(1);
  });

  it('tolerates a leading v and missing segments', () => {
    expect(compareVersions('v1.2', '1.2.0')).toBe(0);
    expect(compareVersions('1.2.1', 'v1.2')).toBe(1);
  });
});

describe('isNewerVersion', () => {
  it('is true only for strictly newer candidates', () => {
    expect(isNewerVersion('0.3.1', '0.3.0')).toBe(true);
    expect(isNewerVersion('0.3.0', '0.3.0')).toBe(false);
    expect(isNewerVersion('0.2.9', '0.3.0')).toBe(false);
  });
});

describe('shouldRollback', () => {
  it('rolls back only after the failure threshold', () => {
    expect(shouldRollback(0)).toBe(false);
    expect(shouldRollback(2)).toBe(false);
    expect(shouldRollback(3)).toBe(true);
    expect(shouldRollback(1, 1)).toBe(true);
  });
});
