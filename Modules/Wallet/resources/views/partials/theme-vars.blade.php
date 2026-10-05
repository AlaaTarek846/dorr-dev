/* The palette comes from the controller (the app passes its theme colours in the query string). */
:root {
    --primary: #{{ $theme['primary'] }};
    --primary-rgb: {{ $theme['primary_rgb'] }};
    --bg: #{{ $theme['bg'] }};
    --surface: #{{ $theme['surface'] }};
    --ink: #{{ $theme['ink'] }};
    --mut: #{{ $theme['mut'] }};
    --soft: #{{ $theme['soft'] }};
    --line: #{{ $theme['line'] }};
    --field: #{{ $theme['field'] }};
    --green: #16a34a;
    --danger: #dc2626;
    --amber: #d97706;
}
