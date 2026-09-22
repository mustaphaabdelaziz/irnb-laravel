<?php

/*
|--------------------------------------------------------------------------
| NativePHP internal overrides
|--------------------------------------------------------------------------
|
| Merged OVER the package's own config (NativeServiceProvider merges its
| defaults underneath this file), so only the keys set here are changed and
| everything else still comes from the package.
|
*/

return [

    /*
     * Where PHP talks to the Electron side.
     *
     * Electron passes "http://localhost:4000/api/". Windows has no `localhost`
     * line in its hosts file by default, so that name goes through the DNS
     * client — which stalls while the machine is offline. NativePHP calls this
     * API during service-provider registration (fireUpQueueWorkers), so the
     * stalled call times out, the ConnectionException escapes before the app
     * has booted, and EVERY request answers 500 until the machine is online
     * again. Saving a player with a photo is simply where a user meets it.
     *
     * A literal loopback address needs no name resolution, so the call behaves
     * the same online and offline. Only the host is rewritten: the port, path
     * and anything else Electron chose are kept as sent.
     */
    'api_url' => preg_replace(
        '#^(https?://)localhost(:|/|$)#i',
        '${1}127.0.0.1${2}',
        (string) env('NATIVEPHP_API_URL', 'http://127.0.0.1:4000/api/'),
    ),

];
