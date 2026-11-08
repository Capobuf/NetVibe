import { HttpResponseError } from '@inertiajs/core';
import { http } from '@inertiajs/react';

type ErrorBody = { errors?: Record<string, string[]> };

// Inertia's HTTP client adds Laravel's XSRF header to same-origin requests.
export async function postJson<T>(
    url: string,
    data: unknown,
    signal?: AbortSignal,
): Promise<{ ok: true; data: T | null } | { ok: false; data: ErrorBody | null }> {
    try {
        const response = await http.getClient().request({
            method: 'post',
            url,
            data,
            // Ignored for FormData, which sets its own multipart boundary.
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
            },
            signal,
        });
        return { ok: true, data: parse(response.data) };
    } catch (error) {
        if (!(error instanceof HttpResponseError)) throw error;
        return { ok: false, data: parse(error.response.data) };
    }
}

function parse(text: string) {
    try {
        return JSON.parse(text);
    } catch {
        return null;
    }
}
