<?php

it('prints process analysis graph in mermaid format', function () {
    $this->artisan('pipeline:graph:show process_analysis --format=mermaid')
        ->expectsOutput('graph TD')
        ->expectsOutputToContain('inventory --> map_dispatch')
        ->expectsOutputToContain('structured_opinion --> designer_brief')
        ->assertSuccessful();
});
