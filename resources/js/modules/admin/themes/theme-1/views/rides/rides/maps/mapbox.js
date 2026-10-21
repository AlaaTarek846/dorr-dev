// The admin's maps (O-06..O-08) draw with Mapbox GL, like LeeTaxi — loaded once, only when a map opens.
const VERSION = '3.6.0';
let loading = null;

export function loadMapbox() {
    if (window.mapboxgl) return Promise.resolve(window.mapboxgl);
    if (loading) return loading;
    loading = new Promise((resolve, reject) => {
        const css = document.createElement('link');
        css.rel = 'stylesheet';
        css.href = `https://api.mapbox.com/mapbox-gl-js/v${VERSION}/mapbox-gl.css`;
        document.head.appendChild(css);
        const script = document.createElement('script');
        script.src = `https://api.mapbox.com/mapbox-gl-js/v${VERSION}/mapbox-gl.js`;
        script.onload = () => resolve(window.mapboxgl);
        script.onerror = (e) => { loading = null; reject(e); };
        document.head.appendChild(script);
    });
    return loading;
}

/** A map in `el`, centred on Riyadh until the data fits it; the labels in the page's language. */
export async function makeMap(el, settings, locale) {
    const mapboxgl = await loadMapbox();
    mapboxgl.accessToken = settings.mapbox_token;
    const map = new mapboxgl.Map({ container: el, style: `mapbox://styles/${settings.mapbox_style || 'mapbox/streets-v12'}`, center: [46.6753, 24.7136], zoom: 9, minZoom: 3 });
    map.addControl(new mapboxgl.NavigationControl());
    map.addControl(new mapboxgl.FullscreenControl());
    map.on('load', () => {
        ['country-label', 'state-label', 'settlement-label', 'settlement-subdivision-label'].forEach((id) => {
            if (map.getLayer(id)) map.setLayoutProperty(id, 'text-field', ['coalesce', ['get', `name_${locale === 'ar' ? 'ar' : 'en'}`], ['get', 'name']]);
        });
    });
    return { map, mapboxgl };
}

/** Google's encoded polyline (what the server keeps) → [lng, lat] pairs. */
export function decodePolyline(encoded) {
    const points = [];
    let index = 0, lat = 0, lng = 0;
    while (index < (encoded?.length ?? 0)) {
        for (const which of ['lat', 'lng']) {
            let result = 0, shift = 0, b;
            do { b = encoded.charCodeAt(index++) - 63; result |= (b & 0x1f) << shift; shift += 5; } while (b >= 0x20);
            const delta = (result & 1) ? ~(result >> 1) : (result >> 1);
            if (which === 'lat') lat += delta; else lng += delta;
        }
        points.push([lng / 1e5, lat / 1e5]);
    }
    return points;
}
