<?php

namespace Modules\Moving\Enums;

/** What is moved (REQ-010): one piece, a home, or allowed commercial goods. */
enum LoadType: string
{
    case SinglePiece = 'single_piece';
    case Home = 'home';
    case Commercial = 'commercial';
}
