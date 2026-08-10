{{-- A `service_worker.extend` entry, as an application would write one. --}}
self.addEventListener('push', (event) => {
    event.waitUntil(self.registration.showNotification('Test'));
});
