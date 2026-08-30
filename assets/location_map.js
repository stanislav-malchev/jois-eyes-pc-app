import L from 'leaflet';
import 'leaflet/dist/leaflet.min.css';

const raw = document.getElementById('map-data');
const container = document.getElementById('locationMap');

if (raw && container) {
    const points = JSON.parse(raw.textContent || '[]');

    // Leaflet normally auto-detects its default marker image path from its
    // own <link> tag's URL, which breaks under AssetMapper's hashed
    // /assets/ URLs. A bundler-style `import icon from '...png'` doesn't
    // work either — native ES modules require module scripts to be served
    // as JS, and a PNG response's MIME type fails that check in the
    // browser. So the template resolves these three via Twig's asset()
    // (which does work for a plain image URL, unlike a JS import) and
    // hands them over as data attributes instead.
    delete L.Icon.Default.prototype._getIconUrl;
    L.Icon.Default.mergeOptions({
        iconRetinaUrl: container.dataset.iconRetinaUrl,
        iconUrl: container.dataset.iconUrl,
        shadowUrl: container.dataset.shadowUrl,
    });

    if (points.length > 0) {
        const map = L.map(container);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
        }).addTo(map);

        // Points are pre-sorted by startTime ASC (LocationController). Fixes
        // at the exact same lat/lon are common while stationary (GPS keeps
        // reporting the identical reading) — collapsing them into one pin
        // per unique coordinate avoids stacking dozens of markers on top of
        // each other; the popup keeps the fix count and time range instead
        // of just the single latest time.
        const groups = new Map();
        for (const p of points) {
            const key = `${p.lat},${p.lon}`;
            const g = groups.get(key);
            if (g) {
                g.last = p.time;
                g.count += 1;
            } else {
                groups.set(key, { lat: p.lat, lon: p.lon, closestTo: p.closestTo, accuracy: p.accuracy, first: p.time, last: p.time, count: 1 });
            }
        }

        const markers = Array.from(groups.values()).map((g) => {
            const marker = L.marker([g.lat, g.lon]);
            const lines = [g.count > 1 ? `${g.first} – ${g.last} (${g.count} fixes)` : g.first];
            if (g.closestTo) lines.push(g.closestTo);
            if (g.accuracy) lines.push(`&plusmn;${g.accuracy}m`);
            marker.bindPopup(lines.join('<br>'));
            return marker;
        });

        const group = L.featureGroup(markers).addTo(map);
        map.fitBounds(group.getBounds().pad(0.15));
    }
}
