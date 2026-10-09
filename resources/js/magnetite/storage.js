// The Storage page: test the connection to the library's store and clear the Ferrite cache.
export default function storage(testUrl, clearUrl, token, cache) {
    return {
        busy: false,
        result: null,
        cache,

        bytes(n) {
            if (n >= 1024 ** 3) return (n / 1024 ** 3).toFixed(1) + ' GB';
            if (n >= 1024 ** 2) return (n / 1024 ** 2).toFixed(1) + ' MB';
            return Math.round(n / 1024) + ' KB';
        },

        async call(url, method) {
            const response = await fetch(url, { method, headers: { 'X-CSRF-TOKEN': token, Accept: 'application/json' } });
            if (!response.ok) throw new Error('The server answered ' + response.status + '.');
            return response.json();
        },

        async test() {
            this.busy = true;
            this.result = null;
            try {
                this.result = await this.call(testUrl, 'POST');
            } catch (e) {
                this.result = { ok: false, error: e.message };
            } finally {
                this.busy = false;
            }
        },

        async clear() {
            this.busy = true;
            try {
                await this.call(clearUrl, 'DELETE');
                this.cache = { files: 0, bytes: 0 };
            } catch (e) {
                this.result = { ok: false, error: e.message };
            } finally {
                this.busy = false;
            }
        },
    };
}
