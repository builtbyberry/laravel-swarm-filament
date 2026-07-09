<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Navigation
    |--------------------------------------------------------------------------
    |
    | The Filament navigation group the read-only observability resources are
    | grouped under, and an optional sort order for the group.
    |
    */

    'navigation' => [
        'group' => 'Swarm',
        'sort' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Authorization
    |--------------------------------------------------------------------------
    |
    | These surfaces expose run data (display-decrypted) and are gated
    | deny-by-default: the resources authorize against the Gate ability named
    | here before rendering. Define that Gate in your application — absent a
    | Gate definition, access is denied. Set to null (or '') to turn the package
    | gate off entirely: the surfaces then become visible to any user who can
    | reach the Filament panel (this does not defer to a per-resource policy).
    |
    */

    'authorization' => [
        'ability' => 'viewSwarmObservability',
    ],

];
