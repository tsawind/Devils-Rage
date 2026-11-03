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

/** Patch 62: a multipart POST (a camera's clip and still), JSON back. */
export async function formFetch<T>(url: string, body: FormData): Promise<T | null> {
    try {
        const response = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrfToken(), ...driverHeaders() },
            body,
        });
        if (!response.ok) return null;
        return (await response.json()) as T;
    } catch {
        return null;
    }
}

/** Patch 73: like jsonFetch, but says why it failed (status and the mapper's message / field errors). */
export async function jsonFetchWhy<T>(
    url: string,
    method: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE' = 'GET',
    body?: unknown,
): Promise<{ data: T | null; status: number; message: string | null; errors: Record<string, string[]> }> {
    try {
        const response = await fetch(url, {
            method,
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': xsrfToken(),
                ...driverHeaders(),
            },
            body: body === undefined ? undefined : JSON.stringify(body),
        });
        const json = (await response.json().catch(() => ({}))) as { message?: string; errors?: Record<string, string[]> };
        if (!response.ok) return { data: null, status: response.status, message: json.message ?? null, errors: json.errors ?? {} };
        return { data: json as T, status: response.status, message: null, errors: {} };
    } catch {
        return { data: null, status: 0, message: null, errors: {} };
    }
}
