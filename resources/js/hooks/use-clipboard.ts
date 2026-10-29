// Credit: https://usehooks-ts.com/
import { useState } from 'react';

export type CopiedValue = string | null;
export type CopyFn = (text: string) => Promise<boolean>;
export type UseClipboardReturn = [CopiedValue, CopyFn];

export function useClipboard(): UseClipboardReturn {
    const [copiedText, setCopiedText] = useState<CopiedValue>(null);

    const copy: CopyFn = async (text) => {
        try {
            if (navigator.clipboard) {
                await navigator.clipboard.writeText(text);
            } else {
                // shortcut: HTTP preview needs execCommand; remove when development uses HTTPS.
                const activeElement = document.activeElement;
                const textarea = document.createElement('textarea');
                textarea.value = text;
                textarea.readOnly = true;
                textarea.style.position = 'fixed';
                textarea.style.opacity = '0';
                (
                    activeElement?.closest('[role="dialog"]') ?? document.body
                ).append(textarea);

                try {
                    textarea.select();
                    if (!document.execCommand('copy')) {
                        throw new Error('Clipboard copy failed');
                    }
                } finally {
                    textarea.remove();
                    if (activeElement instanceof HTMLElement) {
                        activeElement.focus({ preventScroll: true });
                    }
                }
            }
            setCopiedText(text);

            return true;
        } catch (error) {
            console.warn('Copy failed', error);
            setCopiedText(null);

            return false;
        }
    };

    return [copiedText, copy];
}
