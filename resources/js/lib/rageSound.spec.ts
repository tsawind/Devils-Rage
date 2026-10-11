import { pickCalloutVoice, TARGET_CALLOUT } from '@/lib/rageSound';
import { describe, expect, it } from 'vitest';

describe('patch 37: the Rage Roll call-out', () => {
    it('says it twice', () => {
        expect(TARGET_CALLOUT).toBe('Target acquired. Target acquired.');
    });

    it('prefers an English male-sounding voice', () => {
        const voices = [
            { name: 'Microsoft Zira - English (United States)', lang: 'en-US', default: true },
            { name: 'Google Deutsch', lang: 'de-DE' },
            { name: 'Microsoft David - English (United States)', lang: 'en-US' },
        ];
        expect(pickCalloutVoice(voices)?.name).toContain('David');
    });

    it('falls back to the default English voice, then any English, then any voice', () => {
        expect(pickCalloutVoice([{ name: 'Samantha', lang: 'en-US', default: true }, { name: 'Karen', lang: 'en-AU' }])?.name).toBe('Samantha');
        expect(pickCalloutVoice([{ name: 'Thomas', lang: 'fr-FR' }, { name: 'Karen', lang: 'en-AU' }])?.name).toBe('Karen');
        expect(pickCalloutVoice([{ name: 'Thomas', lang: 'fr-FR' }])?.name).toBe('Thomas');
        expect(pickCalloutVoice([])).toBeNull();
    });
});
