import { send } from './http';
import { visualizers, meterList } from './visualizers';
import { createScene } from './visualizers/scene';

const idOf = (scene) => `scene-${scene.id}`;

/** Make a scene from the server a meter the player knows (or update the one it already has). */
export function registerScene(scene) {
    const id = idOf(scene);
    const known = visualizers[id];
    if (known) {
        known.label = `Scene: ${scene.name}`;
        known.settings = { ...scene.settings };
        known.hotspots = scene.hotspots ?? [];

        return;
    }
    visualizers[id] = createScene(id, `Scene: ${scene.name}`, { image: scene.url }, { ...scene.settings });
    visualizers[id].hotspots = scene.hotspots ?? [];
}

/** The person's pictures: `list` is what the server sent, meters are registered as they arrive. */
export function scenesStore(initial, player) {
    (initial ?? []).forEach(registerScene);

    const sync = () => {
        const p = player();
        if (p) p.visualizerList = meterList();
    };

    return {
        list: initial ?? [],
        busy: false,
        error: null,

        idOf,

        async upload(file) {
            this.busy = true;
            this.error = null;
            const form = new FormData();
            form.append('image', file);
            try {
                const r = await fetch('/scenes', {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content ?? '', Accept: 'application/json' },
                    body: form,
                });
                const data = await r.json().catch(() => null);
                if (!r.ok) throw new Error(data?.message ?? 'The upload failed.');
                this.list.push(data);
                registerScene(data);
                sync();

                return data;
            } catch (e) {
                this.error = e.message;

                return null;
            } finally {
                this.busy = false;
            }
        },

        /** Change one scene's settings (a value of `undefined` goes back to auto) or its name; saved after a short pause. */
        change(scene, patch) {
            const settings = { ...scene.settings };
            for (const [k, v] of Object.entries(patch.settings ?? {})) {
                if (v === undefined) delete settings[k];
                else settings[k] = v;
            }
            scene.settings = settings;
            if (patch.name) scene.name = patch.name;
            if (patch.hotspots) scene.hotspots = patch.hotspots;
            registerScene(scene); // the player picks it up on the next frame
            sync();

            clearTimeout(scene.saving);
            scene.saving = setTimeout(async () => {
                const r = await send('PATCH', `/scenes/${scene.id}`, { name: scene.name, settings: scene.settings, hotspots: scene.hotspots ?? [] });
                this.error = r.ok ? null : 'Could not save that setting.';
            }, 500);
        },

        async remove(scene) {
            const r = await send('DELETE', `/scenes/${scene.id}`);
            if (!r.ok) return;
            this.list = this.list.filter((s) => s.id !== scene.id);
            delete visualizers[idOf(scene)];
            const p = player();
            if (p?.meterIds.includes(idOf(scene))) p.setMeters(p.meterIds.filter((m) => m !== idOf(scene)));
            sync();
        },
    };
}
