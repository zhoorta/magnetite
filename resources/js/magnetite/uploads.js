// Uploading zips and watching the scan that follows, kept in a store so it goes on while you move between pages. The checks and the
// status text are pure (Node tests them); the store does the requests. XMLHttpRequest, because fetch cannot report upload progress.

/** The zips of a picked list that can be added: `{ files, error }` (`error` says the last thing that was refused). */
export function checkZips(list, existing, maxMb) {
    const files = [];
    let error = null;
    for (const f of list) {
        if (!/\.zip$/i.test(f.name)) error = `${f.name} is not a zip.`;
        else if (f.size > maxMb * 1024 * 1024) error = `${f.name} is larger than ${maxMb} MB.`;
        else if (![...existing, ...files].some((x) => x.name === f.name && x.size === f.size)) files.push(f);
    }

    return { files, error };
}

export const sizeText = (bytes) => (bytes >= 1024 ** 3 ? `${(bytes / 1024 ** 3).toFixed(2)} GB` : `${(bytes / 1024 ** 2).toFixed(1)} MB`);

/** The short line for the library's status bar: what is going on, or what just finished; empty when nothing. */
export function uploadNote({ busy, progress, scan, done }) {
    if (busy) return progress < 100 ? `Uploading ${progress}%` : 'Unpacking…';
    if (scan?.state === 'queued') return 'Scan queued';
    if (scan?.state === 'running') return `Scanning… ${scan.processed ?? 0} files`;
    if (done) return `Library updated: ${done.added ?? 0} new`;

    return '';
}

/** @param {?{ url: string, scanUrl: string, maxMb: number }} cfg from the page head (null for a guest) */
export function uploadsStore(cfg) {
    const token = () => document.querySelector('meta[name=csrf-token]')?.content ?? '';

    return {
        files: [],
        busy: false,
        progress: 0,
        result: null,
        error: null,
        scan: null,
        waited: 0,
        done: null, // counts of the last scan this page asked for, until the next action
        expect: false, // a scan we started: when it finishes the library is told
        timer: null,
        maxMb: cfg?.maxMb ?? 0,

        get active() {
            return this.busy || ['queued', 'running'].includes(this.scan?.state);
        },

        get note() {
            return uploadNote(this);
        },

        size: sizeText,

        /** Add zips (a file input's list or a drop); returns how many were added. */
        pick(list) {
            this.error = null;
            this.result = null;
            this.done = null;
            const { files, error } = checkZips(list, this.files, this.maxMb);
            this.files.push(...files);
            this.error = error;

            return files.length;
        },

        remove(i) {
            this.files.splice(i, 1);
        },

        clear() {
            if (!this.busy) this.files = [];
        },

        send() {
            if (this.busy || !this.files.length || !cfg) return;

            const body = new FormData();
            this.files.forEach((f) => body.append('archives[]', f));
            this.busy = true;
            this.progress = 0;
            this.error = null;
            this.result = null;
            this.done = null;

            const xhr = new XMLHttpRequest();
            xhr.open('POST', cfg.url);
            xhr.setRequestHeader('Accept', 'application/json');
            xhr.setRequestHeader('X-CSRF-TOKEN', token());
            xhr.upload.onprogress = (e) => {
                if (e.lengthComputable) this.progress = Math.round((e.loaded / e.total) * 100);
            };
            xhr.onerror = () => this.fail('The upload failed. Check the connection and the size limits of the server.');
            xhr.onload = () => {
                let json = null;
                try {
                    json = JSON.parse(xhr.responseText);
                } catch {}

                if (xhr.status === 200 && json) {
                    this.result = json;
                    this.files = [];
                    this.busy = false;
                    if (json.scan) {
                        this.scan = json.scan;
                        this.expect = true;
                        this.watch();
                    }
                } else if (xhr.status === 419) this.fail('The page expired. Reload it and try again.');
                else if (xhr.status === 413) this.fail('The server refused the upload because it is too large (raise its body size limit).');
                else if (json?.errors) this.fail(Object.values(json.errors).flat()[0]);
                else this.fail(json?.message ?? `The server answered ${xhr.status}.`);
            };
            xhr.send(body);
        },

        /** Ask for a scan of the folder (for files that were copied there by hand). */
        async scanNow() {
            if (!cfg) return;
            const res = await fetch(cfg.scanUrl, { method: 'POST', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': token() } });
            if (res.ok) {
                this.scan = await res.json();
                this.expect = true;
                this.done = null;
                this.watch();
            }
        },

        /** Read the scan status, and keep reading while it is queued or running; when a scan we asked for finishes, the library is told. */
        async watch() {
            if (!cfg) return;
            clearTimeout(this.timer);
            try {
                this.scan = await (await fetch(cfg.scanUrl, { headers: { Accept: 'application/json' } })).json();
            } catch {
                return;
            }

            const active = ['queued', 'running'].includes(this.scan.state);
            this.waited = this.scan.state === 'queued' ? this.waited + 2 : 0;
            if (active) this.timer = setTimeout(() => this.watch(), 2000);
            else if (this.expect) {
                this.expect = false;
                if (this.scan.state === 'done') {
                    this.done = this.scan.counts ?? {};
                    window.dispatchEvent(new CustomEvent('magnetite:scanned'));
                }
            }
        },

        fail(message) {
            this.error = message;
            this.busy = false;
        },
    };
}
