import { OfflineQueue } from './offline-queue.js';

(() => {
    'use strict';

    const queue = new OfflineQueue();
    const EVENT_LABELS = {
        ENTRY: 'Entrada',
        BREAK_START: 'Inicio de colación',
        BREAK_END: 'Fin de colación',
        EXIT: 'Salida',
    };
    let retryTimer = null;
    let retryDelay = 2000;
    let syncing = false;
    let activeAccountBinding = null;

    function saveSetting(key, value) {
        return queue.saveSetting(key, value);
    }

    function readSetting(key) {
        return queue.setting(key);
    }

    function readQueue() {
        return queue.all();
    }

    function saveEvent(event) {
        return queue.save(event);
    }

    function setMessage(text, kind = '') {
        const node = document.getElementById('sync-message');
        if (!node) return;
        node.textContent = text;
        node.classList.remove('hidden', 'success', 'error', 'info');
        if (kind) node.classList.add(kind);
    }

    function updateConnectionStatus() {
        const node = document.getElementById('connection-status');
        if (node) node.textContent = navigator.onLine ? 'En línea' : 'Sin conexión';
        node?.parentElement?.setAttribute('data-online', String(navigator.onLine));
        if (!navigator.onLine && activeAccountBinding) {
            setMessage('Puedes marcar. El registro se guardará en este dispositivo.', 'info');
        }
    }

    function formatLocalTimestamp(value) {
        const date = new Date(value);
        return new Intl.DateTimeFormat('es-CL', {
            dateStyle: 'short',
            timeStyle: 'medium',
        }).format(date);
    }

    function renderQueue(events) {
        const count = document.getElementById('pending-count');
        if (count) count.textContent = String(events.length);
        const latestEvent = [...events].sort((a, b) =>
            new Date(b.occurred_at_device).getTime() - new Date(a.occurred_at_device).getTime())[0];
        const latestAction = document.getElementById('last-action');
        const latestTime = document.getElementById('last-action-time');
        const pendingIsLatest = latestEvent && (!latestAction?.dataset.serverTimestamp
            || new Date(latestEvent.occurred_at_device).getTime() >= new Date(latestAction.dataset.serverTimestamp).getTime());
        if (pendingIsLatest && latestAction) latestAction.textContent = EVENT_LABELS[latestEvent.event_type] || 'Marca';
        if (pendingIsLatest && latestTime) latestTime.textContent = `${formatLocalTimestamp(latestEvent.occurred_at_device)} · pendiente`;
        const list = document.getElementById('local-pending');
        if (!list) return;
        list.replaceChildren();
        if (!events.length) {
            const empty = document.createElement('li');
            empty.className = 'muted';
            empty.textContent = 'No hay marcas pendientes.';
            list.append(empty);
            return;
        }
        for (const event of events.sort((a, b) =>
            new Date(b.occurred_at_device).getTime() - new Date(a.occurred_at_device).getTime())) {
            const item = document.createElement('li');
            item.className = 'event-row';
            const description = document.createElement('span');
            const label = document.createElement('strong');
            label.textContent = EVENT_LABELS[event.event_type] || 'Marca';
            const timestamp = document.createElement('small');
            timestamp.textContent = formatLocalTimestamp(event.occurred_at_device);
            description.append(label, timestamp);
            const status = document.createElement('span');
            status.className = 'badge pending';
            status.textContent = 'Pendiente de sincronizar';
            item.append(description, status);
            list.append(item);
        }
    }

    async function refreshQueue() {
        const events = await readQueue();
        renderQueue(events.filter((event) => event.account_binding === activeAccountBinding));
        return events;
    }

    function isoWithOffset(date) {
        const pad = (value) => String(value).padStart(2, '0');
        const offsetMinutes = -date.getTimezoneOffset();
        const sign = offsetMinutes >= 0 ? '+' : '-';
        const offset = Math.abs(offsetMinutes);
        return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`
            + `T${pad(date.getHours())}:${pad(date.getMinutes())}:${pad(date.getSeconds())}.${String(date.getMilliseconds()).padStart(3, '0')}`
            + `${sign}${pad(Math.floor(offset / 60))}:${pad(offset % 60)}`;
    }

    async function deviceIdentity() {
        let deviceId = localStorage.getItem('asistencia-device-id');
        if (!deviceId) {
            deviceId = crypto.randomUUID();
            localStorage.setItem('asistencia-device-id', deviceId);
        }
        const current = Number(localStorage.getItem('asistencia-device-seq') || 0) + 1;
        localStorage.setItem('asistencia-device-seq', String(current));
        return { deviceId, sequence: current };
    }

    async function captureEvent(type, button) {
        const binding = activeAccountBinding;
        if (!binding || !/^[a-f0-9]{64}$/i.test(binding)) {
            setMessage('Conéctate e inicia sesión antes de preparar marcas sin conexión.', 'error');
            return;
        }
        if (!crypto.randomUUID) {
            setMessage('Este navegador no admite identificadores seguros para guardar marcas.', 'error');
            return;
        }

        button.disabled = true;
        try {
            const now = new Date();
            const identity = await deviceIdentity();
            const event = {
                event_uuid: crypto.randomUUID(),
                event_type: type,
                occurred_at_device: isoWithOffset(now),
                client_timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC',
                device_id: identity.deviceId,
                device_event_seq: identity.sequence,
                offline: !navigator.onLine,
                local_status: 'pending',
                account_binding: binding,
            };
            await saveEvent(event);
            const latest = document.getElementById('last-action');
            const latestTime = document.getElementById('last-action-time');
            if (latest) latest.textContent = EVENT_LABELS[type];
            if (latestTime) latestTime.textContent = `${formatLocalTimestamp(event.occurred_at_device)} · pendiente`;
            await refreshQueue();
            setMessage('Registro guardado en el dispositivo. Pendiente de sincronización.', 'info');
            if (navigator.onLine) syncQueue();
        } catch (error) {
            setMessage(error.message || 'No se pudo guardar la marca local. No vuelvas a intentarlo hasta revisar el estado del dispositivo.', 'error');
        } finally {
            button.disabled = false;
        }
    }

    function scheduleRetry() {
        if (retryTimer || !navigator.onLine) return;
        retryTimer = window.setTimeout(() => {
            retryTimer = null;
            if (document.querySelector('[data-offline-shell]')) reopenOnlineApp();
            else syncQueue();
        }, retryDelay);
        retryDelay = Math.min(retryDelay * 2, 60000);
    }

    async function syncQueue() {
        if (syncing || !navigator.onLine) return;
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
        if (!csrf) {
            setMessage('Vuelve a abrir la aplicación con tu sesión cuando el servidor esté disponible para sincronizar.', 'error');
            return;
        }

        syncing = true;
        let confirmed = 0;
        try {
            const events = await readQueue();
            if (events.length === 0) {
                retryDelay = 2000;
                return;
            }
            const binding = activeAccountBinding;
            const currentEvents = events.filter((event) => event.account_binding === binding);
            if (currentEvents.length === 0) {
                setMessage('Hay marcas pendientes asociadas a otra cuenta. Inicia sesión con esa cuenta para sincronizarlas.', 'error');
                return;
            }
            const delivery = await queue.deliver(async (batch) => {
                const response = await fetch('/api/attendance/sync', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrf,
                    },
                    body: JSON.stringify({ events: batch }),
                });
                if (response.status === 401 || response.status === 419) {
                    const error = new Error('No fue posible confirmar la sesión. Mantén las marcas en el dispositivo e inicia sesión para sincronizarlas.');
                    error.retryable = false;
                    throw error;
                }
                if (response.status === 429 || response.status >= 500) {
                    const error = new Error('El servidor no pudo recibir las marcas ahora. Se reintentará.');
                    error.retryable = true;
                    throw error;
                }
                if (!response.ok) {
                    const error = new Error('El servidor rechazó la solicitud. Las marcas permanecen en el dispositivo.');
                    error.retryable = false;
                    throw error;
                }
                return response.json();
            }, currentEvents);
            confirmed = delivery.confirmed.length;
            if (delivery.error) {
                await refreshQueue();
                setMessage(`No se pudo sincronizar. El registro permanece pendiente. ${delivery.error.message || ''}`, 'error');
                if (delivery.error.retryable !== false) scheduleRetry();
                return;
            }
            const currentRemaining = delivery.remaining.filter((event) => event.account_binding === binding);
            const otherAccountRemaining = delivery.remaining.length - currentRemaining.length;
            const eventErrors = delivery.results.filter((result) => result.status === 'error' && result.errors);
            for (const result of delivery.results) {
                if (result.status === 'error' && result.errors) {
                    setMessage(`Una marca necesita revisión: ${Object.values(result.errors).flat().join(' ')}`, 'error');
                }
            }
            await refreshQueue();
            if (currentRemaining.length) {
                if (!eventErrors.length) {
                    setMessage('No se pudo sincronizar. El registro permanece pendiente.', 'error');
                    scheduleRetry();
                }
            } else if (otherAccountRemaining) {
                setMessage(`${otherAccountRemaining} marca(s) están asociadas a otra cuenta y permanecen en el dispositivo.`, 'error');
                if (confirmed) window.location.reload();
            } else {
                retryDelay = 2000;
                if (confirmed) {
                    setMessage('Registro sincronizado.', 'success');
                    window.setTimeout(() => window.location.reload(), 1200);
                }
            }
            if (confirmed && currentRemaining.length) window.location.reload();
        } catch (error) {
            setMessage(`No se completó la sincronización. Las marcas se mantienen en el dispositivo. ${error.message || ''}`, 'error');
            scheduleRetry();
        } finally {
            syncing = false;
        }
    }

    async function reopenOnlineApp() {
        try {
            const response = await fetch('/dashboard', {
                method: 'HEAD',
                credentials: 'same-origin',
                cache: 'no-store',
            });
            if (response.ok) {
                window.location.reload();
            } else {
                setMessage('Hay conexión, pero no se pudo abrir la aplicación. Tus marcas siguen guardadas en el dispositivo.', 'error');
                if (response.status >= 500) scheduleRetry();
            }
        } catch (error) {
            setMessage(`La conexión todavía no llega al servidor. Tus marcas siguen guardadas en el dispositivo. ${error.message || ''}`, 'error');
            scheduleRetry();
        }
    }

    async function initialize() {
        updateConnectionStatus();
        document.querySelectorAll('[data-timezone-field]').forEach((field) => {
            field.value = Intl.DateTimeFormat().resolvedOptions().timeZone || 'America/Santiago';
        });
        const attendancePage = document.querySelector('[data-attendance-action]') || document.querySelector('[data-offline-shell]');
        if (!attendancePage) return;

        const bindingElement = document.querySelector('[data-account-binding]');
        const binding = bindingElement?.dataset.accountBinding;
        if (binding && /^[a-f0-9]{64}$/i.test(binding)) {
            activeAccountBinding = binding;
            await saveSetting('account-binding', binding);
        } else {
            const bindingRecord = await readSetting('account-binding');
            activeAccountBinding = bindingRecord?.value || null;
        }
        const events = await refreshQueue();
        if (!navigator.onLine) {
            if (activeAccountBinding) {
                updateConnectionStatus();
            } else {
                setMessage('Para marcar sin conexión, inicia sesión y prepara la aplicación mientras tengas internet.', 'error');
            }
        }
        if (navigator.onLine && document.querySelector('[data-offline-shell]')) reopenOnlineApp();
        else if (navigator.onLine && events.length) syncQueue();

        document.querySelectorAll('[data-attendance-action]').forEach((button) => {
            button.addEventListener('click', () => captureEvent(button.dataset.attendanceAction, button));
        });
        document.getElementById('sync-now')?.addEventListener('click', syncQueue);
        window.addEventListener('online', () => {
            updateConnectionStatus();
            retryDelay = 2000;
            if (activeAccountBinding) setMessage('Con conexión. Se revisarán las marcas pendientes para sincronización.', 'info');
            if (document.querySelector('[data-offline-shell]')) reopenOnlineApp();
            else syncQueue();
        });
        window.addEventListener('offline', () => {
            updateConnectionStatus();
            if (!activeAccountBinding) {
                setMessage('Para marcar sin conexión, inicia sesión y prepara la aplicación mientras tengas internet.', 'error');
            }
        });
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('/sw.js').catch((error) => {
                setMessage(`No se pudo preparar el soporte PWA: ${error.message}`, 'error');
            });
        }
    }

    initialize().catch((error) => setMessage(error.message || 'No se pudo inicializar el almacenamiento de marcas.', 'error'));
})();
