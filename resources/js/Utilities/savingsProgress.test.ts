import { describe, expect, it } from 'vitest';
import { savingsProgressFromBasisPoints } from '@/Utilities/savingsProgress';

describe('savingsProgressFromBasisPoints', () => {
    it('maps 0, 25%, and 100%', () => {
        expect(savingsProgressFromBasisPoints(0)).toMatchObject({
            percentExact: 0,
            barPercent: 0,
            isOverfunded: false,
        });
        expect(savingsProgressFromBasisPoints(2500)).toMatchObject({
            percentExact: 25,
            barPercent: 25,
            isOverfunded: false,
        });
        expect(savingsProgressFromBasisPoints(10000)).toMatchObject({
            percentExact: 100,
            barPercent: 100,
            isOverfunded: false,
        });
    });

    it('preserves overfunded percentage while clamping visual width', () => {
        const result = savingsProgressFromBasisPoints(12500);
        expect(result.percentExact).toBe(125);
        expect(result.barPercent).toBe(100);
        expect(result.isOverfunded).toBe(true);
        expect(result.accessibleLabel).toMatch(/125/);
        expect(result.accessibleLabel).toMatch(/overfunded/i);
    });
});
