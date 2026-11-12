"""Draws the provider service icons as 512x512 SVGs in Dorr colours (navy #001B53 family + orange #FA7552).

Usage: python service-images.py <svg-dir>, then turn each SVG into a transparent PNG in
database/seeders/data/service-images/<module_name>.png (iOS can't show SVG), e.g. with headless Chrome:
chrome --headless=new --default-background-color=00000000 --window-size=512,512 --screenshot=out.png file:///in.svg
"""
import os
import sys

OUT = sys.argv[1]
os.makedirs(OUT, exist_ok=True)

DEFS = """
<defs>
  <linearGradient id="blue" x1="0" y1="0" x2="1" y2="1">
    <stop offset="0" stop-color="#5B86F2"/><stop offset="1" stop-color="#1E3A8F"/>
  </linearGradient>
  <linearGradient id="blueDeep" x1="0" y1="0" x2="1" y2="1">
    <stop offset="0" stop-color="#2D4FA8"/><stop offset="1" stop-color="#0F2466"/>
  </linearGradient>
  <linearGradient id="soft" x1="0" y1="0" x2="0" y2="1">
    <stop offset="0" stop-color="#E4ECFF"/><stop offset="1" stop-color="#B7C9F7"/>
  </linearGradient>
  <linearGradient id="orange" x1="0" y1="0" x2="1" y2="1">
    <stop offset="0" stop-color="#FFB08F"/><stop offset=".55" stop-color="#FA7552"/><stop offset="1" stop-color="#E5553A"/>
  </linearGradient>
  <linearGradient id="hat" gradientUnits="userSpaceOnUse" x1="0" y1="56" x2="0" y2="346">
    <stop offset="0" stop-color="#DCE5FF"/><stop offset="1" stop-color="#8FA9EC"/>
  </linearGradient>
  <linearGradient id="gold" x1="0" y1="0" x2="1" y2="1">
    <stop offset="0" stop-color="#FFD98A"/><stop offset="1" stop-color="#E9A23B"/>
  </linearGradient>
  <linearGradient id="skin" x1="0" y1="0" x2="0" y2="1">
    <stop offset="0" stop-color="#FFE3D3"/><stop offset="1" stop-color="#F7C2A6"/>
  </linearGradient>
</defs>
"""

SHADOW = '<ellipse cx="256" cy="458" rx="150" ry="16" fill="#001B53" opacity=".12"/>'
TYRE = '#0B1B45'


def wheel(cx, cy, r=40):
    return (f'<circle cx="{cx}" cy="{cy}" r="{r}" fill="{TYRE}"/>'
            f'<circle cx="{cx}" cy="{cy}" r="{r * .42:.0f}" fill="#E4ECFF"/>')


def sparkle(cx, cy, s, fill='url(#orange)'):
    return (f'<path d="M{cx} {cy - s} Q{cx + s * .18} {cy - s * .18} {cx + s} {cy} '
            f'Q{cx + s * .18} {cy + s * .18} {cx} {cy + s} Q{cx - s * .18} {cy + s * .18} {cx - s} {cy} '
            f'Q{cx - s * .18} {cy - s * .18} {cx} {cy - s}Z" fill="{fill}"/>')


def car(dx=0, dy=0, s=1.0):
    return f'''<g transform="translate({dx} {dy}) scale({s})">
  <path d="M150 236 L188 156 Q198 136 222 136 L292 136 Q316 136 328 156 L370 236 Z" fill="url(#soft)"/>
  <path d="M176 230 L204 166 Q210 154 224 154 L250 154 L250 230 Z" fill="#fff" opacity=".9"/>
  <path d="M266 230 L266 154 L290 154 Q304 154 311 166 L342 230 Z" fill="#fff" opacity=".9"/>
  <rect x="66" y="222" width="380" height="128" rx="44" fill="url(#blue)"/>
  <rect x="96" y="262" width="54" height="26" rx="13" fill="url(#orange)"/>
  <rect x="362" y="262" width="54" height="26" rx="13" fill="url(#orange)"/>
  <rect x="190" y="300" width="132" height="14" rx="7" fill="#fff" opacity=".35"/>
  {wheel(146, 350, 44)}{wheel(366, 350, 44)}
</g>'''


ICONS = {}

ICONS['moving'] = f'''
<rect x="44" y="128" width="268" height="222" rx="30" fill="url(#blue)"/>
<rect x="44" y="300" width="268" height="22" fill="url(#orange)"/>
<rect x="78" y="164" width="88" height="70" rx="10" fill="url(#soft)"/>
<rect x="114" y="164" width="16" height="70" fill="#FA7552" opacity=".55"/>
<rect x="186" y="186" width="96" height="48" rx="10" fill="#fff" opacity=".85"/>
<path d="M312 196 L392 196 Q414 196 426 214 L462 268 Q468 278 468 290 L468 330 Q468 350 448 350 L312 350 Z" fill="url(#orange)"/>
<path d="M336 216 L388 216 Q398 216 404 224 L432 266 L336 266 Z" fill="#fff" opacity=".9"/>
{wheel(132, 356)}{wheel(390, 356)}'''

ICONS['passenger_ride'] = car()

ICONS['car_rental'] = f'''
<circle cx="176" cy="256" r="104" fill="url(#orange)"/>
<circle cx="176" cy="256" r="38" fill="#fff"/>
<rect x="256" y="230" width="210" height="52" rx="26" fill="url(#blue)"/>
<rect x="392" y="270" width="34" height="64" rx="10" fill="url(#blue)"/>
<rect x="336" y="270" width="34" height="46" rx="10" fill="url(#blue)"/>
<rect x="120" y="170" width="44" height="16" rx="8" fill="#fff" opacity=".5"/>'''

ICONS['driving_lessons'] = f'''
<circle cx="256" cy="300" r="140" fill="none" stroke="url(#blue)" stroke-width="44"/>
<circle cx="256" cy="300" r="52" fill="url(#blue)"/>
<rect x="130" y="286" width="252" height="30" rx="15" fill="url(#blue)"/>
<rect x="241" y="300" width="30" height="120" rx="15" fill="url(#blue)"/>
<circle cx="256" cy="300" r="20" fill="#E4ECFF"/>
<path d="M256 52 L392 108 L256 164 L120 108 Z" fill="url(#orange)"/>
<path d="M190 128 L190 176 Q256 206 322 176 L322 128 L256 156 Z" fill="#E5553A"/>
<path d="M376 116 L376 190" stroke="#E5553A" stroke-width="8" stroke-linecap="round"/>
<circle cx="376" cy="196" r="12" fill="url(#gold)"/>'''

ICONS['driver_without_vehicle'] = f'''
<path d="M106 448 Q106 326 196 300 L316 300 Q406 326 406 448 Z" fill="url(#blue)"/>
<path d="M226 300 L256 360 L286 300 Z" fill="#fff"/>
<path d="M248 330 L264 330 L272 420 L256 436 L240 420 Z" fill="url(#orange)"/>
<circle cx="256" cy="206" r="82" fill="url(#skin)"/>
<path d="M160 158 Q160 86 256 82 Q352 86 352 158 Z" fill="url(#blueDeep)"/>
<rect x="150" y="148" width="212" height="28" rx="14" fill="url(#orange)"/>
<rect x="236" y="104" width="40" height="28" rx="8" fill="url(#gold)"/>'''

ICONS['restaurants'] = f'''
<path d="M90 338 A166 166 0 0 1 422 338 Z" fill="url(#blue)"/>
<path d="M140 300 A120 120 0 0 1 220 200" fill="none" stroke="#fff" stroke-width="18" stroke-linecap="round" opacity=".45"/>
<circle cx="256" cy="160" r="24" fill="url(#orange)"/>
<rect x="244" y="170" width="24" height="18" fill="url(#orange)"/>
<rect x="60" y="334" width="392" height="40" rx="20" fill="url(#orange)"/>
<rect x="110" y="380" width="292" height="18" rx="9" fill="url(#blueDeep)" opacity=".7"/>'''

ICONS['parcels'] = f'''
<path d="M256 84 L428 164 L256 244 L84 164 Z" fill="url(#soft)"/>
<path d="M84 164 L256 244 L256 440 L84 360 Z" fill="url(#blue)"/>
<path d="M256 244 L428 164 L428 360 L256 440 Z" fill="url(#blueDeep)"/>
<path d="M172 123 L212 104 L384 184 L344 203 Z" fill="url(#orange)"/>
<path d="M344 203 L384 184 L384 262 L344 281 Z" fill="#E5553A"/>
<rect x="116" y="300" width="70" height="14" rx="7" fill="#fff" opacity=".5" transform="rotate(25 151 307)"/>'''

ICONS['water_gas'] = f'''
<path d="M190 70 C190 70 76 214 76 292 A114 114 0 0 0 304 292 C304 214 190 70 190 70 Z" fill="url(#blue)"/>
<path d="M126 300 A64 64 0 0 0 176 352" fill="none" stroke="#fff" stroke-width="16" stroke-linecap="round" opacity=".55"/>
<rect x="334" y="176" width="52" height="44" rx="10" fill="url(#blueDeep)"/>
<rect x="296" y="208" width="128" height="226" rx="54" fill="url(#orange)"/>
<rect x="296" y="292" width="128" height="22" fill="#fff" opacity=".35"/>
<rect x="318" y="232" width="16" height="50" rx="8" fill="#fff" opacity=".45"/>'''

ICONS['fuel'] = f'''
<rect x="96" y="96" width="210" height="322" rx="32" fill="url(#blue)"/>
<rect x="128" y="132" width="146" height="94" rx="16" fill="#fff" opacity=".92"/>
<path d="M201 150 C201 150 172 188 172 204 A29 29 0 0 0 230 204 C230 188 201 150 201 150 Z" fill="url(#orange)"/>
<rect x="128" y="256" width="146" height="18" rx="9" fill="#fff" opacity=".3"/>
<rect x="76" y="404" width="250" height="40" rx="14" fill="url(#blueDeep)"/>
<path d="M306 168 L370 168 Q404 168 404 202 L404 340 Q404 366 380 366 Q356 366 356 340 L356 270" fill="none" stroke="url(#orange)" stroke-width="22" stroke-linecap="round" stroke-linejoin="round"/>
<rect x="338" y="228" width="36" height="58" rx="10" fill="url(#orange)"/>'''

ICONS['mechanic'] = f'''
<circle cx="214" cy="270" r="138" fill="none" stroke="url(#blue)" stroke-width="48" stroke-dasharray="40 32"/>
<circle cx="214" cy="270" r="118" fill="url(#blue)"/>
<circle cx="214" cy="270" r="46" fill="#E4ECFF"/>
<mask id="jaw"><rect width="512" height="512" fill="#fff"/><rect x="380" y="56" width="34" height="74" rx="6" fill="#000" transform="rotate(45 397 93)"/></mask>
<g mask="url(#jaw)">
  <rect x="270" y="110" width="44" height="300" rx="22" fill="url(#orange)" transform="rotate(45 292 260)"/>
  <circle cx="392" cy="122" r="66" fill="url(#orange)"/>
</g>'''

ICONS['electrician'] = f'''
<circle cx="256" cy="210" r="140" fill="url(#soft)"/>
<path d="M150 300 Q190 350 196 392 L316 392 Q322 350 362 300 Z" fill="url(#soft)"/>
<path d="M150 140 A120 120 0 0 1 220 92" fill="none" stroke="#fff" stroke-width="18" stroke-linecap="round"/>
<path d="M276 104 L188 238 L248 238 L226 344 L326 196 L262 196 Z" fill="url(#orange)"/>
<rect x="190" y="392" width="132" height="28" rx="12" fill="url(#blue)"/>
<rect x="200" y="424" width="112" height="26" rx="12" fill="url(#blueDeep)"/>'''

ICONS['car_wash'] = f'''{car(0, 40, 1.0)}
<circle cx="150" cy="120" r="34" fill="none" stroke="#5B86F2" stroke-width="10"/>
<circle cx="236" cy="82" r="22" fill="none" stroke="#5B86F2" stroke-width="9"/>
<path d="M352 60 C352 60 318 104 318 124 A34 34 0 0 0 386 124 C386 104 352 60 352 60 Z" fill="url(#orange)"/>
<circle cx="424" cy="160" r="16" fill="none" stroke="#FA7552" stroke-width="8"/>'''

ICONS['cleaning'] = f'''
<path d="M176 196 L300 196 L318 250 Q332 270 332 300 L332 412 Q332 444 300 444 L176 444 Q144 444 144 412 L144 300 Q144 270 158 250 Z" fill="url(#blue)"/>
<rect x="172" y="300" width="132" height="86" rx="14" fill="#fff" opacity=".9"/>
<rect x="194" y="322" width="88" height="12" rx="6" fill="#5B86F2"/>
<rect x="194" y="348" width="60" height="12" rx="6" fill="#B7C9F7"/>
<rect x="190" y="150" width="96" height="52" rx="12" fill="url(#orange)"/>
<path d="M190 150 L190 106 Q190 92 204 92 L318 92 Q336 92 336 110 L336 126 L286 150 Z" fill="url(#orange)"/>
<rect x="150" y="160" width="40" height="22" rx="8" fill="url(#blueDeep)"/>
{sparkle(408, 118, 40)}{sparkle(424, 236, 24)}{sparkle(372, 186, 14, '#5B86F2')}'''

ICONS['printing'] = f'''
<rect x="150" y="70" width="212" height="120" rx="14" fill="#fff" stroke="#B7C9F7" stroke-width="8"/>
<rect x="76" y="160" width="360" height="190" rx="36" fill="url(#blue)"/>
<rect x="110" y="200" width="48" height="16" rx="8" fill="url(#orange)"/>
<circle cx="396" cy="208" r="12" fill="#7CE0A3"/>
<rect x="140" y="290" width="232" height="152" rx="12" fill="#fff" stroke="#B7C9F7" stroke-width="8"/>
<rect x="170" y="326" width="172" height="14" rx="7" fill="url(#orange)"/>
<rect x="170" y="358" width="130" height="14" rx="7" fill="#B7C9F7"/>
<rect x="170" y="390" width="150" height="14" rx="7" fill="#B7C9F7"/>'''

ICONS['designers'] = f'''
<path d="M110 196 C150 120 362 120 402 196" fill="none" stroke="url(#orange)" stroke-width="12" stroke-dasharray="2 22" stroke-linecap="round"/>
<rect x="84" y="174" width="44" height="44" rx="10" fill="#fff" stroke="#FA7552" stroke-width="10"/>
<rect x="384" y="174" width="44" height="44" rx="10" fill="#fff" stroke="#FA7552" stroke-width="10"/>
<path d="M256 96 L352 250 Q358 330 296 420 L216 420 Q154 330 160 250 Z" fill="url(#blue)"/>
<path d="M256 96 L256 236" stroke="#fff" stroke-width="10" stroke-linecap="round" opacity=".8"/>
<circle cx="256" cy="262" r="30" fill="url(#orange)"/>
<rect x="206" y="410" width="100" height="40" rx="12" fill="url(#blueDeep)"/>'''

ICONS['stores'] = f'''
<rect x="92" y="190" width="328" height="250" rx="22" fill="url(#blue)"/>
<path d="M76 108 L436 108 L460 196 L52 196 Z" fill="#fff"/>
<path d="M76 108 L136 108 L124 196 L52 196 Z M196 108 L256 108 L256 196 L190 196 Z M316 108 L376 108 L392 196 L322 196 Z" fill="url(#orange)"/>
<path d="M52 196 A34 34 0 0 0 124 196 A34 34 0 0 0 190 196 A34 34 0 0 0 256 196 A34 34 0 0 0 322 196 A34 34 0 0 0 392 196 A34 34 0 0 0 460 196 Z" fill="url(#orange)"/>
<rect x="132" y="270" width="112" height="170" rx="14" fill="#fff" opacity=".92"/>
<circle cx="222" cy="360" r="8" fill="#FA7552"/>
<rect x="272" y="270" width="112" height="84" rx="14" fill="url(#soft)"/>'''

ICONS['medical_supplies'] = f'''
<path d="M196 150 L196 116 Q196 92 220 92 L292 92 Q316 92 316 116 L316 150" fill="none" stroke="url(#blueDeep)" stroke-width="24"/>
<rect x="66" y="146" width="380" height="280" rx="44" fill="url(#blue)"/>
<rect x="66" y="210" width="380" height="18" fill="#fff" opacity=".25"/>
<rect x="166" y="206" width="180" height="180" rx="40" fill="url(#orange)"/>
<rect x="234" y="232" width="44" height="128" rx="12" fill="#fff"/>
<rect x="192" y="274" width="128" height="44" rx="12" fill="#fff"/>'''

ICONS['hotels_flights'] = f'''
<rect x="64" y="176" width="190" height="270" rx="20" fill="url(#blue)"/>
<rect x="64" y="140" width="190" height="48" rx="14" fill="url(#blueDeep)"/>
{''.join(f'<rect x="{94 + c * 52}" y="{214 + r * 58}" width="32" height="34" rx="7" fill="#fff" opacity="{.9 if (r + c) % 2 == 0 else .55}"/>' for r in range(3) for c in range(3))}
<rect x="134" y="390" width="50" height="56" rx="8" fill="url(#orange)"/>
<g transform="translate(212 40) scale(11) rotate(45 12 12)">
  <path d="M21 16v-2l-8-5V3.5c0-.83-.67-1.5-1.5-1.5S10 2.67 10 3.5V9l-8 5v2l8-2.5V19l-2 1.5V22l3.5-1 3.5 1v-1.5L13 19v-5.5l8 2.5z" fill="url(#orange)"/>
</g>'''

ICONS['umrah_hajj_trips'] = f'''
<path d="M256 96 L436 160 L256 222 L76 160 Z" fill="#33467E"/>
<path d="M76 160 L256 222 L256 438 L76 372 Z" fill="#16255A" stroke="#4A63A8" stroke-width="3"/>
<path d="M256 222 L436 160 L436 372 L256 438 Z" fill="#0E1B47" stroke="#4A63A8" stroke-width="3"/>
<path d="M76 206 L256 268 L436 206 L436 236 L256 298 L76 236 Z" fill="url(#gold)"/>
<path d="M206 330 L206 404 L236 414 L236 340 Z" fill="url(#gold)" opacity=".9"/>
<path d="M392 70 A44 44 0 1 0 428 140 A34 34 0 1 1 392 70 Z" fill="url(#gold)"/>'''

ICONS['furnished_apartments'] = f'''
<rect x="150" y="60" width="230" height="300" rx="20" fill="url(#blue)"/>
{''.join(f'<rect x="{180 + c * 64}" y="{92 + r * 62}" width="42" height="40" rx="8" fill="#fff" opacity="{.92 if (r * 3 + c) % 3 else .55}"/>' for r in range(3) for c in range(3))}
<rect x="66" y="330" width="380" height="58" rx="24" fill="url(#orange)"/>
<rect x="92" y="296" width="328" height="56" rx="22" fill="#FFB08F"/>
<rect x="50" y="314" width="56" height="104" rx="22" fill="url(#orange)"/>
<rect x="406" y="314" width="56" height="104" rx="22" fill="url(#orange)"/>
<rect x="92" y="412" width="20" height="34" rx="8" fill="url(#blueDeep)"/>
<rect x="400" y="412" width="20" height="34" rx="8" fill="url(#blueDeep)"/>'''

ICONS['chalets_resorts'] = f'''
<path d="M256 70 L446 352 L66 352 Z" fill="url(#orange)"/>
<path d="M256 150 L370 330 L142 330 Z" fill="url(#soft)"/>
<rect x="222" y="246" width="68" height="84" rx="10" fill="url(#blue)"/>
<circle cx="256" cy="200" r="16" fill="#fff"/>
<path d="M52 392 Q90 368 128 392 T204 392 T280 392 T356 392 T432 392 T470 392" fill="none" stroke="url(#blue)" stroke-width="22" stroke-linecap="round"/>
<path d="M90 436 Q128 412 166 436 T242 436 T318 436 T394 436" fill="none" stroke="#5B86F2" stroke-width="16" stroke-linecap="round" opacity=".6"/>'''

ICONS['salons'] = f'''
<rect x="300" y="70" width="64" height="372" rx="26" fill="url(#blue)" transform="rotate(18 332 256)"/>
<line x1="262" y1="100" x2="262" y2="420" stroke="url(#blue)" stroke-width="40" stroke-dasharray="14 12" transform="rotate(18 332 256)"/>
<g transform="translate(44 44) scale(17)">
  <path d="M9.64 7.64c.23-.5.36-1.05.36-1.64 0-2.21-1.79-4-4-4S2 3.79 2 6s1.79 4 4 4c.59 0 1.14-.13 1.64-.36L10 12l-2.36 2.36C7.14 14.13 6.59 14 6 14c-2.21 0-4 1.79-4 4s1.79 4 4 4 4-1.79 4-4c0-.59-.13-1.14-.36-1.64L12 14l7 7h3v-1L9.640 7.64zM6 8c-1.1 0-2-.89-2-2s.9-2 2-2 2 .89 2 2-.9 2-2 2zm0 12c-1.1 0-2-.89-2-2s.9-2 2-2 2 .89 2 2-.9 2-2 2zm6-7.5c-.28 0-.5-.22-.5-.5s.22-.5.5-.5.5.22.5.5-.22.5-.5.5zM19 3l-6 6 2 2 7-7V3h-3z" fill="url(#orange)"/>
</g>'''

ICONS['home_meals'] = f'''
<path d="M190 120 Q170 96 190 72 M256 110 Q236 86 256 60 M322 120 Q302 96 322 72" fill="none" stroke="#5B86F2" stroke-width="12" stroke-linecap="round" opacity=".7"/>
<rect x="96" y="200" width="320" height="34" rx="17" fill="url(#orange)"/>
<rect x="236" y="168" width="40" height="34" rx="10" fill="url(#orange)"/>
<path d="M112 234 L400 234 L386 398 Q382 432 348 432 L164 432 Q130 432 126 398 Z" fill="url(#blue)"/>
<rect x="56" y="262" width="70" height="30" rx="15" fill="url(#blueDeep)"/>
<rect x="386" y="262" width="70" height="30" rx="15" fill="url(#blueDeep)"/>
<rect x="150" y="270" width="18" height="110" rx="9" fill="#fff" opacity=".35"/>'''

ICONS['private_chef'] = f'''
<circle cx="180" cy="196" r="78" fill="url(#hat)"/>
<circle cx="256" cy="150" r="96" fill="url(#hat)"/>
<circle cx="332" cy="196" r="78" fill="url(#hat)"/>
<rect x="164" y="196" width="184" height="150" fill="url(#hat)"/>
<path d="M214 214 L214 340 M256 204 L256 340 M298 214 L298 340" stroke="#fff" stroke-width="12" stroke-linecap="round" opacity=".8"/>
<rect x="150" y="334" width="212" height="76" rx="18" fill="url(#orange)"/>
<rect x="150" y="360" width="212" height="12" fill="#fff" opacity=".35"/>'''

ICONS['event_catering'] = f'''
<rect x="236" y="62" width="40" height="70" rx="20" fill="url(#orange)"/>
<path d="M256 50 C256 50 240 72 256 86 C272 72 256 50 256 50 Z" fill="url(#gold)"/>
<rect x="166" y="130" width="180" height="110" rx="24" fill="url(#soft)"/>
<path d="M166 162 Q196 196 226 162 T286 162 T346 162 L346 150 Q346 130 326 130 L186 130 Q166 130 166 150 Z" fill="url(#orange)"/>
<rect x="100" y="232" width="312" height="178" rx="28" fill="url(#blue)"/>
<path d="M100 270 Q138 312 176 270 T252 270 T328 270 T412 270 L412 258 Q412 232 386 232 L126 232 Q100 232 100 258 Z" fill="url(#orange)"/>
<rect x="70" y="404" width="372" height="34" rx="17" fill="url(#blueDeep)"/>'''

ICONS['sports_venues'] = f'''
<rect x="56" y="110" width="400" height="292" rx="40" fill="url(#blue)"/>
<rect x="84" y="138" width="344" height="236" rx="20" fill="none" stroke="#fff" stroke-width="8" opacity=".75"/>
<line x1="256" y1="138" x2="256" y2="374" stroke="#fff" stroke-width="8" opacity=".75"/>
<circle cx="256" cy="256" r="52" fill="none" stroke="#fff" stroke-width="8" opacity=".75"/>
<rect x="84" y="206" width="54" height="100" fill="none" stroke="#fff" stroke-width="8" opacity=".75"/>
<rect x="374" y="206" width="54" height="100" fill="none" stroke="#fff" stroke-width="8" opacity=".75"/>
<circle cx="350" cy="356" r="70" fill="url(#orange)"/>
<path d="M350 324 L380 346 L368 380 L332 380 L320 346 Z" fill="#fff"/>
<path d="M350 286 L350 324 M380 346 L416 334 M368 380 L388 412 M332 380 L312 412 M320 346 L284 334" stroke="#fff" stroke-width="8" stroke-linecap="round"/>'''

ICONS['events'] = f'''
<mask id="tick"><rect width="512" height="512" fill="#fff"/><circle cx="76" cy="276" r="34" fill="#000"/><circle cx="436" cy="276" r="34" fill="#000"/></mask>
<g mask="url(#tick)" transform="rotate(-10 256 276)">
  <rect x="76" y="176" width="360" height="200" rx="30" fill="url(#orange)"/>
  <line x1="316" y1="196" x2="316" y2="356" stroke="#fff" stroke-width="8" stroke-dasharray="14 12" opacity=".8"/>
  {sparkle(206, 276, 46, '#fff')}
</g>
<rect x="96" y="70" width="18" height="44" rx="9" fill="#5B86F2" transform="rotate(-25 105 92)"/>
<rect x="400" y="84" width="18" height="44" rx="9" fill="url(#orange)" transform="rotate(30 409 106)"/>
<circle cx="190" cy="92" r="12" fill="url(#orange)"/>
<circle cx="330" cy="70" r="10" fill="#5B86F2"/>
<rect x="70" y="420" width="18" height="40" rx="9" fill="url(#orange)" transform="rotate(40 79 440)"/>
<circle cx="420" cy="440" r="13" fill="#5B86F2"/>'''

for key, body in ICONS.items():
    svg = (f'<svg xmlns="http://www.w3.org/2000/svg" width="512" height="512" viewBox="0 0 512 512">'
           f'{DEFS}{SHADOW}{body}</svg>')
    with open(os.path.join(OUT, f'{key}.svg'), 'w', encoding='utf-8') as fh:
        fh.write(svg)
print(len(ICONS), 'icons')
