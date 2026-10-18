import { driverHeaders } from '@/lib/windowDriver';

/** Patch 37: small JSON requests to the mapper (same-origin, with the XSRF cookie). */
function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

export async function jsonFetch<T>(url: string, method: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE' = 'GET', body?: unknown): Promise<T | null> {
    try {
        const response = await fetch(url, {
            method,
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': xsrfToken(),
                // Patch 39: this window's main.
                ...driverHeaders(),
            },
            body: body === undefined ? undefined : JSON.stringify(body),
        });
        if (!response.ok) return null;
        return (await response.json()) as T;
    } catch {
        return null;
    }
}
