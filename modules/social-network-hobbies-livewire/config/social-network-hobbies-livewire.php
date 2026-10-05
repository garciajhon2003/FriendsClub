<?php

declare(strict_types=1);

return [
    /*
     * Places members can search near, as key => ['name' => ..., 'lat' => ..., 'lng' => ...].
     * Empty by default: a composition lists its own, and Explore hides the selector.
     */
    'cities' => [],

    // How far from the chosen place an in-person community may meet.
    'search_radius_km' => 15,
];
