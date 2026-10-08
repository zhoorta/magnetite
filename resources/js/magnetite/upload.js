/** Alpine component for the upload page: sends zips with XMLHttpRequest because fetch cannot report upload progress. */
export default function uploader(url, token, maxMb, scanUrl) {
    return {
        files: [],
        busy: false,
        progress: 0,
        result: null,
        error: null,
        scan: null,
        waited: 0,
        timer: null,

        init() {
            this.watch();
        },

        pick(list) {
            this.error = null;
            this.result = null;
            for (const f of list) {
                if (! /\.zip$/i.test(f.name)) {
                    this.error = `${f.name} is not a zip.`;
                } else if (f.size > maxMb * 1024 * 1024) {
                    this.error = `${f.name} is larger than ${maxMb} MB.`;
                } else if (! this.files.some((x) => x.name === f.name && x.size === f.size)) {
                    this.files.push(f);
                }
            }
        },

        size(bytes) {
            return bytes >= 1024 ** 3 ? (bytes / 1024 ** 3).toFixed(2) + ' GB' : (bytes / 1024 ** 2).toFixed(1) + ' MB';
        },

        send() {
            if (this.busy || ! this.files.length) return;

            const body = new FormData();
            this.files.forEach((f) => body.append('archives[]', f));

            this.busy = true;
            this.progress = 0;
            this.error = null;
            this.result = null;

            const xhr = new XMLHttpRequest();
            xhr.open('POST', url);
            xhr.setRequestHeader('Accept', 'application/json');
            xhr.setRequestHeader('X-CSRF-TOKEN', token);
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
                        this.watch();
                    }
                } else if (xhr.status === 419) {
                    this.fail('The page expired. Reload it and try again.');
                } else if (xhr.status === 413) {
                    this.fail('The server refused the upload because it is too large (raise its body size limit).');
                } else if (json?.errors) {
                    this.fail(Object.values(json.errors).flat()[0]);
                } else {
                    this.fail(json?.message ?? `The server answered ${xhr.status}.`);
                }
            };
            xhr.send(body);
        },

        /** Ask for a scan of the folder (for files that were copied there by hand). */
        async scanNow() {
            const res = await fetch(scanUrl, { method: 'POST', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': token } });
            if (res.ok) {
                this.scan = await res.json();
                this.watch();
            }
        },

        /** Poll the scan status while it is queued or running. */
        async watch() {
            clearTimeout(this.timer);
            try {
                this.scan = await (await fetch(scanUrl, { headers: { Accept: 'application/json' } })).json();
            } catch {
                return;
            }

            const active = ['queued', 'running'].includes(this.scan.state);
            this.waited = this.scan.state === 'queued' ? this.waited + 2 : 0;
            if (active) this.timer = setTimeout(() => this.watch(), 2000);
        },

        fail(message) {
            this.error = message;
            this.busy = false;
        },
    };
}
