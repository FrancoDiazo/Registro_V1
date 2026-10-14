export class OfflineQueue {
    constructor(indexedDBApi = globalThis.indexedDB, databaseName = 'asistencia-local') {
        this.indexedDB = indexedDBApi;
        this.databaseName = databaseName;
        this.databasePromise = null;
    }

    open() {
        if (!this.indexedDB) {
            return Promise.reject(new Error('Este navegador no permite guardar marcas offline de forma segura.'));
        }
        if (!this.databasePromise) {
            this.databasePromise = new Promise((resolve, reject) => {
                const request = this.indexedDB.open(this.databaseName, 1);
                request.onupgradeneeded = () => {
                    const database = request.result;
                    if (!database.objectStoreNames.contains('pending-events')) {
                        database.createObjectStore('pending-events', { keyPath: 'event_uuid' });
                    }
                    if (!database.objectStoreNames.contains('settings')) {
                        database.createObjectStore('settings', { keyPath: 'key' });
                    }
                };
                request.onsuccess = () => resolve(request.result);
                request.onerror = () => reject(request.error || new Error('No se pudo abrir el almacenamiento local.'));
            });
        }
        return this.databasePromise;
    }

    async request(storeName, mode, action) {
        const database = await this.open();
        return new Promise((resolve, reject) => {
            const transaction = database.transaction(storeName, mode);
            const request = action(transaction.objectStore(storeName));
            let result;
            request.onsuccess = () => {
                result = request.result;
            };
            request.onerror = () => reject(request.error || new Error('No se pudo completar la operación local.'));
            transaction.oncomplete = () => resolve(result);
            transaction.onerror = () => reject(transaction.error || new Error('No se pudo confirmar la operación local.'));
            transaction.onabort = () => reject(transaction.error || new Error('Se canceló la operación local.'));
        });
    }

    save(event) {
        return this.request('pending-events', 'readwrite', (store) => store.add(event));
    }

    all() {
        return this.request('pending-events', 'readonly', (store) => store.getAll());
    }

    setting(key) {
        return this.request('settings', 'readonly', (store) => store.get(key));
    }

    saveSetting(key, value) {
        return this.request('settings', 'readwrite', (store) => store.put({ key, value }));
    }

    async confirm(eventUuids) {
        for (const eventUuid of eventUuids) {
            await this.request('pending-events', 'readwrite', (store) => store.delete(eventUuid));
        }
    }

    async deliver(send, pendingEvents = null) {
        const events = pendingEvents || await this.all();
        const confirmed = [];
        const results = [];
        let error = null;
        for (let offset = 0; offset < events.length; offset += 50) {
            try {
                const response = await send(events.slice(offset, offset + 50));
                if (!Array.isArray(response?.results)) {
                    throw new Error('Respuesta de sincronización no válida.');
                }
                results.push(...response.results);
                const batchConfirmed = response.results
                    .filter((result) => result.status === 'accepted' || result.status === 'already_received')
                    .map((result) => result.event_uuid);
                await this.confirm(batchConfirmed);
                confirmed.push(...batchConfirmed);
            } catch (caught) {
                error = caught;
                break;
            }
        }
        return { confirmed, results, remaining: await this.all(), error };
    }
}
