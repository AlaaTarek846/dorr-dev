@php
    $t = $ar ? [
        'title' => 'تتبّع رحلة على دُرر',
        'gone' => 'انتهت صلاحية هذا الرابط أو أوقفه صاحبه.',
        'shares' => ':name يشاركك رحلته',
        'shares_captain' => 'الكابتن :name يشاركك رحلته',
        'from' => 'من', 'to' => 'إلى', 'open' => 'بدون وجهة محددة',
        'captain' => 'الكابتن', 'car' => 'السيارة', 'plate' => 'اللوحة',
        'safe' => 'وصل بالسلامة', 'safe_sub' => 'انتهت الرحلة بأمان.',
        'updated' => 'يتحدث تلقائياً', 'emergency' => 'في حالة الطوارئ اتصل بـ :n',
        'steps' => ['قبول', 'في الطريق', 'وصل', 'في الرحلة', 'وصل الوجهة'],
    ] : [
        'title' => 'Follow a ride on DORR',
        'gone' => 'This link has expired or was turned off.',
        'shares' => ':name is sharing their ride with you',
        'shares_captain' => 'Captain :name is sharing their ride with you',
        'from' => 'From', 'to' => 'To', 'open' => 'No set destination',
        'captain' => 'Captain', 'car' => 'Car', 'plate' => 'Plate',
        'safe' => 'Arrived safely', 'safe_sub' => 'The ride ended safely.',
        'updated' => 'Updates by itself', 'emergency' => 'In an emergency call :n',
        'steps' => ['Booked', 'On the way', 'At pickup', 'On the ride', 'Arrived'],
    ];
@endphp
<!doctype html>
<html lang="{{ $ar ? 'ar' : 'en' }}" dir="{{ $ar ? 'rtl' : 'ltr' }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex">
<meta name="theme-color" content="#001b53">
<title>{{ $t['title'] }}</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">
<style>
:root{--navy:#001b53;--orange:#fa7552;--ok:#16a34a;--bg:#f5f7fb;--card:#fff;--ink:#001b53;--mut:#5b6b8c;--line:#e6eaf3}
@media (prefers-color-scheme:dark){:root{--bg:#0b1020;--card:#141b2f;--ink:#e8ecf7;--mut:#9aa6c4;--line:#253050}}
*{box-sizing:border-box}
html,body{margin:0;height:100%;background:var(--bg);color:var(--ink);font-family:system-ui,-apple-system,"Segoe UI",Tahoma,sans-serif}
#map{position:fixed;inset:0;bottom:40vh;z-index:0}
.sheet{position:fixed;left:0;right:0;bottom:0;max-height:62vh;overflow:auto;background:var(--card);border-radius:26px 26px 0 0;box-shadow:0 -12px 30px rgba(0,27,83,.12);padding:12px 18px calc(18px + env(safe-area-inset-bottom));animation:up .45s cubic-bezier(.2,.8,.2,1)}
@keyframes up{from{transform:translateY(40%);opacity:0}to{transform:none;opacity:1}}
.grip{width:38px;height:4px;border-radius:4px;background:var(--line);margin:0 auto 12px}
.who{font-size:13px;color:var(--mut);margin-bottom:4px}
.status{font-size:21px;font-weight:800;margin:0 0 12px;transition:color .3s}
.steps{display:flex;gap:6px;margin-bottom:14px}
.steps i{flex:1;height:5px;border-radius:5px;background:var(--line);overflow:hidden;position:relative}
.steps i.on::after{content:"";position:absolute;inset:0;background:var(--orange);animation:fill .6s ease forwards;transform-origin:left}
[dir=rtl] .steps i.on::after{transform-origin:right}
@keyframes fill{from{transform:scaleX(0)}to{transform:scaleX(1)}}
.labels{display:flex;justify-content:space-between;font-size:10px;color:var(--mut);margin:-8px 0 14px}
.row{display:flex;align-items:center;gap:10px;padding:6px 0}
.dot{width:10px;height:10px;border-radius:50%;flex:none}
.addr{font-size:14px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.lbl{font-size:11px;color:var(--mut)}
.driver{display:flex;align-items:center;gap:12px;background:var(--bg);border-radius:16px;padding:12px;margin-top:10px}
.avatar{width:46px;height:46px;border-radius:50%;background:rgba(250,117,82,.15);color:var(--orange);font-weight:800;display:flex;align-items:center;justify-content:center;font-size:18px;overflow:hidden;flex:none}
.avatar img{width:100%;height:100%;object-fit:cover}
.plate{display:inline-flex;border:2px solid #1f2937;border-radius:6px;background:#fff;color:#111827;font-weight:700;font-size:13px;padding:2px 8px;direction:ltr}
.safe{display:none;text-align:center;padding:10px 0 4px}
.safe .tick{width:72px;height:72px;border-radius:50%;background:var(--ok);margin:0 auto 10px;display:flex;align-items:center;justify-content:center;animation:pop .5s cubic-bezier(.2,1.6,.4,1)}
@keyframes pop{from{transform:scale(.3)}to{transform:scale(1)}}
.safe h2{margin:0;font-size:22px;color:var(--ok)}
.foot{display:flex;justify-content:space-between;align-items:center;margin-top:14px;font-size:11px;color:var(--mut)}
.live{display:inline-flex;align-items:center;gap:6px}
.live b{width:8px;height:8px;border-radius:50%;background:var(--ok);animation:pulse 1.4s infinite}
@keyframes pulse{0%{box-shadow:0 0 0 0 rgba(22,163,74,.6)}100%{box-shadow:0 0 0 10px rgba(22,163,74,0)}}
.gone{position:fixed;inset:0;display:flex;align-items:center;justify-content:center;padding:24px;text-align:center}
.gone div{background:var(--card);border-radius:24px;padding:32px 24px;max-width:360px;box-shadow:0 10px 30px rgba(0,27,83,.08)}
.car{width:34px;height:34px;border-radius:50%;background:var(--navy);border:3px solid #fff;box-shadow:0 4px 12px rgba(0,27,83,.35);display:flex;align-items:center;justify-content:center;transition:transform .8s linear}
.car svg{width:18px;height:18px}
.pin{width:16px;height:16px;border-radius:50%;border:3px solid #fff;box-shadow:0 2px 6px rgba(0,0,0,.3)}
</style>
</head>
<body>
@if ($data === null)
    <div class="gone"><div><h1 style="font-size:20px;margin:0 0 10px">{{ $t['title'] }}</h1><p style="color:var(--mut);margin:0">{{ $t['gone'] }}</p></div></div>
@else
    <div id="map"></div>
    <div class="sheet">
        <div class="grip"></div>
        <div class="who" id="who">{{ str_replace(':name', $data['shared_by']['name'] ?? '', ($data['shared_by']['type'] ?? '') === 'driver' ? $t['shares_captain'] : $t['shares']) }}</div>
        <h1 class="status" id="status">{{ $data['status_label'] }}</h1>
        <div class="steps" id="steps">@for ($i = 0; $i < 5; $i++)<i></i>@endfor</div>
        <div class="labels">@foreach ($t['steps'] as $s)<span>{{ $s }}</span>@endforeach</div>
        <div class="safe" id="safe">
            <div class="tick"><svg viewBox="0 0 24 24" width="38" height="38" fill="none" stroke="#fff" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12l5 5 9-10"/></svg></div>
            <h2>{{ $t['safe'] }}</h2>
            <p style="color:var(--mut);margin:4px 0 0">{{ $t['safe_sub'] }}</p>
        </div>
        <div class="row"><span class="dot" style="background:var(--navy)"></span><div style="min-width:0"><div class="lbl">{{ $t['from'] }}</div><div class="addr" id="from">{{ $data['origin']['address'] }}</div></div></div>
        <div class="row"><span class="dot" style="background:var(--orange)"></span><div style="min-width:0"><div class="lbl">{{ $t['to'] }}</div><div class="addr" id="to">{{ $data['destination']['address'] ?? $t['open'] }}</div></div></div>
        <div class="driver" id="driver" style="display:none">
            <div class="avatar" id="avatar"></div>
            <div style="flex:1;min-width:0">
                <div class="lbl">{{ $t['captain'] }}</div>
                <div class="addr" id="dname">{{ $data['driver']['name'] ?? '' }}</div>
                <div class="lbl" id="car"></div>
            </div>
            <span class="plate" id="plate"></span>
        </div>
        <div class="foot"><span class="live" id="live"><b></b>{{ $t['updated'] }}</span><a href="tel:{{ $data['emergency_number'] }}" style="color:inherit">{{ str_replace(':n', $data['emergency_number'], $t['emergency']) }}</a></div>
    </div>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>
    <script>
    (function () {
        var T = @json($t);
        var data = @json($data);
        var liveUrl = @json(url('/ride/'.$token.'/live'));
        var order = {searching: 0, bidding: 0, scheduled: 0, awaiting_payment: 0, accepted: 1, arrived: 2, in_progress: 3, completed: 4};
        var map = L.map('map', {zoomControl: false, attributionControl: true});
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {maxZoom: 19, attribution: '&copy; OpenStreetMap'}).addTo(map);
        var pin = function (color) { return L.divIcon({className: '', html: '<div class="pin" style="background:' + color + '"></div>', iconSize: [16, 16], iconAnchor: [8, 8]}); };
        var carIcon = L.divIcon({className: '', html: '<div class="car" id="carEl"><svg viewBox="0 0 24 24" fill="#fff"><path d="M12 2l7 18-7-4-7 4z"/></svg></div>', iconSize: [34, 34], iconAnchor: [17, 17]});
        var car = null, route = null, fitted = false;

        function decode(s) {
            var pts = [], i = 0, lat = 0, lng = 0;
            while (s && i < s.length) {
                var r = 0, sh = 0, b;
                do { b = s.charCodeAt(i++) - 63; r |= (b & 31) << sh; sh += 5; } while (b >= 32 && i < s.length);
                lat += (r & 1) ? ~(r >> 1) : (r >> 1);
                r = 0; sh = 0;
                do { b = s.charCodeAt(i++) - 63; r |= (b & 31) << sh; sh += 5; } while (b >= 32 && i < s.length);
                lng += (r & 1) ? ~(r >> 1) : (r >> 1);
                pts.push([lat / 1e5, lng / 1e5]);
            }
            return pts;
        }

        // The car glides to its new place instead of jumping.
        function glide(to) {
            if (!car) { car = L.marker(to, {icon: carIcon, zIndexOffset: 1000}).addTo(map); return; }
            var from = car.getLatLng(), start = null;
            function step(ts) {
                if (!start) start = ts;
                var p = Math.min(1, (ts - start) / 1200);
                car.setLatLng([from.lat + (to[0] - from.lat) * p, from.lng + (to[1] - from.lng) * p]);
                if (p < 1) requestAnimationFrame(step);
            }
            requestAnimationFrame(step);
        }

        function render(d) {
            var name = d.shared_by && d.shared_by.name ? d.shared_by.name : '';
            document.getElementById('who').textContent = (d.shared_by && d.shared_by.type === 'driver' ? T.shares_captain : T.shares).replace(':name', name);
            document.getElementById('status').textContent = d.status_label;
            var at = order[d.status] === undefined ? -1 : order[d.status];
            var bars = document.querySelectorAll('#steps i');
            for (var i = 0; i < bars.length; i++) { bars[i].className = i <= at ? 'on' : ''; }
            document.getElementById('from').textContent = d.origin.address || '';
            document.getElementById('to').textContent = d.destination ? (d.destination.address || '') : T.open;
            if (d.driver) {
                document.getElementById('driver').style.display = 'flex';
                document.getElementById('dname').textContent = d.driver.name || '';
                document.getElementById('car').textContent = [d.driver.vehicle, d.driver.color].filter(Boolean).join(' · ');
                document.getElementById('plate').textContent = d.driver.plate || '';
                document.getElementById('plate').style.display = d.driver.plate ? 'inline-flex' : 'none';
                var av = document.getElementById('avatar');
                av.innerHTML = d.driver.avatar_url ? '<img alt="" src="' + d.driver.avatar_url + '">' : (d.driver.name || '?').charAt(0);
            }
            if (d.arrived_safely) {
                document.getElementById('safe').style.display = 'block';
                document.getElementById('live').style.visibility = 'hidden';
            }

            if (!route) {
                L.marker([d.origin.lat, d.origin.lng], {icon: pin('#001b53')}).addTo(map);
                if (d.destination) L.marker([d.destination.lat, d.destination.lng], {icon: pin('#fa7552')}).addTo(map);
                (d.stops || []).forEach(function (s) { L.marker([s.lat, s.lng], {icon: pin('#f5a623')}).addTo(map); });
                var pts = decode(d.polyline);
                route = pts.length > 1 ? L.polyline(pts, {color: '#001b53', weight: 5, opacity: .85}).addTo(map) : true;
            }
            if (d.location) {
                glide([d.location.lat, d.location.lng]);
                var el = document.getElementById('carEl');
                if (el && d.location.heading != null) el.style.transform = 'rotate(' + d.location.heading + 'deg)';
            } else if (car) { map.removeLayer(car); car = null; }

            if (!fitted) {
                var b = L.latLngBounds([[d.origin.lat, d.origin.lng]]);
                if (d.destination) b.extend([d.destination.lat, d.destination.lng]);
                if (d.location) b.extend([d.location.lat, d.location.lng]);
                if (route && route.getBounds) b.extend(route.getBounds());
                map.fitBounds(b, {padding: [40, 40], maxZoom: 16});
                fitted = true;
            }
        }

        render(data);
        var timer = setInterval(function () {
            if (data.arrived_safely) { clearInterval(timer); return; }
            fetch(liveUrl, {headers: {'Accept': 'application/json'}}).then(function (r) {
                if (r.status === 404) { clearInterval(timer); location.reload(); return null; }
                return r.json();
            }).then(function (d) { if (d) { data = d; render(d); } }).catch(function () {});
        }, 5000);
    })();
    </script>
@endif
</body>
</html>
