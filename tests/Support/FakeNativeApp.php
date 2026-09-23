<?php

namespace Tests\Support;

/**
 * Stand-in for Native\Desktop\App. The real one posts to the Electron client over
 * HTTP — relaunch() makes Electron tear the window and the PHP child process down,
 * which is not something a test can survive, let alone assert on afterwards.
 *
 * Swap it in with: App::swap(new FakeNativeApp);
 * Same seam as Tests\Support\FakeNativeSettings.
 */
class FakeNativeApp
{
    public int $relaunches = 0;

    public function relaunch(): void
    {
        $this->relaunches++;
    }
}
