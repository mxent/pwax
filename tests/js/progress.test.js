/**
 * The navigation progress bar.
 *
 * The behaviour worth pinning down is not that it exists — it is when it stays out of the
 * way. A bar that appears for every 40ms navigation reads as jitter, so the delay is the
 * feature and the rest is decoration.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createProgress } from '../../src/js/progress.js';

const bar = () => document.getElementById('pwax-progress');
const width = () => bar().style.transform;

describe('the progress bar', () => {
    beforeEach(() => {
        document.body.innerHTML = '';
        vi.useFakeTimers();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('shows nothing for a navigation that finishes quickly', () => {
        const progress = createProgress({ delay: 250 });

        progress.start();
        vi.advanceTimersByTime(40);
        progress.done();

        // Never created. The common navigation costs one timer and no DOM at all.
        expect(bar()).toBeNull();
    });

    it('appears once the navigation has taken long enough to notice', () => {
        const progress = createProgress({ delay: 250 });

        progress.start();
        vi.advanceTimersByTime(300);

        expect(bar()).not.toBeNull();
        expect(bar().classList.contains('pwax-progress-visible')).toBe(true);
    });

    it('advances while it waits, without ever arriving', () => {
        const progress = createProgress({ delay: 0 });

        progress.start();
        vi.advanceTimersByTime(1);

        const first = width();
        vi.advanceTimersByTime(5000);

        const scale = Number(width().replace(/[^0-9.]/g, ''));

        expect(width()).not.toBe(first);
        // Claiming to be finished and then waiting is the one thing that makes a
        // progress bar read as a lie.
        expect(scale).toBeGreaterThan(0.5);
        expect(scale).toBeLessThan(1);
    });

    it('completes before it fades', () => {
        const progress = createProgress({ delay: 0 });

        progress.start();
        vi.advanceTimersByTime(500);
        progress.done();

        expect(width()).toBe('scaleX(1)');
        expect(bar().classList.contains('pwax-progress-done')).toBe(true);
    });

    it('resets itself once the fade is over', () => {
        const progress = createProgress({ delay: 0 });

        progress.start();
        vi.advanceTimersByTime(500);
        progress.done();
        vi.advanceTimersByTime(500);

        expect(bar().classList.contains('pwax-progress-visible')).toBe(false);
        expect(width()).toBe('scaleX(0)');
    });

    it('keeps one bar running across rapid navigations', () => {
        const progress = createProgress({ delay: 0 });

        progress.start();
        vi.advanceTimersByTime(300);
        const midway = width();

        // A second click before the first arrived. Restarting here would snap the bar
        // back to the left, which reads as progress being lost.
        progress.start();

        expect(width()).toBe(midway);
        expect(progress.active).toBe(true);
    });

    it('does nothing when finished without being started', () => {
        const progress = createProgress({ delay: 0 });

        expect(() => progress.done()).not.toThrow();
        expect(bar()).toBeNull();
    });

    it('can be told not to trickle', () => {
        const progress = createProgress({ delay: 0, trickle: false });

        progress.start();
        vi.advanceTimersByTime(1);
        const first = width();
        vi.advanceTimersByTime(5000);

        expect(width()).toBe(first);
    });

    it('is hidden from assistive technology', () => {
        const progress = createProgress({ delay: 0 });

        progress.start();
        vi.advanceTimersByTime(1);

        // The navigation is announced by the shell's live region, which says what the new
        // page is — more use than a bar reading out percentages it is inventing.
        expect(bar().getAttribute('aria-hidden')).toBe('true');
    });

    it('reuses an element the shell already rendered', () => {
        document.body.innerHTML = '<div id="pwax-progress"></div>';
        const existing = bar();

        const progress = createProgress({ delay: 0 });
        progress.start();
        vi.advanceTimersByTime(1);

        expect(bar()).toBe(existing);
        expect(document.querySelectorAll('#pwax-progress')).toHaveLength(1);
    });
});
