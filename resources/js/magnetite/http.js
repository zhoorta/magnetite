/** JSON request with the page's CSRF token. Never throws on an HTTP error: look at `ok`. */
export async function send(method, url, body) {
    const r = await fetch(url, {
        method,
        headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content ?? '',
            Accept: 'application/json',
            ...(body ? { 'Content-Type': 'application/json' } : {}),
        },
        body: body ? JSON.stringify(body) : undefined,
    });

    return { ok: r.ok, status: r.status, data: await r.json().catch(() => null) };
}
