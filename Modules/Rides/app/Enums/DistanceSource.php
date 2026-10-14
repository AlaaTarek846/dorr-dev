<?php

namespace Modules\Rides\Enums;

/** Where the metered distance came from. */
enum DistanceSource: string
{
    case DeviceOdometer = 'device_odometer';
    case ServerTrack = 'server_track';
    case RouteEstimate = 'route_estimate';
}
